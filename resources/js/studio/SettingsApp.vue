<script setup>
/**
 * ⚙️ CÀI ĐẶT STUDIO — bản thiết kế lại UX/UI (2026-09-18).
 *
 * VẤN ĐỀ CỦA BẢN CŨ (một trang, 6 tab, 789 dòng):
 *   · Không có TỔNG QUAN: mở trang là gặp ngay danh sách key — không biết hệ thống đang thiếu gì
 *     (provider nào chưa có key, nhóm việc nào chưa gán model) cho tới khi tự đi hết các tab.
 *   · Tab là 6 nút pill nằm cùng hàng tiêu đề ⇒ tràn/wrap trên màn hẹp, không nhóm theo chủ đề,
 *     không mang thông tin trạng thái, và chỉ có emoji làm điểm neo thị giác.
 *   · Form "thêm mới" LUÔN hiện dưới danh sách ⇒ trang dài, nhiễu; sửa/xoá nằm lẫn trong dòng.
 *   · Xoá dùng confirm() của trình duyệt: không nói rõ hậu quả, không theo được ngôn ngữ thiết kế.
 *   · Thẻ "Sử dụng" đọc các field KHÔNG tồn tại trong payload (images/videos/credits_used) ⇒ luôn
 *     hiển thị 0 dù có số liệu thật (balance/used_total/used_today/limit).
 *   · Toàn bộ giải thích dài (tương thích CKEY, luồng fallback) đổ thẳng vào thân trang.
 *
 * BẢN NÀY GIỮ NGUYÊN HỢP ĐỒNG DỮ LIỆU: đúng bộ endpoint /api/settings-vue/*, đúng payload,
 * đúng thứ tự ưu tiên provider, đúng luật "key là write-only" và luật "key ref là TÊN NHÓM KEY".
 * Thay đổi thuần tuý ở lớp trình bày + luồng thao tác:
 *   1. Điều hướng dọc theo NHÓM (Bắt đầu · Nhà cung cấp · Model · Vận hành), mỗi mục có badge
 *      trạng thái; màn hẹp gom thành dải cuộn ngang.
 *   2. Thêm mục "Tổng quan": 4 thẻ số liệu + danh sách VIỆC CẦN XỬ LÝ (bấm là nhảy đúng chỗ,
 *      mở sẵn form với dữ liệu điền trước) + chuỗi fallback + số liệu sử dụng THẬT.
 *   3. Thêm/sửa bằng HỘP THOẠI (BaseModal: có focus trap, Esc, aria-modal) ⇒ trang chỉ còn danh sách.
 *   4. Xoá qua hộp thoại xác nhận nói rõ hậu quả thay cho confirm() của trình duyệt.
 *   5. Tìm kiếm + lọc tại chỗ cho Keys / Providers / Models; nhóm cũ (inference, text) thu gọn.
 *   6. Giải thích dài đưa vào khối "Trợ giúp" gập lại được.
 *   7. Điều hướng theo URL (?tab=keys) — F5 và link chia sẻ giữ đúng mục đang xem.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue';
import StudioIcon from './components/StudioIcon.vue';
import BaseModal from './components/BaseModal.vue';

const BASE = '/api/settings-vue';
const csrf = (() => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
})();

async function api(path, method = 'GET', body = null) {
  const opts = { method, headers: { 'X-XSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } };
  if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const r = await fetch(BASE + path, opts);
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
  return d;
}

// ─────────────────────────── Điều hướng & nhãn ───────────────────────────
const SECTIONS = [
  { id: 'overview',  group: 'Bắt đầu',      label: 'Tổng quan',        icon: 'activity' },
  { id: 'flow',      group: 'Nhà cung cấp', label: 'Luồng ưu tiên',    icon: 'sliders' },
  { id: 'keys',      group: 'Nhà cung cấp', label: 'API Keys',         icon: 'key' },
  { id: 'providers', group: 'Nhà cung cấp', label: 'Custom Providers', icon: 'globe' },
  { id: 'models',    group: 'Model',        label: 'Model Registry',   icon: 'server' },
  { id: 'tasks',     group: 'Model',        label: 'Nhóm công việc',   icon: 'target' },
  { id: 'general',   group: 'Vận hành',     label: 'Cấu hình chung',   icon: 'gear' },
];
const SECTION_GROUPS = ['Bắt đầu', 'Nhà cung cấp', 'Model', 'Vận hành'];
const sectionsIn = (group) => SECTIONS.filter((s) => s.group === group);

// Vai trò model (group) — nhãn tiếng Việt + mô tả ngắn. legacy = vai trò cũ, thu gọn mặc định.
const ROLE_META = {
  image:     { label: 'Tạo ảnh 2D',        icon: 'image',    desc: 'Tạo Ảnh 2D · ảnh mới từ ảnh mẫu' },
  edit:      { label: 'Sửa ảnh (edit)',    icon: 'wand',     desc: 'Inpaint · xoá vùng · sửa theo prompt' },
  video:     { label: 'Video',             icon: 'film',     desc: 'Kịch bản quay · catwalk' },
  swap:      { label: 'Mặc thử đồ',        icon: 'shirt',    desc: 'Thay người mẫu · ghép trang phục' },
  vision:    { label: 'Đọc ảnh (vision)',  icon: 'eye',      desc: 'Nhận diện khuôn mặt / dáng' },
  prompt:    { label: 'Suy luận prompt',   icon: 'sparkles', desc: 'Trợ lý thiết kế · giám đốc sáng tạo' },
  translate: { label: 'Dịch prompt',       icon: 'globe',    desc: 'Việt ⇄ Anh' },
  inference: { label: 'Suy luận (cũ)',     icon: 'bot',      desc: 'Vai trò cũ — giữ để tương thích', legacy: true },
  text:      { label: 'Ngôn ngữ (cũ)',     icon: 'bot',      desc: 'Vai trò cũ — giữ để tương thích', legacy: true },
};
const ROLE_ORDER = ['image', 'edit', 'video', 'swap', 'vision', 'prompt', 'translate', 'inference', 'text'];
const roleLabel = (g) => (ROLE_META[g] && ROLE_META[g].label) || g;
const roleIcon = (g) => (ROLE_META[g] && ROLE_META[g].icon) || 'server';
const isLegacyRole = (g) => !!(ROLE_META[g] && ROLE_META[g].legacy);

// Luồng ưu tiên provider (token do backend trả về trong provider_priority).
const FLOW_META = {
  qwen:   { label: 'QwenCloud / DashScope', short: 'Qwen',   icon: 'zap',      desc: 'Provider chính — ảnh, video, suy luận, đọc ảnh.' },
  custom: { label: 'Custom provider',       short: 'Custom', icon: 'globe',    desc: 'Route tự khai báo (protocol + base URL) — ví dụ CKEY (api.xah.io).' },
  flux:   { label: 'Flux — Fal.ai',         short: 'Flux',   icon: 'sparkles', desc: 'Fallback tạo ảnh khi Qwen lỗi hoặc hết hạn mức.' },
  deepseek: { label: 'DeepSeek',            short: 'DeepSeek', icon: 'bot',    desc: 'Suy luận / ngôn ngữ — đứng TRƯỚC Gemini: dùng khi Qwen hết hạn mức mà Gemini đắt hơn.' },
  gemini: { label: 'Gemini · Veo',          short: 'Gemini', icon: 'wand',     desc: 'Nhóm cuối — chỉ dùng khi đã cấu hình key.' },
  other:  { label: 'Khác (ngoài luồng)',    short: 'Khác',   icon: 'globe',    desc: 'Nhóm HỨNG provider lạ — chỉ dùng khi được gán làm mặc định cho một nhóm công việc.' },
};
const flowMeta = (token) => FLOW_META[token] || { label: token, short: token, icon: 'bot', desc: 'Nhóm provider ngoài luồng chuẩn.' };

// Bảng màu badge dùng trên nền tối — luôn kèm CHỮ, không dùng màu làm tín hiệu duy nhất.
const BADGE = 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold';
const BADGE_TONE = {
  neutral: 'bg-ink-700 text-cream-300',
  ok: 'bg-emerald-500/15 text-ok',
  warn: 'bg-amber-500/15 text-warn',
  danger: 'bg-red-500/15 text-danger',
  brand: 'bg-brand-600/25 text-brand-100',
  info: 'bg-sky-500/15 text-info',
  custom: 'bg-indigo-500/15 text-indigo-300',
};

// ─────────────────────────── Trạng thái trang ───────────────────────────
const data = ref(null);
const loading = ref(true);
const error = ref('');
const toast = ref(null);
const section = ref('overview');
const q = reactive({ keys: '', providers: '', models: '' });
const roleFilter = ref('');
const showLegacyModels = ref(false);

const providers = computed(() => (data.value && data.value.providers) || []);
const keys = computed(() => (data.value && data.value.api_keys) || []);
const models = computed(() => (data.value && data.value.models) || []);
const config = computed(() => (data.value && data.value.config) || {});
const usage = computed(() => (data.value && data.value.usage) || {});
const taskGroups = computed(() => (data.value && data.value.task_groups) || {});
const taskGroupKeys = computed(() => Object.keys(taskGroups.value));
const providerMap = computed(() => new Map(providers.value.map((p) => [p.slug, p])));
const providerOf = (slug) => providerMap.value.get(slug);
const providerName = (slug) => { const p = providerOf(slug); return p ? p.name : slug; };
const family = (slug) => { const p = providerOf(slug); return (p && p.family) || 'other'; };
const flowTokens = computed(() => ((data.value && data.value.provider_priority) || 'qwen,custom,flux,gemini').split(',').map((s) => s.trim()).filter(Boolean));
const flowRank = (slug) => { const i = flowTokens.value.indexOf(family(slug)); return i === -1 ? 99 : i + 1; };
const familyProviders = (token) => providers.value.filter((p) => (p.family || 'other') === token);
const familyConfigured = (token) => familyProviders(token).some((p) => p.configured && (p.custom || p.enabled));
const flowCounts = computed(() => (data.value && data.value.flow_counts) || {});
const sortedProviders = computed(() => [...providers.value].sort((a, b) => (a.rank == null ? 990 : a.rank) - (b.rank == null ? 990 : b.rank) || String(a.name).localeCompare(String(b.name))));
const customProviders = computed(() => providers.value.filter((p) => p.custom));

function flash(msg, ok = true) { toast.value = { msg, ok }; setTimeout(() => { toast.value = null; }, ok ? 2800 : 5200); }
function goTo(id) { section.value = id; }

async function load(quiet = false) {
  if (!quiet) loading.value = true;
  error.value = '';
  try {
    data.value = await api('/data');
    syncCfg();
    syncTasks();
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}

/** Chạy một thao tác ghi rồi nạp lại snapshot; lỗi hiện qua toast. */
async function run(fn, okMsg) {
  try { await fn(); if (okMsg) flash(okMsg); await load(true); return true; }
  catch (e) { flash(e.message, false); return false; }
}

// ─────────────────────────── Tổng quan: số liệu & việc cần xử lý ───────
const stats = computed(() => {
  const total = providers.value.length;
  const configured = providers.value.filter((p) => p.configured).length;
  const enabledModels = models.value.filter((m) => m.enabled).length;
  const assigned = taskGroupKeys.value.filter((g) => taskGroups.value[g].assigned).length;
  return {
    providers: { configured, total, missing: providers.value.filter((p) => p.enabled && !p.configured) },
    keys: { total: keys.value.length, providers: new Set(keys.value.map((k) => k.provider)).size, off: keys.value.filter((k) => !k.enabled).length },
    models: { total: models.value.length, enabled: enabledModels, off: models.value.length - enabledModels },
    tasks: { assigned, total: taskGroupKeys.value.length },
  };
});

