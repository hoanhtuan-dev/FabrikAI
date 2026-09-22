<script setup>
/**
 * Canvas trống — CHỈ CÒN ô mô tả tạo ảnh (2026-09-26 · đợt 37).
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26 — ĐỌC TRƯỚC KHI "KHÔI PHỤC LẠI CHO ĐỦ BỘ"]
 * Màn hình này TRƯỚC ĐÂY có thêm một TAB «Trò chuyện» (chat theo luồng, có nguồn để tự kiểm). Tab đó
 * đã GỠ HẲN khỏi đây, vì hai lý do THẬT chứ không phải để gọn mắt:
 *   · CHỖ SAI — nó chỉ mở được khi canvas TRỐNG: vừa có ảnh trên canvas là khung chat biến mất, đúng
 *     lúc người dùng cần hỏi nhất («chất liệu này có co không?»);
 *   · TRỘN VIỆC — một màn hình chỉ để TẠO ẢNH lại mang thêm một thanh tab và một trạng thái
 *     đang-mở-tab phải nhớ trong localStorage; bấm nhầm tab là mất chỗ đang gõ dở.
 * Chat nay là MODAL DÙNG CHUNG mở được từ bất kỳ đâu trong /studio (components/ChatModal.vue; lối vào
 * chính là NÚT NỔI ở góc dưới–phải vùng canvas — components/ChatFab.vue — cộng một lệnh trong bảng
 * lệnh). Ở đây chỉ còn MỘT nút phụ «Hỏi trợ lý» để người đang đứng ở canvas trống vẫn tới được nó.
 * KHÔNG có bản chat thứ hai ở đây:
 * hai khung chat là hai lịch sử, và hai câu trả lời có thể mâu thuẫn mà người dùng không biết tin bản nào.
 *
 * Giữ nguyên: z-0 (dưới layer), hành vi generate, state store hiện có (imagePromptEn · imageRatio ·
 * variantCount · planCostImage), và ba tính chất của ô mô tả — CUỘN ĐƯỢC · ẨN ĐƯỢC · GỌI LẠI ĐƯỢC.
 */
import { computed, nextTick, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const promptEl = ref(null);

// Trạng thái ẨN/GỌN của ô mô tả — nhớ theo TRÌNH DUYỆT (khoá fabrikai: — cùng tiền tố với phần lưu cục
// bộ khác của Studio), vì đây là sở thích của người dùng trên máy này, không phải dữ liệu của shop.
const COLLAPSE_KEY = 'fabrikai:studio:prompt-collapsed';
const collapsed = ref(false);

function remember(key, value) {
  try { localStorage.setItem(key, value); } catch (e) { /* chế độ riêng tư: chỉ nhớ trong phiên */ }
}

function recall(key, fallback) {
  try { return localStorage.getItem(key) || fallback; } catch (e) { return fallback; }
}

function toggleCollapse() {
  collapsed.value = ! collapsed.value;
  remember(COLLAPSE_KEY, collapsed.value ? '1' : '0');
  if (! collapsed.value) focusPrompt();
}

// ─────────────────────────── Ô mô tả tạo ảnh ───────────────────────────
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
const EXAMPLES = [
  { label: 'Váy linen pastel', text: 'Váy linen pastel dáng suông, nữ văn phòng, ánh sáng mềm, nền studio sáng' },
  { label: 'Sơ mi oversize', text: 'Áo sơ mi trắng oversize, cotton dày, phong cách tối giản, ánh sáng ban ngày' },
  { label: 'Đầm dạ hội', text: 'Đầm dạ hội sequin đen dáng ôm, nền sân khấu tối, ánh sáng viền' },
];

function focusPrompt() {
  nextTick(() => { if (promptEl.value) promptEl.value.focus(); });
}

function useExample(example) {
  promptText.value = example.text;
  focusPrompt();
}

/** Thêm một dấu xuống dòng tại vị trí con trỏ — cho máy không có phím Shift+Enter tiện (điện thoại). */
function insertNewline() {
  const el = promptEl.value;
  if (! el) return;
  const start = el.selectionStart ?? promptText.value.length;
  const end = el.selectionEnd ?? start;
  promptText.value = promptText.value.slice(0, start) + '\n' + promptText.value.slice(end);
  nextTick(() => {
    el.selectionStart = el.selectionEnd = start + 1;
    focusPrompt();
  });
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
  collapsed.value = recall(COLLAPSE_KEY, '0') === '1';
  // [đợt 26] Tự đặt con trỏ — CHỈ trên thiết bị trỏ mịn: trên điện thoại, focus sẽ bật bàn phím ảo.
  const coarse = typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  if (! coarse && ! collapsed.value) focusPrompt();
});
</script>

