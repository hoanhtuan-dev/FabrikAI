<script setup>
/**
 * CollectionsPage — Trang "Bộ sưu tập" (2026-09-20, bản tối ưu hoá).
 *
 * THIẾT KẾ CHO 3 NHÓM NGƯỜI DÙNG:
 *   1. NGƯỜI MỚI (chưa có bộ nào) → onboarding 4 bước, một nút hành động duy nhất.
 *   2. CHỦ SHOP / DESIGNER (đang làm việc) → hero card của bộ đang áp dụng + gợi ý
 *      "BƯỚC TIẾP THEO" dựa trên trạng thái ảnh THẬT + thanh tiến trình 6 bước duyệt mẫu.
 *   3. SUPER ADMIN (duyệt chéo) → chip "Chờ duyệt toàn hệ thống" + đếm hàng đợi.
 *
 * SỬA BUG so với bản trước:
 *   · ONBOARDING_STEPS được ĐỊNH NGHĨA (bản trước dùng trong template mà không khai báo).
 *   · "Vào Studio" ĐIỀU HƯỚNG tới /?bo=<id> (bản trước gọi store.requestWorkspace() — nhưng
 *     StudioApp không được mount ở trang này nên không có gì nhận tín hiệu: nút bấm không chạy).
 *   · Gọi store.restoreAppliedProject() khi mở trang để lấy lại bộ đang làm từ localStorage.
 *
 * Không thêm API mới — mọi dữ liệu/đối tượng thao tác vẫn từ store dùng chung với StudioApp.
 */
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { useStudioStore } from '../store.js';
import { toastClientErrors } from '../clientErrors.js';
import NotificationCenter from '../components/NotificationCenter.vue';
import { EXPORT_CHANNELS } from '../exportChannels.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from '../components/StudioIcon.vue';
import ProjectDesignView from '../components/ProjectDesignView.vue';
import BaseModal from '../components/BaseModal.vue';
import TechPackEditor from '../components/TechPackEditor.vue';
import SampleTracking from '../components/SampleTracking.vue';
import QcPanel from '../components/QcPanel.vue';
import GatePanel from '../components/GatePanel.vue';
import ProductionTracking from '../components/ProductionTracking.vue';
import { STATUS_COLOR } from '../dataColors.js';
import ShellChrome from '../components/ShellChrome.vue';
// Trình xem ảnh DÙNG CHUNG (cùng component với Studio) — màn chi tiết bộ sưu tập mở nó với ngữ cảnh
// là ảnh của chính bộ đó (xem openDetailShot).
import GalleryModal from '../components/GalleryModal.vue';

// [Xem lại thiết kế] Bộ sưu tập đang xem bản thiết kế đã lưu.
const designView = ref(null);

const store = useStudioStore();
// Lỗi trình duyệt hiện kèm mã tra cứu (xem clientErrors.js).
toastClientErrors((text) => store.toast(text, 'error'));

// ══════════ UI STATE ══════════
const createOpen = ref(false);
const reviewOpen = ref(false);
const shareOpen = ref(false);
const exportOpen = ref(false);
const saving = ref(false);
const reviewBusy = ref(false);
const shareBusy = ref(false);
const shotsSel = ref([]);
const reviewNote = ref('');
const reviewErrors = ref([]);
const form = ref({ name: '', season: '', deadline: '', brief: '' });
const shareDays = ref(30);
const shareInfo = ref(null);
const exportForm = ref({ sizes: '', note: '', channel: '' });
// Phiếu kỹ thuật (Việc #3): hộp thoại riêng — bảng thông số cần chỗ rộng hơn hộp thoại xuất gói.
const techPackOpen = ref(false);
// Mẫu vật lý (Việc #4): bảng theo dõi FIT · PP · TOP — mở trong hộp thoại vì cần chỗ cho bảng.
const samplesOpen = ref(false);
// Kiểm tra chất lượng (Việc #6): biên bản QC của bộ đang áp dụng — nơi ghi LỖI THẬT của lô đã may.
const qcOpen = ref(false);
// Ba cổng duyệt (Việc #7): chốt phiếu kỹ thuật · chốt kế hoạch SX & giá · nghiệm thu QC.
const gatesOpen = ref(false);
// Tiến độ sản xuất (Việc #8): sản lượng THẬT theo ngày, so với kế hoạch.
const productionOpen = ref(false);

/**
 * NĂM VIỆC PHỤ của bộ sưu tập — MỘT nguồn cho cả hai cách hiển thị (2026-09-26).
 *
 * Vì sao gom vào mảng: trước đây chúng là năm nút nằm cạnh nhau trên một hàng; nay desktop hiện trong
 * menu "Thêm" còn điện thoại hiện trong TẤM TRƯỢT ĐÁY (bottom sheet — mẫu điều hướng chuẩn của Material
 * cho màn hình nhỏ). Viết hai lần thì hai bên sẽ lệch nhau ngay lần sửa đầu tiên.
 *
 * `hint` là câu HƯỚNG DẪN, không phải mô tả suông: nó nói tình trạng hiện tại (còn mấy mẫu quá hạn ·
 * mấy lô không đạt · chậm mấy ngày) để người dùng biết nên mở mục nào trước.
 */
const moreActions = computed(() => {
  const mine = (id) => Number(id) === Number(applied?.value?.id);
  const overdue = store.samples && mine(store.samplesProjectId) ? Number(store.samples.alerts?.overdue || 0) : 0;
  const qcFail = store.qc && mine(store.qcProjectId) ? Number(store.qc.counts?.fail || 0) : 0;

  return [
    {
      id: 'share', icon: 'link', label: 'Chia sẻ cho khách',
      hint: 'Gửi link để khách duyệt ảnh, có hạn', alert: false,
      run: () => openShare(),
    },
    {
      id: 'samples', icon: 'scissors', label: 'Mẫu vật lý',
      hint: overdue ? (overdue + ' mẫu quá hạn — mở để xử lý') : 'Theo dõi FIT · PP · TOP và hạn chót của xưởng',
      alert: overdue > 0,
      run: () => { samplesOpen.value = true; },
    },
    {
      id: 'qc', icon: 'shieldCheck', label: 'Kiểm tra chất lượng',
      hint: qcFail ? (qcFail + ' lô không đạt — cần xử lý') : 'Biên bản QC, checklist và mức AQL',
      alert: qcFail > 0,
      run: () => { qcOpen.value = true; },
    },
    {
      id: 'production', icon: 'clock', label: 'Tiến độ sản xuất',
      hint: productionProgress.value && productionProgress.value.behind
        ? ('Chậm ' + productionProgress.value.behind + ' ngày so với hạn')
        : (productionProgress.value ? ('Đã xong ' + productionProgress.value.pct + '% kế hoạch') : 'Ghi sản lượng mỗi ngày, so với kế hoạch'),
      alert: !!(productionProgress.value && productionProgress.value.behind),
      run: () => { productionOpen.value = true; },
    },
    {
      id: 'gates', icon: 'checkSquare', label: 'Ba cổng duyệt',
      hint: gateProgress.value && gateProgress.value.ready
        ? 'Đã đủ ba cổng — sẵn sàng bàn giao'
        : (gateProgress.value ? (gateProgress.value.approved + '/' + gateProgress.value.total + ' cổng đã duyệt') : 'Chốt thông số · chốt tiền · nghiệm thu'),
      alert: false,
      run: () => { gatesOpen.value = true; },
    },
  ];
});
/** Tấm trượt đáy đang mở hay không (chỉ dùng ở màn hình hẹp). */
const moreSheetOpen = ref(false);
const statusFilter = ref('all');
let refreshTimer = null;

// ══════════ NỘI DUNG TĨNH (dịch một lần, không computed) ══════════

/** Onboarding 4 bước cho người mới — mỗi bước nói MỘT việc cần làm + vì sao. */
const ONBOARDING_STEPS = [
  { icon: 'folderOpen', title: 'Tạo Bộ sưu tập', desc: 'Mỗi bộ là một mùa vụ / đơn hàng. Đặt tên + hạn chót là đủ để bắt đầu — có thể bổ sung brief sau.' },
  { icon: 'sparkles', title: 'Tạo ảnh trong Studio', desc: 'Ảnh tạo ra TỰ GẮN vào bộ đang áp dụng — không phải dọn dẹp, phân loại lại sau.' },
  { icon: 'checkSquare', title: 'Duyệt mẫu theo lô', desc: 'Ảnh đi qua 6 bước: Ý tưởng → Phác thảo → Đã chọn → Đã chỉnh → Chờ duyệt → Đã duyệt. Chọn nhiều ảnh, chốt trong 1 lượt.' },
  { icon: 'download', title: 'Xuất gói cho xưởng', desc: 'Tải ZIP gồm ảnh + phiếu kỹ thuật + bảng size — đủ để xưởng may bắt đầu cắt.' },
];

/** 6 bước duyệt mẫu — đúng máy trạng thái máy chủ, có màu + mô tả ngắn cho người mới. */
const WORKFLOW_STEPS = [
  { state: 'idea', label: 'Ý tưởng', hint: 'Ảnh vừa tạo', bar: '#8b8578' },
  { state: 'drafted', label: 'Phác thảo', hint: 'Đã xem qua', bar: '#6b8aad' },
  { state: 'selected', label: 'Đã chọn', hint: 'Ảnh tốt, giữ lại', bar: '#4a7a90' },
  { state: 'fitted', label: 'Đã chỉnh', hint: 'Đã lên phom', bar: '#38815a' },
  { state: 'campaign_ready', label: 'Chờ duyệt', hint: 'Sẵn sàng chốt', bar: '#d9a545' },
  { state: 'approved', label: 'Đã duyệt', hint: 'Chốt sản xuất', bar: '#2d9d6f' },
];

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

