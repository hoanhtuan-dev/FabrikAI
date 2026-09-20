<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from './store.js';
import { thumbUrl, onThumbError } from './composables/useStudioThumb.js';
import GalleryModal from './components/GalleryModal.vue';
import PromptLibraryTab from './components/PromptLibraryTab.vue';
import StudioIcon from './components/StudioIcon.vue';
import { gridClass } from './libraryLayout.js';

const store = useStudioStore();

// embedded: được nhúng trong StudioApp (SPA /studio) thay vì trang riêng /api/library.
// Khi embedded: wrapper compact (lấp đầy khung studio), không render GalleryModal riêng (StudioApp lo),
// và nút "Về Studio" emit('back') để chuyển view client-side thay vì điều hướng full-page.
const props = defineProps({ embedded: { type: Boolean, default: false } });
const emit = defineEmits(['back']);
function goBack() { if (props.embedded) emit('back'); else window.location.href = '/'; }

const types = [
  { value: '', label: 'Ảnh + Video' },
  { value: 'image', label: 'Ảnh' },
  { value: 'video', label: 'Video' },
];

const statuses = [
  { value: '', label: 'Tất cả trạng thái' },
  { value: 'completed', label: 'Hoàn tất' },
  { value: 'pending', label: 'Đang chờ' },
  { value: 'processing', label: 'Đang xử lý' },
  { value: 'failed', label: 'Lỗi' },
  { value: 'cancelled', label: 'Đã hủy' },
];

const selectedCount = computed(() => store.librarySelection.length);
const stats = computed(() => store.libraryStats || {});
const suggestLibStats = computed(() => store.suggestLibStats || {});
const allSelected = computed(() => store.libraryItems.length > 0 && store.librarySelection.length === store.libraryItems.length);
const selectedBytes = computed(() => {
  const ids = new Set(store.librarySelection);
  let bytes = 0;
  for (const g of store.libraryItems) if (ids.has(g.id) && g.media_url) bytes += (Number(g._size) || 0);
  return bytes;
});

// ── Files đã tải lên ──
const uploadStats = computed(() => store.uploadStats || {});
const uploadSelectedCount = computed(() => store.uploadSelection.length);
const uploadUnusedCount = computed(() => store.uploadItems.filter(f => !f.used).length);
function isUploadSelected(rel) { return store.uploadSelection.includes(rel); }

// ── Xóa đơn lẻ file tải lên (2 bước, chỉ file CHƯA dùng) ──
const confirmUploadDelete = ref(''); // rel của file đang xác nhận xóa
let uploadConfirmTimer = null;
function askUploadDelete(f) {
  if (!f) return;
  if (f.used) { store.toast('File đang được dùng — không thể xóa.', 'error'); return; }
  confirmUploadDelete.value = f.rel;
  clearTimeout(uploadConfirmTimer);
  uploadConfirmTimer = setTimeout(() => { confirmUploadDelete.value = ''; }, 5000);
}
function cancelUploadDelete() { confirmUploadDelete.value = ''; clearTimeout(uploadConfirmTimer); }
async function runUploadDelete() {
  const rel = confirmUploadDelete.value;
  confirmUploadDelete.value = '';
  clearTimeout(uploadConfirmTimer);
  await store.deleteUpload(rel);
}

function statusLabel(s) { const f = statuses.find(x => x.value === s); return f ? f.label : (s || '—'); }
function fmtBytes(n) { return store.formatBytes(n); }
function fmtNum(n) { const v = Number(n) || 0; return v >= 1000 ? v.toLocaleString('vi-VN') : String(v); }

function toggleAll() { allSelected.value ? store.librarySelectNone() : store.librarySelectAll(); }
function isSelected(id) { return store.librarySelection.includes(id); }

// ── Gắn hàng loạt ảnh đã chọn vào một bộ sưu tập ──
const bulkAttachProjectId = ref(null);
async function bulkAttach() {
  if (!bulkAttachProjectId) { store.toast('Chọn bộ sưu tập đích trước.', 'error'); return; }
  const ids = store.librarySelection.filter(Boolean);
  if (!ids.length) { store.toast('Chưa chọn ảnh nào để gắn.', 'error'); return; }
  store.toast('Đang gắn ' + ids.length + ' ảnh vào bộ sưu tập…', 'info');
  const res = await store.attachGenerationToProject(bulkAttachProjectId, ids, 'attach');
  if (res && res.ok) {
    store.librarySelectNone();
    bulkAttachProjectId.value = null;
  }
}

// ── Xác nhận 2 bước cho các hành động nguy hiểm ──
const confirmAction = ref(''); // '' | 'bulk' | 'junk' | 'old' | 'orphans' | 'ubulk' | 'uclean'
let confirmTimer = null;
function ask(action) {
  if (!action) return;
  if (action === 'bulk' && !selectedCount.value) { store.toast('Chưa chọn ảnh nào để xóa.', 'error'); return; }
  if (action === 'ubulk' && !uploadSelectedCount.value) { store.toast('Chưa chọn file nào để xóa.', 'error'); return; }
  confirmAction.value = action;
  clearTimeout(confirmTimer);
  confirmTimer = setTimeout(() => { confirmAction.value = ''; }, 5000);
}
function cancelConfirm() { confirmAction.value = ''; clearTimeout(confirmTimer); }
// §5.2: trước đây KHÔNG clear timer khi unmount -> callback 5s còn sống sau khi rời trang
// (gán vào ref của component đã hủy). GalleryModal đã làm đúng, LibraryApp thì chưa.
onBeforeUnmount(() => clearTimeout(confirmTimer));
async function runConfirm() {
  const a = confirmAction.value;
  confirmAction.value = '';
  clearTimeout(confirmTimer);
  if (a === 'bulk') await store.libraryBulkDelete();
  else if (a === 'junk') await store.libraryCleanup('junk');
  else if (a === 'old') await store.libraryCleanup('old');
  else if (a === 'orphans') await store.libraryCleanup('orphans');
  else if (a === 'ubulk') await store.uploadBulkDelete();
  else if (a === 'uclean') await store.uploadCleanup();
}

