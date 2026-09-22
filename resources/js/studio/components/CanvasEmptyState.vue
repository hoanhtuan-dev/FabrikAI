<script setup>
/**
 * Canvas trống — CHỈ CÒN Ô MÔ TẢ TẠO ẢNH (thiết kế lại 2026-09-26 · đợt 26).
 *
 * [Vấn đề gốc] Màn hình này từng là một "command center": ô mô tả + 3 thẻ Agent Studio + cột "Đi
 * nhanh" (Prompt đầy đủ · Nguồn ảnh · Thư viện · Bộ sưu tập · Gói) + khối phím tắt. Người dùng mở
 * Studio lần đầu phải ĐỌC bốn khối trước khi gõ được chữ nào; mà việc duy nhất của màn hình trống
 * là giúp tạo ra tấm ảnh đầu tiên.
 *
 * Nay: MỘT ô mô tả, đúng những gì cần để tạo ảnh (biến thể · tỉ lệ · chi phí · nút Tạo ảnh), cộng ba
 * gợi ý bấm-là-điền và lối vào bảng Prompt đầy đủ. Không thẻ Agent, không cột đi nhanh, không phím tắt.
 *
 * Vì sao KHÔNG mất tính năng nào: Agent Studio vẫn nằm ở rail công cụ bên trái của Studio (mục
 * "Agent thiết kế" → /agent-studio); Nguồn ảnh · Thư viện · Bảng lệnh · Outputs nay ở thanh tiêu đề;
 * Bộ sưu tập nằm ngay cạnh nút đó trên thanh tiêu đề. Màn hình trống không còn là bản sao menu.
 *
 * Giữ nguyên: z-0 (dưới layer), hành vi generate, state store hiện có (imagePromptEn · imageRatio ·
 * variantCount · planCostImage).
 */
