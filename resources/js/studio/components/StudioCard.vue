<script setup>
/**
 * STUDIO — PHÒNG CHỤP THỜI TRANG CHUYÊN NGHIỆP (trước đây là card "Ghép ảnh").
 *
 * Phân tích sâu: một buổi chụp thật không bắt đầu từ "ghép mấy tấm ảnh" mà từ BỐI CẢNH CHỦ ĐỀ +
 * SƠ ĐỒ ĐÈN + ỐNG KÍNH + DÁNG, rồi mới ra DANH SÁCH ẢNH (shot list) — và mọi tấm trong bộ phải
 * trông như CÙNG MỘT BUỔI CHỤP. Ba thứ quyết định ảnh bán được:
 *   1. bối cảnh chủ đề theo bộ sưu tập (mùa · vùng miền · dịp · không gian);
 *   2. tính NHẤT QUÁN giữa các tấm (cùng người mẫu · nền · hướng sáng · grade) — backend chèn một
 *      "look signature" giống hệt nhau vào MỌI prompt;
 *   3. độ TRUNG THỰC của trang phục — ảnh đầu là ảnh sản phẩm và AI phải giữ nguyên, chỉ đổi bối
 *      cảnh/ánh sáng/dáng.
 *
 * Toàn bộ prompt do backend dựng TẤT ĐỊNH (/api/studio/shoot/plan) nên xem trước và sửa được trước
 * khi tốn credit; chạy ảnh dùng lại đúng pipeline /api/compose sẵn có.
 */
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import SourceLibraryPicker from './SourceLibraryPicker.vue';
import CompareSlider from './CompareSlider.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';

const store = useStudioStore();

const open = ref(false);
const targetSlot = ref(0);
const selected = ref([null, null, null]);   // 1=trang phục (giữ nguyên) · 2=người mẫu/dáng · 3=bối cảnh
const slotImgError = ref([false, false, false]);
const SLOT_ROLES = ['Trang phục (giữ nguyên)', 'Người mẫu / dáng', 'Bối cảnh (tùy chọn)'];

const baseUrl = ref('');
const lastIds = ref([]);
const compareOpen = ref(false);
const afterUrl = computed(() => store.generations.find(g => lastIds.value.includes(g.id) && g.status === 'completed')?.media_url || '');
const busy = ref(false);
const promptEditorFor = ref('');            // shot id đang mở ô sửa prompt
const customBackdropOpen = ref(false);

const catalog = computed(() => store.shootCatalog);
const setup = computed(() => store.shootSetup);
const plan = computed(() => store.shootPlan);
const warnings = computed(() => (plan.value && plan.value.warnings) || []);
const shotOptions = computed(() => (catalog.value && catalog.value.shots) || []);
const backdrops = computed(() => (catalog.value && catalog.value.backdrops) || []);
const lighting = computed(() => (catalog.value && catalog.value.lighting) || []);
const cameras = computed(() => (catalog.value && catalog.value.cameras) || []);
const poses = computed(() => (catalog.value && catalog.value.poses) || []);
const styles = computed(() => (catalog.value && catalog.value.styles) || []);
const ratios = computed(() => (catalog.value && catalog.value.ratios) || []);
const selectedCount = computed(() => selected.value.filter(Boolean).length);
const images = computed(() => selected.value.filter(Boolean).map(g => g.url).filter(Boolean));
const canRun = computed(() => selectedCount.value >= 2 && (plan.value?.shots?.length || 0) > 0 && !busy.value);
const activeBackdrop = computed(() => backdrops.value.find(b => b.id === setup.value.backdrop) || null);
const estimatedCredits = computed(() => (plan.value && plan.value.total_credits) || 0);

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
  planTimer = setTimeout(() => { store.planShoot(selectedCount.value).catch(() => {}); }, 300);
}

