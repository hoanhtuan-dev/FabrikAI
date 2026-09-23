<script setup>
/**
 * GỢI Ý TỪ ẢNH — thiết kế lại theo phong cách chung của Studio (xem docs/DESIGN_SYSTEM.md).
 *
 *  ① Ảnh nguồn   (bắt buộc — lấy từ canvas/Thư viện)
 *  ② Kiểu gợi ý  (tùy chọn — 5 chế độ nhanh, mặc định không chọn gì)
 *  ▸ Nâng cao    (gấp sẵn: độ bám · mức chi tiết · bỏ qua phần không cần phân tích)
 *
 * Nguyên tắc trình bày (giống card "Studio" và "Ghép trang phục"):
 *  · MỘT hành động chính tại một thời điểm. Chưa có kết quả ⇒ nút "Phân tích ảnh";
 *    có kết quả rồi ⇒ việc tiếp theo là "Tạo ảnh với prompt này" (nút chính nằm trong khối kết quả).
 *    Trước đây hai nút to ngang nhau cùng hiện ⇒ người mới không biết bấm cái nào trước.
 *  · Nút bị khoá phải NÓI RÕ LÝ DO ngay dưới (blockReason), không để người dùng đoán.
 *  · Tiến trình dùng chung <LoadingSpinner> — không tự vẽ bộ chấm/thanh riêng cho từng luồng.
 *  · Không dùng emoji trong chrome; icon lấy từ icons.json qua <StudioIcon>.
 *  · Màu/kích thước lấy từ token + tiện ích Tailwind, không khai bảng màu riêng ở khối style.
 *
 * Toàn bộ hành vi cũ giữ nguyên: luồng stream NDJSON (/api/suggest/stream), dịch VI, lưu vào
 * Thư viện Prompt, danh sách gợi ý gần đây và luồng "Tạo ảnh ngay" qua popup Tạo Ảnh.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import { thumbUrl } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';
import SourceLibraryPicker from './SourceLibraryPicker.vue';
import LoadingSpinner from './LoadingSpinner.vue';

const store = useStudioStore();
const lang = ref(store.suggestLang === 'vi' ? 'vi' : 'en');
const tab = ref('prompt');        // prompt | video | notes | keywords
const copied = ref('');

const r = computed(() => store.suggestResult || {});
const palette = computed(() => (r.value.color_palette || []).filter(Boolean));
const keywords = computed(() => (r.value.keywords || []).filter(Boolean));
const hasResult = computed(() => !!(r.value.image_prompt_en || (r.value.styles || []).length));

// ── Chế độ nhanh: một cú bấm đặt đúng bộ tham số cho từng mục đích ──────────
// Nhãn viết cho NGƯỜI MỚI đọc là hiểu (không dùng "độ bám", "sàn TMĐT"); phần giải thích nằm ở title.
const PRESETS = [
  { key: 'exact',    icon: 'target',   label: 'Giống ảnh gốc', hint: 'Tái tạo chính xác trang phục: màu, đường may, hoạ tiết', adherence: 10, detail: 10, skipLogo: true,  skipHair: false, skipBg: false },
  { key: 'balanced', icon: 'sliders',  label: 'Cân bằng',      hint: 'Giữ chất liệu & dáng, làm mới bối cảnh vừa phải',        adherence: 7,  detail: 8,  skipLogo: true,  skipHair: true,  skipBg: false },
  { key: 'creative', icon: 'sparkles', label: 'Sáng tạo',      hint: 'Lấy cảm hứng từ ảnh rồi dựng concept mới',               adherence: 3,  detail: 6,  skipLogo: true,  skipHair: true,  skipBg: true },
  { key: 'shop',     icon: 'tag',      label: 'Ảnh bán hàng',  hint: 'Nền sạch, tư thế chuẩn, tập trung chi tiết sản phẩm',   adherence: 9,  detail: 9,  skipLogo: true,  skipHair: true,  skipBg: true },
  { key: 'lookbook', icon: 'camera',   label: 'Lookbook',      hint: 'Ánh sáng editorial, bối cảnh studio, giữ nguyên set đồ', adherence: 8,  detail: 8,  skipLogo: true,  skipHair: false, skipBg: false },
];
const activePreset = computed(() => PRESETS.find((p) => p.adherence === store.suggestAdherence
  && p.detail === store.suggestDetailLevel
  && p.skipLogo === store.suggestSkipLogo
  && p.skipHair === store.suggestSkipHair
  && p.skipBg === store.suggestSkipBackground) || null);
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
  if (!a) return store.suggestAdherence ? adherenceText(Number(store.suggestAdherence)) : 'Tự động';
  return adherenceText(a);
});
function adherenceText(a) {
  if (a <= 3) return 'Thấp — sáng tạo lại';
  if (a <= 6) return 'Trung bình — tinh chỉnh tinh tế';
  return 'Cao — tái tạo chính xác gốc';
}

// ── Tiến trình THẬT khi AI suy luận ────────────────────────────────────────
// Giai đoạn lấy từ sự kiện NDJSON của /api/suggest/stream (store.suggestPhase), không phải đồng hồ
// đếm cho có: nhãn nói rõ AI nào đang đọc ảnh. Hiển thị qua <LoadingSpinner> dùng chung.
const STAGES = ['Ảnh nguồn', 'Chọn AI', 'AI suy luận', 'Dựng prompt'];
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
// ── KHÔNG hiển thị AI nào / model nào (docs/DESIGN_SYSTEM.md §6) ──────────────────────────
// Trước đây card có chip "DeepSeek · deepseek-chat" và nhét tên đó vào cả dòng tiến trình.
// Người dùng không chọn được model ở đây, biết tên cũng không làm được gì — mà lại để lộ hạ tầng
// phía sau. Tên provider/model vẫn nằm trong state (store.suggestProvider/suggestModel) và trong
// log để lập trình viên chẩn đoán; giao diện chỉ nói ĐANG LÀM GÌ.
const phaseLabel = computed(() => store.suggestPhaseLabel || 'AI đang đọc ảnh…');
const stagePct = computed(() => Math.min(100, Math.round((stageIndex.value / STAGES.length) * 100)));
/** Dòng phụ của tiến trình: đang ở bước mấy · đã bao lâu (KHÔNG nêu AI nào). */
const progressSubtext = computed(() => {
  const i = Math.min(stageIndex.value, STAGES.length - 1);
  return ['bước ' + (i + 1) + '/' + STAGES.length + ' · ' + STAGES[i], elapsedText.value]
    .filter(Boolean).join(' · ');
});

