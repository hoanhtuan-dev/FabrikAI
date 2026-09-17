<script setup>
/**
 * AdminApp — Trang Quản trị cho Owner (FabrikAI).
 * 4 khu vực: Tổng quan · Người dùng · Gói cước · Sổ credit.
 * Chuẩn UX: KPI rõ ràng, bảng có tìm kiếm/lọc/phân trang, modal có role/aria + Esc,
 * mọi hành động phá hoại đều có bước xác nhận, toast phản hồi tức thì.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import StudioIcon from './components/StudioIcon.vue';

const BASE = '/api/admin';
const csrf = (() => {
  if (typeof document === 'undefined') return '';
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : '';
})();

const tab = ref('dashboard');
const toast = ref(null);
const error = ref('');
const busy = ref(false);

const dashboard = ref(null);
const usersData = ref({ users: [], total: 0, page: 1, last_page: 1 });
const plansData = ref([]);
const ledgerData = ref({ transactions: [], total: 0, page: 1, last_page: 1 });

const userSearch = ref('');
const userRole = ref('');
const userStatus = ref('');
const ledgerType = ref('');
const ledgerSearch = ref('');

// ── API helpers ──────────────────────────────────────────────────────────
async function api(path, method = 'GET', body = null) {
  const opts = { method, headers: { 'X-XSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } };
  if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const r = await fetch(BASE + path, opts);
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
  return d;
}

function flash(msg, ok = true) { toast.value = { msg, ok }; setTimeout(() => { if (toast.value && toast.value.msg === msg) toast.value = null; }, 3000); }

async function run(fn, okMsg) {
  try { const r = await fn(); if (okMsg) flash(okMsg); return r; }
  catch (e) { flash(e.message, false); return null; }
}

async function loadDashboard() {
  error.value = '';
  try { dashboard.value = await api('/dashboard'); }
  catch (e) { error.value = e.message; }
}
async function loadPlans() {
  try { plansData.value = (await api('/plans')).plans; } catch (e) { flash(e.message, false); }
}
async function loadUsers() {
  busy.value = true;
  try {
    const q = new URLSearchParams();
    if (userSearch.value) q.set('search', userSearch.value);
    if (userRole.value) q.set('role', userRole.value);
    if (userStatus.value) q.set('status', userStatus.value);
    q.set('per_page', 20);
    usersData.value = await api('/users?' + q.toString());
  } catch (e) { flash(e.message, false); }
  finally { busy.value = false; }
}
async function loadLedger() {
  busy.value = true;
  try {
    const q = new URLSearchParams();
    if (ledgerType.value) q.set('type', ledgerType.value);
    if (ledgerSearch.value) q.set('search', ledgerSearch.value);
    q.set('per_page', 20);
    ledgerData.value = await api('/transactions?' + q.toString());
  } catch (e) { flash(e.message, false); }
  finally { busy.value = false; }
}

function goTab(t) {
  tab.value = t;
  if (t === 'users' && !usersData.value.users.length) loadUsers();
  if (t === 'ledger' && !ledgerData.value.transactions.length) loadLedger();
  if (t === 'gui' && !guiItems.value.length) loadGui();
}

// ── Tab "Giao diện": owner quản lý thanh công cụ TRÁI của Studio ─────────
// Thứ tự · nhãn · icon · ẩn/hiện. KHÔNG thêm/xoá mục được: mỗi id gắn cứng một bộ công cụ
// trong code (xem StudioGuiConfig::DEFAULTS) — thêm id lạ thì không có gì để hiển thị.
const guiItems = ref([]);
const guiIcons = ref([]);
const guiSaving = ref(false);
const guiPreview = computed(() => guiItems.value.filter((i) => i.visible !== false));

async function loadGui() {
  try {
    const d = await api('/gui');
    guiItems.value = (d.activityBar || []).map((x) => ({ ...x }));
    guiIcons.value = d.icons || [];
  } catch (e) { flash(e.message, false); }
}

/** Đổi thứ tự: hoán vị với mục liền kề (không cần kéo-thả, dùng được cả trên bàn phím). */
function moveGui(i, dir) {
  const j = i + dir;
  if (j < 0 || j >= guiItems.value.length) return;
  const arr = guiItems.value;
  const t = arr[i];
  arr[i] = arr[j];
  arr[j] = t;
}

async function saveGui() {
  guiSaving.value = true;
  try {
    const d = await api('/gui/activity-bar', 'PUT', { items: guiItems.value });
    guiItems.value = (d.activityBar || []).map((x) => ({ ...x }));
    flash('Đã lưu cấu hình giao diện.');
  } catch (e) { flash(e.message, false); }
  finally { guiSaving.value = false; }
}

async function resetGui() {
  if (!confirm('Khôi phục thanh công cụ về mặc định của hệ thống?')) return;
  try {
    const d = await api('/gui/activity-bar/reset', 'POST');
    guiItems.value = (d.activityBar || []).map((x) => ({ ...x }));
    flash('Đã khôi phục mặc định.');
  } catch (e) { flash(e.message, false); }
}

onMounted(async () => { await loadDashboard(); await loadPlans(); });

// Esc đóng modal đang mở.
function onKey(e) {
  if (e.key !== 'Escape') return;
  userModal.value = null; creditModal.value = null; pwdModal.value = null;
  planModal.value = null; deleteUserTarget.value = null; deletePlanTarget.value = null;
}
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));

