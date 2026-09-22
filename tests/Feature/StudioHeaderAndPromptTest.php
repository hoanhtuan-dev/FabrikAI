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
        // [đợt 34] Thứ tự do `order` quyết định (hai nhóm navbar-start/navbar-end nay là khung trong
        // suốt `contents`), nên bài này khoá THỨ TỰ THỊ GIÁC: menu · thương hiệu · TÀI KHOẢN · CREDIT
        // ở bên trái; nút điện thoại + khay công cụ đẩy sang phải.
        foreach ([
            'class="icon-btn shrink-0 lg:hidden order-1"' => 'nút menu',
            'class="order-2 flex shrink-0 items-center gap-2"' => 'thương hiệu FabrikAI',
            // [đợt 35] Hai nút này nay nằm trong KHAY TRÁI (data-header-account) — thứ tự trong khay lo
            // bằng order-1/order-3 vì mã nguồn xếp credit trước tài khoản.
            'class="order-1 relative"' => 'nút + menu tài khoản',
            'class="order-3 relative"' => 'nút Gói & credit',
            'class="order-5 ml-auto' => 'nút Bộ sưu tập (điện thoại)',
            'class="order-7 ml-auto hidden' => 'khay điều hướng không gian làm việc',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $app, "Thiếu thứ tự cho {$what}.");
        }

        // [đợt 35] Khay TRÁI phải dùng ĐÚNG bộ lớp của khay PHẢI ⇒ hai bên đồng bộ thị giác.
        $this->assertStringContainsString(
            'order-3 flex shrink-0 items-center gap-1 rounded-xl border border-ink-700 bg-ink-800/60 p-1" data-header-account',
            $app,
            'Khay trái (tài khoản · credit) chưa đồng bộ với khay công cụ bên phải.'
        );
        $this->assertStringContainsString('order-7 ml-auto hidden shrink-0 items-center gap-1 rounded-xl border border-ink-700 bg-ink-800/60 p-1 lg:flex" data-header-workspace', $app,
            'Khay công cụ bên phải phải giữ nguyên bộ lớp đó.');

        $this->assertStringContainsString('>FabrikAI</span>', $app, 'Thương hiệu FabrikAI phải còn trong header.');
    }

    /** 2. MỘT menu tài khoản: danh tính + cài đặt + đăng xuất; nút Cài đặt riêng đã bị bỏ. */
    public function test_the_account_menu_holds_identity_settings_and_logout(): void
    {
        $app = $this->app();

        $this->assertStringContainsString('data-account-toggle', $app, 'Thiếu nút mở menu tài khoản.');
        $this->assertStringContainsString('data-account-menu', $app, 'Thiếu menu tài khoản.');
        // [đợt 33] Menu tài khoản KHÔNG còn dùng anchor positioning của daisyUI (gây hiện sai chỗ và đè
        // nút Outputs trên vài trình duyệt) — nay định vị TƯỜNG MINH như mọi popover khác của Studio.
        // [đợt 34] Nút tài khoản nay ở BÊN TRÁI (cạnh thương hiệu) ⇒ menu mở sang PHẢI: `left-0`.
        $this->assertStringContainsString('absolute left-0 top-full', $app,
            'Menu tài khoản phải định vị tường minh ngay dưới nút, mở sang phải.');
        $this->assertStringContainsString('class="menu absolute left-0 top-full', $app,
            'Nội dung menu vẫn dùng .menu của daisyUI nhưng đặt vị trí tuyệt đối rõ ràng.');

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

        // [đợt 28] Không còn dòng tiêu đề mào đầu — màn hình trống chỉ còn đúng việc để làm.
        $this->assertStringNotContainsString('Tạo ảnh đầu tiên', $empty, 'Dòng tiêu đề phải bị xoá.');
        $this->assertStringNotContainsString('Mô tả trang phục, phong cách, bối cảnh và ánh sáng', $empty, 'Dòng phụ đề phải bị xoá.');

        // Nút xuống dòng cho ô nhập (máy không có Shift+Enter tiện).
        $this->assertStringContainsString('data-prompt-newline', $empty, 'Thiếu nút xuống dòng.');
        $this->assertStringContainsString('insertNewline', $empty, 'Thiếu hàm chèn dấu xuống dòng.');

        $this->assertStringContainsString('fabrikai:studio:prompt-collapsed', $empty, 'Trạng thái ẩn phải được nhớ (khoá fabrikai:).');
        $this->assertStringContainsString('data-prompt-collapse', $empty, 'Thiếu nút ẩn ô mô tả.');
        $this->assertStringContainsString('data-prompt-recall', $empty, 'Thiếu nút gọi lại ô mô tả.');
        $this->assertStringContainsString('Mở ô tạo ảnh', $empty, 'Nút gọi lại phải nói rõ nó mở lại ô mô tả.');

        $this->assertStringContainsString('store.generateImage()', $empty);
        $this->assertStringContainsString('canvas-quick-prompt', $empty);
    }

    /**
     * 5. Tab Trò chuyện: CHAT THẬT theo luồng, có nguồn để tự kiểm, KHÔNG bịa.
     *
     * [ĐỔI CHÍNH SÁCH 2026-09-26 — đọc trước khi "khôi phục" những khẳng định cũ]
     * Bài này TRƯỚC ĐÂY khoá điều NGƯỢC LẠI: nó bắt tab Trò chuyện phải có `loadTrendRadar` +
     * `/api/design-search` và phải hiện câu "Không thấy mục nào khớp đúng …". Đó là mô tả của một đường
     * trả lời do TRÌNH DUYỆT ghép: tách từ khoá từ câu hỏi, chấm điểm khớp trên tín hiệu radar, rồi ghép
     * câu trả lời từ dữ liệu đã có. Người dùng đọc khung mang tên "Trò chuyện" và TƯỞNG đang hỏi AI.
     * Nay tab đó gọi endpoint chat thật của Agent Studio (NDJSON, chữ chảy từng mảnh, công cụ web dùng
     * chung với radar/brief). Hai khẳng định cũ đã bị GỠ cùng đường ghép giả — giữ chúng lại là bắt sản
     * phẩm phải có lại chỗ nói dối người dùng.
     */
    public function test_the_chat_tab_is_a_real_streamed_chat_with_checkable_sources(): void
    {
        $empty = $this->empty();

        $this->assertStringContainsString('data-tab="chat"', $empty, 'Thiếu tab Trò chuyện.');
        $this->assertStringContainsString('data-tab="compose"', $empty, 'Thiếu tab Tạo ảnh.');

        // (1) Hỏi bằng LỜI tới endpoint chat thật, qua action dùng chung với bước «Hỏi đáp» của Agent Studio.
        $this->assertStringContainsString('store.agentChatAsk(', $empty,
            'Tab Trò chuyện phải hỏi trợ lý thật (action dùng chung), không được tự ghép câu trả lời ở trình duyệt.'
        );
        $this->assertStringContainsString('store.agentChatStreaming', $empty, 'Phải đọc trạng thái đang trả lời từ kho dữ liệu.');
        $this->assertStringContainsString('store.agentChatStop()', $empty, 'Đang trả lời thì phải có đường DỪNG.');
        $this->assertStringContainsString('data-chat-suggestion', $empty, 'Thiếu câu hỏi gợi ý.');

        // (2) Nguồn là LINK THẬT — không bấm được thì người dùng không kiểm chứng được gì.
        $this->assertStringContainsString('Nguồn để bạn tự kiểm', $empty, 'Thiếu khối trích dẫn.');
        $this->assertStringContainsString('rel="noopener"', $empty, 'Link nguồn phải mở tab mới an toàn.');

        // (3) Cầu nối "tìm hiểu → làm" vẫn phải còn: đưa CÂU TRẢ LỜI vào ô mô tả tạo ảnh.
        $this->assertStringContainsString('data-use-answer', $empty, 'Thiếu nút đưa câu trả lời vào ô mô tả ảnh.');
        $this->assertStringContainsString('useAnswer(', $empty, 'Nút cầu nối phải có hàm thật đứng sau.');

        // (4) Đường ghép câu trả lời ở TRÌNH DUYỆT đã bị gỡ HẲN — còn sót là còn hai câu trả lời mâu thuẫn.
        foreach (['matchScore', 'loadTrendRadar', '/api/design-search', 'Không thấy mục nào khớp đúng'] as $gone) {
            $this->assertStringNotContainsString($gone, $empty,
                'Tab Trò chuyện còn sót đường trả lời giả ở trình duyệt: '.$gone
            );
        }
    }

    /**
     * 6. [đợt 32 · LỖI THẬT] Nút icon phải là KHUNG ĐỊNH VỊ (position: relative).
     *
     * Lỗi gốc rễ: .icon-btn thiếu `relative`, còn huy hiệu đếm của nút Outputs là `absolute right-0
     * top-0` ⇒ nó neo về thẻ cha định vị gần nhất (thẻ <header relative>) thay vì neo vào nút, nên bay
     * lên góc phải header và bị nút tài khoản đè lên — đúng cái người dùng báo "nút tài khoản đè nút
     * Outputs" dù đo bề rộng không thấy tràn. Khoá ở đây để lớp lỗi này không thể quay lại.
     */
    public function test_icon_buttons_anchor_their_badges_inside_the_button(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        // Lấy ĐÚNG khối .icon-btn (không phải .icon-btn.is-active hay nơi khác).
        preg_match('/\.icon-btn\s*\{[^}]*\}/s', $css, $m);
        $this->assertNotEmpty($m, 'Thiếu quy tắc .icon-btn.');
        $this->assertStringContainsString('relative', $m[0],
            '.icon-btn phải là khung định vị — huy hiệu absolute phải neo vào nút, không được trôi về header.'
        );
    }
}
