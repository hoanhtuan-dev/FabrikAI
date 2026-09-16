<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature test: đồng bộ cấu trúc "Quản lý dự án" xuống Thư viện + Outputs (Phần M).
 *
 *  - GET /api/library/data?project_id=<id>  → chỉ ảnh của dự án đó, item kèm tên dự án
 *  - GET /api/library/data?project_id=none  → chỉ ảnh CHƯA gắn dự án
 *  - stats.project_linked_count                → số ảnh đã gắn dự án
 *  - GET /api/latest                        → item kèm project_id + project (tên)
 *  - GET /api/projects/{id}                 → generations kèm project_id + project
 */
class StudioLibraryProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public'); // cô lập quét file mồ côi khỏi storage thật
        $this->admin = User::where('email', 'admin@fabrikai.shop')->first();
        $this->project = Project::factory()->create(['user_id' => $this->admin->id, 'name' => 'BST Test']);
    }

    private function makeGen(array $attrs = []): Generation
    {
        return Generation::factory()->create(array_merge([
            'user_id' => $this->admin->id,
            'status' => 'completed',
            'type' => 'image',
            'media_url' => '/storage/studio/x.png',
        ], $attrs));
    }

    public function test_library_data_filters_by_project_id_and_exposes_project_name(): void
    {
        $in = $this->makeGen(['project_id' => $this->project->id]);
        $out = $this->makeGen(['project_id' => null]);
        $this->actingAs($this->admin);

        $res = $this->getJson('/api/library/data?project_id='.$this->project->id)->assertOk();
        $ids = array_column($res->json('items'), 'id');
        $this->assertContains($in->id, $ids);
        $this->assertNotContains($out->id, $ids);
        $item = collect($res->json('items'))->firstWhere('id', $in->id);
        $this->assertSame('BST Test', $item['project']);
    }

    public function test_library_data_project_id_none_returns_only_unattached(): void
    {
        $in = $this->makeGen(['project_id' => $this->project->id]);
        $out = $this->makeGen(['project_id' => null]);
        $this->actingAs($this->admin);

        $res = $this->getJson('/api/library/data?project_id=none')->assertOk();
        $ids = array_column($res->json('items'), 'id');
        $this->assertContains($out->id, $ids);
        $this->assertNotContains($in->id, $ids);
    }

    public function test_library_stats_include_project_linked_count(): void
    {
        $this->makeGen(['project_id' => $this->project->id]);
        $this->makeGen(['project_id' => $this->project->id]);
        $this->makeGen(['project_id' => null]);
        $this->actingAs($this->admin);

        $res = $this->getJson('/api/library/data')->assertOk();
        $this->assertSame(2, (int) $res->json('stats.project_linked_count'));
    }

    public function test_latest_exposes_project_id_and_name(): void
    {
        $gen = $this->makeGen(['project_id' => $this->project->id]);
        $this->actingAs($this->admin);

        $res = $this->getJson('/api/latest')->assertOk();
        $item = collect($res->json('items'))->firstWhere('id', $gen->id);
        $this->assertNotNull($item);
        $this->assertSame($this->project->id, $item['project_id']);
        $this->assertSame('BST Test', $item['project']);
    }

    public function test_project_show_generations_include_project_fields(): void
    {
        $gen = $this->makeGen(['project_id' => $this->project->id]);
        $this->actingAs($this->admin);

        $res = $this->getJson('/api/projects/'.$this->project->id)->assertOk();
        $item = collect($res->json('generations'))->firstWhere('id', $gen->id);
        $this->assertSame($this->project->id, $item['project_id']);
        $this->assertSame('BST Test', $item['project']);
    }
}
