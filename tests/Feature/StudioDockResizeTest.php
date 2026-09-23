<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [Yêu cầu 2026-09-20] DOCK CO/GIÃN ĐƯỢC (trái · Outputs) + CƠ SỞ CHUYỂN ĐỘNG DÙNG CHUNG.
 *
 * Bối cảnh: bề rộng dock trước đây là HẰNG SỐ trong class Tailwind (w-72 · w-[156px]) nên
 * người dùng không tự chỉnh được, và việc ẩn/hiện mỗi dock một kiểu (v-if gỡ hẳn khỏi DOM ⇒
 * card bên trong mất trạng thái, không có hiệu ứng).
 *
 * Mỗi bất biến dưới đây khoá một cách hỏng THẬT:
 *  (a) chỉ có MỘT cơ sở dùng chung (composable + vách ngăn) thay vì mỗi dock tự viết;
 *  (b) dock ẩn bằng THU BỀ RỘNG + data-collapsed và VẪN nằm trong DOM (quay lại v-if là mất
 *      cả hiệu ứng lẫn trạng thái bên trong);
 *  (c) .dock-panel phải có min-width:0 — thiếu nó thì flex item không co được xuống 0 và
 *      "co dock" trông như bị đứng (bẫy CSS kinh điển);
 *  (d) thời lượng chuyển động chỉ có MỘT nguồn (token CSS); bảng số dự phòng trong motion.js
 *      phải khớp token, nếu không JS "đo lại sau hiệu ứng" sẽ lệch nhịp với CSS;
 *  (e) người dùng bật "giảm chuyển động" ⇒ MỌI token về 0ms (tắt cả app cùng lúc);
 *  (f) bề rộng được LƯU BỀN: kéo xong tải lại trang vẫn đúng bề rộng đã chọn;
 *  (h) ẩn dock bằng BÀN PHÍM không được bỏ rơi focus (nút mở lại phải nhận focus);
 *  (g) CSS/JS ĐÃ BUILD phải chứa cơ sở này — repo commit thư mục build, nên "nguồn đúng mà
 *      bundle cũ" là lỗi thật đã từng xảy ra.
 */
class StudioDockResizeTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(resource_path($rel));
    }


    public function test_shared_dock_base_handles_pointer_keyboard_and_limits(): void
    {
        $dock = $this->src('js/studio/composables/useDockResize.js');

        // Kéo bằng pointer: phải BẮT pointer (nếu không, kéo lệch khỏi vách ngăn 7px là mất tay),
        // và phải dọn listener ở cả pointerup lẫn pointercancel (huỷ giữa đường).
        foreach ([
            'setPointerCapture' => 'Kéo phải bắt pointer — rời khỏi vách ngăn vẫn phải dính.',
            "addEventListener('pointermove'" => 'Thiếu lắng nghe pointermove khi kéo.',
            "addEventListener('pointerup'" => 'Thiếu lắng nghe pointerup để kết thúc kéo.',
            "addEventListener('pointercancel'" => 'Thiếu pointercancel — huỷ kéo giữa đường sẽ treo trạng thái đang kéo.',
            'is-dock-resizing' => 'Thiếu lớp khoá bôi đen/con trỏ toàn trang khi kéo.',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $dock, $why);
        }

        // Bàn phím: cùng một bộ phím cho MỌI dock (trước đây không dock nào có).
        foreach (["'ArrowLeft'", "'ArrowRight'", "'Home'", "'End'", "'Enter'", "'Escape'"] as $key) {
            $this->assertStringContainsString($key, $dock, "Vách ngăn thiếu phím $key.");
        }

        // Kẹp bề rộng + trần mềm theo bề rộng cửa sổ (dock không được ăn hết chỗ của canvas).
        $this->assertStringContainsString('maxViewportFactor', $dock, 'Thiếu trần mềm theo bề rộng cửa sổ.');
        $this->assertStringContainsString('function clamp(', $dock, 'Thiếu hàm kẹp bề rộng trong [min, max].');

        // Chỉ lưu khi thao tác KẾT THÚC — không ghi localStorage mỗi pixel kéo.
        $this->assertStringContainsString('onCommit', $dock, 'Thiếu móc onCommit để lưu bền sau khi thả tay.');

        // Thời lượng hiệu ứng đọc từ token CSS, không chép lại con số.
        $this->assertStringContainsString("motionDurationMs('dock')", $dock,
            'Composable phải đọc thời lượng từ token CSS (motion.js) thay vì viết cứng ms.');
    }

    public function test_dock_separator_is_keyboard_reachable_and_announced(): void
    {
        $resizer = $this->src('js/studio/components/DockResizer.vue');

        // Vách ngăn là phần tử ARIA chuẩn: người dùng bàn phím/trình đọc màn hình biết nó là gì,
        // đang ở giá trị nào và giới hạn ra sao.
        foreach ([
            'role="separator"',
            'aria-orientation="vertical"',
            'tabindex="0"',
            ':aria-valuenow=',
            ':aria-valuemin=',
            ':aria-valuemax=',
            ':aria-controls=',
        ] as $needle) {
            $this->assertStringContainsString($needle, $resizer, "Vách ngăn thiếu $needle.");
        }

        // Không tự giữ logic: mọi hành vi đến từ bộ điều khiển dùng chung.
        foreach (['dock.onPointerDown', 'dock.onKeydown', 'dock.reset()'] as $needle) {
            $this->assertStringContainsString($needle, $resizer, "Vách ngăn phải gọi $needle từ composable.");
        }
    }


    public function test_every_dock_uses_the_shared_base_instead_of_a_fixed_width(): void
    {
        $app = $this->src('js/studio/StudioApp.vue');

        // BA dock (trái · Outputs · Layers) cùng dùng MỘT cơ sở — thêm dock mới chỉ là khai preset +
        // gắn vách ngăn, không chép lại logic kéo/kẹp/lưu/trả focus.
        $this->assertSame(3, substr_count($app, '<DockResizer'), 'Cả ba dock phải dùng chung <DockResizer>.');
        $this->assertSame(3, substr_count($app, 'useDockResize({'), 'Mỗi dock phải có một bộ điều khiển useDockResize().');
        foreach (['DOCK_PRESETS.left', 'DOCK_PRESETS.outputs', 'DOCK_PRESETS.inspector', 'controls="dock-left"', 'controls="dock-outputs"', 'controls="dock-inspector"'] as $needle) {
            $this->assertStringContainsString($needle, $app, "StudioApp thiếu $needle.");
        }

        // Bề rộng KHÔNG còn là class cứng: mỗi dock lấy bề rộng từ bộ điều khiển (store là nguồn sự thật).
        foreach (['dock-left', 'dock-outputs', 'dock-inspector'] as $id) {
            $this->assertMatchesRegularExpression('/<aside\\s+id="'.$id.'"(.*?)>/s', $app, "Không tìm thấy thẻ mở của dock #$id.");
            preg_match('/<aside\\s+id="'.$id.'"(.*?)>/s', $app, $m);
            $tag = $m[1] ?? '';

            $this->assertStringContainsString('dock-panel', $tag, "Dock #$id phải dùng class chung .dock-panel.");
            $this->assertMatchesRegularExpression('/:style="(leftDock|outputDock|inspectorDock)\\.panelStyle"/', $tag,
                "Dock #$id phải lấy bề rộng động từ bộ điều khiển (kéo được), không phải hằng số.");
            $this->assertDoesNotMatchRegularExpression('/\\bw-(?:\\[|\\d)/', $tag,
                "Dock #$id còn bề rộng CỨNG trong class Tailwind — kéo bao nhiêu cũng bị class ghi đè.");
        }
    }

    public function test_collapsing_dock_animates_instead_of_leaving_the_dom(): void
    {
        $app = $this->src('js/studio/StudioApp.vue');

        // Không quay lại v-if trên chính dock: gỡ khỏi DOM là mất hiệu ứng + mất trạng thái card.
        $this->assertStringNotContainsString('<aside v-if="store.leftPanelOpen"', $app,
            'Dock trái bị gỡ khỏi DOM khi ẩn — mất hiệu ứng co/giãn và mất trạng thái card bên trong.');
        $this->assertStringNotContainsString('<aside v-if="store.outputDockOpen"', $app,
            'Dock Outputs bị gỡ khỏi DOM khi ẩn — mất hiệu ứng co/giãn và mất trạng thái bên trong.');
        $this->assertStringNotContainsString('v-if="store.inspectorOpen" data-covers-canvas', $app,
            'Dock Layers bị gỡ khỏi DOM khi ẩn — mất hiệu ứng bật/tắt và mất trạng thái bên trong.');

        // Trạng thái ẩn đi qua data-collapsed (CSS lo phần hiệu ứng) cho CẢ HAI dock.
        $this->assertSame(3, substr_count($app, ':data-collapsed='), 'Cả ba dock phải báo trạng thái ẩn qua data-collapsed.');
        // Và trạng thái đang kéo (để tắt transition cho con trỏ đi trước).
        $this->assertSame(3, substr_count($app, ':data-resizing='), 'Cả ba dock phải báo trạng thái đang kéo qua data-resizing.');
        // Ẩn khỏi bàn phím: thứ gì thu về 0 mà VẪN CÒN trong cây DOM thì phải chặn Tab vào trong.
        //
        // [2026-09-26 · bước 5.2] Con số này từ 3 lên 5 vì khung làm việc nay có HAI MẶT CHÍNH
        // (lưới kết quả ⇄ bảng ghép) ẩn/hiện bằng v-show — cùng đúng một nguyên tắc với ba dock:
        //   · 3 dock:   trái · Outputs · Layers
        //   · 2 mặt:    lưới kết quả · bảng ghép
        // Mặt đang ẩn phải inert, nếu không Tab sẽ nhảy vào lưới ảnh vô hình (hoặc vào canvas vô hình).
        //
        // [2026-09-26 · đợt 52] 5 → 6: tab "Kết quả" của dock điện thoại. Ở mặt lưới nó TRÙNG với
        // chính mặt lưới (cùng một danh sách, mà lưới còn to hơn và có nút hành động), nên nó ẩn
        // theo mặt — và ẩn bằng v-show + inert như mọi thứ khác ẩn theo mặt.
        $this->assertSame(6, substr_count($app, ':inert='),
            'Thứ gì ẩn bằng v-show (3 dock + 2 mặt chính + tab Kết quả) đều phải inert — nếu không, Tab vẫn nhảy vào nội dung vô hình.');
        // Và hai mặt chính phải khai ĐÚNG dấu hiệu nhận biết để tầng kiểm thử bằng trình duyệt tìm được.
        $this->assertSame(1, substr_count($app, 'data-main-view="grid"'), 'Thiếu mặt chính «lưới kết quả».');
        $this->assertSame(1, substr_count($app, 'data-main-view="canvas"'), 'Thiếu mặt chính «bảng ghép».');
    }


    public function test_hiding_dock_by_keyboard_does_not_strand_focus(): void
    {
        // Sự cố THẬT gặp khi kiểm chứng bằng Chrome: bấm Enter trên vách ngăn để ẩn dock ⇒ vách
        // ngăn bị gỡ khỏi DOM, còn nút chevron nằm trong panel vừa inert ⇒ trình duyệt đẩy focus
        // về <body>. Người dùng bàn phím KHÔNG còn điểm dừng nào để mở lại dock — phải Tab mò.
        $dock = $this->src('js/studio/composables/useDockResize.js');
        foreach ([
            'focusWasInsideDock' => 'Thiếu bước chụp lại focus trước khi dock bị thu gọn.',
            'document.activeElement' => 'Phải đọc focus hiện tại để biết có cần trả focus không.',
            'panel.contains(active)' => 'Phải nhận ra focus đang nằm TRONG panel (nút chevron).',
            'nextTick(' => 'Trả focus phải sau khi DOM cập nhật (panel đã inert).',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $dock, $why);
        }

        // Mỗi dock phải chỉ đích danh nút MỞ LẠI của mình, và nút đó phải tồn tại thật trong template.
        $app = $this->src('js/studio/StudioApp.vue');
        $this->assertSame(3, substr_count($app, 'focusAfterCollapse:'),
            'Cả ba dock phải khai nút mở lại — nếu không, ẩn bằng bàn phím là mất dấu.');
        $this->assertStringContainsString('[data-activity="', $app,
            'Nút activity phải có data-activity để trả focus về đúng mục đang xem.');
        $this->assertStringContainsString(':data-activity="a.id"', $app, 'Nút activity phải mang data-activity.');
        $this->assertStringContainsString('data-dock-toggle="outputs"', $app,
            'Nút Outputs ở rail phải có data-dock-toggle để trả focus về đó.');
    }

    public function test_dock_css_has_the_rules_that_make_resizing_work(): void
    {
        $css = $this->src('css/app.css');

        foreach ([
            '.dock-panel' => 'Thiếu class chung cho dock.',
            'min-width: 0' => 'Thiếu min-width:0 — flex item KHÔNG co được xuống 0, hiệu ứng co sẽ đứng.',
            "data-resizing='true'" => 'Thiếu trạng thái đang kéo (phải tắt transition để dock theo tay tức thì).',
            "data-collapsed='true'" => 'Thiếu trạng thái đã thu gọn.',
            '.dock-resizer' => 'Thiếu class vách ngăn kéo.',
            'col-resize' => 'Vách ngăn phải đổi con trỏ thành col-resize.',
            'touch-action: none' => 'Thiếu touch-action:none — kéo trên tablet sẽ cuộn trang theo.',
            'body.is-dock-resizing' => 'Thiếu lớp khoá bôi đen toàn trang khi kéo.',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $css, $why);
        }

        // Đã thu gọn thì phải rời khỏi bàn phím/trình đọc màn hình, KHÔNG chỉ mờ đi.
        $this->assertMatchesRegularExpression(
            "/\\.dock-panel\\[data-collapsed='true'\\]\\s*\\{[^}]*visibility:\\s*hidden/s",
            $css,
            'Dock đã thu gọn phải visibility:hidden — nếu không, nội dung 0px vẫn nhận Tab.'
        );
    }

    public function test_motion_tokens_have_one_source_and_no_drift_with_js(): void
    {
        $css = $this->src('css/app.css');
        $js = $this->src('js/studio/motion.js');

        // Token thời lượng khai ĐÚNG MỘT LẦN (lần khai đầu tiên) cho mỗi tên.
        preg_match_all('/--motion-dur-([a-z]+):\\s*(\\d+)ms/', $css, $rows, PREG_SET_ORDER);
        $tokens = [];
        foreach ($rows as $row) {
            if (! isset($tokens[$row[1]])) {
                $tokens[$row[1]] = (int) $row[2];
            }
        }
        foreach (['instant', 'fast', 'base', 'slow', 'dock'] as $name) {
            $this->assertArrayHasKey($name, $tokens, "app.css thiếu token --motion-dur-$name.");
            $this->assertGreaterThan(0, $tokens[$name], "Token --motion-dur-$name phải > 0 ở chế độ thường.");
        }
        ksort($tokens);

        // Bảng dự phòng trong JS phải KHỚP token CSS — lệch là JS "đo lại sau hiệu ứng" sai nhịp.
        preg_match('/const FALLBACK_MS = \\{([^}]*)\\}/', $js, $m);
        $this->assertNotEmpty($m[1] ?? '', 'motion.js phải có bảng FALLBACK_MS (lưới an toàn khi không đọc được CSS).');
        preg_match_all('/([a-z]+):\\s*(\\d+)/', $m[1] ?? '', $pairs, PREG_SET_ORDER);
        $fallback = [];
        foreach ($pairs as $pair) {
            $fallback[$pair[1]] = (int) $pair[2];
        }
        ksort($fallback);
        $this->assertSame($tokens, $fallback,
            'Số dự phòng trong motion.js LỆCH token trong app.css — sửa một bên là bên kia phải theo.');

        // JS phải ĐỌC biến CSS, không tự bịa thời lượng.
        $this->assertStringContainsString("getPropertyValue('--motion-dur-'", $js,
            'motion.js phải đọc token CSS đang có hiệu lực — nếu không lại có hai nguồn thời lượng.');
    }

    public function test_reduced_motion_switch_zeroes_every_motion_token(): void
    {
        $css = $this->src('css/app.css');

        // Một công tắc cho cả app: bật "giảm chuyển động" ⇒ mọi token về 0ms.
        preg_match_all('/--motion-dur-([a-z]+):\\s*0ms/', $css, $rows, PREG_SET_ORDER);
        $zeroed = array_map(static fn ($row) => $row[1], $rows);
        sort($zeroed);

        $this->assertSame(['base', 'dock', 'fast', 'instant', 'reveal', 'slow'], $zeroed,
            'Khối prefers-reduced-motion phải đưa MỌI token --motion-dur-* về 0ms.');

        // Hiệu ứng viết bằng animation không tự tắt theo token ⇒ phải tắt tường minh.
        $this->assertMatchesRegularExpression(
            '/@media \\(prefers-reduced-motion: reduce\\)\\s*\\{.*?animation:\\s*none/s',
            $css,
            'Khối prefers-reduced-motion phải tắt các hiệu ứng xuất hiện (animation).'
        );
    }

    public function test_dock_width_is_persisted_across_reload(): void
    {
        $store = static::studioStoreSource();

        // Mặc định = đúng bề rộng cũ, để người dùng đang quen không thấy bố cục nhảy.
        $this->assertStringContainsString('leftDockWidth: 288', $store, 'Mặc định dock trái phải là 288px (w-72 cũ).');
        $this->assertStringContainsString('outputDockWidth: 156', $store, 'Mặc định dock Outputs phải là 156px (bề rộng đang dùng).');

        // Lưu và khôi phục: kéo xong tải lại trang phải giữ đúng bề rộng đã chọn.
        $this->assertMatchesRegularExpression(
            '/saveBarSettings\\(\\)\\s*\\{[^}]*leftDockWidth[^}]*outputDockWidth/s',
            $store,
            'saveBarSettings phải lưu bề rộng dock — nếu không, chỉnh xong là mất khi tải lại.'
        );
        $this->assertMatchesRegularExpression(
            '/restoreBarSettings\\(\\)\\s*\\{[^}]*leftDockWidth[^}]*outputDockWidth/s',
            $store,
            'restoreBarSettings phải khôi phục bề rộng dock đã lưu.'
        );

        // Bề rộng đến từ localStorage KHÔNG được dùng thẳng: composable phải kẹp lại.
        $dock = $this->src('js/studio/composables/useDockResize.js');
        $this->assertMatchesRegularExpression('/width\\.value = clamp\\(/', $dock,
            'Bề rộng khôi phục từ localStorage phải bị kẹp trong [min,max] — dữ liệu cũ/rác không được phá bố cục.');
    }

    public function test_shipped_build_carries_the_foundation(): void
    {
        // Nguồn đúng mà CSS/JS đã build còn CŨ là lỗi thật: shared-hosting deploy bằng thư mục
        // build đã commit, không chạy npm. Guard này bắt đúng trường hợp đó.
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        $this->assertNotNull($cssFile, 'Manifest thiếu entry resources/css/app.css.');

        $css = (string) file_get_contents(public_path('build/'.$cssFile));
        foreach (['.dock-panel', '.dock-resizer', '--motion-dur-dock', 'prefers-reduced-motion'] as $needle) {
            $this->assertStringContainsString($needle, $css,
                "CSS đã build thiếu $needle — nguồn có mà bundle chưa build lại.");
        }

        // Và mã kéo dock phải nằm trong bundle JS đã build.
        $hits = 0;
        foreach ((array) glob(public_path('build/assets/*.js')) as $file) {
            if (str_contains((string) file_get_contents($file), 'Kéo để đổi bề rộng')) {
                $hits++;
            }
        }
        $this->assertGreaterThan(0, $hits,
            'Bundle JS đã build KHÔNG chứa phần kéo dock — chưa chạy npm run build sau khi sửa.');
    }
}
