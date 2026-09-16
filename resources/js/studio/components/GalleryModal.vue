<script setup>
import { computed, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

const items = computed(() => store.viewerItems);
const idx = computed(() => items.value.findIndex(g => g.id === store.viewer?.id));
const current = computed(() => items.value[idx.value] || store.viewer);
const isVideo = computed(() => current.value?.type === 'video');
const imgError = ref(false);

// ── Bảng "thông tin ảnh" thu gọn / mở rộng ──
const infoOpen = ref(true);
const fieldsOpen = ref(true); // lưới "Thông tin ảnh" (Dự án · Model · Provider …) thu gọn được

// ── Hiển thị ảnh KHÔNG chớp khi chuyển: giữ ảnh cũ đến khi ảnh mới load xong, rồi crossfade ──
const shown = ref({ id: store.viewer?.id ?? null, url: store.viewer?.media_url || '' });
const loadedUrls = new Set(); // url đã load xong → chuyển ngay, không chớp trắng
let probeId = 0;
function prefetchNeighbors() {
  const arr = items.value; const i = idx.value;
  if (!arr.length) return;
  for (const d of [-1, 1]) {
    const n = arr[(i + d + arr.length) % arr.length];
    if (n && n.media_url && n.type !== 'video' && !loadedUrls.has(n.media_url)) {
      const im = new Image(); im.onload = () => loadedUrls.add(n.media_url); im.src = n.media_url;
    }
  }
}
function requestShow(c) {
  if (!c || !c.media_url || c.type === 'video') { shown.value = { id: c?.id ?? null, url: '' }; return; }
  const url = c.media_url;
  if (shown.value.url === url || loadedUrls.has(url)) { shown.value = { id: c.id, url }; return; }
  const my = ++probeId;
  const probe = new Image();
  probe.onload = () => { loadedUrls.add(url); if (probeId === my && current.value?.media_url === url) shown.value = { id: c.id, url }; };
  probe.onerror = () => { if (probeId === my) { imgError.value = true; shown.value = { id: c.id, url }; } };
  probe.src = url;
}

function nav(d) {
  const n = items.value[(idx.value + d + items.value.length) % items.value.length];
  if (n) { store.viewer = n; }
}
function close() { store.viewer = null; }

// Đổi ảnh (mọi cách: nav / click thumbnail / xóa) → reset zoom + hủy confirm + scroll strip theo
watch(() => current.value?.id, () => {
  resetZoom(); resetConfirm(); imgError.value = false; attachOpen.value = false; attachBusy.value = false;
  requestShow(current.value);
  prefetchNeighbors();
  nextTick(scrollStripToActive);
});

// ── Xóa an toàn: xác nhận 2 bước, tự reset sau 3.5s ──
const confirming = ref(false);
let confirmTimer = null;
function startConfirm() { confirming.value = true; clearTimeout(confirmTimer); confirmTimer = setTimeout(() => { confirming.value = false; }, 3500); }
function resetConfirm() { confirming.value = false; clearTimeout(confirmTimer); }
async function doDelete() {
  const g = current.value; if (!g) return;
  const at = idx.value; // vị trí trước khi xóa
  const ok = await store.deleteGen(g);
  resetConfirm();
  if (!ok) return; // xóa thất bại → giữ modal
  const left = items.value;
  if (!left.length) { close(); return; }
  const next = left[Math.min(Math.max(at, 0), left.length - 1)];
  store.viewer = next;
  resetZoom();
}

// ── Zoom / pan ──
// Ảnh luôn được CSS "contain" (max-w/max-h 100%) → khi mở / đổi ảnh nó tự VỪA TRỌN khung
// theo cả 2 chiều, không phụ thuộc thời điểm load hay kích thước pixel của ảnh.
// viewerZoom: 100% = ảnh fit trọn khung (đây là "mặc định khi mở").
//  - zoom-to-cursor: điểm ảnh dưới con trỏ không trôi khi phóng/thu.
//  - DI CHUYỂN TỰ DO: kéo thoải mái ở mọi mức zoom; chỉ tự về giữa khi thu về ≤ 100%.
//  - Thu nhỏ tùy ý (tối thiểu 10%), phóng to tối đa 8×.
const viewerZoom = ref(1);
const viewerPan = ref({ x: 0, y: 0 });
const zoomArea = ref(null);
const imgEl = ref(null);
const dragging = ref(false);
let drag = null;
const ZOOM_MIN = 0.1;
const ZOOM_MAX = 8;

function resetZoom() { viewerZoom.value = 1; viewerPan.value = { x: 0, y: 0 }; }

// Kéo về giữa khi ảnh đang ≤ 100% (đã trọn khung — kéo chỉ tạo khoảng trống vô nghĩa).
function clampPan() {
  if (viewerZoom.value <= 1.0001) { viewerPan.value = { x: 0, y: 0 }; return; }
}

// cx, cy = toạ độ con trỏ so với TÂM vùng zoom. Sau scale k lần, dịch pan để điểm
// ảnh dưới con trỏ giữ nguyên vị trí màn hình: pan' = c*(1-k) + pan*k.
function zoomTo(cx, cy, factor) {
  const z0 = viewerZoom.value;
  let z1 = z0 * factor;
  if (z1 < ZOOM_MIN) z1 = ZOOM_MIN;
  if (z1 > ZOOM_MAX) z1 = ZOOM_MAX;
  if (z1 === z0) return;
  const k = z1 / z0;
  viewerZoom.value = z1;
  viewerPan.value = { x: cx * (1 - k) + viewerPan.value.x * k, y: cy * (1 - k) + viewerPan.value.y * k };
  clampPan();
}

function onWheel(e) {
  const area = zoomArea.value;
  if (!area) return;
  const rect = area.getBoundingClientRect();
  const cx = e.clientX - rect.left - rect.width / 2;
  const cy = e.clientY - rect.top - rect.height / 2;
  zoomTo(cx, cy, e.deltaY > 0 ? 1 / 1.15 : 1.15);
}
function zoomIn() { zoomTo(0, 0, 1.5); }
function zoomOut() { zoomTo(0, 0, 1 / 1.5); }
function panStart(e) {
  dragging.value = true; // kéo tự do ở mọi mức zoom
  drag = { x: e.clientX, y: e.clientY, px: viewerPan.value.x, py: viewerPan.value.y };
  if (e.currentTarget.setPointerCapture) { try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {} }
}
function panMove(e) { if (drag) { viewerPan.value = { x: drag.px + (e.clientX - drag.x), y: drag.py + (e.clientY - drag.y) }; } } // không giới hạn
function panEnd() { drag = null; dragging.value = false; }
function toggleZoom() { zoomTo(0, 0, viewerZoom.value <= 1.0001 ? 2 : 1 / 2); }
function onImgLoad() { imgError.value = false; if (shown.value.url) loadedUrls.add(shown.value.url); }

// ── Dải thumbnail: wheel + kéo để cuộn ngang ──
const stripEl = ref(null);
let stripDrag = null;
function stripWheel(e) {
  const el = e.currentTarget;
  el.scrollLeft += e.deltaY + e.deltaX;
}
// QUAN TRỌNG: KHÔNG setPointerCapture ngay ở pointerdown — capture sẽ nuốt mọi click
// lên thumbnail (không chuyển ảnh được). Chỉ capture SAU KHI người dùng kéo thật sự (>5px);
// nhấn giữ yên / chạm = click bình thường để chọn & chuyển ảnh.
function stripDown(e) {
  stripDrag = { x: e.clientX, left: e.currentTarget.scrollLeft, moved: false, id: e.pointerId, el: e.currentTarget };
}
function stripMove(e) {
  if (!stripDrag) return;
  const dx = e.clientX - stripDrag.x;
  if (!stripDrag.moved && Math.abs(dx) > 5) {
    stripDrag.moved = true;
    try { if (stripDrag.el.setPointerCapture) stripDrag.el.setPointerCapture(stripDrag.id); } catch (err) { /* không hỗ trợ capture — vẫn cuộn khi con trỏ ở trong dải */ }
    stripDrag.el.style.cursor = 'grabbing';
  }
  if (stripDrag.moved) stripDrag.el.scrollLeft = stripDrag.left - dx;
}
function stripUp() {
  if (!stripDrag) return;
  try {
    if (stripDrag.el.hasPointerCapture && stripDrag.el.hasPointerCapture(stripDrag.id)) stripDrag.el.releasePointerCapture(stripDrag.id);
  } catch (err) {}
  stripDrag.el.style.cursor = 'grab';
  stripDrag = null;
}
function scrollStripToActive() {
  const el = stripEl.value;
  if (!el) return;
  const active = el.querySelector('[data-active="true"]');
  if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
}

// ── Copy prompt ──
async function copyPrompt() {
  const p = current.value?.prompt || current.value?.image_prompt_en || '';
  if (!p) { store.toast('Không có prompt để sao chép.', 'error'); return; }
  // N12: PHẢI await — writeText trả Promise, không await thì catch không bao giờ bắt được
  // rejection (bị chặn quyền / insecure context) và toast báo thành công GIẢ.
  try { await navigator.clipboard.writeText(p); store.toast('Đã sao chép prompt.'); } catch (e) { store.toast('Lỗi sao chép.', 'error'); }
}
const canUsePrompt = computed(() => !!(current.value?.prompt || current.value?.image_prompt_en));
// Nút "Sử dụng": copy prompt vào ô Tạo Ảnh + mở popup Prompt Tạo Ảnh (ConceptCard — cần step 1 mount).
async function usePrompt() {
  const p = current.value?.prompt || current.value?.image_prompt_en || '';
  if (!p) { store.toast('Ảnh này không có prompt để sử dụng.', 'error'); return; }
  try { await navigator.clipboard.writeText(p); } catch (e) { /* popup vẫn mở */ }
  store.imagePromptEn = p;
  store.step = 1;           // đảm bảo ConceptCard (chứa popup Prompt Tạo Ảnh) được mount
  close();                  // đóng viewer trước (popup z70 < viewer z110)
  store.promptOpen = true;  // mở popup Prompt Tạo Ảnh
  store.toast('Đã copy prompt — chỉnh rồi tạo ảnh mới.');
}

// ── Badge trạng thái ──
const statusMeta = computed(() => {
  const s = current.value?.status;
  return {
    pending:    { label: 'Đang chờ',      cls: 'border-amber-500/40 bg-amber-500/15 text-amber-200' },
    processing: { label: 'Đang xử lý',    cls: 'border-amber-500/40 bg-amber-500/15 text-amber-200' },
    completed:  { label: 'Hoàn tất',      cls: 'border-emerald-500/40 bg-emerald-500/15 text-emerald-200' },
    failed:     { label: 'Lỗi',           cls: 'border-red-500/40 bg-red-500/15 text-red-200' },
    cancelled:  { label: 'Đã hủy',        cls: 'border-ink-600 bg-ink-800 text-cream-300/70' },
  }[s] || { label: s || '—', cls: 'border-ink-600 bg-ink-800 text-cream-300/70' };
});

const fields = [
  { k: 'project', l: 'Dự án' },
  { k: 'model', l: 'Model' }, { k: 'provider', l: 'Provider' },
  { k: 'ratio', l: 'Tỷ lệ' }, { k: 'resolution', l: 'Độ phân giải' },
  { k: 'duration', l: 'Thời lượng' }, { k: 'created_at', l: 'Ngày' },
];

// Seed (gieo quẻ) — đọc từ seed top-level hoặc meta.seed (ảnh đã tạo lưu thêm seed khi có).
const seedValue = computed(() => {
  const c = current.value;
  if (!c) return '';
  const s = c.seed ?? c.meta?.seed;
  return (s === null || s === undefined || s === '') ? '' : String(s);
});

// ── Gắn / gỡ dự án ──
const attachOpen = ref(false);
const attachBusy = ref(false);

const projectLabel = computed(() => {
  const c = current.value;
  if (!c) return '—';
  if (c.project) return c.project;
  if (c.project_id) return 'Dự án #' + c.project_id;
  return '—';
});

async function detachProject() {
  const c = current.value;
  if (!c || !c.project_id || attachBusy.value) return;
  attachBusy.value = true;
  try {
    await store.attachGenerationToProject(c.project_id, c.id, 'detach');
  } finally {
    attachBusy.value = false;
  }
}

async function attachToProject(p) {
  if (attachBusy.value) return;
  attachBusy.value = true;
  try {
    await store.attachGenerationToProject(p.id, current.value.id, 'attach');
    attachOpen.value = false;
  } finally {
    attachBusy.value = false;
  }
}

function toggleAttach() {
  if (attachBusy.value) return;
  attachOpen.value = !attachOpen.value;
  if (attachOpen.value && !store.projects.length && !store.projectLoaded) {
    store.loadProjects();
  }
}

const filteredProjects = computed(() => {
  return (store.projects || []).filter(p => !p.archived);
});

// ── Keyboard: Esc đóng · ←/→ chuyển ảnh (capture để ưu tiên khi modal mở) ──
function onKey(e) {
  const t = e.target;
  // §5.2: thiếu VIDEO -> khi ảnh tiêu điểm là video, ArrowLeft/Right bị preventDefault nên người
  // dùng không tua được video trong modal.
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'VIDEO' || t.isContentEditable)) return;
  e.stopImmediatePropagation();
  if (e.key === 'Escape') {
    e.preventDefault();
    if (confirming.value) { resetConfirm(); return; } // Esc ưu tiên hủy xác nhận xóa, không đóng modal
    close();
  }
  else if (e.key === 'ArrowLeft') { e.preventDefault(); nav(-1); }
  else if (e.key === 'ArrowRight') { e.preventDefault(); nav(1); }
}
onMounted(() => {
  window.addEventListener('keydown', onKey, true);
  document.body.style.overflow = 'hidden'; // khóa scroll nền khi modal mở
  nextTick(scrollStripToActive);
  prefetchNeighbors();
});
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey, true);
  document.body.style.overflow = ''; // trả lại scroll nền
  clearTimeout(confirmTimer);
});
</script>

