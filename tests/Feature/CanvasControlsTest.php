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

        // CSS: hệ số ẩn/hiện nằm ở thẻ ngoài, độ mờ riêng của layer nằm ở thẻ trong (.layer-body —
        // kiểm kỹ ở test_motion_of_layer_visibility_does_not_delay_the_opacity_slider).
        preg_match('/\.layer-el\s*\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m[1] ?? '', 'app.css thiếu định nghĩa .layer-el.');
        $this->assertStringContainsString('opacity: var(--layer-vis)', $m[1],
            'Thẻ layer phải mờ theo hệ số ẩn/hiện --layer-vis.');
        $this->assertStringContainsString('var(--motion-dur-', $m[1],
            'Hiệu ứng bật/tắt layer phải dùng token chuyển động, không viết số ms.');

        preg_match('/\.layer-el--hidden\s*\{([^}]*)\}/', $css, $hidden);
        $this->assertNotEmpty($hidden[1] ?? '', 'app.css thiếu .layer-el--hidden.');
        $this->assertStringContainsString('--layer-vis: 0', $hidden[1], 'Layer đã tắt phải có hệ số mờ bằng 0.');
        $this->assertStringContainsString('pointer-events: none', $hidden[1],
            'Layer đã tắt không được nhận chuột — nếu không, nó chặn cả canvas dù vô hình.');

        // Độ mờ riêng của layer KHÔNG nằm ở thẻ ngoài (nếu nằm ở đó là đè mất hiệu ứng mờ của CSS).
        preg_match('/function layerStyle\(l, i\)\s*\{(.*?)\n\}/s', $app, $ls);
        $this->assertNotEmpty($ls[1] ?? '', 'Không đọc được layerStyle().');
        $this->assertStringNotContainsString('opacity:', $ls[1],
            'layerStyle KHÔNG được ghi opacity cho thẻ .layer-el — inline style sẽ đè mất hiệu ứng mờ của CSS.');

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

    public function test_layer_handles_are_always_reachable(): void
    {
        $app = $this->src('js/studio/StudioApp.vue');
        $css = $this->src('css/app.css');

        // Tay cầm KHÔNG được là con của thẻ layer: vùng canvas có overflow:hidden nên khi layer phóng
        // to / kéo ra mép, góc layer rơi ra ngoài vùng nhìn thấy ⇒ tay cầm bị CẮT MẤT (đo được: tâm tay
        // cầm nằm đè lên thanh trạng thái z-30 nên bấm vào là bấm thanh trạng thái).
        $this->assertStringContainsString('const layerHandles = computed(', $app,
            'Phải có computed toạ độ tay cầm (lớp phủ theo toạ độ màn hình).');
        // TAY CẦM KHÔNG CÓ ĐIỀU KIỆN: mọi layer đang hiện đều có tay cầm chỉnh kích cỡ, nên không còn
        // trạng thái nào (chưa chọn layer · đang bật công cụ · vừa bỏ chọn) mà "không có tay cầm".
        $this->assertStringContainsString('store.canvasLayers.forEach((l) => {', $app,
            'Phải duyệt MỌI layer để mỗi layer đều có tay cầm — không chỉ layer đang chọn.');
        $this->assertStringContainsString('layer-handle--other', $app,
            'Layer không được chọn vẫn phải có tay cầm (mờ hơn) — thiếu là quay lại lỗi "không có tay cầm".');
        $this->assertStringContainsString('startResizeFromHandle', $app,
            'Kéo tay cầm của layer chưa chọn phải tự CHỌN layer đó rồi mới chỉnh kích cỡ.');
        $this->assertStringContainsString('const keep = (p) =>', $app,
            'Toạ độ tay cầm phải được KẸP vào trong vùng nhìn thấy — nếu không, tay cầm lại bị cắt.');
        // [SỬA 2026-09-20] Trước đây tay cầm bị ẩn ở MỌI chế độ "chỉnh 1 layer" (crop/inpaint/vẽ) — chính
        // là lúc người dùng mất tay cầm mà không hiểu vì sao. Nay chỉ ẩn khi đang Crop/Reframe.
        $this->assertStringNotContainsString('isolateActive.value) return null', $app,
            'Không được ẩn tay cầm chỉ vì đang ở chế độ chỉnh 1 layer.');
        $this->assertStringContainsString('pointer-events-none absolute inset-0 z-40', $app,
            'Tay cầm phải nằm trong lớp phủ riêng, không chặn chuột ra toàn canvas.');

        // BẪY ĐÃ DÍNH THẬT: pointer-events là thuộc tính KẾ THỪA — thiếu dòng này thì tay cầm vẽ ra
        // nhưng không bấm/kéo được (đo được bằng elementFromPoint: trả về phần tử khác).
        preg_match('/\.layer-handle\s*\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m[1] ?? '', 'app.css thiếu định nghĩa .layer-handle.');
        $this->assertStringContainsString('pointer-events: auto', $m[1],
            'Tay cầm PHẢI bật pointer-events (lớp phủ cha là none và thuộc tính này kế thừa).');
        $this->assertStringContainsString('translate: -50% -50%', $m[1],
            'Tay cầm định vị theo TÂM (toạ độ do JS tính).');

        // Layer KHÓA vẫn phải có tay cầm, bấm vào là mở khóa — trước đây điều kiện "&& !l.locked"
        // làm tay cầm BIẾN MẤT không lời giải thích.
        $this->assertStringContainsString('layer-handle--locked', $app, 'Thiếu trạng thái tay cầm của layer khóa.');
        $this->assertStringContainsString('unlockFromHandle', $app, 'Bấm tay cầm của layer khóa phải mở khóa được.');
        $this->assertStringNotContainsString('&& !l.locked', $app,
            'Tay cầm không được ẩn khi layer khóa — đó chính là lỗi "không có tay cầm".');

        // Kéo chỉnh kích cỡ phải chốt HƯỚNG ngay lúc bấm: tay cầm bị kẹp nên điểm bấm có thể không
        // nằm đúng góc layer, dùng "khoảng cách tới tâm" trực tiếp sẽ làm layer thu nhỏ ngược ý.
        preg_match('/function onScalePointerDown\(l, e\)\s*\{(.*?)\n\}/s', $app, $sp);
        $this->assertNotEmpty($sp[1] ?? '', 'Không đọc được onScalePointerDown().');
        $this->assertStringContainsString('ux', $sp[1], 'Thiếu hướng kéo đã chốt (ux) trong onScalePointerDown.');
        $this->assertStringContainsString('* ux', $sp[1], 'Hệ số scale phải chiếu chuyển động lên hướng đã chốt.');
    }

    public function test_hiding_a_layer_keeps_it_selected(): void
    {
        $store = $this->src('js/studio/store.js');

        preg_match('/toggleLayerVisible\(id\)\s*\{(.*?)\n    \},/s', $store, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được toggleLayerVisible().');
        $this->assertStringNotContainsString('setActiveLayer', $m[1],
            'Ẩn layer không được chuyển layer đang chọn sang layer khác: tay cầm sẽ nhảy sang layer khác '
            .'(trông như mất tay cầm) và bật lại layer cũ vẫn không có tay cầm.');
    }

    public function test_motion_of_layer_visibility_does_not_delay_the_opacity_slider(): void
    {
        $css = $this->src('css/app.css');
        $app = $this->src('js/studio/StudioApp.vue');

        // HAI TẦNG: thẻ ngoài giữ hệ số ẩn/hiện + chuyển động opacity (thuộc tính chuyển động được ở
        // MỌI trình duyệt), thẻ trong giữ độ mờ riêng của layer bằng inline style KHÔNG transition.
        // Gộp cả hai vào một opacity rồi transition ⇒ thanh trượt Độ mờ bị trễ 220ms (đã dính thật).
        preg_match('/\.layer-el\s*\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được .layer-el.');
        $this->assertStringContainsString('opacity: var(--layer-vis)', $m[1],
            'Thẻ ngoài phải mang hệ số ẩn/hiện qua --layer-vis.');
        $this->assertMatchesRegularExpression('/transition:\s*opacity\s+var\(--motion-dur-/s', $m[1],
            'Bật/tắt layer phải chuyển động thuộc tính opacity (chạy trên mọi trình duyệt — không phụ thuộc @property).');
        $this->assertStringNotContainsString('@property --layer-vis', $css,
            'Không dựa vào @property nữa: trình duyệt không hỗ trợ thì hiệu ứng bật/tắt layer sẽ KHÔNG chạy.');

        $this->assertMatchesRegularExpression('/\.layer-body\s*\{[^}]*position:\s*relative/s', $css,
            'Thiếu .layer-body (thẻ trong giữ độ mờ riêng của layer).');
        preg_match('/\.layer-body\s*\{([^}]*)\}/', $css, $body);
        $this->assertStringNotContainsString('transition', $body[1] ?? '',
            '.layer-body KHÔNG được có transition — kéo thanh trượt Độ mờ phải tức thì.');

        // Trong template: độ mờ riêng của layer đặt ở .layer-body, và layerStyle KHÔNG ghi opacity.
        $this->assertMatchesRegularExpression('/<div class="layer-body"\s+:style="\{ opacity:/s', $app,
            'Độ mờ riêng của layer phải nằm ở .layer-body (inline) — không phải ở thẻ .layer-el.');
        preg_match('/function layerStyle\(l, i\)\s*\{(.*?)\n\}/s', $app, $ls);
        $this->assertStringNotContainsString('opacity:', $ls[1] ?? '',
            'layerStyle không được đặt opacity cho thẻ .layer-el — inline style sẽ đè hiệu ứng mờ của CSS.');

        // Màn hình trống phải nằm DƯỚI các layer, nếu không nó che mất hiệu ứng mờ của layer cuối.
        $empty = $this->vue('CanvasEmptyState.vue');
        $this->assertStringContainsString('absolute inset-0 z-0', $empty,
            'Màn hình trống phải ở z-0 (dưới layer) — để z-20 thì nó che hiệu ứng mờ của layer đang tắt.');
    }

    public function test_legacy_layers_get_their_size_backfilled(): void
    {
        $store = $this->src('js/studio/store.js');
        $app = $this->src('js/studio/StudioApp.vue');

        // GỐC RỄ ĐÃ ĐO ĐƯỢC: layer lưu từ phiên bản trước khôi phục về với baseW/baseH = null ⇒
        // (a) tay cầm chỉnh kích cỡ KHÔNG hiện (vị trí tay cầm tính theo kích thước layer),
        // (b) khung logic của layer thành 1×1px nên căn lề/chia đều/fit chọn sai.
        $this->assertStringContainsString('ensureLayerSizes()', $store, 'Thiếu hàm vá kích thước cho layer cũ.');
        preg_match('/ensureLayerSizes\(\)\s*\{(.*?)\n    \},/s', $store, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được ensureLayerSizes().');
        $this->assertStringContainsString('naturalWidth', $m[1], 'Phải đo kích thước thật từ chính ảnh của layer.');
        $this->assertStringContainsString('baseW =', $m[1], 'Phải ghi baseW/baseH sau khi đo.');
        $this->assertStringNotContainsString('_positionByImageSize', $m[1],
            'Vá kích thước KHÔNG được xếp lại vị trí layer — layer cũ đã có toạ độ người dùng đặt.');

        // Phải được gọi trong đường KHÔI PHỤC thì layer cũ mới tự lành khi tải trang.
        preg_match('/restoreLayerLayout\(\)\s*\{(.*?)\n    \},/s', $store, $restore);
        $this->assertStringContainsString('this.ensureLayerSizes()', $restore[1] ?? '',
            'restoreLayerLayout phải vá kích thước cho layer cũ ngay khi khôi phục.');

        // Và khi kích thước vẫn còn thiếu (ảnh chưa nạp xong), tay cầm phải đo từ DOM thay vì biến mất.
        $this->assertStringContainsString('function layerBaseSize(l)', $app, 'Thiếu đường lấy kích thước layer.');
        preg_match('/function layerBaseSize\(l\)\s*\{(.*?)\n\}/s', $app, $size);
        $this->assertStringContainsString('offsetWidth', $size[1] ?? '',
            'Phải có đường đo từ DOM (offsetWidth — không tính transform) cho layer chưa có baseW/baseH.');
        $this->assertStringContainsString('data-layer-id', $app,
            'Thẻ layer phải có data-layer-id để đo được đúng ảnh của nó.');
        $this->assertStringContainsString(':data-layer-id="l.id"', $app, 'Thiếu ràng buộc data-layer-id trên thẻ layer.');
    }

    public function test_handles_are_recomputed_when_the_canvas_resizes(): void
    {
        $app = $this->src('js/studio/StudioApp.vue');

        // ĐO ĐƯỢC: trên khung 390px, tay cầm còn ở "left: 734px" trong khi vùng canvas chỉ rộng 364px
        // ⇒ nằm ngoài màn hình, không bấm được. Nguyên nhân: computed không có phụ thuộc nào đổi khi
        // vùng canvas đổi kích thước (không phải cửa sổ đổi là đủ — kéo dock, mở/đóng bảng cũng đổi).
        $this->assertStringContainsString('const viewportTick = ref(0)', $app,
            'Thiếu nhịp khung nhìn để tay cầm tính lại vị trí.');
        $this->assertStringContainsString('viewportTick.value++', $app, 'Nhịp khung nhìn phải được tăng khi vùng canvas đổi.');
        preg_match('/const layerHandles = computed\(\(\) => \{(.*?)return \[\];/s', $app, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được đầu hàm layerHandles.');
        $this->assertStringContainsString('viewportTick.value', $m[1],
            'layerHandles PHẢI phụ thuộc nhịp khung nhìn — nếu không, toạ độ tay cầm bị "đóng băng" theo khung cũ.');
        $this->assertStringContainsString('new ResizeObserver', $app,
            'Phải theo dõi kích thước phần tử canvas (dock co/giãn, bảng Lớp bật/tắt cũng đổi kích thước).');
    }

    public function test_handles_avoid_overlays_that_cover_the_canvas(): void
    {
        $app = $this->src('js/studio/StudioApp.vue');

        // ĐO ĐƯỢC: trên mobile, tay cầm bị kẹp vào đúng vùng bị NGĂN KÉO bảng Lớp che ⇒
        // elementFromPoint trả về FOOTER của bảng Lớp: "có tay cầm" mà bấm không được.
        $this->assertStringContainsString('function handleClampBox(', $app, 'Thiếu vùng cho phép đặt tay cầm.');
        preg_match('/function handleClampBox\(canvasRect\)\s*\{(.*?)\n\}/s', $app, $box);
        $this->assertStringContainsString('data-covers-canvas', $box[1] ?? '',
            'Phải trừ đi những lớp phủ có khai data-covers-canvas.');
        $this->assertStringContainsString("cs.display === 'none'", $box[1] ?? '',
            'Lớp phủ đang ẩn (display:none) không được tính là che.');

        // Hai lớp phủ đè lên canvas phải tự khai hướng che.
        $this->assertStringContainsString('data-covers-canvas="right"', $app,
            'Ngăn kéo bảng Lớp (mobile) phải khai che phía phải.');
        $this->assertStringContainsString('data-covers-canvas="bottom"', $app,
            'Thanh công cụ floating (mobile) phải khai che phía dưới.');

        // Và vùng đó phải được DÙNG khi kẹp toạ độ.
        preg_match('/const keep = \(p\) => \(\{/', $app, $keep, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($keep, 'Thiếu hàm kẹp toạ độ tay cầm.');
        $segment = substr($app, max(0, $keep[0][1] - 700), 700);
        $this->assertStringContainsString('handleClampBox(r)', $segment,
            'Hàm kẹp phải dùng vùng cho phép (đã trừ lớp phủ), không chỉ kẹp vào vùng canvas.');
    }

    public function test_single_layer_edit_mode_keeps_handles_and_visibility_visible(): void
    {
        $app = $this->src('js/studio/StudioApp.vue');
        $bar = $this->vue('CanvasStatusBar.vue');

        // Chế độ "chỉnh 1 layer" (công cụ vẽ/xóa/vùng chọn) không được làm mất tay cầm.
        $this->assertStringNotContainsString('isolateActive.value) return null', $app,
            'Tay cầm KHÔNG được ẩn chỉ vì đang ở chế độ chỉnh 1 layer.');
        $this->assertMatchesRegularExpression('/store\.cropMode \|\| store\.reframeOpen\) return \[\]/', $app,
            'Chỉ ẩn tay cầm khi đang Crop/Reframe (khung crop có tay cầm riêng).');

        // Khung xem 1 layer GIỮ nguyên nguồn ảnh cũ (không đổi luồng crop/inpaint) nhưng phải TÔN TRỌNG
        // ẩn/hiện — trước đây layer bị ẩn vẫn hiện ảnh dự phòng nên bấm con mắt không thấy gì đổi.
        $this->assertStringContainsString(':src="store.upscaleSrc"', $app, 'Giữ nguyên nguồn ảnh của khung xem 1 layer.');
        $this->assertStringContainsString('store.upscaleSrc && store.activeLayer.visible !== false', $app,
            'Khung xem 1 layer phải TÔN TRỌNG trạng thái ẩn/hiện của layer đang chọn.');
        $this->assertStringContainsString('đang bị <b>ẨN</b>', $app, 'Layer bị ẩn thì phải nói rõ, không im lặng hiện ảnh khác.');

        // Thông tin chế độ nằm ở THANH TRẠNG THÁI (không dán nhãn lên canvas cho đỡ rối không gian làm việc).
        $this->assertStringContainsString('Đang VẼ TỰ DO', $bar, 'Thanh trạng thái phải nói rõ đang ở chế độ vẽ 1 layer.');
        $this->assertStringContainsString('Đang XÓA VÙNG', $bar, 'Thanh trạng thái phải nói rõ đang ở chế độ xóa vùng.');
        $this->assertStringNotContainsString('Đang chỉnh 1 layer', $app,
            'Không dán nhãn chế độ lên canvas nữa — thông tin này ở thanh trạng thái.');
    }

    public function test_status_bar_says_when_no_layer_is_selected(): void
    {
        $bar = $this->vue('CanvasStatusBar.vue');

        // Bấm ra vùng trống là BỎ CHỌN; khi đó tay cầm chỉ hiện lúc TRỎ vào một layer (điểm ngọt: không
        // rối mắt mà vẫn không bao giờ "không có tay cầm"). Nhắc cách lấy lại, đặt ở THANH TRẠNG THÁI
        // để không thêm chữ lên vùng làm việc.
        $this->assertStringContainsString('Chưa chọn layer — bấm vào một layer để chỉnh kích cỡ', $bar,
            'Thanh trạng thái phải nhắc cách lấy lại tay cầm khi chưa chọn layer nào.');
    }

    public function test_layers_dock_is_resizable_and_animates_when_toggled(): void
    {
        $app = $this->src('js/studio/StudioApp.vue');
        $store = $this->src('js/studio/store.js');
        $composable = $this->src('js/studio/composables/useDockResize.js');
        $panel = $this->vue('LayersPanel.vue');

        // YÊU CẦU GỐC: bảng Layers (dock phải trong khung canvas) phải KÉO ĐƯỢC bề rộng và phải có hiệu
        // ứng khi bật/tắt. Trước đây: bề rộng là hằng số w-64 và dock bị gỡ khỏi DOM bằng v-if ⇒ không có
        // tay cầm nào để kéo, và bật/tắt là "giật" tức thì.
        $this->assertStringContainsString("inspector: {", $composable, 'Thiếu preset bề rộng cho dock Layers.');
        $this->assertStringContainsString('defaultWidth: 256', $composable,
            'Mặc định dock Layers phải là 256px (= w-64 cũ) để bố cục không đổi khi vào trang.');
        $this->assertStringContainsString('inspectorWidth: 256', $store, 'Store phải giữ bề rộng dock Layers (nguồn sự thật).');
        $this->assertStringContainsString('inspectorWidth: this.inspectorWidth', $store,
            'Bề rộng dock Layers phải được LƯU BỀN cùng cài đặt thanh trạng thái.');
        $this->assertStringContainsString('if (d.inspectorWidth != null) this.inspectorWidth', $store,
            'Bề rộng dock Layers phải được KHÔI PHỤC sau khi tải lại trang.');

        $this->assertStringContainsString("DOCK_PRESETS.inspector", $app, 'Dock Layers phải dùng cơ sở chung useDockResize.');
        $this->assertStringContainsString('panelId: \'dock-inspector\'', $app, 'Dock Layers phải khai panelId cho aria-controls.');
        $this->assertStringContainsString('id="dock-inspector"', $app, 'Thiếu id của dock Layers.');
        $this->assertStringContainsString('class="dock-panel', $app, 'Dock Layers phải dùng class .dock-panel (có hiệu ứng).');
        $this->assertStringContainsString(':data-collapsed="store.inspectorOpen ? \'false\' : \'true\'"', $app,
            'Dock Layers phải đánh dấu trạng thái thu gọn — đó là thứ chạy hiệu ứng bật/tắt.');
        $this->assertStringContainsString('<DockResizer v-if="store.inspectorOpen" :dock="inspectorDock" controls="dock-inspector"', $app,
            'Thiếu VÁCH NGĂN KÉO cho dock Layers — đây chính là "tay cầm điều chỉnh kích cỡ".');
        // Không được gỡ dock khỏi DOM khi tắt: gỡ là mất cả hiệu ứng lẫn trạng thái bên trong.
        $this->assertStringNotContainsString('v-if="store.inspectorOpen" data-covers-canvas', $app,
            'Dock Layers không được ẩn bằng v-if (mất hiệu ứng bật/tắt).');

        // Bảng Lớp không được tự đặt bề rộng cứng nữa — bề rộng do dock quy định.
        $this->assertStringNotContainsString('w-64', $panel, 'Bảng Layers không được giữ bề rộng cứng w-64.');
        $this->assertStringContainsString('w-full', $panel, 'Bảng Layers phải co giãn theo bề rộng dock.');
    }
}
