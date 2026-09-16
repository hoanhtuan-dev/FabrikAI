<script setup>
/**
 * Studio Settings SPA — redesign modeled on the DeepSeek Harness Models page:
 * one joined snapshot (provider directory + key registry + model registry),
 * one editor card at a time, write-only secrets, and a Custom tag that follows
 * the directory's answer alone. Built-ins are never editable, only custom rows.
 */
import { ref, computed, onMounted } from 'vue';

const BASE = '/api/settings-vue';
const csrf = (() => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
})();

const tab = ref('keys');
const data = ref(null);
const loading = ref(true);
const error = ref('');
const toast = ref(null);

// ── API helpers ──────────────────────────────────────────────────────────
async function api(path, method = 'GET', body = null) {
  const opts = { method, headers: { 'X-XSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } };
  if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const r = await fetch(BASE + path, opts);
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
  return d;
}

async function load() {
  loading.value = true; error.value = '';
  try { data.value = await api('/data'); }
  catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
onMounted(load);

function flash(msg, ok = true) { toast.value = { msg, ok }; setTimeout(() => { toast.value = null; }, 2600); }
async function run(fn, okMsg) {
  try { await fn(); if (okMsg) flash(okMsg); await load(); return true; }
  catch (e) { flash(e.message, false); return false; }
}

const providers = computed(() => data.value?.providers || []);
const keys = computed(() => data.value?.api_keys || []);
const models = computed(() => data.value?.models || []);
const config = computed(() => data.value?.config || {});
// Group keys by provider for the registry list.
const keysByProvider = computed(() => {
  const m = new Map();
  for (const k of keys.value) { if (!m.has(k.provider)) m.set(k.provider, []); m.get(k.provider).push(k); }
  return m;
});
const providerName = (slug) => providers.value.find(p => p.slug === slug)?.name || slug;

// DSH-style key hygiene: trim + printable ASCII only (what a header can carry).
function validKey(v) {
  const t = (v || '').trim();
  if (!t) return 'Key không được để trống.';
  if (t.includes('=') && !t.startsWith('sk-')) return 'Key dạng "NAME=value" — chỉ dán giá trị key, không dán cả dòng env.';
  if (t.startsWith('"') && t.endsWith('"')) return 'Key đang bọc trong dấu ngoặc kép — bỏ ngoặc rồi dán lại.';
  return null;
}

// ── Tab: API Keys ────────────────────────────────────────────────────────
const keyForm = ref({ provider: '', label: '', value: '', kind: '', priority: 5, note: '' });
const keySaving = ref(false);
const editingKey = ref(null); // row being edited inline
const keyEdit = ref(null);    // draft for the edit form
const testingKey = ref(null);
const testResult = ref(null);

async function saveKey() {
  const err = validKey(keyForm.value.value);
  if (err) return flash(err, false);
  if (!keyForm.value.provider) return flash('Chọn provider cho key.', false);
  if (!keyForm.value.label.trim()) return flash('Nhập nhãn cho key.', false);
  keySaving.value = true;
  const payload = { ...keyForm.value, priority: Number(keyForm.value.priority) || 0 };
  const ok = await run(async () => { await api('/keys', 'POST', payload); }, 'Đã thêm API key.');
  if (ok) keyForm.value = { provider: '', label: '', value: '', kind: '', priority: 5, note: '' };
  keySaving.value = false;
}

function startEditKey(k) {
  editingKey.value = k.id;
  keyEdit.value = { provider: k.provider, label: k.label, kind: k.kind || '', value: '', priority: k.priority, enabled: k.enabled, note: k.note || '' };
}
async function saveEditedKey() {
  if (keyEdit.value.value && validKey(keyEdit.value.value)) return flash(validKey(keyEdit.value.value), false);
  await run(async () => {
    await api('/keys/' + editingKey.value, 'PUT', { ...keyEdit.value, priority: Number(keyEdit.value.priority) || 0 });
  }, 'Đã cập nhật API key.');
  editingKey.value = null; keyEdit.value = null;
}
async function removeKey(k) {
  if (!confirm('Xóa key «' + k.label + '»?')) return;
  await run(async () => { await api('/keys/' + k.id, 'DELETE'); }, 'Đã xóa API key.');
}
async function testKeyRow(k) {
  testingKey.value = k.id; testResult.value = null;
  try {
    const d = await api('/keys/' + k.id + '/test', 'POST');
    testResult.value = { id: k.id, ok: d.ok, text: d.key_prefix ? d.key_prefix + ' · ' + d.note : d.note };
  } catch (e) { testResult.value = { id: k.id, ok: false, text: e.message }; }
  testingKey.value = null;
}

// ── Tab: Custom Providers ────────────────────────────────────────────────
const customProviders = computed(() => providers.value.filter(p => p.custom));
const provForm = ref({ slug: '', name: '', protocol: 'openai', base_url: '', auth_style: 'bearer', api_key_ref: '', note: '' });
const provSaving = ref(false);
const editingProv = ref(null);
const provEdit = ref(null);

const protocolHint = computed(() => ({
  openai: '[OI]-compatible — POST {base}/chat/completions · Bearer (OpenRouter, Together, Groq, vLLM…)',
  dashscope: 'DashScope-compatible — POST {base}/api/v1/…/generation (sinh ảnh)',
  gemini: 'Gemini-compatible — POST {base}/v1beta/models/{model}:generateContent',
}[provForm.value.protocol] || ''));

async function saveProvider() {
  if (!provForm.value.slug.trim()) return flash('Nhập Provider ID (slug).', false);
  if (!provForm.value.name.trim()) return flash('Nhập tên hiển thị.', false);
  if (!/^https?:\/\/[^\/]+/.test(provForm.value.base_url.trim())) return flash('Base URL phải bắt đầu bằng http(s):// và có host.', false);
  provSaving.value = true;
  const ok = await run(async () => {
    await api('/providers', 'POST', { ...provForm.value, api_key_ref: provForm.value.api_key_ref || provForm.value.slug });
  }, 'Đã thêm custom provider.');
  if (ok) provForm.value = { slug: '', name: '', protocol: 'openai', base_url: '', auth_style: 'bearer', api_key_ref: '', note: '' };
  provSaving.value = false;
}
function startEditProv(p) {
  editingProv.value = p.id;
  provEdit.value = { name: p.name, protocol: p.protocol, base_url: p.base_url, auth_style: p.auth_style, api_key_ref: p.api_key_ref, enabled: p.enabled, note: p.note || '' };
}
async function saveEditedProv() {
  await run(async () => {
    await api('/providers/' + editingProv.value, 'PUT', provEdit.value);
  }, 'Đã cập nhật custom provider.');
  editingProv.value = null; provEdit.value = null;
}
async function removeProvider(p) {
  if (!confirm('Xóa custom provider «' + p.name + '»? Các model tham chiếu slug này sẽ không còn gọi được.')) return;
  await run(async () => { await api('/providers/' + p.id, 'DELETE'); }, 'Đã xóa custom provider.');
}

// ── Tab: Models ──────────────────────────────────────────────────────────
const modelForm = ref({ group: 'image', name: '', provider: '', model_id: '', api_key_ref: '', priority: 5, note: '' });
const modelSaving = ref(false);
const editingModel = ref(null);
const modelEdit = ref(null);
const groupLabels = { image: 'Tạo ảnh 2D', edit: 'Sửa ảnh (edit)', video: 'Video', swap: 'Thử đồ (Fitting Room)', vision: 'Đọc ảnh (vision)', prompt: 'Suy luận prompt', translate: 'Dịch prompt', inference: 'Suy luận (cũ)', text: 'Ngôn ngữ (cũ)' };
const taskGroups = computed(() => data.value?.task_groups || {});
const taskGroupKeys = computed(() => Object.keys(taskGroups.value));
const modelsByGroup = computed(() => {
  const m = new Map();
  for (const g of Object.keys(groupLabels)) m.set(g, models.value.filter(x => x.group === g));
  return m;
});

async function saveModel() {
  if (!modelForm.value.name.trim()) return flash('Nhập tên model.', false);
  if (!modelForm.value.provider) return flash('Chọn provider.', false);
  if (!modelForm.value.model_id.trim()) return flash('Nhập Model ID.', false);
  modelSaving.value = true;
  const ok = await run(async () => {
    await api('/models', 'POST', { ...modelForm.value, priority: Number(modelForm.value.priority) || 0 });
  }, 'Đã thêm model.');
  if (ok) modelForm.value = { group: 'image', name: '', provider: '', model_id: '', api_key_ref: '', priority: 5, note: '' };
  modelSaving.value = false;
}
function startEditModel(m) {
  editingModel.value = m.id;
  modelEdit.value = { group: m.group, name: m.name, provider: m.provider, model_id: m.model_id, api_key_ref: m.api_key_ref || '', priority: m.priority, enabled: m.enabled, note: m.note || '' };
}
async function saveEditedModel() {
  await run(async () => {
    await api('/models/' + editingModel.value, 'PUT', { ...modelEdit.value, priority: Number(modelEdit.value.priority) || 0 });
  }, 'Đã cập nhật model.');
  editingModel.value = null; modelEdit.value = null;
}
async function removeModel(m) {
  if (!confirm('Xóa model «' + m.name + '»?')) return;
  await run(async () => { await api('/models/' + m.id, 'DELETE'); }, 'Đã xóa model.');
}

// ── Tab: General config ──────────────────────────────────────────────────
const cfgForm = ref(null);
const cfgSaving = ref(false);
function syncCfg() { cfgForm.value = { ...config.value }; }
function ensureCfg() { if (!cfgForm.value && config.value) syncCfg(); }
async function saveConfig() {
  cfgSaving.value = true;
  await run(async () => { await api('/config', 'POST', cfgForm.value); }, 'Đã lưu cấu hình.');
  cfgSaving.value = false;
}

// ── Tab: Task groups (nhóm công việc — model theo card/tính năng) ────────
const taskSaving = ref('');
const taskValue = (g) => taskGroups.value[g]?.assigned ?? '';
function taskDefaultLabel(g) {
  const d = taskGroups.value[g]?.default;
  if (!d) return '— chưa có —';
  const m = taskGroups.value[g]?.models?.find(x => x.provider + ':' + x.model === d);
  return m?.label || d;
}
function taskGroupModelOptions(g) {
  // Danh sách chọn cho một nhóm: các model ĐÃ thuộc nhóm + "auto" (legacy/priority).
  // Local đặt tên groupModels để không shadow computed models (Model Registry) —
  // bug trước đó: models.value trên mảng thường → "not iterable".
  const groupModels = taskGroups.value[g]?.models || [];
  const seen = new Set(groupModels.map(m => m.provider + ':' + m.model));
  const opts = [...groupModels.map(m => ({ value: m.provider + ':' + m.model, label: m.label + (m.default ? ' ★' : ''), registry: !!m.registry_id }))];
  // Thêm các model khác trong Registry (chưa thuộc nhóm) để gán nhanh.
  for (const m of models.value) {
    const v = m.provider + ':' + m.model_id;
    if (!seen.has(v)) { opts.push({ value: v, label: m.name + ' (chưa thuộc nhóm)', registry: true }); seen.add(v); }
  }
  return opts;
}
async function saveTaskDefault(g) {
  taskSaving.value = g;
  await run(async () => {
    await api('/task-defaults', 'POST', { group: g, value: taskValue(g) || '' });
  }, 'Đã lưu default cho nhóm «' + (taskGroups.value[g]?.label || g) + '».');
  taskSaving.value = '';
}
async function clearTaskDefault(g) {
  taskSaving.value = g;
  await run(async () => {
    await api('/task-defaults', 'POST', { group: g, value: '' });
  }, 'Đã đặt lại về tự động (theo ưu tiên model).');
  taskSaving.value = '';
}
</script>

<template>
  <div class="studio-dark w-full p-5">
    <!-- Toast -->
    <transition name="fade">
      <div v-if="toast" :class="toast.ok ? 'bg-emerald-600' : 'bg-red-600'" class="fixed bottom-5 right-5 z-50 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-lg">{{ toast.msg }}</div>
    </transition>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-xl font-semibold text-cream-50">⚙️ Cài đặt Studio</h1>
        <p class="mt-0.5 text-xs text-ink-500">API keys · Custom providers · Model registry — một trang, một nguồn dữ liệu.</p>
      </div>
      <div class="flex flex-wrap gap-1.5">
        <button @click="tab='keys'" :class="tab==='keys' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors">🔑 API Keys</button>
        <button @click="tab='providers'" :class="tab==='providers' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors">🌐 Custom Providers</button>
        <button @click="tab='models'" :class="tab==='models' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors">🤖 Models</button>
        <button @click="tab='tasks'" :class="tab==='tasks' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors">🎯 Nhóm công việc</button>
        <button @click="tab='general'; ensureCfg()" :class="tab==='general' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors">📋 Cấu hình</button>
      </div>
    </div>

    <div v-if="loading" class="card p-10 text-center text-sm text-ink-500">Đang tải…</div>
    <div v-else-if="error" class="card border-red-300 p-6 text-sm text-red-600">{{ error }} — <button class="underline" @click="load">thử lại</button></div>

    <template v-else>
      <!-- ══════════ TAB: API KEYS ══════════ -->
      <div v-show="tab==='keys'" class="space-y-5">
        <div class="card p-5">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-base font-semibold text-ink-900">🔑 API Keys Registry</h2>
            <p class="text-xs text-ink-500">{{ providers.length }} provider · {{ keys.length }} key. Key là <b>write-only</b> — chỉ lưu, không bao giờ đọc lại.</p>
          </div>
          <p class="mt-1 text-xs text-ink-500">Mỗi provider có thể có nhiều key (Qwen: Token-Plan + Pay-As-You-Go…). Model Registry chọn key theo <b>vai trò + ưu tiên</b> của model, không theo thứ tự key.</p>

          <div class="mt-4 space-y-4">
            <div v-for="[prov, rows] in keysByProvider" :key="prov">
              <div class="flex items-center gap-2">
                <span class="flex h-2.5 w-2.5 rounded-full" :class="rows.length ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                <h3 class="text-sm font-semibold text-ink-900">{{ providerName(prov) }}</h3>
                <span v-if="providers.find(p => p.slug === prov)?.custom" class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">Custom</span>
                <span class="rounded-full bg-cream-200 px-2 py-0.5 text-[10px] text-ink-700">× {{ rows.length }}</span>
              </div>
              <div class="mt-1.5 space-y-1.5">
                <div v-for="k in rows" :key="k.id" class="rounded-xl border border-cream-200 p-2.5 text-xs">
                  <!-- Row (view) -->
                  <div v-if="editingKey !== k.id" class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-ink-900">{{ k.label }}</span>
                    <span v-if="k.kind" class="rounded-full bg-cream-100 px-2 py-0.5 text-[10px] text-ink-600">{{ k.kind }}</span>
                    <span class="rounded-full bg-cream-200 px-2 py-0.5 text-[10px] text-ink-700">Ưu tiên {{ k.priority }}</span>
                    <span :class="k.enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600'" class="rounded-full px-2 py-0.5 text-[10px]">{{ k.enabled ? 'Bật' : 'Tắt' }}</span>
                    <span v-if="testResult && testResult.id === k.id" :class="testResult.ok ? 'text-emerald-600' : 'text-red-600'">{{ testResult.text }}</span>
                    <span class="ml-auto flex items-center gap-1.5">
                      <button @click="testKeyRow(k)" :disabled="testingKey === k.id" class="btn-outline btn-sm">{{ testingKey === k.id ? '…' : '🔍 Test' }}</button>
                      <button @click="startEditKey(k)" class="btn-outline btn-sm">✏️ Sửa</button>
                      <button @click="removeKey(k)" class="btn-outline btn-sm text-red-600">Xóa</button>
                    </span>
                  </div>
                  <!-- Row (edit) -->
                  <div v-else class="space-y-2">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                      <div><label class="label">Provider</label>
                        <select v-model="keyEdit.provider" class="input !py-1.5"><option v-for="p in providers" :key="p.slug" :value="p.slug">{{ p.slug }}</option></select>
                      </div>
                      <div><label class="label">Nhãn</label><input v-model="keyEdit.label" class="input !py-1.5"></div>
                      <div><label class="label">Loại</label><input v-model="keyEdit.kind" class="input !py-1.5" placeholder="plan / paygo"></div>
                      <div class="col-span-2"><label class="label">Key (để trống = giữ nguyên)</label><input type="password" autocomplete="new-password" v-model="keyEdit.value" class="input !py-1.5" placeholder="••••••••"></div>
                      <div><label class="label">Ưu tiên</label><input type="number" v-model.number="keyEdit.priority" min="0" max="100" class="input !py-1.5"></div>
                    </div>
                    <label class="flex items-center gap-1.5 text-ink-700"><input type="checkbox" v-model="keyEdit.enabled" class="h-4 w-4 accent-brand-600"> Bật</label>
                    <div class="flex gap-2">
                      <button @click="saveEditedKey" class="btn-brand btn-sm">💾 Lưu</button>
                      <button @click="editingKey = null" class="btn-ghost btn-sm">Hủy</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <p v-if="!keys.length" class="rounded-xl border border-dashed border-cream-300 p-4 text-center text-xs text-ink-500">Chưa có key nào. Thêm key đầu tiên bên dưới — service nào có key sẽ tự chuyển từ stub sang gọi API thật.</p>
          </div>
        </div>

        <!-- Add key -->
        <div class="card p-5">
          <h3 class="text-sm font-semibold text-ink-900">➕ Thêm API key</h3>
          <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div><label class="label">Provider</label>
              <select v-model="keyForm.provider" class="input !py-2">
                <option value="">— Chọn —</option>
                <option v-for="p in providers" :key="p.slug" :value="p.slug">{{ p.name }}</option>
              </select>
            </div>
            <div><label class="label">Nhãn</label><input v-model="keyForm.label" class="input !py-2" placeholder="VD: Qwen Token-Plan"></div>
            <div><label class="label">Key</label><input type="password" autocomplete="new-password" v-model="keyForm.value" class="input !py-2" placeholder="sk-..."></div>
            <div><label class="label">Loại (kind)</label><input v-model="keyForm.kind" class="input !py-2" placeholder="plan / paygo"></div>
            <div><label class="label">Ưu tiên</label><input type="number" v-model.number="keyForm.priority" min="0" max="100" class="input !py-2"></div>
            <div><label class="label">Ghi chú</label><input v-model="keyForm.note" class="input !py-2" placeholder="(tùy chọn)"></div>
          </div>
          <p class="mt-2 text-[11px] text-ink-500">💡 {{ providers.find(p => p.slug === keyForm.provider)?.hint || 'Key được mã hóa trước khi lưu. Key trong Registry ưu tiên hơn env trong .env.' }}</p>
          <button @click="saveKey" :disabled="keySaving" class="btn-brand btn-sm mt-3">{{ keySaving ? 'Đang lưu…' : '➕ Thêm key' }}</button>
        </div>
      </div>

      <!-- ══════════ TAB: CUSTOM PROVIDERS ══════════ -->
      <div v-show="tab==='providers'" class="space-y-5">
        <div class="card p-5">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-base font-semibold text-ink-900">🌐 Custom Providers</h2>
            <p class="text-xs text-ink-500">{{ customProviders.length }} custom route. Thêm mọi endpoint [OI]-compatible mà không cần sửa code.</p>
          </div>
          <p class="mt-1 text-xs text-ink-500">Một custom provider tự khai báo <b>protocol + base URL + auth</b> — giống cách DeepSeek Harness cho phép khai báo route mới qua settings. Provider tích hợp (Qwen, Gemini…) không sửa được; chỉ các route bạn thêm mới ở đây.</p>

          <div class="mt-4 space-y-2">
            <div v-for="p in customProviders" :key="p.id" class="rounded-xl border border-cream-200 p-3 text-xs">
              <div v-if="editingProv !== p.id" class="flex flex-wrap items-center gap-2">
                <span class="flex h-2.5 w-2.5 rounded-full" :class="p.configured ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                <span class="font-semibold text-ink-900">{{ p.name }}</span>
                <code class="rounded bg-ink-700 px-1.5 py-0.5 text-[10px] text-cream-100">{{ p.slug }}</code>
                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">{{ p.protocol }}</span>
                <span :class="p.enabled ? 'text-emerald-600' : 'text-gray-500'">{{ p.enabled ? 'Bật' : 'Tắt' }}</span>
                <span class="text-ink-500">{{ p.base_url }}</span>
                <span v-if="!p.configured" class="text-amber-600">⚠ Chưa có key</span>
                <span class="ml-auto flex items-center gap-1.5">
                  <button @click="startEditProv(p)" class="btn-outline btn-sm">✏️ Sửa</button>
                  <button @click="removeProvider(p)" class="btn-outline btn-sm text-red-600">Xóa</button>
                </span>
              </div>
              <div v-else class="space-y-2">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                  <div><label class="label">Tên hiển thị</label><input v-model="provEdit.name" class="input !py-1.5"></div>
                  <div><label class="label">Protocol</label>
                    <select v-model="provEdit.protocol" class="input !py-1.5"><option value="openai">openai</option><option value="dashscope">dashscope</option><option value="gemini">gemini</option></select>
                  </div>
                  <div><label class="label">Auth</label>
                    <select v-model="provEdit.auth_style" class="input !py-1.5"><option value="bearer">Bearer</option><option value="x-goog-api-key">x-goog-api-key</option></select>
                  </div>
                  <div class="col-span-2"><label class="label">Base URL</label><input v-model="provEdit.base_url" class="input !py-1.5"></div>
                  <div><label class="label">API key ref</label><input v-model="provEdit.api_key_ref" class="input !py-1.5" placeholder="slug của key"></div>
                </div>
                <label class="flex items-center gap-1.5 text-ink-700"><input type="checkbox" v-model="provEdit.enabled" class="h-4 w-4 accent-brand-600"> Bật</label>
                <div class="flex gap-2">
                  <button @click="saveEditedProv" class="btn-brand btn-sm">💾 Lưu</button>
                  <button @click="editingProv = null" class="btn-ghost btn-sm">Hủy</button>
                </div>
              </div>
            </div>
            <p v-if="!customProviders.length" class="rounded-xl border border-dashed border-cream-300 p-4 text-center text-xs text-ink-500">Chưa có custom provider nào. Khai báo route đầu tiên bên dưới — mọi endpoint [OI]-compatible đều dùng được.</p>
          </div>
        </div>

        <!-- Add provider -->
        <div class="card p-5">
          <h3 class="text-sm font-semibold text-ink-900">➕ Thêm custom provider</h3>
          <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div><label class="label">Provider ID (slug)</label><input v-model="provForm.slug" class="input !py-2" placeholder="VD: openrouter"></div>
            <div><label class="label">Tên hiển thị</label><input v-model="provForm.name" class="input !py-2" placeholder="VD: OpenRouter"></div>
            <div><label class="label">Protocol</label>
              <select v-model="provForm.protocol" class="input !py-2"><option value="openai">openai</option><option value="dashscope">dashscope</option><option value="gemini">gemini</option></select>
            </div>
            <div class="col-span-2"><label class="label">Base URL</label><input v-model="provForm.base_url" class="input !py-2" placeholder="https://openrouter.ai/api/v1"></div>
            <div><label class="label">Auth</label>
              <select v-model="provForm.auth_style" class="input !py-2"><option value="bearer">Bearer</option><option value="x-goog-api-key">x-goog-api-key</option></select>
            </div>
            <div class="col-span-2 sm:col-span-3"><label class="label">API key ref (slug nhóm key)</label><input v-model="provForm.api_key_ref" class="input !py-2" placeholder="mặc định = Provider ID"></div>
          </div>
          <p class="mt-2 text-[11px] text-ink-500">🔌 {{ protocolHint }}</p>
          <p class="mt-1 text-[11px] text-ink-500">Provider ID cố định sau khi tạo — mọi model và generation tham chiếu theo slug này. Key đăng ký ở tab <b>API Keys</b> với provider = slug.</p>
          <button @click="saveProvider" :disabled="provSaving" class="btn-brand btn-sm mt-3">{{ provSaving ? 'Đang lưu…' : '➕ Thêm provider' }}</button>
        </div>
      </div>

      <!-- ══════════ TAB: MODELS ══════════ -->
      <div v-show="tab==='models'" class="space-y-5">
        <div class="card p-5">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-base font-semibold text-ink-900">🤖 Model Registry</h2>
            <p class="text-xs text-ink-500">{{ models.length }} model. <b>Vai trò (group)</b> quyết định model thuộc nhóm công việc nào — xem tab 🎯 Nhóm công việc.</p>
          </div>
          <p class="mt-1 text-xs text-ink-500">Vai trò: <b>image</b> = tạo ảnh · <b>edit</b> = sửa ảnh · <b>video</b> · <b>swap</b> = thử đồ / ghép người mẫu · <b>vision</b> = đọc ảnh · <b>prompt</b> = suy luận · <b>translate</b> = dịch. Thứ tự dùng: default nhóm (tab 🎯) → model theo ưu tiên giảm dần.</p>

          <div class="mt-4 space-y-4">
            <div v-for="(rows, g) in modelsByGroup" :key="g">
              <h3 class="text-sm font-semibold text-ink-900">{{ groupLabels[g] }} <span class="ml-1 rounded-full bg-cream-200 px-2 py-0.5 text-[10px] text-ink-700">× {{ rows.length }}</span></h3>
              <div class="mt-1.5 space-y-1.5">
                <div v-for="m in rows" :key="m.id" class="rounded-xl border border-cream-200 p-2.5 text-xs">
                  <div v-if="editingModel !== m.id" class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-ink-900">{{ m.name }}</span>
                    <span class="text-ink-500">{{ m.provider }} · {{ m.model_id }}</span>
                    <span v-if="providers.find(p => p.slug === m.provider)?.custom" class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">Custom</span>
                    <span class="rounded-full bg-cream-200 px-2 py-0.5 text-[10px] text-ink-700">Ưu tiên {{ m.priority }}</span>
                    <span :class="m.enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600'" class="rounded-full px-2 py-0.5 text-[10px]">{{ m.enabled ? 'Bật' : 'Tắt' }}</span>
                    <span class="ml-auto flex items-center gap-1.5">
                      <button @click="startEditModel(m)" class="btn-outline btn-sm">✏️ Sửa</button>
                      <button @click="removeModel(m)" class="btn-outline btn-sm text-red-600">Xóa</button>
                    </span>
                  </div>
                  <div v-else class="space-y-2">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                      <div><label class="label">Vai trò (nhóm)</label>
                        <select v-model="modelEdit.group" class="input !py-1.5"><option v-for="(l, gv) in groupLabels" :key="gv" :value="gv">{{ l }} ({{ gv }})</option></select>
                      </div>
                      <div><label class="label">Tên</label><input v-model="modelEdit.name" class="input !py-1.5"></div>
                      <div><label class="label">Provider</label>
                        <select v-model="modelEdit.provider" class="input !py-1.5"><option v-for="p in providers" :key="p.slug" :value="p.slug">{{ p.slug }}</option></select>
                      </div>
                      <div><label class="label">Model ID</label><input v-model="modelEdit.model_id" class="input !py-1.5"></div>
                      <div><label class="label">Key ref</label><input v-model="modelEdit.api_key_ref" class="input !py-1.5" placeholder="mặc định = provider"></div>
                      <div><label class="label">Ưu tiên</label><input type="number" v-model.number="modelEdit.priority" min="0" max="100" class="input !py-1.5"></div>
                    </div>
                    <label class="flex items-center gap-1.5 text-ink-700"><input type="checkbox" v-model="modelEdit.enabled" class="h-4 w-4 accent-brand-600"> Bật</label>
                    <div class="flex gap-2">
                      <button @click="saveEditedModel" class="btn-brand btn-sm">💾 Lưu</button>
                      <button @click="editingModel = null" class="btn-ghost btn-sm">Hủy</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Add model -->
        <div class="card p-5">
          <h3 class="text-sm font-semibold text-ink-900">➕ Thêm model</h3>
          <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div><label class="label">Vai trò (nhóm công việc)</label>
              <select v-model="modelForm.group" class="input !py-2">
                <option v-for="(l, gv) in groupLabels" :key="gv" :value="gv">{{ l }} ({{ gv }})</option>
              </select>
            </div>
            <div><label class="label">Tên</label><input v-model="modelForm.name" class="input !py-2" placeholder="VD: Wan 2.2 i2v"></div>
            <div><label class="label">Provider</label>
              <select v-model="modelForm.provider" class="input !py-2"><option value="">— Chọn —</option><option v-for="p in providers" :key="p.slug" :value="p.slug">{{ p.name }}</option></select>
            </div>
            <div><label class="label">Model ID</label><input v-model="modelForm.model_id" class="input !py-2" placeholder="wan2.2-i2v"></div>
            <div><label class="label">Key ref</label><input v-model="modelForm.api_key_ref" class="input !py-2" placeholder="mặc định = provider"></div>
            <div><label class="label">Ưu tiên</label><input type="number" v-model.number="modelForm.priority" min="0" max="100" class="input !py-2"></div>
          </div>
          <button @click="saveModel" :disabled="modelSaving" class="btn-brand btn-sm mt-3">{{ modelSaving ? 'Đang lưu…' : '➕ Thêm model' }}</button>
        </div>
      </div>

      <!-- ══════════ TAB: TASK GROUPS (NHÓM CÔNG VIỆC) ══════════ -->
      <div v-show="tab==='tasks'">
        <div class="card p-5">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-base font-semibold text-ink-900">🎯 Model theo nhóm công việc</h2>
            <p class="text-xs text-ink-500">Mỗi card / tính năng trong Studio một nhóm — chọn model mặc định riêng cho từng việc.</p>
          </div>
          <p class="mt-1 text-xs text-ink-500">Danh sách model của mỗi nhóm lấy từ <b>Model Registry</b> (tab 🤖 Models — đăng ký model với vai trò tương ứng). Chọn <b>Tự động</b> để nhóm dùng model đầu tiên theo ưu tiên (kế thừa cấu hình cũ).</p>

          <div class="mt-4 space-y-3">
            <div v-for="g in taskGroupKeys" :key="g" class="rounded-xl border border-cream-200 p-3.5">
              <div class="flex flex-wrap items-center gap-2">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-brand-600/15 text-sm">🎯</span>
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-semibold text-ink-900">{{ taskGroups[g].label }}</p>
                  <p class="mt-0.5 text-[11px] text-ink-500">
                    {{ taskGroups[g].models.length }} model trong nhóm · đang dùng:
                    <b :class="taskGroups[g].default ? 'text-emerald-600' : 'text-amber-600'">{{ taskDefaultLabel(g) }}</b>
                    <span v-if="taskGroups[g].assigned" class="ml-1 rounded-full bg-brand-100 px-1.5 py-0.5 text-[9px] font-semibold text-brand-700">đã gán thủ công</span>
                    <span v-else class="ml-1 rounded-full bg-cream-200 px-1.5 py-0.5 text-[9px] text-ink-600">tự động</span>
                  </p>
                </div>
              </div>
              <div class="mt-2.5 flex flex-wrap items-center gap-2">
                <select v-model="taskGroups[g].assigned" class="input max-w-md !py-1.5 text-xs" @change="saveTaskDefault(g)">
                  <option value="">⚙️ Tự động (ưu tiên model cao nhất)</option>
                  <option v-for="o in taskGroupModelOptions(g)" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <button v-if="taskGroups[g].assigned" @click="clearTaskDefault(g)" :disabled="taskSaving === g" class="btn-outline btn-sm">↺ Về tự động</button>
                <span v-if="taskSaving === g" class="text-[10px] text-ink-500">đang lưu…</span>
              </div>
              <!-- Model chips của nhóm — nhảy sang tab Models để sửa -->
              <div v-if="taskGroups[g].models.length" class="mt-2 flex flex-wrap gap-1">
                <span v-for="(m, i) in taskGroups[g].models.slice(0, 6)" :key="m.provider + m.model"
                      class="rounded-full px-2 py-0.5 text-[10px]"
                      :class="m.provider + ':' + m.model === taskGroups[g].default ? 'bg-emerald-100 text-emerald-700 font-semibold' : 'bg-cream-100 text-ink-600'">
                  {{ m.label }}{{ i === 5 && taskGroups[g].models.length > 6 ? '…' : '' }}
                </span>
                <button @click="tab='models'" class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 hover:bg-indigo-200">→ quản lý ở tab Models</button>
              </div>
              <p v-else class="mt-2 rounded-lg border border-dashed border-cream-300 p-2.5 text-[11px] text-ink-500">
                Chưa có model nào trong nhóm — đang kế thừa cấu hình legacy. Thêm model với vai trò <b>{{ groupLabels[g] || g }}</b> ở tab 🤖 Models.
              </p>
            </div>
          </div>
        </div>

        <!-- Bảng ánh xạ card → nhóm (tham khảo) -->
        <div class="card mt-5 p-5">
          <h3 class="text-sm font-semibold text-ink-900">🗺️ Card nào dùng nhóm nào?</h3>
          <div class="mt-2 overflow-x-auto">
            <table class="w-full text-left text-xs">
              <thead><tr class="border-b border-cream-200 text-ink-500">
                <th class="py-1.5 pr-3">Card / tính năng</th><th class="py-1.5 pr-3">Nhóm</th><th class="py-1.5">Model hiện hành</th>
              </tr></thead>
              <tbody class="text-ink-700">
                <tr class="border-b border-cream-100"><td class="py-1.5 pr-3">💡 Tạo Ảnh 2D (Concept)</td><td class="pr-3"><code>image</code></td><td>{{ taskGroups.image?.default || '—' }}</td></tr>
                <tr class="border-b border-cream-100"><td class="py-1.5 pr-3">🖼️ Ảnh mới từ ảnh mẫu / Thử đồ</td><td class="pr-3"><code>image</code></td><td>{{ taskGroups.image?.default || '—' }}</td></tr>
                <tr class="border-b border-cream-100"><td class="py-1.5 pr-3">✏️ Sửa ảnh (Inpaint) / Xóa vùng</td><td class="pr-3"><code>edit</code></td><td>{{ taskGroups.edit?.default || '—' }}</td></tr>
                <tr class="border-b border-cream-100"><td class="py-1.5 pr-3">🎬 Kịch bản quay (Video)</td><td class="pr-3"><code>video</code></td><td>{{ taskGroups.video?.default || '—' }}</td></tr>
                <tr class="border-b border-cream-100"><td class="py-1.5 pr-3">🪄 Thay Đổi Người Mẫu</td><td class="pr-3"><code>swap</code></td><td>{{ taskGroups.swap?.default || '—' }}</td></tr>
                <tr class="border-b border-cream-100"><td class="py-1.5 pr-3">👁️ Đọc ảnh (khuôn mặt / dáng)</td><td class="pr-3"><code>vision</code></td><td>{{ taskGroups.vision?.default || '—' }}</td></tr>
                <tr class="border-b border-cream-100"><td class="py-1.5 pr-3">✨ Thuật sỹ ảo / Giám đốc sáng tạo</td><td class="pr-3"><code>prompt</code></td><td>{{ taskGroups.prompt?.default || '—' }}</td></tr>
                <tr><td class="py-1.5 pr-3">🌐 Dịch prompt</td><td><code>translate</code></td><td>{{ taskGroups.translate?.default || '—' }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ══════════ TAB: GENERAL ══════════ -->
      <div v-show="tab==='general'">
        <div class="card p-5" v-if="cfgForm">
          <h2 class="font-display text-base font-semibold text-ink-900">📋 Cấu hình chung</h2>
          <p class="mt-1 text-xs text-ink-500">Cấu hình mặc định cho pipeline gọi model. Thứ tự fallback thực tế: model mặc định dưới đây → Model Registry theo ưu tiên.</p>
          <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div><label class="label">Provider sinh ảnh</label><input v-model="cfgForm.image_provider" class="input !py-2" placeholder="flux / wan / qwen / gemini / slug custom"></div>
            <div><label class="label">Model ảnh (flux)</label><input v-model="cfgForm.image_model" class="input !py-2"></div>
            <div><label class="label">Model ảnh (qwen)</label><input v-model="cfgForm.qwen_model" class="input !py-2"></div>
            <div><label class="label">Model video</label><input v-model="cfgForm.video_model" class="input !py-2"></div>
            <div><label class="label">Provider vision</label><input v-model="cfgForm.vision_provider" class="input !py-2" placeholder="gemini / qwen"></div>
            <div><label class="label">Provider prompt</label><input v-model="cfgForm.prompt_provider" class="input !py-2" placeholder="gemini / qwen / deepseek"></div>
            <div><label class="label">Xử lý</label>
              <select v-model="cfgForm.processing" class="input !py-2"><option value="sync">sync</option><option value="queue">queue</option></select>
            </div>
            <div><label class="label">Credit / ảnh</label><input type="number" v-model.number="cfgForm.image_credits" min="0" max="1000" class="input !py-2"></div>
            <div><label class="label">Credit / video</label><input type="number" v-model.number="cfgForm.video_credits" min="0" max="1000" class="input !py-2"></div>
          </div>
          <button @click="saveConfig" :disabled="cfgSaving" class="btn-brand btn-sm mt-4">{{ cfgSaving ? 'Đang lưu…' : '💾 Lưu cấu hình' }}</button>
        </div>
        <div class="card mt-5 p-5" v-if="data?.usage">
          <h3 class="text-sm font-semibold text-ink-900">📊 Sử dụng (tháng này)</h3>
          <div class="mt-2 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
            <div class="rounded-xl border border-cream-200 p-3"><p class="text-ink-500">Ảnh</p><p class="mt-1 text-lg font-semibold text-ink-900">{{ data.usage.images ?? 0 }}</p></div>
            <div class="rounded-xl border border-cream-200 p-3"><p class="text-ink-500">Video</p><p class="mt-1 text-lg font-semibold text-ink-900">{{ data.usage.videos ?? 0 }}</p></div>
            <div class="rounded-xl border border-cream-200 p-3"><p class="text-ink-500">Credit đã dùng</p><p class="mt-1 text-lg font-semibold text-ink-900">{{ data.usage.credits_used ?? 0 }}</p></div>
            <div class="rounded-xl border border-cream-200 p-3"><p class="text-ink-500">Limit</p><p class="mt-1 text-lg font-semibold text-ink-900">{{ data.usage.limit || '∞' }}</p></div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.25s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
