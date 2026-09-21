<script setup>
import { ref, computed, onMounted } from 'vue';
import { useStudioStore } from '../store.js';
import { onThumbError } from '../composables/useStudioThumb.js';
import { gridClass } from '../libraryLayout.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

const showDetail = ref(null);      // id prompt đang xem chi tiết
const editorOpen = ref(false);     // modal thêm / sửa prompt
const editingId = ref(null);       // null = tạo mới; number = đang sửa
const editorBusy = ref(false);
const confirmDeleteId = ref(null); // xác nhận xóa đơn lẻ (2 bước)
let confirmTimer = null;

const stats = computed(() => store.suggestLibStats || {});
const selectedCount = computed(() => store.suggestLibSelection.length);
const allSelected = computed(() => store.suggestLibItems.length > 0 && store.suggestLibSelection.length === store.suggestLibItems.length);

function fmtNum(n) { const v = Number(n) || 0; return v >= 1000 ? v.toLocaleString('vi-VN') : String(v); }
function isSelected(id) { return store.suggestLibSelection.includes(id); }
function toggleAll() { allSelected.value ? store.suggestLibSelectNone() : store.suggestLibSelectAll(); }

// ── Lọc + tìm kiếm ──
let searchTimer = null;
function onSearchInput(e) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => store.setSuggestLibFilter('q', e.target.value || ''), 350);
}
function onGarmentInput(e) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => store.setSuggestLibFilter('garment_type', e.target.value || ''), 350);
}
function onProjectChange(e) { store.setSuggestLibFilter('project_id', e.target.value); }

// ── Bulk delete (2 bước) ──
const confirmBulk = ref(false);
function askBulkDelete() {
  if (!selectedCount.value) { store.toast('Chưa chọn prompt nào để xóa.', 'error'); return; }
  confirmBulk.value = true;
  clearTimeout(confirmTimer);
  confirmTimer = setTimeout(() => { confirmBulk.value = false; }, 5000);
}
function cancelBulk() { confirmBulk.value = false; clearTimeout(confirmTimer); }
async function runBulk() {
  confirmBulk.value = false;
  clearTimeout(confirmTimer);
  await store.suggestLibBulkDelete();
}

// ── CRUD form ──
function emptyForm() {
  return {
    garment_type: '', styles: '', background: '', pose: '', fabric: '', silhouette: '',
    camera: '', embellishment: '', detail_notes: '', color_palette: '',
    image_prompt_en: '', prompt_vi: '', negative_prompt: '', video_prompt_en: '', keywords: '',
    creative_level: 6, adherence: 8, detail_level: 8, project_id: '', reference_url: '',
  };
}
const form = ref(emptyForm());
const arrToText = (a) => (Array.isArray(a) ? a.filter(Boolean).join(', ') : '');
const textToArr = (v) => String(v || '').split(',').map(s => s.trim()).filter(Boolean);

function openCreate() {
  if (!store.projects.length && !store.projectLoaded) store.loadProjects();
  form.value = emptyForm();
  editingId.value = null;
  editorOpen.value = true;
}
function openEdit(item) {
  if (!store.projects.length && !store.projectLoaded) store.loadProjects();
  form.value = {
    garment_type: item.garment_type || '',
    styles: arrToText(item.styles),
    background: item.background || '',
    pose: item.pose || '',
    fabric: item.fabric || '',
    silhouette: item.silhouette || '',
    camera: item.camera || '',
    embellishment: item.embellishment || '',
    detail_notes: item.detail_notes || '',
    color_palette: arrToText(item.color_palette),
    image_prompt_en: item.image_prompt_en || '',
    prompt_vi: item.prompt_vi || '',
    negative_prompt: item.negative_prompt || '',
    video_prompt_en: item.video_prompt_en || '',
    keywords: arrToText(item.keywords),
    creative_level: Number(item.creative_level) || 6,
    adherence: Number(item.adherence) || 8,
    detail_level: Number(item.detail_level) || 8,
    project_id: item.project_id || '',
    reference_url: item.reference_url || '',
  };
  editingId.value = item.id;
  editorOpen.value = true;
  showDetail.value = null;
}
function closeEditor() { editorOpen.value = false; editingId.value = null; }

