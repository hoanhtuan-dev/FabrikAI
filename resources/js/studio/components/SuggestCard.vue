<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const lang = ref(store.suggestLang === 'vi' ? 'vi' : 'en');
const tab = ref('prompt');        // prompt | video | notes | keywords
const advanced = ref(false);      // mở khối tuỳ chỉnh nâng cao
const copied = ref('');

const r = computed(() => store.suggestResult || {});
const palette = computed(() => (r.value.color_palette || []).filter(Boolean));
const keywords = computed(() => (r.value.keywords || []).filter(Boolean));
const hasResult = computed(() => !!(r.value.image_prompt_en || (r.value.styles || []).length));

// ── Chế độ nhanh: một cú bấm đặt đúng bộ tham số cho từng mục đích ──────────
const PRESETS = [
  { key: 'exact',    icon: '🎯', label: 'Bám gốc tối đa', hint: 'Tái tạo chính xác trang phục: màu, đường may, hoạ tiết', adherence: 10, detail: 10, skipLogo: true, skipHair: false, skipBg: false },
  { key: 'balanced', icon: '⚖️', label: 'Cân bằng',        hint: 'Giữ chất liệu & dáng, làm mới bối cảnh vừa phải',      adherence: 7,  detail: 8,  skipLogo: true, skipHair: true,  skipBg: false },
  { key: 'creative', icon: '✨', label: 'Sáng tạo',         hint: 'Lấy cảm hứng từ ảnh rồi dựng concept mới',             adherence: 3,  detail: 6,  skipLogo: true, skipHair: true,  skipBg: true },
  { key: 'shop',     icon: '🛍️', label: 'Sàn TMĐT',        hint: 'Nền sạch, tư thế chuẩn, tập trung chi tiết sản phẩm',   adherence: 9,  detail: 9,  skipLogo: true, skipHair: true,  skipBg: true },
  { key: 'lookbook', icon: '📸', label: 'Lookbook',        hint: 'Ánh sáng editorial, bối cảnh studio, giữ nguyên set đồ', adherence: 8,  detail: 8,  skipLogo: true, skipHair: false, skipBg: false },
];
const activePreset = computed(() => PRESETS.find((p) => p.adherence === store.suggestAdherence
  && p.detail === store.suggestDetailLevel
  && p.skipLogo === store.suggestSkipLogo
  && p.skipHair === store.suggestSkipHair
  && p.skipBg === store.suggestSkipBackground)?.key || '');
function applyPreset(p) {
  store.suggestAdherence = p.adherence;
  store.suggestDetailLevel = p.detail;
  store.suggestSkipLogo = p.skipLogo;
  store.suggestSkipHair = p.skipHair;
  store.suggestSkipBackground = p.skipBg;
  store.toast('Đã áp chế độ « ' + p.label + ' ».');
}

const adherenceLabel = computed(() => {
  const a = Number(r.value.adherence ?? 0);
  if (!a) return store.suggestAdherence ? adherenceText(Number(store.suggestAdherence)) : 'Tự động (theo sáng tạo)';
  return adherenceText(a);
});
function adherenceText(a) {
  if (a <= 3) return 'Thấp — sáng tạo lại';
  if (a <= 6) return 'Trung bình — tinh chỉnh tinh tế';
  return 'Cao — tái tạo chính xác gốc';
}

// ── Tiến trình THẬT khi AI suy luận ────────────────────────────────────────
// Giai đoạn lấy từ sự kiện NDJSON của /api/suggest/stream (store.suggestPhase),
// không phải đồng hồ đếm cho có: nhãn nói rõ AI nào đang đọc ảnh.
const STAGES = [
  { key: 'image',  icon: '🖼️', label: 'Ảnh nguồn' },
  { key: 'pick',   icon: '🧭', label: 'Chọn AI' },
  { key: 'think',  icon: '🧠', label: 'AI suy luận' },
  { key: 'build',  icon: '📝', label: 'Dựng prompt' },
];
const stageIndex = computed(() => {
  const p = store.suggestPhase;
  if (p === 'prepare') return 0;
  if (p === 'vision' || p === 'fallback' || p === 'color') return 2;
  if (p === 'done') return 4;
  return store.suggestProvider ? 1 : 0;
});
const elapsed = ref(0);
let raf = null;
function tick() {
  if (!store.suggesting) return;
  elapsed.value = Date.now() - (store.suggestStartedAt || Date.now());
  raf = requestAnimationFrame(tick);
}
watch(() => store.suggesting, (on) => {
  if (on) { elapsed.value = 0; cancelAnimationFrame(raf); raf = requestAnimationFrame(tick); }
  else { cancelAnimationFrame(raf); raf = null; }
});
onBeforeUnmount(() => cancelAnimationFrame(raf));
const elapsedText = computed(() => (elapsed.value / 1000).toFixed(1) + 's');
const lastDuration = computed(() => {
  const ms = store.suggestLastMeta?.elapsed_ms;
  return ms ? (ms / 1000).toFixed(1) + 's' : '';
});
const providerLabel = computed(() => {
  const p = store.suggestProvider;
  if (!p) return '';
  const names = { deepseek: 'DeepSeek', qwen: 'Qwen', qwen_edit: 'Qwen Edit', dashscope: 'DashScope', wan: 'Wan', gemini: 'Gemini', veo: 'Veo', fal: 'Fal.ai', replicate: 'Replicate', color: 'Phân tích màu (offline)' };
  return (names[p] || p) + (store.suggestModel ? ' · ' + store.suggestModel : '');
});
const phaseLabel = computed(() => store.suggestPhaseLabel || 'Đang xử lý…');
const stagePct = computed(() => Math.min(100, Math.round((stageIndex.value / STAGES.length) * 100)));

