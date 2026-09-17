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
        $row = null;
        foreach (explode('<tr', $html) as $tr) {
            if (str_contains($tr, 'Nhóm làm việc (ghế)')) {
                $row = $tr;
                break;
            }
        }
        $this->assertNotNull($row, 'Không tìm thấy dòng module «Nhóm làm việc (ghế)».');
        $this->assertStringContainsString('—', $row, 'Gói đã bị rút module thì phải hiện dấu —, không phải ✓.');
        $this->assertStringContainsString('✓', $row, 'Gói khác vẫn cấp module này ⇒ phải có ít nhất một dấu ✓ trong dòng.');
    }

    public function test_pricing_page_links_to_signup(): void
    {
        $this->get('/bang-gia')->assertOk()->assertSee(route('register'), false);
    }
}
