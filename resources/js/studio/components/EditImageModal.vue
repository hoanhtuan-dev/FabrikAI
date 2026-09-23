<script setup>
/**
 * MÀN "CHỈNH ẢNH" — MỘT ảnh, ba chế độ: Tả · Khoanh · Cọ (bước 5.3, 2026-09-26).
 *
 * VÌ SAO CÓ MÀN RIÊNG thay vì sửa trên canvas nhiều layer:
 *   · fal tính tiền theo megapixel LÀM TRÒN LÊN ⇒ biết chính xác vùng sửa là biết chính xác giá.
 *   · Người dùng thật sửa MỘT ảnh, không ghép nhiều ảnh — chồng layer là công cụ của hoạ sĩ.
 *   · Trên điện thoại, "canvas nhiều layer + vẽ mask" là bất khả thi; "một ảnh + bottom sheet" thì được.
 *
 * BA CHẾ ĐỘ — và thứ tự này là chủ ý (quyết định D5):
 *   Tả (mặc định)  — chỉ prompt, KHÔNG mask. Đây là đường đi của ĐA SỐ người dùng và là đường DUY NHẤT
 *                    chạy tốt trên điện thoại. Bắt vẽ mask trước khi được sửa ảnh là CHẶN Ở BƯỚC KHÓ NHẤT.
 *   Khoanh         — kéo một khung. Nhanh, đủ chính xác cho phần lớn việc.
 *   Cọ             — vùng méo, cần chính xác.
 *
 * HỆ TOẠ ĐỘ: MỘT hàm (toImageCoords). Vì màn này chỉ có MỘT ảnh, không xoay, không layer, nên hình
 * chữ nhật của thẻ <img> CHÍNH LÀ vùng ảnh — không cần đo canvas/pan/zoom/rotation như
 * canvasView::canvasMetrics() (130 dòng + 21 chỗ đọc ref DOM).
 *
 * MASK GỬI LÊN: đúng hợp đồng backend đang dùng —
 *   rect : mask_mode='rect'  + region {x,y,w,h} chuẩn hoá 0..1
 *   brush: mask_mode='brush' + mask_data = PNG base64, NỀN TRẮNG + NÉT ĐEN (xem buildMaskImage)
 * Không thêm tham số nào mới ⇒ backend không phải sửa một dòng.
 */
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import BaseModal from './BaseModal.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';
import CompareSlider from './CompareSlider.vue';

const store = useStudioStore();

/** 'describe' (Tả — mặc định) | 'rect' (Khoanh) | 'brush' (Cọ) */
const mode = ref('describe');
const prompt = ref('');
const busy = ref(false);
const imgEl = ref(null);
const wrapEl = ref(null);

// Vùng khoanh, chuẩn hoá 0..1 theo ảnh.
const region = ref({ x: 0.3, y: 0.3, w: 0.4, h: 0.4 });
const dragging = ref(false);
const dragStart = ref(null);

// Cọ
const brushSize = ref(60);
const feather = ref(0);
const painting = ref(false);
const lastPt = ref(null);
let ctx = null;

const src = computed(() => store.workingImage?.url || store.upscaleSrc || '');
const srcName = computed(() => store.workingImage?.name || store.upscaleName || 'Ảnh đang chọn');

/**
 * ĐƠN GIÁ THẬT của lượt này — tra theo model + cỡ + tỉ lệ đang chọn.
 * Sửa ảnh là đường ĐẮT NHẤT (1.300–6.500 ₫/lượt ⇒ 2–10 credit), nên KHÔNG được để khách bấm mà
 * không thấy giá (nguyên tắc 3).
 */
const editCost = computed(() => {
  const sel = store.inpaintModel
    ? store.inpaintModels.find((o) => o.provider + ':' + o.model === store.inpaintModel)
    : null;
  const fallback = store.taskGroupModels('edit')[0] || store.inpaintModels[0] || null;
  const provider = (sel && sel.provider) || (fallback && fallback.provider) || 'dashscope';
  const model = (sel && sel.model) || (fallback && fallback.model) || '';
  return store.costFor(provider, model, store.imageRes, store.imageRatio);
});

