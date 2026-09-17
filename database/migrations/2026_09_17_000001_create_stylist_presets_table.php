<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Đợt 0.8 — 2026-09-17] Bảng stylist_presets trước đây KHÔNG có migration riêng mà chỉ được
 * StylistCatalog::ensureTables() tạo lúc chạy (Schema::create ngay trong request). Vì Đợt 0.8
 * cấm DDL trên đường ĐỌC công khai, bảng này giờ phải được tạo ĐÚNG CÁCH qua migrate.
 *
 * Guard hasTable để không đụng vào hosting đã có sẵn bảng (do ensureTables cũ đã tạo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stylist_presets')) {
            return;
        }

        Schema::create('stylist_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('prompt');
            $table->string('type', 40)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_presets');
    }
};
