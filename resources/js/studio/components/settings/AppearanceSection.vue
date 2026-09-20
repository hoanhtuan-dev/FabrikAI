<script setup>
import { ref } from 'vue';
import StudioIcon from '../StudioIcon.vue';
import { useTheme } from '../../composables/useTheme.js';
import { notify } from '../../composables/useSettingsToast.js';

/**
 * MỤC "GIAO DIỆN" trong khu Cài đặt của tôi (2026-09-23).
 *
 * Ba lựa chọn Sáng · Tối · Theo hệ điều hành. Theme áp NGAY khi bấm (không có nút "Lưu"),
 * và được lưu theo TÀI KHOẢN (users.theme) + cache ở máy để lần sau vào là đúng ngay từ
 * khung hình đầu, không nháy màu.
 *
 * Vì sao nói thẳng chuyện "lưu lên tài khoản": nếu request gửi hỏng, lựa chọn vẫn có tác
 * dụng trên MÁY NÀY nhưng máy khác sẽ vẫn là giao diện cũ. Người dùng phải biết điều đó —
 * im lặng là biến một lỗi mạng thành một câu hỏi kiểu "sao máy kia lại khác?".
 */
const { pref, options, setTheme } = useTheme();
const busy = ref(false);

async function choose(id) {
  if (busy.value || pref.value === id) return;
  busy.value = true;
  try {
    const res = await setTheme(id);
    if (res && res.saved) notify.ok('Đã đổi giao diện và lưu vào tài khoản.');
    else if (res && res.error) notify.err(res.error);
    else notify.ok('Đã đổi giao diện cho lần dùng này.');
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <!-- Khối này cố ý dùng ĐÚNG bộ tên token của daisyUI (bg-base-100 · bg-base-300 · text-base-content ·
       bg-primary) để lớp ngữ nghĩa mới không chỉ tồn tại trong tài liệu mà chạy thật trong giao diện. -->
  <section class="rounded-xl border border-base-300 bg-base-200 p-4">
    <h2 class="font-display text-sm font-semibold text-cream-50">Giao diện</h2>
    <p class="mt-1 text-[11px] leading-relaxed text-cream-300">
      Chọn giao diện Sáng hoặc Tối. Lựa chọn được lưu theo tài khoản nên mở trên máy khác vẫn đúng,
      và có hiệu lực ngay — không cần tải lại trang.
    </p>

    <div class="mt-3 grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Chọn giao diện">
      <button v-for="opt in options" :key="opt.id" type="button" role="radio"
              :aria-checked="pref === opt.id ? 'true' : 'false'"
              :disabled="busy"
              @click="choose(opt.id)"
              :class="pref === opt.id
                ? 'border-brand-500 bg-primary/15 text-base-content'
                : 'border-ink-600 bg-base-100 text-base-content hover:border-brand-400'"
              class="motion-ui flex items-start gap-2.5 rounded-lg border p-3 text-left disabled:opacity-60">
        <StudioIcon :name="opt.icon" size="mt-0.5 h-4 w-4 shrink-0" />
        <span class="min-w-0 flex-1">
          <span class="flex items-center gap-1.5 text-xs font-semibold">
            {{ opt.label }}
            <StudioIcon v-if="pref === opt.id" name="check" size="h-3.5 w-3.5 shrink-0" />
          </span>
          <span class="mt-1 block text-[10px] leading-snug text-cream-300">{{ opt.desc }}</span>
        </span>
      </button>
    </div>

    <!-- Một dòng nói RÕ nguồn gốc bảng màu: người dùng đã đưa một theme daisyUI để tham chiếu. -->
    <div class="mt-3 space-y-2">
      <p class="rounded-lg border border-base-300 bg-base-100 p-3 text-[10px] leading-relaxed text-cream-200">
        <b class="text-base-content">Bảng màu:</b> nền và màu trạng thái lấy theo <b class="text-base-content">bộ token của daisyUI</b>
        (<span class="font-mono">base-100 · base-200 · base-300 · base-content · primary · secondary · accent · neutral ·
        info · success · warning · error</span> cùng cặp <span class="font-mono">-content</span>) — xem bảng đầy đủ kèm tỉ lệ tương phản
        ở <span class="font-mono">/he-thong-thiet-ke</span> (cấp Owner). Màu <b class="text-base-content">thương hiệu xanh lá vẫn giữ nguyên</b> và đóng vai <span class="font-mono">primary</span>.
      </p>
      <p class="rounded-lg border border-base-300 bg-base-100 p-3 text-[10px] leading-relaxed text-cream-200">
        Mọi bậc chữ đạt chuẩn <b class="text-base-content">WCAG AA</b> (≥ 4.5:1 trên nền của nó) — đo bằng công thức tương phản,
        không ước lượng bằng mắt. Nếu bạn vẫn thấy chữ khó đọc ở một chỗ cụ thể, hãy báo tên màn hình đó.
      </p>
    </div>
  </section>
</template>
