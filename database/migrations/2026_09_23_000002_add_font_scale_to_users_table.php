<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CỠ CHỮ TOÀN CỤC THEO TÀI KHOẢN (2026-09-23).
 *
 * Phản hồi thật: "tỷ lệ chữ vẫn hơi nhỏ" ⇒ vừa nhích thang cỡ chữ +1px ở mọi bậc, vừa cho người dùng
 * tự chỉnh. Giá trị lưu là PHẦN TRĂM (90 · 100 · 115 · 130), không phải px: px là số cứng, còn phần
 * trăm nhân vào token --text-* nên mọi bậc chữ to/nhỏ cùng nhau và vẫn giữ đúng tỉ lệ đã thiết kế.
 *
 * NULL = "chưa từng chọn" ⇒ dùng 100% (giống cách users.theme xử lý: đổi mặc định sản phẩm sau này
 * vẫn áp được cho người chưa chọn mà không ghi đè lựa chọn của ai).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('font_scale')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('font_scale');
        });
    }
};
