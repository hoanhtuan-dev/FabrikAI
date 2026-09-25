<script setup>
/**
 * PHONE ACTIONS — cấp OPTIONS → ACTION của ảnh đang làm việc (Studio phone, shell 2026).
 *
 * Luồng: preview (REVIEW: chạm ảnh → trình xem) → nút "Tác vụ ảnh" (OPTIONS: sheet này mở)
 * → chọn một mục (ACTION: sheet con có tham số + nút xác nhận). ← lùi một cấp, ✕ đóng hết,
 * nút back của máy lùi đúng từng cấp (useNavStack gài History API).
 *
 * Chỉ gồm việc CHẠY ĐƯỢC KHÔNG CẦN CANVAS: biến thể (refgen), sửa ảnh (tả · khoanh · cọ), upscale,
 * đổi khung, tải, chia sẻ, tech pack, xoá.
 *
 * [Đợt 59 · 2026-09-26] «Sửa ảnh» vào đây sau khi KIỂM LẠI mã: màn Chỉnh ảnh (EditImageModal) vốn
 * đã chạy bằng ngón tay — `touch-action: none` + pointer events cho cả khoanh khung lẫn vẽ cọ, và
 * bố cục `flex-col` dưới lg nên trên điện thoại nó là ảnh ở trên, tham số ở dưới. Câu «khoanh vùng
 * là việc của canvas» chỉ đúng với CÔNG CỤ CANVAS (RegionTools trên bảng ghép), không đúng với màn
 * một-ảnh này. Giữ nguyên tắc §15.10: chỉ mở nút cho việc CHẠY ĐƯỢC.
 */
import { computed, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
import BottomSheet from './BottomSheet.vue';
import { useNavStack } from '../composables/useNavStack.js';
import { haptic } from '../composables/useHaptics.js';
import { useImageActions } from '../composables/useImageActions.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  /**
   * Mở thẳng vào MỘT cấp con ('variant' · 'upscale' · 'reframe' · 'delete').
   * Rỗng = mở cấp Options như cũ.
   * VÌ SAO CẦN: hàng chip trên màn Studio (theo prototype: «Upscale 4K», «Tạo biến thể AI») phải đi tới
   * ĐÚNG cấp Action trong MỘT chạm; nếu bắt người dùng qua cấp Options thì hàng chip chỉ là lối vào thứ
   * hai của cùng một sheet — thêm một cú bấm mà không thêm thông tin.
   */
  startAt: { type: String, default: '' },
});
const emit = defineEmits(['update:open', 'edit']);

const store = useStudioStore();
const nav = useNavStack();
/* Bốn hành động API nay nằm ở MỘT composable dùng chung với hàng chip của màn Studio — xem
   composables/useImageActions.js (lý do: hai lối vào, một bản logic). */
const { runVariant: doVariant, runUpscale: doUpscale, runReframe: doReframe, download: doDownload, share: doShare, remove: doRemove } = useImageActions();

const level = computed(() => nav.state.stack[nav.state.stack.length - 1] || null);
/**
 * TIÊU ĐỀ TỪNG CẤP. Cấp 'options' nay tên là «Việc khác» — vì đây KHÔNG còn là danh sách đầy đủ:
 * biến thể · nâng cấp · tải · chia sẻ · tech pack đã nằm NGAY TRÊN MÀN Studio (CTA + hàng chip), nên
 * lặp lại chúng ở đây là hai lối vào cho cùng một việc trên cùng một màn hình (đợt 62 dọn phần đó).
 */
const TITLES = { options: 'Việc khác', variant: 'Tạo biến thể', upscale: 'Nâng cấp ảnh', reframe: 'Đổi khung hình', delete: 'Xoá ảnh này?' };
const title = computed(() => TITLES[level.value] || '');
const hasImage = computed(() => !!store.upscaleSrc);

const LEVELS = ['options', 'variant', 'upscale', 'reframe', 'delete'];
watch(() => props.open, (v) => {
  if (v) nav.open(LEVELS.includes(props.startAt) ? props.startAt : 'options');
  else if (nav.state.stack.length) nav.closeAll();
});
/* Stack rỗng (người dùng bấm back của máy tới hết) → đóng sheet về phía cha. */
watch(() => nav.state.stack.length, (n) => { if (!n && props.open) emit('update:open', false); });