const STATUS_ORDER = ['draft', 'in_progress', 'review', 'approved', 'archived'];
const STATUS_LABELS = { draft: 'Nháp', in_progress: 'Đang làm', review: 'Chờ duyệt', approved: 'Đã duyệt', archived: 'Lưu trữ' };

// ══════════ COMPUTED ══════════
const user = computed(() => store.user);
const applied = computed(() => store.appliedProject || null);
const projects = computed(() => store.projects || []);
const initialLoading = computed(() => store.projectLoading && !store.projectLoaded);
const isNewUser = computed(() => !initialLoading.value && projects.value.length === 0);
const isSuperAdmin = computed(() => !!user.value?.is_super_admin);
const statuses = computed(() => store.projectStatuses || {});
const stats = computed(() => (applied.value ? store.projectStats[applied.value.id] || null : null));
// Tiến độ ba cổng của bộ ĐANG áp dụng — dùng cho nhãn trên nút. Chỉ đọc khi bảng trong store đúng là
// của bộ này (bài học "số của người khác" đã ghi ở CollectionsCard: mở bộ A rồi xem bộ B).
/**
 * Tiến độ sản xuất của bộ ĐANG áp dụng — dùng cho nhãn trên nút. Cùng lớp lỗi "số của người khác" đã ghi
 * ở các computed bên dưới: chỉ đọc khi bảng trong store ĐÚNG là của bộ này.
 */
const productionProgress = computed(() => {
  if (!store.production || store.productionProjectId !== applied.value?.id) return null;
  const p = store.production.progress || {};
  if (p.pct === null || p.pct === undefined) return null;
  return { pct: p.pct, behind: Number(p.behind_days || 0) };
});
const gateProgress = computed(() => {
  if (!store.gates || store.gatesProjectId !== applied?.value?.id) return null;
  const s = store.gates.summary || {};
  return { approved: Number(s.approved || 0), total: Number(s.total || 0), ready: !!s.ready };
});


// Ảnh của bộ đang áp dụng + các chỉ số duyệt mẫu
const shots = computed(() => (applied.value ? (store.projectShots[applied.value.id]?.items || []) : []));
const awaiting = computed(() => shots.value.filter((s) => s.shot_state === 'campaign_ready'));
const approvedCount = computed(() => shots.value.filter((s) => s.shot_state === 'approved').length);
const rejectedCount = computed(() => shots.value.filter((s) => s.shot_state === 'rejected').length);
const selectedCount = computed(() => shotsSel.value.length);
const totalShots = computed(() => shots.value.length);
const runningCount = computed(() => (store.generations || []).filter((g) => g.status === 'pending' || g.status === 'processing').length);

/* ══════════════════════════════════════════════════════════════════════════════════════════════
   MÀN CHI TIẾT BỘ SƯU TẬP — prototype `#/collection/:id` (đợt 60 · 2026-09-26)
   ──────────────────────────────────────────────────────────────────────────────────────────────
   VÌ SAO CẦN: prototype có HAI màn cho bộ sưu tập — danh sách (`#/collections`) và CHI TIẾT
   (`#/collection/:id`: «N LOOK · ĐANG CHẠY» · tên + mood · chip lọc · lưới shots · sheet chia sẻ).
   Trang thật chỉ có màn danh sách: muốn xem ảnh của MỘT bộ thì phải "áp dụng" nó rồi sang Studio —
   tức là ĐỔI bối cảnh đang làm việc chỉ để NHÌN. Nay xem được mà không đổi gì.

   Bốn quyết định:
     · ĐƯỜNG DẪN THẬT (`/bo-suu-tap/{id}`) + `pushState` ⇒ nút back của trình duyệt/điện thoại đóng
       màn này (không văng khỏi trang), và gửi link cho đồng nghiệp là mở đúng bộ đó;
     · Ảnh lấy qua `store.loadProjectShots(id)` — ĐÚNG nguồn mà màn danh sách và bảng duyệt mẫu dùng
       (không thêm endpoint);
     · Chip lọc dựng từ VÒNG ĐỜI THẬT của ảnh (`shot_state`) và CHỈ hiện bước đang có ảnh — chip 0 ảnh
       là chip vô nghĩa;
     · Chạm một ảnh mở TRÌNH XEM dùng chung với NGỮ CẢNH là ảnh của bộ này (`openViewer(s, list)`),
       không phải toàn bộ thư viện.
   ══════════════════════════════════════════════════════════════════════════════════════════════ */
const detailId = ref(null);
const detailProject = computed(() => projects.value.find((p) => Number(p.id) === Number(detailId.value)) || null);
const detailLoaded = computed(() => !!store.projectShots[detailId.value]);
const detailShots = computed(() => (detailProject.value ? (store.projectShots[detailProject.value.id]?.items || []) : []));
const detailFilter = ref('all');
const detailChips = computed(() => {
  const counts = {};
  detailShots.value.forEach((s) => { const k = s.shot_state || 'idea'; counts[k] = (counts[k] || 0) + 1; });
  return [{ key: 'all', label: 'Tất cả', count: detailShots.value.length }]
    .concat(WORKFLOW_STEPS.filter((s) => counts[s.state]).map((s) => ({ key: s.state, label: s.label, count: counts[s.state] })));
});
const detailItems = computed(() => (detailFilter.value === 'all'
  ? detailShots.value
  : detailShots.value.filter((s) => (s.shot_state || 'idea') === detailFilter.value)));
const detailStatusLabel = computed(() => (detailProject.value ? statusLabel(detailProject.value.status) : ''));

function openDetail(p) {
  if (!p) return;
  detailId.value = p.id;
  detailFilter.value = 'all';
  store.loadProjectShots(p.id);
  try { history.pushState({ collection: Number(p.id) }, '', '/bo-suu-tap/' + p.id); } catch (e) { /* trình duyệt chặn thì vẫn mở màn */ }
}
/** Đóng màn: nếu entry history là do ta gài thì lùi lại (nuốt entry), không thì đóng thẳng. */
function closeDetail() {
  if (history.state && history.state.collection) { history.back(); return; }
  detailId.value = null;
}
/** Back/Forward của trình duyệt: màn chi tiết bám theo URL, không theo một cờ rời. */
function onDetailPop() {
  const m = location.pathname.match(/^\/bo-suu-tap\/(\d+)/);
  const id = m ? Number(m[1]) : null;
  detailId.value = id;
  if (id) store.loadProjectShots(id);
}
/** Chạm một ảnh trong bộ: mở trình xem dùng chung, NGỮ CẢNH là ảnh của bộ này. */
/** Nhãn của một bước vòng đời ảnh (dùng CHUNG bảng WORKFLOW_STEPS với thanh tiến trình). */
function workflowLabel(state) {
  const s = WORKFLOW_STEPS.find((x) => x.state === (state || 'idea'));
  return s ? s.label : 'Ý tưởng';
}
/**
 * ẢNH CỦA BỘ — dạng dữ liệu THẬT mà `store.loadProjectShots()` trả về:
 * `{ id, thumb, shot_state, shot_label, prompt, created_at }` — KHÔNG phải `media_url`/`name` như một
 * generation. Nên có lớp chuyển đổi ở đây: lưới cần dữ liệu của bộ, còn TRÌNH XEM (GalleryModal) chỉ
 * biết dạng `media_url`. Một chỗ đổi — không rải điều kiện khắp template.
 */
function shotImage(s) { return (s && (s.thumb || s.media_url)) || ''; }
function shotTitle(s) { return (s && (s.shot_label || s.name)) || ('Ảnh #' + (s && s.id)); }
function detailViewerItems() {
  return detailShots.value
    .filter((s) => shotImage(s))
    .map((s) => ({ id: s.id, media_url: shotImage(s), prompt: s.prompt || '', type: 'image', status: 'completed' }));
}
/** Chạm một ảnh trong bộ: mở trình xem dùng chung, NGỮ CẢNH là ảnh của bộ này. */
function openDetailShot(s) {
  const url = shotImage(s);
  if (!url) return;
  store.openViewer({ id: s.id, media_url: url, prompt: s.prompt || '', type: 'image', status: 'completed' }, detailViewerItems());
}

/** Thanh tiến trình 6 bước — số ảnh thật đang ở từng bước. */
const workflowProgress = computed(() => WORKFLOW_STEPS.map((step) => ({
  ...step,
  count: shots.value.filter((s) => s.shot_state === step.state).length,
})));
/** Bước "xa nhất" có ảnh — để tô sáng nhịp tiến trình. */
const furthestStep = computed(() => {
  let idx = -1;
  workflowProgress.value.forEach((s, i) => { if (s.count > 0) idx = i; });
  return idx;
});
const progressPct = computed(() => (totalShots.value ? Math.round((approvedCount.value / totalShots.value) * 100) : 0));

