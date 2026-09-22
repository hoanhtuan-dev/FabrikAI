<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * THANH TIÊU ĐỀ STUDIO + Ô MÔ TẢ Ở MÀN HÌNH TRỐNG (2026-09-26 · đợt 27).
 *
 * Ba yêu cầu của chủ dự án, khoá lại thành bất biến:
 *   1. header theo chuẩn daisyUI (navbar + hai nhóm start/end),
 *   2. mọi việc thuộc TÀI KHOẢN nằm trong MỘT menu (nút Cài đặt riêng đã bỏ, Đăng xuất gộp vào),
 *   3. ô mô tả ở màn hình trống cuộn được · ẩn được · gọi lại được.
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26] Điểm 3 TRƯỚC ĐÂY còn kèm "và có TAB TRÒ CHUYỆN lấy dữ liệu thật". Tab đó
 * đã GỠ: chat nay là MODAL DÙNG CHUNG mở được từ bất kỳ đâu trong /studio (xem bài 5 bên dưới).
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
     * 5. [ĐỔI CHÍNH SÁCH 2026-09-26] Chat TÁCH khỏi màn hình canvas trống thành MODAL DÙNG CHUNG.
     *
     * [ĐỔI CHÍNH SÁCH 2026-09-26 — ĐỌC KHỐI NÀY TRƯỚC KHI "KHÔI PHỤC LẠI" NHỮNG KHẲNG ĐỊNH CŨ]
     * Bài này TRƯỚC ĐÂY khoá điều NGƯỢC LẠI: nó bắt màn hình canvas trống phải có `data-tab="chat"`,
     * phải gọi `store.agentChatAsk(`, phải có `rel="noopener"` và `data-use-answer` — tức là bắt chat
     * phải SỐNG TRONG màn hình đó. Cách đó có hai hệ quả THẬT, không phải chuyện thẩm mỹ:
     *   · CHỖ SAI — chat chỉ mở được khi canvas TRỐNG: vừa có ảnh trên canvas là khung chat biến mất,
     *     đúng lúc người dùng cần hỏi nhất;
     *   · TRỘN VIỆC — một màn hình chỉ để TẠO ẢNH lại mang thêm một thanh tab và một trạng thái
     *     đang-mở-tab phải nhớ trong localStorage; bấm nhầm tab là mất chỗ đang gõ.
     * Chủ dự án yêu cầu tách ra: canvas trống GIỮ ô mô tả tạo ảnh, chat thành MODAL thực thụ mở được TỪ
     * BẤT KỲ ĐÂU trong /studio. Bốn khẳng định dưới đây khoá ĐÚNG hành vi mới — chúng KHÔNG yếu hơn bộ
     * cũ: chat vẫn phải là chat THẬT theo luồng (action dùng chung, nguồn bấm được, có đường DỪNG), chỉ
     * khác là nó nằm ở một khung dùng chung thay vì nằm trong một tab.
     *
     * Đường trả lời GIẢ ở trình duyệt (tách từ khoá → chấm điểm khớp → ghép câu) vẫn bị cấm y như trước,
     * và nay cấm ở CẢ HAI file: còn sót một đường thứ hai là còn hai câu trả lời có thể mâu thuẫn.
     */
    public function test_the_chat_is_a_shared_modal_and_the_empty_canvas_only_composes_images(): void
    {
        $empty = $this->empty();
        $modal = (string) file_get_contents(resource_path('js/studio/components/ChatModal.vue'));

        // ── (a) MÀN HÌNH CANVAS TRỐNG: KHÔNG còn chat, nhưng PHẢI có đường mở modal ──
        foreach (['store.agentChatAsk', 'agentChatStop', 'Nguồn để bạn tự kiểm', 'data-tab', 'data-chat-suggestion'] as $gone) {
            $this->assertStringNotContainsString($gone, $empty,
                'Màn hình canvas trống còn sót chat ('.$gone.') — chat đã tách thành MODAL dùng chung, và hai '
                .'khung chat là hai lịch sử mà người dùng không biết tin bản nào.'
            );
        }
        $this->assertStringContainsString('store.chatOpen = true', $empty,
            'Canvas trống phải có nút mở MODAL trợ lý — nếu không thì người đang đứng ở màn này không có lối tới trợ lý.'
        );
        $this->assertStringContainsString('data-chat-open', $empty, 'Thiếu dấu nhận diện cho nút mở modal.');
        // Phần TẠO ẢNH phải còn nguyên — đây mới là việc của màn hình này.
        $this->assertStringContainsString('canvas-quick-prompt', $empty, 'Canvas trống phải giữ ô mô tả tạo ảnh.');
        $this->assertStringContainsString('store.generateImage()', $empty, 'Canvas trống phải vẫn tạo ảnh được.');

        // ── (b) MODAL TRỢ LÝ: có thật, dùng BaseModal dùng chung, và là chat THẬT theo luồng ──
        $this->assertFileExists(resource_path('js/studio/components/ChatModal.vue'),
            'Thiếu components/ChatModal.vue — modal trợ lý dùng chung cho cả /studio.');
        $this->assertStringContainsString("import BaseModal from './BaseModal.vue'", $modal,
            'Modal chat phải dùng BaseModal dùng chung (focus trap + Esc + lớp phủ), KHÔNG tự dựng lớp phủ.'
        );
        $this->assertStringContainsString('<BaseModal', $modal, 'Modal chat phải render bằng BaseModal.');
        $this->assertStringContainsString('title="Trợ lý thiết kế"', $modal, 'Thiếu tiêu đề modal.');
        $this->assertStringContainsString('height="min(80vh, 720px)"', $modal,
            'Chiều cao modal phải chốt bằng prop height của BaseModal (header cố định + danh sách tin tự cuộn).'
        );
        $this->assertStringContainsString('store.agentChatAsk(', $modal,
            'Modal chat phải hỏi trợ lý qua action dùng chung với bước «Hỏi đáp» của Agent Studio.'
        );
        $this->assertStringContainsString('agentChatStop', $modal, 'Đang trả lời thì phải có đường DỪNG.');
        $this->assertStringContainsString('store.agentChatReset()', $modal, 'Thiếu đường mở hội thoại mới.');
        $this->assertStringContainsString('agentChatNotes(', $modal,
            'Cảnh báo của lượt vừa rồi phải lấy từ HÀM DÙNG CHUNG, không chép câu chữ sang bản thứ hai.'
        );
        $this->assertStringContainsString('agentChatMetaLine(', $modal, 'Dòng số đo phải lấy từ HÀM DÙNG CHUNG.');
        $this->assertStringContainsString('isComposing', $modal,
            'Phải tôn trọng bộ gõ tiếng Việt: Enter để CHỐT DẤU không được biến thành Enter để gửi.'
        );

        // Nguồn là LINK THẬT — không bấm được thì người dùng không kiểm chứng được gì.
        $this->assertStringContainsString('rel="noopener"', $modal, 'Link nguồn phải mở tab mới an toàn.');
        $this->assertStringContainsString('target="_blank"', $modal, 'Link nguồn phải mở tab mới.');
        $this->assertStringContainsString('Nguồn để bạn tự kiểm', $modal, 'Thiếu khối trích dẫn.');

        // Cầu nối "tìm hiểu → làm": đưa CÂU TRẢ LỜI vào ô mô tả tạo ảnh (cùng trường canvas trống bind vào).
        $this->assertStringContainsString('data-use-answer', $modal, 'Thiếu nút đưa câu trả lời vào ô mô tả ảnh.');
        $this->assertStringContainsString('store.imagePromptEn', $modal,
            'Cầu nối phải ghi vào ĐÚNG trường ô mô tả của Studio (store.imagePromptEn).'
        );

        // ── (c) MỘT nguồn gợi ý: hằng số nằm ở kho dữ liệu chat, cả hai khung đọc chung ──
        $actions = (string) file_get_contents(resource_path('js/studio/store/actions/agentChat.js'));
        $this->assertStringContainsString('export const CHAT_SUGGESTIONS', $actions,
            'Câu hỏi gợi ý phải nằm ở MỘT chỗ (kho dữ liệu chat) — hai danh sách là hai chỗ để lệch nhau.'
        );
        $this->assertStringContainsString('CHAT_SUGGESTIONS', $modal, 'Modal chat phải đọc danh sách gợi ý dùng chung.');
        $this->assertStringNotContainsString('const CHAT_SUGGESTIONS = [',
            (string) file_get_contents(resource_path('js/studio/composables/useAgentStudio.js')),
            'useAgentStudio.js khai lại danh sách gợi ý — phải IMPORT từ store/actions/agentChat.js.'
        );

        // ── (d) StudioApp: nút mở chat nằm TRONG cụm công cụ header + modal được render ──
        $app = $this->app();
        $this->assertStringContainsString('data-header-action="chat"', $app,
            'Thiếu nút «Trợ lý» trên cụm công cụ header — modal phải mở được từ mọi màn của /studio.'
        );
        $this->assertMatchesRegularExpression('/data-header-action="outputs"[\s\S]{0,1200}data-header-action="chat"/',
            $app, 'Nút «Trợ lý» phải nằm TRONG cụm công cụ đã chốt (cạnh Nguồn ảnh · Thư viện · Bảng lệnh · Outputs).'
        );
        $this->assertStringContainsString('function openChat()', $app, 'Thiếu hàm mở modal trợ lý.');
        $this->assertStringContainsString('<ChatModal />', $app, 'StudioApp phải render modal trợ lý.');

        // ── (e) Đường trả lời GIẢ ở trình duyệt: cấm ở CẢ HAI file ──
        foreach (['matchScore', 'loadTrendRadar', '/api/design-search', 'Không thấy mục nào khớp đúng'] as $gone) {
            $this->assertStringNotContainsString($gone, $empty, 'Canvas trống còn sót đường trả lời giả: '.$gone);
            $this->assertStringNotContainsString($gone, $modal, 'Modal chat còn sót đường trả lời giả: '.$gone);
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