<template>
  <!-- z-0 (không phải z-20): lớp này nằm DƯỚI các layer. Ẩn layer cuối cùng thì màn hình trống
       hiện ra ngay và không che hiệu ứng mờ của layer đang tắt. -->
  <div class="motion-fade-in absolute inset-0 z-0 overflow-y-auto bg-gradient-to-b from-ink-950/97 via-ink-950/94 to-ink-950/97 p-4 backdrop-blur-sm sm:p-6" role="region" aria-label="Canvas trống">
    <!-- TRẠNG THÁI THU GỌN: ô mô tả đã ẩn, còn đúng một nút gọi lại. -->
    <div v-if="collapsed" class="mx-auto flex w-full max-w-3xl flex-col items-center gap-2 py-12 text-center sm:py-20">
      <button type="button" class="btn-brand" data-prompt-recall @click="toggleCollapse">
        <StudioIcon name="sparkles" size="h-4 w-4" /> Mở ô tạo ảnh
      </button>
      <p class="text-label text-cream-400">Ô mô tả đang ẩn. Bấm để mở lại — trạng thái được nhớ cho lần sau.</p>
    </div>

    <div v-else class="mx-auto flex w-full max-w-3xl flex-col gap-2 py-4 sm:py-8">
      <!-- Nút ẩn ô mô tả. [2026-09-26] Trước đây nó nằm trong hàng tab (Tạo ảnh · Trò chuyện) — hàng
           tab đã gỡ cùng tab chat, nên nó đứng riêng một hàng mảnh, không thêm tiêu đề mào đầu. -->
      <div class="flex items-center justify-end">
        <button type="button" class="icon-btn !h-8 !w-8 shrink-0" data-prompt-collapse title="Ẩn ô mô tả" aria-label="Ẩn ô mô tả" @click="toggleCollapse">
          <StudioIcon name="chevronUp" size="h-4 w-4" />
        </button>
      </div>

      <section class="rounded-2xl border border-ink-700 bg-ink-900/90 p-3 shadow-2xl sm:p-4" aria-label="Mô tả ảnh">
        <label for="canvas-quick-prompt" class="sr-only">Mô tả ảnh cần tạo</label>
        <div class="relative">
          <textarea
            id="canvas-quick-prompt"
            ref="promptEl"
            v-model="promptText"
            rows="6"
            class="input max-h-[38vh] min-h-[7rem] w-full resize-none overflow-y-auto !rounded-xl !py-3 !pr-11 !text-base"
            placeholder="Ví dụ: Bộ sưu tập linen pastel, nữ văn phòng, ánh sáng mềm, nền studio sáng…"
            @keydown="onPromptKeydown"
          ></textarea>
          <button type="button" class="icon-btn absolute bottom-2 right-2 !h-8 !w-8" data-prompt-newline title="Xuống dòng (thêm dòng mới)" aria-label="Xuống dòng" @click="insertNewline">
            <StudioIcon name="cornerDownLeft" size="h-4 w-4" />
          </button>
        </div>

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

        <!-- Dòng lý do nằm NGAY DƯỚI nút chính — quy tắc §4.4 của docs/DESIGN_SYSTEM.md. -->
        <p v-if="blockReason" class="mt-2 text-label leading-4 text-warn">↳ {{ blockReason }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-ink-700/70 pt-3">
          <span class="text-label text-cream-400">Gợi ý:</span>
          <button v-for="example in EXAMPLES" :key="example.label" type="button"
                  class="rounded-full border border-ink-600 bg-ink-800 px-3 py-1.5 text-label font-semibold text-cream-200 transition-colors hover:border-brand-400 hover:text-cream-50"
                  :title="example.text" @click="useExample(example)">{{ example.label }}</button>
          <button type="button" class="tool-btn ml-auto" title="Mở bảng Prompt Tạo Ảnh đầy đủ: prefix, negative, phom dáng, mẫu việc" @click="store.promptOpen = true">
            <StudioIcon name="sliders" size="h-3.5 w-3.5" /> Bảng đầy đủ
          </button>
          <!-- Cầu nối sang MODAL TRỢ LÝ. [2026-09-26] Nút này MỞ ĐÚNG modal dùng chung — nó KHÔNG dựng
               một khung chat thứ hai ở đây: hai khung chat là hai lịch sử, mà người dùng thì không có
               cách nào biết bên nào đang nói với mình. (Lối vào Agent Studio đã rời khỏi màn này từ đợt
               trước — nó là một TRANG riêng, mở từ thanh công cụ; đừng thêm lại lối vào thứ hai.) -->
          <button type="button" class="tool-btn" data-chat-open title="Hỏi trợ lý thiết kế — mở khung chat, câu trả lời kèm nguồn bấm được để bạn tự kiểm" @click="store.chatOpen = true">
            <StudioIcon name="bot" size="h-3.5 w-3.5" /> Hỏi trợ lý
          </button>
        </div>

        <p class="mt-2 text-label text-cream-400">Enter để tạo nhanh · Shift+Enter xuống dòng</p>
      </section>
    </div>
  </div>
</template>
