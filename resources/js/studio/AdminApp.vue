<script setup>
/**
 * AdminApp — CONSOLE OWNER của FabrikAI (/admin) — bản thiết kế lại UX/UI (2026-09-18).
 *
 * VẤN ĐỀ CỦA BẢN CŨ (715 dòng, 5 tab pill ngang):
 *   · Tab nằm ngang hàng tiêu đề, không nhóm, không mang thông tin; tab "Giao diện" bị đặt
 *     LẠC CHỖ trong template (nằm giữa các hộp thoại) nên rất khó lần ra khi đọc code.
 *   · Hộp thoại tự viết tay (\`fixed inset-0\` + role) nhưng KHÔNG giữ focus: bấm Tab vài lần là
 *     bàn phím đi xuyên ra sau lớp phủ. Bản này dùng chung BaseModal (đã có focus trap + Esc).
 *   · Mọi nút dùng emoji () — không có nhãn chữ, không screen-reader đọc được.
 *   · Người có vai trò Quản trị (không phải Owner) vẫn thấy tab Người dùng, bấm vào chỉ nhận
 *     lỗi 403 khô khan. Nay phân quyền được HIỂN THỊ rõ (ẩn mục + nói vì sao).
 *   · Dữ liệu server trả về nhưng bị bỏ: type_label, admin (ai điều chỉnh), users_count của gói,
 *     role_label, is_subscribed, phone, và tham số lọc user_id của sổ cái.
 *   · Xoá/khôi phục mặc định dùng confirm() của trình duyệt; xoá người dùng không nhắc lại email.
 *
 * GIỮ NGUYÊN HỢP ĐỒNG DỮ LIỆU: đúng bộ endpoint /api/admin/* + /api/boot, đúng payload,
 * đúng phân quyền 2 tầng (admin cho dashboard/plans/ledger/gui, super_admin cho users).
 */
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { userFacingError, apiError } from './store.js';
import { toastClientErrors } from './clientErrors.js';
import StudioIcon from './components/StudioIcon.vue';
import BaseModal from './components/BaseModal.vue';

const BASE = '/api/admin';
const csrf = (() => {
  if (typeof document === 'undefined') return '';
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : '';
})();

async function api(path, method = 'GET', body = null) {
  const opts = { method, headers: { 'X-XSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } };
  if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const r = await fetch(BASE + path, opts);
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw apiError(d, null, r);
  return d;
}

// ─────────────────────────── Điều hướng ───────────────────────────
const SECTIONS = [
  { id: 'dashboard', group: 'Bắt đầu',        label: 'Tổng quan',        icon: 'activity', superOnly: false },
  { id: 'users',     group: 'Người & credit', label: 'Người dùng',       icon: 'users',    superOnly: true },
  { id: 'plans',     group: 'Người & credit', label: 'Gói cước',         icon: 'package',  superOnly: false },
  { id: 'ledger',    group: 'Người & credit', label: 'Sổ credit',        icon: 'receipt',  superOnly: false },
  // [Q2 — 2026-09-19] Hàng đợi YÊU CẦU NÂNG CẤP: khách gửi yêu cầu (có mã) → xác nhận tiền → kích hoạt.
  { id: 'upgrades',  group: 'Người & credit', label: 'Yêu cầu nâng cấp', icon: 'coins',    superOnly: false },
  { id: 'gui',       group: 'Hệ thống',       label: 'Giao diện Studio', icon: 'palette',  superOnly: false },
  // [Modules 2026-09-19] Công tắc tính năng: bật/tắt toàn cục + cấp module theo GÓI (gói = công tắc).
  { id: 'modules',   group: 'Hệ thống',       label: 'Tính năng & gói',  icon: 'puzzle',   superOnly: false },
];
const SECTION_GROUPS = ['Bắt đầu', 'Người & credit', 'Hệ thống'];

const ROLE_META = {
  super_admin: { label: 'Owner', cls: 'bg-gold-400/15 text-gold-400' },
  admin: { label: 'Quản trị', cls: 'bg-brand-600/25 text-brand-100' },
  customer: { label: 'Khách hàng', cls: 'bg-ink-700 text-cream-300' },
};
const roleMeta = (r) => ROLE_META[r] || { label: r, cls: 'bg-ink-700 text-cream-300' };

const TYPE_META = {
  spend: { label: 'Tiêu credit', cls: 'bg-red-500/15 text-danger' },
  refund: { label: 'Hoàn credit', cls: 'bg-emerald-500/15 text-ok' },
  grant: { label: 'Tặng credit', cls: 'bg-emerald-500/15 text-ok' },
  purchase: { label: 'Nạp gói', cls: 'bg-brand-600/25 text-brand-100' },
  renew: { label: 'Gia hạn', cls: 'bg-brand-600/25 text-brand-100' },
  signup: { label: 'Đăng ký', cls: 'bg-sky-500/15 text-info' },
  adjust: { label: 'Điều chỉnh', cls: 'bg-amber-500/15 text-warn' },
  // [2026-09-19] Cấp credit theo CHU KỲ GÓI (plans.credits_per_month) — trước đây gói chỉ hiển thị
  // số credit/tháng mà không có đường cấp nào.
  plan_grant: { label: 'Cấp theo gói', cls: 'bg-brand-600/25 text-brand-100' },
};
const typeMeta = (t) => TYPE_META[t] || { label: t, cls: 'bg-ink-700 text-cream-300' };

// Id LUÔN ghim ở đáy thanh công cụ Studio — đổi nhãn/icon/ẩn-hiện được, KHÔNG đổi vị trí.
// Phải khớp StudioGuiConfig::PINNED_IDS (có test đối chiếu).
const GUI_PINNED = ['settings'];
const GUI_KIND = {
  panel: { label: 'Nhóm card', cls: 'bg-brand-600/25 text-brand-100', hint: 'Mở nhóm card ở sidebar trái' },
  action: { label: 'Popup', cls: 'bg-amber-500/20 text-warn', hint: 'Mở popup riêng (không đổi sidebar)' },
  menu: { label: 'Menu (ghim đáy)', cls: 'bg-ink-700 text-cream-300', hint: 'Menu Cài đặt — luôn nằm ở đáy thanh công cụ' },
};
const isGuiPinned = (it) => GUI_PINNED.indexOf(it.id) !== -1;

const BADGE = 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label font-semibold';
const BADGE_TONE = {
  neutral: 'bg-ink-700 text-cream-300',
  ok: 'bg-emerald-500/15 text-ok',
  warn: 'bg-amber-500/15 text-warn',
  danger: 'bg-red-500/15 text-danger',
  brand: 'bg-brand-600/25 text-brand-100',
  info: 'bg-sky-500/15 text-info',
  gold: 'bg-gold-400/15 text-gold-400',
};

// ─────────────────────────── Trạng thái ───────────────────────────
const me = ref(null);
const section = ref('dashboard');
const toast = ref(null);
const loading = reactive({ dashboard: true, plans: false, users: false, ledger: false, gui: false, upgrades: false, modules: false });

const dashboard = ref(null);
const plansData = ref([]);
const usersData = ref({ users: [], total: 0, page: 1, last_page: 1 });
const ledgerData = ref({ transactions: [], total: 0, page: 1, last_page: 1 });
const guiItems = ref([]);
const guiIcons = ref([]);
const guiSnapshot = ref('');

// [Q2 — 2026-09-19] Yêu cầu nâng cấp + thông tin nhận tiền
const upgradesData = ref({ requests: [], counts: {}, statuses: [], payment: null });
const upFilter = ref('');
const paymentForm = reactive({
  bank_name: '', bank_account: '', bank_holder: '', bank_branch: '',
  support_phone: '', support_email: '', support_zalo: '', support_hours: '',
});
const pendingUpgrades = computed(() => Number(upgradesData.value.counts.pending || 0) + Number(upgradesData.value.counts.contacted || 0));

// [Modules] Công tắc tính năng: danh mục module (sinh từ ModuleRegistry) + gói nào cấp module nào.
const modulesData = ref({ modules: [], plans: [], groups: {}, disabled: [], total_modules: 0 });
const moduleSearch = ref('');
/** Bản nháp cục bộ: { [planSlug]: [moduleId] } — chỉ ghi khi bấm Lưu. */
const planModules = ref({});
const globalDisabled = ref([]);
const moduleDirty = ref(false);
const filteredModules = computed(() => {
  const n = moduleSearch.value.trim().toLowerCase();
  const list = modulesData.value.modules || [];
  return n ? list.filter((m) => (m.name + ' ' + m.id + ' ' + m.group).toLowerCase().includes(n)) : list;
});
const moduleGroups = computed(() => {
  const out = {};
  filteredModules.value.forEach((m) => { (out[m.group] = out[m.group] || []).push(m); });
  return out;
});
const plansWithModule = (id) => (modulesData.value.plans || []).filter((p) => (planModules.value[p.slug] || []).includes(id));

const f = reactive({ userSearch: '', userRole: '', userStatus: '', userPerPage: 20 });
const l = reactive({ search: '', type: '', userId: null, userName: '', perPage: 20 });
const planSearch = ref('');
const guiSearch = ref('');

const isSuper = computed(() => !!(me.value && me.value.is_super_admin));
const navSections = computed(() => SECTIONS.filter((s) => !s.superOnly || isSuper.value));

const kpis = computed(() => (dashboard.value ? dashboard.value.kpis : null));
const dashboardPlans = computed(() => (dashboard.value ? dashboard.value.plans || [] : []));
const maxPlanCount = computed(() => {
  const v = dashboardPlans.value.map((p) => Number(p.users_count) || 0);
  return v.length ? Math.max(1, Math.max.apply(null, v)) : 1;
});
const totalPlanUsers = computed(() => dashboardPlans.value.reduce((n, p) => n + (Number(p.users_count) || 0), 0));
const planShare = (n) => (totalPlanUsers.value ? Math.round(((Number(n) || 0) / totalPlanUsers.value) * 100) : 0);

const activePlans = computed(() => plansData.value.filter((p) => p.is_active));
const defaultPlan = computed(() => plansData.value.find((p) => p.is_default) || null);
const filteredPlans = computed(() => {
  const n = planSearch.value.trim().toLowerCase();
  if (!n) return plansData.value;
  return plansData.value.filter((p) => (p.name + ' ' + p.slug + ' ' + (p.tagline || '')).toLowerCase().includes(n));
});
const filteredGui = computed(() => {
  const n = guiSearch.value.trim().toLowerCase();
  if (!n) return guiItems.value;
  return guiItems.value.filter((it) => (it.id + ' ' + it.label + ' ' + it.icon).toLowerCase().includes(n));
});
const guiPreview = computed(() => guiItems.value.filter((i) => i.visible !== false));
const guiDirty = computed(() => JSON.stringify(guiItems.value) !== guiSnapshot.value);
const guiHiddenCount = computed(() => guiItems.value.filter((i) => i.visible === false).length);

const fmtNum = (n) => (Number(n) || 0).toLocaleString('vi-VN');
const fmtVnd = (n) => (Number(n) || 0).toLocaleString('vi-VN') + ' ₫';
const planPrice = (p) => p.price_label || fmtVnd(p.price_vnd);
const pageInfo = (d) => 'Trang ' + d.page + ' / ' + (d.last_page || 1) + ' · ' + fmtNum(d.total) + ' dòng';
const canPrev = (d) => d.page > 1;
const canNext = (d) => d.page < (d.last_page || 1);
const countText = (shown, total, unit) => (shown === total ? total + ' ' + unit : 'Hiện ' + shown + '/' + total + ' ' + unit);

function flash(msg, ok = true) { toast.value = { msg, ok }; setTimeout(() => { if (toast.value && toast.value.msg === msg) toast.value = null; }, ok ? 3000 : 5200); }
// Lỗi trình duyệt hiện kèm mã tra cứu; câu hiển thị vẫn qua cửa chặn §6.
toastClientErrors((text) => flash(text, false));
function goTo(id) {
  section.value = id;
  ensureLoaded(id);
}
/**
 * Chạy một thao tác ghi. Trả về true/false (KHÔNG trả dữ liệu): bản cũ trả giá trị của fn nên
 * `ok` là undefined với closure không return → hộp thoại không đóng và danh sách không nạp lại
 * dù máy chủ đã lưu thành công (lỗi bắt được khi kiểm thử thao tác tạo người dùng).
 */
async function run(fn, okMsg) {
  try { await fn(); if (okMsg) flash(okMsg); return true; }
  catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); return false; }
}

// ─────────────────────────── Nạp dữ liệu ───────────────────────────
async function loadDashboard() {
  loading.dashboard = true;
  try { dashboard.value = await api('/dashboard'); }
  catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  finally { loading.dashboard = false; }
}
async function loadPlans() {
  loading.plans = true;
  try { plansData.value = (await api('/plans')).plans || []; }
  catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  finally { loading.plans = false; }
}
async function loadUsers() {
  if (!isSuper.value) return;
  loading.users = true;
  try {
    const q = new URLSearchParams();
    if (f.userSearch) q.set('search', f.userSearch);
    if (f.userRole) q.set('role', f.userRole);
    if (f.userStatus) q.set('status', f.userStatus);
    q.set('per_page', f.userPerPage);
    q.set('page', usersData.value.page || 1);
    usersData.value = await api('/users?' + q.toString());
  } catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  finally { loading.users = false; }
}
async function loadLedger() {
  loading.ledger = true;
  try {
    const q = new URLSearchParams();
    if (l.type) q.set('type', l.type);
    if (l.search) q.set('search', l.search);
    if (l.userId) q.set('user_id', l.userId);
    q.set('per_page', l.perPage);
    q.set('page', ledgerData.value.page || 1);
    ledgerData.value = await api('/transactions?' + q.toString());
  } catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  finally { loading.ledger = false; }
}
async function loadGui() {
  loading.gui = true;
  try {
    const d = await api('/gui');
    guiItems.value = (d.activityBar || []).map((x) => Object.assign({}, x));
    guiIcons.value = d.icons || [];
    guiSnapshot.value = JSON.stringify(guiItems.value);
  } catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  finally { loading.gui = false; }
}
// ─────────────────────────── Yêu cầu nâng cấp (Q2) ───────────────────────────
// Hàng đợi để chủ dự án xử lý: xác nhận đã liên hệ khách, huỷ nếu khách đổi ý, và KÍCH HOẠT khi tiền
// đã về. Kích hoạt đi qua API riêng (chỉ Super Admin) — giao diện không tự gán gói.
async function loadUpgrades() {
  loading.upgrades = true;
  try {
    const q = upFilter.value ? '?status=' + encodeURIComponent(upFilter.value) : '';
    const d = await api('/upgrade-requests' + q);
    upgradesData.value = d;
    const bank = (d.payment && d.payment.bank) || {};
    const sup = (d.payment && d.payment.support) || {};
    Object.assign(paymentForm, {
      bank_name: bank.name || '', bank_account: bank.account || '', bank_holder: bank.holder || '', bank_branch: bank.branch || '',
      support_phone: sup.phone || '', support_email: sup.email || '', support_zalo: sup.zalo || '', support_hours: sup.hours || '',
    });
  } catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  finally { loading.upgrades = false; }
}
async function setUpgradeStatus(row, status) {
  const ok = await run(() => api('/upgrade-requests/' + row.id, 'POST', { status }), 'Đã cập nhật ' + row.code);
  if (ok) loadUpgrades();
}
async function activateUpgrade(row) {
  const ok = await run(
    () => api('/upgrade-requests/' + row.id + '/activate', 'POST', {}),
    'Đã kích hoạt gói cho ' + (row.user ? row.user.name : row.code),
  );
  if (ok) loadUpgrades();
}
async function savePayment() {
  const ok = await run(() => api('/payment-info', 'POST', Object.assign({}, paymentForm)), 'Đã lưu thông tin nhận tiền');
  if (ok) loadUpgrades();
}
const upgradeTone = (s) => (s === 'pending' ? 'warn' : s === 'contacted' ? 'info' : s === 'activated' ? 'ok' : 'neutral');

