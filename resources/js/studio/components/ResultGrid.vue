<script setup>
/**
 * LƯỚI KẾT QUẢ — MẶT CHÍNH của Studio (bước 5.2 của kế hoạch bỏ canvas, 2026-09-26).
 *
 * VÌ SAO CÓ COMPONENT NÀY: canvas đang là TRUNG TÂM màn hình, còn kết quả nằm trong một dock hẹp
 * 156px bên phải. Đo được: canvas chiếm ~70% bề ngang trong khi việc người dùng thật sự làm là
 * XEM KẾT QUẢ và CHỌN BƯỚC TIẾP. Đổi vai: lưới kết quả thành mặt chính, canvas xuống hàng công cụ.
 *
 * TÁI DÙNG, KHÔNG VIẾT LẠI: mọi hành động ở đây đi qua ĐÚNG những hàm đã có
 * (store.select · store.openViewer · store.deleteGen · /api/generations/{id}/download). Không thêm
 * endpoint, không đổi luồng generate — chỉ đổi chỗ đứng của cùng một bộ nút.
 *
 * [§12 nguyên tắc 5 — và đây là chỗ KHÁC OutputModule có chủ ý] Thanh hành động ở lưới chính
 * LUÔN HIỆN, không ẩn theo hover. Lý do: ẩn theo hover là bẫy trên thiết bị cảm ứng (không có
 * hover), và đây là mặt chính nên người dùng mới phải thấy NGAY là có bước tiếp theo. Dock hẹp giữ
 * kiểu hover vì ở đó không gian là ràng buộc thật.
 *
 * [2026-09-26 · đợt 52 — MOBILE-FIRST] Card chỉ còn ĐÚNG HAI nút nhanh: **Sửa** · **Tải**.
 * Trước đây có bốn (Chọn · Sửa · Biến thể · Tải) — bốn nút chữ nhỏ trong một card rộng ~150px trên
 * điện thoại là bốn ô chạm nhau, không ô nào đủ to để chạm chắc. Hai nút còn lại là hai việc
 * NGƯỜI DÙNG THẬT SỰ làm ngay tại lưới; mọi việc khác (biến thể · upscale · mặc thử · kịch bản quay
 * · gắn bộ sưu tập · prompt…) đã chuyển vào TRÌNH XEM ẢNH — chạm vào ảnh là tới đó. Một chỗ để
 * xem, một chỗ để làm tiếp.
 *
 * CHIỀU CAO NÚT: 44px trên cảm ứng (h-11), 32px khi có chuột (lg:h-8). 44px là sàn chạm của Apple
 * HIG; luật nâng sàn chạm trong app.css chỉ áp cho phần tử dưới 40px nên ở đây phải khai thẳng.
 */
import { computed, ref, onMounted, watch } from 'vue';
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';
import { PROJECT_COLOR } from '../dataColors.js';

const store = useStudioStore();

/**
 * LỌC THEO TRẠNG THÁI — [đợt 53] tính năng còn thiếu của mặt lưới.
 *
 * VÌ SAO CẦN: một lượt tạo 8-12 ảnh nằm lẫn trong lưới; ảnh ĐANG CHẠY là thứ duy nhất người dùng
 * cần theo dõi, mà trước đây không có cách nào tách nó ra — phải tự dò từng ô xem ô nào còn quay.
 * Chip «N đang tạo» trên đầu lưới nói CÓ bao nhiêu nhưng không chỉ RA ô nào.
 *
 * LỌC Ở LOCAL, KHÔNG ĐỤNG KHO DỮ LIỆU: đây là chuyện XEM, không phải chuyện dữ liệu. Lọc trong
 * store sẽ đổi visibleGenerations — thứ mà 6 nơi khác đang đọc (OutputModule, biến thể, chọn ảnh…)
 * và biến một bộ lọc màn hình thành trạng thái toàn cục. Giữ nó ở đúng chỗ nó có nghĩa.
 */
const filter = ref('all');
// Tìm theo TÊN hoặc PROMPT — hai thứ người dùng nhớ về một tấm ảnh. Không phân biệt hoa/thường và
// bỏ dấu tiếng Việt: gõ "ao thun" phải ra "Áo thun".
const q = ref('');
const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');
const all = computed(() => store.visibleGenerations || []);