const canRun = computed(() => !!src.value && prompt.value.trim().length > 0 && !busy.value);

// ── HỆ TOẠ ĐỘ: MỘT hàm ────────────────────────────────────────────────────────────────
/** Toạ độ client → chuẩn hoá 0..1 theo ẢNH. Thẻ <img> chính là vùng ảnh (một ảnh, không xoay). */
function toImageCoords(clientX, clientY) {
  const el = imgEl.value;
  if (!el) return { x: 0, y: 0 };
  const r = el.getBoundingClientRect();
  if (!r.width || !r.height) return { x: 0, y: 0 };
  return {
    x: Math.min(1, Math.max(0, (clientX - r.left) / r.width)),
    y: Math.min(1, Math.max(0, (clientY - r.top) / r.height)),
  };
}

// ── KHOANH ────────────────────────────────────────────────────────────────────────────
function rectDown(e) {
  if (mode.value !== 'rect') return;
  dragging.value = true;
  dragStart.value = toImageCoords(e.clientX, e.clientY);
  region.value = { x: dragStart.value.x, y: dragStart.value.y, w: 0, h: 0 };
  e.currentTarget.setPointerCapture?.(e.pointerId);
}
function rectMove(e) {
  if (!dragging.value || !dragStart.value) return;
  const p = toImageCoords(e.clientX, e.clientY);
  const a = dragStart.value;
  region.value = {
    x: Math.min(a.x, p.x),
    y: Math.min(a.y, p.y),
    w: Math.abs(p.x - a.x),
    h: Math.abs(p.y - a.y),
  };
}
function rectUp() { dragging.value = false; }

const regionStyle = computed(() => ({
  left: (region.value.x * 100) + '%',
  top: (region.value.y * 100) + '%',
  width: (region.value.w * 100) + '%',
  height: (region.value.h * 100) + '%',
}));

const regionValid = computed(() => region.value.w > 0.01 && region.value.h > 0.01);

// ── CỌ ────────────────────────────────────────────────────────────────────────────────
/** Khởi tạo canvas phủ ĐÚNG kích thước hiển thị của ảnh (nhân DPR cho nét không răng cưa). */
async function initBrush() {
  await nextTick();
  const el = imgEl.value;
  if (!el) return;
  const r = el.getBoundingClientRect();
  const dpr = Math.min(2, window.devicePixelRatio || 1);
  const c = document.createElement('canvas');
  c.width = Math.max(1, Math.round(r.width * dpr));
  c.height = Math.max(1, Math.round(r.height * dpr));
  ctx = c.getContext('2d');
  ctx.scale(dpr, dpr);
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.strokeStyle = '#000';   // nét ĐEN trên nền TRONG SUỐT; lúc gửi sẽ đặt lên nền TRẮNG
  ctx.lineWidth = brushSize.value;
  brushCanvas.value = c;
}
const brushCanvas = ref(null);

function brushDown(e) {
  if (mode.value !== 'brush') return;
  if (!ctx) return;
  painting.value = true;
  const p = toImageCoords(e.clientX, e.clientY);
  lastPt.value = p;
  ctx.lineWidth = brushSize.value;
  ctx.beginPath();
  dotAt(p);
  e.currentTarget.setPointerCapture?.(e.pointerId);
}
function dotAt(p) {
  if (!ctx) return;
  const el = imgEl.value;
  if (!el) return;
  const r = el.getBoundingClientRect();
  ctx.beginPath();
  ctx.arc(p.x * r.width, p.y * r.height, brushSize.value / 2, 0, Math.PI * 2);
  ctx.fillStyle = '#000';
  ctx.fill();
}
function brushMove(e) {
  if (!painting.value || !ctx || !lastPt.value) return;
  const el = imgEl.value;
  if (!el) return;
  const r = el.getBoundingClientRect();
  const p = toImageCoords(e.clientX, e.clientY);
  ctx.beginPath();
  ctx.lineWidth = brushSize.value;
  ctx.moveTo(lastPt.value.x * r.width, lastPt.value.y * r.height);
  ctx.lineTo(p.x * r.width, p.y * r.height);
  ctx.stroke();
  lastPt.value = p;
}
function brushUp() { painting.value = false; lastPt.value = null; }
function clearBrush() {
  const c = brushCanvas.value;
  if (!c || !ctx) return;
  ctx.clearRect(0, 0, c.width, c.height);
}

