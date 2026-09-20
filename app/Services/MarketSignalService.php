<?php

namespace App\Services;

use App\Models\MarketSignal;
use App\Support\VietnameseText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TÍN HIỆU THỊ TRƯỜNG ĐO TỪ NGUỒN NGOÀI — biến TIN thành DỮ LIỆU, không cần model tìm kiếm web (2026-09-23).
 *
 * Vì sao có lớp này: model đang chạy trên production KHÔNG có tìm kiếm web thật (đo: tham số tìm kiếm bị
 * bỏ qua). Trình kết nối nguồn ngoài đã đưa được TIN vào prompt, nhưng tin vẫn chỉ là CHỮ do model đọc —
 * hết model thì hết phân tích, và mọi con số vẫn là số mẫu của bộ xu hướng có sẵn.
 *
 * Lớp này ĐO bằng thuật toán (không gọi model, không tốn token, tái lập được 100%):
 *   · từ khoá ngành nào đang được nhắc tới, bao nhiêu tin, từ bao nhiêu nguồn, tin nào;
 *   · tăng hay giảm so với kỳ trước (chỉ trả lời được khi có LỊCH SỬ đo, nên mỗi lần đo là một snapshot);
 *   · dải giá ghi nhận được TRONG TIN (giá thị trường đọc được, khác giá bán của shop).
 *
 * Ba nguyên tắc:
 *   1. KHÔNG bịa: không đo được thì trả rỗng và nói rõ "chưa có dữ liệu", không suy diễn thành số.
 *   2. Từ khoá khớp theo RANH GIỚI TỪ, không phải khớp chuỗi con: "áo" không được khớp trong "báo",
 *      "đầm" không được khớp trong "đầm phá" — khớp chuỗi con làm tin rác chảy vào phân tích.
 *   3. Đo lại CÙNG một bộ tin thì không ghi thêm snapshot (khoá theo dấu vân tay) ⇒ bảng không phình.
 */
class MarketSignalService
{
    /** Số tin tối đa dùng để ĐO (rộng hơn số tin đưa vào prompt: đo cần mẫu lớn hơn để đỡ nhiễu). */
    public const MARKET_LIMIT = 40;

    /** Cửa sổ lịch sử dùng để tính tăng/giảm. */
    public const HISTORY_DAYS = 14;

    /** Giữ lịch sử đo bao lâu rồi dọn — đủ để so sánh theo mùa, không để bảng phình mãi. */
    public const RETENTION_DAYS = 120;

    /** Đo lại tối đa bao lâu một lần dù tin chưa đổi (để vẫn có mẫu lịch sử mà so sánh). */
    public const SNAPSHOT_MAX_AGE_HOURS = 12;

    /** Trần số tín hiệu trả ra giao diện / prompt (chặn token và chặn giao diện rối). */
    public const SIGNAL_LIMIT = 12;

