<script setup>
// SourcePickerPopup — popup chọn ảnh nguồn đưa lên canvas: "chọn nhiều → thêm các layer ảnh"
// (store.addImagesToCanvas). Nguồn ảnh:
//   - Thư viện ảnh đã tải lên: /api/ref-images, upload /api/upload-ref (uploadRef),
//     sort/search/cols, xóa ảnh.
//   - Ảnh kết quả (output library) — lấy từ store.generations.
// Footer: "Thêm vào canvas (n)".
import { ref, computed, watch } from 'vue';
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();

const props = defineProps({
  modelValue: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const close = () => emit('update:modelValue', false);

const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

// ── Thư viện ảnh đã tải lên (+ ảnh kết quả) ──
const refs = ref([]);
const query = ref('');
const sortKey = ref('newest');
const gridCols = ref(4);
const selRefs = ref([]);
const selOutput = ref([]);
const fileRef = ref(null);
const uploading = ref(false);

async function loadRefs() {
  try {
    const r = await fetch('/api/ref-images?_=' + Date.now(), { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    refs.value = d.items || [];
  } catch (e) { refs.value = []; }
}

// Kết quả (output library) — các ảnh đã sinh, chỉ lấy ảnh hoàn tất.
const output = computed(() => store.generations
  .filter(g => g.media_url && g.type !== 'video' && g.status !== 'failed')
  .map(g => ({ key: 'gen-' + g.id, url: g.media_url, name: 'Ảnh kết quả #' + g.id, kind: 'output' })));

async function onFile(e) {
  const files = Array.from(e.target.files || []);
  if (fileRef.value) fileRef.value.value = '';
  if (!files.length) return;
  uploading.value = true;
  try {
    const uploaded = [];
    for (const f of files) {
      const d = await store.uploadRef(f, false);
      if (d && d.url) uploaded.push({ key: 'ref-' + d.name, url: d.url, name: f.name || d.name, kind: 'ref' });
    }
    await loadRefs();
    // Tự đánh dấu CHỌN các ảnh vừa tải — người dùng bấm "Thêm vào canvas" để đưa lên.
    if (uploaded.length) {
      for (const u of uploaded) {
        if (!selRefs.value.some((x) => (x.key || x.name) === u.key)) selRefs.value.push(u);
      }
      store.toast('Đã tải ' + uploaded.length + ' ảnh — bấm "Thêm vào canvas" để đưa lên.');
    }
  } catch (err) {
    store.toast('Lỗi tải ảnh.', 'error');
  } finally {
    uploading.value = false;
  }
}

async function delRef(it) {
  try {
    const r = await fetch('/api/ref-images/' + it.name, { method: 'DELETE', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } });
    const d = await r.json();
    if (!r.ok) { store.toast(d.message || 'Không xóa được.', 'error'); return; }
    loadRefs();
    store.toast('Đã xóa ảnh.');
  } catch (e) { store.toast('Lỗi xóa.', 'error'); }
}

function clickItem(item) {
  toggle(item);
}

function toggle(item) {
  const key = item.key || item.name || item.url;
  const list = item.kind === 'output' ? selOutput : selRefs;
  const i = list.value.findIndex((x) => (x.key || x.name || x.url) === key);
  if (i >= 0) list.value.splice(i, 1);
  else list.value.push(item);
}
const isSel = (item) => {
  const key = item.key || item.name || item.url;
  const list = item.kind === 'output' ? selOutput : selRefs;
  return list.value.some((x) => (x.key || x.name || x.url) === key);
};

// Sort / search thư viện đã tải lên (y hệt SourceLibraryPicker).
const sortOptions = [
  { value: 'newest', label: 'Mới nhất' },
  { value: 'oldest', label: 'Cũ nhất' },
  { value: 'name_asc', label: 'Tên A→Z' },
  { value: 'name_desc', label: 'Tên Z→A' },
  { value: 'size_desc', label: 'Dung lượng lớn → nhỏ' },
  { value: 'size_asc', label: 'Dung lượng nhỏ → lớn' },
  { value: 'area_desc', label: 'Độ phân giải cao → thấp' },
];
const sortedRefs = computed(() => {
  let list = refs.value.slice();
  const q = query.value.trim().toLowerCase();
  if (q) list = list.filter((it) => (it.name || '').toLowerCase().includes(q));
  const k = sortKey.value;
  list.sort((a, b) => {
    switch (k) {
      case 'newest': return (b.mtime || 0) - (a.mtime || 0);
      case 'oldest': return (a.mtime || 0) - (b.mtime || 0);
      case 'name_asc': return (a.name || '').localeCompare(b.name || '');
      case 'name_desc': return (b.name || '').localeCompare(a.name || '');
      case 'size_desc': return (b.size || 0) - (a.size || 0);
      case 'size_asc': return (a.size || 0) - (b.size || 0);
      case 'area_desc': return ((b.width || 0) * (b.height || 0)) - ((a.width || 0) * (a.height || 0));
      default: return 0;
    }
  });
  return list;
});
const fmtSize = (b) => { if (!b) return '—'; if (b < 1024) return b + ' B'; if (b < 1048576) return (b / 1024).toFixed(0) + ' KB'; return (b / 1048576).toFixed(1) + ' MB'; };

// ── Footer ──
const totalSel = computed(() => selRefs.value.length + selOutput.value.length);

function confirmAdd() {
  const all = [...selRefs.value, ...selOutput.value].filter((it) => it && it.url);
  if (!all.length) return;
  store.addImagesToCanvas(all); // thêm layer + tự toast "Đã thêm N ảnh vào canvas."
  close();
}

watch(() => props.modelValue, (open) => {
  if (open) {
    // Reset bộ lọc + lựa chọn mỗi lần mở (y hệt các picker cũ).
    query.value = ''; sortKey.value = 'newest'; selRefs.value = []; selOutput.value = [];
    loadRefs();
  }
});
</script>

<template>
  <div v-if="modelValue" role="dialog" aria-modal="true" aria-label="Chọn nguồn ảnh" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-4" @click.self="close">
    <div class="flex h-[82vh] w-full max-w-3xl flex-col rounded-lg border border-ink-700 bg-ink-900 p-4 shadow-2xl" style="height: min(82vh, 760px)">
      <!-- ══ Header ══ -->
      <div class="mb-3 flex items-start justify-between">
        <div class="flex items-center gap-2.5">
          <div class="grid h-9 w-9 place-items-center rounded-md bg-brand-600/15 text-brand-300"><StudioIcon name="layers" size="h-4.5 w-4.5"/></div>
          <div>
            <p class="text-sm font-semibold text-cream-100">Thêm ảnh nguồn</p>
            <p class="text-[11px] text-cream-300/60">Thư viện {{ refs.length + output.length }} ảnh — nhấn chọn nhiều rồi thêm vào canvas</p>
          </div>
        </div>
        <button @click="close" class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-ink-800 text-cream-300 transition-colors hover:bg-ink-700 hover:text-white" title="Đóng" :aria-label="'Đóng'"><StudioIcon name="x" size="h-4 w-4"/></button>
      </div>

      <!-- ══ Thân popup ══ -->
      <div class="flex min-h-0 flex-1 flex-col">
        <template>
          <label class="mb-3 flex h-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed border-ink-600 bg-ink-800/40 text-xs font-medium text-cream-200 transition-colors hover:border-brand-500 hover:bg-brand-600/10 hover:text-brand-200" :title="'Tải ảnh mới lên thư viện'">
            <StudioIcon name="imagePlus" size="h-4 w-4"/>
            {{ uploading ? 'Đang tải lên…' : 'Tải ảnh mới' }}<span class="text-cream-300/50">(chọn nhiều file được)</span>
            <input ref="fileRef" type="file" accept="image/*" multiple @change="onFile" class="hidden">
          </label>

          <div class="scrollbar-hide -mr-1 min-h-0 flex-1 overflow-y-auto overscroll-contain pr-1">
            <!-- Kết quả (output library) -->
            <template v-if="output.length">
              <p class="mb-1.5 flex items-center gap-1 text-xs font-semibold text-cream-200"><StudioIcon name="image" size="h-3.5 w-3.5"/>Ảnh kết quả (output library)</p>
              <div class="mb-3 grid gap-2" :style="{ gridTemplateColumns: 'repeat(' + gridCols + ', minmax(0, 1fr))' }">
                <div v-for="g in output" :key="g.key" class="group relative cursor-pointer overflow-hidden rounded-md border transition-colors" :class="isSel(g) ? 'border-brand-400 ring-2 ring-brand-400/70' : 'border-ink-700 hover:border-ink-600'" style="padding-bottom: 100%" @click="clickItem(g)">
                  <img :src="thumbUrl(g.url)" class="absolute inset-0 h-full w-full bg-ink-900 object-cover" loading="lazy" alt="" @error="onThumbError($event, g.url)">
                  <span v-if="isSel(g)" class="pointer-events-none absolute inset-0 grid place-items-center bg-brand-500/15"><span class="grid h-9 w-9 place-items-center rounded-full bg-brand-500 text-white shadow-lg ring-2 ring-white/50"><StudioIcon name="check" size="h-5 w-5"/></span></span>
                  <span class="absolute inset-x-0 bottom-0 truncate bg-black/60 px-1 py-0.5 text-[9px] text-cream-200">{{ g.name }}</span>
                </div>
              </div>
            </template>

            <!-- Tìm / sắp xếp / cỡ ô -->
            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center">
              <div class="relative flex-1">
                <StudioIcon name="search" size="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cream-300/50"/>
                <input v-model="query" placeholder="Tìm theo tên ảnh…" class="h-9 w-full rounded-md border border-ink-700 bg-ink-800/60 pl-9 pr-3 text-xs text-cream-100 placeholder:text-cream-300/40 focus:border-brand-500 focus:outline-none">
              </div>
              <div class="relative">
                <StudioIcon name="sliders" size="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cream-300/50"/>
                <select v-model="sortKey" class="h-9 w-full appearance-none rounded-md border border-ink-700 bg-ink-800/60 pl-9 pr-8 text-xs text-cream-100 focus:border-brand-500 focus:outline-none sm:w-52">
                  <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <StudioIcon name="chevronDown" size="pointer-events-none absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-cream-300/50"/>
              </div>
              <div class="flex h-9 items-center gap-2 rounded-md border border-ink-700 bg-ink-800/60 px-3" title="Kích thước ô ảnh">
                <StudioIcon name="grid" size="h-4 w-4 shrink-0 text-cream-300/50"/>
                <input type="range" min="2" max="8" step="1" v-model.number="gridCols" class="h-1.5 w-24 cursor-pointer accent-brand-500">
              </div>
            </div>

            <!-- Lưới ảnh đã tải lên -->
            <div class="grid content-start gap-2.5" :style="{ gridTemplateColumns: 'repeat(' + gridCols + ', minmax(0, 1fr))' }">
              <div v-for="it in sortedRefs" :key="it.name" class="group relative cursor-pointer overflow-hidden rounded-md border transition-colors" :class="isSel({ key: 'ref-' + it.name, url: it.url, name: it.name, kind: 'ref' }) ? 'border-brand-400 ring-2 ring-brand-400/70' : 'border-ink-700 hover:border-ink-600'" :title="it.name" style="padding-bottom: 100%" @click="clickItem({ key: 'ref-' + it.name, url: it.url, name: it.name, kind: 'ref' })">
                <img :src="thumbUrl(it.url)" class="absolute inset-0 h-full w-full object-cover" loading="lazy" alt="" @error="onThumbError($event, it.url)">
                <span v-if="isSel({ key: 'ref-' + it.name, url: it.url, name: it.name, kind: 'ref' })" class="pointer-events-none absolute inset-0 grid place-items-center bg-brand-500/15"><span class="grid h-9 w-9 place-items-center rounded-full bg-brand-500 text-white shadow-lg ring-2 ring-white/50"><StudioIcon name="check" size="h-5 w-5"/></span></span>
                <span v-if="it.used" class="absolute left-1.5 top-1.5 flex items-center gap-0.5 rounded-md bg-black/70 px-1.5 py-0.5 text-[9px] font-medium text-emerald-300"><StudioIcon name="check" size="h-3 w-3"/>đang dùng</span>
                <button v-if="!it.used" @click.stop="delRef(it)" class="absolute right-1.5 top-1.5 hidden h-6 w-6 place-items-center rounded-full bg-red-600/90 text-white transition-colors hover:bg-red-500 group-hover:grid" title="Xóa ảnh" :aria-label="'Xóa ảnh'"><StudioIcon name="trash" size="h-3.5 w-3.5"/></button>
                <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent px-1.5 pb-1 pt-5">
                  <p class="truncate text-[10px] font-medium text-cream-100">{{ it.name }}</p>
                  <p class="truncate text-[9px] text-cream-300/75">{{ it.width }}×{{ it.height }} · {{ fmtSize(it.size) }}</p>
                </div>
              </div>
              <div v-if="!sortedRefs.length" class="col-span-full flex flex-col items-center justify-center gap-2 py-8 text-center">
                <p class="text-xs text-cream-300/50">{{ refs.length ? 'Không có ảnh khớp tìm kiếm.' : 'Chưa có ảnh nào — tải ảnh đầu tiên lên nhé.' }}</p>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- ══ Footer ══ -->
      <div class="mt-3 flex shrink-0 items-center justify-between gap-2">
        <span class="text-[11px] text-cream-300/70">
          {{ totalSel ? 'Đã chọn ' + totalSel + ' ảnh' : 'Nhấn chọn 1 hoặc nhiều ảnh để thêm vào canvas' }}
        </span>
        <button @click="confirmAdd" :disabled="!totalSel" class="flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-brand-500 disabled:cursor-not-allowed disabled:opacity-40" title="Thêm ảnh đã chọn vào canvas (không xóa ảnh cũ)">
          <StudioIcon name="plus" size="h-4 w-4"/>Thêm vào canvas ({{ totalSel }})
        </button>
      </div>
    </div>
  </div>
</template>