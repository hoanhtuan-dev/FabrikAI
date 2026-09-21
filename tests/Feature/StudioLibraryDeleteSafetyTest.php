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
        // TIỀN ĐỀ: đường dẫn traversal PHẢI resolve được ra file sentinel — nếu không thì test vô nghĩa
        // (nó sẽ pass vì thư mục thiếu, chứ không phải vì guard hoạt động).
        //
        // [Nâng Laravel 13.26.1 → 13.32.0, 2026-09-22] KHÔNG dùng Storage::disk()->path() để kiểm tiền đề
        // nữa: Flysystem mới NÉM PathTraversalDetected khi đường dẫn chứa '..', nên chính phép kiểm tiền
        // đề lại là thứ ném lỗi (stack trace chỉ đúng dòng này) trong khi GUARD CỦA ỨNG DỤNG vẫn đúng.
        // Dùng hàm hệ thống tập tin thuần: nó chỉ resolve đường dẫn, không diễn giải an toàn hộ ta.
        $this->assertTrue(is_file($root.'/studio/ref/../../../'.basename($sentinel)),
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

    // ── Hai đường xoá còn lại cũng nhận đường dẫn: phải khoá cùng lớp bug ─
    //
    // Vòng 12 tìm ra traversal ở deleteUploadedFiles. Cùng LỚP bug đó đã được vá ở assetDestroy
    // nhưng KHÔNG được vá ở safeUnlink — tức mẫu này phân kỳ giữa các chỗ trong cùng codebase.
    // Vì vậy khoá luôn refImageDelete và assetDestroy, mỗi test đều có ASSERT TIỀN ĐỀ để không
    // thể pass vì lý do sai (bài học vòng 12: thiếu thư mục -> is_file false -> code không chạy).

    /**
     * Sentinel nằm trong storage/app (NGOÀI disk public = storage/app/public).
     * LƯU Ý: 2 test dưới KHÔNG dùng Storage::fake() vì code controller dùng storage_path('app/public')
     * trực tiếp — trộn với disk đã fake sẽ khiến đường dẫn không khớp (lần đầu tôi viết vậy và
     * chính ASSERT TIỀN ĐỀ đã bắt được). Chúng tôi tự tạo/ dọn thư mục thật.
     */
    private function sentinelPath(string $name): string
    {
        return storage_path('app/'.$name);
    }

    private function makeDirs(array $rels): void
    {
        foreach ($rels as $rel) {
            $dir = storage_path('app/public/'.$rel);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    public function test_ref_image_delete_cannot_escape_ref_dir(): void
    {
        $this->makeDirs(['studio/ref']);
        $sentinel = $this->sentinelPath('zz-sentinel-ref.png');
        file_put_contents($sentinel, 'must survive');

        // TIỀN ĐỀ — nếu không kiểm, test có thể pass vì thư mục thiếu (is_file false) chứ không
        // phải vì guard hoạt động. Đây đúng là bẫy đã dính ở vòng 12.
        $this->assertTrue(
            is_file(storage_path('app/public/studio/ref/../../../zz-sentinel-ref.png')),
            'Tiền đề: đường dẫn traversal phải resolve ra sentinel.'
        );

        try {
            app(\App\Http\Controllers\StudioController::class)
                ->refImageDelete(new \Illuminate\Http\Request(), '../../../zz-sentinel-ref.png');
        } finally {
            $exists = file_exists($sentinel);
            @unlink($sentinel);
        }

        $this->assertTrue($exists, 'refImageDelete KHÔNG được xoá file ngoài studio/ref/.');
    }

    public function test_asset_destroy_with_poisoned_path_cannot_escape_root(): void
    {
        $this->makeDirs(['studio/assets']);
        $sentinel = $this->sentinelPath('zz-sentinel-asset.png');
        file_put_contents($sentinel, 'must survive');
        $this->assertTrue(
            is_file(storage_path('app/public/studio/assets/../../../zz-sentinel-asset.png')),
            'Tiền đề: đường dẫn traversal phải resolve ra sentinel.'
        );

        // Dòng DB bị nhiễm (path chứa '..') — kịch bản mà containment phải chặn.
        $asset = \App\Models\StudioAsset::create([
            'type' => 'model', 'name' => 'poisoned', 'path' => '/storage/studio/assets/../../../zz-sentinel-asset.png', 'sort' => 1,
        ]);

        try {
            app(\App\Http\Controllers\StudioController::class)->assetDestroy($asset);
        } finally {
            $exists = file_exists($sentinel);
            @unlink($sentinel);
        }

        $this->assertTrue($exists, 'assetDestroy KHÔNG được xoá file ngoài storage root dù path trong DB bị nhiễm.');
        $this->assertDatabaseMissing('studio_assets', ['id' => $asset->id]);
    }

    public function test_serve_path_guard_rejects_traversal_before_thumb_dir_is_computed(): void
    {
        // studioImageThumb() tính $thumbDir = storage_path('app/public/studio/thumb/'.$size.'/'.dirname($path))
        // từ {path} của route (where('path','.*') nên CÓ THỂ chứa '/'). Guard studioServePath() phải
        // chặn TRƯỚC đó, nếu không dirname() sẽ đưa thư mục thumbnail ra ngoài studio/thumb.
        // Test gọi trực tiếp guard (không qua HTTP) vì HTTP client tự chuẩn hoá '..' trong URL.
        $ctl = app(\App\Http\Controllers\StudioController::class);
        $m = new \ReflectionMethod($ctl, 'studioServePath');
        $m->setAccessible(true);

        foreach ([
            'studio/../../.env',
            '../../.env',
            '.env',
            'studio/.htaccess',
            'studio/ref/../../../etc/passwd',
            'studio/shell.php',
        ] as $bad) {
            $this->assertNull($m->invoke($ctl, $bad), "studioServePath phải từ chối: $bad");
        }
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
