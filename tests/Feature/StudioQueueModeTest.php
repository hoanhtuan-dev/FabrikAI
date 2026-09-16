<?php

namespace Tests\Feature;

use App\Jobs\RenderImageJob;
use App\Jobs\RenderVideoJob;
use App\Jobs\SwapModelJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Test cho cờ `studio.queue_worker` (vá N11/M13 sau khi chốt T7: FabrikAI là production mới
 * và production CÓ queue worker).
 *
 * Vấn đề gốc: khi client poll một generation còn 'pending', request TỰ xử lý inline. Các service
 * phải sleep() chờ provider (ảnh ~3 phút, video tới ~8 phút) ⇒ mỗi request giữ chặt một tiến trình
 * PHP-FPM suốt thời gian đó; vài request đồng thời là cạn pool và sập site.
 *
 * Bất biến cần khoá:
 *   1. Mặc định PHẢI là false — dev và bộ test không được phụ thuộc worker nền.
 *   2. Bật cờ ⇒ request chỉ ENQUEUE, KHÔNG xử lý inline, và trả về ngay.
 *   3. Poll liên tục KHÔNG được dội queue (một generation = một job, không phải 300 job).
 *   4. Tắt cờ ⇒ hành vi cũ giữ nguyên (xử lý ngay trong request).
 */
class StudioQueueModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function generation(array $attrs = []): Generation
    {
        return $this->admin()->generations()->create(array_merge([
            'type' => 'image', 'status' => 'pending', 'prompt' => 'x',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 1,
        ], $attrs));
    }

    // ── 1. Mặc định an toàn cho dev/test ─────────────────────────────────

    public function test_inline_mode_is_the_default(): void
    {
        $this->assertFalse((bool) config('studio.queue_worker'),
            'Mặc định phải là false: bật sẵn sẽ làm dev/test không chạy được nếu thiếu worker nền.');
    }

    public function test_inline_mode_still_processes_within_the_request(): void
    {
        // Bẫy đã sập một lần: bản đầu của test này chỉ assert "status đổi khỏi pending". Nó XANH ở
        // CẢ HAI chế độ, vì phpunit.xml đặt QUEUE_CONNECTION=sync nên `dispatch()` cũng chạy ngay
        // trong tiến trình test. Test đó không chứng minh được gì (mutation-test bắt được).
        //
        // Cách kiểm đúng: Queue::fake() chặn đường queue, nên nếu job VẪN chạy thì đó đúng là
        // dispatchSync (xử lý trong request), không phải enqueue.
        Queue::fake();

        $g = $this->generation(['status' => 'pending']);

        $this->actingAs($this->admin())
            ->getJson('/api/generations/'.$g->id)
            ->assertOk();

        $this->assertNotSame('pending', $g->fresh()->status,
            'Chế độ inline (mặc định) phải xử lý ngay trong request, không đẩy vào queue.');
        Queue::assertNothingPushed();
    }

    // ── 2. Chế độ worker: chỉ enqueue ────────────────────────────────────

    public function test_queue_worker_mode_enqueues_instead_of_processing_in_the_request(): void
    {
        config(['studio.queue_worker' => true]);
        Queue::fake();

        $g = $this->generation(['type' => 'image', 'status' => 'pending']);

        $res = $this->actingAs($this->admin())->getJson('/api/generations/'.$g->id);

        $res->assertOk();
        Queue::assertPushed(RenderImageJob::class, fn ($job) => $job->generationId === $g->id);

        // Bằng chứng KHÔNG xử lý inline: generation vẫn nguyên 'pending'.
        $this->assertSame('pending', $g->fresh()->status);
        $this->assertSame('pending', $res->json('status'));
    }

    public function test_queue_worker_mode_enqueues_the_video_job_for_video_generations(): void
    {
        config(['studio.queue_worker' => true]);
        Queue::fake();

        $g = $this->generation(['type' => 'video', 'status' => 'pending']);

        $this->actingAs($this->admin())->getJson('/api/generations/'.$g->id)->assertOk();

        Queue::assertPushed(RenderVideoJob::class);
        Queue::assertNotPushed(RenderImageJob::class);
        $this->assertSame('pending', $g->fresh()->status);
    }

    public function test_queue_worker_mode_enqueues_the_swap_job_for_swap_generations(): void
    {
        config(['studio.queue_worker' => true]);
        Queue::fake();

        // Swap đi đường riêng (SwapModelJob), không qua RenderImageJob.
        $g = $this->generation(['status' => 'pending', 'meta' => ['swap' => true]]);

        $this->actingAs($this->admin())->getJson('/api/generations/'.$g->id)->assertOk();

        Queue::assertPushed(SwapModelJob::class);
        Queue::assertNotPushed(RenderImageJob::class);
        $this->assertSame('pending', $g->fresh()->status);
    }

    // ── 3. Không dội queue khi client poll liên tục ──────────────────────

    public function test_repeated_polling_enqueues_a_generation_only_once(): void
    {
        config(['studio.queue_worker' => true]);
        Queue::fake();

        $g = $this->generation(['status' => 'pending']);
        $admin = $this->admin();

        // SPA poll mỗi ~1 giây; worker chậm 1 phút = ~60 lần poll cho CÙNG một generation.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->getJson('/api/generations/'.$g->id)->assertOk();
        }

        Queue::assertPushed(RenderImageJob::class, 1);
    }

    // ── 4. Nút "Xử lý ngay" cũng tôn trọng cờ ────────────────────────────

    public function test_process_queue_endpoint_queues_instead_of_blocking_in_queue_mode(): void
    {
        config(['studio.queue_worker' => true]);
        Queue::fake();

        $this->generation(['status' => 'pending']);

        $res = $this->actingAs($this->admin())->postJson('/api/process');

        $res->assertOk();
        $this->assertTrue($res->json('queued'));
        Queue::assertPushed(RenderImageJob::class);
    }

    public function test_process_queue_endpoint_reports_inline_mode_by_default(): void
    {
        $this->generation(['status' => 'pending']);

        $res = $this->actingAs($this->admin())->postJson('/api/process');

        $res->assertOk();
        $this->assertFalse($res->json('queued'));
    }
}
