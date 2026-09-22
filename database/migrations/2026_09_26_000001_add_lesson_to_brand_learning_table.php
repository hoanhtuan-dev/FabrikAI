<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRÍ NHỚ DÀI HẠN (GĐ2 — 2026-09-26): tách "bài học" khỏi "prompt thô".
 *
 * GĐ1 ghi prompt THÔ của ảnh đã duyệt/loại ("đầm linen trắng ngà"). Đó là SỰ KIỆN, chưa phải BÀI HỌC.
 * Cột `lesson` chứa bài học đã KHÁI QUÁT do vai agent_reflect rút ra ("shop chuộng linen trắng ngà,
 * dáng suông; tránh bóng hoạ tiết to") — tín hiệu cao hơn prompt thô khi nhét vào brief sau.
 * `context` ghi nguồn gốc lượt rút (provider · model · thời điểm) để giao diện/kiểm chứng nói đúng sự thật.
 *
 * Cả hai cột NULLABLE: nhánh chưa có model rút kinh nghiệm (hoặc job chưa chạy) thì prompt thô vẫn dùng được —
 * brief rơi về hành vi cũ, không bao giờ vỡ vì thiếu "bài học".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_learning', function (Blueprint $table) {
            $table->text('lesson')->nullable()->after('prompt');
            $table->json('context')->nullable()->after('lesson');
        });
    }

    public function down(): void
    {
        Schema::table('brand_learning', function (Blueprint $table) {
            $table->dropColumn(['lesson', 'context']);
        });
    }
};
