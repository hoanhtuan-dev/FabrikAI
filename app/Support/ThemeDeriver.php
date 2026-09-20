<?php

namespace App\Support;

/**
 * SINH BẢN ĐỐI ỨNG SÁNG ⇄ TỐI CỦA MỘT THEME (2026-09-25 · docs/DESIGN_SYSTEM.md §1.1).
 *
 * Vì sao cần: liên kết của daisyUI Theme Generator chỉ chứa MỘT theme (payload có đúng một
 * "color-scheme"). Nhưng FabrikAI luôn có HAI chế độ, và người dùng đổi qua lại bất cứ lúc nào —
 * import một theme Tối mà chế độ Sáng vẫn là bảng màu cũ của app thì hai chế độ nói hai thứ tiếng
 * khác nhau (đúng lỗi "theme Sáng chỉ là bản sao của theme Tối" mà ThemeSystemTest đã khoá).
 *
 * Nên mỗi lần import, hệ thống lưu HAI theme: bản gốc và bản đối ứng do lớp này sinh ra. Ba quy tắc:
 *
 *   1. GIỮ HUE, ĐỔI ĐỘ SÁNG. Bản đối ứng lấy đúng hue/chroma tương đối của bản gốc, chỉ đổi bậc
 *      sáng-tối. Trộn trong OKLab (không phải HSL) nên màu không đi qua vùng xám và không lệch hue.
 *
 *   2. BỀ MẶT THEO CHUẨN CỦA CHẾ ĐỘ, KHÔNG MIRROR MÙ. "Đảo bậc nền" không có nghĩa là L → 1-L: một
 *      theme Tối rất đen (L=0,10) mirror ra sẽ thành nền xám bẩn chứ không phải nền sáng. Bản Sáng
 *      dùng đúng dải độ sáng của chế độ Sáng (gần trắng), bản Tối dùng đúng dải của chế độ Tối.
 *
 *   3. MÀU VAI TRÒ PHẢI ĐỌC ĐƯỢC. Màu trạng thái/nhấn ở chế độ Sáng là CHỮ trên nền sáng — bộ màu
 *      tươi của theme Tối (vàng #fcb700, teal #00d3bb) chỉ đạt ~1,7:1 trên nền trắng. Lớp này hạ
 *      sắc độ VỪA ĐỦ để đạt WCAG AA, và trả về danh sách những màu đã phải chỉnh để giao diện nói
 *      thật với người import ("3 màu đã được chỉnh cho đạt chuẩn"), thay vì âm thầm đổi màu họ chọn.
 */
final class ThemeDeriver
{
    /** Dải độ sáng bề mặt của từng chế độ (theo đúng bảng màu daisyUI: base-100 sáng nhất). */
    private const SURFACE_L = [
        'dark' => [0.255, 0.233, 0.211],
        'light' => [0.995, 0.975, 0.945],
    ];

    /** Độ sáng của màu CHỮ chính (base-content) theo chế độ. */
    private const CONTENT_L = ['dark' => 0.960, 'light' => 0.260];

    /** Trần chroma cho bề mặt: giữ chút sắc của theme gốc mà không thành màu nhuộm. */
    private const SURFACE_MAX_CHROMA = 0.020;

    /**
     * Sinh bản đối ứng (Sáng ⇄ Tối) của một theme.
     *
     * @return array{theme:DaisyTheme,adjusted:list<string>}
     */
    public static function counterpart(DaisyTheme $theme): array
    {
        $target = $theme->scheme() === 'dark' ? 'light' : 'dark';
        $sourceSurfaces = self::surfaces($theme);
        $worstSurface = [];   // bốn bề mặt của BẢN ĐỐI ỨNG (dựng trước để kiểm tương phản màu chữ)
        $adjusted = [];

        // ── 1. Bề mặt: giữ hue của bản gốc, lấy dải sáng của chế độ đích ──────────────────────
        $hue = ThemeColor::hexToOklch($sourceSurfaces[0])['h'];
        $chroma = min(ThemeColor::hexToOklch($sourceSurfaces[0])['c'], self::SURFACE_MAX_CHROMA);
        $overrides = [];

        foreach (['--color-base-100', '--color-base-200', '--color-base-300'] as $i => $key) {
            $hex = ThemeColor::oklchToHex(['l' => self::SURFACE_L[$target][$i], 'c' => $chroma, 'h' => $hue]);
            $overrides[$key] = $hex;
            $worstSurface[] = $hex;
        }

        // ── 2. Chữ chính: đảo cực, giữ hue ────────────────────────────────────────────────────
        $contentOklch = ThemeColor::hexToOklch($theme->raw('--color-base-content') ?? '#ffffff');
        $overrides['--color-base-content'] = ThemeColor::oklchToHex([
            'l' => self::CONTENT_L[$target],
            'c' => min($contentOklch['c'], 0.03),
            'h' => $contentOklch['h'],
        ]);

        // Bề mặt dùng để kiểm tương phản màu CHỮ: bốn bậc của app, không chỉ ba bậc base của daisyUI.
        $surfaces = ThemeRamp::surfaces($overrides, $target);

        // ── 3. Màu TRẠNG THÁI: hạ sắc độ vừa đủ để đọc được trên nền đích ───────────────────
        // Chỉ bốn màu trạng thái bị chỉnh. Màu NHẬN DIỆN (primary · secondary · accent · neutral)
        // giữ nguyên giữa hai chế độ — đúng như bảng màu viết tay trước đây (xanh thương hiệu là
        // #2d6f4d ở CẢ hai chế độ): đổi chế độ là đổi nền và chữ, không đổi màu thương hiệu.
        $pole = $target === 'dark' ? '#ffffff' : '#000000';
        foreach (ThemeRamp::TEXT_ROLE_KEYS as $key) {
            $raw = $theme->raw($key);
            if ($raw === null) {
                continue;
            }
            $safe = ThemeColor::shadeUntil($raw, $pole, $surfaces, ThemeColor::AA);
            $overrides[$key] = $safe;
            if ($safe !== strtolower($raw)) {
                $adjusted[] = $key;
            }
            $overrides[$key.'-content'] = ThemeColor::contentFor($safe, $theme->raw($key.'-content'));
        }

        $derived = $theme->withScheme($target)->withTokens($overrides);

        return [
            'theme' => $derived->withName($theme->name().' · '.($target === 'light' ? 'Sáng' : 'Tối')),
            'adjusted' => $adjusted,
        ];
    }

    /**
     * Ba bề mặt của một theme, xếp theo đúng vai trò base-100 (card) → base-300 (nền trang).
     *
     * @return list<string>
     */
    private static function surfaces(DaisyTheme $theme): array
    {
        return array_map(
            fn (string $key) => $theme->raw($key) ?? '#000000',
            ['--color-base-100', '--color-base-200', '--color-base-300']
        );
    }
}
