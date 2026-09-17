<script setup>
/**
 * CollectionsCard — "BỘ SƯU TẬP" (Đợt 2 — 2026-09-19).
 *
 * Vì sao có card này: người làm nghề không nghĩ theo "ảnh lẻ", họ nghĩ theo BỘ SƯU TẬP / ĐƠN HÀNG.
 * Trước đây khái niệm dự án nằm sau nút "Dự án" ở thanh tiêu đề (popover chỉ để áp dụng), còn tiến độ,
 * hạn chót và việc đang chạy thì không thấy ở đâu ⇒ mỗi lần vào làm phải tự nhớ đang ở bộ nào, còn
 * bao nhiêu ảnh, hạn khi nào, còn việc gì chạy dở.
 *
 * Card này gom đúng 3 câu hỏi đó vào MỘT panel trong sidebar:
 *   1) Đang làm bộ nào (áp dụng cho phiên tạo ảnh) — đổi/bỏ trong 1 cú bấm;
 *   2) Các bộ sưu tập gần đây kèm trạng thái · số ảnh · hạn chót;
 *   3) Việc đang chạy (ảnh đang xếp hàng/xử lý) + nút xử lý ngay.
 *
 * KHÔNG thêm API mới: dùng đúng /api/projects (index/store/transition) đã có, nên mọi bất biến về
 * quyền và luồng trạng thái vẫn do máy chủ quyết định.
 */