// ── Format helpers ───────────────────────────────────────────────────────
const fmtNum = (n) => (Number(n) || 0).toLocaleString('vi-VN');
const fmtVnd = (n) => (Number(n) || 0).toLocaleString('vi-VN') + ' ₫';

const ROLE_META = {
  super_admin: { label: 'Owner', cls: 'bg-gold-400/15 text-gold-400' },
  admin: { label: 'Quản trị', cls: 'bg-brand-600/25 text-brand-200' },
  customer: { label: 'Khách hàng', cls: 'bg-ink-700 text-cream-300' },
};
const roleMeta = (r) => ROLE_META[r] || { label: r, cls: 'bg-ink-700 text-cream-300' };

const TYPE_META = {
  spend: { label: 'Tiêu credit', cls: 'bg-red-500/15 text-red-300' },
  refund: { label: 'Hoàn credit', cls: 'bg-emerald-500/15 text-emerald-300' },
  grant: { label: 'Tặng credit', cls: 'bg-emerald-500/15 text-emerald-300' },
  purchase: { label: 'Nạp gói', cls: 'bg-brand-600/25 text-brand-200' },
  renew: { label: 'Gia hạn', cls: 'bg-brand-600/25 text-brand-200' },
  signup: { label: 'Đăng ký', cls: 'bg-sky-500/15 text-sky-300' },
  adjust: { label: 'Điều chỉnh', cls: 'bg-amber-500/15 text-amber-300' },
};
const typeMeta = (t) => TYPE_META[t] || { label: t, cls: 'bg-ink-700 text-cream-300' };

// ── Users ────────────────────────────────────────────────────────────────
const userModal = ref(null); // null | { mode, user }
const userForm = ref({ name: '', email: '', phone: '', password: '', role: 'customer', plan_id: '', is_active: true });
const creditModal = ref(null); // { user }
const creditForm = ref({ amount: '', note: '' });
const pwdModal = ref(null); // { user }
const pwdForm = ref({ password: '' });
const deleteUserTarget = ref(null);

function openCreateUser() {
  userForm.value = { name: '', email: '', phone: '', password: '', role: 'customer', plan_id: '', is_active: true };
  userModal.value = { mode: 'create' };
}
function openEditUser(u) {
  userForm.value = { name: u.name, email: u.email, phone: u.phone || '', password: '', role: u.role, plan_id: u.plan ? u.plan.id : '', is_active: u.is_active };
  userModal.value = { mode: 'edit', user: u };
}
async function saveUser() {
  const isEdit = userModal.value.mode === 'edit';
  const payload = {
    name: userForm.value.name, email: userForm.value.email, phone: userForm.value.phone || null,
    role: userForm.value.role, is_active: !!userForm.value.is_active,
    plan_id: userForm.value.plan_id === '' ? null : Number(userForm.value.plan_id),
  };
  if (!isEdit) {
    if (!userForm.value.password || userForm.value.password.length < 8) return flash('Mật khẩu tối thiểu 8 ký tự.', false);
    payload.password = userForm.value.password;
  }
  const ok = await run(async () => {
    if (isEdit) await api('/users/' + userModal.value.user.id, 'PUT', payload);
    else await api('/users', 'POST', payload);
  }, isEdit ? 'Đã cập nhật người dùng.' : 'Đã tạo người dùng.');
  if (ok) { userModal.value = null; await loadUsers(); await loadDashboard(); }
}
function openCredit(u) { creditModal.value = { user: u }; creditForm.value = { amount: '', note: '' }; }
async function saveCredit() {
  const amt = Number(creditForm.value.amount);
  if (!amt) return flash('Nhập số credit cần cộng/trừ.', false);
  const ok = await run(async () => {
    await api('/users/' + creditModal.value.user.id + '/credits', 'POST', { amount: amt, note: creditForm.value.note || null });
  }, 'Đã cập nhật credit.');
  if (ok) { creditModal.value = null; await loadUsers(); await loadLedger(); await loadDashboard(); }
}
function openResetPwd(u) { pwdModal.value = { user: u }; pwdForm.value = { password: '' }; }
async function saveResetPwd() {
  if (!pwdForm.value.password || pwdForm.value.password.length < 8) return flash('Mật khẩu tối thiểu 8 ký tự.', false);
  const ok = await run(async () => { await api('/users/' + pwdModal.value.user.id + '/reset-password', 'POST', { password: pwdForm.value.password }); }, 'Đã đặt lại mật khẩu.');
  if (ok) pwdModal.value = null;
}
function askDeleteUser(u) { deleteUserTarget.value = u; }
async function confirmDeleteUser() {
  const ok = await run(async () => { await api('/users/' + deleteUserTarget.value.id, 'DELETE'); }, 'Đã xóa người dùng.');
  if (ok) { deleteUserTarget.value = null; await loadUsers(); await loadDashboard(); }
}

// ── Plans ────────────────────────────────────────────────────────────────
const planModal = ref(null); // null | { mode, plan }
const planForm = ref({ name: '', slug: '', tagline: '', price_vnd: 0, credits_per_month: 0, bonus_credits: 0, image_credit_cost: 1, video_credit_cost: 10, resolution_cap: '2K', features: [], is_active: true, is_default: false, sort: 0 });
const featureText = ref('');
const deletePlanTarget = ref(null);

