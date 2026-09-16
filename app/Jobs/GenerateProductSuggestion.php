<?php

namespace App\Jobs;

use App\Services\ProductAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the product-content AI suggestion (vision + text) in a background queue
 * worker so the admin request never blocks on (potentially slow / rate-limited)
 * LLM calls on shared hosting — avoiding gateway 504 timeouts.
 */
class GenerateProductSuggestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public string $token,
        public array $input,
        public ?string $imagePath = null,
        public bool $force = false,
    ) {
    }

    public function handle(ProductAIService $service): void
    {
        @set_time_limit(300);

        if ($this->imagePath && is_file($this->imagePath)) {
            $result = $service->generateFromImage($this->input, $this->imagePath, $this->force);
        } else {
            $result = $service->generate($this->input, null);
        }
        $result['source'] ??= 'stub';

        Cache::put('product_ai:'.$this->token, $result, 600);
    }

    /**
     * Fail không âm thầm: nếu AI ném (hết hạn mức / lỗi mạng), ghi kết quả lỗi vào cache token
     * để aiSuggestPoll dừng "processing" vô hạn và hiển thị lỗi thay vì treo spinner mãi.
     * Dữ liệu cùng dạng (object) với kết quả thành công, thêm trường `error` để Vue phân nhánh.
     */
    public function failed(\Throwable $e): void
    {
        Cache::put('product_ai:'.$this->token, [
            'error' => 'AI suggestion failed: '.$e->getMessage(),
            'source' => 'error',
        ], 600);
    }
}
