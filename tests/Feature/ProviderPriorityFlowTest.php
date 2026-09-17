<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Models\User;
use App\Services\ImageAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [2026-09-17] Luồng ưu tiên provider (qwen → custom → flux → gemini) — DeepSeek Harness style.
 *
 * Bảo vệ:
 *   1. Catalog tích hợp ưu tiên Qwen làm provider chính, Flux fallback, Gemini cuối.
 *   2. Rank provider theo luồng (studio_provider_rank) — qwen=0, custom=10, flux=20, gemini=30.
 *   3. Candidate/nhóm công việc đều xếp theo luồng — UI và pipeline không thể lệch nhau.
 *   4. Transport thật cho flux (queue.fal.run) và custom openai-images (CKEY style) —
 *      trước đây 'fal' được khai báo nhưng dispatch BỎ QUA (không có client).
 *   5. Endpoint đổi luồng + đồng bộ catalog chỉ ADMIN dùng được (StudioCustomerAccessTest).
 */
class ProviderPriorityFlowTest extends TestCase
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

    /** PNG thật 64x64 trong storage/app/public. */
    private function makeRealPng(string $name): array
    {
        $rel = 'studio/'.$name;
        $abs = storage_path('app/public/'.$rel);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        $im = imagecreatetruecolor(64, 64);
        imagepng($im, $abs);
        imagedestroy($im);
        $this->tempFiles[] = $abs;

        return [$rel, $abs];
    }

    // ── 1. Luồng mặc định ────────────────────────────────────────────────

    public function test_default_flow_is_qwen_custom_flux_gemini(): void
    {
        $this->assertSame(['qwen', 'custom', 'flux', 'gemini'], studio_provider_priority_flow());
        $this->assertSame('qwen', config('studio.image_provider'));
    }

    public function test_provider_ranks_follow_the_flow(): void
    {
        $this->assertSame(0, studio_provider_rank('qwen'));
        $this->assertSame(0, studio_provider_rank('wan'));
        $this->assertSame(20, studio_provider_rank('fal'));
        $this->assertSame(30, studio_provider_rank('gemini'));
        $this->assertSame(990, studio_provider_rank('deepseek'));
    }

    // ── 2. Thứ tự model theo luồng ────────────────────────────────────────

    public function test_image_candidates_put_qwen_first_then_flux_then_gemini(): void
    {
        $list = studio_model_candidates('image');

        $this->assertSame('qwen', $list[0]['provider']);
        $this->assertSame('qwen-image-3.0-pro', $list[0]['model']);

        // Nhóm qwen trước (priority giảm dần), rồi mới tới flux rồi gemini.
        $providers = array_column($list, 'provider');
        $this->assertSame(['qwen', 'qwen', 'qwen', 'qwen', 'fal', 'gemini'], array_slice($providers, 0, 6));
        $this->assertSame('flux-1.1-schnell', $list[4]['model']);
        $this->assertSame('gemini-2.5-flash-image', $list[5]['model']);
    }

    public function test_task_group_models_follow_flow_after_registered_default(): void
    {
        set_setting('studio_task_image_model', 'gemini:gemini-2.5-flash-image');
        $list = studio_task_group_models('image');
        $this->assertSame('gemini', $list[0]['provider']);
        $this->assertTrue($list[0]['default']);

        set_setting('studio_task_image_model', '');
        $list = studio_task_group_models('image');
        $this->assertSame('qwen', $list[0]['provider']);
    }

    public function test_video_group_uses_wan3_as_newest_default(): void
    {
        $this->assertSame('wan3.0-video', config('studio.video_model'));
        $list = studio_task_group_models('video');
        $this->assertSame('wan3.0-video', $list[0]['model']);
    }

    public function test_translate_legacy_prefers_qwen_over_gemini(): void
    {
        $list = studio_task_group_models('translate');
        $this->assertSame('qwen', $list[0]['provider']);
        $this->assertSame('gemini', $list[1]['provider']);
    }

    // ── 3. Endpoint đổi luồng (admin-only) ────────────────────────────────

    public function test_admin_can_reorder_the_flow(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/settings-vue/provider-priority', ['value' => 'flux,qwen,gemini'])
            ->assertOk();

        $this->assertSame(['flux', 'qwen', 'gemini'], studio_provider_priority_flow());
        $this->assertSame(0, studio_provider_rank('fal'));
        $this->assertSame(10, studio_provider_rank('qwen'));
    }

    public function test_flow_rejects_invalid_tokens(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/settings-vue/provider-priority', ['value' => 'bogus,xxxx'])
            ->assertStatus(422);
    }

    // ── 4. Đồng bộ catalog ────────────────────────────────────────────────

    public function test_sync_catalog_imports_latest_qwen_models(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/settings-vue/sync-catalog')
            ->assertOk()
            ->assertJson(['ok' => true, 'created' => 27]);

        $this->assertDatabaseHas('studio_models', [
            'group' => 'image', 'provider' => 'qwen', 'model_id' => 'qwen-image-3.0-pro',
        ]);
        $this->assertDatabaseHas('studio_models', [
            'group' => 'edit', 'provider' => 'qwen_edit', 'model_id' => 'qwen-image-edit-2511',
        ]);
        $this->assertDatabaseHas('studio_models', [
            'group' => 'video', 'provider' => 'wan', 'model_id' => 'wan3.0-video',
        ]);
    }

    public function test_sync_catalog_is_idempotent_and_keeps_admin_overrides(): void
    {
        studio_sync_model_catalog();
        StudioModel::where('model_id', 'qwen-image-3.0-pro')->update(['enabled' => false]);

        $result = studio_sync_model_catalog();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        // KHÔNG bật lại model admin đã tắt.
        $this->assertSame(0, StudioModel::where('model_id', 'qwen-image-3.0-pro')->where('enabled', true)->count());
    }

    // ── 5. Transport: Fal queue (fallback flux) ───────────────────────────

    public function test_fal_fallback_used_after_qwen_key_failure(): void
    {
        config(['studio.image_provider' => 'qwen']);
        config(['studio.qwen_key' => 'sk-ws-bad']);
        config(['studio.fal_key' => 'fal-test-key']);

        Http::fake([
            'dashscope-intl.aliyuncs.com*' => Http::response(['error' => ['message' => 'InvalidApiKey']], 401),
            'token-plan*' => Http::response(['error' => ['message' => 'InvalidApiKey']], 401),
            'queue.fal.run/*' => Http::sequence()
                ->push(['request_id' => 'req-1'], 200)
                ->push(['status' => 'COMPLETED'], 200)
                ->push(['images' => [['url' => 'https://fal-cdn.test/out.png']]], 200),
            'fal-cdn.test*' => Http::response('fake-png-bytes', 200),
        ]);

        $service = app(ImageAIService::class);
        $url = $service->generate('a red silk dress');

        $this->assertIsString($url);
        $this->assertStringStartsWith('/storage/studio/', $url);
        $this->assertSame('fal', $service->lastProvider());
        $this->assertSame('flux-1.1-schnell', $service->lastModel());

        Http::assertSent(function ($request) {
            $auth = (string) ($request->header('Authorization')[0] ?? '');

            return str_contains($request->url(), 'queue.fal.run/fal-ai/flux-1.1-schnell')
                && str_contains($auth, 'Key fal-test-key');
        });
    }

    public function test_no_key_at_all_still_returns_stub(): void
    {
        config(['studio.image_provider' => 'qwen']);
        config(['studio.qwen_key' => null]);
        config(['studio.fal_key' => null]);

        $service = app(ImageAIService::class);
        $url = $service->generate('a dress');

        $this->assertIsString($url);
        $this->assertNotNull($service->lastStubReason());
    }

    // ── 6. Transport: custom provider openai-images (CKEY style) ──────────

    public function test_custom_provider_openai_images_generates_via_images_endpoint(): void
    {
        StudioProvider::create([
            'slug' => 'ckey', 'name' => 'CKEY', 'protocol' => 'openai',
            'base_url' => 'https://api.xah.io/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'ckey', 'enabled' => true, 'note' => 'test',
        ]);

        StudioApiKey::create([
            'provider' => 'ckey', 'label' => 'main', 'value' => 'ckey-test-secret',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);

        // Đường THỰC TẾ: StudioController resolve default của nhóm công việc rồi truyền
        // provider/model làm override cho generate() (studio_task_group_resolve → override).
        set_setting('studio_task_image_model', 'ckey:phuocanh421994/Qwen_Image_3.0_Pro');
        [$provider, $model] = studio_task_group_resolve('image');
        $this->assertSame(['ckey', 'phuocanh421994/Qwen_Image_3.0_Pro'], [$provider, $model]);

        [, $outAbs] = $this->makeRealPng('ckey-out-'.uniqid().'.png');

        Http::fake([
            'api.xah.io/v1/images/generations' => Http::response([
                'data' => [['url' => 'https://cdn.test/ckey-out.png']],
            ], 200),
            'cdn.test*' => Http::response((string) file_get_contents($outAbs), 200),
        ]);

        $service = app(ImageAIService::class);
        $url = $service->generate('fashion photo of a dress', null, null, null, null, null, $provider, $model);

        $this->assertIsString($url);
        $this->assertStringStartsWith('/storage/studio/', $url);
        $this->assertSame('ckey', $service->lastProvider());
        $this->assertSame('phuocanh421994/Qwen_Image_3.0_Pro', $service->lastModel());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.xah.io/v1/images/generations')
                && ($request['model'] ?? null) === 'phuocanh421994/Qwen_Image_3.0_Pro';
        });
    }

    public function test_custom_provider_slug_ranked_in_custom_family(): void
    {
        StudioProvider::create([
            'slug' => 'ckey', 'name' => 'CKEY', 'protocol' => 'openai',
            'base_url' => 'https://api.xah.io/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'ckey', 'enabled' => true,
        ]);

        $this->assertSame('custom', studio_provider_family('ckey'));
        $this->assertSame(10, studio_provider_rank('ckey'));
    }

    public function test_provider_registry_exposes_flow_metadata(): void
    {
        $this->actingAs($this->admin());
        $data = $this->getJson('/api/settings-vue/data')->assertOk()->json();

        $this->assertSame('qwen,custom,flux,gemini', $data['provider_priority']);
        $this->assertArrayHasKey('qwen', $data['flow_counts']);

        $qwen = collect($data['providers'])->firstWhere('slug', 'qwen');
        $this->assertSame('qwen', $qwen['family']);
        $this->assertSame(0, $qwen['rank']);
    }
    // ── Lỗi thực tế: dán KHOÁ API vào ô 'key ref' (vốn là slug nhóm key) ──────

    public function test_provider_rejects_real_api_key_in_key_ref_with_clear_message(): void
    {
        $this->actingAs($this->admin());

        // Khoá thật dài > 60 ký tự từng gây lỗi khô khan:
        // "The api key ref field must not be greater than 60 characters."
        $key = 'sk-ws-'.str_repeat('a', 120);

        $res = $this->postJson('/api/settings-vue/providers', [
            'slug' => 'ckey',
            'name' => 'CKEY',
            'protocol' => 'openai',
            'base_url' => 'https://api.xah.io/v1',
            'api_key_ref' => $key,
        ])->assertStatus(422);

        $msg = (string) ($res->json('errors.api_key_ref.0') ?? $res->json('message'));
        $this->assertStringContainsString('NHÓM KEY', $msg);
        $this->assertStringContainsString('API Keys', $msg);
        $this->assertDatabaseMissing('studio_providers', ['slug' => 'ckey']);
    }

    public function test_short_key_ref_with_invalid_slug_chars_is_rejected(): void
    {
        $this->actingAs($this->admin());

        // Ky tu khong hop le voi slug nhom key (dau cham, khoang trang) → 422.
        $this->postJson('/api/settings-vue/providers', [
            'slug' => 'ckey',
            'name' => 'CKEY',
            'protocol' => 'openai',
            'base_url' => 'https://api.xah.io/v1',
            'api_key_ref' => 'ckey key',
        ])->assertStatus(422);
    }

    public function test_provider_accepts_proper_slug_key_ref(): void
    {
        $this->actingAs($this->admin());

        $this->postJson('/api/settings-vue/providers', [
            'slug' => 'ckey',
            'name' => 'CKEY',
            'protocol' => 'openai',
            'base_url' => 'https://api.xah.io/v1',
            'api_key_ref' => 'ckey-gateway',
        ])->assertStatus(201);

        $this->assertDatabaseHas('studio_providers', ['slug' => 'ckey', 'api_key_ref' => 'ckey-gateway']);
    }
}