async function run() {
  if (!store.upscaleSrc) { store.toast('Chọn ảnh nguồn trước (bấm vào ảnh trên canvas hoặc Thư viện).', 'error'); return; }
  await store.suggestStyleStream(store.upscaleSrc);
}

async function copyPrompt() {
  const text = tab.value === 'video'
    ? (r.value.video_prompt_en || '')
    : (lang.value === 'vi' ? (r.value.prompt_vi || '') : (r.value.image_prompt_en || ''));
  if (!text) { store.toast('Chưa có nội dung để copy.', 'error'); return; }
  try {
    await navigator.clipboard.writeText(text);
    copied.value = tab.value;
    store.toast('📋 Đã copy prompt.');
    setTimeout(() => { copied.value = ''; }, 2000);
  } catch { store.toast('Trình duyệt chặn clipboard — chọn văn bản và copy tay.', 'error'); }
}

function loadRecent() { store.loadSuggestRecent(); }

/** Lưu một mục trong danh sách gần đây vào Thư viện Prompt (nạp vào card rồi lưu). */
async function saveFromRecent(item) {
  store.applyRecentSuggest(item);
  await store.saveSuggestResult();
  loadRecent();
}

onMounted(loadRecent);
// Lưu vào thư viện xong thì danh sách gần đây tự cập nhật (store đã gọi loadSuggestRecent).
watch(() => store.suggestRecentTotal, (n, o) => { if (n !== o && n > 0 && !store.suggestRecent.length) loadRecent(); });

// ── Luồng "Tạo ảnh ngay": bấm → mở popup xác nhận → user bấm Tạo Ảnh → tiến trình đẹp mắt ──
const genFlow = ref('idle');
const steps = [
  { key: 'suggest', icon: '💡', label: 'Đã gợi ý' },
  { key: 'confirm', icon: '✏️', label: 'Xác nhận' },
  { key: 'generate', icon: '🎨', label: 'Tạo ảnh' },
  { key: 'done', icon: '✅', label: 'Hoàn tất' },
];
const activeStep = computed(() => {
  if (genFlow.value === 'armed') return 1;
  if (genFlow.value === 'generating') return store.generateStage === 'done' ? 3 : 2;
  if (genFlow.value === 'done') return 3;
  return 0;
});
const flowPct = computed(() => {
  if (genFlow.value === 'idle') return 0;
  if (genFlow.value === 'armed') return 0;
  if (genFlow.value === 'generating') return store.generateProgress || 0;
  return 100;
});
const flowLabel = computed(() => {
  if (genFlow.value === 'armed') return 'Đang chờ xác nhận prompt ở popup…';
  if (genFlow.value === 'generating') {
    const s = store.generateStage;
    if (s === 'preparing') return 'Đang chuẩn bị…';
    if (s === 'enriching') return 'Đang làm giàu prompt…';
    if (s === 'rendering') return 'Đang tạo ảnh…';
    if (s === 'done') return 'Hoàn tất!';
    return 'Đang tạo ảnh…';
  }
  if (genFlow.value === 'done') return '✅ Đã tạo xong — xem kết quả ở Tạo Ảnh / Thư viện.';
  return '';
});

async function generateNow() {
  if (genFlow.value !== 'idle' && genFlow.value !== 'done') return;
  const p = lang.value === 'vi' ? store.suggestResult?.prompt_vi : store.suggestResult?.image_prompt_en;
  const prompt = p || store.imagePromptEn;
  if (!prompt) { store.toast('Chưa có prompt — bấm "Gợi ý phong cách & prompt" hoặc nhập ở Tạo Ảnh.', 'error'); return; }
  store.imagePromptEn = prompt;
  store.promptOpen = true;
  genFlow.value = 'armed';
}
watch(() => store.promptOpen, (open, wasOpen) => {
  if (!open && wasOpen && genFlow.value === 'armed' && !store.generating) genFlow.value = 'idle';
});
watch(() => store.generating, (g, old) => {
  if (g && !old && genFlow.value === 'armed') genFlow.value = 'generating';
  if (!g && old && genFlow.value === 'generating') {
    genFlow.value = store.generateStage === 'done' ? 'done' : 'idle';
    setTimeout(() => { if (genFlow.value === 'done') genFlow.value = 'idle'; }, 4000);
  }
});
</script>

