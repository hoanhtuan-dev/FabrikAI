<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seed 4 gói cước VNĐ (chiến lược giá cho thị trường Việt Nam — xem PRICING.md).
 *
 * Đây là dữ liệu CẤU HÌNH (không phải demo): chạy ở mọi môi trường, idempotent theo slug.
 * Giá/credit được tính để biên gộp ≥ 60% ở mô hình Qwen base (~500 ₫/ảnh 1K); Qwen Max
 * (~1.800 ₫/ảnh) chỉ dành cho gói Pro/Studio với mức tiêu hao credit cao hơn (xem PRICING.md).
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
                'resolution_cap' => '1K',
                'is_default' => true,
                'sort' => 0,
                'features' => [
                    '100 credit dùng thử khi đăng ký',
                    'Tạo ảnh 1:1 · độ phân giải 1K',
                    'Thư viện ảnh cá nhân',
                    'Có nhãn DEMO khi chưa cấu hình AI',
                    'Hỗ trợ qua cộng đồng',
                ],
            ],
            [
                'name' => 'Khởi nghiệp',
                'slug' => 'starter',
                'tagline' => 'Cho chủ shop bắt đầu tự làm ảnh',
                'price_vnd' => 199000,
                'credits_per_month' => 120,
                'bonus_credits' => 30,
                'image_credit_cost' => 1,
                'video_credit_cost' => 10,
                'resolution_cap' => '2K',
                'is_default' => false,
                'sort' => 1,
                'features' => [
                    '120 credit mỗi tháng (+30 tặng lần đầu)',
                    'Ảnh 2K · mọi tỉ lệ sàn (1:1, 3:4, 4:5, 9:16)',
                    'Sửa ảnh · thử đồ · xoá nền',
                    'Video catwalk ngắn',
                    'Xuất ảnh (sắp ra mắt)',
                ],
            ],
            [
                'name' => 'Chuyên nghiệp',
                'slug' => 'pro',
                'tagline' => 'Cho nhà bán hàng ra ảnh đều đặn mỗi ngày',
                'price_vnd' => 499000,
                'credits_per_month' => 350,
                'bonus_credits' => 100,
                'image_credit_cost' => 1,
                'video_credit_cost' => 10,
                'resolution_cap' => '2K',
                'is_default' => false,
                'sort' => 2,
                'features' => [
                    '350 credit mỗi tháng (+100 tặng lần đầu)',
                    'Ảnh 2K chất lượng cao (Qwen Max)',
                    'Video catwalk dài hơn',
                    'Ưu tiên xử lý khi cao điểm',
                    'Xuất gói theo sàn (sắp ra mắt)',
                ],
            ],
            [
                'name' => 'Studio',
                'slug' => 'studio',
                'tagline' => 'Cho studio / đội nhóm nhiều SKU',
                'price_vnd' => 1490000,
                'credits_per_month' => 1200,
                'bonus_credits' => 400,
                'image_credit_cost' => 1,
                'video_credit_cost' => 10,
                'resolution_cap' => '2K',
                'is_default' => false,
                'sort' => 3,
                'features' => [
                    '1.200 credit mỗi tháng (+400 tặng lần đầu)',
                    'Tất cả model chất lượng cao nhất',
                    'Nhiều chỗ ngồi / đội nhóm (sắp ra mắt)',
                    'API + xuất hàng loạt (sắp ra mắt)',
                    'Hỗ trợ ưu tiên 1:1',
                ],
            ],
        ];

        foreach ($plans as $i => $data) {
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
