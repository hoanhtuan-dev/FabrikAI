<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * MẶT CHÍNH CỦA KHUNG LÀM VIỆC — bước 5.2 của kế hoạch bỏ canvas (2026-09-26).
 *
 * Đổi vai: LƯỚI KẾT QUẢ thành mặt chính, canvas xuống hàng công cụ ("bảng ghép", mở khi cần).
 *
 * Bất biến khoá lại:
 *   (a) mặc định là 'grid' — việc thật của người dùng là XEM KẾT QUẢ và chọn bước tiếp;
 *   (b) hai mặt ẩn/hiện bằng v-show + inert, KHÔNG v-if — canvas giữ ref DOM (canvasZoom · cvImg)
 *       và lớp phủ mask; gỡ khỏi DOM là mất trạng thái đang vẽ dở;
 *   (c) đổi mặt là ACTION CỦA STORE — hai nơi gọi (thanh trạng thái + watch tự chuyển), một luật;
 *   (d) nút đổi mặt nằm ở THANH TRẠNG THÁI (mọi bề rộng), không ở rail công cụ (chỉ từ lg);
 *   (e) bật công cụ chỉ sống trên canvas thì TỰ mở bảng ghép — nếu không, người dùng bấm «Sửa ảnh»
 *       mà không có chỗ khoanh vùng, đúng kiểu "nút bấm được nhưng không làm gì".
 */
