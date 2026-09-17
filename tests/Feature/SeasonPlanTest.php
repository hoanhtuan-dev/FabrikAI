<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GÓI THEO MÙA VỤ / XƯỞNG (Q3 — 2026-09-19).
 *
 * Vấn đề: chủ xưởng may và chủ doanh nghiệp thời trang mua theo VỤ (một bộ sưu tập / một đơn lớn),
 * không mua theo tháng. Bán theo tháng bắt họ tự quy đổi và tự đoán có đủ credit cho cả vụ hay không.
 *
 * Bất biến khoá ở đây:
 *   (a) gói vụ có `unit_months = 3` và `cycle_months = 3` — mua 1 VỤ là hạn +3 tháng, KHÔNG phải +1;
 *   (b) credit của cả vụ cấp MỘT LẦN (một bể dùng cho cả vụ), không nhỏ giọt theo tháng;
 *   (c) mốc mua của từng gói do CHÍNH gói khai báo (`plans.units`): gói tháng [1,3,6,12], gói vụ [1,2,4];
 *   (d) số tiền = giá MỘT đơn vị × số đơn vị (mua 2 vụ = 2 lần giá, không phải ×6);
 *   (e) gói vụ chỉ được TẠO khi chưa có — migration không bao giờ ghi đè giá chủ dự án đã chỉnh;
 *   (f) nhãn hiển thị đúng đơn vị ("3.000 credit/vụ", "3.290.000 ₫/vụ") ở mọi bề mặt.
 */
class SeasonPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function season(): Plan
    {
        return Plan::where('slug', 'factory_season')->firstOrFail();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    public function test_the_season_plan_exists_with_season_units(): void
    {
        $plan = $this->season();

        $this->assertSame(3, $plan->unitMonths(), 'Một vụ = 3 tháng.');
        $this->assertSame(3, $plan->cycleMonths(), 'Credit cấp theo cả vụ (3 tháng).');
        $this->assertSame('vụ', $plan->unitLabel());
        $this->assertTrue($plan->isSeasonal());
        $this->assertSame([1, 2, 4], $plan->allowedUnits(), 'Mua được 1 · 2 · 4 vụ.');
        $this->assertSame(6, $plan->monthsFor(2), '2 vụ = 6 tháng.');
        $this->assertSame((int) $plan->price_vnd * 2, $plan->amountFor(2), '2 vụ = 2 lần giá (không nhân theo tháng).');
        $this->assertStringContainsString('credit/vụ', $plan->creditsLabel());
        $this->assertTrue($plan->is_active, 'Gói vụ phải đang mở bán thì khách mới thấy.');
    }

    public function test_buying_one_season_extends_three_months_and_grants_credits_once(): void
    {
        $u = $this->customer();
        $plan = $this->season();
        $before = (int) $u->credits_balance;

        app(PlanService::class)->assign($u, $plan, 1);

        $fresh = $u->fresh();
        $this->assertSame($plan->id, $fresh->plan_id);
        // 1 vụ = 3 tháng (không phải 1 tháng như trước khi có Q3).
        $this->assertTrue($fresh->plan_expires_at->greaterThan(now()->addMonths(2)), 'Hạn gói phải xa hơn 2 tháng.');
        $this->assertTrue($fresh->plan_expires_at->lessThan(now()->addMonths(4)), 'Và không quá 4 tháng (đúng ~3 tháng).');

        // Credit của cả vụ cấp MỘT LẦN: bonus lần đầu + credit của kỳ (không nhân 3).
        $this->assertSame(
            $before + (int) $plan->bonus_credits + (int) $plan->credits_per_month,
            (int) $fresh->credits_balance,
        );

        // Chạy lại việc cấp credit trong CÙNG vụ ⇒ không cấp thêm (một bể cho cả vụ).
        app(PlanService::class)->syncCycleCredits($fresh);
        $this->assertSame(
            $before + (int) $plan->bonus_credits + (int) $plan->credits_per_month,
            (int) $fresh->fresh()->credits_balance,
            'Trong cùng một vụ KHÔNG cấp credit thêm.',
        );
        $this->assertSame(1, CreditTransaction::where('user_id', $u->id)->where('type', CreditTransaction::TYPE_PLAN_GRANT)->count());
    }

    public function test_renewing_for_a_second_season_grants_the_next_season_credits(): void
    {
        $u = $this->customer();
        $plan = $this->season();

        app(PlanService::class)->assign($u, $plan, 1);
        $first = (int) $u->fresh()->credits_balance;

        // Gia hạn thêm 1 vụ nữa ⇒ hạn cộng dồn và credit của vụ MỚI được cấp.
        app(PlanService::class)->assign($u->fresh(), $plan, 1);

        $fresh = $u->fresh();
        $this->assertSame($first + (int) $plan->credits_per_month, (int) $fresh->credits_balance, 'Vụ thứ hai có credit riêng.');
        $this->assertTrue($fresh->plan_expires_at->greaterThan(now()->addMonths(5)), 'Hạn gói cộng dồn 2 vụ (~6 tháng).');
    }

    public function test_upgrade_request_uses_units_and_prices_per_season(): void
    {
        $u = $this->customer();
        $plan = $this->season();

        $res = $this->actingAs($u)->postJson('/api/billing/upgrade-request', [
            'plan_id' => $plan->id,
            'units' => 2,
            'method' => UpgradeRequest::METHOD_BANK,
            'contact_phone' => '0901234567',
        ])->assertStatus(201);

        $res->assertJsonPath('request.units', 2)
            ->assertJsonPath('request.months', 6)
            ->assertJsonPath('request.unit_label', 'vụ');

        $row = UpgradeRequest::firstOrFail();
        $this->assertSame((int) $plan->price_vnd * 2, $row->amount_vnd, '2 vụ = 2 lần giá gói.');
        $this->assertSame(6, $row->months);
    }

    public function test_units_must_match_what_the_plan_sells(): void
    {
        $u = $this->customer();

        // Gói vụ KHÔNG bán lẻ theo tháng: xin 3 đơn vị (3 vụ) là mốc không có trong [1,2,4].
        $this->actingAs($u)->postJson('/api/billing/upgrade-request', [
            'plan_id' => $this->season()->id, 'units' => 3, 'method' => 'bank_transfer', 'contact_phone' => '0901234567',
        ])->assertStatus(422)->assertJsonPath('code', 'units_invalid');

        // Gói tháng KHÔNG bán theo 2 tháng (mốc hợp lệ là 1/3/6/12) — máy chủ nói rõ mốc nào mua được.
        $r = $this->actingAs($u)->postJson('/api/billing/upgrade-request', [
            'plan_id' => $this->season()->id, 'units' => 7, 'method' => 'bank_transfer', 'contact_phone' => '0901234567',
        ])->assertStatus(422);
        $r->assertJsonPath('allowed_units', [1, 2, 4]);

        $this->assertSame(0, UpgradeRequest::count());
    }

    public function test_super_admin_activation_honours_the_units_bought(): void
    {
        $u = $this->customer();
        $plan = $this->season();
        $owner = User::where('email', 'owner@fabrikai.shop')->firstOrFail();

        $this->actingAs($u)->postJson('/api/billing/upgrade-request', [
            'plan_id' => $plan->id, 'units' => 2, 'method' => 'bank_transfer', 'contact_phone' => '0901234567',
        ])->assertStatus(201);
        $row = UpgradeRequest::firstOrFail();

        $this->actingAs($owner)->postJson('/api/admin/upgrade-requests/'.$row->id.'/activate')
            ->assertOk()
            ->assertJsonPath('units', 2)
            ->assertJsonPath('months', 6);

        $fresh = $u->fresh();
        $this->assertSame($plan->id, $fresh->plan_id);
        $this->assertTrue($fresh->plan_expires_at->greaterThan(now()->addMonths(5)), '2 vụ ⇒ hạn ~6 tháng.');
    }

    public function test_catalog_and_pricing_expose_the_season_unit(): void
    {
        $plan = $this->season();

        $catalog = $this->getJson('/api/billing/plans')->assertOk();
        $row = collect($catalog->json('plans'))->firstWhere('slug', 'factory_season');
        $this->assertNotNull($row, 'Trang giá/Studio phải thấy gói vụ.');
        $this->assertSame('vụ', $row['unit_label']);
        $this->assertSame([1, 2, 4], $row['units']);
        $this->assertTrue($row['is_seasonal']);
        $this->assertStringContainsString('₫/vụ', $row['price_per_unit_label']);
        $this->assertStringContainsString('credit/vụ', $row['credits_label']);

        // Trang giá công khai phải nói "mỗi vụ", không nói "mỗi tháng" cho gói vụ.
        $html = $this->get('/bang-gia')->assertOk()->getContent();
        $this->assertStringContainsString('mỗi vụ (3 tháng)', $html);
        $this->assertStringContainsString('Xưởng theo vụ', $html);
        $this->assertStringContainsString(number_format((int) $plan->credits_per_month, 0, ',', '.'), $html);
    }

    public function test_studio_plan_status_exposes_units_for_the_upgrade_form(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->season(), 1);

        $res = $this->actingAs($u->fresh())->getJson('/api/plan/status')->assertOk();

        $res->assertJsonPath('plan.unit_label', 'vụ')
            ->assertJsonPath('plan.is_seasonal', true);

        $row = collect($res->json('catalog'))->firstWhere('slug', 'factory_season');
        $this->assertSame([1, 2, 4], $row['units'], 'Form nâng cấp đọc mốc mua theo VỤ từ chính gói.');

        // Giao diện phải dùng đơn vị của gói, không hardcode "tháng".
        $app = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
        $this->assertStringContainsString('upgradeUnitLabel', $app, 'Form phải hiển thị nhãn đơn vị của gói.');
        $this->assertStringContainsString('p.credits_label', $app, 'Danh mục gói phải dùng nhãn credit theo đơn vị.');
    }
}
