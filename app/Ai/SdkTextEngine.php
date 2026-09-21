<?php

namespace App\Ai;

use Laravel\Ai\Ai;
use Laravel\Ai\Messages\Message;

/**
 * ĐỘNG CƠ TEXT CHẠY TRÊN LARAVEL AI SDK (2026-09-22).
 *
 * Vì sao lớp này tồn tại: yêu cầu là SDK trở thành TRÁI TIM điều phối hệ AI đang có. Cách rủi ro thấp
 * nhất để làm việc đó mà không phải sửa hàng loạt nơi gọi: đặt SDK vào BÊN TRONG AiModelGateway, giữ
 * nguyên hợp đồng công khai của nó. Nhờ vậy mọi dịch vụ đã đi qua gateway (DesignAgentService ·
 * StylistService · VirtualTryOnService · GeminiService · VideoAIService) tự động chạy trên SDK mà không
 * phải đổi một dòng nào ở nơi gọi.
 *
 * PHẠM VI CÓ CHỦ Ý: chỉ đường openai-compatible. Đó là nơi đã ĐO ĐƯỢC SDK chạy đúng (deepseek +
 * qwen-paygo, structured output có dữ liệu thật). Gemini đi đường riêng của SDK và có cách khai tham số
 * khác hẳn, nên chưa đưa vào khi chưa đo — thà chạy đường cũ đã kiểm chứng còn hơn đổi mà không có bằng chứng.
 *
 * ĐIỀU LỚP NÀY KHÔNG LÀM: không gọi công cụ tìm kiếm. SDK v0.11.2 không hỗ trợ WebSearch cho driver
 * openai-compatible (xem RegistryProviders), nên hai đường cần công cụ — hosted web_search và vòng lặp
 * hàm — vẫn do AiModelGateway tự chạy. Đó là lý do gateway là BỘ ĐIỀU PHỐI chứ không phải lớp vứt đi.
 */
class SdkTextEngine
{
    /** Candidate này có chạy được trên SDK không (hiện chỉ đường openai-compatible). */
    public function supports(array $candidate): bool
    {
        return SdkProviderMap::driverFor($candidate) === 'openai-compatible';
    }

    /**
     * Chạy MỘT lượt text qua SDK cho một candidate + khoá.
     *
     * Trả về CÙNG hình dạng mà AiModelGateway::callPlain() trả về — nơi gọi không phải biết động cơ nào
     * vừa chạy. null = không chạy được (thiếu địa chỉ · lượt gọi hỏng · không có nội dung).
     *
     * @param  list<array{role:string, content:mixed}>  $messages
     * @return array{text:string, finish_reason:?string, reasoning_only:bool}|null
     */
    public function run(array $candidate, string $key, array $messages, array $options = []): ?array
    {
        $config = SdkProviderMap::configFor($candidate, $key);
        if ($config === null) {
            return null;
        }

        $name = SdkProviderMap::nameForKey($candidate, $key);
        config(['ai.providers.'.$name => $config]);

        // Quên instance đã dựng: cùng một tên có thể đã bị dựng với cấu hình khác (khoá/địa chỉ đổi giữa
        // hai lượt trong cùng tiến trình). Không quên thì lượt sau lặng lẽ dùng lại cấu hình CŨ.
        try {
            Ai::forgetInstance($name);
        } catch (\Throwable) {
            // Chưa dựng instance nào thì không có gì để quên — không phải lỗi.
        }

        [$instructions, $history, $prompt] = $this->splitMessages($messages);
        if ($prompt === '') {
            // Không có lượt nào để gửi: để đường cũ xử lý, đừng gửi một lượt rỗng ra nhà cung cấp.
            return null;
        }

        $agent = new RegistryAgent($instructions, $history, $this->providerOptions($candidate, $options));

        try {
            $response = $agent->prompt(
                $prompt,
                [],
                provider: [$name => (string) $candidate['model']],
                timeout: max(1, (int) ($options['timeout'] ?? 90)),
            );
        } catch (\Throwable $e) {
            // KHÔNG nuốt lỗi: AiModelGateway cần biết lượt này hỏng để ghi nhật ký thử và chuyển candidate
            // kế tiếp — failover của SDK chỉ phủ 4 loại lỗi, còn 400/401/403/404 thì ném thẳng ra đây.
            throw $e;
        }

        $text = trim((string) $response->text);

        return [
            'text' => $text,
            // SDK không phơi finish_reason ở tầng AgentResponse; lấy best-effort từ bước cuối. Đây là thông
            // tin CHẨN ĐOÁN (ghi nhật ký), không phải điều kiện rẽ nhánh — thiếu nó không làm sai lượt chạy.
            'finish_reason' => $this->finishReasonOf($response),
            // Model suy luận có thể trả content rỗng: coi đó là "chỉ có phần nghĩ" để tầng gọi biết mà thử
            // lại với ngân sách lớn hơn, thay vì tưởng model trả lời sai.
            'reasoning_only' => $text === '',
        ];
    }

