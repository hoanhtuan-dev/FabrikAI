<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DUYỆT MẪU THEO LÔ (Đợt 2 — 2026-09-19).
 *
 * Vì sao có bộ test này: máy trạng thái duyệt ảnh (idea → drafted → selected → fitted → campaign_ready →
 * approved/rejected + whitelist ở App\Models\Generation::SHOT_TRANSITIONS) và endpoint lẻ
 * POST /api/generations/{id}/shot-state đã tồn tại từ Đợt 1.1 — nhưng KHÔNG giao diện nào gọi tới
 * (grep 'shot-state' trong resources/js = 0). Với người dùng, vòng đời duyệt ảnh là tính năng CHẾT.
 *
 * Bất biến khoá ở đây:
 *   (a) quyền: chỉ chủ bộ sưu tập hoặc Super Admin (giống show/stats/export);
 *   (b) ảnh phải THUỘC bộ sưu tập đang gọi — id lạ không được phép đụng vào;
 *   (c) chỉ ảnh ĐÃ TẠO XONG mới duyệt được;
 *   (d) mọi bước chuyển đi qua ĐÚNG whitelist của model (không có đường tắt cho thao tác theo lô):
 *       nhảy cóc bị từ chối và ảnh giữ nguyên trạng thái;
 *   (e) kết quả trả về TỪNG ẢNH (ok/lỗi + lý do) để giao diện nói thật thay vì im lặng bỏ qua;
 *   (f) giao diện THẬT SỰ gọi vòng đời này (store + panel Bộ sưu tập), nếu không tính năng lại chết.
 */
