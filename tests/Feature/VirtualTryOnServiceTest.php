<?php

namespace Tests\Feature;

use App\Models\StudioAsset;
use App\Services\VirtualTryOnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test cho VirtualTryOnService — phần CHỌN người mẫu/tư thế từ catalog + asset tuỳ chỉnh.
 *
 * ⚠️ Lịch sử: file này tên cũ là StudioSwapTest. Ngày 2026-09-17 card "Thay người mẫu" bị gỡ, kéo
 * theo swapModel() + SwapModelJob + executeSwapFromGeneration() ⇒ 4 test endpoint swap đã bị xoá.
 * Service VirtualTryOnService VẪN ĐƯỢC DÙNG (đường Fitting Room → /api/refgen chế độ "Thử đồ", và
 * swapEdit dùng cho remove-bg/remove-person) nên 2 test dưới đây được giữ lại và đổi tên file cho
 * đúng nội dung.
 */
class VirtualTryOnServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
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
}
