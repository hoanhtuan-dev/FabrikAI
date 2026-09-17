<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Xác minh 2026-09-17] TRANG QUẢN TRỊ /admin — shell phải được BẢO VỆ.
 *
 * Phát hiện khi đối chiếu việc song song: route `GET /admin` được khai trong nhóm
 * "SPA pages (Blade shells)" KHÔNG middleware, nên BẤT KỲ AI cũng tải được vỏ console Owner.
 * Điều này trái với mô hình đã ghi ở đầu routes/web.php:
 *     · ADMIN (auth + admin) : cấu hình & quản trị TOÀN CỤC
 * Và AdminApp.vue KHÔNG hề tự chuyển hướng khi thiếu quyền.
 *
 * Bất biến khoá ở đây: khách ⇒ về trang đăng nhập; customer ⇒ 403; admin ⇒ 200.
 */
class AdminConsoleAccessTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/dang-nhap');
    }

    public function test_customer_cannot_open_the_owner_console(): void
    {
        $this->actingAs($this->customer())->get('/admin')->assertForbidden();
    }

    public function test_admin_can_open_the_owner_console(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk();
    }
}
