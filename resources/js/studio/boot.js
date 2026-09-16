// Nạp dữ liệu khởi động (user hiện tại + trạng thái project) từ Laravel API.
// Endpoint GET /api/boot trả về đúng payload mà vue.blade.php từng inject vào
// window.__STUDIO_BOOT__. Request GET này cũng khởi tạo session + cookie XSRF-TOKEN,
// để các POST sau (gửi kèm header X-XSRF-TOKEN) không bị 419 CSRF.
const DEFAULT_BOOT = { user: null, project_statuses: [] };

export async function boot() {
  if (typeof window === 'undefined') return DEFAULT_BOOT;
  // Blade đã inject window.__STUDIO_BOOT__ (server-side) -> dùng luôn, không cần fetch lại.
  if (window.__STUDIO_BOOT__) return window.__STUDIO_BOOT__;
  try {
    const res = await fetch('/api/boot', { headers: { Accept: 'application/json' } });
    if (res.ok) {
      const data = await res.json();
      window.__STUDIO_BOOT__ = data || DEFAULT_BOOT;
      return window.__STUDIO_BOOT__;
    }
  } catch (e) {
    // Offline / backend chưa chạy: dùng boot rỗng, SPA hiển thị "Chưa đăng nhập".
  }
  window.__STUDIO_BOOT__ = window.__STUDIO_BOOT__ || DEFAULT_BOOT;
  return window.__STUDIO_BOOT__;
}
