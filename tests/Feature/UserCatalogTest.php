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
        foreach (['/settings', '/presets', '/stylist-data', '/cai-dat', '/cai-dat/model'] as $uri) {
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

        foreach (['/presets', '/stylist-data', '/cai-dat', '/model-settings?tab=pose'] as $uri) {
            $html = $this->actingAs($c)->get($uri)->assertOk()->getContent();
            $this->assertStringContainsString('data-user-id="'.$c->id.'"', $html,
                $uri.' phải nhúng data-user-id — khoá localStorage của bản tùy chỉnh theo từng user.');
        }
    }

    public function test_blade_template_exposes_the_user_identity(): void
    {
        // [2026-09-20] Ba blade cũ (presets · stylist-data · model-settings) đã gộp thành MỘT.
        // [2026-09-26 · đợt 24] Và nay cả ba khu (Cài đặt của tôi · Hệ thống · Quản trị) dùng CHUNG
        // một blade: studio/hub.blade.php — nơi duy nhất còn nhúng danh tính người dùng xuống app.
        $src = (string) file_get_contents(resource_path('views/studio/hub.blade.php'));
        $this->assertStringContainsString('data-user-id', $src, 'hub.blade.php thiếu data-user-id.');
        $this->assertStringContainsString('data-user-admin', $src, 'hub.blade.php thiếu data-user-admin.');
        $this->assertStringContainsString('data-section', $src,
            'hub.blade.php thiếu data-section — server phải nói cho app biết mở MỤC nào.');
    }

    // ── (e) app dùng lớp lưu trữ cục bộ, không ghi thẳng bảng toàn cục ───

    /**
     * [Quyết định 2026-09-20] Người dùng chốt: bản tùy chỉnh phải theo TÀI KHOẢN, không theo máy.
     * Bản cũ nằm trong localStorage nên đổi máy/đổi trình duyệt là mất sạch — mất dữ liệu thật.
     * Nay lưu trên server qua /api/user-catalogs/{name}; localStorage CHỈ còn là nguồn DI TRÚ một
     * lần, nên nó vẫn phải có trong file — bỏ đi là bỏ rơi tùy chỉnh của người dùng cũ.
     */
    public function test_client_stores_custom_catalog_on_the_server_per_account(): void
    {
        $js = (string) file_get_contents(resource_path('js/studio/composables/useLocalCatalog.js'));

        $this->assertStringContainsString('/api/user-catalogs/', $js,
            'Bản tùy chỉnh phải lưu lên server theo tài khoản.');

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

    /**
     * [Yêu cầu 2026-09-17] Sắp xếp lại activity bar: Prompt Tạo Ảnh + Trợ lý thiết kế LÊN NHÓM
     * TRÊN (bỏ `mt-auto`) và nhường ĐÁY cho nút Cài đặt. Guard này chặn việc lùi về bố cục cũ.
     */
    public function test_settings_sits_at_the_bottom_and_prompt_stylist_moved_up(): void
    {
        $src = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));

        // CHỈ soi activity bar BÊN TRÁI. Thanh bên PHẢI có nút Outputs neo đáy (`mt-auto`) là
        // thiết kế đúng — nếu soi cả file thì test bắt oan nút đó.
        $start = strpos($src, '<nav class="activity-bar');
        $this->assertNotFalse($start, 'Không tìm thấy activity bar bên trái.');
        $end = strpos($src, '</nav>', $start);
        $nav = substr($src, (int) $start, (int) $end - (int) $start);

        $this->assertStringNotContainsString('activity-btn mt-auto', $nav,
            'Trong activity bar TRÁI, không nút công cụ nào được neo đáy nữa — Prompt/Trợ lý phải ở nhóm TRÊN.');

        $settingsPos = strpos($nav, 'class="relative mt-auto"');
        $this->assertNotFalse($settingsPos, 'Nút Cài đặt phải nằm ở ĐÁY activity bar (mt-auto).');

        // [Sửa 2026-09-17] Nút nay SINH TỪ CẤU HÌNH nên không còn nhãn viết cứng trong template.
        // Bất biến "ở trên nút Cài đặt" nay nằm ở THỨ TỰ CẤU HÌNH: vòng lặp render mọi nút đứng
        // TRƯỚC khối Cài đặt ghim đáy.
        $loopPos = strpos($nav, 'v-for="a in activityBar"');
        $this->assertNotFalse($loopPos, 'Thanh công cụ phải render nút từ cấu hình.');
        $this->assertLessThan($settingsPos, $loopPos,
            'Mọi nút (gồm Prompt Tạo Ảnh · Trợ lý thiết kế) phải nằm TRÊN khối Cài đặt ghim đáy.');

        // Và trong cấu hình, 2 nút popup đứng TRƯỚC mục ghim.
        $ids = \App\Services\StudioGuiConfig::defaultIds();
        $this->assertLessThan(array_search('settings', $ids, true), array_search('prompt', $ids, true),
            'Prompt Tạo Ảnh phải đứng trước mục ghim ở đáy.');
        $this->assertLessThan(array_search('settings', $ids, true), array_search('stylist', $ids, true),
            'Trợ lý thiết kế phải đứng trước mục ghim ở đáy.');

        // [2026-09-20] Menu Cài đặt nay trỏ về KHU HỢP NHẤT /cai-dat/<mục> thay vì 3 trang rời rạc.
        $this->assertStringContainsString('href="/cai-dat/presets"', $src,
            'Menu Cài đặt phải dẫn tới khu cài đặt đã hợp nhất (mục Preset).');
        $this->assertStringContainsString('href="/cai-dat/model"', $src,
            'Menu Cài đặt phải có lối vào mục Khuôn mặt.');
        $this->assertStringContainsString('href="/cai-dat/pose"', $src,
            'Menu Cài đặt phải có lối vào mục Dáng pose.');
        $this->assertStringContainsString('href="/cai-dat/stylist"', $src,
            'Menu Cài đặt phải có lối vào mục Trợ lý thiết kế.');
    }

    public function test_both_sections_use_the_shared_catalog_layer(): void
    {
        $targets = [
            'PresetSection.vue' => resource_path('js/studio/components/settings/PresetSection.vue'),
            'StylistSection.vue' => resource_path('js/studio/components/settings/StylistSection.vue'),
        ];

        foreach ($targets as $label => $path) {
            $src = (string) file_get_contents($path);
            $this->assertStringContainsString('useLocalCatalog', $src, $label.' phải dùng lớp lưu trữ cục bộ.');
            $this->assertStringContainsString('recompute', $src, $label.' phải ghép lại baseline sau khi sửa.');
        }
    }

    /**
     * Bất biến của khu hợp nhất: mỗi lối vào mở ĐÚNG mục của nó.
     *
     * Guard này chặn lỗi nối dây rất khó thấy bằng mắt — cả 4 URL đều trả về CÙNG một app, nên nếu
     * /model-settings?tab=pose lại mở mục Khuôn mặt thì nhìn bề ngoài trang vẫn chạy bình thường.
     */
    public function test_each_entry_point_opens_the_matching_settings_section(): void
    {
        $c = $this->customer();

        $expect = [
            '/cai-dat' => 'presets',
            '/cai-dat/presets' => 'presets',
            '/cai-dat/model' => 'model',
            '/cai-dat/pose' => 'pose',
            '/cai-dat/stylist' => 'stylist',
            '/presets' => 'presets',
            '/stylist-data' => 'stylist',
            '/model-settings' => 'model',
            '/model-settings?tab=model' => 'model',
            '/model-settings?tab=pose' => 'pose',
        ];

        foreach ($expect as $uri => $section) {
            $html = $this->actingAs($c)->get($uri)->assertOk()->getContent();
            $this->assertStringContainsString('data-section="'.$section.'"', $html,
                $uri.' phải mở mục '.$section.'.');
        }
    }

    /** Mục không tồn tại trên URL phải là 404, không được render một mục tuỳ tiện. */
    public function test_unknown_settings_section_is_not_found(): void
    {
        $this->actingAs($this->customer())->get('/cai-dat/khong-ton-tai')->assertNotFound();
    }
}
