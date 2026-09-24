<script setup>
/**
 * CHÀO MỪNG — 3 slide cho NGƯỜI CHƯA ĐĂNG NHẬP (đợt 60 · 2026-09-26).
 *
 * VÌ SAO CÓ MÀN NÀY: prototype đã duyệt (`prototype/js/screens/onboarding.js`) mở đầu bằng 3 slide
 * "Nói. Chạm. Xong." → "Canvas biết nghe tay bạn." → "Xưởng may trong túi của bạn.", rồi mới tới đăng
 * nhập. Sản phẩm thật **không có màn đó**: khách vào `/` gặp một thẻ chào hai dòng rồi nút Đăng nhập —
 * tức là người mới phải GÕ MẬT KHẨU trước khi biết sản phẩm này làm được gì.
 *
 * Ba luật giữ nguyên từ prototype:
 *   · ba slide, vuốt ngang được (và có nút «Tiếp» cho người không vuốt — cử chỉ KHÔNG bao giờ là đường
 *     duy nhất, §8);
 *   · chấm chỉ vị trí: chấm đang xem dài ra (đây là tín hiệu duy nhất cho biết còn mấy slide);
 *   · slide cuối đổi nhãn nút thành «Bắt đầu» và đi tới ĐĂNG NHẬP.
 *
 * KHÁC prototype ở hai chỗ, có lý do:
 *   · Không dùng ảnh "vải" giả (gradient + noise). Sản phẩm này bán ẢNH THẬT; mở màn bằng ảnh giả là
 *     hứa một thứ không có. Thay bằng tấm nền gradient thương hiệu + icon — trung thực và nhẹ.
 *   · Có thêm lối «Đã có tài khoản? Đăng nhập»: prototype là bản demo nên không cần, người dùng thật
 *     quay lại thì cần về đích trong MỘT chạm.
 */
import { computed, ref } from 'vue';
import StudioIcon from './StudioIcon.vue';
import { haptic } from '../composables/useHaptics.js';

/**
 * Tiêu đề tách thành BA PHẦN [trước · nhấn · sau] thay vì một chuỗi có thẻ <em> + `v-html`.
 * VÌ SAO: `v-html` là một CỬA XSS, và repo có bài test bắt mọi cửa như vậy phải được rà tay
 * (`StudioXssSinksTest`). Ở đây chữ là hằng số của chính component nên rủi ro bằng 0 — nhưng thay bằng
 * ba phần thì cửa đó BIẾN MẤT hẳn, và không phải mở ngoại lệ trong bài test canh XSS.
 */
const SLIDES = [
  {
    icon: 'sparkles',
    title: ['Nói. ', 'Chạm.', ' Xong.'],
    body: 'Mô tả thiết kế bằng lời — FabrikAI dựng concept, ảnh lookbook và tech pack trong một chạm.',
  },
  {
    icon: 'sliders',
    title: ['Mọi công cụ ', 'trong tầm tay', '.'],
    body: 'Tạo ảnh · mặc thử · ghép trang phục · kịch bản quay · bộ sưu tập — mở bằng một ngón tay, không cần màn hình lớn.',
  },
  {
    icon: 'bot',
    title: ['Xưởng may ', 'trong túi', ' của bạn.'],
    body: 'Từ moodboard tới phiếu kỹ thuật — cùng một luồng, không rời điện thoại.',
  },
];

const i = ref(0);
const last = computed(() => i.value === SLIDES.length - 1);
const slide = computed(() => SLIDES[i.value]);

function next() {
  haptic(8);
  if (last.value) { window.location.href = '/dang-nhap'; return; }
  i.value++;
}
/** Vuốt ngang: sang phải để lùi, sang trái để tiến — ngưỡng 60px như prototype. */
let x0 = null;
function down(e) { x0 = e.clientX; }
function up(e) {
  if (x0 === null) return;
  const dx = e.clientX - x0;
  x0 = null;
  if (dx < -60) next();
  else if (dx > 60 && i.value > 0) { haptic(8); i.value--; }
}
</script>

<template>
  <div
    class="mx-auto flex min-h-[86dvh] w-full max-w-md flex-col px-6 pt-6"
    data-onboarding
    @pointerdown="down"
    @pointerup="up"
  >
    <!-- Sân khấu: gradient thương hiệu + icon của slide (không ảnh giả). -->
    <div class="relative flex flex-1 items-center justify-center overflow-hidden rounded-3xl border border-ink-600 bg-gradient-to-br from-brand-600/30 via-ink-800 to-clay-500/25">
      <!-- Bậc chữ dùng 4 mức ĐẶC của hệ token (không độ mờ): text-cream-100 · 300 — xem §1.1. -->
      <StudioIcon :name="slide.icon" size="h-16 w-16" class="text-cream-100" />
      <span class="absolute bottom-3 right-4 text-micro font-semibold uppercase tracking-[0.16em] text-cream-300">FabrikAI</span>
    </div>

    <div class="mt-6">
      <p class="text-micro font-semibold uppercase tracking-[0.16em] text-brand-300">Atelier · AI</p>
      <h1 class="mt-2 font-display text-[30px] font-semibold leading-[1.12] tracking-[-0.02em] text-cream-50">
        {{ slide.title[0] }}<em class="text-brand-300">{{ slide.title[1] }}</em>{{ slide.title[2] }}
      </h1>
      <p class="mt-3 text-body leading-relaxed text-cream-300">{{ slide.body }}</p>
    </div>

    <div class="mt-8 flex items-center justify-between pb-8" style="padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 24px)">
      <div class="flex gap-1.5" role="tablist" :aria-label="'Slide ' + (i + 1) + '/' + SLIDES.length">
        <button
          v-for="(s, k) in SLIDES" :key="k"
          type="button"
          class="h-1.5 rounded-full transition-all"
          :class="k === i ? 'w-5 bg-brand-500' : 'w-1.5 bg-ink-600'"
          :aria-label="'Tới slide ' + (k + 1)"
          :aria-selected="k === i"
          role="tab"
          :data-onboarding-dot="k"
          @click="i = k"
        />
      </div>
      <button
        type="button"
        class="btn-magic inline-flex h-12 items-center gap-2 rounded-full px-6 text-label font-bold transition active:scale-95"
        data-onboarding-next
        @click="next"
      >
        {{ last ? 'Bắt đầu' : 'Tiếp' }}
        <StudioIcon name="arrowRight" size="h-4 w-4" />
      </button>
    </div>

    <p class="pb-6 text-center text-micro text-cream-400">
      Đã có tài khoản?
      <a href="/dang-nhap" class="font-semibold text-brand-300 hover:text-brand-200">Đăng nhập</a>
    </p>
  </div>
</template>
