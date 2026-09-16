<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import CompareSlider from './CompareSlider.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';
import BaseModal from './BaseModal.vue';
const store = useStudioStore();

const beforeUrl = ref('');
const compareOpen = ref(false);
function submitInpaint() {
  beforeUrl.value = store.upscaleSrc || '';
  store.inpaint(store.inpaintPrompt);
}

const activeImg = computed(() => store.upscaleSrc || '');

// ── Chip "Đổi màu" → popup chọn màu target ──
const colorPickerOpen = ref(false);
const editColor = ref('#e11d48');
const colorNames = { '#e11d48':'đỏ','#f97316':'cam','#facc15':'vàng','#22c55e':'xanh lá','#06b6d4':'xanh lơ','#2563eb':'xanh dương','#7c3aed':'tím','#ec4899':'hồng','#0f172a':'đen','#f8fafc':'trắng','#6b7280':'xám' };
const colorName = (hex) => colorNames[hex] || hex;

// ── Chip "Thay nền" → popup nhập mô tả nền ──
const bgPromptOpen = ref(false);
const bgPromptInput = ref('');

// ── Preset chỉnh sửa từ Prompt Templates (category inpaint) ──
function applyPreset(p) { store.inpaintPrompt = p.prompt; store.toast('Đã điền: ' + p.label); }
function chipIcon(p) { const l = (p.label || '').toLowerCase(); if (l.includes('tạp chất') || l.includes('vật thể')) return 'trash'; if (l.includes('làm sạch')) return 'sparkles'; if (l.includes('dáng')) return 'shirt'; if (l.includes('chất liệu')) return 'feather'; if (l.includes('đổi màu')) return 'palette'; return 'wand'; }

// ── Ctrl/Cmd + Enter để gửi ──
function onPromptKeydown(e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); if (canSubmit.value) submitInpaint(); } }

const now = ref(Date.now());
let timer = null;
onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => { if (timer) clearInterval(timer); });

const elapsedSec = computed(() => store.inpaintStartTs ? Math.max(0, Math.floor((now.value - store.inpaintStartTs) / 1000)) : 0);
const fmt = (s) => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');

