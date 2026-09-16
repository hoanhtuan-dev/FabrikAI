<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioRegionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('email', 'admin@trillfa.com')->firstOrFail());
    }

    private function makeSourceGen(): \App\Models\Generation
    {
        $img = imagecreatetruecolor(80, 80);
        for ($y = 0; $y < 80; $y++) {
            for ($x = 0; $x < 80; $x++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * 3) % 256, ($y * 3) % 256, (($x + $y) * 2) % 256));
            }
        }
        imagepng($img, storage_path('app/public/region-src.png'));
        imagedestroy($img);

        return auth()->user()->generations()->create([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'nguồn',
            'model' => 'flux', 'provider' => 'flux',
            'media_url' => '/storage/region-src.png', 'credits_cost' => 1,
        ]);
    }

    public function test_region_erase_local_completes_and_changes_image(): void
    {
        $src = $this->makeSourceGen();
        $res = $this->postJson('/api/generations/'.$src->id.'/region', [
            'op' => 'erase', 'region' => ['x' => 0.3, 'y' => 0.3, 'w' => 0.4, 'h' => 0.4],
        ]);
        $res->assertStatus(200);
        $this->assertEquals('completed', $res->json('status'));
        $this->assertNotEmpty($res->json('media_url'));
        $this->assertDatabaseHas('generations', ['id' => $res->json('generation_id'), 'model' => 'erase', 'provider' => 'local']);

        $out = public_path(ltrim((string) parse_url($res->json('media_url'), PHP_URL_PATH), '/'));
        $this->assertFileExists($out);
        $this->assertNotEquals(md5_file(storage_path('app/public/region-src.png')), md5_file($out));
        $outImg = @imagecreatefrompng($out);
        $this->assertNotFalse($outImg);
        $this->assertSame(80, imagesx($outImg));
        imagedestroy($outImg);

        @unlink($out);
        @unlink(storage_path('app/public/region-src.png'));
    }

    public function test_region_rejects_unknown_op(): void
    {
        $src = $this->makeSourceGen();
        $this->postJson('/api/generations/'.$src->id.'/region', ['op' => 'explode', 'region' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.3]])->assertStatus(422);
        @unlink(storage_path('app/public/region-src.png'));
    }

    public function test_region_replace_requires_prompt(): void
    {
        $src = $this->makeSourceGen();
        $this->postJson('/api/generations/'.$src->id.'/region', ['op' => 'replace', 'region' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.3]])->assertStatus(422);
        @unlink(storage_path('app/public/region-src.png'));
    }

    public function test_region_rejects_tiny_selection(): void
    {
        $src = $this->makeSourceGen();
        $this->postJson('/api/generations/'.$src->id.'/region', ['op' => 'erase', 'region' => ['x' => 0.5, 'y' => 0.5, 'w' => 0.001, 'h' => 0.001]])->assertStatus(422);
        @unlink(storage_path('app/public/region-src.png'));
    }

    public function test_region_denies_other_users_generation(): void
    {
        $other = User::factory()->create();
        $gen = $other->generations()->create([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'x',
            'model' => 'flux', 'provider' => 'flux', 'media_url' => '/storage/none.png', 'credits_cost' => 0,
        ]);
        $this->postJson('/api/generations/'.$gen->id.'/region', ['op' => 'erase', 'region' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.3]])->assertStatus(403);
    }

    public function test_fit_to_source_size_normalizes_edited_image(): void
    {
        $src = imagecreatetruecolor(60, 80);
        for ($y = 0; $y < 80; $y++) { for ($x = 0; $x < 60; $x++) { imagesetpixel($src, $x, $y, imagecolorallocate($src, ($x * 4) % 256, ($y * 3) % 256, 128)); } }
        imagepng($src, storage_path('app/public/fit-src.png')); imagedestroy($src);

        $edited = imagecreatetruecolor(80, 80);
        for ($y = 0; $y < 80; $y++) { for ($x = 0; $x < 80; $x++) { imagesetpixel($edited, $x, $y, imagecolorallocate($edited, ($x * 3) % 256, 128, ($y * 3) % 256)); } }
        imagepng($edited, storage_path('app/public/fit-edited.png')); imagedestroy($edited);

        $svc = app(\App\Services\ImageAIService::class);
        $url = (new \ReflectionMethod($svc, 'fitToSourceSize'))->invoke($svc, '/storage/fit-edited.png', '/storage/fit-src.png');
        $this->assertNotNull($url);

        $outPath = public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));
        $out = @imagecreatefrompng($outPath);
        $this->assertNotFalse($out);
        $this->assertSame(60, imagesx($out), 'width phải khớp ảnh gốc');
        $this->assertSame(80, imagesy($out), 'height phải khớp ảnh gốc');
        imagedestroy($out);

        @unlink($outPath);
        @unlink(storage_path('app/public/fit-src.png'));
        @unlink(storage_path('app/public/fit-edited.png'));
    }

    public function test_composite_masked_edit_keeps_outside_identical(): void
    {
        $src = imagecreatetruecolor(40, 40);
        imagefilledrectangle($src, 0, 0, 39, 39, imagecolorallocate($src, 20, 60, 200));
        imagepng($src, storage_path('app/public/c-src.png')); imagedestroy($src);

        $edited = imagecreatetruecolor(40, 40);
        imagefilledrectangle($edited, 0, 0, 39, 39, imagecolorallocate($edited, 20, 60, 200));
        imagefilledrectangle($edited, 10, 10, 29, 29, imagecolorallocate($edited, 220, 40, 40));
        imagepng($edited, storage_path('app/public/c-edited.png')); imagedestroy($edited);

        $mask = imagecreatetruecolor(40, 40);
        imagefilledrectangle($mask, 0, 0, 39, 39, imagecolorallocate($mask, 255, 255, 255));
        imagefilledrectangle($mask, 10, 10, 29, 29, imagecolorallocate($mask, 0, 0, 0));
        imagepng($mask, storage_path('app/public/c-mask.png')); imagedestroy($mask);

        $svc = app(\App\Services\ImageAIService::class);
        $url = (new \ReflectionMethod($svc, 'compositeMaskedEdit'))->invoke($svc, '/storage/c-edited.png', '/storage/c-src.png', '/storage/c-mask.png');
        $this->assertNotNull($url);

        $out = imagecreatefrompng(public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/')));
        $px = function (int $x, int $y) use ($out): array {
            $c = imagecolorat($out, $x, $y);
            return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
        };
        $this->assertSame([20, 60, 200], $px(2, 2), 'ngoài vùng phải giữ nguyên ảnh gốc');
        $this->assertSame([20, 60, 200], $px(2, 37), 'ngoài vùng phải giữ nguyên ảnh gốc');
        $this->assertSame([220, 40, 40], $px(20, 20), 'trong vùng phải lấy kết quả edit');
        $this->assertSame([220, 40, 40], $px(15, 25), 'trong vùng phải lấy kết quả edit');
        imagedestroy($out);

        foreach (['c-src.png', 'c-edited.png', 'c-mask.png'] as $f) { @unlink(storage_path('app/public/'.$f)); }
        @unlink(public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/')));
    }

    public function test_region_uses_source_url_as_displayed_image(): void
    {
        $a = imagecreatetruecolor(80, 80);
        for ($y = 0; $y < 80; $y++) { for ($x = 0; $x < 80; $x++) { imagesetpixel($a, $x, $y, imagecolorallocate($a, 40, 160, 60)); } }
        imagepng($a, storage_path('app/public/ra.png'));

        $b = imagecreatetruecolor(60, 40);
        for ($y = 0; $y < 40; $y++) { for ($x = 0; $x < 60; $x++) { imagesetpixel($b, $x, $y, imagecolorallocate($b, ($x * 4) % 256, 128, ($y * 6) % 256)); } }
        imagepng($b, storage_path('app/public/rb.png'));

        $gen = auth()->user()->generations()->create([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'nguồn',
            'model' => 'flux', 'provider' => 'flux', 'media_url' => '/storage/ra.png', 'credits_cost' => 1,
        ]);

        $res = $this->postJson('/api/generations/'.$gen->id.'/region', [
            'op' => 'erase', 'region' => ['x' => 0.2, 'y' => 0.2, 'w' => 0.4, 'h' => 0.4],
            'source_url' => '/storage/rb.png',
        ]);
        $res->assertStatus(200);
        $this->assertEquals('completed', $res->json('status'));

        $outPath = public_path(ltrim((string) parse_url($res->json('media_url'), PHP_URL_PATH), '/'));
        $out = @imagecreatefrompng($outPath);
        $this->assertNotFalse($out);
        $this->assertSame(60, imagesx($out), 'phải dùng ảnh hiển thị source_url (60)');
        $this->assertSame(40, imagesy($out), 'phải dùng ảnh hiển thị source_url (40)');
        imagedestroy($out);

        @unlink($outPath);
        @unlink(storage_path('app/public/ra.png'));
        @unlink(storage_path('app/public/rb.png'));
    }
}
