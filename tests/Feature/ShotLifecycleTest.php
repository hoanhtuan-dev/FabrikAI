<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Đợt 1.1 — 2026-09-17] VÒNG ĐỜI SHOT: idea → drafted → selected → fitted → campaign_ready → approved (+ rejected).
 *
 * Bất biến được khoá:
 *   (a) chuyển trạng thái PHẢI theo whitelist — nhảy cóc idea→approved bị chặn 422;
 *   (b) chỉ owner/admin được chuyển;
 *   (c) shot_state được trả ra show()/latest() để UI đổi xem → chọn.
 */
class ShotLifecycleTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function generation(User $u, array $attrs = []): Generation
    {
        return $u->generations()->create(array_merge([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'x',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 1,
            'media_url' => '/storage/studio/shot.jpg',
        ], $attrs));
    }

    public function test_shot_state_walks_the_full_whitelist_chain(): void
    {
        $u = $this->customer();
        $this->actingAs($u);
        $g = $this->generation($u);

        foreach (['selected', 'fitted', 'campaign_ready', 'approved'] as $to) {
            $this->postJson('/api/generations/'.$g->id.'/shot-state', ['state' => $to])
                ->assertOk()
                ->assertJsonPath('shot_state', $to);
            $this->assertSame($to, $g->fresh()->shot_state);
        }
    }

    public function test_rejected_is_reachable_and_rework_returns_to_drafted(): void
    {
        $u = $this->customer();
        $this->actingAs($u);
        $g = $this->generation($u);

        $this->postJson('/api/generations/'.$g->id.'/shot-state', ['state' => 'rejected'])->assertOk();
        $this->assertSame('rejected', $g->fresh()->shot_state);

        $this->postJson('/api/generations/'.$g->id.'/shot-state', ['state' => 'drafted'])->assertOk();
        $this->assertSame('drafted', $g->fresh()->shot_state);
    }

    public function test_shot_state_rejects_invalid_jumps(): void
    {
        $u = $this->customer();
        $this->actingAs($u);
        $g = $this->generation($u);

        $this->postJson('/api/generations/'.$g->id.'/shot-state', ['state' => 'approved'])
            ->assertStatus(422);

        $this->postJson('/api/generations/'.$g->id.'/shot-state', ['state' => 'not_a_state'])
            ->assertStatus(422);

        $this->assertSame('drafted', $g->fresh()->shot_state);
    }

    public function test_only_owner_or_admin_can_transition(): void
    {
        $owner = $this->customer();
        $g = $this->generation($owner);

        $other = User::factory()->create();
        $this->actingAs($other)
            ->postJson('/api/generations/'.$g->id.'/shot-state', ['state' => 'selected'])
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->postJson('/api/generations/'.$g->id.'/shot-state', ['state' => 'selected'])
            ->assertOk();
        $this->assertSame('selected', $g->fresh()->shot_state);
    }

    public function test_transition_persists_note_selected_and_sort(): void
    {
        $u = $this->customer();
        $this->actingAs($u);
        $g = $this->generation($u);

        $this->postJson('/api/generations/'.$g->id.'/shot-state', [
            'state' => 'selected',
            'note' => 'Dung form, giu lai cho bo Shopee.',
            'is_selected' => true,
            'sort' => 3,
        ])->assertOk()
          ->assertJsonPath('note', 'Dung form, giu lai cho bo Shopee.')
          ->assertJsonPath('is_selected', true)
          ->assertJsonPath('sort', 3);

        $fresh = $g->fresh();
        $this->assertSame('selected', $fresh->shot_state);
        $this->assertSame('Dung form, giu lai cho bo Shopee.', $fresh->note);
        $this->assertTrue((bool) $fresh->is_selected);
        $this->assertSame(3, (int) $fresh->sort);
    }

    public function test_shot_state_is_exposed_in_show_and_latest(): void
    {
        $u = $this->customer();
        $this->actingAs($u);
        $g = $this->generation($u, ['shot_state' => 'fitted', 'is_selected' => true, 'sort' => 2]);

        $this->getJson('/api/generations/'.$g->id)
            ->assertOk()
            ->assertJsonPath('shot_state', 'fitted')
            ->assertJsonPath('is_selected', true)
            ->assertJsonPath('sort', 2);

        $row = collect($this->getJson('/api/latest')->assertOk()->json('items'))->firstWhere('id', $g->id);
        $this->assertNotNull($row, 'generation phai co trong /api/latest.');
        $this->assertSame('fitted', $row['shot_state']);
        $this->assertTrue((bool) $row['is_selected']);
    }
}