import { computed, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

// Panel này là ĐIỂM VÀO công việc hằng ngày nên phải tự nạp danh sách bộ sưu tập — không chờ người
// dùng mở popover "Dự án" mới có dữ liệu (trước khi sửa: vào panel thấy trống dù đã có bộ sưu tập).
// Cùng mẫu với LibraryApp/PromptLibraryTab: chưa có dữ liệu VÀ chưa từng nạp thì mới gọi.
onMounted(() => { if (!store.projects.length && !store.projectLoaded) store.loadProjects(); });

// ── Form tạo bộ sưu tập mới ──
const createOpen = ref(false);
const saving = ref(false);
const form = ref({ name: '', season: '', deadline: '', brief: '' });

const applied = computed(() => store.appliedProject || null);

// ── Xuất gói cho xưởng (Đợt 4) ─────────────────────────────────────────────────────
// Người nhận là XƯỞNG MAY (không dùng FabrikAI) nên gói phải tự đủ nghĩa: ảnh + phiếu kỹ thuật +
// bảng size. Bảng size và ghi chú do người dùng nhập ở đây rồi gửi kèm qua query (giới hạn 2000 ký tự
// mỗi trường ở phía máy chủ).
const exportOpen = ref(false);
const exportForm = ref({ sizes: '', note: '' });
function startExport() {
  if (!applied.value) { store.toast('Chọn bộ sưu tập trước khi xuất gói.', 'error'); return; }
  const q = new URLSearchParams();
  if (exportForm.value.sizes.trim()) q.set('sizes', exportForm.value.sizes.trim());
  if (exportForm.value.note.trim()) q.set('note', exportForm.value.note.trim());
  const url = '/api/projects/' + applied.value.id + '/export' + (q.toString() ? '?' + q.toString() : '');
  window.open(url, '_blank', 'noopener');
  store.toast('Đang đóng gói ZIP cho xưởng — trình duyệt sẽ tải về.');
}

/** Bộ sưu tập gần đây (mới cập nhật lên trước) — bỏ cái đang áp dụng để không trùng. */
const recent = computed(() => {
  const list = (store.projects || []).slice();
  const appliedId = applied.value ? Number(applied.value.id) : 0;
  return list
    .filter((p) => Number(p.id) !== appliedId)
    .sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')))
    .slice(0, 5);
});

/** Việc đang chạy: ảnh đang xếp hàng hoặc đang xử lý (số liệu THẬT từ danh sách generation). */
const running = computed(() => (store.generations || []).filter((g) => g.status === 'pending' || g.status === 'processing'));
const pendingCount = computed(() => running.value.filter((g) => g.status === 'pending').length);
const processingCount = computed(() => running.value.filter((g) => g.status === 'processing').length);

function daysLeft(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  const today = new Date();
  return Math.ceil((d.getTime() - today.getTime()) / 86400000);
}
function deadlineLabel(iso) {
  const n = daysLeft(iso);
  if (n === null) return '';
  if (n < 0) return 'Quá hạn ' + Math.abs(n) + ' ngày';
  if (n === 0) return 'Hạn hôm nay';
  return 'Còn ' + n + ' ngày';
}
function deadlineClass(iso) {
  const n = daysLeft(iso);
  if (n === null) return 'bg-ink-700 text-cream-300';
  if (n < 0) return 'bg-red-500/15 text-red-300';
  if (n <= 3) return 'bg-amber-500/15 text-amber-300';
  return 'bg-emerald-500/15 text-emerald-300';
}
function statusClass(p) {
  // Dùng màu máy chủ trả về (status_color) để UI không tự bịa trạng thái.
  return p && p.status_color ? '' : 'bg-ink-700 text-cream-300';
}
function statusStyle(p) {
  return p && p.status_color ? { background: p.status_color + '26', color: p.status_color } : {};
}
async function pick(p) {
  store.applyProject(p);
  store.toast('Đang làm bộ sưu tập «' + p.name + '» — ảnh mới sẽ tự gắn vào đây.');
}
function openWorkspace(p) {
  if (p && (!applied.value || Number(applied.value.id) !== Number(p.id))) store.applyProject(p);
  store.requestWorkspace();
}
async function submit() {
  if (!form.value.name.trim()) { store.toast('Nhập tên bộ sưu tập.', 'error'); return; }
  saving.value = true;
  const payload = {
    name: form.value.name.trim(),
    brief: form.value.brief || null,
    deadline: form.value.deadline || null,
    tags: form.value.season ? [form.value.season] : [],
  };
  const created = await store.createProject(payload);
  saving.value = false;
  if (created) {
    store.applyProject(created);
    form.value = { name: '', season: '', deadline: '', brief: '' };
    createOpen.value = false;
    store.toast('Đã tạo «' + created.name + '» và áp dụng cho phiên làm việc.');
  }
}
</script>

<template>
  <div class="card p-4" style="background: linear-gradient(160deg, rgba(56,129,90,.10), rgba(74,122,144,.05));">
    <div class="flex items-center gap-2">
      <span class="grid h-7 w-7 place-items-center rounded-md bg-brand-500/20 text-brand-300"><StudioIcon name="folderOpen" size="h-4 w-4" /></span>
      <p class="text-sm font-semibold text-brand-300">Bộ sưu tập</p>
      <button class="tool-btn ml-auto" :class="createOpen ? 'is-active' : ''" title="Tạo bộ sưu tập mới cho mùa/vụ hoặc đơn hàng" @click="createOpen = !createOpen">
        <StudioIcon name="plus" size="h-3.5 w-3.5" /> Mới
      </button>
    </div>

    <!-- ── Đang làm ── -->
    <div v-if="applied" class="mt-3 rounded-lg border border-brand-500/30 bg-brand-600/10 p-3">
      <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-200">Đang làm</p>
      <p class="mt-0.5 truncate text-sm font-semibold text-cream-50">{{ applied.name }}</p>
      <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[10px]">
        <span class="rounded-full px-2 py-0.5 font-semibold" :class="statusClass(applied)" :style="statusStyle(applied)">{{ applied.status_label || applied.status }}</span>
        <span class="rounded-full bg-ink-700 px-2 py-0.5 text-cream-200">{{ applied.generations_count || 0 }} ảnh</span>
        <span v-if="applied.deadline" class="rounded-full px-2 py-0.5 font-semibold" :class="deadlineClass(applied.deadline)">{{ deadlineLabel(applied.deadline) }}</span>
      </div>
      <p v-if="applied.brief" class="mt-1.5 line-clamp-2 text-[11px] text-cream-300">{{ applied.brief }}</p>
      <div class="mt-2 flex flex-wrap gap-1.5">
        <button class="tool-btn" @click="openWorkspace(applied)"><StudioIcon name="kanban" size="h-3.5 w-3.5" /> Mở workspace</button>
        <button class="tool-btn" :class="exportOpen ? 'is-active' : ''" title="Đóng gói ảnh + phiếu kỹ thuật + bảng size thành 1 file ZIP để gửi xưởng may" @click="exportOpen = !exportOpen">
          <StudioIcon name="download" size="h-3.5 w-3.5" /> Xuất gói cho xưởng
        </button>
        <button class="tool-btn" title="Không gắn ảnh mới vào bộ này nữa" @click="store.unapplyProject()"><StudioIcon name="pinOff" size="h-3.5 w-3.5" /> Bỏ áp dụng</button>
      </div>

      <!-- Xuất gói cho xưởng: gói ZIP gồm ảnh tham chiếu + phiếu kỹ thuật + bảng size + manifest -->
      <div v-if="exportOpen" class="mt-2 space-y-2 rounded-lg border border-ink-700 bg-ink-900/70 p-2.5">
        <p class="text-[10px] leading-relaxed text-cream-300">
          Gói ZIP gồm: <b class="text-cream-100">ảnh tham chiếu</b> (đánh số) · <b class="text-cream-100">phiếu kỹ thuật</b>
          từng mẫu (chất liệu · màu · đường may) · <b class="text-cream-100">bảng size</b> · thông tin bộ sưu tập ·
          <b class="text-cream-100">manifest.json</b> cho hệ thống của xưởng. Ảnh nào không tải được sẽ được ghi rõ trong gói.
        </p>
        <div>
          <label class="label" for="ex-sizes">Bảng size — mỗi dòng một size (size, ngực, eo, hông, dài áo, dài tay)</label>
          <textarea id="ex-sizes" v-model="exportForm.sizes" rows="3" class="input !py-1.5 text-xs" placeholder="S, 84, 68, 92, 58, 56&#10;M, 88, 72, 96, 59, 57"></textarea>
        </div>
        <div>
          <label class="label" for="ex-note">Ghi chú kỹ thuật chung (chất liệu, màu, yêu cầu riêng)</label>
          <textarea id="ex-note" v-model="exportForm.note" rows="2" class="input !py-1.5 text-xs" placeholder="VD: Vải linen 100%, màu trắng ngà, đường may 1cm, không dùng khoá kéo kim loại"></textarea>
        </div>
        <div class="flex flex-wrap gap-1.5">
          <button class="btn-brand btn-sm flex-1" @click="startExport()">
            <StudioIcon name="download" size="h-3.5 w-3.5" /> Tải gói ZIP
          </button>
          <button class="tool-btn" @click="exportOpen = false">Đóng</button>
        </div>
        <p class="text-[10px] text-cream-300">
          Ảnh AI là ảnh <b class="text-cream-100">tham chiếu</b> — README trong gói nhắc xưởng đối chiếu mẫu thật trước khi sản xuất hàng loạt.
        </p>
      </div>
    </div>
    <div v-else class="mt-3 rounded-lg border border-dashed border-ink-600 p-3 text-[11px] text-cream-300">
      Chưa chọn bộ sưu tập. Ảnh tạo ra hiện không được gắn vào bộ nào — chọn một bộ bên dưới hoặc tạo mới để
      giữ mọi thứ theo mùa vụ/đơn hàng.
    </div>

    <!-- ── Tạo mới ── -->
    <form v-if="createOpen" class="mt-3 space-y-2 rounded-lg border border-ink-700 bg-ink-900/60 p-3" @submit.prevent="submit">
      <div>
        <label class="label" for="col-name">Tên bộ sưu tập</label>
        <input id="col-name" v-model="form.name" class="input !py-1.5 text-xs" placeholder="VD: Thu Đông 2026 · Lookbook" :disabled="saving">
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="label" for="col-season">Mùa / vụ</label>
          <input id="col-season" v-model="form.season" class="input !py-1.5 text-xs" placeholder="VD: Thu Đông 2026" :disabled="saving">
        </div>
        <div>
          <label class="label" for="col-deadline">Hạn chót</label>
          <input id="col-deadline" v-model="form.deadline" type="date" class="input !py-1.5 text-xs" :disabled="saving">
        </div>
      </div>
      <div>
        <label class="label" for="col-brief">Yêu cầu (brief)</label>
        <textarea id="col-brief" v-model="form.brief" rows="2" class="input !py-1.5 text-xs" placeholder="VD: 12 SKU, nền trắng sàn TMĐT + 4 ảnh lookbook ngoài trời" :disabled="saving"></textarea>
      </div>
      <div class="flex gap-2">
        <button type="submit" class="btn-brand btn-sm flex-1" :disabled="saving">
          <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ saving ? 'Đang tạo…' : 'Tạo & áp dụng' }}
        </button>
        <button type="button" class="tool-btn" :disabled="saving" @click="createOpen = false">Huỷ</button>
      </div>
    </form>

    <!-- ── Bộ sưu tập gần đây ── -->
    <div class="mt-3">
      <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-cream-300">Bộ sưu tập gần đây</p>
      <p v-if="store.projectLoading && !store.projects.length" class="text-[11px] text-cream-300">Đang tải…</p>
      <p v-else-if="!recent.length && !applied" class="rounded-lg border border-dashed border-ink-600 p-2.5 text-[11px] text-cream-300">
        Chưa có bộ sưu tập nào. Bấm «Mới» để tạo bộ đầu tiên — mọi ảnh tạo sau đó sẽ tự gắn vào bộ đang làm.
      </p>
      <p v-else-if="!recent.length" class="text-[11px] text-cream-300">Chỉ có bộ đang làm — tạo thêm bộ mới bằng nút «Mới».</p>
      <ul v-else class="space-y-1.5">
        <li v-for="p in recent" :key="p.id" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2">
          <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: p.color || '#559b78' }"></span>
            <button type="button" class="min-w-0 flex-1 truncate text-left text-xs font-semibold text-cream-100 hover:text-white" :title="'Làm việc trên bộ «' + p.name + '»'" @click="pick(p)">{{ p.name }}</button>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-semibold" :class="statusClass(p)" :style="statusStyle(p)">{{ p.status_label || p.status }}</span>
          </div>
          <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[10px] text-cream-300">
            <span>{{ p.generations_count || 0 }} ảnh</span>
            <span v-if="p.deadline" class="rounded-full px-1.5 py-0.5 font-semibold" :class="deadlineClass(p.deadline)">{{ deadlineLabel(p.deadline) }}</span>
            <button type="button" class="ml-auto text-brand-200 hover:underline" @click="openWorkspace(p)">Mở</button>
          </div>
        </li>
      </ul>
    </div>

    <!-- ── Việc đang chạy ── -->
    <div class="mt-3 rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
      <p class="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-cream-300">
        <StudioIcon name="clock" size="h-3 w-3" /> Việc đang chạy
      </p>
      <p v-if="!running.length" class="mt-1 text-[11px] text-cream-300">Không có ảnh nào đang chờ — hàng đợi trống.</p>
      <div v-else class="mt-1 flex flex-wrap items-center gap-2">
        <span class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold text-amber-300">{{ pendingCount }} chờ xử lý</span>
        <span class="rounded-full bg-sky-500/15 px-2 py-0.5 text-[10px] font-semibold text-sky-300">{{ processingCount }} đang tạo</span>
        <button class="tool-btn ml-auto" title="Chạy ngay các ảnh đang chờ (không phải chờ tới lượt)" @click="store.processQueue()">
          <StudioIcon name="play" size="h-3.5 w-3.5" /> Xử lý ngay
        </button>
      </div>
    </div>
  </div>
</template>
