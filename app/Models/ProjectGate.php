<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MỘT CỔNG DUYỆT của một bộ sưu tập (Việc #7 · 2026-09-26) — xem App\Services\ProjectGateService.
 *
 * Máy trạng thái ở đây là WHITELIST, cùng mẫu đã dùng cho Sample::TRANSITIONS và
 * Generation::SHOT_TRANSITIONS. Khác một điểm quan trọng: không có trạng thái nào là NGÕ CỤT — mọi cổng
 * đều có đường lùi (đã duyệt vẫn rút lại được, đã từ chối vẫn mở lại được). Một cổng không rút lại được
 * là một cổng sẽ bị duyệt cho xong.
 */
class ProjectGate extends Model
{
    public const DECISION_PENDING = 'pending';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISIONS = [self::DECISION_PENDING, self::DECISION_APPROVED, self::DECISION_REJECTED];

    public const DECISION_LABELS = [
        self::DECISION_PENDING => 'Chưa duyệt',
        self::DECISION_APPROVED => 'Đã duyệt',
        self::DECISION_REJECTED => 'Không duyệt',
    ];

    /**
     * from => [to, ...]. Không có cặp nào bị chặn ngoài chính nó (no-op bị từ chối vì nó làm mất mốc thời
     * gian mà không nói thêm điều gì), nhưng map vẫn được khai TƯỜNG MINH: đây là chỗ để sau này siết một
     * cổng cụ thể (ví dụ cấm rút lại sau khi đã bàn giao) mà không phải đi tìm luật rải rác trong service.
     */
    public const TRANSITIONS = [
        self::DECISION_PENDING => [self::DECISION_APPROVED, self::DECISION_REJECTED],
        self::DECISION_REJECTED => [self::DECISION_PENDING, self::DECISION_APPROVED],
        self::DECISION_APPROVED => [self::DECISION_PENDING, self::DECISION_REJECTED],
    ];

    protected $fillable = [
        'project_id', 'gate', 'decision', 'note', 'decided_by', 'decided_at', 'fingerprint',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Các bước đi tiếp từ trạng thái hiện tại — dùng cho cả kiểm tra ở máy chủ lẫn nút ở giao diện. */
    public static function allowedFrom(string $decision): array
    {
        return self::TRANSITIONS[$decision] ?? [];
    }
}
