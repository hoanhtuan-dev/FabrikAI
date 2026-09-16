<script setup>
// CanvasStatusBar — thanh trạng thái dock dưới khung canvas (SPEC: STUDIO_UI_REDESIGN.md §4.2).
// Không props — đọc/ghi trực tiếp useStudioStore(). Mọi icon qua <StudioIcon/>.
import { computed } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

// Gợi ý công cụ theo chế độ đang dùng (Krita-style) — hiện khi có công cụ active.
const toolHint = computed(() => {
  const m = store.inpaintMaskMode;
  if (m === 'path') {
    const n = store.inpaintPathPoints.length;
    if (n === 0) return 'Đường cong: bấm để đặt điểm neo · kéo để chỉnh tay điều khiển';
    if (n < 3) return `Đặt thêm điểm neo (${n}/3 tối thiểu) — quay lại điểm đầu (xanh) để đóng kín`;
    if (store.inpaintPathCloseHover) return 'Bấm điểm đầu để đóng kín vùng chọn · kéo sẽ di chuyển thay vì đóng';
    return 'Bấm điểm đầu (xanh) để đóng kín · bấm node vùng đã đóng để sửa lại · kéo neo/tay chỉnh · Ctrl+click node = đổi kiểu · Alt = phá đối xứng · click phải = xóa';
  }
  if (m === 'freehand') return 'Vẽ tự do quanh vùng cần chọn — thả chuột để tự đóng kín';
  if (m === 'rect') return 'Kéo để tạo vùng chữ nhật · kéo góc để chỉnh · kéo giữa để di chuyển';
  if (m === 'brush') return 'Vẽ nét lên vùng cần sửa · Tẩy = xóa nét · Ctrl+Z = hoàn tác';
  if (m === 'magic') return 'Bấm để chọn nhanh vùng màu — chỉnh Tolerance / Feather cho phù hợp';
  return '';
});

// Nút icon chuẩn (spec §4.2): h-7 hit-target, hover nền ink-700, disabled mờ 30%.
const BTN = 'grid h-7 w-7 place-items-center rounded-lg text-cream-200 hover:bg-ink-700 disabled:opacity-30';

// Nền swatch — inline style y hệt pill cũ StudioApp (grid checker / dark / white / cream).
const bgSwatchStyle = (b) => ({
  background: b === 'grid'
    ? 'repeating-conic-gradient(#888 0 25%, #ccc 0 50%) 0 / 10px 10px'
    : b === 'dark'
      ? '#0a0a0f'
      : b === 'white'
        ? '#fff'
        : '#f5ead9',
});
</script>

