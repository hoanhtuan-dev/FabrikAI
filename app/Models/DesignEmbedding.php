<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MỘT tài liệu đã được nhúng (Việc #9 · 2026-09-26) — xem App\Services\DesignSearchService.
 *
 * "Tài liệu" là thứ người dùng đã tạo và có thể muốn tìm lại: prompt ảnh · brief bộ sưu tập · bài học đã
 * rút · phiếu kỹ thuật. Vec-tơ nằm ở cột nhị phân (float32) nên KHÔNG đọc thẳng ra JSON được — luôn đi qua
 * DesignSearchService::unpack().
 */
class DesignEmbedding extends Model
{
    protected $fillable = [
        'user_id', 'project_id', 'source_type', 'source_id',
        'text', 'text_hash', 'provider', 'model', 'dims', 'vector',
    ];

    protected $casts = ['dims' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
