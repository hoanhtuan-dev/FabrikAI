<?php

namespace Tests\Feature;

use App\Jobs\RenderImageJob;
use App\Models\Generation;
use App\Models\User;
use App\Notifications\GenerationCompleted;
use App\Services\ImageAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * [Đợt 1.5 — 2026-09-17] THÔNG BÁO KHI RENDER XONG.
 *
 * Trước đây app không gửi gì (grep 'Notification|Mail::' chỉ ra 'use Notifiable' của User), nên
 * người dùng phải CANH MÀN HÌNH suốt 3–8 phút render mới biết ảnh xong.
 *
 * Bất biến được khoá:
 *   (a) generation hoàn tất ⇒ gửi thông báo cho CHỦ SỞ HỮU;
 *   (b) đúng MỘT lần — chạy lại job (đua CAS) KHÔNG gửi thêm ⇒ không spam;
 *   (c) generation THẤT BẠI thì KHÔNG báo 'xong';
 *   (d) ảnh DEMO thì thông báo phải NÓI THẬT (nhất quán với Đợt 0.3).
 */
class GenerationNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function owner(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function generation(User $u, array $attrs = []): Generation
    {
        return $u->generations()->create(array_merge([
            'type' => 'image', 'status' => 'pending', 'prompt' => 'x',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 1,
        ], $attrs));
    }

    /** Thay service thật bằng đồ giả trả URL cố định. */
    private function fakeService(string $url, ?string $stubReason = null): void
    {
        $this->app->bind(ImageAIService::class, fn () => new class($url, $stubReason) extends ImageAIService
        {
            public function __construct(private string $url, private ?string $reason) {}

            public function generate(string $prompt, ?string $baseImage = null, ?string $maskImage = null, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $providerOverride = null, ?string $modelOverride = null, ?string $negativePrompt = null, array $refImages = [], ?string $mode = null, ?int $seed = null): string
            {
                return $this->url;
            }

            public function lastStubReason(): ?string
            {
                return $this->reason;
            }
        });
    }

    /** Thay service bằng đồ giả LUÔN NÉM LỖI (để kiểm nhánh thất bại). */
    private function failingService(): void
    {
        $this->app->bind(ImageAIService::class, fn () => new class extends ImageAIService
        {
            public function generate(string $prompt, ?string $baseImage = null, ?string $maskImage = null, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $providerOverride = null, ?string $modelOverride = null, ?string $negativePrompt = null, array $refImages = [], ?string $mode = null, ?int $seed = null): string
            {
                throw new \RuntimeException('provider sap');
            }
        });
    }

    // ── (a) hoàn tất ⇒ có thông báo ───────────────────────────────────────

    public function test_completed_generation_notifies_the_owner(): void
    {
        Notification::fake();
        $u = $this->owner();
        $g = $this->generation($u);
        $this->fakeService('/storage/studio/that.jpg');

        RenderImageJob::dispatchSync($g->id);

        $this->assertSame('completed', $g->fresh()->status);
        Notification::assertSentTo($u, GenerationCompleted::class);
    }

    // ── (b) đúng MỘT lần — không spam ─────────────────────────────────────

    public function test_running_the_job_twice_sends_only_one_notification(): void
    {
        Notification::fake();
        $u = $this->owner();
        $g = $this->generation($u);
        $this->fakeService('/storage/studio/that.jpg');

        RenderImageJob::dispatchSync($g->id);
        RenderImageJob::dispatchSync($g->id); // chạy lại: CAS thất bại ⇒ không gửi thêm

        Notification::assertSentToTimes($u, GenerationCompleted::class, 1);
    }

    public function test_notification_goes_only_to_the_owner(): void
    {
        Notification::fake();
        $owner = $this->owner();
        $other = User::factory()->create();
        $g = $this->generation($owner);
        $this->fakeService('/storage/studio/that.jpg');

        RenderImageJob::dispatchSync($g->id);

        Notification::assertSentTo($owner, GenerationCompleted::class);
        Notification::assertNotSentTo($other, GenerationCompleted::class);
    }

    // ── (c) thất bại ⇒ KHÔNG báo 'xong' ───────────────────────────────────

    public function test_failed_generation_does_not_announce_success(): void
    {
        Notification::fake();
        $u = $this->owner();
        $g = $this->generation($u);
        $this->failingService();

        RenderImageJob::dispatchSync($g->id);

        $this->assertSame('failed', $g->fresh()->status);
        Notification::assertNothingSent();
    }

    // ── (d) ảnh DEMO ⇒ thông báo phải nói thật ────────────────────────────

    public function test_demo_completion_notification_says_it_is_a_sample(): void
    {
        Notification::fake();
        $u = $this->owner();
        $g = $this->generation($u);
        $this->fakeService('/storage/studio/demo.jpg', 'Chưa cấu hình API key — kết quả là ẢNH MẪU có sẵn, KHÔNG phải ảnh do AI tạo.');

        RenderImageJob::dispatchSync($g->id);

        Notification::assertSentTo($u, GenerationCompleted::class, function ($notification) use ($u) {
            $text = implode(' ', $notification->toMail($u)->introLines);

            return str_contains($text, 'MẪU');
        });
    }

    // ── (e) guard chống 'vá một nơi, quên chỗ tương đương' ────────────────

    public function test_both_render_jobs_notify(): void
    {
        foreach (['RenderImageJob', 'RenderVideoJob'] as $job) {
            $src = (string) file_get_contents(app_path('Jobs/'.$job.'.php'));
            $this->assertStringContainsString('GenerationCompleted', $src,
                $job.' phải gửi thông báo khi render xong — sửa một job mà quên job kia là mẫu lỗi đã gặp.');
        }
    }
}