const usageCards = computed(() => [
  { key: 'balance', label: 'Số dư credit', value: usage.value.balance == null ? 0 : usage.value.balance, icon: 'coins' },
  { key: 'used_total', label: 'Đã dùng (tổng)', value: usage.value.used_total == null ? 0 : usage.value.used_total, icon: 'history' },
  { key: 'used_today', label: 'Dùng hôm nay', value: usage.value.used_today == null ? 0 : usage.value.used_today, icon: 'clock' },
  { key: 'limit', label: 'Hạn mức / tháng', value: usage.value.limit ? usage.value.limit : '∞', icon: 'target' },
]);

/**
 * "Việc cần xử lý" — mỗi mục là một câu hỏi thật của người quản trị, kèm nút nhảy thẳng tới
 * chỗ sửa (điền trước dữ liệu khi có thể). Thứ tự: chặn chạy → giảm chất lượng → thông tin.
 */
const attention = computed(() => {
  const out = [];
  const p = stats.value.providers;
  if (!providers.value.length) {
    out.push({ icon: 'alertTriangle', tone: 'danger', title: 'Chưa nạp được provider', detail: 'Snapshot rỗng — thử tải lại trang.', action: 'Tải lại', run: () => load() });
    return out;
  }
  if (!keys.value.length) {
    out.push({ icon: 'key', tone: 'danger', title: 'Chưa có API key nào', detail: 'Studio sẽ chạy bằng stub hoặc key trong .env. Thêm key thật để gọi provider.', action: 'Thêm key', run: () => openKeyModal(null, p.missing.length ? p.missing[0].slug : 'qwen') });
  } else if (p.missing.length) {
    out.push({ icon: 'key', tone: 'warn', title: p.missing.length + ' provider chưa có key', detail: p.missing.slice(0, 4).map((x) => x.name).join(' · ') + (p.missing.length > 4 ? ' +' + (p.missing.length - 4) : ''), action: 'Thêm key', run: () => openKeyModal(null, p.missing[0].slug) });
  }
  const emptyGroups = taskGroupKeys.value.filter((g) => !taskGroups.value[g].models.length);
  if (emptyGroups.length) {
    out.push({ icon: 'target', tone: 'warn', title: emptyGroups.length + ' nhóm công việc chưa có model', detail: emptyGroups.map((g) => roleLabel(g)).join(' · ') + ' — các card này dùng cấu hình cũ.', action: 'Xem nhóm', run: () => goTo('tasks') });
  }
  const stuck = taskGroupKeys.value.filter((g) => {
    const d = taskGroups.value[g];
    if (!d.assigned) return false;
    const row = models.value.find((m) => m.provider + ':' + m.model_id === d.assigned);
    return row && !row.enabled;
  });
  if (stuck.length) {
    out.push({ icon: 'ban', tone: 'warn', title: stuck.length + ' nhóm gán model đang TẮT', detail: stuck.map((g) => roleLabel(g)).join(' · ') + ' — model bị tắt nhưng vẫn là mặc định của nhóm.', action: 'Xem model', run: () => { roleFilter.value = ''; goTo('models'); } });
  }
  if (stats.value.keys.off) {
    out.push({ icon: 'eyeOff', tone: 'info', title: stats.value.keys.off + ' API key đang tắt', detail: 'Key bị tắt không được dùng khi gọi model.', action: 'Xem key', run: () => { q.keys = ''; goTo('keys'); } });
  }
  if (stats.value.models.off) {
    out.push({ icon: 'square', tone: 'info', title: stats.value.models.off + ' model đang tắt', detail: 'Model bị tắt bị bỏ qua khi chọn theo ưu tiên.', action: 'Xem model', run: () => goTo('models') });
  }
  const idleFamilies = flowTokens.value.filter((t) => !(flowCounts.value[t] > 0));
  if (idleFamilies.length) {
    out.push({ icon: 'info', tone: 'info', title: 'Nhóm chưa có model: ' + idleFamilies.map((t) => flowMeta(t).short).join(', '), detail: 'Nhóm không có model vẫn nằm trong chuỗi fallback nhưng không được thử.', action: 'Đồng bộ catalog', run: () => goTo('flow') });
  }
  if (!out.length) {
    out.push({ icon: 'checkSquare', tone: 'ok', title: 'Không có việc nào đang chờ', detail: 'Provider đã có key, mọi nhóm công việc đều có model đang bật.', action: '', run: null });
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

// ─────────────────────────── Lọc & nhóm danh sách ───────────────────────────
const norm = (s) => String(s || '').toLowerCase();
const matches = (needle, ...fields) => !needle || fields.some((f) => norm(f).includes(needle));

const filteredKeys = computed(() => {
  const n = norm(q.keys);
  return keys.value.filter((k) => matches(n, k.label, k.provider, providerName(k.provider), k.kind, k.note));
});
const keysByProvider = computed(() => {
  const m = new Map();
  for (const k of filteredKeys.value) { if (!m.has(k.provider)) m.set(k.provider, []); m.get(k.provider).push(k); }
  return [...m.entries()];
});
const filteredCustomProviders = computed(() => {
  const n = norm(q.providers);
  return customProviders.value.filter((p) => matches(n, p.name, p.slug, p.protocol, p.base_url, p.note));
});
const filteredModels = computed(() => {
  const n = norm(q.models);
  return models.value.filter((m) => (!roleFilter.value || m.group === roleFilter.value) && matches(n, m.name, m.model_id, m.provider, providerName(m.provider), m.note));
});
const modelsByRole = computed(() => {
  const out = [];
  for (const role of ROLE_ORDER) {
    const rows = filteredModels.value.filter((m) => m.group === role);
    if (rows.length) out.push({ role, rows });
  }
  // Vai trò lạ (backend thêm sau) vẫn phải hiện — không im lặng bỏ mất model.
  const unknown = filteredModels.value.filter((m) => ROLE_ORDER.indexOf(m.group) === -1);
  if (unknown.length) out.push({ role: 'unknown', rows: unknown });
  return out;
});
const visibleModels = computed(() => modelsByRole.value.filter((g) => showLegacyModels.value || roleFilter.value || !isLegacyRole(g.role)));
const hiddenLegacyCount = computed(() => modelsByRole.value.filter((g) => !showLegacyModels.value && !roleFilter.value && isLegacyRole(g.role)).reduce((n, g) => n + g.rows.length, 0));
const countText = (shown, total, unit) => (shown === total ? total + ' ' + unit : 'Hiện ' + shown + '/' + total + ' ' + unit);

// ─────────────────────────── Key hygiene (giữ nguyên luật cũ) ──────────────
const KEY_PREFIX = /^(sk-|sk_|sk-ws-|sk-or-|AIza|xai-|gsk_|fal-|Bearer\s)/i;
function looksLikeApiKey(v) {
  const s = String(v || '').trim();
  if (!s) return false;
  return s.length > 60 || KEY_PREFIX.test(s);
}
function keyRefWarning() {
  return 'Đây là ô TÊN NHÓM KEY (vd: ckey), không phải khoá API. Khoá thật dán ở mục API Keys với provider = slug của provider này.';
}
function validKey(v) {
  const t = String(v || '').trim();
  if (!t) return 'Key không được để trống.';
  if (t.indexOf('=') !== -1 && t.indexOf('sk-') !== 0) return 'Key dạng "NAME=value" — chỉ dán giá trị key, không dán cả dòng env.';
  if (t.charAt(0) === '"' && t.charAt(t.length - 1) === '"') return 'Key đang bọc trong dấu ngoặc kép — bỏ ngoặc rồi dán lại.';
  return null;
}

// ─────────────────────────── Hộp thoại: API key ───────────────────────────
const blankKey = (provider = '') => ({ provider, label: '', value: '', kind: '', priority: 5, note: '', enabled: true });
const keyModal = reactive({ open: false, mode: 'create', id: null, form: blankKey(), errors: {}, saving: false });
function openKeyModal(row = null, presetProvider = '') {
  Object.assign(keyModal, {
    open: true,
    mode: row ? 'edit' : 'create',
    id: row ? row.id : null,
    errors: {},
    saving: false,
    form: row
      ? { provider: row.provider, label: row.label, value: '', kind: row.kind || '', priority: row.priority, note: row.note || '', enabled: !!row.enabled }
      : blankKey(presetProvider),
  });
}
function closeKeyModal() { keyModal.open = false; }

function validateKey() {
  const f = keyModal.form;
  const e = {};
  if (!f.provider) e.provider = 'Chọn provider cho key.';
  if (!String(f.label).trim()) e.label = 'Nhập nhãn để phân biệt các key cùng provider.';
  if (keyModal.mode === 'create') { const m = validKey(f.value); if (m) e.value = m; }
  else if (String(f.value).trim()) { const m = validKey(f.value); if (m) e.value = m; }
  const p = Number(f.priority);
  if (!Number.isFinite(p) || p < 0 || p > 100) e.priority = 'Ưu tiên trong khoảng 0–100.';
  keyModal.errors = e;
  return !Object.keys(e).length;
}
async function submitKey() {
  if (!validateKey()) return;
  keyModal.saving = true;
  const f = keyModal.form;
  const payload = { provider: f.provider, label: f.label.trim(), kind: f.kind, priority: Number(f.priority) || 0, note: f.note, enabled: !!f.enabled };
  if (String(f.value).trim()) payload.value = f.value;
  const ok = await run(async () => {
    if (keyModal.mode === 'create') await api('/keys', 'POST', payload);
    else await api('/keys/' + keyModal.id, 'PUT', payload);
  }, keyModal.mode === 'create' ? 'Đã thêm API key.' : 'Đã cập nhật API key.');
  keyModal.saving = false;
  if (ok) closeKeyModal();
}

const testState = reactive({ running: null, id: null, ok: false, text: '' });
async function testKey(k) {
  testState.running = k.id; testState.id = null;
  try {
    const d = await api('/keys/' + k.id + '/test', 'POST');
    testState.id = k.id; testState.ok = !!d.ok;
    testState.text = d.note + (d.key_prefix ? ' (' + d.key_prefix + ')' : '');
  } catch (e) {
    testState.id = k.id; testState.ok = false; testState.text = e.message;
  }
  testState.running = null;
}
async function toggleKey(k) {
  await run(() => api('/keys/' + k.id, 'PUT', { provider: k.provider, label: k.label, kind: k.kind, priority: k.priority, note: k.note, enabled: !k.enabled }),
    k.enabled ? 'Đã tắt key «' + k.label + '»' : 'Đã bật key «' + k.label + '»');
}

// ─────────────────────────── Hộp thoại: custom provider ───────────────────────────
const blankProv = () => ({ name: '', protocol: 'openai', base_url: '', auth_style: 'bearer', api_key_ref: '', priority: 5, note: '', enabled: true, slug: '' });
// slugKey = KHOÁ ĐỊNH TUYẾN của bản ghi (routeKey của model là slug, KHÔNG phải id số)
// — trước đây form gửi id nên PUT/DELETE đều 404 (không sửa/xoá được provider).
const provModal = reactive({ open: false, mode: 'create', id: null, slugKey: null, form: blankProv(), errors: {}, saving: false });
const PROTOCOL_HINT = {
  openai: 'OpenAI-compatible — POST {base}/chat/completions · auth Bearer. Dùng cho OpenRouter, Together, Groq, vLLM, CKEY (api.xah.io/v1)…',
  dashscope: 'DashScope-compatible — POST {base}/api/v1/…/generation (sinh ảnh/video).',
  gemini: 'Gemini-compatible — POST {base}/v1beta/models/{model}:generateContent.',
};
function openProvModal(row = null, preset = null) {
  Object.assign(provModal, {
    open: true,
    mode: row ? 'edit' : 'create',
    id: row ? row.id : null,
    slugKey: row ? row.slug : null,
    errors: {},
    saving: false,
    form: row
      ? { slug: row.slug, name: row.name, protocol: row.protocol, base_url: row.base_url, auth_style: row.auth_style, api_key_ref: row.api_key_ref, priority: row.priority ?? 5, note: row.note || '', enabled: !!row.enabled }
      : Object.assign(blankProv(), preset || {}),
  });
}
function closeProvModal() { provModal.open = false; }

function validateProv() {
  const f = provModal.form;
  const e = {};
  if (provModal.mode === 'create' && !/^[a-z0-9][a-z0-9_-]*$/.test(String(f.slug).trim())) e.slug = 'Provider ID: chữ thường/số, bắt đầu bằng chữ hoặc số (vd: ckey, openrouter).';
  if (!String(f.name).trim()) e.name = 'Nhập tên hiển thị.';
  if (!/^https?:\/\/[^/]+/.test(String(f.base_url).trim())) e.base_url = 'Base URL phải bắt đầu bằng http(s):// và có host.';
  if (f.api_key_ref && looksLikeApiKey(f.api_key_ref)) e.api_key_ref = keyRefWarning();
  provModal.errors = e;
  return !Object.keys(e).length;
}
async function submitProv() {
  if (!validateProv()) return;
  provModal.saving = true;
  const f = provModal.form;
  const payload = {
    name: f.name.trim(), protocol: f.protocol, base_url: f.base_url.trim(), auth_style: f.auth_style,
    api_key_ref: f.api_key_ref.trim(), priority: Number(f.priority) || 0, note: f.note, enabled: !!f.enabled,
  };
  if (provModal.mode === 'create') payload.slug = f.slug.trim();
  const ok = await run(async () => {
    if (provModal.mode === 'create') await api('/providers', 'POST', payload);
    // Route bind theo SLUG (routeKey của StudioProvider) — gửi id số sẽ 404.
    else await api('/providers/' + provModal.slugKey, 'PUT', payload);
  }, provModal.mode === 'create' ? 'Đã thêm custom provider.' : 'Đã cập nhật custom provider.');
  provModal.saving = false;
  if (ok) closeProvModal();
}
// MẪU khai báo provider: dữ liệu do backend trả về (studio_provider_templates) — CKEY chỉ
// là MỘT mục ngang hàng OpenRouter/Together/Groq…, không còn nhánh code riêng cho hãng nào.
// Thứ tự THỰC TẾ của một custom provider trong nhóm Custom (priority giảm dần) —
// cho admin thấy ngay số ưu tiên biến thành thứ tự gọi như thế nào.
function customOrder(p) {
  const list = [...(customProviders.value || [])].sort((a, b) => (b.priority ?? 5) - (a.priority ?? 5) || String(a.slug).localeCompare(String(b.slug)));
  return list.findIndex((x) => x.slug === p.slug) + 1;
}
const providerTemplates = computed(() => data.value?.provider_templates || {});
const tplPick = ref('');
function applyProviderTemplate(key) {
  const tpl = providerTemplates.value[key];
  if (!tpl) return;
  openProvModal(null, {
    slug: tpl.slug || '',
    name: tpl.label || '',
    protocol: tpl.protocol || 'openai',
    base_url: tpl.base_url || '',
    auth_style: tpl.auth_style || 'bearer',
    api_key_ref: tpl.api_key_ref || '',
    note: tpl.note || '',
  });
  flash('Đã điền mẫu « ' + (tpl.label || key) + ' » — kiểm tra Base URL rồi bấm "Thêm provider" (KHÔNG dán khoá API vào ô Key ref).');
}
function gotoAddKey(slug) { closeProvModal(); openKeyModal(null, slug || ''); }

// ─────────────────────────── Hộp thoại: model ───────────────────────────
const blankModel = () => ({ group: 'image', name: '', provider: '', model_id: '', api_key_ref: '', priority: 5, note: '', enabled: true });
const modelModal = reactive({ open: false, mode: 'create', id: null, form: blankModel(), errors: {}, saving: false });
function openModelModal(row = null, presetRole = '') {
  Object.assign(modelModal, {
    open: true,
    mode: row ? 'edit' : 'create',
    id: row ? row.id : null,
    errors: {},
    saving: false,
    form: row
      ? { group: row.group, name: row.name, provider: row.provider, model_id: row.model_id, api_key_ref: row.api_key_ref || '', priority: row.priority, note: row.note || '', enabled: !!row.enabled }
      : Object.assign(blankModel(), presetRole ? { group: presetRole } : {}),
  });
}
function closeModelModal() { modelModal.open = false; }

// Chọn provider ⇒ điền sẵn key ref (= slug) và gợi ý ưu tiên theo nhóm luồng (qwen 10 · custom 5 · flux 3 · gemini 1).
const FAMILY_PRIORITY = { qwen: 10, custom: 5, flux: 3, gemini: 1, other: 2 };
function onModelProviderChange() {
  const f = modelModal.form;
  if (!f.provider) return;
  if (!String(f.api_key_ref || '').trim()) f.api_key_ref = f.provider;
  const suggestion = FAMILY_PRIORITY[family(f.provider)];
  f.priority = suggestion == null ? 5 : suggestion;
}
function validateModel() {
  const f = modelModal.form;
  const e = {};
  if (!f.group) e.group = 'Chọn vai trò (nhóm công việc).';
  if (!String(f.name).trim()) e.name = 'Nhập tên model.';
  if (!f.provider) e.provider = 'Chọn provider.';
  if (!String(f.model_id).trim()) e.model_id = 'Nhập Model ID (id gửi lên API).';
  if (f.api_key_ref && looksLikeApiKey(f.api_key_ref)) e.api_key_ref = 'Key ref là TÊN NHÓM KEY (vd: qwen), không phải khoá API.';
  const p = Number(f.priority);
  if (!Number.isFinite(p) || p < 0 || p > 100) e.priority = 'Ưu tiên trong khoảng 0–100.';
  modelModal.errors = e;
  return !Object.keys(e).length;
}
async function submitModel() {
  if (!validateModel()) return;
  modelModal.saving = true;
  const f = modelModal.form;
  const payload = { group: f.group, name: f.name.trim(), provider: f.provider, model_id: f.model_id.trim(), api_key_ref: f.api_key_ref.trim(), priority: Number(f.priority) || 0, note: f.note, enabled: !!f.enabled };
  const ok = await run(async () => {
    if (modelModal.mode === 'create') await api('/models', 'POST', payload);
    else await api('/models/' + modelModal.id, 'PUT', payload);
  }, modelModal.mode === 'create' ? 'Đã thêm model.' : 'Đã cập nhật model.');
  modelModal.saving = false;
  if (ok) closeModelModal();
}

// ─────────────────────────── Xác nhận thao tác phá huỷ ───────────────────────────
const confirmBox = reactive({ open: false, title: '', message: '', label: 'Xoá', busy: false, run: null });
function askConfirm(title, message, label, fn) {
  Object.assign(confirmBox, { open: true, title, message, label, busy: false, run: fn });
}
async function confirmRun() {
  if (!confirmBox.run) return;
  confirmBox.busy = true;
  await confirmBox.run();
  confirmBox.busy = false;
  confirmBox.open = false;
}
const askDeleteKey = (k) => askConfirm('Xoá API key?', 'Key «' + k.label + '» (' + providerName(k.provider) + ') sẽ bị xoá vĩnh viễn. Model trỏ tới provider này sẽ mất key và ngừng gọi được cho tới khi bạn thêm key khác.', 'Xoá key', async () => { await run(() => api('/keys/' + k.id, 'DELETE'), 'Đã xoá API key.'); });
const askDeleteProv = (p) => askConfirm('Xoá custom provider?', 'Provider «' + p.name + '» (slug ' + p.slug + ') sẽ bị xoá. Mọi model tham chiếu slug này sẽ không còn gọi được — kể cả key bạn đã đăng ký cho nó.', 'Xoá provider', async () => { await run(() => api('/providers/' + p.slug, 'DELETE'), 'Đã xoá custom provider.'); });
const askDeleteModel = (m) => askConfirm('Xoá model?', 'Model «' + m.name + '» (' + m.provider + ' · ' + m.model_id + ') sẽ bị xoá khỏi registry. Nhóm công việc đang gán model này sẽ quay về tự động.', 'Xoá model', async () => { await run(() => api('/models/' + m.id, 'DELETE'), 'Đã xoá model.'); });

// ─────────────────────────── Luồng ưu tiên ───────────────────────────
const flowSaving = ref(false);
const syncSaving = ref(false);
async function saveFlow(tokens) {
  flowSaving.value = true;
  await run(() => api('/provider-priority', 'POST', { value: (tokens || flowTokens.value).join(',') }), 'Đã lưu luồng ưu tiên provider.');
  flowSaving.value = false;
}
async function moveFlow(i, dir) {
  const t = [...flowTokens.value];
  const j = i + dir;
  if (j < 0 || j >= t.length) return;
  const tmp = t[i]; t[i] = t[j]; t[j] = tmp;
  await saveFlow(t);
}
async function syncModels() {
  syncSaving.value = true;
  try {
    const d = await api('/sync-catalog', 'POST', {});
    await load(true);
    flash('Đã đồng bộ catalog: ' + (d.created == null ? 0 : d.created) + ' model mới, ' + (d.updated == null ? 0 : d.updated) + ' cập nhật.');
  } catch (e) { flash(e.message, false); }
  syncSaving.value = false;
}

// ─────────────────────────── Nhóm công việc ───────────────────────────
const taskDraft = reactive({});
const taskSaving = ref('');
function syncTasks() {
  for (const g of Object.keys(taskGroups.value)) taskDraft[g] = taskGroups.value[g].assigned || '';
}
const taskValue = (g) => (g in taskDraft ? taskDraft[g] : ((taskGroups.value[g] && taskGroups.value[g].assigned) || ''));
function taskDefaultLabel(g) {
  const d = taskGroups.value[g] && taskGroups.value[g].default;
  if (!d) return '— chưa có —';
  const list = (taskGroups.value[g] && taskGroups.value[g].models) || [];
  const m = list.find((x) => x.provider + ':' + x.model === d);
  return (m && m.label) || d;
}
/** Lựa chọn cho một nhóm: model ĐÃ thuộc nhóm + các model khác trong registry (để gán nhanh). */
function taskGroupModelOptions(g) {
  const groupModels = (taskGroups.value[g] && taskGroups.value[g].models) || [];
  const seen = new Set(groupModels.map((m) => m.provider + ':' + m.model));
  const opts = groupModels.map((m) => ({ value: m.provider + ':' + m.model, label: m.label + (m.default ? ' ★' : ''), registry: !!m.registry_id }));
  for (const m of models.value) {
    const v = m.provider + ':' + m.model_id;
    if (!seen.has(v)) { opts.push({ value: v, label: m.name + ' — chưa thuộc nhóm', registry: true }); seen.add(v); }
  }
  return opts;
}
async function saveTaskDefault(g) {
  taskSaving.value = g;
  const v = taskValue(g);
  const ok = await run(() => api('/task-defaults', 'POST', { group: g, value: v || '' }),
    v ? 'Đã gán model mặc định cho «' + roleLabel(g) + '»' : 'Nhóm «' + roleLabel(g) + '» về chế độ tự động');
  if (!ok) syncTasks();
  taskSaving.value = '';
}
function clearTaskDefault(g) { taskDraft[g] = ''; saveTaskDefault(g); }

// ─────────────────────────── Cấu hình chung ───────────────────────────
const cfgForm = ref(null);
const cfgSaving = ref(false);
function syncCfg() { cfgForm.value = Object.assign({}, config.value); }
const cfgDirty = computed(() => !!cfgForm.value && JSON.stringify(cfgForm.value) !== JSON.stringify(config.value));
async function saveConfig() {
  cfgSaving.value = true;
  await run(() => api('/config', 'POST', cfgForm.value), 'Đã lưu cấu hình.');
  cfgSaving.value = false;
}

// ─────────────────────────── Khởi động & đồng bộ URL ───────────────────────────
function sectionFromUrl() {
  const raw = new URLSearchParams(window.location.search).get('tab') || window.location.hash.replace('#', '');
  return SECTIONS.some((s) => s.id === raw) ? raw : 'overview';
}
watch(section, (v) => {
  try {
    const url = new URL(window.location.href);
    if (v === 'overview') url.searchParams.delete('tab'); else url.searchParams.set('tab', v);
    history.replaceState(history.state, '', url);
  } catch (e) { /* môi trường không cho sửa URL — không được làm hỏng trang */ }
});
// Điều hướng bằng bàn phím trong danh mục: ↑/↓/Home/End như một menu thật.
function navKey(e, i) {
  const list = SECTIONS;
  let next = -1;
  if (e.key === 'ArrowDown') next = (i + 1) % list.length;
  else if (e.key === 'ArrowUp') next = (i - 1 + list.length) % list.length;
  else if (e.key === 'Home') next = 0;
  else if (e.key === 'End') next = list.length - 1;
  if (next === -1) return;
  e.preventDefault();
  section.value = list[next].id;
  const el = document.querySelector('[data-nav="' + list[next].id + '"]');
  if (el) el.focus();
}
function navBadge(id) {
  const map = {
    keys: stats.value.keys.total || '',
    providers: customProviders.value.length || '',
    models: stats.value.models.total || '',
    tasks: stats.value.tasks.total ? stats.value.tasks.assigned + '/' + stats.value.tasks.total : '',
    overview: attention.value[0] && attention.value[0].tone !== 'ok' ? attention.value.length : '',
  };
  return map[id] == null ? '' : map[id];
}

onMounted(() => { section.value = sectionFromUrl(); load(); });
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
            <StudioIcon name="gear" size="h-4 w-4" class="text-brand-300" />
            Cài đặt Studio
          </h1>
          <p class="mt-0.5 hidden truncate text-[11px] text-cream-300 sm:block">
            Nhà cung cấp · API key · model — cấu hình ở đây áp dụng cho mọi tài khoản FabrikAI.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <span v-if="data" :class="[BADGE, stats.providers.missing.length ? BADGE_TONE.warn : BADGE_TONE.ok]">
            <StudioIcon :name="stats.providers.missing.length ? 'alertTriangle' : 'check'" size="h-3 w-3" />
            {{ stats.providers.configured }}/{{ stats.providers.total }} provider có key
          </span>
          <button class="tool-btn" :disabled="loading" title="Nạp lại dữ liệu từ máy chủ" @click="load()">
            <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="{ 'animate-spin': loading }" />
            <span class="hidden sm:inline">Tải lại</span>
          </button>
          <a href="/admin" class="tool-btn" title="Bảng quản trị owner">
            <StudioIcon name="shieldCheck" size="h-3.5 w-3.5" />
            <span class="hidden sm:inline">Quản trị</span>
          </a>
        </div>
      </div>
    </header>

    <div class="mx-auto w-full max-w-[1400px] px-4 py-4 sm:px-5 lg:px-6 lg:py-6">
      <!-- Trạng thái tải / lỗi -->
      <div v-if="loading && !data" class="space-y-3">
        <div class="card animate-pulse p-5"><div class="h-4 w-40 rounded bg-ink-700"></div><div class="mt-3 h-3 w-72 rounded bg-ink-700/70"></div></div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <div v-for="i in 4" :key="i" class="card h-24 animate-pulse"></div>
        </div>
      </div>
      <div v-else-if="error" class="card border-red-500/40 p-6 text-sm text-danger">
        <p class="flex items-center gap-2 font-semibold"><StudioIcon name="alertTriangle" size="h-4 w-4" /> Không nạp được cấu hình</p>
        <p class="mt-1 text-xs text-danger">{{ error }}</p>
        <button class="btn-outline btn-sm mt-3" @click="load()">Thử lại</button>
      </div>

      <div v-else class="grid grid-cols-1 gap-5 lg:grid-cols-[15.5rem_minmax(0,1fr)]">
        <!-- ═════════ Danh mục (desktop) ═════════ -->
        <nav class="hidden lg:sticky lg:top-[4.75rem] lg:block lg:self-start" aria-label="Mục cài đặt">
          <div v-for="group in SECTION_GROUPS" :key="group" class="mb-4">
            <p class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-cream-300">{{ group }}</p>
            <ul class="space-y-0.5">
              <li v-for="s in sectionsIn(group)" :key="s.id">
                <button :data-nav="s.id" @click="goTo(s.id)" @keydown="navKey($event, SECTIONS.indexOf(s))"
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
          <p class="px-3 text-[10px] leading-relaxed text-cream-300">
            Cài đặt ở đây là TOÀN CỤC. Tuỳ chọn riêng của bạn nằm ở
            <a href="/presets" class="link">Prompt Templates</a> và
            <a href="/model-settings" class="link">Khuôn mặt &amp; Dáng</a>.
          </p>
        </nav>

        <!-- ═════════ Danh mục (màn hẹp) ═════════ -->
        <div class="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 lg:hidden scrollbar-hide" role="tablist" aria-label="Mục cài đặt">
          <button v-for="s in SECTIONS" :key="s.id" role="tab" :aria-selected="section === s.id"
                  @click="goTo(s.id)"
                  :class="section === s.id ? 'border-brand-500 bg-brand-600/20 text-cream-50' : 'border-ink-600 bg-ink-800 text-cream-300'"
                  class="flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold">
            <StudioIcon :name="s.icon" size="h-3.5 w-3.5" />
            {{ s.label }}
            <span v-if="navBadge(s.id)" class="rounded-full bg-ink-900/60 px-1.5 text-[10px]">{{ navBadge(s.id) }}</span>
          </button>
        </div>

        <!-- ═════════ Nội dung ═════════ -->
        <main class="min-w-0 space-y-5">
          <!-- ───── TỔNG QUAN ───── -->
          <section v-show="section === 'overview'" class="space-y-5">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <button v-for="card in [
                        { id: 'providers', label: 'Provider có key', value: stats.providers.configured + '/' + stats.providers.total, icon: 'globe',
                          note: stats.providers.missing.length ? stats.providers.missing.length + ' provider chưa có key' : 'Tất cả provider đã có key' },
                        { id: 'keys', label: 'API key', value: stats.keys.total, icon: 'key',
                          note: stats.keys.total ? stats.keys.providers + ' provider · ' + stats.keys.off + ' key đang tắt' : 'Chưa có key nào' },
                        { id: 'models', label: 'Model đã đăng ký', value: stats.models.total, icon: 'server',
                          note: stats.models.enabled + ' đang bật · ' + stats.models.off + ' đang tắt' },
                        { id: 'tasks', label: 'Nhóm gán thủ công', value: stats.tasks.assigned + '/' + stats.tasks.total, icon: 'target',
                          note: (stats.tasks.total - stats.tasks.assigned) + ' nhóm đang dùng model tự động' },
                      ]" :key="card.id" @click="goTo(card.id)"
                      class="card p-4 text-left transition-colors hover:border-brand-400">
                <div class="flex items-start justify-between gap-2">
                  <p class="text-[11px] font-semibold uppercase tracking-wide text-cream-300">{{ card.label }}</p>
                  <StudioIcon :name="card.icon" size="h-4 w-4" class="text-brand-300/70" />
                </div>
                <p class="mt-1.5 font-display text-2xl font-semibold text-cream-50">{{ card.value }}</p>
                <p class="mt-0.5 text-[11px] text-cream-300">{{ card.note }}</p>
              </button>
            </div>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
              <div class="card p-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                  <StudioIcon name="alertTriangle" size="h-4 w-4" class="text-warn" />
                  Việc cần xử lý
                </h2>
                <ul class="mt-3 space-y-2">
                  <li v-for="(item, i) in attention" :key="i"
                      :class="attentionTone(item.tone)"
                      class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-3 py-2.5">
                    <StudioIcon :name="item.icon" size="h-4 w-4 shrink-0" />
                    <div class="min-w-0 flex-1">
                      <p class="text-xs font-semibold">{{ item.title }}</p>
                      <p class="mt-0.5 text-[11px] font-normal opacity-80">{{ item.detail }}</p>
                    </div>
                    <button v-if="item.run" class="tool-btn shrink-0" @click="item.run()">
                      {{ item.action }}
                      <StudioIcon name="arrowRight" size="h-3.5 w-3.5" />
                    </button>
                  </li>
                </ul>
              </div>

              <div class="space-y-5">
                <div class="card p-4">
                  <h2 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                    <StudioIcon name="sliders" size="h-4 w-4" class="text-brand-300" />
                    Luồng ưu tiên
                    <button class="tool-btn ml-auto" @click="goTo('flow')">Sửa thứ tự</button>
                  </h2>
                  <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <template v-for="(t, i) in flowTokens" :key="t">
                      <span :class="[BADGE, familyConfigured(t) ? BADGE_TONE.ok : BADGE_TONE.neutral]">
                        <StudioIcon :name="flowMeta(t).icon" size="h-3 w-3" />
                        {{ flowMeta(t).short }}
                        <span class="opacity-70">{{ flowCounts[t] || 0 }} model</span>
                      </span>
                      <StudioIcon v-if="i < flowTokens.length - 1" name="arrowRight" size="h-3 w-3" class="text-cream-300" />
                    </template>
                  </div>
                  <p class="mt-2.5 text-[11px] leading-relaxed text-cream-300">
                    Khi tạo ảnh/video/suy luận, hệ thống thử theo thứ tự trên cho tới khi có kết quả.
                    Model gán riêng cho từng nhóm công việc thắng chuỗi này.
                  </p>
                </div>

                <div class="card p-4">
                  <h2 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                    <StudioIcon name="coins" size="h-4 w-4" class="text-brand-300" />
                    Sử dụng
                  </h2>
                  <div class="mt-3 grid grid-cols-2 gap-2.5">
                    <div v-for="u in usageCards" :key="u.key" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
                      <p class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-cream-300">
                        <StudioIcon :name="u.icon" size="h-3 w-3" /> {{ u.label }}
                      </p>
                      <p class="mt-1 text-lg font-semibold text-cream-50">{{ u.value }}</p>
                    </div>
                  </div>
                  <p v-if="usage.quota_resets_at" class="mt-2 text-[11px] text-cream-300">Hạn mức đặt lại: {{ usage.quota_resets_at }}</p>
                </div>
              </div>
            </div>

            <div class="card flex flex-wrap items-center gap-2 p-4">
              <p class="mr-auto text-[11px] font-semibold uppercase tracking-wide text-cream-300">Thao tác nhanh</p>
              <button class="btn-brand btn-sm" @click="openKeyModal()"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm API key</button>
              <button class="btn-outline btn-sm" @click="openProvModal()"><StudioIcon name="globe" size="h-3.5 w-3.5" /> Thêm provider</button>
              <button class="btn-outline btn-sm" @click="openModelModal()"><StudioIcon name="server" size="h-3.5 w-3.5" /> Thêm model</button>
              <button class="btn-outline btn-sm" :disabled="syncSaving" @click="syncModels()">
                <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="{ 'animate-spin': syncSaving }" /> Đồng bộ catalog
              </button>
            </div>
          </section>

          <!-- ───── LUỒNG ƯU TIÊN ───── -->
          <section v-show="section === 'flow'" class="space-y-5">
            <div class="card p-5">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="sliders" size="h-4 w-4" class="text-brand-300" /> Luồng ưu tiên provider
                  </h2>
                  <p class="mt-1 max-w-2xl text-xs text-cream-300">
                    Thứ tự thử provider cho MỌI nhóm công việc. Nhóm trên được thử trước; trong cùng một nhóm thì xét Ưu tiên model giảm dần.
                  </p>
                </div>
                <button class="btn-brand btn-sm" :disabled="syncSaving" @click="syncModels()">
                  <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="{ 'animate-spin': syncSaving }" />
                  {{ syncSaving ? 'Đang đồng bộ…' : 'Đồng bộ catalog QwenCloud' }}
                </button>
              </div>

              <div class="mt-4 flex flex-wrap items-center gap-1.5">
                <template v-for="(t, i) in flowTokens" :key="t">
                  <span :class="[BADGE, familyConfigured(t) ? BADGE_TONE.ok : BADGE_TONE.neutral]" class="!px-2.5 !py-1 !text-[11px]">
                    <StudioIcon :name="flowMeta(t).icon" size="h-3.5 w-3.5" />
                    {{ flowMeta(t).short }}
                    <span class="opacity-70">{{ flowCounts[t] || 0 }} model</span>
                  </span>
                  <StudioIcon v-if="i < flowTokens.length - 1" name="arrowRight" size="h-3.5 w-3.5" class="text-cream-300" />
                </template>
              </div>

              <ol class="mt-4 space-y-2">
                <li v-for="(t, i) in flowTokens" :key="t" class="rounded-lg border border-ink-700 bg-ink-900/40 p-3.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-brand-600/20 text-xs font-bold text-brand-200">#{{ i + 1 }}</span>
                    <div class="min-w-0 flex-1">
                      <p class="flex items-center gap-1.5 text-sm font-semibold text-cream-50">
                        <StudioIcon :name="flowMeta(t).icon" size="h-3.5 w-3.5" class="text-cream-300" />
                        {{ flowMeta(t).label }}
                      </p>
                      <p class="mt-0.5 text-[11px] text-cream-300">{{ flowMeta(t).desc }}</p>
                    </div>
                    <span :class="[BADGE, familyConfigured(t) ? BADGE_TONE.ok : BADGE_TONE.warn]">{{ familyConfigured(t) ? 'đã có key' : 'chưa có key' }}</span>
                    <span class="flex items-center gap-1">
                      <button class="icon-btn" :disabled="i === 0 || flowSaving" :aria-label="'Đưa ' + flowMeta(t).short + ' lên trước'" title="Lên" @click="moveFlow(i, -1)"><StudioIcon name="chevronUp" size="h-3.5 w-3.5" /></button>
                      <button class="icon-btn" :disabled="i === flowTokens.length - 1 || flowSaving" :aria-label="'Đưa ' + flowMeta(t).short + ' xuống sau'" title="Xuống" @click="moveFlow(i, 1)"><StudioIcon name="chevronDown" size="h-3.5 w-3.5" /></button>
                    </span>
                  </div>
                  <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span v-for="p in familyProviders(t)" :key="p.slug" :class="[BADGE, p.configured ? BADGE_TONE.ok : BADGE_TONE.neutral]">
                      <span class="h-1.5 w-1.5 rounded-full" :class="p.configured ? 'bg-emerald-400' : 'bg-amber-400'"></span>
                      {{ p.name }}
                      <span v-if="p.key_count" class="opacity-70">×{{ p.key_count }}</span>
                    </span>
                    <span v-if="!familyProviders(t).length" class="text-[11px] text-cream-300">Chưa có provider nào trong nhóm này.</span>
                    <template v-if="t === 'custom'">
                      <button class="tool-btn" @click="goTo('providers')">Khai báo route (có mẫu sẵn) <StudioIcon name="arrowRight" size="h-3.5 w-3.5" /></button>
                    </template>
                  </div>
                </li>
              </ol>

              <p class="mt-3 flex items-start gap-2 rounded-lg border border-ink-700 bg-ink-900/60 p-2.5 text-[11px] text-cream-300">
                <StudioIcon name="info" size="h-3.5 w-3.5 shrink-0 mt-px text-info" />
                <span>Nút "Đồng bộ catalog" nhập danh sách model QwenCloud tích hợp trong mã nguồn vào Model Registry (idempotent — không đụng model bạn đã sửa tay). Khi QwenCloud ra model mới: cập nhật catalog trong <code class="rounded bg-ink-800 px-1">helpers.php</code> rồi bấm lại.</span>
              </p>
            </div>

            <details class="card p-4">
              <summary class="cursor-pointer text-sm font-semibold text-cream-100">Trợ giúp · Custom provider &amp; gateway OpenAI-compatible</summary>
              <div class="mt-2 space-y-1.5 text-[11px] text-cream-300">
                <p>· Mọi gateway <b class="text-cream-100">OpenAI-compatible</b> đều dùng được ngay: khai báo Base URL (kết thúc bằng <code class="rounded bg-ink-800 px-1">/v1</code>), chọn protocol <b class="text-cream-100">openai</b> + auth <b class="text-cream-100">Bearer</b>. Chat/vision/prompt đi qua <code class="rounded bg-ink-800 px-1">POST /chat/completions</code>.</p>
                <p>· Ảnh: nếu gateway phục vụ OpenAI Images API thì FabrikAI gọi <code class="rounded bg-ink-800 px-1">POST /images/generations</code> (trả <code class="rounded bg-ink-800 px-1">data[0].url</code> hoặc <code class="rounded bg-ink-800 px-1">b64_json</code>) — không cần cấu hình thêm.</p>
                <p>· Ví dụ có sẵn trong <b class="text-cream-100">danh sách mẫu</b>: CKEY (api.xah.io/v1 · giá VND · ~120–1.100 ₫/ảnh qwen-image), OpenRouter, Together, Groq, SiliconFlow, DeepInfra, DashScope quốc tế.</p>
                <p>· Chọn mẫu ở ô <b class="text-cream-100">Mẫu khai báo</b> phía trên để điền sẵn form, rồi bấm <b class="text-cream-100">Thêm provider</b>. Thêm gateway mới cho mọi người dùng = thêm một mục trong <code class="rounded bg-ink-800 px-1">studio_provider_templates()</code>.</p>
              </div>
            </details>
          </section>

          <!-- ───── API KEYS ───── -->
          <section v-show="section === 'keys'" class="space-y-5">
            <div class="card p-5">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="key" size="h-4 w-4" class="text-brand-300" /> API Keys
                    <span :class="[BADGE, BADGE_TONE.neutral]">{{ keys.length }}</span>
                  </h2>
                  <p class="mt-1 max-w-2xl text-xs text-cream-300">
                    Một provider có thể có nhiều key (Qwen: Token-Plan + Pay-As-You-Go…). Key là <b class="text-cream-100">write-only</b> — chỉ lưu, không bao giờ đọc lại.
                  </p>
                </div>
                <button class="btn-brand btn-sm" @click="openKeyModal()"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm API key</button>
              </div>

              <div class="mt-4 flex flex-wrap items-center gap-2">
                <div class="relative min-w-[14rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-300" />
                  <input v-model="q.keys" type="search" aria-label="Tìm API key" class="input !py-2 !pl-9 text-xs" placeholder="Tìm theo nhãn, provider, loại hoặc ghi chú…">
                </div>
                <span class="text-[11px] text-cream-300">{{ countText(filteredKeys.length, keys.length, 'key') }}</span>
              </div>

              <div v-if="!keys.length" class="mt-4 flex flex-col items-center gap-2 rounded-lg border border-dashed border-ink-600 p-8 text-center">
                <StudioIcon name="key" size="h-6 w-6" class="text-cream-300" />
                <p class="text-sm font-semibold text-cream-100">Chưa có API key nào</p>
                <p class="max-w-md text-[11px] text-cream-300">Provider nào có key sẽ chuyển từ stub sang gọi API thật. Key trong registry ưu tiên hơn biến môi trường trong .env.</p>
                <button class="btn-brand btn-sm mt-1" @click="openKeyModal()">Thêm key đầu tiên</button>
              </div>

              <div v-else-if="!filteredKeys.length" class="mt-4 rounded-lg border border-dashed border-ink-600 p-6 text-center text-xs text-cream-300">
                Không có key nào khớp « {{ q.keys }} ».
                <button class="ml-1 underline" @click="q.keys = ''">Xoá tìm kiếm</button>
              </div>

              <div v-else class="mt-4 space-y-4">
                <div v-for="[prov, rows] in keysByProvider" :key="prov">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="h-2 w-2 rounded-full" :class="rows.some(k => k.enabled) ? 'bg-emerald-400' : 'bg-amber-400'"></span>
                    <h3 class="text-sm font-semibold text-cream-50">{{ providerName(prov) }}</h3>
                    <span v-if="providerOf(prov) && providerOf(prov).custom" :class="[BADGE, BADGE_TONE.custom]">Custom</span>
                    <span :class="[BADGE, BADGE_TONE.neutral]">×{{ rows.length }}</span>
                    <button class="tool-btn ml-auto" @click="openKeyModal(null, prov)">Thêm key cho nhóm này</button>
                  </div>
                  <ul class="mt-2 space-y-1.5">
                    <li v-for="k in rows" :key="k.id" class="rounded-lg border border-ink-700 bg-ink-900/40 p-3">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-cream-50">{{ k.label }}</span>
                        <span v-if="k.kind" :class="[BADGE, BADGE_TONE.neutral]">{{ k.kind }}</span>
                        <span :class="[BADGE, BADGE_TONE.neutral]">Ưu tiên {{ k.priority }}</span>
                        <span :class="[BADGE, k.enabled ? BADGE_TONE.ok : BADGE_TONE.warn]">{{ k.enabled ? 'Đang bật' : 'Đang tắt' }}</span>
                        <span v-if="k.created_at" class="text-[10px] text-cream-300">tạo {{ k.created_at }}</span>
                        <span class="ml-auto flex flex-wrap items-center gap-1.5">
                          <button class="tool-btn" :disabled="testState.running === k.id" @click="testKey(k)">
                            <StudioIcon name="link" size="h-3.5 w-3.5" /> {{ testState.running === k.id ? 'Đang thử…' : 'Kiểm tra' }}
                          </button>
                          <button class="tool-btn" @click="toggleKey(k)">
                            <StudioIcon :name="k.enabled ? 'eyeOff' : 'eye'" size="h-3.5 w-3.5" /> {{ k.enabled ? 'Tắt' : 'Bật' }}
                          </button>
                          <button class="tool-btn" @click="openKeyModal(k)"><StudioIcon name="pencil" size="h-3.5 w-3.5" /> Sửa</button>
                          <button class="tool-btn !text-danger hover:!bg-red-500/15" @click="askDeleteKey(k)"><StudioIcon name="trash" size="h-3.5 w-3.5" /> Xoá</button>
                        </span>
                      </div>
                      <p v-if="k.note" class="mt-1 text-[11px] text-cream-300">{{ k.note }}</p>
                      <p v-if="testState.id === k.id" :class="testState.ok ? 'text-ok' : 'text-danger'" class="mt-1.5 flex items-center gap-1.5 text-[11px]">
                        <StudioIcon :name="testState.ok ? 'check' : 'alertTriangle'" size="h-3.5 w-3.5" /> {{ testState.text }}
                      </p>
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </section>

          <!-- ───── CUSTOM PROVIDERS ───── -->
          <section v-show="section === 'providers'" class="space-y-5">
            <div class="card p-5">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="globe" size="h-4 w-4" class="text-brand-300" /> Custom Providers
                    <span :class="[BADGE, BADGE_TONE.neutral]">{{ customProviders.length }}</span>
                  </h2>
                  <p class="mt-1 max-w-2xl text-xs text-cream-300">
                    Route tự khai báo <b class="text-cream-100">protocol + base URL + cách xác thực</b>. Provider tích hợp (Qwen, Gemini, Fal…) không sửa được — chỉ route bạn thêm ở đây.
                  </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                  <select v-model="tplPick" aria-label="Chọn mẫu khai báo provider" class="input !w-auto !py-2 text-xs">
                    <option value="">📋 Mẫu khai báo…</option>
                    <option v-for="(tpl, key) in providerTemplates" :key="key" :value="key">{{ tpl.label }}</option>
                  </select>
                  <button class="tool-btn" :disabled="!tplPick" :class="!tplPick ? 'opacity-50' : ''" @click="applyProviderTemplate(tplPick)">
                    <StudioIcon name="zap" size="h-3.5 w-3.5" /> Dùng mẫu
                  </button>
                  <button class="btn-brand btn-sm" @click="openProvModal()"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm provider</button>
                </div>
              </div>

              <div class="mt-4 flex flex-wrap items-center gap-2">
                <div class="relative min-w-[14rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-300" />
                  <input v-model="q.providers" type="search" aria-label="Tìm custom provider" class="input !py-2 !pl-9 text-xs" placeholder="Tìm theo tên, slug, base URL…">
                </div>
                <span class="text-[11px] text-cream-300">{{ countText(filteredCustomProviders.length, customProviders.length, 'route') }}</span>
              </div>

              <div v-if="!customProviders.length" class="mt-4 flex flex-col items-center gap-2 rounded-lg border border-dashed border-ink-600 p-8 text-center">
                <StudioIcon name="globe" size="h-6 w-6" class="text-cream-300" />
                <p class="text-sm font-semibold text-cream-100">Chưa có custom provider</p>
                <p class="max-w-md text-[11px] text-cream-300">Khai báo route đầu tiên — mọi endpoint OpenAI-compatible, DashScope hoặc Gemini đều dùng được.</p>
                <button class="btn-brand btn-sm mt-1" @click="openProvModal()">Thêm provider</button>
              </div>

              <div v-else-if="!filteredCustomProviders.length" class="mt-4 rounded-lg border border-dashed border-ink-600 p-6 text-center text-xs text-cream-300">
                Không có provider nào khớp « {{ q.providers }} ».
                <button class="ml-1 underline" @click="q.providers = ''">Xoá tìm kiếm</button>
              </div>

              <ul v-else class="mt-4 space-y-2">
                <li v-for="p in filteredCustomProviders" :key="p.slug" class="rounded-lg border border-ink-700 bg-ink-900/40 p-3.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="h-2 w-2 rounded-full" :class="p.configured ? 'bg-emerald-400' : 'bg-amber-400'"></span>
                    <span class="text-sm font-semibold text-cream-50">{{ p.name }}</span>
                    <code class="rounded bg-ink-800 px-1.5 py-0.5 text-[10px] text-cream-200">{{ p.slug }}</code>
                    <span :class="[BADGE, BADGE_TONE.custom]">{{ p.protocol }}</span>
                    <span :class="[BADGE, p.enabled ? BADGE_TONE.ok : BADGE_TONE.warn]">{{ p.enabled ? 'Đang bật' : 'Đang tắt' }}</span>
                    <span :class="[BADGE, p.configured ? BADGE_TONE.ok : BADGE_TONE.warn]">{{ p.configured ? p.key_count + ' key' : 'chưa có key' }}</span>
                    <span :class="[BADGE, BADGE_TONE.neutral]" :title="'Ưu tiên trong nhóm Custom — lớn hơn được thử trước'">Ưu tiên {{ p.priority ?? 5 }}</span>
                    <span class="text-[10px] text-cream-300" :title="'Thứ tự thực tế khi chọn model trong nhóm Custom'">#{{ customOrder(p) }}</span>
                    <span class="ml-auto flex flex-wrap items-center gap-1.5">
                      <button class="tool-btn" @click="gotoAddKey(p.slug)"><StudioIcon name="key" size="h-3.5 w-3.5" /> Thêm key</button>
                      <button class="tool-btn" @click="openProvModal(p)"><StudioIcon name="pencil" size="h-3.5 w-3.5" /> Sửa</button>
                      <button class="tool-btn !text-danger hover:!bg-red-500/15" @click="askDeleteProv(p)"><StudioIcon name="trash" size="h-3.5 w-3.5" /> Xoá</button>
                    </span>
                  </div>
                  <p class="mt-1.5 truncate text-[11px] text-cream-300">
                    <span class="text-cream-300">Base URL</span> <code class="text-cream-200">{{ p.base_url }}</code>
                    <span class="mx-1.5 text-cream-400">·</span>
                    <span class="text-cream-300">Key ref</span> <code class="text-cream-200">{{ p.api_key_ref }}</code>
                    <span class="mx-1.5 text-cream-400">·</span>
                    <span class="text-cream-300">Auth</span> {{ p.auth_style }}
                  </p>
                  <p v-if="p.note" class="mt-1 text-[11px] text-cream-300">{{ p.note }}</p>
                </li>
              </ul>

              <p class="mt-3 flex items-start gap-2 rounded-lg border border-ink-700 bg-ink-900/60 p-2.5 text-[11px] text-cream-300">
                <StudioIcon name="info" size="h-3.5 w-3.5 shrink-0 mt-px text-info" />
                <span><b class="text-cream-100">Luồng 2 bước:</b> (1) tạo provider — ô <b>Key ref</b> chỉ là <b>TÊN NHÓM KEY</b> (vd ckey), KHÔNG dán khoá API vào; (2) thêm khoá thật ở mục <b>API Keys</b> với provider = slug. Provider ID cố định sau khi tạo vì mọi model tham chiếu theo nó. Nhiều route cùng nhóm Custom thì <b>Ưu tiên</b> quyết định route nào thử trước.</span>
              </p>
            </div>
          </section>

          <!-- ───── MODEL REGISTRY ───── -->
          <section v-show="section === 'models'" class="space-y-5">
            <div class="card p-5">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                    <StudioIcon name="server" size="h-4 w-4" class="text-brand-300" /> Model Registry
                    <span :class="[BADGE, BADGE_TONE.neutral]">{{ models.length }}</span>
                  </h2>
                  <p class="mt-1 max-w-2xl text-xs text-cream-300">
                    Vai trò quyết định model thuộc nhóm công việc nào. Danh sách xếp theo đúng thứ tự runtime: default của nhóm → luồng ưu tiên provider → Ưu tiên model giảm dần.
                  </p>
                </div>
                <button class="btn-brand btn-sm" @click="openModelModal()"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm model</button>
              </div>

              <div class="mt-4 flex flex-wrap items-center gap-2">
                <div class="relative min-w-[13rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-cream-300" />
                  <input v-model="q.models" type="search" aria-label="Tìm model" class="input !py-2 !pl-9 text-xs" placeholder="Tìm theo tên, model ID, provider…">
                </div>
                <select v-model="roleFilter" aria-label="Lọc theo vai trò" class="input !w-auto !py-2 text-xs">
                  <option value="">Mọi vai trò</option>
                  <option v-for="r in ROLE_ORDER" :key="r" :value="r">{{ roleLabel(r) }}</option>
                </select>
                <span class="text-[11px] text-cream-300">{{ countText(filteredModels.length, models.length, 'model') }}</span>
              </div>

              <div v-if="!models.length" class="mt-4 flex flex-col items-center gap-2 rounded-lg border border-dashed border-ink-600 p-8 text-center">
                <StudioIcon name="server" size="h-6 w-6" class="text-cream-300" />
                <p class="text-sm font-semibold text-cream-100">Registry đang trống</p>
                <p class="max-w-md text-[11px] text-cream-300">Thêm model thủ công, hoặc bấm "Đồng bộ catalog" ở mục Luồng ưu tiên để nhập bộ model QwenCloud tích hợp.</p>
                <div class="mt-1 flex gap-2">
                  <button class="btn-brand btn-sm" @click="openModelModal()">Thêm model</button>
                  <button class="btn-outline btn-sm" :disabled="syncSaving" @click="syncModels()">Đồng bộ catalog</button>
                </div>
              </div>

              <div v-else-if="!filteredModels.length" class="mt-4 rounded-lg border border-dashed border-ink-600 p-6 text-center text-xs text-cream-300">
                Không có model nào khớp bộ lọc hiện tại.
                <button class="ml-1 underline" @click="q.models = ''; roleFilter = ''">Xoá bộ lọc</button>
              </div>

              <div v-else class="mt-4 space-y-5">
                <div v-for="blk in visibleModels" :key="blk.role">
                  <div class="flex flex-wrap items-center gap-2">
                    <StudioIcon :name="roleIcon(blk.role)" size="h-3.5 w-3.5" class="text-brand-300/80" />
                    <h3 class="text-sm font-semibold text-cream-50">{{ roleLabel(blk.role) }}</h3>
                    <span :class="[BADGE, BADGE_TONE.neutral]">×{{ blk.rows.length }}</span>
                    <span v-if="isLegacyRole(blk.role)" :class="[BADGE, BADGE_TONE.warn]">vai trò cũ</span>
                    <button class="tool-btn ml-auto" @click="openModelModal(null, blk.role)">Thêm vào nhóm này</button>
                  </div>
                  <ul class="mt-2 space-y-1.5">
                    <li v-for="m in blk.rows" :key="m.id" class="rounded-lg border border-ink-700 bg-ink-900/40 p-3">
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-cream-50">{{ m.name }}</span>
                        <code class="rounded bg-ink-800 px-1.5 py-0.5 text-[10px] text-cream-200">{{ m.model_id }}</code>
                        <span :class="[BADGE, BADGE_TONE.neutral]" :title="'Provider: ' + providerName(m.provider)">{{ providerOf(m.provider) && providerOf(m.provider).custom ? 'custom' : m.provider }}</span>
                        <span :class="[BADGE, BADGE_TONE.info]" :title="'Nhóm #' + flowRank(m.provider) + ' trong luồng ưu tiên'">#{{ flowRank(m.provider) }} {{ flowMeta(family(m.provider)).short }}</span>
                        <span :class="[BADGE, BADGE_TONE.neutral]">Ưu tiên {{ m.priority }}</span>
                        <span :class="[BADGE, m.enabled ? BADGE_TONE.ok : BADGE_TONE.warn]">{{ m.enabled ? 'Đang bật' : 'Đang tắt' }}</span>
                        <span v-if="m.api_key_ref && m.api_key_ref !== m.provider" class="text-[10px] text-cream-300">key ref: {{ m.api_key_ref }}</span>
                        <span class="ml-auto flex flex-wrap items-center gap-1.5">
                          <button class="tool-btn" @click="openModelModal(m)"><StudioIcon name="pencil" size="h-3.5 w-3.5" /> Sửa</button>
                          <button class="tool-btn !text-danger hover:!bg-red-500/15" @click="askDeleteModel(m)"><StudioIcon name="trash" size="h-3.5 w-3.5" /> Xoá</button>
                        </span>
                      </div>
                      <p v-if="m.note" class="mt-1 text-[11px] text-cream-300">{{ m.note }}</p>
                    </li>
                  </ul>
                </div>
                <button v-if="hiddenLegacyCount" class="tool-btn w-full justify-center" @click="showLegacyModels = true">
                  <StudioIcon name="chevronDown" size="h-3.5 w-3.5" /> Hiện thêm {{ hiddenLegacyCount }} model thuộc vai trò cũ
                </button>
              </div>
            </div>
          </section>

          <!-- ───── NHÓM CÔNG VIỆC ───── -->
          <section v-show="section === 'tasks'" class="space-y-5">
            <div class="card p-5">
              <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                <StudioIcon name="target" size="h-4 w-4" class="text-brand-300" /> Model theo nhóm công việc
              </h2>
              <p class="mt-1 max-w-2xl text-xs text-cream-300">
                Mỗi card / tính năng trong Studio một nhóm. Chọn model mặc định riêng — thay đổi được lưu ngay.
                Để <b class="text-cream-100">Tự động</b> thì nhóm dùng model đầu tiên theo ưu tiên.
              </p>

              <ul class="mt-4 space-y-2">
                <li v-for="g in taskGroupKeys" :key="g" class="rounded-lg border border-ink-700 bg-ink-900/40 p-3.5">
                  <div class="flex flex-wrap items-center gap-2">
                    <StudioIcon :name="roleIcon(g)" size="h-4 w-4" class="text-brand-300/80" />
                    <div class="min-w-0 flex-1">
                      <p class="text-sm font-semibold text-cream-50">{{ taskGroups[g].label }}</p>
                      <p class="mt-0.5 text-[11px] text-cream-300">
                        {{ taskGroups[g].models.length }} model trong nhóm · đang dùng:
                        <b :class="taskGroups[g].default ? 'text-ok' : 'text-warn'">{{ taskDefaultLabel(g) }}</b>
                      </p>
                    </div>
                    <span :class="[BADGE, taskGroups[g].assigned ? BADGE_TONE.brand : BADGE_TONE.neutral]">
                      {{ taskGroups[g].assigned ? 'gán thủ công' : 'tự động' }}
                    </span>
                  </div>
                  <div class="mt-2.5 flex flex-wrap items-center gap-2">
                    <select v-model="taskDraft[g]" :aria-label="'Model mặc định cho ' + taskGroups[g].label"
                            class="input max-w-md !py-1.5 text-xs" @change="saveTaskDefault(g)">
                      <option value="">Tự động (ưu tiên model cao nhất)</option>
                      <option v-for="o in taskGroupModelOptions(g)" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <button v-if="taskGroups[g].assigned" class="tool-btn" :disabled="taskSaving === g" @click="clearTaskDefault(g)">
                      <StudioIcon name="undo" size="h-3.5 w-3.5" /> Về tự động
                    </button>
                    <span v-if="taskSaving === g" class="flex items-center gap-1.5 text-[11px] text-cream-300">
                      <StudioIcon name="refresh" size="h-3.5 w-3.5" class="animate-spin" /> đang lưu…
                    </span>
                  </div>
                  <div v-if="taskGroups[g].models.length" class="mt-2 flex flex-wrap items-center gap-1">
                    <span v-for="m in taskGroups[g].models.slice(0, 6)" :key="m.provider + m.model"
                          :class="[BADGE, m.provider + ':' + m.model === taskGroups[g].default ? BADGE_TONE.ok : BADGE_TONE.neutral]"
                          :title="m.label + ' · nhóm #' + flowRank(m.provider)">
                      <span class="opacity-70">#{{ flowRank(m.provider) }}</span> {{ m.label }}
                    </span>
                    <span v-if="taskGroups[g].models.length > 6" class="text-[10px] text-cream-300">+{{ taskGroups[g].models.length - 6 }} nữa</span>
                    <button class="tool-btn" @click="roleFilter = g; goTo('models')">Quản lý model <StudioIcon name="arrowRight" size="h-3.5 w-3.5" /></button>
                  </div>
                  <p v-else class="mt-2 rounded-lg border border-dashed border-ink-600 p-2.5 text-[11px] text-cream-300">
                    Chưa có model nào trong nhóm — đang kế thừa cấu hình cũ. Thêm model với vai trò <b class="text-cream-200">{{ roleLabel(g) }}</b> ở mục Model Registry.
                  </p>
                </li>
              </ul>
            </div>

            <details class="card p-4">
              <summary class="cursor-pointer text-sm font-semibold text-cream-100">Card nào dùng nhóm nào?</summary>
              <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-xs">
                  <thead>
                    <tr class="border-b border-ink-700 text-cream-300">
                      <th class="py-2 pr-3 font-semibold">Card / tính năng</th>
                      <th class="py-2 pr-3 font-semibold">Nhóm</th>
                      <th class="py-2 font-semibold">Model hiện hành</th>
                    </tr>
                  </thead>
                  <tbody class="text-cream-200">
                    <tr v-for="row in [
                          { card: 'Tạo Ảnh 2D (Concept)', role: 'image' },
                          { card: 'Ảnh mới từ ảnh mẫu · Thử đồ', role: 'image' },
                          { card: 'Sửa ảnh (Inpaint) · Xoá vùng', role: 'edit' },
                          { card: 'Kịch bản quay (Video)', role: 'video' },
                          { card: 'Thay Đổi Người Mẫu', role: 'swap' },
                          { card: 'Đọc ảnh (khuôn mặt / dáng)', role: 'vision' },
                          { card: 'Trợ lý thiết kế · Giám đốc sáng tạo', role: 'prompt' },
                          { card: 'Dịch prompt', role: 'translate' },
                        ]" :key="row.card" class="border-b border-ink-700/60 last:border-0">
                      <td class="py-2 pr-3">{{ row.card }}</td>
                      <td class="py-2 pr-3"><code class="rounded bg-ink-800 px-1.5 py-0.5 text-[10px]">{{ row.role }}</code></td>
                      <td class="py-2">{{ taskGroups[row.role] && taskGroups[row.role].default ? taskGroups[row.role].default : '—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </details>
          </section>

          <!-- ───── CẤU HÌNH CHUNG ───── -->
          <section v-show="section === 'general'" class="space-y-5">
            <div v-if="cfgForm" class="card p-5">
              <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-50">
                <StudioIcon name="gear" size="h-4 w-4" class="text-brand-300" /> Cấu hình chung
              </h2>
              <p class="mt-1 max-w-2xl text-xs text-cream-300">
                Mặc định cho pipeline gọi model. Thứ tự thực tế: model mặc định của nhóm (Nhóm công việc) → Luồng ưu tiên provider → Ưu tiên model giảm dần.
              </p>

              <div class="mt-4 space-y-5">
                <fieldset>
                  <legend class="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-cream-300">
                    <StudioIcon name="image" size="h-3.5 w-3.5" /> Tạo ảnh
                  </legend>
                  <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                      <label class="label" for="cfg-image-provider">Provider sinh ảnh</label>
                      <input id="cfg-image-provider" v-model="cfgForm.image_provider" class="input !py-2" placeholder="qwen / flux / wan / gemini / slug custom">
                    </div>
                    <div>
                      <label class="label" for="cfg-qwen-model">Model ảnh (Qwen)</label>
                      <input id="cfg-qwen-model" v-model="cfgForm.qwen_model" class="input !py-2" placeholder="qwen-image-3.0-pro">
                    </div>
                    <div>
                      <label class="label" for="cfg-image-model">Model ảnh (chung / Flux)</label>
                      <input id="cfg-image-model" v-model="cfgForm.image_model" class="input !py-2">
                    </div>
                  </div>
                </fieldset>

                <fieldset>
                  <legend class="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-cream-300">
                    <StudioIcon name="film" size="h-3.5 w-3.5" /> Video &amp; suy luận
                  </legend>
                  <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                      <label class="label" for="cfg-video-model">Model video</label>
                      <input id="cfg-video-model" v-model="cfgForm.video_model" class="input !py-2">
                    </div>
                    <div>
                      <label class="label" for="cfg-vision">Provider đọc ảnh (vision)</label>
                      <input id="cfg-vision" v-model="cfgForm.vision_provider" class="input !py-2" placeholder="gemini / qwen">
                    </div>
                    <div>
                      <label class="label" for="cfg-prompt">Provider suy luận prompt</label>
                      <input id="cfg-prompt" v-model="cfgForm.prompt_provider" class="input !py-2" placeholder="gemini / qwen / deepseek">
                    </div>
                  </div>
                </fieldset>

                <fieldset>
                  <legend class="mb-2 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-cream-300">
                    <StudioIcon name="sliders" size="h-3.5 w-3.5" /> Vận hành &amp; credit
                  </legend>
                  <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                      <label class="label" for="cfg-processing">Cách xử lý</label>
                      <select id="cfg-processing" v-model="cfgForm.processing" class="input !py-2">
                        <option value="sync">sync — trả kết quả ngay</option>
                        <option value="queue">queue — chạy nền</option>
                      </select>
                    </div>
                    <div>
                      <label class="label" for="cfg-img-credits">Credit mỗi ảnh</label>
                      <input id="cfg-img-credits" type="number" min="0" max="1000" v-model.number="cfgForm.image_credits" class="input !py-2">
                    </div>
                    <div>
                      <label class="label" for="cfg-vid-credits">Credit mỗi video</label>
                      <input id="cfg-vid-credits" type="number" min="0" max="1000" v-model.number="cfgForm.video_credits" class="input !py-2">
                    </div>
                  </div>
                </fieldset>
              </div>

              <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-ink-700 pt-4">
                <button class="btn-brand btn-sm" :disabled="cfgSaving || !cfgDirty" @click="saveConfig()">
                  <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ cfgSaving ? 'Đang lưu…' : 'Lưu cấu hình' }}
                </button>
                <button class="tool-btn" :disabled="!cfgDirty || cfgSaving" @click="syncCfg()">
                  <StudioIcon name="undo" size="h-3.5 w-3.5" /> Hoàn tác thay đổi
                </button>
                <span v-if="cfgDirty" class="flex items-center gap-1.5 text-[11px] text-warn">
                  <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" /> Có thay đổi chưa lưu
                </span>
                <span v-else class="flex items-center gap-1.5 text-[11px] text-cream-300">
                  <StudioIcon name="check" size="h-3.5 w-3.5" /> Đã lưu
                </span>
              </div>
            </div>

            <div class="card p-4">
              <h3 class="flex items-center gap-2 text-sm font-semibold text-cream-50">
                <StudioIcon name="coins" size="h-4 w-4" class="text-brand-300" /> Sử dụng (tài khoản của bạn)
              </h3>
              <div class="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                <div v-for="u in usageCards" :key="u.key" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
                  <p class="text-[10px] font-semibold uppercase tracking-wide text-cream-300">{{ u.label }}</p>
                  <p class="mt-1 text-lg font-semibold text-cream-50">{{ u.value }}</p>
                </div>
              </div>
            </div>
          </section>
        </main>
      </div>
    </div>

    <!-- ═════════ Hộp thoại: API key ═════════ -->
    <BaseModal :model-value="keyModal.open" :title="keyModal.mode === 'create' ? 'Thêm API key' : 'Sửa API key'" @update:model-value="closeKeyModal">
      <form class="space-y-3" @submit.prevent="submitKey">
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="k-provider">Provider</label>
            <select id="k-provider" v-model="keyModal.form.provider" class="input !py-2" :class="keyModal.errors.provider ? '!border-red-500/70' : ''">
              <option value="">— Chọn provider —</option>
              <option v-for="p in sortedProviders" :key="p.slug" :value="p.slug">{{ p.name }} ({{ p.slug }})</option>
            </select>
            <p v-if="keyModal.errors.provider" class="mt-1 text-[11px] text-danger">{{ keyModal.errors.provider }}</p>
          </div>
          <div>
            <label class="label" for="k-label">Nhãn</label>
            <input id="k-label" v-model="keyModal.form.label" class="input !py-2" :class="keyModal.errors.label ? '!border-red-500/70' : ''" placeholder="VD: Qwen Token-Plan">
            <p v-if="keyModal.errors.label" class="mt-1 text-[11px] text-danger">{{ keyModal.errors.label }}</p>
          </div>
        </div>
        <div>
          <label class="label" for="k-value">Khoá API {{ keyModal.mode === 'edit' ? '(để trống = giữ nguyên)' : '' }}</label>
          <input id="k-value" v-model="keyModal.form.value" type="password" autocomplete="new-password" class="input !py-2 font-mono text-xs" :class="keyModal.errors.value ? '!border-red-500/70' : ''" placeholder="sk-…">
          <p v-if="keyModal.errors.value" class="mt-1 text-[11px] text-danger">{{ keyModal.errors.value }}</p>
          <p class="mt-1 text-[11px] text-cream-300">
            {{ providerOf(keyModal.form.provider) && providerOf(keyModal.form.provider).hint ? providerOf(keyModal.form.provider).hint : 'Key được mã hoá trước khi lưu và không bao giờ hiển thị lại.' }}
          </p>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
          <div>
            <label class="label" for="k-kind">Loại (kind)</label>
            <input id="k-kind" v-model="keyModal.form.kind" class="input !py-2" placeholder="plan / paygo">
          </div>
          <div>
            <label class="label" for="k-priority">Ưu tiên</label>
            <input id="k-priority" type="number" min="0" max="100" v-model.number="keyModal.form.priority" class="input !py-2" :class="keyModal.errors.priority ? '!border-red-500/70' : ''">
            <p v-if="keyModal.errors.priority" class="mt-1 text-[11px] text-danger">{{ keyModal.errors.priority }}</p>
          </div>
          <div class="flex items-end pb-2">
            <label class="flex items-center gap-2 text-xs text-cream-200">
              <input type="checkbox" v-model="keyModal.form.enabled" class="h-4 w-4 accent-brand-600"> Bật key này
            </label>
          </div>
        </div>
        <div>
          <label class="label" for="k-note">Ghi chú</label>
          <input id="k-note" v-model="keyModal.form.note" class="input !py-2" placeholder="VD: hạn mức còn 40% · dùng cho ảnh chính">
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
          <button type="button" class="tool-btn" @click="closeKeyModal">Huỷ</button>
          <button type="submit" class="btn-brand btn-sm" :disabled="keyModal.saving">
            <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ keyModal.saving ? 'Đang lưu…' : (keyModal.mode === 'create' ? 'Thêm key' : 'Lưu thay đổi') }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- ═════════ Hộp thoại: custom provider ═════════ -->
    <BaseModal :model-value="provModal.open" :title="provModal.mode === 'create' ? 'Thêm custom provider' : 'Sửa custom provider'" @update:model-value="closeProvModal">
      <form class="space-y-3" @submit.prevent="submitProv">
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="p-slug">Provider ID (slug)</label>
            <input id="p-slug" v-model="provModal.form.slug" :disabled="provModal.mode === 'edit'" class="input !py-2 font-mono text-xs disabled:opacity-60" :class="provModal.errors.slug ? '!border-red-500/70' : ''" placeholder="VD: openrouter">
            <p v-if="provModal.errors.slug" class="mt-1 text-[11px] text-danger">{{ provModal.errors.slug }}</p>
            <p v-else-if="provModal.mode === 'edit'" class="mt-1 text-[11px] text-cream-300">Provider ID cố định sau khi tạo (model và generation tham chiếu theo slug).</p>
          </div>
          <div>
            <label class="label" for="p-name">Tên hiển thị</label>
            <input id="p-name" v-model="provModal.form.name" class="input !py-2" :class="provModal.errors.name ? '!border-red-500/70' : ''" placeholder="VD: OpenRouter">
            <p v-if="provModal.errors.name" class="mt-1 text-[11px] text-danger">{{ provModal.errors.name }}</p>
          </div>
        </div>
        <div>
          <label class="label" for="p-url">Base URL</label>
          <input id="p-url" v-model="provModal.form.base_url" class="input !py-2 font-mono text-xs" :class="provModal.errors.base_url ? '!border-red-500/70' : ''" placeholder="https://openrouter.ai/api/v1">
          <p v-if="provModal.errors.base_url" class="mt-1 text-[11px] text-danger">{{ provModal.errors.base_url }}</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="p-protocol">Protocol</label>
            <select id="p-protocol" v-model="provModal.form.protocol" class="input !py-2">
              <option value="openai">openai</option>
              <option value="dashscope">dashscope</option>
              <option value="gemini">gemini</option>
            </select>
          </div>
          <div>
            <label class="label" for="p-auth">Cách xác thực</label>
            <select id="p-auth" v-model="provModal.form.auth_style" class="input !py-2">
              <option value="bearer">Bearer (Authorization: Bearer …)</option>
              <option value="x-goog-api-key">x-goog-api-key</option>
            </select>
          </div>
        </div>
        <p class="flex items-start gap-2 rounded-lg border border-ink-700 bg-ink-900/60 p-2.5 text-[11px] text-cream-300">
          <StudioIcon name="info" size="h-3.5 w-3.5 shrink-0 mt-px text-info" />
          <span>{{ PROTOCOL_HINT[provModal.form.protocol] }}</span>
        </p>
        <div>
          <label class="label" for="p-keyref">Key ref — TÊN NHÓM KEY <span class="font-normal normal-case text-cream-300">(bỏ trống = dùng Provider ID)</span></label>
          <input id="p-keyref" v-model="provModal.form.api_key_ref" class="input !py-2 font-mono text-xs" :class="provModal.errors.api_key_ref ? '!border-red-500/70' : ''" placeholder="vd: ckey — KHÔNG dán khoá API vào đây">
          <p v-if="provModal.errors.api_key_ref" class="mt-1 text-[11px] text-danger">{{ provModal.errors.api_key_ref }}</p>
          <p v-else class="mt-1 text-[11px] text-cream-300">Khoá API thật thêm ở mục API Keys với provider = slug này.</p>
        </div>
        <div>
          <label class="label" for="p-prio">Ưu tiên trong nhóm Custom <span class="font-normal normal-case text-cream-300">(lớn hơn = thử trước)</span></label>
          <input id="p-prio" type="number" min="0" max="100" v-model.number="provModal.form.priority" class="input !py-2 sm:max-w-[10rem]">
          <p class="mt-1 text-[11px] text-cream-300">Tab 🔥 quyết định THỨ TỰ NHÓM; số này phân định các route nằm CÙNG nhóm — ví dụ nhiều custom provider: route điểm cao được gọi trước, lỗi thì mới rơi xuống route dưới.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <label class="flex items-center gap-2 text-xs text-cream-200">
            <input type="checkbox" v-model="provModal.form.enabled" class="h-4 w-4 accent-brand-600"> Bật provider
          </label>
          <div class="min-w-[12rem] flex-1">
            <input v-model="provModal.form.note" class="input !py-2" placeholder="Ghi chú (tuỳ chọn)">
          </div>
        </div>
        <div class="flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
          <button type="button" class="tool-btn" @click="closeProvModal">Huỷ</button>
          <button type="submit" class="btn-brand btn-sm" :disabled="provModal.saving">
            <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ provModal.saving ? 'Đang lưu…' : (provModal.mode === 'create' ? 'Thêm provider' : 'Lưu thay đổi') }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- ═════════ Hộp thoại: model ═════════ -->
    <BaseModal :model-value="modelModal.open" :title="modelModal.mode === 'create' ? 'Thêm model' : 'Sửa model'" @update:model-value="closeModelModal">
      <form class="space-y-3" @submit.prevent="submitModel">
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="m-group">Vai trò (nhóm công việc)</label>
            <select id="m-group" v-model="modelModal.form.group" class="input !py-2">
              <option v-for="r in ROLE_ORDER" :key="r" :value="r">{{ roleLabel(r) }} ({{ r }})</option>
            </select>
          </div>
          <div>
            <label class="label" for="m-name">Tên model</label>
            <input id="m-name" v-model="modelModal.form.name" class="input !py-2" :class="modelModal.errors.name ? '!border-red-500/70' : ''" placeholder="VD: Qwen Image 3.0 Pro">
            <p v-if="modelModal.errors.name" class="mt-1 text-[11px] text-danger">{{ modelModal.errors.name }}</p>
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="m-provider">Provider</label>
            <select id="m-provider" v-model="modelModal.form.provider" class="input !py-2" :class="modelModal.errors.provider ? '!border-red-500/70' : ''" @change="onModelProviderChange">
              <option value="">— Chọn provider —</option>
              <option v-for="p in sortedProviders" :key="p.slug" :value="p.slug">{{ p.name }} ({{ p.slug }})</option>
            </select>
            <p v-if="modelModal.errors.provider" class="mt-1 text-[11px] text-danger">{{ modelModal.errors.provider }}</p>
          </div>
          <div>
            <label class="label" for="m-model-id">Model ID</label>
            <input id="m-model-id" v-model="modelModal.form.model_id" class="input !py-2 font-mono text-xs" :class="modelModal.errors.model_id ? '!border-red-500/70' : ''" placeholder="qwen-image-3.0-pro">
            <p v-if="modelModal.errors.model_id" class="mt-1 text-[11px] text-danger">{{ modelModal.errors.model_id }}</p>
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="m-keyref">Key ref <span class="font-normal normal-case text-cream-300">(tên nhóm key)</span></label>
            <input id="m-keyref" v-model="modelModal.form.api_key_ref" class="input !py-2 font-mono text-xs" :class="modelModal.errors.api_key_ref ? '!border-red-500/70' : ''" placeholder="mặc định = provider">
            <p v-if="modelModal.errors.api_key_ref" class="mt-1 text-[11px] text-danger">{{ modelModal.errors.api_key_ref }}</p>
          </div>
          <div>
            <label class="label" for="m-priority">Ưu tiên (0–100)</label>
            <input id="m-priority" type="number" min="0" max="100" v-model.number="modelModal.form.priority" class="input !py-2" :class="modelModal.errors.priority ? '!border-red-500/70' : ''">
            <p v-if="modelModal.errors.priority" class="mt-1 text-[11px] text-danger">{{ modelModal.errors.priority }}</p>
            <p v-else class="mt-1 text-[11px] text-cream-300">Chỉ so trong cùng nhóm provider — xem Luồng ưu tiên.</p>
          </div>
        </div>
        <div>
          <label class="label" for="m-note">Ghi chú</label>
          <input id="m-note" v-model="modelModal.form.note" class="input !py-2" placeholder="VD: QwenCloud 2026 · ~$0.02/MP · fallback khi hết hạn mức">
        </div>
        <label class="flex items-center gap-2 text-xs text-cream-200">
          <input type="checkbox" v-model="modelModal.form.enabled" class="h-4 w-4 accent-brand-600"> Bật model
        </label>
        <div class="flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
          <button type="button" class="tool-btn" @click="closeModelModal">Huỷ</button>
          <button type="submit" class="btn-brand btn-sm" :disabled="modelModal.saving">
            <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ modelModal.saving ? 'Đang lưu…' : (modelModal.mode === 'create' ? 'Thêm model' : 'Lưu thay đổi') }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- ═════════ Hộp thoại: xác nhận xoá ═════════ -->
    <BaseModal :model-value="confirmBox.open" :title="confirmBox.title" @update:model-value="confirmBox.open = false">
      <p class="text-xs leading-relaxed text-cream-200">{{ confirmBox.message }}</p>
      <div class="mt-4 flex items-center justify-end gap-2 border-t border-ink-700 pt-3">
        <button class="tool-btn" @click="confirmBox.open = false">Huỷ</button>
        <button class="btn-sm inline-flex items-center gap-1.5 rounded-xl bg-red-600 px-4 py-2 font-semibold text-white hover:bg-red-500 disabled:opacity-60"
                :disabled="confirmBox.busy" @click="confirmRun">
          <StudioIcon name="trash" size="h-3.5 w-3.5" /> {{ confirmBox.busy ? 'Đang xoá…' : confirmBox.label }}
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

