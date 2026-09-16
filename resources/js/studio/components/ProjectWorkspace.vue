<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

const open = defineModel({ type: Boolean, default: false });

const creating = ref(false);
const editing = ref(false);
const busy = ref(false);
const confirmDelete = ref('');
let confirmTimer = null;
const movingId = ref(null);   // id dự án đang chuyển trạng thái — chống double-click
const openingId = ref(null);  // id dự án đang tải chi tiết — spinner trên card/row
const transNote = ref('');    // ghi chú (tùy chọn) kèm lần chuyển trạng thái
const detachId = ref(null);   // id generation đang gỡ khỏi dự án (chống double-click)

// Form state
const form = ref(blankForm());
function blankForm() {
  return { name: '', base_concept: '', brief: '', deadline: '', tags: [], color: '', thumbnail_url: '' };
}
const tagsText = ref('');

const projectId = computed(() => store.activeProject?.id || null);
const statuses = computed(() => store.projectStatuses || {});
const statusOrder = ['draft', 'in_progress', 'review', 'approved', 'archived'];

const grouped = computed(() => {
  const map = {};
  for (const s of statusOrder) map[s] = [];
  for (const p of store.projects) {
    const key = map[p.status] ? p.status : 'draft';
    map[key].push(p);
  }
  return map;
});

// Empty state riêng theo scope (hàng đợi duyệt / lưu trữ) — scope own giữ khung kanban.
const boardEmptyMessage = computed(() => {
  if (store.projects.length) return '';
  if (store.projectScope === 'pending') return 'Không có dự án chờ duyệt 🎉';
  if (store.projectsArchived) return 'Không có dự án lưu trữ.';
  return '';
});
const listEmptyMessage = computed(() => {
  if (store.projectScope === 'pending') return 'Không có dự án chờ duyệt 🎉';
  if (store.projectsArchived) return 'Không có dự án lưu trữ.';
  return 'Chưa có dự án. Bấm "＋ Dự án mới" để bắt đầu.';
});

function statusLabel(s) { return statuses.value[s]?.label || s; }
function statusColor(s) { return statuses.value[s]?.color || '#6b6657'; }

