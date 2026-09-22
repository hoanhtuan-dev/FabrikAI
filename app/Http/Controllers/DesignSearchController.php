<?php

namespace App\Http\Controllers;

use App\Services\DesignSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TÌM THIẾT KẾ CŨ — FileSearch (Việc #9 · 2026-09-26).
 *
 * Vì sao là đường API của TÀI KHOẢN (không thuộc dự án nào): kho tài liệu là của chính người dùng (ảnh đã
 * tạo · brief · bài học · phiếu kỹ thuật của mọi bộ), và câu hỏi hay gặp là "mùa trước mình làm gì rồi" —
 * câu hỏi đó không thuộc về một bộ sưu tập nào.
 *
 * BA ĐƯỜNG: tìm · xem tình trạng chỉ mục · lập chỉ mục ngay (có trần, để một cú bấm không thành 300 lời gọi
 * ra ngoài bất ngờ).
 */
class DesignSearchController extends Controller
{
    public function __construct(private readonly DesignSearchService $search) {}

    /** GET /api/design-search?q=…&limit=… */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:'.DesignSearchService::QUERY_MAX],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.DesignSearchService::QUERY_LIMIT],
        ]);

        return response()->json($this->search->search(
            $request->user(),
            (string) ($data['q'] ?? ''),
            (int) ($data['limit'] ?? DesignSearchService::QUERY_LIMIT),
        ));
    }

    /** GET /api/design-search/status */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'stats' => $this->search->stats($request->user()),
            'shape' => $this->search->shape(),
        ]);
    }

    /** POST /api/design-search/index — lập chỉ mục ngay, có trần. */
    public function indexNow(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.DesignSearchService::MAX_INDEX],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $result = $this->search->index(
            $request->user(),
            (int) ($data['limit'] ?? 50),
            (bool) ($data['dry_run'] ?? false),
        );

        return response()->json([
            'result' => $result,
            'stats' => $this->search->stats($request->user()),
            'shape' => $this->search->shape(),
        ]);
    }
}
