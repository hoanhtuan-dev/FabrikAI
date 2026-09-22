<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Sample;
use App\Services\SampleTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THEO DÕI MẪU VẬT LÝ của một bộ sưu tập — Việc #4, 2026-09-26.
 *
 * Vì sao là endpoint RIÊNG của dự án: mẫu vật lý là DỮ LIỆU THUỘC BỘ SƯU TẬP (cùng bộ thì cùng xưởng,
 * cùng mã hàng), không phải một lượt chạy agent — phải đọc/ghi được kể cả khi khách tắt công tắc AI.
 *
 * QUYỀN: đọc theo team_can_view_project, ghi theo team_can_manage_project. VÀ mọi đường có {sample} đều
 * kiểm sample.project_id === project.id — id lạ (kể cả id thật của bộ KHÁC) trả 404, không có đường nào
 * để một tài khoản đụng vào mẫu của bộ không thuộc về mình.
 */
class SampleController extends Controller
{
    public function __construct(private readonly SampleTrackingService $samples) {}

    /** GET /api/projects/{project}/samples — bảng theo dõi + đếm giai đoạn + cảnh báo. */
    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_view_project($request->user(), $project), 403);

        return response()->json($this->samples->overview($project));
    }

    /** POST /api/projects/{project}/samples — thêm một mẫu (trạng thái đầu luôn là "đã yêu cầu xưởng"). */
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);

        $data = $request->validate($this->rules());

        try {
            $sample = $this->samples->create($project, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->samples->overview($project) + ['created' => $sample], 201);
    }

    /** PATCH /api/projects/{project}/samples/{sample} — sửa thông tin (KHÔNG đụng trạng thái). */
    public function update(Request $request, Project $project, Sample $sample): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);
        $this->assertBelongs($project, $sample);

        $data = $request->validate($this->rules(creating: false));

        return response()->json($this->samples->overview($project) + ['updated' => $this->samples->update($sample, $data)]);
    }

    /**
     * POST /api/projects/{project}/samples/{sample}/stage — chuyển giai đoạn theo WHITELIST.
     *
     * Bước chuyển không hợp lệ trả 422 kèm danh sách bước được phép — giống hệt đường duyệt ảnh, để
     * "nhảy cóc" bị từ chối ở cả hai nơi theo cùng một cách.
     */
    public function stage(Request $request, Project $project, Sample $sample): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);
        $this->assertBelongs($project, $sample);

        $data = $request->validate([
            'stage' => ['required', 'string', 'in:'.implode(',', Sample::STAGES)],
            'note' => ['nullable', 'string', 'max:'.SampleTrackingService::TEXT_LIMITS['note']],
        ]);

        try {
            $updated = $this->samples->transition($sample, (string) $data['stage'], $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'allowed' => Sample::TRANSITIONS[$sample->stage] ?? [],
            ], 422);
        }

        return response()->json($this->samples->overview($project) + ['updated' => $updated]);
    }

    /** DELETE /api/projects/{project}/samples/{sample} */
    public function destroy(Request $request, Project $project, Sample $sample): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);
        $this->assertBelongs($project, $sample);

        $id = (int) $sample->id;
        $this->samples->delete($sample);

        return response()->json($this->samples->overview($project) + ['deleted' => $id]);
    }

    /** Mẫu phải THUỘC bộ đang gọi — id của bộ khác trả 404, không phải 403 (không xác nhận là nó tồn tại). */
    private function assertBelongs(Project $project, Sample $sample): void
    {
        abort_unless((int) $sample->project_id === (int) $project->id, 404, 'Không tìm thấy mẫu trong bộ sưu tập này.');
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating = true): array
    {
        $rules = ['due_at' => ['nullable', 'date'], 'round' => ['nullable', 'integer', 'min:1', 'max:20']];

        foreach (SampleTrackingService::TEXT_LIMITS as $key => $limit) {
            $rules[$key] = ['nullable', 'string', 'max:'.$limit];
        }

        return $rules;
    }
}
