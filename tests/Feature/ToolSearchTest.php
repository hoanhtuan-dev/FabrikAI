<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Models\User;
use App\Models\WebSource;
use App\Services\DesignAgentService;
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

    private function directionsJson(): string
    {
        return (string) json_encode([
            'directions' => array_fill(0, 6, ['title' => 'Hướng', 'thesis' => 't', 'why_now' => 'w', 'action' => 'a', 'risk' => 'r', 'price_band' => 'mid']),
        ], JSON_UNESCAPED_UNICODE);
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

        $calls = 0;
        Http::fake([
            'gw-search.example/*' => function () use (&$calls) {
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
        // Nguồn tin VẪN được đọc (đó là đường đọc tin cố định, không phải công cụ) — nhưng phải đọc bằng
        // ĐÚNG từ khoá cấu hình của nguồn, không phải bằng từ khoá do model nghĩ ra.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'news.example')
            && str_contains($request->url(), 'q=thoi+trang'));
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

    // ── (E) CÔNG CỤ TÌM KIẾM CỦA NHÀ CUNG CẤP QUA /responses (2026-09-21) ───

    /** Khai một Custom Provider dùng endpoint /responses kèm công cụ web_search của nhà cung cấp. */
    private function hostedProvider(string $slug, string $model, array $extra = []): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, $slug, $model, array_merge([
            'search_mode' => 'responses_web_search',
            'search_param' => 'web_search',
        ], $extra));
    }

    /** Phản hồi /responses: `$searches` mục web_search_call + một message chứa JSON. */
    private function responsesBody(string $text, int $searches = 1): array
    {
        $output = [['type' => 'reasoning', 'id' => 'rs_1']];
        for ($i = 0; $i < $searches; $i++) {
            $output[] = [
                'type' => 'web_search_call', 'id' => 'call_'.$i, 'status' => 'completed',
                'action' => [
                    'type' => 'search',
                    'queries' => ['xu hướng thu đông 2026 '.$i, 'ws_call_id=call_'.$i],
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
            'gw-hosted.example/*' => function ($request) use (&$sent) {
                $sent[] = ['url' => $request->url(), 'body' => json_decode($request->body(), true)];

                return Http::response($this->responsesBody($this->briefJson(), 2), 200);
            },
            'news.example/*' => Http::response($this->rss('Không dùng tới', 'https://bao.example/0'), 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        // (1) Đi ĐÚNG endpoint /responses, không phải /chat/completions.
        $this->assertStringEndsWith('/responses', (string) $sent[0]['url']);
        // (2) Khai ĐÚNG công cụ của nhà cung cấp + tách phần chỉ dẫn khỏi phần đầu vào.
        $this->assertSame('web_search', data_get($sent[0]['body'], 'tools.0.type'));
        $this->assertNotEmpty(data_get($sent[0]['body'], 'instructions'), 'Phần CHỈ DẪN phải đi qua instructions.');
        $this->assertNotEmpty(data_get($sent[0]['body'], 'input'));
        $this->assertSame('json_object', data_get($sent[0]['body'], 'text.format.type'), 'Radar/brief cần JSON nên phải khai format.');

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
     * 🔴 CÁI BẪY: model NHẬN tham số công cụ rồi trả lời trơn tru mà KHÔNG tìm gì.
     *
     * Đo thật trên production: cùng `tools:[{type:web_search}]`, model nhỏ trả HTTP 200 và tự BỊA cả tin lẫn
     * URL. Vì vậy "đã bật tìm kiếm" KHÔNG được suy ra từ việc ta đã gửi tham số — phải đếm web_search_call.
     */
    public function test_a_model_that_never_searches_is_not_reported_as_searching(): void
    {
        $this->hostedProvider('gw-hosted', 'model-nho');
        $this->searchSource();

        Http::fake([
            'gw-hosted.example/*' => Http::response($this->responsesBody($this->briefJson(), 0), 200),
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
}





