<?php

namespace App\Services;

use App\Models\Preset;
use Illuminate\Support\Facades\Http;

/**
 * Image → prompt / style suggestion. When a Gemini vision key is available it asks the
 * model to deeply analyse the reference image (style, fabric, silhouette, colours, pose,
 * background) and produce a rich English prompt + matching presets. Otherwise it falls
 * back to a lightweight GD colour analysis so the stub still works offline.
 */
class StyleSuggestService
{
    /**
     * @param callable|null $onProgress Nhận từng sự kiện tiến trình dạng array:
     *        ['type' => 'phase', 'key' => 'prepare|vision', 'label' => '…']
     *        ['type' => 'provider', 'provider' => 'deepseek', 'model' => 'deepseek-flash', 'transport' => 'openai', 'keys' => 1]
     *        Dùng cho endpoint stream (NDJSON) để card hiện tiến trình THẬT khi AI đang suy luận.
     */
    public function suggest(string $imagePath, int $creativeLevel = 6, ?array $opts = null, ?callable $onProgress = null): array
    {
        $emit = function (string $type, array $payload = []) use ($onProgress) {
            if ($onProgress) {
                $onProgress(array_merge(['type' => $type], $payload));
            }
        };
        $started = microtime(true);

        // Tính năng bị tắt -> trả kết quả rỗng kèm cờ `disabled` để controller báo lỗi thân thiện.
        if (! studio_suggest_enabled()) {
            return ['disabled' => true, 'styles' => [], 'background' => '', 'image_prompt_en' => ''];
        }

        $emit('phase', ['key' => 'prepare', 'label' => 'Đang chuẩn bị ảnh nguồn…']);

        // Độ bám ảnh gốc + mức chi tiết — kiểm soát riêng cho "Gợi ý từ ảnh".
        $adherence = $this->resolveAdherence($opts);
        $detailLevel = $this->resolveDetailLevel($opts);

        // Cờ bỏ qua phân tích (checkbox trong card Gợi ý từ ảnh).
        $skipHair = (bool) ($opts['skip_hair'] ?? false);
        $skipLogo = (bool) ($opts['skip_logo'] ?? false);
        $skipBackground = (bool) ($opts['skip_background'] ?? false);

        // Provider cho "Gợi ý từ ảnh":
        //   '' (mặc định) = AUTO — đi theo MODEL REGISTRY + LUỒNG ƯU TIÊN provider, lấy candidate
        //                   từ nhóm công việc 'vision' (qwen → custom → flux → deepseek → gemini).
        //                   Nhờ vậy DeepSeek / custom provider (CKEY…) dùng được cho suy luận ảnh,
        //                   và đổi thứ tự luồng là đổi thứ tự thử — không cần sửa code.
        //   'qwen'/'gemini' (hoặc slug khác) = ÉP provider đó trước, rồi provider còn lại sau
        //                   (hành vi cũ, giữ để tương thích).
        $provider = studio_suggest_provider();
        $lastError = null;   // lỗi cuối cùng của provider — đưa vào thông báo cuối để UI nói rõ AI nào hỏng

        if ($provider === '') {
            foreach ($this->registryVisionCandidates() as $cand) {
                $emit('provider', [
                    'provider' => $cand['provider'],
                    'model' => $cand['model'],
                    'transport' => $cand['transport'],
                    'keys' => count($cand['keys']),
                ]);
                // Nhãn tiến trình là câu NÓI VỚI NGƯỜI DÙNG: không nêu provider/model (xem
                // docs/DESIGN_SYSTEM.md §6). Tên provider/model vẫn nằm trong sự kiện 'provider'
                // và trong log — chỗ của lập trình viên, không phải chỗ của người dùng.
                $emit('phase', ['key' => 'vision', 'label' => 'AI đang đọc ảnh và suy luận…']);

                try {
                    return $this->withMeta($this->runCandidate($cand, $imagePath, $creativeLevel, $adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground), $cand['provider'], $cand['model'], $started);
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    logger()->error($cand['provider'].':'.$cand['model'].' vision suggest failed: '.$lastError);
                    $emit('phase', ['key' => 'fallback', 'label' => 'Đang thử cách phân tích khác…']);
                }
            }

            // Registry trống hoặc chưa provider nào có key dùng được -> rơi về đường cũ
            // (qwen rồi tới gemini) để không mất hành vi của bản trước.
            $provider = 'qwen';
        }

        $geminiKey = studio_api_key('gemini');
        $hasQwen = ! empty(studio_qwen_credentials('vision'));

        // Thử provider đã cấu hình trước, provider còn lại sau — đảm bảo không bao giờ
        // âm thầm rớt về fallback màu khi vẫn còn key hợp lệ của provider kia.
        $attempts = [];
        if ($provider === 'qwen') {
            if ($hasQwen) { $attempts[] = 'qwen'; }
            if ($geminiKey) { $attempts[] = 'gemini'; }
        } else {
            if ($geminiKey) { $attempts[] = 'gemini'; }
            if ($hasQwen) { $attempts[] = 'qwen'; }
        }

        foreach ($attempts as $attempt) {
            $model = $attempt === 'qwen' ? (string) (studio_suggest_qwen_models()[0] ?? 'qwen') : studio_suggest_gemini_model();
            $emit('provider', ['provider' => $attempt, 'model' => $model, 'transport' => $attempt, 'keys' => 1]);
            $emit('phase', ['key' => 'vision', 'label' => 'AI đang đọc ảnh và suy luận…']);

            try {
                $out = $attempt === 'qwen'
                    ? $this->suggestViaQwenVision($imagePath, $creativeLevel, $adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground)
                    : $this->suggestViaVision($imagePath, $creativeLevel, $geminiKey, $adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground);

                return $this->withMeta($out, $attempt, $model, $started);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                logger()->error($attempt.' vision suggest failed: '.$lastError);
            }
        }

        // Không có key + đã bật fallback màu -> phân tích màu GD để vẫn gợi ý offline.
        if (studio_suggest_fallback()) {
            $emit('phase', ['key' => 'color', 'label' => 'Đang phân tích màu trực tiếp trên ảnh…']);
            return $this->withMeta($this->suggestViaColor($imagePath, $creativeLevel, $adherence, $detailLevel), 'color', 'GD', $started);
        }

        if ($lastError) {
            // Lỗi thật đã được ghi log ở từng lần thử ở trên; câu này là câu NÓI VỚI NGƯỜI DÙNG.
            logger()->error('suggest: mọi provider vision đều lỗi', ['last_error' => $lastError]);
            throw new \RuntimeException('Không phân tích được ảnh này. Bạn thử lại sau ít phút, hoặc đổi sang ảnh rõ hơn.');
        }

        // Tính năng chưa được bật ở phía hệ thống — người dùng không cần biết "API key" là gì.
        throw new \RuntimeException('Tính năng "Gợi ý từ ảnh" chưa được bật cho tài khoản này. Vui lòng báo cho quản trị viên.');
    }


