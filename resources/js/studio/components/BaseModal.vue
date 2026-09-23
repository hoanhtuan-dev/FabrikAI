<script setup>
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';
import { useFocusTrap } from '../composables/useFocusTrap.js';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  wide: { type: Boolean, default: false },
  full: { type: Boolean, default: false },  // workspace lớn: gần toàn màn hình, nội dung tự chia cột
  //  LUU Y: 'full' phai duoc ton trong o CA HAI mode. Truoc 2026-09-26 nhanh mode 2 (khong truyen
  //  'height') bo qua 'full' va roi vao max-w-lg = 512px — do duoc: man "Chinh anh" o desktop chi
  //  rong 470px, tru cot dieu khien 380px con 90px cho vung anh ⇒ anh hien 66x66px.
  height: { type: String, default: '' },  // chiều cao cố định (vd '80vh') -> header/tab cố định, body cuộn
});
const emit = defineEmits(['update:modelValue']);

// §5.2/§5.3 (a11y): role/aria-modal + Esc + nhận focus đã có từ trước. [Đợt 0.7] thêm FOCUS TRAP:
// trước đây Tab đi XUYÊN RA khỏi hộp thoại vào những nút mờ đằng sau — bàn phím vẫn "lạc" sau lớp
// phủ, Enter kích hoạt nhầm hành động ẩn. useFocusTrap giữ Tab trong hộp thoại + trả focus khi đóng.
const rootEl = ref(null);
function close() { emit('update:modelValue', false); }
function onKey(e) {
  if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
}
const trap = useFocusTrap(rootEl);
watch(() => props.modelValue, async (open) => {
  if (open) {
    window.addEventListener('keydown', onKey, true);
    await nextTick();
    trap.activate();
  } else {
    window.removeEventListener('keydown', onKey, true);
    trap.deactivate();
  }
});
onBeforeUnmount(() => { window.removeEventListener('keydown', onKey, true); trap.deactivate(); });
</script>
<template>
  <div v-if="modelValue" ref="rootEl" tabindex="-1" role="dialog" aria-modal="true" :aria-label="title || undefined"
       class="fixed inset-0 z-[70] flex items-center justify-center bg-scrim/70 p-4 outline-none"
       @click.self="close">
    <!-- Mode 1: có height cố định -> flex-col + header cố định + body cuộn (inline style chống override) -->
    <div
      v-if="height"
      class="w-full overflow-hidden rounded-lg border border-brand-500/40 bg-ink-900 shadow-2xl"
      :class="full ? 'w-[min(1440px,calc(100vw-1rem))] max-w-none' : (wide ? 'max-w-3xl' : 'max-w-lg')"
      :style="{ display: 'flex', flexDirection: 'column', height: full ? 'min(94vh, 960px)' : height, maxHeight: 'calc(100vh - 2rem)' }"
      @click.stop
    >
      <div class="flex h-14 shrink-0 items-center justify-between border-b border-ink-700 bg-ink-900 px-5">
        <span class="text-sm font-semibold text-brand-300">{{ title }}</span>
        <button @click="close" aria-label="Đóng" class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-ink-700 text-cream-200 transition hover:bg-danger hover:text-cream-50" title="Đóng">✕</button>
      </div>
      <div :style="{ flex: '1 1 0', minHeight: 0, overflowY: full ? 'hidden' : 'auto', overscrollBehavior: 'contain' }">
        <slot />
      </div>
    </div>
    <!-- Mode 2: không height -> auto tối đa 85vh, toàn bộ cuộn + header sticky -->
    <div
      v-else
      class="w-full overflow-y-auto overscroll-contain rounded-lg border border-brand-500/40 bg-ink-900 shadow-2xl"
      :class="full ? 'w-[min(1440px,calc(100vw-1rem))] max-w-none' : (wide ? 'max-w-3xl' : 'max-w-lg')"
      style="max-height: 85vh"
      @click.stop
    >
      <div class="sticky top-0 z-20 flex h-14 shrink-0 items-center justify-between border-b border-ink-700 bg-ink-900 px-5">
        <span class="text-sm font-semibold text-brand-300">{{ title }}</span>
        <button @click="close" aria-label="Đóng" class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-ink-700 text-cream-200 transition hover:bg-danger hover:text-cream-50" title="Đóng">✕</button>
      </div>
      <div class="p-5">
        <slot />
      </div>
    </div>
  </div>
</template>