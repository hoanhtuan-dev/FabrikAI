<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DesignAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BA VAI RIÊNG CỦA AGENT STUDIO (Đợt 30 — 2026-09-23): suy luận · đọc ảnh · tìm kiếm.
 *
 * Vì sao tách: ba việc cần ba loại model khác nhau (viết nội dung / nhìn được ảnh / có tìm kiếm). Gộp vào
 * một nhóm thì đổi một vai là đổi cả ba. Bộ test này khoá: nhóm nào khai thì dùng nhóm đó, nhóm nào bỏ
 * trống thì rơi về nhóm nền (không im lặng quay về engine tất định), và vai đọc ảnh thật sự gửi ảnh cho model.
 */
class AgentRolesTest extends TestCase
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

    /** Khai một provider + key + model cho một nhóm công việc. */
    private function model(string $group, string $slug, string $modelId, array $extra = []): void
    {
        \App\Models\StudioProvider::create(array_merge([
            'slug' => $slug, 'name' => 'Gateway '.$slug, 'protocol' => 'openai',
            'base_url' => 'https://'.$slug.'.example/v1', 'auth_style' => 'bearer',
            'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
        ], $extra));
        \App\Models\StudioApiKey::create([
            'provider' => $slug, 'label' => $slug, 'value' => 'sk-'.$slug,
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        \App\Models\StudioModel::create([
            'group' => $group, 'name' => $modelId, 'provider' => $slug,
            'model_id' => $modelId, 'api_key_ref' => $slug, 'priority' => 9, 'enabled' => true,
        ]);
        set_setting('studio_task_'.$group.'_model', $slug.':'.$modelId);
    }

    /**
     * Thân request ở dạng ĐỌC ĐƯỢC: HTTP client mã hoá lại (mất JSON_UNESCAPED_UNICODE) và khối DỮ LIỆU còn
     * nằm trong `content` dưới dạng chuỗi JSON — phải giải CẢ HAI lớp rồi mới so được chữ tiếng Việt.
     */
    private function readableBody(string $body): string
    {
        $payload = json_decode($body, true);
        $text = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $content = (string) data_get($payload, 'messages.1.content', '');
        $start = strpos($content, '{');
        if ($start !== false) {
            $text .= json_encode(json_decode(substr($content, $start), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $text;
    }

    private function briefJson(): string
    {
        return json_encode([
            'narrative' => 'n', 'brief' => 'b', 'prompt_vi' => 'vi', 'prompt_en' => 'en',
            'moodboard_captions' => array_fill(0, 24, 'c'), 'category_rationale' => [], 'outfit_goals' => [],
            'next_steps' => ['a', 'b', 'c'],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── (A) NHÓM ĐƯỢC KHAI ─────────────────────────────────────────────────

    /** Bốn nhóm vai phải có trong danh sách nhóm công việc (Settings đọc từ đây để hiện ra). */
    public function test_the_agent_roles_are_registered(): void
    {
        $groups = studio_task_groups();

        foreach ([DesignAgentService::REASON_GROUP, DesignAgentService::VISION_GROUP, DesignAgentService::SEARCH_GROUP, DesignAgentService::REFLECT_GROUP] as $key) {
            $this->assertArrayHasKey($key, $groups, 'Thiếu nhóm '.$key);
            $this->assertStringContainsString('Agent Studio', (string) $groups[$key]['label']);
        }

        // Model Registry nhận nhóm qua studio_model_group_slugs() — ba vai Agent Studio phải nằm trong đó
        // (kèm hai vai cũ inference/text để cấu hình cũ không vỡ), không để mỗi nơi chép một danh sách rồi lệch.
        $slugs = studio_model_group_slugs();
        foreach ([DesignAgentService::REASON_GROUP, DesignAgentService::VISION_GROUP, DesignAgentService::SEARCH_GROUP, DesignAgentService::REFLECT_GROUP, 'inference', 'text'] as $key) {
            $this->assertContains($key, $slugs, 'studio_model_group_slugs() thiếu nhóm '.$key);
        }

        // Model Registry UI (SettingsApp.vue) cũng phải liệt kê 3 vai trong dropdown "Vai trò" — chỉ backend
        // nhận mà UI thiếu thì chủ shop vẫn không thêm được model.
        $view = (string) file_get_contents(resource_path('js/studio/SettingsApp.vue'));
        foreach ([DesignAgentService::REASON_GROUP, DesignAgentService::VISION_GROUP, DesignAgentService::SEARCH_GROUP, DesignAgentService::REFLECT_GROUP] as $key) {
            $this->assertStringContainsString("'".$key."'", $view, 'SettingsApp.vue (Model Registry) thiếu vai '.$key);
        }
    }

    /** Model Registry phải NHẬN được 3 vai Agent Studio khi thêm model (validation không được chặn). */
    public function test_the_model_registry_accepts_agent_roles(): void
    {
        $admin = User::where('email', 'admin@fabrikai.shop')->firstOrFail();

        $this->actingAs($admin)
            ->postJson('/api/settings-vue/models', [
                'group' => DesignAgentService::SEARCH_GROUP,
                'name' => 'Model tìm kiếm nguồn ngoài',
                'provider' => 'gw-search',
                'model_id' => 'search-1',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('studio_models', ['group' => 'agent_search', 'model_id' => 'search-1']);
    }

    /** Khai nhóm SUY LUẬN ⇒ lượt chạy dùng đúng model đó và khối `model` nói đúng nhóm. */
    public function test_the_reason_role_overrides_the_shared_group(): void
    {
        $this->model(DesignAgentService::REASON_GROUP, 'gw-reason', 'reason-1');
        Http::fake(['gw-reason.example/*' => Http::response(['choices' => [['message' => ['content' => $this->briefJson()]]]], 200)]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame('gw-reason', $brief['model']['provider']);
        $this->assertSame('reason-1', $brief['model']['model']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gw-reason.example'));
    }

    /** Bỏ trống nhóm riêng ⇒ rơi về nhóm nền 'prompt' (cấu hình cũ KHÔNG bị vỡ). */
    public function test_an_empty_role_falls_back_to_the_shared_group(): void
    {
        $this->model('prompt', 'gw-shared', 'shared-1');
        Http::fake(['gw-shared.example/*' => Http::response(['choices' => [['message' => ['content' => $this->briefJson()]]]], 200)]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame('gw-shared', $brief['model']['provider'], 'Nhóm riêng bỏ trống thì phải dùng nhóm nền.');
        $this->assertSame('ai-v1', $brief['engine']);
    }

    // ── (A2) VAI TÌM KIẾM INTERNET (đảm bảo nhóm search thật sự chạy, không chỉ "có tên") ──

    /**
     * Khai nhóm TÌM KIẾM với provider tự khai search_param ⇒ lượt chạy dùng ĐÚNG model của nhóm đó
     * VÀ gửi cờ bật tìm kiếm web vào body (không chỉ là cờ nội bộ nói suông).
     */
    public function test_the_search_role_is_used_and_enables_web_search(): void
    {
        // Custom Provider khai cách bật tìm kiếm (search_param) — planFor đọc tham số này trước giao thức.
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1', [
            'search_param' => 'enable_search',
            'search_mode' => 'body_flag',
        ]);
        Http::fake(['gw-search.example/*' => Http::response(['choices' => [['message' => ['content' => $this->briefJson()]]]], 200)]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame('gw-search', $brief['model']['provider'], 'Vai tìm kiếm đã khai thì lượt chạy phải dùng model của nó.');
        $this->assertSame('search-1', $brief['model']['model']);
        $this->assertTrue($brief['model']['web_search'], 'Model có khả năng tìm kiếm thì cờ web_search phải bật.');

        // Cờ bật tìm kiếm THẬT SỰ nằm trong request gửi đi (enable_search), không phải chỉ là cờ báo cáo.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gw-search.example')
            && str_contains((string) $request->body(), 'enable_search'));
    }

    /**
     * Nhóm tìm kiếm BỎ TRỐNG ⇒ rơi về nhóm suy luận và KHÔNG bật tìm kiếm web (nói đúng, không hứa suông).
     */
    public function test_an_empty_search_role_falls_back_without_web_search(): void
    {
        $this->model(DesignAgentService::REASON_GROUP, 'gw-reason', 'reason-1');
        Http::fake(['gw-reason.example/*' => Http::response(['choices' => [['message' => ['content' => $this->briefJson()]]]], 200)]);

        $brief = app(DesignAgentService::class)->collectionBrief(['prompt' => 'đầm linen'], $this->customer(), true, true);

        $this->assertSame('gw-reason', $brief['model']['provider'], 'Nhóm tìm kiếm bỏ trống thì dùng nhóm suy luận.');
        $this->assertFalse($brief['model']['web_search'], 'Không có model tìm kiếm thì cờ web_search phải TẮT.');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gw-reason.example')
            && ! str_contains((string) $request->body(), 'enable_search'));
    }

    /**
     * Đường RADAR cũng phải chạy được BẰNG MỘT MÌNH nhóm tìm kiếm (không cần nhóm suy luận) —
     * khoá luôn cả hai đường vì trước đây cả hai đều kiểm nhóm suy luận TRƯỚC nhóm tìm kiếm.
     */
    public function test_the_search_role_runs_radar_standalone(): void
    {
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1', [
            'search_param' => 'enable_search',
            'search_mode' => 'body_flag',
        ]);
        Http::fake(['gw-search.example/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'directions' => array_fill(0, 6, ['title' => 'Hướng', 'thesis' => 't', 'why_now' => 'w', 'action' => 'a', 'risk' => 'r', 'price_band' => 'mid']),
        ], JSON_UNESCAPED_UNICODE)]]]], 200)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', true);

        $this->assertSame('ai-v1', $radar['engine'], 'Radar phải chạy AI bằng nhóm tìm kiếm khi nhóm suy luận trống.');
        $this->assertSame('gw-search', $radar['model']['provider']);
        $this->assertSame('search-1', $radar['model']['model']);
        $this->assertTrue($radar['model']['web_search'], 'Radar chạy bằng model tìm kiếm thì cờ web_search phải bật.');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gw-search.example')
            && str_contains((string) $request->body(), 'enable_search'));
    }

    /**
     * Nhóm tìm kiếm CÓ model OpenAI-compatible (không khai `search_param`) ⇒ radar DÙNG model đó và KHAI
     * công cụ `web_search` cho nó. Trước 2026-09-24 ca này bị coi là "không tìm kiếm được" nên radar lặng lẽ
     * bỏ qua vai tìm kiếm — đúng với cách bật tìm kiếm cũ (chỉ nhà cung cấp tự có tìm kiếm), SAI với công cụ
     * do máy chủ chạy: model không cần có tìm kiếm tích hợp, nó chỉ cần biết gọi hàm.
     */
    public function test_radar_uses_an_openai_compatible_search_model_with_the_web_search_tool(): void
    {
        // Nhóm tìm kiếm CÓ model; provider KHÔNG khai search_param ⇒ planFor = null (không có tìm kiếm sẵn).
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-search', 'search-1');
        // Nhóm suy luận có model bình thường.
        $this->model(DesignAgentService::REASON_GROUP, 'gw-reason', 'reason-1');
        Http::fake(['gw-search.example/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'directions' => array_fill(0, 6, ['title' => 'Hướng', 'thesis' => 't', 'why_now' => 'w', 'action' => 'a', 'risk' => 'r', 'price_band' => 'mid']),
        ], JSON_UNESCAPED_UNICODE)]]]], 200)]);

        $radar = app(DesignAgentService::class)->radar($this->customer(), 'all', true);

        $this->assertSame('gw-search', $radar['model']['provider'], 'Vai tìm kiếm đã khai thì lượt chạy phải dùng model của nó.');
        $this->assertTrue($radar['model']['web_search'], 'Provider chấp nhận công cụ thì web_search phải bật.');
        $this->assertSame('tool', $radar['model']['tool_search']['mode']);
        $this->assertTrue($radar['model']['tool_search']['accepted']);

        // Công cụ phải THẬT SỰ nằm trong request gửi model, không chỉ là cờ báo cáo.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gw-search.example')
            && str_contains((string) $request->body(), 'web_search'));
    }

    /**
     * Model trong nhóm tìm kiếm KHÔNG gọi được công cụ (giao thức Gemini — đường tìm kiếm của nó là
     * grounding riêng, không phải `tools` kiểu OpenAI) và KHÔNG khai `search_param` ⇒ không tính là tìm được;
     * radar phải quay về nhóm suy luận chứ không gọi nhóm tìm kiếm rồi treo.
     */
    public function test_radar_ignores_a_search_group_model_that_cannot_search_at_all(): void
    {
        // Transport gemini có kế hoạch tìm kiếm riêng nên vẫn tính là tìm được; để mô phỏng "không tìm được"
        // ta dùng một giao thức lạ (không openai/qwen/dashscope/gemini) — đúng ca mà cả hai đường đều không có.
        $this->model(DesignAgentService::SEARCH_GROUP, 'gw-la', 'la-1', ['protocol' => 'la']);

        $this->assertNull(
            \App\Services\WebAccessService::planFor(['provider' => 'gw-la', 'model' => 'la-1', 'transport' => 'la']),
            'Giao thức lạ không được coi là có tìm kiếm sẵn.',
        );
        $this->assertFalse(
            (new \ReflectionMethod(DesignAgentService::class, 'toolSearchCapable'))->invoke(null, ['transport' => 'la']),
            'Giao thức lạ không được coi là gọi được công cụ.',
        );
    }

    // ── (B) VAI ĐỌC ẢNH ────────────────────────────────────────────────────

    /**
     * Ảnh mẫu: model đọc ảnh phải NHẬN ĐƯỢC ảnh (data URI) và câu mô tả của nó phải vào brief.
     */
    public function test_the_vision_role_reads_reference_images_and_feeds_the_brief(): void
    {
        $this->model(DesignAgentService::REASON_GROUP, 'gw-reason', 'reason-1');
        $this->model(DesignAgentService::VISION_GROUP, 'gw-vision', 'vision-1');

        // Ảnh mẫu nằm trong thư mục public của chính app (đường dẫn nội bộ).
        $relative = 'storage/test-ref-'.uniqid().'.png';
        $absolute = public_path($relative);
        @mkdir(dirname($absolute), 0775, true);
        file_put_contents($absolute, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));

        Http::fake([
            'gw-vision.example/*' => Http::response(['choices' => [['message' => ['content' => 'Vải linen thô, tông trắng ngà, ánh sáng cửa sổ.']]]], 200),
            'gw-reason.example/*' => Http::response(['choices' => [['message' => ['content' => $this->briefJson()]]]], 200),
        ]);

        $brief = app(DesignAgentService::class)->collectionBrief([
            'prompt' => 'đầm linen',
            'reference_images' => ['/'.$relative],
        ], $this->customer(), true, true);

        @unlink($absolute);

        $this->assertTrue($brief['reference_style']['used'], 'AI phải đọc được ảnh mẫu.');
        $this->assertSame(1, $brief['reference_style']['count']);
        $this->assertStringContainsString('linen thô', (string) $brief['reference_style']['note']);

        // Ảnh THẬT SỰ được gửi cho model đọc ảnh (image_url data URI), và mô tả vào prompt của model suy luận.
        // Http::recorded() trả Collection ⇒ phải ->all() trước khi lọc bằng array_filter (lỗi thật đã gặp).
        $recorded = Http::recorded()->all();
        $visionRequests = array_values(array_filter($recorded, fn ($pair) => str_contains($pair[0]->url(), 'gw-vision.example')));
        $this->assertNotEmpty($visionRequests, 'Không có lời gọi model đọc ảnh.');
        // Không so 'data:image/' (dấu / bị escape thành \/ trong JSON) — so phần không thể nhầm.
        $this->assertStringContainsString('base64,', (string) $visionRequests[0][0]->body(), 'Ảnh không được gửi kèm.');

        $reasonRequests = array_values(array_filter($recorded, fn ($pair) => str_contains($pair[0]->url(), 'gw-reason.example')));
        $this->assertNotEmpty($reasonRequests);
        $this->assertStringContainsString('linen thô', $this->readableBody($reasonRequests[0][0]->body()), 'Mô tả ảnh phải vào prompt của brief.');
    }

    /** Không có model đọc ảnh ⇒ BỎ QUA ảnh, brief vẫn chạy và nói rõ lý do (không im lặng). */
    public function test_without_a_vision_model_the_brief_still_runs(): void
    {
        $this->model(DesignAgentService::REASON_GROUP, 'gw-reason', 'reason-1');
        Http::fake(['gw-reason.example/*' => Http::response(['choices' => [['message' => ['content' => $this->briefJson()]]]], 200)]);

        $brief = app(DesignAgentService::class)->collectionBrief([
            'prompt' => 'đầm linen',
            'reference_images' => ['/khong-ton-tai.png'],
        ], $this->customer(), true, true);

        $this->assertFalse($brief['reference_style']['used']);
        $this->assertContains($brief['reference_style']['reason'], ['no_vision_model', 'images_unreadable']);
        $this->assertSame('ai-v1', $brief['engine'], 'Thiếu model đọc ảnh KHÔNG được làm hỏng brief.');
    }

    /** Chỉ nhận ảnh của chính hệ thống: host lạ bị từ chối (chống SSRF). */
    public function test_reference_images_must_be_local_or_same_host(): void
    {
        $this->actingAs($this->customer())->postJson('/api/design-agent/collection', [
            'prompt' => 'đầm linen',
            'reference_images' => ['https://host-la.example/anh.jpg'],
        ])->assertOk();  // hợp lệ về định dạng…

        // …nhưng KHÔNG được máy chủ đi lấy: service từ chối host lạ.
        $service = app(DesignAgentService::class);
        $ref = new \ReflectionMethod($service, 'imageDataUri');
        $ref->setAccessible(true);
        $this->assertNull($ref->invoke($service, 'https://host-la.example/anh.jpg'));
        $this->assertNull($ref->invoke($service, 'file:///etc/passwd'));
    }

    /** Quá 3 ảnh hoặc định dạng lạ ⇒ 422 (không nhận đầu vào lạ). */
    public function test_reference_image_input_is_validated(): void
    {
        $this->actingAs($this->customer())->postJson('/api/design-agent/collection', [
            'prompt' => 'đầm linen',
            'reference_images' => ['/a.png', '/b.png', '/c.png', '/d.png'],
        ])->assertStatus(422);

        $this->actingAs($this->customer())->postJson('/api/design-agent/collection', [
            'prompt' => 'đầm linen',
            'reference_images' => ['ftp://x/y.png'],
        ])->assertStatus(422);
    }
}
