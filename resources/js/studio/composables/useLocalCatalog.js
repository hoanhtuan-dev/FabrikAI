/**
 * [Quyết định 2026-09-17] CATALOG TÙY CHỈNH CẤP USER — lưu CỤC BỘ trên máy khách.
 *
 * Trước đây `/presets` và `/stylist-data` sửa thẳng bảng TOÀN CỤC (`presets` · `stylist_presets`),
 * mà ghi/xoá toàn cục chỉ admin làm được ⇒ người dùng thường không tùy chỉnh được gì. Nay mỗi
 * user có BẢN RIÊNG trong localStorage; dữ liệu toàn cục chỉ còn là GIÁ TRỊ MẶC ĐỊNH (baseline).
 *
 * Mô hình 3 phần — đủ để thêm/sửa/xoá mà không mất dữ liệu và không cần server:
 *   custom : mục do user tự tạo (id cục bộ `local-…`)
 *   edits  : ghi đè lên một mục mặc định theo id
 *   hidden : mục mặc định bị user ẩn khỏi bản của mình
 *
 * Khoá lưu trữ có userId ⇒ hai người dùng chung một trình duyệt KHÔNG thấy bản của nhau.
 */

const VERSION = 1;

/** Id user do blade nhúng vào phần tử gốc (`data-user-id`). */
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

export function useLocalCatalog(name) {
  const key = 'fabrikai:' + name + ':v' + VERSION + ':u' + currentUserId();

  const empty = () => ({ custom: [], edits: {}, hidden: [] });

  function readAll() {
    try {
      const raw = typeof localStorage !== 'undefined' ? localStorage.getItem(key) : null;
      const p = raw ? JSON.parse(raw) : null;
      if (!p || typeof p !== 'object') return empty();
      return {
        custom: Array.isArray(p.custom) ? p.custom : [],
        edits: p.edits && typeof p.edits === 'object' ? p.edits : {},
        hidden: Array.isArray(p.hidden) ? p.hidden : [],
      };
    } catch {
      // Chế độ riêng tư / JSON hỏng ⇒ coi như chưa tùy chỉnh, KHÔNG được làm hỏng trang.
      return empty();
    }
  }

  function writeAll(next) {
    try { localStorage.setItem(key, JSON.stringify(next)); } catch { /* hết quota: bỏ qua */ }
  }

  /**
   * Ghép baseline (toàn cục) với bản tùy chỉnh của user.
   *
   * THỨ TỰ (cố ý, đã kiểm bằng scripts/check-local-catalog.mjs): giữ NGUYÊN thứ tự baseline
   * (server đã sắp sẵn) rồi NỐI các mục user tự tạo vào cuối — trong nhóm đó sắp theo `sort_order`.
   * Không trộn cả hai lại theo sort_order vì `stylist.types` / `stylist.questions` không có
   * trường này: trộn sẽ khiến mọi mục cùng hạng 0 và bị đảo thứ tự ngoài ý muốn.
   */
  function merge(baseline) {
    const s = readAll();
    const hidden = new Set(s.hidden.map(String));
    const out = [];
    for (const b of baseline || []) {
      const id = String(b.id);
      if (hidden.has(id)) continue;
      out.push(s.edits[id] ? { ...b, ...s.edits[id] } : { ...b });
    }
    const custom = s.custom.map((c) => ({ ...c }));
    custom.sort((a, b) => (Number(a.sort_order) || 0) - (Number(b.sort_order) || 0) || String(a.id).localeCompare(String(b.id)));
    return [...out, ...custom];
  }

  /** Tạo mục MỚI trong bản của user; trả về id cục bộ. */
  function create(item) {
    const s = readAll();
    const id = 'local-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
    s.custom.push({ ...item, id, _local: true });
    writeAll(s);
    return id;
  }

  /** Sửa mục của user, hoặc ghi đè lên mục mặc định nếu id không phải mục cục bộ. */
  function update(id, patch) {
    const s = readAll();
    const sid = String(id);
    const i = s.custom.findIndex((c) => String(c.id) === sid);
    if (i >= 0) s.custom[i] = { ...s.custom[i], ...patch, id: s.custom[i].id };
    else s.edits[sid] = { ...(s.edits[sid] || {}), ...patch };
    writeAll(s);
  }

  /** Xoá mục của user; nếu là mục mặc định thì chỉ ẨN khỏi bản của user. */
  function remove(id) {
    const s = readAll();
    const sid = String(id);
    const before = s.custom.length;
    s.custom = s.custom.filter((c) => String(c.id) !== sid);
    if (s.custom.length === before && !s.hidden.includes(sid)) s.hidden.push(sid);
    writeAll(s);
  }

  /** Xoá SẠCH tùy chỉnh của user ⇒ quay về đúng bản mặc định. */
  function reset() { writeAll(empty()); }

  function hasOverrides() {
    const s = readAll();
    return s.custom.length > 0 || Object.keys(s.edits).length > 0 || s.hidden.length > 0;
  }

  return { key, readAll, writeAll, merge, create, update, remove, reset, hasOverrides };
}