    /**
     * Candidate vision lấy từ MODEL REGISTRY nhóm 'vision', xếp theo LUỒNG ƯU TIÊN provider
     * (qwen → custom → flux → deepseek → gemini) — đúng thứ tự studio_task_group_models()
     * trả về (default của nhóm trước, rồi rank nhóm → ưu tiên provider → ưu tiên model).
     *
     * Candidate không có key dùng được bị LOẠI NGAY (không gọi rồi mới lỗi), nên provider
     * đang tắt key sẽ tự động bị bỏ qua và lời gọi rơi xuống provider kế tiếp trong luồng.
     *
     * @return list<array{provider:string, model:string, transport:string, base:string, keys:list<string>}>
     */
    /**
     * Gắn metadata về provider/model/thời gian vào kết quả — UI hiển thị được “AI nào đã suy luận và mất bao lâu”.
     */
    protected function withMeta(array $out, string $provider, string $model, float $started): array
    {
        $out['_meta'] = [
            'provider' => $provider,
            'model' => $model,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'finished_at' => now()->toIso8601String(),
        ];

        return $out;
    }

    protected function registryVisionCandidates(): array
    {
        if (! function_exists('studio_task_group_models')) {
            return [];
        }

        $out = [];
        foreach (studio_task_group_models('vision') as $row) {
            $slug = (string) ($row['provider'] ?? '');
            $model = (string) ($row['model'] ?? '');
            if ($slug === '' || $model === '') {
                continue;
            }
            $cand = $this->resolveCandidate($slug, $model);
            if ($cand !== null) {
                $out[] = $cand;
            }
        }

        return $out;
    }

