<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHIẾU KỸ THUẬT của một bộ sưu tập (một hàng cho một bộ).
 *
 * Việc chuẩn hoá/giới hạn nằm ở TechPackService — model chỉ đọc/ghi thô để không có hai nơi cùng
 * quyết định hình dạng dữ liệu (bài học "bản sao lệch chuẩn" đã gặp nhiều lần trong repo này).
 */
class TechPack extends Model
{
    protected $table = 'tech_packs';

    protected $fillable = ['project_id', 'data'];

    protected $casts = ['data' => 'array'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
