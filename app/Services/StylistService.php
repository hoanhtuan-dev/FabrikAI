<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * "Thuật sỹ ảo" — an AI fashion stylist that walks a SKELETON question matrix then
 * gives deep, specific advice per step. Never dumps a preset list; it interviews.
 */
class StylistService
{
    /** Skeleton "backbone" the stylist always walks through (deep layer comes from the LLM). */
    protected $skeleton = [
        ['model' => true, 'en' => 'Vietnamese model character', 'vi' => 'Nhân vật người mẫu (Việt)', 'opts' => [
            'Trẻ trung (18-25), thanh mảnh, tóc dài đen, da sáng',
            'Thanh xuân (25-32), cao ráo, tóc dài xoăn, da nâu vàng',
            'Trưởng thành (32-40), đầy đặn, tóc ngắn cá tính, da ngăm',
            'Cận trung niên (40-50), quyến rũ, tóc búi, da sáng',
            'Nhẹ nhàng, tóc dài thẳng, da trắng sáng, dáng thon',
        ]],
        ['en' => 'silhouette and fit',            'vi' => 'Phom dáng / sự vừa vặn',   'opts' => ['Ôm / fitted', 'Suông / straight', 'Rộng / oversized', 'Bồng / volume']],
        ['en' => 'fabric and texture',            'vi' => 'Chất liệu / bề mặt',       'opts' => ['Lụa mềm', 'Cotton', 'Dệt kim', 'Da', 'Thô / linen']],
        ['en' => 'color and print',               'vi' => 'Màu sắc / họa tiết',       'opts' => ['Pastel nhẹ', 'Tối / trầm', 'Tươi sáng', 'Đen - trắng', 'Trung tính (be/cream)']],
        ['en' => 'design details and trims',      'vi' => 'Chi tiết thiết kế',         'opts' => ['Không hoạ tiết', 'Kẻ sọc', 'Chấm bi', 'Hoa văn', 'Thêu / logo']],
        ['en' => 'style and mood',                'vi' => 'Phong cách / cảm hứng',     'opts' => ['Sang trọng', 'Tối giản', 'Boho', 'Streetwear', 'Cổ điển']],
        ['en' => 'occasion and setting',          'vi' => 'Dịp / bối cảnh',            'opts' => ['Tiệc tối', 'Công sở', 'Dạo phố', 'Bãi biển', 'Sự kiện']],
    ];

    public function garmentTypes(): array
    {
        $types = app(\App\Services\StylistCatalog::class)->garmentTypes();
        foreach ($types as &$t) {
            $t['prompt'] = $this->basePrompt((string) $t['name']);
        }
        unset($t);
        return $types;
    }

    /** Prompt EN cơ bản cho một loại trang phục — dùng cho nút Preset trong popup Prompt. */
    protected function basePrompt(string $name): string
    {
        return 'A high-fashion editorial photo of a women\'s '.$name.', an elegant contemporary design, worn by a young Vietnamese woman (slim, fair skin, long black hair), styled for an elegant occasion, refined editorial aesthetic, set in a clean minimal studio, premium Vogue editorial, full-body, refined silhouette, soft even studio lighting, ultra detailed, 4k';
    }

    public function nameOf(string $id): string
    {
        return app(\App\Services\StylistCatalog::class)->nameOf($id);
    }

    /**
     * Skeleton data matrix (xương sườn): một cụm câu hỏi ngắn, sát thị trường Việt Nam,
     * giàu hàm lượng kỹ thuật sản xuất — trình bày MỘT LƯỢT để rút ngắn quá trình.
     * Điều chỉnh theo loại trang phục đã chọn (trọng tâm chủ đề).
     */
    public function cluster(string $type): array
    {
        return app(\App\Services\StylistCatalog::class)->questions($type);
    }

