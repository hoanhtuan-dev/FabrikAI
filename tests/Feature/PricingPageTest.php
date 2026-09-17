<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TRANG GIÁ CÔNG KHAI (/bang-gia) — bất biến khoá lỗ hổng phát hiện 2026-09-19.
 *
 * Trước đây khách CHƯA đăng nhập không có cách nào xem gói: không view pricing, trang đăng ký
 * không nhắc gói nào, và toàn bộ UI gói nằm sau đăng nhập ⇒ phải tạo tài khoản mới biết giá.
 *
 * Bất biến:
 *   (a) khách xem được, không cần đăng nhập;
 *   (b) giá/credit trên trang LUÔN lấy từ bảng plans đang mở bán — đổi DB là trang đổi theo,
 *       không có con số nào ghi cứng trong view (chống "bảng giá mẫu" lệch thực tế);
 *   (c) gói đang ẨN không được lộ ra ngoài;
 *   (d) nói thật về thanh toán (chưa có cổng trực tuyến).
 */
class PricingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_guest_can_open_the_pricing_page(): void
    {
        $this->get('/bang-gia')->assertOk()->assertSee('Các gói đang mở bán', false);
    }

    public function test_page_shows_every_active_plan_with_live_numbers(): void
    {
        $pro = Plan::where('slug', 'pro')->firstOrFail();
        $pro->update(['price_vnd' => 555000, 'credits_per_month' => 999]);

        $res = $this->get('/bang-gia')->assertOk();

        foreach (Plan::where('is_active', true)->get() as $plan) {
            $res->assertSee($plan->name, false);
        }

        $res->assertSee('555.000', false);
        $res->assertSee('999', false);
    }

    public function test_hidden_plan_is_not_exposed(): void
    {
        $hidden = Plan::where('slug', 'studio')->firstOrFail();
        $hidden->update(['is_active' => false, 'name' => 'Gói BÍ MẬT 2027']);

        $this->get('/bang-gia')->assertOk()->assertDontSee('Gói BÍ MẬT 2027', false);
    }

    public function test_page_is_honest_about_payment_and_personas(): void
    {
        $res = $this->get('/bang-gia')->assertOk();

        // [Q2 — 2026-09-19] Trang giá nay nói được BƯỚC TIẾP THEO (gửi yêu cầu → mã → chuyển khoản →
        // kích hoạt) và vẫn nói thật là cổng thanh toán trực tuyến chưa mở.
        $res->assertSee('Yêu cầu nâng cấp', false);
        $res->assertSee('MÃ YÊU CẦU', false);
        $res->assertSee('chưa mở', false);
        $res->assertSee('Nhà thiết kế thời trang', false);
        $res->assertSee('Chủ doanh nghiệp', false);
        $res->assertSee('Chủ xưởng may', false);
    }

    public function test_pricing_page_shows_the_module_matrix_from_the_registry(): void
    {
        // [Modules 2026-09-26] Bảng "tính năng nào có ở gói nào" phải SINH TỪ bản khai module + plans.modules:
        // thêm tính năng mới là bảng tự có dòng, đổi quyền trong Quản trị là trang giá đổi theo.
        $plan = Plan::where('slug', 'pro')->firstOrFail();
        $plan->forceFill(['modules' => array_values(array_diff($plan->modules(), ['team_seats']))])->save();

        $html = $this->get('/bang-gia')->assertOk()->getContent();

        $this->assertStringContainsString('Tính năng nào có ở gói nào', $html);
        foreach (['collections' => 'Bộ sưu tập', 'team_seats' => 'Nhóm làm việc'] as $id => $name) {
            $this->assertStringContainsString($name, $html, 'Thiếu module '.$id.' trong bảng tính năng.');
        }

        // Gói 'pro' vừa bị rút 'team_seats' ⇒ trong bảng, dòng đó phải là dấu "—" ở cột Chuyên nghiệp.
        // Khoanh vùng BẢNG trước rồi mới tìm dòng: tên module còn xuất hiện ở thẻ gói phía trên (danh sách
        // tính năng suy từ quyền), nên quét cả trang sẽ bắt nhầm.
        $matrix = substr($html, (int) strpos($html, 'Tính năng nào có ở gói nào'));
        $row = null;
        foreach (explode('<tr', $matrix) as $tr) {
            if (str_contains($tr, 'Nhóm làm việc (ghế)')) {
                $row = $tr;
                break;
            }
        }
        $this->assertNotNull($row, 'Không tìm thấy dòng module «Nhóm làm việc (ghế)».');
        $this->assertStringContainsString('—', $row, 'Gói đã bị rút module thì phải hiện dấu —, không phải ✓.');
        $this->assertStringContainsString('✓', $row, 'Gói khác vẫn cấp module này ⇒ phải có ít nhất một dấu ✓ trong dòng.');
    }

    public function test_plan_card_shows_capabilities_from_the_switch_and_manual_notes_separately(): void
    {
        // Yêu cầu chủ dự án: GÓI CƯỚC phải đồng bộ với "gói cấp tính năng nào", ĐỒNG THỜI owner vẫn nhập
        // tay được phần chữ hiển thị cho khách — phần nhập tay KHÔNG phải công tắc.
        $plan = Plan::where('slug', 'pro')->firstOrFail();
        $plan->forceFill([
            'modules' => ['collections', 'upscale'],
            'features' => ['Hỗ trợ ưu tiên 1:1', 'Hoá đơn theo vụ'],
        ])->save();

        $html = $this->get('/bang-gia')->assertOk()->getContent();

        // (a) Danh sách tính năng trong thẻ gói suy TỪ quyền: có 2 tính năng đã tick…
        $this->assertStringContainsString('Có trong gói', $html);
        $this->assertStringContainsString('2/'.count(\App\Support\ModuleRegistry::all()).' tính năng', $html);
        $card = substr($html, (int) strpos($html, 'id="goi-pro"'));
        $card = substr($card, 0, (int) strpos($card, 'id="goi-', 5) ?: 6000);
        $this->assertStringContainsString('Bộ sưu tập', $card, 'Tên tính năng phải lấy từ bản khai module.');
        $this->assertStringContainsString('Upscale', $card);
        // …và KHÔNG chứa tính năng chưa được tick (vd Kịch bản quay / video).
        $this->assertStringNotContainsString('Kịch bản quay', $card, 'Tính năng chưa cấp không được quảng cáo trong thẻ gói.');

        // (b) Ghi chú nhập tay vẫn hiển thị, ở khối riêng, và không cấp quyền gì.
        $this->assertStringContainsString('Thông tin thêm', $html);
        $this->assertStringContainsString('Hỗ trợ ưu tiên 1:1', $html);
        $this->assertStringContainsString('Hoá đơn theo vụ', $html);

        $user = \App\Models\User::where('email', 'user@fabrikai.shop')->firstOrFail();
        app(\App\Services\PlanService::class)->assign($user, $plan);
        $this->assertFalse(module_allowed($user->fresh(), 'director'), 'Ghi chú nhập tay KHÔNG được cấp quyền.');
        $this->assertSame(['collections', 'upscale'], $plan->fresh()->modules(), 'Ghi chú không được đụng vào công tắc.');
    }

    public function test_pricing_page_links_to_signup(): void
    {
        $this->get('/bang-gia')->assertOk()->assertSee(route('register'), false);
    }
}
