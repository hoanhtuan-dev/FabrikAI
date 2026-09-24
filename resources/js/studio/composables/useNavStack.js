/**
 * NAV STACK — ngăn xếp điều hướng cho các lớp phủ LỒNG CẤP (shell 2026).
 *
 * Mô hình Review → Options → Action: mỗi cấp là một mục trong stack; nút ← lùi một cấp,
 * ✕ đóng hết. QUAN TRỌNG: tích hợp nút BACK CỦA MÁY (Android gesture/back) qua History API —
 * mở cấp đầu thì pushState một entry; người dùng bấm back của máy là LÙI CẤP thay vì rời trang.
 *
 * Singleton module-level: một app chỉ có một chuỗi lớp phủ tại một thời điểm.
 */
import { reactive } from 'vue';

const state = reactive({ stack: [] });
let pushed = 0;   // đã push bao nhiêu history entry cho chuỗi hiện tại (tối đa 1)
let wired = false;

function wire() {
  if (wired || typeof window === 'undefined') return;
  wired = true;
  window.addEventListener('popstate', () => {
    if (!state.stack.length) return;
    state.stack.pop();                 // back của máy = lùi một cấp
    pushed = 0;
    // Còn cấp thì gài lại một entry để lần back sau vẫn chặn được trong sheet.
    if (state.stack.length) { history.pushState({ dshNav: true }, ''); pushed = 1; }
  });
}

export function useNavStack() {
  wire();

  /** Mở cấp đầu của một chuỗi lớp phủ (vd: sheet Options). */
  function open(level) {
    state.stack = [level];
    if (!pushed) { history.pushState({ dshNav: true }, ''); pushed = 1; }
  }
  /** Đi sâu một cấp (vd: Options → Action "Biến thể"). */
  function push(level) { state.stack.push(level); }
  /** Lùi một cấp qua nút ← trong app (không đụng history: entry gài sẵn vẫn còn đúng). */
  function pop() { state.stack.pop(); }
  /** Đóng hết qua nút ✕: nuốt entry đã gài bằng history.back() (popstate sẽ dọn stack rỗng). */
  function closeAll() {
    if (!state.stack.length) return;
    const hadEntry = pushed > 0;
    state.stack = [];
    pushed = 0;
    if (hadEntry) history.back();
  }

  return { state, open, push, pop, closeAll };
}
