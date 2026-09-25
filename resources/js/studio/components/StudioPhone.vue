<script setup>
/**
 * STUDIO PHONE — mặt Studio trên điện thoại (shell 2026 · đợt 59).
 *
 * ── LỊCH SỬ QUYẾT ĐỊNH (đọc trước khi sửa) ────────────────────────────────────────────────
 * [Phase 2 · 2026-09-24] Điện thoại (≤520px) NHẬN component này THAY toàn bộ cây desktop —
 *   `v-if`, không `v-show`: không một phần tử DOM canvas nào được tạo. Quyết định đó vẫn giữ.
 *   Nhưng bản Phase 2 mới có: ảnh đang làm việc · Tác vụ ảnh · rail kết quả · danh sách lớp đọc ·
 *   thanh lệnh. Hệ quả ĐO ĐƯỢC: 9 công cụ của xưởng (Tạo ảnh có tham số · Tạo biến thể · Mặc thử
 *   đồ · Sửa ảnh · Studio · Ghép trang phục · Upscale · Kịch bản quay · Bộ sưu tập), TRỢ LÝ, lưới
 *   kết quả có lọc/tìm/sắp xếp, Nguồn ảnh, Thư viện, Bộ sưu tập — tất cả KHÔNG còn lối vào nào
 *   trên điện thoại. Người dùng điện thoại mất phần lớn sản phẩm, không phải chỉ mất canvas.
 * [Đợt 59 · 2026-09-26] Bù lại ĐÚNG phần đã mất, theo thiết kế mới — KHÔNG dựng lại canvas:
 *   · "Công cụ" — sheet danh sách sinh từ CÙNG cấu hình owner quản lý (/admin → Giao diện) và
 *     CÙNG dữ liệu khoá-theo-gói mà thanh công cụ desktop dùng; chọn một công cụ ⇒ mở ĐÚNG card
 *     của nó trong màn chiếm trọn (PhoneSurface), y như tầng 2 của luồng cũ.
 *   · "Kết quả" — lưới kết quả THẬT (ResultGrid: lọc trạng thái · tìm không dấu · sắp xếp · cỡ
 *     lưới · phạm vi bộ sưu tập) trong màn chiếm trọn, thay vì chỉ rail 12 ảnh.
 *   · "Trợ lý" · "Bộ sưu tập" · "Nguồn ảnh" · "Thư viện & ảnh của tôi" — bốn lối vào vốn chỉ có ở
 *     dock của màn rộng.
 *   · Lớp/kéo giãn/khoanh vùng nhiều lớp VẪN là việc của màn rộng — giao diện nói THẬT (§15.10),
 *     nhưng "Sửa ảnh" (tả · khoanh · cọ) nay chạy được trên điện thoại qua màn Chỉnh ảnh.
 *
 * ── LUẬT CỦA NHÁNH NÀY ───────────────────────────────────────────────────────────────────
 *   · §7.2: `h-dvh` · chrome đáy `fixed` (CommandBar) · chừa chỗ bằng spacer `h-24` · thang tầng
 *     cố định (nội dung 0 · header 30 · thanh lệnh 60 · menu nổi 70 · sheet 80 · màn chiếm trọn 90).
 *   · §15.7: Review (chạm ảnh → trình xem) → Options (sheet Tác vụ ảnh / sheet Công cụ) → Action
 *     (cấp con có tham số + MỘT nút xác nhận). Nút back của máy lùi đúng từng cấp qua useNavStack.
 *   · §15.8: mọi <img> của ảnh người dùng đi qua `thumbUrl()` + `onThumbError`; không `src` trần.
 *   · §15.9: rung rất nhẹ và KHÔNG bao giờ là tín hiệu duy nhất (mọi cú rung đều kèm đổi trạng thái
 *     nhìn thấy được: ảnh mới trong rail · toast · nút đổi màu).
 *
 * Chọn ảnh KHÔNG qua store.select() (hàm đó đẩy layer canvas — vô nghĩa khi không có canvas);
 * chỉ đặt preview + workingImage (đúng tinh thần bước 5.1 trong store/actions/generation.js).
 */
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
import CommandBar from './CommandBar.vue';
import TriageDeck from './TriageDeck.vue';
import PhoneActions from './PhoneActions.vue';
import PhoneSurface from './PhoneSurface.vue';
import BottomSheet from './BottomSheet.vue';
import ResultGrid from './ResultGrid.vue';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import { haptic } from '../composables/useHaptics.js';
import { useNavStack } from '../composables/useNavStack.js';
import { useImageActions } from '../composables/useImageActions.js';
import { PHONE_NAV } from '../phoneNav.js';

// Thư viện nhúng: TRÙNG với đường của màn rộng (store.openLibrary() đổi studioView), nên nhánh điện
// thoại phải render nó — trước đây lệnh này mở một mặt chỉ tồn tại trong cây desktop ⇒ bấm «Thư viện»
// trên điện thoại ra màn TRẮNG.
const LibraryApp = defineAsyncComponent(() => import('../LibraryApp.vue'));

const store = useStudioStore();
const nav = useNavStack();
/* Tải xuống · Chia sẻ dùng CHUNG bản logic với sheet «Tác vụ ảnh» (composables/useImageActions.js) —
   hàng chip của màn Studio là LỐI TẮT tới đúng những hành động đó, không phải bản sao. */
const { download: doDownload, share: doShare } = useImageActions();

