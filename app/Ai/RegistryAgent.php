<?php

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * AGENT DỰNG TỪ DỮ LIỆU CỦA MỘT LƯỢT GỌI — để AiModelGateway chạy được trên Laravel AI SDK.
 *
 * Vì sao không dùng AnonymousAgent của SDK: lớp đó KHÔNG implements HasProviderOptions, mà dự án cần
 * gửi thêm tuỳ chọn theo nhà cung cấp — cụ thể là response_format json_object (SDK chỉ tự gắn khi agent
 * khai schema) và enable_thinking=false cho họ Qwen (model suy luận đốt hết ngân sách token vào phần
 * nghĩ rồi bị cắt, không bao giờ viết ra JSON — lỗi thật đã gây sự cố production).
 *
 * HỢP ĐỒNG: lớp này KHÔNG quyết định gì về nội dung. Nó chỉ chở (chỉ dẫn · lịch sử · tuỳ chọn) từ
 * AiModelGateway vào SDK. Mọi luật về prompt vẫn nằm ở nơi gọi — đúng như trước khi có SDK.
 */
class RegistryAgent implements Agent, Conversational, HasProviderOptions
{
    use Promptable;

    /**
     * @param  string  $instructions  phần CHỈ DẪN (gộp từ các message role=system)
     * @param  list<\Laravel\Ai\Messages\Message>  $history  các lượt trước, KHÔNG gồm lượt sắp gửi
     * @param  array<string, mixed>  $providerOptions  tuỳ chọn riêng theo nhà cung cấp
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $history = [],
        private readonly array $providerOptions = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->providerOptions;
    }
}
