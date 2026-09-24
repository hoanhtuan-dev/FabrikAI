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
            fonts: [
                bunny('Inter', { weights: [400, 500, 600, 700] }),
                bunny('Fraunces', { weights: [400, 500, 600, 700], variants: ['italic'] }),
            ],
        }),
        tailwindcss(),
    ],
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
});
