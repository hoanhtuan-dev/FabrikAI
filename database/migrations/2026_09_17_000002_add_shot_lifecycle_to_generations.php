<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Đợt 1.1 — 2026-09-17] Nâng Generation thành artifact có VÒNG ĐỜI (shot lifecycle).
 *
 * Trước đây `status` chỉ mô tả vòng đời RENDER (pending/processing/completed/failed/cancelled).
 * Nay thêm một trục RIÊNG cho vòng đời SÁNG TẠO của "shot" (ảnh đã ra):
 *   idea → drafted → selected → fitted → campaign_ready → approved  (+ rejected ở mọi nhánh).
 *
 * Kèm theo:
 *   is_selected — đánh dấu shot được chọn vào bộ (lô) sắp xuất.
 *   note        — ghi chú review (vì sao chọn/loại).
 *   sort        — thứ tự hiển thị trong bộ chọn.
 *
 * Default 'drafted': ảnh vừa tạo ra là một "bản nháp", chưa được chọn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->string('shot_state', 32)->default('drafted')->after('status');
            $table->boolean('is_selected')->default(false)->after('shot_state');
            $table->text('note')->nullable()->after('is_selected');
            $table->integer('sort')->default(0)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['shot_state', 'is_selected', 'note', 'sort']);
        });
    }
};
