<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRÍ NHỚ THỦ TỤC — PROCEDURAL MEMORY (GĐ2 · 2026-09-26).
 *
 * VÌ SAO CÓ BẢNG NÀY: hai loại trí nhớ kia đã có chỗ ở — SỰ KIỆN ở `brand_learning` (duyệt/loại ảnh),
 * KIẾN THỨC KHÁI QUÁT ở `brand_dna` (chủ shop khai: định vị, phong cách, màu, chất liệu, thứ KHÔNG làm).
 * Còn loại thứ ba — QUY TRÌNH — thì chưa có chỗ nào: *"khi làm đồ công sở thì ưu tiên màu trung tính và
 * chất liệu ít nhăn"* là một CÂU ĐIỀU KIỆN, không phải một sở thích phẳng. Nhét nó vào `brand_dna` là
 * bắt một hồ sơ danh sách gánh một quan hệ "khi nào → làm gì" mà nó không diễn đạt được.
 *
 * MỘT HÀNG LÀ MỘT QUY TẮC (không phải một JSON blob) vì mỗi quy tắc có thuộc tính RIÊNG và sẽ còn được
 * ghi thêm bằng đường khác: `source` phân biệt quy tắc do CHỦ SHOP đặt với quy tắc do agent RÚT RA từ
 * kinh nghiệm (đường học), `weight` để xếp hạng khi hai quy tắc xung đột, `is_active` để tắt tạm mà
 * không mất chữ đã viết.
 *
 * Dữ liệu là chữ do người dùng nhập nên đi thẳng vào prompt ⇒ trần độ dài và trần số hàng nằm ở
 * BrandRuleService (MỘT nguồn), không rải rác ở controller và giao diện.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 120);   // "khi nào" — tình huống kích hoạt quy tắc
            $table->string('action', 240);    // "làm thế nào" — cách làm bắt buộc theo
            $table->unsignedTinyInteger('weight')->default(5);   // 1..10, cao hơn = ưu tiên hơn
            $table->string('source', 20)->default('owner');      // owner | learned
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            // Đường đọc NÓNG là "lấy quy tắc ĐANG BẬT của MỘT người, theo thứ tự" — index đúng câu đó.
            $table->index(['user_id', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_rules');
    }
};
