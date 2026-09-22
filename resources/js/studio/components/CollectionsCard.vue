<script setup>
/**
 * CollectionsCard — "Bộ sưu tập" (2026-09-20, bản tối ưu sidebar).
 *
 * THIẾT KẾ:
 *  - Sidebar = không gian hẹp → card phải GỌN, không đè nút.
 *  - Hiển thị rõ Bộ đang áp dụng + tiến trình duyệt 6 bước dạng MINI.
 *  - Link rõ ràng sang trang đầy đủ /bo-suu-tap để thao tác chuyên sâu.
 *  - Giữ lại các hành động nhanh: Duyệt · Chia sẻ · Xuất gói · Vào Studio.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
import { EXPORT_CHANNELS } from '../exportChannels.js';
import StudioIcon from './StudioIcon.vue';
import BaseModal from './BaseModal.vue';
import TechPackEditor from './TechPackEditor.vue';
import SampleTracking from './SampleTracking.vue';
import QcPanel from './QcPanel.vue';
import GatePanel from './GatePanel.vue';

const store = useStudioStore();

onMounted(() => {
  if (!store.projects.length && !store.projectLoaded) store.loadProjects();
  if (store.appliedProject) {
    store.loadProjectStats(store.appliedProject.id);
    store.loadProjectShots(store.appliedProject.id);
  }
  const timer = setInterval(() => {
    if (applied.value?.id) {
      store.loadProjectStats(applied.value.id, true);
      store.loadProjectShots(applied.value.id, true);
    }
  }, 30000);
  onBeforeUnmount(() => clearInterval(timer));
});

const applied = computed(() => store.appliedProject || null);
/**
 * Số mẫu QUÁ HẠN của bộ đang áp dụng.
 *
 * Chỉ tin con số khi bảng trong store là của ĐÚNG bộ này — store giữ bảng gần nhất đã nạp, nên nếu
 * không kiểm thì mở bộ A rồi xem bộ B sẽ thấy cảnh báo của A. Đây đúng là lớp lỗi "số của người khác"
 * mà repo này đã gặp ở nhiều chỗ khác.
 */
const overdueSamples = computed(() => (
  store.samples && store.samplesProjectId === applied.value?.id
    ? Number(store.samples.alerts?.overdue || 0)
    : 0
));
/**
 * Số lô KHÔNG ĐẠT của bộ đang áp dụng — cùng lớp lỗi "số của người khác" đã ghi ở trên: chỉ đọc khi
 * bảng QC trong store ĐÚNG là của bộ này, nếu không thì mở bộ A rồi xem bộ B sẽ thấy số của A.
 */
const failedQc = computed(() => (
  store.qc && store.qcProjectId === applied.value?.id
    ? Number(store.qc.counts?.fail || 0)
    : 0
));
/**
 * Tiến độ ba cổng của bộ đang áp dụng. Cùng lớp lỗi "số của người khác" đã ghi ở trên: chỉ đọc khi bảng
 * trong store ĐÚNG là của bộ này, nếu không mở bộ A rồi xem bộ B sẽ thấy tiến độ của A.
 */
const gateProgress = computed(() => {
  if (!store.gates || store.gatesProjectId !== applied.value?.id) return null;
  const s = store.gates.summary || {};
  return { approved: Number(s.approved || 0), total: Number(s.total || 0), ready: !!s.ready };
});
const stats = computed(() => (applied.value ? store.projectStats[applied.value.id] || null : null));
const shots = computed(() => (applied.value ? (store.projectShots[applied.value.id]?.items || []) : []));
const awaiting = computed(() => shots.value.filter((s) => s.shot_state === 'campaign_ready'));
const approvedCount = computed(() => shots.value.filter((s) => s.shot_state === 'approved').length);
const rejectedCount = computed(() => shots.value.filter((s) => s.shot_state === 'rejected').length);
const shotsSel = ref([]);
const selectedCount = computed(() => shotsSel.value.length);

const reviewOpen = ref(false);
const reviewBusy = ref(false);
const reviewNote = ref('');
const reviewErrors = ref([]);

const shareOpen = ref(false);
const shareBusy = ref(false);
const shareInfo = ref(null);
const shareDays = ref(30);

const exportOpen = ref(false);
const exportForm = ref({ sizes: '', note: '', channel: '' });
// Phiếu kỹ thuật (Việc #3): mở trong hộp thoại vì card sidebar quá hẹp cho một bảng thông số.
const techPackOpen = ref(false);
// Mẫu vật lý (Việc #4): bảng theo dõi FIT · PP · TOP của bộ đang áp dụng.
const samplesOpen = ref(false);
// Kiểm tra chất lượng (Việc #6): biên bản QC của bộ đang áp dụng.
const qcOpen = ref(false);
// Ba cổng duyệt (Việc #7): chốt phiếu kỹ thuật · chốt kế hoạch SX & giá · nghiệm thu QC.
const gatesOpen = ref(false);

