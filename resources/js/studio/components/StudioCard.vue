<script setup>
/**
 * STUDIO — luồng 4 bước, thiết kế cho NGƯỜI MỚI.
 *
 *  ① Ảnh người mẫu (bắt buộc — GIỮ NGUYÊN, đây là sản phẩm)
 *  ② Bối cảnh & khung hình (tùy chọn: ảnh bối cảnh + ảnh tham chiếu thêm)
 *  ③ Chọn nhanh (3 nhóm chip: Bối cảnh · Góc máy · Ống kính — lấy từ PRESET trong «Cài đặt của tôi»)
 *  ④ Mô tả thêm (tùy chọn)
 *
 * Nguyên tắc trình bày: mỗi bước có SỐ THỨ TỰ + một câu giải thích ngắn, việc bắt buộc nằm riêng và
 * to nhất, việc nâng cao (biến thể · tỉ lệ · prompt gửi AI) thu vào một khối — để người mới chỉ cần
 * làm đúng 2 việc: chọn ảnh người mẫu, bấm Tạo ảnh.
 *
 * Vì sao chip chỉ 3 nhóm: các nhóm preset khác (chất liệu · phom dáng · màu sắc…) mô tả CHÍNH SẢN PHẨM,
 * mà sản phẩm ở đây phải GIỮ NGUYÊN — đưa vào dễ khiến người mới tưởng đang thiết kế lại trang phục.
 */
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import SourceLibraryPicker from './SourceLibraryPicker.vue';
import CompareSlider from './CompareSlider.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';

const store = useStudioStore();

const FALLBACK_SLOTS = [
  { id: 1, name: 'Người mẫu mặc trang phục', hint: 'Ảnh này được giữ nguyên', required: true },
  { id: 2, name: 'Bối cảnh', hint: 'Nơi bạn muốn đặt người mẫu vào', required: false },
  { id: 3, name: 'Tham chiếu thêm', hint: 'Chi tiết, phụ kiện hoặc màu cần bám', required: false },
];

/** Số chip hiện trước khi phải bấm "xem thêm" — giữ khối chọn nhanh gọn mắt. */
const CHIPS_VISIBLE = 6;

/**
 * Prompt mẫu — học đúng cách card "Ghép trang phục" làm: một nút chèn sẵn câu chỉ dẫn hoàn chỉnh để
 * người mới không phải nghĩ cấu trúc câu, rồi sửa lại theo ý mình.
 * Có thẻ @imageN vì backend dịch chúng sang cách gọi ảnh mà model hiểu.
 */
const SAMPLE_PROMPT = 'đặt cô ấy vào đúng bối cảnh trong @image2, giữ nguyên trang phục, gương mặt và tư thế; '
  + 'ánh sáng, phối cảnh và mặt đất phải khớp với bối cảnh; nếu có @image3 thì bám theo chi tiết trong đó';

const open = ref(false);
const targetSlot = ref(0);
const selected = ref([null, null, null]);
const slotImgError = ref([false, false, false]);
const busy = ref(false);
const advancedOpen = ref(false);
const promptOpen = ref(false);
const expanded = ref({});          // nhóm chip đang mở rộng
const baseUrl = ref('');
const lastIds = ref([]);
const compareOpen = ref(false);
const afterUrl = computed(() => store.generations.find(g => lastIds.value.includes(g.id) && g.status === 'completed')?.media_url || '');

const catalog = computed(() => store.sceneCatalog);
const slots = computed(() => (catalog.value && catalog.value.slots) || FALLBACK_SLOTS);
const groups = computed(() => (catalog.value && catalog.value.groups) || []);
const ratios = computed(() => (catalog.value && catalog.value.ratios) || []);
const setup = computed(() => store.sceneSetup);
const plan = computed(() => store.scenePlan);
const warnings = computed(() => (plan.value && plan.value.warnings) || []);
const visibleWarnings = computed(() => warnings.value.filter(w => w.level === 'error' || w.level === 'warning'));
const selectedChips = computed(() => (setup.value.chips || []).map(String));
const usedChips = computed(() => (plan.value && plan.value.used_chips) || []);
const selectedCount = computed(() => selected.value.filter(Boolean).length);
const images = computed(() => selected.value.filter(Boolean).map(g => g.url).filter(Boolean));
const estimatedCredits = computed(() => (plan.value && plan.value.total_credits) || 0);
const settingsUrl = computed(() => (catalog.value && catalog.value.settings_url) || '/cai-dat/presets');
const maxChips = computed(() => (catalog.value && catalog.value.max_chips) || 12);
const variants = computed(() => Number(setup.value.variants) || 1);

