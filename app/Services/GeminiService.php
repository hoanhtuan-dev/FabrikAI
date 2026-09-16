<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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
        $provider = (string) studio_config('prompt_provider', 'gemini');
        $qwenKey = studio_api_key('qwen') ?: studio_api_key('dashscope');

        if ($provider === 'qwen' && $qwenKey) {
            return $this->callQwen($idea, $injections, $creativeLevel);
        }

        $key = studio_api_key('gemini');

        if ($key) {
            return $this->callGemini($idea, $injections, $creativeLevel, $key);
        }

        return $this->stub($idea, $injections, $creativeLevel);
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

    protected function callQwen(string $idea, array $injections, int $creativeLevel): array
    {
        $system = $this->systemPrompt($creativeLevel);
        $prompt = 'Idea: '.$idea."
Creative level: {$creativeLevel}/10
Tags: ".json_encode($injections, JSON_UNESCAPED_UNICODE);

        $raw = null;
        $last = null;
        foreach (studio_qwen_text_models() as $model) {
            foreach (studio_qwen_credentials('prompt') as $key) {
                $base = dashscope_base_url($key).'/compatible-mode/v1';
                try {
                    $resp = Http::withToken($key)->timeout(90)
                        ->post($base.'/chat/completions', [
                            'model' => $model,
                            'messages' => [
                                ['role' => 'system', 'content' => $system],
                                ['role' => 'user', 'content' => $prompt],
                            ],
                            'response_format' => ['type' => 'json_object'],
                        ]);

                if ($resp->successful()) {
                    $text = (string) data_get($resp->json(), 'choices.0.message.content');
                    $json = json_decode(trim($text), true);
                    if (is_array($json)) {
                        $raw = $json;
                        break 2; // đã có kết quả -> thoát cả vòng model lẫn vòng key
                    }
                    $last = 'Bad JSON from Qwen.';
                } elseif (is_qwen_quota_error((string) $resp->body())) {
                    $last = 'HTTP '.$resp->status().': '.substr((string) $resp->body(), 0, 180);
                    continue; // Token Plan quota exhausted -> try Pay-As-You-Go next
                } else {
                    $last = 'HTTP '.$resp->status().': '.substr((string) $resp->body(), 0, 180);
                    break; // non-quota -> don't rotate
                }
            } catch (\Throwable $e) {
                $last = $e->getMessage();
                logger()->error('Qwen prompt failed: '.$e->getMessage());
                break;
                }
            }
        }
        if ($raw === null && $last) {
            logger()->warning('Qwen prompt failed all keys', ['last' => $last]);
        }

        return $this->normalize($raw, $idea, $injections, $creativeLevel, 'qwen');
    }

    protected function callGemini(string $idea, array $injections, int $creativeLevel, string $key): array
    {
        $system = $this->systemPrompt($creativeLevel);
        $prompt = "Idea: {$idea}
Creative level: {$creativeLevel}/10
Tags: ".json_encode($injections, JSON_UNESCAPED_UNICODE);

        $raw = null;

        try {
            $model = studio_config('prompt_model', 'gemini-2.5-flash');

            $resp = Http::withHeaders(['x-goog-api-key' => $key])->timeout(60)
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                    'contents' => [['parts' => [['text' => $system."

".$prompt]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json'],
                ]);

            if ($resp->successful()) {
                $text = (string) data_get($resp->json(), 'candidates.0.content.parts.0.text');
                $json = json_decode(trim($text), true);
                if (is_array($json)) {
                    $raw = $json;
                }
            }
        } catch (\Throwable $e) {
            logger()->error('GeminiService failed: '.$e->getMessage());
        }

        return $this->normalize($raw, $idea, $injections, $creativeLevel, 'gemini');
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