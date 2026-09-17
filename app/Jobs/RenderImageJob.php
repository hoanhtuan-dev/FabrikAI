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

            // [M-d — 2026-09-17] CAS: nếu người dùng đã Huỷ trong lúc job chạy (hoặc đường khác đã
            // kết thúc row) thì KHÔNG ghi đè 'completed'. Trước đây update() vô điều kiện làm "hồi
            // sinh" row đã cancelled trong khi credit đã được hoàn ⇒ trạng thái và tiền lệch nhau.
            // [Đợt 0.3] NÓI THẬT về ảnh DEMO: khi chưa có API key, service trả ảnh mẫu (hoặc với đường
            // EDIT thì trả lại CHÍNH ẢNH GỐC) mà vẫn đi tới đây với trạng thái 'completed'. Không có
            // cờ này thì người dùng bấm "Sửa ảnh", nhận lại ảnh cũ, và app báo thành công.
            $stubReason = $images->lastStubReason();

            $claimed = studio_claim_generation($generation, ['processing'], [
                'status' => 'completed',
                'media_url' => $url,
                'is_demo' => $stubReason !== null,
                'demo_reason' => $stubReason,
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
                'provider' => $usedProvider,
                'model' => $usedModel,
                'meta' => array_merge($genMeta, [
                    'type' => 'image',
                    'is_demo' => $stubReason !== null,
                    'demo_reason' => $stubReason,
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

            if (! $claimed) {
                logger()->warning('Image generation finished but the row had already left processing — result discarded', [
                    'generation_id' => $generation->id,
                    'status_now' => $generation->fresh()?->status,
                ]);

                return; // credit đã được xử lý ở đường thắng CAS (cancel/failStuck/job khác)
            }

            logger()->info('Image generation completed', [
                'generation_id' => $generation->id, 'provider' => $usedProvider,
                'model' => $usedModel, 'requested_provider' => $generation->provider,
                'requested_model' => $generation->model, 'total_s' => round(microtime(true) - $t0, 2),
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
        } catch (\Throwable $e) {
            // [M-c/M-d] Một đường duy nhất: CAS + hoàn credit ĐÚNG MỘT LẦN (kể cả khi user vừa Huỷ).
            studio_finalize_generation($generation, 'failed', ['processing'], studio_generation_error($e), [
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
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

        studio_finalize_generation($generation, 'failed', ['pending', 'processing'], studio_generation_error($e, 'Render bị ngắt (worker timeout/vào failed_jobs): '));
        logger()->warning('Image generation crashed (job failed)', [
            'generation_id' => $this->generationId, 'error' => $e->getMessage(),
        ]);
    }

    // [M-c — 2026-09-17] refund() cục bộ đã bị GỠ: hoàn credit nay chỉ nằm trong
    // studio_finalize_generation() (helpers.php) để không thể tồn tại đường hoàn thứ hai thiếu CAS.
}
