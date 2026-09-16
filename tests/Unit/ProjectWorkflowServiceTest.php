<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit test cho engine luông công việc của Designer trong Studio.
 *
 * Phạm vi: chỉ kiểm ProjectWorkflowService thuần tuý — bản đồ transition,
 * reviewer gate (Super Admin), side-effects (started_at / completed_at /
 * archived) và lịch sử status_history. Không phụ thuộc HTTP hay Controller.
 */
class ProjectWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = new ProjectWorkflowService;
    }

    public function test_states_contain_full_designer_lifecycle(): void
    {
        $states = $this->workflow->states();

        $this->assertArrayHasKey(Project::STATUS_DRAFT, $states);
        $this->assertArrayHasKey(Project::STATUS_IN_PROGRESS, $states);
        $this->assertArrayHasKey(Project::STATUS_REVIEW, $states);
        $this->assertArrayHasKey(Project::STATUS_APPROVED, $states);
        $this->assertArrayHasKey(Project::STATUS_ARCHIVED, $states);

        // Stage tăng dần theo đúng thứ tự luông công việc.
        $this->assertSame(0, $states[Project::STATUS_DRAFT]['stage']);
        $this->assertSame(1, $states[Project::STATUS_IN_PROGRESS]['stage']);
        $this->assertSame(2, $states[Project::STATUS_REVIEW]['stage']);
        $this->assertSame(3, $states[Project::STATUS_APPROVED]['stage']);
        $this->assertSame(4, $states[Project::STATUS_ARCHIVED]['stage']);
    }

    public function test_can_transition_rejects_invalid_status(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status' => Project::STATUS_DRAFT,
        ]);

        [$ok, $error] = $this->workflow->canTransition($project, 'nonexistent', $user);
        $this->assertFalse($ok);
        $this->assertStringContainsString('không hợp lệ', $error);
    }

    public function test_can_transition_rejects_same_status(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status' => Project::STATUS_REVIEW,
        ]);

        [$ok, $error] = $this->workflow->canTransition($project, Project::STATUS_REVIEW, $user);
        $this->assertFalse($ok);
        $this->assertStringContainsString('đã ở trạng thái', $error);
    }

    public function test_can_transition_rejects_unmapped_jump(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        // draft -> approved là bước nhảy không hợp lệ (phải qua in_progress + review).
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'status' => Project::STATUS_DRAFT,
        ]);

        [$ok, $error] = $this->workflow->canTransition($project, Project::STATUS_APPROVED, $user);
        $this->assertFalse($ok);
        $this->assertStringContainsString('Không thể chuyển', $error);
    }

    public function test_reviewer_gate_blocks_admin_from_approving(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $admin->id,
            'status' => Project::STATUS_REVIEW,
        ]);

        [$ok, $error] = $this->workflow->canTransition($project, Project::STATUS_APPROVED, $admin);
        $this->assertFalse($ok);
        $this->assertStringContainsString('Super Admin', $error);
    }

    public function test_reviewer_gate_allows_super_admin_to_approve(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_REVIEW,
        ]);

        [$ok] = $this->workflow->canTransition($project, Project::STATUS_APPROVED, $super);
        $this->assertTrue($ok);
    }

    public function test_reviewer_gate_allows_admin_to_send_to_review(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $admin->id,
            'status' => Project::STATUS_IN_PROGRESS,
        ]);

        // review không thuộc REVIEWER_GATES -> Admin được phép chuyển lên.
        [$ok] = $this->workflow->canTransition($project, Project::STATUS_REVIEW, $admin);
        $this->assertTrue($ok);
    }

    public function test_transition_throws_on_invalid_path(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_DRAFT,
        ]);

        $this->expectException(\DomainException::class);
        $this->workflow->transition($project, Project::STATUS_APPROVED, $super);
    }

    public function test_transition_sets_started_at_on_first_in_progress(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_DRAFT,
            'started_at' => null,
        ]);

        $result = $this->workflow->transition($project, Project::STATUS_IN_PROGRESS, $super);
        $this->assertSame(Project::STATUS_IN_PROGRESS, $result->status);
        $this->assertNotNull($result->started_at);
    }

    public function test_transition_does_not_overwrite_started_at_on_second_in_progress(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $originalStartedAt = now()->subDays(3);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_REVIEW,
            'started_at' => $originalStartedAt,
        ]);

        // review -> in_progress (rework) — không reset started_at đã có.
        $result = $this->workflow->transition($project, Project::STATUS_IN_PROGRESS, $super);
        $this->assertSame(Project::STATUS_IN_PROGRESS, $result->status);
        $this->assertSame(
            $originalStartedAt->startOfSecond()->toIso8601String(),
            $result->started_at->startOfSecond()->toIso8601String(),
        );
    }

    public function test_transition_sets_completed_at_on_first_approval(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_REVIEW,
            'completed_at' => null,
        ]);

        $result = $this->workflow->transition($project, Project::STATUS_APPROVED, $super);
        $this->assertSame(Project::STATUS_APPROVED, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    public function test_transition_to_archived_sets_archived_flag(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_APPROVED,
            'archived' => false,
        ]);

        $result = $this->workflow->transition($project, Project::STATUS_ARCHIVED, $super);
        $this->assertSame(Project::STATUS_ARCHIVED, $result->status);
        $this->assertTrue((bool) $result->archived);
    }

    public function test_reopen_from_archived_resets_archived_flag(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_ARCHIVED,
            'archived' => true,
            'completed_at' => now()->subDay(),
        ]);

        // archived -> draft (mở lại dự án đã lưu trữ).
        $result = $this->workflow->transition($project, Project::STATUS_DRAFT, $super);
        $this->assertSame(Project::STATUS_DRAFT, $result->status);
        $this->assertFalse((bool) $result->archived);
        // Mở lại về draft phải reset completed_at để dashboard không đếm dự án đã hoàn thành.
        $this->assertNull($result->completed_at);
    }

    public function test_status_history_recorded_when_note_provided(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_IN_PROGRESS,
            'settings' => null,
        ]);

        $this->workflow->transition($project, Project::STATUS_REVIEW, $super, 'Gửi duyệt mẫu chốt');

        $project->refresh();
        $history = $project->settings['status_history'] ?? [];
        $this->assertCount(1, $history);
        $this->assertSame(Project::STATUS_IN_PROGRESS, $history[0]['from']);
        $this->assertSame(Project::STATUS_REVIEW, $history[0]['to']);
        $this->assertSame('Gửi duyệt mẫu chốt', $history[0]['note']);
        $this->assertSame($super->id, $history[0]['by']);
    }

    public function test_status_history_not_recorded_when_note_empty(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_IN_PROGRESS,
            'settings' => null,
        ]);

        $this->workflow->transition($project, Project::STATUS_REVIEW, $super, null);

        $project->refresh();
        $this->assertNull($project->settings);
    }

    public function test_status_history_accumulates_across_transitions(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_DRAFT,
            'settings' => null,
        ]);

        $this->workflow->transition($project, Project::STATUS_IN_PROGRESS, $super, 'Bắt đầu');
        $this->workflow->transition($project->fresh(), Project::STATUS_REVIEW, $super, 'Gửi duyệt');

        $project->refresh();
        $this->assertCount(2, $project->settings['status_history']);
    }

    public function test_available_transitions_respects_reviewer_gate(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $admin->id,
            'status' => Project::STATUS_REVIEW,
        ]);

        $out = $this->workflow->availableTransitions($project, $admin);
        $targets = array_column($out, 'to');

        // review -> in_progress (rework) + archived đều bị chặn vì archived là REVIEWER_GATES.
        // Chỉ còn approved... cũng bị chặn -> Admin chỉ được về in_progress.
        $this->assertContains(Project::STATUS_IN_PROGRESS, $targets);
        $this->assertNotContains(Project::STATUS_APPROVED, $targets);
        $this->assertNotContains(Project::STATUS_ARCHIVED, $targets);
    }

    public function test_describe_returns_label_color_stage_and_transitions(): void
    {
        $super = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $super->id,
            'status' => Project::STATUS_IN_PROGRESS,
        ]);

        $desc = $this->workflow->describe($project, $super);
        $this->assertSame(Project::STATUS_IN_PROGRESS, $desc['status']);
        $this->assertSame('Đang làm', $desc['status_label']);
        $this->assertNotEmpty($desc['status_color']);
        $this->assertSame(1, $desc['stage']);
        $this->assertIsArray($desc['transitions']);
        $this->assertFalse((bool) $desc['is_archived']);
    }

    public function test_label_falls_back_for_unknown_status(): void
    {
        $this->assertSame('Foobar', $this->workflow->label('foobar'));
    }

    // ── Reviewer gate thích ứng (tách nhiệm vụ + chống lockout, Phần I) ──────

    public function test_reviewer_gate_blocks_self_approval_when_second_super_exists(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]); // Super Admin thứ hai
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'status' => Project::STATUS_REVIEW,
        ]);

        [$ok, $error] = $this->workflow->canTransition($project, Project::STATUS_APPROVED, $owner);
        $this->assertFalse($ok);
        $this->assertStringContainsString('tự duyệt', $error);
    }

    public function test_reviewer_gate_blocks_self_archive_when_second_super_exists(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'status' => Project::STATUS_APPROVED,
        ]);

        [$ok, $error] = $this->workflow->canTransition($project, Project::STATUS_ARCHIVED, $owner);
        $this->assertFalse($ok);
        $this->assertStringContainsString('tự duyệt', $error);
    }

    public function test_sole_super_admin_may_self_approve_and_history_is_flagged(): void
    {
        $sole = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $sole->id,
            'status' => Project::STATUS_REVIEW,
            'settings' => null,
        ]);

        $result = $this->workflow->transition($project, Project::STATUS_APPROVED, $sole);
        $this->assertSame(Project::STATUS_APPROVED, $result->status);

        $history = $result->settings['status_history'] ?? [];
        $this->assertCount(1, $history);
        $this->assertTrue($history[0]['self_approved']);
        $this->assertNull($history[0]['note']);
        $this->assertSame($sole->id, $history[0]['by']);
    }

    public function test_cross_approval_by_second_super_is_not_flagged_self_approved(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $reviewer = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $owner->id,
            'status' => Project::STATUS_REVIEW,
            'settings' => null,
        ]);

        $result = $this->workflow->transition($project, Project::STATUS_APPROVED, $reviewer, 'Duyệt chéo');
        $history = $result->settings['status_history'] ?? [];
        $this->assertCount(1, $history);
        $this->assertArrayNotHasKey('self_approved', $history[0]);
        $this->assertSame('Duyệt chéo', $history[0]['note']);
        $this->assertSame($reviewer->id, $history[0]['by']);
    }

    public function test_transition_with_note_saves_exactly_once(): void
    {
        $sole = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $project = Project::factory()->create([
            'user_id' => $sole->id,
            'status' => Project::STATUS_DRAFT,
            'started_at' => null,
            'settings' => null,
        ]);

        $updates = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$updates) {
            if (str_starts_with(strtolower($q->sql), 'update')) {
                $updates++;
            }
        });

        $result = $this->workflow->transition($project, Project::STATUS_IN_PROGRESS, $sole, 'Bắt đầu làm');

        // Trước fix: 2 câu UPDATE (status riêng, settings history riêng, không transaction).
        $this->assertSame(1, $updates);
        $this->assertSame(Project::STATUS_IN_PROGRESS, $result->status);
        $this->assertNotNull($result->started_at);
        $this->assertCount(1, $result->settings['status_history']);
    }
}
