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

    protected $fillable = ['user_id', 'generation_id', 'decision', 'prompt', 'lesson', 'context', 'weight', 'hits', 'refreshed_at', 'source'];

    /**
     * Nguồn gốc lượt rút kinh nghiệm (provider · model · thời điểm) — xem ReflectBrandMemoryJob.
     *
     * @@weight@@ · @@hits@@ · @@refreshed_at@@ là ba cột của CỦNG CỐ TRÍ NHỚ (GĐ3): độ mạnh, số lần được
     * nhắc lại, và lần củng cố gần nhất. Xem BrandLearningService::reinforce()/decay().
     */
    protected $casts = ['context' => 'array', 'refreshed_at' => 'datetime', 'weight' => 'integer', 'hits' => 'integer'];

    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';

    /** Trần/sàn độ mạnh của một ký ức — MỘT nguồn, dùng chung với BrandLearningService. */
    public const WEIGHT_MIN = 1;

    public const WEIGHT_MAX = 10;

    public const DEFAULT_WEIGHT = 5;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
