<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioModel extends Model
{
    protected $table = 'studio_models';
    protected $fillable = ['group', 'name', 'provider', 'model_id', 'api_key_ref', 'priority', 'enabled', 'note'];
    protected $casts = ['enabled' => 'boolean'];

    /** Khoá cache: model đang BẬT, nhóm sẵn theo 'group' (xem studio_models_enabled_by_group()). */
    public const CACHE_KEY = 'studio_models_enabled';

    /** Khoá cache: TOÀN BỘ registry, không lọc enabled (xem studio_models()). */
    public const CACHE_KEY_ALL = 'studio_models_all';

    /**
     * [HIỆU NĂNG — vòng 20] studio_task_group_models() được gọi cho TỪNG nhóm công việc trong một
     * request (7 nhóm) và mỗi lượt lại query bảng này -> 21 query cho GET /api/defaults.
     * Xoá cache mỗi khi registry đổi để process chạy dài (queue:work) vẫn thấy thay đổi mà KHÔNG
     * cần restart — và cache có TTL ngắn (xem helper) nên tự lành kể cả khi có đường ghi khác.
     */
    protected static function booted(): void
    {
        $forget = function () {
            \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY);
            \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY_ALL);
        };
        static::saved($forget);
        static::deleted($forget);
    }
}