function openCreatePlan() {
  planForm.value = { name: '', slug: '', tagline: '', price_vnd: 0, credits_per_month: 0, bonus_credits: 0, image_credit_cost: 1, video_credit_cost: 10, resolution_cap: '2K', features: [], is_active: true, is_default: false, sort: 0 };
  featureText.value = '';
  planModal.value = { mode: 'create' };
}
function openEditPlan(p) {
  planForm.value = { name: p.name, slug: p.slug, tagline: p.tagline || '', price_vnd: p.price_vnd, credits_per_month: p.credits_per_month, bonus_credits: p.bonus_credits, image_credit_cost: p.image_credit_cost, video_credit_cost: p.video_credit_cost, resolution_cap: p.resolution_cap, features: (p.features || []).slice(), is_active: p.is_active, is_default: p.is_default, sort: p.sort };
  featureText.value = '';
  planModal.value = { mode: 'edit', plan: p };
}
function addFeature() {
  const t = featureText.value.trim();
  if (t && !planForm.value.features.includes(t)) planForm.value.features.push(t);
  featureText.value = '';
}
function removeFeature(i) { planForm.value.features.splice(i, 1); }
async function savePlan() {
  const isEdit = planModal.value.mode === 'edit';
  const payload = { ...planForm.value, price_vnd: Number(planForm.value.price_vnd) || 0, credits_per_month: Number(planForm.value.credits_per_month) || 0, bonus_credits: Number(planForm.value.bonus_credits) || 0, image_credit_cost: Number(planForm.value.image_credit_cost) || 1, video_credit_cost: Number(planForm.value.video_credit_cost) || 10, sort: Number(planForm.value.sort) || 0 };
  const ok = await run(async () => {
    if (isEdit) await api('/plans/' + planModal.value.plan.id, 'PUT', payload);
    else await api('/plans', 'POST', payload);
  }, isEdit ? 'Đã cập nhật gói.' : 'Đã tạo gói.');
  if (ok) { planModal.value = null; await loadPlans(); await loadDashboard(); }
}
function askDeletePlan(p) { deletePlanTarget.value = p; }
async function confirmDeletePlan() {
  const ok = await run(async () => { await api('/plans/' + deletePlanTarget.value.id, 'DELETE'); }, 'Đã xóa gói.');
  if (ok) { deletePlanTarget.value = null; await loadPlans(); await loadDashboard(); }
}

// ── Pagination helpers ────────────────────────────────────────────────────
function pageInfo(d) { return 'Trang ' + d.page + ' / ' + (d.last_page || 1) + ' · ' + fmtNum(d.total) + ' dòng'; }
function canPrev(d) { return d.page > 1; }
function canNext(d) { return d.page < (d.last_page || 1); }

const kpis = computed(() => dashboard.value ? dashboard.value.kpis : null);
const planDistribution = computed(() => dashboard.value ? dashboard.value.plan_distribution : {});
const maxPlanCount = computed(() => {
  const v = Object.values(planDistribution.value);
  return v.length ? Math.max(...v.map(Number)) : 1;
});

const currentUser = ref(null);
onMounted(async () => {
  try { const r = await fetch('/api/boot', { headers: { Accept: 'application/json' } }); if (r.ok) currentUser.value = (await r.json()).user; } catch (e) {}
});
</script>