    /**
     * TỪ VỰNG NGÀNH — ĐÓNG, có chủ ý: mỗi từ khoá gắn sẵn một nhóm hàng, nên kết quả đo giải thích được
     * ("linen: 6 tin · chất liệu") thay vì một mớ từ khoá không rõ nghĩa. Thêm từ khoá = thêm một dòng ở đây,
     * không phải sửa thuật toán.
     *
     * Ưu tiên cụm từ (≥2 tiếng) cho các từ dễ nhiễu: "màu be" thay vì "be", "màu kem" thay vì "kem".
     */
    public const VOCABULARY = [
        'color' => [
            'pastel', 'màu be', 'beige', 'màu kem', 'trắng', 'đen', 'xanh', 'hồng', 'vàng', 'nâu',
            'đỏ', 'tím', 'xám', 'xanh rêu', 'xanh navy', 'xanh mint', 'tông đất', 'ánh kim', 'nude',
            'màu nóng', 'màu lạnh', 'hai màu', 'màu sắc',
        ],
        'silhouette' => [
            'ống rộng', 'suông', 'cropped', 'oversize', 'chữ a', 'midi', 'maxi', 'blazer', 'sơ mi',
            'áo thun', 'áo phông', 'áo len', 'áo dài', 'đầm', 'váy', 'chân váy', 'đầm dạ hội',
            'quần jean', 'quần tây', 'quần short', 'quần áo', 'jean', 'jumpsuit', 'cardigan', 'blouse',
            'polo', 'set bộ', 'áo khoác', 'hoodie', 'suit', 'vest', 'yếm', 'đồng phục', 'trang phục',
            'giày', 'túi xách', 'trang sức', 'phụ kiện',
        ],
        'fabric' => [
            'linen', 'cotton', 'lụa', 'satin', 'denim', 'len', 'dạ', 'tweed', 'voan', 'ren',
            'organza', 'vải thô', 'vải', 'chất liệu', 'kaki', 'polyester', 'spandex', 'bamboo', 'modal',
            'gấm', 'nhung', 'da thuộc', 'dệt kim', 'dệt may', 'tơ tằm', 'cashmere', 'vải tái chế',
        ],
        'detail' => [
            'thêu', 'in hoa', 'in 3d', 'xếp ly', 'bèo', 'túi', 'khoá kéo', 'cúc', 'đính đá', 'sequin',
            'sọc', 'kẻ', 'caro', 'họa tiết', 'trơn', 'thủ công', 'đan móc', 'cắt xẻ', 'phối màu',
        ],
        'style' => [
            'công sở', 'dạo phố', 'thể thao', 'tối giản', 'thanh lịch', 'vintage', 'retro',
            'streetwear', 'bền vững', 'secondhand', 'thời trang nhanh', 'local brand', 'hàn quốc',
            'sang trọng', 'quyến rũ', 'xu hướng', 'bộ sưu tập', 'thương hiệu', 'nhà thiết kế',
            'tuần lễ thời trang', 'sàn diễn', 'người mẫu', 'tái chế', 'may đo', 'thời trang xanh',
        ],
    ];

    /**
     * TỪ MỘT TIẾNG DỄ TRÙNG NGHĨA KHÁC — chỉ tính khi CÙNG BÀI có một từ khoá rõ nghĩa khác.
     *
     * Vì sao cần: khớp theo ranh giới từ đã chặn được "áo" trong "báo", nhưng không cứu được những từ mà
     * bản thân chúng là một TỪ riêng ở nghĩa khác: "đầm" (đầm phá) · "dạ" (dạ dày) · "da" (da thịt) ·
     * "kẻ" (kẻ gian) · "thô" (thô ráp) · "ren" (ren rỉ) · "len" (len lỏi) · "trơn" (trơn tru)…
     * Quy tắc: bài phải có ít nhất MỘT từ khoá rõ nghĩa của ngành thì các từ mơ hồ này mới được tính —
     * nhờ vậy tin kinh doanh chung không sinh ra "tín hiệu thời trang" giả.
     *
     * @var array<string, true>
     */
    public const AMBIGUOUS = [
        'đầm' => true, 'dạ' => true, 'da' => true, 'kẻ' => true, 'thô' => true, 'ren' => true, 'len' => true,
        'trơn' => true, 'sọc' => true, 'cúc' => true, 'túi' => true, 'bèo' => true, 'gấm' => true,
        'nhung' => true, 'lụa' => true, 'vải' => true, 'váy' => true, 'yếm' => true, 'giày' => true,
        'trắng' => true, 'đen' => true, 'xanh' => true, 'hồng' => true, 'vàng' => true, 'nâu' => true,
        'đỏ' => true, 'tím' => true, 'xám' => true,
    ];

    public const CATEGORY_LABELS = [
        'color' => 'Màu sắc',
        'silhouette' => 'Dáng',
        'fabric' => 'Chất liệu',
        'detail' => 'Chi tiết',
        'style' => 'Phong cách',
    ];

    /** Giá một món đồ may mặc nằm trong khoảng nào thì mới tính là giá sản phẩm (chặn "1.200 tấn", năm 2026…). */
    private const PRICE_MIN_VND = 20000;

    private const PRICE_MAX_VND = 500000000;

