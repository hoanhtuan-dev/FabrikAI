<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [Yêu cầu 2026-09-20 — đợt 3] BA VIỆC Ở KHUNG CANVAS:
 *   (A) nút XÓA khi chọn đối tượng phải CHẠY, và xác nhận phải KẾ THỪA popup "⚠️ Dọn toàn bộ canvas?";
 *   (B) các nút MÀU NỀN canvas ở thanh trạng thái phải đúng (ô màu = màu thật của canvas) và BỎ nút
 *       "Tải ảnh đang chọn";
 *   (C) BẬT/TẮT LAYER phải có hiệu ứng chuyển động.
 *
 * Mỗi bất biến dưới đây khoá một cách hỏng THẬT đã đo được trong mã nguồn trước đợt này:
 *  · store.deleteSelection() đặt cờ confirmDeleteOpen nhưng KHÔNG có popup nào render ⇒ bấm Delete
 *    (hoặc nút thùng rác) không thấy gì xảy ra, cờ treo lại và còn CHẶN phím tắt layer;
 *  · đường xóa theo lựa chọn bỏ qua hoàn toàn layer KHÓA (nút xóa ở bảng Lớp thì lại tôn trọng khóa);
 *  · vùng canvas trỏ tới class bàn cờ không hề được định nghĩa ⇒ chọn "lưới" nền trong suốt;
 *  · ô màu nền ở status bar tự vẽ màu riêng ⇒ lệch với màu thật của canvas;
 *  · layer bị ẩn bị gỡ khỏi danh sách hiển thị nên biến mất tức thì (không có hiệu ứng).
 */
class CanvasControlsTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(resource_path($rel));
    }

    private function vue(string $name): string
    {
        return $this->src('js/studio/components/'.$name);
    }

    public function test_delete_confirmation_reuses_the_shared_dialog(): void
    {
        $dialog = $this->vue('ConfirmDialog.vue');

        // Một popup DÙNG CHUNG, có trợ năng đầy đủ (và phải hủy được bằng Esc + bấm nền ⇒ không thể treo).
        foreach ([
            'role="dialog"' => 'Popup xác nhận phải là dialog thật cho trình đọc màn hình.',
            'aria-modal="true"' => 'Popup phải là modal.',
            "e.key === 'Escape'" => 'Popup phải đóng được bằng Esc — nếu không, người dùng bàn phím bị kẹt.',
            '@click.self="cancel"' => 'Bấm nền phải hủy được.',
            'restoreTo' => 'Phải TRẢ focus về chỗ cũ khi đóng (nếu không, mất điểm dừng bàn phím).',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $dialog, $why);
        }

        // Hành động xóa đối tượng KẾ THỪA đúng popup đó, và popup cũ vẫn giữ nguyên câu hỏi gốc.
        $app = $this->src('js/studio/StudioApp.vue');
        $this->assertStringContainsString('import ConfirmDialog', $app, 'StudioApp phải dùng popup xác nhận dùng chung.');
        $this->assertStringContainsString('store.confirmDeleteOpen', $app,
            'Thiếu popup cho cờ confirmDeleteOpen — đây chính là lỗi "bấm Delete không có gì xảy ra".');
        $this->assertStringContainsString('store.confirmDeleteSelection()', $app,
            'Nút xác nhận phải gọi đúng hành động xóa đối tượng đang chọn.');

        $layers = $this->vue('LayersPanel.vue');
        $this->assertStringContainsString('⚠️ Dọn toàn bộ canvas?', $layers,
            'Popup dọn canvas phải giữ nguyên câu hỏi gốc (đây là popup được kế thừa).');
        $this->assertStringContainsString('<ConfirmDialog', $layers,
            'Popup dọn canvas phải chuyển sang component dùng chung thay vì markup chép tay.');
    }

    public function test_delete_works_for_a_single_selected_object(): void
    {
        $store = $this->src('js/studio/store.js');
        $app = $this->src('js/studio/StudioApp.vue');

        // Đối tượng đang chọn LUÔN gồm layer active ⇒ chọn 1 đối tượng vẫn là 1 đơn vị để xóa.
        $this->assertStringContainsString('selectedIds() { const a = [this.activeLayerId, ...this.selectedLayerIds]', $store,
            'Đối tượng đang chọn phải bao gồm layer active (chọn 1 đối tượng vẫn phải xóa được).');

        // Phím Delete → mở xác nhận; nút thùng rác trên thanh công cụ cũng đi đúng đường đó.
        $this->assertMatchesRegularExpression("/e\\.key === 'Delete' \\|\\| e\\.key === 'Backspace'/", $app,
            'Thiếu phím Delete/Backspace cho layer đang chọn.');
        $this->assertStringContainsString('store.deleteSelection()', $app, 'Phím Delete phải mở xác nhận xóa.');
        $this->assertStringContainsString('store.deleteSelection()', $this->vue('ContextToolbar.vue'),
            'Nút thùng rác trên thanh công cụ phải mở xác nhận xóa (không xóa ngay tay).');

        // Nói RÕ sắp xóa cái gì, và tôn trọng layer KHÓA (trước đây đường lựa chọn bỏ qua khóa).
        $this->assertStringContainsString('selectionUnitLabels()', $store, 'Thiếu nhãn đối tượng đang chọn cho popup.');
        $this->assertStringContainsString('lockedSelectionCount()', $store, 'Popup phải biết có bao nhiêu đối tượng đang khóa.');
        $this->assertMatchesRegularExpression(
            '/confirmDeleteSelection\(\)\s*\{.*?l\.locked.*?toast\(/s',
            $store,
            'Xóa theo lựa chọn phải TÔN TRỌNG layer khóa — đúng luật đã áp ở nút xóa trong bảng Lớp.'
        );

        // Mọi nhánh kết thúc đều phải hạ cờ, nếu không app kẹt ở "đang có modal".
        preg_match('/confirmDeleteSelection\(\)\s*\{(.*?)\n    \},/s', $store, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được thân confirmDeleteSelection().');
        $this->assertStringContainsString('this.confirmDeleteOpen = false', $m[1],
            'Kết thúc xóa phải hạ cờ confirmDeleteOpen — cờ treo sẽ chặn cả phím tắt layer.');
        $this->assertGreaterThanOrEqual(2, substr_count($m[1], 'this.confirmDeleteOpen = false'),
            'MỌI nhánh (không có gì để xóa · toàn bộ bị khóa · xóa xong) đều phải hạ cờ.');
    }

    public function test_canvas_backgrounds_come_from_one_source(): void
    {
        $css = $this->src('css/app.css');
        $app = $this->src('js/studio/StudioApp.vue');
        $bar = $this->vue('CanvasStatusBar.vue');

        // Bốn nền canvas khai ĐÚNG MỘT LẦN trong CSS — canvas và ô màu cùng đọc từ đây.
        foreach (['grid', 'dark', 'white', 'cream'] as $id) {
            $this->assertMatchesRegularExpression('/\.canvas-bg-'.$id.'\s*\{[^}]*background/s', $css,
                "app.css thiếu .canvas-bg-$id — nền canvas và ô màu sẽ không thể khớp nhau.");
        }
        $this->assertStringContainsString('repeating-conic-gradient', $css,
            'Nền "lưới" phải là ô bàn cờ thật (trước đây class bàn cờ không hề được định nghĩa).');
        $this->assertMatchesRegularExpression('/\.canvas-bg-dark\s*\{[^}]*var\(--color-ink-950\)/s', $css,
            'Nền tối phải dùng ĐÚNG token ink-950 của canvas.');
        $this->assertMatchesRegularExpression('/\.canvas-bg-cream\s*\{[^}]*var\(--color-cream-100\)/s', $css,
            'Nền kem phải dùng ĐÚNG token cream-100 của canvas.');

        // Hai nơi dùng cùng class, không nơi nào tự vẽ màu riêng.
        $this->assertStringContainsString("'canvas-bg-' +", $app, 'Vùng canvas phải dùng class .canvas-bg-*.');
        $this->assertStringContainsString("'canvas-bg-' + b.id", $bar, 'Ô màu ở status bar phải dùng CHÍNH class đó.');
        $this->assertStringNotContainsString('bgSwatchStyle', $bar,
            'Ô màu không được tự vẽ màu bằng inline style nữa — đó là nguồn lệch thứ hai.');
        $this->assertStringNotContainsString(':style=', $bar, 'Ô màu nền không cần inline style nữa.');
        $this->assertStringNotContainsString('cvs-checker', $app,
            'Vùng canvas không được trỏ lại class bàn cờ không tồn tại.');
    }

    public function test_status_bar_no_longer_downloads_the_active_image(): void
    {
        $bar = $this->vue('CanvasStatusBar.vue');
        $store = $this->src('js/studio/store.js');

        $this->assertStringNotContainsString('downloadActive', $bar, 'Nút "Tải ảnh đang chọn" phải được gỡ khỏi status bar.');
        $this->assertStringNotContainsString('Tải ảnh đang chọn', $bar, 'Không còn nhãn "Tải ảnh đang chọn".');
        $this->assertStringNotContainsString('async downloadActive', $store,
            'Hàm downloadActive() chỉ còn nút đó gọi ⇒ phải gỡ luôn, không để mã chết.');

        // Đường tải ảnh KHÁC vẫn còn nguyên (không gỡ nhầm).
        $this->assertStringContainsString('downloadSelection', $store, 'Vẫn phải giữ đường tải layer đang chọn ở thanh công cụ.');
    }

    public function test_layer_visibility_toggle_animates(): void
    {
        $app = $this->src('js/studio/StudioApp.vue');
        $css = $this->src('css/app.css');
        $layers = $this->vue('LayersPanel.vue');

        // Layer ẩn bị gỡ khỏi danh sách hiển thị ⇒ cần TransitionGroup mới có hiệu ứng vào/ra.
        $this->assertStringContainsString('<TransitionGroup', $app,
            'Danh sách layer trên canvas phải dùng TransitionGroup để bật/tắt có hiệu ứng.');
        foreach (['layer-vis-enter-active', 'layer-vis-enter-from', 'layer-vis-leave-active', 'layer-vis-leave-to'] as $cls) {
            $this->assertStringContainsString($cls, $app, "Thiếu lớp hiệu ứng $cls khi bật/tắt layer.");
            $this->assertStringContainsString('.'.$cls, $css, "app.css thiếu định nghĩa .$cls.");
        }

        // Hiệu ứng phải theo TOKEN chung (và do đó tắt được bằng công tắc giảm chuyển động).
        preg_match('/\.layer-vis-enter-active[^{]*\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được định nghĩa .layer-vis-enter-active.');
        $this->assertStringContainsString('var(--motion-dur-', $m[1], 'Hiệu ứng bật/tắt layer phải dùng token, không viết số ms.');

        // Opacity của layer do inline style giữ ⇒ hiệu ứng mờ phải đặt lên <img> bên trong.
        $this->assertMatchesRegularExpression('/\.layer-vis-(?:enter-from|leave-to)\s+img\s*\{[^}]*opacity:\s*0/s', $css,
            'Hiệu ứng mờ phải đặt lên <img> bên trong thẻ layer (thẻ layer đã có opacity riêng theo từng layer).');

        // Hàng trong bảng Lớp cũng phải mờ dần khi ẩn layer, không đổi tức thì.
        $this->assertMatchesRegularExpression('/class="motion-ui[^"]*group flex items-center/', $layers,
            'Hàng layer trong bảng Lớp phải chuyển động khi đổi trạng thái ẩn/hiện.');
    }

    public function test_shipped_build_carries_the_canvas_controls(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('public_html/build/manifest.json')), true);
        $css = (string) file_get_contents(base_path('public_html/build/'.$manifest['resources/css/app.css']['file']));
        $js = (string) file_get_contents(base_path('public_html/build/'.$manifest['resources/js/studio/main.js']['file']));

        foreach (['.canvas-bg-grid', '.canvas-bg-cream', '.layer-vis-enter-active', '.motion-pop-in'] as $needle) {
            $this->assertStringContainsString($needle, $css, "CSS đã build thiếu $needle — chạy lại npm run build.");
        }
        foreach (['canvas-bg-', 'layer-vis-enter-active', 'selectionUnitLabels', 'lockedSelectionCount'] as $needle) {
            $this->assertStringContainsString($needle, $js, "JS đã build thiếu $needle — chạy lại npm run build.");
        }
        $this->assertStringNotContainsString('Tải ảnh đang chọn', $js, 'Bundle vẫn còn nút "Tải ảnh đang chọn".');
    }
}
