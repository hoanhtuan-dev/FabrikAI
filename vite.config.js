import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        vue(),
        laravel({
            input: [
                'resources/css/app.css',
                // (T8) Đã bỏ 'resources/js/app.js' — entry Alpine 574 dòng của storefront, KHÔNG
                // view nào nạp (grep "@vite" chỉ ra app.css; grep "x-data" trong resources/views = 0).
                'resources/js/studio/main.js',
                // [Yêu cầu 2026-09-26 · đợt 24] TRANG HỢP NHẤT "Cài đặt & Quản trị": MỘT entry duy nhất.
                // Ba entry cũ (settings.js · my-settings.js · admin.js) đã XOÁ cùng ba blade của chúng —
                // mọi URL cũ (/settings, /admin, /cai-dat, /presets, /model-settings, /stylist-data) nay
                // cùng trả view studio.hub.
                'resources/js/studio/hub.js',
                'resources/js/studio/collections.js',
                // [Yêu cầu 2026-09-25] TRANG AGENT STUDIO — luồng 4 bước tách khỏi modal trong
                // /studio thành trang riêng, nên có entry riêng: trang này KHÔNG cần cả xưởng thiết
                // kế (canvas · dock · layers) mà main.js chở theo.
                'resources/js/studio/agent-studio.js',
                // [Shell 2026 · Phase 1] TRANG CHỦ prompt-first '/' — entry nhẹ, không kéo theo
                // canvas/store của xưởng (main.js).
                'resources/js/studio/home.js',
            ],
            refresh: true,
            publicDirectory: 'public_html',
            /* [Đợt 62 · 2026-09-26] BA HỌ CHỮ THEO PROTOTYPE (prototype/css/tokens.css khai đúng ba
               biến: --f-ui · --f-display · --f-mono):
                 · Inter        — toàn bộ chữ giao diện;
                 · Fraunces     — chữ TIÊU ĐỀ (display serif, có bản nghiêng cho nhấn);
                 · Space Grotesk— NHÃN NHỎ IN HOA (kiểu "kỹ thuật/phòng lab" của prototype).
               VÌ SAO PHẢI SỬA: bản trước khai `--font-display: 'Inter'` (bỏ serif) trong khi vẫn TẢI
               Fraunces 4 weight + italic ⇒ vừa lệch prototype vừa tải thừa ~4 tệp font cho một họ chữ
               không dùng ở đâu. Nay Fraunces được dùng thật, và Space Grotesk được tải thêm — tổng số
               họ chữ vẫn là 3, đúng bằng prototype. */
            fonts: [
                bunny('Inter', { weights: [400, 500, 600, 700] }),
                bunny('Fraunces', { weights: [400, 500, 600, 700], variants: ['italic'] }),
                bunny('Space Grotesk', { weights: [400, 500, 600] }),
            ],
        }),
        tailwindcss(),
    ],
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
});