class MainViewTest extends TestCase
{
    private function app(): string
    {
        return (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
    }

    public function test_the_result_grid_is_the_default_main_view(): void
    {
        $state = (string) file_get_contents(resource_path('js/studio/store/state.js'));

        $this->assertStringContainsString("mainView: 'grid'", $state,
            'Mặc định phải là lưới kết quả — canvas nay là hàng công cụ, không phải mặt chính.');
    }

    public function test_both_faces_are_kept_in_the_dom_with_inert_not_v_if(): void
    {
        $app = $this->app();

        // v-show + inert cho CẢ HAI mặt. v-if sẽ gỡ canvas khỏi DOM ⇒ mất ref + mất mask đang vẽ.
        $this->assertStringContainsString("v-show=\"store.mainView === 'grid'\"", $app);
        $this->assertStringContainsString("v-show=\"store.mainView === 'canvas'\"", $app);
        $this->assertStringContainsString(':inert="store.mainView !== \'grid\' ? true : null"', $app);
        $this->assertStringContainsString(':inert="store.mainView !== \'canvas\' ? true : null"', $app);

        // Không được có nhánh v-if nào gỡ hẳn một trong hai mặt khỏi DOM.
        $this->assertStringNotContainsString("v-if=\"store.mainView === 'grid'\"", $app);
        $this->assertStringNotContainsString("v-if=\"store.mainView === 'canvas'\"", $app);
    }

    public function test_switching_the_main_view_is_a_store_action_not_a_local_copy(): void
    {
        $actions = (string) file_get_contents(resource_path('js/studio/store/actions/canvasView.js'));
        $app = $this->app();

        // MỘT luật, ở store — MỘT nơi gọi (nút đổi mặt ở thanh tiêu đề sau đợt 53).
        $this->assertStringContainsString('setMainView(v)', $actions, 'setMainView phải là action của store.');
        $this->assertStringContainsString('saveBarSettings', $actions, 'Chọn mặt nào thì lần sau mở lại phải đúng mặt đó.');
        $this->assertStringContainsString('store.setMainView(', $app, 'Nút đổi mặt phải gọi action của store, không tự đặt state.');
    }

    /**
     * ĐỔI MẶT PHẢI TỚI ĐƯỢC TỪ MỌI BỀ RỘNG — bất biến giữ nguyên, CHỖ ĐỨNG đã đổi.
     *
     * [đợt 53] Trước đây nút này ở thanh trạng thái. Nhưng thanh trạng thái là chrome CỦA CANVAS
     * (hoàn tác layer · thu/phóng · nền · bắt điểm · số lớp), nên giữ nút đổi mặt ở đó buộc mặt lưới
     * phải nuôi cả một thanh 40px chỉ để chứa hai nút — mà ở 320px thì 40px là thứ đắt nhất trên màn.
     * Nay nút nằm ở THANH TIÊU ĐỀ: chỗ đứng của mọi thứ thuộc CẢ HAI mặt, và hiện ở mọi bề rộng
     * (không gắn lg như rail công cụ) ⇒ điện thoại vẫn có lối đổi mặt.
     *
     * Đây là bất biến về KHẢ NĂNG TỚI ĐƯỢC, không phải về vị trí — nên bài test kiểm đúng điều đó.
     */
    public function test_the_switch_is_reachable_at_every_width(): void
    {
        $app = $this->app();

        // MỘT nút công tắc: giá trị mang ĐÍCH ĐẾN, nên cả 'grid' lẫn 'canvas' đều có mặt trong source
        // và trong DOM (mỗi lần một giá trị). Đây là điều tầng kiểm thử bằng trình duyệt bám vào.
        $this->assertStringContainsString('data-main-view-switch', $app);
        $this->assertStringContainsString("? 'canvas' : 'grid'", $app, 'Nút đổi mặt phải mang giá trị ĐÍCH ĐẾN.');

        // PHẢI tới được từ mọi bề rộng: nút không được nằm trong cụm chỉ-desktop ("hidden ... lg:flex").
        $i = strpos($app, 'data-main-view-switch');
        $tag = substr($app, max(0, $i - 600), 1000);
        $this->assertStringNotContainsString('hidden shrink-0 items-center gap-1 rounded-xl', $tag,
            'Nút đổi mặt KHÔNG được nằm trong cụm chỉ-desktop — điện thoại sẽ mất lối đổi mặt.');
        $this->assertStringContainsString('order-4', $tag, 'Nút đổi mặt phải là một phần tử độc lập của thanh tiêu đề.');

        // Và thanh trạng thái phải là chrome của RIÊNG mặt canvas.
        $status = (string) file_get_contents(resource_path('js/studio/components/CanvasStatusBar.vue'));
        $this->assertStringContainsString('v-show="store.mainView === \'canvas\'"', $status,
            'Thanh trạng thái là chrome của canvas ⇒ phải ẩn hoàn toàn ở mặt lưới.');
        $this->assertStringNotContainsString('data-main-view-switch', $status,
            'Nút đổi mặt đã chuyển lên thanh tiêu đề — không giữ bản sao thứ hai ở thanh trạng thái.');
    }

    public function test_canvas_only_tools_auto_open_the_canvas_face(): void
    {
        $app = $this->app();

        $this->assertStringContainsString('CANVAS_ONLY_TOOLS', $app,
            'Thiếu danh sách công cụ chỉ sống trên canvas ⇒ bấm «Sửa ảnh» từ lưới sẽ không có chỗ khoanh vùng.');
        $this->assertStringContainsString("store.setMainView('canvas')", $app,
            'Thiếu luật tự mở bảng ghép khi công cụ canvas được bật.');

        // Các công cụ bắt buộc phải có trong danh sách (mỗi cái là một đường vào canvas).
        foreach (['inpaintMaskMode', 'cropMode', 'selectTool', 'panMode'] as $flag) {
            $this->assertStringContainsString($flag, $app, 'Danh sách công cụ canvas thiếu '.$flag.'.');
        }
    }

    public function test_the_grid_reuses_the_existing_action_paths(): void
    {
        $grid = (string) file_get_contents(resource_path('js/studio/components/ResultGrid.vue'));

        // KHÔNG thêm endpoint, KHÔNG đổi luồng — mọi nút đi qua ĐÚNG hàm đã có.
        $this->assertStringContainsString('store.select(g)', $grid);
        $this->assertStringContainsString("'/api/generations/' + g.id + '/download'", $grid);
        $this->assertStringContainsString('store.openViewer(g)', $grid);
        $this->assertStringContainsString('store.deleteGen(g)', $grid, 'Xoá phải đi cùng đường với GalleryModal.');
    }

    /**
     * [2026-09-26 · đợt 52] LƯỚI CHỈ CÒN HAI NÚT NHANH — và mọi việc còn lại đã dồn về TRÌNH XEM ẢNH.
     *
     * Lý do (đo trên Chrome thật ở 320px): card rộng ~150px, bốn nút chữ nhỏ nằm cạnh nhau ⇒ ô chạm
     * 54x28px, không ô nào đủ to để chạm chắc. Hai nút còn lại là hai việc người dùng làm NGAY tại
     * lưới; phần còn lại chuyển vào trình xem ảnh (chạm ảnh là tới).
     *
     * Bất biến này giữ CẢ HAI đầu: lưới không được mọc lại nút thứ ba, VÀ trình xem phải thật sự
     * nhận được việc đã chuyển đi — nếu không thì đây chỉ là "xoá tính năng", không phải "dời chỗ".
     * Việc dời chỗ phải đi qua ĐÚNG kênh cũ (store.requestActivity), không dựng kênh thứ hai.
     */
    public function test_the_grid_has_exactly_two_quick_buttons_and_the_viewer_took_over(): void
    {
        $grid = (string) file_get_contents(resource_path('js/studio/components/ResultGrid.vue'));

        // Đếm nút trong ĐÚNG khối hai nút nhanh (từ mốc đánh dấu tới thẻ đóng của khối).
        // Không đếm cả file: mỗi card còn một <button> BỌC ẢNH (chạm ảnh = mở trình xem) và một
        // nút Xoá cho thẻ "đang chạy/lỗi" — hai thứ đó vẫn phải còn.
        $start = strpos($grid, 'HAI nút nhanh: Sửa');
        $this->assertNotFalse($start, 'Thiếu mốc đánh dấu khối hai nút nhanh trong TEMPLATE.');
        $bar = substr($grid, $start);
        $bar = substr($bar, 0, strpos($bar, '</div>'));
        $this->assertSame(2, substr_count($bar, '<button'), 'Khối nút nhanh của lưới phải có ĐÚNG hai nút.');

        // Hai nút đó là Sửa và Tải — không phải hai nút khác.
        $this->assertStringContainsString('data-edit-open', $bar, 'Nút nhanh thứ nhất phải là «Sửa».');
        $this->assertStringContainsString('download(g)', $bar, 'Nút nhanh thứ hai phải là «Tải».');
        // URL tải nằm ở script (hàm download) — khoá riêng, không lẫn với phần template ở trên.
        $this->assertStringContainsString("'/api/generations/' + g.id + '/download'", $grid,
            'Nút «Tải» phải đi đúng endpoint tải của máy chủ.');
        // Bóc chú thích trước khi kiểm: phần đầu tệp GHI LẠI lịch sử (nó từng đi qua
        // requestActivity) — kiểm mã sống, không kiểm tài liệu.
        $gridCode = preg_replace('#/\*.*?\*/#s', '', $grid);
        $gridCode = preg_replace('#(?<!:)//[^\n]*#', '', $gridCode);
        $this->assertStringNotContainsString('requestActivity', $gridCode,
            'Lưới không còn tự mở công cụ — việc đó đã thuộc về trình xem ảnh.');

        // ...và trình xem phải THẬT SỰ nhận việc: đi qua đúng kênh requestActivity.
        $viewer = (string) file_get_contents(resource_path('js/studio/components/GalleryModal.vue'));
        $this->assertStringContainsString('store.requestActivity(', $viewer,
            'Trình xem ảnh phải là nơi điều phối tới các tính năng — qua đúng kênh requestActivity.');
    }
}
