<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * AI MODEL GATEWAY — một cửa duy nhất cho mọi lời gọi model văn bản/vision.
 *
 * Vì sao cần: trước đây mỗi tính năng tự chọn provider/model bằng hằng số hoặc setting rời
 * (StylistService cứng Qwen → Gemini; translate cứng Gemini → Qwen; QA thử đồ cứng qwen-vl).
 * Hệ quả: đổi Model Registry / Nhóm công việc / Luồng ưu tiên / custom provider trong Cài đặt
 * KHÔNG tác động tới các đường đó — cấu hình hiển thị một đằng, gọi model một nẻo.
 *
 * Cách làm: mọi call-site đi qua candidates($group) (lấy từ studio_task_group_models(), đã tôn
 * trọng: default nhóm → Model Registry → luồng ưu tiên provider → ưu tiên provider → ưu tiên
 * model) rồi thử lần lượt từng candidate/key. Provider không có key dùng được bị bỏ qua NGAY,
 * không gọi rồi mới lỗi. Custom provider (Settings → Custom Providers) dùng đúng protocol +
 * base_url + api_key_ref của nó.
 *
 * Không tự quyết định model: gateway chỉ thực thi thứ mà bản khai module/task-group trả về.
 */
class AiModelGateway
{
    /** @return list<array{provider:string, model:string, transport:string, base:string, keys:list<string>, search_param?:?string}> */
    public function candidates(string $group): array
    {
        if (! function_exists('studio_task_group_models')) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach (studio_task_group_models($group) as $row) {
            $provider = trim((string) ($row['provider'] ?? ''));
            $model = trim((string) ($row['model'] ?? ''));
            $key = $provider.':'.$model;
            if ($provider === '' || $model === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $candidate = $this->resolve($provider, $model, $group);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /** Nhóm này có ít nhất một candidate dùng được (có key) không? */
    public function has(string $group): bool
    {
        return $this->candidates($group) !== [];
    }

    /**
     * Credential của một nhóm dưới dạng PHẲNG — cho những đường KHÔNG phải chat thường
     * (video async DashScope, edit multimodal, vision QA tự viết…) nhưng vẫn phải tôn trọng
     * Model Registry / Nhóm công việc / Luồng ưu tiên / Custom Providers.
     *
     * @param  list<string>  $transports  lọc theo transport (['qwen'], ['gemini'], ['openai']); [] = tất cả
     * @return list<array{provider:string, model:string, transport:string, base:string, key:string}>
     */
    public function credentials(string $group, array $transports = []): array
    {
        $out = [];
        foreach ($this->candidates($group) as $candidate) {
            if ($transports !== [] && ! in_array($candidate['transport'], $transports, true)) {
                continue;
            }
            foreach ($candidate['keys'] as $key) {
                $out[] = [
                    'provider' => $candidate['provider'],
                    'model' => $candidate['model'],
                    'transport' => $candidate['transport'],
                    'base' => $candidate['base'],
                    'key' => (string) $key,
                ];
            }
        }

        return $out;
    }

    /** Khóa đầu tiên dùng được của nhóm (đúng thứ tự ưu tiên đã cấu hình). */
    public function key(string $group, array $transports = []): ?string
    {
        return $this->credentials($group, $transports)[0]['key'] ?? null;
    }

    /**
     * Khóa DashScope/Qwen-family cho các DỊCH VỤ DashScope NGUYÊN BẢN (image-moderation,
     * image-super-resolution, face-image-enhance): chúng không phải model trong Model Registry
     * nên không có nhóm công việc riêng — nhưng vẫn ưu tiên khóa đến từ các nhóm đang chạy
     * trên DashScope (edit → image → video → swap → vision) rồi mới tới slot cổ điển.
     */
    public function dashscopeKey(): ?string
    {
        foreach (['edit', 'image', 'video', 'swap', 'vision'] as $group) {
            $key = $this->key($group, ['qwen']);
            if ($key) {
                return $key;
            }
        }

        if (function_exists('studio_api_key')) {
            foreach (['dashscope', 'qwen', 'qwen_edit'] as $service) {
                $key = studio_api_key($service);
                if ($key) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Gọi model văn bản theo nhóm công việc (prompt / translate / …).
     *
     * @param  list<array{role:string, content:mixed}>  $messages
     * @param  array{response_format?:string, max_tokens?:int, timeout?:int, json?:bool}  $options
     * @return array{text:string, provider:string, model:string, finish_reason:?string, reasoning_only:bool}|null
     */
    public function text(string $group, array $messages, array $options = []): ?array
    {
        foreach ($this->candidates($group) as $candidate) {
            foreach ($candidate['keys'] as $key) {
                try {
                    $result = $this->callText($candidate, $key, $messages, $options);
                } catch (\Throwable $e) {
                    logger()->warning('AiModelGateway text lỗi ('.$candidate['provider'].':'.$candidate['model'].'): '.$e->getMessage());
                    continue;
                }
                if ($result !== null && trim($result['text']) !== '') {
                    return [
                        'text' => trim($result['text']),
                        'provider' => $candidate['provider'],
                        'model' => $candidate['model'],
                        'finish_reason' => $result['finish_reason'],
                        'reasoning_only' => $result['reasoning_only'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Gọi model vision theo nhóm công việc 'vision' (đọc ảnh).
     *
     * @param  list<string>  $images  data URI hoặc đường dẫn file local
     * @param  array{max_tokens?:int, timeout?:int}  $options
     * @return array{text:string, provider:string, model:string}|null
     */
    public function vision(string $group, string $instruction, array $images, array $options = []): ?array
    {
        $parts = [];
        foreach ($images as $image) {
            $data = $this->imageDataUri($image);
            if ($data !== null) {
                $parts[] = $data;
            }
        }
        if ($parts === []) {
            return null;
        }

        foreach ($this->candidates($group) as $candidate) {
            foreach ($candidate['keys'] as $key) {
                try {
                    $text = $this->callVision($candidate, $key, $instruction, $parts, $options);
                } catch (\Throwable $e) {
                    logger()->warning('AiModelGateway vision lỗi ('.$candidate['provider'].':'.$candidate['model'].'): '.$e->getMessage());
                    continue;
                }
                if ($text !== null && trim($text) !== '') {
                    return ['text' => trim($text), 'provider' => $candidate['provider'], 'model' => $candidate['model']];
                }
            }
        }

        return null;
    }

    /**
     * Một dòng task-group → transport cụ thể.
     * Built-in dùng transport viết tay; custom provider dùng protocol/base_url trong Settings.
     */
    protected function resolve(string $provider, string $model, string $group): ?array
    {
        $custom = function_exists('studio_custom_provider') ? studio_custom_provider($provider) : null;
        $keys = function_exists('studio_candidate_key')
            ? studio_candidate_key(['provider' => $provider, 'model' => $model], $group)
            : [];

        if ($custom) {
            $base = rtrim((string) ($custom['base_url'] ?? ''), '/');
            if ($base === '' || $keys === []) {
                return null;
            }
            $protocol = (string) ($custom['protocol'] ?? 'openai');
            $transport = in_array($protocol, ['openai', 'dashscope', 'gemini'], true) ? $protocol : 'openai';

            // `search_param`: nhà cung cấp TỰ KHAI cách bật tìm kiếm web (Cài đặt → Custom Providers).
            // Có mặt ở đây thì WebAccessService::planFor() đọc được mà không phải truy vấn thêm lần nữa.
            return [
                'provider' => $provider, 'model' => $model, 'transport' => $transport, 'base' => $base, 'keys' => $keys,
                'search_param' => trim((string) ($custom['search_param'] ?? '')) ?: null,
            ];
        }

        $catalog = function_exists('studio_provider_catalog') ? studio_provider_catalog() : [];
        $meta = $catalog[$provider] ?? null;

        if (in_array($provider, ['qwen', 'qwen_edit', 'dashscope', 'wan'], true)) {
            return $keys !== [] ? ['provider' => $provider, 'model' => $model, 'transport' => 'qwen', 'base' => '', 'keys' => $keys] : null;
        }

        if (in_array($provider, ['gemini', 'veo'], true)) {
            return $keys !== [] ? ['provider' => $provider, 'model' => $model, 'transport' => 'gemini', 'base' => '', 'keys' => $keys] : null;
        }

        $base = rtrim((string) ($meta['base_url'] ?? ''), '/');
        if ($base !== '' && (string) ($meta['protocol'] ?? '') === 'openai') {
            return $keys !== [] ? ['provider' => $provider, 'model' => $model, 'transport' => 'openai', 'base' => $base, 'keys' => $keys] : null;
        }

        return null;
    }

    /**
     * @return array{text:string, finish_reason:?string, reasoning_only:bool}|null
     */
    protected function callText(array $candidate, string $key, array $messages, array $options): ?array
    {
        $timeout = (int) ($options['timeout'] ?? 90);
        $maxTokens = (int) ($options['max_tokens'] ?? 1024);

        if ($candidate['transport'] === 'qwen') {
            $base = dashscope_base_url($key).'/compatible-mode/v1';
            $body = ['model' => $candidate['model'], 'messages' => $messages, 'max_tokens' => $maxTokens];
            if (($options['response_format'] ?? '') === 'json_object') {
                $body['response_format'] = ['type' => 'json_object'];
            }
            // [2026-09-23] TÌM KIẾM WEB — áp dụng theo KẾ HOẠCH của candidate đang được CẤU HÌNH
            // (`WebAccessService::planFor`): giao thức biết cách bật, còn chọn nhà cung cấp/model nào là
            // việc của Cài đặt. Không bật bừa: provider không khai thì gửi tham số lạ có thể hỏng request.
            $this->applySearch($body, $options, $candidate);
            $resp = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', $body);

            return $resp->successful() ? $this->textResult($resp->json()) : null;
        }

        if ($candidate['transport'] === 'gemini') {
            $prompt = $this->messagesToPrompt($messages);
            $body = [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['maxOutputTokens' => $maxTokens],
            ];
            if (($options['response_format'] ?? '') === 'json_object') {
                $body['generationConfig']['responseMimeType'] = 'application/json';
            }
            // TÌM KIẾM WEB (Gemini): cùng một cơ chế — kế hoạch lấy từ candidate đang cấu hình.
            $this->applySearch($body, $options, $candidate);
            $resp = Http::withHeaders(['x-goog-api-key' => $key])->timeout($timeout)
                ->post($this->geminiBase($candidate).'/models/'.$candidate['model'].':generateContent', $body);

            if (! $resp->successful()) {
                return null;
            }
            $json = $resp->json();

            return [
                'text' => (string) data_get($json, 'candidates.0.content.parts.0.text'),
                'finish_reason' => strtolower((string) data_get($json, 'candidates.0.finishReason')) ?: null,
                'reasoning_only' => false,
            ];
        }

        // openai-compatible (built-in base_url hoặc custom provider)
        $base = $candidate['transport'] === 'dashscope' && $candidate['base'] === ''
            ? dashscope_base_url($key).'/compatible-mode/v1'
            : rtrim($candidate['base'], '/');
        $body = ['model' => $candidate['model'], 'messages' => $messages, 'max_tokens' => $maxTokens];
        if (($options['response_format'] ?? '') === 'json_object') {
            $body['response_format'] = ['type' => 'json_object'];
        }
        // OpenAI-compatible KHÔNG có cờ tìm kiếm chuẩn — chỉ bật khi CHÍNH nhà cung cấp tự khai tham số
        // (`studio_providers.search_param`), tức là đến từ Cài đặt.
        $this->applySearch($body, $options, $candidate);
        $resp = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', $body);

        return $resp->successful() ? $this->textResult($resp->json()) : null;
    }

    /**
     * Bật tìm kiếm web cho MỘT request — CHỈ khi nơi gọi yêu cầu và candidate đang cấu hình có kế hoạch.
     *
     * Một chỗ duy nhất để cả ba nhánh giao thức dùng chung: nếu mỗi nhánh tự viết, sớm muộn có nhánh
     * quên (hoặc bật tham số mà provider không hiểu).
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $candidate
     */
    protected function applySearch(array &$body, array $options, array $candidate): void
    {
        if (empty($options['search'])) {
            return;
        }

        $plan = WebAccessService::planFor($candidate);
        if ($plan === null) {
            return;
        }

        if ($plan['mode'] === 'tools') {
            $body['tools'] = [[$plan['param'] => new \stdClass()]];

            return;
        }

        $body[$plan['param']] = true;
    }

    /**
     * Chuẩn hoá một phản hồi chat-completions.
     *
     * Vì sao cần finish_reason + reasoning_only: model "suy luận" (deepseek-flash, *-reasoner…)
     * tính CẢ token suy luận vào max_tokens, nên khi ngân sách token cạn thì 'content' có thể
     * RỖNG (chỉ còn reasoning) hoặc bị CẮT giữa chừng với finish_reason='length'. Người gọi cần
     * biết điều đó để thử LẠI với ngân sách lớn hơn thay vì tưởng model trả lời sai.
     *
     * @return array{text:string, finish_reason:?string, reasoning_only:bool}
     */
    protected function textResult($json): array
    {
        $content = trim((string) data_get($json, 'choices.0.message.content'));
        $reasoning = trim((string) data_get($json, 'choices.0.message.reasoning_content'));

        return [
            'text' => $content !== '' ? $content : $reasoning,
            'finish_reason' => data_get($json, 'choices.0.finish_reason') ?: null,
            'reasoning_only' => $content === '' && $reasoning !== '',
        ];
    }

    protected function callVision(array $candidate, string $key, string $instruction, array $dataUris, array $options): ?string
    {
        $timeout = (int) ($options['timeout'] ?? 120);
        $maxTokens = (int) ($options['max_tokens'] ?? 2048);

        if ($candidate['transport'] === 'gemini') {
            $parts = [['text' => $instruction]];
            foreach ($dataUris as $uri) {
                [$mime, $b64] = $this->splitDataUri($uri);
                if ($b64 !== '') {
                    $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $b64]];
                }
            }
            $resp = Http::withHeaders(['x-goog-api-key' => $key])->timeout($timeout)
                ->post($this->geminiBase($candidate).'/models/'.$candidate['model'].':generateContent', [
                    'contents' => [['parts' => $parts]],
                    'generationConfig' => ['maxOutputTokens' => $maxTokens],
                ]);

            return $resp->successful() ? (string) data_get($resp->json(), 'candidates.0.content.parts.0.text') : null;
        }

        $base = $candidate['transport'] === 'qwen'
            ? dashscope_base_url($key).'/compatible-mode/v1'
            : rtrim($candidate['base'], '/');

        $content = [['type' => 'text', 'text' => $instruction]];
        foreach ($dataUris as $uri) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $uri]];
        }

        $resp = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', [
            'model' => $candidate['model'],
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);

        if (! $resp->successful()) {
            return null;
        }

        return (string) (data_get($resp->json(), 'choices.0.message.content')
            ?: data_get($resp->json(), 'choices.0.message.reasoning_content'));
    }

    protected function geminiBase(array $candidate): string
    {
        return $candidate['base'] !== ''
            ? rtrim($candidate['base'], '/')
            : 'https://generativelanguage.googleapis.com/v1beta';
    }

    protected function messagesToPrompt(array $messages): string
    {
        $out = [];
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = collect($content)->map(fn ($part) => $part['text'] ?? '')->implode('\n');
            }
            $out[] = trim((string) $content);
        }

        return trim(implode("\n\n", array_filter($out)));
    }

    /** data URI hoặc file local → data URI (provider bên ngoài không fetch được localhost). */
    protected function imageDataUri(string $image): ?string
    {
        if (str_starts_with($image, 'data:')) {
            return $image;
        }

        if (function_exists('studio_vision_image_data_uri')) {
            return studio_vision_image_data_uri($image, 1600);
        }

        $path = function_exists('studio_safe_public_file') ? studio_safe_public_file($image) : null;
        if (! $path || ! is_file($path)) {
            return null;
        }

        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/jpeg') : 'image/jpeg';
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /** @return array{0:string,1:string} [mime, base64] */
    protected function splitDataUri(string $uri): array
    {
        if (preg_match('#^data:([^;]+);base64,(.*)$#s', $uri, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return ['image/jpeg', ''];
    }
}
