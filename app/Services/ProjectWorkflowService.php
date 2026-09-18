<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * FabrikAI Studio — Project Workflow engine.
 *
 * Định nghĩa luồng công việc chuẩn cho Designer:
 *
 *   draft ──▶ in_progress ──▶ review ──┬──▶ approved ──▶ archived
 *        │            │               │        │
 *        └────────────┴───────────────┘        └──▶ (reopen về review, rare)
 *
 * Quy tắc (P0.2 — 2026-09-20, sửa lại sau khi đo được ngõ cụt):
 *  - CHỦ BỘ SƯU TẬP luôn chuyển được bộ sưu tập CỦA MÌNH qua trọn vòng đời, kể cả
 *    approved / archived; lần tự duyệt được đánh dấu `self_approved` trong status_history
 *    để vẫn có vết kiểm toán.
 *  - NGƯỜI KHÔNG PHẢI CHỦ thì phải là Super Admin mới duyệt/lưu trữ được bộ sưu tập của
 *    người khác (hàng đợi "Chờ duyệt" của agency — không đổi).
 *  - Tách nhiệm vụ (separation of duties) chỉ còn áp cho TẦNG CAO NHẤT: một Super Admin
 *    không được tự duyệt bộ sưu tập của chính mình khi hệ thống còn Super Admin khác.
 *    Đây là chốt chống leo thang đặc quyền, không phải chốt chặn khách hàng.
 *
 * Vì sao đổi: bản trước yêu cầu SUPER ADMIN cho MỌI lần approve/archive, kể cả khi người
 * thao tác là chủ bộ sưu tập. Với khách tự phục vụ (role=customer) điều đó tạo NGÕ CỤT ĐO
 * ĐƯỢC: ở trạng thái "Chờ duyệt", availableTransitions() chỉ còn ["in_progress"] — khách
 * không bao giờ chốt được bộ sưu tập của chính mình, mãi mãi không đóng được việc.
 *  - Mỗi transition có thể chạy side-effect: cập nhật started_at / completed_at,
 *    bump sort (đưa dự án đang hoạt động lên đầu), v.v.
 *  - Trạng thái hợp lệ được dẫn dắt duy nhất bởi service này để Controller/UI không tự ý.
 */
class ProjectWorkflowService
{
    /**
     * Memoize kết quả "có phải Super Admin duy nhất không" theo user id —
     * tránh lặp query User::exists() khi availableTransitions() gọi canTransition()
     * cho từng trạng thái đích × từng project (N+1 ở endpoint index).
     *
     * @var array<int, bool>
     */
    protected array $soleSuperAdminCache = [];
    /**
     * Trạng thái hợp lệ + metadata hiển thị (label, màu, mô tả).
     * Giữ đồng bộ với App\Models\Project::STATUSES.
     */
    public const STATES = [
        Project::STATUS_DRAFT => [
            'label' => 'Nháp',
            'color' => '#6b6657',
            'hint' => 'Ý tưởng mới, chưa bắt đầu sản xuất.',
            'stage' => 0,
        ],
        Project::STATUS_IN_PROGRESS => [
            'label' => 'Đang làm',
            'color' => '#38815a',
            'hint' => 'Designer đang tạo/chỉnh sửa ảnh.',
            'stage' => 1,
        ],
        Project::STATUS_REVIEW => [
            'label' => 'Chờ duyệt',
            'color' => '#b56a37',
            'hint' => 'Gửi khách (hoặc người duyệt nội bộ) xem mẫu trước khi chốt.',
            'stage' => 2,
        ],
        Project::STATUS_APPROVED => [
            'label' => 'Đã duyệt',
            'color' => '#2d6f4d',
            'hint' => 'Đã chốt, sẵn sàng xuất / đẩy sản phẩm.',
            'stage' => 3,
        ],
        Project::STATUS_ARCHIVED => [
            'label' => 'Lưu trữ',
            'color' => '#36332a',
            'hint' => 'Đã đóng — không còn active.',
            'stage' => 4,
        ],
    ];

    /**
     * Bản đồ transition hợp lệ: from => [to, ...].
     * Mọi chuyển trạng thái ngoài map này sẽ bị từ chối.
     */
    public const TRANSITIONS = [
        Project::STATUS_DRAFT => [Project::STATUS_IN_PROGRESS, Project::STATUS_ARCHIVED],
        Project::STATUS_IN_PROGRESS => [Project::STATUS_REVIEW, Project::STATUS_DRAFT, Project::STATUS_ARCHIVED],
        Project::STATUS_REVIEW => [Project::STATUS_APPROVED, Project::STATUS_IN_PROGRESS, Project::STATUS_ARCHIVED],
        Project::STATUS_APPROVED => [Project::STATUS_ARCHIVED, Project::STATUS_REVIEW],
        Project::STATUS_ARCHIVED => [Project::STATUS_DRAFT],
    ];

    /**
     * Trạng thái cần quyền "reviewer" (Super Admin) để chuyển tới.
     */
    public const REVIEWER_GATES = [Project::STATUS_APPROVED, Project::STATUS_ARCHIVED];

    public function states(): array
    {
        return self::STATES;
    }

    public function label(string $status): string
    {
        return self::STATES[$status]['label'] ?? ucfirst($status);
    }

