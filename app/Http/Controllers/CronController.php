<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CRON NGOÀI — một URL chạy được `schedule:run` mà KHÔNG cần cron của hPanel.
 *
 * VÌ SAO CẦN (đo trên production 2026-09-26): lệnh `crontab` KHÔNG tồn tại trên host; cron của hPanel
 * CÓ chạy `schedule:run` mỗi 30 phút nhưng ĐANG LỖI (65 lần) vì một tác vụ dùng Symfony Process trên
 * host chặn `proc_open`. Hậu quả đo được: `studio:grant-plan-credits` (cấp credit hằng tháng cho
 * khách trả tiền) không bao giờ chạy đúng hạn.
 *
 * Đường này để cron-job.org / GitHub Actions gọi mỗi 1–5 phút, THAY cho việc tin vào cron của host.
 *
 * VÌ SAO KHÔNG đặt dưới nhóm auth: cron-job.org không có phiên đăng nhập. Xác thực bằng TOKEN khó
 * đoán (hash_equals), và CSRF đã được loại trừ riêng ở bootstrap/app.php.
 *
 * BẢO MẬT — ba lớp, theo đúng chuẩn của repo:
 *   1. TOKEN bắt buộc (so bằng hash_equals, KHÔNG so bằng ==).
 *   2. Khoá chồng (Cache::lock 55 giây) — hai lần gọi đè nhau không chạy đúp tác vụ.
 *   3. Kết quả trả về là SỐ ĐO THẬT (chạy những gì, mất bao lâu) để cron-job.org hiện được
 *      "thành công" hay "thất bại", và để ta phát hiện ngay khi nó ngừng chạy.
 */
class CronController extends Controller
{
    /** Khoá cache đánh dấu "lần tick cuối" — màn Quản trị đọc để nói THẬT thay vì khẳng định suông. */
    public const HEARTBEAT_KEY = 'studio:cron:tick';

    public function tick(Request $request): JsonResponse
    {
        $expected = $this->token();
        if ($expected === '' || $expected === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Cron ngoài CHƯA được bật: chưa đặt STUDIO_CRON_TOKEN (hoặc setting studio_cron_token).',
            ], 403);
        }

        $provided = (string) ($request->query('token', '') ?: $request->bearerToken() ?: '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning('cron/tick: token sai hoặc thiếu', ['ip' => $request->ip()]);

            return response()->json(['ok' => false, 'message' => 'Sai token.'], 401);
        }

        // Khoá chồng: lần gọi trước chưa xong thì lần này bỏ, trả "vẫn đang chạy" để cron-job.org
        // không báo thất bại giả.
        $lock = Cache::lock('studio:cron:tick:lock', 55);
        if (! $lock->get()) {
            return response()->json([
                'ok' => true,
                'skipped' => true,
                'message' => 'Lượt chạy trước chưa xong — bỏ qua lượt này.',
            ]);
        }

        $t0 = microtime(true);
        $ran = [];

        try {
            // 1) Bộ lịch Laravel — đúng thứ cron hPanel lẽ ra chạy. Một tác vụ lỗi không giết các tác
            //    vụ khác (Laravel bắt lỗi từng event), nên proc_open của market-signals không phá grant-plan-credits.
            $exit = Artisan::call('schedule:run');
            $ran[] = ['task' => 'schedule:run', 'exit' => $exit];

            // 2) Nhặt generation đang chờ (fallback render) + heal generation kẹt. Giới hạn nhỏ để
            //    một tick không biến thành một worker dài vô hạn.
            $exit2 = Artisan::call('studio:process', ['--limit' => 5]);
            $ran[] = ['task' => 'studio:process', 'exit' => $exit2];
        } catch (\Throwable $e) {
            Log::error('cron/tick: tác vụ ném lỗi', ['error' => $e->getMessage()]);
        } finally {
            $lock->release();
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);

        // Ghi nhịp tim — đây là thứ màn Quản trị + /up có thể đọc để nói "lần chạy cuối 4 phút trước".
        Cache::put(self::HEARTBEAT_KEY, [
            'at' => now()->toISOString(),
            'ms' => $ms,
            'ran' => count($ran),
        ], now()->addMinutes(10));

        return response()->json([
            'ok' => true,
            'ran' => $ran,
            'ms' => $ms,
            'at' => now()->toISOString(),
        ]);
    }

    /**
     * Token của cron ngoài — ưu tiên DB setting (chủ dự án đổi được trong Quản trị), rồi env.
     * Trả về chuỗi rỗng khi chưa cấu hình ⇒ endpoint TỰ KHOÁ (403) thay vì chạy hớ.
     */
    protected function token(): string
    {
        $fromSetting = (string) studio_config('cron_token', '');

        if ($fromSetting !== '') {
            return $fromSetting;
        }

        return (string) env('STUDIO_CRON_TOKEN', '');
    }
}