class ShotReviewTest extends TestCase
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

    private function project(User $u, array $attrs = []): Project
    {
        return $u->projects()->create(array_merge(['name' => 'Thu Đông 2026'], $attrs));
    }

    private function shot(User $u, Project $p, string $shotState, string $status = 'completed'): Generation
    {
        return Generation::create([
            'user_id' => $u->id,
            'project_id' => $p->id,
            'type' => 'image',
            'status' => $status,
            'prompt' => 'áo sơ mi linen',
            'media_url' => $status === 'completed' ? '/storage/studio/t/a.jpg' : null,
            'credits_cost' => 1,
            'shot_state' => $shotState,
        ]);
    }

    private function review(User $u, Project $p, array $ids, string $state, ?string $note = null)
    {
        return $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/shots/review', array_filter([
            'ids' => $ids,
            'state' => $state,
            'note' => $note,
        ], fn ($v) => $v !== null));
    }

    /** [GĐ1 trí nhớ dài hạn] Duyệt/loại ảnh phải ghi lại prompt vào brand_learning để brief sau học gu thật. */
    public function test_approving_or_rejecting_a_shot_records_brand_memory(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $approved = $this->shot($u, $p, 'campaign_ready');
        $approved->update(['prompt' => 'đầm linen trắng ngà dáng suông']);
        $this->review($u, $p, [$approved->id], 'approved')->assertOk();

        $rejected = $this->shot($u, $p, 'campaign_ready');
        $rejected->update(['prompt' => 'áo bóng họa tiết to']);
        $this->review($u, $p, [$rejected->id], 'rejected')->assertOk();

        $this->assertDatabaseHas('brand_learning', ['user_id' => $u->id, 'decision' => 'approved']);
        $this->assertDatabaseHas('brand_learning', ['user_id' => $u->id, 'decision' => 'rejected']);

        $prefs = app(\App\Services\BrandLearningService::class)->preferences($u);
        $this->assertContains('đầm linen trắng ngà dáng suông', $prefs['approved']);
        $this->assertContains('áo bóng họa tiết to', $prefs['rejected']);
    }

    public function test_only_the_owner_or_super_admin_can_review_shots(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);
        $shot = $this->shot($owner, $p, 'campaign_ready');

        $this->postJson('/api/projects/'.$p->id.'/shots/review', ['ids' => [$shot->id], 'state' => 'approved'])
            ->assertStatus(401);

        $this->actingAs($other)
            ->postJson('/api/projects/'.$p->id.'/shots/review', ['ids' => [$shot->id], 'state' => 'approved'])
            ->assertForbidden();

        $this->assertEquals('campaign_ready', $shot->fresh()->shot_state, 'Người ngoài không được đổi trạng thái.');
    }

    public function test_batch_approve_changes_only_the_shots_that_may_advance(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $ready = $this->shot($u, $p, 'campaign_ready');
        $ready2 = $this->shot($u, $p, 'campaign_ready');
        $tooEarly = $this->shot($u, $p, 'drafted');   // nhảy cóc drafted → approved phải bị chặn

        $res = $this->review($u, $p, [$ready->id, $ready2->id, $tooEarly->id], 'approved')->assertOk();

        $res->assertJsonPath('reviewed', 2)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('state', 'approved');

        $this->assertEquals('approved', $ready->fresh()->shot_state);
        $this->assertEquals('approved', $ready2->fresh()->shot_state);
        $this->assertEquals('drafted', $tooEarly->fresh()->shot_state, 'Ảnh nhảy cóc phải GIỮ NGUYÊN.');

        // Lý do của ảnh hỏng phải nói rõ bước chuyển nào bị chặn (không im lặng bỏ qua).
        $failed = collect($res->json('results'))->firstWhere('id', $tooEarly->id);
        $this->assertFalse($failed['ok']);
        $this->assertStringContainsString('drafted', $failed['error']);
    }

    public function test_next_action_advances_exactly_one_step(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $drafted = $this->shot($u, $p, 'drafted');
        $fitted = $this->shot($u, $p, 'fitted');
        $done = $this->shot($u, $p, 'approved');

        $res = $this->review($u, $p, [$drafted->id, $fitted->id, $done->id], 'next')->assertOk();

        $this->assertEquals('selected', $drafted->fresh()->shot_state);
        $this->assertEquals('campaign_ready', $fitted->fresh()->shot_state);
        $this->assertEquals('approved', $done->fresh()->shot_state);
        $res->assertJsonPath('reviewed', 2)->assertJsonPath('failed', 1);

        $last = collect($res->json('results'))->firstWhere('id', $done->id);
        $this->assertStringContainsString('bước cuối', $last['error']);
    }

    public function test_shots_from_another_collection_are_refused_per_item(): void
    {
        $u = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($u);
        $mine = $this->shot($u, $p, 'campaign_ready');
        $foreign = $this->shot($other, $this->project($other, ['name' => 'Bộ người khác']), 'campaign_ready');

        $res = $this->review($u, $p, [$mine->id, $foreign->id], 'approved')->assertOk();

        $res->assertJsonPath('reviewed', 1)->assertJsonPath('failed', 1);
        $this->assertEquals('approved', $mine->fresh()->shot_state);
        $this->assertEquals('campaign_ready', $foreign->fresh()->shot_state, 'Ảnh ngoài bộ sưu tập phải bất động.');

        $bad = collect($res->json('results'))->firstWhere('id', $foreign->id);
        $this->assertStringContainsString('không thuộc bộ sưu tập', $bad['error']);
    }

    public function test_unfinished_images_cannot_be_reviewed(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $pending = $this->shot($u, $p, 'campaign_ready', 'pending');
        $failed = $this->shot($u, $p, 'campaign_ready', 'failed');

        $res = $this->review($u, $p, [$pending->id, $failed->id], 'approved')->assertOk();

        $res->assertJsonPath('reviewed', 0)->assertJsonPath('failed', 2);
        $this->assertEquals('campaign_ready', $pending->fresh()->shot_state);
        $this->assertEquals('campaign_ready', $failed->fresh()->shot_state);
        $this->assertStringContainsString('đã tạo xong', $res->json('results.0.error'));
    }

    public function test_review_note_is_saved_and_input_is_validated(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $shot = $this->shot($u, $p, 'campaign_ready');

        $this->review($u, $p, [$shot->id], 'approved', 'Chốt đợt 1 — màu chuẩn')->assertOk();
        $this->assertEquals('Chốt đợt 1 — màu chuẩn', $shot->fresh()->note);

        // Lô rỗng / quá 60 ảnh / trạng thái lạ đều bị chặn ở tầng validate.
        $this->review($u, $p, [], 'approved')->assertStatus(422);
        $this->review($u, $p, range(1, 61), 'approved')->assertStatus(422);
        $this->review($u, $p, [$shot->id], 'published')->assertStatus(422);

        // Ảnh lặp trong cùng một lô chỉ được xử lý một lần.
        $dup = $this->shot($u, $p, 'campaign_ready');
        $this->review($u, $p, [$dup->id, $dup->id], 'approved')->assertOk()->assertJsonPath('reviewed', 1);
    }

    public function test_rejected_images_can_come_back_to_draft(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $shot = $this->shot($u, $p, 'campaign_ready');

        $this->review($u, $p, [$shot->id], 'rejected', 'Lệch màu so với mẫu')->assertOk();
        $this->assertEquals('rejected', $shot->fresh()->shot_state);

        // Máy trạng thái cho phép kéo ảnh bị loại về lại bản nháp để làm tiếp — đúng whitelist.
        $this->review($u, $p, [$shot->id], 'next')->assertOk();
        $this->assertEquals('drafted', $shot->fresh()->shot_state);
    }

    public function test_project_payload_exposes_the_review_state_for_the_ui(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $shot = $this->shot($u, $p, 'campaign_ready');

        $res = $this->actingAs($u)->getJson('/api/projects/'.$p->id)->assertOk();

        $row = collect($res->json('generations'))->firstWhere('id', $shot->id);
        $this->assertNotNull($row, 'show() phải trả về ảnh của bộ sưu tập.');
        $this->assertEquals('campaign_ready', $row['shot_state']);
        $this->assertEquals('Chờ duyệt', $row['shot_label']);
    }

    public function test_ui_actually_drives_the_shot_lifecycle(): void
    {
        // Bất biến chống "tính năng chết": backend có mà giao diện không gọi thì coi như không có.
        $store = static::studioStoreSource();
        $this->assertStringContainsString('async loadProjectShots(', $store, 'Store phải nạp được danh sách ảnh kèm trạng thái duyệt.');
        $this->assertStringContainsString("'/shots/review'", $store, 'Store phải gọi endpoint duyệt theo lô.');
        $this->assertStringContainsString('shotLabel(state)', $store, 'Nhãn trạng thái phải có một nguồn trong store.');

        $card = (string) file_get_contents(resource_path('js/studio/components/CollectionsCard.vue'));
        $this->assertStringContainsString('async function reviewBatch(', $card, 'Panel Bộ sưu tập phải có hàm gửi lượt duyệt.');
        $this->assertStringContainsString("reviewBatch('next')", $card, 'Phải có thao tác chuyển bước tiếp (không bắt người dùng nhảy cóc).');
        $this->assertStringContainsString("reviewBatch('approved')", $card, 'Phải có thao tác duyệt.');
        $this->assertStringContainsString("reviewBatch('rejected')", $card, 'Phải có thao tác loại.');
        $this->assertStringContainsString('store.shotLabel(', $card, 'Lý do hỏng phải nói rõ ảnh đang ở bước nào.');

        // Không tự bịa luật ở client: bước chuyển vẫn do model quyết định.
        $controller = (string) file_get_contents(app_path('Http/Controllers/ProjectController.php'));
        $this->assertStringContainsString('transitionShotState(', $controller, 'Controller phải đi qua model, không tự gán trạng thái.');
        $this->assertStringContainsString('nextShotState(', $controller, "Bước 'next' phải lấy từ đường thuận của model.");
    }

    public function test_review_shortcuts_are_wired_and_safe(): void
    {
        // Đợt 3 — phím tắt cho khối duyệt: một buổi duyệt là hàng chục ảnh, rời tay khỏi bàn phím để bấm
        // chuột từng lượt là chỗ tốn thời gian nhất. Bất biến: phím CHỈ chạy khi khối duyệt đang mở, KHÔNG
        // cướp phím khi người dùng đang gõ, và NHƯỜNG phím cho modal/công cụ canvas đang chạy (Esc của
        // modal vẫn phải đóng modal, không được đóng khối duyệt).
        $card = (string) file_get_contents(resource_path('js/studio/components/CollectionsCard.vue'));

        $this->assertStringContainsString('window.addEventListener(\'keydown\', onReviewKey)', $card, 'Phải có bộ xử lý phím tắt.');
        $this->assertStringContainsString('window.removeEventListener(\'keydown\', onReviewKey)', $card, 'Phải gỡ bộ xử lý khi card bị tháo (không rò rỉ listener).');
        $this->assertStringContainsString('if (!reviewOpen.value || !applied.value) return;', $card, 'Phím tắt chỉ có tác dụng khi khối duyệt đang mở.');
        $this->assertStringContainsString('typingIn(e.target)', $card, 'Không được cướp phím khi người dùng đang gõ.');
        $this->assertStringContainsString('toolBusy()', $card, 'Phải nhường phím khi modal/công cụ canvas đang chạy.');
        $this->assertStringContainsString('e.ctrlKey || e.metaKey || e.altKey', $card, 'Không được đụng vào tổ hợp phím của trình duyệt/hệ thống.');

        // Đúng bốn phím, đúng việc, và có nhắc trong giao diện (không có phím tắt "ẩn" không ai biết).
        foreach (["s: 'select'", "n: 'next'", "a: 'approved'", "r: 'rejected'", "Escape: 'close'"] as $pair) {
            $this->assertStringContainsString($pair, $card, 'Thiếu phím tắt: '.$pair);
        }
        $this->assertStringContainsString("reviewBatch(action)", $card, 'Phím duyệt/loại phải đi qua đúng hàm gửi lượt duyệt.');
        $this->assertStringContainsString('selectAwaiting()', $card, 'Phím S phải chọn ảnh chờ duyệt.');
        $this->assertStringContainsString('Phím tắt khi khối này đang mở', $card, 'Phải nhắc phím tắt ngay trong khối duyệt.');

        // Cùng một đường dữ liệu: không có fetch trực tiếp trong card (bất biến chung của panel).
        $this->assertStringNotContainsString("fetch('/api/", $card, 'Card không được tự gọi API.');
    }
}
