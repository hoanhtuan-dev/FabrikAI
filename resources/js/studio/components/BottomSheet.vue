<script setup>
/**
 * BOTTOM SHEET — lớp phủ mặc định của shell 2026 trên điện thoại (kéo xuống để đóng).
 *
 * Mọi nội dung phụ (bộ lọc · chia sẻ · tuỳ chọn nhanh) là sheet trượt từ đáy thay vì modal
 * giữa màn hình: ngón cái với tới, một tay dùng được. v-model:open đóng/mở.
 */
import { ref, watch } from 'vue';
import StudioIcon from './StudioIcon.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
});
const emit = defineEmits(['update:open']);

const dragY = ref(0);
let startY = 0, dragging = false;

function grabDown(e) {
  dragging = true; startY = e.clientY;
  e.currentTarget.setPointerCapture(e.pointerId);
}
function grabMove(e) {
  if (!dragging) return;
  dragY.value = Math.max(0, e.clientY - startY);
}
function grabUp() {
  if (!dragging) return;
  dragging = false;
  if (dragY.value > 90) { emit('update:open', false); }
  dragY.value = 0;
}
watch(() => props.open, (v) => { if (!v) dragY.value = 0; });
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="bs-root">
      <div class="bs-scrim" @click="emit('update:open', false)" />
      <div
        class="bs"
        role="dialog"
        :aria-label="title"
        :style="dragY ? { transform: 'translateY(' + dragY + 'px)', transition: 'none' } : {}"
      >
        <div
          class="bs-grab"
          @pointerdown="grabDown"
          @pointermove="grabMove"
          @pointerup="grabUp"
          @pointercancel="grabUp"
        />
        <div v-if="title" class="bs-head">
          <span class="bs-title">{{ title }}</span>
          <button type="button" class="bs-x" aria-label="Đóng" @click="emit('update:open', false)">
            <StudioIcon name="x" size="h-4 w-4" />
          </button>
        </div>
        <div class="bs-body"><slot /></div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.bs-root { position: fixed; inset: 0; z-index: 80; }
.bs-scrim { position: absolute; inset: 0; background: rgb(4 4 7 / 0.56); backdrop-filter: blur(2px); }
.bs {
  position: absolute; left: 0; right: 0; bottom: 0; max-height: 86dvh; display: flex; flex-direction: column;
  background: var(--color-ink-900); border-radius: 28px 28px 0 0;
  box-shadow: 0 -18px 60px rgb(0 0 0 / 0.5), inset 0 1px 0 var(--color-ink-700);
  padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 12px);
  animation: bs-in var(--motion-dur-slow) var(--motion-ease-emphasized) both;
  transition: transform var(--motion-dur-slow) var(--motion-ease-emphasized);
}
@keyframes bs-in { from { transform: translateY(103%); } }
.bs-grab { padding: 10px 0 8px; display: grid; place-items: center; touch-action: none; cursor: grab; }
.bs-grab::before { content: ""; width: 38px; height: 5px; border-radius: 3px; background: var(--color-ink-600); }
.bs-head { display: flex; align-items: center; justify-content: space-between; padding: 4px 20px 12px; }
.bs-title { font-size: 16px; font-weight: 600; color: var(--color-cream-100); }
.bs-x {
  width: 32px; height: 32px; border-radius: 999px; display: grid; place-items: center;
  background: var(--color-ink-800); color: var(--color-cream-300);
}
.bs-body { overflow-y: auto; padding: 0 20px; scrollbar-width: none; }
</style>
