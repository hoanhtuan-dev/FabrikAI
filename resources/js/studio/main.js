import { createApp } from 'vue';
import { createPinia } from 'pinia';
import StudioApp from './StudioApp.vue';
import { boot } from './boot.js';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

// Gỡ service worker cũ (nếu migrate từ /studio của Laravel) + xoá cache để luôn tải bản mới.
// Dùng chung với 3 entry còn lại — xem pageBoot.js.
killLegacyServiceWorker();

async function start() {
  // Nạp boot payload (user + trạng thái project) từ Laravel API TRƯỚC khi mount,
  // để store.js đọc window.__STUDIO_BOOT__ đồng bộ như ở bản Laravel cũ.
  await boot();
  mountGuarded(createApp(StudioApp).use(createPinia()), '#studio-root');
}

start();
