<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GIÁ BẬC THANG + SỐ MẶT ĐƯỢC TÍNH TIỀN (2026-09-26).
     *
     * VÌ SAO PHẢI THÊM — đo trực tiếp trên trang model của fal, đúng những model production đang gọi:
     *
     *   fal-ai/flux-2-pro       "**$0.03** for the FIRST megapixel of output, plus **$0.015** per
     *                            EXTRA megapixel of input and output, rounded up"
     *   fal-ai/flux-2-flex      "**$0.05** per megapixel on BOTH input and output side, rounded up"
     *   fal-ai/flux-pro/v1/vto  "**$0.0375** for the FIRST input megapixel, plus **$0.005** per extra
     *                            input megapixel and **$0.005** per output megapixel"
     *
     * Mô hình cũ (\`unit_price_usd × units\`) KHÔNG biểu diễn được ba điều này:
     *   · đơn vị ĐẦU có giá KHÁC đơn vị thêm,
     *   · ảnh EDIT bị tính CẢ mặt vào LẪN mặt ra (không phải chỉ mặt ra),
     *   · mỗi ảnh vào được làm tròn RIÊNG rồi mới cộng.
     *
     * Sai ở đây thì báo cáo lợi nhuận sai — mà báo cáo sai còn tệ hơn không có báo cáo.
     */
    public function up(): void
    {
        Schema::table('provider_price', function (Blueprint $table) {
            // Giá đơn vị ĐẦU TIÊN. NULL = giá phẳng (mọi đơn vị cùng giá như unit_price_usd).
            $table->decimal('first_unit_price_usd', 12, 6)->nullable()->after('unit_price_usd');
            // Số MẶT được tính tiền: 1 = chỉ đầu ra (t2i), 2 = vào + ra (edit), 3 = 2 ảnh vào + 1 ra (VTO).
            // Là CẤU HÌNH vì cùng một model có thể được gọi theo hai cách khác nhau.
            $table->unsignedTinyInteger('billed_sides')->default(1)->after('first_unit_price_usd');
        });
    }

    public function down(): void
    {
        Schema::table('provider_price', function (Blueprint $table) {
            $table->dropColumn(['first_unit_price_usd', 'billed_sides']);
        });
    }
};
