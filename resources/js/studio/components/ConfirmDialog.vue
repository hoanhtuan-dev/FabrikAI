<script setup>
/**
 * ConfirmDialog — POPUP XÁC NHẬN DÙNG CHUNG cho mọi hành động phá huỷ.
 *
 * Vì sao có file này: mỗi hành động trước đây tự chép lại một popup y hệt nhau (dọn canvas · xóa nền AI),
 * nên thêm hành động mới là thêm một bản sao nữa. Nặng hơn: hành động XÓA ĐỐI TƯỢNG ĐANG CHỌN chỉ đặt
 * cờ confirmDeleteOpen mà KHÔNG có popup nào render ⇒ bấm Delete (hoặc nút thùng rác) không thấy gì xảy
 * ra, cờ thì treo lại và còn CHẶN luôn phím tắt layer. Nay mọi xác nhận đi qua đúng một component này.
 *
 * Hình dáng KẾ THỪA popup " Dọn toàn bộ canvas?" đang dùng: nền đen mờ, khung max-w-xs, viền đỏ khi
 * là hành động nguy hiểm, hai nút [hành động | Hủy].
 *
 * Trợ năng: role=dialog + aria-modal; Esc và bấm nền = hủy; focus vào nút hành động khi mở và TRẢ focus
 * về đúng chỗ cũ khi đóng (nếu không, người dùng bàn phím bị mất điểm dừng — đúng lỗi đã gặp ở dock).
 * Hiệu ứng vào/ra dùng CƠ SỞ CHUYỂN ĐỘNG chung (motion-fade-in · motion-pop-in).
 */
import { ref, watch, nextTick, onBeforeUnmount } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, required: true },
  confirmLabel: { type: String, default: 'Xóa' },
  cancelLabel: { type: String, default: 'Hủy' },
  /** true = hành động phá huỷ (nút đỏ + viền đỏ); false = hành động thường (nút thương hiệu). */
  danger: { type: Boolean, default: true },
});
const emit = defineEmits(['confirm', 'cancel']);

const confirmBtn = ref(null);
let restoreTo = null;   // phần tử cần trả focus khi đóng

const cancel = () => emit('cancel');

// Esc = hủy. Bắt ở pha CAPTURE và chặn lan ra: nếu không, phím Esc còn chạy tiếp các handler toàn cục
// của Studio (bỏ chọn layer, thoát công cụ…) trong lúc người dùng chỉ định đóng popup.
function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); cancel(); } }

watch(() => props.open, (isOpen) => {
  if (typeof document === 'undefined') return;
  if (isOpen) {
    // Chụp focus TRƯỚC khi DOM đổi — sau khi popup hiện thì không còn biết người dùng vừa đứng ở đâu.
    restoreTo = document.activeElement;
    window.addEventListener('keydown', onKey, true);
    nextTick(() => { if (confirmBtn.value) confirmBtn.value.focus(); });
  } else {
    window.removeEventListener('keydown', onKey, true);
    const back = restoreTo;
    restoreTo = null;
    if (back && typeof back.focus === 'function' && document.contains(back)) nextTick(() => back.focus());
  }
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true));
</script>

<template>
  <div
    v-if="open"
    role="dialog"
    aria-modal="true"
    :aria-label="title"
    class="motion-fade-in fixed inset-0 z-[80] flex items-center justify-center bg-scrim/60 p-4"
    @click.self="cancel"
  >
    <div
      class="motion-pop-in w-full max-w-xs rounded-lg border bg-ink-900 p-4 shadow-2xl"
      :class="danger ? 'border-danger/40' : 'border-ink-700'"
    >
      <p class="text-sm font-semibold text-cream-100">{{ title }}</p>
      <p class="mt-1 text-xs leading-relaxed text-cream-300"><slot /></p>
      <div class="mt-3 flex gap-2">
        <button
          ref="confirmBtn"
          type="button"
          @click="emit('confirm')"
          class="flex-1 rounded-lg px-3 py-2 text-sm font-semibold"
          :class="danger ? 'bg-danger text-danger-content hover:bg-danger' : 'bg-brand-600 text-primary-content hover:bg-brand-500'"
        >{{ confirmLabel }}</button>
        <button
          type="button"
          @click="cancel"
          class="flex-1 rounded-lg bg-ink-800 px-3 py-2 text-sm font-semibold text-cream-200 hover:bg-ink-700"
        >{{ cancelLabel }}</button>
      </div>
    </div>
  </div>
</template>
