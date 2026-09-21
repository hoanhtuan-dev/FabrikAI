<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Models\User;
use App\Services\AiModelGateway;
use App\Services\GeminiService;
use App\Services\ImageAIService;
use App\Services\VideoAIService;
use App\Services\VirtualTryOnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [2026-09-22] "MỘT CỬA" cho mọi lời gọi model AI — kiểm tra sâu việc dùng model.
 *
 * Lỗi thật trước đây: rất nhiều đường tự chọn provider/model bằng hằng số hoặc setting rời
 * (GeminiService đọc prompt_provider; VideoAIService đọc studio_qwen_credentials('video');
 * ImageAIService Sửa ảnh chỉ biết setting qwen_edit_model; Fitting Room đọc studio_swap_model;
 * vision QA + QA thử đồ cứng qwen|dashscope; moderation/super-resolution cứng 3 slot key).
 * Hệ quả: đổi Model Registry / Nhóm công việc / Luồng ưu tiên / Custom Provider trong Cài đặt
 * KHÔNG tác động tới các đường đó — Settings hiển thị một đằng, gọi model một nẻo.
 *
 * Các test dưới đây KHOÁ hành vi mới: cấu hình đổi ⇒ đường gọi đổi theo.
 */
class AiModelGatewayTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $abs) {
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function model(string $group, string $provider, string $modelId, int $priority = 5): StudioModel
    {
        return StudioModel::create([
            'group' => $group, 'name' => $provider.' '.$modelId, 'provider' => $provider,
            'model_id' => $modelId, 'api_key_ref' => $provider, 'priority' => $priority, 'enabled' => true,
        ]);
    }