// ─────────────────────────── Tính năng & gói (Modules) ───────────────────────────
// Một nguồn: ModuleRegistry. Màn này chỉ đọc bản khai và ghi vào DỮ LIỆU (setting + plans.modules), nên
// thêm module mới là màn tự có thêm dòng, không phải sửa giao diện.
async function loadModules() {
  loading.modules = true;
  try {
    const d = await api('/modules');
    modulesData.value = d;
    globalDisabled.value = (d.disabled || []).slice();
    const draft = {};
    (d.plans || []).forEach((p) => { draft[p.slug] = (p.modules || []).slice(); });
    planModules.value = draft;
    moduleDirty.value = false;
  } catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  finally { loading.modules = false; }
}
function toggleGlobal(id) {
  const i = globalDisabled.value.indexOf(id);
  if (i === -1) globalDisabled.value.push(id); else globalDisabled.value.splice(i, 1);
  moduleDirty.value = true;
}
async function saveGlobalModules() {
  const ok = await run(() => api('/modules', 'POST', { disabled: globalDisabled.value }), 'Đã lưu công tắc tính năng.');
  if (ok) loadModules();
}
function toggleGrant(slug, id) {
  const list = planModules.value[slug] || [];
  const i = list.indexOf(id);
  if (i === -1) list.push(id); else list.splice(i, 1);
  planModules.value = { ...planModules.value, [slug]: list };
  moduleDirty.value = true;
}
async function savePlanModules(plan) {
  const next = (planModules.value[plan.slug] || []).slice();
  confirmRevokeIfNeeded(plan, next, 'Kiểm tra lại danh sách rồi bấm Lưu nếu vẫn đúng.', async () => {
    const ok = await run(
      () => api('/plans/' + plan.id + '/modules', 'PUT', { modules: next }),
      'Đã lưu module cho gói ' + plan.name,
    );
    if (ok) loadModules();
  });
}
/**
 * [Modules] Thay đổi quyền của gói là thay đổi TRẢI NGHIỆM của mọi người dùng gói đó ⇒ nếu gói đang có
 * người dùng mà lại RÚT tính năng thì phải xác nhận kèm ẢNH HƯỞNG cụ thể (bao nhiêu người · rút gì).
 * Đây là bài học từ chính lần chủ dự án bấm «Áp đề xuất»: thao tác đúng chức năng nhưng không nói trước
 * hậu quả.
 */
function moduleNamesOf(ids) {
  return ids.map((id) => (modulesData.value.modules.find((m) => m.id === id) || {}).name || id);
}
function confirmRevokeIfNeeded(plan, nextIds, message, after) {
  const removed = (planModules.value[plan.slug] || []).filter((id) => !nextIds.includes(id));
  const users = Number(plan.users_count || 0);
  if (!removed.length || !users) { after(); return; }
  askConfirm(
    'Rút tính năng khỏi gói ' + plan.name + '?',
    'Gói này đang có ' + users + ' người dùng. Thao tác sẽ RÚT ' + removed.length + ' tính năng: '
      + moduleNamesOf(removed).join(' · ') + '. '
      + 'Người dùng gói này sẽ thấy ổ khoá và được mời nâng cấp ngay khi tính năng bị rút. '
      + message,
    'Rút và lưu',
    after,
  );
}
async function applySuggested(plan) {
  const suggested = (plan.suggested || []).slice();
  confirmRevokeIfNeeded(plan, suggested, 'Bạn có thể bấm «Cấp tất cả» nếu muốn giữ nguyên quyền cũ.', async () => {
    const ok = await run(
      () => api('/plans/' + plan.id + '/modules/suggested', 'POST', {}),
      'Đã áp đề xuất cho gói ' + plan.name,
    );
    if (ok) loadModules();
  });
}
/** [Modules] Cấp LẠI toàn bộ tính năng cho gói — đường khôi phục 1 cú bấm nếu lỡ rút nhầm. */
async function grantAllModules(plan) {
  askConfirm(
    'Cấp tất cả tính năng cho gói ' + plan.name + '?',
    'Gói này sẽ cấp đủ ' + modulesData.value.total_modules + ' tính năng đang mở. '
      + (Number(plan.users_count || 0) ? Number(plan.users_count) + ' người dùng của gói sẽ dùng được ngay.' : 'Gói hiện chưa có người dùng nào.'),
    'Cấp tất cả',
    async () => {
      const ok = await run(
        () => api('/plans/' + plan.id + '/modules', 'PUT', { modules: (modulesData.value.modules || []).map((m) => m.id) }),
        'Đã cấp toàn bộ tính năng cho gói ' + plan.name,
      );
      if (ok) loadModules();
    },
  );
}
const moduleKindMeta = (k) => ({
  panel: { label: 'Nhóm card', cls: 'bg-brand-600/25 text-brand-100' },
  action: { label: 'Popup', cls: 'bg-amber-500/20 text-warn' },
  menu: { label: 'Menu', cls: 'bg-ink-700 text-cream-300' },
  feature: { label: 'Tính năng', cls: 'bg-sky-500/15 text-info' },
}[k] || { label: k, cls: 'bg-ink-700 text-cream-300' });

function ensureLoaded(id) {
  if (id === 'users' && isSuper.value && !usersData.value.users.length) loadUsers();
  if (id === 'ledger' && !ledgerData.value.transactions.length) loadLedger();
  if (id === 'gui' && !guiItems.value.length) loadGui();
  if (id === 'plans' && !plansData.value.length) loadPlans();
  if (id === 'upgrades' && !upgradesData.value.requests.length) loadUpgrades();
  if (id === 'modules' && !modulesData.value.modules.length) loadModules();
}

// ─────────────────────────── Việc cần xử lý (tổng quan) ───────────────────────────
const attention = computed(() => {
  const out = [];
  if (!isSuper.value) {
    out.push({ icon: 'shieldCheck', tone: 'info', title: 'Không có quyền quản lý tài khoản', detail: 'Bạn đang là «' + ((me.value && me.value.role_label) || 'Quản trị') + '». Chỉ Owner (super admin) tạo/sửa/khoá được người dùng — các mục còn lại vẫn dùng bình thường.', action: 'Cài đặt AI', run: () => { window.location.href = '/settings'; } });
  }
  if (plansData.value.length && !defaultPlan.value) {
    out.push({ icon: 'package', tone: 'warn', title: 'Chưa có gói mặc định', detail: 'Người dùng mới sẽ không được gán gói nào. Đặt một gói làm mặc định để luồng đăng ký tự phục vụ hoạt động.', action: 'Xem gói cước', run: () => goTo('plans') });
  }
  if (plansData.value.length && !activePlans.value.length) {
    out.push({ icon: 'eyeOff', tone: 'warn', title: 'Tất cả gói đang bị ẩn', detail: 'Không gói nào đang mở bán — trang giá sẽ trống với khách.', action: 'Xem gói cước', run: () => goTo('plans') });
  } else if (plansData.value.filter((p) => !p.is_active).length) {
    const n = plansData.value.filter((p) => !p.is_active).length;
    out.push({ icon: 'eyeOff', tone: 'info', title: n + ' gói đang ẩn', detail: 'Gói ẩn không hiện cho khách nhưng vẫn giữ người dùng cũ: ' + plansData.value.filter((p) => !p.is_active).map((p) => p.name).join(' · '), action: 'Xem gói cước', run: () => goTo('plans') });
  }
  const emptyPlans = activePlans.value.filter((p) => !(Number(p.users_count) > 0));
  if (activePlans.value.length && emptyPlans.length === activePlans.value.length && kpis.value && kpis.value.total_users) {
    out.push({ icon: 'info', tone: 'info', title: 'Chưa ai đăng ký gói trả phí', detail: emptyPlans.length + ' gói đang mở bán nhưng 0 người dùng: ' + emptyPlans.map((p) => p.name).join(' · '), action: '', run: null });
  }
  if (kpis.value && !kpis.value.credits_spent_30d) {
    out.push({ icon: 'receipt', tone: 'info', title: 'Chưa phát sinh tiêu credit trong 30 ngày', detail: 'Sổ credit chỉ có giao dịch tặng/điều chỉnh. Kiểm tra key/provider ở Cài đặt nếu người dùng không tạo được ảnh.', action: 'Mở Cài đặt', run: () => { window.location.href = '/settings'; } });
  }
  if (kpis.value && !kpis.value.paying_subscribers && kpis.value.total_users) {
    out.push({ icon: 'coins', tone: 'info', title: 'Không có người dùng trả phí', detail: 'Người dùng đang dùng gói miễn phí hoặc chưa gán gói.', action: 'Xem người dùng', run: () => { if (isSuper.value) goTo('users'); } });
  }
  // [Q2] Yêu cầu nâng cấp đang chờ = TIỀN đang chờ thu: phải nằm trong danh sách việc cần xử lý.
  if (pendingUpgrades.value) {
    out.push({
      icon: 'coins', tone: 'warn',
      title: pendingUpgrades.value + ' yêu cầu nâng cấp đang chờ xử lý',
      detail: 'Khách đã để lại số điện thoại và chọn gói: xác nhận đã liên hệ, và kích hoạt gói ngay khi tiền về.',
      action: 'Xử lý ngay', run: () => goTo('upgrades'),
    });
  }
  if (guiHiddenCount.value) {
    out.push({ icon: 'palette', tone: 'info', title: guiHiddenCount.value + ' nút đang bị ẩn trên thanh công cụ', detail: 'Người dùng Studio không thấy các mục này.', action: 'Xem giao diện', run: () => goTo('gui') });
  }
  if (!out.length) {
    out.push({ icon: 'checkSquare', tone: 'ok', title: 'Không có việc nào đang chờ', detail: 'Gói cước, sổ credit và giao diện đều đang ở trạng thái bình thường.', action: '', run: null });
  }
  return out;
});
// Bốn tông chú ý — CÙNG quy ước với viền nút: một nghĩa = ĐÚNG MỘT cặp màu + alpha (/40).
// Xem docs/DESIGN_SYSTEM.md §5 "Viền".
const attentionTone = (tone) => ({
  danger: 'text-danger bg-red-500/10 border-red-500/40',
  warn: 'text-warn bg-amber-500/10 border-amber-500/40',
  info: 'text-info bg-sky-500/10 border-sky-500/40',
  ok: 'text-ok bg-emerald-500/10 border-emerald-500/40',
}[tone] || 'text-cream-300 bg-ink-700 border-ink-700');

// ─────────────────────────── Hộp thoại: người dùng ───────────────────────────
const userModal = reactive({ open: false, mode: 'create', row: null, form: blankUser(), errors: {}, saving: false });
const creditModal = reactive({ open: false, row: null, form: { amount: '', note: '' }, errors: {}, saving: false });
const pwdModal = reactive({ open: false, row: null, form: { password: '' }, errors: {}, saving: false });
const planModal = reactive({ open: false, mode: 'create', row: null, form: blankPlan(), errors: {}, saving: false, featureText: '' });

function blankUser() { return { name: '', email: '', phone: '', password: '', role: 'customer', plan_id: '', is_active: true }; }
function blankPlan() {
  // seats: [Q4] số NGƯỜI dùng chung một gói (1 = một người).
  // modules: [Modules] công tắc cấp phát tính năng của gói (danh sách id module).
  // features: ghi chú HIỂN THỊ nhập tay — chỉ để khách đọc, KHÔNG cấp quyền gì.
  return { name: '', slug: '', tagline: '', price_vnd: 0, credits_per_month: 0, bonus_credits: 0, image_credit_cost: 1, video_credit_cost: 10, resolution_cap: '2K', seats: 1, modules: [], features: [], is_active: true, is_default: false, sort: 0 };
}
function openCreateUser() { Object.assign(userModal, { open: true, mode: 'create', row: null, form: blankUser(), errors: {}, saving: false }); }
function openEditUser(u) {
  Object.assign(userModal, {
    open: true, mode: 'edit', row: u, errors: {}, saving: false,
    form: { name: u.name, email: u.email, phone: u.phone || '', password: '', role: u.role, plan_id: u.plan ? u.plan.id : '', is_active: !!u.is_active },
  });
}
function openCredit(u) { Object.assign(creditModal, { open: true, row: u, form: { amount: '', note: '' }, errors: {}, saving: false }); }
function openPwd(u) { Object.assign(pwdModal, { open: true, row: u, form: { password: '' }, errors: {}, saving: false }); }

function validateUser() {
  const v = userModal.form;
  const e = {};
  if (!String(v.name).trim()) e.name = 'Nhập tên người dùng.';
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(v.email).trim())) e.email = 'Email không hợp lệ.';
  if (userModal.mode === 'create' && String(v.password).length < 8) e.password = 'Mật khẩu tối thiểu 8 ký tự.';
  if (userModal.row && userModal.row.id === (me.value && me.value.id) && v.role !== 'super_admin') e.role = 'Không thể tự hạ quyền chính mình.';
  if (userModal.row && userModal.row.id === (me.value && me.value.id) && !v.is_active) e.is_active = 'Không thể tự khoá chính mình.';
  userModal.errors = e;
  return !Object.keys(e).length;
}
async function saveUser() {
  if (!validateUser()) return;
  userModal.saving = true;
  const v = userModal.form;
  const payload = {
    name: v.name.trim(), email: v.email.trim(), phone: v.phone || null, role: v.role,
    is_active: !!v.is_active, plan_id: v.plan_id === '' ? null : Number(v.plan_id),
  };
  if (userModal.mode === 'create') payload.password = v.password;
  const ok = await run(async () => {
    if (userModal.mode === 'edit') await api('/users/' + userModal.row.id, 'PUT', payload);
    else await api('/users', 'POST', payload);
  }, userModal.mode === 'edit' ? 'Đã cập nhật người dùng.' : 'Đã tạo người dùng.');
  userModal.saving = false;
  if (ok) { userModal.open = false; await loadUsers(); await loadDashboard(); }
}
/** Khoá / mở khoá nhanh: dùng lại PUT /users/{id} với đầy đủ trường bắt buộc. */
async function toggleUserActive(u, active) {
  const payload = { name: u.name, email: u.email, phone: u.phone || null, role: u.role, is_active: active, plan_id: u.plan ? u.plan.id : null };
  const ok = await run(() => api('/users/' + u.id, 'PUT', payload), active ? 'Đã mở khoá «' + u.name + '».' : 'Đã khoá «' + u.name + '».');
  if (ok) { await loadUsers(); await loadDashboard(); }
}
async function saveCredit() {
  const amt = Number(creditModal.form.amount);
  const e = {};
  if (!Number.isInteger(amt) || amt === 0) e.amount = 'Nhập số nguyên khác 0 (dương = cộng, âm = trừ).';
  creditModal.errors = e;
  if (Object.keys(e).length) return;
  creditModal.saving = true;
  const ok = await run(() => api('/users/' + creditModal.row.id + '/credits', 'POST', { amount: amt, note: creditModal.form.note || null }), 'Đã cập nhật credit.');
  creditModal.saving = false;
  if (ok) { creditModal.open = false; await loadUsers(); await loadLedger(); await loadDashboard(); }
}
async function savePwd() {
  const e = {};
  if (String(pwdModal.form.password).length < 8) e.password = 'Mật khẩu tối thiểu 8 ký tự.';
  pwdModal.errors = e;
  if (Object.keys(e).length) return;
  pwdModal.saving = true;
  const ok = await run(() => api('/users/' + pwdModal.row.id + '/reset-password', 'POST', { password: pwdModal.form.password }), 'Đã đặt lại mật khẩu cho «' + pwdModal.row.name + '».');
  pwdModal.saving = false;
  if (ok) { pwdModal.open = false; }
}
/** Nhảy sang Sổ credit và lọc đúng người này — dùng tham số user_id có sẵn của endpoint. */
function openUserLedger(u) {
  l.userId = u.id; l.userName = u.name;
  ledgerData.value = { transactions: [], total: 0, page: 1, last_page: 1 };
  goTo('ledger');
}

