<?php

namespace App\Support;

use RuntimeException;

/**
 * SINH BẢNG TOKEN CỦA APP TỪ MỘT THEME daisyUI (2026-09-25 · docs/DESIGN_SYSTEM.md §1.1).
 *
 * Đây là chỗ nối hai thế giới:
 *   · daisyUI có 20 token (base-100 · primary · error …) — đủ để dựng component, KHÔNG đủ để làm
 *     giao diện: nó chỉ có MỘT bậc chữ (base-content) và MỘT bậc viền.
 *   · FabrikAI cần bốn bậc chữ, bốn bề mặt, viền ba mức, và một dải brand 11 bậc.
 * Lớp này sinh phần còn thiếu TỪ những gì theme có, với hai bất biến được ép bằng số:
 *
 *   (1) MỌI BẬC CHỮ ĐẠT WCAG AA (≥ 4,5:1) TRÊN MỌI BỀ MẶT. Bậc 400 được hạ xuống đúng ngưỡng
 *       4,7:1 (đủ đạt, còn dư một chút), bậc 300 ~6:1, bậc 200 ~8,5:1 — và nếu theme gốc có
 *       base-content quá nhạt thì nó được KÉO VỀ một cực trước khi phân bậc, chứ không sinh ra một
 *       bảng màu đẹp nhưng không đọc được.
 *   (2) MỌI GIÁ TRỊ PHÁT RA CSS ĐỀU LÀ #rrggbb ĐÃ KIỂM. Theme đến từ bên ngoài; không có giá trị
 *       nào đi thẳng vào <style> mà chưa qua ThemeColor::isLiteral.
 *
 * Hàm thuần: cùng một theme luôn cho cùng một bảng token (không đọc DB, không đọc thời gian) —
 * nhờ vậy app.css (sinh ra ở đây, đã commit) và CSS phát lúc chạy không thể lệch nhau.
 */
final class ThemeRamp
{
    /** Tám màu VAI TRÒ của daisyUI (mỗi màu có thêm một màu chữ "-content"). */
    public const ROLE_KEYS = [
        '--color-primary', '--color-secondary', '--color-accent', '--color-neutral',
        '--color-info', '--color-success', '--color-warning', '--color-error',
    ];

    /**
     * Bốn màu vừa là NỀN vừa là CHỮ, nên phải đạt AA trên mọi bề mặt.
     *
     * Vì sao chỉ bốn màu này: bốn màu trạng thái được dùng làm chữ khắp giao diện (text-danger ·
     * text-ok · text-info — 20+ chỗ, và ThemeSystemTest khoá lại). Còn primary/secondary/accent/
     * neutral là màu NHẬN DIỆN/NỀN: chúng được lấy ĐÚNG giá trị của theme, không bị hạ sắc độ —
     * đó chính là nghĩa của "đồng bộ theo theme" (nếu tự ý chỉnh, theme import về sẽ không giống
     * thứ người dùng nhìn thấy trên trang Theme Generator).
     */
    public const TEXT_ROLE_KEYS = [
        '--color-info', '--color-success', '--color-warning', '--color-error',
    ];

    /** Tỉ lệ tương phản MỤC TIÊU cho ba bậc chữ phụ — bậc 400 nằm ngay trên ngưỡng AA. */
    private const CONTENT_RATIOS = [
        '--color-cream-200' => 8.5,
        '--color-cream-300' => 6.0,
        '--color-cream-400' => 4.7,
    ];

    /** Độ pha của viền (ink-700 · ink-600 · ink-500) so với nền card. */
    private const BORDER_MIX = [
        '--color-ink-700' => 0.07,
        '--color-ink-600' => 0.18,
        '--color-ink-500' => 0.35,
    ];

    /** Cột mốc của dải brand 11 bậc: pha về phía CỰC (chữ/nhấn) rồi về phía NỀN (nền tint). */
    private const BRAND_POLE_MIX = [
        '--color-brand-100' => 0.78,
        '--color-brand-200' => 0.62,
        '--color-brand-300' => 0.42,
        '--color-brand-400' => 0.28,
        '--color-brand-500' => 0.14,
    ];

    /**
     * Ba bậc brand được dùng làm CHỮ (text-brand-100/200/300) ⇒ phải đạt AA trên mọi bề mặt.
     *
     * Đếm thật trong mã nguồn: text-brand-100 (10 chỗ) · text-brand-200 (113) · text-brand-300 (223).
     * Các bậc còn lại chỉ là NỀN/VIỀN (bg-brand-900 · border-brand-400 · bg-brand-600) nên giữ
     * nguyên sắc độ đã pha — hạ chúng xuống sẽ làm hỏng chính vai trò nền của chúng.
     */
    private const BRAND_TEXT_KEYS = ['--color-brand-100', '--color-brand-200', '--color-brand-300'];

