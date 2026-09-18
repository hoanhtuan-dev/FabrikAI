<script setup>
/**
 * [Trục 1 — 2026-09-20] Canvas Empty State — OpenArt-style.
 *
 * Thay thế dòng text "Chọn/hiện một ảnh..." bằng một màn hình khởi động giàu nội dung:
 *   · Thanh prompt nhanh (OpenArt-style) — nhập ngay, không cần mở popup
 *   · Mẫu việc theo ngành (job-templates) — bấm một cái là có bộ prompt để tạo hàng loạt
 *   · Ảnh gần đây — kéo thả ngay vào canvas hoặc nhấn để mở viewer
 *   · Gợi ý phím tắt — giảm thời gian học
 *
 * Tất cả đều dùng state/store hiện có — KHÔNG thêm endpoint, KHÔNG thay đổi hành vi generate.
 */
import { ref, computed, onMounted } from 'vue'; // ref: còn dùng cho templatesLoading
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

// ── Prompt bar (OpenArt-style) ──
const promptText = computed({
  get: () => store.imagePromptEn || '',
  set: (v) => { store.imagePromptEn = v; },
});

const variantCount = computed({
  get: () => store.variantCount || 1,
  set: (v) => { store.variantCount = Math.max(1, Math.min(4, Number(v) || 1)); },
});

const creditEstimate = computed(() => {
  const base = store.planCostImage || 1;
  return base * variantCount.value;
});

const canGenerate = computed(() => {
  return (store.imagePromptEn || '').trim().length > 0 && !store.generating;
});

const ratioOptions = ['1:1', '4:3', '3:4', '9:16', '16:9', '4:5', '21:9'];

function generate() {
  if (!canGenerate.value) return;
  store.generateImage();
}

function onPromptKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
    e.preventDefault();
    generate();
  }
}

// ── Job templates (Đợt 2) ──
const templatesLoading = ref(false);
async function loadTemplates() {
  if (store.jobTemplatesLoaded || templatesLoading.value) return;
  templatesLoading.value = true;
  try { await store.loadJobTemplates(); } finally { templatesLoading.value = false; }
}

/**
 * Áp mẫu việc: đặt tỉ lệ/độ phân giải (store.applyJobTemplate) rồi gửi TOÀN BỘ prompt của mẫu vào
 * tab "Hàng loạt" của ConceptCard qua kênh store — màn hình này không sở hữu ô dán danh sách nên
 * không tự ý sửa DOM (cách cũ vỡ ngay khi card đổi bố cục).
 */
function applyTemplate(tpl) {
  const prompts = store.applyJobTemplate(tpl);
  if (!prompts.length) return;
  store.requestBatchPrompts(prompts, { from: tpl.title });
}

// ── Recent generations ──
const recentGens = computed(() => {
  return (store.generations || [])
    .filter(g => g.status === 'completed' && g.media_url)
    .slice(0, 6);
});

function openGen(g) {
  store.openViewer(g);
}

// ── Keyboard shortcuts hint ──
const shortcuts = [
  { key: 'Ctrl+K', label: 'Mở Command Palette' },
  { key: 'S', label: 'Chọn ảnh chờ duyệt' },
  { key: 'N', label: 'Chuyển bước tiếp' },
  { key: 'V', label: 'Di chuyển canvas' },
  { key: 'Ctrl+Z', label: 'Hoàn tác' },
  { key: 'Ctrl+Shift+Z', label: 'Làm lại' },
];

onMounted(() => { loadTemplates(); });
</script>

