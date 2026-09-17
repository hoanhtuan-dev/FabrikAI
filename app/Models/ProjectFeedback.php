<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHẢN HỒI của người xem link chia sẻ (Đợt 4): Duyệt hoặc Yêu cầu sửa + ghi chú.
 *
 * Lưu thành dấu vết trong hệ thống để designer biết khách đã quyết gì, thay vì tin nhắn trôi trong Zalo.
 */
class ProjectFeedback extends Model
{
    public const DECISION_APPROVED = 'approved';
    public const DECISION_CHANGES = 'changes';

    public const DECISIONS = [self::DECISION_APPROVED, self::DECISION_CHANGES];

    public const LABELS = [
        self::DECISION_APPROVED => 'Đã duyệt',
        self::DECISION_CHANGES => 'Yêu cầu sửa',
    ];

    protected $fillable = ['project_id', 'share_id', 'author_name', 'decision', 'message'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function decisionLabel(): string
    {
        return self::LABELS[$this->decision] ?? $this->decision;
    }
}
