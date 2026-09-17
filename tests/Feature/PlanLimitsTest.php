<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THỰC THI ĐẶC QUYỀN CỦA GÓI — bất biến khoá lại lỗ hổng phát hiện 2026-09-19.
 *
 * Trước đây plans.resolution_cap chỉ được trả về ở API catalog, KHÔNG chỗ nào trong pipeline kiểm
 * tra ⇒ gói Miễn phí (1K) và gói Studio (2K) cho ra ảnh giống hệt nhau; đặc quyền của gói là chữ.
 *
 * Bất biến:
 *   (a) yêu cầu vượt cap của gói bị HẠ xuống cap (không chặn công việc) và có thông báo nói rõ;
 *   (b) trong cap thì giữ nguyên, không thông báo;
 *   (c) video suy cap từ cap ảnh (1K ⇒ 720p, 2K ⇒ 1080p);
 *   (d) [Q1 — 2026-09-19] MẶC ĐỊNH nay CHẶN khi hết credit (chủ dự án đã quyết) và trả 402 CÓ CẤU
 *       TRÚC (mã lỗi + thiếu bao nhiêu + còn bao nhiêu + đường nâng cấp); tắt lại được bằng setting
 *       studio_enforce_credits=0;
 *   (e) /api/plan/status nói đúng gói/hạn mức/chi phí + danh mục gói đang mở bán.
 */
class PlanLimitsTest extends TestCase
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

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    private function lastGeneration(User $u): Generation
    {
        return Generation::where('user_id', $u->id)->latest('id')->firstOrFail();
    }

    public function test_free_plan_image_request_is_clamped_to_1k_with_a_notice(): void
    {
        $u = $this->customer();
        $this->assertSame('1K', $this->plan('free')->resolution_cap);
        // Seeder không gán gói cho tài khoản demo ⇒ phải gán tường minh, nếu không hệ thống dùng
        // mặc định toàn cục (2K) — đúng như thiết kế "chưa có gói thì giữ hành vi cũ".
        app(PlanService::class)->assign($u, $this->plan('free'));

        $r = $this->actingAs($u->fresh())->postJson('/api/generate', [
            'prompt' => 'áo sơ mi trắng', 'resolution' => '2K', 'variants' => 1,
        ])->assertOk();

        $this->assertSame('1K', $this->lastGeneration($u)->resolution, 'Ảnh của gói 1K phải bị hạ xuống 1K.');
        $this->assertStringContainsString('1K', (string) $r->json('notice'), 'Phải nói rõ vì sao ảnh không ở 2K.');
    }

    public function test_paid_plan_keeps_2k_without_notice(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('pro'));

        $r = $this->actingAs($u->fresh())->postJson('/api/generate', [
            'prompt' => 'áo sơ mi trắng', 'resolution' => '2K', 'variants' => 1,
        ])->assertOk();

        $this->assertSame('2K', $this->lastGeneration($u)->resolution, 'Gói 2K phải giữ nguyên 2K.');
        $this->assertNull($r->json('notice'), 'Không hạ cấp thì không có thông báo.');
    }

    public function test_video_resolution_cap_follows_the_plan(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('free'));

        $this->actingAs($u->fresh())->postJson('/api/video', [
            'prompt' => 'catwalk', 'resolution' => '1080', 'duration' => '5',
        ])->assertOk();

        $this->assertSame('720', $this->lastGeneration($u)->resolution, 'Gói 1K ⇒ video tối đa 720p.');

        app(PlanService::class)->assign($u->fresh(), $this->plan('studio'));
        $this->actingAs($u->fresh())->postJson('/api/video', [
            'prompt' => 'catwalk', 'resolution' => '1080', 'duration' => '5',
        ])->assertOk();

        $this->assertSame('1080', $this->lastGeneration($u)->resolution, 'Gói 2K ⇒ video được 1080p.');
    }

    public function test_credits_are_enforced_by_default_with_a_structured_402(): void
    {
        // [Q1 — 2026-09-19] Chủ dự án đã quyết BẬT chặn. Hành vi cũ ("never hard-block") là chủ ý của
        // giai đoạn chưa có thanh toán; nay khách hết credit phải được nói RÕ và có đường nâng cấp.
        $u = $this->customer();
        $u->forceFill(['credits_balance' => 0])->save();

        $r = $this->actingAs($u->fresh())
            ->postJson('/api/generate', ['prompt' => 'áo sơ mi trắng', 'variants' => 1])
            ->assertStatus(402);

        // Một câu "lỗi" chung chung là thứ làm khách bỏ đi: payload phải nói thiếu bao nhiêu, còn bao
        // nhiêu, gói nào, và nâng cấp ở đâu — giao diện dùng đúng những trường này.
        $r->assertJsonPath('code', 'out_of_credits')
            ->assertJsonPath('needed', 1)
            ->assertJsonPath('balance', 0)
            ->assertJsonPath('upgrade_url', '/bang-gia');
        $this->assertStringContainsString('credit', (string) $r->json('message'));

        $this->assertSame(0, Generation::where('user_id', $u->id)->count(), 'Bị chặn thì KHÔNG được tạo generation nào.');
        $this->assertSame(0, (int) $u->fresh()->credits_balance, 'Bị chặn thì không được trừ credit.');
    }

    public function test_enforcement_can_still_be_turned_off_by_setting(): void
    {
        // Cần đường lùi KHÔNG phải sửa mã: chủ dự án tắt cờ trong Quản trị là hệ thống về hành vi cũ.
        $u = $this->customer();
        $u->forceFill(['credits_balance' => 0])->save();
        set_setting('studio_enforce_credits', '0');

        $this->actingAs($u->fresh())
            ->postJson('/api/generate', ['prompt' => 'áo sơ mi trắng', 'variants' => 1])
            ->assertOk();

        $this->assertLessThan(0, (int) $u->fresh()->credits_balance, 'Tắt cờ ⇒ giữ hành vi cũ (không chặn).');
    }

    public function test_plan_status_endpoint_reports_limits_costs_and_catalog(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('pro'));

        $this->actingAs($u->fresh())
            ->getJson('/api/plan/status')
            ->assertOk()
            ->assertJsonPath('plan.slug', 'pro')
            ->assertJsonPath('limits.image_resolution_cap', '2K')
            ->assertJsonPath('limits.video_resolution_cap', '1080')
            ->assertJsonPath('limits.enforce_credits', true)
            ->assertJsonPath('costs.image', 1)
            ->assertJsonPath('costs.video', 10)
            ->assertJsonCount(4, 'catalog');
    }

    public function test_plan_status_requires_authentication(): void
    {
        $this->getJson('/api/plan/status')->assertStatus(401);
    }

    public function test_super_admin_is_never_locked_out_by_credit_enforcement(): void
    {
        // Đo trên production 2026-09-19: tài khoản owner đang có 0 credit ⇒ nếu chặn cả owner thì chủ dự
        // án tự khoá mình khỏi sản phẩm của mình. Việc chặn là để bảo vệ doanh thu từ KHÁCH.
        $owner = User::where('email', 'owner@fabrikai.shop')->firstOrFail();
        $owner->forceFill(['credits_balance' => 0])->save();

        $this->assertTrue($owner->isSuperAdmin());

        $this->actingAs($owner->fresh())
            ->postJson('/api/generate', ['prompt' => 'áo sơ mi trắng', 'variants' => 1])
            ->assertOk();

        $this->assertSame(1, Generation::where('user_id', $owner->id)->count(), 'Owner vẫn tạo được ảnh.');
    }
}
