import { createApp } from 'vue';
import { createPinia } from 'pinia';
import AgentStudioApp from './AgentStudioApp.vue';
import { ripple } from './ripple.js';
import { boot } from './boot.js';
import { killLegacyServiceWorker, mountGuarded } from './pageBoot.js';

// Gỡ service worker cũ (nếu migrate từ /studio của Laravel) + xoá cache để luôn tải bản mới.
// Dùng chung với các entry còn lại — xem pageBoot.js.
killLegacyServiceWorker();

// Entry của TRANG Agent Studio (/agent-studio) — tách khỏi main.js vì trang này KHÔNG cần cả
// xưởng thiết kế (canvas · dock · layers): nạp main.js ở đây là gửi thừa ~1 bản bundle nữa.
async function start() {
  // Nạp boot payload (user + trạng thái project) từ Laravel API TRƯỚC khi mount,
  // để store.js đọc window.__STUDIO_BOOT__ đồng bộ như ở bản Laravel cũ.
  await boot();
  const app = createApp(AgentStudioApp).use(createPinia());
  // Gợn nước ở nút chính (Material) — hành vi gắn thêm, không phải một loại nút mới.
  app.directive('ripple', ripple);
  mountGuarded(app, '#agent-studio-root');
}

start();
