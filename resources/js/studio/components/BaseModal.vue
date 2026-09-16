<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  wide: { type: Boolean, default: false },
  height: { type: String, default: '' },  // chiều cao cố định (vd '80vh') -> header/tab cố định, body cuộn
});
const emit = defineEmits(['update:modelValue']);

// §5.2 (a11y): trước đây modal không có role/aria-modal, không đóng bằng Esc và không nhận focus
// -> trình đọc màn hình không biết đây là hộp thoại, và bàn phím vẫn đi xuyên ra sau lớp phủ.
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
  <div v-if="modelValue" ref="rootEl" tabindex="-1" role="dialog" aria-modal="true" :aria-label="title || undefined"
       class="fixed inset-0 z-[70] flex items-center justify-center bg-black/70 p-4 outline-none"
       @click.self="close">
    <!-- Mode 1: có height cố định -> flex-col + header cố định + body cuộn (inline style chống override) -->
    <div
      v-if="height"
      class="w-full overflow-hidden rounded-lg border border-brand-500/40 bg-ink-900 shadow-2xl"
      :class="wide ? 'max-w-3xl' : 'max-w-lg'"
      :style="{ display: 'flex', flexDirection: 'column', height: height, maxHeight: 'calc(100vh - 2rem)' }"
      @click.stop
    >
      <div class="flex h-14 shrink-0 items-center justify-between border-b border-ink-700 bg-ink-900 px-5">
        <span class="text-sm font-semibold text-brand-300">{{ title }}</span>
        <button @click="close" aria-label="Đóng" class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-ink-700 text-cream-200 transition hover:bg-red-600 hover:text-white" title="Đóng">✕</button>
      </div>
      <div style="flex: 1 1 0; min-height: 0; overflow-y: auto; overscroll-behavior: contain;">
        <slot />
      </div>
    </div>
    <!-- Mode 2: không height -> auto tối đa 85vh, toàn bộ cuộn + header sticky -->
    <div
      v-else
      class="w-full overflow-y-auto overscroll-contain rounded-lg border border-brand-500/40 bg-ink-900 shadow-2xl"
      :class="wide ? 'max-w-3xl' : 'max-w-lg'"
      style="max-height: 85vh"
      @click.stop
    >
      <div class="sticky top-0 z-20 flex h-14 shrink-0 items-center justify-between border-b border-ink-700 bg-ink-900 px-5">
        <span class="text-sm font-semibold text-brand-300">{{ title }}</span>
        <button @click="close" aria-label="Đóng" class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-ink-700 text-cream-200 transition hover:bg-red-600 hover:text-white" title="Đóng">✕</button>
      </div>
      <div class="p-5">
        <slot />
      </div>
    </div>
  </div>
</template>