<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Phân quyền quản lý tài khoản: chỉ Super Admin (chuẩn Laravel Policy).
        Gate::policy(User::class, UserPolicy::class);

        // [BẢO MẬT — vòng 18] Chống brute-force ở endpoint xác thực CÔNG KHAI.
        // Trước đây chỉ 6/135 route có throttle và POST /dang-nhap KHÔNG có gì: thử mật khẩu
        // không giới hạn. Khoá đếm theo EMAIL + IP (không chỉ IP) để một IP dùng chung không
        // khoá oan người khác, mà kẻ tấn công cũng không rải được nhiều email từ một IP.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(auth_throttle_key($request));
        });

        // Đăng ký: chặn spam tạo tài khoản. Khoá theo IP (chưa có danh tính để phân biệt).
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        // [Đợt 4 — 2026-09-19] Phản hồi trên LINK CHIA SẺ công khai: người gửi không có tài khoản nên
        // chỉ khoá được theo IP. 10 lần/phút đủ rộng cho người dùng thật mà vẫn chặn spam vào sổ phản hồi.
        RateLimiter::for('share-feedback', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // [Q2 — 2026-09-19] Yêu cầu nâng cấp gói: chống spam gửi yêu cầu (khách bấm nhiều lần / bot).
        // Khoá theo user (đã đăng nhập) — 5 yêu cầu/giờ là quá đủ cho nhu cầu thật.
        RateLimiter::for('upgrade-request', function (Request $request) {
            return Limit::perHour(5)->by('upgrade:'.($request->user()?->id ?? $request->ip()));
        });
    }
}