function close() { nav.closeAll(); }
function go(l) { haptic(8); nav.push(l); }
function back() { nav.pop(); }

/* ── Biến thể ── */
const sim = ref(70);
const variants = ref(2);
async function runVariant() {
  if (!hasImage.value) return;
  close();
  await doVariant(sim.value, variants.value);
}

/* ── Upscale ── */
const scale = ref(2);
async function runUpscale() {
  if (!hasImage.value || store.upscaling) return;
  close();
  await doUpscale(scale.value);
}

/* ── Sửa ảnh: mở màn Chỉnh ảnh của xưởng (tả · khoanh vùng · cọ) — nơi gọi lo việc mở ── */
function goEdit() {
  haptic(12);
  close();
  emit('edit');
}

/* ── Đổi khung hình (POST /api/reframe — crop tâm theo tỉ lệ, đồng bộ, miễn phí) ── */
const ratio = ref('3:4');
const RATIOS = ['3:4', '4:5', '1:1', '9:16', '16:9'];
const reframing = ref(false);
async function runReframe() {
  if (!hasImage.value || reframing.value) return;
  reframing.value = true;
  try { if (await doReframe(ratio.value)) close(); }
  finally { reframing.value = false; }
}

/* ── Việc trực tiếp (không cần cấp con) ── */
function download() { close(); doDownload(); }
async function share() { close(); await doShare(); }
async function runDelete() { close(); await doRemove(); }
</script>

