<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Seeder là chỗ dễ lộ quyền nhất: một tài khoản quyền cao nhất với mật khẩu hardcode nằm trong
 * repo PUBLIC nghĩa là **ai đọc repo cũng vào được**. Bản cũ đúng như vậy:
 * §BT§tuan.ho.designer@gmail.com§BT§ / mật khẩu hardcode, VÀ dùng §BT§updateOrCreate§BT§ nên mỗi lần
 * chạy lại seeder là **ghi đè** mật khẩu về giá trị đã lộ — người vận hành đổi mật khẩu xong vẫn bị reset.
 *
 * File này khoá 4 bất biến:
 *   1. Không còn mật khẩu hardcode cho tài khoản quản trị trong seeder.
 *   2. Chạy lại seeder KHÔNG được ghi đè mật khẩu đã có.
 *   3. Ở production, thiếu env mật khẩu ⇒ NÉM LỖI (fail-closed), không tạo tài khoản với mật khẩu đã lộ.
 *   4. Dev/test vẫn seed được bình thường (nếu không, cả bộ test sẽ không chạy được).
 */
class SeederCredentialSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** Mật khẩu super admin từng bị hardcode và LỘ trong repo public. */
    private const LEAKED_PASSWORD = 'hattf2768';

    // ── 1. Không còn mật khẩu hardcode ───────────────────────────────────

    public function test_seeder_has_no_hardcoded_privileged_password(): void
    {
        $src = (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertStringNotContainsString(self::LEAKED_PASSWORD, $src,
            'Mật khẩu super admin từng bị lộ đã quay trở lại seeder.');

        // Tài khoản quản trị PHẢI lấy mật khẩu qua seedPassword(<env key>).
        $this->assertStringContainsString("seedPassword('SEED_SUPER_ADMIN_PASSWORD'", $src);
        $this->assertStringContainsString("seedPassword('SEED_ADMIN_PASSWORD'", $src);
    }

    public function test_privileged_accounts_are_created_with_first_or_create_not_update_or_create(): void
    {
        $src = (string) file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        // updateOrCreate trên tài khoản quản trị = ghi đè mật khẩu mỗi lần seed (đúng lỗi cũ).
        foreach (['tuan.ho.designer@gmail.com', 'admin@trillfa.com'] as $email) {
            $this->assertDoesNotMatchRegularExpression(
                "/updateOrCreate\(\['email' => '".preg_quote($email, '/')."'/",
                $src,
                "Tài khoản {$email} lại dùng updateOrCreate — seeder sẽ reset mật khẩu về mặc định."
            );
        }
    }

    // ── 2. Không ghi đè mật khẩu đã đổi ──────────────────────────────────

    public function test_reseeding_does_not_reset_an_existing_admin_password(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@trillfa.com')->firstOrFail();
        $admin->password = Hash::make('mat-khau-rieng-cua-toi');
        $admin->save();

        $this->seed();   // chạy lại y như một lần deploy

        $this->assertTrue(Hash::check('mat-khau-rieng-cua-toi', $admin->fresh()->password),
            'Chạy lại seeder đã RESET mật khẩu quản trị — người vận hành đổi mật khẩu rồi vẫn bị ghi đè.');
    }

    public function test_reseeding_does_not_reset_the_super_admin_password(): void
    {
        $this->seed();

        $super = User::where('email', 'tuan.ho.designer@gmail.com')->firstOrFail();
        $super->password = Hash::make('mat-khau-super-rieng');
        $super->save();

        $this->seed();

        $this->assertTrue(Hash::check('mat-khau-super-rieng', $super->fresh()->password));
    }

    // ── 3. Production: fail-closed ───────────────────────────────────────

    public function test_production_seeding_fails_closed_when_password_env_is_missing(): void
    {
        app()->detectEnvironment(fn () => 'production');

        // Dùng --force đúng như deploy thật: ở production `db:seed` sẽ hỏi xác nhận tương tác,
        // nên bỏ qua bước đó mới chạm tới logic của seeder.
        $caught = null;
        try {
            $this->artisan('db:seed', ['--force' => true]);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught,
            'Ở production, thiếu SEED_SUPER_ADMIN_PASSWORD phải NÉM LỖI thay vì tạo tài khoản với mật khẩu mặc định đã lộ.');
        $this->assertStringContainsString('SEED_SUPER_ADMIN_PASSWORD', $caught->getMessage());

        // Và tuyệt đối KHÔNG được tạo tài khoản quản trị nào.
        $this->assertSame(0, User::where('email', 'admin@trillfa.com')->count());
        $this->assertSame(0, User::where('email', 'tuan.ho.designer@gmail.com')->count());
    }

    public function test_production_seeding_uses_the_env_password_when_provided(): void
    {
        app()->detectEnvironment(fn () => 'production');
        putenv('SEED_SUPER_ADMIN_PASSWORD=mat-khau-production-rat-manh');
        putenv('SEED_ADMIN_PASSWORD=mat-khau-admin-production');
        $_ENV['SEED_SUPER_ADMIN_PASSWORD'] = $_SERVER['SEED_SUPER_ADMIN_PASSWORD'] = 'mat-khau-production-rat-manh';
        $_ENV['SEED_ADMIN_PASSWORD'] = $_SERVER['SEED_ADMIN_PASSWORD'] = 'mat-khau-admin-production';

        try {
            $this->artisan('db:seed', ['--force' => true]);

            $super = User::where('email', 'tuan.ho.designer@gmail.com')->firstOrFail();
            $this->assertTrue(Hash::check('mat-khau-production-rat-manh', $super->password));

            // Không đổ dữ liệu demo vào production.
            $this->assertSame(0, User::where('email', 'customer@trillfa.com')->count());
            $this->assertNotContains(self::LEAKED_PASSWORD, [$super->password]);
        } finally {
            putenv('SEED_SUPER_ADMIN_PASSWORD');
            putenv('SEED_ADMIN_PASSWORD');
            unset($_ENV['SEED_SUPER_ADMIN_PASSWORD'], $_SERVER['SEED_SUPER_ADMIN_PASSWORD']);
            unset($_ENV['SEED_ADMIN_PASSWORD'], $_SERVER['SEED_ADMIN_PASSWORD']);
        }
    }

    // ── 4. Dev/test vẫn chạy bình thường ─────────────────────────────────

    public function test_dev_seeding_creates_verified_privileged_accounts(): void
    {
        $this->seed();

        $super = User::where('email', 'tuan.ho.designer@gmail.com')->firstOrFail();
        $admin = User::where('email', 'admin@trillfa.com')->firstOrFail();

        $this->assertSame(User::ROLE_SUPER_ADMIN, $super->role);
        $this->assertSame(User::ROLE_ADMIN, $admin->role);

        // email_verified_at KHÔNG nằm trong $fillable nên bản cũ ghi qua updateOrCreate() đã bị nuốt
        // -> tài khoản seed ra ở trạng thái CHƯA xác thực email. Nay ghi tường minh bằng forceFill().
        $this->assertNotNull($super->email_verified_at, 'Tài khoản seed phải được đánh dấu đã xác thực email.');
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_dev_seeding_still_creates_demo_customers(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, User::where('role', User::ROLE_CUSTOMER)->count(),
            'Dev/test vẫn phải có khách hàng mẫu, nếu không nhiều test khác sẽ đổ.');
    }
}
