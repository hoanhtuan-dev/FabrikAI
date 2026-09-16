<script setup>
import { ref } from 'vue';
defineProps({
  modelValue: { type: Boolean, default: false },
  before: { type: String, default: '' },
  after: { type: String, default: '' },
  title: { type: String, default: '🔍 So sánh Trước / Sau' },
});
const emit = defineEmits(['update:modelValue']);
const pos = ref(50);
</script>
<template>
  <div v-if="modelValue" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/90 p-4" @click.self="emit('update:modelValue', false)">
    <div class="w-full max-w-3xl">
      <div class="mb-3 flex items-center justify-between">
        <span class="text-sm font-semibold text-cream-100">{{ title }}</span>
        <button @click="emit('update:modelValue', false)" class="grid h-8 w-8 place-items-center rounded-full bg-ink-700 text-cream-200 hover:text-white">✕</button>
      </div>

      <div class="relative mx-auto aspect-square max-h-[70vh] w-full select-none overflow-hidden rounded-lg border border-white/10 bg-ink-900">
        <!-- Ảnh Trước (nền) -->
        <img :src="before" class="absolute inset-0 h-full w-full object-contain" draggable="false">
        <!-- Ảnh Sau (phủ, cắt theo vị trí slider) -->
        <div class="absolute inset-0" :style="{ clipPath: 'inset(0 0 0 ' + pos + '%)' }">
          <img :src="after" class="h-full w-full object-contain" draggable="false">
        </div>
        <!-- Đường chia -->
        <div class="pointer-events-none absolute inset-y-0 w-0.5 bg-white shadow" :style="{ left: pos + '%' }"></div>
        <div class="pointer-events-none absolute top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full bg-white text-ink-900 shadow" :style="{ left: 'calc(' + pos + '% - 16px)' }">⇄</div>
        <!-- Nhãn -->
        <span class="pointer-events-none absolute left-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-[10px] font-semibold text-cream-100">Trước</span>
        <span class="pointer-events-none absolute right-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-[10px] font-semibold text-cream-100">Sau</span>
      </div>

      <input type="range" min="0" max="100" step="1" v-model.number="pos" class="mt-4 h-2 w-full cursor-pointer accent-brand-500">
    </div>
  </div>
</template>