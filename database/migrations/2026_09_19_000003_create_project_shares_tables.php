<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHIA SẺ BỘ SƯU TẬP CHO KHÁCH DUYỆT (Đợt 4 — 2026-09-19).
 *
 * Chủ doanh nghiệp/thương hiệu cần gửi bộ sưu tập cho KHÁCH hoặc cho người duyệt nội bộ — người
 * KHÔNG có tài khoản FabrikAI. Trước đây chỉ có workspace trong app (phải có tài khoản) nên luồng
 * duyệt thực tế vẫn phải chụp màn hình gửi qua Zalo.
 *
 * · `project_shares`: một LINK công khai cho một bộ sưu tập. Có hạn (expires_at) và THU HỒI được
 *   (revoked_at) — chia sẻ phải rút lại được, không phải "gửi rồi chịu".
 * · `project_feedback`: phản hồi của người xem (Duyệt / Yêu cầu sửa + ghi chú) — lưu lại thành
 *   dấu vết để designer biết khách đã nói gì, thay vì tin nhắn trôi trong Zalo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Token công khai: 48 ký tự ngẫu nhiên — đoán được token là xem được bộ sưu tập.
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'revoked_at']);
        });

        Schema::create('project_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('share_id')->nullable()->constrained('project_shares')->nullOnDelete();
            $table->string('author_name', 120);
            // 'approved' = khách đồng ý · 'changes' = yêu cầu sửa
            $table->string('decision', 20);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_feedback');
        Schema::dropIfExists('project_shares');
    }
};
