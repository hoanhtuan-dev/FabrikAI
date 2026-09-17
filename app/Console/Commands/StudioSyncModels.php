<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Đồng bộ catalog model QwenCloud mới nhất vào Model Registry (bảng studio_models).
 *
 * Nguồn: studio_model_catalog() trong app/Support/helpers.php — mảng model tích hợp
 * theo qwencloud.com/models (Qwen Image 3.0 Pro, Qwen Image Edit 2511, Wan3.0-Video,
 * Qwen 3.8 Flash/Max…), tập trung Qwen làm provider chính, Flux fallback, Gemini tùy
 * chọn cuối luồng.
 *
 * Idempotent theo (group, provider, model_id): tạo dòng mới, cập nhật name/priority/
 * note của dòng trùng, KHÔNG đụng enabled/api_key_ref admin đã tùy chỉnh, KHÔNG xoá
 * dòng nào. Khi QwenCloud phát hành model mới: cập nhật mảng catalog rồi chạy lại
 * lệnh này — cache registry tự xoá qua model events.
 */
class StudioSyncModels extends Command
{
    protected $signature = 'studio:sync-models';

    protected $description = 'Import the built-in QwenCloud model catalog (latest Qwen models, Flux fallback, optional Gemini) into the studio_models registry. Idempotent.';

    public function handle(): int
    {
        if (! function_exists('studio_sync_model_catalog')) {
            $this->error('studio_sync_model_catalog() not found — helpers.php loaded?');

            return self::FAILURE;
        }

        $result = studio_sync_model_catalog();

        $this->info(sprintf(
            'Đồng bộ catalog model QwenCloud: +%d mới, ~%d cập nhật (registry: %d model).',
            $result['created'],
            $result['updated'],
            $result['total'],
        ));

        return self::SUCCESS;
    }
}
