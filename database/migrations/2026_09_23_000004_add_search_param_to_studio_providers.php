<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THAM SỐ BẬT TÌM KIẾM WEB của một CUSTOM PROVIDER (2026-09-23).
 *
 * Vì sao cần: tính năng "agent có tìm kiếm web" phải do CÀI ĐẶT quyết định, không phải do mã nguồn
 * biết sẵn tên nhà cung cấp. Các giao thức đã biết (Qwen/DashScope `enable_search`, Gemini
 * `google_search`) nằm ở bảng giao thức trong WebAccessService; còn nhà cung cấp TỰ KHAI thì cần một
 * chỗ để nói "gateway của tôi bật tìm kiếm bằng tham số X" — đó là cột này.
 *
 * NULL = chưa khai ⇒ hệ thống KHÔNG đoán là có tìm kiếm (đoán sai ở đây nghĩa là hứa với khách một
 * năng lực không tồn tại).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_providers', function (Blueprint $table) {
            $table->string('search_param', 40)->nullable()->after('auth_style');
        });
    }

    public function down(): void
    {
        Schema::table('studio_providers', function (Blueprint $table) {
            $table->dropColumn('search_param');
        });
    }
};