const props = defineProps({
  /** Thanh công cụ do OWNER quản lý — truyền từ StudioApp (MỘT nguồn, không khai lại). */
  activityNav: { type: Array, default: () => [] },
  /** Mục 'action' của thanh công cụ (Prompt Tạo Ảnh · Agent thiết kế). */
  toolbarActions: { type: Array, default: () => [] },
  /** Mục menu Cài đặt (nhãn/icon/ẩn-hiện do owner quản lý). */
  settingsEntry: { type: Object, default: null },
  /** Yêu cầu điều hướng từ nơi khác trong app ({ n, id } — store.requestActivity). */
  toolRequest: { type: Object, default: null },
});
const emit = defineEmits(['upgrade', 'open-prompt', 'open-agent', 'open-source', 'open-library', 'open-collections', 'open-assistant', 'logout']);

/* ── [Phase 3] Deck sàng lọc: tự mở khi một LƯỢT TẠO (≥2 ảnh) vừa hoàn tất; mở tay qua nút Duyệt. */
const triageOpen = ref(false);
/* [Phase 6] Tác vụ ảnh: sheet Options → Action (PhoneActions) thay lưới nút phẳng. */
const actionsOpen = ref(false);
/* Cấp mà sheet Tác vụ ảnh mở thẳng vào ('' = cấp Options). Hàng chip trên màn đặt giá trị này rồi mở. */
const actionsAt = ref('');

/* ── Ba cấp của chuỗi lớp phủ trên điện thoại, giữ trong MỘT ngăn xếp điều hướng ──
 * 'tools'   danh sách công cụ (sheet)
 * 'tool'    MỘT công cụ, chiếm trọn màn (PhoneSurface)
 * 'results' lưới kết quả, chiếm trọn màn (PhoneSurface)
 * Nhờ đi qua useNavStack, nút back của máy lùi ĐÚNG một cấp (§15.7 luật 5) thay vì văng khỏi trang.
 */
const surfaceTool = ref(null);
const top = computed(() => nav.state.stack[nav.state.stack.length - 1] || '');
const canBack = computed(() => nav.state.stack.length > 1);
const toolsOpen = computed(() => top.value === 'tools');
const toolOpen = computed(() => top.value === 'tool' && !!surfaceTool.value);
const resultsOpen = computed(() => top.value === 'results');
/**
 * TÀI KHOẢN — mục thứ tư của chuỗi, và là chỗ DUY NHẤT trên điện thoại có danh tính + đăng xuất.
 *
 * VÌ SAO CẦN: menu tài khoản của màn rộng (danh tính · Cài đặt & quản trị · Agent Studio · Đăng xuất)
 * neo vào nút avatar ở thanh tiêu đề — thanh đó không tồn tại trong nhánh điện thoại. Đo được: người
 * dùng điện thoại KHÔNG có cách nào đăng xuất trong app. Đây là bề mặt dùng CHUNG về quyền (mọi bề
 * rộng đều có), không phải đặc quyền của màn rộng — nên nó phải có mặt ở cả hai (§15.10).
 * Việc đăng xuất vẫn do StudioApp làm (một bản logic: fetch + XSRF + điều hướng) — ở đây chỉ PHÁT ý.
 */
const accountOpen = computed(() => top.value === 'account');

/* Nhịp DUY NHẤT đồng bộ ba cờ hiển thị với ngăn xếp: ngăn xếp là nguồn sự thật, cờ chỉ là hình chiếu.
 * Nhờ vậy cả ba đường (nút ←, ✕, back của máy) đều đi qua một chỗ — không có trạng thái thứ hai. */
watch(top, (t) => { if (t !== 'tool') surfaceTool.value = null; });

function openTools() { haptic(8); nav.open('tools'); }
/** Mở sheet «Tác vụ ảnh» NGAY ở một cấp Action (dùng cho hàng chip: Upscale 4K · Tạo biến thể AI). */
function openActions(at = '') { actionsAt.value = at; actionsOpen.value = true; }
function openAccount() { haptic(8); nav.push('account'); }
function openResults() { haptic(8); nav.open('results'); }
/**
 * MỞ CHẾ ĐỘ QUẢN LÝ ẢNH AI (thống kê · dọn rác · chọn hàng loạt) — cửa duy nhất, đặt trên màn Kết quả.
 * Đi qua ĐÚNG action `store.openLibrary()` (thoát công cụ canvas rồi đổi mặt) chứ không tự ghi hai cờ:
 * lối vào thứ hai mà quên một bước là hành xử khác lối thứ nhất.
 */
function openLibraryManage() {
  haptic(8);
  store.libraryTab = 'generations';
  store.libraryManage = true;
  store.openLibrary();
}
function closeAll() { nav.closeAll(); }
function backOne() { haptic(8); nav.pop(); }

/** Mở MỘT công cụ: khoá theo gói ⇒ mời nâng cấp; mục 'action' ⇒ việc của nơi khác; còn lại ⇒ màn riêng. */
function pickTool(item, nested = false) {
  if (!item) return;
  haptic(8);
  if (item.locked) { nav.closeAll(); emit('upgrade', item.id); return; }
  if (item.kind === 'action') {
    nav.closeAll();
    if (item.id === 'prompt') emit('open-prompt');
    else emit('open-agent', item.id);
    return;
  }
  if (!item.cards || !item.cards.length) return;
  surfaceTool.value = { id: item.id, label: item.label, icon: item.icon, cards: item.cards };
  if (nested) nav.push('tool'); else nav.open('tool');
}
/** Đổi công cụ khác: lùi về ĐÚNG cấp danh sách (không mở thêm cấp thừa). */
function switchTool() { haptic(8); if (canBack.value) nav.pop(); else nav.open('tools'); }

