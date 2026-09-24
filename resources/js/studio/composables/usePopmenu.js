/**
 * POP MENU — menu ngữ cảnh neo theo điểm chạm (shell 2026).
 *
 * Thay thế menu dạng quạt/radial của prototype v1–v3: quạt chồng lấn khi điểm chạm sát mép
 * màn hình. Danh sách dọc kiểu context-menu của iOS KHÔNG thể chồng lấn, mục nào cũng đủ
 * lớn để bấm, và tự LẬT HƯỚNG khi neo gần đáy.
 *
 * Kiến trúc: state module-level (singleton) + <PopMenu> mount MỘT lần ở gốc app. Bất kỳ
 * component nào cũng gọi được openPopmenu(...) mà không cần truyền ref.
 *
 *   openPopmenu({
 *     anchor: DOMRect | {left, top},          // vị trí neo (thường là rect của nút)
 *     items: [{ icon, label, desc?, url?, onSelect?, active? }],
 *     dir: 'up' | 'down' | 'auto',            // auto: lật khi neo ở nửa dưới màn hình
 *   })
 */
import { reactive } from 'vue';

const state = reactive({
  open: false,
  anchor: null,
  items: [],
  dir: 'auto',
});

export function usePopmenu() {
  function openPopmenu({ anchor, items, dir = 'auto' }) {
    state.anchor = anchor;
    state.items = items;
    state.dir = dir;
    state.open = true;
  }
  function closePopmenu() {
    state.open = false;
  }
  return { state, openPopmenu, closePopmenu };
}
