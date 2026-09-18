/**
 * CATALOG TÙY CHỈNH CẤP TÀI KHOẢN — nay lưu trên SERVER (trước đây localStorage).
 *
 * [Quyết định 2026-09-20] Người dùng chốt: dữ liệu phải theo TÀI KHOẢN, không phải theo máy.
 * Bản cũ lưu trong localStorage nên đổi máy/đổi trình duyệt là mất sạch tùy chỉnh — với người dùng
 * làm việc trên nhiều máy thì đó là mất dữ liệu thật.
 *
 * GIỮ NGUYÊN mô hình 3 phần (đã kiểm bằng scripts/check-local-catalog.mjs):
 *   custom : mục do user tự tạo (id cục bộ local-…)
 *   edits  : ghi đè lên một mục mặc định theo id
 *   hidden : mục mặc định bị user ẩn khỏi bản của mình
 * Nhờ giữ nguyên mô hình, toàn bộ logic ghép baseline ⊕ bản của user KHÔNG đổi.
 *
 * BA RÀNG BUỘC THIẾT KẾ (dễ vi phạm, đọc trước khi sửa):
 *
 *  1. Module này KHÔNG được import bất cứ thứ gì (kể cả vue). scripts/check-local-catalog.mjs nạp
 *     nó qua data-URL trong Node — mọi bare specifier sẽ làm script đó chết, kéo theo test
 *     UserCatalogTest::test_local_catalog_logic_passes_its_node_self_check đỏ.
 *
 *  2. Các hàm đọc/ghi phải ĐỒNG BỘ trên bộ nhớ. create() phải TRẢ VỀ id ngay (guard script dùng giá
 *     trị đó liền), và merge() phải đồng bộ để component không phải chờ mạng mỗi lần render.
 *     Vì vậy: SỬA BỘ NHỚ TRƯỚC, GỬI MẠNG SAU (ghi lạc hậu — debounce).
 *
 *  3. Khi CHƯA gọi load() thì KHÔNG được chạm tới mạng. Đây là thứ giữ cho guard script chạy thuần
 *     trong bộ nhớ (nó không gọi load) và giữ cho mọi thao tác trước khi nạp xong là vô hại.
 */

const VERSION = 1; // khoá localStorage ĐỜI CŨ — chỉ còn dùng để DI TRÚ một lần sang server.
const API = '/api/user-catalogs/';

/** Id user do blade nhúng vào phần tử gốc (data-user-id). */
export function currentUserId() {
  if (typeof document === 'undefined') return 'anon';
  const el = document.querySelector('[data-user-id]');
  return (el && el.getAttribute('data-user-id')) || 'anon';
}

/** Blade cũng cho biết user có phải admin — để hiện chế độ sửa bản dùng chung. */
export function isAdminUser() {
  if (typeof document === 'undefined') return false;
  const el = document.querySelector('[data-user-admin]');
  return ((el && el.getAttribute('data-user-admin')) || '0') === '1';
}

/**
 * Nơi nhận lỗi khi ghi lên server thất bại. Tách thành hook thay vì import trực tiếp useSettingsToast
 * (xem ràng buộc 1). App gọi setCatalogErrorHandler(notify.err) lúc khởi động.
 */
let onError = () => {};
export function setCatalogErrorHandler(fn) { onError = typeof fn === 'function' ? fn : () => {}; }

/** Danh sách name hợp lệ — phải khớp whitelist ở UserCatalogController phía server. */
export const CATALOG_NAMES = ['presets', 'stylist.types', 'stylist.questions'];

function blank() { return { custom: [], edits: {}, hidden: [] }; }

/** Chuẩn hoá dữ liệu bất kể nguồn (server trả về hay localStorage cũ) — không bao giờ ném lỗi. */
function normalize(raw) {
  const out = blank();
  if (!raw || typeof raw !== 'object') return out;
  if (Array.isArray(raw.custom)) out.custom = raw.custom.filter((c) => c && typeof c === 'object');
  // edits phải là MAP id => patch. PHP mã hoá map RỖNG thành [] (mảng JSON) chứ không phải {} vì
  // json_encode không phân biệt được mảng rỗng. Vì vậy phải loại mảng ở đây: nhận [] rồi dùng như map
  // sẽ tạo khoá 0/1 không tồn tại ⇒ ghi đè nhầm mục khác. Bỏ qua ⇒ giữ {} là đúng.
  if (raw.edits && typeof raw.edits === 'object' && !Array.isArray(raw.edits)) out.edits = raw.edits;
  if (Array.isArray(raw.hidden)) out.hidden = raw.hidden.map(String);
  return out;
}

