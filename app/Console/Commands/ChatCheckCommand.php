<?php

namespace App\Console\Commands;

use App\Services\AgentChatService;
use App\Services\DesignAgentService;
use Illuminate\Console\Command;

/**
 * ĐO ĐƯỜNG CHAT THEO LUỒNG ngay trên máy chủ (2026-09-26).
 *
 * Vì sao cần lệnh riêng: "chat mượt" là một lời KHẲNG ĐỊNH về ĐỘ TRỄ, mà độ trễ thì chỉ đo được bằng cách
 * gọi thật. Ba thứ có thể hỏng ÂM THẦM nếu không đo:
 *   1. nhà cung cấp BỎ QUA \`stream: true\` ⇒ chữ về một cục (giao diện vẫn chạy, nhưng "mượt" là giả);
 *   2. proxy đệm phản hồi ⇒ mảnh đầu tiên về cùng lúc với mảnh cuối;
 *   3. model không gọi được công cụ ⇒ chat trả lời bằng trí nhớ thay vì tra thật.
 * Lệnh này in MỐC THỜI GIAN THẬT của từng sự kiện đầu tiên, nên câu trả lời là số đo chứ không phải suy đoán.
 *
 * Dùng:
 *   php artisan studio:chat-check              # in cấu hình nhóm công việc của chat (không gọi mạng)
 *   php artisan studio:chat-check --live       # gọi THẬT một lượt chat ngắn và đo từng mốc
 *   php artisan studio:chat-check --live --ask="câu hỏi khác"
 */
class ChatCheckCommand extends Command
{
    protected $signature = 'studio:chat-check
        {--live : Gọi THẬT một lượt chat ngắn (tốn token) để đo đường luồng}
        {--ask=Xu hướng áo dạ tweed mùa thu này thế nào? : Câu hỏi dùng cho lượt đo}
        {--show : In luôn phần đầu CÂU TRẢ LỜI (để kiểm nội dung, không chỉ số đo)}
        {--region=all : Vùng dữ liệu (all|hcm|hanoi|danang)}';

    protected $description = 'Đo đường CHAT THEO LUỒNG của Agent Studio: chữ có chảy thật không, mảnh đầu về sau bao lâu, công cụ có chạy không.';

    public function handle(AgentChatService $chat): int
    {
        $this->line('── CẤU HÌNH ──');
        $gateway = app(\App\Services\AiModelGateway::class);
        foreach ([DesignAgentService::SEARCH_GROUP, DesignAgentService::REASON_GROUP] as $group) {
            $this->line(sprintf('  %-14s %s', $group, $gateway->has($group) ? 'CÓ model' : 'chưa gán model'));
        }
        $this->line('  Chat dùng: '.AgentChatService::CHAT_GROUP
            .' (hoặc vai tìm kiếm nếu có) · trần '.AgentChatService::CEILING_SECONDS.' s · '.AgentChatService::MAX_TOKENS.' token');

        if (! $this->option('live')) {
            $this->line('  (Thêm --live để gọi thật một lượt ngắn và đo từng mốc thời gian.)');

            return self::SUCCESS;
        }

        $question = trim((string) $this->option('ask'));
        $started = microtime(true);
        $milestones = [];
        $chunks = 0;

        $this->newLine();
        $this->line('── GỌI THẬT ──');
        $this->line('  hỏi: '.$question);

        $result = $chat->chat(
            ['messages' => [['role' => 'user', 'content' => $question]]],
            null,
            function (array $event) use (&$milestones, &$chunks, $started): void {
                $type = (string) ($event['type'] ?? '');
                $ms = (int) round((microtime(true) - $started) * 1000);

                if ($type === 'token') {
                    $chunks++;
                    if ($chunks === 1) {
                        $milestones['token_1'] = $ms;
                    }

                    return;
                }

                if ($type === 'phase') {
                    $milestones['phase_'.(string) ($event['key'] ?? '?')] = $ms;
                    $this->line(sprintf('  %6d ms  tiến trình: %s', $ms, (string) ($event['label'] ?? '')));

                    return;
                }

                if ($type === 'tool') {
                    $milestones['tool'] = $ms;
                    $this->line(sprintf('  %6d ms  CÔNG CỤ: %s %s', $ms, (string) ($event['name'] ?? ''), (string) ($event['query'] ?? '')));

                    return;
                }

                if ($type === 'tool_result') {
                    $milestones['tool_result'] = $ms;
                    $this->line(sprintf('  %6d ms  kết quả công cụ: %d nguồn (dùng lại %d, đọc trang %s)',
                        $ms, (int) ($event['found'] ?? 0), (int) ($event['reused'] ?? 0), ($event['chars'] ?? 0) > 0 ? 'có' : 'không'));

                    return;
                }

                if ($type === 'citation') {
                    $this->line(sprintf('  %6d ms  nguồn: %s', $ms, (string) ($event['url'] ?? '')));
                }
            },
            (string) $this->option('region'),
        );

        $this->newLine();
        $this->line('── SỐ ĐO ──');
        $this->line(sprintf('  mảnh chữ đầu tiên : %s', isset($milestones['token_1']) ? $milestones['token_1'].' ms' : 'KHÔNG có (chữ về một cục?)'));
        $this->line(sprintf('  tổng thời gian     : %d ms', (int) ($result['elapsed_ms'] ?? 0)));
        $this->line(sprintf('  số mảnh chữ        : %d', $chunks));
        $this->line(sprintf('  CHẢY THEO LUỒNG    : %s', ($result['streamed'] ?? false) ? 'CÓ' : 'KHÔNG — nhà cung cấp trả một cục'));
        $this->line(sprintf('  công cụ            : %d lượt · %d kết quả · dùng lại %d · lưu sổ %d',
            (int) ($result['tool_search']['calls'] ?? 0),
            (int) ($result['tool_search']['results'] ?? 0),
            (int) ($result['tool_search']['reused'] ?? 0),
            (int) ($result['tool_search']['stored'] ?? 0)));
        $this->line(sprintf('  trích dẫn          : %d', count((array) ($result['citations'] ?? []))));
        $this->line(sprintf('  độ dài trả lời     : %d ký tự', mb_strlen((string) ($result['text'] ?? ''))));

        // IN NỘI DUNG khi được yêu cầu: số đo cho biết máy đã làm gì, nhưng câu hỏi "nó có BỊA không" chỉ trả
        // lời được bằng chính câu trả lời — nhất là ca tra không ra kết quả nào.
        if ((bool) $this->option('show')) {
            $text = trim((string) ($result['text'] ?? ''));
            $this->newLine();
            $this->line('── CÂU TRẢ LỜI (đầu) ──');
            $this->line($text === '' ? '(rỗng)' : mb_substr($text, 0, 600));
        }

        if (($result['failed'] ?? false) === true) {
            $this->error('  Lượt chạy HỎNG: '.(string) ($result['message'] ?? ''));

            return self::FAILURE;
        }

        // Kết luận nói thẳng: chữ về một cục là HỎNG về mặt "mượt", không phải "chạy được là xong".
        if (($result['streamed'] ?? false) !== true) {
            $this->warn('  LƯU Ý: lượt này KHÔNG chảy chữ. Kiểm tra model/nhà cung cấp có hỗ trợ luồng, và proxy có đệm không.');

            return self::SUCCESS;
        }

        $this->info('  Đường luồng chạy thật: sự kiện đầu tiên đã về trước khi câu trả lời kết thúc.');

        return self::SUCCESS;
    }
}
