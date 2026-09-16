<?php

namespace Tests\Feature;

use App\Services\ImageAIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImageFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** Real PNG files created for edit tests (imageDataUri/storeRemoteImage read the filesystem). */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $abs) {
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    /** Create a real 64x64 PNG under storage/app/public; returns [relative, absolute] paths. */
    private function makeRealPng(string $name): array
    {
        $rel = 'studio/'.$name;
        $abs = storage_path('app/public/'.$rel);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        $im = imagecreatetruecolor(64, 64);
        imagepng($im, $abs);
        imagedestroy($im);
        $this->tempFiles[] = $abs;

        return [$rel, $abs];
    }

    /** Fake DashScope edit success trỏ tới URL https giả lập (sau vá S3, file:// bị chặn đúng). */
    private function editSuccessResponse(string $absFile): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'output' => ['choices' => [['message' => ['content' => [
                ['image' => 'https://cdn.test/edit-out.png'],
            ]]]]],
        ], 200);
    }

    /** Fake CDN trả bytes ảnh thật cho URL edit-out (khớp SSRF guard mới — chỉ http/https). */
    private function cdnFake(string $absFile): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response((string) file_get_contents($absFile), 200);
    }

    public function test_qwen_key_failure_falls_back_to_gemini(): void
    {
        config(['studio.image_provider' => 'qwen']);
        config(['studio.qwen_key' => 'sk-bad-key']);
        config(['studio.gemini_key' => 'gem-good-key']);

        Http::fake([
            'dashscope-intl.aliyuncs.com*' => Http::response(['error' => ['message' => 'InvalidApiKey']], 401),
            'token-plan*' => Http::response(['error' => ['message' => 'InvalidApiKey']], 401),
            'generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('fake-image-bytes')]],
                    ]],
                ]],
            ], 200),
        ]);

        $service = app(ImageAIService::class);
        $url = $service->generate('photo of a red silk dress', null, null, null, null);

        $this->assertIsString($url);
        $this->assertStringStartsWith('/storage/studio/', $url);
        $this->assertSame('gemini', $service->lastProvider());
        $this->assertSame('gemini-2.5-flash-image', $service->lastModel());

        Storage::disk('public')->assertExists(str_replace('/storage/', '', $url));
    }

    public function test_all_providers_fail_throws_clear_message(): void
    {
        config(['studio.image_provider' => 'qwen']);
        config(['studio.qwen_key' => 'sk-bad-key']);
        config(['studio.gemini_key' => null]);

        Http::fake([
            'dashscope-intl.aliyuncs.com*' => Http::response(['error' => ['message' => 'InvalidApiKey']], 401),
            'token-plan*' => Http::response(['error' => ['message' => 'InvalidApiKey']], 401),
        ]);

        $service = app(ImageAIService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/InvalidApiKey|Khoá API|Pay-As-You-Go/');

        $service->generate('photo of a red silk dress', null, null, null, null);
    }

    public function test_model_check_uses_paygo_key_even_when_plan_has_higher_priority(): void
    {
        config(['studio.image_provider' => 'qwen']);
        \App\Models\StudioModel::create([
            'group' => 'image', 'name' => 'Qwen Image 3.0 Pro', 'provider' => 'qwen',
            'model_id' => 'qwen-image-3.0-pro', 'api_key_ref' => 'qwen', 'priority' => 8, 'enabled' => true,
        ]);
        // The Token/Coding-Plan key has the HIGHER priority, but it is FORBIDDEN for image generation
        // (plan host has no image model), so the check must still pick the Pay-As-You-Go key.
        \App\Models\StudioApiKey::create([
            'provider' => 'qwen', 'label' => 'plan', 'value' => 'sk-sp-TESTPLANKEY', 'priority' => 10, 'enabled' => true, 'scopes' => ['*'],
        ]);
        \App\Models\StudioApiKey::create([
            'provider' => 'qwen', 'label' => 'paygo', 'value' => 'sk-ws-TESTPAYGO', 'priority' => 5, 'enabled' => true, 'scopes' => ['*'],
        ]);

        $admin = \App\Models\User::where('email', 'admin@fabrikai.shop')->first();
        $this->actingAs($admin);

        $model = \App\Models\StudioModel::first();
        $res = $this->getJson('/api/models/'.$model->id.'/test')->assertOk()->json();

        // The checked model's key must be the Pay-As-You-Go one, NOT the higher-priority plan key.
        $this->assertSame('qwen', $res['provider']);
        $this->assertSame('qwen-image-3.0-pro', $res['model_id']);
        $this->assertStringStartsWith('sk-ws', (string) $res['key_prefix']);
        $this->assertStringContainsString('dashscope-intl', (string) $res['base_url']);
        $this->assertStringStartsWith('sk-ws', (string) $res['keys'][0]);
    }

    public function test_model_check_reports_no_usable_key_when_only_plan_registered(): void
    {
        config(['studio.image_provider' => 'qwen']);
        \App\Models\StudioModel::create([
            'group' => 'image', 'name' => 'Qwen Image 3.0 Pro', 'provider' => 'qwen',
            'model_id' => 'qwen-image-3.0-pro', 'api_key_ref' => 'qwen', 'priority' => 8, 'enabled' => true,
        ]);
        // Only a Token/Coding Plan key exists -> not usable for image, so no key is selected.
        \App\Models\StudioApiKey::create([
            'provider' => 'qwen', 'label' => 'plan', 'value' => 'sk-sp-TESTPLANKEY', 'priority' => 10, 'enabled' => true, 'scopes' => ['*'],
        ]);

        $admin = \App\Models\User::where('email', 'admin@fabrikai.shop')->first();
        $this->actingAs($admin);

        $model = \App\Models\StudioModel::first();
        $res = $this->getJson('/api/models/'.$model->id.'/test')->assertOk()->json();

        $this->assertNull($res['key_prefix']);
        $this->assertSame('', (string) $res['base_url']);
        $this->assertStringContainsString('Chưa có KEY', (string) $res['note']);
    }

    public function test_inpaint_uses_requested_edit_capable_image_model(): void
    {
        // Sửa ảnh với model tạo ảnh qwen-image-3.0-pro (do người dùng chọn) phải gửi ĐÚNG
        // model đó tới DashScope thay vì luôn dùng qwen_edit_model cấu hình.
        config(['studio.image_provider' => 'qwen']);
        config(['studio.qwen_key' => 'sk-ws-test-paygo']);

        [$srcRel] = $this->makeRealPng('edit-src-'.uniqid().'.png');
        [, $outAbs] = $this->makeRealPng('edit-out-'.uniqid().'.png');

        Http::fake([
            'dashscope-intl.aliyuncs.com*' => $this->editSuccessResponse($outAbs),
            'cdn.test*' => $this->cdnFake($outAbs),
        ]);

        $service = app(ImageAIService::class);
        $url = $service->generate(
            'change the dress color to red',
            '/storage/'.$srcRel,
            null, null, null, null,
            'qwen', 'qwen-image-3.0-pro',
        );

        $this->assertIsString($url);
        $this->assertSame('qwen-image-3.0-pro', $service->lastModel());
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'multimodal-generation/generation')
                && $request['model'] === 'qwen-image-3.0-pro';
        });
    }

    public function test_edit_fails_over_to_next_key_on_quota_error(): void
    {
        // Key 1 hết hạn mức (429 AllocationQuota) → phải thử key kế tiếp (tài khoản khác)
        // thay vì dừng ở key đầu như trước đây (tạo ảnh 2D vốn đã thử cả chuỗi key).
        config(['studio.image_provider' => 'qwen']);
        config(['studio.qwen_key' => 'sk-ws-key-one']);
        config(['studio.qwen_edit_key' => 'sk-ws-key-two']);

        [$srcRel] = $this->makeRealPng('failover-src-'.uniqid().'.png');
        [, $outAbs] = $this->makeRealPng('failover-out-'.uniqid().'.png');

        Http::fake(function ($request) use ($outAbs) {
            if (str_contains($request->url(), 'cdn.test')) {
                return $this->cdnFake($outAbs);
            }
            $auth = (string) ($request->header('Authorization')[0] ?? '');
            if (str_contains($auth, 'sk-ws-key-one')) {
                return Http::response(['code' => 'Throttling.AllocationQuota', 'message' => 'Allocation quota exhausted'], 429);
            }

            return $this->editSuccessResponse($outAbs);
        });

        $service = app(ImageAIService::class);
        $url = $service->generate(
            'edit the dress',
            '/storage/'.$srcRel,
            null, null, null, null,
            'qwen', 'qwen-image-3.0-pro',
        );

        $this->assertIsString($url);
        $this->assertSame('qwen-image-3.0-pro', $service->lastModel());
        // Cả 2 key đều được gọi: key 1 (429) rồi key 2 (thành công); +1 request tải ảnh kết
        // quả từ URL provider trả về (sau vá S3 việc tải đi qua Http client nên đếm luôn).
        Http::assertSentCount(3);
        Http::assertSent(function ($request) {
            return str_contains((string) ($request->header('Authorization')[0] ?? ''), 'sk-ws-key-two')
                && $request['model'] === 'qwen-image-3.0-pro';
        });
    }

    public function test_inpaint_falls_back_to_configured_edit_model_on_quota(): void
    {
        // Model được chọn (qwen-image-3.0-pro) hết hạn mức ở MỌI key → tự fallback sang
        // model Qwen Edit cấu hình (qwen-image-edit) — hạn mức DashScope tính theo model.
        config(['studio.image_provider' => 'qwen']);
        config(['studio.qwen_key' => 'sk-ws-test-paygo']);

        [$srcRel] = $this->makeRealPng('fallback-src-'.uniqid().'.png');
        [, $outAbs] = $this->makeRealPng('fallback-out-'.uniqid().'.png');

        Http::fake(function ($request) use ($outAbs) {
            if (str_contains($request->url(), 'cdn.test')) {
                return $this->cdnFake($outAbs);
            }
            if (($request->data()['model'] ?? '') === 'qwen-image-3.0-pro') {
                return Http::response(['code' => 'Throttling.AllocationQuota', 'message' => 'quota exhausted'], 429);
            }

            return $this->editSuccessResponse($outAbs);
        });

        $service = app(ImageAIService::class);
        $url = $service->generate(
            'change the color',
            '/storage/'.$srcRel,
            null, null, null, null,
            'qwen', 'qwen-image-3.0-pro',
        );

        $this->assertIsString($url);
        // Kết quả do model edit cấu hình tạo ra, không phải model đã hết hạn mức.
        $this->assertSame('qwen-image-edit', $service->lastModel());
    }

    public function test_inpaint_error_lists_all_tried_models_when_quota_exhausted(): void
    {
        // Mọi model/key đều hết hạn mức → lỗi phải nêu rõ chuỗi model đã thử để dễ chẩn đoán.
        config(['studio.image_provider' => 'qwen']);
        config(['studio.qwen_key' => 'sk-ws-test-paygo']);

        [$srcRel] = $this->makeRealPng('allfail-src-'.uniqid().'.png');

        Http::fake([
            'dashscope-intl.aliyuncs.com*' => Http::response(['code' => 'Throttling.AllocationQuota', 'message' => 'quota exhausted'], 429),
        ]);

        $service = app(ImageAIService::class);

        try {
            $service->generate(
                'edit something',
                '/storage/'.$srcRel,
                null, null, null, null,
                'qwen', 'qwen-image-3.0-pro',
            );
            $this->fail('Expected RuntimeException for exhausted quota.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Hạn mức', $e->getMessage());
            $this->assertStringContainsString('Đã thử: qwen-image-3.0-pro → qwen-image-edit', $e->getMessage());
        }
    }
}
