<script setup>
/**
 * Khung xương khi đang tải — thay cho dòng chữ "Đang tải…" trơ trọi.
 *
 * [Lý do 2026-09-20] Cả 3 trang cũ đều hiện "<div class=card>Đang tải…</div>": trang nhảy layout khi
 * dữ liệu về, và người dùng không biết sắp có bao nhiêu nội dung. Khung xương giữ đúng chỗ và cho biết
 * quy mô nội dung sắp tới.
 */
defineProps({
  rows: { type: Number, default: 6 },
  variant: { type: String, default: 'card' }, // card | row
});
</script>
<template>
  <div v-if="variant === 'card'" class="grid gap-3 md:grid-cols-2" aria-hidden="true">
    <div v-for="i in rows" :key="i" class="card animate-pulse p-4">
      <div class="h-3.5 w-1/3 rounded bg-ink-700"></div>
      <div class="mt-2.5 h-2.5 w-full rounded bg-ink-700/70"></div>
      <div class="mt-1.5 h-2.5 w-4/5 rounded bg-ink-700/70"></div>
      <div class="mt-4 h-6 w-24 rounded bg-ink-700/50"></div>
    </div>
  </div>
  <div v-else class="space-y-2" aria-hidden="true">
    <div v-for="i in rows" :key="i" class="flex animate-pulse items-center gap-3 rounded-lg border border-ink-700 bg-ink-800 p-3">
      <div class="h-12 w-12 shrink-0 rounded-lg bg-ink-700"></div>
      <div class="min-w-0 flex-1">
        <div class="h-3 w-1/3 rounded bg-ink-700"></div>
        <div class="mt-2 h-2.5 w-1/2 rounded bg-ink-700/70"></div>
      </div>
    </div>
  </div>
</template>
