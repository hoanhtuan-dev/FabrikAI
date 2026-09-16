<script setup>
// LibraryCard — card icon "Thư viện" (tách riêng từ nút "Xem thư viện" cũ ở header Outputs).
// Chỉ hiển thị duy nhất icon library làm slot bấm — mọi chữ chỉ hiện khi hover (title/aria).
// Bấm → chuyển sang view Thư viện client-side trong SPA /studio (không điều hướng full-page).
import StudioIcon from './StudioIcon.vue';
import { useStudioStore } from '../store.js';
const store = useStudioStore();
function goLibrary() { store.exitCanvasTools(); store.studioView = 'library'; }
</script>
<template>
  <div class="card overflow-hidden">
    <div class="p-2">
      <button
        @click="goLibrary"
        class="flex aspect-square w-full flex-col items-center justify-center rounded-md border border-dashed border-ink-600 bg-ink-800/50 text-cream-300/70 transition-colors hover:border-brand-500/70 hover:bg-ink-800 hover:text-brand-300"
        title="Xem ảnh trong thư viện"
        :aria-label="'Xem ảnh trong thư viện'"
      >
        <StudioIcon name="grid" size="h-6 w-6"/>
      </button>
    </div>
  </div>
</template>