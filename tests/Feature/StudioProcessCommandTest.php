<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Test cho command `studio:process` — fallback worker khi KHÔNG chạy queue:work.
 *
 * Hai việc của command: (1) heal generation kẹt 'processing' → failed + HOÀN CREDIT,
 * (2) chạy tuần tự mọi generation 'pending'. Cả hai đều đụng tới TIỀN của người dùng nên phải khoá
 * các tính chất: chỉ heal row đủ cũ · không đụng row trạng thái khác · hoàn credit ĐÚNG MỘT LẦN ·
 * --stuck-only không chạy job mới · --limit bị kẹp và có tác dụng.
 */
class StudioProcessCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Ngưỡng kẹt của command. */
    private const STUCK_MINUTES = 11;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function user(): User
    {
        return User::first() ?? User::factory()->create();
    }

    private function generation(array $attrs = []): Generation
    {
        $u = $this->user();

        return $u->generations()->create(array_merge([
            'type' => 'image', 'status' => 'processing', 'prompt' => 'x',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 3,
        ], $attrs));
    }

    private function age(Generation $g, int $minutes): void
    {
        // updated_at phải cũ để row được coi là "kẹt".
        Generation::where('id', $g->id)->update(['updated_at' => now()->subMinutes($minutes)]);
    }

    // ── Heal ─────────────────────────────────────────────────────────────

    public function test_heals_stuck_generation_and_refunds_once(): void
    {
        $user = $this->user();
        $before = (int) $user->credits_balance;

        $g = $this->generation(['status' => 'processing', 'credits_cost' => 3]);
        $this->age($g, self::STUCK_MINUTES + 5);

        $this->artisan('studio:process', ['--stuck-only' => true])->assertExitCode(0);

        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame($before + 3, (int) $user->fresh()->credits_balance);

        // Chạy thêm 2 lượt: row đã 'failed' nên không được hoàn thêm.
        $this->artisan('studio:process', ['--stuck-only' => true])->assertExitCode(0);
        $this->artisan('studio:process', ['--stuck-only' => true])->assertExitCode(0);
        $this->assertSame($before + 3, (int) $user->fresh()->credits_balance, 'Chỉ được hoàn credit ĐÚNG MỘT lần.');
    }

    /**
     * Mô phỏng ĐUA một cách TẤT ĐỊNH: command đọc danh sách row kẹt ở ĐẦU handle(), rồi mới lặp
     * để heal. Nếu một lượt cron khác (hoặc queue worker) claim + hoàn tiền cho row thứ hai trong
     * khoảng giữa đó, command này KHÔNG được hoàn tiền lần nữa.
     *
     * Điểm chen: khi command vừa heal xong row A (model event 'updated' của A bắn ra), ta đổi row B
     * sang 'failed' — đúng trạng thái mà một lượt khác để lại. Row B vẫn nằm trong danh sách đã đọc
     * của command, nên đây chính là tình huống đua thật.
     *
     * Vì sao cần test riêng: nếu chỉ chạy command nhiều lần tuần tự, row đã 'failed' sẽ không được
     * SELECT lại nữa — test tuần tự KHÔNG bắt được việc thiếu CAS (đã kiểm chứng bằng mutation).
     */
    public function test_row_claimed_by_another_worker_is_not_refunded(): void
    {
        $user = $this->user();
        $before = (int) $user->credits_balance;

        $a = $this->generation(['status' => 'processing', 'credits_cost' => 2, 'prompt' => 'A']);
        $b = $this->generation(['status' => 'processing', 'credits_cost' => 5, 'prompt' => 'B']);
        $this->age($a, self::STUCK_MINUTES + 5);
        $this->age($b, self::STUCK_MINUTES + 5);

        // Điểm chen phải là DB::listen, KHÔNG phải model event: lệnh heal của command dùng
        // Generation::where(...)->update(...) (query-builder) nên KHÔNG bắn eloquent.updated.
        $state = (object) ['armed' => true];
        DB::listen(function ($query) use ($a, $b, $state) {
            if (! $state->armed) {
                return;
            }
            if (! str_contains(strtolower($query->sql), 'update')) {
                return;
            }
            $bindings = array_map('strval', $query->bindings);
            if (! in_array((string) $a->id, $bindings, true)) {
                return;   // chỉ chen ngay sau khi A vừa được heal
            }

            $state->armed = false;   // tắt TRƯỚC khi chạy query để không đệ quy vào chính listener
            Generation::where('id', $b->id)->where('status', 'processing')
                ->update(['status' => 'failed', 'error' => 'claimed by another worker']);
        });

        try {
            $this->artisan('studio:process', ['--stuck-only' => true])->assertExitCode(0);
        } finally {
            $state->armed = false;   // listener vẫn đăng ký nhưng vô hiệu cho các test sau
        }

        $this->assertSame('failed', $a->fresh()->status);
        $this->assertSame('failed', $b->fresh()->status);
        $this->assertSame(
            $before + 2,
            (int) $user->fresh()->credits_balance,
            'Row đã bị lượt khác claim KHÔNG được hoàn credit lần nữa (thiếu CAS -> hoàn +7).'
        );
    }

    public function test_does_not_heal_recent_processing_rows(): void
    {
        $user = $this->user();
        $before = (int) $user->credits_balance;

        $fresh = $this->generation(['status' => 'processing', 'credits_cost' => 3]);
        $this->age($fresh, 2);   // mới 2 phút — chưa kẹt

        $this->artisan('studio:process', ['--stuck-only' => true])->assertExitCode(0);

        $this->assertSame('processing', $fresh->fresh()->status);
        $this->assertSame($before, (int) $user->fresh()->credits_balance, 'Row chưa kẹt thì không được hoàn credit.');
    }

    public function test_does_not_touch_terminal_rows(): void
    {
        $user = $this->user();
        $before = (int) $user->credits_balance;

        $done = $this->generation(['status' => 'completed', 'credits_cost' => 3]);
        $fail = $this->generation(['status' => 'failed', 'credits_cost' => 3]);
        $cancel = $this->generation(['status' => 'cancelled', 'credits_cost' => 3]);
        foreach ([$done, $fail, $cancel] as $g) {
            $this->age($g, self::STUCK_MINUTES + 30);
        }

        $this->artisan('studio:process', ['--stuck-only' => true])->assertExitCode(0);

        $this->assertSame('completed', $done->fresh()->status);
        $this->assertSame('failed', $fail->fresh()->status);
        $this->assertSame('cancelled', $cancel->fresh()->status);
        $this->assertSame($before, (int) $user->fresh()->credits_balance);
    }

    public function test_stuck_rows_with_zero_credits_are_healed_without_refund(): void
    {
        $user = $this->user();
        $before = (int) $user->credits_balance;

        $g = $this->generation(['status' => 'processing', 'credits_cost' => 0]);
        $this->age($g, self::STUCK_MINUTES + 5);

        $this->artisan('studio:process', ['--stuck-only' => true])->assertExitCode(0);

        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame($before, (int) $user->fresh()->credits_balance);
    }

    public function test_limit_clamps_to_at_least_one(): void
    {
        $a = $this->generation(['status' => 'processing', 'credits_cost' => 1]);
        $b = $this->generation(['status' => 'processing', 'credits_cost' => 1]);
        $this->age($a, self::STUCK_MINUTES + 5);
        $this->age($b, self::STUCK_MINUTES + 5);

        // --limit=0 bị kẹp thành 1 -> chỉ heal 1 row.
        $this->artisan('studio:process', ['--limit' => 0, '--stuck-only' => true])->assertExitCode(0);

        $healed = Generation::whereIn('id', [$a->id, $b->id])->where('status', 'failed')->count();
        $this->assertSame(1, $healed, '--limit=0 phải bị kẹp thành 1.');
    }

    // ── Process ──────────────────────────────────────────────────────────

    public function test_stuck_only_does_not_run_pending_jobs(): void
    {
        $pending = $this->generation(['status' => 'pending', 'credits_cost' => 1]);

        $this->artisan('studio:process', ['--stuck-only' => true])->assertExitCode(0);

        $this->assertSame('pending', $pending->fresh()->status, '--stuck-only không được chạy job mới.');
    }

    public function test_processes_pending_generation(): void
    {
        // Ở chế độ demo (không cấu hình khoá provider) ảnh dùng samples/*.jpg nên job kết thúc
        // 'completed' — đây cũng là contract của StudioFlowTest.
        $pending = $this->generation(['status' => 'pending', 'credits_cost' => 1]);

        $this->artisan('studio:process', ['--limit' => 5])->assertExitCode(0);

        $fresh = $pending->fresh();
        $this->assertContains($fresh->status, ['completed', 'failed'], 'Row pending phải được xử lý tới trạng thái kết thúc.');
        $this->assertNotNull($fresh->elapsed_ms);
    }
}