<template>
  <div class="card suggest-card p-5">
    <!-- ── Đầu card: tiêu đề + AI đang/đã suy luận ── -->
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div>
        <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="lightbulb" /> Gợi ý từ ảnh</h2>
        <p class="mt-0.5 text-[11px] text-cream-300/70">AI đọc trang phục trong ảnh → phong cách, bảng màu, prompt ảnh + video tiếng Anh.</p>
      </div>
      <span v-if="providerLabel" class="suggest-chip" :title="'AI đang/đã suy luận cho lần gần nhất'">
        <span class="suggest-chip-dot" :class="store.suggesting ? 'is-live' : ''"></span>{{ providerLabel }}
      </span>
    </div>

    <div v-if="!store.suggestEnabled" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-2.5 text-xs text-red-100">
      Tính năng đang tắt — bật lại trong <b>Cài đặt Studio → Gợi ý từ ảnh</b>.
    </div>

    <!-- ── Ảnh nguồn ── -->
    <div class="mt-3 rounded-xl border border-white/10 bg-white/5 p-2.5">
      <div v-if="store.upscaleSrc" class="flex items-center gap-3">
        <img :src="store.upscaleSrc" alt="Ảnh nguồn để phân tích" class="h-16 w-16 rounded-lg bg-ink-900 object-cover ring-1 ring-white/10">
        <div class="min-w-0 flex-1">
          <p class="truncate text-xs font-semibold text-cream-100">{{ store.upscaleName || 'Ảnh đang chọn' }}</p>
          <p class="mt-0.5 text-[10px] text-cream-300/60">Đổi ảnh bằng cách chọn ảnh khác trên canvas / Thư viện.</p>
        </div>
        <button class="tool-btn motion-ui" title="Nạp lại danh sách gợi ý gần đây" @click="loadRecent">
          <StudioIcon name="refresh" size="h-3.5 w-3.5" /> Gần đây
        </button>
      </div>
      <p v-else class="py-2 text-center text-xs text-cream-300/60">Chưa có ảnh nguồn — chọn một ảnh trên canvas hoặc Thư viện.</p>
    </div>

    <!-- ── Chế độ nhanh ── -->
    <div class="mt-3">
      <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-cream-300/50">Chế độ nhanh</p>
      <div class="flex flex-wrap gap-1.5">
        <button v-for="p in PRESETS" :key="p.key" :title="p.hint"
                class="suggest-preset motion-ui" :class="activePreset === p.key ? 'is-active' : ''"
                @click="applyPreset(p)">
          <span aria-hidden="true">{{ p.icon }}</span> {{ p.label }}
        </button>
      </div>
    </div>

    <!-- ── Tuỳ chỉnh nâng cao (mặc định gọn) ── -->
    <button class="suggest-disclosure motion-ui mt-2.5" :aria-expanded="advanced ? 'true' : 'false'" @click="advanced = !advanced">
      <span class="suggest-caret" :class="advanced ? 'is-open' : ''">▸</span>
      Tuỳ chỉnh nâng cao · bám {{ store.suggestAdherence === 0 ? 'tự động' : store.suggestAdherence + '/10' }} · chi tiết {{ store.suggestDetailLevel }}/10
      <span v-if="store.suggestSkipLogo || store.suggestSkipHair || store.suggestSkipBackground" class="text-cream-300/50">
        · bỏ qua {{ [store.suggestSkipLogo ? 'logo' : '', store.suggestSkipHair ? 'tóc' : '', store.suggestSkipBackground ? 'bối cảnh' : ''].filter(Boolean).join(', ') }}
      </span>
    </button>

    <div v-show="advanced" class="mt-2 space-y-2.5 rounded-lg border border-white/10 bg-white/5 p-2.5 text-[11px]">
      <div>
        <div class="mb-1 flex items-center justify-between text-cream-200">
          <span class="flex items-center gap-1 font-semibold"><StudioIcon name="target" size="h-3.5 w-3.5" /> Bám trang phục gốc</span>
          <span class="text-cream-300/70">{{ store.suggestAdherence === 0 ? 'Tự động' : store.suggestAdherence + '/10' }}</span>
        </div>
        <input type="range" min="0" max="10" step="1" v-model.number="store.suggestAdherence" aria-label="Mức bám trang phục gốc" class="w-full accent-brand-500" title="0 = tự theo mức sáng tạo; cao = tái tạo chính xác trang phục gốc (màu/đường may/hoạ tiết)">
        <p class="mt-0.5 text-[10px] text-cream-300/55">{{ adherenceText(store.suggestAdherence || 6) }}</p>
      </div>
      <div>
        <div class="mb-1 flex items-center justify-between text-cream-200">
          <span class="flex items-center gap-1 font-semibold"><StudioIcon name="search" size="h-3.5 w-3.5" /> Mức chi tiết phân tích</span>
          <span class="text-cream-300/70">{{ store.suggestDetailLevel }}/10</span>
        </div>
        <input type="range" min="1" max="10" step="1" v-model.number="store.suggestDetailLevel" aria-label="Mức chi tiết phân tích" class="w-full accent-brand-500" title="Cao = vision liệt kê đầy đủ màu/đường may/hoạ tiết/độ dài/cổ/tay">
      </div>
      <div class="space-y-1.5">
        <p class="text-[10px] font-semibold text-cream-300/50">Bỏ qua phân tích</p>
        <label class="motion-ui flex cursor-pointer items-center gap-2 text-cream-200">
          <input type="checkbox" v-model="store.suggestSkipLogo" class="h-3.5 w-3.5 rounded accent-brand-500"> <span>Logo, chữ, watermark</span>
        </label>
        <label class="motion-ui flex cursor-pointer items-center gap-2 text-cream-200">
          <input type="checkbox" v-model="store.suggestSkipHair" class="h-3.5 w-3.5 rounded accent-brand-500"> <span>Kiểu tóc &amp; trang điểm</span>
        </label>
        <label class="motion-ui flex cursor-pointer items-center gap-2 text-cream-200">
          <input type="checkbox" v-model="store.suggestSkipBackground" class="h-3.5 w-3.5 rounded accent-brand-500"> <span>Bối cảnh &amp; ánh sáng nền</span>
        </label>
      </div>
    </div>

    <!-- ── Nút chạy ── -->
    <button class="btn-brand mt-3 w-full" :disabled="store.suggesting || !store.upscaleSrc"
            title="Phân tích ảnh và gợi ý phong cách, prompt"
            @click="run">
      <span v-if="!store.suggesting" class="flex items-center justify-center gap-1.5"><StudioIcon name="lightbulb" size="h-4 w-4" /> Gợi ý phong cách &amp; prompt</span>
      <span v-else class="flex items-center justify-center gap-1.5"><span class="suggest-spin"></span> Đang phân tích… {{ elapsedText }}</span>
    </button>

    <!-- ── Tiến trình AI suy luận (sự kiện THẬT từ server) ── -->
    <transition name="sugfade">
      <div v-if="store.suggesting || store.suggestError" class="suggest-progress mt-2" role="status" aria-live="polite">
        <div class="suggest-stages">
          <template v-for="(s, i) in STAGES" :key="s.key">
            <div class="suggest-stage" :class="{ active: i <= stageIndex, done: i < stageIndex, running: i === stageIndex && store.suggesting }">
              <span class="suggest-stage-dot">
                <span v-if="i < stageIndex">✓</span>
                <span v-else-if="i === stageIndex && store.suggesting" class="suggest-pulse"></span>
                <span v-else aria-hidden="true">{{ s.icon }}</span>
              </span>
              <span class="suggest-stage-label">{{ s.label }}</span>
            </div>
            <div v-if="i < STAGES.length - 1" class="suggest-connector" :class="{ filled: i < stageIndex }"></div>
          </template>
        </div>

        <div class="suggest-bar-wrap">
          <div class="suggest-bar" :class="{ 'is-running': store.suggesting }" :style="{ width: (store.suggesting ? Math.max(6, stagePct) : 100) + '%' }"></div>
        </div>

        <div v-if="store.suggesting" class="mt-1.5 flex flex-wrap items-center justify-between gap-1 text-[10px] text-cream-200/80">
          <span class="truncate">{{ phaseLabel }}</span>
          <span class="shrink-0 tabular-nums text-cream-300/70">{{ elapsedText }}</span>
        </div>

        <div v-if="store.suggestError" class="mt-1.5 rounded-md border border-red-500/40 bg-red-900/25 p-2 text-[11px] text-red-100">
          ⚠️ {{ store.suggestError }}
        </div>
      </div>
    </transition>

    <!-- ── Nút tạo ảnh ngay + tiến trình tạo ảnh ── -->
    <button class="btn-genflow motion-ui mt-1.5 w-full whitespace-nowrap" :disabled="genFlow !== 'idle' && genFlow !== 'done'"
            title="Đưa prompt vào popup Tạo Ảnh để xác nhận / chỉnh sửa, rồi bấm Tạo Ảnh — tiến trình hiện tại đây"
            @click="generateNow">
      <span v-if="genFlow === 'idle' || genFlow === 'done'" class="flex items-center justify-center gap-1.5"><span aria-hidden="true">🚀</span> Tạo ảnh ngay</span>
      <span v-else-if="genFlow === 'armed'" class="flex items-center justify-center gap-1.5"><span aria-hidden="true">⏳</span> Chờ xác nhận ở popup…</span>
      <span v-else class="flex items-center justify-center gap-1.5"><span class="genflow-spinner"></span> {{ flowLabel }}</span>
    </button>

    <transition name="genfade">
      <div v-if="genFlow !== 'idle'" class="genflow-panel mt-2">
        <div class="genflow-steps">
          <template v-for="(s, i) in steps" :key="s.key">
            <div class="genflow-step" :class="{ active: i <= activeStep, done: i < activeStep }">
              <span class="genflow-dot">
                <span v-if="i < activeStep" class="genflow-check">✓</span>
                <span v-else-if="i === activeStep && genFlow === 'generating'" class="genflow-pulse"></span>
                <span v-else aria-hidden="true">{{ s.icon }}</span>
              </span>
              <span class="genflow-step-label">{{ s.label }}</span>
            </div>
            <div v-if="i < steps.length - 1" class="genflow-connector" :class="{ filled: i < activeStep }"></div>
          </template>
        </div>
        <div class="genflow-bar-wrap">
          <div class="genflow-bar" :style="{ width: flowPct + '%' }"></div>
        </div>
        <p class="genflow-pct">{{ Math.round(flowPct) }}% · {{ flowLabel }}</p>
      </div>
    </transition>

    <!-- ── Kết quả ── -->
    <div v-if="hasResult" class="suggest-result mt-3">
      <div class="flex items-start justify-between gap-2">
        <div class="flex flex-wrap items-center gap-1.5 text-[10px]">
          <span class="rounded-full bg-emerald-600/30 px-2 py-0.5 font-semibold text-emerald-100">✅ Phân tích xong</span>
          <span v-if="providerLabel" class="suggest-chip">{{ providerLabel }}</span>
          <span v-if="lastDuration" class="suggest-chip">⏱ {{ lastDuration }}</span>
          <span v-if="adherenceLabel" class="rounded-full bg-brand-600/30 px-2 py-0.5 font-semibold text-brand-200">🎯 {{ adherenceLabel }}</span>
        </div>
        <button class="suggest-x motion-ui" title="Xoá gợi ý" @click="store.suggestResult = null; store.suggestError = ''; lang = 'en'">
          <StudioIcon name="x" size="h-3.5 w-3.5" />
        </button>
      </div>

      <div class="mt-2 flex flex-wrap gap-1 text-[10px]">
        <span v-if="r.garment_type" class="suggest-fact">👕 {{ r.garment_type }}</span>
        <span v-if="r.fabric" class="suggest-fact">🧵 {{ r.fabric }}</span>
        <span v-if="r.silhouette" class="suggest-fact">👗 {{ r.silhouette }}</span>
        <span v-if="r.camera" class="suggest-fact">🎥 {{ r.camera }}</span>
        <span v-if="r.pose" class="suggest-fact">🧍 {{ r.pose }}</span>
        <span v-if="r.background" class="suggest-fact">🏙️ {{ r.background }}</span>
        <span v-if="r.embellishment" class="suggest-fact">✨ {{ r.embellishment }}</span>
      </div>

      <div v-if="palette.length" class="mt-2 flex flex-wrap items-center gap-1">
        <span class="text-[10px] text-cream-300/60">Bảng màu:</span>
        <span v-for="c in palette" :key="c" class="suggest-swatch">{{ c }}</span>
      </div>

      <div class="suggest-tabs mt-2.5">
        <button class="suggest-tab motion-ui" :class="tab === 'prompt' ? 'is-active' : ''" @click="tab = 'prompt'">Prompt ảnh</button>
        <button v-if="r.video_prompt_en" class="suggest-tab motion-ui" :class="tab === 'video' ? 'is-active' : ''" @click="tab = 'video'">Prompt video</button>
        <button v-if="r.detail_notes" class="suggest-tab motion-ui" :class="tab === 'notes' ? 'is-active' : ''" @click="tab = 'notes'">Chi tiết gốc</button>
        <button v-if="keywords.length" class="suggest-tab motion-ui" :class="tab === 'keywords' ? 'is-active' : ''" @click="tab = 'keywords'">Từ khoá</button>
      </div>

      <div v-if="tab === 'prompt'" class="mt-2">
        <div class="mb-1 flex items-center justify-between gap-2">
          <div class="seg w-28 shrink-0">
            <button class="seg-btn motion-ui" title="Hiển thị tiếng Anh" :class="lang === 'en' ? 'is-active' : ''" @click="lang = 'en'">EN</button>
            <button class="seg-btn motion-ui" title="Hiển thị tiếng Việt" :class="lang === 'vi' ? 'is-active' : ''" @click="lang = 'vi'; if (!r.prompt_vi) store.translate(r.image_prompt_en)">VI</button>
          </div>
          <button class="tool-btn motion-ui" @click="copyPrompt"><StudioIcon name="copy" size="h-3.5 w-3.5" /> {{ copied === 'prompt' ? 'Đã copy' : 'Copy' }}</button>
        </div>
        <p class="suggest-text">{{ lang === 'vi' ? (r.prompt_vi || 'Đang dịch…') : r.image_prompt_en }}</p>
      </div>

      <div v-else-if="tab === 'video'" class="mt-2">
        <div class="mb-1 flex justify-end">
          <button class="tool-btn motion-ui" @click="copyPrompt"><StudioIcon name="copy" size="h-3.5 w-3.5" /> {{ copied === 'video' ? 'Đã copy' : 'Copy' }}</button>
        </div>
        <p class="suggest-text">{{ r.video_prompt_en }}</p>
      </div>

      <p v-else-if="tab === 'notes'" class="suggest-text mt-2">{{ r.detail_notes }}</p>

      <div v-else class="mt-2 flex flex-wrap gap-1">
        <span v-for="k in keywords" :key="k" class="suggest-keyword">#{{ k }}</span>
      </div>

      <div class="mt-2 grid grid-cols-2 gap-1.5">
        <button class="btn-ghost btn-sm motion-ui border border-emerald-500/30 text-emerald-200" :disabled="store.suggestSaving" @click="store.saveSuggestResult()">
          {{ store.suggestSaving ? 'Đang lưu…' : '💾 Lưu vào Thư viện' }}
        </button>
        <button class="btn-ghost btn-sm motion-ui border border-brand-500/30 text-brand-200" @click="generateNow">🚀 Tạo ảnh với prompt này</button>
      </div>
    </div>

    <!-- ── 10 gợi ý từ ảnh mới nhất ── -->
    <div class="mt-4 border-t border-white/10 pt-3">
      <div class="flex items-baseline justify-between gap-2">
        <h3 class="flex items-center gap-1.5 text-xs font-semibold text-cream-100"><StudioIcon name="clock" size="h-3.5 w-3.5" /> Gợi ý gần đây</h3>
        <button class="text-[10px] text-cream-300/70 motion-ui hover:text-cream-100" @click="loadRecent">Làm mới</button>
      </div>

      <p v-if="store.suggestRecentLoading && !store.suggestRecent.length" class="mt-2 text-[11px] text-cream-300/60">Đang tải…</p>
      <p v-else-if="!store.suggestRecent.length" class="mt-2 text-[11px] text-cream-300/60">
        Chưa có gợi ý nào được lưu. Bấm <b>Lưu vào Thư viện</b> sau khi phân tích để lần sau dùng lại ngay.
      </p>

      <ul v-else class="mt-2 space-y-1.5">
        <li v-for="item in store.suggestRecent" :key="item.id" class="suggest-recent motion-row flex items-center gap-2">
          <button class="flex min-w-0 flex-1 items-center gap-2 text-left motion-ui" :title="'Nạp lại gợi ý: ' + (item.garment_type || 'không tên')" @click="store.applyRecentSuggest(item)">
            <img v-if="item.reference_thumb || item.reference_url" :src="item.reference_thumb || item.reference_url" :alt="item.garment_type || 'Ảnh gợi ý'" class="suggest-recent-thumb">
            <span v-else class="suggest-recent-thumb grid place-items-center text-[10px] text-cream-300/50">ảnh</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-[11px] font-semibold text-cream-100">{{ item.garment_type || (item.styles || []).join(', ') || 'Gợi ý từ ảnh' }}</span>
              <span class="mt-0.5 block truncate text-[10px] text-cream-300/60">
                {{ item._local ? 'vừa phân tích (chưa lưu)' : (item.ago || '') }}<template v-if="item.apply_count"> · đã dùng {{ item.apply_count }} lần</template>
              </span>
            </span>
          </button>
          <button v-if="item._local" class="tool-btn motion-ui shrink-0" title="Lưu gợi ý này vào Thư viện Prompt" @click="saveFromRecent(item)">
            <StudioIcon name="save" size="h-3.5 w-3.5" />
          </button>
          <span v-else class="shrink-0 text-[10px] text-emerald-200/80">đã lưu</span>
        </li>
      </ul>

      <p v-if="store.suggestRecentTotal > store.suggestRecent.length" class="mt-1.5 text-[10px] text-cream-300/50">
        Còn {{ store.suggestRecentTotal - store.suggestRecent.length }} gợi ý nữa trong Thư viện Prompt.
      </p>
    </div>
  </div>
