<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHIẾU KỸ THUẬT (TECH PACK) CỦA MỘT BỘ SƯU TẬP — 2026-09-26.
 *
 * VÌ SAO CÓ BẢNG NÀY: gói xuất cho xưởng đã có từ Đợt 4, nhưng "phiếu kỹ thuật" trong đó chỉ là một
 * tệp CHỮ CÓ DÒNG CHẤM ĐỂ XƯỞNG TỰ ĐIỀN ("Chất liệu: ......................"). Nghĩa là chủ shop
 * KHÔNG có chỗ nào ghi thông số trong ứng dụng — họ phải in ra rồi viết tay, và lần xuất sau lại ra
 * một tờ giấy trắng khác. Đây là mắt xích mất giữa "ảnh AI" và "lệnh cho xưởng".
 *
 * MỘT HÀNG CHO MỘT BỘ SƯU TẬP (unique project_id): phiếu kỹ thuật là thuộc tính CỦA BỘ, không phải
 * của từng ảnh — cùng một bộ thì cùng một vải, cùng một bảng thông số. Dữ liệu để trong cột json vì
 * đây là hồ sơ do người dùng khai và các trường sẽ còn tiến hoá; TechPackService chuẩn hoá + chặn
 * trần nên dữ liệu bẩn không vào được DB.
 *
 * Cascade khi xoá bộ sưu tập: phiếu kỹ thuật không có nghĩa gì khi bộ đã bị xoá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tech_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tech_packs');
    }
};
