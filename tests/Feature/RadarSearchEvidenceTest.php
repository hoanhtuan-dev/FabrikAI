<?php

namespace Tests\Feature;

use App\Models\MarketSignal;
use App\Models\User;
use App\Models\WebSource;
use App\Services\DesignAgentService;
use App\Services\MarketSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BƯỚC 2/5 — TÍN HIỆU SỬ DỤNG DỮ LIỆU TÌM KIẾM, KHÔNG DÙNG RSS/TRANG BÁO (2026-09-26).
 *
 * Yêu cầu nguyên văn của chủ dự án: "làm cho agent studio: Bước 2/5 · Tín hiệu sử dụng dữ liệu tìm kiếm
 * thay vì rss|pages, tránh tự bịa hoặc dữ liệu [mẫu]."
 *
 * HIỆN TRẠNG ĐÃ ĐO TRƯỚC KHI SỬA (lý do của cả bộ test này):
 *   · khối dữ liệu của radar lấy từ WebSourceService::evidence() — tức NGUỒN ĐÃ KHAI kind=rss/page;
 *   · MarketSignalService::capture() cũng đo từ chính đường đó ⇒ mọi con số của khối "Tín hiệu" là số đếm
 *     trên tin của vài chuyên mục đã khai;
 *   · không có hướng nào có bằng chứng thật thì danh mục MẪU vẫn hiện ở bước 2;
 *   · giao diện ghi "Máy chủ đọc tin từ các nguồn đã nối..." — sai việc máy chủ đang làm.
 *
 * NĂM LUẬT ĐƯỢC KHOÁ Ở ĐÂY (mỗi luật một bài, tên bài nói rõ luật):
 *   (a) CÓ nguồn tìm kiếm  ⇒ mọi con số đến từ KẾT QUẢ TÌM KIẾM, và câu hỏi là câu hỏi CHUNG của ngành;
 *   (b) nguồn kind=page/rss KHÔNG vào khối dữ liệu của radar và KHÔNG được đếm vào tín hiệu thị trường;
 *   (c) KHÔNG có nguồn tìm kiếm ⇒ báo cáo RỖNG + câu nói thật, KHÔNG số mẫu, KHÔNG ghi ảnh chụp rỗng;
 *   (d) trends chỉ chứa hướng CÓ BẰNG CHỨNG; hướng của bộ có sẵn nằm ở trends_demo và đếm vào demo_hidden;
 *   (e) chữ trên giao diện khớp sự thật (nói "tra trên web"), và câu sai cũ không quay lại.
 */