onMounted(async () => {
  timer = setInterval(() => { now.value = Date.now(); }, 1000);
  await store.loadShootCatalog();
  // Dựng danh sách ảnh ngay từ setup mặc định để người dùng thấy phòng chụp đang làm gì.
  // Gắn sẵn tên BỘ SƯU TẬP đang áp dụng (nếu có) để buổi chụp thuộc đúng bộ đó.
  store.setShootSetup({ collection: (store.appliedProject && store.appliedProject.name) || '' });
  schedulePlan();
});
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
  if (planTimer) clearTimeout(planTimer);
});

// Đổi bất kỳ tham số nào ⇒ dựng lại danh sách ảnh (tất định, nhanh, không tốn credit).
watch(() => JSON.stringify(store.shootSetup), () => schedulePlan());
watch(selectedCount, () => schedulePlan());

function openSlot(i) { targetSlot.value = i; open.value = true; }
function onPick(img) { selected.value[targetSlot.value] = img; slotImgError.value[targetSlot.value] = false; open.value = false; }
function removeSlot(i) { selected.value[i] = null; slotImgError.value[i] = false; }
function onSlotImgError(i) { slotImgError.value[i] = true; }
function roleLabel(i) { return '@image' + (i + 1); }

function chooseBackdrop(id) {
  const found = backdrops.value.find(b => b.id === id);
  store.setShootSetup({ backdrop: id, lighting: '', backdrop_note: '' });
  if (found) store.toast('Bối cảnh: ' + found.name + ' · đèn gợi ý: ' + (found.light_name || '—'));
}
function chooseAllShots() { store.setShootSetup({ shots: shotOptions.value.map(s => s.id) }); }
function clearShots() { store.setShootSetup({ shots: [] }); }
function shotSelected(id) { return (setup.value.shots || []).includes(id); }

