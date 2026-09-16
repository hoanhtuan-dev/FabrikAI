<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ImageAIService;
use App\Services\ProductAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Test cho đợt vá T2 (tái xác minh 11 nhóm high/critical §3 → vá nhóm CÒN):
 *  - S1 [critical] traversal ở 3 resolver ImageAIService → helper studio_safe_public_file()
 *  - S2 pixel cap (decompression bomb) trong studio_image_decode()
 *  - S3 SSRF guard trong storeRemoteImage()
 *  - S4 stored XSS — escape e() trong stub HTML ProductAIService
 *  - S8 whitelist scope cleanup (422 thay vì ok giả)
 *  - (S5 đã gỡ cùng AdminProductController ở T8 — xem ghi chú trong file)
 */
class StudioSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ── S1: containment helper ────────────────────────────────────────────

    public function test_safe_public_file_accepts_real_file_inside_storage(): void
    {
        Storage::disk('public')->put('studio/test-s1-ok.txt', 'ok');
        try {
            $hit = studio_safe_public_file('studio/test-s1-ok.txt');
            $this->assertNotNull($hit);
            $this->assertStringContainsString('studio/test-s1-ok.txt', str_replace('\\', '/', $hit));
        } finally {
            Storage::disk('public')->delete('studio/test-s1-ok.txt');
        }
    }

    public function test_safe_public_file_rejects_traversal_and_weird_paths(): void
    {
        $this->assertNull(studio_safe_public_file('../../.env'));
        $this->assertNull(studio_safe_public_file('storage/../../.env'));
        $this->assertNull(studio_safe_public_file('studio/../../../.env'));
        $this->assertNull(studio_safe_public_file('studio/..%2f..%2f.env'));
        $this->assertNull(studio_safe_public_file('/etc/passwd'));
        $this->assertNull(studio_safe_public_file(''));
        // File thật ngoài 2 root cho phép — không bao giờ resolve ra ngoài.
        $this->assertNull(studio_safe_public_file('../.env'));
    }

    // ── S5: AdminProductController::resolveImagePath containment ────────
    //
    // ĐÃ GỠ Ở T8 (2026-09-16): AdminProductController nằm trong nhóm 32 controller có 0 route
    // (admin CRUD của storefront — storefront đã bỏ khỏi FabrikAI, StorefrontModuleServiceProvider
    // không được đăng ký trong bootstrap/providers.php). Code + test S5 gốc khôi phục được ở
    // commit cha của commit "chore(T8)".
    //
    // Bản thân bản vá S5 KHÔNG mất coverage: nó chỉ uỷ quyền cho helper dùng chung
    // studio_safe_public_file() — vẫn được phủ đầy đủ bởi StudioLocalFileContainmentTest
    // (chặn '..' + charset + realpath containment) và các test S1 ngay trên file này.

    // ── S6 residual: vision data-uri traversal ──────────────────────────

    public function test_vision_image_data_uri_rejects_traversal(): void
    {
        $this->assertNull(studio_vision_image_data_uri('/storage/../../.env'));
        $this->assertNull(studio_vision_image_data_uri('/../composer.json'));
    }

    public function test_image_ai_resolvers_do_not_leak_env_via_traversal(): void
    {
        $svc = app(ImageAIService::class);
        $m = new \ReflectionMethod($svc, 'resolveImageBinary');
        $m->setAccessible(true);
        // Trước vá: public_path('../.env') is_file → trả nội dung .env (exfil lên provider).
        $this->assertNull($m->invoke($svc, '/storage/../../.env'));
        $this->assertNull($m->invoke($svc, '/storage/../.env'));
        $m2 = new \ReflectionMethod($svc, 'resolveSamplePath');
        $m2->setAccessible(true);
        $hit = $m2->invoke($svc, '/storage/../../.env');
        // Phải rơi về sample placeholder (glob public/samples/*) hoặc null — không bao giờ là .env.
        $this->assertTrue($hit === null || ! str_ends_with($hit, '.env'));
    }

    // ── S2: pixel cap trong studio_image_decode ───────────────────────────

    public function test_image_decode_rejects_decompression_bomb(): void
    {
        config(['studio.image_max_pixels' => 1000]); // 32×32 = 1024 px > 1000
        ob_start();
        imagepng(imagecreatetruecolor(32, 32));
        $png = (string) ob_get_clean();
        $this->assertFalse(studio_image_decode($png));
        // Tắt cap (0) → decode bình thường.
        config(['studio.image_max_pixels' => 0]);
        $this->assertNotFalse(studio_image_decode($png));
    }

    // ── S3: SSRF guard trong storeRemoteImage ─────────────────────────────

    private function invokeStoreRemote(string $url): ?string
    {
        $svc = app(ImageAIService::class);
        $m = new \ReflectionMethod($svc, 'storeRemoteImage');
        $m->setAccessible(true);
        return $m->invoke($svc, $url);
    }

    public function test_store_remote_image_rejects_non_http_schemes(): void
    {
        $this->assertNull($this->invokeStoreRemote('file:///etc/passwd'));
        $this->assertNull($this->invokeStoreRemote('ftp://example.com/x.png'));
        $this->assertNull($this->invokeStoreRemote('gopher://127.0.0.1/'));
    }

    public function test_store_remote_image_fetches_http_with_cap_and_timeout(): void
    {
        Storage::fake('public');
        // Một fake duy nhất với sequence: request 1 → 200 (lưu được), request 2 → 500 (null).
        Http::fake(['*' => Http::sequence()->push('fake-image-bytes', 200)->push('nope', 500)]);
        $hit = $this->invokeStoreRemote('https://cdn.provider.example/out/abc.png');
        $this->assertNotNull($hit);
        $this->assertStringStartsWith('/storage/', $hit);
        // HTTP lỗi → null.
        $this->assertNull($this->invokeStoreRemote('https://cdn.provider.example/out/abc.png'));
        Http::assertSentCount(2);
    }

    public function test_store_remote_image_enforces_host_allowlist(): void
    {
        Storage::fake('public');
        set_setting('studio_remote_image_hosts', 'cdn.provider.example');
        Http::fake(['*' => Http::response('bytes', 200)]);

        // Host trong allowlist -> lưu được.
        $this->assertNotNull($this->invokeStoreRemote('https://cdn.provider.example/a.png'));
        // Subdomain của host trong allowlist cũng được.
        $this->assertNotNull($this->invokeStoreRemote('https://img.cdn.provider.example/b.png'));
        // Host ngoài allowlist -> null, và KHÔNG được gọi ra ngoài.
        $this->assertNull($this->invokeStoreRemote('https://evil.example.org/c.png'));

        Http::assertSentCount(2);
    }

    public function test_store_remote_image_picks_extension_from_magic_bytes(): void
    {
        Storage::fake('public');

        // storeRemoteImage lưu NGUYÊN định dạng provider trả về, chọn đuôi theo magic bytes —
        // đuôi sai sẽ làm endpoint ảnh (whitelist theo đuôi) hoặc trình duyệt xử lý nhầm.
        $png = "\x89PNG\r\n\x1a\n" . 'payload';
        $jpg = "\xFF\xD8\xFF" . 'payload';

        Http::fake(['*' => Http::sequence()->push($png, 200)->push($jpg, 200)]);

        $a = $this->invokeStoreRemote('https://cdn.provider.example/a');
        $b = $this->invokeStoreRemote('https://cdn.provider.example/b');

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertStringEndsWith('.png', $a);
        $this->assertStringEndsWith('.jpg', $b);

        // Phải nằm dưới studio/ và file THẬT SỰ tồn tại trên disk.
        $this->assertStringStartsWith('/storage/studio/', $a);
        Storage::disk('public')->assertExists('studio/'.basename($a));
        Storage::disk('public')->assertExists('studio/'.basename($b));
    }

    // (Cap 50 MiB của storeRemoteImage KHÔNG test ở đây: nó uỷ quyền cho studio_fetch_remote_bytes()
    //  với tham số mặc định, và việc tạo chuỗi >50 MiB trong test rất tốn RAM. Hành vi cap đã được
    //  phủ ở StudioRegressionFixesTest::test_fetch_remote_bytes_caps_size với ngưỡng nhỏ.)

    // ── S4: escape stub HTML ──────────────────────────────────────────────

    public function test_stub_description_escapes_user_and_ai_fields(): void
    {
        $svc = app(ProductAIService::class);
        $m = new \ReflectionMethod($svc, 'stubDescription');
        $m->setAccessible(true);
        $html = $m->invoke($svc, '<script>alert(1)</script>', ['category' => '<b>cat</b>'], ['fabric' => '<i>f</i>', 'colors' => '', 'styles' => '']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<b>cat</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;cat&lt;/b&gt;', $html);
    }

    public function test_stub_refine_escapes_ai_fabric_in_html_variant(): void
    {
        $svc = app(ProductAIService::class);
        $m = new \ReflectionMethod($svc, 'stubRefine');
        $m->setAccessible(true);
        $r = $m->invoke($svc, ['name' => 'Váy test'], ['fabric' => '<img src=x onerror=alert(1)>'], 'desc_variants');
        $html = $r['variants'][1]['description'] ?? '';
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    // ── S8: cleanup scope whitelist ───────────────────────────────────────

    public function test_library_cleanup_rejects_unknown_scope_with_422(): void
    {
        $admin = User::where('email', 'admin@trillfa.com')->first();
        $this->actingAs($admin);
        $this->postJson('/api/library/cleanup', ['scope' => 'everything'])->assertStatus(422);
        $this->postJson('/api/library/cleanup', ['scope' => 'junk'])->assertOk()->assertJsonPath('ok', true);
    }
}
