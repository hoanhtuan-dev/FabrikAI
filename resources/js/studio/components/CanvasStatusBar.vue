<script setup>
// CanvasStatusBar — thanh trạng thái dock dưới khung canvas (SPEC: STUDIO_UI_REDESIGN.md §4.2).
// Không props — đọc/ghi trực tiếp useStudioStore(). Mọi icon qua <StudioIcon/>.
import { computed } from 'vue';
import { useStudioStore } from '../store.js';
import { useTheme } from '../composables/useTheme.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

// Đổi nhanh Sáng ⇄ Tối ngay tại chỗ làm việc. Ba lựa chọn đầy đủ (gồm "Theo hệ điều hành")
// nằm ở Cài đặt của tôi → Giao diện; ở đây bấm là chốt hẳn một trong hai giao diện, nên
// title nói rõ điều đó để người dùng không tưởng nút này cũng xoay được chế độ "theo máy".
const { resolved: themeResolved, setTheme } = useTheme();
function toggleTheme() { setTheme(themeResolved.value === 'light' ? 'dark' : 'light'); }


// Gợi ý công cụ theo chế độ đang dùng (Krita-style) — hiện khi có công cụ active.
const toolHint = computed(() => {
  // Chế độ "chỉnh 1 layer" (bật bởi công cụ vẽ/xóa/vùng chọn) — nói ở ĐÂY thay vì dán nhãn lên canvas:
  // người dùng vẫn hiểu vì sao chỉ thấy một layer, mà không gian làm việc không bị thêm chữ.
  // [2026-09-26 · D1+D2] Đã bỏ gợi ý cho VẼ TỰ DO · XOÁ VÙNG · CẮT KHUNG: ba công cụ đó đã bị xoá
  // theo quyết định của chủ dự án (không cần crop · bỏ hẳn paint/erase).
  // Không có layer nào đang chọn (bấm ra vùng trống là bỏ chọn): nói rõ cách lấy lại tay cầm chỉnh kích cỡ.
  if (!store.activeLayer && store.visibleLayers.length) return 'Chưa chọn layer — bấm vào một layer để chỉnh kích cỡ · xoay';
  const m = store.inpaintMaskMode;
  if (m === 'path') {
    const n = store.inpaintPathPoints.length;
    if (n === 0) return 'Đường cong: bấm để đặt điểm neo · kéo để chỉnh tay điều khiển';
    if (n < 3) return `Đặt thêm điểm neo (${n}/3 tối thiểu) — quay lại điểm đầu (xanh) để đóng kín`;
    if (store.inpaintPathCloseHover) return 'Bấm điểm đầu để đóng kín vùng chọn · kéo sẽ di chuyển thay vì đóng';
    return 'Bấm điểm đầu (xanh) để đóng kín · bấm node vùng đã đóng để sửa lại · kéo neo/tay chỉnh · Ctrl+click node = đổi kiểu · Alt = phá đối xứng · click phải = xóa';
  }
  if (m === 'freehand') return 'Vẽ tự do quanh vùng cần chọn — thả chuột để tự đóng kín';
  if (m === 'rect') return 'Kéo để tạo vùng chữ nhật · kéo góc để chỉnh · kéo giữa để di chuyển';
  if (m === 'brush') return 'Vẽ nét lên vùng cần sửa · Tẩy = xóa nét · Ctrl+Z = hoàn tác';
  if (m === 'magic') return 'Bấm để chọn nhanh vùng màu — chỉnh Tolerance / Feather cho phù hợp';
  return '';
});

