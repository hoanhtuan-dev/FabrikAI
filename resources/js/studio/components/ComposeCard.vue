<script setup>
/**
 * "Ghép ảnh" — dựng BỐ CỤC từ nhiều ảnh: @image1 = nền chính, @image2/@image3 = ảnh ghép.
 *
 * [Yêu cầu 2026-09-22] Chế độ "Ghép trang phục" đã được TÁCH RA THÀNH CARD RIÊNG
 * (OutfitComposeCard) — đó là việc khác về bản chất (lai tạo biến thể trang phục, có phong cách ·
 * trang trí · mức sáng tạo · preset) nên để chung một card thì người dùng phải đổi chế độ rồi mới
 * thấy đúng công cụ của mình, còn card này bị đội thêm 6 khối chỉ dùng cho chế độ kia.
 *
 * Hai card dùng CHUNG đường backend /api/compose (khác tham số mode) nên kết quả, tiến trình và
 * Outputs vẫn nhất quán.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import SourceLibraryPicker from './SourceLibraryPicker.vue';
import CompareSlider from './CompareSlider.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';
const store = useStudioStore();

const prompt = ref('');
const variants = ref(1);
const busy = ref(false);
const previewOpen = ref(false);
const previewPrompt = ref('');
const previewLoading = ref(false);
const previewDirty = ref(false);
const open = ref(false);
const selected = ref([null, null, null]); // 3 slot cố định: image object hoặc null
const targetSlot = ref(0);  // slot đang chọn trong popup
const slotImgError = ref([false, false, false]); // ảnh slot bị lỗi (404/broken) — vẫn cho xóa

const baseUrl = ref('');
const lastIds = ref([]);
const compareOpen = ref(false);
const afterUrl = computed(() => store.generations.find(g => lastIds.value.includes(g.id) && g.status === 'completed')?.media_url || '');

// Tiến trình (dùng chung state với card Ghép trang phục — chỉ một card mở tại một thời điểm)
const now = ref(Date.now());
let timer = null;
const elapsedSec = computed(() => store.composeStartTs ? Math.max(0, Math.floor((now.value - store.composeStartTs) / 1000)) : 0);
const fmt = (s) => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
const running = computed(() => store.composeStage === 'send' || store.composeStage === 'processing');
const doneCount = computed(() => store.composeGenIds.filter(id => store.generations.find(g => g.id === Number(id))?.status === 'completed').length);

const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

const selectedImgs = computed(() => selected.value.filter(Boolean));
const selectedCount = computed(() => selectedImgs.value.length);
const SLOT_ROLES = ['Nền chính', 'Ảnh ghép', 'Ảnh ghép'];

onMounted(() => { timer = setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => { if (timer) clearInterval(timer); });

function openSlot(i) {
  targetSlot.value = i;
  open.value = true;
}

// Bấm 1 ảnh trong popup → gán vào slot đang chọn
function onPick(img) {
  selected.value[targetSlot.value] = img;
  slotImgError.value[targetSlot.value] = false;
  open.value = false;
}

function removeSlot(i) {
  selected.value[i] = null;
  slotImgError.value[i] = false;
}
function onSlotImgError(i) { slotImgError.value[i] = true; }

// Đưa slot i lên làm @image1 (nền chính)
function makeBase(i) {
  if (i <= 0) return;
  const s = [...selected.value];
  const img = s[i];
  s.splice(i, 1);
  s.unshift(img);
  s.push(null); // giữ luôn 3 slot
  s.length = 3;
  selected.value = s;
}

function roleLabel(i) { return '@image' + (i + 1); }
function insertTag(tag) {
  prompt.value = (prompt.value ? prompt.value + ' ' : '') + tag + ' ';
}

async function run() {
  if (selectedCount.value < 2 || busy.value) return;
  const urls = selectedImgs.value.map(g => g.url).filter(Boolean);
  baseUrl.value = urls[0] || '';
  busy.value = true;
  const override = previewDirty.value ? previewPrompt.value : '';
  // Chế độ 'compose' KHÔNG dùng creative_level/style/ornament (đó là tham số của Ghép trang phục).
  const items = await store.compose(urls, prompt.value, variants.value, 'compose', 6, '', 0, override);
  if (items) lastIds.value = items.map(it => it.generation_id).filter(Boolean);
  busy.value = false;
}

// ── Xem trước / chỉnh tay prompt ──
function togglePreview() {
  previewOpen.value = !previewOpen.value;
  if (previewOpen.value) loadPreview();
}

async function loadPreview() {
  if (selectedCount.value < 2) { store.toast('Chọn ít nhất 2 ảnh để xem trước prompt.', 'error'); return; }
  previewLoading.value = true;
  try {
    const urls = selectedImgs.value.map(g => g.url).filter(Boolean);
    const res = await fetch('/api/compose/preview', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ images: urls, prompt: prompt.value, mode: 'compose', variants: variants.value }),
    });
    const d = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(d.message || 'Không tải được bản xem trước prompt.');
    previewPrompt.value = d.prompt || '';
    previewDirty.value = false;
  } catch (e) {
    store.toast(e.message || 'Lỗi tải bản xem trước prompt.', 'error');
  } finally {
    previewLoading.value = false;
  }
}

function onPreviewEdit() { previewDirty.value = true; }
</script>
<template>
  <div class="card p-4" style="background: linear-gradient(160deg, rgba(255,170,120,.13), rgba(74,122,144,.06));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="layers" /> Ghép ảnh</h2>
    <p class="mt-2 rounded-md border border-white/10 bg-white/5 px-2.5 py-1.5 text-[10px] leading-relaxed text-cream-300/70">
      @image1 = nền chính · @image2/@image3 = ảnh ghép — dựng bố cục hoàn chỉnh từ nhiều ảnh
    </p>

    <!-- 3 slot ảnh: bấm để tải/chọn -->
    <div class="mt-3 grid grid-cols-3 gap-2">
      <button v-for="i in 3" :key="i" @click="openSlot(i - 1)" title="Bấm để tải/chọn ảnh"
              class="relative flex h-24 flex-col items-center justify-center overflow-hidden rounded-md border transition"
              :class="selected[i-1] ? 'border-brand-500 bg-ink-900' : 'border-dashed border-ink-700 hover:border-brand-400 bg-ink-900/40'">
        <template v-if="selected[i-1]">
          <img :src="selected[i-1].url" class="h-full w-full object-cover" @error="onSlotImgError(i-1)">
          <span v-if="slotImgError[i-1]" class="absolute inset-0 grid place-items-center bg-ink-900 text-2xl" title="Ảnh không tải được — bấm × để bỏ">🖼️</span>
          <span class="absolute left-1 top-1 rounded-full bg-brand-500 px-1.5 text-[9px] font-bold text-white">{{ i }}</span>
          <span class="absolute inset-x-0 bottom-0 bg-black/65 px-1 py-0.5 text-center text-[9px] font-semibold text-cream-100">{{ SLOT_ROLES[i-1] }}</span>
          <span @click.stop="removeSlot(i-1)" title="Bỏ ảnh khỏi slot" class="motion-ui absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-red-600/90 text-[11px] text-white hover:bg-red-500"><StudioIcon name="x" size="h-3.5 w-3.5" /></span>
          <span v-if="i > 1" @click.stop="makeBase(i-1)" class="absolute bottom-6 right-1 grid h-5 w-5 place-items-center rounded-full bg-ink-800/90 text-[9px] text-white" title="Đưa lên làm @image1">⤴</span>
        </template>
        <template v-else>
          <span class="grid h-6 w-6 place-items-center text-ink-600"><StudioIcon name="image" size="h-5 w-5" v-if="i === 1" /><span v-else>＋</span></span>
          <span class="px-1 text-center text-[9px] font-medium text-cream-300/60">{{ SLOT_ROLES[i-1] }}</span>
          <span class="px-1 text-center text-[9px] text-cream-300/40">@image{{ i }}</span>
        </template>
      </button>
    </div>

    <label class="label mt-4">Mô tả ghép</label>
    <textarea v-model="prompt" rows="3" maxlength="1000" class="input !text-xs" placeholder="VD: giữ nguyên @image1, đặt cô gái trong @image2 vào nền studio…"></textarea>
    <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10px]">
      <button v-for="n in 3" :key="n" @click="insertTag('@image' + n)"
              class="rounded-full bg-ink-800 px-2 py-0.5 font-semibold text-brand-300 transition hover:bg-brand-600 hover:text-white">@image{{ n }}</button>
    </div>

    <!-- Số biến thể -->
    <div class="mt-3 flex items-center gap-1.5 text-xs text-cream-200">
      <span class="mr-1">Số biến thể:</span>
      <button v-for="n in [1,2,3,4]" :key="n" @click="variants = n"
              :class="variants === n ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'"
              class="h-7 w-7 rounded-full font-semibold transition-colors">{{ n }}</button>
    </div>

    <!-- Xem trước / chỉnh tay prompt -->
    <button @click="togglePreview" type="button" class="btn-outline mt-3 w-full whitespace-nowrap">
      {{ previewOpen ? 'Ẩn xem trước prompt' : '👁 Xem trước prompt' }}
    </button>
    <div v-if="previewOpen" class="mt-2 rounded-lg border border-brand-500/30 bg-brand-900/20 p-3">
      <div class="mb-1.5 flex items-center justify-between gap-2">
        <span class="text-[11px] font-semibold text-brand-200">Prompt sẽ gửi cho AI (chỉnh được)</span>
        <button @click="loadPreview" :disabled="previewLoading" class="btn-ghost btn-sm shrink-0">{{ previewLoading ? 'Đang tải…' : 'Làm mới' }}</button>
      </div>
      <textarea v-model="previewPrompt" @input="onPreviewEdit" rows="6" class="input w-full !text-[11px] leading-relaxed" placeholder="Bấm Làm mới để lấy prompt hiện tại…"></textarea>
      <p class="mt-1 text-[10px] leading-relaxed" :class="previewDirty ? 'text-amber-300' : 'text-cream-300/50'">
        <span v-if="previewDirty">✓ Sẽ gửi bản prompt đã chỉnh này.</span>
        <span v-else>Chưa chỉnh sửa — hệ thống tự dựng prompt từ các tùy chọn. Đổi tùy chọn/ảnh xong bấm "Làm mới".</span>
      </p>
    </div>

    <button @click="run" :disabled="busy || selectedCount < 2 || !prompt.trim()" class="btn-brand mt-3 w-full whitespace-nowrap">
      {{ busy ? 'Đang ghép…' : (variants > 1 ? 'Ghép ' + variants + ' biến thể' : 'Ghép ảnh') }} <span v-if="!busy" class="opacity-70">· {{ variants * store.imageCreditCost }} credit</span>
    </button>

    <!-- Tiến độ (LoadingSpinner dùng chung) -->
    <div v-if="running" class="mt-3 rounded-lg border border-brand-500/30 bg-brand-900/30 p-3">
      <LoadingSpinner
        :text="store.composeStage === 'send' ? 'Đang gửi yêu cầu tới AI…' : 'AI đang ghép ảnh…'"
        :subtext="fmt(elapsedSec) + ' · ' + doneCount + '/' + store.composeGenIds.length + ' biến thể'"
        :progress="doneCount / Math.max(1, store.composeGenIds.length) * 100" />
      <div class="mt-2 flex justify-end">
        <button @click="store.cancelCompose()" class="rounded-full bg-red-600/25 px-2.5 py-1 text-[10px] font-semibold text-red-200 hover:bg-red-600">Hủy</button>
      </div>
    </div>

    <!-- Thành công -->
    <div v-if="store.composeStage === 'done'" class="mt-3 rounded-lg border border-emerald-500/40 bg-emerald-900/25 p-3 text-xs text-emerald-200">
      Đã ghép xong — kết quả đã được chọn trong Outputs.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <!-- Lỗi -->
    <div v-if="store.composeStage === 'error' && store.composeError" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-3 text-xs text-red-200">
      <p class="font-semibold">Ghép ảnh thất bại</p>
      <p class="mt-1 whitespace-pre-line leading-relaxed">{{ store.composeError }}</p>
      <div class="mt-2 flex gap-2">
        <button @click="run" class="btn-brand btn-sm">Thử lại</button>
        <button @click="store.clearComposeStatus()" class="btn-ghost btn-sm">Đóng</button>
      </div>
    </div>

    <!-- Đã hủy -->
    <div v-if="store.composeStage === 'cancelled'" class="mt-3 flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 p-3 text-xs text-cream-200">
      Đã hủy yêu cầu ghép ảnh.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <!-- So sánh Trước/Sau -->
    <button v-if="baseUrl && afterUrl" @click="compareOpen = true" class="btn-outline mt-1.5 w-full whitespace-nowrap">🔍 So sánh Trước/Sau</button>

    <!-- Popup chọn/tải ảnh cho slot (dùng chung Thư viện ảnh nguồn) -->
    <SourceLibraryPicker
      v-model="open"
      :title="'Tải ảnh cho ' + roleLabel(targetSlot) + ' · ' + SLOT_ROLES[targetSlot]"
      mode="pick"
      @pick="onPick" />

    <!-- So sánh Trước/Sau -->
    <CompareSlider v-model="compareOpen" :before="baseUrl" :after="afterUrl" title="So sánh Trước/Sau khi ghép" />
  </div>
</template>
