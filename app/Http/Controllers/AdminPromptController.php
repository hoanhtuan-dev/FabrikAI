<?php

namespace App\Http\Controllers;

use App\Ai\PromptCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QUẢN LÝ CHỈ DẪN (PROMPT) QUA GIAO DIỆN — thay cho việc phải SSH (2026-09-22).
 *
 * Trước đây muốn đổi chỉ dẫn phải: ssh → tạo tệp /tmp/p.txt → php artisan studio:prompt --set-file.
 * Chủ dự án không phải lúc nào cũng có SSH trong tay, mà chỉ dẫn thì cần sửa LUÔN. Lớp này mở đúng
 * bốn thao tác của lệnh đó ra HTTP: xem · đặt · tắt · khôi phục phiên bản cũ.
 *
 * Bất biến:
 *   - Chỉ khoá CÓ TRONG PromptCatalog mới sửa được (khoá lạ ⇒ 422, không tạo rác trong bảng).
 *   - Nội dung RỖNG bị từ chối: chỉ dẫn rỗng làm lượt chạy mất hết chỉ dẫn mà vẫn báo "thành công".
 *   - Mọi thao tác ghi đều TẠO PHIÊN BẢN MỚI, không sửa đè — bản cũ còn để khôi phục.
 *   - Toàn cục: chỉ dẫn dùng chung cho mọi tài khoản, nên nhóm route là [auth, admin, nostore].
 */
class AdminPromptController extends Controller
{
    /** Danh mục + trạng thái thật + bản mặc định đã ghi nhận + lịch sử phiên bản. */
    public function index(): JsonResponse
    {
        return response()->json([
            'prompts' => PromptCatalog::all(),
        ]);
    }

    /** Đặt chỉ dẫn mới (tạo phiên bản mới và bật lên ngay). */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:20000'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        if (! PromptCatalog::has($data['key'])) {
            return response()->json([
                'message' => 'Khoá chỉ dẫn không có trong danh mục: '.$data['key'],
            ], 422);
        }

        if (trim($data['body']) === '') {
            return response()->json([
                'message' => 'Chỉ dẫn rỗng — từ chối. Nếu muốn quay về mặc định trong mã, dùng nút "Quay về mặc định".',
            ], 422);
        }

        $row = PromptCatalog::put($data['key'], $data['body'], $data['note'] ?? null);

        return response()->json([
            'ok' => true,
            'message' => 'Đã lưu phiên bản v'.$row->version.' — lượt chạy TIẾP THEO dùng bản này.',
            'prompt' => PromptCatalog::entry($data['key']) + PromptCatalog::state($data['key']),
        ]);
    }

    /** Quay về mặc định trong mã: tắt mọi phiên bản đang bật của khoá. */
    public function off(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:80']]);

        if (! PromptCatalog::has($data['key'])) {
            return response()->json(['message' => 'Khoá chỉ dẫn không có trong danh mục: '.$data['key']], 422);
        }

        $n = PromptCatalog::turnOff($data['key']);

        return response()->json([
            'ok' => true,
            'message' => $n > 0
                ? 'Đã tắt '.$n.' bản cấu hình — quay về chuỗi mặc định trong mã.'
                : 'Khoá này vốn đang dùng mặc định trong mã.',
            'prompt' => PromptCatalog::entry($data['key']) + PromptCatalog::state($data['key']),
        ]);
    }

    /** Khôi phục MỘT phiên bản cũ (tắt các bản khác của cùng khoá). */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        if (! PromptCatalog::has($data['key'])) {
            return response()->json(['message' => 'Khoá chỉ dẫn không có trong danh mục: '.$data['key']], 422);
        }

        if (! PromptCatalog::activate($data['key'], (int) $data['version'])) {
            return response()->json(['message' => 'Không thấy phiên bản v'.$data['version'].' của khoá này.'], 404);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Đã khôi phục phiên bản v'.$data['version'].'.',
            'prompt' => PromptCatalog::entry($data['key']) + PromptCatalog::state($data['key']),
        ]);
    }
}
