<script setup>
/**
 * POP MENU — vỏ hiển thị của usePopmenu (xem composable để biết cách dùng).
 *
 * Mount MỘT lần ở gốc app (ngang CommandBar). Tự định vị theo anchor, tự lật hướng khi sát
 * mép, đóng khi chạm nền/Escape. Item có "url" thì điều hướng, có "onSelect" thì gọi hàm.
 */
import { computed, watch } from 'vue';
import StudioIcon from './StudioIcon.vue';
import { usePopmenu } from '../composables/usePopmenu.js';
import { haptic } from '../composables/useHaptics.js';

const { state, closePopmenu } = usePopmenu();

/* Vị trí menu: neo theo anchor, kẹp trong khung nhìn, lật lên khi anchor ở nửa dưới. */
const pos = computed(() => {
  if (!state.anchor) return { left: '12px', top: '50%' };
  const a = state.anchor;
  const ax = a.left ?? 12;
  const ay = a.top ?? 12;
  const W = window.innerWidth, H = window.innerHeight, MW = 250;
  const left = Math.max(12, Math.min(W - MW - 12, ax - 8));
  const itemH = 52, mh = Math.min(state.items.length * itemH + 12, H * 0.7);
  const up = state.dir === 'up' || (state.dir !== 'down' && ay > H * 0.55);
  const top = up ? Math.max(60, ay - 10 - mh) : Math.min(H - mh - 16, ay + 10);
  return { left: left + 'px', top: top + 'px', transformOrigin: (up ? 'bottom' : 'top') + ' left' };
});

function pick(item) {
  haptic(8);
  closePopmenu();
  if (item.onSelect) { item.onSelect(item); return; }
  if (item.url) { window.location.assign(item.url); }
}

function onKey(e) {
  if (e.key === 'Escape') closePopmenu();
}
watch(() => state.open, (v) => {
  if (v) { window.addEventListener('keydown', onKey); }
  else { window.removeEventListener('keydown', onKey); }
});
</script>

<template>
  <Teleport to="body">
    <div v-if="state.open" class="pm-root" @contextmenu.prevent>
      <div class="pm-bg" @pointerdown="closePopmenu" />
      <div class="pm-card" role="menu" :style="pos">
        <button
          v-for="(item, i) in state.items"
          :key="item.id ?? item.label"
          type="button"
          role="menuitem"
          class="pm-item"
          :class="{ 'pm-item--on': item.active }"
          :style="{ animationDelay: (i * 26) + 'ms' }"
          @click="pick(item)"
        >
          <span class="pm-ic"><StudioIcon :name="item.icon" size="h-[18px] w-[18px]" /></span>
          <span class="pm-main">
            <span class="pm-t">{{ item.label }}</span>
            <span v-if="item.desc" class="pm-s">{{ item.desc }}</span>
          </span>
          <StudioIcon v-if="item.active" name="check" size="h-4 w-4" class="text-brand-300" />
        </button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* Cục bộ cho lớp phủ — màu qua token theme, nhịp qua token --motion-dur-*. */
.pm-root { position: fixed; inset: 0; z-index: 90; }
.pm-bg { position: absolute; inset: 0; background: rgb(4 4 7 / 0.42); backdrop-filter: blur(2px); }
.pm-card {
  position: absolute; width: 250px; max-height: 70dvh; overflow-y: auto; scrollbar-width: none;
  border-radius: 20px; padding: 6px; background: rgb(19 19 24 / 0.92);
  backdrop-filter: blur(26px) saturate(1.4); -webkit-backdrop-filter: blur(26px) saturate(1.4);
  box-shadow: inset 0 0 0 1px var(--color-ink-600), 0 22px 54px rgb(0 0 0 / 0.5);
  animation: pm-in var(--motion-dur-base) var(--motion-ease-emphasized) both;
}
[data-theme="light"] .pm-card { background: rgb(250 247 240 / 0.95); }
@keyframes pm-in { from { opacity: 0; transform: scale(.88); } }
.pm-item {
  display: flex; align-items: center; gap: 11px; width: 100%; padding: 9px 10px;
  border-radius: 14px; text-align: left; opacity: 0;
  animation: pm-item-in var(--motion-dur-base) var(--motion-ease-standard) forwards;
}
@keyframes pm-item-in { from { opacity: 0; transform: translateY(9px); } to { opacity: 1; transform: none; } }
.pm-item:hover { background: var(--color-ink-700); }
.pm-ic {
  width: 36px; height: 36px; flex: none; border-radius: 11px; display: grid; place-items: center;
  background: var(--color-ink-800); color: var(--color-cream-300);
}
.pm-item--on .pm-ic { background: var(--color-brand-600); color: var(--color-primary-content); }
.pm-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pm-t { font-size: 14px; font-weight: 600; line-height: 1.2; color: var(--color-cream-100); }
.pm-s { font-size: 11.5px; line-height: 1.35; color: var(--color-cream-400); }
</style>