<template>
  <div class="fixed inset-0 z-[110] flex items-center justify-center bg-black/90 p-2 backdrop-blur-sm sm:p-5" @click.self="close">
    <!-- Đóng -->
    <button @click="close" class="absolute right-4 top-4 z-30 grid h-10 w-10 place-items-center rounded-full bg-ink-900/90 text-cream-200 transition hover:bg-ink-700 hover:text-white" title="Đóng (Esc)" aria-label="Đóng">
      <StudioIcon name="x" size="h-5 w-5" />
    </button>
    <!-- Chuyển ảnh -->
    <button v-if="items.length > 1" @click="nav(-1)" class="absolute left-2 top-1/2 z-30 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full bg-ink-900/90 text-xl text-cream-100 transition hover:bg-brand-600 sm:h-10 sm:w-10" title="Ảnh trước (←)" aria-label="Ảnh trước">‹</button>
    <button v-if="items.length > 1" @click="nav(1)" class="absolute right-2 top-1/2 z-30 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full bg-ink-900/90 text-xl text-cream-100 transition hover:bg-brand-600 sm:h-10 sm:w-10" title="Ảnh sau (→)" aria-label="Ảnh sau">›</button>

    <div class="flex h-full w-full max-w-7xl flex-col gap-2 lg:flex-row">
      <!-- ══ Khu vực ảnh ══ -->
      <div class="relative min-h-0 flex-1 overflow-hidden rounded-lg border border-ink-700/60 bg-ink-900/40">
        <!-- Video: phát trực tiếp, không zoom/pan (fit trọn khung, centered) -->
        <video v-if="isVideo && current?.media_url" :src="current.media_url" controls autoplay loop muted playsinline
               class="absolute inset-0 m-auto max-h-full max-w-full rounded-md object-contain"></video>
        <!-- Ảnh: zoom / pan. absolute inset-0 m-auto + max-w/max-h 100% => LUÔN fit trọn
             khung & canh giữa khi mở (không phụ thuộc flex/grid). Scale quanh tâm ảnh. -->
        <div ref="zoomArea" v-else-if="current?.media_url && !imgError" class="absolute inset-0 cursor-grab overflow-hidden active:cursor-grabbing" style="touch-action:none"
             @wheel.prevent="onWheel"
             @pointerdown="panStart" @pointermove="panMove" @pointerup="panEnd" @pointerleave="panEnd">
          <Transition name="cf">
            <img v-if="shown.url" ref="imgEl" :src="shown.url" :key="'img-' + shown.id"
                 class="absolute inset-0 m-auto max-h-full max-w-full select-none object-contain"
                 :class="dragging ? 'transition-none' : 'transition-transform duration-100 ease-out'"
                 :style="{ transform: 'translate(' + viewerPan.x + 'px, ' + viewerPan.y + 'px) scale(' + viewerZoom + ')' }"
                 draggable="false" @dblclick="toggleZoom" @error="imgError = true" @load="onImgLoad" />
          </Transition>
        </div>
        <p v-else class="absolute inset-0 grid place-items-center text-sm text-cream-300/60">{{ imgError ? 'Không tải được nội dung.' : 'Không có nội dung.' }}</p>
        <!-- Badge trạng thái -->
        <span v-if="current" class="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-semibold" :class="statusMeta.cls">
          <span v-if="['pending','processing'].includes(current.status)" class="h-2.5 w-2.5 animate-spin rounded-full border border-current border-t-transparent"></span>
          {{ statusMeta.label }}
        </span>
        <!-- Bộ đếm x / y -->
        <span v-if="items.length > 1" class="absolute right-3 top-3 rounded-full border border-ink-700 bg-ink-900/90 px-2 py-0.5 text-[10px] font-semibold text-cream-200">{{ idx + 1 }} / {{ items.length }}</span>
        <!-- Thu gọn / mở thông tin ảnh -->
        <button @click="infoOpen = !infoOpen"
                class="absolute right-3 top-14 z-20 grid h-7 w-7 place-items-center rounded-full border transition"
                :class="infoOpen ? 'border-ink-700 bg-ink-900/90 text-cream-200 hover:bg-ink-700 hover:text-white' : 'border-brand-500/60 bg-brand-600/25 text-brand-200 hover:bg-brand-600/40'"
                :title="infoOpen ? 'Thu gọn thông tin ảnh' : 'Mở thông tin ảnh'"
                :aria-label="infoOpen ? 'Thu gọn thông tin ảnh' : 'Mở thông tin ảnh'">
          <StudioIcon name="columns" size="h-3.5 w-3.5"/>
        </button>
        <!-- Zoom toolbar (chỉ khi có ảnh) -->
        <div v-if="current?.media_url && !isVideo" class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-0.5 rounded-full border border-ink-700 bg-ink-900/95 px-1.5 py-1 shadow-lg">
          <button @click="zoomOut" class="grid h-7 w-7 place-items-center rounded-full text-cream-200 transition hover:bg-ink-700" title="Thu nhỏ" aria-label="Thu nhỏ"><StudioIcon name="minus" size="h-4 w-4" /></button>
          <button @click="resetZoom" class="min-w-12 rounded-full px-2 py-0.5 text-[11px] font-semibold text-cream-100 transition hover:bg-ink-700" title="Về 100%">{{ Math.round(viewerZoom * 100) }}%</button>
          <button @click="zoomIn" class="grid h-7 w-7 place-items-center rounded-full text-cream-200 transition hover:bg-ink-700" title="Phóng to" aria-label="Phóng to"><StudioIcon name="plus" size="h-4 w-4" /></button>
        </div>
        <!-- Dải thumbnail: cuộn ngang bằng wheel (vertical scroll → horizontal) + kéo chuột/touch -->
        <div v-if="items.length > 1" ref="stripEl"
             class="absolute bottom-14 left-1/2 z-10 flex max-w-[92%] -translate-x-1/2 items-center gap-1.5 overflow-x-auto rounded-lg border border-ink-700 bg-ink-900/95 p-1.5 shadow-lg"
             style="scrollbar-width:none; scroll-snap-type:x proximity; cursor:grab; touch-action:pan-x; user-select:none; -webkit-user-select:none;"
             @wheel.prevent="stripWheel"
             @pointerdown="stripDown" @pointermove="stripMove" @pointerup="stripUp" @pointercancel="stripUp" @pointerleave="stripUp">
          <button v-for="g in items" :key="g.id" @click="store.viewer = g"
                  :data-active="current?.id === g.id ? 'true' : 'false'"
                  class="relative h-11 w-11 shrink-0 snap-start overflow-hidden rounded-lg border-2 transition"
                  :class="current?.id === g.id ? 'border-brand-500' : 'border-ink-700/60 hover:border-ink-500'">
            <img :src="thumbUrl(g.media_url)" class="pointer-events-none h-full w-full select-none bg-ink-900 object-cover" loading="lazy" draggable="false" @error="onThumbError($event, g.media_url)" />
          </button>
        </div>
      </div>

      <!-- ══ Panel thông tin + hành động ══ -->
      <Transition name="aside">
      <aside v-if="infoOpen" class="flex max-h-[42vh] w-full shrink-0 flex-col gap-3 overflow-y-auto rounded-lg border border-ink-700 bg-ink-900/95 p-4 lg:max-h-none lg:w-80">
        <!-- Tiêu đề -->
        <div class="flex items-center justify-between">
          <p class="text-sm font-semibold text-cream-100">Ảnh #<span class="text-brand-300">{{ current?.id }}</span></p>
          <button @click="close" class="grid h-8 w-8 place-items-center rounded-full bg-ink-800 text-cream-300 transition hover:bg-ink-700 hover:text-white lg:hidden" title="Đóng" aria-label="Đóng">
            <StudioIcon name="x" size="h-4 w-4" />
          </button>
        </div>

        <!-- Thông tin ảnh (thu gọn được): Dự án · Model · Provider · Tỷ lệ · Độ phân giải · Thời lượng · Ngày -->
        <div class="flex items-center justify-between">
          <p class="text-[9px] font-semibold uppercase tracking-wide text-cream-300/50">Thông tin ảnh</p>
          <button @click="fieldsOpen = !fieldsOpen"
                  class="icon-btn !h-5 !w-5"
                  :title="fieldsOpen ? 'Thu gọn thông tin ảnh' : 'Mở rộng thông tin ảnh'"
                  :aria-label="fieldsOpen ? 'Thu gọn thông tin ảnh' : 'Mở rộng thông tin ảnh'">
            <StudioIcon name="chevronDown" size="h-3.5 w-3.5" :class="fieldsOpen ? '' : 'rotate-180'" />
          </button>
        </div>
        <Transition name="cf">
          <div v-if="fieldsOpen" key="fields" class="grid grid-cols-2 gap-1.5">
            <div v-for="f in fields" :key="f.k" class="rounded-md bg-ink-800/70 px-2.5 py-1.5">
              <p class="text-[9px] uppercase tracking-wide text-cream-300/50">{{ f.l }}</p>
              <p class="truncate text-xs font-medium text-cream-100">{{ f.k === 'project' ? projectLabel : (current?.[f.k] ?? '—') }}</p>
            </div>
          </div>
        </Transition>

        <!-- Seed (gieo quẻ) — hiện khi ảnh đã tạo có lưu seed -->
        <div v-if="seedValue" class="flex items-center justify-between rounded-md border border-brand-500/30 bg-brand-600/10 px-2.5 py-1.5">
          <span class="text-[9px] font-semibold uppercase tracking-wide text-brand-300/80">🎲 Seed</span>
          <span class="text-xs font-semibold text-brand-100">{{ seedValue }}</span>
        </div>

        <!-- ══ Khối Dự án: gắn / gỡ ══ -->
        <div class="rounded-md border border-ink-700/60 bg-ink-800/70 p-2.5">
          <!-- KHI đã có project_id: chip dự án + nút chuyển + nút gỡ -->
          <template v-if="current?.project_id">
            <div class="flex items-center justify-between gap-2">
              <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-500/40 bg-brand-500/15 px-2.5 py-1 text-[11px] font-semibold text-brand-200">
                <StudioIcon name="pin" size="h-3 w-3" />
                {{ projectLabel }}
              </span>
              <div class="flex items-center gap-1.5">
                <button @click="toggleAttach" :disabled="attachBusy" class="inline-flex items-center gap-1 rounded-full border border-ink-600 bg-ink-800 px-2 py-1 text-[10px] font-semibold text-cream-300 transition hover:border-brand-500/40 hover:bg-brand-600/10 hover:text-brand-200" title="Chuyển sang dự án khác (1 chạm)">
                  <StudioIcon name="link" size="h-3 w-3" />
                  Chuyển
                </button>
                <button @click="detachProject" :disabled="attachBusy" class="inline-flex items-center gap-1 rounded-full border border-ink-600 bg-ink-800 px-2 py-1 text-[10px] font-semibold text-cream-300 transition hover:border-red-500/40 hover:bg-red-600/10 hover:text-red-300" title="Gỡ khỏi dự án">
                  <StudioIcon name="unlink" size="h-3 w-3" />
                  Gỡ
                </button>
              </div>
            </div>
          </template>
          <!-- KHI KHÔNG có project_id: nút gắn -->
          <template v-else>
            <button @click="toggleAttach" :disabled="attachBusy" class="inline-flex w-full items-center justify-center gap-1.5 rounded-full border border-ink-600 bg-transparent px-3 py-1.5 text-[11px] font-semibold text-cream-200 transition hover:border-brand-500/40 hover:bg-brand-600/10 hover:text-brand-200">
              <StudioIcon name="link" size="h-3.5 w-3.5" />
              Gắn vào dự án
            </button>
          </template>
          <!-- Panel chọn dự án (dùng chung cho gắn mới + chuyển 1-chạm) -->
          <div v-if="attachOpen && !attachBusy" class="mt-2 space-y-1">
              <!-- Nút nhanh: gắn vào dự án đang áp dụng -->
              <button v-if="store.appliedProject && store.appliedProject.id !== current?.project_id" @click="attachToProject(store.appliedProject)" class="flex w-full items-center gap-2 rounded-lg bg-brand-600/15 px-2.5 py-1.5 text-[11px] font-semibold text-brand-200 transition hover:bg-brand-600/25">
                <StudioIcon name="pin" size="h-3.5 w-3.5" />
                Gắn vào "{{ store.appliedProject.name }}" (dự án hiện tại)
              </button>
              <!-- Danh sách dự án -->
              <template v-if="filteredProjects.length">
                <button v-for="p in filteredProjects" :key="p.id" @click="attachToProject(p)" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-[11px] transition hover:bg-ink-700">
                  <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: p.color || '#7aa2f7' }"></span>
                  <span class="flex-1 truncate text-left text-cream-200">{{ p.name }}</span>
                  <span class="shrink-0 text-[10px] text-cream-300/50">{{ p.generations_count ?? 0 }}</span>
                </button>
              </template>
              <p v-else class="py-1 text-center text-[10px] text-cream-300/50">Chưa có dự án nào — tạo dự án ở Studio.</p>
            </div>
        </div>

        <!-- Prompt + copy -->
        <div class="rounded-md border border-ink-700/60 bg-ink-800/70 p-2.5">
          <div class="mb-1 flex items-center justify-between">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-cream-300/50">Prompt</p>
            <button @click="copyPrompt" class="inline-flex items-center gap-1 rounded-full bg-ink-700 px-2 py-0.5 text-[10px] font-semibold text-cream-200 transition hover:bg-brand-600 hover:text-white" title="Sao chép prompt">
              <StudioIcon name="copy" size="h-3 w-3" />
              Sao chép
            </button>
          </div>
          <p class="max-h-24 overflow-y-auto whitespace-pre-wrap text-[11px] leading-relaxed text-cream-100">{{ current?.prompt || '—' }}</p>
        </div>

        <!-- Nhóm secondary: Tải xuống · Tạo video -->
        <div class="grid grid-cols-2 gap-1.5">
          <a :href="current ? '/api/generations/' + current.id + '/download' : '#'" class="btn-outline btn-sm inline-flex items-center justify-center gap-1 w-full !py-2">
            <StudioIcon name="download" size="h-3.5 w-3.5" />
            Tải xuống
          </a>
          <button v-if="!isVideo" @click="store.goVideo(current)" class="btn-outline btn-sm inline-flex items-center justify-center gap-1 w-full !py-2">
            <StudioIcon name="film" size="h-3.5 w-3.5" />
            Tạo video
          </button>
        </div>
        <!-- Nút Sử dụng: copy prompt → mở popup Prompt Tạo Ảnh để tạo ảnh mới -->
        <!-- Nhóm primary: Chỉnh sửa → Fitting Room (hành động chính, tô xanh ưu tiên) -->
        <button v-if="!isVideo" @click="store.goEdit(current)" class="btn-brand btn-sm inline-flex items-center justify-center gap-1 w-full !py-2.5">
          <StudioIcon name="pencil" size="h-3.5 w-3.5" />
          Chỉnh sửa → Fitting Room
        </button>

        <!-- Nút Sử dụng: copy prompt → mở popup Prompt Tạo Ảnh để tạo ảnh mới -->
        <button @click="usePrompt" :disabled="!canUsePrompt" class="btn-outline btn-sm inline-flex items-center justify-center gap-1 w-full !py-2.5"
          :title="canUsePrompt ? 'Copy prompt & mở popup Prompt Tạo Ảnh để tạo ảnh mới' : 'Ảnh này không có prompt để sử dụng'">
          <StudioIcon name="sparkles" size="h-3.5 w-3.5" />
          Sử dụng prompt · Tạo ảnh mới
        </button>

        <!-- ══ Vùng nguy hiểm (tách biệt, xác nhận 2 bước) ══ -->
        <div class="mt-1 border-t border-ink-700/70 pt-3">
          <template v-if="!confirming">
            <button @click="startConfirm" class="inline-flex items-center justify-center gap-1 w-full rounded-md border border-red-500/40 bg-transparent py-2 text-xs font-semibold text-red-300 transition hover:bg-red-600/10">
              <StudioIcon name="trash" size="h-3.5 w-3.5" />
              Xóa ảnh
            </button>
          </template>
          <template v-else>
            <p class="mb-1.5 flex items-center justify-center gap-1 text-center text-[11px] font-medium text-red-200"><StudioIcon name="alertTriangle" size="h-3.5 w-3.5" /> Xóa vĩnh viễn? Hành động này không thể hoàn tác.</p>
            <div class="flex gap-1.5">
              <button @click="resetConfirm" class="flex-1 rounded-md border border-ink-600 bg-ink-800 py-2 text-xs font-semibold text-cream-200 transition hover:bg-ink-700">Hủy</button>
              <button @click="doDelete" class="inline-flex items-center justify-center gap-1 flex-1 rounded-md bg-red-600 py-2 text-xs font-semibold text-white transition hover:bg-red-500">
                <StudioIcon name="trash" size="h-3.5 w-3.5" />
                Xóa vĩnh viễn
              </button>
            </div>
          </template>
          <p class="mt-1.5 text-center text-[10px] text-cream-300/40">Nhấn Esc để đóng · dùng ← → để xem ảnh khác</p>
        </div>
      </aside>
      </Transition>
    </div>
  </div>
</template>

<style scoped>
/* Crossfade khi chuyển ảnh — không chớp trắng (ảnh mới chỉ vào sau khi load xong) */
.cf-enter-active, .cf-leave-active { transition: opacity .18s ease; }
.cf-enter-from, .cf-leave-to { opacity: 0; }
/* Bảng thông tin ảnh thu gọn / mở rộng */
.aside-enter-active, .aside-leave-active { transition: opacity .18s ease, transform .18s ease; }
.aside-enter-from, .aside-leave-to { opacity: 0; transform: translateX(28px); }
</style>