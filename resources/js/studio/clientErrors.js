/**
 * MÃ TRA CỨU LỖI cho lỗi phát sinh CHỈ Ở TRÌNH DUYỆT (2026-09-23) — món nợ cuối của docs/DESIGN_SYSTEM.md §6.5.
 *
 * Vì sao có file này: mã tra cứu L-XXXX trước đây chỉ có ở lỗi do MÁY CHỦ sinh (studio_error_code trong
 * app/Support/helpers.php). Nhưng phần lớn câu lỗi người dùng thật nhìn thấy lại sinh NGAY TRONG TRÌNH DUYỆT:
 * fetch hỏng mạng, ảnh không giải mã được, canvas không xuất được blob, hết dung lượng localStorage, hoặc
 * một exception không ai bắt. Những lỗi đó trước đây KHÔNG có mã nào ⇒ hỗ trợ phải hỏi khách "lỗi lúc mấy
 * giờ, tài khoản nào" rồi tự dò log — mà log máy chủ thì không hề có dấu vết của chúng.
 *
 * Cách làm: sinh mã Ở CLIENT theo CÙNG định dạng và CÙNG bảng chữ với PHP, rồi GỬI KÈM chi tiết kỹ thuật về
 * POST /api/client-errors để máy chủ ghi vào storage/logs/laravel.log. Nhờ vậy mã khách đọc cho tổng đài
 * tra được y như mã của lỗi máy chủ.
 *
 * Nguyên tắc bất di bất dịch: KHÔNG bao giờ để chi tiết kỹ thuật ra giao diện (§6). Câu hiển thị vẫn là
 * câu người dùng hiểu; chỉ có MÃ được thêm vào cuối.
 */

/**
 * Bảng chữ PHẢI giống hệt studio_error_code() ở app/Support/helpers.php — test
 * ClientErrorReportTest::test_js_alphabet_matches_php khẳng định điều này, nên đổi một bên mà quên bên kia
 * là test ĐỎ. Cố ý bỏ 0/O và 1/I vì khách đọc mã qua điện thoại.
 */
export const CLIENT_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

const ENDPOINT = '/api/client-errors';
const QUEUE_KEY = 'fabrikai.clientErrors';
/** Trần số lần GỬI mỗi lần tải trang: lỗi lặp không được biến thành bão request + bão log. */
const MAX_POSTS = 12;
/** Trần số chữ ký lỗi khác nhau trong một lần tải trang (xem reportClientError). */
const MAX_SIGNATURES = 40;
/** Trần hàng đợi khi máy chủ không nhận được (mất mạng) — bản CŨ NHẤT bị bỏ khi đầy. */
const QUEUE_MAX = 30;
const QUEUE_FLUSH_MAX = 10;

const GENERIC_MESSAGE = 'Có lỗi xảy ra ngay trong trình duyệt. Hãy tải lại trang (Ctrl+Shift+R) và thử lại.';

/** Chữ ký lỗi → bản ghi. Lỗi TRÙNG chỉ tính thêm `count`, không gửi lại (một dòng log cho một lỗi). */
const seen = new Map();
let posts = 0;
const listeners = [];
/** Sự kiện xảy ra TRƯỚC khi giao diện kịp đăng ký người nghe — giữ lại để không mất. */
const pending = [];

function rawText(err) {
  if (err == null) return '';
  if (typeof err === 'string') return err;
  const parts = [err.name, err.message].filter(Boolean);
  const head = parts.join(': ').trim();
  // Dòng đầu của stack đủ để hỗ trợ biết lỗi nổ ở đâu; KHÔNG hiển thị cho người dùng.
  const frame = typeof err.stack === 'string' ? err.stack.split('\n')[1] : '';
  return (head + (frame ? ' | ' + frame.trim() : '')).slice(0, 500);
}

function csrfToken() {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
}

function pageUrl() {
  try { return String(window.location.pathname + window.location.search).slice(0, 300); } catch { return ''; }
}

/** Gửi một bản ghi lên máy chủ. Trả true khi máy chủ ĐÃ nhận (kể cả khi nó bỏ qua vì trùng). */
async function send(record) {
  if (typeof fetch !== 'function') return false;
  try {
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
      credentials: 'same-origin',
      keepalive: true,
      body: JSON.stringify(record),
    });
    return res.ok;
  } catch {
    return false;
  }
}