    private function key(string $provider, string $value): StudioApiKey
    {
        return StudioApiKey::create([
            'provider' => $provider, 'label' => $provider, 'value' => $value,
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
    }

    /** PNG thật trong storage/app/public — đường vision cần ảnh đọc được, không phải chuỗi rỗng. */
    private function png(string $name): string
    {
        $abs = storage_path('app/public/studio/'.$name);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        $im = imagecreatetruecolor(48, 48);
        imagepng($im, $abs);
        imagedestroy($im);
        $this->tempFiles[] = $abs;

        return '/storage/studio/'.$name;
    }


    /**
     * CUSTOM PROVIDER DÙNG LẠI SLOT KHOÁ ĐÃ CÓ phải chạy được.
     *
     * [LỖI THẬT — đo trên production 2026-09-21] Một route riêng của cùng nhà cung cấp (khác CÁCH GỌI, không
     * khác khoá) khai `api_key_ref` trỏ vào slot sẵn có. `studio_candidate_key()` chỉ tra khoá theo SLUG
     * của provider nên trả về rỗng ⇒ `AiModelGateway::candidates()` RỖNG ⇒ agent lặng lẽ rơi về nhóm khác
     * dù Cài đặt hiện "đã gán model". Test khoá cả hai đầu: khoá phải tra được, và candidate phải có mặt.
     */
    public function test_a_custom_provider_can_reuse_an_existing_key_slot(): void
    {
        StudioApiKey::create([
            'provider' => 'deepseek', 'label' => 'deepseek', 'value' => 'sk-goc',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
        StudioProvider::create([
            'slug' => 'deepseek_search', 'name' => 'DeepSeek — có công cụ tìm kiếm', 'protocol' => 'openai',
            'base_url' => 'https://api.deepseek.com', 'auth_style' => 'bearer',
            'api_key_ref' => 'deepseek', 'search_mode' => 'responses_web_search', 'search_param' => 'web_search',
            'priority' => 9, 'enabled' => true,
        ]);
        $this->model('agent_search', 'deepseek_search', 'deepseek-v4-pro');
        set_setting('studio_task_agent_search_model', 'deepseek_search:deepseek-v4-pro');

        $this->assertSame(['sk-goc'], studio_candidate_key(['provider' => 'deepseek_search', 'model' => 'deepseek-v4-pro'], 'agent_search'), 'Khoá phải tra được qua api_key_ref của Custom Provider.');

        $candidates = app(AiModelGateway::class)->candidates('agent_search');
        $this->assertCount(1, $candidates, 'Candidate phải CÓ MẶT — thiếu nó là agent rơi về nhóm khác trong im lặng.');
        $this->assertSame('deepseek_search', $candidates[0]['provider']);
        $this->assertSame('https://api.deepseek.com', $candidates[0]['base']);
        $this->assertSame('responses_web_search', $candidates[0]['search_mode']);
    }

    /**
     * CỜ TẮT SUY LUẬN CHỈ ĐƯỢC BỎ KHI PROVIDER TỪ CHỐI THAM SỐ — không phải với mọi lỗi.
     *
     * [LỖI THẬT — tìm ra 2026-09-21 khi rà lại đường tìm kiếm] Ba chỗ gọi `/chat/completions` đều bỏ cờ
     * `enable_thinking: false` với MỌI lỗi: gặp 429 (hết hạn mức — rất thường với gói Token Plan) hay 5xx là
     * lần gọi lại chạy KHÔNG có cờ ⇒ model bật lại suy luận dài, đốt ngân sách token, và có lượt trả về
     * NGUYÊN CHUỖI SUY NGHĨ thay vì JSON. Log production có 6 ca "không đọc được JSON" trong một buổi tối;
     * ca của khách thật bắt đầu bằng "We need to output JSON only. The user asks: …".
     *
     * Đo được trên chính khoá đang chạy: gửi cờ ⇒ phản hồi KHÔNG có `reasoning_content` và không tốn token
     * suy luận; không gửi ⇒ có `reasoning_content` và 325 token suy luận cho một câu ngắn.
     *
     * Bài này gọi THẲNG hàm gửi request (không đi qua máy móc chọn model) để đo đúng một việc: cờ có bị bỏ
     * hay không, và bỏ vì lý do gì.
     */
    public function test_the_thinking_off_flag_is_only_dropped_when_the_provider_rejects_it(): void
    {
        $body = ['model' => 'qwen3.8-flash', 'messages' => [['role' => 'user', 'content' => 'hi']], 'enable_thinking' => false];
        $url = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';

        // MỘT stub duy nhất: `Http::fake()` CỘNG DỒN stub chứ không thay thế, nên đăng ký stub thứ hai cho
        // cùng một URL là stub thứ nhất vẫn trả lời — bẫy đã làm bài test này "xanh" sai một lần.
        $sent = [];
        $mode = 'rate_limit';
        Http::fake([
            'dashscope-intl.aliyuncs.com/*' => function ($request) use (&$sent, &$mode) {
                $sent[] = json_decode($request->body(), true);

                return match ($mode) {
                    'rate_limit' => Http::response(['error' => ['message' => 'rate limited']], 429),
                    'reject_param' => count($sent) === 1
                        ? Http::response(['error' => ['message' => 'unknown parameter: enable_thinking']], 400)
                        : Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
                    default => Http::response(['error' => ['message' => 'bad request']], 400),
                };
            },
        ]);

        // (a) Lỗi KHÔNG liên quan tới cờ (429 hết hạn mức) ⇒ gửi ĐÚNG MỘT lần, cờ còn nguyên.
        $this->callProtected(AiModelGateway::class, 'postChat', [$url, 'sk-x', $body, true, 30]);

        $this->assertCount(1, $sent, 'Gặp 429 mà gọi lại không có cờ tắt suy luận = tự bật lại suy luận dài.');
        $this->assertFalse($sent[0]['enable_thinking'] ?? null, 'Lần gọi đầu phải có cờ.');

        // (b) Provider TỪ CHỐI THAM SỐ (400) ⇒ gọi lại, và lần gọi lại KHÔNG có cờ.
        $sent = [];
        $mode = 'reject_param';
        $resp = $this->callProtected(AiModelGateway::class, 'postChat', [$url, 'sk-x', $body, true, 30]);

        $this->assertCount(2, $sent, 'Provider từ chối tham số thì PHẢI gọi lại — tham số tuỳ chọn không được làm hỏng lời gọi.');
        $this->assertFalse($sent[0]['enable_thinking'] ?? null);
        $this->assertArrayNotHasKey('enable_thinking', $sent[1], 'Lần gọi lại phải KHÔNG có cờ.');
        $this->assertTrue($resp->successful());

        // (c) Không khai cờ thì đừng gọi lại lần nào — dù lỗi là 400.
        $sent = [];
        $mode = 'plain';
        $this->callProtected(AiModelGateway::class, 'postChat', [$url, 'sk-x', ['model' => 'qwen3.8-flash', 'messages' => []], false, 30]);

        $this->assertCount(1, $sent);
    }

    private function creativeJson(string $marker): string
    {
        return json_encode([
            'concept_en' => 'a silk midi dress',
            'image_prompt_en' => 'Editorial photo of a silk midi dress, '.$marker.'.',
            'video_prompt_en' => 'Catwalk video of the same silk midi dress, '.$marker.'.',
            'keywords' => ['silk', 'midi'],
            'mood' => 'luxury',
            'color_palette' => ['ivory'],
            'style_notes' => 'minimal',
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── 1. Nhóm 'prompt' (Giám đốc sáng tạo) ────────────────────────────────

    public function test_creative_director_uses_the_prompt_task_group(): void
    {
        $this->model('prompt', 'deepseek', 'deepseek-chat', 5);
        $this->key('deepseek', 'sk-deepseek-test');
        set_setting('studio_task_prompt_model', 'deepseek:deepseek-chat');

        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => $this->creativeJson('deepseek')]]]], 200),
        ]);

        $out = app(GeminiService::class)->generateCreativeDirector('đầm lụa ngọc trai', [], 6);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.deepseek.com/chat/completions'));
        $this->assertSame('deepseek', $out['provider'], 'Provider trả về phải là provider THẬT của nhóm prompt.');
        $this->assertStringContainsString('deepseek', (string) $out['image_prompt_en']);
    }