    /**
     * Tham số BẮT BUỘC-kiểu-nullable (không có default): container Laravel không tự inject tham số nullable
     * có default, nên "?WebSourceService $sources = null" sẽ khiến lớp này LUÔN chạy không có nguồn.
     * Test thuần PHPUnit truyền null tường minh.
     */
    public function __construct(private readonly ?WebSourceService $sources) {}

    // ─────────────────────────────────────────────────────────────────────────────
    // ĐO & LƯU
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Đo tín hiệu từ nguồn ngoài rồi LƯU một snapshot (nếu dữ liệu đã đổi hoặc đã cũ).
     *
     * @return array<string, mixed> báo cáo đọc được ngay (cùng dạng với report())
     */
    public function capture(string $region = 'all', bool $force = false): array
    {
        if ($this->sources === null) {
            return $this->emptyReport();
        }

        try {
            $evidence = $this->sources->evidence($region, self::MARKET_LIMIT, $force);
        } catch (\Throwable $e) {
            // Nguồn ngoài hỏng KHÔNG được làm hỏng lượt phân tích: dữ liệu cũ vẫn dùng được.
            $this->warn('không lấy được tin để đo', $e);

            return $this->report($region);
        }

        $items = (array) ($evidence['items'] ?? []);
        $measured = $this->extract($items);
        $fingerprint = (string) ($evidence['fingerprint'] ?? '');
        if ($fingerprint === '') {
            $fingerprint = md5(json_encode(array_column($items, 'url')) ?: '');
        }

        try {
            $previous = $this->query($region)->first();
            $stale = $previous === null
                || $previous->captured_at === null
                || $previous->captured_at->lt(Carbon::now()->subHours(self::SNAPSHOT_MAX_AGE_HOURS));

            if ($force || $previous === null || $previous->fingerprint !== $fingerprint || $stale) {
                MarketSignal::query()->create([
                    'region' => $region,
                    'window_days' => WebSourceService::MAX_AGE_DAYS,
                    'captured_at' => Carbon::now(),
                    'item_count' => $measured['item_count'],
                    'source_count' => $measured['source_count'],
                    'signals' => $measured['signals'],
                    'prices' => $measured['prices'],
                    'fingerprint' => $fingerprint,
                ]);
                $this->prune();
            }
        } catch (\Throwable $e) {
            // Bảng chưa migrate / DB đọc-only: vẫn phải trả về kết quả ĐO được, chỉ là không lưu lịch sử.
            $this->warn('không lưu được snapshot tín hiệu', $e);

            return $this->reportFrom($measured, Carbon::now(), $region);
        }

        return $this->report($region);
    }

    /**
     * Bảo đảm có dữ liệu đủ mới rồi trả báo cáo — dùng ở đường radar (KHÔNG đo lại nếu vừa đo xong).
     *
     * Vì sao cần: cron có thể chưa được bật ở nơi triển khai mới. Khi đó lần mở Agent Studio đầu tiên phải
     * tự đo, còn các lần sau thì đọc thẳng bản đã lưu (không thêm việc gì cho máy chủ).
     *
     * @return array<string, mixed>
     */
    public function ensureFresh(string $region = 'all', int $maxAgeHours = self::SNAPSHOT_MAX_AGE_HOURS): array
    {
        try {
            $latest = $this->query($region)->first();
        } catch (\Throwable $e) {
            $this->warn('không đọc được lịch sử tín hiệu', $e);

            return $this->emptyReport();
        }

        if ($latest === null || $latest->captured_at === null || $latest->captured_at->lt(Carbon::now()->subHours($maxAgeHours))) {
            return $this->capture($region);
        }

        return $this->report($region);
    }

