<script setup>
import { computed } from 'vue';
import { toastState, dismiss, clearAll } from '../../composables/useSettingsToast.js';
import StudioIcon from '../StudioIcon.vue';
/**
 * Khay thông báo của khu Cài đặt — một chỗ duy nhất, dùng cho cả 3 mục.
 * Bố cục và hành vi giống NotificationCenter của Studio để hai nơi không "nói hai giọng khác nhau".
 */
const TONE = {
  success: { icon: 'check', cls: 'border-brand-500/40 bg-brand-900/40 text-cream-50', ico: 'text-brand-300' },
  error:   { icon: 'alertTriangle', cls: 'border-red-500/40 bg-red-950/60 text-cream-50', ico: 'text-red-300' },
  info:    { icon: 'info', cls: 'border-ink-600 bg-ink-800 text-cream-50', ico: 'text-cream-300' },
};
const items = computed(() => toastState.items);
const show = computed(() => items.value.slice(-4));
</script>
<template>
  <div class="pointer-events-none fixed bottom-4 right-4 z-[95] flex w-[min(92vw,22rem)] flex-col gap-2" role="region" aria-label="Thông báo" aria-live="polite">
    <button v-if="items.length >= 2" @click="clearAll"
            class="pointer-events-auto self-end rounded-md border border-ink-600 bg-ink-800/95 px-2.5 py-1 text-[11px] font-medium text-cream-300 transition hover:text-cream-50">
      Xoá hết ({{ items.length }})
    </button>
    <div v-for="t in show" :key="t.id" role="status"
         :class="['pointer-events-auto flex items-start gap-2.5 rounded-lg border px-3 py-2.5 shadow-xl backdrop-blur', (TONE[t.type] || TONE.info).cls]">
      <StudioIcon :name="(TONE[t.type] || TONE.info).icon" size="mt-0.5 h-4 w-4 shrink-0" />
      <p class="min-w-0 flex-1 text-xs leading-relaxed">{{ t.message }}</p>
      <button @click="dismiss(t.id)" class="shrink-0 rounded p-0.5 text-cream-300/70 transition hover:text-cream-50" aria-label="Đóng thông báo">
        <StudioIcon name="x" size="h-3.5 w-3.5" />
      </button>
    </div>
  </div>
</template>
