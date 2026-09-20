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
    /** Nhật ký thử của lần gọi gần nhất (xem lastAttempts()) — để log nói ĐÚNG vì sao không dùng được model. */
    protected array $lastAttempts = [];

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
     * CÔNG CỤ: khai `tools` (danh sách khai báo hàm) + `tool_handler` (hàm chạy công cụ ở phía máy chủ) thì
     * gateway tự chạy vòng lặp "model gọi → máy chủ thực thi → kết quả quay lại prompt". Kết quả trả về nói
     * THẬT chuyện đã xảy ra: `tools_accepted` (provider có hiểu `tools` không), `tool_calls`, `tool_queries`,
     * `tool_results` — giao diện đọc những số này chứ không đọc lời hứa.
     *
     * @param  array{response_format?:string, max_tokens?:int, timeout?:int, json?:bool, tools?:list<array<string,mixed>>, tool_handler?:callable, tool_rounds?:int}  $options
     * @return array{text:string, provider:string, model:string, group:string, finish_reason:?string, reasoning_only:bool, tools_accepted:?bool, tool_calls:int, tool_queries:list<string>, tool_results:int, hosted_calls:int, hosted_queries:list<string>, hosted_sources:list<string>}|null
     */
    public function text(string $group, array $messages, array $options = []): ?array
    {
        $this->lastAttempts = [];

        // NHÓM DỰ PHÒNG: nơi gọi có thể khai thêm các nhóm khác để thử khi nhóm chính không cho ra nội dung.
        //
        // Vì sao cần (lỗi thật trên production 2026-09-20): nhóm "tìm kiếm" được gán MỘT model suy luận
        // (qwen3.8-flash) đốt hết ngân sách token vào phần thinking rồi bị cắt (finish_reason=length,
        // reasoning_only=true) ⇒ chuỗi candidate của nhóm đó CHỈ có một model ⇒ agent mất phần suy luận
        // của AI và rơi về engine tất định. Có nhóm dự phòng thì chỉ cần một model khác trong Cài đặt là
        // agent chạy được ngay, không phải chờ ai đó phát hiện và đổi cấu hình.
        $groups = [$group];
        foreach ((array) ($options['fallback_groups'] ?? []) as $fallback) {
            $fallback = trim((string) $fallback);
            if ($fallback !== '' && $fallback !== $group) {
                $groups[] = $fallback;
            }
        }

        $candidates = [];
        $seen = [];
        foreach (array_values(array_unique($groups)) as $candidateGroup) {
            foreach ($this->candidates($candidateGroup) as $candidate) {
                $signature = $candidate['provider'].':'.$candidate['model'];
                if (isset($seen[$signature])) {
                    continue;
                }
                $seen[$signature] = true;
                $candidates[] = $candidate + ['group' => $candidateGroup];
            }
        }

        foreach ($candidates as $candidate) {
            foreach ($candidate['keys'] as $key) {
                try {
                    $result = $this->callText($candidate, $key, $messages, $options);
                } catch (\Throwable $e) {
                    logger()->warning('AiModelGateway text lỗi ('.$candidate['provider'].':'.$candidate['model'].'): '.$e->getMessage());
                    $this->lastAttempts[] = [
                        'group' => $candidate['group'], 'provider' => $candidate['provider'], 'model' => $candidate['model'],
                        'ok' => false, 'note' => 'lỗi khi gọi: '.class_basename($e),
                    ];
                    continue;
                }

                if ($result !== null && trim($result['text']) !== '') {
                    $this->lastAttempts[] = [
                        'group' => $candidate['group'], 'provider' => $candidate['provider'], 'model' => $candidate['model'],
                        'ok' => true, 'finish_reason' => $result['finish_reason'], 'reasoning_only' => $result['reasoning_only'],
                        'chars' => strlen($result['text']),
                    ];

                    return [
                        'text' => trim($result['text']),
                        'provider' => $candidate['provider'],
                        'model' => $candidate['model'],
                        'group' => $candidate['group'],
                        'finish_reason' => $result['finish_reason'],
                        'reasoning_only' => $result['reasoning_only'],
                        // CÔNG CỤ: đường KHÔNG có công cụ để null (không khai) thay vì false — "chưa từng thử"
                        // khác hẳn "đã thử và provider từ chối", và giao diện phải phân biệt được hai chuyện đó.
                        'tools_accepted' => $result['tools_accepted'] ?? null,
                        'tool_calls' => (int) ($result['tool_calls'] ?? 0),
                        'tool_queries' => array_values((array) ($result['tool_queries'] ?? [])),
                        'tool_results' => (int) ($result['tool_results'] ?? 0),
                        // CÔNG CỤ CỦA NHÀ CUNG CẤP qua /responses: số lượt tìm THẬT (0 = nhận tham số mà
                        // không tìm — model không hỗ trợ). Ba khoá này luôn có mặt để nơi gọi không phải đoán.
                        'hosted_calls' => (int) ($result['hosted_calls'] ?? 0),
                        'hosted_queries' => array_values((array) ($result['hosted_queries'] ?? [])),
                        'hosted_sources' => array_values((array) ($result['hosted_sources'] ?? [])),
                    ];
                }

                // Ghi lại VÌ SAO model này không dùng được — nếu chỉ ghi "không trả về nội dung" thì lần sau
                // vẫn phải đoán: hết ngân sách token (length) khác hẳn với "model trả lời rỗng".
                $this->lastAttempts[] = [
                    'group' => $candidate['group'], 'provider' => $candidate['provider'], 'model' => $candidate['model'],
                    'ok' => false,
                    'finish_reason' => $result['finish_reason'] ?? null,
                    'reasoning_only' => (bool) ($result['reasoning_only'] ?? false),
                    'chars' => strlen((string) ($result['text'] ?? '')),
                    'note' => $result === null ? 'không gọi được' : 'không có nội dung dùng được',
                ];
            }
        }

        return null;
    }

    /**
     * Nhật ký những lần thử của lần gọi GẦN NHẤT: mỗi dòng nói rõ nhóm · model · vì sao không dùng được.
     *
     * @return list<array<string, mixed>>
     */
    public function lastAttempts(): array
    {
        return $this->lastAttempts;
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
                'search_mode' => trim((string) ($custom['search_mode'] ?? '')) ?: null,
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
     * Điểm vào của một lời gọi văn bản: có CÔNG CỤ thì đi đường vòng lặp công cụ, không thì đi đường thường.
     *
     * @return array{text:string, finish_reason:?string, reasoning_only:bool, tools_accepted?:bool, tool_calls?:int, tool_queries?:list<string>, tool_results?:int}|null
     */
    protected function callText(array $candidate, string $key, array $messages, array $options): ?array
    {
        // ĐƯỜNG /responses + công cụ tìm kiếm CỦA NHÀ CUNG CẤP (2026-09-21): không phải tham số trong
        // /chat/completions mà là một endpoint khác hẳn, nên phải rẽ nhánh TRƯỚC các nhánh còn lại.
        if (! empty($options['search']) && WebAccessService::isHostedMode(WebAccessService::planFor($candidate))) {
            return $this->callResponsesWithSearch($candidate, $key, $messages, $options);
        }

        $tools = $this->toolDefinitions($options);

        // CÔNG CỤ (function calling): chỉ trên họ giao thức OpenAI-compatible — đó là nơi chuẩn "tools" là
        // chung và cũng là nơi model văn bản đang chạy trên production (DeepSeek) thuộc về. Gemini đi đường
        // riêng (grounding bằng tools của chính nó) nên KHÔNG vào đây, tránh gửi sai hình dạng tham số.
        if ($tools !== [] && in_array((string) $candidate['transport'], ['qwen', 'dashscope', 'openai'], true)) {
            return $this->callWithTools($candidate, $key, $messages, $options, $tools);
        }

        return $this->callPlain($candidate, $key, $messages, $options);
    }

    /**
     * Danh sách công cụ hợp lệ của lời gọi này — RỖNG khi thiếu hàm thực thi.
     *
     * Vì sao kiểm cả hai: khai công cụ mà không có hàm chạy nó thì model gọi vào khoảng không, và lượt chạy
     * hỏng ở chỗ khó thấy nhất. Thà không khai công cụ (lượt chạy vẫn xong) còn hơn khai rồi treo.
     *
     * @return list<array<string, mixed>>
     */
    protected function toolDefinitions(array $options): array
    {
        $tools = $options['tools'] ?? [];
        if (! is_array($tools) || $tools === [] || ! is_callable($options['tool_handler'] ?? null)) {
            return [];
        }

        return array_values(array_filter($tools, 'is_array'));
    }

    /** Base URL chat-completions của một candidate (qwen/dashscope dùng compatible-mode của DashScope). */
    protected function chatBase(array $candidate, string $key): string
    {
        if ($candidate['transport'] === 'qwen') {
            return dashscope_base_url($key).'/compatible-mode/v1';
        }

        if ($candidate['transport'] === 'dashscope' && ($candidate['base'] ?? '') === '') {
            return dashscope_base_url($key).'/compatible-mode/v1';
        }

        return rtrim((string) $candidate['base'], '/');
    }

    /**
     * VÒNG LẶP CÔNG CỤ — model hỏi, MÁY CHỦ chạy công cụ, kết quả quay lại prompt, model trả lời.
     *
     * Vì sao ở lớp gateway chứ không ở từng tính năng: vòng lặp này phải giống nhau ở mọi nơi gọi, và nó
     * phải nằm CÙNG CHỖ với các ràng buộc sẵn có (tắt suy luận, cờ tìm kiếm của nhà cung cấp, gọi lại khi
     * provider không hiểu tham số). Viết lại ở nơi gọi là tạo bản sao sẽ lệch chuẩn.
     *
     * BA điều bắt buộc, đều là bài học từ lỗi thật của dự án:
     *   1. Provider KHÔNG hiểu `tools` ⇒ gọi lại y như đường thường, và GHI RÕ `tools_accepted=false`. Một
     *      tham số tuỳ chọn không được phép làm hỏng cả lượt chạy, nhưng cũng không được im lặng nói dối là
     *      đã có công cụ.
     *   2. Lượt CUỐI không gửi công cụ: model buộc phải trả lời bằng dữ liệu đang có, thay vì đòi tìm tiếp
     *      cho tới khi hết thời gian chờ.
     *   3. Kết quả công cụ là DỮ LIỆU do người ngoài viết — chỉ được đưa vào như nội dung của role "tool",
     *      không bao giờ được ghép vào phần chỉ dẫn hệ thống.
     *
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>|null
     */
    protected function callWithTools(array $candidate, string $key, array $messages, array $options, array $tools): ?array
    {
        $timeout = (int) ($options['timeout'] ?? 90);
        $maxTokens = (int) ($options['max_tokens'] ?? 1024);
        $handler = $options['tool_handler'];
        // Trần vòng: đủ cho "tìm → đọc kết quả → tìm tiếp nếu cần", không đủ để một model lan man quay
        // vòng cho tới khi proxy trả 504.
        $maxRounds = max(1, min(5, (int) ($options['tool_rounds'] ?? 3)));
        // Tên công cụ ĐÃ KHAI: model bịa tên khác thì trả lỗi đọc được cho nó tự sửa, không chạy bừa.
        $allowed = array_values(array_filter(array_map(
            fn (array $tool) => (string) ($tool['function']['name'] ?? ''),
            $tools,
        ), 'strlen'));
        $base = $this->chatBase($candidate, $key);

        // Mở LẦN THỬ mới: công cụ tự tính lại trần lời gọi của mình (lần thử lại bắt đầu hội thoại mới nên
        // kết quả tìm cũ không còn trong prompt — xem WebSearchTool::beginAttempt()).
        if (is_callable($options['tool_begin'] ?? null)) {
            ($options['tool_begin'])();
        }

        $conversation = $messages;
        $queries = [];
        $calls = 0;
        $results = 0;

        for ($round = 1; $round <= $maxRounds + 1; $round++) {
            $offerTools = $round <= $maxRounds;

            $body = ['model' => $candidate['model'], 'messages' => $conversation, 'max_tokens' => $maxTokens];
            if (($options['response_format'] ?? '') === 'json_object') {
                $body['response_format'] = ['type' => 'json_object'];
            }
            if ($offerTools) {
                $body['tools'] = $tools;
                $body['tool_choice'] = 'auto';
            }
            $this->applySearch($body, $options, $candidate);
            $thinkingOff = $this->applyThinkingOff($body, $candidate, $options);

            $response = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', $body);
            if ($thinkingOff && ! $response->successful()) {
                unset($body['enable_thinking']);
                $response = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', $body);
            }

            if (! $response->successful()) {
                if (! $offerTools) {
                    return null;
                }

                // (1) Provider không hiểu `tools`: chạy lại đúng đường thường rồi NÓI THẬT là không có công cụ.
                $plain = $this->callPlain($candidate, $key, $messages, $options);

                return $plain === null ? null : $plain + [
                    'tools_accepted' => false, 'tool_calls' => 0, 'tool_queries' => [], 'tool_results' => 0,
                ];
            }

            $json = $response->json();
            $toolCalls = data_get($json, 'choices.0.message.tool_calls');

            if (! is_array($toolCalls) || $toolCalls === [] || ! $offerTools) {
                return $this->textResult($json) + [
                    'tools_accepted' => true, 'tool_calls' => $calls, 'tool_queries' => $queries, 'tool_results' => $results,
                ];
            }

            // Lời gọi công cụ phải được ghi lại NGUYÊN VẸN trong hội thoại: thiếu nó thì lượt sau provider
            // từ chối vì thấy role "tool" không có lời gọi tương ứng.
            $assistant = (array) data_get($json, 'choices.0.message', []);
            $conversation[] = [
                'role' => 'assistant',
                'content' => (string) ($assistant['content'] ?? ''),
                'tool_calls' => array_values($toolCalls),
            ];

            foreach ($toolCalls as $index => $call) {
                $id = (string) (data_get($call, 'id') ?: 'call_'.$round.'_'.$index);
                $name = (string) data_get($call, 'function.name');
                $arguments = json_decode((string) data_get($call, 'function.arguments'), true);
                $arguments = is_array($arguments) ? $arguments : [];
                $calls++;

                if ($name === '' || ! in_array($name, $allowed, true)) {
                    // Model bịa tên hàm: trả về lỗi ĐỌC ĐƯỢC để nó tự sửa, không ném ngoại lệ.
                    $payload = ['error' => 'không có công cụ tên "'.$name.'"'];
                } else {
                    $payload = (array) $handler($name, $arguments);
                    $query = trim((string) ($payload['query'] ?? ''));
                    if ($query !== '') {
                        $queries[] = $query;
                    }
                    $results += (int) ($payload['found'] ?? 0);
                }

                // CHỈ `role` + `tool_call_id` + `content`: đó là hình dạng TỐI THIỂU mà mọi gateway
                // OpenAI-compatible chấp nhận. Thêm trường tuỳ chọn (`name`) là rủi ro không đáng có — một
                // gateway khắt khe từ chối cả lượt chạy chỉ vì một khoá thừa, trong khi khoá đó không cần
                // thiết (`tool_call_id` đã nối kết quả với đúng lời gọi).
                $conversation[] = [
                    'role' => 'tool',
                    'tool_call_id' => $id,
                    'content' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        return null;
    }

    /**
     * GỌI ENDPOINT /responses KÈM CÔNG CỤ TÌM KIẾM CỦA NHÀ CUNG CẤP.
     *
     * Vì sao có đường riêng: đo thật trên production (2026-09-21) — cùng một tham số
     * `tools:[{"type":"web_search"}]`:
     *   · `/chat/completions` → **HTTP 422** `unknown variant web_search, expected function`;
     *   · `/responses` → **HTTP 200**, sinh ra mục `web_search_call` với truy vấn và trang đã mở THẬT.
     * Nhưng đường này CHỈ chạy với model hỗ trợ: cùng tham số đó, model nhỏ hơn trả lời trơn tru mà KHÔNG
     * có lời gọi tìm kiếm nào và tự BỊA tin + URL. Nên kết quả ở đây luôn kèm SỐ ĐO của việc đã xảy ra
     * (`hosted_calls`), và nơi gọi chỉ được nói "đã tìm" khi con số đó > 0.
     *
     * Không ném lỗi khi endpoint không dùng được: rơi về đường /chat/completions thường (một tính năng
     * tuỳ chọn không được phép làm hỏng cả lượt chạy) — chỉ là lượt đó không có tìm kiếm, và điều đó được
     * báo đúng bằng `hosted_calls = 0`.
     *
     * @return array<string, mixed>|null
     */
    protected function callResponsesWithSearch(array $candidate, string $key, array $messages, array $options): ?array
    {
        $plan = WebAccessService::planFor($candidate) ?? [];
        $timeout = (int) ($options['timeout'] ?? 90);
        $maxTokens = (int) ($options['max_tokens'] ?? 1024);
        $base = $this->chatBase($candidate, $key);

        // /responses tách phần CHỈ DẪN (system) khỏi phần ĐẦU VÀO (user) — gộp lại thì mất ranh giới giữa
        // "luật của hệ thống" và "dữ liệu người dùng", đúng ranh giới mà lớp chống prompt-injection dựa vào.
        $instructions = [];
        $input = [];
        foreach ($messages as $message) {
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = collect($content)->map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : (string) $part)->implode('\n');
            }
            $content = trim((string) $content);
            if ($content === '') {
                continue;
            }
            if (($message['role'] ?? '') === 'system') {
                $instructions[] = $content;
            } else {
                $input[] = $content;
            }
        }

        $body = [
            'model' => $candidate['model'],
            'instructions' => implode("\n\n", $instructions),
            'input' => implode("\n\n", $input),
            'max_output_tokens' => $maxTokens,
            'tools' => [['type' => (string) ($plan['param'] ?: 'web_search')]],
        ];
        if (($options['response_format'] ?? '') === 'json_object') {
            $body['text'] = ['format' => ['type' => 'json_object']];
        }
        // Đo THẬT cần cả URL nguồn: thiếu `include` thì một số lượt không kèm danh sách nguồn đã mở.
        $body['include'] = ['web_search_call.action.sources'];

        try {
            $response = Http::withToken($key)->timeout($timeout)->post($base.'/responses', $body);
        } catch (\Throwable $e) {
            logger()->warning('AiModelGateway /responses lỗi: '.$e->getMessage());

            return $this->fallbackWithoutHostedSearch($candidate, $key, $messages, $options, $e);
        }

        if (! $response->successful()) {
            // Endpoint không có / không cho phép công cụ ⇒ vẫn phải trả lời được, chỉ là không có tìm kiếm.
            return $this->fallbackWithoutHostedSearch($candidate, $key, $messages, $options, null, $response->status());
        }

        return $this->responsesResult($response->json())
            + ['tools_accepted' => true, 'tool_calls' => 0, 'tool_queries' => [], 'tool_results' => 0];
    }

    /** Rơi về /chat/completions khi /responses không dùng được — báo ĐÚNG là lượt này không tìm kiếm. */
    protected function fallbackWithoutHostedSearch(array $candidate, string $key, array $messages, array $options, ?\Throwable $error = null, ?int $status = null): ?array
    {
        logger()->warning('AiModelGateway: /responses không dùng được, quay về /chat/completions', [
            'provider' => $candidate['provider'], 'model' => $candidate['model'],
            'status' => $status, 'error' => $error?->getMessage(),
        ]);

        unset($options['search']);
        $plain = $this->callPlain($candidate, $key, $messages, $options);

        return $plain === null ? null : $plain + [
            'tools_accepted' => null, 'tool_calls' => 0, 'tool_queries' => [], 'tool_results' => 0,
            'hosted_calls' => 0, 'hosted_queries' => [], 'hosted_sources' => [],
        ];
    }

    /**
     * Đọc phản hồi của /responses: chữ trả lời + SỐ ĐO công cụ tìm kiếm đã chạy thật.
     *
     * @param  mixed  $json
     * @return array<string, mixed>
     */
    protected function responsesResult($json): array
    {
        $text = '';
        $calls = 0;
        $queries = [];
        $sources = [];
        $finish = null;

        foreach ((array) data_get($json, 'output', []) as $item) {
            $type = (string) ($item['type'] ?? '');

            if ($type === 'web_search_call') {
                $calls++;
                foreach ((array) data_get($item, 'action.queries', []) as $query) {
                    // Gateway gắn hậu tố nội bộ `ws_call_id=…` vào truy vấn — không phải từ khoá người dùng.
                    $query = trim(preg_replace('/ws_call_id=\S+/', '', (string) $query) ?? '');
                    if ($query !== '') {
                        $queries[] = $query;
                    }
                }
                $url = (string) data_get($item, 'action.url', '');
                if ($url !== '') {
                    $sources[] = preg_replace('/#ws_call_id=\S+/', '', $url) ?? $url;
                }
                foreach ((array) data_get($item, 'action.sources', []) as $row) {
                    $u = (string) (is_array($row) ? ($row['url'] ?? '') : $row);
                    if ($u !== '') {
                        $sources[] = $u;
                    }
                }
            }

            if ($type === 'message') {
                foreach ((array) ($item['content'] ?? []) as $part) {
                    $text .= (string) ($part['text'] ?? '');
                }
                $finish = (string) ($item['status'] ?? '') ?: $finish;
            }
        }

        return [
            'text' => $text,
            'finish_reason' => (string) data_get($json, 'status', '') ?: $finish,
            'reasoning_only' => $text === '' && data_get($json, 'output.0.type') === 'reasoning',
            // SỐ ĐO THẬT của công cụ: 0 = model nhận tham số nhưng KHÔNG hề tìm (đừng nói là đã tìm).
            'hosted_calls' => $calls,
            'hosted_queries' => array_values(array_unique($queries)),
            'hosted_sources' => array_values(array_unique($sources)),
        ];
    }

    /**
     * Đường gọi THƯỜNG (không công cụ) — thân cũ của callText, giữ nguyên hành vi.
     *
     * @return array{text:string, finish_reason:?string, reasoning_only:bool}|null
     */
    protected function callPlain(array $candidate, string $key, array $messages, array $options): ?array
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
            $thinkingOff = $this->applyThinkingOff($body, $candidate, $options);
            $resp = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', $body);
            if ($thinkingOff && ! $resp->successful()) {
                // Provider không hiểu cờ tắt suy luận ⇒ gọi lại KHÔNG có cờ đó. Một tham số tuỳ chọn không
                // bao giờ được phép làm hỏng cả lời gọi.
                unset($body['enable_thinking']);
                $resp = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', $body);
            }

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
        $thinkingOff = $this->applyThinkingOff($body, $candidate, $options);
        $resp = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', $body);
        if ($thinkingOff && ! $resp->successful()) {
            unset($body['enable_thinking']);
            $resp = Http::withToken($key)->timeout($timeout)->post($base.'/chat/completions', $body);
        }

        return $resp->successful() ? $this->textResult($resp->json()) : null;
    }

    /**
     * TẮT SUY LUẬN DÀI cho việc cần JSON — lỗi thật trên production 2026-09-20.
     *
     * Chuyện đã xảy ra: model "suy luận" (qwen3.8-flash · deepseek-flash) tính CẢ token suy luận vào
     * `max_tokens`, nên nó viết gần 10.000 ký tự suy luận rồi bị cắt (`finish_reason=length`,
     * `reasoning_only=true`) và **không bao giờ viết ra JSON**. Hệ quả: mọi lượt radar đều mất phần suy
     * luận của AI, rơi về engine tất định, và người dùng thấy toàn hướng của BỘ CÓ SẴN dù đã nối nguồn thật.
     *
     * Cách sửa: khi nơi gọi khai `disable_thinking`, gửi `enable_thinking: false` cho các model họ Qwen3
     * trên giao thức DashScope (đúng tham số của họ). An toàn: provider không hiểu tham số ⇒ nơi gọi
     * (callText) tự gọi lại KHÔNG có tham số đó, nên một cờ tuỳ chọn không thể làm hỏng lời gọi.
     *
     * @param  array<string,mixed>  $body
     * @return bool  đã gửi cờ hay chưa (để biết có cần gọi lại khi provider từ chối)
     */
    protected function applyThinkingOff(array &$body, array $candidate, array $options): bool
    {
        if (($options['disable_thinking'] ?? false) !== true) {
            return false;
        }

        $transport = (string) ($candidate['transport'] ?? '');
        $model = mb_strtolower((string) ($candidate['model'] ?? ''));
        $hybrid = str_contains($model, 'qwen3') || str_contains($model, 'thinking') || str_contains($model, 'reasoner');
        if (! in_array($transport, ['qwen', 'dashscope'], true) || ! $hybrid) {
            return false;
        }

        $body['enable_thinking'] = false;

        return true;
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

        // Chế độ /responses KHÔNG phải một tham số trong body: nó là endpoint khác (xem
        // callResponsesWithSearch). Rơi vào `default` ở đây sẽ gửi `{"web_search": true}` vào
        // /chat/completions — một tham số bịa, đúng loại lỗi mà lớp này sinh ra để chặn.
        if (WebAccessService::isHostedMode($plan)) {
            return;
        }

        switch ($plan['mode']) {
            case 'tools':
                $body['tools'] = [[$plan['param'] => new \stdClass()]];
                break;
            case 'model_suffix':
                // Một số gateway bật tìm kiếm bằng CHÍNH TÊN MODEL (vd OpenRouter ':online').
                if (! str_ends_with((string) $body['model'], (string) $plan['param'])) {
                    $body['model'] = (string) $body['model'].$plan['param'];
                }
                break;
            case 'plugins':
                $body['plugins'] = [['id' => $plan['param']]];
                break;
            default:
                $body[$plan['param']] = true;
        }
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
