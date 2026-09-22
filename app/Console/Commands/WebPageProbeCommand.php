<?php

namespace App\Console\Commands;

use App\Services\WebSourceService;
use Illuminate\Console\Command;

/**
 * THỬ MỘT ĐỊA CHỈ WEB BÌNH THƯỜNG trước khi khai thành nguồn (2026-09-26).
 *
 * Vì sao cần lệnh này: loại nguồn `page` (địa chỉ web thường thay vì RSS) chỉ đáng tin khi bộ bóc lấy ĐÚNG
 * vùng danh sách bài — mà điều đó phụ thuộc giao diện TỪNG site. Đo thật trên production:
 *   · tuoitre.vn/thoi-trang.htm  → 200, bóc ra liên kết điều hướng ("Tuổi Trẻ Start-Up Award"…), KHÔNG phải bài;
 *   · eva.vn/thoi-trang-c13.html → 200, 10/10 mục đầu là bài NUÔI CON (khối "đọc nhiều" toàn site);
 *   · vnexpress.net/thoi-trang   → HTTP 406, chặn bot.
 * Nghĩa là: cùng một hàm bóc, site này dùng được còn site kia thì KHÔNG. Nút "Lấy thử" trong Cài đặt chỉ thử
 * được nguồn ĐÃ lưu, nên cần đường thử ỨNG VIÊN — và cần một câu kết luận nói thẳng kết quả có đáng tin không.
 *
 * Dùng:
 *   php artisan studio:web-page-probe --url=https://eva.vn/thoi-trang-c13.html
 *   php artisan studio:web-page-probe --url=… --prefix=/thoi-trang-c13/
 */
class WebPageProbeCommand extends Command
{
    protected $signature = 'studio:web-page-probe
        {--url= : Địa chỉ TRANG cần thử (trang chuyên mục có danh sách bài)}
        {--prefix= : Tiền tố đường dẫn BẮT BUỘC của bài viết (vd /thoi-trang-c13/) — khai càng hẹp càng ít rác}
        {--limit=10 : Số mục tối đa cần bóc}';

    protected $description = 'Thử bóc một địa chỉ web bình thường (nguồn kind=page) TRƯỚC khi khai vào Cài đặt.';

    public function handle(WebSourceService $sources): int
    {
        $url = trim((string) $this->option('url'));
        if ($url === '') {
            $this->error('Thiếu --url=… (địa chỉ trang chuyên mục cần thử).');

            return self::FAILURE;
        }

        $prefix = trim((string) $this->option('prefix'));
        $limit = max(1, min(50, (int) $this->option('limit')));

        $result = $sources->previewUrl($url, $prefix, $limit);

        $this->line('── THỬ BÓC ──');
        $this->line('  địa chỉ : '.$url);
        $this->line('  tiền tố : '.($prefix !== '' ? $prefix : '(không khai — dễ lấy nhầm menu/khối "đọc nhiều")'));
        $this->line('  kết quả : HTTP '.($result['http'] ?? '?').' · '.(int) ($result['ms'] ?? 0).' ms · '
            .count($result['items']).' mục'.($result['error'] ? ' · '.$result['error'] : ''));

        if ($result['items'] === []) {
            $this->newLine();
            $this->warn('  KHÔNG bóc được bài nào ⇒ ĐỪNG khai nguồn này.');
            $this->line('  Thử: một trang CHUYÊN MỤC khác (không phải trang chủ), hoặc khai --prefix=… hẹp hơn.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('── 5 MỤC ĐẦU (ĐỌC BẰNG MẮT TRƯỚC KHI KHAI) ──');
        foreach (array_slice($result['items'], 0, 5) as $item) {
            $this->line('  · '.mb_substr((string) $item['title'], 0, 90));
            $this->line('      '.mb_substr((string) $item['url'], 0, 100));
        }

        $this->newLine();
        // KẾT LUẬN NÓI THẲNG: đây là chỗ dễ tự lừa nhất — bộ bóc LUÔN ra "N mục", câu hỏi thật là chúng có
        // đúng chuyên mục không. Không ai kiểm hộ được ngoài mắt người khai.
        $this->line('KẾT LUẬN: máy bóc được '.count($result['items']).' mục — nhưng MÁY KHÔNG BIẾT chúng có đúng chuyên mục hay không.');
        $this->line('  · Nếu 5 tiêu đề trên ĐÚNG là bài của chuyên mục bạn muốn  → khai nguồn (kind = page) trong Cài đặt.');
        $this->line('  · Nếu chúng là menu, quảng cáo, hay bài của mục KHÁC        → ĐỪNG khai: agent sẽ đọc tin sai.');

        return self::SUCCESS;
    }
}
