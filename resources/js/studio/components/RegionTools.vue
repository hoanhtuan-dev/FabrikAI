<script setup>
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();

// Đơn sắc đồng nhất với theme (cream/ink).
const mono = {
  on: 'bg-invert text-invert-content border-cream-300/40 shadow-cream-100/10',
  off: 'text-cream-300 border-transparent hover:bg-ink-700 hover:text-cream-100',
};
const sep = 'h-px w-6 shrink-0 bg-ink-700';
const ICON = 'h-4 w-4';

/**
 * THANH CÔNG CỤ CANVAS — GOM NHÓM CHO ĐIỆN THOẠI (2026-09-26 · thiết kế lại).
 *
 * ĐO ĐƯỢC trước khi sửa: 10 nút 32px xếp ngang, phải CUỘN NGANG mới thấy hết, tất cả cùng một cỡ và chỉ
 * có icon — không nút nào nói được nó là gì, và bốn nút "vùng chọn" (chữ nhật · tự do · đường cong ·
 * magic) là bốn BIẾN THỂ của cùng một việc.
 *
 * Nay trên điện thoại: **6 nút** — bốn việc đơn (Lựa chọn · Di chuyển · Cắt khung · Film Look) và hai
 * NHÓM có menu chọn biến thể (Vùng sửa · Vẽ/Xoá). Nút nhóm hiện icon của biến thể ĐANG dùng, nên nhìn là
 * biết đang ở chế độ nào; mở menu thì mỗi lựa chọn có NHÃN CHỮ thay vì để người dùng đoán qua icon.
 *
 * Máy tính giữ nguyên cột dọc 10 nút như cũ: ở đó có chuột, mật độ dày là lợi thế, và mọi icon đều có
 * tooltip. Cố ý KHÔNG gom nhóm trên desktop để không thêm một cú bấm cho người dùng quen cột dọc.
 */
const MASK_VARIANTS = [
  { id: 'rect', icon: 'boxSelect', label: 'Vùng chữ nhật', hint: 'Kéo để tạo vùng · kéo góc để chỉnh' },
  { id: 'freehand', icon: 'lasso', label: 'Vùng tự do', hint: 'Vẽ quanh vùng cần chọn, thả là tự khép kín' },
  { id: 'path', icon: 'penTool', label: 'Vùng đường cong', hint: 'Bấm đặt điểm neo, quay lại điểm đầu để đóng' },
  { id: 'magic', icon: 'wand', label: 'Theo màu (magic)', hint: 'Bấm để chọn nhanh vùng cùng màu' },
];

const maskActive = (id) => store.inpaintMaskMode === id && store.inpaintMaskSource === 'canvas';
const currentMask = () => MASK_VARIANTS.find((v) => maskActive(v.id)) || null;

