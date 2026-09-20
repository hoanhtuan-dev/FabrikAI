<script setup>
/**
 * [Yêu cầu 2026-09-22] CARD RIÊNG: "Ghép trang phục" (trước đây là một CHẾ ĐỘ bên trong card "Ghép ảnh").
 *
 * Vì sao tách: hai việc khác hẳn nhau về bản chất và về thao tác —
 *   · Ghép ảnh      = dựng BỐ CỤC (nền chính + ảnh ghép), không có tham số thiết kế;
 *   · Ghép trang phục = LAI TẠO BIẾN THỂ từ 2 trang phục nguồn, có phong cách · mức trang trí ·
 *     mức sáng tạo · preset theo tài khoản · biến thể theo trục.
 * Để chung một card thì người dùng phải đổi chế độ rồi mới thấy đúng công cụ của mình, và card
 * "Ghép ảnh" bị đội thêm 6 khối điều khiển chỉ dùng cho chế độ kia.
 *
 * Dùng CHUNG đường backend /api/compose (mode='outfit') và cài đặt /api/outfit-settings với card
 * Ghép ảnh — cùng một pipeline, hai cửa vào.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import SourceLibraryPicker from './SourceLibraryPicker.vue';
import CompareSlider from './CompareSlider.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';

const store = useStudioStore();

/** Prompt lai tạo mặc định — người dùng sửa được, và bấm lại được nút để khôi phục. */
const OUTFIT_PROMPT = 'lai tạo trang phục mới từ @image1 và @image2: hòa trộn các đặc điểm nổi bật của cả hai (phom dáng, chất liệu, màu sắc, chi tiết) thành biến thể thời trang mới, đúng chuẩn thiết kế thời trang chuyên nghiệp';

const prompt = ref('');
const variants = ref(1);
const creativeLevel = ref(8);   // 1–10: thấp = bám sát 2 trang phục gốc, cao = tự do lai tạo
const style = ref('');          // phong cách thiết kế (nhập tự do) — hướng sáng tạo CHỦ ĐẠO
const ornamentLevel = ref(0);   // 0–10: 0 = tối giản, 10 = cầu kỳ
const busy = ref(false);
const previewOpen = ref(false);
const previewPrompt = ref('');
const previewLoading = ref(false);
const previewDirty = ref(false);
const previewAxes = ref([]);
const stylePresets = ref([]);
const presetName = ref('');
const open = ref(false);
const selected = ref([null, null, null]);      // 3 slot: 2 trang phục nguồn + bối cảnh (tùy chọn)
const targetSlot = ref(0);
const slotImgError = ref([false, false, false]);
const baseUrl = ref('');
const lastIds = ref([]);
const compareOpen = ref(false);
const afterUrl = computed(() => store.generations.find(g => lastIds.value.includes(g.id) && g.status === 'completed')?.media_url || '');

// Tiến trình (dùng chung state với card Ghép ảnh — chỉ một card mở tại một thời điểm)
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
const SLOT_ROLES = ['Trang phục 1', 'Trang phục 2', 'Bối cảnh (tùy chọn)'];

function openSlot(i) { targetSlot.value = i; open.value = true; }
function onPick(img) {
  selected.value[targetSlot.value] = img;
  slotImgError.value[targetSlot.value] = false;
  open.value = false;
}
function removeSlot(i) { selected.value[i] = null; slotImgError.value[i] = false; }
function onSlotImgError(i) { slotImgError.value[i] = true; }
/** Đưa slot i lên làm @image1 (trang phục nguồn chính). */
function makeBase(i) {
  if (i <= 0) return;
  const s = [...selected.value];
  const img = s[i];
  s.splice(i, 1);
  s.unshift(img);
  s.push(null);
  s.length = 3;
  selected.value = s;
}
function roleLabel(i) { return '@image' + (i + 1); }
function insertTag(tag) { prompt.value = (prompt.value ? prompt.value + ' ' : '') + tag + ' '; }
function useSamplePrompt() {
  prompt.value = OUTFIT_PROMPT;
  store.toast('Đã chèn prompt lai tạo mẫu — sửa lại theo ý bạn nếu cần.');
}

