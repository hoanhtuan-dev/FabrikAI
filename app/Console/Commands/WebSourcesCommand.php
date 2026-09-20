<?php

namespace App\Console\Commands;

use App\Models\WebSource;
use App\Services\WebSourceService;
use Illuminate\Console\Command;

/**
 * TRÌNH KẾT NỐI NGUỒN NGOÀI — chạy từ SSH (2026-09-23).
 *
 * Dùng:
 *   php artisan studio:web-sources              # xem danh sách nguồn + tình trạng lấy tin (dùng đệm)
 *   php artisan studio:web-sources --force      # lấy lại ngay, bỏ đệm
 *   php artisan studio:web-sources --seed       # tạo các nguồn mặc định còn thiếu
 */
class WebSourcesCommand extends Command
{
    protected $signature = 'studio:web-sources {--force : Lấy lại ngay, bỏ qua đệm} {--seed : Tạo các nguồn mặc định còn thiếu}';

    protected $description = 'Xem/tạo nguồn dữ liệu ngoài và đo việc lấy tin (RSS/JSON) cho agent.';

    public function handle(WebSourceService $service): int
    {
        if ($this->option('seed')) {
            $created = $service->seedDefaults();
            $this->line('Đã tạo '.$created.' nguồn mặc định (nguồn đã có giữ nguyên).');
        }

        $rows = WebSource::query()->orderBy('priority')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $this->warn('Chưa có nguồn nào. Chạy lại với --seed, hoặc thêm trong Cài đặt → Nguồn dữ liệu ngoài.');

            return self::SUCCESS;
        }

        $evidence = $service->evidence('all', WebSourceService::EVIDENCE_LIMIT, (bool) $this->option('force'));

        $this->line('── NGUỒN ──');
        foreach ($evidence['sources'] as $row) {
            $this->line(sprintf(
                '  %s %-32s %s · %s · %d tin%s',
                $row['ok'] ? '[OK]  ' : '[LỖI] ',
                $row['name'],
                $row['kind'],
                $row['http'] !== null ? 'HTTP '.$row['http'] : 'không kết nối',
                $row['count'],
                $row['error'] ? ' · '.$row['error'] : '',
            ));
        }

        $this->line('── TIN ĐƯA VÀO PROMPT (mới nhất trước) ──');
        foreach (array_slice($evidence['items'], 0, 8) as $item) {
            $this->line('  · ['.($item['published_at'] ? date('d/m/Y', strtotime($item['published_at'])) : 'không ngày').'] '
                .mb_substr($item['title'], 0, 90).' — '.$item['source_name']);
        }

        $this->newLine();
        $this->line('mode='.$evidence['mode'].' · tổng '.count($evidence['items']).' tin · lấy lúc '.$evidence['fetched_at']);

        return self::SUCCESS;
    }
}
