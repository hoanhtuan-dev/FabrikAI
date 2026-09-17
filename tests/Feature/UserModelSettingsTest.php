<?php

namespace Tests\Feature;

use App\Models\StudioAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * [Yêu cầu 2026-09-17] CÀI ĐẶT KHUÔN MẶT (model) + DÁNG POSE (người mẫu) — CẤP USER.
 *
 * Trước đây `studio_assets` là bảng TOÀN CỤC: mặt/dáng một người thêm thì MỌI người thấy, và
 * endpoint thêm/xoá buộc giữ ở nhóm ADMIN ⇒ người dùng thường không tự thêm được gì.
 *
 * Nay có `user_id` (NULL = catalog DÙNG CHUNG có từ trước). Bất biến khoá ở đây:
 *   (a) người dùng thêm được mặt/dáng CỦA MÌNH và hàng đó mang đúng user_id;
 *   (b) họ KHÔNG thấy và KHÔNG xoá được của người khác;
 *   (c) owner thấy và xoá được TẤT CẢ;
 *   (d) catalog dùng chung (user_id NULL) vẫn hiện cho mọi người — tương thích ngược.
 */
class UserModelSettingsTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function asset(?string $name, string $type = 'model', ?int $userId = null): StudioAsset
    {
        return StudioAsset::create([
            'type' => $type, 'name' => $name, 'path' => '/storage/studio/assets/x.jpg',
            'sort' => 0, 'user_id' => $userId,
        ]);
    }

    /** @return array<int, string> */
    private function names(User $as): array
    {
        return array_column($this->actingAs($as)->getJson('/api/assets')->assertOk()->json('items'), 'name');
    }

    // ── Trang cài đặt ─────────────────────────────────────────────────────

    public function test_guest_is_redirected_and_user_can_open_settings(): void
    {
        $this->get('/model-settings')->assertRedirect('/dang-nhap');
        $this->actingAs($this->customer())->get('/model-settings')->assertOk();
        $this->actingAs($this->admin())->get('/model-settings')->assertOk();
    }

    public function test_page_exposes_the_user_identity(): void
    {
        $c = $this->customer();
        $html = $this->actingAs($c)->get('/model-settings')->assertOk()->getContent();
        $this->assertStringContainsString('data-user-id="'.$c->id.'"', $html);
    }

    // ── (a) thêm được mặt/dáng CỦA MÌNH ───────────────────────────────────

    public function test_user_can_add_their_own_face(): void
    {
        $c = $this->customer();
        $resp = $this->actingAs($c)->post('/api/assets', [
            'type' => 'model',
            'name' => 'Khuôn mặt của tôi',
            'image' => UploadedFile::fake()->image('face.jpg', 20, 20),
        ])->assertOk();

        $asset = StudioAsset::findOrFail($resp->json('id'));
        $this->assertSame($c->id, (int) $asset->user_id, 'Mặt do user thêm PHẢI gắn user_id của họ.');
    }

    public function test_user_can_add_their_own_pose(): void
    {
        $c = $this->customer();
        $resp = $this->actingAs($c)->post('/api/assets', [
            'type' => 'pose',
            'name' => 'Dáng của tôi',
            'image' => UploadedFile::fake()->image('pose.jpg', 20, 20),
        ])->assertOk();

        $this->assertSame('pose', StudioAsset::findOrFail($resp->json('id'))->type);
    }

    // ── (b) không thấy / không xoá được của người khác ────────────────────

    public function test_user_does_not_see_another_users_face(): void
    {
        $c = $this->customer();
        $other = User::factory()->create();
        $this->asset('Của người khác', 'model', $other->id);
        $this->asset('Chung cho mọi người', 'model', null);
        $this->asset('Của tôi', 'model', $c->id);

        $names = $this->names($c);

        $this->assertContains('Của tôi', $names);
        $this->assertContains('Chung cho mọi người', $names, 'Catalog dùng chung phải còn thấy (tương thích ngược).');
        $this->assertNotContains('Của người khác', $names, 'KHÔNG được thấy mặt/dáng của người khác.');
    }

    public function test_user_cannot_delete_another_users_face(): void
    {
        $c = $this->customer();
        $other = User::factory()->create();
        $victim = $this->asset('Không được xoá', 'model', $other->id);

        $this->actingAs($c)->deleteJson('/api/assets/'.$victim->id)->assertForbidden();
        $this->assertDatabaseHas('studio_assets', ['id' => $victim->id]);
    }

    public function test_user_can_delete_their_own_face(): void
    {
        $c = $this->customer();
        $mine = $this->asset('Của tôi xoá được', 'model', $c->id);

        $this->actingAs($c)->deleteJson('/api/assets/'.$mine->id)->assertOk();
        $this->assertDatabaseMissing('studio_assets', ['id' => $mine->id]);
    }

    // ── (c) owner quản lý tất cả ──────────────────────────────────────────

    public function test_owner_sees_and_deletes_every_users_face(): void
    {
        $a = $this->admin();
        $other = User::factory()->create();
        $theirs = $this->asset('Của user khác', 'model', $other->id);

        $this->assertContains('Của user khác', $this->names($a), 'Owner phải thấy tất cả.');
        $this->actingAs($a)->deleteJson('/api/assets/'.$theirs->id)->assertOk();
        $this->assertDatabaseMissing('studio_assets', ['id' => $theirs->id]);
    }

    // ── (d) quy tắc hiển thị (hàm thuần) ──────────────────────────────────

    public function test_visibility_rule(): void
    {
        $mine = $this->asset('m', 'model', 5);
        $shared = $this->asset('s', 'model', null);
        $theirs = $this->asset('t', 'model', 6);

        $user5 = User::factory()->create(['id' => 5]);
        $admin = $this->admin();

        $this->assertTrue($mine->visibleTo($user5), 'Của mình thì được.');
        $this->assertTrue($shared->visibleTo($user5), 'Dùng chung thì ai cũng được.');
        $this->assertFalse($theirs->visibleTo($user5), 'Của người khác thì không.');
        $this->assertTrue($theirs->visibleTo($admin), 'Owner thì được mọi thứ.');
    }

    // ── (e) tạo ảnh phải TRA RA ĐƯỢC mặt/dáng của user ────────────────────

    public function test_own_asset_resolves_at_generation_time(): void
    {
        // Đây là lý do mặt/dáng lưu ở SERVER chứ không phải localStorage: khi tạo ảnh studio
        // gửi lên id và backend phải tra ra ảnh tham chiếu.
        $c = $this->customer();
        $asset = $this->asset('Tra ra được', 'model', $c->id);

        $picked = app(\App\Services\VirtualTryOnService::class)->pickModel((string) $asset->id);

        $this->assertNotNull($picked, 'Mặt của user phải tra được theo id khi tạo ảnh.');
        $this->assertSame('Tra ra được', $picked['name']);
    }
}
