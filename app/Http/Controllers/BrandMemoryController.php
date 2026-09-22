<?php

namespace App\Http\Controllers;

use App\Models\BrandLearning;
use App\Services\BrandLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TRÍ NHỚ CỦA SHOP — màn hình đọc lại những gì agent đã học (Việc #7+ · 2026-09-26).
 *
 * Vì sao là đường API RIÊNG của người dùng (không thuộc dự án nào): ký ức gắn với TÀI KHOẢN, không gắn
 * với một bộ sưu tập — cùng một gu được dùng cho mọi bộ. Đặt dưới /projects/... là nói sai bản chất và
 * làm màn hình không mở được khi chưa chọn bộ nào.
 *
 * HAI QUYỀN, khác nhau có chủ ý:
 *   · ĐỌC  — mọi thao tác đều chỉ trên ký ức CỦA CHÍNH mình (lọc theo user_id, không nhận id người khác).
 *   · XOÁ  — cũng chỉ trên ký ức của mình; id của người khác trả 404 (không xác nhận là nó tồn tại).
 */
class BrandMemoryController extends Controller
{
    public function __construct(private readonly BrandLearningService $memory) {}

    /** GET /api/brand-memory */
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);

        return response()->json($this->memory->memory($request->user(), $limit));
    }

    /** DELETE /api/brand-memory/{learning} — quên MỘT ký ức (sửa bài học sai bằng cách xoá nó). */
    public function destroy(Request $request, BrandLearning $learning): JsonResponse
    {
        abort_unless((int) $learning->user_id === (int) $request->user()->id, 404, 'Không tìm thấy ký ức này.');

        $this->memory->forget($learning);

        return response()->json($this->memory->memory($request->user()));
    }
}
