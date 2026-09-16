<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DEEP REDESIGN (CROP + INPAINT + PASTE): pasteRegionEdit dán ảnh crop (vùng đã AI sửa)
 * về ĐÚNG vị trí trong ảnh gốc — không lệch tọa độ.
 */
class StudioRegionPasteTest extends TestCase
{
    use RefreshDatabase;

    public function test_paste_region_edit_places_at_exact_position(): void
    {
        $src = imagecreatetruecolor(100, 100);
        imagefilledrectangle($src, 0, 0, 99, 99, imagecolorallocate($src, 200, 200, 200));

        // crop từ (10,20) size 30x30 — "edited": vùng giữa đỏ
        $crop = imagecreatetruecolor(30, 30);
        imagecopy($crop, $src, 0, 0, 10, 20, 30, 30);
        imagefilledrectangle($crop, 8, 8, 21, 21, imagecolorallocate($crop, 220, 40, 40));

        imagepng($src, storage_path('app/public/p-src.png'));
        imagepng($crop, storage_path('app/public/p-crop.png'));

        $svc = app(\App\Services\ImageAIService::class);
        $url = (new \ReflectionMethod($svc, 'pasteRegionEdit'))->invoke($svc, '/storage/p-crop.png', [
            'source' => '/storage/p-src.png', 'crop_x' => 10, 'crop_y' => 20, 'crop_w' => 30, 'crop_h' => 30,
        ]);
        $this->assertNotNull($url);

        $out = imagecreatefrompng(public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/')));
        $px = function (int $x, int $y) use ($out): array {
            $c = imagecolorat($out, $x, $y);
            return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
        };
        // vùng đã paste nằm đúng vị trí (crop 10,20) + tâm crop (9..19) → đỏ ở (19,29)
        $this->assertSame([220, 40, 40], $px(19, 29), 'nội dung phải nằm đúng vị trí vùng');
        $this->assertSame([220, 40, 40], $px(30, 40), 'nội dung phải nằm đúng vị trí vùng');
        // ngoài crop giữ nguyên ảnh gốc
        $this->assertSame([200, 200, 200], $px(5, 5), 'ngoài vùng giữ nguyên');
        $this->assertSame([200, 200, 200], $px(70, 70), 'ngoài vùng giữ nguyên');
        imagedestroy($out);

        foreach (['p-src.png', 'p-crop.png'] as $f) { @unlink(storage_path('app/public/'.$f)); }
        @unlink(public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/')));
    }
}