function onChangeType(e) { store.setLibraryFilter('type', e.target.value); }
function onChangeStatus(e) { store.setLibraryFilter('status', e.target.value); }
function onChangeProject(e) { store.setLibraryFilter('project_id', e.target.value); }
function onChangeOldDays() { store.refreshLibraryScan(); }

let searchTimer = null;
function onSearchInput(e) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => store.setLibraryFilter('q', e.target.value || ''), 350);
}

function openViewer(g) { store.openViewer(g); }

function switchTab(tab) {
  store.libraryTab = tab;
  if (tab === 'uploads') {
    if (!store.uploadItems.length) store.loadUploads();
    // Cần danh sách dự án để gắn ảnh tải lên vào bộ sưu tập (loadProjects tự bỏ qua nếu đã nạp).
    if (!store.projectLoaded) store.loadProjects();
  }
  else if (tab === 'suggest' && !store.suggestLibItems.length) store.loadSuggestLib();
}

// ── Gắn ẢNH TẢI LÊN vào B SU TẬP ─
// Hai loại ảnh nằm cùng một Thư viện; trước đây chỉ ảnh do AI tạo mới vào được bộ sưu tập.
const projectOptions = computed(() => store.projects || []);

async function onUploadProject(f, value) {
  const target = Number(value) || 0;
  if (!target) {
    if (f.project_id) await store.attachUploadProject(f.project_id, f.rel, 'detach');
    return;
  }
  if (target === Number(f.project_id)) return;
  await store.attachUploadProject(target, f.rel, 'attach');
}
function refresh() {
  if (store.libraryTab === 'uploads') store.loadUploads();
  else if (store.libraryTab === 'suggest') store.loadSuggestLib();
  else { store.loadLibrary(true); store.refreshLibraryScan(); }
}

// ── Sắp xếp / hiển thị / cỡ lưới (dùng chung 3 tab) ──
const sortOptions = computed(() => {
  if (store.libraryTab === 'uploads') return [
    { value: 'newest', label: 'Mới nhất' },
    { value: 'oldest', label: 'Cũ nhất' },
    { value: 'name_asc', label: 'Tên A→Z' },
    { value: 'name_desc', label: 'Tên Z→A' },
    { value: 'size_desc', label: 'Dung lượng ↓' },
    { value: 'size_asc', label: 'Dung lượng ↑' },
  ];
  if (store.libraryTab === 'suggest') return [
    { value: 'newest', label: 'Mới nhất' },
    { value: 'oldest', label: 'Cũ nhất' },
    { value: 'name_asc', label: 'Loại A→Z' },
    { value: 'name_desc', label: 'Loại Z→A' },
    { value: 'used_desc', label: 'Dùng nhiều nhất' },
  ];
  return [
    { value: 'newest', label: 'Mới nhất' },
    { value: 'oldest', label: 'Cũ nhất' },
    { value: 'name_asc', label: 'Tên A→Z' },
    { value: 'name_desc', label: 'Tên Z→A' },
    { value: 'cost_desc', label: 'Credit ↓' },
    { value: 'cost_asc', label: 'Credit ↑' },
  ];
});

const activeSort = computed({
  get() {
    if (store.libraryTab === 'uploads') return store.uploadSort;
    if (store.libraryTab === 'suggest') return store.suggestLibSort;
    return store.librarySort;
  },
  set(v) {
    if (store.libraryTab === 'uploads') store.setUploadSort(v);
    else if (store.libraryTab === 'suggest') store.setSuggestLibSort(v);
    else store.setLibrarySort(v);
  },
});

const gridCls = computed(() => gridClass(store.libraryGrid));

// File tải lên: sort client-side (không phân trang → sắp xếp ngay trên danh sách đã tải).
const sortedUploads = computed(() => {
  const arr = [...(store.uploadItems || [])];
  const s = store.uploadSort;
  const cmp = {
    newest: (a, b) => (b.mtime || 0) - (a.mtime || 0),
    oldest: (a, b) => (a.mtime || 0) - (b.mtime || 0),
    name_asc: (a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'vi'),
    name_desc: (a, b) => String(b.name || '').localeCompare(String(a.name || ''), 'vi'),
    size_desc: (a, b) => (b.size || 0) - (a.size || 0),
    size_asc: (a, b) => (a.size || 0) - (b.size || 0),
  }[s] || ((a, b) => (b.mtime || 0) - (a.mtime || 0));
  return arr.sort(cmp);
});

function seedLabel(g) {
  if (g == null) return '';
  const s = g.seed ?? g.meta?.seed;
  return (s === null || s === undefined || s === '') ? '' : String(s);
}

// ── Bộ sưu tập helpers ──
const activeProjectId = computed(() => store.libraryFilters.project_id);
const filteredProject = computed(() => {
  if (!activeProjectId.value || activeProjectId.value === 'none') return null;
  return store.projects.find(p => Number(p.id) === Number(activeProjectId.value)) || null;
});
const canApplyFilteredProject = computed(() => {
  return filteredProject.value && store.appliedProject?.id !== Number(activeProjectId.value);
});
function applyFilteredProject() {
  if (filteredProject.value) store.applyProject(filteredProject.value);
}
function getProjectColor(g) {
  if (!g || !g.project_id) return '#7aa2f7';
  const p = store.projects.find(p => Number(p.id) === Number(g.project_id));
  return p?.color || '#7aa2f7';
}
function clickProjectChip(g) {
  store.setLibraryFilter('project_id', g.project_id);
}

onMounted(async () => {
  store.libraryManage = false;
  await store.loadLibrary(true);
  await store.refreshLibraryScan();
  store.loadUploads();
  if (!store.projects.length && !store.projectLoaded) store.loadProjects();
});
</script>