import { computed, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const promptEl = ref(null);

const promptText = computed({
  get: () => store.imagePromptEn || '',
  set: (value) => { store.imagePromptEn = value; },
});

const variantCount = computed({
  get: () => store.variantCount || 1,
  set: (value) => { store.variantCount = Math.max(1, Math.min(4, Number(value) || 1)); },
});

const creditEstimate = computed(() => (store.planCostImage || 1) * variantCount.value);

/** Lý do nút "Tạo ảnh" bị khoá — MỘT nguồn: canGenerate suy ra từ đây (§4.4). */
const blockReason = computed(() => {
  const empty = (store.imagePromptEn || '').trim().length === 0;
  return empty ? 'Chưa nhập mô tả ảnh — gõ mô tả vào ô trên rồi bấm Tạo ảnh.' : '';
});
const canGenerate = computed(() => ! blockReason.value && ! store.generating);

const ratioOptions = ['1:1', '4:5', '3:4', '16:9', '9:16'];

/** Ba gợi ý bấm-là-điền: cách nhanh nhất để có một mô tả ĐỦ Ý (chất liệu · dáng · bối cảnh · sáng). */
const EXAMPLES = [
  { label: 'Váy linen pastel', text: 'Váy linen pastel dáng suông, nữ văn phòng, ánh sáng mềm, nền studio sáng' },
  { label: 'Sơ mi oversize', text: 'Áo sơ mi trắng oversize, cotton dày, phong cách tối giản, ánh sáng ban ngày' },
  { label: 'Đầm dạ hội', text: 'Đầm dạ hội sequin đen dáng ôm, nền sân khấu tối, ánh sáng viền' },
];

function focusPrompt() {
  if (promptEl.value) promptEl.value.focus();
}

function useExample(example) {
  promptText.value = example.text;
  focusPrompt();
}

function generate() {
  if (! canGenerate.value) return;
  store.generateImage();
}

function onPromptKeydown(event) {
  // Enter = tạo ảnh; Shift+Enter = xuống dòng (giữ nguyên hành vi cũ).
  if (event.key === 'Enter' && ! event.shiftKey && ! event.ctrlKey && ! event.metaKey) {
    event.preventDefault();
    generate();
  }
}

onMounted(() => {
  // [đợt 26] Tự đặt con trỏ vào ô mô tả — màn hình trống chỉ có một việc để làm. CHỈ trên thiết bị
  // có chuột: trên điện thoại, tự focus sẽ bật bàn phím ảo che mất nửa màn hình ngay khi mở Studio.
  const coarse = typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  if (! coarse) {
    focusPrompt();
  }
});
</script>

<template>
  <!-- z-0 (không phải z-20): lớp này nằm DƯỚI các layer. Ẩn layer cuối cùng thì màn hình trống
       hiện ra ngay và không che hiệu ứng mờ của layer đang tắt. Layer ẩn không nhận chuột nên
       màn hình trống vẫn bấm được. -->
  <div class="motion-fade-in absolute inset-0 z-0 overflow-y-auto bg-gradient-to-b from-ink-950/97 via-ink-950/94 to-ink-950/97 p-4 backdrop-blur-sm sm:p-6" role="region" aria-label="Canvas trống">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-3 py-4 sm:py-10">
      <header class="flex items-center gap-3">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-brand-600/20 text-brand-300">
          <StudioIcon name="imagePlus" size="h-5 w-5" />
        </span>
        <div class="min-w-0">
          <h2 class="font-display text-base font-semibold text-cream-50 sm:text-lg">Tạo ảnh đầu tiên</h2>
          <p class="mt-0.5 text-body text-cream-400">Mô tả trang phục, phong cách, bối cảnh và ánh sáng.</p>
        </div>
      </header>

      <section class="rounded-2xl border border-ink-700 bg-ink-900/90 p-3 shadow-2xl sm:p-4">
        <label for="canvas-quick-prompt" class="sr-only">Mô tả ảnh cần tạo</label>
        <textarea
          id="canvas-quick-prompt"
          ref="promptEl"
          v-model="promptText"
          rows="6"
          class="input w-full resize-none !rounded-xl !py-3 !text-base"
          placeholder="Ví dụ: Bộ sưu tập linen pastel, nữ văn phòng, ánh sáng mềm, nền studio sáng…"
          @keydown="onPromptKeydown"
        ></textarea>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <div class="flex items-center gap-1 rounded-lg bg-ink-800 p-1" role="group" aria-label="Số biến thể">
            <span class="px-1 text-label text-cream-400">Biến thể</span>
            <button v-for="n in [1, 2, 4]" :key="n" type="button" class="h-6 min-w-6 rounded-md px-1.5 text-body font-semibold transition" :class="Number(variantCount) === n ? 'bg-brand-600 text-primary-content' : 'text-cream-400 hover:text-cream-100'" @click="variantCount = n">{{ n }}</button>
          </div>
          <div class="flex items-center gap-1 rounded-lg bg-ink-800 p-1" role="group" aria-label="Tỉ lệ khung hình">
            <span class="px-1 text-label text-cream-400">Tỉ lệ</span>
            <button v-for="r in ratioOptions" :key="r" type="button" class="h-6 rounded-md px-2 text-body font-semibold transition" :class="store.imageRatio === r ? 'bg-brand-600 text-primary-content' : 'text-cream-400 hover:text-cream-100'" @click="store.imageRatio = r">{{ r }}</button>
          </div>
          <span class="ml-auto text-label font-semibold text-cream-400" title="Số credit ước tính">~{{ creditEstimate }} credit</span>
          <button type="button" class="btn-brand btn-sm flex items-center gap-1.5" :disabled="!canGenerate" @click="generate" title="Tạo ảnh (Enter)">
            <StudioIcon name="zap" size="h-3.5 w-3.5" /> Tạo ảnh
          </button>
        </div>

        <!-- Dòng lý do nằm NGAY DƯỚI nút chính — quy tắc §4.4 của docs/DESIGN_SYSTEM.md: nút mờ phải
             nói vì sao, và câu ấy lấy từ chính blockReason dùng để khoá nút. -->
        <p v-if="blockReason" class="mt-2 text-label leading-4 text-warn">↳ {{ blockReason }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-ink-700/70 pt-3">
          <span class="text-label text-cream-400">Gợi ý:</span>
          <button v-for="example in EXAMPLES" :key="example.label" type="button"
                  class="rounded-full border border-ink-600 bg-ink-800 px-3 py-1.5 text-label font-semibold text-cream-200 transition-colors hover:border-brand-400 hover:text-cream-50"
                  :title="example.text" @click="useExample(example)">{{ example.label }}</button>
          <button type="button" class="tool-btn ml-auto" title="Mở bảng Prompt Tạo Ảnh đầy đủ: prefix, negative, phom dáng, mẫu việc" @click="store.promptOpen = true">
            <StudioIcon name="sliders" size="h-3.5 w-3.5" /> Bảng đầy đủ
          </button>
        </div>

        <p class="mt-2 text-label text-cream-400">Enter để tạo nhanh · Shift+Enter xuống dòng</p>
      </section>
    </div>
  </div>
</template>
