<script setup>
import StudioIcon from '../StudioIcon.vue';
/**
 * Trạng thái RỖNG. Khác "Đang tải…" ở chỗ: nói rõ VÌ SAO rỗng và NGƯỜI DÙNG LÀM GÌ TIẾP.
 *
 * [Lý do 2026-09-20] Bản cũ chỉ có "Chưa có mục nào." — người mới không biết nên bắt đầu từ đâu,
 * và với người đã lọc thì không phân biệt được "chưa có dữ liệu" với "bộ lọc không khớp".
 * Prop `filtered` tách đúng hai tình huống đó ra.
 */
defineProps({
  icon: { type: String, default: 'info' },
  title: { type: String, required: true },
  hint: { type: String, default: '' },
  filtered: { type: Boolean, default: false },
});
</script>
<template>
  <div class="card flex flex-col items-center justify-center gap-2.5 px-6 py-12 text-center">
    <span class="grid h-12 w-12 place-items-center rounded-full bg-ink-700/70 text-cream-300">
      <StudioIcon :name="filtered ? 'search' : icon" size="h-6 w-6" />
    </span>
    <p class="text-sm font-semibold text-cream-100">{{ title }}</p>
    <p v-if="hint" class="max-w-md text-xs leading-relaxed text-cream-300">{{ hint }}</p>
    <div class="mt-1.5"><slot /></div>
  </div>
</template>