    private const BRAND_SURFACE_MIX = [
        '--color-brand-700' => 0.45,
        '--color-brand-800' => 0.62,
        '--color-brand-900' => 0.78,
        '--color-brand-950' => 0.90,
    ];

    /** Cực "xa nền" của mỗi chế độ: chữ/nhấn ở chế độ tối đi về trắng, ở chế độ sáng đi về đen. */
    public static function pole(string $scheme): string
    {
        return $scheme === 'light' ? '#000000' : '#ffffff';
    }

    /**
     * Bốn BỀ MẶT của app (nền trang → nền nổi) suy từ ba bậc base của theme.
     *
     * Đây cũng là danh sách nền dùng cho MỌI phép kiểm tương phản: chữ phải đọc được trên cả bốn,
     * nên bậc chữ mờ nhất phải được tính theo bề mặt SÁNG NHẤT (nơi tương phản thấp nhất).
     *
     * @param array<string,string> $base  --color-base-100/200/300 + --color-base-content (hex)
     * @return list<string> [ink-950, ink-900, ink-800, ink-700]
     */
    public static function surfaces(array $base, string $scheme): array
    {
        $card = $base['--color-base-100'] ?? '#000000';
        $content = $base['--color-base-content'] ?? '#ffffff';
        $mix = ThemeRamp::BORDER_MIX['--color-ink-700'];

        return [
            $base['--color-base-300'] ?? $card,
            $base['--color-base-200'] ?? $card,
            $card,
            ThemeColor::mix($card, $content, $mix),
        ];
    }

    /**
     * Bảng token MÀU của một theme, theo đúng thứ tự sẽ được ghi ra CSS.
     *
     * @return array<string,string>
     */
    public static function tokens(DaisyTheme $theme): array
    {
        $scheme = $theme->scheme();
        $pole = self::pole($scheme);

        $card = self::hex($theme, '--color-base-100');
        $panel = self::hex($theme, '--color-base-200');
        $page = self::hex($theme, '--color-base-300');
        $base = ['--color-base-100' => $card, '--color-base-200' => $panel, '--color-base-300' => $page];
        $surface = $base['--color-base-content'] = self::hex($theme, '--color-base-content');

        $surfaces = self::surfaces($base, $scheme);

        $out = [];

        // ── BỀ MẶT ────────────────────────────────────────────────────────────────────────────
        $out['--color-ink-950'] = $page;
        $out['--color-ink-900'] = $panel;
        $out['--color-ink-800'] = $card;
        foreach (self::BORDER_MIX as $key => $t) {
            $out[$key] = ThemeColor::mix($card, $surface, $t);
        }

        // ── BỐN BẬC CHỮ ───────────────────────────────────────────────────────────────────────
        // Chữ chính: lấy base-content, nhưng nếu chính nó chưa đạt AA trên mọi bề mặt thì kéo về
        // cực trước — nền "lưng chừng" (xám vừa) không được phép sinh ra chữ không đọc được.
        $primaryContent = ThemeColor::shadeUntil($surface, $pole, $surfaces, ThemeColor::AA);
        $out['--color-cream-100'] = $primaryContent;
        $out['--color-cream-50'] = ThemeColor::mix($primaryContent, $pole, 0.35);

        foreach (self::CONTENT_RATIOS as $key => $ratio) {
            // blendToRatio: pha về phía NỀN xa nhất mà vẫn còn đạt tỉ lệ mục tiêu — đó chính là định
            // nghĩa của "bậc chữ phụ": nhạt nhất có thể nhưng vẫn đọc được.
            $out[$key] = ThemeColor::blendToRatio($primaryContent, $card, $surfaces, $ratio);
        }

        // ── DẢI BRAND (nhấn + nút) ────────────────────────────────────────────────────────────
        $primary = self::hex($theme, '--color-primary');
        foreach (self::BRAND_POLE_MIX as $key => $t) {
            $out[$key] = ThemeColor::mix($primary, $pole, $t);
        }
        // brand-50 là "tint rất nhạt" ở CẢ hai chế độ (đúng như bảng màu cũ): nó là nền, không phải
        // chữ, nên không đi theo cực của chế độ mà luôn đi về phía trắng.
        $out['--color-brand-50'] = ThemeColor::mix($primary, '#ffffff', 0.92);
        foreach (self::BRAND_TEXT_KEYS as $key) {
            if (isset($out[$key])) {
                $out[$key] = ThemeColor::shadeUntil($out[$key], $pole, $surfaces, ThemeColor::AA);
            }
        }
        $out['--color-brand-600'] = self::buttonSafe($primary, $surfaces, $pole);
        foreach (self::BRAND_SURFACE_MIX as $key => $t) {
            $out[$key] = ThemeColor::mix($primary, $card, $t);
        }

        // ── HAI DẢI PHỤ (clay · gold) — vẫn dùng ở vài badge, lấy từ secondary/accent ─────────
        $out['--color-clay-500'] = self::hex($theme, '--color-secondary');
        $out['--color-clay-600'] = ThemeColor::mix($out['--color-clay-500'], '#000000', 0.18);
        $out['--color-gold-500'] = self::hex($theme, '--color-accent');
        $out['--color-gold-400'] = ThemeColor::shadeUntil(ThemeColor::mix($out['--color-gold-500'], $pole, 0.18), $pole, $surfaces, ThemeColor::AA);

        // ── MÀU VAI TRÒ + MÀU CHỮ CỦA CHÚNG ───────────────────────────────────────────────────
        foreach (self::ROLE_KEYS as $key) {
            $role = self::hex($theme, $key);
            // Màu nhận diện giữ ĐÚNG giá trị của theme; bốn màu trạng thái là CHỮ nên phải đạt AA.
            $safe = in_array($key, self::TEXT_ROLE_KEYS, true)
                ? ThemeColor::shadeUntil($role, $pole, $surfaces, ThemeColor::AA)
                : $role;
            $out[$key] = $safe;
            $out[$key.'-content'] = ThemeColor::contentFor($safe, self::raw($theme, $key.'-content'));
        }

        return self::verified($out);
    }

