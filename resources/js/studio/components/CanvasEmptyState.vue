<script setup>
/**
 * Canvas Empty State — thiết kế lại toàn diện (2026-09-20).
 *
 * Màn hình canvas trống giờ là một "command center" gọn:
 *   · composer tạo ảnh là trọng tâm (prompt, tỉ lệ, biến thể, credit),
 *   · Agent Studio 3 bước nằm cùng chỗ để người dùng đi từ hướng đi → ảnh,
 *   · quick actions tới Nguồn ảnh · Thư viện · Bộ sưu tập · Prompt đầy đủ · Gói,
 *   · bối cảnh hiện tại (gói, credit, dự án, số lớp) luôn hiển thị,
 *   · phím tắt gom trong mục mở rộng.
 *
 * ĐÃ XÓA theo yêu cầu: khối mẫu việc theo ngành và khối ảnh cũ.
 * — mẫu việc vẫn dùng được trong Prompt Tạo Ảnh; ảnh cũ vẫn nằm ở Thư viện/Outputs.
 *
 * Giữ nguyên: vị trí z-0 (dưới layer), hành vi generate, state store hiện có.
 */
import { computed } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

const promptText = computed({
  get: () => store.imagePromptEn || '',
  set: (value) => { store.imagePromptEn = value; },
});

const variantCount = computed({
  get: () => store.variantCount || 1,
  set: (value) => { store.variantCount = Math.max(1, Math.min(4, Number(value) || 1)); },
});

const creditEstimate = computed(() => (store.planCostImage || 1) * variantCount.value);
const canGenerate = computed(() => (store.imagePromptEn || '').trim().length > 0 && !store.generating);
const ratioOptions = ['1:1', '4:5', '3:4', '16:9', '9:16'];

const projectName = computed(() => store.appliedProject?.name || '');
const layerCount = computed(() => (store.canvasLayers || []).length);

const AGENT_STEPS = [
  { id: 'radar', icon: 'scan', title: 'Tín hiệu', text: 'Đọc xu hướng theo khu vực, chọn hướng phù hợp DNA shop.' },
  { id: 'brief', icon: 'briefcase', title: 'Định hướng', text: 'Dựng brief, mood board, cấu trúc SKU và dải giá.' },
  { id: 'canvas', icon: 'wand', title: 'Thực thi', text: 'Chốt prompt, tỉ lệ, biến thể rồi mở Tạo ảnh.' },
];

const quickActions = [
  { id: 'prompt', icon: 'sliders', label: 'Prompt Tạo Ảnh', hint: 'Bảng đầy đủ: prefix, negative, phom dáng, mẫu việc', run: () => { store.promptOpen = true; } },
  { id: 'source', icon: 'imagePlus', label: 'Nguồn ảnh', hint: 'Chọn ảnh từ Outputs, Thư viện hoặc tải lên', run: () => { store.sourcePickerOpen = true; } },
  { id: 'library', icon: 'library', label: 'Thư viện', hint: 'Xem và quản lý ảnh đã tạo trước đây', run: () => { store.studioView = 'library'; } },
  { id: 'collections', icon: 'folderOpen', label: 'Bộ sưu tập', hint: 'Mở bảng thiết kế và bộ sưu tập hiện tại', run: () => { store.requestWorkspace(); } },
];

function openAgent(step = 'radar') {
  if (store.moduleLocked('stylist')) {
    store.toast('Agent thiết kế không có trong gói của bạn. Mở «Gói & credit» để nâng cấp.', 'error');
    store.planOpen = true;
    store.planCatalogOpen = true;
    store.loadPlanStatus && store.loadPlanStatus(true);
    return;
  }
  store.designAgentOpen = true;
  store.setDesignAgentStep(step);
}

function generate() {
  if (!canGenerate.value) return;
  store.generateImage();
}

function onPromptKeydown(event) {
  if (event.key === 'Enter' && !event.shiftKey && !event.ctrlKey && !event.metaKey) {
    event.preventDefault();
    generate();
  }
}

const shortcuts = [
  { key: 'Ctrl+K', label: 'Command Palette' },
  { key: 'Enter', label: 'Tạo ảnh từ ô mô tả' },
  { key: 'Ctrl+Z', label: 'Hoàn tác' },
  { key: 'Ctrl+Shift+Z', label: 'Làm lại' },
  { key: 'V', label: 'Di chuyển canvas' },
];
</script>

