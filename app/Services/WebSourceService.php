<?php

namespace App\Services;

use App\Models\WebSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * TRÌNH KẾT NỐI NGUỒN NGOÀI — MÁY CHỦ tự đi lấy dữ liệu thật rồi đưa vào lời nhắc (2026-09-23).
 *
 * Vì sao có lớp này: đo thật trên production — DeepSeek nhận tham số `enable_search` với HTTP 200 rồi
 * BỎ QUA, model vẫn trả lời "không có quyền truy cập thông tin thời gian thực". Nghĩa là KHÔNG thể bật
 * tìm kiếm web cho model bằng cấu hình. Nhưng MÁY CHỦ thì ra được internet — nên việc đúng là để máy
 * chủ lấy dữ liệu, lọc, rồi đưa vào prompt kèm URL + THỜI ĐIỂM. Model chỉ đọc và dẫn nguồn.
 *
 * Ba nguyên tắc:
 *   1. Danh sách nguồn là CẤU HÌNH (bảng web_sources, khai trong Cài đặt) — thêm nguồn RSS/JSON/API mới
 *      KHÔNG phải sửa mã; nguồn JSON tự khai hình dạng qua items_path + các trường *_field.
 *   2. Một nguồn hỏng KHÔNG được làm hỏng cả lượt: mỗi nguồn có trạng thái riêng (ok/http/ms/lỗi) và
 *      giao diện hiển thị đúng như vậy — "0 tin" phải đọc được là do nguồn chết hay do bộ lọc.
 *   3. Dữ liệu lấy về là DỮ LIỆU, không phải mệnh lệnh: nội dung bị cắt ngắn và lời nhắc nói rõ phải bỏ
 *      qua mọi chỉ dẫn nằm trong đó (chống prompt-injection từ trang ngoài).
 */
class WebSourceService
{
    /** Đệm mỗi nguồn 30 phút — đủ tươi cho tin xu hướng mà không đập vào site người ta mỗi lần bấm. */
    private const CACHE_MINUTES = 30;

    private const CACHE_PREFIX = 'studio:web-source:v1:';

    /** Tin cũ hơn mức này thì bỏ — "xu hướng" mà cũ 2 tháng là vô nghĩa. */
    private const MAX_AGE_DAYS = 60;

    /** Trần số tin đưa vào prompt (chặn token). */
    public const EVIDENCE_LIMIT = 14;

    /**
     * Dữ liệu ngoài cho MỘT lượt chạy agent.
     *
     * @param  string  $region  'all' | 'hcm' | 'hanoi' | 'danang'
     * @param  int  $limit  trần số tin
     * @param  bool  $force  bỏ đệm (nút "Làm mới nguồn" / lệnh artisan)
     * @return array<string, mixed>
     */
    public function evidence(string $region = 'all', int $limit = self::EVIDENCE_LIMIT, bool $force = false): array
    {
        $sources = WebSource::query()->where('enabled', true)->orderBy('priority')->orderBy('id')->get();
        $statuses = [];
        $items = [];
        $seen = [];

        foreach ($sources as $source) {
            if (! $source->matchesRegion($region)) {
                $statuses[] = $this->status($source, false, null, 0, 0, 'bỏ qua: nguồn của vùng khác');
                continue;
            }

            $fetched = $this->fetch($source, $force);
            $statuses[] = $this->status($source, $fetched['ok'], $fetched['http'], $fetched['ms'], count($fetched['items']), $fetched['error']);

            foreach ($fetched['items'] as $item) {
                $key = $this->dedupeKey($item);
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = $item;
            }
        }

        // Mới nhất trước; tin không có ngày xếp sau nhưng KHÔNG bị loại (nhiều feed thiếu pubDate).
        usort($items, fn (array $a, array $b) => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));
        $items = array_slice($items, 0, max(1, min(40, $limit)));

