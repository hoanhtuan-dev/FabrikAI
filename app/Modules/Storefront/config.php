<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Storefront module configuration
    |--------------------------------------------------------------------------
    | Merged under the `storefront_module` config key. The Storefront is the
    | public-facing homepage SPA served at `/`. It mirrors the Studio module
    | pattern (provider + config + bridge) and owns its own Vue + Pinia entry.
    */

    // Default number of products per widget grid when no setting is stored.
    'default_limit' => 8,

    // Base path used by the module's public assets / routes.
    'asset_base' => env('STOREFRONT_ASSET_BASE', '/'),

    // Whether the public storefront mounts the SPA (set false to fall back to
    // the legacy Blade home.blade.php — handy as a kill switch / rollback).
    'spa_enabled' => (bool) env('STOREFRONT_SPA_ENABLED', true),
];
