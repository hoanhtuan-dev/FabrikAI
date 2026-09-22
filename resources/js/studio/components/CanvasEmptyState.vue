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
 *   3. TAB TRÒ CHUYỆN — hỏi về xu hướng/chất liệu/màu sắc; câu trả lời KHÔNG bịa: nó lấy từ đúng
 *      dữ liệu máy chủ đang có (TrendRadar của shop + tin nguồn ngoài + kho thiết kế cũ của chính
 *      người dùng). Không có dữ liệu thì nói thẳng là chưa có.
 *
 * Giữ nguyên: z-0 (dưới layer), hành vi generate, state store hiện có (imagePromptEn · imageRatio ·
 * variantCount · planCostImage · loadTrendRadar).
 */
import { computed, nextTick, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
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
/** Câu hỏi gợi ý: đúng những việc dữ liệu hiện có trả lời được. */
const SUGGESTIONS = [
  'Xu hướng nào đang lên?',
  'Chất liệu nào đang được chú ý?',
  'Màu sắc cho bộ tới nên chọn gì?',
];
const messages = ref([]);
const question = ref('');
const thinking = ref(false);

/** MỘT nguồn cho cả việc khoá nút Hỏi lẫn câu nói vì sao khoá (§4.4 của docs/DESIGN_SYSTEM.md). */
const chatBlockReason = computed(() => {
  if (thinking.value) return '';
  return question.value.trim() ? '' : 'Gõ câu hỏi rồi bấm Hỏi — ví dụ: «chất liệu nào đang lên?»';
});
const canAsk = computed(() => ! thinking.value && ! chatBlockReason.value);

const REGION = 'all';
/** Trần chờ phần đọc tín hiệu (xem withTimeout): quá hạn thì trả lời bằng dữ liệu đã có. */
const SIGNAL_TIMEOUT_MS = 12000;
/** Trần chờ đường nhanh (tin nguồn + tín hiệu đã đo) — chính đường này cũng đi lấy RSS nên cũng có thể chậm. */
const EVIDENCE_TIMEOUT_MS = 6000;
/** Trần chờ tìm trong kho thiết kế cũ — đường này cũng gọi model để nhúng câu hỏi, nên cũng có thể chậm. */
const ARCHIVE_TIMEOUT_MS = 5000;
const STOP_WORDS = ['xhuong', 'xuong', 'huong', 'dang', 'tuan', 'nay', 'nao', 'cho', 'cua', 'voi', 'the', 'and', 'cac', 'nhung'];

function words(text) {
  return String(text || '')
    .toLowerCase()
    .split(/[^\p{L}\p{N}]+/u)
    .filter((w) => w.length > 2 && ! STOP_WORDS.includes(w));
}

const TREND_KEYS = ['title', 'name', 'description', 'recommended_action', 'category'];

function matchScore(item, wanted) {
  const hay = TREND_KEYS.map((k) => item && item[k] ? String(item[k]) : '').join(' ').toLowerCase();
  return wanted.reduce((n, w) => (hay.includes(w) ? n + 1 : n), 0);
}

/**
 * Chờ tối đa `ms`: quá hạn thì trả null thay vì treo giao diện mãi.
 *
 * [Lý do có mặt — 2026-09-26 · đợt 27] Đo thật trên máy: khi phần đọc tín hiệu phải gọi model, lời gọi
 * có thể kéo dài vô hạn (đã thấy >100 giây mà giao diện vẫn "Đang đọc tín hiệu…"). Người dùng không
 * được nhìn một vòng xoay vô tận: quá hạn thì trả lời bằng dữ liệu đã có sẵn và NÓI RÕ là bản đầy đủ
 * chưa xong.
 */
function withTimeout(promise, ms) {
  return new Promise((resolve) => {
    let done = false;
    const timer = setTimeout(() => { if (! done) { done = true; resolve(null); } }, ms);
    Promise.resolve(promise)
      .then((value) => { if (! done) { done = true; clearTimeout(timer); resolve(value || null); } })
      .catch(() => { if (! done) { done = true; clearTimeout(timer); resolve(null); } });
  });
}

/** Tin nguồn ngoài + tín hiệu thị trường đã đo — đường NHANH, không cần model. */
async function loadEvidence() {
  try {
    const res = await fetch('/api/design-agent/sources?region=' + REGION, { headers: { Accept: 'application/json' } });
    if (! res.ok) return null;
    const data = await res.json();
    const signals = Array.isArray(data.market) ? data.market : [];
    const news = Array.isArray(data.items) ? data.items : (Array.isArray(data.sources) ? data.sources : []);
    return { signals: signals.slice(0, 6), news: news.slice(0, 3), mode: data.mode || data.source_mode || '' };
  } catch (e) {
    return null;
  }
}

/** Kho thiết kế cũ của CHÍNH người dùng — máy chủ đã có tìm kiếm ngữ nghĩa sẵn. */
async function searchOwnDesigns(query) {
  try {
    const res = await fetch('/api/design-search?q=' + encodeURIComponent(query) + '&limit=3', { headers: { Accept: 'application/json' } });
    if (! res.ok) return [];
    const data = await res.json();
    const list = data.items || data.results || data.matches || [];
    return Array.isArray(list) ? list.slice(0, 3) : [];
  } catch (e) {
    return [];
  }
}

function scrollChat() {
  nextTick(() => { if (chatBox.value) chatBox.value.scrollTop = chatBox.value.scrollHeight; });
}

async function ask(text) {
  const q = String(text || question.value || '').trim();
  if (! q || thinking.value) return;
  messages.value.push({ role: 'user', text: q });
  question.value = '';
  thinking.value = true;
  scrollChat();

  try {
    // TrendRadar có sẵn trong store (cache theo khu vực + chế độ) thì trả lời ngay; chưa có thì chờ tối đa
    // SIGNAL_TIMEOUT_MS rồi rơi về đường nhanh (tin nguồn + tín hiệu đã đo).
    const radar = store.trendRadar || await withTimeout(store.loadTrendRadar(REGION, {}), SIGNAL_TIMEOUT_MS);
    const wanted = words(q);
    const trends = Array.isArray(radar && radar.trends) ? radar.trends.slice() : [];
    trends.sort((a, b) => matchScore(b, wanted) - matchScore(a, wanted));
    const matched = wanted.length ? trends.filter((t) => matchScore(t, wanted) > 0) : [];
    const top = (matched.length ? matched : trends).slice(0, 3);
    const sources = Array.isArray(radar && radar.sources) ? radar.sources.slice(0, 3) : [];
    // BA đường mạng trong một câu hỏi (đọc tín hiệu · tin nguồn · tìm kho thiết kế) — ĐO ĐƯỢC: chỉ cần
    // MỘT đường treo là cả giao diện treo ở "Đang đọc tín hiệu…". Nên cả ba đều có trần thời gian.
    const mine = (await withTimeout(searchOwnDesigns(q), ARCHIVE_TIMEOUT_MS)) || [];

    if (! radar) {
      // Quá hạn đọc tín hiệu ⇒ trả lời bằng dữ liệu ĐÃ CÓ, và nói rõ bản đầy đủ chưa xong.
      // Cả đường nhanh cũng có thể chậm (máy chủ đi lấy RSS) — vẫn phải có trần, nếu không người dùng
      // lại nhìn vòng xoay vô tận ở nhánh dự phòng.
      const evidence = await withTimeout(loadEvidence(), EVIDENCE_TIMEOUT_MS);
      messages.value.push({
        role: 'bot',
        askedFor: q,
        slow: true,
        signals: evidence ? evidence.signals : [],
        sources: evidence ? evidence.news : [],
        mine,
      });
      return;
    }

    messages.value.push({
      role: 'bot',
      askedFor: q,
      fresh: matched.length > 0,
      live: (radar && radar.source_mode) === 'live',
      trends: top,
      sources,
      mine,
    });
  } catch (e) {
    // Lỗi bất kỳ (mạng, mã, dữ liệu lạ) ⇒ một thẻ nói thật là chưa lấy được, KHÔNG để vòng xoay treo.
    messages.value.push({ role: 'bot', failed: true });
  } finally {
    // Bất biến: dù nhánh nào chạy, trạng thái "đang đọc" phải được nhả ra.
    thinking.value = false;
    scrollChat();
  }
}

function trendTitle(trend) {
  return trend.title || trend.name || trend.category || 'Xu hướng';
}

/** Đưa một xu hướng vào ô mô tả rồi quay về tab Tạo ảnh — cầu nối giữa tìm hiểu và làm. */
function useTrend(trend) {
  const line = [trendTitle(trend), trend.description, trend.recommended_action].filter(Boolean).join('. ');
  promptText.value = (store.imagePromptEn ? store.imagePromptEn + ' ' : '') + (line || trendTitle(trend));
  collapsed.value = false;
  remember(COLLAPSE_KEY, '0');
  pickTab('compose');
}

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
      <header class="flex items-center gap-3">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-brand-600/20 text-brand-300">
          <StudioIcon name="imagePlus" size="h-5 w-5" />
        </span>
        <div class="min-w-0 flex-1">
          <h2 class="font-display text-base font-semibold text-cream-50 sm:text-lg">Tạo ảnh đầu tiên</h2>
          <p class="mt-0.5 text-body text-cream-400">Mô tả trang phục, phong cách, bối cảnh và ánh sáng.</p>
        </div>
        <!-- Thu gọn: ẩn ô mô tả, còn nút gọi lại (yêu cầu 2026-09-26). -->
        <button type="button" class="icon-btn shrink-0" data-prompt-collapse title="Ẩn ô mô tả" aria-label="Ẩn ô mô tả" @click="toggleCollapse">
          <StudioIcon name="chevronUp" size="h-4 w-4" />
        </button>
      </header>

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
      </div>

      <section v-show="tab === 'compose'" class="rounded-2xl border border-ink-700 bg-ink-900/90 p-3 shadow-2xl sm:p-4" aria-label="Mô tả ảnh">
        <label for="canvas-quick-prompt" class="sr-only">Mô tả ảnh cần tạo</label>
        <textarea
          id="canvas-quick-prompt"
          ref="promptEl"
          v-model="promptText"
          rows="6"
          class="input max-h-[38vh] min-h-[7rem] w-full resize-none overflow-y-auto !rounded-xl !py-3 !text-base"
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

      <!-- ═════════ TAB TRÒ CHUYỆN: hỏi về xu hướng · chất liệu · màu sắc ═════════ -->
      <section v-show="tab === 'chat'" class="rounded-2xl border border-ink-700 bg-ink-900/90 p-3 shadow-2xl sm:p-4" aria-label="Trò chuyện về xu hướng">
        <div ref="chatBox" class="max-h-[46vh] space-y-3 overflow-y-auto pr-1" data-chat-log>
          <div v-if="!messages.length" class="rounded-xl border border-ink-700 bg-ink-800/60 p-3">
            <p class="flex items-center gap-2 text-xs font-semibold text-cream-100">
              <StudioIcon name="search" size="h-4 w-4" class="text-brand-300" /> Hỏi về xu hướng &amp; chất liệu
            </p>
            <p class="mt-1 text-body leading-relaxed text-cream-300">
              Trả lời lấy từ tín hiệu thị trường và nguồn tin đang nối của shop, cộng với kho thiết kế cũ của chính bạn.
              Chưa có dữ liệu thì mình nói thẳng là chưa có — không bịa.
            </p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button v-for="s in SUGGESTIONS" :key="s" type="button" data-chat-suggestion
                      class="rounded-full border border-ink-600 bg-ink-800 px-3 py-1.5 text-label font-semibold text-cream-200 transition-colors hover:border-brand-400 hover:text-cream-50"
                      @click="ask(s)">{{ s }}</button>
            </div>
          </div>

          <div v-for="(m, i) in messages" :key="i" class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
            <div v-if="m.role === 'user'" class="max-w-[85%] rounded-2xl rounded-br-sm bg-brand-600 px-3 py-2 text-body text-primary-content">{{ m.text }}</div>
            <div v-else class="w-full rounded-2xl rounded-bl-sm border border-ink-700 bg-ink-800/70 px-3 py-2">
              <p v-if="m.failed" class="text-body text-warn">Chưa lấy được thông tin xu hướng. Thử lại sau ít phút.</p>
              <template v-else>
                <!-- Quá hạn đọc tín hiệu: trả lời bằng dữ liệu ĐÃ CÓ và nói rõ bản đầy đủ chưa xong —
                     người dùng không bao giờ nhìn một vòng xoay vô tận. -->
                <template v-if="m.slow">
                  <p class="text-label text-cream-400">Việc đọc tín hiệu lâu hơn dự kiến — đây là những gì đã có sẵn:</p>
                  <div v-if="m.signals.length" class="mt-2 flex flex-wrap gap-1.5">
                    <span v-for="(s, si) in m.signals" :key="si" class="rounded-full border border-ink-600 bg-ink-900 px-2.5 py-1 text-label text-cream-200">
                      {{ s.category || s.label || 'Tín hiệu' }} · {{ s.term || s.keyword || s.value }}
                    </span>
                  </div>
                  <p v-else class="mt-2 text-body text-cream-300">Chưa đo được tín hiệu nào. Mở mục «Agent thiết kế» ở thanh công cụ bên trái để chạy đọc tín hiệu đầy đủ.</p>
                  <div v-if="m.sources.length" class="mt-2 border-t border-ink-700/70 pt-2">
                    <p class="text-label font-semibold text-cream-400">Tin nguồn</p>
                    <ul class="mt-1 space-y-1">
                      <li v-for="(s, si) in m.sources" :key="si" class="text-body leading-snug">
                        <a v-if="s.url" :href="s.url" target="_blank" rel="noopener" class="text-brand-200 underline decoration-dotted hover:text-cream-50">{{ s.title || s.url }}</a>
                        <span v-else class="text-cream-200">{{ s.title }}</span>
                        <span v-if="s.source_name" class="text-cream-400"> — {{ s.source_name }}</span>
                      </li>
                    </ul>
                  </div>
                  <button type="button" class="tool-btn mt-2" @click="ask(m.askedFor)">
                    <StudioIcon name="refresh" size="h-3.5 w-3.5" /> Hỏi lại (bản đầy đủ)
                  </button>
                </template>

                <template v-else>
                <p class="text-label text-cream-400">
                  <template v-if="m.fresh">Khớp với «{{ m.askedFor }}»</template>
                  <template v-else>Không thấy mục nào khớp đúng «{{ m.askedFor }}» — đây là những hướng đang được chú ý nhất</template>
                </p>
                <ul v-if="m.trends.length" class="mt-2 space-y-2">
                  <li v-for="t in m.trends" :key="t.id || trendTitle(t)" class="rounded-xl border border-ink-700 bg-ink-900/70 p-2.5">
                    <p class="flex flex-wrap items-center gap-1.5 text-xs font-semibold text-cream-50">
                      {{ trendTitle(t) }}
                      <span v-if="t.lifecycle_label || t.lifecycle" class="rounded bg-ink-700 px-1.5 py-0.5 text-label font-normal text-cream-300">{{ t.lifecycle_label || t.lifecycle }}</span>
                    </p>
                    <p v-if="t.description" class="mt-1 text-body leading-relaxed text-cream-300">{{ t.description }}</p>
                    <p v-if="t.recommended_action" class="mt-1 text-body leading-relaxed text-brand-200">Nên làm: {{ t.recommended_action }}</p>
                    <button type="button" class="tool-btn mt-2" data-use-trend title="Đưa hướng này vào ô mô tả tạo ảnh" @click="useTrend(t)">
                      <StudioIcon name="wand" size="h-3.5 w-3.5" /> Đưa vào mô tả ảnh
                    </button>
                  </li>
                </ul>
                <p v-else class="mt-2 text-body text-cream-300">Chưa có tín hiệu xu hướng nào để trả lời.</p>

                <div v-if="m.sources.length" class="mt-2 border-t border-ink-700/70 pt-2">
                  <p class="text-label font-semibold text-cream-400">Tin nguồn</p>
                  <ul class="mt-1 space-y-1">
                    <li v-for="(s, si) in m.sources" :key="si" class="text-body leading-snug">
                      <a v-if="s.url" :href="s.url" target="_blank" rel="noopener" class="text-brand-200 underline decoration-dotted hover:text-cream-50">{{ s.title || s.url }}</a>
                      <span v-else class="text-cream-200">{{ s.title }}</span>
                      <span v-if="s.source_name" class="text-cream-400"> — {{ s.source_name }}</span>
                    </li>
                  </ul>
                </div>

                <div v-if="m.mine.length" class="mt-2 border-t border-ink-700/70 pt-2">
                  <p class="text-label font-semibold text-cream-400">Trong kho thiết kế của bạn</p>
                  <ul class="mt-1 space-y-1">
                    <li v-for="(d, di) in m.mine" :key="di" class="text-body text-cream-200">{{ d.title || d.label || d.name || d.prompt || 'Thiết kế cũ' }}</li>
                  </ul>
                </div>

                <p v-if="!m.live" class="mt-2 text-label text-cream-400">Chưa có tin từ nguồn ngoài — phần trên là tín hiệu nội bộ của shop.</p>
                </template>
              </template>
            </div>
          </div>

          <p v-if="thinking" class="flex items-center gap-2 text-body text-cream-400">
            <StudioIcon name="refresh" size="h-3.5 w-3.5" class="animate-spin" /> Đang đọc tín hiệu…
          </p>
        </div>

        <form class="mt-3 flex items-end gap-2" @submit.prevent="ask()">
          <label for="canvas-chat-input" class="sr-only">Câu hỏi về xu hướng, chất liệu, màu sắc</label>
          <textarea id="canvas-chat-input" v-model="question" rows="2"
                    class="input max-h-[24vh] min-h-[2.75rem] w-full resize-none overflow-y-auto !rounded-xl !py-2.5 !text-sm"
                    placeholder="Hỏi: chất liệu nào đang lên? màu nào hợp bộ Thu Đông?"
                    @keydown.enter.exact.prevent="ask()"></textarea>
          <button type="submit" class="btn-brand btn-sm shrink-0" :disabled="!canAsk">
            <StudioIcon name="zap" size="h-3.5 w-3.5" /> Hỏi
          </button>
        </form>

        <p v-if="chatBlockReason" class="mt-2 text-label leading-4 text-warn">↳ {{ chatBlockReason }}</p>
        <p class="mt-2 text-label text-cream-400">Trả lời dựa trên tín hiệu thị trường &amp; tin nguồn đang nối — kèm liên kết để bạn tự kiểm. Lần đọc tín hiệu đầu tiên có thể mất tới ~12 giây; quá lâu thì mình trả lời bằng dữ liệu đã có sẵn.</p>
      </section>
    </div>
  </div>
</template>
