<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\Project;
use App\Services\ProjectWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * FabrikAI Studio — Project Controller.
 *
 * Cung cấp CRUD + luồng công việc (workflow transitions) + gán generations/assets
 * cho bảng "Dự án thiết kế" của Designer. Mọi thao tác scoped theo user hiện tại
 * (Designer chỉ thấy dự án của mình; Super Admin duyệt qua endpoint transition).
 */
class ProjectController extends Controller
{
    public function __construct(protected ProjectWorkflowService $workflow) {}

    /**
     * GET /studio/projects — danh sách dự án của user (kèm metadata workflow).
     *
     * Scope đặc biệt cho Super Admin: `?scope=pending` trả dự án đang CHỜ DUYỆT
     * (status=review) của TOÀN HỆ THỐNG — hàng đợi để reviewer duyệt chéo
     * (owner không tự duyệt được khi có Super Admin thứ hai).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($request->input('scope') === 'pending') {
            abort_unless($user->isSuperAdmin(), 403);

            $pending = Project::query()
                ->whereNull('deleted_at')
                ->where('status', Project::STATUS_REVIEW)
                ->where('archived', false)
                ->with(['user:id,name', 'latestGeneration'])
                ->withCount('generations')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Project $p) => $this->serialize($p, $user));

            return response()->json([
                'items' => $pending,
                'statuses' => $this->workflow->states(),
                'archived' => false,
                'scope' => 'pending',
                'can_review' => true,
            ]);
        }

        $archived = (bool) $request->input('archived', false);

        // Eager-load latestGeneration (thumbnail) + withCount (generations_count)
        // để serialize() không bắn thêm query nào cho từng project (N+1).
        // [Q4 — 2026-09-19] Thành viên trong nhóm thấy BỘ SƯU TẬP CỦA CHỦ NHÓM: cả nhóm làm chung một
        // danh sách việc, không phải mỗi người một danh sách rồi ngồi chép qua lại.
        $ownerIds = $user->isTeamMember() ? [(int) $user->team_owner_id] : [];
        $projects = Project::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($user, $ownerIds) {
                $q->where('user_id', $user->id);
                if ($ownerIds) {
                    $q->orWhereIn('user_id', $ownerIds);
                }
            })
            ->where('archived', $archived)
            // [P1.2] Kèm NGƯỜI PHỤ TRÁCH để thẻ bộ sưu tập nói được "ai đang làm".
            ->with(['latestGeneration', 'user:id,name', 'assignee:id,name'])
            ->withCount('generations');

        // [P1.2] LỌC THEO NGƯỜI: "việc của tôi" và "theo thành viên". Không có bộ lọc này thì
        // trường người phụ trách chỉ là nhãn trang trí — giao việc xong vẫn phải tự dò từng bộ.
        $assignee = (string) $request->input('assignee', '');
        if ($assignee !== '') {
            if ($assignee === 'me') {
                $projects = $projects->where('assignee_id', $user->id);
            } elseif ($assignee === 'none') {
                // Chưa giao cho ai (thường là chính chủ đang tự làm).
                $projects = $projects->whereNull('assignee_id');
            } elseif (ctype_digit($assignee)) {
                $projects = $projects->where('assignee_id', (int) $assignee);
            }
        }

        $projects = $projects
            ->orderBy('sort')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Project $p) => $this->serialize($p, $user));

        return response()->json([
            'items' => $projects,
            'statuses' => $this->workflow->states(),
            'archived' => $archived,
            'scope' => 'own',
            'can_review' => $user->isSuperAdmin(),
            'assignee_filter' => $assignee,
            // [P1.2] Danh sách người CÓ THỂ giao việc (chủ sở hữu + thành viên nhóm) để giao diện
            // đổ vào ô chọn mà không phải đoán — máy chủ vẫn là nơi quyết định khi lưu.
            'assignable' => $this->assignableUsers($user),
        ]);
    }

    /**
     * GET /studio/projects/{project} — chi tiết 1 dự án + generations.
     */
    public function show(Request $request, Project $project)
    {
        // Owner hoặc Super Admin (reviewer cần xem dự án của Designer trước khi duyệt).
        $actor = $request->user();
        abort_unless(team_can_view_project($actor, $project), 403);
        $project->load(['generations' => fn ($q) => $q->latest()->limit(60), 'assets', 'user:id,name', 'assignee:id,name']);

        return response()->json($this->serialize($project, $actor, true));
    }

