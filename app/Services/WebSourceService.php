<?php

namespace App\Services;

use App\Models\WebSource;
use App\Support\VietnameseText;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * TRÌNH KẾT NỐI NGUỒN NGOÀI — MÁY CHỦ tự đi lấy dữ liệu thật rồi đưa vào lời nhắc (2026-09-23).
 *
 * Vì sao có lớp này: đo thật trên production — DeepSeek nhận tham số tìm kiếm web với HTTP 200 rồi BỎ QUA,
 * model vẫn trả lời "không có quyền truy cập thông tin thời gian thực". Nghĩa là KHÔNG thể bật tìm kiếm
 * web cho model bằng cấu hình. Nhưng MÁY CHỦ thì ra được internet — nên việc đúng là để máy chủ lấy dữ
 * liệu, lọc, rồi đưa vào prompt kèm URL + THỜI ĐIỂM. Model chỉ đọc và dẫn nguồn.
 *
 * Ba nguyên tắc:
 *   1. Danh sách nguồn là CẤU HÌNH (bảng web_sources, khai trong Cài đặt) — thêm nguồn RSS/JSON/API mới
 *      KHÔNG phải sửa mã; nguồn JSON tự khai hình dạng qua items_path + các trường *_field.
 *   2. Một nguồn hỏng KHÔNG được làm hỏng cả lượt: mỗi nguồn có trạng thái riêng, và trạng thái phải phân
 *      biệt được BỐN chuyện rất khác nhau: nguồn chết · nguồn không có tin · tin bị bộ lọc loại hết ·
 *      đang dùng bản lấy từ trước. Gộp chúng thành "0 tin" là nói dối người dùng.
 *   3. Dữ liệu lấy về là DỮ LIỆU, không phải mệnh lệnh: nội dung bị làm sạch (bỏ thẻ TRƯỚC khi giải mã
 *      thực thể — làm ngược lại thì thẻ HTML sống lại), cắt ngắn, và lời nhắc nói rõ phải bỏ qua mọi chỉ
 *      dẫn nằm trong đó.
 */
class WebSourceService
{
    /** Đệm mỗi nguồn 30 phút — đủ tươi cho tin xu hướng mà không đập vào site người ta mỗi lần bấm. */
    public const CACHE_MINUTES = 30;

    /** Kết quả LỖI đệm ngắn hơn: nguồn chết không bị gọi lại liên tục mỗi lần mở màn hình. */
    private const FAIL_CACHE_MINUTES = 5;

    /** Bản lấy THÀNH CÔNG gần nhất được giữ riêng: nguồn chết tạm thời thì vẫn còn tin mà dùng. */
    private const LAST_GOOD_HOURS = 24;

    /** v2: kết quả có thêm state/parsed/filtered — bản cache v1 thiếu khoá nên không được lẫn vào. */
    private const CACHE_PREFIX = 'studio:web-source:v2:';

    /** Tin cũ hơn mức này thì bỏ — "xu hướng" mà cũ 2 tháng là vô nghĩa. */
    public const MAX_AGE_DAYS = 60;

    /** Trần số tin đưa vào prompt (chặn token). */
    public const EVIDENCE_LIMIT = 14;

    /** Trần kích thước body đọc vào bộ nhớ (feed lớn bất thường = dấu hiệu bị chặn hoặc lỗi). */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Chờ kết nối (TCP/TLS) ngắn hơn chờ nội dung: host treo không được giữ request 12 giây. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 12;

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
        $usable = [];
        $statuses = [];

        foreach ($sources as $source) {
            if (! $source->matchesRegion($region)) {
                // Nguồn của vùng khác KHÔNG phải nguồn lỗi — giao diện phải đọc được lý do, không thấy "hỏng".
                $statuses[] = $this->status($source, false, null, 0, 0, 'bỏ qua: nguồn của vùng khác', [
                    'state' => 'skipped',
                    'state_label' => 'Bỏ qua (nguồn của vùng khác)',
                ]);
                continue;
            }
            $usable[] = $source;
        }

        $fetched = $this->fetchMany($usable, $force);
        $items = [];
        $seen = [];
        $seenTitles = [];

