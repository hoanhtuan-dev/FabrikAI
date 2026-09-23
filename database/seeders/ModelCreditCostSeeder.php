<?php

namespace Database\Seeders;

use App\Models\ModelCreditCost;
use App\Models\ProviderPrice;
use App\Services\ProviderCostService;
use Illuminate\Database\Seeder;

/**
 * GIÁ BÁN THEO MODEL (số credit) — SINH TỪ GIÁ VỐN, KHÔNG CHÉP TAY.
 *
 * VÌ SAO SINH THAY VÌ VIẾT TAY: công thức đã chốt ở docs/CREDIT_GOI_VA_LOI_NHUAN.md §4.4 là
 * \`credit = ceil(giá_vốn_VNĐ / 720)\` với 720 ₫ = 1.200 ₫/credit × (1 − 0,40) ⇒ bảo đảm biên
 * **≥ 40 %** ở mức đơn giá TỆ NHẤT trong mọi gói. Viết tay 18 dòng cho mỗi model là cách chắc
 * chắn để có một dòng sai — và một dòng sai ở đây là một model lỗ âm thầm.
 *
 * Sinh bằng CHÍNH ProviderCostService::suggestCredits ⇒ "cách tính" và "bảng giá" không thể lệch.
 *
 * Dùng firstOrCreate: dòng chủ dự án đã sửa tay KHÔNG bị ghi đè mỗi lần deploy. Muốn tính lại
 * toàn bộ thì chạy \`php artisan studio:pricing --sync\` (lệnh đó ghi đè, có xác nhận).
 */
class ModelCreditCostSeeder extends Seeder
{
    /** Tỉ lệ app đang dùng — khớp ImageAIService::falSizeFor và ProviderCostService::imageDimensions. */
    private const RATIOS = ['1:1', '4:3', '3:4', '4:5', '16:9', '9:16', '2:3', '21:9', '19:6'];

    private const RESOLUTIONS = ['1K', '2K'];

    public function run(): void
    {
        $cost = app(ProviderCostService::class);

        // ── Model tính theo MEGAPIXEL (hoặc theo giây): giá phụ thuộc cỡ + tỉ lệ ──────────
        $priced = ProviderPrice::query()->get();

        foreach ($priced as $price) {
            // Model theo ẢNH: một dòng duy nhất, không phụ thuộc cỡ/tỉ lệ.
            if ($price->unit === ProviderPrice::UNIT_IMAGE) {
                $credits = $cost->suggestCredits($price->provider, $price->model, []);
                ModelCreditCost::firstOrCreate(
                    ['provider' => $price->provider, 'model' => $price->model, 'resolution' => '', 'ratio' => ''],
                    ['credits' => $credits, 'note' => 'Sinh từ giá vốn $'.$price->unit_price_usd.'/ảnh'],
                );
                continue;
            }

            // Model theo GIÂY (video): KHÔNG seed vào bảng cố định.
            // VÌ SAO: video cho người dùng chọn thời lượng 5/8/10/15/20 giây, và giá vốn tính
            // THEO GIÂY. Một dòng cố định thì 20 giây tốn 4 lần 5 giây nhưng chỉ thu 1 lần — lỗ
            // âm thầm đúng kiểu "1 ảnh = 1 credit" ta vừa bỏ. Giá video được TÍNH ĐỘNG trong
            // studio_credit_cost_for() (nhánh video), nên bảng chỉ cần dữ liệu VỐN ở đây.
            if ($price->unit === ProviderPrice::UNIT_SECOND) {
                continue;
            }

            // Model theo MEGAPIXEL: một dòng cho MỖI (cỡ × tỉ lệ) — vì fal tính megapixel làm
            // tròn LÊN, nên 1K 1:1 (2 MP) và 1K 4:5 (1 MP) có giá vốn chênh nhau GẤP ĐÔI.
            foreach (self::RESOLUTIONS as $resolution) {
                foreach (self::RATIOS as $ratio) {
                    [$w, $h] = $cost->imageDimensions($resolution, $ratio);
                    $credits = $cost->suggestCredits($price->provider, $price->model, ['width' => $w, 'height' => $h]);

                    ModelCreditCost::firstOrCreate(
                        ['provider' => $price->provider, 'model' => $price->model, 'resolution' => $resolution, 'ratio' => $ratio],
                        ['credits' => $credits, 'note' => $resolution.' '.$ratio.' = '.$cost->billableMegapixels($w, $h).' MP'],
                    );
                }
            }
        }
    }
}