/** Hàng đợi cục bộ: giữ những bản ghi chưa tới được máy chủ (mất mạng) để lần sau gửi bù. */
function readQueue() {
  try {
    const raw = window.localStorage.getItem(QUEUE_KEY);
    const list = raw ? JSON.parse(raw) : [];
    return Array.isArray(list) ? list : [];
  } catch { return []; }
}

function writeQueue(list) {
  try { window.localStorage.setItem(QUEUE_KEY, JSON.stringify(list.slice(-QUEUE_MAX))); } catch { /* chế độ riêng tư */ }
}

function enqueue(record) {
  try {
    const list = readQueue();
    if (list.some((r) => r && r.code === record.code)) return;
    list.push(record);
    // Hàng đợi đầy ⇒ bỏ bản CŨ NHẤT. Giới hạn đã biết: mã của bản bị bỏ có thể không bao giờ tới log
    // (chỉ xảy ra khi trình duyệt hỏng liên tục mà không lần nào gửi được).
    writeQueue(list);
  } catch { /* không được làm hỏng luồng chính */ }
}

/** Gửi bù hàng đợi (best-effort). Gọi lúc khởi động và sau mỗi lần gửi thành công. */
export function flushClientErrorQueue() {
  if (typeof window === 'undefined') return;
  const list = readQueue();
  if (!list.length) return;
  const keep = [];
  list.slice(0, QUEUE_FLUSH_MAX).forEach((record) => {
    send(record).then((ok) => { if (!ok) keep.push(record); });
  });
  writeQueue(keep.concat(list.slice(QUEUE_FLUSH_MAX)));
}

/** Sinh mã L-XXXX từ một hạt giống — CÙNG công thức tinh thần với PHP (băm rồi lấy 4 ký tự). */
export function newClientCode(seed) {
  const input = String(seed == null ? '' : seed) + '|' + Date.now() + '|' + Math.random();
  let h1 = 0x811c9dc5;
  let h2 = 0x1000193;
  for (let i = 0; i < input.length; i++) {
    const c = input.charCodeAt(i);
    h1 = (h1 ^ c) >>> 0; h1 = Math.imul(h1, 16777619) >>> 0;
    h2 = (h2 + c * (i + 7)) >>> 0; h2 = Math.imul(h2, 2246822519) >>> 0;
  }
  let code = '';
  for (let i = 0; i < 4; i++) {
    const n = (i % 2 === 0 ? h1 : h2) >>> (i * 4);
    code += CLIENT_CODE_ALPHABET[n % CLIENT_CODE_ALPHABET.length];
  }
  return 'L-' + code;
}

function emit(record) {
  const detail = {
    code: record.code,
    message: record.userMessage || GENERIC_MESSAGE,
    context: record.context,
    count: record.count,
  };
  if (!listeners.length) {
    pending.push(detail);
    while (pending.length > 5) pending.shift();
    return;
  }
  listeners.forEach((cb) => { try { cb(detail); } catch { /* người nghe hỏng không được làm hỏng app */ } });
}

/**
 * GHI NHẬN một lỗi phía trình duyệt và trả MÃ TRA CỨU để hiển thị cho người dùng.
 *
 * @param {*} err       lỗi bắt được (Error, string, hay bất kỳ thứ gì bị ném ra)
 * @param {string} context  ngữ cảnh ngắn để hỗ trợ đọc log ('tao-anh', 'unhandledrejection'…)
 * @param {{userMessage?: string, silent?: boolean}} opts  câu NGƯỜI DÙNG đọc (chi tiết kỹ thuật không bao giờ ra
 *        giao diện) · `silent: true` khi CHÍNH NƠI GỌI đã hiển thị lỗi rồi (userFacingError → failToast):
 *        không bắn thêm sự kiện cho ô thông báo, nếu không người dùng nhận HAI thẻ cho cùng một lỗi.
 * @returns {string} mã L-XXXX, hoặc '' khi đã chạm trần (khi đó KHÔNG hiển thị mã, vì mã phải tra được)
 */
