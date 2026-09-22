<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CỦNG CỐ TRÍ NHỚ — CONSOLIDATE (GĐ3 · 2026-09-26).
 *
 * VÌ SAO: hai giai đoạn trước đã cho trí nhớ biết GHI (GĐ1: prompt đã duyệt/loại) và biết RÚT BÀI HỌC
 * (GĐ2: lesson khái quát). Nhưng cả hai đều ghi rồi để đó: mọi ký ức nặng như nhau, và cảnh báo luôn lấy
 * N ký ức MỚI NHẤT. Hệ quả thật: shop duyệt 40 ảnh trong một buổi thì "gu" của buổi đó **đẩy hết** những
 * phong cách đã đúng suốt nhiều tháng ra khỏi cửa sổ prompt — trí nhớ thành NHẬT KÝ, không thành TRÍ NHỚ.
 *
 * Ba cột này làm đúng việc mà mọi cơ chế ghi nhớ sinh học làm:
 *   · @@weight@@       — độ mạnh 1..10. Ký ức được nhắc lại thì mạnh lên, lâu không dùng thì yếu đi.
 *   · @@hits@@         — đã được củng cố bao nhiêu lần (đo được, thay vì chỉ tin vào weight).
 *   · @@refreshed_at@@ — lần củng cố gần nhất; null = chưa từng, lấy @@created_at@@ làm mốc.
 *
 * Index (user_id, decision, weight) đúng cho hai đường đọc nóng: "lấy ký ức MẠNH NHẤT của tôi" (prompt) và
 * "tìm ký ức cũ đã yếu để suy giảm" (lệnh dọn hằng ngày).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_learning', function (Blueprint $table) {
            $table->unsignedTinyInteger('weight')->default(5)->after('context');
            $table->unsignedInteger('hits')->default(1)->after('weight');
            $table->timestamp('refreshed_at')->nullable()->after('hits');
            $table->index(['user_id', 'decision', 'weight'], 'brand_learning_user_decision_weight_index');
        });
    }

    public function down(): void
    {
        Schema::table('brand_learning', function (Blueprint $table) {
            $table->dropIndex('brand_learning_user_decision_weight_index');
            $table->dropColumn(['weight', 'hits', 'refreshed_at']);
        });
    }
};
