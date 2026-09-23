<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * THANH TIÊU ĐỀ STUDIO + Ô MÔ TẢ Ở MÀN HÌNH TRỐNG (2026-09-26 · đợt 27).
 *
 * Ba yêu cầu của chủ dự án, khoá lại thành bất biến:
 *   1. header theo chuẩn daisyUI (navbar + hai nhóm start/end),
 *   2. mọi việc thuộc TÀI KHOẢN nằm trong MỘT menu (nút Cài đặt riêng đã bỏ, Đăng xuất gộp vào),
 *   3. màn hình canvas trống chỉ còn MỘT LỜI MỜI NGẮN + lối vào chat và bảng prompt đầy đủ.
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26] Điểm 3 TRƯỚC ĐÂY còn kèm "và có TAB TRÒ CHUYỆN lấy dữ liệu thật". Tab đó
 * đã GỠ: chat nay là MODAL DÙNG CHUNG mở được từ bất kỳ đâu trong /studio (xem bài 5 bên dưới).
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 4] Điểm 3 lại đổi một lần nữa, và lần này NGƯỢC chiều với hai đợt
 * trước: màn hình canvas trống BỎ HẲN ô mô tả tạo ảnh (textarea · nút «Tạo ảnh» · gợi ý điền nhanh ·
 * nút ẩn/gọi lại · khoá localStorage). Việc tạo ảnh CHUYỂN VÀO KHUNG CHAT (bài 8 bên dưới).
 * Bài 4 KHÔNG bị nới lỏng — nó khoá ĐÚNG sự thật mới, và khoá CHẶT HƠN: trước đây nó chỉ đòi màn hình
 * trống CÓ ô mô tả, nay nó đòi màn hình trống SẠCH HẲN mọi dấu vết của ô đó (kể cả mã chết: hằng số,
 * khoá localStorage, hàm chèn dòng) mà VẪN giữ đúng hai lối vào còn lại cùng bất biến z-0.
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 2] Lối vào modal trợ lý lại đổi một lần nữa, và bài 5 bên dưới được
 * viết lại theo ĐÚNG sự thật mới: nút icon trong cụm công cụ ở thanh tiêu đề (data-header-action="chat")
 * và mục «Trợ lý» trong menu mobile đã GỠ, thay bằng NÚT NỔI (components/ChatFab.vue) nằm trong VÙNG
 * CANVAS. Bộ khẳng định KHÔNG bị nới lỏng — xem khối (d) trong bài 5.
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 3 — BỐN YÊU CẦU MỚI CỦA CHỦ DỰ ÁN, KHOÁ Ở KHỐI (f) CỦA BÀI 5]
 *   1. KHỐI "NGUỒN ĐỂ BẠN TỰ KIỂM" BỊ GỠ HẲN khỏi CẢ HAI khung chat (không ẩn bằng CSS, không để lại
 *      nút mở lại) — dữ liệu citations vẫn nguyên trong kho dữ liệu, chỉ không hiển thị;
 *   2. mỗi tin nhắn (cả hai vai) có NÚT COPY;
 *   3. chữ của trợ lý hiện dưới dạng VĂN BẢN ĐÃ TRANG TRÍ, không phải markdown thô;
 *   4. ô nhập của chat có NÚT XUỐNG DÒNG (cho điện thoại, nơi Enter là GỬI).
 * Hai khẳng định CŨ của bài 5 đi ngược lại yêu cầu 1 và 3 — chúng bắt modal PHẢI có chuỗi "Nguồn để bạn
 * tự kiểm" và PHẢI có link nguồn trong chính file khung chat. Chúng KHÔNG bị bỏ: chúng được CHUYỂN sang
 * đúng chỗ mới (khối (f.3) — link thật giờ nằm ở components/ChatMessageText.vue), và bất biến "link mở
 * tab mới phải an toàn" vẫn được khoá y như trước.
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
            // [đợt 53] Thương hiệu ẩn dưới sm ("hidden ... sm:flex"): ở 320px nó chỉ còn là hình trang
            // trí chiếm 40px và đẩy nút «Bộ sưu tập» ra ngoài mép phải thanh tiêu đề.
            'class="order-2 hidden shrink-0 items-center gap-2 sm:flex"' => 'thương hiệu FabrikAI',
            // [đợt 35] Hai nút này nay nằm trong KHAY TRÁI (data-header-account) — thứ tự trong khay lo
            // bằng order-1/order-3 vì mã nguồn xếp credit trước tài khoản.
            'class="order-1 relative"' => 'nút + menu tài khoản',
            'class="order-3 relative"' => 'nút Gói & credit',
            // [đợt 53] Nút ĐỔI MẶT chen vào giữa thương hiệu và nút Bộ sưu tập: nó thuộc CẢ HAI mặt nên
            // đứng ở thanh tiêu đề, không nằm trong chrome của mặt nào.
            'class="order-4 icon-btn !h-8 !w-8 shrink-0"' => 'nút đổi mặt Lưới ⇄ Bảng ghép',
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

    /**
     * 4. [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 4] MÀN HÌNH CANVAS TRỐNG KHÔNG CÒN Ô MÔ TẢ TẠO ẢNH.
     *
     * [ĐỌC KHỐI NÀY TRƯỚC KHI "KHÔI PHỤC LẠI Ô MÔ TẢ CHO ĐỦ BỘ"]
     * Bài này TRƯỚC ĐÂY khoá điều NGƯỢC LẠI: nó bắt màn hình trống phải có ô mô tả CUỘN ĐƯỢC · ẨN ĐƯỢC ·
     * GỌI LẠI ĐƯỢC — `max-h-[38vh]` · `data-prompt-newline` · `insertNewline` ·
     * `data-prompt-collapse` · `data-prompt-recall` · khoá `fabrikai:studio:prompt-collapsed` ·
     * nút «Mở ô tạo ảnh» · `canvas-quick-prompt` · `store.generateImage()`.
     * Chủ dự án yêu cầu màn hình trống chỉ còn MỘT LỜI MỜI NGẮN + lối vào chat. Hai lý do THẬT, không
     * phải thẩm mỹ:
     *   · BA CHỖ VIẾT MỘT VIỆC — cùng trường dữ liệu imagePromptEn đã có ô nhập ở card «Tạo ảnh»
     *     (ConceptCard) và ở đây; thêm ô trong chat nữa là BA ô cho một việc, và ba chỗ phải sửa mỗi lần
     *     đổi quy ước (biến thể · tỉ lệ · phím tắt · cách chèn dòng);
     *   · CHỖ SAI — ô mô tả chỉ tồn tại KHI CANVAS TRỐNG (StudioApp render nó theo v-if), nên vừa có ảnh
     *     trên canvas là mất chỗ viết mô tả cho ảnh tiếp theo.
     * Bộ khẳng định dưới đây KHÔNG yếu hơn bộ cũ: nó đòi màn hình trống sạch HẲN dấu vết của ô mô tả
     * (kể cả mã chết — hằng số, khoá localStorage, hàm chèn dòng) mà VẪN giữ đúng hai lối vào còn lại
     * (chat · bảng prompt đầy đủ) và VẪN giữ bất biến z-0 (nằm dưới layer).
     */
    public function test_the_empty_canvas_no_longer_holds_a_prompt_composer(): void
    {
        $empty = $this->empty();

        // Bất biến CŨ, giữ nguyên: màn hình trống nằm DƯỚI layer (nếu không nó che hiệu ứng mờ của layer
        // đang tắt) và vẫn tự cuộn được trên màn hình thấp.
        $this->assertStringContainsString('absolute inset-0 z-0', $empty, 'Màn hình trống vẫn phải nằm dưới layer.');
        $this->assertStringContainsString('overflow-y-auto', $empty, 'Màn hình trống vẫn phải cuộn được trên màn hình thấp.');

        // [đợt 28] Không còn dòng tiêu đề mào đầu — màn hình trống chỉ còn đúng việc để làm.
        $this->assertStringNotContainsString('Tạo ảnh đầu tiên', $empty, 'Dòng tiêu đề phải bị xoá.');
        $this->assertStringNotContainsString('Mô tả trang phục, phong cách, bối cảnh và ánh sáng', $empty, 'Dòng phụ đề phải bị xoá.');

        // KHÔNG còn ô mô tả tạo ảnh — kể cả mã chết của nó. Mỗi chuỗi dưới đây là một mảnh của ô đó.
        foreach ([
            'canvas-quick-prompt' => 'ô mô tả tạo ảnh (id cũ)',
            '<textarea' => 'ô nhập mô tả',
            'data-prompt-newline' => 'nút xuống dòng của ô mô tả',
            'insertNewline' => 'hàm chèn dấu xuống dòng',
            'data-prompt-collapse' => 'nút ẩn ô mô tả',
            'data-prompt-recall' => 'nút gọi lại ô mô tả',
            'fabrikai:studio:prompt-collapsed' => 'khoá localStorage chỉ phục vụ ô mô tả',
            'max-h-[38vh]' => 'trần chiều cao của ô mô tả',
            'store.generateImage' => 'đường tạo ảnh ở màn hình trống',
            'EXAMPLES' => 'gợi ý điền nhanh của ô mô tả',
            'creditEstimate' => 'số credit của ô mô tả',
        ] as $gone => $what) {
            $this->assertStringNotContainsString($gone, $empty,
                'Màn hình canvas trống còn sót '.$what.' ('.$gone.') — việc tạo ảnh đã CHUYỂN VÀO KHUNG CHAT '
                .'(components/ChatModal.vue, bài 8 bên dưới), và hai chỗ viết một mô tả là hai chỗ để lệch nhau.'
            );
        }

        // KHÔNG mất tính năng: hai lối vào còn lại phải CÓ THẬT.
        $this->assertStringContainsString('data-chat-open', $empty,
            'Màn hình trống phải giữ lối vào khung chat — nếu không, người đang đứng ở canvas trống không có '
            .'cách nào tạo ảnh (ô mô tả ở đây đã gỡ).'
        );
        $this->assertStringContainsString('store.chatOpen = true', $empty,
            'Nút mở chat phải mở ĐÚNG cờ của modal dùng chung.'
        );
        $this->assertStringContainsString('store.promptOpen = true', $empty,
            'Thiếu đường DỰ PHÒNG cho người quen chỉnh kỹ: bảng Prompt Tạo Ảnh đầy đủ (prefix · negative · '
            .'phom dáng · mẫu việc) vẫn phải mở được từ màn hình trống.'
        );
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
        // [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 4] HAI khẳng định ở đây TRƯỚC ĐÂY bắt canvas trống PHẢI có ô mô tả
        // (`canvas-quick-prompt`) và PHẢI gọi `store.generateImage()`. Chúng nay SAI theo thiết kế:
        // việc tạo ảnh chuyển vào khung chat (xem bài 4 và bài 8). Không bỏ khoá — ĐỔI CHIỀU khoá, và khoá
        // cả hai file cùng lúc: canvas trống KHÔNG được có đường tạo ảnh, còn khung chat thì BẮT BUỘC có
        // ĐÚNG MỘT đường (bài 8 khối (b)). Cộng lại thì vẫn đúng một đường tạo ảnh trong cả /studio.
        $this->assertStringNotContainsString('canvas-quick-prompt', $empty,
            'Canvas trống vẫn còn ô mô tả tạo ảnh — việc đó đã chuyển vào khung chat (components/ChatModal.vue).'
        );
        $this->assertStringNotContainsString('store.generateImage', $empty,
            'Canvas trống vẫn còn đường tạo ảnh — đường DUY NHẤT nay nằm trong khung chat (bài 8 khối (b)).'
        );

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

        // [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 3] Hai khẳng định về khối nguồn TRONG CHÍNH file khung chat đã
        // được CHUYỂN sang khối (f.3) bên dưới — nơi link thật đang sống (components/ChatMessageText.vue)
        // — chứ KHÔNG bị xoá. Lý do đầy đủ ở docblock của bài này.

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
        // [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 3] Lời hứa CŨ trong title ("câu trả lời kèm nguồn bấm được để
        // bạn tự kiểm") nay SAI theo thiết kế: khối nguồn đã bị gỡ hẳn khỏi giao diện (xem khối (f.1)).
        // Bất biến KHÔNG bị nới lỏng — nó vẫn đòi title nói TRƯỚC kết quả người dùng sẽ nhận được, chỉ
        // đổi sang ĐÚNG sự thật mới: mở ra việc gì (hỏi đáp) và câu trả lời dựa trên cái gì (tự tra).
        $this->assertMatchesRegularExpression('/title="[^"]*hỏi đáp[^"]*"/u', $fab,
            'Thiếu title nói rõ nút mở ra VIỆC GÌ — lời hứa của tính năng phải nói trước khi bấm.'
        );
        $this->assertMatchesRegularExpression('/title="[^"]*tự tra[^"]*"/u', $fab,
            'Thiếu title nói TRƯỚC nguồn gốc câu trả lời (trợ lý tự tra internet khi cần) để người dùng biết mức độ tin.'
        );
        $this->assertStringNotContainsString('nguồn bấm được', $fab,
            'Title còn hứa "nguồn bấm được" — khối nguồn đã gỡ hẳn khỏi giao diện (2026-09-26), giữ lời hứa đó là nói sai với người dùng.'
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

        // ── (f) BỐN YÊU CẦU CỦA CHỦ DỰ ÁN [2026-09-26] ────────────────────────────────────────────
        //
        // [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 3 — ĐỌC KHỐI NÀY TRƯỚC KHI "KHÔI PHỤC LẠI" HAI KHẲNG ĐỊNH CŨ]
        // Hai khẳng định cũ của bài này (và của CẢ HAI khung chat) đi NGƯỢC lại yêu cầu mới: chúng bắt
        // khung chat PHẢI có chuỗi "Nguồn để bạn tự kiểm" và PHẢI có link nguồn trong chính file khung.
        // Chủ dự án yêu cầu ẩn VĨNH VIỄN khối đó khỏi người dùng (nó làm rối khung chat) ⇒ khối phải bị
        // XOÁ HẲN khỏi DOM, KHÔNG phải ẩn bằng CSS và KHÔNG để lại nút mở lại. Vì sao phải xoá hẳn chứ
        // không ẩn: khối còn trong DOM thì trình đọc màn hình vẫn đọc, Ctrl+F vẫn tìm thấy, và nó tự
        // quay lại nguyên trạng khi ai đó gỡ một class — đó không phải "ẩn vĩnh viễn".
        // DỮ LIỆU KHÔNG BỊ XOÁ: citations vẫn nằm nguyên trong kho dữ liệu dùng chung
        // (store/actions/agentChat.js) — chỉ không hiển thị. Muốn trả lại thì phải là hành động NGƯỜI
        // DÙNG CHỦ ĐỘNG (bấm mới hiện), KHÔNG tự hiện như trước.
        // Bất biến "link mở tab mới phải an toàn" KHÔNG mất: nó CHUYỂN sang components/ChatMessageText.vue
        // (khối f.3) — nơi link thật giờ được render. Không có khẳng định nào bị nới lỏng.
        $chatStep = (string) file_get_contents(resource_path('js/studio/components/agents/AgentChatStep.vue'));
        $frames = [
            'Modal trợ lý (ChatModal.vue)' => $modal,
            'Bước «Hỏi đáp» (AgentChatStep.vue)' => $chatStep,
        ];

        // (f.1) KHÔNG khung nào còn khối nguồn — kể cả tiêu đề của nó và câu của thẻ gấp.
        foreach ($frames as $name => $frame) {
            // Bỏ CHÚ THÍCH trước khi soi: cả hai file đều có khối "ĐỔI CHÍNH SÁCH 2026-09-26" KỂ LẠI
            // việc đã gỡ (đúng quy ước của repo), mà chú thích thì không bao giờ nằm trong DOM.
            $code = $this->withoutComments($frame);
            foreach (['Nguồn để bạn tự kiểm', 'thu gọn nguồn này', 'data-chat-sources'] as $gone) {
                $this->assertStringNotContainsString($gone, $code,
                    $name.' còn sót khối nguồn ('.$gone.') — chủ dự án yêu cầu GỠ HẲN khỏi giao diện, và '
                    .'một khối "ẩn bằng CSS" thì vẫn đọc được bằng trình đọc màn hình, vẫn tìm thấy bằng Ctrl+F.'
                );
            }
        }

        // (f.2) NÚT COPY cho TỪNG tin nhắn, ở CẢ HAI khung.
        $copier = (string) file_get_contents(resource_path('js/studio/chatCopy.js'));
        foreach ($frames as $name => $frame) {
            $this->assertStringContainsString('data-chat-copy', $frame,
                $name.' thiếu nút Copy — chủ dự án yêu cầu copy được TỪNG tin nhắn, cả câu hỏi lẫn câu trả lời.'
            );
            $this->assertStringContainsString('copyPlainText(', $frame,
                $name.' phải copy qua ĐƯỜNG DÙNG CHUNG (chatCopy.js), không tự viết một bản clipboard riêng.'
            );
            // Nhãn nói rõ COPY CÁI GÌ: khung có hai loại tin nhắn nên một chữ "Copy" trống là nhập nhằng.
            $this->assertStringContainsString('Copy câu hỏi này', $frame, $name.' thiếu nhãn copy câu hỏi.');
            $this->assertStringContainsString('Copy câu trả lời này', $frame, $name.' thiếu nhãn copy câu trả lời.');
            $this->assertStringContainsString('store.toast(', $frame,
                $name.' phải XÁC NHẬN bằng toast — bấm copy mà không có phản hồi thì người dùng không biết đã copy chưa.'
            );
            // Tin của trợ lý copy bản CHỮ SẠCH, không phải nguyên văn còn dấu định dạng.
            $this->assertStringContainsString('assistantPlainText(', $frame,
                $name.' phải copy bản chữ sạch của câu trả lời (không copy dấu sao/backtick của máy).'
            );
        }
        // Đường copy dùng navigator.clipboard VÀ có đường dự phòng: navigator.clipboard CHỈ có khi trang
        // chạy HTTPS/localhost, nên máy khách mở bằng http://<ip> sẽ không có nó — thiếu đường dự phòng
        // là nút bấm không làm gì mà cũng không nói gì. Khẳng định này nằm ở MODULE DÙNG CHUNG vì repo có
        // luật MỘT CHỖ CHO MỘT VIỆC (xem khối (c): CHAT_SUGGESTIONS), và hai bản copy trong hai khung là
        // hai chỗ để lệch nhau (một bên có dự phòng, bên kia không).
        $this->assertStringContainsString('navigator.clipboard', $copier,
            'Đường copy phải dùng navigator.clipboard.writeText.'
        );
        $this->assertStringContainsString('document.execCommand(', $copier,
            'Đường copy thiếu bản DỰ PHÒNG cho trình duyệt chặn clipboard (không HTTPS / không phải thao tác trực tiếp).'
        );
        $this->assertStringContainsString('Đã copy câu trả lời vào bộ nhớ tạm.', $copier.$modal.$chatStep,
            'Thiếu câu xác nhận đã copy câu trả lời.'
        );
        $this->assertStringContainsString('Trình duyệt chặn việc copy', $copier.$modal.$chatStep,
            'Lỗi copy phải thành CÂU người dùng đọc được — không được ném ra console (trình duyệt chặn clipboard).'
        );

        // (f.3) CHỮ CỦA TRỢ LÝ: văn bản ĐÃ TRANG TRÍ qua module + component DÙNG CHUNG, không markdown thô.
        $renderer = (string) file_get_contents(resource_path('js/studio/components/ChatMessageText.vue'));
        $formatter = (string) file_get_contents(resource_path('js/studio/chatFormat.js'));
        $this->assertStringContainsString('export function formatAssistantText', $formatter,
            'chatFormat.js thiếu formatAssistantText — bộ nhận dạng phải là MODULE THUẦN để tự kiểm bằng Node.'
        );
        $this->assertStringContainsString('export function assistantPlainText', $formatter,
            'chatFormat.js thiếu assistantPlainText — bản CHỮ SẠCH dùng cho nút Copy.'
        );
        // Bộ nhận dạng phải có ĐỦ bốn thứ chủ dự án yêu cầu: đậm · gạch đầu dòng · link · URL trần.
        foreach (['\\*\\*', 'li', 'href', 'https?'] as $needle) {
            $this->assertStringContainsString($needle, $formatter,
                'chatFormat.js thiếu phần nhận dạng: '.$needle
            );
        }
        foreach ($frames as $name => $frame) {
            $this->assertStringContainsString("import ChatMessageText from", $frame,
                $name.' phải dùng component hiển thị DÙNG CHUNG — hai bản render là hai chỗ để lệch nhau.'
            );
            $this->assertStringContainsString('<ChatMessageText', $frame, $name.' chưa render chữ của trợ lý qua ChatMessageText.');
            $this->assertStringContainsString('chatFormat.js', $frame, $name.' phải đọc bộ định dạng dùng chung.');
            // KHÔNG sink HTML thô ở bất kỳ đâu: chữ trong khung chat là VĂN BẢN DO MÁY TRẢ LỜI.
            $this->assertStringNotContainsString('v-html', $frame.$renderer,
                $name.' hoặc ChatMessageText.vue dùng sink HTML thô — xem tests/Feature/StudioXssSinksTest.php.'
            );
        }
        $this->assertStringNotContainsString('v-html', $renderer, 'ChatMessageText.vue không được có sink HTML thô.');
        $this->assertStringNotContainsString('innerHTML', $renderer, 'ChatMessageText.vue không được dựng chuỗi HTML.');
        // Đậm · nghiêng · mã là THẺ THẬT; link mở tab mới an toàn (bất biến CŨ, nay ở đúng chỗ mới).
        foreach (['<strong', '<em', '<code'] as $tag) {
            $this->assertStringContainsString($tag, $renderer, 'ChatMessageText.vue thiếu thẻ thật: '.$tag);
        }
        $this->assertStringContainsString('rel="noopener"', $renderer, 'Link trong câu trả lời phải mở tab mới an toàn.');
        $this->assertStringContainsString('target="_blank"', $renderer, 'Link trong câu trả lời phải mở tab mới.');

        // (f.4) NÚT XUỐNG DÒNG trong ô nhập của CẢ HAI khung (trên điện thoại Enter là GỬI).
        foreach ($frames as $name => $frame) {
            $this->assertStringContainsString('data-chat-newline', $frame, $name.' thiếu nút xuống dòng.');
            $this->assertStringContainsString('insertNewline', $frame, $name.' thiếu hàm chèn dấu xuống dòng.');
            $this->assertStringContainsString('selectionStart', $frame,
                $name.' phải chèn tại ĐÚNG VỊ TRÍ CON TRỎ, không phải nối vào cuối ô.'
            );
            $this->assertStringContainsString('el.selectionStart = el.selectionEnd = start + 1', $frame,
                $name.' phải đặt lại con trỏ NGAY SAU ký tự vừa chèn — nhảy về cuối là làm mất chỗ đang gõ.'
            );
            // Cùng icon với nút xuống dòng của ô mô tả tạo ảnh: hai ô nhập trong CÙNG một sản phẩm không
            // được hành xử (và trông) khác nhau.
            $this->assertStringContainsString('cornerDownLeft', $frame,
                $name.' phải dùng CÙNG icon với nút data-prompt-newline của ô mô tả tạo ảnh.'
            );
        }
        // [ĐỔI CHÍNH SÁCH 2026-09-26 · LẦN 4] Khẳng định cũ ở đây bắt canvas trống phải còn
        // `data-prompt-newline` — "bản gốc" mà nút của khung chat chép theo. Bản gốc đó đã GỠ cùng ô
        // mô tả tạo ảnh, nên khẳng định ấy nay SAI. Bất biến KHÔNG bị nới lỏng: quy ước "cùng icon · cùng
        // lối xử lý con trỏ" vẫn được khoá ở vòng lặp NGAY TRÊN (cả hai khung chat phải dùng
        // `cornerDownLeft`), và dưới đây khoá thêm chiều ngược lại — canvas trống KHÔNG được mọc lại nút đó.
        $this->assertStringNotContainsString('data-prompt-newline', $empty,
            'Canvas trống lại có nút xuống dòng của ô mô tả — ô đó đã gỡ 2026-09-26, nút xuống dòng nay chỉ '
            .'thuộc về hai khung chat (data-chat-newline).'
        );

        // (f.5) Biểu tượng copy lấy từ nguồn icon duy nhất (icons.json) — không svg chép tay, không emoji.
        $icons = (string) file_get_contents(resource_path('js/studio/icons.json'));
        $this->assertStringContainsString('"copy"', $icons, 'icons.json thiếu biểu tượng copy.');
        foreach ($frames as $name => $frame) {
            $this->assertStringContainsString('name="copy"', $frame, $name.' phải dùng biểu tượng copy dùng chung.');
        }
    }

    /**
     * 8. [2026-09-26] CHAT NHẬN THÊM BA VIỆC: TẠO ẢNH · ĐIỀU PHỐI · KHAI KHOÁ TÌM KIẾM WEB.
     *
     * Yêu cầu của chủ dự án, khoá thành bất biến — MỖI khối dưới đây khoá một điều KHÔNG ĐƯỢC phép lệch:
     *   (a) canvas trống KHÔNG còn ô mô tả/nút tạo ảnh, VẪN có nút mở chat, và KHÔNG chứa chuỗi
     *       `'/agent-studio'` (bài 4 khoá phần "không còn ô mô tả"; khối này khoá nốt hai vế còn lại);
     *   (b) khung chat CÓ ô tạo ảnh, gọi ĐÚNG MỘT đường generateImage() và ĐÚNG trường imagePromptEn;
     *   (c) khung chat CÓ thẻ trỏ tới hoạt động 'concept' — và KHÔNG chép luồng phân tích ảnh;
     *   (d) mỗi thẻ chức năng trỏ tới một CỜ/HÀM CÓ THẬT (không nút chết);
     *   (e) mục khai khoá có ĐÚNG cả hai câu lệnh studio:web-search-setup, và KHÔNG có khoá giả nào.
     *
     * Vì sao phải khoá bằng máy: cả ba việc này đều là loại "dễ mục" — một nút điều hướng trỏ vào cờ đã
     * đổi tên thì KHÔNG đỏ ở đâu cả, nó chỉ im lặng không làm gì khi người dùng bấm; và một câu lệnh CLI
     * chép sai một chữ thì lệnh chạy ra lỗi trên máy chủ của khách.
     */
    public function test_the_chat_composes_images_navigates_and_documents_search_keys(): void
    {
        $empty = $this->empty();
        $modal = (string) file_get_contents(resource_path('js/studio/components/ChatModal.vue'));
        $app = $this->app();
        // Bỏ CHÚ THÍCH trước khi soi: quy ước của repo là chú thích KỂ LẠI việc đã gỡ và nhắc tên hàm cũ,
        // nên soi cả chú thích là tự tạo cảnh báo giả (cùng lý do đã ghi ở withoutComments()).
        $code = $this->withoutComments($modal);

        // ── (a) CANVAS TRỐNG: không còn đường tạo ảnh, vẫn có lối vào chat, KHÔNG mở Agent Studio ──
        $this->assertStringNotContainsString('store.generateImage', $empty,
            'Canvas trống còn đường tạo ảnh — đường DUY NHẤT nay nằm trong khung chat.'
        );
        $this->assertStringNotContainsString('<textarea', $empty, 'Canvas trống còn ô nhập mô tả.');
        $this->assertStringContainsString('data-chat-open', $empty, 'Canvas trống mất nút mở chat.');
        $this->assertStringNotContainsString("'/agent-studio'", $empty,
            'Canvas trống lại mở Agent Studio — lối vào đó nằm ở thanh công cụ của Studio '
            .'(tests/Feature/AgentStudioPageTest.php khoá cùng điều này).'
        );

        // ── (b) Ô TẠO ẢNH TRONG CHAT: ĐÚNG MỘT đường, ĐÚNG trường dữ liệu ──
        $this->assertStringContainsString('data-chat-image', $code, 'Khung chat thiếu ô tạo ảnh.');
        $this->assertStringContainsString('data-chat-image-send', $code, 'Ô tạo ảnh thiếu nút gửi.');
        $this->assertSame(1, substr_count($code, 'store.generateImage()'),
            'Khung chat phải gọi ĐÚNG MỘT đường tạo ảnh (store.generateImage()). Hai lời gọi — hoặc một '
            .'đường tự dựng payload riêng — là hai nơi để lệch nhau về tỉ lệ/độ phân giải/phom dáng/prefix.'
        );
        $this->assertStringNotContainsString("'/api/generate'", $code,
            'Khung chat tự gọi /api/generate — payload tạo ảnh chỉ được dựng ở MỘT chỗ (kho dữ liệu generation).'
        );
        $this->assertStringContainsString('store.imagePromptEn', $code,
            'Ô tạo ảnh phải ghi vào ĐÚNG trường mô tả của Studio (store.imagePromptEn) — đó là trường mà '
            .'bảng Prompt Tạo Ảnh đầy đủ và card «Tạo ảnh» cùng đọc.'
        );
        // TIẾN TRÌNH PHẢI LÀ SỐ CỦA KHO DỮ LIỆU, không phải hoạt ảnh tự chế ở giao diện.
        foreach (['store.generating', 'store.generateStage', 'store.generateProgress', 'store.lastBatch'] as $truth) {
            $this->assertStringContainsString($truth, $code,
                'Thẻ kết quả tạo ảnh phải đọc trạng thái THẬT ('.$truth.') — giao diện không được tự bịa tiến trình.'
            );
        }
        $this->assertStringContainsString('data-chat-back-canvas', $code,
            'Thẻ kết quả thiếu nút «Về canvas» — người dùng phải có đường đóng chat để nhìn thấy ảnh.'
        );
        $this->assertStringContainsString('LoadingSpinner', $modal,
            'Chỉ báo đang chờ của phần tạo ảnh phải dùng <LoadingSpinner> dùng chung (§3), không tự vẽ.'
        );

        // ── (c) «GỢI Ý TỪ ẢNH»: TRỎ tới card đang có, KHÔNG chép luồng phân tích ──
        $this->assertStringContainsString("store.requestActivity('concept')", $code,
            "Thẻ «Gợi ý từ ảnh» phải đi qua ĐÚNG kênh điều hướng có sẵn (store.requestActivity('concept')) — "
            .'activeActivity là biến CỤC BỘ của StudioApp.vue nên không component nào được tự đổi nó.'
        );
        $this->assertStringContainsString('store.activityRequest', $app,
            'StudioApp phải TIÊU THỤ kênh yêu cầu đó (một nguồn sự thật, không hai bản sao logic).'
        );
        // Soi bản ĐÃ BỎ CHÚ THÍCH ($code): chú thích của tệp có nhắc tên đường dẫn này để GIẢI THÍCH vì sao
        // không chép nó sang — soi cả chú thích là tự tạo cảnh báo giả (xem withoutComments()).
        $this->assertStringNotContainsString('/api/suggest/stream', $code,
            'Khung chat chép lại luồng phân tích ảnh — luồng đó thuộc card «Gợi ý từ ảnh» '
            .'(components/SuggestCard.vue), chép sang đây là bản sao thứ hai.'
        );
        $this->assertStringNotContainsString('suggestStyleStream', $code,
            'Khung chat gọi thẳng luồng gợi ý phong cách — phải TRỎ tới card, không chạy lại luồng.'
        );

        // ── (d) THẺ CHỨC NĂNG: mỗi thẻ trỏ tới một cờ/hàm CÓ THẬT ──
        $this->assertStringContainsString('data-chat-actions', $code, 'Thiếu dải thẻ chức năng.');
        $this->assertStringContainsString('data-chat-card', $code, 'Thẻ chức năng thiếu dấu nhận diện cho test.');

        // Từng hàm của thẻ phải được KHAI trong chính file (nút gọi vào hư không là nút chết).
        foreach (['openImageComposer', 'goConcept', 'goAgentStudio', 'goLibrary', 'goProjects', 'goPromptPanel'] as $fn) {
            $this->assertStringContainsString('function '.$fn.'(', $modal, 'Thẻ chức năng gọi hàm không tồn tại: '.$fn);
        }
        // Và từng ĐÍCH điều hướng phải có thật trong mã — kiểm ở ĐÚNG nơi nó sống.
        $library = (string) file_get_contents(resource_path('js/studio/store/actions/library.js'));
        $projects = (string) file_get_contents(resource_path('js/studio/store/actions/projects.js'));
        $state = (string) file_get_contents(resource_path('js/studio/store/state.js'));
        $this->assertStringContainsString('openLibrary() {', $library,
            'Thẻ «Thư viện» gọi store.openLibrary() nhưng kho dữ liệu không có hàm đó.'
        );
        $this->assertStringContainsString('requestWorkspace() {', $projects,
            'Thẻ «Bộ sưu tập» gọi store.requestWorkspace() nhưng kho dữ liệu không có hàm đó.'
        );
        $this->assertStringContainsString('promptOpen: false,', $state,
            'Thẻ «Bảng prompt» ghi vào store.promptOpen nhưng cờ đó không có trong state.'
        );
        $this->assertStringContainsString("const AGENT_STUDIO_URL = '/agent-studio'", $modal,
            'Thẻ «Tín hiệu & Định hướng» phải trỏ tới ĐÚNG đường dẫn trang Agent Studio, khai ở MỘT chỗ.'
        );
        // MỘT bản logic mở Thư viện: StudioApp gọi lại hàm của kho dữ liệu, KHÔNG chép hai bước vào đây.
        $this->assertStringContainsString('function goLibrary() { store.openLibrary(); }', $app,
            'StudioApp chép lại logic mở Thư viện — phải gọi store.openLibrary() để chỉ còn MỘT bản.'
        );
        // Điều hướng phải TẤT ĐỊNH: yêu cầu mở nhóm công cụ KHÔNG được "bấm lần hai thì đóng bảng".
        $this->assertStringContainsString('function revealActivity(id)', $app,
            'Thiếu đường mở nhóm công cụ tất định — requestActivity không được toggle đóng bảng đang mở.'
        );

        // ── (e) MỤC KHAI API KEY TÌM KIẾM: đúng HAI câu lệnh, KHÔNG có khoá giả ──
        $this->assertStringContainsString('data-chat-apikey', $code, 'Thiếu mục «Cách đăng ký API key tìm kiếm web».');
        $this->assertStringContainsString('apiKeyOpen = ref(false)', $modal,
            'Mục khai khoá phải MẶC ĐỊNH ĐÓNG — đây là việc một lần của chủ shop, không phải việc hằng ngày.'
        );
        $this->assertStringContainsString('v-if="apiKeyOpen"', $code, 'Nội dung mục khai khoá phải gấp lại được.');
        $this->assertStringContainsString('php artisan studio:web-search-setup --provider=tavily --key=tvly-', $code,
            'Thiếu câu lệnh Tavily — đường ĐANG CHẠY (chế độ không cần khoá).'
        );
        $this->assertStringContainsString('php artisan studio:web-search-setup --key=AIza', $code,
            'Thiếu câu lệnh Google Programmable Search (đường thay thế).'
        );
        $this->assertStringContainsString('--cx=', $code, 'Câu lệnh Google thiếu tham số --cx (Search engine ID).');
        $this->assertStringContainsString('app.tavily.com', $code, 'Thiếu chỗ lấy khoá Tavily.');
        $this->assertStringContainsString('programmablesearchengine.google.com', $code, 'Thiếu chỗ tạo engine Google.');
        $this->assertStringContainsString('console.cloud.google.com', $code, 'Thiếu chỗ bật Custom Search API.');
        // HAI sự thật phải nói rõ, không hứa gì thêm.
        $this->assertStringContainsString('ĐÃ MÃ HOÁ', $code, 'Phải nói rõ khoá được lưu ở dạng đã mã hoá.');
        $this->assertStringContainsString('KHÔNG cần khoá', $code, 'Phải nói rõ người dùng không cần khoá nếu dùng hạn mức chung.');
        $this->assertStringContainsString('data-chat-apikey-copy', $code, 'Câu lệnh phải copy được (chatCopy.js dùng chung).');
        // KHÔNG có khoá THẬT/GIẢ nào trong mã: chỉ được là dạng mẫu tvly-… · AIza… (kết thúc ngay bằng dấu …).
        $this->assertSame(0, preg_match('/(?:AIza|tvly-)[0-9A-Za-z_\-]{8,}/', $modal),
            'Trong mã khung chat có một chuỗi TRÔNG NHƯ KHOÁ THẬT — chỉ được để dạng mẫu tvly-… · AIza…, '
            .'khoá thật không bao giờ nằm trong mã nguồn.'
        );
    }

    /**
     * Bỏ CHÚ THÍCH khỏi mã trước khi soi chuỗi.
     *
     * Vì sao cần: quy ước của repo là chú thích KỂ LẠI việc đã gỡ và nói rõ VÌ SAO — nên chính những
     * khối "ĐỔI CHÍNH SÁCH" đó có nhắc tới chuỗi vừa bị cấm. Một rào chắn báo động vì chú thích sẽ bị
     * tắt đi, và lúc đó nó không chặn được gì nữa (cùng lý do đã dùng ở DesignSystemTest).
     */
    private function withoutComments(string $src): string
    {
        return (string) preg_replace(
            ['/<!--.*?-->/s', '#/\\*.*?\\*/#s', '/^[ \\t]*\\/\\/[^\\n]*$/m'],
            '',
            $src
        );
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

    /**
     * 7. [2026-09-26] BỘ ĐỊNH DẠNG CHỮ CỦA TRỢ LÝ được kiểm bằng Node.
     *
     * Vì sao bằng Node chứ không bằng PHP: bộ nhận dạng (đậm · nghiêng · mã · link · URL trần · gạch
     * đầu dòng · tiêu đề) là JavaScript, và nó là phần DỄ SAI NHẤT của việc "hiện câu trả lời cho đọc
     * được" — sai một chỗ thì người dùng đọc thấy ký tự của máy, hoặc tệ hơn: một chuỗi không phải địa
     * chỉ web bị biến thành chỗ bấm được. Repo không có JS test runner (chỉ vite build), nên dùng ĐÚNG
     * lối đã có: scripts/check-chat-format.mjs chạy bằng Node thuần, bài này gọi nó và đòi exit code 0
     * (cùng cách SharedSessionTest gọi scripts/check-session-store.mjs).
     */
    public function test_the_chat_format_module_passes_its_node_self_check(): void
    {
        $node = @shell_exec('command -v node 2>/dev/null');
        if (! is_string($node) || trim($node) === '') {
            $this->markTestSkipped('Không có node trong PATH — bỏ qua self-check JS.');
        }

        $script = base_path('scripts/check-chat-format.mjs');
        $this->assertFileExists($script, 'Thiếu scripts/check-chat-format.mjs — bộ định dạng chữ không được kiểm bằng gì.');

        $out = [];
        $rc = 1;
        @exec(escapeshellcmd(trim($node)).' '.escapeshellarg($script).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "check-chat-format.mjs thất bại:\n".implode("\n", $out));
    }
}
