<?php

namespace App\Console\Commands;

use App\Ai\SdkProviderMap;
use App\Services\AiModelGateway;
use App\Services\DesignAgentService;
use App\Services\WebAccessService;
use Illuminate\Console\Command;

/**
 * KIỂM TRA KHẢ NĂNG TRUY CẬP INTERNET CỦA AGENT — chạy được từ SSH (Đợt 22 — 2026-09-23).
 *
 * Vì sao cần lệnh riêng: câu hỏi "agent có ra được internet không?" phải trả lời được ngay trên máy
 * chủ thật, không phụ thuộc giao diện và không phải mở trình duyệt. Lệnh đo HAI tầng tách bạch:
 * máy chủ có gọi ra ngoài được không, và model đang cấu hình có tìm kiếm tích hợp hay không.
 *
 * Dùng:  php artisan studio:web-access          # dùng cache 10 phút
 *        php artisan studio:web-access --force  # đo lại ngay
 */
class WebAccessCheck extends Command
{
    protected $signature = 'studio:web-access {--force : Đo lại ngay, bỏ qua cache}';

    protected $description = 'Đo khả năng truy cập internet của máy chủ + khả năng tìm kiếm của model đang cấu hình.';

    public function handle(WebAccessService $web): int
    {
        $result = $web->probe((bool) $this->option('force'));

        $this->line('── TẦNG MÁY CHỦ ──');
        foreach ($result['outbound']['results'] as $row) {
            $this->line(sprintf(
                '  %s %s — %s (%d ms)%s',
                $row['ok'] ? '[OK] ' : '[LỖI]',
                $row['url'],
                $row['status'] !== null ? 'HTTP '.$row['status'] : 'không kết nối được',
                $row['ms'],
                isset($row['error']) ? ' · '.$row['error'] : '',
            ));
        }
        $this->line('  Kết luận: '.($result['outbound']['ok'] ? 'CÓ internet' : 'KHÔNG ra được internet'));

        $this->line('── TẦNG MODEL ──');
        if ($result['model_search']['candidates'] === []) {
            $this->line('  Chưa cấu hình model nào cho nhóm công việc của agent.');
        }
        foreach ($result['model_search']['candidates'] as $row) {
            // BA trạng thái, không phải hai (2026-09-24): nhãn cũ chỉ có "có/không tìm kiếm" theo khả năng
            // TÍCH HỢP của nhà cung cấp ⇒ in ra "[không tìm kiếm]" ngay cạnh dòng nói máy chủ chạy được công
            // cụ — hai câu ngược nhau trên cùng một màn hình.
            $badge = match (true) {
                // Đường /responses là endpoint RIÊNG của nhà cung cấp — không phải "tìm kiếm sẵn trong
                // /chat/completions", nên nhãn phải khác; gộp lại là nói sai cơ chế ngay trên màn hình
                // dùng để kiểm chứng.
                WebAccessService::isHostedMode($row['plan'] ?? null) => '[CÔNG CỤ NCC]   ',
                (bool) ($row['supported'] ?? false) => '[CÓ tìm kiếm sẵn]',
                (bool) ($row['tool_ready'] ?? false) => '[CÔNG CỤ]       ',
                (bool) ($row['tool'] ?? false) => '[chưa gán vai]  ',
                default => '[không tìm kiếm]',
            };
            $this->line(sprintf('  %s %s — %s', $badge, $row['provider'].':'.$row['model'], $row['label']));
        }

        // LÀN CÔNG CỤ CỦA LARAVEL AI SDK — in RÕ vì đây là câu hỏi lặp lại: "sao không dùng WebSearch /
        // WebFetch của SDK?". Hai công cụ đó là ProviderTool (NHÀ CUNG CẤP tự chạy, SDK chỉ gửi khai báo),
        // và SDK v0.11.2 chỉ map được cho OpenAI · Azure · Anthropic · Gemini · xAI · OpenRouter —
        // OpenAiCompatibleProvider và DeepSeek KHÔNG nằm trong danh sách. Xem ghi chú đã đo ở
        // App\Ai\RegistryProviders. In ra đây để không phải suy đoán lại lần sau.
        $this->newLine();
        $this->line('── LÀN CÔNG CỤ CỦA LARAVEL AI SDK (WebSearch · WebFetch) ──');
        $this->line('  '.$this->sdkLaneReport());

        $this->newLine();
        $this->line($result['verdict_label']);

        return self::SUCCESS;
    }

    /**
     * Nhà cung cấp đang cấu hình có nằm trong nhóm mà SDK map được công cụ tìm kiếm/đọc trang không?
     *
     * Đọc từ CÙNG nguồn mà đường chạy thật đọc (AiModelGateway::candidates + SdkProviderMap::driverFor), không
     * tự suy ra từ tên provider — hai nơi hiểu khác nhau là đúng kiểu lệch mà lớp này sinh ra để chặn.
     */
    private function sdkLaneReport(): string
    {
        $drivers = [];
        $gateway = app(AiModelGateway::class);
        foreach ([DesignAgentService::SEARCH_GROUP, DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP] as $group) {
            foreach ($gateway->candidates($group) as $candidate) {
                $drivers[] = SdkProviderMap::driverFor($candidate);
            }
        }
        $drivers = array_values(array_unique($drivers));

        if ($drivers === []) {
            return 'Chưa cấu hình nhà cung cấp nào ⇒ chưa áp dụng được làn nào.';
        }

        // Sáu driver SDK CÓ hỗ trợ (Contracts/Providers/SupportsWebSearch · SupportsWebFetch).
        $supported = array_values(array_intersect($drivers, ['openai', 'azure', 'anthropic', 'gemini', 'xai', 'openrouter']));
        if ($supported !== []) {
            return 'CÓ nhà cung cấp mà SDK map được công cụ của nhà cung cấp ('.implode(', ', $supported).') ⇒ có thể bật WebSearch/WebFetch của SDK cho nhà cung cấp đó.';
        }

        return 'KHÔNG áp dụng: nhà cung cấp đang cấu hình đi driver "'.implode(', ', $drivers).'" — SDK v0.11.2 không map WebSearch/WebFetch cho driver này. '
            .'Tìm kiếm web vẫn chạy ở đường tự viết: công cụ web_search do MÁY CHỦ chạy (mọi model biết gọi hàm) + làn /responses khi nhà cung cấp có công cụ.';
    }
}
