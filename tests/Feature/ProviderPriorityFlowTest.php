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

    public function test_default_flow_is_qwen_custom_flux_deepseek_gemini(): void
    {
        $this->assertSame(['qwen', 'custom', 'flux', 'deepseek', 'gemini'], studio_provider_priority_flow());
        $this->assertSame('qwen', config('studio.image_provider'));
        // 'other' là nhóm HỨNG, không nằm trong luồng mặc định.
        $this->assertNotContains('other', studio_provider_default_flow());
    }

    public function test_provider_ranks_follow_the_flow(): void
    {
        $this->assertSame(0, studio_provider_rank('qwen'));
        $this->assertSame(0, studio_provider_rank('wan'));
        $this->assertSame(20, studio_provider_rank('fal'));
        $this->assertSame(30, studio_provider_rank('deepseek'), 'deepseek phải đứng TRƯỚC gemini');
        $this->assertSame(40, studio_provider_rank('gemini'));
        $this->assertLessThan(studio_provider_rank('gemini'), studio_provider_rank('deepseek'));
    }

    public function test_deepseek_has_its_own_family_not_other(): void
    {
        $this->assertSame('deepseek', studio_provider_family('deepseek'));
        $this->assertContains('deepseek', studio_provider_families());
        // Nhóm deepseek được vẽ trong tab Luồng ưu tiên nên flow_counts phải có khoá này.
        $this->actingAs($this->admin());
        $data = $this->getJson('/api/settings-vue/data')->assertOk()->json();
        $this->assertArrayHasKey('deepseek', $data['flow_counts']);
        $this->assertSame(5, collect($data['providers'])->firstWhere('slug', 'deepseek')['priority']);
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

    public function test_reordering_flow_accepts_deepseek(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/settings-vue/provider-priority', ['value' => 'qwen,deepseek,gemini'])
            ->assertOk();

        $this->assertSame(['qwen', 'deepseek', 'gemini'], studio_provider_priority_flow());
        $this->assertSame(10, studio_provider_rank('deepseek'));
        $this->assertSame(20, studio_provider_rank('gemini'));
    }

    // ── 4. Đồng bộ catalog ────────────────────────────────────────────────

    public function test_sync_catalog_imports_latest_qwen_models(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/settings-vue/sync-catalog')
            ->assertOk()
            ->assertJson(['ok' => true, 'created' => 30]);

        $this->assertDatabaseHas('studio_models', [
            'group' => 'image', 'provider' => 'qwen', 'model_id' => 'qwen-image-3.0-pro',
        ]);
        // DeepSeek có mặt trong catalog cho nhóm văn bản (đứng trước gemini trong luồng).
        $this->assertDatabaseHas('studio_models', [
            'group' => 'prompt', 'provider' => 'deepseek', 'model_id' => 'deepseek-chat',
        ]);
        $this->assertDatabaseHas('studio_models', [
            'group' => 'translate', 'provider' => 'deepseek', 'model_id' => 'deepseek-chat',
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

        $this->assertSame('qwen,custom,flux,deepseek,gemini', $data['provider_priority']);
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
    // ── Xoá / sửa custom provider (route bind theo SLUG, không phải id) ───────

    public function test_custom_provider_can_be_deleted_by_slug(): void
    {
        $this->actingAs($this->admin());
        $p = StudioProvider::create([
            'slug' => 'openrouter', 'name' => 'OpenRouter', 'protocol' => 'openai',
            'base_url' => 'https://openrouter.ai/api/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'openrouter', 'enabled' => true,
        ]);

        // Đường UI dùng: DELETE /providers/{slug}
        $this->deleteJson('/api/settings-vue/providers/openrouter')->assertOk();
        $this->assertDatabaseMissing('studio_providers', ['slug' => 'openrouter']);

        // Gửi id số (lỗi cũ) phải là 404 chứ KHÔNG được xoá nhầm bản ghi khác.
        $this->deleteJson('/api/settings-vue/providers/'.$p->id)->assertStatus(404);
    }

    public function test_custom_provider_can_be_edited_by_slug(): void
    {
        $this->actingAs($this->admin());
        StudioProvider::create([
            'slug' => 'together', 'name' => 'Together', 'protocol' => 'openai',
            'base_url' => 'https://api.together.xyz/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'together', 'enabled' => true,
        ]);

        $this->putJson('/api/settings-vue/providers/together', [
            'name' => 'Together AI',
            'protocol' => 'openai',
            'base_url' => 'https://api.together.xyz/v1',
            'api_key_ref' => 'together',
            'priority' => 20,
            'enabled' => true,
        ])->assertOk();

        $this->assertDatabaseHas('studio_providers', ['slug' => 'together', 'name' => 'Together AI', 'priority' => 20]);
    }

    // ── Ưu tiên TRONG NỘI BỘ nhóm custom provider ────────────────────────────

    public function test_custom_providers_are_ordered_by_their_own_priority(): void
    {
        // Ba custom provider cùng nhóm: priority quyết định route nào thử trước.
        $cheap = StudioProvider::create([
            'slug' => 'zeta', 'name' => 'Zeta', 'protocol' => 'openai',
            'base_url' => 'https://zeta.test/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'zeta', 'priority' => 5, 'enabled' => true,
        ]);
        StudioProvider::create([
            'slug' => 'alpha', 'name' => 'Alpha', 'protocol' => 'openai',
            'base_url' => 'https://alpha.test/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'alpha', 'priority' => 30, 'enabled' => true,
        ]);
        StudioProvider::create([
            'slug' => 'mid', 'name' => 'Mid', 'protocol' => 'openai',
            'base_url' => 'https://mid.test/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'mid', 'priority' => 15, 'enabled' => true,
        ]);

        foreach (['alpha', 'mid', 'zeta'] as $slug) {
            StudioModel::create([
                'group' => 'image', 'name' => ucfirst($slug).' image', 'provider' => $slug,
                'model_id' => $slug.'-image', 'api_key_ref' => $slug, 'priority' => 5, 'enabled' => true,
            ]);
        }

        // Cả ba cùng nhóm 'custom' (rank 10) nên thứ tự do priority CỦA PROVIDER quyết định.
        $order = array_map(fn ($c) => $c['provider'], studio_model_candidates('image'));
        $custom = array_values(array_filter($order, fn ($p) => in_array($p, ['alpha', 'mid', 'zeta'], true)));

        $this->assertSame(['alpha', 'mid', 'zeta'], $custom);
        $this->assertSame(30, studio_provider_priority('alpha'));
        $this->assertSame(5, studio_provider_priority('zeta'));

        // Đổi priority: zeta lên đầu ngay (memo phải được xoá qua model event).
        $cheap->update(['priority' => 99]);
        $order2 = array_map(fn ($c) => $c['provider'], studio_model_candidates('image'));
        $custom2 = array_values(array_filter($order2, fn ($p) => in_array($p, ['alpha', 'mid', 'zeta'], true)));
        $this->assertSame(['zeta', 'alpha', 'mid'], $custom2);
    }

    public function test_builtin_providers_have_priority_inside_their_family(): void
    {
        // Trong nhóm qwen: qwen (10) trên qwen_edit (9) trên dashscope (8) trên wan (7).
        $this->assertGreaterThan(studio_provider_priority('qwen_edit'), studio_provider_priority('qwen'));
        $this->assertGreaterThan(studio_provider_priority('dashscope'), studio_provider_priority('qwen_edit'));
        $this->assertGreaterThan(studio_provider_priority('wan'), studio_provider_priority('dashscope'));
        $this->assertGreaterThan(studio_provider_priority('veo'), studio_provider_priority('gemini'));
    }

    // ── Template khai báo provider (CKEY chỉ là một mục, không hardcode) ──────

    public function test_provider_templates_are_exposed_and_generic(): void
    {
        $templates = studio_provider_templates();
        $this->assertArrayHasKey('ckey', $templates);
        $this->assertGreaterThanOrEqual(5, count($templates), 'Phải có nhiều mẫu, không chỉ CKEY');

        foreach ($templates as $key => $tpl) {
            $this->assertArrayHasKey('label', $tpl, $key);
            $this->assertArrayHasKey('protocol', $tpl, $key);
            $this->assertArrayHasKey('base_url', $tpl, $key);
            $this->assertContains($tpl['protocol'], ['openai', 'dashscope', 'gemini'], $key);
        }

        $this->actingAs($this->admin());
        $data = $this->getJson('/api/settings-vue/data')->assertOk()->json();
        $this->assertArrayHasKey('provider_templates', $data);
        $this->assertArrayHasKey('ckey', $data['provider_templates']);
    }

    public function test_provider_registry_rows_carry_priority(): void
    {
        StudioProvider::create([
            'slug' => 'mid', 'name' => 'Mid', 'protocol' => 'openai',
            'base_url' => 'https://mid.test/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'mid', 'priority' => 42, 'enabled' => true,
        ]);

        $this->actingAs($this->admin());
        $data = $this->getJson('/api/settings-vue/data')->assertOk()->json();
        $mid = collect($data['providers'])->firstWhere('slug', 'mid');
        $this->assertSame(42, $mid['priority']);
        $this->assertSame(10, $mid['rank']); // nhóm custom

        $qwen = collect($data['providers'])->firstWhere('slug', 'qwen');
        $this->assertSame(10, $qwen['priority']);
    }
    // ── Migration: chèn deepseek vào luồng ĐÃ LƯU (trước gemini) ─────────────

    public function test_migration_inserts_deepseek_before_gemini_in_stored_flow(): void
    {
        set_setting('studio_provider_priority', 'custom,qwen,flux,gemini');

        $migration = require database_path('migrations/2026_09_22_000002_add_deepseek_to_provider_flow.php');
        $migration->up();

        // Giữ nguyên thứ tự admin đã đặt, chỉ chèn deepseek ngay TRƯỚC gemini.
        $this->assertSame('custom,qwen,flux,deepseek,gemini', setting('studio_provider_priority'));
        $this->assertSame(['custom', 'qwen', 'flux', 'deepseek', 'gemini'], studio_provider_priority_flow());

        // Idempotent: chạy lần hai không nhân đôi.
        $migration->up();
        $this->assertSame('custom,qwen,flux,deepseek,gemini', setting('studio_provider_priority'));
    }

    public function test_migration_skips_when_no_flow_was_configured(): void
    {
        // Không có setting ⇒ default của config đã có deepseek, migration không tạo rác.
        $migration = require database_path('migrations/2026_09_22_000002_add_deepseek_to_provider_flow.php');
        $migration->up();

        $this->assertNull(setting('studio_provider_priority'));
        $this->assertSame(['qwen', 'custom', 'flux', 'deepseek', 'gemini'], studio_provider_priority_flow());
    }
    public function test_sync_can_be_limited_to_one_provider(): void
    {
        // Admin đã xoá dòng flux (không dùng Fal.ai nữa) và registry CHƯA có deepseek.
        studio_sync_model_catalog();
        StudioModel::where('provider', 'fal')->delete();
        StudioModel::where('provider', 'deepseek')->delete();
        $this->assertSame(0, StudioModel::where('provider', 'fal')->count());
        $this->assertSame(0, StudioModel::where('provider', 'deepseek')->count());

        // Đồng bộ CHỈ nhóm deepseek ⇒ dòng fal đã xoá KHÔNG bị hồi sinh.
        $result = studio_sync_model_catalog('deepseek');
        $this->assertSame(3, $result['created']);
        $this->assertSame(0, StudioModel::where('provider', 'fal')->count());
        $this->assertSame(3, StudioModel::where('provider', 'deepseek')->count());

        // Idempotent khi lọc.
        $again = studio_sync_model_catalog('deepseek');
        $this->assertSame(0, $again['created']);
    }

    public function test_deepseek_models_come_after_qwen_and_before_gemini_in_text_groups(): void
    {
        studio_sync_model_catalog();

        foreach (['prompt', 'translate'] as $group) {
            $providers = array_map(fn ($c) => $c['provider'], studio_task_group_models($group));
            $qwen = array_search('qwen', $providers, true);
            $deepseek = array_search('deepseek', $providers, true);
            $gemini = array_search('gemini', $providers, true);

            $this->assertNotFalse($deepseek, $group.' phải có provider deepseek');
            if ($qwen !== false) {
                $this->assertLessThan($deepseek, $qwen, $group.': qwen đứng trước deepseek');
            }
            if ($gemini !== false) {
                $this->assertLessThan($gemini, $deepseek, $group.': deepseek đứng TRƯỚC gemini');
            }
        }
    }
    // ── Migration phải XOÁ CACHE settings (lỗi thật khi deploy lần đầu) ──────

    public function test_deepseek_migration_invalidates_settings_cache(): void
    {
        set_setting('studio_provider_priority', 'custom,qwen,flux,gemini');
        // Mồi cache: đọc một lần để giá trị cũ nằm trong cache 'settings:all'.
        $this->assertSame('custom,qwen,flux,gemini', setting('studio_provider_priority'));

        $migration = require database_path('migrations/2026_09_22_000002_add_deepseek_to_provider_flow.php');
        $migration->up();

        // Nếu migration ghi thẳng bằng DB::table() thì cache cũ vẫn được trả về ⇒ test này đỏ.
        $this->assertSame('custom,qwen,flux,deepseek,gemini', setting('studio_provider_priority'));
        $this->assertSame(['custom', 'qwen', 'flux', 'deepseek', 'gemini'], studio_provider_priority_flow());
        $this->assertSame(30, studio_provider_rank('deepseek'), 'deepseek phải được xếp hạng sau khi cache xoá');
        $this->assertSame(40, studio_provider_rank('gemini'));
    }
}
