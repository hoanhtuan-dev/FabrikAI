<script setup>
/**
 * COMMAND BAR — thanh lệnh DUY NHẤT của shell 2026 (docs/DESIGN_SYSTEM.md · Phase 0).
 *
 * Một dock đáy biến hình theo ngữ cảnh:
 *   · mode="prompt"  — orb (1 chạm → menu Không gian) · ô nhập prompt · nút Tạo (lớp .btn-magic).
 *   · mode="tools"   — orb · cụm công cụ (slot #tools) · hành động chính (slot #primary).
 *   · running        — lớp .aurora-ring (app.css) vẽ viền quay quanh thanh khi AI đang chạy.
 *
 * Mọi thời lượng dùng token --motion-dur-* (MotionFoundationTest), mọi gradient dùng lớp
 * dùng chung trong app.css (DesignSystemTest §2) — component KHÔNG tự khai.
 */
import { ref } from 'vue';
import StudioIcon from './StudioIcon.vue';
import { usePopmenu } from '../composables/usePopmenu.js';
import { spacesForViewport } from '../spaces.js';

const props = defineProps({
  mode: { type: String, default: 'prompt' },      // prompt · tools
  placeholder: { type: String, default: 'Mô tả thiết kế bạn mơ…' },
  running: { type: Boolean, default: false },
  space: { type: String, default: '' },           // id không gian hiện tại (đánh dấu trong menu)
});
const emit = defineEmits(['go']);

const { openPopmenu } = usePopmenu();
const text = ref('');

function openSpaces(e) {
  const rect = e.currentTarget.getBoundingClientRect();
  openPopmenu({
    anchor: rect,
    dir: 'up',
    items: spacesForViewport().map((s) => ({
      id: s.id, icon: s.icon, label: s.label, desc: s.desc, url: s.url, active: s.id === props.space,
    })),
  });
}

function go() {
  emit('go', text.value.trim());
  text.value = '';
}
</script>

<template>
  <div class="cb" :class="{ 'aurora-ring': running }" role="toolbar" aria-label="Thanh lệnh">
    <button type="button" class="cb-orb" aria-label="Mở menu không gian" title="Không gian" @click="openSpaces">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M12 2l8.66 5v10L12 22l-8.66-5V7L12 2z" stroke="url(#cbg)" stroke-width="1.8" stroke-linejoin="round"/>
        <path d="M12 8l3.5 2v4L12 16l-3.5-2v-4L12 8z" fill="url(#cbg)"/>
        <defs><linearGradient id="cbg" x1="3" y1="3" x2="21" y2="21">
          <stop stop-color="var(--color-brand-400)"/><stop offset="1" stop-color="var(--color-clay-500)"/>
        </linearGradient></defs>
      </svg>
    </button>

    <div v-if="mode === 'prompt'" class="cb-prompt">
      <input
        v-model="text"
        type="text"
        :placeholder="placeholder"
        enterkeyhint="go"
        class="cb-input"
        @keydown.enter="go"
      >
    </div>
    <div v-else class="cb-tools"><slot name="tools" /></div>

    <button
      v-if="mode === 'prompt'"
      type="button"
      class="cb-go btn-magic"
      aria-label="Tạo"
      :disabled="running"
      @click="go"
    >
      <StudioIcon name="sparkles" size="h-5 w-5" />
    </button>
    <slot v-else name="primary" />
  </div>
</template>

<style scoped>
/* Cục bộ BỐ CỤC thanh lệnh; màu/motion/gradient đều qua token & lớp dùng chung. */
.cb {
  position: fixed; left: 12px; right: 12px; bottom: calc(env(safe-area-inset-bottom, 0px) + 10px);
  z-index: 60; min-height: 64px; border-radius: 24px; display: flex; align-items: center; gap: 8px;
  padding: 8px; background: rgb(16 16 21 / 0.88);
  backdrop-filter: blur(26px) saturate(1.4); -webkit-backdrop-filter: blur(26px) saturate(1.4);
  box-shadow: inset 0 0 0 1px var(--color-ink-600), 0 18px 44px rgb(0 0 0 / 0.45);
}
[data-theme="light"] .cb { background: rgb(250 247 240 / 0.92); }
.cb-orb {
  width: 48px; height: 48px; border-radius: 16px; flex: none; display: grid; place-items: center;
  background: var(--color-ink-800); box-shadow: inset 0 0 0 1px var(--color-ink-600);
  transition: transform var(--motion-dur-fast) var(--motion-ease-emphasized);
}
.cb-orb:active { transform: scale(.9); }
.cb-prompt { flex: 1; display: flex; align-items: center; min-width: 0; }
.cb-input {
  flex: 1; min-width: 0; height: 48px; background: none; border: 0; outline: 0;
  font-size: 15px; color: var(--color-cream-100);
}
.cb-input::placeholder { color: var(--color-cream-400); }
.cb-go {
  width: 48px; height: 48px; border-radius: 16px; flex: none; display: grid; place-items: center;
  transition: transform var(--motion-dur-fast) var(--motion-ease-emphasized);
}
.cb-go:active { transform: scale(.9); }
.cb-go[disabled] { opacity: .5; }
.cb-tools { flex: 1; display: flex; align-items: center; justify-content: space-evenly; min-width: 0; }
</style>
