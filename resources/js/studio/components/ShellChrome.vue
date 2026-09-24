<script setup>
/**
 * SHELL CHROME — CommandBar + PopMenu đóng gói MỘT LẦN cho các trang ngoài Studio
 * (Agent · Bộ sưu tập · Hub). Mỗi app chỉ cần <ShellChrome space="…" />.
 *
 * Thanh lệnh là VŨ TRỤ: gõ prompt ở bất kỳ không gian nào cũng đưa thẳng sang Studio
 * kèm nội dung (?prompt=) — ý tưởng đến đâu, việc bắt đầu từ đó, không cần về trang chủ.
 */
import CommandBar from './CommandBar.vue';
import PopMenu from './PopMenu.vue';

defineProps({
  space: { type: String, default: '' },
  placeholder: { type: String, default: 'Mô tả thiết kế bạn mơ…' },
});

function go(q) {
  window.location.assign(q ? '/studio?prompt=' + encodeURIComponent(q) : '/studio');
}
</script>

<template>
  <CommandBar mode="prompt" :space="space" :placeholder="placeholder" @go="go" />
  <PopMenu />
</template>