/**
 * YÊU CẦU ĐIỀU HƯỚNG từ nơi khác (thẻ trong Trợ lý · trình xem ảnh · lưới kết quả · `?panel=` từ Trang
 * chủ): mở thẳng công cụ đó.
 *
 * `immediate: true` là BẮT BUỘC, không phải cho gọn: StudioApp đặt yêu cầu trong `onMounted` của nó —
 * tức là TRƯỚC khi `booting` thành false và component này được mount. Thiếu `immediate`, yêu cầu đầu
 * tiên (đúng loại người dùng tạo ra bằng cách bấm một ô ở Trang chủ) rơi vào khoảng không: prop đã có
 * giá trị, watcher chưa từng chạy. Đo được: `/studio?panel=compose` mở ra màn Studio trống.
 * Đổi lại: xoay máy rồi xoay lại sẽ mở lại đúng công cụ đó — chấp nhận được (đó là nơi người dùng
 * vừa ở), và rẻ hơn nhiều so với việc mất hẳn yêu cầu điều hướng.
 */
watch(() => props.toolRequest && props.toolRequest.n, (n) => {
  if (!n) return;
  const id = props.toolRequest && props.toolRequest.id;
  // Đích KHÔNG phải công cụ: lưới kết quả (xem phoneNav.js). Thiếu nhánh này thì yêu cầu rơi xuống
  // nhánh "không tìm thấy công cụ" ⇒ mở bảng nâng cấp gói, sai hẳn ý người dùng.
  if (id === PHONE_NAV.RESULTS) { openResults(); return; }
  const item = props.activityNav.find((a) => a.id === id);
  if (item) pickTool(item);
  else { nav.closeAll(); emit('upgrade', id); }
}, { immediate: true });

const batchReady = computed(() => {
  const ids = store.lastBatch || [];
  if (ids.length < 2) return false;
  const byId = new Map((store.generations || []).map((g) => [g.id, g]));
  const done = ids.filter((id) => { const g = byId.get(id); return g && g.status === 'completed' && g.media_url; });
  return done.length >= 2;
});
watch(() => store.generating, (now, before) => { if (before && !now && batchReady.value) triageOpen.value = true; });

const currentUrl = computed(() => store.upscaleSrc || '');
const recents = computed(() => (store.visibleGenerations || []).filter((g) => g.media_url && g.status === 'completed').slice(0, 12));
// [Đợt 64] Đã gỡ `layers` (danh sách lớp) — khối «Lớp» không còn trên điện thoại.
const activeId = computed(() => (store.preview && store.preview.id) ?? null);

function pickGen(g) {
  store.previewId = g.id;
  store.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed', prompt: g.prompt || '' };
  store.setWorkingImage(g, 'generation');
}
function openCurrent() {
  if (store.preview) store.openViewer(store.preview);
}
/**
// [Đợt 64] Đã gỡ `pickLayer()` cùng danh sách lớp: ảnh đang làm việc nay chỉ đổi từ dải
// «Kết quả gần đây» (pickGen) — một đường, và đúng bản chất state (không phải "layer đang chọn").
function goPrompt(q) {
  // Ô prompt của thanh lệnh là ĐƯỜNG CHÍNH để tạo ảnh trên điện thoại (đợt 59: bảng "Prompt Tạo Ảnh"
  // đầy đủ vẫn mở được từ sheet Công cụ cho ai cần tham số — hai đường, một trường prompt).
  if (!q) return;
  store.imagePromptEn = q;
  store.generateImage();
}
/**
 * NHÃN NGỮ CẢNH của màn Studio — «BỘ SƯU TẬP · ẢNH», dựng từ dữ liệu THẬT của phiên.
 * Prototype ghi «THU ĐÔNG 26 · LOOK 04»; ở đây lấy tên bộ sưu tập đang áp + tên ảnh đang làm việc,
 * thiếu vế nào thì bỏ vế đó (không bịa). Không có gì thì nói «Studio».
 */
const contextLabel = computed(() => {
  const parts = [];
  if (store.appliedProject && store.appliedProject.name) parts.push(String(store.appliedProject.name));
  if (store.workingImage && store.workingImage.name) parts.push(String(store.workingImage.name));
  return parts.length ? parts.join(' · ') : 'Studio';
});

/**
 * SIÊU DỮ LIỆU ẢNH ĐANG XEM — tỉ lệ + kích thước thật, đọc từ CHÍNH tấm ảnh khi nó tải xong.
 *
 * VÌ SAO ĐỌC TỪ ẢNH chứ không lấy từ CSDL: cột dữ liệu không lưu kích thước cho mọi đường (ảnh cũ,
 * ảnh nguồn tải lên, ảnh do dịch vụ ngoài trả về). Đọc naturalWidth/Height là con số THẬT của tấm ảnh
 * đang hiện — prototype ghi cứng «3 : 4 · 2048px», ta không hứa một con số mình không có.
 */
const imgMeta = ref('');
function onPreviewLoad(e) {
  const el = e && e.target;
  if (!el || !el.naturalWidth || !el.naturalHeight) { imgMeta.value = ''; return; }
  const w = el.naturalWidth, h = el.naturalHeight;
  const gcd = (a, b) => (b ? gcd(b, a % b) : a);
  const d = gcd(w, h) || 1;
  const rw = Math.round(w / d), rh = Math.round(h / d);
  // Tỉ lệ chỉ có nghĩa khi gọn (3:4 · 4:5 · 1:1 · 9:16…); tỉ lệ lẻ thì in dạng thập phân.
  const ratio = (rw <= 30 && rh <= 30) ? (rw + ':' + rh) : (Math.round((w / h) * 100) / 100) + ':1';
  imgMeta.value = ratio + ' · ' + w + '×' + h;
}

/** Giá credit của một lần tạo ảnh — hiện TRƯỚC khi bấm (luật 10 §0 · nguyên tắc 3 của prototype). */
const imageCost = computed(() => Number(store.planCostImage) || 0);

