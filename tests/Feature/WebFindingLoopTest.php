<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Models\User;
use App\Models\WebFinding;
use App\Models\WebSource;
use App\Services\DesignAgentService;
use App\Services\WebFindingService;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * VÒNG KHÉP KÍN CỦA CÔNG CỤ TÌM KIẾM (2026-09-26).
 *
 * Vấn đề: \`web_search\` đã chạy thật, nhưng kết quả chỉ sống trong ĐÚNG một lời gọi model — tra xong là
 * quên. Lượt sau hỏi lại câu cũ phải đi mạng lại, giao diện chỉ có con số đếm, và người dùng không có chỗ
 * nào để giữ một nguồn hay.
 *
 * Vòng khép kín mà bộ test này khoá, từng mắt xích một:
 *   1. TÌM   — công cụ chạy thật (đã có test riêng ở ToolSearchTest);
 *   2. LƯU   — nguồn tìm được ghi vào SỔ của chính tài khoản đó;
 *   3. DÙNG LẠI — lần tra sau không ra gì thì trả nguồn trong sổ, và NÓI RÕ là nguồn cũ (reused);
 *   4. QUAY VỀ — nguồn trong sổ xuất hiện trong khối DỮ LIỆU của Agent Studio ở lượt chạy sau;
 *   5. ĐỌC TRANG — chỉ đọc được địa chỉ ĐÃ có trong kết quả tìm kiếm (chốt chống SSRF do model điều khiển);
 *   6. NGƯỜI DÙNG — xem được sổ, lưu được nguồn, và KHÔNG chạm được vào sổ của tài khoản khác.
 */
class WebFindingLoopTest extends TestCase
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

    /** Khai provider + key + model cho một nhóm công việc (không khai \`search_param\`). */
    private function model(string $group, string $slug, string $modelId): void
    {
        StudioProvider::create([
            'slug' => $slug, 'name' => 'Gateway '.$slug, 'protocol' => 'openai',
            'base_url' => 'https://'.$slug.'.example/v1', 'auth_style' => 'bearer',
            'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
        ]);
        StudioApiKey::create([
            'provider' => $slug, 'label' => $slug, 'value' => 'sk-'.$slug,
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        StudioModel::create([
            'group' => $group, 'name' => $modelId, 'provider' => $slug,
            'model_id' => $modelId, 'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_'.$group.'_model', $slug.':'.$modelId);
    }

    /** Nguồn TÌM ĐƯỢC: URL có sẵn tham số \`q=\` — máy chủ thay từ khoá của model vào đó. */
    private function searchSource(): WebSource
    {
        return WebSource::create([
            'slug' => 'google-news', 'name' => 'Google News — tìm theo từ khoá',
            'url' => 'https://news.example/rss/search?q=thoi+trang&hl=vi&gl=VN',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 8,
        ]);
    }

    private function rss(string $title, string $link, string $summary = ''): string
    {
        return '<?xml version="1.0"?><rss version="2.0"><channel>'
            .'<item><title><![CDATA['.$title.']]></title><link>'.$link.'</link>'
            .'<description><![CDATA['.$summary.']]></description>'
            .'<pubDate>'.date('r', time() - 3600).'</pubDate></item>'
            .'</channel></rss>';
    }

    private function emptyRss(): string
    {
        return '<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>';
    }

    private function briefJson(): string
    {
        return (string) json_encode([
            'narrative' => 'n', 'brief' => 'b', 'prompt_vi' => 'vi', 'prompt_en' => 'en',
            'moodboard_captions' => array_fill(0, 24, 'c'), 'category_rationale' => [], 'outfit_goals' => [],
            'next_steps' => ['a', 'b', 'c'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Phản hồi model: ĐÒI GỌI một công cụ (\`web_search\` hoặc \`read_page\`). */
    private function toolCall(string $name, array $arguments, string $id = 'call_1'): array
    {
        return ['choices' => [['message' => [
            'content' => '',
            'tool_calls' => [[
                'id' => $id, 'type' => 'function',
                'function' => ['name' => $name, 'arguments' => (string) json_encode($arguments)],
            ]],
        ]]]];
    }

    private function answer(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    /** Nội dung các lượt gọi model đã GỬI ĐI — để soi kết quả công cụ có quay lại prompt hay không. */
    private function toolMessages(array $sent): array
    {
        $last = end($sent);
        $messages = array_values(array_filter((array) ($last['messages'] ?? []), fn ($m) => ($m['role'] ?? '') === 'tool'));

        return array_map(fn ($m) => json_decode((string) $m['content'], true), $messages);
    }

    // ── (1) LƯU: nguồn tìm được vào SỔ của tài khoản ─────────────────────────

    public function test_a_real_search_is_written_to_the_findings_ledger(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);

                return count($sent) === 1
                    ? Http::response($this->toolCall('web_search', ['query' => 'áo dạ tweed']), 200)
                    : Http::response($this->answer($this->briefJson()), 200);
            },
            // Nguồn TRẢ LỜI THEO TỪ KHOÁ: chỉ câu hỏi của model là ra tin, các câu hỏi CHUNG của lượt tra
            // nền trả về rỗng. Vì sao phải phân biệt: [ĐỔI CHÍNH SÁCH 2026-09-26] đường brief nay LUÔN chạy
            // một lượt tra chung trước lượt model, nên nếu nguồn trả cùng một bài cho MỌI từ khoá thì bài đó
            // đã nằm trong sổ từ trước đó và phép đếm "ghi mới" của lượt model sẽ luôn ra 0 — test sẽ đỏ vì
            // cách giả lập, không phải vì tính năng. Phép thử "tra thật thì ghi vào sổ" giữ nguyên.
            'news.example/*' => function ($request) {
                return str_contains(urldecode($request->url()), 'áo dạ tweed')
                    ? Http::response($this->rss('Áo dạ tweed lên ngôi mùa thu', 'https://bao.example/tweed', 'Tweed là chất liệu được nhắc nhiều nhất tháng này.'), 200)
                    : Http::response($this->emptyRss(), 200);
            },
        ]);

        $customer = $this->customer();
        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $customer, true, true);

        // (1) SỐ ĐO nói thật chặng LƯU: 1 nguồn mới vào sổ.
        $this->assertSame(1, $brief['model']['tool_search']['stored'], 'Nguồn tìm được phải được ghi vào sổ.');
        $this->assertSame(0, $brief['model']['tool_search']['reused'], 'Lượt đầu chưa có gì để dùng lại.');

        // (2) SỔ có thật hàng đó, thuộc ĐÚNG tài khoản, kèm từ khoá + đoạn trích (không chỉ URL trần).
        $row = WebFinding::query()->where('user_id', $customer->id)->first();
        $this->assertNotNull($row, 'Phải có hàng trong sổ nguồn của chính tài khoản này.');
        $this->assertSame('áo dạ tweed', $row->query);
        $this->assertSame('https://bao.example/tweed', $row->url);
        $this->assertSame('Áo dạ tweed lên ngôi mùa thu', $row->title);
        $this->assertStringContainsString('Tweed là chất liệu', (string) $row->snippet);
        $this->assertNull($row->saved_at, 'Nguồn do MÁY tìm không được tự đánh dấu là đã lưu.');

        // (3) SỔ TRÍCH DẪN của lượt: kết quả có mã ổn định để giao diện dẫn nguồn và để công cụ đọc trang.
        $citations = $brief['model']['tool_search']['citations'];
        $this->assertCount(1, $citations);
        $this->assertSame('src_1', $citations[0]['ref']);
        $this->assertSame('https://bao.example/tweed', $citations[0]['url']);

        // (4) Kết quả công cụ quay lại prompt CÓ mã và ĐOẠN TRÍCH (không chỉ tiêu đề).
        $payload = $this->toolMessages($sent)[0];
        $this->assertSame('src_1', $payload['results'][0]['ref']);
        $this->assertStringContainsString('Tweed là chất liệu', (string) $payload['results'][0]['snippet']);
    }

    // ── (2) DÙNG LẠI: mạng không có gì mới thì lấy nguồn trong sổ ────────────

    public function test_a_search_with_no_live_result_reuses_a_stored_source(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        // Sổ đã có nguồn của CHÍNH tài khoản này (như thể hôm qua AI đã tra câu này).
        $customer = $this->customer();
        app(WebFindingService::class)->remember($customer, 'áo dạ tweed', 'all', [[
            'title' => 'Áo dạ tweed: 5 mẫu đáng may', 'url' => 'https://bao.example/tweed-cu',
            'summary' => 'Nguồn đã đọc hôm qua.', 'source_name' => 'Báo Mẫu',
        ]]);

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);

                return count($sent) === 1
                    ? Http::response($this->toolCall('web_search', ['query' => 'áo dạ tweed']), 200)
                    : Http::response($this->answer($this->briefJson()), 200);
            },
            // Lần tra MỚI không có tin nào (nguồn tạm chết / không khớp) — đúng tình huống cần dùng lại.
            'news.example/*' => Http::response($this->emptyRss(), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $customer, true, true);
        $measure = $brief['model']['tool_search'];

        $this->assertSame(1, $measure['reused'], 'Không có kết quả mới thì phải dùng lại nguồn đã tra.');
        $this->assertSame(1, $measure['results'], 'Nguồn dùng lại vẫn phải được ĐẾM là kết quả.');

        // Nguồn cũ quay lại prompt KÈM CỜ reused — model phải biết đây không phải tin vừa lấy.
        $payload = $this->toolMessages($sent)[0];
        $this->assertSame('https://bao.example/tweed-cu', $payload['results'][0]['url']);
        $this->assertTrue($payload['results'][0]['reused'], 'Nguồn từ sổ phải mang cờ reused=true.');
        $this->assertStringContainsString('reused=true', (string) $payload['note'], 'Phải NÓI RÕ với model là nguồn cũ.');
    }

    // ── (3) QUAY VỀ: sổ nuôi lại khối DỮ LIỆU của Agent Studio ───────────────

    public function test_stored_sources_feed_the_agent_data_block_on_the_next_run(): void
    {
        Http::fake(['*' => Http::response($this->emptyRss(), 200)]);

        $customer = $this->customer();
        app(WebFindingService::class)->remember($customer, 'vải linen giá', 'all', [[
            'title' => 'Giá vải linen tăng nhẹ', 'url' => 'https://bao.example/linen-gia',
            'summary' => 'Giá tăng 4% so với quý trước.', 'source_name' => 'Báo Ngành',
        ]]);

        // Radar chạy KHÔNG AI (tất định): vẫn phải thấy nguồn trong sổ — đó chính là "quay lại phục vụ".
        $radar = app(DesignAgentService::class)->radar($customer, 'all', false);
        $evidence = $radar['external_evidence'];

        $this->assertSame(1, $evidence['findings_count'], 'Nguồn trong sổ phải được trộn vào khối DỮ LIỆU.');
        $urls = array_column($evidence['items'], 'url');
        $this->assertContains('https://bao.example/linen-gia', $urls);

        $mine = collect($evidence['items'])->firstWhere('url', 'https://bao.example/linen-gia');
        $this->assertSame('ai_search', $mine['found_by'], 'Phải phân biệt được nguồn AI tra với tin máy chủ vừa lấy.');
        $this->assertSame('vải linen giá', $mine['found_query']);
        $this->assertSame('live', $evidence['mode'], 'Có nguồn thật trong prompt ⇒ mode phải là live.');
    }

    /**
     * NGUỒN ĐÃ KHAI (trang/RSS) KHÔNG ĐƯỢC VÀO KHỐI DỮ LIỆU CỦA BƯỚC 2 — chỉ kết quả TÌM KIẾM.
     *
     * [ĐỔI CHÍNH SÁCH 2026-09-26 — lần thứ TƯ và cũng là lần cuối của câu hỏi "cái gì đứng trước"]
     * Bài này từng khoá ba thang khác nhau: "nguồn đã lưu đứng đầu", rồi "tin vừa lấy đứng đầu", rồi
     * "kết quả tìm kiếm trước — nguồn khai sau". Cả ba đều tranh luận về THỨ TỰ của một danh sách mà chủ dự
     * án đã nói là không được tồn tại: bước 2 phải dùng DỮ LIỆU TÌM KIẾM, không phải RSS/trang báo.
     * Nay khoá luật mạnh hơn hẳn: nguồn kind=rss/page có tin SỐNG cũng KHÔNG xuất hiện trong khối này,
     * còn nguồn trong SỔ (đã lưu) vẫn phải đứng ĐẦU — assert cũ không bị bỏ, chỉ đổi sang luật mới.
     */
    public function test_configured_page_or_rss_sources_never_enter_the_data_block(): void
    {
        // Feed sống: một nguồn RSS bình thường (không phải nguồn tìm kiếm).
        WebSource::create([
            'slug' => 'bao-nganh', 'name' => 'Báo Ngành', 'url' => 'https://feed.example/rss',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 8,
        ]);

        Http::fake([
            'feed.example/*' => Http::response($this->rss('Tin VỪA LẤY về vải linen', 'https://bao.example/tin-moi'), 200),
        ]);

        $customer = $this->customer();

        // Sổ đã có một nguồn, và người dùng ĐÃ LƯU nó (trường hợp mạnh nhất của "bản cũ").
        app(WebFindingService::class)->remember($customer, 'linen cũ', 'all', [[
            'title' => 'Nguồn ĐÃ LƯU', 'url' => 'https://bao.example/da-luu', 'summary' => 's', 'source_name' => 'Báo Cũ',
        ]]);
        $row = WebFinding::query()->where('user_id', $customer->id)->firstOrFail();
        app(WebFindingService::class)->markSaved($customer, (int) $row->id, true);

        $items = app(DesignAgentService::class)->radar($customer, 'all', false)['external_evidence']['items'];

        // (1) Nguồn trong SỔ (người dùng ĐÃ LƯU) là kết quả TÌM KIẾM ⇒ vẫn đứng đầu khối dữ liệu.
        $this->assertSame('https://bao.example/da-luu', $items[0]['url'],
            'Kết quả TÌM KIẾM đã lưu phải đứng ĐẦU khối DỮ LIỆU.');
        $this->assertSame('ai_search', $items[0]['found_by'] ?? null);
        $this->assertTrue($items[0]['saved'], 'Nguồn người dùng đã lưu vẫn phải giữ nhãn.');
        // (2) Tin của NGUỒN ĐÃ KHAI (trang chuyên mục / RSS) KHÔNG được có mặt — kể cả khi nguồn đó SỐNG và
        // vừa trả về tin mới. Đây là điều chủ dự án yêu cầu: bước 2 dùng kết quả TÌM KIẾM, không dùng RSS.
        $urls = array_column($items, 'url');
        $this->assertNotContains('https://bao.example/tin-moi', $urls,
            'Nguồn kind=rss/page không được vào khối dữ liệu của bước 2.');
        $this->assertSame(['https://bao.example/da-luu'], $urls);
    }

    // ── (4)(5) ĐỌC TRANG: chỉ đọc địa chỉ có trong kết quả tìm kiếm ──────────

    public function test_read_page_refuses_an_address_that_is_not_in_the_search_results(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);

                return match (count($sent)) {
                    1 => Http::response($this->toolCall('web_search', ['query' => 'áo dạ tweed'], 'call_1'), 200),
                    // Model tự nghĩ ra một địa chỉ KHÔNG có trong kết quả tìm kiếm.
                    2 => Http::response($this->toolCall('read_page', ['url' => 'https://la.example/bi-mat'], 'call_2'), 200),
                    default => Http::response($this->answer($this->briefJson()), 200),
                };
            },
            'news.example/*' => Http::response($this->rss('Áo dạ tweed lên ngôi', 'https://bao.example/tweed'), 200),
            'la.example/*' => Http::response('<html><body>BÍ MẬT</body></html>', 200),
        ]);

        app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        // Máy chủ KHÔNG được đi đọc địa chỉ do model bịa — đây là chốt chống SSRF/leak do model điều khiển.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'la.example'));

        $results = $this->toolMessages($sent);
        $refused = collect($results)->firstWhere('url', 'https://la.example/bi-mat');
        $this->assertNotNull($refused, 'Công cụ phải trả lời cho lời gọi đọc trang sai.');
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('NẰM TRONG kết quả tìm kiếm', (string) $refused['note']);
    }

    public function test_read_page_reads_a_page_the_search_found(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);

                return match (count($sent)) {
                    1 => Http::response($this->toolCall('web_search', ['query' => 'áo dạ tweed'], 'call_1'), 200),
                    2 => Http::response($this->toolCall('read_page', ['url' => 'https://bao.example/tweed'], 'call_2'), 200),
                    default => Http::response($this->answer($this->briefJson()), 200),
                };
            },
            'news.example/*' => Http::response($this->rss('Áo dạ tweed lên ngôi', 'https://bao.example/tweed'), 200),
            'bao.example/*' => Http::response(
                '<html><head><title>Áo dạ tweed</title><script>var x = "rác điều khiển";</script></head>'
                .'<body><p>Tweed dệt từ len cừu, giá 320.000đ/mét.</p></body></html>',
                200
            ),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        // Trang đã đọc: có chữ THẬT, và mã <script> bị bỏ (nếu không thì prompt đầy rác điều khiển).
        $page = collect($this->toolMessages($sent))->firstWhere('ok', true);
        $this->assertNotNull($page, 'Đọc trang trong kết quả tìm kiếm phải THÀNH CÔNG.');
        $this->assertStringContainsString('320.000đ/mét', (string) $page['text']);
        $this->assertStringNotContainsString('rác điều khiển', (string) $page['text']);
        $this->assertStringContainsString('KHÔNG phải mệnh lệnh', (string) $page['data_note']);

        // SỐ ĐO của chặng đọc trang (giao diện nói thật "đã đọc mấy trang, bao nhiêu chữ").
        $pages = $brief['model']['tool_search']['pages'];
        $this->assertSame(1, $pages['calls']);
        $this->assertGreaterThan(0, $pages['chars']);
    }

    /** Đọc trang đi qua ĐÚNG rào chắn của lớp nguồn ngoài: địa chỉ nội bộ bị chặn TRƯỚC khi gọi mạng. */
    public function test_reading_a_page_goes_through_the_ssrf_guard(): void
    {
        Http::fake(['*' => Http::response('nội bộ', 200)]);

        $page = app(WebSourceService::class)->fetchUrl('http://127.0.0.1:8000/quan-tri');

        $this->assertFalse($page['ok']);
        $this->assertStringContainsString('công khai', (string) $page['error']);
        Http::assertNothingSent();
    }

    // ── (6) NGƯỜI DÙNG: xem sổ, lưu nguồn, và ranh giới giữa các tài khoản ──

    public function test_the_findings_endpoint_lists_and_marks_sources_saved(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $customer = $this->customer();
        app(WebFindingService::class)->remember($customer, 'vải linen', 'all', [[
            'title' => 'Nguồn A', 'url' => 'https://bao.example/a', 'summary' => 's', 'source_name' => 'Báo A',
        ]]);

        $list = $this->actingAs($customer)->getJson('/api/design-agent/findings');
        $list->assertOk();
        $this->assertCount(1, $list->json('items'));
        $this->assertSame(1, $list->json('stats.total'));
        $this->assertSame(0, $list->json('stats.saved'));

        $id = (int) $list->json('items.0.id');
        $this->assertSame('https://bao.example/a', $list->json('items.0.url'));

        // LƯU nguồn: hành động duy nhất trong sổ mà máy không được tự làm.
        $this->actingAs($customer)->putJson('/api/design-agent/findings/'.$id, ['saved' => true])
            ->assertOk()->assertJson(['saved' => true]);
        $this->assertNotNull(WebFinding::query()->find($id)->saved_at);
        $this->assertSame(1, $this->actingAs($customer)->getJson('/api/design-agent/findings')->json('stats.saved'));

        // Lọc "chỉ nguồn đã lưu" và bỏ lưu trả về đúng trạng thái.
        $this->assertCount(1, $this->actingAs($customer)->getJson('/api/design-agent/findings?saved=1')->json('items'));
        $this->actingAs($customer)->putJson('/api/design-agent/findings/'.$id, ['saved' => false])->assertOk();
        $this->assertCount(0, $this->actingAs($customer)->getJson('/api/design-agent/findings?saved=1')->json('items'));

        // Nguồn không có trong sổ ⇒ 404, không phải im lặng thành công.
        $this->actingAs($customer)->putJson('/api/design-agent/findings/99999', ['saved' => true])->assertNotFound();
    }

    /**
     * Giao diện gọi đường lưu nguồn bằng POST + \`_method=PUT\` (xem store/actions/agentStudio.js, cùng cách
     * các action khác trong store đang làm). Nếu Laravel KHÔNG đọc \`_method\` trong body JSON thì nút "Lưu
     * nguồn" trên màn hình sẽ nhận 405 mà không ai thấy cho tới khi có người bấm — nên khoá luôn ở đây.
     */
    public function test_the_save_endpoint_accepts_the_method_spoof_the_store_uses(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $customer = $this->customer();
        app(WebFindingService::class)->remember($customer, 'vải linen', 'all', [[
            'title' => 'Nguồn A', 'url' => 'https://bao.example/a', 'summary' => 's',
        ]]);
        $id = (int) WebFinding::query()->where('user_id', $customer->id)->value('id');

        $this->actingAs($customer)
            ->postJson('/api/design-agent/findings/'.$id, ['saved' => true, '_method' => 'PUT'])
            ->assertOk()
            ->assertJson(['saved' => true]);

        $this->assertNotNull(WebFinding::query()->find($id)->saved_at);
    }

    public function test_a_finding_of_another_account_is_not_reachable(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        app(WebFindingService::class)->remember($other, 'câu hỏi riêng', 'all', [[
            'title' => 'Chiến lược của đối thủ', 'url' => 'https://bao.example/rieng-tu', 'summary' => 's',
        ]]);
        $row = WebFinding::query()->where('user_id', $other->id)->firstOrFail();

        // Khách khác không được thấy và không được sửa nguồn này.
        $mine = $this->actingAs($this->customer())->getJson('/api/design-agent/findings');
        $mine->assertOk();
        $this->assertSame(0, $mine->json('stats.total'), 'Sổ nguồn phải tách theo tài khoản.');
        $this->actingAs($this->customer())->putJson('/api/design-agent/findings/'.$row->id, ['saved' => true])
            ->assertNotFound();
        $this->assertNull($row->fresh()->saved_at, 'Nguồn của tài khoản khác phải KHÔNG bị chạm tới.');
    }

    /** Giao diện đang đọc khoá nào thì chúng phải còn nguyên — thêm khoá mới không được làm mất khoá cũ. */
    public function test_the_tool_measurement_keeps_the_keys_the_interface_reads(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);

                return count($sent) === 1
                    ? Http::response($this->toolCall('web_search', ['query' => 'áo dạ tweed']), 200)
                    : Http::response($this->answer($this->briefJson()), 200);
            },
            'news.example/*' => Http::response($this->rss('Tin thật', 'https://bao.example/tin'), 200),
        ]);

        $measure = app(DesignAgentService::class)
            ->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true)['model']['tool_search'];

        foreach (['mode', 'enabled', 'accepted', 'calls', 'queries', 'results', 'sources', 'truncated', 'error'] as $key) {
            $this->assertArrayHasKey($key, $measure, 'Thiếu khoá cũ mà giao diện đang đọc: '.$key);
        }
        foreach (['stored', 'updated', 'reused', 'pages', 'citations'] as $key) {
            $this->assertArrayHasKey($key, $measure, 'Thiếu khoá của vòng khép kín: '.$key);
        }
    }
}
