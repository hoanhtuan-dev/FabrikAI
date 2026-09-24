<script setup>
/**
 * TRANG CHỦ — prompt-first (shell 2026 · Phase 1).
 *
 * Trước 2026-09-24, '/' mở thẳng Studio (canvas). Nay '/' là sảnh: một câu hỏi "Hôm nay bạn
 * muốn tạo gì?", bốn lối vào việc, và rail ảnh gần đây. Studio dời sang '/studio'.
 *
 * Khách vãng lai (shell công khai) thấy màn chào + lối vào đăng nhập — payload boot có
 * user=null, rail gần đây 401 → hiển thị trạng thái trống thay vì lỗi.
 */
import { onMounted, ref } from 'vue';
import CommandBar from './components/CommandBar.vue';
import PopMenu from './components/PopMenu.vue';
import StudioIcon from './components/StudioIcon.vue';
import { thumbUrl, onThumbError } from './composables/useStudioThumb.js';

const boot = window.__STUDIO_BOOT__ || {};
const user = boot.user || null;

const recents = ref([]);
const loadingRecents = ref(false);

const INTENTS = [
  { icon: 'sparkles',   label: 'Concept',    url: '/studio' },
  { icon: 'bot',        label: 'Agent',      url: '/agent-studio' },
  { icon: 'folderOpen', label: 'Bộ sưu tập', url: '/bo-suu-tap' },
  { icon: 'image',      label: 'Thư viện',   url: '/studio?view=library' },
];

function greeting() {
  const h = new Date().getHours();
  if (h < 11) return 'Chào buổi sáng';
  if (h < 18) return 'Chào buổi chiều';
  return 'Chào buổi tối';
}

function goPrompt(q) {
  window.location.assign(q ? '/studio?prompt=' + encodeURIComponent(q) : '/studio');
}

// [Phase 5] Rảnh tay thì TẢI TRƯỚC bundle Studio: người dùng gõ prompt xong sang /studio
// là mở gần như tức thì. URL bundle do blade tính sẵn (Vite::asset) — không đọc manifest phía client.
function prefetchStudio() {
  const url = window.__STUDIO_MAIN_URL__;
  if (!url || document.querySelector('link[data-prefetch-studio]')) return;
  const l = document.createElement('link');
  l.rel = 'modulepreload'; l.href = url; l.dataset.prefetchStudio = '1';
  document.head.appendChild(l);
}

onMounted(async () => {
  if ('requestIdleCallback' in window) window.requestIdleCallback(prefetchStudio, { timeout: 4000 });
  else setTimeout(prefetchStudio, 2000);
  if (!user) return;
  loadingRecents.value = true;
  try {
    const r = await fetch('/api/latest', { headers: { Accept: 'application/json' } });
    if (r.ok) {
      const d = await r.json();
      recents.value = (d.items || []).filter((g) => g.media_url).slice(0, 12);
    }
  } catch (e) { /* rail trống không phải lỗi nặng — bỏ qua */ }
  loadingRecents.value = false;
});
</script>

