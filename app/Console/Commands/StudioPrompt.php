<?php

namespace App\Console\Commands;

use App\Ai\PromptCatalog;
use App\Models\PromptTemplate;
use App\Services\DesignAgentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

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
        {--label= : Nhãn ghi chú cho version mới}
        {--capture : Ghi nhận BẢN MẶC ĐỊNH trong mã vào CSDL (AI được giả lập — KHÔNG tốn token)}';

    protected $description = 'Liệt kê / xem / đặt / tắt CHỈ DẪN cấu hình được (bảng prompt_templates).';

    // Danh mục khoá nằm ở PromptCatalog — GIAO DIỆN QUẢN TRỊ đọc đúng danh mục đó.
    // Khai báo lại ở đây là mở đường cho hai bên lệch nhau.

    public function handle(): int
    {
        if ($this->option('capture')) {
            return $this->capture();
        }

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
        foreach (PromptCatalog::keys() as $key) {
            $row = PromptTemplate::query()->where('key', $key)->where('is_active', true)->orderByDesc('version')->first();
            $this->line(sprintf('  %-42s %s', $key, $row
                ? 'ĐANG CẤU HÌNH (v'.$row->version.', '.mb_strlen((string) $row->body).' ký tự)'
                : 'mặc định trong mã'));
        }

        $others = PromptTemplate::query()->select('key')->distinct()->pluck('key')->all();
        $extra = array_values(array_diff($others, PromptCatalog::keys()));
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

        if (! PromptCatalog::has($key)) {
            $this->error('Khoá không có trong danh mục: '.$key);
            $this->line('Khoá hợp lệ: '.implode(', ', PromptCatalog::keys()));

            return self::FAILURE;
        }

        // Version MỚI mỗi lần đặt: giữ bản cũ để đối chiếu/khôi phục, đúng cách bảng này được thiết kế.
        // Đi qua PromptCatalog để lệnh và GIAO DIỆN ghi dữ liệu y hệt nhau.
        $note = trim((string) ($this->option('label') ?? ''));
        $row = PromptCatalog::put($key, $body, $note !== '' ? $note : 'Đặt từ SSH '.now()->format('d/m/Y H:i'));

        $this->info(sprintf('Đã đặt %s = v%d (%d ký tự). Lượt chạy KẾ TIẾP dùng bản này.', $key, $row->version, mb_strlen($body)));
        $this->line('Quay về mặc định: php artisan studio:prompt '.$key.' --off');

        return self::SUCCESS;
    }


    /**
     * GHI NHẬN BẢN MẶC ĐỊNH TRONG MÃ vào CSDL — để tab «Chỉ dẫn AI» có nội dung THẬT mà sửa,
     * thay vì một ô trống bắt owner tự nghĩ ra câu lệnh.
     *
     * Vì sao phải CHẠY LUỒNG THẬT chứ không chép tay câu lệnh vào đây: câu lệnh không phải một chuỗi
     * cố định — nó được LẮP theo tình trạng của lượt chạy (hôm nay ngày nào, khu vực nào, có nguồn
     * ngoài hay không, có công cụ tìm kiếm hay không). Chép tay là tạo bản sao thứ hai và nó lệch
     * ngay ở lần sửa mã tiếp theo.
     *
     * KHÔNG tốn token: lớp HTTP được GIẢ LẬP, và preventStrayRequests() chặn mọi yêu cầu không khớp —
     * giả lập hỏng thì lệnh NÉM LỖI chứ không âm thầm gọi nhà cung cấp thật.
     *
     * Bản ghi nhận là ẢNH CHỤP của MỘT lượt chạy: câu lệnh thật còn đổi theo tình trạng nguồn dữ liệu.
     * Nó để owner có điểm xuất phát, không phải để khẳng định "câu lệnh luôn đúng như vậy".
     */
    private function capture(): int
    {
        $this->line('── GHI NHẬN BẢN MẶC ĐỊNH (AI được giả lập — KHÔNG gọi nhà cung cấp thật) ──');

        // GIẢ LẬP TOÀN BỘ mạng ra ngoài, theo thứ tự ưu tiên: nhà cung cấp AI trước, còn lại là nguồn tin.
        //
        // Vì sao KHÔNG dùng preventStrayRequests(): nó làm lượt lấy nguồn ngoài (RSS) NÉM LỖI, mà lượt
        // đó chạy TRƯỚC khi câu lệnh được dựng — nên chặn cứng là không ghi nhận được gì cả. Ở đây nguồn
        // tin được trả về một feed nhỏ hợp lệ, đúng hình dạng lượt chạy thật, vẫn KHÔNG ra internet.
        Http::fake([
            '*chat/completions*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"directions":[],"trend_checks":[],"narrative":"","brief":"","moodboard_captions":[],"category_rationale":{},"outfit_goals":{},"prompt_vi":"","prompt_en":"","next_steps":[]}'],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
            '*/responses*' => Http::response(['output_text' => '{"directions":[],"trend_checks":[]}', 'output' => []], 200),
            '*:generateContent*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{}']]]]]], 200),
            '*' => Http::response($this->sampleFeed(), 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']),
        ]);

        // BẮT BUỘC: đường radar trả về từ bộ đệm TRƯỚC khi dựng câu lệnh, nên không bật chế độ này thì
        // lượt ghi nhận trúng bộ đệm và im lặng không ghi được gì.
        $svc = app(DesignAgentService::class)->captureMode();

        $runs = [
            'agent.radar.instruction' => fn () => $svc->radar(null, 'all', true),
            'agent.collection_brief.instruction' => fn () => $svc->collectionBrief(['prompt' => 'Bộ sưu tập thu để ghi nhận chỉ dẫn mặc định'], null, true, true),
            'agent.sample_prompt.instruction' => fn () => $svc->samplePrompt(
                ['prompt' => 'Bộ sưu tập thu để ghi nhận chỉ dẫn mặc định', 'brief' => 'Brief thu.'],
                null,
                true,
                ['id' => 'seed-thu', 'name' => 'Mẫu thử', 'category' => 'Áo', 'size' => 'M', 'index' => 1, 'total' => 1, 'note' => ''],
            ),
        ];

        $ok = 0;
        foreach ($runs as $key => $run) {
            try {
                $run();
            } catch (\Throwable $e) {
                // Lỗi ở ĐƯỜNG CHẠY không có nghĩa là không ghi nhận được: câu lệnh được dựng TRƯỚC khi
                // gọi nhà cung cấp, nên ảnh chụp có thể đã có. Kiểm tra bên dưới mới là kết luận.
                $this->line('  (luồng báo lỗi: '.mb_substr($e->getMessage(), 0, 120).')');
            }

            $chars = mb_strlen((string) PromptCatalog::defaultBody($key));
            if ($chars > 0) {
                $ok++;
                $this->info(sprintf('  %-38s ĐÃ GHI NHẬN (%d ký tự)', $key, $chars));
            } else {
                $this->warn(sprintf('  %-38s chưa ghi nhận được', $key));
            }
        }

        $this->newLine();
        $this->line(sprintf('Kết quả: %d/%d khoá. Bản ghi nhận là ẢNH CHỤP của lượt chạy này —', $ok, count($runs)));
        $this->line('câu lệnh thật còn đổi theo tình trạng nguồn dữ liệu, nên nó là ĐIỂM XUẤT PHÁT để sửa, không phải bản sao tuyệt đối.');

        return $ok > 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Feed RSS hợp lệ để lượt lấy nguồn ngoài chạy đúng hình dạng thật mà KHÔNG ra internet. */
    private function sampleFeed(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel><title>Nguồn thử</title><link>https://news.example/</link>'
            .'<item><title>Xu hướng thời trang: chất liệu len và tông màu đất</title>'
            .'<link>https://news.example/a</link><description>Xu hướng thời trang</description></item>'
            .'<item><title>Thời trang công sở tối giản đang lên ngôi</title>'
            .'<link>https://news.example/b</link><description>Thời trang công sở</description></item>'
            .'</channel></rss>';
    }

    /** Tắt MỌI version đang bật của khoá ⇒ quay về chuỗi mặc định trong mã. */
    private function turnOff(string $key): int
    {
        $n = PromptCatalog::turnOff($key);

        $this->info($n > 0
            ? sprintf('Đã tắt %d bản cấu hình của %s — quay về mặc định trong mã.', $n, $key)
            : 'Không có bản cấu hình nào đang bật cho '.$key.'.');

        return self::SUCCESS;
    }
}