export function reportClientError(err, context, opts) {
  try {
    const options = opts || {};
    const ctx = String(context == null ? '' : context).slice(0, 120);
    const raw = rawText(err);
    const key = ctx + '|' + (err && err.name ? err.name : '') + '|' + raw.slice(0, 200);
    const hit = seen.get(key);
    if (hit) { hit.count += 1; return hit.code; }
    if (seen.size >= MAX_SIGNATURES) return '';

    const record = {
      code: newClientCode(key),
      message: raw,
      context: ctx,
      userMessage: String(options.userMessage == null ? '' : options.userMessage).slice(0, 200),
      page: pageUrl(),
      at: new Date().toISOString(),
    };
    record.count = 1;
    seen.set(key, record);
    // Chỉ báo cho ô thông báo khi nơi gọi CHƯA hiển thị lỗi (lỗi toàn cục). Nơi gọi đã hiện rồi thì
    // bắn thêm ở đây sẽ thành hai thẻ cho cùng một lỗi.
    if (!options.silent) emit(record);

    if (posts < MAX_POSTS) {
      posts += 1;
      send(record).then((ok) => { if (ok) flushClientErrorQueue(); else enqueue(record); });
    } else {
      enqueue(record);
    }
    return record.code;
  } catch {
    return '';
  }
}

/** Nghe mọi lỗi trình duyệt để hiển thị (kèm mã). Trả hàm huỷ đăng ký. */
export function onClientError(cb) {
  if (typeof cb !== 'function') return () => {};
  listeners.push(cb);
  if (listeners.length === 1 && pending.length) {
    const flush = pending.splice(0, pending.length);
    flush.forEach((detail) => { try { cb(detail); } catch { /* bỏ qua */ } });
  }
  return () => { const i = listeners.indexOf(cb); if (i >= 0) listeners.splice(i, 1); };
}

/** Ô hiển thị ĐANG dùng của shell hiện tại (mỗi shell chỉ có một root ⇒ sink mới nhất thay sink cũ). */
let sink = null;
let sinkBound = false;

/**
 * Nối bộ báo lỗi trình duyệt vào hàm hiển thị thông báo sẵn có của shell (store.toast · notify · flash).
 *
 * Vì sao "sink mới nhất thắng" thay vì đăng ký nhiều người nghe: root component có thể được mount lại
 * (đổi route trong SPA) — đăng ký theo vòng đời component thì người nghe cũ sẽ chồng lên và người dùng
 * nhận cùng một thông báo nhiều lần. Ở đây chỉ có MỘT người nghe nội bộ, luôn trỏ vào sink hiện hành.
 *
 * Mỗi lỗi chỉ hiện MỘT lần dù lặp lại (detail.count > 1) — tránh vòng lặp lỗi làm ngập màn hình.
 */
export function toastClientErrors(notifyFn) {
  if (typeof notifyFn !== 'function') return () => {};
  sink = notifyFn;
  if (!sinkBound) {
    sinkBound = true;
    onClientError((detail) => {
      if (!sink || !detail || detail.count > 1) return;
      try { sink(detail.message + ' (mã tra cứu: ' + detail.code + ')'); } catch { /* bỏ qua */ }
    });
  }
  return () => { if (sink === notifyFn) sink = null; };
}

/**
 * Bắt lỗi TOÀN CỤC: exception không ai bắt và promise bị từ chối mà không có .catch.
 *
 * Không có hai đường này thì lỗi nổ ra ngoài mọi khối try/catch sẽ chỉ nằm trong console của khách —
 * không mã, không log, không cách nào chẩn đoán.
 */
export function installClientErrorReporters() {
  if (typeof window === 'undefined' || window.__fabrikaiClientErrors) return;
  window.__fabrikaiClientErrors = true;
  window.addEventListener('error', (ev) => {
    const err = (ev && ev.error) || new Error((ev && ev.message) || 'Lỗi không rõ trong trình duyệt');
    reportClientError(err, 'window.onerror', { userMessage: GENERIC_MESSAGE });
  });
  window.addEventListener('unhandledrejection', (ev) => {
    const reason = ev && ev.reason;
    const err = reason instanceof Error ? reason : new Error(String((reason && reason.message) || reason || 'Promise bị từ chối'));
    reportClientError(err, 'unhandledrejection', { userMessage: GENERIC_MESSAGE });
  });
  flushClientErrorQueue();
}

export const CLIENT_ERROR_GENERIC_MESSAGE = GENERIC_MESSAGE;