<template>
  <div class="min-h-dvh bg-ink-900 pb-28 text-cream-100">
    <!-- Khách vãng lai: màn chào gọn -->
    <div v-if="!user" class="mx-auto flex min-h-[80dvh] w-full max-w-md flex-col items-center justify-center gap-6 px-6 text-center">
      <div class="font-display text-3xl font-semibold">FabrikAI</div>
      <p class="text-body text-cream-300">Xưởng thiết kế thời trang AI — concept, lookbook và tech pack trong một chạm.</p>
      <a href="/dang-nhap" class="btn-brand inline-flex items-center gap-2">Đăng nhập <StudioIcon name="arrowRight" size="h-4 w-4" /></a>
      <a href="/dang-ky" class="text-label text-brand-300 hover:text-brand-200">Tạo tài khoản mới</a>
    </div>

    <template v-else>
      <header class="mx-auto flex w-full max-w-3xl items-center justify-between px-5 pt-6">
        <div class="flex flex-col gap-0.5">
          <span class="text-micro uppercase tracking-[0.16em] text-cream-400">{{ greeting() }}</span>
          <span class="text-title font-semibold">{{ user.name }}</span>
        </div>
        <a href="/bang-gia" class="inline-flex h-9 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-3.5 text-label font-semibold text-cream-200 transition hover:border-brand-400" title="Credit & gói">
          <StudioIcon name="zap" size="h-3.5 w-3.5" class="text-brand-300" />
          {{ Number(user.credits_balance ?? 0).toLocaleString('vi') }}
        </a>
      </header>

      <main class="mx-auto w-full max-w-3xl px-5">
        <h1 class="mt-8 font-display text-[34px] font-semibold leading-[1.08] tracking-[-0.02em]">
          Hôm nay bạn<br>muốn <em class="not-italic text-brand-300">tạo</em> gì?
        </h1>

        <!-- 4 lối vào việc -->
        <div class="mt-6 grid grid-cols-4 gap-2.5">
          <a
            v-for="it in INTENTS" :key="it.label" :href="it.url"
            class="flex flex-col items-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 py-4 text-[11.5px] font-semibold text-cream-300 transition hover:border-brand-400 hover:text-cream-100 active:scale-95"
          >
            <StudioIcon :name="it.icon" size="h-5 w-5" class="text-brand-300" />
            {{ it.label }}
          </a>
        </div>

        <!-- Rail gần đây -->
        <section class="mt-9">
          <div class="flex items-center justify-between">
            <h2 class="text-title font-semibold">Gần đây</h2>
            <a href="/studio?view=library" class="text-label font-semibold text-brand-300 hover:text-brand-200">Xem tất cả</a>
          </div>
          <div v-if="recents.length" class="-mx-5 mt-3 flex gap-3 overflow-x-auto px-5 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <a
              v-for="g in recents" :key="g.id" href="/studio?view=library"
              class="block w-32 shrink-0 overflow-hidden rounded-2xl border border-ink-600 bg-ink-800 transition active:scale-95"
            >
              <img :src="thumbUrl(g.media_url, 320)" :alt="g.prompt || 'Thiết kế'" class="aspect-[3/4] w-full object-cover" loading="lazy" @error="onThumbError($event, g.media_url)">
            </a>
          </div>
          <div v-else class="mt-3 rounded-2xl border border-dashed border-ink-600 px-5 py-8 text-center text-body text-cream-400">
            {{ loadingRecents ? 'Đang tải…' : 'Chưa có thiết kế nào — nhập prompt ở thanh dưới để bắt đầu.' }}
          </div>
        </section>

        <!-- Lối tắt nhanh -->
        <section class="mt-7 grid grid-cols-2 gap-2.5">
          <a href="/agent-studio" class="rounded-2xl border border-ink-600 bg-ink-800 p-4 transition hover:border-brand-400 active:scale-[0.98]">
            <StudioIcon name="bot" size="h-5 w-5" class="text-clay-500" />
            <div class="mt-2.5 text-label font-semibold">Design Agent</div>
            <div class="mt-1 text-micro text-cream-400">Nghiên cứu xu hướng & dựng BST</div>
          </a>
          <a href="/bo-suu-tap" class="rounded-2xl border border-ink-600 bg-ink-800 p-4 transition hover:border-brand-400 active:scale-[0.98]">
            <StudioIcon name="folderOpen" size="h-5 w-5" class="text-clay-500" />
            <div class="mt-2.5 text-label font-semibold">Bộ sưu tập</div>
            <div class="mt-1 text-micro text-cream-400">Quản lý look & dự án</div>
          </a>
        </section>
      </main>
    </template>

    <CommandBar v-if="user" mode="prompt" space="home" placeholder="Mô tả thiết kế bạn mơ…" @go="goPrompt" />
    <PopMenu />
  </div>
</template>
