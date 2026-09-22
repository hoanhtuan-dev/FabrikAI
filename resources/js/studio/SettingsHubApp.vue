<script setup>
import { computed } from 'vue';
import StudioIcon from './components/StudioIcon.vue';
import MySettingsApp from './MySettingsApp.vue';
import SettingsApp from './SettingsApp.vue';
import AdminApp from './AdminApp.vue';

/**
 * TRANG HỢP NHẤT: CÀI ĐẶT & QUẢN TRỊ.
 *
 * Ba khu, một khung nhìn:
 *   · Cài đặt của tôi   (/cai-dat, /presets, /stylist-data, /model-settings) — dữ liệu riêng từng người;
 *   · Cài đặt hệ thống  (/settings) — nhà cung cấp AI · API key · model, chỉ owner;
 *   · Quản trị           (/admin)   — người dùng · gói cước · sổ credit, chỉ owner.
 *
 * Vì sao ba app vẫn tách rời bên trong: mỗi khu là một nghiệp vụ lớn và đã có test riêng. Việc của
 * hub là HỢP NHẤT PHẦN KHUNG NHÌN — thanh tiêu đề, bộ chuyển khu, lối về Studio — nên ba app được
 * nhúng với prop "embedded": chúng ẩn thanh tiêu đề riêng và giữ lại sidebar mục bên trong.
 */

const CFG = (() => {
  if (typeof document === 'undefined') return { area: 'mine', admin: false };
  const el = document.getElementById('hub-root');
  return {
    area: (el && el.getAttribute('data-area')) || 'mine',
    admin: ((el && el.getAttribute('data-user-admin')) || '0') === '1',
  };
})();

const AREAS = [
  {
    id: 'mine',
    label: 'Cài đặt của tôi',
    short: 'Của tôi',
    href: '/cai-dat',
    icon: 'user',
    desc: 'Preset · khuôn mặt · dáng pose · trợ lý thiết kế · giao diện — dữ liệu riêng của bạn.',
  },
  {
    id: 'system',
    label: 'Cài đặt hệ thống',
    short: 'Hệ thống',
    href: '/settings',
    icon: 'gear',
    desc: 'Nhà cung cấp AI · API key · model — áp dụng cho mọi tài khoản FabrikAI.',
    ownerOnly: true,
  },
  {
    id: 'admin',
    label: 'Quản trị',
    short: 'Quản trị',
    href: '/admin',
    icon: 'shieldCheck',
    desc: 'Người dùng · gói cước · sổ credit · giao diện Studio — thao tác ảnh hưởng toàn hệ thống.',
    ownerOnly: true,
  },
];

// Khu chỉ owner: ẩn khỏi menu cho tài khoản thường. Máy chủ VẪN chặn thật bằng middleware
// auth+admin — ẩn ở đây chỉ để người dùng không bấm vào chỗ họ không có quyền.
const areas = computed(() => AREAS.filter((a) => !a.ownerOnly || CFG.admin));
const area = computed(() => (areas.value.some((a) => a.id === CFG.area) ? CFG.area : 'mine'));
const active = computed(() => areas.value.find((a) => a.id === area.value) || areas.value[0]);

const views = { mine: MySettingsApp, system: SettingsApp, admin: AdminApp };
const view = computed(() => views[area.value] || MySettingsApp);
</script>

<template>
  <div class="studio-shell min-h-screen w-full">
    <!-- ═════════ Thanh tiêu đề DUY NHẤT của cả ba khu ═════════ -->
    <header class="sticky top-0 z-40 border-b border-ink-700 bg-ink-900/95 backdrop-blur">
      <div class="mx-auto flex w-full max-w-[1400px] flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:px-5 lg:px-6">
        <a href="/" class="tool-btn shrink-0" title="Về xưởng thiết kế">
          <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" />
          <span class="hidden sm:inline">Studio</span>
        </a>
        <div class="min-w-0 flex-1">
          <h1 class="flex items-center gap-2 font-display text-lg font-semibold text-cream-50">
            <StudioIcon name="sliders" size="h-4 w-4" class="text-brand-300" />
            {{ active.label }}
          </h1>
          <p class="mt-0.5 hidden truncate text-body text-cream-300 sm:block">{{ active.desc }}</p>
        </div>

        <!-- Bộ chuyển khu (màn hình rộng) -->
        <nav class="hidden items-center gap-1 rounded-xl border border-ink-700 bg-ink-800/70 p-1 lg:flex" aria-label="Khu vực cài đặt">
          <a v-for="a in areas" :key="a.id" :href="a.href"
             :aria-current="a.id === area ? 'page' : undefined"
             :class="a.id === area
               ? 'bg-brand-600 text-primary-content shadow-sm'
               : 'text-cream-300 hover:bg-ink-700 hover:text-cream-100'"
             class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors">
            <StudioIcon :name="a.icon" size="h-3.5 w-3.5" /> {{ a.label }}
          </a>
        </nav>

        <!-- Bộ chuyển khu (màn hình hẹp): hàng cuộn ngang, mỗi mục cao ≥ 40px -->
        <div class="-mx-1 flex w-full items-center gap-1 overflow-x-auto pb-0.5 lg:hidden" role="tablist" aria-label="Khu vực cài đặt">
          <a v-for="a in areas" :key="a.id" :href="a.href"
             role="tab" :aria-selected="a.id === area ? 'true' : 'false'"
             :class="a.id === area
               ? 'border-brand-500 bg-brand-600/20 text-cream-50'
               : 'border-ink-600 bg-ink-800 text-cream-300'"
             class="flex min-h-10 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold">
            <StudioIcon :name="a.icon" size="h-3.5 w-3.5" /> {{ a.short }}
          </a>
        </div>
      </div>
    </header>

    <!-- Nội dung khu đang mở. Thuộc tính "embedded" để app con ẩn thanh tiêu đề riêng của nó. -->
    <component :is="view" embedded />
  </div>
</template>