    public function test_custom_provider_is_used_when_it_is_the_prompt_default(): void
    {
        StudioProvider::create([
            'slug' => 'ckey', 'name' => 'CKEY', 'protocol' => 'openai',
            'base_url' => 'https://api.xah.io/v1', 'auth_style' => 'bearer',
            'api_key_ref' => 'ckey', 'priority' => 5, 'enabled' => true,
        ]);
        $this->model('prompt', 'ckey', 'custom-chat', 5);
        $this->key('ckey', 'ckey-secret');
        set_setting('studio_task_prompt_model', 'ckey:custom-chat');

        Http::fake([
            'api.xah.io/*' => Http::response(['choices' => [['message' => ['content' => $this->creativeJson('ckey')]]]], 200),
        ]);

        $out = app(GeminiService::class)->generateCreativeDirector('áo dệt kim', [], 6);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.xah.io/v1/chat/completions'));
        $this->assertSame('ckey', $out['provider']);
    }

    // ── 2. Nhóm 'translate' qua endpoint thật ───────────────────────────────

    public function test_translate_endpoint_follows_the_translate_group(): void
    {
        $this->model('translate', 'deepseek', 'deepseek-chat', 5);
        $this->key('deepseek', 'sk-deepseek-test');
        set_setting('studio_task_translate_model', 'deepseek:deepseek-chat');

        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => 'Editorial photo of a silk dress']]]], 200),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/translate', ['text' => 'ảnh đầm lụa', 'direction' => 'en'])
            ->assertOk()
            ->assertJsonPath('provider', 'deepseek');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.deepseek.com/chat/completions'));
    }

    // ── 3. Nhóm 'video' ─────────────────────────────────────────────────────

    public function test_video_render_submits_the_video_group_model(): void
    {
        $this->model('video', 'wan', 'wan2.7-i2v', 5);
        $this->key('dashscope', 'sk-dashscope-paygo-key');
        set_setting('studio_task_video_model', 'wan:wan2.7-i2v');

        Http::fake(['*' => Http::response(['message' => 'model not exist'], 400)]);

        try {
            app(VideoAIService::class)->render('catwalk prompt', '/samples/a.jpg', 'orbit', '720', 5);
            $this->fail('Provider từ chối model ⇒ phải ném lỗi, không được im lặng trả video demo.');
        } catch (\RuntimeException $e) {
            // đúng như mong đợi: lỗi được nêu ra
        }

        Http::assertSent(function ($r) {
            return str_contains($r->url(), 'video-generation/video-synthesis')
                && ($r->data()['model'] ?? null) === 'wan2.7-i2v';
        });
    }

    // ── 4. Nhóm 'edit' (Sửa ảnh / Inpaint) ──────────────────────────────────

    public function test_inpaint_chain_follows_the_edit_task_group(): void
    {
        $this->key('dashscope', 'sk-dashscope-paygo-key');
        set_setting('studio_qwen_edit_model', 'qwen-image-edit');       // setting legacy cũ
        set_setting('studio_task_edit_model', 'qwen:qwen-image-edit-max'); // default MỚI của nhóm edit

        $chain = $this->callProtected(ImageAIService::class, 'editModelChain', []);

        $this->assertSame('qwen-image-edit-max', $chain[0] ?? null,
            'Default của NHÓM edit phải đứng đầu chuỗi — trước đây hàm chỉ biết setting qwen_edit_model.');
    }

    public function test_inpaint_chain_includes_edit_models_from_the_registry(): void
    {
        $this->key('dashscope', 'sk-dashscope-paygo-key');
        set_setting('studio_qwen_edit_model', 'qwen-image-edit');
        $this->model('edit', 'qwen', 'qwen-image-edit-plus', 9);

        $chain = $this->callProtected(ImageAIService::class, 'editModelChain', []);

        $this->assertContains('qwen-image-edit-plus', $chain,
            'Model edit đăng ký trong Model Registry phải vào chuỗi thử của card Sửa ảnh.');
    }

    public function test_inpaint_chain_is_empty_without_any_usable_key(): void
    {
        set_setting('studio_qwen_edit_model', 'qwen-image-edit');

        $chain = $this->callProtected(ImageAIService::class, 'editModelChain', []);

        $this->assertSame([], $chain, 'Không có key nào dùng được ⇒ không thử model (giữ nguyên đường stub).');
    }

    // ── 5. Nhóm 'swap' (Fitting Room) ───────────────────────────────────────

    public function test_fitting_room_swap_model_follows_the_swap_task_group(): void
    {
        $this->key('dashscope', 'sk-dashscope-paygo-key');
        set_setting('studio_swap_model', 'qwen-image-edit');            // setting legacy cũ
        set_setting('studio_task_swap_model', 'qwen:qwen-image-edit-max'); // default MỚI của nhóm swap

        $model = $this->callProtected(VirtualTryOnService::class, 'swapModel', []);

        $this->assertSame('qwen-image-edit-max', $model,
            'Model thử đồ phải theo NHÓM swap — trước đây hàm gọi thẳng studio_swap_model().');
    }

    // ── 6. Nhóm 'vision' (vision QA) ────────────────────────────────────────

    public function test_swap_quality_qa_uses_the_vision_task_group(): void
    {
        $this->model('vision', 'deepseek', 'deepseek-chat', 5);
        $this->key('deepseek', 'sk-deepseek-test');
        set_setting('studio_task_vision_model', 'deepseek:deepseek-chat');

        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' =>
                '{"garment_preservation":9,"face_quality":8,"pose_accuracy":7,"overall_aesthetic":8}']]]], 200),
        ]);

        $design = $this->png('qa-design.png');
        $candidate = $this->png('qa-candidate.png');

        $scores = $this->callProtected(VirtualTryOnService::class, 'scoreCandidate', [$candidate, $design]);

        $this->assertSame(9, $scores['garment_preservation'] ?? null,
            'QA thử đồ phải chạy trên model của NHÓM vision (đây là DeepSeek), không cứng qwen-vl.');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.deepseek.com'));
    }

    public function test_vision_group_reports_no_candidates_without_keys(): void
    {
        $this->model('vision', 'qwen', 'qwen3.8-flash', 5);

        $this->assertFalse(app(AiModelGateway::class)->has('vision'),
            'Model trong registry nhưng KHÔNG có key enabled ⇒ nhóm coi như không khả dụng.');
    }

    // ── 7. Khóa cho dịch vụ DashScope nguyên bản (moderation / super-resolution) ──

    public function test_dashscope_native_services_take_the_key_from_the_configured_groups(): void
    {
        $this->model('edit', 'qwen', 'qwen-image-edit', 5);
        $this->key('qwen_edit', 'sk-qwen-edit-secret');

        $this->assertSame('sk-qwen-edit-secret', app(AiModelGateway::class)->dashscopeKey(),
            'Khóa DashScope phải lấy từ nhóm đang cấu hình, không chỉ 3 slot studio_api_key cứng.');
    }

    public function test_dashscope_native_services_return_null_without_any_key(): void
    {
        $this->assertNull(app(AiModelGateway::class)->dashscopeKey());
    }

    /**
     * Gọi method protected bằng reflection — các hàm chọn model là NỘI BỘ, không public API,
     * nhưng chính chúng là nơi từng bỏ qua cài đặt.
     */
    private function callProtected(string $class, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(app($class), $args);
    }
}
