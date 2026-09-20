<?php

namespace Tests\Feature;

use App\Models\MarketSignal;
use App\Models\User;
use App\Models\WebSource;
use App\Services\DesignAgentService;
use App\Services\MarketSignalService;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TÍN HIỆU THỊ TRƯỜNG TỪ NGUỒN NGOÀI (2026-09-23).
 *
 * Vì sao có bộ test này: model đang chạy KHÔNG có tìm kiếm web thật (đo trên production: tham số tìm kiếm
 * bị bỏ qua). Nếu chỉ đưa TIN vào prompt thì hết model là hết phân tích, và mọi con số vẫn là hằng số của
 * bộ xu hướng có sẵn. Bộ test này khoá đúng đường còn lại: MÁY CHỦ lấy tin → ĐO bằng thuật toán → lưu
 * lịch sử → dùng làm dữ liệu cho agent kể cả khi không có model nào chạy.
 *
 * Sáu nhóm luật:
 *   1. ĐO đúng: khớp từ khoá theo RANH GIỚI TỪ (không để "áo" khớp trong "báo"), có nhóm hàng, có bằng chứng;
 *   2. GIÁ đọc được trong tin: "499.000đ", "1,2 triệu", "499k" — và loại con số không phải giá;
 *   3. LƯU lịch sử: cùng dữ liệu thì không ghi thêm, dữ liệu mới thì ghi, bản quá cũ thì dọn;
 *   4. TĂNG/GIẢM tính từ lịch sử, và lần đo đầu tiên phải nói "chưa so sánh được" chứ không bịa 0%;
 *   5. AGENT dùng số đo: hướng có tin thật mang số thật + link, hướng còn lại vẫn gắn nhãn "bộ có sẵn";
 *   6. Đường TẤT ĐỊNH (không AI) vẫn có việc thật để nói, và id hướng sinh từ tin phải chọn được.
 */
class MarketSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    /** Một nguồn RSS đã bật, đủ cấu hình tối thiểu. */
    private function source(array $overrides = []): WebSource
    {
        return WebSource::create(array_merge([
            'slug' => 'nguon-tin', 'name' => 'Nguồn tin', 'url' => 'https://feed.example/rss',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 20,
        ], $overrides));
    }

    /** Feed RSS với nhiều mục: tiêu đề + mô tả + ngày. */
    private function feed(array $items): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>';
        foreach ($items as $index => $item) {
            $body .= '<item><title><![CDATA['.$item[0].']]></title>'
                .'<link>https://bao.example/'.$index.'</link>'
                .'<pubDate>'.($item[2] ?? date('r', time() - 3600)).'</pubDate>'
                .'<description><![CDATA['.($item[1] ?? '').']]></description></item>';
        }

        return $body.'</channel></rss>';
    }

    private function market(): MarketSignalService
    {
        return app(MarketSignalService::class);
    }

    // ── (1) ĐO ────────────────────────────────────────────────────────────────

    /** Đo từ tin: có từ khoá, nhóm hàng, số tin, số nguồn và TIN LÀM BẰNG CHỨNG để người dùng tự kiểm. */
    public function test_it_measures_terms_categories_and_evidence_from_news(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->feed([
            ['Đầm linen lên ngôi mùa hè', 'Chất liệu linen thoáng mát cho công sở'],
            ['Linen và cotton chiếm sóng', 'Vải linen được các thương hiệu chọn'],
            ['Xu hướng màu pastel', 'Tông pastel dịu nhẹ'],
        ]), 200)]);

        $report = $this->market()->capture('all');

        $this->assertSame('live', $report['mode']);
        $terms = array_column($report['signals'], 'term');
        $this->assertContains('linen', $terms, 'Từ khoá xuất hiện trong tin phải được đo.');
        $this->assertContains('pastel', $terms);

        $linen = collect($report['signals'])->firstWhere('term', 'linen');
        $this->assertGreaterThanOrEqual(2, $linen['mentions']);
        $this->assertSame('Chất liệu', $linen['category_label']);
        $this->assertNotEmpty($linen['samples'], 'Mỗi tín hiệu phải kèm tin làm bằng chứng.');
        $this->assertStringContainsString('https://bao.example/', $linen['samples'][0]['url']);
    }

    /**
     * Khớp từ khoá theo RANH GIỚI TỪ.
     *
     * Lỗi thật của bản trước: lọc bằng str_contains nên "áo" khớp trong "báo", "đầm" khớp trong "đầm phá"
     * ⇒ tin rác chảy vào phần phân tích mà không ai thấy.
     */
    public function test_keyword_matching_respects_word_boundaries(): void
    {
        $signals = $this->market()->extract([
            ['title' => 'Báo cáo thị trường chứng khoán', 'summary' => 'Báo chí đưa tin về cáo buộc'],
        ]);

        $terms = array_column($signals['signals'], 'term');
        $this->assertNotContains('áo', $terms, '"Áo" không được khớp bên trong "báo" hay "cáo".');
        $this->assertSame([], $terms, 'Không có từ khoá ngành nào trong câu này thì phải trả rỗng, không đoán.');
    }

    /** Khớp KHÔNG DẤU cho cụm từ (feed viết không dấu) nhưng KHÔNG hạ chuẩn cho từ một tiếng. */
    public function test_multi_syllable_terms_match_without_diacritics(): void
    {
        $signals = $this->market()->extract([
            ['title' => 'Thoi trang cong so voi ao so mi', 'summary' => 'xu huong moi'],
        ]);
        $terms = array_column($signals['signals'], 'term');

        $this->assertContains('công sở', $terms, 'Cụm từ phải khớp được khi feed viết không dấu.');
        $this->assertContains('sơ mi', $terms);
    }

    // ── (2) GIÁ ───────────────────────────────────────────────────────────────

    /** Giá đọc được trong tin: ba cách viết thường gặp của báo Việt Nam. */
    public function test_it_reads_prices_written_in_vietnamese_news(): void
    {
        $prices = $this->market()->pricesIn('Đầm linen giá 499.000đ, bộ vest 1,2 triệu đồng, áo thun 250k.');

        $this->assertContains(499000, $prices);
        $this->assertContains(1200000, $prices);
        $this->assertContains(250000, $prices);
    }

    /** Con số KHÔNG phải giá món may mặc thì không được thành giá (1.200 tấn, 100.000 lượt xem, năm 2026). */
    public function test_it_does_not_mistake_other_numbers_for_prices(): void
    {
        $prices = $this->market()->pricesIn('Xuất khẩu 1.200 tấn vải, 100.000 lượt xem, năm 2026.');

        $this->assertSame([], $prices);
    }

    /** Dải giá trong báo cáo dùng TRUNG VỊ (một tin siêu đắt không được kéo lệch cả thị trường). */
    public function test_price_summary_uses_median(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->feed([
            ['Đầm giá 300.000đ', 'áo'],
            ['Đầm giá 400.000đ', 'áo'],
            ['Đầm giá 500.000đ', 'áo'],
            ['Đầm cao cấp 90.000.000đ', 'áo'],
        ]), 200)]);

        $prices = $this->market()->capture('all')['prices'];

        $this->assertSame(4, $prices['count']);
        $this->assertSame(300000, $prices['min_vnd']);
        $this->assertSame(450000, $prices['median_vnd']);
        $this->assertSame(90000000, $prices['max_vnd']);
    }

    // ── (3) LƯU LỊCH SỬ ───────────────────────────────────────────────────────

    /** Cùng một bộ tin ⇒ không ghi thêm lần đo (bảng không phình vì người dùng mở màn hình liên tục). */
    public function test_capturing_the_same_news_does_not_write_a_second_snapshot(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->feed([['Đầm linen mới', 'vải linen']]), 200)]);

        $this->market()->capture('all');
        $this->market()->capture('all');
        $this->assertSame(1, MarketSignal::query()->count());

        $this->market()->capture('all', true);
        $this->assertSame(2, MarketSignal::query()->count(), 'Đo lại có ép buộc thì phải ghi lần đo mới.');
    }

    /** Lần đo quá cũ bị dọn — không dọn thì bảng tín hiệu lớn mãi theo thời gian. */
    public function test_pruning_removes_old_snapshots(): void
    {
        MarketSignal::query()->create([
            'region' => 'all', 'window_days' => 60, 'captured_at' => Carbon::now()->subDays(200),
            'item_count' => 3, 'source_count' => 1, 'signals' => [], 'prices' => [], 'fingerprint' => 'x',
        ]);

        $this->assertSame(1, $this->market()->prune());
        $this->assertSame(0, MarketSignal::query()->count());
    }

    // ── (4) TĂNG / GIẢM ───────────────────────────────────────────────────────

    /** Có hai lần đo ⇒ nói được tăng/giảm; lần đo ĐẦU TIÊN phải nói "chưa so sánh được", không bịa 0%. */
    public function test_momentum_comes_from_history_and_first_run_says_so(): void
    {
        MarketSignal::query()->create([
            'region' => 'all', 'window_days' => 60, 'captured_at' => Carbon::now()->subHours(6),
            'item_count' => 2, 'source_count' => 1, 'fingerprint' => 'cu',
            'signals' => [[
                'term' => 'linen', 'category' => 'fabric', 'category_label' => 'Chất liệu',
                'mentions' => 1, 'source_count' => 1, 'sources' => ['Nguồn tin'], 'samples' => [],
            ]],
            'prices' => ['count' => 0, 'min_vnd' => null, 'median_vnd' => null, 'max_vnd' => null, 'samples' => []],
        ]);

        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->feed([
            ['Linen', 'vải linen'],
            ['Linen trở lại', 'linen'],
            ['Linen và cotton', 'linen'],
        ]), 200)]);

        $report = $this->market()->capture('all');
        $linen = collect($report['signals'])->firstWhere('term', 'linen');

        $this->assertSame(3, $linen['mentions']);
        $this->assertSame(200, $linen['change_pct'], '1 tin trước đó ⇒ 3 tin bây giờ = tăng 200%.');
        $this->assertSame(2, $report['snapshots']);
    }

    // ── (5) AGENT DÙNG SỐ ĐO ─────────────────────────────────────────────────

    /** Radar: hướng có tin thật mang SỐ ĐO + link; hướng không có tin vẫn gắn nhãn "bộ có sẵn". */
    public function test_radar_carries_measured_signals_and_labelled_demo_trends(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->feed([
            ['Đầm linen lên ngôi', 'chất liệu linen thoáng'],
            ['Linen được ưa chuộng', 'vải linen'],
            ['Mùa hè với linen', 'linen'],
        ]), 200)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $this->assertSame('live', $radar['market']['mode']);
        $this->assertNotEmpty($radar['market']['signals']);

        $live = collect($radar['trends'])->firstWhere('evidence_mode', 'live');
        $this->assertNotNull($live, 'Hướng khớp với tin thật phải được đánh dấu là có bằng chứng thật.');
        $this->assertSame('signal', $live['momentum_source']);
        $this->assertGreaterThanOrEqual(2, $live['live']['mentions']);
        $this->assertNotEmpty($live['live']['articles'], 'Hướng có tin thật phải kèm link bài viết.');

        $demo = collect($radar['trends'])->firstWhere('evidence_mode', 'demo');
        if ($demo !== null) {
            $this->assertSame('catalog', $demo['momentum_source']);
            $this->assertNull($demo['live']);
        }
    }

    /**
     * KHÔNG CÓ MODEL: engine tất định vẫn phải nói bằng SỐ ĐO, không đọc lại câu "tín hiệu mẫu".
     * Đây chính là yêu cầu "tạo dữ liệu từ nguồn ngoài khi model không tìm kiếm web được".
     */
    public function test_without_any_model_the_deterministic_engine_uses_measured_numbers(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->feed([
            ['Đầm linen lên ngôi', 'linen'],
            ['Linen trở lại', 'linen'],
            ['Linen bán chạy', 'linen'],
        ]), 200)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $this->assertSame('rule', $radar['model']['mode'], 'Không có model ⇒ chạy tất định.');

        $live = collect($radar['directions'])->first(fn (array $row) => ($row['evidence_mode'] ?? 'demo') === 'live');
        $this->assertNotNull($live, 'Hướng tất định cho hướng có tin thật phải được sinh ra.');
        $this->assertStringContainsString('tin', $live['why_now']);
        $this->assertStringNotContainsString('mẫu', $live['why_now']);
        $this->assertNotEmpty($live['evidence'], 'Hướng dựa trên tin phải mang link bài viết.');
    }

    /** Hướng CHỈ có trong tin phải chọn được ở bước Định hướng (nếu không, giao diện hiện mà bấm là 422). */
    public function test_trends_born_from_news_can_be_selected(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->feed([
            ['Xu hướng áo khoác dạ', 'áo khoác dạ'],
            ['Áo khoác dạ mùa đông', 'áo khoác dạ'],
        ]), 200)]);

        $agents = app(DesignAgentService::class);
        $radar = $agents->radar($this->customer(), 'all', false);
        $extra = collect($radar['trends'])->first(fn (array $row) => str_starts_with((string) $row['id'], 'live-'));

        $this->assertNotNull($extra, 'Từ khoá chỉ có trong tin phải thành hướng mới để chọn.');
        $this->assertContains($extra['id'], $agents->trendIds(), 'Id hướng mới phải nằm trong danh sách hợp lệ.');

        $this->actingAs($this->customer(), 'web')->postJson('/api/design-agent/collection', [
            'prompt' => 'Bộ sưu tập mùa đông',
            'trend_ids' => [$extra['id']],
            'ai' => false,
        ])->assertOk();
    }

    /** Brief cũng phải mang khối tín hiệu + nói rõ số liệu nào đo từ tin. */
    public function test_brief_exposes_the_measured_signals(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->feed([
            ['Đầm linen lên ngôi', 'linen'],
            ['Linen trở lại', 'linen'],
        ]), 200)]);

        $brief = app(DesignAgentService::class)->collectionBrief([
            'prompt' => 'Bộ sưu tập linen cho công sở',
            'region' => 'all',
        ], $this->customer(), false);

        $this->assertSame('live', $brief['market']['mode']);
        $this->assertGreaterThan(0, $brief['market']['item_count']);
        $this->assertNotEmpty($brief['market']['signals']);
    }

    /** Nguồn ngoài hỏng KHÔNG được làm hỏng radar: vẫn trả hợp đồng đầy đủ, chỉ là mode=empty. */
    public function test_radar_survives_a_broken_source(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response('hỏng', 500)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $this->assertSame('empty', $radar['market']['mode']);
        $this->assertSame([], $radar['market']['signals']);
        $this->assertNotEmpty($radar['trends']);
        $this->assertNotEmpty($radar['directions']);
    }

    // ── (6) ĐỌC NGUỒN: NHỮNG LỖI THẬT ĐÃ SỬA ─────────────────────────────────

    /**
     * Nguồn của VÙNG KHÁC phải hiện "bỏ qua vì khác vùng", KHÔNG được hiện "Không lấy được".
     * Bản trước chỉ đọc ok/không-ok nên báo lỗi cho một nguồn hoàn toàn bình thường.
     */
    public function test_source_status_tells_apart_dead_empty_filtered_and_skipped(): void
    {
        $this->source(['slug' => 'nguon-hn', 'region' => 'hanoi', 'url' => 'https://hn.example/rss']);
        $this->source(['slug' => 'nguon-loc-het', 'url' => 'https://loc.example/rss', 'keywords' => 'linen', 'priority' => 2]);
        $this->source(['slug' => 'nguon-rong', 'url' => 'https://rong.example/rss', 'priority' => 3]);
        $this->source(['slug' => 'nguon-chet', 'url' => 'https://chet.example/rss', 'priority' => 4]);

        Http::fake([
            'hn.example/*' => Http::response($this->feed([['Tin Hà Nội', 'linen']]), 200),
            'loc.example/*' => Http::response($this->feed([['Tin về giày dép', 'không liên quan']]), 200),
            'rong.example/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>', 200),
            'chet.example/*' => Http::response('lỗi', 500),
        ]);

        $evidence = app(WebSourceService::class)->evidence('hcm');
        $bySlug = collect($evidence['sources'])->keyBy('slug');

        $this->assertSame('skipped', $bySlug['nguon-hn']['state']);
        $this->assertStringContainsString('vùng khác', (string) $bySlug['nguon-hn']['state_label']);
        $this->assertSame('filtered', $bySlug['nguon-loc-het']['state']);
        $this->assertSame(1, $bySlug['nguon-loc-het']['parsed'], 'Phải nói được nguồn CÓ tin nhưng bị lọc hết.');
        $this->assertSame(0, $bySlug['nguon-loc-het']['count']);
        $this->assertSame('empty', $bySlug['nguon-rong']['state']);
        $this->assertSame('error', $bySlug['nguon-chet']['state']);
    }

    /** Nguồn chết tạm thời thì dùng bản lấy THÀNH CÔNG gần nhất và NÓI RÕ đó là bản cũ. */
    public function test_a_dead_source_falls_back_to_the_last_good_items(): void
    {
        $this->source();
        // Một stub ĐỘNG: lần gọi đầu trả tin thật, lần sau trả lỗi. (Gọi Http::fake() hai lần không thay
        // được stub cũ — Laravel GỘP stub, nên stub đăng ký trước vẫn thắng.)
        $down = false;
        Http::fake(['feed.example/*' => function () use (&$down) {
            return $down
                ? Http::response('sập', 503)
                : Http::response($this->feed([['Đầm linen', 'linen']]), 200);
        }]);
        $service = app(WebSourceService::class);

        $this->assertCount(1, $service->evidence('all')['items']);

        // Lần hai ÉP lấy lại (bỏ đệm đọc) và nguồn trả lỗi ⇒ phải rơi về bản lấy THÀNH CÔNG gần nhất.
        $down = true;
        $evidence = $service->evidence('all', WebSourceService::EVIDENCE_LIMIT, true);
        $row = $evidence['sources'][0];

        $this->assertSame('stale', $row['state']);
        $this->assertSame(1, $row['count'], 'Vẫn phải có tin để phân tích.');
        $this->assertSame('Đang dùng bản lấy trước', $row['state_label']);
    }

    /**
     * Nội dung ngoài: bỏ thẻ TRƯỚC khi giải mã thực thể.
     *
     * Lỗi thật: html_entity_decode(strip_tags(...)) làm "&lt;img onerror=…&gt;" sống lại thành thẻ THẬT
     * sau khi đã bỏ thẻ — tức là lớp chống prompt-injection bị vô hiệu ngay trong prompt.
     */
    public function test_html_entities_do_not_revive_tags_in_the_prompt(): void
    {
        $this->source();
        $body = '<?xml version="1.0"?><rss version="2.0"><channel><item>'
            .'<title>Đầm linen &lt;img src=x onerror=alert(1)&gt;</title>'
            .'<link>https://bao.example/x</link>'
            .'<description><![CDATA[&lt;script&gt;alert(1)&lt;/script&gt; nội dung]]></description>'
            .'</item></channel></rss>';
        Http::fake(['feed.example/*' => Http::response($body, 200)]);

        $item = app(WebSourceService::class)->evidence('all')['items'][0];

        $this->assertStringNotContainsString('<img', $item['title']);
        $this->assertStringNotContainsString('<script', $item['summary']);
    }

    /** Ngày kiểu Việt Nam (ngày/tháng/năm) phải đọc ĐÚNG: 05/09 là tháng 9, không phải tháng 5 (lỗi strtotime). */
    public function test_vietnamese_date_format_is_read_correctly(): void
    {
        $this->source();
        $body = '<?xml version="1.0"?><rss version="2.0"><channel><item>'
            .'<title>Tin linen</title><link>https://bao.example/ngay</link>'
            .'<pubDate>'.now()->format('d/m/Y').'</pubDate>'
            .'</item></channel></rss>';
        Http::fake(['feed.example/*' => Http::response($body, 200)]);

        $item = app(WebSourceService::class)->evidence('all')['items'][0];

        $this->assertNotNull($item['published_at']);
        $this->assertSame(now()->format('Y-m-d'), date('Y-m-d', strtotime((string) $item['published_at'])));
    }

    /** Lọc từ khoá của nguồn cũng theo ranh giới từ: từ khoá "áo" KHÔNG được giữ tin về "báo". */
    public function test_source_keyword_filter_does_not_match_inside_words(): void
    {
        $this->source(['keywords' => 'áo']);
        Http::fake(['feed.example/*' => Http::response($this->feed([
            ['Báo cáo ngành dệt may', 'báo chí đưa tin'],
            ['Áo sơ mi mới', 'áo'],
        ]), 200)]);

        $items = app(WebSourceService::class)->evidence('all')['items'];

        $this->assertCount(1, $items);
        $this->assertStringContainsString('Áo sơ mi', $items[0]['title']);
    }

    /** Cùng một bài qua hai nguồn (URL khác nhau, tiêu đề giống nhau) chỉ được đưa vào prompt MỘT lần. */
    public function test_the_same_story_from_two_sources_is_deduplicated_by_title(): void
    {
        $this->source(['slug' => 'nguon-a', 'url' => 'https://a.example/rss']);
        $this->source(['slug' => 'nguon-b', 'url' => 'https://b.example/rss', 'priority' => 2]);

        $title = 'Đầm linen Việt Nam lên ngôi trong mùa hè này';
        Http::fake([
            'a.example/*' => Http::response($this->feed([[$title, 'linen']]), 200),
            'b.example/*' => Http::response($this->feed([[$title, 'linen']]), 200),
        ]);

        $items = app(WebSourceService::class)->evidence('all')['items'];

        $this->assertCount(1, $items, 'Tin trùng tiêu đề chỉ được vào prompt một lần.');
    }

    // ── (7) GIAO DIỆN ────────────────────────────────────────────────────────

    /** Giao diện phải có khối tín hiệu + nhãn phân biệt số đo với bộ có sẵn, và KHÔNG lộ chữ kỹ thuật. */
    public function test_the_agent_screen_shows_measured_signals_without_technical_jargon(): void
    {
        $view = static::designAgentsSource();

        foreach (['Tín hiệu đo từ tin thật', 'tin thật nhắc tới', 'có tin thật', 'bộ có sẵn', 'Kiểm tra lại'] as $needle) {
            $this->assertStringContainsString($needle, $view, 'Thiếu chữ bắt buộc trên màn hình: '.$needle);
        }

        foreach (['latency_ms', 'rule-based-v1', 'CollectionBot', 'nhóm “prompt”', 'Model đang dùng:'] as $leak) {
            $this->assertStringNotContainsString($leak, $view, 'Chữ kỹ thuật lộ ra người dùng: '.$leak);
        }
    }

    /** Nút khoá phải nói lý do; nút chỉ có icon phải có nhãn; vùng chạm nhỏ phải bỏ (§4 + WCAG 2.2). */
    public function test_the_screen_keeps_accessibility_rules_for_the_new_block(): void
    {
        $view = static::designAgentsSource();

        $this->assertStringContainsString('Khu vực đọc tín hiệu', $view, 'Ô chọn vùng phải có nhãn cho trình đọc màn hình.');
        $this->assertStringContainsString("Xoá ' + (row.name", $view, 'Nút chỉ có icon phải có nhãn nêu đúng dòng.');
        $this->assertStringNotContainsString('h-5 w-5 place-items-center rounded-full bg-ink-800 text-cream-300" aria-label="Bỏ ảnh mẫu"', $view, 'Vùng chạm 20px phải được nâng lên ≥24px.');
    }
}
