<?php

namespace App\Jobs;

use App\Models\BrandLearning;
use App\Services\AiModelGateway;
use App\Services\BrandLearningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RÚT KINH NGHIỆM cho một quyết định duyệt/loại ảnh (ReasoningBank — GĐ2 trí nhớ dài hạn).
 *
 * Vì sao là job nền: record() chạy trong request duyệt ảnh; gọi model rút kinh nghiệm mất vài giây nên
 * không được chặn người dùng. Job tự bỏ qua khi chưa có model vai agent_reflect (gateway trả null) —
 * khi đó brief vẫn dùng prompt thô như cũ, không có gì vỡ.
 *
 * Idempotent: hàng đã có lesson thì không gọi lại; một quyết định chỉ rút một lần.
 */
class ReflectBrandMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $learningId) {}

    public function handle(AiModelGateway $gateway, BrandLearningService $learning): void
    {
        $row = BrandLearning::find($this->learningId);
        if ($row === null || $row->lesson !== null) {
            return;
        }

        $learning->reflectRecord($row, $gateway);
    }
}
