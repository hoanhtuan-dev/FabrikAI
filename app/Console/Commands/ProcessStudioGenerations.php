<?php

namespace App\Console\Commands;

use App\Jobs\RenderImageJob;
use App\Jobs\RenderVideoJob;
use App\Jobs\SwapModelJob;
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
            // M02 (đồng bộ với StudioController::failStuck/reconcileStuckCredits): CAS — chỉ lượt nào
            // ĐỔI ĐƯỢC trạng thái khỏi 'processing' mới hoàn credit. Trước đây khối này update +
            // increment KHÔNG điều kiện, nên hai lượt cron chồng nhau (shared hosting) cùng SELECT ra
            // một row sẽ hoàn tiền 2 lần. Comment ở đầu class chỉ nói về CAS của các JOB, không phải
            // khối heal này.
            $claimed = Generation::where('id', $generation->id)
                ->where('status', 'processing')
                ->update([
                    'status' => 'failed',
                    'error' => 'Hết thời gian xử lý (worker bị ngắt). Đã hoàn tiền vào tài khoản — vui lòng tạo lại.',
                ]);

            if (! $claimed) {
                continue; // lượt khác đã xử lý row này
            }

            if ($generation->credits_cost > 0) {
                $generation->user?->increment('credits_balance', $generation->credits_cost);
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
            if (($generation->meta['swap'] ?? false) === true) {
                // Swap cũng là job CAS-claim pending→processing — dispatchSync chạy inline an toàn.
                SwapModelJob::dispatchSync($generation->id);
            } elseif ($generation->type === 'video') {
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
