<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import StudioIcon from './components/StudioIcon.vue';
import SettingsToasts from './components/settings/SettingsToasts.vue';
import PresetSection from './components/settings/PresetSection.vue';
import FacesSection from './components/settings/FacesSection.vue';
import StylistSection from './components/settings/StylistSection.vue';
import { notify } from './composables/useSettingsToast.js';
import { setCatalogErrorHandler } from './composables/useLocalCatalog.js';

/**
 * KHU "CÀI ĐẶT CỦA TÔI" HỢP NHẤT.
 *
 * [Vấn đề gốc 2026-09-20] Trước đây là 4 trang SPA RỜI RẠC (/presets, /stylist-data,
 * /model-settings?tab=model, /model-settings?tab=pose). Mỗi trang chỉ có một link "← Về FabrikAI":
 * muốn sang mục khác PHẢI quay về Studio rồi mở lại menu bánh răng. Bốn trang cũng tự chế bốn kiểu
 * tiêu đề, ba kiểu thông báo và hai kiểu hộp thoại xác nhận khác nhau.
 *
 * Nay: MỘT app, MỘT sidebar, MỘT khay thông báo, MỘT hộp thoại xác nhận.
 *
 * Vì sao vẫn giữ 4 URL cũ thay vì chuyển hướng hết về /cai-dat:
 *   · bookmark và link đang dùng tiếp tục chạy;
 *   · trang cũ trả 200 đúng như hợp đồng đã có (tests/Feature/UserCatalogTest.php khoá bất biến này);
 *   · đổi mục thì cập nhật URL bằng history.pushState sang /cai-dat/<mục> nên vẫn deep-link được.
 */

const SECTIONS = [
  { id: 'presets', label: 'Preset', icon: 'palette', desc: 'Mẫu prompt dùng trong Studio', hint: 'Cặp key: value được chèn vào prompt khi bạn chọn nó trong Studio.' },
  { id: 'model', label: 'Khuôn mặt', icon: 'user', desc: 'Khuôn mặt người mẫu', hint: 'Dùng cho Thay người mẫu và Ghép ảnh. Mục bạn thêm là của riêng bạn.' },
  { id: 'pose', label: 'Dáng pose', icon: 'image', desc: 'Dáng đứng của người mẫu', hint: 'Dáng đứng áp dụng khi tạo ảnh người mẫu.' },
  { id: 'stylist', label: 'Trợ lý thiết kế', icon: 'sparkles', desc: 'Loại trang phục + bộ câu hỏi', hint: 'Dữ liệu dùng bởi card Trợ lý thiết kế trong Studio.' },
];

// Lớp lưu trữ catalog (nay ghi lên server) báo lỗi qua hook thay vì import vue — xem ràng buộc 1
// trong useLocalCatalog.js. Nối vào khay thông báo để người dùng BIẾT khi tùy chỉnh không lưu được,
// thay vì tưởng đã lưu rồi mất dữ liệu.
setCatalogErrorHandler(notify.err);

const section = ref('presets');
const navOpen = ref(false);   // sidebar dạng ngăn kéo trên màn hình hẹp

const USER = (() => {
  if (typeof document === 'undefined') return { id: '', admin: false };
  const el = document.querySelector('[data-user-id]');
  return {
    id: (el && el.getAttribute('data-user-id')) || '',
    admin: ((el && el.getAttribute('data-user-admin')) || '0') === '1',
  };
})();

const active = computed(() => SECTIONS.find((s) => s.id === section.value) || SECTIONS[0]);
const component = computed(() => (section.value === 'presets' ? PresetSection : (section.value === 'stylist' ? StylistSection : FacesSection)));

/** Mục model/pose dùng CHUNG một component, phân biệt bằng prop `kind`. */
const componentProps = computed(() => (section.value === 'model' || section.value === 'pose' ? { kind: section.value } : {}));

function go(id, push = true) {
  if (!SECTIONS.some((s) => s.id === id)) return;
  section.value = id;
  navOpen.value = false;
  if (push && typeof history !== 'undefined') history.pushState({ section: id }, '', '/cai-dat/' + id);
  if (typeof window !== 'undefined') window.scrollTo({ top: 0, behavior: 'smooth' });
}

function onPop() {
  const m = (typeof location !== 'undefined' ? location.pathname : '').match(/\/cai-dat\/([a-z-]+)/);
  if (m && SECTIONS.some((s) => s.id === m[1])) section.value = m[1];
}

