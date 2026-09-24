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
import BottomSheet from './components/BottomSheet.vue';
import StudioIcon from './components/StudioIcon.vue';
import OnboardingSlides from './components/OnboardingSlides.vue';
import { thumbUrl, onThumbError } from './composables/useStudioThumb.js';

const boot = window.__STUDIO_BOOT__ || {};
const user = boot.user || null;

const recents = ref([]);
const loadingRecents = ref(false);

/**
 * BỐN LỐI VÀO VIỆC — theo ĐÚNG prototype (`home.js`: Concept · Photoshoot · Lookbook · Tech pack).
 *
 * Trước đợt này bốn ô là Concept · Agent · Bộ sưu tập · Thư viện — tức là hai ô ĐIỀU HƯỚNG trộn vào hai
 * ô CÔNG VIỆC, và không ô nào trả lời câu hỏi của chính màn hình ("hôm nay bạn muốn TẠO gì?"). Nay bốn ô
 * là bốn VIỆC, mỗi ô mở thẳng công cụ làm việc đó trong Studio qua `?tool=` (StudioApp đọc tham số này
 * (StudioApp đọc ĐÚNG tham số `?panel=` mà /bo-suu-tap và Agent Studio đang dùng — một từ vựng, không
 * đẻ thêm tham số thứ hai; trên điện thoại nó mở thẳng công cụ đó qua prop phoneToolRequest).
 * Agent · Bộ sưu tập · Hub vẫn còn nguyên lối vào: menu KHÔNG GIAN ở orb của thanh lệnh.
 */
const INTENTS = [
  { icon: 'sparkles',   label: 'Concept',    hint: 'Tạo ảnh từ mô tả',         url: '/studio?panel=concept' },
  { icon: 'camera',     label: 'Photoshoot', hint: 'Người mẫu · bối cảnh',     url: '/studio?panel=compose' },
  { icon: 'shirt',      label: 'Lookbook',   hint: 'Ghép cả bộ trang phục',    url: '/studio?panel=outfit' },
  { icon: 'ruler',      label: 'Tech pack',  hint: 'Phiếu kỹ thuật & giá vốn', url: '/bo-suu-tap' },
];

/**
 * HOẠT ĐỘNG GẦN ĐÂY (chuông) — dữ liệu THẬT từ `/api/latest`, KHÔNG lọc theo `media_url`.
 *
 * VÌ SAO KHÔNG DÙNG LẠI rail "Gần đây": rail chỉ hiện ảnh ĐÃ XONG (nó là ảnh để nhìn). Chuông trả lời
 * câu khác — "việc của tôi đang thế nào" — nên nó phải thấy cả ảnh đang chạy và ảnh lỗi. Cùng endpoint,
 * khác bộ lọc.
 */
const activity = ref([]);
const activityOpen = ref(false);
const activityLoading = ref(false);
// Nhãn trạng thái: bản sao CÓ Ý THỨC của `store.statusLabel` (store/getters.js). Không import store vào
// trang này: entry Trang chủ cố ý nhẹ (không kéo theo store + canvas của xưởng) — thêm store là tăng
// gấp nhiều lần gói JS mà mọi khách vãng lai đều tải. Bốn chuỗi thì rẻ hơn nhiều so với cả một store.
const STATUS_LABEL = { pending: 'Đang chờ', processing: 'Đang tạo', completed: 'Xong', failed: 'Lỗi', cancelled: 'Đã huỷ' };
function statusLabel(s) { return STATUS_LABEL[s] || s; }

/**
 * RADAR XU HƯỚNG — nguồn THẬT: sổ nguồn ngoài mà agent đã đọc (`/api/design-agent/findings`).
 * Rỗng thì KHÔNG hiện thẻ (thẻ "radar" không có dữ liệu là thẻ nói dối).
 */
const radar = ref(null);

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

