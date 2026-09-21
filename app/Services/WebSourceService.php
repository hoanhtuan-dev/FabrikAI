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

    /** Số tin ĐỌC ĐƯỢC giữ trong đệm mỗi nguồn (trước khi cắt theo trần của từng việc). */
    private const STORED_ITEMS = 60;

    /**
     * Trần mỗi nguồn khi ĐO tín hiệu thị trường — rộng hơn trần đưa vào prompt (mặc định 5–8 tin).
     *
     * Vì sao phải rộng: đo trên 8 tin thì gần như không từ khoá nào lặp lại ≥2 lần ⇒ không có hướng nào
     * sinh từ tin, và người dùng thấy toàn hướng của bộ có sẵn dù đã nối nguồn thật (đo trên production
     * 2026-09-20: 3 nguồn trả về 210 tin, nhưng máy đo chỉ nhận 16 tin ⇒ 1 hướng từ tin).
     */
    public const MEASURE_PER_SOURCE = 30;

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
    public function evidence(string $region = 'all', int $limit = self::EVIDENCE_LIMIT, bool $force = false, ?int $perSourceCap = null): array
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
            // Nguồn TÌM KIẾM chỉ có nghĩa khi có từ khoá điền vào (`{query}`); gọi thẳng URL đó ở đường đọc
            // tin cố định là đi hỏi internet đúng chuỗi "{query}" rồi nhận về rác. Bỏ qua và NÓI RA lý do.
            // Kiểu `search` bị bỏ qua ở đây LUÔN (kể cả khi admin quên `{query}` trong URL) — nó là làn
            // TÌM KIẾM, không phải feed.
            if ($source->kind === 'search' || str_contains((string) $source->url, self::SEARCH_PLACEHOLDER)) {
                $statuses[] = $this->status($source, false, null, 0, 0, 'bỏ qua: nguồn tìm kiếm theo từ khoá', [
                    'state' => 'skipped',
                    'state_label' => 'Bỏ qua (chỉ dùng khi model gọi công cụ tìm kiếm)',
                ]);
                continue;
            }
            $usable[] = $source;
        }

        // TRẦN MỖI NGUỒN cho lần gọi này. Việc ĐO cần bản rộng hơn việc đưa vào prompt (đo trên 8 tin thì
        // gần như không từ khoá nào đủ 2 tin để thành hướng); nơi gọi đo truyền trần riêng.
        $cap = $perSourceCap !== null && $perSourceCap > 0 ? $perSourceCap : null;
        $fetched = $this->fetchMany($usable, $force, $cap);
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
            // LỊCH CHẠY NỀN: số ĐO, không phải lời hứa. Giao diện đọc khối này để nói đúng việc máy chủ
            // đang làm — có cron thì "tự động mỗi 30 phút", không có thì "khi mở màn hình / bấm làm mới".
            'auto_refresh' => studio_scheduler_alive()
                ? ['alive' => true, 'label' => 'Máy chủ tự làm mới tin mỗi 30 phút, kể cả khi bạn không mở trang.']
                : ['alive' => false, 'label' => 'Máy chủ chưa bật lịch chạy nền — tin được làm mới khi bạn mở màn hình hoặc bấm «Cập nhật tin».'],
        ];
    }