    /**
     * Kiểm tra user có thể chuyển $project sang trạng thái $to không.
     *
     * @return array{bool, ?string} [ok, errorMessage]
     */
    public function canTransition(Project $project, string $to, User $user): array
    {
        if (! array_key_exists($to, self::STATES)) {
            return [false, 'Trạng thái không hợp lệ.'];
        }
        $from = $project->status;
        if ($from === $to) {
            return [false, 'Dự án đã ở trạng thái này.'];
        }
        $allowed = self::TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            return [false, sprintf('Không thể chuyển từ "%s" sang "%s".', $this->label($from), $this->label($to))];
        }
        // Reviewer gate cho approve/archive.
        if (in_array($to, self::REVIEWER_GATES, true)) {
            $isOwner = (int) $project->user_id === (int) $user->id;

            // (a) CHỦ bộ sưu tập: được tự chốt — trừ chốt chống leo thang đặc quyền ở tầng
            //     Super Admin (một Super Admin không tự duyệt bài của mình khi còn Super khác).
            if ($isOwner) {
                if ($user->isSuperAdmin() && ! $this->isOnlySuperAdmin($user)) {
                    return [false, 'Không thể tự duyệt / lưu trữ bộ sưu tập của chính mình khi hệ thống còn Super Admin khác — cần người đó duyệt thay.'];
                }

                return [true, null];
            }

            // (b) KHÔNG phải chủ: chỉ Super Admin duyệt được bài của người khác (hàng đợi agency).
            if (! $user->isSuperAdmin()) {
                return [false, 'Chỉ chủ bộ sưu tập hoặc Super Admin mới được duyệt / lưu trữ bộ sưu tập này.'];
            }
        }
        return [true, null];
    }

    /**
     * Actor có phải Super Admin DUY NHẤT của hệ thống không (memoized theo request).
     */
    protected function isOnlySuperAdmin(User $user): bool
    {
        return $this->soleSuperAdminCache[$user->id] ??= ! User::query()
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->where('id', '!=', $user->id)
            ->exists();
    }

    /**
     * Thực thi transition: kiểm tra, cập nhật trạng thái + side-effects.
     */
    public function transition(Project $project, string $to, User $user, ?string $note = null): Project
    {
        [$ok, $error] = $this->canTransition($project, $to, $user);
        if (! $ok) {
            throw new \DomainException($error);
        }

        $from = $project->status;
        $updates = ['status' => $to];

        // Side-effects theo trạng thái đích.
        if ($to === Project::STATUS_IN_PROGRESS && empty($project->started_at)) {
            $updates['started_at'] = Carbon::now();
        }
        if ($to === Project::STATUS_APPROVED && empty($project->completed_at)) {
            $updates['completed_at'] = Carbon::now();
        }
        if ($to === Project::STATUS_ARCHIVED) {
            $updates['archived'] = true;
        }
        if ($from === Project::STATUS_ARCHIVED && $to !== Project::STATUS_ARCHIVED) {
            // Reopen từ archive → bỏ cờ archived, reset completed_at nếu có.
            $updates['archived'] = false;
            if ($to === Project::STATUS_DRAFT) {
                $updates['completed_at'] = null;
            }
        }

        // Ghi lịch sử chuyển trạng thái vào settings (không cần bảng riêng).
        // Gộp vào CÙNG một lần save với status/side-effects — trước đây project bị
        // save 2 lần ngoài transaction, save thứ hai fail sẽ để lại state lệch.
        // Self-approval (chỉ xảy ra khi actor là Super Admin duy nhất — xem
        // canTransition) luôn được ghi lịch sử kèm cờ `self_approved` để audit.
        $selfApproved = in_array($to, self::REVIEWER_GATES, true)
            && (int) $project->user_id === (int) $user->id;
        $hasNote = $note !== null && $note !== '';
        if ($hasNote || $selfApproved) {
            $current = $project->settings;
            $history = is_object($current) ? $current->getArrayCopy() : (array) ($current ?? []);
            $history['status_history'] = $history['status_history'] ?? [];
            $entry = [
                'from' => $from, 'to' => $to, 'note' => $hasNote ? mb_substr($note, 0, 500) : null,
                'at' => Carbon::now()->toDateTimeString(), 'by' => $user->id,
            ];
            if ($selfApproved) {
                $entry['self_approved'] = true;
            }
            $history['status_history'][] = $entry;
            $updates['settings'] = $history;
        }

        // Service là thẩm quyền duy nhất của `status` (đã rút khỏi $fillable của
        // model để chặn mass-assignment từ request) → dùng forceFill.
        $project->forceFill($updates)->save();

        return $project->fresh();
    }

    /**
     * Danh sách transition khả dụng cho project + user (cho UI render nút).
     */
    public function availableTransitions(Project $project, User $user): array
    {
        $out = [];
        foreach (self::TRANSITIONS[$project->status] ?? [] as $to) {
            [$ok] = $this->canTransition($project, $to, $user);
            if ($ok) {
                $out[] = [
                    'to' => $to,
                    'label' => $this->label($to),
                    'color' => self::STATES[$to]['color'],
                    'hint' => self::STATES[$to]['hint'],
                ];
            }
        }
        return $out;
    }

    /**
     * Trả về metadata hiển thị cho frontend (label, màu, stage, transitions).
     */
    public function describe(Project $project, User $user): array
    {
        $state = self::STATES[$project->status] ?? self::STATES[Project::STATUS_DRAFT];

        return [
            'status' => $project->status,
            'status_label' => $state['label'],
            'status_color' => $state['color'],
            'status_hint' => $state['hint'],
            'stage' => $state['stage'],
            'transitions' => $this->availableTransitions($project, $user),
            'is_archived' => (bool) $project->archived,
        ];
    }
}