<template>
  <div class="studio-dark min-h-screen w-full">
    <!-- Toast -->
    <transition name="fade">
      <div v-if="toast" :class="toast.ok ? 'bg-emerald-600' : 'bg-red-600'" class="fixed bottom-5 right-5 z-[60] rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-xl">{{ toast.msg }}</div>
    </transition>

    <!-- Header -->
    <header class="sticky top-0 z-40 border-b border-ink-700 bg-ink-900/90 backdrop-blur">
      <div class="container-x flex items-center justify-between gap-3 py-3.5">
        <div class="flex items-center gap-3">
          <div class="grid h-10 w-10 place-items-center rounded-xl bg-brand-600 text-lg font-bold text-white">F</div>
          <div>
            <h1 class="font-display text-lg font-semibold leading-tight text-cream-50">Quản trị FabrikAI</h1>
            <p class="text-xs text-cream-300/60">Owner console · quản lý người dùng, gói cước &amp; credit</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <a href="/" class="btn-outline btn-sm">← Studio</a>
          <a href="/settings" class="btn-outline btn-sm">⚙️ Cài đặt</a>
          <div v-if="currentUser" class="hidden items-center gap-2 rounded-lg border border-ink-700 bg-ink-800 px-3 py-1.5 sm:flex">
            <span class="grid h-6 w-6 place-items-center rounded-full bg-brand-600 text-[11px] font-bold text-white">{{ (currentUser.name || '?').charAt(0).toUpperCase() }}</span>
            <div class="leading-tight">
              <p class="text-xs font-semibold text-cream-100">{{ currentUser.name }}</p>
              <p class="text-[10px] text-cream-300/60">{{ currentUser.role_label }}</p>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="container-x py-6">
      <!-- Tab nav -->
      <div class="mb-5 flex flex-wrap gap-1.5">
        <button @click="goTab('dashboard')" :class="tab==='dashboard' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3.5 py-2 text-xs font-semibold transition-colors">📊 Tổng quan</button>
        <button @click="goTab('users')" :class="tab==='users' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3.5 py-2 text-xs font-semibold transition-colors">👥 Người dùng</button>
        <button @click="goTab('plans')" :class="tab==='plans' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3.5 py-2 text-xs font-semibold transition-colors">📦 Gói cước</button>
        <button @click="goTab('ledger')" :class="tab==='ledger' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3.5 py-2 text-xs font-semibold transition-colors">🧾 Sổ credit</button>
        <button @click="goTab('gui')" :class="tab==='gui' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3.5 py-2 text-xs font-semibold transition-colors">🎨 Giao diện</button>
      </div>

      <div v-if="error" class="card p-6 text-sm text-red-400">{{ error }} — <button class="underline" @click="loadDashboard">thử lại</button></div>

      <!-- ════════════ DASHBOARD ════════════ -->
      <div v-show="tab==='dashboard'">
        <template v-if="kpis">
          <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="card p-4">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/70">Người dùng</p>
              <p class="mt-1.5 font-display text-3xl font-semibold text-cream-50">{{ fmtNum(kpis.total_users) }}</p>
              <p class="mt-1 text-[11px] text-emerald-300">+{{ fmtNum(kpis.new_users_7d) }} trong 7 ngày</p>
            </div>
            <div class="card p-4">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/70">Đang trả phí</p>
              <p class="mt-1.5 font-display text-3xl font-semibold text-cream-50">{{ fmtNum(kpis.paying_subscribers) }}</p>
              <p class="mt-1 text-[11px] text-cream-300/60">gói active</p>
            </div>
            <div class="card p-4">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/70">Ảnh đã tạo</p>
              <p class="mt-1.5 font-display text-3xl font-semibold text-cream-50">{{ fmtNum(kpis.generations_total) }}</p>
              <p class="mt-1 text-[11px] text-cream-300/60">+{{ fmtNum(kpis.generations_today) }} hôm nay</p>
            </div>
            <div class="card p-4">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/70">Credit tiêu (30 ngày)</p>
              <p class="mt-1.5 font-display text-3xl font-semibold text-cream-50">{{ fmtNum(kpis.credits_spent_30d) }}</p>
              <p class="mt-1 text-[11px] text-cream-300/60">dư hệ thống: {{ fmtNum(kpis.credits_balance_total) }}</p>
            </div>
          </div>

          <div class="card mt-5 p-5">
            <h2 class="font-display text-base font-semibold text-cream-50">Phân bố gói cước</h2>
            <p class="mt-0.5 text-xs text-cream-300/60">Số người dùng theo từng gói hiện tại.</p>
            <div class="mt-4 space-y-3">
              <div v-for="p in dashboard.plans" :key="p.id" class="flex items-center gap-3">
                <span class="w-28 shrink-0 truncate text-xs text-cream-200">{{ p.name }}</span>
                <div class="h-2.5 flex-1 overflow-hidden rounded-full bg-ink-700">
                  <div class="h-full rounded-full bg-brand-500" :style="{ width: (p.users_count / maxPlanCount * 100) + '%' }"></div>
                </div>
                <span class="w-10 shrink-0 text-right text-xs font-semibold text-cream-100">{{ fmtNum(p.users_count) }}</span>
              </div>
            </div>
          </div>
        </template>
        <div v-else class="card p-10 text-center text-sm text-cream-300/60">Đang tải dữ liệu tổng quan…</div>
      </div>

      <!-- ════════════ USERS ════════════ -->
      <div v-show="tab==='users'">
        <div class="card p-4">
          <div class="flex flex-wrap items-center gap-2">
            <div class="flex min-w-[220px] flex-1 items-center gap-2">
              <input v-model="userSearch" @keyup.enter="loadUsers" placeholder="Tìm theo tên / email / SĐT…" class="input !py-2">
              <select v-model="userRole" @change="loadUsers" class="input w-36 !py-2">
                <option value="">Mọi vai trò</option>
                <option value="super_admin">Owner</option>
                <option value="admin">Quản trị</option>
                <option value="customer">Khách hàng</option>
              </select>
              <select v-model="userStatus" @change="loadUsers" class="input w-36 !py-2">
                <option value="">Mọi trạng thái</option>
                <option value="active">Đang hoạt động</option>
                <option value="inactive">Bị khóa</option>
              </select>
              <button @click="loadUsers" class="btn-outline btn-sm">Tìm</button>
            </div>
            <button @click="openCreateUser" class="btn-brand btn-sm">➕ Thêm người dùng</button>
          </div>
        </div>

        <div class="card mt-4 overflow-x-auto">
          <table class="w-full min-w-[760px] text-left text-xs">
            <thead>
              <tr class="border-b border-ink-700 text-cream-300/70">
                <th class="px-4 py-3 font-semibold">Người dùng</th>
                <th class="px-3 py-3 font-semibold">Vai trò</th>
                <th class="px-3 py-3 font-semibold text-right">Credit</th>
                <th class="px-3 py-3 font-semibold">Gói</th>
                <th class="px-3 py-3 font-semibold text-right">Ảnh</th>
                <th class="px-3 py-3 font-semibold">Ngày tạo</th>
                <th class="px-3 py-3 text-right font-semibold">Hành động</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-ink-800">
              <tr v-for="u in usersData.users" :key="u.id" class="hover:bg-ink-800/50">
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2.5">
                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-600/25 text-xs font-bold text-brand-200">{{ (u.name || '?').charAt(0).toUpperCase() }}</span>
                    <div class="leading-tight">
                      <p class="font-semibold text-cream-50">{{ u.name }}</p>
                      <p class="text-[11px] text-cream-300/60">{{ u.email }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-3 py-3"><span :class="roleMeta(u.role).cls" class="rounded-full px-2 py-0.5 text-[10px] font-semibold">{{ roleMeta(u.role).label }}</span></td>
                <td class="px-3 py-3 text-right font-semibold text-cream-50">{{ fmtNum(u.credits_balance) }}</td>
                <td class="px-3 py-3">
                  <span v-if="u.plan" class="text-cream-200">{{ u.plan.name }}</span>
                  <span v-else class="text-cream-300/50">—</span>
                  <span v-if="!u.is_active" class="ml-1 rounded-full bg-red-500/15 px-1.5 py-0.5 text-[9px] font-semibold text-red-300">khóa</span>
                </td>
                <td class="px-3 py-3 text-right text-cream-200">{{ fmtNum(u.generations_count) }}</td>
                <td class="px-3 py-3 text-cream-300/70">{{ u.created_at }}</td>
                <td class="px-3 py-3">
                  <div class="flex justify-end gap-1">
                    <button @click="openEditUser(u)" class="icon-btn" title="Sửa">✏️</button>
                    <button @click="openCredit(u)" class="icon-btn" title="Cộng/trừ credit">💰</button>
                    <button @click="openResetPwd(u)" class="icon-btn" title="Đặt lại mật khẩu">🔑</button>
                    <button @click="askDeleteUser(u)" class="icon-btn hover:!bg-red-500/20" title="Xóa">🗑</button>
                  </div>
                </td>
              </tr>
              <tr v-if="!usersData.users.length"><td colspan="7" class="px-4 py-8 text-center text-cream-300/50">Không có người dùng nào.</td></tr>
            </tbody>
          </table>
          <div class="flex items-center justify-between border-t border-ink-700 px-4 py-3">
            <span class="text-[11px] text-cream-300/60">{{ pageInfo(usersData) }}</span>
            <div class="flex gap-1.5">
              <button :disabled="!canPrev(usersData)" @click="usersData.page--; loadUsers()" class="btn-outline btn-sm">← Trước</button>
              <button :disabled="!canNext(usersData)" @click="usersData.page++; loadUsers()" class="btn-outline btn-sm">Sau →</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ════════════ PLANS ════════════ -->
      <div v-show="tab==='plans'">
        <div class="mb-4 flex items-center justify-between">
          <p class="text-xs text-cream-300/60">{{ plansData.length }} gói · giá VNĐ, credit theo tháng.</p>
          <button @click="openCreatePlan" class="btn-brand btn-sm">➕ Thêm gói</button>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div v-for="p in plansData" :key="p.id" class="card flex flex-col p-5" :class="p.is_active ? '' : 'opacity-60'">
            <div class="flex items-center justify-between">
              <h3 class="font-display text-base font-semibold text-cream-50">{{ p.name }}</h3>
              <span v-if="p.is_default" class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold text-amber-300">Mặc định</span>
              <span v-else-if="!p.is_active" class="rounded-full bg-ink-700 px-2 py-0.5 text-[10px] font-semibold text-cream-300/70">Ẩn</span>
            </div>
            <p class="mt-0.5 text-xs text-cream-300/60">{{ p.tagline }}</p>
            <div class="mt-3 flex items-baseline gap-1">
              <span class="font-display text-3xl font-semibold text-cream-50">{{ fmtVnd(p.price_vnd) }}</span>
              <span v-if="p.price_vnd > 0" class="text-xs text-cream-300/60">/tháng</span>
            </div>
            <p class="mt-2 text-sm font-semibold text-brand-200">{{ fmtNum(p.credits_per_month) }} credit/tháng <span v-if="p.bonus_credits" class="text-cream-300/60">(+{{ fmtNum(p.bonus_credits) }} tặng)</span></p>
            <ul class="mt-3 flex-1 space-y-1.5">
              <li v-for="(f, i) in p.features" :key="i" class="flex items-start gap-1.5 text-[12px] text-cream-200"><span class="mt-0.5 text-emerald-400">✓</span>{{ f }}</li>
            </ul>
            <div class="mt-4 flex gap-1.5">
              <button @click="openEditPlan(p)" class="btn-outline btn-sm flex-1">✏️ Sửa</button>
              <button @click="askDeletePlan(p)" class="btn-outline btn-sm text-red-400">🗑</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ════════════ LEDGER ════════════ -->
      <div v-show="tab==='ledger'">
        <div class="card p-4">
          <div class="flex flex-wrap items-center gap-2">
            <input v-model="ledgerSearch" @keyup.enter="loadLedger" placeholder="Tìm người dùng…" class="input max-w-xs !py-2">
            <select v-model="ledgerType" @change="loadLedger" class="input w-44 !py-2">
              <option value="">Mọi loại</option>
              <option value="spend">Tiêu credit</option>
              <option value="refund">Hoàn credit</option>
              <option value="grant">Tặng credit</option>
              <option value="adjust">Điều chỉnh</option>
              <option value="purchase">Nạp gói</option>
              <option value="renew">Gia hạn</option>
              <option value="signup">Đăng ký</option>
            </select>
            <button @click="loadLedger" class="btn-outline btn-sm">Tìm</button>
          </div>
        </div>

        <div class="card mt-4 overflow-x-auto">
          <table class="w-full min-w-[720px] text-left text-xs">
            <thead>
              <tr class="border-b border-ink-700 text-cream-300/70">
                <th class="px-4 py-3 font-semibold">Thời gian</th>
                <th class="px-3 py-3 font-semibold">Người dùng</th>
                <th class="px-3 py-3 font-semibold">Loại</th>
                <th class="px-3 py-3 text-right font-semibold">Số credit</th>
                <th class="px-3 py-3 text-right font-semibold">Số dư sau</th>
                <th class="px-3 py-3 font-semibold">Ghi chú</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-ink-800">
              <tr v-for="t in ledgerData.transactions" :key="t.id" class="hover:bg-ink-800/50">
                <td class="px-4 py-2.5 whitespace-nowrap text-cream-300/70">{{ t.created_at }}</td>
                <td class="px-3 py-2.5 font-semibold text-cream-50">{{ t.user ? t.user.name : '—' }}</td>
                <td class="px-3 py-2.5"><span :class="typeMeta(t.type).cls" class="rounded-full px-2 py-0.5 text-[10px] font-semibold">{{ typeMeta(t.type).label }}</span></td>
                <td class="px-3 py-2.5 text-right font-semibold" :class="t.amount > 0 ? 'text-emerald-300' : 'text-red-300'">{{ t.amount > 0 ? '+' : '' }}{{ fmtNum(t.amount) }}</td>
                <td class="px-3 py-2.5 text-right text-cream-200">{{ fmtNum(t.balance_after) }}</td>
                <td class="px-3 py-2.5 text-cream-300/70">{{ t.note }}</td>
              </tr>
              <tr v-if="!ledgerData.transactions.length"><td colspan="6" class="px-4 py-8 text-center text-cream-300/50">Chưa có giao dịch nào.</td></tr>
            </tbody>
          </table>
          <div class="flex items-center justify-between border-t border-ink-700 px-4 py-3">
            <span class="text-[11px] text-cream-300/60">{{ pageInfo(ledgerData) }}</span>
            <div class="flex gap-1.5">
              <button :disabled="!canPrev(ledgerData)" @click="ledgerData.page--; loadLedger()" class="btn-outline btn-sm">← Trước</button>
              <button :disabled="!canNext(ledgerData)" @click="ledgerData.page++; loadLedger()" class="btn-outline btn-sm">Sau →</button>
            </div>
          </div>
        </div>
      </div>
    </main>

    <!-- ════════════ MODAL: create/edit user ════════════ -->
    <div v-if="userModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-label="Người dùng">
      <div class="card w-full max-w-lg p-5">
        <h3 class="font-display text-base font-semibold text-cream-50">{{ userModal.mode === 'create' ? 'Thêm người dùng' : 'Sửa người dùng' }}</h3>
        <div class="mt-4 grid grid-cols-2 gap-3">
          <div class="col-span-2"><label class="label">Tên</label><input v-model="userForm.name" class="input !py-2"></div>
          <div class="col-span-2"><label class="label">Email</label><input v-model="userForm.email" type="email" class="input !py-2"></div>
          <div><label class="label">SĐT</label><input v-model="userForm.phone" class="input !py-2"></div>
          <div><label class="label">Vai trò</label>
            <select v-model="userForm.role" class="input !py-2">
              <option value="customer">Khách hàng</option>
              <option value="admin">Quản trị</option>
              <option value="super_admin">Owner</option>
            </select>
          </div>
          <div v-if="userModal.mode === 'create'"><label class="label">Mật khẩu</label><input v-model="userForm.password" type="password" autocomplete="new-password" class="input !py-2"></div>
          <div><label class="label">Gói cước</label>
            <select v-model="userForm.plan_id" class="input !py-2">
              <option value="">— Không gán —</option>
              <option v-for="p in plansData" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <label class="col-span-2 flex items-center gap-2 text-sm text-cream-200"><input type="checkbox" v-model="userForm.is_active" class="h-4 w-4 accent-brand-500"> Tài khoản đang hoạt động</label>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button @click="userModal = null" class="btn-ghost btn-sm">Hủy</button>
          <button @click="saveUser" class="btn-brand btn-sm">💾 Lưu</button>
        </div>
      </div>
    </div>

    <!-- ════════════ TAB: Giao diện — thanh công cụ TRÁI của Studio ════════════ -->
    <div v-show="tab==='gui'">
      <div class="card p-4">
        <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-display text-base font-semibold text-cream-50">🎨 Thanh công cụ trái của Studio</h2>
          <div class="flex gap-2">
            <button @click="resetGui" class="btn-ghost btn-sm">↺ Khôi phục mặc định</button>
            <button @click="saveGui" :disabled="guiSaving" class="btn-brand btn-sm">{{ guiSaving ? 'Đang lưu…' : '💾 Lưu' }}</button>
          </div>
        </div>
        <p class="mb-4 text-xs leading-relaxed text-cream-300/60">
          Đổi <b class="text-cream-200">thứ tự</b> · <b class="text-cream-200">nhãn</b> · <b class="text-cream-200">icon</b> · <b class="text-cream-200">ẩn/hiện</b> từng mục — áp dụng cho MỌI người dùng Studio.
          Không thêm/xoá được mục vì mỗi mục gắn cứng một bộ công cụ có sẵn trong code.
        </p>

        <!-- Xem trước đúng thứ tự & icon sẽ hiện trên thanh công cụ -->
        <div class="mb-4 rounded-lg border border-ink-700 bg-ink-900 p-3">
          <p class="mb-2 text-[10px] font-semibold uppercase tracking-wide text-cream-300/40">Xem trước</p>
          <div class="flex items-center gap-1.5 overflow-x-auto">
            <div v-for="it in guiPreview" :key="it.id" class="grid h-10 w-10 shrink-0 place-items-center rounded-lg" :class="it.visible ? 'bg-ink-800 text-brand-200' : 'bg-ink-800/40 text-cream-300/25'" :title="it.label">
              <StudioIcon :name="it.icon" size="h-5 w-5" />
            </div>
            <span v-if="!guiPreview.length" class="text-xs text-cream-300/50">Chưa có mục nào được hiện.</span>
          </div>
        </div>

        <div class="space-y-2">
          <div v-for="(it, i) in guiItems" :key="it.id" class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
            <span class="w-6 shrink-0 text-center text-[10px] font-semibold text-cream-300/40">{{ i + 1 }}</span>
            <div class="flex shrink-0 gap-1">
              <button @click="moveGui(i, -1)" :disabled="i === 0" class="grid h-7 w-7 place-items-center rounded-md bg-ink-700 text-cream-200 transition hover:bg-ink-600 disabled:opacity-30" title="Đưa lên" aria-label="Đưa lên">▲</button>
              <button @click="moveGui(i, 1)" :disabled="i === guiItems.length - 1" class="grid h-7 w-7 place-items-center rounded-md bg-ink-700 text-cream-200 transition hover:bg-ink-600 disabled:opacity-30" title="Đưa xuống" aria-label="Đưa xuống">▼</button>
            </div>
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-ink-800 text-brand-200" title="Xem trước icon"><StudioIcon :name="it.icon" size="h-5 w-5" /></span>
            <select v-model="it.icon" class="input !w-40 !py-1.5 text-xs" aria-label="Icon" title="Chọn icon — danh sách lấy từ registry chung">
              <option v-for="ic in guiIcons" :key="ic.name" :value="ic.name" :title="ic.note || ic.name">{{ ic.name }}</option>
            </select>
            <input v-model="it.label" type="text" maxlength="40" class="input !min-w-40 !flex-1 !py-1.5 text-xs" placeholder="Nhãn hiển thị" aria-label="Nhãn">
            <span class="shrink-0 rounded bg-ink-800 px-1.5 py-0.5 font-mono text-[10px] text-cream-300/50" title="Id — không đổi được">{{ it.id }}</span>
            <label class="flex shrink-0 cursor-pointer items-center gap-1.5 text-[11px] text-cream-200">
              <input type="checkbox" v-model="it.visible" class="h-3.5 w-3.5 accent-brand-500"> Hiện
            </label>
          </div>
        </div>
        <p v-if="!guiItems.length" class="py-8 text-center text-xs text-cream-300/50">Đang tải cấu hình…</p>
      </div>
    </div>
    <!-- ════════════ MODAL: credit adjust ════════════ -->
    <div v-if="creditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-label="Điều chỉnh credit">
      <div class="card w-full max-w-sm p-5">
        <h3 class="font-display text-base font-semibold text-cream-50">Điều chỉnh credit</h3>
        <p class="mt-1 text-xs text-cream-300/60">{{ creditModal.user.name }} · số dư <b class="text-cream-100">{{ fmtNum(creditModal.user.credits_balance) }}</b></p>
        <div class="mt-4 space-y-3">
          <div><label class="label">Số credit (dương = cộng, âm = trừ)</label><input v-model.number="creditForm.amount" type="number" step="1" class="input !py-2" placeholder="vd: 500 hoặc -100"></div>
          <div><label class="label">Ghi chú</label><input v-model="creditForm.note" class="input !py-2" placeholder="Lý do điều chỉnh (tùy chọn)"></div>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button @click="creditModal = null" class="btn-ghost btn-sm">Hủy</button>
          <button @click="saveCredit" class="btn-brand btn-sm">💾 Áp dụng</button>
        </div>
      </div>
    </div>

    <!-- ════════════ MODAL: reset password ════════════ -->
    <div v-if="pwdModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-label="Đặt lại mật khẩu">
      <div class="card w-full max-w-sm p-5">
        <h3 class="font-display text-base font-semibold text-cream-50">Đặt lại mật khẩu</h3>
        <p class="mt-1 text-xs text-cream-300/60">{{ pwdModal.user.name }}</p>
        <div class="mt-4"><label class="label">Mật khẩu mới</label><input v-model="pwdForm.password" type="password" autocomplete="new-password" class="input !py-2"></div>
        <div class="mt-4 flex justify-end gap-2">
          <button @click="pwdModal = null" class="btn-ghost btn-sm">Hủy</button>
          <button @click="saveResetPwd" class="btn-brand btn-sm">🔑 Đặt lại</button>
        </div>
      </div>
    </div>

    <!-- ════════════ MODAL: create/edit plan ════════════ -->
    <div v-if="planModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-label="Gói cước">
      <div class="card max-h-[90vh] w-full max-w-xl overflow-y-auto p-5">
        <h3 class="font-display text-base font-semibold text-cream-50">{{ planModal.mode === 'create' ? 'Thêm gói cước' : 'Sửa gói cước' }}</h3>
        <div class="mt-4 grid grid-cols-2 gap-3">
          <div><label class="label">Tên gói</label><input v-model="planForm.name" class="input !py-2"></div>
          <div><label class="label">Slug</label><input v-model="planForm.slug" class="input !py-2" placeholder="vd: pro"></div>
          <div class="col-span-2"><label class="label">Tagline</label><input v-model="planForm.tagline" class="input !py-2"></div>
          <div><label class="label">Giá VNĐ/tháng (0 = miễn phí)</label><input v-model.number="planForm.price_vnd" type="number" min="0" class="input !py-2"></div>
          <div><label class="label">Credit / tháng</label><input v-model.number="planForm.credits_per_month" type="number" min="0" class="input !py-2"></div>
          <div><label class="label">Credit tặng lần đầu</label><input v-model.number="planForm.bonus_credits" type="number" min="0" class="input !py-2"></div>
          <div><label class="label">Độ phân giải</label>
            <select v-model="planForm.resolution_cap" class="input !py-2"><option value="1K">1K</option><option value="2K">2K</option></select>
          </div>
          <div><label class="label">Credit / ảnh</label><input v-model.number="planForm.image_credit_cost" type="number" min="1" class="input !py-2"></div>
          <div><label class="label">Credit / video</label><input v-model.number="planForm.video_credit_cost" type="number" min="1" class="input !py-2"></div>
          <div><label class="label">Thứ tự</label><input v-model.number="planForm.sort" type="number" min="0" class="input !py-2"></div>
          <div class="col-span-2">
            <label class="label">Đặc quyền (mỗi dòng một mục)</label>
            <div class="flex gap-2">
              <input v-model="featureText" @keyup.enter.prevent="addFeature" class="input flex-1 !py-2" placeholder="Nhập đặc quyền rồi Enter">
              <button @click="addFeature" class="btn-outline btn-sm">Thêm</button>
            </div>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <span v-for="(f, i) in planForm.features" :key="i" class="inline-flex items-center gap-1 rounded-full bg-ink-700 px-2 py-1 text-[11px] text-cream-100">{{ f }} <button @click="removeFeature(i)" class="text-cream-300/60 hover:text-red-300">✕</button></span>
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm text-cream-200"><input type="checkbox" v-model="planForm.is_active" class="h-4 w-4 accent-brand-500"> Đang mở bán</label>
          <label class="flex items-center gap-2 text-sm text-cream-200"><input type="checkbox" v-model="planForm.is_default" class="h-4 w-4 accent-brand-500"> Gói mặc định</label>
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button @click="planModal = null" class="btn-ghost btn-sm">Hủy</button>
          <button @click="savePlan" class="btn-brand btn-sm">💾 Lưu</button>
        </div>
      </div>
    </div>

    <!-- ════════════ MODAL: confirm delete user ════════════ -->
    <div v-if="deleteUserTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-label="Xác nhận xóa">
      <div class="card w-full max-w-sm p-5">
        <h3 class="font-display text-base font-semibold text-red-300">Xóa người dùng?</h3>
        <p class="mt-2 text-sm text-cream-200">Xóa <b>{{ deleteUserTarget.name }}</b> ({{ deleteUserTarget.email }}) sẽ mất toàn bộ dự án, ảnh và lịch sử credit. Hành động này không thể hoàn tác.</p>
        <div class="mt-4 flex justify-end gap-2">
          <button @click="deleteUserTarget = null" class="btn-ghost btn-sm">Hủy</button>
          <button @click="confirmDeleteUser" class="rounded-lg bg-red-600 px-4 py-2 text-xs font-semibold text-white hover:bg-red-700">🗑 Xóa vĩnh viễn</button>
        </div>
      </div>
    </div>

    <!-- ════════════ MODAL: confirm delete plan ════════════ -->
    <div v-if="deletePlanTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-label="Xác nhận xóa gói">
      <div class="card w-full max-w-sm p-5">
        <h3 class="font-display text-base font-semibold text-red-300">Xóa gói cước?</h3>
        <p class="mt-2 text-sm text-cream-200">Xóa gói <b>{{ deletePlanTarget.name }}</b>. Gói đang có người dùng sẽ không thể xóa — hãy ẩn gói thay vì xóa.</p>
        <div class="mt-4 flex justify-end gap-2">
          <button @click="deletePlanTarget = null" class="btn-ghost btn-sm">Hủy</button>
          <button @click="confirmDeletePlan" class="rounded-lg bg-red-600 px-4 py-2 text-xs font-semibold text-white hover:bg-red-700">🗑 Xóa</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
