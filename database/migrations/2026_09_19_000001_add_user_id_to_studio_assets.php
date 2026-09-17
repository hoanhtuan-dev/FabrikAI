<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Yêu cầu 2026-09-17] Khuôn mặt (model) + Dáng pose (người mẫu) RIÊNG theo user.
 *
 * `studio_assets` trước đây là bảng TOÀN CỤC (không có user_id): mặt/dáng do một người thêm
 * thì MỌI người thấy, và endpoint thêm/xoá buộc phải giữ ở nhóm ADMIN ⇒ người dùng thường không
 * tự thêm được khuôn mặt/dáng của mình.
 *
 * Thêm `user_id` (nullable):
 *   · user_id = NULL  ⇒ tài nguyên DÙNG CHUNG (dữ liệu có TRƯỚC khi tách theo user);
 *   · user_id = <id>  ⇒ tài nguyên RIÊNG của user đó (chỉ họ thấy; owner thấy tất cả).
 *
 * Vì sao lưu ở SERVER chứ không phải localStorage như /presets · /stylist-data: khi tạo ảnh,
 * studio gửi LÊN id của mặt/dáng và backend phải tra ra ảnh tham chiếu (VirtualTryOnService::
 * pickModel/pickPose). Id nằm trong localStorage thì backend KHÔNG tra được ⇒ tính năng vô hiệu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_assets', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->index(['type', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('studio_assets', function (Blueprint $table) {
            $table->dropIndex(['type', 'user_id']);
            $table->dropColumn('user_id');
        });
    }
};
