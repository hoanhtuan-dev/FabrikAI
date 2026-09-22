<?php

namespace Tests\Feature;

use App\Models\ProductionLog;
use App\Models\Project;
use App\Models\User;
use App\Services\ProductionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TIẾN ĐỘ SẢN XUẤT — thực tế so với kế hoạch (Việc #8, 2026-09-26).
 *
 * Khoá mười bất biến:
 *   (a) MỘT DÒNG MỘT NGÀY: ghi lại cùng ngày là SỬA, không cộng thêm (ghi hai lần thành gấp đôi là kiểu
 *       sai tệ nhất của một bảng cộng dồn);
 *   (b) KHÔNG ghi được ngày CHƯA TỚI — con số đó chưa xảy ra;
 *   (c) chặn số vô lý (một ngày gấp hơn 20 lần cả kế hoạch ⇒ nhiều khả năng gõ thừa số 0);
 *   (d) KHÔNG có kế hoạch ⇒ nói thẳng "chưa so được", KHÔNG bịa tỉ lệ phần trăm;
 *   (e) tiến độ/nhịp/ngày dự kiến xong là SỐ TÍNH từ (kế hoạch + sản lượng + hôm nay);
 *   (f) số ngày chậm = (số ngày cần theo nhịp đang chạy) − (số ngày còn lại tới hạn);
 *   (g) cảnh báo kèm CON SỐ sinh ra nó (chậm bao nhiêu · cần bao nhiêu cái/ngày · lỗi thật so với giả định);
 *   (h) lỗi thật được đối chiếu với @@defect_pct@@ mà chủ xưởng tự đoán trong kế hoạch;
 *   (i) quyền: khách 401 · người ngoài 403 · chủ bộ 200; IDOR: ngày của bộ KHÁC trả 404;
 *   (j) xoá một ngày thì mọi con số tính lại — không có số lưu sẵn nào để lệch.
 */
class ProductionTrackingTest extends TestCase
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

    /** Kế hoạch 1.200 cái / 12 ngày, giả định lỗi 3% — đúng chỗ Agent Studio ghi (settings.plan). */
    private function withPlan(Project $p, int $units = 1200, int $days = 12, float $defectPct = 3.0): Project
    {
        $p->settings = ['plan' => [
            'totals' => ['units' => $units, 'days_total' => $days, 'cut_lines' => 4],
            'assumptions' => ['defect_pct' => $defectPct, 'daily_capacity' => 100],
            'waves' => [['label' => 'Đợt 1', 'units' => 400, 'days' => 4]],
        ]];
        $p->save();

        return $p->fresh();
    }

    private function log(Project $p, string $day, int $done, int $defects = 0, ?User $by = null): ProductionLog
    {
        return ProductionLog::create([
            'project_id' => $p->id,
            'logged_on' => $day,
            'units_done' => $done,
            'units_defect' => $defects,
            'created_by' => ($by ?? $this->customer())->id,
        ]);
    }

    // ── (a)(b)(c) GHI ───────────────────────────────────────────────────────────────────────

    public function test_one_row_per_day_and_logging_again_updates_it(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u));

        $first = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/production', [
            'logged_on' => now()->toDateString(), 'units_done' => 50,
        ])->assertStatus(200);
        $this->assertSame(50, $first->json('actual.units_done'));

        // Ghi lại CÙNG NGÀY là sửa: nếu cộng dồn thì con số thành 110 mà không ai biết vì sao.
        $second = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/production', [
            'logged_on' => now()->toDateString(), 'units_done' => 60, 'units_defect' => 2,
        ])->assertStatus(200);

        $this->assertSame(60, $second->json('actual.units_done'));
        $this->assertSame(1, $second->json('summary.total'), 'Một ngày chỉ có MỘT dòng.');
        $this->assertSame(2, $second->json('actual.units_defect'));
        $this->assertSame(1, ProductionLog::query()->where('project_id', $p->id)->count());
    }

    public function test_a_future_day_is_refused(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u));

        $res = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/production', [
            'logged_on' => now()->addDay()->toDateString(), 'units_done' => 10,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('CHƯA TỚI', (string) $res->json('message'));
    }

    public function test_an_absurd_daily_number_is_refused(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u), 1200);

        // 1.200 × 20 = 24.000 ⇒ 30.000 trong một ngày là gõ thừa số 0.
        $res = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/production', [
            'logged_on' => now()->toDateString(), 'units_done' => 30000,
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('Kiểm tra lại số vừa nhập', (string) $res->json('message'));
        $this->assertSame(0, ProductionLog::query()->where('project_id', $p->id)->count());
    }

    // ── (d)(e)(f) TÍNH TOÁN ─────────────────────────────────────────────────────────────────

    public function test_without_a_plan_it_says_it_cannot_compare(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->log($p, now()->subDays(2)->toDateString(), 100);

        $res = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/production')->assertStatus(200);

        $this->assertSame(ProductionTrackingService::STATUS_NO_PLAN, $res->json('progress.status'));
        $this->assertNull($res->json('progress.pct'), 'Không có kế hoạch thì KHÔNG bịa tỉ lệ phần trăm.');
        $this->assertNull($res->json('progress.forecast_date'));
        $this->assertStringContainsString('Chưa có kế hoạch', (string) $res->json('alerts.0.text'));
    }

    public function test_progress_pace_and_forecast_are_computed(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u, ['deadline' => now()->addDays(10)->toDateString()]), 1200, 12);

        // 5 ngày × 60 cái = 300 cái ⇒ nhịp 60 cái/ngày.
        for ($i = 5; $i >= 1; $i--) {
            $this->log($p, now()->subDays($i)->toDateString(), 60);
        }

        $res = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/production')->assertStatus(200);

        $this->assertSame(300, $res->json('actual.units_done'));
        $this->assertSame(900, $res->json('actual.units_left'));
        $this->assertSame(5, $res->json('actual.days_logged'));
        $this->assertEqualsWithDelta(60.0, (float) $res->json('actual.pace_per_day'), 0.05, '300 cái / 5 ngày = 60 cái/ngày.');
        $this->assertEqualsWithDelta(25.0, (float) $res->json('progress.pct'), 0.05, '300/1.200 = 25%.');
        // Còn 900 cái ở nhịp 60/ngày ⇒ cần 15 ngày, còn 10 ngày ⇒ chậm 5 ngày.
        $this->assertSame(5, $res->json('progress.behind_days'));
        $this->assertSame(ProductionTrackingService::STATUS_BEHIND, $res->json('progress.status'));
        $this->assertSame(now()->addDays(15)->toDateString(), $res->json('progress.forecast_date'));
        $this->assertEqualsWithDelta(90.0, (float) $res->json('actual.required_per_day'), 0.05, '900 cái / 10 ngày.');
    }

    public function test_on_track_when_the_pace_meets_the_deadline(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u, ['deadline' => now()->addDays(10)->toDateString()]), 1200, 12);

        // 2 ngày × 100 cái = 200 cái; còn 1.000 cái trong 10 ngày ⇒ cần 100/ngày = đúng nhịp.
        $this->log($p, now()->subDays(1)->toDateString(), 100);
        $this->log($p, now()->toDateString(), 100);

        $res = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/production')->assertStatus(200);

        $this->assertSame(0, $res->json('progress.behind_days'));
        $this->assertSame(ProductionTrackingService::STATUS_ON_TRACK, $res->json('progress.status'));
        $this->assertTrue($res->json('summary.today_logged'));
        $this->assertSame(100, $res->json('summary.today_units'));
    }

    public function test_finishing_the_plan_is_reported_as_done(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u), 100);

        $this->log($p, now()->toDateString(), 120);

        $res = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/production')->assertStatus(200);

        $this->assertSame(ProductionTrackingService::STATUS_DONE, $res->json('progress.status'));
        $this->assertEqualsWithDelta(100.0, (float) $res->json('progress.pct'), 0.05, 'Vượt kế hoạch vẫn hiện 100%, không hiện 120%.');
        $this->assertSame(0, $res->json('actual.units_left'));
    }

    // ── (g)(h) CẢNH BÁO ─────────────────────────────────────────────────────────────────────

    public function test_alerts_carry_the_numbers_that_created_them(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u, ['deadline' => now()->addDays(4)->toDateString()]), 1200, 12, 3.0);

        // Nhịp 120/ngày (600 cái trong 5 ngày), còn 600 cái trong 4 ngày ⇒ cần 150/ngày ⇒ chậm 1 ngày.
        // Lỗi thật 100/600 = 16,7% so với giả định 3% trong kế hoạch.
        for ($i = 5; $i >= 1; $i--) {
            $this->log($p, now()->subDays($i)->toDateString(), 120, 20);
        }

        $res = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/production')->assertStatus(200);
        $texts = implode(' | ', array_column($res->json('alerts'), 'text'));

        $this->assertStringContainsString('cần 150', $texts, 'Cảnh báo phải nói nhịp CẦN: 600 cái còn lại / 4 ngày.');
        $this->assertStringContainsString('đang làm 120', $texts, 'Cảnh báo phải nói nhịp ĐANG CHẠY.');
        $this->assertStringContainsString('chậm khoảng 1 ngày', $texts, '600 cái ở nhịp 120/ngày cần 5 ngày, còn 4 ngày.');
        $this->assertStringContainsString('16,7%', $texts, 'Lỗi thật (100/600) so với giả định 3% trong kế hoạch.');
        $this->assertSame(16.67, $res->json('actual.defect_pct'));
    }

    public function test_a_long_silence_is_flagged_as_stale(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u), 1200);

        $this->log($p, now()->subDays(9)->toDateString(), 100);

        $texts = implode(' | ', array_column(
            $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/production')->json('alerts'),
            'text',
        ));

        $this->assertStringContainsString('9 ngày không ghi sản lượng', $texts);
        $this->assertStringContainsString('số cũ', $texts, 'Nói rõ vì sao con số trên màn hình đáng ngờ.');
    }

    // ── (i)(j) QUYỀN + XOÁ ──────────────────────────────────────────────────────────────────

    public function test_guest_and_outsiders_are_blocked(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->withPlan($this->project($owner));
        $row = $this->log($p, now()->toDateString(), 10, 0, $owner);

        $this->getJson('/api/projects/'.$p->id.'/production')->assertStatus(401);
        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/production')->assertForbidden();
        $this->actingAs($other)->postJson('/api/projects/'.$p->id.'/production', [
            'logged_on' => now()->toDateString(), 'units_done' => 5,
        ])->assertForbidden();
        $this->actingAs($other)->deleteJson('/api/projects/'.$p->id.'/production/'.$row->id)->assertForbidden();
    }

    public function test_a_day_from_another_project_is_not_reachable(): void
    {
        $owner = $this->customer();
        $mine = $this->withPlan($this->project($owner, ['name' => 'Bộ của tôi']));
        $theirs = $this->withPlan($this->project($owner, ['name' => 'Bộ khác']));
        $foreign = $this->log($theirs, now()->toDateString(), 77);

        $this->actingAs($owner)->deleteJson('/api/projects/'.$mine->id.'/production/'.$foreign->id)->assertStatus(404);
        $this->assertNotNull(ProductionLog::query()->find($foreign->id), 'Ngày của bộ khác phải nguyên vẹn.');
    }

    public function test_deleting_a_day_recomputes_every_number(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u), 1200);

        $keep = $this->log($p, now()->subDays(2)->toDateString(), 100);
        $drop = $this->log($p, now()->subDays(1)->toDateString(), 500);

        $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/production')->assertJsonPath('actual.units_done', 600);

        $after = $this->actingAs($u)->deleteJson('/api/projects/'.$p->id.'/production/'.$drop->id)->assertStatus(200);

        $this->assertSame(100, $after->json('actual.units_done'), 'Số phải tính lại từ dữ liệu còn lại.');
        $this->assertEqualsWithDelta(100.0, (float) $after->json('actual.pace_per_day'), 0.05, 'Nhịp cũng phải tính lại.');
        $this->assertSame(1, $after->json('summary.total'));
        $this->assertNotNull(ProductionLog::query()->find($keep->id));
    }

    public function test_the_note_is_capped_and_the_limits_are_declared(): void
    {
        $u = $this->customer();
        $p = $this->withPlan($this->project($u));

        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/production', [
            'logged_on' => now()->toDateString(),
            'units_done' => 10,
            'note' => str_repeat('a', ProductionTrackingService::NOTE_MAX + 1),
        ])->assertStatus(422)->assertJsonValidationErrors('note');

        $shape = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/production')->json('shape');
        $this->assertSame(ProductionTrackingService::NOTE_MAX, $shape['limits']['note_max']);
        $this->assertCount(count(ProductionTrackingService::STATUS_LABELS), $shape['statuses']);
    }
}
