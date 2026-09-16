<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Test AN TOÀN cho command `studio:clean-storage` — code XOÁ FILE mà trước đây không có test nào.
 *
 * Rủi ro: một lỗi ở đây xoá mất ảnh thật của người dùng. Bộ test này khoá các tính chất bắt buộc:
 *  1. Ảnh KẾT QUẢ (media_url) không bao giờ bị xoá, dù cũ.
 *  2. Ảnh nguồn/mask được generation tham chiếu (base_image/mask_image) cũng không bị xoá.
 *  3. Thư mục tài nguyên người dùng (studio/ref, studio/assets, ...) không bị đụng tới.
 *  4. File có tiền tố bảo vệ (background-*, ref/) không bị đụng tới.
 *  5. File MỚI hơn ngưỡng `--days` không bị xoá.
 *  6. File mồ côi + đã cũ THÌ bị xoá (command phải thật sự hoạt động).
 *  7. `--dry-run` không xoá gì.
 *  8. `--days` bị kẹp tối thiểu 1 ngày.
 */
class StudioCleanStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    /** Đặt file với mtime chỉ định (để mô phỏng file cũ/mới). */
    private function putFile(string $path, int $mtime): void
    {
        Storage::disk('public')->put($path, 'bytes');
        touch(Storage::disk('public')->path($path), $mtime);
    }

    private function generationWith(array $attrs): void
    {
        $user = User::first() ?? User::factory()->create();
        $user->generations()->create(array_merge([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'x',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 0,
        ], $attrs));
    }

    public function test_never_deletes_referenced_or_protected_files(): void
    {
        $old = now()->subDays(30)->timestamp;

        // 1) Ảnh KẾT QUẢ được tham chiếu — phải sống sót dù rất cũ.
        $this->putFile('studio/result-keep.png', $old);
        $this->generationWith(['media_url' => '/storage/studio/result-keep.png']);

        // 2) base_image / mask_image được tham chiếu — cũng phải sống sót.
        $this->putFile('studio/base-keep.png', $old);
        $this->putFile('studio/mask-keep.png', $old);
        $this->generationWith([
            'media_url' => '/storage/studio/other-result.png',
            'base_image' => '/storage/studio/base-keep.png',
            'mask_image' => '/storage/studio/mask-keep.png',
        ]);

        // 3) Thư mục tài nguyên người dùng.
        $this->putFile('studio/ref/upload-cu.png', $old);
        $this->putFile('studio/assets/khuon-mat-cu.png', $old);
        $this->putFile('studio/dang-nguoi-mau/x.png', $old);
        $this->putFile('studio/khuon-mat/y.png', $old);
        $this->putFile('studio/logo/z.png', $old);

        // 4) Tiền tố bảo vệ (so với basename): file tên bắt đầu bằng "background-".
        $this->putFile('studio/background-1.png', $old);

        // 5) File mồ côi ở GỐC studio/ — KHÔNG được bảo vệ, phải bị xoá. (Trước đây command có
        //    thêm tiền tố 'ref/' trong danh sách bảo vệ, nhưng nó so với basename() nên không bao
        //    giờ khớp — lớp bảo vệ chết. Việc bảo vệ studio/ref/ do $protectedDirs lo.)
        $this->putFile('studio/ref-abc.png', $old);

        $this->artisan('studio:clean-storage', ['--days' => 14])->assertExitCode(0);

        foreach ([
            'studio/result-keep.png',
            'studio/base-keep.png',
            'studio/mask-keep.png',
            'studio/ref/upload-cu.png',
            'studio/assets/khuon-mat-cu.png',
            'studio/dang-nguoi-mau/x.png',
            'studio/khuon-mat/y.png',
            'studio/logo/z.png',
            'studio/background-1.png',
        ] as $path) {
            Storage::disk('public')->assertExists($path);
        }

        Storage::disk('public')->assertMissing('studio/ref-abc.png');
    }

    public function test_deletes_only_old_orphans(): void
    {
        $old = now()->subDays(30)->timestamp;
        $recent = now()->subHours(2)->timestamp;

        $this->putFile('studio/orphan-old.png', $old);
        $this->putFile('studio/orphan-recent.png', $recent);
        $this->putFile('studio/keep-referenced.png', $old);
        $this->generationWith(['media_url' => '/storage/studio/keep-referenced.png']);

        $this->artisan('studio:clean-storage', ['--days' => 14])->assertExitCode(0);

        Storage::disk('public')->assertMissing('studio/orphan-old.png');   // mồ côi + cũ -> xoá
        Storage::disk('public')->assertExists('studio/orphan-recent.png'); // mồ côi nhưng MỚI -> giữ
        Storage::disk('public')->assertExists('studio/keep-referenced.png');
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $old = now()->subDays(30)->timestamp;
        $this->putFile('studio/orphan-old.png', $old);

        $this->artisan('studio:clean-storage', ['--days' => 14, '--dry-run' => true])->assertExitCode(0);

        Storage::disk('public')->assertExists('studio/orphan-old.png');
    }

    public function test_days_option_is_clamped_to_at_least_one(): void
    {
        // --days=0 bị kẹp thành 1: file 3 ngày tuổi vẫn bị coi là cũ -> xoá;
        // file 12 giờ tuổi vẫn nằm trong ngưỡng -> giữ.
        $threeDays = now()->subDays(3)->timestamp;
        $halfDay = now()->subHours(12)->timestamp;

        $this->putFile('studio/orphan-3d.png', $threeDays);
        $this->putFile('studio/orphan-12h.png', $halfDay);

        $this->artisan('studio:clean-storage', ['--days' => 0])->assertExitCode(0);

        Storage::disk('public')->assertMissing('studio/orphan-3d.png');
        Storage::disk('public')->assertExists('studio/orphan-12h.png');
    }

    public function test_reference_normalization_handles_alternate_url_forms(): void
    {
        // media_url có thể ở dạng /storage/... , storage/... hoặc /public_html/storage/...
        // — cả 3 phải được nhận ra là "đang được tham chiếu".
        $old = now()->subDays(30)->timestamp;
        $this->putFile('studio/a.png', $old);
        $this->putFile('studio/b.png', $old);
        $this->putFile('studio/c.png', $old);

        $this->generationWith(['media_url' => '/storage/studio/a.png']);
        $this->generationWith(['media_url' => 'storage/studio/b.png']);
        $this->generationWith(['media_url' => '/public_html/storage/studio/c.png']);

        $this->artisan('studio:clean-storage', ['--days' => 14])->assertExitCode(0);

        Storage::disk('public')->assertExists('studio/a.png');
        Storage::disk('public')->assertExists('studio/b.png');
        Storage::disk('public')->assertExists('studio/c.png');
    }
}
