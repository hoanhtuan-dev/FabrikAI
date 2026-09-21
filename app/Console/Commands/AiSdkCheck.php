<?php

namespace App\Console\Commands;

use App\Ai\Agents\SamplePromptAgent;
use App\Ai\RegistryProviders;
use App\Services\DesignAgentService;
use Illuminate\Console\Command;

/**
 * KIỂM TRA CẦU NỐI LARAVEL AI SDK ↔ MODEL REGISTRY (2026-09-22).
 *
 * Vì sao cần lệnh riêng: khi ghép SDK vào một hệ đã có phần quản trị riêng, chỗ hỏng LUÔN nằm ở ĐƯỜNG
 * NỐI chứ không ở SDK: sai driver, thiếu khoá, sai địa chỉ, hoặc thứ tự ưu tiên khác với Cài đặt. Những
 * lỗi đó chỉ lộ ra lúc chạy thật — tốn tiền và khó truy. Lệnh này in ra ĐÚNG thứ SDK sẽ dùng, TRƯỚC khi
 * gọi, và chỉ gọi thật khi được yêu cầu.
 *
 * Dùng:  php artisan studio:ai-check                       # xem cấu hình đã ánh xạ (không gọi mạng)
 *        php artisan studio:ai-check --group=agent_reason  # xem một nhóm công việc khác
 *        php artisan studio:ai-check --live                # gọi THẬT một lượt nhỏ để chứng minh
 */
class AiSdkCheck extends Command
{
    protected $signature = 'studio:ai-check
        {--group= : Nhóm công việc cần kiểm (mặc định: nhóm suy luận của Agent Studio)}
        {--live : Gọi THẬT một lượt nhỏ qua SDK (tốn token) thay vì chỉ in cấu hình}';

    protected $description = 'In ra nhà cung cấp mà Laravel AI SDK sẽ dùng cho một nhóm công việc, và (tuỳ chọn) gọi thử một lượt.';

    public function handle(RegistryProviders $bridge): int
    {
        $role = (string) ($this->option('group') ?: 'reason');
        $chain = $bridge->chainFor($role);
        $group = $chain[0];

        // Đi ĐÚNG chuỗi dự phòng như agent: nhóm vai bỏ trống là chuyện bình thường, và lệnh kiểm tra
        // không được báo "chưa cấu hình" cho một hệ vẫn chạy được nhờ nhóm nền.
        $rows = $bridge->forGroup($group, array_slice($chain, 1));

        $this->line('── NHÓM CÔNG VIỆC: '.implode(' → ', $chain).' ──');

        if ($rows === []) {
            $this->warn('  Không có nhà cung cấp nào dùng được cho nhóm này.');
            $this->line('  Đây là trạng thái CẤU HÌNH, không phải lỗi SDK: vào Cài đặt → Nhóm công việc để gán model,');
            $this->line('  và Cài đặt → Quản lý API để thêm khoá cho nhà cung cấp đó.');

            return self::FAILURE;
        }

        foreach ($rows as $i => $row) {
            $config = (array) config('ai.providers.'.$row['name']);
            $this->line(sprintf('  [%d] %s', $i, $row['label']));
            $this->line(sprintf('      driver : %s', $config['driver'] ?? '?'));
            $this->line(sprintf('      url    : %s', $config['url'] ?? '?'));
            // KHÔNG in khoá: đây là log/lịch sử terminal của máy chủ dùng chung.
            $this->line(sprintf('      key    : %s', $this->mask((string) ($config['key'] ?? ''))));
        }

        $this->line('  Thứ tự trên CHÍNH LÀ thứ tự failover của SDK (khớp Cài đặt → Nhóm công việc).');

        if (! $this->option('live')) {
            $this->line('  (Thêm --live để gọi thật một lượt nhỏ và chứng minh đường nối chạy được.)');

            return self::SUCCESS;
        }

        return $this->liveProbe($group, array_slice($chain, 1));
    }

    /**
     * Gọi THẬT một lượt nhỏ qua SDK — chứng minh đường nối, không chỉ in cấu hình.
     *
     * @param  list<string>  $fallbacks  nhóm dự phòng, y như lượt in cấu hình ở trên
     */
    private function liveProbe(string $group, array $fallbacks): int
    {
        $args = app(RegistryProviders::class)->failoverArgs($group, $fallbacks);

        $this->newLine();
        $this->line('── GỌI THẬT QUA SDK ──');

        $started = microtime(true);

        try {
            $response = (new SamplePromptAgent(['probe' => true]))->prompt(
                'Đây là lượt thử đường nối. Trả về nội dung ngắn, đúng bốn trường của schema.',
                [],
                provider: $args['providers'],
                model: $args['models'],
                timeout: 60,
            );
        } catch (\Throwable $e) {
            $this->error('  THẤT BẠI: '.class_basename($e).' — '.mb_substr($e->getMessage(), 0, 200));
            $this->line('  Lượt thử KHÔNG chạy được ⇒ SDK chưa dùng được cho nhóm này. Xem lại khoá/địa chỉ ở trên.');

            return self::FAILURE;
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $data = $response->structured ?? [];

        $this->info(sprintf('  THÀNH CÔNG trong %d ms', $ms));
        $this->line('  Nhà cung cấp đã trả lời: '.$response->meta->provider.' · '.$response->meta->model);
        foreach (['prompt_vi', 'prompt_en', 'negative_prompt', 'note'] as $field) {
            $value = (string) ($data[$field] ?? '');
            $this->line(sprintf('      %-16s %s', $field.':', mb_substr($value, 0, 80)));
        }

        return self::SUCCESS;
    }

    /** Che khoá khi in: chỉ giữ 4 ký tự đầu/cuối để còn đối chiếu được mà không phơi khoá. */
    private function mask(string $key): string
    {
        if ($key === '') {
            return '(không có)';
        }

        return mb_strlen($key) <= 10
            ? str_repeat('*', mb_strlen($key))
            : mb_substr($key, 0, 4).'…'.mb_substr($key, -4).' (dài '.mb_strlen($key).')';
    }
}