<template>
  <!-- z-0 (không phải z-20): lớp này nằm DƯỚI các layer. Ẩn layer cuối cùng thì màn hình trống
       hiện ra ngay và không che hiệu ứng mờ của layer đang tắt. Layer ẩn không nhận chuột nên
       màn hình trống vẫn bấm được. -->
  <div class="motion-fade-in absolute inset-0 z-0 overflow-y-auto bg-gradient-to-b from-ink-950/97 via-ink-950/94 to-ink-950/97 p-4 backdrop-blur-sm sm:p-6" role="region" aria-label="Canvas trống">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-5">
      <!-- Đầu trang: trạng thái canvas + bối cảnh -->
      <header class="motion-rise-in flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
          <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-brand-600/20 text-brand-300">
            <StudioIcon name="imagePlus" size="h-5 w-5" />
          </span>
          <div class="min-w-0">
            <h2 class="text-lg font-semibold text-cream-50">Canvas trống</h2>
            <p class="mt-0.5 text-xs text-cream-400">Tạo ảnh từ mô tả, hoặc để Agent Studio dựng hướng đi trước.</p>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-[11px]">
          <span class="inline-flex items-center gap-1.5 rounded-full bg-ink-800 px-2.5 py-1 text-cream-200" :title="'Credit còn lại'">
            <StudioIcon name="coins" size="h-3.5 w-3.5" class="text-brand-300" /> {{ store.creditsLeft }}
          </span>
          <span v-if="store.planName" class="rounded-full bg-ink-800 px-2.5 py-1 text-cream-300">{{ store.planName }}</span>
          <span v-if="projectName" class="inline-flex items-center gap-1.5 rounded-full bg-brand-500/15 px-2.5 py-1 text-brand-200" :title="'Bộ sưu tập đang áp dụng'">
            <StudioIcon name="folderOpen" size="h-3.5 w-3.5" /> {{ projectName }}
          </span>
          <span class="rounded-full bg-ink-800 px-2.5 py-1 text-cream-300">{{ layerCount }} lớp</span>
        </div>
      </header>

      <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(280px,340px)]">
        <!-- Cột chính: composer + lối vào nhanh -->
        <div class="space-y-4">
          <section class="motion-rise-in rounded-2xl border border-ink-700 bg-ink-900/90 p-4 shadow-2xl sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 class="text-sm font-semibold text-cream-100">Tạo ảnh từ mô tả</h3>
                <p class="mt-0.5 text-[11px] text-cream-400">Mô tả trang phục, phong cách, bối cảnh và ánh sáng.</p>
              </div>
              <button type="button" class="tool-btn" @click="store.promptOpen = true" title="Mở bảng Prompt Tạo Ảnh đầy đủ">
                <StudioIcon name="sliders" size="h-3.5 w-3.5" /> Bảng đầy đủ
              </button>
            </div>

            <div class="relative mt-3">
              <label for="canvas-quick-prompt" class="sr-only">Mô tả ảnh cần tạo</label>
              <textarea
                id="canvas-quick-prompt"
                v-model="promptText"
                rows="4"
                class="input w-full resize-none !rounded-xl !py-3 !pr-28 !text-sm"
                placeholder="Ví dụ: Bộ sưu tập linen pastel, nữ văn phòng, ánh sáng mềm, nền studio sáng…"
                @keydown="onPromptKeydown"
              ></textarea>
              <div class="absolute bottom-3 right-3 flex items-center gap-2">
                <span class="text-[10px] font-semibold text-cream-400" title="Số credit ước tính">~{{ creditEstimate }} credit</span>
                <button type="button" class="btn-brand btn-sm flex items-center gap-1.5" :disabled="!canGenerate" @click="generate" title="Tạo ảnh (Enter)">
                  <StudioIcon name="zap" size="h-3.5 w-3.5" /> Tạo ảnh
                </button>
              </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
              <div class="flex items-center gap-1 rounded-lg bg-ink-800 p-1" role="group" aria-label="Số biến thể">
                <span class="px-1 text-[10px] text-cream-400">Biến thể</span>
                <button v-for="n in [1, 2, 4]" :key="n" type="button" class="h-6 min-w-6 rounded-md px-1.5 text-[11px] font-semibold transition" :class="Number(variantCount) === n ? 'bg-brand-600 text-white' : 'text-cream-400 hover:text-cream-100'" @click="variantCount = n">{{ n }}</button>
              </div>
              <div class="flex items-center gap-1 rounded-lg bg-ink-800 p-1" role="group" aria-label="Tỉ lệ khung hình">
                <span class="px-1 text-[10px] text-cream-400">Tỉ lệ</span>
                <button v-for="r in ratioOptions" :key="r" type="button" class="h-6 rounded-md px-2 text-[11px] font-semibold transition" :class="store.imageRatio === r ? 'bg-brand-600 text-white' : 'text-cream-400 hover:text-cream-100'" @click="store.imageRatio = r">{{ r }}</button>
              </div>
              <span class="ml-auto text-[10px] text-cream-400">Enter để tạo nhanh · Shift+Enter xuống dòng</span>
            </div>
          </section>

          <section class="motion-rise-in rounded-2xl border border-ink-700 bg-ink-900/80 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 class="text-sm font-semibold text-cream-100">Hoặc bắt đầu có định hướng</h3>
                <p class="mt-0.5 text-[11px] text-cream-400">Agent Studio dẫn từ tín hiệu thị trường đến prompt tạo ảnh.</p>
              </div>
              <button type="button" class="btn-brand btn-sm flex items-center gap-2" @click="openAgent('radar')">
                <StudioIcon name="sparkles" size="h-3.5 w-3.5" /> Mở Agent Studio
              </button>
            </div>
            <div class="mt-3 grid gap-2 sm:grid-cols-3">
              <div v-for="(stepItem, index) in AGENT_STEPS" :key="stepItem.id" class="rounded-xl border border-ink-700 bg-ink-800/70 p-3">
                <div class="flex items-center gap-2">
                  <span class="grid h-6 w-6 place-items-center rounded-full bg-ink-900 text-[10px] font-bold text-brand-200">{{ index + 1 }}</span>
                  <StudioIcon :name="stepItem.icon" size="h-3.5 w-3.5" class="text-brand-300" />
                  <span class="text-xs font-semibold text-cream-100">{{ stepItem.title }}</span>
                </div>
                <p class="mt-2 text-[11px] leading-4 text-cream-400">{{ stepItem.text }}</p>
              </div>
            </div>
          </section>
        </div>

        <!-- Cột phụ: hành động nhanh + phím tắt -->
        <aside class="space-y-4">
          <section class="motion-rise-in rounded-2xl border border-ink-700 bg-ink-900/80 p-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-cream-400">Đi nhanh</h3>
            <div class="mt-3 space-y-2">
              <button v-for="action in quickActions" :key="action.id" type="button" class="tool-btn w-full justify-start !px-3 !py-2.5 text-left" :title="action.hint" @click="action.run()">
                <StudioIcon :name="action.icon" size="h-4 w-4" class="shrink-0 text-brand-300" />
                <span class="min-w-0"><span class="block text-xs font-semibold text-cream-100">{{ action.label }}</span><span class="block truncate text-[10px] text-cream-400">{{ action.hint }}</span></span>
              </button>
              <button type="button" class="tool-btn w-full justify-start !px-3 !py-2.5 text-left" title="Xem gói và nâng cấp" @click="store.planOpen = true">
                <StudioIcon name="coins" size="h-4 w-4" class="shrink-0 text-brand-300" />
                <span class="min-w-0"><span class="block text-xs font-semibold text-cream-100">Gói &amp; credit</span><span class="block truncate text-[10px] text-cream-400">Hạn mức, chi phí, nâng cấp</span></span>
              </button>
            </div>
          </section>

          <details class="motion-rise-in rounded-2xl border border-ink-700 bg-ink-900/80 p-4">
            <summary class="cursor-pointer text-xs font-semibold text-cream-200">Phím tắt canvas</summary>
            <ul class="mt-3 space-y-1.5">
              <li v-for="item in shortcuts" :key="item.key" class="flex items-center justify-between gap-3 text-[11px] text-cream-400">
                <span>{{ item.label }}</span>
                <kbd class="rounded bg-ink-800 px-1.5 py-0.5 font-mono text-[10px] text-cream-200">{{ item.key }}</kbd>
              </li>
            </ul>
          </details>
        </aside>
      </div>
    </div>
  </div>
</template>
