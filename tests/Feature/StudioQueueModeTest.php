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

    public function test_generation_is_enqueued_at_creation_not_only_on_poll(): void
    {
        // [BUG THẬT gặp khi deploy fabrikai.shop] Bản cũ CHỈ enqueue trong show() (lúc client poll).
        // Người dùng tạo ảnh rồi đóng tab ngay ⇒ không ai poll ⇒ generation nằm 'pending' VĨNH VIỄN
        // trong khi credit đã bị trừ. Đã kiểm chứng bằng chạy thật trên production: tạo generation
        // xong bảng 'jobs' vẫn rỗng cho tới khi có request poll.
        config(['studio.queue_worker' => true]);
        Queue::fake();

        $g = $this->generation(['status' => 'pending']);

        Queue::assertPushed(RenderImageJob::class, fn ($job) => $job->generationId === $g->id);
    }

    public function test_creation_does_not_enqueue_twice_when_client_also_polls(): void
    {
        // Chốt Cache::add dùng chung cho cả 2 đường (created() + show()) -> vẫn đúng MỘT job.
        config(['studio.queue_worker' => true]);
        Queue::fake();

        $g = $this->generation(['status' => 'pending']);
        $this->actingAs($this->admin())->getJson('/api/generations/'.$g->id)->assertOk();
        $this->actingAs($this->admin())->getJson('/api/generations/'.$g->id)->assertOk();

        Queue::assertPushed(RenderImageJob::class, 1);
    }

    public function test_inline_mode_does_not_dispatch_at_creation(): void
    {
        // Chế độ dev (không worker) phải giữ nguyên hành vi cũ: KHÔNG đụng tới queue, request poll
        // tự xử lý inline. Nếu test này đỏ nghĩa là model event đang chạy cả khi máy chủ không có worker.
        $this->assertFalse((bool) config('studio.queue_worker'));
        Queue::fake();

        $this->generation(['status' => 'pending']);

        Queue::assertNothingPushed();
    }

    public function test_video_and_swap_generations_are_enqueued_at_creation_too(): void
    {
        config(['studio.queue_worker' => true]);
        Queue::fake();

        $this->generation(['type' => 'video', 'status' => 'pending']);
        $this->generation(['type' => 'image', 'status' => 'pending', 'meta' => ['swap' => true]]);

        Queue::assertPushed(RenderVideoJob::class, 1);
        Queue::assertPushed(SwapModelJob::class, 1);
    }

    public function test_fresh_pending_generation_is_not_processed_inline_in_queue_mode(): void
    {
        // Khi CÓ worker nền (cron 1 phút), generation mới phải được để worker xử lý — request poll
        // KHÔNG được giành việc, nếu không thì chế độ queue mất hết ý nghĩa (request lại bị giữ lâu).
        config(['studio.queue_worker' => true]);
        Queue::fake();

        $g = $this->generation(['status' => 'pending']);

        $this->actingAs($this->admin())->getJson('/api/generations/'.$g->id)->assertOk();

        $this->assertSame('pending', $g->fresh()->status,
            'Generation mới (chưa quá ngưỡng chờ) không được xử lý inline khi đang ở chế độ queue.');
    }

    public function test_stale_pending_generation_falls_back_to_inline_when_no_worker_runs(): void
    {
        // [LƯỚI AN TOÀN] Bật cờ queue mà máy chủ KHÔNG có worker nào chạy (cron chưa tạo) thì
        // generation sẽ kẹt 'pending' mãi và người dùng mất credit. Sau ngưỡng chờ, request poll
        // phải tự xử lý inline để người dùng vẫn có kết quả.
        config(['studio.queue_worker' => true]);
        // BẮT BUỘC: Queue::fake() để đường ENQUEUE không tự chạy. Thiếu dòng này thì
        // QUEUE_CONNECTION=sync khiến dispatch() chạy ngay, generation đổi trạng thái do ENQUEUE
        // chứ không phải do lưới an toàn => test XANH kể cả khi đã gỡ lưới (mutation-test bắt được).
        Queue::fake();

        $g = $this->generation(['status' => 'pending']);
        // Đẩy created_at ra quá ngưỡng (90s) — mô phỏng "không worker nào nhặt việc".
        \Illuminate\Support\Facades\DB::table('generations')->where('id', $g->id)
            ->update(['created_at' => now()->subMinutes(5)]);

        $this->actingAs($this->admin())->getJson('/api/generations/'.$g->id)->assertOk();

        $this->assertNotSame('pending', $g->fresh()->status,
            'Không có worker chạy thì phải tự xử lý inline thay vì để generation kẹt pending mãi.');
    }

    public function test_inline_fallback_cleans_up_the_queued_job(): void
    {
        // Khi máy chủ không có worker: generation vẫn được enqueue (đúng thiết kế) rồi được lưới an
        // toàn xử lý inline. Nếu không dọn, job đã enqueue nằm lại trong bảng 'jobs' MÃI MÃI — cứ mỗi
        // ảnh lại thêm một job chết.
        // Dùng queue store THẬT (database) để job thực sự được ghi thành row.
        config(['studio.queue_worker' => true, 'queue.default' => 'database']);

        $g = $this->generation(['status' => 'pending']);

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('jobs')->count(),
            'Tạo generation phải đẩy đúng một job vào hàng đợi.');

        // Đẩy created_at quá ngưỡng 90s để kích hoạt lưới an toàn.
        \Illuminate\Support\Facades\DB::table('generations')->where('id', $g->id)
            ->update(['created_at' => now()->subMinutes(5)]);

        $this->actingAs($this->admin())->getJson('/api/generations/'.$g->id)->assertOk();

        $this->assertNotSame('pending', $g->fresh()->status, 'Lưới an toàn phải xử lý inline.');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count(),
            'Job đã enqueue phải được dọn sau khi xử lý inline — không để lại job chết.');
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
