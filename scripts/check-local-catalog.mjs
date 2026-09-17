#!/usr/bin/env node
/**
 * [2026-09-17] Self-check cho LÕI LOGIC của bản tùy chỉnh cục bộ.
 *
 * Repo không có JS test runner (chỉ `vite build`), nên lớp ghép baseline ⊕ bản của user —
 * phần dễ sai nhất của tính năng `/presets` + `/stylist-data` — được kiểm ở đây bằng Node thuần:
 * nạp thẳng module nguồn qua data-URL (không cần bundler), stub `localStorage` + `document`.
 *
 * Chạy: node scripts/check-local-catalog.mjs   (exit != 0 nghĩa là HỎNG)
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'resources/js/studio/composables/useLocalCatalog.js'), 'utf8');

// ── stub môi trường trình duyệt ──────────────────────────────────────────
let attrs = {};
globalThis.document = { querySelector: () => ({ getAttribute: (k) => (k in attrs ? attrs[k] : null) }) };
const store = new Map();
globalThis.localStorage = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: (k) => store.delete(k),
};

const mod = await import('data:text/javascript;base64,' + Buffer.from(src).toString('base64'));
const { currentUserId, isAdminUser, useLocalCatalog } = mod;

let pass = 0;
const fails = [];
function check(name, cond, extra = '') {
  if (cond) { pass += 1; return; }
  fails.push(name + (extra ? ' — ' + extra : ''));
}
const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

attrs = {};
check('không có data-user-id ⇒ anon', currentUserId() === 'anon');
check('không có data-user-admin ⇒ không phải admin', isAdminUser() === false);
attrs = { 'data-user-id': '7', 'data-user-admin': '1' };
check('đọc đúng userId từ blade', currentUserId() === '7');
check('đọc đúng cờ admin', isAdminUser() === true);

const baseline = [
  { id: 1, name: 'A', sort_order: 1 },
  { id: 2, name: 'B', sort_order: 2 },
  { id: 3, name: 'C', sort_order: 3 },
];

const cat = useLocalCatalog('presets');
check('khoá lưu trữ kèm userId', cat.key.includes('u7'), cat.key);
check('chưa tùy chỉnh ⇒ merge = baseline', eq(cat.merge(baseline), baseline));
check('chưa tùy chỉnh ⇒ hasOverrides false', cat.hasOverrides() === false);

const newId = cat.create({ name: 'Của tôi', sort_order: 0 });
let m = cat.merge(baseline);
check('tạo mới ⇒ xuất hiện trong bản ghép', m.some((x) => x.id === newId && x.name === 'Của tôi'));
check('mục tự tạo được đánh dấu _local', m.find((x) => x.id === newId)._local === true);
check('mục tự tạo NỐI VÀO CUỐI, baseline giữ nguyên thứ tự', m[m.length - 1].id === newId);
check('baseline không bị đụng', m.filter((x) => typeof x.id === 'number').length === 3);
check('có tùy chỉnh ⇒ hasOverrides true', cat.hasOverrides() === true);

cat.update(newId, { name: 'Đổi tên' });
check('sửa mục tự tạo', cat.merge(baseline).find((x) => x.id === newId).name === 'Đổi tên');

cat.update(2, { name: 'B đã sửa' });
m = cat.merge(baseline);
check('ghi đè mục mặc định', m.find((x) => x.id === 2).name === 'B đã sửa');
check('ghi đè KHÔNG tạo thêm mục', m.filter((x) => x.id === 2).length === 1);

cat.remove(1);
check('xoá mục mặc định ⇒ ẩn khỏi bản của user', !cat.merge(baseline).some((x) => x.id === 1));
check('xoá mục mặc định KHÔNG xoá khỏi baseline gốc', baseline.length === 3);

cat.remove(newId);
check('xoá mục tự tạo ⇒ biến mất', !cat.merge(baseline).some((x) => x.id === newId));

check('chế độ admin: dùng chung một catalog khác khoá', useLocalCatalog('stylist.types').key !== cat.key);

const catUser8 = (() => { attrs = { 'data-user-id': '8' }; const c = useLocalCatalog('presets'); attrs = { 'data-user-id': '7' }; return c; })();
check('user khác ⇒ bản RIÊNG (không thấy tùy chỉnh của user 7)', eq(catUser8.merge(baseline), baseline));

cat.reset();
check('reset ⇒ quay về đúng baseline', eq(cat.merge(baseline), baseline));
check('reset ⇒ hasOverrides false', cat.hasOverrides() === false);

// JSON hỏng (chế độ riêng tư / ghi dở) KHÔNG được làm sập trang.
store.set(cat.key, '{khong-phai-json');
check('JSON hỏng ⇒ fallback baseline, không ném lỗi', eq(cat.merge(baseline), baseline));
store.delete(cat.key);

if (fails.length) {
  console.error('❌ check-local-catalog: ' + fails.length + ' lỗi');
  fails.forEach((f) => console.error('   · ' + f));
  process.exit(1);
}
console.log('✅ check-local-catalog: ' + pass + ' kiểm tra ĐẠT');