onMounted(() => {
  timer = setInterval(() => { now.value = Date.now(); }, 1000);
  // Nạp cài đặt của tài khoản TRƯỚC, rồi mới điền prompt mẫu (để không ghi đè prompt đã lưu).
  loadOutfitSettings().then(() => {
    if (!prompt.value.trim()) prompt.value = OUTFIT_PROMPT;
  });
});
onBeforeUnmount(() => { if (timer) clearInterval(timer); });

async function run() {
  if (selectedCount.value < 2 || busy.value) return;
  const urls = selectedImgs.value.map(g => g.url).filter(Boolean);
  baseUrl.value = urls[0] || '';
  busy.value = true;
  const override = previewDirty.value ? previewPrompt.value : '';
  const items = await store.compose(urls, prompt.value, variants.value, 'outfit', creativeLevel.value, style.value, ornamentLevel.value, override);
  if (items) lastIds.value = items.map(it => it.generation_id).filter(Boolean);
  busy.value = false;
}

// ── Xem trước / chỉnh tay prompt ──
function togglePreview() {
  previewOpen.value = !previewOpen.value;
  if (previewOpen.value) loadPreview();
}
async function loadPreview() {
  if (selectedCount.value < 2) { store.toast('Chọn ít nhất 2 trang phục nguồn để xem trước prompt.', 'error'); return; }
  previewLoading.value = true;
  try {
    const urls = selectedImgs.value.map(g => g.url).filter(Boolean);
    const res = await fetch('/api/compose/preview', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ images: urls, prompt: prompt.value, mode: 'outfit', creative_level: creativeLevel.value, style: style.value, ornament_level: ornamentLevel.value, variants: variants.value }),
    });
    const d = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(d.message || 'Không tải được bản xem trước prompt.');
    previewPrompt.value = d.prompt || '';
    previewAxes.value = Array.isArray(d.axes) ? d.axes : [];
    previewDirty.value = false;
  } catch (e) {
    store.toast(e.message || 'Lỗi tải bản xem trước prompt.', 'error');
  } finally {
    previewLoading.value = false;
  }
}
function onPreviewEdit() { previewDirty.value = true; }

// ── Preset phong cách + cài đặt (lưu database theo tài khoản) ──
async function loadOutfitSettings() {
  try {
    const r = await fetch('/api/outfit-settings', { headers: { Accept: 'application/json' } });
    const d = await r.json();
    if (!r.ok) return;
    style.value = d.style || '';
    ornamentLevel.value = Number(d.ornament_level) ?? 0;
    creativeLevel.value = Number(d.creative_level) ?? 8;
    stylePresets.value = Array.isArray(d.presets) ? d.presets : [];
  } catch (e) { /* giữ mặc định */ }
}
async function persistOutfitSettings() {
  try {
    const res = await fetch('/api/outfit-settings', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        style: style.value,
        ornament_level: Number(ornamentLevel.value) ?? 0,
        creative_level: Number(creativeLevel.value) ?? 8,
        presets: stylePresets.value,
      }),
    });
    const d = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(d.message || 'Không lưu được cài đặt.');
    return true;
  } catch (e) {
    store.toast(e.message || 'Lỗi lưu cài đặt.', 'error');
    return false;
  }
}
function savePreset() {
  const name = presetName.value.trim();
  if (!name) { store.toast('Nhập tên preset.', 'error'); return; }
  const p = { name, style: style.value, ornament: Number(ornamentLevel.value) ?? 0, creative: Number(creativeLevel.value) ?? 8 };
  const i = stylePresets.value.findIndex((x) => x.name === name);
  if (i >= 0) stylePresets.value[i] = p; else stylePresets.value.push(p);
  presetName.value = '';
  persistOutfitSettings();
  store.toast('Đã lưu preset "' + name + '".');
}
function applyPreset(p) {
  style.value = p.style || '';
  ornamentLevel.value = Number(p.ornament) ?? 0;
  creativeLevel.value = Number(p.creative) ?? 8;
  store.toast('Đã áp preset "' + p.name + '".');
}
function deletePreset(p) {
  stylePresets.value = stylePresets.value.filter((x) => x.name !== p.name);
  persistOutfitSettings();
}
function saveSettings() {
  persistOutfitSettings().then((ok) => { if (ok) store.toast('Đã lưu cài đặt Ghép trang phục.'); });
}
</script>