<template>
  <!-- [2026-09-20] Hiệu ứng vào dùng CƠ SỞ CHUNG (app.css: .motion-*) thay vì số ms viết cứng:
       nền mờ dần, nội dung nhô lên — và tự tắt khi người dùng bật "giảm chuyển động". -->
  <div class="motion-fade-in absolute inset-0 z-20 flex flex-col items-center justify-center overflow-y-auto bg-gradient-to-b from-ink-950/95 via-ink-950/90 to-ink-950/95 p-4 backdrop-blur-sm">
    <!-- Header -->
    <div class="motion-rise-in mb-6 text-center">
      <div class="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-600/20 text-brand-300">
        <StudioIcon name="sparkles" size="h-8 w-8" />
      </div>
      <h2 class="text-xl font-semibold text-cream-50">Bắt đầu tạo ảnh</h2>
      <p class="mt-1 text-sm text-cream-300/60">Nhập mô tả hoặc chọn mẫu việc để bắt đầu</p>
    </div>

    <!-- Prompt Bar (OpenArt-style) -->
    <div class="pointer-events-auto w-full max-w-2xl">
      <div class="rounded-2xl border border-ink-700 bg-ink-900/90 p-4 shadow-2xl backdrop-blur">
        <!-- Prompt input -->
        <div class="relative">
          <textarea
            v-model="promptText"
            rows="3"
            class="input w-full resize-none !rounded-xl !border-ink-700 !bg-ink-800 !py-3 !pr-24 !text-sm"
            placeholder="Mô tả trang phục, phong cách, bối cảnh, ánh sáng…"
            @keydown="onPromptKeydown"
          ></textarea>
          <div class="absolute bottom-3 right-3 flex items-center gap-2">
            <span class="text-[10px] font-semibold text-cream-300/40" title="Số credit ước tính">
              ~{{ creditEstimate }} credit
            </span>
            <button
              @click="generate"
              :disabled="!canGenerate"
              class="flex h-10 items-center gap-1.5 rounded-xl bg-brand-600 px-4 text-sm font-semibold text-white transition hover:bg-brand-500 disabled:opacity-40 disabled:hover:bg-brand-600"
              title="Tạo ảnh (Enter)"
            >
              <StudioIcon name="zap" size="h-4 w-4" />
              Tạo ảnh
            </button>
          </div>
        </div>

        <!-- Quick options row -->
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <!-- Variant count -->
          <div class="flex items-center gap-1 rounded-lg bg-ink-800 px-2 py-1" title="Số biến thể">
            <span class="text-[10px] text-cream-300/50">Biến thể:</span>
            <button
              v-for="n in [1, 2, 4]"
              :key="n"
              @click="variantCount = n"
              class="h-6 min-w-6 rounded-md px-1.5 text-[11px] font-semibold transition"
              :class="variantCount === n ? 'bg-brand-600 text-white' : 'text-cream-300/60 hover:text-cream-100'"
            >{{ n }}</button>
          </div>
          <!-- Aspect ratio -->
          <div class="flex items-center gap-1 rounded-lg bg-ink-800 px-2 py-1" title="Tỷ lệ khung hình">
            <span class="text-[10px] text-cream-300/50">Tỷ lệ:</span>
            <button
              v-for="r in ratioOptions"
              :key="r"
              @click="store.imageRatio = r"
              class="h-6 rounded-md px-2 text-[11px] font-semibold transition"
              :class="store.imageRatio === r ? 'bg-brand-600 text-white' : 'text-cream-300/60 hover:text-cream-100'"
            >{{ r }}</button>
          </div>
          <!-- Credits left -->
          <div class="ml-auto flex items-center gap-1.5 rounded-lg bg-ink-800 px-2.5 py-1.5" title="Credit còn lại">
            <StudioIcon name="coins" size="h-3.5 w-3.5" class="text-brand-300" />
            <span class="text-[11px] font-semibold text-cream-200">{{ store.creditsLeft }}</span>
            <span v-if="store.planName" class="text-[10px] text-cream-300/50">{{ store.planName }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Job Templates -->
    <div v-if="store.jobTemplates.length" class="pointer-events-auto mt-6 w-full max-w-2xl">
      <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-cream-300/50">
        <StudioIcon name="template" size="h-3.5 w-3.5" /> Bắt đầu từ mẫu việc
      </p>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <button
          v-for="tpl in store.jobTemplates"
          :key="tpl.id"
          @click="applyTemplate(tpl)"
          class="group rounded-xl border border-ink-700 bg-ink-900/80 p-3 text-left transition hover:border-brand-500 hover:bg-brand-600/10"
          :title="tpl.hint || tpl.title"
        >
          <span class="mb-1 flex items-center gap-1.5 text-sm font-semibold text-cream-100 group-hover:text-brand-200">
            <StudioIcon :name="tpl.icon || 'folderOpen'" size="h-4 w-4" class="text-brand-400" />
            {{ tpl.title }}
          </span>
          <span class="block text-[10px] leading-snug text-cream-300/50">
            {{ tpl.prompts?.length || 0 }} prompt · {{ tpl.ratio }} · {{ tpl.resolution }}
          </span>
        </button>
      </div>
    </div>
    <div v-else-if="templatesLoading" class="mt-6 w-full max-w-2xl">
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div v-for="i in 4" :key="i" class="h-20 animate-pulse rounded-xl bg-ink-800"></div>
      </div>
    </div>

    <!-- Recent generations -->
    <div v-if="recentGens.length" class="pointer-events-auto mt-6 w-full max-w-2xl">
      <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-cream-300/50">
        <StudioIcon name="history" size="h-3.5 w-3.5" /> Ảnh gần đây
      </p>
      <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
        <button
          v-for="g in recentGens"
          :key="g.id"
          @click="openGen(g)"
          class="group relative aspect-square overflow-hidden rounded-lg border border-ink-700 bg-ink-900 transition hover:border-brand-500"
          :title="'Mở viewer — ' + store.genName(g)"
        >
          <img :src="thumbUrl(g.media_url)" class="h-full w-full object-cover" loading="lazy" @error="onThumbError($event, g.media_url)">
          <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-1 text-[9px] text-cream-200 opacity-0 transition group-hover:opacity-100">
            {{ store.genName(g) }}
          </span>
        </button>
      </div>
    </div>

    <!-- Keyboard shortcuts -->
    <div class="pointer-events-auto mt-6 flex flex-wrap items-center justify-center gap-3 text-[10px] text-cream-300/40">
      <span v-for="s in shortcuts" :key="s.key" class="flex items-center gap-1">
        <kbd class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-[9px] text-cream-300">{{ s.key }}</kbd>
        {{ s.label }}
      </span>
    </div>
  </div>
</template>
