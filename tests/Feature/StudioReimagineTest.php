<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reimagine (Render đa góc / Tạo lại ảnh): endpoint tạo generation pending và tôn trọng
 * model chỉnh sửa do người dùng chọn trên card Sửa ảnh.
 */
class StudioReimagineTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRel = 'test-src.png';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('email', 'admin@trillfa.com')->firstOrFail());

        // Fixture ảnh THẬT trên public disk — refgen giờ pre-validate ảnh tham chiếu
        // resolve được (422 nếu không), nên URL /storage/test-src.png phải tồn tại.
        $img = imagecreatetruecolor(32, 32);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 60));
        ob_start();
        imagepng($img);
        Storage::disk('public')->put($this->fixtureRel, (string) ob_get_clean());
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        Storage::disk('public')->delete($this->fixtureRel);
        parent::tearDown();
    }

    private function sourceUrl(): string
    {
        return '/storage/test-src.png';
    }

    public function test_reimagine_creates_pending_generation(): void
    {
        $res = $this->postJson('/api/reimagine', [
            'image' => $this->sourceUrl(),
            'prompt' => 'full-body front view',
            'similarity' => 85,
            'variants' => 1,
        ]);
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('items'));
        $this->assertNotEmpty($res->json('items.0.generation_id'));
    }

    public function test_render_da_goc_creates_one_generation_per_angle(): void
    {
        // Giả lập vòng lặp runMultiView: 4 lượt reimagine (variants=1, không process).
        $angles = ['front view', 'back view', 'side view', 'detail view'];
        foreach ($angles as $v) {
            $this->postJson('/api/reimagine', [
                'image' => $this->sourceUrl(),
                'prompt' => 'render this fashion product at a new camera angle — '.$v,
                'similarity' => 85,
                'variants' => 1,
            ])->assertStatus(200);
        }

        $count = Generation::where('user_id', auth()->id())
            ->where('prompt', 'like', '%new camera angle%')
            ->count();

        // 4 góc -> đúng 4 generation (không trùng lặp do xử lý nhiều lần).
        $this->assertSame(4, $count);
    }

    public function test_reimagine_honors_selected_edit_capable_model(): void
    {
        $res = $this->postJson('/api/reimagine', [
            'image' => $this->sourceUrl(),
            'prompt' => 'render at new angle',
            'similarity' => 85,
            'variants' => 1,
            'provider' => 'qwen',
            'model' => 'qwen-image-3.0-pro',
        ]);
        $res->assertStatus(200);
        $this->assertSame('qwen-image-3.0-pro', $res->json('items.0.model'));
        $this->assertSame('qwen', $res->json('items.0.provider'));
    }

    /**
     * RefGen (card "Ảnh mới từ ảnh mẫu" — i2i KHÔNG phải edit): tạo generation pending,
     * tôn trọng model sinh ảnh người dùng chọn (qwen-image-3.0-pro), mode='refgen' trong meta.
     */
    public function test_refgen_creates_pending_generation_with_mode(): void
    {
        $res = $this->postJson('/api/refgen', [
            'image' => $this->sourceUrl(),
            'prompt' => 'đổi sang nền studio tối',
            'similarity' => 70,
            'variants' => 1,
            'provider' => 'qwen',
            'model' => 'qwen-image-3.0-pro',
        ]);
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('items'));
        $id = $res->json('items.0.generation_id');
        $this->assertNotEmpty($id);
        $this->assertSame('qwen-image-3.0-pro', $res->json('items.0.model'));
        $this->assertSame('qwen', $res->json('items.0.provider'));

        $gen = Generation::find($id);
        $this->assertNotNull($gen);
        $this->assertSame('pending', $gen->status);
        $this->assertSame('refgen', $gen->meta['mode'] ?? null);
        $this->assertNotNull($gen->base_image);
    }

    public function test_refgen_requires_image(): void
    {
        $this->postJson('/api/refgen', ['prompt' => 'mô tả', 'similarity' => 70])->assertStatus(422);
    }

    /**
     * Ảnh tham chiếu không resolve được (file đã xóa, blob:/URL ngoài): endpoint trả 422
     * NGAY và KHÔNG tạo generation — trước đây tạo generation rồi job fail với
     * "Không tạo được ảnh mới từ ảnh tham chiếu." (lỗi production gen #3).
     */
    public function test_refgen_rejects_unresolvable_image(): void
    {
        $before = Generation::count();

        $res = $this->postJson('/api/refgen', [
            'image' => '/storage/studio/file-da-bi-xoa-xyz.png',
            'prompt' => 'mô tả',
            'similarity' => 70,
            'variants' => 1,
        ]);

        $res->assertStatus(422);
        $this->assertSame($before, Generation::count());
    }

    public function test_refgen_creates_one_generation_per_variant(): void
    {
        $res = $this->postJson('/api/refgen', [
            'image' => $this->sourceUrl(),
            'prompt' => 'giữ phong cách',
            'similarity' => 60,
            'variants' => 3,
            'provider' => 'qwen',
            'model' => 'qwen-image-3.0-pro',
        ]);
        $res->assertStatus(200);
        $this->assertCount(3, $res->json('items'));
    }

    public function test_refgen_accepts_blank_prompt(): void
    {
        $res = $this->postJson('/api/refgen', [
            'image' => $this->sourceUrl(),
            'prompt' => '',
            'similarity' => 70,
            'variants' => 1,
            'provider' => 'qwen',
            'model' => 'qwen-image-3.0-pro',
        ]);
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('items.0.generation_id'));
    }
}
