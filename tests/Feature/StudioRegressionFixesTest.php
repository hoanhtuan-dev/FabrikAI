<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\StylistGarmentType;
use App\Models\StylistPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Test HỒI QUY cho các vá của vòng 1–6 (STUDIO_REVIEW.md §1/§3) — những fix CHƯA có test phủ.
 *
 * Bối cảnh: bộ test port từ app cũ phủ các vá cũ (S1–S11). Các lỗi phát hiện SAU đó —
 * N1 (bypass SSRF), N5 (svg), N6 (IDOR suggest-library), N7 (ghi DB ẩn danh), N8 (rò exception),
 * N15 (route chết), M02 (double-refund), M07 (forward URL vision), M16 (LIKE wildcard) — chỉ được
 * xác minh bằng script một lần. File này khoá chúng lại để không tái phát âm thầm.
 */
class StudioRegressionFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    // ── N1/N4: helper SSRF dùng chung ────────────────────────────────────

    public function test_fetch_remote_bytes_rejects_non_http_schemes(): void
    {
        $this->assertNull(studio_fetch_remote_bytes('file:///etc/passwd'));
        $this->assertNull(studio_fetch_remote_bytes('ftp://example.com/x.png'));
        $this->assertNull(studio_fetch_remote_bytes('data:image/png;base64,AAAA'));
        $this->assertNull(studio_fetch_remote_bytes(''));
    }

    public function test_fetch_remote_bytes_enforces_host_allowlist_when_configured(): void
    {
        set_setting('studio_remote_image_hosts', 'cdn.example.com');

        Http::fake(['*' => Http::response('BYTES', 200)]);

        // Host trong allowlist -> tải được.
        $this->assertSame('BYTES', studio_fetch_remote_bytes('https://cdn.example.com/a.png'));
        // Subdomain của host trong allowlist cũng được.
        $this->assertSame('BYTES', studio_fetch_remote_bytes('https://img.cdn.example.com/a.png'));
        // Host ngoài allowlist -> chặn, KHÔNG gọi ra ngoài.
        $this->assertNull(studio_fetch_remote_bytes('https://evil.example.org/a.png'));

        Http::assertSentCount(2);
    }

    public function test_fetch_remote_bytes_caps_size(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 2048), 200)]);

        $this->assertNull(studio_fetch_remote_bytes('https://example.com/big.png', 1024));
        $this->assertNotNull(studio_fetch_remote_bytes('https://example.com/big.png', 4096));
    }

    // ── M07: không forward URL remote tuỳ ý cho provider vision ──────────

    public function test_vision_image_url_keeps_local_and_app_host(): void
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        // Đường bình thường: '/storage/...' -> URL tuyệt đối của chính app.
        $this->assertStringStartsWith('http', studio_vision_image_url('/storage/studio/a.png'));

        // Host của chính app -> giữ nguyên.
        $same = 'https://'.$appHost.'/x.png';
        $this->assertSame($same, studio_vision_image_url($same));
    }

    public function test_vision_image_url_blocks_foreign_host(): void
    {
        // Không có allowlist -> host lạ bị đổi về URL của chính app (fail closed).
        $out = studio_vision_image_url('https://evil.example.org/internal.png');
        $this->assertStringNotContainsString('evil.example.org', $out);

        // Có allowlist -> host trong danh sách được giữ nguyên.
        set_setting('studio_remote_image_hosts', 'cdn.example.com');
        $keep = 'https://cdn.example.com/a.png';
        $this->assertSame($keep, studio_vision_image_url($keep));
    }

    // ── N6: project_id ở suggest-library phải scope theo owner ───────────

    public function test_suggest_library_rejects_foreign_project_id(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $foreign = $other->projects()->create(['name' => 'Dự án người khác', 'base_concept' => 'x']);

        $this->actingAs($admin)
            ->postJson('/api/suggest-library/save', [
                'reference_url' => '/storage/studio/whatever.png',
                'project_id' => $foreign->id,
            ])
            ->assertStatus(422);
    }

    // ── N7: endpoint public không được ghi DB khi chưa đăng nhập ─────────

    public function test_guest_stylist_prompt_does_not_write_preset(): void
    {
        $catalog = app(\App\Services\StylistCatalog::class);
        $types = $catalog->garmentTypes();
        $type = $types[0]['slug'] ?? null;

        if (! $type) {
            $t = new StylistGarmentType();
            $t->slug = 'ao-test'; $t->name = 'Áo test'; $t->emoji = ''; $t->color = '#000'; $t->sort_order = 900;
            $t->save();
            $type = 'ao-test';
        }

        $before = StylistPreset::count();

        $res = $this->postJson('/api/stylist/prompt', ['type' => $type, 'answers' => ['a' => 'b']]);
        $res->assertOk();

        $this->assertSame($before, StylistPreset::count(), 'Guest gọi /api/stylist/prompt KHÔNG được tạo preset.');
    }

    public function test_authenticated_stylist_prompt_may_write_preset(): void
    {
        $catalog = app(\App\Services\StylistCatalog::class);
        $types = $catalog->garmentTypes();
        $type = $types[0]['slug'] ?? null;

        if (! $type) {
            $t = new StylistGarmentType();
            $t->slug = 'ao-test-auth'; $t->name = 'Áo test'; $t->emoji = ''; $t->color = '#000'; $t->sort_order = 901;
            $t->save();
            $type = 'ao-test-auth';
        }

        $before = StylistPreset::count();

        $this->actingAs($this->admin())
            ->postJson('/api/stylist/prompt', ['type' => $type, 'answers' => ['a' => 'b']])
            ->assertOk();

        // Đã đăng nhập: auto-save preset là hành vi CÓ CHỦ ĐÍCH (>= trước, có thể +1).
        $this->assertGreaterThanOrEqual($before, StylistPreset::count());
    }

    // ── M02: hoàn credit khi generation kẹt — chỉ MỘT lần ────────────────

    public function test_fail_stuck_refunds_credits_only_once(): void
    {
        $user = $this->admin();
        $before = (int) $user->credits_balance;

        $gen = $user->generations()->create([
            'type' => 'image', 'status' => 'processing', 'prompt' => 'regression M02',
            'provider' => 'qwen', 'model' => 'test', 'credits_cost' => 5,
        ]);

        $ctl = app(\App\Http\Controllers\StudioController::class);
        $m = new \ReflectionMethod($ctl, 'failStuck');
        $m->setAccessible(true);

        // Ba lượt gọi liên tiếp (mô phỏng poll show() + reconcile đồng thời).
        $m->invoke($ctl, $gen->fresh(), 'lần 1');
        $m->invoke($ctl, $gen->fresh(), 'lần 2');
        $m->invoke($ctl, $gen->fresh(), 'lần 3');

        $this->assertSame('failed', $gen->fresh()->status);
        $this->assertSame($before + 5, (int) $user->fresh()->credits_balance, 'Chỉ được hoàn credit ĐÚNG MỘT lần.');
    }

    // ── M16: ô tìm kiếm không được hiểu '%'/'_' là wildcard ─────────────

    public function test_library_search_does_not_treat_percent_as_wildcard(): void
    {
        $user = $this->admin();
        foreach (['alpha dress', 'beta dress', 'gamma dress'] as $p) {
            $user->generations()->create([
                'type' => 'image', 'status' => 'completed', 'prompt' => $p,
                'media_url' => '/storage/studio/x.png', 'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 0,
            ]);
        }

        $all = $this->actingAs($user)->getJson('/api/library/data')->assertOk()->json();
        $this->assertGreaterThanOrEqual(3, count($all['items'] ?? []));

        // Gõ '%' phải KHÔNG khớp mọi bản ghi.
        $wild = $this->actingAs($user)->getJson('/api/library/data?q='.urlencode('%'))->assertOk()->json();
        $this->assertLessThan(
            count($all['items'] ?? []),
            count($wild['items'] ?? []),
            "'%' không được hoạt động như wildcard của LIKE."
        );
    }

    // ── N8/N10: cột Generation.error không lộ chi tiết khi không debug ───

    public function test_generation_error_hides_details_unless_debug(): void
    {
        $e = new \RuntimeException('chi-tiet-noi-bo-bi-mat');

        config(['app.debug' => false]);
        $prod = studio_generation_error($e);
        $this->assertStringNotContainsString('chi-tiet-noi-bo-bi-mat', $prod);

        config(['app.debug' => true]);
        $this->assertStringContainsString('chi-tiet-noi-bo-bi-mat', studio_generation_error($e));

        // Prefix hướng dẫn cho người dùng vẫn được giữ.
        config(['app.debug' => false]);
        $prefixed = studio_generation_error($e, 'Render bị ngắt: ');
        $this->assertStringStartsWith('Render bị ngắt: ', $prefixed);
        $this->assertStringNotContainsString('chi-tiet-noi-bo-bi-mat', $prefixed);
    }

    // ── N5: endpoint ảnh public không phục vụ .svg ───────────────────────

    public function test_public_image_route_rejects_svg(): void
    {
        // Endpoint ảnh chính: whitelist đuôi từ chối 'svg' -> 404 (không bao giờ phục vụ SVG).
        $this->get('/api/image/studio/anything.svg')->assertNotFound();

        // Endpoint thumbnail: khi nguồn không phục vụ được, nó trả PNG placeholder (200) — đó là
        // thiết kế. Điều PHẢI giữ là nó KHÔNG bao giờ trả nội dung SVG (nếu trả, SVG sẽ render như
        // document cùng origin = stored XSS).
        $thumb = $this->get('/api/image-thumb/studio/anything.svg');
        $this->assertStringNotContainsString('svg', strtolower((string) $thumb->headers->get('content-type')));
    }

    // ── T15: route trỏ method không tồn tại đã bị xoá ────────────────────

    public function test_dead_sample_route_is_gone(): void
    {
        $this->assertFalse(Route::has('api.studio.asset'), 'Route api.studio.asset (assetSample) phải đã bị xoá.');
        $this->get('/api/studiosample/environment.txt')->assertNotFound();
    }
}
