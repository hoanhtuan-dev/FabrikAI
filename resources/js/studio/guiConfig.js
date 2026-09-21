/**
 * CẤU HÌNH GIAO DIỄN + QUYỀN THEO GÓI (GET /api/gui) — MỘT chỗ nạp cho MỌI trang SPA.
 *
 * Vì sao tách khỏi StudioApp.vue: quyền module (`stylist` · `trend_radar` · `collection_bot` …) là
 * thứ MỌI trang cần biết để không mời người dùng bấm vào một tính năng họ không có. Trước đây chỉ
 * StudioApp nạp, nên trang /agent-studio "chưa biết ⇒ coi như mở" (đúng luật của `moduleLocked`) và
 * để bốn bước chạy rồi mỗi lượt gọi API trả về một dòng lỗi — người dùng đọc thành "sản phẩm hỏng".
 *
 * Luật giữ nguyên từ bản cũ trong StudioApp:
 *   · KHÔNG chặn khởi động, KHÔNG làm hỏng app — API lỗi thì giữ nguyên mặc định trong mã;
 *   · trang nào cần thì gọi, và chỉ gọi MỘT lần cho mỗi lần tải trang.
 */
export async function fetchGuiConfig() {
  try {
    const res = await fetch('/api/gui', { headers: { Accept: 'application/json' } });
    if (!res.ok) return null;
    return await res.json();
  } catch (e) {
    // [Modules] Không đọc được cấu hình thì giữ hành vi cũ (không chặn ai).
    return null;
  }
}

/**
 * Áp cấu hình vừa đọc vào store.
 * Trả về danh sách mục thanh công cụ NẾU máy chủ có cấu hình riêng, ngược lại null (giữ bản gốc).
 */
export function applyGuiConfig(store, data) {
  if (!data || typeof data !== 'object') return null;
  // [Modules 2026-09-19] Mục nào bị khoá theo gói thì hiện ổ khoá + mời nâng cấp, thay vì để khách
  // bấm vào rồi bị máy chủ chặn mà không hiểu vì sao.
  if (Array.isArray(data.modules)) {
    store.setModuleAccess(data.modules, data.modules_allowed || [], data.modules_catalog || []);
  }
  return Array.isArray(data.activityBar) && data.activityBar.length ? data.activityBar : null;
}
