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

    public function test_admin_product_image_url_cannot_traverse_to_env(): void
    {
        $ctl = app(\App\Http\Controllers\Admin\AdminProductController::class);
        $m = new \ReflectionMethod($ctl, 'resolveImagePath');
        $m->setAccessible(true);
        $this->assertNull($m->invoke($ctl, '/../.env'));
        $this->assertNull($m->invoke($ctl, asset('../.env')));
        $this->assertNull($m->invoke($ctl, '/storage/../../.env'));
    }

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
