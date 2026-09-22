<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectGate;
use App\Services\ProjectGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BA CỔNG DUYỆT của một bộ sưu tập (Việc #7 · 2026-09-26) — xem ProjectGateService.
 *
 * Vì sao endpoint riêng của dự án: cổng duyệt là DỮ LIỆU THUỘC BỘ và phải có VẾT (ai · khi nào · vì sao).
 * Nhét vào payload cập nhật dự án thì mọi lần đổi tên bộ cũng ghi lại được một quyết định duyệt — không
 * còn phân biệt được "ai duyệt" với "ai vừa sửa tên".
 *
 * QUYỀN: đọc theo team_can_view_project, GHI theo team_can_manage_project. Người ngoài nhận 403 chứ
 * không phải 404: họ không được biết bộ này có tồn tại hay không là chuyện của 404, còn ở đây họ ĐÃ gọi
 * đúng id của một bộ họ không có quyền — 403 nói đúng sự thật đó.
 */
class ProjectGateController extends Controller
{
    public function __construct(private readonly ProjectGateService $gates) {}

    /** GET /api/projects/{project}/gates */
    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_view_project($request->user(), $project), 403);

        return response()->json($this->gates->overview($project));
    }

    /** POST /api/projects/{project}/gates/{gate} — duyệt · không duyệt · rút lại · mở lại. */
    public function decide(Request $request, Project $project, string $gate): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);

        $data = $request->validate([
            'decision' => ['required', 'string', 'in:'.implode(',', ProjectGate::DECISIONS)],
            'note' => ['nullable', 'string', 'max:'.ProjectGateService::NOTE_MAX],
        ]);

        try {
            $overview = $this->gates->decide(
                $project,
                $gate,
                (string) $data['decision'],
                $data['note'] ?? null,
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            // 422 kèm các bước ĐƯỢC PHÉP — giao diện vẽ lại nút theo đúng luật máy chủ, không đoán.
            return response()->json([
                'message' => $e->getMessage(),
                'allowed' => ProjectGate::allowedFrom(
                    (string) (ProjectGate::query()->where('project_id', $project->id)->where('gate', $gate)->value('decision') ?? ProjectGate::DECISION_PENDING),
                ),
            ], 422);
        }

        return response()->json($overview);
    }
}