<template>
  <div class="studio-dark text-cream-100" :class="props.embedded ? 'min-h-0 flex-1 overflow-y-auto bg-ink-900 p-4 sm:p-6' : 'min-h-screen bg-ink-900 p-4 sm:p-6'">
    <div class="mx-auto max-w-7xl">
      <!-- ══ Thanh điều hướng (header) ══ -->
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-ink-700 bg-ink-900/70 px-3 py-2.5 shadow-lg shadow-black/20 sm:px-4">
        <!-- Trái: thương hiệu + tiêu đề + điều hướng + dự án hiện tại -->
        <div class="flex min-w-0 flex-wrap items-center gap-2.5">
          <div class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-brand-600/20 text-brand-300 ring-1 ring-brand-500/30">
            <StudioIcon name="image" size="h-4 w-4" />
          </div>
          <h1 class="font-display text-lg font-semibold leading-tight sm:text-xl">
            Thư viện
            <span class="ml-1.5 rounded-full border border-ink-700 bg-ink-800 px-2 py-0.5 text-[11px] font-semibold text-cream-300/80">{{ fmtNum(store.libraryTab === 'uploads' ? (uploadStats.total ?? store.uploadItems.length) : store.libraryTab === 'suggest' ? (suggestLibStats.total ?? 0) : store.libraryTotal) }}</span>
          </h1>
          <button type="button" @click="goBack" class="tool-btn !px-3 !py-1.5" title="Quay lại studio thiết kế">
            <span class="rotate-180"><StudioIcon name="arrowRight" size="h-3.5 w-3.5" /></span> Về Studio
          </button>
          <!-- Chip dự án hiện tại (gọn) -->
          <div v-if="store.appliedProject" class="flex items-center gap-1.5 rounded-full border border-brand-500/50 bg-brand-600/20 px-3 py-1 text-sm text-brand-100">
            <StudioIcon name="pin" size="h-3.5 w-3.5" />
            <span class="max-w-[160px] truncate" :title="store.appliedProject.name">{{ store.appliedProject.name }}</span>
            <button @click="store.unapplyProject()" aria-label="Ngắt dự án hiện tại" class="ml-0.5 rounded-full p-0.5 text-brand-300 hover:bg-brand-500/30 hover:text-brand-100">
              <StudioIcon name="x" size="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
        <!-- Phải: hành động -->
        <div class="flex items-center gap-1.5">
          <button @click="refresh" :disabled="store.libraryLoading || store.uploadLoading"
                  class="icon-btn !h-9 !w-9 rounded-md border border-ink-600 bg-ink-800 disabled:opacity-40"
                  title="Làm mới thư viện" aria-label="Làm mới thư viện">
            <StudioIcon name="refresh" size="h-4 w-4" />
          </button>
          <button @click="store.libraryManage = !store.libraryManage"
                  class="tool-btn" :class="store.libraryManage ? 'is-active' : ''"
                  :title="store.libraryManage ? 'Đang quản lý — bấm để thoát' : 'Chọn & dọn dẹp ảnh'">
            <StudioIcon name="kanban" size="h-3.5 w-3.5" />
            <template v-if="store.libraryManage">Đang quản lý</template>
            <template v-else>Quản lý / Dọn dẹp</template>
          </button>
        </div>
      </div>

      <!-- ══ Tab điều hướng: Ảnh đã tạo / File tải lên / Prompt ══ -->
      <div class="seg mb-4 !p-1">
        <button @click="switchTab('generations')" class="seg-btn !py-2" :class="store.libraryTab === 'generations' ? 'is-active' : ''" title="Ảnh AI đã tạo">
          <StudioIcon name="image" size="h-4 w-4"/> Ảnh đã tạo
        </button>
        <button @click="switchTab('uploads')" class="seg-btn !py-2" :class="store.libraryTab === 'uploads' ? 'is-active' : ''" title="File đã tải lên">
          <StudioIcon name="folderOpen" size="h-4 w-4"/> File tải lên
          <span v-if="uploadStats.unused_count" class="ml-1 rounded-full bg-red-500/30 px-1.5 py-0.5 text-[10px] font-semibold text-red-100">{{ uploadStats.unused_count }}</span>
        </button>
        <button @click="switchTab('suggest')" class="seg-btn !py-2" :class="store.libraryTab === 'suggest' ? 'is-active' : ''" title="Prompt phân tích từ Gợi ý từ ảnh">
          <StudioIcon name="lightbulb" size="h-4 w-4"/> 💡 Prompt
          <span v-if="suggestLibStats.total" class="ml-1 rounded-full bg-brand-500/30 px-1.5 py-0.5 text-[10px] font-semibold text-brand-100">{{ fmtNum(suggestLibStats.total) }}</span>
        </button>
      </div>

      <!-- ══ Thanh sắp xếp / hiển thị / cỡ lưới (dùng chung cho cả 3 tab) ══ -->
      <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-ink-700 bg-ink-800 p-3">
        <div class="flex items-center gap-1.5">
          <label class="text-[10px] font-semibold uppercase tracking-wide text-cream-300">Sắp xếp theo</label>
          <select v-model="activeSort" class="rounded-lg border border-ink-700 bg-ink-900 px-2 py-1.5 text-sm text-cream-100 focus:border-brand-400 focus:outline-none">
            <option v-for="o in sortOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </div>
        <div class="flex items-center gap-1.5">
          <label class="text-[10px] font-semibold uppercase tracking-wide text-cream-300">Hiển thị theo</label>
          <div class="seg !p-0.5">
            <button @click="store.libraryView = 'grid'" class="seg-btn !py-1" :class="store.libraryView === 'grid' ? 'is-active' : ''" title="Hiển thị dạng lưới"><StudioIcon name="grid" size="h-3.5 w-3.5" /> Lưới</button>
            <button @click="store.libraryView = 'list'" class="seg-btn !py-1" :class="store.libraryView === 'list' ? 'is-active' : ''" title="Hiển thị dạng danh sách"><StudioIcon name="list" size="h-3.5 w-3.5" /> Danh sách</button>
          </div>
        </div>
        <div class="flex items-center gap-1.5">
          <label class="text-[10px] font-semibold uppercase tracking-wide text-cream-300">Cỡ lưới ảnh</label>
          <div class="seg !p-0.5">
            <button @click="store.libraryGrid = 's'" class="seg-btn !px-2.5 !py-1" :class="store.libraryGrid === 's' ? 'is-active' : ''" title="Lưới nhỏ (nhiều cột)">S</button>
            <button @click="store.libraryGrid = 'm'" class="seg-btn !px-2.5 !py-1" :class="store.libraryGrid === 'm' ? 'is-active' : ''" title="Lưới vừa">M</button>
            <button @click="store.libraryGrid = 'l'" class="seg-btn !px-2.5 !py-1" :class="store.libraryGrid === 'l' ? 'is-active' : ''" title="Lưới lớn (ít cột)">L</button>
          </div>
        </div>
      </div>

      <template v-if="store.libraryTab === 'generations'">
      <!-- ══ Thống kê ══ -->
      <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
        <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
          <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Tổng mục</p>
          <p class="text-lg font-semibold text-cream-100">{{ fmtNum(stats.total ?? store.libraryTotal) }}</p>
        </div>
        <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
          <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Hoàn tất</p>
          <p class="text-lg font-semibold text-emerald-300">{{ fmtNum(stats.completed ?? 0) }}</p>
        </div>
        <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
          <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Đã gắn dự án</p>
          <p class="text-lg font-semibold text-brand-300">{{ fmtNum(stats.project_linked_count ?? 0) }}</p>
        </div>
        <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
          <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Ảnh rác</p>
          <p class="text-lg font-semibold text-amber-300">{{ fmtNum(stats.junk_count ?? 0) }}</p>
          <p v-if="stats.junk_bytes" class="text-[10px] text-cream-300/50">{{ fmtBytes(stats.junk_bytes) }}</p>
        </div>
        <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
          <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Ảnh cũ</p>
          <p class="text-lg font-semibold text-sky-300">{{ fmtNum(stats.old_count ?? 0) }}</p>
          <p v-if="stats.old_bytes" class="text-[10px] text-cream-300/50">{{ fmtBytes(stats.old_bytes) }}</p>
        </div>
        <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
          <p class="text-[10px] uppercase tracking-wide text-cream-300/50">File mồ côi</p>
          <p class="text-lg font-semibold text-red-300">{{ fmtNum(stats.orphan_count ?? 0) }}</p>
          <p v-if="stats.orphan_bytes" class="text-[10px] text-cream-300/50">{{ fmtBytes(stats.orphan_bytes) }}</p>
        </div>
        <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
          <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Ngưỡng ảnh cũ</p>
          <div class="mt-1 flex items-center gap-1">
            <input v-model.number="store.libraryFilters.old_days" type="number" min="1" max="365" @change="onChangeOldDays"
                   class="w-16 rounded-lg border border-ink-700 bg-ink-900 px-2 py-1 text-sm text-cream-100 focus:border-brand-400 focus:outline-none" />
            <span class="text-xs text-cream-300/60">ngày</span>
          </div>
        </div>
      </div>

      <!-- ══ Bộ lọc ══ -->
      <div class="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-ink-700 bg-ink-800 p-3">
        <div class="w-36">
          <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-cream-300">Loại</label>
          <select @change="onChangeType" class="w-full rounded-lg border border-ink-700 bg-ink-900 px-2 py-1.5 text-sm text-cream-100 focus:border-brand-400 focus:outline-none">
            <option v-for="t in types" :key="t.value" :value="t.value" :selected="store.libraryFilters.type === t.value">{{ t.label }}</option>
          </select>
        </div>
        <div class="w-44">
          <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-cream-300">Trạng thái</label>
          <select @change="onChangeStatus" class="w-full rounded-lg border border-ink-700 bg-ink-900 px-2 py-1.5 text-sm text-cream-100 focus:border-brand-400 focus:outline-none">
            <option v-for="s in statuses" :key="s.value" :value="s.value" :selected="store.libraryFilters.status === s.value">{{ s.label }}</option>
          </select>
        </div>
        <div class="w-44">
          <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-cream-300">Bộ sưu tập</label>
          <select @change="onChangeProject" class="w-full rounded-lg border border-ink-700 bg-ink-900 px-2 py-1.5 text-sm text-cream-100 focus:border-brand-400 focus:outline-none">
            <option value="" :selected="!store.libraryFilters.project_id">Tất cả bộ sưu tập</option>
            <option value="none" :selected="store.libraryFilters.project_id === 'none'">Chưa gắn bộ sưu tập</option>
            <option v-for="p in store.projects" :key="p.id" :value="p.id" :selected="String(store.libraryFilters.project_id) === String(p.id)">
              {{ p.color ? '● ' : '' }}{{ p.name }}
            </option>
          </select>
        </div>
        <!-- Nút áp dụng nhanh / chip đã áp dụng -->
        <div v-if="activeProjectId && activeProjectId !== 'none' && filteredProject" class="flex items-center">
          <button v-if="canApplyFilteredProject" @click="applyFilteredProject"
                  class="rounded-lg border border-brand-500 bg-brand-600/20 px-2.5 py-1.5 text-xs font-semibold text-brand-100 hover:bg-brand-500/30">
            <StudioIcon name="pin" size="h-3.5 w-3.5" /> Áp dụng bộ sưu tập này
          </button>
          <div v-else-if="store.appliedProject?.id === Number(activeProjectId)" class="flex items-center gap-1.5 rounded-full border border-brand-500/50 bg-brand-600/20 px-3 py-1 text-xs text-brand-100">
            <StudioIcon name="pin" size="h-3.5 w-3.5" />
            <span class="max-w-[120px] truncate" :title="store.appliedProject.name">{{ store.appliedProject.name }}</span>
            <button @click="store.unapplyProject()" aria-label="Ngắt bộ sưu tập hiện tại" class="ml-0.5 rounded-full p-0.5 text-brand-300 hover:bg-brand-500/30 hover:text-brand-100">
              <StudioIcon name="x" size="h-3 w-3" />
            </button>
          </div>
        </div>
        <div class="min-w-[200px] flex-1">
          <label class="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-cream-300">Tìm prompt / model</label>
          <input :value="store.libraryFilters.q" @input="onSearchInput" type="text" placeholder="Nhập từ khóa…"
                 class="w-full rounded-lg border border-ink-700 bg-ink-900 px-3 py-1.5 text-sm text-cream-100 placeholder:text-cream-300/40 focus:border-brand-400 focus:outline-none" />
        </div>
      </div>

      <!-- ══ Thanh quản lý ══ -->
      <div v-if="store.libraryManage" class="mb-4 space-y-2 rounded-lg border border-brand-600/40 bg-brand-900/30 p-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-sm font-semibold text-cream-100">Đã chọn <span class="text-brand-300">{{ selectedCount }}</span> mục</span>
          <button @click="toggleAll" class="rounded-lg border border-ink-600 bg-ink-800 px-2.5 py-1 text-xs text-cream-200 hover:bg-ink-700">{{ allSelected ? 'Bỏ chọn tất cả' : 'Chọn tất cả' }}</button>
          <button @click="store.librarySelectJunk" class="rounded-lg border border-amber-500/40 bg-amber-500/10 px-2.5 py-1 text-xs text-amber-200 hover:bg-amber-500/20">Chọn ảnh rác</button>
          <button @click="store.librarySelectOld" class="rounded-lg border border-sky-500/40 bg-sky-500/10 px-2.5 py-1 text-xs text-sky-200 hover:bg-sky-500/20">Chọn ảnh cũ ({{ store.libraryFilters.old_days }} ngày)</button>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-t border-ink-700/60 pt-2">
          <template v-if="confirmAction === ''">
            <button @click="ask('bulk')" :disabled="!selectedCount" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500 disabled:opacity-40"><StudioIcon name="trash" size="h-3.5 w-3.5" /> Xóa đã chọn ({{ selectedCount }})</button>
            <span class="text-cream-300/40">·</span>
            <select v-model="bulkAttachProjectId" class="rounded-lg border border-ink-700 bg-ink-900 px-2 py-1.5 text-xs text-cream-100 outline-none focus:border-brand-500">
              <option :value="null">— Gắn vào bộ sưu tập —</option>
              <option v-for="p in store.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <button @click="bulkAttach" :disabled="!selectedCount || !bulkAttachProjectId" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-500 disabled:opacity-40"><StudioIcon name="pin" size="h-3.5 w-3.5" /> Gắn vào bộ ({{ selectedCount }})</button>
            <button @click="ask('junk')" class="rounded-lg border border-amber-500/40 px-3 py-1.5 text-xs text-amber-200 hover:bg-amber-500/15" :disabled="store.libraryCleaning"><StudioIcon name="brush" size="h-3.5 w-3.5" /> Dọn ảnh rác ({{ fmtNum(stats.junk_count ?? 0) }})</button>
            <button @click="ask('old')" class="rounded-lg border border-sky-500/40 px-3 py-1.5 text-xs text-sky-200 hover:bg-sky-500/15" :disabled="store.libraryCleaning"><StudioIcon name="clock" size="h-3.5 w-3.5" /> Dọn ảnh cũ ({{ fmtNum(stats.old_count ?? 0) }})</button>
            <button @click="ask('orphans')" class="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs text-red-300 hover:bg-red-500/15" :disabled="store.libraryCleaning"><StudioIcon name="unlink" size="h-3.5 w-3.5" /> Dọn file mồ côi ({{ fmtNum(stats.orphan_count ?? 0) }})</button>
          </template>
          <template v-else>
            <p class="text-xs font-semibold text-red-200">
              <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" />
              <span v-if="confirmAction === 'bulk'">Xóa vĩnh viễn {{ selectedCount }} ảnh đã chọn?</span>
              <span v-else-if="confirmAction === 'junk'">Xóa toàn bộ ảnh rác (lỗi / đã hủy)?</span>
              <span v-else-if="confirmAction === 'old'">Xóa ảnh cũ hơn {{ store.libraryFilters.old_days }} ngày?</span>
              <span v-else>Xóa các file không còn được tham chiếu?</span>
            </p>
            <div class="flex gap-2">
              <button @click="cancelConfirm" class="rounded-lg border border-ink-600 bg-ink-800 px-3 py-1.5 text-xs text-cream-200 hover:bg-ink-700">Hủy</button>
              <button @click="runConfirm" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500">Xác nhận xóa</button>
            </div>
          </template>
        </div>
      </div>

      <!-- ══ Lưới ảnh ══ -->
      <div v-if="store.libraryLoading && !store.libraryItems.length" class="grid gap-3" :class="gridCls">
        <div v-for="i in 10" :key="i" class="aspect-[3/4] animate-pulse rounded-lg border-2 border-ink-700 bg-ink-800"></div>
      </div>

      <div v-else-if="!store.libraryItems.length" class="rounded-lg border border-ink-700 bg-ink-800 py-16 text-center">
        <p v-if="activeProjectId && activeProjectId !== 'none'" class="text-sm text-cream-300/50">Bộ sưu tập này chưa có ảnh/video nào.</p>
        <p v-else class="text-sm text-cream-300/50">Chưa có ảnh / video nào khớp bộ lọc.</p>
        <button type="button" @click="goBack" class="mt-2 inline-block text-xs text-brand-300 hover:text-brand-200">Tạo ảnh mới trong Studio →</button>
      </div>

      <div v-else-if="store.libraryView === 'grid'" class="grid gap-3" :class="gridCls">
        <div v-for="g in store.libraryItems" :key="g.id"
             class="group relative overflow-hidden rounded-lg border-2 transition"
             :class="isSelected(g.id) ? 'border-brand-400' : 'border-ink-700'">
          <div class="relative cursor-pointer" @click="openViewer(g)">
            <img v-if="g.media_url" :src="thumbUrl(g.media_url, 480)" class="aspect-[3/4] w-full bg-ink-900 object-cover" loading="lazy" @error="onThumbError($event, g.media_url)">
            <div v-else class="grid aspect-[3/4] w-full place-items-center bg-ink-800 text-xs text-cream-300/60">
              {{ g.status === 'failed' ? 'Lỗi' : (g.status === 'cancelled' ? 'Đã hủy' : (g.type === 'video' ? '▶ Video' : 'Không có ảnh')) }}
            </div>

            <!-- Badge trạng thái -->
            <span class="absolute left-2 top-2 rounded-full border px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wide"
                  :class="{
                    'border-emerald-500/40 bg-emerald-500/15 text-emerald-200': g.status === 'completed',
                    'border-red-500/40 bg-red-500/15 text-red-200': g.status === 'failed',
                    'border-ink-600 bg-ink-800 text-cream-300/70': g.status === 'cancelled',
                    'border-amber-500/40 bg-amber-500/15 text-amber-200': ['pending','processing'].includes(g.status),
                  }">
              {{ statusLabel(g.status) }}
            </span>
            <span v-if="g.type === 'video'" class="absolute right-2 top-2 grid h-7 w-7 place-items-center rounded-full bg-ink-900/80 text-white"><StudioIcon name="play" size="h-3.5 w-3.5" /></span>

            <!-- Overlay hover -->
            <div class="absolute inset-0 bg-black/40 opacity-0 transition group-hover:opacity-100"></div>
            <div class="absolute inset-x-0 bottom-0 p-2 text-[10px] text-cream-100 opacity-0 transition group-hover:opacity-100">
              {{ store.genName(g) }} <span v-if="g.created_at" class="text-cream-300/70">· {{ g.created_at }}</span>
              <span v-if="seedLabel(g)" class="ml-1 rounded bg-ink-900/80 px-1 py-0.5 text-[9px] text-brand-200">🎲 {{ seedLabel(g) }}</span>
            </div>
          </div>

          <!-- Badge dự án -->
          <div v-if="g.project" @click.stop="clickProjectChip(g)" class="absolute left-2 top-8 flex cursor-pointer items-center gap-1 rounded-full border border-ink-600/60 bg-ink-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-cream-300 hover:border-brand-500/60 hover:bg-brand-600/30 hover:text-brand-100 transition">
            <span class="inline-block h-1.5 w-1.5 rounded-full flex-shrink-0" :style="{ backgroundColor: getProjectColor(g) }"></span>
            <span class="max-w-[80px] truncate">{{ g.project }}</span>
          </div>

          <!-- Checkbox chọn (luôn hiện ở chế độ quản lý) -->
          <button v-if="store.libraryManage" @click.stop="store.toggleLibrarySelect(g.id)"
                  class="absolute right-2 bottom-2 grid h-7 w-7 place-items-center rounded-lg border text-sm"
                  :class="isSelected(g.id) ? 'border-brand-500 bg-brand-600 text-white' : 'border-cream-300/50 bg-ink-900/70 text-transparent hover:border-cream-200'">
            <StudioIcon name="check" size="h-3.5 w-3.5" />
          </button>
        </div>
      </div>

      <!-- ══ Danh sách (list view) ══ -->
      <div v-else class="space-y-2">
        <div v-for="g in store.libraryItems" :key="g.id"
             class="group relative flex items-center gap-3 overflow-hidden rounded-lg border-2 bg-ink-800/70 p-2 transition"
             :class="isSelected(g.id) ? 'border-brand-400' : 'border-ink-700 hover:border-brand-500/50'">
          <div class="h-16 w-12 shrink-0 cursor-pointer overflow-hidden rounded-md bg-ink-900" @click="openViewer(g)">
            <img v-if="g.media_url" :src="thumbUrl(g.media_url, 320)" class="h-full w-full object-cover" loading="lazy" @error="onThumbError($event, g.media_url)">
            <div v-else class="grid h-full w-full place-items-center text-[10px] text-cream-300/60">{{ g.status === 'failed' ? 'Lỗi' : (g.type === 'video' ? '▶' : '—') }}</div>
          </div>
          <div class="min-w-0 flex-1 cursor-pointer" @click="openViewer(g)">
            <div class="flex flex-wrap items-center gap-1.5">
              <span class="truncate text-xs font-semibold text-cream-100">{{ store.genName(g) }}</span>
              <span class="rounded-full border px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide"
                    :class="{
                      'border-emerald-500/40 bg-emerald-500/15 text-emerald-200': g.status === 'completed',
                      'border-red-500/40 bg-red-500/15 text-red-200': g.status === 'failed',
                      'border-ink-600 bg-ink-800 text-cream-300/70': g.status === 'cancelled',
                      'border-amber-500/40 bg-amber-500/15 text-amber-200': ['pending','processing'].includes(g.status),
                    }">{{ statusLabel(g.status) }}</span>
              <span v-if="g.type === 'video'" class="rounded-full bg-ink-700 px-1.5 py-0.5 text-[9px] text-cream-300/70">Video</span>
              <span v-if="seedLabel(g)" class="rounded-full bg-brand-600/20 px-1.5 py-0.5 text-[9px] text-brand-200">🎲 {{ seedLabel(g) }}</span>
            </div>
            <p class="mt-0.5 truncate text-[10px] text-cream-300/50">{{ g.model || '—' }} · {{ g.created_at }}</p>
          </div>
          <button v-if="store.libraryManage" @click.stop="store.toggleLibrarySelect(g.id)"
                  class="grid h-7 w-7 shrink-0 place-items-center rounded-lg border text-sm"
                  :class="isSelected(g.id) ? 'border-brand-500 bg-brand-600 text-white' : 'border-cream-300/50 bg-ink-900/70 text-transparent hover:border-cream-200'">
            <StudioIcon name="check" size="h-3.5 w-3.5" />
          </button>
        </div>
      </div>

      <!-- ══ Xem thêm ══ -->
      <div v-if="store.libraryHasMore" class="mt-6 flex justify-center">
        <button @click="store.loadMoreLibrary()" :disabled="store.libraryLoading"
                class="rounded-md border border-ink-600 bg-ink-800 px-5 py-2 text-sm font-semibold text-cream-200 hover:bg-ink-700 disabled:opacity-50">
          {{ store.libraryLoading ? 'Đang tải…' : 'Xem thêm' }}
        </button>
      </div>
      </template>

      <!-- ══ Tab: FILE TẢI LÊN ══ -->
      <template v-else-if="store.libraryTab === 'uploads'">
        <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
          <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
            <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Tổng file</p>
            <p class="text-lg font-semibold text-cream-100">{{ fmtNum(uploadStats.total ?? store.uploadItems.length) }}</p>
          </div>
          <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
            <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Dung lượng</p>
            <p class="text-lg font-semibold text-cream-100">{{ fmtBytes(uploadStats.total_bytes ?? 0) }}</p>
          </div>
          <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
            <p class="text-[10px] uppercase tracking-wide text-cream-300/50">File mồ côi (chưa dùng)</p>
            <p class="text-lg font-semibold text-red-300">{{ fmtNum(uploadUnusedCount) }}</p>
            <p v-if="uploadStats.unused_bytes" class="text-[10px] text-cream-300/50">{{ fmtBytes(uploadStats.unused_bytes) }}</p>
          </div>
          <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
            <p class="text-[10px] uppercase tracking-wide text-cream-300/50">Đang dùng</p>
            <p class="text-lg font-semibold text-emerald-300">{{ fmtNum((uploadStats.total ?? 0) - (uploadStats.unused_count ?? 0)) }}</p>
          </div>
        </div>

        <!-- Thanh quản lý uploads -->
        <div v-if="store.libraryManage" class="mb-4 space-y-2 rounded-lg border border-brand-600/40 bg-brand-900/30 p-3">
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-semibold text-cream-100">Đã chọn <span class="text-brand-300">{{ uploadSelectedCount }}</span> file</span>
            <button @click="store.uploadSelectUnused" class="rounded-lg border border-red-500/40 bg-red-500/10 px-2.5 py-1 text-xs text-red-200 hover:bg-red-500/20">Chọn file mồ côi</button>
            <button @click="store.uploadSelectNone" class="rounded-lg border border-ink-600 bg-ink-800 px-2.5 py-1 text-xs text-cream-200 hover:bg-ink-700">Bỏ chọn</button>
          </div>
          <div class="flex flex-wrap items-center gap-2 border-t border-ink-700/60 pt-2">
            <template v-if="confirmAction === ''">
              <button @click="ask('ubulk')" :disabled="!uploadSelectedCount" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500 disabled:opacity-40"><StudioIcon name="trash" size="h-3.5 w-3.5" /> Xóa đã chọn ({{ uploadSelectedCount }})</button>
              <button @click="ask('uclean')" :disabled="!uploadUnusedCount" class="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs text-red-300 hover:bg-red-500/15"><StudioIcon name="unlink" size="h-3.5 w-3.5" /> Dọn file mồ côi ({{ uploadUnusedCount }})</button>
            </template>
            <template v-else>
              <p class="text-xs font-semibold text-red-200">
                <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" />
                <span v-if="confirmAction === 'ubulk'">Xóa vĩnh viễn {{ uploadSelectedCount }} file đã chọn?</span>
                <span v-else>Dọn toàn bộ file đã tải lên không còn dùng?</span>
              </p>
              <div class="flex gap-2">
                <button @click="cancelConfirm" class="rounded-lg border border-ink-600 bg-ink-800 px-3 py-1.5 text-xs text-cream-200 hover:bg-ink-700">Hủy</button>
                <button @click="runConfirm" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500">Xác nhận xóa</button>
              </div>
            </template>
          </div>
        </div>

        <!-- Lưới file tải lên -->
        <div v-if="store.uploadLoading && !store.uploadItems.length" class="grid gap-3" :class="gridCls">
          <div v-for="i in 10" :key="i" class="aspect-square animate-pulse rounded-lg border-2 border-ink-700 bg-ink-800"></div>
        </div>
        <div v-else-if="!store.uploadItems.length" class="rounded-lg border border-ink-700 bg-ink-800 py-16 text-center">
          <p class="text-sm text-cream-300/50">Chưa có file nào được tải lên.</p>
        </div>
        <div v-else-if="store.libraryView === 'grid'" class="grid gap-3" :class="gridCls">
          <div v-for="f in sortedUploads" :key="f.rel"
               class="group relative overflow-hidden rounded-lg border-2 transition"
               :class="isUploadSelected(f.rel) ? 'border-brand-400' : (f.used ? 'border-ink-700' : 'border-red-500/40')">
            <div class="relative cursor-pointer">
              <img :src="thumbUrl(f.url, 480)" class="aspect-square w-full bg-ink-900 object-cover" loading="lazy" @error="onThumbError($event, f.url)">
              <span v-if="f.used" class="absolute left-2 top-2 rounded-full border border-emerald-500/40 bg-emerald-500/15 px-2 py-0.5 text-[9px] font-semibold text-emerald-200">đang dùng</span>
              <span v-else class="absolute left-2 top-2 rounded-full border border-red-500/40 bg-red-500/15 px-2 py-0.5 text-[9px] font-semibold text-red-200">chưa dùng</span>
              <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent px-2 pb-1.5 pt-6">
                <p class="truncate text-[10px] font-medium text-cream-100">{{ f.name }}</p>
                <p class="truncate text-[9px] text-cream-300/75">{{ f.width }}×{{ f.height }} · {{ fmtBytes(f.size) }} · {{ f.kind === 'asset' ? 'tài nguyên' : 'ảnh nguồn' }}</p>
              </div>
            </div>
            <div class="border-t border-ink-700/60 bg-ink-800/80 px-2 py-1.5">
              <select :value="f.project_id || ''" @change="onUploadProject(f, $event.target.value)"
                      class="w-full rounded-md border border-ink-700 bg-ink-900 px-1.5 py-1 text-[10px] text-cream-200 outline-none focus:border-brand-500"
                      :title="f.project_id ? 'Đang thuộc một bộ sưu tập — đổi hoặc bỏ gắn' : 'Gắn ảnh này vào một bộ sưu tập'">
                <option value="">— Chưa gắn bộ sưu tập —</option>
                <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
            </div>
            <button v-if="store.libraryManage && !f.used" @click.stop="store.toggleUploadSelect(f.rel)"
                    class="absolute right-2 top-2 grid h-7 w-7 place-items-center rounded-lg border text-sm"
                    :class="isUploadSelected(f.rel) ? 'border-brand-500 bg-brand-600 text-white' : 'border-cream-300/50 bg-ink-900/70 text-transparent hover:border-cream-200'">
              <StudioIcon name="check" size="h-3.5 w-3.5" />
            </button>
            <!-- Xóa nhanh (ngoài chế độ quản lý, chỉ file chưa dùng) -->
            <div v-else-if="!f.used" class="absolute right-2 top-2 flex gap-1 transition"
                 :class="confirmUploadDelete === f.rel ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'">
              <template v-if="confirmUploadDelete === f.rel">
                <button @click.stop="runUploadDelete" class="rounded-lg bg-red-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-red-500">Xóa</button>
                <button @click.stop="cancelUploadDelete" class="rounded-lg border border-ink-600 bg-ink-900/95 px-2 py-1 text-[10px] font-semibold text-cream-200 hover:bg-ink-700">Hủy</button>
              </template>
              <button v-else @click.stop="askUploadDelete(f)" class="grid h-7 w-7 place-items-center rounded-lg border border-ink-600 bg-ink-900/90 text-cream-200 hover:border-red-500 hover:bg-red-600/30 hover:text-red-200" title="Xóa file"><StudioIcon name="trash" size="h-3.5 w-3.5" /></button>
            </div>
          </div>
        </div>
      <!-- ══ Danh sách file tải lên (list view) ══ -->
      <div v-else class="space-y-2">
        <div v-for="f in sortedUploads" :key="f.rel"
             class="group relative flex items-center gap-3 overflow-hidden rounded-lg border-2 bg-ink-800/70 p-2 transition"
             :class="isUploadSelected(f.rel) ? 'border-brand-400' : (f.used ? 'border-ink-700' : 'border-red-500/40')">
          <div class="h-14 w-14 shrink-0 overflow-hidden rounded-md bg-ink-900">
            <img :src="thumbUrl(f.url, 320)" class="h-full w-full object-cover" loading="lazy" @error="onThumbError($event, f.url)">
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
              <p class="truncate text-xs font-medium text-cream-100">{{ f.name }}</p>
              <span v-if="f.used" class="shrink-0 rounded-full border border-emerald-500/40 bg-emerald-500/15 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-200">đang dùng</span>
              <span v-else class="shrink-0 rounded-full border border-red-500/40 bg-red-500/15 px-1.5 py-0.5 text-[9px] font-semibold text-red-200">chưa dùng</span>
            </div>
            <p class="truncate text-[10px] text-cream-300/50">{{ f.width }}×{{ f.height }} · {{ fmtBytes(f.size) }} · {{ f.kind === 'asset' ? 'tài nguyên' : 'ảnh nguồn' }}</p>
            <select :value="f.project_id || ''" @change="onUploadProject(f, $event.target.value)"
                    class="mt-1 w-full max-w-[220px] rounded-md border border-ink-700 bg-ink-900 px-1.5 py-0.5 text-[10px] text-cream-200 outline-none focus:border-brand-500"
                    :title="f.project_id ? 'Đang thuộc một bộ sưu tập — đổi hoặc bỏ gắn' : 'Gắn ảnh này vào một bộ sưu tập'">
              <option value="">— Chưa gắn bộ sưu tập —</option>
              <option v-for="p in projectOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>
          <button v-if="store.libraryManage && !f.used" @click.stop="store.toggleUploadSelect(f.rel)"
                  class="grid h-7 w-7 shrink-0 place-items-center rounded-lg border text-sm"
                  :class="isUploadSelected(f.rel) ? 'border-brand-500 bg-brand-600 text-white' : 'border-cream-300/50 bg-ink-900/70 text-transparent hover:border-cream-200'">
            <StudioIcon name="check" size="h-3.5 w-3.5" />
          </button>
          <!-- Xóa nhanh (ngoài chế độ quản lý, chỉ file chưa dùng) -->
          <div v-else-if="!f.used" class="flex shrink-0 gap-1 transition"
               :class="confirmUploadDelete === f.rel ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'">
            <template v-if="confirmUploadDelete === f.rel">
              <button @click.stop="runUploadDelete" class="rounded-lg bg-red-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-red-500">Xóa</button>
              <button @click.stop="cancelUploadDelete" class="rounded-lg border border-ink-600 bg-ink-800 px-2 py-1 text-[10px] font-semibold text-cream-200 hover:bg-ink-700">Hủy</button>
            </template>
            <button v-else @click.stop="askUploadDelete(f)" class="grid h-7 w-7 place-items-center rounded-lg border border-ink-600 bg-ink-900/90 text-cream-200 hover:border-red-500 hover:bg-red-600/30 hover:text-red-200" title="Xóa file"><StudioIcon name="trash" size="h-3.5 w-3.5" /></button>
          </div>
        </div>
      </div>
      </template>
      <!-- ══ Tab: 💡 PROMPT PHÂN TÍCH ══ -->
      <template v-else>
        <PromptLibraryTab />
      </template>
    </div>

    <GalleryModal v-if="store.viewer && !props.embedded" />
  </div>
</template>