async function submitEditor() {
  if (editorBusy.value) return;
  if (!form.value.image_prompt_en?.trim() && !form.value.prompt_vi?.trim() && !form.value.garment_type?.trim()) {
    store.toast('Nhập ít nhất prompt tiếng Anh, tiếng Việt hoặc loại trang phục.', 'error');
    return;
  }
  editorBusy.value = true;
  const payload = {
    garment_type: form.value.garment_type?.trim() || null,
    styles: textToArr(form.value.styles),
    background: form.value.background?.trim() || null,
    pose: form.value.pose?.trim() || null,
    fabric: form.value.fabric?.trim() || null,
    silhouette: form.value.silhouette?.trim() || null,
    camera: form.value.camera?.trim() || null,
    embellishment: form.value.embellishment?.trim() || null,
    detail_notes: form.value.detail_notes?.trim() || null,
    color_palette: textToArr(form.value.color_palette),
    image_prompt_en: form.value.image_prompt_en?.trim() || null,
    prompt_vi: form.value.prompt_vi?.trim() || null,
    negative_prompt: form.value.negative_prompt?.trim() || null,
    video_prompt_en: form.value.video_prompt_en?.trim() || null,
    keywords: textToArr(form.value.keywords),
    creative_level: Number(form.value.creative_level) || 6,
    adherence: Number(form.value.adherence) || 8,
    detail_level: Number(form.value.detail_level) || 8,
    project_id: form.value.project_id ? Number(form.value.project_id) : null,
    reference_url: form.value.reference_url?.trim() || '',
  };
  try {
    const res = editingId.value
      ? await store.updatePrompt(editingId.value, payload)
      : await store.savePrompt(payload);
    if (res !== null) closeEditor();
  } finally { editorBusy.value = false; }
}

// ── Xóa đơn lẻ (2 bước) ──
function askDeleteSingle(item) { confirmDeleteId.value = item.id; clearTimeout(confirmTimer); confirmTimer = setTimeout(() => { confirmDeleteId.value = null; }, 5000); }
function cancelDeleteSingle() { confirmDeleteId.value = null; clearTimeout(confirmTimer); }
async function runDeleteSingle() {
  const id = confirmDeleteId.value;
  confirmDeleteId.value = null;
  clearTimeout(confirmTimer);
  showDetail.value = null;
  await store.deletePrompt(id);
}

function openDetail(item) { showDetail.value = item.id; }
function closeDetail() { showDetail.value = null; }
function applyPrompt(item) { closeDetail(); store.applySuggestPrompt(item); }

onMounted(() => { if (!store.suggestLibItems.length) store.loadSuggestLib(); });
</script>

