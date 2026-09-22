<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Sample;
use App\Models\User;
use App\Notifications\SamplesDue;
use App\Services\SampleTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * VÒNG ĐỜI MẪU VẬT LÝ (fit · PP · TOP) — Việc #4, 2026-09-26.
 *
 * Khoá tám bất biến:
 *   (a) máy trạng thái CHẶN NHẢY CÓC (requested → approved bị từ chối) và LUÔN có đường lùi;
 *   (b) cảnh báo TÍNH TỪ DỮ LIỆU (so hạn với hôm nay), không đọc cờ lưu sẵn — nên nó tự đúng lại mỗi ngày;
 *   (c) mẫu ĐÃ ĐẠT thì không còn cảnh báo (nhắc tiếp là tạo tiếng ồn);
 *   (d) quyền: khách 401 · người ngoài 403 · chủ bộ 200;
 *   (e) IDOR: mẫu của bộ KHÁC trả 404 dù id có thật;
 *   (f) trạng thái đầu khi tạo LUÔN là 'requested' — không nhận từ client;
 *   (g) lệnh nhắc hạn gửi ĐÚNG MỘT thư mỗi tài khoản mỗi ngày (không dội hộp thư khi chạy lặp);
 *   (h) --dry-run KHÔNG gửi thư nào.
 */
class SampleTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function project(User $u, array $attrs = []): Project
    {
        return $u->projects()->create(array_merge(['name' => 'Đầm linen Thu Đông'], $attrs));
    }

    private function sample(Project $p, array $attrs = []): Sample
    {
        return Sample::create(array_merge([
            'project_id' => $p->id,
            'style_no' => 'FD-2610',
            'name' => 'Đầm linen cổ V',
            'factory' => 'Xưởng Bình Tân',
            'stage' => Sample::STAGE_REQUESTED,
            'round' => 1,
        ], $attrs));
    }

    // ── (a) MÁY TRẠNG THÁI ─────────────────────────────────────────────────────────────────────

    public function test_the_stage_machine_blocks_skipping_and_always_allows_a_way_back(): void
    {
        $sample = new Sample(['stage' => Sample::STAGE_REQUESTED]);

        try {
            $sample->transitionStage(Sample::STAGE_APPROVED);
            $this->fail('Nhảy cóc từ "đã yêu cầu xưởng" thẳng tới "đạt" phải bị từ chối.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('in_factory', $e->getMessage(), 'Câu lỗi phải nói ĐƯỢC PHÉP đi đâu.');
        }

        $this->assertSame(Sample::STAGE_REQUESTED, $sample->stage, 'Trạng thái phải giữ nguyên sau lượt bị từ chối.');

        // Mọi trạng thái CHƯA ĐẠT đều phải tới được nhánh "không đạt" — làm mẫu là quá trình thử-sai.
        foreach (Sample::STAGES as $stage) {
            if ($stage === Sample::STAGE_REJECTED) {
                continue;
            }
            $this->assertContains('rejected', Sample::TRANSITIONS[$stage] ?? [], 'Trạng thái '.$stage.' phải loại được.');
        }

        // Và nhánh "không đạt" phải quay lại được vòng mới.
        $this->assertSame('requested', Sample::nextStage(Sample::STAGE_REJECTED));
    }

    public function test_next_stage_walks_the_happy_path(): void
    {
        $this->assertSame('in_factory', Sample::nextStage('requested'));
        $this->assertSame('fit', Sample::nextStage('in_factory'));
        $this->assertSame('pp', Sample::nextStage('fit'));
        $this->assertSame('top', Sample::nextStage('pp'));
        $this->assertSame('approved', Sample::nextStage('top'));
        $this->assertNull(Sample::nextStage('approved'), 'Đã đạt là bước cuối.');
    }

    // ── (b) + (c) CẢNH BÁO TÍNH TỪ DỮ LIỆU ────────────────────────────────────────────────────

    public function test_alerts_are_computed_from_the_due_date(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $overdue = $this->sample($p, ['due_at' => now()->subDays(2)->toDateString()]);
        $soon = $this->sample($p, ['due_at' => now()->addDays(2)->toDateString()]);
        $later = $this->sample($p, ['due_at' => now()->addDays(30)->toDateString()]);
        $approved = $this->sample($p, ['stage' => Sample::STAGE_APPROVED, 'due_at' => now()->subDays(9)->toDateString()]);
        $noDue = $this->sample($p);

        $this->assertSame('overdue', SampleTrackingService::alert($overdue));
        $this->assertSame('due_soon', SampleTrackingService::alert($soon));
        $this->assertNull(SampleTrackingService::alert($later));
        $this->assertNull(SampleTrackingService::alert($approved), 'Mẫu ĐÃ ĐẠT không còn gì phải nhắc.');
        $this->assertNull(SampleTrackingService::alert($noDue), 'Chưa khai hạn thì không đoán hộ.');

        $this->assertSame(-2, SampleTrackingService::daysLeft($overdue));
        $this->assertSame(2, SampleTrackingService::daysLeft($soon));
        $this->assertNull(SampleTrackingService::daysLeft($noDue));
    }

    /** Cảnh báo phải ĐỔI theo thời gian mà không cần ghi gì — đây là lý do không lưu cờ. */
    public function test_alert_follows_the_clock_without_any_write(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $sample = $this->sample($p, ['due_at' => now()->addDays(10)->toDateString()]);

        $this->assertNull(SampleTrackingService::alert($sample));

        $this->travel(8)->days();
        $this->assertSame('due_soon', SampleTrackingService::alert($sample->fresh()));

        $this->travel(5)->days();
        $this->assertSame('overdue', SampleTrackingService::alert($sample->fresh()));
    }

    // ── (d)(e)(f) API + QUYỀN ─────────────────────────────────────────────────────────────────

    public function test_guest_and_outsiders_are_blocked(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);
        $sample = $this->sample($p);

        $this->getJson('/api/projects/'.$p->id.'/samples')->assertStatus(401);
        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/samples')->assertForbidden();
        $this->actingAs($other)->postJson('/api/projects/'.$p->id.'/samples', ['style_no' => 'X'])->assertForbidden();
        $this->actingAs($other)->postJson('/api/projects/'.$p->id.'/samples/'.$sample->id.'/stage', ['stage' => 'in_factory'])->assertForbidden();
    }

    /** IDOR: mẫu có THẬT nhưng thuộc bộ khác ⇒ 404 (không xác nhận là nó tồn tại). */
    public function test_a_sample_from_another_project_is_not_reachable(): void
    {
        $owner = $this->customer();
        $mine = $this->project($owner, ['name' => 'Bộ của tôi']);
        $theirs = $this->project($owner, ['name' => 'Bộ khác']);
        $foreign = $this->sample($theirs);

        $this->actingAs($owner)
            ->postJson('/api/projects/'.$mine->id.'/samples/'.$foreign->id.'/stage', ['stage' => 'in_factory'])
            ->assertStatus(404);

        $this->actingAs($owner)
            ->deleteJson('/api/projects/'.$mine->id.'/samples/'.$foreign->id)
            ->assertStatus(404);

        $this->assertSame(Sample::STAGE_REQUESTED, $foreign->fresh()->stage, 'Mẫu của bộ khác phải nguyên vẹn.');
    }

    public function test_api_round_trip_create_transition_and_delete(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $created = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/samples', [
            'style_no' => 'FD-2610',
            'name' => 'Đầm linen cổ V',
            'factory' => 'Xưởng Bình Tân',
            // 2 ngày ⇒ nằm trong ngưỡng nhắc (DUE_SOON_DAYS = 3) nên phải có cảnh báo 'due_soon'.
            'due_at' => now()->addDays(2)->toDateString(),
            // Trạng thái gửi lên PHẢI bị bỏ qua — mẫu mới luôn bắt đầu ở "đã yêu cầu xưởng".
            'stage' => 'approved',
        ])->assertStatus(201);

        $id = $created->json('created.id');
        $this->assertSame('requested', $created->json('created.stage'));
        $this->assertSame('due_soon', $created->json('created.alert'));
        $this->assertSame(1, $created->json('summary.open'));

        // Nhảy cóc bị từ chối kèm danh sách bước được phép.
        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/samples/'.$id.'/stage', ['stage' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('allowed', ['in_factory', 'rejected']);

        // Đường thuận đi được, và bước kế tiếp được gợi ý sẵn cho giao diện.
        $moved = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/samples/'.$id.'/stage', ['stage' => 'in_factory'])
            ->assertOk();
        $this->assertSame('in_factory', $moved->json('updated.stage'));
        $this->assertSame('fit', $moved->json('updated.next_stage'));

        $this->actingAs($u)->deleteJson('/api/projects/'.$p->id.'/samples/'.$id)->assertOk();
        $this->assertSame(0, Sample::count());
    }

    // ── (g)(h) LỆNH NHẮC HẠN ──────────────────────────────────────────────────────────────────

    public function test_reminder_is_sent_at_most_once_per_account_per_day(): void
    {
        Notification::fake();
        Cache::flush();

        $u = $this->customer();
        $p = $this->project($u);
        $this->sample($p, ['due_at' => now()->subDay()->toDateString()]);
        $this->sample($p, ['style_no' => 'FD-2', 'due_at' => now()->addDay()->toDateString()]);

        $this->artisan('studio:samples:remind')->assertExitCode(0);

        Notification::assertSentTo($u, SamplesDue::class, function (SamplesDue $n) {
            return $n->samples->count() === 2 && $n->overdue === 1 && $n->dueSoon === 1;
        });

        // Chạy lần hai trong cùng ngày: đai chống spam phải chặn lượt thứ hai.
        $this->artisan('studio:samples:remind')->assertExitCode(0);
        Notification::assertSentToTimes($u, SamplesDue::class, 1);
    }

    public function test_dry_run_sends_nothing_but_reports(): void
    {
        Notification::fake();
        Cache::flush();

        $u = $this->customer();
        $p = $this->project($u);
        $this->sample($p, ['due_at' => now()->subDays(3)->toDateString()]);

        $this->artisan('studio:samples:remind --dry-run')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_reminder_stays_quiet_when_nothing_is_due(): void
    {
        Notification::fake();
        Cache::flush();

        $u = $this->customer();
        $p = $this->project($u);
        $this->sample($p, ['due_at' => now()->addDays(40)->toDateString()]);
        $this->sample($p, ['style_no' => 'FD-2', 'stage' => Sample::STAGE_APPROVED, 'due_at' => now()->subDays(5)->toDateString()]);

        $this->artisan('studio:samples:remind')->assertExitCode(0);

        Notification::assertNothingSent();
    }
}
