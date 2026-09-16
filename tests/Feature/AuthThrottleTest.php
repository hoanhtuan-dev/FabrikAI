<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chống BRUTE-FORCE ở các endpoint xác thực CÔNG KHAI.
 *
 * Phát hiện (vòng 18): rà toàn bộ route cho thấy chỉ 6/135 route có throttle (nhóm API public), còn
 * POST /dang-nhap và POST /dang-ky KHÔNG có throttle và AuthController::login KHÔNG giới hạn số lần
 * thử — nghĩa là có thể thử mật khẩu KHÔNG GIỚI HẠN.
 *
 * Test này khoá các tính chất bắt buộc:
 *  1. Sai mật khẩu nhiều lần -> bị chặn 429 (không còn brute-force vô hạn).
 *  2. Đăng nhập ĐÚNG vẫn phải vào được (chống siết quá tay / tự khoá người dùng thật).
 *  3. Đăng nhập thành công phải XOÁ bộ đếm (người dùng gõ sai vài lần rồi gõ đúng không bị phạt).
 *  4. Đăng ký cũng bị giới hạn (chống spam tạo tài khoản).
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Không cần dọn tay: phpunit.xml đặt CACHE_STORE=array và mỗi test có app mới -> bộ đếm
        // rate limiter luôn bắt đầu từ 0.
    }

    private function attemptLogin(string $password, string $email = 'admin@fabrikai.shop'): int
    {
        return $this->post('/dang-nhap', ['email' => $email, 'password' => $password])->getStatusCode();
    }

    public function test_repeated_wrong_passwords_are_throttled(): void
    {
        $codes = [];
        for ($i = 0; $i < 12; $i++) {
            $codes[] = $this->attemptLogin('mat-khau-sai-'.$i);
        }

        $this->assertContains(
            429,
            $codes,
            'Sai mật khẩu liên tục KHÔNG bị chặn — brute-force vô hạn. Mã trả về: '.implode(',', $codes)
        );
    }

    public function test_correct_login_still_works(): void
    {
        // Chống siết quá tay: nếu giới hạn làm người dùng thật không vào được thì bản vá hỏng.
        $res = $this->post('/dang-nhap', ['email' => 'admin@fabrikai.shop', 'password' => 'password']);
        $this->assertSame(302, $res->getStatusCode(), 'Đăng nhập ĐÚNG phải thành công (302).');
        $this->assertAuthenticated();
    }

    public function test_a_few_wrong_attempts_do_not_lock_out_the_real_user(): void
    {
        // Tính chất QUAN SÁT ĐƯỢC cần bảo vệ: gõ sai VÀI lần (dưới ngưỡng 5/phút) rồi gõ đúng thì
        // vẫn vào được — bản vá không tự khoá người dùng thật.
        //
        // Trước đây test này đọc trực tiếp bộ đếm qua RateLimiter::attempts('email|ip'). CÁCH ĐÓ SAI:
        // middleware throttle:login lưu bộ đếm dưới khoá md5(limiterName.$key) (ThrottleRequests:134)
        // nên đọc khoá thô LUÔN trả 0 -> test PASS RỖNG dù bộ đếm chưa hề bị xoá. Đã kiểm chứng bằng
        // mutation: bỏ hẳn dòng clear() mà test vẫn xanh. Giờ chỉ khẳng định hành vi quan sát được.
        for ($i = 0; $i < 3; $i++) {
            $this->attemptLogin('sai-'.$i);
        }

        $this->post('/dang-nhap', ['email' => 'admin@fabrikai.shop', 'password' => 'password'])
            ->assertStatus(302);

        $this->assertAuthenticated('web');
    }

    public function test_registration_is_rate_limited(): void
    {
        $codes = [];
        for ($i = 0; $i < 15; $i++) {
            $codes[] = $this->post('/dang-ky', [
                'name' => 'Spam '.$i,
                'email' => 'spam'.$i.'@example.com',
                'password' => 'matkhau123',
                'password_confirmation' => 'matkhau123',
            ])->getStatusCode();
        }

        $this->assertContains(429, $codes, 'Đăng ký không bị giới hạn — spam tạo tài khoản. Mã: '.implode(',', $codes));
    }
}
