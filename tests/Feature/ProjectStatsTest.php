<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\ProjectFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CHI PHÍ & TIẾN ĐỘ THEO BỘ SƯU TẬP (Đợt 2 — 2026-09-19).
 *
 * Chủ doanh nghiệp cần trả lời được: "bộ này đã ngốn bao nhiêu credit, còn bao nhiêu ảnh chưa xong, có ảnh
 * nào lỗi, còn mấy ngày tới hạn, khách đã phản hồi chưa". Trước đây danh sách dự án chỉ có tổng số ảnh.
 *
 * Bất biến khoá ở đây:
 *   (a) quyền giống show/export (chủ bộ sưu tập hoặc Super Admin);
 *   (b) số liệu lấy TRỰC TIẾP từ bảng generations (đếm theo trạng thái + cộng credit) ⇒ không lệch;
 *   (c) hạn chót trả về dạng SỐ NGÀY CÒN LẠI (âm = quá hạn) để giao diện không phải tự tính;
 *   (d) phản hồi của khách (từ link chia sẻ) được gộp vào cùng một chỗ.
 */
class ProjectStatsTest extends TestCase
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

    private function gen(User $u, Project $p, string $status, int $credits = 1): Generation
    {
        return Generation::create([
            'user_id' => $u->id,
            'project_id' => $p->id,
            'type' => 'image',
            'status' => $status,
            'prompt' => 'áo sơ mi linen',
            'media_url' => $status === 'completed' ? '/storage/studio/t/a.jpg' : null,
            'credits_cost' => $credits,
        ]);
    }

    public function test_only_the_owner_can_read_collection_stats(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);

        $this->getJson('/api/projects/'.$p->id.'/stats')->assertStatus(401);
        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/stats')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/projects/'.$p->id.'/stats')->assertOk();
    }

    public function test_stats_count_images_by_status_and_sum_credits(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $this->gen($u, $p, 'completed', 1);
        $this->gen($u, $p, 'completed', 3);   // ảnh tốn 3 credit (gói có thể khác)
        $this->gen($u, $p, 'pending', 1);
        $this->gen($u, $p, 'processing', 1);
        $this->gen($u, $p, 'failed', 1);

        $res = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/stats')->assertOk();

        $res->assertJsonPath('images.total', 5)
            ->assertJsonPath('images.completed', 2)
            ->assertJsonPath('images.running', 2)
            ->assertJsonPath('images.failed', 1)
            ->assertJsonPath('credits.used', 7);
    }

    public function test_deadline_is_reported_as_days_left_including_overdue(): void
    {
        $u = $this->customer();

        $soon = $this->project($u, ['name' => 'Sắp tới hạn', 'deadline' => now()->addDays(3)]);
        $late = $this->project($u, ['name' => 'Quá hạn', 'deadline' => now()->subDays(2)]);

        $this->actingAs($u)->getJson('/api/projects/'.$soon->id.'/stats')
            ->assertOk()->assertJsonPath('deadline.days_left', 3);

        $this->actingAs($u)->getJson('/api/projects/'.$late->id.'/stats')
            ->assertOk()->assertJsonPath('deadline.days_left', -2);
    }

    public function test_stats_include_the_latest_client_feedback(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        ProjectFeedback::create([
            'project_id' => $p->id,
            'author_name' => 'Chị Hương',
            'decision' => ProjectFeedback::DECISION_CHANGES,
            'message' => 'Ảnh 03 đổi nền sáng hơn.',
        ]);

        $res = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/stats')->assertOk();

        $res->assertJsonPath('feedback.count', 1)
            ->assertJsonPath('feedback.latest.author_name', 'Chị Hương')
            ->assertJsonPath('feedback.latest.decision_label', 'Yêu cầu sửa')
            ->assertJsonPath('feedback.latest.message', 'Ảnh 03 đổi nền sáng hơn.');
    }

    public function test_collection_without_images_reports_zeroes_not_errors(): void
    {
        $u = $this->customer();
        $p = $this->project($u, ['name' => 'Bộ trống']);

        $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/stats')
            ->assertOk()
            ->assertJsonPath('images.total', 0)
            ->assertJsonPath('images.completed', 0)
            ->assertJsonPath('credits.used', 0)
            ->assertJsonPath('deadline', null);
    }

    public function test_batch_retry_keeps_failed_prompts_for_the_ui(): void
    {
        // Bất biến phía giao diện: danh sách mục LỖI của lượt hàng loạt được giữ lại để chạy lại
        // (trước đây chỉ hiện toast rồi mất, người dùng phải tự nhớ mục nào lỗi).
        $store = (string) file_get_contents(resource_path('js/studio/store.js'));
        $this->assertStringContainsString('batchFailed', $store, 'Store phải giữ danh sách mục lỗi.');
        $this->assertStringContainsString('entry.ok = true', $store, 'Phải đánh dấu từng mục xong/lỗi khi gửi.');
        $this->assertStringContainsString('batchSend.items.push', $store, 'Phải ghi lại từng mục để hiện tiến trình thật.');

        // Tab Hàng loạt nằm trong card Tạo ảnh; còn thống kê chi phí/tiến độ nằm trong panel Bộ sưu tập.
        $concept = (string) file_get_contents(resource_path('js/studio/components/ConceptCard.vue'));
        $this->assertStringContainsString('function retryFailed()', $concept, 'Tab Hàng loạt phải có nút chạy lại mục lỗi.');
        $this->assertStringContainsString('store.batchFailed', $concept, 'Tab Hàng loạt phải đọc danh sách mục lỗi từ store.');

        $collections = (string) file_get_contents(resource_path('js/studio/components/CollectionsCard.vue'));
        $this->assertStringContainsString('store.loadProjectStats', $collections, 'Panel Bộ sưu tập phải nạp thống kê chi phí/tiến độ.');
        $this->assertStringContainsString('credits.used', $collections, 'Panel phải hiển thị credit đã dùng của bộ sưu tập.');
        $this->assertStringContainsString('deadline.days_left', $collections, 'Panel phải hiển thị số ngày còn lại tới hạn.');
    }
}
