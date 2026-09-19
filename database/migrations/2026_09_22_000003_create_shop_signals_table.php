<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Yêu cầu 2026-09-22] DỮ LIỆU THẬT CỦA SHOP — nền tảng cho "gắn bó lâu dài".
 *
 * Vì sao cần: Agent Studio trước đây chỉ đọc project/generation của tài khoản (đếm số lượng) và
 * ĐOÁN nhóm hàng bằng cách dò từ khoá trong prompt. Chủ xưởng thì có sẵn thứ quan trọng hơn
 * nhiều: cái gì ĐÃ BÁN ĐƯỢC, bán bao nhiêu, còn tồn bao nhiêu, đổi trả bao nhiêu, giá bán bao
 * nhiêu. Không có mấy con số đó thì mọi lời khuyên "nên làm gì" đều chỉ là phỏng đoán — và phỏng
 * đoán thì không ai trả tiền.
 *
 * Người bán hàng qua Facebook/Zalo/POS đều nhập tay hoặc dán từ Excel được, nên bảng này cố tình
 * TỐI GIẢN (6 cột số) và ghi rõ nguồn ('manual' | 'paste') để giao diện nói thật dữ liệu đến từ đâu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_signals', function (Blueprint $table) {
            $table->id();
            // Xoá tài khoản là xoá luôn dữ liệu bán hàng của họ.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);                    // tên sản phẩm / nhóm hàng của shop
            $table->string('category', 80)->nullable();     // nhóm (áo · quần · váy · phụ kiện…)
            $table->unsignedInteger('units_sold')->default(0);
            $table->unsignedInteger('stock_on_hand')->default(0);
            $table->unsignedInteger('returns')->default(0);
            $table->unsignedInteger('price_vnd')->default(0);
            $table->unsignedInteger('period_days')->default(30);
            $table->string('source', 20)->default('manual');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // Truy vấn chính: "dữ liệu shop của tôi, xếp theo bán chạy".
            $table->index(['user_id', 'units_sold']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_signals');
    }
};
