<?php

namespace Tests\Feature;

use App\Services\ImageAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Đợt 0.4 — 2026-09-17] NÚT 1K/2K + TỈ LỆ TRUNG THỰC (4:5, 21:9...).
 *
 * Hai lỗi bị khoá ở đây (STUDIO_REVIEW_PLAN.md Đợt 0.4):
 *   (a) Nút 1K/2K vô tác dụng: sizeFor() nhận $resolution nhưng không dùng ⇒ 1K hay 2K
 *       đều ra cùng một bức ảnh.
 *   (b) Tỉ lệ 4:5 và 21:9 bị ÂM THẦM đổi thành 3:4 và 16:9 trong sizeFor().
 *
 * Nguyên tắc thiết kế (đã build trong app/Services/ImageAIService.php):
 *   · sizeFor() giữ nguyên cho PROVIDER (Qwen chỉ nhận một tập kích thước cố định).
 *   · normalizeOutputSize() — áp dụng CHO ĐƯỜNG TẠO ảnh — cắt giữa về ĐÚNG tỉ lệ đã chọn,
 *     rồi hạ cạnh dài về mốc 1K (1024) / 2K (2048).
 *   · KHÔNG BAO GIỜ phóng to: thà giao đúng độ phân giải provider tạo ra còn hơn nội suy
 *     lên rồi dán nhãn "2K" (đúng kiểu nói dối Đợt 0.3 vừa dẹp).
 *   · ĐƯỜNG SỬA ảnh KHÔNG được chạm tỉ lệ (phải giữ kích thước ẢNH GỐC).
 */
class OutputSizePolicyTest extends TestCase
{
    use RefreshDatabase;