async function run() {
  if (!canRun.value) {
    if (selectedCount.value < 2) store.toast('Cần ít nhất 2 ảnh: trang phục + người mẫu/dáng.', 'error');
    return;
  }
  busy.value = true;
  baseUrl.value = images.value[0] || '';
  const ids = await store.runShoot(images.value);
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
        <p class="mt-0.5 text-[10px] leading-4 text-cream-300/55">Phòng chụp thời trang: bối cảnh chủ đề · ánh sáng · dáng · danh sách ảnh</p>
      </div>
      <span v-if="plan" class="shrink-0 rounded-full bg-ink-800 px-2 py-0.5 text-[9px] font-semibold text-cream-300/70" :title="plan.look_signature">{{ plan.look_id }}</span>
    </div>

    <!-- Trạng thái model ảnh: nói THẬT nếu chưa cấu hình -->
    <p v-if="plan && plan.image_ready === false" role="status"
       class="mt-2 flex gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-2.5 py-1.5 text-[10px] leading-4 text-amber-100">
      <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" class="shrink-0" />
      <span>Chưa cấu hình model tạo/sửa ảnh (nhóm “edit”) — buổi chụp sẽ chạy ở CHẾ ĐỘ DEMO và trả ảnh mẫu, không phải ảnh do AI tạo.</span>
    </p>

    <!-- 1. ẢNH THAM CHIẾU -->
    <p class="mt-3 rounded-md border border-white/10 bg-white/5 px-2.5 py-1.5 text-[10px] leading-relaxed text-cream-300/70">
      Ảnh 1 = <strong class="text-cream-100">trang phục</strong> (AI giữ nguyên) · ảnh 2 = người mẫu/dáng · ảnh 3 = bối cảnh tham chiếu
    </p>
    <div class="mt-2 grid grid-cols-3 gap-2">
      <button v-for="i in 3" :key="i" @click="openSlot(i - 1)" title="Bấm để tải/chọn ảnh"
              class="relative flex h-24 flex-col items-center justify-center overflow-hidden rounded-md border transition"
              :class="selected[i-1] ? 'border-brand-500 bg-ink-900' : 'border-dashed border-ink-700 hover:border-brand-400 bg-ink-900/40'">
        <template v-if="selected[i-1]">
          <img :src="selected[i-1].url" class="h-full w-full object-cover" @error="onSlotImgError(i-1)">
          <span v-if="slotImgError[i-1]" class="absolute inset-0 grid place-items-center bg-ink-900 text-2xl" title="Ảnh không tải được — bấm × để bỏ">🖼️</span>
          <span class="absolute left-1 top-1 rounded-full bg-brand-500 px-1.5 text-[9px] font-bold text-white">{{ i }}</span>
          <span class="absolute inset-x-0 bottom-0 bg-black/65 px-1 py-0.5 text-center text-[9px] font-semibold text-cream-100">{{ SLOT_ROLES[i-1] }}</span>
          <span @click.stop="removeSlot(i-1)" title="Bỏ ảnh khỏi slot" class="motion-ui absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-red-600/90 text-[11px] text-white hover:bg-red-500"><StudioIcon name="x" size="h-3.5 w-3.5" /></span>
        </template>
        <template v-else>
          <span class="grid h-6 w-6 place-items-center text-ink-600"><StudioIcon name="shirt" size="h-5 w-5" v-if="i === 1" /><span v-else>＋</span></span>
          <span class="px-1 text-center text-[9px] font-medium text-cream-300/60">{{ SLOT_ROLES[i-1] }}</span>
          <span class="px-1 text-center text-[9px] text-cream-300/40">{{ roleLabel(i - 1) }}</span>
        </template>
      </button>
    </div>

    <!-- 2. BỐI CẢNH CHỦ ĐỀ -->
    <div class="mt-4">
      <div class="flex items-center justify-between gap-2">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Bối cảnh chủ đề</span>
        <button type="button" class="tool-btn !px-2 !py-1 text-[10px]" @click="customBackdropOpen = !customBackdropOpen">
          {{ customBackdropOpen ? 'Chọn từ danh mục' : 'Bối cảnh tự nhập' }}
        </button>
      </div>

      <textarea v-if="customBackdropOpen" :value="setup.backdrop_note" rows="2" maxlength="400"
                class="input mt-2 w-full resize-none !text-[11px]"
                placeholder="VD: sân thượng Sài Gòn lúc hoàng hôn, lan can sắt, cây xanh mờ phía sau…"
                @input="store.setShootSetup({ backdrop_note: $event.target.value, backdrop: 'custom' })"></textarea>

      <div v-else class="mt-2 grid max-h-64 grid-cols-2 gap-1.5 overflow-y-auto pr-0.5">
        <button v-for="b in backdrops" :key="b.id" type="button"
                class="motion-ui rounded-lg border p-2 text-left transition"
                :class="setup.backdrop === b.id ? 'border-brand-500 bg-brand-600/15' : 'border-ink-700 bg-ink-900/60 hover:border-brand-400 hover:bg-ink-800'"
                :title="b.tip" @click="chooseBackdrop(b.id)">
          <span class="flex items-center gap-1">
            <span v-for="hex in (b.palette || []).slice(0, 3)" :key="hex" class="h-2.5 w-2.5 rounded-full border border-white/20" :style="{ backgroundColor: hex }"></span>
            <span class="ml-auto text-[8px] uppercase tracking-wide text-cream-300/40">{{ b.season }}</span>
          </span>
          <span class="mt-1 block truncate text-[11px] font-semibold text-cream-100">{{ b.name }}</span>
          <span class="block truncate text-[9px] text-cream-300/50">{{ b.theme }} · {{ b.light_name }}</span>
        </button>
      </div>
    </div>

    <!-- 3. ÁNH SÁNG -->
    <div class="mt-3">
      <span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Ánh sáng</span>
      <p class="mt-0.5 text-[10px] text-cream-300/45">Bỏ trống = dùng đèn gợi ý của bối cảnh ({{ activeBackdrop?.light_name || 'Softbox đều' }}).</p>
      <div class="mt-1.5 flex flex-wrap gap-1.5">
        <button type="button" class="tool-btn !px-2 !py-1 text-[10px]" :class="{ 'is-active': !setup.lighting }" @click="store.setShootSetup({ lighting: '' })">Theo bối cảnh</button>
        <button v-for="l in lighting" :key="l.id" type="button" :title="l.tip"
                class="tool-btn !px-2 !py-1 text-[10px]" :class="{ 'is-active': setup.lighting === l.id }"
                @click="store.setShootSetup({ lighting: l.id })">{{ l.name }}</button>
      </div>
    </div>

    <!-- 4. ỐNG KÍNH + DÁNG -->
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
      <div>
        <span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Ống kính</span>
        <div class="mt-1.5 flex flex-wrap gap-1.5">
          <button v-for="c in cameras" :key="c.id" type="button" class="tool-btn !px-2 !py-1 text-[10px]"
                  :class="{ 'is-active': setup.camera === c.id }" @click="store.setShootSetup({ camera: c.id })">{{ c.name }}</button>
        </div>
      </div>
      <div>
        <span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Dáng người mẫu</span>
        <div class="mt-1.5 flex flex-wrap gap-1.5">
          <button v-for="p in poses" :key="p.id" type="button" class="tool-btn !px-2 !py-1 text-[10px]"
                  :class="{ 'is-active': setup.pose === p.id }" @click="store.setShootSetup({ pose: p.id })">{{ p.name }}</button>
        </div>
      </div>
    </div>

    <!-- 5. PHONG CÁCH HẬU KỲ -->
    <div class="mt-3">
      <span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Phong cách hậu kỳ</span>
      <div class="mt-1.5 flex flex-wrap gap-1.5">
        <button v-for="s in styles" :key="s.id" type="button" :title="s.tip" class="tool-btn !px-2 !py-1 text-[10px]"
                :class="{ 'is-active': setup.style === s.id }" @click="store.setShootSetup({ style: s.id })">{{ s.name }}</button>
      </div>
    </div>

    <!-- 6. DANH SÁCH ẢNH -->
    <div class="mt-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Danh sách ảnh ({{ (setup.shots || []).length }})</span>
        <div class="flex gap-1.5">
          <button type="button" class="tool-btn !px-2 !py-1 text-[10px]" @click="chooseAllShots">Chọn tất cả</button>
          <button type="button" class="tool-btn !px-2 !py-1 text-[10px]" @click="clearShots">Bỏ chọn</button>
        </div>
      </div>
      <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
        <label v-for="s in shotOptions" :key="s.id"
               class="motion-ui flex cursor-pointer items-start gap-2 rounded-lg border p-2 transition"
               :class="shotSelected(s.id) ? 'border-brand-500/60 bg-brand-600/10' : 'border-ink-700 bg-ink-900/60 hover:border-brand-400'">
          <input type="checkbox" class="mt-0.5 h-3.5 w-3.5" :checked="shotSelected(s.id)" @change="store.toggleShootShot(s.id)">
          <span class="min-w-0">
            <span class="block text-[11px] font-semibold text-cream-100">{{ s.name }} <span class="font-normal text-cream-300/45">· {{ s.ratio }}</span></span>
            <span class="block text-[9px] leading-4 text-cream-300/50">{{ s.use }}</span>
          </span>
        </label>
      </div>

      <div class="mt-2 flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-[11px] text-cream-200">
          <span>Biến thể mỗi ảnh</span>
          <select :value="setup.variants" class="input !w-auto !py-1 !text-[11px]" @change="store.setShootSetup({ variants: Number($event.target.value) })">
            <option v-for="n in [1, 2, 3]" :key="n" :value="n">{{ n }}</option>
          </select>
        </label>
        <label class="flex items-center gap-2 text-[11px] text-cream-200">
          <span>Tỉ lệ</span>
          <select :value="setup.ratio" class="input !w-auto !py-1 !text-[11px]" @change="store.setShootSetup({ ratio: $event.target.value })">
            <option value="">Theo từng loại ảnh</option>
            <option v-for="r in ratios" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </label>
      </div>
    </div>

    <!-- 7. BỘ SƯU TẬP & GHI CHÚ -->
    <div class="mt-3 space-y-2">
      <label class="block">
        <span class="label">Bộ sưu tập / chủ đề</span>
        <input :value="setup.collection" type="text" maxlength="120" class="input !text-xs"
               placeholder="VD: Hè 2026 · Linen công sở"
               @input="store.setShootSetup({ collection: $event.target.value })">
      </label>
      <label class="block">
        <span class="label">Ghi chú người mẫu</span>
        <input :value="setup.model_note" type="text" maxlength="300" class="input !text-xs"
               placeholder="VD: nữ 25 tuổi, tóc dài đen, makeup tự nhiên, cao 1m68"
               @input="store.setShootSetup({ model_note: $event.target.value })">
      </label>
      <label class="block">
        <span class="label">Ghi chú trang phục (AI phải giữ đúng)</span>
        <input :value="setup.garment_note" type="text" maxlength="300" class="input !text-xs"
               placeholder="VD: giữ nguyên nếp gấp, khoá kéo kim loại, đường may vai"
               @input="store.setShootSetup({ garment_note: $event.target.value })">
      </label>
    </div>

    <!-- 8. KẾT QUẢ DỰNG DANH SÁCH -->
    <div v-if="store.shootError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-2.5 text-[11px] text-red-200">
      <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" class="shrink-0" /><span>{{ store.shootError }}</span>
    </div>

    <div v-if="plan" class="mt-3 rounded-lg border border-ink-700 bg-ink-900/70 p-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-[11px] font-semibold text-cream-100">
          {{ plan.total_images }} ảnh ({{ plan.total_shots }} loại × {{ plan.setup.variants }} biến thể)
          <span class="font-normal text-cream-300/50">· ~{{ estimatedCredits }} credit</span>
        </p>
        <button type="button" class="tool-btn !px-2 !py-1 text-[10px]" @click="store.planShoot(selectedCount)">
          <StudioIcon name="refresh" size="h-3 w-3" :class="store.shootLoading ? 'animate-spin' : ''" /> Dựng lại
        </button>
      </div>
      <p class="mt-1 text-[10px] leading-4 text-cream-300/55">
        {{ plan.setup.backdrop_name }} · {{ plan.setup.lighting_name }} · {{ plan.setup.camera_name }} · {{ plan.setup.pose_name }} · {{ plan.setup.style_name }}
      </p>

      <ul v-if="warnings.length" class="mt-2 space-y-1">
        <li v-for="(w, i) in warnings" :key="i" class="text-[10px] leading-4"
            :class="w.level === 'error' ? 'text-red-300' : (w.level === 'warning' ? 'text-amber-200' : 'text-cream-300/60')">• {{ w.message }}</li>
      </ul>

      <details class="mt-2">
        <summary class="cursor-pointer text-[10px] font-semibold text-cream-300/60">Xem/sửa prompt từng ảnh</summary>
        <div class="mt-2 space-y-2">
          <div v-for="shot in plan.shots" :key="shot.id" class="rounded-md border border-ink-700 bg-ink-800/60 p-2">
            <div class="flex items-center justify-between gap-2">
              <span class="text-[10px] font-semibold text-cream-100">{{ shot.name }} <span class="font-normal text-cream-300/45">· {{ shot.ratio_name }}</span></span>
              <button type="button" class="tool-btn !px-2 !py-0.5 text-[9px]" @click="promptEditorFor = promptEditorFor === shot.id ? '' : shot.id">
                {{ promptEditorFor === shot.id ? 'Đóng' : 'Sửa' }}
              </button>
            </div>
            <textarea v-if="promptEditorFor === shot.id" :value="store.shootPromptOf(shot)" rows="6"
                      class="input mt-1.5 w-full !text-[10px] leading-relaxed"
                      @input="store.setShootPrompt(shot.id, $event.target.value)"></textarea>
            <p v-else class="mt-1 line-clamp-3 text-[10px] leading-4 text-cream-300/60">{{ store.shootPromptOf(shot) }}</p>
          </div>
        </div>
      </details>

      <ul class="mt-2 space-y-0.5">
        <li v-for="(n, i) in (plan.notes || [])" :key="i" class="text-[10px] leading-4 text-cream-300/45">• {{ n }}</li>
      </ul>
    </div>

    <!-- 9. CHẠY -->
    <button @click="run" :disabled="!canRun" class="btn-brand mt-3 w-full whitespace-nowrap">
      {{ busy ? 'Đang chạy buổi chụp…' : 'Chụp ' + ((plan && plan.total_images) || 0) + ' ảnh' }}
      <span v-if="!busy" class="opacity-70">· ~{{ estimatedCredits }} credit</span>
    </button>
    <p v-if="selectedCount < 2" class="mt-1 text-[10px] leading-4 text-amber-200/80">Cần ít nhất 2 ảnh: 1 trang phục + 1 người mẫu/dáng.</p>

    <!-- Tiến độ -->
    <div v-if="running" class="mt-3 rounded-lg border border-brand-500/30 bg-brand-900/30 p-3">
      <LoadingSpinner
        :text="store.composeStage === 'send' ? 'Đang gửi buổi chụp tới AI…' : 'AI đang dựng từng tấm ảnh…'"
        :subtext="fmt(elapsedSec) + ' · ' + doneCount + '/' + store.composeGenIds.length + ' ảnh'"
        :progress="doneCount / Math.max(1, store.composeGenIds.length) * 100" />
      <div class="mt-2 flex justify-end">
        <button @click="store.cancelCompose()" class="rounded-full bg-red-600/25 px-2.5 py-1 text-[10px] font-semibold text-red-200 hover:bg-red-600">Hủy</button>
      </div>
    </div>

    <div v-if="store.composeStage === 'done'" class="mt-3 rounded-lg border border-emerald-500/40 bg-emerald-900/25 p-3 text-xs text-emerald-200">
      Đã chụp xong — ảnh đã được chọn trong Outputs và xếp vào bảng Lớp.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <div v-if="store.composeStage === 'error' && store.composeError" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-3 text-xs text-red-200">
      <p class="font-semibold">Buổi chụp thất bại</p>
      <p class="mt-1 whitespace-pre-line leading-relaxed">{{ store.composeError }}</p>
      <div class="mt-2 flex gap-2">
        <button @click="retry" class="btn-brand btn-sm">Thử lại</button>
        <button @click="store.clearComposeStatus()" class="btn-ghost btn-sm">Đóng</button>
      </div>
    </div>

    <div v-if="store.composeStage === 'cancelled'" class="mt-3 flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 p-3 text-xs text-cream-200">
      Đã hủy buổi chụp.
      <button @click="store.clearComposeStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <button v-if="baseUrl && afterUrl" @click="compareOpen = true" class="btn-outline mt-1.5 w-full whitespace-nowrap">🔍 So sánh Trước/Sau</button>

    <SourceLibraryPicker
      v-model="open"
      :title="'Chọn ảnh cho ' + roleLabel(targetSlot) + ' · ' + SLOT_ROLES[targetSlot]"
      mode="pick"
      @pick="onPick" />

    <CompareSlider v-model="compareOpen" :before="baseUrl" :after="afterUrl" title="So sánh ảnh gốc và ảnh chụp" />
  </div>
</template>
