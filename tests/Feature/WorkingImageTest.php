<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ẢNH ĐANG LÀM VIỆC — bước 5.1 của kế hoạch bỏ canvas (2026-09-26).
 *
 * VÌ SAO CẦN KHOÁ: "tôi đang sửa ẢNH NÀO" hôm nay chỉ trả lời được gián tiếp qua "layer nào đang
 * chọn". Mọi công cụ một-ảnh (Sửa ảnh · Upscale · Biến thể · Gợi ý · Kịch bản quay) đọc getter
 * \`upscaleSrc\`, mà getter đó lấy từ layer ⇒ bỏ layer là bỏ luôn ảnh nguồn của 8 card.
 *
 * Bước 5.1 tách khái niệm đó thành DỮ LIỆU TƯỜNG MINH (\`store.workingImage\`) và đặt nó ở MỌI đường
 * vào. Nhờ vậy bước 5.4 (bỏ canvas) chỉ còn là XOÁ MỘT NHÁNH trong getter, không phải viết lại card nào.
 *
 * Repo không có runner JS — bài này đọc thẳng mã nguồn, đúng lối các test giao diện đã có
 * (DesignSystemTest · CanvasControlsTest).
 */
class WorkingImageTest extends TestCase
{
    private function store(): string
    {
        $files = [
            'state' => 'resources/js/studio/store/state.js',
            'actions' => implode("\n", array_map(
                fn ($f) => (string) file_get_contents(base_path($f)),
                glob(base_path('resources/js/studio/store/actions/*.js')),
            )),
            'getters' => 'resources/js/studio/store/getters.js',
        ];

        return implode("\n", array_map(fn ($f) => (string) file_get_contents(base_path($f)), [
            $files['state'], $files['getters'],
        ]))."\n".$files['actions'];
    }

    public function test_the_working_image_is_explicit_state_not_implied_by_a_layer(): void
    {
        $state = (string) file_get_contents(base_path('resources/js/studio/store/state.js'));

        $this->assertStringContainsString('workingImage: null', $state,
            'Thiếu workingImage trong state — khái niệm "ảnh đang làm việc" phải là DỮ LIỆU, không suy từ layer.');
    }

    public function test_set_working_image_accepts_a_url_or_a_generation(): void
    {
        $actions = (string) file_get_contents(base_path('resources/js/studio/store/actions/generation.js'));

        $this->assertStringContainsString('setWorkingImage(img, kind', $actions);
        // Nhận cả chuỗi URL lẫn object generation — hai đường gọi khác nhau trong mã.
        $this->assertStringContainsString("typeof img === 'string' ? img : (img.media_url || img.url", $actions);
        // Tên hiển thị phải suy được khi chỉ có URL (ảnh nguồn/upload không có id).
        $this->assertStringContainsString("'Ảnh đang chọn'", $actions);
    }

    public function test_every_entry_point_sets_the_working_image(): void
    {
        // BA đường vào: ảnh kết quả (select), ảnh vừa tạo xong (addGen), ảnh nguồn/upload (setSource).
        // Thiếu một đường là card vẫn lấy ảnh từ layer ⇒ bước 5.4 sẽ âm thầm làm mất ảnh nguồn.
        $gen = (string) file_get_contents(base_path('resources/js/studio/store/actions/generation.js'));
        $acct = (string) file_get_contents(base_path('resources/js/studio/store/actions/account.js'));
        $src = (string) file_get_contents(base_path('resources/js/studio/store/actions/sources.js'));

        $this->assertStringContainsString('setWorkingImage', $gen, 'select() phải đặt ảnh đang làm việc.');
        $this->assertStringContainsString('setWorkingImage', $acct, 'addGen() phải đặt ảnh đang làm việc.');
        $this->assertStringContainsString('setWorkingImage', $src, 'setSource() phải đặt ảnh đang làm việc.');
    }

    public function test_select_sets_the_working_image_before_pushing_the_layer(): void
    {
        $gen = (string) file_get_contents(base_path('resources/js/studio/store/actions/generation.js'));

        $posWorking = strpos($gen, 'this.setWorkingImage({ id: g.id');
        $posLayer = strpos($gen, "this.pushCanvasLayer(String(g.id), 'gen'");

        $this->assertNotFalse($posWorking, 'select() thiếu setWorkingImage.');
        $this->assertNotFalse($posLayer, 'select() thiếu pushCanvasLayer (giai đoạn chuyển vẫn giữ canvas).');
        $this->assertLessThan($posLayer, $posWorking,
            'setWorkingImage phải chạy TRƯỚC pushCanvasLayer — để khi bước 5.4 xoá dòng layer, ảnh đang làm việc vẫn được đặt.');
    }

    public function test_the_source_getter_has_a_working_image_branch_between_layer_and_legacy(): void
    {
        $getters = (string) file_get_contents(base_path('resources/js/studio/store/getters.js'));

        // Thứ tự đọc: LAYER (nguồn pixel thật, có thể đã bị sửa) → workingImage → editSource/preview.
        // Bỏ nhánh workingImage thì bước 5.4 làm mọi card mất ảnh nguồn.
        $this->assertStringContainsString('this.workingImage && this.workingImage.url', $getters,
            'upscaleSrc thiếu nhánh workingImage — bỏ canvas sẽ làm 8 card mất ảnh nguồn.');
        $this->assertStringContainsString('this.workingImage && this.workingImage.name', $getters,
            'upscaleName thiếu nhánh workingImage — tên ảnh sẽ hiện sai sau khi bỏ layer.');
    }

    public function test_the_legacy_fallbacks_are_still_intact(): void
    {
        // Giai đoạn chuyển: KHÔNG được xoá đường cũ trước khi bước 5.4 xong. Xoá sớm là mất ảnh nguồn
        // ở những đường chưa kịp chuyển (ví dụ phiên đang mở từ localStorage).
        $getters = (string) file_get_contents(base_path('resources/js/studio/store/getters.js'));

        $this->assertStringContainsString('this.activeLayerId', $getters, 'Nhánh layer (nguồn pixel thật) phải còn.');
        $this->assertStringContainsString('this.editSource', $getters, 'Nhánh editSource (đường cũ) phải còn.');
        $this->assertStringContainsString('this.preview', $getters, 'Nhánh preview (đường cũ) phải còn.');
    }
}
