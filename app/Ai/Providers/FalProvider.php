<?php

namespace App\Ai\Providers;

use App\Ai\Gateways\FalImageGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Providers\Concerns\GeneratesImages;
use Laravel\Ai\Providers\Concerns\HasImageGateway;
use Laravel\Ai\Providers\Provider;

/**
 * NHÀ CUNG CẤP fal.ai CHO LARAVEL AI SDK (2026-09-26).
 *
 * VÌ SAO LÀ LỚP CỦA ỨNG DỤNG, KHÔNG PHẢI VÁ VENDOR: SDK v0.11.2 không có driver fal (16 driver dựng sẵn:
 * anthropic · azure · bedrock · cohere · deepseek · eleven · gemini · groq · jina · mistral · ollama ·
 * openai · openai-compatible · openrouter · voyageai · xai). Nhưng AiManager kế thừa
 * Illuminate\Support\MultipleInstanceManager, mà lớp đó có extend($name, Closure) — cơ chế CHÍNH THỨC
 * để ứng dụng đăng ký driver riêng. Nhờ vậy hỗ trợ fal KHÔNG cần sửa vendor (sửa vendor là mất khi
 * composer update, và trên máy chủ này composer bị chặn proc_open nên càng không nên phụ thuộc).
 *
 * CHỈ KHAI Ở NHÓM ẢNH, KHÔNG KHAI LÀM PROVIDER VĂN BẢN: fal không có endpoint chat kiểu OpenAI — API của
 * nó là hàng đợi queue.fal.run. Đăng ký fal như một TextProvider sẽ tạo ra một đường gọi SAI mà không ai
 * phát hiện cho tới lúc chạy thật.
 *
 * Vì sao constructor KHÔNG gọi parent: lớp nền Provider đòi một Gateway văn bản, mà fal không có gateway
 * văn bản nào để đưa vào. Các provider dựng sẵn của SDK cũng override y hệt (xem
 * vendor/laravel/ai/src/Providers/OpenAiCompatibleProvider.php:25).
 */
class FalProvider extends Provider implements ImageProvider
{
    use GeneratesImages;
    use HasImageGateway;

    /** Model mặc định khi Cài đặt không khai model ảnh cho provider này. */
    public const DEFAULT_IMAGE_MODEL = 'fal-ai/flux-1.1-schnell';

    public function __construct(protected array $config, protected Dispatcher $events) {}

    public function providerCredentials(): array
    {
        return ['key' => $this->config['key'] ?? null];
    }

    public function imageGateway(): ImageGateway
    {
        // Gốc API đi từ CẤU HÌNH xuống cổng (SdkProviderMap đặt 'url') — một nguồn, không hardcode hai nơi.
        return $this->imageGateway ??= new FalImageGateway(base: $this->config['url'] ?? null);
    }

    public function defaultImageModel(): string
    {
        return (string) ($this->config['models']['image']['default'] ?? self::DEFAULT_IMAGE_MODEL);
    }

    /**
     * Tuỳ chọn gửi kèm lượt tạo ảnh.
     *
     * fal nhận `image_size` là ENUM ("square_hd") HOẶC object {width,height} — KHÔNG phải chuỗi "WxH"
     * như OpenAI Images. SDK chuyển size xuống dạng chuỗi, nên phải đổi ở đây; gửi nguyên "1024x1024"
     * là fal trả lỗi tham số.
     *
     * @param  'low'|'medium'|'high'|null  $quality
     * @return array<string, mixed>
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        if (! is_string($size) || trim($size) === '') {
            return [];
        }

        $size = trim($size);
        if (preg_match('/^(\d+)\s*x\s*(\d+)$/i', $size, $m) === 1) {
            return ['image_size' => ['width' => (int) $m[1], 'height' => (int) $m[2]]];
        }

        // Enum của fal ("square_hd", "portrait_16_9"…) đi thẳng.
        return ['image_size' => $size];
    }
}
