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

    /**
     * Xoá memo danh sách custom provider ở MỌI đường ghi qua Eloquent — nếu không,
     * worker queue (tiến trình sống lâu) vẫn xếp hạng provider theo danh sách cũ sau
     * khi admin thêm/sửa/xoá route ở request khác.
     */
    protected static function booted(): void
    {
        $forget = static function () {
            if (function_exists('studio_custom_provider_slugs')) {
                studio_custom_provider_slugs(true);
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /** Route key used by StudioModel.provider / StudioApiKey.provider. */
    public function routeKey(): string
    {
        return $this->slug;
    }
}
