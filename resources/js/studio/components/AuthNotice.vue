<script setup>
// AuthNotice — banner trạng thái xác thực.
// [2026-09-17 · Đợt 0.1 — Q1] Chống "403 im lặng": thay vì để người dùng đoán, màn hình
// nói ĐÚNG 3 trạng thái riêng biệt (store.authState — xem store.js):
//    guest        → "Bạn chưa đăng nhập" + nút đăng nhập
//    expired      → "Phiên đã hết hạn"   + nút đăng nhập lại
//    unauthorized → "Chưa được cấp quyền" (đã đăng nhập nhưng bị 403) — thì nói rõ cần admin cấp.
// Trạng thái này cùng nguồn với toast, nhưng là NƠI DUY NHẤT in ra cho màn hình, còn toast chỉ
// lặp lại lỗi thao tác cụ thể.
import { useStudioStore } from '../store.js';
const store = useStudioStore();

const copy = {
  guest: {
    title: 'Bạn chưa đăng nhập',
    body: 'Đăng nhập để dùng xưởng thiết kế: tạo ảnh người mẫu, sửa ảnh, dựng bộ sưu tập.',
    action: 'Đăng nhập',
    href: '/dang-nhap?redirect=/',
    icon: 'login',
  },
  expired: {
    title: 'Phiên làm việc đã hết hạn',
    body: 'Vì lý do bảo mật, bạn cần đăng nhập lại để tiếp tục thiết kế.',
    action: 'Đăng nhập lại',
    href: '/dang-nhap?redirect=/',
    icon: 'login',
  },
  unauthorized: {
    title: 'Tài khoản chưa được cấp quyền dùng FabrikAI',
    body: 'Bạn đã đăng nhập, nhưng tài khoản chưa được kích hoạt để dùng xưởng thiết kế. Vui lòng liên hệ quản trị viên.',
    action: '',
    href: '',
    icon: 'lock',
  },
};
</script>

<template>
  <!-- role/aria BẮT BUỘC: bất biến a11y của repo (StaticIntegrityTest::test_full_screen_overlays_declare_their_role)
       đòi mọi lớp phủ "fixed inset-0" có nội dung phải khai báo vai trò hộp thoại. -->
  <div
    v-if="store.authState !== 'ok'"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="auth-notice-title"
    class="fixed inset-0 z-[98] grid place-items-center bg-ink-950/95 p-4"
  >
    <div class="w-full max-w-md rounded-2xl border border-ink-700 bg-ink-900 p-6 text-center shadow-2xl">
      <div class="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-full bg-brand-600/15 text-brand-300">
        <span v-if="copy[store.authState].icon === 'login'" class="text-2xl" role="img" aria-hidden="true">→</span>
        <span v-else class="text-2xl" role="img" aria-hidden="true">🔒</span>
      </div>
      <h1 id="auth-notice-title" class="font-display text-lg font-semibold text-cream-100">{{ copy[store.authState].title }}</h1>
      <p class="mt-2 text-sm leading-relaxed text-cream-300">{{ copy[store.authState].body }}</p>

      <a
        v-if="copy[store.authState].href"
        :href="copy[store.authState].href"
        class="mt-5 inline-flex items-center gap-2 rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-500"
      >{{ copy[store.authState].action }}</a>
      <p v-else class="mt-5 text-xs text-cream-400">Liên hệ quản trị viên qua fanpage / email đã đăng ký trên website.</p>
    </div>
  </div>
</template>