</template>

<style scoped>
/* ── Nền card ── */
.suggest-card { background: linear-gradient(160deg, rgba(80,150,150,.13), rgba(74,122,144,.06)); }

/* ── Chip nhỏ (AI đang chạy, thời gian, provider) ── */
.suggest-chip {
  display: inline-flex; align-items: center; gap: .3rem;
  border-radius: 999px; padding: .1rem .45rem;
  font-size: 10px; font-weight: 600;
  background: rgba(74,120,144,.28); color: rgba(245,241,232,.92);
  border: 1px solid rgba(255,255,255,.12);
}
.suggest-chip-dot { width: 6px; height: 6px; border-radius: 999px; background: #6ec9a8; }
.suggest-chip-dot.is-live { animation: suggest-blink 1s ease-in-out infinite; }
@keyframes suggest-blink { 0%, 100% { opacity: .35; } 50% { opacity: 1; } }

/* ── Chế độ nhanh ── */
.suggest-preset {
  display: inline-flex; align-items: center; gap: .25rem;
  border-radius: 999px; padding: .22rem .55rem;
  font-size: 10.5px; font-weight: 600;
  background: rgba(255,255,255,.06); color: rgba(245,241,232,.85);
  border: 1px solid rgba(255,255,255,.12);
}
.suggest-preset.is-active {
  background: rgba(91,157,110,.32); color: #d9f2e2; border-color: rgba(110,201,168,.6);
  box-shadow: 0 0 0 3px rgba(91,157,110,.14);
}

/* ── Nút mở/đóng tuỳ chỉnh ── */
.suggest-disclosure {
  display: flex; align-items: center; gap: .35rem; width: 100%;
  border-radius: .5rem; padding: .35rem .5rem; text-align: left;
  font-size: 10.5px; color: rgba(245,241,232,.75);
  background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
}
.suggest-caret { display: inline-block; transition: transform var(--motion-dur-base) var(--motion-ease-standard); }
.suggest-caret.is-open { transform: rotate(90deg); }

/* ── Tiến trình AI suy luận ── */
.suggest-progress {
  border: 1px solid rgba(74,120,144,.4);
  background: linear-gradient(160deg, rgba(74,120,144,.16), rgba(91,157,110,.07));
  border-radius: .6rem; padding: .7rem .6rem .55rem;
}
.suggest-stages { display: flex; align-items: center; }
.suggest-stage {
  display: flex; flex-direction: column; align-items: center; gap: .2rem; flex: 0 0 auto;
  opacity: .42;
  transition: opacity var(--motion-dur-slow) var(--motion-ease-standard),
              transform var(--motion-dur-slow) var(--motion-ease-emphasized);
}
.suggest-stage.active { opacity: 1; transform: scale(1.05); }
.suggest-stage.done { opacity: .85; }
.suggest-stage-dot {
  display: grid; place-items: center; width: 26px; height: 26px; border-radius: 50%;
  font-size: 12px; line-height: 1; background: rgba(255,255,255,.08); border: 1.5px solid rgba(255,255,255,.2);
}
.suggest-stage.active .suggest-stage-dot { background: linear-gradient(135deg, #4a7890, #5b9d6e); border-color: #6ec9a8; box-shadow: 0 0 0 4px rgba(74,120,144,.18); }
.suggest-stage.done .suggest-stage-dot { background: #4a7890; border-color: #4a7890; color: #fff; font-weight: 700; }
.suggest-stage-label { font-size: 9px; color: rgba(245,241,232,.7); white-space: nowrap; }
.suggest-stage.active .suggest-stage-label { color: #cfe9f5; font-weight: 600; }
.suggest-pulse { display: block; width: 8px; height: 8px; border-radius: 50%; background: #fff; animation: genflow-pulse 1s ease-in-out infinite; }
.suggest-connector { flex: 1 1 auto; height: 2px; margin: 0 4px 14px; background: rgba(255,255,255,.12); border-radius: 2px; overflow: hidden; position: relative; }
.suggest-connector.filled::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, #4a7890, #6ec9a8); animation: genflow-fill .5s ease forwards; }
.suggest-bar-wrap { margin-top: .5rem; height: 6px; border-radius: 3px; background: rgba(255,255,255,.1); overflow: hidden; }
.suggest-bar {
  height: 100%; border-radius: 3px;
  background: linear-gradient(90deg, #4a7890, #6ec9a8, #4a7890);
  background-size: 200% 100%;
  transition: width var(--motion-dur-slow) var(--motion-ease-standard);
}
.suggest-bar.is-running { animation: genflow-shimmer 1.6s linear infinite; }
.suggest-spin {
  display: inline-block; width: 14px; height: 14px; border-radius: 50%;
  border: 2px solid rgba(255,255,255,.35); border-top-color: #fff;
  animation: genflow-spin .7s linear infinite;
}

/* ── Kết quả ── */
.suggest-result { border: 1px solid rgba(110,201,168,.4); background: rgba(20,60,50,.35); border-radius: .6rem; padding: .7rem; }
.suggest-fact { border-radius: 999px; padding: .1rem .45rem; background: rgba(255,255,255,.08); color: rgba(245,241,232,.9); }
.suggest-swatch { border-radius: 999px; padding: .1rem .5rem; background: rgba(74,120,144,.3); color: #e8f4f8; border: 1px solid rgba(255,255,255,.1); }
.suggest-keyword { border-radius: 999px; padding: .1rem .45rem; background: rgba(91,157,110,.28); color: #d9f2e2; }
.suggest-tabs { display: flex; flex-wrap: wrap; gap: .25rem; }
.suggest-tab {
  border-radius: .4rem; padding: .2rem .5rem; font-size: 10px; font-weight: 600;
  background: rgba(255,255,255,.05); color: rgba(245,241,232,.7); border: 1px solid rgba(255,255,255,.08);
}
.suggest-tab.is-active { background: rgba(74,120,144,.35); color: #fff; border-color: rgba(110,201,168,.45); }
.suggest-text {
  max-height: 11rem; overflow-y: auto; border-radius: .45rem; padding: .5rem;
  font-size: 11.5px; line-height: 1.55; color: rgba(245,241,232,.95);
  background: rgba(0,0,0,.22); border: 1px solid rgba(255,255,255,.08);
}
.suggest-x { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 999px; background: rgba(255,255,255,.08); color: rgba(245,241,232,.8); }
.suggest-x:hover { background: rgba(220,38,38,.75); color: #fff; }

/* ── Danh sách gần đây ── */
.suggest-recent {
  display: flex; align-items: center; gap: .5rem; width: 100%;
  border-radius: .5rem; padding: .35rem .45rem;
  background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
}
.suggest-recent:hover { background: rgba(74,120,144,.22); border-color: rgba(110,201,168,.35); }
.suggest-recent-thumb { width: 34px; height: 34px; border-radius: .4rem; object-fit: cover; background: rgba(0,0,0,.3); flex: 0 0 auto; }

/* ── Nút Tạo ảnh ngay ── */
.btn-genflow {
  position: relative; overflow: hidden; padding: 0.55rem 0.75rem; border-radius: 0.5rem;
  font-size: 0.8rem; font-weight: 600; color: #fff;
  background: linear-gradient(120deg, #4a7890, #5b9d6e, #4a7890);
  background-size: 200% 100%; animation: genflow-gradient 3s ease infinite;
  box-shadow: 0 4px 14px -4px rgba(91, 157, 110, 0.5);
}
.btn-genflow:disabled { opacity: 0.85; cursor: progress; background: linear-gradient(120deg, #3a5d70, #4a7d5a, #3a5d70); }
@keyframes genflow-gradient { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
.genflow-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.35); border-top-color: #fff; border-radius: 50%; animation: genflow-spin 0.7s linear infinite; }
@keyframes genflow-spin { to { transform: rotate(360deg); } }

/* ── Panel tiến trình TẠO ẢNH (giữ nguyên hành vi cũ) ── */
.genflow-panel { border: 1px solid rgba(91, 157, 110, 0.35); background: linear-gradient(160deg, rgba(91,157,110,.12), rgba(74,122,144,.06)); border-radius: 0.6rem; padding: 0.7rem 0.6rem 0.55rem; }
.genflow-steps { display: flex; align-items: center; }
.genflow-step { display: flex; flex-direction: column; align-items: center; gap: 0.2rem; flex: 0 0 auto; opacity: 0.45; transition: opacity var(--motion-dur-slow) var(--motion-ease-standard), transform var(--motion-dur-slow) var(--motion-ease-emphasized); }
.genflow-step.active { opacity: 1; transform: scale(1.06); }
.genflow-step.done { opacity: 0.85; }
.genflow-dot { display: grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; font-size: 12px; line-height: 1; background: rgba(255,255,255,0.08); border: 1.5px solid rgba(255,255,255,0.2); }
.genflow-step.active .genflow-dot { background: linear-gradient(135deg, #5b9d6e, #4a7890); border-color: #5b9d6e; box-shadow: 0 0 0 4px rgba(91,157,110,0.18); }
.genflow-step.done .genflow-dot { background: #5b9d6e; border-color: #5b9d6e; }
.genflow-check { color: #fff; font-size: 12px; font-weight: 700; }
.genflow-step-label { font-size: 9px; color: rgba(245,241,232,0.7); white-space: nowrap; }
.genflow-step.active .genflow-step-label { color: #bfe8c8; font-weight: 600; }
.genflow-pulse { display: block; width: 8px; height: 8px; border-radius: 50%; background: #fff; animation: genflow-pulse 1s ease-in-out infinite; }
@keyframes genflow-pulse { 0%, 100% { transform: scale(0.6); opacity: 0.6; } 50% { transform: scale(1.2); opacity: 1; } }
.genflow-connector { flex: 1 1 auto; height: 2px; margin: 0 4px 14px; background: rgba(255,255,255,0.12); border-radius: 2px; position: relative; overflow: hidden; }
.genflow-connector.filled::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, #5b9d6e, #4a7890); animation: genflow-fill 0.5s ease forwards; }
@keyframes genflow-fill { from { transform: scaleX(0); transform-origin: left; } to { transform: scaleX(1); } }
.genflow-bar-wrap { margin-top: 0.5rem; height: 6px; border-radius: 3px; background: rgba(255,255,255,0.1); overflow: hidden; }
.genflow-bar { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #5b9d6e, #6ec9a8, #4a7890); background-size: 200% 100%; animation: genflow-shimmer 1.8s linear infinite; transition: width var(--motion-dur-slow) var(--motion-ease-standard); box-shadow: 0 0 8px rgba(110,201,168,0.5); }
@keyframes genflow-shimmer { 0% { background-position: 0% 0; } 100% { background-position: 200% 0; } }
.genflow-pct { margin-top: 0.35rem; font-size: 10px; color: rgba(245,241,232,0.6); text-align: center; }

/* ── Transition ── */
.genfade-enter-active, .genfade-leave-active, .sugfade-enter-active, .sugfade-leave-active {
  transition: opacity var(--motion-dur-base) var(--motion-ease-standard),
              transform var(--motion-dur-base) var(--motion-ease-emphasized);
}
.genfade-enter-from, .genfade-leave-to, .sugfade-enter-from, .sugfade-leave-to { opacity: 0; transform: translateY(-6px); }
</style>
