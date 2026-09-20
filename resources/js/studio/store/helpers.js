// ═══════════════════════════════════════════════════════════════════════════════════════
// HELPER DÙNG CHUNG của studio store — TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24).
// Các symbol được export thêm (CSRF · readBoot · bootUser · bootProjectStatuses ·
// PLAN_ASSUMPTION_DEFAULTS) vì nay nhiều module miền cùng dùng; trong file gốc chúng là biến
// module-private của cùng một file.
// ═══════════════════════════════════════════════════════════════════════════════════════
import { reportClientError } from '../clientErrors.js';


export const CSRF = () => {
  // Standalone SPA: Laravel đặt cookie XSRF-TOKEN (đã mã hoá) sau request GET đầu tiên (/api/boot).
  // Gửi lại qua header X-XSRF-TOKEN — VerifyCsrfToken sẽ tự giải mã để khớp token phiên.
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

export function readBoot() {
  return (typeof window !== 'undefined' && window.__STUDIO_BOOT__) || null;
}
export function bootUser() {
  return (readBoot() && readBoot().user) || null;
}
export function bootProjectStatuses() {
  return (readBoot() && readBoot().project_statuses) || null;
}


/**
 * Lỗi dựng từ MỘT PHẢN HỒI MÁY CHỦ — GIỮ LẠI mã tra cứu.
 *
 * Vì sao có hàm này: các chỗ cũ viết `throw new Error(d.message || '…')` nên `d.error_code` (mã tra cứu
 * mà studio_fail vừa gửi) bị NÉM BỎ ngay tại đó — kết quả là phần lớn lỗi máy chủ hiện ra giao diện
 * KHÔNG có mã, dù log phía máy chủ có mã. Dùng hàm này ở mọi chỗ biến phản hồi lỗi thành Error.
 */
export function apiError(payload, fallback, res) {
  const msg = (payload && payload.message) || fallback || ('HTTP ' + ((res && res.status) || '?'));
  const err = new Error(msg);
  if (payload && payload.error_code) err.error_code = payload.error_code;
  if (res && res.status) err.status = res.status;
  return err;
}

/* ══════════════════════════════════════════════════════════════════════════════════════════
   CHẶN RÒ RỈ CHI TIẾT KỸ THUẬT RA GIAO DIỆN — luật đầy đủ ở docs/DESIGN_SYSTEM.md §6.

   Vì sao có khối này: mọi chỗ bắt lỗi trước đây đều làm `this.xxxError = e.message`, mà `e.message`
   là thông báo THÔ của backend (thường là chuỗi exception của nhà cung cấp AI). Người dùng thật đã
   nhìn thấy những câu như:
     · "Qwen vision: HTTP 429: {"error":{"message":"Your token-plan 1-week quota has been exhausted…"}}"
     · "Model trả về JSON không đọc được."
   Đó là thông tin của LẬP TRÌNH VIÊN: nó không nói người dùng phải làm gì, lại để lộ tên nhà cung
   cấp/model và tình trạng hạ tầng.

   Luật: giao diện chỉ nói NGƯỜI DÙNG cần biết (chuyện gì xảy ra + làm gì tiếp). Chi tiết kỹ thuật
   đi vào `console` (ở đây) và `storage/logs/laravel.log` (phía PHP) — nơi lập trình viên đọc.
   ══════════════════════════════════════════════════════════════════════════════════════════ */

/** Dấu hiệu một chuỗi là thông báo KỸ THUẬT, không phải câu nói với người dùng. */
const TECH_LEAK = new RegExp([
  'deepseek|qwen|dashscope|gemini|replicate|fal\\.ai|\\bveo\\b|\\bwan\\b|\\bflux\\b',
  '\\bprovider\\b|\\bmodel\\b|\\btransport\\b|\\bendpoint\\b',
  'HTTP\\s*\\d{3}|\\bJSON\\b|SQLSTATE|exception|stack trace',
  '\\/api\\/|\\/home\\/|\\/var\\/www|\\.php\\b',
  'API key|api_key|quota|rate limit|timeout|ECONN|ETIMEDOUT',
  '\\bundefined\\b|\\bnull\\b|\\bNaN\\b',
  // [Đợt 21 — 2026-09-23] Câu lỗi MẠNG/DOM do chính trình duyệt sinh cũng là chi tiết kỹ thuật: trước đây
  // người dùng đọc nguyên "Failed to fetch" (tiếng Anh, không nói phải làm gì). Nay câu đó bị thay bằng
  // câu hướng dẫn, còn bản gốc đi vào log kèm mã tra cứu (clientErrors.js).
  'failed to fetch|network ?error|network request failed|load failed|aborterror|the operation was aborted',
  'quotaexceeded|securityerror|invalidstateerror|notallowederror|indexsizeerror|encodingerror',
].join('|'), 'i');

/** Ghi chi tiết kỹ thuật ra console (chỉ lập trình viên thấy) kèm ngữ cảnh. */
function logTechnical(scope, detail) {
  try {
    // eslint-disable-next-line no-console
    console.warn('[studio:' + scope + '] chi tiết kỹ thuật (không hiển thị cho người dùng):', detail);
  } catch { /* console có thể bị chặn — không được làm hỏng luồng chính */ }
}

/**
 * Đổi một thông báo (thường là từ server/exception) thành câu NÓI VỚI NGƯỜI DÙNG.
 * · Câu sạch ⇒ giữ nguyên (nhờ vậy thông báo kiểm tra dữ liệu của Laravel vẫn tới được người dùng).
 * · Câu chứa dấu hiệu kỹ thuật ⇒ thay bằng `fallback`, và bản gốc được ghi ra console.
 */
export function safeMessage(text, fallback = '') {
  const raw = String(text == null ? '' : text).trim();
  if (!raw) return fallback;
  if (TECH_LEAK.test(raw)) { logTechnical('leak-blocked', raw); return fallback; }
  return raw;
}

/** Câu đã mang mã tra cứu rồi (một lớp trước đã gắn) — không gắn thêm mã thứ hai. */
const LOOKUP_SUFFIX = /\(mã tra cứu: L-[A-Z0-9]{4}\)/;

/**
 * Bắt lỗi ở mọi action: trả câu hướng người dùng, KHÔNG bao giờ trả `e.message` thô.
 *
 * Mã tra cứu (§6.5): có HAI nguồn và hàm này chọn đúng nguồn.
 *   · Lỗi MÁY CHỦ ⇒ dùng `error_code` server gửi kèm (studio_fail → mã đã có trong laravel.log).
 *   · Lỗi sinh NGAY TRONG TRÌNH DUYỆT (mất mạng · fetch hỏng · canvas/Blob · exception không ai bắt)
 *     ⇒ KHÔNG có mã nào từ server, nên ta tự sinh mã rồi gửi chi tiết về /api/client-errors để máy chủ
 *     ghi log (clientErrors.js). Trước 2026-09-23 nhóm lỗi này hiện ra mà không có mã nào để tra.
 *
 * @param {*} e            lỗi bắt được
 * @param {string} fallback câu thay thế khi câu gốc là chi tiết kỹ thuật
 * @param {{prefix?: string, context?: string}} opts  tiền tố ngữ cảnh ('Không thu hồi được') + ngữ cảnh log
 */
export function userFacingError(e, fallback, opts) {
  const options = opts || {};
  const raw = (e && (e.message || e.error || e.statusText)) || '';
  const clean = safeMessage(raw, '');
  const prefix = options.prefix ? String(options.prefix) + ': ' : '';
  const body = clean ? prefix + clean : (fallback || '');
  if (!clean && raw) logTechnical('error-blocked', raw);
  if (LOOKUP_SUFFIX.test(body)) return body;
  const serverCode = (e && (e.error_code || (e.response && e.response.error_code))) || '';
  // Ngữ cảnh ưu tiên: nơi gọi truyền vào → ngữ cảnh của lời gọi API (endpoint + mã) → mặc định.
  const context = options.context || (e && e.api_context) || 'userFacingError';
  const code = serverCode || reportClientError(e || new Error(body || 'unknown'), context, { userMessage: body, silent: true });
  return code && body ? body + ' (mã tra cứu: ' + code + ')' : body;
}

/**
 * Đơn giá/định mức MẶC ĐỊNH của kế hoạch sản xuất — PHẢI khớp CollectionPlanService::DEFAULTS.
 * Chủ xưởng sửa được toàn bộ; đây chỉ là điểm khởi đầu để họ thấy ngay con số mà sửa.
 */
export const PLAN_ASSUMPTION_DEFAULTS = {
  units_per_sku: 30,
  fabric_price_per_m: 85000,
  fabric_width_cm: 150,
  fabric_safety_pct: 5,
  wastage_pct: 12,
  trim_cost: 25000,
  sewing_cost: 65000,
  packaging_cost: 8000,
  defect_pct: 3,
  channel_discount_pct: 18,
  target_margin_pct: 55,
  daily_capacity: 25,
  fixed_cost: 0,
};