async function loadRadar() {
  try {
    const r = await fetch('/api/design-agent/findings?limit=1', { headers: { Accept: 'application/json' } });
    if (!r.ok) return;
    const d = await r.json();
    const it = (d.items || [])[0];
    if (it && it.title) radar.value = it;
  } catch (e) { /* không có radar thì không hiện thẻ — không phải lỗi chặn ai */ }
}

/** Mở chuông: nạp HOẠT ĐỘNG (mọi trạng thái) rồi hiện sheet. Nạp lại mỗi lần mở để số liệu không cũ. */
async function openActivity() {
  activityOpen.value = true;
  activityLoading.value = true;
  try {
    const r = await fetch('/api/latest', { headers: { Accept: 'application/json' } });
    if (r.ok) { const d = await r.json(); activity.value = (d.items || []).slice(0, 8); }
  } catch (e) { /* sheet sẽ nói "không đọc được" thay vì treo */ }
  activityLoading.value = false;
}
function fmtTime(s) { return s ? String(s) : ''; }

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
  loadRadar();
});
</script>

<template>
  <div class="min-h-dvh bg-ink-900 pb-28 text-cream-100">
    <!-- Khách vãng lai: 3 SLIDE CHÀO MỪNG theo prototype (#/onboarding) rồi mới tới đăng nhập.
         Trước đây là một thẻ chào hai dòng: người mới phải GÕ MẬT KHẨU trước khi biết sản phẩm làm gì. -->
    <OnboardingSlides v-if="!user" />

    <template v-else>
      <header class="mx-auto flex w-full max-w-3xl items-center justify-between px-5 pt-6">
        <div class="flex flex-col gap-0.5">
          <span class="text-micro uppercase tracking-[0.16em] text-cream-400">{{ greeting() }}</span>
          <span class="text-title font-semibold">{{ user.name }}</span>
        </div>
        <div class="flex items-center gap-2">
          <a href="/bang-gia" class="inline-flex h-9 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-3.5 text-label font-semibold text-cream-200 transition hover:border-brand-400" title="Credit & gói">
            <StudioIcon name="zap" size="h-3.5 w-3.5" class="text-brand-300" />
            {{ Number(user.credits_balance ?? 0).toLocaleString('vi') }}
          </a>
          <!-- Chuông: việc của tôi đang thế nào (kể cả ảnh đang chạy và ảnh lỗi). -->
          <button
            type="button"
            class="grid h-9 w-9 place-items-center rounded-full border border-ink-600 bg-ink-800 text-cream-200 transition hover:border-brand-400"
            title="Hoạt động gần đây"
            aria-label="Hoạt động gần đây"
            data-home-bell
            @click="openActivity"
          >
            <StudioIcon name="bell" size="h-4 w-4" />
          </button>
        </div>
      </header>

      <main class="mx-auto w-full max-w-3xl px-5">
        <h1 class="mt-8 font-display text-[34px] font-semibold leading-[1.08] tracking-[-0.02em]">
          Hôm nay bạn<br>muốn <em class="not-italic text-brand-300">tạo</em> gì?
        </h1>

        <!-- 4 lối vào VIỆC (prototype: Concept · Photoshoot · Lookbook · Tech pack) -->
        <div class="mt-6 grid grid-cols-4 gap-2.5">
          <a
            v-for="it in INTENTS" :key="it.label" :href="it.url"
            class="flex flex-col items-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 py-4 text-center text-[11.5px] font-semibold text-cream-300 transition hover:border-brand-400 hover:text-cream-100 active:scale-95"
            :title="it.hint"
            :data-home-intent="it.label.toLowerCase()"
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

        <!-- ══ THẺ RADAR XU HƯỚNG (prototype: thẻ «Radar xu hướng · tuần N») ══
             Chỉ hiện khi CÓ dữ liệu thật: tiêu đề lấy từ sổ nguồn ngoài mà agent đã đọc
             (/api/design-agent/findings). Không có dữ liệu ⇒ không có thẻ — thẻ radar rỗng là thẻ nói dối. -->
        <section v-if="radar" class="mt-7" data-home-radar>
          <a href="/agent-studio" class="block overflow-hidden rounded-3xl border border-ink-600 bg-gradient-to-br from-brand-600/25 via-ink-800 to-clay-500/20 p-5 transition hover:border-brand-400 active:scale-[0.99]">
            <span class="inline-flex items-center gap-1.5 text-micro font-semibold uppercase tracking-[0.16em] text-brand-200">
              <StudioIcon name="radar" size="h-3.5 w-3.5" /> Radar xu hướng
            </span>
            <p class="mt-2 line-clamp-3 font-display text-lg font-semibold leading-snug text-cream-50">{{ radar.title }}</p>
            <p v-if="radar.source_name" class="mt-1 text-micro text-cream-400">Nguồn: {{ radar.source_name }}</p>
            <span class="mt-3 inline-flex items-center gap-1.5 text-label font-semibold text-brand-200">
              <StudioIcon name="bot" size="h-4 w-4" /> Nhờ Agent phân tích
            </span>
          </a>
        </section>

        <!-- Lối còn lại, gọn MỘT hàng chữ — bản đầy đủ nằm ở menu Không gian trên thanh lệnh. -->
        <nav class="mt-7 flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-ink-700 pt-4 text-label" aria-label="Lối khác">
          <a href="/agent-studio" class="font-semibold text-cream-300 transition hover:text-brand-200">Design Agent</a>
          <a href="/bo-suu-tap" class="font-semibold text-cream-300 transition hover:text-brand-200">Bộ sưu tập</a>
          <a href="/studio?view=library" class="font-semibold text-cream-300 transition hover:text-brand-200">Thư viện</a>
          <a href="/cai-dat" class="font-semibold text-cream-300 transition hover:text-brand-200">Cài đặt</a>
        </nav>
      </main>
    </template>

    <CommandBar v-if="user" mode="prompt" space="home" placeholder="Mô tả thiết kế bạn mơ…" @go="goPrompt" />
    <PopMenu />

    <!-- ══ HOẠT ĐỘNG GẦN ĐÂY (chuông) — sheet theo §15.7 (một cấp, kéo xuống đóng) ══ -->
    <BottomSheet :open="activityOpen" title="Hoạt động gần đây" @update:open="activityOpen = false">
      <div class="pb-2" data-home-activity>
        <p v-if="activityLoading" class="py-6 text-center text-body text-cream-400">Đang tải…</p>
        <ul v-else-if="activity.length" class="space-y-1.5">
          <li v-for="g in activity" :key="g.id" class="flex items-center gap-3 rounded-xl border border-ink-600 bg-ink-800 px-3 py-2.5">
            <img v-if="g.media_url" :src="thumbUrl(g.media_url, 160)" :alt="g.prompt || 'Thiết kế'" class="h-10 w-10 shrink-0 rounded-lg object-cover" loading="lazy" @error="onThumbError($event, g.media_url)">
            <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-ink-900 text-cream-400"><StudioIcon name="image" size="h-4 w-4" /></span>
            <span class="min-w-0 flex-1">
              <b class="block truncate text-label font-semibold text-cream-100">{{ g.prompt || ('Ảnh #' + g.id) }}</b>
              <i class="block text-micro not-italic text-cream-400">{{ statusLabel(g.status) }} · {{ fmtTime(g.created_at) }}<template v-if="g.project"> · {{ g.project }}</template></i>
            </span>
            <a href="/studio" class="shrink-0 rounded-full border border-ink-600 px-3 py-1.5 text-micro font-semibold text-cream-200">Mở</a>
          </li>
        </ul>
        <p v-else class="py-6 text-center text-body text-cream-400">Chưa có hoạt động nào — nhập prompt ở thanh dưới để bắt đầu.</p>
      </div>
    </BottomSheet>
  </div>
</template>