/**
 * SẮP XẾP — tính từ danh sách ĐẦY ĐỦ, trước khi lọc. Sắp xếp sau khi lọc cũng ra cùng thứ tự, nhưng
 * đặt ở đây thì mọi thứ đọc danh sách đầy đủ đều nhất quán, và thứ tự không đổi khi đổi bộ lọc.
 */
const sorted = computed(() => {
  const list = [...all.value];
  const t = (g) => new Date(g.created_at_raw || g.created_at || 0).getTime() || 0;
  const n = (g) => norm(store.genName(g));
  const running = (g) => (['pending', 'processing'].includes(g.status) ? 0 : 1);
  switch (store.outputSortBy) {
    case 'old': return list.sort((a, b) => t(a) - t(b));
    case 'name': return list.sort((a, b) => n(a).localeCompare(n(b), 'vi'));
    case 'running': return list.sort((a, b) => running(a) - running(b) || t(b) - t(a));
    // 'new' khai TƯỜNG MINH chứ không để rơi vào default: đây là mặc định của người dùng, và một
    // giá trị lạ trong localStorage phải rơi vào default chứ không lặng lẽ thành "mới nhất".
    case 'new': return list.sort((a, b) => t(b) - t(a));
    default: return list.sort((a, b) => t(b) - t(a));
  }
});
const FILTERS = [
  { id: 'all', label: 'Tất cả', test: () => true },
  { id: 'running', label: 'Đang chạy', test: (g) => ['pending', 'processing'].includes(g.status) },
  { id: 'done', label: 'Hoàn tất', test: (g) => g.status === 'completed' },
  { id: 'failed', label: 'Lỗi', test: (g) => ['failed', 'cancelled'].includes(g.status) },
];
// Số trên mỗi chip tính từ danh sách ĐẦY ĐỦ: bộ lọc không được làm con số tự nói dối về chính nó.
const searched = computed(() => {
  const needle = norm(q.value).trim();
  if (!needle) return sorted.value;
  return sorted.value.filter((g) => norm(store.genName(g)).includes(needle) || norm(g.prompt).includes(needle));
});
const filterCounts = computed(() => {
  const out = {};
  for (const f of FILTERS) out[f.id] = searched.value.filter(f.test).length;
  return out;
});
const items = computed(() => {
  const f = FILTERS.find((x) => x.id === filter.value) || FILTERS[0];
  return searched.value.filter(f.test);
});
// Chip «đang tạo» là sự thật của lưới ĐANG XEM (đã tính bộ sưu tập + tìm kiếm), không của bộ lọc
// trạng thái — người dùng đang tìm trong bộ X thì con số phải nói về bộ X.
const pending = computed(() => filterCounts.value.running);
// Lọc ra rỗng KHÁC HẲN chưa có ảnh nào: một bên là "đổi bộ lọc đi", một bên là "tạo ảnh đầu tiên".
const filteredEmpty = computed(() => !items.value.length && all.value.length > 0);

// ── PHẠM VI: TẤT CẢ ẢNH ⇄ MỘT BỘ SƯU TẬP ──
// Thay cho chip cũ chỉ hiện mỗi TÊN bộ sưu tập — nó không nói mình làm gì, và trên điện thoại thì
// thuộc tính title không bao giờ hiện vì không có hover.
const collections = computed(() => (store.projects || []).filter((p) => !p.archived));
const scope = computed(() => (store.outputFilterProject && store.appliedProjectId() ? 'p:' + store.appliedProjectId() : 'all'));
function setScope(v) {
  if (v === 'all') { store.outputFilterProject = false; store.saveOutputPrefs(); return; }
  const p = collections.value.find((x) => 'p:' + x.id === v);
  if (p) { store.applyProject(p); store.outputFilterProject = true; store.saveOutputPrefs(); }
}
/**
 * BỘ SƯU TẬP ĐANG ÁP DỤNG MÀ RỖNG ⇒ TỰ VỀ «TẤT CẢ ẢNH» VÀ NÓI RA.
 *
 * Lỗi người dùng gặp: đang áp một bộ sưu tập chưa có ảnh nào ⇒ lưới rỗng, và màn hình rơi vào khối
 * "Chưa có ảnh nào" (vốn là câu chuyện của canvas trống) ⇒ người dùng không hiểu vì sao ảnh của họ
 * biến mất. Ảnh KHÔNG mất — chỉ là đang bị giới hạn vào một bộ rỗng.
 * Nay: tự mở phạm vi ra VÀ nói rõ vừa xảy ra chuyện gì. Im lặng tự đổi phạm vi cũng là một kiểu nói
 * dối khác — người dùng phải biết vì sao màn hình vừa đổi.
 */