// ───────────────────────── TÌM THEO TỪ KHOÁ (CÔNG CỤ web_search) ─────────────────────────

    /**
     * Chỗ điền từ khoá trong URL nguồn. Nguồn nào có `{query}` (hoặc sẵn tham số `q=`) thì trở thành
     * nguồn TÌM ĐƯỢC: máy chủ thay từ khoá của model vào rồi đi lấy, thay vì chỉ đọc feed cố định.
     */
    public const SEARCH_PLACEHOLDER = '{query}';

    /** Trần tin trả về cho MỘT truy vấn — kết quả này đi thẳng vào prompt nên phải ngắn. */
    public const SEARCH_LIMIT = 6;

    /** Trần số nguồn chạy cho một truy vấn: ba nguồn là đủ, và mỗi nguồn là một lần gọi mạng. */
    private const SEARCH_MAX_SOURCES = 3;

    /** Đệm kết quả tìm theo (nguồn · từ khoá): model hay hỏi lại cùng câu trong một lượt chạy. */
    private const SEARCH_CACHE_MINUTES = 15;

    private const SEARCH_CACHE_PREFIX = 'studio:web-search:v1:';

    /** Tham số query string được coi là chỗ điền từ khoá khi URL không có `{query}`. */
    private const SEARCH_QUERY_KEYS = ['q', 'query', 'keyword', 'keywords'];

    /**
     * Nguồn này TÌM ĐƯỢC theo từ khoá không?
     *
     * Nhận hai dạng, cố ý KHÔNG cần cột cấu hình mới: `{query}` tường minh, hoặc URL đã có sẵn một
     * tham số từ khoá (`q=`) — dạng thứ hai làm nguồn Google News mặc định trở thành nguồn tìm kiếm
     * ngay mà không phải migrate dữ liệu đang chạy trên production.
     */
    public static function isSearchable(WebSource $source): bool
    {
        return self::querySlot((string) $source->url) !== null;
    }

    /** Tên chỗ điền từ khoá trong URL ('{query}' hoặc tên tham số), null = không tìm được. */
    private static function querySlot(string $url): ?string
    {
        if (str_contains($url, self::SEARCH_PLACEHOLDER)) {
            return self::SEARCH_PLACEHOLDER;
        }

        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return null;
        }

        parse_str($query, $params);
        foreach (self::SEARCH_QUERY_KEYS as $key) {
            if (isset($params[$key]) && is_string($params[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Danh sách nguồn TÌM ĐƯỢC đang bật cho một vùng — theo đúng thứ tự ưu tiên như đường đọc tin.
     *
     * @return list<WebSource>
     */
    public function searchableSources(string $region = 'all'): array
    {
        return WebSource::query()
            ->where('enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (WebSource $source) => $source->matchesRegion($region) && self::isSearchable($source))
            ->values()
            ->all();
    }

    /**
     * TÌM THẬT theo từ khoá — đây là việc máy chủ làm khi model gọi công cụ `web_search`.
     *
     * Vì sao ở đây chứ không ở lớp công cụ: mọi ràng buộc an toàn của việc ra internet (chỉ http/https,
     * chặn địa chỉ nội bộ, trần dung lượng, đệm, làm sạch nội dung) đã nằm ở lớp này. Viết một đường HTTP
     * thứ hai cho công cụ là tạo bản sao lệch chuẩn — đúng loại lỗi đã gặp nhiều lần trong dự án.
     *
     * KHÁC đường đọc tin ở MỘT điểm có chủ ý: KHÔNG lọc theo từ khoá khai trong nguồn. Từ khoá của nguồn
     * dùng để giữ feed tin chung cho đúng chủ đề; còn ở đây chính TRUY VẤN đã là bộ lọc (nguồn tìm kiếm
     * trả về đúng thứ được hỏi) — lọc thêm bằng từ khoá cấu hình sẽ nuốt mất kết quả đúng.
     *
     * @return array{query:string, mode:string, count:int, parsed:int, dropped:int, items:list<array<string,mixed>>, sources:list<array<string,mixed>>, checked_at:string, error:?string}
     */
    public function search(string $query, string $region = 'all', ?int $limit = null): array
    {
        $query = $this->normalizeQuery($query);
        $limit = max(1, min(20, $limit ?? self::SEARCH_LIMIT));
        $out = [
            'query' => $query, 'mode' => 'empty', 'count' => 0,
            // Số ĐO để phân biệt "internet không có gì" với "có tin nhưng đều quá cũ" — hai chuyện rất khác
            // nhau, và gộp chúng thành "0 kết quả" là nói thiếu sự thật.
            'parsed' => 0, 'dropped' => 0,
            'items' => [], 'sources' => [],
            'checked_at' => now()->toISOString(), 'error' => null,
        ];

        if ($query === '') {
            $out['error'] = 'từ khoá rỗng';

            return $out;
        }

        $targets = array_slice($this->searchableSources($region), 0, self::SEARCH_MAX_SOURCES);
        if ($targets === []) {
            // Chưa khai nguồn tìm kiếm nào = một trạng thái CẤU HÌNH, phải nói ra chứ không im lặng.
            $out['error'] = 'chưa có nguồn tìm kiếm nào được bật';

            return $out;
        }

        $items = [];
        $seen = [];
        $seenTitles = [];
        $okCount = 0;

        foreach ($targets as $source) {
            $row = $this->searchOnce($source, $query, $limit);
            $out['sources'][] = $this->statusFromResult($source, $row);
            $out['parsed'] += (int) ($row['parsed'] ?? 0);
            $out['dropped'] += (int) ($row['dropped'] ?? 0);
            if (($row['ok'] ?? false) === true) {
                $okCount++;
            }

            foreach ((array) ($row['items'] ?? []) as $item) {
                $key = $this->dedupeKey($item);
                $titleKey = $this->titleKey($item);
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

        usort($items, fn (array $a, array $b) => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));
        $out['items'] = array_slice($items, 0, $limit);
        $out['count'] = count($out['items']);
        // mode=live CHỈ khi có tin thật: model phải đọc được "tìm rồi mà không có gì" khác "không tìm được".
        $out['mode'] = $out['count'] > 0 ? 'live' : 'empty';
        if ($out['count'] === 0 && $okCount === 0) {
            $out['error'] = 'không nguồn tìm kiếm nào trả lời được';
        }

        return $out;
    }

    /**
     * LẤY THỬ MỘT NGUỒN TÌM KIẾM bằng một từ khoá mẫu — cho nút "Lấy thử" ở Cài đặt.
     *
     * Vì sao cần đường riêng: nguồn tìm kiếm có `{query}` trong URL nên gọi thẳng URL đó là đi hỏi internet
     * đúng chuỗi "{query}". Người khai nguồn cần thấy NGAY là cấu hình đúng hay sai, kèm HTTP và số tin —
     * cấu hình mù là cấu hình sẽ hỏng lúc chạy thật.
     *
     * KHÔNG dùng đệm: đây là phép THỬ, phải là kết quả của lúc bấm.
     *
     * @return array<string, mixed>
     */
    public function fetchWithQuery(WebSource $source, string $query): array
    {
        // Chưa có khoá thì nói NGAY và nói ĐÚNG VIỆC CẦN LÀM — gọi ra API chỉ để nhận 403 rồi hiện câu lỗi
        // tiếng Anh của Google là bắt người khai tự đoán.
        if ($source->kind === 'search' && $this->searchKeyFor($source) === null) {
            return [
                'ok' => false, 'http' => null, 'ms' => 0, 'items' => [], 'parsed' => 0, 'dropped' => 0, 'stale' => false,
                'error' => 'chưa có khoá API cho nguồn này — thêm ở Cài đặt → API key với provider = '.$source->slug,
            ];
        }

        $target = $this->withQuery($source, $query);
        $started = microtime(true);

        try {
            return $this->interpretSearch($target, $this->request($target), $started, self::SEARCH_LIMIT);
        } catch (\Throwable $e) {
            return $this->failure($source, $e, $started);
        }
    }

    /**
     * Một truy vấn trên MỘT nguồn, CÓ ĐỆM riêng theo (nguồn · từ khoá).
     *
     * Vì sao không dùng `fetch()`: đệm của `fetch()` khoá theo nguồn nên hai truy vấn khác nhau sẽ nuốt
     * kết quả của nhau; và nó ghi thêm bản "lấy tốt nhất 24 giờ" — với từ khoá tuỳ ý thì đó là rác đệm
     * không bao giờ đọc lại.
     *
     * @return array<string, mixed>
     */
    private function searchOnce(WebSource $source, string $query, int $limit): array
    {
        // Nguồn tìm kiếm thiếu khoá ⇒ trả lý do đọc được thay vì gọi ra API rồi nhận 403.
        if ($source->kind === 'search' && $this->searchKeyFor($source) === null) {
            return [
                'ok' => false, 'http' => null, 'ms' => 0, 'items' => [], 'parsed' => 0, 'dropped' => 0, 'stale' => false,
                'error' => 'chưa có khoá API cho nguồn này — thêm ở Cài đặt → API key với provider = '.$source->slug,
            ];
        }

        $target = $this->withQuery($source, $query);
        $key = self::SEARCH_CACHE_PREFIX.md5($source->slug.'|'.$query.'|'.$target->url);

        try {
            $hit = Cache::get($key);
            if (is_array($hit)) {
                return $hit;
            }
        } catch (\Throwable) {
            // Đệm hỏng KHÔNG được làm hỏng việc tìm: đi lấy thật.
        }

        $started = microtime(true);
        try {
            $result = $this->interpretSearch($target, $this->request($target), $started, $limit);
        } catch (\Throwable $e) {
            // Nguồn chết là một KẾT QUẢ ĐO, không phải lỗi của tính năng.
            $result = $this->failure($source, $e, $started);
        }

        try {
            Cache::put($key, $result, now()->addMinutes(self::SEARCH_CACHE_MINUTES));
        } catch (\Throwable) {
            // Bộ đệm là tối ưu tốc độ.
        }

        return $result;
    }

    /** Bản sao nguồn với từ khoá đã điền vào URL (không sửa bản ghi thật trong DB). */
    private function withQuery(WebSource $source, string $query): WebSource
    {
        $clone = clone $source;
        $url = (string) $source->url;
        $slot = self::querySlot($url);

        if ($slot === self::SEARCH_PLACEHOLDER) {
            $clone->url = str_replace(self::SEARCH_PLACEHOLDER, rawurlencode($query), $url);

            return $clone;
        }

        if ($slot === null) {
            $clone->url = $url;

            return $clone;
        }

        // Thay ĐÚNG tham số từ khoá, giữ nguyên phần còn lại của query string (hl/gl/ceid/format…).
        $clone->url = (string) preg_replace_callback(
            '/([?&]'.preg_quote($slot, '/').'=)[^&#]*/u',
            fn (array $m) => $m[1].rawurlencode($query),
            $url,
            1,
        );

        return $clone;
    }

    /**
     * Đọc phản hồi của một truy vấn thành kết quả chuẩn.
     *
     * Giống `interpret()` ở mọi ràng buộc (trần dung lượng, làm sạch nội dung, phân biệt lỗi), KHÁC ở chỗ
     * không áp bộ lọc từ khoá của nguồn — xem chú thích ở `search()`.
     *
     * @return array<string, mixed>
     */
    private function interpretSearch(WebSource $source, Response $response, float $started, int $limit): array
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
        } elseif ($source->kind !== 'rss' && ! $this->looksLikeJson($body)) {
            // Xem chú thích ở interpret(): trả về HTML thì DỪNG, đừng "đọc" ra mục rác rồi im lặng báo 0 tin.
            $result['error'] = $this->readErrorReason($source, $body, $response->status());
            $result['ms'] = (int) round((microtime(true) - $started) * 1000);

            return $result;
        } else {
            // Cùng lý do như interpret(): 'search' cũng là API JSON.
            $parsed = $source->kind === 'rss' ? $this->parseRss($body) : $this->parseJson($body, $source);
            $result['parsed'] = count($parsed);
            $result['ok'] = true;

            $cutoff = now()->subDays(self::MAX_AGE_DAYS)->getTimestamp();
            $kept = [];
            foreach ($parsed as $item) {
                if ($item['published_at'] !== null) {
                    $ts = strtotime((string) $item['published_at']);
                    if ($ts !== false && $ts < $cutoff) {
                        continue;
                    }
                }
                $kept[] = $item + ['source' => $source->slug, 'source_name' => $source->name];
            }

            usort($kept, fn (array $a, array $b) => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));
            $result['dropped'] = max(0, count($parsed) - count($kept));
            $result['items'] = array_slice($kept, 0, max(1, min(30, $limit)));
            if ($parsed === [] && trim($body) !== '') {
                // LỖI PHẢI NÓI ĐÚNG BỆNH: ba nguyên nhân rất khác nhau và cách sửa cũng khác nhau.
                //   · URL trả HTML  ⇒ đang trỏ vào TRANG WEB của dịch vụ, không phải endpoint API;
                //   · API trả lỗi   ⇒ thiếu/sai khoá, hết hạn mức, engine chưa bật tìm toàn web;
                //   · JSON đúng nhưng ánh xạ sai ⇒ items_path/*_field chưa khớp.
                // Đo thật 2026-09-21: nguồn Google khai URL cse.google.com (trang HTML) mà báo
                // "nội dung không phải RSS/Atom đọc được" — câu đó chỉ đúng với nguồn RSS và không giúp
                // người khai biết phải sửa gì.
                $result['error'] = $this->readErrorReason($source, $body, $response->status());
            }
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    /**
     * Chuẩn hoá từ khoá do MODEL đưa vào — đầu vào không đáng tin: cắt ký tự điều khiển, gộp khoảng
     * trắng, chặn chuỗi dài bất thường. Rỗng = không tìm gì cả (nơi gọi phải nói thật là không tìm được).
     */
    private function normalizeQuery(string $query): string
    {
        $query = str_replace(["\r", "\n", "\t"], ' ', $query);
        $query = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $query);
        $query = (string) preg_replace('/\s+/u', ' ', $query);
        $query = trim($query);
        $query = strip_tags($query);

        // Trần 120 ký tự: một "truy vấn" dài hơn thế là model đang dán cả đoạn văn, không phải từ khoá.
        return mb_substr($query, 0, 120);
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
    public function fetch(WebSource $source, bool $force = false, ?int $perSourceCap = null): array
    {
        if (! $force) {
            $hit = $this->readCache($source);
            if ($hit !== null) {
                return $this->withCap($hit, $source, $perSourceCap);
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

        return $this->withCap($result, $source, $perSourceCap);
    }

    /**
     * Áp TRẦN của lần gọi này lên kết quả đã có — cắt lại từ danh sách ĐÃ ĐỌC (parsed_items) chứ không
     * phải đi mạng lần nữa.
     *
     * Vì sao: bộ đệm giữ bản ĐỌC ĐƯỢC (rộng), còn trần thì khác nhau theo việc — prompt chỉ cần 8 tin mỗi
     * nguồn, nhưng máy ĐO cần bản rộng hơn nhiều mới thấy được từ khoá nào lặp lại. Đệm theo trần của
     * người gọi đầu tiên là cách chắc chắn nhất để việc đo luôn chỉ có 8 tin.
     */
    private function withCap(array $row, WebSource $source, ?int $perSourceCap): array
    {
        if ($perSourceCap === null || $perSourceCap <= 0 || ! isset($row['parsed_items']) || ($row['items'] ?? []) === []) {
            return $row;
        }

        $row['items'] = $this->filter((array) $row['parsed_items'], $source, $perSourceCap);

        return $row;
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
    private function fetchMany(array $sources, bool $force, ?int $perSourceCap = null): array
    {
        $out = [];
        $pending = [];
        foreach ($sources as $source) {
            if (! $force) {
                $hit = $this->readCache($source);
                if ($hit !== null) {
                    $out[$source->id] = $this->withCap($hit, $source, $perSourceCap);
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
            $out[$only->id] = $this->fetch($only, true, $perSourceCap);

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
                $out[$source->id] = $this->fetch($source, true, $perSourceCap);
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
            $out[$source->id] = $this->withCap($result, $source, $perSourceCap);
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
            ->get($this->withSearchKey($source));
    }

    /** Yêu cầu đơn (đường fetch một nguồn). */
    private function request(WebSource $source): Response
    {
        $this->assertPublicUrl($source->url);

        return Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withHeaders(['User-Agent' => 'FabrikAI/1.0 (+https://fabrikai.shop)'])
            ->withOptions($this->options())
            ->get($this->withSearchKey($source));
    }

    /**
     * URL gọi thật của một nguồn — GẮN KHOÁ API cho nguồn TÌM KIẾM, và chỉ ở đây.
     *
     * Vì sao khoá KHÔNG nằm trong `web_sources.url`: cột đó hiện nguyên văn trên màn Cài đặt, đi vào
     * payload API, vào bảng trạng thái nguồn và vào log — dán khoá vào đó là phơi khoá ở 4 chỗ. Khoá đọc
     * từ bảng API key (đã mã hoá) theo provider = slug của nguồn, rồi chỉ xuất hiện trong URL của ĐÚNG
     * lời gọi HTTP này.
     */
    /** Khoá API của một nguồn TÌM KIẾM: slot theo SLUG của nguồn trước, rồi tới slot chung `google_cse`. */
    private function searchKeyFor(WebSource $source): ?string
    {
        if (($source->kind ?? '') !== 'search') {
            return null;
        }

        foreach (array_unique(array_filter([trim((string) $source->slug), 'google_cse'], 'strlen')) as $ref) {
            $key = function_exists('studio_api_key') ? studio_api_key($ref) : null;
            if ($key) {
                return (string) $key;
            }
        }

        return null;
    }

    private function withSearchKey(WebSource $source): string
    {
        $url = (string) $source->url;

        if (($source->kind ?? '') !== 'search') {
            return $url;
        }

        $key = $this->searchKeyFor($source);

        if (! $key) {
            // Không có khoá ⇒ gọi thẳng URL (API sẽ trả 401/403) và trạng thái nguồn nói rõ "Không lấy được".
            // KHÔNG tự chế khoá, KHÔNG im lặng coi như thành công.
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'key='.rawurlencode((string) $key);
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
        } elseif ($source->kind !== 'rss' && ! $this->looksLikeJson($body)) {
            // Nguồn JSON/TÌM KIẾM mà trả về HTML (hoặc bất cứ thứ gì không phải JSON) ⇒ DỪNG, không cố đọc.
            //
            // [LỖI THẬT — đo trên production 2026-09-21] Nguồn Google khai URL trang HTML: bộ đọc JSON vẫn
            // "đọc" ra vài mục rác từ trang đó, nên `parsed > 0` và KHÔNG có lỗi nào được báo — màn hình chỉ
            // hiện "0 tin" trơ trọi. Tệ hơn cả báo lỗi: người khai không biết đường nào mà sửa.
            $result['error'] = $this->readErrorReason($source, $body, $response->status());
        } else {
            // CHỈ 'rss' mới đi đường RSS: 'json' VÀ 'search' đều là API trả JSON. Viết `=== 'json'` thì nguồn
            // tìm kiếm rơi vào bộ đọc RSS và luôn ra 0 tin dù API trả 200 — lỗi im lặng đúng kiểu khó thấy.
            $parsed = $source->kind === 'rss' ? $this->parseRss($body) : $this->parseJson($body, $source);
            $result['parsed'] = count($parsed);
            $result['ok'] = true;
            // GIỮ BẢN ĐỌC ĐƯỢC (rộng) trong đệm: việc cắt theo trần là rẻ, việc đi mạng là đắt. Nhờ vậy lần
            // sau muốn đo trên bản rộng hơn (máy đo tín hiệu) thì cắt lại từ đây, không phải gọi lại nguồn.
            $result['parsed_items'] = array_slice($parsed, 0, self::STORED_ITEMS);
            $filtered = $this->filter($parsed, $source);
            $result['dropped'] = max(0, count($parsed) - count($filtered));
            $result['items'] = $filtered;
            if ($parsed === [] && trim($body) !== '') {
                // Đọc được HTTP nhưng KHÔNG đọc được mục nào: định dạng lạ, ánh xạ sai, hoặc URL trỏ nhầm
                // vào trang HTML. Nói ra để người cấu hình biết mà sửa, không im lặng.
                $result['error'] = $this->readErrorReason($source, $body, $response->status());
            }
        }

        $result['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    /**
     * Thân phản hồi có ĐÚNG ĐỊNH DẠNG JSON không (đủ để quyết định có nên cố đọc hay không).
     *
     * Đây là hàng rào chống "đọc rác thành tin": trang HTML, trang lỗi của proxy, hay một khối text đều có
     * thể lọt qua bộ đọc JSON và sinh ra vài mục vô nghĩa — mà có mục thì lớp báo lỗi im lặng.
     */
    private function looksLikeJson(string $body): bool
    {
        $head = ltrim($body);

        return $head !== '' && ($head[0] === '{' || $head[0] === '[');
    }

    /**
     * Vì sao đọc được phản hồi mà KHÔNG ra mục nào — câu trả lời phải chỉ đúng việc cần sửa.
     *
     * @param  array<string, mixed>|null  $json
     */
    private function readErrorReason(WebSource $source, string $body, ?int $status): string
    {
        $trimmed = ltrim($body);

        if (str_starts_with($trimmed, '<')) {
            return 'URL này trả về TRANG HTML, không phải API JSON — hãy dùng endpoint API (vd Google: https://www.googleapis.com/customsearch/v1?cx=…&q={query})';
        }

        $json = json_decode($body, true);
        $apiError = is_array($json) ? data_get($json, 'error.message') : null;
        if (is_string($apiError) && trim($apiError) !== '') {
            return 'API trả lỗi: '.Str::limit(trim($apiError), 160, '');
        }

        // Nguồn TÌM KIẾM luôn là JSON: câu "không phải RSS/Atom" ở đây là nói sai loại nguồn.
        if ($source->kind !== 'rss') {
            return 'không đọc được mục nào theo ánh xạ đã khai (kiểm tra items_path và các trường *_field)';
        }

        return 'nội dung không phải RSS/Atom đọc được';
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

        return $this->stripPublisher($out);
    }

    /**
     * BỎ TÊN TOÀ SOẠN khỏi tiêu đề và mô tả (nguồn tổng hợp như Google News trả "Bài viết - Kenh14.vn").
     *
     * Vì sao phải làm ở đây: tên toà soạn là RÁC ở mọi đường — nó vào prompt (model đọc thấy "Kenh14.vn"),
     * vào máy đo tín hiệu (thành một "chủ đề thị trường" tên là "kenh14 vn"), và vào cả tiêu đề hiển thị.
     * Nhận diện bằng DỮ LIỆU, không bằng danh sách cứng: phần đuôi sau dấu gạch mà LẶP LẠI ở ≥2 tin trong
     * cùng lượt thì gần như chắc chắn là tên nguồn đăng, không phải nội dung bài.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function stripPublisher(array $items): array
    {
        $tails = [];
        foreach ($items as $item) {
            $tail = $this->publisherTail((string) ($item['title'] ?? ''));
            if ($tail !== null) {
                $key = mb_strtolower($tail);
                $tails[$key] = ($tails[$key] ?? 0) + 1;
            }
        }

        $publishers = array_keys(array_filter($tails, fn (int $count) => $count >= 2));
        if ($publishers === []) {
            return $items;
        }

        foreach ($items as $index => $item) {
            $title = (string) ($item['title'] ?? '');
            $tail = $this->publisherTail($title);
            if ($tail !== null && in_array(mb_strtolower($tail), $publishers, true)) {
                $items[$index]['title'] = trim(mb_substr($title, 0, mb_strlen($title) - mb_strlen($tail) - 3));
            }
            // Mô tả của nguồn tổng hợp thường là TIÊU ĐỀ + tên toà soạn ⇒ bỏ luôn tên đó khỏi mô tả.
            $summary = (string) ($item['summary'] ?? '');
            foreach ($publishers as $publisher) {
                $summary = (string) preg_replace(
                    '/\s*[-–|]?\s*'.preg_quote($this->mixtureCase($publisher, $summary), '/').'\s*$/iu',
                    '',
                    $summary,
                );
            }
            $items[$index]['summary'] = trim($summary);
        }

        return $items;
    }

    /** Trả về CHÍNH chuỗi xuất hiện trong văn bản (khác hoa/thường) để thay thế đúng chỗ. */
    private function mixtureCase(string $needle, string $haystack): string
    {
        if ($needle === '' || $haystack === '') {
            return $needle;
        }

        return preg_match('/'.preg_quote($needle, '/').'/iu', $haystack, $m) ? $m[0] : $needle;
    }

    /** Đuôi sau dấu gạch cuối của tiêu đề — nghi là tên toà soạn ("Bài viết - Kenh14.vn"). */
    private function publisherTail(string $title): ?string
    {
        if (! preg_match('/\s[-–|]\s([^-–|]{3,40})$/u', trim($title), $m)) {
            return null;
        }

        return trim($m[1]);
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
    private function filter(array $items, WebSource $source, ?int $cap = null): array
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

        // Trần của lần gọi này (việc ĐO xin bản rộng) — mặc định vẫn là trần của nguồn.
        $limit = $cap !== null && $cap > 0 ? min(60, $cap) : max(1, min(50, (int) $source->max_items));

        return array_slice($out, 0, max(1, $limit));
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
