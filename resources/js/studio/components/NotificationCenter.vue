<script setup>
/**
 * [Trục 2 — 2026-09-20] Notification Center — kiểu VSCode.
 *
 * Vấn đề cũ: `store.flashMsg` là MỘT ô duy nhất, mỗi toast mới GHI ĐÈ toast trước và tự tắt sau
 * 2,6 giây. Với thao tác hàng loạt (12 mục), thông báo "mục 3 lỗi" có thể bị thông báo sau đè mất
 * trước khi người dùng kịp đọc — mất dấu vết lỗi.
 *
 * Nay: hàng đợi xếp chồng ở góc phải-dưới (đúng vị trí thông báo của VSCode), tối đa 4 mục:
 *   · tự tắt theo loại (lỗi 8s — lâu hơn để kịp đọc; thường 4,2s)
 *   · đóng tay từng mục; nút "Xoá hết" khi có từ 2 mục
 *   · thẻ TIẾN TRÌNH riêng, chỉ hiện khi có việc đang chạy, đọc số THẬT từ store
 *     (generateProgress · batchSend · số ảnh pending/processing) — không mô phỏng.
 *
 * Không đổi hành vi: `store.toast(msg, type)` vẫn là API duy nhất các nơi khác gọi.
 */
import { computed, watch, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

const ICON = { success: 'check', error: 'alertTriangle', warning: 'alertTriangle', info: 'info' };
const TONE = {
  success: 'border-emerald-500/40 bg-emerald-950/85 text-emerald-100',
  error: 'border-red-500/50 bg-red-950/85 text-red-100',
  warning: 'border-amber-500/50 bg-amber-950/85 text-amber-100',
  info: 'border-ink-600 bg-ink-900/95 text-cream-100',
};
const ICON_TONE = {
  success: 'text-emerald-300', error: 'text-red-300', warning: 'text-amber-300', info: 'text-brand-300',
};

function iconOf(n) { return ICON[n.type] || ICON.info; }
function toneOf(n) { return TONE[n.type] || TONE.info; }
function iconToneOf(n) { return ICON_TONE[n.type] || ICON_TONE.info; }

// ── Tự tắt: mỗi thông báo một đồng hồ riêng, dọn đúng lúc (không rò timer khi unmount) ──
const timers = new Map();
watch(
  () => store.notifications.map((n) => n.id).join(','),
  () => {
    const live = new Set(store.notifications.map((n) => n.id));
    for (const n of store.notifications) {
      if (!n.ttl || timers.has(n.id)) continue;
      timers.set(n.id, setTimeout(() => { timers.delete(n.id); store.dismissNotification(n.id); }, n.ttl));
    }
    for (const [id, t] of timers) {
      if (!live.has(id)) { clearTimeout(t); timers.delete(id); }
    }
  },
  { immediate: true },
);
onBeforeUnmount(() => { for (const t of timers.values()) clearTimeout(t); timers.clear(); });

// ── Thẻ tiến trình: số THẬT, không mô phỏng ──
const runningCount = computed(() => (store.generations || []).filter((g) => ['pending', 'processing'].includes(g.status)).length);
const progress = computed(() => {
  const b = store.batchSend;
  if (b && b.total) {
    return { label: 'Đang gửi ' + b.done + '/' + b.total + ' mục', pct: Math.round((b.done / b.total) * 100) };
  }
  if (store.generating) {
    const stage = store.generateStage;
    const label = stage === 'preparing' ? 'Đang chuẩn bị…'
      : stage === 'enriching' ? 'Đang làm giàu prompt…'
        : stage === 'queued' ? 'Đang xếp hàng xử lý…'
          : stage === 'rendering' ? 'Đang tạo ảnh…'
            : 'Đang xử lý…';
    return { label, pct: Math.round(store.generateProgress || 0) };
  }
  if (runningCount.value) {
    return { label: runningCount.value + ' ảnh đang tạo', pct: Math.round(store.generateProgress || 0) };
  }
  return null;
});
</script>

<template>
  <div class="pointer-events-none fixed bottom-4 right-4 z-[95] flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-2" role="region" aria-label="Thông báo" aria-live="polite">
    <!-- Thẻ tiến trình: chỉ hiện khi có việc đang chạy -->
    <div v-if="progress" class="pointer-events-auto overflow-hidden rounded-xl border border-brand-500/40 bg-ink-900/95 shadow-2xl backdrop-blur">
      <div class="flex items-center gap-2 px-3 py-2.5">
        <span class="inline-block h-2 w-2 shrink-0 animate-pulse rounded-full bg-brand-400"></span>
        <span class="min-w-0 flex-1 truncate text-[11px] font-semibold text-cream-100">{{ progress.label }}</span>
        <span class="shrink-0 text-[11px] font-semibold tabular-nums text-brand-300">{{ progress.pct }}%</span>
      </div>
      <div class="h-1 w-full bg-ink-800">
        <div class="h-full bg-gradient-to-r from-brand-500 to-brand-300 transition-all duration-500 ease-out" :style="{ width: progress.pct + '%' }"></div>
      </div>
    </div>

    <!-- Hàng đợi thông báo -->
    <TransitionGroup
      tag="div"
      class="flex flex-col gap-2"
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="translate-x-4 opacity-0"
      enter-to-class="translate-x-0 opacity-100"
      leave-active-class="transition duration-150 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-for="n in store.notifications"
        :key="n.id"
        class="pointer-events-auto flex items-start gap-2 rounded-xl border px-3 py-2.5 shadow-2xl backdrop-blur"
        :class="toneOf(n)"
        role="status"
      >
        <StudioIcon :name="iconOf(n)" size="h-4 w-4 shrink-0 mt-0.5" :class="iconToneOf(n)" />
        <p class="min-w-0 flex-1 whitespace-pre-line break-words text-[11px] leading-snug">{{ n.msg }}</p>
        <button
          v-if="n.action"
          type="button"
          class="shrink-0 rounded-md border border-white/20 px-2 py-0.5 text-[10px] font-semibold transition hover:bg-white/10"
          @click="n.action.run(); store.dismissNotification(n.id)"
        >{{ n.action.label }}</button>
        <button
          type="button"
          class="shrink-0 rounded-md p-0.5 opacity-60 transition hover:bg-white/10 hover:opacity-100"
          title="Đóng thông báo"
          aria-label="Đóng thông báo"
          @click="store.dismissNotification(n.id)"
        ><StudioIcon name="x" size="h-3.5 w-3.5" /></button>
      </div>
    </TransitionGroup>

    <!-- Xoá hết: chỉ hiện khi có từ 2 mục trở lên (1 mục thì nút X của chính nó là đủ) -->
    <button
      v-if="store.notifications.length > 1"
      type="button"
      class="pointer-events-auto self-end rounded-full border border-ink-600 bg-ink-900/95 px-2.5 py-1 text-[10px] font-semibold text-cream-300 transition hover:bg-ink-800 hover:text-cream-100"
      @click="store.clearNotifications()"
    >Xoá hết ({{ store.notifications.length }})</button>
  </div>
</template>
