<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * AGENT VIẾT PROMPT ẢNH CHO MỘT MẪU (Laravel AI SDK, 2026-09-22).
 *
 * Đây là bước đầu tiên của Agent Studio chạy bằng SDK, chọn vì nó là bước TỰ CHỨA và AN TOÀN nhất:
 *   · đầu vào đã được chốt sẵn (mẫu · màu · mood · DNA shop) ⇒ không phụ thuộc tìm kiếm web;
 *   · đầu ra là bốn trường chữ, KHÔNG có con số nào do model quyết (giá/số lượng vẫn ở tầng dữ liệu);
 *   · đã có sẵn bản dựng tất định làm lưới an toàn ⇒ SDK hỏng thì người dùng vẫn có prompt dùng được.
 *
 * VÌ SAO KHAI SCHEMA: đường cũ yêu cầu model "trả về JSON đúng dạng" bằng lời dặn rồi tự cắt/đọc JSON,
 * kèm cả một tầng THỬ LẠI cho ca JSON bị cắt vì hết token. Khai schema thì ràng buộc hình dạng do nhà cung
 * cấp thi hành (khi họ hỗ trợ), và SDK trả về mảng đã đúng dạng — bớt hẳn một lớp lỗi đã gây sự cố thật.
 *
 * ⚠️ GIỚI HẠN ĐÃ ĐO TRÊN PRODUCTION 2026-09-22 — ĐỪNG BỎ QUA:
 *   · KHÔNG phải nhà cung cấp nào cũng nhận response_format json_schema. DeepSeek trả HTTP 400
 *     "This response_format type is unavailable now". Vì vậy providerOptions() hạ về json_object cho
 *     đường openai-compatible — cả DeepSeek lẫn DashScope/Qwen đều nhận json_object (đã đo: HTTP 200,
 *     đủ bốn trường). Đổi lại: hình dạng KHÔNG còn được nhà cung cấp ràng buộc, nên chính CHỈ DẪN phải
 *     nói rõ bốn trường, và nơi gọi vẫn phải kiểm hình dạng sau khi giải mã.
 *   · Agent này KHÔNG gọi công cụ tìm kiếm: SDK v0.11.2 không hỗ trợ WebSearch cho driver
 *     openai-compatible (xem App\Ai\RegistryProviders).
 */
class SamplePromptAgent implements Agent, HasStructuredOutput, HasProviderOptions
{
    use Promptable;

    /**
     * @param  array<string, mixed>  $context  dữ liệu đã chốt của mẫu (mô tả · DNA · màu · mood · mẫu)
     */
    public function __construct(private readonly array $context = []) {}

    public function instructions(): Stringable|string
    {
        return 'Bạn viết PROMPT ẢNH cho ĐÚNG MỘT mẫu trong bộ sưu tập thời trang. '
            .'Dữ liệu gồm: mô tả bộ sưu tập, DNA shop, bảng màu, bảng mood, và mẫu cần viết. '
            // Hình dạng phải nói RÕ trong chỉ dẫn: xem ghi chú json_object ở đầu lớp.
            .'Trả về DUY NHẤT một object JSON có ĐÚNG BỐN khoá sau, KHÔNG thêm khoá nào khác: '
            .'{"prompt_vi": "...", "prompt_en": "...", "negative_prompt": "...", "note": "..."}. '
            .'prompt_vi: 1 đoạn 2-3 câu tiếng Việt tả ĐÚNG mẫu này (nhóm hàng, dáng, chất liệu, chi tiết, '
            .'bối cảnh chụp, ánh sáng, tư thế). '
            .'prompt_en: bản tiếng Anh giàu chi tiết dùng được ngay cho công cụ tạo ảnh. '
            .'negative_prompt: những thứ cần tránh cho mẫu này (ngắn). '
            .'note: 1 câu vì sao mẫu này đáng làm trước. '
            .'KHÔNG bịa con số (giá, số lượng, tỉ lệ %). KHÔNG nhắc tên model hay nhà cung cấp. '
            .'Mỗi trường là MỘT dòng, dùng dấu chấm để ngắt câu. Trả JSON ngay, không viết suy luận dài dòng.';
    }

    /**
     * Hình dạng đầu ra — dùng để SDK biết lượt gọi này cần dữ liệu có cấu trúc và tự giải mã phần chữ.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt_vi' => $schema->string()->required(),
            'prompt_en' => $schema->string()->required(),
            'negative_prompt' => $schema->string()->required(),
            'note' => $schema->string()->required(),
        ];
    }

    /**
     * TUỲ CHỈNH THEO NHÀ CUNG CẤP — hiện chỉ để HẠ response_format cho đường openai-compatible.
     *
     * Lý do (đo thật, không phải phòng xa): SDK dựng json_schema cho mọi agent có HasStructuredOutput
     * (xem Gateway/OpenAiCompatible/Concerns/BuildsTextRequests::buildResponseFormat), nhưng DeepSeek
     * trả HTTP 400 "This response_format type is unavailable now". json_object thì cả DeepSeek lẫn
     * DashScope/Qwen đều nhận và vẫn trả đủ bốn trường.
     *
     * Chỉ hạ cho openai-compatible: Gemini đi đường riêng và có cách khai schema của chính nó, hạ ở đó
     * là làm mất ràng buộc mà không được lợi gì.
     *
     * GIỚI HẠN CỦA SDK ĐÃ ĐO: providerOptions() chỉ được SDK TRỘN vào thân request; nó không gỡ được
     * lỗi mà nhà cung cấp trả về. Và failover của SDK chỉ chuyển tiếp với 4 loại lỗi (quá tải · mất kết
     * nối · giới hạn nhịp · hết credit) — 400 như trên NÉM THẲNG RA NGOÀI. Nên nơi gọi vẫn phải tự thử
     * nhà cung cấp kế tiếp nếu muốn hành vi "lỗi nào cũng thử tiếp" như AiModelGateway đang làm.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = (string) config('ai.providers.'.(\is_string($provider) ? $provider : $provider->value).'.driver', '');

        return $driver === 'openai-compatible'
            ? ['response_format' => ['type' => 'json_object']]
            : [];
    }

    /** Ngữ cảnh đã chốt, đưa vào lời gọi dưới dạng JSON để prompt ngắn và ổn định. */
    public function context(): array
    {
        return $this->context;
    }
}
