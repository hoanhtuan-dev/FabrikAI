<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: localEraseFill (xóa vùng = tái tạo nền) không được nhuộm ĐEN ra ngoài vùng
 * (lỗi patch feather imagecreatetruecolor mặc định đen gây viền đen quanh vùng).
 */
class StudioEraseFillTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_erase_fill_keeps_outside_clean_and_matches_background(): void
    {
        $ctrl = app(\App\Http\Controllers\StudioController::class);
        $m = new \ReflectionMethod($ctrl, 'localEraseFill');

        // Nền xám đồng nhất 100x100
        $img = imagecreatetruecolor(100, 100);
        imagefilledrectangle($img, 0, 0, 99, 99, imagecolorallocate($img, 200, 200, 200));

        $color = function (int $x, int $y) use ($img): array {
            $c = imagecolorat($img, $x, $y);
            return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
        };

        $m->invoke($ctrl, $img, 30, 30, 40, 40); // vùng (30..69, 30..69)

        // NGOÀI vùng (nằm trong lề feather, trước đây bị đen) — phải giữ nguyên nền
        $this->assertSame([200, 200, 200], $color(27, 30), 'mép trái ngoài vùng không được đen');
        $this->assertSame([200, 200, 200], $color(30, 27), 'mép trên ngoài vùng không được đen');
        $this->assertSame([200, 200, 200], $color(73, 69), 'mép phải ngoài vùng không được đen');
        // TRONG vùng — được tái tạo khớp nền (không đen, không màu lạ)
        $this->assertSame([200, 200, 200], $color(50, 50), 'tâm vùng phải khớp nền');
        $this->assertSame([200, 200, 200], $color(32, 32), 'góc vùng phải khớp nền');

        imagedestroy($img);
    }
}
