<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * XOÁ dòng giá CỐ ĐỊNH của video trong model_credit_cost (2026-09-26).
     *
     * VÌ SAO: video cho người dùng chọn thời lượng 5/8/10/15/20 giây, giá vốn tính THEO GIÂY. Bản
     * seeder cũ đã gieo MỘT dòng cố định tính cho 5 giây (vd kling = 13 credit) — nhưng khách chọn
     * 20 giây thì giá vốn gấp 4 lần mà vẫn chỉ thu 13 credit ⇒ lỗ âm thầm đúng kiểu "1 ảnh = 1 credit".
     *
     * Từ đây video tính ĐỘNG trong studio_credit_cost_for() (nhánh video), nên mọi dòng bán cố định
     * của model video phải GỠ — không thì nó đứng TRƯỚC nhánh động trong thứ tự tra và ghi đè nó.
     *
     * Chỉ gỡ dòng của model mà bảng provider_price khai đơn vị 'second' — ảnh thì giữ nguyên.
     */
    public function up(): void
    {
        $videoModels = DB::table('provider_price')
            ->where('unit', 'second')
            ->pluck('model')
            ->map(fn ($m) => (string) $m)
            ->all();

        if ($videoModels) {
            DB::table('model_credit_cost')
                ->whereIn('model', $videoModels)
                ->delete();
        }
    }

    public function down(): void
    {
        // Không khôi phục: dòng cố định sai thời lượng là thứ vừa gây lỗ.
    }
};
