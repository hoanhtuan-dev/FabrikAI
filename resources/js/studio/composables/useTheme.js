import { ref } from 'vue';

/**
 * THEME TOÀN CỤC — cầu nối Vue ↔ bộ điều khiển theme (2026-09-23).
 *
 * Bộ điều khiển thật nằm ở resources/views/partials/theme.blade.php và đã chạy TRƯỚC khi
 * trang vẽ (đó là lý do nó không nằm trong bundle: chờ bundle là bị nháy màu). Ở đây chỉ là
 * lớp mỏng để component Vue đọc/ghi cùng một nguồn sự thật — không có biến theme thứ hai,
 * không có khoá localStorage thứ hai.
 *
 * Vì sao dùng ref ở PHẠM VI MODULE (không phải trong từng component): đổi theme ở thanh trạng
 * thái Studio thì mọi chỗ khác đang hiển thị trạng thái đó cũng phải đổi theo.
 */

const bridge = () => (typeof window !== 'undefined' ? window.FabrikAITheme : null);

export const THEME_OPTIONS = [
  { id: 'light', label: 'Sáng', icon: 'sun', desc: 'Nền sáng, chữ đậm — hợp khi làm việc ở nơi nhiều ánh sáng.' },
  { id: 'dark', label: 'Tối', icon: 'moon', desc: 'Giao diện gốc của FabrikAI, hợp khi làm việc lâu trong phòng tối.' },
  { id: 'system', label: 'Theo hệ điều hành', icon: 'monitor', desc: 'Tự đổi theo cài đặt Sáng/Tối của máy — đổi bên máy là app đổi theo.' },
];

export const themePref = ref(bridge() ? bridge().pref : 'dark');
export const themeResolved = ref(bridge() ? bridge().resolved : 'dark');
/** Mức cỡ chữ toàn cục (phần trăm) — cùng bộ điều khiển, cùng kho lưu theo tài khoản. */
export const fontScale = ref(bridge() ? bridge().fontScale : 100);

export const FONT_OPTIONS = [
  { id: 90, label: 'Nhỏ gọn', desc: 'Nhiều nội dung trên một màn hình — hợp khi màn hình nhỏ.' },
  { id: 100, label: 'Vừa', desc: 'Mặc định của FabrikAI — đã nhích to hơn bản trước một bậc.' },
  { id: 115, label: 'Lớn', desc: 'Chữ rõ hơn cho màn hình lớn hoặc khi đọc lâu.' },
  { id: 130, label: 'Rất lớn', desc: 'Dễ đọc nhất; bố cục sẽ thoáng hơn, ít nội dung hơn mỗi màn hình.' },
];

let wired = false;

/** Nối vào bộ điều khiển MỘT lần cho cả app (đổi từ nơi khác cũng cập nhật ref này). */
function wire() {
  if (wired) return;
  wired = true;
  const api = bridge();
  if (!api || typeof api.onChange !== 'function') return;
  api.onChange((state) => {
    themePref.value = state.pref;
    themeResolved.value = state.resolved;
    fontScale.value = state.fontScale;
  });
}

/**
 * Đổi theme. Trả về { saved, error } — giao diện PHẢI nói rõ khi chưa lưu được lên tài khoản,
 * thay vì im lặng coi như xong (bài học "báo thành công mà không ghi gì",
 * docs/DESIGN_SYSTEM.md §14 luật 11).
 */
export async function setTheme(pref) {
  wire();
  const api = bridge();
  if (!api || typeof api.set !== 'function') {
    return { saved: false, error: 'Không đổi được giao diện trên trang này.' };
  }
  const res = await api.set(pref);
  themePref.value = api.pref;
  themeResolved.value = api.resolved;
  return res;
}

/** Đổi cỡ chữ toàn cục. Trả { saved, error } y như setTheme. */
export async function setFontScale(pct) {
  wire();
  const api = bridge();
  if (!api || typeof api.setFontScale !== 'function') {
    return { saved: false, error: 'Không đổi được cỡ chữ trên trang này.' };
  }
  const res = await api.setFontScale(pct);
  fontScale.value = api.fontScale;
  return res;
}

export function useTheme() {
  wire();
  return { pref: themePref, resolved: themeResolved, fontScale, options: THEME_OPTIONS, setTheme, setFontScale };
}
