<?php

namespace App\Console\Commands;

use App\Models\Sample;
use App\Services\SampleTrackingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * NHẮC HẠN MẪU VẬT LÝ — Việc #4 (2026-09-26).
 *
 * Dùng:  php artisan studio:samples:remind                 # nhắc hạn trong 3 ngày tới (kể cả quá hạn)
 *        php artisan studio:samples:remind --days=7        # rộng hơn
 *        php artisan studio:samples:remind --dry-run       # chỉ liệt kê, KHÔNG gửi mail
 *
 * ĐAI CHỐNG SPAM: một tài khoản chỉ nhận TỐI ĐA MỘT thư mỗi ngày cho việc này (Cache::add theo user +
 * ngày). Không có đai thì lịch chạy mỗi giờ — hoặc người vận hành bấm tay hai lần — là hộp thư khách bị
 * dội, và khách sẽ tắt luôn loại thông báo hữu ích này.
 *
 * LƯU Ý VỀ PRODUCTION: máy chủ hiện KHÔNG có cron nào (đã ghi trong DEPLOY_LOG), nên lệnh này chỉ chạy
 * khi chủ dự án thêm dòng cron trong hPanel. Giao diện vẫn hiện cảnh báo mà không cần lệnh này — cảnh báo
 * đó TÍNH TỪ DỮ LIỆU, không phụ thuộc việc gì đã chạy.
 */
class RemindSamples extends Command
{
    protected $signature = 'studio:samples:remind {--days=3 : Nhắc cả mẫu có hạn trong bao nhiêu ngày tới} {--dry-run : Chỉ liệt kê, không gửi thư}';

    protected $description = 'Gửi thư nhắc các mẫu vật lý sắp tới hạn hoặc đã quá hạn (mỗi tài khoản tối đa 1 thư/ngày).';

    public function handle(SampleTrackingService $tracking): int
    {
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        $samples = $tracking->dueSamples($days);

        if ($samples->isEmpty()) {
            $this->info('Không có mẫu nào cần nhắc (hạn trong '.$days.' ngày tới).');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        // Gom theo CHỦ BỘ SƯU TẬP: một thư nói về mọi mẫu của họ, thay vì một thư cho mỗi mẫu.
        foreach ($samples->groupBy(fn (Sample $s) => $s->project?->user_id) as $userId => $group) {
            if (! $userId) {
                continue;
            }

            $overdue = $group->filter(fn (Sample $s) => SampleTrackingService::alert($s) === 'overdue')->count();
            $dueSoon = $group->count() - $overdue;

            $owner = $group->first()?->project?->user;
            if ($owner === null) {
                continue;
            }

            $this->line(sprintf('· %s (%s): %d mẫu — %d quá hạn · %d sắp tới hạn',
                $owner->email ?? ('user #'.$userId), $owner->name ?? '', $group->count(), $overdue, $dueSoon));

            if ($dryRun) {
                continue;
            }

            // Đai chống spam: khoá theo (user · ngày). add() trả false nghĩa là hôm nay đã gửi rồi.
            if (! Cache::add('studio:samples-reminded:'.$userId.':'.now()->toDateString(), 1, now()->addDay())) {
                $skipped++;
                $this->line('  ↳ đã gửi thư hôm nay — bỏ qua.');

                continue;
            }

            $owner->notify(new \App\Notifications\SamplesDue($group->values(), $overdue, $dueSoon));
            $sent++;
        }

        $this->info($dryRun
            ? 'Chạy thử: KHÔNG gửi thư nào.'
            : 'Đã gửi '.$sent.' thư'.($skipped > 0 ? ' (bỏ qua '.$skipped.' vì đã gửi hôm nay)' : '').'.');

        return self::SUCCESS;
    }
}
