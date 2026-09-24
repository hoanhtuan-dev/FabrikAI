<script setup>
/**
 * STUDIO PHONE — mặt Studio trên điện thoại (Phase 2 · shell 2026).
 *
 * Quyết định 2026-09-24 của người dùng: trên điện thoại CANVAS KHÔNG XUẤT HIỆN — StudioApp
 * render component này THAY toàn bộ cây desktop (không phải v-show: không có DOM canvas nào
 * được tạo). Bản chất màn này là BẢNG ĐIỀU KHIỂN quanh ảnh đang làm việc:
 *   · preview lớn (chạm → trình xem toàn màn hình GalleryModal);
 *   · hành động nhanh: Biến thể AI · Tải · Chia sẻ · Tech pack;
 *   · rail kết quả gần đây (chạm để đổi ảnh đang làm việc);
 *   · danh sách lớp đang có (đọc, để biết ảnh gồm những gì);
 *   · CommandBar prompt ở đáy — nhập mô tả → tạo ảnh ngay tại đây.
 *
 * Chọn ảnh KHÔNG qua store.select() (hàm đó đẩy layer canvas — vô nghĩa khi không có canvas);
 * chỉ đặt preview + workingImage (đúng tinh thần bước 5.1 trong store/actions/generation.js).
 */
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
import CommandBar from './CommandBar.vue';
import PopMenu from './PopMenu.vue';
import NotificationCenter from './NotificationCenter.vue';
import TriageDeck from './TriageDeck.vue';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';

const GalleryModal = defineAsyncComponent(() => import('./GalleryModal.vue'));

const store = useStudioStore();

/* [Phase 3] Deck sàng lọc: tự mở khi một LƯỢT TẠO (≥2 ảnh) vừa hoàn tất; mở tay qua nút Duyệt. */
const triageOpen = ref(false);
const batchReady = computed(() => {
  const ids = store.lastBatch || [];
  if (ids.length < 2) return false;
  const byId = new Map((store.generations || []).map((g) => [g.id, g]));
  const done = ids.filter((id) => { const g = byId.get(id); return g && g.status === 'completed' && g.media_url; });
  return done.length >= 2;
});
watch(() => store.generating, (now, before) => { if (before && !now && batchReady.value) triageOpen.value = true; });

const currentUrl = computed(() => store.upscaleSrc || '');
const recents = computed(() => (store.visibleGenerations || []).filter((g) => g.media_url && g.status === 'completed').slice(0, 12));
const layers = computed(() => store.canvasLayers || []);
const activeId = computed(() => (store.preview && store.preview.id) ?? null);

function pickGen(g) {
  store.previewId = g.id;
  store.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed', prompt: g.prompt || '' };
  store.setWorkingImage(g, 'generation');
}
function openCurrent() {
  if (store.preview) store.openViewer(store.preview);
}
function variant() {
  if (!currentUrl.value) { store.toast('Chọn hoặc tạo một ảnh trước.', 'error'); return; }
  store.refgen(store.upscaleSrc, store.imagePromptEn || '', 70, 2);
}
function download() {
  const g = store.preview;
  if (!g || !g.id) { store.toast('Chưa có ảnh để tải.', 'error'); return; }
  window.location.href = '/api/generations/' + g.id + '/download';
}
async function share() {
  const url = currentUrl.value;
  if (!url) { store.toast('Chưa có ảnh để chia sẻ.', 'error'); return; }
  try {
    if (navigator.share) { await navigator.share({ title: 'FabrikAI', url }); }
    else { await navigator.clipboard.writeText(url); store.toast('Đã chép liên kết ảnh.', 'success'); }
  } catch (e) { /* người dùng tự huỷ sheet chia sẻ — không phải lỗi */ }
}
function goPrompt(q) {
  // Rỗng = không làm gì (ConceptCard popup chỉ có trong cây desktop; trên phone prompt chính là ô này).
  if (!q) return;
  store.imagePromptEn = q;
  store.generateImage();
}
</script>

