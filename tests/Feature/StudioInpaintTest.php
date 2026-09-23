<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ✏️ Sửa ảnh (Inpaint): endpoint tạo generation pending + poll trả về terminal status.
 */
class StudioInpaintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('email', 'admin@fabrikai.shop')->firstOrFail());
    }

    private function makeSourceGen(): Generation
    {
        return auth()->user()->generations()->create([
            'type' => 'image', 'status' => 'completed',
            'prompt' => 'nguồn', 'model' => 'flux', 'provider' => 'flux',
            'media_url' => '/storage/test-src.png', 'credits_cost' => 1,
        ]);
    }

    public function test_inpaint_creates_pending_generation(): void
    {
        $src = $this->makeSourceGen();
        $res = $this->postJson('/api/generations/'.$src->id.'/inpaint', ['prompt' => 'đổi màu áo thành đỏ']);
        $res->assertStatus(200);
        $this->assertEquals('pending', $res->json('status'));
        $this->assertNotEmpty($res->json('generation_id'));
        $this->assertDatabaseHas('generations', [
            'id' => $res->json('generation_id'),
            'type' => 'image',
            'provider' => 'qwen',
        ]);

        // [2026-09-26] GIÁ THEO MODEL, không còn "1 ảnh = 1 credit". Sửa ảnh là đường ĐẮT NHẤT:
        // model edit tốn nhiều credit hơn tạo ảnh (giá vốn 1.035–1.170 ₫ so với 78 ₫).
        // Khẳng định con số bằng CHÍNH bảng giá, không viết cứng — bảng giá đổi thì test vẫn đúng
        // chừng nào giá vẫn được suy từ giá vốn.
        $gen = Generation::findOrFail($res->json('generation_id'));
        $expected = studio_credit_cost_for('image', $gen->provider, $gen->model, $gen->resolution, $gen->ratio, $gen->user);
        $this->assertSame($expected, (int) $gen->credits_cost, 'Số credit phải khớp bảng giá theo model.');
        $this->assertGreaterThanOrEqual(1, (int) $gen->credits_cost);
    }

    public function test_inpaint_requires_prompt(): void
    {
        $src = $this->makeSourceGen();
        $this->postJson('/api/generations/'.$src->id.'/inpaint', ['prompt' => ''])->assertStatus(422);
    }

    public function test_inpaint_denies_other_users_generation(): void
    {
        $other = User::factory()->create();
        $gen = $other->generations()->create([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'x',
            'model' => 'flux', 'provider' => 'flux', 'credits_cost' => 0,
        ]);
        $this->postJson('/api/generations/'.$gen->id.'/inpaint', ['prompt' => 'test'])->assertStatus(403);
    }

    public function test_show_resolves_to_terminal_status(): void
    {
        $src = $this->makeSourceGen();
        $res = $this->postJson('/api/generations/'.$src->id.'/inpaint', ['prompt' => 'sửa nhẹ']);
        $id = $res->json('generation_id');
        // Poll endpoint must resolve the pending job (lazy worker) to a terminal status.
        $poll = $this->getJson('/api/generations/'.$id);
        $poll->assertStatus(200);
        $this->assertContains($poll->json('status'), ['completed', 'failed']);
        $this->assertNotNull($poll->json('media_url'));
    }

    public function test_inpaint_honors_explicit_edit_capable_model(): void
    {
        $src = $this->makeSourceGen();
        // Card Sửa ảnh chọn model sinh ảnh qwen-image-3.0-pro (edit-capable) → được tôn trọng,
        // không bị ép về model Qwen Edit cấu hình.
        $res = $this->postJson('/api/generations/'.$src->id.'/inpaint', [
            'prompt' => 'đổi màu áo thành đỏ',
            'provider' => 'qwen',
            'model' => 'qwen-image-3.0-pro',
        ]);
        $res->assertStatus(200);
        $this->assertSame('qwen-image-3.0-pro', $res->json('model'));
        $this->assertSame('qwen', $res->json('provider'));
        $this->assertDatabaseHas('generations', [
            'id' => $res->json('generation_id'),
            'type' => 'image',
            'provider' => 'qwen',
            'model' => 'qwen-image-3.0-pro',
        ]);
    }

    public function test_inpaint_ignores_non_edit_capable_model(): void
    {
        $src = $this->makeSourceGen();
        // qwen3.8-flash là model chat đa phương thức (đọc ảnh) — KHÔNG sinh/sửa ảnh,
        // nên bị bỏ qua và giữ model Qwen Edit cấu hình.
        $res = $this->postJson('/api/generations/'.$src->id.'/inpaint', [
            'prompt' => 'sửa nhẹ',
            'provider' => 'qwen',
            'model' => 'qwen3.8-flash',
        ]);
        $res->assertStatus(200);
        $this->assertSame('qwen-image-edit', $res->json('model'));
    }

    public function test_defaults_expose_edit_capable_models_for_inpaint_card(): void
    {
        config(['studio.image_provider' => 'qwen']);
        $res = $this->getJson('/api/defaults');
        $res->assertStatus(200);
        $models = collect($res->json('inpaint_models'));
        $this->assertTrue($models->isNotEmpty());
        // Mặc định đứng đầu: model Qwen Edit cấu hình.
        $this->assertTrue((bool) $models->first()['default']);
        $this->assertSame('qwen-image-edit', $models->first()['model']);
        // Model tạo ảnh 2D qwen-image-3.0-pro phải chọn được cho Sửa ảnh.
        $this->assertTrue($models->contains(fn ($o) => $o['model'] === 'qwen-image-3.0-pro'));
        // Model chat/vision không được phép chọn cho Sửa ảnh.
        $this->assertFalse($models->contains(fn ($o) => $o['model'] === 'qwen3.8-flash'));
    }
}

