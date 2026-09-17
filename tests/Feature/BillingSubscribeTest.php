<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gói đăng ký (billing): catalog công khai + người dùng tự đăng ký gói.
 */
class BillingSubscribeTest extends TestCase
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

    public function test_catalog_returns_active_plans_publicly(): void
    {
        $this->getJson('/api/billing/plans')
            ->assertOk()
            ->assertJsonCount(4, 'plans')
            ->assertJsonPath('plans.0.slug', 'free');
    }

    public function test_customer_can_subscribe_to_paid_plan(): void
    {
        $u = $this->customer();
        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $before = (int) $u->fresh()->credits_balance;

        $this->actingAs($u)
            ->postJson('/api/billing/subscribe', ['plan_id' => $starter->id])
            ->assertOk()
            ->assertJsonPath('plan.slug', 'starter');

        $fresh = $u->fresh();
        $this->assertSame($starter->id, (int) $fresh->plan_id);
        $this->assertNotNull($fresh->plan_expires_at);
        $this->assertTrue($fresh->plan_expires_at->isFuture());
        // [2026-09-19] Đăng ký gói trả phí cấp NGAY cả bonus (một lần) LẪN credit của chu kỳ đầu
        // (plans.credits_per_month). Trước đây chỉ có bonus vì chưa có đường cấp credit định kỳ.
        $this->assertSame(
            $before + (int) $starter->bonus_credits + (int) $starter->credits_per_month,
            (int) $fresh->credits_balance,
        );
        $this->assertTrue($fresh->isSubscribed());
    }

    public function test_subscribe_to_free_plan_sets_no_expiry(): void
    {
        $u = $this->customer();
        $free = Plan::where('slug', 'free')->firstOrFail();

        $this->actingAs($u)
            ->postJson('/api/billing/subscribe', ['plan_id' => $free->id])
            ->assertOk()
            ->assertJsonPath('plan.is_free', true);

        $fresh = $u->fresh();
        $this->assertSame($free->id, (int) $fresh->plan_id);
        $this->assertNull($fresh->plan_expires_at);
        $this->assertFalse($fresh->isSubscribed());
    }

    public function test_guest_cannot_subscribe(): void
    {
        $starter = Plan::where('slug', 'starter')->firstOrFail();
        $this->postJson('/api/billing/subscribe', ['plan_id' => $starter->id])->assertStatus(401);
    }

    public function test_subscribe_to_unknown_plan_is_rejected(): void
    {
        $this->actingAs($this->customer())
            ->postJson('/api/billing/subscribe', ['plan_id' => 99999])
            ->assertStatus(422);
    }

    public function test_bonus_grant_is_recorded_in_ledger(): void
    {
        $u = $this->customer();
        $pro = Plan::where('slug', 'pro')->firstOrFail();

        $this->actingAs($u)->postJson('/api/billing/subscribe', ['plan_id' => $pro->id])->assertOk();

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $u->id,
            'type' => 'grant',
            'amount' => (int) $pro->bonus_credits,
        ]);
    }
}