    /**
     * Token HÌNH HỌC (bán kính · kích thước · viền) — không đổi theo Sáng/Tối nên chỉ phát một lần.
     *
     * @return array<string,string>
     */
    public static function geometry(DaisyTheme $theme): array
    {
        $out = [];
        foreach (DaisyTheme::LENGTH_KEYS as $key) {
            $value = $theme->raw($key);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        foreach (DaisyTheme::FLAG_KEYS as $key) {
            if ($theme->hasFlag($key)) {
                $out[$key] = $theme->flag($key) ? '1' : '0';
            }
        }

        return $out;
    }

    /** Bảng token ĐẦY ĐỦ (màu + hình học) — dùng khi phát CSS lúc chạy. */
    public static function full(DaisyTheme $theme): array
    {
        return self::tokens($theme) + self::geometry($theme);
    }

    /**
     * Màu NỀN NÚT: giữ đúng màu của theme, TRỪ KHI chữ trắng trên nó không đọc được.
     *
     * Vì sao tách riêng khỏi màu vai trò: primary là màu nhận diện (badge, viền, chữ nhấn), còn
     * brand-600 là NỀN của nút chính — nút nào cũng có chữ trắng, kể cả khi theme đưa vào một màu
     * primary vàng nhạt. Lúc đó nút phải tối đi, chứ không phải chữ trắng mờ đi.
     *
     * @param list<string> $surfaces
     */
    private static function buttonSafe(string $primary, array $surfaces, string $pole): string
    {
        if (ThemeColor::contrast('#ffffff', $primary) >= ThemeColor::AA) {
            return $primary;
        }

        // [Sửa 2026-09-24] Hướng "tối đi" phải LUÔN là #000000 ở cả hai chế độ. Bản cũ đảo về
        // '#ffffff' khi $pole đen (chế độ Sáng): primary sáng (#ff4d1c) bị pha trắng mãi không đạt
        // AA ⇒ brand-600 thoái hoá thành #ffffff — nút chính của theme Sáng thành cục trắng.
        return ThemeColor::shadeUntil($primary, '#000000', ['#ffffff'], ThemeColor::AA);
    }

    /**
     * Chốt chặn cuối: KHÔNG giá trị nào rời khỏi lớp này mà chưa được kiểm.
     *
     * @param array<string,string> $tokens
     * @return array<string,string>
     */
    private static function verified(array $tokens): array
    {
        foreach ($tokens as $key => $value) {
            if (! ThemeColor::isLiteral($value)) {
                throw new RuntimeException(sprintf(
                    'Token %s sinh ra giá trị không hợp lệ ("%s") — dừng lại thay vì phát ra CSS.',
                    $key, mb_substr($value, 0, 30)
                ));
            }
        }

        return $tokens;
    }

    private static function hex(DaisyTheme $theme, string $key): string
    {
        $value = $theme->raw($key);
        if ($value === null || ! ThemeColor::isLiteral($value)) {
            throw new RuntimeException(sprintf('Theme thiếu token %s.', $key));
        }

        return $value;
    }

    private static function raw(DaisyTheme $theme, string $key): ?string
    {
        return $theme->raw($key);
    }
}
