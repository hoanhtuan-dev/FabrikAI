/**
 * Entry TRANG CHỦ (shell 2026). Không dùng store.js/pinia: trang sảnh chỉ cần boot payload
 * (user) đã nhúng trong blade + một fetch /latest — nhẹ hơn entry studio nhiều lần.
 */
import { createApp } from 'vue';
import HomeApp from './HomeApp.vue';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

killLegacyServiceWorker();
mountGuarded(createApp(HomeApp), '#home-root');
