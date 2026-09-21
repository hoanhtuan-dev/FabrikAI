<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [2026-09-22] Agent Studio PHẢI nhận biết model: hai agent (TrendRadar + CollectionBot) đọc
 * model từ NHÓM CÔNG VIỆC 'prompt' trong Cài đặt, và khi không có model thì phải NÓI THẬT
 * (mode=rule + lý do) thay vì im lặng.
 *
 * Lỗi thật trước đây: cả hai agent chạy 100% rule-based, KHÔNG gọi model nào — người dùng đổi
 * Model Registry / Nhóm công việc xong vẫn thấy y hệt, không có dấu hiệu nào cho biết AI có
 * đang chạy hay không.
 *
 * Nguyên tắc được khoá bằng test:
 *   1. có key ⇒ gọi ĐÚNG model của nhóm 'prompt', trả về engine=ai-v1 + khối model;
 *   2. AI chỉ trả CHỮ — mọi con số vẫn do tầng dữ liệu quyết định (AI không thể bịa số liệu);
 *   3. model lỗi / JSON hỏng ⇒ quay về engine tất định kèm lý do cụ thể;
 *   4. người dùng tắt AI ⇒ không gọi model nào (không tốn lượt gọi).
 */
class DesignAgentAiTest extends TestCase
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

    /** Cấu hình nhóm 'prompt' trỏ vào DeepSeek + key đang bật (đúng cách Cài đặt làm). */
    private function configurePromptModel(string $modelId = 'deepseek-chat'): void
    {
        StudioModel::create([
            'group' => 'prompt', 'name' => 'DeepSeek '.$modelId, 'provider' => 'deepseek',
            'model_id' => $modelId, 'api_key_ref' => 'deepseek', 'priority' => 9, 'enabled' => true,
        ]);
        StudioApiKey::create([
            'provider' => 'deepseek', 'label' => 'deepseek', 'value' => 'sk-deepseek-test',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        set_setting('studio_task_prompt_model', 'deepseek:'.$modelId);
    }

    private function fakeChat(string $content, int $status = 200): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $content]]]], $status),
        ]);
    }

    private function directionsJson(int $count = 6): string
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'title' => 'Định hướng AI '.($i + 1),
                'thesis' => 'Luận điểm '.($i + 1),
                'why_now' => 'Vì sao bây giờ '.($i + 1),
                'action' => 'Việc cần làm '.($i + 1),
                'risk' => 'Rủi ro '.($i + 1),
                'price_band' => 'mid',
                'confidence' => 0.7,
                'trend_ids' => ['linen-breeze'],
            ];
        }

        return json_encode(['directions' => $rows], JSON_UNESCAPED_UNICODE);
    }

    private function briefJson(): string
    {
        return json_encode([
            'narrative' => 'DNA shop do AI viết: tối giản, linen, màu pastel.',
            'brief' => 'Brief do AI viết cho xưởng may.',
            'moodboard_captions' => array_map(fn ($i) => 'Caption AI '.$i, range(1, 24)),
            'category_rationale' => ['Áo / blouse' => 'Lý do AI cho nhóm áo.'],
            'outfit_goals' => ['look-1' => 'Mục tiêu AI cho look 1.'],
            'prompt_vi' => 'Prompt tiếng Việt do AI viết.',
            'prompt_en' => 'AI written English prompt for the collection.',
            'next_steps' => ['Bước AI 1', 'Bước AI 2', 'Bước AI 3'],
            // Số liệu do AI "tự nghĩ" — KHÔNG được lọt vào response.
            'total_skus' => 999,
            'images_analyzed' => 123456,
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── 0. JSON HỎNG KIỂU THẬT của model vẫn phải dùng được ─────────────────

    /**
     * Nháy kép NẰM TRONG nội dung — kiểu hỏng hay gặp nhất của model viết tiếng Việt (nó dùng nháy
     * kép kiểu báo chí cho một cụm, vd "dáng "may đo""). JSON cấm nháy kép chưa escape trong chuỗi.
     */
    public function test_a_model_reply_with_stray_double_quotes_inside_values_is_repaired(): void
    {
        $this->configurePromptModel();
        // Dựng JSON hợp lệ rồi thay brief bằng một câu chứa nháy kép THẬT chưa escape.
        $broken = str_replace(
            '"Brief do AI viết cho xưởng may."',
            '"Brief phong cách \\"may đo\\" và \\"dễ mặc\\"."',
            $this->briefJson()
        );
        $this->assertStringContainsString('may đo', $broken);
        $this->fakeChat($broken);

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', ['prompt' => 'Bộ sưu tập linen pastel'])
            ->assertOk();

        $response->assertJsonPath('model.mode', 'ai');
        $this->assertStringContainsString('may đo', (string) $response->json('brief'));
    }

    /**
     * [LỖI THẬT — production 2026-09-21] Model trả về JSON mở đầu/kết thúc đúng dạng nhưng XUỐNG DÒNG
     * thật bên trong giá trị chuỗi. JSON không cho phép ký tự điều khiển trong chuỗi ⇒ cả câu trả lời
     * thành không đọc được, brief rơi về bộ quy tắc, và người dùng đọc câu "AI trả về dữ liệu không
     * dùng được" dù AI ĐÃ trả lời đầy đủ.
     */
    public function test_a_model_reply_with_raw_newlines_inside_values_is_still_used(): void
    {
        $this->configurePromptModel();
        $broken = str_replace(
            '"Brief do AI viết cho xưởng may."',
            "\"Brief do AI viết cho xưởng may.\nDòng thứ hai của brief.\"",
            $this->briefJson()
        );
        $this->assertStringContainsString("may.\nDòng", $broken, 'Ca kiểm thử phải chứa xuống dòng THẬT.');
        $this->fakeChat($broken);

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', ['prompt' => 'Bộ sưu tập linen pastel'])
            ->assertOk();

        $response->assertJsonPath('model.mode', 'ai');
        $this->assertStringContainsString('Brief do AI viết', (string) $response->json('brief'));
        $this->assertStringContainsString('Dòng thứ hai', (string) $response->json('brief'));
    }

    /** Dấu phẩy thừa trước ngoặc đóng — kiểu hỏng thứ hai hay gặp. */
    public function test_a_model_reply_with_a_trailing_comma_is_repaired(): void
    {
        $this->configurePromptModel();
        $json = $this->briefJson();
        $broken = preg_replace('/\}\s*$/', ',}', $json);
        $this->fakeChat($broken);

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', ['prompt' => 'Bộ sưu tập linen pastel'])
            ->assertOk();

        $response->assertJsonPath('model.mode', 'ai');
        $this->assertSame('DNA shop do AI viết: tối giản, linen, màu pastel.', $response->json('brand_narrative.narrative'));
    }

    /**
     * BỘ SỬA JSON KHÔNG ĐƯỢC SỬA NỘI DUNG. Cùng một dấu phẩy: ở ngoài chuỗi là lỗi cú pháp, ở TRONG
     * chuỗi là câu người dùng viết. Regex không phân biệt được hai chỗ đó — nên bộ sửa phải là máy
     * trạng thái, và bài này khoá đúng điều đó.
     */
    public function test_json_repair_never_rewrites_text_inside_strings(): void
    {
        $this->configurePromptModel();
        $json = $this->briefJson();
        // (a) câu chứa dấu phẩy + ngoặc đóng NGAY TRONG nội dung;
        $json = str_replace(
            '"DNA shop do AI viết: tối giản, linen, màu pastel."',
            '"DNA shop, } giữ nguyên dấu đóng."',
            $json
        );
        // (b) và một lỗi thật ở chỗ khác để bắt buộc bộ sửa phải chạy.
        $broken = str_replace(
            '"Brief do AI viết cho xưởng may."',
            "\"Brief do AI viết cho xưởng may.\nDòng hai.\"",
            $json
        );
        $this->fakeChat($broken);

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', ['prompt' => 'Bộ sưu tập linen pastel'])
            ->assertOk();

        $this->assertSame('DNA shop, } giữ nguyên dấu đóng.', $response->json('brand_narrative.narrative'),
            'Bộ sửa JSON đã sửa cả nội dung bên trong chuỗi — đúng thứ không được phép xảy ra.');
    }

    // ── 1. TrendRadar gọi đúng model của nhóm 'prompt' ──────────────────────

    public function test_radar_uses_the_configured_prompt_model(): void
    {
        $this->configurePromptModel();
        $this->fakeChat($this->directionsJson());

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/radar', ['region' => 'hcm'])
            ->assertOk();

        $response->assertJsonPath('engine', 'ai-v1')
            ->assertJsonPath('model.mode', 'ai')
            ->assertJsonPath('model.group', 'prompt')
            ->assertJsonPath('model.provider', 'deepseek')
            ->assertJsonPath('model.model', 'deepseek-chat');
        $this->assertGreaterThanOrEqual(5, count($response->json('directions')));
        $this->assertSame('ai', $response->json('directions.0.source'));
        $this->assertSame('Định hướng AI 1', $response->json('directions.0.title'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.deepseek.com/chat/completions')
                && ($request->data()['model'] ?? null) === 'deepseek-chat';
        });
    }

    public function test_radar_directions_are_grounded_in_the_catalog(): void
    {
        $this->configurePromptModel();
        $this->fakeChat(json_encode(['directions' => [
            ['title' => 'Bịa id', 'trend_ids' => ['khong-ton-tai', 'linen-breeze'], 'confidence' => 150],
            ['title' => 'Hướng hai', 'trend_ids' => ['soft-pastel'], 'confidence' => 0.5],
            ['title' => 'Hướng ba', 'trend_ids' => [], 'confidence' => 'khong-phai-so'],
        ]], JSON_UNESCAPED_UNICODE));

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $ids = $response->json('directions.0.trend_ids');
        $this->assertSame(['linen-breeze'], $ids, 'AI chỉ được tham chiếu id xu hướng CÓ THẬT trong catalog.');
        $this->assertSame(1.0, (float) $response->json('directions.0.confidence'), 'Confidence phải được kẹp về 0..1.');
        $this->assertNull($response->json('directions.2.confidence'), 'Confidence không phải số thì phải bỏ, không nhận bừa.');
    }

    // ── 2. AI không được tạo số liệu ────────────────────────────────────────

    public function test_ai_cannot_inject_numbers_into_the_radar_summary(): void
    {
        $this->configurePromptModel();
        $this->fakeChat(json_encode([
            'directions' => [['title' => 'A', 'trend_ids' => []], ['title' => 'B'], ['title' => 'C']],
            'summary' => ['images_analyzed_monthly' => 123456, 'active_trends' => 99],
        ], JSON_UNESCAPED_UNICODE));

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $response->assertJsonPath('source_mode', 'demo')
            ->assertJsonPath('summary.images_analyzed_monthly', 0)
            ->assertJsonPath('summary.active_trends', 8);
        $this->assertSame('demo', $response->json('trends.0.evidence_mode'));
    }

    public function test_ai_directions_are_topped_up_to_the_minimum_five(): void
    {
        $this->configurePromptModel();
        $this->fakeChat(json_encode(['directions' => [
            ['title' => 'Chỉ một hướng', 'trend_ids' => []],
            ['title' => 'Hướng hai', 'trend_ids' => []],
            ['title' => 'Hướng ba', 'trend_ids' => []],
        ]], JSON_UNESCAPED_UNICODE));

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $this->assertSame('ai-v1', $response->json('engine'));
        $this->assertGreaterThanOrEqual(5, count($response->json('directions')));
        $sources = array_column($response->json('directions'), 'source');
        $this->assertContains('rule', $sources, 'Thiếu hướng thì phải bù bằng hướng tất định, không trả danh sách rỗng.');
    }

    // ── 2b. Đầu ra "bẩn" của model vẫn phải dùng được ───────────────────────

    public function test_radar_recovers_directions_from_a_truncated_response(): void
    {
        $this->configurePromptModel();
        $full = json_encode(['directions' => array_map(fn ($i) => [
            'title' => 'Hướng AI '.$i,
            'thesis' => 'Luận điểm '.$i,
            'why_now' => 'Vì sao '.$i,
            'action' => 'Việc làm '.$i,
            'risk' => 'Rủi ro '.$i,
            'trend_ids' => ['linen-breeze'],
        ], range(1, 5))], JSON_UNESCAPED_UNICODE);

        // Mô phỏng model bị hết token giữa chừng: JSON bị cắt cụt.
        $this->fakeChat(substr($full, 0, strlen($full) - 45));

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $this->assertSame('ai-v1', $response->json('engine'), 'JSON bị cắt vẫn phải cứu được, không được đẩy về tất định.');
        $this->assertGreaterThanOrEqual(3, count($response->json('directions')));
        $this->assertSame('ai', $response->json('directions.0.source'));
        $this->assertSame('Hướng AI 1', $response->json('directions.0.title'));
    }

    public function test_radar_accepts_json_wrapped_in_a_code_fence(): void
    {
        $this->configurePromptModel();
        $fence = '```json' . "\n" . $this->directionsJson(4) . "\n" . '```';
        $this->fakeChat('Đây là kết quả:'."\n".$fence);

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $this->assertSame('ai-v1', $response->json('engine'));
        $this->assertSame('Định hướng AI 1', $response->json('directions.0.title'));
    }

    /**
     * Đo THẬT trên production: deepseek-flash là model suy luận (token suy luận tính vào
     * max_tokens) nên lần gọi đầu có thể chỉ trả về đúng vài ký tự hoặc chỉ có reasoning.
     * Agent phải THỬ LẠI với ngân sách token lớn hơn thay vì bỏ cuộc.
     */
    public function test_radar_retries_with_a_bigger_token_budget_when_the_answer_is_cut(): void
    {
        $this->configurePromptModel();
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push(['choices' => [[
                    'message' => ['content' => '{"dire'],
                    'finish_reason' => 'length',
                ]]], 200)
                ->push(['choices' => [[
                    'message' => ['content' => $this->directionsJson(6)],
                    'finish_reason' => 'stop',
                ]]], 200),
        ]);

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $response->assertJsonPath('engine', 'ai-v1')->assertJsonPath('model.attempts', 2);
        $this->assertSame('Định hướng AI 1', $response->json('directions.0.title'));

        $budgets = Http::recorded()
            ->map(fn ($pair) => (int) ($pair[0]->data()['max_tokens'] ?? 0))
            ->values()
            ->all();
        $this->assertCount(2, $budgets, 'Phải gọi lại đúng một lần.');
        $this->assertGreaterThan($budgets[0], $budgets[1], 'Lần thử lại phải xin ngân sách token LỚN HƠN.');
    }

    public function test_radar_retries_when_only_reasoning_came_back(): void
    {
        $this->configurePromptModel();
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push(['choices' => [[
                    'message' => ['content' => '', 'reasoning_content' => 'Ta cần trả JSON gồm các định hướng…'],
                    'finish_reason' => 'length',
                ]]], 200)
                ->push(['choices' => [[
                    'message' => ['content' => $this->directionsJson(5)],
                    'finish_reason' => 'stop',
                ]]], 200),
        ]);

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $response->assertJsonPath('engine', 'ai-v1')->assertJsonPath('model.attempts', 2);
    }

    // ── 3. Lỗi model ⇒ quay về tất định và nói rõ lý do ─────────────────────

    public function test_radar_falls_back_when_the_model_returns_broken_json(): void
    {
        $this->configurePromptModel();
        $this->fakeChat('xin chào, tôi không trả JSON đâu');

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $response->assertJsonPath('engine', 'rule-based-v1')
            ->assertJsonPath('model.mode', 'rule')
            ->assertJsonPath('model.reason', 'invalid_output')
            ->assertJsonPath('model.attempted', 'deepseek:deepseek-chat');
        $this->assertGreaterThanOrEqual(5, count($response->json('directions')));
        $this->assertSame('rule', $response->json('directions.0.source'));
    }

    public function test_radar_reports_rule_mode_without_any_model_key(): void
    {
        Http::fake();

        $response = $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        $response->assertJsonPath('engine', 'rule-based-v1')
            ->assertJsonPath('model.mode', 'rule')
            ->assertJsonPath('model.reason', 'no_model_key')
            ->assertJsonPath('model.candidates', 0);
        Http::assertNothingSent();
    }

    // ── 4. Người dùng tắt AI ⇒ không gọi model ──────────────────────────────

    public function test_radar_can_be_forced_to_rule_mode_by_the_client(): void
    {
        $this->configurePromptModel();
        Http::fake();

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/radar', ['ai' => false])
            ->assertOk();

        $response->assertJsonPath('engine', 'rule-based-v1')
            ->assertJsonPath('model.reason', 'ai_disabled');
        $this->assertGreaterThan(0, $response->json('model.candidates'));
        Http::assertNothingSent();
    }

    // ── 5. CollectionBot: AI viết chữ, hệ thống giữ số ──────────────────────

    public function test_collection_brief_applies_ai_text_and_keeps_the_numbers(): void
    {
        $this->configurePromptModel();
        $this->fakeChat($this->briefJson());

        $user = $this->customer();
        $payload = [
            'prompt' => 'Bộ sưu tập linen pastel cho công sở',
            'region' => 'all',
            'trend_ids' => ['linen-breeze', 'soft-pastel'],
            'size_distribution' => ['s' => 12, 'm' => 24, 'l' => 18, 'xl' => 6],
        ];

        $ai = $this->actingAs($user)->postJson('/api/design-agent/collection', $payload)->assertOk();
        $rule = $this->actingAs($user)->postJson('/api/design-agent/collection', $payload + ['ai' => false])->assertOk();

        $ai->assertJsonPath('engine', 'ai-v1')
            ->assertJsonPath('model.provider', 'deepseek')
            ->assertJsonPath('ai_applied.narrative', true)
            ->assertJsonPath('ai_applied.brief', true)
            ->assertJsonPath('ai_applied.moodboard_captions', 24)
            ->assertJsonPath('brand_narrative.narrative', 'DNA shop do AI viết: tối giản, linen, màu pastel.')
            ->assertJsonPath('brief', 'Brief do AI viết cho xưởng may.')
            ->assertJsonPath('moodboard.items.0.caption', 'Caption AI 1')
            ->assertJsonPath('prompt_en', 'AI written English prompt for the collection.')
            ->assertJsonPath('next_steps.0', 'Bước AI 1')
            ->assertJsonPath('structure.categories.0.rationale', 'Lý do AI cho nhóm áo.')
            ->assertJsonPath('outfit_matching.0.goal', 'Mục tiêu AI cho look 1.');

        // SỐ LIỆU bất biến: AI trả total_skus=999 nhưng response vẫn đúng con số của hệ thống.
        $this->assertSame($rule->json('structure.total_skus'), $ai->json('structure.total_skus'));
        $this->assertSame($rule->json('size_distribution'), $ai->json('size_distribution'));
        $this->assertSame($rule->json('price_bands'), $ai->json('price_bands'));
        $this->assertSame(24, $ai->json('moodboard.count'));
        $this->assertSame($rule->json('project_payload.name'), $ai->json('project_payload.name'));
    }

    public function test_collection_falls_back_when_the_model_is_down(): void
    {
        $this->configurePromptModel();
        $this->fakeChat('', 500);

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', ['prompt' => 'Bộ sưu tập thu đông tối giản'])
            ->assertOk();

        $response->assertJsonPath('engine', 'rule-based-v1')
            ->assertJsonPath('model.reason', 'model_error')
            ->assertJsonPath('ai_applied.brief', false);
        $this->assertNotEmpty($response->json('brief'));
        $this->assertCount(24, $response->json('moodboard.items'));
        $this->assertNotEmpty($response->json('prompt_en'));
    }

    public function test_user_written_brief_wins_over_the_model(): void
    {
        $this->configurePromptModel();
        $this->fakeChat($this->briefJson());

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', [
                'prompt' => 'Bộ sưu tập linen pastel',
                'brief' => 'Brief do NGƯỜI DÙNG viết, không được ghi đè.',
            ])
            ->assertOk();

        $response->assertJsonPath('brief', 'Brief do NGƯỜI DÙNG viết, không được ghi đè.')
            ->assertJsonPath('ai_applied.brief', false)
            ->assertJsonPath('ai_applied.narrative', true);
    }

    public function test_internal_brand_signal_never_reaches_the_shared_model_cache(): void
    {
        $this->configurePromptModel();
        $this->fakeChat($this->directionsJson(3));

        $this->actingAs($this->customer())->postJson('/api/design-agent/radar')->assertOk();

        // Payload gửi cho model chỉ chứa catalog của vùng — KHÔNG chứa tín hiệu nội bộ.
        Http::assertSent(function ($request) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return ! str_contains($body, 'internal_brand_signal') && ! str_contains($body, 'generation_count');
        });
    }
}
