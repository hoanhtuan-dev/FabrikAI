<script setup>
import { computed, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';
import { PROJECT_COLOR } from '../dataColors.js';

/**
 * TRÌNH XEM ẢNH = TRUNG TÂM ĐIỀU PHỐI (đợt 52, 2026-09-26).
 *
 * Trước đây lưới kết quả có 4 nút nhanh (Chọn · Sửa · Biến thể · Tải) và trình xem có thêm 5 nút
 * rời (Tải xuống · Tạo video · Biến thể · Sử dụng prompt · Xóa) — hai danh sách chồng nhau, mỗi
 * chỗ một nửa, và cả hai đều KHÔNG có upscale / mặc thử / ghép trang phục / kịch bản quay.
 *
 * Nay: lưới giữ ĐÚNG hai việc làm ngay tại chỗ (Sửa · Tải); chạm vào ảnh là mở trình xem — nơi có
 * ĐỦ mọi tính năng. Một chỗ để xem, một chỗ để làm tiếp.
 *
 * ĐIỀU PHỐI ĐI QUA ĐÚNG KÊNH CŨ: store.requestActivity(id) — kênh mà ChatModal đã dùng để mở
 * "Gợi ý từ ảnh". KHÔNG dựng kênh thứ hai, KHÔNG chép logic chọn panel sang đây (activeActivity là
 * biến cục bộ của StudioApp; nhân bản nó ở đây là hai bản sao trôi khỏi nhau).
 *
 * Danh sách tính năng đến từ PROP, do StudioApp truyền xuống — nó đã lọc theo cấu hình owner quản
 * lý ở /admin và theo gói cước (khoá module). Nhờ vậy trình xem KHÔNG giữ bản sao thứ hai của danh
 * sách đó. LibraryApp mở trình xem mà không truyền prop ⇒ chỉ còn nhóm nút của chính ảnh.
 */
const props = defineProps({
  actions: { type: Array, default: () => [] },
});

const store = useStudioStore();

const items = computed(() => store.viewerItems);
const idx = computed(() => items.value.findIndex(g => g.id === store.viewer?.id));
const current = computed(() => items.value[idx.value] || store.viewer);
const isVideo = computed(() => current.value?.type === 'video');
const imgError = ref(false);

// §5.2 (a11y): modal này đã có Esc + khóa scroll nền + cleanup, nhưng thiếu role/aria-modal và không
// nhận focus -> trình đọc màn hình không biết đây là hộp thoại và Tab vẫn đi xuyên ra sau lớp phủ.
// Theo đúng mẫu đã áp ở BaseModal.vue.
const rootEl = ref(null);

// ── Bảng bên phải (tính năng + thông tin) ──
const infoOpen = ref(true);
/**
 * «VIỆC KHÁC» — nhóm phụ của trình xem (đợt 63 · 2026-09-26).
 *
 * ĐO ĐƯỢC TRƯỚC KHI SỬA: panel trình xem có **21 nút** trải phẳng — 9 công cụ, khối dự án (3 nút),
 * khối prompt (2), lưới thông tin + lưới kỹ thuật, và 3 nút xoá. Mở một tấm ảnh ra là gặp một BỨC
 * TƯỜNG nút, không có thứ tự việc nào: người dùng phải tự đoán nên bấm gì trước.
 *
 * Nay theo mô hình Review → Options → Action (§15.7):
 *   · REVIEW   — tấm ảnh + điều hướng + thu/phóng (giữ nguyên);
 *   · ACTION   — hàng nút CHÍNH, đúng 3: «Sửa ảnh» · «Tải xuống» · «Việc khác (N)»;
 *   · OPTIONS  — mọi thứ còn lại nằm SAU «Việc khác» (9 công cụ · dự án · prompt · thông tin · kỹ thuật
 *                · xoá). Số N trên nút nói trước có bao nhiêu việc ở trong — không giấu thông tin.
 */
const moreOpen = ref(false);
/** Tải ảnh gốc — cùng route với mọi nút tải khác của app (không dùng media_url trần). */
function downloadCurrent() {
  const g = current.value;
  if (!g || !g.id) { store.toast('Chưa có ảnh để tải.', 'error'); return; }
  window.location.href = '/api/generations/' + g.id + '/download';
}
/**
 * Số việc nằm trong «Việc khác» — đếm THẬT từ danh sách công cụ, không viết cứng.
 *
 * LƯU Ý (đã trả giá): TRONG SCRIPT phải là `props.actions`, KHÔNG phải `actions` — template nhìn thấy prop trực tiếp, còn
 * script thì không. Viết `actions.value` ở đây từng gây `ReferenceError: actions is not defined` NGAY
 * TRONG LÚC RENDER — component ném lỗi giữa chừng nên panel/biến mất, mà triệu chứng nhìn thấy chỉ là
 * "trình xem thiếu nút" (lỗi nằm ở console). Đây là kiểu lỗi im lặng nhất của đợt 63.
 */
const moreCount = computed(() => (props.actions?.length || 0) + 4);
// [đợt 52] MẶC ĐỊNH ẨN. Đây là thông tin về ảnh, không phải việc cần làm với ảnh — mà người mở
// trình xem gần như luôn đang muốn LÀM gì đó. Mở sẵn ra là chiếm chỗ của chính việc họ cần.
const fieldsOpen = ref(false);
// Thông tin KỸ THUẬT (model · provider · seed) — ẩn sâu thêm một tầng. Người dùng cuối không cần
// biết ảnh do model nào sinh ra; đó là chi tiết của nhà cung cấp, không phải của công việc.
const techOpen = ref(false);

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
const deleting = ref(false);
async function doDelete() {
  // §5.2: trước đây không có cờ đang-xóa và nút không:disabled -> bấm nhanh 2 lần gửi 2 lệnh DELETE.
  if (deleting.value) return;
  const g = current.value; if (!g) return;
  deleting.value = true;
  const at = idx.value; // vị trí trước khi xóa
  try {
    const ok = await store.deleteGen(g);
    resetConfirm();
    if (!ok) return; // xóa thất bại → giữ modal
    const left = items.value;
    if (!left.length) { close(); return; }
    const next = left[Math.min(Math.max(at, 0), left.length - 1)];
    store.viewer = next;
    resetZoom();
  } finally {
    deleting.value = false;
  }
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

// ── Dải ảnh: CỘT BÊN TRÁI ảnh chính (desktop) — xem chú thích ở template ──
//
// [đợt 52] Dải này trước đây NẰM TRONG khung ảnh (absolute bottom-14), đè lên chính tấm ảnh đang
// xem và chồng chỗ với thanh thu/phóng. Nay nó ra NGOÀI khung ảnh thành một cột riêng bên trái, nên
// không còn gì đè lên ảnh. Hệ quả: bỏ luôn bốn hàm kéo-ngang (wheel→ngang, pointer capture…).
// Cột dọc cuộn bằng con lăn mặc định của trình duyệt — không cần mã nào.
const stripEl = ref(null);
function scrollStripToActive() {
  const el = stripEl.value;
  if (!el) return;
  const active = el.querySelector('[data-active="true"]');
  // block:'center' (không phải inline) vì cột chạy DỌC.
  if (active) active.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
}

// ── ĐIỀU PHỐI TỚI TÍNH NĂNG (trung tâm điều phối) ──
/**
 * Đưa ảnh này sang một nhóm công cụ khác. Ba bước, ĐÚNG thứ tự:
 *   1) store.select(g)     — ảnh này thành ảnh đang làm việc (card bên kia đọc nó làm nguồn);
 *   2) close()             — đóng trình xem TRƯỚC, vì StudioApp sẽ mở ngăn kéo/bảng công cụ;
 *   3) requestActivity(id) — đi qua kênh điều phối có sẵn, không tự chọn panel.
 * Gói theo thứ tự này là để không bao giờ có cảnh bảng công cụ mở ra SAU LƯNG trình xem.
 */
function dispatch(id) {
  const g = current.value;
  if (!g || !id) return;
  store.select(g);
  close();
  store.requestActivity(id);
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
    pending:    { label: 'Đang chờ',      cls: 'border-warn/40 bg-warn/15 text-warn' },
    processing: { label: 'Đang xử lý',    cls: 'border-warn/40 bg-warn/15 text-warn' },
    completed:  { label: 'Hoàn tất',      cls: 'border-ok/40 bg-ok/15 text-ok' },
    failed:     { label: 'Lỗi',           cls: 'border-danger/40 bg-danger/15 text-danger' },
    cancelled:  { label: 'Đã hủy',        cls: 'border-ink-600 bg-ink-800 text-cream-300' },
  }[s] || { label: s || '—', cls: 'border-ink-600 bg-ink-800 text-cream-300' };
});

