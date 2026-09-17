<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use App\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QUẢN TRỊ MODULE: BẬT/TẮT TOÀN CỤC + CẤP THEO GÓI (2026-09-19).
 *
 * Chủ dự án cần một chỗ để biến "gói đăng ký" thành công tắc cấp phát tính năng, và tắt/mở một tính năng
 * cho toàn hệ thống — không phải sửa mã, không phải deploy. Test này khoá lại:
 *   (a) màn Quản trị thấy ĐÚNG bản khai (thêm module mới là tự có dòng);
 *   (b) chỉ admin vào được;
 *   (c) ghi công tắc toàn cục: id lạ bị từ chối, dữ liệu lưu đúng;
 *   (d) ghi module cho GÓI: chuẩn hoá thứ tự, bỏ id lạ, và có hiệu lực THẬT với khách ở gói đó;
 *   (e) áp đề xuất từ bản khai;
 *   (f) form gói (thêm/sửa) lưu được module, và KHÔNG gửi 'modules' thì giữ nguyên (không xoá quyền oan);
 *   (g) giao diện Quản trị + Studio + trang giá đều nối vào API/dữ liệu này (chống "tính năng chết").
 */
class ModulesAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function superAdmin(): User
    {
        return User::where('email', 'owner@fabrikai.shop')->firstOrFail();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    public function test_admin_sees_the_whole_registry_and_the_plan_matrix(): void
    {
        $res = $this->actingAs($this->superAdmin())->getJson('/api/admin/modules')->assertOk();

        $res->assertJsonCount(count(ModuleRegistry::all()), 'modules')
            ->assertJsonCount(5, 'plans')
            ->assertJsonPath('total_modules', count(ModuleRegistry::all()));

        // Mỗi module phải nói được gói nào đang cấp nó (ma trận gói × module).
        $row = collect($res->json('modules'))->firstWhere('id', 'team_seats');
        $this->assertNotNull($row);
        $this->assertContains('pro', $row['plans_with']);
        // Và mỗi gói có danh sách module + đề xuất để bấm một nút là xong.
        $pro = collect($res->json('plans'))->firstWhere('slug', 'pro');
        $this->assertSame($this->plan('pro')->modules(), $pro['modules']);
        $this->assertSame(ModuleRegistry::suggestedForPlan('pro'), $pro['suggested']);
    }

    public function test_only_admins_can_touch_the_switches(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->getJson('/api/admin/modules')->assertForbidden();
        $this->actingAs($u)->postJson('/api/admin/modules', ['disabled' => ['look']])->assertForbidden();
        $this->actingAs($u)->putJson('/api/admin/plans/'.$this->plan('pro')->id.'/modules', ['modules' => []])->assertForbidden();
        $this->actingAs($u)->postJson('/api/admin/plans/'.$this->plan('pro')->id.'/modules/suggested')->assertForbidden();
    }

    public function test_global_switch_is_saved_and_rejects_unknown_ids(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)->postJson('/api/admin/modules', ['disabled' => ['look', 'reframe']])
            ->assertOk()
            ->assertJsonPath('disabled', ['look', 'reframe']);

        $this->assertSame(['look', 'reframe'], ModuleRegistry::disabledGlobally());

        // Id lạ ⇒ bỏ qua nhưng BÁO LẠI, và không được biến thành "tắt nhầm thứ khác".
        $this->actingAs($owner)->postJson('/api/admin/modules', ['disabled' => ['khong_ton_tai', 'look']])
            ->assertOk()->assertJsonPath('ignored', ['khong_ton_tai']);
        $this->assertSame(['look'], ModuleRegistry::disabledGlobally());

        // Bật lại hết.
        $this->actingAs($owner)->postJson('/api/admin/modules', ['disabled' => []])->assertOk();
        $this->assertSame([], ModuleRegistry::disabledGlobally());
    }

    public function test_plan_modules_are_saved_normalized_and_enforced_for_customers(): void
    {
        $owner = $this->superAdmin();
        $plan = $this->plan('pro');
        $customer = $this->customer();
        app(PlanService::class)->assign($customer, $plan);

        // Gửi lộn xộn + 1 id lạ ⇒ lưu theo thứ tự bản khai, id lạ BỎ QUA nhưng phải BÁO LẠI (không im lặng).
        // Có 'prompt' vì 'job_templates' phụ thuộc nó (bài học: bản đầu thiếu 'prompt' nên module con bị
        // khoá theo luật phụ thuộc — luật chạy đúng, kỳ vọng của test mới là chỗ sai).
        $keep = ['prompt', 'upscale', 'khong_ton_tai', 'collections', 'job_templates'];
        $res = $this->actingAs($owner)->putJson('/api/admin/plans/'.$plan->id.'/modules', ['modules' => $keep])
            ->assertOk()
            ->assertJsonPath('ignored', ['khong_ton_tai']);

        $saved = $res->json('modules');
        $this->assertSame(array_values(array_intersect(ModuleRegistry::ids(), $keep)), $saved, 'Thứ tự phải theo bản khai, id lạ bị bỏ.');
        $this->assertSame(4, $res->json('modules_count'));

        // Hiệu lực THẬT: khách ở gói này mất tính năng không được tick…
        $fresh = $customer->fresh();
        $this->actingAs($fresh)->getJson('/api/job-templates')->assertOk();          // có trong danh sách
        $this->actingAs($fresh)->getJson('/api/team')->assertStatus(403)             // 'team_seats' bị rút
            ->assertJsonPath('code', 'module_locked');

        // …và có lại ngay khi được tick.
        $this->actingAs($owner)->putJson('/api/admin/plans/'.$plan->id.'/modules', [
            'modules' => array_merge($saved, ['team_seats']),
        ])->assertOk();
        $this->actingAs($customer->fresh())->getJson('/api/team')->assertOk();
    }

    public function test_apply_suggestion_fills_a_plan_from_the_registry(): void
    {
        $owner = $this->superAdmin();
        $plan = $this->plan('studio');
        $plan->forceFill(['modules' => ['collections']])->save();

        $this->actingAs($owner)->postJson('/api/admin/plans/'.$plan->id.'/modules/suggested')
            ->assertOk()
            ->assertJsonPath('modules', ModuleRegistry::suggestedForPlan('studio'));

        $this->assertSame(ModuleRegistry::suggestedForPlan('studio'), $plan->fresh()->modules());
    }

    public function test_plan_form_can_carry_modules_without_wiping_them_by_accident(): void
    {
        $owner = $this->superAdmin();
        $plan = $this->plan('starter');
        $original = $plan->modules();

        // Form KHÔNG gửi 'modules' (ví dụ đổi mỗi giá) ⇒ giữ nguyên quyền của gói.
        $this->actingAs($owner)->putJson('/api/admin/plans/'.$plan->id, [
            'name' => $plan->name, 'slug' => $plan->slug, 'price_vnd' => 250000, 'credits_per_month' => 150,
        ])->assertOk();
        $this->assertSame($original, $plan->fresh()->modules(), 'Không gửi modules thì không được xoá quyền.');

        // Form CÓ gửi 'modules' ⇒ lưu đúng danh sách đã chuẩn hoá.
        $this->actingAs($owner)->putJson('/api/admin/plans/'.$plan->id, [
            'name' => $plan->name, 'slug' => $plan->slug, 'price_vnd' => 250000, 'credits_per_month' => 150,
            'modules' => ['collections', 'upscale'],
        ])->assertOk();
        $this->assertSame(['collections', 'upscale'], $plan->fresh()->modules());
    }

    public function test_new_module_shows_up_as_not_yet_granted(): void
    {
        // Bất biến MỞ RỘNG QUY MÔ: thêm module vào bản khai thì nó phải xuất hiện ngay ở màn Quản trị và ở
        // danh sách "chưa gói nào cấp" — quyền theo gói là dữ liệu tường minh nên KHÔNG tự cấp cho ai,
        // nhưng cũng KHÔNG được nằm im không ai biết.
        $pro = $this->plan('pro');
        $before = $pro->modules();

        // Giả lập "module mới": có trong bản khai (đã có sẵn) nhưng chưa gói nào cấp.
        Plan::query()->get()->each(function (Plan $p) {
            $p->forceFill(['modules' => array_values(array_diff($p->modules(), ['team_seats']))])->save();
        });

        $res = $this->actingAs($this->superAdmin())->getJson('/api/admin/modules')->assertOk();
        $this->assertContains('team_seats', $res->json('ungranted'));

        $planRow = collect($res->json('plans'))->firstWhere('slug', 'studio');
        $this->assertContains('team_seats', $planRow['missing_suggested'], 'Gói nên có module này ⇒ phải hiện là thiếu so với đề xuất.');

        // Bấm "áp đề xuất" ⇒ hết thiếu, và hết cảnh báo chưa-gói-nào-cấp.
        $this->actingAs($this->superAdmin())->postJson('/api/admin/plans/'.$this->plan('studio')->id.'/modules/suggested')->assertOk();
        $again = $this->actingAs($this->superAdmin())->getJson('/api/admin/modules')->assertOk();
        $this->assertNotContains('team_seats', collect($again->json('plans'))->firstWhere('slug', 'studio')['missing_suggested']);
        $this->assertNotContains('team_seats', $again->json('ungranted'));

        $pro->forceFill(['modules' => $before])->save();
    }

    public function test_no_blade_comment_syntax_leaks_into_vue_files(): void
    {
        // Bài học thật: chú thích kiểu Blade đặt trong file .vue bị Vue hiểu là interpolation ⇒ build đỏ
        // ("Invalid left-hand side in prefix operation"). Test này bắt lỗi đó TRƯỚC khi build.
        $bladeComment = '{'.'{--';

        foreach (['AdminApp.vue', 'StudioApp.vue', 'store.js'] as $file) {
            $code = (string) file_get_contents(resource_path('js/studio/'.$file));
            $this->assertStringNotContainsString($bladeComment, $code, 'File '.$file.' dùng chú thích Blade — Vue/JS phải dùng HTML comment hoặc //.');
        }
    }

    public function test_manual_feature_notes_never_grant_anything_and_plan_display_follows_the_switch(): void
    {
        // Yêu cầu chủ dự án: gói cước ĐỒNG BỘ với "gói cấp tính năng nào", nhưng owner vẫn nhập tay được chữ
        // hiển thị cho khách. Phần nhập tay KHÔNG phải công tắc: thêm/xoá ghi chú không đổi quyền.
        $owner = $this->superAdmin();
        $plan = $this->plan('pro');
        $customer = $this->customer();
        app(PlanService::class)->assign($customer, $plan);

        $before = $plan->modules();

        // (a) Ghi chú nhập tay: KHÔNG cấp thêm tính năng nào.
        $this->actingAs($owner)->putJson('/api/admin/plans/'.$plan->id, [
            'name' => $plan->name, 'slug' => $plan->slug, 'price_vnd' => $plan->price_vnd,
            'credits_per_month' => $plan->credits_per_month,
            'features' => ['Hỗ trợ ưu tiên 1:1', 'Hoá đơn theo vụ'],
            'modules' => $before,
        ])->assertOk();

        $wasLocked = ! module_allowed($customer->fresh(), 'team_seats');
        $plan->forceFill(['features' => ['Ghi chú mới toanh']])->save();
        $this->assertSame($wasLocked, ! module_allowed($customer->fresh(), 'team_seats'), 'Ghi chú nhập tay không được đổi quyền.');
        $this->assertSame($before, $plan->fresh()->modules(), 'Ghi chú nhập tay không được đụng vào công tắc.');

        // (b) Tên tính năng hiển thị SUY TỪ công tắc: rút một module ⇒ tên đó biến mất khỏi danh sách hiển thị.
        $plan->forceFill(['modules' => array_values(array_diff($before, ['upscale']))])->save();
        $this->assertNotContains('Upscale', $plan->fresh()->moduleNames());
        $this->assertSame($plan->fresh()->modulesCount(), count($plan->fresh()->moduleNames()));
        $this->assertArrayHasKey('Chỉnh ảnh', $plan->fresh()->modulesByGroup());

        // (c) mapPlan trả đủ dữ liệu cho màn Quản trị (số tính năng + tên + ghi chú).
        $row = collect($this->actingAs($owner)->getJson('/api/admin/plans')->assertOk()->json('plans'))
            ->firstWhere('slug', 'pro');
        $this->assertSame($plan->fresh()->modules(), $row['modules']);
        $this->assertSame($plan->fresh()->moduleNames(), $row['module_names']);
        $this->assertSame(['Ghi chú mới toanh'], $row['manual_features']);
        $this->assertArrayHasKey('modules_by_group', $row);

        $plan->forceFill(['modules' => $before, 'features' => []])->save();
    }

    public function test_changing_entitlements_reports_impact_for_confirmation(): void
    {
        // Bài học từ chính lần chủ dự án bấm «Áp đề xuất»: thao tác đúng chức năng nhưng giao diện không nói
        // trước hậu quả. Nay màn Quản trị phải biết SỐ NGƯỜI DÙNG của từng gói để hỏi xác nhận kèm ảnh hưởng.
        $u = $this->customer();
        app(PlanService::class)->assign($u, $this->plan('pro'));

        $res = $this->actingAs($this->superAdmin())->getJson('/api/admin/modules')->assertOk();
        $pro = collect($res->json('plans'))->firstWhere('slug', 'pro');
        $this->assertSame(1, $pro['users_count'], 'Phải trả số người dùng của gói để tính ảnh hưởng.');
        $free = collect($res->json('plans'))->firstWhere('slug', 'free');
        $this->assertSame(0, $free['users_count']);

        // Giao diện: có hàm xác nhận ảnh hưởng + nút khôi phục 1 cú bấm, và đều gọi đúng API.
        $admin = (string) file_get_contents(resource_path('js/studio/AdminApp.vue'));
        $this->assertStringContainsString('function confirmRevokeIfNeeded(', $admin, 'Thiếu bước xác nhận ảnh hưởng.');
        $this->assertStringContainsString('sẽ RÚT', $admin, 'Câu xác nhận phải nói rõ SẼ RÚT tính năng.');
        $this->assertStringContainsString('người dùng. Thao tác sẽ', $admin, 'Câu xác nhận phải nêu số người dùng bị ảnh hưởng.');
        $this->assertStringContainsString('async function grantAllModules(', $admin, 'Thiếu đường khôi phục 1 cú bấm.');
        $this->assertStringContainsString('Cấp tất cả', $admin);
        $this->assertStringContainsString('askConfirm(', $admin, 'Phải dùng hộp xác nhận chuẩn của trang Quản trị.');
    }

    public function test_admin_and_studio_surfaces_are_wired_to_the_registry(): void
    {
        // Chống "tính năng chết": API có mà giao diện không gọi thì chủ dự án không dùng được công tắc.
        $admin = (string) file_get_contents(resource_path('js/studio/AdminApp.vue'));
        $this->assertStringContainsString("{ id: 'modules'", $admin, 'Thiếu mục «Tính năng & gói» trong Quản trị.');
        $this->assertStringContainsString("api('/modules'", $admin, 'Phải nạp danh mục module.');
        $this->assertStringContainsString("api('/modules', 'POST'", $admin, 'Phải lưu công tắc toàn cục.');
        $this->assertStringContainsString("'/plans/' + plan.id + '/modules'", $admin, 'Phải lưu module cho gói.');
        $this->assertStringContainsString("'/modules/suggested'", $admin, 'Phải áp được đề xuất từ bản khai.');

        // Studio: hiện ổ khoá + mời nâng cấp cho module bị khoá.
        $app = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
        $this->assertStringContainsString('store.moduleLocked(a.id)', $app, 'Thanh công cụ phải biết mục nào bị khoá.');
        $this->assertStringContainsString('openUpgradeFor(', $app, 'Bấm vào mục bị khoá phải mời nâng cấp.');
        $this->assertStringContainsString('lockedModules', $app, 'Popup gói phải liệt kê tính năng còn thiếu.');

        $store = (string) file_get_contents(resource_path('js/studio/store.js'));
        $this->assertStringContainsString('setModuleAccess(', $store, 'Store phải nhận quyền module từ máy chủ.');
        $this->assertStringContainsString("data.code === 'module_locked'", $store, '403 module_locked phải mở bảng nâng cấp.');

        // Trang giá: bảng tính năng theo gói suy từ bản khai (không viết tay).
        $pricing = (string) file_get_contents(resource_path('views/pricing.blade.php'));
        $this->assertStringContainsString('$moduleGroups', $pricing, 'Bảng tính năng phải sinh từ registry.');
        $this->assertStringContainsString('$planModules', $pricing, 'Cột gói phải đọc plans.modules.');
    }
}
