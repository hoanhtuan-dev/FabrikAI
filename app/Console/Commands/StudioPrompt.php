<?php

namespace App\Console\Commands;

use App\Models\PromptTemplate;
use Illuminate\Console\Command;

/**
 * QUẢN LÝ CHỈ DẪN (PROMPT) TỪ SSH — để "prompt linh hoạt" DÙNG ĐƯỢC, không chỉ tồn tại trên giấy (2026-09-22).
 *
 * Vì sao cần lệnh: lớp cấu hình hoá chỉ dẫn đọc bảng prompt_templates, nhưng nếu không có đường nào ĐẶT
 * giá trị thì tính năng chỉ là lý thuyết. Lệnh này là đường đó — chạy được từ SSH, không cần giao diện.
 *
 * Dùng:
 *   php artisan studio:prompt                       # liệt kê chỉ dẫn đang cấu hình
 *   php artisan studio:prompt agent.radar.instruction          # xem một khoá
 *   php artisan studio:prompt agent.radar.instruction --set-file=/tmp/p.txt   # đặt từ TỆP
 *   php artisan studio:prompt agent.radar.instruction --off    # quay về mặc định trong mã
 *
 * Vì sao ĐẶT TỪ TỆP chứ không phải tham số dòng lệnh: chỉ dẫn radar dài hơn 3.000 ký tự, nhiều dòng, và
 * chứa dấu ngoặc kép — nhét vào tham số shell là bảo đảm sai lệch mà không ai thấy.
 */
class StudioPrompt extends Command
{
    protected $signature = 'studio:prompt
        {key? : Khoá chỉ dẫn (vd agent.radar.instruction)}
        {--set-file= : Đặt nội dung từ TỆP này (tạo version mới, bật lên)}
        {--off : Tắt bản cấu hình của khoá này (quay về chuỗi mặc định trong mã)}
        {--label= : Nhãn ghi chú cho version mới}';

    protected $description = 'Liệt kê / xem / đặt / tắt CHỈ DẪN cấu hình được (bảng prompt_templates).';

    /** Các khoá Agent Studio đi qua mốc cấu hình. */
    private const AGENT_KEYS = [
        'agent.radar.instruction',
        'agent.collection_brief.instruction',
        'agent.sample_prompt.instruction',
    ];

    public function handle(): int
    {
        $key = (string) ($this->argument('key') ?? '');

        if ($key === '') {
            return $this->listAll();
        }

        if ($this->option('off')) {
            return $this->turnOff($key);
        }

        $file = (string) ($this->option('set-file') ?? '');
        if ($file !== '') {
            return $this->setFromFile($key, $file);
        }

        return $this->show($key);
    }

    private function listAll(): int
    {
        $this->line('── CHỈ DẪN AGENT STUDIO ĐI QUA MỐC CẤU HÌNH ──');
        foreach (self::AGENT_KEYS as $key) {
            $row = PromptTemplate::query()->where('key', $key)->where('is_active', true)->orderByDesc('version')->first();
            $this->line(sprintf('  %-42s %s', $key, $row
                ? 'ĐANG CẤU HÌNH (v'.$row->version.', '.mb_strlen((string) $row->body).' ký tự)'
                : 'mặc định trong mã'));
        }

        $others = PromptTemplate::query()->select('key')->distinct()->pluck('key')->all();
        $extra = array_values(array_diff($others, self::AGENT_KEYS));
        if ($extra !== []) {
            $this->newLine();
            $this->line('  Khoá khác đang có trong bảng: '.implode(', ', $extra));
        }

        return self::SUCCESS;
    }

    private function show(string $key): int
    {
        $rows = PromptTemplate::query()->where('key', $key)->orderByDesc('version')->get();

        if ($rows->isEmpty()) {
            $this->info('Chưa có bản cấu hình nào cho '.$key.' — hệ thống đang dùng chuỗi mặc định trong mã.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line(sprintf('v%d · %s · %d ký tự', $row->version, $row->is_active ? 'BẬT' : 'tắt', mb_strlen((string) $row->body)));
            $this->line((string) $row->body);
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function setFromFile(string $key, string $file): int
    {
        if (! is_file($file)) {
            $this->error('Không thấy tệp: '.$file);

            return self::FAILURE;
        }

        $body = (string) file_get_contents($file);
        if (trim($body) === '') {
            $this->error('Tệp rỗng — từ chối đặt chỉ dẫn rỗng (nó sẽ làm lượt chạy mất hết chỉ dẫn).');

            return self::FAILURE;
        }

        // Version MỚI mỗi lần đặt: giữ bản cũ để đối chiếu/khôi phục, đúng cách bảng này được thiết kế.
        $next = (int) PromptTemplate::query()->where('key', $key)->max('version') + 1;

        PromptTemplate::create([
            'key' => $key,
            'body' => $body,
            'version' => $next,
            'is_active' => true,
            'label' => (string) ($this->option('label') ?? 'đặt từ SSH '.now()->format('d/m/Y H:i')),
        ]);

        $this->info(sprintf('Đã đặt %s = v%d (%d ký tự). Lượt chạy KẾ TIẾP dùng bản này.', $key, $next, mb_strlen($body)));
        $this->line('Quay về mặc định: php artisan studio:prompt '.$key.' --off');

        return self::SUCCESS;
    }

    /** Tắt MỌI version đang bật của khoá ⇒ quay về chuỗi mặc định trong mã. */
    private function turnOff(string $key): int
    {
        $n = PromptTemplate::query()->where('key', $key)->where('is_active', true)->update(['is_active' => false]);

        $this->info($n > 0
            ? sprintf('Đã tắt %d bản cấu hình của %s — quay về mặc định trong mã.', $n, $key)
            : 'Không có bản cấu hình nào đang bật cho '.$key.'.');

        return self::SUCCESS;
    }
}
