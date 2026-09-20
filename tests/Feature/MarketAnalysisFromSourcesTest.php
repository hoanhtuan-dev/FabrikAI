<?php

namespace Tests\Feature;

use App\Models\WebSource;
use App\Services\DesignAgentService;
use App\Services\MarketSignalService;
use App\Services\WebSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "CÓ NGUỒN NGOÀI MÀ VẪN DÙNG BỘ CÓ SẴN" — ba nguyên nhân thật, mỗi cái một nhóm test (2026-09-20).
 *
 * Câu hỏi của chủ dự án sau khi deploy, đo được trên production:
 *   1. máy đo chỉ nhận 16/210 tin (trần mỗi nguồn 8/6/5 dùng chung cho cả prompt LẪN việc đo) ⇒ gần như
 *      không từ khoá nào lặp lại ≥2 tin ⇒ không có hướng nào sinh từ tin;
 *   2. máy đo chỉ biết TỪ VỰNG tôi khai — tin nói "tuần lễ thời trang" mà tôi chưa khai thì không thành gì;
 *   3. model suy luận đốt hết ngân sách token vào phần "nghĩ" rồi bị cắt nên không bao giờ viết JSON
 *      ⇒ mọi lượt radar rơi về engine tất định (đọc bộ hướng có sẵn).
 */
class MarketAnalysisFromSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    /** Feed RSS nhiều mục để có đủ mẫu cho việc đo. */
    private function feed(int $count, string $titlePattern = 'Đầm linen số %d - Kenh14.vn'): string
    {
        $body = '<?xml version="1.0"?><rss version="2.0"><channel>';
        for ($i = 1; $i <= $count; $i++) {
            $body .= '<item><title><![CDATA['.sprintf($titlePattern, $i).']]></title>'
                .'<link>https://bao.example/'.$i.'</link>'
                .'<pubDate>'.date('r', time() - 3600 * $i).'</pubDate>'
                .'<description><![CDATA[Mô tả '.$i.' - Kenh14.vn]]></description></item>';
        }

        return $body.'</channel></rss>';
    }

    private function source(array $overrides = []): WebSource
    {
        return WebSource::create(array_merge([
            'slug' => 'nguon-do', 'name' => 'Nguồn đo', 'url' => 'https://feed.example/rss',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 4,
        ], $overrides));
    }

    // ── (1) ĐO TRÊN BẢN RỘNG, KHÔNG DÙNG TRẦN CỦA PROMPT ────────────────────
    /** Trần 4 tin/nguồn cho prompt KHÔNG được chặn việc đo — máy đo phải nhận bản rộng. */
    public function test_measurement_uses_a_wider_base_than_the_prompt(): void
    {
        $this->source(['max_items' => 4]);
        Http::fake(['feed.example/*' => Http::response($this->feed(20), 200)]);

        $sources = app(WebSourceService::class);

        // Prompt: đúng trần của nguồn (4 tin).
        $forPrompt = $sources->evidence('all');
        $this->assertCount(4, $forPrompt['items']);

        // Đo: bản rộng (trần riêng), dù KHÔNG gọi lại mạng (đệm giữ bản đọc được).
        $forMeasurement = $sources->evidence('all', 40, false, WebSourceService::MEASURE_PER_SOURCE);
        $this->assertGreaterThan(4, count($forMeasurement['items']));
    }

    // ── (2) CHỦ ĐỀ ĐỌC TỪ CHÍNH TIN ─────────────────────────────────────────
    /** Cụm từ lặp lại trong tin thành CHỦ ĐỀ, kể cả khi tôi chưa từng khai từ khoá đó. */
    public function test_topics_come_from_the_news_language_itself(): void
    {
        $this->source(['max_items' => 20]);
        Http::fake(['feed.example/*' => Http::response($this->feed(6, 'Tuần lễ thời trang Hàn Quốc lần %d'), 200)]);

        $report = app(MarketSignalService::class)->capture('all');
        $terms = array_column($report['topics'], 'term');

        $this->assertContains('tuần lễ thời trang', $terms, 'Cụm BỐN tiếng trong tin phải được đọc ra (không bị chẻ đôi).');
        $this->assertNotContains('tuần lễ thời', $terms, 'Cụm con của cụm đã giữ phải bị dọn.');
        $this->assertNotContains('lễ thời trang', $terms);
    }

    /** Tên toà soạn không được thành "chủ đề thị trường" (nguồn tổng hợp nhét vào tiêu đề + mô tả). */
    public function test_publisher_names_are_not_topics(): void
    {
        $this->source(['max_items' => 20]);
        Http::fake(['feed.example/*' => Http::response($this->feed(5, 'Đầm linen mùa hè số %d - Kenh14.vn'), 200)]);

        $report = app(MarketSignalService::class)->capture('all');
        $terms = implode(' | ', array_column($report['topics'], 'term'));

        $this->assertStringNotContainsString('kenh14', mb_strtolower($terms));
        $items = app(WebSourceService::class)->evidence('all')['items'];
        $this->assertStringNotContainsString('Kenh14.vn', $items[0]['title'], 'Đuôi toà soạn phải bị bỏ khỏi tiêu đề.');
        $this->assertStringNotContainsString('Kenh14.vn', $items[0]['summary'], 'Và bỏ cả khỏi mô tả.');
    }

    /** Cụm chứa từ dừng không được lọt vào chủ đề. */
    public function test_topics_are_pruned_for_noise(): void
    {
        $signals = app(MarketSignalService::class)->extract([
            ['title' => 'Thời trang tinh tế của Hoàng hậu Thái Lan', 'summary' => ''],
            ['title' => 'Thời trang tinh tế của Hoàng hậu Thái Lan tại Việt Nam', 'summary' => ''],
        ]);
        $terms = array_column($signals['topics'], 'term');

        foreach ($terms as $term) {
            foreach (['của', 'tại', 'cho', 'và'] as $stop) {
                $this->assertStringNotContainsString(' '.$stop.' ', ' '.$term.' ', 'Cụm chứa từ dừng lọt vào chủ đề: '.$term);
            }
        }
    }

    // ── (3) HƯỚNG SINH TỪ TIN PHẢI HIỆN DIỆN ────────────────────────────────
    /** Có chủ đề lặp lại ⇒ radar có hướng sinh từ tin, và KHÔNG hiện trùng thẻ. */
    public function test_news_topics_become_trends_without_duplicates(): void
    {
        $this->source(['max_items' => 20]);
        Http::fake(['feed.example/*' => Http::response($this->feed(6, 'Tuần lễ thời trang Hàn Quốc lần %d'), 200)]);

        $radar = app(DesignAgentService::class)->radar(null, 'all', false);
        $ids = array_column($radar['trends'], 'id');

        $this->assertSame(count($ids), count(array_unique($ids)), 'Một hướng không được hiện hai lần.');
        $this->assertContains('live-tuan-le-thoi-trang', $ids);

        $live = array_filter($radar['trends'], fn (array $t) => $t['evidence_mode'] === 'live');
        $this->assertNotEmpty($live);
        $this->assertSame('live', $radar['trends'][0]['evidence_mode'], 'Hướng có tin thật phải đứng trước.');
    }

    /** Nhóm chủ đề phải có nhãn và việc-nên-làm riêng, không rơi vào nhánh mặc định của nhóm khác. */
    public function test_topic_trends_have_their_own_label_and_action(): void
    {
        $this->source(['max_items' => 20]);
        Http::fake(['feed.example/*' => Http::response($this->feed(6, 'Tuần lễ thời trang Hàn Quốc lần %d'), 200)]);

        $radar = app(DesignAgentService::class)->radar(null, 'all', false);
        $topic = collect($radar['trends'])->firstWhere('id', 'live-tuan-le-thoi-tráng') ?: collect($radar['trends'])->firstWhere('category', 'topic');

        $this->assertNotNull($topic);
        $this->assertSame('topic', $topic['category']);
        $this->assertStringContainsString('đối chiếu', mb_strtolower((string) $topic['recommended_action']));
    }

    // ── (4) VÌ SAO MODEL KHÔNG VIẾT ĐƯỢC JSON ───────────────────────────────
    /** Model "suy luận" bị cắt vì hết ngân sách token ⇒ phải có cờ tắt suy luận và ngân sách lớn hơn. */
    public function test_json_calls_disable_long_reasoning_and_use_a_larger_budget(): void
    {
        $service = (string) file_get_contents(app_path('Services/DesignAgentService.php'));

        $this->assertStringContainsString("'disable_thinking' => true", $service);
        $this->assertStringContainsString('Trả JSON NGAY', $service);
        $this->assertStringContainsString('], 6000, 16000, 90,', $service, 'Ngân sách token phải đủ cho cả phần suy luận của model.');
        $this->assertStringContainsString('fallback_groups', $service);
    }

    /** Cổng model: có nhóm dự phòng + ghi lại VÌ SAO từng model không dùng được. */
    public function test_gateway_falls_back_to_other_groups_and_records_why(): void
    {
        $gateway = (string) file_get_contents(app_path('Services/AiModelGateway.php'));

        $this->assertStringContainsString('fallback_groups', $gateway);
        $this->assertStringContainsString('lastAttempts', $gateway);
        $this->assertStringContainsString('applyThinkingOff', $gateway);
        $this->assertStringContainsString('enable_thinking', $gateway);
        $this->assertStringContainsString("unset(\$body['enable_thinking'])", $gateway, 'Provider không hiểu cờ thì phải gọi lại KHÔNG có cờ.');
    }

    /** Giao diện: có chip lọc "có tin thật", có khối chủ đề, và nói rõ bao nhiêu hướng là bộ có sẵn. */
    public function test_the_screen_separates_measured_trends_from_the_builtin_set(): void
    {
        $view = (string) file_get_contents(resource_path('js/studio/components/DesignAgents.vue'));

        $this->assertStringContainsString('Có tin thật ({{ liveTrendCount }})', $view);
        $this->assertStringContainsString('Chủ đề đang được nói tới trong tin', $view);
        $this->assertStringContainsString('hướng thuộc bộ có sẵn', $view);
        $this->assertStringContainsString('marketTopics', $view);
    }
}
