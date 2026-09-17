<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [2026-09-17 · kế hoạch Đợt 0.3] Đánh dấu kết quả DEMO / fallback.
 *
 * Đề bài: khi CHƯA cấu hình API key, các pipeline trả về ảnh MẪU hoặc tái dùng ẢNH GỐC
 * (copySample) mà BÁO 'completed' — người dùng "bấm Sửa ảnh, nhận lại ảnh cũ, tưởng thành công".
 * Đây là lỗi NIỀM TIN, không phải lỗi logic (xem STUDIO_REVIEW_DEEPDIVE §3.2 S3).
 *
 * Giải pháp: ghi cờ is_demo + demo_reason ở tầng service (nơi biết có dùng fallback hay không)
 * rồi trả ra API để UI nói rõ "Đây là ảnh mẫu — chưa cấu hình API key cho model này".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('status');
            $table->string('demo_reason', 255)->nullable()->after('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['is_demo', 'demo_reason']);
        });
    }
};
