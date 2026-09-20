<?php

namespace App\Console\Commands;

use App\Services\WebAccessService;
use Illuminate\Console\Command;

/**
 * KIỂM TRA KHẢ NĂNG TRUY CẬP INTERNET CỦA AGENT — chạy được từ SSH (Đợt 22 — 2026-09-23).
 *
 * Vì sao cần lệnh riêng: câu hỏi "agent có ra được internet không?" phải trả lời được ngay trên máy
 * chủ thật, không phụ thuộc giao diện và không phải mở trình duyệt. Lệnh đo HAI tầng tách bạch:
 * máy chủ có gọi ra ngoài được không, và model đang cấu hình có tìm kiếm tích hợp hay không.
 *
 * Dùng:  php artisan studio:web-access          # dùng cache 10 phút
 *        php artisan studio:web-access --force  # đo lại ngay
 */
class WebAccessCheck extends Command
{
    protected $signature = 'studio:web-access {--force : Đo lại ngay, bỏ qua cache}';

    protected $description = 'Đo khả năng truy cập internet của máy chủ + khả năng tìm kiếm của model đang cấu hình.';

    public function handle(WebAccessService $web): int
    {
        $result = $web->probe((bool) $this->option('force'));

        $this->line('── TẦNG MÁY CHỦ ──');
        foreach ($result['outbound']['results'] as $row) {
            $this->line(sprintf(
                '  %s %s — %s (%d ms)%s',
                $row['ok'] ? '[OK] ' : '[LỖI]',
                $row['url'],
                $row['status'] !== null ? 'HTTP '.$row['status'] : 'không kết nối được',
                $row['ms'],
                isset($row['error']) ? ' · '.$row['error'] : '',
            ));
        }
        $this->line('  Kết luận: '.($result['outbound']['ok'] ? 'CÓ internet' : 'KHÔNG ra được internet'));

        $this->line('── TẦNG MODEL ──');
        if ($result['model_search']['candidates'] === []) {
            $this->line('  Chưa cấu hình model nào cho nhóm công việc của agent.');
        }
        foreach ($result['model_search']['candidates'] as $row) {
            $this->line(sprintf(
                '  %s %s — %s',
                $row['supported'] ? '[CÓ tìm kiếm]     ' : '[không tìm kiếm]',
                $row['provider'].':'.$row['model'],
                $row['label'],
            ));
        }

        $this->newLine();
        $this->line($result['verdict_label']);

        return self::SUCCESS;
    }
}
