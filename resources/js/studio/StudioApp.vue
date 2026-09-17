<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch, nextTick } from 'vue';
import { useStudioStore } from './store.js';
import CollectionsCard from './components/CollectionsCard.vue';
import SuggestCard from './components/SuggestCard.vue';
import ConceptCard from './components/ConceptCard.vue';
import StylistCard from './components/StylistCard.vue';
import UpscaleCard from './components/UpscaleCard.vue';
import InpaintCard from './components/InpaintCard.vue';
import ComposeCard from './components/ComposeCard.vue';
// [Yêu cầu 2026-09-17] Card cũ "Ảnh mới từ ảnh mẫu" tách thành 2 card riêng.
import VariationCard from './components/VariationCard.vue';
import TryOnCard from './components/TryOnCard.vue';
import RegionTools from './components/RegionTools.vue';
import CanvasMaskTools from './components/CanvasMaskTools.vue';
import ContextToolbar from './components/ContextToolbar.vue';
import DirectorCard from './components/DirectorCard.vue';
// [Đợt 0.5] GỠ import chết: SourcePanel/LibraryCard từng được thay bằng SourcePickerPopup/LibraryApp
// nhưng import còn sót — "card" cũ không render ở đâu, chỉ để lại ấn tượng mobile đang dùng chúng.
import SourcePickerPopup from './components/SourcePickerPopup.vue';
import OutputModule from './components/OutputModule.vue';
import LibraryApp from './LibraryApp.vue';
// MultiSelectBar đã gộp vào ContextToolbar (layer selection bar).
import GalleryModal from './components/GalleryModal.vue';
import ProjectWorkspace from './components/ProjectWorkspace.vue';
import StudioIcon from './components/StudioIcon.vue';
import LayersPanel from './components/LayersPanel.vue';
import CanvasStatusBar from './components/CanvasStatusBar.vue';
import AuthNotice from './components/AuthNotice.vue';
const store = useStudioStore();
// Đăng xuất qua fetch (route Laravel POST /dang-xuat) — dùng XSRF-TOKEN cookie cho CSRF.
async function logout() {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  const token = m ? decodeURIComponent(m[1]) : '';
  try {
    await fetch('/dang-xuat', { method: 'POST', headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' } });
  } catch (e) { /* bỏ qua lỗi mạng */ }
  window.location.href = '/';
}
// Activity bar (VSCode-style): mỗi icon mở 1 nhóm card trong sidebar.
//
// [Yêu cầu 2026-09-17] OWNER quản lý phần TRÌNH BÀY qua trang /admin → tab "Giao diện".
//   · id → card là DÂY CỐ ĐỊNH trong code (không có card thì mục cũng chẳng có gì để hiện)
//   · owner đổi được: THỨ TỰ · NHÃN · ICON · ẨN/HIỆN
const ACTIVITY_CARDS = {
  // (Đã gỡ 3 activity 2026-09-17: 'pattern' · 'tryon' cũ · 'swap' — xem STUDIO_REVIEW_PROGRESS.md.)
  // [Đợt 2 — 2026-09-19] Không gian làm việc theo nghề: bộ sưu tập đang làm · các bộ gần đây · việc đang chạy.
  collections: [CollectionsCard],
  concept: [SuggestCard],
  variation: [VariationCard],
  tryon: [TryOnCard],
  inpaint: [InpaintCard],
  compose: [ComposeCard],
  upscale: [UpscaleCard],
  director: [DirectorCard],
};

// Bản GỐC — dùng khi chưa tải xong cấu hình hoặc API lỗi, để thanh công cụ không bao giờ trống.
// Phải khớp `StudioGuiConfig::DEFAULTS` (có test đối chiếu), gồm cả nút popup + menu Cài đặt.
const ACTIVITY_FALLBACK = [
  { id: 'collections', kind: 'panel', icon: 'folderOpen', label: 'Bộ sưu tập' },
  { id: 'concept', kind: 'panel', icon: 'sparkles', label: 'Tạo ảnh' },
  { id: 'variation', kind: 'panel', icon: 'variations', label: 'Tạo biến thể ảnh' },
  { id: 'tryon', kind: 'panel', icon: 'hanger', label: 'Mặc thử đồ' },
  { id: 'inpaint', kind: 'panel', icon: 'pencil', label: 'Sửa ảnh' },
  { id: 'compose', kind: 'panel', icon: 'layers', label: 'Ghép ảnh' },
  { id: 'upscale', kind: 'panel', icon: 'maximize', label: 'Upscale' },
  { id: 'director', kind: 'panel', icon: 'film', label: 'Kịch bản quay' },
  { id: 'prompt', kind: 'action', icon: 'sparkles', label: 'Prompt Tạo Ảnh' },
  { id: 'stylist', kind: 'action', icon: 'shirt', label: 'Trợ lý thiết kế' },
  { id: 'settings', kind: 'menu', icon: 'gear', label: 'Cài đặt' },
];

const activityCfg = ref(null); // [{ id, kind, label, icon, visible }] từ /api/gui

/** Toàn bộ nút thanh công cụ TRÁI (trừ nút ghim đáy) — sinh từ cấu hình owner quản lý. */
const activityBar = computed(() => {
  const src = Array.isArray(activityCfg.value) && activityCfg.value.length
    ? activityCfg.value
    : ACTIVITY_FALLBACK;

  return src
    .filter((a) => a && a.visible !== false)
    // Mục 'panel' phải có card, nếu không thì bấm vào sẽ ra panel rỗng ⇒ chặn id lạ.
    .filter((a) => (a.kind === 'panel' ? !!ACTIVITY_CARDS[a.id] : ['action', 'menu'].includes(a.kind)))
    .map((a) => ({
      id: a.id,
      kind: a.kind || 'panel',
      icon: a.icon || 'square',
      label: a.label || a.id,
      cards: a.kind === 'panel' ? ACTIVITY_CARDS[a.id] : null,
    }));
});

/** Nhóm panel (đổi sidebar) — dùng cho vòng lặp card + panel đang mở. */
const activityNav = computed(() => activityBar.value.filter((a) => a.kind === 'panel'));

/** Mục menu Cài đặt — luôn ghim ở ĐÁY; nhãn/icon/ẩn-hiện theo cấu hình. */
const settingsEntry = computed(() => activityBar.value.find((a) => a.kind === 'menu' && a.id === 'settings') || null);

/** Nút KHÔNG đổi panel mà mở POPUP độc lập (Prompt Tạo Ảnh · Trợ lý thiết kế). */
function runToolbarAction(id) {
  if (id === 'prompt') { store.promptOpen = true; stylistPopupOpen.value = false; outputOpen.value = false; settingsOpen.value = false; }
  else if (id === 'stylist') { stylistPopupOpen.value = true; store.promptOpen = false; outputOpen.value = false; settingsOpen.value = false; }
}

function isToolbarActionActive(id) {
  if (id === 'prompt') return store.promptOpen;
  if (id === 'stylist') return stylistPopupOpen.value;
  return false;
}

// Owner ẩn đúng mục đang mở ⇒ nhảy về mục hiển thị đầu tiên, tránh panel rỗng không lối thoát.
watch(activityNav, (list) => {
  if (list.length && ! list.some((a) => a.id === activeActivity.value)) {
    activeActivity.value = list[0].id;
  }
});

/**
 * Nạp cấu hình thanh công cụ do OWNER đặt (/admin → tab "Giao diện").
 *
 * Không chặn khởi động và KHÔNG được làm hỏng app: API lỗi ⇒ giữ bản gốc trong code.
 * (Đọc được với mọi tài khoản Studio; chỉ owner ghi được.)
 */
async function loadGuiConfig() {
  try {
    const r = await fetch('/api/gui', { headers: { Accept: 'application/json' } });
    if (!r.ok) return;
    const d = await r.json();
    if (Array.isArray(d.activityBar) && d.activityBar.length) activityCfg.value = d.activityBar;
  } catch (e) { /* giữ bản gốc */ }
}

// [Đợt 2] Mở workspace Dự án khi card "Bộ sưu tập" yêu cầu (card không nhận được event).
watch(() => store.workspaceOpenRequest, (n) => { if (n > 0) projectsOpen.value = true; });

const activeActivity = ref('concept');
const menuOpen = ref(false);
const outputOpen = ref(false);
const projectsOpen = ref(false);
const promptPopupOpen = ref(false);  // popup độc lập cho Prompt Tạo Ảnh (ConceptCard)
const stylistPopupOpen = ref(false); // popup độc lập cho Trợ lý Thiết kế (StylistCard)
// [Yêu cầu 2026-09-17] Menu Cài đặt ở GÓC TRÁI DƯỚI CÙNG của activity bar.
const settingsOpen = ref(false);
// Backdrop chỉ là <div> bắt click (không nhận bàn phím) nên phải tự lo đóng bằng Escape.
function closeSettingsOnEsc(e) { if (e.key === 'Escape') settingsOpen.value = false; }
watch(settingsOpen, (open) => {
  if (open) window.addEventListener('keydown', closeSettingsOnEsc);
  else window.removeEventListener('keydown', closeSettingsOnEsc);
});
onBeforeUnmount(() => window.removeEventListener('keydown', closeSettingsOnEsc));
// [Đợt 0.6] Đã gỡ khối cài đặt PWA (hai nút "Cài đặt FabrikAI" + 4 hàm/ref liên quan) — Chốt Q4 bỏ PWA hoàn toàn.
// Popup "Prompt Tạo Ảnh" (ConceptCard) mount GLOBAL ở cuối template (mọi viewport):
// chỉ cần đồng bộ activity hiện tại + đóng drawer Outputs mobile cho gọn.
watch(() => store.promptOpen, (v) => { if (v) { activeActivity.value = 'concept'; outputOpen.value = false; } });
// Lưu cài đặt status bar khi thay đổi (snap · nền canvas · inspector).
watch([() => store.snapGrid, () => store.canvasBg, () => store.inspectorOpen], () => store.saveBarSettings());
// ── Thoát công cụ thông minh khi chuyển tác vụ / thoát ảnh tiêu điểm ──
watch(activeActivity, () => { store.exitCanvasTools(); store.step = activeActivity.value === 'director' ? 3 : activeActivity.value === 'concept' ? 1 : 2; });
watch(() => store.activeLayerId, (id) => { if (!id) store.exitCanvasTools(); });
watch(() => !!store.viewer, (v) => { if (v) store.exitCanvasTools(); });
watch(() => !!store.promptOpen, (v) => { if (v) store.exitCanvasTools(); });
const applyOpen = ref(false);
function openApplyPopover() {
  applyOpen.value = !applyOpen.value;
  if (applyOpen.value && !store.projectLoaded) store.loadProjects();
}
function onCanvasResize() { nextTick(() => { eraseTick.value++; drawTick.value++; }); }
function onBeforeUnload() { try { store.saveLayerLayout(); } catch (e) { /* bỏ qua */ } }
onMounted(async () => { loadGuiConfig(); await store.load(); if (new URLSearchParams(window.location.search).get('view') === 'library') store.studioView = 'library'; activeActivity.value = store.step === 3 ? 'director' : store.step === 2 ? 'variation' : 'concept'; store.loadPaletteFromImage(store.upscaleSrc); window.addEventListener('keydown', onCanvasKey); window.addEventListener('keydown', onLayerKeys); window.addEventListener('keydown', onHistoryKeys); window.addEventListener('keydown', onGlobalKey); window.addEventListener('resize', onCanvasResize); window.addEventListener('beforeunload', onBeforeUnload); });
onBeforeUnmount(() => { window.removeEventListener('keydown', onCanvasKey); window.removeEventListener('keydown', onLayerKeys); window.removeEventListener('keydown', onHistoryKeys); window.removeEventListener('resize', onCanvasResize); window.removeEventListener('beforeunload', onBeforeUnload); });
// Palette bám ẢNH HIỆN TẠI (mọi nguồn: result/preview, ảnh tải lên, product, layer đang sửa…).
watch(() => store.upscaleSrc, (url) => { store.loadPaletteFromImage(url); });
// Template refs -> store: StudioApp owns the canvas DOM; the store needs the elements for crop geometry.
const cvImg = ref(null);
const canvasZoom = ref(null);
const eraseOverlay = ref(null);
const drawOverlay = ref(null);
watch([cvImg, canvasZoom], ([img, zoom]) => { store.setCanvasRefs(img, zoom); });
watch(eraseOverlay, (el) => store.attachEraseCanvas(el));
watch(drawOverlay, (el) => store.attachDrawCanvas(el));
// While crop mode is on: re-fit the box when the ratio changes, re-init when the image changes.
watch(() => store.reframeRatio, () => { if (store.cropMode) store.refitCropBox(); });
// Khi đổi layer, KHÔNG reset zoom/pan — giữ nguyên khung nhìn của người dùng.
watch(() => store.upscaleSrc, () => {
  if (store.cropMode) store.initCropBox();
});
function onCanvasKey(e) {
  const editing = store.cropMode || store.inpaintMaskMode !== 'none' || store.eraseMode || store.drawMode || store.selectTool || store.panMode;
  if (!editing) return;
  const t = e.target;
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
  if (e.key === 'Escape') {
    // Thoát an toàn theo ưu tiên: vùng chọn/mask → vẽ → xóa → crop → selectTool → panMode.
    if (store.inpaintMaskMode !== 'none') store.clearInpaintMask();
    else if (store.drawMode) store.cancelDraw();
    else if (store.eraseMode) store.cancelErase();
    else if (store.cropMode) store.toggleCrop();
    else if (store.selectTool) store.selectTool = false;
    else if (store.panMode) store.panMode = false;
  } else if (e.key === 'Enter' && !(t && t.tagName === 'BUTTON')) {
    // Hoàn tất công cụ đang dùng (Enter = "Xong").
    if (store.cropMode) store.confirmCrop();
    else if (store.inpaintMaskMode !== 'none') store.confirmInpaintMask();
    else if (store.eraseMode) store.finishErase();
    else if (store.drawMode) store.finishDraw();
  } else if (e.key === 'a' && (e.ctrlKey || e.metaKey)) {
    // Ctrl+A: chọn tất cả layer (chỉ khi selectTool đang bật hoặc không có tool nào khác)
    if (!store.cropMode && store.inpaintMaskMode === 'none' && !store.drawMode && !store.eraseMode) {
      e.preventDefault();
      store.selectAll();
    }
  } else if (e.key === 'v' && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
    // 'v': toggle công cụ di chuyển canvas (panMode) — thân thiện với tablet.
    store.exitCanvasTools();
    store.panMode = !store.panMode;
  } else if (e.key === 's' && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
    // 's': toggle công cụ lựa chọn.
    store.exitCanvasTools();
    store.selectTool = !store.selectTool;
  }
}
// Phím tắt cho layer (chế độ stack): mũi tên di chuyển, Ctrl/Cmd+D nhân đôi.
function onLayerKeys(e) {
  if (store.cropMode || store.inpaintMaskMode !== 'none') return;
  if (store.viewer || store.confirmDeleteOpen) return; // đang có modal → không xử lý phím layer
  const t = e.target;
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
  // Phím Delete/Backspace → popup xác nhận xóa layer đang chọn (1 hoặc nhiều).
  if (e.key === 'Delete' || e.key === 'Backspace') {
    if (store.selectedIds.length && !e.ctrlKey && !e.metaKey) { e.preventDefault(); store.deleteSelection(); }
    return;
  }
  const l = store.activeLayer;
  if (!l || l.locked) return;
  if ((e.key === 'd' || e.key === 'D') && (e.ctrlKey || e.metaKey)) { e.preventDefault(); store.duplicateActiveUnit(); return; }
  const step = e.shiftKey ? 10 : 1;
  let dx = 0, dy = 0;
  if (e.key === 'ArrowLeft') dx = -step;
  else if (e.key === 'ArrowRight') dx = step;
  else if (e.key === 'ArrowUp') dy = -step;
  else if (e.key === 'ArrowDown') dy = step;
  else return;
  e.preventDefault();
  store.nudgeSelection(dx, dy); // di chuyển toàn bộ nhóm đang chọn
}
// Phím tắt undo/redo toàn cục (Ctrl+Z / Ctrl+Shift+Z / Ctrl+Y) — trừ khi đang vẽ mask brush.
function onHistoryKeys(e) {
  const t = e.target;
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
  if (!(e.ctrlKey || e.metaKey)) return;
  if (store.inpaintMaskMode === 'brush') return; // brush dùng Ctrl+Z riêng cho undo nét vẽ
  if (e.key === 'z' || e.key === 'Z') { e.preventDefault(); if (e.shiftKey) store.redo(); else store.undo(); }
  else if (e.key === 'y' || e.key === 'Y') { e.preventDefault(); store.redo(); }
}
const bgClass = computed(() => ({ grid: 'cvs-checker', dark: 'bg-ink-950', white: 'bg-white', cream: 'bg-cream-100' }[store.canvasBg] || 'cvs-checker'));
const activeActivityDef = computed(() => activityNav.value.find(a => a.id === activeActivity.value) || activityNav.value[0]);
const panel = computed(() => activeActivityDef.value.cards);
// Quản lý title cho /studio: cập nhật document.title theo activity + dự án đang áp dụng.
watch([activeActivity, () => store.appliedProject?.name], () => {
  const parts = [];
  if (activeActivityDef.value) parts.push(activeActivityDef.value.label);
  if (store.appliedProject && store.appliedProject.name) parts.push(store.appliedProject.name);
  parts.push('FabrikAI');
  document.title = parts.join(' · ');
}, { immediate: true });
// Chọn activity: trên tablet/mobile (sidebar ẩn) mở drawer để hiện card; desktop chỉ đổi card sidebar.
// Toggle kiểu VSCode: bấm icon ĐANG CHỌN → đóng/mở card; bấm icon KHÁC → đổi card + mở.
function selectActivity(id) {
  const tablet = window.innerWidth < 1024;
  if (id === activeActivity.value) {
    if (tablet) menuOpen.value = !menuOpen.value;
    else store.leftPanelOpen = !store.leftPanelOpen;
    return;
  }
  activeActivity.value = id;
  if (tablet) menuOpen.value = true;
  else store.leftPanelOpen = true;
}
// Right activity bar: Nguồn ảnh (popup) · Thư viện (điều hướng) · Outputs (toggle dock).
function goLibrary() { store.exitCanvasTools(); store.studioView = 'library'; }

// ── Command Palette (VSCode-style: Ctrl+Shift+P / F1 / Ctrl+K) ──
const paletteOpen = ref(false);
const paletteQuery = ref('');
const paletteInput = ref(null);
watch(paletteOpen, (v) => { if (v) nextTick(() => paletteInput.value && paletteInput.value.focus()); });
const paletteCommands = computed(() => {
  const q = paletteQuery.value.trim().toLowerCase();
  const list = [
    ...activityNav.value.map((a) => ({ id: 'act-' + a.id, label: 'Chuyển tới: ' + a.label, hint: a.id, icon: a.icon, run: () => selectActivity(a.id) })),
    { id: 'toggle-left', label: 'Bật/tắt panel trái', hint: 'explorer', icon: 'panelLeft', run: () => { store.leftPanelOpen = !store.leftPanelOpen; } },
    { id: 'toggle-layers', label: 'Bật/tắt panel Layers', hint: 'inspector', icon: 'layers', run: () => store.toggleInspector() },
    { id: 'toggle-outputs', label: 'Bật/tắt dock Outputs', hint: 'outputs', icon: 'grid', run: () => store.toggleOutputDock() },
    { id: 'library', label: 'Mở Thư viện', hint: 'library', icon: 'library', run: () => goLibrary() },
    { id: 'projects', label: 'Mở workspace Dự án', hint: 'projects', icon: 'kanban', run: () => { projectsOpen.value = true; } },
    { id: 'prompt', label: 'Mở Prompt Tạo Ảnh', hint: 'prompt', icon: 'sparkles', run: () => { store.promptOpen = true; } },
    { id: 'stylist', label: 'Mở Trợ lý thiết kế', hint: 'stylist', icon: 'shirt', run: () => { stylistPopupOpen.value = true; } },
    { id: 'source', label: 'Mở Nguồn ảnh', hint: 'source', icon: 'imagePlus', run: () => { store.sourcePickerOpen = true; } },
    { id: 'settings', label: 'Mở Cài đặt', hint: 'settings', icon: 'gear', run: () => { window.location.href = '/settings'; } },
    { id: 'presets', label: 'Mở Prompt Templates', hint: 'presets', icon: 'template', run: () => { window.location.href = '/presets'; } },
    { id: 'zoom-in', label: 'Phóng to canvas', hint: 'zoomIn', icon: 'zoomIn', run: () => store.zoomIn() },
    { id: 'zoom-out', label: 'Thu nhỏ canvas', hint: 'zoomOut', icon: 'zoomOut', run: () => store.zoomOut() },
    { id: 'zoom-fit', label: 'Vừa khung hình', hint: 'zoomFit', icon: 'maximize', run: () => store.zoomFit() },
    { id: 'undo', label: 'Hoàn tác', hint: 'Ctrl+Z', icon: 'undo', run: () => store.undo() },
    { id: 'redo', label: 'Làm lại', hint: 'Ctrl+Y', icon: 'redo', run: () => store.redo() },
  ];
  if (!q) return list;
  return list.filter((c) => c.label.toLowerCase().includes(q) || c.hint.includes(q));
});
function runCommand(cmd) { paletteOpen.value = false; paletteQuery.value = ''; cmd.run(); }
function onGlobalKey(e) {
  const mod = e.ctrlKey || e.metaKey;
  if (mod && e.shiftKey && (e.key === 'p' || e.key === 'P')) { e.preventDefault(); paletteOpen.value = !paletteOpen.value; paletteQuery.value = ''; return; }
  if (e.key === 'F1') { e.preventDefault(); paletteOpen.value = !paletteOpen.value; paletteQuery.value = ''; return; }
  if (mod && !e.shiftKey && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); paletteOpen.value = !paletteOpen.value; paletteQuery.value = ''; return; }
}
// ContextToolbar chỉ hiện (floating) trên mobile khi có công cụ đang hoạt động — tối giản mobile.
const toolActive = computed(() => store.inpaintMaskMode !== 'none' || store.inpaintMaskDone || store.eraseMode || store.drawMode || store.reframeOpen || store.cropMode || store.filmOpen || store.looking || store.selectTool || store.panMode || !!store.activeLayer);

// ── Layer editor (composite + transform) ──
const isolateActive = computed(() => (store.cropMode || store.inpaintMaskMode !== 'none' || store.eraseMode || store.drawMode) && !store.panMode);
function layerStyle(l, i) {
  return {
    transform: store.layerTransformStyle(l),
    transformOrigin: 'center',
    opacity: l.opacity != null ? l.opacity : 1,
    mixBlendMode: (l.blend && l.blend !== 'normal') ? l.blend : 'normal',
    zIndex: i + 1,
  };
}
// Transform của layer active trong chế độ isolate — giữ ĐÚNG vị trí/scale/rotation như ở stack (không xê dịch).
const isolateLayerStyle = computed(() => {
  const l = store.activeLayer;
  if (!l) return {};
  return {
    transform: store.layerTransformStyle(l),
    transformOrigin: 'center',
    opacity: l.opacity != null ? l.opacity : 1,
    mixBlendMode: (l.blend && l.blend !== 'normal') ? l.blend : 'normal',
  };
});
// Overlay canvas xóa bám đúng vùng ảnh hiển thị (chịu zoom/pan) — khớp canvasMetrics.
const eraseTick = ref(0);
watch([() => store.zoom, () => store.pan.x, () => store.pan.y, () => store.upscaleSrc, () => store.imgTick], () => { nextTick(() => { eraseTick.value++; }); });
// Kích hoạt công cụ / ảnh isolate thay đổi → overlay chưa đo được vị trí ngay trong render đầu
// (img vừa được tạo). Bump tick SAU khi patch để khung vẽ/overlay hiện đúng vị trí từ giây đầu.
watch([() => store.cropMode, () => store.inpaintMaskMode, () => store.eraseMode, () => store.drawMode, () => store.cvImg, () => store.imgTick], () => {
  nextTick(() => { eraseTick.value++; drawTick.value++; });
});
const eraseOverlayStyle = computed(() => {
  void eraseTick.value;
  const m = store.canvasMetrics();
  if (!m || !store.eraseMode) return { display: 'none' };
  return {
    left: (m.vx / m.crW * 100) + '%',
    top: (m.vy / m.crH * 100) + '%',
    width: (m.vw / m.crW * 100) + '%',
    height: (m.vh / m.crH * 100) + '%',
    touchAction: 'none',
  };
});
// Overlay canvas vẽ (paint) bám đúng vùng ảnh — giống erase.
const drawTick = ref(0);
watch([() => store.zoom, () => store.pan.x, () => store.pan.y, () => store.upscaleSrc], () => { nextTick(() => { drawTick.value++; }); });
const drawOverlayStyle = computed(() => {
  void drawTick.value;
  const m = store.canvasMetrics();
  if (!m || !store.drawMode) return { display: 'none' };
  return {
    left: (m.vx / m.crW * 100) + '%',
    top: (m.vy / m.crH * 100) + '%',
    width: (m.vw / m.crW * 100) + '%',
    height: (m.vh / m.crH * 100) + '%',
    touchAction: 'none',
  };
});
function onLayerPointerDown(l, e) {
  // panMode: không bao giờ kéo/thao tác layer — chỉ pan canvas.
  if (store.panMode) { store.panStart(e); bgDownPos = { x: e.clientX, y: e.clientY }; marquee.value = null; return; }
  if (e.shiftKey) { store.shiftSelectLayer(l.id); return; } // shift+click: thêm/bỏ vào nhóm chọn nhiều
  // selectTool bật: click layer = toggle chọn (giống shift+click) — quét vùng trống để chọn nhiều.
  if (store.selectTool) { store.shiftSelectLayer(l.id); return; }
  if (l.locked) { store.selectLayer(l); return; }
  // Alt+click layer trong nhóm → chỉnh sửa RIÊNG layer đó (edit in group), không chọn cả nhóm.
  if (e.altKey && l.groupId) store.setActiveLayer(l.id);
  // Click thường: nếu layer không thuộc nhóm đang chọn → chọn riêng nó; nếu thuộc nhóm → giữ nhóm.
  else if (!store.isSelected(l.id)) store.selectLayerWithGroup(l.id); // chọn cả nhóm nếu thuộc nhóm
  store.beginLayerDrag(l.id, e);
  const move = (ev) => store.layerDragMove(ev);
  const up = () => { store.endLayerDrag(); window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up); };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', up);
  window.addEventListener('pointercancel', up);
}
function unitCenterScreen() {
  const c = store._editUnitCenter();
  if (!c) return null;
  const el = store.canvasZoom; if (!el) return null;
  const r = el.getBoundingClientRect();
  return { cx: r.left + r.width / 2 + (c.x * (store.zoom || 1) + store.pan.x), cy: r.top + r.height / 2 + (c.y * (store.zoom || 1) + store.pan.y) };
}
function layerCenterScreen(l) {
  const el = store.canvasZoom;
  if (!el) return { cx: 0, cy: 0 };
  const r = el.getBoundingClientRect();
  return {
    cx: r.left + r.width / 2 + (l.x || 0) * store.zoom + store.pan.x,
    cy: r.top + r.height / 2 + (l.y || 0) * store.zoom + store.pan.y,
  };
}
function onScalePointerDown(l, e) {
  e.stopPropagation();
  const c = unitCenterScreen(); if (!c) return;
  const startDist = Math.hypot(e.clientX - c.cx, e.clientY - c.cy) || 1;
  const snap = store._snapshot(); let lastRatio = 1;
  const move = (ev) => { const ratio = Math.hypot(ev.clientX - c.cx, ev.clientY - c.cy) / startDist; const k = ratio / lastRatio; lastRatio = ratio; store.scaleSelectionBy(k); };
  const up = () => { if (snap) { store.undoStack.push(snap); if (store.undoStack.length > 50) store.undoStack.shift(); store.redoStack = []; } store.saveLayerLayout(); window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up); };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', up);
  window.addEventListener('pointercancel', up);
}
function onRotatePointerDown(l, e) {
  e.stopPropagation();
  const c = unitCenterScreen(); if (!c) return;
  const startAngle = Math.atan2(e.clientY - c.cy, e.clientX - c.cx);
  const snap = store._snapshot(); let lastAng = startAngle;
  const move = (ev) => { const a = Math.atan2(ev.clientY - c.cy, ev.clientX - c.cx); let d = (a - lastAng) * 180 / Math.PI; lastAng = a; store.rotateSelectionBy(d); };
  const up = () => { if (snap) { store.undoStack.push(snap); if (store.undoStack.length > 50) store.undoStack.shift(); store.redoStack = []; } store.saveLayerLayout(); window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up); };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', up);
  window.addEventListener('pointercancel', up);
}
// ── Quét chọn nhiều layer (marquee) trên vùng trống · pan khi giữ Ctrl/Space/Cmd ──
const marquee = ref(null); // {x0,y0,x1,y1} toạ độ client
let bgDownPos = null;
// Kéo-thả ảnh từ dock Outputs vào canvas: thả = thêm layer, hover = highlight khung thả.
const dropOver = ref(false);
function onCanvasDrop(e) {
  dropOver.value = false;
  try {
    const raw = e.dataTransfer.getData('text/plain');
    if (!raw) return;
    const d = JSON.parse(raw);
    if (d && d.url) store.addImagesToCanvas([{ url: d.url, name: d.name || 'Ảnh kéo thả' }]);
  } catch (err) { /* không phải dữ liệu kéo thả của studio */ }
}
function marqueeClientOn(e) { if (!store.canvasZoom) return 0; const r = store.canvasZoom.getBoundingClientRect(); void r; return 0; }
function onCanvasBgDown(e) {
  store.panStart(e);
  bgDownPos = { x: e.clientX, y: e.clientY };
  marquee.value = null;
}
function onCanvasBgMove(e) {
  if (!bgDownPos) return;
  const dx = e.clientX - bgDownPos.x, dy = e.clientY - bgDownPos.y;
  const panMod = e.ctrlKey || e.metaKey || e.altKey;
  // panMode: luôn pan, không bao giờ quét chọn.
  if (store.panMode) { store.panMove(e); return; }
  // Chỉ QUÉT CHỌN khi công cụ "Lựa chọn" đang bật; ngược lại kéo vùng trống = pan (không cạnh tranh).
  if (store.selectTool) {
    if (marquee.value === null && !panMod && (Math.abs(dx) > 6 || Math.abs(dy) > 6)) {
      marquee.value = { x0: bgDownPos.x, y0: bgDownPos.y, x1: e.clientX, y1: e.clientY };
      store.panEnd();
    }
    if (marquee.value) { marquee.value.x1 = e.clientX; marquee.value.y1 = e.clientY; return; }
  }
  store.panMove(e);
}
function onCanvasBgUp(e) {
  if (marquee.value) {
    const m = marquee.value, el = store.canvasZoom;
    if (el) {
      const r = el.getBoundingClientRect(); const z = store.zoom || 1, p = store.pan;
      const x0 = (Math.min(m.x0, m.x1) - (r.left + r.width / 2) - p.x) / z;
      const x1 = (Math.max(m.x0, m.x1) - (r.left + r.width / 2) - p.x) / z;
      const y0 = (Math.min(m.y0, m.y1) - (r.top + r.height / 2) - p.y) / z;
      const y1 = (Math.max(m.y0, m.y1) - (r.top + r.height / 2) - p.y) / z;
      store.selectInRect(x0, y0, x1, y1);
    }
    marquee.value = null; store.panEnd(); bgDownPos = null; return;
  }
  store.panEnd();
  // Click vùng trống: nếu panMode → không làm gì; selectTool → bỏ chọn hết.
  if (bgDownPos && !isolateActive.value && !store.panMode && Math.hypot(e.clientX - bgDownPos.x, e.clientY - bgDownPos.y) < 5) store.deselectAll();
  bgDownPos = null;
}
// Vòng cọ (preview) khi vẽ — bám con trỏ, cỡ = drawBrushSize × zoom.
const brushCursorStyle = computed(() => {
  if (!store.drawMode || !store._drawCursor) return { display: 'none' };
  const el = store.canvasZoom; if (!el) return { display: 'none' };
  const r = el.getBoundingClientRect();
  const d = Math.max(2, (store.drawBrushSize || 24) * 2 * (store.zoom || 1));
  return { left: (store._drawCursor.x - r.left - d / 2) + 'px', top: (store._drawCursor.y - r.top - d / 2) + 'px', width: d + 'px', height: d + 'px' };
});
const marqueeStyle = computed(() => {
  const m = marquee.value, el = store.canvasZoom; if (!m || !el) return { display: 'none' };
  const r = el.getBoundingClientRect();
  const left = Math.min(m.x0, m.x1) - r.left, top = Math.min(m.y0, m.y1) - r.top;
  return { left: left + 'px', top: top + 'px', width: Math.abs(m.x1 - m.x0) + 'px', height: Math.abs(m.y1 - m.y0) + 'px' };
});
// Khung bbox quanh MỖI NHÓM được chọn trọn (group = 1 đối tượng, giảm rối như Figma).
const selectedGroupOutlines = computed(() => {
  const gids = new Set();
  store.selection.forEach(l => { if (l.groupId) gids.add(l.groupId); });
  const el = store.canvasZoom; if (!el) return [];
  const r = el.getBoundingClientRect(); const z = store.zoom || 1, pan = store.pan; const out = [];
  gids.forEach(gid => { const g = store.layerGroups.find(x => x.id === gid); if (!g || !g.layerIds.every(id => store.selectedIds.includes(id))) return; const b = store.groupBox(gid); if (!b) return; const left = r.width / 2 + ((b.x - b.w / 2) * z + pan.x); const top = r.height / 2 + ((b.y - b.h / 2) * z + pan.y); out.push({ gid, style: { left: left + 'px', top: top + 'px', width: (b.w * z) + 'px', height: (b.h * z) + 'px' } }); });
  return out;
});
// ── Đổi tên nhóm (nhấn đúp badge group trên canvas) ──
const renamingGroupId = ref(null);
const groupRenameVal = ref('');
function startGroupRename(gid) { const g = store.groupOf(gid); if (!g) return; renamingGroupId.value = gid; groupRenameVal.value = g.name || ''; }
function commitGroupRename() { if (renamingGroupId.value) store.renameGroup(renamingGroupId.value, groupRenameVal.value); renamingGroupId.value = null; }
function cancelGroupRename() { renamingGroupId.value = null; }
function onTouchStart(e) {
  if (e.touches.length === 2) store.beginPinch(e.touches[0], e.touches[1]);
}
function onTouchMove(e) {
  if (e.touches.length === 2) {
    if (e.cancelable) e.preventDefault();
    store.pinchMove(e.touches[0], e.touches[1]);
  }
}
function onTouchEnd(e) {
  if (e.touches.length < 2) store.endPinch();
}
</script>
<template>
  <div class="studio-dark flex h-full w-full flex-col bg-ink-950 text-cream-100">
    <!-- [Đợt 0.1] Banner 3 trạng thái xác thực — thay thế 403 im lặng bằng thông báo rõ ràng -->
    <AuthNotice />
    <!-- ══ Top account bar: thông tin người dùng + đăng nhập/đăng xuất + điều hướng quản trị ══ -->
    <div class="flex items-center justify-between gap-3 border-b border-ink-700 bg-ink-900/90 px-3 py-2.5 sm:px-4">
      <div class="flex min-w-0 items-center gap-2.5">
        <template v-if="store.user">
          <img :src="store.user.avatar || '/images/placeholder.svg'" class="h-8 w-8 shrink-0 rounded-full bg-ink-700 object-cover ring-2 ring-brand-500/40" @error="$event.target.src = '/images/placeholder.svg'" alt="Ảnh đại diện">
          <div class="min-w-0 leading-tight">
            <p class="truncate text-sm font-semibold text-cream-50">{{ store.user.name }}</p>
            <p class="truncate text-[11px] text-cream-300/60">{{ store.user.role_label || store.user.role }}<span class="hidden sm:inline"> · {{ store.user.email }}</span></p>
          </div>
        </template>
        <template v-else>
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-amber-500/20 text-amber-200"><StudioIcon name="lock" size="h-4 w-4" /></span>
          <div class="leading-tight">
            <p class="text-sm font-semibold text-cream-50">Chưa đăng nhập</p>
            <p class="text-[11px] text-cream-300/60">Đăng nhập để tạo ảnh/video &amp; lưu dữ liệu.</p>
          </div>
        </template>
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <!-- Dự án + quick-apply gộp 1 tool-btn -->
        <div class="relative">
          <button @click="openApplyPopover" class="tool-btn" title="Dự án — áp dụng nhanh hoặc mở workspace quản lý"><StudioIcon name="kanban" size="h-3.5 w-3.5" /> <span class="hidden sm:inline">Dự án</span> <StudioIcon name="chevronDown" size="h-3 w-3" /></button>
          <div v-if="applyOpen" class="absolute left-0 top-full z-50 mt-1 w-72 rounded-md border border-ink-700 bg-ink-900 shadow-xl">
            <div class="p-2.5">
              <p class="mb-2 text-[11px] font-semibold text-cream-200">Áp dụng dự án cho phiên tạo ảnh</p>
              <div v-if="store.projectScope !== 'own'" class="mb-2 rounded-lg bg-amber-500/10 px-2 py-1.5 text-[10px] text-amber-200">Đang xem dự án chờ duyệt. Mở workspace để xem dự án của bạn.</div>
              <div v-else class="max-h-64 overflow-y-auto">
                <!-- Tối đa 20 dự án gần nhất, cuộn được (trước giới hạn cứng 8) -->
                <div v-for="p in store.projects.slice(0, 20)" :key="p.id" @click="store.applyProject(p); applyOpen = false" class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 transition hover:bg-ink-800" :class="store.appliedProjectId() === p.id ? 'bg-brand-600/20 text-brand-100' : 'text-cream-200'">
                  <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: p.color }"></span>
                  <span class="min-w-0 flex-1 truncate text-xs">{{ p.name }}</span>
                  <span class="shrink-0 text-[9px] text-cream-300/50">{{ p.status }}</span>
                  <StudioIcon v-if="store.appliedProjectId() === p.id" name="pin" size="h-3 w-3" class="text-brand-400" />
                </div>
                <p v-if="!store.projects.length" class="px-2 py-1 text-[10px] text-cream-300/40">Chưa có dự án nào.</p>
              </div>
              <button v-if="store.appliedProject" @click="store.unapplyProject(); applyOpen = false" class="mt-2 flex w-full items-center justify-center gap-1 rounded-lg border border-red-500/30 bg-red-600/10 px-2 py-1.5 text-[10px] font-semibold text-red-200 transition hover:bg-red-600/20">
                <StudioIcon name="pinOff" size="h-3 w-3" /> Ngắt dự án hiện tại
              </button>
              <button @click="applyOpen = false; projectsOpen = true" class="mt-2 flex w-full items-center justify-center gap-1 rounded-lg border border-ink-700 bg-ink-800 px-2 py-1.5 text-[10px] font-semibold text-cream-200 transition hover:bg-ink-700">
                <StudioIcon name="kanban" size="h-3 w-3" /> Mở workspace quản lý dự án
              </button>
            </div>
          </div>
          <div v-if="applyOpen" class="fixed inset-0 z-40" @click="applyOpen = false"></div>
        </div>
        <span v-if="store.appliedProject" class="tool-btn is-active hidden max-w-[13rem] md:inline-flex" :title="'Dự án hiện tại: ' + store.appliedProject.name + ' — ảnh/video tạo mới sẽ tự gắn vào dự án này'">
          <button type="button" @click="projectsOpen = true" class="flex min-w-0 items-center gap-1.5 truncate hover:text-white"><StudioIcon name="pin" size="h-3.5 w-3.5" /><span class="truncate">{{ store.appliedProject.name }}</span></button>
          <button type="button" @click="store.unapplyProject()" class="shrink-0 text-brand-200/70 hover:text-white" aria-label="Ngắt dự án hiện tại"><StudioIcon name="x" size="h-3.5 w-3.5" /></button>
        </span>
        <!-- [Đợt 1 — 2026-09-19] Badge credit cũ chỉ hiển thị con số (không biết gói, không có đường
             nâng cấp). Nay là nút mở popup "Gói & credit": gói hiện tại · credit còn lại · chi phí
             mỗi ảnh/video THEO GÓI · độ phân giải tối đa của gói · danh mục gói để đổi/nâng cấp. -->
        <div v-if="store.user" class="relative">
          <button type="button" class="tool-btn" :class="store.creditsLow ? 'is-active' : ''"
                  :title="'Gói & credit — còn ' + store.creditsLeft + ' credit' + (store.planName ? ' · gói ' + store.planName : '')"
                  @click="store.togglePlanPopover()">
            <StudioIcon name="coins" size="h-3.5 w-3.5" />
            {{ store.creditsLeft }}
            <span v-if="store.planName" class="hidden text-[10px] text-cream-300/85 lg:inline">{{ store.planName }}</span>
            <StudioIcon name="chevronDown" size="h-3 w-3" />
          </button>

          <div v-if="store.planOpen" role="dialog" aria-label="Gói và credit"
               class="fixed inset-x-3 top-16 z-50 rounded-md border border-ink-700 bg-ink-900 p-3 shadow-xl md:absolute md:inset-x-auto md:right-0 md:top-full md:mt-1 md:w-80">
            <template v-if="store.planStatus">
              <p class="flex items-center gap-2 text-xs font-semibold text-cream-50">
                <StudioIcon name="package" size="h-3.5 w-3.5" class="text-brand-300" />
                {{ store.planStatus.plan ? store.planStatus.plan.name : 'Chưa gán gói' }}
                <span v-if="store.planStatus.plan" class="ml-auto rounded-full bg-ink-700 px-2 py-0.5 text-[10px] text-cream-300">{{ store.planStatus.plan.price_label }}</span>
              </p>
              <p class="mt-1 text-[11px] text-cream-300/85">
                Còn <b class="text-cream-50">{{ store.planStatus.credits.balance }}</b> credit
                · hôm nay dùng {{ store.planStatus.credits.used_today }}
                <span v-if="store.planStatus.plan && store.planStatus.plan.expires_at"> · hạn {{ store.planStatus.plan.expires_at }}</span>
              </p>
              <div class="mt-2 grid grid-cols-2 gap-1.5 text-[10px]">
                <span class="rounded bg-ink-800 px-2 py-1 text-cream-200"><StudioIcon name="image" size="h-3 w-3" class="mr-1 inline" />{{ store.planCostImage }} credit/ảnh</span>
                <span class="rounded bg-ink-800 px-2 py-1 text-cream-200"><StudioIcon name="film" size="h-3 w-3" class="mr-1 inline" />{{ store.planCostVideo }} credit/video</span>
                <span class="rounded bg-ink-800 px-2 py-1 text-cream-200">Ảnh tối đa {{ store.planStatus.limits.image_resolution_cap }}</span>
                <span class="rounded bg-ink-800 px-2 py-1 text-cream-200">Video tối đa {{ store.planStatus.limits.video_resolution_cap }}p</span>
              </div>
              <p v-if="store.planStatus.warning" class="mt-2 rounded border border-amber-500/30 bg-amber-500/10 px-2 py-1.5 text-[10px] text-amber-200">{{ store.planStatus.warning }}</p>
              <button type="button" class="mt-2 flex w-full items-center justify-center gap-1 rounded-lg border border-ink-700 bg-ink-800 px-2 py-1.5 text-[10px] font-semibold text-cream-200 transition hover:bg-ink-700" @click="store.planCatalogOpen = !store.planCatalogOpen">
                <StudioIcon name="sparkles" size="h-3 w-3" /> {{ store.planCatalogOpen ? 'Thu gọn danh mục gói' : 'Xem gói khác / nâng cấp' }}
              </button>
              <div v-if="store.planCatalogOpen" class="mt-2 max-h-64 space-y-1.5 overflow-y-auto">
                <p class="rounded bg-ink-800 px-2 py-1 text-[10px] text-cream-300/85">Hệ thống chưa có cổng thanh toán trực tuyến — gói trả phí được kích hoạt ngay, vui lòng thanh toán theo hướng dẫn của FabrikAI.</p>
                <div v-for="p in store.planStatus.catalog" :key="p.id" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2">
                  <p class="flex items-center gap-2 text-[11px] font-semibold text-cream-100">
                    {{ p.name }}
                    <span class="ml-auto text-cream-300/85">{{ p.price_label }}</span>
                  </p>
                  <p class="mt-0.5 text-[10px] text-cream-300/85">{{ p.credits_per_month }} credit/tháng · tối đa {{ p.resolution_cap }}<span v-if="p.bonus_credits"> · +{{ p.bonus_credits }} tặng lần đầu</span></p>
                  <p v-if="p.tagline" class="mt-0.5 text-[10px] text-cream-300/75">{{ p.tagline }}</p>
                  <button type="button" class="mt-1.5 w-full rounded bg-brand-600 px-2 py-1 text-[10px] font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50"
                          :disabled="p.is_current || store.planBusy"
                          @click="store.subscribePlan(p.id)">
                    {{ p.is_current ? 'Đang dùng' : (p.is_free ? 'Chuyển sang gói này' : 'Kích hoạt gói này') }}
                  </button>
                </div>
              </div>
            </template>
            <p v-else class="text-[11px] text-cream-300/85">Đang tải thông tin gói…</p>
          </div>
          <div v-if="store.planOpen" class="fixed inset-0 z-40" @click="store.planOpen = false"></div>
        </div>
        <!-- [Đợt 0.6] đã gỡ nút "Cài đặt FabrikAI" (PWA) — bỏ PWA hoàn toàn (Q4) -->
        <a v-if="store.user && store.user.is_admin" href="/settings" class="tool-btn" title="Cài đặt AI Models & API Keys"><StudioIcon name="gear" size="h-3.5 w-3.5" /> <span class="hidden sm:inline">Cài đặt</span></a>
        <button v-if="store.user" type="button" @click="logout" class="tool-btn" title="Đăng xuất khỏi tài khoản">Đăng xuất</button>
        <a v-else href="/dang-nhap?redirect=/" class="rounded-full bg-amber-500 px-3 py-1 text-xs font-semibold text-black transition hover:bg-amber-400">Đăng nhập</a>
      </div>
    </div>
    <!-- toast (copy/status) -->
    <div v-if="store.flashMsg" class="pointer-events-none fixed left-1/2 bottom-5 z-[90] -translate-x-1/2 rounded-full px-4 py-2 text-xs font-semibold shadow-2xl" :class="store.flashType === 'error' ? 'bg-red-600 text-white' : 'bg-ink-800 text-cream-100 border border-brand-500/40'">{{ store.flashMsg }}</div>
    <!-- ══ Thư viện (SPA view nhúng trong /studio — thay thế trang riêng /api/library) ══ -->
    <LibraryApp v-if="store.studioView === 'library'" embedded @back="store.studioView = 'studio'" />
    <!-- [Đợt 0.6] Đã gỡ banner "Cài đặt FabrikAI" (PWA) — Chốt Q4 bỏ PWA hoàn toàn, nên không còn gì
         để trình duyệt bật lời mời cài đặt (đã bỏ service worker lẫn manifest). -->
    <!-- Mobile top bar (chỉ ở view studio) -->
    <div v-if="store.studioView !== 'library'" class="flex items-center justify-between border-b border-ink-700 bg-ink-900/80 px-3 py-2 lg:hidden">
      <button @click="menuOpen = true" class="icon-btn !h-9 !w-9 border border-ink-700 md:hidden" title="Mở menu công cụ" aria-label="Mở menu công cụ"><StudioIcon name="menu" size="h-5 w-5" /></button>
      <span class="flex items-center gap-1.5 font-display text-sm font-semibold"><StudioIcon name="sparkles" size="h-4 w-4" class="text-brand-400" /> Studio</span>
      <div class="flex items-center gap-1.5">
        <button @click="projectsOpen = true" class="icon-btn relative !h-9 !w-9 border border-ink-700" :title="store.appliedProject ? 'Dự án hiện tại: ' + store.appliedProject.name : 'Dự án'" aria-label="Dự án"><StudioIcon name="kanban" size="h-4 w-4" /><span v-if="store.appliedProject" class="absolute right-1 top-1 h-1.5 w-1.5 rounded-full bg-brand-400"></span></button>
        <button @click="outputOpen = true" class="icon-btn relative !h-9 !w-9 border border-ink-700" title="Kết quả" aria-label="Kết quả"><StudioIcon name="grid" size="h-4 w-4" /><span v-if="store.generations.length" class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full bg-brand-600 px-1 text-[9px] font-bold leading-none text-white">{{ store.generations.length }}</span></button>
      </div>
    </div>
    <div v-if="store.studioView !== 'library'" class="flex flex-1 overflow-hidden">
      <!-- Activity bar (VSCode-style) + Sidebar card của activity đang chọn (desktop) -->
      <nav class="activity-bar hidden md:flex" aria-label="Công cụ">
        <div class="mb-2 grid h-11 w-11 shrink-0 place-items-center text-brand-400" title="Studio"><StudioIcon name="bot" size="h-5 w-5" /></div>
        <!-- [Sửa 2026-09-17] MỌI nút ở đây sinh từ CẤU HÌNH owner quản lý (/admin → tab Giao diện).
             Trước đây Prompt Tạo Ảnh + Trợ lý thiết kế bị VIẾT CỨNG nên không xuất hiện trong
             danh sách quản trị ⇒ owner không đổi được nhãn/icon/thứ tự của chúng.
             Nút ghim đáy (menu Cài đặt) tách riêng ngay dưới vì cần thêm markup popup. -->
        <template v-for="a in activityBar" :key="a.id">
          <button v-if="a.kind === 'panel'" @click="selectActivity(a.id)" class="activity-btn" :class="activeActivity === a.id ? 'is-active' : ''" :title="a.label" :aria-label="a.label">
            <StudioIcon :name="a.icon" size="h-5 w-5" />
          </button>
          <button v-else-if="a.kind === 'action'" @click="runToolbarAction(a.id)" class="activity-btn" :class="isToolbarActionActive(a.id) ? 'is-active' : ''" :title="a.label" :aria-label="a.label">
            <StudioIcon :name="a.icon" size="h-5 w-5" />
          </button>
        </template>

        <!-- ⚙️ CÀI ĐẶT — GÓC TRÁI DƯỚI CÙNG ------------------------------------------------->
        <!-- Ghim đáy; nhãn/icon/ẩn-hiện lấy từ CẤU HÌNH (không đổi được vị trí). -->
        <div v-if="settingsEntry" class="relative mt-auto">
          <div v-if="settingsOpen" class="fixed inset-0 z-40" @click="settingsOpen = false"></div>
          <button @click="settingsOpen = !settingsOpen" class="activity-btn" :class="settingsOpen ? 'is-active' : ''" :title="settingsEntry.label + ' — preset, dữ liệu Trợ lý, thư viện'" :aria-label="settingsEntry.label" aria-haspopup="menu" :aria-expanded="settingsOpen ? 'true' : 'false'">
            <StudioIcon :name="settingsEntry.icon" size="h-5 w-5" />
          </button>
          <div v-if="settingsOpen" role="menu" class="absolute bottom-0 left-full z-50 ml-2 w-64 overflow-hidden rounded-xl border border-ink-700 bg-ink-900 p-1.5 shadow-2xl">
            <p class="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-cream-300/40">Cài đặt của tôi</p>
            <a href="/presets" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs text-cream-200 hover:bg-ink-800" role="menuitem"><StudioIcon name="template" size="h-4 w-4" class="text-brand-400" /> Cài đặt Preset (Prompt Templates)</a>
            <a href="/stylist-data" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs text-cream-200 hover:bg-ink-800" role="menuitem"><StudioIcon name="shirt" size="h-4 w-4" class="text-brand-400" /> Dữ liệu Trợ lý thiết kế</a>
            <a href="/model-settings?tab=model" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs text-cream-200 hover:bg-ink-800" role="menuitem"><StudioIcon name="user" size="h-4 w-4" class="text-brand-400" /> Khuôn mặt (model)</a>
            <a href="/model-settings?tab=pose" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs text-cream-200 hover:bg-ink-800" role="menuitem"><StudioIcon name="pose" size="h-4 w-4" class="text-brand-400" /> Dáng pose (người mẫu)</a>
            <button type="button" @click="settingsOpen = false; goLibrary()" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-xs text-cream-200 hover:bg-ink-800" role="menuitem"><StudioIcon name="grid" size="h-4 w-4" class="text-brand-400" /> Thư viện &amp; ảnh của tôi</button>
            <template v-if="store.user && store.user.is_admin">
              <div class="my-1 border-t border-ink-700"></div>
              <p class="px-2.5 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-cream-300/40">Quản trị</p>
              <a href="/settings" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs text-cream-200 hover:bg-ink-800" role="menuitem"><StudioIcon name="gear" size="h-4 w-4" class="text-amber-400" /> Cài đặt hệ thống (API key · model)</a>
              <a href="/admin" class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs text-cream-200 hover:bg-ink-800" role="menuitem"><StudioIcon name="coins" size="h-4 w-4" class="text-amber-400" /> Quản trị Owner (người dùng · gói cước)</a>
            </template>
          </div>
        </div>
      </nav>
      <aside v-if="store.leftPanelOpen" class="scrollbar-hide hidden w-72 shrink-0 flex-col overflow-y-auto border-r border-ink-700 bg-ink-900/70 lg:flex">
        <div class="panel-head border-b border-ink-700">
          <span class="panel-title"><StudioIcon :name="activeActivityDef.icon" size="h-4 w-4" class="text-brand-400" /> {{ activeActivityDef.label }}</span>
          <div class="flex shrink-0 items-center gap-1.5">
            <span class="text-[10px] text-cream-300/50">Credit {{ store.creditsLeft }}</span>
            <button @click="store.leftPanelOpen = false" class="grid h-7 w-7 shrink-0 place-items-center rounded-lg border border-ink-600 text-cream-300 transition hover:border-brand-400 hover:bg-ink-700 hover:text-white" title="Ẩn bảng trái" aria-label="Ẩn bảng trái"><StudioIcon name="chevronLeft" size="h-4 w-4" /></button>
          </div>
        </div>
        <div class="scrollbar-hide space-y-2.5 p-2.5">
          <component :is="c" v-for="(c,i) in panel" :key="i" />
        </div>
      </aside>
      <!-- Center canvas -->
      <main class="relative flex-1 min-w-0 p-3">
        <div class="relative flex h-full flex-col overflow-hidden rounded-lg border border-ink-700 bg-ink-900">
          <!-- ══ Toolbar dock (phía trên, full width) — desktop only ══ -->
          <div class="relative z-40 hidden min-h-12 shrink-0 items-center justify-center gap-2 overflow-x-auto overflow-y-hidden border-b border-ink-700/40 px-3 py-1.5 lg:flex">
            <ContextToolbar />
          </div>
          <!-- ══ Thân frame: vùng canvas + inspector Layers dock phải (desktop) ══ -->
          <div class="flex min-h-0 flex-1">
          <!-- ══ Vùng canvas (trái, flex-1) ══ -->
          <div class="relative flex-1 overflow-hidden" :class="bgClass" @dragover.prevent="dropOver = true" @dragleave="dropOver = false" @drop.prevent="onCanvasDrop($event)">
            <!-- Khung báo kéo-thả khi đang kéo ảnh vào canvas -->
            <div v-if="dropOver" class="pointer-events-none absolute inset-2 z-50 rounded-lg border-2 border-dashed border-brand-400 bg-brand-400/5"></div>
            <!-- Khung chọn nhóm (mỗi nhóm được chọn trọn = 1 đối tượng) -->
            <template v-for="o in selectedGroupOutlines" :key="o.gid"><div class="pointer-events-none absolute z-40 rounded-lg border-2 border-dashed border-violet-400 bg-violet-400/10" :style="o.style"></div></template>
            <!-- Thanh ngữ cảnh khi chọn nhiều layer: căn lề · chia đều · bắt điểm · xóa -->
            <!-- MultiSelectBar đã gộp vào ContextToolbar (hiện ở bottom toolbar) -->
          <!-- Floating tools (Crop/Select/Draw/Erase/Look) — mọi viewport; tự định vị theo màn hình -->
          <RegionTools />
          <!-- Inpaint mask overlay on canvas -->
          <CanvasMaskTools />
          <!-- Nút "Bỏ ảnh nguồn khỏi canvas" đã xóa — ảnh nguồn tự động clear khi chọn layer khác hoặc dùng nút X ở SourcePanel -->
          
          <div ref="canvasZoom" class="absolute inset-0" :class="store.selectTool ? 'cursor-crosshair active:cursor-crosshair' : 'cursor-grab active:cursor-grabbing'" style="touch-action:none" @wheel.prevent="store.wheelZoom($event)" @pointerdown="onCanvasBgDown($event)" @pointermove="onCanvasBgMove($event)" @pointerup="onCanvasBgUp($event)" @pointerleave="onCanvasBgUp($event)" @touchstart="onTouchStart($event)" @touchmove="onTouchMove($event)" @touchend="onTouchEnd($event)">
            <!-- Chế độ isolate (crop/inpaint/erase): chỉ khi có layer active — không có layer thì hiện composite (không ẩn hết) -->
            <div v-if="isolateActive && store.activeLayer" class="absolute inset-0">
              <div v-if="store.upscaleSrc" class="absolute left-1/2 top-1/2" :style="{ transform: 'translate(-50%, -50%) translate(' + store.pan.x + 'px, ' + store.pan.y + 'px) scale(' + store.zoom + ')' }">
                <div class="absolute left-0 top-0" :style="isolateLayerStyle">
                  <img ref="cvImg" :src="store.upscaleSrc" class="block max-h-[512px] max-w-[512px] min-w-0 select-none" :class="store.activeLayerId === store.highlightLayerId ? 'outline-2 outline-dashed outline-red-500' : ''" draggable="false" @load="store.onCanvasImgLoad()" />
                </div>
              </div>
              <p v-else class="text-sm text-cream-300/60">Chọn/hiện một ảnh (Nguồn hoặc Kết quả) để làm việc.</p>
            </div>
            <!-- Chế độ stack: composite tất cả layer đang hiển thị -->
            <div v-else class="absolute inset-0">
              <div class="absolute left-1/2 top-1/2" :style="{ transform: 'translate(-50%, -50%) translate(' + store.pan.x + 'px, ' + store.pan.y + 'px) scale(' + store.zoom + ')' }">
                <div v-for="(l, i) in store.visibleLayers" :key="l.id" class="absolute left-0 top-0" :style="layerStyle(l, i)" @pointerdown.stop="onLayerPointerDown(l, $event)">
                  <img :src="l.image" class="relative block max-h-[512px] max-w-[512px] cursor-move select-none" :title="l.groupId ? 'Thuộc nhóm — Alt+click để chỉnh sửa riêng layer này' : l.name" :class="[l.id === store.activeLayerId ? 'outline outline-2 -outline-offset-2 outline-sky-400' : (store.isSelected(l.id) ? 'outline outline-2 -outline-offset-2 outline-sky-400/70' : ''), l.id === store.highlightLayerId ? 'outline-2 outline-dashed outline-red-500' : '']" draggable="false" />
                  <template v-if="l.id === store.activeLayerId && !l.locked">
                    <div class="absolute -bottom-3 -right-3 h-4 w-4 cursor-nwse-resize rounded-sm border-2 border-white bg-brand-400 shadow" @pointerdown.stop="onScalePointerDown(l, $event)" title="Kéo để phóng to/thu nhỏ"></div>
                    <div class="absolute -top-7 left-1/2 h-4 w-4 -translate-x-1/2 cursor-crosshair rounded-full border-2 border-white bg-brand-400 shadow" @pointerdown.stop="onRotatePointerDown(l, $event)" title="Kéo để xoay"></div>
                  </template>
                  <!-- Nhãn nhóm trên canvas (icon + tên, nhấn đúp để đổi tên) -->
                  <template v-if="store.groupLabel(l)">
                    <span @dblclick.stop="startGroupRename(l.groupId)" class="absolute -top-7 left-0 flex max-w-[140px] items-center gap-1 rounded-full border border-ink-600 bg-ink-900/95 px-1.5 py-0.5 text-[9px] font-semibold text-cream-100 shadow" :title="'Nhóm: ' + store.groupLabel(l) + ' — nhấn đúp đổi tên · Alt+click layer trong nhóm để chỉnh sửa riêng'">
                      <StudioIcon name="group" size="h-2.5 w-2.5" class="text-brand-300"/>
                      <template v-if="renamingGroupId === l.groupId">
                        <input v-model="groupRenameVal" @keydown.enter.prevent="commitGroupRename" @keydown.esc="cancelGroupRename" @blur="commitGroupRename" class="w-24 bg-ink-800 text-[9px] text-cream-100 placeholder:text-cream-300/40 focus:outline-none" />
                      </template>
                      <span v-else class="truncate">{{ store.groupLabel(l) }}</span>
                    </span>
                  </template>
                </div>
              </div>
              <p v-if="!store.visibleLayers.length" class="absolute inset-0 grid place-items-center text-sm text-cream-300/60">Chọn/hiện một ảnh (Nguồn hoặc Kết quả) để làm việc.</p>
            </div>
            <!-- Overlay canvas xóa: bám đúng vùng ảnh hiển thị (chịu zoom/pan) -->
            <canvas v-if="store.eraseMode" ref="eraseOverlay" class="absolute z-30 cursor-crosshair rounded bg-red-500/10" :style="eraseOverlayStyle" @pointerdown.stop="store.beginEraseBrush($event)" @pointermove="store.eraseBrushMove($event)" @pointerup="store.endEraseBrush()" @pointerleave="store.endEraseBrush()"></canvas>
            <!-- Overlay canvas vẽ (paint): tô màu lên layer -->
            <div v-if="store.drawMode" class="pointer-events-none absolute z-40 rounded-full border border-white/80" :style="brushCursorStyle"></div>
            <canvas v-if="store.drawMode" ref="drawOverlay" class="absolute z-30 cursor-crosshair rounded" :style="drawOverlayStyle" @pointerdown.stop="store.beginDrawBrush($event)" @pointermove="store.drawBrushMove($event)" @pointerup="store.endDrawBrush()" @pointerleave="store.endDrawBrush()"></canvas>
            <!-- Vùng chọn quét (marquee) -->
            <div v-if="marquee" class="pointer-events-none absolute z-50 rounded border-2 border-brand-400 bg-brand-400/10" :style="marqueeStyle"></div>
            <!-- Đường guide khi bắt điểm (snap) -->
            <div v-if="store.snapX != null" class="pointer-events-none absolute inset-y-0 z-40 w-px bg-brand-400/80" :style="{ left: 'calc(50% + ' + (store.snapX * store.zoom + store.pan.x) + 'px)' }"></div>
            <div v-if="store.snapY != null" class="pointer-events-none absolute inset-x-0 z-40 h-px bg-brand-400/80" :style="{ top: 'calc(50% + ' + (store.snapY * store.zoom + store.pan.y) + 'px)' }"></div>
            <div v-if="store.cropMode && store.upscaleSrc" class="pointer-events-none absolute inset-0" style="z-index:30">
              <div class="absolute cursor-move select-none" style="pointer-events:auto; touch-action:none" :style="store.cropStyle()" @pointerdown.stop="store.cropStart($event,'move')" @dblclick="store.toggleCrop" title="Kéo để di chuyển · nhấn đúp để hủy">
                <div class="pointer-events-none absolute inset-0 border-2 border-dashed border-brand-300" style="box-shadow: 0 0 0 9999px rgba(0,0,0,0.55);"></div>
                <div class="pointer-events-none absolute inset-0 opacity-30">
                  <div class="absolute left-1/3 top-0 h-full w-px bg-brand-300/60"></div>
                  <div class="absolute left-2/3 top-0 h-full w-px bg-brand-300/60"></div>
                  <div class="absolute left-0 top-1/3 h-px w-full bg-brand-300/60"></div>
                  <div class="absolute left-0 top-2/3 h-px w-full bg-brand-300/60"></div>
                </div>
                <div class="pointer-events-none absolute -bottom-6 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-ink-900/90 px-2 py-0.5 text-[10px] font-semibold text-brand-200">{{ store.cropSizeLabel() }}</div>
                <div class="absolute -left-2 -top-2 h-4 w-4 cursor-nwse-resize rounded-sm border-2 border-white bg-brand-400 shadow" style="pointer-events:auto; touch-action:none" @pointerdown.stop="store.cropStart($event,'nw')" @dblclick.stop></div>
                <div class="absolute -right-2 -top-2 h-4 w-4 cursor-nesw-resize rounded-sm border-2 border-white bg-brand-400 shadow" style="pointer-events:auto; touch-action:none" @pointerdown.stop="store.cropStart($event,'ne')" @dblclick.stop></div>
                <div class="absolute -bottom-2 -left-2 h-4 w-4 cursor-nesw-resize rounded-sm border-2 border-white bg-brand-400 shadow" style="pointer-events:auto; touch-action:none" @pointerdown.stop="store.cropStart($event,'sw')" @dblclick.stop></div>
                <div class="absolute -bottom-2 -right-2 h-4 w-4 cursor-nwse-resize rounded-sm border-2 border-white bg-brand-400 shadow" style="pointer-events:auto; touch-action:none" @pointerdown.stop="store.cropStart($event,'se')" @dblclick.stop></div>
              </div>
            </div>

          </div>
          <!-- Mobile: strip chip layer mini (desktop dùng LayersPanel dock phải) -->
          <div v-if="store.canvasLayers.length && !store.inspectorOpen" class="absolute right-3 top-3 z-30 flex flex-col gap-1 lg:hidden">
            <button v-for="l in store.layersFrontFirst" :key="l.id" @click="store.selectLayer(l)" class="h-7 w-7 shrink-0 overflow-hidden rounded-md transition" :class="store.activeLayerId === l.id ? 'ring-2 ring-brand-400' : 'opacity-60 hover:opacity-100'" :title="l.name">
              <img :src="l.image" class="h-7 w-7 object-cover" />
            </button>
            <button @click="store.deleteLayer(store.activeLayer)" :disabled="!store.activeLayer" class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-ink-900/85 text-red-300 shadow hover:bg-red-600 hover:text-white disabled:opacity-30" title="Xóa layer khỏi canvas" aria-label="Xóa layer khỏi canvas"><StudioIcon name="trash" size="h-3.5 w-3.5" /></button>
          </div>
          <!-- variant slider (bottom, only when multiple variants) -->
          <div v-if="store.showBatch && store.activeBatch.length > 1" class="batch-slider absolute bottom-14 left-1/2 z-20 -translate-x-1/2 rounded-lg bg-ink-900/90 px-2.5 py-1.5 shadow-xl">
            <div class="flex items-center gap-1.5">
              <span class="text-[10px] text-cream-300/60">{{ store.activeBatch.length }} biến thể</span>
              <button v-for="v in store.activeBatch" :key="v.id" @click="store.select(v)" class="relative h-12 w-12 overflow-hidden rounded-lg border-2 transition-all duration-300" :class="store.previewId === v.id ? 'border-brand-500 scale-105' : 'border-ink-700 hover:border-brand-400'">
                <template v-if="v.status === 'completed' && v.media_url">
                  <img :src="v.media_url" class="batch-thumb h-full w-full bg-ink-900 object-cover" loading="lazy">
                </template>
                <template v-else>
                  <div class="skeleton-shimmer absolute inset-0"></div>
                  <span class="absolute inset-0 grid place-items-center bg-black/40 text-[9px] font-semibold text-cream-200">
                    <span v-if="['pending','processing'].includes(v.status)" class="batch-dot"></span>
                    <span v-else-if="v.status === 'failed'"><StudioIcon name="alertTriangle" size="h-4 w-4" /></span>
                    <span v-else-if="v.status === 'cancelled'"><StudioIcon name="ban" size="h-4 w-4" /></span>
                  </span>
                </template>
              </button>
              <button @click="store.hideBatch()" class="ml-1 grid h-6 w-6 place-items-center rounded-full bg-ink-700 text-cream-200 transition-colors hover:bg-red-600" title="Ẩn biến thể" aria-label="Ẩn biến thể"><StudioIcon name="x" size="h-3.5 w-3.5" /></button>
            </div>
          </div>
          </div><!-- /vùng canvas -->
          <!-- ══ Inspector Layers: dock phải (desktop) · drawer đè canvas (mobile) ══ -->
          <div v-if="store.inspectorOpen" class="absolute inset-y-0 right-0 z-50 lg:static lg:z-auto">
            <LayersPanel />
          </div>
          </div><!-- /flex row: canvas + inspector -->
          <!-- ══ Toolbar ngữ cảnh floating trên mobile (12px trên status bar) ══ -->
          <div v-if="toolActive" class="absolute bottom-12 left-1/2 z-40 max-w-[calc(100%-1rem)] -translate-x-1/2 lg:hidden">
            <ContextToolbar />
          </div>
          <!-- ══ Status bar dock dưới khung canvas ══ -->
          <CanvasStatusBar />
        </div>
      </main>
      <!-- Right dock (desktop): chỉ hiển thị Outputs; Nguồn ảnh & Thư viện là nút HÀNH ĐỘNG ở rail -->
      <aside v-if="store.outputDockOpen" class="scrollbar-hide hidden w-[115px] shrink-0 flex-col border-l border-ink-700 bg-ink-900/70 lg:flex">
        <div class="panel-head shrink-0 border-b border-ink-700">
          <span class="panel-title"><StudioIcon name="grid" size="h-3.5 w-3.5" class="text-brand-400" /> Outputs</span>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto scrollbar-hide p-2">
          <OutputModule />
        </div>
      </aside>
      <nav class="activity-bar right hidden lg:flex" aria-label="Nguồn · Thư viện · Outputs">
        <button @click="store.sourcePickerOpen = true" class="activity-btn" title="Nguồn ảnh — chọn ảnh từ thư viện/sản phẩm" aria-label="Nguồn ảnh">
          <StudioIcon name="imagePlus" size="h-5 w-5" />
        </button>
        <button @click="goLibrary" class="activity-btn" title="Thư viện — xem ảnh đã tạo & file tải lên" aria-label="Thư viện">
          <StudioIcon name="library" size="h-5 w-5" />
        </button>
        <button @click="store.toggleOutputDock()" class="activity-btn mt-auto" :class="store.outputDockOpen ? 'is-active' : ''" title="Outputs — bật/tắt danh sách" aria-label="Outputs">
          <StudioIcon name="grid" size="h-5 w-5" />
          <span v-if="store.generations.length" class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-brand-600 px-1 text-[8px] font-bold leading-none text-white">{{ store.generations.length }}</span>
        </button>
      </nav>
    </div>

    <!-- Mobile menu overlay -->
    <div v-if="menuOpen" role="dialog" aria-modal="true" aria-label="Menu Studio" class="fixed inset-0 z-50 lg:hidden" @click="menuOpen=false">
      <div class="absolute inset-0 bg-black/60"></div>
      <div class="absolute left-0 top-0 h-full w-80 scrollbar-hide overflow-y-auto bg-ink-900 p-3" @click.stop>
        <div class="panel-head -mx-3 mb-2 border-b border-ink-700 px-3"><span class="panel-title"><StudioIcon name="sparkles" size="h-4 w-4" class="text-brand-400" /> Studio</span><button @click="menuOpen=false" class="icon-btn !h-8 !w-8 bg-ink-800" title="Đóng menu" aria-label="Đóng menu"><StudioIcon name="x" size="h-4 w-4" /></button></div>
        <div class="mb-3 flex gap-1.5 overflow-x-auto">
          <!-- [Sửa 2026-09-17] Panel + nút popup đều sinh từ CÙNG cấu hình owner quản lý, nên
               mobile không còn bản sao viết cứng lệch khỏi desktop. -->
          <template v-for="a in activityBar" :key="'m-' + a.id">
            <button v-if="a.kind === 'panel'" @click="selectActivity(a.id)" class="flex shrink-0 flex-col items-center gap-0.5 rounded-lg px-2.5 py-1.5 text-[10px] font-semibold transition-colors" :class="activeActivity === a.id ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-300/70'">
              <StudioIcon :name="a.icon" size="h-4 w-4" /> {{ a.label }}
            </button>
            <button v-else-if="a.kind === 'action'" @click="runToolbarAction(a.id); menuOpen = false" class="flex shrink-0 flex-col items-center gap-0.5 rounded-lg px-2.5 py-1.5 text-[10px] font-semibold transition-colors" :class="isToolbarActionActive(a.id) ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-300/70'" :title="a.label">
              <StudioIcon :name="a.icon" size="h-4 w-4" /> {{ a.label }}
            </button>
          </template>
          <!-- [Đợt 0.5] Nguồn ảnh + Thư viện: trước đây chỉ có nút ở rail hidden lg:flex (≥1024px),
               nên người dùng điện thoại KHÔNG có cách mở. Nay cho vào drawer mobile. -->
          <button @click="menuOpen = false; store.sourcePickerOpen = true" class="flex shrink-0 flex-col items-center gap-0.5 rounded-lg px-2.5 py-1.5 text-[10px] font-semibold transition-colors" :class="store.sourcePickerOpen ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-300/70'" title="Nguồn ảnh — chọn ảnh từ thư viện/sản phẩm">
            <StudioIcon name="imagePlus" size="h-4 w-4" /> Nguồn ảnh
          </button>
          <button @click="menuOpen = false; goLibrary()" class="flex shrink-0 flex-col items-center gap-0.5 rounded-lg px-2.5 py-1.5 text-[10px] font-semibold transition-colors bg-ink-800 text-cream-300/70" title="Thư viện — xem ảnh đã tạo & file tải lên">
            <StudioIcon name="library" size="h-4 w-4" /> Thư viện
          </button>
          <!-- [Yêu cầu 2026-09-17] Trên desktop là nút Cài đặt ở góc trái dưới; mobile phải có lối vào tương đương. -->
          <a v-if="settingsEntry" href="/presets" class="flex shrink-0 flex-col items-center gap-0.5 rounded-lg bg-ink-800 px-2.5 py-1.5 text-[10px] font-semibold text-cream-300/70 transition-colors" :title="settingsEntry.label + ' — preset prompt của bạn'">
            <StudioIcon :name="settingsEntry.icon" size="h-4 w-4" /> {{ settingsEntry.label }}
          </a>
          <a href="/model-settings" class="flex shrink-0 flex-col items-center gap-0.5 rounded-lg bg-ink-800 px-2.5 py-1.5 text-[10px] font-semibold text-cream-300/70 transition-colors" title="Cài đặt — khuôn mặt & dáng pose của bạn">
            <StudioIcon name="user" size="h-4 w-4" /> Mặt &amp; dáng
          </a>
        </div>
        <div class="space-y-3"><component :is="c" v-for="(c,i) in panel" :key="i" /></div>
      </div>
    </div>
    <!-- Mobile outputs overlay: [Đợt 0.5] trước đây drawer này RỖNG (div trong suông) dù OutputModule
         đã import sẵn — người dùng điện thoại bấm "Kết quả" nhận một ngăn trống. Nay render đúng lưới kết quả. -->
    <div v-if="outputOpen" role="dialog" aria-modal="true" aria-label="Kết quả tạo ảnh" class="fixed inset-0 z-50 lg:hidden">
      <div class="absolute inset-0 bg-black/60"></div>
      <div class="absolute right-0 top-0 flex h-full w-80 flex-col scrollbar-hide overflow-y-auto bg-ink-900 p-3" @click.stop>
        <div class="panel-head -mx-3 mb-2 flex shrink-0 items-center justify-between border-b border-ink-700 px-3">
          <span class="panel-title"><StudioIcon name="grid" size="h-3.5 w-3.5" class="text-brand-400" /> Kết quả</span>
          <button @click="outputOpen = false" class="icon-btn !h-8 !w-8 bg-ink-800" title="Đóng" aria-label="Đóng"><StudioIcon name="x" size="h-4 w-4" /></button>
        </div>
        <div class="min-h-0 flex-1">
          <OutputModule />
        </div>
      </div>
    </div>
    <!-- GalleryModal: xem ảnh lớn (bấm vào output trong dock phải) -->
    <GalleryModal v-if="store.viewer" />
    <!-- SourcePickerPopup: popup chọn nguồn ảnh (nút "Nguồn ảnh" ở activity bar) -->
    <SourcePickerPopup v-if="store.sourcePickerOpen" v-model="store.sourcePickerOpen" />
    <!-- ProjectWorkspace: popup quản lý dự án (nút "Dự án" ở mobile bar / chip dự án / popover apply) -->
    <ProjectWorkspace v-if="projectsOpen" v-model="projectsOpen" />
    <!-- Prompt Tạo Ảnh (ConceptCard): popup độc lập — nút sparkles ở right toolbar (dưới cùng) -->
    <!-- ══ Command Palette (VSCode-style) ══ -->
    <div v-if="paletteOpen" role="dialog" aria-modal="true" aria-label="Bảng lệnh" class="fixed inset-0 z-[110] flex items-start justify-center pt-[12vh]" @click.self="paletteOpen = false">
      <div class="w-full max-w-lg overflow-hidden rounded-xl border border-ink-600 bg-ink-900 shadow-2xl">
        <div class="flex items-center gap-2 border-b border-ink-700 px-3 py-2.5">
          <StudioIcon name="search" size="h-4 w-4" class="text-cream-300/60" />
          <input ref="paletteInput" v-model="paletteQuery" class="min-w-0 flex-1 bg-transparent text-sm text-cream-100 placeholder:text-cream-300/40 focus:outline-none" placeholder="Nhập lệnh…" @keydown.esc="paletteOpen = false" />
          <span class="rounded border border-ink-700 px-1.5 py-0.5 text-[10px] text-cream-300/40">esc</span>
        </div>
        <div class="max-h-[50vh] overflow-y-auto p-1.5">
          <button v-for="cmd in paletteCommands" :key="cmd.id" @click="runCommand(cmd)" class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm text-cream-100 transition hover:bg-brand-600/25">
            <StudioIcon :name="cmd.icon" size="h-4 w-4" class="shrink-0 text-brand-300" />
            <span class="min-w-0 flex-1 truncate">{{ cmd.label }}</span>
            <span class="shrink-0 text-[10px] text-cream-300/40">{{ cmd.hint }}</span>
          </button>
          <p v-if="!paletteCommands.length" class="px-2.5 py-6 text-center text-xs text-cream-300/50">Không tìm thấy lệnh.</p>
        </div>
      </div>
    </div>
    <ConceptCard popup />
    <!-- Trợ lý thiết kế (StylistCard): popup độc lập — nút shirt ở right toolbar (dưới cùng) -->
    <StylistCard popup v-model="stylistPopupOpen" />
  </div>
</template>