watch([() => store.outputFilterProject, () => store.appliedProjectId(), () => all.value.length, () => (store.generations || []).length], () => {
  const hasAny = (store.generations || []).length > 0;
  if (store.outputFilterProject && store.appliedProjectId() && all.value.length === 0 && hasAny) {
    const name = store.appliedProject?.name || 'đang áp dụng';
    setScope('all');
    store.toast('Bộ sưu tập «' + name + '» chưa có ảnh nào — đang xem tất cả ' + (store.generations || []).length + ' ảnh.');
  }
}, { immediate: true });

onMounted(() => {
  store.restoreOutputPrefs();
  // Bộ chọn PHẠM VI cần danh sách bộ sưu tập. Không nạp thì nó chỉ có một mục «Tất cả ảnh» — tức là
  // tính năng "xem theo bộ sưu tập" trông như không tồn tại. loadProjects() là hàm ĐỌC có sẵn và tự
  // bỏ qua khi đã nạp (cùng lối bảng lệnh đang dùng), nên mở lưới không sinh request thừa.
  if (!store.projectLoaded) store.loadProjects();
});
function setSort(v) { store.outputSortBy = v; store.saveOutputPrefs(); }
function setDensity(v) { store.outputDensity = v; store.saveOutputPrefs(); }

function projectColor(pid) {
  const p = store.projects.find((x) => Number(x.id) === Number(pid));
  return (p && p.color) || PROJECT_COLOR;
}
function projectName(pid, fallback) {
  if (fallback) return fallback;
  const p = store.projects.find((x) => Number(x.id) === Number(pid));
  return p ? p.name : '#' + pid;
}

/**
 * CỠ THUMBNAIL THEO TỪNG CHỖ — một bảng, không rải số khắp nơi.
 *
 * Lỗi cũ: mọi lưới gọi thumbUrl(url) KHÔNG kèm cỡ ⇒ luôn nhận thumbnail 160px, trong khi card ở
 * lưới chính rộng 150-320 CSS px; trên màn 2x thì cần 300-640 điểm ảnh thật ⇒ ảnh nhòe đúng ở chỗ
 * người dùng nhìn kỹ nhất (kết quả họ vừa tạo).
 *
 * Backend chỉ nhận 160|320|480|640 (whitelist cứng trong StudioController::studioImageThumb) nên
 * srcset chỉ được trỏ vào bốn cỡ đó. Thuộc tính sizes PHẢI khớp breakpoint của lưới bên dưới:
 *   grid-cols-2 (mặc định) · sm:grid-cols-3 (640) · lg:grid-cols-3 · xl:grid-cols-4 (1280) · 2xl:grid-cols-5 (1536)
 * Lệch sizes thì trình duyệt tải sai cỡ — nhòe (nếu nhỏ hơn) hoặc phí băng thông (nếu lớn hơn).
 */
const THUMB_SIZES = [160, 320, 480, 640];
function gridSrcset(url) {
  return THUMB_SIZES.map((s) => thumbUrl(url, s) + ' ' + s + 'w').join(', ');
}

/**
 * CỠ LƯỚI — ba mức, và SỐ CỘT phải đi CẶP với thuộc tính sizes của ảnh.
 *
 * Đây là chỗ dễ sai nhất khi thêm tính năng "chỉnh cỡ lưới": đổi số cột mà quên đổi sizes thì trình
 * duyệt vẫn tải thumbnail theo bề rộng CŨ — lưới nhỏ đi thì tải ảnh thừa (phí băng thông), lưới to
 * ra thì tải ảnh thiếu (nhòe). Vì vậy hai thứ nằm CHUNG một bảng, không tách rời.
 */
