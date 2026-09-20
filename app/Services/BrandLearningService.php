<?php

namespace App\Services;

use App\Models\BrandLearning;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * TRÍ NHỚ DÀI HẠN (GĐ1): học từ lựa chọn duyệt/loại ảnh của chủ shop.
 *
 * Luồng: chủ shop duyệt ảnh (ProjectController::reviewShots) → record() ghi prompt + quyết định vào
 * bảng brand_learning → khi tạo brief mới, DesignAgentService đọc preferences() để nhét "gu" thật vào
 * prompt (ưu tiên phong cách đã duyệt, tránh phong cách đã loại).
 */
class BrandLearningService
{
    /**
     * Ghi một quyết định. Prompt rút gọn 500 ký tự; cùng generation + cùng quyết định chỉ ghi 1 lần.
     */
    public function record(Generation $shot, string $decision): void
    {
        if (! in_array($decision, [BrandLearning::DECISION_APPROVED, BrandLearning::DECISION_REJECTED], true)) {
            return;
        }

        $prompt = Str::limit(trim((string) $shot->prompt), 500, '');
        if ($prompt === '') {
            return;
        }

        $exists = BrandLearning::where('generation_id', $shot->id)
            ->where('decision', $decision)
            ->exists();
        if ($exists) {
            return;
        }

        BrandLearning::create([
            'user_id' => $shot->user_id,
            'generation_id' => $shot->id,
            'decision' => $decision,
            'prompt' => $prompt,
            'source' => 'shot_review',
        ]);
    }

    /**
     * "Gu" của shop: prompt đã DUYỆT và prompt đã LOẠI gần nhất.
     *
     * @return array{approved: list<string>, rejected: list<string>}
     */
    public function preferences(User $user, int $limitApproved = 10, int $limitRejected = 5): array
    {
        return [
            'approved' => BrandLearning::where('user_id', $user->id)
                ->where('decision', BrandLearning::DECISION_APPROVED)
                ->orderByDesc('id')
                ->limit($limitApproved)
                ->pluck('prompt')
                ->all(),
            'rejected' => BrandLearning::where('user_id', $user->id)
                ->where('decision', BrandLearning::DECISION_REJECTED)
                ->orderByDesc('id')
                ->limit($limitRejected)
                ->pluck('prompt')
                ->all(),
        ];
    }
}
