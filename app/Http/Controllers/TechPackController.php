<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\TechPackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PHIẾU KỸ THUẬT (tech pack) của một bộ sưu tập — 2026-09-26.
 *
 * Vì sao là endpoint RIÊNG của dự án (không nhét vào /api/design-agent/*): phiếu kỹ thuật là DỮ LIỆU
 * THUỘC BỘ SƯU TẬP, không phải một lượt chạy agent. Nó phải đọc/sửa được kể cả khi khách tắt công tắc
 * "Suy luận AI" — thông số họ tự gõ không được phụ thuộc vào model nào.
 *
 * Validate lấy TRẦN từ chính TechPackService (một nguồn khai báo hình dạng) để không thể có chuyện
 * validate cho qua rồi service cắt bớt mà không ai biết.
 */
class TechPackController extends Controller
{
    public function __construct(private readonly TechPackService $packs) {}

    /** GET /api/projects/{project}/tech-pack */
    public function show(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_view_project($request->user(), $project), 403);

        return response()->json($this->payload($project));
    }

    /** PUT /api/projects/{project}/tech-pack — upsert theo dự án. */
    public function update(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);

        $request->validate($this->rules());

        $this->packs->save($project, $request->only(['fields', 'sizes', 'measurements']));

        return response()->json($this->payload($project) + ['saved' => true]);
    }

    /** DELETE /api/projects/{project}/tech-pack — về "chưa lập" (KHÔNG đụng ảnh hay dự án). */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        abort_unless(team_can_manage_project($request->user(), $project), 403);

        $this->packs->reset($project);

        return response()->json($this->payload($project) + ['saved' => true]);
    }

    /**
     * GET /du-an/{project}/phieu-ky-thuat — BẢN IN A4.
     *
     * VÌ SAO LÀ TRANG IN chứ không phải một endpoint trả file PDF: máy chủ dùng chung này CHẶN
     * proc_open (đã ghi nhiều lần trong DEPLOY_LOG) nên thêm một thư viện PDF qua composer là rủi ro
     * thật lúc deploy; và repo này cũng không thêm phụ thuộc khi chưa cần. Trình duyệt của chính người
     * dùng đã có sẵn "In → Lưu thành PDF" — cùng kết quả, không thêm gì phải cài, và chạy được cả trên
     * điện thoại.
     */
    public function print(Request $request, Project $project): View
    {
        abort_unless(team_can_view_project($request->user(), $project), 403);

        $pack = $this->packs->get($project);

        // Ảnh tham chiếu: CHỈ liệt kê tên tệp, không nhúng ảnh — trang A4 phải in được cả khi mạng chậm,
        // và ảnh gốc đã nằm trong gói ZIP gửi xưởng.
        $images = $project->generations()
            ->whereNotNull('media_url')
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'prompt', 'shot_state'])
            ->map(fn ($g) => [
                'id' => (int) $g->id,
                'prompt' => (string) $g->prompt,
                'state' => (string) ($g->shot_state ?: 'drafted'),
            ])
            ->all();

        return view('studio.tech-pack-print', [
            'project' => $project,
            'pack' => $pack['data'],
            'completeness' => $pack['completeness'],
            'updatedAt' => $pack['updated_at'],
            'shape' => TechPackService::shape(),
            'table' => $this->packs->measurementTable($pack['data']),
            'images' => $images,
            'generatedAt' => now(),
        ]);
    }

    /**
     * Luật validate dựng từ chính khai báo của service.
     *
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $rules = [
            'fields' => ['nullable', 'array'],
            'sizes' => ['nullable', 'array', 'max:'.TechPackService::MAX_SIZE_LABELS],
            'sizes.*' => ['nullable', 'string', 'max:'.TechPackService::SIZE_LABEL_MAX],
            'measurements' => ['nullable', 'array', 'max:'.TechPackService::MAX_MEASUREMENT_ROWS],
            'measurements.*.point' => ['nullable', 'string', 'max:'.TechPackService::POINT_MAX],
            'measurements.*.tolerance' => ['nullable', 'string', 'max:'.TechPackService::TOLERANCE_MAX],
            // `values` nhận HAI dạng, vì TechPackService::normalize() cũng nhận cả hai:
            //   · mảng map size => số đo (dạng giao diện gửi lên),
            //   · chuỗi nhập nhanh "96 / 100 / 104" (thứ tự khớp cột size).
            // Chặn ở đây thành "chỉ mảng" là biến một tiện ích của service thành mã không ai gọi tới.
            // Dạng lồng vẫn bị chặn: rule `values.*` chỉ nhận chuỗi nên map-of-array sẽ trượt.
            'measurements.*.values' => ['nullable'],
            'measurements.*.values.*' => ['nullable', 'string', 'max:'.TechPackService::MEASUREMENT_VALUE_MAX],
        ];

        foreach (TechPackService::TEXT_FIELDS as $key => $max) {
            $rules['fields.'.$key] = ['nullable', 'string', 'max:'.$max];
        }

        return $rules;
    }

    /** Hình dạng phản hồi dùng CHUNG cho cả ba hành động — không có ba bản chép lệch nhau. */
    private function payload(Project $project): array
    {
        $pack = $this->packs->get($project);

        return [
            'tech_pack' => $pack['data'],
            'is_set' => $pack['is_set'],
            'updated_at' => $pack['updated_at'],
            'completeness' => $pack['completeness'],
            'shape' => TechPackService::shape(),
        ];
    }
}
