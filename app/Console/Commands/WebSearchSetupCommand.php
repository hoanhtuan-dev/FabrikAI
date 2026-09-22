<?php

namespace App\Console\Commands;

use App\Models\StudioApiKey;
use App\Models\WebSource;
use App\Services\WebSourceService;
use Illuminate\Console\Command;

/**
 * KHAI API TÌM KIẾM CÓ KHOÁ (Google Programmable Search) BẰNG MỘT LỆNH (2026-09-26).
 *
 * Vì sao cần lệnh này thay vì "vào Cài đặt bấm 4 bước": đo trên production cho thấy nguồn RSS chỉ có TIN TỨC —
 * câu hỏi web chung ("cách giặt vải linen", "giá vải linen") trả về **0 kết quả** (đọc được 16-23 tin nhưng
 * đều quá cũ). Muốn tra được web chung thì phải có API tìm kiếm, mà API thì cần khoá. Bốn bước khai tay
 * (tạo engine · tạo API key · khai nguồn · ánh xạ 4 trường) là bốn chỗ để sai, và sai thì triệu chứng chỉ là
 * "0 kết quả" — đúng loại lỗi im lặng mà dự án này cấm. Lệnh này làm cả bốn bước, rồi **THỬ THẬT** một câu
 * hỏi và in ra kết quả.
 *
 * HAI quy tắc an toàn:
 *   · KHOÁ KHÔNG BAO GIỜ IN RA (chỉ in 4 ký tự cuối) và KHÔNG nằm trong URL của nguồn — khoá đi vào bảng
 *     `studio_api_keys` (đã mã hoá) và chỉ được gắn vào URL ở ĐÚNG lời gọi HTTP;
 *   · khoá nên được truyền qua THAM SỐ DÒNG LỆNH trên chính máy chủ, để nó không phải đi qua chat/email.
 *
 * Dùng:
 *   php artisan studio:web-search-setup --key=AIza… --cx=0123456789abcdef
 *   php artisan studio:web-search-setup --key=… --cx=… --fresh=30 --test="xu hướng vải linen 2026"
 */