/** Gợi ý "BƯỚC TIẾP THEO" — đúng việc người dùng nên làm NGAY BÂY GIỜ. */
const nextStep = computed(() => {
  if (!applied.value) return null;
  if (!totalShots.value) {
    return { icon: 'sparkles', text: 'Bộ này chưa có ảnh nào — vào Studio tạo ảnh đầu tiên, ảnh sẽ tự gắn vào đây.', action: 'studio', label: 'Vào Studio tạo ảnh' };
  }
  if (awaiting.value.length) {
    return { icon: 'checkSquare', text: 'Có ' + awaiting.value.length + ' ảnh sẵn sàng chốt — duyệt ngay để đẩy tiến trình.', action: 'review', label: 'Duyệt ' + awaiting.value.length + ' ảnh' };
  }
  if (approvedCount.value && approvedCount.value === totalShots.value - rejectedCount.value) {
    return { icon: 'download', text: 'Mọi ảnh đã duyệt xong — đóng gói ZIP gửi xưởng may thôi.', action: 'export', label: 'Xuất gói cho xưởng' };
  }
  const early = shots.value.filter((s) => ['idea', 'drafted', 'selected', 'fitted'].includes(s.shot_state)).length;
  if (early) {
    return { icon: 'chevronRight', text: early + ' ảnh còn ở bước giữa — dùng «Chuyển bước tiếp» để đưa lên hàng chờ duyệt.', action: 'review', label: 'Xử lý ' + early + ' ảnh' };
  }
  return { icon: 'check', text: 'Bộ sưu tập đang đi đúng tiến độ.', action: null, label: null };
});

/** Câu chào theo VAI TRÒ + trạng thái. */
const welcomeText = computed(() => {
  if (isNewUser.value) return 'Chào mừng đến với FabrikAI';
  if (applied.value) return 'Đang làm: «' + applied.value.name + '»';
  const firstName = (user.value?.name || 'bạn').split(' ')[0];
  return 'Xin chào ' + firstName + ' — ' + projects.value.length + ' bộ sưu tập';
});

/** Bộ sưu tập đã loại bộ đang áp dụng + sắp theo hoạt động gần nhất. */
const otherProjects = computed(() => {
  const appliedId = applied.value ? Number(applied.value.id) : 0;
  return projects.value
    .filter((p) => Number(p.id) !== appliedId)
    .sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')));
});

/** Lọc theo trạng thái (chip ở đầu danh sách). */
const filteredProjects = computed(() => {
  if (statusFilter.value === 'pending') return pendingProjects.value;
  if (statusFilter.value !== 'all') return otherProjects.value.filter((p) => p.status === statusFilter.value);
  return otherProjects.value;
});

/** Hàng đợi duyệt toàn hệ thống (Super Admin). */
const pendingProjects = computed(() => {
  if (!store.projectCanReview) return [];
  return projects.value.filter((p) => p.status === 'review' && p.owner_name);
});

/** Chip lọc + đếm từng trạng thái. */
const statusFilters = computed(() => {
  const counts = {};
  for (const p of projects.value) counts[p.status] = (counts[p.status] || 0) + 1;
  const chips = [{ key: 'all', label: 'Tất cả', count: projects.value.length }];
  for (const s of STATUS_ORDER) chips.push({ key: s, label: STATUS_LABELS[s], count: counts[s] || 0 });
  if (store.projectCanReview) chips.push({ key: 'pending', label: 'Chờ duyệt (hệ thống)', count: pendingProjects.value.length });
  return chips;
});

// ══════════ HELPERS ══════════
function statusLabel(s) { return (statuses.value[s] && statuses.value[s].label) || STATUS_LABELS[s] || s; }
function statusColor(s) { return (statuses.value[s] && statuses.value[s].color) || STATUS_COLOR; }
function stateTone(state) {
  if (state === 'approved') return 'bg-ok text-ok-content';
  if (state === 'rejected') return 'bg-danger text-danger-content';
  if (state === 'campaign_ready') return 'bg-warn text-warn-content';
  return 'bg-ink-800/85 text-cream-100';
}
function deadlineDays(d) {
  if (!d) return null;
  const dt = new Date(d);
  if (isNaN(dt.getTime())) return null;
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const target = new Date(dt.getTime()); target.setHours(0, 0, 0, 0);
  return Math.round((target.getTime() - today.getTime()) / 86400000);
}
function isOverdue(p) {
  const n = deadlineDays(p && p.deadline);
  return n !== null && n < 0 && !['approved', 'archived'].includes(p.status);
}
function deadlineLabel(p) {
  const n = deadlineDays(p && p.deadline);
  if (n === null) return '';
  if (n < 0) return 'Quá hạn ' + Math.abs(n) + ' ngày';
  if (n === 0) return 'Hạn hôm nay';
  return 'Còn ' + n + ' ngày';
}
function deadlineToneClass(p) {
  const n = deadlineDays(p && p.deadline);
  if (n === null) return 'text-cream-400';
  if (n < 0) return 'text-danger';
  if (n <= 3) return 'text-warn';
  return 'text-ok';
}
function formatDate(iso) {
  if (!iso) return '';
  const dt = new Date(iso);
  return isNaN(dt.getTime()) ? '' : dt.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
}

// ══════════ ACTIONS ══════════
function goToStudio(p) {
  const pid = (p || applied.value)?.id;
  const qs = pid ? '?bo=' + pid + '&panel=collections' : '?panel=collections';
  window.location.href = '/' + qs;
}
function handleNextStep() {
  const a = nextStep.value?.action;
  if (a === 'studio') goToStudio(applied.value);
  else if (a === 'review') openReview();
  else if (a === 'export') openExport();
}
async function pick(p) {
  const ok = store.applyProject(p);
  if (!ok) return;
  shotsSel.value = [];
  reviewErrors.value = [];
  store.loadProjectStats(p.id);
  store.loadProjectShots(p.id);
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

// ── Duyệt mẫu theo lô ──
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
    + (d.failed ? ' — ' + d.failed + ' ảnh không đổi được, xem lý do bên dưới.' : '.'),
  d.failed ? 'error' : 'success');
  store.loadProjectStats(applied.value.id, true);
  if (!d.failed) reviewNote.value = '';
}

// ── Chia sẻ cho khách ──
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

// ── Xuất gói cho xưởng ──
function openExport() {
  if (!applied.value) { store.toast('Chọn bộ sưu tập trước khi xuất gói.', 'error'); return; }
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
// ══════════ PHÍM TẮT (chỉ khi khối duyệt mở) ══════════
function onReviewKey(e) {
  if (!reviewOpen.value || e.ctrlKey || e.metaKey || e.altKey) return;
  const t = e.target;
  if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
  const k = e.key.toLowerCase();
  if (k === 'escape') { reviewOpen.value = false; e.preventDefault(); return; }
  if (k === 's') { selectAwaiting(); e.preventDefault(); return; }
  if (!shotsSel.value.length) return;
  if (k === 'a') { reviewBatch('approved'); e.preventDefault(); }
  else if (k === 'r') { reviewBatch('rejected'); e.preventDefault(); }
  else if (k === 'n') { reviewBatch('next'); e.preventDefault(); }
}

// ══════════ LIFECYCLE ══════════
onMounted(async () => {
  window.addEventListener('keydown', onReviewKey);
  window.addEventListener('popstate', onDetailPop);
  if (!store.projectLoaded) await store.loadProjects();
  await store.restoreAppliedProject();   // lấy lại "bộ đang làm" từ localStorage
  // Mở thẳng màn chi tiết khi URL là /bo-suu-tap/{id} (link đồng nghiệp gửi, hoặc F5 giữa chừng).
  onDetailPop();
  if (applied.value) {
    store.loadProjectStats(applied.value.id);
    store.loadProjectShots(applied.value.id);
  }
  refreshTimer = setInterval(() => {
    if (applied.value?.id) {
      store.loadProjectStats(applied.value.id, true);
      store.loadProjectShots(applied.value.id, true);
    }
  }, 30000);
});
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onReviewKey);
  window.removeEventListener('popstate', onDetailPop);
  if (refreshTimer) clearInterval(refreshTimer);
});
</script>

