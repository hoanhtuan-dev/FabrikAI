<script setup>
/**
 * LƯỚI KẾT QUẢ — MẶT CHÍNH của Studio (bước 5.2 của kế hoạch bỏ canvas, 2026-09-26).
 *
 * VÌ SAO CÓ COMPONENT NÀY: canvas đang là TRUNG TÂM màn hình, còn kết quả nằm trong một dock hẹp
 * 156px bên phải. Đo được: canvas chiếm ~70% bề ngang trong khi việc người dùng thật sự làm là
 * XEM KẾT QUẢ và CHỌN BƯỚC TIẾP. Đổi vai: lưới kết quả thành mặt chính, canvas xuống hàng công cụ.
 *
 * TÁI DÙNG, KHÔNG VIẾT LẠI: mọi hành động ở đây đi qua ĐÚNG những hàm mà OutputModule.vue đang gọi
 * (store.select · store.requestActivity · /api/generations/{id}/download). Không thêm endpoint,
 * không đổi luồng generate — chỉ đổi chỗ đứng của cùng một bộ nút.
 *
 * [§12 nguyên tắc 5 — và đây là chỗ KHÁC OutputModule có chủ ý] Thanh hành động ở lưới chính
 * LUÔN HIỆN, không ẩn theo hover. Lý do: ẩn theo hover là bẫy trên thiết bị cảm ứng (không có
 * hover), và đây là mặt chính nên người dùng mới phải thấy NGAY là có bước tiếp theo. Dock hẹp giữ
 * kiểu hover vì ở đó không gian là ràng buộc thật.
 */
import { computed } from 'vue';
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';
import { PROJECT_COLOR } from '../dataColors.js';

const store = useStudioStore();

const items = computed(() => store.visibleGenerations || []);
const pending = computed(() => items.value.filter((g) => ['pending', 'processing'].includes(g.status)).length);

function projectColor(pid) {
  const p = store.projects.find((x) => Number(x.id) === Number(pid));
  return (p && p.color) || PROJECT_COLOR;
}
function projectName(pid, fallback) {
  if (fallback) return fallback;
  const p = store.projects.find((x) => Number(x.id) === Number(pid));
  return p ? p.name : '#' + pid;
}

/** CHỌN ảnh này làm ảnh đang làm việc (đặt workingImage + đẩy layer trong giai đoạn chuyển). */
function select(g) { store.select(g); }
/** Mở công cụ với CHÍNH ảnh này làm ảnh nguồn. */
function useIn(g, activity) { store.select(g); store.requestActivity(activity); }
function download(g) {
  if (!g || !g.id) return;
  window.location.href = '/api/generations/' + g.id + '/download';
}
/**
 * Xoá một mục khỏi danh sách — đi qua ĐÚNG action GalleryModal đang dùng (store.deleteGen),
 * nên hành vi xoá (xoá mềm ở máy chủ · xử lý layer cục bộ · hoàn credit nếu cần) không lệch nhau.
 */
function remove(g) { store.deleteGen(g); }

// Kéo-thả sang canvas (vẫn còn trong giai đoạn chuyển) — cùng định dạng dữ liệu với OutputModule.
function onDragStart(e, g) {
  e.dataTransfer.effectAllowed = 'copy';
  try { e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'studio-output', url: g.media_url, name: store.genName(g) })); } catch (err) { /* bỏ qua */ }
}
</script>

