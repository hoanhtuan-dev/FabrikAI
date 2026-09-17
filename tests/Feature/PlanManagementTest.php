<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gói cước: seed đúng, CRUD đúng phân quyền, gán gói đúng (hết hạn + bonus credit).
 */
class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function super(): User
    {
        return User::where('email', 'owner@fabrikai.shop')->firstOrFail();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    public function test_seeder_creates_plans_with_single_default(): void
    {
        $this->assertGreaterThanOrEqual(4, Plan::count());
        $this->assertSame(1, Plan::where('is_default', true)->count(), 'Phải có đúng MỘT gói mặc định.');
        $this->assertNotNull(Plan::where('slug', 'free')->first());
        $this->assertNotNull(Plan::where('slug', 'pro')->first());
    }

    public function test_admin_can_create_and_list_plans(): void
    {
        $this->actingAs($this->super())->getJson('/api/admin/plans')->assertOk();

        $this->actingAs($this->super())->postJson('/api/admin/plans', [
            'name' => 'Gói thử', 'slug' => 'trial-x', 'price_vnd' => 99000, 'credits_per_month' => 60,
            'features' => ['a', 'b'], 'is_active' => true, 'sort' => 9,
        ])->assertCreated();

        $this->assertDatabaseHas('plans', ['slug' => 'trial-x', 'price_vnd' => 99000]);
    }

    public function test_customer_cannot_manage_plans(): void
    {
        $this->actingAs($this->customer())->getJson('/api/admin/plans')->assertForbidden();
        $this->actingAs($this->customer())->postJson('/api/admin/plans', [])->assertForbidden();
    }

    public function test_cannot_delete_default_plan(): void
    {
        $free = Plan::where('slug', 'free')->firstOrFail();
        $this->actingAs($this->super())->deleteJson('/api/admin/plans/'.$free->id)->assertStatus(422);
    }

    public function test_plan_service_assigns_paid_plan_with_expiry_and_bonus(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['credits_balance' => 100])->save();
        $u = $u->fresh();

        $starter = Plan::where('slug', 'starter')->firstOrFail();
        app(PlanService::class)->assign($u, $starter);

        $fresh = $u->fresh();
        $this->assertSame($starter->id, (int) $fresh->plan_id);
        $this->assertNotNull($fresh->plan_expires_at);
        $this->assertTrue($fresh->plan_expires_at->isFuture());
        // [2026-09-19] Gán gói cấp: bonus (một lần khi đổi gói) + credit chu kỳ đầu (credits_per_month).
        $this->assertSame(
            100 + (int) $starter->bonus_credits + (int) $starter->credits_per_month,
            (int) $fresh->credits_balance,
            'Tặng bonus một lần khi đổi gói + cấp credit chu kỳ đầu.'
        );
    }

    public function test_free_plan_has_no_expiry(): void
    {
        $u = User::factory()->create();
        $free = Plan::where('slug', 'free')->firstOrFail();
        app(PlanService::class)->assign($u, $free);

        $fresh = $u->fresh();
        $this->assertSame($free->id, (int) $fresh->plan_id);
        $this->assertNull($fresh->plan_expires_at);
        $this->assertFalse($fresh->isSubscribed());
    }
}
