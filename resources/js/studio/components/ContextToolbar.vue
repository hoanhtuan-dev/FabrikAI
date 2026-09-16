<script setup>
import { computed } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();

const reframeRatios = ['1:1','3:4','4:5','9:16','16:9','2:3'];
const looks = [['studio','Studio'],['warm','Ấm'],['cool','Lạnh'],['cinematic','Điện ảnh'],['dramatic','Dramatic'],['retro','Retro'],['mono','Mono']];
const hasBox = computed(() => (store.inpaintMaskBox.w || 0) >= 0.02 && (store.inpaintMaskBox.h || 0) >= 0.02);

// ── Chuyên nghiệp: từng thông số = "pill" (icon + nhãn + slider + giá trị), giống Krita/PS ──
const I = 'h-3.5 w-3.5';
const primary = 'bg-cream-100 text-ink-900 hover:bg-cream-200';
// Nút "Hoàn thành" (chỉnh sửa vùng) — màu KHÁC hẳn (xanh lục) để phân biệt với nút "Xong" thoát (kem).
const confirm = 'bg-emerald-600 text-white hover:bg-emerald-500';
const btn = 'bg-ink-800 text-cream-200 hover:bg-ink-700';
// Nút ĐANG ĐƯỢC CHỌN (toggle/mode): nền sáng + vòng brand + bóng → rõ là đang active.
const on = '!bg-cream-100 !text-ink-900 ring-2 ring-brand-300 shadow';
const iconBtn = 'grid h-6 w-6 place-items-center rounded-full bg-ink-800 text-cream-200 transition-colors hover:bg-ink-700';
const iconBtnDanger = 'grid h-6 w-6 place-items-center rounded-full bg-ink-800 text-cream-200 transition-colors hover:bg-red-600 hover:text-white';
const lbl = 'flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold transition-colors';
const chip = 'rounded-full border px-2 py-0.5 text-[11px] font-medium transition-colors';
const chipOn = 'border-cream-100 bg-cream-100 font-semibold text-ink-900';
const chipOff = 'border-ink-700 text-cream-200 hover:border-cream-300 hover:text-cream-100';
const sep = 'mx-0.5 h-3.5 w-px shrink-0 bg-ink-600';
const ring = 'ring-ink-600/70';
// Widget pill cho thông số
const pill = 'flex items-center justify-between gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1';
const pillL = 'flex items-center gap-1 pr-1 text-[9px] text-cream-300/70';
const sld = 'h-1 min-w-0 flex-1 cursor-pointer accent-cream-300';
const val = 'w-7 shrink-0 text-right text-[8px] tabular-nums text-cream-100';
function P(icon, lbl, v) { return { icon, lbl, v }; }
</script>
<template>
  <!-- ══ Vùng chọn (rect/freehand/path/magic/brush) ══ -->
  <div v-if="store.inpaintMaskMode !== 'none'" class="flex flex-wrap items-center justify-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <template v-if="store.inpaintMaskMode === 'rect' || store.inpaintMaskMode === 'freehand' || store.inpaintMaskMode === 'path' || store.inpaintMaskMode === 'magic'">
      <template v-if="store.inpaintMaskMode === 'path'">
        <!-- Đang CHỈNH SỬA vùng đã đóng → nút "Xong" để hoàn thành việc sửa (cập nhật vùng + re-bake). -->
        <button v-if="store._pathEditingRegion >= 0" @click="store.pathClose()" :class="[lbl, confirm]" title="Hoàn thành chỉnh sửa vùng chọn này"><StudioIcon name="check" :size="I"/>Hoàn thành</button>
        <!-- Tự động đóng kín khi quay lại điểm bắt đầu (Krita) → không cần nút "Đóng" nữa. -->
        <button @click="store.pathUndoPoint()" :class="iconBtn" title="Bỏ điểm neo cuối" aria-label="Bỏ điểm neo cuối"><StudioIcon name="undo" :size="I"/></button>
      </template>
      <template v-if="store.inpaintMaskMode === 'magic'">
        <div class="flex items-center justify-center gap-0.5 rounded-lg bg-ink-800/70 px-1.5 py-0.5"><StudioIcon name="sliders" size="h-3 w-3" class="text-brand-300"/><input type="range" min="1" max="128" step="1" :value="store.magicTolerance" @input="store.magicTolerance = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-7 text-right text-[9px] tabular-nums text-cream-100">{{ store.magicTolerance }}</span></div>
        <div class="flex items-center justify-center gap-0.5 rounded-lg bg-ink-800/70 px-1.5 py-0.5"><StudioIcon name="feather" size="h-3 w-3" class="text-brand-300"/><input type="range" min="0" max="20" step="1" :value="store.magicFeather" @input="store.magicFeather = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-7 text-right text-[9px] tabular-nums text-cream-100">{{ store.magicFeather }}</span></div>
      </template>
      <button v-if="!(store.inpaintMaskMode === 'path' && store._pathEditingRegion >= 0)" @click="store.confirmInpaintMask()" :class="[lbl, primary]" :title="store.inpaintMaskSource === 'canvas' ? 'Thoát vùng chọn' : 'Áp dụng vùng chọn và thoát'"><StudioIcon name="check" :size="I"/>Xong</button>
    </template>
    <template v-else>
      <button @click="store.inpaintErase = false" :class="[lbl, store.inpaintErase ? on : btn]" class="rounded-full px-3 py-1 text-xs font-semibold transition-colors" title="Vẽ thêm vùng cần sửa"><StudioIcon name="brush" :size="I"/>Vẽ</button>
      <button @click="store.inpaintErase = true" :class="[lbl, store.inpaintErase ? on : btn]" class="rounded-full px-3 py-1 text-xs font-semibold transition-colors" title="Tẩy nét đã vẽ"><StudioIcon name="eraser" :size="I"/>Tẩy</button>
      <button @click="store.undoInpaintBrush()" :class="iconBtn" title="Hoàn tác nét vẽ (Ctrl+Z)" aria-label="Hoàn tác nét vẽ (Ctrl+Z)"><StudioIcon name="undo" :size="I"/></button>
      <span :class="sep"></span>
      <div class="flex items-center justify-center gap-0.5 rounded-lg bg-ink-800/70 px-1.5 py-0.5"><StudioIcon name="brush" size="h-3 w-3" class="text-brand-300"/><button @click="store.inpaintBrushSize = Math.max(2, (store.inpaintBrushSize||10) - 2)" :class="iconBtn" class="!h-5 !w-5" title="Cọ nhỏ hơn"><StudioIcon name="minus" size="h-3 w-3"/></button><span class="w-6 text-center text-[9px] tabular-nums text-cream-100">{{ store.inpaintBrushSize || 10 }}</span><button @click="store.inpaintBrushSize = Math.min(48, (store.inpaintBrushSize||10) + 2)" :class="iconBtn" class="!h-5 !w-5" title="Cọ to hơn"><StudioIcon name="plus" size="h-3 w-3"/></button></div>
      <span :class="sep"></span>
      <button @click="store.confirmInpaintMask()" :class="[lbl, primary]" :title="store.inpaintMaskSource === 'canvas' ? 'Thoát vùng chọn' : 'Lưu vùng vẽ và thoát'"><StudioIcon name="check" :size="I"/>Xong</button>
    </template>
    <span :class="sep"></span>
    <div class="flex items-center justify-center gap-0.5 rounded-lg bg-ink-800/70 px-1.5 py-0.5"><StudioIcon name="feather" size="h-3 w-3" class="text-brand-300"/><input type="range" min="0" max="50" step="1" :value="store.inpaintFeather" @input="store.inpaintFeather = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-7 text-right text-[9px] tabular-nums text-cream-100">{{ store.inpaintFeather }}</span></div>
    <template v-if="store.inpaintMaskMode === 'rect' || store.inpaintMaskMode === 'freehand' || store.inpaintMaskMode === 'path' || store.inpaintMaskMode === 'magic'">
      <span :class="sep"></span>
      <template v-if="store.inpaintMaskMode === 'freehand' || store.inpaintMaskMode === 'path' || store.inpaintMaskMode === 'magic'">
        <button @click="store.setInpaintSelectMode('add')" :class="[iconBtn, store.inpaintSelectMode === 'add' ? on : '']" title="Cộng vào vùng chọn (+ vùng chọn)"><StudioIcon name="selectAdd" :size="I"/></button>
        <button @click="store.setInpaintSelectMode('subtract')" :class="[iconBtn, store.inpaintSelectMode === 'subtract' ? on : '']" title="Trừ khỏi vùng chọn (− vùng chọn)"><StudioIcon name="selectSubtract" :size="I"/></button>
      </template>
      <button @click="store.invertSelection()" :class="[lbl, btn]" title="Đảo ngược vùng chọn"><StudioIcon name="swapHorizontal" :size="I"/>Đảo</button>
      <button @click="store.floatSelectedRegion()" :class="[lbl, btn]" title="Nâng (cắt) vùng chọn thành layer mới"><StudioIcon name="scissors" :size="I"/>Nâng</button>
      <button @click="store.duplicateSelectedRegion()" :class="[lbl, btn]" title="Nhân đôi vùng chọn"><StudioIcon name="copy" :size="I"/>Nhân đôi</button>
      <button @click="store.deleteSelectedRegion()" :class="[lbl, btn]" title="Xóa nội dung trong vùng chọn"><StudioIcon name="trash" :size="I"/>Xóa</button>
      <label class="relative inline-flex h-7 w-7 cursor-pointer overflow-hidden rounded-full ring-1 ring-white/20" title="Chọn màu tô"><span class="absolute inset-0" :style="{ background: store.inpaintFillColor }"></span><input type="color" :value="store.inpaintFillColor" @input="store.inpaintFillColor = $event.target.value" class="absolute inset-0 cursor-pointer opacity-0"></label>
      <button @click="store.fillSelectedRegion()" :class="[lbl, btn]" title="Tô màu vào vùng chọn"><StudioIcon name="paintBucket" :size="I"/>Tô</button>
    </template>
    <button @click="store.clearInpaintMask()" :class="iconBtnDanger" title="Bỏ mask hiện tại" aria-label="Bỏ mask hiện tại"><StudioIcon name="x" :size="I"/></button>
  </div>

  <!-- ══ Đã lưu vùng ══ -->
  <div v-else-if="store.inpaintMaskDone" class="flex items-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <span class="flex items-center gap-1 px-1 text-[10px] font-medium text-cream-200"><StudioIcon name="check" :size="I"/>Đã lưu vùng</span>
    <button @click="store.toggleInpaintMask('path')" :class="[lbl, btn]" title="Mở lại để chỉnh sửa"><StudioIcon name="pencil" :size="I"/>Chỉnh lại</button>
    <button @click="store.clearInpaintMask()" :class="iconBtnDanger" title="Bỏ mask" aria-label="Bỏ mask"><StudioIcon name="x" :size="I"/></button>
  </div>

  <!-- ══ Xóa vùng (erase) ══ -->
  <div v-else-if="store.eraseMode" class="flex flex-wrap items-center justify-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <div class="flex items-center justify-center gap-0.5 rounded-lg bg-ink-800/70 px-1.5 py-0.5"><StudioIcon name="brush" size="h-3 w-3" class="text-brand-300"/><input type="range" min="3" max="150" step="1" :value="store.eraseBrushSize" @input="store.eraseBrushSize = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-8 text-right text-[9px] tabular-nums text-cream-100">{{ store.eraseBrushSize }}px</span></div>
    <div class="flex items-center justify-center gap-0.5 rounded-lg bg-ink-800/70 px-1.5 py-0.5"><StudioIcon name="feather" size="h-3 w-3" class="text-brand-300"/><input type="range" min="0" max="60" step="1" :value="store.eraseFeather" @input="store.eraseFeather = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-6 text-right text-[9px] tabular-nums text-cream-100">{{ store.eraseFeather }}</span></div>
    <span :class="sep"></span>
    <button @click="store.applyEraseNow()" :class="[lbl, btn]" title="Áp dụng nét đã xóa và vẽ tiếp"><StudioIcon name="trash" :size="I"/>Xóa</button>
    <button @click="store.finishErase()" :class="[lbl, primary]"><StudioIcon name="check" :size="I"/>Xong</button>
    <button @click="store.cancelErase()" :class="iconBtn" title="Hủy" aria-label="Hủy"><StudioIcon name="x" :size="I"/></button>
  </div>

  <!-- ══ Vẽ tự do (paint brush) — tiến tới Krita/PS ══ -->
  <div v-else-if="store.drawMode" class="flex flex-wrap items-center justify-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <label class="flex items-center gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1"><StudioIcon name="brush" size="h-3 w-3" class="shrink-0 text-brand-300"/><input type="range" min="3" max="150" step="1" :value="store.drawBrushSize" @input="store.drawBrushSize = Number($event.target.value)" class="h-1 w-14 cursor-pointer accent-cream-300"><span class="w-7 shrink-0 text-right text-[8px] tabular-nums text-cream-100">{{ store.drawBrushSize }}px</span></label>
    <label class="flex items-center gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1"><StudioIcon name="droplet" size="h-3 w-3" class="shrink-0 text-brand-300"/><span class="text-[9px] text-cream-300/60">Đậm</span><input type="range" min="0.05" max="1" step="0.05" :value="store.drawOpacity" @input="store.drawOpacity = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-7 shrink-0 text-right text-[8px] tabular-nums text-cream-100">{{ Math.round(store.drawOpacity * 100) }}%</span></label>
    <label class="flex items-center gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1"><StudioIcon name="feather" size="h-3 w-3" class="shrink-0 text-brand-300"/><span class="text-[9px] text-cream-300/60">Mềm</span><input type="range" min="0" max="60" step="1" :value="store.drawSoftness" @input="store.drawSoftness = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-5 shrink-0 text-right text-[8px] tabular-nums text-cream-100">{{ store.drawSoftness }}</span></label>
    <label class="flex items-center gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1"><StudioIcon name="hardness" size="h-3 w-3" class="shrink-0 text-brand-300"/><span class="text-[9px] text-cream-300/60">Cứng</span><input type="range" min="0" max="100" step="1" :value="store.drawHardness" @input="store.drawHardness = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-7 shrink-0 text-right text-[8px] tabular-nums text-cream-100">{{ store.drawHardness }}%</span></label>
    <label class="flex items-center gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1"><StudioIcon name="droplet" size="h-3 w-3" class="shrink-0 text-brand-300"/><span class="text-[9px] text-cream-300/60">Mực</span><input type="range" min="0.05" max="1" step="0.05" :value="store.drawFlow" @input="store.drawFlow = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-7 shrink-0 text-right text-[8px] tabular-nums text-cream-100">{{ Math.round(store.drawFlow * 100) }}%</span></label>
    <label class="flex items-center gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1"><StudioIcon name="betweenDots" size="h-3 w-3" class="shrink-0 text-brand-300"/><span class="text-[9px] text-cream-300/60">Khoảng</span><input type="range" min="0.05" max="1" step="0.05" :value="store.drawSpacing" @input="store.drawSpacing = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-7 shrink-0 text-right text-[8px] tabular-nums text-cream-100">{{ Math.round(store.drawSpacing * 100) }}%</span></label>
    <label class="flex items-center gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1"><StudioIcon name="waves" size="h-3 w-3" class="shrink-0 text-brand-300"/><span class="text-[9px] text-cream-300/60">Mượt</span><input type="range" min="0" max="100" step="1" :value="store.drawSmoothing" @input="store.drawSmoothing = Number($event.target.value)" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-5 shrink-0 text-right text-[8px] tabular-nums text-cream-100">{{ store.drawSmoothing }}</span></label>
    <label class="flex items-center gap-1.5 rounded-lg bg-ink-800/70 px-1.5 py-1"><StudioIcon name="blend" size="h-3 w-3" class="shrink-0 text-brand-300"/><span class="text-[9px] text-cream-300/60">Chế độ</span><select :value="store.drawBlend" @change="store.drawBlend = $event.target.value" class="h-7 rounded-lg border border-ink-700 bg-ink-800 px-1 text-[10px] text-cream-100 focus:outline-none"><option value="normal">Thường</option><option value="multiply">Nhân</option><option value="screen">Màn hình</option><option value="overlay">Phủ</option><option value="darken">Tối</option><option value="lighten">Sáng</option></select></label>
    <label class="relative inline-flex h-7 w-7 shrink-0 cursor-pointer overflow-hidden rounded-full ring-1 ring-white/20" title="Chọn màu vẽ"><span class="absolute inset-0" :style="{ background: store.inpaintFillColor }"></span><input type="color" :value="store.inpaintFillColor" @input="store.inpaintFillColor = $event.target.value" class="absolute inset-0 cursor-pointer opacity-0"></label>
    <button @click="store.applyDrawNow()" :class="[lbl, btn]" title="Áp dụng nét vẽ và vẽ tiếp"><StudioIcon name="brush" :size="I"/>Vẽ</button>
    <button @click="store.finishDraw()" :class="[lbl, primary]"><StudioIcon name="check" :size="I"/>Xong</button>
    <button @click="store.cancelDraw()" :class="iconBtn" title="Hủy" aria-label="Hủy"><StudioIcon name="x" :size="I"/></button>
  </div>

  <!-- ══ Crop ══ -->
  <div v-else-if="store.reframeOpen || store.cropMode" class="flex flex-wrap items-center justify-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <span class="flex items-center gap-1 text-cream-200"><StudioIcon name="crop" :size="I"/>Crop</span>
    <button v-for="r in reframeRatios" :key="r" type="button" @click="store.reframeRatio = r" class="rounded-full border px-2 py-0.5 text-[11px] font-medium transition-colors" :class="store.reframeRatio === r ? chipOn : chipOff">{{ r }}</button>
    <span class="h-4 w-px bg-ink-600"></span>
    <button @click="store.reframeCenter()" :disabled="store.reframing || !store.upscaleSrc" :class="[lbl, btn]" class="disabled:opacity-40">{{ store.reframing ? 'Đang cắt…' : 'Cắt giữa' }}</button>
    <button @click="store.toggleCrop()" :disabled="store.reframing || !store.upscaleSrc" :class="[lbl, store.cropMode ? on : btn]" class="disabled:opacity-40"><StudioIcon name="boxSelect" :size="I"/>{{ store.cropMode ? 'Hủy chọn vùng' : 'Chọn vùng' }}</button>
    <button v-if="store.cropMode" @click="store.confirmCrop()" :disabled="store.reframing || !store.upscaleSrc" :class="[lbl, primary]" class="disabled:opacity-40"><StudioIcon name="check" :size="I"/>Áp dụng</button>
    <button @click="store.reframeOpen = false; if (store.cropMode) store.toggleCrop()" :class="iconBtnDanger" title="Đóng" aria-label="Đóng"><StudioIcon name="x" :size="I"/></button>
  </div>

  <!-- ══ Film Look ══ -->
  <div v-else-if="store.filmOpen || store.looking" class="flex flex-wrap items-center justify-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <span class="flex items-center gap-1 text-cream-200"><StudioIcon name="palette" :size="I"/>Look</span>
    <button v-for="p in looks" :key="p[0]" type="button" @click="store.lookPreset = p[0]" class="rounded-full border px-2 py-0.5 text-[11px] font-medium transition-colors" :class="store.lookPreset === p[0] ? chipOn : chipOff">{{ p[1] }}</button>
    <span class="h-4 w-px bg-ink-600"></span>
    <div class="flex items-center justify-center gap-0.5 rounded-lg bg-ink-800/70 px-1.5 py-0.5"><StudioIcon name="sliders" size="h-3 w-3" class="text-brand-300"/><input type="range" min="0" max="10" step="1" v-model.number="store.lookLevel" class="h-1 w-12 cursor-pointer accent-cream-300"><span class="w-8 text-right text-[9px] tabular-nums text-cream-100">{{ store.lookLevel }}/10</span></div>
    <button @click="store.applyFilmLook()" :disabled="store.looking || !store.upscaleSrc" :class="[lbl, primary]" class="disabled:opacity-40">{{ store.looking ? 'Đang áp dụng…' : 'Áp dụng' }}</button>
    <button @click="store.filmOpen = false" :class="iconBtnDanger" title="Đóng" aria-label="Đóng"><StudioIcon name="x" :size="I"/></button>
  </div>

  <!-- ══ Layer Selection: hiện khi có layer ĐANG CHỌN (không cần bật selectTool) ══ -->
  <div v-else-if="store.activeLayer && !store.selectTool" class="flex flex-wrap items-center justify-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <!-- Nút checkbox chọn nhiều: toggle selectTool → cho phép thêm/bớt layer vào selection -->
    <button @click="store.selectTool = !store.selectTool" :class="[lbl, store.selectTool ? on : btn]" title="Bật/tắt chọn nhiều layer — bấm layer để thêm/bớt vào nhóm chọn (giống Shift+click)"><StudioIcon name="checkSquare" :size="I"/>Chọn nhiều</button>
    <!-- Căn lề (hiện khi >= 2 layer) -->
    <template v-if="store.selectionUnitCount >= 2">
      <span :class="sep"></span>
      <button @click="store.alignSelection('left')" :class="iconBtn" title="Căn trái"><StudioIcon name="alignStartHorizontal" :size="I"/></button>
      <button @click="store.alignSelection('hcenter')" :class="iconBtn" title="Căn giữa ngang"><StudioIcon name="alignCenterHorizontal" :size="I"/></button>
      <button @click="store.alignSelection('right')" :class="iconBtn" title="Căn phải"><StudioIcon name="alignEndHorizontal" :size="I"/></button>
      <button @click="store.alignSelection('top')" :class="iconBtn" title="Căn trên"><StudioIcon name="alignStartVertical" :size="I"/></button>
      <button @click="store.alignSelection('vcenter')" :class="iconBtn" title="Căn giữa dọc"><StudioIcon name="alignCenterVertical" :size="I"/></button>
      <button @click="store.alignSelection('bottom')" :class="iconBtn" title="Căn dưới"><StudioIcon name="alignEndVertical" :size="I"/></button>
      <span :class="sep"></span>
      <button @click="store.distributeSelection('x')" :class="iconBtn" :disabled="store.selectionUnitCount < 3" title="Chia đều theo chiều ngang"><StudioIcon name="distributeHorizontal" :size="I"/></button>
      <button @click="store.distributeSelection('y')" :class="iconBtn" :disabled="store.selectionUnitCount < 3" title="Chia đều theo chiều dọc"><StudioIcon name="distributeVertical" :size="I"/></button>
      <button @click="store.groupSelection()" :class="[lbl, '!bg-brand-600/20 !text-brand-200 hover:!bg-brand-600 hover:!text-white']" title="Tạo nhóm từ các layer đang chọn"><StudioIcon name="group" :size="I"/>Nhóm</button>
    </template>
    <!-- Nút Tách nhóm: luôn hiện nếu ít nhất 1 layer thuộc group (kể cả chọn 1 layer trong group) -->
    <button v-if="store.selection.some(l => l.groupId)" @click="store.ungroupSelection()" :class="[lbl, 'text-amber-300 hover:bg-amber-600/25 hover:text-amber-200']" title="Tách nhóm — trả các layer về độc lập"><StudioIcon name="unlink" :size="I"/>Tách</button>
    <span :class="sep"></span>
    <button @click="store.duplicateActiveUnit()" :class="iconBtn" title="Nhân đôi (Ctrl+D)"><StudioIcon name="copy" :size="I"/></button>
    <button @click="store.deleteSelection()" :class="iconBtnDanger" title="Xóa layer đang chọn (Delete)"><StudioIcon name="trash" :size="I"/></button>
    <button @click="store.downloadSelection()" :class="[lbl, btn]" title="Tải layer đang chọn"><StudioIcon name="download" :size="I"/>Tải</button>
    <span :class="sep"></span>
    <button @click="store.zoomFitSelection()" :class="[lbl, btn]" title="Fit canvas — đưa các layer/group đang chọn vào giữa khung nhìn"><StudioIcon name="maximize" :size="I"/>Fit chọn</button>
    <span :class="sep"></span>
    <!-- Số layer đang chọn -->
    <span class="flex items-center gap-1 rounded-full bg-ink-800 px-2 py-0.5 text-[10px] text-cream-300/70"><StudioIcon name="layers" size="h-3 w-3" class="text-brand-300" />{{ store.selectionUnitCount || 1 }}</span>
  </div>

  <!-- ══ Select Tool: marquee chọn hàng loạt + chọn tất cả / bỏ chọn + fit selection ══ -->
  <div v-else-if="store.selectTool" class="flex flex-wrap items-center justify-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <span class="flex items-center gap-1 px-1 text-[10px] font-medium text-cream-200"><StudioIcon name="cursor" :size="I"/>Quét chọn</span>
    <button @click="store.selectAll()" :class="[lbl, btn]" title="Chọn tất cả layer (Ctrl+A)"><StudioIcon name="selectAll" :size="I"/>Tất cả</button>
    <button @click="store.deselectAll()" :class="[lbl, btn]" title="Bỏ chọn tất cả (Esc)"><StudioIcon name="x" :size="I"/>Bỏ chọn</button>
    <span :class="sep"></span>
    <!-- Hành động hiện khi có layer đang chọn -->
    <template v-if="store.selectionUnitCount >= 2">
      <button @click="store.alignSelection('left')" :class="iconBtn" title="Căn trái"><StudioIcon name="alignStartHorizontal" :size="I"/></button>
      <button @click="store.alignSelection('hcenter')" :class="iconBtn" title="Căn giữa ngang"><StudioIcon name="alignCenterHorizontal" :size="I"/></button>
      <button @click="store.alignSelection('right')" :class="iconBtn" title="Căn phải"><StudioIcon name="alignEndHorizontal" :size="I"/></button>
      <button @click="store.alignSelection('top')" :class="iconBtn" title="Căn trên"><StudioIcon name="alignStartVertical" :size="I"/></button>
      <button @click="store.alignSelection('vcenter')" :class="iconBtn" title="Căn giữa dọc"><StudioIcon name="alignCenterVertical" :size="I"/></button>
      <button @click="store.alignSelection('bottom')" :class="iconBtn" title="Căn dưới"><StudioIcon name="alignEndVertical" :size="I"/></button>
      <span :class="sep"></span>
      <button @click="store.distributeSelection('x')" :class="iconBtn" :disabled="store.selectionUnitCount < 3" title="Chia đều theo chiều ngang"><StudioIcon name="distributeHorizontal" :size="I"/></button>
      <button @click="store.distributeSelection('y')" :class="iconBtn" :disabled="store.selectionUnitCount < 3" title="Chia đều theo chiều dọc"><StudioIcon name="distributeVertical" :size="I"/></button>
      <button @click="store.groupSelection()" :class="[lbl, '!bg-brand-600/20 !text-brand-200 hover:!bg-brand-600 hover:!text-white']" title="Tạo nhóm"><StudioIcon name="group" :size="I"/>Nhóm</button>
    </template>
    <button v-if="store.selection.some(l => l.groupId)" @click="store.ungroupSelection()" :class="[lbl, 'text-amber-300 hover:bg-amber-600/25 hover:text-amber-200']" title="Tách nhóm"><StudioIcon name="unlink" :size="I"/>Tách</button>
    <template v-if="store.selectionUnitCount">
      <button @click="store.duplicateActiveUnit()" :class="iconBtn" title="Nhân đôi (Ctrl+D)"><StudioIcon name="copy" :size="I"/></button>
      <button @click="store.deleteSelection()" :class="iconBtnDanger" title="Xóa (Delete)"><StudioIcon name="trash" :size="I"/></button>
      <button @click="store.downloadSelection()" :class="[lbl, btn]" title="Tải"><StudioIcon name="download" :size="I"/>Tải</button>
    </template>
    <span :class="sep"></span>
    <button @click="store.zoomFitSelection()" :class="[lbl, btn]" title="Fit canvas — đưa các layer/group đang chọn vào giữa khung nhìn"><StudioIcon name="maximize" :size="I"/>Fit chọn</button>
    <span :class="sep"></span>
    <span class="flex items-center gap-1 rounded-full bg-ink-800 px-2 py-0.5 text-[10px] text-cream-300/70"><StudioIcon name="layers" size="h-3 w-3" class="text-brand-300" />{{ store.selectionUnitCount || (store.activeLayer ? 1 : 0) }}</span>
  </div>

  <!-- ══ Pan tool: context bar gọn với zoom + fit ══ -->
  <div v-else-if="store.panMode" class="flex flex-wrap items-center justify-center gap-1.5 rounded-lg bg-ink-900/95 px-2.5 py-2 text-xs font-semibold shadow-xl ring-1" :class="ring">
    <span class="flex items-center gap-1 px-1 text-[10px] font-medium text-cream-200"><StudioIcon name="hand" :size="I"/>Di chuyển canvas</span>
    <button @click="store.zoomOut()" :class="iconBtn" title="Thu nhỏ"><StudioIcon name="zoomOut" :size="I"/></button>
    <button @click="store.zoomIn()" :class="iconBtn" title="Phóng to"><StudioIcon name="zoomIn" :size="I"/></button>
    <button @click="store.zoomFit()" :class="[lbl, btn]" title="Fit canvas — toàn bộ layer vừa khung nhìn"><StudioIcon name="maximize" :size="I"/>Fit toàn bộ</button>
  </div>

  <!-- ══ No active tool: placeholder text ══ -->
  <span v-else class="text-[11px] font-medium text-cream-300/50">Chọn công cụ từ thanh công cụ cạnh canvas (Esc = hủy · Enter = xong)</span>
</template>