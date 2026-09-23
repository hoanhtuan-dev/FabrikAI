<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Models\User;
use App\Models\WebSource;
use App\Services\DesignAgentService;
use App\Services\WebAccessService;
use App\Services\WebSearchTool;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TOOL SEARCH — "Tìm kiếm nguồn ngoài" chạy được với model KHÔNG có tìm kiếm tích hợp (2026-09-24).
 *
 * Vấn đề đo được trên production: cách bật tìm kiếm cũ chỉ biết nhà cung cấp TỰ có tìm kiếm (DashScope
 * `enable_search`, Gemini `google_search`, Custom Provider tự khai `search_param`). Model văn bản đang chạy
 * là DeepSeek trên giao thức OpenAI-compatible — giao thức này không có cờ tìm kiếm — nên gán model vào nhóm
 * "Agent Studio — Tìm kiếm nguồn ngoài" vẫn KHÔNG tìm được gì và lượt chạy lặng lẽ quay về nhóm suy luận.
 *
 * Cách sửa: máy chủ khai công cụ `web_search` theo chuẩn function-calling, MODEL tự quyết định hỏi gì, MÁY
 * CHỦ đi tìm thật (nguồn có chỗ điền từ khoá), rồi trả kết quả về prompt.
 *
 * Bộ test này khoá: kết quả tìm THẬT SỰ vào prompt · số đo nói thật · provider từ chối công cụ thì KHÔNG
 * được nói là đã tìm · vai tìm kiếm bỏ trống thì KHÔNG tự thêm công cụ (không đội thời gian chờ của mọi
 * lượt chạy) · tìm không ra thì không được bịa.
 */
