import { onBeforeUnmount, watch } from 'vue';

/**
 * [Đợt 0.7 — 2026-09-17] GIỮ FOCUS TRONG HỘP THOẠI (focus trap).
 *
 * Lỗi gốc: các lớp phủ đã có `role="dialog"` + `aria-modal="true"` (vá §5.2/§5.3) nhưng KHÔNG
 * lớp nào giữ bàn phím ở lại. Người dùng chỉ dùng bàn phím bấm Tab vài lần là focus ĐI XUYÊN RA
 * SAU lớp phủ — vào đúng những nút mờ không nhìn thấy — rồi Enter kích hoạt nhầm hành động ẩn.
 * Đây là lỗi a11y nghiêm trọng nhất còn lại của studio (xem STUDIO_REVIEW_PLAN.md Đợt 0.7).
 *
 * Bất biến của composable này:
 *   1. Khi mở: ghi nhớ phần tử đang có focus, rồi đưa focus vào phần tử đầu tiên bên trong hộp thoại.
 *   2. Tab ở phần tử CUỐI ⇒ quay về phần tử ĐẦU; Shift+Tab ở phần tử ĐẦU ⇒ nhảy tới phần tử CUỐI.
 *   3. Khi đóng: TRẢ focus về đúng phần tử đã ghi nhớ (nếu nó còn trong DOM) — nếu không, người dùng
 *      bàn phím bị ném về đầu trang và mất vị trí vừa thao tác.
 *   4. Gỡ listener khi unmount: nếu không, mỗi lần mở/đóng lại chồng thêm một listener.
 *
 * @param {import('vue').Ref<HTMLElement|null>|(() => HTMLElement|null)} container Nguồn lấy phần tử hộp thoại.
 * @param {{ active?: import('vue').Ref<boolean>|null }} [opts] Nếu truyền `active`, trap tự bật/tắt theo ref đó.
 */
const FOCUSABLE = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

/** Phần tử có thể nhận focus VÀ đang thực sự nhìn thấy được (bỏ nút bị ẩn bằng CSS). */
function focusableIn(container) {
  if (!container || typeof container.querySelectorAll !== 'function') return [];
  return Array.from(container.querySelectorAll(FOCUSABLE)).filter((el) => {
    if (el.hasAttribute('disabled')) return false;
    if (el.getAttribute('aria-hidden') === 'true') return false;
    // offsetParent === null ⇒ đang bị ẩn (display:none / trong nhánh v-if đã tắt).
    return el.offsetParent !== null;
  });
}

export function useFocusTrap(container, opts = {}) {
  let lastFocused = null;
  let onKeydown = null;

  const resolve = () => {
    const c = typeof container === 'function' ? container() : container;
    return (c && c.value !== undefined) ? c.value : c;
  };

  function deactivate() {
    if (onKeydown) {
      window.removeEventListener('keydown', onKeydown, true);
      onKeydown = null;
    }
    // Chỉ trả focus nếu phần tử cũ còn trong DOM — tránh focus vào node đã bị gỡ.
    if (lastFocused && typeof lastFocused.focus === 'function' && document.contains(lastFocused)) {
      lastFocused.focus();
    }
    lastFocused = null;
  }

  function activate() {
    const el = resolve();
    if (!el) return;

    if (!onKeydown) {
      lastFocused = document.activeElement;

      onKeydown = (e) => {
        if (e.key !== 'Tab') return;
        const box = resolve();
        if (!box) return;
        const items = focusableIn(box);
        if (!items.length) {
          // Không có gì để focus bên trong: giữ Tab ở lại hộp thoại thay vì để nó thoát ra ngoài.
          e.preventDefault();
          return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        const current = document.activeElement;

        if (e.shiftKey) {
          if (current === first || !box.contains(current)) {
            e.preventDefault();
            last.focus();
          }
        } else if (current === last || !box.contains(current)) {
          e.preventDefault();
          first.focus();
        }
      };

      window.addEventListener('keydown', onKeydown, true);
    }

    // Đưa focus vào hộp thoại (phần tử đầu tiên; nếu chưa có thì chính hộp thoại).
    const items = focusableIn(el);
    (items[0] || el).focus?.();
  }

  if (opts.active) {
    watch(opts.active, (v) => { if (v) activate(); else deactivate(); });
  }
  onBeforeUnmount(deactivate);

  return { activate, deactivate, focusableIn: () => focusableIn(resolve()) };
}
