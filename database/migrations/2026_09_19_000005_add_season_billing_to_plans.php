<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GÓI THEO MÙA VỤ / XƯỞNG (Q3 — 2026-09-19): bán theo VỤ thay vì chỉ theo THÁNG.
 *
 * Vì sao: chủ xưởng may và chủ doanh nghiệp thời trang mua theo VỤ (một bộ sưu tập / một đơn hàng lớn),
 * không mua theo tháng. Bán theo tháng bắt họ tự quy đổi "3 tháng cho một vụ" và tự đoán có đủ credit
 * cho cả vụ hay không — đúng chỗ làm khách do dự.
 *
 * Cách làm (dùng lại toàn bộ máy cấp credit/hoá đơn đang có, không đẻ hệ thống thứ hai):
 *   · `plans.unit_months`  — một ĐƠN VỊ MUA bằng mấy tháng (1 = gói tháng, 3 = gói theo vụ).
 *   · `plans.cycle_months` — bao lâu CẤP CREDIT một lần (gói vụ cấp MỘT LẦN cho cả vụ).
 *   · `plans.units`        — các mốc mua được (JSON): [1,3,6,12] tháng hoặc [1,2,4] vụ.
 *   · `upgrade_requests.units` — khách mua mấy đơn vị (số vụ), `months` vẫn là tổng số tháng để
 *     hoá đơn/kích hoạt không phải suy diễn lại.
 *
 * Gói "Xưởng theo vụ" được TẠO Ở ĐÂY (chỉ khi chưa có, KHÔNG bao giờ ghi đè) — không chạy lại
 * PlanSeeder trên production vì seeder dùng updateOrCreate và sẽ ghi đè giá/chi phí chủ dự án đã chỉnh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('unit_months')->default(1)->after('price_vnd');
            $table->unsignedTinyInteger('cycle_months')->default(1)->after('unit_months');
            $table->json('units')->nullable()->after('cycle_months');
        });

        Schema::table('upgrade_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('units')->default(1)->after('months');
        });

        // Gói theo VỤ cho xưởng may / doanh nghiệp: trả một lần cho cả vụ, credit là MỘT bể dùng cho cả vụ.
        if (! DB::table('plans')->where('slug', 'factory_season')->exists()) {
            $now = now();
            DB::table('plans')->insert([
                'name' => 'Xưởng theo vụ',
                'slug' => 'factory_season',
                'tagline' => 'Một vụ may (3 tháng) — credit dùng cho cả vụ, không chia nhỏ theo tháng',
                'price_vnd' => 3290000,
                'unit_months' => 3,
                'cycle_months' => 3,
                'units' => json_encode([1, 2, 4], JSON_UNESCAPED_UNICODE),
                'credits_per_month' => 3000,
                'bonus_credits' => 300,
                'image_credit_cost' => 1,
                'video_credit_cost' => 10,
                'resolution_cap' => '2K',
                'features' => json_encode([
                    '3.000 credit cho CẢ VỤ (3 tháng) — cấp một lần, không chia nhỏ',
                    'Ảnh 2K cho mẫu kỹ thuật + lookbook cả bộ',
                    'Xuất gói cho xưởng (ZIP: ảnh + phiếu kỹ thuật + bảng size)',
                    'Mẫu việc theo ngành: mẫu kỹ thuật gửi xưởng · lookbook bộ sưu tập',
                    'Hoá đơn theo vụ, dễ đối chiếu với đơn hàng',
                ], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'is_default' => false,
                'sort' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plans')->where('slug', 'factory_season')->delete();

        Schema::table('upgrade_requests', function (Blueprint $table) {
            $table->dropColumn('units');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['unit_months', 'cycle_months', 'units']);
        });
    }
};
