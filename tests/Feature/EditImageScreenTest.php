<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * MÀN "CHỈNH ẢNH" — bước 5.3 của kế hoạch bỏ canvas (2026-09-26).
 *
 * MỘT ảnh, BA chế độ: Tả · Khoanh · Cọ. Đây là chỗ thay thế việc "khoanh vùng sửa TRÊN CANVAS
 * nhiều layer" — làm đúng một việc, trên đúng một ảnh, và chạy được trên điện thoại.
 *
 * Bất biến khoá lại:
 *   (a) MẶC ĐỊNH là 'describe' (Tả) — quyết định D5: mask là TUỲ CHỌN, không bắt buộc vẽ trước;
 *   (b) hệ toạ độ là MỘT hàm, đọc getBoundingClientRect của chính thẻ <img> — không canvasMetrics;
 *   (c) hợp đồng mask KHÔNG đổi: rect → mask_mode='rect' + region; brush → mask_data PNG base64;
 *   (d) mask gửi lên đúng quy ước backend: NỀN TRẮNG + NÉT ĐEN (xem StudioController::buildMaskImage);
 *   (e) nút chạy hiện GIÁ THẬT theo model + cỡ (không phải hằng số) — nguyên tắc 3;
 *   (f) mở từ lưới kết quả: đặt ảnh đang làm việc RỒI mới mở màn.
 */
class EditImageScreenTest extends TestCase
{
    private function screen(): string
    {
        return (string) file_get_contents(resource_path('js/studio/components/EditImageModal.vue'));
    }

    public function test_the_default_mode_is_describe_without_a_mask(): void
    {
        $src = $this->screen();

        // D5: mặc định "Tả" (không mask). Chế độ Tả phải được khởi tạo là giá trị ĐẦU TIÊN của mode.
        $this->assertMatchesRegularExpression(
            '/const mode = ref\(\'describe\'\)/',
            $src,
            'Mặc định phải là chế độ Tả — bắt vẽ mask trước khi được sửa ảnh là CHẶN Ở BƯỚC KHÓ NHẤT.',
        );
        // Và chế độ Tả phải THỰC SỰ không gửi mask.
        $this->assertStringContainsString("store.inpaintMaskDone = false", $src,
            'Chế độ Tả phải TẮT mask — nếu không, mask của lần trước bị gửi kèm (sửa sai vùng).');
    }

    public function test_all_three_modes_are_present_and_reachable(): void
    {
        $src = $this->screen();

        foreach (['describe', 'rect', 'brush'] as $m) {
            $this->assertStringContainsString('data-edit-mode="'.$m.'"', $src, 'Thiếu chế độ '.$m.'.');
        }
    }

    public function test_the_coordinate_system_is_one_function_reading_the_image_rect(): void
    {
        $src = $this->screen();

        $this->assertStringContainsString('function toImageCoords(', $src, 'Thiếu hàm toạ độ dùng chung.');
        // MỘT ảnh, không xoay, không layer ⇒ hình chữ nhật của <img> CHÍNH LÀ vùng ảnh.
        // (Mã đọc `imgEl.value` vào biến rồi mới gọi rect — khẳng định cả hai, không khẳng định chuỗi nối.)
        $this->assertStringContainsString('imgEl.value', $src, 'Hàm toạ độ phải đọc đúng thẻ <img> đang hiển thị.');
        $this->assertStringContainsString('getBoundingClientRect()', $src, 'Toạ độ phải lấy từ hình chữ nhật THẬT của ảnh trên màn hình.');
        // KHÔNG được kéo theo hệ toạ độ cũ của canvas (đo canvasZoom + pan/zoom/rotation).
        // Khẳng định LỜI GỌI, không khẳng định sự có mặt của chữ: tên hàm cũ được NHẮC trong chú
        // thích giải thích vì sao không dùng nó — đó là tài liệu, không phải phụ thuộc.
        $this->assertStringNotContainsString('store.canvasMetrics', $src);
        $this->assertStringNotContainsString('this.canvasMetrics', $src);
        $this->assertStringNotContainsString('store.canvasZoom', $src, 'Màn một-ảnh không được phụ thuộc ref canvas.');
    }

