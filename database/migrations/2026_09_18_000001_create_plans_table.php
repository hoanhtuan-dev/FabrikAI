<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gói cước (subscription plans) — FabrikAI Studio.
     *
     * Mỗi gói định nghĩa: giá VNĐ/tháng, hạn mức credit hàng tháng, credit thưởng khi đăng ký,
     * và chi phí credit riêng cho từng loại thao tác (ảnh / video). Đây là lớp dữ liệu phục vụ
     * trang Quản trị của Owner — không thay đổi cách pipeline tính credit (config/studio.php).
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();
            // Giá niêm yết VNĐ / tháng. 0 = gói miễn phí.
            $table->unsignedBigInteger('price_vnd')->default(0);
            // Hạn mức credit cấp mỗi chu kỳ (tháng).
            $table->unsignedInteger('credits_per_month')->default(0);
            // Credit tặng MỘT LẦN khi lần đầu chuyển sang gói này (0 = không tặng).
            $table->unsignedInteger('bonus_credits')->default(0);
            // Chi phí credit cho từng thao tác trong gói (mặc định = config toàn cục).
            $table->unsignedInteger('image_credit_cost')->default(1);
            $table->unsignedInteger('video_credit_cost')->default(10);
            // Trần độ phân giải gói được phép dùng (1K | 2K).
            $table->string('resolution_cap')->default('2K');
            // Danh sách đặc quyền hiển thị cho người mua (JSON mảng chuỗi).
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            // Gói gán mặc định cho tài khoản tự đăng ký.
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
