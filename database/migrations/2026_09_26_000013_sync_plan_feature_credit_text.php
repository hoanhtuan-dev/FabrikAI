<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SỬA CHỮ TRÊN TRANG GIÁ CHO KHỚP SỐ THẬT (2026-09-26) — LỖI BẮT ĐƯỢC KHI NHÌN TRANG THẬT.
     *
     * VÌ SAO CẦN: migration 000011 đã NÂNG `plans.credits_per_month` (120→155, 350→405, 1.200→1.240),
     * nhưng `plans.features` là mảng CHUỖI TIẾP THỊ do chủ dự án viết — migration đó không đụng tới.
     * Hệ quả đo được trên https://fabrikai.shop/bang-gia: trang giá nói CẢ HAI con số —
     * thẻ gói ghi "155 credit" còn danh sách đặc quyền ghi "120 credit mỗi tháng".
     *
     * Đó là lỗi nặng hơn một con số sai: khách đọc thấy hai câu trả lời khác nhau cho cùng một câu hỏi,
     * và cả hai đều nằm trên trang NIÊM YẾT GIÁ.
     *
     * CÁCH SỬA — TÁCH TỪNG CHUỖI, KHÔNG GHI ĐÈ CẢ MẢNG:
     * `features` là dữ liệu chủ dự án sửa được trong Quản trị. Ghi đè cả mảng là xoá mọi câu họ đã viết.
     * Ở đây chỉ THAY ĐÚNG chuỗi cũ bằng chuỗi mới, câu nào không khớp thì giữ nguyên từng ký tự.
     *
     * Đây là lần thứ HAI trong cùng một đợt mà lỗi chỉ lộ ra khi ĐỌC TRANG THẬT — lần đầu là bảng giá
     * seed không khớp model đang chạy. Bài học: "migrate DONE" không phải là bằng chứng; phải mở trang ra xem.
     */
    public function up(): void
    {
        // [slug => [chuỗi CŨ, chuỗi MỚI]]
        $fixes = [
            // Gói Miễn phí: credit được cấp LẠI MỖI CHU KỲ (PlanService::syncCycleCredits cấp
            // credits_per_month cho mọi gói có credits > 0), KHÔNG phải "dùng thử khi đăng ký" một lần.
            // Câu cũ vừa sai về cơ chế, vừa làm gói free trông kém hơn thực tế.
            'free' => ['50 credit dùng thử khi đăng ký', '50 credit mỗi tháng — dùng thử không giới hạn thời gian'],
            'starter' => ['120 credit mỗi tháng (+30 tặng lần đầu)', '155 credit mỗi tháng (+30 tặng lần đầu)'],
            'pro' => ['350 credit mỗi tháng (+100 tặng lần đầu)', '405 credit mỗi tháng (+100 tặng lần đầu)'],
            'studio' => ['1.200 credit mỗi tháng (+400 tặng lần đầu)', '1.240 credit mỗi tháng (+400 tặng lần đầu)'],
        ];

        foreach ($fixes as $slug => [$old, $new]) {
            $plan = DB::table('plans')->where('slug', $slug)->first(['id', 'features']);
            if (! $plan) {
                continue;
            }

            $features = json_decode((string) $plan->features, true);
            if (! is_array($features)) {
                continue;
            }

            $changed = false;
            foreach ($features as $i => $line) {
                if (trim((string) $line) === $old) {
                    $features[$i] = $new;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('plans')->where('id', $plan->id)
                    ->update(['features' => json_encode(array_values($features), JSON_UNESCAPED_UNICODE)]);
            }
        }

        // ── NÓI THẬT VỀ TRẦN ẢNH/NGÀY ────────────────────────────────────────────────────────
        // Trần theo ngày là giới hạn THẬT, được THỰC THI (429) — khách phải được biết TRƯỚC khi mua,
        // không phải phát hiện lúc bị chặn. Thêm một dòng nếu gói chưa có.
        foreach (DB::table('plans')->get(['id', 'slug', 'daily_image_limit', 'features']) as $plan) {
            $limit = (int) $plan->daily_image_limit;
            if ($limit <= 0) {
                continue;
            }

            $features = json_decode((string) $plan->features, true);
            if (! is_array($features)) {
                continue;
            }

            $already = false;
            foreach ($features as $line) {
                if (str_contains((string) $line, 'ảnh/ngày') || str_contains((string) $line, 'ảnh mỗi ngày')) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }

            $features[] = 'Tối đa '.number_format($limit, 0, ',', '.').' ảnh mỗi ngày';

            DB::table('plans')->where('id', $plan->id)
                ->update(['features' => json_encode(array_values($features), JSON_UNESCAPED_UNICODE)]);
        }
    }

    /**
     * KHÔNG HOÀN TÁC: chuỗi cũ nói SAI số credit đang có hiệu lực, khôi phục lại là trả về câu sai.
     * Bảng giá đúng nằm ở `credits_per_month`; chữ chỉ đi theo nó.
     */
    public function down(): void
    {
        // Cố ý để trống.
    }
};
