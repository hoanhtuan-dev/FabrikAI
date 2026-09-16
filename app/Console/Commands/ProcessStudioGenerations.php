<?php

namespace App\Console\Commands;

use App\Jobs\RenderImageJob;
use App\Jobs\RenderVideoJob;
use App\Models\Generation;
use Illuminate\Console\Command;

/**
 * Fallback worker cho Studio khi KHÔNG chạy `queue:work` (shared hosting / cron).
 *
 * 1) Heal: các generation kẹt 'processing' (worker/request bị giết giữa chừng) → failed + hoàn tiền,
 *    thay vì "Đang xử lý" mãi mãi với chủ nhân không quay lại poll show().
 * 2) Process: chạy tuần tự mọi generation 'pending' — render image/video VÀ swap (trước đây swap
 *    bị bỏ qua nên nếu queue worker không chạy, swap kẹt 'pending' vĩnh viễn).
 *
 * An toàn chạy song song: mọi job CAS-claim pending→processing ngay khi chạy, nên lệnh này đè
 * lên queue:work / lazy show() / chính nó chạy chồng cũng không xử lý đúp.
 */
class ProcessStudioGenerations extends Command
{
    /** > timeout lớn nhất của job (600s) + dư địa — row 'processing' cũ hơn mức này coi như mồ côi. */
    protected const STUCK_MINUTES = 11;

    protected $signature = 'studio:process {--limit=10 : Số generation xử lý mỗi lượt} {--stuck-only : Chỉ hồi phục generation kẹt, không chạy job mới}';

    protected $description = 'Process pending Studio generations (render + swap) and heal stuck ones (fallback for queue mode).';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // 1) Heal stuck 'processing' rows — mirrors show()'s failStuck, nhưng chủ động (cron)
        // thay vì thụ động chờ user poll.
        $stuck = Generation::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($stuck as $generation) {
            // M02/M-c (2026-09-17): đi qua helper CAS DÙNG CHUNG (app/Support/helpers.php) — cùng một
            // đường với failStuck() · reconcileStuckCredits() · cancel() · RenderImage/VideoJob.
            // Trước đây mỗi chỗ tự update + increment nên có 5 bản sao, chỉ 4 bản có CAS ⇒ cancel()
            // hoàn tiền 2 lần. Bất biến "chỉ MỘT đường đụng credit" được khoá bằng test.
            if (! studio_finalize_generation(
                $generation,
                'failed',
                ['processing'],
                'Hết thời gian xử lý (worker bị ngắt). Đã hoàn tiền vào tài khoản — vui lòng tạo lại.'
            )) {
                continue; // lượt khác đã xử lý row này
            }
            $this->warn('Healed stuck generation #'.$generation->id.' (failed + refund).');
        }

        if ($this->option('stuck-only')) {
            $this->info('Done ('.$stuck->count().' healed, no new jobs run).');

            return 0;
        }

        // 2) Process pending generations — thứ tự FIFO theo id.
        $pending = Generation::where('status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $n = 0;
        foreach ($pending as $generation) {
            if ($generation->type === 'video') {
                RenderVideoJob::dispatchSync($generation->id);
            } else {
                RenderImageJob::dispatchSync($generation->id);
            }
            $this->info('Generation #'.$generation->id.' → '.($generation->fresh()->status ?? '?'));
            $n++;
        }

        $this->info('Done ('.$n.' processed, '.$stuck->count().' healed).');

        return 0;
    }
}