const CSRF = () => {
  if (typeof document === 'undefined') return '';
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : '';
};
export function useLocalCatalog(name) {
  // Khoá localStorage ĐỜI CŨ. Giữ đúng định dạng cũ (có userId) vì đây là nguồn di trú.
  const key = 'fabrikai:' + name + ':v' + VERSION + ':u' + currentUserId();

  let state = blank();
  let loaded = false;   // đã nạp xong từ server chưa (chưa nạp ⇒ cấm chạm mạng)
  let inflight = null;  // Promise của lần load đang chạy — nhiều component gọi load vẫn chỉ 1 request
  let timer = null;

  function readAll() { return JSON.parse(JSON.stringify(state)); }

  /** Ghi bộ nhớ + hẹn giờ đẩy lên server. ĐỒNG BỘ (xem ràng buộc 2). */
  function writeAll(next) {
    state = normalize(next);
    scheduleSave();
  }

  function scheduleSave() {
    if (!loaded) return;   // ràng buộc 3
    if (timer) clearTimeout(timer);
    // Gộp nhiều thao tác liên tiếp (thêm rồi sửa ngay) thành MỘT request — tránh dội server
    // và tránh hai request ghi đè lẫn nhau khi người dùng bấm nhanh.
    timer = setTimeout(save, 350);
  }

  async function save() {
    timer = null;
    try {
      const r = await fetch(API + encodeURIComponent(name), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': CSRF() },
        body: JSON.stringify({ data: readAll() }),
      });
      if (!r.ok) throw new Error('HTTP ' + r.status);
    } catch (e) {
      // Ghi thất bại ⇒ bộ nhớ đang KHÁC server. Nạp lại cho hai bên khớp nhau, thay vì âm thầm lệch
      // rồi lần sau người dùng tưởng đã lưu mà thực ra chưa.
      onError('Không lưu được tùy chỉnh lên tài khoản (' + e.message + '). Đang tải lại dữ liệu.');
      loaded = false;
      await load();
    }
  }

  /** Đọc bản cũ trong localStorage (nếu có) để di trú một lần. */
  function readLegacy() {
    try {
      if (typeof localStorage === 'undefined') return null;
      const raw = localStorage.getItem(key);
      if (!raw) return null;
      const parsed = normalize(JSON.parse(raw));
      const has = parsed.custom.length || Object.keys(parsed.edits).length || parsed.hidden.length;
      return has ? parsed : null;
    } catch { return null; }   // JSON hỏng / chế độ riêng tư ⇒ coi như không có, KHÔNG được sập trang
  }

  function clearLegacy() { try { localStorage.removeItem(key); } catch { /* bỏ qua */ } }

  /**
   * Nạp tùy chỉnh của tài khoản từ server. Gọi một lần khi section mount.
   * Nếu server CHƯA có gì mà máy này còn bản cũ trong localStorage thì đẩy lên (di trú) rồi xoá bản cũ,
   * để người dùng không mất tùy chỉnh đã tạo trước đây.
   */
  async function load() {
    if (inflight) return inflight;
    inflight = (async () => {
      try {
        const r = await fetch(API + encodeURIComponent(name), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        state = normalize(d && d.data);
        loaded = true;

        const legacy = readLegacy();
        const serverEmpty = !state.custom.length && !Object.keys(state.edits).length && !state.hidden.length;
        if (legacy && serverEmpty) {
          state = legacy;
          await save();
          clearLegacy();
        }
      } catch (e) {
        // Không đọc được (mất mạng, 500, chưa đăng nhập): vẫn cho trang chạy bằng bản trong máy nếu có,
        // và ĐÁNH DẤU chưa nạp để không ghi đè server bằng dữ liệu rỗng.
        state = readLegacy() || blank();
        loaded = false;
        onError('Không tải được tùy chỉnh của tài khoản (' + e.message + '). Đang dùng bản trên máy này.');
      }
      return state;
    })();
    try { return await inflight; } finally { inflight = null; }
  }

  /**
   * Ghép baseline (toàn cục) với bản tùy chỉnh của user. ĐỒNG BỘ.
   *
   * THỨ TỰ (cố ý, đã kiểm bằng scripts/check-local-catalog.mjs): giữ NGUYÊN thứ tự baseline
   * (server đã sắp sẵn) rồi NỐI các mục user tự tạo vào cuối — trong nhóm đó sắp theo sort_order.
   * Không trộn cả hai lại theo sort_order vì stylist.types / stylist.questions không có trường này:
   * trộn sẽ khiến mọi mục cùng hạng 0 và bị đảo thứ tự ngoài ý muốn.
   */
  function merge(baseline) {
    const hidden = new Set(state.hidden.map(String));
    const out = [];
    for (const b of baseline || []) {
      const id = String(b.id);
      if (hidden.has(id)) continue;
      out.push(state.edits[id] ? { ...b, ...state.edits[id] } : { ...b });
    }
    const custom = state.custom.map((c) => ({ ...c }));
    custom.sort((a, b) => (Number(a.sort_order) || 0) - (Number(b.sort_order) || 0) || String(a.id).localeCompare(String(b.id)));
    return [...out, ...custom];
  }
  /** Tạo mục MỚI trong bản của user. TRẢ VỀ id ngay (đồng bộ) — mạng gửi sau. */
  function create(item) {
    const id = 'local-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
    state.custom.push({ ...item, id, _local: true });
    state = normalize(state);
    scheduleSave();
    return id;
  }

  /** Sửa mục của user, hoặc ghi đè lên mục mặc định nếu id không phải mục cục bộ. */
  function update(id, patch) {
    const sid = String(id);
    const i = state.custom.findIndex((c) => String(c.id) === sid);
    if (i >= 0) state.custom[i] = { ...state.custom[i], ...patch, id: state.custom[i].id };
    else state.edits[sid] = { ...(state.edits[sid] || {}), ...patch };
    scheduleSave();
  }

  /** Xoá mục của user; nếu là mục mặc định thì chỉ ẨN khỏi bản của user. */
  function remove(id) {
    const sid = String(id);
    const before = state.custom.length;
    state.custom = state.custom.filter((c) => String(c.id) !== sid);
    if (state.custom.length === before && !state.hidden.includes(sid)) state.hidden.push(sid);
    scheduleSave();
  }

  /** Xoá SẠCH tùy chỉnh của user ⇒ quay về đúng bản mặc định. */
  function reset() { state = blank(); scheduleSave(); }

  function hasOverrides() {
    return state.custom.length > 0 || Object.keys(state.edits).length > 0 || state.hidden.length > 0;
  }

  return { key, name, load, readAll, writeAll, merge, create, update, remove, reset, hasOverrides, isLoaded: () => loaded };
}
