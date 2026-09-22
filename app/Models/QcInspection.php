<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MỘT BIÊN BẢN KIỂM TRA CHẤT LƯỢNG (Việc #6 · 2026-09-26).
 *
 * Kết luận (result) do QcService TÍNH từ số lỗi so với kế hoạch lấy mẫu — xem QcService::verdict().
 * Model chỉ đọc/ghi thô, không tự quyết định đạt hay không.
 */
class QcInspection extends Model
{
    protected $table = 'qc_inspections';

    protected $fillable = [
        'project_id', 'sample_id', 'style_no', 'stage', 'lot_size', 'aql', 'plan',
        'critical', 'major', 'minor', 'checklist', 'result', 'notes', 'inspected_at',
    ];

    protected $casts = [
        'plan' => 'array',
        'checklist' => 'array',
        'lot_size' => 'integer',
        'critical' => 'integer',
        'major' => 'integer',
        'minor' => 'integer',
        'inspected_at' => 'datetime',
    ];

    public const RESULT_PENDING = 'pending';

    public const RESULT_PASS = 'pass';

    public const RESULT_FAIL = 'fail';

    public const RESULTS = ['pending', 'pass', 'fail'];

    public const RESULT_LABELS = [
        'pending' => 'Chưa kết luận',
        'pass' => 'Đạt',
        'fail' => 'Không đạt',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }
}
