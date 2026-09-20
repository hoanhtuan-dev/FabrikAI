<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRÍ NHỚ DÀI HẠN CỦA AGENT STUDIO (GĐ1 — 2026-09-24): học từ lựa chọn của chủ shop.
 *
 * Mỗi khi chủ shop DUYỆT hoặc LOẠI một ảnh trong buổi duyệt mẫu, ta ghi lại prompt của ảnh đó kèm quyết
 * định. Prompt chứa từ khoá phong cách/màu/chất liệu nên đây là "gu" thật của shop — dùng để nhét vào
 * prompt brief sau này (ưu tiên phong cách đã duyệt, tránh phong cách đã loại). Không lưu ảnh, chỉ lưu
 * chữ đã rút gọn (500 ký tự) nên bảng rất nhẹ và không chứa dữ liệu cá nhân ngoài prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_learning', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('generation_id')->nullable();
            $table->string('decision'); // 'approved' | 'rejected'
            $table->string('prompt', 500);
            $table->string('source', 20)->default('shot_review');
            $table->timestamps();
            $table->index(['user_id', 'decision', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_learning');
    }
};
