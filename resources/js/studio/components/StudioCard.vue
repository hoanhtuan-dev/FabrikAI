<script setup>
/**
 * STUDIO — dựng khung hình từ ẢNH NGƯỜI MẪU + BỐI CẢNH + PROMPT + CHIP NHANH.
 *
 * LUỒNG (chốt 2026-09-22):
 *   ảnh 1 = người mẫu mặc trang phục (kết quả từ bước trước — GIỮ NGUYÊN, đây là sản phẩm)
 *   ảnh 2 = bối cảnh (tùy chọn)  ·  ảnh 3 = tham chiếu thêm (tùy chọn)
 *   + ô nhập prompt  +  CHIP NHANH đọc từ PRESET trong "Cài đặt của tôi".
 *
 * Vì sao bỏ bối cảnh/ánh sáng/ống kính/dáng dựng sẵn: người dùng đã có nguồn chân lý riêng là PRESET
 * trong Cài đặt của tôi (nhãn + đoạn chèn vào prompt, chia theo nhóm). Chip ở đây đọc ĐÚNG dữ liệu đó
 * (kể cả phần tự thêm/sửa/ẩn) nên không còn hai nơi định nghĩa trùng nhau.
 *
 * Prompt do backend dựng TẤT ĐỊNH (/api/studio/shoot/plan) nên xem/sửa được trước khi tốn credit;
 * chạy ảnh dùng lại đúng pipeline /api/compose sẵn có (base = ảnh 1, refs = ảnh 2/3).
 */
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import SourceLibraryPicker from './SourceLibraryPicker.vue';
import CompareSlider from './CompareSlider.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';

const store = useStudioStore();

const FALLBACK_SLOTS = [
  { id: 1, name: 'Người mẫu mặc trang phục', hint: 'BẮT BUỘC — ảnh này được giữ nguyên', required: true },
  { id: 2, name: 'Bối cảnh', hint: 'Tùy chọn — AI đưa người mẫu vào đúng bối cảnh này', required: false },
  { id: 3, name: 'Tham chiếu thêm', hint: 'Tùy chọn — chi tiết, phụ kiện hoặc màu cần bám', required: false },
];

const open = ref(false);
const targetSlot = ref(0);
const selected = ref([null, null, null]);
const slotImgError = ref([false, false, false]);
const busy = ref(false);
const promptOpen = ref(false);
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
const selectedChips = computed(() => (setup.value.chips || []).map(String));
const usedChips = computed(() => (plan.value && plan.value.used_chips) || []);
const selectedCount = computed(() => selected.value.filter(Boolean).length);
const images = computed(() => selected.value.filter(Boolean).map(g => g.url).filter(Boolean));
const estimatedCredits = computed(() => (plan.value && plan.value.total_credits) || 0);
const blocked = computed(() => warnings.value.some(w => w.level === 'error'));
const canRun = computed(() => selectedCount.value >= 1 && !blocked.value && !busy.value && !!store.scenePrompt());
const settingsUrl = computed(() => (catalog.value && catalog.value.settings_url) || '/cai-dat/presets');
const maxChips = computed(() => (catalog.value && catalog.value.max_chips) || 12);
const chipLabel = (id) => {
  for (const group of groups.value) {
    const hit = (group.items || []).find(i => String(i.id) === String(id));
    if (hit) return hit.label;
  }
  return String(id);
};

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
function roleLabel(i) { return '@image' + (i + 1); }
function toggleChip(id) {
  if (!selectedChips.value.includes(String(id)) && selectedChips.value.length >= maxChips.value) {
    store.toast('Tối đa ' + maxChips.value + ' chip một lần — bỏ bớt chip đã chọn.', 'error');
    return;
  }
  store.toggleSceneChip(id);
}
function chipActive(id) { return selectedChips.value.includes(String(id)); }