/*
 * [2026-09-26 · thiết kế lại] THANH TRẠNG THÁI CANVAS — chia theo TẦN SUẤT DÙNG.
 *
 * Trước đây MỘT hàng 36px nhồi 13 điều khiển (hoàn tác · zoom · bốn ô nền · bắt điểm + cỡ · nhãn trạng
 * thái · sáng/tối · lưu · panel). Trên điện thoại hàng đó bị bóp lại và mọi thứ đều nhỏ như nhau ⇒
 * không ai biết cái nào là việc chính.
 *
 * Nay theo mẫu Material: hành động THƯỜNG XUYÊN ở lại trên thanh (hoàn tác · làm lại · thu/phóng ·
 * % · vừa khung), phần CÒN LẠI vào menu "⋯" ở điện thoại (nền canvas · bắt điểm · giao diện · lưu ·
 * panel). Từ lg trở lên vẫn hiện đủ như cũ, chỉ cao hơn (36 → 40px) và thoáng hơn.
 * Menu "⋯" KHÔNG mở đường tắt nào mới: nó gọi đúng những hàm mà thanh đang gọi.
 */

// Nút icon của thanh này: 32px trên máy tính, và sàn chạm 40px của mobile (app.css) tự nâng khi màn hẹp.
const BTN = 'grid h-7 w-7 place-items-center rounded-lg text-cream-200 hover:bg-ink-700 disabled:opacity-30';

/*
 * [2026-09-26 · đợt 52] THANH NÀY LÀ CỦA CANVAS — Ở MẶT LƯỚI THÌ KHÔNG ĐƯỢC HIỆN.
 *
 * Lỗi cũ: thanh trạng thái nằm NGOÀI hai mặt (grid/canvas) nên ở mặt lưới nó vẫn phơi ra đủ thứ
 * chỉ có nghĩa với canvas: hoàn tác/làm lại thao tác LAYER, thu-phóng và % zoom của khung vẽ, bốn ô
 * NỀN CANVAS, bắt điểm, số LỚP. Trên điện thoại — nơi bề ngang là thứ đắt nhất — người dùng đang
 * xem lưới kết quả mà thấy toàn nút của một khung vẽ họ không nhìn thấy.
 *
 * Nay: mặt lưới giữ LẠI ĐÚNG nút đổi mặt (thứ duy nhất thuộc về cả hai mặt). Mọi nhóm còn lại chỉ
 * render khi đang ở mặt canvas. Không xoá nút nào — chỉ thôi phơi chúng ra sai chỗ.
 * Ngoại lệ có chủ ý: giao diện Sáng/Tối và Lưu trang là việc của CẢ ỨNG DỤNG, không của canvas,
 * nên vẫn hiện ở máy tính (nơi có chỗ); ở điện thoại chúng nằm trong menu Tài khoản.
 */
const isCanvas = computed(() => store.mainView === 'canvas');

// Nền canvas — MỘT NGUỒN với chính vùng canvas.
//
// Trước đây chỗ này tự vẽ màu bằng inline style, lệch hẳn với màu thật của canvas: ô "tối" một sắc,
// canvas ink-950 một sắc khác; ô "kem" một sắc, canvas cream-100 một sắc khác —
// và "lưới" thì canvas trỏ tới một class KHÔNG hề được định nghĩa nên nền trong suốt,
// trông như bấm nút không có tác dụng. Nay ô màu dùng ĐÚNG class .canvas-bg-* mà canvas đang dùng
// (app.css) ⇒ nhìn nút là biết canvas sẽ ra sao, không thể lệch.
const BG_OPTIONS = [
  { id: 'grid', label: 'Lưới trong suốt' },
  { id: 'dark', label: 'Tối' },
  { id: 'white', label: 'Trắng' },
  { id: 'cream', label: 'Kem' },
];
</script>