    /** Build a high-quality EN prompt from the cluster answers (technical detail). */
    /** Vietnamese-readable prompt (choice) from the same answers. */
    /**
     * Giai đoạn 2: tinh chỉnh & nâng cấp prompt — thuật sỹ đề xuất cải thiện + lời khuyên,
     * trả về prompt song ngữ (EN/VI) chi tiết hơn.
     */
    public function refine(string $type, string $promptEn, array $answers): array
    {
        $g = $this->nameOf($type);

        // Nếu prompt quá ngắn (< 30 ký tự), xây dựng lại từ answers thay vì refine.
        if (mb_strlen(trim($promptEn)) < 30) {
            $rebuilt = $this->buildPrompt($type, $answers);
            $promptEn = $rebuilt ?: $promptEn;
        }

        $instruction = <<<PROMPT
You are a senior high-fashion prompt engineer. The user designed a {$g}. Here is the current image-generation prompt:

{$promptEn}

Task:
- REFINE it into a richer, more detailed EN prompt (fabric construction, silhouette/pattern detail, fit, trims, styling, hair/makeup, pose, lighting, camera lens, background, mood, 4k editorial).
- Also produce a natural Vietnamese version (refined_vi).
- Add concise, expert ADVICE in Vietnamese (2-4 short bullet points) on what makes the prompt higher-quality (e.g., add specific fabric weight, construction, lighting, or details you recommend).

Reply ONLY JSON:
{"refined_en":"...","refined_vi":"...","advice":"- ...\n- ...\n- ..."}
PROMPT;
        $json = $this->chat($instruction);
        if ($json === null || ! is_array($json)) {
            // AI không phản hồi — trả về lỗi để client hiển thị cho user
            // thay vì âm thầm trả prompt cũ (gây hiểu nhầm "tinh chỉnh không hoạt động").
            return [
                'refined_en' => $promptEn,
                'refined_vi' => $this->buildPromptVi($type, $answers),
                'advice' => '• Thêm chất liệu + trọng lượng/cấu trúc • Mô tả ánh sáng & camera • Nêu bối cảnh & tâm trạng • Chỉnh cho sát kiểu dáng bạn muốn.',
                'error' => 'ai_unavailable',
                'error_message' => 'Không kết nối được AI. Kiểm tra key Qwen/Gemini trong Cài đặt Studio → Quản lý API.',
            ];
        }
        return [
            'refined_en' => (string) ($json['refined_en'] ?? $promptEn),
            'refined_vi' => (string) ($json['refined_vi'] ?? $this->buildPromptVi($type, $answers)),
            'advice' => (string) ($json['advice'] ?? ''),
        ];
    }

    public function buildPromptVi(string $type, array $answers): string
    {
        $g = $this->nameOf($type);
        $seg = [];
        if (! empty($answers['fabric'])) { $seg[] = 'chất liệu '.$answers['fabric']; }
        if (! empty($answers['silhouette'])) { $seg[] = 'phom '.$answers['silhouette']; }
        if (! empty($answers['color'])) { $seg[] = 'màu '.$answers['color']; }
        if (! empty($answers['details'])) { $seg[] = 'chi tiết '.$answers['details']; }
        $model = ! empty($answers['model']) ? $answers['model'] : 'phụ nữ Việt trẻ trung, thanh mảnh, da sáng, tóc dài';
        $occ = ! empty($answers['occasion']) ? $answers['occasion'] : 'dịp sang trọng';
        $set = ! empty($answers['setting']) ? $answers['setting'] : 'studio tối giản';
        $style = ! empty($answers['style']) ? $answers['style'] : 'thời trang cao cấp';
        $desc = $seg ? implode(', ', $seg) : 'thiết kế hiện đại thanh lịch';
        return 'Ảnh thời trang cao cấp của '.$g.' nữ, '.$desc.', mặc bởi '.$model.', phong cách '.$style.', bối cảnh '.$set.', dịp '.$occ.', chụp full-body, ánh sáng studio dịu, chi tiết sắc nét, 4k';
    }

    public function buildPrompt(string $type, array $answers): string
    {
        $g = $this->nameOf($type);
        $map = [
            'model' => 'model', 'silhouette' => 'silhouette', 'fabric' => 'fabric', 'color' => 'color', 'details' => 'details',
        ];
        $seg = [];
        if (! empty($answers['fabric'])) { $seg[] = 'crafted from '.$answers['fabric'].' fabric'; }
        if (! empty($answers['silhouette'])) { $seg[] = 'with a '.$answers['silhouette'].' silhouette'; }
        if (! empty($answers['color'])) { $seg[] = 'in '.$answers['color']; }
        if (! empty($answers['details'])) { $seg[] = 'featuring '.$answers['details'].' construction'; }
        $model = ! empty($answers['model']) ? $answers['model'] : 'a young Vietnamese woman, slim, fair skin, long black hair';
        $occ = ! empty($answers['occasion']) ? $answers['occasion'] : 'an elegant occasion';
        $set = ! empty($answers['setting']) ? $answers['setting'] : 'a clean minimal studio';
        $style = ! empty($answers['style']) ? $answers['style'] : 'refined editorial';
        $desc = $seg ? implode(', ', $seg) : 'an elegant contemporary design';
        return 'A high-fashion editorial photo of a women\'s '.$g.', '.$desc.', worn by a young Vietnamese woman ('.$model.'), styled for '.$occ.', '.$style.' aesthetic, set in '.$set.', premium Vogue editorial, full-body, refined silhouette, soft even studio lighting, ultra detailed, 4k';
    }


