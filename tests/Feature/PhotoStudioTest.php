<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [2026-09-22] STUDIO — hai endpoint của phòng chụp: catalog + dựng danh sách ảnh.
 * Cả hai đều TẤT ĐỊNH (không gọi model, không tốn credit) nên xem/sửa prompt thoải mái.
 */
class PhotoStudioTest extends TestCase
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

    public function test_guests_cannot_use_the_studio_endpoints(): void
    {
        $this->postJson('/api/studio/shoot/catalog')->assertUnauthorized();
        $this->postJson('/api/studio/shoot/plan', ['shots' => ['full-body']])->assertUnauthorized();
    }

    public function test_catalog_endpoint_returns_the_whole_set(): void
    {
        $response = $this->actingAs($this->customer())
            ->postJson('/api/studio/shoot/catalog')
            ->assertOk();

        $this->assertGreaterThanOrEqual(10, count($response->json('backdrops')));
        $this->assertGreaterThanOrEqual(6, count($response->json('shots')));
        $this->assertNotEmpty($response->json('lighting'));
        $this->assertNotEmpty($response->json('cameras'));
        $this->assertNotEmpty($response->json('poses'));
        $this->assertNotEmpty($response->json('styles'));
        $this->assertNotEmpty($response->json('ratios'));
        $this->assertNotEmpty($response->json('backdrops.0.light_name'));
    }

    public function test_plan_endpoint_builds_the_shot_list_without_calling_any_model(): void
    {
        $response = $this->actingAs($this->customer())
            ->postJson('/api/studio/shoot/plan', [
                'backdrop' => 'danang-beach',
                'camera' => 'mf-80',
                'pose' => 'walking',
                'style' => 'lookbook-season',
                'shots' => ['full-body', 'three-quarter', 'movement'],
                'collection' => 'Hè 2026 · Linen',
                'model_note' => 'nữ 25 tuổi, tóc dài đen',
                'image_count' => 2,
                'variants' => 1,
            ])
            ->assertOk();

        $response->assertJsonPath('total_shots', 3)
            ->assertJsonPath('setup.backdrop', 'danang-beach')
            ->assertJsonPath('setup.camera_name', 'Medium format f/8 — thương mại nét căng')
            ->assertJsonPath('setup.collection', 'Hè 2026 · Linen');

        $this->assertSame(3, $response->json('total_images'));
        $this->assertGreaterThan(0, $response->json('total_credits'));
        $this->assertStringContainsString('Hè 2026', (string) $response->json('shots.0.prompt'));
        $this->assertStringContainsString('nữ 25 tuổi', (string) $response->json('shots.0.prompt'));

        // Test không cấu hình model ảnh ⇒ phải NÓI THẬT là chạy ở chế độ demo.
        $this->assertFalse($response->json('image_ready'));
    }

    public function test_plan_endpoint_validates_input(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->postJson('/api/studio/shoot/plan', ['ratio' => '16:9'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ratio');

        $this->actingAs($user)
            ->postJson('/api/studio/shoot/plan', ['shots' => array_fill(0, 13, 'full-body')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shots');
    }

    public function test_module_switch_blocks_the_studio_endpoints(): void
    {
        $user = $this->customer();
        $plan = Plan::where('slug', 'pro')->firstOrFail();
        $plan->forceFill(['modules' => array_values(array_diff(ModuleRegistry::ids(), ['compose']))])->save();
        $user->forceFill(['plan_id' => $plan->id, 'plan_expires_at' => null])->save();

        $this->actingAs($user->fresh())
            ->postJson('/api/studio/shoot/catalog')
            ->assertForbidden()
            ->assertJsonPath('code', 'module_locked');
    }
}
