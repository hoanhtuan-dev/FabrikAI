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

        // [Đợt 21 — 2026-09-23] BÁO LỖI TỪ TRÌNH DUYỆT: endpoint phải MỞ cho cả khách chưa đăng nhập
        // (lỗi có thể nổ ngay ở trang đăng nhập), nên khoá theo IP là lớp chặn duy nhất. 30 lần/phút
        // rộng hơn nhu cầu thật rất nhiều (một lần tải trang chỉ gửi tối đa 12 báo cáo) mà vẫn chặn
        // được việc dùng endpoint để bơm log.
        RateLimiter::for('client-errors', function (Request $request) {
            return Limit::perMinute(30)->by('client-errors:'.(string) $request->ip());
        });

        // [Q2 — 2026-09-19] Yêu cầu nâng cấp gói: chống spam gửi yêu cầu (khách bấm nhiều lần / bot).
        // Khoá theo user (đã đăng nhập) — 5 yêu cầu/giờ là quá đủ cho nhu cầu thật.
        RateLimiter::for('upgrade-request', function (Request $request) {
            return Limit::perHour(5)->by('upgrade:'.($request->user()?->id ?? $request->ip()));
        });

        $this->registerFalDriver();
    }

    /**
     * DRIVER fal.ai CHO LARAVEL AI SDK — đăng ký TỪ ỨNG DỤNG, không vá vendor (2026-09-26).
     *
     * Vì sao làm được mà không cần sửa package: AiManager kế thừa Illuminate\Support\MultipleInstanceManager,
     * và lớp đó có extend($name, Closure) — cơ chế chính thức để ứng dụng thêm driver riêng. Resolve() ưu
     * tiên customCreators TRƯỚC các method create*Driver dựng sẵn.
     *
     * Vì sao KHÔNG vá vendor: bản vá sẽ mất ở lần composer update kế tiếp, và máy chủ dùng chung đang chặn
     * proc_open nên càng không nên tạo thêm phụ thuộc phải cài đặt lại.
     *
     * Chỉ khai cho NHÓM ẢNH: fal không có endpoint chat kiểu OpenAI (API của nó là hàng đợi
     * queue.fal.run), nên đăng ký nó như provider văn bản là mở một đường gọi SAI.
     */
    private function registerFalDriver(): void
    {
        if (! class_exists(\Laravel\Ai\AiManager::class)) {
            return;
        }

        app(\Laravel\Ai\AiManager::class)->extend('fal', function ($app, array $config) {
            return new \App\Ai\Providers\FalProvider(
                $config,
                $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
            );
        });
    }
}