class ToolSearchTest extends TestCase
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

    /** Khai một provider + key + model cho một nhóm công việc (không khai `search_param`). */
    private function model(string $group, string $slug, string $modelId, array $extra = []): void
    {
        StudioProvider::create(array_merge([
            'slug' => $slug, 'name' => 'Gateway '.$slug, 'protocol' => 'openai',
            'base_url' => 'https://'.$slug.'.example/v1', 'auth_style' => 'bearer',
            'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
        ], $extra));
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

    /** Nguồn TÌM ĐƯỢC: URL có sẵn tham số `q=` — máy chủ thay từ khoá của model vào đó. */
    private function searchSource(): WebSource
    {
        return WebSource::create([
            'slug' => 'google-news', 'name' => 'Google News — tìm theo từ khoá',
            'url' => 'https://news.example/rss/search?q=thoi+trang&hl=vi&gl=VN',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 8,
        ]);
    }

    private function rss(string $title, string $link): string
    {
        return '<?xml version="1.0"?><rss version="2.0"><channel>'
            .'<item><title><![CDATA['.$title.']]></title><link>'.$link.'</link>'
            .'<pubDate>'.date('r', time() - 3600).'</pubDate></item>'
            .'</channel></rss>';
    }

    private function briefJson(): string
    {
        return (string) json_encode([
            'narrative' => 'n', 'brief' => 'b', 'prompt_vi' => 'vi', 'prompt_en' => 'en',
            'moodboard_captions' => array_fill(0, 24, 'c'), 'category_rationale' => [], 'outfit_goals' => [],
            'next_steps' => ['a', 'b', 'c'],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function directionsJson(array $extra = []): string
    {
        return (string) json_encode(array_merge([
            'directions' => array_fill(0, 6, ['title' => 'Hướng', 'thesis' => 't', 'why_now' => 'w', 'action' => 'a', 'risk' => 'r', 'price_band' => 'mid']),
        ], $extra), JSON_UNESCAPED_UNICODE);
    }

    /** Phản hồi của model: ĐÒI GỌI công cụ `web_search` với một từ khoá. */
    private function toolCallResponse(string $query, string $id = 'call_1'): array
    {
        return ['choices' => [['message' => [
            'content' => '',
            'tool_calls' => [[
                'id' => $id, 'type' => 'function',
                'function' => ['name' => 'web_search', 'arguments' => (string) json_encode(['query' => $query])],
            ]],
        ]]]];
    }

    /** @return array{message: array} */
    private function answerResponse(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    // ── (A) VÒNG LẶP CÔNG CỤ CHẠY THẬT ─────────────────────────────────────

    /**
     * Đường brief: model gọi công cụ ⇒ MÁY CHỦ đi tìm theo ĐÚNG từ khoá ⇒ kết quả quay lại prompt ⇒ model
     * viết JSON. Khoá cả bốn chặng vì thiếu chặng nào thì "tìm kiếm" chỉ là chữ trên giao diện.
     */
    public function test_the_search_role_runs_a_real_tool_search_and_feeds_results_back(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);
                if (count($sent) === 1) {
                    return Http::response($this->toolCallResponse('áo dạ tweed'), 200);
                }

                return Http::response($this->answerResponse($this->briefJson()), 200);
            },
            'news.example/*' => Http::response($this->rss('Áo dạ tweed lên ngôi mùa thu', 'https://bao.example/tweed'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        // (1) Lượt chạy dùng ĐÚNG model của vai tìm kiếm và báo là AI.
        $this->assertSame('ai-v1', $brief['engine']);
        $this->assertSame('gw-search', $brief['model']['provider']);
        $this->assertSame('search-1', $brief['model']['model']);

        // (2) Số đo nói THẬT: đã bật công cụ, provider chấp nhận, gọi 1 lượt, 1 tin.
        $this->assertTrue($brief['model']['web_search'], 'Có công cụ tìm kiếm chạy được thì web_search phải bật.');
        $this->assertSame('tool', $brief['model']['tool_search']['mode']);
        $this->assertTrue($brief['model']['tool_search']['accepted']);
        $this->assertSame(1, $brief['model']['tool_search']['calls']);
        $this->assertSame(['áo dạ tweed'], $brief['model']['tool_search']['queries']);
        $this->assertSame(1, $brief['model']['tool_search']['results']);

        // (3) MÁY CHỦ thật sự đi tìm theo ĐÚNG từ khoá model hỏi (không phải đọc lại feed cố định).
        Http::assertSent(fn ($request) => str_contains($request->url(), 'news.example')
            && str_contains($request->url(), rawurlencode('áo dạ tweed')));

        // (4) Kết quả tìm QUAY LẠI prompt: lượt gọi thứ hai có khai công cụ và có tin thật trong role "tool".
        $this->assertCount(2, $sent, 'Phải có đúng hai lượt gọi model: lượt gọi công cụ và lượt trả lời.');
        $this->assertSame('web_search', $sent[0]['tools'][0]['function']['name'] ?? null, 'Lượt đầu phải KHAI công cụ cho model.');
        $tools = array_values(array_filter((array) $sent[1]['messages'], fn ($m) => ($m['role'] ?? '') === 'tool'));
        $this->assertNotEmpty($tools, 'Lượt sau phải chứa kết quả công cụ (role "tool").');
        $this->assertStringContainsString('Áo dạ tweed lên ngôi mùa thu', (string) $tools[0]['content']);
    }

    /** Đường RADAR cũng chạy công cụ khi vai tìm kiếm được khai (không chỉ đường brief). */
    public function test_radar_uses_the_search_tool_too(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$calls, &$sent) {
                $sent[] = json_decode($request->body(), true);
                $calls++;

                return $calls === 1
                    ? Http::response($this->toolCallResponse('xu hướng tweed'), 200)
                    : Http::response($this->answerResponse($this->directionsJson()), 200);
            },
            'news.example/*' => Http::response($this->rss('Xu hướng tweed', 'https://bao.example/1'), 200),
        ]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', true);

        $this->assertSame('ai-v1', $radar['engine']);
        $this->assertTrue($radar['model']['web_search']);
        $this->assertSame('tool', $radar['model']['tool_search']['mode']);
        // Radar chạy CÔNG CỤ DO MÁY CHỦ CHẠY (đường /chat/completions), không phải /responses — nên
        // request phải KHAI hàm web_search; trần lượt gọi do WebSearchTool::MAX_CALLS quyết định.
        $this->assertSame('web_search', data_get($sent[0] ?? [], 'tools.0.function.name'));
        $this->assertSame(['xu hướng tweed'], $radar['model']['tool_search']['queries']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'news.example'));
    }

    // ── (B) NÓI THẬT KHI KHÔNG TÌM ĐƯỢC ────────────────────────────────────

    /**
     * Provider TỪ CHỐI tham số `tools` ⇒ lượt chạy VẪN xong (gọi lại không công cụ) nhưng KHÔNG được nói là
     * đã có tìm kiếm. Đây là bài học "một tham số tuỳ chọn không được làm hỏng cả lời gọi", kèm điều kiện
     * thứ hai: không được im lặng hứa suông.
     */
    public function test_a_provider_that_rejects_tools_still_answers_and_says_so(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $bodies = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$bodies) {
                $body = (string) $request->body();
                $bodies[] = $body;
                if (str_contains($body, '"tools"')) {
                    return Http::response(['error' => ['message' => 'unsupported parameter: tools']], 400);
                }

                return Http::response($this->answerResponse($this->briefJson()), 200);
            },
            'news.example/*' => Http::response($this->rss('Không dùng tới', 'https://bao.example/0'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame('ai-v1', $brief['engine'], 'Provider không hiểu công cụ KHÔNG được làm hỏng lượt chạy.');
        $this->assertFalse($brief['model']['web_search'], 'Provider từ chối công cụ thì KHÔNG được nói là đã tìm kiếm.');
        $this->assertFalse($brief['model']['tool_search']['accepted']);
        $this->assertSame(0, $brief['model']['tool_search']['calls']);
        $this->assertCount(2, $bodies, 'Phải thử một lần có công cụ rồi một lần không có.');
        $this->assertStringNotContainsString('"tools"', $bodies[1], 'Lần gọi lại không được mang tham số đã bị từ chối.');
    }

    /** Tìm mà KHÔNG ra tin nào ⇒ model được nói rõ "0 kết quả", và số đo ghi đúng 0 (không bịa). */
    public function test_an_empty_search_result_is_reported_as_zero(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);

                return count($sent) === 1
                    ? Http::response($this->toolCallResponse('chủ đề không tồn tại'), 200)
                    : Http::response($this->answerResponse($this->briefJson()), 200);
            },
            'news.example/*' => Http::response('lỗi máy chủ nguồn', 500),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame(0, $brief['model']['tool_search']['results']);
        $this->assertSame(1, $brief['model']['tool_search']['calls'], 'Đã GỌI công cụ thì vẫn phải đếm là có gọi.');
        $this->assertNotNull($brief['model']['tool_search']['error'], 'Không tìm được thì phải có lý do đọc được.');

        // Model phải ĐỌC ĐƯỢC "0 kết quả" để không tưởng mình vừa đọc được tin.
        $tool = collect((array) ($sent[1]['messages'] ?? []))->firstWhere('role', 'tool');
        $this->assertNotNull($tool);
        $this->assertStringContainsString('"found":0', (string) $tool['content']);
    }

    /** Vai tìm kiếm BỎ TRỐNG ⇒ KHÔNG tự thêm công cụ: mọi lượt chạy không được đội thời gian chờ vô cớ. */
    public function test_the_tool_stays_off_when_the_search_role_is_empty(): void
    {
        $this->model(DesignAgentService::REASON_GROUP, 'gw-reason', 'reason-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-reason.example/*' => function ($request) use (&$sent) {
                $sent[] = (string) $request->body();

                return Http::response($this->answerResponse($this->briefJson()), 200);
            },
            'news.example/*' => Http::response($this->rss('Không dùng tới', 'https://bao.example/0'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame('gw-reason', $brief['model']['provider']);
        $this->assertSame('off', $brief['model']['tool_search']['mode']);
        $this->assertFalse($brief['model']['web_search']);
        $this->assertSame(0, $brief['model']['tool_search']['calls']);
        $this->assertStringNotContainsString('"tools"', $sent[0], 'Không khai vai tìm kiếm thì không được gửi công cụ.');
        // [ĐỔI CHÍNH SÁCH 2026-09-26] Đường đọc tin cố định bằng từ khoá cấu hình của nguồn đã bị BỎ khỏi
        // khối dữ liệu của Agent Studio: nay máy chủ chạy LƯỢT TRA CHUNG bằng các truy vấn chủ đề chung.
        // Assert được viết lại CHẶT HƠN (không bỏ assert nào): vẫn phải có lời gọi tới nguồn tìm kiếm, và
        // câu hỏi gửi đi phải là câu hỏi CHUNG (có tên vùng) — KHÔNG phải từ khoá do model nghĩ ra.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'news.example')
            && str_contains(urldecode($request->url()), 'Việt Nam'));

        // Và từ khoá của model thì KHÔNG được dùng ở lượt này (vai tìm kiếm bỏ trống ⇒ không có công cụ).
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'news.example')
            && str_contains(urldecode($request->url()), 'áo dạ tweed'));
    }

    // ── (C) TRẦN LỜI GỌI ───────────────────────────────────────────────────

    /** Trần lời gọi: model đòi tìm mãi cũng chỉ được tối đa MAX_CALLS lượt, và phải được nhắc trả lời. */
    public function test_the_tool_refuses_to_search_forever(): void
    {
        $this->searchSource();
        Http::fake(['news.example/*' => Http::response($this->rss('Tin', 'https://bao.example/1'), 200)]);

        $tool = new WebSearchTool(app(WebSourceService::class));
        $tool->enable(true);

        $payload = null;
        for ($i = 0; $i < WebSearchTool::MAX_CALLS + 1; $i++) {
            $payload = $tool->handle(['query' => 'từ khoá '.$i], 'all');
        }

        $report = $tool->report();
        $this->assertSame(WebSearchTool::MAX_CALLS, $report['calls'], 'Vượt trần là mỗi lượt chạy thành hàng chục lời gọi mạng.');
        $this->assertTrue($report['truncated']);
        $this->assertStringContainsString('hết', mb_strtolower((string) $payload['note']));
        $this->assertSame(0, $payload['found']);
    }

    /** Mở LẦN THỬ mới ⇒ trần tính lại (lần thử lại bắt đầu hội thoại mới nên kết quả tìm cũ không còn). */
    public function test_a_new_attempt_gets_a_fresh_budget(): void
    {
        $this->searchSource();
        Http::fake(['news.example/*' => Http::response($this->rss('Tin', 'https://bao.example/1'), 200)]);

        $tool = new WebSearchTool(app(WebSourceService::class));
        $tool->enable(true);

        for ($i = 0; $i < WebSearchTool::MAX_CALLS; $i++) {
            $tool->handle(['query' => 'lần một '.$i], 'all');
        }
        $this->assertSame(0, $tool->handle(['query' => 'bị chặn'], 'all')['found']);

        $tool->beginAttempt();
        $after = $tool->handle(['query' => 'lần hai'], 'all');

        $this->assertSame(1, $after['found'], 'Lần thử lại phải tìm được, không bị trần của lần trước chặn.');
        // Số ĐO tổng vẫn cộng dồn để giao diện báo đúng cả lượt chạy.
        $this->assertSame(WebSearchTool::MAX_CALLS + 1, $tool->report()['calls']);
    }

    // ── (D) GIAO DIỆN PHẢI NÓI THẬT ────────────────────────────────────────

    /**
     * Giao diện đọc SỐ ĐO của lượt chạy (khối `model.tool_search`), không đọc một câu văn tĩnh.
     *
     * Vì sao khoá bằng test: đây đúng là lỗi đã gặp nhiều lần trong dự án — màn hình ghi "Nguồn ngoài đang ở
     * chế độ demo" bằng chữ cứng, nên nó nói sai cả khi agent thật sự đã tìm được tin. Quét source để câu
     * hiển thị buộc phải đi qua khối số đo.
     */
    public function test_the_interface_reports_the_tool_search_measurement(): void
    {
        $view = $this->designAgentsSource();

        $this->assertStringContainsString('tool_search', $view, 'Giao diện chưa đọc khối số đo công cụ tìm kiếm.');
        $this->assertStringContainsString('toolSearchLine', $view, 'Thiếu câu hiển thị số đo cho người dùng.');
        $this->assertStringContainsString("provide('toolSearchLine'", $view, 'Shell chưa cung cấp số đo cho các bước.');
        $this->assertStringContainsString('toolSearchLine', (string) file_get_contents(resource_path('js/studio/components/agents/AgentRadarStep.vue')));
        $this->assertStringContainsString('toolSearchLine', (string) file_get_contents(resource_path('js/studio/components/agents/AgentBriefStep.vue')));

        // Ba mức của đường công cụ phải được phân biệt — gộp lại là nói sai (nhà cung cấp TỪ CHỐI công cụ
        // KHÁC hẳn model có công cụ mà không dùng).
        $this->assertStringContainsString('t.accepted === false', $view, 'Phải phân biệt "model không nhận công cụ".');
        $this->assertStringContainsString('t.calls', $view, 'Phải hiện số lượt đã tìm thật.');
    }

    /** Câu mô tả nguồn ngoài không được nói model TỰ ra internet — người đi lấy luôn là máy chủ. */
    public function test_the_copy_never_claims_the_model_browses_by_itself(): void
    {
        $radar = (string) file_get_contents(resource_path('js/studio/components/agents/AgentRadarStep.vue'));

        $this->assertStringNotContainsString('model tự tìm kiếm', $radar, 'Câu cũ nói sai khi công cụ do máy chủ chạy.');
        $this->assertStringContainsString('máy chủ FabrikAI đi lấy', $radar);
    }

    /**
     * Có tin nhưng TẤT CẢ đều quá cũ ≠ không có tin. Hai chuyện này phải được nói KHÁC nhau, nếu không
     * model tưởng "internet không có gì" và dễ bịa hơn.
     */
    public function test_results_that_are_all_too_old_are_reported_as_such(): void
    {
        $this->searchSource();
        $old = '<?xml version="1.0"?><rss version="2.0"><channel>'
            .'<item><title>Tin cũ về tweed</title><link>https://bao.example/old</link>'
            .'<pubDate>'.date('r', time() - 86400 * (WebSourceService::MAX_AGE_DAYS + 30)).'</pubDate></item>'
            .'</channel></rss>';
        Http::fake(['news.example/*' => Http::response($old, 200)]);

        $tool = new WebSearchTool(app(WebSourceService::class));
        $tool->enable(true);
        $payload = $tool->handle(['query' => 'tweed'], 'all');

        $this->assertSame(0, $payload['found']);
        $this->assertSame(1, $payload['read'], 'Phải ghi lại là ĐÃ ĐỌC được một tin.');
        $this->assertSame(1, $payload['too_old'], 'Phải ghi lại là tin đó bị loại vì quá cũ.');
        $this->assertStringContainsString('quá cũ', (string) $payload['note']);
    }

    /**
     * CÔNG CỤ PHẢI ĐƯỢC NÓI RA kể cả khi máy chủ ĐÃ có tin thật.
     *
     * [LỖI THẬT — đo trên production 2026-09-21] Bản đầu viết các mức dữ liệu dưới dạng HOẶC: hễ có
     * external_evidence (tin thật) là prompt KHÔNG nhắc tới công cụ. Đo được trên production: radar chạy
     * deepseek-flash, `tool_search.accepted=true` nhưng `calls=0` — công cụ nằm trong request mà model
     * không biết mình được phép hỏi thêm. Tin lấy theo feed cố định và việc hỏi đúng chủ đề đang cần là
     * hai thứ BỔ SUNG cho nhau, không thay thế nhau.
     */
    public function test_the_tool_is_announced_even_when_live_news_is_present(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-search.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);

                return Http::response($this->answerResponse($this->briefJson()), 200);
            },
            // Nguồn trả tin THẬT ⇒ evidence.mode=live ⇒ nhánh "đã có tin" được dùng trong prompt.
            'news.example/*' => Http::response($this->rss('Tin thật hôm nay về linen', 'https://bao.example/1'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $system = (string) data_get($sent[0] ?? [], 'messages.0.content', '');
        $this->assertStringContainsString('web_search', $system, 'Có tin thật rồi vẫn phải NÓI cho model biết nó được gọi công cụ.');
        $this->assertSame('web_search', data_get($sent[0] ?? [], 'tools.0.function.name'), 'Công cụ phải nằm trong request.');
        $this->assertTrue($brief['model']['tool_search']['enabled']);
    }

    /** Lý do của MỘT truy vấn không được ghi thành "lỗi của cả lượt chạy" khi truy vấn khác đã ra tin. */
    public function test_a_barren_query_does_not_mark_the_whole_run_as_failed(): void
    {
        $this->searchSource();
        Http::fake([
            'news.example/*' => function ($request) {
                // Truy vấn "không có gì" trả feed rỗng; truy vấn còn lại trả tin thật.
                return str_contains(urldecode($request->url()), 'khong-co-gi')
                    ? Http::response('<?xml version="1.0"?><rss version="2.0"><channel></channel></rss>', 200)
                    : Http::response($this->rss('Tin thật', 'https://bao.example/1'), 200);
            },
        ]);

        $tool = new WebSearchTool(app(WebSourceService::class));
        $tool->enable(true);

        $tool->handle(['query' => 'tin that'], 'all');
        $tool->handle(['query' => 'khong-co-gi'], 'all');

        $report = $tool->report();
        $this->assertSame(2, $report['calls']);
        $this->assertSame(1, $report['results']);
        $this->assertNull($report['error'], 'Đã có tin thật thì không được báo lỗi cho cả lượt chạy.');
    }

// ── (F) QWEN 3.8: CÔNG CỤ `web_search` TRÊN /responses, KHÔNG PHẢI CỜ enable_search (2026-09-21) ──
    //
    // ĐO THẬT trên chính khoá/model đang chạy production (Qwen Token Plan · qwen3.8-flash):
    //   · /chat/completions + enable_search:true → HTTP 200, model trả lời "Không truy cập được internet",
    //     phản hồi KHÔNG có `search_info` ⇒ cờ bị BỎ QUA, không có lượt tìm nào;
    //   · /responses + tools:[{type:web_search}] → HTTP 200, có `web_search_call` thật (2 từ khoá, 20 URL
    //     nguồn), và ép `text.format=json_object` thì trả JSON hợp lệ.
    // Trước đợt này Agent Studio đi đường thứ nhất và VẪN khẳng định "lượt này tìm kiếm nguồn ngoài do nhà
    // cung cấp thực hiện" — một câu sai, đo được, hiện ngay trên màn hình khách.

    /** Khai model + khoá của provider `qwen` (KHÔNG tạo Custom Provider — transport phải là qwen). */
    private function qwenModel(string $group, string $modelId): void
    {
        StudioApiKey::create([
            'provider' => 'qwen', 'label' => 'qwen', 'value' => 'sk-qwen-test',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        StudioModel::create([
            'group' => $group, 'name' => $modelId, 'provider' => 'qwen',
            'model_id' => $modelId, 'api_key_ref' => 'qwen', 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_'.$group.'_model', 'qwen:'.$modelId);
    }

    /** Họ model Qwen3.8 phải đi đường CÔNG CỤ: đó là đường duy nhất đo được là có tìm thật. */
    public function test_a_qwen_web_search_model_gets_the_tool_instead_of_the_ignored_flag(): void
    {
        $this->qwenModel(DesignAgentService::SEARCH_GROUP, 'qwen3.8-flash');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'dashscope-intl.aliyuncs.com/*' => $this->hostedSplitFake(
                fn () => Http::response($this->responsesBody($this->findingsJson(), 1, ['xu hướng linen 2026']), 200),
                fn () => Http::response($this->answerResponse($this->briefJson()), 200),
                $sent,
            ),
            'news.example/*' => Http::response($this->rss('Không dùng tới', 'https://bao.example/0'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertStringEndsWith('/responses', (string) $sent[0]['url'],
            'Qwen3.8 phải đi /responses — đó là đường đo được là tìm thật.');
        $this->assertSame('web_search', data_get($sent[0]['body'], 'tools.0.type'));
        $this->assertArrayNotHasKey('enable_search', (array) $sent[0]['body'],
            'Cờ đã đo là bị bỏ qua thì không được gửi thay cho công cụ.');

        $this->assertSame('hosted', $brief['model']['tool_search']['mode']);
        $this->assertSame(1, $brief['model']['tool_search']['calls']);
        $this->assertTrue($brief['model']['web_search']);
    }

    /**
     * Model qwen NGOÀI họ đã đo: vẫn gửi cờ như cũ, nhưng KHÔNG được khai là đã kiểm chứng — và không
     * được nói lượt này có tìm kiếm khi không có gì đối chiếu.
     */
    public function test_a_qwen_model_outside_the_tool_family_is_not_claimed_as_searching(): void
    {
        $this->qwenModel(DesignAgentService::SEARCH_GROUP, 'qwen-plus');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'dashscope-intl.aliyuncs.com/*' => function ($request) use (&$sent) {
                $sent[] = ['url' => $request->url(), 'body' => json_decode($request->body(), true)];

                return Http::response($this->answerResponse($this->briefJson()), 200);
            },
            'news.example/*' => Http::response($this->rss('Không dùng tới', 'https://bao.example/0'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertStringEndsWith('/chat/completions', (string) $sent[0]['url']);
        $this->assertTrue(data_get($sent[0]['body'], 'enable_search'));

        $this->assertSame('native', $brief['model']['tool_search']['mode']);
        $this->assertFalse($brief['model']['tool_search']['verified'],
            'Cờ enable_search đã đo là bị bỏ qua — không được khai là đã kiểm chứng.');
        $this->assertFalse($brief['model']['tool_search']['claim'],
            'Giao thức đã đo là bị bỏ qua thì không được phép nói "lượt này đã tìm".');
        $this->assertFalse($brief['model']['web_search'],
            'Không có bằng chứng nào thì không được nói lượt này có tìm kiếm.');

        // Và màn hình Cài đặt cũng phải nói đúng điều đó.
        $row = collect(app(WebAccessService::class)->providerSearchMap())->firstWhere('model', 'qwen-plus');
        $this->assertNotNull($row);
        $this->assertStringContainsString('CHƯA kiểm chứng', (string) $row['label']);
    }

    /** Tên model viết kiểu nào (có/không gạch nối, hoa thường) cũng phải vào cùng một luật. */
    public function test_the_qwen_model_spelling_does_not_change_the_route(): void
    {
        foreach (['qwen3.8-flash', 'qwen-3.8-flash', 'Qwen3.8-Max', 'qwen3.8-omni-flash', 'qwen3-max', 'qwen3.7-plus', 'qwen3.6-flash'] as $model) {
            $this->assertTrue(WebAccessService::hasWebSearchTool('qwen', $model), $model.' phải đi đường công cụ web_search.');
        }

        // Ngoài danh sách tài liệu ⇒ KHÔNG hứa. (Tài liệu ghi rõ Qwen3.5/3.7 "but NOT Omni models".)
        foreach (['qwen-plus', 'qwen-turbo', 'qwen2.5-72b-instruct', 'qwen3-235b-a22b', 'qwen3.5-omni', 'qwen3.7-omni-plus'] as $model) {
            $this->assertFalse(WebAccessService::hasWebSearchTool('qwen', $model), $model.' không nằm trong họ đã đo — không được hứa.');
        }

        // Chỉ giao thức DashScope: host của nhà cung cấp khác không chắc có endpoint /responses.
        $this->assertFalse(WebAccessService::hasWebSearchTool('openai', 'qwen3.8-flash'));
    }

    /** Câu chữ trên màn hình phải theo BẰNG CHỨNG: đường chưa kiểm chứng thì không được nói "đã tìm". */
    public function test_the_copy_follows_the_evidence_not_the_protocol(): void
    {
        $core = (string) file_get_contents(base_path('resources/js/studio/composables/useAgentStudio.js'));
        $service = (string) file_get_contents(base_path('app/Services/DesignAgentService.php'));

        $this->assertStringContainsString("if (t.claim === false)", $core);
        $this->assertStringContainsString('KHÔNG xác nhận được đã tra hay chưa', $core);
        $this->assertStringContainsString("return (\$search['native']['claim'] ?? true) === true;", $service,
            'Đường "native" chỉ được tính là đã tìm khi CÓ CƠ SỞ, không phải vì giao thức có tham số.');
        // Và không được dặn model "bạn CÓ công cụ tìm kiếm" trên đường không được phép nói đã tìm (mời bịa nguồn).
        $this->assertStringContainsString("(\$search['native']['claim'] ?? true) === true)", $service);
    }


    // ── (E) CÔNG CỤ TÌM KIẾM CỦA NHÀ CUNG CẤP QUA /responses (2026-09-21) ───

    /** Khai một Custom Provider dùng endpoint /responses kèm công cụ web_search của nhà cung cấp. */
    private function hostedProvider(string $slug, string $model, array $extra = []): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, $slug, $model, array_merge([
            'search_mode' => 'responses_web_search',
            'search_param' => 'web_search',
        ], $extra));
    }

    /**
     * THÂN JSON CỦA LƯỢT DÒ (probe) — model khai từ khoá và NHỮNG GÌ NÓ THẬT SỰ TÌM ĐƯỢC.
     *
     * Từ 2026-09-22 lượt hosted được TÁCH làm hai: /responses là LƯỢT DÒ (nhỏ, có công cụ) trả về
     * queries + findings; /chat/completions là LƯỢT VIẾT JSON (không công cụ). Fake cũ trả CÙNG một thân
     * cho mọi URL nên lượt viết nhận thân dạng /responses (không có choices) ⇒ coi như model câm.
     *
     * @param  list<array{title?:string,url:string,snippet?:string}>  $findings
     * @param  list<string>  $queries
     */
    private function findingsJson(array $findings = [], array $queries = []): string
    {
        return (string) json_encode([
            'queries' => $queries !== [] ? $queries : ['xu hướng thu đông 2026'],
            'findings' => $findings,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * FAKE CHO MỘT LƯỢT HOSTED ĐÃ TÁCH: /responses trả lượt DÒ, mọi URL khác trả lượt VIẾT.
     *
     * @param  callable  $onProbe  phản hồi cho /responses
     * @param  callable  $onWrite  phản hồi cho /chat/completions
     * @param  list<array<string,mixed>>  $sent  (tham chiếu) nhật ký lời gọi
     */
    private function hostedSplitFake(callable $onProbe, callable $onWrite, array &$sent): callable
    {
        return function ($request) use ($onProbe, $onWrite, &$sent) {
            $sent[] = ['url' => $request->url(), 'body' => json_decode((string) $request->body(), true)];

            return str_contains($request->url(), '/responses') ? $onProbe() : $onWrite();
        };
    }

    /** Phản hồi /responses: số mục web_search_call + một message chứa JSON. */
    private function responsesBody(string $text, int $searches = 1, array $queries = []): array
    {
        $output = [['type' => 'reasoning', 'id' => 'rs_1']];
        for ($i = 0; $i < $searches; $i++) {
            $output[] = [
                'type' => 'web_search_call', 'id' => 'call_'.$i, 'status' => 'completed',
                'action' => [
                    'type' => 'search',
                    'queries' => $queries !== [] ? $queries : ['xu hướng thu đông 2026 '.$i, 'ws_call_id=call_'.$i],
                    'sources' => [['url' => 'https://bao.example/tin-'.$i]],
                ],
            ];
        }
        $output[] = ['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text]]];

        return ['id' => 'resp_1', 'object' => 'response', 'status' => 'completed', 'output' => $output];
    }

    /**
     * Đường /responses: gọi ĐÚNG endpoint, gửi ĐÚNG công cụ, và đếm số lượt tìm THẬT từ phản hồi.
     * Đây là đường "dùng công cụ tìm kiếm của chính model" — /chat/completions từ chối nó (HTTP 422).
     */
    public function test_the_hosted_responses_mode_really_searches(): void
    {
        $this->hostedProvider('gw-hosted', 'v4-pro');
        $this->searchSource();

        $sent = [];
        Http::fake([
            'gw-hosted.example/*' => $this->hostedSplitFake(
                fn () => Http::response($this->responsesBody($this->findingsJson(), 2), 200),
                fn () => Http::response($this->answerResponse($this->briefJson()), 200),
                $sent,
            ),
            'news.example/*' => Http::response($this->rss('Không dùng tới', 'https://bao.example/0'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        // (1) Lượt DÒ đi ĐÚNG endpoint /responses (lượt viết JSON sau đó đi /chat/completions, không công cụ).
        $this->assertStringEndsWith('/responses', (string) $sent[0]['url']);
        // (2) Khai ĐÚNG công cụ của nhà cung cấp + tách phần chỉ dẫn khỏi phần đầu vào.
        $this->assertSame('web_search', data_get($sent[0]['body'], 'tools.0.type'));
        $this->assertNotEmpty(data_get($sent[0]['body'], 'instructions'), 'Phần CHỈ DẪN phải đi qua instructions.');
        $this->assertNotEmpty(data_get($sent[0]['body'], 'input'));
        $this->assertSame('json_object', data_get($sent[0]['body'], 'text.format.type'), 'Radar/brief cần JSON nên phải khai format.');
        // TỐI ƯU THỜI GIAN: giảm suy luận dài và CHẶN trần số lượt tìm — đo thật một lượt radar kéo tới
        // 6 truy vấn / 49 giây khi không có trần.
        $this->assertSame('low', data_get($sent[0]['body'], 'reasoning.effort'));
        // Đây là đường BRIEF ⇒ trần 2 (radar để 3, xem test_the_radar_allows_more_search_rounds_than_the_brief).
        $this->assertSame(2, data_get($sent[0]['body'], 'max_tool_calls'));

        // (3) Số ĐO lấy từ phản hồi: 2 lượt tìm thật, có từ khoá và nguồn.
        $this->assertSame('ai-v1', $brief['engine']);
        $this->assertTrue($brief['model']['web_search']);
        $this->assertSame('hosted', $brief['model']['tool_search']['mode']);
        $this->assertSame(2, $brief['model']['tool_search']['calls']);
        $this->assertContains('xu hướng thu đông 2026 0', $brief['model']['tool_search']['queries']);
        $this->assertContains('https://bao.example/tin-0', $brief['model']['tool_search']['sources']);
        // Hậu tố nội bộ của gateway KHÔNG được lọt ra như một từ khoá.
        foreach ($brief['model']['tool_search']['queries'] as $q) {
            $this->assertStringNotContainsString('ws_call_id', (string) $q);
        }
    }

/**
     * HẠN CHÓT LÀ CỦA CẢ LƯỢT CHẠY, KHÔNG PHẢI TỪNG LẦN GỌI.
     *
     * [LỖI THẬT — production 2026-09-22 00:16, mã tra cứu L-G8YM] Vai tìm kiếm chạy `qwen3.8-omni-flash`:
     * lần gọi /responses quá 55 s (0 byte nhận được) ⇒ rơi về /chat/completions, lần này lại bắt đầu một
     * lời gọi MỚI dài bằng lần trước ⇒ vượt trần proxy ⇒ khách nhận **HTTP 504** và mất cả phần đã tính.
     */
    public function test_the_fallback_refuses_to_start_a_new_call_when_time_is_out(): void
    {
        $gw = app(\App\Services\AiModelGateway::class);
        $m = new \ReflectionMethod($gw, 'remainingSeconds');
        $m->setAccessible(true);

        // Không có hạn chót ⇒ giữ nguyên trần cũ (nơi gọi cũ không truyền).
        $this->assertSame(90, $m->invoke($gw, [], 90));

        // Có hạn chót ⇒ bị cắt theo phần còn lại, không bao giờ vượt hạn.
        $this->assertSame(5, $m->invoke($gw, ['deadline_ts' => microtime(true) + 5.5], 90));
        $this->assertSame(0, $m->invoke($gw, ['deadline_ts' => microtime(true) - 1], 90));
        $this->assertLessThanOrEqual(10, $m->invoke($gw, ['deadline_ts' => microtime(true) + 10], 90));

        // Bất biến trong mã: đường rơi về phải từ chối khi đã cạn thời gian, và lượt chạy phải đặt hạn chót.
        $src = (string) file_get_contents(app_path('Services/AiModelGateway.php'));
        $this->assertStringContainsString('KHÔNG rơi về /chat/completions (tránh 504)', $src);
        $svc = (string) file_get_contents(app_path('Services/DesignAgentService.php'));
        // Hạn chót tính MỘT lần, TRƯỚC cả lượt dò, rồi dùng chung cho mọi lần gọi con. [ĐO THẬT 2026-09-22]
        // Bản trước tính SAU lượt dò nên tổng = 30 s (dò) + 55 s (gọi cũ) = 85 s — khách vẫn nhận 504.
        $this->assertStringContainsString('$deadline = microtime(true) + self::AI_CALL_CEILING_MS / 1000;', $svc,
            'Thiếu hạn chót thì "trần" chỉ là trang trí: các lần gọi cộng lại vẫn vượt trần proxy.');
        $this->assertStringContainsString('=> $deadline,', $svc, 'Mọi lần gọi con phải dùng CHUNG hạn chót đó.');
        $this->assertStringContainsString('min(40, $remaining)', $svc,
            'Lượt dò phải bị cắt theo phần thời gian CÒN LẠI của cả lượt, không có trần riêng rời rạc.');
        $this->assertStringContainsString('bỏ lượt DÒ TÌM KIẾM vì không còn đủ thời gian', $svc,
            'Không còn đủ chỗ cho lượt dò thì phải bỏ nó, không được bắt đầu rồi để cả lượt vượt trần.');
    }

    /**
     * 🔴 CÁI BẪY: model NHẬN tham số công cụ rồi trả lời trơn tru mà KHÔNG tìm gì.
     *
     * Đo thật trên production: cùng `tools:[{type:web_search}]`, model nhỏ trả HTTP 200 và tự BỊA cả tin lẫn
     * URL. Vì vậy "đã bật tìm kiếm" KHÔNG được suy ra từ việc ta đã gửi tham số — phải đếm web_search_call.
     */
    public function test_a_model_that_never_searches_is_not_reported_as_searching(): void
    {
        $this->hostedProvider('gw-hosted', 'model-nho');
        $this->searchSource();
        $neverSearched = [];

        Http::fake([
            'gw-hosted.example/*' => $this->hostedSplitFake(
                // Lượt DÒ: model nhận công cụ nhưng KHÔNG gọi lần nào (0 mục web_search_call).
                fn () => Http::response($this->responsesBody($this->findingsJson(), 0), 200),
                fn () => Http::response($this->answerResponse($this->briefJson()), 200),
                $neverSearched,
            ),
            'news.example/*' => Http::response($this->rss('Không dùng tới', 'https://bao.example/0'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame('ai-v1', $brief['engine'], 'Model vẫn trả lời được — chỉ là không có tìm kiếm.');
        $this->assertFalse($brief['model']['web_search'], 'Không có web_search_call nào thì KHÔNG được nói là đã tìm.');
        $this->assertSame('hosted', $brief['model']['tool_search']['mode']);
        $this->assertSame(0, $brief['model']['tool_search']['calls']);
    }

    /** /responses không dùng được (endpoint thiếu/không cho công cụ) ⇒ quay về đường thường, KHÔNG vỡ lượt. */
    public function test_the_hosted_mode_falls_back_when_the_endpoint_is_unavailable(): void
    {
        $this->hostedProvider('gw-hosted', 'v4-pro');
        $this->searchSource();

        $urls = [];
        Http::fake([
            'gw-hosted.example/*' => function ($request) use (&$urls) {
                $urls[] = $request->url();
                if (str_contains($request->url(), '/responses')) {
                    return Http::response(['error' => ['message' => 'unknown url']], 404);
                }

                return Http::response($this->answerResponse($this->briefJson()), 200);
            },
            'news.example/*' => Http::response($this->rss('Không dùng tới', 'https://bao.example/0'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame('ai-v1', $brief['engine'], 'Một endpoint tuỳ chọn không được làm hỏng cả lượt chạy.');
        $this->assertFalse($brief['model']['web_search'], 'Rơi về đường thường thì lượt này KHÔNG có tìm kiếm.');
        $this->assertSame(0, $brief['model']['tool_search']['calls']);
        $this->assertTrue(collect($urls)->contains(fn ($u) => str_contains((string) $u, '/chat/completions')), 'Phải có lời gọi dự phòng /chat/completions.');
    }

    /**
     * CÂU HỎI CỦA MODEL → CHẠY TRÊN CÔNG CỤ CỦA MÁY CHỦ → THÀNH DỮ LIỆU (2026-09-21).
     *
     * Vì sao cần: API /responses chỉ trả về CÂU HỎI model đã hỏi, KHÔNG trả kết quả — dừng ở đó thì việc AI
     * tự tra không để lại gì cho máy chủ: hướng vẫn gắn "bộ có sẵn", không có link cho người dùng kiểm, và
     * tầng đo không có gì để đếm. Test này khoá cả ba chặng: máy chủ CHẠY LẠI đúng câu hỏi đó → tin lấy được
     * VÀO payload → hướng khớp từ khoá được gắn "có tin thật" kèm nguồn gốc "ai".
     */
    public function test_the_models_queries_are_re_run_on_the_server_and_become_evidence(): void
    {
        $this->hostedProvider('gw-hosted', 'v4-pro');
        $this->searchSource();

        $log = [];
        Http::fake([
            // LƯỢT DÒ (/responses): model tra thật, khai từ khoá ĐÃ DÙNG (web_search_call).
            'gw-hosted.example/*' => $this->hostedSplitFake(
                fn () => Http::response($this->responsesBody($this->directionsJson(), 1, ['xu hướng pastel 2026']), 200),
                // LƯỢT VIẾT JSON (/chat/completions): KHÔNG công cụ, chỉ viết dựa trên dữ liệu đã có.
                fn () => Http::response($this->answerResponse($this->directionsJson()), 200),
                $log,
            ),
            'news.example/*' => function ($request) {
                // Tin của FEED (máy chủ lấy sẵn) KHÔNG nhắc pastel; chỉ tin do CÂU HỎI CỦA MODEL mang về mới có.
                return str_contains(urldecode($request->url()), 'pastel')
                    ? Http::response($this->rss('Màu pastel lên ngôi mùa thu 2026', 'https://bao.example/pastel'), 200)
                    : Http::response($this->rss('Tin chung về ngành may mặc', 'https://bao.example/chung'), 200);
            },
        ]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', true);

        // (1) Máy chủ ĐÃ chạy lại câu hỏi của model trên nguồn tìm kiếm thật.
        $this->assertContains('xu hướng pastel 2026', $radar['model']['tool_search']['server_queries'] ?? []);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'news.example')
            && str_contains(urldecode($request->url()), 'pastel'));

        // (2) Tin lấy được VÀO payload — giao diện có link thật để người dùng tự kiểm.
        $items = $radar['model']['tool_search']['items'] ?? [];
        $this->assertNotEmpty($items, 'Tin do câu hỏi của model mang về phải có trong payload.');
        $this->assertContains('https://bao.example/pastel', array_column($items, 'url'));

        // (3) Hướng khớp từ khoá trong tin đó được gắn "có tin thật", và KHAI RÕ nguồn gốc là AI tự tra.
        $pastel = collect($radar['trends'])->firstWhere('id', 'soft-pastel');
        $this->assertNotNull($pastel);
        $this->assertSame('live', $pastel['evidence_mode'], 'Hướng khớp tin AI tìm được phải mang bằng chứng thật.');
        $this->assertSame('ai', $pastel['live']['origin'] ?? null, 'Phải phân biệt được tin do AI tra với tin của feed định kỳ.');
        $this->assertStringContainsString('AI tự tra', (string) $pastel['regional_note']);
    }

    /**
     * BẢN ĐỆM PHẢI GIỮ CẢ NHÃN "AI TÌM THẤY".
     *
     * [LỖI THẬT — đo trên production 2026-09-21] Bản đầu chỉ đệm directions, không đệm danh mục hướng: lượt
     * ĐẦU hiện "3 hướng AI tìm thấy · 2 bộ có sẵn", mở lại màn hình (đọc đệm) lại hiện "4 bộ có sẵn" — cùng
     * một lượt chạy mà hai màn hình khác nhau. Test này chạy HAI lần liên tiếp và bắt buộc kết quả giống nhau.
     */
    public function test_the_cache_keeps_the_ai_evidence_labels(): void
    {
        $this->hostedProvider('gw-hosted', 'v4-pro');
        $this->searchSource();

        $log = [];
        Http::fake([
            'gw-hosted.example/*' => $this->hostedSplitFake(
                fn () => Http::response($this->responsesBody($this->directionsJson(), 1, ['xu hướng pastel 2026']), 200),
                fn () => Http::response($this->answerResponse($this->directionsJson()), 200),
                $log,
            ),
            'news.example/*' => function ($request) {
                return str_contains(urldecode($request->url()), 'pastel')
                    ? Http::response($this->rss('Màu pastel lên ngôi mùa thu 2026', 'https://bao.example/pastel'), 200)
                    : Http::response($this->rss('Tin chung về ngành may mặc', 'https://bao.example/chung'), 200);
            },
        ]);

        $first = app(DesignAgentService::class)->radar($this->customer(), 'all', true);
        $second = app(DesignAgentService::class)->radar($this->customer(), 'all', true);

        $label = function (array $radar): string {
            $row = collect($radar['trends'])->firstWhere('id', 'soft-pastel');

            return (string) ($row['live']['origin'] ?? 'khong-co');
        };

        $this->assertSame('ai', $label($first));
        $this->assertTrue($second['model']['cached'], 'Lần hai phải đọc từ đệm (đúng thứ cần kiểm).');
        $this->assertSame('ai', $label($second), 'Đọc từ đệm KHÔNG được làm mất nhãn bằng chứng do AI tìm.');
    }

    // ── (F) LÀN WEB CHUNG BẰNG API TÌM KIẾM (2026-09-21) ───────────────────

    /**
     * Nguồn kiểu `search` PHẢI gắn khoá API vào lời gọi HTTP, và khoá KHÔNG được nằm trong URL nguồn.
     *
     * Đo thật: Google News RSS (kiểu rss) chỉ có tin tức — câu hỏi tra cứu thường ("cách giặt vải linen")
     * và câu hỏi thương mại đều trả 0 kết quả. Muốn tra web chung thì phải gọi API tìm kiếm thật, và API
     * đó cần khoá. Khoá để trong `web_sources.url` là phơi khoá ở màn Cài đặt + payload + log.
     */
    public function test_a_search_source_gets_the_api_key_at_call_time_only(): void
    {
        StudioApiKey::create([
            'provider' => 'google-web', 'label' => 'Google CSE', 'value' => 'key-cse-123',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        WebSource::create([
            'slug' => 'google-web', 'name' => 'Google — tìm kiếm web chung', 'kind' => 'search', 'enabled' => true,
            'url' => 'https://www.googleapis.com/customsearch/v1?cx=CX123&num=10&q={query}',
            'priority' => 1, 'max_items' => 10,
            'items_path' => 'items', 'title_field' => 'title', 'link_field' => 'link', 'summary_field' => 'snippet',
        ]);

        Http::fake(['www.googleapis.com/*' => Http::response(json_encode(['items' => [
            ['title' => 'Cách giặt vải linen', 'link' => 'https://gusa.vn/a', 'snippet' => 'Hướng dẫn giặt linen không nhão'],
        ]]), 200)]);

        $found = app(WebSourceService::class)->search('cách giặt vải linen', 'all');

        $this->assertSame(1, $found['count'], 'Nguồn tìm kiếm phải trả được kết quả web chung.');
        $this->assertSame('https://gusa.vn/a', $found['items'][0]['url']);
        $this->assertSame('Hướng dẫn giặt linen không nhão', $found['items'][0]['summary']);

        // KHOÁ có trong lời gọi HTTP…
        Http::assertSent(fn ($request) => str_contains($request->url(), 'key=key-cse-123')
            && str_contains(urldecode($request->url()), 'q=cách giặt vải linen'));
        // …nhưng KHÔNG có trong URL lưu ở Cài đặt (chỗ hiện nguyên văn cho mọi người đọc).
        $row = WebSource::query()->where('slug', 'google-web')->first();
        $this->assertStringNotContainsString('key-cse-123', (string) $row->url);
    }

    /** Nguồn tìm kiếm KHÔNG được đọc ở đường lấy tin cố định — nó là làn TÌM KIẾM, không phải feed. */
    public function test_a_search_source_is_never_read_as_a_fixed_feed(): void
    {
        WebSource::create([
            'slug' => 'google-web', 'name' => 'Google — web chung', 'kind' => 'search', 'enabled' => true,
            // Cố tình KHÔNG có {query}: dù admin quên, nó vẫn không được coi là feed.
            'url' => 'https://www.googleapis.com/customsearch/v1?cx=CX123&q=thoi+trang',
            'priority' => 1, 'max_items' => 10,
        ]);
        Http::fake();

        $evidence = app(WebSourceService::class)->evidence('all');
        $row = collect($evidence['sources'])->firstWhere('slug', 'google-web');

        $this->assertSame('skipped', $row['state']);
        $this->assertStringContainsString('tìm kiếm', (string) $row['state_label']);
        Http::assertNothingSent();
    }

    /**
     * LỖI PHẢI NÓI ĐÚNG BỆNH — ba nguyên nhân, ba câu khác nhau.
     *
     * Đo thật 2026-09-21: nguồn Google khai URL `cse.google.com/cse?cx=…` (TRANG HTML của dịch vụ) và màn
     * Cài đặt báo "nội dung không phải RSS/Atom đọc được" — câu đó chỉ đúng với nguồn RSS, không cho người
     * khai biết phải sửa gì. Ba ca dưới đây khoá lại từng câu.
     */
    public function test_a_search_source_reports_the_real_reason_when_it_finds_nothing(): void
    {
        $source = WebSource::create([
            'slug' => 'gcse-search', 'name' => 'Google', 'kind' => 'search', 'enabled' => true,
            'url' => 'https://www.googleapis.com/customsearch/v1?cx=CX1&q={query}',
            'priority' => 1, 'max_items' => 10,
        ]);

        // MỘT stub duy nhất, đổi hành vi bằng biến — KHÔNG gọi `Http::fake()` trần ở giữa bài.
        //
        // Vì sao: `Http::fake()` không có tham số đăng ký một stub khớp MỌI URL, và Laravel MERGE các stub
        // theo thứ tự (cái nào khớp trước thì thắng) chứ không xoá cái cũ. Gọi trần rồi gọi lại có tham số
        // là stub rỗng thắng — bài test tưởng đang thử HTML nhưng thực ra nhận thân rỗng.
        $mode = 'chua-co-khoa';
        Http::fake([
            'www.googleapis.com/*' => function () use (&$mode) {
                return match ($mode) {
                    'html' => Http::response('<!doctype html><html><body>Programmable Search</body></html>', 200),
                    'api-error' => Http::response((string) json_encode([
                        'error' => ['code' => 403, 'message' => 'The request is missing a valid API key.'],
                    ]), 200),
                    default => Http::response('', 500),
                };
            },
        ]);

        // (a) CHƯA CÓ KHOÁ ⇒ nói đúng việc cần làm, và KHÔNG gọi ra mạng.
        $noKey = app(WebSourceService::class)->fetchWithQuery($source, 'linen');
        $this->assertStringContainsString('chưa có khoá API', (string) $noKey['error']);
        $this->assertStringContainsString('gcse-search', (string) $noKey['error'], 'Phải nói tên provider cần khai.');
        Http::assertNothingSent();

        StudioApiKey::create([
            'provider' => 'gcse-search', 'label' => 'Google CSE', 'value' => 'key-1',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);

        // (b) URL trỏ vào TRANG HTML (không phải API) ⇒ nói thẳng là trỏ nhầm endpoint.
        $mode = 'html';
        $html = app(WebSourceService::class)->fetchWithQuery($source, 'linen');
        $this->assertStringContainsString('TRANG HTML', (string) $html['error']);
        $this->assertStringContainsString('customsearch/v1', (string) $html['error'], 'Phải chỉ luôn endpoint đúng.');

        // (c) API trả lỗi ⇒ hiện ĐÚNG câu lỗi của API (thiếu/sai khoá, hết hạn mức, engine chưa bật web).
        $mode = 'api-error';
        $apiError = app(WebSourceService::class)->fetchWithQuery($source, 'linen');
        $this->assertStringContainsString('API trả lỗi', (string) $apiError['error']);
        $this->assertStringContainsString('missing a valid API key', (string) $apiError['error']);
    }

    /** Ngưỡng thử lại: lần đầu đã chậm thì KHÔNG thử lại — thà có kết quả tất định còn hơn 504. */
    public function test_the_retry_is_skipped_when_the_first_call_was_already_slow(): void
    {
        $service = app(DesignAgentService::class);
        $method = new \ReflectionMethod($service, 'retryWorthIt');
        $method->setAccessible(true);

        // Ngưỡng 30 s: lần thử lại ĐÃ BỎ TÌM KIẾM nên chỉ tốn thêm ~8–12 s — tổng vẫn dưới trần proxy.
        $this->assertTrue($method->invoke($service, 1000), 'Lần đầu nhanh thì nên thử lại.');
        $this->assertTrue($method->invoke($service, 29000));
        $this->assertFalse($method->invoke($service, 30000), 'Chạm trần thì dừng.');
        $this->assertFalse($method->invoke($service, 49000), 'Radar 49 giây rồi mà thử lại là vượt trần proxy.');
    }

/**
     * TRẦN TỔNG của một lượt gọi AI — chỗ thật sự chặn được HTTP 504.
     *
     * ĐO THẬT: `timeout` từng là 90 s cho lần đầu và **180 s** cho lần thử lại. Đó là trần của TỪNG lần gọi,
     * không phải của cả lượt: 30 s + 180 s vượt xa trần proxy ⇒ khách nhận 504 và mất TẤT CẢ, kể cả phần đã
     * tính được. Log production có 2 ca thật (07:08 brief · 23:34 radar).
     */
    public function test_the_total_call_time_stays_inside_the_ceiling(): void
    {
        $service = app(DesignAgentService::class);

        $call = new \ReflectionMethod($service, 'callTimeout');
        $call->setAccessible(true);
        $retry = new \ReflectionMethod($service, 'retryTimeout');
        $retry->setAccessible(true);

        // Lần đầu: 90 s yêu cầu ⇒ bị siết còn 55 s (chừa 5 s trả phản hồi cho khách).
        $this->assertSame(55, $call->invoke($service, 90));
        // Yêu cầu ngắn hơn trần thì giữ nguyên (đường đọc ảnh chỉ cần 45–60 s).
        $this->assertSame(45, $call->invoke($service, 45));

        // Lần thử lại: chỉ được dùng phần CÒN LẠI của lượt, không phải "gấp đôi timeout".
        $this->assertSame(45, $retry->invoke($service, 10000), 'Lần đầu 10 s ⇒ còn 50 s, chừa 5 s ⇒ 45 s.');
        $this->assertSame(16, $retry->invoke($service, 39000));
        $this->assertSame(0, $retry->invoke($service, 59000),
            'Hết thời gian thì trả 0 = "đừng thử lại" — một cái sàn cứng ở đây sẽ phá chính bất biến nó sinh ra để giữ.');

        // BẤT BIẾN: ở MỌI mốc mà lần thử lại còn được phép, tổng thời gian vẫn nằm trong trần.
        $worth = new \ReflectionMethod($service, 'retryWorthIt');
        $worth->setAccessible(true);
        for ($elapsed = 0; $elapsed <= 60000; $elapsed += 1000) {
            if (! $worth->invoke($service, $elapsed)) {
                continue;
            }
            $total = $elapsed + $retry->invoke($service, $elapsed) * 1000;
            $this->assertLessThanOrEqual(60000, $total,
                'Lượt chạy ở mốc '.$elapsed.' ms cộng lần thử lại vượt trần proxy — đây là đường dẫn tới 504.');
        }
    }

    /**
     * HƯỚNG ĐÃ CHỌN MÀ KHÔNG CÒN TỒN TẠI ⇒ KHÔNG ĐƯỢC CHẶN CẢ REQUEST.
     *
     * [LỖI THẬT — production 2026-09-21, mã tra cứu L-WJH6] Khách chọn một hướng đang hiện trên màn hình
     * rồi bấm tạo bộ sưu tập và nhận HTTP 422 "Có xu hướng không tồn tại trong TrendRadar." — vì danh mục
     * hướng KHÔNG cố định: hướng sinh từ tin thật (id live-…) phụ thuộc tin lấy được, và từ 2026-09-21 còn
     * phụ thuộc câu hỏi model tự tra. Giữa lúc mở radar và lúc bấm tạo brief, danh mục có thể đã đổi.
     *
     * Nay id lạ bị BỎ RA và ĐẾM LẠI; hình thức id vẫn phải hợp lệ (chống dữ liệu rác).
     */
    public function test_a_selected_trend_that_vanished_does_not_block_the_brief(): void
    {
        Http::fake();

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/collection', [
            'prompt' => 'đầm linen công sở',
            'trend_ids' => ['live-khong-con-ton-tai-abc', 'cung-khong-co-luon'],
        ])->assertOk();   // KHÔNG được 422

        $dropped = (array) $response->json('dropped_trend_ids');
        $this->assertContains('live-khong-con-ton-tai-abc', $dropped);
        $this->assertContains('cung-khong-co-luon', $dropped);
        $this->assertNotEmpty((string) $response->json('dropped_note'), 'Phải nói RA là đã bỏ hướng nào.');
    }

    /**
     * "BỘ CÒN THIẾU" ĐƯỢC GIAO CHO AI TRA, VÀ AI ĐÃ TRA MÀ KHÔNG RA TIN THÌ PHẢI NÓI RA.
     *
     * Vai trò của "bộ có sẵn": 8 hướng mẫu của FabrikAI (Pastel dịu · Tailoring tối giản · Linen thoáng ·
     * Quần ống rộng · Vàng bơ · Váy midi công sở · Quiet shine · Neutral đất) — chúng là XƯƠNG SỐNG của
     * danh mục và là bộ từ vựng để định hướng bám vào, nhưng số liệu của chúng là SỐ MẪU. Hướng nào tin
     * thật không nhắc tới thì phải mượn số mẫu — "bộ còn thiếu" chính là những hướng đó.
     *
     * Test này khoá ba việc: (1) danh sách hướng thiếu bằng chứng được GỬI cho model; (2) hướng AI đã tra
     * mà không ra tin được đánh dấu `checked_by_ai` (không gộp vào "bộ có sẵn"); (3) hướng AI tra RA tin
     * thì thành "có tin thật" như bình thường.
     */
    public function test_the_missing_trends_are_handed_to_the_model_to_search(): void
    {
        $this->hostedProvider('gw-hosted', 'v4-pro');
        $this->searchSource();

        $sent = [];
        Http::fake([
            // LƯỢT DÒ: model tra ĐÚNG một hướng còn thiếu ("Pastel dịu"). LƯỢT VIẾT: viết JSON.
            'gw-hosted.example/*' => $this->hostedSplitFake(
                fn () => Http::response($this->responsesBody($this->findingsJson(), 1, ['pastel dịu xu hướng 2026']), 200),
                fn () => Http::response($this->answerResponse($this->directionsJson()), 200),
                $sent,
            ),
            'news.example/*' => function ($request) {
                // Tin của FEED không nhắc pastel; chỉ tin do CÂU HỎI CỦA MODEL mang về mới có.
                return str_contains(urldecode($request->url()), 'pastel')
                    ? Http::response($this->rss('Màu pastel lên ngôi mùa thu 2026', 'https://bao.example/pastel'), 200)
                    : Http::response($this->rss('Tin chung về ngành may mặc', 'https://bao.example/chung'), 200);
            },
        ]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', true);

        // (1) Danh sách "bộ còn thiếu" có mặt trong DỮ LIỆU gửi model — không để nó tự đoán chủ đề.
        //
        // TỪ 2026-09-22 lượt hosted TÁCH LÀM HAI, nên danh sách này đi ở LƯỢT VIẾT JSON
        // (/chat/completions, tham số `messages`) chứ không phải lượt DÒ (/responses, `input`). Lượt dò
        // chỉ nhận tên các hướng cần tra; toàn bộ payload nằm ở lượt viết.
        // Phần nội dung là CHUỖI có tiền tố ("DỮ LIỆU:" rồi mới tới JSON) — phải cắt từ dấu ngoặc nhọn đầu
        // tiên rồi mới giải mã (bài học từ readableBody ở AgentRolesTest).
        $write = collect($sent)->first(fn ($row) => str_contains((string) ($row['url'] ?? ''), '/chat/completions'));
        $this->assertNotNull($write, 'Phải có LƯỢT VIẾT JSON ở /chat/completions.');
        // Đọc ĐÚNG message của vai "user": phần CHỈ DẪN (system) cũng chứa vài object JSON mẫu, nên cắt
        // từ dấu ngoặc nhọn ĐẦU TIÊN của cả mảng messages là bắt nhầm object trong chỉ dẫn.
        $rawInput = '';
        foreach ((array) data_get($write, 'body.messages', []) as $message) {
            if (($message['role'] ?? '') === 'user') {
                $rawInput = (string) ($message['content'] ?? '');
            }
        }
        $start = strpos($rawInput, '{');
        $payload = $start === false ? [] : (array) json_decode(substr($rawInput, $start), true);
        $missing = (array) data_get($payload, 'trends_without_evidence', []);
        $this->assertNotEmpty($missing, 'Phải gửi danh sách hướng CHƯA có bằng chứng cho model.');
        $this->assertContains('soft-pastel', array_column($missing, 'id'));

        // (3) Hướng model tra RA tin ⇒ thành "có tin thật", nguồn gốc là AI.
        $pastel = collect($radar['trends'])->firstWhere('id', 'soft-pastel');
        $this->assertSame('live', $pastel['evidence_mode'], 'Hướng AI tra RA tin phải thành "có tin thật".');
        $this->assertSame('ai', $pastel['live']['origin'] ?? null);

        // (2) Hướng model KHÔNG tra tới thì KHÔNG được gắn nhãn "đã tra" — nhãn phải đúng sự thật, vì nó
        // là thứ nói với người dùng "hệ thống ĐÃ thử hướng này rồi".
        //
        // [2026-09-26] Từ khi có luật "ẩn hướng MẪU khi đã có hướng THẬT", hướng mẫu nằm ở khoá RIÊNG
        // `trends_demo` (không bị xoá). Bài này cố tình kiểm CẢ hướng mẫu nên phải tìm trong cả hai khoá.
        $allTrends = array_merge((array) $radar['trends'], (array) ($radar['trends_demo'] ?? []));
        $quiet = collect($allTrends)->firstWhere('id', 'quiet-shine');
        $this->assertNotNull($quiet);
        $this->assertArrayNotHasKey('checked_by_ai', (array) $quiet, 'Chưa tra thì không được nói là đã tra.');
    }

    /**
     * MODEL PHÁN TỪNG HƯỚNG CÒN THIẾU — VÀ MÁY CHỦ CHỈ NHẬN KHI NGUỒN CÓ THẬT.
     *
     * Vì sao cần lời phán của model: nó nối được ngữ nghĩa ("tông màu đất" ↔ "Neutral đất"), còn tầng đo
     * của máy chủ chỉ khớp theo TỪ VỰNG khai sẵn — đo thật: từ vựng không có chữ "đất" nên hướng
     * "Neutral đất" không bao giờ được xác nhận dù báo có viết về nó.
     *
     * Vì sao vẫn phải kiểm: model nhỏ đã từng bịa cả tin lẫn URL. Luật ở đây — "confirmed" chỉ được nhận
     * khi URL nằm trong kết quả máy chủ ĐÃ THẬT SỰ lấy về; dẫn nguồn không có thật thì KHÔNG tính là bằng
     * chứng, và phải NÓI RA lý do.
     */
    public function test_the_model_can_confirm_a_missing_trend_but_only_with_a_real_source(): void
    {
        $this->hostedProvider('gw-hosted', 'v4-pro');
        $this->searchSource();

        $log = [];
        Http::fake([
            'gw-hosted.example/*' => $this->hostedSplitFake(
                fn () => Http::response($this->responsesBody($this->findingsJson(), 1, ['tông màu đất 2026', 'quần ống rộng 2026']), 200),
                fn () => Http::response($this->answerResponse($this->directionsJson([
                'trend_checks' => [
                    // (a) dẫn ĐÚNG url có trong kết quả tra được ⇒ được tính
                    ['id' => 'earth-neutral', 'status' => 'confirmed', 'url' => 'https://bao.example/dat'],
                    // (b) dẫn URL BỊA ⇒ KHÔNG được tính, và phải ghi rõ vì sao
                    ['id' => 'quiet-shine', 'status' => 'confirmed', 'url' => 'https://bia-dat.example/khong-co-that'],
                    // (c) tra mà không thấy ⇒ "đã tra, chưa có tin"
                    ['id' => 'wide-leg', 'status' => 'not_found'],
                ],
                ])), 200),
                $log,
            ),
            'news.example/*' => function ($request) {
                // So trên chuỗi ĐÃ GIẢI MÃ: 'đất' mã hoá thành %C4%91... nên tìm 'dat' trên URL thô là
                // không bao giờ khớp — đúng cái bẫy vừa làm bài test này đỏ.
                return str_contains(urldecode($request->url()), 'đất')
                    ? Http::response($this->rss('Tông màu đất trở lại mùa thu 2026', 'https://bao.example/dat'), 200)
                    : Http::response($this->rss('Tin chung về ngành may mặc', 'https://bao.example/chung'), 200);
            },
        ]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', true);
        // [2026-09-26] Hướng MẪU nay nằm ở khoá riêng `trends_demo` khi lượt chạy đã có hướng THẬT (luật "ẩn
        // hướng mẫu"). Bài này kiểm cả hướng mẫu ⇒ tra trong CẢ HAI khoá; dữ liệu không bị mất, chỉ tách ra.
        $allTrends = array_merge((array) $radar['trends'], (array) ($radar['trends_demo'] ?? []));
        $trend = fn (string $id) => (array) collect($allTrends)->firstWhere('id', $id);

        // (a) XÁC NHẬN CÓ NGUỒN THẬT ⇒ thành "có tin thật", kèm link để bấm vào kiểm.
        $earth = $trend('earth-neutral');
        $this->assertSame('live', $earth['evidence_mode'], 'Hướng AI xác nhận CÓ nguồn thật phải thành có bằng chứng.');
        $this->assertSame('ai', $earth['live']['origin'] ?? null);
        $this->assertTrue($earth['live']['verified'] ?? false, 'Phải ghi rõ là đã đối chiếu URL.');
        $this->assertSame('https://bao.example/dat', $earth['live']['articles'][0]['url'] ?? null);

        // (b) NGUỒN BỊA ⇒ KHÔNG tính là bằng chứng, và nói rõ lý do.
        $quiet = $trend('quiet-shine');
        $this->assertSame('demo', $quiet['evidence_mode'], 'Nguồn bịa KHÔNG được biến thành bằng chứng.');
        $this->assertTrue($quiet['checked_by_ai'] ?? false);
        $this->assertStringContainsString('KHÔNG nằm trong kết quả tra được', (string) ($quiet['check_note'] ?? ''));

        // (c) TRA MÀ KHÔNG THẤY ⇒ "đã tra", vẫn là số mẫu.
        $wide = $trend('wide-leg');
        $this->assertSame('demo', $wide['evidence_mode']);
        $this->assertTrue($wide['checked_by_ai'] ?? false);
        $this->assertStringContainsString('không thấy nguồn nào', (string) ($wide['check_note'] ?? ''));
    }

    /** Hình thức id vẫn phải hợp lệ — bỏ qua id cũ KHÔNG có nghĩa là nhận mọi chuỗi. */
    public function test_a_malformed_trend_id_is_still_rejected(): void
    {
        Http::fake();

        $this->actingAs($this->customer())->postJson('/api/design-agent/collection', [
            'prompt' => 'đầm linen công sở',
            'trend_ids' => ['Hướng Xấu!!'],
        ])->assertStatus(422);
    }
}











