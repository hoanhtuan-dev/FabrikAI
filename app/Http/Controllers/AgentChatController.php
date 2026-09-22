<?php

namespace App\Http\Controllers;

use App\Services\AgentChatService;
use Illuminate\Http\Request;

/**
 * CHAT CỦA AGENT STUDIO THEO LUỒNG NDJSON (2026-09-26).
 *
 * Hợp đồng sự kiện (mỗi dòng là MỘT object JSON độc lập, kết thúc bằng "\\n" — KHÔNG phải SSE):
 *   {"type":"phase","key":"prepare|context|thinking|searching|reading|done","label":"…"}
 *   {"type":"tool","name":"…","query":"…","url":"…"}
 *   {"type":"tool_result","name":"…","found":N,"reused":N,"ok":true|false,"chars":N}
 *   {"type":"token","text":"…"}
 *   {"type":"citation","ref":"src_1","title":"…","url":"…","source_name":"…","published_at":"…"}
 *   {"type":"provider","provider":"…","model":"…"}
 *   {"type":"result","data":{…}}
 *   {"type":"error","message":"…"}
 *
 * BA luật giữ nguyên từ đường stream đã chạy production (StudioController::suggestStream):
 *   1. Lỗi TRƯỚC khi mở stream vẫn trả JSON 422 bình thường (client đọc res.ok) — không nhét lỗi vào NDJSON.
 *   2. Trong test, Laravel tự bọc output buffer: đụng vào buffer là test đọc ra chuỗi rỗng, nên mọi thao tác
 *      buffer nằm sau cờ \`$live = ! app()->runningUnitTests()\`.
 *   3. Nhãn \`phase\` là câu NÓI VỚI NGƯỜI DÙNG: không tên nhà cung cấp, không tên model, không mã HTTP.
 *      Chi tiết kỹ thuật chỉ có ở sự kiện \`provider\` và trong log.
 */
class AgentChatController extends Controller
{
    public function stream(Request $request, AgentChatService $chat)
    {
        $data = $request->validate([
            // Trần hội thoại và trần TỪNG lượt: hội thoại dài / một lượt dán cả tài liệu là cách nhanh nhất
            // để đốt ngân sách token và làm model lạc câu hỏi hiện tại.
            'messages' => ['required', 'array', 'min:1', 'max:'.AgentChatService::MAX_TURNS],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:'.AgentChatService::MAX_TURN_CHARS],
            'region' => ['nullable', 'string', 'in:all,hcm,hanoi,danang'],
        ]);

        $messages = array_values((array) $data['messages']);
        $region = (string) ($data['region'] ?? 'all');

        $live = ! app()->runningUnitTests();

        return response()->stream(function () use ($chat, $messages, $region, $request, $live) {
            // Câu trả lời có thể dài và phải đi qua công cụ — đừng để PHP cắt giữa chừng.
            @set_time_limit(180);
            if ($live) {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
            }

            $write = function (array $event) use ($live): void {
                echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
                if ($live) {
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    @flush();
                }
            };

            try {
                $chat->chat(['messages' => $messages], $request->user(), $write, $region);
            } catch (\Throwable $e) {
                // Chi tiết kỹ thuật (chuỗi lỗi của nhà cung cấp AI) chỉ vào log — gửi thẳng ra trình duyệt là
                // để lộ provider/model/quota lên giao diện.
                logger()->error('Agent chat stream failed', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'at' => $e->getFile().':'.$e->getLine(),
                ]);
                $write(['type' => 'error', 'message' => 'Trợ lý chưa trả lời được lúc này. Bạn thử lại sau ít phút.']);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            // Không có dòng này thì proxy đệm cả câu trả lời rồi trào ra một cục — mất hết ý nghĩa "chảy chữ".
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