<template>
  <div class="relative flex h-full min-h-0 flex-col overflow-hidden rounded-lg border border-ink-700 bg-ink-900">
    <!-- ── Thanh đầu: đếm · lọc dự án · việc đang chạy ── -->
    <div class="flex flex-wrap items-center gap-2 border-b border-ink-700 px-3 py-2">
      <h2 class="flex items-center gap-2 font-display text-sm font-semibold text-cream-50">
        <StudioIcon name="grid" size="h-4 w-4" class="text-brand-300" /> Kết quả
      </h2>
      <span class="rounded-full bg-ink-700 px-2 py-0.5 text-label text-cream-300">{{ items.length }}</span>

      <span v-if="pending" class="flex items-center gap-1 rounded-full bg-warn/15 px-2 py-0.5 text-label font-semibold text-warn">
        <span class="h-2 w-2 animate-pulse rounded-full bg-warn"></span>{{ pending }} đang tạo
      </span>

      <button
        v-if="store.appliedProject"
        type="button"
        class="tool-btn !py-1 text-label"
        :class="store.outputFilterProject ? 'is-active' : ''"
        :title="store.outputFilterProject ? 'Đang lọc theo bộ sưu tập: ' + store.appliedProject.name : 'Chỉ hiện ảnh của bộ sưu tập đang áp dụng'"
        @click="store.outputFilterProject = !store.outputFilterProject"
      >
        <StudioIcon name="filter" size="h-3 w-3" /> {{ store.appliedProject.name }}
      </button>

      <span class="ml-auto hidden text-label text-cream-400 lg:inline">
        Bấm ảnh để xem lớn · nút dưới ảnh để làm tiếp
      </span>
    </div>

    <!-- Banner nói thật khi lô này có ảnh DEMO (không phải do AI tạo) -->
    <p v-if="items.some((g) => g.is_demo)" class="mx-3 mt-2 rounded-md border border-warn/40 bg-warn/10 px-2 py-1.5 text-label leading-snug text-warn">
      <span class="font-semibold">Ảnh DEMO:</span> tính năng tạo ảnh chưa được bật nên kết quả là ảnh mẫu (hoặc chính ảnh gốc), <span class="font-semibold">không phải do AI tạo</span>. Vui lòng báo cho quản trị viên để bật tính năng.
    </p>

    <!-- ── Lưới kết quả ── -->
    <div v-if="items.length" class="scrollbar-hide grid flex-1 auto-rows-min grid-cols-2 gap-3 overflow-y-auto p-3 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
      <article
        v-for="g in items"
        :key="g.id"
        class="group relative overflow-hidden rounded-xl border-2 bg-ink-800 transition-colors"
        :class="store.previewId === g.id ? 'border-brand-500' : 'border-ink-700 hover:border-ink-600'"
      >
        <div class="relative aspect-square">
          <span v-if="g.project_id" class="absolute left-1.5 top-1.5 z-10 h-2.5 w-2.5 rounded-full ring-1 ring-ink-600" :style="{ background: projectColor(g.project_id) }" :title="'Bộ sưu tập: ' + projectName(g.project_id, g.project)"></span>
          <span v-if="g.is_demo" class="absolute right-1.5 top-1.5 z-10 rounded-full bg-warn px-1.5 py-0.5 text-micro font-bold uppercase leading-none text-warn-content" :title="g.demo_reason || 'Ảnh mẫu — tính năng tạo ảnh AI chưa được bật'">ẢNH MẪU</span>

          <template v-if="g.status === 'completed' && g.media_url">
            <button
              type="button"
              class="absolute inset-0 cursor-zoom-in"
              draggable="true"
              :title="'Xem lớn ' + store.genName(g)"
              :aria-label="'Xem lớn ' + store.genName(g)"
              @click="store.openViewer(g)"
              @dragstart="onDragStart($event, g)"
            >
              <img :src="thumbUrl(g.media_url)" class="pointer-events-none h-full w-full bg-ink-900 object-cover" loading="lazy" :alt="store.genName(g)" @error="onThumbError($event, g.media_url)">
            </button>
          </template>

          <!-- Chờ / đang xử lý / lỗi -->
          <template v-else>
            <div class="skeleton-shimmer absolute inset-0"></div>
            <div class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-scrim/40 text-label text-scrim-content">
              <span v-if="['pending', 'processing'].includes(g.status)" class="h-5 w-5 animate-spin rounded-full border-2 border-brand-300 border-t-transparent"></span>
              <span v-else-if="g.status === 'failed'" class="text-base"><StudioIcon name="alertTriangle" size="h-5 w-5" /></span>
              <span v-else-if="g.status === 'cancelled'" class="text-base"><StudioIcon name="x" size="h-5 w-5" /></span>
              <span class="font-semibold">{{ store.statusLabel(g.status) }}</span>
            </div>
          </template>
        </div>

        <!-- ── Thanh hành động: LUÔN HIỆN (xem chú thích đầu file) ── -->
        <template v-if="g.status === 'completed' && g.media_url">
          <div class="grid grid-cols-2 gap-1 border-t border-ink-700 p-1.5">
            <button type="button" class="flex h-7 items-center justify-center gap-1 rounded bg-ink-700 text-tiny font-semibold text-cream-200 transition hover:bg-brand-600 hover:text-cream-50" title="Chọn ảnh này làm ảnh đang làm việc" :aria-label="'Chọn ' + store.genName(g)" @click.stop="select(g)">
              <StudioIcon name="target" size="h-3 w-3" /> Chọn
            </button>
            <button type="button" class="flex h-7 items-center justify-center gap-1 rounded bg-ink-700 text-tiny font-semibold text-cream-200 transition hover:bg-brand-600 hover:text-cream-50" title="Mở công cụ Sửa ảnh với ảnh này" :aria-label="'Sửa ' + store.genName(g)" @click.stop="useIn(g, 'inpaint')">
              <StudioIcon name="pencil" size="h-3 w-3" /> Sửa
            </button>
            <button type="button" class="flex h-7 items-center justify-center gap-1 rounded bg-ink-700 text-tiny font-semibold text-cream-200 transition hover:bg-brand-600 hover:text-cream-50" title="Tạo biến thể từ ảnh này" :aria-label="'Biến thể từ ' + store.genName(g)" @click.stop="useIn(g, 'variation')">
              <StudioIcon name="variations" size="h-3 w-3" /> Biến thể
            </button>
            <button type="button" class="flex h-7 items-center justify-center gap-1 rounded bg-ink-700 text-tiny font-semibold text-cream-200 transition hover:bg-brand-600 hover:text-cream-50" title="Tải ảnh gốc về máy" :aria-label="'Tải ' + store.genName(g)" @click.stop="download(g)">
              <StudioIcon name="download" size="h-3 w-3" /> Tải
            </button>
          </div>
          <p class="truncate px-2 pb-1.5 text-tiny text-cream-400" :title="store.genName(g)">{{ store.genName(g) }}</p>
        </template>
        <template v-else>
          <div class="flex items-center gap-1 border-t border-ink-700 p-1.5">
            <button type="button" class="tool-btn !py-1 text-tiny" title="Xoá mục này khỏi danh sách" :aria-label="'Xoá ' + store.genName(g)" @click.stop="remove(g)">
              <StudioIcon name="trash" size="h-3 w-3" />
            </button>
            <span v-if="g.error" class="min-w-0 flex-1 truncate text-tiny text-danger" :title="g.error">{{ g.error }}</span>
          </div>
        </template>
      </article>
    </div>

    <!-- ── Trống: nói việc đầu tiên nên làm, KHÔNG liệt kê tính năng ── -->
    <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
      <span class="grid h-12 w-12 place-items-center rounded-full bg-brand-600/15 text-brand-300">
        <StudioIcon name="sparkles" size="h-6 w-6" />
      </span>
      <p class="text-base font-semibold text-cream-100">Chưa có ảnh nào</p>
      <p class="max-w-md text-body leading-relaxed text-cream-300">
        Mở bảng công cụ bên trái để tả tấm ảnh bạn muốn — hoặc hỏi trợ lý, rồi bấm Tạo ảnh.
      </p>
      <div class="flex flex-wrap items-center justify-center gap-2">
        <button type="button" class="btn-brand" data-chat-open title="Mở trợ lý thiết kế" @click="store.chatOpen = true">
          <StudioIcon name="bot" size="h-4 w-4" /> Mở trợ lý &amp; tạo ảnh
        </button>
        <button type="button" class="tool-btn" data-prompt-panel title="Mở bảng Prompt Tạo Ảnh đầy đủ" @click="store.promptOpen = true">
          <StudioIcon name="sliders" size="h-3.5 w-3.5" /> Bảng prompt đầy đủ
        </button>
      </div>
      <p v-if="store.appliedProject && store.outputFilterProject" class="text-label text-cream-400">
        Đang lọc theo bộ sưu tập «{{ store.appliedProject.name }}» — <button type="button" class="underline" @click="store.outputFilterProject = false">bỏ lọc</button>
      </p>
    </div>
  </div>
</template>

<style scoped>
.skeleton-shimmer {
  background: linear-gradient(100deg, var(--color-ink-800) 20%, var(--color-ink-700) 40%, var(--color-ink-600) 60%, var(--color-ink-700) 80%, var(--color-ink-800) 100%);
  background-size: 200% 100%;
  animation: shimmerSweep 1.8s linear infinite;
}
@keyframes shimmerSweep {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
</style>
