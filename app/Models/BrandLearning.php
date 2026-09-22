<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trí nhớ dài hạn của Agent Studio: một quyết định (duyệt/loại) của chủ shop đối với một ảnh đã tạo.
 * Xem BrandLearningService — chỗ đọc/ghi tập trung, không để controller tự viết truy vấn rải rác.
 */
class BrandLearning extends Model
{
    // Bảng đặt tên SỐ ÍT 'brand_learning' (giống brand_dna) — khai tường minh để không rơi về 'brand_learnings'.
    protected $table = 'brand_learning';

    protected $fillable = ['user_id', 'generation_id', 'decision', 'prompt', 'lesson', 'context', 'source'];

    /** Nguồn gốc lượt rút kinh nghiệm (provider · model · thời điểm) — xem ReflectBrandMemoryJob. */
    protected $casts = ['context' => 'array'];

    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
