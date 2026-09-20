<?php

namespace App\Support;

/**
 * TOÁN MÀU CHO HỆ THỐNG THEME (2026-09-25 · docs/DESIGN_SYSTEM.md §1.1).
 *
 * Vì sao cần một lớp riêng thay vì so chuỗi hex: từ nay bảng màu KHÔNG còn được viết tay trong
 * app.css mà được SINH ra từ một theme daisyUI (xem App\Support\ThemeRamp). Sinh màu nghĩa là
 * phải: đổi hệ màu (oklch ↔ hex), trộn hai màu, và QUAN TRỌNG NHẤT — hạ/tăng sắc độ cho tới khi
 * đạt ngưỡng tương phản WCAG AA. Ba việc đó phải làm bằng SỐ, không bằng mắt.
 *
 * Vì sao dùng OKLab (không phải HSL/HSV): trộn hai màu trong HSL đi qua vùng xám và lệch hue
 * (trộn xanh lá với trắng trong HSL ra màu bùn). OKLab là không gian cảm nhận, trộn ở đó giữ
 * đúng hue — và đó cũng là không gian daisyUI dùng (oklch) nên giá trị đọc vào/ghi ra khớp nhau.
 *
 * Mọi hàm ở đây là HÀM THUẦN: không đọc file, không DB, không cache ⇒ test được từng hàm một.
 */
final class ThemeColor
{
    /** Ngưỡng WCAG AA cho chữ thường — dùng chung với ThemePalette::AA. */
    public const AA = 4.5;

    /** Số bước của phép tìm kiếm nhị phân khi cần chỉnh sắc độ cho đạt AA. */
    private const SEARCH_STEPS = 20;

    /** Hệ số chroma tối đa nhận vào (oklch): ngoài dải này là giá trị rác, không phải màu. */
    private const MAX_CHROMA = 0.5;

