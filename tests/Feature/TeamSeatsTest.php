<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Project;
use App\Models\User;
use App\Services\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SỐ GHẾ THEO GÓI — NHÓM LÀM VIỆC (Q4 — 2026-09-19).
 *
 * Vấn đề: chủ doanh nghiệp/studio không làm một mình. Trước đây mỗi tài khoản là một người dùng riêng:
 * muốn 3 người cùng làm một bộ sưu tập thì phải mua 3 gói, mỗi người một bể credit, bộ sưu tập của
 * người này không hiện với người kia.
 *
 * Bất biến khoá ở đây:
 *   (a) số ghế do GÓI quyết định (Miễn phí 1 · Khởi nghiệp 1 · Chuyên nghiệp 3 · Studio 10 · Xưởng 5);
 *   (b) mời thành viên tạo TÀI KHOẢN MỚI (không chiếm tài khoản người khác), trả mật khẩu tạm MỘT LẦN;
 *   (c) hết ghế ⇒ 422 kèm câu nói rõ phải nâng cấp gói;
 *   (d) thành viên tiêu CREDIT CỦA CHỦ NHÓM và sổ cái ghi đúng người bị trừ tiền, còn ảnh ghi rõ ai tạo;
 *   (e) thành viên thấy & làm việc trên BỘ SƯU TẬP của chủ nhóm, nhưng KHÔNG xoá/đổi trạng thái/đổi gói;
 *   (f) bỏ ghế ⇒ tài khoản trở lại độc lập, ảnh đã tạo vẫn còn.
 */
