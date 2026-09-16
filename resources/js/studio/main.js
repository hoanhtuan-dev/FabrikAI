import { createApp } from 'vue';
import { createPinia } from 'pinia';
import StudioApp from './StudioApp.vue';
import { boot } from './boot.js';

// Gỡ service worker cũ (nếu migrate từ /studio của Laravel) + xoá cache để luôn tải bản mới.
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.getRegistrations()
    .then((regs) => regs.forEach((reg) => reg.unregister()))
    .catch(() => {});
  if (typeof caches !== 'undefined') {
    caches.keys()
      .then((keys) => keys.forEach((k) => caches.delete(k)))
      .catch(() => {});
  }
}

async function start() {
  // Nạp boot payload (user + trạng thái project) từ Laravel API TRƯỚC khi mount,
  // để store.js đọc window.__STUDIO_BOOT__ đồng bộ như ở bản Laravel cũ.
  await boot();
  // §5.2: guard element — trước đây mount() vào selector không tồn tại sẽ ném lỗi khó đọc và
  // không nói rõ nguyên nhân (blade thiếu #studio-root).
  const el = document.querySelector('#studio-root');
  if (!el) {
    console.error('FabrikAI: không tìm thấy #studio-root — kiểm tra resources/views/studio/index.blade.php');
    return;
  }
  createApp(StudioApp).use(createPinia()).mount(el);
}

start();
