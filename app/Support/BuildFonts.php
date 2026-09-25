<?php

namespace App\Support;

/**
 * FONT CỦA BUILD — nối `laravel-vite-plugin/fonts` với Blade (đợt 62 · 2026-09-26).
 *
 * VẤN ĐỀ ĐO ĐƯỢC: plugin đã tự-host và PHÁT RA đủ thứ — `public/build/assets/fonts-<hash>.css` (mọi
 * @font-face của Inter · Fraunces · Space Grotesk) + `public/build/fonts-manifest.json` — nhưng KHÔNG
 * trang nào nạp tệp CSS đó. Hệ quả: cả ba họ chữ chỉ là TÊN trong CSS, còn trình duyệt thì rơi về
 * `system-ui` ở MỌI máy. Đo trên Chrome thật: `document.fonts` rỗng, HTML không có <link> font nào.
 *
 * VÌ SAO KHÔNG dùng `@vite('fonts.css')`: tệp này KHÔNG nằm trong `manifest.json` của Vite (plugin phát
 * nó bằng `emitFile` loại asset, kèm một manifest RIÊNG). Nên đường đúng là đọc chính manifest đó —
 * cùng lối với `__STUDIO_MAIN_URL__` (đọc qua `Vite::asset`) đang dùng cho Trang chủ.
 *
 * Không có manifest (chưa build / bản dev) ⇒ trả về rỗng và trang vẫn chạy với font hệ thống: thiếu
 * font là chuyện NHÌN, không được làm sập trang.
 */
final class BuildFonts
{
    /** @return array{style: string, preloads: array<int, array<string, mixed>>} */
    public static function manifest(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $path = public_path('build/fonts-manifest.json');
        if (! is_file($path)) {
            return $cache = ['style' => '', 'preloads' => []];
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            return $cache = ['style' => '', 'preloads' => []];
        }

        $style = (string) ($json['style']['file'] ?? '');

        return $cache = [
            'style' => $style !== '' ? '/build/'.ltrim($style, '/') : '',
            'preloads' => is_array($json['preloads'] ?? null) ? $json['preloads'] : [],
        ];
    }

    /** URL của tệp CSS chứa mọi @font-face (rỗng nếu chưa build). */
    public static function styleUrl(): string
    {
        return self::manifest()['style'];
    }

    /**
     * Danh sách font nên PRELOAD: chỉ woff2, và chỉ một weight cho mỗi họ (preload thừa là chậm trang —
     * trình duyệt phải tải trước cả những weight chưa chắc dùng).
     */
    public static function preloads(): array
    {
        $seen = [];
        $out = [];
        foreach (self::manifest()['preloads'] as $p) {
            $family = (string) ($p['family'] ?? '');
            $file = (string) ($p['file'] ?? '');
            if ($family === '' || $file === '' || ! str_ends_with($file, '.woff2')) {
                continue;
            }
            if (isset($seen[$family])) {
                continue;
            }
            $seen[$family] = true;
            $out[] = ['family' => $family, 'url' => '/build/'.ltrim($file, '/')];
        }

        return $out;
    }
}
