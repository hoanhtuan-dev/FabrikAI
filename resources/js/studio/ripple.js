/**
 * GỢN NƯỚC Ở NÚT (Material ripple) — dùng cho TRANG Agent Studio.
 *
 * Vì sao cần JS: gợn phải mọc ra từ ĐÚNG ĐIỂM BẤM, mà CSS không biết được điểm đó. Phần hình
 * dáng và nhịp nằm ở app.css (.ripple-host · .ripple-ink) để thời lượng vẫn đọc token chuyển
 * động — nhờ vậy công tắc "giảm chuyển động" của hệ điều hành tắt được cả gợn này.
 *
 * Vì sao là DIRECTIVE chứ không phải component: gợn là HÀNH VI gắn thêm vào nút đã có
 * (.btn-brand · .tool-btn · .nav-step), không phải một loại nút mới. Dựng thêm component nút
 * sẽ thành bộ nút thứ hai — đúng thứ docs/DESIGN_SYSTEM.md §2 cấm.
 *
 * Dùng: <button class="btn-brand" v-ripple>…</button>
 */
const HOST = 'ripple-host';
const INK = 'ripple-ink';
/** Trần thời gian gỡ gợn — lưới an toàn nếu trình duyệt không bắn animationend. */
const SWEEP_MS = 900;

function spawn(el, clientX, clientY) {
  const rect = el.getBoundingClientRect();
  const size = Math.max(rect.width, rect.height) * 2;
  if (!size) return;
  // Bàn phím (Enter/Space) không có toạ độ ⇒ gợn mọc từ giữa nút, không phải từ góc (0,0).
  const cx = Number.isFinite(clientX) && clientX > 0 ? clientX : rect.left + rect.width / 2;
  const cy = Number.isFinite(clientY) && clientY > 0 ? clientY : rect.top + rect.height / 2;

  const ink = document.createElement('span');
  ink.className = INK;
  ink.setAttribute('aria-hidden', 'true');
  ink.style.width = size + 'px';
  ink.style.height = size + 'px';
  ink.style.left = cx - rect.left - size / 2 + 'px';
  ink.style.top = cy - rect.top - size / 2 + 'px';
  el.appendChild(ink);

  let done = false;
  const sweep = () => {
    if (done) return;
    done = true;
    clearTimeout(timer);
    ink.remove();
  };
  const timer = setTimeout(sweep, SWEEP_MS);
  ink.addEventListener('animationend', sweep, { once: true });
}

function onPointerDown(event) {
  // Chỉ gợn cho chuột/ngón tay/bút; bàn phím đi đường riêng bên dưới.
  if (event.button !== undefined && event.button !== 0) return;
  spawn(event.currentTarget, event.clientX, event.clientY);
}

function onKeyDown(event) {
  if (event.key !== 'Enter' && event.key !== ' ') return;
  spawn(event.currentTarget, null, null);
}

function bind(el) {
  el.classList.add(HOST);
  el.addEventListener('pointerdown', onPointerDown);
  el.addEventListener('keydown', onKeyDown);
}

function unbind(el) {
  el.classList.remove(HOST);
  el.removeEventListener('pointerdown', onPointerDown);
  el.removeEventListener('keydown', onKeyDown);
}

/** Directive Vue 3 — xem agent-studio.js (app.directive('ripple', ripple)). */
export const ripple = {
  mounted: bind,
  unmounted: unbind,
};
