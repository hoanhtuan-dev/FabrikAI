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
        $projects = $user->projects()
            ->where('archived', $archived)
            ->with('latestGeneration')
            ->withCount('generations')
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
        ]);
    }

    /**
     * GET /studio/projects/{project} — chi tiết 1 dự án + generations.
     */
    public function show(Request $request, Project $project)
    {
        // Owner hoặc Super Admin (reviewer cần xem dự án của Designer trước khi duyệt).
        $actor = $request->user();
        abort_unless($project->user_id === $actor->id || $actor->isSuperAdmin(), 403);
        $project->load(['generations' => fn ($q) => $q->latest()->limit(60), 'assets', 'user:id,name']);

        return response()->json($this->serialize($project, $actor, true));
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
        abort_unless($project->user_id === $actor->id || $actor->isSuperAdmin(), 403);

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
        ]);

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
        abort_unless($project->user_id === $request->user()->id, 403);

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
        ]);

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
        abort_unless($project->user_id === $request->user()->id, 403);

        // Detach generations (set project_id null) để không mất output đã tạo.
        // Bọc transaction: nếu delete fail giữa chừng thì detach cũng rollback,
        // đóng race-window "generation vừa attach xong bị mồ côi" (FK generations
        // đã có nullOnDelete nhưng explicit detach giữ hành vi giống nhau trên mọi DB).
        DB::transaction(function () use ($project) {
            $project->generations()->update(['project_id' => null]);
            $project->delete();
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
        abort_unless($project->user_id === $actor->id || $actor->isSuperAdmin(), 403);

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
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'generation_id' => ['required', 'integer', 'exists:generations,id'],
            'action' => ['nullable', 'string', 'in:attach,detach'],
        ]);

        $gen = Generation::where('id', $data['generation_id'])
            ->where('user_id', $request->user()->id)
            ->first();
        abort_unless($gen, 403);

        if (($data['action'] ?? 'attach') === 'detach') {
            $gen->update(['project_id' => null]);
        } else {
            $gen->update(['project_id' => $project->id]);
        }

        return response()->json(['ok' => true, 'generation' => [
            'id' => $gen->id, 'project_id' => $gen->project_id,
        ]]);
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
            'generations_count' => $project->generations_count,
            'thumbnail' => $project->thumbnail,
            // workflow
            ...$desc,
        ];
        if ($withRelations) {
            // Dùng relation đã eager-load (show) thay vì query lại lần hai.
            $generations = $project->relationLoaded('generations')
                ? $project->generations
                : $project->generations()->latest()->limit(60)->get();
            $data['generations'] = $generations->map(fn ($g) => [
                'id' => $g->id, 'type' => $g->type, 'status' => $g->status,
                'media_url' => $g->media_url, 'prompt' => $g->prompt,
                'model' => $g->model, 'provider' => $g->provider,
                'project_id' => $g->project_id, 'project' => $project->name,
                'created_at' => $g->created_at?->format('d/m H:i'),
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
