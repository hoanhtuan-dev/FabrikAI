<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chống LEO QUYỀN / tự cộng credit qua mass-assignment.
 *
 * Bối cảnh: User::$fillable từng chứa 'role', 'is_active' và 'credits_balance'. Không có endpoint
 * nào hiện tại ghi vào User bằng dữ liệu request (0 route quản lý tài khoản), nên đây là rủi ro
 * TIỀM ẨN — nhưng chỉ cần một endpoint tương lai làm $user->update($request->all()) là leo quyền
 * lên admin + tự cộng credit ngay. Đã siết $fillable theo đúng tiền lệ K.1 F4 (đã làm cho Project).
 *
 * File này khoá: (a) mass-assignment không set được field đặc quyền, (b) đường chính thức
 * forceFill() vẫn hoạt động, (c) seeder vẫn tạo đúng role/credit, (d) đăng ký công khai không
 * thể tự nâng quyền.
 */
class UserPrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_create_cannot_set_privileged_attributes(): void
    {
        $u = User::create([
            'name' => 'Kẻ tấn công',
            'email' => 'attacker@example.com',
            'password' => 'matkhau123',
            'role' => User::ROLE_SUPER_ADMIN,     // phải bị bỏ qua
            'is_active' => false,                  // phải bị bỏ qua
            'credits_balance' => 999999,           // phải bị bỏ qua
        ]);

        $fresh = $u->fresh();
        $this->assertSame(User::ROLE_CUSTOMER, $fresh->role, 'Mass-assignment KHÔNG được set role.');
        $this->assertNotSame(999999, (int) $fresh->credits_balance, 'Mass-assignment KHÔNG được set credits_balance.');
        $this->assertNotFalse((bool) $fresh->is_active, 'Mass-assignment KHÔNG được tắt is_active.');
    }

    public function test_update_cannot_set_privileged_attributes(): void
    {
        $u = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $u->forceFill(['credits_balance' => 10])->save();

        $u->update([
            'role' => User::ROLE_ADMIN,
            'credits_balance' => 500000,
            'is_active' => false,
        ]);

        $fresh = $u->fresh();
        $this->assertSame(User::ROLE_CUSTOMER, $fresh->role, 'update() KHÔNG được nâng role.');
        $this->assertSame(10, (int) $fresh->credits_balance, 'update() KHÔNG được đổi credits_balance.');
        $this->assertNotFalse((bool) $fresh->is_active);
    }

    public function test_force_fill_still_works_for_official_paths(): void
    {
        // Nếu cả forceFill cũng bị chặn thì đã siết quá tay — đường chính thức phải còn dùng được.
        $u = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $u->forceFill(['role' => User::ROLE_ADMIN, 'credits_balance' => 42])->save();

        $this->assertSame(User::ROLE_ADMIN, $u->fresh()->role);
        $this->assertSame(42, (int) $u->fresh()->credits_balance);
    }

    public function test_seeder_still_assigns_roles_and_credits(): void
    {
        // Seeder ghi role/credits bằng forceFill sau khi siết $fillable — test này bảo vệ chính
        // thay đổi đó (nếu seeder quay lại mass-assign, admin sẽ thành 'customer' và toàn bộ route
        // admin sẽ 403).
        $super = User::where('email', 'owner@fabrikai.shop')->first();
        $admin = User::where('email', 'admin@fabrikai.shop')->first();
        $customer = User::where('email', 'user@fabrikai.shop')->first();

        $this->assertNotNull($super);
        $this->assertTrue($super->isSuperAdmin());
        $this->assertNotNull($admin);
        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue($admin->isAdmin());
        $this->assertSame(1000, (int) $admin->credits_balance);
        $this->assertNotNull($customer);
        $this->assertSame(User::ROLE_CUSTOMER, $customer->role);
        $this->assertSame(200, (int) $customer->credits_balance);
    }

    public function test_public_registration_cannot_escalate_role(): void
    {
        // /dang-ky là route CÔNG KHAI: gửi kèm role/credits phải bị bỏ qua.
        $this->post('/dang-ky', [
            'name' => 'Người đăng ký',
            'email' => 'register-escalate@example.com',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
            'role' => User::ROLE_SUPER_ADMIN,
            'credits_balance' => 999999,
        ]);

        $u = User::where('email', 'register-escalate@example.com')->first();
        $this->assertNotNull($u, 'Đăng ký phải tạo được tài khoản.');
        $this->assertSame(User::ROLE_CUSTOMER, $u->role, 'Đăng ký công khai KHÔNG được tự nâng quyền.');
        $this->assertFalse($u->isAdmin());
        $this->assertNotSame(999999, (int) $u->credits_balance);
    }

    public function test_role_default_is_declared_on_the_model_not_only_in_the_schema(): void
    {
        // [BUG ĐÃ SỬA 2026-09-17] AuthController::register() từng truyền 'role' => 'customer' trong
        // User::create([...]). Nhưng 'role' KHÔNG nằm trong $fillable (cố ý chống leo quyền) nên dòng
        // đó là NO-OP im lặng: nó chẳng set gì cả. Đăng ký ra đúng 'customer' CHỈ NHỜ default của cột
        // DB — tức hành vi đúng đang phụ thuộc schema, không phụ thuộc code.
        //
        // Test này khoá mặc định ở TẦNG MODEL để nó không phụ thuộc cột DB nữa.
        $fresh = new User();

        $this->assertSame(User::ROLE_CUSTOMER, $fresh->role,
            'Model phải tự khai báo role mặc định (User::$attributes), không dựa vào default cột DB.');
    }

    public function test_user_created_without_a_role_still_ends_up_as_customer(): void
    {
        // ⚠️ Ghi chú trung thực: test này KHÔNG chứng minh được điều tên nó từng nói ("không cần
        // default của schema") — vì schema vẫn có default 'customer', nên nó XANH kể cả khi đã gỡ
        // User::$attributes (mutation-test phát hiện: chỉ 1 test đỏ, không phải 2).
        // Nó vẫn có giá trị như một khẳng định HÀNH VI đầu-cuối: tạo user không truyền role thì ra
        // 'customer'. Việc khoá mặc định ở TẦNG MODEL do test phía trên đảm nhiệm.
        $u = User::create([
            'name' => 'Kiểm mặc định',
            'email' => 'default-role@example.com',
            'password' => 'matkhau123',
        ]);

        $this->assertSame(User::ROLE_CUSTOMER, $u->fresh()->role);
        $this->assertFalse($u->fresh()->isAdmin());
    }
}