    /**
     * Một dòng registry -> transport cụ thể. Custom provider dùng chính protocol + base_url
     * đã khai trong Settings; built-in dùng transport viết tay, base_url (nếu có) lấy từ catalog.
     */
    protected function resolveCandidate(string $slug, string $model): ?array
    {
        $custom = function_exists('studio_custom_provider') ? studio_custom_provider($slug) : null;

        if ($custom) {
            $keys = $this->keysFor($slug, $model, $custom['api_key_ref'] ?? null);
            if (! $keys) {
                return null;
            }
            // Đường vision hiện hỗ trợ gateway OpenAI-compatible (CKEY, DeepSeek gateway…);
            // custom dashscope/gemini dùng transport riêng nên bỏ qua ở đây.
            if ((string) ($custom['protocol'] ?? 'openai') !== 'openai') {
                return null;
            }

            return [
                'provider' => $slug,
                'model' => $model,
                'transport' => 'openai',
                'base' => rtrim((string) $custom['base_url'], '/'),
                'keys' => $keys,
            ];
        }

        $catalog = function_exists('studio_provider_catalog') ? studio_provider_catalog() : [];
        $meta = $catalog[$slug] ?? null;
        $base = rtrim((string) ($meta['base_url'] ?? ''), '/');

        if (in_array($slug, ['qwen', 'qwen_edit', 'dashscope', 'wan'], true)) {
            $keys = studio_qwen_credentials('vision');

            return $keys ? ['provider' => $slug, 'model' => $model, 'transport' => 'qwen', 'base' => '', 'keys' => array_values($keys)] : null;
        }

        if (in_array($slug, ['gemini', 'veo'], true)) {
            $keys = $this->keysFor('gemini', $model);

            return $keys ? ['provider' => $slug, 'model' => $model, 'transport' => 'gemini', 'base' => '', 'keys' => $keys] : null;
        }

        // Built-in OpenAI-compatible khai base_url trong catalog (vd deepseek).
        if ($base !== '' && (string) ($meta['protocol'] ?? '') === 'openai') {
            $keys = $this->keysFor($slug, $model);

            return $keys ? ['provider' => $slug, 'model' => $model, 'transport' => 'openai', 'base' => $base, 'keys' => $keys] : null;
        }

        return null;
    }

    /**
     * Mọi key dùng được cho một provider (key đã đăng ký theo scope 'vision' + key mặc định),
     * đã giải mã và khử trùng lặp.
     *
     * @return list<string>
     */
    protected function keysFor(string $provider, string $model, ?string $extraRef = null): array
    {
        $keys = [];

        foreach (array_unique(array_filter([$provider, $extraRef])) as $ref) {
            if (function_exists('studio_api_keys_for')) {
                foreach (studio_api_keys_for($ref, $model, 'vision') as $k) {
                    $v = studio_api_key_value($k);
                    if ($v) {
                        $keys[] = $v;
                    }
                }
            }
            $v = function_exists('studio_api_key') ? studio_api_key($ref) : null;
            if ($v) {
                $keys[] = $v;
            }
        }

        return array_values(array_unique($keys));
    }

    /** Chạy một candidate qua transport tương ứng, thử lần lượt các key của provider đó. */
    protected function runCandidate(array $cand, string $imagePath, int $creativeLevel, int $adherence, int $detailLevel, bool $skipHair, bool $skipLogo, bool $skipBackground): array
    {
        $last = null;

        foreach ($cand['keys'] as $key) {
            try {
                return match ($cand['transport']) {
                    'qwen' => $this->suggestViaQwenModel($cand['model'], $key, $imagePath, $creativeLevel, $adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground),
                    'gemini' => $this->suggestViaVision($imagePath, $creativeLevel, $key, $adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground, $cand['model']),
                    default => $this->suggestViaOpenAiVision($cand['base'], $cand['model'], $key, $imagePath, $creativeLevel, $adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground),
                };
            } catch (\Throwable $e) {
                $last = $e->getMessage();
            }
        }

        throw new \RuntimeException((string) ($last ?: 'không xác định'));
    }

