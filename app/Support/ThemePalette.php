<?php

namespace App\Support;

/**
 * BẢNG TOKEN CỦA HAI THEME — MỘT nguồn cho cả TEST lẫn TRANG XEM TOKEN (2026-09-23).
 *
 * Vì sao có lớp này: nợ đã ghi trong DEPLOY_LOG — "số liệu tương phản chỉ nằm trong test", người
 * thiết kế không có chỗ nào xem. Nếu trang xem token tự đọc app.css theo cách riêng thì lại thành
 * HAI nguồn: sửa token ở CSS, test đọc một kiểu, trang đọc một kiểu khác ⇒ trang nói dối.
 * Nay cả hai cùng gọi lớp này, và cả hai cùng đọc TRỰC TIẾP resources/css/app.css:
 *   · theme TỐI  = khối @theme
 *   · theme SÁNG = khối [data-theme='light'] (ghi đè ngoài layer)
 *
 * Công thức tương phản theo WCAG 2.1 (độ chói tương đối), không phải cảm nhận.
 */
class ThemePalette
{
    /** Bề mặt, xếp từ nền trang tới nền nổi. */
    public const SURFACES = ['ink-950', 'ink-900', 'ink-800', 'ink-700'];

    /** Bốn bậc nội dung, từ chữ chính tới ghi chú phụ. */
    public const CONTENT = ['cream-100', 'cream-200', 'cream-300', 'cream-400'];

    /** Màu nhấn + màu trạng thái (đều là CHỮ nên cũng phải đạt AA). */
    public const ACCENTS = ['brand-200', 'brand-300', 'danger', 'warn', 'ok', 'info'];

    /** Token CỐ ĐỊNH: không đổi theo theme (nền canvas, lớp phủ trên ảnh, khối đảo màu). */
    public const FIXED = ['canvas-dark', 'canvas-white', 'canvas-cream', 'scrim', 'scrim-content', 'invert', 'invert-content', 'invert-hover', 'on-accent'];

    private static ?string $css = null;

    private static function css(): string
    {
        return self::$css ??= (string) file_get_contents(resource_path('css/app.css'));
    }

    /** Xoá cache khi cần (test gọi sau khi sửa file trong cùng tiến trình). */
    public static function flush(): void
    {
        self::$css = null;
    }

    /**
     * Khai báo THÔ của một khối: mã hex HOẶC một bí danh `var(--color-x)`.
     *
     * [2026-09-23] Vì sao phải hỗ trợ bí danh: lớp ngữ nghĩa nay theo đúng bộ tên của daisyUI
     * (base-100 · base-content · primary · error …) và các tên cũ của app (danger · ok · warn …)
     * trỏ VỀ nó bằng `var(…)` — một nguồn giá trị, nhiều tên gọi. Nếu lớp này chỉ đọc hex thì
     * bảng token sẽ bỏ sót đúng những token quan trọng nhất.
     *
     * @return array<string,string>
     */
    public static function raw(string $theme): array
    {
        $css = self::css();

        if ($theme === 'light') {
            preg_match("/\[data-theme='light'\]\s*\{(.*?)\n\}/s", $css, $m);
            $block = $m[1] ?? '';
        } else {
            preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $m);
            $block = $m[1] ?? '';
        }

        preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9a-fA-F]{6}|var\(--color-[a-z0-9-]+\))\s*;/', $block, $rows, PREG_SET_ORDER);

        $out = [];
        foreach ($rows as $row) {
            $out[$row[1]] = $row[2];
        }

        return $out;
    }

    /**
     * Bảng token của một theme ĐÃ GIẢI BÍ DANH: mọi giá trị trả về đều là mã hex.
     *
     * Giải theo chuỗi (tối đa 6 vòng): hôm nay chuỗi dài nhất là 1 bước (danger → error), nhưng để
     * vòng lặp thì sau này thêm một tầng bí danh nữa cũng không phải sửa hàm này.
     *
     * @return array<string,string>
     */
    public static function tokens(string $theme): array
    {
        $map = self::resolvedRaw($theme);

        for ($pass = 0; $pass < 6; $pass++) {
            $changed = false;
            foreach ($map as $name => $value) {
                if (preg_match('/^var\(--color-([a-z0-9-]+)\)$/', $value, $m) && isset($map[$m[1]])) {
                    $map[$name] = $map[$m[1]];
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        // Bỏ những token vẫn còn là bí danh chưa giải được (không đo được tương phản từ chúng).
        return array_filter($map, fn (string $v) => str_starts_with($v, '#'));
    }

    /** Bảng thô đã trộn ghi đè của theme (chưa giải bí danh). @return array<string,string> */
    private static function resolvedRaw(string $theme): array
    {
        return $theme === 'light'
            ? array_merge(self::raw('dark'), self::raw('light'))
            : self::raw('dark');
    }

    /**
     * Bảng token ĐẦY ĐỦ của một theme: giá trị riêng của theme đó, phần còn lại lấy từ theme TỐI
     * (vì theme sáng chỉ ghi đè những token đổi theo theme — đó chính là điều làm nên "một bảng token").
     *
     * @return array<string,string>
     */
    public static function resolved(string $theme): array
    {
        return self::tokens($theme);
    }

    /** Độ chói tương đối theo WCAG 2.1. */
    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** Tỉ lệ tương phản giữa hai màu (1..21). */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Ngưỡng WCAG AA cho chữ thường. */
    public const AA = 4.5;

    /**
     * Ma trận tương phản "bậc nội dung × bề mặt" của một theme — dùng cho trang xem token.
     *
     * @return array{theme:string, tokens:array<string,string>, rows:list<array{token:string,hex:string,ratios:array<string,float>,min:float,pass:bool}>}
     */
    public static function matrix(string $theme): array
    {
        $tokens = self::resolved($theme);
        $rows = [];

        foreach (self::CONTENT as $level) {
            $ratios = [];
            foreach (self::SURFACES as $surface) {
                $ratios[$surface] = self::contrast($tokens[$level], $tokens[$surface]);
            }
            $min = min($ratios);
            $rows[] = [
                'token' => $level,
                'hex' => $tokens[$level],
                'ratios' => $ratios,
                'min' => $min,
                'pass' => $min >= self::AA,
            ];
        }

        return ['theme' => $theme, 'tokens' => $tokens, 'rows' => $rows];
    }

    /**
     * Tương phản của màu nhấn/trạng thái trên từng bề mặt (chúng cũng là CHỮ).
     *
     * @return list<array{token:string,hex:string,min:float,pass:bool}>
     */
    public static function accents(string $theme): array
    {
        $tokens = self::resolved($theme);
        $out = [];

        foreach (self::ACCENTS as $token) {
            if (! isset($tokens[$token])) {
                continue;
            }
            $min = min(array_map(
                fn (string $surface) => self::contrast($tokens[$token], $tokens[$surface]),
                self::SURFACES
            ));
            $out[] = ['token' => $token, 'hex' => $tokens[$token], 'min' => $min, 'pass' => $min >= self::AA];
        }

        return $out;
    }
}
