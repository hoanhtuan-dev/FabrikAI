<script setup>
/**
 * PHONE ACTIONS — cấp OPTIONS → ACTION của ảnh đang làm việc (Studio phone, shell 2026).
 *
 * Luồng: preview (REVIEW: chạm ảnh → trình xem) → nút "Tác vụ ảnh" (OPTIONS: sheet này mở)
 * → chọn một mục (ACTION: sheet con có tham số + nút xác nhận). ← lùi một cấp, ✕ đóng hết,
 * nút back của máy lùi đúng từng cấp (useNavStack gài History API).
 *
 * Chỉ gồm việc CHẠY ĐƯỢC KHÔNG CẦN CANVAS: biến thể (refgen), upscale, tải, chia sẻ, tech pack,
 * xoá. Công cụ khoanh vùng (inpaint…) là việc của canvas → chỉ có trên màn rộng.
 */
import { computed, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
import BottomSheet from './BottomSheet.vue';
import { useNavStack } from '../composables/useNavStack.js';
import { haptic } from '../composables/useHaptics.js';

const props = defineProps({
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open']);

const store = useStudioStore();
const nav = useNavStack();

const level = computed(() => nav.state.stack[nav.state.stack.length - 1] || null);
const TITLES = { options: 'Tác vụ ảnh', variant: 'Tạo biến thể', upscale: 'Nâng cấp ảnh', delete: 'Xoá ảnh này?' };
const title = computed(() => TITLES[level.value] || '');
const hasImage = computed(() => !!store.upscaleSrc);

watch(() => props.open, (v) => {
  if (v) nav.open('options');
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
  haptic(12);
  close();
  await store.refgen(store.upscaleSrc, store.imagePromptEn || '', sim.value, variants.value);
}

/* ── Upscale (giống UpscaleCard: cùng endpoint, cùng cờ upscaling) ── */
const scale = ref(2);
async function runUpscale() {
  if (!hasImage.value || store.upscaling) return;
  haptic(12);
  close();
  store.upscaling = true;
  try {
    const d = await store.api('/api/upscale', {
      image: store.upscaleSrc,
      scale: Number(scale.value) || 2,
      refine: Number(store.upscaleRefine) || 0,
      vibrance: Number(store.vibrance) || 0,
      project_id: store.appliedProjectId(),
    });
    store.addGen({
      id: d.generation_id, type: 'image', status: d.status || 'completed',
      model: d.model || 'upscale', provider: d.provider || 'upscale',
      media_url: d.media_url, error: d.error || null,
      credits_cost: Number(d.credits_cost ?? 0), created_at: 'Vừa nâng cấp',
    });
    store.toast('Đã nâng cấp ảnh (' + scale.value + 'x).');
  } catch (e) { store.failToast(e, 'Lỗi nâng cấp ảnh.'); }
  finally { store.upscaling = false; }
}

/* ── Việc trực tiếp (không cần cấp con) ── */
function download() {
  const g = store.preview;
  close();
  if (!g || !g.id) { store.toast('Chưa có ảnh để tải.', 'error'); return; }
  window.location.href = '/api/generations/' + g.id + '/download';
}
async function share() {
  const url = store.upscaleSrc;
  close();
  if (!url) { store.toast('Chưa có ảnh để chia sẻ.', 'error'); return; }
  try {
    if (navigator.share) { await navigator.share({ title: 'FabrikAI', url }); }
    else { await navigator.clipboard.writeText(url); store.toast('Đã chép liên kết ảnh.', 'success'); }
  } catch (e) { /* người dùng tự huỷ sheet chia sẻ */ }
}
async function runDelete() {
  const g = store.preview;
  close();
  if (!g || !g.id) return;
  await store.deleteGen(g);
}
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
      <button type="button" class="opt" :disabled="!hasImage" @click="go('variant')">
        <span class="opt-ic opt-ic--magic"><StudioIcon name="sparkles" size="h-[18px] w-[18px]" /></span>
        <span class="opt-main"><b>Tạo biến thể AI</b><i>Diễn giải lại ảnh theo hướng mới</i></span>
        <StudioIcon name="chevronRight" size="h-4 w-4" class="text-cream-400" />
      </button>
      <button type="button" class="opt" :disabled="!hasImage || store.upscaling" @click="go('upscale')">
        <span class="opt-ic"><StudioIcon name="maximize" size="h-[18px] w-[18px]" /></span>
        <span class="opt-main"><b>Nâng cấp ảnh</b><i>Phóng to 2×/4× giữ chi tiết</i></span>
        <StudioIcon name="chevronRight" size="h-4 w-4" class="text-cream-400" />
      </button>
      <button type="button" class="opt" :disabled="!hasImage" @click="download">
        <span class="opt-ic"><StudioIcon name="download" size="h-[18px] w-[18px]" /></span>
        <span class="opt-main"><b>Tải về máy</b><i>Ảnh gốc độ phân giải đầy đủ</i></span>
      </button>
      <button type="button" class="opt" :disabled="!hasImage" @click="share">
        <span class="opt-ic"><StudioIcon name="share" size="h-[18px] w-[18px]" /></span>
        <span class="opt-main"><b>Chia sẻ</b><i>Gửi qua app khác hoặc chép liên kết</i></span>
      </button>
      <a href="/bo-suu-tap" class="opt">
        <span class="opt-ic"><StudioIcon name="ruler" size="h-[18px] w-[18px]" /></span>
        <span class="opt-main"><b>Tech pack</b><i>Phiếu kỹ thuật trong Bộ sưu tập</i></span>
      </a>
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