// ─────────────────────────── Hộp thoại: gói cước ───────────────────────────
function openCreatePlan() {
  ensureModulesLoaded();
  Object.assign(planModal, { open: true, mode: 'create', row: null, form: blankPlan(), errors: {}, saving: false, featureText: '' });
}
function openEditPlan(p) {
    ensureModulesLoaded();
  const form = blankPlan();
  Object.keys(form).forEach((k) => { if (k in p) form[k] = k === 'features' ? (p.features || []).slice() : p[k]; });
  Object.assign(planModal, { open: true, mode: 'edit', row: p, form, errors: {}, saving: false, featureText: '' });
}
function addFeature() {
  const t = planModal.featureText.trim();
  if (t && planModal.form.features.indexOf(t) === -1) planModal.form.features.push(t);
  planModal.featureText = '';
}
function removeFeature(i) { planModal.form.features.splice(i, 1); }
// [Modules] Tick/bỏ tick module cho gói NGAY TRONG form gói (cùng dữ liệu với màn «Tính năng & gói»).
function togglePlanFormModule(id) {
  const list = planModal.form.modules || [];
  const i = list.indexOf(id);
  if (i === -1) list.push(id); else list.splice(i, 1);
  planModal.form.modules = list.slice();
}
const planFormModuleCount = () => (planModal.form.modules || []).length;
/** Tên tính năng khách sẽ thấy (suy từ module đã tick) — để owner xem trước khi lưu. */
const planFormModuleNames = () => (planModal.form.modules || [])
  .map((id) => (modulesData.value.modules || []).find((m) => m.id === id))
  .filter(Boolean)
  .map((m) => m.name);
/** Đảm bảo màn «Tính năng & gói» có dữ liệu để form gói hiện được danh sách module. */
function ensureModulesLoaded() {
  if (!modulesData.value.modules.length) loadModules();
}

function validatePlan() {
  const v = planModal.form;
  const e = {};
  if (!String(v.name).trim()) e.name = 'Nhập tên gói.';
  if (!/^[a-z0-9][a-z0-9_-]*$/.test(String(v.slug).trim())) e.slug = 'Slug: chữ thường/số, bắt đầu bằng chữ hoặc số (vd: pro, studio-2026).';
  if (!Number.isFinite(Number(v.price_vnd)) || Number(v.price_vnd) < 0) e.price_vnd = 'Giá không hợp lệ.';
  if (!Number.isFinite(Number(v.credits_per_month)) || Number(v.credits_per_month) < 0) e.credits_per_month = 'Credit/tháng không hợp lệ.';
  if (Number(v.image_credit_cost) < 1) e.image_credit_cost = 'Tối thiểu 1 credit/ảnh.';
  if (Number(v.video_credit_cost) < 1) e.video_credit_cost = 'Tối thiểu 1 credit/video.';
  planModal.errors = e;
  return !Object.keys(e).length;
}
async function savePlan() {
  if (!validatePlan()) return;
  planModal.saving = true;
  const v = planModal.form;
  const payload = Object.assign({}, v, {
    name: v.name.trim(), slug: v.slug.trim(), tagline: v.tagline || null,
    price_vnd: Number(v.price_vnd) || 0, credits_per_month: Number(v.credits_per_month) || 0,
    bonus_credits: Number(v.bonus_credits) || 0, image_credit_cost: Number(v.image_credit_cost) || 1,
    video_credit_cost: Number(v.video_credit_cost) || 10, sort: Number(v.sort) || 0,
  });
  const ok = await run(async () => {
    if (planModal.mode === 'edit') await api('/plans/' + planModal.row.id, 'PUT', payload);
    else await api('/plans', 'POST', payload);
  }, planModal.mode === 'edit' ? 'Đã cập nhật gói.' : 'Đã tạo gói.');
  planModal.saving = false;
  if (ok) { planModal.open = false; await loadPlans(); await loadDashboard(); }
}
/** Ẩn/hiện gói = PUT đầy đủ trường bắt buộc (giữ nguyên phần còn lại). */
async function togglePlanActive(p, active) {
  const payload = Object.assign({}, p, { is_active: active, tagline: p.tagline || null, features: p.features || [] });
  const ok = await run(() => api('/plans/' + p.id, 'PUT', payload), active ? 'Đã mở bán gói «' + p.name + '».' : 'Đã ẩn gói «' + p.name + '».');
  if (ok) { await loadPlans(); await loadDashboard(); }
}

// ─────────────────────────── Xác nhận thao tác phá huỷ ───────────────────────────
const confirmBox = reactive({ open: false, title: '', message: '', label: 'Xác nhận', busy: false, run: null });
function askConfirm(title, message, label, fn) { Object.assign(confirmBox, { open: true, title, message, label, busy: false, run: fn }); }
async function confirmRun() {
  if (!confirmBox.run) return;
  confirmBox.busy = true;
  await confirmBox.run();
  confirmBox.busy = false;
  confirmBox.open = false;
}
const askDeleteUser = (u) => askConfirm(
  'Xoá người dùng?',
  'Xoá «' + u.name + '» (' + u.email + ') sẽ mất toàn bộ dự án, ảnh và lịch sử credit. Hành động này KHÔNG hoàn tác được. Nếu chỉ muốn chặn đăng nhập, hãy dùng nút Khoá.',
  'Xoá vĩnh viễn',
  async () => { const ok = await run(() => api('/users/' + u.id, 'DELETE'), 'Đã xoá người dùng.'); if (ok) { await loadUsers(); await loadDashboard(); } },
);
const askBanUser = (u) => askConfirm(
  'Khoá tài khoản?',
  '«' + u.name + '» sẽ không đăng nhập được cho tới khi bạn mở khoá. Dữ liệu (dự án, ảnh, credit) vẫn giữ nguyên.',
  'Khoá tài khoản',
  async () => { await toggleUserActive(u, false); },
);
const askTogglePlan = (p, active) => askConfirm(
  active ? 'Mở bán lại gói?' : 'Ẩn gói khỏi trang giá?',
  active
    ? 'Gói «' + p.name + '» sẽ hiện lại cho khách đăng ký.'
    : 'Gói «' + p.name + '» sẽ không còn hiện cho khách. Người dùng đang dùng gói này KHÔNG bị ảnh hưởng — muốn xoá hẳn thì dùng nút Xoá.',
  active ? 'Mở bán' : 'Ẩn gói',
  async () => { await togglePlanActive(p, active); },
);
const askDeletePlan = (p) => askConfirm(
  'Xoá gói cước?',
  'Xoá gói «' + p.name + '» (' + p.slug + '). Gói đang có người dùng hoặc đang là gói mặc định sẽ bị máy chủ từ chối — khi đó hãy ẨN gói thay vì xoá.',
  'Xoá gói',
  async () => { const ok = await run(() => api('/plans/' + p.id, 'DELETE'), 'Đã xoá gói.'); if (ok) { await loadPlans(); await loadDashboard(); } },
);
const askResetGui = () => askConfirm(
  'Khôi phục thanh công cụ?',
  'Toàn bộ tuỳ chỉnh (thứ tự · nhãn · icon · ẩn/hiện) sẽ bị bỏ và trở về bản gốc trong mã nguồn. Thay đổi này áp dụng cho MỌI người dùng Studio.',
  'Khôi phục mặc định',
  async () => {
    // Khôi phục trả về DANH SÁCH MỚI nên không dùng run() (run chỉ trả true/false).
    try {
      const d = await api('/gui/activity-bar/reset', 'POST');
      guiItems.value = (d.activityBar || []).map((x) => Object.assign({}, x));
      guiSnapshot.value = JSON.stringify(guiItems.value);
      flash('Đã khôi phục mặc định.');
    } catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  },
);

// ─────────────────────────── Giao diện Studio (thanh công cụ trái) ───────────────────────────
const guiSaving = ref(false);
function moveGui(item, dir) {
  const arr = guiItems.value;
  const i = arr.indexOf(item);
  const j = i + dir;
  if (i === -1 || j < 0 || j >= arr.length) return;
  const t = arr[i]; arr[i] = arr[j]; arr[j] = t;
}
async function saveGui() {
  guiSaving.value = true;
  try {
    const d = await api('/gui/activity-bar', 'PUT', { items: guiItems.value });
    guiItems.value = (d.activityBar || []).map((x) => Object.assign({}, x));
    guiSnapshot.value = JSON.stringify(guiItems.value);
    flash('Đã lưu cấu hình giao diện.');
  } catch (e) { flash(userFacingError(e, 'Thao tác thất bại.'), false); }
  guiSaving.value = false;
}

// ─────────────────────────── Khởi động & URL ───────────────────────────
function sectionFromUrl() {
  const raw = new URLSearchParams(window.location.search).get('tab') || window.location.hash.replace('#', '');
  return SECTIONS.some((s) => s.id === raw) ? raw : 'dashboard';
}
watch(section, (v) => {
  try {
    const url = new URL(window.location.href);
    if (v === 'dashboard') url.searchParams.delete('tab'); else url.searchParams.set('tab', v);
    history.replaceState(history.state, '', url);
  } catch (e) { /* môi trường không cho sửa URL — không được làm hỏng trang */ }
});
function navKey(e, i) {
  const list = navSections.value;
  let next = -1;
  if (e.key === 'ArrowDown') next = (i + 1) % list.length;
  else if (e.key === 'ArrowUp') next = (i - 1 + list.length) % list.length;
  else if (e.key === 'Home') next = 0;
  else if (e.key === 'End') next = list.length - 1;
  if (next === -1) return;
  e.preventDefault();
  goTo(list[next].id);
  const el = document.querySelector('[data-nav="' + list[next].id + '"]');
  if (el) el.focus();
}
function navBadge(id) {
  const map = {
    users: usersData.value.total ? fmtNum(usersData.value.total) : (isSuper.value ? '' : ''),
    plans: plansData.value.length || '',
    ledger: ledgerData.value.total ? fmtNum(ledgerData.value.total) : '',
    gui: guiItems.value.length ? (guiHiddenCount.value ? guiHiddenCount.value + ' ẩn' : String(guiItems.value.length)) : '',
    dashboard: attention.value[0] && attention.value[0].tone !== 'ok' ? attention.value.length : '',
    // [Q2] Số yêu cầu nâng cấp CHƯA xong — đây là việc chủ dự án cần xử lý, nên phải thấy ngay ở menu.
    upgrades: pendingUpgrades.value ? String(pendingUpgrades.value) : '',
    // [Modules] Số module đang bị TẮT toàn cục — con số đáng để thấy ngay trên menu.
    modules: globalDisabled.value.length ? globalDisabled.value.length + ' tắt' : (modulesData.value.total_modules ? String(modulesData.value.total_modules) : ''),
  };
  return map[id] == null ? '' : map[id];
}

onMounted(async () => {
  section.value = sectionFromUrl();
  // /api/boot cho biết vai trò thật (is_super_admin) — dùng để ẩn/hiện phần Người dùng.
  try {
    const r = await fetch('/api/boot', { headers: { Accept: 'application/json' } });
    if (r.ok) me.value = (await r.json()).user;
  } catch (e) { /* không lấy được danh tính: vẫn hiển thị phần không cần quyền */ }
  await loadDashboard();
  await loadPlans();
  // [Q2] Nạp luôn hàng đợi nâng cấp: menu hiện số việc đang chờ ngay khi mở trang Quản trị.
  await loadUpgrades();
  ensureLoaded(section.value);
});
</script>