<template>
  <div class="flex h-full flex-col bg-ink-950">
    <!-- ══ HEADER ══ -->
    <header class="shrink-0 border-b border-ink-700 bg-ink-900/80 px-4 py-3 backdrop-blur sm:px-6">
      <div class="mx-auto flex max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
          <a href="/" class="grid h-9 w-9 place-items-center rounded-xl bg-ink-800 text-cream-300 transition hover:bg-ink-700 hover:text-cream-100" title="Về Studio">
            <StudioIcon name="arrowLeft" size="h-4 w-4" />
          </a>
          <div class="grid h-10 w-10 place-items-center rounded-xl bg-brand-600/20 text-brand-300">
            <StudioIcon name="folderOpen" size="h-5 w-5" />
          </div>
          <div>
            <h1 class="font-display text-lg font-semibold text-cream-50">Bộ sưu tập</h1>
            <p class="text-xs text-cream-300">{{ welcomeText }}</p>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <!-- Chip "đang tạo" nay là NÚT: card sidebar trước đây có "Xử lý ngay" (store.processQueue),
               khi card thu gọn thì khả năng đó MẤT HẲN khỏi giao diện — hàng đợi chỉ còn chạy theo nhịp
               cron, người dùng không có cách nào thúc tại chỗ. -->
          <button v-if="runningCount" type="button" @click="store.processQueue()"
                  class="motion-ui flex items-center gap-1.5 rounded-full border border-info/40 bg-info/15 px-3 py-1.5 text-xs font-semibold text-info hover:bg-info/25"
                  title="Chạy ngay hàng đợi tạo ảnh — không phải chờ nhịp cron">
            <span class="h-3 w-3 animate-spin rounded-full border-2 border-info/40 border-t-info"></span>
            {{ runningCount }} ảnh đang tạo · Xử lý ngay
          </button>
          <button class="btn-brand btn-sm" @click="createOpen = true">
            <StudioIcon name="plus" size="h-4 w-4" /> Tạo bộ sưu tập
          </button>
        </div>
      </div>
    </header>

    <!-- ══ BODY ══ -->
    <main class="flex-1 overflow-y-auto">
      <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6">

        <!-- ĐANG TẢI -->
        <div v-if="initialLoading" class="flex flex-col items-center justify-center gap-3 py-24">
          <span class="h-8 w-8 animate-spin rounded-full border-2 border-ink-700 border-t-brand-500"></span>
          <p class="text-sm text-cream-400">Đang tải bộ sưu tập…</p>
        </div>

        <!-- ── NGƯỜI MỚI: ONBOARDING 4 BƯỚC ── -->
        <div v-else-if="isNewUser" class="py-8">
          <div class="mb-6 text-center">
            <div class="mx-auto mb-4 grid h-16 w-16 place-items-center rounded-2xl bg-brand-600/20 text-brand-300">
              <StudioIcon name="sparkles" size="h-8 w-8" />
            </div>
            <h2 class="font-display text-2xl font-semibold text-cream-50">Bắt đầu bộ sưu tập đầu tiên</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm leading-relaxed text-cream-300">
              FabrikAI tổ chức ảnh theo <b class="text-cream-100">bộ sưu tập</b> — mỗi bộ là một mùa vụ hoặc đơn hàng.
              Làm theo 4 bước dưới đây, chỉ mất vài phút.
            </p>
          </div>
          <div class="grid gap-4 text-left sm:grid-cols-2">
            <div v-for="(step, idx) in ONBOARDING_STEPS" :key="idx" class="rounded-2xl border border-ink-700 bg-ink-900/60 p-5 transition hover:border-brand-500/40">
              <div class="flex items-start gap-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600/15 text-brand-300">
                  <StudioIcon :name="step.icon" size="h-5 w-5" />
                </span>
                <div>
                  <p class="flex items-center gap-2 font-semibold text-cream-100">
                    <span class="rounded-full bg-brand-600 px-2 py-0.5 text-label font-bold text-primary-content">{{ idx + 1 }}</span>
                    {{ step.title }}
                  </p>
                  <p class="mt-1.5 text-xs leading-relaxed text-cream-300">{{ step.desc }}</p>
                </div>
              </div>
            </div>
          </div>
          <div class="mt-8 text-center">
            <button class="btn-brand" @click="createOpen = true">
              <StudioIcon name="plus" size="h-4 w-4" /> Tạo bộ sưu tập đầu tiên
            </button>
            <p class="mt-2 text-xs text-cream-400">Chỉ cần tên — mọi thứ khác bổ sung sau được.</p>
          </div>
        </div>

        <!-- ── ĐÃ CÓ DỮ LIỆU ── -->
        <template v-else>
          <!-- HERO: bộ sưu tập đang làm -->
          <section v-if="applied" class="mb-8 overflow-hidden rounded-2xl border border-brand-500/30 bg-gradient-to-br from-brand-900/30 via-ink-900/60 to-ink-900/60">
            <div class="p-5 sm:p-6">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="flex items-center gap-1.5 text-label font-bold uppercase tracking-wide text-brand-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-400"></span> Bộ đang làm
                  </p>
                  <h2 class="mt-1 truncate font-display text-xl font-semibold text-cream-50">{{ applied.name }}</h2>
                  <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-semibold" :class="statusToneClass(applied.status)">
                      <span class="h-1.5 w-1.5 rounded-full" :style="{ background: statusColor(applied.status) }"></span>
                      {{ statusLabel(applied.status) }}
                    </span>
                    <span class="rounded-full bg-ink-800 px-2.5 py-1 text-cream-200">{{ totalShots || (applied.generations_count || 0) }} ảnh</span>
                    <span v-if="applied.deadline" class="rounded-full bg-ink-800 px-2.5 py-1 font-semibold" :class="deadlineToneClass(applied)">
                      <StudioIcon name="calendar" size="h-3 w-3" class="mr-1 inline align-[-2px]" />{{ deadlineLabel(applied) }}
                    </span>
                    <button class="rounded-full bg-ink-800 px-2.5 py-1 text-cream-300 transition hover:text-brand-200" @click="store.unapplyProject()" title="Ngắt — ảnh mới không gắn vào bộ này nữa">
                      <StudioIcon name="pinOff" size="h-3 w-3" class="mr-1 inline align-[-2px]" />Ngắt
                    </button>
                  </div>
                  <p v-if="applied.brief" class="mt-2 max-w-2xl text-xs leading-relaxed text-cream-300">{{ applied.brief }}</p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1.5">
                  <div class="flex gap-2">
                    <button class="btn-brand btn-sm" @click="goToStudio(applied)">
                      <StudioIcon name="sparkles" size="h-4 w-4" /> Vào Studio
                    </button>
                  </div>
                </div>
              </div>

              <!-- THANH TIẾN TRÌNH 6 BƯỚC DUYỆT MẪU -->
              <div class="mt-5">
                <div class="mb-2 flex items-center justify-between text-label font-semibold uppercase tracking-wide text-cream-400">
                  <span>Tiến trình duyệt mẫu ({{ totalShots }} ảnh)</span>
                  <span class="text-ok">{{ progressPct }}% đã duyệt</span>
                </div>
                <ol v-if="totalShots" class="flex items-stretch gap-1.5">
                  <li v-for="(step, idx) in workflowProgress" :key="step.state" class="flex flex-1 flex-col gap-1" :title="step.label + ' — ' + step.hint + ': ' + step.count + ' ảnh'">
                    <div class="h-1.5 overflow-hidden rounded-full bg-ink-800">
                      <div class="h-full rounded-full motion-ui motion-ui--size duration-base" :class="step.count > 0 ? 'opacity-100' : 'opacity-0'" :style="{ background: step.bar, width: '100%' }"></div>
                    </div>
                    <div class="flex items-baseline gap-1">
                      <span class="text-label font-bold" :class="step.count > 0 ? 'text-cream-100' : 'text-cream-400'">{{ step.count }}</span>
                      <span class="truncate text-label" :class="step.count > 0 ? 'text-cream-300' : 'text-cream-400'">{{ step.label }}</span>
                    </div>
                    <span v-if="idx < workflowProgress.length - 1 && idx === furthestStep" class="text-label text-brand-300">↓ đang ở đây</span>
                  </li>
                </ol>
                <div v-else class="rounded-xl border border-dashed border-ink-600 px-4 py-3 text-center text-xs text-cream-400">
                  Chưa có ảnh nào trong bộ này — ảnh tạo trong Studio sẽ tự gắn vào.
                </div>
              </div>

              <!-- GỢI Ý BƯỚC TIẾP THEO -->
              <div v-if="nextStep" class="mt-4 flex flex-wrap items-center gap-3 rounded-xl border border-ink-700 bg-ink-900/70 p-3.5">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-600/15 text-brand-300">
                  <StudioIcon :name="nextStep.icon" size="h-4.5 w-4.5" />
                </span>
                <p class="min-w-0 flex-1 text-body leading-relaxed text-cream-200">{{ nextStep.text }}</p>
                <button v-if="nextStep.action" class="btn-brand btn-sm shrink-0" @click="handleNextStep()">
                  {{ nextStep.label }} <StudioIcon name="chevronRight" size="h-3.5 w-3.5" />
                </button>
              </div>

              <!-- ══ HÀNH ĐỘNG — hai việc CHÍNH đứng riêng, phần còn lại vào menu ══════════════════════
                   [2026-09-26 · thiết kế lại] Trước đây bảy nút cùng cỡ nằm cạnh nhau nên không nút nào
                   nói được đâu là việc chính, và trên điện thoại chúng chiếm ba hàng. Nay theo đúng thứ tự
                   ưu tiên của Material: MỘT nút đặc (việc chính) · MỘT nút viền (việc phụ tần suất cao) ·
                   phần còn lại nằm trong "Thêm".

                   HAI CÁCH HIỂN THỊ cho cùng một danh sách (moreActions):
                     · màn hình rộng → dropdown của daisyUI ngay dưới nút;
                     · điện thoại     → TẤM TRƯỢT ĐÁY (bottom sheet) — đúng mẫu của Material, và tránh hẳn
                       cảnh menu bung xuống dưới đáy màn hình rồi bị cắt (đã đo được: nút nằm ở y≈800/844
                       nên menu mở xuống là NGOÀI khung nhìn). -->
              <div class="mt-4 flex flex-wrap items-center gap-2">
                <button class="btn-brand btn-sm" @click="openReview()">
                  <StudioIcon name="checkSquare" size="h-4 w-4" /> Duyệt mẫu
                  <span v-if="awaiting.length" class="ml-1 rounded-full bg-warn px-1.5 text-label font-bold text-on-accent">{{ awaiting.length }}</span>
                </button>
                <button class="btn-outline btn-sm" @click="openExport()">
                  <StudioIcon name="download" size="h-4 w-4" /> Xuất gói xưởng
                </button>

                <!-- Màn hình rộng: menu thả xuống của daisyUI -->
                <div class="dropdown dropdown-end hidden lg:block">
                  <div tabindex="0" role="button" class="tool-btn btn-sm">
                    <StudioIcon name="sliders" size="h-4 w-4" /> Thêm
                    <StudioIcon name="chevronDown" size="h-3.5 w-3.5" />
                  </div>
                  <ul tabindex="0" class="menu dropdown-content z-50 mt-1 w-72 rounded-box border border-ink-700 bg-ink-800 p-2 shadow-xl">
                    <li v-for="a in moreActions" :key="a.id">
                      <button @click="a.run()">
                        <StudioIcon :name="a.icon" size="h-4 w-4" :class="a.alert ? 'text-warn' : ''" />
                        <span><b>{{ a.label }}</b><br><span class="text-label" :class="a.alert ? 'text-warn' : 'text-cream-400'">{{ a.hint }}</span></span>
                      </button>
                    </li>
                  </ul>
                </div>

                <!-- Điện thoại: mở tấm trượt đáy -->
                <button class="tool-btn btn-sm lg:hidden" @click="moreSheetOpen = true">
                  <StudioIcon name="sliders" size="h-4 w-4" /> Thêm
                </button>
              </div>
              <p class="mt-2 text-label leading-5 text-cream-400">
                Thứ tự công việc: <b class="text-cream-200">duyệt ảnh</b> → <b class="text-cream-200">xuất gói xưởng</b> (phiếu kỹ thuật đi kèm)
                → <b class="text-cream-200">mẫu vật lý</b> → <b class="text-cream-200">kiểm tra chất lượng</b> khi hàng về → <b class="text-cream-200">ba cổng duyệt</b> để bàn giao.
              </p>


              <!-- TẤM TRƯỢT ĐÁY (điện thoại) — cùng danh sách moreActions, không chép nội dung -->
              <div v-if="moreSheetOpen" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Việc khác của bộ sưu tập">
                <div class="absolute inset-0 bg-scrim/70" @click="moreSheetOpen = false"></div>
                <div class="absolute inset-x-0 bottom-0 rounded-t-2xl border-t border-ink-700 bg-ink-800 pb-[env(safe-area-inset-bottom)] shadow-2xl">
                  <div class="flex items-center justify-between px-4 pb-2 pt-3">
                    <p class="text-title font-semibold text-cream-100">Việc khác</p>
                    <button type="button" class="icon-btn" aria-label="Đóng" @click="moreSheetOpen = false">
                      <StudioIcon name="x" size="h-4 w-4" />
                    </button>
                  </div>
                  <ul class="pb-3">
                    <li v-for="a in moreActions" :key="a.id">
                      <button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-ink-700"
                              @click="moreSheetOpen = false; a.run()">
                        <StudioIcon :name="a.icon" size="h-5 w-5" class="mt-0.5 shrink-0" :class="a.alert ? 'text-warn' : 'text-brand-300'" />
                        <span class="min-w-0">
                          <b class="block text-body text-cream-100">{{ a.label }}</b>
                          <span class="mt-0.5 block text-label leading-5" :class="a.alert ? 'text-warn' : 'text-cream-400'">{{ a.hint }}</span>
                        </span>
                        <StudioIcon name="chevronRight" size="h-4 w-4" class="ml-auto mt-0.5 shrink-0 text-cream-400" />
                      </button>
                    </li>
                  </ul>
                </div>
              </div>
              <!-- SỐ LIỆU (chi phí · phản hồi khách) -->
              <div v-if="stats" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div class="rounded-xl border border-ink-700/60 bg-ink-900/50 p-3">
                  <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Ảnh xong</p>
                  <p class="mt-0.5 text-lg font-semibold text-ok">{{ stats.images.completed }}</p>
                </div>
                <div class="rounded-xl border border-ink-700/60 bg-ink-900/50 p-3">
                  <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Credit đã dùng</p>
                  <p class="mt-0.5 text-lg font-semibold text-cream-100">{{ stats.credits.used }}</p>
                </div>
                <div class="rounded-xl border border-ink-700/60 bg-ink-900/50 p-3">
                  <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Phản hồi khách</p>
                  <p class="mt-0.5 text-lg font-semibold" :class="stats.feedback.count ? 'text-brand-300' : 'text-cream-400'">{{ stats.feedback.count || 0 }}</p>
                </div>
                <div class="rounded-xl border border-ink-700/60 bg-ink-900/50 p-3">
                  <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Ảnh lỗi</p>
                  <p class="mt-0.5 text-lg font-semibold" :class="stats.images.failed ? 'text-danger' : 'text-cream-400'">{{ stats.images.failed || 0 }}</p>
                </div>
              </div>
              <p v-if="stats && stats.feedback.latest && stats.feedback.latest.message" class="mt-2 rounded-xl border border-brand-500/25 bg-brand-600/10 px-3.5 py-2.5 text-xs text-cream-200">
                <b class="text-brand-200">Khách ({{ stats.feedback.latest.decision_label }}):</b> «{{ stats.feedback.latest.message }}»
              </p>
            </div>
          </section>

          <!-- CHƯA ÁP DỤNG BỘ NÀO -->
          <div v-else class="mb-6 rounded-2xl border border-dashed border-ink-600 bg-ink-900/40 p-5 text-center">
            <p class="text-sm text-cream-200">Chưa chọn bộ sưu tập nào để làm việc.</p>
            <p class="mt-1 text-xs text-cream-400">Ảnh tạo ra sẽ KHÔNG tự gắn vào bộ nào — chọn một bộ bên dưới để bắt đầu.</p>
          </div>

          <!-- ── DANH SÁCH BỘ SƯU TẬP ── -->
          <section>
            <div class="mb-4 flex flex-wrap items-center gap-2">
              <p class="mr-2 text-sm font-semibold text-cream-100">Tất cả bộ sưu tập</p>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="chip in statusFilters" :key="chip.key"
                  class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                  :class="statusFilter === chip.key
                    ? 'border-brand-500 bg-brand-600/25 text-brand-200'
                    : 'border-ink-600 text-cream-300 hover:border-ink-500 hover:text-cream-100'"
                  @click="statusFilter = chip.key"
                >
                  {{ chip.label }} <span class="opacity-70">({{ chip.count }})</span>
                </button>
              </div>
            </div>

            <!-- Danh sách rỗng theo filter -->
            <div v-if="!filteredProjects.length" class="rounded-2xl border border-dashed border-ink-600 p-10 text-center text-sm text-cream-400">
              <template v-if="statusFilter === 'all'">
                {{ applied ? 'Chỉ có bộ đang làm — tạo thêm bằng nút «Tạo bộ sưu tập».' : 'Chưa có bộ sưu tập nào khác.' }}
              </template>
              <template v-else-if="statusFilter === 'pending'">Không có bộ sưu tập nào chờ duyệt toàn hệ thống.</template>
              <template v-else>Không có bộ sưu tập ở trạng thái này.</template>
            </div>

            <!-- Lưới thẻ bộ sưu tập -->
            <!-- NHỊP LƯỚI THEO PROTOTYPE (#/collections): trên điện thoại là lưới 2 cột, và CỨ MỖI THẺ
                 THỨ BA chiếm trọn 2 cột — nhịp bất đối xứng làm danh sách bớt đơn điệu và cho thẻ đó
                 chỗ để lộ ảnh bìa. Từ sm trở lên quay về lưới đều (ở đó bề ngang đã đủ). -->
            <div v-else class="grid grid-cols-2 gap-3 [&>article:nth-child(3n)]:col-span-2 sm:grid-cols-2 sm:gap-4 sm:[&>article:nth-child(3n)]:col-span-1 lg:grid-cols-3">
              <article
                v-for="p in filteredProjects" :key="p.id"
                class="group relative flex flex-col overflow-hidden rounded-2xl border border-ink-700 bg-ink-900/60 transition hover:border-brand-500/40"
              >
                <!-- thanh màu nhận diện -->
                <div class="h-1 w-full" :style="{ background: p.color || statusColor(p.status) }"></div>
                <div class="flex flex-1 flex-col p-4">
                  <div class="flex items-start justify-between gap-2">
                    <button class="min-w-0 text-left" @click="pick(p)" :title="'Áp dụng «' + p.name + '» cho phiên tạo ảnh'">
                      <p class="motion-ui truncate text-sm font-semibold text-cream-50 group-hover:text-cream-50">{{ p.name }}</p>
                      <p v-if="p.brief" class="mt-0.5 line-clamp-1 text-xs text-cream-400">{{ p.brief }}</p>
                    </button>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-label font-semibold" :class="statusToneClass(p.status)">{{ statusLabel(p.status) }}</span>
                  </div>

                  <!-- Ảnh bìa = LỐI VÀO MÀN CHI TIẾT (prototype: chạm thẻ bộ sưu tập → #/collection/:id).
                       Cố ý KHÔNG đổi nút «Áp dụng» cạnh dưới: xem một bộ và ĐỔI bộ đang làm là hai việc
                       khác nhau — trước đây muốn NHÌN ảnh của bộ khác thì phải áp dụng nó trước. -->
                  <button
                    v-if="p.thumbnail"
                    type="button"
                    class="mt-3 h-28 w-full overflow-hidden rounded-xl bg-ink-800"
                    :title="'Mở bộ sưu tập «' + p.name + '»'"
                    :aria-label="'Mở bộ sưu tập ' + p.name"
                    :data-collection-open="p.id"
                    @click="openDetail(p)"
                  >
                    <img :src="thumbUrl(p.thumbnail)" :alt="p.name" class="h-full w-full object-cover transition group-hover:scale-105" loading="lazy" @error="onThumbError($event, p.thumbnail)">
                  </button>

                  <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-cream-300">
                    <span class="inline-flex items-center gap-1"><StudioIcon name="image" size="h-3.5 w-3.5" />{{ p.generations_count || 0 }} ảnh</span>
                    <span v-if="p.deadline" class="inline-flex items-center gap-1 font-semibold" :class="deadlineToneClass(p)">
                      <StudioIcon name="calendar" size="h-3.5 w-3.5" />{{ deadlineLabel(p) }}
                    </span>
                    <span v-if="statusFilter === 'pending' && p.owner_name" class="inline-flex items-center gap-1 text-cream-300">
                      <StudioIcon name="user" size="h-3.5 w-3.5" />{{ p.owner_name }}
                    </span>
                  </div>

                  <div v-if="p.tags && p.tags.length" class="mt-2 flex flex-wrap gap-1">
                    <span v-for="t in p.tags.slice(0, 3)" :key="t" class="rounded-full bg-ink-800 px-2 py-0.5 text-label text-cream-300">{{ t }}</span>
                  </div>

                  <div class="mt-auto flex items-center gap-2 pt-3">
                    <button
                      class="flex-1 rounded-lg px-3 py-1.5 text-center text-xs font-semibold transition"
                      :class="store.appliedProject?.id === p.id
                        ? 'bg-brand-600 text-primary-content'
                        : 'border border-ink-600 text-cream-200 hover:border-brand-400 hover:text-brand-200'"
                      :disabled="store.appliedProject?.id === p.id"
                      @click="pick(p)"
                    >
                      {{ store.appliedProject?.id === p.id ? 'Đang áp dụng' : 'Áp dụng' }}
                    </button>
                    <button class="grid h-8 w-8 place-items-center rounded-lg border border-ink-600 text-cream-300 transition hover:border-brand-400 hover:text-brand-200" :title="'Mở bộ sưu tập «' + p.name + '»'" :aria-label="'Mở bộ sưu tập ' + p.name" @click="openDetail(p)">
                      <StudioIcon name="folderOpen" size="h-3.5 w-3.5" />
                    </button>
                    <button class="grid h-8 w-8 place-items-center rounded-lg border border-ink-600 text-cream-300 transition hover:border-brand-400 hover:text-brand-200" title="Mở trong Studio" @click="goToStudio(p)">
                      <StudioIcon name="arrowRight" size="h-3.5 w-3.5" />
                    </button>
                    <button v-if="p.settings?.agent_studio" type="button" class="grid h-8 w-8 place-items-center rounded-lg border border-brand-500 bg-brand-500/10 text-brand-200 transition hover:bg-brand-500/20" title="Xem lại thiết kế" @click="designView = p">
                      <StudioIcon name="sparkles" size="h-3.5 w-3.5" />
                    </button>
                  </div>
                </div>
              </article>
            </div>
          </section>
        </template>
      </div>
    </main>

    <!-- ══ MODAL: TẠO BỘ SƯU TẬP ══ -->
    <div v-if="createOpen" role="dialog" aria-modal="true" aria-label="Bộ sưu tập mới" class="fixed inset-0 z-[90] flex items-center justify-center bg-scrim/60 p-4" @click.self="createOpen = false">
      <div class="w-full max-w-lg rounded-2xl border border-ink-700 bg-ink-950 p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
          <h3 class="font-display text-base font-semibold text-cream-50">Bộ sưu tập mới</h3>
          <button class="grid h-8 w-8 place-items-center rounded-full bg-ink-800 text-cream-300 transition hover:bg-ink-700" @click="createOpen = false">
            <StudioIcon name="x" size="h-4 w-4" />
          </button>
        </div>
        <form class="space-y-4" @submit.prevent="submit">
          <div>
            <label class="label" for="col-name">Tên bộ sưu tập <span class="text-danger">*</span></label>
            <input id="col-name" v-model="form.name" class="input" placeholder="VD: Thu Đông 2026 · Lookbook" :disabled="saving">
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label" for="col-season">Mùa / vụ</label>
              <input id="col-season" v-model="form.season" class="input" placeholder="VD: Thu Đông 2026" :disabled="saving">
            </div>
            <div>
              <label class="label" for="col-deadline">Hạn chót</label>
              <input id="col-deadline" v-model="form.deadline" type="date" class="input" :disabled="saving">
            </div>
          </div>
          <div>
            <label class="label" for="col-brief">Yêu cầu (brief)</label>
            <textarea id="col-brief" v-model="form.brief" rows="3" class="input" placeholder="VD: 12 SKU, nền trắng sàn TMĐT + 4 ảnh lookbook ngoài trời" :disabled="saving"></textarea>
          </div>
          <div class="flex justify-end gap-3 pt-1">
            <button type="button" class="btn-ghost" :disabled="saving" @click="createOpen = false">Huỷ</button>
            <button type="submit" class="btn-brand" :disabled="saving">
              <StudioIcon name="plus" size="h-4 w-4" /> {{ saving ? 'Đang tạo…' : 'Tạo & áp dụng' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ══ MODAL: DUYỆT MẪU THEO LÔ ══ -->
    <div v-if="reviewOpen && applied" role="dialog" aria-modal="true" aria-label="Duyệt mẫu theo lô" class="fixed inset-0 z-[80] flex items-center justify-center bg-scrim/60 p-4" @click.self="reviewOpen = false">
      <div class="flex max-h-[88vh] w-full max-w-3xl flex-col rounded-2xl border border-ink-700 bg-ink-950 shadow-2xl">
        <div class="shrink-0 border-b border-ink-700 px-5 py-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="font-display text-base font-semibold text-cream-50">Duyệt mẫu — {{ applied.name }}</h3>
              <p class="mt-0.5 text-xs text-cream-300">
                {{ shots.length }} ảnh · <span class="text-warn">{{ awaiting.length }} chờ duyệt</span> ·
                <span class="text-ok">{{ approvedCount }} đã duyệt</span> ·
                <span class="text-danger">{{ rejectedCount }} đã loại</span>
              </p>
            </div>
            <button class="grid h-8 w-8 place-items-center rounded-full bg-ink-800 text-cream-300 transition hover:bg-ink-700" @click="reviewOpen = false">
              <StudioIcon name="x" size="h-4 w-4" />
            </button>
          </div>
          <!-- giải thích luồng cho người mới, đặt ngay trong khối duyệt -->
          <p class="mt-2 rounded-lg bg-ink-900 px-3 py-2 text-body leading-relaxed text-cream-300">
            Ảnh đi theo từng bước: <b class="text-cream-100">Ý tưởng → Phác thảo → Đã chọn → Đã chỉnh → Chờ duyệt → Đã duyệt</b>.
            Chọn ảnh rồi bấm <b class="text-cream-100">Chuyển bước tiếp</b> để đẩy lên bậc kế (không nhảy cóc) — máy chủ chặn bước không hợp lệ và nói rõ lý do.
          </p>
        </div>
        <div class="flex-1 overflow-y-auto p-5">
          <div v-if="!shots.length" class="py-12 text-center text-sm text-cream-400">
            Bộ này chưa có ảnh tạo xong — ảnh render xong sẽ hiện ở đây để duyệt.
          </div>
          <template v-else>
            <div class="mb-4 flex flex-wrap items-center gap-2">
              <button class="tool-btn btn-sm" @click="selectAwaiting()">
                <StudioIcon name="selectAll" size="h-3.5 w-3.5" /> Chọn ảnh chờ duyệt
              </button>
              <button class="tool-btn btn-sm" @click="shotsSel = []">
                <StudioIcon name="x" size="h-3.5 w-3.5" /> Bỏ chọn
              </button>
              <span class="text-xs text-cream-300">Đã chọn <b class="text-cream-100">{{ selectedCount }}</b>/{{ shots.length }}</span>
            </div>
            <div class="grid grid-cols-3 gap-3 sm:grid-cols-4">
              <button
                v-for="s in shots" :key="s.id"
                class="relative overflow-hidden rounded-xl border transition"
                :class="shotsSel.includes(s.id) ? 'border-brand-500 ring-2 ring-brand-400' : 'border-ink-600 hover:border-ink-500'"
                :title="'Ảnh #' + s.id + ' — ' + s.shot_label + (s.prompt ? ': ' + s.prompt : '')"
                @click="toggleShot(s.id)"
              >
                <img v-if="s.thumb" :src="s.thumb" :alt="'Ảnh ' + s.id" class="h-24 w-full object-cover" loading="lazy">
                <span v-else class="flex h-24 w-full items-center justify-center bg-ink-800 text-cream-300"><StudioIcon name="image" size="h-5 w-5" /></span>
                <span class="absolute left-2 top-2 rounded-lg px-1.5 py-0.5 text-tiny font-bold" :class="stateTone(s.shot_state)">{{ s.shot_label }}</span>
                <span v-if="shotsSel.includes(s.id)" class="absolute right-2 top-2 grid h-5 w-5 place-items-center rounded-full bg-brand-600 text-primary-content">
                  <StudioIcon name="check" size="h-3 w-3" />
                </span>
              </button>
            </div>
            <div class="mt-5">
              <label class="label" for="review-note">Ghi chú duyệt (tuỳ chọn)</label>
              <input id="review-note" v-model="reviewNote" class="input" maxlength="1000" placeholder="VD: chốt 12 ảnh đợt 1, loại ảnh lệch màu">
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
              <button class="tool-btn" :disabled="reviewBusy || !selectedCount" title="Đẩy các ảnh đã chọn lên bậc kế (phím N)" @click="reviewBatch('next')">
                <StudioIcon name="chevronRight" size="h-4 w-4" /> Chuyển bước tiếp
              </button>
              <button class="btn-brand btn-sm" :disabled="reviewBusy || !selectedCount" title="Chốt các ảnh đã chọn (phím A)" @click="reviewBatch('approved')">
                <StudioIcon name="check" size="h-4 w-4" /> Duyệt {{ selectedCount }} ảnh
              </button>
              <button class="tool-btn btn-sm !text-danger hover:!bg-danger/15" :disabled="reviewBusy || !selectedCount" title="Loại các ảnh đã chọn (phím R)" @click="reviewBatch('rejected')">
                <StudioIcon name="ban" size="h-4 w-4" /> Loại {{ selectedCount }} ảnh
              </button>
              <p v-if="!reviewBusy && !selectedCount" class="mt-1.5 text-label leading-4 text-warn">↳ Chưa chọn ảnh nào — bấm vào ảnh trong danh sách để chọn trước khi duyệt.</p>
            </div>
            <p class="mt-3 text-body text-cream-400">
              Phím tắt khi khối này đang mở: <b class="text-cream-100">S</b> chọn ảnh chờ duyệt · <b class="text-cream-100">N</b> chuyển bước · <b class="text-cream-100">A</b> duyệt · <b class="text-cream-100">R</b> loại · <b class="text-cream-100">Esc</b> đóng
            </p>
            <ul v-if="reviewErrors.length" class="mt-4 space-y-2">
              <li v-for="err in reviewErrors" :key="err.id" class="rounded-xl border border-danger/30 bg-danger/10 px-4 py-2 text-xs text-danger">
                Ảnh #{{ err.id }} ({{ store.shotLabel(err.shot_state) }}): {{ err.error }}
              </li>
            </ul>
          </template>
        </div>
      </div>
    </div>

    <!-- ══ MODAL: CHIA SẺ ══ -->
    <div v-if="shareOpen && applied" role="dialog" aria-modal="true" aria-label="Chia sẻ link cho khách duyệt" class="fixed inset-0 z-[80] flex items-center justify-center bg-scrim/60 p-4" @click.self="shareOpen = false">
      <div class="w-full max-w-lg rounded-2xl border border-ink-700 bg-ink-950 p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
          <h3 class="font-display text-base font-semibold text-cream-50">Chia sẻ cho khách duyệt</h3>
          <button class="grid h-8 w-8 place-items-center rounded-full bg-ink-800 text-cream-300 transition hover:bg-ink-700" @click="shareOpen = false">
            <StudioIcon name="x" size="h-4 w-4" />
          </button>
        </div>
        <div v-if="!shareInfo" class="py-6 text-center text-sm text-cream-400">Đang tải trạng thái chia sẻ…</div>
        <template v-else>
          <div v-if="shareInfo.share" class="space-y-3">
            <p class="text-sm font-semibold text-cream-100">Link đang hiệu lực</p>
            <input :value="shareInfo.share.url" readonly class="input" @focus="$event.target.select()">
            <div class="flex flex-wrap items-center gap-2 text-xs text-cream-300">
              <span class="rounded-full bg-ink-800 px-2 py-1">{{ shareInfo.share.views }} lượt xem</span>
              <span v-if="shareInfo.share.expires_at" class="rounded-full bg-ink-800 px-2 py-1">hết hạn {{ shareInfo.share.expires_at }}</span>
            </div>
            <div class="flex gap-2">
              <button class="btn-brand btn-sm flex-1" @click="copyShare()"><StudioIcon name="copy" size="h-4 w-4" /> Copy link</button>
              <button class="tool-btn btn-sm !text-danger hover:!bg-danger/15" :disabled="shareBusy" @click="revokeShare()"><StudioIcon name="ban" size="h-4 w-4" /> Thu hồi</button>
            </div>
          </div>
          <div v-else class="space-y-4">
            <p class="text-sm leading-relaxed text-cream-200">
              Tạo link công khai để khách (hoặc người duyệt nội bộ) xem ảnh + brief và bấm
              <b class="text-cream-100">Duyệt</b> / <b class="text-cream-100">Yêu cầu sửa</b> —
              <b>không cần tài khoản FabrikAI</b>. Link có hạn và thu hồi được bất cứ lúc nào.
            </p>
            <div class="flex items-end gap-3">
              <div class="flex-1">
                <label class="label" for="sh-days">Hiệu lực</label>
                <select id="sh-days" v-model.number="shareDays" class="input">
                  <option :value="7">7 ngày</option>
                  <option :value="30">30 ngày</option>
                  <option :value="90">90 ngày</option>
                </select>
              </div>
              <button class="btn-brand" :disabled="shareBusy" @click="createShare()">{{ shareBusy ? 'Đang tạo…' : 'Tạo link' }}</button>
            </div>
          </div>
          <div v-if="shareInfo.feedback && shareInfo.feedback.length" class="mt-4 rounded-xl border border-brand-500/25 bg-brand-600/10 p-4">
            <p class="text-label font-bold uppercase tracking-wide text-brand-200">Phản hồi của khách</p>
            <ul class="mt-2 space-y-2">
              <li v-for="fb in shareInfo.feedback.slice(0, 5)" :key="fb.id" class="text-xs">
                <span class="font-semibold text-cream-100">{{ fb.author_name }}</span>
                <span class="ml-1.5 rounded-full px-1.5 py-0.5 text-tiny font-semibold" :class="fb.decision === 'approved' ? 'bg-ok/15 text-ok' : 'bg-warn/15 text-warn'">{{ fb.decision_label }}</span>
                <span class="ml-1.5 text-cream-400">{{ fb.created_at }}</span>
                <p v-if="fb.message" class="mt-0.5 whitespace-pre-line text-cream-200">{{ fb.message }}</p>
              </li>
            </ul>
          </div>
        </template>
      </div>
    </div>

    <!-- ══ MODAL: XUẤT GÓI ══ -->
    <div v-if="exportOpen && applied" role="dialog" aria-modal="true" aria-label="Xuất gói cho xưởng" class="fixed inset-0 z-[80] flex items-center justify-center bg-scrim/60 p-4" @click.self="exportOpen = false">
      <div class="w-full max-w-lg rounded-2xl border border-ink-700 bg-ink-950 p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
          <h3 class="font-display text-base font-semibold text-cream-50">Xuất gói cho xưởng</h3>
          <button class="grid h-8 w-8 place-items-center rounded-full bg-ink-800 text-cream-300 transition hover:bg-ink-700" @click="exportOpen = false">
            <StudioIcon name="x" size="h-4 w-4" />
          </button>
        </div>
        <p class="text-sm leading-relaxed text-cream-200">
          Gói ZIP gồm: <b class="text-cream-100">ảnh tham chiếu</b> (đánh số) · <b class="text-cream-100">phiếu kỹ thuật</b>
          từng mẫu · <b class="text-cream-100">bảng size</b> · <b class="text-cream-100">manifest.json</b> cho hệ thống của xưởng.
        </p>
        <div class="mt-4 space-y-4">
          <div>
            <label class="label" for="ex-sizes">Bảng size — mỗi dòng một size (size, ngực, eo, hông, dài áo, dài tay)</label>
            <label class="block">
              <span class="label">Kênh bán (đặt tên file trong gói)</span>
              <select v-model="exportForm.channel" class="input" title="Tên ảnh trong gói sẽ có tiền tố theo kênh — sàn TMĐT sắp xếp theo tên file">
                <option v-for="c in EXPORT_CHANNELS" :key="c.id" :value="c.id">{{ c.label }}</option>
              </select>
            </label>
            <textarea id="ex-sizes" v-model="exportForm.sizes" rows="3" class="input" placeholder="S, 84, 68, 92, 58, 56&#10;M, 88, 72, 96, 59, 57"></textarea>
          </div>
          <div>
            <label class="label" for="ex-note">Ghi chú kỹ thuật (chất liệu, màu, yêu cầu riêng)</label>
            <textarea id="ex-note" v-model="exportForm.note" rows="2" class="input" placeholder="VD: Vải linen 100%, màu trắng ngà, đường may 1cm"></textarea>
          </div>
          <!-- PHIẾU KỸ THUẬT: thông số THẬT đi vào tệp gửi xưởng. Chưa lập thì gói vẫn xuất được (mẫu trắng). -->
          <div class="rounded-lg border border-ink-700 bg-ink-900/60 px-3 py-2.5">
            <p class="text-label font-semibold text-cream-200">Phiếu kỹ thuật</p>
            <p class="mt-1 text-label leading-5 text-cream-400">Vải · màu · đường may · bảng thông số theo size. Phiếu này thay các dòng chấm trống trong tệp gửi xưởng.</p>
            <button type="button" class="tool-btn btn-sm mt-2" @click="techPackOpen = true">
              <StudioIcon name="briefcase" size="h-3.5 w-3.5" /> Mở phiếu kỹ thuật
            </button>
          </div>
          <div class="flex justify-end gap-3">
            <button class="btn-ghost" @click="exportOpen = false">Đóng</button>
            <button class="btn-brand" @click="startExport()"><StudioIcon name="download" size="h-4 w-4" /> Tải gói ZIP</button>
          </div>
        </div>
      </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════════════════════════════════════
         MÀN CHI TIẾT BỘ SƯU TẬP (prototype #/collection/:id) — đợt 60.
         Màn CHIẾM TRỌN, không phải modal: nó là một ĐÍCH đến được (có URL riêng), không phải hộp thoại
         phụ. Tầng 95 = cùng tầng với ProjectWorkspace (bề mặt chiếm trọn lớn nhất của trang này).
         Bố cục theo prototype: hàng đầu (← · «N ẢNH · TRẠNG THÁI» · nút đóng) → tên + brief → chip lọc →
         lưới ảnh (ô đầu TO gấp đôi) → hàng việc (Áp dụng · Mở trong Studio).
         ══════════════════════════════════════════════════════════════════════════════════════════ -->
    <section
      v-if="detailProject"
      class="fixed inset-0 z-[95] flex flex-col bg-ink-950 text-cream-100 motion-fade-in"
      role="dialog"
      aria-modal="true"
      :aria-label="'Bộ sưu tập ' + detailProject.name"
      data-collection-detail
    >
      <header class="shrink-0 border-b border-ink-700 bg-ink-900 px-3 pb-3" style="padding-top: calc(env(safe-area-inset-top, 0px) + 10px)">
        <div class="flex items-center gap-2">
          <button type="button" class="icon-btn !h-10 !w-10 shrink-0" title="Về danh sách bộ sưu tập" aria-label="Về danh sách bộ sưu tập" data-collection-detail-back @click="closeDetail">
            <StudioIcon name="arrowLeft" size="h-5 w-5" />
          </button>
          <p class="min-w-0 flex-1 truncate text-center text-micro font-semibold uppercase tracking-[0.16em] text-cream-400">
            {{ detailProject.generations_count || detailShots.length }} ảnh · {{ detailStatusLabel }}
          </p>
          <button type="button" class="icon-btn !h-10 !w-10 shrink-0" title="Đóng" aria-label="Đóng" @click="closeDetail">
            <StudioIcon name="x" size="h-5 w-5" />
          </button>
        </div>
        <h2 class="mt-2 truncate font-display text-xl font-semibold text-cream-50">{{ detailProject.name }}</h2>
        <p v-if="detailProject.brief" class="mt-1 line-clamp-2 text-body text-cream-300">{{ detailProject.brief }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-micro text-cream-400">
          <span v-if="detailProject.deadline" class="inline-flex items-center gap-1"><StudioIcon name="calendar" size="h-3.5 w-3.5" />{{ deadlineLabel(detailProject) }}</span>
          <span v-if="detailProject.owner_name" class="inline-flex items-center gap-1"><StudioIcon name="user" size="h-3.5 w-3.5" />{{ detailProject.owner_name }}</span>
          <span v-if="store.appliedProject?.id === detailProject.id" class="inline-flex items-center gap-1 font-semibold text-brand-200"><StudioIcon name="check" size="h-3.5 w-3.5" />Đang áp dụng</span>
        </div>
      </header>

      <!-- Chip lọc theo VÒNG ĐỜI ẢNH — chỉ hiện bước đang có ảnh (chip 0 ảnh là chip vô nghĩa). -->
      <div v-if="detailChips.length > 1" class="scrollbar-hide flex shrink-0 gap-2 overflow-x-auto border-b border-ink-800 px-4 py-2.5" role="tablist" aria-label="Lọc ảnh theo bước">
        <button
          v-for="c in detailChips" :key="c.key"
          type="button"
          role="tab"
          :aria-selected="detailFilter === c.key"
          class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-3.5 text-label font-semibold transition"
          :class="detailFilter === c.key ? 'border-brand-500 bg-brand-600/20 text-cream-50' : 'border-ink-600 bg-ink-800 text-cream-300 hover:border-brand-400'"
          :data-collection-chip="c.key"
          @click="detailFilter = c.key"
        >
          {{ c.label }}<span class="text-cream-400">{{ c.count }}</span>
        </button>
      </div>

      <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-24 pt-3">
        <p v-if="!detailLoaded" class="py-10 text-center text-body text-cream-400" role="status">Đang tải ảnh…</p>
        <div v-else-if="detailItems.length" class="grid grid-cols-2 gap-3">
          <button
            v-for="(s, i) in detailItems" :key="s.id"
            type="button"
            class="relative overflow-hidden rounded-2xl border border-ink-600 bg-ink-800 text-left transition active:scale-[0.98]"
            :class="i === 0 && detailFilter === 'all' ? 'col-span-2' : ''"
            :aria-label="'Xem ảnh ' + shotTitle(s)"
            :data-collection-shot="s.id"
            @click="openDetailShot(s)"
          >
            <img :src="thumbUrl(shotImage(s), 480)" :alt="shotTitle(s)" class="w-full object-cover" :class="i === 0 && detailFilter === 'all' ? 'aspect-[4/3]' : 'aspect-square'" loading="lazy" @error="onThumbError($event, shotImage(s))">
            <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-ink-950/85 to-transparent px-3 pb-2 pt-6">
              <b class="block truncate text-label font-semibold text-cream-50">{{ shotTitle(s) }}</b>
              <i class="block text-micro not-italic text-cream-300">{{ workflowLabel(s.shot_state) }}</i>
            </span>
          </button>
        </div>
        <div v-else class="rounded-2xl border border-dashed border-ink-600 px-6 py-10 text-center">
          <StudioIcon name="image" size="h-7 w-7" class="mx-auto text-cream-400" />
          <p class="mt-2 text-body text-cream-300">
            {{ detailShots.length ? 'Không có ảnh ở bước này.' : 'Bộ này chưa có ảnh — vào Studio tạo ảnh, ảnh sẽ tự gắn vào bộ đang áp dụng.' }}
          </p>
          <button v-if="!detailShots.length" type="button" class="btn-magic mt-3 inline-flex h-11 items-center gap-2 rounded-full px-5 text-label font-bold" @click="goToStudio(detailProject)">
            <StudioIcon name="sparkles" size="h-4 w-4" /> Vào Studio tạo ảnh
          </button>
        </div>
      </div>

      <!-- Hàng việc của bộ: ĐỔI bộ đang làm, hoặc đi tiếp sang Studio. Hai việc này KHÁC việc xem. -->
      <footer class="shrink-0 border-t border-ink-700 bg-ink-900 px-4 py-3" style="padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 12px)">
        <div class="flex gap-2">
          <button
            type="button"
            class="flex h-12 flex-1 items-center justify-center gap-2 rounded-2xl border border-ink-600 bg-ink-800 text-label font-semibold text-cream-200 transition hover:border-brand-400 disabled:opacity-50"
            :disabled="store.appliedProject?.id === detailProject.id"
            data-collection-apply
            @click="pick(detailProject)"
          >
            <StudioIcon name="check" size="h-4 w-4" class="text-brand-300" />
            {{ store.appliedProject?.id === detailProject.id ? 'Đang áp dụng' : 'Áp dụng cho phiên này' }}
          </button>
          <button type="button" class="btn-magic flex h-12 flex-1 items-center justify-center gap-2 rounded-2xl text-label font-bold transition active:scale-[0.98]" @click="goToStudio(detailProject)">
            <StudioIcon name="arrowRight" size="h-4 w-4" /> Mở trong Studio
          </button>
        </div>
      </footer>
    </section>

    <!-- Trình xem ảnh dùng chung — mở từ lưới ảnh của màn chi tiết (ngữ cảnh: ảnh CỦA BỘ NÀY). -->
    <GalleryModal v-if="store.viewer" />

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

    <BaseModal v-model="productionOpen" wide :title="'Tiến độ sản xuất — ' + (applied?.name || '')">
      <ProductionTracking v-if="applied && productionOpen" :project-id="applied.id" />
    </BaseModal>

    <!-- store.toast() đã được gọi ở khắp trang này nhưng KHÔNG có chỗ render ⇒ thông báo (kèm mã tra cứu) vô hình. -->
    <NotificationCenter />
  </div>

  <!-- Xem lại thiết kế đã lưu (Agent Studio) -->
  <ProjectDesignView v-model="designView" :settings="designView?.settings || {}" :name="designView?.name || ''" />
  <!-- [Phase 4 · shell 2026] Thanh lệnh chung (orb → không gian; prompt → Studio). Spacer h-24
       giữ nội dung cuối không bị thanh lệnh cố định che. -->
  <div class="h-24 shrink-0" aria-hidden="true"></div>
  <ShellChrome space="collections" />
</template>

<style scoped>
.line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
</style>
