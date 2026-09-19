<?php

namespace Tests\Unit;

use App\Services\DesignAgentService;
use PHPUnit\Framework\TestCase;

class DesignAgentServiceTest extends TestCase
{
    private DesignAgentService $agents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agents = new DesignAgentService();
    }

    public function test_catalog_has_stable_data_backed_directions(): void
    {
        $trends = $this->agents->trendCatalog('hcm');

        $this->assertCount(8, $trends);
        $this->assertSame('hcm', $trends[0]['region']);
        $this->assertSame('demo', $trends[0]['evidence_mode']);
        $this->assertContains('soft-pastel', $this->agents->trendIds());
        $this->assertContains('clean-tailoring', $this->agents->trendIds());
    }

    public function test_collection_brief_builds_the_expected_contract(): void
    {
        $brief = $this->agents->collectionBrief([
            'prompt' => 'Bộ sưu tập linen pastel cho công sở.',
            'region' => 'all',
            'trend_ids' => ['linen-breeze', 'soft-pastel'],
            'size_distribution' => ['s' => 12, 'm' => 24, 'l' => 18, 'xl' => 6],
        ], null);

        $this->assertSame('CollectionBot', $brief['agent']);
        $this->assertCount(2, $brief['selected_trends']);
        $this->assertCount(24, $brief['moodboard']['items']);
        $this->assertSame(['S', 'M', 'L', 'XL'], array_column($brief['size_distribution'], 'size'));
        $this->assertArrayHasKey('name', $brief['project_payload']);
        $this->assertArrayHasKey('brief', $brief['project_payload']);
        $this->assertSame('4:5', $brief['canvas']['ratio']);
        $this->assertSame(2, $brief['canvas']['variant_count']);
        $this->assertNotEmpty($brief['canvas']['negative_prompt']);
        $this->assertNotEmpty($brief['input_signature']);
    }

    public function test_input_signature_changes_when_prompt_changes(): void
    {
        $base = [
            'prompt' => 'Bộ sưu tập linen pastel.',
            'region' => 'all',
            'trend_ids' => ['linen-breeze'],
        ];
        $first = $this->agents->collectionBrief($base, null);
        $second = $this->agents->collectionBrief($base, null);
        $third = $this->agents->collectionBrief(array_merge($base, ['prompt' => 'Bộ sưu tập linen pastel mùa hè.']), null);

        $this->assertSame($first['input_signature'], $second['input_signature'], 'Cùng input phải cho cùng signature.');
        $this->assertNotSame($first['input_signature'], $third['input_signature'], 'Đổi prompt phải đổi signature.');
    }

    public function test_region_boost_changes_momentum_not_catalog_identity(): void
    {
        $all = collect($this->agents->trendCatalog('all'))->keyBy('id');
        $hcm = collect($this->agents->trendCatalog('hcm'))->keyBy('id');

        $this->assertGreaterThan($all['linen-breeze']['momentum'], $hcm['linen-breeze']['momentum']);
        $this->assertSame($all['linen-breeze']['id'], $hcm['linen-breeze']['id']);
    }
}
