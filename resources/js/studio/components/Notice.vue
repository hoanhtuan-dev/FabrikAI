<script setup>
/**
 * NOTICE — băng thông báo có thể ĐÓNG (2026-09-21).
 *
 * VÌ SAO CÓ FILE NÀY: các bước của Agent Studio dùng nhiều băng role=status để nói "brief đã cũ",
 * "AI đang chạy bằng bộ quy tắc", "tổng size chưa bằng 100%"… Chúng KHÔNG tự tắt và KHÔNG có nút đóng —
 * người dùng phản hồi đúng một lỗi UX: muốn gạt đi mà không được.
 *
 * Băng này là băng DẠNG CHUNG cho mọi trường hợp đó:
 *   · tự đóng khi người dùng bấm;
 *   · khi watchKey ĐỔI (vd lỗi mới khác lỗi cũ, hay brief vừa được tạo lại) thì băng HIỆN LẠI dù đã đóng
 *     — vì điều kiện đằng sau nó đã khác;
 *   · nút đóng 24×24, đúng §8 (WCAG 2.2 SC 2.5.8).
 */
import { ref, watch } from 'vue';
import StudioIcon from './StudioIcon.vue';

const props = defineProps({
  tone: { type: String, default: 'info' },   // info · warn · danger · ok
  watchKey: { type: [String, Number], default: '' },
  dismissible: { type: Boolean, default: true },
});

const TONES = {
  info: 'border-ink-700 text-cream-200',
  warn: 'border-warn/40 text-warn',
  danger: 'border-danger/40 text-danger',
  ok: 'border-ok/40 text-ok',
};
const ICON = { info: 'info', warn: 'info', danger: 'alertTriangle', ok: 'check' };

const dismissed = ref(false);
watch(() => props.watchKey, () => { dismissed.value = false; });
</script>

<template>
  <div
    v-if="!dismissed"
    role="status"
    class="flex items-start gap-2 rounded-xl border px-3 py-2 text-body leading-5"
    :class="TONES[tone]"
  >
    <StudioIcon :name="ICON[tone]" size="h-4 w-4" class="mt-0.5 shrink-0" />
    <div class="min-w-0 flex-1"><slot /></div>
    <button
      v-if="dismissible"
      type="button"
      class="grid h-6 w-6 shrink-0 place-items-center rounded-md text-cream-200 transition hover:bg-ink-800 hover:text-cream-50"
      title="Đóng thông báo"
      aria-label="Đóng thông báo"
      @click="dismissed = true"
    >
      <StudioIcon name="x" size="h-3.5 w-3.5" />
    </button>
  </div>
</template>