<template>
  <div class="studio-shell flex h-dvh w-full flex-col overflow-hidden bg-ink-950 text-cream-100">
    <!-- Thanh mini: nhận diện + credit (điều hướng không gian nằm ở orb của CommandBar) -->
    <header class="flex items-center justify-between px-4 pt-3">
      <span class="text-micro uppercase tracking-[0.16em] text-cream-400">Studio</span>
      <div class="flex items-center gap-2">
        <button
          v-if="batchReady"
          type="button"
          class="inline-flex h-8 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-3 text-label font-semibold text-cream-200 transition hover:border-brand-400"
          title="Sàng lọc lượt tạo gần nhất (vuốt giữ/bỏ)"
          @click="triageOpen = true"
        >
          <StudioIcon name="checkSquare" size="h-3.5 w-3.5" class="text-brand-300" />
          Duyệt
        </button>
        <a href="/bang-gia" class="inline-flex h-8 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-3 text-label font-semibold text-cream-200 transition hover:border-brand-400" title="Credit & gói">
          <StudioIcon name="zap" size="h-3.5 w-3.5" class="text-brand-300" />
          {{ Number(store.creditsLeft ?? 0).toLocaleString('vi') }}
        </a>
      </div>
    </header>

    <main class="min-h-0 flex-1 overflow-y-auto px-4 pb-3 pt-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <!-- Preview ảnh đang làm việc -->
      <button
        v-if="currentUrl"
        type="button"
        class="relative block w-full overflow-hidden rounded-3xl border border-ink-600 bg-ink-900 transition hover:border-brand-400"
        :aria-label="'Xem lớn ảnh đang chọn'"
        @click="openCurrent"
      >
        <img :src="currentUrl" alt="Ảnh đang làm việc" class="max-h-[44dvh] w-full object-contain">
        <span v-if="store.generating" class="absolute inset-0 grid place-items-center bg-ink-950/60 backdrop-blur-sm">
          <span class="flex items-center gap-2 text-label font-semibold text-cream-100">
            <StudioIcon name="sparkles" size="h-4 w-4" class="animate-pulse text-brand-300" />
            AI đang tạo…
          </span>
        </span>
      </button>
      <div v-else class="grid h-[38dvh] w-full place-items-center rounded-3xl border border-dashed border-ink-600 bg-ink-900">
        <div class="flex flex-col items-center gap-2 px-8 text-center">
          <StudioIcon name="sparkles" size="h-7 w-7" class="text-brand-300" />
          <p class="text-body text-cream-300">Chưa có ảnh nào — mô tả thiết kế ở thanh dưới để tạo ảnh đầu tiên.</p>
        </div>
      </div>

      <!-- Hành động nhanh -->
      <div class="mt-3 grid grid-cols-4 gap-2">
        <button type="button" class="btn-magic flex h-12 flex-col items-center justify-center gap-0.5 rounded-2xl text-[11px] font-bold transition active:scale-95" @click="variant">
          <StudioIcon name="sparkles" size="h-4 w-4" />Biến thể
        </button>
        <button type="button" class="flex h-12 flex-col items-center justify-center gap-0.5 rounded-2xl border border-ink-600 bg-ink-800 text-[11px] font-semibold text-cream-200 transition hover:border-brand-400 active:scale-95" @click="download">
          <StudioIcon name="download" size="h-4 w-4" />Tải
        </button>
        <button type="button" class="flex h-12 flex-col items-center justify-center gap-0.5 rounded-2xl border border-ink-600 bg-ink-800 text-[11px] font-semibold text-cream-200 transition hover:border-brand-400 active:scale-95" @click="share">
          <StudioIcon name="share" size="h-4 w-4" />Chia sẻ
        </button>
        <a href="/bo-suu-tap" class="flex h-12 flex-col items-center justify-center gap-0.5 rounded-2xl border border-ink-600 bg-ink-800 text-[11px] font-semibold text-cream-200 transition hover:border-brand-400 active:scale-95">
          <StudioIcon name="ruler" size="h-4 w-4" />Tech pack
        </a>
      </div>

      <!-- Rail kết quả gần đây -->
      <section v-if="recents.length" class="mt-5">
        <h2 class="text-label font-semibold text-cream-300">Kết quả gần đây</h2>
        <div class="-mx-4 mt-2 flex gap-2.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          <button
            v-for="g in recents" :key="g.id" type="button"
            class="block w-20 shrink-0 overflow-hidden rounded-xl border transition active:scale-95"
            :class="g.id === activeId ? 'border-brand-500' : 'border-ink-600'"
            :aria-label="'Chọn ảnh #' + g.id"
            @click="pickGen(g)"
          >
            <img :src="thumbUrl(g.media_url, 320)" :alt="g.prompt || ('Ảnh #' + g.id)" class="aspect-[3/4] w-full object-cover" loading="lazy" @error="onThumbError">
          </button>
        </div>
      </section>

      <!-- Lớp đang có (đọc) -->
      <section v-if="layers.length" class="mt-5">
        <h2 class="text-label font-semibold text-cream-300">Lớp trên bảng ghép ({{ layers.length }})</h2>
        <ul class="mt-2 space-y-1.5">
          <li v-for="l in layers" :key="l.id" class="flex items-center gap-2.5 rounded-xl bg-ink-800 px-3 py-2">
            <img v-if="l.src" :src="thumbUrl(l.src, 160)" :alt="l.name || 'Lớp'" class="h-9 w-9 rounded-lg object-cover" loading="lazy" @error="onThumbError">
            <span class="min-w-0 flex-1 truncate text-label text-cream-200">{{ l.name || 'Lớp' }}</span>
            <StudioIcon name="layers" size="h-4 w-4" class="shrink-0 text-cream-400" />
          </li>
        </ul>
        <p class="mt-2 text-micro text-cream-400">Xếp lớp & chỉnh vùng làm trên máy tính (màn rộng).</p>
      </section>
    </main>

    <CommandBar mode="prompt" space="studio" placeholder="Mô tả thiết kế / biến thể…" :running="store.generating" @go="goPrompt" />
    <PopMenu />
    <GalleryModal v-if="store.viewer" />
    <TriageDeck v-model:open="triageOpen" />
    <NotificationCenter />
  </div>
</template>
