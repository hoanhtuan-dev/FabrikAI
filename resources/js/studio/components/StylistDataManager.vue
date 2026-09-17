<script setup>
import { ref, onMounted } from 'vue';
import { useLocalCatalog, isAdminUser } from '../composables/useLocalCatalog.js';

const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

// [2026-09-17] Bản tùy chỉnh của RIÊNG user, lưu trong localStorage của máy khách.
const typeCatalog = useLocalCatalog('stylist.types');
const questionCatalog = useLocalCatalog('stylist.questions');
const isAdmin = isAdminUser();
const mode = ref('mine');

const tab = ref('types');
const baseTypes = ref([]);
const baseQuestions = ref([]);
const types = ref([]);
const questions = ref([]);
const loading = ref(false);
const saving = ref(false);
const toast = ref('');
const toastType = ref('info');

const typeForm = ref({ id: null, slug: '', name: '', emoji: '', color: '#4a7a90' });
const editingType = ref(false);
const qForm = ref({ id: null, key: '', q: '', optsText: '' });
const editingQuestion = ref(false);
const confirmOpen = ref(false);
const confirmMsg = ref('');
const confirmAction = ref(null);

function flash(msg, type = 'info') { toast.value = msg; toastType.value = type; setTimeout(() => { toast.value = ''; }, 2400); }

function recompute() {
  types.value = typeCatalog.merge(baseTypes.value);
  questions.value = questionCatalog.merge(baseQuestions.value);
}

