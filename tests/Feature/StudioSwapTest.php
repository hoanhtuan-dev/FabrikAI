<?php

namespace Tests\Feature;

use App\Models\StudioAsset;
use App\Services\VirtualTryOnService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioSwapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Bật tính năng swap (mặc định tắt ở production) để test các luồng swap.
        config(['studio.swap_enabled' => true]);
    }

    public function test_pick_model_pose_support_custom_assets(): void
    {
        $asset = StudioAsset::create(['type' => 'model', 'name' => 'Mặt riêng A', 'path' => '/storage/custom-face.png', 'sort' => 0]);
        $pose = StudioAsset::create(['type' => 'pose', 'name' => 'Dáng riêng B', 'path' => '/storage/custom-pose.png', 'sort' => 0]);

        $svc = app(VirtualTryOnService::class);

        // Custom assets are resolved by id (NOT silently replaced by catalog[0]).
        $picked = $svc->pickModel((string) $asset->id);
        $this->assertNotNull($picked);
        $this->assertSame('Mặt riêng A', $picked['name']);
        $this->assertSame('/storage/custom-face.png', $picked['image']);

        $pickedPose = $svc->pickPose((string) $pose->id);
        $this->assertNotNull($pickedPose);
        $this->assertSame('Dáng riêng B', $pickedPose['name']);
        $this->assertSame('/storage/custom-pose.png', $pickedPose['image']);

        // Seeded DB preset resolves (id like "fp1").
        $preset = \App\Models\FacePreset::first();
        $this->assertNotNull($preset);
        $pickedPreset = $svc->pickModel('fp'.$preset->id);
        $this->assertSame($preset->name, $pickedPreset['name'] ?? null);

        // Built-in preset + pose still resolve via fallback.
        $this->assertSame('vp01', $svc->pickModel('vp01')['id'] ?? null);
        $this->assertSame('pose01', $svc->pickPose('pose01')['id'] ?? null);
    }

    public function test_pick_returns_null_for_unknown_id(): void
    {
        $svc = app(VirtualTryOnService::class);
        $this->assertNull($svc->pickModel('fake-model-id'));
        $this->assertNull($svc->pickPose('fake-pose-id'));
    }

    public function test_swap_model_requires_both_face_and_pose(): void
    {
        $admin = User::where('email', 'admin@trillfa.com')->first();
        $this->actingAs($admin);

        // Missing pose -> 422 validation (P2: pose is required).
        $this->postJson('/api/swap-model', [
            'image' => '/storage/design.png', 'model_id' => 'model01', 'pose_id' => '',
        ])->assertStatus(422);
    }

    public function test_swap_model_keep_face_by_default_without_model(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = User::where('email', 'admin@trillfa.com')->first();
        $this->actingAs($admin);

        // Mặc định giữ nguyên khuôn mặt: model_id có thể bỏ trống, vẫn tạo generation + dispatch job.
        $res = $this->postJson('/api/swap-model', [
            'image' => '/storage/design.png', 'model_id' => '', 'pose_id' => 'pose01',
        ])->assertStatus(200)->assertJson(['status' => 'pending']);

        $gen = \App\Models\Generation::find($res->json('generation_id'));
        $this->assertNotNull($gen);
        $this->assertTrue((bool) ($gen->meta['swap'] ?? false));
        $this->assertFalse((bool) ($gen->meta['change_face'] ?? false));
        $this->assertSame('', (string) ($gen->meta['model_id'] ?? ''));
        $this->assertSame('pose01', (string) ($gen->meta['pose_id'] ?? ''));
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SwapModelJob::class);
    }

    public function test_swap_model_change_face_stores_meta_and_prompt(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = User::where('email', 'admin@trillfa.com')->first();
        $this->actingAs($admin);

        $res = $this->postJson('/api/swap-model', [
            'image' => '/storage/design.png', 'model_id' => 'vp01', 'pose_id' => 'pose01', 'change_face' => true,
        ])->assertStatus(200);
        $gen = \App\Models\Generation::find($res->json('generation_id'));
        $this->assertNotNull($gen);
        $this->assertTrue((bool) ($gen->meta['change_face'] ?? false));
        $this->assertSame('vp01', (string) ($gen->meta['model_id'] ?? ''));
        // Prompt hiển thị tên người mẫu (không phải marker giữ nguyên khuôn mặt).
        $this->assertStringNotContainsString('giữ nguyên khuôn mặt', (string) $gen->prompt);
        $this->assertStringContainsString('Nhẹ nhàng tự nhiên', (string) $gen->prompt);
    }

    public function test_swap_model_change_face_requires_model(): void
    {
        $admin = User::where('email', 'admin@trillfa.com')->first();
        $this->actingAs($admin);

        // Bật đổi khuôn mặt nhưng model_id trống -> 422 (không tìm thấy người mẫu).
        $this->postJson('/api/swap-model', [
            'image' => '/storage/design.png', 'model_id' => '', 'pose_id' => 'pose01', 'change_face' => true,
        ])->assertStatus(422);
    }
}
