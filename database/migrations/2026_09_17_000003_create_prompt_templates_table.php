<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Đợt 1.7 — 2026-09-17] Bảng prompt_templates: đưa các chuỗi prompt hardcode ra DB.
 *
 * Mỗi hàng = một phiên bản của một prompt có key ổn định. Resolver (studio_prompt_template)
 * luôn chọn phiên bản CAO NHẤT đang active cho key — đổi prompt KHÔNG cần deploy.
 * scope: global | brand | category (để sau phân tầng theo thương hiệu/danh mục).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->string('scope', 20)->default('global');
            $table->integer('version')->default(1);
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['key', 'version']);
            $table->index(['key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_templates');
    }
};