function pickMask(id) {
  store.selectTool = false; store.finishDraw(); store.startCanvasSelect(id);
  store.reframeOpen = false; store.filmOpen = false; store.exitErase();
}
</script>
<template>
  <!-- Thanh công cụ canvas (LUÔN nổi, docked nổi/mobile): cột dọc desktop · hàng ngang mobile -->
  <div class="pointer-events-none absolute inset-x-0 bottom-16 z-40 flex justify-center lg:inset-auto lg:left-2 lg:top-1/2 lg:bottom-auto lg:-translate-y-1/2 lg:flex-col">
    <!-- ══════════ ĐIỆN THOẠI: 4 việc đơn + 2 nhóm có menu ══════════ -->
    <div class="pointer-events-auto flex items-center gap-1 rounded-xl border border-ink-700 bg-ink-900/95 p-1 shadow-xl backdrop-blur lg:hidden">
      <button @click="store.selectTool = !store.selectTool; store.panMode = false; store.finishDraw(); store.reframeOpen = false; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="store.selectTool ? mono.on : mono.off"
        class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border transition-colors" title="Lựa chọn (quét chọn nhiều layer)" aria-label="Lựa chọn"
        :aria-pressed="store.selectTool">
        <StudioIcon name="cursor" :size="ICON"/>
      </button>
      <button @click="store.panMode = !store.panMode; store.selectTool = false; store.finishDraw(); store.reframeOpen = false; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="store.panMode ? mono.on : mono.off"
        class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border transition-colors" title="Di chuyển canvas (kéo để pan)" aria-label="Di chuyển canvas"
        :aria-pressed="store.panMode">
        <StudioIcon name="hand" :size="ICON"/>
      </button>

      <!-- NHÓM 1: Vùng sửa — 4 biến thể gộp còn một nút -->
      <div class="dropdown dropdown-top">
        <div tabindex="0" role="button"
             :class="currentMask() ? mono.on : mono.off"
             class="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-lg border transition-colors"
             :title="'Vùng sửa: ' + (currentMask() ? currentMask().label : 'chọn cách chọn vùng')"
             :aria-label="'Vùng sửa' + (currentMask() ? ' — đang là ' + currentMask().label : '')">
          <StudioIcon :name="currentMask() ? currentMask().icon : 'boxSelect'" :size="ICON"/>
        </div>
        <ul tabindex="0" class="menu dropdown-content z-50 mb-1 w-64 rounded-box border border-ink-700 bg-ink-800 p-2 shadow-xl">
          <li class="menu-title"><span>Chọn vùng để sửa</span></li>
          <li v-for="v in MASK_VARIANTS" :key="v.id">
            <button @click="pickMask(v.id)" :class="maskActive(v.id) ? 'active' : ''">
              <StudioIcon :name="v.icon" :size="ICON"/>
              <span><b>{{ v.label }}</b><br><span class="text-label text-cream-400">{{ v.hint }}</span></span>
            </button>
          </li>
        </ul>
      </div>

      <!-- NHÓM 2: Vẽ / Xoá -->
      <div class="dropdown dropdown-top">
        <div tabindex="0" role="button"
             :class="(store.drawMode || store.eraseMode) ? mono.on : mono.off"
             class="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-lg border transition-colors"
             :title="store.drawMode ? 'Đang VẼ TỰ DO' : (store.eraseMode ? 'Đang XOÁ VÙNG' : 'Vẽ / Xoá')"
             :aria-label="store.drawMode ? 'Vẽ tự do' : (store.eraseMode ? 'Xoá vùng' : 'Vẽ hoặc xoá')">
          <StudioIcon :name="store.eraseMode ? 'eraser' : 'brush'" :size="ICON"/>
        </div>
        <ul tabindex="0" class="menu dropdown-content z-50 mb-1 w-64 rounded-box border border-ink-700 bg-ink-800 p-2 shadow-xl">
          <li class="menu-title"><span>Vẽ trên layer đang chọn</span></li>
          <li>
            <button @click="store.selectTool = false; store.toggleDraw(); store.reframeOpen = false; store.filmOpen = false; store.clearInpaintMask(); store.exitErase()" :class="store.drawMode ? 'active' : ''">
              <StudioIcon name="brush" :size="ICON"/>
              <span><b>Vẽ tự do</b><br><span class="text-label text-cream-400">Cọ vẽ nét lên layer đang chọn</span></span>
            </button>
          </li>
          <li>
            <button @click="store.selectTool = false; store.finishDraw(); store.toggleErase(); store.reframeOpen = false; store.filmOpen = false; store.clearInpaintMask()" :class="store.eraseMode ? 'active' : ''">
              <StudioIcon name="eraser" :size="ICON"/>
              <span><b>Xoá vùng</b><br><span class="text-label text-cream-400">Làm mờ dần vùng đã vẽ (feather)</span></span>
            </button>
          </li>
        </ul>
      </div>

      <button @click="store.selectTool = false; store.finishDraw(); store.reframeOpen = !store.reframeOpen; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="(store.reframeOpen || store.cropMode) ? mono.on : mono.off"
        class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border transition-colors" title="Cắt khung theo tỉ lệ" aria-label="Cắt khung"
        :aria-pressed="store.reframeOpen || store.cropMode">
        <StudioIcon name="crop" :size="ICON"/>
      </button>
      <button @click="store.selectTool = false; store.finishDraw(); store.filmOpen = !store.filmOpen; store.reframeOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="(store.filmOpen || store.looking) ? mono.on : mono.off"
        class="grid h-10 w-10 shrink-0 place-items-center rounded-lg border transition-colors" title="Film Look · gán tone màu phim" aria-label="Film Look"
        :aria-pressed="store.filmOpen || store.looking">
        <StudioIcon name="palette" :size="ICON"/>
      </button>
    </div>

    <!-- ══════════ MÁY TÍNH: giữ nguyên cột dọc 10 nút (chuột + tooltip) ══════════ -->
    <div class="scrollbar-hide pointer-events-auto hidden max-w-[calc(100vw-1.5rem)] items-center gap-1 overflow-x-auto rounded-lg border border-ink-700 bg-ink-900/95 p-1.5 shadow-xl backdrop-blur lg:flex lg:max-w-none lg:flex-col lg:p-1.5">
      <button @click="store.selectTool = !store.selectTool; store.panMode = false; store.finishDraw(); store.reframeOpen = false; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="store.selectTool ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Lựa chọn (quét chọn nhiều layer)" aria-label="Lựa chọn">
        <StudioIcon name="cursor" :size="ICON"/>
      </button>
      <button @click="store.panMode = !store.panMode; store.selectTool = false; store.finishDraw(); store.reframeOpen = false; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="store.panMode ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Di chuyển canvas (kéo để pan)" aria-label="Di chuyển canvas">
        <StudioIcon name="hand" :size="ICON"/>
      </button>
      <div :class="sep"></div>
      <button @click="store.selectTool = false; store.finishDraw(); store.reframeOpen = !store.reframeOpen; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="(store.reframeOpen || store.cropMode) ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Reframe / Crop · Cắt khung theo tỉ lệ" aria-label="Cắt khung">
        <StudioIcon name="crop" :size="ICON"/>
      </button>
      <div :class="sep"></div>
      <button v-for="v in MASK_VARIANTS" :key="'d-' + v.id" @click="pickMask(v.id)"
        :class="maskActive(v.id) ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" :title="v.label + ' · ' + v.hint" :aria-label="v.label">
        <StudioIcon :name="v.icon" :size="ICON"/>
      </button>
      <div :class="sep"></div>
      <button @click="store.selectTool = false; store.toggleDraw(); store.reframeOpen = false; store.filmOpen = false; store.clearInpaintMask(); store.exitErase()"
        :class="store.drawMode ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Vẽ tự do (brush)" aria-label="Vẽ tự do">
        <StudioIcon name="brush" :size="ICON"/>
      </button>
      <button @click="store.selectTool = false; store.finishDraw(); store.toggleErase(); store.reframeOpen = false; store.filmOpen = false; store.clearInpaintMask()"
        :class="store.eraseMode ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Xóa vùng (feather)" aria-label="Xóa vùng">
        <StudioIcon name="eraser" :size="ICON"/>
      </button>
      <div :class="sep"></div>
      <button @click="store.selectTool = false; store.finishDraw(); store.filmOpen = !store.filmOpen; store.reframeOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="(store.filmOpen || store.looking) ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Film Look · Gán tone màu phim" aria-label="Film Look">
        <StudioIcon name="palette" :size="ICON"/>
      </button>
    </div>
  </div>
</template>
