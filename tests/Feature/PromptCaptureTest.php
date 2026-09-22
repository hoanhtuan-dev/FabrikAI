<?php

namespace Tests\Feature;

use App\Ai\PromptCatalog;
use App\Models\PromptTemplate;
use App\Models\StudioApiKey;
use App\Models\StudioModel;
use App\Models\StudioProvider;
use App\Models\User;
use App\Models\WebSource;
use App\Services\DesignAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GHI NHẬN BẢN CHỈ DẪN MẶC ĐỊNH (2026-09-25).
 *
 * Yêu cầu của chủ dự án: "ghi prompt mặc định vào chỉ dẫn AI". Tab «Chỉ dẫn AI» phải có NỘI DUNG THẬT
 * để sửa, chứ không phải một ô trống bắt owner tự nghĩ ra câu lệnh dài hơn 3.000 ký tự.
 *
 * Bất biến khoá ở đây:
 *   (a) lệnh ghi nhận KHÔNG gọi nhà cung cấp nào (mọi thứ được giả lập) — chạy lại bao nhiêu lần cũng
 *       không tốn token, nên nó dùng được như một bước thiết lập;
 *   (b) ảnh chụp nằm ở PHIÊN BẢN 0 và KHÔNG BAO GIỜ được bật — resolver không bao giờ chọn nó, và nó
 *       không lẫn vào lịch sử phiên bản của owner;
 *   (c) nội dung ghi nhận là CÂU LỆNH THẬT đã dựng, không phải một bản chép tay trong tài liệu;
 *   (d) lượt chạy thật không cấu hình cũng tự ghi nhận — không phụ thuộc việc ai đó nhớ chạy lệnh.
 */
class PromptCaptureTest extends TestCase
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

    private function ready(): void
    {
        $this->model(DesignAgentService::REASON_GROUP, 'gw-reason', 'reason-1');
        WebSource::create([
            'slug' => 'nguon-thu', 'name' => 'Nguồn thử', 'url' => 'https://news.example/rss',
            'kind' => 'rss', 'enabled' => true, 'priority' => 1, 'max_items' => 8,
        ]);
    }

    // ── (a) + (b) + (c) ───────────────────────────────────────────────────

    public function test_capture_records_the_real_built_instruction_without_calling_any_provider(): void
    {
        $this->ready();

        $this->artisan('studio:prompt --capture')->assertExitCode(0);

        $radar = PromptCatalog::defaultBody('agent.radar.instruction');
        $this->assertNotNull($radar, 'Khoá radar phải được ghi nhận.');
        $this->assertGreaterThan(1000, mb_strlen((string) $radar), 'Chỉ dẫn radar thật dài hơn 1.000 ký tự.');
        $this->assertStringContainsString('ĐỊNH HƯỚNG', (string) $radar);
        $this->assertStringContainsString('trend_checks', (string) $radar);

        $brief = PromptCatalog::defaultBody('agent.collection_brief.instruction');
        $this->assertNotNull($brief, 'Khoá brief phải được ghi nhận.');
        $this->assertStringContainsString('CollectionBot', (string) $brief);

        $sample = PromptCatalog::defaultBody('agent.sample_prompt.instruction');
        $this->assertNotNull($sample, 'Khoá prompt mẫu phải được ghi nhận.');
    }

    public function test_baseline_is_never_active_and_stays_out_of_the_version_history(): void
    {
        $this->ready();
        $this->artisan('studio:prompt --capture')->assertExitCode(0);

        $row = PromptTemplate::where('key', 'agent.radar.instruction')
            ->where('version', PromptCatalog::BASELINE_VERSION)->first();

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_active, 'Ảnh chụp mặc định KHÔNG được bật — bật là nó thành chỉ dẫn của owner.');

        $state = PromptCatalog::state('agent.radar.instruction');
        $this->assertFalse($state['configured'], 'Có ảnh chụp KHÔNG có nghĩa là đã cấu hình.');
        $this->assertSame([], $state['versions'], 'Ảnh chụp không được lẫn vào lịch sử phiên bản.');

        // Resolver thật cũng phải bỏ qua nó.
        $this->assertSame('MẶC ĐỊNH', studio_prompt_template('agent.radar.instruction', [], 'MẶC ĐỊNH'));
    }

    public function test_capture_survives_a_cache_clear(): void
    {
        $this->ready();
        $this->artisan('studio:prompt --capture')->assertExitCode(0);
        $before = PromptCatalog::defaultBody('agent.radar.instruction');

        Cache::flush();
        $this->artisan('config:clear');

        $this->assertSame($before, PromptCatalog::defaultBody('agent.radar.instruction'),
            'Ảnh chụp phải nằm trong CSDL, không phải cache — cache bị xoá là mất đúng lúc cần nhất.');
    }

    // ── (d) ĐƯỜNG CHẠY THẬT CŨNG TỰ GHI NHẬN ────────────────────────────

    public function test_a_real_run_without_configuration_also_records_the_baseline(): void
    {
        $this->ready();
        $this->assertNull(PromptCatalog::defaultBody('agent.radar.instruction'));

        app(DesignAgentService::class)->radar(null, 'all', true);

        $this->assertNotNull(PromptCatalog::defaultBody('agent.radar.instruction'),
            'Chạy thật khi chưa cấu hình phải tự ghi nhận — không phụ thuộc việc nhớ chạy lệnh.');
    }

    // ── Ảnh chụp KHÔNG được cản trở việc đặt chỉ dẫn thật ────────────────

    public function test_saving_a_version_still_works_alongside_a_baseline(): void
    {
        $this->ready();
        $this->artisan('studio:prompt --capture')->assertExitCode(0);

        $admin = User::where('email', 'admin@fabrikai.shop')->firstOrFail();
        $this->actingAs($admin)
            ->postJson('/api/admin/prompts', ['key' => 'agent.radar.instruction', 'body' => 'CHỈ DẪN CỦA TÔI'])
            ->assertOk()
            ->assertJsonPath('prompt.active_version', 1);

        $state = PromptCatalog::state('agent.radar.instruction');
        $this->assertTrue($state['configured']);
        $this->assertSame('CHỈ DẪN CỦA TÔI', $state['active_body']);
        $this->assertNotNull($state['default_body'], 'Ảnh chụp mặc định vẫn còn để đối chiếu.');
        $this->assertSame(1, count($state['versions']));
    }
}
