<script setup>
/**
 * [Single source of truth — 2026-09-17] TOÀN BỘ icon nằm ở `resources/js/studio/icons.json`.
 *
 * File JSON đó là nguồn DUY NHẤT, được CẢ HAI phía đọc:
 *   · Vue (file này) — render ra SVG;
 *   · PHP (App\Support\IconRegistry) — liệt kê/kiểm tra icon cho trang quản trị.
 *
 * Trước đây hai danh sách song song (một ở đây, một hằng số trong StudioGuiConfig) nên thêm icon
 * là phải sửa 2 chỗ; quên một chỗ thì owner chọn phải icon không tồn tại và nút render TRỐNG mà
 * không có lỗi nào. Nay thêm icon = thêm 1 khoá vào JSON, mọi nơi tự có.
 *
 * Mỗi mục: { "svg": "<path …/>", "note": "…" } — `note` là tuỳ chọn, giữ lại tài liệu ngắn
 * về ý nghĩa icon (nền tảng cho tính năng quản lý icon toàn hệ thống sau này).
 */
import ICONS from '../icons.json';

defineProps({
  name: { type: String, required: true },
  size: { type: String, default: 'h-4 w-4' },
});
</script>

<template>
  <svg :class="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="(ICONS[name] && ICONS[name].svg) || ''"></svg>
</template>