const createOpen = ref(false);
const saving = ref(false);
const form = ref({ name: '', season: '', deadline: '', brief: '' });

const recent = computed(() => {
  const list = (store.projects || []).slice();
  const appliedId = applied.value ? Number(applied.value.id) : 0;
  return list
    .filter((p) => Number(p.id) !== appliedId)
    .sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')))
    .slice(0, 4);
});

// Màu CHIP trạng thái đi qua TOKEN ngữ nghĩa (không dùng mã hex của server cho phần chữ):
// cùng một hex cho hai theme thì không thể đạt tương phản ở cả hai (đo được 2,7–3,0:1).
// Mã hex của server vẫn dùng cho THANH tiến độ (trang trí, không phải chữ).
const STATUS_TONE = { draft: 'neutral', in_progress: 'info', review: 'warn', approved: 'ok', archived: 'neutral' };
const TONE_CLASS = {
  neutral: 'bg-ink-700 text-cream-200',
  info: 'bg-info/15 text-info',
  warn: 'bg-warn/15 text-warn',
  ok: 'bg-ok/15 text-ok',
};
function statusToneClass(s) { return TONE_CLASS[STATUS_TONE[s] || 'neutral']; }

const SHOT_STATES = ['idea', 'drafted', 'selected', 'fitted', 'campaign_ready', 'approved'];
const workflowProgress = computed(() => SHOT_STATES.map(state => ({
  state,
  label: state === 'campaign_ready' ? 'Chờ duyệt' : (state === 'idea' ? 'Ý tưởng' : state),
  count: shots.value.filter(s => s.shot_state === state).length,
})));

function stateTone(state) {
  if (state === 'approved') return 'bg-ok text-ok-content';
  if (state === 'rejected') return 'bg-danger text-danger-content';
  if (state === 'campaign_ready') return 'bg-warn text-warn-content';
  return 'bg-ink-800/85 text-cream-100';
}
function statusClass(p) { return statusToneClass(p && p.status); }
function deadlineClass(iso) {
  if (!iso) return 'bg-ink-700 text-cream-300';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return 'bg-ink-700 text-cream-300';
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const target = new Date(d.getTime()); target.setHours(0, 0, 0, 0);
  const days = Math.ceil((target.getTime() - today.getTime()) / 86400000);
  if (days < 0) return 'bg-danger/15 text-danger';
  if (days <= 3) return 'bg-warn/15 text-warn';
  return 'bg-ok/15 text-ok';
}
function deadlineLabel(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const target = new Date(d.getTime()); target.setHours(0, 0, 0, 0);
  const days = Math.ceil((target.getTime() - today.getTime()) / 86400000);
  if (days < 0) return 'Quá hạn ' + Math.abs(days) + ' ngày';
  if (days === 0) return 'Hạn hôm nay';
  return 'Còn ' + days + ' ngày';
}
function daysLeft(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  const today = new Date();
  return Math.ceil((d.getTime() - today.getTime()) / 86400000);
}

// ── Actions ───────────────────────────────────────────────────────────────────
function goToStudio(p) {
  const pid = (p || applied.value)?.id;
  const qs = pid ? '?bo=' + pid + '&panel=collections' : '?panel=collections';
  window.location.href = '/' + qs;
}
function pick(p) {
  store.applyProject(p);
  store.loadProjectStats(p.id);
  store.loadProjectShots(p.id);
  shotsSel.value = [];
  reviewErrors.value = [];
}
async function submit() {
  if (!form.value.name.trim()) { store.toast('Nhập tên bộ sưu tập.', 'error'); return; }
  saving.value = true;
  const created = await store.createProject({
    name: form.value.name.trim(),
    brief: form.value.brief || null,
    deadline: form.value.deadline || null,
    tags: form.value.season ? [form.value.season] : [],
  });
  saving.value = false;
  if (created) {
    store.applyProject(created);
    form.value = { name: '', season: '', deadline: '', brief: '' };
    createOpen.value = false;
  }
}