class TeamSeatsTest extends TestCase
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

    private function pro(): Plan
    {
        return Plan::where('slug', 'pro')->firstOrFail();
    }

    private function giveProSeats(User $u): void
    {
        app(\App\Services\PlanService::class)->assign($u, $this->pro());
    }

    public function test_seats_come_from_the_plan(): void
    {
        $this->assertSame(1, Plan::where('slug', 'free')->firstOrFail()->seats());
        $this->assertSame(1, Plan::where('slug', 'starter')->firstOrFail()->seats());
        $this->assertSame(3, $this->pro()->seats());
        $this->assertSame(10, Plan::where('slug', 'studio')->firstOrFail()->seats());
        $this->assertSame(5, Plan::where('slug', 'factory_season')->firstOrFail()->seats());
        $this->assertSame('3 người', $this->pro()->seatsLabel());

        // Trang giá/Studio phải thấy số ghế để khách doanh nghiệp so sánh được.
        $row = collect($this->getJson('/api/billing/plans')->assertOk()->json('plans'))->firstWhere('slug', 'pro');
        $this->assertSame(3, $row['seats']);
        $this->assertSame('3 người', $row['seats_label']);
    }

    public function test_owner_can_invite_members_up_to_the_seat_limit(): void
    {
        $owner = $this->customer();
        $this->giveProSeats($owner);

        $this->assertSame(3, app(TeamService::class)->seatLimit($owner->fresh()));
        $this->assertSame(2, app(TeamService::class)->remainingSeats($owner->fresh()));

        $first = $this->actingAs($owner->fresh())->postJson('/api/team/members', [
            'email' => 'nhanvien1@shop.vn', 'name' => 'Nhân viên 1', 'phone' => '0901111111',
        ])->assertStatus(201);

        $first->assertJsonPath('member.name', 'Nhân viên 1')
            ->assertJsonPath('seats.used', 2)
            ->assertJsonPath('seats.limit', 3);
        $this->assertNotEmpty($first->json('temp_password'), 'Mật khẩu tạm trả về đúng một lần để chủ nhóm gửi cho nhân viên.');

        // Người thứ hai vừa đủ 3 ghế.
        $this->actingAs($owner->fresh())->postJson('/api/team/members', ['email' => 'nhanvien2@shop.vn'])
            ->assertStatus(201)->assertJsonPath('seats.remaining', 0);

        // Người thứ ba ⇒ HẾT GHẾ, câu trả lời phải nói rõ phải nâng cấp gói.
        $res = $this->actingAs($owner->fresh())->postJson('/api/team/members', ['email' => 'nhanvien3@shop.vn'])
            ->assertStatus(422);
        $res->assertJsonPath('code', 'invite_failed');
        $this->assertStringContainsString('ghế', (string) $res->json('message'));
        $this->assertSame(2, User::where('team_owner_id', $owner->id)->count());
    }

    public function test_invite_creates_a_new_account_and_never_hijacks_an_existing_one(): void
    {
        $owner = $this->customer();
        $this->giveProSeats($owner);
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        app(\App\Services\PlanService::class)->assign($other, $this->pro());

        // Email đã có tài khoản ⇒ từ chối (không âm thầm kéo tài khoản người khác vào nhóm mình).
        $this->actingAs($owner->fresh())
            ->postJson('/api/team/members', ['email' => $other->email])
            ->assertStatus(422)->assertJsonPath('code', 'invite_failed');

        $this->assertNull($other->fresh()->team_owner_id);
        $this->assertSame($this->pro()->id, $other->fresh()->plan_id, 'Không được đụng vào gói của người khác.');
    }

    public function test_member_spends_the_owners_credits_and_images_stay_attributed(): void
    {
        $owner = $this->customer();
        $this->giveProSeats($owner);
        $owner->forceFill(['credits_balance' => 50])->save();

        $created = $this->actingAs($owner->fresh())
            ->postJson('/api/team/members', ['email' => 'thietke@shop.vn', 'name' => 'Bé Thiết Kế'])
            ->assertStatus(201);
        $memberId = $created->json('member.id');
        $member = User::findOrFail($memberId);
        $this->assertSame($owner->id, $member->team_owner_id);
        $this->assertSame(100, (int) $member->credits_balance, 'Tài khoản mới vẫn có credit dùng thử riêng, nhưng KHÔNG được dùng khi làm việc cho nhóm.');

        // Thành viên tạo ảnh ⇒ trừ credit của CHỦ NHÓM.
        $this->actingAs($member)->postJson('/api/generate', ['prompt' => 'áo sơ mi linen', 'variants' => 1])->assertOk();

        $this->assertSame(49, (int) $owner->fresh()->credits_balance, 'Chủ nhóm bị trừ credit.');
        $this->assertSame(100, (int) $member->fresh()->credits_balance, 'Credit riêng của thành viên không bị đụng.');
        $this->assertSame(1, \App\Models\Generation::where('user_id', $member->id)->count(), 'Ảnh ghi rõ AI tạo (thành viên).');
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $owner->id, 'type' => 'spend']);
    }

    public function test_member_shares_the_owners_collections_but_cannot_delete_them(): void
    {
        $owner = $this->customer();
        $this->giveProSeats($owner);
        $project = $owner->projects()->create(['name' => 'Thu Đông 2026']);

        $member = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $member->forceFill(['team_owner_id' => $owner->id])->save();

        // Thấy trong danh sách + mở được + xuất gói được (cả nhóm làm chung một bộ sưu tập).
        $list = $this->actingAs($member)->getJson('/api/projects')->assertOk();
        $this->assertContains($project->id, collect($list->json('items'))->pluck('id')->all(), 'Thành viên phải thấy bộ sưu tập của chủ nhóm.');
        $this->actingAs($member)->getJson('/api/projects/'.$project->id)->assertOk();
        $this->actingAs($member)->getJson('/api/projects/'.$project->id.'/stats')->assertOk();

        // Nhưng KHÔNG được xoá / đổi trạng thái / sửa "vỏ" bộ sưu tập — việc của chủ nhóm.
        $this->actingAs($member)->deleteJson('/api/projects/'.$project->id)->assertForbidden();
        $this->actingAs($member)->postJson('/api/projects/'.$project->id.'/transition', ['to' => 'in_progress'])->assertForbidden();
        $this->actingAs($member)->putJson('/api/projects/'.$project->id, ['name' => 'Đổi tên'])->assertForbidden();
        $this->assertSame('Thu Đông 2026', $project->fresh()->name);

        // Bộ sưu tập của CHÍNH thành viên vẫn thuộc quyền họ.
        $own = $member->projects()->create(['name' => 'Bộ riêng của nhân viên']);
        $this->actingAs($member)->deleteJson('/api/projects/'.$own->id)->assertOk();
    }

    public function test_member_cannot_manage_seats_or_buy_a_plan(): void
    {
        $owner = $this->customer();
        $this->giveProSeats($owner);
        $member = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $member->forceFill(['team_owner_id' => $owner->id])->save();

        $this->actingAs($member)->postJson('/api/team/members', ['email' => 'nguoi-moi@shop.vn'])
            ->assertForbidden()->assertJsonPath('code', 'not_team_owner');

        $this->actingAs($member)->postJson('/api/billing/upgrade-request', [
            'plan_id' => $this->pro()->id, 'units' => 1, 'method' => 'bank_transfer', 'contact_phone' => '0901234567',
        ])->assertStatus(422)->assertJsonPath('code', 'team_member_cannot_upgrade');

        // Nhưng xem được nhóm mình đang ở (biết ai trả tiền, còn mấy ghế).
        $this->actingAs($member)->getJson('/api/team')
            ->assertOk()
            ->assertJsonPath('is_owner', false)
            ->assertJsonPath('owner.id', $owner->id)
            ->assertJsonPath('seats.limit', 3);
    }

    public function test_member_sees_the_team_plan_limits_and_balance(): void
    {
        $owner = $this->customer();
        $this->giveProSeats($owner);
        $owner->forceFill(['credits_balance' => 321])->save();

        $member = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $member->forceFill(['team_owner_id' => $owner->id])->save();

        // Gói + hạn mức + số dư đều của nhóm: nếu trả số riêng của tài khoản phụ thì thanh công cụ hiện
        // một con số không dùng được.
        $this->assertSame($this->pro()->id, $member->fresh()->activePlan()->id);
        $this->assertSame(321, $member->fresh()->billingBalance());

        $boot = $this->actingAs($member)->getJson('/api/boot')->assertOk();
        $boot->assertJsonPath('user.credits_balance', 321)
            ->assertJsonPath('user.plan.slug', 'pro')
            ->assertJsonPath('user.team.is_member', true)
            ->assertJsonPath('user.team.limit', 3);

        $this->actingAs($member)->getJson('/api/plan/status')
            ->assertOk()
            ->assertJsonPath('credits.balance', 321)
            ->assertJsonPath('plan.unit_label', 'tháng')
            ->assertJsonPath('seats.limit', 3)
            ->assertJsonPath('seats.is_owner', false);
    }

    public function test_removing_a_member_frees_the_seat_and_keeps_their_work(): void
    {
        $owner = $this->customer();
        $this->giveProSeats($owner);

        $res = $this->actingAs($owner->fresh())->postJson('/api/team/members', ['email' => 'tam@shop.vn'])->assertStatus(201);
        $member = User::findOrFail($res->json('member.id'));
        $member->generations()->create(['type' => 'image', 'status' => 'completed', 'prompt' => 'ảnh của nhân viên', 'credits_cost' => 1]);

        $this->actingAs($owner->fresh())->deleteJson('/api/team/members/'.$member->id)
            ->assertOk()->assertJsonPath('seats.used', 1)->assertJsonPath('seats.remaining', 2);

        $this->assertNull($member->fresh()->team_owner_id, 'Đã rời nhóm.');
        $this->assertSame(1, $member->generations()->count(), 'Ảnh đã tạo vẫn còn (không xoá việc của người khác).');

        // Ghế đã giải phóng ⇒ mời được người mới.
        $this->actingAs($owner->fresh())->postJson('/api/team/members', ['email' => 'nguoi-moi@shop.vn'])->assertStatus(201);
    }

    public function test_only_the_owner_of_that_team_can_remove_a_member(): void
    {
        $owner = $this->customer();
        $this->giveProSeats($owner);
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        app(\App\Services\PlanService::class)->assign($stranger, $this->pro());

        $res = $this->actingAs($owner->fresh())->postJson('/api/team/members', ['email' => 'tv@shop.vn'])->assertStatus(201);
        $member = User::findOrFail($res->json('member.id'));

        // Người ngoài không xoá được ghế của nhóm khác.
        $this->actingAs($stranger)->deleteJson('/api/team/members/'.$member->id)->assertForbidden();
        $this->assertSame($owner->id, $member->fresh()->team_owner_id);
    }

    public function test_ui_and_docs_are_wired_for_seats(): void
    {
        // Bất biến chống "tính năng chết": backend có ghế mà giao diện không có chỗ mời người thì vô nghĩa.
        $app = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
        $this->assertStringContainsString('store.loadTeam', $app, 'Studio phải nạp trạng thái nhóm.');
        $this->assertStringContainsString('store.inviteMember', $app, 'Studio phải mời được thành viên.');
        $this->assertStringContainsString('store.removeMember', $app, 'Studio phải bỏ được ghế.');

        $store = (string) file_get_contents(resource_path('js/studio/store.js'));
        $this->assertStringContainsString("'/api/team'", $store, 'Store phải gọi API nhóm.');
        $this->assertStringContainsString('temp_password', $store, 'Mật khẩu tạm phải được hiển thị một lần cho chủ nhóm.');

        $pricing = (string) file_get_contents(resource_path('views/pricing.blade.php'));
        $this->assertStringContainsString('Số ghế', $pricing, 'Trang giá phải nói gói cho bao nhiêu người.');
        $this->assertStringContainsString('seatsLabel()', $pricing, 'Số ghế phải lấy từ gói (một nguồn), không viết cứng.');
    }
}
