<?php

namespace App\Console\Commands;

use App\Models\BrandLearning;
use App\Services\BrandLearningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CỦNG CỐ TRÍ NHỚ DÀI HẠN — GĐ3 (2026-09-26).
 *
 * Dùng:  php artisan studio:memory:consolidate              # suy yếu + quên theo lịch
 *        php artisan studio:memory:consolidate --dry-run    # chỉ ĐẾM, không ghi gì
 *
 * VÌ SAO CẦN MỘT LỆNH THEO LỊCH: hai bước kia của vòng lặp tự học đã chạy — GHI thì xảy ra lúc chủ shop
 * bấm duyệt/loại, RÚT BÀI HỌC thì chạy trong queue. Còn "suy yếu" và "quên" là việc CHỈ thời gian làm được:
 * không có lệnh theo lịch thì mọi ký ức nặng mãi như nhau và cửa sổ prompt bị lấp bởi những ký ức cũ —
 * trí nhớ dày lên nhưng KHÔNG sắc hơn.
 *
 * Chạy SAU các việc dọn dẹp khác (03:00 storage · 03:30 prune tín hiệu) để không tranh nhau ghi DB.
 *
 * KHÔNG đụng tới @@brand_rules@@: quy tắc làm việc là do CHỦ SHOP tự đặt và tự đặt mức ưu tiên — suy yếu
 * chúng theo thời gian là máy tự ý hạ ưu tiên của người dùng.
 */
class ConsolidateMemory extends Command
{
    protected $signature = 'studio:memory:consolidate {--dry-run : Chỉ đếm, không ghi gì}';

    protected $description = 'Củng cố trí nhớ dài hạn: suy yếu ký ức lâu không dùng, quên ký ức đã yếu và đã cũ.';

    public function handle(BrandLearningService $memory): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $before = $this->summary();
        if ($before['total'] === 0) {
            $this->info('Chưa có ký ức nào — không có gì để củng cố.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'TRƯỚC: %d ký ức · %d đã có bài học · mạnh (>=8): %d · yếu (<=2): %d · weight TB: %s',
            $before['total'], $before['with_lesson'], $before['strong'], $before['weak'], $before['avg_weight'],
        ));

        $result = $memory->decay($dryRun);

        if ($dryRun) {
            $this->warn(sprintf(
                'CHẠY THỬ: sẽ suy yếu %d ký ức và quên %d ký ức (không ghi gì).',
                $result['decayed'], $result['forgotten'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('Đã suy yếu %d ký ức · đã quên %d ký ức.', $result['decayed'], $result['forgotten']));

        $after = $this->summary();
        $this->line(sprintf(
            'SAU:  %d ký ức · %d đã có bài học · mạnh (>=8): %d · yếu (<=2): %d · weight TB: %s',
            $after['total'], $after['with_lesson'], $after['strong'], $after['weak'], $after['avg_weight'],
        ));

        return self::SUCCESS;
    }

    /** Số liệu TOÀN HỆ THỐNG (mọi tài khoản) — người vận hành cần thấy trí nhớ dày lên hay mỏng đi. */
    private function summary(): array
    {
        $row = DB::table('brand_learning')->selectRaw(
            'COUNT(*) AS total,'
            .' SUM(CASE WHEN lesson IS NOT NULL AND lesson != \'\' THEN 1 ELSE 0 END) AS with_lesson,'
            .' SUM(CASE WHEN weight >= 8 THEN 1 ELSE 0 END) AS strong,'
            .' SUM(CASE WHEN weight <= 2 THEN 1 ELSE 0 END) AS weak,'
            .' AVG(weight) AS avg_weight'
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'with_lesson' => (int) ($row->with_lesson ?? 0),
            'strong' => (int) ($row->strong ?? 0),
            'weak' => (int) ($row->weak ?? 0),
            'avg_weight' => round((float) ($row->avg_weight ?? 0), 2),
        ];
    }
}
