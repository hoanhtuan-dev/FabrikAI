<script setup>
/**
 * Canvas trống — ô mô tả tạo ảnh + TAB TRÒ CHUYỆN tìm thông tin & xu hướng (2026-09-26 · đợt 27).
 *
 * Ba thứ người dùng yêu cầu ở màn hình này:
 *   1. Ô mô tả CUỘN ĐƯỢC — mô tả dài không đẩy nút Tạo ảnh ra khỏi tầm mắt (textarea tự cuộn trong
 *      khung `max-h-[38vh]`, cả vùng canvas trống vẫn cuộn được trên màn hình thấp).
 *   2. ẨN ĐƯỢC và GỌI LẠI ĐƯỢC — nút thu gọn trong thẻ; trạng thái nhớ theo trình duyệt
 *      (khoá `fabrikai:` — cùng tiền tố với phần lưu cục bộ khác của Studio), khi ẩn thì còn một nút
 *      "Mở ô tạo ảnh" để gọi lại.
 *   3. TAB TRÒ CHUYỆN — CHAT THẬT: hỏi bằng lời, trợ lý trả lời THEO LUỒNG (chữ hiện dần) và tự tra
 *      internet khi cần. [ĐỔI 2026-09-26] Trước đây tab này KHÔNG gọi model: nó tách từ khoá ở trình
 *      duyệt rồi ghép câu trả lời từ dữ liệu radar — người dùng tưởng đang hỏi AI. Nay câu trả lời là
 *      chữ của trợ lý, kèm NGUỒN BẤM ĐƯỢC để tự kiểm; không có dữ liệu thì nói thẳng là chưa có.
 *
 * Giữ nguyên: z-0 (dưới layer), hành vi generate, state store hiện có (imagePromptEn · imageRatio ·
 * variantCount · planCostImage). Tab Trò chuyện KHÔNG còn đọc tín hiệu radar ở đây — việc tra cứu nay do
 * trợ lý làm ở máy chủ (xem khối chú thích của tab đó).
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
// Cảnh báo + dòng số đo của lượt chat: HÀM DÙNG CHUNG với bước «Hỏi đáp» của Agent Studio, không chép lại.
import { agentChatMetaLine, agentChatNotes } from '../store/actions/agentChat.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const promptEl = ref(null);
const chatBox = ref(null);

// ─────────────────────────── Tab: Tạo ảnh · Trò chuyện ───────────────────────────
const TAB_KEY = 'fabrikai:studio:empty-tab';
const COLLAPSE_KEY = 'fabrikai:studio:prompt-collapsed';
const tab = ref('compose');
const collapsed = ref(false);

function remember(key, value) {
  try { localStorage.setItem(key, value); } catch (e) { /* chế độ riêng tư: chỉ nhớ trong phiên */ }
}

function recall(key, fallback) {
  try { return localStorage.getItem(key) || fallback; } catch (e) { return fallback; }
}

