<?php

namespace Tests\Unit;

use App\Services\CreativeDirectionService;
use PHPUnit\Framework\TestCase;

class CreativeDirectionServiceTest extends TestCase
{
    protected CreativeDirectionService $dir;

    protected function setUp(): void
    {
        $this->dir = new CreativeDirectionService();
    }

    public function test_image_and_video_prompt_share_the_same_garment_signature(): void
    {
        $injections = [
            'fabric' => 'silk',
            'silhouette' => 'A-line',
            'style' => 'minimal rose gold',
            'background' => 'marble studio',
        ];

        $c = $this->dir->normalize([], 'Váy dạ hội', $injections, 6);

        $this->assertStringContainsString('silk', strtolower($c['image_prompt_en']));
        $this->assertStringContainsString('silk', strtolower($c['video_prompt_en']));
        $this->assertStringContainsString('minimal rose gold', strtolower($c['image_prompt_en']));
        $this->assertStringContainsString('minimal rose gold', strtolower($c['video_prompt_en']));
        $this->assertStringContainsString('a-line', strtolower($c['video_prompt_en']));
        $this->assertSame(6, $c['creative_level']);
        $this->assertSame(5, $c['adherence']);
    }

    public function test_creativity_directive_varies_with_level(): void
    {
        $this->assertStringContainsString('low', strtolower($this->dir->creativityDirective(2)));
        $this->assertStringContainsString('medium', strtolower($this->dir->creativityDirective(6)));
        $this->assertStringContainsString('high', strtolower($this->dir->creativityDirective(9)));
    }

    public function test_adherence_is_inverse_of_creativity(): void
    {
        $this->assertSame(10, $this->dir->adherence(1));
        $this->assertSame(1, $this->dir->adherence(10));
        $this->assertSame(4, $this->dir->adherence(7));
    }

    public function test_normalize_preserves_a_model_prompt_when_provided(): void
    {
        $injections = ['fabric' => 'velvet', 'style' => 'old money'];
        $raw = ['image_prompt_en' => 'A rich old money velvet coat on a marble podium, soft light, 4k'];

        $c = $this->dir->normalize($raw, 'Áo ve lvet sang trọng', $injections, 8);

        // The provided prompt is kept (only the signature block is guaranteed).
        $this->assertStringContainsString('velvet coat', strtolower($c['image_prompt_en']));
        $this->assertNotEmpty($c['video_prompt_en']);
        $this->assertSame(8, $c['creative_level']);
        $this->assertSame(3, $c['adherence']);
    }

    public function test_high_creativity_relaxes_the_negative_prompt(): void
    {
        $low = $this->dir->normalize([], 'x', [], 2);
        $high = $this->dir->normalize([], 'x', [], 9);

        $this->assertStringContainsString('inconsistent face', $low['negative_prompt']);
        $this->assertStringNotContainsString('inconsistent face', $high['negative_prompt']);
    }

    public function test_empty_injections_fall_back_to_canonical_builders(): void
    {
        $c = $this->dir->normalize([], 'a velvet evening gown', [], 6);

        $this->assertStringContainsString('velvet evening gown', strtolower($c['image_prompt_en']));
        // The base video prompt must NOT hardcode a camera — the camera action comes from the
        // "Kịch bản quay" preset at render time, so it is added by VideoAIService (dedup-aware).
        $this->assertStringNotContainsString('slow tracking shot', strtolower($c['video_prompt_en']));
        $this->assertStringContainsString('cinematic fashion show', strtolower($c['video_prompt_en']));
        $this->assertStringContainsString('creativity', strtolower($c['image_prompt_en']));
    }
}
