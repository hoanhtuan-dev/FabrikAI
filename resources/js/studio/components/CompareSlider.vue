<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  before: { type: String, default: '' },
  after: { type: String, default: '' },
  title: { type: String, default: 'So sánh Trước / Sau' },
});
const emit = defineEmits(['update:modelValue']);
const pos = ref(50);

// §5.2 (a11y): đây là overlay TOÀN MÀN HÌNH (fixed inset-0 + nền đen) nhưng trước đây không có
// role/aria-modal, không đóng bằng Esc và không nhận focus -> cùng lớp lỗi đã vá ở BaseModal.vue mà
// component này bị bỏ sót. Nay theo đúng mẫu đó.
const rootEl = ref(null);
function close() { emit('update:modelValue', false); }
function onKey(e) {
  if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
}
watch(() => props.modelValue, async (open) => {
  if (open) {
    window.addEventListener('keydown', onKey, true);
    await nextTick();
    rootEl.value?.focus?.();
  } else {
    window.removeEventListener('keydown', onKey, true);
  }
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true));
</script>
<template>
  <div v-if="modelValue" ref="rootEl" tabindex="-1" role="dialog" aria-modal="true" :aria-label="title" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/90 p-4 outline-none" @click.self="close">
    <div class="w-full max-w-3xl">
      <div class="mb-3 flex items-center justify-between">
        <span class="text-sm font-semibold text-cream-100">{{ title }}</span>
        <button @click="close" aria-label="Đóng" title="Đóng (Esc)" class="grid h-8 w-8 place-items-center rounded-full bg-ink-700 text-cream-200 hover:text-white">✕</button>
      </div>

      <div class="relative mx-auto aspect-square max-h-[70vh] w-full select-none overflow-hidden rounded-lg border border-white/10 bg-ink-900">
        <!-- Ảnh Trước (nền) -->
        <img :src="before" class="absolute inset-0 h-full w-full object-contain" draggable="false">
        <!-- Ảnh Sau (phủ, cắt theo vị trí slider) -->
        <div class="absolute inset-0" :style="{ clipPath: 'inset(0 0 0 ' + pos + '%)' }">
          <img :src="after" class="h-full w-full object-contain" draggable="false">
        </div>
        <!-- Đường chia -->
        <div class="pointer-events-none absolute inset-y-0 w-0.5 bg-invert shadow" :style="{ left: pos + '%' }"></div>
        <div class="pointer-events-none absolute top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full bg-invert text-invert-content shadow" :style="{ left: 'calc(' + pos + '% - 16px)' }">⇄</div>
        <!-- Nhãn -->
        <span class="pointer-events-none absolute left-2 top-2 rounded-full bg-scrim/60 px-2 py-0.5 text-[10px] font-semibold text-scrim-content">Trước</span>
        <span class="pointer-events-none absolute right-2 top-2 rounded-full bg-scrim/60 px-2 py-0.5 text-[10px] font-semibold text-scrim-content">Sau</span>
      </div>

      <input type="range" min="0" max="100" step="1" v-model.number="pos" class="mt-4 h-2 w-full cursor-pointer accent-brand-500">
    </div>
  </div>
</template>