    /**
     * Tách danh sách message thành (chỉ dẫn · lịch sử · lượt sắp gửi) theo đúng cách SDK hiểu.
     *
     * SDK tách phần CHỈ DẪN khỏi phần ĐẦU VÀO — gộp lại thì mất ranh giới giữa "luật của hệ thống" và "dữ
     * liệu người dùng", đúng ranh giới mà lớp chống prompt-injection dựa vào. Vì vậy message role=system đi
     * vào instructions, các lượt còn lại đi vào lịch sử, và lượt CUỐI thành prompt.
     *
     * @param  list<array{role:string, content:mixed}>  $messages
     * @return array{0:string, 1:list<Message>, 2:string}
     */
    private function splitMessages(array $messages): array
    {
        $instructions = [];
        $turns = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                // Nội dung dạng mảng bộ phận (multimodal): chỉ lấy phần chữ — ảnh đi đường vision riêng.
                $content = implode('\n', array_map(
                    fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part,
                    $content,
                ));
            }
            $content = trim((string) $content);

            if ($role === 'system') {
                if ($content !== '') {
                    $instructions[] = $content;
                }

                continue;
            }

            $turns[] = ['role' => $role, 'content' => $content];
        }

        $last = array_pop($turns);
        $history = [];
        foreach ($turns as $turn) {
            try {
                $history[] = new Message($turn['role'], $turn['content']);
            } catch (\Throwable) {
                // Vai trò SDK không nhận (vd 'tool'): bỏ khỏi LỊCH SỬ chứ không làm hỏng lượt gọi. Đường
                // cần vai trò 'tool' là đường công cụ, và đường đó không đi qua động cơ này.
            }
        }

        return [implode("\n\n", $instructions), $history, (string) ($last['content'] ?? '')];
    }

    /**
     * Tuỳ chọn theo nhà cung cấp — chỉ những thứ ĐÃ ĐO ĐƯỢC là cần thiết.
     *
     * @return array<string, mixed>
     */
    private function providerOptions(array $candidate, array $options): array
    {
        $out = [];

        // JSON: SDK chỉ tự gắn response_format khi agent khai schema (HasStructuredOutput). Đường này chở
        // prompt tự do nên phải tự gắn — và PHẢI là json_object: json_schema bị DeepSeek trả HTTP 400
        // "This response_format type is unavailable now" (đo thật 2026-09-22).
        if (($options['response_format'] ?? '') === 'json_object') {
            $out['response_format'] = ['type' => 'json_object'];
        }

        // TẮT SUY LUẬN DÀI cho họ Qwen3: model "suy luận" tính CẢ token nghĩ vào max_tokens nên viết rất
        // dài rồi bị cắt (finish_reason=length) và KHÔNG BAO GIỜ viết ra JSON. Cùng luật với
        // AiModelGateway::applyThinkingOff để hai động cơ không hành xử khác nhau.
        if (($options['disable_thinking'] ?? false) === true) {
            $model = mb_strtolower((string) ($candidate['model'] ?? ''));
            $hybrid = str_contains($model, 'qwen3') || str_contains($model, 'thinking') || str_contains($model, 'reasoner');
            if ($hybrid) {
                $out['enable_thinking'] = false;
            }
        }

        if (! empty($options['max_tokens'])) {
            $out['max_tokens'] = (int) $options['max_tokens'];
        }

        return $out;
    }

    /** finish_reason best-effort từ bước cuối của SDK — chỉ để ghi nhật ký chẩn đoán. */
    private function finishReasonOf(object $response): ?string
    {
        try {
            $steps = $response->steps ?? null;
            $last = $steps?->last();
            $reason = $last?->finishReason ?? null;

            return $reason === null ? null : (string) ($reason->value ?? $reason);
        } catch (\Throwable) {
            return null;
        }
    }
}