<template>
  <BottomSheet
    :open="open"
    :title="title"
    :back="level !== 'options'"
    @update:open="close"
    @back="back"
  >
    <!-- CẤP 1 · OPTIONS -->
    <div v-if="level === 'options'" class="flex flex-col gap-1.5 pb-2">
      <button type="button" class="opt" :disabled="!hasImage" data-phone-action="edit" @click="goEdit">
        <span class="opt-ic"><StudioIcon name="pencil" size="h-[18px] w-[18px]" /></span>
        <span class="opt-main"><b>Sửa ảnh</b><i>Tả điều muốn đổi · khoanh vùng · vẽ cọ</i></span>
        <StudioIcon name="chevronRight" size="h-4 w-4" class="text-cream-400" />
      </button>
      <button type="button" class="opt" :disabled="!hasImage" @click="go('reframe')">
        <span class="opt-ic"><StudioIcon name="crop" size="h-[18px] w-[18px]" /></span>
        <span class="opt-main"><b>Đổi khung hình</b><i>Cắt theo tỉ lệ 3:4 · 1:1 · 9:16…</i></span>
        <StudioIcon name="chevronRight" size="h-4 w-4" class="text-cream-400" />
      </button>
      <button type="button" class="opt" :disabled="!hasImage" @click="go('delete')">
        <span class="opt-ic text-danger"><StudioIcon name="trash" size="h-[18px] w-[18px]" /></span>
        <span class="opt-main"><b class="text-danger">Xoá ảnh</b><i>Xoá hẳn khỏi thư viện</i></span>
      </button>
    </div>

    <!-- CẤP 2 · BIẾN THỂ -->
    <div v-else-if="level === 'variant'" class="flex flex-col gap-4 pb-2">
      <div>
        <div class="mb-1.5 flex items-center justify-between text-label">
          <span class="font-semibold text-cream-200">Mức giữ nét gốc</span>
          <span class="text-cream-400">{{ sim }}%</span>
        </div>
        <input v-model="sim" type="range" min="40" max="95" step="5" class="w-full accent-brand-500">
        <p class="mt-1 text-micro text-cream-400">Thấp = sáng tạo tự do · Cao = bám sát ảnh gốc.</p>
      </div>
      <div>
        <div class="mb-1.5 text-label font-semibold text-cream-200">Số biến thể</div>
        <div class="flex gap-2">
          <button
            v-for="n in [1, 2, 4]" :key="n" type="button"
            class="h-10 flex-1 rounded-xl border text-label font-semibold transition"
            :class="variants === n ? 'border-brand-500 bg-brand-600/20 text-cream-50' : 'border-ink-600 bg-ink-800 text-cream-300'"
            @click="variants = n"
          >{{ n }}</button>
        </div>
      </div>
      <button type="button" class="btn-magic flex h-12 items-center justify-center gap-2 rounded-2xl text-label font-bold transition active:scale-[0.98]" @click="runVariant">
        <StudioIcon name="sparkles" size="h-4 w-4" /> Tạo {{ variants }} biến thể
      </button>
    </div>

    <!-- CẤP 2 · UPSCALE -->
    <div v-else-if="level === 'upscale'" class="flex flex-col gap-4 pb-2">
      <div class="flex gap-2">
        <button
          v-for="n in [2, 4]" :key="n" type="button"
          class="h-10 flex-1 rounded-xl border text-label font-semibold transition"
          :class="scale === n ? 'border-brand-500 bg-brand-600/20 text-cream-50' : 'border-ink-600 bg-ink-800 text-cream-300'"
          @click="scale = n"
        >{{ n }}×</button>
      </div>
      <p class="text-micro text-cream-400">Ảnh mới là một KẾT QUẢ riêng — ảnh gốc giữ nguyên.</p>
      <button type="button" class="btn-magic flex h-12 items-center justify-center gap-2 rounded-2xl text-label font-bold transition active:scale-[0.98]" :disabled="store.upscaling" @click="runUpscale">
        <StudioIcon name="maximize" size="h-4 w-4" /> {{ store.upscaling ? 'Đang nâng cấp…' : 'Nâng cấp ' + scale + '×' }}
      </button>
    </div>

    <!-- CẤP 2 · ĐỔI KHUNG -->
    <div v-else-if="level === 'reframe'" class="flex flex-col gap-4 pb-2">
      <div class="grid grid-cols-5 gap-2">
        <button
          v-for="r in RATIOS" :key="r" type="button"
          class="h-10 rounded-xl border text-label font-semibold transition"
          :class="ratio === r ? 'border-brand-500 bg-brand-600/20 text-cream-50' : 'border-ink-600 bg-ink-800 text-cream-300'"
          @click="ratio = r"
        >{{ r }}</button>
      </div>
      <p class="text-micro text-cream-400">Cắt từ tâm ảnh — ảnh mới là một KẾT QUẢ riêng, ảnh gốc giữ nguyên.</p>
      <button type="button" class="btn-magic flex h-12 items-center justify-center gap-2 rounded-2xl text-label font-bold transition active:scale-[0.98]" :disabled="reframing" @click="runReframe">
        <StudioIcon name="crop" size="h-4 w-4" /> {{ reframing ? 'Đang cắt…' : 'Cắt theo ' + ratio }}
      </button>
    </div>

    <!-- CẤP 2 · XOÁ -->
    <div v-else-if="level === 'delete'" class="flex flex-col gap-4 pb-2">
      <p class="text-body text-cream-300">Ảnh sẽ bị xoá hẳn khỏi thư viện. Không hoàn tác được.</p>
      <button type="button" class="flex h-12 items-center justify-center gap-2 rounded-2xl border border-danger/40 text-label font-bold text-danger transition active:scale-[0.98]" @click="runDelete">
        <StudioIcon name="trash" size="h-4 w-4" /> Xoá vĩnh viễn
      </button>
    </div>
  </BottomSheet>
</template>

<style scoped>
/* Hàng tuỳ chọn: một ngón cái chạm chắc (≥52px), token theme, nhịp motion dùng chung. */
.opt {
  display: flex; align-items: center; gap: 11px; width: 100%; padding: 10px; text-align: left;
  border-radius: 14px; transition: background var(--motion-dur-fast) var(--motion-ease-standard);
}
.opt:hover { background: var(--color-ink-800); }
.opt[disabled] { opacity: .45; }
.opt-ic {
  width: 38px; height: 38px; flex: none; border-radius: 12px; display: grid; place-items: center;
  background: var(--color-ink-800); color: var(--color-cream-300);
}
.opt-ic--magic { background: var(--color-brand-600); color: var(--color-primary-content); }
.opt-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.opt-main b { font-size: 14px; font-weight: 600; line-height: 1.25; color: var(--color-cream-100); }
.opt-main i { font-style: normal; font-size: 11.5px; color: var(--color-cream-400); }
</style>
