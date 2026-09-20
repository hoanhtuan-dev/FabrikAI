<?php

namespace App\Http\Controllers;

use App\Services\BrandDnaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DNA THƯƠNG HIỆU của chính người dùng đang đăng nhập — xem và sửa (2026-09-23).
 *
 * Vì sao là endpoint RIÊNG chứ không nhét vào /api/design-agent/*: đây là HỒ SƠ của người dùng,
 * không phải một lần chạy agent. Nó phải đọc/ghi được kể cả khi gói của khách không có module agent
 * (người dùng vẫn có quyền khai mình là ai — và dữ liệu đó không được biến mất khi đổi gói).
 *
 * Quy tắc validate sinh TỪ BrandDnaService (một nguồn khai báo hình dạng): trần ký tự, trần số mục
 * và whitelist dải giá đều lấy từ đó, nên không thể có chuyện validate cho qua rồi service cắt bớt
 * mà không ai biết.
 */
class BrandDnaController extends Controller
{
    public function __construct(private readonly BrandDnaService $dna) {}

    /** GET /api/brand-dna — hồ sơ hiện tại + nhãn các trường để giao diện không tự chế chữ. */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    /** PUT /api/brand-dna — lưu hồ sơ (upsert theo tài khoản). */
    public function update(Request $request): JsonResponse
    {
        $request->validate($this->rules());

        $this->dna->save($request->user(), $request->only(array_keys($this->rules())));

        return response()->json($this->payload($request) + ['saved' => true]);
    }

    /** DELETE /api/brand-dna — trả hồ sơ về "chưa khai" (KHÔNG đụng dữ liệu bán hàng / dự án). */
    public function destroy(Request $request): JsonResponse
    {
        $this->dna->reset($request->user());

        return response()->json($this->payload($request) + ['saved' => true]);
    }

    /**
     * Luật validate dựng từ chính khai báo của service.
     *
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $rules = ['price_band' => ['nullable', 'string', 'in:'.implode(',', BrandDnaService::PRICE_BANDS)]];

        foreach (BrandDnaService::TEXT_FIELDS as $field => $limit) {
            $rules[$field] = ['nullable', 'string', 'max:'.$limit];
        }

        foreach (BrandDnaService::LIST_FIELDS as $field => [$maxItems, $maxChars]) {
            $rules[$field] = ['nullable', 'array', 'max:'.$maxItems];
            $rules[$field.'.*'] = ['nullable', 'string', 'max:'.$maxChars];
        }

        return $rules;
    }

    /** Hình dạng phản hồi dùng CHUNG cho cả ba hành động — không có ba bản chép lệch nhau. */
    private function payload(Request $request): array
    {
        $current = $this->dna->get($request->user());

        return [
            'dna' => $current['data'],
            'is_set' => $current['is_set'],
            'updated_at' => $current['updated_at'],
            'summary' => $current['summary'],
            'labels' => BrandDnaService::FIELD_LABELS,
            'price_bands' => array_map(
                fn (string $id) => ['id' => $id, 'label' => BrandDnaService::PRICE_BAND_LABELS[$id]],
                BrandDnaService::PRICE_BANDS,
            ),
            'limits' => [
                'text' => BrandDnaService::TEXT_FIELDS,
                'lists' => BrandDnaService::LIST_FIELDS,
            ],
        ];
    }
}