// ── Nút chạy: vì sao bị khoá (nói thẳng, đừng để người dùng đoán) ──────────
/** Mở/đóng trình chọn ảnh nguồn NGAY TRONG CARD (không phải đi sang màn hình khác). */
const pickerOpen = ref(false);

/**
 * Nhận ảnh người dùng vừa chọn. Ghi vào CÙNG ô nguồn mà cả Studio đang dùng (store.upscaleSrc)
 * nên không sinh nguồn sự thật thứ hai: card nào cũng thấy ảnh đang làm việc là ảnh này.
 */
function onPickSource(item) {
  const url = item && (item.media_url || item.url || item.src || '');
  if (!url) { store.toast('Ảnh này chưa có đường dẫn dùng được — chọn ảnh khác.', 'error'); return; }
  store.upscaleSrc = url;
  if (item.id) store.select(item);
  pickerOpen.value = false;
  store.toast('Đã chọn ảnh nguồn cho bước phân tích.');
}

const canRun = computed(() => !store.suggesting && !!store.upscaleSrc && store.suggestEnabled);
const blockReason = computed(() => {
  if (store.suggesting) return '';
  if (!store.suggestEnabled) return 'Tính năng đang bị tắt trong Cài đặt Studio.';
  if (!store.upscaleSrc) return 'Chưa có ảnh nguồn — chọn một ảnh trên canvas hoặc trong Thư viện ảnh.';
  return '';
});

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
    store.toast('Đã copy prompt.');
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

