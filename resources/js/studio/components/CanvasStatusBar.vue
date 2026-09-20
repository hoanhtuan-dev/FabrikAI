<script setup>
// CanvasStatusBar — thanh trạng thái dock dưới khung canvas (SPEC: STUDIO_UI_REDESIGN.md §4.2).
// Không props — đọc/ghi trực tiếp useStudioStore(). Mọi icon qua <StudioIcon/>.
import { computed } from 'vue';
import { useStudioStore } from '../store.js';
import { useTheme } from '../composables/useTheme.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

// Đổi nhanh Sáng ⇄ Tối ngay tại chỗ làm việc. Ba lựa chọn đầy đủ (gồm "Theo hệ điều hành")
// nằm ở Cài đặt của tôi → Giao diện; ở đây bấm là chốt hẳn một trong hai giao diện, nên
// title nói rõ điều đó để người dùng không tưởng nút này cũng xoay được chế độ "theo máy".
const { resolved: themeResolved, setTheme } = useTheme();
function toggleTheme() { setTheme(themeResolved.value === 'light' ? 'dark' : 'light'); }


// Gợi ý công cụ theo chế độ đang dùng (Krita-style) — hiện khi có công cụ active.
const toolHint = computed(() => {
  // Chế độ "chỉnh 1 layer" (bật bởi công cụ vẽ/xóa/vùng chọn) — nói ở ĐÂY thay vì dán nhãn lên canvas:
  // người dùng vẫn hiểu vì sao chỉ thấy một layer, mà không gian làm việc không bị thêm chữ.
  if (store.drawMode) return 'Đang VẼ TỰ DO trên layer đang chọn — canvas chỉ hiện layer đó · thoát công cụ (Esc) để thấy toàn bộ';
  if (store.eraseMode) return 'Đang XÓA VÙNG trên layer đang chọn — canvas chỉ hiện layer đó · thoát công cụ (Esc) để thấy toàn bộ';
  if (store.cropMode) return 'Đang CẮT KHUNG — canvas chỉ hiện layer đang chọn';
  // Không có layer nào đang chọn (bấm ra vùng trống là bỏ chọn): nói rõ cách lấy lại tay cầm chỉnh kích cỡ.
  if (!store.activeLayer && store.visibleLayers.length) return 'Chưa chọn layer — bấm vào một layer để chỉnh kích cỡ · xoay';
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

// Nền canvas — MỘT NGUỒN với chính vùng canvas.
//
// Trước đây chỗ này tự vẽ màu bằng inline style, lệch hẳn với màu thật của canvas: ô "tối" một sắc,
// canvas ink-950 một sắc khác; ô "kem" một sắc, canvas cream-100 một sắc khác —
// và "lưới" thì canvas trỏ tới một class KHÔNG hề được định nghĩa nên nền trong suốt,
// trông như bấm nút không có tác dụng. Nay ô màu dùng ĐÚNG class .canvas-bg-* mà canvas đang dùng
// (app.css) ⇒ nhìn nút là biết canvas sẽ ra sao, không thể lệch.
const BG_OPTIONS = [
  { id: 'grid', label: 'Lưới trong suốt' },
  { id: 'dark', label: 'Tối' },
  { id: 'white', label: 'Trắng' },
  { id: 'cream', label: 'Kem' },
];
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
    <button @click="store.zoomFit()" class="min-w-12 rounded-lg px-1 py-1 text-center text-body tabular-nums text-cream-200 hover:bg-ink-700">{{ Math.round(store.zoom * 100) }}%</button>
    <button @click="store.zoomIn()" :class="BTN" title="Phóng to" aria-label="Phóng to"><StudioIcon name="zoomIn" /></button>
    <button @click="store.zoomFit()" :class="BTN" title="Vừa khung hình" aria-label="Vừa khung hình"><StudioIcon name="maximize" /></button>

    <!-- 4. Divider -->
    <div class="h-4 w-px bg-ink-700" aria-hidden="true"></div>

    <!-- 5. Nền canvas — ô màu dùng CHÍNH class nền của canvas -->
    <button
      v-for="b in BG_OPTIONS"
      :key="b.id"
      @click="store.canvasBg = b.id"
      class="motion-ui h-5 w-5 rounded-full border border-ink-600"
      :class="['canvas-bg-' + b.id, store.canvasBg === b.id ? 'ring-2 ring-brand-400' : '']"
      :title="'Nền canvas: ' + b.label"
      :aria-label="'Nền canvas: ' + b.label"
      :aria-pressed="store.canvasBg === b.id"
    ></button>

    <!-- 6. Snap (bắt điểm) — mặc định BẬT 8px -->
    <div class="h-4 w-px bg-ink-700" aria-hidden="true"></div>
    <button @click="store.snapGrid = store.snapGrid ? 0 : 8" :class="[BTN, store.snapGrid ? 'text-brand-200' : '']" title="Bật/tắt bắt điểm (snap)" aria-label="Bật/tắt bắt điểm (snap)"><StudioIcon name="target" /></button>
    <select v-if="store.snapGrid" :value="store.snapGrid" @change="store.snapGrid = Number($event.target.value)" class="h-6 rounded-md border border-ink-700 bg-ink-800 px-1 text-label tabular-nums text-cream-100 focus:outline-none" title="Khoảng cách bắt điểm (px)">
      <option :value="8">8</option><option :value="16">16</option><option :value="24">24</option><option :value="32">32</option>
    </select>

    <!-- 7. Spacer + Gợi ý công cụ (Krita-style) -->
    <div class="flex-1"></div>
    <div v-if="toolHint" class="flex min-w-0 items-center gap-1.5 overflow-hidden px-1 text-label text-cream-400" title="Hướng dẫn công cụ">
      <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
      <span class="truncate">{{ toolHint }}</span>
    </div>

    <!-- 7. Trạng thái (md+) -->
    <div class="hidden items-center gap-1.5 text-label text-cream-400 md:flex">
      <StudioIcon name="layers" size="h-3.5 w-3.5" />
      <span>{{ store.canvasLayers.length }} lớp</span>
      <template v-if="store.activeLayer">
        <span class="max-w-32 truncate">· {{ store.activeLayer.name }}</span>
        <span>{{ Math.round((store.activeLayer.scale || 1) * 100) }}%</span>
      </template>
    </div>

    <!-- 8. Giao diện Sáng/Tối (2026-09-23) — đổi ngay tại chỗ, không phải mở Cài đặt -->
    <div class="h-4 w-px bg-ink-700" aria-hidden="true"></div>
    <button
      @click="toggleTheme"
      class="motion-ui flex h-7 shrink-0 items-center gap-1 rounded-lg border border-ink-600 px-2 text-label font-semibold text-cream-200 hover:border-brand-400 hover:bg-ink-700"
      :title="'Giao diện đang là ' + (themeResolved === 'light' ? 'Sáng' : 'Tối') + ' — bấm để đổi (muốn theo hệ điều hành: Cài đặt của tôi → Giao diện)'"
      :aria-label="'Đổi giao diện Sáng/Tối, đang là ' + (themeResolved === 'light' ? 'Sáng' : 'Tối')"
    >
      <!-- Hai thẻ <StudioIcon> tách bằng v-if/v-else (KHÔNG dùng tam phân trong :name): test
           quét mọi chuỗi trong thuộc tính name của <StudioIcon> và đòi chúng là icon có thật —
           'light' trong biểu thức tam phân sẽ bị coi là một icon không tồn tại. -->
      <StudioIcon v-if="themeResolved === 'light'" name="sun" size="h-3.5 w-3.5" />
      <StudioIcon v-else name="moon" size="h-3.5 w-3.5" />
      <span>{{ themeResolved === 'light' ? 'Sáng' : 'Tối' }}</span>
    </button>

    <!-- 9. Lưu vật lý -->
    <button
      @click="store.saveNow()"
      class="grid h-7 w-7 place-items-center rounded-lg text-cream-200 hover:bg-ink-700"
      title="Lưu trang (Save)"
      aria-label="Lưu trang"
    ><StudioIcon name="save" /></button>

    <!-- 9. Toggle inspector (lg+) — nút tải ảnh đang chọn ở thanh này đã bỏ theo yêu cầu: việc tải ảnh
         đã có đường riêng ở bảng Lớp (Xuất PNG) và ở Kết quả/Thư viện, để đây chỉ gây trùng và bấm nhầm. -->
    <button
      data-dock-toggle="inspector"
      @click="store.toggleInspector()"
      class="grid h-7 w-7 place-items-center rounded-lg text-cream-200 hover:bg-ink-700 disabled:opacity-30"
      :class="store.inspectorOpen ? 'bg-brand-600/20 text-brand-300' : ''"
      title="Bật/tắt panel Layers"
      aria-label="Bật/tắt panel Layers"
    ><StudioIcon name="panelRight" /></button>
  </div>
</template>