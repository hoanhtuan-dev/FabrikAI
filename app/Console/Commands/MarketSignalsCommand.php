<?php

namespace App\Console\Commands;

use App\Services\MarketSignalService;
use Illuminate\Console\Command;

/**
 * ĐO TÍN HIỆU THỊ TRƯỜNG TỪ KẾT QUẢ TÌM KIẾM — chạy theo lịch và chạy tay (2026-09-23).
 *
 * Dùng:
 *   php artisan studio:market-signals              # tự tra trên web + đo + lưu một lần đo
 *   php artisan studio:market-signals --force      # ghi một lần đo mới dù dữ liệu chưa đổi
 *   php artisan studio:market-signals --region=hcm
 *   php artisan studio:market-signals --prune      # chỉ dọn lần đo cũ
 *
 * Vì sao cần chạy theo LỊCH: model đang dùng không tự ra internet được, nên dữ liệu thị trường chỉ có khi
 * máy chủ đi lấy. Không có lịch thì tin chỉ được lấy khi có người mở màn hình — dữ liệu sẽ cũ vô hạn và
 * không có lịch sử để nói "đang lên hay đang chậm lại".
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26] Lệnh này KHÔNG còn đọc nguồn kind=rss/page (WebSourceService::evidence).
 * Nó để MarketSignalService tự chạy ĐÚNG bộ TRUY VẤN CHỦ ĐỀ CHUNG (topicQueries) — cùng bộ mà bước 2 của
 * Agent Studio dùng — nên số đo của lịch chạy nền và số đo trên màn hình là CÙNG một phép đo.
 */
class MarketSignalsCommand extends Command
{
    protected $signature = 'studio:market-signals
        {--force : Lấy lại tin ngay, bỏ qua đệm}
        {--region=all : Vùng đo (all|hcm|hanoi|danang)}
        {--prune : Chỉ dọn các lần đo quá cũ rồi thoát}';

    protected $description = 'Đo tín hiệu thị trường (từ khoá · số tin · tăng/giảm · dải giá) từ kết quả tìm kiếm trên web và lưu lại.';

    public function handle(MarketSignalService $market): int
    {
        if ($this->option('prune')) {
            $removed = $market->prune();
            $this->line('Đã dọn '.$removed.' lần đo cũ hơn '.MarketSignalService::RETENTION_DAYS.' ngày.');

            return self::SUCCESS;
        }

        $region = (string) $this->option('region');
        if (! in_array($region, ['all', 'hcm', 'hanoi', 'danang'], true)) {
            $this->error('Vùng không hợp lệ. Chọn một trong: all, hcm, hanoi, danang.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        // Không truyền danh sách tin: lớp đo TỰ chạy bộ truy vấn chủ đề chung rồi đo trên kết quả. Đây là
        // đường DUY NHẤT còn đi mạng ở lệnh này — bỏ hẳn việc đọc nguồn kind=rss/page.
        $report = $market->capture($region, $force);
        $search = (array) ($report['search'] ?? []);

        $this->line('── LƯỢT TRA ──');
        if (($search['queries'] ?? []) === []) {
            $this->line('  (không có truy vấn nào được chạy)');
        }
        foreach ((array) ($search['queries'] ?? []) as $query) {
            $this->line('  · '.$query);
        }
        if (($search['sources'] ?? []) === []) {
            $this->warn('  Chưa khai nguồn TÌM KIẾM nào. Thêm trong Cài đặt → Nguồn dữ liệu ngoài (URL có {query}), hoặc chạy: php artisan studio:web-sources --seed');
        }
        foreach ((array) ($search['sources'] ?? []) as $row) {
            $this->line(sprintf(
                '  %-28s %-24s %2d tin%s',
                mb_substr((string) ($row['name'] ?? ''), 0, 27),
                (string) ($row['state_label'] ?? ''),
                (int) ($row['count'] ?? 0),
                ($row['error'] ?? null) ? ' · '.$row['error'] : '',
            ));
        }
        if (($search['error'] ?? null) !== null) {
            $this->warn('  Lý do: '.(string) $search['error']);
        }

        if (($report['mode'] ?? 'empty') !== 'live') {
            $this->warn((string) ($report['note'] ?? 'Chưa tra được tin nào để đo.'));

            return self::SUCCESS;
        }

        $this->line('── TÍN HIỆU (đo từ '.$report['item_count'].' tin của '.$report['source_count'].' nguồn) ──');
        foreach ($report['signals'] as $row) {
            $this->line(sprintf(
                '  %-22s %-11s %2d tin · %d nguồn%s',
                $row['term'],
                $row['category_label'],
                $row['mentions'],
                $row['source_count'],
                is_numeric($row['change_pct'] ?? null) ? ' · '.($row['change_pct'] >= 0 ? '+' : '').$row['change_pct'].'%' : '',
            ));
        }

        $prices = $report['prices'] ?? [];
        if (($prices['count'] ?? 0) > 0) {
            $this->newLine();
            $this->line('Giá ghi nhận trong tin ('.$prices['count'].' lần): '
                .number_format((int) $prices['min_vnd']).'đ – '.number_format((int) $prices['median_vnd']).'đ – '
                .number_format((int) $prices['max_vnd']).'đ (thấp · trung vị · cao)');
        }

        $this->newLine();
        $this->line($report['note']);

        return self::SUCCESS;
    }
}
