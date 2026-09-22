<?php

namespace App\Http\Controllers;

use App\Models\ProductionLog;
use App\Models\Project;
use App\Services\ProductionTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TIẾN ĐỘ SẢN XUẤT — thực tế so với kế hoạch (Việc #8 · 2026-09-26).
 *
 * Vì sao endpoint riêng của dự án: sản lượng là DỮ LIỆU THUỘC BỘ (cùng mã hàng, cùng kế hoạch, cùng hạn),
 * và nó ghi theo NGÀY nên cần đường riêng để một lần ghi = một dòng — không nhét vào payload cập nhật dự án.
 *
 * QUYỀN: đọc theo team_can_view_project, ghi theo team_can_manage_project; mọi đường có {log} đều kiểm
 * log.project_id === project.id ⇒ id lạ trả 404, không xác nhận là nó tồn tại.
 */
class ProductionController extends Controller
{
    public function __construct(private readonly ProductionTrackingService $production) {}

    /** GET /api/projects/{project}/production */
    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_view_project($request->user(), $project), 403);

        return response()->json($this->production->overview($project));
    }

    /** POST /api/projects/{project}/production — ghi (hoặc sửa) sản lượng của MỘT ngày. */
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);

        $data = $request->validate([
            'logged_on' => ['required', 'date'],
            'units_done' => ['required', 'integer', 'min:0', 'max:100000000'],
            'units_defect' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:'.ProductionTrackingService::NOTE_MAX],
        ]);

        try {
            $overview = $this->production->log($project, $data, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($overview);
    }

    /** DELETE /api/projects/{project}/production/{log} */
    public function destroy(Request $request, Project $project, ProductionLog $log): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);
        abort_unless((int) $log->project_id === (int) $project->id, 404, 'Không tìm thấy ngày sản lượng trong bộ sưu tập này.');

        $this->production->delete($log);

        return response()->json($this->production->overview($project));
    }
}