class WebSearchSetupCommand extends Command
{
    protected $signature = 'studio:web-search-setup
        {--key= : API key của Google (Custom Search JSON API)}
        {--cx= : Search engine ID (cx) của engine đã tạo ở programmablesearchengine.google.com}
        {--slug=google-cse : Slug của nguồn — cũng là \"provider\" của khoá API trong bảng khoá}
        {--name=Google — tìm kiếm web chung : Tên hiển thị của nguồn}
        {--num=10 : Số kết quả mỗi truy vấn (1..10, đúng trần của Google CSE)}
        {--fresh= : Chỉ lấy kết quả trong N ngày gần đây (thêm dateRestrict=dN). Bỏ trống = không giới hạn}
        {--test=thời trang bền vững 2026 : Câu hỏi dùng để THỬ THẬT sau khi khai}';

    protected $description = 'Khai API tìm kiếm có khoá (Google CSE) làm nguồn web chung cho agent, rồi thử thật một câu hỏi.';

    public function handle(WebSourceService $sources): int
    {
        $key = trim((string) $this->option('key'));
        $cx = trim((string) $this->option('cx'));
        $slug = trim((string) $this->option('slug')) ?: 'google-cse';
        $num = max(1, min(10, (int) $this->option('num')));
        $fresh = trim((string) $this->option('fresh'));
        $freshDays = $fresh === '' ? null : max(1, min(365, (int) $fresh));

        if ($key === '' || $cx === '') {
            $this->error('Thiếu --key=… hoặc --cx=…');
            $this->line('  Lấy ở đâu:');
            $this->line('   · cx  : programmablesearchengine.google.com → tạo engine → BẬT "Search the entire web" → copy mã cx');
            $this->line('   · key : console.cloud.google.com → APIs & Services → bật "Custom Search API" → tạo API key');
            $this->line('  Khoá nên truyền NGAY TRÊN MÁY CHỦ (SSH), đừng gửi qua chat/email.');

            return self::FAILURE;
        }

        // 1) KHOÁ — vào bảng khoá (đã mã hoá). Cố tình KHÔNG in khoá ra màn hình.
        StudioApiKey::query()->updateOrCreate(
            ['provider' => $slug],
            [
                'label' => 'Google CSE (tìm kiếm web chung)',
                'value' => $key,
                'kind' => null,
                'scopes' => ['*'],
                'priority' => 9,
                'enabled' => true,
                'note' => 'Khoá cho nguồn tìm kiếm "'.$slug.'" — gắn vào URL chỉ ở lời gọi HTTP.',
            ]
        );
        $this->line('Đã lưu khoá API cho provider "'.$slug.'" (mã hoá; hiện 4 ký tự cuối: …'.mb_substr($key, -4).')');

        // 2) NGUỒN — URL có {query} (máy chủ thay từ khoá model hỏi vào) và KHÔNG chứa khoá.
        $url = 'https://www.googleapis.com/customsearch/v1?cx='.rawurlencode($cx)
            .'&num='.$num.'&q={query}'
            .($freshDays !== null ? '&dateRestrict=d'.$freshDays : '');

        $source = WebSource::query()->firstOrNew(['slug' => $slug]);
        $source->fill([
            'name' => (string) $this->option('name'),
            'url' => $url,
            'kind' => 'search',
            'enabled' => true,
            'priority' => 1,
            'max_items' => $num,
            'keywords' => null,
            'region' => null,
            'items_path' => 'items',
            'title_field' => 'title',
            'link_field' => 'link',
            'date_field' => null,        // Google CSE KHÔNG trả ngày đăng — xem cảnh báo bên dưới
            'summary_field' => 'snippet',
            'note' => 'API tìm kiếm có khoá (Google Programmable Search). '.($freshDays !== null ? 'Chỉ lấy tin trong '.$freshDays.' ngày.' : 'Không giới hạn thời gian.'),
        ]);
        $source->save();
        $this->line(($source->wasRecentlyCreated ? 'Đã TẠO' : 'Đã cập nhật').' nguồn "'.$slug.'" (kind=search)');
        $this->line('  URL lưu trong Cài đặt: '.$url);

        // 3) THỬ THẬT — không có bước này thì "đã khai" chỉ là lời hứa.
        $query = trim((string) $this->option('test'));
        $this->newLine();
        $this->line('── THỬ THẬT: "'.$query.'" ──');
        $found = $sources->search($query, 'all', 5);
        $this->line('  kết quả: '.$found['count'].' mục · đọc được '.$found['parsed'].' · bỏ vì cũ '.$found['dropped']
            .($found['error'] ? ' · LỖI: '.$found['error'] : ''));

        foreach (array_slice($found['items'], 0, 3) as $item) {
            $this->line('   · '.mb_substr((string) $item['title'], 0, 80));
            $this->line('     '.mb_substr((string) $item['url'], 0, 90));
        }

        $this->newLine();
        if ($found['count'] === 0) {
            // Nói ĐÚNG việc cần sửa — ba nguyên nhân rất khác nhau, và cả ba đều chỉ hiện ra là "0 kết quả".
            $this->error('  CHƯA TRA ĐƯỢC GÌ ⇒ đừng coi là xong.');
            $this->line('   · HTTP 400/403 kèm "API key not valid" ⇒ khoá sai hoặc CHƯA bật "Custom Search API" trong Google Cloud');
            $this->line('   · HTTP 400 kèm "invalid cx" ⇒ sai cx, hoặc engine CHƯA bật "Search the entire web"');
            $this->line('   · HTTP 200 mà 0 mục ⇒ engine chỉ tìm trong vài site đã khai; sửa ở Programmable Search');

            return self::FAILURE;
        }

        $this->info('  ĐẠT: web chung đã tra được thật ('.$found['count'].' mục cho câu hỏi thử).');

        // 4) HAI điều phải nói trước khi tin: ngày đăng và hạn mức.
        $this->newLine();
        $this->warn('  LƯU Ý 1: Google CSE KHÔNG trả ngày đăng ⇒ mục vào prompt với nhãn "không ngày".');
        $this->line('    Hệ quả thật: tin không ngày thì KHÔNG đo được xu hướng tăng/giảm. Muốn chắc là tin mới,');
        $this->line('    chạy lại lệnh này kèm --fresh=30 (Google tự lọc theo thời gian ở phía họ).');
        $this->warn('  LƯU Ý 2: hạn mức miễn phí của Google là 100 truy vấn/ngày; mỗi lời gọi công cụ của model là 1 truy vấn');
        $this->line('    (có đệm 15 phút theo nguồn · từ khoá, nên hỏi lại cùng câu không tốn thêm).');
        $this->newLine();
        $this->line('Kiểm độ phủ bất cứ lúc nào: php artisan studio:web-search-probe');

        return self::SUCCESS;
    }
}
