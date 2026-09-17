<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Quyết định 2026-09-17] PHÂN QUYỀN 3 TRANG CATALOG + TÙY CHỈNH LƯU CỤC BỘ.
 *
 * Người dùng chốt:
 *   · `/settings`                    → cấp OWNER (cài đặt toàn cục: API key · model registry)
 *   · `/presets` · `/stylist-data`   → cấp USER, tùy chỉnh LƯU CỤC BỘ trên máy khách.
 *
 * Trước đây cả 3 shell đều nằm trong nhóm 'SPA pages (Blade shells)' KHÔNG middleware ⇒ ai cũng
 * tải được, kể cả vỏ trang cấu hình. Bất biến khoá ở đây:
 *   (a) khách ⇒ về trang đăng nhập;
 *   (b) customer: 403 ở /settings nhưng 200 ở /presets + /stylist-data;
 *   (c) admin: 200 cả 3;
 *   (d) bản tùy chỉnh phải theo TỪNG user (khoá lưu trữ có userId) và lưu ở localStorage.
 */
class UserCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    // ── (a) khách bị chặn ở cả 3 ─────────────────────────────────────────

    public function test_guest_is_redirected_to_login_on_all_three_pages(): void
    {
        foreach (['/settings', '/presets', '/stylist-data'] as $uri) {
            $this->get($uri)->assertRedirect('/dang-nhap');
        }
    }

    // ── (b) cấp OWNER cho /settings ──────────────────────────────────────

    public function test_customer_cannot_open_settings(): void
    {
        $this->actingAs($this->customer())->get('/settings')->assertForbidden();
    }

    public function test_admin_can_open_settings(): void
    {
        $this->actingAs($this->admin())->get('/settings')->assertOk();
    }

    // ── (c) cấp USER cho /presets + /stylist-data ────────────────────────

    public function test_customer_can_open_presets_and_stylist_data(): void
    {
        $c = $this->customer();
        $this->actingAs($c)->get('/presets')->assertOk();
        $this->actingAs($c)->get('/stylist-data')->assertOk();
    }

    public function test_admin_can_open_presets_and_stylist_data(): void
    {
        $a = $this->admin();
        $this->actingAs($a)->get('/presets')->assertOk();
        $this->actingAs($a)->get('/stylist-data')->assertOk();
    }

    // ── (d) trang phải mang danh tính user để khoá lưu trữ cục bộ ────────

    public function test_user_pages_expose_the_user_id_for_local_storage(): void
    {
        $c = $this->customer();

        foreach (['/presets', '/stylist-data'] as $uri) {
            $html = $this->actingAs($c)->get($uri)->assertOk()->getContent();
            $this->assertStringContainsString('data-user-id="'.$c->id.'"', $html,
                $uri.' phải nhúng data-user-id — khoá localStorage của bản tùy chỉnh theo từng user.');
        }
    }

    public function test_blade_templates_expose_the_user_identity(): void
    {
        foreach (['presets', 'stylist-data'] as $view) {
            $src = (string) file_get_contents(resource_path('views/studio/'.$view.'.blade.php'));
            $this->assertStringContainsString('data-user-id', $src, $view.'.blade.php thiếu data-user-id.');
        }
    }

    // ── (e) app dùng lớp lưu trữ cục bộ, không ghi thẳng bảng toàn cục ───

    public function test_client_stores_custom_catalog_locally_per_user(): void
    {
        $js = (string) file_get_contents(resource_path('js/studio/composables/useLocalCatalog.js'));

        $this->assertStringContainsString('localStorage', $js, 'Bản tùy chỉnh phải lưu ở localStorage.');
        $this->assertStringContainsString('currentUserId', $js,
            'Khoá lưu trữ phải kèm userId — hai user chung máy không được thấy bản của nhau.');
        // Tên miền riêng để không đụng khoá của trang khác.
        $this->assertStringContainsString("'fabrikai:'", $js, 'Khoá phải có tiền tố riêng của app.');
    }

    /**
     * Lõi ghép baseline ⊕ bản của user là phần dễ sai nhất, mà repo KHÔNG có JS test runner.
     * Nó được kiểm bằng `node scripts/check-local-catalog.mjs` (chạy cả trong CI lẫn tay).
     * Thiếu node hoặc host chặn shell_exec ⇒ skip, không làm đỏ suite ở môi trường khác.
     */
    public function test_local_catalog_logic_passes_its_node_self_check(): void
    {
        $node = @shell_exec('command -v node 2>/dev/null');
        if (! is_string($node) || trim($node) === '') {
            $this->markTestSkipped('Không có node trong PATH — bỏ qua self-check JS.');
        }

        $out = [];
        $rc = 1;
        @exec(escapeshellcmd(trim($node)).' '.escapeshellarg(base_path('scripts/check-local-catalog.mjs')).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "check-local-catalog.mjs thất bại:\n".implode("\n", $out));
    }

    /**
     * Hệ quả trực tiếp của việc /settings thành cấp OWNER: link tới nó ở trang KHÁCH dùng
     * (ConceptCard) phải được gate `is_admin`, nếu không khách bấm vào chỉ nhận 403.
     */
    public function test_settings_link_is_gated_for_non_admins(): void
    {
        $src = (string) file_get_contents(resource_path('js/studio/components/ConceptCard.vue'));

        $this->assertMatchesRegularExpression(
            '#<a[^>]*v-if="store\.user && store\.user\.is_admin"[^>]*href="/settings"#',
            $src,
            'Link /settings trong ConceptCard phải gate is_admin — /settings nay là cấp owner.'
        );
    }

    public function test_both_pages_use_the_local_catalog(): void
    {
        $targets = [
            'PresetsApp.vue' => resource_path('js/studio/PresetsApp.vue'),
            'StylistDataManager.vue' => resource_path('js/studio/components/StylistDataManager.vue'),
        ];

        foreach ($targets as $label => $path) {
            $src = (string) file_get_contents($path);
            $this->assertStringContainsString('useLocalCatalog', $src, $label.' phải dùng lớp lưu trữ cục bộ.');
            $this->assertStringContainsString('recompute', $src, $label.' phải ghép lại baseline sau khi sửa.');
        }
    }
}
