<?php

namespace Tests\Feature;

use App\Jobs\ReflectBrandMemoryJob;
use App\Models\BrandLearning;
use App\Models\Generation;
use App\Models\Project;
use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\User;
use App\Services\BrandLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TRÍ NHỚ DÀI HẠN GĐ2 — rút "bài học" từ quyết định duyệt/loại (ReasoningBank).
 *
 * Khoá bốn bất biến:
 *   (a) record() PHẢI đẩy job rút kinh nghiệm (không có job thì prompt thô không bao giờ thành bài học);
 *   (b) khi có model agent_reflect, job ghi lesson KHÁC prompt thô (đó là dấu hiệu đã KHÁI QUÁT);
 *   (c) khi KHÔNG có model, job bỏ qua nhẹ nhàng — lesson null, không ném, brief dùng prompt thô như cũ;
 *   (d) preferences() trả cả lessons bên cạnh approved/rejected (tương thích ngược với nơi đọc cũ).
 */
class ReflectBrandMemoryTest extends TestCase
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

    private function shot(User $u, string $prompt): Generation
    {
        $p = $u->projects()->create(['name' => 'Thu Đông 2026']);

        return Generation::create([
            'user_id' => $u->id,
            'project_id' => $p->id,
            'type' => 'image',
            'status' => 'completed',
            'prompt' => $prompt,
            'media_url' => '/storage/studio/t/a.jpg',
            'credits_cost' => 1,
            'shot_state' => 'campaign_ready',
        ]);
    }

    /** Gán vai agent_reflect vào DeepSeek (đúng cách Cài đặt → Nhóm công việc). */
    private function configureReflectModel(string $modelId = 'deepseek-chat'): void
    {
        StudioModel::create([
            'group' => 'agent_reflect', 'name' => 'DeepSeek '.$modelId, 'provider' => 'deepseek',
            'model_id' => $modelId, 'api_key_ref' => 'deepseek', 'priority' => 9, 'enabled' => true,
        ]);
        StudioApiKey::create([
            'provider' => 'deepseek', 'label' => 'deepseek', 'value' => 'sk-deepseek-test',
            'kind' => null, 'scopes' => ['*'], 'priority' => 5, 'enabled' => true,
        ]);
    }

    public function test_record_dispatches_the_reflect_job(): void
    {
        Queue::fake();

        $u = $this->customer();
        $shot = $this->shot($u, 'đầm linen trắng ngà dáng suông');

        app(BrandLearningService::class)->record($shot, BrandLearning::DECISION_APPROVED);

        Queue::assertPushed(ReflectBrandMemoryJob::class, fn ($job) => $job->learningId > 0);
    }

    public function test_reflect_writes_a_lesson_distinct_from_the_raw_prompt(): void
    {
        $this->configureReflectModel();
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"lesson": "Shop chuộng linen trắng ngà, dáng suông; tránh bóng hoạ tiết to."}']]],
            ], 200),
        ]);

        $u = $this->customer();
        $shot = $this->shot($u, 'đầm linen trắng ngà dáng suông');

        // sync queue (phpunit.xml) ⇒ job chạy ngay trong record().
        app(BrandLearningService::class)->record($shot, BrandLearning::DECISION_APPROVED);

        $row = BrandLearning::where('generation_id', $shot->id)->first();
        $this->assertNotNull($row, 'Phải ghi được dòng trí nhớ.');
        $this->assertNotNull($row->lesson, 'Có model thì phải rút được bài học.');
        $this->assertNotSame($row->prompt, $row->lesson, 'Bài học phải khác prompt thô (đã khái quát).');
        $this->assertStringContainsString('linen trắng ngà', $row->lesson);
    }

    public function test_reflect_noops_gracefully_when_no_model_is_configured(): void
    {
        // Không cấu hình agent_reflect: job phải bỏ qua, không ném, lesson vẫn null.
        $u = $this->customer();
        $shot = $this->shot($u, 'đầm linen trắng ngà dáng suông');

        app(BrandLearningService::class)->record($shot, BrandLearning::DECISION_APPROVED);

        $row = BrandLearning::where('generation_id', $shot->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->lesson);
    }

    public function test_preferences_includes_lessons_alongside_raw_prompts(): void
    {
        $u = $this->customer();
        $shot = $this->shot($u, 'đầm linen trắng ngà');
        app(BrandLearningService::class)->record($shot, BrandLearning::DECISION_APPROVED);
        BrandLearning::where('generation_id', $shot->id)->update(['lesson' => 'Thích linen trắng ngà, dáng suông']);

        $prefs = app(BrandLearningService::class)->preferences($u);

        $this->assertContains('đầm linen trắng ngà', $prefs['approved']);
        $this->assertContains('Thích linen trắng ngà, dáng suông', $prefs['lessons']['approved']);
    }
}
