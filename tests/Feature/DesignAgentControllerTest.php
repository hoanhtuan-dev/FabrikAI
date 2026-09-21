<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesignAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function customerWithoutModule(string $moduleId): User
    {
        $user = $this->customer();
        $plan = Plan::where('slug', 'pro')->firstOrFail();
        $plan->forceFill([
            'modules' => array_values(array_diff(ModuleRegistry::ids(), [$moduleId])),
        ])->save();
        $user->forceFill(['plan_id' => $plan->id, 'plan_expires_at' => null])->save();

        return $user->fresh();
    }

    public function test_guests_cannot_use_design_agents(): void
    {
        $this->postJson('/api/design-agent/radar')->assertUnauthorized();
        $this->postJson('/api/design-agent/collection', [
            'prompt' => 'Bộ sưu tập công sở',
        ])->assertUnauthorized();
    }

    public function test_radar_returns_truthful_demo_schema(): void
    {
        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/radar', ['region' => 'hcm'])
            ->assertOk();

        $response->assertJsonPath('agent', 'TrendRadar')
            ->assertJsonPath('source_mode', 'demo')
            ->assertJsonPath('region', 'hcm')
            ->assertJsonPath('summary.images_analyzed_monthly', 0);
        $this->assertCount(8, $response->json('trends'));
        $this->assertSame('demo', $response->json('trends.0.evidence_mode'));
        // BÁO CÁO NGUỒN nay nói ĐÚNG cái đang dùng: chưa khai nguồn nào ⇒ chỉ còn MỘT dòng nói rõ các kênh
        // chưa kết nối (trước đây là 5 dòng tĩnh, 4 dòng ghi "Dữ liệu mẫu" gây hiểu sai khi đã nối nguồn thật).
        $sources = collect($response->json('sources'));
        $this->assertSame(1, $sources->count());
        $this->assertSame('not_connected', $sources->firstWhere('id', 'not_connected')['status']);
        $this->assertStringContainsString('Shopee', (string) $sources->firstWhere('id', 'not_connected')['channels']);
    }

    public function test_collection_returns_complete_brief_without_creating_project(): void
    {
        $before = Project::count();
        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', [
                'prompt' => 'Bộ sưu tập công sở mùa hè cho nữ văn phòng, ưu tiên linen thoáng và màu pastel dịu.',
                'region' => 'hanoi',
                'trend_ids' => ['soft-pastel', 'linen-breeze'],
                'size_distribution' => ['s' => 10, 'm' => 20],
                'unexpected_field' => 'ignored',
            ])
            ->assertOk();

        $response
            ->assertJsonPath('agent', 'CollectionBot')
            ->assertJsonPath('engine', 'rule-based-v1')
            ->assertJsonPath('moodboard.count', 24)
            ->assertJsonPath('project_payload.name', 'Bộ sưu tập Công Sở Hè Hà Nội')
            ->assertJsonPath('canvas.ratio', '4:5')
            ->assertJsonPath('canvas.variant_count', 2);
        $this->assertNotEmpty($response->json('input_signature'));
        $this->assertCount(2, $response->json('selected_trends'));
        $this->assertSame(['S', 'M', 'L', 'XL'], array_column($response->json('size_distribution'), 'size'));
        $this->assertSame($before, Project::count(), 'project_payload chỉ là dữ liệu gợi ý, không tạo dự án.');
    }

    public function test_project_payload_is_compatible_with_project_creation(): void
    {
        $user = $this->customer();
        $brief = $this->actingAs($user)
            ->postJson('/api/design-agent/collection', ['prompt' => 'Bộ sưu tập linen pastel'])
            ->assertOk()
            ->json('project_payload');

        $created = $this->actingAs($user)
            ->postJson('/api/projects/new', $brief)
            ->assertCreated();

        $created->assertJsonPath('name', $brief['name']);
        $this->assertDatabaseHas('projects', [
            'user_id' => $user->id,
            'name' => $brief['name'],
        ]);
    }

    public function test_collection_rejects_invalid_or_ambiguous_inputs(): void
    {
        $base = ['prompt' => 'Bộ sưu tập công sở'];

        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', ['prompt' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prompt');

        // Id HỢP LỆ về hình thức nhưng KHÔNG còn trong danh mục ⇒ BỎ QUA và báo lại, KHÔNG chặn request.
        //
        // [Đổi hành vi 2026-09-21 — lỗi thật L-WJH6] Danh mục hướng không còn cố định (hướng sinh từ tin
        // thật, và từ 2026-09-21 còn phụ thuộc câu hỏi model tự tra), nên giữa lúc mở radar và lúc bấm tạo
        // brief một hướng có thể đã biến mất. Chặn cả request vì thế là bắt người dùng mất hết công nhập
        // liệu mà không có cách nào tự sửa.
        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', $base + ['trend_ids' => ['not-a-real-trend']])
            ->assertOk()
            ->assertJsonPath('dropped_trend_ids.0', 'not-a-real-trend');

        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', $base + ['trend_ids' => ['soft-pastel', 'soft-pastel']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('trend_ids');

        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', $base + ['size_distribution' => ['XXL/2' => 10]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('size_distribution');

        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', $base + ['region' => 'unknown'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('region');
    }

    public function test_internal_brand_signal_is_scoped_to_the_current_user(): void
    {
        $user = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Generation::factory()->create([
            'user_id' => $user->id,
            'prompt' => 'pastel linen office collection',
            'shot_state' => Generation::SHOT_APPROVED,
        ]);
        Generation::factory()->create([
            'user_id' => $other->id,
            'prompt' => 'red silk evening gown',
            'shot_state' => Generation::SHOT_APPROVED,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/design-agent/radar')
            ->assertOk();

        $this->assertSame(1, $response->json('internal_brand_signal.generation_count'));
        $this->assertStringContainsString('pastel', $response->json('internal_brand_signal.narrative'));
        $this->assertStringNotContainsString('silk', $response->json('internal_brand_signal.narrative'));
    }

    public function test_module_switch_blocks_the_agent_endpoint(): void
    {
        $user = $this->customerWithoutModule('trend_radar');

        $this->actingAs($user)
            ->postJson('/api/design-agent/radar')
            ->assertForbidden()
            ->assertJsonPath('code', 'module_locked')
            ->assertJsonPath('module', 'trend_radar');
    }
}
