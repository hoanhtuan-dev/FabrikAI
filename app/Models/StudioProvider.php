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
        'api_key_ref', 'priority', 'enabled', 'note',
    ];

    protected $casts = ['enabled' => 'boolean', 'priority' => 'integer'];

    /**
     * Xoá memo danh sách custom provider ở MỌI đường ghi qua Eloquent — nếu không,
     * worker queue (tiến trình sống lâu) vẫn xếp hạng provider theo danh sách cũ sau
     * khi admin thêm/sửa/xoá route ở request khác.
     */
    protected static function booted(): void
    {
        $forget = static function () {
            if (function_exists('studio_custom_provider_map')) {
                studio_custom_provider_map(true);
            } elseif (function_exists('studio_custom_provider_slugs')) {
                studio_custom_provider_slugs(true);
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Bind route theo SLUG (không phải id số) — slug mới là khoá mà StudioModel.provider
     * và StudioApiKey.provider tham chiếu, và là thứ payload /settings-vue/data trả về.
     *
     * ⚠️ Laravel chỉ đọc getRouteKeyName(); một method tên routeKey() KHÔNG có tác dụng
     * bind (trước đây model khai routeKey() nên bind vẫn theo id, trong khi UI chỉ có slug
     * ⇒ DELETE/PUT luôn 404 và không xoá/sửa được provider).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