// [đợt 56] Bề rộng khai ở đây là bề rộng CỦA Ô ẢNH, và nó phải là mức TRẦN (không phải mức trung
// bình): máy ảnh thật của điện thoại là 3x điểm ảnh, nên ô 195px cần ~585 điểm ảnh thật. Khai thấp
// hơn thực tế là trình duyệt chọn cỡ nhỏ hơn và ảnh NHÒE — đúng lỗi người dùng báo.
const DENSITY = {
  s: { cols: 'grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8', sizes: '(min-width: 1536px) 14vw, (min-width: 1280px) 18vw, (min-width: 640px) 28vw, 36vw', label: 'Nhỏ' },
  m: { cols: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5', sizes: '(min-width: 1536px) 22vw, (min-width: 1280px) 28vw, (min-width: 640px) 36vw, 54vw', label: 'Vừa' },
  l: { cols: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4', sizes: '(min-width: 1536px) 28vw, (min-width: 1280px) 36vw, (min-width: 640px) 54vw, 100vw', label: 'Lớn' },
};
const density = computed(() => store.outputDensity || 'm');
const gridCols = computed(() => (DENSITY[density.value] || DENSITY.m).cols);
const GRID_SIZES = computed(() => (DENSITY[density.value] || DENSITY.m).sizes);

/**
 * MỞ MÀN «CHỈNH ẢNH» (bước 5.3) — MỘT ảnh, ba chế độ Tả/Khoanh/Cọ.
 *
 * VÌ SAO KHÔNG dùng useIn(g,'inpaint') như trước: đường cũ đưa ảnh lên canvas rồi bắt người dùng
 * khoanh vùng TRÊN CANVAS nhiều layer. Màn mới làm đúng một việc, trên đúng một ảnh — và chạy được
 * trên điện thoại.
 */
function editImage(g) { store.select(g); store.editImageOpen = true; }
function download(g) {
  if (!g || !g.id) return;
  window.location.href = '/api/generations/' + g.id + '/download';
}
/**
 * Xoá một mục khỏi danh sách — đi qua ĐÚNG action GalleryModal đang dùng (store.deleteGen),
 * nên hành vi xoá (xoá mềm ở máy chủ · xử lý layer cục bộ · hoàn credit nếu cần) không lệch nhau.
 */
function remove(g) { store.deleteGen(g); }

// Kéo-thả sang canvas (vẫn còn trong giai đoạn chuyển) — cùng định dạng dữ liệu với OutputModule.
function onDragStart(e, g) {
  e.dataTransfer.effectAllowed = 'copy';
  try { e.dataTransfer.setData('text/plain', JSON.stringify({ type: 'studio-output', url: g.media_url, name: store.genName(g) })); } catch (err) { /* bỏ qua */ }
}
</script>

<template>
  <div class="relative flex h-full min-h-0 flex-col overflow-hidden rounded-lg border border-ink-700 bg-ink-900">
    <!-- ══ THANH LƯỚI — ĐÚNG MỘT HÀNG, CUỘN NGANG (đợt 56) ══
         Lịch sử của chỗ này: 4 hàng → 3 hàng → 2 hàng → **1 hàng**. ĐO ĐƯỢC trên Chrome thật ở 390px:
         4 hàng chiếm 171px trên vùng lưới 699px = **24% màn hình** chỉ để hiện bộ lọc. Số HÀNG mới là
         thứ ăn chỗ; bề ngang thì ngón tay đã quen vuốt.
         Nay tất cả trong một dải: tiêu đề (chỉ từ lg, vì ở điện thoại mặt lưới vốn đã là "kết quả") ·
         tìm · đếm · việc đang chạy · chip trạng thái · ba lựa chọn · nút bỏ lọc.
         Dropdown dùng lớp .select CỦA daisyUI và nút dùng .btn của daisyUI: màu nền/viền/chữ lấy từ
         theme, không tự pha màu tay — bản trước tự pha nên trông lệch hẳn khỏi phần còn lại. -->
    <div class="flex shrink-0 items-center gap-1.5 overflow-x-auto border-b border-ink-700 px-2 py-1.5 scrollbar-hide" role="group" aria-label="Lọc và sắp xếp kết quả">
      <h2 class="hidden shrink-0 items-center gap-2 font-display text-sm font-semibold text-cream-50 lg:flex">
        <StudioIcon name="grid" size="h-4 w-4" class="text-brand-300" /> Kết quả
      </h2>

      <label class="flex min-w-28 flex-1 items-center lg:max-w-64">
        <input v-model="q" type="search" data-output-search placeholder="Tìm ảnh…"
               class="input input-sm w-full" aria-label="Tìm ảnh theo tên hoặc mô tả">
      </label>

      <span class="badge badge-sm shrink-0" data-output-count>{{ items.length }}</span>
      <!-- Nói rõ khi con số KHÔNG phải tổng: người dùng phải biết mình đang xem một phần. -->
      <span v-if="items.length !== (store.generations || []).length" class="shrink-0 text-label text-cream-400">
        / {{ (store.generations || []).length }}
      </span>

      <span v-if="pending" class="badge badge-warn badge-sm shrink-0 gap-1">
        <span class="h-2 w-2 animate-pulse rounded-full bg-current"></span>{{ pending }} đang tạo
      </span>

      <span v-if="all.length" class="mx-0.5 h-6 w-px shrink-0 bg-ink-700" aria-hidden="true"></span>
      <button
        v-for="f in FILTERS" :key="f.id"
        type="button"
        class="btn btn-sm shrink-0 gap-1.5"
        :class="filter === f.id ? 'btn-primary' : 'btn-ghost'"
        :data-output-filter="f.id"
        :aria-pressed="filter === f.id"
        @click="filter = f.id"
      >
        {{ f.label }}
        <span class="badge badge-xs" :class="filter === f.id ? 'badge-neutral' : 'badge-ghost'">{{ filterCounts[f.id] }}</span>
      </button>

      <span class="mx-0.5 h-6 w-px shrink-0 bg-ink-700" aria-hidden="true"></span>

      <select :value="scope" data-output-scope class="select select-sm w-auto max-w-44 shrink-0"
              aria-label="Xem ảnh theo bộ sưu tập" @change="setScope($event.target.value)">
        <option value="all">Tất cả ảnh</option>
        <option v-for="p in collections" :key="'sc-' + p.id" :value="'p:' + p.id">{{ p.name }}</option>
      </select>

      <select :value="store.outputSortBy" data-output-sort class="select select-sm w-auto shrink-0"
              aria-label="Sắp xếp ảnh" @change="setSort($event.target.value)">
        <option value="new">Mới nhất</option>
        <option value="old">Cũ nhất</option>
        <option value="name">Tên A→Z</option>
        <option value="running">Đang chạy trước</option>
      </select>

      <select :value="density" data-output-density class="select select-sm w-auto shrink-0"
              aria-label="Cỡ lưới" @change="setDensity($event.target.value)">
        <option value="s">Lưới nhỏ</option>
        <option value="m">Lưới vừa</option>
        <option value="l">Lưới lớn</option>
      </select>

      <button v-if="filter !== 'all' || q" type="button" class="btn btn-ghost btn-sm shrink-0 gap-1" data-output-filter-clear @click="filter = 'all'; q = ''">
        <StudioIcon name="x" size="h-3.5 w-3.5" /> Bỏ lọc
      </button>
    </div>

    <!-- Banner nói thật khi lô này có ảnh DEMO (không phải do AI tạo) -->
    <p v-if="items.some((g) => g.is_demo)" class="mx-3 mt-2 rounded-md border border-warn/40 bg-warn/10 px-2 py-1.5 text-label leading-snug text-warn">
      <span class="font-semibold">Ảnh DEMO:</span> tính năng tạo ảnh chưa được bật nên kết quả là ảnh mẫu (hoặc chính ảnh gốc), <span class="font-semibold">không phải do AI tạo</span>. Vui lòng báo cho quản trị viên để bật tính năng.
    </p>

    <!-- ── Lưới kết quả ── -->
    <!-- Số cột đến từ store.outputDensity (bảng DENSITY ở script) — ĐI CẶP với thuộc tính sizes của
         ảnh, không tách rời, nếu không trình duyệt tải thumbnail sai cỡ. -->
    <div v-if="items.length" class="scrollbar-hide grid flex-1 auto-rows-min gap-3 overflow-y-auto p-3" :class="gridCols">
      <article
        v-for="g in items"
        :key="g.id"
        class="group relative overflow-hidden rounded-xl border-2 bg-ink-800 transition-colors"
        :class="store.previewId === g.id ? 'border-brand-500' : 'border-ink-700 hover:border-ink-600'"
      >
        <div class="relative aspect-square">
          <span v-if="g.project_id" class="absolute left-1.5 top-1.5 z-10 h-2.5 w-2.5 rounded-full ring-1 ring-ink-600" :style="{ background: projectColor(g.project_id) }" :title="'Bộ sưu tập: ' + projectName(g.project_id, g.project)"></span>
          <span v-if="g.is_demo" class="absolute right-1.5 top-1.5 z-10 rounded-full bg-warn px-1.5 py-0.5 text-micro font-bold uppercase leading-none text-warn-content" :title="g.demo_reason || 'Ảnh mẫu — tính năng tạo ảnh AI chưa được bật'">ẢNH MẪU</span>

          <template v-if="g.status === 'completed' && g.media_url">
            <button
              type="button"
              class="absolute inset-0 cursor-zoom-in"
              draggable="true"
              :title="'Xem lớn ' + store.genName(g)"
              :aria-label="'Xem lớn ' + store.genName(g)"
              @click="store.openViewer(g)"
              @dragstart="onDragStart($event, g)"
            >
              <img :src="thumbUrl(g.media_url, 480)" :srcset="gridSrcset(g.media_url)" :sizes="GRID_SIZES" class="pointer-events-none h-full w-full bg-ink-900 object-cover" loading="lazy" decoding="async" :alt="store.genName(g)" @error="onThumbError($event, g.media_url)">
            </button>
          </template>

          <!-- Chờ / đang xử lý / lỗi -->
          <template v-else>
            <div class="skeleton-shimmer absolute inset-0"></div>
            <div class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-scrim/40 text-label text-scrim-content">
              <span v-if="['pending', 'processing'].includes(g.status)" class="h-5 w-5 animate-spin rounded-full border-2 border-brand-300 border-t-transparent"></span>
              <span v-else-if="g.status === 'failed'" class="text-base"><StudioIcon name="alertTriangle" size="h-5 w-5" /></span>
              <span v-else-if="g.status === 'cancelled'" class="text-base"><StudioIcon name="x" size="h-5 w-5" /></span>
              <span class="font-semibold">{{ store.statusLabel(g.status) }}</span>
            </div>
          </template>
        </div>

        <!-- ── HAI nút nhanh: Sửa · Tải. LUÔN HIỆN (xem chú thích đầu file). ── -->
        <template v-if="g.status === 'completed' && g.media_url">
          <div class="grid grid-cols-2 gap-1.5 border-t border-ink-700 p-1.5">
            <button type="button" class="flex h-11 items-center justify-center gap-1.5 rounded-lg bg-brand-600 text-label font-semibold text-primary-content transition hover:bg-brand-500 lg:h-8 lg:text-tiny" title="Mở màn Chỉnh ảnh: tả · khoanh vùng · vẽ cọ" :aria-label="'Sửa ' + store.genName(g)" data-edit-open @click.stop="editImage(g)">
              <StudioIcon name="pencil" size="h-3.5 w-3.5" /> Sửa
            </button>
            <button type="button" class="flex h-11 items-center justify-center gap-1.5 rounded-lg bg-ink-700 text-label font-semibold text-cream-200 transition hover:bg-ink-600 lg:h-8 lg:text-tiny" title="Tải ảnh gốc về máy" :aria-label="'Tải ' + store.genName(g)" @click.stop="download(g)">
              <StudioIcon name="download" size="h-3.5 w-3.5" /> Tải
            </button>
          </div>
          <p class="truncate px-2 pb-1.5 pt-1 text-label text-cream-400 lg:text-tiny" :title="store.genName(g)">{{ store.genName(g) }}</p>
        </template>
        <template v-else>
          <div class="flex items-center gap-1 border-t border-ink-700 p-1.5">
            <button type="button" class="tool-btn !py-1 text-tiny" title="Xoá mục này khỏi danh sách" :aria-label="'Xoá ' + store.genName(g)" @click.stop="remove(g)">
              <StudioIcon name="trash" size="h-3 w-3" />
            </button>
            <span v-if="g.error" class="min-w-0 flex-1 truncate text-tiny text-danger" :title="g.error">{{ g.error }}</span>
          </div>
        </template>
      </article>
    </div>

    <!-- ── Lọc ra rỗng: KHÁC HẲN chưa có ảnh nào — đừng mời "tạo ảnh đầu tiên" khi họ đã có ảnh ── -->
    <div v-else-if="filteredEmpty" class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
      <span class="grid h-12 w-12 place-items-center rounded-full bg-ink-800 text-cream-400">
        <StudioIcon name="filter" size="h-6 w-6" />
      </span>
      <p class="text-base font-semibold text-cream-100">Không có ảnh nào ở mục «{{ (FILTERS.find(f => f.id === filter) || {}).label }}»</p>
      <p class="max-w-md text-body text-cream-300">{{ all.length }} ảnh vẫn còn nguyên — chỉ là không ảnh nào khớp bộ lọc đang bật.</p>
      <div class="flex flex-wrap items-center justify-center gap-2">
        <button type="button" class="tool-btn" data-output-filter-clear @click="filter = 'all'; q = ''">
          <StudioIcon name="x" size="h-3.5 w-3.5" /> Xem tất cả {{ all.length }} ảnh
        </button>
        <button v-if="scope !== 'all'" type="button" class="tool-btn" data-output-scope-clear @click="setScope('all')">
          <StudioIcon name="folderOpen" size="h-3.5 w-3.5" /> Bỏ giới hạn bộ sưu tập
        </button>
      </div>
    </div>

    <!-- ── Trống: nói việc đầu tiên nên làm, KHÔNG liệt kê tính năng ── -->
    <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
      <span class="grid h-12 w-12 place-items-center rounded-full bg-brand-600/15 text-brand-300">
        <StudioIcon name="sparkles" size="h-6 w-6" />
      </span>
      <p class="text-base font-semibold text-cream-100">Chưa có ảnh nào</p>
      <p class="max-w-md text-body leading-relaxed text-cream-300">
        Mở bảng công cụ bên trái để tả tấm ảnh bạn muốn — hoặc hỏi trợ lý, rồi bấm Tạo ảnh.
      </p>
      <div class="flex flex-wrap items-center justify-center gap-2">
        <button type="button" class="btn-brand" data-chat-open title="Mở trợ lý thiết kế" @click="store.chatOpen = true">
          <StudioIcon name="bot" size="h-4 w-4" /> Mở trợ lý &amp; tạo ảnh
        </button>
        <button type="button" class="tool-btn" data-prompt-panel title="Mở bảng Prompt Tạo Ảnh đầy đủ" @click="store.promptOpen = true">
          <StudioIcon name="sliders" size="h-3.5 w-3.5" /> Bảng prompt đầy đủ
        </button>
      </div>
      <!-- Chưa có ảnh NÀO mà vẫn đang giới hạn theo bộ sưu tập là ngõ cụt thật: nói rõ và mở lối ra. -->
      <p v-if="store.appliedProject && store.outputFilterProject" class="text-label text-cream-400">
        Đang chỉ xem ảnh của bộ sưu tập «{{ store.appliedProject.name }}» —
        <button type="button" class="underline" data-output-scope-clear @click="setScope('all')">xem tất cả ảnh</button>
      </p>
    </div>
  </div>
</template>

<style scoped>
.skeleton-shimmer {
  background: linear-gradient(100deg, var(--color-ink-800) 20%, var(--color-ink-700) 40%, var(--color-ink-600) 60%, var(--color-ink-700) 80%, var(--color-ink-800) 100%);
  background-size: 200% 100%;
  animation: shimmerSweep 1.8s linear infinite;
}
@keyframes shimmerSweep {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
</style>
