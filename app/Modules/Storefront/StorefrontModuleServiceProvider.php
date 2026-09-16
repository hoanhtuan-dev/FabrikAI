<?php

namespace App\Modules\Storefront;

use Illuminate\Support\ServiceProvider;

/**
 * Storefront module — the public-facing home page served at "/", rebuilt as a
 * self-contained Vue 3 + Pinia SPA alongside the Studio module.
 *
 * Mirrors App\Modules\Studio\StudioModuleServiceProvider: it merges config,
 * binds a bridge used by the controllers/views and loads its own route file so
 * the feature stays a closed, reusable unit rather than being smeared across
 * the app's routes/web.php.
 */
class StorefrontModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'storefront_module');
    }

    public function boot(): void
    {
        // Bridge = contract between the backend data and the Vue storefront.
        // Bound as a shared singleton so controllers and views agree on shape.
        $this->app->singleton('storefront.bridge', fn () => new StorefrontBridge());

        // Module-owned routes: the homepage plus its JSON data feed.
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');
    }
}