function pickTab(id) {
  tab.value = id;
  remember(TAB_KEY, id);
  if (id === 'compose') focusPrompt();
  if (id === 'chat') scrollChat();
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

// ─────────────────────────── Tab Trò chuyện ───────────────────────────
/**
 * CHAT THẬT — hỏi bằng lời, trả lời theo luồng, có nguồn để tự kiểm (2026-09-26 · đợt 36).
 *
 * [VÌ SAO PHẢI THAY — ĐỌC KHỐI NÀY TRƯỚC KHI SỬA] Bản trước của tab này KHÔNG gọi model: nó tách từ
 * khoá ở TRÌNH DUYỆT, chấm điểm khớp trên tín hiệu TrendRadar trong store rồi GHÉP câu trả lời từ ba
 * đường mạng (đọc tín hiệu · tin nguồn ngoài · tìm trong kho thiết kế cũ). Người dùng đọc một khung
 * mang tên "Trò chuyện" và TƯỞNG đang hỏi AI, trong khi thứ họ nhận được là TRUY HỒI + XẾP HẠNG do
 * chính trình duyệt làm. Đó là chỗ nói dối người dùng ⇒ đã GỠ HẲN. KHÔNG để lại đường trả lời thứ hai
 * song song: hai đường là hai câu trả lời có thể mâu thuẫn, mà người dùng không có cách nào biết
 * đường nào đang nói với mình.
 *
 * Hội thoại KHÔNG sống ở component này: nó nằm ở KHO DỮ LIỆU DÙNG CHUNG (store.agentChatMessages cùng
 * các action trong store/actions/agentChat.js) — ĐÚNG chỗ bước «Hỏi đáp» của Agent Studio đang dùng.
 * ĐÓ LÀ CHỦ Ý, KHÔNG PHẢI TRÙNG LẶP: MỘT trợ lý, MỘT hội thoại. Hai màn cùng đọc/ghi một mảng tin
 * nhắn thì không thể có hai lịch sử lệch nhau, và sửa luồng ở một bên là bên kia đổi theo.
 * (Nói cho đúng mức: hai màn là hai TRANG khác nhau nên khi đổi trang thì lịch sử không tự đi theo —
 * hiện KHÔNG lưu ra localStorage. Ghi rõ ở đây để người sau không tưởng nó có.)
 *
 * Ba thứ khung này làm theo ĐÚNG cách của components/agents/AgentChatStep.vue (một quy ước, hai nơi
 * hiển thị — không phải hai bản sao):
 *   · chữ CHẢY TỪNG MẢNH, nối đúng thứ tự máy chủ gửi;
 *   · nguồn là LINK THẬT (target="_blank" + rel="noopener"): một trích dẫn không bấm được thì người
 *     dùng không kiểm chứng được gì, mà cả khung này tồn tại chính vì việc kiểm chứng;
 *   · SỐ ĐO (thời gian · số nguồn) lấy từ MÁY CHỦ — giao diện KHÔNG tự bấm giờ, KHÔNG tự đếm nguồn.
 * Tên nhà cung cấp / tên model KHÔNG xuất hiện ở bất kỳ đâu (§6.1 luật 2): sự kiện "provider" của luồng
 * bị kho dữ liệu bỏ hẳn nên nó không chảy vào state hiển thị nào.
 */

/** Câu hỏi gợi ý: đúng những việc một trợ lý có công cụ tra cứu trả lời được. */
const SUGGESTIONS = [
  'Xu hướng nào đang lên?',
  'Chất liệu nào đang được chú ý?',
  'Màu sắc cho bộ tới nên chọn gì?',
];

/** Câu hỏi đang gõ. HỘI THOẠI thì ở kho dữ liệu — xem khối chú thích ngay trên. */
const question = ref('');

/**
 * Khu vực hỏi: màn hình trống KHÔNG có bộ chọn khu vực (bộ đó nằm ở trang Agent Studio). "all" = toàn
 * bộ thị trường shop đang theo dõi — cũng là giá trị mặc định của hợp đồng máy chủ.
 */
const REGION = 'all';

/** Trần ký tự của MỘT lượt — chép đúng hợp đồng máy chủ (MAX_TURN_CHARS) để không gửi đi rồi bị 422. */
const CHAT_MAX_CHARS = 4000;

// Bề mặt hội thoại: TẤT CẢ đọc từ store. Component KHÔNG giữ bản sao — hai bản sao là hai lịch sử,
// và đúng lớp lỗi mà việc gộp về một kho dữ liệu sinh ra để chặn.
const chatMessages = computed(() => store.agentChatMessages || []);
const chatStreaming = computed(() => !! store.agentChatStreaming);
const chatPhaseLabel = computed(() => store.agentChatPhaseLabel || '');
const chatToolLine = computed(() => store.agentChatToolLine || '');
const chatError = computed(() => store.agentChatError || '');
const chatLastMeta = computed(() => store.agentChatLastMeta || null);
/** Cảnh báo + dòng số đo của lượt vừa rồi: HÀM DÙNG CHUNG với bước «Hỏi đáp» của Agent Studio. */
const chatNotes = computed(() => agentChatNotes(chatLastMeta.value));
const chatMetaLine = computed(() => agentChatMetaLine(chatLastMeta.value));

/** MỘT nguồn cho cả việc khoá nút Hỏi lẫn câu nói vì sao khoá (§4.4 của docs/DESIGN_SYSTEM.md). */
const chatBlockReason = computed(() => {
  if (chatStreaming.value) return '';
  return question.value.trim() ? '' : 'Gõ câu hỏi rồi bấm Hỏi — ví dụ: «chất liệu nào đang lên?»';
});
const canAsk = computed(() => ! chatStreaming.value && ! chatBlockReason.value);

function scrollChat() {
  nextTick(() => { if (chatBox.value) chatBox.value.scrollTop = chatBox.value.scrollHeight; });
}

/**
 * Tự cuộn xuống cuối khi chữ đang chảy. Theo dõi ĐỘ DÀI của lượt cuối, không chỉ SỐ tin nhắn: chữ về
 * từng mảnh nên nếu chỉ đếm số tin thì câu trả lời dài sẽ chảy xuống dưới tầm nhìn mà màn hình đứng im.
 */
watch(
  () => {
    const list = chatMessages.value;
    const last = list[list.length - 1];
    const citations = (last && last.citations && last.citations.length) || 0;
    return list.length + ':' + String((last && last.text) || '').length + ':' + citations;
  },
  () => scrollChat(),
);

/**
 * Gửi một câu hỏi. Không truyền gì ⇒ lấy chữ đang gõ (nút Hỏi · phím Enter); truyền câu gợi ý ⇒ gửi
 * luôn câu đó (gợi ý là câu hỏi HOÀN CHỈNH, không phải mẫu để sửa).
 *
 * Ô nhập CHỈ bị xoá khi câu hỏi ĐÃ ĐI: action trả về false khi CHƯA gửi được (hết phiên · thiếu gói ·
 * mất mạng) — giữ lại chữ người dùng vừa gõ là cách duy nhất để họ không phải gõ lại từ đầu.
 */
async function ask(text) {
  const q = String(text == null ? question.value : text).trim();
  if (! q || chatStreaming.value) return;
  const sent = await store.agentChatAsk(q, REGION);
  if (sent) question.value = '';
  scrollChat();
}

/** Dừng giữa lượt — phần chữ ĐÃ NHẬN được giữ lại (kho dữ liệu lo việc đó, xem agentChatStop). */
function stopChat() {
  return store.agentChatStop();
}

/** Hội thoại mới — chỉ xoá ở màn hình; dữ liệu shop và những nguồn đã tra không bị đụng tới. */
function resetChat() {
  store.agentChatReset();
  scrollChat();
}

/**
 * CẦU NỐI "tìm hiểu → làm" — đưa CÂU TRẢ LỜI của trợ lý vào ô mô tả tạo ảnh.
 *
 * [ĐỔI 2026-09-26] Bản cũ đưa một XU HƯỚNG lấy từ mảng mà chính trình duyệt ghép ra; mảng đó đã gỡ
 * cùng đường trả lời giả, nên cầu nối nay lấy thứ có thật: chữ trợ lý vừa viết. Mất cầu nối này là bắt
 * người dùng tự chép tay từ khung chat sang ô mô tả — đúng việc mà sản phẩm phải làm hộ.
 */
function useAnswer(text) {
  const line = String(text || '').trim();
  if (! line) return;
  promptText.value = (promptText.value ? promptText.value + ' ' : '') + line;
  collapsed.value = false;
  remember(COLLAPSE_KEY, '0');
  pickTab('compose');
}

function onChatKeydown(event) {
  // Enter GỬI · Shift+Enter XUỐNG DÒNG — quy ước của mọi ô chat.
  // isComposing PHẢI được tôn trọng: bộ gõ tiếng Việt dùng Enter để CHỐT DẤU, chặn nó là gõ dấu là gửi.
  if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
  event.preventDefault();
  ask();
}

/**
 * ĐÃ GỠ khỏi tab này (đợt 36) — và GHI RÕ LÝ DO, để không ai "khôi phục lại cho đủ bộ":
 *   · hàm tách từ khoá + chấm điểm khớp + hằng số trần thời gian của đường cũ: cả bộ đó tồn tại CHỈ để
 *     ghép câu trả lời giả ở trình duyệt. Đường ghép đã gỡ thì chúng là mã chết;
 *   · nút «Đưa vào mô tả ảnh» + hàm dựng tiêu đề xu hướng: nút đó lấy dữ liệu từ CHÍNH mảng xu hướng mà
 *     đường cũ tự ghép. Nay câu trả lời là chữ của trợ lý nên KHÔNG còn mảng nào để đưa vào ô mô tả —
 *     giữ lại chỉ được một nút không có dữ liệu, bấm vào không ra gì (một chỗ nói dối khác).
 *     Cầu nối "tìm hiểu → làm" KHÔNG mất: nó vẫn nằm ở Agent Studio (bước Tín hiệu → Đưa vào mô tả),
 *     nơi dữ liệu xu hướng thật sự sống.
 *   · hai câu "khớp/không khớp câu hỏi" của đường cũ: chúng mô tả một việc (so khớp từ khoá) mà sản
 *     phẩm KHÔNG còn làm nữa.
 */

onMounted(() => {
  tab.value = recall(TAB_KEY, 'compose') === 'chat' ? 'chat' : 'compose';
  collapsed.value = recall(COLLAPSE_KEY, '0') === '1';
  // [đợt 26] Tự đặt con trỏ — CHỈ trên thiết bị trỏ mịn: trên điện thoại, focus sẽ bật bàn phím ảo.
  const coarse = typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  if (! coarse && ! collapsed.value && tab.value === 'compose') focusPrompt();
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

    <div v-else class="mx-auto flex w-full max-w-3xl flex-col gap-3 py-4 sm:py-8">
      <!-- [2026-09-26 · đợt 28] ĐÃ BỎ dòng tiêu đề mào đầu (icon + tựa + phụ đề): màn hình trống chỉ
           còn đúng việc để làm. Nút thu gọn nay nằm trong hàng tab cho gọn. -->
      <!-- Hai tab: Tạo ảnh · Trò chuyện -->
      <div class="flex items-center gap-1 rounded-xl border border-ink-700 bg-ink-900/80 p-1" role="tablist" aria-label="Chế độ màn hình trống">
        <button type="button" role="tab" :aria-selected="tab === 'compose' ? 'true' : 'false'" data-tab="compose"
                class="flex min-h-9 flex-1 items-center justify-center gap-1.5 rounded-lg px-3 text-xs font-semibold transition-colors"
                :class="tab === 'compose' ? 'bg-brand-600 text-primary-content' : 'text-cream-300 hover:bg-ink-800 hover:text-cream-100'"
                @click="pickTab('compose')">
          <StudioIcon name="imagePlus" size="h-3.5 w-3.5" /> Tạo ảnh
        </button>
        <button type="button" role="tab" :aria-selected="tab === 'chat' ? 'true' : 'false'" data-tab="chat"
                class="flex min-h-9 flex-1 items-center justify-center gap-1.5 rounded-lg px-3 text-xs font-semibold transition-colors"
                :class="tab === 'chat' ? 'bg-brand-600 text-primary-content' : 'text-cream-300 hover:bg-ink-800 hover:text-cream-100'"
                @click="pickTab('chat')">
          <StudioIcon name="search" size="h-3.5 w-3.5" /> Trò chuyện
        </button>
        <!-- Thu gọn: ẩn ô mô tả, còn nút gọi lại (yêu cầu 2026-09-26). -->
        <button type="button" class="icon-btn !h-8 !w-8 shrink-0" data-prompt-collapse title="Ẩn ô mô tả" aria-label="Ẩn ô mô tả" @click="toggleCollapse">
          <StudioIcon name="chevronUp" size="h-4 w-4" />
        </button>
      </div>

      <section v-show="tab === 'compose'" class="rounded-2xl border border-ink-700 bg-ink-900/90 p-3 shadow-2xl sm:p-4" aria-label="Mô tả ảnh">
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
        </div>

        <p class="mt-2 text-label text-cream-400">Enter để tạo nhanh · Shift+Enter xuống dòng</p>
      </section>

      <!-- ═════════ TAB TRÒ CHUYỆN: hỏi bằng lời · trả lời theo luồng · có nguồn tự kiểm ═════════ -->
      <section v-show="tab === 'chat'" class="rounded-2xl border border-ink-700 bg-ink-900/90 p-3 shadow-2xl sm:p-4" aria-label="Trò chuyện với trợ lý thiết kế">
        <div ref="chatBox" class="max-h-[46vh] space-y-3 overflow-y-auto pr-1" data-chat-log role="log" aria-live="polite">
          <div v-if="!chatMessages.length" class="rounded-xl border border-ink-700 bg-ink-800/60 p-3">
            <p class="flex items-center gap-2 text-xs font-semibold text-cream-100">
              <StudioIcon name="search" size="h-4 w-4" class="text-brand-300" /> Hỏi trợ lý về bộ sưu tập của bạn
            </p>
            <p class="mt-1 text-body leading-relaxed text-cream-300">
              Trợ lý đọc hồ sơ thương hiệu và quy tắc làm việc bạn đã khai, tự tra internet khi cần dữ kiện,
              rồi trả lời kèm NGUỒN BẤM ĐƯỢC để bạn tự kiểm. Chưa có dữ liệu thì nói thẳng là chưa có — không bịa.
            </p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button v-for="s in SUGGESTIONS" :key="s" type="button" data-chat-suggestion
                      class="rounded-full border border-ink-600 bg-ink-800 px-3 py-1.5 text-label font-semibold text-cream-200 transition-colors hover:border-brand-400 hover:text-cream-50"
                      @click="ask(s)">{{ s }}</button>
            </div>
          </div>

          <div v-for="(m, i) in chatMessages" :key="i" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
            <div v-if="m.role === 'user'" class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-brand-600 px-3 py-2 text-body text-primary-content">{{ m.text }}</div>
            <div v-else class="w-full rounded-2xl rounded-bl-sm border border-ink-700 bg-ink-800/70 px-3 py-2">
              <!-- Lỗi của RIÊNG lượt này: câu hướng dẫn đã qua userFacingError ở kho dữ liệu (§6.1 luật 5). -->
              <p v-if="m.failed" class="text-body text-warn">Chưa trả lời được câu này. Bạn thử lại sau ít phút.</p>
              <template v-else>
                <!-- CHỮ CỦA TRỢ LÝ — chảy từng mảnh, giữ nguyên xuống dòng người dùng thấy được. -->
                <p v-if="m.text" class="whitespace-pre-wrap text-body leading-relaxed text-cream-100">{{ m.text }}</p>
                <p v-else class="text-body text-cream-400">Đang trả lời…</p>

                <!-- NÓI THẬT khi người dùng bấm Dừng: phần chữ đã nhận được GIỮ LẠI, không xoá đi. -->
                <p v-if="m.stopped" class="mt-1 text-label text-cream-400">Bạn đã dừng lượt này — phần trả lời ở trên là phần đã nhận được.</p>

                <!-- NGUỒN LÀ LINK THẬT: cả khung chat tồn tại để người dùng KIỂM, không phải để tin. -->
                <button v-if="m.text && ! m.streaming" type="button" class="tool-btn mt-2" data-use-answer
                        title="Đưa câu trả lời này vào ô mô tả tạo ảnh" @click="useAnswer(m.text)">
                  <StudioIcon name="wand" size="h-3.5 w-3.5" /> Đưa vào mô tả ảnh
                </button>

                <div v-if="m.citations && m.citations.length" class="mt-2 border-t border-ink-700/70 pt-2">
                  <p class="text-label font-semibold text-cream-400">Nguồn để bạn tự kiểm</p>
                  <ul class="mt-1 space-y-1">
                    <li v-for="(c, ci) in m.citations" :key="c.ref || ci" class="text-body leading-snug">
                      <a v-if="c.url" :href="c.url" target="_blank" rel="noopener" class="text-brand-200 underline decoration-dotted hover:text-cream-50">{{ c.title || c.url }}</a>
                      <span v-else class="text-cream-200">{{ c.title }}</span>
                      <span v-if="c.source_name" class="text-cream-400"> — {{ c.source_name }}</span>
                    </li>
                  </ul>
                </div>
              </template>
            </div>
          </div>

          <!-- Tiến trình THẬT của lượt đang chạy: nhãn giai đoạn + đang tra gì (nếu có công cụ chạy). -->
          <p v-if="chatStreaming" class="flex flex-wrap items-center gap-2 text-body text-cream-400">
            <StudioIcon name="refresh" size="h-3.5 w-3.5" class="animate-spin" /> {{ chatPhaseLabel || 'Đang suy luận…' }}
            <span v-if="chatToolLine" class="text-cream-400">· {{ chatToolLine }}</span>
          </p>
        </div>

        <!-- SỐ ĐO + cảnh báo của lượt vừa rồi: nguyên văn từ máy chủ, không tự bấm giờ ở trình duyệt. -->
        <div v-if="chatMetaLine || chatNotes.length" class="mt-2 space-y-1">
          <p v-if="chatMetaLine" class="text-label text-cream-400">{{ chatMetaLine }}</p>
          <p v-for="note in chatNotes" :key="note" class="text-label text-warn">↳ {{ note }}</p>
        </div>
        <p v-if="chatError" class="mt-2 text-label text-warn">↳ {{ chatError }}</p>

        <form class="mt-3 flex items-end gap-2" @submit.prevent="ask()">
          <label for="canvas-chat-input" class="sr-only">Câu hỏi cho trợ lý thiết kế</label>
          <textarea id="canvas-chat-input" v-model="question" rows="2" :maxlength="CHAT_MAX_CHARS"
                    class="input max-h-[24vh] min-h-[2.75rem] w-full resize-none overflow-y-auto !rounded-xl !py-2.5 !text-sm"
                    placeholder="Hỏi: chất liệu nào đang lên? màu nào hợp bộ Thu Đông?"
                    @keydown="onChatKeydown"></textarea>
          <!-- Đang trả lời thì nút chính là DỪNG: người dùng phải luôn có đường thoát khỏi lượt đang chạy. -->
          <button v-if="chatStreaming" type="button" class="tool-btn shrink-0" title="Dừng lượt trả lời — phần chữ đã nhận được giữ lại" @click="stopChat">
            <StudioIcon name="ban" size="h-3.5 w-3.5" /> Dừng
          </button>
          <button v-else type="submit" class="btn-brand btn-sm shrink-0" :disabled="!canAsk">
            <StudioIcon name="zap" size="h-3.5 w-3.5" /> Hỏi
          </button>
        </form>

        <div class="mt-2 flex flex-wrap items-center gap-2">
          <p v-if="chatBlockReason" class="text-label leading-4 text-warn">↳ {{ chatBlockReason }}</p>
          <button v-if="chatMessages.length" type="button" class="tool-btn ml-auto !px-2 !py-1 !text-tiny" @click="resetChat">
            <StudioIcon name="refresh" size="h-3 w-3" /> Hội thoại mới
          </button>
        </div>
        <p class="mt-2 text-label text-cream-400">Trả lời dựa trên hồ sơ thương hiệu của shop và kết quả tra cứu thật — kèm liên kết để bạn tự kiểm. Có lượt chữ hiện dần, có lượt hiện một lần; mình luôn nói rõ khi nguồn là nguồn cũ hoặc chưa đầy đủ.</p>
      </section>
    </div>
  </div>
</template>
