<script setup>
// Thanh ngữ cảnh nổi khi CHỌN NHIỀU layer (shift+click): căn lề · chia đều · TẠO NHÓM ·
// bắt điểm preset · tách nhóm · xóa. Icon chuẩn ngành (Lucide) + mono theme.
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();

const snapOptions = [['0','Tắt'],['8','8px'],['16','16px'],['24','24px'],['32','32px']];
// Căn lề: [kind, icon, tooltip]
const al = [
  ['left','alignStartHorizontal','Căn trái'],
  ['hcenter','alignCenterHorizontal','Căn ngang'],
  ['right','alignEndHorizontal','Căn phải'],
  ['top','alignStartVertical','Căn trên'],
  ['vcenter','alignCenterVertical','Căn dọc'],
  ['bottom','alignEndVertical','Căn dưới'],
];
const ico = 'grid h-7 w-7 place-items-center rounded-lg border border-transparent text-cream-200 transition hover:bg-ink-700 hover:text-cream-100 disabled:opacity-30 disabled:hover:bg-transparent';
const lblBtn = 'flex items-center gap-1 rounded-lg px-2 py-1 text-[10px] font-semibold transition';
const sep = 'h-4 w-px shrink-0 bg-ink-600';
</script>
<template>
  <div class="pointer-events-auto flex flex-wrap items-center gap-0.5 rounded-lg border border-ink-700/80 bg-ink-900/95 px-1.5 py-1 shadow-2xl ring-1 ring-ink-700/40 backdrop-blur">
    <!-- Số layer -->
    <span class="flex items-center gap-1.5 px-2 text-[11px] font-semibold text-cream-300/80"><StudioIcon name="layers" size="h-3.5 w-3.5" class="text-brand-300"/> {{ store.selectionUnitCount }} đối tượng</span>
    <span :class="sep"></span>

    <!-- Căn lề -->
    <button v-for="a in al" :key="a[0]" @click="store.alignSelection(a[0])" :class="ico" :title="a[2]"><StudioIcon :name="a[1]" size="h-3.5 w-3.5"/></button>
    <span :class="sep"></span>

    <!-- Chia đều khoảng cách X/Y -->
    <button @click="store.distributeSelection('x')" :class="ico" :disabled="store.selectionUnitCount < 3" title="Chia đều khoảng cách theo trục X"><StudioIcon name="distributeHorizontal" size="h-3.5 w-3.5"/></button>
    <button @click="store.distributeSelection('y')" :class="ico" :disabled="store.selectionUnitCount < 3" title="Chia đều khoảng cách theo trục Y"><StudioIcon name="distributeVertical" size="h-3.5 w-3.5"/></button>
    <span :class="sep"></span>

    <!-- Tạo nhóm / Tách nhóm -->
    <button @click="store.groupSelection()" :class="[lblBtn, 'text-cream-100 hover:bg-brand-600 hover:text-white']" title="Tạo nhóm từ các layer đang chọn (click 1 layer trong nhóm = chọn cả nhóm)"><StudioIcon name="group" size="h-3.5 w-3.5"/>Nhóm</button>
    <button @click="store.ungroupSelection()" :class="[lblBtn, 'text-cream-200 hover:bg-ink-700']" title="Tách nhóm — trả các layer về độc lập"><StudioIcon name="unlink" size="h-3.5 w-3.5"/>Tách</button>
    <span :class="sep"></span>

    <!-- Tải hàng loạt -->
    <button @click="store.downloadSelection()" :class="[lblBtn, 'text-cream-200 hover:bg-ink-700']" title="Tải tất cả các layer đang chọn về máy"><StudioIcon name="download" size="h-3.5 w-3.5"/>Tải</button>
    <span :class="sep"></span>

    <!-- Xóa -->
    <button @click="store.deleteSelection()" :class="[lblBtn, 'text-red-300 hover:bg-red-600/25 hover:text-red-200']" title="Xóa các layer đang chọn (Delete)"><StudioIcon name="trash" size="h-3.5 w-3.5"/>Xóa</button>
  </div>
</template>