#!/usr/bin/env node
/**
 * Self-check cho STORE PHIÊN DÙNG CHUNG — resources/js/studio/store/session.js.
 *
 * [2026-09-26 · đợt 25] Repo không có JS test runner (chỉ `vite build`), nên phần dễ sai nhất của
 * việc hợp nhất ba khu — luật "sửa người khác thì KHÔNG được đụng vào danh tính của phiên này" — được
 * kiểm ở đây bằng Node thuần, chạy cả trong CI lẫn tay:
 *     node scripts/check-session-store.mjs        (exit != 0 nghĩa là HỎNG)
 * Thiếu pinia/vue ⇒ script tự báo HỎNG (không im lặng bỏ qua).
 */
import { createPinia, setActivePinia } from 'pinia';
import { useSessionStore } from '../resources/js/studio/store/session.js';

const failed = [];
const check = (name, ok) => {
  console.log((ok ? 'ok   ' : 'FAIL ') + name);
  if (!ok) failed.push(name);
};

const ME = {
  id: 7, name: 'An', email: 'an@fabrikai.shop', role: 'super_admin', role_label: 'Owner',
  credits_balance: 1200, is_admin: true, is_super_admin: true,
};

setActivePinia(createPinia());
const s = useSessionStore();

check('chưa có gì thì không có danh tính', s.me === null && s.name === '' && s.initial === '?');

s.hydrate(ME);
check('hydrate() nhận danh tính máy chủ nhúng sẵn (không request)', s.userId === 7 && s.name === 'An' && s.source === 'server');
check('getter suy ra quyền, nhãn vai trò và chữ cái đầu', s.isOwner && s.isSuper && s.roleLabel === 'Owner' && s.initial === 'A');

check('applyUser() BỎ QUA người khác', s.applyUser({ id: 9, name: 'Bình' }) === false && s.name === 'An');
check('applyUser() ÁP cho chính mình', s.applyUser({ id: 7, name: 'An Mới' }) === true && s.name === 'An Mới');
check('applyUser() gộp một phần — giữ trường không gửi tới', s.credits === 1200 && s.email === 'an@fabrikai.shop');

let calls = 0;
globalThis.fetch = async () => { calls += 1; return { ok: true, json: async () => ({ user: { ...ME, name: 'Từ API' } }) }; };
s.me = null;
const both = await Promise.all([s.load(), s.load()]);
check('load() chia sẻ ĐÚNG MỘT request cho mọi nơi cùng hỏi', calls === 1);
check('load() đọc danh tính từ /api/boot', both[0].name === 'Từ API' && both[1].name === 'Từ API' && s.source === 'api');

const before = calls;
await s.load();
check('đã có danh tính thì load() KHÔNG gọi mạng nữa', calls === before);

if (failed.length) {
  console.error('\nHỎNG: ' + failed.length + ' mục — ' + failed.join(' · '));
  process.exit(1);
}
console.log('\nTất cả mục OK (' + 9 + ' mục).');
