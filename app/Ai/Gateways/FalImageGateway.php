<?php

namespace App\Ai\Gateways;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use RuntimeException;

/**
 * CỔNG TẠO ẢNH QUA fal.ai CHO LARAVEL AI SDK (2026-09-26).
 *
 * VÌ SAO CẦN LỚP NÀY: SDK không có driver fal, và driver openai-compatible của SDK KHÔNG tạo được ảnh
 * (OpenAiCompatibleProvider chỉ implements Embedding/Text/Transcription — xem vendor/laravel/ai). Nên
 * không thể trỏ fal vào SDK theo kiểu openai-compatible. Muốn fal chạy qua SDK thì phải có cổng riêng.
 *
 * GIAO THỨC ĐÃ ĐO ĐƯỢC (chép từ ImageAIService::tryFal — đường đang chạy thật trên production, KHÔNG
 * đoán lại): API hàng đợi https://queue.fal.run
 *   POST {base}/{model}                      → {"request_id", "status_url", "response_url"}
 *   GET  {base}/{model}/requests/{id}/status → {"status": IN_QUEUE|IN_PROGRESS|COMPLETED}
 *   GET  {base}/{model}/requests/{id}        → {"images": [{"url", "width", "height"}]}
 *   Auth: "Authorization: Key <FAL_KEY>" (tiền tố literal "Key " — KHÔNG phải "Bearer").
 *   Model id chuẩn hoá thành "fal-ai/<id>" khi thiếu tiền tố.
 *
 * KHÁC BIỆT CÓ CHỦ Ý so với ImageAIService: lớp đó trả null để nhường candidate kế tiếp trong chuỗi ưu
 * tiên của ứng dụng. Ở SDK, hợp đồng ImageGateway là TRẢ ImageResponse hoặc NÉM — nuốt lỗi thành null
 * sẽ khiến nơi gọi tưởng lượt chạy thành công mà không có ảnh nào.
 */
class FalImageGateway implements ImageGateway
{
    /** Gốc của API hàng đợi fal — một hằng, không rải chuỗi ở ba chỗ. */
    public const BASE = 'https://queue.fal.run/';

    /**
     * Nhịp chờ giữa các lượt hỏi trạng thái (giây).
     *
     * Tham số hoá để TEST chạy được mà không phải ngủ thật: truyền [0, 0] cho ra cùng đường mã với
     * production nhưng tức thì. Bài học từ chính repo này: thứ không test được là thứ sẽ hỏng lúc deploy.
     *
     * @param  list<int>  $pollWaits
     */
    public function __construct(
        private readonly array $pollWaits = [1, 3],
        private readonly int $deadlineSeconds = 150,
        // Gốc API lấy từ CẤU HÌNH provider khi có (SdkProviderMap đặt 'url'), mặc định là fal thật.
        // Nhờ vậy "Cài đặt nói gì" và "mã gọi đâu" không thể lệch nhau, và test trỏ được sang host giả.
        private readonly ?string $base = null,
    ) {}

    /** Gốc API đang dùng thật của lượt chạy này. */
    public function base(): string
    {
        return $this->base !== null && $this->base !== '' ? rtrim($this->base, '/').'/' : self::BASE;
    }

    /**
     * Tạo ảnh qua fal và trả về SDK.
     *
     * @param  array<Image>  $attachments
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        $key = (string) ($provider->providerCredentials()['key'] ?? '');
        if ($key === '') {
            throw new RuntimeException('fal.ai: thiếu khoá API (FAL_KEY) — thêm ở Cài đặt → Quản lý API.');
        }

        $base = $this->base().self::modelSlug($model);
        $auth = ['Authorization' => 'Key '.$key];

        $requestId = $this->submit($base, $auth, $prompt, $provider->defaultImageOptions($size, $quality), $timeout);
        $url = $this->awaitResult($base, $auth, $requestId);
        [$base64, $mime] = $this->download($url, $timeout);

        return new ImageResponse(
            new Collection([new GeneratedImage($base64, $mime)]),
            new Usage,
            new Meta($provider->name(), $model),
        );
    }

    /** fal nhận id dạng "fal-ai/<slug>"; registry của ta có thể khai thiếu tiền tố. */
    public static function modelSlug(string $model): string
    {
        return str_starts_with($model, 'fal-ai/') ? $model : 'fal-ai/'.ltrim($model, '/');
    }

    /**
     * @param  array<string, string>  $auth
     * @param  array<string, mixed>  $options
     */
    private function submit(string $base, array $auth, string $prompt, array $options, ?int $timeout): string
    {
        $body = array_filter(
            ['prompt' => $prompt, 'num_images' => 1] + $options,
            fn ($v) => $v !== null && $v !== '',
        );

        $response = Http::withHeaders($auth)->timeout($timeout ?? 60)->post($base, $body);

        if ($response->failed()) {
            throw new RuntimeException(
                'fal.ai từ chối lượt tạo ảnh (HTTP '.$response->status().'): '.Str::limit((string) $response->body(), 240)
            );
        }

        $requestId = (string) data_get($response->json(), 'request_id', '');
        if ($requestId === '') {
            throw new RuntimeException('fal.ai không trả về request_id — không theo dõi được lượt chạy.');
        }

        return $requestId;
    }

    /** @param  array<string, string>  $auth */
    private function awaitResult(string $base, array $auth, string $requestId): string
    {
        $deadline = microtime(true) + $this->deadlineSeconds;
        $waits = $this->pollWaits;
        $last = (int) (end($waits) ?: 3);

        while (microtime(true) < $deadline) {
            $wait = array_shift($waits);
            $wait = $wait === null ? $last : (int) $wait;
            if ($wait > 0) {
                sleep($wait);
            }

            $query = Http::withHeaders($auth)->timeout(30)->get($base.'/requests/'.$requestId.'/status');
            if ($query->failed()) {
                throw new RuntimeException('fal.ai: không đọc được trạng thái lượt chạy (HTTP '.$query->status().').');
            }

            $status = (string) data_get($query->json(), 'status', '');

            if ($status === 'COMPLETED') {
                $result = Http::withHeaders($auth)->timeout(60)->get($base.'/requests/'.$requestId);
                $url = (string) data_get($result->json(), 'images.0.url', '');
                if ($url === '') {
                    throw new RuntimeException('fal.ai báo xong nhưng không trả về đường dẫn ảnh.');
                }

                return $url;
            }

            if (in_array($status, ['FAILED', 'ERROR'], true)) {
                throw new RuntimeException('fal.ai: lượt chạy hỏng — '.Str::limit((string) $query->body(), 240));
            }
        }

        throw new RuntimeException('fal.ai: quá hạn chờ kết quả ('.$this->deadlineSeconds.'s, request '.$requestId.').');
    }

    /**
     * Tải ảnh về và mã hoá base64 — GeneratedImage của SDK nhận base64, KHÔNG nhận URL.
     *
     * @return array{0: string, 1: string}
     */
    private function download(string $url, ?int $timeout): array
    {
        $response = Http::timeout($timeout ?? 60)->get($url);

        if ($response->failed() || $response->body() === '') {
            throw new RuntimeException('fal.ai: không tải được ảnh kết quả (HTTP '.$response->status().').');
        }

        $mime = (string) ($response->header('Content-Type') ?: 'image/jpeg');
        $mime = str_contains($mime, '/') ? explode(';', $mime)[0] : 'image/jpeg';

        return [base64_encode($response->body()), $mime];
    }
}
