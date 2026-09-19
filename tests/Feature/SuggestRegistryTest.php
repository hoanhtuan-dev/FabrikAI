<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Models\User;
use App\Services\StyleSuggestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [2026-09-22] "💡 Gợi ý từ ảnh" phải đi theo MODEL REGISTRY + LUỒNG ƯU TIÊN provider.
 *
 * Lỗi thật trước đây: StyleSuggestService cứng qwen|gemini ở 3 chỗ (helper, service, validate
 * của /settings/suggest) nên DeepSeek và custom provider KHÔNG BAO GIỜ được gọi, dù đã đăng ký
 * model trong nhóm 'vision' và có key. Hệ quả trên production: chỉ còn key deepseek đang bật
 * ⇒ danh sách thử RỖNG ⇒ card lặng lẽ rơi về phân tích màu GD, không có suy luận nào.
 */
class SuggestRegistryTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $abs) {
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    /** PNG thật 64x64 trong storage/app/public (đường vision cần ảnh thật để đọc). */
    private function makeRealPng(string $name): string
    {
        $abs = storage_path('app/public/studio/'.$name);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        $im = imagecreatetruecolor(64, 64);
        imagepng($im, $abs);
        imagedestroy($im);
        $this->tempFiles[] = $abs;

        return $abs;
    }

    private function visionModel(string $provider, string $modelId, int $priority = 5): StudioModel
    {
        return StudioModel::create([
            'group' => 'vision', 'name' => $provider.' '.$modelId, 'provider' => $provider,
            'model_id' => $modelId, 'api_key_ref' => $provider, 'priority' => $priority, 'enabled' => true,
        ]);
    }

