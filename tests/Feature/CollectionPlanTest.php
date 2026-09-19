<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CollectionPlanService;
use App\Services\DesignAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [2026-09-22] TẦNG "TIỀN" của Agent Studio: giá thành · lệnh cắt · đợt sản xuất · bảng size, và
 * DỮ LIỆU BÁN HÀNG THẬT của shop.
 *
 * Vì sao có bộ test này: chủ xưởng chỉ trả tiền khi Agent Studio trả lời được "cắt bao nhiêu cái,
 * đặt bao nhiêu mét vải, giá vốn bao nhiêu, bán giá nào thì lãi, cần bao nhiêu vốn". Mọi con số
 * phải TÁI LẬP ĐƯỢC và do chủ xưởng kiểm soát (đơn giá họ nhập) — không được để AI bịa ra.
 */
class CollectionPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    /** Brief tất định (không AI) — đúng dữ liệu vào của kế hoạch sản xuất. */
    private function brief(array $overrides = []): array
    {
        return app(DesignAgentService::class)->collectionBrief(array_merge([
            'prompt' => 'Bộ sưu tập linen pastel cho nữ văn phòng mùa hè',
            'region' => 'all',
            'trend_ids' => ['linen-breeze', 'soft-pastel'],
            'size_distribution' => ['s' => 20, 'm' => 35, 'l' => 30, 'xl' => 15],
        ], $overrides), null, false);
    }

    // ── 1. Lệnh cắt & giá thành ─────────────────────────────────────────────

    public function test_plan_builds_a_costed_cut_sheet(): void
    {
        $plan = app(CollectionPlanService::class)->plan($this->brief(), ['units_per_sku' => 30]);

        // 4 nhóm hàng × 4 size = 16 dòng cắt; tổng cái = số mã × số lượng mỗi mã.
        $this->assertCount(16, $plan['cut_lines']);
        $this->assertSame(12 * 30, $plan['totals']['units']);
        $this->assertSame('VND', $plan['currency']);

        foreach ($plan['cut_lines'] as $line) {
            $this->assertGreaterThan(0, $line['unit_cost'], 'Mỗi dòng cắt phải có giá vốn.');
            $this->assertGreaterThan(0, $line['fabric_m_per_unit']);
            $this->assertEqualsWithDelta($line['qty'] * $line['net_revenue_unit'], $line['revenue_vnd'], 1);
            $this->assertNotNull($line['margin_pct']);
        }

        // Đặt vải phải nhiều hơn lượng vải dùng (dư đầu khúc + làm tròn lên 10m).
        $this->assertGreaterThan($plan['totals']['fabric_m'], $plan['totals']['fabric_order_m']);
        $this->assertSame(
            (int) round($plan['totals']['fabric_order_m'] * $plan['assumptions']['fabric_price_per_m']),
            $plan['totals']['fabric_order_cost_vnd'],
        );

        // Lợi nhuận = doanh thu thu về - giá vốn, và vốn cần = giá vốn + chi phí cố định.
        $this->assertSame(
            $plan['totals']['revenue_vnd'] - $plan['totals']['cost_total_vnd'],
            $plan['totals']['profit_vnd'],
        );
        $this->assertSame($plan['totals']['cost_total_vnd'], $plan['totals']['capital_needed_vnd']);
    }

    public function test_plan_splits_into_three_waves_that_add_up(): void
    {
        $plan = app(CollectionPlanService::class)->plan($this->brief(), ['units_per_sku' => 40]);

        $this->assertCount(3, $plan['waves']);
        $units = array_sum(array_column($plan['waves'], 'units'));
        $this->assertSame($plan['totals']['units'], $units, 'Ba đợt phải cộng lại ĐÚNG bằng tổng số cái.');
        $fabric = array_sum(array_column($plan['waves'], 'fabric_m'));
        $this->assertEqualsWithDelta($plan['totals']['fabric_m'], $fabric, 1.0, 'Vải của ba đợt phải khớp tổng vải.');
        $this->assertLessThan($plan['waves'][1]['units'], $plan['waves'][0]['units'], 'Đợt 1 (cắt thử) phải nhỏ nhất về số cái.');
        $this->assertGreaterThan(0, $plan['waves'][0]['days']);
    }

    public function test_plan_units_per_sku_changes_the_whole_sheet(): void
    {
        $planner = app(CollectionPlanService::class);
        $small = $planner->plan($this->brief(), ['units_per_sku' => 10]);
        $big = $planner->plan($this->brief(), ['units_per_sku' => 100]);

        $this->assertSame(120, $small['totals']['units']);
        $this->assertSame(1200, $big['totals']['units']);
        $this->assertGreaterThan($small['totals']['fabric_order_m'], $big['totals']['fabric_order_m']);
        $this->assertGreaterThan($small['totals']['capital_needed_vnd'], $big['totals']['capital_needed_vnd']);
    }

    public function test_higher_fabric_price_raises_cost_and_suggested_price(): void
    {
        $planner = app(CollectionPlanService::class);
        $cheap = $planner->plan($this->brief(), ['fabric_price_per_m' => 60000]);
        $pricey = $planner->plan($this->brief(), ['fabric_price_per_m' => 160000]);

        $this->assertLessThan($pricey['totals']['avg_unit_cost_vnd'], $cheap['totals']['avg_unit_cost_vnd']);
        $this->assertLessThan($pricey['cut_lines'][0]['suggested_price_vnd'], $cheap['cut_lines'][0]['suggested_price_vnd']);
        $this->assertGreaterThan(0, $cheap['totals']['avg_profit_unit_vnd']);
    }

    public function test_plan_has_a_size_chart_and_selling_scenarios(): void
    {
        $plan = app(CollectionPlanService::class)->plan($this->brief());

        $this->assertNotEmpty($plan['size_chart']);
        $chart = collect($plan['size_chart'])->firstWhere('category', 'Áo / blouse');
        $this->assertNotNull($chart);
        $this->assertSame('cm', $chart['unit']);
        $measures = array_column($chart['rows'], 'measures');
        $this->assertLessThan($measures[3]['Ngực'], $measures[0]['Ngực'], 'Số đo phải tăng dần theo size.');
        $this->assertGreaterThan(0, array_sum(array_column($chart['rows'], 'units_planned')));

        $this->assertGreaterThanOrEqual(2, count($plan['selling']));
        foreach ($plan['selling'] as $scenario) {
            $this->assertArrayHasKey('profit_vnd', $scenario);
            $this->assertArrayHasKey('label', $scenario);
        }
    }

    public function test_plan_flags_when_the_cost_price_sits_outside_the_market_band(): void
    {
        $planner = app(CollectionPlanService::class);

        // Giá vải + giá công quá cao ⇒ muốn đủ lãi thì phải bán CAO HƠN dải giá thị trường.
        $expensive = $planner->plan($this->brief(), ['fabric_price_per_m' => 900000, 'sewing_cost' => 400000]);
        $this->assertSame('above_band', $expensive['price_check']['status']);
        $this->assertGreaterThan($expensive['price_check']['band_max_vnd'], $expensive['price_check']['suggested_price_vnd']);
        $this->assertStringContainsString('CAO HƠN', $expensive['price_check']['message']);

        // Giá vải rất rẻ ⇒ giá vốn thấp hơn hẳn dải giá: dư địa lãi, nhưng phải cảnh báo để không bán rẻ.
        $cheap = $planner->plan($this->brief(), [
            'fabric_price_per_m' => 20000, 'sewing_cost' => 20000, 'trim_cost' => 5000, 'packaging_cost' => 1000,
        ]);
        $this->assertSame('below_band', $cheap['price_check']['status']);
        $this->assertStringContainsString('dư địa lãi', (string) $cheap['price_check']['message']);

        // Kết luận này phải xuất hiện trong danh sách ghi chú để người dùng không bỏ sót.
        $this->assertStringContainsString('Đối chiếu giá:', implode(' ', $expensive['notes']));
    }

    public function test_plan_clamps_absurd_assumptions(): void
    {
        $plan = app(CollectionPlanService::class)->plan($this->brief(), [
            'wastage_pct' => 900,
            'channel_discount_pct' => -50,
            'fabric_price_per_m' => 'khong-phai-so',
        ]);

        $this->assertSame(100, $plan['assumptions']['wastage_pct']);
        $this->assertSame(0, $plan['assumptions']['channel_discount_pct']);
        $this->assertSame(CollectionPlanService::DEFAULTS['fabric_price_per_m'], $plan['assumptions']['fabric_price_per_m']);
    }

    public function test_plan_without_structure_reports_missing_input(): void
    {
        $plan = app(CollectionPlanService::class)->plan(['structure' => ['categories' => []], 'size_distribution' => []]);

        $this->assertSame('missing_input', $plan['basis']);
        $this->assertSame([], $plan['cut_lines']);
    }

    // ── 2. Endpoint kế hoạch sản xuất ───────────────────────────────────────

    public function test_plan_endpoint_costs_the_collection_without_calling_ai(): void
    {
        Http::fake();

        $response = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/plan', [
                'prompt' => 'Bộ sưu tập linen pastel cho nữ văn phòng',
                'region' => 'all',
                'trend_ids' => ['linen-breeze'],
                'size_distribution' => ['s' => 20, 'm' => 35, 'l' => 30, 'xl' => 15],
                'assumptions' => ['units_per_sku' => 25, 'fabric_price_per_m' => 90000],
            ])
            ->assertOk();

        $response->assertJsonPath('engine', 'plan-v1')
            ->assertJsonPath('model.reason', 'plan_is_deterministic')
            ->assertJsonPath('plan.assumptions.units_per_sku', 25)
            ->assertJsonPath('plan.assumptions.fabric_price_per_m', 90000);

        $this->assertGreaterThan(0, $response->json('plan.totals.units'));
        $this->assertNotEmpty($response->json('plan.waves'));
        Http::assertNothingSent();   // tiền là chuyện của hệ thống, không phải của model
    }

    public function test_plan_endpoint_validates_input_like_the_brief(): void
    {
        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/plan', ['prompt' => '  '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('prompt');
    }

    public function test_guests_cannot_request_a_plan(): void
    {
        $this->postJson('/api/design-agent/plan', ['prompt' => 'Bộ sưu tập hè'])->assertUnauthorized();
    }

    // ── 3. Dữ liệu bán hàng thật của shop ───────────────────────────────────

    private function shopRows(): array
    {
        return [
            ['name' => 'Áo linen tay dài', 'category' => 'Áo', 'units_sold' => 120, 'stock_on_hand' => 18, 'returns' => 5, 'price_vnd' => 520000],
            ['name' => 'Váy midi công sở', 'category' => 'Váy', 'units_sold' => 20, 'stock_on_hand' => 60, 'returns' => 2, 'price_vnd' => 430000],
        ];
    }

    public function test_shop_signals_are_saved_and_feed_the_brief(): void
    {
        $user = $this->customer();

        $saved = $this->actingAs($user)
            ->postJson('/api/design-agent/shop-signals', ['rows' => $this->shopRows(), 'source' => 'paste'])
            ->assertOk();

        $this->assertSame(2, $saved->json('saved'));
        $this->assertSame(140, $saved->json('shop.units_sold'));
        $this->assertSame(78, $saved->json('shop.stock_on_hand'));
        $this->assertSame(7, $saved->json('shop.returns'));
        $this->assertStringContainsString('Áo linen tay dài', (string) $saved->json('shop.narrative'));
        $this->assertDatabaseHas('shop_signals', ['user_id' => $user->id, 'name' => 'Áo linen tay dài', 'source' => 'paste']);

        // Radar: tín hiệu nội bộ chuyển sang dữ liệu THẬT (local) và nêu đúng số của shop.
        $radar = $this->actingAs($user)->postJson('/api/design-agent/radar', ['ai' => false])->assertOk();
        $radar->assertJsonPath('internal_brand_signal.data_mode', 'local')
            ->assertJsonPath('internal_brand_signal.shop.row_count', 2);
        $this->assertStringContainsString('140', (string) $radar->json('internal_brand_signal.narrative'));

        // Brief: cơ cấu SKU bám nhóm BÁN CHẠY NHẤT và bớt 1 SKU ở nhóm tồn nhiều.
        $brief = $this->actingAs($user)->postJson('/api/design-agent/collection', [
            'prompt' => 'Bộ sưu tập linen pastel cho nữ văn phòng',
            'ai' => false,
        ])->assertOk();

        $brief->assertJsonPath('structure.basis', 'shop_data')
            ->assertJsonPath('brand_narrative.data_mode', 'local');
        $categories = collect($brief->json('structure.categories'))->keyBy('category');
        $this->assertSame('shop', $categories['Áo / blouse']['source']);
        // Prompt nhắc "văn phòng" đã +1 cho nhóm áo (quy tắc từ khoá), rồi boost dữ liệu shop +1 nữa.
        $this->assertSame(6, $categories['Áo / blouse']['count'], 'Nhóm bán chạy nhất phải được +1 SKU so với mức từ khoá.');
        // Váy: quy tắc "văn phòng" đưa về 2, rồi dữ liệu shop (tồn 60 mà bán 20) lấy bớt 1 → 1.
        $this->assertSame(1, $categories['Váy']['count'], 'Nhóm tồn nhiều mà bán chậm bị -1 SKU.');
        $this->assertSame('shop_data', $brief->json('price_bands.basis'));
        $this->assertGreaterThan(0, $brief->json('price_bands.avg_shop_vnd'));
    }

    public function test_plan_uses_the_shop_anchored_price_band(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->postJson('/api/design-agent/shop-signals', ['rows' => $this->shopRows()])->assertOk();

        $response = $this->actingAs($user)->postJson('/api/design-agent/plan', [
            'prompt' => 'Bộ sưu tập linen pastel',
            'size_distribution' => ['s' => 25, 'm' => 40, 'l' => 25, 'xl' => 10],
        ])->assertOk();

        $band = $response->json('brief_basis.price_bands');
        $this->assertSame('shop_data', $band['basis']);
        $this->assertGreaterThan($band['min_vnd'], $band['max_vnd']);
        $this->assertSame('shop_data', $response->json('plan.basis'));
        $this->assertGreaterThan(0, $response->json('plan.totals.revenue_vnd'));
    }

    public function test_shop_signals_replace_previous_rows(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->postJson('/api/design-agent/shop-signals', ['rows' => $this->shopRows()])->assertOk();
        $this->actingAs($user)->postJson('/api/design-agent/shop-signals', [
            'rows' => [['name' => 'Quần ống rộng', 'category' => 'Quần', 'units_sold' => 50, 'stock_on_hand' => 5, 'returns' => 1, 'price_vnd' => 480000]],
        ])->assertOk();

        $this->assertSame(1, \App\Models\ShopSignal::where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('shop_signals', ['user_id' => $user->id, 'name' => 'Áo linen tay dài']);
    }

    public function test_shop_signals_are_scoped_to_the_owner(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($owner)->postJson('/api/design-agent/shop-signals', ['rows' => $this->shopRows()])->assertOk();

        $radar = $this->actingAs($other)->postJson('/api/design-agent/radar', ['ai' => false])->assertOk();
        $radar->assertJsonPath('internal_brand_signal.data_mode', 'empty')
            ->assertJsonPath('internal_brand_signal.shop.row_count', 0);
    }

    public function test_shop_signals_validation_rejects_nonsense(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->postJson('/api/design-agent/shop-signals', ['rows' => [['name' => 'X', 'units_sold' => -5]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows.0.units_sold');

        $this->actingAs($user)
            ->postJson('/api/design-agent/shop-signals', ['rows' => [['name' => str_repeat('a', 200)]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rows.0.name');

        $this->assertSame(0, \App\Models\ShopSignal::count());
    }

    public function test_shop_signal_summary_computes_return_rate_and_sell_through(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->postJson('/api/design-agent/shop-signals', ['rows' => $this->shopRows()])->assertOk();

        $signal = $this->actingAs($user)->postJson('/api/design-agent/radar', ['ai' => false])->assertOk()
            ->json('internal_brand_signal.shop');

        $this->assertSame(5.0, (float) $signal['return_rate_pct']);
        $this->assertSame(64, (int) $signal['sell_through_pct']);
        $this->assertNotEmpty($signal['best_sellers']);
        $this->assertNotEmpty($signal['slow_movers']);
        $this->assertSame('Áo', $signal['category_demand'][0]['name']);
        $this->assertGreaterThan(0, (int) $signal['avg_price_vnd']);
    }
}
