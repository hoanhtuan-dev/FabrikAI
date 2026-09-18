<script setup>
// DockResizer — VÁCH NGĂN KÉO DÙNG CHUNG cho mọi dock co/giãn được (bảng trái · dock Outputs · …).
//
// Không tự giữ trạng thái: nhận "bộ điều khiển" từ useDockResize() nên mọi dock có Y HỆT hành vi
// (kéo · bàn phím · nhấp đúp) và y hệt phần trợ năng. Nhờ vậy không còn cảnh dock này kéo được
// còn dock kia thì không, hay mỗi bên một kiểu phím tắt.
//
// Phần hình dáng nằm ở .dock-resizer / .dock-resizer__grip trong resources/css/app.css.
defineProps({
  /** Bộ điều khiển trả về từ useDockResize() — object reactive (width · min · max · resizing…). */
  dock: { type: Object, required: true },
  /** id của panel mà vách ngăn điều khiển (aria-controls): trình đọc màn hình biết nó thuộc về đâu. */
  controls: { type: String, default: '' },
});
</script>

<template>
  <div
    class="dock-resizer"
    role="separator"
    aria-orientation="vertical"
    tabindex="0"
    :aria-label="dock.label"
    :aria-controls="controls || null"
    :aria-valuenow="dock.width"
    :aria-valuemin="dock.min"
    :aria-valuemax="dock.max"
    :data-resizing="dock.resizing ? 'true' : 'false'"
    :title="dock.hint"
    @pointerdown="dock.onPointerDown"
    @keydown="dock.onKeydown"
    @dblclick.prevent="dock.reset()"
  >
    <span class="dock-resizer__grip" aria-hidden="true"></span>
  </div>
</template>
