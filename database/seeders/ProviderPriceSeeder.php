<?php

namespace Database\Seeders;

use App\Models\ProviderPrice;
use Illuminate\Database\Seeder;

/**
 * GIÁ VỐN NHÀ CUNG CẤP — DỮ LIỆU, KHÔNG PHẢI HẰNG SỐ TRONG MÃ.
 *
 * Nguồn từng dòng: docs/PRICING_RESEARCH_2026-09-23.md (đọc thẳng trang model của fal / bảng giá
 * Model Studio của Alibaba, mỗi dòng có URL). Giá đổi theo thời gian ⇒ sửa Ở ĐÂY (hoặc trong
 * Quản trị), rồi chạy \`php artisan studio:pricing --sync\` để tính lại giá bán.
 *
 * ⚠️ ĐƠN VỊ LÀ CHỖ DỄ SAI NHẤT — sai ở đây thì MỌI con số lãi đều sai:
 *   · fal   → 'megapixel' (và fal làm tròn LÊN) hoặc 'image'
 *   · Qwen/DashScope → 'image'  ← tính theo ẢNH, KHÔNG theo megapixel
 *   · video → 'second'
 *
 * Dùng firstOrCreate (KHÔNG updateOrCreate): giá chủ dự án đã sửa tay trong Quản trị không bị
 * seeder ghi đè mỗi lần deploy.
 */
class ProviderPriceSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // ══ MODEL PRODUCTION ĐANG GỌI THẬT (đo bằng SSH vào máy chủ 2026-09-26) ═══════════
            // Đây là bài học đắt nhất của đợt này: bảng giá seed theo TÀI LIỆU không khớp với
            // bảng model ĐANG CHẠY. Model đang chạy mới là model phải khai giá — không thì sổ
            // chi phí ghi 'unknown_cost' cho gần như mọi lượt và báo cáo lợi nhuận vô dụng.
            //
            // [model, đơn vị, giá đơn vị ĐẦU, giá đơn vị THÊM, số MẶT tính tiền, ghi chú]
            ['fal', 'fal-ai/flux-2-flex', ProviderPrice::UNIT_MEGAPIXEL, 0.05, 0.05, 1,
                'Production (nhóm image). fal: "$0.05 per megapixel on BOTH input and output side, rounded up".'],
            ['fal', 'fal-ai/flux-2-pro', ProviderPrice::UNIT_MEGAPIXEL, 0.015, 0.03, 1,
                'Production (nhóm image). fal: "$0.03 for the FIRST megapixel of output, plus $0.015 per EXTRA megapixel".'],
            ['fal', 'fal-ai/flux-2-pro/edit', ProviderPrice::UNIT_MEGAPIXEL, 0.015, 0.03, 2,
                'Production (nhóm edit). CÙNG công thức nhưng tính CẢ mặt vào LẪN mặt ra ⇒ billed_sides = 2.'],
            ['fal', 'fal-ai/flux-pro/v1/vto', ProviderPrice::UNIT_MEGAPIXEL, 0.005, 0.0375, 3,
                'Production (nhóm swap — mặc thử). fal: "$0.0375 for the FIRST INPUT megapixel, plus $0.005 per extra input and $0.005 per output". 2 ảnh vào (người + đồ) + 1 ảnh ra ⇒ sides = 3.'],

            // ── DashScope / Qwen — model đang khai trong Model Registry ─────────────────────
            ['dashscope', 'qwen-image-edit-2511', ProviderPrice::UNIT_IMAGE, 0.045, null, 1,
                'Production (nhóm edit). DashScope tính theo ẢNH, không theo megapixel ⇒ ảnh 2K KHÔNG đắt hơn 1K.'],
            ['dashscope', 'qwen-image-edit-max', ProviderPrice::UNIT_IMAGE, 0.075, null, 1,
                'Production (nhóm edit) — bản Max, đắt gấp rưỡi bản thường.'],

            // ══ Các model dự phòng / thay thế ═══════════════════════════════════════════════
            ['fal', 'fal-ai/flux/schnell', ProviderPrice::UNIT_MEGAPIXEL, 0.003, null, 1,
                'Rẻ nhất. fal ghi: billed by rounding up to the nearest megapixel.'],
            ['fal', 'fal-ai/flux/dev', ProviderPrice::UNIT_MEGAPIXEL, 0.025, null, 1, 'Cân bằng chất lượng/giá.'],

            // ── fal.ai — SỬA ẢNH theo MASK ────────────────────────────────────────────────
            ['fal', 'fal-ai/flux-pro/v1/fill', ProviderPrice::UNIT_MEGAPIXEL, 0.05, null, 1,
                'Inpaint theo mask. fal ghi rõ: white(255) = vùng CẦN XOÁ/SỬA — NGƯỢC với mask của ta.'],
            ['fal', 'fal-ai/nano-banana/edit', ProviderPrice::UNIT_IMAGE, 0.0398, null, 1,
                'Sửa bằng MÔ TẢ (không mask) — đường mặc định của màn Chỉnh ảnh.'],
            ['fal', 'fal-ai/nano-banana-pro/edit', ProviderPrice::UNIT_IMAGE, 0.15, null, 1, 'Bản cao cấp, 4K gấp đôi.'],
            ['fal', 'fal-ai/qwen-image-edit', ProviderPrice::UNIT_MEGAPIXEL, 0.03, null, 1, 'Sửa ảnh — bản chạy trên fal.'],

            // ── fal.ai — hậu kỳ ──────────────────────────────────────────────────────────
            ['fal', 'fal-ai/clarity-upscaler', ProviderPrice::UNIT_MEGAPIXEL, 0.03, null, 1, 'Upscale tính theo MP đầu ra.'],

            // ── fal.ai — video ───────────────────────────────────────────────────────────
            ['fal', 'fal-ai/kling-video/v2.5-turbo/pro/text-to-video', ProviderPrice::UNIT_SECOND, 0.07, null, 1,
                'Video 5 giây = $0,35. veo3 ($0,40/giây, 5 giây = $2,00) ĐÃ BỎ KHỎI UI vì lỗ nặng — xem §3.5.'],

            // ── Qwen / DashScope — ĐƯỜNG DỰ PHÒNG (D3) ───────────────────────────────────
            ['dashscope', 'qwen-image-edit', ProviderPrice::UNIT_IMAGE, 0.045, null, 1,
                'Tính theo ẢNH, KHÔNG theo megapixel — nên ảnh 2K rẻ hơn fal rõ rệt.'],
            ['dashscope', 'qwen-image-edit-plus', ProviderPrice::UNIT_IMAGE, 0.03, null, 1, 'Rẻ hơn bản thường 50 %.'],
            ['dashscope', 'qwen-image', ProviderPrice::UNIT_IMAGE, 0.035, null, 1, 'Tạo ảnh — dự phòng.'],
        ];

        foreach ($rows as [$provider, $model, $unit, $extra, $first, $sides, $note]) {
            ProviderPrice::firstOrCreate(
                ['provider' => $provider, 'model' => $model],
                [
                    'unit' => $unit,
                    'unit_price_usd' => $extra,
                    'first_unit_price_usd' => $first,
                    'billed_sides' => $sides,
                    'note' => $note,
                ],
            );
        }
    }
}
