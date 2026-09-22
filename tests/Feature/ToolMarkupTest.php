<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Services\AiModelGateway;
use App\Services\DesignAgentService;
use App\Services\WebSearchTool;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MARKUP GỌI CÔNG CỤ CỦA DEEPSEEK — KHÔNG BAO GIỜ TỚI MÀN HÌNH NGƯỜI DÙNG (2026-09-26).
 *
 * [ĐO THẬT TRÊN PRODUCTION] Với \`deepseek-flash\`, khi công cụ được khai, provider trả **cả hai**:
 * trường \`tool_calls\` CHUẨN (máy chủ chạy được công cụ — phần này tốt), VÀ một khối **markup riêng của
 * DeepSeek** nằm trong \`content\` — tức là CHỮ HIỂN THỊ. Lượt CUỐI (không khai công cụ) thì chỉ còn markup:
 * đo được câu trả lời dài 332 ký tự và bắt đầu bằng thẻ, không có lời văn nào.
 *
 * Bộ test này khoá BA bất biến, thiếu cái nào cũng hỏng thật:
 *   (a) markup KHÔNG BAO GIỜ đi ra ngoài — không trong chữ trả về, không trong từng mảnh chữ chảy;
 *   (b) markup ĐƯỢC KHAI THÁC: có tên hàm + tham số thì đọc thành lời gọi công cụ thật để vòng lặp chạy tiếp;
 *   (c) lượt CUỐI mà model vẫn xin gọi công cụ thì được MỘT vòng gia hạn — thay vì trả về khối markup rỗng nghĩa.
 *
 * Chuỗi nhận diện được DỰNG trong mã test (không dán ký tự thô vào tệp): hai ký tự U+FF5C + "DSML" + hai ký tự
 * U+FF5C. Đây là hằng số của provider, KHÔNG phải suy đoán — xem AiModelGateway::TOOL_MARKUP.
 */
class ToolMarkupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

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

    /** Khối markup ĐÚNG dạng đã đo được (mỗi thẻ một dòng). */
    private function markup(string $query, string $tool = 'web_search'): string
    {
        $m = str_repeat("\u{FF5C}", 2).'DSML'.str_repeat("\u{FF5C}", 2);

        return "<{$m} calls>\n"
            ."<{$m} invoke name=\"{$tool}\">\n"
            ."<{$m} parameter name=\"query\" string=\"true\">{$query}</{$m} parameter>\n"
            ."</{$m} invoke>\n"
            ."</{$m} calls>";
    }

    private function marker(): string
    {
        return str_repeat("\u{FF5C}", 2).'DSML'.str_repeat("\u{FF5C}", 2);
    }

    /** Dựng thân SSE từ danh sách mảnh chữ, mỗi mảnh cắt thành các khối \`$size\` ký tự. */
    private function sseFrom(array $pieces, int $size = 1): string
    {
        $sse = '';
        foreach ($pieces as $piece) {
            foreach (mb_str_split((string) $piece, max(1, $size)) as $chunk) {
                $sse .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $chunk]]]], JSON_UNESCAPED_UNICODE)."\n\n";
            }
        }

        return $sse."data: [DONE]\n\n";
    }

    // ── (a)(b) ĐƯỜNG THƯỜNG: markup được THI HÀNH, không được HIỂN THỊ ────────

    public function test_a_markup_tool_call_is_executed_and_never_shown_to_the_user(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-markup', 'markup-1');

        $sent = [];
        Http::fake([
            'gw-markup.example/*' => function ($request) use (&$sent) {
                $body = json_decode($request->body(), true);
                $sent[] = $body;

                // Lượt 1: KHÔNG có trường tool_calls — chỉ có markup trong content (đúng ca đã đo).
                return count($sent) === 1
                    ? Http::response(['choices' => [['message' => ['content' => $this->markup('áo dạ tweed 2026')]]]], 200)
                    : Http::response(['choices' => [['message' => ['content' => 'Tweed đang lên, theo nguồn vừa tra.']]]], 200);
            },
        ]);

        $queries = [];
        $tool = new WebSearchTool(app(WebSourceService::class));
        $tool->enable(true);

        $result = app(AiModelGateway::class)->text(DesignAgentService::SEARCH_GROUP, [
            ['role' => 'user', 'content' => 'Xu hướng áo dạ tweed?'],
        ], [
            'tools' => [$tool->definition()],
            'tool_handler' => function (string $name, array $args) use (&$queries): array {
                $queries[] = (string) ($args['query'] ?? '');

                return ['query' => $args['query'] ?? '', 'found' => 1, 'results' => [], 'note' => 'thử'];
            },
            'tool_begin' => fn () => $tool->beginAttempt(),
            'tool_rounds' => 1,
            'max_tokens' => 200,
        ]);

        $this->assertNotNull($result);
        // (b) markup được KHAI THÁC: từ khoá trong markup đi tới hàm chạy công cụ.
        $this->assertSame(['áo dạ tweed 2026'], $queries, 'Markup phải được đọc thành lời gọi công cụ thật.');
        $this->assertSame(1, $result['tool_calls']);
        $this->assertGreaterThanOrEqual(1, (int) $result['tool_markup_calls'], 'Số đo phải nói rằng provider trả bằng markup.');

        // (a) markup KHÔNG có trong chữ trả về, và KHÔNG được gửi lại cho provider trong lượt sau.
        $this->assertStringNotContainsString($this->marker(), (string) $result['text']);
        $this->assertSame('Tweed đang lên, theo nguồn vừa tra.', $result['text']);
        foreach ((array) ($sent[1]['messages'] ?? []) as $message) {
            $this->assertStringNotContainsString($this->marker(), (string) json_encode($message, JSON_UNESCAPED_UNICODE),
                'Chữ gửi LẠI cho provider còn markup ⇒ nó sẽ tiếp tục trả bằng định dạng đó.');
        }
    }

    // ── (c) LƯỢT CUỐI XIN CÔNG CỤ ⇒ GIA HẠN MỘT VÒNG ────────────────────────

    public function test_a_markup_request_in_the_final_round_gets_one_extra_tool_round(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-markup', 'markup-1');

        $sent = [];
        Http::fake([
            'gw-markup.example/*' => function ($request) use (&$sent) {
                $sent[] = json_decode($request->body(), true);

                return match (count($sent)) {
                    // Lượt 1: gọi công cụ CHUẨN (trường tool_calls) — vòng lặp chạy bình thường.
                    1 => Http::response(['choices' => [['message' => [
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_1', 'type' => 'function',
                            'function' => ['name' => 'web_search', 'arguments' => json_encode(['query' => 'tweed mùa thu'])],
                        ]],
                    ]]]], 200),
                    // Lượt 2 là LƯỢT CUỐI (không khai công cụ) mà model vẫn xin tra tiếp bằng markup.
                    2 => Http::response(['choices' => [['message' => ['content' => $this->markup('giá vải tweed')]]]], 200),
                    // Lượt 3 = vòng GIA HẠN: trả lời thật.
                    default => Http::response(['choices' => [['message' => ['content' => 'Giá tweed đang tăng nhẹ.']]]], 200),
                };
            },
        ]);

        $queries = [];
        $tool = new WebSearchTool(app(WebSourceService::class));
        $tool->enable(true);

        $result = app(AiModelGateway::class)->text(DesignAgentService::SEARCH_GROUP, [
            ['role' => 'user', 'content' => 'Tweed thế nào?'],
        ], [
            'tools' => [$tool->definition()],
            'tool_handler' => function (string $name, array $args) use (&$queries): array {
                $queries[] = (string) ($args['query'] ?? '');

                return ['query' => $args['query'] ?? '', 'found' => 1, 'results' => [], 'note' => 'thử'];
            },
            'tool_begin' => fn () => $tool->beginAttempt(),
            'tool_rounds' => 1,
            'max_tokens' => 200,
        ]);

        $this->assertNotNull($result);
        $this->assertCount(3, $sent, 'Lượt cuối xin công cụ ⇒ phải có vòng gia hạn thứ ba.');
        $this->assertSame(['tweed mùa thu', 'giá vải tweed'], $queries, 'Vòng gia hạn phải chạy được công cụ mà model xin.');
        $this->assertSame('Giá tweed đang tăng nhẹ.', $result['text']);
        $this->assertStringNotContainsString($this->marker(), (string) $result['text']);

        // Vòng gia hạn PHẢI khai công cụ trở lại (đó là điều model đang đòi).
        $this->assertArrayHasKey('tools', (array) $sent[2], 'Vòng gia hạn phải khai công cụ.');
    }

    // ── (a) ĐƯỜNG CHẢY CHỮ: không mảnh nào chứa markup ──────────────────────

    public function test_markup_never_reaches_the_streamed_tokens(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-markup', 'markup-1');

        // Mỗi lời gọi phải nhận một phản hồi MỚI: dùng lại cùng một đối tượng Response thì luồng đã bị đọc
        // hết ở lượt đầu và lượt sau nhận rỗng (đã dính đúng lỗi này khi viết test).
        $markupSse = $this->sseFrom([$this->markup('tweed 2026')], 20);
        $plainSse = $this->sseFrom(['Câu trả lời ', 'thật của ', 'trợ lý.'], 1);

        $sent = 0;
        Http::fake([
            'gw-markup.example/*' => function () use (&$sent, $markupSse, $plainSse) {
                $sent++;

                // Lượt 1: model xin công cụ bằng MARKUP (không có trường tool_calls). Lượt sau: trả lời thật.
                return Http::response($sent === 1 ? $markupSse : $plainSse, 200, ['Content-Type' => 'text/event-stream']);
            },
        ]);

        $tokens = [];
        $result = app(AiModelGateway::class)->stream(DesignAgentService::SEARCH_GROUP, [
            ['role' => 'user', 'content' => 'Tweed thế nào?'],
        ], [
            'tools' => [(new WebSearchTool(app(WebSourceService::class)))->definition()],
            'tool_handler' => fn (string $name, array $args): array => ['query' => $args['query'] ?? '', 'found' => 0, 'results' => []],
            'tool_begin' => fn () => null,
            'tool_rounds' => 1,
            'max_tokens' => 200,
        ], function (string $type, array $payload) use (&$tokens): void {
            if ($type === 'token') {
                $tokens[] = (string) ($payload['text'] ?? '');
            }
        });

        $this->assertNotNull($result);
        $joined = implode('', $tokens);

        // (a) KHÔNG một mảnh nào chứa markup — đây là bất biến quan trọng nhất: người dùng đọc chữ chảy.
        $this->assertStringNotContainsString($this->marker(), $joined, 'Markup đã chảy ra màn hình người dùng.');
        $this->assertStringNotContainsString($this->marker(), (string) $result['text']);
        // Và chữ thường vẫn phải chảy bình thường (không được "im lặng cho an toàn").
        $this->assertStringContainsString('Câu trả lời', $joined);
    }
}
