/**
 * Việc khởi động chung cho CẢ 4 entry SPA (main · settings · presets · stylist-data).
 *
 * Lý do có file này — cùng một lớp bug đã tái diễn nhiều lần trong repo: bản vá được áp ở MỘT chỗ
 * rồi bỏ quên các chỗ tương đương.
 *   • `main.js` gỡ service worker cũ + guard element trước khi mount; 3 entry còn lại thì không.
 *   • Hệ quả: người vào thẳng `/settings` · `/presets` · `/stylist-data` vẫn bị service worker CŨ
 *     (đăng ký từ thời `/studio`, scope `/`) phục vụ asset cũ; và nếu blade thiếu element gốc thì
 *     `mount('#x-root')` ném lỗi khó đọc thay vì nói rõ nguyên nhân.
 * Gom về một chỗ để không thể lệch lại.
 */

/**
 * Gỡ mọi service worker còn đăng ký + xoá cache của chúng.
 *
 * Chạy trên MỌI lần tải trang: người dùng migrate từ `/studio` của bản Laravel cũ còn giữ
 * `sw-studio.js` (scope `/studio`) hoặc `sw.js` (scope `/`), và service worker cũ sẽ phục vụ
 * JavaScript/CSS đã lâu không còn tồn tại → SPA trắng trang mà không có lỗi rõ ràng.
 *
 * Không chặn luồng khởi động: chạy nền, mọi lỗi đều bỏ qua (chế độ riêng tư của trình duyệt có thể
 * chặn `caches`) — hỏng việc dọn dẹp KHÔNG được làm hỏng việc render app.
 */
export function killLegacyServiceWorker() {
  if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations()
      .then((regs) => regs.forEach((reg) => reg.unregister()))
      .catch(() => {});
  }
  if (typeof caches !== 'undefined') {
    caches.keys()
      .then((keys) => keys.forEach((k) => caches.delete(k)))
      .catch(() => {});
  }
}

/**
 * Mount app vào element gốc, có guard.
 *
 * Trước đây `mount('#settings-root')` khi blade thiếu element sẽ ném lỗi Vue khó đọc
 * ("Failed to mount app: mount target selector returned null") mà không nói element nào / file nào.
 *
 * @returns {object|null} instance app, hoặc null nếu không tìm thấy element gốc.
 */
export function mountGuarded(app, selector) {
  const el = document.querySelector(selector);
  if (!el) {
    console.error(
      `FabrikAI: không tìm thấy ${selector} — kiểm tra blade tương ứng trong resources/views/studio/`,
    );
    return null;
  }

  return app.mount(el);
}