class RadarSearchEvidenceTest extends TestCase
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

    /** NGUỒN TÌM KIẾM: URL có chỗ điền từ khoá nên nó trả lời được các truy vấn chủ đề chung. */
    private function searchSource(array $overrides = []): WebSource
    {
        return WebSource::create(array_merge([
            'slug' => 'nguon-tim', 'name' => 'Nguồn tìm kiếm', 'url' => 'https://search.example/find?q={query}',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 20,
        ], $overrides));
    }

    /** NGUỒN ĐÃ KHAI kiểu trang/RSS — đúng loại nguồn mà bước 2 KHÔNG được dùng nữa. */
    private function pageSource(array $overrides = []): WebSource
    {
        return WebSource::create(array_merge([
            'slug' => 'nguon-trang', 'name' => 'Trang chuyên mục', 'url' => 'https://trang.example/rss',
            'kind' => 'page', 'enabled' => true, 'priority' => 2, 'max_items' => 20,
        ], $overrides));
    }

    /**
     * Feed cho một nguồn. $host nằm trong THAM SỐ vì bộ test này phải phân biệt được tin của nguồn TÌM KIẾM
     * với tin của nguồn ĐÃ KHAI: dùng chung một miền thì không thể nói "tin nào lọt vào từ đâu".
     */
    private function feed(array $items, string $host = 'https://tim.example'): string
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>';
        foreach ($items as $index => $item) {
            $body .= '<item><title><![CDATA['.$item[0].']]></title>'
                .'<link>'.$host.'/'.$index.'</link>'
                .'<pubDate>'.date('r', time() - 3600).'</pubDate>'
                .'<description><![CDATA['.($item[1] ?? '').']]></description></item>';
        }

        return $body.'</channel></rss>';
    }

    // ── (a) SỐ ĐO ĐẾN TỪ KẾT QUẢ TÌM KIẾM ───────────────────────────────────

    /** Có nguồn tìm kiếm ⇒ số đo là số đếm TRÊN KẾT QUẢ TRA, và câu hỏi gửi đi là câu hỏi chung của ngành. */
    public function test_numbers_are_measured_on_the_search_results(): void
    {
        $this->searchSource();
        Http::fake(['search.example/*' => Http::response($this->feed([
            ['Đầm linen lên ngôi mùa hè', 'Chất liệu linen thoáng mát cho công sở'],
            ['Linen và cotton chiếm sóng', 'Vải linen được các thương hiệu chọn'],
            ['Mùa hè với linen', 'linen là chất liệu chính'],
        ]), 200)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $this->assertSame('live', $radar['market']['mode']);
        $linen = collect($radar['market']['signals'])->firstWhere('term', 'linen');
        $this->assertNotNull($linen, 'Từ khoá xuất hiện trong KẾT QUẢ TRA phải được đo.');
        $this->assertSame('Chất liệu', $linen['category_label']);
        $this->assertSame(3, $linen['mentions'], 'Số tin đếm được là số tin của chính lượt tra này.');

        // BẰNG CHỨNG của con số phải là địa chỉ NẰM TRONG kết quả tra — người dùng bấm vào là kiểm được, và
        // nó phải trùng với khối dữ liệu đang hiển thị (cùng MỘT lượt tra, không phải hai lượt khác nhau).
        $evidenceUrls = array_column($radar['external_evidence']['items'], 'url');
        $this->assertNotEmpty($evidenceUrls);
        $this->assertContains($linen['samples'][0]['url'], $evidenceUrls,
            'Tin làm bằng chứng cho số đo phải chính là tin trong khối dữ liệu của bước 2.');

        // CÂU HỎI CHUNG: có vùng + mốc thời gian, và KHÔNG chứa dữ liệu riêng của shop (luật an toàn dữ liệu
        // — ảnh chụp tín hiệu dùng chung giữa các tài khoản).
        $queries = (array) $radar['external_evidence']['queries'];
        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('Việt Nam', $queries[0]);
        $this->assertStringContainsString((string) now()->format('Y'), $queries[0]);
    }

    // ── (b) TRANG/RSS KHÔNG ĐƯỢC LỌT VÀO ────────────────────────────────────

    /** Tin của nguồn kind=page/rss KHÔNG vào khối dữ liệu của radar và KHÔNG được đếm vào tín hiệu. */
    public function test_page_and_rss_news_never_enter_the_block_or_the_measurement(): void
    {
        $this->searchSource();
        $this->pageSource();
        Http::fake([
            'search.example/*' => Http::response($this->feed([['Đầm linen tra được', 'linen']], 'https://tim.example'), 200),
            'trang.example/*' => Http::response($this->feed([['Tin từ TRANG CHUYÊN MỤC', 'satin bóng']], 'https://trang.example'), 200),
        ]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $urls = array_column($radar['external_evidence']['items'], 'url');
        $titles = implode(' | ', array_column($radar['external_evidence']['items'], 'title'));

        $this->assertContains('https://tim.example/0', $urls, 'Kết quả TÌM KIẾM phải có trong khối dữ liệu.');
        $this->assertNotContains('https://trang.example/0', $urls, 'Tin của nguồn đã khai KHÔNG được vào khối dữ liệu.');
        $this->assertStringNotContainsString('TRANG CHUYÊN MỤC', $titles);

        // KHÔNG đếm: "satin" chỉ có trong tin của nguồn đã khai ⇒ nếu nó xuất hiện trong tín hiệu thì tin
        // của nguồn rss/page đã lọt vào máy đo.
        $terms = array_column($radar['market']['signals'], 'term');
        $this->assertContains('linen', $terms);
        $this->assertNotContains('satin', $terms, 'Từ khoá chỉ có trong tin của nguồn đã khai KHÔNG được đếm.');

        // Và mạnh hơn cả: máy chủ KHÔNG hề gọi tới nguồn đã khai trong lượt này.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'trang.example'));
    }

    // ── (c) KHÔNG CÓ NGUỒN TÌM KIẾM ⇒ RỖNG + NÓI THẬT ───────────────────────

    /** Chưa khai nguồn tìm kiếm ⇒ báo cáo RỖNG, câu nói thật, KHÔNG số mẫu và KHÔNG ghi ảnh chụp rỗng. */
    public function test_without_a_search_source_the_report_is_empty_and_honest(): void
    {
        // Chỉ có nguồn kind=rss đã khai: nó KHÔNG phải nguồn tìm kiếm.
        $this->pageSource();
        Http::fake(['*' => Http::response($this->feed([
            ['Đầm linen', 'linen'],
            ['Linen trở lại', 'linen'],
        ], 'https://trang.example'), 200)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $this->assertSame('empty', $radar['market']['mode']);
        $this->assertSame([], $radar['market']['signals']);
        $this->assertSame([], $radar['market']['topics']);
        $this->assertSame(0, $radar['market']['signals_total']);
        $this->assertSame(0, $radar['market']['item_count']);
        $this->assertStringContainsString('chưa tra được tin nào', mb_strtolower((string) $radar['market']['note']),
            'Câu nói thật phải nói ĐÚNG việc đã làm: chưa tra được tin nào để đo.');

        $this->assertSame([], $radar['external_evidence']['items']);
        $this->assertSame('demo', $radar['source_mode']);
        $this->assertStringContainsString('chưa có nguồn tìm kiếm', (string) $radar['external_evidence']['search_error'],
            'Lý do cụ thể phải đọc được — "chưa khai nguồn tìm kiếm" khác "đã tra mà không ra tin".');
        // Bảng nguồn nói thẳng việc cần làm thay vì để trống.
        $this->assertSame('Chưa có nguồn tìm kiếm', $radar['sources'][0]['name']);

        // KHÔNG SỐ MẪU: không hướng nào của bộ có sẵn được đẩy vào trends, và lượt chạy KHÔNG ghi một ảnh
        // chụp rỗng vào lịch sử (nó sẽ thành "lần đo trước có 0 tin" và làm con số tăng/giảm lần sau sai).
        $this->assertSame([], $radar['trends']);
        $this->assertNotEmpty($radar['trends_demo']);
        $this->assertSame(0, MarketSignal::query()->count(), 'Tra không ra tin thì KHÔNG ghi ảnh chụp.');
    }

    /** Đường CRON cũng vậy: tự đi tra, không ra tin ⇒ rỗng + nói thật, TUYỆT ĐỐI không quay lại đọc RSS. */
    public function test_the_scheduled_measurement_also_searches_and_reports_empty_honestly(): void
    {
        // Nguồn RSS đã khai CÓ tin sống: nếu đường cron còn đọc nó thì báo cáo sẽ KHÁC rỗng.
        $this->pageSource();
        Http::fake(['*' => Http::response($this->feed([['Đầm linen', 'linen'], ['Linen trở lại', 'linen']], 'https://trang.example'), 200)]);

        $report = app(MarketSignalService::class)->capture('all');

        $this->assertSame('empty', $report['mode']);
        $this->assertSame([], $report['signals']);
        $this->assertStringContainsString('chưa tra được tin nào', mb_strtolower((string) $report['note']));
        $this->assertSame(0, MarketSignal::query()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'trang.example'));
    }

    // ── (d) BỘ CÓ SẴN BỊ TÁCH, KHÔNG BỊ LẤP VÀO CHỖ TRỐNG ───────────────────

    /** trends chỉ chứa hướng CÓ BẰNG CHỨNG; hướng của bộ có sẵn nằm ở trends_demo và đếm vào demo_hidden. */
    public function test_trends_hold_only_real_evidence_and_the_builtin_set_is_split_out(): void
    {
        $this->searchSource();
        Http::fake(['search.example/*' => Http::response($this->feed([
            ['Đầm linen lên ngôi', 'linen'],
            ['Linen trở lại', 'linen'],
        ], 'https://tim.example'), 200)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $this->assertNotEmpty($radar['trends'], 'Có tin thật ⇒ phải có hướng mang bằng chứng.');
        foreach ((array) $radar['trends'] as $trend) {
            $this->assertSame('live', $trend['evidence_mode'], 'Hướng của bộ có sẵn KHÔNG được nằm trong trends.');
        }

        $this->assertNotEmpty($radar['trends_demo'], 'Bộ có sẵn bị TÁCH RA, không bị xoá.');
        foreach ((array) $radar['trends_demo'] as $trend) {
            $this->assertSame('demo', $trend['evidence_mode']);
        }
        $this->assertSame(count($radar['trends_demo']), $radar['demo_hidden'],
            'Số hướng đã tách phải đếm được để giao diện nói ra, không ẩn im lặng.');

        // Không hướng nào xuất hiện ở CẢ hai khoá (hai thẻ giống nhau là lỗi nhìn thấy được).
        $liveIds = array_column($radar['trends'], 'id');
        $demoIds = array_column($radar['trends_demo'], 'id');
        $this->assertSame([], array_intersect($liveIds, $demoIds));
    }

    // ── (e) CHỮ TRÊN GIAO DIỆN PHẢI KHỚP SỰ THẬT ────────────────────────────

    /** Giao diện nói đúng việc "tra trên web", có trạng thái nói thật khi không tra được, không v-html. */
    public function test_the_screen_tells_the_truth_about_the_web_search(): void
    {
        $view = static::designAgentsSource();

        // CHỮ MỚI: khối đo nói rõ số liệu đến từ KẾT QUẢ TÌM KIẾM và máy chủ TỰ TRA trên web.
        $this->assertStringContainsString('Tín hiệu đo từ kết quả tìm kiếm', $view);
        $this->assertStringContainsString('Máy chủ tự tra trên web', $view);
        $this->assertStringContainsString('số đo từ kết quả tìm kiếm', $view);

        // CÂU SAI CŨ KHÔNG ĐƯỢC QUAY LẠI: khối dữ liệu của bước 2 không còn đọc "nguồn đã nối".
        $this->assertStringNotContainsString('nguồn đã nối', $view,
            'Câu cũ mô tả sai việc máy chủ đang làm (đọc tin từ nguồn đã khai) đã quay lại giao diện.');

        // TRẠNG THÁI NÓI THẬT khi lượt này không tra được hướng nào — và nó nói RA là không lấp bằng bộ có sẵn.
        $this->assertStringContainsString('emptyTrendNote', $view);
        $this->assertStringContainsString('chưa tra được hướng nào có bằng chứng thật trên web', $view);
        $this->assertStringContainsString('không lấp chỗ trống bằng danh mục có sẵn', $view);

        // Mọi chuỗi hiển thị là VĂN BẢN, không phải HTML (§6 · §8 của docs/DESIGN_SYSTEM.md).
        $this->assertStringNotContainsString('v-html', $view);
    }
}