function deadlineLabel(d) {
  if (!d) return '';
  const dt = new Date(d);
  if (isNaN(dt.getTime())) return '';
  return dt.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
}
function isOverdue(p) {
  if (!p.deadline) return false;
  const dt = new Date(p.deadline);
  return !isNaN(dt.getTime()) && dt.getTime() < Date.now() && !['approved', 'archived'].includes(p.status);
}
// Số ngày còn lại / quá hạn (tính theo ngày lịch, bỏ giờ).
function deadlineDays(d) {
  if (!d) return null;
  const dt = new Date(d);
  if (isNaN(dt.getTime())) return null;
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const target = new Date(dt.getTime()); target.setHours(0, 0, 0, 0);
  return Math.round((target.getTime() - today.getTime()) / 86400000);
}
function deadlineCountdown(p) {
  const n = deadlineDays(p && p.deadline);
  if (n === null) return '';
  if (n < 0) return ['approved', 'archived'].includes(p.status) ? '' : 'Quá hạn ' + Math.abs(n) + ' ngày';
  if (n === 0) return 'Hôm nay đến hạn';
  return 'Còn ' + n + ' ngày';
}
function deadlineFull(d) {
  if (!d) return '';
  const dt = new Date(d);
  if (isNaN(dt.getTime())) return '';
  return dt.toLocaleDateString('vi-VN', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
}

async function openCreate() {
  form.value = blankForm();
  tagsText.value = '';
  creating.value = true;
  editing.value = false;
}
function openEdit(p) {
  form.value = {
    name: p.name || '',
    base_concept: p.base_concept || '',
    brief: p.brief || '',
    deadline: p.deadline ? p.deadline.slice(0, 10) : '',
    tags: Array.isArray(p.tags) ? [...p.tags] : [],
    color: p.color || '',
    thumbnail_url: p.thumbnail_url || '',
  };
  tagsText.value = form.value.tags.join(', ');
  editing.value = true;
  creating.value = false;
}
function closeForm() { creating.value = false; editing.value = false; }

function parseTags() {
  return tagsText.value.split(',').map(t => t.trim()).filter(Boolean).slice(0, 20);
}

async function submitCreate() {
  if (!form.value.name.trim()) { store.toast('Nhập tên dự án.', 'error'); return; }
  busy.value = true;
  try {
    const payload = { ...form.value, tags: parseTags() };
    const d = await store.createProject(payload);
    if (d) closeForm();
  } finally { busy.value = false; }
}
async function submitEdit() {
  if (!form.value.name.trim()) { store.toast('Nhập tên dự án.', 'error'); return; }
  busy.value = true;
  try {
    const payload = { ...form.value, tags: parseTags() };
    const d = await store.updateProject(projectId.value, payload);
    if (d) closeForm();
  } finally { busy.value = false; }
}

async function openProject(p) {
  if (openingId.value) return;
  openingId.value = p.id;
  // Mở dự án người khác (scope pending) → chế độ duyệt, KHÔNG áp dụng cho phiên tạo ảnh.
  try { await store.loadProject(p.id, { reviewOnly: store.projectScope === 'pending' }); }
  finally { openingId.value = null; }
}
function closeProject() {
  store.clearActiveProject();
  transNote.value = '';
}

async function move(p, to) {
  if (movingId.value) return; // chống double-click
  movingId.value = p.id;
  try {
    const d = await store.transitionProject(p.id, to, transNote.value.trim());
    if (d) {
      transNote.value = '';
      // Ở hàng đợi duyệt: tải lại để dự án vừa duyệt rời khỏi danh sách pending.
      if (store.projectScope === 'pending') await store.loadProjects();
    }
  } finally { movingId.value = null; }
}

async function removeProject(p) {
  if (confirmDelete.value !== p.id) {
    confirmDelete.value = p.id;
    clearTimeout(confirmTimer);
    confirmTimer = setTimeout(() => { confirmDelete.value = ''; }, 4000);
    return;
  }
  clearTimeout(confirmTimer);
  confirmDelete.value = '';
  await store.deleteProject(p.id);
}

async function detachGen(g) {
  if (detachId.value) return;
  detachId.value = g.id;
  try {
    await store.attachGenerationToProject(store.activeProject.id, g.id, 'detach');
  } finally {
    detachId.value = null;
  }
}

async function toggleArchived() {
  store.projectsArchived = !store.projectsArchived;
  if (store.projectsArchived) store.projectScope = 'own'; // loại trừ lẫn nhau với hàng đợi duyệt
  await store.loadProjects();
}
async function togglePending() {
  if (store.projectScope === 'pending') {
    store.projectScope = 'own';
  } else {
    store.projectScope = 'pending';
    store.projectsArchived = false; // loại trừ lẫn nhau với lưu trữ
  }
  await store.loadProjects();
}

// ESC: đóng modal tạo/sửa trước → thoát detail về board → đóng workspace.
function onKeydown(e) {
  if (e.key !== 'Escape' || !open.value) return;
  if (creating.value || editing.value) { closeForm(); return; }
  if (store.activeProject) { closeProject(); return; }
  open.value = false;
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown);
  if (!store.projectLoaded) store.loadProjects();
});
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown);
  clearTimeout(confirmTimer);
});
watch(() => open.value, (v) => {
  if (v && !store.projectLoaded) store.loadProjects();
});
</script>

