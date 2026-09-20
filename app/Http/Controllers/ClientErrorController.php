<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * NHẬN BÁO LỖI TỪ TRÌNH DUYỆT — để mã tra cứu L-XXXX của lỗi phía client cũng tra được trong log.
 *
 * Vì sao cần endpoint này (docs/DESIGN_SYSTEM.md §6.5): mã tra cứu trước đây chỉ có ở lỗi do MÁY CHỦ sinh.
 * Nhưng lỗi người dùng thật hay gặp lại sinh ngay trong trình duyệt — mất mạng, ảnh không giải mã được,
 * canvas không xuất được blob, hết dung lượng localStorage, exception không ai bắt. Những lỗi đó không
 * đi qua bất kỳ controller nào nên KHÔNG có dấu vết nào trong storage/logs/laravel.log: khách đọc mã cho
 * tổng đài mà hỗ trợ cũng không tra được gì.
 *
 * Client sinh mã (cùng định dạng, cùng bảng chữ với studio_error_code) rồi gửi kèm chi tiết kỹ thuật về
 * đây; endpoint ghi log `client_error[L-XXXX]: <ngữ cảnh>` để hỗ trợ grep y như lỗi máy chủ.
 *
 * Ba ràng buộc an toàn:
 *   1. KHÔNG tin nội dung client gửi — mã phải đúng định dạng, các trường đều bị cắt độ dài;
 *   2. throttle riêng theo IP (xem AppServiceProvider) + gộp trùng theo mã trong 10 phút ⇒ không thể
 *      dùng endpoint này để bơm log;
 *   3. luôn trả 200 {ok:true} (trừ khi dữ liệu sai định dạng) — báo lỗi là việc phụ, không được phép
 *      làm hỏng thêm trải nghiệm của người đang gặp sự cố.
 */
class ClientErrorController extends Controller
{
    /**
     * Định dạng mã tra cứu dùng CHUNG với studio_error_code() (PHP) và newClientCode() (JS):
     * `L-` + 4 ký tự thuộc bảng chữ ĐÃ LOẠI 0 O 1 I (khách đọc qua điện thoại). Regex chặt đúng bằng
     * bảng chữ đó — nếu chỉ dùng [A-Z2-9] thì mã chứa O/I vẫn lọt, tức là nhận cả mã mà hệ thống
     * không bao giờ sinh ra.
     */
    public const CODE_PATTERN = '/^L-[A-HJ-NP-Z2-9]{4}$/';

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:'.self::CODE_PATTERN],
            'message' => ['nullable', 'string', 'max:500'],
            'context' => ['nullable', 'string', 'max:120'],
            'userMessage' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'string', 'max:300'],
            'at' => ['nullable', 'string', 'max:40'],
        ]);

        $code = $data['code'];
        $context = $data['context'] ?? '';

        // Gộp trùng: cùng một mã gửi lại (mất mạng rồi gửi bù, hoặc người dùng tải lại trang) chỉ ghi
        // MỘT dòng log. Cache::add là phép thử-và-đặt nguyên tử nên hai request song song không cùng ghi.
        $fresh = Cache::add('client_error:'.$code, 1, now()->addMinutes(10));
        if ($fresh) {
            Log::warning('client_error['.$code.']: '.($context !== '' ? $context : 'trình duyệt'), [
                'message' => $data['message'] ?? '',
                'user_message' => $data['userMessage'] ?? '',
                'page' => $data['page'] ?? '',
                'client_time' => $data['at'] ?? '',
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'agent' => substr((string) $request->userAgent(), 0, 200),
            ]);
        }

        // Không trả về chi tiết kỹ thuật (chính endpoint này cũng phải theo §6).
        return response()->json(['ok' => true, 'code' => $code, 'logged' => (bool) $fresh]);
    }
}
