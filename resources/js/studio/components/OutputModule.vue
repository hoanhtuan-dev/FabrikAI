<script setup>
/**
 * [Trục 3 — 2026-09-20] Dock Outputs — lưới kết quả có HÀNH ĐỘNG NGAY TRÊN ẢNH (kiểu OpenArt).
 *
 * Nguyên tắc 5 của UX_PERSONA_STRATEGY: "Kết quả luôn có bước tiếp theo — Biến thể · Sửa · Ghép ·
 * Tải · Gửi duyệt, một cú bấm". Trước đây thumbnail chỉ có 1 hành động (nhấn = mở viewer) và một
 * gợi ý kéo-thả; muốn biến thể/sửa/tải thì phải mở viewer rồi tự tìm đường.
 *
 * Nay mỗi ảnh hoàn tất có thanh hành động hiện khi rê chuột (và khi focus bàn phím — không có
 * hành động nào chỉ dùng được bằng chuột):
 *   · Thêm vào canvas  → store.select(g)  (đẩy layer + chọn làm ảnh đang làm việc)
 *   · Biến thể         → select(g) + requestActivity(variation)
 *   · Sửa ảnh          → select(g) + requestActivity(inpaint)
 *   · Tải xuống        → /api/generations/{id}/download
 *
 * KHÔNG thêm endpoint, KHÔNG đổi luồng generate — mọi nút đi qua API đã có của store.
 */
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

// ── [Trục 3] Hành động trên từng ảnh kết quả ──
/** Đưa ảnh lên canvas (thêm layer + chọn làm ảnh đang làm việc). */
function toCanvas(g) { store.select(g); }
/** Mở công cụ với CHÍNH ảnh này làm ảnh nguồn: đưa lên canvas trước, rồi xin đổi nhóm công cụ. */
function useIn(g, activity) { store.select(g); store.requestActivity(activity); }
/** Tải ảnh gốc qua endpoint download đã có (không tự dựng link tải mới). */
function download(g) {
  if (!g || !g.id) return;
  window.location.href = '/api/generations/' + g.id + '/download';
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
    <!-- [Đợt 0.3] Banner nói thật khi lô hiện tại có ảnh DEMO -->
    <p v-if="store.generations.some(g => g.is_demo)" class="mx-2 mt-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-2 py-1.5 text-[10px] leading-snug text-amber-200">
      <span class="font-semibold">Ảnh DEMO:</span> chưa cấu hình API key cho model này nên kết quả là ảnh mẫu (hoặc chính ảnh gốc), <span class="font-semibold">không phải do AI tạo</span>. Vào Cài đặt để thêm API key.
    </p>
    <div class="scrollbar-hide mt-2 grid flex-1 auto-rows-min grid-cols-1 gap-1.5 overflow-y-auto p-2">
      <div v-for="g in store.visibleGenerations" :key="g.id" class="group relative aspect-square overflow-hidden rounded-lg border-2" :class="store.previewId === g.id ? 'border-brand-500' : 'border-ink-700'">
        <!-- Badge dự án -->
        <span v-if="g.project_id" class="absolute top-1 left-1 z-10 h-2.5 w-2.5 rounded-full ring-1 ring-black/40" :style="{ background: projectColor(g.project_id) }" :title="'Dự án: ' + projectName(g.project_id, g.project)"></span>
        <!-- [Đợt 0.3] Nhãn DEMO: ảnh này KHÔNG do AI tạo (chưa có API key) — ảnh mẫu hoặc chính ảnh gốc.
             Trước đây những ảnh này được báo Hoàn tất im lặng, người dùng tưởng AI đã xử lý. -->
        <span v-if="g.is_demo" class="absolute right-1 top-1 z-10 rounded-full bg-amber-500 px-1.5 py-0.5 text-[8px] font-bold uppercase leading-none text-black" :title="g.demo_reason || 'Ảnh mẫu — chưa cấu hình API key'">DEMO</span>
        <!-- Ảnh hoàn tất -->
        <template v-if="g.status === 'completed' && g.media_url">
          <button @click="store.openViewer(g)" draggable="true" @dragstart="onThumbDrag($event, g)" class="absolute inset-0 cursor-grab active:cursor-grabbing" :title="'Kéo thả vào canvas để thêm · nhấn để xem lớn'"><img :src="thumbUrl(g.media_url)" class="pointer-events-none h-full w-full bg-ink-900 object-cover" loading="lazy" @error="onThumbError($event, g.media_url)"></button>
          <span class="pointer-events-none absolute left-1/2 top-1 z-10 hidden -translate-x-1/2 items-center gap-1 rounded-full bg-black/70 px-1.5 py-0.5 text-[9px] font-semibold text-cream-100 transition group-hover:flex"><StudioIcon name="download" size="h-2.5 w-2.5" class="text-brand-300"/>Kéo thả</span>
          <!-- [Trục 3] Thanh hành động: hiện khi rê chuột HOẶC khi có nút được focus (bàn phím dùng được). -->
          <div class="pointer-events-none absolute inset-x-0 bottom-0 z-20 grid grid-cols-2 gap-0.5 bg-black/80 p-0.5 opacity-0 backdrop-blur-sm transition group-hover:pointer-events-auto group-hover:opacity-100 group-focus-within:pointer-events-auto group-focus-within:opacity-100">
            <button type="button" class="flex h-6 items-center justify-center gap-1 rounded bg-ink-800/90 text-[9px] font-semibold text-cream-100 transition hover:bg-brand-600 hover:text-white" title="Thêm vào canvas (thành layer để ghép/sửa)" :aria-label="'Thêm ' + store.genName(g) + ' vào canvas'" @click.stop="toCanvas(g)">
              <StudioIcon name="plus" size="h-3 w-3" /> Canvas
            </button>
            <button type="button" class="flex h-6 items-center justify-center gap-1 rounded bg-ink-800/90 text-[9px] font-semibold text-cream-100 transition hover:bg-brand-600 hover:text-white" title="Tải ảnh gốc về máy" :aria-label="'Tải ' + store.genName(g)" @click.stop="download(g)">
              <StudioIcon name="download" size="h-3 w-3" /> Tải
            </button>
            <button type="button" class="flex h-6 items-center justify-center gap-1 rounded bg-ink-800/90 text-[9px] font-semibold text-cream-100 transition hover:bg-brand-600 hover:text-white" title="Tạo biến thể từ ảnh này (mở công cụ Biến thể)" :aria-label="'Tạo biến thể từ ' + store.genName(g)" @click.stop="useIn(g, 'variation')">
              <StudioIcon name="variations" size="h-3 w-3" /> Biến thể
            </button>
            <button type="button" class="flex h-6 items-center justify-center gap-1 rounded bg-ink-800/90 text-[9px] font-semibold text-cream-100 transition hover:bg-brand-600 hover:text-white" title="Sửa ảnh này (mở công cụ Sửa ảnh)" :aria-label="'Sửa ' + store.genName(g)" @click.stop="useIn(g, 'inpaint')">
              <StudioIcon name="pencil" size="h-3 w-3" /> Sửa
            </button>
          </div>
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
        <span v-if="g.media_url" class="absolute bottom-1 left-1 max-w-[92%] truncate rounded-full bg-black/60 px-1.5 py-0.5 text-[9px] text-cream-100 transition group-hover:opacity-0">{{ store.genName(g) }}</span>
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
