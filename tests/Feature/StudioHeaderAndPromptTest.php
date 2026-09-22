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
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 2] Lối vào modal trợ lý lại đổi một lần nữa, và bài 5 bên dưới được
 * viết lại theo ĐÚNG sự thật mới: nút icon trong cụm công cụ ở thanh tiêu đề (data-header-action="chat")
 * và mục «Trợ lý» trong menu mobile đã GỠ, thay bằng NÚT NỔI (components/ChatFab.vue) nằm trong VÙNG
 * CANVAS. Bộ khẳng định KHÔNG bị nới lỏng — xem khối (d) trong bài 5.
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
     * [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 2 — LỐI VÀO MODAL: TỪ NÚT Ở THANH TIÊU ĐỀ → NÚT NỔI (FAB)]
     * Khẳng định (d) TRƯỚC ĐÂY bắt StudioApp phải có `data-header-action="chat"` nằm trong cụm công cụ
     * của thanh tiêu đề. Khẳng định đó nay SAI theo thiết kế, và được thay bằng bộ khẳng định mới (d.1)
     * …(d.6) — KHÔNG phải bỏ khoá, mà khoá vào sự thật mới. Vì sao chuyển (đo được, không phải thẩm mỹ):
     *   · cụm công cụ ở thanh tiêu đề ẩn HẲN dưới `lg` (`hidden … lg:flex`) ⇒ trên điện thoại nút
     *     «Trợ lý» VỐN KHÔNG TỒN TẠI, nên đợt trước phải bù bằng một mục thứ hai trong menu mobile —
     *     hai lối vào cho cùng một việc, mỗi lối chỉ đúng ở một bề rộng màn hình;
     *   · chat là việc dùng LIÊN TỤC khi đang làm trên canvas (canvas đã có ảnh vẫn phải hỏi được — đó
     *     chính là lý do chat rời khỏi màn hình trống), nên lối vào phải NỔI và có mặt ở mọi bề rộng.
     * Bất biến mới, khoá bằng máy: nút nổi TỒN TẠI và đúng chuẩn FAB (tròn · 48px/56px · lớp trạng thái
     * Material · aria-label · KHÔNG CSS riêng), nó nằm TRONG vùng canvas (không phải trong thanh tiêu
     * đề), nó gọi ĐÚNG hàm mở modal dùng chung (`openChat()` — một nguồn, không hai bản logic), nó ẩn
     * khi chính modal của nó đang mở, nút cũ ở thanh tiêu đề KHÔNG được quay lại, menu mobile KHÔNG
     * giữ mục thứ hai, và bảng lệnh VẪN giữ lệnh mở trợ lý (đường dành cho bàn phím).
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

        // ── (d) StudioApp: nút mở chat nay là NÚT NỔI (FAB) trong VÙNG CANVAS + modal được render ──
        //
        // [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 2] Xem khối chú thích dài ở docblock của bài này: nút icon
        // trong cụm công cụ ở thanh tiêu đề đã được CHUYỂN thành nút nổi (không phải "thêm bản thứ
        // hai"). SÁU khẳng định dưới đây thay cho hai khẳng định cũ về `data-header-action="chat"`,
        // và chúng KHÔNG yếu hơn: chúng khoá cả HÌNH DẠNG nút, VỊ TRÍ của nó trong cây DOM, ĐƯỜNG gọi
        // hàm mở modal, và cả hai lối vào CŨ phải biến mất.
        $app = $this->app();
        $fabPath = resource_path('js/studio/components/ChatFab.vue');
        $this->assertFileExists($fabPath, 'Thiếu components/ChatFab.vue — nút nổi mở trợ lý thiết kế.');
        $fab = (string) file_get_contents($fabPath);

        // (d.1) HÌNH DẠNG: đúng chuẩn FAB Material + nhãn cho trình đọc màn hình (§3.1 · §8 · §9).
        $this->assertStringContainsString('rounded-full', $fab, 'Nút nổi phải TRÒN (chuẩn Material).');
        $this->assertMatchesRegularExpression('/\bh-12 w-12\b/', $fab, 'Nút nổi phải 48px trên màn hẹp (chuẩn Material: 48–56).');
        $this->assertMatchesRegularExpression('/\blg:h-14 lg:w-14\b/', $fab, 'Nút nổi phải 56px từ lg (chuẩn FAB Material).');
        $this->assertStringContainsString('shadow-2xl', $fab, 'Nút nổi phải có tầng nổi (§2) — không tự viết box-shadow.');
        $this->assertStringContainsString('state-layer', $fab, 'Trạng thái trỏ/bấm phải dùng lớp trạng thái Material (§2), không hover:bg-* chồng nền.');
        $this->assertStringContainsString('aria-label="Trợ lý thiết kế"', $fab,
            'Nút CHỈ CÓ ICON thì bắt buộc phải có aria-label (§8) — và phải là TÊN VIỆC, không phải "chat".'
        );
        $this->assertMatchesRegularExpression('/title="[^"]*nguồn[^"]*"/u', $fab,
            'Thiếu title nói rõ mở chat có DẪN NGUỒN để người dùng tự kiểm — đó là lời hứa của tính năng, phải nói trước khi bấm.'
        );
        $this->assertStringContainsString('name="bot"', $fab, 'Icon phải lấy từ icons.json qua StudioIcon (§9), không svg chép tay, không emoji.');
        $this->assertStringContainsString("import StudioIcon from './StudioIcon.vue'", $fab, 'Import icon dùng chung rồi mà không dùng thì vô nghĩa.');
        $this->assertStringNotContainsString('<style', $fab, 'Nút nổi KHÔNG được thêm khối CSS riêng — vị trí/hiệu ứng phải bằng class dùng chung (§2 · §10).');
        $this->assertStringNotContainsString(':style=', $fab, 'Không sơn nút nổi bằng style inline — vị trí lấy từ class tiện dụng (§2).');

        // (d.2) ẨN/HIỆN: luôn hiện trong /studio, CHỈ ẩn khi hộp thoại của CHÍNH nó đang mở (§3.1).
        //       (Không được ẩn theo bề rộng màn hình — đó chính là lỗi của nút cũ.)
        $this->assertStringContainsString('v-if="!store.chatOpen"', $fab,
            'Nút nổi phải ẩn khi modal trợ lý đang mở, và KHÔNG được ẩn theo bề rộng màn hình.'
        );

        // (d.2b) KHOẢNG CÁCH MÉP: trên màn hẹp phải NẰM TRÊN mọi dải nổi ở đáy vùng canvas.
        //        Ba dải đó (đo từ mã, mốc 0 = đáy vùng canvas): thanh ngữ cảnh mobile 8–52px · dải biến
        //        thể 56–116px · dải công cụ canvas (RegionTools) 64–112px — mà trên máy 320–375px
        //        RegionTools rộng ~268px nên gần hết bề ngang. Vì vậy 128px (bottom-32) là mốc thấp
        //        nhất còn trống; hạ xuống bottom-24/bottom-16 là nút chồng lên dải công cụ.
        $this->assertStringContainsString('bottom-32 right-3', $fab,
            'Nút nổi phải nằm TRÊN ba dải nổi ở đáy vùng canvas (bottom-32 · right-3 ở màn hẹp) — '
            .'hạ thấp hơn là đè lên dải công cụ canvas/thanh ngữ cảnh của mobile.'
        );
        $this->assertStringContainsString('lg:bottom-4 lg:right-4', $fab,
            'Từ lg, góc dưới–phải vùng canvas trống (hai dải ở đáy nằm GIỮA, RegionTools thành cột dọc bên '
            .'trái) nên nút phải về đúng 16px cho hợp chuẩn FAB Material.'
        );

        // (d.3) VỊ TRÍ: FAB phải nằm TRONG VÙNG CANVAS của StudioApp, không phải trong <header>.
        //       Đây là bất biến khó thấy bằng mắt nhất nên khoá bằng CẤU TRÚC: thanh tiêu đề không được
        //       chứa nó, và nó phải nằm giữa dòng đánh dấu vùng canvas và bảng Layers (inspector).
        $headerStart = strpos($app, 'class="navbar elev-bar');
        $canvasStart = strpos($app, '<!-- Center canvas -->');
        $this->assertNotFalse($headerStart, 'Không đọc được thanh tiêu đề để kiểm vị trí nút nổi.');
        $this->assertNotFalse($canvasStart, 'Không đọc được dấu "Center canvas" để kiểm vị trí nút nổi.');
        $header = substr($app, (int) $headerStart, (int) $canvasStart - (int) $headerStart);
        $this->assertStringNotContainsString('<ChatFab', $header,
            'Nút nổi lại được render TRONG thanh tiêu đề — nút nổi phải neo vào vùng canvas, nếu không nó '
            .'bị dock Outputs/bảng Layers che và lại ẩn mất trên màn hẹp.'
        );
        $this->assertMatchesRegularExpression(
            '/Vùng canvas \(trái, flex-1\)[\s\S]*<ChatFab[\s\S]*id="dock-inspector"/',
            $app,
            'Nút nổi phải nằm TRONG khối vùng canvas (tổ tiên định vị: <div class="relative flex-1 '
            .'overflow-hidden" :class="bgClass">), tức là phải xuất hiện SAU dòng đánh dấu vùng canvas và '
            .'TRƯỚC bảng Layers — không phải ở góc màn hình, không phải trong thanh trạng thái.'
        );

        // (d.3b) Nút phải là CON TRỰC TIẾP của khối vùng canvas — KHÔNG nằm trong <div ref="canvasZoom">
        //        (khối absolute inset-0 ngay trong vùng canvas). Đây là lỗi THẬT đã suýt xảy ra lúc đặt
        //        nút: khối đó mang @pointerdown="onCanvasBgDown" và cursor-grab/cursor-crosshair, nên nút
        //        nằm trong nó sẽ (a) hiện con trỏ "bàn tay" thay vì con trỏ bấm, và (b) MỖI cú bấm nút
        //        kéo theo xử lý nền canvas (bỏ chọn layer, bắt đầu quét chọn).
        //        Cách kiểm: từ thẻ MỞ canvasZoom tới ngay trước <ChatFab>, số thẻ <div> phải CÂN BẰNG với
        //        số </div> — nghĩa là canvasZoom (và mọi khối con của nó) đã đóng trước khi nút xuất
        //        hiện. Bỏ chú thích trước khi đếm, vì chú thích giải thích lựa chọn CÓ nhắc tên các khối.
        $code = (string) preg_replace('/<!--.*?-->/s', '', $app);
        $zoomStart = strpos($code, '<div ref="canvasZoom"');
        $fabStart = strpos($code, '<ChatFab');
        $this->assertNotFalse($zoomStart, 'Không đọc được khối canvasZoom để kiểm cha của nút nổi.');
        $this->assertNotFalse($fabStart, 'Không đọc được <ChatFab> để kiểm cha của nút nổi.');
        $inside = substr($code, (int) $zoomStart, (int) $fabStart - (int) $zoomStart);
        $this->assertSame(substr_count($inside, '<div'), substr_count($inside, '</div>'),
            'Nút nổi đang nằm BÊN TRONG canvasZoom (hoặc một khối con của nó): nút sẽ thừa hưởng '
            .'cursor-grab và mỗi cú bấm lại chạy onCanvasBgDown của nền canvas. Nút phải là con TRỰC TIẾP '
            .'của khối vùng canvas, đứng ngay sau thẻ đóng của canvasZoom.'
        );

        // (d.4) ĐƯỜNG MỞ MODAL: chỉ MỘT bản logic. Nút nổi phát 'open' → StudioApp gọi openChat()
        //       (hàm DUY NHẤT đóng các lớp phủ rồi bật store.chatOpen).
        $this->assertStringContainsString('@open="openChat"', $app,
            'Nút nổi phải gọi ĐÚNG hàm mở modal dùng chung — dựng logic mở thứ hai là hai chỗ để lệch nhau.'
        );
        $this->assertStringContainsString('function openChat()', $app, 'Thiếu hàm mở modal trợ lý.');
        $this->assertMatchesRegularExpression('/function openChat\(\)\s*\{[^}]*store\.chatOpen = true/s', $app,
            'openChat() phải là chỗ DUY NHẤT bật cờ mở modal (cùng chỗ đóng các lớp phủ đang mở).'
        );

        // (d.5) HAI LỐI VÀO CŨ PHẢI BIẾN MẤT (đây là "CHUYỂN", không phải "thêm bản thứ hai")…
        $this->assertStringNotContainsString('data-header-action="chat"', $app,
            'Nút «Trợ lý» cũ VẪN CÒN trong cụm công cụ ở thanh tiêu đề — nút nổi là để CHUYỂN, không phải thêm bản thứ hai.'
        );
        $drawerStart = strpos($app, 'aria-label="Menu Studio"');
        $this->assertNotFalse($drawerStart, 'Không đọc được menu mobile để kiểm lối vào trùng.');
        $drawerEnd = strpos($app, 'Mobile outputs overlay', (int) $drawerStart);
        $this->assertNotFalse($drawerEnd, 'Không đọc được cuối menu mobile để kiểm lối vào trùng.');
        $drawer = substr($app, (int) $drawerStart, (int) $drawerEnd - (int) $drawerStart);
        $this->assertStringNotContainsString('openChat()', $drawer,
            'Menu mobile vẫn còn lối vào thứ hai mở trợ lý — nút nổi đã hiện trên MỌI bề rộng nên mục đó '
            .'chỉ là bản sao (người dùng phải nhớ hai chỗ cho cùng một việc).'
        );

        // …(d.6) NHƯNG LỆNH TRONG BẢNG LỆNH THÌ GIỮ: bảng lệnh là đường dành cho BÀN PHÍM (§3.1).
        $this->assertStringContainsString('run: () => openChat()', $app,
            'Bảng lệnh phải GIỮ lệnh mở trợ lý — gỡ nút trên giao diện không có nghĩa là gỡ đường bàn phím.'
        );
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
