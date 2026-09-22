<?php

namespace Tests\Feature;

use App\Ai\Gateways\FalImageGateway;
use App\Ai\Providers\FalProvider;
use App\Ai\SdkProviderMap;
use App\Ai\SdkTextEngine;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use RuntimeException;
use Tests\TestCase;

/**
 * HỖ TRỢ fal.ai QUA LARAVEL AI SDK — 2026-09-26.
 *
 * BỐI CẢNH ĐO ĐƯỢC (đây là lý do tệp này tồn tại):
 *   · SDK v0.11.2 KHÔNG có driver fal (16 driver dựng sẵn, không có fal).
 *   · Driver openai-compatible của SDK KHÔNG tạo được ảnh: OpenAiCompatibleProvider chỉ implements
 *     Embedding/Text/Transcription (vendor/laravel/ai/src/Providers/OpenAiCompatibleProvider.php:15)
 *     ⇒ KHÔNG thể trỏ fal vào SDK theo kiểu openai-compatible.
 *   · AiManager kế thừa Illuminate\Support\MultipleInstanceManager, lớp đó có extend($name, Closure)
 *     ⇒ đăng ký driver riêng được TỪ ỨNG DỤNG, không cần vá vendor.
 *
 * Khoá bảy bất biến:
 *   (a) driver 'fal' phân giải ra FalProvider (đi đúng đường công khai imageProvider());
 *   (b) lượt tạo ảnh gửi ĐÚNG payload fal (prompt · num_images · image_size dạng {width,height}) và
 *       ĐÚNG header xác thực ("Key …", không phải "Bearer");
 *   (c) ảnh trả về là base64 + mime thật (hợp đồng GeneratedImage của SDK);
 *   (d) thiếu khoá ⇒ NÉM kèm câu chỉ đường, không im lặng trả ảnh rỗng;
 *   (e) fal bị từ chối (HTTP 4xx/5xx) ⇒ NÉM kèm mã trạng thái, không nuốt lỗi;
 *   (f) SdkProviderMap map fal sang driver 'fal' (KHÔNG phải openai-compatible) và khai models.image;
 *   (g) đường VĂN BẢN phải TỪ CHỐI fal — fal không có endpoint chat kiểu OpenAI.
 */
class FalDriverTest extends TestCase
{
    private const PROVIDER = 'fabrikai_fal_probe';

    protected function setUp(): void
    {
        parent::setUp();

        // Cấu hình đúng hình dạng mà SdkProviderMap::configFor() sinh ra.
        config(['ai.providers.'.self::PROVIDER => [
            'driver' => 'fal',
            'key' => 'sk-fal-test',
            'url' => 'https://queue.fal.run/',
            'models' => ['image' => ['default' => 'fal-ai/flux-1.1-schnell']],
        ]]);
    }

    private function provider(): FalProvider
    {
        $provider = app(AiManager::class)->imageProvider(self::PROVIDER);
        $this->assertInstanceOf(FalProvider::class, $provider);

        return $provider;
    }

    // ── (a) ĐĂNG KÝ DRIVER ─────────────────────────────────────────────────────────────────────

    public function test_the_fal_driver_resolves_to_our_provider(): void
    {
        $provider = $this->provider();

        $this->assertSame('sk-fal-test', $provider->providerCredentials()['key']);
        $this->assertSame('fal-ai/flux-1.1-schnell', $provider->defaultImageModel());
        $this->assertSame(self::PROVIDER, $provider->name());
    }

    /** Gốc API đi từ CẤU HÌNH xuống cổng — "Cài đặt nói gì" phải khớp "mã gọi đâu". */
    public function test_the_gateway_takes_its_base_url_from_config(): void
    {
        $gateway = $this->provider()->imageGateway();
        $this->assertInstanceOf(FalImageGateway::class, $gateway);
        $this->assertSame('https://queue.fal.run/', $gateway->base());
    }

    // ── (b) + (c) LƯỢT CHẠY THẬT QUA HTTP GIẢ ──────────────────────────────────────────────────