function hasBrushStrokes() {
  const c = brushCanvas.value;
  if (!c) return false;
  const g = c.getContext('2d');
  const d = g.getImageData(0, 0, c.width, c.height).data;
  for (let i = 3; i < d.length; i += 4) { if (d[i] > 0) return true; }
  return false;
}

/**
 * Xuất mask theo ĐÚNG quy ước backend: PNG NỀN TRẮNG + NÉT ĐEN (xem StudioController::buildMaskImage).
 * Canvas vẽ giữ nét đen trên nền TRONG SUỐT (để nhìn thấy ảnh bên dưới); lúc gửi mới đặt lên nền TRẮNG.
 */
function exportBrushMask() {
  const c = brushCanvas.value;
  if (!c) return '';
  const out = document.createElement('canvas');
  out.width = c.width;
  out.height = c.height;
  const octx = out.getContext('2d');
  octx.fillStyle = '#fff';
  octx.fillRect(0, 0, out.width, out.height);
  octx.drawImage(c, 0, 0);
  return out.toDataURL('image/png');
}

// ── CHẠY ──────────────────────────────────────────────────────────────────────────────
async function run() {
  if (!canRun.value) return;
  busy.value = true;
  try {
    if (mode.value === 'describe') {
      // TẢ = KHÔNG mask. Đây là mặc định, và là đường chạy tốt trên điện thoại.
      store.inpaintMaskDone = false;
      store._inpaintMaskKind = '';
      store.inpaintBrushData = '';
    } else if (mode.value === 'rect') {
      if (!regionValid.value) { store.toast('Kéo một khung trên ảnh để chọn vùng cần sửa.', 'error'); return; }
      store._inpaintMaskKind = 'rect';
      store.inpaintMaskBox = { ...region.value };
      store.inpaintBrushData = '';
      store.inpaintMaskDone = true;
    } else {
      if (!hasBrushStrokes()) { store.toast('Vẽ lên ảnh để chọn vùng cần sửa.', 'error'); return; }
      store._inpaintMaskKind = 'brush';
      store.inpaintBrushData = exportBrushMask();
      store.inpaintMaskDone = true;
    }
    store.inpaintFeather = Number(feather.value) || 0;

    const before = src.value;
    await store.inpaint(prompt.value.trim());
    compareBefore.value = before;
  } finally {
    busy.value = false;
  }
}
const compareBefore = ref('');
const compareOpen = ref(false);

function close() {
  store.editImageOpen = false;
  painting.value = false;
  dragging.value = false;
}

// Đổi chế độ: dọn trạng thái của chế độ cũ để không gửi mask "ma" của lần trước.
watch(mode, async (m) => {
  dragging.value = false;
  painting.value = false;
  lastPt.value = null;
  if (m === 'brush') { await initBrush(); } else { brushCanvas.value = null; ctx = null; }
  if (m === 'describe') { store.inpaintMaskDone = false; }
});

// Mở màn: nạp ảnh đang làm việc, đặt lại prompt và khởi tạo cọ nếu đang ở chế độ Cọ.
watch(() => store.editImageOpen, async (open) => {
  if (!open) return;
  prompt.value = store.inpaintPrompt || '';
  feather.value = Number(store.inpaintFeather) || 0;
  if (mode.value === 'brush') await initBrush();
  else await nextTick();
});

onBeforeUnmount(() => { ctx = null; brushCanvas.value = null; });
</script>