        return [
            // mode=live CHỈ khi có ít nhất một tin thật — nơi gọi dùng nó để nói thật với người dùng.
            'mode' => $items !== [] ? 'live' : 'empty',
            'fetched_at' => now()->toISOString(),
            'fingerprint' => md5(json_encode(array_map(fn (array $i) => $i['url'], $items))),
            'items' => $items,
            'sources' => $statuses,
            'limits' => ['max_age_days' => self::MAX_AGE_DAYS, 'limit' => $limit],
        ];
    }

    /**
     * Lấy MỘT nguồn (có đệm). KHÔNG bao giờ ném lỗi: nguồn chết là một KẾT QUẢ ĐO.
     *
     * @return array{ok:bool, http:?int, ms:int, items:list<array>, error:?string}
     */
    public function fetch(WebSource $source, bool $force = false): array
    {
        $cacheKey = self::CACHE_PREFIX.$source->id.':'.md5($source->url.'|'.(string) $source->updated_at);
        if (! $force) {
            try {
                $hit = Cache::get($cacheKey);
                if (is_array($hit)) {
                    return $hit;
                }
            } catch (\Throwable) {
                // không đọc được cache ⇒ đi lấy thật
            }
        }

        $started = microtime(true);
        $result = ['ok' => false, 'http' => null, 'ms' => 0, 'items' => [], 'error' => null];

        try {
            $response = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'FabrikAI/1.0 (+https://fabrikai.shop)'])
                ->get($source->url);
            $result['http'] = $response->status();

            if (! $response->successful()) {
                $result['error'] = 'HTTP '.$response->status();
            } else {
                $raw = $source->kind === 'json'
                    ? $this->parseJson((string) $response->body(), $source)
                    : $this->parseRss((string) $response->body());
                $result['ok'] = true;
                $result['items'] = $this->filter($raw, $source);
            }
        } catch (\Throwable $e) {
            $result['error'] = class_basename($e);
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);

        try {
            // Đệm CẢ kết quả lỗi (ngắn hơn): nguồn chết không bị gọi lại liên tục mỗi lần mở màn hình.
            Cache::put($cacheKey, $result, now()->addMinutes($result['ok'] ? self::CACHE_MINUTES : 5));
        } catch (\Throwable) {
            // bộ đệm là tối ưu, không phải điều kiện chạy
        }

        return $result;
    }
    /**
     * Đọc RSS/Atom. Dùng SimpleXML với libxml nội bộ; lỗi cú pháp ⇒ trả [] (nguồn hỏng, không phải app hỏng).
     *
     * @return list<array{title:string, url:string, published_at:?string, summary:string}>
     */
    private function parseRss(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        $nodes = [];
        if (isset($xml->channel->item)) {
            $nodes = $xml->channel->item;            // RSS 2.0
        } elseif (isset($xml->item)) {
            $nodes = $xml->item;
        } elseif (isset($xml->entry)) {
            $nodes = $xml->entry;                    // Atom
        }

        $out = [];
        foreach ($nodes as $node) {
            $title = $this->clean((string) ($node->title ?? ''), 200);
            // Atom để link ở thuộc tính href; RSS để trong thẻ <link>.
            $link = trim((string) ($node->link ?? ''));
            if ($link === '' && isset($node->link['href'])) {
                $link = trim((string) $node->link['href']);
            }
            $date = (string) ($node->pubDate ?? $node->published ?? $node->updated ?? '');
            $summary = $this->clean((string) ($node->description ?? $node->summary ?? $node->content ?? ''), 400);

            if ($title === '' || $link === '') {
                continue;
            }

            $out[] = [
                'title' => $title,
                'url' => Str::limit($link, 500, ''),
                'published_at' => $this->isoDate($date),
                'summary' => $summary,
            ];
        }

        return $out;
    }

    /**
     * Đọc nguồn JSON/API theo ÁNH XẠ người dùng khai (items_path + *_field). Nhờ vậy thêm API mới không
     * phải viết mã: nguồn tự nói dữ liệu của nó nằm ở đâu.
     *
     * @return list<array{title:string, url:string, published_at:?string, summary:string}>
     */
    private function parseJson(string $body, WebSource $source): array
    {
        $json = json_decode($body, true);
        if (! is_array($json)) {
            return [];
        }

        $path = trim((string) $source->items_path);
        $rows = $path !== '' ? data_get($json, $path) : $json;
        if (! is_array($rows)) {
            return [];
        }

        $titleField = trim((string) $source->title_field) ?: 'title';
        $linkField = trim((string) $source->link_field) ?: 'url';
        $dateField = trim((string) $source->date_field) ?: 'published_at';
        $summaryField = trim((string) $source->summary_field) ?: 'summary';

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = $this->clean((string) data_get($row, $titleField, ''), 200);
            $link = trim((string) data_get($row, $linkField, ''));
            if ($title === '' || $link === '') {
                continue;
            }
            $out[] = [
                'title' => $title,
                'url' => Str::limit($link, 500, ''),
                'published_at' => $this->isoDate((string) data_get($row, $dateField, '')),
                'summary' => $this->clean((string) data_get($row, $summaryField, ''), 400),
            ];
        }

        return $out;
    }

    /**
     * LỌC theo cấu hình của nguồn: từ khoá · độ mới · số mục.
     *
     * @param  list<array{title:string, url:string, published_at:?string, summary:string}>  $items
     * @return list<array<string, mixed>>
     */
    private function filter(array $items, WebSource $source): array
    {
        $keywords = $source->keywordList();
        $cutoff = now()->subDays(self::MAX_AGE_DAYS)->getTimestamp();
        $out = [];

        foreach ($items as $item) {
            $haystack = mb_strtolower($item['title'].' '.$item['summary']);

            if ($keywords !== []) {
                $hit = false;
                foreach ($keywords as $word) {
                    if ($word !== '' && str_contains($haystack, $word)) {
                        $hit = true;
                        break;
                    }
                }
                if (! $hit) {
                    continue;
                }
            }

            // Tin có ngày mà quá cũ thì bỏ; tin KHÔNG có ngày vẫn giữ (nhiều feed thiếu).
            if ($item['published_at'] !== null) {
                $ts = strtotime((string) $item['published_at']);
                if ($ts !== false && $ts < $cutoff) {
                    continue;
                }
            }

            $out[] = $item + [
                'source' => $source->slug,
                'source_name' => $source->name,
            ];
        }

        usort($out, fn (array $a, array $b) => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));

        return array_slice($out, 0, max(1, min(50, (int) $source->max_items)));
    }
    /** Trạng thái một nguồn để giao diện nói THẬT (0 tin do nguồn chết hay do bộ lọc). */
    private function status(WebSource $source, bool $ok, ?int $http, int $ms, int $count, ?string $error): array
    {
        return [
            'slug' => $source->slug,
            'id' => $source->id,
            'name' => $source->name,
            'url' => $source->url,
            'kind' => $source->kind,
            'region' => $source->region,
            'ok' => $ok,
            'http' => $http,
            'ms' => $ms,
            'count' => $count,
            'error' => $error,
            'fetched_at' => now()->toISOString(),
        ];
    }

    /** Bỏ HTML/thực thể, gộp khoảng trắng, cắt trần. Nội dung ngoài KHÔNG được mang thẻ vào prompt. */
    private function clean(string $text, int $limit): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, $limit, '');
    }

    /** Ngày về ISO-8601 (kèm giờ) để prompt luôn có THỜI ĐIỂM; không đọc được thì null. */
    private function isoDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);

        return $ts === false ? null : date('c', $ts);
    }

    /** Khoá chống trùng giữa các nguồn: ưu tiên URL, không có thì dùng tiêu đề đã chuẩn hoá. */
    private function dedupeKey(array $item): string
    {
        $url = trim((string) ($item['url'] ?? ''));
        if ($url !== '') {
            return mb_strtolower($url);
        }

        return mb_strtolower(trim((string) ($item['title'] ?? '')));
    }

    /**
     * Danh sách mặc định — dùng cho lệnh `studio:web-sources --seed` và nút "Thêm nguồn mẫu".
     *
     * Đã ĐO trên production (2026-09-23): 3 nguồn dưới đây trả 200 và có tin thật;
     * `vnexpress.net/rss/thoi-trang.rss` trả 200 nhưng 0 item và `thanhnien.vn/rss/thoi-trang.rss` trả 404
     * ⇒ KHÔNG đưa vào mặc định (nguồn chết làm người dùng tưởng hệ thống hỏng).
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'slug' => 'google-news-thoi-trang',
                'name' => 'Google News — thời trang (VN)',
                'url' => 'https://news.google.com/rss/search?q=th%E1%BB%9Di+trang&hl=vi&gl=VN&ceid=VN:vi',
                'kind' => 'rss', 'priority' => 1, 'keywords' => 'thời trang, thiết kế, xu hướng, vải, áo, đầm',
                'region' => null, 'max_items' => 8,
                'note' => 'Tin thời trang tiếng Việt (Google News tổng hợp, có ngày + link gốc).',
            ],
            [
                'slug' => 'tuoitre-thoi-trang',
                'name' => 'Tuổi Trẻ — Thời trang',
                'url' => 'https://tuoitre.vn/rss/thoi-trang.rss',
                'kind' => 'rss', 'priority' => 2, 'keywords' => null,
                'region' => null, 'max_items' => 6,
                'note' => 'Chuyên mục thời trang của báo Tuổi Trẻ.',
            ],
            [
                'slug' => 'vnexpress-kinh-doanh',
                'name' => 'VnExpress — Kinh doanh',
                'url' => 'https://vnexpress.net/rss/kinh-doanh.rss',
                'kind' => 'rss', 'priority' => 3,
                'keywords' => 'thời trang, may mặc, dệt may, bán lẻ, thương mại điện tử, xuất khẩu',
                'region' => null, 'max_items' => 5,
                'note' => 'Bối cảnh ngành may mặc/bán lẻ — lọc theo từ khoá nên chỉ giữ tin liên quan.',
            ],
        ];
    }

    /** Tạo các nguồn mặc định còn thiếu (giữ nguyên nguồn đã có — không ghi đè cấu hình của người dùng). */
    public function seedDefaults(): int
    {
        $created = 0;
        foreach (self::defaults() as $row) {
            if (WebSource::query()->where('slug', $row['slug'])->exists()) {
                continue;
            }
            WebSource::create($row + ['enabled' => true]);
            $created++;
        }

        return $created;
    }
}
