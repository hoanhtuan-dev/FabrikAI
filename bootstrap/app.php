<?php

use App\Console\Commands\CleanStudioStorage;
use App\Console\Commands\GrantPlanCredits;
use App\Console\Commands\ProcessStudioGenerations;
use App\Console\Commands\StudioPricingCommand;
use App\Console\Commands\StudioWebhookDoctorCommand;
use App\Console\Commands\ThemeSync;
use App\Http\Middleware\EnsureUserCanUseStudio;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withCommands([CleanStudioStorage::class, ProcessStudioGenerations::class, GrantPlanCredits::class, ThemeSync::class, StudioPricingCommand::class, StudioWebhookDoctorCommand::class])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            // [2026-09-17 · Đợt 0.1] Cổng vào nhóm API STUDIO — admin + customer đang hoạt động.
            'can-studio' => EnsureUserCanUseStudio::class,
            'superadmin' => \App\Http\Middleware\EnsureUserIsSuperAdmin::class,
            'nostore' => \App\Http\Middleware\NoStoreCache::class,
        ]);
        // Apply no-store to ALL web responses so the browser never caches any auth/redirect
        // (definitively fixes ERR_TOO_MANY_REDIRECTS from stale cached redirects).
        $middleware->web(append: [\App\Http\Middleware\NoStoreCache::class]);
        // [2026-09-26] Webhook fal + cron ngoài là POST đến từ bên thứ ba (fal.ai / cron-job.org),
        // không có CSRF token. Xác thực bằng token riêng trong controller, không phải bằng CSRF.
        // KHÔNG đưa route khác vào danh sách này — đây là cửa hậu, mở hẹp nhất có thể.
        $middleware->validateCsrfTokens(except: ['api/cron/tick', 'api/webhooks/*']);
        // [Modules 2026-09-19] CÔNG TẮC MODULE: chặn ở backend theo bản khai ModuleRegistry (gói nào cấp
        // module nào · module bị tắt toàn cục). Đặt SAU auth trong chuỗi nên chỉ chạy khi đã biết người dùng.
        $middleware->web(append: [\App\Http\Middleware\EnforceModules::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// FabrikAI uses 'public_html' as the web root (Hostinger shared hosting).
// Rebinding public_path() keeps public_path(), secure asset resolution,
// 'php artisan serve' and 'storage:link' all pointing at public_html/.
$app->usePublicPath(base_path('public_html'));

return $app;
