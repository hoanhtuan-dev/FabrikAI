<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\ProjectFeedback;
use App\Models\ProjectShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CHIA SẺ BỘ SƯU TẬP CHO KHÁCH DUYỆT (Đợt 4 — 2026-09-19).
 *
 * Chủ doanh nghiệp/thương hiệu cần gửi bộ sưu tập cho KHÁCH hoặc người duyệt nội bộ — người KHÔNG có
 * tài khoản FabrikAI. Trước đây chỉ có workspace trong app nên luồng duyệt thực tế vẫn là chụp màn hình
 * gửi Zalo, và phản hồi trôi mất.
 *
 * Bất biến khoá ở đây:
 *   (a) chỉ chủ bộ sưu tập (hoặc Super Admin) tạo/thu hồi được link;
 *   (b) token đủ dài, KHÔNG đoán được; link hết hạn hoặc bị thu hồi ⇒ 404 (không dò được token);
 *   (c) khách KHÔNG cần đăng nhập vẫn xem được ảnh + brief và gửi phản hồi;
 *   (d) phản hồi được lưu lại kèm tên + quyết định + ghi chú và hiện cho chủ thấy qua API;
 *   (e) trang công khai có noindex (link chia sẻ không được vào Google).
 */
class ProjectShareTest extends TestCase
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

    private function project(User $u): Project
    {
        return $u->projects()->create([
            'name' => 'Thu Đông 2026 · Lookbook',
            'brief' => '12 SKU nền trắng',
            'tags' => ['Thu Đông 2026'],
        ]);
    }

    private function withImage(User $u, Project $p): Generation
    {
        return Generation::create([
            'user_id' => $u->id,
            'project_id' => $p->id,
            'type' => 'image',
            'status' => 'completed',
            'prompt' => 'áo sơ mi linen trắng',
            'media_url' => '/storage/studio/t/ao.jpg',
            'credits_cost' => 1,
        ]);
    }

    public function test_only_the_owner_can_create_a_share_link(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);

        // Khách chưa đăng nhập ⇒ 401 (chưa có danh tính).
        $this->getJson('/api/projects/'.$p->id.'/share')->assertStatus(401);

        // Đã đăng nhập nhưng KHÔNG phải chủ ⇒ 403 (cả tạo lẫn đọc trạng thái).
        $this->actingAs($other)->postJson('/api/projects/'.$p->id.'/share')->assertForbidden();
        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/share')->assertForbidden();

        $res = $this->actingAs($owner)->postJson('/api/projects/'.$p->id.'/share', ['days' => 30])->assertOk();
        $this->assertStringContainsString('/chia-se/', (string) $res->json('url'));
        $this->assertGreaterThanOrEqual(40, strlen((string) $res->json('token')), 'Token phải đủ dài để không đoán được.');
    }

    public function test_creating_twice_reuses_the_existing_link(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $first = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/share', ['days' => 7])->assertOk()->json('token');
        $second = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/share', ['days' => 30])->assertOk()->json('token');

        $this->assertSame($first, $second, 'Không rải nhiều link cho cùng một bộ sưu tập.');
        $this->assertSame(1, ProjectShare::where('project_id', $p->id)->count());
    }

    public function test_guest_can_open_the_share_page_and_see_images_and_brief(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->withImage($u, $p);
        $token = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/share')->assertOk()->json('token');

        $res = $this->get('/chia-se/'.$token);
        $res->assertOk();
        $res->assertSee('Thu Đông 2026 · Lookbook', false);
        $res->assertSee('12 SKU nền trắng', false);
        $res->assertSee('/storage/studio/t/ao.jpg', false);
        $res->assertSee('noindex', false);
        $this->assertSame(1, (int) ProjectShare::where('token', $token)->first()->views, 'Lượt xem phải được đếm.');
    }

    public function test_unknown_expired_or_revoked_token_is_not_found(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $token = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/share')->assertOk()->json('token');

        $this->get('/chia-se/khong-co-token-nay')->assertNotFound();

        ProjectShare::where('token', $token)->update(['expires_at' => now()->subDay()]);
        $this->get('/chia-se/'.$token)->assertNotFound();

        ProjectShare::where('token', $token)->update(['expires_at' => now()->addDays(7), 'revoked_at' => now()]);
        $this->get('/chia-se/'.$token)->assertNotFound();
    }

    public function test_owner_can_revoke_and_the_link_stops_working(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $token = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/share')->assertOk()->json('token');
        $share = ProjectShare::where('token', $token)->firstOrFail();

        $this->get('/chia-se/'.$token)->assertOk();

        $this->actingAs($u)->deleteJson('/api/projects/'.$p->id.'/share/'.$share->id)->assertOk();
        $this->get('/chia-se/'.$token)->assertNotFound();
    }

    public function test_guest_feedback_is_stored_and_visible_to_the_owner(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $token = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/share')->assertOk()->json('token');

        $this->post('/chia-se/'.$token.'/phan-hoi', [
            'author_name' => 'Chị Hương — khách',
            'decision' => 'changes',
            'message' => 'Ảnh 03 đổi nền sáng hơn.',
        ])->assertRedirectContains('/chia-se/'.$token);

        $this->assertDatabaseHas('project_feedback', [
            'project_id' => $p->id,
            'author_name' => 'Chị Hương — khách',
            'decision' => ProjectFeedback::DECISION_CHANGES,
        ]);

        // Chủ thấy phản hồi qua API trạng thái chia sẻ.
        $status = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/share')->assertOk();
        $status->assertJsonPath('feedback.0.author_name', 'Chị Hương — khách');
        $status->assertJsonPath('feedback.0.decision_label', 'Yêu cầu sửa');
        $this->assertNotNull($status->json('share.url'));

        // Và trang công khai hiển thị phản hồi cho lần xem sau; banner cảm ơn chỉ hiện khi ?sent=1
        // (đúng luồng: form POST → redirect kèm sent=1).
        $this->get('/chia-se/'.$token)->assertOk()->assertSee('Chị Hương — khách', false)->assertSee('Yêu cầu sửa', false);
        $this->get('/chia-se/'.$token.'?sent=1')->assertOk()->assertSee('Đã gửi phản hồi', false);
    }

    public function test_feedback_requires_name_and_valid_decision(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $token = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/share')->assertOk()->json('token');

        $this->post('/chia-se/'.$token.'/phan-hoi', ['decision' => 'approved'])
            ->assertSessionHasErrors('author_name');

        $this->post('/chia-se/'.$token.'/phan-hoi', ['author_name' => 'Khách', 'decision' => 'linh-tinh'])
            ->assertSessionHasErrors('decision');

        $this->assertSame(0, ProjectFeedback::count(), 'Phản hồi sai không được ghi vào sổ.');
    }

    public function test_owner_only_sees_their_own_project_status(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);

        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/share')->assertForbidden();
    }
}
