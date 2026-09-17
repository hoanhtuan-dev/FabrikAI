<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [Xác minh 2026-09-17] BUILD PHẢI TẤT ĐỊNH (không phụ thuộc việc đã chạy test hay chưa).
 *
 * Sự cố thật: `resources/css/app.css` quét `@source '../../storage/framework/views/*.php'`.
 * Thư mục đó chứa view ĐÃ BIÊN DỊCH sinh ra lúc chạy — chỉ cần chạy test thông báo (render
 * `mail::message`) là Tailwind nhặt thêm class của mail, và hash CSS ĐỔI dù không ai sửa
 * CSS/JS. Hệ quả: build trong CI khác build trên máy ⇒ diff giả, deploy không lặp lại được.
 *
 * Bất biến khoá ở đây: CSS nguồn chỉ được quét NGUỒN, không quét thư mục biên dịch lúc chạy.
 */
class BuildDeterminismTest extends TestCase
{
    public function test_css_does_not_scan_compiled_views(): void
    {
        // Chỉ soi dòng CHỈ THỊ đang hoạt động — comment được phép NHẮC tới đường dẫn này
        // (chính file app.css ghi lại lý do đã gỡ), nếu không test sẽ bắt oan chính ghi chú.
        $active = implode("\n", array_filter(
            preg_split('/\R/', (string) file_get_contents(resource_path('css/app.css'))),
            fn ($line) => ! str_starts_with(ltrim((string) $line), '//')
        ));

        $this->assertStringNotContainsString('storage/framework/views', $active,
            'app.css KHÔNG được quét storage/framework/views: đó là view đã biên dịch lúc chạy, '.
            'quét nó làm hash CSS đổi chỉ vì đã chạy test ⇒ build không tất định.');
    }

    public function test_css_still_scans_real_sources(): void
    {
        // Nửa còn lại của bất biến: gỡ nhầm hết @source thì class của app biến mất khỏi CSS.
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("@source '../views'", $css, 'phải quét blade nguồn.');
        $this->assertStringContainsString("@source '../js/studio'", $css, 'phải quét JS/Vue nguồn.');
    }
}
