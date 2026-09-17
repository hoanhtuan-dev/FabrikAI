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
