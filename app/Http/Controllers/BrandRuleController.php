<?php

namespace App\Http\Controllers;

use App\Services\BrandRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QUY TẮC LÀM VIỆC của chính người dùng đang đăng nhập (trí nhớ thủ tục — GĐ2, 2026-09-26).
 *
 * Vì sao là endpoint RIÊNG, cạnh /api/brand-dna chứ không nhét vào /api/design-agent/*: đây là HỒ SƠ
 * CỦA NGƯỜI DÙNG, không phải một lượt chạy agent. Nó phải đọc/ghi được kể cả khi gói của khách không có
 * module agent — và dữ liệu họ đã viết không được biến mất khi đổi gói.
 *
 * Validate lấy TRẦN từ chính BrandRuleService (một nguồn khai báo hình dạng): không thể có chuyện
 * validate cho qua rồi service cắt bớt mà không ai biết.
 */
class BrandRuleController extends Controller
{
    public function __construct(private readonly BrandRuleService $rules) {}

    /** GET /api/brand-rules — danh sách hiện tại + trần/nhãn để giao diện không tự chế chữ. */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /** PUT /api/brand-rules — ghi đè tập hợp quy tắc đang thấy (ngữ nghĩa ở BrandRuleService::save). */
    public function update(Request $request): JsonResponse
    {
        $request->validate($this->validationRules());

        $saved = $this->rules->save($request->user(), (array) $request->input('rules', []));

        return response()->json([
            'rules' => $saved['rules'],
            'limits' => BrandRuleService::limits(),
            // NÓI THẬT khi có hàng bị bỏ: người dùng gõ mà không thấy nó quay lại thì họ phải biết vì sao.
            'dropped' => $saved['dropped'],
            'truncated' => $saved['truncated'],
            'saved' => true,
        ]);
    }

    /** DELETE /api/brand-rules — xoá hết quy tắc (KHÔNG đụng DNA / dữ liệu bán / trí nhớ sự kiện). */
    public function destroy(Request $request): JsonResponse
    {
        $this->rules->reset($request->user());

        return response()->json($this->payload($request) + ['saved' => true]);
    }

    /**
     * Luật validate dựng từ chính khai báo của service.
     *
     * @return array<string, list<string>>
     */
    private function validationRules(): array
    {
        return [
            'rules' => ['present', 'array', 'max:'.BrandRuleService::MAX_RULES],
            'rules.*.id' => ['nullable', 'integer', 'min:1'],
            'rules.*.trigger' => ['nullable', 'string', 'max:'.BrandRuleService::TRIGGER_MAX],
            'rules.*.action' => ['nullable', 'string', 'max:'.BrandRuleService::ACTION_MAX],
            'rules.*.weight' => ['nullable', 'integer', 'min:'.BrandRuleService::WEIGHT_MIN, 'max:'.BrandRuleService::WEIGHT_MAX],
            'rules.*.source' => ['nullable', 'string', 'in:'.implode(',', BrandRuleService::SOURCES)],
            'rules.*.is_active' => ['nullable', 'boolean'],
        ];
    }

    /** Hình dạng phản hồi dùng CHUNG cho cả ba hành động — không có ba bản chép lệch nhau. */
    private function payload(Request $request): array
    {
        return [
            'rules' => $this->rules->all($request->user()),
            'limits' => BrandRuleService::limits(),
            'dropped' => 0,
            'truncated' => 0,
        ];
    }
}
