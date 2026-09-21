<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * AGENT VIẾT PROMPT ẢNH CHO MỘT MẪU (Laravel AI SDK, 2026-09-22).
 *
 * Đây là bước đầu tiên của Agent Studio chạy bằng SDK, chọn vì nó là bước TỰ CHỨA và AN TOÀN nhất:
 *   · đầu vào đã được chốt sẵn (mẫu · bảng màu · bảng mood · DNA shop) ⇒ không phụ thuộc tìm kiếm web;
 *   · đầu ra là bốn trường chữ, KHÔNG có con số nào do model quyết (giá/số lượng vẫn ở tầng dữ liệu);
 *   · đã có sẵn bản dựng tất định làm lưới an toàn ⇒ SDK hỏng thì người dùng vẫn có prompt dùng được.
 *
 * VÌ SAO DÙNG HasStructuredOutput: đường cũ yêu cầu model "trả về JSON đúng dạng" bằng lời dặn, rồi tự
 * cắt/đọc JSON và có cả một tầng THỬ LẠI cho ca JSON bị cắt vì hết token. Khai SCHEMA thì ràng buộc đó do
 * nhà cung cấp thi hành, và SDK trả về mảng đã đúng hình dạng — bớt hẳn một lớp lỗi đã gây sự cố thật.
 *
 * GIỚI HẠN: agent này KHÔNG gọi công cụ tìm kiếm. SDK v0.11.2 không hỗ trợ WebSearch cho driver
 * openai-compatible (xem App\Ai\RegistryProviders) — tìm kiếm vẫn nằm ở đường tự viết của AiModelGateway.
 */
class SamplePromptAgent implements Agent, HasStructuredOutput
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
            .'prompt_vi: 1 đoạn 2-3 câu tiếng Việt tả ĐÚNG mẫu này (nhóm hàng, dáng, chất liệu, chi tiết, '
            .'bối cảnh chụp, ánh sáng, tư thế). '
            .'prompt_en: bản tiếng Anh giàu chi tiết dùng được ngay cho công cụ tạo ảnh. '
            .'negative_prompt: những thứ cần tránh cho mẫu này (ngắn). '
            .'note: 1 câu vì sao mẫu này đáng làm trước. '
            .'KHÔNG bịa con số (giá, số lượng, tỉ lệ %). KHÔNG nhắc tên model hay nhà cung cấp. '
            .'Mỗi trường là MỘT dòng, dùng dấu chấm để ngắt câu. Trả JSON ngay, không viết suy luận dài dòng.';
    }

    /**
     * Hình dạng đầu ra do NHÀ CUNG CẤP ràng buộc — không còn phụ thuộc việc model có chịu nghe lời dặn không.
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

    /** Ngữ cảnh đã chốt, đưa vào lời gọi dưới dạng JSON để prompt ngắn và ổn định. */
    public function context(): array
    {
        return $this->context;
    }
}
