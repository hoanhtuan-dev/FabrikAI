<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KIỂU BẬT TÌM KIẾM WEB của một Custom Provider (2026-09-23).
 *
 * Vì sao cần thêm cột: chỉ một kiểu "gửi cờ trong body" là chưa đủ cho thực tế các gateway:
 *   · body_flag    — gửi `{"<tham số>": true}` (DashScope/Qwen: enable_search);
 *   · tools        — gửi `tools: [{"<tham số>": {}}]` (Gemini: google_search);
 *   · model_suffix — NỐI vào tên model (OpenRouter kiểu `:online`);
 *   · plugins      — gửi `plugins: [{"id": "<tham số>"}]` (một số gateway OpenAI-compatible).
 *
 * Nhờ vậy người dùng khai được gateway CÓ tìm kiếm mà không phải sửa mã — đúng nguyên tắc: cấu hình
 * quyết định, mã nguồn chỉ biết các GIAO THỨC phổ biến.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_providers', function (Blueprint $table) {
            $table->string('search_mode', 20)->nullable()->after('search_param');
        });
    }

    public function down(): void
    {
        Schema::table('studio_providers', function (Blueprint $table) {
            $table->dropColumn('search_mode');
        });
    }
};
