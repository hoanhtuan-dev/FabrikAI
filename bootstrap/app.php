<?php

use App\Console\Commands\GrantPlanCredits;
use App\Console\Commands\ProcessStudioGenerations;
use App\Http\Middleware\EnsureUserCanUseStudio;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withCommands([ProcessStudioGenerations::class, GrantPlanCredits::class])
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
