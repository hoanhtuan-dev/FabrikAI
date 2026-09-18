/**
 * Thông báo (toast) DÙNG CHUNG cho cả khu "Cài đặt của tôi".
 *
 * [Lý do tách ra 2026-09-20] Trước đây MỖI trang tự chế một biến `toast` + một div `fixed bottom-5 right-5`
 * riêng (PresetsApp và ModelSettingsApp mỗi nơi một kiểu, thời lượng khác nhau 2600ms vs 2800ms).
 * Người dùng thấy cùng một loại phản hồi nhưng hiển thị khác nhau tuỳ trang. Nay một nguồn duy nhất,
 * theo đúng mô hình NotificationCenter đã dùng trong Studio: xếp chồng, tự tắt, xoá được, có nút xoá hết.
 *
 * Trạng thái đặt ở phạm vi MODULE (không phải trong component) để section nào cũng gọi được mà không
 * phải prop-drilling qua nhiều tầng.
 */
import { reactive } from 'vue';

const MAX = 4;
const TTL = { error: 8000, success: 4200, info: 4200 };

export const toastState = reactive({ items: [] });
let seq = 0;

/** Đẩy một thông báo. type: success | error | info */
export function notify(message, type = 'success') {
  const id = ++seq;
  toastState.items.push({ id, message: String(message), type });
  // Giữ tối đa MAX thẻ: cũ nhất tự rụng, tránh che kín màn hình khi thao tác nhanh.
  while (toastState.items.length > MAX) toastState.items.shift();
  setTimeout(() => dismiss(id), TTL[type] || TTL.info);
  return id;
}

export function dismiss(id) {
  const i = toastState.items.findIndex((t) => t.id === id);
  if (i >= 0) toastState.items.splice(i, 1);
}

export function clearAll() { toastState.items.splice(0, toastState.items.length); }

/** Tiện dụng: notify.ok(...) / notify.err(...) cho dễ đọc ở nơi gọi. */
notify.ok = (m) => notify(m, 'success');
notify.err = (m) => notify(m, 'error');
notify.info = (m) => notify(m, 'info');