<template>
  <div>
    <!-- ══ Thống kê ══ -->
    <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
      <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
        <p class="text-label uppercase tracking-wide text-cream-400">Tổng prompt</p>
        <p class="text-lg font-semibold text-cream-100">{{ fmtNum(stats.total ?? store.suggestLibTotal) }}</p>
      </div>
      <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
        <p class="text-label uppercase tracking-wide text-cream-400">Đã dùng</p>
        <p class="text-lg font-semibold text-ok">{{ fmtNum(stats.applied ?? 0) }}</p>
      </div>
      <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
        <p class="text-label uppercase tracking-wide text-cream-400">Chưa dùng</p>
        <p class="text-lg font-semibold text-warn">{{ fmtNum(stats.unused ?? 0) }}</p>
      </div>
      <div class="rounded-lg border border-ink-700 bg-ink-800 p-3">
        <p class="text-label uppercase tracking-wide text-cream-400">Có ảnh nguồn</p>
        <p class="text-lg font-semibold text-brand-300">{{ fmtNum(stats.with_image ?? 0) }}</p>
      </div>
    </div>

    <!-- ══ Bộ lọc ══ -->
    <div class="mb-4 flex flex-wrap items-end gap-2 rounded-lg border border-ink-700 bg-ink-800 p-3">
      <div class="w-40">
        <label class="mb-1 block text-label font-semibold uppercase tracking-wide text-cream-300">Loại trang phục</label>
        <input :value="store.suggestLibFilters.garment_type" @input="onGarmentInput" type="text" placeholder="Váy, áo…"
               class="w-full rounded-lg border border-ink-700 bg-ink-900 px-2 py-1.5 text-sm text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none" />
      </div>
      <div class="w-44">
        <label class="mb-1 block text-label font-semibold uppercase tracking-wide text-cream-300">Dự án</label>
        <select @change="onProjectChange" class="w-full rounded-lg border border-ink-700 bg-ink-900 px-2 py-1.5 text-sm text-cream-100 focus:border-brand-400 focus:outline-none">
          <option value="" :selected="!store.suggestLibFilters.project_id">Tất cả dự án</option>
          <option value="none" :selected="store.suggestLibFilters.project_id === 'none'">Chưa gắn dự án</option>
          <option v-for="p in store.projects" :key="p.id" :value="p.id" :selected="String(store.suggestLibFilters.project_id) === String(p.id)">
            {{ p.color ? '● ' : '' }}{{ p.name }}
          </option>
        </select>
      </div>
      <div class="min-w-[220px] flex-1">
        <label class="mb-1 block text-label font-semibold uppercase tracking-wide text-cream-300">Tìm prompt</label>
        <input :value="store.suggestLibFilters.q" @input="onSearchInput" type="text" placeholder="Tìm prompt / từ khóa…"
               class="w-full rounded-lg border border-ink-700 bg-ink-900 px-3 py-1.5 text-sm text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none" />
      </div>
      <button @click="openCreate" class="tool-btn !py-1.5">
        <StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm prompt
      </button>
    </div>

    <!-- ══ Thanh quản lý ══ -->
    <div v-if="store.suggestLibManage" class="mb-4 space-y-2 rounded-lg border border-brand-600/40 bg-brand-900/30 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-semibold text-cream-100">Đã chọn <span class="text-brand-300">{{ selectedCount }}</span> prompt</span>
        <button @click="toggleAll" class="rounded-lg border border-ink-600 bg-ink-800 px-2.5 py-1 text-xs text-cream-200 hover:bg-ink-700">{{ allSelected ? 'Bỏ chọn tất cả' : 'Chọn tất cả' }}</button>
        <button @click="store.suggestLibSelectNone" class="rounded-lg border border-ink-600 bg-ink-800 px-2.5 py-1 text-xs text-cream-200 hover:bg-ink-700">Bỏ chọn</button>
      </div>
      <div class="flex flex-wrap items-center gap-2 border-t border-ink-700/60 pt-2">
        <template v-if="!confirmBulk">
          <button @click="askBulkDelete" :disabled="!selectedCount" class="rounded-lg bg-danger px-3 py-1.5 text-xs font-semibold text-danger-content hover:bg-danger disabled:opacity-40"><StudioIcon name="trash" size="h-3.5 w-3.5" /> Xóa đã chọn ({{ selectedCount }})</button>
        </template>
        <template v-else>
          <p class="text-xs font-semibold text-danger"><StudioIcon name="alertTriangle" size="h-3.5 w-3.5" /> Xóa vĩnh viễn {{ selectedCount }} prompt đã chọn?</p>
          <button @click="runBulk" class="rounded-lg bg-danger px-3 py-1.5 text-xs font-semibold text-danger-content hover:bg-danger">Xác nhận xóa</button>
          <button @click="cancelBulk" class="rounded-lg border border-ink-600 bg-ink-800 px-3 py-1.5 text-xs text-cream-200 hover:bg-ink-700">Hủy</button>
        </template>
      </div>
    </div>

    <!-- ══ Loading / empty ══ -->
    <div v-if="store.suggestLibLoading && !store.suggestLibItems.length" class="grid gap-3" :class="gridClass(store.libraryGrid)">
      <div v-for="i in 10" :key="i" class="aspect-square animate-pulse rounded-lg border-2 border-ink-700 bg-ink-800"></div>
    </div>
    <div v-else-if="!store.suggestLibItems.length" class="rounded-lg border border-ink-700 bg-ink-800 py-16 text-center">
      <p class="text-sm text-cream-400">Chưa có prompt nào được lưu.</p>
      <button @click="openCreate" class="mt-2 inline-flex items-center gap-1 text-xs text-brand-300 hover:text-brand-200"><StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm prompt đầu tiên</button>
    </div>

    <!-- ══ Grid view ══ -->
    <div v-else-if="store.libraryView !== 'list'" class="grid gap-3" :class="gridClass(store.libraryGrid)">
      <div v-for="item in store.suggestLibItems" :key="item.id"
           class="group relative overflow-hidden rounded-lg border-2 bg-ink-800/70 transition"
           :class="isSelected(item.id) ? 'border-brand-400' : 'border-ink-700 hover:border-brand-500/50'">
        <!-- Thumbnail -->
        <div class="relative cursor-pointer" @click="store.suggestLibManage ? store.toggleSuggestLibSelect(item.id) : openDetail(item)">
          <div class="aspect-square overflow-hidden bg-ink-900">
            <img v-if="item.reference_thumb || item.reference_url"
                 :src="item.reference_thumb || item.reference_url"
                 class="h-full w-full object-cover transition group-hover:scale-105" loading="lazy"
                 @error="onThumbError($event, item.reference_thumb || item.reference_url)" />
            <div v-else class="grid h-full w-full place-items-center text-cream-400"><StudioIcon name="lightbulb" size="h-9 w-9" /></div>
          </div>
          <!-- Badge loại + dùng -->
          <span v-if="item.garment_type" class="absolute left-2 top-2 max-w-[70%] truncate rounded-full border border-brand-500/40 bg-brand-600/20 px-2 py-0.5 text-tiny font-semibold text-brand-200">{{ item.garment_type }}</span>
          <span v-if="item.apply_count" class="absolute right-2 top-2 rounded-full bg-ok/25 px-1.5 py-0.5 text-tiny font-semibold text-ok">✓{{ item.apply_count }}x</span>
          <!-- Overlay hover -->
          <div class="absolute inset-0 bg-scrim/40 opacity-0 transition group-hover:opacity-100"></div>
        </div>
        <!-- Nội dung -->
        <div class="p-2">
          <div class="flex flex-wrap gap-1">
            <span v-for="s in (item.styles || []).slice(0, 2)" :key="s" class="truncate rounded-full bg-ink-700/80 px-1.5 py-0.5 text-tiny text-cream-300">{{ s }}</span>
          </div>
          <p class="mt-1 line-clamp-2 text-label leading-relaxed text-cream-400">{{ item.image_prompt_en || item.prompt_vi || '—' }}</p>
          <div class="mt-1.5 flex items-center justify-between text-tiny text-cream-400">
            <span>{{ item.created_at }}</span>
          </div>
        </div>
        <!-- Checkbox chọn (chế độ quản lý) -->
        <button v-if="store.suggestLibManage" @click.stop="store.toggleSuggestLibSelect(item.id)"
                class="absolute bottom-2 right-2 grid h-7 w-7 place-items-center rounded-lg border text-sm"
                :class="isSelected(item.id) ? 'border-brand-500 bg-brand-600 text-primary-content' : 'border-cream-300/50 bg-ink-800 text-transparent hover:border-cream-200'">
          <StudioIcon name="check" size="h-3.5 w-3.5" />
        </button>
        <!-- Hành động nhanh (ngoài chế độ quản lý) -->
        <div v-else class="absolute right-1.5 top-1.5 flex gap-1 transition"
             :class="confirmDeleteId === item.id ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'">
          <template v-if="confirmDeleteId === item.id">
            <button @click.stop="runDeleteSingle" class="rounded-lg bg-danger px-2 py-1 text-label font-semibold text-danger-content hover:bg-danger">Xóa</button>
            <button @click.stop="cancelDeleteSingle" class="rounded-lg border border-ink-600 bg-ink-800 px-2 py-1 text-label font-semibold text-cream-200 hover:bg-ink-700">Hủy</button>
          </template>
          <template v-else>
            <button @click.stop="openEdit(item)" class="grid h-7 w-7 place-items-center rounded-lg border border-ink-600 bg-ink-800 text-cream-200 hover:border-brand-400 hover:bg-brand-600/30 hover:text-brand-200" title="Sửa prompt"><StudioIcon name="pencil" size="h-3.5 w-3.5" /></button>
            <button @click.stop="askDeleteSingle(item)" class="grid h-7 w-7 place-items-center rounded-lg border border-ink-600 bg-ink-800 text-cream-200 hover:border-danger hover:bg-danger/30 hover:text-danger" title="Xóa prompt"><StudioIcon name="trash" size="h-3.5 w-3.5" /></button>
          </template>
        </div>
      </div>
    </div>

    <!-- ══ List view ══ -->
    <div v-else class="space-y-2">
      <div v-for="item in store.suggestLibItems" :key="item.id"
           class="group relative flex items-center gap-3 overflow-hidden rounded-lg border-2 bg-ink-800/70 p-2 transition"
           :class="isSelected(item.id) ? 'border-brand-400' : 'border-ink-700 hover:border-brand-500/50'">
        <button v-if="store.suggestLibManage" @click.stop="store.toggleSuggestLibSelect(item.id)"
                class="grid h-7 w-7 shrink-0 place-items-center rounded-lg border text-sm"
                :class="isSelected(item.id) ? 'border-brand-500 bg-brand-600 text-primary-content' : 'border-cream-300/50 bg-ink-800 text-transparent hover:border-cream-200'">
          <StudioIcon name="check" size="h-3.5 w-3.5" />
        </button>
        <div class="h-16 w-16 shrink-0 cursor-pointer overflow-hidden rounded-md bg-ink-900" @click="store.suggestLibManage ? store.toggleSuggestLibSelect(item.id) : openDetail(item)">
          <img v-if="item.reference_thumb || item.reference_url" :src="item.reference_thumb || item.reference_url" class="h-full w-full object-cover" loading="lazy" @error="onThumbError($event, item.reference_thumb || item.reference_url)" />
          <div v-else class="grid h-full w-full place-items-center text-cream-400"><StudioIcon name="lightbulb" size="h-6 w-6" /></div>
        </div>
        <div class="min-w-0 flex-1 cursor-pointer" @click="store.suggestLibManage ? store.toggleSuggestLibSelect(item.id) : openDetail(item)">
          <div class="flex flex-wrap items-center gap-1.5">
            <span v-if="item.garment_type" class="truncate rounded-full bg-brand-600/20 px-1.5 py-0.5 text-tiny font-semibold text-brand-200">{{ item.garment_type }}</span>
            <span v-for="s in (item.styles || []).slice(0, 3)" :key="s" class="truncate rounded-full bg-ink-700 px-1.5 py-0.5 text-tiny text-cream-400">{{ s }}</span>
          </div>
          <p class="mt-0.5 truncate text-xs text-cream-100">{{ item.image_prompt_en || item.prompt_vi || '—' }}</p>
          <p class="truncate text-label text-cream-400">{{ item.created_at }}<span v-if="item.apply_count"> · Đã dùng {{ item.apply_count }}x</span></p>
        </div>
        <div v-if="!store.suggestLibManage" class="flex shrink-0 gap-1 transition"
             :class="confirmDeleteId === item.id ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'">
          <template v-if="confirmDeleteId === item.id">
            <button @click.stop="runDeleteSingle" class="rounded-lg bg-danger px-2 py-1 text-label font-semibold text-danger-content hover:bg-danger">Xóa</button>
            <button @click.stop="cancelDeleteSingle" class="rounded-lg border border-ink-600 bg-ink-800 px-2 py-1 text-label font-semibold text-cream-200 hover:bg-ink-700">Hủy</button>
          </template>
          <template v-else>
            <button @click.stop="openEdit(item)" class="grid h-7 w-7 place-items-center rounded-lg border border-ink-600 bg-ink-800 text-cream-200 hover:border-brand-400 hover:bg-brand-600/30 hover:text-brand-200" title="Sửa prompt"><StudioIcon name="pencil" size="h-3.5 w-3.5" /></button>
            <button @click.stop="askDeleteSingle(item)" class="grid h-7 w-7 place-items-center rounded-lg border border-ink-600 bg-ink-800 text-cream-200 hover:border-danger hover:bg-danger/30 hover:text-danger" title="Xóa prompt"><StudioIcon name="trash" size="h-3.5 w-3.5" /></button>
          </template>
        </div>
      </div>
    </div>

    <!-- ══ Xem thêm ══ -->
    <div v-if="store.suggestLibHasMore" class="mt-6 flex justify-center">
      <button @click="store.suggestLibNextPage()" :disabled="store.suggestLibLoading"
              class="rounded-md border border-ink-600 bg-ink-800 px-5 py-2 text-sm font-semibold text-cream-200 hover:bg-ink-700 disabled:opacity-50">
        {{ store.suggestLibLoading ? 'Đang tải…' : 'Xem thêm' }}
      </button>
    </div>

    <!-- ══ Modal chi tiết ══ -->
    <div v-if="showDetail" role="dialog" aria-modal="true" aria-label="Chi tiết prompt" class="fixed inset-0 z-[90] flex items-center justify-center bg-scrim/70 p-4" @click.self="closeDetail">
      <div class="w-full max-w-lg max-h-[85vh] overflow-y-auto rounded-lg border border-brand-500/40 bg-ink-900 shadow-2xl" @click.stop>
        <template v-for="item in store.suggestLibItems.filter(x => x.id === showDetail)" :key="item.id">
          <div class="flex items-center justify-between border-b border-ink-700 px-4 py-3">
            <h3 class="text-sm font-semibold text-brand-300">Chi tiết Prompt</h3>
            <button @click="closeDetail" class="grid h-7 w-7 place-items-center rounded-full bg-ink-700 text-cream-200 hover:bg-danger" title="Đóng"><StudioIcon name="x" size="h-3.5 w-3.5" /></button>
          </div>
          <div class="space-y-3 p-4 text-xs">
            <div v-if="item.reference_url" class="flex items-center gap-3 rounded-lg border border-ink-700 bg-cream-50/5 p-2">
              <img :src="item.reference_thumb || item.reference_url" class="h-16 w-16 rounded-md bg-ink-900 object-cover" @error="onThumbError" />
              <span class="text-cream-400">Ảnh nguồn phân tích</span>
            </div>

            <div class="flex flex-wrap gap-1.5">
              <span v-for="s in (item.styles || [])" :key="s" class="rounded-full bg-brand-600/20 px-2 py-0.5 text-label text-brand-200">{{ s }}</span>
              <span v-if="item.garment_type" class="rounded-full bg-ink-700 px-2 py-0.5 text-label text-cream-200"><StudioIcon name="shirt" size="h-3 w-3" /> {{ item.garment_type }}</span>
              <span v-if="item.embellishment" class="rounded-full bg-ink-700 px-2 py-0.5 text-label text-cream-200"><StudioIcon name="sparkles" size="h-3 w-3" /> {{ item.embellishment }}</span>
            </div>

            <div class="space-y-1.5 text-cream-200">
              <p v-if="item.background"><span class="text-cream-400">Bối cảnh:</span> {{ item.background }}</p>
              <p v-if="item.fabric"><span class="text-cream-400">Chất liệu:</span> {{ item.fabric }}</p>
              <p v-if="item.silhouette"><span class="text-cream-400">Dáng:</span> {{ item.silhouette }}</p>
              <p v-if="item.camera"><span class="text-cream-400">Góc máy:</span> {{ item.camera }}</p>
              <p v-if="item.pose"><span class="text-cream-400">Tư thế:</span> {{ item.pose }}</p>
            </div>

            <div v-if="(item.color_palette || []).length">
              <span class="text-cream-400">Bảng màu:</span>
              <div class="mt-1 flex flex-wrap gap-1">
                <span v-for="c in item.color_palette" :key="c" class="rounded-full bg-ink-700/70 px-2 py-0.5 text-label text-cream-200">{{ c }}</span>
              </div>
            </div>

            <div v-if="item.detail_notes" class="rounded-md border border-ink-700 bg-cream-50/5 p-2 leading-relaxed text-cream-100">
              <span class="text-cream-400">Chi tiết gốc:</span> {{ item.detail_notes }}
            </div>

            <div>
              <p class="mb-1 font-semibold text-cream-400">Prompt tiếng Anh</p>
              <div class="max-h-32 overflow-y-auto rounded-md border border-ink-700 bg-cream-50/5 p-2 leading-relaxed text-cream-100">{{ item.image_prompt_en || '—' }}</div>
            </div>
            <div v-if="item.prompt_vi">
              <p class="mb-1 font-semibold text-cream-400">Prompt tiếng Việt</p>
              <div class="max-h-32 overflow-y-auto rounded-md border border-ink-700 bg-cream-50/5 p-2 leading-relaxed text-cream-100">{{ item.prompt_vi }}</div>
            </div>
            <div v-if="item.negative_prompt">
              <p class="mb-1 font-semibold text-cream-400">Negative prompt</p>
              <div class="max-h-24 overflow-y-auto rounded-md border border-ink-700 bg-cream-50/5 p-2 leading-relaxed text-cream-100">{{ item.negative_prompt }}</div>
            </div>
            <div v-if="(item.keywords || []).length" class="flex flex-wrap gap-1">
              <span v-for="k in item.keywords" :key="k" class="rounded-full bg-ok/25 px-2 py-0.5 text-label text-ok">#{{ k }}</span>
            </div>

            <div class="flex items-center gap-3 text-label text-cream-400">
              <span>Lưu: {{ item.created_at }}</span>
              <span v-if="item.applied_at">Áp dụng: {{ item.applied_at }}</span>
              <span v-if="item.apply_count" class="text-brand-300/70">Dùng {{ item.apply_count }} lần</span>
            </div>

            <div class="flex gap-2 pt-2">
              <button @click="closeDetail(); store.applySuggestPrompt(item)" class="btn-brand flex-1 text-xs">Áp dụng → Tạo Ảnh</button>
              <button @click="openEdit(item)" class="btn-outline text-xs">Sửa</button>
              <template v-if="confirmDeleteId === item.id">
                <button @click="runDeleteSingle" class="rounded-lg bg-danger px-3 py-1.5 text-xs font-semibold text-danger-content">Xóa?</button>
                <button @click="cancelDeleteSingle" class="rounded-lg border border-ink-600 bg-ink-800 px-3 py-1.5 text-xs text-cream-200">Hủy</button>
              </template>
              <button v-else @click="askDeleteSingle(item)" class="rounded-lg border border-danger/40 px-3 py-1.5 text-xs text-danger hover:bg-danger/10">Xóa</button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- ══ Modal thêm / sửa prompt (CRUD) ══ -->
    <div v-if="editorOpen" role="dialog" aria-modal="true" aria-label="Sửa prompt" class="fixed inset-0 z-[90] flex items-center justify-center bg-scrim/70 p-4" @click.self="closeEditor">
      <div class="w-full max-w-2xl max-h-[88vh] overflow-y-auto rounded-lg border border-brand-500/40 bg-ink-900 shadow-2xl" @click.stop>
        <div class="flex items-center justify-between border-b border-ink-700 px-4 py-3">
          <h3 class="text-sm font-semibold text-brand-300">{{ editingId ? 'Sửa Prompt' : 'Thêm Prompt' }}</h3>
          <button @click="closeEditor" class="grid h-7 w-7 place-items-center rounded-full bg-ink-700 text-cream-200 hover:bg-danger" title="Đóng"><StudioIcon name="x" size="h-3.5 w-3.5" /></button>
        </div>
        <div class="space-y-3 p-4 text-xs">
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Loại trang phục</label>
              <input v-model="form.garment_type" type="text" placeholder="Ví dụ: Váy dạ hội" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Dự án</label>
              <select v-model="form.project_id" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none">
                <option value="">Không gắn dự án</option>
                <option v-for="p in store.projects" :key="p.id" :value="String(p.id)">{{ p.color ? '● ' : '' }}{{ p.name }}</option>
              </select>
            </div>
          </div>

          <div>
            <label class="mb-1 block font-semibold text-cream-400">Prompt tiếng Anh <span class="text-cream-400">(bắt buộc nếu chưa nhập mục khác)</span></label>
            <textarea v-model="form.image_prompt_en" rows="4" placeholder="Nhập prompt tiếng Anh…" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 leading-relaxed text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none"></textarea>
          </div>
          <div>
            <label class="mb-1 block font-semibold text-cream-400">Prompt tiếng Việt</label>
            <textarea v-model="form.prompt_vi" rows="3" placeholder="Mô tả tiếng Việt…" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 leading-relaxed text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none"></textarea>
          </div>
          <div>
            <label class="mb-1 block font-semibold text-cream-400">Negative prompt</label>
            <textarea v-model="form.negative_prompt" rows="2" placeholder="Những gì cần tránh…" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 leading-relaxed text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none"></textarea>
          </div>

          <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Phong cách <span class="text-cream-400">(cách nhau bởi ,)</span></label>
              <input v-model="form.styles" type="text" placeholder="Minimalist, Korean" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Từ khóa <span class="text-cream-400">(,)</span></label>
              <input v-model="form.keywords" type="text" placeholder="fashion, studio" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Bảng màu <span class="text-cream-400">(,)</span></label>
              <input v-model="form.color_palette" type="text" placeholder="ivory, navy, gold" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Bối cảnh</label>
              <input v-model="form.background" type="text" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Chất liệu</label>
              <input v-model="form.fabric" type="text" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Dáng</label>
              <input v-model="form.silhouette" type="text" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Góc máy</label>
              <input v-model="form.camera" type="text" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Tư thế</label>
              <input v-model="form.pose" type="text" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Trang trí</label>
              <input v-model="form.embellishment" type="text" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
          </div>

          <div>
            <label class="mb-1 block font-semibold text-cream-400">Ghi chú chi tiết</label>
            <textarea v-model="form.detail_notes" rows="2" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 leading-relaxed text-cream-100 focus:border-brand-400 focus:outline-none"></textarea>
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Mức sáng tạo (1-10)</label>
              <input v-model.number="form.creative_level" type="number" min="1" max="10" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Bám ảnh gốc (0-10)</label>
              <input v-model.number="form.adherence" type="number" min="0" max="10" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
            <div>
              <label class="mb-1 block font-semibold text-cream-400">Mức chi tiết (1-10)</label>
              <input v-model.number="form.detail_level" type="number" min="1" max="10" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 focus:border-brand-400 focus:outline-none" />
            </div>
          </div>

          <div>
            <label class="mb-1 block font-semibold text-cream-400">URL ảnh nguồn <span class="text-cream-400">(tùy chọn)</span></label>
            <input v-model="form.reference_url" type="text" placeholder="/storage/api/ref/…" class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-cream-100 placeholder:text-cream-400 focus:border-brand-400 focus:outline-none" />
          </div>

          <div class="flex justify-end gap-2 border-t border-ink-700 pt-3">
            <button @click="closeEditor" class="rounded-lg border border-ink-600 bg-ink-800 px-4 py-2 text-xs font-semibold text-cream-200 hover:bg-ink-700">Hủy</button>
            <button @click="submitEditor" :disabled="editorBusy" class="btn-brand text-xs disabled:opacity-50">{{ editorBusy ? 'Đang lưu…' : (editingId ? 'Lưu thay đổi' : 'Thêm prompt') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
