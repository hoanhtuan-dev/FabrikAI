<script setup>
// DockResizer — VÁCH NGĂN KÉO DÙNG CHUNG cho mọi dock co/giãn được (bảng trái · dock Outputs · …).
//
// Không tự giữ trạng thái: nhận "bộ điều khiển" từ useDockResize() nên mọi dock có Y HỆT hành vi
// (kéo · bàn phím · nhấp đúp) và y hệt phần trợ năng. Nhờ vậy không còn cảnh dock này kéo được
// còn dock kia thì không, hay mỗi bên một kiểu phím tắt.
//
// Phần hình dáng nằm ở .dock-resizer / .dock-resizer__grip / .dock-resizer__knob trong
// resources/css/app.css.
//
// Vì sao có TAY CẦM (knob) chứ không chỉ một đường kẻ: đường kẻ 1px chỉ người đã biết mới thấy.
// Tay cầm hiện sẵn ở mức mờ ngay khi tải trang, có icon grip — người dùng nhìn ra "chỗ này kéo được"
// mà không phải đoán; trỏ vào / focus bàn phím / đang kéo thì nó sáng rõ và nở ra một nhịp.
import StudioIcon from './StudioIcon.vue';

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
    <!-- Tay cầm chỉ là HÌNH: tên, giá trị và hướng đã nằm trên role="separator" ở cha nên
         trình đọc màn hình không đọc thêm gì; pointerdown vẫn bắt ở vách ngăn (e.currentTarget)
         nên kéo từ chính tay cầm vẫn chạy y như kéo ở mép. -->
    <span class="dock-resizer__knob" aria-hidden="true">
      <StudioIcon name="gripVertical" size="h-3.5 w-3.5" />
    </span>
  </div>
</template>