    /**
     * GET /api/projects/{project}/stats — CHI PHÍ & TIẾN ĐỘ của MỘT bộ sưu tập (Đợt 2).
     *
     * Vì sao: chủ doanh nghiệp cần biết "bộ này đã ngốn bao nhiêu credit, còn bao nhiêu ảnh chưa xong,
     * có ảnh nào lỗi không, còn mấy ngày tới hạn" — trước đây chỉ thấy tổng số ảnh trong danh sách dự án.
     * Số liệu lấy TRỰC TIẾP từ bảng generations (không đếm lại ở client) nên không bao giờ lệch.
     *
     * Quyền: chủ bộ sưu tập hoặc Super Admin — giống show()/export().
     */
    public function stats(Request $request, Project $project): \Illuminate\Http\JsonResponse
    {
        $actor = $request->user();
        abort_unless(team_can_view_project($actor, $project), 403);

        $byStatus = [];
        $rows = $project->generations()
            ->selectRaw('status, count(*) as n, coalesce(sum(credits_cost), 0) as credits')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $byStatus[(string) $row->status] = ['n' => (int) $row->n, 'credits' => (int) $row->credits];
        }

        $count = fn (string $s): int => $byStatus[$s]['n'] ?? 0;
        $total = array_sum(array_map(fn ($r) => $r['n'], $byStatus));
        $creditsUsed = array_sum(array_map(fn ($r) => $r['credits'], $byStatus));

