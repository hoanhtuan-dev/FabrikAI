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

        // Layer ẩn KHÔNG bị gỡ khỏi DOM: vòng lặp phải đi qua TOÀN BỘ canvasLayers và đánh dấu layer ẩn.
        $this->assertStringContainsString('v-for="(l, i) in store.canvasLayers"', $app,
            'Layer ẩn phải được giữ trong DOM (mờ đi tại chỗ). Nếu duyệt visibleLayers thì layer ẩn bị gỡ '
            .'ngay lập tức và không thể có hiệu ứng phủ cả thẻ layer.');
        $this->assertStringContainsString('class="layer-el absolute left-0 top-0"', $app,
            'Thẻ layer phải mang class .layer-el để CSS lo phần mờ/thu.');
        $this->assertStringContainsString(":class=\"l.visible === false ? 'layer-el--hidden' : ''\"", $app,
            'Thiếu dấu hiệu layer đang tắt (.layer-el--hidden).');

        // CSS: độ mờ = độ mờ riêng của layer × hệ số ẩn/hiện, và theo TOKEN chung.
        preg_match('/\.layer-el\s*\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m[1] ?? '', 'app.css thiếu định nghĩa .layer-el.');
        $this->assertStringContainsString('calc(var(--layer-opacity, 1) * var(--layer-vis))', $m[1],
            'Độ mờ phải nhân độ mờ riêng của layer với hệ số ẩn/hiện (nếu không, layer có opacity riêng sẽ mờ sai).');
        $this->assertStringContainsString('var(--motion-dur-', $m[1],
            'Hiệu ứng bật/tắt layer phải dùng token chuyển động, không viết số ms.');

        preg_match('/\.layer-el--hidden\s*\{([^}]*)\}/', $css, $hidden);
        $this->assertNotEmpty($hidden[1] ?? '', 'app.css thiếu .layer-el--hidden.');
        $this->assertStringContainsString('--layer-vis: 0', $hidden[1], 'Layer đã tắt phải có hệ số mờ bằng 0.');
        $this->assertStringContainsString('pointer-events: none', $hidden[1],
            'Layer đã tắt không được nhận chuột — nếu không, nó chặn cả canvas dù vô hình.');

        // Opacity riêng của layer phải đi qua BIẾN, không ghi thẳng 'opacity' (ghi thẳng là CSS thua inline style).
        preg_match('/function layerStyle\(l, i\)\s*\{(.*?)\n\}/s', $app, $ls);
        $this->assertNotEmpty($ls[1] ?? '', 'Không đọc được layerStyle().');
        $this->assertStringContainsString("'--layer-opacity'", $ls[1],
            'layerStyle phải đưa độ mờ vào biến --layer-opacity để CSS nhân được với hệ số ẩn/hiện.');
        $this->assertStringNotContainsString('opacity:', $ls[1],
            'layerStyle KHÔNG được ghi thẳng opacity — inline style sẽ đè mất hiệu ứng mờ của CSS.');

        // Hàng trong bảng Lớp cũng phải mờ dần khi ẩn layer, không đổi tức thì.
        $this->assertMatchesRegularExpression('/class="motion-ui[^"]*group flex items-center/', $layers,
            'Hàng layer trong bảng Lớp phải chuyển động khi đổi trạng thái ẩn/hiện.');
    }

    public function test_shipped_build_carries_the_canvas_controls(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('public_html/build/manifest.json')), true);
        $css = (string) file_get_contents(base_path('public_html/build/'.$manifest['resources/css/app.css']['file']));
        $js = (string) file_get_contents(base_path('public_html/build/'.$manifest['resources/js/studio/main.js']['file']));

        foreach (['.canvas-bg-grid', '.canvas-bg-cream', '.layer-el--hidden', '.motion-pop-in'] as $needle) {
            $this->assertStringContainsString($needle, $css, "CSS đã build thiếu $needle — chạy lại npm run build.");
        }
        foreach (['canvas-bg-', 'layer-el--hidden', 'selectionUnitLabels', 'lockedSelectionCount'] as $needle) {
            $this->assertStringContainsString($needle, $js, "JS đã build thiếu $needle — chạy lại npm run build.");
        }
        $this->assertStringNotContainsString('Tải ảnh đang chọn', $js, 'Bundle vẫn còn nút "Tải ảnh đang chọn".');
    }

    public function test_remove_background_feature_is_gone_everywhere(): void
    {
        // Gỡ nút thì phải gỡ CẢ DÂY CHUYỀN — để lại route/controller/action là mã chết vẫn gọi được.
        $layers = $this->vue('LayersPanel.vue');
        $store = $this->src('js/studio/store.js');

        $this->assertStringNotContainsString('Xóa nền AI', $layers, 'Bảng Lớp vẫn còn nút "Xóa nền AI".');
        $this->assertStringNotContainsString('removeBgConfirmOpen', $layers, 'Còn state popup xác nhận xóa nền.');
        $this->assertStringNotContainsString('removeBackground', $store, 'store.js vẫn còn action removeBackground().');

        $controller = (string) file_get_contents(app_path('Http/Controllers/StudioController.php'));
        $this->assertStringNotContainsString('function removeBackground(', $controller, 'Controller vẫn còn removeBackground().');
        $this->assertStringNotContainsString('function buildBackgroundMask(', $controller, 'buildBackgroundMask() chỉ phục vụ xóa nền ⇒ phải gỡ.');

        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertStringNotContainsString("'/remove-bg'", $routes, 'routes/web.php vẫn còn route /remove-bg.');

        $registry = (string) file_get_contents(app_path('Support/ModuleRegistry.php'));
        $this->assertStringNotContainsString("'remove-bg'", $registry, 'ModuleRegistry vẫn khai endpoint remove-bg.');

        // Và kiểm bằng chính bảng route đang chạy: không còn đường nào tên remove-bg.
        $names = array_map(static fn ($r) => $r->getName(), app('router')->getRoutes()->getRoutes());
        $this->assertNotContains('remove-bg', $names, 'Bảng route vẫn còn route tên remove-bg.');

        // Hậu kỳ theo METADATA trong job thì GIỮ (generation xếp hàng trước lúc gỡ vẫn cần nó) — nhưng phải
        // nói rõ vì sao còn, nếu không người sau sẽ tưởng là mã chết rồi xoá.
        $job = (string) file_get_contents(app_path('Jobs/RenderImageJob.php'));
        $this->assertStringContainsString("=== 'remove-bg'", $job, 'Thiếu hậu kỳ remove-bg cho generation đã xếp hàng trước đó.');
        $this->assertStringContainsString('KHÔNG còn đường nào tạo mode', $job,
            'Phải ghi rõ vì sao nhánh remove-bg trong job vẫn được giữ.');
    }

    public function test_layer_row_buttons_are_always_visible(): void
    {
        $layers = $this->vue('LayersPanel.vue');

        // Ba nút (khóa · nhân đôi · gỡ) phải LUÔN hiện: trước đây chúng opacity-0 + chỉ hiện khi hover
        // ⇒ không ai biết là có, và trên thiết bị cảm ứng thì gần như không bấm được.
        $this->assertStringNotContainsString('group-hover:opacity-100', $layers,
            'Nút trong bảng Lớp không được ẩn chờ hover nữa.');
        $this->assertStringNotContainsString('opacity-0 transition-opacity', $layers,
            'Bỏ lớp opacity-0 của nhóm nút hành động trong bảng Lớp.');

        // Vẫn phải đủ 3 hành động, và nhóm layer cũng vậy.
        foreach (['toggleLayerLock', 'duplicateLayer', 'deleteLayer', 'toggleGroupLock', 'duplicateGroup', 'deleteGroup'] as $action) {
            $this->assertStringContainsString($action, $layers, "Thiếu hành động $action trong bảng Lớp.");
        }
    }

    public function test_save_output_is_lit_only_for_new_images(): void
    {
        $store = $this->src('js/studio/store.js');
        $layers = $this->vue('LayersPanel.vue');

        // Nhận biết "đã có trong Output" theo ĐÚNG thứ tự danh tính của ảnh.
        $this->assertStringContainsString('activeLayerInOutputs()', $store, 'Thiếu getter biết ảnh đã có trong Output.');
        foreach (['savedOutputId', 'l.genId', 'media_url'] as $needle) {
            $this->assertStringContainsString($needle, $store, "Thiếu cách nhận biết trùng qua $needle.");
        }
        $this->assertStringContainsString('canSaveActiveLayerToOutput()', $store, 'Thiếu getter "có ảnh mới để lưu".');

        // Chặn lưu trùng NGAY ĐẦU hàm (không gọi máy chủ) + đánh dấu sau khi lưu thành công.
        preg_match('/async saveActiveLayerToOutput\(\)\s*\{(.*?)\n    \},/s', $store, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được saveActiveLayerToOutput().');
        $this->assertStringContainsString('if (this.activeLayerInOutputs)', $m[1],
            'Hàm lưu phải tự chặn khi ảnh đã có trong Output — nút im không đủ, người dùng còn bấm được bằng phím/khác đường.');
        $this->assertStringContainsString('savedOutputId =', $m[1],
            'Sau khi lưu phải ĐÁNH DẤU layer đã có bản trong Output (layer ghép data URL không đổi danh tính).');

        // Nút phải phản ánh đúng trạng thái đó.
        $this->assertStringContainsString('store.canSaveActiveLayerToOutput', $layers,
            'Nút "Lưu Output" phải sáng theo trạng thái có ảnh mới hay không.');
        $this->assertStringContainsString('bg-brand-600 text-white', $layers,
            'Trạng thái có ảnh mới phải là nút SÁNG (màu thương hiệu).');
    }
}