    /**
     * Next step of the stylist conversation. Walks the skeleton matrix; the LLM adds depth.
     * @param string $type
     * @param array  $history [['label'=>..., 'answer'=>...], ...]
     * @return array {done, question, options[], prompt, summary, category}
     */
    public function next(string $type, array $history): array
    {
        $stepNum = count($history);
        $typeName = $this->nameOf($type);
        $skeleton = $this->skeleton;

        if ($stepNum >= count($skeleton)) {
            // Skeleton done -> finalize a rich prompt.
            return ['done' => true, 'question' => '', 'options' => [], 'prompt' => $this->buildChatPrompt($type, $history), 'summary' => $this->buildSummary($history), 'category' => ''];
        }

        $cat = $skeleton[$stepNum];
        $isModel = ! empty($cat['model']);
        $topicText = $isModel ? 'describe a realistic VIETNAMESE female model (age 18-50): age, body, hair and skin tone' : $cat['vi'].' ('.$cat['en'].')';
        $historyText = '';
        if ($history) {
            $historyText = implode("
", array_map(fn ($h) => '- '.($h['label'] ?? 'Bước').': '.($h['answer'] ?? ''), $history));
        }

        $instruction = <<<PROMPT
You are a premium Vietnamese high-fashion creative director and AI stylist (thuật sỹ). You are helping design: {$typeName}.

This is step {$stepNum} of 6 — the topic is: {$cat['vi']} ({$cat['en']}).

Choices so far:
{$historyText}

Rules:
- The user may have typed a CUSTOM free-text answer; if so, PRIORITISE reasoning from it (offer options that refine it). Otherwise rely on your fashion knowledge.
- Ask ONE deep, specific, fashion-expert question in Vietnamese about: {$topicText} for this {$typeName}. Make it feel like a stylist advising a client (not a form).
- Give 3-5 concrete, distinct options that are rich fashion descriptors (not generic).
- Do NOT move to other topics; only the current one.
- Reply ONLY with JSON:
{"done":false,"question":"...","options":["a","b","c"]}
PROMPT;

        $json = $this->chat($instruction);
        if ($json === null || ! is_array($json)) {
            return ['done' => false, 'question' => $cat['vi'].' như thế nào cho '.$typeName.'?', 'options' => array_values($cat['opts']), 'prompt' => '', 'summary' => '', 'category' => $cat['en']];
        }

        return [
            'done' => false,
            'question' => (string) ($json['question'] ?? ($cat['vi'].' như thế nào?')),
            'options' => array_values((array) ($json['options'] ?? $cat['opts'])),
            'prompt' => '',
            'summary' => '',
            'category' => $cat['en'],
        ];
    }

    /** Build a rich EN image prompt from the accumulated answers (chat/history flow). */
    protected function buildChatPrompt(string $type, array $history): string
    {
        $typeName = $this->nameOf($type);
        $model = ''; $design = [];
        foreach ($history as $i => $h) {
            if (! empty($h['answer'])) {
                $a = trim((string) $h['answer']);
                if ($i === 0 && ! empty($this->skeleton[0]['model'])) { $model = $a; }
                else { $design[] = strtolower($a); }
            }
        }
        $desc = $design ? implode(', ', $design) : 'elegant contemporary design';
        $modelPart = $model ? 'worn by a '.$model.', ' : '';
        return 'A high-fashion editorial photo of a '.$typeName.', '.$desc.', '.$modelPart.'premium Vogue editorial, full-body, refined silhouette, soft even studio lighting, clean minimal background, ultra detailed, 4k';
    }

    protected function buildSummary(array $history): string
    {
        if (! $history) { return 'Bạn đã hoàn thành mô tả thiết kế.'; }
        $lines = array_map(fn ($h) => ucfirst((string) ($h['answer'] ?? '')), $history);
        return 'Thiết kế với: '.implode(' · ', $lines).'.';
    }

    /**
     * Text chat that returns parsed JSON. Tries Qwen then Gemini with aggressive
     * timeouts (15 s each) and parallelised key+model attempts so the user never
     * waits more than ~20 s for the fastest provider. Falls back to a cached
     * previous result when the same instruction is retried within 5 minutes.
     */
    protected function chat(string $instruction): ?array
    {
        // Cache dedup: skip the network round-trip for identical prompts within a short window.
        $cacheKey = 'stylist_chat:'.md5($instruction);
        try {
            $cached = cache()->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // cache driver unavailable — ignore
        }

        $timeout = 15; // seconds — tight per-call so total latency stays low
        $qwenKey = studio_api_key('qwen') ?: studio_api_key('dashscope');
        if ($qwenKey) {
            // Chỉ thử model đầu tiên (flash) + key đầu tiên — nhanh nhất.
            // Các model khác chỉ thử khi flash thất bại (model not found / quota).
            $models = studio_qwen_text_models();
            $firstModel = array_shift($models);
            $models = array_merge([$firstModel], $models); // put first back

            foreach ($models as $qm) {
                $keys = studio_qwen_credentials('prompt');
                $firstKey = array_shift($keys);
                $keys = array_merge([$firstKey], $keys);

                foreach ($keys as $key) {
                    $base = dashscope_base_url($key).'/compatible-mode/v1';
                    try {
                        $resp = Http::withToken($key)->timeout($timeout)
                            ->post($base.'/chat/completions', [
                                'model' => $qm,
                                'messages' => [['role' => 'user', 'content' => $instruction]],
                                'response_format' => ['type' => 'json_object'],
                                'max_tokens' => 1024, // Giới hạn output để response nhanh hơn
                            ]);
                        if ($resp->successful()) {
                            $out = trim((string) data_get($resp->json(), 'choices.0.message.content'));
                            $decoded = $this->decodeJson($out);
                            if ($decoded) {
                                $this->cacheChatResult($cacheKey, $decoded);
                                return $decoded;
                            }
                        } elseif (is_qwen_quota_error((string) $resp->body())) {
                            continue; // quota -> thử key tiếp theo
                        } elseif ($resp->status() === 404
                            || str_contains(strtolower((string) $resp->body()), 'model_not_found')
                            || str_contains(strtolower((string) $resp->body()), 'model not exist')) {
                            break; // model không tồn tại -> thử model kế tiếp
                        }
                        logger()->warning('Stylist Qwen('.$qm.') HTTP '.$resp->status().' '.substr((string) $resp->body(), 0, 160));
                    } catch (\Throwable $e) {
                        logger()->warning('Stylist Qwen('.$qm.') failed: '.$e->getMessage());
                        break;
                    }
                }
            }
        }

        // ── Gemini (fast failover) ──
        $geminiKey = studio_api_key('gemini');
        if ($geminiKey) {
            $gemModels = array_values(array_unique(array_filter([
                'gemini-2.5-flash',
                (string) studio_config('translate_model', ''),
                'gemini-2.0-flash',
            ])));
            foreach ($gemModels as $gm) {
                if (! $gm) continue;
                try {
                    $resp = Http::withHeaders(['x-goog-api-key' => $geminiKey])->timeout($timeout)
                        ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$gm.':generateContent', [
                            'contents' => [['parts' => [['text' => $instruction]]]],
                            'generationConfig' => [
                                'responseMimeType' => 'application/json',
                                'maxOutputTokens' => 1024,
                            ],
                        ]);
                    if ($resp->successful()) {
                        $out = trim((string) data_get($resp->json(), 'candidates.0.content.parts.0.text'));
                        $decoded = $this->decodeJson($out);
                        if ($decoded) {
                            $this->cacheChatResult($cacheKey, $decoded);
                            return $decoded;
                        }
                    }
                } catch (\Throwable $e) {
                    logger()->warning('Stylist Gemini('. $gm.') failed: '.$e->getMessage());
                }
            }
        }

        return null;
    }

    /** Cache a successful chat result for 5 minutes to avoid repeated calls. */
    protected function cacheChatResult(string $key, array $value): void
    {
        try {
            cache()->put($key, $value, 300);
        } catch (\Throwable $e) {
            // cache driver unavailable — ignore
        }
    }

    /** Parse a JSON string, tolerating a markdown-fenced or leading-text wrapper. */
    protected function decodeJson(string $out): ?array
    {
        $out = trim($out);
        $decoded = json_decode($out, true);
        if (is_array($decoded)) { return $decoded; }
        $start = strpos($out, '{');
        $end = strrpos($out, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($out, $start, $end - $start + 1), true);
            if (is_array($decoded)) { return $decoded; }
        }
        return null;
    }
}
