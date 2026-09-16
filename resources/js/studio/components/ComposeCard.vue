<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import SourceLibraryPicker from './SourceLibraryPicker.vue';
import CompareSlider from './CompareSlider.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';
const store = useStudioStore();

const prompt = ref('');
const variants = ref(1);
const creativeLevel = ref(8);   // mức độ sáng tạo (1–10) — dùng cho chế độ Ghép Trang Phục
const style = ref('');          // phong cách thiết kế (nhập tự do) — dùng cho chế độ Ghép Trang Phục
const ornamentLevel = ref(0);   // mức độ trang trí (0–10; 0 = tối giản, 10 = cầu kỳ) — dùng cho chế độ Ghép Trang Phục
const busy = ref(false);
const previewOpen = ref(false);
const previewPrompt = ref('');
const previewLoading = ref(false);
const previewDirty = ref(false);
const previewAxes = ref([]);
const stylePresets = ref([]);   // preset phong cách (lưu database theo tài khoản)
const presetName = ref('');
const mode = ref('compose'); // 'compose' | 'faceswap' | 'outfit'
const open = ref(false);
const selected = ref([null, null, null]); // 3 slot cố định: image object hoặc null
const targetSlot = ref(0);  // slot đang chọn trong popup
const slotImgError = ref([false, false, false]); // ảnh slot bị lỗi (404/broken) — vẫn cho xóa

const baseUrl = ref('');
const lastIds = ref([]);
const compareOpen = ref(false);
const afterUrl = computed(() => store.generations.find(g => lastIds.value.includes(g.id) && g.status === 'completed')?.media_url || '');

// Tiến trình (giống Inpaint)
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

// Vai trò slot theo chế độ
const slotRoles = computed(() => mode.value === 'faceswap'
  ? ['Người mẫu', 'Khuôn mặt', 'Ảnh ghép (tùy chọn)']
  : mode.value === 'outfit'
    ? ['Trang phục 1', 'Trang phục 2', 'Bối cảnh (tùy chọn)']
    : ['Nền chính', 'Ảnh ghép', 'Ảnh ghép']);

// Vòng viền màu theo vai trò slot
const slotRingClass = 'border-brand-500';
const slotRingEmptyClass = 'border-dashed border-ink-700 hover:border-brand-400';

const promptPlaceholder = computed(() => mode.value === 'outfit'
  ? 'VD: lai tạo trang phục từ phom dáng của @image1 và màu sắc của @image2…'
  : mode.value === 'faceswap'
    ? 'VD: thay khuôn mặt @image2 vào người mẫu @image1…'
    : 'VD: giữ nguyên @image1, đặt cô gái trong @image2 vào nền studio…');

function setMode(m) {
  if (m === 'faceswap') setFaceSwap();
  else if (m === 'outfit') setOutfit();
  else setCompose();
}

function setFaceSwap() {
  mode.value = 'faceswap';
  prompt.value = 'thay khuôn mặt của @image1 bằng khuôn mặt trong @image2, giữ nguyên dáng, trang phục, bối cảnh';
  store.toast('Thay khuôn mặt: @image1 = người mẫu, @image2 = khuôn mặt, @image3 = ảnh ghép (tùy chọn).');
}
function setOutfit() {
  mode.value = 'outfit';
  prompt.value = 'lai tạo trang phục mới từ @image1 và @image2: hòa trộn các đặc điểm nổi bật của cả hai (phom dáng, chất liệu, màu sắc, chi tiết) thành biến thể thời trang mới, đúng chuẩn thiết kế thời trang chuyên nghiệp';
  store.toast('Ghép Trang Phục: @image1 + @image2 = trang phục, @image3 = bối cảnh (tùy chọn).');
}
function setCompose() {
  mode.value = 'compose';
}

function insertTag(tag) {
  prompt.value = (prompt.value ? prompt.value + ' ' : '') + tag + ' ';
}