<template>
  <div class="studio-shell min-h-screen w-full">
    <!-- Thông báo: aria-live để trình đọc màn hình đọc được kết quả thao tác -->
    <div class="pointer-events-none fixed bottom-5 right-5 z-[80] flex w-[min(92vw,26rem)] flex-col gap-2">
      <transition name="fade">
        <div v-if="toast" role="status" aria-live="polite"
             :class="toast.ok ? 'border-emerald-500/40 bg-emerald-950/95 text-ok' : 'border-red-500/50 bg-red-950/95 text-danger'"
             class="pointer-events-auto flex items-start gap-2 rounded-lg border px-3.5 py-2.5 text-xs font-semibold shadow-2xl backdrop-blur">
          <StudioIcon :name="toast.ok ? 'check' : 'alertTriangle'" size="h-4 w-4 shrink-0" class="mt-px" />
          <span>{{ toast.msg }}</span>
        </div>
      </transition>
    </div>

    <!-- ═════════ Thanh tiêu đề ═════════ -->
    <header class="sticky top-0 z-40 border-b border-ink-700 bg-ink-900/95 backdrop-blur">
      <div class="mx-auto flex w-full max-w-[1400px] flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-5 lg:px-6">
        <a href="/" class="tool-btn shrink-0" title="Về Studio">
          <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" />
          <span class="hidden sm:inline">Studio</span>
        </a>
        <div class="min-w-0 flex-1">
          <h1 class="flex items-center gap-2 font-display text-lg font-semibold text-cream-50">
            <span class="grid h-6 w-6 place-items-center rounded-md bg-brand-600 text-body font-bold text-white">F</span>
            Quản trị FabrikAI
          </h1>
          <p class="mt-0.5 hidden truncate text-body text-cream-300 sm:block">
            Người dùng · gói cước · sổ credit · giao diện Studio — thao tác ở đây ảnh hưởng toàn hệ thống.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <span v-if="me" :class="[BADGE, isSuper ? BADGE_TONE.gold : BADGE_TONE.brand]" :title="me.email">
            <StudioIcon :name="isSuper ? 'shieldCheck' : 'users'" size="h-3 w-3" />
            {{ me.name }} · {{ me.role_label }}
          </span>
          <span v-if="me && !isSuper" :class="[BADGE, BADGE_TONE.warn]" title="Chỉ Owner (super admin) quản lý được tài khoản người dùng">
            <StudioIcon name="info" size="h-3 w-3" /> không quản lý người dùng
          </span>
          <!-- [2026-09-25] Thư viện theme nằm CÙNG trang với bảng token: import liên kết daisyUI Theme
               Generator, bật theme cho từng chế độ Sáng/Tối, và đo luôn tỉ lệ tương phản của theme
               đang chạy. Để ngay cạnh "Cài đặt" vì cả hai đều là việc cấu hình toàn hệ thống. -->
          <a href="/he-thong-thiet-ke" class="tool-btn" title="Hệ thống thiết kế: thư viện theme daisyUI · bảng token hai chế độ">
            <StudioIcon name="palette" size="h-3.5 w-3.5" />
            <span class="hidden sm:inline">Thiết kế</span>
          </a>
          <a href="/settings" class="tool-btn" title="Cài đặt AI: API key · provider · model">
            <StudioIcon name="gear" size="h-3.5 w-3.5" />
            <span class="hidden sm:inline">Cài đặt</span>
          </a>
          <button class="tool-btn" :disabled="loading.dashboard" title="Nạp lại dữ liệu" @click="loadDashboard(); if (section==='users') loadUsers(); if (section==='ledger') loadLedger(); if (section==='gui') loadGui(); if (section==='plans') loadPlans(); if (section==='upgrades') loadUpgrades(); if (section==='modules') loadModules()">
            <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="{ 'animate-spin': loading.dashboard }" />
            <span class="hidden sm:inline">Tải lại</span>
          </button>
        </div>
      </div>
    </header>

    <div class="mx-auto w-full max-w-[1400px] px-4 py-4 sm:px-5 lg:px-6 lg:py-6">
      <div class="grid grid-cols-1 gap-5 lg:grid-cols-[15.5rem_minmax(0,1fr)]">
        <!-- ═════════ Danh mục (desktop) ═════════ -->
        <nav class="hidden lg:sticky lg:top-[4.75rem] lg:block lg:self-start" aria-label="Mục quản trị">
          <div v-for="group in SECTION_GROUPS" :key="group" class="mb-4">
            <p class="mb-1.5 px-3 text-label font-semibold uppercase tracking-[0.14em] text-cream-300">{{ group }}</p>
            <ul class="space-y-0.5">
              <li v-for="s in navSections.filter(x => x.group === group)" :key="s.id">
                <button :data-nav="s.id" @click="goTo(s.id)" @keydown="navKey($event, navSections.indexOf(s))"
                        :aria-current="section === s.id ? 'true' : undefined"
                        :class="section === s.id ? 'bg-brand-600/20 text-cream-50 ring-1 ring-inset ring-brand-500/40' : 'text-cream-200 hover:bg-ink-800'"
                        class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-xs font-semibold transition-colors">
                  <StudioIcon :name="s.icon" size="h-4 w-4" :class="section === s.id ? 'text-brand-300' : 'text-cream-300'" />
                  <span class="min-w-0 flex-1 truncate">{{ s.label }}</span>
                  <span v-if="navBadge(s.id)" :class="[BADGE, section === s.id ? BADGE_TONE.brand : BADGE_TONE.neutral]" class="!px-1.5">{{ navBadge(s.id) }}</span>
                </button>
              </li>
            </ul>
          </div>
          <p v-if="!isSuper" class="mx-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-2.5 text-label leading-relaxed text-warn">
            Vai trò của bạn không có quyền quản lý tài khoản người dùng (chỉ Owner). Các mục còn lại vẫn dùng bình thường.
          </p>
          <p class="mt-3 px-3 text-label leading-relaxed text-cream-300">
            Cấu hình AI (API key · provider · model) nằm ở <a href="/settings" class="link">Cài đặt</a>.<br>
            Bảng màu của cả sản phẩm nằm ở <a href="/he-thong-thiet-ke" class="link">Hệ thống thiết kế</a>
            — nơi import theme từ liên kết daisyUI và bật cho từng chế độ Sáng/Tối.
          </p>
        </nav>

        <!-- ═════════ Danh mục (màn hẹp) ═════════ -->
        <div class="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 lg:hidden scrollbar-hide" role="tablist" aria-label="Mục quản trị">
          <button v-for="s in navSections" :key="s.id" role="tab" :aria-selected="section === s.id"
                  @click="goTo(s.id)"
                  :class="section === s.id ? 'border-brand-500 bg-brand-600/20 text-cream-50' : 'border-ink-600 bg-ink-800 text-cream-300'"
                  class="flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold">
            <StudioIcon :name="s.icon" size="h-3.5 w-3.5" />
            {{ s.label }}
            <span v-if="navBadge(s.id)" class="rounded-full bg-ink-900/60 px-1.5 text-label">{{ navBadge(s.id) }}</span>
          </button>
        </div>

        <!-- ═════════ Nội dung ═════════ -->
        <main class="min-w-0 space-y-5">
          <!-- ───── TỔNG QUAN ───── -->
          <section v-show="section === 'dashboard'" class="space-y-5">
            <div v-if="!kpis" class="space-y-3">
              <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="i in 4" :key="i" class="card h-24 animate-pulse"></div>
              </div>
              <p class="text-center text-xs text-cream-300">Đang tải dữ liệu tổng quan…</p>
            </div>

            <template v-else>
              <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <button class="card p-4 text-left transition-colors hover:border-brand-400" @click="isSuper && goTo('users')">
                  <div class="flex items-start justify-between gap-2">
                    <p class="text-body font-semibold uppercase tracking-wide text-cream-300">Người dùng</p>
                    <StudioIcon name="users" size="h-4 w-4" class="text-brand-300/80" />
                  </div>
                  <p class="mt-1.5 font-display text-2xl font-semibold text-cream-50">{{ fmtNum(kpis.total_users) }}</p>
                  <p class="mt-0.5 text-body text-ok">+{{ fmtNum(kpis.new_users_7d) }} trong 7 ngày</p>
                </button>
                <button class="card p-4 text-left transition-colors hover:border-brand-400" @click="goTo('plans')">
                  <div class="flex items-start justify-between gap-2">
                    <p class="text-body font-semibold uppercase tracking-wide text-cream-300">Đang trả phí</p>
                    <StudioIcon name="coins" size="h-4 w-4" class="text-brand-300/80" />
                  </div>
                  <p class="mt-1.5 font-display text-2xl font-semibold text-cream-50">{{ fmtNum(kpis.paying_subscribers) }}</p>
                  <p class="mt-0.5 text-body text-cream-300">{{ activePlans.length }} gói đang mở bán</p>
                </button>
                <div class="card p-4">
                  <div class="flex items-start justify-between gap-2">
                    <p class="text-body font-semibold uppercase tracking-wide text-cream-300">Ảnh đã tạo</p>
                    <StudioIcon name="image" size="h-4 w-4" class="text-brand-300/80" />
                  </div>
                  <p class="mt-1.5 font-display text-2xl font-semibold text-cream-50">{{ fmtNum(kpis.generations_total) }}</p>
                  <p class="mt-0.5 text-body text-cream-300">+{{ fmtNum(kpis.generations_today) }} hôm nay</p>
                </div>
                <button class="card p-4 text-left transition-colors hover:border-brand-400" @click="goTo('ledger')">
                  <div class="flex items-start justify-between gap-2">
                    <p class="text-body font-semibold uppercase tracking-wide text-cream-300">Credit tiêu (30 ngày)</p>
                    <StudioIcon name="receipt" size="h-4 w-4" class="text-brand-300/80" />
                  </div>
                  <p class="mt-1.5 font-display text-2xl font-semibold text-cream-50">{{ fmtNum(kpis.credits_spent_30d) }}</p>
                  <p class="mt-0.5 text-body text-cream-300">dư hệ thống: {{ fmtNum(kpis.credits_balance_total) }}</p>
                </button>
              </div>

              <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="card p-4">
                  <h2 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                    <StudioIcon name="alertTriangle" size="h-4 w-4" class="text-warn" />
                    Việc cần xử lý
                  </h2>
                  <ul class="mt-3 space-y-2">
                    <li v-for="(item, i) in attention" :key="i" :class="attentionTone(item.tone)"
                        class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-3 py-2.5">
                      <StudioIcon :name="item.icon" size="h-4 w-4 shrink-0" />
                      <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold">{{ item.title }}</p>
                        <p class="mt-0.5 text-body font-normal opacity-80">{{ item.detail }}</p>
                      </div>
                      <button v-if="item.run" class="tool-btn shrink-0" @click="item.run()">
                        {{ item.action }}
                        <StudioIcon name="arrowRight" size="h-3.5 w-3.5" />
                      </button>
                    </li>
                  </ul>
                </div>

                <div class="card p-4">
                  <h2 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                    <StudioIcon name="package" size="h-4 w-4" class="text-brand-300" />
                    Phân bố gói cước
                    <button class="tool-btn ml-auto" @click="goTo('plans')">Quản lý gói</button>
                  </h2>
                  <p class="mt-1 text-body text-cream-300">{{ fmtNum(totalPlanUsers) }} người dùng đã được gán gói.</p>
                  <ul class="mt-3 space-y-3">
                    <li v-for="p in dashboardPlans" :key="p.id">
                      <div class="flex items-center gap-2 text-body">
                        <span class="min-w-0 flex-1 truncate font-semibold text-cream-100">{{ p.name }}</span>
                        <span class="text-cream-300">{{ fmtNum(p.users_count) }} người · {{ planShare(p.users_count) }}%</span>
                      </div>
                      <div class="mt-1 h-2 overflow-hidden rounded-full bg-ink-700">
                        <div class="h-full rounded-full bg-brand-500" :style="{ width: (Number(p.users_count) || 0) / maxPlanCount * 100 + '%' }"></div>
                      </div>
                    </li>
                    <li v-if="!dashboardPlans.length" class="text-xs text-cream-300">Chưa có gói cước nào — tạo gói đầu tiên ở mục Gói cước.</li>
                  </ul>
                </div>
              </div>

              <div class="card flex flex-wrap items-center gap-2 p-4">
                <p class="mr-auto text-body font-semibold uppercase tracking-wide text-cream-300">Thao tác nhanh</p>
                <button v-if="isSuper" class="btn-brand btn-sm" @click="openCreateUser()"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm người dùng</button>
                <button class="btn-outline btn-sm" @click="openCreatePlan()"><StudioIcon name="package" size="h-3.5 w-3.5" /> Thêm gói cước</button>
                <button class="btn-outline btn-sm" @click="goTo('ledger')"><StudioIcon name="receipt" size="h-3.5 w-3.5" /> Xem sổ credit</button>
                <button class="btn-outline btn-sm" @click="goTo('gui')"><StudioIcon name="palette" size="h-3.5 w-3.5" /> Thanh công cụ Studio</button>
              </div>
            </template>
          </section>

          <!-- ───── NGƯỜI DÙNG ───── -->
          <section v-show="section === 'users'" class="space-y-5">
            <div v-if="!isSuper" class="card flex flex-col items-center gap-2 p-8 text-center">
              <StudioIcon name="shieldCheck" size="h-6 w-6" class="text-warn" />
              <p class="text-sm font-semibold text-cream-100">Không có quyền quản lý tài khoản</p>
              <p class="max-w-md text-body text-cream-300">
                Danh sách và thao tác trên người dùng chỉ dành cho Owner (super admin) — máy chủ trả 403 cho vai trò
                «{{ (me && me.role_label) || 'Quản trị' }}». Bạn vẫn xem được Tổng quan · Gói cước · Sổ credit · Giao diện.
              </p>
              <button class="btn-outline btn-sm mt-1" @click="goTo('dashboard')">Về Tổng quan</button>
            </div>

            <template v-else>
              <div class="card p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                      <StudioIcon name="users" size="h-4 w-4" class="text-brand-300" /> Người dùng
                      <span :class="[BADGE, BADGE_TONE.neutral]">{{ fmtNum(usersData.total) }}</span>
                    </h2>
                    <p class="mt-1 max-w-2xl text-xs text-cream-300">
                      Tạo · sửa · khoá · cấp credit · đặt lại mật khẩu. Mọi biến động credit đều ghi vào Sổ credit.
                    </p>
                  </div>
                  <button class="btn-brand btn-sm" @click="openCreateUser()"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm người dùng</button>
                </div>

                <form class="mt-4 flex flex-wrap items-center gap-2" @submit.prevent="usersData.page = 1; loadUsers()">
                  <div class="relative min-w-[13rem] flex-1">
                    <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-300" />
                    <input v-model="f.userSearch" type="search" aria-label="Tìm người dùng" class="input !py-2 !pl-9 text-xs" placeholder="Tìm theo tên, email hoặc SĐT…">
                  </div>
                  <select v-model="f.userRole" aria-label="Lọc theo vai trò" class="input !w-auto !py-2 text-xs" @change="usersData.page = 1; loadUsers()">
                    <option value="">Mọi vai trò</option>
                    <option value="super_admin">Owner</option>
                    <option value="admin">Quản trị</option>
                    <option value="customer">Khách hàng</option>
                  </select>
                  <select v-model="f.userStatus" aria-label="Lọc theo trạng thái" class="input !w-auto !py-2 text-xs" @change="usersData.page = 1; loadUsers()">
                    <option value="">Mọi trạng thái</option>
                    <option value="active">Đang hoạt động</option>
                    <option value="inactive">Bị khoá</option>
                  </select>
                  <select v-model.number="f.userPerPage" aria-label="Số dòng mỗi trang" class="input !w-auto !py-2 text-xs" @change="usersData.page = 1; loadUsers()">
                    <option :value="20">20 dòng</option>
                    <option :value="50">50 dòng</option>
                    <option :value="100">100 dòng</option>
                  </select>
                  <button type="submit" class="tool-btn"><StudioIcon name="search" size="h-3.5 w-3.5" /> Tìm</button>
                </form>
              </div>

              <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                  <table class="w-full min-w-[900px] text-left text-xs">
                    <thead>
                      <tr class="border-b border-ink-700 text-cream-300">
                        <th class="px-4 py-3 font-semibold">Người dùng</th>
                        <th class="px-3 py-3 font-semibold">Vai trò</th>
                        <th class="px-3 py-3 text-right font-semibold">Credit</th>
                        <th class="px-3 py-3 font-semibold">Gói</th>
                        <th class="px-3 py-3 text-right font-semibold">Ảnh</th>
                        <th class="px-3 py-3 font-semibold">Ngày tạo</th>
                        <th class="px-4 py-3 text-right font-semibold">Hành động</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-700/60">
                      <tr v-for="u in usersData.users" :key="u.id" class="motion-row hover:bg-ink-800/50">
                        <td class="px-4 py-3">
                          <div class="flex items-center gap-2.5">
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-600/25 text-xs font-bold text-brand-200">{{ (u.name || '?').charAt(0).toUpperCase() }}</span>
                            <div class="min-w-0 leading-tight">
                              <p class="flex items-center gap-1.5 font-semibold text-cream-50">
                                {{ u.name }}
                                <span v-if="u.id === (me && me.id)" :class="[BADGE, BADGE_TONE.info]">bạn</span>
                                <span v-if="!u.is_active" :class="[BADGE, BADGE_TONE.danger]">đã khoá</span>
                              </p>
                              <p class="truncate text-body text-cream-300">{{ u.email }}<span v-if="u.phone"> · {{ u.phone }}</span></p>
                            </div>
                          </div>
                        </td>
                        <td class="px-3 py-3"><span :class="roleMeta(u.role).cls" class="rounded-full px-2 py-0.5 text-label font-semibold" :title="'role: ' + u.role">{{ u.role_label || roleMeta(u.role).label }}</span></td>
                        <td class="px-3 py-3 text-right font-semibold text-cream-50">{{ fmtNum(u.credits_balance) }}</td>
                        <td class="px-3 py-3">
                          <span v-if="u.plan" class="text-cream-200">{{ u.plan.name }}</span>
                          <span v-else class="text-cream-300">— chưa gán —</span>
                        </td>
                        <td class="px-3 py-3 text-right text-cream-200">{{ fmtNum(u.generations_count) }}</td>
                        <td class="px-3 py-3 text-cream-300">{{ u.created_at }}</td>
                        <td class="px-4 py-3">
                          <div class="flex flex-wrap items-center justify-end gap-1.5">
                            <button class="tool-btn" @click="openEditUser(u)"><StudioIcon name="pencil" size="h-3.5 w-3.5" /> Sửa</button>
                            <button class="icon-btn" title="Cộng / trừ credit" :aria-label="'Cộng trừ credit cho ' + u.name" @click="openCredit(u)"><StudioIcon name="coins" size="h-4 w-4" /></button>
                            <button class="icon-btn" title="Đặt lại mật khẩu" :aria-label="'Đặt lại mật khẩu cho ' + u.name" @click="openPwd(u)"><StudioIcon name="key" size="h-4 w-4" /></button>
                            <button class="icon-btn" title="Xem sổ credit của người này" :aria-label="'Xem sổ credit của ' + u.name" @click="openUserLedger(u)"><StudioIcon name="receipt" size="h-4 w-4" /></button>
                            <button v-if="u.is_active" class="icon-btn" title="Khoá tài khoản" :aria-label="'Khoá tài khoản ' + u.name" :disabled="u.id === (me && me.id)" @click="askBanUser(u)"><StudioIcon name="lock" size="h-4 w-4" /></button>
                            <button v-else class="icon-btn !text-ok" title="Mở khoá tài khoản" :aria-label="'Mở khoá tài khoản ' + u.name" @click="toggleUserActive(u, true)"><StudioIcon name="lockOpen" size="h-4 w-4" /></button>
                            <button class="icon-btn !text-danger hover:!bg-red-500/15" title="Xoá người dùng" :aria-label="'Xoá người dùng ' + u.name" :disabled="u.id === (me && me.id)" @click="askDeleteUser(u)"><StudioIcon name="userX" size="h-4 w-4" /></button>
                          </div>
                        </td>
                      </tr>
                      <tr v-if="!usersData.users.length && !loading.users">
                        <td colspan="7" class="px-4 py-10 text-center text-xs text-cream-300">
                          Không có người dùng nào khớp bộ lọc.
                          <button class="ml-1 underline" @click="f.userSearch = ''; f.userRole = ''; f.userStatus = ''; usersData.page = 1; loadUsers()">Xoá bộ lọc</button>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-ink-700 px-4 py-3">
                  <span class="text-body text-cream-300">{{ pageInfo(usersData) }}<span v-if="loading.users" class="ml-1">· đang tải…</span></span>
                  <div class="flex gap-1.5">
                    <button :disabled="!canPrev(usersData) || loading.users" class="tool-btn" @click="usersData.page--; loadUsers()"><StudioIcon name="chevronLeft" size="h-3.5 w-3.5" /> Trước</button>
                    <button :disabled="!canNext(usersData) || loading.users" class="tool-btn" @click="usersData.page++; loadUsers()">Sau <StudioIcon name="chevronRight" size="h-3.5 w-3.5" /></button>
                  </div>
                </div>
              </div>
            </template>
          </section>

          <!-- ───── GÓI CƯỚC ───── -->
          <section v-show="section === 'plans'" class="space-y-5">
            <div class="card p-4">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="package" size="h-4 w-4" class="text-brand-300" /> Gói cước
                    <span :class="[BADGE, BADGE_TONE.neutral]">{{ plansData.length }}</span>
                  </h2>
                  <p class="mt-1 max-w-2xl text-xs text-cream-300">
                    Giá VNĐ, credit theo tháng, chi phí credit mỗi ảnh/video. Gói đang có người dùng thì nên ẨN thay vì xoá để giữ lịch sử.
                  </p>
                </div>
                <button class="btn-brand btn-sm" @click="openCreatePlan()"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm gói</button>
              </div>
              <div class="mt-4 flex flex-wrap items-center gap-2">
                <div class="relative min-w-[13rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-300" />
                  <input v-model="planSearch" type="search" aria-label="Tìm gói cước" class="input !py-2 !pl-9 text-xs" placeholder="Tìm theo tên, slug hoặc mô tả…">
                </div>
                <span class="text-body text-cream-300">{{ countText(filteredPlans.length, plansData.length, 'gói') }}</span>
              </div>
            </div>

            <div v-if="loading.plans && !plansData.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div v-for="i in 4" :key="i" class="card h-56 animate-pulse"></div>
            </div>
            <div v-else-if="!plansData.length" class="card flex flex-col items-center gap-2 p-10 text-center">
              <StudioIcon name="package" size="h-6 w-6" class="text-cream-300" />
              <p class="text-sm font-semibold text-cream-100">Chưa có gói cước nào</p>
              <p class="max-w-md text-body text-cream-300">Tạo gói đầu tiên để người dùng tự đăng ký. Gói mặc định được gán cho tài khoản mới.</p>
              <button class="btn-brand btn-sm mt-1" @click="openCreatePlan()">Thêm gói cước</button>
            </div>
            <div v-else-if="!filteredPlans.length" class="card p-8 text-center text-xs text-cream-300">
              Không có gói nào khớp « {{ planSearch }} ».
              <button class="ml-1 underline" @click="planSearch = ''">Xoá tìm kiếm</button>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              <article v-for="p in filteredPlans" :key="p.id" class="card flex flex-col p-5" :class="p.is_active ? '' : 'border-dashed'">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="font-display text-base font-semibold text-cream-50">{{ p.name }}</h3>
                  <span v-if="p.is_default" :class="[BADGE, BADGE_TONE.warn]"><StudioIcon name="target" size="h-3 w-3" /> Mặc định</span>
                  <span :class="[BADGE, p.is_active ? BADGE_TONE.ok : BADGE_TONE.neutral]">{{ p.is_active ? 'Đang mở bán' : 'Đang ẩn' }}</span>
                  <span :class="[BADGE, BADGE_TONE.info]" :title="'Số người dùng đang gán gói này'">{{ fmtNum(p.users_count) }} người</span>
                  <!-- [Modules] Gói cước gắn liền với quyền cấp tính năng: hiện NGAY số tính năng (công tắc)
                       và số ghi chú hiển thị (nhập tay) để không phải mở form mới biết. -->
                  <span :class="[BADGE, (p.modules_count || 0) ? BADGE_TONE.brand : BADGE_TONE.warn]"
                        :title="'Tính năng gói này CẤP cho khách: ' + ((p.module_names || []).slice(0, 12).join(' · ') || 'chưa cấp tính năng nào')">
                    <StudioIcon name="puzzle" size="h-3 w-3" /> {{ p.modules_count || 0 }} tính năng
                  </span>
                  <span v-if="(p.manual_features || []).length" :class="[BADGE, BADGE_TONE.neutral]"
                        :title="'Ghi chú hiển thị (chỉ để khách đọc, không cấp quyền): ' + (p.manual_features || []).join(' · ')">
                    <StudioIcon name="info" size="h-3 w-3" /> {{ p.manual_features.length }} ghi chú
                  </span>
                </div>
                <p class="mt-1 min-h-[2rem] text-xs text-cream-300">{{ p.tagline || '—' }}</p>
                <div class="mt-2 flex items-baseline gap-1.5">
                  <span class="font-display text-2xl font-semibold text-cream-50">{{ planPrice(p) }}</span>
                  <span v-if="p.price_vnd > 0" class="text-body text-cream-300">/ tháng</span>
                </div>
                <p class="mt-1.5 text-sm font-semibold text-brand-200">
                  {{ fmtNum(p.credits_per_month) }} credit / tháng
                  <span v-if="p.bonus_credits" class="text-body font-normal text-cream-300">(+{{ fmtNum(p.bonus_credits) }} tặng lần đầu)</span>
                </p>
                <ul class="mt-3 flex-1 space-y-1.5">
                  <li v-for="(ft, i) in p.features" :key="i" class="flex items-start gap-1.5 text-body-lg text-cream-200">
                    <StudioIcon name="check" size="h-3.5 w-3.5" class="mt-0.5 shrink-0 text-ok" />{{ ft }}
                  </li>
                  <li v-if="!p.features || !p.features.length" class="text-body text-cream-300">Chưa khai đặc quyền.</li>
                </ul>
                <div class="mt-3 flex flex-wrap gap-1.5 border-t border-ink-700 pt-3 text-label text-cream-300">
                  <span :class="[BADGE, BADGE_TONE.neutral]"><StudioIcon name="image" size="h-3 w-3" /> {{ p.image_credit_cost }} credit/ảnh</span>
                  <span :class="[BADGE, BADGE_TONE.neutral]"><StudioIcon name="film" size="h-3 w-3" /> {{ p.video_credit_cost }} credit/video</span>
                  <span :class="[BADGE, BADGE_TONE.neutral]">{{ p.resolution_cap }}</span>
                  <span :class="[BADGE, p.seats > 1 ? BADGE_TONE.ok : BADGE_TONE.neutral]" title="Số ghế: số người dùng chung gói này">
                    <StudioIcon name="users" size="h-3 w-3" /> {{ p.seats_label || (p.seats + ' người') }}
                  </span>
                  <span :class="[BADGE, BADGE_TONE.neutral]" title="Thứ tự sắp xếp">#{{ p.sort }}</span>
                </div>
                <div class="mt-3 flex flex-wrap gap-1.5">
                  <button class="tool-btn" @click="openEditPlan(p)"><StudioIcon name="pencil" size="h-3.5 w-3.5" /> Sửa</button>
                  <button v-if="p.is_active" class="tool-btn" @click="askTogglePlan(p, false)"><StudioIcon name="eyeOff" size="h-3.5 w-3.5" /> Ẩn gói</button>
                  <button v-else class="tool-btn" @click="askTogglePlan(p, true)"><StudioIcon name="eye" size="h-3.5 w-3.5" /> Mở bán</button>
                  <button class="tool-btn !text-danger hover:!bg-red-500/15" @click="askDeletePlan(p)"><StudioIcon name="trash" size="h-3.5 w-3.5" /> Xoá</button>
                </div>
              </article>
            </div>
          </section>

          <!-- ───── SỔ CREDIT ───── -->
          <section v-show="section === 'ledger'" class="space-y-5">
            <div class="card p-4">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="receipt" size="h-4 w-4" class="text-brand-300" /> Sổ credit
                    <span :class="[BADGE, BADGE_TONE.neutral]">{{ fmtNum(ledgerData.total) }}</span>
                  </h2>
                  <p class="mt-1 max-w-2xl text-xs text-cream-300">
                    Mọi biến động credit (tiêu · hoàn · tặng · điều chỉnh · nạp gói) đều ghi ở đây, kèm người thực hiện.
                  </p>
                </div>
                <span v-if="l.userId" :class="[BADGE, BADGE_TONE.brand]">
                  <StudioIcon name="filter" size="h-3 w-3" /> đang lọc: {{ l.userName }}
                  <button class="ml-1 underline" @click="l.userId = null; l.userName = ''; ledgerData.page = 1; loadLedger()">bỏ lọc</button>
                </span>
              </div>
              <form class="mt-4 flex flex-wrap items-center gap-2" @submit.prevent="ledgerData.page = 1; loadLedger()">
                <div class="relative min-w-[13rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-300" />
                  <input v-model="l.search" type="search" aria-label="Tìm theo người dùng" class="input !py-2 !pl-9 text-xs" placeholder="Tìm theo tên hoặc email người dùng…">
                </div>
                <select v-model="l.type" aria-label="Lọc theo loại giao dịch" class="input !w-auto !py-2 text-xs" @change="ledgerData.page = 1; loadLedger()">
                  <option value="">Mọi loại</option>
                  <option v-for="(m, k) in TYPE_META" :key="k" :value="k">{{ m.label }}</option>
                </select>
                <select v-model.number="l.perPage" aria-label="Số dòng mỗi trang" class="input !w-auto !py-2 text-xs" @change="ledgerData.page = 1; loadLedger()">
                  <option :value="20">20 dòng</option>
                  <option :value="50">50 dòng</option>
                  <option :value="100">100 dòng</option>
                </select>
                <button type="submit" class="tool-btn"><StudioIcon name="search" size="h-3.5 w-3.5" /> Tìm</button>
              </form>
            </div>

            <div class="card overflow-hidden">
              <div class="overflow-x-auto">
                <table class="w-full min-w-[880px] text-left text-xs">
                  <thead>
                    <tr class="border-b border-ink-700 text-cream-300">
                      <th class="px-4 py-3 font-semibold">Thời gian</th>
                      <th class="px-3 py-3 font-semibold">Người dùng</th>
                      <th class="px-3 py-3 font-semibold">Loại</th>
                      <th class="px-3 py-3 text-right font-semibold">Số credit</th>
                      <th class="px-3 py-3 text-right font-semibold">Số dư sau</th>
                      <th class="px-3 py-3 font-semibold">Thực hiện bởi</th>
                      <th class="px-3 py-3 font-semibold">Ghi chú</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-ink-700/60">
                    <tr v-for="t in ledgerData.transactions" :key="t.id" class="motion-row hover:bg-ink-800/50">
                      <td class="px-4 py-2.5 whitespace-nowrap text-cream-300">{{ t.created_at }}</td>
                      <td class="px-3 py-2.5">
                        <p class="font-semibold text-cream-50">{{ t.user ? t.user.name : '—' }}</p>
                        <p v-if="t.user" class="text-label text-cream-300">{{ t.user.email }}</p>
                      </td>
                      <td class="px-3 py-2.5"><span :class="typeMeta(t.type).cls" class="rounded-full px-2 py-0.5 text-label font-semibold" :title="'type: ' + t.type">{{ t.type_label || typeMeta(t.type).label }}</span></td>
                      <td class="px-3 py-2.5 text-right font-semibold" :class="t.amount > 0 ? 'text-ok' : 'text-danger'">{{ t.amount > 0 ? '+' : '' }}{{ fmtNum(t.amount) }}</td>
                      <td class="px-3 py-2.5 text-right text-cream-200">{{ fmtNum(t.balance_after) }}</td>
                      <td class="px-3 py-2.5 text-cream-200">
                        <span v-if="t.admin" class="flex items-center gap-1.5"><StudioIcon name="shieldCheck" size="h-3.5 w-3.5" class="text-gold-400" /> {{ t.admin.name }}</span>
                        <span v-else class="text-cream-300">Hệ thống</span>
                      </td>
                      <td class="px-3 py-2.5 text-cream-300">{{ t.note || '—' }}</td>
                    </tr>
                    <tr v-if="!ledgerData.transactions.length && !loading.ledger">
                      <td colspan="7" class="px-4 py-10 text-center text-xs text-cream-300">
                        Chưa có giao dịch nào khớp bộ lọc.
                        <button v-if="l.search || l.type || l.userId" class="ml-1 underline" @click="l.search = ''; l.type = ''; l.userId = null; l.userName = ''; ledgerData.page = 1; loadLedger()">Xoá bộ lọc</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="flex flex-wrap items-center justify-between gap-2 border-t border-ink-700 px-4 py-3">
                <span class="text-body text-cream-300">{{ pageInfo(ledgerData) }}<span v-if="loading.ledger" class="ml-1">· đang tải…</span></span>
                <div class="flex gap-1.5">
                  <button :disabled="!canPrev(ledgerData) || loading.ledger" class="tool-btn" @click="ledgerData.page--; loadLedger()"><StudioIcon name="chevronLeft" size="h-3.5 w-3.5" /> Trước</button>
                  <button :disabled="!canNext(ledgerData) || loading.ledger" class="tool-btn" @click="ledgerData.page++; loadLedger()">Sau <StudioIcon name="chevronRight" size="h-3.5 w-3.5" /></button>
                </div>
              </div>
            </div>
          </section>

          <!-- ───── GIAO DIỆN STUDIO ───── -->
          <!-- ═════════ YÊU CẦU NÂNG CẤP (Q2) ═════════ -->
          <section v-show="section === 'upgrades'" class="space-y-5">
            <div class="card p-4">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="coins" size="h-4 w-4" class="text-brand-300" /> Yêu cầu nâng cấp gói
                    <span :class="[BADGE, pendingUpgrades ? BADGE_TONE.warn : BADGE_TONE.neutral]">{{ pendingUpgrades }} đang chờ</span>
                  </h2>
                  <p class="mt-1 max-w-3xl text-xs text-cream-300">
                    Hệ thống chưa có cổng thanh toán: khách gửi yêu cầu (nhận MÃ như <span class="font-mono">UP-2609-0001</span>), chuyển khoản theo mã đó,
                    rồi bạn <b class="text-cream-100">kích hoạt gói</b> ở đây. Chỉ Owner kích hoạt được — người khác vẫn đánh dấu «đã liên hệ» để cả nhóm biết ai đang chăm khách.
                  </p>
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                  <button v-for="st in [['', 'Tất cả'], ['pending', 'Chờ xử lý'], ['contacted', 'Đã liên hệ'], ['activated', 'Đã kích hoạt'], ['cancelled', 'Đã huỷ']]"
                          :key="st[0]" class="tool-btn" :class="upFilter === st[0] ? 'is-active' : ''"
                          @click="upFilter = st[0]; loadUpgrades()">
                    {{ st[1] }}
                  </button>
                  <button class="tool-btn" :disabled="loading.upgrades" title="Nạp lại hàng đợi" @click="loadUpgrades()">
                    <StudioIcon name="refresh" size="h-3.5 w-3.5" /> Nạp lại
                  </button>
                </div>
              </div>
            </div>

            <!-- Thông tin nhận tiền: MỘT nguồn cho trang giá + popup Studio + hoá đơn -->
            <div class="card p-4">
              <h3 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                <StudioIcon name="receipt" size="h-4 w-4" class="text-brand-300" /> Thông tin nhận tiền &amp; hỗ trợ
                <span v-if="!paymentForm.bank_account" :class="[BADGE, BADGE_TONE.warn]">chưa điền STK</span>
              </h3>
              <p class="mt-1 text-xs text-cream-300">
                Thông tin này hiện ở <a href="/bang-gia" class="link" target="_blank" rel="noopener">trang giá</a> và trong popup «Gói &amp; credit» của Studio.
                Để trống thì hệ thống <b class="text-cream-100">không bịa số tài khoản</b> — chỉ báo «FabrikAI sẽ gửi thông tin thanh toán».
              </p>
              <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block text-label font-semibold uppercase tracking-wide text-cream-300">
                  Ngân hàng
                  <input v-model="paymentForm.bank_name" maxlength="120" class="input mt-1 !py-2 text-xs" placeholder="Vietcombank">
                </label>
                <label class="block text-label font-semibold uppercase tracking-wide text-cream-300">
                  Số tài khoản
                  <input v-model="paymentForm.bank_account" maxlength="60" class="input mt-1 !py-2 text-xs" placeholder="0123456789">
                </label>
                <label class="block text-label font-semibold uppercase tracking-wide text-cream-300">
                  Chủ tài khoản
                  <input v-model="paymentForm.bank_holder" maxlength="120" class="input mt-1 !py-2 text-xs" placeholder="CONG TY FABRIKAI">
                </label>
                <label class="block text-label font-semibold uppercase tracking-wide text-cream-300">
                  Chi nhánh
                  <input v-model="paymentForm.bank_branch" maxlength="120" class="input mt-1 !py-2 text-xs" placeholder="CN Sài Gòn">
                </label>
                <label class="block text-label font-semibold uppercase tracking-wide text-cream-300">
                  Hotline
                  <input v-model="paymentForm.support_phone" maxlength="32" class="input mt-1 !py-2 text-xs" placeholder="0901234567">
                </label>
                <label class="block text-label font-semibold uppercase tracking-wide text-cream-300">
                  Email hỗ trợ
                  <input v-model="paymentForm.support_email" maxlength="120" class="input mt-1 !py-2 text-xs" placeholder="hotro@fabrikai.shop">
                </label>
                <label class="block text-label font-semibold uppercase tracking-wide text-cream-300">
                  Zalo
                  <input v-model="paymentForm.support_zalo" maxlength="120" class="input mt-1 !py-2 text-xs" placeholder="0901234567">
                </label>
                <label class="block text-label font-semibold uppercase tracking-wide text-cream-300">
                  Giờ làm việc
                  <input v-model="paymentForm.support_hours" maxlength="120" class="input mt-1 !py-2 text-xs" placeholder="8h30 – 18h, thứ 2 – thứ 7">
                </label>
              </div>
              <div class="mt-3 flex flex-wrap gap-2">
                <button class="btn-brand btn-sm" :disabled="loading.upgrades" @click="savePayment()">
                  <StudioIcon name="save" size="h-3.5 w-3.5" /> Lưu thông tin nhận tiền
                </button>
              </div>
            </div>

            <div class="card overflow-hidden">
              <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-left text-xs">
                  <thead>
                    <tr class="border-b border-ink-700 text-cream-300">
                      <th class="px-4 py-3 font-semibold">Mã · thời gian</th>
                      <th class="px-3 py-3 font-semibold">Khách</th>
                      <th class="px-3 py-3 font-semibold">Gói · số tháng</th>
                      <th class="px-3 py-3 text-right font-semibold">Số tiền</th>
                      <th class="px-3 py-3 font-semibold">Thanh toán</th>
                      <th class="px-3 py-3 font-semibold">Trạng thái</th>
                      <th class="px-3 py-3 text-right font-semibold">Xử lý</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-ink-700/60">
                    <tr v-for="r in upgradesData.requests" :key="r.id" class="motion-row align-top hover:bg-ink-800/40">
                      <td class="px-4 py-3">
                        <span class="font-mono text-body font-semibold text-cream-100">{{ r.code }}</span>
                        <span class="mt-0.5 block text-label text-cream-300">{{ r.created_at }}</span>
                        <span v-if="r.status === 'pending' && r.age_hours >= 24" :class="[BADGE, BADGE_TONE.danger]" class="mt-1">chờ {{ r.age_hours }} giờ</span>
                      </td>
                      <td class="px-3 py-3">
                        <span class="block font-semibold text-cream-100">{{ r.user ? r.user.name : '—' }}</span>
                        <span class="block text-label text-cream-300">{{ r.user ? r.user.email : '' }}</span>
                        <a v-if="r.contact_phone" :href="'tel:' + r.contact_phone" class="mt-0.5 inline-flex items-center gap-1 text-label text-brand-200 hover:underline">
                          <StudioIcon name="user" size="h-3 w-3" /> {{ r.contact_name || r.user?.name }} · {{ r.contact_phone }}
                        </a>
                      </td>
                      <td class="px-3 py-3">
                        <span class="block text-cream-100">{{ r.plan ? r.plan.name : '—' }}</span>
                        <span class="block text-label text-cream-300">{{ r.months }} tháng</span>
                      </td>
                      <td class="px-3 py-3 text-right font-semibold text-cream-50">{{ r.amount_label }}</td>
                      <td class="px-3 py-3">
                        <span class="block text-cream-200">{{ r.method_label }}</span>
                        <span v-if="r.note" class="mt-0.5 block max-w-[16rem] whitespace-pre-line text-label text-cream-300">{{ r.note }}</span>
                      </td>
                      <td class="px-3 py-3">
                        <span :class="[BADGE, BADGE_TONE[upgradeTone(r.status)]]">{{ r.status_label }}</span>
                        <span v-if="r.handler" class="mt-1 block text-label text-cream-300">{{ r.handler }} · {{ r.handled_at }}</span>
                        <span v-if="r.admin_note" class="mt-1 block max-w-[14rem] text-label italic text-cream-300">{{ r.admin_note }}</span>
                      </td>
                      <td class="px-3 py-3">
                        <div class="flex flex-wrap justify-end gap-1.5">
                          <template v-if="r.status === 'activated'">
                            <span :class="[BADGE, BADGE_TONE.ok]"><StudioIcon name="check" size="h-3 w-3" /> đã cấp gói</span>
                          </template>
                          <template v-else>
                            <!-- Nhãn KHÁC nút lọc cùng tên ("Đã liên hệ" ở thanh lọc) để không nhầm khi bấm
                                 và để người dùng biết đây là thao tác trên khách, không phải bộ lọc. -->
                            <button v-if="r.status === 'pending'" class="tool-btn" @click="setUpgradeStatus(r, 'contacted')"
                                    title="Đánh dấu là đã gọi/zalo cho khách này">
                              <StudioIcon name="user" size="h-3.5 w-3.5" /> Đã liên hệ khách
                            </button>
                            <button v-if="isSuper" class="btn-brand btn-sm" @click="activateUpgrade(r)"
                                    :title="'Kích hoạt gói ' + (r.plan ? r.plan.name : '') + ' cho ' + (r.user ? r.user.name : '') + ' sau khi đã nhận ' + r.amount_label">
                              <StudioIcon name="check" size="h-3.5 w-3.5" /> Kích hoạt
                            </button>
                            <button class="tool-btn !text-danger hover:!bg-red-500/15" @click="setUpgradeStatus(r, 'cancelled')" title="Huỷ yêu cầu (khách đổi ý / trùng)">
                              <StudioIcon name="ban" size="h-3.5 w-3.5" /> Huỷ
                            </button>
                          </template>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-if="loading.upgrades" class="px-4 py-4 text-xs text-cream-300">Đang nạp yêu cầu…</p>
              <p v-else-if="!upgradesData.requests.length" class="px-4 py-4 text-xs text-cream-300">
                Chưa có yêu cầu nâng cấp nào{{ upFilter ? ' ở trạng thái này' : '' }}. Khi khách bấm «Yêu cầu nâng cấp» trong Studio, yêu cầu sẽ hiện ở đây kèm số điện thoại liên hệ.
              </p>
            </div>

            <p class="rounded-lg border border-ink-700 bg-ink-900/60 p-3 text-body leading-relaxed text-cream-300">
              <b class="text-cream-100">Quy trình chuẩn:</b> nhận yêu cầu → gọi/zalo theo số khách để lại → khách chuyển khoản với nội dung là <b class="text-cream-100">mã yêu cầu</b> →
              đối chiếu sao kê → bấm <b class="text-cream-100">Kích hoạt</b> (hệ thống tự gán gói, đúng số tháng, cấp bonus lần đầu và credit của chu kỳ đầu, có ghi vết ai kích hoạt).
              Khách gửi lại cùng một gói thì hệ thống dùng lại yêu cầu cũ nên không có yêu cầu trùng.
            </p>
          </section>

          <!-- ═════════ TÍNH NĂNG & GÓI (Modules) ═════════ -->
          <section v-show="section === 'modules'" class="space-y-5">
            <div class="card p-4">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="puzzle" size="h-4 w-4" class="text-brand-300" /> Tính năng &amp; gói
                    <span :class="[BADGE, BADGE_TONE.neutral]">{{ modulesData.total_modules }} module</span>
                    <span v-if="(modulesData.ungranted || []).length" :class="[BADGE, BADGE_TONE.warn]"
                          :title="'Module chưa gói nào cấp: ' + (modulesData.ungranted || []).join(', ')">
                      <StudioIcon name="alertTriangle" size="h-3 w-3" /> {{ modulesData.ungranted.length }} chưa gói nào cấp
                    </span>
                  </h2>
                  <p class="mt-1 max-w-3xl text-xs text-cream-300">
                    Mọi tính năng của Studio được khai ở <b class="text-cream-100">một nguồn duy nhất</b> (module registry):
                    tắt/mở ở đây là tắt/mở thật ở máy chủ, và tick vào <b class="text-cream-100">gói</b> nghĩa là gói đó cấp tính năng cho khách.
                    Thêm tính năng mới thì màn này tự có thêm dòng — không phải cấu hình lại.
                  </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <div class="relative min-w-[12rem]">
                    <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-300" />
                    <input v-model="moduleSearch" type="search" aria-label="Tìm tính năng" class="input !py-2 !pl-9 text-xs" placeholder="Tìm tính năng…">
                  </div>
                  <button class="tool-btn" :disabled="loading.modules" title="Nạp lại" @click="loadModules()">
                    <StudioIcon name="refresh" size="h-3.5 w-3.5" /> Nạp lại
                  </button>
                </div>
              </div>
              <p v-if="moduleDirty" class="mt-3 rounded border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-body text-warn">
                Có thay đổi chưa lưu — nhớ bấm <b>Lưu công tắc</b> (toàn cục) hoặc <b>Lưu</b> ở từng gói.
              </p>
            </div>

            <!-- Công tắc TOÀN CỤC -->
            <div class="card p-4">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                  <StudioIcon name="sliders" size="h-4 w-4" class="text-brand-300" /> Bật / tắt toàn hệ thống
                </h3>
                <span :class="[BADGE, globalDisabled.length ? BADGE_TONE.warn : BADGE_TONE.ok]">
                  {{ globalDisabled.length ? globalDisabled.length + ' đang tắt' : 'tất cả đang bật' }}
                </span>
                <button class="btn-brand btn-sm ml-auto" :disabled="loading.modules" @click="saveGlobalModules()">
                  <StudioIcon name="save" size="h-3.5 w-3.5" /> Lưu công tắc
                </button>
              </div>
              <p class="mt-1 text-xs text-cream-300">
                Tắt một tính năng ở đây là chặn ở máy chủ (khách gọi thẳng API cũng bị 403), <b class="text-cream-100">không ảnh hưởng tính năng khác</b>.
                Tài khoản quản trị vẫn vào được để hỗ trợ khách.
              </p>
              <div class="mt-3 space-y-3">
                <div v-for="(items, group) in moduleGroups" :key="group">
                  <p class="text-label font-semibold uppercase tracking-wide text-cream-300">{{ group }}</p>
                  <ul class="mt-1 divide-y divide-ink-700/60">
                    <li v-for="m in items" :key="m.id" class="flex flex-wrap items-center gap-2 py-2">
                      <StudioIcon :name="m.icon" size="h-4 w-4" class="text-cream-300" />
                      <span class="min-w-0 flex-1">
                        <span class="block text-xs font-semibold text-cream-100">{{ m.name }}</span>
                        <span class="block text-label text-cream-300">{{ m.summary }}</span>
                        <span v-if="m.depends_on.length" class="block text-label text-warn">cần: {{ m.depends_on.join(' · ') }}</span>
                      </span>
                      <span :class="[BADGE, moduleKindMeta(m.kind).cls]">{{ moduleKindMeta(m.kind).label }}</span>
                      <span class="hidden text-label text-cream-300 sm:inline">{{ plansWithModule(m.id).map((p) => p.name).join(' · ') || 'chưa gói nào' }}</span>
                      <button class="tool-btn" :class="globalDisabled.includes(m.id) ? '!text-danger' : '!text-ok'"
                              :title="globalDisabled.includes(m.id) ? 'Đang TẮT toàn hệ thống — bấm để bật' : 'Đang BẬT — bấm để tắt toàn hệ thống'"
                              @click="toggleGlobal(m.id)">
                        <StudioIcon :name="globalDisabled.includes(m.id) ? 'eyeOff' : 'eye'" size="h-3.5 w-3.5" />
                        {{ globalDisabled.includes(m.id) ? 'Đang tắt' : 'Đang bật' }}
                      </button>
                    </li>
                  </ul>
                </div>
              </div>
            </div>

            <!-- CÔNG TẮC THEO GÓI -->
            <div class="card p-4">
              <h3 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                <StudioIcon name="package" size="h-4 w-4" class="text-brand-300" /> Gói cấp tính năng nào
              </h3>
              <p class="mt-1 text-xs text-cream-300">
                Gói đăng ký chính là công tắc cấp phát: khách ở gói chỉ dùng được đúng những tính năng được tick.
                Nút <b class="text-cream-100">Áp đề xuất</b> lấy gợi ý từ bản khai module (gói miễn phí giữ phần cơ bản, gói cao có thêm video · trợ lý · xuất gói · ghế…).
              </p>
              <div class="mt-3 grid grid-cols-1 gap-3 xl:grid-cols-2">
                <div v-for="p in modulesData.plans" :key="p.slug" class="rounded-lg border border-ink-700 bg-ink-900/60 p-3">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold text-cream-100">{{ p.name }}</span>
                    <span :class="[BADGE, BADGE_TONE.neutral]">{{ p.price_label }}</span>
                    <span :class="[BADGE, (planModules[p.slug] || []).length ? BADGE_TONE.ok : BADGE_TONE.warn]">
                      {{ (planModules[p.slug] || []).length }}/{{ modulesData.total_modules }} tính năng
                    </span>
                    <span v-if="!p.is_active" :class="[BADGE, BADGE_TONE.warn]">đang ẩn</span>
                    <span v-if="(p.missing_suggested || []).length" :class="[BADGE, BADGE_TONE.info]"
                          :title="'Đề xuất chưa cấp: ' + (p.missing_suggested || []).join(', ')">
                      thiếu {{ p.missing_suggested.length }} so với đề xuất
                    </span>
                    <!-- Số người dùng: rút tính năng là đổi trải nghiệm của từng ấy người ⇒ hiện ngay tại đây. -->
                    <span :class="[BADGE, p.users_count ? BADGE_TONE.info : BADGE_TONE.neutral]"
                          :title="p.users_count ? 'Số người dùng đang ở gói này (sẽ thấy ổ khoá nếu bị rút tính năng)' : 'Chưa có người dùng nào ở gói này'">
                      <StudioIcon name="users" size="h-3 w-3" /> {{ p.users_count || 0 }} người dùng
                    </span>
                    <div class="ml-auto flex gap-1.5">
                      <button class="tool-btn !py-1 text-label" title="Áp đề xuất từ bản khai module (hỏi xác nhận kèm ảnh hưởng nếu gói đang có người dùng)" @click="applySuggested(p)">
                        <StudioIcon name="sparkles" size="h-3 w-3" /> Áp đề xuất
                      </button>
                      <button class="tool-btn !py-1 text-label" title="Cấp lại TOÀN BỘ tính năng đang mở cho gói này (khôi phục nếu lỡ rút nhầm)" @click="grantAllModules(p)">
                        <StudioIcon name="selectAll" size="h-3 w-3" /> Cấp tất cả
                      </button>
                      <button class="btn-brand btn-sm" @click="savePlanModules(p)">Lưu</button>
                    </div>
                  </div>
                  <div class="mt-2 max-h-72 space-y-2 overflow-y-auto pr-1">
                    <div v-for="(items, group) in moduleGroups" :key="p.slug + group">
                      <p class="text-label font-semibold uppercase tracking-wide text-cream-300">{{ group }}</p>
                      <div class="mt-0.5 flex flex-wrap gap-1">
                        <button v-for="m in items" :key="p.slug + m.id"
                                class="rounded border px-1.5 py-0.5 text-label transition"
                                :class="(planModules[p.slug] || []).includes(m.id)
                                  ? 'border-emerald-500/40 bg-emerald-500/15 text-ok'
                                  : 'border-ink-600 bg-ink-800 text-cream-300 hover:border-ink-500'"
                                :title="m.summary"
                                @click="toggleGrant(p.slug, m.id)">
                          <StudioIcon :name="(planModules[p.slug] || []).includes(m.id) ? 'check' : 'x'" size="h-3 w-3" class="mr-0.5 inline" />{{ m.name }}
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section v-show="section === 'gui'" class="space-y-5">
            <div class="card p-5">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="palette" size="h-4 w-4" class="text-brand-300" /> Thanh công cụ trái của Studio
                    <span :class="[BADGE, BADGE_TONE.neutral]">{{ guiItems.length }}</span>
                    <span v-if="guiHiddenCount" :class="[BADGE, BADGE_TONE.warn]">{{ guiHiddenCount }} đang ẩn</span>
                  </h2>
                  <p class="mt-1 max-w-2xl text-xs text-cream-300">
                    Đổi thứ tự · nhãn · icon · ẩn/hiện từng mục — áp dụng cho MỌI người dùng Studio.
                    Không thêm/xoá được mục vì mỗi mục gắn cứng một chức năng trong mã nguồn.
                  </p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                  <button class="tool-btn" :disabled="guiSaving" @click="askResetGui()"><StudioIcon name="undo" size="h-3.5 w-3.5" /> Khôi phục mặc định</button>
                  <button class="btn-brand btn-sm" :disabled="guiSaving || !guiDirty" @click="saveGui()">
                    <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ guiSaving ? 'Đang lưu…' : 'Lưu thay đổi' }}
                  </button>
                </div>
              </div>

              <div v-if="guiItems.length" class="mt-4 rounded-lg border border-ink-700 bg-ink-900/60 p-3">
                <p class="mb-2 flex items-center gap-2 text-label font-semibold uppercase tracking-wide text-cream-300">
                  Xem trước thứ tự &amp; icon
                  <span v-if="guiDirty" class="text-warn">· có thay đổi chưa lưu</span>
                </p>
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1">
                  <span v-for="it in guiPreview" :key="it.id"
                        class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-ink-800 text-brand-200"
                        :title="it.label + ' (' + it.id + ')'">
                    <StudioIcon :name="it.icon" size="h-5 w-5" />
                  </span>
                  <span v-if="!guiPreview.length" class="text-xs text-cream-300">Tất cả mục đang bị ẩn — thanh công cụ sẽ trống.</span>
                </div>
              </div>

              <div class="mt-4 flex flex-wrap items-center gap-2">
                <div class="relative min-w-[13rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-300" />
                  <input v-model="guiSearch" type="search" aria-label="Tìm mục" class="input !py-2 !pl-9 text-xs" placeholder="Tìm theo id, nhãn hoặc icon…">
                </div>
                <span class="text-body text-cream-300">{{ countText(filteredGui.length, guiItems.length, 'mục') }}</span>
              </div>

              <ul class="mt-3 space-y-2">
                <li v-for="it in filteredGui" :key="it.id" class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
                  <span class="w-6 shrink-0 text-center text-label font-semibold text-cream-300">{{ guiItems.indexOf(it) + 1 }}</span>
                  <span class="flex shrink-0 gap-1">
                    <button class="icon-btn" :disabled="guiItems.indexOf(it) === 0 || isGuiPinned(it)" title="Đưa lên" :aria-label="'Đưa ' + it.label + ' lên'" @click="moveGui(it, -1)"><StudioIcon name="chevronUp" size="h-4 w-4" /></button>
                    <button class="icon-btn" :disabled="guiItems.indexOf(it) === guiItems.length - 1 || isGuiPinned(it)" :title="isGuiPinned(it) ? 'Mục này luôn ở đáy thanh công cụ' : 'Đưa xuống'" :aria-label="'Đưa ' + it.label + ' xuống'" @click="moveGui(it, 1)"><StudioIcon name="chevronDown" size="h-4 w-4" /></button>
                  </span>
                  <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-ink-800 text-brand-200" title="Icon hiện tại"><StudioIcon :name="it.icon" size="h-5 w-5" /></span>
                  <select v-model="it.icon" class="input !w-44 !py-1.5 text-xs" :aria-label="'Icon cho ' + it.label" title="Chọn icon — danh sách lấy từ registry chung">
                    <option v-for="ic in guiIcons" :key="ic.name" :value="ic.name" :title="ic.note || ic.name">{{ ic.name }}</option>
                  </select>
                  <input v-model="it.label" type="text" maxlength="40" class="input !min-w-40 !flex-1 !py-1.5 text-xs" placeholder="Nhãn hiển thị" :aria-label="'Nhãn cho ' + it.id">
                  <code class="shrink-0 rounded bg-ink-800 px-1.5 py-0.5 text-label text-cream-300" title="Id — không đổi được">{{ it.id }}</code>
                  <span class="shrink-0 rounded px-1.5 py-0.5 text-label font-semibold" :class="(GUI_KIND[it.kind] || {}).cls || 'bg-ink-700 text-cream-300'" :title="(GUI_KIND[it.kind] || {}).hint || ''">{{ (GUI_KIND[it.kind] || {}).label || it.kind }}</span>
                  <span v-if="isGuiPinned(it)" :class="[BADGE, BADGE_TONE.info]" title="Luôn nằm ở đáy thanh công cụ">ghim đáy</span>
                  <label class="flex shrink-0 cursor-pointer items-center gap-1.5 text-body text-cream-200">
                    <input type="checkbox" v-model="it.visible" class="h-3.5 w-3.5 accent-brand-500"> Hiện
                  </label>
                </li>
                <li v-if="!guiItems.length" class="py-8 text-center text-xs text-cream-300">Đang tải cấu hình…</li>
                <li v-else-if="!filteredGui.length" class="py-8 text-center text-xs text-cream-300">
                  Không có mục nào khớp « {{ guiSearch }} ».
                  <button class="ml-1 underline" @click="guiSearch = ''">Xoá tìm kiếm</button>
                </li>
              </ul>

              <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-ink-700 pt-3">
                <button class="btn-brand btn-sm" :disabled="guiSaving || !guiDirty" @click="saveGui()">
                  <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ guiSaving ? 'Đang lưu…' : 'Lưu thay đổi' }}
                </button>
                <button class="tool-btn" :disabled="!guiDirty || guiSaving" @click="loadGui()"><StudioIcon name="undo" size="h-3.5 w-3.5" /> Hoàn tác</button>
                <span v-if="guiDirty" class="flex items-center gap-1.5 text-body text-warn"><StudioIcon name="alertTriangle" size="h-3.5 w-3.5" /> Có thay đổi chưa lưu</span>
                <span v-else class="flex items-center gap-1.5 text-body text-cream-300"><StudioIcon name="check" size="h-3.5 w-3.5" /> Đã lưu</span>
              </div>
            </div>

            <details class="card p-4">
              <summary class="cursor-pointer text-sm font-semibold text-cream-100">Trợ giúp · Ý nghĩa các loại mục</summary>
              <div class="mt-2 space-y-1.5 text-body text-cream-300">
                <p v-for="(m, k) in GUI_KIND" :key="k">· <b class="text-cream-100">{{ m.label }}</b> — {{ m.hint }}. Mục «ghim đáy» luôn nằm dưới cùng, không đổi được vị trí.</p>
                <p>· Nhãn tối đa 40 ký tự. Icon lấy từ registry dùng chung (thêm icon mới = thêm một khoá vào <code class="rounded bg-ink-800 px-1">resources/js/studio/icons.json</code>).</p>
                <p>· Lưu xong, người dùng Studio thấy thay đổi ở lần tải trang kế tiếp.</p>
              </div>
            </details>
          </section>
        </main>
      </div>
    </div>

    <!-- ═════════ Hộp thoại: người dùng ═════════ -->
    <BaseModal :model-value="userModal.open" :title="userModal.mode === 'create' ? 'Thêm người dùng' : 'Sửa người dùng'" @update:model-value="userModal.open = false">
      <form class="space-y-3" @submit.prevent="saveUser">
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <label class="label" for="u-name">Tên</label>
            <input id="u-name" v-model="userModal.form.name" class="input !py-2" :class="userModal.errors.name ? '!border-red-500/70' : ''" placeholder="VD: Nguyễn Văn A">
            <p v-if="userModal.errors.name" class="mt-1 text-body text-danger">{{ userModal.errors.name }}</p>
          </div>
          <div>
            <label class="label" for="u-email">Email</label>
            <input id="u-email" v-model="userModal.form.email" type="email" class="input !py-2" :class="userModal.errors.email ? '!border-red-500/70' : ''" placeholder="email@example.com">
            <p v-if="userModal.errors.email" class="mt-1 text-body text-danger">{{ userModal.errors.email }}</p>
          </div>
          <div>
            <label class="label" for="u-phone">Số điện thoại</label>
            <input id="u-phone" v-model="userModal.form.phone" class="input !py-2" placeholder="(tuỳ chọn)">
          </div>
          <div>
            <label class="label" for="u-role">Vai trò</label>
            <select id="u-role" v-model="userModal.form.role" class="input !py-2" :class="userModal.errors.role ? '!border-red-500/70' : ''">
              <option value="customer">Khách hàng</option>
              <option value="admin">Quản trị</option>
              <option value="super_admin">Owner (super admin)</option>
            </select>
            <p v-if="userModal.errors.role" class="mt-1 text-body text-danger">{{ userModal.errors.role }}</p>
          </div>
          <div>
            <label class="label" for="u-plan">Gói cước</label>
            <select id="u-plan" v-model="userModal.form.plan_id" class="input !py-2">
              <option value="">— Không gán —</option>
              <option v-for="p in plansData" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <div v-if="userModal.mode === 'create'" class="sm:col-span-2">
            <label class="label" for="u-pwd">Mật khẩu (tối thiểu 8 ký tự)</label>
            <input id="u-pwd" v-model="userModal.form.password" type="password" autocomplete="new-password" class="input !py-2 font-mono text-xs" :class="userModal.errors.password ? '!border-red-500/70' : ''">
            <p v-if="userModal.errors.password" class="mt-1 text-body text-danger">{{ userModal.errors.password }}</p>
          </div>
        </div>
        <div>
          <label class="flex items-center gap-2 text-xs text-cream-200">
            <input type="checkbox" v-model="userModal.form.is_active" class="h-4 w-4 accent-brand-500"> Tài khoản đang hoạt động
          </label>
          <p v-if="userModal.errors.is_active" class="mt-1 text-body text-danger">{{ userModal.errors.is_active }}</p>
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
          <button type="button" class="tool-btn" @click="userModal.open = false">Huỷ</button>
          <button type="submit" class="btn-brand btn-sm" :disabled="userModal.saving">
            <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ userModal.saving ? 'Đang lưu…' : (userModal.mode === 'create' ? 'Tạo người dùng' : 'Lưu thay đổi') }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- ═════════ Hộp thoại: cộng/trừ credit ═════════ -->
    <BaseModal :model-value="creditModal.open" title="Điều chỉnh credit" @update:model-value="creditModal.open = false">
      <form class="space-y-3" @submit.prevent="saveCredit">
        <p v-if="creditModal.row" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2.5 text-xs text-cream-200">
          {{ creditModal.row.name }} · {{ creditModal.row.email }}<br>
          Số dư hiện tại: <b class="text-cream-50">{{ fmtNum(creditModal.row.credits_balance) }}</b>
        </p>
        <div>
          <label class="label" for="c-amount">Số credit (dương = cộng, âm = trừ)</label>
          <input id="c-amount" v-model.number="creditModal.form.amount" type="number" step="1" class="input !py-2" :class="creditModal.errors.amount ? '!border-red-500/70' : ''" placeholder="vd: 500 hoặc -100">
          <p v-if="creditModal.errors.amount" class="mt-1 text-body text-danger">{{ creditModal.errors.amount }}</p>
        </div>
        <div>
          <label class="label" for="c-note">Ghi chú</label>
          <input id="c-note" v-model="creditModal.form.note" class="input !py-2" placeholder="Lý do điều chỉnh (hiện trong Sổ credit)">
        </div>
        <p class="text-body text-cream-300">Giao dịch được ghi vào Sổ credit kèm tên bạn là người thực hiện.</p>
        <div class="flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
          <button type="button" class="tool-btn" @click="creditModal.open = false">Huỷ</button>
          <button type="submit" class="btn-brand btn-sm" :disabled="creditModal.saving">
            <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ creditModal.saving ? 'Đang áp dụng…' : 'Áp dụng' }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- ═════════ Hộp thoại: đặt lại mật khẩu ═════════ -->
    <BaseModal :model-value="pwdModal.open" title="Đặt lại mật khẩu" @update:model-value="pwdModal.open = false">
      <form class="space-y-3" @submit.prevent="savePwd">
        <p v-if="pwdModal.row" class="text-xs text-cream-200">Đặt mật khẩu mới cho <b class="text-cream-50">{{ pwdModal.row.name }}</b> ({{ pwdModal.row.email }}).</p>
        <div>
          <label class="label" for="p-pwd">Mật khẩu mới (tối thiểu 8 ký tự)</label>
          <input id="p-pwd" v-model="pwdModal.form.password" type="password" autocomplete="new-password" class="input !py-2 font-mono text-xs" :class="pwdModal.errors.password ? '!border-red-500/70' : ''">
          <p v-if="pwdModal.errors.password" class="mt-1 text-body text-danger">{{ pwdModal.errors.password }}</p>
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
          <button type="button" class="tool-btn" @click="pwdModal.open = false">Huỷ</button>
          <button type="submit" class="btn-brand btn-sm" :disabled="pwdModal.saving">
            <StudioIcon name="key" size="h-3.5 w-3.5" /> {{ pwdModal.saving ? 'Đang đặt…' : 'Đặt lại mật khẩu' }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- ═════════ Hộp thoại: gói cước ═════════ -->
    <BaseModal :model-value="planModal.open" :title="planModal.mode === 'create' ? 'Thêm gói cước' : 'Sửa gói cước'" wide @update:model-value="planModal.open = false">
      <form class="space-y-3" @submit.prevent="savePlan">
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="pl-name">Tên gói</label>
            <input id="pl-name" v-model="planModal.form.name" class="input !py-2" :class="planModal.errors.name ? '!border-red-500/70' : ''" placeholder="VD: Chuyên nghiệp">
            <p v-if="planModal.errors.name" class="mt-1 text-body text-danger">{{ planModal.errors.name }}</p>
          </div>
          <div>
            <label class="label" for="pl-slug">Slug</label>
            <input id="pl-slug" v-model="planModal.form.slug" class="input !py-2 font-mono text-xs" :class="planModal.errors.slug ? '!border-red-500/70' : ''" placeholder="vd: pro">
            <p v-if="planModal.errors.slug" class="mt-1 text-body text-danger">{{ planModal.errors.slug }}</p>
          </div>
          <div class="sm:col-span-2">
            <label class="label" for="pl-tagline">Mô tả ngắn (tagline)</label>
            <input id="pl-tagline" v-model="planModal.form.tagline" class="input !py-2" placeholder="Hiện trên trang giá">
          </div>
          <div>
            <label class="label" for="pl-price">Giá VNĐ / tháng (0 = miễn phí)</label>
            <input id="pl-price" v-model.number="planModal.form.price_vnd" type="number" min="0" class="input !py-2" :class="planModal.errors.price_vnd ? '!border-red-500/70' : ''">
            <p v-if="planModal.errors.price_vnd" class="mt-1 text-body text-danger">{{ planModal.errors.price_vnd }}</p>
          </div>
          <div>
            <label class="label" for="pl-credits">Credit / tháng</label>
            <input id="pl-credits" v-model.number="planModal.form.credits_per_month" type="number" min="0" class="input !py-2" :class="planModal.errors.credits_per_month ? '!border-red-500/70' : ''">
            <p v-if="planModal.errors.credits_per_month" class="mt-1 text-body text-danger">{{ planModal.errors.credits_per_month }}</p>
          </div>
          <div>
            <label class="label" for="pl-bonus">Credit tặng lần đầu</label>
            <input id="pl-bonus" v-model.number="planModal.form.bonus_credits" type="number" min="0" class="input !py-2">
          </div>
          <div>
            <label class="label" for="pl-res">Độ phân giải tối đa</label>
            <select id="pl-res" v-model="planModal.form.resolution_cap" class="input !py-2">
              <option value="1K">1K</option>
              <option value="2K">2K</option>
            </select>
          </div>
          <!-- [Q4] Số ghế: gói cho bao nhiêu NGƯỜI dùng chung (ảnh hưởng trực tiếp giá trị gói) -->
          <div>
            <label class="label" for="pl-seats">Số ghế (người dùng chung)</label>
            <input id="pl-seats" v-model.number="planModal.form.seats" type="number" min="1" max="100" class="input !py-2">
            <p v-if="planModal.errors.seats" class="mt-1 text-body text-danger">{{ planModal.errors.seats }}</p>
          </div>
          <div>
            <label class="label" for="pl-img">Credit / ảnh</label>
            <input id="pl-img" v-model.number="planModal.form.image_credit_cost" type="number" min="1" class="input !py-2" :class="planModal.errors.image_credit_cost ? '!border-red-500/70' : ''">
            <p v-if="planModal.errors.image_credit_cost" class="mt-1 text-body text-danger">{{ planModal.errors.image_credit_cost }}</p>
          </div>
          <div>
            <label class="label" for="pl-vid">Credit / video</label>
            <input id="pl-vid" v-model.number="planModal.form.video_credit_cost" type="number" min="1" class="input !py-2" :class="planModal.errors.video_credit_cost ? '!border-red-500/70' : ''">
            <p v-if="planModal.errors.video_credit_cost" class="mt-1 text-body text-danger">{{ planModal.errors.video_credit_cost }}</p>
          </div>
          <div>
            <label class="label" for="pl-sort">Thứ tự hiển thị</label>
            <input id="pl-sort" v-model.number="planModal.form.sort" type="number" min="0" class="input !py-2">
          </div>
        </div>

        <!-- [Modules] KHỐI 1 — CÔNG TẮC THẬT: tick module là gói CẤP quyền dùng tính năng đó cho khách.-->
        <div class="rounded-lg border border-brand-500/30 bg-brand-600/10 p-3">
          <div class="flex flex-wrap items-center gap-2">
            <p class="flex items-center gap-2 text-xs font-semibold text-cream-50">
              <StudioIcon name="puzzle" size="h-4 w-4" class="text-brand-300" /> Tính năng gói này CẤP cho khách
            </p>
            <span :class="[BADGE, BADGE_TONE.brand]">{{ planFormModuleCount() }}/{{ modulesData.total_modules }} tính năng</span>
            <span class="text-label text-cream-300">Đây là công tắc thật: khách ở gói chỉ dùng được đúng những mục được tick.</span>
          </div>
          <p v-if="!modulesData.modules.length" class="mt-2 text-body text-cream-300">Đang nạp danh mục tính năng…</p>
          <template v-else>
            <div class="mt-2 max-h-64 space-y-2 overflow-y-auto pr-1">
              <div v-for="(items, group) in moduleGroups" :key="'pf-' + group">
                <p class="text-label font-semibold uppercase tracking-wide text-cream-300">{{ group }}</p>
                <div class="mt-0.5 flex flex-wrap gap-1">
                  <button v-for="m in items" :key="'pf-' + m.id" type="button"
                          class="rounded border px-1.5 py-0.5 text-label transition"
                          :class="(planModal.form.modules || []).includes(m.id)
                            ? 'border-emerald-500/40 bg-emerald-500/15 text-ok'
                            : 'border-ink-600 bg-ink-800 text-cream-300 hover:border-ink-500'"
                          :title="m.summary + (m.depends_on.length ? ' · cần: ' + m.depends_on.join(', ') : '')"
                          @click="togglePlanFormModule(m.id)">
                    <StudioIcon :name="(planModal.form.modules || []).includes(m.id) ? 'check' : 'x'" size="h-3 w-3" class="mr-0.5 inline" />{{ m.name }}
                  </button>
                </div>
              </div>
            </div>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button type="button" class="tool-btn !py-1 text-label" title="Chọn tất cả tính năng đang mở" @click="planModal.form.modules = (modulesData.modules || []).map((m) => m.id)">
                <StudioIcon name="selectAll" size="h-3 w-3" /> Chọn tất cả
              </button>
              <button type="button" class="tool-btn !py-1 text-label" title="Bỏ chọn hết" @click="planModal.form.modules = []">
                <StudioIcon name="x" size="h-3 w-3" /> Bỏ chọn hết
              </button>
              <button type="button" class="tool-btn !py-1 text-label" title="Lấy đề xuất từ bản khai tính năng" @click="planModal.form.modules = (modulesData.plans.find((x) => x.slug === planModal.form.slug)?.suggested || []).slice()">
                <StudioIcon name="sparkles" size="h-3 w-3" /> Áp đề xuất
              </button>
            </div>
          </template>
        </div>

        <!-- [Modules] KHỐI 2 — GHI CHÚ HIỂN THỊ nhập tay: chỉ để khách đọc, KHÔNG phải công tắc.-->
        <div class="rounded-lg border border-ink-700 bg-ink-900/60 p-3">
          <label class="label" for="pl-feature">Ghi chú hiển thị cho khách (Enter để thêm từng mục)</label>
          <p class="mb-2 text-body leading-relaxed text-warn">
            <StudioIcon name="info" size="h-3 w-3" class="mr-1 inline" />
            Phần này <b>CHỈ để khách đọc</b> trên trang giá — <b>không cấp quyền</b> và không chặn gì cả.
            Muốn mở/ khoá tính năng thì tick ở khối phía trên.
          </p>
          <div class="flex gap-2">
            <input id="pl-feature" v-model="planModal.featureText" class="input flex-1 !py-2" placeholder="VD: Hỗ trợ ưu tiên 1:1 · Hoá đơn theo vụ" @keyup.enter.prevent="addFeature">
            <button type="button" class="tool-btn" @click="addFeature"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm</button>
          </div>
          <div class="mt-2 flex flex-wrap gap-1.5">
            <span v-for="(ft, i) in planModal.form.features" :key="i" class="inline-flex items-center gap-1 rounded-full bg-ink-700 px-2 py-1 text-body text-cream-100">
              {{ ft }}
              <button type="button" class="text-cream-300 hover:text-danger" :aria-label="'Xoá ghi chú ' + ft" @click="removeFeature(i)"><StudioIcon name="x" size="h-3 w-3" /></button>
            </span>
            <span v-if="!planModal.form.features.length" class="text-body text-cream-300">Chưa có ghi chú nào.</span>
          </div>

          <!-- Xem trước: khách sẽ thấy gì trên trang giá (tính năng suy từ công tắc + ghi chú ở trên)-->
          <div class="mt-3 rounded border border-ink-700 bg-ink-950/40 p-2">
            <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Khách sẽ thấy trên trang giá</p>
            <p class="mt-1 text-body text-cream-200">
              <b class="text-cream-50">{{ planFormModuleCount() }} tính năng</b><span v-if="planFormModuleNames().length">:</span>
              <span class="text-cream-300">{{ planFormModuleNames().slice(0, 12).join(' · ') }}<span v-if="planFormModuleNames().length > 12"> …</span></span>
            </p>
            <p v-if="planModal.form.features.length" class="mt-1 text-body text-cream-300">
              Thông tin thêm: {{ planModal.form.features.join(' · ') }}
            </p>
          </div>
        </div>

        <div class="flex flex-wrap gap-4">
          <label class="flex items-center gap-2 text-xs text-cream-200"><input type="checkbox" v-model="planModal.form.is_active" class="h-4 w-4 accent-brand-500"> Đang mở bán</label>
          <label class="flex items-center gap-2 text-xs text-cream-200"><input type="checkbox" v-model="planModal.form.is_default" class="h-4 w-4 accent-brand-500"> Gói mặc định cho tài khoản mới</label>
        </div>
        <p class="text-body text-cream-300">Đặt gói này làm mặc định sẽ tự bỏ mặc định ở gói khác.</p>

        <div class="flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
          <button type="button" class="tool-btn" @click="planModal.open = false">Huỷ</button>
          <button type="submit" class="btn-brand btn-sm" :disabled="planModal.saving">
            <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ planModal.saving ? 'Đang lưu…' : (planModal.mode === 'create' ? 'Tạo gói' : 'Lưu thay đổi') }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- ═════════ Hộp thoại: xác nhận ═════════ -->
    <BaseModal :model-value="confirmBox.open" :title="confirmBox.title" @update:model-value="confirmBox.open = false">
      <p class="text-xs leading-relaxed text-cream-200">{{ confirmBox.message }}</p>
      <div class="mt-4 flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
        <button class="tool-btn" @click="confirmBox.open = false">Huỷ</button>
        <button class="btn-sm inline-flex items-center gap-1.5 rounded-xl bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-500 disabled:opacity-60"
                :disabled="confirmBox.busy" @click="confirmRun">
          <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" /> {{ confirmBox.busy ? 'Đang xử lý…' : confirmBox.label }}
        </button>
      </div>
    </BaseModal>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity var(--motion-dur-base) var(--motion-ease-standard); }
.fade-enter-from, .fade-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
  .fade-enter-active, .fade-leave-active { transition: none; }
}
</style>