<template>
  <div class="relative z-30 flex h-9 shrink-0 items-center gap-1 border-t border-ink-700 bg-ink-900/95 px-2">
    <!-- 1. Undo / Redo -->
    <button @click="store.undo()" :disabled="!store.undoStack.length" :class="BTN" title="Hoàn tác (Ctrl+Z)" aria-label="Hoàn tác (Ctrl+Z)"><StudioIcon name="undo" /></button>
    <button @click="store.redo()" :disabled="!store.redoStack.length" :class="BTN" title="Làm lại (Ctrl+Y)" aria-label="Làm lại (Ctrl+Y)"><StudioIcon name="redo" /></button>

    <!-- 2. Divider -->
    <div class="h-4 w-px bg-ink-700" aria-hidden="true"></div>

    <!-- 3. Zoom -->
    <button @click="store.zoomOut()" :class="BTN" title="Thu nhỏ" aria-label="Thu nhỏ"><StudioIcon name="zoomOut" /></button>
    <button @click="store.zoomFit()" class="min-w-12 rounded-lg px-1 py-1 text-center text-[11px] tabular-nums text-cream-200 hover:bg-ink-700">{{ Math.round(store.zoom * 100) }}%</button>
    <button @click="store.zoomIn()" :class="BTN" title="Phóng to" aria-label="Phóng to"><StudioIcon name="zoomIn" /></button>
    <button @click="store.zoomFit()" :class="BTN" title="Vừa khung hình" aria-label="Vừa khung hình"><StudioIcon name="maximize" /></button>

    <!-- 4. Divider -->
    <div class="h-4 w-px bg-ink-700" aria-hidden="true"></div>

    <!-- 5. Nền canvas -->
    <button
      v-for="b in ['grid', 'dark', 'white', 'cream']"
      :key="b"
      @click="store.canvasBg = b"
      class="h-5 w-5 rounded-full border border-ink-600"
      :class="store.canvasBg === b ? 'ring-2 ring-brand-400' : ''"
      :style="bgSwatchStyle(b)"
      :title="'Nền: ' + b"
      :aria-label="'Nền: ' + b"
    ></button>

    <!-- 6. Snap (bắt điểm) — mặc định BẬT 8px -->
    <div class="h-4 w-px bg-ink-700" aria-hidden="true"></div>
    <button @click="store.snapGrid = store.snapGrid ? 0 : 8" :class="[BTN, store.snapGrid ? 'text-brand-200' : '']" title="Bật/tắt bắt điểm (snap)" aria-label="Bật/tắt bắt điểm (snap)"><StudioIcon name="target" /></button>
    <select v-if="store.snapGrid" :value="store.snapGrid" @change="store.snapGrid = Number($event.target.value)" class="h-6 rounded-md border border-ink-700 bg-ink-800 px-1 text-[10px] tabular-nums text-cream-100 focus:outline-none" title="Khoảng cách bắt điểm (px)">
      <option :value="8">8</option><option :value="16">16</option><option :value="24">24</option><option :value="32">32</option>
    </select>

    <!-- 7. Spacer + Gợi ý công cụ (Krita-style) -->
    <div class="flex-1"></div>
    <div v-if="toolHint" class="flex min-w-0 items-center gap-1.5 overflow-hidden px-1 text-[10px] text-cream-300/60" title="Hướng dẫn công cụ">
      <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
      <span class="truncate">{{ toolHint }}</span>
    </div>

    <!-- 7. Trạng thái (md+) -->
    <div class="hidden items-center gap-1.5 text-[10px] text-cream-300/60 md:flex">
      <StudioIcon name="layers" size="h-3.5 w-3.5" />
      <span>{{ store.canvasLayers.length }} lớp</span>
      <template v-if="store.activeLayer">
        <span class="max-w-32 truncate">· {{ store.activeLayer.name }}</span>
        <span>{{ Math.round((store.activeLayer.scale || 1) * 100) }}%</span>
      </template>
    </div>

    <!-- 8. Lưu vật lý -->
    <button
      @click="store.saveNow()"
      class="grid h-7 w-7 place-items-center rounded-lg text-cream-200 hover:bg-ink-700"
      title="Lưu trang (Save)"
      aria-label="Lưu trang"
    ><StudioIcon name="save" /></button>

    <!-- 9. Download -->
    <button
      @click="store.downloadActive()"
      :disabled="!store.upscaleSrc"
      class="grid h-7 w-7 place-items-center rounded-lg bg-brand-600 text-white transition-colors hover:bg-brand-500 disabled:opacity-30"
      title="Tải ảnh đang chọn"
      aria-label="Tải ảnh đang chọn"
    ><StudioIcon name="download" /></button>

    <!-- 9. Toggle inspector (lg+) -->
    <button
      @click="store.toggleInspector()"
      class="grid h-7 w-7 place-items-center rounded-lg text-cream-200 hover:bg-ink-700 disabled:opacity-30"
      :class="store.inspectorOpen ? 'bg-brand-600/20 text-brand-300' : ''"
      title="Bật/tắt panel Layers"
      aria-label="Bật/tắt panel Layers"
    ><StudioIcon name="panelRight" /></button>
  </div>
</template>