onMounted(() => {
  timer = setInterval(() => { now.value = Date.now(); }, 1000);
  loadOutfitSettings();
});
onBeforeUnmount(() => { if (timer) clearInterval(timer); });

async function run() {
  if (selectedCount.value < 2 || busy.value) return;
  const urls = selectedImgs.value.map(g => g.url).filter(Boolean);
  baseUrl.value = urls[0] || '';
  busy.value = true;
  const override = previewDirty.value ? previewPrompt.value : '';
  const items = await store.compose(urls, prompt.value, variants.value, mode.value, creativeLevel.value, style.value, ornamentLevel.value, override);
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
      body: JSON.stringify({ images: urls, prompt: prompt.value, mode: mode.value, creative_level: creativeLevel.value, style: style.value, ornament_level: ornamentLevel.value, variants: variants.value }),
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
  persistOutfitSettings().then((ok) => { if (ok) store.toast('Đã lưu cài đặt Ghép Trang Phục.'); });
}
</script>
<template>
  <div class="card p-4" style="background: linear-gradient(160deg, rgba(255,170,120,.13), rgba(74,122,144,.06));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="puzzle" /> Ghép ảnh</h2>

    <!-- Chọn chế độ: segmented tabs 1 hàng (lean) -->
    <div class="mt-3 grid grid-cols-3 gap-1 rounded-lg border border-white/10 bg-ink-900/60 p-1">
      <button @click="setMode('compose')" title="Ghép tự do: hòa trộn nhiều ảnh"
              :class="mode === 'compose' ? 'bg-brand-600 text-white shadow' : 'text-cream-200 hover:bg-ink-800'"
              class="flex flex-col items-center justify-center gap-0.5 rounded-md px-1 py-1.5 text-[9px] font-semibold leading-tight transition-colors">
        <StudioIcon name="layers" size="h-4 w-4" /> Ghép tự do
      </button>
      <button @click="setMode('faceswap')" title="Thay khuôn mặt người mẫu"
              :class="mode === 'faceswap' ? 'bg-brand-600 text-white shadow' : 'text-cream-200 hover:bg-ink-800'"
              class="flex flex-col items-center justify-center gap-0.5 rounded-md px-1 py-1.5 text-[9px] font-semibold leading-tight transition-colors">
        <StudioIcon name="user" size="h-4 w-4" /> Thay mặt
      </button>
      <button @click="setMode('outfit')" title="Ghép Trang Phục: lai tạo biến thể từ 2 trang phục"
              :class="mode === 'outfit' ? 'bg-brand-600 text-white shadow' : 'text-cream-200 hover:bg-ink-800'"
              class="flex flex-col items-center justify-center gap-0.5 rounded-md px-1 py-1.5 text-[9px] font-semibold leading-tight transition-colors">
        <StudioIcon name="shirt" size="h-4 w-4" /> Ghép trang phục
      </button>
    </div>

    <!-- Hướng dẫn slot theo chế độ -->
    <p v-if="mode === 'faceswap'" class="mt-2 rounded-md border border-brand-500/30 bg-brand-900/20 px-2.5 py-1.5 text-[10px] leading-relaxed text-brand-100">
      @image1 = người mẫu · @image2 = khuôn mặt · @image3 = ảnh ghép (tùy chọn)
    </p>
    <p v-if="mode === 'outfit'" class="mt-2 rounded-md border border-brand-500/30 bg-brand-900/20 px-2.5 py-1.5 text-[10px] leading-relaxed text-brand-100">
      @image1 + @image2 = trang phục nguồn · @image3 = bối cảnh (tùy chọn) — lai tạo biến thể mới
    </p>

    <!-- 3 slot ảnh: bấm để tải/chọn -->
    <div class="mt-3 grid grid-cols-3 gap-2">
      <button v-for="i in 3" :key="i" @click="openSlot(i - 1)" title="Bấm để tải/chọn ảnh"
              class="relative flex h-24 flex-col items-center justify-center overflow-hidden rounded-md border transition"
              :class="selected[i-1] ? slotRingClass + ' bg-ink-900' : slotRingEmptyClass + ' bg-ink-900/40'">
        <template v-if="selected[i-1]">
          <img :src="selected[i-1].url" class="h-full w-full object-cover" @error="onSlotImgError(i-1)">
          <span v-if="slotImgError[i-1]" class="absolute inset-0 grid place-items-center bg-ink-900 text-2xl" title="Ảnh không tải được — bấm × để bỏ">🖼️</span>
          <span class="absolute left-1 top-1 rounded-full bg-brand-500 px-1.5 text-[9px] font-bold text-white">{{ i }}</span>
          <span class="absolute inset-x-0 bottom-0 bg-black/65 px-1 py-0.5 text-center text-[9px] font-semibold text-cream-100">{{ slotRoles[i-1] }}</span>
          <span @click.stop="removeSlot(i-1)" title="Bỏ ảnh khỏi slot" class="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-red-600/90 text-[11px] text-white hover:bg-red-500"><StudioIcon name="x" size="h-3.5 w-3.5" /></span>
          <span v-if="i > 1" @click.stop="makeBase(i-1)" class="absolute bottom-6 right-1 grid h-5 w-5 place-items-center rounded-full bg-ink-800/90 text-[9px] text-white" title="Đưa lên làm @image1">⤴</span>
        </template>
        <template v-else>
          <span class="grid h-6 w-6 place-items-center text-ink-600"><StudioIcon name="image" size="h-5 w-5" v-if="i === 1" /><span v-else>＋</span></span>
          <span class="px-1 text-center text-[9px] font-medium text-cream-300/60">{{ slotRoles[i-1] }}</span>
          <span class="px-1 text-center text-[9px] text-cream-300/40">@image{{ i }}</span>
        </template>
      </button>
    </div>

    <label class="label mt-4">Mô tả ghép</label>
    <textarea v-model="prompt" rows="3" maxlength="1000" class="input !text-xs" :placeholder="promptPlaceholder"></textarea>
    <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10px]">
      <button v-for="n in 3" :key="n" @click="insertTag('@image' + n)"
              class="rounded-full bg-ink-800 px-2 py-0.5 font-semibold text-brand-300 transition hover:bg-brand-600 hover:text-white">@image{{ n }}</button>
    </div>

    <!-- Phong cách + trang trí + mức sáng tạo (chỉ cho chế độ Ghép Trang Phục) -->
    <div v-if="mode === 'outfit'" class="mt-3">
      <label class="label">Phong cách</label>
      <input v-model="style" type="text" maxlength="200" class="input !text-xs" placeholder="VD: tối giản hiện đại, công sở thanh lịch, streetwear, boho, cổ điển…">
      <p class="mt-1 text-[10px] text-cream-300/50">Phong cách là hướng sáng tạo CHỦ ĐẠO — kết quả sẽ bám theo phong cách này.</p>
    </div>
    <div v-if="mode === 'outfit'" class="mt-3 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-xs">
      <span class="shrink-0 font-medium text-cream-200">Trang trí</span>
      <input type="range" min="0" max="10" v-model.number="ornamentLevel" class="h-2 w-full cursor-pointer accent-brand-500">
      <span class="shrink-0 font-semibold text-cream-50">{{ ornamentLevel }}</span><span class="shrink-0 text-cream-300/60">/10</span>
    </div>
    <p v-if="mode === 'outfit'" class="mt-1 text-[10px] leading-relaxed text-cream-300/50">0 = tối giản, không họa tiết/đính đá · 10 = cầu kỳ, đính đá & họa tiết đậm.</p>
    <div v-if="mode === 'outfit'" class="mt-3 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-xs">
      <span class="shrink-0 font-medium text-cream-200">Sáng tạo</span>
      <input type="range" min="1" max="10" v-model.number="creativeLevel" class="h-2 w-full cursor-pointer accent-brand-500">
      <span class="shrink-0 font-semibold text-cream-50">{{ creativeLevel }}</span><span class="shrink-0 text-cream-300/60">/10</span>
    </div>
    <p v-if="mode === 'outfit'" class="mt-1 text-[10px] leading-relaxed text-cream-300/50">Thấp = bám sát 2 trang phục gốc · Cao = tự do lai tạo, editorial.</p>

    <!-- Preset phong cách + lưu cài đặt (database) -->
    <div v-if="mode === 'outfit'" class="mt-3">
      <div class="flex items-center justify-between">
        <label class="label mb-0">Preset phong cách</label>
        <button @click="saveSettings" class="btn-ghost btn-sm shrink-0 whitespace-nowrap" title="Lưu phong cách + trang trí + sáng tạo hiện tại vào tài khoản">💾 Lưu cài đặt</button>
      </div>
      <div class="mt-1 flex flex-wrap gap-1.5">
        <button v-for="p in stylePresets" :key="p.name" @click="applyPreset(p)" class="group inline-flex items-center gap-1 rounded-full border border-ink-600 bg-ink-800 px-2.5 py-1 text-[10px] font-medium text-cream-200 transition hover:border-brand-400">
          {{ p.name }}
          <span @click.stop="deletePreset(p)" class="grid h-4 w-4 place-items-center rounded-full text-cream-400 hover:bg-red-600 hover:text-white" title="Xóa preset">×</span>
        </button>
        <span v-if="!stylePresets.length" class="text-[10px] text-cream-300/50">Chưa có preset — lưu phong cách + trang trí + sáng tạo hiện tại để tái dùng cho cả bộ sưu tập.</span>
      </div>
      <div class="mt-1.5 flex gap-1.5">
        <input v-model="presetName" type="text" maxlength="60" class="input !py-1.5 !text-xs" placeholder="Tên preset (VD: Bộ sưu tập Xuân)">
        <button @click="savePreset" class="btn-ghost btn-sm shrink-0 whitespace-nowrap">Lưu preset</button>
      </div>
    </div>

    <!-- Số biến thể -->
    <div v-if="mode !== 'outfit'" class="mt-3 flex items-center gap-1.5 text-xs text-cream-200">
      <span class="mr-1">Số biến thể:</span>
      <button v-for="n in [1,2,3,4]" :key="n" @click="variants = n"
              :class="variants === n ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'"
              class="h-7 w-7 rounded-full font-semibold transition-colors">{{ n }}</button>
    </div>
    <div v-if="mode === 'outfit'" class="mt-3 flex items-center gap-1.5 text-xs text-cream-200">
      <span class="mr-1">Số biến thể:</span>
      <button v-for="n in [1,2,3,4]" :key="n" @click="variants = n"
              :class="variants === n ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'"
              class="h-7 w-7 rounded-full font-semibold transition-colors">{{ n }}</button>
    </div>
    <p v-if="mode === 'outfit' && variants > 1" class="mt-1 text-[10px] leading-relaxed text-cream-300/50">Biến thể đi theo trục khác nhau để không trùng lặp: Classic · Modern · Bold · Fluid.</p>

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
      <p v-if="previewAxes.length > 1 && mode === 'outfit'" class="mt-1 text-[10px] leading-relaxed text-brand-200/80">
        Biến thể theo trục ({{ variants }} biến thể): mỗi biến thể thêm 1 chỉ thị phom dáng/tâm trạng riêng.<br>
        <span class="text-cream-300/60">1·Classic · 2·Modern · 3·Bold · 4·Fluid — nếu bạn chỉnh tay prompt trên, trục sẽ tắt.</span>
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
      :title="'Tải ảnh cho ' + roleLabel(targetSlot) + ' · ' + slotRoles[targetSlot]"
      mode="pick"
      @pick="onPick" />

    <!-- So sánh Trước/Sau -->
    <CompareSlider v-model="compareOpen" :before="baseUrl" :after="afterUrl" title="So sánh Trước/Sau khi ghép" />
  </div>
</template>