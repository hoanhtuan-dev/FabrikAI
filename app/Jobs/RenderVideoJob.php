<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\VideoAIService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenderVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Allow generous time for video render (1-3 min on real providers).
     */
    public int $timeout = 600; // 10 min — must exceed the 300s provider poll deadline

    /** One attempt: a render is expensive — timeouts/fatals are cleaned up by failed(), not retried. */
    public int $tries = 1;

    /** A timed-out job goes straight to failed_jobs instead of a silent retry that double-bills providers. */
    public bool $failOnTimeout = true;

    public function __construct(public int $generationId) {}

    public function handle(VideoAIService $videos): void
    {
        $t0 = microtime(true);
        $generation = Generation::find($this->generationId);

        if (! $generation) {
            return;
        }

        // CAS: chỉ một đường (queue worker / lazy show() / studio:process) nhận được
        // pending->processing — hai tab poll đồng thời hay worker chạy song song không render đúp.
        $claimed = Generation::where('id', $this->generationId)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if (! $claimed) {
            return; // ai khác đang xử lý, hoặc generation đã completed/failed/cancelled.
        }

        try {
            if ($generation->fresh()->status === 'cancelled') {
                return;
            }

            // Camera + coherent video prompt come from the same Creative Direction so the
            // video matches the rendered image (consolidated image -> video workflow).
            $pr = $generation->promptsHistory?->json_response ?? [];
            // Camera action comes from the selected "Kịch bản quay" (stored in meta.camera); fall back to
            // a neutral tracking shot. Image camera angles (category.camera) must NOT leak into video.
            $camera = (string) ($generation->meta['camera'] ?? '');
            if ($camera === '') {
                $camera = (string) (data_get($pr, 'camera', '') ?: 'slow tracking');
            }
            $prompt = (string) $generation->prompt;
            if (trim($prompt) === '') {
                $prompt = (string) data_get($pr, 'video_prompt_en', 'a fashion model walking on a runway, cinematic fashion catwalk, dynamic fabric motion, professional fashion video');
            }

            $url = $videos->render(
                $prompt,
                (string) $generation->base_image,
                $camera,
                $generation->resolution,
                $generation->duration,
                $generation->id,
                $generation->model,
                $generation->provider,
            );

            $genMeta = (array) ($generation->meta ?? []);
            $generation->update([
                'status' => 'completed',
                'media_url' => $url,
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                // Merge (không thay thế) meta: giữ seed/ref_images/mode/user_prompt… đã lưu lúc tạo —
                // trước đây meta bị ghi đè mất, mất dấu nguồn của video.
                'meta' => array_merge($genMeta, [
                    'type' => 'video',
                    'provider' => $generation->provider,
                    'model' => $generation->model,
                    'resolution' => $generation->resolution,
                    'duration' => $generation->duration,
                    'camera' => $camera,
                    'creative_level' => $pr['creative_level'] ?? null,
                    'adherence' => $pr['adherence'] ?? null,
                    'negative_prompt' => $pr['negative_prompt'] ?? null,
                    'base_image' => $generation->base_image,
                ]),
            ]);
            logger()->info('Video generation completed', [
                'generation_id' => $generation->id, 'provider' => $generation->provider,
                'model' => $generation->model, 'total_s' => round(microtime(true) - $t0, 2),
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
        } catch (\Throwable $e) {
            $generation->update(['status' => 'failed', 'error' => studio_generation_error($e), 'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
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
            'error' => studio_generation_error($e, 'Render bị ngắt (worker timeout/vào failed_jobs): '),
        ]);
        $this->refund($generation);
        logger()->warning('Video generation crashed (job failed)', [
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
