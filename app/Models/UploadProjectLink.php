<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Liên kết ẢNH TẢI LÊN (file trên đĩa) ↔ DỰ ÁN (bộ sưu tập).
 *
 * Ảnh tải lên không có dòng trong CSDL nên không thể thêm cột project_id — xem migration
 * 2026_09_20_000002_create_upload_project_links_table để biết vì sao.
 */
class UploadProjectLink extends Model
{
    protected $fillable = ['user_id', 'rel', 'project_id'];

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** rel => project_id của MỘT tài khoản (dùng để gắn nhãn bộ sưu tập khi liệt kê ảnh). */
    public static function mapForUser(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return static::query()->where('user_id', $userId)->pluck('project_id', 'rel')->all();
    }
}
