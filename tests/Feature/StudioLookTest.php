<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioLookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('email', 'admin@fabrikai.shop')->firstOrFail());
    }

    /** Small colorful gradient source so every tone component (tint/contrast/sat/lift) is exercised. */
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

    public function test_look_accepts_all_presets_including_cinematic(): void
    {
        $this->makeSource('look-src.png');
        foreach (['studio', 'warm', 'cool', 'cinematic', 'dramatic', 'retro', 'mono'] as $look) {
            $res = $this->postJson('/api/look', ['image' => '/storage/look-src.png', 'look' => $look, 'level' => 5]);
            $res->assertStatus(200);
            $this->assertNotEmpty($res->json('media_url'), "media_url for {$look}");
            $this->assertNotEmpty($res->json('generation_id'), "generation_id for {$look}");
            $this->assertDatabaseHas('generations', ['id' => $res->json('generation_id'), 'model' => 'look']);
        }
        @unlink(storage_path('app/public/look-src.png'));
    }

    public function test_look_rejects_unknown_preset(): void
    {
        $this->makeSource('look-src2.png');
        $this->postJson('/api/look', ['image' => '/storage/look-src2.png', 'look' => 'neon', 'level' => 5])->assertStatus(422);
        @unlink(storage_path('app/public/look-src2.png'));
    }

    /**
     * Regression: the strength slider must actually control the whole grade.
     * Previously only the tint was scaled by level while contrast/lift/saturation ran at
     * full strength, so level 1 was still punchy (and mono was always 100% desaturated).
     */
    public function test_look_strength_scales_with_level(): void
    {
        $ctrl = app(\App\Http\Controllers\StudioController::class);
        $method = new \ReflectionMethod($ctrl, 'applyLook');

        $make = function (): \GdImage {
            $img = imagecreatetruecolor(32, 32);
            for ($y = 0; $y < 32; $y++) {
                for ($x = 0; $x < 32; $x++) {
                    imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * 8) % 256, ($y * 8) % 256, (($x + $y) * 4) % 256));
                }
            }
            return $img;
        };
        $delta = function (\GdImage $a, \GdImage $b): float {
            $sum = 0.0; $n = 0;
            for ($y = 0; $y < 32; $y++) {
                for ($x = 0; $x < 32; $x++) {
                    $pa = imagecolorat($a, $x, $y);
                    $pb = imagecolorat($b, $x, $y);
                    $sum += abs((($pa >> 16) & 255) - (($pb >> 16) & 255))
                          + abs((($pa >> 8) & 255) - (($pb >> 8) & 255))
                          + abs(($pa & 255) - ($pb & 255));
                    $n += 3;
                }
            }
            return $sum / $n;
        };

        foreach (['studio', 'warm', 'cool', 'cinematic', 'dramatic', 'retro', 'mono'] as $look) {
            $orig = $make();
            $l2 = $make();
            $method->invoke($ctrl, $l2, $look, 2);
            $l10 = $make();
            $method->invoke($ctrl, $l10, $look, 10);
            $d2 = $delta($orig, $l2);
            $d10 = $delta($orig, $l10);
            // Level 10 must grade clearly stronger than level 2.
            $this->assertGreaterThan($d2 * 1.5, $d10, "level 10 should be stronger than level 2 ({$look})");
            // ...and level 2 must stay genuinely subtle (per-channel mean delta < ~14% of 255).
            $this->assertLessThan(35.0, $d2, "level 2 should be subtle ({$look})");
            imagedestroy($orig);
            imagedestroy($l2);
            imagedestroy($l10);
        }
    }
}
