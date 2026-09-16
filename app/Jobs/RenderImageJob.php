<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\ImageAIService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenderImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /** One attempt: a render is expensive — timeouts/fatals are cleaned up by failed(), not retried. */
    public int $tries = 1;

    /** A timed-out job goes straight to failed_jobs instead of a silent retry that double-bills providers. */
    public bool $failOnTimeout = true;

    public function __construct(public int $generationId) {}

    public function handle(ImageAIService $images): void
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

            // (Face sync "Khuôn mặt mẫu" was removed — no vision description step, keeps generation fast.)
            $prompt = (string) $generation->prompt;

            $refImages = (array) ($generation->meta['ref_images'] ?? []);
            $faceRef = $generation->meta['face_ref'] ?? null;
            $url = $images->generate(
                $prompt,
                $generation->base_image,
                $generation->mask_image,
                $generation->resolution,
                $generation->ratio,
                $faceRef,
                $generation->provider,
                $generation->model,
                $generation->meta['negative_prompt'] ?? null,
                $refImages,
                // mode='refgen' (card "Tạo ảnh mới từ ảnh mẫu"): i2i qua model sinh ảnh, KHÔNG edit.
                $generation->meta['mode'] ?? null,
                // seed (gieo quẻ): để tạo ảnh nhất quán giữa các lần chạy
                isset($generation->meta['seed']) ? (int) $generation->meta['seed'] : null,
            );

            // DEEP REDESIGN (region): AI đã sửa trên CROP — paste lại vào ẢNH GỐC đúng vị trí
            // (xóa/thay vùng chọn chính xác, không lệch tọa độ; feather đã làm trong composite).
            $genMeta = (array) ($generation->meta ?? []);
            if (isset($genMeta['region_op']) && $genMeta['region_op']) {
                $pasted = $images->pasteRegionEdit($url, $genMeta);
                if ($pasted) { $url = $pasted; }
            }

            // Xóa nền AI: hậu kỳ cắt nền thành trong suốt (PNG alpha).
            // Có mask lasso → cắt theo mask; KHÔNG có mask → tự nhận diện nền trắng (auto).
            // Lỗi → fallback ảnh không trong suốt.
            if (($genMeta['mode'] ?? '') === 'remove-bg') {
                try {
                    $transparent = $generation->mask_image
                        ? $images->applyTransparentBackground($url, $generation->mask_image)
                        : $images->applyAutoTransparentBackground($url);
                    if ($transparent) { $url = $transparent; }
                } catch (\Throwable $e) {
                    logger()->warning('remove-bg transparent post-processing failed', ['error' => $e->getMessage()]);
                }
            }

            // NOTE: Face sync ("Đồng bộ khuôn mặt") was removed from the UI. We no longer do a second
            // applyFace edit pass here — it doubled the edit time (2 edits instead of 1). Keep the edit
            // a single pass so phẫu thuật ảnh is as fast as Thay Đổi Người Mẫu.


            $pr = (array) ($generation->promptsHistory?->json_response ?? []);
            // Record the provider/model that actually produced the image — which may differ from the
            // requested one when the generation fell back to another provider after a key/quota failure.
            $usedProvider = $images->lastProvider() ?: $generation->provider;
            $usedModel = $images->lastModel() ?: $generation->model;

            $generation->update([
                'status' => 'completed',
                'media_url' => $url,
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                'provider' => $usedProvider,
                'model' => $usedModel,
                'meta' => array_merge($genMeta, [
                    'type' => 'image',
                    'provider' => $usedProvider,
                    'model' => $usedModel,
                    'requested_provider' => $generation->provider,
                    'requested_model' => $generation->model,
                    'resolution' => $generation->resolution,
                    'ratio' => $generation->ratio,
                    'creative_level' => $pr['creative_level'] ?? null,
                    'adherence' => $pr['adherence'] ?? null,
                    'negative_prompt' => $pr['negative_prompt'] ?? null,
                ]),
            ]);
            logger()->info('Image generation completed', [
                'generation_id' => $generation->id, 'provider' => $usedProvider,
                'model' => $usedModel, 'requested_provider' => $generation->provider,
                'requested_model' => $generation->model, 'total_s' => round(microtime(true) - $t0, 2),
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
        } catch (\Throwable $e) {
            $generation->update(['status' => 'failed', 'error' => $e->getMessage(), 'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000)]);
            $this->refund($generation);
            logger()->warning('Image generation failed', [
                'generation_id' => $generation->id, 'total_s' => round(microtime(true) - $t0, 2),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Queue-worker safety net: fires when handle() never finishes (worker timeout via
     * pcntl, OOM, process kill) — the exception escapes handle()'s catch. Without this,
     * the row sticks in 'processing' until the owner polls show() again. Idempotent: skips
     * rows already completed/failed/cancelled (their refund already happened in handle()).
     */
    public function failed(\Throwable $e): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || ! in_array($generation->status, ['pending', 'processing'], true)) {
            return;
        }

        $generation->update([
            'status' => 'failed',
            'error' => 'Render bị ngắt (worker timeout/vào failed_jobs): '.$e->getMessage(),
        ]);
        $this->refund($generation);
        logger()->warning('Image generation crashed (job failed)', [
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