<template>
  <div class="card p-4" style="background: linear-gradient(160deg, rgba(255,170,120,.13), rgba(74,122,144,.06));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="shirt" /> Ghép trang phục</h2>

    <p class="mt-2 rounded-md border border-brand-500/30 bg-brand-900/20 px-2.5 py-1.5 text-[10px] leading-relaxed text-brand-100">
      @image1 + @image2 = trang phục nguồn · @image3 = bối cảnh (tùy chọn) — lai tạo biến thể mới
    </p>

    <!-- 3 slot ảnh: 2 trang phục nguồn + bối cảnh -->
    <div class="mt-3 grid grid-cols-3 gap-2">
      <button v-for="i in 3" :key="i" @click="openSlot(i - 1)" title="Bấm để tải/chọn ảnh"
              class="relative flex h-24 flex-col items-center justify-center overflow-hidden rounded-md border transition"
              :class="selected[i-1] ? 'border-brand-500 bg-ink-900' : 'border-dashed border-ink-600 hover:border-brand-400 bg-ink-900/40'">
        <template v-if="selected[i-1]">
          <img :src="selected[i-1].url" class="h-full w-full object-cover" @error="onSlotImgError(i-1)">
          <span v-if="slotImgError[i-1]" class="absolute inset-0 grid place-items-center bg-ink-900 text-2xl" title="Ảnh không tải được — bấm × để bỏ">🖼️</span>
          <span class="absolute left-1 top-1 rounded-full bg-brand-500 px-1.5 text-[9px] font-bold text-white">{{ i }}</span>
          <span class="absolute inset-x-0 bottom-0 bg-black/65 px-1 py-0.5 text-center text-[9px] font-semibold text-cream-100">{{ SLOT_ROLES[i-1] }}</span>
          <span @click.stop="removeSlot(i-1)" title="Bỏ ảnh khỏi slot" class="motion-ui absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-red-600/90 text-[11px] text-white hover:bg-red-500"><StudioIcon name="x" size="h-3.5 w-3.5" /></span>
          <span v-if="i > 1" @click.stop="makeBase(i-1)" class="absolute bottom-6 right-1 grid h-5 w-5 place-items-center rounded-full bg-ink-800/90 text-[9px] text-white" title="Đưa lên làm @image1">⤴</span>
        </template>
        <template v-else>
          <span class="grid h-6 w-6 place-items-center text-ink-600"><StudioIcon name="shirt" size="h-5 w-5" v-if="i === 1" /><span v-else>＋</span></span>
          <span class="px-1 text-center text-[9px] font-medium text-cream-300/60">{{ SLOT_ROLES[i-1] }}</span>
          <span class="px-1 text-center text-[9px] text-cream-300/40">@image{{ i }}</span>
        </template>
      </button>
    </div>

    <label class="label mt-4">Mô tả lai tạo</label>
    <textarea v-model="prompt" rows="3" maxlength="1000" class="input !text-xs" placeholder="VD: lai tạo trang phục từ phom dáng của @image1 và màu sắc của @image2…"></textarea>
    <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10px]">
      <button v-for="n in 3" :key="n" @click="insertTag('@image' + n)"
              class="rounded-full bg-ink-800 px-2 py-0.5 font-semibold text-brand-300 transition hover:bg-brand-600 hover:text-white">@image{{ n }}</button>
      <button @click="useSamplePrompt" class="rounded-full border border-ink-600 px-2 py-0.5 font-semibold text-cream-200 transition hover:border-brand-400"
              title="Chèn lại prompt lai tạo mẫu của hệ thống">Prompt lai tạo mẫu</button>
    </div>

    <!-- Phong cách + trang trí + mức sáng tạo -->
    <div class="mt-3">
      <label class="label">Phong cách</label>
      <input v-model="style" type="text" maxlength="200" class="input !text-xs" placeholder="VD: tối giản hiện đại, công sở thanh lịch, streetwear, boho, cổ điển…">
      <p class="mt-1 text-[10px] text-cream-300/50">Phong cách là hướng sáng tạo CHỦ ĐẠO — kết quả sẽ bám theo phong cách này.</p>
    </div>
    <div class="mt-3 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-xs">
      <span class="shrink-0 font-medium text-cream-200">Trang trí</span>
      <input type="range" min="0" max="10" v-model.number="ornamentLevel" class="h-2 w-full cursor-pointer accent-brand-500">
      <span class="shrink-0 font-semibold text-cream-50">{{ ornamentLevel }}</span><span class="shrink-0 text-cream-300/60">/10</span>
    </div>
    <p class="mt-1 text-[10px] leading-relaxed text-cream-300/50">0 = tối giản, không họa tiết/đính đá · 10 = cầu kỳ, đính đá &amp; họa tiết đậm.</p>
    <div class="mt-3 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-xs">
      <span class="shrink-0 font-medium text-cream-200">Sáng tạo</span>
      <input type="range" min="1" max="10" v-model.number="creativeLevel" class="h-2 w-full cursor-pointer accent-brand-500">
      <span class="shrink-0 font-semibold text-cream-50">{{ creativeLevel }}</span><span class="shrink-0 text-cream-300/60">/10</span>
    </div>
    <p class="mt-1 text-[10px] leading-relaxed text-cream-300/50">Thấp = bám sát 2 trang phục gốc · Cao = tự do lai tạo, editorial.</p>

    <!-- Preset phong cách + lưu cài đặt (database) -->
    <div class="mt-3">
      <div class="flex items-center justify-between">
        <label class="label mb-0">Preset phong cách</label>
        <button @click="saveSettings" class="btn-ghost btn-sm shrink-0 whitespace-nowrap" title="Lưu phong cách + trang trí + sáng tạo hiện tại vào tài khoản">💾 Lưu cài đặt</button>
      </div>
      <div class="mt-1 flex flex-wrap gap-1.5">
        <button v-for="p in stylePresets" :key="p.name" @click="applyPreset(p)" class="group inline-flex items-center gap-1 rounded-full border border-ink-600 bg-ink-800 px-2.5 py-1 text-[10px] font-medium text-cream-200 transition hover:border-brand-400">
          {{ p.name }}
          <span @click.stop="deletePreset(p)" class="motion-ui grid h-4 w-4 place-items-center rounded-full text-cream-400 hover:bg-red-600 hover:text-white" title="Xóa preset">×</span>
        </button>
        <span v-if="!stylePresets.length" class="text-[10px] text-cream-300/50">Chưa có preset — lưu phong cách + trang trí + sáng tạo hiện tại để tái dùng cho cả bộ sưu tập.</span>
      </div>
      <div class="mt-1.5 flex gap-1.5">
        <input v-model="presetName" type="text" maxlength="60" class="input !py-1.5 !text-xs" placeholder="Tên preset (VD: Bộ sưu tập Xuân)">
        <button @click="savePreset" class="btn-ghost btn-sm shrink-0 whitespace-nowrap">Lưu preset</button>
      </div>
    </div>

    <!-- Số biến thể -->
    <div class="mt-3 flex items-center gap-1.5 text-xs text-cream-200">
      <span class="mr-1">Số biến thể:</span>
      <button v-for="n in [1,2,3,4]" :key="n" @click="variants = n"
              :class="variants === n ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'"
              class="h-7 w-7 rounded-full font-semibold transition-colors">{{ n }}</button>
    </div>
    <p v-if="variants > 1" class="mt-1 text-[10px] leading-relaxed text-cream-300/50">Biến thể đi theo trục khác nhau để không trùng lặp: Classic · Modern · Bold · Fluid.</p>

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
      <p v-if="previewAxes.length > 1" class="mt-1 text-[10px] leading-relaxed text-brand-200/80">
        Biến thể theo trục ({{ variants }} biến thể): mỗi biến thể thêm 1 chỉ thị phom dáng/tâm trạng riêng.<br>
        <span class="text-cream-300/60">1·Classic · 2·Modern · 3·Bold · 4·Fluid — nếu bạn chỉnh tay prompt trên, trục sẽ tắt.</span>
      </p>
    </div>

    <button @click="run" :disabled="busy || selectedCount < 2 || !prompt.trim()" class="btn-brand mt-3 w-full whitespace-nowrap">
      {{ busy ? 'Đang lai tạo…' : (variants > 1 ? 'Lai tạo ' + variants + ' biến thể' : 'Ghép trang phục') }} <span v-if="!busy" class="opacity-70">· {{ variants * store.imageCreditCost }} credit</span>
    </button>

    <!-- Tiến độ -->
    <div v-if="running" class="mt-3 rounded-lg border border-brand-500/30 bg-brand-900/30 p-3">
      <LoadingSpinner
        :text="store.composeStage === 'send' ? 'Đang gửi yêu cầu tới AI…' : 'AI đang lai tạo trang phục…'"
        :subtext="fmt(elapsedSec) + ' · ' + doneCount + '/' + store.composeGenIds.length + ' biến thể'"
        :progress="doneCount / Math.max(1, store.composeGenIds.length) * 100" />
      <div class="mt-2 flex justify-end">
        <button @click="store.cancelCompose()" class="rounded-full bg-red-600/25 px-2.5 py-1 text-[10px] font-semibold text-red-200 hover:bg-red-600">Hủy</button>
      </div>
    </div>

    <!-- Thành công -->
    <div v-if="store.composeStage === 'done'" class="mt-3 rounded-lg border border-emerald-500/40 bg-emerald-900/25 p-3 text-xs text-emerald-200">
      Đã lai tạo xong — kết quả đã được chọn trong Outputs.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <!-- Lỗi -->
    <div v-if="store.composeStage === 'error' && store.composeError" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-3 text-xs text-red-200">
      <p class="font-semibold">Ghép trang phục thất bại</p>
      <p class="mt-1 whitespace-pre-line leading-relaxed">{{ store.composeError }}</p>
      <div class="mt-2 flex gap-2">
        <button @click="run" class="btn-brand btn-sm">Thử lại</button>
        <button @click="store.clearComposeStatus()" class="btn-ghost btn-sm">Đóng</button>
      </div>
    </div>

    <!-- Đã hủy -->
    <div v-if="store.composeStage === 'cancelled'" class="mt-3 flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 p-3 text-xs text-cream-200">
      Đã hủy yêu cầu ghép trang phục.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <!-- So sánh Trước/Sau -->
    <button v-if="baseUrl && afterUrl" @click="compareOpen = true" class="btn-outline mt-1.5 w-full whitespace-nowrap">🔍 So sánh Trước/Sau</button>

    <SourceLibraryPicker
      v-model="open"
      :title="'Tải ảnh cho ' + roleLabel(targetSlot) + ' · ' + SLOT_ROLES[targetSlot]"
      mode="pick"
      @pick="onPick" />

    <CompareSlider v-model="compareOpen" :before="baseUrl" :after="afterUrl" title="So sánh Trước/Sau khi ghép trang phục" />
  </div>
</template>
