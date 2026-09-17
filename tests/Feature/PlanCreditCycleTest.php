<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CẤP CREDIT THEO CHU KỲ GÓI — bất biến khoá lại lỗ hổng phát hiện 2026-09-19.
 *
 * Trước đây plans.credits_per_month chỉ được HIỂN THỊ (catalog + trang Quản trị): không có
 * scheduler, không có command, PlanService chỉ tặng bonus_credits một lần ⇒ khách trả 499.000 ₫
 * cho "350 credit/tháng" mà không nhận được credit nào sau tháng đầu.
 *
 * Bất biến:
 *   (a) mỗi chu kỳ cấp ĐÚNG MỘT LẦN, dù gọi nhiều lần / nhiều đường (cron + lazy);
 *   (b) gia hạn (mốc chu kỳ tiến lên) ⇒ cấp tiếp; gói hết hạn ⇒ KHÔNG cấp;
 *   (c) chi phí credit lấy THEO GÓI (plans.image_credit_cost), không còn là cột trang trí;
 *   (d) /api/boot nói đúng gói hiện tại để giao diện hiển thị được.
 */
class PlanCreditCycleTest extends TestCase
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

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    private function grantRows(User $u): int
    {
        return CreditTransaction::where('user_id', $u->id)
            ->where('type', CreditTransaction::TYPE_PLAN_GRANT)
            ->count();
    }

    public function test_assigning_a_paid_plan_grants_the_monthly_credits_immediately(): void
    {
        $u = $this->customer();
        $starter = $this->plan('starter');
        $before = (int) $u->fresh()->credits_balance;

        app(PlanService::class)->assign($u, $starter);

        $fresh = $u->fresh();
        $this->assertSame(
            $before + (int) $starter->bonus_credits + (int) $starter->credits_per_month,
            (int) $fresh->credits_balance,
            'Gán gói trả phí phải cấp NGAY credit của chu kỳ đầu (bonus + credits_per_month).'
        );
        $this->assertSame(1, $this->grantRows($fresh), 'Chu kỳ đầu chỉ được ghi ĐÚNG MỘT dòng sổ cái plan_grant.');
        $this->assertNotNull($fresh->plan_credits_granted_at);
    }

    public function test_second_call_in_the_same_cycle_grants_nothing(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('pro'));

        $balance = (int) $u->fresh()->credits_balance;

        // Mô phỏng cron + lazy cùng chạy trong một chu kỳ.
        $svc = app(PlanService::class);
        $this->assertNull($svc->syncCycleCredits($u->fresh()));
        $this->assertNull($svc->syncCycleCredits($u->fresh()));

        $this->assertSame($balance, (int) $u->fresh()->credits_balance, 'Không được cấp trùng trong cùng chu kỳ.');
        $this->assertSame(1, $this->grantRows($u->fresh()));
    }

    public function test_renewal_opens_a_new_cycle_and_grants_again(): void
    {
        $u = $this->customer();
        $pro = $this->plan('pro');
        app(PlanService::class)->assign($u, $pro);
        $afterFirst = (int) $u->fresh()->credits_balance;

        // Gia hạn 1 tháng nữa ⇒ mốc chu kỳ tiến lên.
        app(PlanService::class)->assign($u->fresh(), $pro);

        $this->assertSame(
            $afterFirst + (int) $pro->credits_per_month,
            (int) $u->fresh()->credits_balance,
            'Gia hạn phải cấp credit của chu kỳ mới (không tặng lại bonus).'
        );
        $this->assertSame(2, $this->grantRows($u->fresh()));
    }

    public function test_expired_plan_grants_nothing(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('starter'));

        $u->forceFill(['plan_expires_at' => now()->subDay(), 'plan_credits_granted_at' => now()->subMonths(2)])->save();
        $before = (int) $u->fresh()->credits_balance;

        $this->assertNull(app(PlanService::class)->syncCycleCredits($u->fresh()));
        $this->assertSame($before, (int) $u->fresh()->credits_balance, 'Gói hết hạn thì KHÔNG cấp credit.');
    }

    public function test_free_plan_with_zero_monthly_credits_grants_nothing(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('free'));

        $this->assertSame(0, (int) $this->plan('free')->credits_per_month);
        $this->assertSame(0, $this->grantRows($u->fresh()));
    }

    public function test_command_grants_for_due_users_and_dry_run_writes_nothing(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('starter'));
        // Đẩy mốc cấp về quá khứ để coi như sang chu kỳ mới.
        $u->forceFill(['plan_credits_granted_at' => now()->subMonths(3)])->save();
        $before = (int) $u->fresh()->credits_balance;

        $this->artisan('studio:grant-plan-credits', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, (int) $u->fresh()->credits_balance, 'dry-run KHÔNG được ghi gì.');

        $this->artisan('studio:grant-plan-credits')->assertSuccessful();
        $this->assertSame(
            $before + (int) $this->plan('starter')->credits_per_month,
            (int) $u->fresh()->credits_balance,
            'Lệnh cron phải cấp credit cho người đã tới kỳ.'
        );
    }

    public function test_generation_uses_the_plan_credit_cost(): void
    {
        $u = $this->customer();
        $pro = $this->plan('pro');
        $pro->update(['image_credit_cost' => 3]);
        app(PlanService::class)->assign($u, $pro->fresh());

        $before = (int) $u->fresh()->credits_balance;

        $this->actingAs($u->fresh())
            ->postJson('/api/generate', ['prompt' => 'áo sơ mi trắng', 'variants' => 1])
            ->assertOk();

        $spent = CreditTransaction::where('user_id', $u->id)
            ->where('type', CreditTransaction::TYPE_SPEND)
            ->latest('id')->first();

        $this->assertNotNull($spent, 'Tạo ảnh phải ghi một dòng sổ cái spend.');
        $this->assertSame(-3, (int) $spent->amount, 'Chi phí phải lấy theo plans.image_credit_cost của gói.');
        $this->assertSame($before - 3, (int) $u->fresh()->credits_balance);
    }

    public function test_boot_exposes_the_active_plan(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('pro'));

        $this->actingAs($u->fresh())
            ->getJson('/api/boot')
            ->assertOk()
            ->assertJsonPath('user.plan.slug', 'pro')
            ->assertJsonPath('user.plan.credits_per_month', (int) $this->plan('pro')->credits_per_month)
            ->assertJsonPath('user.plan.resolution_cap', $this->plan('pro')->resolution_cap);
    }
}
