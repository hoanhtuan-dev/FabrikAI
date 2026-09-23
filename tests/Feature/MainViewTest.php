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
        $status = (string) file_get_contents(resource_path('js/studio/components/CanvasStatusBar.vue'));

        // MỘT luật, ở store — hai nơi gọi.
        $this->assertStringContainsString('setMainView(v)', $actions, 'setMainView phải là action của store.');
        $this->assertStringContainsString('saveBarSettings', $actions, 'Chọn mặt nào thì lần sau mở lại phải đúng mặt đó.');
        $this->assertStringContainsString('store.setMainView(', $status, 'Thanh trạng thái phải gọi action của store, không tự đặt state.');
    }

    public function test_the_switch_lives_in_the_status_bar_so_every_width_can_reach_it(): void
    {
        $status = (string) file_get_contents(resource_path('js/studio/components/CanvasStatusBar.vue'));

        // Rail công cụ là 'hidden lg:flex' (chỉ từ 1024px) ⇒ đặt nút ở đó là điện thoại không có lối đổi mặt.
        $this->assertStringContainsString('data-main-view-switch="grid"', $status);
        $this->assertStringContainsString('data-main-view-switch="canvas"', $status);
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

        // KHÔNG thêm endpoint, KHÔNG đổi luồng — mọi nút đi qua ĐÚNG hàm OutputModule đang dùng.
        $this->assertStringContainsString('store.select(g)', $grid);
        $this->assertStringContainsString('store.requestActivity(activity)', $grid);
        $this->assertStringContainsString("'/api/generations/' + g.id + '/download'", $grid);
        $this->assertStringContainsString('store.openViewer(g)', $grid);
        $this->assertStringContainsString('store.deleteGen(g)', $grid, 'Xoá phải đi cùng đường với GalleryModal.');
    }
}
