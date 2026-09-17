<?php

namespace Tests\Feature;

use App\Jobs\RenderImageJob;
use App\Models\Generation;
use App\Models\User;
use App\Services\ImageAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [Đợt 0.3 — 2026-09-17] NÓI THẬT VỀ CHẾ ĐỘ DEMO.
 *
 * Lỗi gốc (STUDIO_REVIEW_DEEPDIVE §3.2 S3): khi CHƯA cấu hình API key, ImageAIService::generate()
 * KHÔNG ném lỗi mà trả về ảnh mẫu — và với đường EDIT thì trả lại CHÍNH ẢNH GỐC (copySample) — rồi
 * generation được ghi 'completed'. Người dùng bấm "Sửa ảnh", nhận lại đúng ảnh cũ, thấy báo thành
 * công. Không test nào bắt được vì mọi thứ "chạy đúng": không exception, không log lỗi.
 *
 * Bất biến được khoá ở đây:
 *   (a) service PHẢI ghi lại lý do fallback (lastStubReason) mỗi lần generate();
 *   (b) lý do đó PHẢI được reset giữa các lần gọi (service là singleton — rò trạng thái là bug thật);
 *   (c) job hoàn tất PHẢI ghi cờ đó xuống generations.is_demo + demo_reason;
 *   (d) API (show · latest) PHẢI trả cờ đó ra cho UI, nếu không UI lại im lặng như trước.
 */
class DemoHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    /** Xoá SẠCH mọi khoá API để rơi vào nhánh fallback của generate(). */
    private function withoutAnyApiKey(): void
    {
        foreach (['qwen', 'qwen_edit', 'dashscope', 'wan', 'gemini', 'fal', 'replicate'] as $k) {
            config(['studio.'.$k.'_key' => null]);
        }
        \App\Models\StudioModel::query()->delete();
        \App\Models\StudioApiKey::query()->delete();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function generation(User $u, array $attrs = []): Generation
    {
        return $u->generations()->create(array_merge([
            'type' => 'image', 'status' => 'pending', 'prompt' => 'x',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 1,
        ], $attrs));
    }

    // ── (a) service ghi lý do fallback ────────────────────────────────────

    public function test_text2image_without_key_reports_it_returned_a_sample(): void
    {
        $this->withoutAnyApiKey();

        $service = app(ImageAIService::class);
        $url = $service->generate('photo of a red silk dress', null, null, null, null);

        $this->assertIsString($url, 'Nhánh fallback vẫn phải trả URL (giữ nguyên hành vi cũ).');
        $this->assertNotNull($service->lastStubReason(),
            'Đợt 0.3: ảnh mẫu PHẢI được đánh dấu — không được báo "thành công" im lặng.');
        $this->assertStringContainsString('ẢNH MẪU', (string) $service->lastStubReason());
    }

    public function test_edit_without_key_reports_it_returned_the_original_image(): void
    {
        $this->withoutAnyApiKey();

        // Ảnh gốc giả: copySample chỉ cần đường dẫn tồn tại để đọc; không có thì dùng placeholder.
        $service = app(ImageAIService::class);
        $url = $service->generate('make the dress blue', '/storage/studio/anh-goc.jpg', null, null, null);

        $this->assertIsString($url);
        $this->assertNotNull($service->lastStubReason());
        $this->assertStringContainsString('ẢNH GỐC', (string) $service->lastStubReason(),
            'Đường EDIT trả lại chính ảnh gốc — đây là ca tệ nhất (người dùng tưởng AI đã sửa).');
    }

    // ── (b) cờ không rò giữa các lần gọi ──────────────────────────────────

    public function test_stub_reason_is_reset_when_a_later_call_succeeds(): void
    {
        $this->withoutAnyApiKey();

        $service = app(ImageAIService::class);
        $service->generate('first call with no key', null, null, null, null);
        $this->assertNotNull($service->lastStubReason(), 'Lần gọi đầu rơi vào fallback.');

        // Lần gọi sau có provider thật ⇒ cờ DEMO của lần trước PHẢI bị xoá, nếu không ảnh THẬT
        // sẽ bị gắn nhãn DEMO oan.
        config(['studio.image_provider' => 'qwen', 'studio.qwen_key' => 'sk-real']);
        config(['studio.dashscope_key' => 'sk-real']);
        Http::fake([
            'dashscope-intl.aliyuncs.com*' => Http::response([
                'output' => ['choices' => [['message' => ['content' => [['image' => 'https://cdn.example/real.png']]]]]],
            ], 200),
            'cdn.example*' => Http::response(base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
            ), 200),
        ]);

        $service->generate('second call with a real key', null, null, null, null);

        $this->assertNull($service->lastStubReason(),
            'Cờ DEMO rò từ lần gọi trước ⇒ ảnh THẬT bị gắn nhãn DEMO oan (service là singleton).');
    }

    // ── (c) job hoàn tất ghi cờ xuống DB ──────────────────────────────────

    public function test_completed_job_persists_the_demo_flag(): void
    {
        $u = $this->admin();
        $g = $this->generation($u);

        $this->app->bind(ImageAIService::class, fn () => new class extends ImageAIService {
            public function generate(string $prompt, ?string $baseImage = null, ?string $maskImage = null, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $providerOverride = null, ?string $modelOverride = null, ?string $negativePrompt = null, array $refImages = [], ?string $mode = null, ?int $seed = null): string
            {
                return '/storage/studio/demo.jpg';
            }

            public function lastStubReason(): ?string
            {
                return 'Chưa cấu hình API key — kết quả là ẢNH MẪU có sẵn, KHÔNG phải ảnh do AI tạo.';
            }
        });

        RenderImageJob::dispatchSync($g->id);

        $fresh = $g->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertTrue((bool) $fresh->is_demo, 'Job phải ghi is_demo=true khi service trả ảnh fallback.');
        $this->assertStringContainsString('ẢNH MẪU', (string) $fresh->demo_reason);
    }

    public function test_completed_job_with_a_real_image_is_not_flagged_demo(): void
    {
        $u = $this->admin();
        $g = $this->generation($u);

        $this->app->bind(ImageAIService::class, fn () => new class extends ImageAIService {
            public function generate(string $prompt, ?string $baseImage = null, ?string $maskImage = null, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $providerOverride = null, ?string $modelOverride = null, ?string $negativePrompt = null, array $refImages = [], ?string $mode = null, ?int $seed = null): string
            {
                return '/storage/studio/that.jpg';
            }
        });

        RenderImageJob::dispatchSync($g->id);

        $fresh = $g->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertFalse((bool) $fresh->is_demo, 'Ảnh do AI tạo KHÔNG được gắn nhãn DEMO.');
        $this->assertNull($fresh->demo_reason);
    }

    // ── (d) API trả cờ ra cho UI ──────────────────────────────────────────

    public function test_show_and_latest_expose_the_demo_flag(): void
    {
        $u = $this->admin();
        $this->actingAs($u);

        $g = $this->generation($u, [
            'status' => 'completed',
            'media_url' => '/storage/studio/demo.jpg',
            'is_demo' => true,
            'demo_reason' => 'Chưa cấu hình API key.',
        ]);

        $this->getJson('/api/generations/'.$g->id)
            ->assertOk()
            ->assertJsonPath('is_demo', true)
            ->assertJsonPath('demo_reason', 'Chưa cấu hình API key.');

        $resp = $this->getJson('/api/latest')->assertOk();
        $row = collect($resp->json('items'))->firstWhere('id', $g->id);
        $this->assertNotNull($row, 'generation phải có trong /api/latest.');
        // Dùng assertArrayHasKey trước: nếu khoá biến mất thì đây là FAIL gọn gàng kèm thông điệp
        // nghiệp vụ, không phải ErrorException "Undefined array key" khó đọc.
        $this->assertArrayHasKey('is_demo', $row, '/api/latest phải trả is_demo để UI gắn nhãn DEMO.');
        $this->assertTrue((bool) $row['is_demo'], '/api/latest phải trả is_demo=true cho ảnh demo.');
        $this->assertSame('Chưa cấu hình API key.', $row['demo_reason'] ?? null);
    }
}
