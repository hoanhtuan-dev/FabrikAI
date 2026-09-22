<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * THANH TIÊU ĐỀ STUDIO + Ô MÔ TẢ Ở MÀN HÌNH TRỐNG (2026-09-26 · đợt 27).
 *
 * Ba yêu cầu của chủ dự án, khoá lại thành bất biến:
 *   1. header theo chuẩn daisyUI (navbar + hai nhóm start/end),
 *   2. mọi việc thuộc TÀI KHOẢN nằm trong MỘT menu (nút Cài đặt riêng đã bỏ, Đăng xuất gộp vào),
 *   3. ô mô tả ở màn hình trống cuộn được · ẩn được · gọi lại được, và có TAB TRÒ CHUYỆN lấy dữ liệu
 *      thật (TrendRadar của shop + kho thiết kế cũ) chứ không bịa câu trả lời.
 */
class StudioHeaderAndPromptTest extends TestCase
{
    private function app(): string
    {
        return (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
    }

    private function empty(): string
    {
        return (string) file_get_contents(resource_path('js/studio/components/CanvasEmptyState.vue'));
    }

    /** 1. Header dùng đúng bộ lớp của daisyUI, nội dung gom thành hai nhóm rõ ràng. */
    public function test_the_studio_header_uses_daisyui_navbar_groups(): void
    {
        $app = $this->app();

        $this->assertStringContainsString('class="navbar elev-bar', $app,
            'Thanh tiêu đề Studio phải dùng .navbar của daisyUI (kèm .elev-bar để giữ độ nổi đã chốt).'
        );
        $this->assertStringContainsString('navbar-start', $app, 'Thiếu nhóm trái (thương hiệu + bối cảnh).');
        $this->assertStringContainsString('navbar-end', $app, 'Thiếu nhóm phải (công cụ · credit · tài khoản).');

        // Tìm theo CLASS trong markup (không phải theo chữ trong chú thích giải thích phía trên).
        $start = strpos($app, 'class="navbar-start');
        $end = strpos($app, 'class="navbar-end');
        $brand = strpos($app, 'FabrikAI</span>');
        $this->assertNotFalse($brand, 'Nhóm trái phải có thương hiệu FabrikAI.');
        $this->assertTrue($brand > $start && $brand < $end, 'Thương hiệu phải nằm trong navbar-start.');
    }

    /** 2. MỘT menu tài khoản: danh tính + cài đặt + đăng xuất; nút Cài đặt riêng đã bị bỏ. */
    public function test_the_account_menu_holds_identity_settings_and_logout(): void
    {
        $app = $this->app();

        $this->assertStringContainsString('data-account-toggle', $app, 'Thiếu nút mở menu tài khoản.');
        $this->assertStringContainsString('data-account-menu', $app, 'Thiếu menu tài khoản.');
        $this->assertStringContainsString('dropdown dropdown-end', $app, 'Menu tài khoản phải là dropdown của daisyUI.');
        $this->assertStringContainsString('menu dropdown-content', $app, 'Nội dung menu phải dùng .menu của daisyUI.');

        // Đếm trong PHẠM VI MENU: chú thích trong mã cũng nhắc tới chữ này khi giải thích vì sao nút rời đã bỏ.
        $menu = substr($app, strpos($app, 'data-account-menu'));
        $menu = substr($menu, 0, strpos($menu, '</ul>'));
        // Bất biến thật: ĐÚNG MỘT hành động đăng xuất trong menu (nhãn + title là hai chuỗi, không phải hai nút).
        $this->assertSame(1, substr_count($menu, 'logout()'), 'Menu tài khoản phải có đúng MỘT hành động đăng xuất.');
        $this->assertStringContainsString('Đăng xuất', $menu);
        $this->assertStringContainsString('href="/cai-dat"', $menu,
            'Lối vào cài đặt trong menu phải là /cai-dat (trang hợp nhất ba khu).'
        );

        // Studio KHÔNG đi thẳng tới /settings nữa: mọi lối vào cài đặt đi qua /cai-dat (trang hợp
        // nhất ba khu). Nhờ vậy owner và tài khoản thường vào cùng một chỗ, và máy chủ tự ẩn khu
        // chỉ owner thay vì để họ bấm vào rồi nhận 403.
        $this->assertStringNotContainsString('/settings', $app,
            'Studio còn trỏ thẳng tới /settings — phải đi qua /cai-dat.'
        );
        $this->assertStringNotContainsString('title="Đăng xuất khỏi tài khoản">Đăng xuất</button>', $app,
            'Nút Đăng xuất rời đã bỏ — nay nằm trong menu tài khoản.'
        );
    }

    /** 3. Biểu tượng đăng xuất lấy từ nguồn icon duy nhất (icons.json). */
    public function test_the_logout_icon_comes_from_the_shared_icon_source(): void
    {
        $icons = (string) file_get_contents(resource_path('js/studio/icons.json'));
        $this->assertStringContainsString('"logout"', $icons, 'icons.json thiếu biểu tượng logout.');
        $this->assertStringContainsString('name="logout"', $this->app(), 'Menu tài khoản phải dùng biểu tượng logout.');
        $this->assertTrue(\App\Support\IconRegistry::has('logout'), 'IconRegistry phải thấy logout (PHP và Vue dùng chung file).');
    }

    /** 4. Ô mô tả: cuộn được · ẩn được · gọi lại được. */
    public function test_the_prompt_can_scroll_collapse_and_be_called_back(): void
    {
        $empty = $this->empty();

        $this->assertStringContainsString('max-h-[38vh]', $empty, 'Ô mô tả phải có trần chiều cao.');
        $this->assertStringContainsString('overflow-y-auto', $empty, 'Ô mô tả phải tự cuộn khi mô tả dài.');
        $this->assertStringContainsString('absolute inset-0 z-0', $empty, 'Màn hình trống vẫn phải nằm dưới layer.');

        $this->assertStringContainsString('fabrikai:studio:prompt-collapsed', $empty, 'Trạng thái ẩn phải được nhớ (khoá fabrikai:).');
        $this->assertStringContainsString('data-prompt-collapse', $empty, 'Thiếu nút ẩn ô mô tả.');
        $this->assertStringContainsString('data-prompt-recall', $empty, 'Thiếu nút gọi lại ô mô tả.');
        $this->assertStringContainsString('Mở ô tạo ảnh', $empty, 'Nút gọi lại phải nói rõ nó mở lại ô mô tả.');

        $this->assertStringContainsString('store.generateImage()', $empty);
        $this->assertStringContainsString('canvas-quick-prompt', $empty);
    }

    /** 5. Tab Trò chuyện: lấy dữ liệu THẬT (radar + nguồn + kho thiết kế), không bịa. */
    public function test_the_chat_tab_searches_trends_and_the_own_design_archive(): void
    {
        $empty = $this->empty();

        $this->assertStringContainsString('data-tab="chat"', $empty, 'Thiếu tab Trò chuyện.');
        $this->assertStringContainsString('data-tab="compose"', $empty, 'Thiếu tab Tạo ảnh.');
        $this->assertStringContainsString('loadTrendRadar', $empty,
            'Tab Trò chuyện phải đọc tín hiệu thị trường qua store (dùng chung cache, không gọi lại máy chủ).'
        );
        $this->assertStringContainsString('/api/design-search', $empty,
            'Tab Trò chuyện phải tìm được trong kho thiết kế cũ của chính người dùng.'
        );
        $this->assertStringContainsString('data-chat-suggestion', $empty, 'Thiếu câu hỏi gợi ý.');
        $this->assertStringContainsString('data-use-trend', $empty, 'Thiếu nút đưa xu hướng vào ô mô tả (cầu nối chat - tạo ảnh).');

        $this->assertStringContainsString('Không thấy mục nào khớp đúng', $empty,
            'Không được để người dùng tưởng câu trả lời là kết quả khớp khi thực ra là xu hướng chung.'
        );
        $this->assertStringContainsString('Chưa có tín hiệu xu hướng nào để trả lời', $empty,
            'Không có dữ liệu thì phải nói thẳng là không có.'
        );
    }
}
