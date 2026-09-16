<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One user-declared provider route (DeepSeek Harness "custom provider" model):
 * a profile that names its own protocol, base URL, and auth style, plus the
 * StudioApiKey provider slug that holds its secret. Built-in providers are
 * hardcoded in helpers (studio_provider_catalog()); rows here only extend them.
 */
class StudioProvider extends Model
{
    protected $table = 'studio_providers';

    protected $fillable = [
        'slug', 'name', 'protocol', 'base_url', 'auth_style',
        'api_key_ref', 'enabled', 'note',
    ];

    protected $casts = ['enabled' => 'boolean'];

    /** Route key used by StudioModel.provider / StudioApiKey.provider. */
    public function routeKey(): string
    {
        return $this->slug;
    }
}
