<script setup>
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();
function openGallery() { store.openViewer(store.preview || store.visibleGenerations[0] || store.generations[0] || null); }
// Thumbnail URL lấy từ composable dùng chung (useStudioThumb) — cùng logic với PHP helper
// studio_image_thumb_url(): giảm ~50x dung lượng so với ảnh gốc 2K; path đặc biệt → fallback ảnh gốc.
// Ảnh gốc full-size vẫn dùng khi mở viewer (store.viewer = g → media_url gốc).
function projectColor(pid) {
  const p = store.projects.find(p => Number(p.id) === Number(pid));
  return (p && p.color) || '#7aa2f7';
}
// Kéo-thả thumbnail này vào canvas (thêm layer) — set data cho drop zone.
function onThumbDrag(e, g) {
  e.dataTransfer.effectAllowed = 'copy';
  try { e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'studio-output', url: g.media_url, name: store.genName(g) })); } catch (err) { /* bỏ qua */ }
}
function projectName(pid, fallback) {
  if (fallback) return fallback;
  const p = store.projects.find(p => Number(p.id) === Number(pid));
  return p ? p.name : '#' + pid;
}
</script>
<template>
  <div class="card flex flex-1 flex-col overflow-hidden" style="min-height:0">
    <!-- Header chỉ còn nút lọc (khi có dự án áp dụng) — đã xóa dòng chữ "Outputs (n)" -->
    <div v-if="store.appliedProject" class="panel-head border-b border-ink-700">
      <button @click="store.outputFilterProject = !store.outputFilterProject" class="icon-btn" :class="store.outputFilterProject ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'" :title="store.outputFilterProject ? 'Đang lọc theo dự án: ' + store.appliedProject.name : 'Chỉ hiện outputs của dự án đang áp dụng'" :aria-label="'Lọc outputs theo dự án đang áp dụng'">
        <StudioIcon name="filter" size="h-3.5 w-3.5" />
      </button>
    </div>
    <div class="scrollbar-hide mt-2 grid flex-1 auto-rows-min grid-cols-1 gap-1.5 overflow-y-auto p-2">
      <div v-for="g in store.visibleGenerations" :key="g.id" class="group relative aspect-square overflow-hidden rounded-lg border-2" :class="store.previewId === g.id ? 'border-brand-500' : 'border-ink-700'">
        <!-- Badge dự án -->
        <span v-if="g.project_id" class="absolute top-1 left-1 z-10 h-2.5 w-2.5 rounded-full ring-1 ring-black/40" :style="{ background: projectColor(g.project_id) }" :title="'Dự án: ' + projectName(g.project_id, g.project)"></span>
        <!-- Ảnh hoàn tất -->
        <template v-if="g.status === 'completed' && g.media_url">
          <button @click="store.openViewer(g)" draggable="true" @dragstart="onThumbDrag($event, g)" class="absolute inset-0 cursor-grab active:cursor-grabbing" :title="'Kéo thả vào canvas để thêm · nhấn để xem lớn'"><img :src="thumbUrl(g.media_url)" class="pointer-events-none h-full w-full bg-ink-900 object-cover" loading="lazy" @error="onThumbError($event, g.media_url)"></button>
          <span class="pointer-events-none absolute left-1/2 top-1 z-10 hidden -translate-x-1/2 items-center gap-1 rounded-full bg-black/70 px-1.5 py-0.5 text-[9px] font-semibold text-cream-100 transition group-hover:flex"><StudioIcon name="download" size="h-2.5 w-2.5" class="text-brand-300"/>Kéo thả</span>
        </template>
        <!-- Đang xử lý / chờ: skeleton shimmer + overlay tiến độ -->
        <template v-else>
          <div class="skeleton-shimmer absolute inset-0"></div>
          <div class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-black/40 text-[10px] text-cream-200">
            <span v-if="['pending','processing'].includes(g.status)" class="h-5 w-5 animate-spin rounded-full border-2 border-brand-300 border-t-transparent"></span>
            <span v-else-if="g.status === 'failed'" class="text-base"><StudioIcon name="alertTriangle" size="h-5 w-5" /></span>
            <span v-else-if="g.status === 'cancelled'" class="text-base"><StudioIcon name="x" size="h-5 w-5" /></span>
            <span class="font-semibold">{{ store.statusLabel(g.status) }}</span>
            <span v-if="['pending','processing'].includes(g.status)" class="flex items-center gap-0.5">
              <span v-for="i in 3" :key="i" class="status-dot" :style="{ animationDelay: (i - 1) * 0.2 + 's' }"></span>
            </span>
          </div>
        </template>
        <span v-if="g.media_url" class="absolute bottom-1 left-1 max-w-[92%] truncate rounded-full bg-black/60 px-1.5 py-0.5 text-[9px] text-cream-100">{{ store.genName(g) }}</span>
      </div>
    </div>
    <p v-if="store.appliedProject && store.outputFilterProject && !store.visibleGenerations.length" class="mt-2 text-center text-[10px] text-cream-300/40">Chưa có output nào thuộc dự án này.</p>
  </div>
</template>

<style scoped>
/* Skeleton shimmer — hiệu ứng quét sáng chạy ngang ảnh slot đang chờ/xử lý */
.skeleton-shimmer {
  background: linear-gradient(100deg, #1c2333 20%, #2a3347 40%, #3a4560 60%, #2a3347 80%, #1c2333 100%);
  background-size: 200% 100%;
  animation: shimmerSweep 1.8s linear infinite;
}
@keyframes shimmerSweep {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
/* 3 dot "đang chờ" — nhấp nháy tuần tự */
.status-dot {
  width: 3px;
  height: 3px;
  border-radius: 9999px;
  background: #f5c06a;
  animation: dotBlink 1.2s ease-in-out infinite;
}
@keyframes dotBlink {
  0%, 80%, 100% { opacity: 0.25; transform: translateY(0); }
  40% { opacity: 1; transform: translateY(-2px); }
}
</style>