// ── Review ────────────────────────────────────────────────────────────────────
function openReview() {
  if (!applied.value) { store.toast('Chọn một bộ sưu tập trước.', 'error'); return; }
  reviewOpen.value = true;
  reviewErrors.value = [];
  store.loadProjectShots(applied.value.id).then(() => {
    if (!shotsSel.value.length) shotsSel.value = awaiting.value.map((s) => s.id);
  });
}
function toggleShot(id) {
  shotsSel.value = shotsSel.value.includes(id)
    ? shotsSel.value.filter((x) => x !== id)
    : shotsSel.value.concat([id]);
}
function selectAwaiting() { shotsSel.value = awaiting.value.map((s) => s.id); }
async function reviewBatch(state) {
  if (!applied.value || !shotsSel.value.length || reviewBusy.value) return;
  reviewBusy.value = true;
  const ids = shotsSel.value.slice();
  const verb = state === 'approved' ? 'duyệt' : (state === 'rejected' ? 'loại' : 'chuyển bước cho');
  const d = await store.reviewShots(applied.value.id, ids, state, reviewNote.value.trim());
  reviewBusy.value = false;
  if (!d) return;
  reviewErrors.value = (d.results || []).filter((r) => !r.ok);
  shotsSel.value = [];
  store.toast('Đã ' + verb + ' ' + d.reviewed + '/' + ids.length + ' ảnh'
    + (d.failed ? ' — ' + d.failed + ' ảnh không đổi được.' : '.'),
  d.failed ? 'error' : 'success');
  store.loadProjectStats(applied.value.id, true);
  if (!d.failed) reviewNote.value = '';
}

// ── Share ─────────────────────────────────────────────────────────────────────
async function openShare() {
  if (!applied.value) return;
  shareOpen.value = true;
  shareInfo.value = null;
  shareInfo.value = await store.loadShareStatus(applied.value.id);
}
async function createShare() {
  if (!applied.value || shareBusy.value) return;
  shareBusy.value = true;
  if (await store.createShare(applied.value.id, shareDays.value)) await openShare();
  shareBusy.value = false;
}
async function revokeShare() {
  if (!applied.value || !shareInfo.value?.share || shareBusy.value) return;
  shareBusy.value = true;
  if (await store.revokeShare(applied.value.id, shareInfo.value.share.token)) await openShare();
  shareBusy.value = false;
}
function copyShare() {
  const url = shareInfo.value?.share?.url;
  if (!url) return;
  navigator.clipboard?.writeText(url)
    .then(() => store.toast('Đã copy link chia sẻ.'))
    .catch(() => store.toast('Không copy được — hãy chọn và copy thủ công.', 'error'));
}

// ── Export ────────────────────────────────────────────────────────────────────
function openExport() {
  if (!applied.value) { store.toast('Chọn bộ sưu tập trước.', 'error'); return; }
  exportOpen.value = true;
  // Điền sẵn từ mẫu việc đang chờ — logic nằm ở STORE (một nguồn cho cả card lẫn trang).
  store.applyPendingExport(exportForm.value);
}
async function startExport() {
  if (!applied.value) return;
  store.toast('Đang đóng gói — vui lòng đợi.', 'info');
  try {
    // MỘT đường dữ liệu: fetch + tải file nằm trong store, không chép lại ở mỗi màn hình.
    await store.exportProject(applied.value.id, exportForm.value);
    store.toast('Đã tải gói ZIP về máy.', 'success');
  } catch (e) {
    store.failToast(e, 'Lỗi khi tải gói xuất.');
  }
}
// ── Phím tắt trong khối duyệt (chỉ khi card đang mở khối duyệt) ──────────────
const REVIEW_KEYS = { s: 'select', n: 'next', a: 'approved', r: 'rejected', Escape: 'close' };
function typingIn(el) {
  return !!el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable);
}
function toolBusy() {
  return !!(store.viewer || store.promptOpen || store.planOpen || store.sourcePickerOpen
    || store.confirmDeleteOpen || store.confirmClearCanvasOpen || store.reframeOpen || store.filmOpen
    || store.cropMode || store.inpaintMaskMode !== 'none' || store.drawMode || store.eraseMode
    || store.selectTool || store.panMode);
}
function onReviewKey(e) {
  if (!reviewOpen.value || !applied.value) return;
  if (e.ctrlKey || e.metaKey || e.altKey) return;
  if (typingIn(e.target) || toolBusy()) return;
  const action = REVIEW_KEYS[e.key];
  if (!action) return;
  e.preventDefault();
  if (action === 'close') { reviewOpen.value = false; return; }
  if (action === 'select') { selectAwaiting(); return; }
  if (!shotsSel.value.length) { store.toast('Chọn ảnh trước (S = chọn ảnh chờ duyệt).', 'error'); return; }
  reviewBatch(action);
}
onMounted(() => window.addEventListener('keydown', onReviewKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onReviewKey));
</script>