    // ─────────────────────────────────────────────────────────────────────────
    // ĐỌC VÀO — allowlist, không phải blocklist
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Đọc một giá trị màu thành oklch [l, c, h]. Trả NULL nếu không thuộc allowlist.
     *
     * Vì sao allowlist: giá trị này đi thẳng vào một thẻ <style> trong <head> của MỌI trang. Một
     * blocklist (chặn "}", chặn "url(") luôn thiếu một biến thể nào đó; allowlist thì ngược lại —
     * cái gì không khớp đúng một trong bốn dạng dưới đây đều bị từ chối:
     *   #rgb · #rrggbb · oklch(L C H) · rgb(r g b) · hsl(h s% l%)
     * KHÔNG nhận alpha: mọi token ở đây là màu ĐẶC, và độ mờ chính là thứ vừa bị gỡ khỏi tầng chữ
     * (xem ThemeSystemTest::test_text_no_longer_uses_opacity_for_hierarchy).
     *
     * @return array{l:float,c:float,h:float}|null
     */
    public static function parse(string $raw): ?array
    {
        $v = strtolower(trim($raw));
        if ($v === '') {
            return null;
        }

        if ($v[0] === '#') {
            $hex = self::normalizeHex($v);

            return $hex === null ? null : self::hexToOklch($hex);
        }

        // oklch(58% 0.233 277.117) — dạng daisyUI phát ra; cho phép cả L dạng 0..1.
        if (preg_match('/^oklch\(\s*([0-9]*\.?[0-9]+)(%?)\s+([0-9]*\.?[0-9]+)\s+([0-9]*\.?[0-9]+)(?:deg)?\s*\)$/', $v, $m)) {
            $l = (float) $m[1];
            if ($m[2] === '%' || $l > 1.0) {
                $l /= 100.0;
            }
            $c = (float) $m[3];
            $h = (float) $m[4];

            return self::validOklch($l, $c, $h);
        }

        // rgb(255 98 125) hoặc rgb(255, 98, 125)
        if (preg_match('/^rgba?\(\s*([0-9]{1,3})\s*[ ,]\s*([0-9]{1,3})\s*[ ,]\s*([0-9]{1,3})\s*\)$/', $v, $m)) {
            foreach ([1, 2, 3] as $i) {
                if ((int) $m[$i] > 255) {
                    return null;
                }
            }
            $hex = sprintf('#%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);

            return self::hexToOklch($hex);
        }

        // hsl(200 50% 40%) hoặc hsl(200, 50%, 40%)
        if (preg_match('/^hsla?\(\s*([0-9]{1,3})\s*[ ,]\s*([0-9]*\.?[0-9]+)%\s*[ ,]\s*([0-9]*\.?[0-9]+)%\s*\)$/', $v, $m)) {
            if ((int) $m[1] > 360) {
                return null;
            }
            $hex = self::hslToHex((float) $m[1], (float) $m[2] / 100, (float) $m[3] / 100);

            return self::hexToOklch($hex);
        }

        return null;
    }

    /**
     * Chuẩn hoá một giá trị màu về #rrggbb. NULL nếu không đọc được.
     */
    public static function toHex(string $raw): ?string
    {
        $oklch = self::parse($raw);

        return $oklch === null ? null : self::oklchToHex($oklch);
    }

    /**
     * Cổng an toàn CUỐI CÙNG trước khi một chuỗi được ghép vào CSS.
     *
     * Không thay thế validate ở đầu vào (DaisyTheme) — đây là chốt chặn thứ hai: dù ai đó sau này
     * thêm một đường sinh token mới mà quên validate, giá trị lạ vẫn không thể đi vào <style>.
     */
    public static function isLiteral(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/', strtolower($value)) === 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHUYỂN HỆ MÀU
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{l:float,c:float,h:float} */
    public static function hexToOklch(string $hex): array
    {
        [$L, $a, $b] = self::hexToOklab($hex);
        $c = sqrt($a * $a + $b * $b);
        $h = $c < 1e-9 ? 0.0 : fmod(rad2deg(atan2($b, $a)) + 360.0, 360.0);

        return ['l' => $L, 'c' => $c, 'h' => $h];
    }

    /** @param array{l:float,c:float,h:float} $oklch */
    public static function oklchToHex(array $oklch): string
    {
        $rad = deg2rad($oklch['h']);

        return self::oklabToHex($oklch['l'], $oklch['c'] * cos($rad), $oklch['c'] * sin($rad));
    }

    /**
     * Trộn hai màu trong OKLab. $t = 0 ⇒ $a, $t = 1 ⇒ $b.
     *
     * Đây là phép toán dựng nên TOÀN BỘ các bậc còn lại của bảng màu (viền, bậc chữ phụ, dải
     * brand-*) từ đúng hai màu gốc của theme — nên nó phải giữ hue, không được đi qua vùng xám.
     */
    public static function mix(string $a, string $b, float $t): string
    {
        $t = max(0.0, min(1.0, $t));
        [$la, $aa, $ba] = self::hexToOklab($a);
        [$lb, $ab, $bb] = self::hexToOklab($b);

        return self::oklabToHex(
            $la + ($lb - $la) * $t,
            $aa + ($ab - $aa) * $t,
            $ba + ($bb - $ba) * $t,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TƯƠNG PHẢN — WCAG 2.1 (độ chói tương đối), cùng công thức với ThemePalette
    // ─────────────────────────────────────────────────────────────────────────

    public static function luminance(string $hex): float
    {
        $hex = ltrim(strtolower($hex), '#');
        $channels = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Tương phản THẤP NHẤT của một màu trên một tập nền — con số duy nhất đáng quan tâm khi màu
     * đó là CHỮ (chữ phải đọc được trên mọi bề mặt, không phải chỉ trên bề mặt đẹp nhất).
     *
     * @param list<string> $backgrounds
     */
    public static function worstContrast(string $color, array $backgrounds): float
    {
        $min = INF;
        foreach ($backgrounds as $bg) {
            $min = min($min, self::contrast($color, $bg));
        }

        return $backgrounds === [] ? 0.0 : $min;
    }

    /**
     * Dịch $color về phía $toward VỪA ĐỦ để đạt $min tương phản trên MỌI nền trong $backgrounds.
     *
     * Vì sao tìm kiếm nhị phân chứ không "làm tối 20%": quan hệ giữa độ sáng OKLab và độ chói WCAG
     * không tuyến tính, nên một phép chỉnh cố định hoặc thiếu (vẫn dưới AA) hoặc thừa (màu mất
     * hẳn cá tính của theme). Tìm kiếm cho ra màu GẦN NHẤT với màu gốc còn đạt ngưỡng.
     *
     * @param list<string> $backgrounds
     */
    public static function shadeUntil(string $color, string $toward, array $backgrounds, float $min = self::AA): string
    {
        if (self::worstContrast($color, $backgrounds) >= $min) {
            return $color;
        }

        $best = self::mix($color, $toward, 1.0);
        $low = 0.0;
        $high = 1.0;

        for ($i = 0; $i < self::SEARCH_STEPS; $i++) {
            $mid = ($low + $high) / 2;
            if (self::worstContrast(self::mix($color, $toward, $mid), $backgrounds) >= $min) {
                $best = self::mix($color, $toward, $mid);
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        return $best;
    }

    /**
     * Pha $color về phía $toward XA NHẤT mà vẫn còn đạt $target tương phản trên mọi nền.
     *
     * Đây là phép toán ngược với shadeUntil, và là định nghĩa của "bậc chữ phụ": bậc 200/300/400
     * không phải ba màu tuỳ ý — chúng là ba mức NHẠT NHẤT còn đọc được, lần lượt ứng với ba tỉ lệ
     * tương phản mục tiêu. Nếu chỉ hạ độ sáng theo cảm nhận thì bậc mờ nhất sẽ rơi xuống dưới AA
     * (đúng lỗi "chữ mờ khó đọc" mà ThemeSystemTest khoá lại).
     *
     * @param list<string> $backgrounds
     */
    public static function blendToRatio(string $color, string $toward, array $backgrounds, float $target): string
    {
        $best = $color;
        $low = 0.0;
        $high = 1.0;

        for ($i = 0; $i < self::SEARCH_STEPS; $i++) {
            $mid = ($low + $high) / 2;
            if (self::worstContrast(self::mix($color, $toward, $mid), $backgrounds) >= $target) {
                $best = self::mix($color, $toward, $mid);
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return $best;
    }

    /**
     * Chọn màu CHỮ đặt trên một nền màu đậm (nút, badge).
     *
     * Thứ tự ưu tiên: màu chữ do theme khai (giữ đúng ý tác giả theme) → trắng → đen. Trả về màu
     * ĐẠT ngưỡng, không bao giờ trả về màu chữ không đọc được.
     *
     * Vì sao luôn có đáp án: hai đường cong tương phản của trắng và đen cắt nhau ở độ chói 0,179 và
     * tại điểm cắt cả hai đều đạt 4,58:1 — cao hơn ngưỡng AA. Nên nhánh cuối chỉ là chốt chặn cho
     * một giá trị không đọc được, không phải đường đi bình thường.
     */
    public static function contentFor(string $background, ?string $preferred = null): string
    {
        foreach (array_filter([$preferred !== null ? self::toHex($preferred) : null, '#ffffff', '#000000']) as $candidate) {
            if (self::contrast($candidate, $background) >= self::AA) {
                return strtolower($candidate);
            }
        }

        return self::luminance($background) > 0.179 ? '#000000' : '#ffffff';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NỘI BỘ
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{l:float,c:float,h:float}|null */
    private static function validOklch(float $l, float $c, float $h): ?array
    {
        if ($l < 0.0 || $l > 1.0 || $c < 0.0 || $c > self::MAX_CHROMA || $h < 0.0 || $h > 360.0) {
            return null;
        }

        return ['l' => $l, 'c' => $c, 'h' => $h];
    }

    private static function normalizeHex(string $hex): ?string
    {
        if (preg_match('/^#([0-9a-f]{3})$/', $hex, $m)) {
            return '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $hex) === 1 ? $hex : null;
    }

    /** @return array{0:float,1:float,2:float} L, a, b */
    private static function hexToOklab(string $hex): array
    {
        $hex = ltrim(strtolower($hex), '#');
        $lin = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $lin[] = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        [$r, $g, $b] = $lin;

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

        $l = $l < 0 ? -((-$l) ** (1 / 3)) : $l ** (1 / 3);
        $m = $m < 0 ? -((-$m) ** (1 / 3)) : $m ** (1 / 3);
        $s = $s < 0 ? -((-$s) ** (1 / 3)) : $s ** (1 / 3);

        return [
            0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
            1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
            0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
        ];
    }

    private static function oklabToHex(float $L, float $a, float $b): string
    {
        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $channels = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];

        $out = '#';
        foreach ($channels as $channel) {
            // Kẹp vào [0,1] rồi mã hoá gamma: màu ngoài dải sRGB (daisyUI có vài màu như vậy) được
            // đưa về màu gần nhất HIỂN THỊ ĐƯỢC, thay vì trả về NaN hay một giá trị CSS không hợp lệ.
            $v = max(0.0, min(1.0, $channel));
            $v = $v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055;
            $out .= str_pad(dechex((int) round(max(0.0, min(1.0, $v)) * 255)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return sprintf('#%02x%02x%02x',
            (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}