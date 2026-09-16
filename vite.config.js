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
                'resources/js/app.js',
                'resources/js/studio/main.js',
                'resources/js/studio/settings.js',
                'resources/js/studio/presets.js',
                'resources/js/studio/stylist-data.js',
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