async function run() {
  if (selectedCount.value < 1) { store.toast('Chọn ảnh 1: ảnh người mẫu mặc trang phục.', 'error'); return; }
  if (blocked.value) { store.toast('Còn lỗi cần sửa trước khi chạy.', 'error'); return; }
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
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="camera" /> Studio</h2>
        <p class="mt-0.5 text-[10px] leading-4 text-cream-300/55">Đưa người mẫu vào bối cảnh · giữ nguyên trang phục</p>
      </div>
      <a :href="settingsUrl" target="_blank" rel="noopener"
         class="motion-ui shrink-0 rounded-full border border-ink-600 px-2 py-0.5 text-[9px] font-semibold text-cream-300/70 transition hover:border-brand-400 hover:text-brand-200"
         title="Chip nhanh lấy từ PRESET trong Cài đặt của tôi — mở để thêm/sửa/ẩn">Sửa chip</a>
    </div>

    <p v-if="plan && plan.image_ready === false" role="status"
       class="mt-2 flex gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-2.5 py-1.5 text-[10px] leading-4 text-amber-100">
      <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" class="shrink-0" />
      <span>Chưa cấu hình model tạo/sửa ảnh (nhóm “edit”) — kết quả sẽ là ẢNH MẪU (chế độ demo), không phải ảnh do AI tạo.</span>
    </p>

    <!-- 1. BA Ô ẢNH -->
    <div class="mt-3 grid grid-cols-3 gap-2">
      <button v-for="slot in slots" :key="slot.id" @click="openSlot(slot.id - 1)" :title="slot.hint"
              class="relative flex h-24 flex-col items-center justify-center overflow-hidden rounded-md border transition"
              :class="selected[slot.id-1] ? 'border-brand-500 bg-ink-900' : 'border-dashed border-ink-700 hover:border-brand-400 bg-ink-900/40'">
        <template v-if="selected[slot.id-1]">
          <img :src="selected[slot.id-1].url" class="h-full w-full object-cover" @error="onSlotImgError(slot.id-1)">
          <span v-if="slotImgError[slot.id-1]" class="absolute inset-0 grid place-items-center bg-ink-900 text-2xl" title="Ảnh không tải được — bấm × để bỏ">🖼️</span>
          <span class="absolute left-1 top-1 rounded-full bg-brand-500 px-1.5 text-[9px] font-bold text-white">{{ slot.id }}</span>
          <span class="absolute inset-x-0 bottom-0 bg-black/65 px-1 py-0.5 text-center text-[9px] font-semibold text-cream-100">{{ slot.name }}</span>
          <span @click.stop="removeSlot(slot.id-1)" title="Bỏ ảnh khỏi ô" class="motion-ui absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-red-600/90 text-[11px] text-white hover:bg-red-500"><StudioIcon name="x" size="h-3.5 w-3.5" /></span>
        </template>
        <template v-else>
          <span class="grid h-6 w-6 place-items-center text-ink-600"><StudioIcon :name="slot.id === 1 ? 'shirt' : (slot.id === 2 ? 'image' : 'imagePlus')" size="h-5 w-5" /></span>
          <span class="px-1 text-center text-[9px] font-medium text-cream-300/60">{{ slot.name }}</span>
          <span class="px-1 text-center text-[9px]" :class="slot.required ? 'text-brand-300' : 'text-cream-300/40'">{{ slot.required ? 'bắt buộc' : 'tùy chọn' }}</span>
        </template>
      </button>
    </div>

    <!-- 2. Ô NHẬP PROMPT -->
    <label class="label mt-4">Mô tả / chỉ dẫn</label>
    <textarea :value="setup.prompt" rows="3" maxlength="2000" class="input w-full resize-none !text-xs"
              placeholder="VD: đặt cô ấy vào quán cà phê với ánh sáng cửa sổ, giữ nguyên trang phục và tư thế…"
              @input="store.setSceneSetup({ prompt: $event.target.value })"></textarea>
    <div class="mt-1 flex items-center justify-between text-[10px] text-cream-300/45">
      <span>Chip nhanh nối vào prompt khi gửi AI — bạn không cần gõ lại.</span>
      <span class="tabular-nums">{{ (setup.prompt || '').length }}/2000</span>
    </div>

    <!-- 3. CHIP NHANH (từ Cài đặt của tôi) -->
    <div class="mt-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">
          Chip nhanh <span class="font-normal normal-case text-cream-300/40">· {{ selectedChips.length }}/{{ maxChips }}</span>
        </span>
        <button v-if="selectedChips.length" type="button" class="tool-btn !px-2 !py-1 text-[10px]" @click="store.clearSceneChips()">Bỏ chọn tất cả</button>
      </div>

      <p v-if="!groups.length" class="mt-1.5 rounded-md border border-ink-700 bg-ink-900/60 p-2.5 text-[10px] leading-4 text-cream-300/60">
        Chưa có preset nào trong «Cài đặt của tôi» → <a :href="settingsUrl" target="_blank" rel="noopener" class="text-brand-300 underline">thêm chip ở đây</a>.
      </p>

      <div v-for="group in groups" :key="group.id" class="mt-2">
        <p class="text-[9px] font-semibold uppercase tracking-wide text-cream-300/40">{{ group.label }}</p>
        <div class="mt-1 flex flex-wrap gap-1.5">
          <button v-for="item in group.items" :key="item.id" type="button" :title="item.note || item.injection"
                  class="motion-ui rounded-full border px-2 py-0.5 text-[10px] font-medium transition"
                  :class="chipActive(item.id) ? 'border-brand-500 bg-brand-600 text-white' : 'border-ink-600 bg-ink-800 text-cream-200 hover:border-brand-400'"
                  @click="toggleChip(item.id)">{{ item.label }}</button>
        </div>
      </div>

      <div v-if="usedChips.length" class="mt-2 flex flex-wrap items-center gap-1.5">
        <span class="text-[9px] uppercase tracking-wide text-cream-300/40">Đang dùng:</span>
        <span v-for="chip in usedChips" :key="chip.id" class="rounded-full bg-ink-800 px-2 py-0.5 text-[9px] text-cream-200" :title="chip.injection">
          {{ chip.category_label }} · {{ chip.label }}
        </span>
      </div>
    </div>

    <!-- 4. BIẾN THỂ + TỈ LỆ -->
    <div class="mt-3 flex flex-wrap items-center gap-3">
      <label class="flex items-center gap-2 text-[11px] text-cream-200">
        <span>Số biến thể</span>
        <select :value="setup.variants" class="input !w-auto !py-1 !text-[11px]" @change="store.setSceneSetup({ variants: Number($event.target.value) })">
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

    <div v-if="promptOpen" class="mt-2 rounded-lg border border-brand-500/30 bg-brand-900/20 p-3">
      <div class="mb-1.5 flex items-center justify-between gap-2">
        <span class="text-[11px] font-semibold text-brand-200">Prompt sẽ gửi cho AI</span>
        <button type="button" class="btn-ghost btn-sm shrink-0" :disabled="store.sceneLoading" @click="store.planScene(selectedCount)">Làm mới</button>
      </div>
      <textarea :value="store.sceneEditedPrompt || (plan && plan.prompt) || ''" rows="7"
                class="input w-full !text-[11px] leading-relaxed"
                @input="store.sceneEditedPrompt = $event.target.value"></textarea>
      <p class="mt-1 text-[10px] leading-4" :class="store.sceneEditedPrompt ? 'text-amber-300' : 'text-cream-300/50'">
        <span v-if="store.sceneEditedPrompt">✓ Sẽ gửi bản bạn đã sửa.</span>
        <span v-else>Tự dựng từ 3 ô ảnh + prompt + chip. Sửa tay ở đây thì bản sửa được ưu tiên.</span>
      </p>
    </div>

    <!-- 5. CẢNH BÁO -->
    <ul v-if="warnings.length" class="mt-2 space-y-1">
      <li v-for="(w, i) in warnings" :key="i" class="text-[10px] leading-4"
          :class="w.level === 'error' ? 'text-red-300' : (w.level === 'warning' ? 'text-amber-200' : 'text-cream-300/60')">
        • {{ w.message }}
      </li>
    </ul>
    <div v-if="store.sceneError" role="alert" class="mt-2 flex gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-2.5 text-[11px] text-red-200">
      <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" class="shrink-0" /><span>{{ store.sceneError }}</span>
    </div>

    <!-- 6. CHẠY -->
    <button @click="run" :disabled="!canRun" class="btn-brand mt-3 w-full whitespace-nowrap">
      {{ busy ? 'Đang dựng ảnh…' : ((plan && plan.variants > 1) ? 'Tạo ' + plan.variants + ' biến thể' : 'Tạo ảnh') }}
      <span v-if="!busy" class="opacity-70">· ~{{ estimatedCredits }} credit</span>
    </button>
    <p v-if="selectedCount < 1" class="mt-1 text-[10px] leading-4 text-amber-200/80">Cần ảnh 1: ảnh người mẫu mặc trang phục (kết quả từ bước trước).</p>

    <!-- Tiến độ -->
    <div v-if="running" class="mt-3 rounded-lg border border-brand-500/30 bg-brand-900/30 p-3">
      <LoadingSpinner
        :text="store.composeStage === 'send' ? 'Đang gửi yêu cầu tới AI…' : 'AI đang dựng khung hình…'"
        :subtext="fmt(elapsedSec) + ' · ' + doneCount + '/' + store.composeGenIds.length + ' ảnh'"
        :progress="doneCount / Math.max(1, store.composeGenIds.length) * 100" />
      <div class="mt-2 flex justify-end">
        <button @click="store.cancelCompose()" class="rounded-full bg-red-600/25 px-2.5 py-1 text-[10px] font-semibold text-red-200 hover:bg-red-600">Hủy</button>
      </div>
    </div>

    <div v-if="store.composeStage === 'done'" class="mt-3 rounded-lg border border-emerald-500/40 bg-emerald-900/25 p-3 text-xs text-emerald-200">
      Đã xong — ảnh đã được chọn trong Outputs và xếp vào bảng Lớp.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <div v-if="store.composeStage === 'error' && store.composeError" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-3 text-xs text-red-200">
      <p class="font-semibold">Studio thất bại</p>
      <p class="mt-1 whitespace-pre-line leading-relaxed">{{ store.composeError }}</p>
      <div class="mt-2 flex gap-2">
        <button @click="retry" class="btn-brand btn-sm">Thử lại</button>
        <button @click="store.clearComposeStatus()" class="btn-ghost btn-sm">Đóng</button>
      </div>
    </div>

    <div v-if="store.composeStage === 'cancelled'" class="mt-3 flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 p-3 text-xs text-cream-200">
      Đã hủy yêu cầu.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <button v-if="baseUrl && afterUrl" @click="compareOpen = true" class="btn-outline mt-1.5 w-full whitespace-nowrap">🔍 So sánh Trước/Sau</button>

    <SourceLibraryPicker
      v-model="open"
      :title="'Chọn ảnh cho ' + roleLabel(targetSlot) + ' · ' + (slots[targetSlot] ? slots[targetSlot].name : '')"
      mode="pick"
      @pick="onPick" />

    <CompareSlider v-model="compareOpen" :before="baseUrl" :after="afterUrl" title="So sánh ảnh gốc và ảnh Studio" />
  </div>
</template>
