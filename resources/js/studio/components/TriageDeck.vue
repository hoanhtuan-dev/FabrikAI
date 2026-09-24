<script setup>
/**
 * TRIAGE DECK — sàng lọc kết quả một lượt tạo bằng VUỐT (Phase 3 · shell 2026, phone-first).
 *
 * Sau khi một batch (≥2 ảnh) hoàn tất, deck tự mở: vuốt PHẢI = GIỮ, vuốt TRÁI = BỎ (xoá thật
 * qua store.deleteGen — BỎ chính là hành động loại, không phải ẩn tạm). Hai nút ✕ / ✓ dưới đáy
 * làm cùng việc cho ai không vuốt (và cho trình đọc màn hình).
 *
 * Chỉ mount trên StudioPhone: desktop đã có lưới kết quả + chọn nhiều; deck là câu trả lời
 * cho màn hẹp một-tay, nơi lưới thumbnail khó ra quyết định nhanh.
 */
import { computed, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open']);

const store = useStudioStore();

/* Ảnh của batch hiện tại đã hoàn tất (theo thứ tự tạo). */
const queue = computed(() => {
  const ids = store.lastBatch || [];
  const byId = new Map((store.generations || []).map((g) => [g.id, g]));
  return ids.map((id) => byId.get(id)).filter((g) => g && g.media_url && g.status === 'completed');
});

const idx = ref(0);            // con trỏ thẻ hiện tại
const kept = ref(0);
const dropped = ref(0);
const current = computed(() => queue.value[idx.value] || null);

/* ── Vuốt: pointer events, xoay theo quãng ngang, ngưỡng quyết định 90px ── */
const dx = ref(0);
const dragging = ref(false);
let startX = 0;
const cardStyle = computed(() => ({
  transform: 'translateX(' + dx.value + 'px) rotate(' + (dx.value / 14) + 'deg)',
  transition: dragging.value ? 'none' : 'transform var(--motion-dur-base) var(--motion-ease-emphasized)',
}));

function down(e) { dragging.value = true; startX = e.clientX; e.currentTarget.setPointerCapture(e.pointerId); }
function move(e) { if (dragging.value) dx.value = e.clientX - startX; }
function up() {
  if (!dragging.value) return;
  dragging.value = false;
  if (dx.value > 90) decide(true);
  else if (dx.value < -90) decide(false);
  dx.value = 0;
}

async function decide(keep) {
  const g = current.value;
  if (!g) return;
  dx.value = 0;
  if (keep) { kept.value++; }
  else { dropped.value++; await store.deleteGen(g); }
  idx.value++;
  if (idx.value >= queue.value.length) finish();
}

function finish() {
  store.toast('Xong — giữ ' + kept.value + ' · bỏ ' + dropped.value + '.', 'success');
  emit('update:open', false);
}

/* Mở lại deck = duyệt từ đầu batch hiện tại. */
watch(() => props.open, (v) => { if (v) { idx.value = 0; kept.value = 0; dropped.value = 0; dx.value = 0; } });
</script>

<template>
  <Teleport to="body">
    <div v-if="open && queue.length" class="tri-root" role="dialog" aria-label="Sàng lọc kết quả">
      <div class="tri-head">
        <span class="text-micro uppercase tracking-[0.16em] text-cream-400">Sàng lọc lượt tạo</span>
        <span class="text-label font-semibold text-cream-200">{{ Math.min(idx + 1, queue.length) }}/{{ queue.length }}</span>
      </div>

      <div class="tri-stage">
        <!-- Thẻ kế tiếp lộ dưới để tạo chiều sâu -->
        <div v-if="queue[idx + 1]" class="tri-card tri-card--under">
          <img :src="queue[idx + 1].media_url" alt="" class="h-full w-full object-cover" draggable="false">
        </div>
        <div
          v-if="current"
          :key="current.id"
          class="tri-card"
          :style="cardStyle"
          @pointerdown="down"
          @pointermove="move"
          @pointerup="up"
          @pointercancel="up"
        >
          <img :src="current.media_url" :alt="current.prompt || ('Ảnh #' + current.id)" class="h-full w-full object-cover" draggable="false">
          <span class="tri-badge tri-badge--keep" :style="{ opacity: Math.max(0, dx / 90) }">GIỮ</span>
          <span class="tri-badge tri-badge--drop" :style="{ opacity: Math.max(0, -dx / 90) }">BỎ</span>
        </div>
      </div>

      <div class="tri-actions">
        <button type="button" class="tri-btn border border-danger/40 text-danger" aria-label="Bỏ ảnh này" @click="decide(false)">
          <StudioIcon name="x" size="h-6 w-6" />
        </button>
        <button type="button" class="tri-btn border border-ok/40 text-ok" aria-label="Giữ ảnh này" @click="decide(true)">
          <StudioIcon name="check" size="h-6 w-6" />
        </button>
      </div>
      <p class="tri-hint">Vuốt phải để giữ · vuốt trái để bỏ (xoá hẳn)</p>
    </div>
  </Teleport>
</template>

<style scoped>
.tri-root {
  position: fixed; inset: 0; z-index: 70; display: flex; flex-direction: column;
  background: var(--color-ink-950); padding: calc(env(safe-area-inset-top, 0px) + 14px) 20px calc(env(safe-area-inset-bottom, 0px) + 18px);
  animation: tri-in var(--motion-dur-base) var(--motion-ease-standard) both;
}
@keyframes tri-in { from { opacity: 0; } }
.tri-head { display: flex; align-items: center; justify-content: space-between; }
.tri-stage { position: relative; flex: 1; margin: 14px 0; min-height: 0; }
.tri-card {
  position: absolute; inset: 0; border-radius: 26px; overflow: hidden; touch-action: none;
  background: var(--color-ink-800); box-shadow: inset 0 0 0 1px var(--color-ink-600), 0 24px 60px rgb(0 0 0 / 0.5);
  user-select: none; -webkit-user-select: none; cursor: grab;
}
.tri-card--under { transform: scale(.94) translateY(10px); pointer-events: none; }
.tri-badge {
  position: absolute; top: 18px; padding: 6px 14px; border-radius: 12px;
  font-size: 15px; font-weight: 800; letter-spacing: .08em; border: 2px solid currentColor;
}
.tri-badge--keep { left: 18px; color: var(--color-ok); transform: rotate(-10deg); }
.tri-badge--drop { right: 18px; color: var(--color-danger); transform: rotate(10deg); }
.tri-actions { display: flex; justify-content: center; gap: 26px; }
.tri-btn {
  width: 62px; height: 62px; border-radius: 999px; display: grid; place-items: center;
  background: var(--color-ink-800);
  transition: transform var(--motion-dur-fast) var(--motion-ease-emphasized);
}
.tri-btn:active { transform: scale(.88); }
.tri-hint { margin-top: 12px; text-align: center; font-size: 11.5px; color: var(--color-cream-400); }
</style>