// ── Luồng "Tạo ảnh": bấm → mở popup xác nhận → tiến trình hiện ngay tại đây ──
const genFlow = ref('idle');
const flowPct = computed(() => {
  if (genFlow.value === 'armed') return 0;
  if (genFlow.value === 'generating') return store.generateProgress || 0;
  if (genFlow.value === 'done') return 100;
  return 0;
});
const flowLabel = computed(() => {
  if (genFlow.value === 'armed') return 'Đang chờ xác nhận prompt ở popup Tạo Ảnh…';
  if (genFlow.value === 'generating') {
    const s = store.generateStage;
    if (s === 'preparing') return 'Đang chuẩn bị…';
    if (s === 'enriching') return 'Đang làm giàu prompt…';
    if (s === 'rendering') return 'AI đang tạo ảnh…';
    if (s === 'done') return 'Hoàn tất!';
    return 'AI đang tạo ảnh…';
  }
  if (genFlow.value === 'done') return 'Đã tạo xong — xem kết quả ở Outputs và bảng Lớp.';
  return '';
});

async function generateNow() {
  if (genFlow.value !== 'idle' && genFlow.value !== 'done') return;
  const p = lang.value === 'vi' ? store.suggestResult?.prompt_vi : store.suggestResult?.image_prompt_en;
  const prompt = p || store.imagePromptEn;
  if (!prompt) { store.toast('Chưa có prompt — bấm "Phân tích ảnh" trước, hoặc nhập ở card Tạo Ảnh.', 'error'); return; }
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

/** Nhãn bước ① khi đã có ảnh nguồn. */
const sourceName = computed(() => store.upscaleName || 'Ảnh đang chọn');
</script>

<template>
  <div class="card p-4">
    <!-- ── Đầu card: làm gì, nói một câu cho người mới ── -->
    <div class="min-w-0">
      <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="lightbulb" /> Gợi ý từ ảnh</h2>
      <p class="mt-0.5 text-label leading-4 text-cream-400">Đọc ảnh bạn đang có → gợi ý <span class="text-cream-200">phong cách · bảng màu · prompt</span> để tạo ảnh mới.</p>
    </div>

    <p v-if="!store.suggestEnabled" role="status" class="mt-2 rounded-md border border-danger/40 bg-danger/10 px-2.5 py-1.5 text-label leading-4 text-danger">
      Tính năng đang tắt — bật lại trong <b>Cài đặt Studio → Gợi ý từ ảnh</b>.
    </p>

    <!-- ① ẢNH NGUỒN (bắt buộc) -->
    <div class="mt-3">
      <p class="flex items-center gap-1.5 text-body font-semibold text-cream-100">
        <span class="grid h-4 w-4 place-items-center rounded-full bg-brand-600 text-tiny font-bold text-primary-content">1</span>
        Ảnh nguồn <span class="rounded bg-ink-800 px-1.5 py-0.5 text-tiny font-medium text-brand-200">bắt buộc</span>
      </p>

      <!-- Chưa có ảnh nguồn ⇒ nút chọn NGAY TẠI ĐÂY (trước đây chỉ có câu hướng dẫn suông). -->
      <button v-if="!store.upscaleSrc" type="button" @click="pickerOpen = true"
              class="motion-ui mt-1.5 flex w-full items-center justify-center gap-2 rounded-lg border border-ink-600 bg-ink-800 px-3 py-2 text-label font-semibold text-cream-200 hover:border-brand-400">
        <StudioIcon name="imagePlus" size="h-3.5 w-3.5" /> Chọn ảnh nguồn từ Thư viện
      </button>

      <div v-if="store.upscaleSrc" class="mt-1.5 flex items-center gap-3 rounded-lg border border-ink-700 bg-ink-900/50 p-2">
        <img :src="thumbUrl(store.upscaleSrc)" alt="Ảnh nguồn để phân tích" class="h-14 w-14 shrink-0 rounded-lg bg-ink-900 object-cover ring-1 ring-ink-600">
        <div class="min-w-0 flex-1">
          <p class="truncate text-body font-semibold text-cream-100">{{ sourceName }}</p>
          <p class="mt-0.5 text-label leading-4 text-cream-400">Muốn đổi ảnh: chọn ảnh khác trên canvas hoặc trong Thư viện ảnh.</p>
        </div>
      </div>

      <p v-else class="mt-1.5 flex flex-col items-center gap-1 rounded-lg border border-dashed border-ink-600 bg-ink-900/50 px-2.5 py-3 text-center">
        <StudioIcon name="image" size="h-5 w-5" class="text-cream-400" />
        <span class="text-body font-semibold text-cream-200">Chưa có ảnh nguồn</span>
        <span class="text-label leading-4 text-cream-400">Bấm một ảnh trên canvas, hoặc mở Thư viện ảnh rồi chọn ảnh cần phân tích.</span>
      </p>
    </div>

    <!-- ② KIỂU GỢI Ý (tùy chọn) -->
    <div class="mt-4">
      <p class="flex items-center gap-1.5 text-body font-semibold text-cream-100">
        <span class="grid h-4 w-4 place-items-center rounded-full bg-ink-700 text-tiny font-bold text-cream-100">2</span>
        Kiểu gợi ý <span class="rounded bg-ink-800 px-1.5 py-0.5 text-tiny font-medium text-cream-400">tùy chọn</span>
      </p>
      <p class="mt-1 text-label leading-4 text-cream-400">
        Không chọn cũng được — AI tự cân bằng.
        <span v-if="activePreset" class="text-brand-200">Đang dùng: {{ activePreset.label }}.</span>
      </p>
      <div class="mt-1.5 flex flex-wrap gap-1.5">
        <button v-for="p in PRESETS" :key="p.key" type="button" :title="p.hint"
                class="motion-ui inline-flex items-center gap-1 rounded-full border px-2 py-1 text-label font-medium transition"
                :class="activePreset && activePreset.key === p.key ? 'border-brand-500 bg-brand-600 text-primary-content' : 'border-ink-600 bg-ink-800 text-cream-200 hover:border-brand-400'"
                @click="applyPreset(p)">
          <StudioIcon :name="p.icon" size="h-3 w-3" /> {{ p.label }}
        </button>
      </div>
    </div>

    <!-- NÂNG CAO (mặc định gấp) -->
    <details class="mt-3 rounded-lg border border-ink-700 bg-ink-900/50 p-2.5">
      <summary class="cursor-pointer text-label font-semibold text-cream-300">
        Nâng cao: bám trang phục gốc · mức chi tiết · phần bỏ qua
        <span v-if="activePreset" class="font-normal text-cream-400">(theo « {{ activePreset.label }} »)</span>
      </summary>

      <div class="mt-2.5 space-y-2.5 text-body">
        <div>
          <div class="mb-1 flex items-center justify-between text-cream-200">
            <span class="flex items-center gap-1 font-semibold"><StudioIcon name="target" size="h-3.5 w-3.5" /> Bám trang phục gốc</span>
            <span class="text-cream-300">{{ store.suggestAdherence === 0 ? 'Tự động' : store.suggestAdherence + '/10' }}</span>
          </div>
          <input type="range" min="0" max="10" step="1" v-model.number="store.suggestAdherence" aria-label="Mức bám trang phục gốc" class="w-full accent-brand-500" title="0 = tự theo mức sáng tạo; cao = tái tạo chính xác trang phục gốc (màu/đường may/hoạ tiết)">
          <p class="mt-0.5 text-label text-cream-400">
            {{ store.suggestAdherence === 0 ? 'AI tự chọn mức bám phù hợp với ảnh này.' : adherenceText(store.suggestAdherence) }}
          </p>
        </div>
        <div>
          <div class="mb-1 flex items-center justify-between text-cream-200">
            <span class="flex items-center gap-1 font-semibold"><StudioIcon name="search" size="h-3.5 w-3.5" /> Mức chi tiết phân tích</span>
            <span class="text-cream-300">{{ store.suggestDetailLevel }}/10</span>
          </div>
          <input type="range" min="1" max="10" step="1" v-model.number="store.suggestDetailLevel" aria-label="Mức chi tiết phân tích" class="w-full accent-brand-500" title="Cao = AI liệt kê đầy đủ màu/đường may/hoạ tiết/độ dài/cổ/tay">
        </div>
        <div class="space-y-1.5">
          <p class="text-label font-semibold text-cream-400">Không cần phân tích</p>
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
    </details>

    <!-- ── Nút chạy: MỘT hành động chính, khoá thì nói rõ lý do ── -->
    <!-- Đã có kết quả rồi thì việc chính là TẠO ẢNH (nút trong khối kết quả); nút này lùi về thứ yếu
         để trên card chỉ còn ĐÚNG MỘT nút chính — trước đây hai nút to ngang nhau gây bối rối. -->
    <button class="motion-ui mt-3 w-full whitespace-nowrap" :class="hasResult ? 'btn-ghost btn-sm border border-ink-600 text-body text-cream-200' : 'btn-brand'" :disabled="!canRun" @click="run">
      <span v-if="store.suggesting" class="flex items-center justify-center gap-1.5"><span class="suggest-spin"></span> Đang phân tích…</span>
      <span v-else class="flex items-center justify-center gap-1.5"><StudioIcon name="lightbulb" size="h-3.5 w-3.5" /> {{ hasResult ? 'Phân tích lại ảnh này' : 'Phân tích ảnh' }}</span>
    </button>
    <p v-if="blockReason" class="mt-1.5 text-label leading-4 text-warn">↳ {{ blockReason }}</p>

    <!-- ── Tiến trình phân tích (sự kiện THẬT từ server) ── -->
    <div v-if="store.suggesting" role="status" aria-live="polite"
         class="mt-2.5 rounded-lg border border-brand-500/30 bg-brand-900/30 p-2">
      <LoadingSpinner size="sm" :text="phaseLabel" :subtext="progressSubtext" :progress="stagePct" />
    </div>

    <div v-if="store.suggestError" role="alert" class="mt-2.5 rounded-lg border border-danger/40 bg-danger/10 p-2.5 text-body text-danger">
      {{ store.suggestError }}
    </div>

    <!-- ── KẾT QUẢ ── -->
    <div v-if="hasResult" class="mt-3 rounded-lg border border-ok/40 bg-ok/20 p-2.5">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <p class="flex items-center gap-1.5 text-body font-semibold text-ok">
            <StudioIcon name="check" size="h-3.5 w-3.5" /> Đã phân tích xong
          </p>
          <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-tiny text-cream-400">
            <span v-if="lastDuration">Xong trong {{ lastDuration }}</span>
            <span>· mức bám: {{ adherenceLabel }}</span>
          </p>
        </div>
        <button class="motion-ui grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ink-800 text-cream-200 transition hover:bg-danger hover:text-cream-50"
                title="Xoá gợi ý này" aria-label="Xoá gợi ý này"
                @click="store.suggestResult = null; store.suggestError = ''; lang = 'en'">
          <StudioIcon name="x" size="h-3.5 w-3.5" />
        </button>
      </div>

      <!-- Đặc điểm AI đọc được từ ảnh -->
      <div class="mt-2 flex flex-wrap gap-1">
        <span v-if="r.garment_type" class="inline-flex items-center gap-1 rounded-full bg-cream-50/8 px-2 py-0.5 text-label text-cream-100"><StudioIcon name="shirt" size="h-3 w-3" class="text-brand-300" /> {{ r.garment_type }}</span>
        <span v-if="r.fabric" class="inline-flex items-center gap-1 rounded-full bg-cream-50/8 px-2 py-0.5 text-label text-cream-100"><StudioIcon name="feather" size="h-3 w-3" class="text-brand-300" /> {{ r.fabric }}</span>
        <span v-if="r.silhouette" class="inline-flex items-center gap-1 rounded-full bg-cream-50/8 px-2 py-0.5 text-label text-cream-100"><StudioIcon name="body" size="h-3 w-3" class="text-brand-300" /> {{ r.silhouette }}</span>
        <span v-if="r.camera" class="inline-flex items-center gap-1 rounded-full bg-cream-50/8 px-2 py-0.5 text-label text-cream-100"><StudioIcon name="camera" size="h-3 w-3" class="text-brand-300" /> {{ r.camera }}</span>
        <span v-if="r.pose" class="inline-flex items-center gap-1 rounded-full bg-cream-50/8 px-2 py-0.5 text-label text-cream-100"><StudioIcon name="pose" size="h-3 w-3" class="text-brand-300" /> {{ r.pose }}</span>
        <span v-if="r.background" class="inline-flex items-center gap-1 rounded-full bg-cream-50/8 px-2 py-0.5 text-label text-cream-100"><StudioIcon name="background" size="h-3 w-3" class="text-brand-300" /> {{ r.background }}</span>
        <span v-if="r.embellishment" class="inline-flex items-center gap-1 rounded-full bg-cream-50/8 px-2 py-0.5 text-label text-cream-100"><StudioIcon name="sparkles" size="h-3 w-3" class="text-brand-300" /> {{ r.embellishment }}</span>
      </div>

      <!-- Bảng màu -->
      <div v-if="palette.length" class="mt-2 flex flex-wrap items-center gap-1">
        <span class="text-label text-cream-400">Bảng màu:</span>
        <span v-for="c in palette" :key="c" class="rounded-full border border-ink-600 bg-ink-800 px-2 py-0.5 text-label text-cream-200">{{ c }}</span>
      </div>

      <!-- Nội dung: prompt ảnh · prompt video · chi tiết gốc · từ khoá -->
      <div class="seg mt-2.5 flex-wrap">
        <button class="seg-btn motion-ui !py-1 !text-label" :class="tab === 'prompt' ? 'is-active' : ''" @click="tab = 'prompt'">Prompt</button>
        <button v-if="r.video_prompt_en" class="seg-btn motion-ui !py-1 !text-label" :class="tab === 'video' ? 'is-active' : ''" @click="tab = 'video'">Video</button>
        <button v-if="r.detail_notes" class="seg-btn motion-ui !py-1 !text-label" :class="tab === 'notes' ? 'is-active' : ''" @click="tab = 'notes'">Chi tiết</button>
        <button v-if="keywords.length" class="seg-btn motion-ui !py-1 !text-label" :class="tab === 'keywords' ? 'is-active' : ''" @click="tab = 'keywords'">Từ khoá</button>
      </div>

      <div v-if="tab === 'prompt'" class="mt-2">
        <div class="mb-1 flex items-center justify-between gap-2">
          <div class="seg w-28 shrink-0">
            <button class="seg-btn motion-ui !py-1" title="Xem bản tiếng Anh (dùng để tạo ảnh)" :class="lang === 'en' ? 'is-active' : ''" @click="lang = 'en'">EN</button>
            <button class="seg-btn motion-ui !py-1" title="Xem bản tiếng Việt (để đọc hiểu)" :class="lang === 'vi' ? 'is-active' : ''" @click="lang = 'vi'; if (!r.prompt_vi) store.translate(r.image_prompt_en)">VI</button>
          </div>
          <button class="tool-btn motion-ui !px-2 !py-1 text-label" @click="copyPrompt"><StudioIcon name="copy" size="h-3 w-3" /> {{ copied === 'prompt' ? 'Đã copy' : 'Copy' }}</button>
        </div>
        <p class="suggest-text">{{ lang === 'vi' ? (r.prompt_vi || 'Đang dịch…') : r.image_prompt_en }}</p>
      </div>

      <div v-else-if="tab === 'video'" class="mt-2">
        <div class="mb-1 flex justify-end">
          <button class="tool-btn motion-ui !px-2 !py-1 text-label" @click="copyPrompt"><StudioIcon name="copy" size="h-3 w-3" /> {{ copied === 'video' ? 'Đã copy' : 'Copy' }}</button>
        </div>
        <p class="suggest-text">{{ r.video_prompt_en }}</p>
      </div>

      <p v-else-if="tab === 'notes'" class="suggest-text mt-2">{{ r.detail_notes }}</p>

      <div v-else class="mt-2 flex flex-wrap gap-1">
        <span v-for="k in keywords" :key="k" class="rounded-full bg-brand-900/60 px-2 py-0.5 text-label text-brand-200">#{{ k }}</span>
      </div>

      <!-- Việc tiếp theo: TẠO ẢNH (nút chính) · lưu lại để dùng sau (nút phụ) -->
      <button class="btn-brand mt-2.5 w-full whitespace-nowrap" :disabled="genFlow !== 'idle' && genFlow !== 'done'" @click="generateNow">
        <span v-if="genFlow === 'idle' || genFlow === 'done'" class="flex items-center justify-center gap-1.5"><StudioIcon name="imagePlus" size="h-3.5 w-3.5" /> Tạo ảnh với prompt này</span>
        <span v-else class="flex items-center justify-center gap-1.5"><span class="suggest-spin"></span> {{ flowLabel }}</span>
      </button>
      <button class="btn-ghost btn-sm motion-ui mt-1.5 w-full border border-ink-600 text-body text-cream-200" :disabled="store.suggestSaving" @click="store.saveSuggestResult()">
        {{ store.suggestSaving ? 'Đang lưu…' : 'Lưu vào Thư viện Prompt' }}
      </button>
      <p class="mt-1 text-center text-tiny leading-4 text-cream-400">Lưu lại để lần sau mở là dùng được ngay, không phải phân tích lại.</p>
    </div>

    <!-- ── Tiến trình TẠO ẢNH: cùng một widget tiến trình, không vẽ bộ riêng ── -->
    <div v-if="genFlow !== 'idle'" role="status" aria-live="polite"
         class="mt-2.5 rounded-lg border border-ok/40 bg-ok/20 p-2">
      <LoadingSpinner size="sm" :text="flowLabel" :subtext="genFlow === 'generating' || genFlow === 'done' ? Math.round(flowPct) + '%' : ''" :progress="genFlow === 'armed' ? null : flowPct" />
    </div>

    <!-- ── Gợi ý gần đây: dùng lại kết quả cũ (gấp sẵn — việc của người mới là ảnh nguồn + nút Phân tích) ── -->
    <details class="mt-3 rounded-lg border border-ink-700 bg-ink-900/50 p-2.5">
      <summary class="flex cursor-pointer items-center gap-1.5 text-label font-semibold text-cream-300">
        <StudioIcon name="clock" size="h-3.5 w-3.5" /> Gợi ý gần đây
        <span v-if="store.suggestRecentTotal" class="rounded bg-ink-800 px-1.5 py-0.5 text-tiny font-medium text-cream-300">{{ store.suggestRecentTotal }}</span>
      </summary>

      <div class="mt-2 flex justify-end">
        <button class="motion-ui rounded-full border border-ink-600 px-2 py-0.5 text-tiny font-semibold text-cream-300 transition hover:border-brand-400 hover:text-brand-200" @click="loadRecent">Làm mới</button>
      </div>

      <p v-if="store.suggestRecentLoading && !store.suggestRecent.length" class="mt-2 text-label text-cream-400">Đang tải…</p>
      <p v-else-if="!store.suggestRecent.length" class="mt-2 rounded-md border border-ink-700 bg-ink-900/60 p-2.5 text-label leading-4 text-cream-400">
        Chưa có gợi ý nào được lưu. Sau khi phân tích, bấm <b class="text-cream-200">Lưu vào Thư viện Prompt</b> để lần sau dùng lại ngay.
      </p>

      <ul v-else class="mt-2 space-y-1.5">
        <li v-for="item in store.suggestRecent" :key="item.id"
            class="motion-row flex items-center gap-2 rounded-lg border border-ink-700 bg-ink-900/50 p-1.5 transition hover:border-brand-400">
          <button class="flex min-w-0 flex-1 items-center gap-2 text-left" :title="'Nạp lại gợi ý: ' + (item.garment_type || 'không tên')" @click="store.applyRecentSuggest(item)">
            <img v-if="item.reference_thumb || item.reference_url" :src="item.reference_thumb || item.reference_url" :alt="item.garment_type || 'Ảnh gợi ý'" class="h-9 w-9 shrink-0 rounded-md bg-ink-900 object-cover ring-1 ring-ink-600">
            <span v-else class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-ink-800 text-cream-400"><StudioIcon name="image" size="h-4 w-4" /></span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-body font-semibold text-cream-100">{{ item.garment_type || (item.styles || []).join(', ') || 'Gợi ý từ ảnh' }}</span>
              <span class="mt-0.5 block truncate text-tiny text-cream-400">
                {{ item._local ? 'vừa phân tích (chưa lưu)' : (item.ago || '') }}<template v-if="item.apply_count"> · đã dùng {{ item.apply_count }} lần</template>
              </span>
            </span>
          </button>
          <button v-if="item._local" class="tool-btn motion-ui shrink-0 !px-2 !py-1" title="Lưu gợi ý này vào Thư viện Prompt" aria-label="Lưu gợi ý này vào Thư viện Prompt" @click="saveFromRecent(item)">
            <StudioIcon name="save" size="h-3.5 w-3.5" />
          </button>
          <span v-else class="shrink-0 text-tiny text-ok">đã lưu</span>
        </li>
      </ul>

      <p v-if="store.suggestRecentTotal > store.suggestRecent.length" class="mt-1.5 text-tiny text-cream-400">
        Còn {{ store.suggestRecentTotal - store.suggestRecent.length }} gợi ý nữa trong Thư viện Prompt.
      </p>
    </details>
  </div>
  <!-- Trình chọn ảnh DÙNG CHUNG của app (không tự vẽ danh sách ảnh mới). -->
  <SourceLibraryPicker v-model="pickerOpen" mode="pick" title="Chọn ảnh nguồn để phân tích" @pick="onPickSource" />
</template>

<style scoped>
/* [2026-09-23] Card KHÔNG còn sắc thái riêng: mọi card dùng chung lớp .card (bề mặt của theme).
   Trước đây mỗi card có một gradient nhận diện riêng nên 9 card là 9 sắc thái — người dùng phải
   học lại từng card, và màu thì nằm ngoài bảng màu của app. */
/* Khung văn bản prompt: cao giới hạn + cuộn, để prompt dài không kéo card dài vô tận. */
.suggest-text {
  max-height: 11rem; overflow-y: auto; border-radius: .5rem; padding: .5rem .6rem;
  font-size: var(--text-body); line-height: 1.55; color: var(--color-cream-100);
  background: var(--color-ink-900); border: 1px solid var(--color-ink-700);
}

/* Vòng xoay trên nút — dùng đúng token chuyển động, tự đứng khi bật "giảm chuyển động". */
.suggest-spin {
  display: inline-block; width: 13px; height: 13px; border-radius: 50%;
  border: 2px solid var(--color-primary-content); border-top-color: transparent;
  animation: suggest-spin var(--motion-dur-reveal) linear infinite;
}
@keyframes suggest-spin { to { transform: rotate(360deg); } }
</style>
