<script setup>
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();

// Đơn sắc đồng nhất với theme (cream/ink).
const mono = {
  on: 'bg-cream-100 text-ink-900 border-cream-300/40 shadow-cream-100/10',
  off: 'text-cream-300/70 border-transparent hover:bg-ink-700 hover:text-cream-100',
};
const sep = 'h-px w-6 shrink-0 bg-ink-700';
const ICON = 'h-4 w-4';
</script>
<template>
  <!-- Thanh công cụ canvas (LUÔN nổi, docked nổi/mobile): cột dọc desktop · hàng ngang mobile -->
  <div class="pointer-events-none absolute inset-x-0 bottom-16 z-40 flex justify-center lg:inset-auto lg:left-2 lg:top-1/2 lg:bottom-auto lg:-translate-y-1/2 lg:justify-start">
    <div class="scrollbar-hide pointer-events-auto flex max-w-[calc(100vw-1.5rem)] items-center gap-1 overflow-x-auto rounded-lg border border-ink-700 bg-ink-900/85 p-1.5 shadow-xl backdrop-blur lg:max-w-none lg:flex-col lg:items-center lg:overflow-visible">

      <!-- ══ Công cụ LỰA CHỌN (quét chọn nhiều layer) — icon chuẩn ngành ══ -->
      <button @click="store.selectTool = !store.selectTool; store.panMode = false; store.finishDraw(); store.reframeOpen = false; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="store.selectTool ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Lựa chọn (quét chọn nhiều layer)" :aria-label="'Lựa chọn (quét chọn nhiều layer)'">
        <StudioIcon name="cursor" :size="ICON"/>
      </button>
      <!-- ══ Công cụ DI CHUYỂN CANVAS (pan/hand) — tablet không có Space/Ctrl để pan ══ -->
      <button @click="store.panMode = !store.panMode; store.selectTool = false; store.finishDraw(); store.reframeOpen = false; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="store.panMode ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Di chuyển canvas (kéo để pan)" :aria-label="'Di chuyển canvas (kéo để pan)'">
        <StudioIcon name="hand" :size="ICON"/>
      </button>
      <div :class="sep"></div>

      <!-- ══ Nhóm 1: Cắt khung / Reframe ══ -->
      <button @click="store.selectTool = false; store.finishDraw(); store.reframeOpen = !store.reframeOpen; store.filmOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="(store.reframeOpen || store.cropMode) ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Reframe / Crop · Cắt khung theo tỷ lệ / chọn vùng" :aria-label="'Reframe / Crop · Cắt khung theo tỷ lệ / chọn vùng'">
        <StudioIcon name="crop" :size="ICON"/>
      </button>
      <div :class="sep"></div>

      <!-- ══ Nhóm 2: Vùng chọn (chữ nhật · tự do · đường cong · magic) ══ -->
      <button @click="store.selectTool = false; store.finishDraw(); store.startCanvasSelect('rect'); store.reframeOpen = false; store.filmOpen = false; store.exitErase()"
        :class="(store.inpaintMaskMode === 'rect' && store.inpaintMaskSource === 'canvas') ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Vẽ vùng chọn (chữ nhật) · Xóa / Tô màu / Feather" :aria-label="'Vẽ vùng chọn (chữ nhật) · Xóa / Tô màu / Feather'">
        <StudioIcon name="boxSelect" :size="ICON"/>
      </button>
      <button @click="store.selectTool = false; store.finishDraw(); store.startCanvasSelect('freehand'); store.reframeOpen = false; store.filmOpen = false; store.exitErase()"
        :class="(store.inpaintMaskMode === 'freehand' && store.inpaintMaskSource === 'canvas') ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Vẽ vùng chọn tự do (lasso) · Xóa / Tô màu / Feather" :aria-label="'Vẽ vùng chọn tự do (lasso) · Xóa / Tô màu / Feather'">
        <StudioIcon name="lasso" :size="ICON"/>
      </button>
      <button @click="store.selectTool = false; store.finishDraw(); store.startCanvasSelect('path'); store.reframeOpen = false; store.filmOpen = false; store.exitErase()"
        :class="(store.inpaintMaskMode === 'path' && store.inpaintMaskSource === 'canvas') ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Vùng chọn bằng đường cong (path/curve) · click thêm điểm · Đóng để tạo vùng" :aria-label="'Vùng chọn bằng đường cong (path/curve) · click thêm điểm · Đóng để tạo vùng'">
        <StudioIcon name="penTool" :size="ICON"/>
      </button>
      <button @click="store.selectTool = false; store.finishDraw(); store.startCanvasSelect('magic'); store.reframeOpen = false; store.filmOpen = false; store.exitErase()"
        :class="(store.inpaintMaskMode === 'magic' && store.inpaintMaskSource === 'canvas') ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Magic Wand · chọn vùng theo màu (click 1 điểm)" :aria-label="'Magic Wand · chọn vùng theo màu (click 1 điểm)'">
        <StudioIcon name="wand" :size="ICON"/>
      </button>
      <div :class="sep"></div>

      <!-- ══ Nhóm 3: Vẽ tự do · Xóa vùng ══ -->
      <button @click="store.selectTool = false; store.toggleDraw(); store.reframeOpen = false; store.filmOpen = false; store.clearInpaintMask(); store.exitErase()"
        :class="store.drawMode ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Vẽ tự do (brush)" :aria-label="'Vẽ tự do (brush)'">
        <StudioIcon name="brush" :size="ICON"/>
      </button>
      <button @click="store.selectTool = false; store.finishDraw(); store.toggleErase(); store.reframeOpen = false; store.filmOpen = false; store.clearInpaintMask()"
        :class="store.eraseMode ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Xóa vùng (feather)" :aria-label="'Xóa vùng (feather)'">
        <StudioIcon name="eraser" :size="ICON"/>
      </button>
      <div :class="sep"></div>

      <!-- ══ Nhóm 4: Film Look ══ -->
      <button @click="store.selectTool = false; store.finishDraw(); store.filmOpen = !store.filmOpen; store.reframeOpen = false; store.exitErase(); store.clearInpaintMask()"
        :class="(store.filmOpen || store.looking) ? mono.on : mono.off"
        class="grid h-8 w-8 shrink-0 place-items-center rounded-md border transition-colors lg:h-9 lg:w-9" title="Film Look · Gán tone màu phim" :aria-label="'Film Look · Gán tone màu phim'">
        <StudioIcon name="palette" :size="ICON"/>
      </button>
    </div>
  </div>
</template>