const activeGen = computed(() => store.inpaintGenId ? store.generations.find(g => g.id === Number(store.inpaintGenId)) : null);
const canSubmit = computed(() => !!activeImg.value && !store.inpainting && !!store.inpaintPrompt.trim());
const running = computed(() => store.inpaintStage === 'send' || store.inpaintStage === 'processing');
const maskActive = computed(() => store.inpaintMaskMode !== 'none');
</script>
<template>
  <div class="card p-5" style="background: linear-gradient(160deg, rgba(124,200,90,.13), rgba(74,122,144,.06));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300">
      <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-brand-600/20 text-brand-300"><StudioIcon name="pencil" size="h-4 w-4" /></span>
      Sửa ảnh
      <span class="rounded-full bg-brand-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-brand-200">AI</span>
    </h2>

    <!-- Ảnh đang chọn -->
    <div v-if="activeImg" class="mt-3 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-2.5">
      <img :src="activeImg" class="h-14 w-14 shrink-0 rounded-md bg-ink-900 object-cover">
      <div class="min-w-0 text-xs text-cream-200">
        <p class="truncate font-semibold">{{ store.upscaleName || 'Ảnh đang chọn' }}</p>
        <p class="text-cream-300/60">Sẽ sửa trực tiếp trên ảnh này</p>
      </div>
    </div>
    <div v-else class="mt-3 rounded-lg border border-dashed border-white/15 bg-white/5 p-3 text-xs text-cream-300/60">Chọn một ảnh trên <b>canvas</b> (Nguồn / Kết quả / sản phẩm) để sửa.</div>

    <!-- Vẽ mask -->
    <div v-if="activeImg" class="mt-3 space-y-2">
      <button @click="store.toggleInpaintMask('path')"
              class="group flex w-full items-center justify-center gap-2.5 rounded-lg border px-4 py-3 text-sm font-semibold transition-all duration-200"
              :class="store.inpaintMaskMode === 'path'
                ? 'border-emerald-400 bg-gradient-to-r from-emerald-500 to-teal-500 text-white shadow-lg shadow-emerald-900/30'
                : 'border-emerald-400/40 bg-emerald-500/10 text-emerald-200 hover:border-emerald-400/80 hover:bg-emerald-500/20 hover:shadow-md hover:shadow-emerald-900/20 active:scale-[.98]'">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md transition-colors"
              :class="store.inpaintMaskMode === 'path' ? 'bg-white/20' : 'bg-emerald-500/20 group-hover:bg-emerald-500/30'">
          <StudioIcon name="penTool" size="h-4 w-4" />
        </span>
        <span class="flex flex-col items-start text-left leading-tight">
          <span class="text-[13px] font-bold">{{ store.inpaintMaskMode === 'path' ? 'Đang vẽ mask — đóng kín để hoàn tất' : 'Vẽ mask' }}</span>
          <span class="text-[10px] opacity-80">Vùng chọn bằng đường cong (Bezier)</span>
        </span>
      </button>
      <!-- Undo / Redo riêng cho mask -->
      <div v-if="store.inpaintMaskMode === 'path'" class="flex justify-center gap-1.5">
        <button @click="store.inpaintPathUndo()" class="flex items-center gap-1 rounded-full border border-ink-600 px-2.5 py-1 text-[10px] font-semibold text-cream-200 transition hover:border-brand-400 hover:bg-ink-800" title="Hoàn tác (Ctrl+Z)"><StudioIcon name="undo" size="h-3 w-3" /> Hoàn tác</button>
        <button @click="store.inpaintPathRedo()" class="flex items-center gap-1 rounded-full border border-ink-600 px-2.5 py-1 text-[10px] font-semibold text-cream-200 transition hover:border-brand-400 hover:bg-ink-800" title="Làm lại (Ctrl+Y)"><StudioIcon name="redo" size="h-3 w-3" /> Làm lại</button>
      </div>
      <div v-if="maskActive || store.inpaintMaskDone" class="flex justify-center">
        <button @click="store.clearInpaintMask()" class="rounded-full bg-red-600/25 px-3 py-1 text-[10px] font-semibold text-red-200 transition-colors hover:bg-red-600 hover:text-white">Bỏ mask</button>
      </div>
    </div>
    <div v-if="maskActive" class="mt-1.5 rounded-md border border-brand-500/30 bg-brand-900/20 px-2.5 py-1.5 text-[10px] text-brand-200">Vẽ đường cong quanh vùng cần sửa — quay lại điểm đầu để đóng kín, vùng chọn tự thành mask.</div>
    <!-- Trạng thái mask đã lưu: lưới mini preview -->
    <div v-else-if="store.inpaintMaskDone" class="mt-1.5 rounded-md border border-emerald-500/30 bg-emerald-900/20 px-2.5 py-2 text-[10px] text-emerald-200">
      <div class="grid grid-cols-[auto_1fr_auto] items-center gap-3">
        <div class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-md border border-white/20 bg-[repeating-conic-gradient(#d8d2c4_0_25%,#fff_0_50%)] bg-[length:16px_16px]">
          <img v-if="store.inpaintBrushData" :src="'data:image/png;base64,' + store.inpaintBrushData" class="h-full w-full object-contain" alt="Mask" />
          <span v-else class="text-[9px] text-ink-500">Xóa hộp chọn</span>
        </div>
        <div class="min-w-0">
          <p class="font-semibold">Đã vẽ mask — AI chỉ sửa vùng đen.</p>
          <p class="mt-0.5 text-emerald-200/70">Quay lại điểm đầu để đóng, hoặc vẽ vùng mới.</p>
        </div>
        <button @click="store.toggleInpaintMask('path')" class="shrink-0 rounded-full bg-white/10 px-2 py-0.5 font-semibold hover:bg-white/20">Chỉnh lại</button>
      </div>
    </div>

    <!-- Chỉnh nhanh: 2 chip đặc biệt (đổi màu / thay nền) + preset từ Prompt Templates -->
    <p class="label mt-4"><StudioIcon name="zap" size="h-3.5 w-3.5" class="-mt-0.5 mr-1 inline text-brand-300" /> Chỉnh nhanh</p>
    <div class="mt-1 grid grid-cols-2 gap-1.5">
      <button @click="colorPickerOpen = true" class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-[10px] font-semibold transition-all" :class="store.inpaintPrompt.startsWith('đổi màu quần áo') ? 'border-brand-400 bg-brand-600/25 text-cream-50 shadow-brand-500/20' : 'border-ink-700 bg-ink-800 text-cream-200 hover:border-brand-400/50 hover:bg-ink-700'">
        <StudioIcon name="palette" size="h-3.5 w-3.5" class="shrink-0 text-brand-300" /> <span class="truncate">Đổi màu…</span>
      </button>
      <button @click="bgPromptOpen = true" class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-[10px] font-semibold transition-all" :class="store.inpaintPrompt.startsWith('thay đổi phông nền') ? 'border-brand-400 bg-brand-600/25 text-cream-50 shadow-brand-500/20' : 'border-ink-700 bg-ink-800 text-cream-200 hover:border-brand-400/50 hover:bg-ink-700'">
        <StudioIcon name="wand" size="h-3.5 w-3.5" class="shrink-0 text-brand-300" /> <span class="truncate">Thay nền…</span>
      </button>
    </div>
    <div v-if="store.inpaintEditPresets.length" class="mt-1.5 grid grid-cols-2 gap-1.5">
      <button v-for="p in store.inpaintEditPresets" :key="p.id" @click="applyPreset(p)" :title="p.prompt"
              class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-[10px] font-semibold transition-all"
              :class="store.inpaintPrompt === p.prompt ? 'border-brand-400 bg-brand-600/25 text-cream-50 shadow-brand-500/20' : 'border-ink-700 bg-ink-800 text-cream-200 hover:border-brand-400/50 hover:bg-ink-700'">
        <StudioIcon :name="chipIcon(p)" size="h-3.5 w-3.5" class="shrink-0 text-brand-300" />
        <span class="truncate">{{ p.label }}</span>
      </button>
    </div>

    <label class="label mt-4">Mô tả chỉnh sửa</label>
    <textarea v-model="store.inpaintPrompt" rows="3" maxlength="1000" @keydown="onPromptKeydown" class="input !text-xs" placeholder="VD: đổi màu áo thành đỏ, ngắn tay hơn, thêm túi trước… (Ctrl+Enter để gửi)"></textarea>
    <p class="mt-1 text-right text-[10px] text-cream-300/50">{{ store.inpaintPrompt.length }}/1000</p>

    <!-- Model chỉnh sửa — nhóm edit (Cài đặt → 🎯 Nhóm công việc) -->
    <div v-if="store.taskGroupModels('edit').length > 1" class="mt-2 flex items-center gap-2">
      <span class="shrink-0 text-[10px] font-medium text-cream-300/60">🤖</span>
      <select v-model="store.inpaintModel" class="input !py-2 !text-xs" title="Model chỉnh sửa ảnh — danh sách từ Cài đặt → 🎯 Nhóm công việc (edit)">
        <option value="">Mặc định ({{ (store.taskGroupModels('edit')[0] || store.inpaintModels[0] || {}).label || 'Qwen Edit' }})</option>
        <option v-for="m in store.taskGroupModels('edit')" :key="m.provider + m.model" :value="m.provider + ':' + m.model">{{ m.label }}</option>
      </select>
    </div>

    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-cream-200">
      <label class="flex cursor-pointer items-center gap-1.5"><input type="checkbox" v-model="store.inpaintPreserveFace" class="h-3.5 w-3.5 accent-brand-500"> Giữ nguyên khuôn mặt & dáng</label>
      <label class="flex cursor-pointer items-center gap-1.5"><input type="checkbox" v-model="store.inpaintPreserveBg" class="h-3.5 w-3.5 accent-brand-500"> Giữ nguyên nền</label>
    </div>

    <button @click="submitInpaint" :disabled="!canSubmit" class="btn-brand mt-3 w-full whitespace-nowrap">
      <span v-if="store.inpainting && store.inpaintStage === 'send'">Đang gửi yêu cầu…</span>
      <span v-else-if="store.inpainting">AI đang chỉnh sửa…</span>
      <span v-else>Sửa ảnh <span class="opacity-70">· {{ store.imageCreditCost }} credit</span></span>
    </button>

    <!-- Tiến độ -->
    <div v-if="running" class="mt-3 rounded-lg border border-brand-500/30 bg-brand-900/30 p-3">
      <LoadingSpinner :text="store.inpaintStage === 'send' ? 'Đang gửi yêu cầu tới AI…' : 'AI đang chỉnh sửa ảnh…'" :subtext="fmt(elapsedSec) + ' · Nhiệm vụ #' + store.inpaintGenId + (activeGen?.model ? ' · Model: ' + activeGen.model : '')" />
      <div class="mt-2 flex justify-end"><button @click="store.cancelInpaint()" class="rounded-full bg-red-600/25 px-2.5 py-1 text-[10px] font-semibold text-red-200 hover:bg-red-600">Hủy</button></div>
    </div>

    <!-- Thành công -->
    <div v-if="store.inpaintStage === 'done'" class="mt-3 flex items-center gap-2 rounded-lg border border-emerald-500/40 bg-emerald-900/25 p-3 text-xs text-emerald-200">
      Đã sửa xong — ảnh mới đã được chọn trong Outputs.
      <button v-if="beforeUrl && activeGen?.media_url" @click="compareOpen = true" class="rounded-full bg-white/10 px-2 py-0.5 font-semibold hover:bg-white/20">So sánh Trước/Sau</button>
      <button @click="store.clearInpaintStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <!-- Lỗi -->
    <div v-if="store.inpaintStage === 'error' && store.inpaintError" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-3 text-xs text-red-200">
      <p class="font-semibold">Sửa ảnh thất bại</p>
      <p class="mt-1 whitespace-pre-line leading-relaxed">{{ store.inpaintError }}</p>
      <div class="mt-2 flex gap-2">
        <button @click="store.inpaint(store.inpaintPrompt)" class="btn-brand btn-sm">Thử lại</button>
        <button @click="store.clearInpaintStatus()" class="btn-ghost btn-sm">Đóng</button>
      </div>
    </div>

    <!-- Đã hủy -->
    <div v-if="store.inpaintStage === 'cancelled'" class="mt-3 flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 p-3 text-xs text-cream-200">
      Đã hủy yêu cầu sửa ảnh.
      <button @click="store.clearInpaintStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <CompareSlider v-model="compareOpen" :before="beforeUrl" :after="activeGen?.media_url || ''" />

    <!-- Popup chọn màu target -->
    <BaseModal v-model="colorPickerOpen" title="Chọn màu target">
      <div class="flex flex-col items-center gap-3 p-5">
        <div class="h-12 w-full rounded-md border border-white/20" :style="{ background: editColor }"></div>
        <div class="flex flex-wrap justify-center gap-1.5">
          <button v-for="(name, hex) in colorNames" :key="hex" @click="editColor = hex" :title="'Màu ' + name" class="h-8 w-8 rounded-full border border-white/20 transition" :class="editColor === hex ? 'ring-2 ring-brand-400 ring-offset-2 ring-offset-ink-900' : 'hover:scale-110'" :style="{ background: hex }"></button>
        </div>
        <input type="color" v-model="editColor" class="h-9 w-full cursor-pointer rounded-lg border border-ink-700 bg-ink-800" title="Chọn màu tùy ý" />
        <button @click="store.inpaintPrompt = 'đổi màu quần áo trong vùng chọn sang màu ' + colorName(editColor) + ' (' + editColor + '), giữ nguyên chi tiết, chất liệu và phong cách.'; store.toast('Đã điền: Đổi màu → ' + colorName(editColor)); colorPickerOpen = false" class="btn-brand btn-sm w-full">Áp dụng màu</button>
      </div>
    </BaseModal>

    <!-- Popup nhập mô tả nền mới -->
    <BaseModal v-model="bgPromptOpen" title="Thay nền — nhập mô tả">
      <div class="p-5">
      <textarea v-model="bgPromptInput" rows="3" class="input !text-xs" placeholder="VD: phông studio màu pastel, gradient xanh dương, nền biển…"></textarea>
      <button @click="store.inpaintPrompt = 'thay đổi phông nền trong vùng chọn: ' + (bgPromptInput.trim() || 'theo mô tả') + ', giữ nguyên chủ thể.'; store.toast('Đã điền: Thay nền'); bgPromptOpen = false" class="btn-brand mt-2 w-full">Áp dụng</button>
      </div>
    </BaseModal>
  </div>
</template>