    public function test_the_mask_payload_contract_is_unchanged(): void
    {
        $src = $this->screen();

        // rect: mask_mode='rect' + region {x,y,w,h}. brush: mask_mode='brush' + mask_data.
        $this->assertStringContainsString("store._inpaintMaskKind = 'rect'", $src);
        $this->assertStringContainsString('store.inpaintMaskBox = { ...region.value }', $src);
        $this->assertStringContainsString("store._inpaintMaskKind = 'brush'", $src);
        $this->assertStringContainsString('store.inpaintBrushData = exportBrushMask()', $src);

        // Và đi qua ĐÚNG action sửa ảnh đang có — không thêm đường thứ hai.
        $this->assertStringContainsString('store.inpaint(', $src, 'Phải gọi store.inpaint() — một đường sinh ảnh, không đẻ đường thứ hai.');
    }

    public function test_the_exported_brush_mask_is_white_background_with_black_strokes(): void
    {
        $src = $this->screen();

        // ĐÂY LÀ CHỖ DỄ SAI NHẤT: backend dựng mask theo quy ước "TRẮNG = giữ nguyên, ĐEN = vùng sửa"
        // (StudioController::buildMaskImage). Gửi sai chiều thì fal sửa ĐÚNG VÙNG MUỐN GIỮ — không lỗi,
        // không cảnh báo, chỉ ra ảnh sai.
        $this->assertStringContainsString('exportBrushMask()', $src);
        $this->assertStringContainsString("octx.fillStyle = '#fff'", $src, 'Mask gửi lên phải có NỀN TRẮNG.');
        $this->assertStringContainsString("ctx.strokeStyle = '#000'", $src, 'Nét vẽ phải là MÀU ĐEN.');
        $this->assertStringContainsString("ctx.fillStyle = '#000'", $src, 'Chấm cọ cũng phải màu đen.');
    }

    public function test_the_run_button_shows_the_real_price_of_this_model(): void
    {
        $src = $this->screen();

        // Sửa ảnh là đường đắt nhất (2–10 credit). Hằng số ở đây là lỗi nguyên tắc 3.
        $this->assertStringContainsString('store.costFor(', $src, 'Giá phải tra theo model + cỡ, không viết cứng.');
        $this->assertStringContainsString('{{ editCost }} credit', $src, 'Nút chạy phải hiện số credit thật.');
    }

    public function test_opening_from_the_result_grid_sets_the_working_image_first(): void
    {
        $grid = (string) file_get_contents(resource_path('js/studio/components/ResultGrid.vue'));

        $posSelect = strpos($grid, 'store.select(g); store.editImageOpen = true;');
        $this->assertNotFalse($posSelect,
            'Nút «Sửa» phải đặt ảnh đang làm việc RỒI mới mở màn — nếu không, màn mở ra với ảnh cũ.');
        $this->assertStringContainsString('data-edit-open', $grid, 'Nút mở màn Chỉnh ảnh thiếu dấu hiệu nhận biết.');
    }

    /**
     * (g) MÀN NÀY PHẢI RỘNG THẬT TRÊN DESKTOP.
     *
     * Lỗi đo được 2026-09-26 (Chrome thật, 1280x800): BaseModal chỉ tôn trọng prop 'full' ở
     * NHÁNH CÓ 'height'; màn này không truyền 'height' nên rơi vào nhánh còn lại và ăn 'max-w-lg'
     * = 512px. Kết quả: hộp thoại rộng 470px, trừ cột điều khiển 380px còn 90px cho vùng ảnh
     * ⇒ ảnh hiển thị 66x66px, canvas cọ 66x66px — không vẽ được gì.
     *
     * Đây là loại lỗi mà test đọc source KHÔNG bắt được (mọi class đều "đúng"), nên bất biến phải
     * được ghi thẳng vào chỗ dễ sửa nhất: 'full' phải có tác dụng ở CẢ HAI nhánh.
     */
    public function test_modal_full_phai_co_tac_dung_o_ca_hai_nhanh(): void
    {
        $modal = (string) file_get_contents(resource_path('js/studio/components/BaseModal.vue'));

        $fullBranch = "full ? 'w-[min(1440px,calc(100vw-1rem))] max-w-none' : ";
        $this->assertSame(
            2,
            substr_count($modal, $fullBranch),
            "BaseModal có 2 nhánh (có 'height' / không 'height'). Prop 'full' phải được tôn trọng ở CẢ HAI — "
            .'bỏ sót một nhánh là màn rộng rơi về max-w-lg (512px) và vùng ảnh co còn vài chục px.'
        );

        $screen = $this->screen();
        $this->assertStringContainsString('full', $screen, "Màn Chỉnh ảnh phải mở ở chế độ 'full'.");
        $this->assertStringContainsString(
            "lg:w-[380px]",
            $screen,
            'Cột điều khiển cố định 380px là LÝ DO phải mở rộng modal: nếu modal hẹp, cột này ăn hết chỗ của ảnh.'
        );
    }
}