        $deadline = $project->deadline;
        $feedback = $project->feedback()->orderByDesc('id')->first();

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'status_label' => app(\App\Services\ProjectWorkflowService::class)->describe($project, $actor)['status_label'] ?? $project->status,
            ],
            'images' => [
                'total' => $total,
                'completed' => $count('completed'),
                'running' => $count('pending') + $count('processing'),
                'failed' => $count('failed'),
                'by_status' => $byStatus,
            ],
            'credits' => ['used' => $creditsUsed],
            'deadline' => $deadline ? [
                'date' => $deadline->format('d/m/Y'),
                // Âm = đã quá hạn. Tính theo NGÀY (không theo giờ) để "còn 0 ngày" = hạn hôm nay.
                'days_left' => (int) now()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false),
            ] : null,
            'feedback' => [
                'count' => $project->feedback()->count(),
                'latest' => $feedback ? [
                    'author_name' => $feedback->author_name,
                    'decision' => $feedback->decision,
                    'decision_label' => $feedback->decisionLabel(),
                    'message' => $feedback->message,
                    'created_at' => $feedback->created_at?->format('d/m/Y H:i'),
                ] : null,
            ],
        ]);
    }

    /**
     * POST /api/projects/{project}/shots/review — DUYỆT MẪU THEO LÔ (Đợt 2 — 2026-09-19).
     *
     * Vì sao có endpoint này: máy trạng thái của từng ảnh (idea → drafted → selected → fitted →
     * campaign_ready → approved/rejected, whitelist ở App\Models\Generation::SHOT_TRANSITIONS) và
     * endpoint lẻ POST /api/generations/{id}/shot-state đã có từ Đợt 1.1 — nhưng **không giao diện nào
     * gọi tới** (grep 'shot-state' trong resources/js = 0) ⇒ vòng đời duyệt ảnh là tính năng CHẾT với
     * người dùng: họ chỉ thấy ảnh, không biết ảnh nào đã chốt/loại.
     *
     * Một buổi duyệt thật là "xem 20 ảnh rồi chốt 12, loại 8" — gọi 20 request lẻ vừa chậm vừa không có
     * chỗ để báo "3 ảnh không duyệt được vì lý do gì". Endpoint này trả về KẾT QUẢ TỪNG ẢNH nên giao
     * diện nói thật được cái nào xong, cái nào hỏng và vì sao — KHÔNG im lặng bỏ qua.
     *
     * Quy tắc giữ nguyên của hệ thống (không nới ở đây):
     *   - Quyền: chủ bộ sưu tập hoặc Super Admin (giống show/stats/export).
     *   - Ảnh phải THUỘC bộ sưu tập này (id ngoài bộ ⇒ báo lỗi riêng, không đụng ảnh người khác).
     *   - Chỉ ảnh đã tạo XONG mới duyệt được (ảnh pending/failed chưa có gì để duyệt).
     *   - Bước chuyển phải hợp lệ theo whitelist ⇒ nhảy cóc (drafted → approved) bị từ chối kèm giải
     *     thích, thay vì âm thầm đổi trạng thái. Ba thao tác: 'approved' (chốt) · 'rejected' (loại) ·
     *     'next' (đẩy lên bước kế tiếp trên đường thuận).
     *   - Tối đa 60 ảnh/lô (khớp đúng hạn mức generations mà show() trả về).
     */
    public function reviewShots(Request $request, Project $project): \Illuminate\Http\JsonResponse
    {
        $actor = $request->user();
        abort_unless(team_can_view_project($actor, $project), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:60'],
            'ids.*' => ['integer'],
            // 'next' = đẩy lên BƯỚC KẾ TIẾP trên đường thuận (không nhảy cóc) — thao tác chính của một
            // buổi duyệt: chọn ảnh đạt rồi đẩy lên bước sau thay vì bắt người dùng tự đi từng bước.
            'state' => ['required', 'string', Rule::in([Generation::SHOT_APPROVED, Generation::SHOT_REJECTED, 'next'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $shots = $project->generations()->whereIn('id', $ids)->get()->keyBy('id');
        $label = $data['state'] === Generation::SHOT_APPROVED ? 'duyệt'
            : ($data['state'] === Generation::SHOT_REJECTED ? 'loại' : 'chuyển bước');

        $results = [];
        $done = 0;

        foreach ($ids as $id) {
            $shot = $shots->get($id);

            if (! $shot) {
                $results[] = ['id' => $id, 'ok' => false, 'error' => 'Ảnh không thuộc bộ sưu tập này.'];
                continue;
            }

            if ($shot->status !== 'completed') {
                $results[] = [
                    'id' => $id,
                    'ok' => false,
                    'shot_state' => $shot->shot_state ?: 'drafted',
                    'error' => 'Chỉ '.$label.' được ảnh đã tạo xong (ảnh này đang ở trạng thái tạo: '.$shot->status.').',
                ];
                continue;
            }

            $from = $shot->shot_state ?: 'drafted';

            // 'next' được giải thành trạng thái cụ thể Ở ĐÂY rồi vẫn đi qua đúng whitelist bên dưới —
            // không có đường tắt nào cho thao tác theo lô.
            $target = (string) $data['state'];
            if ($target === 'next') {
                $target = Generation::nextShotState($from) ?? '';
                if ($target === '') {
                    $results[] = [
                        'id' => $id, 'ok' => false, 'shot_state' => $from,
                        'error' => 'Ảnh đã ở bước cuối (Đã duyệt) — không còn bước kế tiếp.',
                    ];
                    continue;
                }
            }

            try {
                $shot->transitionShotState($target, $data['note'] ?? null);
            } catch (\InvalidArgumentException $e) {
                // Whitelist từ chối: giữ nguyên ảnh, báo rõ vì sao (không "thử lại rồi tính").
                $results[] = ['id' => $id, 'ok' => false, 'shot_state' => $from, 'error' => $e->getMessage()];
                continue;
            }

            $done++;
            $results[] = ['id' => $id, 'ok' => true, 'from' => $from, 'shot_state' => $shot->shot_state];
        }

        return response()->json([
            'ok' => true,
            'state' => $data['state'],
            'reviewed' => $done,
            'failed' => count($results) - $done,
            'results' => $results,
        ]);
    }

    /**
     * GET /api/projects/{project}/export — TẢI GÓI SẢN XUẤT CHO XƯỞNG (ZIP).
     *
     * Người nhận là XƯỞNG MAY (không dùng FabrikAI), nên gói phải tự đủ nghĩa: ảnh tham chiếu + phiếu
     * kỹ thuật + bảng size + manifest đọc được bằng máy, kèm cảnh báo ảnh AI là ảnh tham chiếu.
     *
     * Quyền: chủ bộ sưu tập hoặc Super Admin (reviewer) — giống show().
     * Tham số: sizes (bảng size, mỗi dòng một size) · note (ghi chú kỹ thuật chung).
     */
    public function exportBundle(Request $request, Project $project)
    {
        $actor = $request->user();
        abort_unless(team_can_view_project($actor, $project), 403);

        $data = $request->validate([
            'sizes' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $bundle = app(\App\Services\ProjectExportService::class)->build($project, [
                'sizes' => $data['sizes'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Không đóng gói được: '.$e->getMessage()], 500);
        }

        // deleteFileAfterSend: ZIP chỉ tồn tại trong lúc tải, không để lại rác trong /tmp.
        return response()->download($bundle['path'], $bundle['name'], [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * POST /studio/projects — tạo dự án mới (mặc định status=draft).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_concept' => ['nullable', 'string', 'max:1000'],
            'brief' => ['nullable', 'string', 'max:4000'],
            'deadline' => ['nullable', 'date'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'color' => ['nullable', 'string', 'max:20'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            // [P1.2] Người phụ trách — giao việc ngay lúc tạo bộ sưu tập.
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $data['assignee_id'] = $this->resolveAssignee(null, $request->user(), $data['assignee_id'] ?? null);

        // `status` không còn fillable — Project::$attributes mặc định đã là
        // STATUS_DRAFT, mọi chuyển trạng thái sau này phải đi qua transition().
        $project = $request->user()->projects()->create(array_merge($data, [
            'tags' => $data['tags'] ?? [],
        ]));

        if ($request->wantsJson()) {
            return response()->json($this->serialize($project->fresh(), $request->user()), 201);
        }

        return redirect('/')->with('success', 'Đã tạo dự án.');
    }

    /**
     * PUT /studio/projects/{project} — cập nhật dự án (không đổi status qua đây).
     */
    public function update(Request $request, Project $project)
    {
        // [Q4] Sửa thông tin bộ sưu tập (tên · hạn · brief · tags) là việc của CHỦ bộ sưu tập; thành
        // viên trong nhóm được làm việc trên bộ sưu tập nhưng không đổi "vỏ" của nó.
        abort_unless(team_can_manage_project($request->user(), $project), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'base_concept' => ['nullable', 'string', 'max:1000'],
            'brief' => ['nullable', 'string', 'max:4000'],
            'deadline' => ['nullable', 'date'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'color' => ['nullable', 'string', 'max:20'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'sort' => ['nullable', 'integer', 'min:0'],
            // [P1.2] Giao / đổi / bỏ người phụ trách. Gửi null = bỏ giao (về chủ bộ sưu tập).
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (array_key_exists('assignee_id', $data)) {
            $data['assignee_id'] = $this->resolveAssignee($project, $request->user(), $data['assignee_id']);
        }

        if (isset($data['tags'])) {
            $data['tags'] = array_values(array_map('strval', $data['tags']));
        }
        $project->update($data);

        return response()->json($this->serialize($project->fresh(), $request->user()));
    }

    /**
     * DELETE /studio/projects/{project} — xóa dự án (generations detach, không cascade).
     */
    public function destroy(Request $request, Project $project)
    {
        // [Q4] Xoá bộ sưu tập: CHỈ chủ bộ sưu tập hoặc Super Admin. Thành viên dù là "ghế" trong nhóm
        // cũng không được xoá công việc chung.
        abort_unless(team_can_manage_project($request->user(), $project), 403);

        // Detach generations (set project_id null) để không mất output đã tạo.
        // Bọc transaction: nếu delete fail giữa chừng thì detach cũng rollback,
        // đóng race-window "generation vừa attach xong bị mồ côi" (FK generations
        // đã có nullOnDelete nhưng explicit detach giữ hành vi giống nhau trên mọi DB).
        DB::transaction(function () use ($project) {
            $project->generations()->update(['project_id' => null]);
            // ẢNH TẢI LÊN: bỏ liên kết bộ sưu tập (KHÔNG xoá file trên đĩa). Khai báo tường minh cho
            // cùng hành vi với generations ở trên, dù khoá ngoại đã có cascadeOnDelete.
            \App\Models\UploadProjectLink::where('project_id', $project->id)->delete();
            // [R6] Soft-delete thủ công: đánh dấu thay vì xoá vĩnh viễn — người dùng có thể khôi phục
            // từ thùng rác (hoặc xoá vĩnh viễn sau 30 ngày).
            $project->forceFill(['deleted_at' => now()])->save();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * POST /studio/projects/{project}/transition — chuyển trạng thái workflow.
     * Body: { to: 'review', note?: '...' }
     */
    public function transition(Request $request, Project $project)
    {
        // Owner tự chuyển trạng thái của mình; Super Admin (reviewer) được duyệt
        // dự án BẤT KỲ — gate tách nhiệm vụ nằm trong ProjectWorkflowService.
        $actor = $request->user();
        // [Q4] Thành viên trong nhóm KHÔNG đổi trạng thái bộ sưu tập (vẫn tạo ảnh + duyệt mẫu bình thường).
        abort_unless(team_can_manage_project($actor, $project), 403);

        $data = $request->validate([
            // in: chặn chuỗi lạ ngay ở tầng validation, không để lọt xuống engine.
            'to' => ['required', 'string', Rule::in(Project::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'to.in' => 'Trạng thái không hợp lệ.',
            'to.required' => 'Thiếu trạng thái đích.',
        ]);

        try {
            $project = $this->workflow->transition($project, $data['to'], $actor, $data['note'] ?? null);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // Lỗi ngoài nghiệp vụ (DB, v.v.) → log server-side, trả message chung,
            // không rò chi tiết kỹ thuật cho client.
            report($e);
            return response()->json(['message' => 'Có lỗi máy chủ khi chuyển trạng thái. Vui lòng thử lại.'], 500);
        }

        return response()->json($this->serialize($project->fresh(), $actor));
    }

    /**
     * POST /studio/projects/{project}/generations — gán một generation vào dự án.
     * Body: { generation_id: 123, action: 'attach'|'detach' }
     */
    public function attachGeneration(Request $request, Project $project)
    {
        $actor = $request->user();
        abort_unless(team_can_view_project($actor, $project), 403);

        $data = $request->validate([
            // [P1.3] Nhận MỘT id (đường cũ) hoặc NHIỀU id — một buổi làm việc thật là "chọn 30 ảnh
            // trong Thư viện rồi đưa cả vào bộ sưu tập", không phải 30 request lẻ.
            'generation_id' => ['nullable', 'integer', 'exists:generations,id', 'required_without:ids'],
            'ids' => ['nullable', 'array', 'max:100', 'required_without:generation_id'],
            'ids.*' => ['integer'],
            'action' => ['nullable', 'string', 'in:attach,detach'],
        ]);

        $detach = ($data['action'] ?? 'attach') === 'detach';
        $ids = $data['ids'] ?? [(int) $data['generation_id']];
        $ids = array_values(array_unique(array_map('intval', $ids)));

        // GẮN: chỉ gắn được ảnh CỦA CHÍNH MÌNH (không nhặt ảnh người khác vào bộ của mình).
        // GỠ: chủ bộ sưu tập (hoặc Super Admin) gỡ được ẢNH CỦA NGƯỜI KHÁC khỏi bộ sưu tập của mình —
        // đây là lỗ hổng vận hành đo được: nhân viên rời nhóm để lại ảnh mà chủ nhóm không có cách nào
        // dọn, đường duy nhất là xoá cả bộ sưu tập.
        $canDetachForeign = $detach && team_can_manage_project($actor, $project);

        $query = Generation::query()->whereIn('id', $ids);
        if ($detach) {
            // Chỉ ảnh ĐANG thuộc bộ sưu tập này mới gỡ được (không đụng ảnh của bộ khác).
            $query->where('project_id', $project->id);
            if (! $canDetachForeign) {
                $query->where('user_id', $actor->id);
            }
        } else {
            $query->where('user_id', $actor->id);
        }

        $affected = $query->update(['project_id' => $detach ? null : $project->id]);

        // Trả kết quả TỪNG ảnh để giao diện nói thật cái nào đổi được, cái nào không — cùng nguyên
        // tắc với /shots/review, không im lặng bỏ qua.
        $results = [];
        foreach ($ids as $id) {
            $results[] = ['id' => $id, 'ok' => false];
        }
        $touched = Generation::query()->whereIn('id', $ids)
            ->where(fn ($q) => $detach ? $q->whereNull('project_id') : $q->where('project_id', $project->id))
            ->pluck('id')->all();
        foreach ($results as &$row) {
            $row['ok'] = in_array($row['id'], $touched, true);
        }
        unset($row);

        $done = count(array_filter($results, fn ($r) => $r['ok']));

        return response()->json([
            'ok' => true,
            'action' => $detach ? 'detach' : 'attach',
            'project_id' => $detach ? null : (int) $project->id,
            'changed' => $affected,
            'done' => $done,
            'failed' => count($results) - $done,
            'results' => $results,
            // Giữ khoá cũ cho client hiện tại (GalleryModal/ProjectWorkspace).
            'generation' => count($ids) === 1 ? [
                'id' => $ids[0],
                'project_id' => $detach ? null : (int) $project->id,
            ] : null,
        ]);
    }

    /**
     * Gắn / bỏ gắn một ẢNH TẢI LÊN vào dự án (bộ sưu tập).
     *
     * Vì sao có endpoint này: ảnh tải lên là FILE trên đĩa, không có dòng trong CSDL, nên trước đây
     * KHÔNG có cách nào đưa chúng vào bộ sưu tập — trong khi ảnh do AI tạo thì gắn được. Hai loại ảnh
     * nằm cùng một Thư viện nhưng chỉ một loại vào được bộ sưu tập là điều vô lý với người dùng.
     *
     * KHÁC thao tác sinh ảnh ở chỗ: gắn bộ sưu tập CHÍNH LÀ mục đích của request, nên đường dẫn sai
     * phải trả 403 thay vì im lặng bỏ qua (im lặng ở đây nghĩa là người dùng bấm mà không có gì xảy ra).
     */
    public function attachUpload(Request $request, Project $project)
    {
        abort_unless(team_can_view_project($request->user(), $project), 403);

        $data = $request->validate([
            'rel' => ['required', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'in:attach,detach'],
        ]);

        $rel = app(\App\Services\StudioLibraryService::class)->ownedUploadRel($request->user(), (string) $data['rel']);
        abort_if($rel === '', 403, 'Ảnh không hợp lệ hoặc không thuộc về bạn.');

        $detach = ($data['action'] ?? 'attach') === 'detach';

        if ($detach) {
            \App\Models\UploadProjectLink::where('user_id', $request->user()->id)->where('rel', $rel)->delete();
        } else {
            \App\Models\UploadProjectLink::updateOrCreate(
                ['user_id' => $request->user()->id, 'rel' => $rel],
                ['project_id' => $project->id],
            );
        }

        return response()->json(['ok' => true, 'rel' => $rel, 'project_id' => $detach ? null : $project->id]);
    }

    /**
     * [P1.2] Danh sách người có thể được GIAO VIỆC: chính người dùng + chủ nhóm (nếu là thành viên)
     * + các thành viên trong nhóm của chính người dùng (nếu là chủ nhóm).
     *
     * Chỉ trả id + tên — đủ để đổ vào ô chọn, không lộ email/SĐT của đồng nghiệp.
     */
    protected function assignableUsers($user): array
    {
        $ids = [(int) $user->id];

        if ($user->isTeamMember()) {
            $ids[] = (int) $user->team_owner_id;
        }

        $ids = array_merge($ids, \App\Models\User::query()
            ->where('team_owner_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());

        return \App\Models\User::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()
            ->all();
    }

    /**
     * [P1.2] Kiểm tra người được giao phải NẰM TRONG nhóm làm việc của bộ sưu tập.
     * Trả về id hợp lệ hoặc null; ném 422 kèm câu tiếng Việt nếu giao cho người ngoài nhóm.
     */
    protected function resolveAssignee(?Project $project, $owner, $assigneeId): ?int
    {
        if ($assigneeId === null || $assigneeId === '') {
            return null;
        }

        $id = (int) $assigneeId;
        if ($id <= 0) {
            return null;
        }

        $allowed = $project
            ? $project->assignableUserIds()
            : array_map(fn ($row) => (int) $row['id'], $this->assignableUsers($owner));

        if (! in_array($id, $allowed, true)) {
            abort(response()->json([
                'message' => 'Chỉ giao được việc cho thành viên trong nhóm làm việc của bộ sưu tập này.',
                'code' => 'assignee_not_in_team',
            ], 422));
        }

        return $id;
    }

    /**
     * Serialize Project + workflow metadata cho frontend.
     */
    protected function serialize(Project $project, $user, bool $withRelations = false): array
    {
        // generations_count: dùng attribute do withCount() set (index/pending);
        // khi chưa có (show/store/update/transition — bối cảnh 1 project) thì
        // loadCount() đúng 1 query thay vì fallback count() rải rác gây N+1.
        if (! isset($project->generations_count)) {
            $project->loadCount('generations');
        }

        $desc = $this->workflow->describe($project, $user);
        $data = [
            'id' => $project->id,
            'name' => $project->name,
            'base_concept' => $project->base_concept,
            'brief' => $project->brief,
            'deadline' => $project->deadline?->toIso8601String(),
            'thumbnail_url' => $project->thumbnail_url,
            'tags' => $project->tags ?? [],
            'color' => $project->color,
            'sort' => (int) $project->sort,
            'archived' => (bool) $project->archived,
            'started_at' => $project->started_at?->toIso8601String(),
            'completed_at' => $project->completed_at?->toIso8601String(),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
            'user_id' => $project->user_id,
            // owner_name chỉ có khi relation user được eager-load (show/pending scope).
            'owner_name' => $project->relationLoaded('user') ? $project->user?->name : null,
            // [P1.2] Ai đang làm bộ sưu tập này. null = chủ bộ sưu tập tự làm.
            'assignee_id' => $project->assignee_id ? (int) $project->assignee_id : null,
            'assignee_name' => $project->relationLoaded('assignee') ? $project->assignee?->name : null,
            'generations_count' => $project->generations_count,
            'thumbnail' => $project->thumbnail,
            // workflow
            ...$desc,
        ];

        if ($withRelations) {
            // [P0.6] ẢNH TẢI LÊN THUỘC BỘ SƯU TẬP — trước đây gắn xong là mất dấu (xem
            // StudioLibraryService::uploadsForProject). Trả về để workspace/export nói được
            // "bộ này có 3 ảnh tham chiếu gốc".
            $data['uploads'] = app(\App\Services\StudioLibraryService::class)->uploadsForProject((int) $project->id);
        }
        if ($withRelations) {
            // Dùng relation đã eager-load (show) thay vì query lại lần hai.
            $generations = $project->relationLoaded('generations')
                ? $project->generations
                : $project->generations()->latest()->limit(60)->get();
            // [P0.6] TRUNG THỰC VỀ SỐ LƯỢNG: workspace chỉ nạp 60 output mới nhất trong khi badge
            // vẫn ghi tổng thật ⇒ bộ 100 ảnh hiển thị "100 ảnh" nhưng chỉ thấy 60, không lời giải
            // thích. Trả đủ ba số để giao diện nói thật và chỉ người dùng sang Thư viện xem phần còn lại.
            $data['generations_shown'] = $generations->count();
            $data['generations_truncated'] = (int) $project->generations_count > $generations->count();
            $data['generations_limit'] = (int) $project->generations_count > $generations->count()
                ? $generations->count()
                : null;

            $data['generations'] = $generations->map(fn ($g) => [
                'id' => $g->id, 'type' => $g->type, 'status' => $g->status,
                'media_url' => $g->media_url, 'prompt' => $g->prompt,
                'model' => $g->model, 'provider' => $g->provider,
                'project_id' => $g->project_id, 'project' => $project->name,
                'created_at' => $g->created_at?->format('d/m H:i'),
                // [Đợt 2] Vòng đời DUYỆT của ảnh — trước đây không trả về nên giao diện không thể biết
                // ảnh nào chờ duyệt/đã chốt/đã loại, dù máy trạng thái đã có từ Đợt 1.1.
                'shot_state' => $g->shot_state ?: 'drafted',
                'shot_label' => Generation::SHOT_LABELS[$g->shot_state ?: 'drafted'] ?? 'Bản nháp',
                'note' => $g->note,
                'is_selected' => (bool) $g->is_selected,
            ])->values();

            $assets = $project->relationLoaded('assets')
                ? $project->assets
                : $project->assets()->orderByPivot('sort')->get(['studio_assets.id', 'type', 'name', 'path']);
            $data['assets'] = $assets->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'name' => $a->name, 'path' => $a->path,
            ])->values();
        }

        return $data;
    }
}
