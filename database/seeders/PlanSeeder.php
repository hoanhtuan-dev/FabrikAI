<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seed 4 gói cước VNĐ (chiến lược giá cho thị trường Việt Nam — xem PRICING.md).
 *
 * Đây là dữ liệu CẤU HÌNH (không phải demo): chạy ở mọi môi trường, idempotent theo slug.
 *
 * [Q4 — 2026-09-19] Số ghế (`seats`) phải khai Ở ĐÂY cho cài đặt MỚI, và migration
 * 2026_09_19_000006 lo phần cài đặt ĐANG CHẠY (nơi bảng plans đã có dữ liệu trước khi thêm cột).
 * Bài học từ chính lần làm này: migration UPDATE ... WHERE seats = 1 chạy trên bảng RỖNG khi migrate
 * fresh ⇒ test đỏ vì gói vẫn 1 ghế; phải khai ở cả hai chỗ.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════════════
 * [2026-09-26] TÍNH LẠI THEO BIÊN 40 % — docs/CREDIT_GOI_VA_LOI_NHUAN.md §4.7
 * ════════════════════════════════════════════════════════════════════════════════════════════════
 * Bản cũ giả định giá vốn Qwen là $0,02/MP (ảnh) và $0,03/MP (edit). Hai số đó KHỚP GIÁ fal.ai
 * nhưng KHÔNG khớp DashScope đang gọi — giá thật là **$0,035/ảnh** và **$0,045/ảnh** (DashScope
 * tính theo ẢNH, không theo megapixel). Hệ quả đo được: biên gộp rơi từ ~70 % xuống **49 / 41 / 32 %**.
 *
 * Bảng dưới đây giữ NGUYÊN GIÁ GÓI (không phá lời hứa với khách đang trả) và **NÂNG số credit** để
 * mọi gói nằm trên ngưỡng đơn giá tối thiểu:
 *
 *     BẤT BIẾN (khoá bằng test):  ₫/credit ≥ 1.200
 *
 * Ngưỡng này đến từ dòng ĐẮT NHẤT trên mỗi credit — video kling (9.100 ₫ ÷ 13 credit = 700 ₫/credit):
 *     700 ÷ (1 − 0,40) = 1.166,67 ₫  ⇒  làm tròn lên 1.200 ₫ (đệm cho thử lại và lỗi 422 fal CÓ THỂ tính tiền).
 *
 * Nhờ vậy biên **≥ 40 % ở MỌI dòng**, kể cả khi một khách dùng toàn model đắt nhất.
 *
 * `daily_image_limit`: 0 = không giới hạn. Đây là GIỚI HẠN TẠO ẢNH theo ngày — credit chặn TỔNG
 * chi tiêu nhưng không chặn một tài khoản đốt sạch credit trong 10 phút rồi bỏ.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Miễn phí',
                'slug' => 'free',
                'tagline' => 'Dùng thử studio AI thời trang',
                'price_vnd' => 0,
                'credits_per_month' => 0,
                'bonus_credits' => 0,
                'image_credit_cost' => 1,
                'video_credit_cost' => 10,
                // Trần tạo ảnh/ngày của gói free: chặn một tài khoản mới đốt sạch 100 credit tặng
                // trong một phiên. Trần chi phí một tài khoản free (khoá 1K) là 65.000 ₫ — xem §4.8.
                'daily_image_limit' => 20,
                // KHOÁ 1K LÀ BẮT BUỘC về mặt kinh tế: mở 2K cho gói free thì trần chi phí một tài
                // khoản vọt lên 10 credit/lượt × 6.500 ₫ — gấp 2,5 lần (§4.8).
                'resolution_cap' => '1K',
                // [Q4] Số ghế: gói miễn phí 1 người.
                'seats' => 1,
                'is_default' => true,
                'sort' => 0,
                'features' => [
                    '100 credit dùng thử khi đăng ký',
                    'MỞ ĐẦY ĐỦ tính năng — không khoá tính năng nào',
                    'Ảnh 1K · mọi tỉ lệ · tối đa 20 ảnh/ngày',
                    'Thư viện ảnh cá nhân',
                    'Hỗ trợ qua cộng đồng',
                ],
            ],
            [
                'name' => 'Khởi nghiệp',
                'slug' => 'starter',
                'tagline' => 'Cho chủ shop bắt đầu tự làm ảnh',
                'price_vnd' => 199000,
                // 155 credit ⇒ 199.000 ÷ 155 = 1.284 ₫/credit ⇒ biên xấu nhất 45,5 %.
                'credits_per_month' => 155,
                'bonus_credits' => 30,
                'image_credit_cost' => 1,
                'video_credit_cost' => 10,
                'daily_image_limit' => 60,
                'resolution_cap' => '2K',
                // [Q4] Khởi nghiệp: 1 người (chủ shop tự làm).
                'seats' => 1,
                'is_default' => false,
                'sort' => 1,
                'features' => [
                    '155 credit mỗi tháng (+30 tặng lần đầu)',
                    'Ảnh 2K · mọi tỉ lệ sàn (1:1, 3:4, 4:5, 9:16)',
                    'MỞ ĐẦY ĐỦ tính năng: sửa ảnh · thử đồ · ghép trang phục · video',
                    'Tối đa 60 ảnh/ngày',
                    'Xuất ảnh và gói cho xưởng',
                ],
            ],
            [
                'name' => 'Chuyên nghiệp',
                'slug' => 'pro',
                'tagline' => 'Cho nhà bán hàng ra ảnh đều đặn mỗi ngày',
                'price_vnd' => 499000,
                // 405 credit ⇒ 499.000 ÷ 405 = 1.232 ₫/credit ⇒ biên xấu nhất 43,2 %.
                'credits_per_month' => 405,
                'bonus_credits' => 100,
                'image_credit_cost' => 1,
                'video_credit_cost' => 10,
                'daily_image_limit' => 150,
                'resolution_cap' => '2K',
                // [Q4] Chuyên nghiệp: 3 ghế (chủ shop + 2 nhân viên).
                'seats' => 3,
                'is_default' => false,
                'sort' => 2,
                'features' => [
                    '405 credit mỗi tháng (+100 tặng lần đầu)',
                    'Ảnh 2K chất lượng cao',
                    'MỞ ĐẦY ĐỦ tính năng cho cả 3 ghế',
                    'Tối đa 150 ảnh/ngày',
                    'Ưu tiên xử lý khi cao điểm',
                ],
            ],
            [
                'name' => 'Studio',
                'slug' => 'studio',
                'tagline' => 'Cho studio / đội nhóm nhiều SKU',
                'price_vnd' => 1490000,
                // 1.240 credit ⇒ 1.490.000 ÷ 1.240 = 1.202 ₫/credit ⇒ biên xấu nhất 41,8 %.
                'credits_per_month' => 1240,
                'bonus_credits' => 400,
                'image_credit_cost' => 1,
                'video_credit_cost' => 10,
                'daily_image_limit' => 400,
                'resolution_cap' => '2K',
                // [Q4] Studio: 10 ghế (đội nhóm).
                'seats' => 10,
                'is_default' => false,
                'sort' => 3,
                'features' => [
                    '1.240 credit mỗi tháng (+400 tặng lần đầu)',
                    'Tất cả model chất lượng cao nhất',
                    'MỞ ĐẦY ĐỦ tính năng cho cả 10 ghế',
                    'Tối đa 400 ảnh/ngày',
                    'Hỗ trợ ưu tiên 1:1',
                ],
            ],
        ];

        foreach ($plans as $i => $data) {
            // Gói theo MÙA VỤ (factory_season) do migration 2026_09_19_000005 tạo — seeder này
            // không khai lại, nhưng vẫn phải nhận trần ngày (xem migration 000011).
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }

        // Bất biến: có ĐÚNG MỘT gói mặc định cho người tự đăng ký.
        $defaults = Plan::where('is_default', true)->count();
        if ($defaults !== 1) {
            Plan::query()->update(['is_default' => false]);
            Plan::where('slug', 'free')->update(['is_default' => true]);
        }
    }
}
