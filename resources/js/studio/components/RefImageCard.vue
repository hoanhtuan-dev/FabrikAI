<script setup>
import { ref, computed, onMounted } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';

const store = useStudioStore();

// Card "Ảnh mới từ ảnh mẫu" (i2i): model SINH ẢNH (qwen-image-3.0-pro) nhận ảnh tham chiếu
// làm base và tạo 1 bức ảnh HOÀN TOÀN MỚI giống ảnh mẫu theo % tương đồng — KHÔNG phải edit.
// 2 chế độ chip (giống Card Ghép ảnh):
//   - "Tạo ảnh mới" (refgen thường): giữ chủ thể/phong cách theo % tương đồng + nền/góc chụp.
//   - "Thử đồ" (tryon sinh ảnh): dùng ảnh đang chọn làm TRANG PHỤC → sinh người mẫu mặc đúng đồ
//     (rẻ hơn Thử đồ ảo edit). Kế thừa body/hair directive (Tạo ảnh 2D) + khuôn mặt mẫu + pose mẫu.

const img = computed(() => store.upscaleSrc || store.preview?.media_url || '');
const imgName = computed(() => store.upscaleName || (store.preview ? 'Ảnh kết quả #' + store.preview.id : 'Ảnh đang chọn'));

const mode = ref('refgen'); // 'refgen' | 'tryon'
const prompt = ref('');
// Prompt riêng cho từng chế độ — chuyển chip sẽ tự khôi phục/giữ prompt của chế độ đó.
const refgenPrompt = ref('');
const tryonPrompt = ref('mặc trang phục trong ảnh lên người mẫu thời trang, giữ nguyên màu sắc, chất liệu, họa tiết và phụ kiện, pose đứng tự nhiên, ánh sáng studio');
const similarity = ref(70);
const variants = ref(1);
const busy = ref(false);