/** Vì sao nút Tạo ảnh đang khoá — nói thẳng thay vì để người mới đoán. */
const blockReason = computed(() => {
  if (selectedCount.value < 1) return 'Chưa chọn ảnh ① người mẫu mặc trang phục.';
  const blocking = warnings.value.find(w => w.level === 'error');
  if (blocking) return blocking.message;
  if (!store.scenePrompt()) return 'Chưa có nội dung để làm — chọn chip ở ③ hoặc viết mô tả ở ④.';
  return '';
});
const canRun = computed(() => !blockReason.value && !busy.value);

function groupItems(group) {
  const items = (group.items || []);
  return expanded.value[group.id] ? items : items.slice(0, CHIPS_VISIBLE);
}
function hiddenCount(group) { return Math.max(0, (group.items || []).length - CHIPS_VISIBLE); }

// Tiến trình dùng CHUNG state với pipeline compose (chỉ một card mở tại một thời điểm).
const now = ref(Date.now());
let timer = null;
const elapsedSec = computed(() => store.composeStartTs ? Math.max(0, Math.floor((now.value - store.composeStartTs) / 1000)) : 0);
const fmt = (s) => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
const running = computed(() => store.composeStage === 'send' || store.composeStage === 'processing');
const doneCount = computed(() => store.composeGenIds.filter(id => store.generations.find(g => g.id === Number(id))?.status === 'completed').length);

let planTimer = null;
function schedulePlan() {
  if (planTimer) clearTimeout(planTimer);
  planTimer = setTimeout(() => { store.planScene(selectedCount.value).catch(() => {}); }, 300);
}

onMounted(async () => {
  timer = setInterval(() => { now.value = Date.now(); }, 1000);
  await store.loadSceneCatalog();
  schedulePlan();
});
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
  if (planTimer) clearTimeout(planTimer);
});

watch(() => JSON.stringify(store.sceneSetup), () => schedulePlan());
watch(selectedCount, () => schedulePlan());

function openSlot(i) { targetSlot.value = i; open.value = true; }
function onPick(img) { selected.value[targetSlot.value] = img; slotImgError.value[targetSlot.value] = false; open.value = false; }
function removeSlot(i) { selected.value[i] = null; slotImgError.value[i] = false; }
function onSlotImgError(i) { slotImgError.value[i] = true; }
function slotTitle(i) { return slots.value[i] ? slots.value[i].name : 'Ảnh'; }
function toggleChip(id) {
  if (!selectedChips.value.includes(String(id)) && selectedChips.value.length >= maxChips.value) {
    store.toast('Tối đa ' + maxChips.value + ' chip một lần — bỏ bớt chip đã chọn.', 'error');
    return;
  }
  store.toggleSceneChip(id);
}
function chipActive(id) { return selectedChips.value.includes(String(id)); }
/** Chèn thẻ @imageN vào cuối prompt (giống card Ghép trang phục). */
function insertTag(tag) {
  const current = String(setup.value.prompt || '');
  store.setSceneSetup({ prompt: (current ? current.trimEnd() + ' ' : '') + tag + ' ' });
}
function useSamplePrompt() {
  store.setSceneSetup({ prompt: SAMPLE_PROMPT });
  store.toast('Đã chèn prompt mẫu — sửa lại theo ý bạn.');
}
function chipLabelOf(id) {
  for (const group of groups.value) {
    const hit = (group.items || []).find(i => String(i.id) === String(id));
    if (hit) return hit.label;
  }
  return String(id);
}

async function run() {
  if (!canRun.value) { if (blockReason.value) store.toast(blockReason.value, 'error'); return; }
  busy.value = true;
  baseUrl.value = images.value[0] || '';
  const ids = await store.runScene(images.value);
  if (ids) lastIds.value = ids;
  busy.value = false;
}
function retry() { lastIds.value = []; store.clearComposeStatus(); run(); }
</script>

