<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * YÊU CẦU NÂNG CẤP GÓI (Q2 — 2026-09-19).
 *
 * Vấn đề: hệ thống CHƯA có cổng thanh toán, nhưng POST /api/billing/subscribe lại gán được cả gói
 * trả phí ⇒ khách bấm một nút là có gói 499.000 ₫ miễn phí, không có dấu vết ai trả tiền. Test này
 * khoá lại hợp đồng mới:
 *
 *   (a) khách TỰ ĐĂNG KÝ chỉ được gói MIỄN PHÍ; gói trả phí trả 402 code=payment_required;
 *   (b) khách gửi YÊU CẦU NÂNG CẤP (mã theo dõi · số tiền chốt tại thời điểm gửi · SĐT chuẩn hoá);
 *   (c) gửi trùng ⇒ dùng lại yêu cầu đang mở (không rải rác yêu cầu cho chủ dự án);
 *   (d) CHỈ Super Admin kích hoạt được, và kích hoạt đi qua PlanService (gói + hạn + credit chu kỳ);
 *   (e) thông tin nhận tiền là MỘT nguồn, chỉ admin sửa được, chưa cấu hình thì trả rỗng (không bịa STK).
 */
class UpgradeRequestTest extends TestCase
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

    private function superAdmin(): User
    {
        return User::where('email', 'owner@fabrikai.shop')->firstOrFail();
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'plan_id' => $this->plan('pro')->id,
            'months' => 1,
            'method' => UpgradeRequest::METHOD_BANK,
            'contact_name' => 'Chị Hương',
            'contact_phone' => '0901 234 567',
            'note' => 'Cần gấp cho bộ Thu Đông',
        ], $override);
    }

    public function test_guests_cannot_send_upgrade_requests(): void
    {
        $this->postJson('/api/billing/upgrade-request', $this->payload())->assertStatus(401);
        $this->getJson('/api/billing/upgrade-request')->assertStatus(401);
        $this->assertSame(0, UpgradeRequest::count());
    }

    public function test_paid_plan_can_no_longer_be_self_activated(): void
    {
        // Đây là lỗ hổng doanh thu cũ: không có cổng thanh toán mà vẫn tự gán gói trả phí.
        $u = $this->customer();
        $pro = $this->plan('pro');

        $r = $this->actingAs($u)->postJson('/api/billing/subscribe', ['plan_id' => $pro->id])
            ->assertStatus(402);

        $r->assertJsonPath('code', 'payment_required');
        $this->assertNotSame($pro->id, $u->fresh()->plan_id, 'Gói trả phí KHÔNG được tự kích hoạt.');
    }

    public function test_free_plan_can_still_be_self_activated(): void
    {
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('pro'));
        $free = $this->plan('free');

        $this->actingAs($u->fresh())
            ->postJson('/api/billing/subscribe', ['plan_id' => $free->id])
            ->assertOk();

        $this->assertSame($free->id, $u->fresh()->plan_id);
    }

    public function test_customer_creates_a_trackable_upgrade_request(): void
    {
        $u = $this->customer();
        $pro = $this->plan('pro');

        $res = $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload(['months' => 3]))
            ->assertStatus(201);

        $res->assertJsonPath('ok', true)
            ->assertJsonPath('reused', false)
            ->assertJsonPath('request.status', UpgradeRequest::STATUS_PENDING)
            ->assertJsonPath('request.status_label', 'Chờ xử lý')
            ->assertJsonPath('request.method_label', 'Chuyển khoản ngân hàng')
            ->assertJsonPath('request.months', 3);

        $row = UpgradeRequest::firstOrFail();
        $this->assertMatchesRegularExpression('/^UP-\d{4}-\d{4}$/', $row->code, 'Mã theo dõi phải đọc được qua điện thoại.');
        $this->assertSame($u->id, $row->user_id);
        $this->assertSame($pro->id, $row->plan_id);
        $this->assertSame((int) $pro->price_vnd * 3, $row->amount_vnd, 'Số tiền chốt tại thời điểm gửi (giá có thể đổi sau).');
        $this->assertSame('0901234567', $row->contact_phone, 'SĐT được chuẩn hoá bỏ khoảng trắng.');
        $this->assertSame('Chị Hương', $row->contact_name);

        // Giá đổi SAU khi gửi ⇒ yêu cầu cũ giữ nguyên số tiền đã báo cho khách.
        $pro->forceFill(['price_vnd' => 999000])->save();
        $this->assertSame((int) 499000 * 3, $row->fresh()->amount_vnd);
    }

    public function test_sending_twice_reuses_the_open_request(): void
    {
        $u = $this->customer();

        $first = $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload())->assertStatus(201);
        $second = $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload())->assertOk();

        $second->assertJsonPath('reused', true)
            ->assertJsonPath('request.code', $first->json('request.code'));

        $this->assertSame(1, UpgradeRequest::count(), 'Không rải nhiều yêu cầu trùng cho chủ dự án.');
    }

    public function test_upgrade_request_validates_plan_months_method_and_phone(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload(['plan_id' => $this->plan('free')->id]))
            ->assertStatus(422)->assertJsonPath('code', 'plan_is_free');

        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload(['months' => 2]))
            ->assertStatus(422);

        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload(['method' => 'bitcoin']))
            ->assertStatus(422);

        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload(['contact_phone' => '123']))
            ->assertStatus(422)->assertJsonPath('code', 'phone_invalid');

        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload(['months' => 12, 'contact_phone' => '+84 901 234 567']))
            ->assertStatus(422);

        $this->assertSame(0, UpgradeRequest::count(), 'Dữ liệu sai thì không được tạo yêu cầu nào.');
    }

    public function test_status_endpoint_reports_open_request_and_payment_channels(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload())->assertStatus(201);

        $res = $this->actingAs($u)->getJson('/api/billing/upgrade-request')->assertOk();

        $res->assertJsonPath('open.status', UpgradeRequest::STATUS_PENDING)
            ->assertJsonPath('open.plan.slug', 'pro')
            ->assertJsonCount(3, 'methods')
            ->assertJsonPath('months', [1, 3, 6, 12])
            // VNPay chưa có thì phải nói thật là chưa có.
            ->assertJsonPath('payment.vnpay.available', false);
    }

    public function test_only_admin_can_read_and_update_the_queue(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload())->assertStatus(201);
        $row = UpgradeRequest::firstOrFail();

        $this->actingAs($u)->getJson('/api/admin/upgrade-requests')->assertForbidden();
        $this->actingAs($u)->postJson('/api/admin/upgrade-requests/'.$row->id, ['status' => 'contacted'])->assertForbidden();

        $this->actingAs($this->superAdmin())->getJson('/api/admin/upgrade-requests')
            ->assertOk()
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('requests.0.code', $row->code)
            ->assertJsonPath('requests.0.user.email', $u->email)
            ->assertJsonPath('requests.0.amount_label', '499.000 ₫');
    }

    public function test_super_admin_activation_grants_the_plan_and_cycle_credits(): void
    {
        $u = $this->customer();
        $pro = $this->plan('pro');
        $before = (int) $u->credits_balance;

        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload(['months' => 3]))->assertStatus(201);
        $row = UpgradeRequest::firstOrFail();

        $res = $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/upgrade-requests/'.$row->id.'/activate', ['admin_note' => 'Đã nhận chuyển khoản'])
            ->assertOk();

        $res->assertJsonPath('plan.id', $pro->id)
            ->assertJsonPath('months', 3);

        $fresh = $u->fresh();
        $this->assertSame($pro->id, $fresh->plan_id);
        $this->assertNotNull($fresh->plan_expires_at);
        // 3 tháng ⇒ hạn gói phải xa hơn 2 tháng (không phải chỉ gia hạn 1 tháng).
        $this->assertTrue($fresh->plan_expires_at->greaterThan(now()->addMonths(2)), 'Số tháng của yêu cầu phải được tôn trọng.');
        $this->assertSame($before + (int) $pro->bonus_credits + (int) $pro->credits_per_month, (int) $fresh->credits_balance);

        $row->refresh();
        $this->assertSame(UpgradeRequest::STATUS_ACTIVATED, $row->status);
        $this->assertSame($this->superAdmin()->id, $row->handled_by);
        $this->assertNotNull($row->handled_at);

        // Kích hoạt lần hai ⇒ chặn (không cấp credit hai lần).
        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/upgrade-requests/'.$row->id.'/activate')
            ->assertStatus(422)->assertJsonPath('code', 'already_activated');
    }

    public function test_plain_admin_cannot_activate_but_can_mark_contacted(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload())->assertStatus(201);
        $row = UpgradeRequest::firstOrFail();
        $admin = User::where('email', 'admin@fabrikai.shop')->firstOrFail();

        // Đánh dấu "đã liên hệ" ai cũng làm được (nghiệp vụ chăm sóc khách); KÍCH HOẠT thì không.
        $this->actingAs($admin)->postJson('/api/admin/upgrade-requests/'.$row->id, ['status' => 'contacted'])
            ->assertOk()->assertJsonPath('request.status_label', 'Đã liên hệ');

        $this->actingAs($admin)->postJson('/api/admin/upgrade-requests/'.$row->id.'/activate')->assertForbidden();
        $this->assertNotSame($this->plan('pro')->id, $u->fresh()->plan_id);
    }

    public function test_activated_request_cannot_be_moved_back_to_pending(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/billing/upgrade-request', $this->payload())->assertStatus(201);
        $row = UpgradeRequest::firstOrFail();

        $this->actingAs($this->superAdmin())->postJson('/api/admin/upgrade-requests/'.$row->id.'/activate')->assertOk();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/upgrade-requests/'.$row->id, ['status' => 'pending'])
            ->assertStatus(422)->assertJsonPath('code', 'already_activated');
    }

    public function test_payment_info_is_one_source_editable_only_by_admin(): void
    {
        $u = $this->customer();

        // Chưa cấu hình ⇒ trả RỖNG, tuyệt đối không bịa số tài khoản.
        $this->actingAs($u)->getJson('/api/billing/upgrade-request')
            ->assertOk()->assertJsonPath('payment.bank.account', '');

        $this->actingAs($u)->postJson('/api/admin/payment-info', ['bank_account' => '123'])->assertForbidden();

        $this->actingAs($this->superAdmin())->postJson('/api/admin/payment-info', [
            'bank_name' => 'Vietcombank',
            'bank_account' => '0123456789',
            'bank_holder' => 'CONG TY FABRIKAI',
            'support_phone' => '0901234567',
        ])->assertOk()->assertJsonPath('payment.bank.name', 'Vietcombank');

        // Mọi bề mặt đọc cùng một nguồn: popup trong Studio cũng thấy đúng thông tin đó.
        $this->actingAs($u)->getJson('/api/plan/status')
            ->assertOk()
            ->assertJsonPath('payment.bank.account', '0123456789')
            ->assertJsonPath('payment.support.phone', '0901234567');
    }

    public function test_admin_ui_actually_drives_the_upgrade_queue(): void
    {
        // Bất biến chống "tính năng chết" (đã gặp một lần với vòng đời duyệt ảnh): API có mà giao diện
        // không gọi thì coi như không có. Màn Quản trị PHẢI: có mục trong menu, nạp hàng đợi, hiện số
        // việc đang chờ, gửi đúng 3 lời gọi (đổi trạng thái · kích hoạt · lưu thông tin nhận tiền), và
        // CHỈ hiện nút kích hoạt cho Super Admin.
        $ui = (string) file_get_contents(resource_path('js/studio/AdminApp.vue'));

        $this->assertStringContainsString("{ id: 'upgrades'", $ui, 'Phải có mục «Yêu cầu nâng cấp» trong menu Quản trị.');
        $this->assertStringContainsString("api('/upgrade-requests'", $ui, 'Phải nạp hàng đợi từ API quản trị.');
        $this->assertStringContainsString("api('/upgrade-requests/' + row.id + '/activate'", $ui, 'Nút kích hoạt phải gọi đúng endpoint.');
        $this->assertStringContainsString("api('/payment-info', 'POST'", $ui, 'Phải lưu được thông tin nhận tiền.');
        $this->assertStringContainsString('v-if="isSuper"', $ui, 'Nút kích hoạt chỉ dành cho Super Admin.');
        $this->assertStringContainsString('pendingUpgrades', $ui, 'Menu phải hiện số yêu cầu đang chờ.');
        $this->assertStringContainsString('await loadUpgrades();', $ui, 'Phải nạp hàng đợi ngay khi mở trang Quản trị.');
        // Không tự gọi API bằng fetch thô trong màn này (giữ một đường dữ liệu qua api()).
        $this->assertStringNotContainsString("fetch('/api/admin", $ui, 'Không gọi API quản trị bằng fetch thô.');
    }
}