async function load() {
  loading.value = true;
  try {
    const r = await fetch('/api/stylist-data/data', { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    baseTypes.value = d.types || [];
    baseQuestions.value = d.questions || [];
    recompute();
  } catch (e) { flash('Lỗi tải dữ liệu (' + e.message + ').', 'error'); }
  finally { loading.value = false; }
}
onMounted(load);

async function postJson(url, body) {
  const r = await fetch(url, { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.message || 'Lỗi lưu.');
  return d;
}
async function del(url) {
  const r = await fetch(url, { method: 'DELETE', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } });
  if (!r.ok) throw new Error('Lỗi xóa.');
}
function askConfirm(msg, action) { confirmMsg.value = msg; confirmAction.value = action; confirmOpen.value = true; }
function doConfirm() { confirmOpen.value = false; const fn = confirmAction.value; confirmAction.value = null; if (typeof fn === 'function') fn(); }
function cancelConfirm() { confirmOpen.value = false; confirmAction.value = null; }

function newType() { typeForm.value = { id: null, slug: '', name: '', emoji: '', color: '#4a7a90' }; editingType.value = true; tab.value = 'types'; }
function editType(t) { typeForm.value = { id: t.id, slug: t.slug, name: t.name, emoji: t.emoji || '', color: t.color || '#4a7a90' }; editingType.value = true; tab.value = 'types'; }
function cancelType() { editingType.value = false; typeForm.value = { id: null, slug: '', name: '', emoji: '', color: '#4a7a90' }; }

async function saveType() {
  saving.value = true;
  try {
    const payload = { slug: typeForm.value.slug, name: typeForm.value.name, emoji: typeForm.value.emoji, color: typeForm.value.color };
    if (mode.value === 'global') {
      await postJson('/api/stylist-data/types', { id: typeForm.value.id, ...payload });
      await load();
      flash('Đã lưu loại trang phục dùng chung.');
    } else {
      if (typeForm.value.id) typeCatalog.update(typeForm.value.id, payload);
      else typeCatalog.create(payload);
      recompute();
      flash('Đã lưu vào bản của bạn (trên máy này).');
    }
    cancelType();
  } catch (e) { flash(e.message, 'error'); }
  finally { saving.value = false; }
}

function deleteType(t) {
  askConfirm('Xóa loại trang phục "' + t.name + '"?', async () => {
    try {
      if (mode.value === 'global') { await del('/api/stylist-data/types/' + t.id); await load(); }
      else { typeCatalog.remove(t.id); recompute(); }
      flash('Đã xóa.');
    } catch (e) { flash(e.message, 'error'); }
  });
}

function newQuestion() { qForm.value = { id: null, key: '', q: '', optsText: '' }; editingQuestion.value = true; tab.value = 'questions'; }
function editQuestion(q) { qForm.value = { id: q.id, key: q.key, q: q.q, optsText: (q.opts || []).join('\n') }; editingQuestion.value = true; tab.value = 'questions'; }
function cancelQuestion() { editingQuestion.value = false; qForm.value = { id: null, key: '', q: '', optsText: '' }; }

async function saveQuestion() {
  saving.value = true;
  try {
    const opts = qForm.value.optsText.split('\n').map(s => s.trim()).filter(Boolean);
    const payload = { key: qForm.value.key, q: qForm.value.q, opts };
    if (mode.value === 'global') {
      await postJson('/api/stylist-data/questions', { id: qForm.value.id, ...payload });
      await load();
      flash('Đã lưu câu hỏi dùng chung.');
    } else {
      if (qForm.value.id) questionCatalog.update(qForm.value.id, payload);
      else questionCatalog.create(payload);
      recompute();
      flash('Đã lưu vào bản của bạn (trên máy này).');
    }
    cancelQuestion();
  } catch (e) { flash(e.message, 'error'); }
  finally { saving.value = false; }
}

function deleteQuestion(q) {
  askConfirm('Xóa câu hỏi "' + q.key + '"?', async () => {
    try {
      if (mode.value === 'global') { await del('/api/stylist-data/questions/' + q.id); await load(); }
      else { questionCatalog.remove(q.id); recompute(); }
      flash('Đã xóa.');
    } catch (e) { flash(e.message, 'error'); }
  });
}

function resetMine() {
  askConfirm('Khôi phục về bản mặc định? Mọi tùy chỉnh của bạn trên máy này sẽ mất.', () => {
    typeCatalog.reset(); questionCatalog.reset(); recompute(); flash('Đã khôi phục bản mặc định.');
  });
}

const hasOverrides = () => typeCatalog.hasOverrides() || questionCatalog.hasOverrides();
</script>

<template>
  <div>
    <div v-if="toast" class="pointer-events-none fixed left-1/2 top-4 z-[95] -translate-x-1/2 rounded-full px-4 py-2 text-xs font-semibold shadow-2xl" :class="toastType === 'error' ? 'bg-red-600 text-white' : 'bg-ink-800 text-cream-100 border border-brand-500/40'">{{ toast }}</div>

    <!-- Nói rõ tùy chỉnh lưu ở đâu — trước đây trang này sửa thẳng bảng toàn cục. -->
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-ink-700 bg-ink-800/60 p-3">
      <p class="text-xs text-cream-300/70">
        <b class="text-cream-100">Bản của bạn</b> lưu ngay trên máy này, riêng cho tài khoản đang đăng nhập.
      </p>
      <div class="flex items-center gap-2">
        <div v-if="isAdmin" class="flex overflow-hidden rounded-lg border border-ink-600">
          <button @click="mode='mine'" :class="mode==='mine' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200'" class="px-2.5 py-1 text-[11px] font-semibold">Bản của tôi</button>
          <button @click="mode='global'" :class="mode==='global' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200'" class="px-2.5 py-1 text-[11px] font-semibold">Bản dùng chung</button>
        </div>
        <button v-if="hasOverrides()" @click="resetMine" class="rounded-lg bg-ink-700 px-2.5 py-1 text-[11px] text-cream-200 hover:bg-ink-600">Khôi phục mặc định</button>
      </div>
    </div>

    <div class="mb-4 flex gap-1.5">
      <button @click="tab='types'" :class="tab==='types' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200'" class="rounded-lg px-3 py-1.5 text-xs font-semibold">👗 Loại trang phục ({{ types.length }})</button>
      <button @click="tab='questions'" :class="tab==='questions' ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200'" class="rounded-lg px-3 py-1.5 text-xs font-semibold">📋 Câu hỏi ({{ questions.length }})</button>
    </div>

    <!-- Loại trang phục -->
    <div v-if="tab==='types'" class="rounded-lg border border-ink-700 bg-ink-800/60 p-4">
      <div class="mb-3 flex items-center justify-between">
        <p class="text-xs text-ink-500">Ảnh đại diện phục vụ tự động theo slug: <span class="text-brand-300">/garment/{slug}</span></p>
        <button @click="newType" class="btn-brand btn-sm">➕ Thêm loại</button>
      </div>

      <div v-if="loading" class="py-8 text-center text-xs text-cream-300/60">⏳ Đang tải…</div>
      <div v-else class="space-y-2">
        <div v-for="t in types" :key="t.slug" class="flex items-center gap-3 rounded-md border border-ink-700 bg-ink-900/60 p-2.5">
          <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-ink-900 text-lg" :style="{ boxShadow: 'inset 0 0 0 1px ' + (t.color || '#4a7a90') }">{{ t.emoji || '👗' }}</span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-xs font-semibold text-cream-100">{{ t.name }} <span v-if="t._local" class="ml-1 rounded bg-brand-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-brand-200">của bạn</span></p>
            <p class="truncate text-[10px] text-cream-300/50">slug: {{ t.slug }}</p>
          </div>
          <span class="h-5 w-5 shrink-0 rounded-full border border-white/20" :style="{ background: t.color || '#4a7a90' }"></span>
          <button @click="editType(t)" class="rounded-lg bg-ink-700 px-2 py-1 text-[11px] text-cream-200 hover:bg-brand-600 hover:text-white">Sửa</button>
          <button @click="deleteType(t)" class="rounded-lg bg-red-600/25 px-2 py-1 text-[11px] text-red-200 hover:bg-red-600 hover:text-white">Xóa</button>
        </div>
      </div>

      <div v-if="editingType" class="mt-4 rounded-lg border border-brand-500/40 bg-ink-900 p-4">
        <p class="mb-3 text-xs font-semibold text-brand-300">{{ typeForm.id ? 'Sửa loại trang phục' : 'Thêm loại trang phục' }}</p>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
          <div><label class="label">Slug (mã)</label><input v-model="typeForm.slug" class="input !py-2" placeholder="dress"></div>
          <div><label class="label">Tên</label><input v-model="typeForm.name" class="input !py-2" placeholder="Đầm"></div>
          <div><label class="label">Emoji</label><input v-model="typeForm.emoji" class="input !py-2" placeholder="👗"></div>
          <div><label class="label">Màu</label><div class="flex items-center gap-1.5"><input type="color" v-model="typeForm.color" class="h-9 w-10 cursor-pointer rounded-lg border border-ink-600 bg-ink-900"><input v-model="typeForm.color" class="input !py-2" placeholder="#e8577d"></div></div>
        </div>
        <div class="mt-3 flex justify-end gap-2">
          <button @click="cancelType" class="btn-outline btn-sm">Huỷ</button>
          <button @click="saveType" :disabled="saving" class="btn-brand btn-sm">{{ saving ? 'Đang lưu…' : '💾 Lưu' }}</button>
        </div>
      </div>
    </div>

    <!-- Câu hỏi -->
    <div v-else class="rounded-lg border border-ink-700 bg-ink-800/60 p-4">
      <div class="mb-3 flex items-center justify-between">
        <p class="text-xs text-ink-500">Câu hỏi hiển thị theo thứ tự; dùng <span class="text-brand-300">{name}</span> để chèn tên loại trang phục.</p>
        <button @click="newQuestion" class="btn-brand btn-sm">➕ Thêm câu hỏi</button>
      </div>

      <div v-if="loading" class="py-8 text-center text-xs text-cream-300/60">⏳ Đang tải…</div>
      <div v-else class="space-y-2">
        <div v-for="q in questions" :key="q.key" class="rounded-md border border-ink-700 bg-ink-900/60 p-3">
          <div class="flex items-start gap-3">
            <span class="mt-0.5 shrink-0 rounded bg-ink-900 px-1.5 py-0.5 font-mono text-[10px] text-brand-300">{{ q.key }}</span>
            <p class="min-w-0 flex-1 text-xs font-semibold text-cream-100">{{ q.q }} <span v-if="q._local" class="ml-1 rounded bg-brand-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-brand-200">của bạn</span></p>
            <button @click="editQuestion(q)" class="shrink-0 rounded-lg bg-ink-700 px-2 py-1 text-[11px] text-cream-200 hover:bg-brand-600 hover:text-white">Sửa</button>
            <button @click="deleteQuestion(q)" class="shrink-0 rounded-lg bg-red-600/25 px-2 py-1 text-[11px] text-red-200 hover:bg-red-600 hover:text-white">Xóa</button>
          </div>
          <p class="mt-1.5 text-[10px] text-cream-300/50">{{ (q.opts || []).length }} lựa chọn</p>
        </div>
      </div>

      <div v-if="editingQuestion" class="mt-4 rounded-lg border border-brand-500/40 bg-ink-900 p-4">
        <p class="mb-3 text-xs font-semibold text-brand-300">{{ qForm.id ? 'Sửa câu hỏi' : 'Thêm câu hỏi' }}</p>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="label">Key (mã)</label><input v-model="qForm.key" class="input !py-2" placeholder="fabric"></div>
        </div>
        <div class="mt-2"><label class="label">Câu hỏi</label><input v-model="qForm.q" class="input !py-2" placeholder="Chất liệu (kỹ thuật dệt):"></div>
        <div class="mt-2"><label class="label">Lựa chọn (mỗi dòng một lựa chọn)</label><textarea v-model="qForm.optsText" rows="5" class="input !text-xs" placeholder="Lụa satin mềm&#10;Chiffon mỏng nhẹ"></textarea></div>
        <div class="mt-3 flex justify-end gap-2">
          <button @click="cancelQuestion" class="btn-outline btn-sm">Huỷ</button>
          <button @click="saveQuestion" :disabled="saving" class="btn-brand btn-sm">{{ saving ? 'Đang lưu…' : '💾 Lưu' }}</button>
        </div>
      </div>
    </div>

    <!-- Confirm xóa (modal riêng, không dùng window.confirm) -->
    <div v-if="confirmOpen" role="dialog" aria-modal="true" aria-label="Xác nhận xóa" class="fixed inset-0 z-[95] flex items-center justify-center bg-black/70 p-4" @click.self="cancelConfirm">
      <div class="w-full max-w-sm rounded-lg border border-red-500/40 bg-ink-900 p-5 shadow-2xl" @click.stop>
        <p class="mb-2 text-sm font-semibold text-cream-100">⚠️ Xác nhận xóa</p>
        <p class="mb-4 text-xs leading-relaxed text-cream-200">{{ confirmMsg }}</p>
        <div class="flex justify-end gap-2">
          <button @click="cancelConfirm" class="btn-outline btn-sm">Huỷ</button>
          <button @click="doConfirm" class="rounded-md bg-red-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-red-500">🗑 Xóa</button>
        </div>
      </div>
    </div>
  </div>
</template>
