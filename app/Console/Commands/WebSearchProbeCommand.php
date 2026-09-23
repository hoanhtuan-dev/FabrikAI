<?php

namespace App\Console\Commands;

use App\Services\WebSourceService;
use Illuminate\Console\Command;

/**
 * KIỂM ĐỘ PHỦ TÌM KIẾM THẬT của các nguồn đang khai (2026-09-26).
 *
 * Vì sao cần: "model có tìm kiếm thật không" là HAI câu hỏi khác nhau, và trước lệnh này chỉ trả lời được
 * câu thứ nhất:
 *   1. công cụ có chạy thật không ⇒ đo bằng studio:chat-check --live;
 *   2. nó tra được GÌ          ⇒ phải đo bằng câu hỏi THẬT. Nguồn đang khai toàn là RSS TIN TỨC: câu hỏi
 *      dạng tin tức ("xu hướng áo dạ tweed 2026") có kết quả, còn câu hỏi web chung ("cách giặt vải
 *      linen", "giá vải linen") trả về 0 — và model sẽ nói "chưa tra được".
 * Có con số đó thì việc còn lại là CẤU HÌNH (khai thêm nguồn tìm kiếm web chung), không phải sửa mã.
 *
 * Dùng:
 *   php artisan studio:web-search-probe                           # bộ câu hỏi mặc định
 *   php artisan studio:web-search-probe --q="cách giặt vải linen"  # một câu hỏi cụ thể
 */
class WebSearchProbeCommand extends Command
{
    protected $signature = 'studio:web-search-probe
        {--q= : Một câu hỏi cụ thể cần đo (bỏ trống thì chạy bộ mặc định)}
        {--region=all : Vùng dữ liệu (all|hcm|hanoi|danang)}';

    protected $description = 'Đo TÌM KIẾM THẬT: câu hỏi tin tức và câu hỏi web chung hiện trả về bao nhiêu nguồn.';

    public function handle(WebSourceService $sources): int
    {
        $region = (string) $this->option('region');
        $one = trim((string) $this->option('q'));

        $questions = $one !== '' ? [$one] : [
            'xu hướng áo dạ tweed 2026',   // TIN TỨC — đường đang chạy được
            'cách giặt vải linen',          // WEB CHUNG — câu hỏi không phải tin tức
            'giá vải linen',                // WEB CHUNG — câu hỏi thương mại
        ];

        $targets = $sources->searchableSources($region);
        $this->line('── NGUỒN TÌM ĐƯỢC THEO TỪ KHOÁ ('.$region.') ──');
        if ($targets === []) {
            $this->warn('  KHÔNG có nguồn nào tìm được theo từ khoá ⇒ công cụ web_search luôn trả 0 kết quả.');
            $this->line('  Cần khai một nguồn có {query} trong URL — xem HUONG_DAN_TINH_NANG_MOI.md §11.6.');

            return self::SUCCESS;
        }
        foreach ($targets as $source) {
            $this->line(sprintf('  · %-30s %s', $source->slug, $source->kind === 'search' ? 'API tìm kiếm (cần khoá)' : $source->kind.' (không cần khoá)'));
        }

        $this->newLine();
        $this->line('── ĐO TỪNG CÂU HỎI ──');
        $live = 0;
        foreach ($questions as $question) {
            $t0 = microtime(true);
            $result = $sources->search($question, $region);
            $ms = (int) round((microtime(true) - $t0) * 1000);

            $this->line(sprintf('  %-32s %s · %d nguồn · đọc được %d · bỏ vì cũ %d · %d ms%s',
                $question,
                $result['count'] > 0 ? 'CÓ KẾT QUẢ' : '0 KẾT QUẢ',
                $result['count'], $result['parsed'], $result['dropped'], $ms,
                $result['error'] ? ' · '.$result['error'] : '',
            ));
            foreach (array_slice($result['items'], 0, 2) as $item) {
                $this->line('      - '.mb_substr((string) $item['title'], 0, 80).' — '.mb_substr((string) $item['url'], 0, 60));
            }
            if ($result['count'] > 0) {
                $live++;
            }
        }

        $this->newLine();

        if ($live === count($questions)) {
            $this->line('Kết luận: MỌI câu hỏi đều có kết quả thật.');

            return self::SUCCESS;
        }

        // KẾT LUẬN PHẢI CHỈ ĐÚNG VIỆC CẦN LÀM — ba nguyên nhân rất khác nhau, và cả ba đều hiện ra là "0 kết quả":
        $hasWebSearch = false;
        foreach ($sources->searchableSources($region) as $source) {
            if (in_array((string) ($source->kind ?? ''), ['tavily', 'search'], true)) {
                $hasWebSearch = true;
            }
        }

        $this->line('Kết luận: '.(count($questions) - $live).'/'.count($questions).' câu hỏi KHÔNG tra được gì.');
        if (! $hasWebSearch) {
            $this->line('  · Nguồn đang khai KHÔNG có nguồn tìm kiếm web chung ⇒ câu hỏi ngoài tin tức sẽ luôn 0 kết quả.');
            $this->line('    Khai bằng MỘT lệnh: php artisan studio:web-search-setup --provider=tavily   (tavily.com — chạy được KHÔNG cần khoá)');
            $this->line('    hoặc một API có khoá (Google CSE) — xem HUONG_DAN_TINH_NANG_MOI.md §11.6');

            return self::SUCCESS;
        }

        // Đã CÓ nguồn web chung mà vẫn 0 ⇒ phần lớn là do BỘ LỌC ĐỘ MỚI, không phải "internet không có gì".
        $this->line('  · Đã có nguồn tìm kiếm web chung. Câu hỏi ra 0 thường vì kết quả đều CŨ hơn '
            .WebSourceService::MAX_AGE_DAYS.' ngày (xem cột "bỏ vì cũ").');
        $this->line('    Đây là luật CỐ Ý của dự án: thà không có tin còn hơn đưa tin cũ rồi gọi đó là xu hướng hiện tại.');

        return self::SUCCESS;
    }
}
