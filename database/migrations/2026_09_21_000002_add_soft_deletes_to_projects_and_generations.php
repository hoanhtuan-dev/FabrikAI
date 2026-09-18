<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SOFT DELETE cho bộ sưu tập và ảnh (R6 — 2026-09-20).
 *
 * Vì sao: trước đây xoá bộ sưu tập là xoá VĨNH VIỄN — lỡ chủ nhóm bấm nhầm "Xóa" thì mất
 * brief, thẻ, tiến độ, phản hồi khách duyệt và cả liên kết ảnh tải lên. Soft-delete cho phép
 * khôi phục từ thùng rác (hoặc xoá vĩnh viễn sau 30 ngày).
 *
 * deleted_at thêm vào CẢ hai bảng:
 *   - projects:   bộ sưu tập vào thùng rác ⇒ các ảnh trong bộ KHÔNG bị xoá (giữ nguyên project_id),
 *                 chỉ là bộ không còn hiển thị trong danh sách active.
 *   - generations: ảnh vào thùng rác ⇒ không hiển thị trong workspace/thư viện.
 *
 * Xoá vĩnh viễn bộ sưu tập (sau 30 ngày hoặc chủ nhóm xác nhận) sẽ cascade xoá các liên kết
 * upload_project_links thuộc về bộ đó (đã có observer bên dưới).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('generations', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