    /** Xoá snapshot quá cũ — gọi sau mỗi lần ghi (không dọn thì bảng phình mãi theo thời gian). */
    public function prune(int $keepDays = self::RETENTION_DAYS): int
    {
        try {
            return MarketSignal::query()->where('captured_at', '<', Carbon::now()->subDays($keepDays))->delete();
        } catch (\Throwable $e) {
            $this->warn('không dọn được snapshot cũ', $e);

            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // BÁO CÁO (chỉ đọc)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Báo cáo tín hiệu của một vùng — KHÔNG đi mạng, chỉ đọc lịch sử đã đo.
     *
     * @return array<string, mixed>
     */
    public function report(string $region = 'all'): array
    {
        try {
            $snapshots = $this->history($region);
        } catch (\Throwable $e) {
            $this->warn('không đọc được lịch sử tín hiệu', $e);

            return $this->emptyReport();
        }

        $latest = $snapshots->first();
        if ($latest === null) {
            return $this->emptyReport();
        }

        return $this->buildReport(
            (array) $latest->signals,
            (array) $latest->prices,
            $snapshots,
            $region,
            $latest->captured_at,
            (int) $latest->item_count,
            (int) $latest->source_count,
            (int) $latest->window_days,
        );
    }

    /**
     * Lịch sử đo của một vùng trong cửa sổ so sánh (mới nhất trước).
     *
     * Vùng chưa từng được đo riêng thì rơi về bản 'all' — thà dùng số toàn quốc còn hơn không có gì, và
     * báo cáo nói rõ vùng nào (region_note) để người dùng không hiểu nhầm là số của riêng vùng họ chọn.
     *
     * @return \Illuminate\Support\Collection<int, MarketSignal>
     */
    public function history(string $region = 'all'): \Illuminate\Support\Collection
    {
        $rows = $this->query($region)->limit(60)->get();
        if ($rows->isEmpty() && $region !== 'all') {
            $rows = $this->query('all')->limit(60)->get();
        }

        $cutoff = Carbon::now()->subDays(self::HISTORY_DAYS);

        return $rows->filter(fn (MarketSignal $row) => $row->captured_at === null || $row->captured_at->gte($cutoff))->values();
    }

    /** Truy vấn nền: bản mới nhất trước, kèm giới hạn thời gian giữ dữ liệu. */
    private function query(string $region): \Illuminate\Database\Eloquent\Builder
    {
        return MarketSignal::query()
            ->where('region', $region)
            ->where('captured_at', '>=', Carbon::now()->subDays(self::RETENTION_DAYS))
            ->orderByDesc('captured_at')
            ->orderByDesc('id');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // TRÍCH XUẤT (thuật toán, không AI)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Đo từ một danh sách tin: tín hiệu theo từ khoá + dải giá ghi nhận.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{signals:list<array<string,mixed>>, prices:array<string,mixed>, item_count:int, source_count:int}
     */
    public function extract(array $items): array
    {
        $found = [];
        $sources = [];
        $prices = [];

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $summary = trim((string) ($item['summary'] ?? ''));
            if ($title === '' && $summary === '') {
                continue;
            }

            $text = $title.' '.$summary;
            $sourceName = trim((string) ($item['source_name'] ?? $item['source'] ?? ''));
            if ($sourceName !== '') {
                $sources[$sourceName] = true;
            }

            // TỪ MỘT TIẾNG DỄ TRÙNG NGHĨA KHÁC chỉ được tính khi bài CÓ ngữ cảnh ngành (xem AMBIGUOUS):
            // "đầm" trong "đầm phá", "dạ" trong "dạ dày", "da" trong "da thịt", "kẻ" trong "kẻ gian" —
            // khớp theo ranh giới từ KHÔNG cứu được những ca này vì chúng vẫn là một TỪ riêng.
            $clear = [];
            $ambiguous = [];
            foreach (self::VOCABULARY as $category => $terms) {
                foreach ($terms as $term) {
                    if (! $this->mentions($term, $text)) {
                        continue;
                    }
                    if (isset(self::AMBIGUOUS[$term])) {
                        $ambiguous[] = [$category, $term];
                    } else {
                        $clear[] = [$category, $term];
                    }
                }
            }
            // Có ít nhất một từ khoá RÕ NGHĨA ⇒ bài đang nói về ngành ⇒ nhận cả các từ mơ hồ ở trên.
            $accepted = $clear === [] ? [] : array_merge($clear, $ambiguous);

            foreach ($accepted as [$category, $term]) {
                $key = $category.'|'.$term;
                $found[$key] ??= [
                    'term' => $term,
                    'category' => $category,
                    'mentions' => 0,
                    'sources' => [],
                    'samples' => [],
                ];
                $found[$key]['mentions']++;
                if ($sourceName !== '') {
                    $found[$key]['sources'][$sourceName] = true;
                }
                // Giữ tối đa 3 tin làm bằng chứng để người dùng tự kiểm chứng từng tín hiệu.
                if (count($found[$key]['samples']) < 3) {
                    $found[$key]['samples'][] = [
                        'title' => Str::limit($title, 160, ''),
                        'url' => (string) ($item['url'] ?? ''),
                        'source' => $sourceName,
                        'published_at' => $item['published_at'] ?? null,
                    ];
                }
            }

            foreach ($this->pricesIn($text) as $value) {
                $prices[] = [
                    'value_vnd' => $value,
                    'title' => Str::limit($title, 120, ''),
                    'url' => (string) ($item['url'] ?? ''),
                ];
            }
        }

        $signals = [];
        foreach ($found as $row) {
            $signals[] = [
                'term' => $row['term'],
                'category' => $row['category'],
                'category_label' => self::CATEGORY_LABELS[$row['category']] ?? $row['category'],
                'mentions' => $row['mentions'],
                'source_count' => count($row['sources']),
                'sources' => array_slice(array_keys($row['sources']), 0, 5),
                'samples' => $row['samples'],
            ];
        }
        usort($signals, fn (array $a, array $b) => [$b['mentions'], $b['source_count'], $a['term']] <=> [$a['mentions'], $a['source_count'], $b['term']]);

        return [
            'signals' => $signals,
            'prices' => $this->summarisePrices($prices),
            'item_count' => count($items),
            'source_count' => count($sources),
        ];
    }

    /**
     * Từ khoá có xuất hiện trong văn bản không? Quy tắc khớp nằm ở VietnameseText — dùng CHUNG với bộ lọc
     * nguồn tin, vì "thế nào là được coi là nhắc tới" phải là một định nghĩa duy nhất; hai chỗ tự viết thì
     * sớm muộn lệch nhau (một chỗ khớp "báo" cho "áo", chỗ kia thì không).
     */
    private function mentions(string $term, string $text): bool
    {
        return VietnameseText::mentions($term, $text);
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // GIÁ GHI NHẬN TRONG TIN
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Giá VND đọc được trong một đoạn chữ. Chỉ nhận giá NẰM TRONG KHOẢNG hợp lý của một món đồ may mặc
     * (20.000 – 500.000.000đ) — nếu không thì "1.200 tấn", "năm 2026", "100.000 lượt xem" cũng thành giá.
     *
     * @return list<int>
     */
    public function pricesIn(string $text): array
    {
        $out = [];
        $patterns = [
            // 499.000đ · 1,2 triệu đồng · 899.000 VNĐ
            ['/(?<![\p{L}\p{N}])(\d[\d.,]*)\s*(?:triệu|tr)(?![\p{L}\p{N}])/iu', 1000000],
            ['/(?<![\p{L}\p{N}])(\d[\d.,]*)\s*(?:nghìn|ngàn|k)(?![\p{L}\p{N}])/iu', 1000],
            ['/(?<![\p{L}\p{N}])(\d[\d.,]*)\s*(?:vnđ|vnd|đồng|đ)(?![\p{L}\p{N}])/iu', 1],
        ];

        foreach ($patterns as [$pattern, $factor]) {
            if (! preg_match_all($pattern, $text, $matches)) {
                continue;
            }
            foreach ($matches[1] as $raw) {
                $value = $this->parseAmount((string) $raw, $factor);
                if ($value !== null) {
                    $out[] = $value;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** "1.200.000" → 1200000 · "1,2" (triệu) → 1200000 · "499" (nghìn) → 499000. */
    private function parseAmount(string $raw, int $factor): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $hasDot = str_contains($raw, '.');
        $hasComma = str_contains($raw, ',');
        if ($hasDot && $hasComma) {
            // Dấu phân cách ĐỨNG SAU CÙNG là dấu thập phân ("1.234,5" kiểu Việt · "1,234.5" kiểu Anh).
            $lastDot = strrpos($raw, '.');
            $lastComma = strrpos($raw, ',');
            $decimal = $lastDot > $lastComma ? '.' : ',';
            $raw = str_replace($decimal === '.' ? ',' : '.', '', $raw);
            $raw = str_replace($decimal, '.', $raw);
        } elseif ($hasDot || $hasComma) {
            $separator = $hasDot ? '.' : ',';
            $parts = explode($separator, $raw);
            $tail = end($parts);
            // Đúng 3 chữ số ở đuôi ⇒ đây là dấu NGĂN NGHÌN ("1.200" = 1200), còn lại là dấu thập phân.
            $raw = (strlen((string) $tail) === 3 && count($parts) > 1)
                ? str_replace($separator, '', $raw)
                : str_replace($separator, '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        $value = (int) round(((float) $raw) * $factor);

        return ($value >= self::PRICE_MIN_VND && $value <= self::PRICE_MAX_VND) ? $value : null;
    }

    /**
     * Gộp giá đọc được thành dải giá — người dùng cần "khoảng giá thị trường đang nhắc tới", không cần
     * danh sách thô. Trung vị (median) chứ không phải trung bình: một tin nhắc món 50 triệu không được
     * kéo lệch bức tranh của cả thị trường.
     *
     * @param  list<array{value_vnd:int, title:string, url:string}>  $prices
     * @return array<string, mixed>
     */
    private function summarisePrices(array $prices): array
    {
        if ($prices === []) {
            return ['count' => 0, 'min_vnd' => null, 'median_vnd' => null, 'max_vnd' => null, 'samples' => []];
        }

        $values = array_map(fn (array $row) => (int) $row['value_vnd'], $prices);
        sort($values);
        $count = count($values);
        $median = $count % 2 === 1
            ? $values[intdiv($count, 2)]
            : (int) round(($values[$count / 2 - 1] + $values[$count / 2]) / 2);

        // Mẫu đưa ra phải TRẢI ĐỀU dải giá (rẻ nhất · giữa · đắt nhất), không phải 5 tin đầu giống nhau.
        $picked = [];
        foreach ([0, intdiv($count, 2), $count - 1] as $index) {
            $pick = $prices[$index] ?? null;
            if ($pick !== null && ! in_array($pick, $picked, true)) {
                $picked[] = $pick;
            }
        }

        return [
            'count' => $count,
            'min_vnd' => $values[0],
            'median_vnd' => $median,
            'max_vnd' => $values[$count - 1],
            'samples' => array_slice($picked, 0, 3),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // DỰNG BÁO CÁO (kèm tăng/giảm so với kỳ trước)
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $signals  tín hiệu của bản MỚI NHẤT
     * @param  array<string, mixed>  $prices
     * @param  \Illuminate\Support\Collection<int, MarketSignal>  $snapshots
     * @return array<string, mixed>
     */
    private function buildReport(array $signals, array $prices, $snapshots, string $region, ?Carbon $capturedAt, int $itemCount, int $sourceCount, int $windowDays): array
    {
        $latest = $snapshots->first();
        $older = $snapshots->slice(1)->values();

        $rows = [];
        foreach ($signals as $signal) {
            $term = (string) ($signal['term'] ?? '');
            if ($term === '') {
                continue;
            }
            $category = (string) ($signal['category'] ?? '');

            // Tăng/giảm: so số tin nhắc tới ở bản mới nhất với MỨC TRUNG BÌNH các bản trước trong cửa sổ.
            // Chưa có bản trước ⇒ null, và giao diện phải nói "chưa đủ dữ liệu để so", không được bịa 0%.
            $previousCounts = [];
            $seenIn = 0;
            foreach ($older as $snapshot) {
                $previousCounts[] = $this->countIn((array) $snapshot->signals, $term, $category);
            }
            $seenIn = count(array_filter($previousCounts, fn (int $n) => $n > 0));
            $average = $previousCounts === [] ? null : array_sum($previousCounts) / count($previousCounts);
            $mentions = (int) ($signal['mentions'] ?? 0);
            $change = ($average === null || $average <= 0)
                ? null
                : (int) round((($mentions - $average) / $average) * 100);

            $rows[] = $signal + [
                'term' => $term,
                'category' => $category,
                'category_label' => self::CATEGORY_LABELS[$category] ?? $category,
                'mentions' => $mentions,
                'change_pct' => $change,
                'history_points' => count($previousCounts),
                'seen_before' => $seenIn > 0,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['mentions'], $b['source_count'] ?? 0, $a['term']] <=> [$a['mentions'], $a['source_count'] ?? 0, $b['term']]);

        $snapshotCount = $snapshots->count();
        $ageMinutes = $capturedAt === null ? null : max(0, (int) $capturedAt->diffInMinutes(Carbon::now()));

        return [
            'mode' => $rows !== [] ? 'live' : 'empty',
            'region' => $region,
            'region_note' => $region === 'all' ? 'Tin toàn quốc.' : 'Chưa đủ tin riêng cho vùng này nên số liệu lấy từ nguồn toàn quốc.',
            'captured_at' => $capturedAt?->toISOString(),
            'age_minutes' => $ageMinutes,
            'window_days' => $windowDays,
            'history_days' => self::HISTORY_DAYS,
            'item_count' => $itemCount,
            'source_count' => $sourceCount,
            'snapshots' => $snapshotCount,
            'signals_total' => count($rows),
            'signals' => array_slice($rows, 0, self::SIGNAL_LIMIT),
            'prices' => $prices,
            // Câu cho giao diện: nói ĐÚNG cái đã đo, không hứa gì thêm.
            'note' => $rows === []
                ? 'Chưa đo được tín hiệu nào từ tin thị trường.'
                : sprintf(
                    'Đo từ %d tin của %d nguồn: %d từ khoá đang được nhắc tới%s.',
                    $itemCount,
                    $sourceCount,
                    count($rows),
                    $snapshotCount > 1 ? ' · so sánh với '.($snapshotCount - 1).' lần đo trước' : ' · lần đo đầu tiên',
                ),
            'label' => 'Tín hiệu thị trường',
        ];
    }

    /** Số tin nhắc tới một từ khoá trong một snapshot cũ. */
    private function countIn(array $signals, string $term, string $category): int
    {
        foreach ($signals as $row) {
            if (($row['term'] ?? null) === $term && ($row['category'] ?? null) === $category) {
                return (int) ($row['mentions'] ?? 0);
            }
        }

        return 0;
    }

    /** Báo cáo rỗng nhưng ĐỦ KHOÁ — nơi gọi không phải rẽ nhánh, và "không có dữ liệu" luôn nói được. */
    public function emptyReport(string $region = 'all'): array
    {
        return [
            'mode' => 'empty',
            'region' => $region,
            'region_note' => '',
            'captured_at' => null,
            'age_minutes' => null,
            'window_days' => WebSourceService::MAX_AGE_DAYS,
            'history_days' => self::HISTORY_DAYS,
            'item_count' => 0,
            'source_count' => 0,
            'snapshots' => 0,
            'signals_total' => 0,
            'signals' => [],
            'prices' => ['count' => 0, 'min_vnd' => null, 'median_vnd' => null, 'max_vnd' => null, 'samples' => []],
            'note' => 'Chưa có tin thật nào để đo tín hiệu thị trường.',
            'label' => 'Tín hiệu thị trường',
        ];
    }

    /** Báo cáo dựng thẳng từ một lần đo CHƯA lưu được (DB lỗi) — dữ liệu vẫn tới được người dùng. */
    private function reportFrom(array $measured, Carbon $capturedAt, string $region): array
    {
        return $this->buildReport(
            (array) $measured['signals'],
            (array) $measured['prices'],
            collect(),
            $region,
            $capturedAt,
            (int) $measured['item_count'],
            (int) $measured['source_count'],
            WebSourceService::MAX_AGE_DAYS,
        );
    }

    private function warn(string $message, \Throwable $e): void
    {
        try {
            Log::warning('MarketSignal: '.$message, ['error' => $e->getMessage()]);
        } catch (\Throwable) {
            // Ghi log là tiện ích, không phải điều kiện chạy.
        }
    }
}