// ── Khuôn mặt mẫu + Pose mẫu (FacePreset / PosePreset từ cài đặt) ──
const faces = ref([]);
const faceModelId = ref('');
const poses = ref([]);
const poseId = ref('');
// Chỉ 1 chip mở tại 1 thời điểm (accordion) — '' | 'face' | 'body' | 'pose' | 'bg'
const openPanel = ref('');
function togglePanel(name) {
  openPanel.value = openPanel.value === name ? '' : name;
}
// Chip Nền Studio (chế độ "Tạo ảnh mới") — giống chip "Thử đồ": bấm mở lưới 2x
const bgOpenRefgen = ref(false);
onMounted(async () => {
  try {
    const r = await fetch('/api/swap-models', { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    if (Array.isArray(d.items)) faces.value = d.items;
  } catch (e) { /* giữ mặc định */ }
  try {
    const r = await fetch('/api/swap-poses', { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    if (Array.isArray(d.items)) poses.value = d.items;
  } catch (e) { /* giữ mặc định */ }
});
const selectedFace = computed(() => faces.value.find(f => String(f.id) === String(faceModelId.value)) || null);
const selectedPose = computed(() => poses.value.find(p => String(p.id) === String(poseId.value)) || null);

function setMode(m) {
  if (m === mode.value) return;
  // Lưu prompt hiện tại về chế độ cũ trước khi chuyển.
  if (mode.value === 'refgen') refgenPrompt.value = prompt.value;
  else if (mode.value === 'tryon') tryonPrompt.value = prompt.value;

  mode.value = m;
  if (m === 'tryon') {
    prompt.value = tryonPrompt.value;
    similarity.value = 85; // tryon cần độ giống cao (bám mẫu trang phục)
  } else {
    prompt.value = refgenPrompt.value;
  }
}

// Không còn dropdown chọn model trên card này: backend refgen đã mặc định dùng model
// sinh ảnh (qwen-image-3.0-pro / qwen_model trong Cài đặt) và tự fallback đúng model
// sinh ảnh — không cần người dùng chọn. selectedModel = null → backend dùng default settings.

// Cho phép tạo ngay cả khi chưa nhập mô tả — backend tự dựng prompt "create a fresh variation".
const canSubmit = computed(() => !!img.value && !busy.value);

// ── Nền Studio: 16 màu nền studio (dùng chung cho cả "Tạo ảnh mới" và "Thử đồ") ──
const presets = [
  { id: 'studio-white',  label: 'Trắng thuần',  color: '#ffffff', similarity: 82, prompt: 'keep the subject unchanged; replace the background with a pure-white seamless studio backdrop, even soft diffused lighting, no harsh shadows, clean editorial fashion look' },
  { id: 'studio-lgray',  label: 'Xám nhạt',     color: '#e8e8e8', similarity: 80, prompt: 'keep the subject unchanged; replace the background with a light neutral gray seamless studio backdrop, soft top-down diffused lighting, subtle gradient' },
  { id: 'studio-mgray',  label: 'Xám trung',    color: '#9a9a9a', similarity: 78, prompt: 'keep the subject unchanged; replace the background with an 18% medium gray seamless studio backdrop, professional softbox lighting, balanced contrast' },
  { id: 'studio-storm',  label: 'Xám đậm',      color: '#4a4a4a', similarity: 76, prompt: 'keep the subject unchanged; replace the background with a dark storm-gray studio backdrop with a soft rim light and subtle gradient, dramatic fashion mood' },
  { id: 'studio-black',  label: 'Đen tuyền',    color: '#0a0a0a', similarity: 75, prompt: 'keep the subject unchanged; replace the background with a jet-black seamless studio backdrop, deep shadow, single soft key light, high-contrast editorial mood' },
  { id: 'studio-cream',  label: 'Kem',          color: '#f2e7d5', similarity: 80, prompt: 'keep the subject unchanged; replace the background with a warm cream/beige seamless studio backdrop, soft warm diffuse lighting, clean catalog look' },
  { id: 'studio-blue',   label: 'Xanh phấn',    color: '#c9d8e0', similarity: 78, prompt: 'keep the subject unchanged; replace the background with a soft powder-blue seamless studio backdrop, cool soft lighting, calm editorial mood' },
  { id: 'studio-blush',  label: 'Hồng phấn',    color: '#ead3d5', similarity: 78, prompt: 'keep the subject unchanged; replace the background with a dusty blush-pink seamless studio backdrop, soft warm diffuse lighting, fashion lookbook mood' },
  { id: 'studio-lavender', label: 'Tím oải hương', color: '#d9d2e9', similarity: 78, prompt: 'keep the subject unchanged; replace the background with a soft lavender seamless studio backdrop, gentle diffused lighting, calm elegant mood' },
  { id: 'studio-mint',   label: 'Xanh bạc hà',  color: '#cfe8dd', similarity: 78, prompt: 'keep the subject unchanged; replace the background with a fresh mint-green seamless studio backdrop, soft even lighting, clean airy look' },
  { id: 'studio-peach',  label: 'Hồng đào',     color: '#f8d9c0', similarity: 80, prompt: 'keep the subject unchanged; replace the background with a warm peach seamless studio backdrop, soft flattering lighting, fresh beauty look' },
  { id: 'studio-sand',   label: 'Cát',          color: '#e7dcc6', similarity: 78, prompt: 'keep the subject unchanged; replace the background with a warm sand/beige seamless studio backdrop, soft natural lighting, earthy neutral mood' },
  { id: 'studio-sage',   label: 'Xanh xô thơm', color: '#c8d6c0', similarity: 76, prompt: 'keep the subject unchanged; replace the background with a muted sage-green seamless studio backdrop, soft natural lighting, organic calm mood' },
  { id: 'studio-rose',   label: 'Hồng cánh sen', color: '#f0cdd5', similarity: 78, prompt: 'keep the subject unchanged; replace the background with a soft rose-pink seamless studio backdrop, gentle diffused lighting, romantic editorial mood' },
  { id: 'studio-sky',    label: 'Xanh da trời', color: '#cfe3f2', similarity: 78, prompt: 'keep the subject unchanged; replace the background with a light sky-blue seamless studio backdrop, airy bright lighting, fresh clean look' },
  { id: 'studio-lilac',  label: 'Tím nhạt',     color: '#e3d9f0', similarity: 78, prompt: 'keep the subject unchanged; replace the background with a pale lilac seamless studio backdrop, soft dreamy lighting, delicate fashion mood' },
];
const activePreset = ref(null);
// Chỉ lưu lựa chọn + thông báo cho người dùng — KHÔNG nối text nền vào ô prompt.
function applyPreset(p) {
  if (activePreset.value === p.id) {
    activePreset.value = null;
    store.toast('Đã bỏ chọn nền studio.');
    return;
  }
  activePreset.value = p.id;
  similarity.value = p.similarity;
  store.toast('Đã chọn nền: ' + p.label);
}
const activeBgLabel = computed(() => { const p = presets.find(x => x.id === activePreset.value); return p ? p.label : ''; });
const activeBgPrompt = computed(() => { const p = presets.find(x => x.id === activePreset.value); return p ? p.prompt : ''; });

// ── Góc chụp: 4 hướng camera (chỉ chế độ "Tạo ảnh mới") ──
const anglePresets = [
  { id: 'angle-front',  label: 'Chính diện', similarity: 70, prompt: 'keep the subject, garment and styling unchanged; shoot from a straight-on front view, eye-level camera, symmetrical framing, flat even studio lighting', svg: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/><circle cx="17" cy="10" r="0.6"/>' },
  { id: 'angle-back',  label: 'Mặt sau', similarity: 65, prompt: 'keep the subject, garment and styling unchanged; shoot from directly behind the subject (back view), eye-level camera, same lighting, full back of garment visible', svg: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/><path d="M10 11l4 4"/><path d="M14 11l-4 4"/>' },
  { id: 'angle-left45',  label: 'Nghiêng 45° trái', similarity: 62, prompt: 'keep the subject, garment and styling unchanged; shoot from a 45-degree three-quarter front-left angle, camera slightly to the left and slightly above eye level, same lighting and framing', svg: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/><path d="M6 6l4 4"/><path d="M6 10h4V6"/>' },
  { id: 'angle-right45',  label: 'Nghiêng 45° phải', similarity: 62, prompt: 'keep the subject, garment and styling unchanged; shoot from a 45-degree three-quarter front-right angle, camera slightly to the right and slightly above eye level, same lighting and framing', svg: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/><path d="M18 6l-4 4"/><path d="M18 10h-4V6"/>' },
];
const activeAngle = ref(null);
// Chỉ lưu lựa chọn + thông báo cho người dùng — KHÔNG nối text góc chụp vào ô prompt.
function applyAngle(a) {
  if (activeAngle.value === a.id) {
    activeAngle.value = null;
    store.toast('Đã bỏ chọn góc chụp.');
    return;
  }
  activeAngle.value = a.id;
  similarity.value = a.similarity;
  store.toast('Đã chọn góc chụp: ' + a.label);
}
const activeAngleLabel = computed(() => { const a = anglePresets.find(x => x.id === activeAngle.value); return a ? a.label : ''; });
const activeAnglePrompt = computed(() => { const a = anglePresets.find(x => x.id === activeAngle.value); return a ? a.prompt : ''; });

// ── Body directive labels (kế thừa từ "Tạo ảnh 2D") ──
const bodyHeightLabel = computed(() => { const v = store.bodyHeight; if (v <= 2) return 'Rất thấp'; if (v <= 4) return 'Hơi thấp'; if (v <= 6) return 'Trung bình'; if (v <= 8) return 'Cao'; return 'Siêu cao'; });
const bodyBuildLabel = computed(() => { const v = store.bodyBuild; if (v <= 2) return 'Siêu gầy'; if (v <= 4) return 'Thon gọn'; if (v <= 6) return 'Cân đối'; if (v <= 8) return 'Đầy đặn'; return 'Curvy'; });
const bodyWaistLabel = computed(() => { const v = store.bodyWaist; if (v <= 2) return 'Thẳng'; if (v <= 4) return 'Ít eo'; if (v <= 6) return 'Cân đối'; if (v <= 8) return 'Eo thon'; return 'Đồng hồ cát'; });
const bodyShouldersLabel = computed(() => { const v = store.bodyShoulders; if (v <= 2) return 'Rất hẹp'; if (v <= 4) return 'Hẹp'; if (v <= 6) return 'Cân đối'; if (v <= 8) return 'Rộng'; return 'Rất rộng'; });
const bodyHipsLabel = computed(() => { const v = store.bodyHips; if (v <= 2) return 'Rất hẹp'; if (v <= 4) return 'Hẹp'; if (v <= 6) return 'Cân đối'; if (v <= 8) return 'Nở'; return 'Rất nở'; });
const bodyTouched = computed(() => store.bodyHeight !== 5 || store.bodyBuild !== 5 || store.bodyWaist !== 5 || store.bodyShoulders !== 5 || store.bodyHips !== 5);

async function runRefgen() {
  if (!canSubmit.value) return;
  busy.value = true;
  // Ảnh tham chiếu có thể là data:URL (canvas flattened) → backend downscaleSource xử lý.
  // Model: selector trên card (imageModelSel — dùng chung nhóm image với Tạo Ảnh 2D);
  // '' = default nhóm image từ Cài đặt → 🎯 Nhóm công việc.
  // Thử đồ: gửi tryon=true + body directive từ store + khuôn mặt mẫu (ảnh, mô tả do vision đọc).
  const isTryon = mode.value === 'tryon';
  const body = isTryon ? { height: store.bodyHeight, build: store.bodyBuild, waist: store.bodyWaist, shoulders: store.bodyShoulders, hips: store.bodyHips } : null;
  const selModel = store.imageModelSel && store.imageModelSel.includes(':')
    ? (([p, m]) => ({ provider: p, model: m }))(store.imageModelSel.split(':'))
    : null;
  // Nền Studio áp dụng cho cả 2 chế độ; Góc chụp chỉ cho "Tạo ảnh mới".
  const items = await store.refgen(
    img.value, prompt.value.trim(), similarity.value, variants.value, selModel, isTryon, body,
    isTryon ? faceModelId.value : '', isTryon ? poseId.value : '',
    activeBgPrompt.value, isTryon ? '' : activeAnglePrompt.value,
  );
  busy.value = false;
  if (items && items.length) {
    store.toast('Đã gửi ' + items.length + ' ảnh mới — đang tạo…');
  }
}
</script>

<template>
  <div class="card p-5" style="background: linear-gradient(160deg, rgba(124,200,90,.13), rgba(74,122,144,.06));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300">
      <StudioIcon name="image" /> Ảnh mới từ ảnh mẫu
      <span class="rounded-full bg-brand-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-brand-200">i2i</span>
    </h2>

    <!-- Chọn chế độ: segmented tabs (giống Card Ghép ảnh) -->
    <div class="mt-3 seg">
      <button @click="setMode('refgen')" title="Tạo ảnh mới giống ảnh mẫu"
              :class="mode === 'refgen' ? 'is-active' : ''"
              class="seg-btn">
        <StudioIcon name="image" size="h-4 w-4" /> Tạo ảnh mới
      </button>
      <button @click="setMode('tryon')" title="Dùng ảnh làm trang phục → sinh người mẫu mặc đúng đồ (rẻ hơn edit)"
              :class="mode === 'tryon' ? 'is-active' : ''"
              class="seg-btn">
        <StudioIcon name="shirt" size="h-4 w-4" /> Thử đồ
      </button>
    </div>

    <!-- Ảnh tham chiếu -->
    <div v-if="img" class="mt-3 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-2.5">
      <img :src="img" class="h-14 w-14 rounded-md bg-ink-900 object-cover">
      <div class="min-w-0 text-xs text-cream-200">
        <p class="truncate font-semibold">{{ imgName }}</p>
        <p class="text-cream-300/60">{{ mode === 'tryon' ? 'Ảnh trang phục — sinh người mẫu mặc đúng đồ này' : 'Ảnh tham chiếu — giữ chủ thể/phong cách/bố cục' }}</p>
      </div>
    </div>
    <div v-else class="mt-3 rounded-lg border border-dashed border-white/15 bg-white/5 p-3 text-xs text-cream-300/60">Chọn một ảnh trong <b>Outputs</b> để làm {{ mode === 'tryon' ? 'trang phục' : 'ảnh tham chiếu' }}.</div>

    <!-- ============ CHẾ ĐỘ: TẠO ẢNH MỚI (refgen) ============ -->
    <template v-if="mode === 'refgen'">
      <!-- Nền Studio (chip toggle giống Thử đồ) -->
      <button @click="bgOpenRefgen = !bgOpenRefgen"
              class="mt-4 flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-xs font-semibold transition"
              :class="bgOpenRefgen ? 'border-brand-400 bg-brand-600/20 text-brand-100' : 'border-ink-700 bg-ink-800 text-cream-200 hover:border-brand-400/50'">
        <span class="flex items-center gap-2"><StudioIcon name="background" size="h-4 w-4" class="text-brand-300" /> Nền Studio</span>
        <span class="flex items-center gap-2">
          <span class="text-[10px] font-medium text-cream-300/60">{{ activeBgLabel || 'Mặc định' }}</span>
          <span class="text-brand-300">{{ bgOpenRefgen ? '▲' : '▼' }}</span>
        </span>
      </button>
      <div v-if="bgOpenRefgen" class="mt-2 grid grid-cols-2 gap-1.5">
        <button v-for="p in presets" :key="p.id" @click="applyPreset(p)"
                class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-[10px] font-semibold transition-all"
                :class="activePreset === p.id ? 'border-brand-400 bg-brand-600/25 text-cream-50 shadow-brand-500/20' : 'border-ink-700 bg-ink-800 text-cream-200 hover:border-brand-400/50 hover:bg-ink-700'">
          <span class="h-4 w-4 shrink-0 rounded-full border border-white/25 shadow-inner ring-1 ring-black/30" :style="{ background: p.color }" :title="'Mã màu ' + p.color"></span>
          <span class="truncate">{{ p.label }}</span>
        </button>
      </div>

      <!-- Góc chụp -->
      <div class="mt-4 flex items-center justify-between">
        <p class="label">Góc chụp <span v-if="activeAngleLabel" class="font-semibold text-brand-300">· {{ activeAngleLabel }}</span></p>
        <span class="text-[9px] font-medium text-cream-300/40">{{ activeAngleLabel ? 'Đã chọn' : anglePresets.length + ' góc' }}</span>
      </div>
      <div class="mt-1 grid grid-cols-2 gap-1.5">
        <button v-for="a in anglePresets" :key="a.id" @click="applyAngle(a)"
                class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-[10px] font-semibold transition-all"
                :class="activeAngle === a.id ? 'border-brand-400 bg-brand-600/25 text-cream-50 shadow-brand-500/20' : 'border-ink-700 bg-ink-800 text-cream-200 hover:border-brand-400/50 hover:bg-ink-700'">
          <svg class="h-4 w-4 shrink-0 text-brand-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="a.svg"></svg>
          <span class="truncate">{{ a.label }}</span>
        </button>
      </div>

      <!-- Mô tả -->
      <label class="label mt-4">Mô tả ảnh mới <span class="text-cream-300/40">(để trống = tạo biến thể giống ảnh mẫu)</span></label>
      <textarea v-model="prompt" rows="3" maxlength="1000" class="input !text-xs" placeholder="VD: giữ chủ thể, đổi sang nền studio tối, góc máy chếch…"></textarea>
      <p class="mt-1 text-right text-[10px] text-cream-300/50">{{ prompt.length }}/1000</p>

      <!-- Model sinh ảnh — nhóm image (Cài đặt → 🎯 Nhóm công việc) -->
      <div v-if="store.taskGroupModels('image').length > 1" class="mt-3 flex items-center gap-2">
        <span class="shrink-0 text-[10px] font-medium text-cream-300/60">🤖</span>
        <select v-model="store.imageModelSel" class="input !py-2 !text-xs" title="Model sinh ảnh — danh sách từ Cài đặt → 🎯 Nhóm công việc (image)">
          <option value="">Mặc định ({{ store.taskGroupModels('image')[0]?.label || 'auto' }})</option>
          <option v-for="m in store.taskGroupModels('image')" :key="m.provider + m.model" :value="m.provider + ':' + m.model">{{ m.label }}</option>
        </select>
      </div>

      <!-- Độ giống ảnh mẫu -->
      <label class="label mt-3">Độ giống ảnh mẫu</label>
      <div class="flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-xs">
        <span class="shrink-0 font-medium text-cream-200">Giống</span>
        <input type="range" min="0" max="100" step="5" v-model.number="similarity" class="h-2 w-full cursor-pointer accent-brand-500">
        <span class="shrink-0 font-semibold text-cream-50">{{ similarity }}%</span>
      </div>
    </template>

    <!-- ============ CHẾ ĐỘ: THỬ ĐỒ (tryon sinh ảnh) ============ -->
    <template v-else>
      <!-- 4 chip điều khiển: chỉ 1 chip mở/tô sáng tại 1 thời điểm -->
      <div class="mt-4 grid grid-cols-4 gap-1.5">
        <button @click="togglePanel('face')"
                :class="openPanel === 'face' ? 'border-emerald-400 bg-emerald-600/25 ring-1 ring-emerald-400/40' : 'border-ink-700 bg-ink-800 hover:border-emerald-400/50'"
                class="flex flex-col items-center gap-1 rounded-lg border px-1 py-2.5 text-center transition">
          <StudioIcon name="user" size="h-5 w-5" class="text-emerald-300" />
          <span class="text-[11px] font-semibold leading-none text-cream-100">Khuôn mặt</span>
          <span class="max-w-full truncate text-[9px] leading-none text-cream-300/60">{{ selectedFace ? selectedFace.name : 'Mặc định' }}</span>
        </button>
        <button @click="togglePanel('body')"
                :class="openPanel === 'body' ? 'border-emerald-400 bg-emerald-600/25 ring-1 ring-emerald-400/40' : 'border-ink-700 bg-ink-800 hover:border-emerald-400/50'"
                class="flex flex-col items-center gap-1 rounded-lg border px-1 py-2.5 text-center transition">
          <StudioIcon name="body" size="h-5 w-5" class="text-emerald-300" />
          <span class="text-[11px] font-semibold leading-none text-cream-100">Phom dáng</span>
          <span class="max-w-full truncate text-[9px] leading-none text-cream-300/60">{{ bodyTouched ? bodyBuildLabel : 'Mặc định' }}</span>
        </button>
        <button @click="togglePanel('pose')"
                :class="openPanel === 'pose' ? 'border-emerald-400 bg-emerald-600/25 ring-1 ring-emerald-400/40' : 'border-ink-700 bg-ink-800 hover:border-emerald-400/50'"
                class="flex flex-col items-center gap-1 rounded-lg border px-1 py-2.5 text-center transition">
          <StudioIcon name="pose" size="h-5 w-5" class="text-emerald-300" />
          <span class="text-[11px] font-semibold leading-none text-cream-100">Pose</span>
          <span class="max-w-full truncate text-[9px] leading-none text-cream-300/60">{{ selectedPose ? selectedPose.name : 'Tự do' }}</span>
        </button>
        <button @click="togglePanel('bg')"
                :class="openPanel === 'bg' ? 'border-emerald-400 bg-emerald-600/25 ring-1 ring-emerald-400/40' : 'border-ink-700 bg-ink-800 hover:border-emerald-400/50'"
                class="flex flex-col items-center gap-1 rounded-lg border px-1 py-2.5 text-center transition">
          <StudioIcon name="background" size="h-5 w-5" class="text-emerald-300" />
          <span class="text-[11px] font-semibold leading-none text-cream-100">Nền Studio</span>
          <span class="max-w-full truncate text-[9px] leading-none text-cream-300/60">{{ activePreset ? 'Đã chọn' : 'Mặc định' }}</span>
        </button>
      </div>

      <!-- Khuôn mặt mẫu (lưới 2x, không title) -->
      <div v-if="openPanel === 'face'" class="mt-2 rounded-lg border border-emerald-400/20 bg-emerald-900/10 p-2.5">
        <div class="grid grid-cols-2 gap-1.5">
          <button v-for="f in faces" :key="f.id" @click="faceModelId = (String(faceModelId) === String(f.id)) ? '' : String(f.id)"
                  :class="String(faceModelId) === String(f.id) ? 'border-emerald-400 bg-emerald-600/25 ring-1 ring-emerald-400/40' : 'border-ink-700 bg-ink-800 hover:border-emerald-400/50'"
                  class="flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-[10px] font-semibold text-cream-200 transition">
            <img v-if="f.thumb || f.image" :src="f.thumb || f.image" loading="lazy" class="h-8 w-8 shrink-0 rounded-lg object-cover ring-1 ring-white/20">
            <span v-else class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-ink-700 text-[12px]">👩</span>
            <span class="truncate">{{ f.name }}</span>
          </button>
          <span v-if="!faces.length" class="col-span-2 text-[10px] text-cream-300/50">Chưa có khuôn mặt mẫu — để trống để AI tự chọn.</span>
        </div>
      </div>

      <!-- Phom dáng người mẫu (dropdown) -->
      <div v-if="openPanel === 'body'" class="mt-2 space-y-2 rounded-lg border border-emerald-400/20 bg-emerald-900/10 p-3">
        <div>
          <p class="mb-0.5 flex items-center justify-between text-[11px]"><span class="text-cream-200">Chiều cao</span><span class="font-semibold text-emerald-300">{{ bodyHeightLabel }}</span></p>
          <input type="range" min="1" max="10" step="1" v-model.number="store.bodyHeight" class="h-1.5 w-full cursor-pointer accent-emerald-400">
        </div>
        <div>
          <p class="mb-0.5 flex items-center justify-between text-[11px]"><span class="text-cream-200">Vóc dáng</span><span class="font-semibold text-emerald-300">{{ bodyBuildLabel }}</span></p>
          <input type="range" min="1" max="10" step="1" v-model.number="store.bodyBuild" class="h-1.5 w-full cursor-pointer accent-emerald-400">
        </div>
        <div>
          <p class="mb-0.5 flex items-center justify-between text-[11px]"><span class="text-cream-200">Eo</span><span class="font-semibold text-emerald-300">{{ bodyWaistLabel }}</span></p>
          <input type="range" min="1" max="10" step="1" v-model.number="store.bodyWaist" class="h-1.5 w-full cursor-pointer accent-emerald-400">
        </div>
        <div>
          <p class="mb-0.5 flex items-center justify-between text-[11px]"><span class="text-cream-200">Vai</span><span class="font-semibold text-emerald-300">{{ bodyShouldersLabel }}</span></p>
          <input type="range" min="1" max="10" step="1" v-model.number="store.bodyShoulders" class="h-1.5 w-full cursor-pointer accent-emerald-400">
        </div>
        <div>
          <p class="mb-0.5 flex items-center justify-between text-[11px]"><span class="text-cream-200">Hông</span><span class="font-semibold text-emerald-300">{{ bodyHipsLabel }}</span></p>
          <input type="range" min="1" max="10" step="1" v-model.number="store.bodyHips" class="h-1.5 w-full cursor-pointer accent-emerald-400">
        </div>
      </div>

      <!-- Pose mẫu (lưới 2x, không title) — AI đọc ẢNH pose để tạo mô tả tư thế -->
      <div v-if="openPanel === 'pose'" class="mt-2 rounded-lg border border-emerald-400/20 bg-emerald-900/10 p-2.5">
        <div class="grid grid-cols-2 gap-1.5">
          <button v-for="p in poses" :key="p.id" @click="poseId = (String(poseId) === String(p.id)) ? '' : String(p.id)"
                  :class="String(poseId) === String(p.id) ? 'border-emerald-400 bg-emerald-600/25 ring-1 ring-emerald-400/40' : 'border-ink-700 bg-ink-800 hover:border-emerald-400/50'"
                  class="flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-[10px] font-semibold text-cream-200 transition">
            <img v-if="p.thumb || p.image" :src="p.thumb || p.image" loading="lazy" class="h-9 w-9 shrink-0 rounded-lg object-cover ring-1 ring-white/20">
            <span v-else class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-ink-700 text-sm">🧍</span>
            <span class="truncate">{{ p.name }}</span>
          </button>
          <span v-if="!poses.length" class="col-span-2 text-[10px] text-cream-300/50">Chưa có pose mẫu — để trống để AI tự chọn.</span>
        </div>
      </div>

      <!-- Nền Studio (lưới 2x, không title) -->
      <div v-if="openPanel === 'bg'" class="mt-2 rounded-lg border border-emerald-400/20 bg-emerald-900/10 p-2.5">
        <div class="grid grid-cols-2 gap-1.5">
          <button v-for="p in presets" :key="p.id" @click="applyPreset(p)"
                  class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-[10px] font-semibold transition-all"
                  :class="activePreset === p.id ? 'border-emerald-400 bg-emerald-600/25 text-cream-50' : 'border-ink-700 bg-ink-800 text-cream-200 hover:border-emerald-400/50'">
            <span class="h-4 w-4 shrink-0 rounded-full border border-white/25 shadow-inner ring-1 ring-black/30" :style="{ background: p.color }"></span>
            <span class="truncate">{{ p.label }}</span>
          </button>
        </div>
      </div>

      <!-- Mô tả pose / thêm yêu cầu -->
      <label class="label mt-4">Mô tả pose / thêm yêu cầu</label>
      <textarea v-model="prompt" rows="3" maxlength="1000" class="input !text-xs" placeholder="VD: pose đứng tự nhiên, tay chống hông, ánh sáng studio…"></textarea>
      <p class="mt-1 text-right text-[10px] text-cream-300/50">{{ prompt.length }}/1000</p>
    </template>

    <!-- Số ảnh -->
    <label class="label mt-3">Số ảnh</label>
    <div class="seg">
      <button v-for="n in [1,2,3,4]" :key="n" @click="variants = n"
              class="seg-btn"
              :class="variants === n ? 'is-active' : ''">{{ n }}</button>
    </div>

    <button @click="runRefgen" :disabled="!canSubmit" class="btn-brand mt-4 w-full whitespace-nowrap">
      <span v-if="busy">Đang tạo {{ variants }} ảnh…</span>
      <span v-else>{{ mode === 'tryon' ? 'Thử đồ ' + variants + ' bản' : 'Tạo ' + variants + ' ảnh mới' }} <span class="opacity-70">· {{ store.imageCreditCost * variants }} credit</span></span>
    </button>
    <LoadingSpinner v-if="busy" :text="mode === 'tryon' ? 'AI đang tạo người mẫu mặc đồ…' : 'AI đang tạo ảnh từ ảnh mẫu…'" subtext="Quá trình này có thể mất vài giây" size="sm" />
    <p class="mt-2 text-[10px] leading-relaxed text-cream-300/40">Kết quả xuất hiện trong <b>Outputs</b> — chọn ảnh nào cũng được để xem lớn / làm ảnh gốc tiếp theo.</p>
  </div>
</template>