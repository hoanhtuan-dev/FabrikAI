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

        $this->line('  Thứ tự trên khớp Cài đặt → Nhóm công việc. LƯU Ý về failover của SDK: nó CHỈ tự chuyển');
        $this->line('  sang nhà cung cấp kế tiếp với 4 loại lỗi (quá tải · mất kết nối · bị giới hạn nhịp · hết credit).');

        if (! $this->option('live')) {
            $this->line('  (Thêm --live để gọi thật một lượt nhỏ và chứng minh đường nối chạy được.)');

            return self::SUCCESS;
        }

        return $this->liveProbe($group, array_slice($chain, 1));
    }

    /**
     * Gọi THẬT một lượt nhỏ qua TỪNG nhà cung cấp — chứng minh đường nối và chỉ ra CHÍNH XÁC cái nào hỏng.
     *
     * Vì sao gọi từng cái một thay vì đưa cả bản đồ cho SDK tự failover: failover của SDK CHỈ chạy với bốn
     * loại lỗi (ProviderOverloaded · ProviderConnection · RateLimited · InsufficientCredits — xem
     * Laravel\Ai\Exceptions). Lỗi xác thực (401/403) và sai model (404) NÉM THẲNG RA NGOÀI, không chuyển
     * sang nhà cung cấp kế tiếp. Nghĩa là một khoá hỏng nằm ĐẦU danh sách sẽ chặn hết phần còn lại — đúng
     * tình trạng đang có trên production (khoá qwen trả 401). Nếu lệnh này đưa cả bản đồ cho SDK thì nó sẽ
     * báo "hỏng" mà KHÔNG nói được rằng các nhà cung cấp sau vẫn tốt.
     *
     * @param  list<string>  $fallbacks  nhóm dự phòng, y như lượt in cấu hình ở trên
     */
    private function liveProbe(string $group, array $fallbacks): int
    {
        $args = app(RegistryProviders::class)->failoverArgs($group, $fallbacks);

        $this->newLine();
        $this->line('── GỌI THẬT QUA SDK (từng nhà cung cấp một) ──');

        $ok = 0;
        $index = 0;
        foreach ($args['providers'] as $name => $model) {
            // Nhãn đọc theo CHỈ SỐ RIÊNG: dùng $ok làm chỉ số là lệch ngay khi có provider hỏng (vì $ok
            // chỉ tăng khi thành công) — nhãn sẽ trỏ nhầm nhà cung cấp.
            $label = $args['labels'][$index] ?? $name;
            $index++;
            $started = microtime(true);

            try {
                $response = (new SamplePromptAgent(['probe' => true]))->prompt(
                    'Đây là lượt thử đường nối. Trả về nội dung ngắn, đúng bốn trường của schema.',
                    [],
                    // MỘT provider mỗi lần: xem chú thích ở trên — gộp lại thì lỗi 401 ở đầu sẽ che hết
                    // những nhà cung cấp còn tốt. BẢN ĐỒ tên => model là hình dạng SDK yêu cầu.
                    provider: [$name => $model],
                    timeout: 60,
                );
            } catch (\Throwable $e) {
                $ms = (int) round((microtime(true) - $started) * 1000);
                $this->error(sprintf('  [HỎNG] %s — %s (%d ms)', $label, class_basename($e), $ms));
                $this->line('         '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160));

                continue;
            }

            $ms = (int) round((microtime(true) - $started) * 1000);
            $data = $response->structured ?? [];
            $this->info(sprintf('  [OK]   %s — %d ms', $label, $ms));
            $this->line(sprintf('         %s · prompt_vi: %s', $response->meta->model, mb_substr((string) ($data['prompt_vi'] ?? ''), 0, 60)));
            $ok++;
        }

        $this->newLine();
        if ($ok === 0) {
            $this->error('  KHÔNG nhà cung cấp nào trả lời được — SDK chưa dùng được cho nhóm này.');

            return self::FAILURE;
        }

        $this->info(sprintf('  %d/%d nhà cung cấp chạy được.', $ok, count($args['providers'])));

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