<template>
  <Teleport to="body">
    <div v-if="open" role="dialog" aria-modal="true" aria-label="Dự án thiết kế" class="fixed inset-0 z-[95] flex items-stretch justify-center bg-black/70 p-3 sm:p-6">
      <div class="flex h-full w-full max-w-6xl flex-col overflow-hidden rounded-lg border border-ink-700 bg-ink-950 text-cream-100 shadow-2xl">
        <!-- ══ Header ══ -->
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-ink-700 bg-ink-900 px-4 py-3">
          <div class="flex items-center gap-3">
            <span class="font-display text-base font-semibold"><StudioIcon name="kanban" size="h-4 w-4" class="mr-1.5 inline align-[-2px]" />Dự án thiết kế</span>
            <div class="flex items-center gap-1 rounded-md bg-ink-800 p-1">
              <button @click="store.projectView = 'board'" class="rounded-lg px-2.5 py-1 text-xs font-semibold" :class="store.projectView === 'board' ? 'bg-brand-600 text-white' : 'text-cream-200 hover:bg-ink-700'">Bảng</button>
              <button @click="store.projectView = 'list'" class="rounded-lg px-2.5 py-1 text-xs font-semibold" :class="store.projectView === 'list' ? 'bg-brand-600 text-white' : 'text-cream-200 hover:bg-ink-700'">Danh sách</button>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="store.projectCanReview" @click="togglePending" class="rounded-full border px-3 py-1.5 text-xs font-semibold" :class="store.projectScope === 'pending' ? 'border-brand-500 bg-brand-600/30 text-brand-100' : 'border-ink-700 text-cream-200 hover:bg-ink-800'"><StudioIcon name="clock" size="h-3.5 w-3.5" class="mr-1 inline align-[-2px]" />Chờ duyệt</button>
            <button @click="toggleArchived" class="rounded-full border px-3 py-1.5 text-xs font-semibold" :class="store.projectsArchived ? 'border-brand-500 bg-brand-600/30 text-brand-100' : 'border-ink-700 text-cream-200 hover:bg-ink-800'"><StudioIcon name="archive" size="h-3.5 w-3.5" class="mr-1 inline align-[-2px]" />Đã lưu trữ</button>
            <button @click="openCreate" class="rounded-full bg-brand-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-brand-500">+ Dự án mới</button>
            <button @click="open = false" aria-label="Đóng không gian dự án" class="grid h-8 w-8 place-items-center rounded-full bg-ink-700 text-cream-200 hover:bg-ink-600"><StudioIcon name="x" size="h-4 w-4" /></button>
          </div>
        </div>

        <!-- ══ Body ══ -->
        <div class="flex flex-1 overflow-hidden">
          <!-- Loading (board + list) — spinner gọn, không để khoảng trống im lặng -->
          <div v-if="!store.activeProject && store.projectLoading" class="flex flex-1 flex-col items-center justify-center gap-3 p-10">
            <span class="h-7 w-7 animate-spin rounded-full border-2 border-ink-700 border-t-brand-500"></span>
            <p class="text-xs text-cream-300/50">Đang tải dự án…</p>
          </div>

          <!-- Board view -->
          <div v-else-if="store.projectView === 'board' && !store.activeProject" class="flex flex-1 overflow-hidden">
            <!-- Empty state cho hàng đợi duyệt / scope lưu trữ -->
            <div v-if="boardEmptyMessage" class="flex flex-1 items-center justify-center p-10 text-center text-xs text-cream-300/40">{{ boardEmptyMessage }}</div>
            <div v-else class="scrollbar-hide flex flex-1 gap-3 overflow-x-auto p-4">
              <div v-for="s in statusOrder" :key="s" class="flex w-60 shrink-0 flex-col rounded-md border border-ink-700/60 bg-ink-900/50">
                <div class="flex items-center justify-between border-b border-ink-700/60 px-3 py-2">
                  <span class="flex items-center gap-2 text-xs font-bold">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ background: statusColor(s) }"></span>
                    {{ statusLabel(s) }}
                  </span>
                  <span class="rounded-full bg-ink-800 px-2 py-0.5 text-[10px] text-cream-300">{{ (grouped[s] || []).length }}</span>
                </div>
                <div class="flex min-h-24 flex-col gap-2 p-2">
                  <div v-for="p in grouped[s]" :key="p.id" class="group relative cursor-pointer rounded-lg border border-ink-700/60 bg-ink-800/70 p-2.5 transition hover:border-brand-500/50 hover:bg-ink-800" :class="openingId === p.id ? 'pointer-events-none opacity-60' : ''" @click="openProject(p)">
                    <span v-if="openingId === p.id" class="absolute right-2 top-2 z-10 h-3.5 w-3.5 animate-spin rounded-full border-2 border-ink-600 border-t-brand-400"></span>
                    <div class="flex items-start justify-between gap-2">
                      <p class="line-clamp-2 text-xs font-semibold text-cream-50">{{ p.name }}</p>
                      <span v-if="p.color" class="h-3 w-3 shrink-0 rounded-full border border-white/20" :style="{ background: p.color }"></span>
                    </div>
                    <span v-if="store.projectScope === 'pending' && p.owner_name" class="mt-1 inline-flex items-center gap-0.5 max-w-full truncate rounded-full bg-ink-700 px-1.5 py-0.5 text-[9px] text-cream-300"><StudioIcon name="user" size="h-3 w-3" class="shrink-0" />{{ p.owner_name }}</span>
                    <div v-if="p.thumbnail" class="mt-2 h-20 w-full overflow-hidden rounded-md bg-ink-900">
                      <img :src="thumbUrl(p.thumbnail)" :alt="p.name" class="h-full w-full object-cover" loading="lazy" @error="onThumbError($event, p.thumbnail)">
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2">
                      <span class="text-[10px] text-cream-300/60">{{ p.generations_count || 0 }} ảnh</span>
                      <span v-if="p.deadline" class="inline-flex items-center gap-0.5 text-[10px] font-semibold" :class="isOverdue(p) ? 'text-red-400' : 'text-cream-300/70'" :title="deadlineFull(p.deadline)"><StudioIcon name="calendar" size="h-3 w-3" class="shrink-0" />{{ deadlineLabel(p.deadline) }}{{ deadlineCountdown(p) ? ' · ' + deadlineCountdown(p) : '' }}</span>
                    </div>
                    <div v-if="p.tags && p.tags.length" class="mt-1.5 flex flex-wrap gap-1">
                      <span v-for="t in p.tags.slice(0, 3)" :key="t" class="rounded-full bg-ink-700 px-1.5 py-0.5 text-[9px] text-cream-300">{{ t }}</span>
                    </div>
                    <!-- Áp dụng nhanh (chỉ own scope) -->
                    <button v-if="store.projectScope === 'own'" @click.stop="store.appliedProject?.id === p.id ? store.unapplyProject() : store.applyProject(p)" class="absolute right-1.5 top-1.5 grid h-6 w-6 place-items-center rounded-full transition" :class="store.appliedProject?.id === p.id ? 'bg-brand-600/80 text-white hover:bg-brand-500' : 'bg-ink-700/80 text-cream-300 opacity-0 hover:bg-ink-600 hover:text-brand-200 group-hover:opacity-100'" :title="store.appliedProject?.id === p.id ? 'Đang áp dụng — bấm để ngắt' : 'Áp dụng dự án này'"><StudioIcon :name="store.appliedProject?.id === p.id ? 'pin' : 'pinOff'" size="h-3.5 w-3.5" /></button>
                  </div>
                  <p v-if="!grouped[s].length" class="py-6 text-center text-[10px] text-cream-300/30">Trống</p>
                </div>
              </div>
            </div>
          </div>

          <!-- List view -->
          <div v-else-if="store.projectView === 'list' && !store.activeProject" class="flex-1 overflow-y-auto p-4">
            <div class="overflow-hidden rounded-md border border-ink-700">
              <table class="w-full text-left text-xs">
                <thead class="bg-ink-900 text-cream-300/70">
                  <tr>
                    <th class="px-3 py-2 font-semibold">Dự án</th>
                    <th class="px-3 py-2 font-semibold">Trạng thái</th>
                    <th class="px-3 py-2 font-semibold">Deadline</th>
                    <th class="px-3 py-2 font-semibold">Ảnh</th>
                    <th class="px-3 py-2 text-right font-semibold">Thao tác</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-ink-800">
                  <tr v-for="p in store.projects" :key="p.id" class="bg-ink-900/40 hover:bg-ink-800/60" :class="openingId === p.id ? 'opacity-60' : ''">
                    <td class="px-3 py-2.5">
                      <button class="text-left" :disabled="openingId === p.id" @click="openProject(p)">
                        <span class="font-semibold text-cream-50">{{ p.name }}</span>
                        <span v-if="store.projectScope === 'pending' && p.owner_name" class="ml-1.5 inline-flex items-center gap-0.5 max-w-[10rem] truncate rounded-full bg-ink-700/70 px-1.5 py-0.5 align-middle text-[9px] text-cream-300"><StudioIcon name="user" size="h-3 w-3" class="shrink-0" />{{ p.owner_name }}</span>
                        <span v-if="p.base_concept" class="mt-0.5 block max-w-xs truncate text-[10px] text-cream-300/50">{{ p.base_concept }}</span>
                      </button>
                    </td>
                    <td class="px-3 py-2.5">
                      <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold" :style="{ background: statusColor(p.status) + '33', color: statusColor(p.status) }">
                        <span class="h-1.5 w-1.5 rounded-full" :style="{ background: statusColor(p.status) }"></span>{{ statusLabel(p.status) }}
                      </span>
                    </td>
                    <td class="px-3 py-2.5" :class="isOverdue(p) ? 'text-red-400' : 'text-cream-300/70'" :title="deadlineFull(p.deadline)">{{ deadlineLabel(p.deadline) ? deadlineLabel(p.deadline) + (deadlineCountdown(p) ? ' · ' + deadlineCountdown(p) : '') : '—' }}</td>
                    <td class="px-3 py-2.5 text-cream-300/70">{{ p.generations_count || 0 }}</td>
                    <td class="px-3 py-2.5 text-right">
                      <div class="inline-flex items-center gap-1.5">
                        <button v-if="store.projectScope === 'own'" @click.stop="store.appliedProject?.id === p.id ? store.unapplyProject() : store.applyProject(p)" class="grid h-7 w-7 place-items-center rounded-md" :class="store.appliedProject?.id === p.id ? 'bg-brand-600/30 text-brand-200 hover:bg-brand-600/50' : 'bg-ink-700/60 text-cream-300 hover:bg-ink-600 hover:text-brand-200'" :title="store.appliedProject?.id === p.id ? 'Đang áp dụng — bấm để ngắt' : 'Áp dụng dự án này'"><StudioIcon :name="store.appliedProject?.id === p.id ? 'pin' : 'pinOff'" size="h-3.5 w-3.5" /></button>
                        <button @click="openProject(p)" :disabled="openingId === p.id" class="rounded-md bg-brand-600/20 px-2 py-1 text-[10px] font-semibold text-brand-200 hover:bg-brand-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-60">{{ openingId === p.id ? 'Đang mở…' : 'Mở' }}</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
              <p v-if="!store.projects.length" class="py-10 text-center text-xs text-cream-300/40">{{ listEmptyMessage }}</p>
            </div>
          </div>

          <!-- Detail view -->
          <div v-else-if="store.activeProject" class="flex flex-1 flex-col overflow-y-auto">
            <div class="sticky top-0 z-10 flex items-center justify-between border-b border-ink-700 bg-ink-900/95 px-4 py-3">
              <div class="flex items-center gap-3">
                <button @click="closeProject" aria-label="Quay lại bảng" class="grid h-8 w-8 place-items-center rounded-full bg-ink-700 text-cream-200 hover:bg-ink-600">←</button>
                <div>
                  <p class="font-display text-sm font-semibold text-cream-50">{{ store.activeProject.name }}</p>
                  <p class="text-[11px] text-cream-300/60">{{ statusLabel(store.activeProject.status) }} · {{ store.activeProject.generations_count || 0 }} ảnh</p>
                  <p v-if="store.activeProjectReviewOnly" class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-2.5 py-1 text-[10px] font-semibold text-amber-200" :title="'Bạn đang duyệt dự án của người khác — ảnh/video bạn tạo KHÔNG tự gắn vào đây'"><StudioIcon name="eye" size="h-3.5 w-3.5" class="shrink-0" />Chế độ duyệt — output bạn tạo không gắn vào dự án này</p>
                  <p v-else-if="store.appliedProject?.id === store.activeProject?.id" class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-600/20 px-2.5 py-1 text-[10px] font-semibold text-brand-200" :title="'Ảnh/video tạo mới sẽ tự gắn vào dự án này'"><StudioIcon name="pin" size="h-3.5 w-3.5" class="shrink-0" />Đang áp dụng — ảnh/video tạo mới sẽ tự gắn vào đây <button type="button" @click="store.unapplyProject()" class="ml-1 underline decoration-dotted hover:text-white">ngắt</button></p>
                  <button v-else @click="store.applyProject(store.activeProject)" class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-brand-600 px-2.5 py-1 text-[10px] font-semibold text-white hover:bg-brand-500"><StudioIcon name="pin" size="h-3.5 w-3.5" class="shrink-0" />Áp dụng dự án này</button>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <button @click="openEdit(store.activeProject)" class="inline-flex items-center gap-1 rounded-full border border-ink-700 px-3 py-1.5 text-xs font-semibold text-cream-200 hover:bg-ink-800"><StudioIcon name="pencil" size="h-3.5 w-3.5" />Sửa</button>
                <button @click="removeProject(store.activeProject)" class="inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold" :class="confirmDelete === store.activeProject.id ? 'border-red-600 bg-red-600 text-white' : 'border-ink-700 text-cream-200 hover:border-red-600 hover:text-red-300'"><StudioIcon name="trash" size="h-3.5 w-3.5" />{{ confirmDelete === store.activeProject.id ? 'Xác nhận xóa?' : 'Xóa' }}</button>
              </div>
            </div>

            <!-- Workflow transitions -->
            <div class="border-b border-ink-700 bg-ink-900/50 px-4 py-3">
              <p class="mb-2 text-[11px] font-semibold text-cream-300/60">Chuyển trạng thái (luồng công việc)</p>
              <div class="flex flex-wrap items-center gap-2">
                <button
                  v-for="t in store.activeProject.transitions || []"
                  :key="t.to"
                  @click="move(store.activeProject, t.to)"
                  :disabled="movingId === store.activeProject.id"
                  class="rounded-full border px-3 py-1.5 text-xs font-semibold transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50"
                  :style="{ borderColor: t.color, color: t.color, background: t.color + '1a' }"
                  :title="t.hint"
                >{{ t.label }}</button>
                <span v-if="movingId === store.activeProject.id" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-ink-600 border-t-brand-400"></span>
                <span v-if="!((store.activeProject.transitions || []).length)" class="text-[11px] text-cream-300/40">Không có chuyển trạng thái khả dụng.</span>
                <input
                  v-if="(store.activeProject.transitions || []).length"
                  v-model="transNote"
                  type="text"
                  maxlength="255"
                  :disabled="movingId === store.activeProject.id"
                  placeholder="Lý do chuyển trạng thái…"
                  class="w-full rounded-full border border-ink-700 bg-ink-900 px-3 py-1.5 text-[11px] text-cream-50 placeholder:text-cream-300/30 focus:border-brand-500 focus:outline-none disabled:opacity-50 sm:w-64"
                >
              </div>
            </div>

            <!-- Brief + meta -->
            <div class="grid gap-4 p-4 lg:grid-cols-3">
              <div class="lg:col-span-2 space-y-4">
                <div v-if="store.activeProject.brief" class="rounded-md border border-ink-700/60 bg-ink-900/40 p-3">
                  <p class="mb-1 text-[11px] font-bold uppercase tracking-wide text-cream-300/50">Brief thiết kế</p>
                  <p class="whitespace-pre-wrap text-xs leading-relaxed text-cream-100">{{ store.activeProject.brief }}</p>
                </div>
                <div v-if="store.activeProject.base_concept" class="rounded-md border border-ink-700/60 bg-ink-900/40 p-3">
                  <p class="mb-1 text-[11px] font-bold uppercase tracking-wide text-cream-300/50">Ý tưởng gốc</p>
                  <p class="text-xs text-cream-200">{{ store.activeProject.base_concept }}</p>
                </div>
                <div class="rounded-md border border-ink-700/60 bg-ink-900/40 p-3">
                  <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-cream-300/50">Outputs ({{ store.activeProjectGenerations.length }})</p>
                  <div v-if="store.activeProjectGenerations.length" class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5">
                    <div v-for="g in store.activeProjectGenerations" :key="g.id" class="group relative aspect-square overflow-hidden rounded-lg bg-ink-800" :class="g.media_url ? 'cursor-pointer' : ''" @click="g.media_url ? store.openViewer(g, store.activeProjectGenerations) : null">
                      <img v-if="g.media_url" :src="thumbUrl(g.media_url, 320)" :alt="'#' + g.id" class="h-full w-full object-cover" loading="lazy" @error="onThumbError($event, g.media_url)">
                      <!-- hover: nút gỡ khỏi dự án -->
                      <button v-if="!store.activeProjectReviewOnly && g.media_url" @click.stop="detachGen(g)" :disabled="detachId === g.id" class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-ink-900/80 text-cream-300 opacity-0 transition hover:bg-red-800 hover:text-white group-hover:opacity-100 disabled:opacity-60" title="Gỡ khỏi dự án"><StudioIcon name="unlink" size="h-3.5 w-3.5" /></button>
                      <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent px-1.5 pb-1 pt-4 text-[9px] text-cream-100">
                        <span class="block truncate">{{ g.status }}</span>
                      </div>
                    </div>
                  </div>
                  <p v-else class="py-6 text-center text-[11px] text-cream-300/40">Chưa có ảnh nào gắn vào dự án.</p>
                </div>
              </div>
              <div class="space-y-3">
                <div class="rounded-md border border-ink-700/60 bg-ink-900/40 p-3">
                  <p class="mb-1 text-[11px] font-bold uppercase tracking-wide text-cream-300/50">Deadline</p>
                  <p class="text-xs" :class="isOverdue(store.activeProject) ? 'text-red-400' : 'text-cream-100'" :title="deadlineFull(store.activeProject.deadline)">{{ deadlineLabel(store.activeProject.deadline) || 'Không đặt' }}<span v-if="deadlineCountdown(store.activeProject)" class="ml-1 text-[10px] opacity-80">({{ deadlineCountdown(store.activeProject) }})</span></p>
                </div>
                <div v-if="store.activeProject.tags && store.activeProject.tags.length" class="rounded-md border border-ink-700/60 bg-ink-900/40 p-3">
                  <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-cream-300/50">Thẻ</p>
                  <div class="flex flex-wrap gap-1.5">
                    <span v-for="t in store.activeProject.tags" :key="t" class="rounded-full bg-ink-700 px-2 py-0.5 text-[10px] text-cream-200">{{ t }}</span>
                  </div>
                </div>
                <div class="rounded-md border border-ink-700/60 bg-ink-900/40 p-3 text-[11px] text-cream-300/60">
                  <p class="mb-1 font-bold uppercase tracking-wide text-cream-300/50">Tạo lúc</p>
                  <p>{{ store.activeProject.created_at ? new Date(store.activeProject.created_at).toLocaleString('vi-VN') : '—' }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- ══ Modal tạo/sửa ══ -->
  <Teleport to="body">
    <div v-if="creating || editing" role="dialog" aria-modal="true" :aria-label="creating ? 'Tạo dự án mới' : 'Sửa dự án'" class="fixed inset-0 z-[96] flex items-center justify-center bg-black/70 p-4" @click.self="closeForm">
      <div class="w-full max-w-lg rounded-lg border border-ink-700 bg-ink-950 p-5 text-cream-100 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
          <p class="font-display text-sm font-semibold">{{ creating ? 'Dự án mới' : 'Sửa dự án' }}</p>
          <button @click="closeForm" aria-label="Đóng biểu mẫu" class="grid h-8 w-8 place-items-center rounded-full bg-ink-700 text-cream-200 hover:bg-ink-600"><StudioIcon name="x" size="h-4 w-4" /></button>
        </div>
        <div class="space-y-3">
          <div>
            <label class="mb-1 block text-[11px] font-semibold text-cream-300/70">Tên dự án <span class="text-red-400">*</span></label>
            <input v-model="form.name" type="text" class="w-full rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-sm text-cream-50 placeholder:text-cream-300/30 focus:border-brand-500 focus:outline-none" placeholder="Ví dụ: BST Xuân Hè 2026 — Đầm Linen">
          </div>
          <div>
            <label class="mb-1 block text-[11px] font-semibold text-cream-300/70">Ý tưởng gốc (base concept)</label>
            <input v-model="form.base_concept" type="text" class="w-full rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-sm text-cream-50 placeholder:text-cream-300/30 focus:border-brand-500 focus:outline-none" placeholder="Mô tả ngắn về ý tưởng">
          </div>
          <div>
            <label class="mb-1 block text-[11px] font-semibold text-cream-300/70">Brief thiết kế</label>
            <textarea v-model="form.brief" rows="3" class="w-full resize-none rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-sm text-cream-50 placeholder:text-cream-300/30 focus:border-brand-500 focus:outline-none" placeholder="Yêu cầu chi tiết cho Designer"></textarea>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="mb-1 block text-[11px] font-semibold text-cream-300/70">Deadline</label>
              <input v-model="form.deadline" type="date" class="w-full rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-sm text-cream-50 focus:border-brand-500 focus:outline-none">
            </div>
            <div>
              <label class="mb-1 block text-[11px] font-semibold text-cream-300/70">Màu nhận diện</label>
              <!-- input[type=color] không chấp nhận '' (warning "must be a valid CSS color") — fallback hiển thị, giữ nguyên '' trong model (dự án không màu) -->
              <input :value="form.color || '#4a7a90'" @input="form.color = $event.target.value" type="color" class="h-10 w-full rounded-lg border border-ink-700 bg-ink-900 px-1 py-1 focus:border-brand-500 focus:outline-none">
            </div>
          </div>
          <div>
            <label class="mb-1 block text-[11px] font-semibold text-cream-300/70">Thẻ (phân cách bằng dấu phẩy)</label>
            <input v-model="tagsText" type="text" class="w-full rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-sm text-cream-50 placeholder:text-cream-300/30 focus:border-brand-500 focus:outline-none" placeholder="linen, xuan-he, studio">
          </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button @click="closeForm" class="rounded-full border border-ink-700 px-4 py-2 text-xs font-semibold text-cream-200 hover:bg-ink-800">Hủy</button>
          <button @click="creating ? submitCreate() : submitEdit()" :disabled="busy" class="rounded-full bg-brand-600 px-5 py-2 text-xs font-semibold text-white hover:bg-brand-500 disabled:opacity-50">{{ busy ? 'Đang lưu…' : (creating ? 'Tạo dự án' : 'Lưu thay đổi') }}</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
</style>