        foreach ($usable as $source) {
            $row = $fetched[$source->id] ?? ['ok' => false, 'http' => null, 'ms' => 0, 'items' => [], 'error' => 'không chạy', 'parsed' => 0, 'dropped' => 0, 'stale' => false];
            $statuses[] = $this->statusFromResult($source, $row);

            foreach ($row['items'] as $item) {
                $key = $this->dedupeKey($item);
                $titleKey = $this->titleKey($item);
                // Trùng URL HOẶC trùng tiêu đề đã chuẩn hoá: cùng một bài chạy qua Google News và qua báo
                // gốc có hai URL khác nhau — chỉ so URL thì prompt chứa hai bản của cùng một tin.
                if (($key !== '' && isset($seen[$key])) || ($titleKey !== '' && isset($seenTitles[$titleKey]))) {
                    continue;
                }
                if ($key !== '') {
                    $seen[$key] = true;
                }
                if ($titleKey !== '') {
                    $seenTitles[$titleKey] = true;
                }
                $items[] = $item;
            }
        }

        // Mới nhất trước; tin không có ngày xếp sau nhưng KHÔNG bị loại (nhiều feed thiếu pubDate).
        usort($items, fn (array $a, array $b) => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));
        $items = array_slice($items, 0, $this->effectiveLimit($limit));

        return [
            // mode=live CHỈ khi có ít nhất một tin thật — nơi gọi dùng nó để nói thật với người dùng.
            'mode' => $items !== [] ? 'live' : 'empty',
            'fetched_at' => now()->toISOString(),
            'fingerprint' => md5(json_encode(array_map(fn (array $i) => $i['url'], $items))),
            'items' => $items,
            'sources' => $statuses,
            'limits' => ['max_age_days' => self::MAX_AGE_DAYS, 'limit' => $this->effectiveLimit($limit)],
        ];
    }

    /** Trần hiệu dụng — trả về ĐÚNG con số đã dùng, không phải con số người gọi xin (0 vẫn phải có trần thật). */
    private function effectiveLimit(int $limit): int
    {
        return max(1, min(40, $limit));
    }

    /**
     * Lấy MỘT nguồn (có đệm). KHÔNG bao giờ ném lỗi: nguồn chết là một KẾT QUẢ ĐO.
     *
     * @return array{ok:bool, http:?int, ms:int, items:list<array>, error:?string, parsed:int, dropped:int, stale:bool}
     */
    public function fetch(WebSource $source, bool $force = false): array
    {
        if (! $force) {
            $hit = $this->readCache($source);
            if ($hit !== null) {
                return $hit;
            }
        }

        $started = microtime(true);
        try {
            $response = $this->request($source);
            $result = $this->finalise($source, $this->interpret($source, $response, $started));
        } catch (\Throwable $e) {
            $result = $this->finalise($source, $this->failure($source, $e, $started));
        }

        $this->writeCache($source, $result);

        return $result;
    }

    /**
     * Lấy NHIỀU nguồn — chạy SONG SONG khi được.
     *
     * Vì sao: gọi tuần tự 3 nguồn × 12 giây = 36 giây cho một lần cache nguội; người dùng mở Agent Studio
     * là phải chờ từng ấy. Song song thì trần là nguồn chậm nhất. Nếu môi trường không chạy được pool
     * (handler HTTP lạ) thì TỰ QUAY VỀ tuần tự — thà chậm còn hơn không có tin.
     *
     * @param  list<WebSource>  $sources
     * @return array<int, array<string, mixed>>  khoá theo id nguồn
     */
    private function fetchMany(array $sources, bool $force): array
    {
        $out = [];
        $pending = [];
        foreach ($sources as $source) {
            if (! $force) {
                $hit = $this->readCache($source);
                if ($hit !== null) {
                    $out[$source->id] = $hit;
                    continue;
                }
            }
            $pending[] = $source;
        }

        if ($pending === []) {
            return $out;
        }

        if (count($pending) === 1) {
            $only = $pending[0];
            $out[$only->id] = $this->fetch($only, true);

            return $out;
        }

        $started = microtime(true);
        try {
            $responses = Http::pool(function (Pool $pool) use ($pending) {
                foreach ($pending as $source) {
                    $this->queue($pool, $source);
                }
            });
        } catch (\Throwable $e) {
            // Pool không dựng được (handler không hỗ trợ bất đồng bộ) ⇒ tuần tự, KHÔNG mất tin.
            foreach ($pending as $source) {
                $out[$source->id] = $this->fetch($source, true);
            }

            return $out;
        }

        foreach ($pending as $source) {
            $response = $responses[$this->poolKey($source)] ?? null;
            $result = $response instanceof Response || $response instanceof \Throwable
                ? ($response instanceof \Throwable
                    ? $this->failure($source, $response, $started)
                    : $this->interpret($source, $response, $started))
                : $this->failure($source, new \RuntimeException('không có phản hồi'), $started);
            $result = $this->finalise($source, $result);
            $this->writeCache($source, $result);
            $out[$source->id] = $result;
        }

        return $out;
    }

    /** Khoá trong pool phải là chuỗi; id nguồn là số nên phải đổi tên cho khỏi bị PHP ép kiểu. */
    private function poolKey(WebSource $source): string
    {
        return 's'.$source->id;
    }

    /** Xếp một yêu cầu vào pool với đúng các ràng buộc an toàn như đường gọi đơn. */
    private function queue(Pool $pool, WebSource $source): void
    {
        $this->assertPublicUrl($source->url);

        $pool->as($this->poolKey($source))
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withHeaders(['User-Agent' => 'FabrikAI/1.0 (+https://fabrikai.shop)'])
            ->withOptions($this->options())
            ->get($source->url);
    }

    /** Yêu cầu đơn (đường fetch một nguồn). */
    private function request(WebSource $source): Response
    {
        $this->assertPublicUrl($source->url);

        return Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withHeaders(['User-Agent' => 'FabrikAI/1.0 (+https://fabrikai.shop)'])
            ->withOptions($this->options())
            ->get($source->url);
    }

    /**
     * Tuỳ chọn HTTP an toàn: đi theo redirect nhưng TỐI ĐA 2 bước, chỉ http/https, và CHẶN redirect về địa
     * chỉ nội bộ. Không có hai ràng buộc cuối thì một feed công khai bị chiếm có thể 302 máy chủ đi đọc
     * metadata nội bộ của hạ tầng (SSRF) — chuyện chỉ cần quyền admin khai nguồn là làm được.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'allow_redirects' => [
                'max' => 2,
                'strict' => false,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri) {
                    if (! $this->isPublicHost((string) $uri->getHost())) {
                        throw new \RuntimeException('redirect tới địa chỉ nội bộ');
                    }
                },
            ],
        ];
    }

    /** URL nguồn phải trỏ ra internet công khai — chặn localhost và dải địa chỉ nội bộ/đặc biệt. */
    private function assertPublicUrl(string $url): void
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if (! $this->isPublicHost($host)) {
            throw new \RuntimeException('địa chỉ không công khai: '.$host);
        }
    }

    private function isPublicHost(string $host): bool
    {
        $host = trim(mb_strtolower($host));
        if ($host === '') {
            return false;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Địa chỉ nội bộ (10/8 · 172.16/12 · 192.168/16) và dải đặc biệt (169.254/16, 127/8) đều bị chặn.
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /**
     * Đọc phản hồi thành kết quả chuẩn (parse → lọc → số đo). Tách khỏi việc gọi mạng để đường SONG SONG
     * dùng lại đúng phép xử lý này — hai đường không thể lệch nhau về cách hiểu dữ liệu.
     */
    private function interpret(WebSource $source, Response $response, float $started): array
    {
        $result = [
            'ok' => false, 'http' => $response->status(), 'ms' => 0, 'items' => [],
            'error' => null, 'parsed' => 0, 'dropped' => 0, 'stale' => false,
        ];

        $body = (string) $response->body();
        $declared = (int) $response->header('Content-Length');
        $oversized = ($declared > 0 && $declared > self::MAX_BYTES) || strlen($body) > self::MAX_BYTES;

        if (! $response->successful()) {
            $result['error'] = 'HTTP '.$response->status();
        } elseif ($oversized) {
            $result['error'] = 'nội dung quá lớn';
        } else {
            $parsed = $source->kind === 'json' ? $this->parseJson($body, $source) : $this->parseRss($body);
            $result['parsed'] = count($parsed);
            $result['ok'] = true;
            $filtered = $this->filter($parsed, $source);
            $result['dropped'] = max(0, count($parsed) - count($filtered));
            $result['items'] = $filtered;
            if ($parsed === [] && trim($body) !== '') {
                // Đọc được HTTP nhưng KHÔNG đọc được mục nào: gần như luôn là định dạng lạ hoặc ánh xạ sai
                // (nguồn JSON khai sai items_path). Nói ra để người cấu hình biết mà sửa, không im lặng.
                $result['error'] = $source->kind === 'json'
                    ? 'không đọc được mục nào theo ánh xạ đã khai'
                    : 'nội dung không phải RSS/Atom đọc được';
            }
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    /** Nguồn lỗi: ghi lý do ĐỌC ĐƯỢC (không phải tên lớp ngoại lệ). */
    private function failure(WebSource $source, \Throwable $e, float $started): array
    {
        return [
            'ok' => false, 'http' => null, 'ms' => (int) round((microtime(true) - $started) * 1000),
            'items' => [], 'error' => $this->readableError($e), 'parsed' => 0, 'dropped' => 0, 'stale' => false,
        ];
    }

    /**
     * Chốt kết quả của MỘT nguồn, ở ĐÚNG MỘT chỗ: nguồn lỗi (dù lỗi mạng hay mã HTTP xấu) thì dùng bản lấy
     * THÀNH CÔNG gần nhất và nói rõ đó là bản cũ. Thà có tin cũ kèm thời điểm còn hơn mất cả nền dữ liệu
     * thị trường — nhưng tuyệt đối không được im lặng coi tin cũ là tin mới.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finalise(WebSource $source, array $result): array
    {
        if (($result['ok'] ?? false) === true || ($result['items'] ?? []) !== []) {
            return $result;
        }

        $last = $this->readLastGood($source);
        if ($last !== null) {
            $result['items'] = $last['items'];
            $result['stale'] = true;
            $result['stale_at'] = $last['fetched_at'] ?? null;
        }

        return $result;
    }

    /** Câu lỗi cho NGƯỜI ĐỌC: phân biệt không kết nối được / quá thời gian / chứng chỉ / bị chặn. */
    private function readableError(\Throwable $e): string
    {
        $message = mb_strtolower($e->getMessage());
        $reason = match (true) {
            str_contains($message, 'timed out') || str_contains($message, 'timeout') => 'quá thời gian chờ',
            str_contains($message, 'certificate') || str_contains($message, 'ssl') => 'lỗi chứng chỉ bảo mật',
            str_contains($message, 'could not resolve') || str_contains($message, 'getaddrinfo') => 'không tìm thấy tên miền',
            str_contains($message, 'refused') => 'máy chủ nguồn từ chối kết nối',
            str_contains($message, 'địa chỉ không công khai'), str_contains($message, 'nội bộ') => $e->getMessage(),
            default => 'không kết nối được',
        };

        return Str::limit($reason, 120, '');
    }

    /** Đọc đệm. Lỗi đệm (driver hỏng) ⇒ coi như không có đệm rồi đi lấy thật. */
    private function readCache(WebSource $source): ?array
    {
        try {
            $hit = Cache::get($this->cacheKey($source));

            return is_array($hit) ? $hit : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Bản lấy THÀNH CÔNG gần nhất, giữ lâu hơn — nguồn chết tạm thời vẫn còn dữ liệu mà dùng. */
    private function readLastGood(WebSource $source): ?array
    {
        try {
            $hit = Cache::get($this->cacheKey($source).':last');

            return is_array($hit) && ($hit['items'] ?? []) !== [] ? $hit : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function writeCache(WebSource $source, array $result): void
    {
        try {
            // Đệm CẢ kết quả lỗi (ngắn hơn): nguồn chết không bị gọi lại liên tục mỗi lần mở màn hình.
            Cache::put(
                $this->cacheKey($source),
                $result,
                now()->addMinutes($result['ok'] ? self::CACHE_MINUTES : self::FAIL_CACHE_MINUTES),
            );

            if ($result['ok'] && ($result['items'] ?? []) !== []) {
                Cache::put(
                    $this->cacheKey($source).':last',
                    ['items' => $result['items'], 'fetched_at' => now()->toISOString()],
                    now()->addHours(self::LAST_GOOD_HOURS),
                );
            }
        } catch (\Throwable) {
            // Bộ đệm là tối ưu, không phải điều kiện chạy.
        }
    }

    private function cacheKey(WebSource $source): string
    {
        return self::CACHE_PREFIX.$source->id.':'.md5($source->url.'|'.(string) $source->updated_at);
    }

    /**
     * Đọc RSS/Atom. Dùng SimpleXML với libxml nội bộ; lỗi cú pháp ⇒ trả [] (nguồn hỏng, không phải app hỏng).
     *
     * Hỗ trợ cả ba họ feed đang gặp thật: RSS 2.0 (channel/item) · RSS 1.0/RDF (item ở gốc) · Atom (entry).
     *
     * @return list<array{title:string, url:string, published_at:?string, summary:string}>
     */
    private function parseRss(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        // Feed khai encoding khác UTF-8 (windows-1258, ISO-8859-1) làm simplexml trả false và CẢ nguồn
        // thành "0 tin" mà không ai biết vì sao. Đổi về UTF-8 trước khi parse.
        $body = $this->toUtf8($body);

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
            $nodes = $xml->item;                     // RSS 1.0 / RDF
        } elseif (isset($xml->entry)) {
            $nodes = $xml->entry;                    // Atom
        }

        $out = [];
        foreach ($nodes as $node) {
            $title = $this->clean((string) ($node->title ?? ''), 200);
            $link = $this->linkOf($node);
            $date = (string) ($node->pubDate ?? $node->published ?? $node->updated ?? $node->date ?? '');
            // content:encoded (RSS mở rộng) thường chứa nội dung đầy đủ hơn description.
            $summary = $this->clean((string) ($node->description ?? $node->summary ?? $node->content ?? $node->encoded ?? ''), 400);

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
     * Link của một mục: RSS để trong thẻ link, Atom để ở thuộc tính href — và Atom có THỂ có nhiều thẻ
     * link (self · enclosure · alternate) nên phải chọn đúng cái dẫn tới bài viết, không lấy cái đầu tiên.
     */
    private function linkOf(\SimpleXMLElement $node): string
    {
        $direct = trim((string) ($node->link ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $fallback = '';
        foreach ($node->link as $link) {
            $href = trim((string) ($link['href'] ?? ''));
            if ($href === '') {
                continue;
            }
            $rel = mb_strtolower(trim((string) ($link['rel'] ?? 'alternate')));
            if ($rel === 'alternate' || $rel === '') {
                return $href;
            }
            $fallback = $fallback !== '' ? $fallback : $href;
        }

        if ($fallback !== '') {
            return $fallback;
        }

        // RDF/RSS 1.0 đặt URL bài viết ở thuộc tính rdf:about của chính mục.
        $attributes = $node->attributes('rdf', true);
        $about = trim((string) ($attributes['about'] ?? ''));

        return $about !== '' ? $about : trim((string) ($node->id ?? ''));
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
            $haystack = trim($item['title'].' '.$item['summary']);

            if ($keywords !== [] && ! VietnameseText::mentionsAny($keywords, $haystack)) {
                continue;
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

    /**
     * Trạng thái một nguồn để giao diện nói THẬT.
     *
     * NĂM trạng thái, không phải hai: nguồn chết · nguồn không có tin · tin bị LỌC hết · đang dùng bản cũ ·
     * bỏ qua vì khác vùng. Chỉ có "ok/không ok" thì người cấu hình không biết phải sửa ở đâu.
     */
    private function statusFromResult(WebSource $source, array $row): array
    {
        $ok = (bool) ($row['ok'] ?? false);
        $count = count($row['items'] ?? []);
        $stale = (bool) ($row['stale'] ?? false);
        $parsed = (int) ($row['parsed'] ?? 0);

        $state = match (true) {
            ! $ok && $stale => 'stale',
            ! $ok => 'error',
            $count > 0 => 'live',
            $parsed > 0 => 'filtered',
            default => 'empty',
        };
        $labels = [
            'live' => 'Đang dùng',
            'filtered' => 'Bị bộ lọc loại hết',
            'empty' => 'Nguồn không có tin',
            'stale' => 'Đang dùng bản lấy trước',
            'error' => 'Không lấy được',
        ];

        return $this->status($source, $ok, $row['http'] ?? null, (int) ($row['ms'] ?? 0), $count, $row['error'] ?? null, [
            'state' => $state,
            'state_label' => $labels[$state],
            'parsed' => $parsed,
            'dropped' => (int) ($row['dropped'] ?? 0),
            'stale' => $stale,
            'stale_at' => $row['stale_at'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function status(WebSource $source, bool $ok, ?int $http, int $ms, int $count, ?string $error, array $extra = []): array
    {
        return array_merge([
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
        ], $extra);
    }

    /**
     * Làm sạch nội dung ngoài trước khi đưa vào prompt.
     *
     * THỨ TỰ QUAN TRỌNG: bỏ thẻ TRƯỚC, giải mã thực thể SAU. Làm ngược lại thì "&lt;img onerror=…&gt;" được
     * giải mã thành thẻ thật SAU khi đã bỏ thẻ — tức là thẻ HTML sống lại đúng trong prompt, phá luôn lớp
     * chống prompt-injection mà lớp này tuyên bố có. Sau đó bỏ ký tự điều khiển/vô hình (zero-width) vì
     * chúng dùng để che chữ hoặc phá khung dữ liệu.
     */
    private function clean(string $text, int $limit): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, $limit, '');
    }

    /** Chuyển body về UTF-8 theo khai báo trong XML (feed không phải UTF-8 vẫn đọc được). */
    private function toUtf8(string $body): string
    {
        if (! preg_match('/encoding\s*=\s*["\']([^"\']+)["\']/i', substr($body, 0, 200), $m)) {
            return $body;
        }

        $charset = mb_strtoupper(trim($m[1]));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8') {
            return $body;
        }

        $converted = @mb_convert_encoding($body, 'UTF-8', $charset);

        return is_string($converted) && $converted !== '' ? $converted : $body;
    }

    /**
     * Ngày về ISO-8601 (kèm giờ) để prompt luôn có THỜI ĐIỂM; không đọc được thì null.
     *
     * Vì sao không dùng thẳng strtotime: "05/09/2026" bị hiểu theo kiểu Mỹ thành 9 tháng 5 (sai một cách
     * im lặng), còn "22/09/2026" thì trả false. Feed Việt Nam ghi ngày kiểu ngày/tháng/năm, nên phải thử
     * ĐÚNG định dạng ngày/tháng trước rồi mới tới các định dạng chuẩn. Ngày ở TƯƠNG LAI bị kéo về hiện tại:
     * một feed ghi sai năm 2030 sẽ đứng đầu mọi danh sách và làm hỏng cả thứ tự "mới nhất trước".
     */
    private function isoDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $ts = false;
        $formats = ['d/m/Y H:i', 'd/m/Y', 'd-m-Y H:i', 'd-m-Y', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d'];
        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($parsed instanceof \DateTimeImmutable) {
                $ts = $parsed->getTimestamp();
                break;
            }
        }
        if ($ts === false) {
            $ts = strtotime($raw);
        }
        if ($ts === false) {
            return null;
        }

        $now = time();

        return date('c', min($ts, $now));
    }

    /** Khoá chống trùng theo URL đã chuẩn hoá (bỏ tham số tracking — cùng bài, khác nguồn chia sẻ). */
    private function dedupeKey(array $item): string
    {
        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return mb_strtolower($url);
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ref'] as $tracking) {
            unset($query[$tracking]);
        }
        ksort($query);

        $normalised = mb_strtolower((string) $parts['host']).(string) ($parts['path'] ?? '');
        $normalised = rtrim($normalised, '/');
        if ($query !== []) {
            $normalised .= '?'.http_build_query($query);
        }

        return $normalised;
    }

    /** Khoá phụ theo TIÊU ĐỀ: cùng một bài qua Google News và qua báo gốc có hai URL khác nhau. */
    private function titleKey(array $item): string
    {
        $title = VietnameseText::flatten((string) ($item['title'] ?? ''));
        if (mb_strlen($title) < 12) {
            return '';
        }

        return mb_substr($title, 0, 120);
    }

    /**
     * Danh sách mặc định — dùng cho lệnh studio:web-sources --seed và nút "Thêm nguồn mẫu".
     *
     * Đã ĐO trên production (2026-09-23): 3 nguồn dưới đây trả 200 và có tin thật;
     * vnexpress.net/rss/thoi-trang.rss trả 200 nhưng 0 item và thanhnien.vn/rss/thoi-trung.rss trả 404
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