    public function test_image_generation_submits_polls_and_returns_a_base64_image(): void
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
        );

        Http::fake([
            // ĐỔI THỨ TỰ: pattern cụ thể phải đứng TRƯỚC pattern rộng (Laravel so khớp theo thứ tự đăng ký).
            'queue.fal.run/*/requests/*/status' => Http::response(['status' => 'COMPLETED'], 200),
            'queue.fal.run/*/requests/*' => Http::response(['images' => [['url' => 'https://cdn.fal.run/out.jpg', 'width' => 1024, 'height' => 1024]]], 200),
            'queue.fal.run/*' => Http::response([
                'request_id' => 'req-1',
                'status_url' => 'https://queue.fal.run/fal-ai/flux-1.1-schnell/requests/req-1/status',
                'response_url' => 'https://queue.fal.run/fal-ai/flux-1.1-schnell/requests/req-1',
            ], 200),
            'cdn.fal.run/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $provider = $this->provider();
        // Nhịp chờ 0 để bài test không phải ngủ thật (cùng đường mã với production).
        $provider->useImageGateway(new FalImageGateway([0, 0], 5, 'https://queue.fal.run/'));

        $response = $provider->image('đầm linen trắng ngà dáng suông', size: '1024x1024');

        // (c) SDK nhận base64, và mime lấy từ chính phản hồi tải ảnh.
        $this->assertSame($bytes, $response->firstImage()->content());
        $this->assertSame('image/jpeg', $response->firstImage()->mime());
        $this->assertSame(self::PROVIDER, $response->meta->provider);
        $this->assertSame('fal-ai/flux-1.1-schnell', $response->meta->model);

        // (b) Payload + xác thực đúng chuẩn fal.
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), 'queue.fal.run/fal-ai/flux-1.1-schnell')) {
                return false;
            }

            return $request['prompt'] === 'đầm linen trắng ngà dáng suông'
                && $request['num_images'] === 1
                // fal nhận image_size là OBJECT {width,height} — KHÔNG phải chuỗi "1024x1024" như OpenAI.
                && $request['image_size'] === ['width' => 1024, 'height' => 1024]
                && $request->hasHeader('Authorization', 'Key sk-fal-test');
        });
    }

    /** Size dạng enum của fal ("square_hd") phải đi thẳng, không bị bọc thành object. */
    public function test_enum_size_passes_through_untouched(): void
    {
        $provider = $this->provider();

        $this->assertSame(['image_size' => ['width' => 1104, 'height' => 1380]], $provider->defaultImageOptions('1104x1380'));
        $this->assertSame(['image_size' => 'square_hd'], $provider->defaultImageOptions('square_hd'));
        $this->assertSame([], $provider->defaultImageOptions(null));
    }

    /** Model khai thiếu tiền tố "fal-ai/" vẫn phải ra đúng slug của fal. */
    public function test_model_slug_is_normalised(): void
    {
        $this->assertSame('fal-ai/flux-1.1-schnell', FalImageGateway::modelSlug('flux-1.1-schnell'));
        $this->assertSame('fal-ai/flux-1.1-schnell', FalImageGateway::modelSlug('fal-ai/flux-1.1-schnell'));
        $this->assertSame('fal-ai/fal-ai/x', FalImageGateway::modelSlug('/fal-ai/x'));
    }

    // ── (d) THIẾU KHOÁ ─────────────────────────────────────────────────────────────────────────

    public function test_missing_key_throws_a_clear_error(): void
    {
        config(['ai.providers.'.self::PROVIDER.'.key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/thiếu khoá API/');

        $this->provider()->image('áo sơ mi linen');
    }

    // ── (e) fal TỪ CHỐI ────────────────────────────────────────────────────────────────────────

    public function test_a_rejected_submit_surfaces_the_status_code(): void
    {
        Http::fake(['queue.fal.run/*' => Http::response(['detail' => 'Invalid api key'], 401)]);

        $provider = $this->provider();
        $provider->useImageGateway(new FalImageGateway([0, 0], 5, 'https://queue.fal.run/'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 401/');

        $provider->image('áo sơ mi linen');
    }

    public function test_a_failed_task_surfaces_as_an_error(): void
    {
        Http::fake([
            'queue.fal.run/*/requests/*/status' => Http::response(['status' => 'FAILED'], 200),
            'queue.fal.run/*' => Http::response(['request_id' => 'req-x'], 200),
        ]);

        $provider = $this->provider();
        $provider->useImageGateway(new FalImageGateway([0, 0], 5, 'https://queue.fal.run/'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/lượt chạy hỏng/');

        $provider->image('áo sơ mi linen');
    }

    // ── (f) + (g) CẦU NỐI app/Ai ───────────────────────────────────────────────────────────────

    public function test_the_bridge_routes_fal_to_its_own_driver_and_keeps_it_out_of_the_text_path(): void
    {
        $fal = ['provider' => 'fal', 'model' => 'flux-1.1-schnell', 'transport' => 'openai', 'base' => ''];

        $this->assertSame(SdkProviderMap::DRIVER_FAL, SdkProviderMap::driverFor($fal), 'fal PHẢI có driver riêng.');
        $this->assertSame(FalImageGateway::BASE, SdkProviderMap::baseFor($fal, 'sk-x'));

        $config = SdkProviderMap::configFor($fal, 'sk-x');
        $this->assertNotNull($config, 'fal phải dựng được cấu hình — trước đây base rỗng nên bị bỏ qua.');
        $this->assertSame('fal', $config['driver']);
        // Model giữ NGUYÊN như registry khai (admin có thể gõ thiếu tiền tố "fal-ai/"): việc chuẩn hoá
        // slug nằm ở MỘT chỗ là FalImageGateway::modelSlug(), không rải thêm một điểm chuẩn hoá thứ hai.
        $this->assertSame('flux-1.1-schnell', $config['models']['image']['default']);
        $this->assertArrayNotHasKey('text', $config['models'], 'fal là nhà cung cấp ẢNH, không phải văn bản.');

        // (g) fal KHÔNG có gateway văn bản ⇒ lớp text phải từ chối, không được nhận rồi hỏng lúc chạy.
        $this->assertFalse(app(SdkTextEngine::class)->supports($fal));
    }

    /** Không được đổi hành vi của đường cũ: provider thường vẫn là openai-compatible + models.text. */
    public function test_ordinary_providers_are_untouched(): void
    {
        $plain = ['provider' => 'deepseek', 'model' => 'deepseek-chat', 'transport' => 'openai', 'base' => 'https://api.deepseek.com'];

        $this->assertSame('openai-compatible', SdkProviderMap::driverFor($plain));
        $this->assertSame('https://api.deepseek.com', SdkProviderMap::baseFor($plain, 'sk-x'));
        $this->assertSame('text', array_key_first(SdkProviderMap::configFor($plain, 'sk-x')['models']));
        $this->assertTrue(app(SdkTextEngine::class)->supports($plain));
    }
}
