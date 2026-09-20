<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DNA THƯƠNG HIỆU CỦA MỘT TÀI KHOẢN (2026-09-23).
 *
 * Vì sao có bảng này: Agent Studio đang TỰ ĐOÁN DNA của shop — đếm project/generation rồi dò từ khoá
 * trong prompt, không có dữ liệu bán hàng thì rơi về một câu mặc định cứng ("tối giản, dễ phối, chất
 * liệu thoáng"). Chủ shop KHÔNG có chỗ nào để nói thẳng mình là ai: định vị, khách hàng mục tiêu, dải
 * giá, phong cách, màu chủ đạo, nhóm hàng, chất liệu, và những thứ KHÔNG làm.
 *
 * Một hàng cho một tài khoản (unique user_id). Dữ liệu để trong cột json vì đây là hồ sơ do CHÍNH chủ
 * shop khai, các trường sẽ còn tiến hoá; service BrandDnaService chuẩn hoá + giới hạn độ dài nên dữ
 * liệu bẩn không vào được DB.
 *
 * Không dùng user_catalogs: bảng đó có shape 3 phần (custom/edits/hidden) cho danh sách, ép hồ sơ DNA
 * vào đó sẽ tạo dữ liệu mang hình dạng sai và không ai đọc được về sau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_dna', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_dna');
    }
};
