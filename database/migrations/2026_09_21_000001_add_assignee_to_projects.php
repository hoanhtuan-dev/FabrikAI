<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NGƯỜI PHỤ TRÁCH một bộ sưu tập (P1.2 — 2026-09-20).
 *
 * Vì sao: trước đây một bộ sưu tập chỉ có "chủ sở hữu" (người tạo), không có chỗ nói "việc này
 * giao cho ai". Với gói có SỐ GHẾ (nhiều người dùng chung một bộ sưu tập), không có người phụ
 * trách nghĩa là: không giao được việc, không lọc được "việc của tôi", không biết ai đang làm gì —
 * đúng ba việc mà một studio cần. Cột này KHÔNG thay `user_id` (quyền sở hữu/thanh toán vẫn thuộc
 * chủ bộ sưu tập); nó chỉ trả lời "ai đang làm".
 *
 * nullOnDelete: tài khoản bị xoá thì bộ sưu tập KHÔNG được xoá theo — chỉ mất nhãn người phụ trách.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['assignee_id', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['assignee_id', 'archived']);
            $table->dropConstrainedForeignId('assignee_id');
        });
    }
};
