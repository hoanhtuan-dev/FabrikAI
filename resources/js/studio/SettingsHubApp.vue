<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import StudioIcon from './components/StudioIcon.vue';
import MySettingsApp from './MySettingsApp.vue';
import SettingsApp from './SettingsApp.vue';
import AdminApp from './AdminApp.vue';

/**
 * TRANG HỢP NHẤT: CÀI ĐẶT & QUẢN TRỊ.
 *
 * Ba khu render NGAY TRONG trang này (SPA):
 *   · Cài đặt của tôi   (/cai-dat, /presets, /stylist-data, /model-settings) — dữ liệu riêng từng người;
 *   · Cài đặt hệ thống  (/settings) — nhà cung cấp AI · API key · model, chỉ owner;
 *   · Quản trị           (/admin)   — người dùng · gói cước · sổ credit, chỉ owner.
 * Hai khu còn lại (Hệ thống thiết kế · Chi phí theo nhóm) là trang MÁY CHỦ render — thanh chung của
 * chúng nằm ở resources/views/studio/partials/hub-bar.blade.php, nên ở đây chỉ cần điều hướng thật.
 *
 * Vì sao ba app vẫn tách rời bên trong: mỗi khu là một nghiệp vụ lớn và đã có test riêng. Việc của
 * hub là HỢP NHẤT PHẦN KHUNG NHÌN — thanh tiêu đề, bộ chuyển khu, lối về Studio — nên ba app được
 * nhúng với prop "embedded": chúng ẩn thanh tiêu đề riêng và giữ lại sidebar mục bên trong.
 *
 * [2026-09-26 · đợt 24] Đổi khu TẠI CHỖ: trước đây thanh chung là liên kết thường nên bấm "Quản trị"
 * là nạp lại cả trang. Nay với ba khu SPA thì chỉ đổi component + pushState (URL vẫn sâu, Ctrl/Cmd-
 * click vẫn mở tab mới như liên kết thật).
 */

const views = { mine: MySettingsApp, system: SettingsApp, admin: AdminApp };

const CFG = (() => {
  if (typeof document === 'undefined') return { area: 'mine', areas: [] };
  const el = document.getElementById('hub-root');
  let areas = [];
  try {
    areas = JSON.parse((el && el.getAttribute('data-areas')) || '[]') || [];
  } catch (e) {
    areas = [];
  }
  return { area: (el && el.getAttribute('data-area')) || 'mine', areas };
})();

// Danh sách khu do MÁY CHỦ quyết định (App\Support\SettingsAreas::visibleFor) rồi truyền xuống qua
// data-areas — không khai lại ở đây để không có hai danh sách lệch nhau.
const areas = ref(CFG.areas);
const area = ref(views[CFG.area] ? CFG.area : 'mine');
const active = computed(() => areas.value.find((a) => a.id === area.value) || areas.value[0] || { label: '', desc: '', icon: 'sliders' });
const view = computed(() => views[area.value] || MySettingsApp);
const switchable = computed(() => areas.value.length > 1);

/** Bấm một khu: khu SPA thì đổi TẠI CHỖ; khu máy chủ render thì để trình duyệt điều hướng thật. */
function go(event, a) {
  if (!views[a.id]) return;
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
  event.preventDefault();
  area.value = a.id;
  if (typeof history !== 'undefined') history.pushState({ area: a.id }, '', a.href);
  if (typeof window !== 'undefined') window.scrollTo({ top: 0, behavior: 'smooth' });
}

/** Nút Back/Forward của trình duyệt phải đưa về đúng khu vừa xem. */
function onPop() {
  const path = typeof location !== 'undefined' ? location.pathname : '';
  const hit = areas.value.find((a) => views[a.id] && (path === a.href || path.startsWith(a.href + '/')));
  if (hit) area.value = hit.id;
}

onMounted(() => window.addEventListener('popstate', onPop));
onBeforeUnmount(() => window.removeEventListener('popstate', onPop));
</script>

<template>
  <div class="studio-shell min-h-screen w-full">
    <!-- ═════════ Thanh tiêu đề DUY NHẤT của mọi khu ═════════ -->
    <header class="sticky top-0 z-40 border-b border-ink-700 bg-ink-900/95 backdrop-blur">
      <div class="mx-auto flex w-full max-w-[1400px] flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:px-5 lg:px-6">
        <a href="/" class="tool-btn shrink-0" title="Về xưởng thiết kế">
          <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" />
          <span class="hidden sm:inline">Studio</span>
        </a>
        <div class="min-w-0 flex-1">
          <h1 class="flex items-center gap-2 font-display text-lg font-semibold text-cream-50">
            <StudioIcon :name="active.icon" size="h-4 w-4" class="text-brand-300" />
            {{ active.label }}
          </h1>
          <p class="mt-0.5 hidden truncate text-body text-cream-300 sm:block">{{ active.desc }}</p>
        </div>

        <!-- Bộ chuyển khu (màn hình rộng) -->
        <nav v-if="switchable" class="hidden items-center gap-1 rounded-xl border border-ink-700 bg-ink-800/70 p-1 lg:flex" aria-label="Khu vực cài đặt">
          <a v-for="a in areas" :key="a.id" :href="a.href"
             :aria-current="a.id === area ? 'page' : undefined"
             :class="a.id === area
               ? 'bg-brand-600 text-primary-content shadow-sm'
               : 'text-cream-300 hover:bg-ink-700 hover:text-cream-100'"
             class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors"
             @click="go($event, a)">
            <StudioIcon :name="a.icon" size="h-3.5 w-3.5" /> {{ a.label }}
          </a>
        </nav>

        <!-- Bộ chuyển khu (màn hình hẹp): hàng cuộn ngang, mỗi mục cao ≥ 40px -->
        <div v-if="switchable" class="-mx-1 flex w-full items-center gap-1 overflow-x-auto pb-0.5 lg:hidden" role="tablist" aria-label="Khu vực cài đặt">
          <a v-for="a in areas" :key="a.id" :href="a.href"
             role="tab" :aria-selected="a.id === area ? 'true' : 'false'"
             :class="a.id === area
               ? 'border-brand-500 bg-brand-600/20 text-cream-50'
               : 'border-ink-600 bg-ink-800 text-cream-300'"
             class="flex min-h-10 shrink-0 items-center gap-1.5 rounded-lg border px-3 text-xs font-semibold"
             @click="go($event, a)">
            <StudioIcon :name="a.icon" size="h-3.5 w-3.5" /> {{ a.short }}
          </a>
        </div>
      </div>
    </header>

    <!-- Nội dung khu đang mở. Thuộc tính "embedded" để app con ẩn thanh tiêu đề riêng của nó. -->
    <component :is="view" :key="area" embedded />
  </div>
</template>