    private function key(string $provider, string $value): StudioApiKey
    {
        return StudioApiKey::create([
            'provider' => $provider, 'label' => $provider, 'value' => $value,
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
    }

    /** JSON hợp lệ để finalize() dựng Creative Direction. */
    private function visionJson(string $marker): string
    {
        return json_encode([
            'styles' => ['editorial'],
            'background' => 'studio',
            'pose' => 'standing',
            'fabric' => 'silk',
            'silhouette' => 'midi dress',
            'camera' => '50mm',
            'garment_type' => 'midi dress',
            'color_palette' => ['ivory'],
            'embellishment' => 'plain solid',
            'detail_notes' => 'marker '.$marker,
            'image_prompt_en' => 'Editorial photo of an ivory silk midi dress, marker '.$marker.'.',
            'prompt_vi' => 'Anh editorial vay lua mau ngoc trai, marker '.$marker.'.',
            'video_prompt_en' => 'Catwalk video of the same ivory silk midi dress, marker '.$marker.'.',
            'keywords' => ['silk', 'midi'],
        ]);
    }

    // ── 1. DeepSeek được dùng khi là provider DUY NHẤT có key ────────────────

    public function test_suggest_uses_deepseek_when_it_is_the_only_provider_with_a_key(): void
    {
        // Đúng trạng thái production: có dòng qwen trong registry nhưng key qwen đã tắt.
        $this->visionModel('qwen', 'qwen3.8-flash', 10);
        $this->visionModel('deepseek', 'deepseek-flash', 5);
        $this->key('deepseek', 'sk-deepseek-test');

        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson('deepseek')]]]], 200),
            'dashscope-intl.aliyuncs.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson('qwen')]]]], 200),
        ]);

        $result = app(StyleSuggestService::class)->suggest($this->makeRealPng('deepseek.png'), 6, []);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.deepseek.com/chat/completions'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'dashscope-intl.aliyuncs.com'));
        $this->assertStringContainsString('deepseek', (string) $result['image_prompt_en']);
    }

    // ── 2. Thứ tự thử ĐỔI THEO LUỒNG ƯU TIÊN ────────────────────────────────

    public function test_suggest_follows_the_provider_priority_flow(): void
    {
        $this->visionModel('qwen', 'qwen3.8-flash', 10);
        $this->visionModel('deepseek', 'deepseek-flash', 5);
        $this->key('qwen', 'sk-ws-paygo-test');
        $this->key('deepseek', 'sk-deepseek-test');

        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson('deepseek')]]]], 200),
            'dashscope-intl.aliyuncs.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson('qwen')]]]], 200),
        ]);

        // Mặc định: qwen đứng trước deepseek trong luồng.
        $first = app(StyleSuggestService::class)->suggest($this->makeRealPng('flow1.png'), 6, []);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'dashscope-intl.aliyuncs.com'));
        $this->assertStringContainsString('qwen', (string) $first['image_prompt_en']);

        // Đổi luồng cho deepseek lên trước ⇒ KHÔNG cần sửa code, thứ tự thử đổi theo.
        set_setting('studio_provider_priority', 'deepseek,qwen,gemini');
        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson('deepseek')]]]], 200),
            'dashscope-intl.aliyuncs.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson('qwen')]]]], 200),
        ]);

        $second = app(StyleSuggestService::class)->suggest($this->makeRealPng('flow2.png'), 6, []);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.deepseek.com'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'dashscope-intl.aliyuncs.com'));
        $this->assertStringContainsString('deepseek', (string) $second['image_prompt_en']);
    }

    // ── 3. Custom provider protocol 'openai' dùng được (CKEY…) ───────────────

    public function test_suggest_uses_custom_openai_provider(): void
    {
        StudioProvider::create([
            'slug' => 'ckey', 'name' => 'CKEY', 'protocol' => 'openai',
            'base_url' => 'https://api.xah.io/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'ckey', 'priority' => 5, 'enabled' => true,
        ]);
        $this->visionModel('ckey', 'phuocanh421994/Qwen_Image_3.0_Pro', 5);
        $this->key('ckey', 'ckey-secret');

        Http::fake([
            'api.xah.io/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson('ckey')]]]], 200),
        ]);

        $result = app(StyleSuggestService::class)->suggest($this->makeRealPng('ckey.png'), 6, []);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.xah.io/v1/chat/completions'));
        $this->assertStringContainsString('ckey', (string) $result['image_prompt_en']);
    }

    // ── 4. Ép provider cụ thể vẫn giữ hành vi cũ (tương thích) ───────────────

    public function test_forced_provider_keeps_legacy_behaviour(): void
    {
        $this->visionModel('deepseek', 'deepseek-flash', 10);
        $this->key('deepseek', 'sk-deepseek-test');
        $this->key('gemini', 'AIza-test');

        set_setting('studio_suggest_provider', 'gemini');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $this->visionJson('gemini')]]]]],
            ], 200),
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $this->visionJson('deepseek')]]]], 200),
        ]);

        $result = app(StyleSuggestService::class)->suggest($this->makeRealPng('forced.png'), 6, []);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'generativelanguage.googleapis.com'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.deepseek.com'));
        $this->assertStringContainsString('gemini', (string) $result['image_prompt_en']);
    }

    // ── 5. Helper + endpoint cấu hình ────────────────────────────────────────

    public function test_suggest_provider_defaults_to_auto_and_accepts_any_slug(): void
    {
        $this->assertSame('', studio_suggest_provider(), 'Chưa cấu hình ⇒ AUTO (theo registry)');

        set_setting('studio_suggest_provider', 'auto');
        $this->assertSame('', studio_suggest_provider());

        set_setting('studio_suggest_provider', 'deepseek');
        $this->assertSame('deepseek', studio_suggest_provider());

        set_setting('studio_suggest_provider', 'ckey');
        $this->assertSame('ckey', studio_suggest_provider(), 'Slug custom phải giữ nguyên, không bị ép về qwen');
    }

    public function test_suggest_settings_endpoint_accepts_deepseek_and_auto(): void
    {
        $this->actingAs($this->admin());

        // Endpoint legacy trả back() (302) — quan trọng là KHÔNG còn 422 vì 'in:gemini,qwen'.
        foreach (['deepseek', 'auto', 'ckey'] as $slug) {
            $this->post('/api/settings/suggest', [
                'suggest_provider' => $slug,
                'suggest_default_lang' => 'vi',
            ])->assertRedirect();
        }

        // Slug lạ trước đây bị 422; nay lưu được và giữ nguyên.
        $this->post('/api/settings/suggest', ['suggest_provider' => 'ckey', 'suggest_default_lang' => 'vi'])->assertRedirect();
        $this->assertSame('ckey', (string) setting('studio_suggest_provider'));

        // 'auto' được lưu thành '' (quy ước AUTO = rỗng) để helper đọc ra chế độ registry.
        $this->post('/api/settings/suggest', ['suggest_provider' => 'auto', 'suggest_default_lang' => 'vi'])->assertRedirect();
        $this->assertSame('', (string) setting('studio_suggest_provider'));
    }

    // ── 6. Không có key nào ⇒ vẫn fallback màu như cũ ────────────────────────

    public function test_colour_fallback_when_no_provider_has_a_key(): void
    {
        $this->visionModel('deepseek', 'deepseek-flash', 5);
        Http::fake();

        $result = app(StyleSuggestService::class)->suggest($this->makeRealPng('nokey.png'), 6, []);

        Http::assertNothingSent();
        $this->assertNotEmpty($result['image_prompt_en']);
    }
}
