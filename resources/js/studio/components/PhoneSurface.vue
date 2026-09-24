<script setup>
/**
 * PHONE SURFACE — khung "MÀN CHIẾM TRỌN" của nhánh điện thoại (shell 2026 · đợt 59).
 *
 * VÌ SAO CÓ COMPONENT NÀY: bản điện thoại của Studio (StudioPhone.vue) cần mở những công cụ vốn
 * chỉ sống trong panel trái của màn rộng (Tạo ảnh · Mặc thử · Ghép ảnh · Kịch bản quay…) và cả
 * LƯỚI KẾT QUẢ. Trước đây mỗi thứ như vậy phải dựng một khung riêng, và mỗi khung lại quên một
 * thứ khác nhau (nút lùi · nút đóng · chừa vạch home của iPhone · tầng z-index). Nay: MỘT khung,
 * nhiều nội dung — đúng luật §15.7 "khung sheet dùng chung, cấp chỉ là NỘI DUNG".
 *
 * Ba luật của khung này (§7.2):
 *   · chiều cao bằng `fixed inset-0` trong thang tầng CỐ ĐỊNH — đây là tầng 90 (màn chiếm trọn:
 *     cùng tầng với deck sàng lọc và trình xem ảnh), trên sheet 80 và menu nổi 70;
 *   · đệm trên/dưới theo `env(safe-area-inset-*)` để thanh tiêu đề không nằm dưới tai thỏ và hàng
 *     nút cuối không nằm dưới vạch home;
 *   · đầu màn là MỘT hàng: ← lùi một cấp (tuỳ chọn) · tên việc · nút đổi · ✕ đóng hết.
 *
 * "← lùi một cấp" và "✕ đóng hết" là HAI việc khác nhau (§15.7 luật 4): `back` bật nút ← và phát
 * sự kiện 'back' cho nơi gọi tự quyết định lùi về đâu; ✕ luôn đóng cả chuỗi. Khung KHÔNG tự biết
 * cấp trước là gì — đó là việc của ngăn xếp điều hướng ở nơi gọi.
 */
import StudioIcon from './StudioIcon.vue';

defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  icon: { type: String, default: 'square' },
  /** Bật nút ← (lùi MỘT cấp). Tắt khi màn này được mở thẳng từ màn chính. */
  back: { type: Boolean, default: false },
  /** Nhãn nút "đổi" (vd «Đổi công cụ»). Rỗng = không hiện nút. */
  switchLabel: { type: String, default: '' },
  /** false = nội dung tự lo cuộn (dùng cho lưới kết quả, vốn có vùng cuộn riêng). */
  scroll: { type: Boolean, default: true },
});
const emit = defineEmits(['update:open', 'back', 'switch']);
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="motion-fade-in fixed inset-0 z-[90] flex flex-col bg-ink-950 text-cream-100"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
      data-phone-surface
    >
      <header
        class="elev-bar flex shrink-0 items-center gap-1 border-b border-ink-700 bg-ink-900 px-2 pb-2"
        style="padding-top: calc(env(safe-area-inset-top, 0px) + 8px)"
      >
        <button
          v-if="back"
          type="button"
          class="icon-btn !h-10 !w-10"
          title="Lùi một cấp"
          aria-label="Lùi một cấp"
          data-phone-surface-back
          @click="emit('back')"
        >
          <StudioIcon name="arrowLeft" size="h-5 w-5" />
        </button>
        <span class="panel-title min-w-0 flex-1 px-1">
          <StudioIcon :name="icon" size="h-4 w-4" class="shrink-0 text-brand-300" />
          <span class="truncate">{{ title }}</span>
        </span>
        <button
          v-if="switchLabel"
          type="button"
          class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-3 text-label font-semibold text-cream-200 transition hover:border-brand-400"
          data-phone-surface-switch
          @click="emit('switch')"
        >
          <StudioIcon name="sliders" size="h-3.5 w-3.5" class="text-brand-300" />{{ switchLabel }}
        </button>
        <button
          type="button"
          class="icon-btn !h-10 !w-10"
          title="Đóng"
          aria-label="Đóng"
          data-phone-surface-close
          @click="emit('update:open', false)"
        >
          <StudioIcon name="x" size="h-5 w-5" />
        </button>
      </header>
      <div class="min-h-0 flex-1" :class="scroll ? 'overflow-y-auto' : 'overflow-hidden'">
        <slot />
      </div>
    </div>
  </Teleport>
</template>
