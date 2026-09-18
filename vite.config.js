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
                'resources/js/studio/settings.js',
                // [Yêu cầu 2026-09-20] KHU "CÀI ĐẶT CỦA TÔI" HỢP NHẤT — thay 3 entry rời rạc
                // (presets.js · stylist-data.js · model-settings.js). Một app duy nhất phục vụ cả
                // 4 lối vào: /cai-dat/<mục> cùng các URL cũ /presets · /stylist-data · /model-settings.
                'resources/js/studio/my-settings.js',
                'resources/js/studio/admin.js',
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