onMounted(() => {
  // Mục ban đầu do SERVER quyết định: mỗi route cũ chiếu vào đúng một mục qua data-section.
  // Nhờ vậy /model-settings?tab=pose mở đúng mục Dáng pose mà không cần đoán từ URL.
  const el = document.querySelector('[data-section]');
  const fromServer = (el && el.getAttribute('data-section')) || '';
  const fromPath = (location.pathname.match(/\/cai-dat\/([a-z-]+)/) || [])[1] || '';
  const initial = SECTIONS.some((s) => s.id === fromServer) ? fromServer : (SECTIONS.some((s) => s.id === fromPath) ? fromPath : 'presets');
  section.value = initial;
  window.addEventListener('popstate', onPop);
});
onBeforeUnmount(() => window.removeEventListener('popstate', onPop));
</script>

<template>
  <div class="studio-dark min-h-screen w-full">
    <div class="mx-auto flex w-full max-w-[100rem] lg:gap-6 lg:px-6 lg:py-6">

      <!-- Sidebar (màn hình rộng) -->
      <aside class="hidden w-64 shrink-0 lg:block">
        <div class="sticky top-6 flex max-h-[calc(100vh-3rem)] flex-col overflow-hidden rounded-xl border border-ink-700 bg-ink-800/70">
          <div class="border-b border-ink-700 px-4 py-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-cream-300/50">FabrikAI</p>
            <h1 class="mt-1 font-display text-base font-semibold text-cream-50">Cài đặt của tôi</h1>
          </div>
          <nav class="min-h-0 flex-1 overflow-y-auto p-2" aria-label="Các mục cài đặt">
            <button v-for="s in SECTIONS" :key="s.id" @click="go(s.id)"
                    :class="section === s.id ? 'bg-brand-600/20 text-cream-50 ring-1 ring-inset ring-brand-500/50' : 'text-cream-300 hover:bg-ink-700/60 hover:text-cream-100'"
                    class="mb-1 flex w-full items-start gap-2.5 rounded-lg px-3 py-2.5 text-left transition"
                    :aria-current="section === s.id ? 'page' : undefined">
              <StudioIcon :name="s.icon" size="mt-0.5 h-4 w-4 shrink-0" />
              <span class="min-w-0 flex-1">
                <span class="block text-xs font-semibold">{{ s.label }}</span>
                <span class="mt-0.5 block text-[10px] leading-snug text-cream-300/60">{{ s.desc }}</span>
              </span>
            </button>
          </nav>
          <div class="border-t border-ink-700 p-3">
            <a href="/" class="flex items-center justify-center gap-1.5 rounded-lg border border-ink-600 px-3 py-2 text-[11px] font-medium text-cream-200 transition hover:border-cream-300 hover:bg-cream-300 hover:text-ink-900">
              <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" /> Về xưởng thiết kế
            </a>
            <p v-if="USER.admin" class="mt-2 text-center text-[10px] text-amber-300/70">Tài khoản owner</p>
          </div>
        </div>
      </aside>

      <!-- Nội dung -->
      <main class="min-w-0 flex-1">
        <!-- Chọn mục cho màn hình hẹp (sidebar bị ẩn dưới lg) -->
        <div class="border-b border-ink-700 bg-ink-900/95 px-4 py-3 lg:hidden">
          <div class="flex items-center justify-between gap-2">
            <h1 class="font-display text-base font-semibold text-cream-50">Cài đặt của tôi</h1>
            <button @click="navOpen = !navOpen" class="btn-outline btn-sm" :aria-expanded="navOpen">
              <StudioIcon name="layers" size="h-3.5 w-3.5" /> {{ active.label }}
            </button>
          </div>
          <div v-if="navOpen" class="mt-2 grid gap-1.5">
            <button v-for="s in SECTIONS" :key="s.id" @click="go(s.id)"
                    :class="section === s.id ? 'border-brand-500/50 bg-brand-600/20 text-cream-50' : 'border-ink-700 bg-ink-800 text-cream-300'"
                    class="flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-xs font-medium">
              <StudioIcon :name="s.icon" size="h-3.5 w-3.5" /> {{ s.label }}
            </button>
            <a href="/" class="flex items-center gap-2 rounded-lg border border-ink-700 bg-ink-800 px-3 py-2 text-xs font-medium text-cream-300">
              <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" /> Về xưởng thiết kế
            </a>
          </div>
        </div>

        <div class="px-4 py-5 lg:px-0 lg:py-0">
          <header class="mb-5">
            <div class="flex items-center gap-2.5">
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-600/15 text-brand-300">
                <StudioIcon :name="active.icon" size="h-4.5 w-4.5" />
              </span>
              <div class="min-w-0">
                <h2 class="font-display text-lg font-semibold text-cream-50">{{ active.label }}</h2>
                <p class="mt-0.5 text-[11px] leading-snug text-cream-300/60">{{ active.hint }}</p>
              </div>
            </div>
          </header>

          <component :is="component" v-bind="componentProps" :key="section" />
        </div>
      </main>
    </div>

    <SettingsToasts />
  </div>
</template>
