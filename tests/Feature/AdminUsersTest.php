<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phân quyền trang Quản trị (/admin) + hành vi quản lý người dùng.
 *
 * Bất biến 2 tầng:
 *   · dashboard / plans / transactions: admin (super_admin + admin) dùng được, customer 403, guest 401.
 *   · users (list/create/update/delete/credit/reset-password): CHỈ super_admin (đúng UserPolicy).
 */
class AdminUsersTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    public function test_super_admin_can_list_users(): void
    {
        $this->actingAs($this->super())->getJson('/api/admin/users')->assertOk();
    }

    public function test_regular_admin_can_view_dashboard_plans_ledger(): void
    {
        $this->actingAs($this->admin())->getJson('/api/admin/dashboard')->assertOk();
        $this->actingAs($this->admin())->getJson('/api/admin/plans')->assertOk();
        $this->actingAs($this->admin())->getJson('/api/admin/transactions')->assertOk();
    }

    public function test_regular_admin_cannot_manage_users(): void
    {
        $this->actingAs($this->admin())->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($this->admin())->postJson('/api/admin/users', [])->assertForbidden();
    }

    public function test_customer_cannot_access_any_admin_endpoint(): void
    {
        $this->actingAs($this->customer())->getJson('/api/admin/dashboard')->assertForbidden();
        $this->actingAs($this->customer())->getJson('/api/admin/plans')->assertForbidden();
        $this->actingAs($this->customer())->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_guest_gets_401_on_admin_endpoints(): void
    {
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    public function test_super_admin_can_create_user_with_role(): void
    {
        $this->actingAs($this->super())->postJson('/api/admin/users', [
            'name' => 'Người mới',
            'email' => 'new-admin@example.com',
            'password' => 'matkhau123',
            'role' => 'admin',
        ])->assertCreated();

        $u = User::where('email', 'new-admin@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_ADMIN, $u->role);
        $this->assertTrue($u->isAdmin());
    }

    public function test_super_admin_can_adjust_credits_with_ledger(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['credits_balance' => 100])->save();

        $this->actingAs($this->super())
            ->postJson('/api/admin/users/'.$u->id.'/credits', ['amount' => 250, 'note' => 'nạp thử'])
            ->assertOk();

        $this->assertSame(350, (int) $u->fresh()->credits_balance);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $u->id,
            'type' => 'adjust',
            'amount' => 250,
            'balance_after' => 350,
        ]);
    }

    public function test_super_admin_can_ban_and_unban_user(): void
    {
        $u = User::factory()->create();

        $this->actingAs($this->super())->putJson('/api/admin/users/'.$u->id, [
            'name' => $u->name, 'email' => $u->email, 'role' => 'customer', 'is_active' => false,
        ])->assertOk();
        $this->assertFalse((bool) $u->fresh()->is_active);

        $this->actingAs($this->super())->putJson('/api/admin/users/'.$u->id, [
            'name' => $u->name, 'email' => $u->email, 'role' => 'customer', 'is_active' => true,
        ])->assertOk();
        $this->assertTrue((bool) $u->fresh()->is_active);
    }

    public function test_super_admin_cannot_demote_self(): void
    {
        $super = $this->super();

        $this->actingAs($super)->putJson('/api/admin/users/'.$super->id, [
            'name' => $super->name, 'email' => $super->email, 'role' => 'admin',
        ])->assertStatus(422);
    }

    public function test_super_admin_can_reset_password(): void
    {
        $u = User::factory()->create();
        $this->actingAs($this->super())
            ->postJson('/api/admin/users/'.$u->id.'/reset-password', ['password' => 'matkhau-moi-123'])
            ->assertOk();
    }

    public function test_credit_adjust_amount_zero_is_rejected(): void
    {
        $u = User::factory()->create();
        $this->actingAs($this->super())
            ->postJson('/api/admin/users/'.$u->id.'/credits', ['amount' => 0])
            ->assertStatus(422);
    }
}