    /** Đường dẫn tuyệt đối đã ghi, để dọn trong tearDown. */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $abs) {
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->written = [];
        parent::tearDown();
    }

    /** Ghi PNG THẬT vào storage/app/public/studio/, trả URL /storage/studio/... */
    private function putImage(int $w, int $h, string $name): string
    {
        $abs = storage_path('app/public/studio/'.$name);
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 20, 30, 45));
        imagefilledrectangle($im, (int) ($w * 0.1), (int) ($h * 0.1), (int) ($w * 0.9), (int) ($h * 0.9), imagecolorallocate($im, 210, 180, 120));
        imagepng($im, $abs);
        imagedestroy($im);
        $this->written[] = $abs;

        return '/storage/studio/'.$name;
    }

    /** Quy URL /storage/... về đường dẫn tuyệt đối bằng helper của app. */
    private function absOf(string $url): ?string
    {
        return studio_safe_public_file(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));
    }

    /** @return array{0:int,1:int} */
    private function dimsOf(?string $url): array
    {
        $this->assertNotNull($url, 'normalizeOutputSize phải trả về URL.');
        $abs = $this->absOf((string) $url);
        $this->assertNotNull($abs, 'Không đọc được ảnh đầu ra: '.$url);
        $info = @getimagesize($abs);
        $this->assertNotFalse($info, 'getimagesize thất bại: '.$url);

        return [(int) $info[0], (int) $info[1]];
    }

    private function ratio(int $w, int $h): float
    {
        return $w / $h;
    }

    // ── (a) nút 1K/2K có tác dụng ─────────────────────────────────────────

    public function test_1k_downsizes_a_large_16_9_to_the_1024_long_edge(): void
    {
        $service = app(ImageAIService::class);
        $out = $service->normalizeOutputSize($this->putImage(1664, 928, 'a-1664x928.png'), '1K', '16:9');

        [$w, $h] = $this->dimsOf($out);
        $this->assertSame(1024, $w, '1K ⇒ cạnh dài đúng 1024.');
        $this->assertSame(576, $h);
    }

    public function test_1k_and_2k_produce_different_sizes_so_the_button_matters(): void
    {
        $service = app(ImageAIService::class);
        $src = $this->putImage(1664, 928, 'b-1664x928.png');

        [$w1] = $this->dimsOf($service->normalizeOutputSize($src, '1K', '16:9'));
        [$w2, $h2] = $this->dimsOf($service->normalizeOutputSize($src, '2K', '16:9'));

        $this->assertSame(1024, $w1, '1K hạ về 1024.');
        $this->assertGreaterThan($w1, $w2,
            '2K phải cho ảnh lớn hơn 1K — trước đây hai nút cho CÙNG MỘT kết quả.');
        $this->assertLessThanOrEqual(2048, max($w2, $h2), '2K là mức CAP, không được phóng to quá 2048.');
    }

    // ── (b) tỉ lệ trung thực: 4:5 ra 4:5, 21:9 ra 21:9 ─────────────────────

    public function test_4_5_is_delivered_as_4_5_not_3_4(): void
    {
        $service = app(ImageAIService::class);
        $out = $service->normalizeOutputSize($this->putImage(1328, 1328, 'c-1328.png'), '1K', '4:5');

        [$w, $h] = $this->dimsOf($out);
        $r = $this->ratio($w, $h);

        $this->assertGreaterThan(0.78, $r, 'Tỉ lệ phải ≈ 4:5 (0.80).');
        $this->assertLessThan(0.82, $r, 'Tỉ lệ phải ≈ 4:5 (0.80) — KHÔNG được là 3:4 (0.75).');
        // 4:5 là CHÂN DUNG: cạnh dài 1024 = CHIỀU CAO. 3:4 ở cùng cạnh dài cho cao 1024 nhưng
        // rộng 768; chốt cứng cả hai chiều để bắt tái phát ánh xạ 4:5→3:4.
        $this->assertSame(1024, $h, 'Cạnh dài của 4:5 là chiều cao = 1024.');
        $this->assertSame(819, $w, '4:5 với cạnh dài 1024 ⇒ rộng 819. 3:4 sẽ cho rộng 768.');
        $this->assertNotSame(768, $w, 'Chặn ánh xạ 3:4.');
    }

    public function test_21_9_is_delivered_as_21_9_not_16_9(): void
    {
        $service = app(ImageAIService::class);
        $out = $service->normalizeOutputSize($this->putImage(1328, 1328, 'd-1328.png'), '1K', '21:9');

        [$w, $h] = $this->dimsOf($out);
        $r = $this->ratio($w, $h);

        $this->assertGreaterThan(2.20, $r, 'Tỉ lệ phải ≈ 21:9 (2.33).');
        $this->assertLessThan(2.42, $r);
        $this->assertGreaterThan(2.0, $r, '16:9 (≈1.78) đã bị cấm — trước đây 21:9 bị đổi âm thầm thành 16:9.');
    }

    // ── CHÍNH SÁCH: normalize CHỈ cho TẠO, KHÔNG cho SỬA ────────────────────

    public function test_edit_path_keeps_the_source_dimensions_not_the_selected_ratio(): void
    {
        // Ảnh gốc 800×1000 (tỉ lệ 0.8 — TRÙNG 4:5 nên nếu bị normalize thì khó phát hiện).
        // Chọn 1K + 4:5: nếu normalize chạy nhầm, kết quả sẽ là 1024×1280; nếu đúng chính sách
        // thì phải giữ NGUYÊN 800×1000 của ảnh gốc.
        $src = $this->putImage(800, 1000, 'e-src.png');
        $edited = $this->putImage(1024, 1024, 'e-edited.png');

        $svc = new class($edited) extends ImageAIService {
            public function __construct(private string $editUrl) {}

            protected function editImage(string $prompt, string $imageUrl, ?string $modelOverride = null, ?string $faceRefUrl = null, ?string $poseRefUrl = null, ?string $maskImage = null, array $refImages = []): ?string
            {
                return $this->editUrl;
            }
        };

        config(['studio.qwen_edit_key' => 'sk-edit']);

        $out = $svc->generate('change the sleeve', $src, null, '1K', '4:5');
        [$w, $h] = $this->dimsOf($out);

        $this->assertSame(800, $w, 'Ảnh SỬA phải giữ chiều rộng ẢNH GỐC (không cắt về 4:5).');
        $this->assertSame(1000, $h, 'Ảnh SỬA phải giữ chiều cao ẢNH GỐC (không ép về 1K).');
    }
}
