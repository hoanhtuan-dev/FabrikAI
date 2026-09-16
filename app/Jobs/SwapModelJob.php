<?php

namespace App\Jobs;

use App\Http\Controllers\StudioController;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * "Thay Đổi Người Mẫu" runs the long AI pipeline (try-on + optional face-swap, ~2-3 min) in the
 * background queue so the HTTP request returns immediately — long synchronous requests get cut by
 * the hosting proxy timeout. The worker processes this job and updates the generation row.
 */
class SwapModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /** 10 min — the try-on + face-swap passes can each take ~1 min plus retries/backoffs. */
    public int $timeout = 600;

    /** One attempt: the pipeline is expensive — timeouts/fatals are cleaned up by failed(), not retried. */
    public int $tries = 1;

    /** A timed-out job goes straight to failed_jobs instead of a silent retry that double-bills providers. */
    public bool $failOnTimeout = true;

    public function __construct(public int $generationId) {}

    public function handle(): void
    {
        $t0 = microtime(true);
        $generation = Generation::find($this->generationId);

        if (! $generation) {
            return;
        }

        // CAS: chỉ người đầu tiên chuyển pending->processing được xử lý — tránh chạy đúp giữa
        // queue worker và đường xử lý lazy trong show() (khi worker không chạy).
        $claimed = Generation::where('id', $this->generationId)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if (! $claimed) {
            return;
        }

        try {
            app(StudioController::class)->executeSwapFromGeneration($generation);

            // Pipeline hoàn tất — ghi thời lượng thực để đo cảm nhận tốc độ (parity với Render jobs).
            $generation->update(['elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);

            logger()->info('Swap job completed', [
                'generation_id' => $this->generationId,
                'status' => $generation->fresh()->status,
                'total_s' => round(microtime(true) - $t0, 2),
            ]);
        } catch (\Throwable $e) {
            logger()->error('Swap job failed', ['generation_id' => $this->generationId, 'error' => $e->getMessage()]);
            $generation->update([
                'status' => 'failed',
                'error' => studio_generation_error($e),
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
            // Credit đã trừ lúc tạo generation — thất bại phải hoàn (parity với Render jobs).
            $this->refund($generation);
        }
    }

    /**
     * Queue-worker safety net: fires when handle() never finishes (worker timeout via
     * pcntl, OOM, process kill) — the exception escapes handle()'s catch. Idempotent:
     * skips rows already completed/failed/cancelled (refund already happened in handle()).
     */
    public function failed(\Throwable $e): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || ! in_array($generation->status, ['pending', 'processing'], true)) {
            return;
        }

        $generation->update([
            'status' => 'failed',
            'error' => studio_generation_error($e, 'Swap bị ngắt (worker timeout/vào failed_jobs): '),
        ]);
        $this->refund($generation);
        logger()->warning('Swap job crashed (job failed)', [
            'generation_id' => $this->generationId, 'error' => $e->getMessage(),
        ]);
    }

    protected function refund(Generation $generation): void
    {
        if ($generation->credits_cost > 0) {
            $generation->user?->increment('credits_balance', $generation->credits_cost);
        }
    }
}