<template>
  <div class="card overflow-hidden">
    <!-- ══ HEADER: gọn, có link sang trang đầy đủ ══ -->
    <div class="flex items-center gap-2 px-4 py-3">
      <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-500/15 text-brand-300">
        <StudioIcon name="folderOpen" size="h-4 w-4" />
      </span>
      <span class="flex-1 text-sm font-semibold text-cream-100">Bộ sưu tập</span>
      <a href="/bo-suu-tap" class="tool-btn" title="Mở trang Bộ sưu tập đầy đủ (tối ưu cho điện thoại / làm việc chi tiết)">
        <StudioIcon name="panelRight" size="h-3.5 w-3.5" /> Trang
      </a>
      <button class="tool-btn" :class="createOpen ? 'is-active' : ''" title="Tạo bộ sưu tập mới" @click="createOpen = !createOpen">
        <StudioIcon name="plus" size="h-3.5 w-3.5" /> Mới
      </button>
    </div>

    <!-- ══ BỘ ĐANG ÁP DỤNG + TIẾN TRÌNH DUYỆT MẪU 6 BƯỚC ══ -->
    <div v-if="applied" class="mx-3 mb-3 rounded-xl border border-brand-500/25 bg-brand-600/8 p-3">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0 flex-1">
          <p class="text-label font-semibold uppercase tracking-wide text-brand-200">Đang làm</p>
          <p class="mt-0.5 truncate text-sm font-semibold text-cream-50">{{ applied.name }}</p>
          <div class="mt-1.5 flex flex-wrap items-center gap-1 text-label">
            <span class="rounded-full px-2 py-0.5 font-semibold" :class="statusClass(applied)">{{ applied.status_label || applied.status }}</span>
            <span class="rounded-full bg-ink-800 px-2 py-0.5 text-cream-200">{{ applied.generations_count || 0 }} ảnh</span>
            <span v-if="applied.deadline" class="rounded-full px-2 py-0.5 font-semibold" :class="deadlineClass(applied.deadline)">{{ deadlineLabel(applied.deadline) }}</span>
          </div>
        </div>
        <button class="icon-btn !h-6 !w-6 shrink-0" title="Bỏ áp dụng — ảnh mới không gắn vào bộ này nữa" @click="store.unapplyProject()">
          <StudioIcon name="pinOff" size="h-3 w-3" />
        </button>
      </div>

      <!-- [R3] Tiến độ duyệt 6 bước — dạng CHIP NGANG, ngắn gọn cho sidebar -->
      <div class="mt-2.5">
        <p class="mb-1 text-label font-semibold uppercase tracking-wide text-cream-400">Tiến trình duyệt</p>
        <div class="flex items-center gap-1 overflow-x-auto scrollbar-hide">
          <span
            v-for="step in workflowProgress" :key="step.state"
            class="flex shrink-0 items-center gap-1 rounded-full border px-2 py-1 text-label font-semibold transition"
            :class="step.count
              ? 'border-brand-500/40 bg-brand-600/15 text-brand-200'
              : 'border-ink-700/60 bg-ink-900/50 text-cream-400'"
            :title="step.label + ': ' + step.count + ' ảnh'"
          >
            <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="step.count ? 'bg-brand-400' : 'bg-ink-700'"></span>
            {{ step.label }}
            <span v-if="step.count" class="rounded-full bg-brand-600/25 px-1 py-0.5 text-tiny">{{ step.count }}</span>
          </span>
        </div>
      </div>

      <!-- Chi phí & tiến độ thật (từ máy chủ) -->
      <div v-if="stats" class="mt-2.5 rounded-lg border border-ink-700/60 bg-ink-900/50 p-2">
        <div class="flex items-center justify-between">
          <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Chi phí &amp; tiến độ</p>
          <button class="icon-btn !h-4 !w-4" title="Nạp lại" @click="store.loadProjectStats(applied.id, true)">
            <StudioIcon name="refresh" size="h-3 w-3" />
          </button>
        </div>
        <div class="mt-1.5 flex flex-wrap gap-1 text-label">
          <span class="rounded-full bg-ok/12 px-2 py-0.5 font-semibold text-ok">{{ stats.images.completed }} xong</span>
          <span v-if="stats.images.running" class="rounded-full bg-info/12 px-2 py-0.5 font-semibold text-info">{{ stats.images.running }} chạy</span>
          <span v-if="stats.images.failed" class="rounded-full bg-danger/12 px-2 py-0.5 font-semibold text-danger">{{ stats.images.failed }} lỗi</span>
          <span class="rounded-full bg-ink-800 px-2 py-0.5 text-cream-200">{{ stats.credits.used }} credit</span>
          <span v-if="stats.deadline" class="rounded-full px-2 py-0.5 font-semibold" :class="deadlineClass(stats.deadline.days_left)">
            {{ stats.deadline.days_left < 0 ? 'Quá hạn ' + Math.abs(stats.deadline.days_left) : (stats.deadline.days_left === 0 ? 'Hạn hôm nay' : 'Còn ' + stats.deadline.days_left) }}
          </span>
        </div>
        <p v-if="stats.feedback.latest && stats.feedback.latest.message" class="mt-1 text-label leading-relaxed text-cream-300">
          <b class="text-brand-200">Khách ({{ stats.feedback.latest.decision_label }}):</b> «{{ stats.feedback.latest.message }}»
        </p>
      </div>

      <!-- Hành động chuyên sâu -->
      <div class="mt-2.5 flex flex-wrap gap-1.5">
        <button class="tool-btn btn-sm flex-1" :class="awaiting.length ? '!border-warn/40 !bg-warn/8 !text-warn' : ''" @click="openReview()">
          <StudioIcon name="checkSquare" size="h-3.5 w-3.5" /> Duyệt<span v-if="awaiting.length"> · {{ awaiting.length }}</span>
        </button>
        <button class="tool-btn btn-sm" @click="openShare()">
          <StudioIcon name="link" size="h-3.5 w-3.5" />
        </button>
        <button class="tool-btn btn-sm" @click="openExport()">
          <StudioIcon name="download" size="h-3.5 w-3.5" />
        </button>
        <!-- MẪU VẬT LÝ: vòng đời do xưởng làm ra (FIT · PP · TOP). Chấm đỏ khi có mẫu quá hạn. -->
        <button class="tool-btn btn-sm relative" :class="overdueSamples ? '!border-danger/40 !text-danger' : ''" title="Theo dõi mẫu vật lý (FIT · PP · TOP)" @click="samplesOpen = true">
          <StudioIcon name="scissors" size="h-3.5 w-3.5" />
          <span v-if="overdueSamples" class="absolute -right-0.5 -top-0.5 grid h-3.5 w-3.5 place-items-center rounded-full bg-danger text-[9px] font-bold text-cream-50">{{ overdueSamples }}</span>
        </button>
        <!-- KIỂM TRA CHẤT LƯỢNG (Việc #6): biên bản QC của lô đã may — chấm đỏ khi có lô không đạt. -->
        <button class="tool-btn btn-sm relative" :class="failedQc ? '!border-danger/40 !text-danger' : ''" title="Kiểm tra chất lượng (biên bản QC · AQL)" @click="qcOpen = true">
          <StudioIcon name="shieldCheck" size="h-3.5 w-3.5" />
          <span v-if="failedQc" class="absolute -right-0.5 -top-0.5 grid h-3.5 w-3.5 place-items-center rounded-full bg-danger text-[9px] font-bold text-cream-50">{{ failedQc }}</span>
        </button>
        <!-- BA CỔNG DUYỆT (Việc #7): nhãn hiện tiến độ khi đã nạp; viền xanh khi đủ ba cổng. -->
        <button class="tool-btn btn-sm" :class="gateProgress && gateProgress.ready ? '!border-ok/40 !text-ok' : ''"
                :title="'Ba cổng duyệt (thông số · tiền · chất lượng)' + (gateProgress ? ' — ' + gateProgress.approved + '/' + gateProgress.total + ' đã duyệt' : '')"
                @click="gatesOpen = true">
          <StudioIcon name="checkSquare" size="h-3.5 w-3.5" />
          <span v-if="gateProgress && !gateProgress.ready" class="text-[10px] font-semibold">{{ gateProgress.approved }}/{{ gateProgress.total }}</span>
        </button>
        <button class="tool-btn btn-sm" @click="goToStudio(applied)" title="Mở Studio để làm việc trên bộ này">
          <StudioIcon name="arrowRight" size="h-3.5 w-3.5" />
        </button>
      </div>
    </div>

    <!-- ══ CHƯA CHỌN BỘ NÀO ══ -->
    <div v-else class="mx-3 mb-3 rounded-xl border border-dashed border-ink-600 bg-ink-900/30 p-3 text-center">
      <p class="text-xs text-cream-300">Chưa chọn bộ sưu tập.</p>
      <p class="mt-0.5 text-label text-cream-400">Ảnh tạo ra không tự gắn vào đâu — chọn bộ bên dưới hoặc tạo mới.</p>
    </div>

    <!-- ══ DUYỆT MẪU (khối inline gọn) ══ -->
    <div v-if="reviewOpen && applied" class="mx-3 mb-3 rounded-xl border border-ink-700 bg-ink-900/70 p-2.5">
      <div class="flex items-center justify-between gap-2">
        <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Duyệt mẫu</p>
        <button class="icon-btn !h-5 !w-5" title="Đóng" @click="reviewOpen = false">
          <StudioIcon name="x" size="h-3 w-3" />
        </button>
      </div>
      <p class="mt-1 text-label text-cream-300">
        {{ shots.length }} ảnh · <span class="text-warn">{{ awaiting.length }} chờ</span> ·
        <span class="text-ok">{{ approvedCount }} duyệt</span> · <span class="text-danger">{{ rejectedCount }} loại</span>
      </p>
      <div v-if="!shots.length" class="mt-2 rounded-lg border border-dashed border-ink-700 p-3 text-center text-label text-cream-400">
        Chưa có ảnh tạo xong — duyệt được ngay khi ảnh render xong.
      </div>
      <template v-else>
        <div class="mt-2 flex max-h-48 flex-col gap-1.5 overflow-y-auto">
          <button
            v-for="s in shots" :key="s.id"
            class="flex items-center gap-2 rounded-lg border p-1.5 text-left transition"
            :class="shotsSel.includes(s.id) ? 'border-brand-500 bg-brand-600/10' : 'border-ink-600 hover:border-ink-500'"
            @click="toggleShot(s.id)"
          >
            <img v-if="s.thumb" :src="s.thumb" :alt="'Ảnh ' + s.id" class="h-10 w-10 shrink-0 rounded-md object-cover">
            <span v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-ink-800 text-cream-300">
              <StudioIcon name="image" size="h-4 w-4" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-body font-semibold text-cream-100">#{{ s.id }}</span>
              <span class="block truncate text-label text-cream-300">{{ s.shot_label }}</span>
            </span>
            <span class="rounded-full px-1.5 py-0.5 text-tiny font-bold" :class="stateTone(s.shot_state)">{{ s.shot_label }}</span>
            <span v-if="shotsSel.includes(s.id)" class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-600 text-primary-content">
              <StudioIcon name="check" size="h-3 w-3" />
            </span>
          </button>
        </div>
        <div class="mt-2 flex flex-wrap gap-1.5">
          <button class="tool-btn btn-sm flex-1" :disabled="reviewBusy || !selectedCount" @click="reviewBatch('next')">
            <StudioIcon name="chevronRight" size="h-3.5 w-3.5" /> Bước tiếp
          </button>
          <button class="btn-brand btn-sm flex-1" :disabled="reviewBusy || !selectedCount" @click="reviewBatch('approved')">
            <StudioIcon name="check" size="h-3.5 w-3.5" /> Duyệt {{ selectedCount }}
          </button>
          <button class="tool-btn btn-sm !text-danger hover:!bg-danger/15" :disabled="reviewBusy || !selectedCount" @click="reviewBatch('rejected')">
            <StudioIcon name="ban" size="h-3.5 w-3.5" /> Loại {{ selectedCount }}
          </button>
        <p v-if="!reviewBusy && !selectedCount" class="mt-1.5 text-label leading-4 text-warn">↳ Chưa chọn ảnh nào — bấm vào ảnh trong danh sách trên để chọn trước khi duyệt.</p>
        </div>
        <p class="mt-1.5 text-tiny text-cream-400">
          Phím tắt khi khối này đang mở: S chọn ảnh chờ duyệt · N bước tiếp · A duyệt · R loại · Esc đóng
        </p>

        <!-- Lỗi TỪNG ẢNH của lượt duyệt. Trước đây state reviewErrors được gán mà KHÔNG nơi nào render
             (đúng loại lỗi "state chết"): bấm Duyệt 5 ảnh, 2 ảnh hỏng, người dùng không thấy vì sao.
             Nêu cả BƯỚC đang ở (store.shotLabel) để biết ảnh kẹt ở đâu. -->
        <ul v-if="reviewErrors.length" class="mt-2 space-y-1 rounded-lg border border-danger/40 bg-danger/10 p-2 text-label text-danger">
          <li v-for="err in reviewErrors" :key="err.id">Ảnh #{{ err.id }} ({{ store.shotLabel(err.shot_state) }}): {{ err.error }}</li>
        </ul>
      </template>
    </div>

    <!-- ══ CHIA SẺ ══ -->
    <div v-if="shareOpen && applied" class="mx-3 mb-3 rounded-xl border border-ink-700 bg-ink-900/70 p-2.5">
      <div class="flex items-center justify-between">
        <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Chia sẻ cho khách</p>
        <button class="icon-btn !h-5 !w-5" @click="shareOpen = false"><StudioIcon name="x" size="h-3 w-3" /></button>
      </div>
      <div v-if="!shareInfo" class="py-3 text-center text-label text-cream-400">Đang tải…</div>
      <template v-else>
        <div v-if="shareInfo.share" class="space-y-1.5">
          <input :value="shareInfo.share.url" readonly class="input !py-1.5 text-label" @focus="$event.target.select()">
          <div class="flex gap-1.5">
            <button class="btn-brand btn-sm flex-1" @click="copyShare()"><StudioIcon name="copy" size="h-3.5 w-3.5" /> Copy</button>
            <button class="tool-btn btn-sm !text-danger hover:!bg-danger/15" :disabled="shareBusy" @click="revokeShare()"><StudioIcon name="ban" size="h-3.5 w-3.5" /> Thu hồi</button>
          </div>
        </div>
        <div v-else class="space-y-2">
          <p class="text-label leading-relaxed text-cream-300">Tạo link công khai (không cần tài khoản) — khách xem ảnh + gửi phản hồi Duyệt / Yêu cầu sửa.</p>
          <div class="flex items-end gap-2">
            <select v-model.number="shareDays" class="input !py-1.5 text-label">
              <option :value="7">7 ngày</option>
              <option :value="30">30 ngày</option>
              <option :value="90">90 ngày</option>
            </select>
            <button class="btn-brand btn-sm" :disabled="shareBusy" @click="createShare()">Tạo link</button>
          </div>
        </div>
        <div v-if="shareInfo.feedback?.length" class="mt-2 rounded-lg border border-brand-500/20 bg-brand-600/8 p-2">
          <p class="text-tiny font-bold uppercase tracking-wide text-brand-200">Phản hồi gần nhất</p>
          <ul class="mt-1 space-y-1">
            <li v-for="fb in shareInfo.feedback.slice(0, 2)" :key="fb.id" class="text-label">
              <span class="font-semibold text-cream-100">{{ fb.author_name }}</span>
              <span class="ml-1 rounded-full px-1 py-0.5 text-micro font-semibold" :class="fb.decision === 'approved' ? 'bg-ok/15 text-ok' : 'bg-warn/15 text-warn'">{{ fb.decision_label }}</span>
              <span v-if="fb.message" class="ml-1 text-cream-300">{{ fb.message }}</span>
            </li>
          </ul>
        </div>
      </template>
    </div>

    <!-- ══ XUẤT GÓI ══ -->
    <div v-if="exportOpen && applied" class="mx-3 mb-3 rounded-xl border border-ink-700 bg-ink-900/70 p-2.5">
      <div class="flex items-center justify-between">
        <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Xuất gói cho xưởng</p>
        <button class="icon-btn !h-5 !w-5" @click="exportOpen = false"><StudioIcon name="x" size="h-3 w-3" /></button>
      </div>
      <p class="mt-1 text-label leading-relaxed text-cream-300">Gói ZIP gồm: ảnh + phiếu kỹ thuật + bảng size + manifest.</p>
      <div class="mt-2 space-y-2">
        <div>
          <label class="label mb-1 block text-label uppercase tracking-wide text-cream-400" for="ex-sizes">Bảng size</label>
          <label class="block">
            <span class="label">Kênh bán (đặt tên file trong gói)</span>
            <select v-model="exportForm.channel" class="input !py-1.5 text-label" title="Tên ảnh trong gói sẽ có tiền tố theo kênh — sàn TMĐT sắp xếp theo tên file">
              <option v-for="c in EXPORT_CHANNELS" :key="c.id" :value="c.id">{{ c.label }}</option>
            </select>
          </label>
          <textarea id="ex-sizes" v-model="exportForm.sizes" rows="2" class="input !py-1.5 text-label" placeholder="S, 84, 68, 92, 58, 56&#10;M, 88, 72, 96, 59, 57"></textarea>
        </div>
        <div>
          <label class="label mb-1 block text-label uppercase tracking-wide text-cream-400" for="ex-note">Ghi chú kỹ thuật</label>
          <textarea id="ex-note" v-model="exportForm.note" rows="1" class="input !py-1.5 text-label" placeholder="Vải linen 100%, màu trắng ngà, đường may 1cm"></textarea>
        </div>
        <!-- PHIẾU KỸ THUẬT: thông số THẬT đi vào gói. Chưa lập phiếu thì gói vẫn xuất được (mẫu trắng). -->
        <button type="button" class="tool-btn btn-sm w-full justify-start" @click="techPackOpen = true">
          <StudioIcon name="briefcase" size="h-3.5 w-3.5" /> Phiếu kỹ thuật (vải · màu · đường may · bảng thông số)
        </button>
        <div class="flex justify-end gap-2">
          <button class="tool-btn btn-sm" @click="exportOpen = false">Đóng</button>
          <button class="btn-brand btn-sm" @click="startExport()"><StudioIcon name="download" size="h-3.5 w-3.5" /> Tải ZIP</button>
        </div>
      </div>
    </div>

    <BaseModal v-model="techPackOpen" wide :title="'Phiếu kỹ thuật — ' + (applied?.name || '')">
      <TechPackEditor v-if="applied && techPackOpen" :project-id="applied.id" />
    </BaseModal>

    <BaseModal v-model="samplesOpen" wide :title="'Mẫu vật lý — ' + (applied?.name || '')">
      <SampleTracking v-if="applied && samplesOpen" :project-id="applied.id" />
    </BaseModal>

    <BaseModal v-model="qcOpen" wide :title="'Kiểm tra chất lượng — ' + (applied?.name || '')">
      <QcPanel v-if="applied && qcOpen" :project-id="applied.id" />
    </BaseModal>

    <BaseModal v-model="gatesOpen" wide :title="'Ba cổng duyệt — ' + (applied?.name || '')">
      <GatePanel v-if="applied && gatesOpen" :project-id="applied.id" />
    </BaseModal>

    <!-- ══ TẠO MỚI ══ -->
    <div v-if="createOpen" class="mx-3 mb-3 rounded-xl border border-ink-700 bg-ink-900/60 p-3">
      <p class="text-xs font-semibold text-cream-100">Bộ sưu tập mới</p>
      <form class="mt-2 space-y-2" @submit.prevent="submit">
        <input v-model="form.name" class="input !py-1.5 text-xs" placeholder="Tên bộ (VD: Thu Đông 2026)" :disabled="saving">
        <div class="grid grid-cols-2 gap-2">
          <input v-model="form.season" class="input !py-1.5 text-label" placeholder="Mùa / vụ" :disabled="saving">
          <input v-model="form.deadline" type="date" class="input !py-1.5 text-label" :disabled="saving">
        </div>
        <textarea v-model="form.brief" rows="2" class="input !py-1.5 text-label" placeholder="Brief ngắn (tuỳ chọn)" :disabled="saving"></textarea>
        <div class="flex justify-end gap-2">
          <button type="button" class="tool-btn btn-sm" :disabled="saving" @click="createOpen = false">Huỷ</button>
          <button type="submit" class="btn-brand btn-sm" :disabled="saving">{{ saving ? 'Đang tạo…' : 'Tạo & áp dụng' }}</button>
        </div>
      </form>
    </div>

    <!-- ══ BỘ GẦN ĐÂY (RÚT GỌN) ══ -->
    <div v-if="recent.length" class="border-t border-ink-700/60 px-4 py-3">
      <p class="mb-2 text-label font-semibold uppercase tracking-wide text-cream-400">Gần đây</p>
      <ul class="space-y-1.5">
        <li v-for="p in recent" :key="p.id" class="flex items-center justify-between gap-2">
          <button class="min-w-0 flex-1 text-left" @click="pick(p)" :title="'Áp dụng «' + p.name + '»'">
            <span class="motion-ui block truncate text-xs font-medium text-cream-100 hover:text-cream-50">{{ p.name }}</span>
            <span class="text-label text-cream-400">{{ p.generations_count || 0 }} ảnh · {{ p.status_label || p.status }}</span>
          </button>
          <button class="icon-btn !h-6 !w-6 shrink-0" title="Mở trong Studio" @click="goToStudio(p)">
            <StudioIcon name="arrowRight" size="h-3 w-3" />
          </button>
        </li>
      </ul>
    </div>

    <!-- ══ ONBOARDING (khi chưa có bộ nào) ══ -->
    <div v-if="!store.projects.length && !store.projectLoading" class="border-t border-ink-700/60 px-4 py-3">
      <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Bắt đầu</p>
      <p class="mt-1 text-label leading-relaxed text-cream-300">
        Chưa có bộ sưu tập nào. Tạo bộ đầu tiên để ảnh tạo sau này tự gắn vào đúng chỗ.
      </p>
      <a href="/bo-suu-tap" class="mt-2 block text-center text-body font-semibold text-brand-300 underline decoration-dotted hover:text-brand-200">
        Mở trang Bộ sưu tập đầy đủ →
      </a>
    </div>
  </div>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
</style>
