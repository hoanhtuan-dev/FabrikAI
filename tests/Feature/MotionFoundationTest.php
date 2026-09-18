<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [Yêu cầu 2026-09-20 — đợt 2] ÁP DỤNG CHUYỂN ĐỘNG SÂU RỘNG + TAY CẦM ICON CHO VÁCH NGĂN KÉO.
 *
 * Bối cảnh: đợt trước mới có token + vài class dùng cho dock, nhưng phần LỚN giao diện vẫn tự viết
 * thời lượng riêng (transition rải rác, duration-150/200/300/500, .fade-enter-active 0.2s…), nên
 * nhịp mỗi chỗ một khác và công tắc "giảm chuyển động" chỉ tắt được phần đã dùng token. Vách ngăn
 * kéo thì chỉ là một đường kẻ 1px — người chưa biết không có cách nào đoán ra chỗ kéo được.
 *
 * Mỗi bất biến dưới đây khoá một cách hỏng THẬT:
 *  (a) tiện ích transition* của Tailwind phải nối vào token (--default-transition-duration /
 *      --default-transition-timing-function) — nếu không, ~135 chỗ dùng transition quay về số cứng
 *      150ms của Tailwind và KHÔNG tắt theo công tắc giảm chuyển động;
 *  (b) hết thời lượng viết tay: không còn duration-<số> hay transition: … 0.3s trong mã nguồn;
 *  (c) .motion-ui phải phủ ĐÚNG bộ thuộc tính Tailwind v4 chuyển động được — thiếu translate/scale
 *      thì hiệu ứng "nhấc thẻ khi hover" (hover:-translate-y-1) đứng im mà không báo lỗi gì;
 *  (d) bề mặt đổi hình khối khi hover/focus mà KHÔNG có chuyển động = 0;
 *  (e) tay cầm phải có thật: icon grip, hiện sẵn ở mức mờ, sáng rõ khi hover/focus/đang kéo, và kéo
 *      từ chính tay cầm vẫn chạy;
 *  (f) hiệu ứng chạy vòng lặp (nhấp nháy/pulse) phải bị chặn khi người dùng bật giảm chuyển động;
 *  (g) CSS/JS ĐÃ BUILD phải chứa cả hai thứ trên (repo commit thư mục build).
 */
class MotionFoundationTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /** Mọi file .vue trong resources/js (đệ quy). */
    private function vueFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** Tên các class dùng chung đã có transition sẵn trong app.css (không tính là "thiếu chuyển động"). */
    private function sharedClasses(): array
    {
        preg_match_all('/^\s*\.([a-z][a-z0-9-]*)\s*(?:,[^{]*)?\{/im', $this->css(), $m1);
        preg_match_all('/@utility\s+([a-z0-9-]+)/', $this->css(), $m2);

        return array_unique(array_merge($m1[1], $m2[1]));
    }

    public function test_tailwind_transition_utilities_are_wired_to_the_shared_tokens(): void
    {
        $css = $this->css();

        // (a) Một dòng đổi nhịp cho toàn app: tiện ích transition* của Tailwind đọc hai biến này.
        foreach ([
            '--default-transition-duration: var(--motion-dur-fast)' => 'Tiện ích transition* phải lấy thời lượng từ token, không phải 150ms cứng của Tailwind.',
            '--default-transition-timing-function: var(--motion-ease-standard)' => 'Tiện ích transition* phải lấy đường cong từ token.',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $css, $why);
        }

        // Cả bộ thời lượng thành tiện ích theo TÊN NGHĨA (duration-instant … duration-reveal), vẫn trỏ về token.
        foreach (['instant', 'fast', 'base', 'slow', 'dock', 'reveal'] as $name) {
            $this->assertStringContainsString(
                "--transition-duration-$name: var(--motion-dur-$name)",
                $css,
                "Thiếu tiện ích duration-$name trỏ về token --motion-dur-$name."
            );
        }
    }

    public function test_no_hand_written_transition_durations_are_left(): void
    {
        $offenders = [];

        foreach (array_merge([resource_path('css/app.css')], $this->vueFiles()) as $file) {
            $body = (string) file_get_contents($file);

            // Số ms/s viết thẳng trong transition. Bỏ qua:
            //   · thời lượng 0 (thành ngữ "visibility 0s" để TRỄ việc ẩn, không phải nhịp chuyển động);
            //   · khai báo !important của khối giảm chuyển động (lưới an toàn, cố ý không dùng token).
            preg_match_all('/transition[a-z-]*\s*:([^;{}]+)/', $body, $decls);
            foreach ($decls[1] as $decl) {
                if (str_contains($decl, '!important')) {
                    continue;
                }
                preg_match_all('/(?<![\w-])(\d+(?:\.\d+)?)(m?s)(?![\w-])/', $decl, $nums, PREG_SET_ORDER);
                foreach ($nums as $num) {
                    if ((float) $num[1] > 0) {
                        $offenders[] = basename($file).': transition…'.trim($num[0]);
                    }
                }
            }

            // Tiện ích thời lượng dạng số (duration-150 · duration-500) — phải dùng duration-<tên>.
            preg_match_all('/duration-\d+/', $body, $m2);
            foreach (array_unique($m2[0]) as $hit) {
                $offenders[] = basename($file).': '.$hit;
            }
        }

        $this->assertSame([], $offenders,
            "Còn thời lượng chuyển động viết tay — phải dùng token (duration-<tên> hoặc var(--motion-dur-*)):\n"
            .implode("\n", $offenders));
    }

    public function test_motion_ui_covers_every_property_tailwind_can_transition(): void
    {
        $css = $this->css();

        preg_match('/\.motion-ui\s*\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Thiếu class .motion-ui.');
        // Bỏ chú thích trước khi soi: chữ trong chú thích không phải khai báo thật.
        $block = (string) preg_replace('!/\*.*?\*/!s', '', $m[1] ?? '');

        // Tailwind v4 chuyển động bằng thuộc tính RIÊNG translate/scale/rotate — thiếu chúng thì
        // các hiệu ứng nhấc/nở trên giao diện đứng im mà không có lỗi nào.
        foreach (['translate', 'scale', 'rotate', 'box-shadow', 'opacity', 'backdrop-filter'] as $prop) {
            $this->assertMatchesRegularExpression(
                '/(?:^|[\s,;])'.preg_quote($prop, '/').'(?:[\s,;]|$)/',
                $block,
                ".motion-ui thiếu thuộc tính $prop — hiệu ứng dùng nó sẽ đứng im."
            );
        }

        // Mặc định KHÔNG chuyển động kích thước (gây giật bố cục) — chỉ khi nói rõ bằng --size.
        $this->assertStringNotContainsString('width', $block,
            '.motion-ui không được chuyển động width mặc định — dễ gây giật bố cục.');
        $this->assertMatchesRegularExpression('/\.motion-ui--size\s*\{[^}]*width/s',
            $css, 'Thiếu .motion-ui--size cho chỗ CẦN chuyển động kích thước (thanh tiến trình…).');
    }

    public function test_every_hover_surface_animates(): void
    {
        $shared = $this->sharedClasses();
        $offenders = [];

        foreach ($this->vueFiles() as $file) {
            $body = (string) file_get_contents($file);

            // <tag ... class="..."> — bỏ qua :class (class ĐỘNG, không đọc được tĩnh).
            preg_match_all('/<([a-zA-Z][\w-]*)([^>]*?)class="([^"]*)"/', $body, $sets, PREG_SET_ORDER);
            foreach ($sets as $set) {
                [, $tag, $before, $cls] = $set;
                if (str_ends_with($before, ':')) {
                    continue;   // :class="..." → biểu thức, không phải class tĩnh
                }
                if (preg_match('/transition|motion-ui|motion-row/', $cls)) {
                    continue;
                }
                if (! preg_match('/hover:|focus:|active:/', $cls)) {
                    continue;
                }

                // Thuộc tính hình khối bị đổi (bỏ gạch chân/con trỏ — không phải chuyển động khối).
                preg_match_all('/(?:hover|focus|active):(?!underline|cursor)[a-z0-9\/-]+/', $cls, $props);
                if (! $props[0]) {
                    continue;
                }

                // Class dùng chung (btn · icon-btn · tool-btn …) đã có transition trong app.css.
                $hasShared = false;
                foreach (preg_split('/\s+/', trim($cls)) ?: [] as $token) {
                    $bare = ltrim((string) preg_replace('/^.*:/', '', $token), '!');
                    if (in_array($bare, $shared, true)) {
                        $hasShared = true;
                        break;
                    }
                }
                if ($hasShared) {
                    continue;
                }

                // a/button/input/select/textarea đã có quy tắc chuyển động ở tầng base cho
                // màu nền/viền/chữ/độ mờ/vòng focus — chỉ những thuộc tính NGOÀI nhóm đó mới hở.
                $baseCovered = in_array(strtolower($tag), ['a', 'button', 'input', 'select', 'textarea'], true)
                    && ! array_filter($props[0], static fn ($p) => ! preg_match('/^(?:hover|focus|active):(?:bg|border|text|opacity|ring|outline)-/', $p));
                if ($baseCovered) {
                    continue;
                }

                $offenders[] = basename($file).': '.implode(' ', array_unique($props[0]));
            }
        }

        $this->assertSame([], $offenders,
            "Có bề mặt đổi hình khối khi hover/focus mà KHÔNG có chuyển động (thêm .motion-ui hoặc .motion-row):\n"
            .implode("\n", $offenders));
    }

    public function test_dock_handle_is_visible_and_announced(): void
    {
        $component = (string) file_get_contents(resource_path('js/studio/components/DockResizer.vue'));
        $css = $this->css();

        // Tay cầm có thật, có icon grip, và là hình TRANG TRÍ (không đọc lên — tên/giá trị ở cha).
        $this->assertStringContainsString('dock-resizer__knob', $component, 'Vách ngăn thiếu tay cầm.');
        $this->assertStringContainsString('gripVertical', $component,
            'Tay cầm phải có icon grip để người dùng nhận ra chỗ kéo.');
        $this->assertMatchesRegularExpression('/class="dock-resizer__knob"\s+aria-hidden="true"/s', $component,
            'Tay cầm phải aria-hidden (chỉ là hình) — nếu không, trình đọc màn hình đọc thừa một mục.');

        // Tay cầm phải HIN SẴN ở mức mờ: chỉ hiện khi hover thì người mới không bao giờ thấy nó.
        preg_match('/\.dock-resizer__knob\s*\{([^}]*)\}/', $css, $knob);
        $this->assertNotEmpty($knob[1] ?? '', 'Thiếu CSS cho tay cầm.');
        $this->assertMatchesRegularExpression('/opacity:\s*0?\.[0-9]+/', $knob[1],
            'Tay cầm phải hiện sẵn ở mức mờ (không ẩn hẳn) để người dùng nhận biết chỗ kéo.');
        $this->assertMatchesRegularExpression('/transition:[^;]*var\(--motion-dur-/s', $knob[1],
            'Tay cầm phải chuyển động theo token như phần còn lại của app.');

        // Sáng rõ khi trỏ vào · focus bàn phím · đang kéo.
        foreach ([
            '\.dock-resizer:hover \.dock-resizer__knob',
            '\.dock-resizer:focus-visible \.dock-resizer__knob',
            "data-resizing='true'\] \.dock-resizer__knob",
        ] as $sel) {
            $this->assertMatchesRegularExpression('/'.$sel.'/', $css,
                'Tay cầm phải nổi rõ khi hover / focus bàn phím / đang kéo.');
        }

        // Kéo từ CHÍNH tay cầm vẫn phải chạy: pointerdown bắt ở vách ngăn (e.currentTarget).
        $this->assertStringContainsString('e.currentTarget',
            (string) file_get_contents(resource_path('js/studio/composables/useDockResize.js')),
            'Kéo phải bắt pointer ở vách ngăn (e.currentTarget) — kéo từ tay cầm cũng phải chạy.');
    }

    public function test_reduced_motion_also_tames_looping_animations(): void
    {
        $css = $this->css();

        // Hiệu ứng vòng lặp không dùng token ⇒ phải chặn tường minh bằng công tắc chung.
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{(?:(?!\n\}).)*?animation-duration:\s*1ms\s*!important/s',
            $css,
            'Khối giảm chuyển động phải chặn hiệu ứng chạy vòng lặp (nhấp nháy/pulse/quay).'
        );
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{(?:(?!\n\}).)*?animation-iteration-count:\s*1\s*!important/s',
            $css,
            'Vòng lặp phải dừng sau MỘT vòng — nếu không, hiệu ứng vẫn nhấp nháy mãi.'
        );
    }

    public function test_shipped_build_carries_the_deep_motion_foundation(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('public_html/build/manifest.json')), true);
        $css = (string) file_get_contents(base_path('public_html/build/'.$manifest['resources/css/app.css']['file']));
        $js = (string) file_get_contents(base_path('public_html/build/'.$manifest['resources/js/studio/main.js']['file']));

        foreach ([
            '--default-transition-duration:var(--motion-dur-fast)',
            '--transition-duration-base:var(--motion-dur-base)',
            '.dock-resizer__knob',
        ] as $needle) {
            $this->assertStringContainsString($needle, $css, "CSS đã build thiếu $needle — chạy lại npm run build.");
        }
        $this->assertStringContainsString('gripVertical', $js,
            'JS đã build thiếu tay cầm grip — chạy lại npm run build.');
    }
}
