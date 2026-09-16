<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
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
    }
}