/**
 * Sửa ảnh đang làm việc — màn Chỉnh ảnh (tả · khoanh vùng · cọ), chạy được bằng ngón tay.
 *
 * Nguồn ảnh là `store.workingImage`. Có nhánh dự phòng từ `store.preview`: hai thứ này luôn được đặt
 * cùng nhau (pickGen · ResultGrid · Trợ lý), nhưng một lối vào quên đặt `workingImage` sẽ làm nút này
 * IM LẶNG không mở gì — đúng loại lỗi mà đợt này đang đi truy.
 */
function editCurrent() {
  if (!store.workingImage && store.preview && store.preview.media_url) store.setWorkingImage(store.preview, 'generation');
  if (!store.workingImage) return;
  haptic(12);
  // [Đợt 64] Mở CÔNG CỤ «Sửa ảnh» (chứa bề mặt chỉnh ảnh), không mở màn «Chỉnh ảnh» riêng nữa —
  // hai màn cùng một việc đã nhập làm một.
  const item = props.activityNav.find((a) => a.id === 'inpaint');
  if (item) pickTool(item);
}
</script>

<template>
  <div class="studio-shell flex h-dvh w-full flex-col overflow-hidden bg-ink-950 text-cream-100">
    <!-- Thanh mini: nhận diện + credit (điều hướng không gian nằm ở orb của CommandBar) -->
    <header class="flex items-center gap-2 px-3 pt-3">
      <!-- ══ ← VỀ TRANG CHỦ («Tạo») ══════════════════════════════════════════════════════════
           Yêu cầu trực tiếp của chủ dự án, và cũng là hàng đầu của prototype
           (prototype/js/screens/studio.js · renderPhone: nút ← gọi go('#/home')).
           VÌ SAO PHẢI LÀ NÚT THẬT Ở ĐÂY: trước đợt này, đường về Trang chủ trên điện thoại chỉ có
           MỘT: chạm orb trên thanh lệnh rồi chọn «Tạo» trong menu không gian — hai cú chạm cho việc
           quay lại nơi mình vừa rời, và orb là chỗ để ĐỔI KHÔNG GIAN, không phải nút «về».
           Nút này là <a href="/"> (không phải router) nên vẫn đúng khi mở ở tab mới / giữ liên kết. -->
      <a
        href="/"
        class="icon-btn !h-10 !w-10 shrink-0"
        aria-label="Về Trang chủ — Tạo"
        title="Về Trang chủ · Tạo"
        data-phone-home
      >
        <StudioIcon name="home" size="h-5 w-5" />
      </a>
      <!-- Nhãn ngữ cảnh: «BỘ SƯU TẬP · ẢNH» như prototype («THU ĐÔNG 26 · LOOK 04»), dựng từ dữ liệu
           THẬT đang có trong phiên (bộ sưu tập đang áp + ảnh đang làm việc). Không có gì thì nói
           «Studio» — không bịa tên. -->
      <span class="micro-label min-w-0 flex-1 truncate text-center text-cream-400" data-phone-context>{{ contextLabel }}</span>
      <div class="flex shrink-0 items-center gap-2">
        <button
          v-if="batchReady"
          type="button"
          class="inline-flex h-8 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-3 text-label font-semibold text-cream-200 transition hover:border-brand-400"
          title="Sàng lọc lượt tạo gần nhất (vuốt giữ/bỏ)"
          @click="triageOpen = true"
        >
          <StudioIcon name="checkSquare" size="h-3.5 w-3.5" class="text-brand-300" />
          Duyệt
        </button>
        <a href="/bang-gia" class="inline-flex h-8 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-3 text-label font-semibold text-cream-200 transition hover:border-brand-400" title="Credit & gói">
          <StudioIcon name="zap" size="h-3.5 w-3.5" class="text-brand-300" />
          {{ Number(store.creditsLeft ?? 0).toLocaleString('vi') }}
        </a>
      </div>
    </header>

    <!-- Thư viện: khi đang mở, nó THAY vùng nội dung (và thanh lệnh ẩn đi) — đúng như màn rộng. -->
    <LibraryApp v-if="store.studioView === 'library'" embedded @back="store.studioView = 'studio'" />

    <main v-else class="min-h-0 flex-1 overflow-y-auto px-4 pb-3 pt-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      <!-- Preview ảnh đang làm việc (cấp REVIEW: chạm → trình xem toàn màn hình) -->
      <button
        v-if="currentUrl"
        type="button"
        class="relative block w-full overflow-hidden rounded-3xl border border-ink-600 bg-ink-900 transition hover:border-brand-400"
        aria-label="Xem lớn ảnh đang chọn"
        @click="openCurrent"
      >
        <img :src="currentUrl" alt="Ảnh đang làm việc" class="max-h-[44dvh] w-full object-contain" @load="onPreviewLoad">
        <!-- Hai nhãn của prototype: «Xem lớn» (gợi ý chạm được) và tag tỉ lệ · kích thước (số THẬT,
             đọc từ chính tấm ảnh — xem onPreviewLoad). Đặt trong nút nên chúng không giành cú chạm. -->
        <span class="pointer-events-none absolute bottom-2.5 left-2.5 inline-flex items-center gap-1.5 rounded-full bg-ink-950/60 px-3 py-1.5 text-micro font-semibold text-cream-100 backdrop-blur-sm">
          <StudioIcon name="eye" size="h-3.5 w-3.5" /> Xem lớn
        </span>
        <span v-if="imgMeta" class="pointer-events-none absolute bottom-2.5 right-2.5 rounded-lg bg-ink-950/60 px-2.5 py-1.5 text-micro font-semibold tabular-nums text-cream-200 backdrop-blur-sm" data-phone-img-meta>{{ imgMeta }}</span>
        <span v-if="store.generating" class="absolute inset-0 grid place-items-center bg-ink-950/60 backdrop-blur-sm" role="status">
          <span class="flex items-center gap-2 text-label font-semibold text-cream-100">
            <StudioIcon name="sparkles" size="h-4 w-4" class="animate-pulse text-brand-300" />
            AI đang tạo…
          </span>
        </span>
      </button>
      <div v-else class="grid w-full place-items-center rounded-3xl border border-dashed border-ink-600 bg-ink-900 px-6 py-8">
        <div class="flex flex-col items-center gap-3 text-center">
          <StudioIcon name="sparkles" size="h-7 w-7" class="text-brand-300" />
          <p class="text-body text-cream-300">Chưa có ảnh nào — mô tả thiết kế ở thanh dưới để tạo ảnh đầu tiên.</p>
          <button
            type="button"
            class="inline-flex h-10 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-4 text-label font-semibold text-cream-200 transition hover:border-brand-400"
            @click="emit('open-library')"
          >
            <StudioIcon name="imagePlus" size="h-4 w-4" class="text-brand-300" /> Chọn ảnh có sẵn
          </button>
        </div>
      </div>

      <!-- ══ HÀNH ĐỘNG CHÍNH — có GIÁ CREDIT ngay trên nút (prototype: «Tạo biến thể AI · 20 credit»;
           luật 10 §0: chi phí hiện TRƯỚC khi bấm). Bấm là mở THẲNG cấp Action «Tạo biến thể». ══ -->
      <button
        type="button"
        class="btn-magic mt-3 flex h-13 w-full items-center justify-center gap-2 rounded-2xl text-label font-bold transition active:scale-[0.98] disabled:opacity-45"
        :disabled="!currentUrl"
        data-phone-primary
        @click="openActions('variant')"
      >
        <StudioIcon name="sparkles" size="h-4 w-4" /> Tạo biến thể AI<span v-if="imageCost"> · {{ imageCost }} credit</span>
      </button>

      <!-- ══ SÁU LỐI TẮT (2×3): mỗi ô MỘT việc chạy được ngay, không ô nào trùng ô nào.
           [Đợt 64] Từ 4 lên 6 ô vì hai việc được ĐƯA LÊN ĐÂY khỏi nút «Việc khác» đã gỡ:
             · «Sửa ảnh» — việc chính với một tấm ảnh, mở công cụ chứa bề mặt chỉnh ảnh;
             · «Đổi khung» — cắt theo tỉ lệ, việc hay dùng khi chuẩn bị ảnh đăng bán.
           «Xoá» KHÔNG lên đây: nó nằm trong TRÌNH XEM (nơi người dùng đang nhìn kỹ tấm ảnh trước khi xoá).
           Ô nào chưa có ảnh thì KHOÁ (luật 5 §0: nút khoá phải nói được lý do — màn có sẵn trạng thái
           «Chưa có ảnh nào — mô tả thiết kế ở thanh dưới», và chính CTA cũng khoá như vậy). ══ -->
      <div class="mt-2 grid grid-cols-2 gap-2">
        <button type="button" class="flex h-12 items-center justify-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700 disabled:opacity-45" :disabled="!currentUrl" data-phone-quick="edit" @click="editCurrent()">
          <StudioIcon name="pencil" size="h-4 w-4" class="text-brand-300" /> Sửa ảnh
        </button>
        <button type="button" class="flex h-12 items-center justify-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700 disabled:opacity-45" :disabled="!currentUrl" data-phone-quick="upscale" @click="openActions('upscale')">
          <StudioIcon name="maximize" size="h-4 w-4" class="text-brand-300" /> Nâng cấp 4×
        </button>
        <button type="button" class="flex h-12 items-center justify-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700 disabled:opacity-45" :disabled="!currentUrl" data-phone-quick="reframe" @click="openActions('reframe')">
          <StudioIcon name="crop" size="h-4 w-4" class="text-brand-300" /> Đổi khung
        </button>
        <button type="button" class="flex h-12 items-center justify-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700 disabled:opacity-45" :disabled="!currentUrl" data-phone-quick="download" @click="doDownload()">
          <StudioIcon name="download" size="h-4 w-4" class="text-brand-300" /> Tải xuống
        </button>
        <button type="button" class="flex h-12 items-center justify-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700 disabled:opacity-45" :disabled="!currentUrl" data-phone-quick="share" @click="doShare()">
          <StudioIcon name="share" size="h-4 w-4" class="text-brand-300" /> Chia sẻ
        </button>
        <a href="/bo-suu-tap" class="flex h-12 items-center justify-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700" data-phone-quick="techpack">
          <StudioIcon name="ruler" size="h-4 w-4" class="text-brand-300" /> Tech pack
        </a>
      </div>

      <!-- ══ [Đợt 64] NÚT «VIỆC KHÁC» ĐÃ GỠ KHỎI MÀN CHÍNH ═══════════════════════════════════════════
           Ba việc nó chứa nay đã có chỗ ĐÚNG hơn, mỗi việc MỘT chỗ:
             · SỬA ẢNH  → thành lối tắt trên màn (chip «Sửa ảnh») vì đây là việc chính với một tấm ảnh,
                            và nó mở công cụ chứa bề mặt chỉnh ảnh (tả · khoanh · cọ);
             · ĐỔI KHUNG → chip riêng (cắt theo tỉ lệ, hay dùng cho ảnh đăng bán);
             · XOÁ        → đã nằm trong TRÌNH XEM («Việc khác» của trình xem), nơi người dùng đang nhìn
                            kỹ tấm ảnh trước khi quyết định xoá — không phải một nút trên màn chính.
           Nhờ vậy màn Studio còn ĐÚNG 6 lối tắt + 1 nút chính, tất cả đều có việc thật đằng sau.

      <!-- Bốn lối vào ngang cấp: công cụ · kết quả · trợ lý · bộ sưu tập.
           Đây là phần bù cho dock 4 đích của màn rộng, dựng theo §15.7 (mỗi mục là một CỬA, không
           phải một hành động rời rạc) và theo §5 (từ vựng viền ĐÓNG: nghỉ border-ink-600/bg-ink-800). -->
      <!-- Bốn lối vào ngang cấp, nay là MỘT dải chip (hàng 44px) thay vì bốn ô 64px: prototype không
           có ô vuông nào ở màn Studio, và chiều cao tiết kiệm được là chỗ cho chính tấm ảnh. -->
      <nav class="scrollbar-hide -mx-4 mt-5 flex gap-2 overflow-x-auto px-4" aria-label="Lối vào chính">
        <button type="button" class="flex h-11 shrink-0 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-4 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700" data-phone-gate="other" @click="openTools">
          <StudioIcon name="sliders" size="h-4 w-4" class="text-brand-300" /> Khác
        </button>
        <button type="button" class="flex h-11 shrink-0 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-4 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700" data-phone-gate="results" @click="openResults">
          <StudioIcon name="grid" size="h-4 w-4" class="text-brand-300" /> Kết quả
        </button>
        <button type="button" class="flex h-11 shrink-0 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-4 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700" data-phone-gate="assistant" @click="emit('open-assistant')">
          <StudioIcon name="bot" size="h-4 w-4" class="text-brand-300" /> Trợ lý
        </button>
        <button type="button" class="flex h-11 shrink-0 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-4 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700" data-phone-gate="collections" @click="emit('open-collections')">
          <StudioIcon name="folderOpen" size="h-4 w-4" class="text-brand-300" /> Bộ sưu tập
        </button>
      </nav>

      <!-- Dải công cụ: chạm MỘT lần là vào thẳng công cụ (không phải qua danh sách).
           Danh sách sinh từ cấu hình owner quản lý — thêm/bớt công cụ ở /admin là dải này đổi theo. -->
      <section v-if="activityNav.length" class="mt-5">
        <div class="flex items-baseline justify-between">
          <h2 class="text-label font-semibold text-cream-300">Công cụ</h2>
          <!-- [Đợt 64] Nhãn «Tất cả» đổi thành «Khác»: sheet nay KHÔNG liệt kê lại công cụ (chúng ở
               ngay dải này) mà chứa nhóm việc khác (nguồn ảnh · tệp & nguồn · bộ sưu tập · cài đặt…). -->
          <button type="button" class="text-micro font-semibold text-brand-300" data-phone-more-entries @click="openTools">Khác</button>
        </div>
        <div class="scrollbar-hide -mx-4 mt-2 flex gap-2 overflow-x-auto px-4 pb-1" data-phone-tool-rail>
          <button
            v-for="a in activityNav" :key="'rail-' + a.id"
            type="button"
            class="flex h-11 shrink-0 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-800 px-3.5 text-label font-semibold transition active:bg-ink-700"
            :class="a.locked ? 'text-cream-400 opacity-60' : 'text-cream-100'"
            :data-phone-tool="a.id"
            :title="a.locked ? a.label + ' — không có trong gói của bạn' : a.label"
            @click="pickTool(a)"
          >
            <StudioIcon :name="a.locked ? 'lock' : a.icon" size="h-4 w-4" class="text-brand-300" />
            {{ a.label }}
          </button>
        </div>
      </section>

      <!-- Rail kết quả gần đây (chạm để đổi ảnh đang làm việc) -->
      <section v-if="recents.length" class="mt-5">
        <div class="flex items-baseline justify-between">
          <h2 class="text-label font-semibold text-cream-300">Kết quả gần đây</h2>
          <button type="button" class="text-micro font-semibold text-brand-300" @click="openResults">Xem tất cả</button>
        </div>
        <div class="scrollbar-hide -mx-4 mt-2 flex snap-x gap-2.5 overflow-x-auto px-4 pb-1">
          <button
            v-for="g in recents" :key="g.id" type="button"
            class="block w-20 shrink-0 snap-start overflow-hidden rounded-xl border transition active:scale-95"
            :class="g.id === activeId ? 'border-brand-500' : 'border-ink-600'"
            :aria-label="'Chọn ảnh ' + store.genName(g)"
            @click="pickGen(g)"
          >
            <img :src="thumbUrl(g.media_url, 320)" :alt="store.genName(g)" class="aspect-[3/4] w-full object-cover" loading="lazy" @error="onThumbError($event, g.media_url)">
          </button>
        </div>
      </section>

      <!-- ══ [Đợt 64 · ĐÃ GỠ KHỐI «LỚP» KHỎI ĐIỆN THOẠI] ═══════════════════════════════════════════
           Yêu cầu trực tiếp: "bỏ các tính năng xếp lớp và scale trên điện thoại".

           VÌ SAO GỠ LÀ ĐÚNG (không phải cắt tính năng): khối này cho ẩn/hiện và đổi ĐỘ MỜ của từng
           lớp trong BẢNG GHÉP — mà bảng ghép KHÔNG tồn tại trên điện thoại (§15.6: không có DOM
           canvas). Hệ quả đo được: người dùng kéo thanh độ mờ trên điện thoại và **không thấy gì đổi**
           (không có composite nào để nhìn), còn ẩn/hiện một lớp thì chỉ đổi trạng thái mà màn hình
           không hề phản ánh. Đó là loại điều khiển tệ nhất: có, bấm được, và vô nghĩa tại chỗ.

           Ảnh đang làm việc vẫn đổi được — bằng đường ĐÚNG của nó: chạm một ảnh ở dải «Kết quả gần
           đây» (ảnh đang làm việc là state riêng, không phải "layer đang chọn").
           Xếp lớp · kéo giãn · độ mờ vẫn nguyên vẹn ở màn rộng (bảng Lớp trong Studio).
           Nút mắt và thanh độ mờ KHÔNG mất chức năng ở đâu cả — chúng chỉ không xuất hiện ở nơi không
           có gì để nhìn. -->

      <!-- Chừa chỗ cho thanh lệnh (§7.2 luật 3): spacer, KHÔNG dùng padding-bottom trên khung cuộn. -->
      <div class="h-24 shrink-0" aria-hidden="true"></div>
    </main>

    <CommandBar
      v-if="store.studioView !== 'library'"
      mode="prompt"
      space="studio"
      placeholder="Mô tả thiết kế / biến thể…"
      :running="store.generating"
      :model-value="store.imagePromptEn"
      @update:model-value="(v) => { store.imagePromptEn = v; }"
      @go="goPrompt"
    />
    <!-- PopMenu · GalleryModal · NotificationCenter · ChatModal KHÔNG mount ở đây: chúng là lớp phủ
         DÙNG CHUNG và đã được StudioApp mount MỘT LẦN cho cả hai nhánh. Mount thêm ở đây là hai bản
         sao cùng lúc (menu không gian hiện hai lần, trình xem mở hai lớp) — lỗi im lặng, chỉ thấy khi bấm. -->
    <TriageDeck v-model:open="triageOpen" />
    <PhoneActions v-model:open="actionsOpen" :start-at="actionsAt" @edit="editCurrent" />

    <!-- ══ CẤP OPTIONS · «KHÁC» — chỉ những thứ KHÔNG phải công cụ ══════════════════════════════════
         [Đợt 64 · dọn trùng lặp cuối cùng] LƯỚI 9 CÔNG CỤ ĐÃ GỠ khỏi sheet này.
         VÌ SAO: 9 công cụ đó đã có ĐÚNG một lối vào — **dải công cụ** ngay trên màn (một cú chạm, có
         icon + nhãn, sinh từ cùng cấu hình owner quản lý). Sheet này liệt kê lại y hệt 9 mục đó ⇒ hai
         lối vào cho cùng một việc, và người dùng phải đoán "dải kia và sheet này khác gì nhau".
         Sheet nay chỉ còn việc ĐỔI KHÔNG GIAN / NGUỒN DỮ LIỆU (Nguồn ảnh · Tệp & nguồn · Bộ sưu tập ·
         Cài đặt · Agent · Tài khoản) — nhóm việc mà dải công cụ KHÔNG có. Nhãn đổi từ «Công cụ» →
         «Khác» cho đúng thứ nó chứa. -->
    <BottomSheet :open="toolsOpen" title="Khác" @update:open="closeAll">
      <div class="space-y-1.5 pb-1">
        <button type="button" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-left text-body font-semibold text-cream-100" @click="closeAll(); emit('open-source')">
          <StudioIcon name="imagePlus" size="h-5 w-5" class="text-brand-300" /> Nguồn ảnh
        </button>
        <button type="button" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-left text-body font-semibold text-cream-100" @click="closeAll(); emit('open-library')">
          <!-- [Đợt 63] Nhãn nói ĐÚNG thứ còn ở đó: Thư viện nay là FILE tải lên + PROMPT đã lưu
               (tab «Ảnh đã tạo» đã gỡ — ảnh AI xem ở «Kết quả»). Tên cũ «Thư viện & ảnh của tôi» hứa
               cả ảnh AI, nên người dùng mở ra rồi đi tìm ảnh của mình ở một chỗ không có nữa. -->
          <StudioIcon name="folderOpen" size="h-5 w-5" class="text-brand-300" /> Tệp &amp; nguồn (file tải lên · prompt)
        </button>
        <button type="button" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-left text-body font-semibold text-cream-100" @click="closeAll(); emit('open-collections')">
          <StudioIcon name="folderOpen" size="h-5 w-5" class="text-brand-300" /> Bộ sưu tập &amp; dự án
        </button>
        <a v-if="settingsEntry" :href="settingsEntry.url || '/cai-dat'" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-body font-semibold text-cream-100">
          <StudioIcon :name="settingsEntry.icon || 'gear'" size="h-5 w-5" class="text-brand-300" /> {{ settingsEntry.label }}
        </a>
        <a href="/agent-studio" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-body font-semibold text-cream-100">
          <StudioIcon name="bot" size="h-5 w-5" class="text-brand-300" /> Agent thiết kế
        </a>
        <button type="button" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-left text-body font-semibold text-cream-100" data-phone-account @click="openAccount">
          <StudioIcon name="user" size="h-5 w-5" class="text-brand-300" /> Tài khoản &amp; đăng xuất
        </button>
      </div>
    </BottomSheet>

    <!-- ══ CẤP TÀI KHOẢN · danh tính · gói · cài đặt · đăng xuất ══════════════════════════
         Lồng trong chuỗi Công cụ (← lùi về danh sách) để nút back của máy vẫn lùi đúng từng cấp. -->
    <BottomSheet :open="accountOpen" title="Tài khoản" :back="canBack" @update:open="closeAll" @back="backOne">
      <div class="pb-2">
        <div class="mb-3 flex items-center gap-3 rounded-2xl border border-ink-600 bg-ink-800 px-3 py-2.5">
          <span class="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-ink-700 ring-1 ring-brand-500/60">
            <img v-if="store.user && store.user.avatar" :src="store.user.avatar" alt="" class="h-10 w-10 object-cover">
            <span v-else class="text-body font-bold text-cream-100">{{ ((store.user && store.user.name) || '?').charAt(0).toUpperCase() }}</span>
          </span>
          <span class="min-w-0 flex-1">
            <b class="block truncate text-body font-semibold text-cream-100">{{ (store.user && store.user.name) || 'Khách' }}</b>
            <i class="block truncate text-micro not-italic text-cream-400">{{ store.user ? ((store.user.role_label || store.user.role) + ' · ' + store.user.email) : 'Chưa đăng nhập' }}</i>
          </span>
          <a href="/bang-gia" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-ink-600 bg-ink-900 px-3 text-label font-semibold text-cream-200">
            <StudioIcon name="zap" size="h-3.5 w-3.5" class="text-brand-300" /> {{ Number(store.creditsLeft ?? 0).toLocaleString('vi') }}
          </a>
        </div>
        <div class="space-y-1.5">
          <a href="/bang-gia" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-body font-semibold text-cream-100">
            <StudioIcon name="coins" size="h-5 w-5" class="text-brand-300" /> Gói &amp; credit
          </a>
          <a href="/cai-dat" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-body font-semibold text-cream-100">
            <StudioIcon name="gear" size="h-5 w-5" class="text-brand-300" /> Cài đặt &amp; quản trị
          </a>
          <a href="/agent-studio" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-ink-600 bg-ink-800 px-3 text-body font-semibold text-cream-100">
            <StudioIcon name="sparkles" size="h-5 w-5" class="text-brand-300" /> Agent Studio
          </a>
          <button type="button" class="flex min-h-12 w-full items-center gap-2 rounded-xl border border-danger/40 bg-ink-800 px-3 text-left text-body font-semibold text-danger" data-phone-logout @click="closeAll(); emit('logout')">
            <StudioIcon name="logout" size="h-5 w-5" /> Đăng xuất
          </button>
        </div>
      </div>
    </BottomSheet>

    <!-- ══ CẤP ACTION · MỘT CÔNG CỤ, CHIẾM TRỌN MÀN ════════════════════════════════════════
         ĐÚNG card của công cụ đang chọn (cùng component với màn rộng) — không có bản sao thứ hai
         của công cụ, chỉ có một khung khác cho nó. -->
    <PhoneSurface
      :open="toolOpen"
      :title="surfaceTool ? surfaceTool.label : ''"
      :icon="surfaceTool ? surfaceTool.icon : 'square'"
      :back="canBack"
      switch-label="Đổi"
      @update:open="closeAll"
      @back="backOne"
      @switch="switchTool"
    >
      <div v-if="surfaceTool" class="space-y-2.5 p-2.5" data-phone-tool-panel>
        <component :is="c" v-for="(c, i) in surfaceTool.cards" :key="surfaceTool.id + '-' + i" />
      </div>
    </PhoneSurface>

    <!-- ══ LƯỚI KẾT QUẢ, CHIẾM TRỌN MÀN ════════════════════════════════════════════════════
         Lưới THẬT của xưởng (lọc trạng thái · tìm không dấu · sắp xếp · cỡ lưới · phạm vi bộ sưu
         tập · chọn nhiều). `scroll=false` vì ResultGrid tự có vùng cuộn của nó. -->
    <PhoneSurface
      :open="resultsOpen"
      title="Kết quả"
      icon="grid"
      :scroll="false"
      @update:open="closeAll"
    >
      <!-- Thanh lọc RIÊNG của lớp phủ này: trên màn rộng ba nút này nằm ở <header> của StudioApp
           (data-header-action) — mà header đó KHÔNG tồn tại trong nhánh điện thoại, nên nếu không có
           hàng này thì lưới kết quả trên điện thoại là lưới CHẾT: xem được mà không lọc, không tìm,
           không đổi cỡ được. Nút mở ĐÚNG bảng lọc dùng chung (store.outputSheet) — không bản sao. -->
      <div class="flex h-full flex-col p-2" data-phone-results>
        <div class="flex shrink-0 items-center gap-2 pb-2">
          <button
            type="button"
            class="inline-flex h-11 flex-1 items-center justify-center gap-1.5 rounded-xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700"
            title="Lọc theo trạng thái · phạm vi bộ sưu tập · sắp xếp · cỡ lưới"
            data-phone-results-filter
            @click="store.outputSheet = 'all'"
          >
            <StudioIcon name="filter" size="h-4 w-4" class="text-brand-300" /> Lọc &amp; sắp xếp
          </button>
          <button
            type="button"
            class="inline-flex h-11 flex-1 items-center justify-center gap-1.5 rounded-xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700"
            title="Tìm ảnh theo tên hoặc mô tả (không cần dấu)"
            data-phone-results-search
            @click="store.outputSheet = 'search'"
          >
            <StudioIcon name="search" size="h-4 w-4" class="text-brand-300" /> Tìm
          </button>
          <!-- [Đợt 63] QUẢN LÝ ẢNH — cửa DUY NHẤT vào chế độ thống kê/dọn rác/chọn hàng loạt.
               Trước đây cửa này nằm trong Thư viện, mà Thư viện lại có nguyên một tab «Ảnh đã tạo»:
               hai màn cùng liệt kê ảnh AI. Nay chỉ còn MỘT màn xem ảnh (lưới này) và việc quản lý mở
               TỪ ĐÂY — vẫn là cùng trang Thư viện nhưng mang cờ quản lý + dòng nhắc «đang ở chế độ...». -->
          <button
            type="button"
            class="inline-flex h-11 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-ink-600 bg-ink-800 px-3 text-label font-semibold text-cream-200 transition hover:border-brand-400 active:bg-ink-700"
            title="Quản lý ảnh AI: thống kê · dọn rác · gắn bộ sưu tập hàng loạt"
            data-phone-results-manage
            @click="openLibraryManage"
          >
            <StudioIcon name="kanban" size="h-4 w-4" class="text-brand-300" /> Quản lý
          </button>
        </div>
        <div class="min-h-0 flex-1">
          <ResultGrid />
        </div>
      </div>
    </PhoneSurface>
  </div>
</template>