<template>
  <div class="card p-4" style="background: linear-gradient(160deg, rgba(255,170,120,.13), rgba(74,122,144,.06));">
    <!-- Đầu card: Studio làm gì, nói một câu cho người mới -->
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="camera" /> Studio</h2>
        <p class="mt-0.5 text-[10px] leading-4 text-cream-400">Đặt người mẫu vào bối cảnh mới — <span class="text-cream-200">trang phục luôn được giữ nguyên</span>.</p>
      </div>
    </div>

    <!-- Vai trò từng ô ảnh, viết theo đúng cách của card "Ghép trang phục" để nhất quán toàn Studio -->
    <p class="mt-2 rounded-md border border-brand-500/30 bg-brand-900/20 px-2.5 py-1.5 text-[10px] leading-relaxed text-brand-200">
      @image1 = người mẫu mặc trang phục (giữ nguyên) · @image2 = bối cảnh (tùy chọn) · @image3 = tham chiếu thêm (tùy chọn)
    </p>

    <p v-if="plan && plan.image_ready === false" role="status"
       class="mt-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-2.5 py-1.5 text-[10px] leading-4 text-warn">
      Tính năng tạo ảnh chưa được bật — kết quả sẽ là ẢNH MẪU (chế độ demo), không phải ảnh do AI tạo. Vui lòng báo cho quản trị viên.
    </p>

    <!-- ① ẢNH NGƯỜI MẪU (bắt buộc) -->
    <div class="mt-3">
      <p class="flex items-center gap-1.5 text-[11px] font-semibold text-cream-100">
        <span class="grid h-4 w-4 place-items-center rounded-full bg-brand-600 text-[9px] font-bold text-white">1</span>
        Ảnh người mẫu <span class="rounded bg-ink-800 px-1.5 py-0.5 text-[9px] font-medium text-brand-200">bắt buộc</span>
      </p>
      <button type="button" @click="openSlot(0)" title="Chọn ảnh người mẫu đã tạo ở bước trước"
              class="relative mt-1.5 flex h-32 w-full items-center justify-center overflow-hidden rounded-lg border transition"
              :class="selected[0] ? 'border-brand-500 bg-ink-900' : 'border-dashed border-ink-600 hover:border-brand-400 bg-ink-900/50'">
        <template v-if="selected[0]">
          <img :src="selected[0].url" class="h-full w-full object-cover" @error="onSlotImgError(0)">
          <span v-if="slotImgError[0]" class="absolute inset-0 grid place-items-center bg-ink-900 text-3xl">🖼️</span>
          <span class="absolute inset-x-0 bottom-0 bg-scrim/70 px-2 py-1 text-[10px] font-semibold text-scrim-content">Giữ nguyên ảnh này · bấm để đổi</span>
          <span @click.stop="removeSlot(0)" title="Bỏ ảnh" class="motion-ui absolute right-1.5 top-1.5 grid h-6 w-6 place-items-center rounded-full bg-red-600/90 text-white hover:bg-red-500"><StudioIcon name="x" size="h-3.5 w-3.5" /></span>
        </template>
        <span v-else class="flex flex-col items-center gap-1 text-cream-300">
          <StudioIcon name="shirt" size="h-6 w-6" />
          <span class="text-[11px] font-semibold">Bấm để chọn ảnh người mẫu</span>
          <span class="text-[10px] text-cream-400">Lấy từ Thư viện ảnh — kết quả ở bước 「Tạo ảnh」</span>
        </span>
      </button>
    </div>

    <!-- ② BỐI CẢNH & KHUNG HÌNH (tùy chọn) -->
    <div class="mt-4">
      <p class="flex items-center gap-1.5 text-[11px] font-semibold text-cream-100">
        <span class="grid h-4 w-4 place-items-center rounded-full bg-ink-700 text-[9px] font-bold text-cream-100">2</span>
        Bối cảnh &amp; khung hình <span class="rounded bg-ink-800 px-1.5 py-0.5 text-[9px] font-medium text-cream-400">tùy chọn</span>
      </p>
      <div class="mt-1.5 grid grid-cols-2 gap-2">
        <button v-for="i in [1, 2]" :key="i" type="button" @click="openSlot(i)" :title="slotTitle(i)"
                class="relative flex h-20 items-center justify-center overflow-hidden rounded-lg border transition"
                :class="selected[i] ? 'border-brand-500 bg-ink-900' : 'border-dashed border-ink-600 hover:border-brand-400 bg-ink-900/50'">
          <template v-if="selected[i]">
            <img :src="selected[i].url" class="h-full w-full object-cover" @error="onSlotImgError(i)">
            <span v-if="slotImgError[i]" class="absolute inset-0 grid place-items-center bg-ink-900 text-2xl">🖼️</span>
            <span class="absolute inset-x-0 bottom-0 bg-scrim/70 px-1 py-0.5 text-center text-[9px] font-semibold text-scrim-content">{{ slotTitle(i) }}</span>
            <span @click.stop="removeSlot(i)" title="Bỏ ảnh" class="motion-ui absolute right-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-red-600/90 text-[10px] text-white hover:bg-red-500"><StudioIcon name="x" size="h-3 w-3" /></span>
          </template>
          <span v-else class="flex flex-col items-center gap-0.5 px-1 text-center">
            <StudioIcon :name="i === 1 ? 'image' : 'imagePlus'" size="h-4 w-4" class="text-cream-400" />
            <span class="text-[10px] font-medium text-cream-200">{{ slotTitle(i) }}</span>
            <span class="text-[9px] leading-3 text-cream-400">{{ i === 1 ? 'nơi đặt người mẫu vào' : 'chi tiết cần bám' }}</span>
          </span>
        </button>
      </div>
    </div>

    <!-- ③ CHỌN NHANH: Bối cảnh · Góc máy · Ống kính -->
    <div class="mt-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="flex items-center gap-1.5 text-[11px] font-semibold text-cream-100">
          <span class="grid h-4 w-4 place-items-center rounded-full bg-ink-700 text-[9px] font-bold text-cream-100">3</span>
          Chọn nhanh
          <span v-if="selectedChips.length" class="rounded bg-brand-600 px-1.5 py-0.5 text-[9px] font-bold text-white">{{ selectedChips.length }}</span>
        </p>
        <div class="flex items-center gap-1.5">
          <a :href="settingsUrl" target="_blank" rel="noopener"
             class="motion-ui rounded-full border border-ink-600 px-2 py-0.5 text-[9px] font-semibold text-cream-300 transition hover:border-brand-400 hover:text-brand-200"
             title="Chip lấy từ PRESET trong «Cài đặt của tôi» — mở để thêm/sửa">Sửa chip</a>
          <button v-if="selectedChips.length" type="button" class="tool-btn !px-2 !py-0.5 text-[9px]" @click="store.clearSceneChips()">Bỏ chọn</button>
        </div>
      </div>

      <p v-if="!groups.length" class="mt-1.5 rounded-md border border-ink-700 bg-ink-900/60 p-2.5 text-[10px] leading-4 text-cream-400">
        Chưa có preset cho Bối cảnh / Góc máy / Ống kính → <a :href="settingsUrl" target="_blank" rel="noopener" class="text-brand-300 underline">thêm ở «Cài đặt của tôi」</a>.
      </p>

      <div v-for="group in groups" :key="group.id" class="mt-2.5">
        <p class="text-[9px] font-semibold uppercase tracking-wide text-cream-400">{{ group.label }}</p>
        <div class="mt-1 flex flex-wrap gap-1.5">
          <button v-for="item in groupItems(group)" :key="item.id" type="button" :title="item.note || item.injection"
                  class="motion-ui rounded-full border px-2 py-1 text-[10px] font-medium transition"
                  :class="chipActive(item.id) ? 'border-brand-500 bg-brand-600 text-white' : 'border-ink-600 bg-ink-800 text-cream-200 hover:border-brand-400'"
                  @click="toggleChip(item.id)">{{ item.label }}</button>
          <button v-if="hiddenCount(group) && !expanded[group.id]" type="button"
                  class="motion-ui rounded-full border border-ink-600 px-2 py-1 text-[10px] text-cream-400 transition hover:border-brand-400 hover:text-cream-100"
                  @click="expanded = { ...expanded, [group.id]: true }">+{{ hiddenCount(group) }} nữa</button>
        </div>
      </div>

      <div v-if="selectedChips.length" class="mt-2 flex flex-wrap gap-1.5">
        <button v-for="id in selectedChips" :key="id" type="button"
                class="motion-ui inline-flex items-center gap-1 rounded-full bg-ink-800 px-2 py-0.5 text-[9px] text-cream-200 transition hover:bg-red-600/25"
                :title="'Bỏ chip: ' + chipLabelOf(id)" @click="toggleChip(id)">
          {{ chipLabelOf(id) }} <span class="text-cream-400">×</span>
        </button>
      </div>
    </div>

    <!-- ④ MÔ TẢ THÊM (tùy chọn) -->
    <div class="mt-4">
      <p class="flex items-center gap-1.5 text-[11px] font-semibold text-cream-100">
        <span class="grid h-4 w-4 place-items-center rounded-full bg-ink-700 text-[9px] font-bold text-cream-100">4</span>
        Mô tả thêm <span class="rounded bg-ink-800 px-1.5 py-0.5 text-[9px] font-medium text-cream-400">tùy chọn</span>
      </p>
      <textarea :value="setup.prompt" rows="3" maxlength="2000" class="input mt-1.5 w-full resize-none !text-xs"
                placeholder="VD: đặt cô ấy vào quán cà phê, ánh sáng cửa sổ, giữ nguyên tư thế…"
                @input="store.setSceneSetup({ prompt: $event.target.value })"></textarea>

      <!-- Chip thẻ ảnh + prompt mẫu (cùng cách card "Ghép trang phục") -->
      <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
        <button v-for="n in 3" :key="n" type="button" @click="insertTag('@image' + n)"
                class="motion-ui rounded-full bg-ink-800 px-2 py-0.5 text-[10px] font-semibold text-brand-300 transition hover:bg-brand-600 hover:text-white"
                :title="'Chèn @image' + n + ' vào prompt'">@image{{ n }}</button>
        <span class="text-cream-400">|</span>
        <button type="button" @click="useSamplePrompt"
                class="motion-ui rounded-full border border-ink-600 px-2 py-0.5 text-[10px] font-semibold text-cream-200 transition hover:border-brand-400"
                title="Chèn một prompt mẫu hoàn chỉnh rồi sửa lại">Prompt mẫu</button>
      </div>
      <p class="mt-1 text-[9px] leading-4 text-cream-400">
        Chip ở bước ③ được nối tự động — không cần gõ lại. Thẻ <span class="text-brand-300">@image1/@image2/@image3</span> giúp chỉ đích danh từng ảnh.
      </p>
    </div>

    <!-- NÂNG CAO -->
    <details class="mt-3 rounded-lg border border-ink-700 bg-ink-900/50 p-2.5" @toggle="advancedOpen = $event.target.open">
      <summary class="cursor-pointer text-[10px] font-semibold text-cream-300">Nâng cao: biến thể · tỉ lệ · prompt gửi AI</summary>
      <div class="mt-2.5 flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-[11px] text-cream-200">
          <span>Số biến thể</span>
          <select :value="variants" class="input !w-auto !py-1 !text-[11px]" @change="store.setSceneSetup({ variants: Number($event.target.value) })">
            <option v-for="n in [1, 2, 3, 4]" :key="n" :value="n">{{ n }}</option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-[11px] text-cream-200">
          <span>Tỉ lệ</span>
          <select :value="setup.ratio" class="input !w-auto !py-1 !text-[11px]" @change="store.setSceneSetup({ ratio: $event.target.value })">
            <option value="">Mặc định</option>
            <option v-for="r in ratios" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </label>
        <button type="button" class="tool-btn !px-2 !py-1 text-[10px]" @click="promptOpen = !promptOpen">
          {{ promptOpen ? 'Ẩn prompt gửi AI' : 'Xem/sửa prompt gửi AI' }}
        </button>
      </div>

      <div v-if="promptOpen" class="mt-2">
        <textarea :value="store.sceneEditedPrompt || (plan && plan.prompt) || ''" rows="7"
                  class="input w-full !text-[11px] leading-relaxed"
                  @input="store.sceneEditedPrompt = $event.target.value"></textarea>
        <p class="mt-1 text-[10px] leading-4" :class="store.sceneEditedPrompt ? 'text-warn' : 'text-cream-400'">
          <span v-if="store.sceneEditedPrompt">✓ Sẽ gửi bản bạn đã sửa.</span>
          <span v-else>Hệ thống tự dựng từ ảnh + chip + mô tả. Sửa tay ở đây thì bản sửa được ưu tiên.</span>
        </p>
      </div>

      <ul v-if="plan && plan.notes && plan.notes.length" class="mt-2 space-y-0.5">
        <li v-for="(n, i) in plan.notes" :key="i" class="text-[9px] leading-4 text-cream-400">• {{ n }}</li>
      </ul>
    </details>

    <!-- CẢNH BÁO chặn/đáng chú ý -->
    <ul v-if="visibleWarnings.length" class="mt-2.5 space-y-1">
      <li v-for="(w, i) in visibleWarnings" :key="i" class="text-[10px] leading-4"
          :class="w.level === 'error' ? 'text-danger' : 'text-warn'">• {{ w.message }}</li>
    </ul>
    <div v-if="store.sceneError" role="alert" class="mt-2.5 rounded-lg border border-red-500/40 bg-red-500/10 p-2.5 text-[11px] text-danger">
      {{ store.sceneError }}
    </div>

    <!-- NÚT CHẠY -->
    <button @click="run" :disabled="!canRun" class="btn-brand mt-3 w-full whitespace-nowrap">
      <StudioIcon name="camera" size="h-3.5 w-3.5" />
      {{ busy ? 'Đang tạo ảnh…' : (variants > 1 ? 'Tạo ' + variants + ' biến thể' : 'Tạo ảnh') }}
      <span v-if="!busy && estimatedCredits" class="opacity-70">· ~{{ estimatedCredits }} credit</span>
    </button>
    <p v-if="blockReason" class="mt-1.5 text-[10px] leading-4 text-warn">↳ {{ blockReason }}</p>

    <!-- Tiến độ -->
    <div v-if="running" class="mt-3 rounded-lg border border-brand-500/30 bg-brand-900/30 p-3">
      <LoadingSpinner
        :text="store.composeStage === 'send' ? 'Đang gửi yêu cầu tới AI…' : 'AI đang dựng khung hình…'"
        :subtext="fmt(elapsedSec) + ' · ' + doneCount + '/' + store.composeGenIds.length + ' ảnh'"
        :progress="doneCount / Math.max(1, store.composeGenIds.length) * 100" />
      <div class="mt-2 flex justify-end">
        <button @click="store.cancelCompose()" class="rounded-full bg-red-600/25 px-2.5 py-1 text-[10px] font-semibold text-danger hover:bg-red-600">Hủy</button>
      </div>
    </div>

    <div v-if="store.composeStage === 'done'" class="mt-3 rounded-lg border border-emerald-500/40 bg-emerald-900/25 p-3 text-[11px] leading-5 text-ok">
      Xong — ảnh đã vào <strong>Outputs</strong> (góc phải) và bảng Lớp.
      <button @click="store.clearComposeStatus()" class="ml-1 rounded-full bg-cream-50/10 px-2 py-0.5 hover:bg-cream-50/20">Đóng</button>
    </div>

    <div v-if="store.composeStage === 'error' && store.composeError" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-3 text-[11px] leading-5 text-danger">
      <p class="font-semibold">Studio không chạy được</p>
      <p class="mt-1 whitespace-pre-line">{{ store.composeError }}</p>
      <div class="mt-2 flex gap-2">
        <button @click="retry" class="btn-brand btn-sm">Thử lại</button>
        <button @click="store.clearComposeStatus()" class="btn-ghost btn-sm">Đóng</button>
      </div>
    </div>

    <div v-if="store.composeStage === 'cancelled'" class="mt-3 flex items-center gap-2 rounded-lg border border-white/15 bg-cream-50/5 p-3 text-[11px] text-cream-200">
      Đã hủy.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-cream-50/10 px-2 py-0.5 hover:bg-cream-50/20">Đóng</button>
    </div>

    <button v-if="baseUrl && afterUrl" @click="compareOpen = true" class="btn-outline mt-2 w-full whitespace-nowrap">🔍 So sánh Trước/Sau</button>

    <SourceLibraryPicker
      v-model="open"
      :title="'Chọn ảnh cho ô ' + (targetSlot + 1) + ' · ' + slotTitle(targetSlot)"
      mode="pick"
      @pick="onPick" />

    <CompareSlider v-model="compareOpen" :before="baseUrl" :after="afterUrl" title="So sánh ảnh gốc và ảnh Studio" />
  </div>
</template>
