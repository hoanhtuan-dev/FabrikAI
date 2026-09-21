<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebSource;
use App\Services\DesignAgentService;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TRÌNH KẾT NỐI NGUỒN NGOÀI (Đợt 27 — 2026-09-23).
 *
 * Vì sao có lớp này: đo thật với DeepSeek — gửi tham số tìm kiếm vào API thì trả HTTP 200 nhưng BỎ QUA,
 * model vẫn nói "không có quyền truy cập thông tin thời gian thực". Nên đường đúng là MÁY CHỦ đi lấy dữ
 * liệu rồi đưa vào prompt kèm URL + thời điểm — và danh sách nguồn phải CÀI ĐẶT ĐƯỢC.
 *
 * Bộ test này khoá: đọc RSS/JSON theo ánh xạ · lọc từ khoá/độ mới/trần số mục · một nguồn chết không
 * làm hỏng cả lượt · tin thật ĐI VÀO PROMPT · API cấu hình chỉ mở cho quản trị viên · chặn URL nội bộ.
 */
class WebSourceTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function rss(string $title, string $link, string $date = 'Mon, 22 Sep 2026 08:00:00 +0700'): string
    {
        return '<?xml version="1.0"?><rss version="2.0"><channel>'
            .'<item><title><![CDATA['.$title.']]></title><link>'.$link.'</link>'
            .'<pubDate>'.$date.'</pubDate><description><![CDATA[<p>Mô tả <b>'.$title.'</b></p>]]></description></item>'
            .'</channel></rss>';
    }

    private function source(array $overrides = []): WebSource
    {
        return WebSource::create(array_merge([
            'slug' => 'nguon-test', 'name' => 'Nguồn test', 'url' => 'https://feed.example/rss',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 5,
        ], $overrides));
    }

    // ── (A) ĐỌC & LỌC ──────────────────────────────────────────────────────

    /** RSS: lấy được tiêu đề/link/ngày, BỎ HTML, và có kèm tên nguồn. */
    public function test_it_parses_rss_into_clean_items(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->rss('Đầm linen lên ngôi', 'https://bao.example/a'), 200)]);

        $evidence = app(WebSourceService::class)->evidence('all');

        $this->assertSame('live', $evidence['mode']);
        $this->assertCount(1, $evidence['items']);
        $this->assertSame('Đầm linen lên ngôi', $evidence['items'][0]['title']);
        $this->assertSame('https://bao.example/a', $evidence['items'][0]['url']);
        $this->assertStringNotContainsString('<b>', $evidence['items'][0]['summary'], 'Nội dung ngoài không được mang thẻ HTML.');
        $this->assertSame('Nguồn test', $evidence['items'][0]['source_name']);
        $this->assertNotNull($evidence['items'][0]['published_at']);
    }

    /** Nguồn JSON khai ánh xạ ⇒ đọc được mà KHÔNG phải sửa mã (đây là điều kiện của "cài đặt được"). */
    public function test_json_source_follows_the_declared_mapping(): void
    {
        $this->source([
            'slug' => 'api-test', 'url' => 'https://api.example/trends', 'kind' => 'json',
            'items_path' => 'data.rows', 'title_field' => 'name', 'link_field' => 'permalink',
            'date_field' => 'created', 'summary_field' => 'excerpt',
        ]);
        Http::fake(['api.example/*' => Http::response(json_encode([
            'data' => ['rows' => [[
                'name' => 'Xu hướng pastel', 'permalink' => 'https://api.example/1',
                'created' => '2026-09-21T10:00:00Z', 'excerpt' => 'Màu nhạt lên ngôi',
            ]]],
        ]), 200)]);

        $items = app(WebSourceService::class)->evidence('all')['items'];

        $this->assertCount(1, $items);
        $this->assertSame('Xu hướng pastel', $items[0]['title']);
        $this->assertSame('https://api.example/1', $items[0]['url']);
    }

    /** Lọc từ khoá + trần số mục + bỏ tin quá cũ; tin cũ bị loại, tin mới được giữ. */
    public function test_it_filters_by_keywords_age_and_cap(): void
    {
        $this->source(['keywords' => 'linen', 'max_items' => 1]);
        $body = '<?xml version="1.0"?><rss version="2.0"><channel>'
            .'<item><title>Tin về linen</title><link>https://e/1</link><pubDate>'.date('r', time() - 86400).'</pubDate></item>'
            .'<item><title>Tin về giày</title><link>https://e/2</link><pubDate>'.date('r', time() - 86400).'</pubDate></item>'
            .'<item><title>Tin linen cũ</title><link>https://e/3</link><pubDate>'.date('r', time() - 86400 * 400).'</pubDate></item>'
            .'</channel></rss>';
        Http::fake(['feed.example/*' => Http::response($body, 200)]);

        $items = app(WebSourceService::class)->evidence('all')['items'];

        $this->assertCount(1, $items, 'Trần max_items phải được tôn trọng.');
        $this->assertSame('Tin về linen', $items[0]['title']);
    }

    /** Một nguồn chết KHÔNG làm hỏng lượt: nguồn kia vẫn có tin, và trạng thái nói rõ nguồn nào lỗi. */
    public function test_one_broken_source_does_not_break_the_run(): void
    {
        $this->source(['slug' => 'nguon-ok', 'url' => 'https://ok.example/rss']);
        $this->source(['slug' => 'nguon-chet', 'url' => 'https://chet.example/rss', 'priority' => 2]);
        Http::fake([
            'ok.example/*' => Http::response($this->rss('Tin tốt', 'https://ok.example/1'), 200),
            'chet.example/*' => Http::response('lỗi', 500),
        ]);

        $evidence = app(WebSourceService::class)->evidence('all');
        $bySlug = collect($evidence['sources'])->keyBy('slug');

        $this->assertSame('live', $evidence['mode']);
        $this->assertCount(1, $evidence['items']);
        $this->assertFalse($bySlug['nguon-chet']['ok']);
        $this->assertSame('HTTP 500', $bySlug['nguon-chet']['error']);
        $this->assertTrue($bySlug['nguon-ok']['ok']);
    }

    /** Nguồn dành cho vùng khác thì bị bỏ qua TƯỜNG MINH (không im lặng biến mất). */
    public function test_region_specific_sources_are_skipped_with_a_reason(): void
    {
        $this->source(['slug' => 'nguon-hn', 'region' => 'hanoi']);
        Http::fake(['*' => Http::response($this->rss('Tin Hà Nội', 'https://e/1'), 200)]);

        $evidence = app(WebSourceService::class)->evidence('hcm');

        $this->assertSame('empty', $evidence['mode']);
        $this->assertStringContainsString('vùng khác', (string) $evidence['sources'][0]['error']);
    }

    /** Đệm: lần hai KHÔNG gọi lại nguồn; `force` thì gọi lại thật. */
    public function test_fetch_is_cached_and_force_refreshes(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->rss('Tin', 'https://e/1'), 200)]);
        $service = app(WebSourceService::class);

        $service->evidence('all');
        $service->evidence('all');
        Http::assertSentCount(1);

        $service->evidence('all', WebSourceService::EVIDENCE_LIMIT, true);
        Http::assertSentCount(2);
    }
    // ── (B) TIN THẬT ĐI VÀO PROMPT ─────────────────────────────────────────

    /** Bằng chứng mạnh nhất: tiêu đề + URL của tin ngoài phải nằm TRONG payload gửi model. */
    public function test_external_news_reaches_the_model_prompt(): void
    {
        $this->source();

        // Nhóm 'prompt' cần một model dùng được thì agent mới gọi AI.
        \App\Models\StudioProvider::create([
            'slug' => 'gw-news', 'name' => 'Gateway', 'protocol' => 'openai',
            'base_url' => 'https://llm.example/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'gw-news', 'priority' => 9, 'enabled' => true,
        ]);
        \App\Models\StudioApiKey::create([
            'provider' => 'gw-news', 'label' => 'gw-news', 'value' => 'sk-x',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        \App\Models\StudioModel::create([
            'group' => 'prompt', 'name' => 'Model', 'provider' => 'gw-news',
            'model_id' => 'm1', 'api_key_ref' => 'gw-news', 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_prompt_model', 'gw-news:m1');
        Cache::flush();

        Http::fake([
            'feed.example/*' => Http::response($this->rss('Đầm linen bán chạy ở Hà Nội', 'https://bao.example/linen'), 200),
            'llm.example/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'directions' => array_fill(0, 6, ['title' => 'Hướng', 'thesis' => 't', 'why_now' => 'w', 'action' => 'a', 'risk' => 'r', 'price_band' => 'mid']),
            ], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        app(DesignAgentService::class)->radar($this->customer(), 'hcm', true);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'llm.example')) {
                return false;
            }
            // Thân request bị mã hoá HAI LỚP: HTTP client mã hoá lại (mất JSON_UNESCAPED_UNICODE và escape dấu
            // `/`), còn khối DỮ LIỆU nằm trong `content` của message dưới dạng CHUỖI JSON. Phải giải CẢ HAI lớp
            // rồi mới so — nếu không sẽ tưởng tin không vào prompt trong khi nó VẪN vào.
            $payload = json_decode((string) $request->body(), true);
            $content = (string) data_get($payload, 'messages.1.content', '');
            // Khối dữ liệu có tiền tố "DỮ LIỆU:\n" nên phải cắt từ dấu { đầu tiên rồi mới giải mã được.
            $start = strpos($content, '{');
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($start !== false) {
                $body .= json_encode(json_decode(substr($content, $start), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            return str_contains($body, 'Đầm linen bán chạy ở Hà Nội')
                && str_contains($body, 'https://bao.example/linen')
                && str_contains($body, 'external_evidence');
        });
    }

    /** Không có nguồn nào ⇒ mode=empty và KHÔNG được nói là đang dùng dữ liệu thật. */
    public function test_without_sources_the_evidence_is_explicitly_empty(): void
    {
        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $this->assertSame('demo', $radar['source_mode']);
        $this->assertSame('empty', $radar['external_evidence']['mode']);
        $this->assertSame(0, $radar['external_evidence']['count']);
    }

    /** Có nguồn thật ⇒ radar nói `live` và trả về đúng tin đã dùng (để người dùng tự kiểm chứng). */
    public function test_radar_reports_live_mode_with_the_real_items(): void
    {
        $this->source();
        Http::fake(['feed.example/*' => Http::response($this->rss('Tin thật', 'https://e/that'), 200)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', false);

        $this->assertSame('live', $radar['source_mode']);
        $this->assertSame(1, $radar['external_evidence']['count']);
        $this->assertSame('https://e/that', $radar['external_evidence']['items'][0]['url']);
    }

// ── (B2) TÌM THEO TỪ KHOÁ (nền của công cụ `web_search`) ──────────────

    /**
     * Nguồn có chỗ điền từ khoá (`q=` sẵn có, hoặc `{query}` tường minh) là nguồn TÌM ĐƯỢC.
     *
     * Vì sao nhận cả hai dạng: nguồn Google News mặc định đã có `?q=thời+trang` từ trước, nên nhận dạng
     * `q=` khiến nó thành nguồn tìm kiếm NGAY trên production mà không phải migrate dữ liệu đang chạy.
     */
    public function test_only_sources_with_a_query_slot_are_searchable(): void
    {
        $searchable = $this->source(['slug' => 'co-q', 'url' => 'https://news.example/rss/search?q=thoi+trang&hl=vi']);
        $placeholder = $this->source(['slug' => 'co-query', 'url' => 'https://news.example/rss?q={query}&hl=vi']);
        $plain = $this->source(['slug' => 'khong-tim', 'url' => 'https://feed.example/rss']);

        $this->assertTrue(WebSourceService::isSearchable($searchable));
        $this->assertTrue(WebSourceService::isSearchable($placeholder));
        $this->assertFalse(WebSourceService::isSearchable($plain), 'Feed cố định KHÔNG được coi là nguồn tìm kiếm.');

        $slugs = array_map(fn (WebSource $s) => $s->slug, app(WebSourceService::class)->searchableSources('all'));
        $this->assertContains('co-q', $slugs);
        $this->assertContains('co-query', $slugs);
        $this->assertNotContains('khong-tim', $slugs);
    }

    /**
     * TÌM THẬT: từ khoá của người gọi phải được ĐIỀN VÀO URL rồi mới gọi ra ngoài — đây là điều phân biệt
     * "tìm theo từ khoá" với "đọc lại feed cố định".
     */
    public function test_search_replaces_the_keyword_in_the_source_url(): void
    {
        $this->source(['slug' => 'google-news', 'url' => 'https://news.example/rss/search?q=thoi+trang&hl=vi&gl=VN']);
        Http::fake(['news.example/*' => Http::response($this->rss('Áo dạ tweed lên ngôi', 'https://bao.example/tweed'), 200)]);

        $found = app(WebSourceService::class)->search('áo dạ tweed', 'all');

        $this->assertSame('live', $found['mode']);
        $this->assertSame(1, $found['count']);
        $this->assertSame('Áo dạ tweed lên ngôi', $found['items'][0]['title']);
        $this->assertSame('áo dạ tweed', $found['query']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'news.example')
            && str_contains($request->url(), rawurlencode('áo dạ tweed'))
            // Phần còn lại của query string phải được GIỮ NGUYÊN — mất `hl/gl` là đổi cả thị trường tìm.
            && str_contains($request->url(), 'hl=vi'));
    }

    /** Nguồn `{query}` được điền y như vậy, và KHÔNG lọc theo từ khoá cấu hình của nguồn. */
    public function test_search_uses_placeholder_sources_and_skips_the_keyword_filter(): void
    {
        // `keywords` cố tình KHÔNG khớp tiêu đề: ở đường tìm kiếm, chính TRUY VẤN là bộ lọc.
        $this->source(['slug' => 'tim-theo-query', 'url' => 'https://news.example/rss?q={query}', 'keywords' => 'linen']);
        Http::fake(['news.example/*' => Http::response($this->rss('Tin không có từ khoá cấu hình', 'https://bao.example/x'), 200)]);

        $found = app(WebSourceService::class)->search('tweed', 'all');

        $this->assertSame(1, $found['count'], 'Kết quả tìm không được bị bộ lọc từ khoá của nguồn nuốt mất.');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'q=tweed'));
    }

    /** Không nguồn tìm kiếm nào được bật ⇒ nói THẲNG lý do, không im lặng trả về rỗng. */
    public function test_search_reports_when_no_search_source_is_configured(): void
    {
        $this->source(['slug' => 'feed-thuong', 'url' => 'https://feed.example/rss']);

        $found = app(WebSourceService::class)->search('tweed', 'all');

        $this->assertSame('empty', $found['mode']);
        $this->assertSame(0, $found['count']);
        $this->assertSame('chưa có nguồn tìm kiếm nào được bật', $found['error']);
    }

    /** Từ khoá rỗng (model gọi công cụ mà không truyền gì) ⇒ KHÔNG đi mạng, và nói rõ. */
    public function test_search_refuses_an_empty_query(): void
    {
        $this->source(['slug' => 'google-news', 'url' => 'https://news.example/rss?q=thoi+trang']);
        Http::fake();

        $found = app(WebSourceService::class)->search('   ', 'all');

        $this->assertSame('empty', $found['mode']);
        $this->assertSame('từ khoá rỗng', $found['error']);
        Http::assertNothingSent();
    }

    /** Nguồn chỉ có `{query}` KHÔNG được đọc ở đường đọc tin cố định (nếu không là đi hỏi chuỗi "{query}"). */
    public function test_placeholder_sources_are_skipped_by_the_fixed_feed_reader(): void
    {
        $this->source(['slug' => 'chi-tim-kiem', 'url' => 'https://news.example/rss?q={query}']);
        Http::fake();

        $evidence = app(WebSourceService::class)->evidence('all');
        $row = collect($evidence['sources'])->firstWhere('slug', 'chi-tim-kiem');

        $this->assertSame('skipped', $row['state']);
        $this->assertStringContainsString('tìm kiếm', (string) $row['state_label']);
        Http::assertNothingSent();
    }

    // ── (C) API CẤU HÌNH ───────────────────────────────────────────────────

    /** Chỉ QUẢN TRỊ VIÊN cấu hình được nguồn (đây là thiết lập toàn cục, không phải dữ liệu của khách). */
    public function test_only_admins_can_manage_sources(): void
    {
        $this->getJson('/api/admin/web-sources')->assertStatus(401);
        $this->actingAs($this->customer(), 'web')->getJson('/api/admin/web-sources')->assertStatus(403);
        $this->actingAs($this->admin(), 'web')->getJson('/api/admin/web-sources')->assertOk();
    }

    /** Thêm/sửa/xoá + LẤY THỬ ngay (test trả về số tin thật sau lọc). */
    public function test_admin_can_add_test_and_delete_a_source(): void
    {
        Http::fake(['moi.example/*' => Http::response($this->rss('Tin mới', 'https://moi.example/1'), 200)]);

        $created = $this->actingAs($this->admin(), 'web')->postJson('/api/admin/web-sources', [
            'slug' => 'nguon-moi', 'name' => 'Nguồn mới', 'url' => 'https://moi.example/rss',
            'kind' => 'rss', 'max_items' => 3, 'keywords' => 'tin',
        ])->assertStatus(201)->json('source');

        $this->assertSame('nguon-moi', $created['slug']);

        $test = $this->actingAs($this->admin(), 'web')
            ->postJson('/api/admin/web-sources/nguon-moi/test')
            ->assertOk()
            ->json();
        $this->assertTrue($test['ok']);
        $this->assertSame(1, $test['count']);

        $this->actingAs($this->admin(), 'web')->deleteJson('/api/admin/web-sources/nguon-moi')->assertOk();
        $this->assertSame(0, WebSource::query()->where('slug', 'nguon-moi')->count());
    }

    /** KHÔNG nhận URL nội bộ/không phải http(s) — nguồn dữ liệu không được thành đường đọc file máy chủ. */
    public function test_source_url_must_be_public_http(): void
    {
        foreach (['file:///etc/passwd', '/etc/passwd', 'ftp://x/y'] as $bad) {
            $this->actingAs($this->admin(), 'web')->postJson('/api/admin/web-sources', [
                'slug' => 'nguon-xau', 'name' => 'Xấu', 'url' => $bad, 'kind' => 'rss',
            ])->assertStatus(422);
        }
    }

    /**
     * Nguồn TÌM KIẾM có `{query}` trong URL PHẢI lưu được từ giao diện.
     *
     * [LỖI THẬT — production 2026-09-21] Rule `url` của Laravel từ chối dấu { } nên URL
     * `…&q={query}` không lưu được: người khai bấm Lưu và nhận "The url field must be a valid URL"
     * trong khi URL hoàn toàn đúng (log production ghi đúng câu lỗi này ở client_error).
     */
    public function test_a_search_source_with_a_query_placeholder_can_be_saved(): void
    {
        $this->actingAs($this->admin(), 'web')->postJson('/api/admin/web-sources', [
            'slug' => 'google-web', 'name' => 'Google — tìm kiếm web chung', 'kind' => 'search',
            'url' => 'https://www.googleapis.com/customsearch/v1?cx=CX1&num=10&hl=vi&q={query}',
            'items_path' => 'items', 'title_field' => 'title', 'link_field' => 'link', 'summary_field' => 'snippet',
            'max_items' => 10,
        ])->assertStatus(201);

        $this->assertDatabaseHas('web_sources', ['slug' => 'google-web', 'kind' => 'search']);
    }

    /** Nút "Thêm nguồn mẫu": tạo các nguồn mặc định còn thiếu, KHÔNG ghi đè nguồn đã có. */
    public function test_seeding_defaults_does_not_overwrite_existing_sources(): void
    {
        $this->source(['slug' => 'google-news-thoi-trang', 'name' => 'Tên tôi tự đặt']);

        $this->actingAs($this->admin(), 'web')->postJson('/api/admin/web-sources/seed')->assertOk();

        $this->assertSame('Tên tôi tự đặt', WebSource::query()->where('slug', 'google-news-thoi-trang')->value('name'));
        $this->assertGreaterThan(1, WebSource::query()->count(), 'Các nguồn mặc định còn thiếu phải được tạo.');
    }
}
