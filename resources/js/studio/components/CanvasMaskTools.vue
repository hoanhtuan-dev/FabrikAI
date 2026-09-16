<script setup>
import { computed, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();

// Hiển thị overlay khi: đang chỉnh (rect/brush) HOẶC đã lưu (done — vẫn xem được vùng đã chọn)
const visible = computed(() => store.upscaleSrc && (store.inpaintMaskMode !== 'none' || store.inpaintMaskDone));
const editing = computed(() => store.inpaintMaskMode !== 'none');

// ── Overlay được NEO VÀO KHUNG LAYER (cùng CSS transform với <img>) ──
// Toàn bộ visual vẽ ở toạ độ KHUNG ẢNH (px layout 0..baseW/H) bên trong 1 wrapper lặp lại đúng
// chuỗi transform của <img> isolate (pan/zoom + layerTransformStyle). CSS tự scale/mirror mọi thứ
// nên preview KHÔNG BAO GIỜ trôi dù zoom/pan/transform đổi — không phụ thuộc getBoundingClientRect.
const frame = computed(() => store.frameLayout); // {w,h} layout px của <img>
const anchor = computed(() => {
  const l = store.activeLayer;
  const f = frame.value;
  if (!l || !f || !store.upscaleSrc) return null;
  return {
    outer: 'translate(' + (store.pan.x || 0) + 'px, ' + (store.pan.y || 0) + 'px) scale(' + (store.zoom || 1) + ')',
    inner: store.layerTransformStyle(l),
    w: f.w, h: f.h,
  };
});
// Nghịch đảo tỉ lệ hiển thị (zoom × layer scale): giữ nét vẽ/điểm neo có độ lớn ổn định trên màn hình.
const invScale = computed(() => {
  const l = store.activeLayer;
  const sc = Math.max(0.05, Math.min(8, Number(l && l.scale) || 1)) * Math.max(0.05, store.zoom || 1);
  return 1 / sc;
});
// Bán kính điểm neo (path/curve) giữ ~3px màn hình bất kể zoom/scale.
const circleR = computed(() => Math.max(0.5, 3 * invScale.value));
// Màu node theo KIỂU: smooth (tím) · cusp (cam) · sharp (hồng); node 0 highlight xanh khi hover snap.
function nodeFill(p, i) {
  if (i === 0 && store.inpaintPathCloseHover) return '#34d399';
  if (p.kind === 'cusp') return '#fb923c';
  if (p.kind === 'sharp') return '#f43f5e';
  return '#a78bfa';
}
function nodeKindLabel(p) { return p.kind === 'cusp' ? 'Cusp' : p.kind === 'sharp' ? 'Góc nhọn' : 'Mượt'; }

// Style vùng chữ nhật (mask box / crop-like) theo % KHUNG ẢNH — không cần metrics container.
function boxFrameStyle() {
  const b = store.inpaintMaskBox || { x: 0, y: 0, w: 0, h: 0 };
  return { left: (b.x * 100) + '%', top: (b.y * 100) + '%', width: (b.w * 100) + '%', height: (b.h * 100) + '%' };
}
const hasBox = computed(() => (store.inpaintMaskBox.w || 0) >= 0.02 && (store.inpaintMaskBox.h || 0) >= 0.02);

// Làm mờ vùng NGOÀI vùng chọn: invert mask rồi multiply → vùng chọn sáng rõ, phần đảo (ngoài) tối đi.
// mask quy ước: đen=vùng chọn, trắng=ngoài → invert(1) đổi thành trắng=vùng chọn(giữ sáng), đen=ngoài(tối).
const showDimOverlay = computed(() => {
  if (!store.inpaintBrushData) return false;
  const mode = store.inpaintMaskMode;
  return mode === 'freehand' || mode === 'path' || mode === 'magic' || mode === 'rect';
});
const invertedRect = computed(() => store.inpaintMaskMode === 'rect' && !!store.inpaintBrushData);

// Kích thước buffer canvas brush = ĐÚNG kích thước khung layer (baseW/baseH, cap 1024) → khớp mask & vùng chọn.
const brushCanvasSize = computed(() => {
  const f = frame.value;
  let w = 512, h = 512;
  if (f && f.w && f.h) {
    const cap = 1024;
    const s = Math.min(1, cap / f.w, cap / f.h);
    w = Math.max(1, Math.round(f.w * s));
    h = Math.max(1, Math.round(f.h * s));
  }
  return { width: w, height: h };
});

// Bấm ngoài box (container) → tạo vùng mới; brush → vẽ mask; freehand → vẽ tự do. Chỉ khi ĐANG chỉnh.
function onFhMove(e) { store.freehandMove(e); }
function onFhEnd() { window.removeEventListener('pointermove', onFhMove); window.removeEventListener('pointerup', onFhEnd); window.removeEventListener('pointercancel', onFhEnd); store.freehandStop(); }
function onPointerDown(e) {
  if (!editing.value) return;
  e.preventDefault();
  if (store.inpaintMaskMode === 'freehand') {
    store.freehandStart(e);
    window.addEventListener('pointermove', onFhMove);
    window.addEventListener('pointerup', onFhEnd);
    window.addEventListener('pointercancel', onFhEnd);
    return;
  }
  if (store.inpaintMaskMode === 'path') {
    store.pathDown(e); // click = neo; kéo (move) = tay điều khiển Bezier
    const mo = (ev) => store.pathMove(ev);
    const up = () => { store.pathUp(); window.removeEventListener('pointermove', mo); window.removeEventListener('pointerup', up); window.removeEventListener('pointercancel', up); };
    window.addEventListener('pointermove', mo);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
    return;
  }
  if (store.inpaintMaskMode === 'magic') {
    store.magicWand(e);
    return;
  }
  store.inpaintMaskStart(e);
}
// Hover (không bấm) trong mode path → cập nhật "snap để đóng kín" (highlight node đầu + guide).
function onPointerMove(e) { if (store.inpaintMaskMode === 'path') store.pathHover(e); }

// ── Toạ độ SVG trong KHUNG ẢNH (px layout) — normalized (0..1) × baseW/baseH ──
// Toạ độ giữ SỐ THẬP PHÂN (0.1px layout) — nếu làm tròn về px layout nguyên thì khi phóng to
// mỗi bước 1px layout = nhiều px màn hình → đường gãy/răng cưa.
const fmt1 = (v) => Math.round(v * 10) / 10;
const pt = (p) => {
  const f = frame.value;
  if (!f) return '';
  return fmt1(p.nx * f.w) + ',' + fmt1(p.ny * f.h);
};
const pathPixels = (pts) => (pts || []).map(pt).join(' ');
const freehandPoints = computed(() => pathPixels(store.inpaintFreehandPoints));
const freehandPaths = computed(() => store.inpaintFreehandPaths.map((pts) => pathPixels(pts)));
// Đường cong path/curve: lấy mẫu Bézier bằng TAY ĐIỀU KHIỂN (như Krita): c1 = a + a.out · c2 = b + b.in.
// closed=false (đang vẽ, ĐỂ MỞ — không đóng vòng sẵn); closed=true (vùng ĐÃ đóng → đóng kín preview).
const pathSmooth = (pts, closed = true) => {
  const f = frame.value;
  if (!f || !pts || pts.length < 2) return '';
  const P = pts.map((p) => ({ x: p.nx * f.w, y: p.ny * f.h, ox: (p.ox || 0) * f.w, oy: (p.oy || 0) * f.h, ix: (p.ix || 0) * f.w, iy: (p.iy || 0) * f.h }));
  const n = P.length, SAMPLES = 20, out = [];
  const segs = closed ? n : n - 1; // đang vẽ: chỉ nối các điểm kế tiếp, CHƯA quay về điểm đầu
  for (let i = 0; i < segs; i++) {
    const a = P[i], b = P[(i + 1) % n];
    const c1x = a.x + a.ox, c1y = a.y + a.oy, c2x = b.x + b.ix, c2y = b.y + b.iy;
    for (let s = 0; s < SAMPLES; s++) {
      const t = s / SAMPLES, mt = 1 - t;
      const x = mt * mt * mt * a.x + 3 * mt * mt * t * c1x + 3 * mt * t * t * c2x + t * t * t * b.x;
      const y = mt * mt * mt * a.y + 3 * mt * mt * t * c1y + 3 * mt * t * t * c2y + t * t * t * b.y;
      out.push(fmt1(x) + ',' + fmt1(y));
    }
  }
  return out.join(' ');
};
const pathSmoothPixels = computed(() => pathSmooth(store.inpaintPathPoints, false)); // đang vẽ: MỞ
const pathRegionsPixels = computed(() => store.inpaintPathRegions.map((pts) => pathSmooth(pts))); // đã đóng: kín
// Vị trí nút "Sửa" (gần điểm đường bao người dùng đang hover) — giữ kích thước ổn định trên màn hình.
const hoverRegionTransform = computed(() => {
  const pt = store._pathHoverPoint;
  if (!pt || store._pathHoverRegion < 0 || !anchor.value) return '';
  const x = pt.nx * anchor.value.w, y = pt.ny * anchor.value.h;
  return 'translate(' + fmt1(x) + ', ' + fmt1(y) + ') scale(' + invScale.value + ')';
});

// ── Brush: canvas overlay nét vẽ mask (đỏ 60%) — nằm TRONG khung layer, co giãn đúng theo ảnh ──
const metricsTick = ref(0);
const brushCanvas = ref(null);
let attachedEl = null;
function attachBrush(forceClear = false) {
  const el = store.inpaintMaskMode === 'brush' ? (brushCanvas.value || null) : null;
  if (el && el === attachedEl && !forceClear) return;
  store.attachBrushCanvas(el);
  attachedEl = el;
}

watch(() => store.inpaintMaskMode, () => { nextTick(() => attachBrush()); });
watch(() => store.upscaleSrc, () => {
  if (store.inpaintMaskMode !== 'brush') return;
  store.attachBrushCanvas(null); attachedEl = null;
  store.inpaintBrushData = '';
  nextTick(() => { metricsTick.value++; attachBrush(true); });
});
// Khi tỉ lệ canvas brush đổi (ảnh load xong) → re-attach đúng tỉ lệ
watch(brushCanvasSize, () => {
  if (store.inpaintMaskMode !== 'brush') return;
  nextTick(() => attachBrush(true));
});

function onKeyDown(e) {
  if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) {
    if (store.inpaintMaskMode === 'brush') {
      const t = e.target;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
      e.preventDefault(); store.undoInpaintBrush();
    }
  }
}
function onResize() { nextTick(() => { metricsTick.value++; }); }
onMounted(() => { window.addEventListener('keydown', onKeyDown); window.addEventListener('resize', onResize); });
onBeforeUnmount(() => { store.attachBrushCanvas(null); attachedEl = null; window.removeEventListener('keydown', onKeyDown); window.removeEventListener('resize', onResize); window.removeEventListener('pointermove', onFhMove); window.removeEventListener('pointerup', onFhEnd); window.removeEventListener('pointercancel', onFhEnd); });
</script>
<template>
  <div v-if="visible" class="absolute inset-0 z-31 select-none" :class="editing ? 'cursor-crosshair' : 'cursor-default'"
       style="touch-action:none; -webkit-user-select:none; user-select:none; -webkit-touch-callout:none;"
       @pointerdown="onPointerDown" @pointermove="onPointerMove" @pointerleave="store.inpaintPathCloseHover = false" @wheel.prevent="store.wheelZoom($event)" @contextmenu.prevent @dragstart.prevent>

    <!-- ══ Neo theo khung <img> isolate: wrapper lặp đúng transform pan/zoom + transform layer ══ -->
    <div v-if="anchor" class="pointer-events-none absolute left-1/2 top-1/2" :style="{ width: '0px', height: '0px', transform: anchor.outer, transformOrigin: 'center' }">
      <div class="absolute left-0 top-0" :style="{ width: anchor.w + 'px', height: anchor.h + 'px', transform: anchor.inner, transformOrigin: 'center' }">

        <!-- Brush overlay canvas: hiển thị nét vẽ mask (đỏ 60%) ngay trên ảnh khi ĐANG vẽ -->
        <canvas v-if="store.inpaintMaskMode === 'brush' && store.upscaleSrc" ref="brushCanvas"
                :width="brushCanvasSize.width" :height="brushCanvasSize.height"
                class="pointer-events-none absolute left-0 top-0 h-full w-full rounded-lg"></canvas>

        <!-- Làm mờ vùng ĐẢO NGƯỢC (ngoài vùng chọn) — magic/freehand/path + rect đã đảo -->
        <img v-if="showDimOverlay" :src="'data:image/png;base64,' + store.inpaintBrushData"
             class="pointer-events-none absolute left-0 top-0 h-full w-full rounded-lg mix-blend-multiply"
             :style="{ filter: 'invert(1)', opacity: 0.55 }" alt="Vùng chọn" />

        <!-- Freehand (lasso): hiển thị TẤT CẢ nét đã hoàn thành + nét đang kéo (GIMP-style) -->
        <svg v-if="store.inpaintMaskMode === 'freehand' && (store.inpaintFreehandPaths.length || store.inpaintFreehandPoints.length > 1)"
             class="pointer-events-none absolute left-0 top-0 h-full w-full">
          <template v-for="(path, idx) in freehandPaths" :key="idx">
            <polyline :points="path" fill="none" stroke="#f43f5e" :stroke-width="2 * invScale" stroke-linejoin="round" stroke-linecap="round" />
            <polygon :points="path" fill="rgba(244,63,94,0.12)" stroke="none" />
          </template>
          <polyline v-if="store.inpaintFreehandPoints.length > 1" :points="freehandPoints" fill="none" stroke="#f43f5e" :stroke-width="2 * invScale" stroke-linejoin="round" stroke-linecap="round" />
        </svg>

        <!-- Path (curve) select: hiển thị TẤT CẢ vùng đã đóng + path đang vẽ -->
        <svg v-if="store.inpaintMaskMode === 'path' && (store.inpaintPathRegions.length || store.inpaintPathPoints.length > 0)"
             class="pointer-events-none absolute left-0 top-0 h-full w-full">
          <template v-for="(reg, idx) in pathRegionsPixels" :key="'r'+idx">
            <polyline :points="reg" fill="none" stroke="#a78bfa" :stroke-width="2 * invScale" stroke-linejoin="round" stroke-linecap="round" />
            <polygon :points="reg" fill="rgba(167,139,250,0.12)" stroke="none" />
            <!-- Node của vùng ĐÃ ĐÓNG: nhỏ + mờ, có thể BẤM để mở lại chỉnh sửa (chỉ khi không đang vẽ) -->
            <template v-if="store.inpaintPathPoints.length === 0" v-for="(p2, j) in (store.inpaintPathRegions[idx] || [])" :key="'rn'+j">
              <circle :cx="p2.nx * (anchor.w)" :cy="p2.ny * (anchor.h)" :r="circleR * 0.5" fill="#a78bfa" :opacity="0.55"
                      style="pointer-events:auto; cursor:pointer" :title="'Nhấp để chỉnh sửa lại vùng này' + (p2.kind === 'cusp' ? ' · Cusp' : p2.kind === 'sharp' ? ' · Góc nhọn' : '')" />
            </template>
          </template>
          <!-- Đang vẽ: ĐƯỜNG MỞ (chưa đóng vòng) — nối các điểm kế tiếp, không quay về đầu -->
          <polyline v-if="store.inpaintPathPoints.length > 1" :points="pathSmoothPixels" fill="none" stroke="#a78bfa" :stroke-width="2 * invScale" stroke-linejoin="round" stroke-linecap="round" />
          <!-- Guide nối điểm cuối → điểm BẮT ĐẦU khi hover snap để đóng kín (Krita) -->
          <line
            v-if="store.inpaintPathCloseHover && store.inpaintPathPoints.length >= 3"
            :x1="store.inpaintPathPoints[store.inpaintPathPoints.length - 1].nx * (anchor.w)"
            :y1="store.inpaintPathPoints[store.inpaintPathPoints.length - 1].ny * (anchor.h)"
            :x2="store.inpaintPathPoints[0].nx * (anchor.w)"
            :y2="store.inpaintPathPoints[0].ny * (anchor.h)"
            stroke="#34d399" :stroke-width="1.5 * invScale" stroke-dasharray="5 4" stroke-linecap="round" />
          <template v-for="(p, i) in store.inpaintPathPoints" :key="'p'+i">
            <!-- Tay điều khiển Bezier (Krita): out (xanh) + in (hồng); kéo node/tay để chỉnh, right-click node = xóa.
                 Ẩn tay SUY BIẾN (độ dài ~0) để lần nhấp đầu hiện node sạch & kéo node không dính tay lệch. -->
            <template v-if="Math.hypot(p.ox||0, p.oy||0) * anchor.w > 2">
              <line :x1="p.nx * (anchor.w)" :y1="p.ny * (anchor.h)" :x2="(p.nx + (p.ox||0)) * anchor.w" :y2="(p.ny + (p.oy||0)) * anchor.h" stroke="#38bdf8" :stroke-width="1.5 * invScale" stroke-linecap="round" />
              <circle :cx="(p.nx + (p.ox||0)) * anchor.w" :cy="(p.ny + (p.oy||0)) * anchor.h" :r="circleR * 0.7" fill="#38bdf8" style="pointer-events:auto; cursor:move" />
            </template>
            <template v-if="Math.hypot(p.ix||0, p.iy||0) * anchor.w > 2">
              <line :x1="p.nx * (anchor.w)" :y1="p.ny * (anchor.h)" :x2="(p.nx + (p.ix||0)) * anchor.w" :y2="(p.ny + (p.iy||0)) * anchor.h" stroke="#f0abfc" :stroke-width="1.5 * invScale" stroke-linecap="round" />
              <circle :cx="(p.nx + (p.ix||0)) * anchor.w" :cy="(p.ny + (p.iy||0)) * anchor.h" :r="circleR * 0.7" fill="#f0abfc" style="pointer-events:auto; cursor:move" />
            </template>
            <!-- Node: node 0 = điểm BẮT ĐẦU (đóng kín) → highlight xanh khi hover snap; Ctrl+click node = đổi kiểu -->
            <circle :cx="p.nx * (anchor.w)" :cy="p.ny * (anchor.h)"
              :r="i === 0 && store.inpaintPathCloseHover ? circleR * 1.5 : circleR"
              :fill="nodeFill(p, i)"
              :title="'Kiểu: ' + nodeKindLabel(p) + ' — Ctrl+click để đổi · click phải để xóa'"
              stroke="#111" :stroke-width="1" style="pointer-events:auto; cursor:move" @contextmenu.prevent="store.pathDeleteNode(i)" />
          </template>
          <!-- Nút "Sửa" khi hover vùng ĐÃ ĐÓNG (và không đang vẽ điểm mới) -->
          <g v-if="hoverRegionTransform && store.inpaintPathPoints.length === 0"
             :transform="hoverRegionTransform"
             style="pointer-events:auto; cursor:pointer"
             @pointerdown.stop.prevent="store.enterEditRegion(store._pathHoverRegion)"
             title="Sửa lại vùng chọn này">
            <rect x="-27" y="-11.5" width="54" height="23" rx="6" fill="#0b1220" fill-opacity="0.95" stroke="#38bdf8" stroke-width="1" />
            <path d="M21.17 6.81a1 1 0 0 0-3.99-3.99L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.36a.5.5 0 0 0 .62.62l4.36-1.32a2 2 0 0 0 .83-.5z" fill="none" stroke="#7dd3fc" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" transform="translate(-16, -1) scale(0.55)" />
            <text x="8" y="4" text-anchor="middle" font-size="11" fill="#7dd3fc" font-weight="600">Sửa</text>
          </g>
        </svg>

        <!-- Rect: box + handles (chỉ khi ĐANG chỉnh) -->
        <div v-if="store.inpaintMaskMode === 'rect'" class="pointer-events-none absolute inset-0">
          <template v-if="hasBox">
            <div class="absolute cursor-move select-none" style="pointer-events:auto; touch-action:none"
                 :style="boxFrameStyle()"
                 @pointerdown.stop="store.beginInpaintDrag('move', $event)"
                 @dblclick.stop="store.resetInpaintMaskBox()"
                 title="Kéo để di chuyển · đúp chuột để vẽ lại vùng">
              <div class="pointer-events-none absolute -inset-px border-2 border-dashed border-brand-300"></div>
              <div v-if="!invertedRect" class="pointer-events-none absolute -inset-px" style="box-shadow: 0 0 0 9999px rgba(0,0,0,0.55);"></div>
              <div class="absolute -left-3 -top-3 h-6 w-6 cursor-nwse-resize rounded-sm border-2 border-white bg-brand-400 shadow" style="pointer-events:auto; touch-action:none" @pointerdown.stop="store.beginInpaintDrag('nw', $event)" title="Kéo góc"></div>
              <div class="absolute -right-3 -top-3 h-6 w-6 cursor-nesw-resize rounded-sm border-2 border-white bg-brand-400 shadow" style="pointer-events:auto; touch-action:none" @pointerdown.stop="store.beginInpaintDrag('ne', $event)" title="Kéo góc"></div>
              <div class="absolute -bottom-3 -left-3 h-6 w-6 cursor-nesw-resize rounded-sm border-2 border-white bg-brand-400 shadow" style="pointer-events:auto; touch-action:none" @pointerdown.stop="store.beginInpaintDrag('sw', $event)" title="Kéo góc"></div>
              <div class="absolute -bottom-3 -right-3 h-6 w-6 cursor-nwse-resize rounded-sm border-2 border-white bg-brand-400 shadow" style="pointer-events:auto; touch-action:none" @pointerdown.stop="store.beginInpaintDrag('se', $event)" title="Kéo góc"></div>
            </div>
          </template>
        </div>

        <!-- ══ Preview vùng ĐÃ LƯU (bấm Xong): hiển thị kết quả ngay trên ảnh, không chỉnh được ══ -->
        <template v-if="!editing && store.inpaintMaskDone">
          <!-- Rect đã lưu: viền xanh lá -->
          <div v-if="store._inpaintMaskKind === 'rect'" class="pointer-events-none absolute" :style="boxFrameStyle()">
            <div class="pointer-events-none absolute -inset-px border-2 border-dashed border-emerald-400"></div>
            <div class="pointer-events-none absolute -inset-px" style="box-shadow: 0 0 0 9999px rgba(0,0,0,0.45);"></div>
          </div>
          <!-- Brush đã lưu: mask đen-trắng phủ đúng vùng ảnh, opacity cao để thấy rõ -->
          <img v-else-if="store.inpaintBrushData" :src="'data:image/png;base64,' + store.inpaintBrushData"
               class="pointer-events-none absolute left-0 top-0 h-full w-full rounded-lg mix-blend-multiply"
               style="opacity:0.65" alt="Vùng đã vẽ" />
        </template>
      </div>
    </div>
  </div>
</template>
