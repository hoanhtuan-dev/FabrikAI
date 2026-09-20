<?php

namespace App\Console\Commands;

use App\Support\ThemeCss;
use Illuminate\Console\Command;

/**
 * ĐỒNG BỘ BẢNG MÀU TRONG resources/css/app.css TỪ THEME GỐC (2026-09-25).
 *
 * Chạy sau khi đổi resources/themes/daisyui-dark.theme.json (hoặc sau khi đổi công thức sinh ở
 * App\Support\ThemeRamp):
 *
 *     php artisan theme:sync            # ghi lại bảng màu
 *     php artisan theme:sync --check    # chỉ kiểm tra, khác thì trả mã lỗi 1 (dùng cho CI)
 *
 * Vì sao cần lệnh riêng thay vì sinh lúc chạy: CSS được build bởi Vite và bán cho khách — bảng màu
 * phải nằm TRONG tệp CSS đã build (không phụ thuộc database, không thêm một <style> cho mọi trang).
 * Đổi lại, tệp sinh ra phải có đường cập nhật và một bài test canh lệch (ThemeImportTest).
 */
class ThemeSync extends Command
{
    protected $signature = 'theme:sync {--check : Chỉ kiểm tra app.css có khớp theme gốc không}';

    protected $description = 'Sinh bảng màu trong resources/css/app.css từ theme gốc (resources/themes/*.json).';

    public function handle(): int
    {
        $path = resource_path(ThemeCss::FILE);
        $expected = ThemeCss::expected();
        $current = (string) file_get_contents($path);

        if ($expected === $current) {
            $this->info('app.css đã khớp theme gốc — không cần ghi gì.');

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            $this->error('app.css KHÔNG khớp theme gốc. Chạy "php artisan theme:sync" rồi commit lại.');

            return self::FAILURE;
        }

        file_put_contents($path, $expected);
        $this->info('Đã sinh lại bảng màu trong resources/css/app.css từ theme gốc.');

        return self::SUCCESS;
    }
}
