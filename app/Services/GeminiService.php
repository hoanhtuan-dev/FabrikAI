<?php

namespace App\Services;

/**
 * "Giám đốc sáng tạo" — turns a Vietnamese idea + fashion presets into a canonical
 * Creative Direction: professional English prompts (image + video) that describe the
 * SAME garment, plus a controllable creativity vs. adherence level.
 *
 * Every path (Gemini, Qwen, or deterministic stub) is run through
 * CreativeDirectionService so the schema is unified and the image/video prompts stay
 * in sync regardless of provider or whether a real key is configured.
 */
class GeminiService
{
    protected CreativeDirectionService $direction;

    public function __construct(?CreativeDirectionService $direction = null)
    {
        $this->direction = $direction ?: app(CreativeDirectionService::class);
    }

    public function generateCreativeDirector(string $idea, array $injections = [], int $creativeLevel = 6): array
    {
        // MỘT cửa duy nhất: NHÓM CÔNG VIỆC 'prompt' (Cài đặt → Nhóm công việc / Model Registry /
        // Luồng ưu tiên provider / Custom Providers). Trước đây hàm này tự đọc setting rời
        // prompt_provider + studio_api_key('qwen'|'gemini') ⇒ đổi Registry / thêm custom provider
        // trong Cài đặt KHÔNG có tác dụng: cấu hình hiển thị một đằng, gọi model một nẻo.
        $answer = app(AiModelGateway::class)->text('prompt', [
            ['role' => 'system', 'content' => $this->systemPrompt($creativeLevel)],
            ['role' => 'user', 'content' => $this->userInstruction($idea, $injections, $creativeLevel)],
        ], ['response_format' => 'json_object', 'max_tokens' => 2048, 'timeout' => 90]);

        if ($answer === null) {
            // Chưa cấu hình key nào dùng được cho nhóm 'prompt' -> stub tất định (nói thật, không bịa).
            return $this->stub($idea, $injections, $creativeLevel);
        }

        $json = $this->decodeJson($answer['text']);
        if ($json === null) {
            logger()->warning('GeminiService: model trả về không phải JSON hợp lệ', [
                'provider' => $answer['provider'],
                'model' => $answer['model'],
                'body' => substr($answer['text'], 0, 200),
            ]);
        }

        return $this->normalize($json, $idea, $injections, $creativeLevel, $answer['provider']);
    }

    /** Câu lệnh người dùng dùng chung cho mọi provider (trước đây lặp lại ở callQwen + callGemini). */
    protected function userInstruction(string $idea, array $injections, int $creativeLevel): string
    {
        return 'Idea: '.$idea."\n"
            .'Creative level: '.$creativeLevel."/10\n"
            .'Tags: '.json_encode($injections, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Model đôi khi bọc JSON trong markdown code fence hoặc thêm lời dẫn quanh object.
     * Trích object JSON đầu tiên; trả null nếu không có JSON dùng được.
     */
    protected function decodeJson(string $text): ?array
    {
        $text = trim($text);
        $json = json_decode($text, true);
        if (is_array($json)) {
            return $json;
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $m) === 1) {
            $json = json_decode($m[0], true);
            if (is_array($json)) {
                return $json;
            }
        }

        return null;
    }

    protected function systemPrompt(int $creativeLevel): string
    {
        $directive = app(CreativeDirectionService::class)->creativityDirective($creativeLevel);

        return 'You are a fashion creative director. Given a Vietnamese idea and optional preset tags, '
            .'write BOTH an image-generation prompt and a matching video-catwalk prompt for the SAME garment. '
            .'Keep the garment identity (fabric, silhouette, style, colours) identical across the two prompts so the '
            .'video matches the rendered image. '.$directive.' '
            .'Return ONLY valid JSON with keys: image_prompt_en, video_prompt_en, negative_prompt (short comma-separated EN '
            .'phrases to avoid: low quality, distortions, extra limbs, cropped garment, inconsistent face, watermark), '
            .'concept_en (short English garment concept), category (object with fabric/silhouette/style/background/pose/camera), '
            .'keywords (array), mood, color_palette (array), style_notes.';
    }

    protected function stub(string $idea, array $injections, int $creativeLevel): array
    {
        return $this->normalize([
            'concept_en' => $this->englishConcept($idea, $injections),
            'keywords' => array_values(array_filter([
                $injections['fabric'] ?? null,
                $injections['silhouette'] ?? null,
                $injections['style'] ?? null,
                $injections['camera'] ?? null,
            ])),
            'mood' => ($injections['style'] ?? null) ?: 'luxury',
            'color_palette' => ['ivory', 'black', 'gold'],
            'style_notes' => 'High-fashion editorial, minimal, luxury fabric feel.',
        ], $idea, $injections, $creativeLevel, 'stub');
    }

    protected function normalize(?array $raw, string $idea, array $injections, int $creativeLevel, string $provider): array
    {
        $result = $this->direction->normalize($raw ?? [], $idea, $injections, $creativeLevel);
        $result['provider'] = $provider;

        return $result;
    }

    protected function englishConcept(string $idea, array $injections): string
    {
        // Stub: build a coherent ENGLISH concept from the preset tokens (English values),
        // so the generated prompt is usable even without an AI key. Falls back to the idea.
        $f = strtolower(trim((string) ($injections['fabric'] ?? '')));
        $s = strtolower(trim((string) ($injections['silhouette'] ?? '')));
        $st = strtolower(trim((string) ($injections['style'] ?? '')));
        if ($f || $s || $st) {
            $parts = array_filter([$f, $s, $st]);
            return 'a luxury '.implode(', ', $parts).' garment';
        }
        return $this->clean(trim($idea ?: 'a high-end fashion outfit', ' .,'));
    }

    protected function clean(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? '';
    }
}