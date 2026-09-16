<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Tinh chỉnh & Nâng cấp ảnh" (upscale) endpoint + GD pipeline.
 * Fabric-weave/roughness pass was removed (it affected dark skin & detail boundaries).
 */
class StudioUpscaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('email', 'admin@fabrikai.shop')->firstOrFail());
    }

    private function ctrl(): \App\Http\Controllers\StudioController
    {
        return app(\App\Http\Controllers\StudioController::class);
    }

    private function invoke(string $method, ...$args): mixed
    {
        return (new \ReflectionMethod($this->ctrl(), $method))->invoke($this->ctrl(), ...$args);
    }

    private function makeSource(string $file, int $w = 48, int $h = 48): void
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * 5) % 256, ($y * 5) % 256, (($x + $y) * 3) % 256));
            }
        }
        imagepng($img, storage_path('app/public/'.$file));
        imagedestroy($img);
    }

    public function test_upscale_endpoint_works(): void
    {
        $this->makeSource('upscale-src.png', 64, 64);
        $res = $this->postJson('/api/upscale', [
            'image' => '/storage/upscale-src.png',
            'scale' => 2,
            'refine' => 0,   // 0 = bỏ qua AI refine (test thuần GD, không cần key)
            'vibrance' => 4,
        ]);
        $res->assertStatus(200);
        $this->assertNotEmpty($res->json('media_url'));
        $this->assertNotEmpty($res->json('generation_id'));
        $this->assertDatabaseHas('generations', ['id' => $res->json('generation_id'), 'model' => 'upscale']);

        $file = public_path(ltrim((string) parse_url($res->json('media_url'), PHP_URL_PATH), '/'));
        if (is_file($file)) { @unlink($file); }
        @unlink(storage_path('app/public/upscale-src.png'));
    }

    public function test_smart_upscale_still_resizes(): void
    {
        $src = imagecreatetruecolor(48, 48);
        $out = $this->invoke('smartUpscale', $src, 4);
        $this->assertSame(192, imagesx($out));
        $this->assertSame(192, imagesy($out));
        imagedestroy($src);
        imagedestroy($out);
    }
}