<template>
  <BaseModal :model-value="store.editImageOpen" full title="Chỉnh ảnh" @update:model-value="close">
    <div class="flex h-full min-h-0 flex-col lg:flex-row">
      <!-- ── VÙNG ẢNH (chiếm phần lớn; trên điện thoại là phần trên) ── -->
      <div ref="wrapEl" class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden bg-ink-950 p-3">
        <div v-if="!src" class="text-center text-cream-300">
          <StudioIcon name="image" size="h-8 w-8" class="mx-auto mb-2 opacity-60" />
          <p class="text-sm">Chưa chọn ảnh nào</p>
          <p class="text-body">Chọn một ảnh ở lưới kết quả rồi bấm «Sửa».</p>
        </div>

        <div v-else class="relative max-h-full" style="touch-action:none">
          <img
            ref="imgEl"
            :src="src"
            :alt="srcName"
            class="max-h-[62vh] w-auto max-w-full select-none rounded-lg border border-ink-700 object-contain lg:max-h-[80vh]"
            draggable="false"
            @load="mode === 'brush' && initBrush()"
          >

          <!-- Lớp phủ thao tác: KHOANH (kéo khung) hoặc CỌ (vẽ) -->
          <div
            v-if="mode !== 'describe'"
            class="absolute inset-0 cursor-crosshair"
            @pointerdown="mode === 'rect' ? rectDown($event) : brushDown($event)"
            @pointermove="mode === 'rect' ? rectMove($event) : brushMove($event)"
            @pointerup="mode === 'rect' ? rectUp() : brushUp()"
            @pointerleave="mode === 'rect' ? rectUp() : brushUp()"
          >
            <!-- Khung khoanh -->
            <div v-if="mode === 'rect'" class="pointer-events-none absolute border-2 border-dashed border-brand-400 bg-brand-400/15" :style="regionStyle"></div>
            <!-- Cọ: canvas vẽ đè lên ảnh (nét đen, nền trong suốt) -->
            <canvas
              v-if="mode === 'brush' && brushCanvas"
              :ref="(el) => { if (el && brushCanvas) { el.width = brushCanvas.width; el.height = brushCanvas.height; el.getContext('2d').drawImage(brushCanvas, 0, 0); } }"
              class="pointer-events-none absolute inset-0 h-full w-full opacity-60"
            ></canvas>
          </div>
        </div>
      </div>

      <!-- ── BẢNG ĐIỀU KHIỂN (trên điện thoại: dưới ảnh; desktop: cột phải) ── -->
      <aside class="flex w-full shrink-0 flex-col gap-3 overflow-y-auto border-t border-ink-700 bg-ink-900 p-3 lg:w-[380px] lg:border-l lg:border-t-0">
        <div class="min-w-0">
          <p class="truncate text-body font-semibold text-cream-100" :title="srcName">{{ srcName }}</p>
          <p class="text-label text-cream-400">{{ store.taskGroupModels('edit')[0]?.label || 'Model sửa ảnh mặc định' }}</p>
        </div>

        <!-- ① BA CHẾ ĐỘ — Tả đứng ĐẦU và là mặc định -->
        <div class="flex flex-wrap gap-1" role="group" aria-label="Chọn cách chỉnh ảnh">
          <button type="button" class="tool-btn !py-1.5 text-label" :class="mode === 'describe' ? 'is-active' : ''" data-edit-mode="describe" title="Tả điều muốn đổi — không cần khoanh vùng" @click="mode = 'describe'">
            <StudioIcon name="wand" size="h-3.5 w-3.5" /> Tả
          </button>
          <button type="button" class="tool-btn !py-1.5 text-label" :class="mode === 'rect' ? 'is-active' : ''" data-edit-mode="rect" title="Kéo một khung quanh vùng cần sửa" @click="mode = 'rect'">
            <StudioIcon name="crop" size="h-3.5 w-3.5" /> Khoanh
          </button>
          <button type="button" class="tool-btn !py-1.5 text-label" :class="mode === 'brush' ? 'is-active' : ''" data-edit-mode="brush" title="Vẽ tự do vùng cần sửa" @click="mode = 'brush'">
            <StudioIcon name="brush" size="h-3.5 w-3.5" /> Cọ
          </button>
        </div>

        <p class="rounded-md bg-ink-800 px-2 py-1.5 text-label leading-snug text-cream-300">
          <template v-if="mode === 'describe'">Tả thay đổi bằng lời — <b class="text-cream-100">không cần khoanh vùng</b>. Cách này nhanh nhất và dùng được trên điện thoại.</template>
          <template v-else-if="mode === 'rect'">Kéo một khung trên ảnh để chọn vùng cần sửa. Vùng ngoài khung giữ nguyên.</template>
          <template v-else>Vẽ lên ảnh để chọn vùng cần sửa. Nét càng phủ đúng chỗ, kết quả càng sát.</template>
        </p>

        <!-- ② THAM SỐ CỦA CHẾ ĐỘ ĐANG CHỌN -->
        <div v-if="mode === 'brush'" class="space-y-2">
          <label class="label" for="ed-brush">Cỡ cọ — {{ brushSize }}px</label>
          <input id="ed-brush" v-model.number="brushSize" type="range" min="8" max="200" step="2" class="w-full">
          <button type="button" class="tool-btn !py-1.5 text-label" @click="clearBrush()"><StudioIcon name="trash" size="h-3 w-3" /> Xoá nét vẽ</button>
        </div>

        <div class="space-y-1">
          <label class="label" for="ed-feather">Làm mềm mép — {{ feather }}px</label>
          <input id="ed-feather" v-model.number="feather" type="range" min="0" max="40" step="1" class="w-full">
          <p class="text-tiny text-cream-400">Mép cứng dễ lộ viền khi ghép lại. 8–16px là mức hợp lý cho ảnh 2K.</p>
        </div>

        <!-- ③ MÔ TẢ -->
        <div>
          <label class="label" for="ed-prompt">Mô tả thay đổi</label>
          <textarea
            id="ed-prompt"
            v-model="prompt"
            rows="3"
            class="input w-full resize-y text-body"
            placeholder="VD: đổi màu áo sang đỏ đô, giữ nguyên chất liệu và phông nền"
          ></textarea>
        </div>

        <!-- ④ CHẠY — nút luôn ở cuối, kèm GIÁ THẬT -->
        <div class="mt-auto space-y-2 pt-1">
          <button type="button" class="btn-brand w-full" :disabled="!canRun" data-edit-run @click="run()">
            <LoadingSpinner v-if="busy" size="h-4 w-4" />
            <template v-else>
              <StudioIcon name="wand" size="h-4 w-4" />
              Sửa ảnh <span class="opacity-70">· {{ editCost }} credit</span>
            </template>
          </button>
          <p v-if="!prompt.trim()" class="text-label text-cream-400">↳ Nhập mô tả thay đổi để bật nút.</p>
          <p v-else class="text-label text-cream-400">Còn {{ store.creditsLeft }} credit trong gói.</p>
        </div>

        <!-- ⑤ KẾT QUẢ GẦN NHẤT: trước/sau (CompareSlider là MODAL — mở bằng nút, như InpaintCard) -->
        <div v-if="store.inpaintStage === 'done' && compareBefore && src" class="space-y-2 rounded-lg border border-ok/40 bg-ok/5 p-2">
          <p class="text-label font-semibold text-ok">Đã sửa xong</p>
          <button type="button" class="btn-outline btn-sm w-full" @click="compareOpen = true">
            <StudioIcon name="columns" size="h-3.5 w-3.5" /> Xem trước / sau
          </button>
        </div>
        <p v-if="store.inpaintStage === 'error'" class="rounded-md border border-danger/40 bg-danger/10 px-2 py-1.5 text-label text-danger">{{ store.inpaintError }}</p>
      </aside>
    </div>

    <CompareSlider v-model="compareOpen" :before="compareBefore" :after="src" />
  </BaseModal>
</template>