    /**
     * Vision qua gateway OpenAI-compatible: POST {base}/chat/completions với ảnh dạng
     * image_url (data URI). Dùng cho DeepSeek (base https://api.deepseek.com) và MỌI custom
     * provider protocol 'openai' (CKEY, OpenRouter, Together…).
     *
     * Model reasoning (deepseek-flash/v4-pro) có thể trả nội dung ở 'reasoning_content' khi
     * 'content' rỗng — đọc cả hai trước khi kết luận là không phân tích được JSON.
     */
    protected function suggestViaOpenAiVision(string $base, string $model, string $key, string $imagePath, int $creativeLevel, int $adherence, int $detailLevel, bool $skipHair = false, bool $skipLogo = false, bool $skipBackground = false): array
    {
        [$b64, $mime] = $this->downscaleBase64($imagePath, (int) studio_suggest_config('downscale_max', 1024));
        if ($b64 === '') {
            $mime = function_exists('mime_content_type') ? (mime_content_type($imagePath) ?: 'image/jpeg') : 'image/jpeg';
            $b64 = base64_encode((string) file_get_contents($imagePath));
        }

        $prompt = $this->analysisPrompt($adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground);

        $resp = Http::withToken($key)->timeout(120)
            ->post(rtrim($base, '/').'/chat/completions', [
                'model' => $model,
                // Model reasoning tính cả token suy luận vào completion nên để mức rộng.
                'max_tokens' => (int) studio_suggest_config('max_tokens', 4000),
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.$b64]],
                ]]],
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Vision '.$model.' (HTTP '.$resp->status().'): '.substr((string) $resp->body(), 0, 180));
        }

        $text = trim((string) data_get($resp->json(), 'choices.0.message.content'));
        if ($text === '') {
            $text = trim((string) data_get($resp->json(), 'choices.0.message.reasoning_content'));
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $json = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

        if (! is_array($json)) {
            throw new \RuntimeException('Không phân tích được JSON từ vision '.$model.'.');
        }

        return $this->finalize($json, $creativeLevel, $adherence, $detailLevel);
    }

    /** Một model Qwen vision × một key (tách ra để registry-driven gọi được model cụ thể). */
    protected function suggestViaQwenModel(string $model, string $key, string $imagePath, int $creativeLevel, int $adherence, int $detailLevel, bool $skipHair = false, bool $skipLogo = false, bool $skipBackground = false): array
    {
        [$b64, $mime] = $this->downscaleBase64($imagePath, (int) studio_suggest_config('downscale_max', 1024));
        $prompt = $this->analysisPrompt($adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground);
        $base = dashscope_base_url($key).'/compatible-mode/v1';

        $resp = Http::withToken($key)->timeout(90)
            ->post($base.'/chat/completions', [
                'model' => $model,
                'max_tokens' => (int) studio_suggest_config('max_tokens', 4000),
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.$b64]],
                ]]],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Qwen vision '.$model.' (HTTP '.$resp->status().'): '.substr((string) $resp->body(), 0, 180));
        }

        $text = trim((string) data_get($resp->json(), 'choices.0.message.content'));
        $json = json_decode($text, true);
        if (! is_array($json)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            $json = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        }
        if (! is_array($json)) {
            throw new \RuntimeException('Không phân tích được JSON từ Qwen vision ('.$model.').');
        }

        return $this->finalize($json, $creativeLevel, $adherence, $detailLevel);
    }

    /**
     * Độ bám ảnh gốc (1..10). Ưu tiên: opts override -> setting -> default theo creative
     * (creative càng thấp càng bám). Cao = tái tạo chính xác chi tiết trang phục gốc.
     */
    protected function resolveAdherence(?array $opts): int
    {
        $val = $opts['adherence'] ?? null;
        if ($val === null) {
            $val = studio_suggest_config('adherence', null);
        }
        if ($val !== null && $val !== '' && (int) $val > 0) {
            return max(1, min(10, (int) $val));
        }
        // Mặc định: creative thấp => bám cao; creative cao => bám vừa.
        $creative = (int) ($opts['creative_level'] ?? studio_suggest_config('creative_level', 6));

        return max(5, 11 - $creative);
    }

    /**
     * Mức độ chi tiết phân tích (1..10). Ưu tiên: opts -> setting -> default 8.
     * Cao = yêu cầu vision liệt kê chi tiết (màu, đường may, họa tiết, độ dài, cổ, tay...).
     */
    protected function resolveDetailLevel(?array $opts): int
    {
        $val = $opts['detail_level'] ?? null;
        if ($val === null) {
            $val = studio_suggest_config('detail_level', null);
        }
        if ($val !== null && $val !== '' && (int) $val > 0) {
            return max(1, min(10, (int) $val));
        }

        return 8;
    }

    /**
     * Prompt phân tích chi tiết — yêu cầu vision mô tả chính xác trang phục gốc (màu, họa tiết,
     * đường may, độ dài, kiểu cổ/tay, chất liệu, phụ kiện) và sinh prompt EN/VI bám sát.
     */
    protected function analysisPrompt(int $adherence, int $detailLevel, bool $skipHair = false, bool $skipLogo = false, bool $skipBackground = false): string
    {
        $direction = app(CreativeDirectionService::class);
        $adherenceClause = $direction->adherenceDirective($adherence);
        $detailClause = $direction->detailDirective($detailLevel);

        $exclude = [];
        if ($skipHair) {
            $exclude[] = 'hairstyle & hair colour, makeup palette';
        }
        if ($skipLogo) {
            $exclude[] = 'logos, text, watermarks, branding';
        }
        if ($skipBackground) {
            $exclude[] = 'the background/setting/lighting';
        }
        $excludeClause = $exclude ? ' Do NOT describe or include in the prompt: '.implode('; ', $exclude).'.' : '';

        return "You are a senior fashion stylist & prompt engineer. Analyze this fashion model photo and the EXACT garment worn. "
            .$detailClause.' '
            .$adherenceClause.' '
            ."Study the reference image precisely and capture: garment type (dress / top+bottom / suit / outerwear ...), "
            ."exact dominant and accent colours, fabric/material & texture, neckline, collar, sleeve length & shape, "
            ."hem length, fit/silhouette, drape, patterns/prints/embellishments, buttons/zips/trims, accessories, footwear"
            .($skipHair ? '' : ', hairstyle & hair colour, makeup palette')
            .', pose, body angle'
            .($skipBackground ? '' : ', and the background/setting/lighting')
            .'. '
            ."Do NOT invent new colours, fabrics, silhouettes or accessories that are not in the image. "
            ."If a detail is not visible, omit it rather than guessing."
            .$excludeClause
            ." Return ONLY valid JSON (no markdown) with these keys: "
            .'"styles" (1-3 style labels), "background" (one label), "pose" (one label), '
            .'"fabric" (one label), "silhouette" (one label), "camera" (one label), '
            .'"garment_type" (one short label, e.g. "midi dress", "blazer + trousers"), '
            .'"color_palette" (array of 2-5 colour names matching the image), '
            .'"embellishment" (one short label of pattern/decoration level, e.g. "plain solid", "floral print", "sequin embellishment"), '
            .'"detail_notes" (a 1-3 sentence English note of the key visible garment details), '
            .'"image_prompt_en" (a DETAILED, ready-to-use English image-generation prompt of 60-160 words that faithfully REPRODUCES '
            ."the outfit, fabric, colours, fit, neckline, sleeves, hem, pattern, accessories and setting from the reference image), "
            .'"prompt_vi" (a Vietnamese translation of image_prompt_en — keep technical fashion terms like fabric, silhouette, pose, camera, '
            ."neckline, hem in English; translate only the descriptive parts naturally into Vietnamese), "
            .'"video_prompt_en" (a matching English video-catwalk prompt for the SAME garment), '
            .'"keywords" (array of 5-12 tags).';
    }

    protected function suggestViaQwenVision(string $imagePath, int $creativeLevel, int $adherence, int $detailLevel, bool $skipHair = false, bool $skipLogo = false, bool $skipBackground = false): array
    {
        // Thu nhieu model Qwen VISION x nhieu key: qwen3.8-flash/max (da phuong thuc) truoc, cac
        // tai khoan cu chi expose qwen-vl-* nen giu o cuoi danh sach. Mot model x mot key nam o
        // suggestViaQwenModel() de duong registry-driven dung lai cung implementation.
        $last = null;
        foreach (studio_suggest_qwen_models() as $model) {
            foreach (studio_qwen_credentials('vision') as $key) {
                try {
                    return $this->suggestViaQwenModel($model, $key, $imagePath, $creativeLevel, $adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground);
                } catch (\Throwable $e) {
                    $last = $e->getMessage();
                    // Quota (Token Plan het han muc) -> thu key ke tiep; model khong ton tai -> thu model ke tiep.
                    if (is_qwen_quota_error($last) || str_contains(strtolower($last), 'model_not_found') || str_contains(strtolower($last), 'model not exist') || str_contains($last, 'HTTP 404')) {
                        continue;
                    }
                    break 2;
                }
            }
        }

        throw new \RuntimeException('Qwen vision: '.($last ?: 'không xác định'));
    }

    protected function suggestViaVision(string $imagePath, int $creativeLevel, string $key, int $adherence, int $detailLevel, bool $skipHair = false, bool $skipLogo = false, bool $skipBackground = false, ?string $model = null): array
    {
        // Registry-driven truyen model cu the; duong cu khong truyen thi lay tu setting.
        $model = $model ?: studio_suggest_gemini_model();
        [$b64, $mime] = $this->downscaleBase64($imagePath, (int) studio_suggest_config('downscale_max', 1024));
        if ($b64 === '') {
            $mime = function_exists('mime_content_type') ? (mime_content_type($imagePath) ?: 'image/jpeg') : 'image/jpeg';
            $b64 = base64_encode((string) file_get_contents($imagePath));
        }

        $prompt = $this->analysisPrompt($adherence, $detailLevel, $skipHair, $skipLogo, $skipBackground);

        $resp = Http::withHeaders(['x-goog-api-key' => $key])->timeout(90)
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                'contents' => [['parts' => [
                    ['text' => $prompt],
                    ['inlineData' => ['mimeType' => $mime, 'data' => $b64]],
                ]]],
                'generationConfig' => ['responseMimeType' => 'application/json'],
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Vision ('.$resp->status().'): '.$resp->body());
        }

        $text = trim((string) data_get($resp->json(), 'candidates.0.content.parts.0.text'));
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $json = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($json)) {
            throw new \RuntimeException('Không phân tích được JSON từ vision.');
        }

        return $this->finalize($json, $creativeLevel, $adherence, $detailLevel);
    }

    /**
     * Canonicalise a vision suggestion into the unified Creative Direction schema,
     * guaranteeing image & video prompts describe the SAME garment.
     */
    protected function str($v): string
    {
        if (is_array($v)) {
            return trim(implode(', ', array_filter(array_map('strval', $v))));
        }
        return trim((string) $v);
    }

    protected function finalize(array $json, int $creativeLevel, int $adherence = 8, int $detailLevel = 8): array
    {
        $styles = array_values(array_filter((array) ($json['styles'] ?? [])));
        // Giới hạn số phong cách gợi ý (cấu hình riêng max_styles).
        $styles = array_values(array_slice($styles, 0, max(1, (int) studio_suggest_config('max_styles', 3))));
        $background = $this->str($json['background'] ?? '');
        $pose = $this->str($json['pose'] ?? '');
        $fabric = $this->str($json['fabric'] ?? '');
        $silhouette = $this->str($json['silhouette'] ?? '');
        $camera = $this->str($json['camera'] ?? '');
        $garmentType = $this->str($json['garment_type'] ?? '');
        $embellishment = $this->str($json['embellishment'] ?? '');
        $detailNotes = $this->str($json['detail_notes'] ?? '');
        $palette = array_values(array_filter((array) ($json['color_palette'] ?? [])));

        $injections = array_filter([
            'fabric' => $fabric,
            'silhouette' => $silhouette,
            'style' => implode(', ', $styles),
            'background' => $background,
            'pose' => $pose,
            'camera' => $camera,
        ]);

        // Ghi chú chi tiết từ ảnh gốc được nhồi vào style_notes để ensureSignature/normalize
        // luôn giữ thông tin bám ảnh (màu/đường may/hoạ tiết) — không bị mất qua consolidation.
        $styleNotes = trim(($json['style_notes'] ?? '').($detailNotes !== '' ? ' '.$detailNotes : ''));
        if ($styleNotes === '') {
            $styleNotes = 'Faithful reproduction of the reference garment — same colours, fabric, silhouette, neckline, hem, pattern and accessories.';
        }

        $raw = [
            'image_prompt_en' => (string) ($json['image_prompt_en'] ?? ''),
            'prompt_vi' => (string) ($json['prompt_vi'] ?? ''),
            'video_prompt_en' => (string) ($json['video_prompt_en'] ?? ''),
            'keywords' => $json['keywords'] ?? [],
            'category' => $injections,
            'mood' => $json['mood'] ?? ($styles[0] ?? 'luxury'),
            'color_palette' => ! empty($palette) ? $palette : ['ivory', 'black', 'gold'],
            'style_notes' => $styleNotes,
        ];

        $dir = app(CreativeDirectionService::class);
        $c = $dir->normalize($raw, '', $injections, $creativeLevel);

        return [
            'preset_ids' => $this->matchPresets($json),
            'styles' => $styles,
            'background' => $background,
            'pose' => $pose,
            'fabric' => $fabric,
            'silhouette' => $silhouette,
            'camera' => $camera,
            'garment_type' => $garmentType,
            'embellishment' => $embellishment,
            'detail_notes' => $detailNotes,
            'color_palette' => $c['color_palette'],
            'image_prompt_en' => $c['image_prompt_en'],
            'prompt_vi' => (string) ($json['prompt_vi'] ?? ''),
            'video_prompt_en' => studio_suggest_include_video() ? $c['video_prompt_en'] : '',
            'creative_level' => $c['creative_level'],
            'adherence' => $adherence,
            'detail_level' => $detailLevel,
            'negative_prompt' => $c['negative_prompt'],
            'keywords' => $c['keywords'],
            'category' => $c['category'],
        ];
    }

    protected function matchPresets(array $json): array
    {
        $wants = collect([
            'style' => $json['styles'] ?? [],
            'background' => [$json['background'] ?? null],
            'pose' => [$json['pose'] ?? null],
            'fabric' => [$json['fabric'] ?? null],
            'silhouette' => [$json['silhouette'] ?? null],
            'camera' => [$json['camera'] ?? null],
        ])->filter(fn ($v) => ! empty($v));

        $ids = [];

        // M10: nạp catalog MỘT lần rồi tra trong bộ nhớ. Trước đây mỗi label trong mỗi category
        // chạy một query riêng (vòng lặp LỒNG nhau) trên cùng một bảng nhỏ.
        // orderBy('sort_order') để giữ đúng thứ tự mà scopeCategory() vẫn đảm bảo.
        $catalog = Preset::orderBy('sort_order')->get()->groupBy('category');

        foreach ($wants as $category => $labels) {
            $pool = $catalog->get($category) ?? collect();
            foreach ($labels as $label) {
                if (! is_string($label) || $label === '') {
                    continue;
                }
                $found = $pool->first(fn ($p) => str_contains(mb_strtolower($p->ui_label ?? ''), mb_strtolower($label))
                    || str_contains(mb_strtolower($label), mb_strtolower($p->ui_label ?? '')));
                if ($found) {
                    $ids[] = $found->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    protected function downscaleBase64(string $path, int $max = 1024): array
    {
        $img = studio_image_decode($path);
        if (! $img) {
            return ['', 'image/jpeg'];
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $max = max(64, min(4096, $max));
        if ($w > $max || $h > $max) {
            $scale = min($max / $w, $max / $h);
            $nw = max(1, (int) ($w * $scale));
            $nh = max(1, (int) ($h * $scale));
            $tmp = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($tmp, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $tmp;
        }
        ob_start();
        imagejpeg($img, null, 85);
        $data = ob_get_clean();
        imagedestroy($img);

        return [base64_encode((string) $data), 'image/jpeg'];
    }

    /**
     * Đọc ảnh khuôn mặt bằng vision model → trả mô tả chi tiết (tiếng Anh) để hỗ trợ face swap.
     * Dùng cho "👤 Thay khuôn mặt": mô tả identity + tóc + tai + tỷ lệ giúp model edit hiểu chính xác hơn.
     * Trả null khi không có key vision hoặc model lỗi (face swap vẫn chạy, chỉ thiếu mô tả).
     */
    public function describeFace(string $imagePath): ?string
    {
        try {
            [$b64, $mime] = $this->downscaleBase64($imagePath, 1024);
            if ($b64 === '') { return null; }
            $prompt = 'Describe this face in detail for a face-swap task. Return a short English description (1-2 sentences, plain text only, no JSON, no labels) covering: gender, age, face shape, hairstyle (length, color, style), ears, eyebrows, eyes, nose, lips, skin tone, and overall head proportions.';
            foreach (studio_suggest_qwen_models() as $model) {
                foreach (studio_qwen_credentials('vision') as $key) {
                    $base = dashscope_base_url($key).'/compatible-mode/v1';
                    try {
                        $resp = Http::withToken($key)->timeout(60)
                            ->post($base.'/chat/completions', [
                                'model' => $model,
                                'messages' => [['role' => 'user', 'content' => [
                                    ['type' => 'text', 'text' => $prompt],
                                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.$b64]],
                                ]]],
                            ]);
                        if ($resp->successful()) {
                            $text = trim((string) data_get($resp->json(), 'choices.0.message.content'));
                            if ($text !== '' && mb_strlen($text) < 800) { return $text; }
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('describeFace failed: '.$e->getMessage());
        }
        return null;
    }

    /**
     * Đọc ảnh pose bằng vision model → trả mô tả TƯ THẾ chi tiết (tiếng Anh) để hỗ trợ tryon.
     * Dùng cho chip "Thử đồ": mô tả hướng người, tay/chân, trọng tâm từ ẢNH pose (thay vì text DB).
     * Trả null khi không có key vision hoặc model lỗi (vẫn chạy, chỉ thiếu mô tả pose).
     */
    public function describePose(string $imagePath): ?string
    {
        try {
            [$b64, $mime] = $this->downscaleBase64($imagePath, 1024);
            if ($b64 === '') { return null; }
            $prompt = 'Describe the full-body pose in this image for a virtual try-on task. Return a short English description (1-2 sentences, plain text only, no JSON, no labels) covering: body orientation (front/back/side/three-quarter), stance, arm position, hand placement, leg position, weight distribution, head/gaze direction, and any prop (chair/wall/stool). Focus ONLY on the body posture — ignore clothing, face and background.';
            foreach (studio_suggest_qwen_models() as $model) {
                foreach (studio_qwen_credentials('vision') as $key) {
                    $base = dashscope_base_url($key).'/compatible-mode/v1';
                    try {
                        $resp = Http::withToken($key)->timeout(60)
                            ->post($base.'/chat/completions', [
                                'model' => $model,
                                'messages' => [['role' => 'user', 'content' => [
                                    ['type' => 'text', 'text' => $prompt],
                                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$mime.';base64,'.$b64]],
                                ]]],
                            ]);
                        if ($resp->successful()) {
                            $text = trim((string) data_get($resp->json(), 'choices.0.message.content'));
                            if ($text !== '' && mb_strlen($text) < 800) { return $text; }
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('describePose failed: '.$e->getMessage());
        }
        return null;
    }

    protected function suggestViaColor(string $imagePath, int $creativeLevel = 6, int $adherence = 8, int $detailLevel = 8): array
    {
        // M10: nạp catalog MỘT lần (trước đây 5 query riêng cho 5 category).
        // orderBy('sort_order') để tương đương scopeCategory().
        $catalog = Preset::orderBy('sort_order')->get()->groupBy('category');
        $styles = $catalog->get('style') ?? collect();
        $backgrounds = $catalog->get('background') ?? collect();
        $poses = $catalog->get('pose') ?? collect();
        $fabrics = $catalog->get('fabric') ?? collect();
        $silhouettes = $catalog->get('silhouette') ?? collect();

        [$warm, $brightness] = $this->analyzeImage($imagePath);

        $style = $styles->first(fn ($p) => $p->ui_label === $this->pickStyle($warm, $brightness, $styles));
        $bg = $backgrounds->first(fn ($p) => $p->ui_label === $this->pickBackground($brightness, $backgrounds));
        $pose = $poses->isEmpty() ? null : $poses->random();
        $fabric = $fabrics->isEmpty() ? null : $fabrics->random();
        $silhouette = $silhouettes->isEmpty() ? null : $silhouettes->random();

        // Build a richer prompt by injecting ALL preset prompt_injections (not just style/background/pose).
        $injections = collect([$style, $bg, $pose, $fabric, $silhouette])
            ->filter()
            ->map(fn ($p) => $p->prompt_injection)
            ->filter()
            ->implode(', ');

        $prompt = 'High-fashion editorial photo'
            .($injections ? ', '.$injections : '')
            .', soft diffused studio lighting, clean minimal background, ultra detailed, 4k, sharp focus';

        return $this->finalize([
            'image_prompt_en' => $prompt,
            'styles' => $style ? [$style->ui_label] : [],
            'background' => $bg?->ui_label,
            'pose' => $pose?->ui_label,
            'fabric' => $fabric?->ui_label,
            'silhouette' => $silhouette?->ui_label,
        ], $creativeLevel, $adherence, $detailLevel);
    }

    /**
     * @return array{0: float, 1: float} [warmth, brightness(0..1)]
     */
    protected function analyzeImage(string $path): array
    {
        try {
            $img = studio_image_decode($path);
            if (! $img) {
                return [0, 0.5];
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $tmp = imagecreatetruecolor(1, 1);
            imagecopyresampled($tmp, $img, 0, 0, 0, 0, 1, 1, $w, $h);
            $rgb = imagecolorsforindex($tmp, imagecolorat($tmp, 0, 0));
            imagedestroy($tmp);
            imagedestroy($img);

            $brightness = ($rgb['red'] + $rgb['green'] + $rgb['blue']) / 3 / 255;
            $warmth = ($rgb['red'] + $rgb['green']) / (2 * max(1, (int) $rgb['blue']));

            return [$warmth, $brightness];
        } catch (\Throwable $e) {
            return [0, 0.5];
        }
    }

    protected function pickStyle(float $warm, float $brightness, $styles): ?string
    {
        $byLabel = fn ($label) => $styles->contains('ui_label', $label) ? $label : ($styles->first()?->ui_label ?? null);

        if ($warm > 1.2 && $brightness > 0.4) {
            return $byLabel('Boho Chic (Modern)');
        }
        if ($brightness > 0.68) {
            return $byLabel('Old Money / Classic');
        }
        if ($brightness < 0.32) {
            return $byLabel('Gorpcore / Techwear');
        }

        return $styles->random()?->ui_label;
    }

    protected function pickBackground(float $brightness, $backgrounds): ?string
    {
        $byLabel = fn ($label) => $backgrounds->contains('ui_label', $label) ? $label : ($backgrounds->first()?->ui_label ?? null);

        if ($brightness > 0.68) {
            return $byLabel('Minimalist Studio');
        }
        if ($brightness < 0.32) {
            return $byLabel('Concrete Brutalism');
        }

        return $byLabel('High-End Boutique (Shop)');
    }
}