<template>
  <div class="relative z-30 flex min-h-10 shrink-0 items-center gap-1 border-t border-ink-700 bg-ink-900/95 px-2">
    <!-- 0. [Bước 5.2 — 2026-09-26] ĐỔI MẶT CHÍNH: Lưới kết quả ⇄ Bảng ghép.
         Đặt ở THANH TRẠNG THÁI chứ không ở rail công cụ: đây là đổi CHẾ ĐỘ XEM của cả khung,
         không phải một công cụ vẽ — và thanh trạng thái hiện ở MỌI bề rộng (rail chỉ có từ lg).
         Hai mặt SONG SONG trong giai đoạn chuyển; bước 5.4 xoá mặt 'canvas' thì bỏ luôn nhóm này. -->
    <div class="flex shrink-0 items-center gap-0.5 rounded-lg bg-ink-800 p-0.5" role="group" aria-label="Chọn mặt chính của khung làm việc">
      <button type="button" class="icon-btn !h-8 !w-8" :class="store.mainView === 'grid' ? 'bg-brand-600 text-primary-content' : ''"
              title="Lưới kết quả — xem và chọn bước tiếp (mặc định)" aria-label="Xem lưới kết quả" data-main-view-switch="grid"
              @click="store.setMainView('grid')">
        <StudioIcon name="grid" size="h-4 w-4" />
      </button>
      <button type="button" class="icon-btn !h-8 !w-8" :class="store.mainView === 'canvas' ? 'bg-brand-600 text-primary-content' : ''"
              title="Bảng ghép — khoanh vùng sửa, cắt khung, ghép layer" aria-label="Xem bảng ghép" data-main-view-switch="canvas"
              @click="store.setMainView('canvas')">
        <StudioIcon name="layers" size="h-4 w-4" />
      </button>
    </div>

    <!-- ══ TỪ ĐÂY TRỞ XUỐNG: CHỈ MẶT CANVAS (xem chú thích isCanvas ở script) ══ -->
    <template v-if="isCanvas">
    <div class="h-5 w-px bg-ink-700" aria-hidden="true"></div>

    <!-- 1. Hoàn tác / Làm lại -->
    <button @click="store.undo()" :disabled="!store.undoStack.length" class="icon-btn !h-8 !w-8" title="Hoàn tác (Ctrl+Z)" aria-label="Hoàn tác (Ctrl+Z)"><StudioIcon name="undo" size="h-4 w-4" /></button>
    <button @click="store.redo()" :disabled="!store.redoStack.length" class="icon-btn !h-8 !w-8" title="Làm lại (Ctrl+Y)" aria-label="Làm lại (Ctrl+Y)"><StudioIcon name="redo" size="h-4 w-4" /></button>

    <div class="h-5 w-px bg-ink-700" aria-hidden="true"></div>

    <!-- 2. Zoom — nhóm hay dùng nhất sau hoàn tác -->
    <button @click="store.zoomOut()" class="icon-btn !h-8 !w-8" title="Thu nhỏ" aria-label="Thu nhỏ"><StudioIcon name="zoomOut" size="h-4 w-4" /></button>
    <button @click="store.zoomFit()" class="min-w-14 rounded-lg px-1 py-1 text-center text-body font-semibold tabular-nums text-cream-100 transition-colors hover:bg-ink-700"
            title="Bấm để vừa khung hình">{{ Math.round(store.zoom * 100) }}%</button>
    <button @click="store.zoomIn()" class="icon-btn !h-8 !w-8" title="Phóng to" aria-label="Phóng to"><StudioIcon name="zoomIn" size="h-4 w-4" /></button>
    <button @click="store.zoomFit()" class="icon-btn !h-8 !w-8" title="Vừa khung hình" aria-label="Vừa khung hình"><StudioIcon name="maximize" size="h-4 w-4" /></button>

    <!-- 3. PHẦN CÒN LẠI — chỉ từ lg trở lên mới hiện thẳng trên thanh -->
    <div class="hidden items-center gap-1 lg:flex">
      <div class="h-5 w-px bg-ink-700" aria-hidden="true"></div>

      <!-- Nền canvas — ô màu dùng CHÍNH class nền của canvas -->
      <button
        v-for="b in BG_OPTIONS"
        :key="b.id"
        @click="store.canvasBg = b.id"
        class="motion-ui h-6 w-6 rounded-full border border-ink-600"
        :class="['canvas-bg-' + b.id, store.canvasBg === b.id ? 'ring-2 ring-brand-400' : '']"
        :title="'Nền canvas: ' + b.label"
        :aria-label="'Nền canvas: ' + b.label"
        :aria-pressed="store.canvasBg === b.id"
      ></button>

      <!-- Snap (bắt điểm) — mặc định BẬT 8px -->
      <div class="h-5 w-px bg-ink-700" aria-hidden="true"></div>
      <button @click="store.snapGrid = store.snapGrid ? 0 : 8" class="icon-btn !h-8 !w-8" :class="store.snapGrid ? '!text-brand-200' : ''"
              title="Bật/tắt bắt điểm (snap)" aria-label="Bật/tắt bắt điểm (snap)"><StudioIcon name="grid" size="h-4 w-4" /></button>
      <select v-if="store.snapGrid" :value="store.snapGrid" @change="store.snapGrid = Number($event.target.value)"
              class="h-8 rounded-lg border border-ink-600 bg-ink-800 px-1 text-body text-cream-100" title="Khoảng bắt điểm (px)" aria-label="Khoảng bắt điểm">
        <option :value="8">8</option><option :value="16">16</option><option :value="24">24</option><option :value="32">32</option>
      </select>
    </div>

    </template><!-- /nhóm chỉ-canvas -->

    <!-- 4. Chỗ trống + gợi ý công cụ (Krita-style) -->
    <div class="flex-1"></div>
    <div v-if="isCanvas && toolHint" class="flex min-w-0 items-center gap-1.5 overflow-hidden px-1 text-label text-cream-400" title="Hướng dẫn công cụ">
      <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
      <span class="truncate">{{ toolHint }}</span>
    </div>

    <!-- 5. Trạng thái lớp (md+) -->
    <div v-if="isCanvas" class="hidden items-center gap-1.5 text-label text-cream-400 md:flex">
      <StudioIcon name="layers" size="h-3.5 w-3.5" />
      <span>{{ store.canvasLayers.length }} lớp</span>
      <template v-if="store.activeLayer">
        <span class="max-w-32 truncate">· {{ store.activeLayer.name }}</span>
        <span>{{ Math.round((store.activeLayer.scale || 1) * 100) }}%</span>
      </template>
    </div>

    <!-- 6. Menu "⋯" của ĐIỆN THOẠI — cùng các việc trên, không mở đường tắt nào mới.
         Toàn bộ nội dung menu là việc của canvas (nền · bắt điểm · panel Layers) ⇒ ở mặt lưới
         không có gì để mở, nên ẩn luôn nút. Giao diện và Lưu trang trên điện thoại nằm ở menu
         Tài khoản, không mất lối vào. -->
    <div v-if="isCanvas" class="dropdown dropdown-end dropdown-top lg:hidden">
      <div tabindex="0" role="button" class="icon-btn !h-8 !w-8" title="Thêm tuỳ chọn hiển thị" aria-label="Thêm tuỳ chọn hiển thị">
        <StudioIcon name="sliders" size="h-4 w-4" />
      </div>
      <ul tabindex="0" class="menu dropdown-content z-50 mb-1 w-60 rounded-box border border-ink-700 bg-ink-800 p-2 shadow-xl">
        <li class="menu-title"><span>Nền canvas</span></li>
        <li>
          <div class="flex items-center gap-2 px-1 pb-1">
            <button
              v-for="b in BG_OPTIONS"
              :key="'m-' + b.id"
              @click="store.canvasBg = b.id"
              class="motion-ui h-8 w-8 rounded-full border border-ink-600"
              :class="['canvas-bg-' + b.id, store.canvasBg === b.id ? 'ring-2 ring-brand-400' : '']"
              :title="'Nền canvas: ' + b.label"
              :aria-label="'Nền canvas: ' + b.label"
              :aria-pressed="store.canvasBg === b.id"
            ></button>
          </div>
        </li>
        <li>
          <button @click="store.snapGrid = store.snapGrid ? 0 : 8">
            <StudioIcon name="grid" size="h-4 w-4" />
            <span>Bắt điểm (snap): <b>{{ store.snapGrid ? store.snapGrid + 'px' : 'tắt' }}</b></span>
          </button>
        </li>
        <li v-if="store.snapGrid">
          <div class="flex items-center gap-2 px-1 pb-1">
            <button v-for="s in [8, 16, 24, 32]" :key="'s-' + s" @click="store.snapGrid = s"
                    class="min-w-10 rounded-lg px-2 py-1 text-body font-semibold"
                    :class="store.snapGrid === s ? 'bg-brand-600 text-primary-content' : 'bg-ink-700 text-cream-200'">{{ s }}</button>
          </div>
        </li>
        <li>
          <button @click="toggleTheme">
            <StudioIcon v-if="themeResolved === 'light'" name="sun" size="h-4 w-4" />
            <StudioIcon v-else name="moon" size="h-4 w-4" />
            <span>Giao diện: <b>{{ themeResolved === 'light' ? 'Sáng' : 'Tối' }}</b></span>
          </button>
        </li>
        <li>
          <button @click="store.saveNow()">
            <StudioIcon name="save" size="h-4 w-4" />
            <span>Lưu trang</span>
          </button>
        </li>
        <li>
          <button @click="store.toggleInspector()">
            <StudioIcon name="panelRight" size="h-4 w-4" />
            <span>{{ store.inspectorOpen ? 'Ẩn' : 'Hiện' }} panel Layers</span>
          </button>
        </li>
      </ul>
    </div>

    <!-- 7. Máy tính: giao diện · lưu · panel (như cũ, nút to hơn) -->
    <div class="hidden items-center gap-1 lg:flex">
      <div class="h-5 w-px bg-ink-700" aria-hidden="true"></div>
      <button
        @click="toggleTheme"
        class="motion-ui flex h-8 shrink-0 items-center gap-1 rounded-lg border border-ink-600 px-2 text-label font-semibold text-cream-200 transition-colors hover:border-ink-500 hover:text-cream-50"
        :title="'Giao diện đang là ' + (themeResolved === 'light' ? 'Sáng' : 'Tối') + ' — bấm để đổi (muốn theo hệ điều hành: Cài đặt của tôi → Giao diện)'"
        :aria-label="'Đổi giao diện Sáng/Tối, đang là ' + (themeResolved === 'light' ? 'Sáng' : 'Tối')"
      >
        <StudioIcon v-if="themeResolved === 'light'" name="sun" size="h-3.5 w-3.5" />
        <StudioIcon v-else name="moon" size="h-3.5 w-3.5" />
        <span>{{ themeResolved === 'light' ? 'Sáng' : 'Tối' }}</span>
      </button>
      <button @click="store.saveNow()" class="icon-btn !h-8 !w-8" title="Lưu trang (Save)" aria-label="Lưu trang"><StudioIcon name="save" size="h-4 w-4" /></button>
      <!-- Panel Layers là của mặt canvas ⇒ ở mặt lưới không có gì để bật/tắt. -->
      <template v-if="isCanvas">
        <div class="h-5 w-px bg-ink-700" aria-hidden="true"></div>
        <button
          data-dock-toggle="inspector"
          @click="store.toggleInspector()"
          class="icon-btn !h-8 !w-8"
          :class="store.inspectorOpen ? '!bg-brand-600/20 !text-brand-300' : ''"
          title="Bật/tắt panel Layers"
          aria-label="Bật/tắt panel Layers"
        ><StudioIcon name="panelRight" size="h-4 w-4" /></button>
      </template>
    </div>
  </div>
</template>