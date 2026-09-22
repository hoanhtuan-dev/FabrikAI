<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\QcInspection;
use App\Services\QcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KIỂM TRA CHẤT LƯỢNG của một bộ sưu tập — Việc #6, 2026-09-26.
 *
 * Vì sao là endpoint RIÊNG của dự án: biên bản QC là DỮ LIỆU THUỘC BỘ (cùng bộ thì cùng mã hàng, cùng
 * phiếu kỹ thuật), không phải một lượt chạy agent — phải đọc/ghi được kể cả khi khách tắt công tắc AI.
 *
 * QUYỀN: đọc theo team_can_view_project, ghi theo team_can_manage_project. VÀ mọi đường có {inspection}
 * đều kiểm inspection.project_id === project.id — id lạ trả 404, không xác nhận là nó tồn tại.
 */
class QcController extends Controller
{
    public function __construct(private readonly QcService $qc) {}

    /** GET /api/projects/{project}/qc-inspections */
    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_view_project($request->user(), $project), 403);

        return response()->json($this->qc->overview($project));
    }

    /** POST /api/projects/{project}/qc-inspections — mở biên bản, chốt kế hoạch lấy mẫu ngay. */
    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);

        $data = $request->validate($this->rules());

        try {
            $created = $this->qc->create($project, $data);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // ĐỌC TRƯỚC KHI GHI: trong `A + B` PHP tính hạng TRÁI trước, nên nếu viết overview() ở bên trái
        // thì bảng trả về là ảnh chụp TRƯỚC khi thêm biên bản — giao diện sẽ hiện thiếu đúng dòng vừa tạo.
        return response()->json(['created' => $created] + $this->qc->overview($project), 201);
    }

    /** PATCH /api/projects/{project}/qc-inspections/{inspection} — ghi kết quả kiểm, máy tính lại kết luận. */
    public function update(Request $request, Project $project, QcInspection $inspection): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);
        abort_unless((int) $inspection->project_id === (int) $project->id, 404, 'Không tìm thấy biên bản trong bộ sưu tập này.');

        $data = $request->validate($this->rules(creating: false));

        $updated = $this->qc->update($inspection, $data);

        return response()->json(['updated' => $updated] + $this->qc->overview($project));
    }

    /** DELETE /api/projects/{project}/qc-inspections/{inspection} */
    public function destroy(Request $request, Project $project, QcInspection $inspection): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);
        abort_unless((int) $inspection->project_id === (int) $project->id, 404, 'Không tìm thấy biên bản trong bộ sưu tập này.');

        $id = (int) $inspection->id;
        $this->qc->destroy($inspection);

        return response()->json(['deleted' => $id] + $this->qc->overview($project));
    }

    /** @return array<string, list<string>> */
    private function rules(bool $creating = true): array
    {
        $rules = [
            'sample_id' => ['nullable', 'integer', 'min:1'],
            'style_no' => ['nullable', 'string', 'max:40'],
            'stage' => ['nullable', 'string', 'in:'.implode(',', QcService::STAGES)],
            'lot_size' => ['nullable', 'integer', 'min:0', 'max:'.QcService::MAX_LOT_SIZE],
            'aql' => ['nullable', 'string', 'in:'.implode(',', QcService::AQL_LEVELS)],
            'critical' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'major' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'minor' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:'.QcService::NOTE_MAX],
            'inspected_at' => ['nullable', 'date'],
            'checklist' => ['nullable', 'array', 'max:'.QcService::MAX_CHECKLIST_ITEMS],
            'checklist.*.key' => ['nullable', 'string', 'max:20'],
            'checklist.*.label' => ['required_with:checklist', 'string', 'max:'.QcService::LABEL_MAX],
            'checklist.*.result' => ['nullable', 'string', 'in:pass,fail,na'],
            'checklist.*.note' => ['nullable', 'string', 'max:240'],
        ];

        if ($creating) {
            // Khi MỞ biên bản: cỡ lô là thứ quyết định kế hoạch lấy mẫu, nên bắt buộc có — mở một biên bản
            // không biết lô bao nhiêu cái là biên bản không dùng được.
            $rules['lot_size'] = ['required', 'integer', 'min:1', 'max:'.QcService::MAX_LOT_SIZE];
        }

        return $rules;
    }
}
