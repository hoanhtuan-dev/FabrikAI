<?php

namespace App\Services;

use App\Jobs\ReflectBrandMemoryJob;
use App\Models\BrandLearning;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * TRÍ NHỚ DÀI HẠN — học từ lựa chọn duyệt/loại ảnh của chủ shop.
 *
 * Luồng:
 *   · chủ shop duyệt ảnh (ProjectController::reviewShots) → record() ghi prompt + quyết định vào
 *     bảng brand_learning, rồi đẩy ReflectBrandMemoryJob;
 *   · ReflectBrandMemoryJob gọi vai agent_reflect để rút "bài học" KHÁI QUÁT (lesson) từ prompt thô;
 *   · khi tạo brief mới, DesignAgentService đọc preferences() để nhét cả prompt thô (GĐ1) lẫn bài học
 *     (GĐ2) vào prompt — ưu tiên phong cách đã duyệt, tránh phong cách đã loại.
 */
class BrandLearningService
{
    /**
     * Ghi một quyết định. Prompt rút gọn 500 ký tự; cùng generation + cùng quyết định chỉ ghi 1 lần.
     * Ghi xong thì đẩy job rút kinh nghiệm (không chờ — job tự bỏ qua khi chưa có model rút kinh nghiệm).
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

        $row = BrandLearning::create([
            'user_id' => $shot->user_id,
            'generation_id' => $shot->id,
            'decision' => $decision,
            'prompt' => $prompt,
            'source' => 'shot_review',
        ]);

        ReflectBrandMemoryJob::dispatch($row->id);
    }

    /**
     * Rút "bài học" cho MỘT quyết định đã ghi (ReasoningBank, rút gọn).
     *
     * Vì sao tách khỏi record(): bước này gọi model (~vài giây) nên phải nằm trong job nền, KHÔNG chặn
     * request duyệt ảnh. Trả null khi không có model hoặc model không trả được bài học — khi đó brief
     * vẫn dùng prompt thô như cũ, không bao giờ vỡ vì thiếu "bài học".
     *
     * @return string|null bài học đã ghi, hoặc null nếu không rút được.
     */
    public function reflectRecord(BrandLearning $row, ?AiModelGateway $gateway = null): ?string
    {
        if ($gateway === null || $row->lesson !== null) {
            return $row->lesson;
        }

        $approved = [];
        $rejected = [];
        foreach (BrandLearning::where('user_id', $row->user_id)
            ->where('id', '!=', $row->id)
            ->orderByDesc('id')->limit(12)->get(['decision', 'prompt']) as $near) {
            if ($near->decision === BrandLearning::DECISION_APPROVED) {
                $approved[] = $near->prompt;
            } else {
                $rejected[] = $near->prompt;
            }
        }

        $instruction = 'Bạn là bộ phận RÚT KINH NGHIỆM cho Agent Studio thời trang. '
            .'Dựa trên prompt ảnh chủ shop ĐÃ DUYỆT và ĐÃ LOẠI, hãy viết MỘT bài học ngắn về gu thật của shop. '
            .'Bài học phải KHÁI QUÁT (thích/tránh chất liệu, dáng, màu, phong cách) — KHÔNG chép nguyên văn prompt. '
            .'Không bịa con số, không nhắc tên model hay nhà cung cấp. '
            .'Trả DUY NHẤT một object JSON có đúng một khoá: {"lesson": "..."}.';

        $focus = $row->decision === BrandLearning::DECISION_APPROVED ? 'ĐÃ DUYỆT' : 'ĐÃ LOẠI';
        $payload = [
            'focus' => $focus.' — '.$row->prompt,
            'approved_recent' => array_slice($approved, 0, 6),
            'rejected_recent' => array_slice($rejected, 0, 6),
        ];

        $messages = [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => 'DỮ LIỆU: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];

        $answer = $gateway->text(DesignAgentService::REFLECT_GROUP, $messages, [
            'fallback_groups' => [DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP],
            'response_format' => 'json_object',
            'disable_thinking' => true,
            'timeout' => 30,
        ]);

        if ($answer === null) {
            return null;
        }

        $lesson = $this->decodeLesson((string) $answer['text']);
        if ($lesson === null) {
            return null;
        }

        $row->lesson = Str::limit($lesson, 800, '');
        $row->context = [
            'reflected_at' => now()->toISOString(),
            'provider' => $answer['provider'] ?? null,
            'model' => $answer['model'] ?? null,
        ];
        $row->save();

        return $row->lesson;
    }

    /** Đọc "lesson" từ text trả về — chấp nhận JSON trần hoặc bọc lời dẫn quanh vùng JSON. */
    private function decodeLesson(string $text): ?string
    {
        $text = trim($text);
        $json = json_decode($text, true);

        if (! is_array($json)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $json = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($json)) {
            return null;
        }

        $lesson = trim((string) ($json['lesson'] ?? ''));

        return $lesson !== '' ? $lesson : null;
    }

    /**
     * "Gu" của shop: prompt đã DUYỆT/đã LOẠI gần nhất (GĐ1) + bài học đã rút (GĐ2).
     *
     * @return array{approved: list<string>, rejected: list<string>, lessons: array{approved: list<string>, rejected: list<string>}}
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
            'lessons' => [
                'approved' => BrandLearning::where('user_id', $user->id)
                    ->where('decision', BrandLearning::DECISION_APPROVED)
                    ->whereNotNull('lesson')->where('lesson', '!=', '')
                    ->orderByDesc('id')->limit($limitApproved)
                    ->pluck('lesson')
                    ->all(),
                'rejected' => BrandLearning::where('user_id', $user->id)
                    ->where('decision', BrandLearning::DECISION_REJECTED)
                    ->whereNotNull('lesson')->where('lesson', '!=', '')
                    ->orderByDesc('id')->limit($limitRejected)
                    ->pluck('lesson')
                    ->all(),
            ],
        ];
    }
}
