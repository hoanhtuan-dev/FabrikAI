<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [Xác minh 2026-09-17] BUILD PHẢI TẤT ĐỊNH và PHẢI KHỚP NGUỒN.
 *
 * Hai sự cố THẬT đã xảy ra, đều không bộ test nào bắt được:
 *
 *  (1) `app.css` quét `storage/framework/views/*.php` — thư mục chứa view ĐÃ BIÊN DỊCH sinh ra lúc
 *      chạy. Test thông báo render `mail::message` ⇒ Tailwind nhặt thêm class của mail ⇒ hash CSS
 *      đổi dù không ai sửa CSS/JS. Build vì thế KHÔNG tất định (máy đã chạy test ≠ máy sạch).
 *
 *  (2) Ghi chú trong `app.css` dùng `//` — KHÔNG phải cú pháp CSS. Tailwind coi đó là khai báo
 *      hỏng và NUỐT LUÔN dòng `@source` phía sau ⇒ `npm run build` đỏ. Nguy hiểm ở chỗ: build đỏ
 *      để lại asset CŨ trên đĩa, nên nhìn `ls` vẫn thấy file ⇒ tưởng build đã thành công.
 *
 * Bất biến khoá ở đây: (a) chỉ quét NGUỒN; (b) comment phải đúng cú pháp CSS;
 * (c) manifest build phải trỏ tới file CÓ THẬT trên đĩa (chặn đúng cái bẫy (2)).
 */
class BuildDeterminismTest extends TestCase
{
    /** CSS đã bỏ hết comment khối kiểu CSS — chỉ còn chỉ thị đang hoạt động. */
    private function activeCss(): string
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    public function test_css_does_not_scan_compiled_views(): void
    {
        $this->assertStringNotContainsString('storage/framework/views', $this->activeCss(),
            'app.css KHÔNG được quét storage/framework/views: đó là view đã biên dịch lúc chạy, '.
            'quét nó làm hash CSS đổi chỉ vì đã chạy test ⇒ build không tất định.');
    }

    public function test_tailwind_auto_detection_is_off(): void
    {
        // Mặc định Tailwind tự quét cả cây dự án (kể cả .md) ⇒ CSS phụ thuộc VĂN BẢN TÀI LIỆU.
        // Sự cố thật: chữ "break-all" trong STUDIO_REVIEW_PROGRESS.md sinh ra utility .break-all
        // trong CSS bán cho khách. `source(none)` + khai báo @source tường minh mới chặn được.
        $this->assertStringContainsString('source(none)', $this->activeCss(),
            'Phải tắt tự-dò-nguồn của Tailwind, nếu không CSS lại phụ thuộc nội dung file tài liệu.');
    }

    public function test_css_still_scans_real_sources(): void
    {
        // Nửa còn lại của bất biến: gỡ nhầm hết @source thì class của app biến mất khỏi CSS.
        $css = $this->activeCss();

        $this->assertStringContainsString("@source '../views'", $css, 'phải quét blade nguồn.');
        $this->assertStringContainsString("@source '../js/studio'", $css, 'phải quét JS/Vue nguồn.');
    }

    public function test_css_has_no_double_slash_comments(): void
    {
        // `//` không phải cú pháp CSS: Tailwind nuốt luôn dòng @source phía sau ⇒ build đỏ.
        foreach (preg_split('/\R/', $this->activeCss()) as $line) {
            $this->assertStringStartsNotWith('//', ltrim((string) $line),
                'app.css dùng // làm comment — Tailwind coi là khai báo hỏng và ăn luôn @source phía sau. '.
                'Dùng comment khối kiểu CSS thay thế.');
        }
    }

    public function test_built_manifest_points_at_files_that_exist(): void
    {
        // Build đỏ để lại asset CŨ ⇒ `ls` vẫn thấy file và rất dễ tưởng đã build xong.
        // Kiểm manifest trỏ tới file có thật để không deploy một build hỏng.
        $manifestPath = public_path('build/manifest.json');
        $this->assertFileExists($manifestPath, 'Thiếu manifest build — chưa chạy `npm run build`.');

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest, 'manifest.json không đọc được.');

        $missing = [];
        foreach ($manifest as $entry => $meta) {
            foreach ((array) ($meta['file'] ?? []) as $f) {
                if ($f !== '' && ! is_file(public_path('build/'.$f))) $missing[] = $entry.' → '.$f;
            }
            foreach ((array) ($meta['css'] ?? []) as $f) {
                if ($f !== '' && ! is_file(public_path('build/'.$f))) $missing[] = $entry.' (css) → '.$f;
            }
        }

        $this->assertSame([], $missing,
            'manifest trỏ tới asset KHÔNG có trên đĩa — dấu hiệu build hỏng nhưng asset cũ còn sót: '.
            implode(' · ', $missing));
    }
}
