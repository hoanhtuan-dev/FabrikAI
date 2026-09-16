<?php
namespace App\Modules\Studio;

use Illuminate\Support\ServiceProvider;

/**
 * Studio module — wires the Studio feature as a self-contained, reusable unit.
 */
class StudioModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config.php', 'studio_module');
        $this->app->singleton('studio.stylist_catalog', fn () => new \App\Services\StylistCatalog());
        // Designer project workflow — shared engine cho ProjectController + Studio UI.
        $this->app->singleton('studio.project_workflow', fn () => new \App\Services\ProjectWorkflowService());
    }

    public function boot(): void
    {
        // Studio views live in resources/views/studio (default app namespace).
        $this->app->bind('studio.bridge', fn () => new StudioBridge());
    }
}
