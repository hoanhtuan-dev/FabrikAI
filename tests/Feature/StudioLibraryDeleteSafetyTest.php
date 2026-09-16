<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Services\StudioLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An toàn của các đường XOÁ trong thư viện Studio.
 *
 * Đây là lớp nguy hiểm: xoá nhầm ở đây là mất dữ liệu thật. Trước file này, các endpoint
 * /api/library/bulk-delete, /api/library/cleanup, /api/uploads/delete chỉ được test phần VALIDATE
 * (S8: scope lạ -> 422), chưa test HÀNH VI xoá.
 */
class StudioLibraryDeleteSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    private function svc(): StudioLibraryService
    {
        return app(StudioLibraryService::class);
    }

    // ── TRAVERSAL trong đường xoá file tải lên ───────────────────────────

    public function test_upload_delete_never_escapes_storage_root(): void
    {
        // QUAN TRỌNG: phải tạo thư mục studio/ref/ trước. Nếu không, is_file() trả false vì thư mục
        // trung gian không tồn tại -> code bỏ qua và test PASS VÌ LÝ DO SAI (lần đầu tôi viết thiếu
        // bước này và suýt kết luận "không có lỗ hổng"). Trong production studio/ref/ LUÔN tồn tại.
        Storage::disk('public')->put('studio/ref/an-existing-upload.png', 'x');

        $root = rtrim(str_replace('\\', '/', Storage::disk('public')->path('')), '/');
        // Sentinel nằm NGOÀI root của disk public (một cấp trên) — không bao giờ được bị xoá.
        $sentinel = dirname($root).'/zz-sentinel-outside-root.txt';
        file_put_contents($sentinel, 'must survive');

        $this->assertFileExists($sentinel);
        $this->assertTrue(is_file(Storage::disk('public')->path('studio/ref/../../../'.basename($sentinel))),
            'Tiền đề của test: đường dẫn traversal PHẢI resolve được ra file sentinel (nếu không thì test vô nghĩa).');

        try {
            // 'studio/ref/' là tiền tố HỢP LỆ theo normalizeUploadRel, nhưng phần sau leo ra ngoài root.
            $this->svc()->deleteUploadedFiles(['studio/ref/../../../'.basename($sentinel)]);
        } finally {
            $exists = file_exists($sentinel);
            @unlink($sentinel);
        }

        $this->assertTrue($exists, 'Xoá file tải lên KHÔNG được phép leo ra ngoài root của disk public.');
    }

    public function test_upload_delete_rejects_dotdot_segments(): void
    {
        $rel = 'studio/ref/../../../../.env';
        $this->svc()->deleteUploadedFiles([$rel]);

        // Không có gì bị xoá ngoài ý muốn; và file .env của dự án vẫn còn.
        $this->assertFileExists(base_path('.env'));
    }

    public function test_upload_delete_refuses_paths_outside_allowed_dirs(): void
    {
        Storage::disk('public')->put('studio/other/keep.png', 'x');

        // Chỉ studio/ref/ và studio/assets/ mới được phép -> đường dẫn khác bị bỏ qua.
        $this->svc()->deleteUploadedFiles(['studio/other/keep.png']);

        Storage::disk('public')->assertExists('studio/other/keep.png');
    }

    public function test_upload_delete_skips_files_still_referenced(): void
    {
        Storage::disk('public')->put('studio/ref/in-use.png', 'x');
        $user = User::first() ?? User::factory()->create();
        $user->generations()->create([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'x',
            'base_image' => '/storage/studio/ref/in-use.png',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 0,
        ]);

        $this->svc()->deleteUploadedFiles(['studio/ref/in-use.png']);

        Storage::disk('public')->assertExists('studio/ref/in-use.png');
    }

    // ── bulkDelete phải theo chủ sở hữu ──────────────────────────────────

    public function test_bulk_delete_is_owner_scoped(): void
    {
        $me = User::first() ?? User::factory()->create();
        $other = User::factory()->create();

        $mine = $me->generations()->create(['type' => 'image', 'status' => 'completed', 'prompt' => 'mine', 'provider' => 'q', 'model' => 'm', 'credits_cost' => 0]);
        $theirs = $other->generations()->create(['type' => 'image', 'status' => 'completed', 'prompt' => 'theirs', 'provider' => 'q', 'model' => 'm', 'credits_cost' => 0]);

        $res = $this->svc()->bulkDelete($me, [$mine->id, $theirs->id]);

        $this->assertSame(1, $res['deleted'], 'Chỉ được xoá generation của chính mình.');
        $this->assertDatabaseMissing('generations', ['id' => $mine->id]);
        $this->assertDatabaseHas('generations', ['id' => $theirs->id]);
    }

    public function test_cleanup_junk_only_touches_own_generations(): void
    {
        $me = User::first() ?? User::factory()->create();
        $other = User::factory()->create();

        $myJunk = $me->generations()->create(['type' => 'image', 'status' => 'failed', 'prompt' => 'mine', 'provider' => 'q', 'model' => 'm', 'credits_cost' => 0]);
        $theirJunk = $other->generations()->create(['type' => 'image', 'status' => 'failed', 'prompt' => 'theirs', 'provider' => 'q', 'model' => 'm', 'credits_cost' => 0]);

        $this->svc()->cleanup($me, 'junk');

        $this->assertDatabaseMissing('generations', ['id' => $myJunk->id]);
        $this->assertDatabaseHas('generations', ['id' => $theirJunk->id], );
    }

    // ── cleanup('orphans') chỉ xoá file KHÔNG ai tham chiếu ──────────────

    public function test_orphan_cleanup_keeps_files_referenced_by_any_user(): void
    {
        $other = User::factory()->create();

        Storage::disk('public')->put('studio/other-user-result.png', 'x');
        Storage::disk('public')->put('studio/nobody-uses-this.png', 'y');

        $other->generations()->create([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'x',
            'media_url' => '/storage/studio/other-user-result.png',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 0,
        ]);

        $this->svc()->cleanup($other, 'orphans');

        Storage::disk('public')->assertExists('studio/other-user-result.png');
    }
}