// Thông tin người dùng HIỂU VÀ DÙNG ĐƯỢC: thuộc về ai · to cỡ nào · khi nào.
const fields = [
  { k: 'project', l: 'Dự án' },
  { k: 'ratio', l: 'Tỷ lệ' }, { k: 'resolution', l: 'Độ phân giải' },
  { k: 'duration', l: 'Thời lượng' }, { k: 'created_at', l: 'Ngày' },
];
// Chi tiết KỸ THUẬT của nhà cung cấp — tách riêng, mặc định đóng (xem techOpen).
const techFields = [
  { k: 'model', l: 'Model' }, { k: 'provider', l: 'Provider' },
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
  nextTick(() => rootEl.value?.focus?.());
  prefetchNeighbors();
});
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey, true);
  document.body.style.overflow = ''; // trả lại scroll nền
  clearTimeout(confirmTimer);
});
</script>

<template>
  <div ref="rootEl" tabindex="-1" role="dialog" aria-modal="true" aria-label="Xem ảnh" class="fixed inset-0 z-[110] flex items-center justify-center bg-scrim/90 p-2 outline-none backdrop-blur-sm sm:p-5" @click.self="close">
    <!-- Đóng -->
    <button @click="close" class="absolute right-4 top-4 z-30 grid h-10 w-10 place-items-center rounded-full bg-ink-800 text-cream-200 transition hover:bg-ink-700 hover:text-cream-50" title="Đóng (Esc)" aria-label="Đóng">
      <StudioIcon name="x" size="h-5 w-5" />
    </button>
    <div class="flex h-full w-full max-w-[1600px] flex-col gap-2 lg:flex-row">
      <!-- ══ DẢI ẢNH — CỘT BÊN TRÁI, NGOÀI KHUNG ẢNH (chỉ desktop) ══
           [đợt 52] Trước đây dải này NẰM TRONG khung ảnh (absolute bottom-14) — nó đè lên chính
           tấm ảnh đang xem và chồng chỗ với thanh thu/phóng ở bottom-3; trên màn thấp thì hai thứ
           đó chạm nhau. Nay nó ra ngoài, thành cột riêng: ảnh không còn bị che, và không còn cặp
           nút nào chồng lên nhau.
           ĐIỆN THOẠI: ẨN. Ở đó bề ngang là thứ đắt nhất, và đã có hai nút ‹ › để chuyển ảnh —
           thêm một cột thumbnail nữa là lấy chỗ của chính tấm ảnh mà người ta mở ra để xem. -->
      <aside
        v-show="items.length > 1"
        ref="stripEl"
        class="scrollbar-hide hidden w-[72px] shrink-0 flex-col gap-1.5 overflow-y-auto rounded-lg border border-ink-700/60 bg-ink-900/60 p-1.5 lg:flex"
        aria-label="Chọn ảnh khác"
      >
        <button
          v-for="g in items" :key="g.id"
          type="button"
          class="relative aspect-square w-full shrink-0 overflow-hidden rounded-lg border-2 transition"
          :class="current?.id === g.id ? 'border-brand-500' : 'border-ink-600 hover:border-ink-500'"
          :data-active="current?.id === g.id ? 'true' : 'false'"
          :title="'Xem ' + store.genName(g)"
          :aria-label="'Xem ' + store.genName(g)"
          :aria-current="current?.id === g.id ? 'true' : undefined"
          @click="store.viewer = g"
        >
          <img :src="thumbUrl(g.media_url, 320)" class="pointer-events-none h-full w-full select-none bg-ink-900 object-cover" loading="lazy" decoding="async" draggable="false" @error="onThumbError($event, g.media_url)" />
        </button>
      </aside>

      <!-- ══ Khu vực ảnh ══ -->
      <div class="relative min-h-0 flex-1 overflow-hidden rounded-lg border border-ink-700/60 bg-ink-900/40">
        <!-- Chuyển ảnh — NẰM TRONG khung ảnh, KHÔNG neo theo màn hình.
             [đợt 52] Trước đây hai nút này là absolute theo CẢ hộp thoại (left-2/right-2 của lớp phủ).
             Khi dải ảnh chuyển thành cột bên trái, nút ‹ lập tức đè lên cột đó (đo được trên Chrome:
             đè 21x25px lên thumbnail). Neo vào khung ảnh thì nút luôn ở trên chính tấm ảnh, và không
             bao giờ chạm vào cột dải ảnh — dù sau này cột đó rộng bao nhiêu. -->
        <button v-if="items.length > 1" @click="nav(-1)" class="absolute left-2 top-1/2 z-30 grid h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-ink-900/90 text-xl text-cream-100 transition hover:bg-brand-600" title="Ảnh trước (←)" aria-label="Ảnh trước">‹</button>
        <button v-if="items.length > 1" @click="nav(1)" class="absolute right-2 top-1/2 z-30 grid h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-ink-900/90 text-xl text-cream-100 transition hover:bg-brand-600" title="Ảnh sau (→)" aria-label="Ảnh sau">›</button>

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
                 :class="dragging ? 'transition-none' : 'transition-transform duration-instant ease-standard'"
                 :style="{ transform: 'translate(' + viewerPan.x + 'px, ' + viewerPan.y + 'px) scale(' + viewerZoom + ')' }"
                 draggable="false" @dblclick="toggleZoom" @error="imgError = true" @load="onImgLoad" />
          </Transition>
        </div>
        <p v-else class="absolute inset-0 grid place-items-center text-sm text-cream-400">{{ imgError ? 'Không tải được nội dung.' : 'Không có nội dung.' }}</p>
        <!-- Badge trạng thái + bộ đếm — GOM VỀ MỘT HÀNG ở góc TRÊN-TRÁI.
             [đợt 52] Trước đây trạng thái ở trái, bộ đếm ở PHẢI, còn nút thu/mở thông tin thì ở
             phải-tiếp-dưới — mà nút Đóng của hộp thoại cũng nằm ở góc phải trên, neo theo màn hình.
             Trên 320px ba thứ đó chen nhau ở cùng một góc. Nay góc phải trên chỉ còn nút Đóng; mọi
             nhãn của ảnh nằm gọn một hàng bên trái. Nhãn thu/mở thông tin đã bỏ — bảng bên phải tự
             có nút của nó. -->
        <div class="absolute left-3 top-3 z-20 flex max-w-[calc(100%-1rem)] items-center gap-1.5">
          <span v-if="current" class="inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-label font-semibold" :class="statusMeta.cls">
            <span v-if="['pending','processing'].includes(current.status)" class="h-2.5 w-2.5 animate-spin rounded-full border border-current border-t-transparent"></span>
            {{ statusMeta.label }}
          </span>
          <span v-if="items.length > 1" class="shrink-0 rounded-full border border-ink-700 bg-ink-900/90 px-2 py-0.5 text-label font-semibold text-cream-200">{{ idx + 1 }} / {{ items.length }}</span>
        </div>
        <!-- Zoom toolbar (chỉ khi có ảnh) -->
        <div v-if="current?.media_url && !isVideo" class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-0.5 rounded-full border border-ink-700 bg-ink-900/95 px-1.5 py-1 shadow-lg">
          <button @click="zoomOut" class="grid h-7 w-7 place-items-center rounded-full text-cream-200 transition hover:bg-ink-700" title="Thu nhỏ" aria-label="Thu nhỏ"><StudioIcon name="minus" size="h-4 w-4" /></button>
          <button @click="resetZoom" class="min-w-12 rounded-full px-2 py-0.5 text-body font-semibold text-cream-100 transition hover:bg-ink-700" title="Về 100%">{{ Math.round(viewerZoom * 100) }}%</button>
          <button @click="zoomIn" class="grid h-7 w-7 place-items-center rounded-full text-cream-200 transition hover:bg-ink-700" title="Phóng to" aria-label="Phóng to"><StudioIcon name="plus" size="h-4 w-4" /></button>
        </div>
      </div>

      <!-- ══ Panel hành động + thông tin ══════════════════════════════════════════════════════════════
           [Đợt 63] ĐÃ GỠ `<Transition name="aside">` bọc ngoài. Vì sao: Transition chỉ nhận ĐÚNG MỘT phần
           tử con, mà panel này lại chứa cả một khối `<template v-if>` (nhóm «Việc khác») — cấu trúc đó
           làm nhánh panel không được render (đo trên Chrome: DOM chỉ còn một nút comment của Vue, panel
           không tồn tại ⇒ mở trình xem ra KHÔNG có hành động nào). Panel này cũng không cần hiệu ứng vào:
           nó là một phần cố định của trình xem, không phải lớp phủ bật/tắt. ══ -->
      <!-- [Đợt 63] `v-show` thay `v-if`: panel này KHÔNG phải thông tin phụ — nó là chỗ chứa HÀNG NÚT CHÍNH
           (Sửa ảnh · Tải · Việc khác) và cả nhóm «Việc khác». Đo trên Chrome thật: với `v-if` panel KHÔNG
           có trong DOM (chỉ còn một nút comment của Vue) ⇒ mở trình xem ra chỉ thấy ảnh + thu/phóng, KHÔNG
           có một hành động nào. Dùng `v-show` thì panel luôn tồn tại và trạng thái bên trong (nhóm nào
           đang mở) không bị dựng lại mỗi lần ẩn/hiện. -->
      <aside v-show="infoOpen" data-viewer-panel class="flex max-h-[42vh] w-full shrink-0 flex-col gap-3 overflow-y-auto rounded-lg border border-ink-700 bg-ink-900/95 p-4 lg:max-h-none lg:w-80">
        <!-- Tiêu đề -->
        <div class="flex items-center justify-between">
          <p class="text-sm font-semibold text-cream-100">Ảnh #<span class="text-brand-300">{{ current?.id }}</span></p>
          <button @click="close" class="grid h-8 w-8 place-items-center rounded-full bg-ink-800 text-cream-300 transition hover:bg-ink-700 hover:text-cream-50 lg:hidden" title="Đóng" aria-label="Đóng">
            <StudioIcon name="x" size="h-4 w-4" />
          </button>
        </div>

        <!-- ══ HÀNG NÚT CHÍNH — ĐÚNG 3 (đợt 63) ══════════════════════════════════════════════════════
             Trước đây panel mở ra là 21 nút phẳng. Nay việc ĐẦU TIÊN người dùng làm với một tấm ảnh
             (sửa nó, hoặc tải nó) đứng ngay đây; mọi thứ khác nằm sau «Việc khác». Nút «Sửa ảnh» đi qua
             ĐÚNG kênh điều phối `dispatch('inpaint')` như mọi lối vào công cụ khác. -->
        <div class="grid grid-cols-3 gap-1.5" data-viewer-primary>
          <button
            type="button"
            class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg btn-magic text-label font-bold transition active:scale-[0.98] lg:min-h-9"
            title="Sửa ảnh này: tả điều muốn đổi · khoanh vùng · vẽ cọ"
            data-viewer-primary-action="edit"
            @click="dispatch('inpaint')"
          >
            <StudioIcon name="pencil" size="h-4 w-4" /> Sửa ảnh
          </button>
          <button
            type="button"
            class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 hover:bg-brand-600/10 lg:min-h-9"
            title="Tải ảnh gốc về máy"
            data-viewer-primary-action="download"
            @click="downloadCurrent"
          >
            <StudioIcon name="download" size="h-4 w-4" class="text-brand-300" /> Tải
          </button>
          <button
            type="button"
            class="flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 hover:bg-brand-600/10 lg:min-h-9"
            :class="moreOpen ? 'border-brand-500 text-cream-50' : ''"
            :aria-expanded="moreOpen ? 'true' : 'false'"
            :title="moreOpen ? 'Thu gọn danh sách việc khác' : 'Mở danh sách việc khác với ảnh này'"
            data-viewer-more
            @click="moreOpen = !moreOpen"
          >
            <StudioIcon :name="moreOpen ? 'chevronUp' : 'chevronDown'" size="h-4 w-4" class="text-brand-300" />
            Việc khác ({{ moreCount }})
          </button>
        </div>

        <!-- ══ PHẦN CÒN LẠI — sau «Việc khác» ══ -->
        <template v-if="moreOpen">
        <!-- ══ TÍNH NĂNG — TRUNG TÂM ĐIỀU PHỐI. Đứng ĐẦU, ngay dưới tiêu đề. ══
             Đây là lý do người dùng mở trình xem: để LÀM tiếp với ảnh. Mọi nhóm công cụ của Studio
             đều có mặt ở đây, sinh từ prop 'actions' (StudioApp truyền xuống, đã lọc theo cấu hình
             owner quản lý + theo gói cước). Không giữ bản sao danh sách ở đây.
             Mỗi ô cao 44px trên cảm ứng (min-h-11) — sàn chạm, và 36px khi có chuột (lg:min-h-9). -->
        <div v-if="actions.length" class="space-y-1.5">
          <p class="text-tiny font-semibold uppercase tracking-wide text-cream-400">Làm tiếp với ảnh này</p>
          <div class="grid grid-cols-2 gap-1.5">
            <button
              v-for="a in actions" :key="a.id"
              type="button"
              class="flex min-h-11 items-center gap-2 rounded-lg border border-ink-600 bg-ink-800 px-2.5 text-left text-body font-semibold text-cream-100 transition hover:border-brand-400 hover:bg-brand-600/10 hover:text-brand-100 lg:min-h-9"
              :data-viewer-action="a.id"
              :class="a.locked ? 'opacity-60' : ''"
              :title="a.locked ? a.label + ' — chưa có trong gói của bạn' : a.label"
              @click="dispatch(a.id)"
            >
              <StudioIcon :name="a.icon" size="h-4 w-4" class="shrink-0 text-brand-300" />
              <span class="min-w-0 flex-1 truncate">{{ a.label }}</span>
              <StudioIcon v-if="a.locked" name="lock" size="h-3.5 w-3.5" class="shrink-0 text-cream-400" />
            </button>
          </div>
        </div>

        <!-- ══ Khối Dự án: gắn / gỡ ══ -->
        <div class="rounded-md border border-ink-700/60 bg-ink-800/70 p-2.5">
          <!-- KHI đã có project_id: chip dự án + nút chuyển + nút gỡ -->
          <template v-if="current?.project_id">
            <div class="flex items-center justify-between gap-2">
              <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-500/40 bg-brand-500/15 px-2.5 py-1 text-body font-semibold text-brand-200">
                <StudioIcon name="pin" size="h-3 w-3" />
                {{ projectLabel }}
              </span>
              <div class="flex items-center gap-1.5">
                <button @click="toggleAttach" :disabled="attachBusy" class="inline-flex items-center gap-1 rounded-full border border-ink-600 bg-ink-800 px-2 py-1 text-label font-semibold text-cream-300 transition hover:border-brand-400 hover:bg-brand-600/10 hover:text-brand-200" title="Chuyển sang dự án khác (1 chạm)">
                  <StudioIcon name="link" size="h-3 w-3" />
                  Chuyển
                </button>
                <button @click="detachProject" :disabled="attachBusy" class="inline-flex items-center gap-1 rounded-full border border-ink-600 bg-ink-800 px-2 py-1 text-label font-semibold text-cream-300 transition hover:border-danger hover:bg-danger/10 hover:text-danger" title="Gỡ khỏi dự án">
                  <StudioIcon name="unlink" size="h-3 w-3" />
                  Gỡ
                </button>
              </div>
            </div>
          </template>
          <!-- KHI KHÔNG có project_id: nút gắn -->
          <template v-else>
            <button @click="toggleAttach" :disabled="attachBusy" class="inline-flex w-full items-center justify-center gap-1.5 rounded-full border border-ink-600 bg-transparent px-3 py-1.5 text-body font-semibold text-cream-200 transition hover:border-brand-400 hover:bg-brand-600/10 hover:text-brand-200">
              <StudioIcon name="link" size="h-3.5 w-3.5" />
              Gắn vào dự án
            </button>
          </template>
          <!-- Panel chọn dự án (dùng chung cho gắn mới + chuyển 1-chạm) -->
          <div v-if="attachOpen && !attachBusy" class="mt-2 space-y-1">
              <!-- Nút nhanh: gắn vào dự án đang áp dụng -->
              <button v-if="store.appliedProject && store.appliedProject.id !== current?.project_id" @click="attachToProject(store.appliedProject)" class="flex w-full items-center gap-2 rounded-lg bg-brand-600/15 px-2.5 py-1.5 text-body font-semibold text-brand-200 transition hover:bg-brand-600/25">
                <StudioIcon name="pin" size="h-3.5 w-3.5" />
                Gắn vào "{{ store.appliedProject.name }}" (dự án hiện tại)
              </button>
              <!-- Danh sách dự án -->
              <template v-if="filteredProjects.length">
                <button v-for="p in filteredProjects" :key="p.id" @click="attachToProject(p)" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-body transition hover:bg-ink-700">
                  <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: p.color || PROJECT_COLOR }"></span>
                  <span class="flex-1 truncate text-left text-cream-200">{{ p.name }}</span>
                  <span class="shrink-0 text-label text-cream-400">{{ p.generations_count ?? 0 }}</span>
                </button>
              </template>
              <p v-else class="py-1 text-center text-label text-cream-400">Chưa có dự án nào — tạo dự án ở Studio.</p>
            </div>
        </div>

        <!-- Prompt + copy -->
        <div class="rounded-md border border-ink-700/60 bg-ink-800/70 p-2.5">
          <div class="mb-1 flex items-center justify-between">
            <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Prompt</p>
            <button @click="copyPrompt" class="inline-flex items-center gap-1 rounded-full bg-ink-700 px-2 py-0.5 text-label font-semibold text-cream-200 transition hover:bg-brand-600 hover:text-cream-50" title="Sao chép prompt">
              <StudioIcon name="copy" size="h-3 w-3" />
              Sao chép
            </button>
          </div>
          <p class="max-h-24 overflow-y-auto whitespace-pre-wrap text-body leading-relaxed text-cream-100">{{ current?.prompt || '—' }}</p>
        </div>

        <!-- Nút của CHÍNH tấm ảnh (không phải một nhóm công cụ): tải về · dùng lại prompt.
             [đợt 52] «Tạo video» và «Tạo biến thể từ ảnh này» đã GỠ khỏi đây: cả hai đều có mặt
             trong khối TÍNH NĂNG ở trên («Kịch bản quay» · «Tạo biến thể ảnh») ⇒ giữ lại là hai
             lối vào cho cùng một việc, và người dùng phải đoán xem chúng khác nhau ở đâu. -->
        <div class="grid grid-cols-1 gap-1.5">
          <a :href="current ? '/api/generations/' + current.id + '/download' : '#'" class="btn-outline btn-sm inline-flex items-center justify-center gap-1 w-full !py-2">
            <StudioIcon name="download" size="h-3.5 w-3.5" />
            Tải ảnh gốc
          </a>
          <button @click="usePrompt" :disabled="!canUsePrompt" class="btn-outline btn-sm inline-flex items-center justify-center gap-1 w-full !py-2"
            :title="canUsePrompt ? 'Copy prompt & mở popup Prompt Tạo Ảnh để tạo ảnh mới' : 'Ảnh này không có prompt để sử dụng'">
            <StudioIcon name="sparkles" size="h-3.5 w-3.5" />
            Sử dụng prompt · Tạo ảnh mới
          </button>
        </div>

        <!-- ══ THÔNG TIN ẢNH — MẶC ĐỊNH ĐÓNG (fieldsOpen = false) ══
             Người mở trình xem gần như luôn đang muốn LÀM gì đó với ảnh, không phải đọc lý lịch
             của nó. Mở sẵn là chiếm chỗ của chính việc họ cần. Bên trong còn một tầng nữa:
             model · provider · seed nằm trong mục «Kỹ thuật», cũng đóng — đó là chi tiết của nhà
             cung cấp, không phải thông tin người dùng cuối cần thấy. -->
        <div class="rounded-md border border-ink-700/60 bg-ink-800/40">
          <button type="button" class="flex w-full items-center justify-between px-2.5 py-2" :aria-expanded="fieldsOpen" @click="fieldsOpen = !fieldsOpen">
            <span class="text-tiny font-semibold uppercase tracking-wide text-cream-400">Thông tin ảnh</span>
            <StudioIcon name="chevronDown" size="h-3.5 w-3.5" class="text-cream-400 transition-transform" :class="fieldsOpen ? '' : 'rotate-180'" />
          </button>
          <Transition name="cf">
            <div v-if="fieldsOpen" key="fields" class="space-y-1.5 px-2.5 pb-2.5">
              <div class="grid grid-cols-2 gap-1.5">
                <div v-for="f in fields" :key="f.k" class="rounded-md bg-ink-800/70 px-2.5 py-1.5">
                  <p class="text-tiny uppercase tracking-wide text-cream-400">{{ f.l }}</p>
                  <p class="truncate text-xs font-medium text-cream-100">{{ f.k === 'project' ? projectLabel : (current?.[f.k] ?? '—') }}</p>
                </div>
              </div>

              <!-- Kỹ thuật: model · provider · seed — đóng sẵn -->
              <button type="button" class="flex w-full items-center justify-between rounded-md px-1.5 py-1 text-tiny font-semibold text-cream-400 transition hover:text-cream-200" :aria-expanded="techOpen" @click="techOpen = !techOpen">
                <span class="flex items-center gap-1"><StudioIcon name="server" size="h-3 w-3" /> Kỹ thuật (model · nhà cung cấp)</span>
                <StudioIcon name="chevronDown" size="h-3 w-3" class="transition-transform" :class="techOpen ? '' : 'rotate-180'" />
              </button>
              <div v-if="techOpen" class="grid grid-cols-2 gap-1.5">
                <div v-for="f in techFields" :key="f.k" class="rounded-md bg-ink-800/70 px-2.5 py-1.5">
                  <p class="text-tiny uppercase tracking-wide text-cream-400">{{ f.l }}</p>
                  <p class="truncate text-xs font-medium text-cream-100">{{ current?.[f.k] ?? '—' }}</p>
                </div>
                <div v-if="seedValue" class="rounded-md bg-ink-800/70 px-2.5 py-1.5">
                  <p class="text-tiny uppercase tracking-wide text-cream-400">Seed</p>
                  <p class="truncate text-xs font-medium text-cream-100">{{ seedValue }}</p>
                </div>
              </div>
            </div>
          </Transition>
        </div>

        <!-- ══ Vùng nguy hiểm (tách biệt, xác nhận 2 bước) ══ -->
        <div class="mt-1 border-t border-ink-700/70 pt-3">
          <template v-if="!confirming">
            <button @click="startConfirm" class="inline-flex items-center justify-center gap-1 w-full rounded-md border border-danger/40 bg-transparent py-2 text-xs font-semibold text-danger transition hover:bg-danger/10">
              <StudioIcon name="trash" size="h-3.5 w-3.5" />
              Xóa ảnh
            </button>
          </template>
          <template v-else>
            <p class="mb-1.5 flex items-center justify-center gap-1 text-center text-body font-medium text-danger"><StudioIcon name="alertTriangle" size="h-3.5 w-3.5" /> Xóa vĩnh viễn? Hành động này không thể hoàn tác.</p>
            <div class="flex gap-1.5">
              <button @click="resetConfirm" class="flex-1 rounded-md border border-ink-600 bg-ink-800 py-2 text-xs font-semibold text-cream-200 transition hover:bg-ink-700">Hủy</button>
              <button @click="doDelete" :disabled="deleting" class="inline-flex items-center justify-center gap-1 flex-1 rounded-md bg-danger py-2 text-xs font-semibold text-danger-content transition hover:bg-danger disabled:opacity-60 disabled:cursor-not-allowed">
                <StudioIcon name="trash" size="h-3.5 w-3.5" />
                Xóa vĩnh viễn
              </button>
            </div>
          </template>
          <p class="mt-1.5 text-center text-label text-cream-400">Nhấn Esc để đóng · dùng ← → để xem ảnh khác</p>
        </div>
        </template><!-- /«Việc khác» -->
      </aside>
    </div>
  </div>
</template>

<style scoped>
/* Crossfade khi chuyển ảnh — không chớp trắng (ảnh mới chỉ vào sau khi load xong) */
.cf-enter-active, .cf-leave-active { transition: opacity var(--motion-dur-fast) var(--motion-ease-standard); }
.cf-enter-from, .cf-leave-to { opacity: 0; }
/* Bảng thông tin ảnh thu gọn / mở rộng */
.aside-enter-active, .aside-leave-active {
  transition: opacity var(--motion-dur-base) var(--motion-ease-emphasized),
              transform var(--motion-dur-base) var(--motion-ease-emphasized);
}
.aside-enter-from, .aside-leave-to { opacity: 0; transform: translateX(28px); }
</style>