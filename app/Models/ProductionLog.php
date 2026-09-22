<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MỘT NGÀY SẢN XUẤT của một bộ sưu tập (Việc #8 · 2026-09-26) — xem App\Services\ProductionTrackingService.
 *
 * Đây là SỐ THẬT do người dùng ghi. Không AI nào được viết vào đây, và không con số nào ở đây được suy ra
 * từ kế hoạch: mọi phép tính (tiến độ · nhịp · ngày dự kiến xong · cảnh báo) đều đọc từ bảng này.
 */
class ProductionLog extends Model
{
    protected $fillable = ['project_id', 'logged_on', 'units_done', 'units_defect', 'note', 'created_by'];

    protected $casts = [
        'logged_on' => 'date',
        'units_done' => 'integer',
        'units_defect' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
