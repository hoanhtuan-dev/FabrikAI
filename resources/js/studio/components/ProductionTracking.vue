<script setup>
/**
 * TIẾN ĐỘ SẢN XUẤT — thực tế so với kế hoạch (Việc #8, 2026-09-26).
 *
 * VÌ SAO CÓ MÀN HÌNH NÀY: kế hoạch sản xuất (lệnh cắt · số ngày · năng lực xưởng) đã có, nhưng nó là KẾ
 * HOẠCH — một tờ giấy đúng ở thời điểm lập. Sau khi lên kế hoạch, thứ duy nhất còn thiếu là SỐ THẬT mỗi
 * ngày, và từ đó là câu trả lời cho câu hỏi duy nhất chủ xưởng cần: "có kịp không, lệch bao nhiêu?".
 *
 * BỐN QUYẾT ĐỊNH GIAO DIỆN:
 *   1. NHẬP TRƯỚC, ĐỌC SAU: ô ghi sản lượng đặt ngay trên cùng vì đó là việc lặp lại mỗi ngày; bảng lịch
 *      sử ở dưới.
 *   2. CẢNH BÁO KÈM CON SỐ (chậm 1 ngày · cần 150 cái/ngày mà đang làm 120) — cảnh báo không số là cảnh
 *      báo sẽ bị bỏ qua.
 *   3. MỌI CON SỐ DO MÁY CHỦ TÍNH: giao diện chỉ hiện. Tự tính ở máy khách là chỗ để hai bên lệch nhau
 *      sau mỗi lần sửa số.
 *   4. NÓI RÕ "MỘT DÒNG MỘT NGÀY": ghi lại cùng ngày là SỬA, không cộng thêm — người dùng phải biết.
 */
import { computed, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const props = defineProps({
  projectId: { type: Number, required: true },
});

const store = useStudioStore();

onMounted(() => { store.loadProduction(props.projectId); });

const EMPTY = { items: [], plan: {}, actual: {}, progress: {}, alerts: [], summary: {}, shape: { limits: {} } };
const data = computed(() => store.production || EMPTY);
const items = computed(() => data.value.items || []);
const plan = computed(() => data.value.plan || {});
const actual = computed(() => data.value.actual || {});
const progress = computed(() => data.value.progress || {});
const alerts = computed(() => data.value.alerts || []);
const summary = computed(() => data.value.summary || {});

const today = new Date().toISOString().slice(0, 10);
const form = ref({ logged_on: today, units_done: '', units_defect: '', note: '' });
const formError = ref('');
const pendingDelete = ref(0);

function statusClass(status) {
  if (status === 'done') return 'bg-ok/15 text-ok';
  if (status === 'behind') return 'bg-danger/15 text-danger';
  if (status === 'on_track') return 'bg-ok/15 text-ok';
  return 'bg-ink-700 text-cream-300';
}

function alertClass(level) {
  if (level === 'danger') return 'border-danger/40 bg-danger/10 text-danger';
  if (level === 'warn') return 'border-warn/40 bg-warn/10 text-warn';
  return 'border-ink-700 bg-ink-900 text-cream-300';
}

function money(value) {
  return Number(value || 0).toLocaleString('vi-VN');
}

/** Ô số của ngày hôm nay đã có dòng chưa — hiện sẵn số cũ để sửa thay vì gõ lại từ đầu. */
function fillToday() {
  if (summary.value.today_logged) {
    const row = items.value.find((r) => r.logged_on === summary.value.today);
    if (row) {
      form.value = { logged_on: row.logged_on, units_done: row.units_done, units_defect: row.units_defect, note: row.note };
      return;
    }
  }
  form.value = { logged_on: today, units_done: '', units_defect: '', note: '' };
}

async function save() {
  formError.value = '';
  const done = Number(form.value.units_done);
  if (!form.value.logged_on) {
    formError.value = 'Chọn NGÀY ghi sản lượng.';
    return;
  }
  if (!Number.isFinite(done) || done < 0) {
    formError.value = 'Số cái làm xong phải là số ≥ 0.';
    return;
  }

  const ok = await store.logProduction(props.projectId, {
    logged_on: form.value.logged_on,
    units_done: Math.floor(done),
    units_defect: Math.floor(Number(form.value.units_defect) || 0),
    note: form.value.note,
  });

  if (ok) form.value = { logged_on: today, units_done: '', units_defect: '', note: '' };
}

/** Xoá bằng HAI NHỊP — thao tác lặp lại, hộp thoại cho từng dòng là biến việc dọn số thành một buổi làm việc. */
async function remove(row) {
  if (pendingDelete.value !== row.id) {
    pendingDelete.value = row.id;
    return;
  }
  pendingDelete.value = 0;
  await store.deleteProduction(props.projectId, row.id);
}
</script>

<template>
  <section aria-label="Tiến độ sản xuất" :aria-busy="store.productionLoading">
    <div v-if="store.productionLoading" class="p-5 text-body text-cream-400">Đang tải tiến độ sản xuất…</div>

    <p v-else-if="store.productionError" role="alert" class="m-4 rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-body text-danger">
      {{ store.productionError }}
    </p>

    <div v-else class="space-y-4 p-4 sm:p-5">
      <!-- TÌNH TRẠNG -->
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full px-2.5 py-0.5 text-label font-semibold" :class="statusClass(progress.status)">{{ progress.status_label }}</span>
        <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-200">
          {{ money(actual.units_done) }}<span v-if="plan.units"> / {{ money(plan.units) }} cái</span>
        </span>
        <span v-if="progress.pct !== null && progress.pct !== undefined" class="rounded-full bg-brand-600/20 px-2.5 py-0.5 text-label font-semibold text-brand-200">{{ progress.pct }}%</span>
        <span v-if="actual.pace_per_day" class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-300">nhịp {{ actual.pace_per_day }} cái/ngày</span>
        <span v-if="progress.behind_days" class="rounded-full bg-danger/15 px-2.5 py-0.5 text-label font-semibold text-danger">chậm {{ progress.behind_days }} ngày</span>
        <span v-if="progress.forecast_label" class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-300">dự kiến xong {{ progress.forecast_label }}</span>
        <span v-if="plan.deadline_label" class="flex items-center gap-1 rounded-full border border-ink-600 px-2 py-0.5 text-tiny text-cream-300">
          <StudioIcon name="calendar" size="h-3 w-3" /> hạn {{ plan.deadline_label }}
        </span>
      </div>

      <!-- CẢNH BÁO -->
      <ul v-if="alerts.length" class="space-y-1">
        <li v-for="(alert, i) in alerts" :key="i" class="flex items-start gap-1.5 rounded border px-2 py-1 text-label leading-5" :class="alertClass(alert.level)">
          <StudioIcon name="alertTriangle" size="h-3.5 w-3.5 shrink-0" /> <span>{{ alert.text }}</span>
        </li>
      </ul>

      <!-- GHI SẢN LƯỢNG -->
      <div class="rounded-lg border border-ink-700 bg-ink-900/40 p-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Ghi sản lượng một ngày</p>
          <button v-if="summary.today_logged" type="button" class="tool-btn btn-sm" @click="fillToday">
            <StudioIcon name="pencil" size="h-3.5 w-3.5" /> Sửa số hôm nay ({{ money(summary.today_units) }} cái)
          </button>
        </div>
        <div class="mt-2 grid gap-2 sm:grid-cols-4">
          <label class="text-label text-cream-300">
            Ngày
            <input v-model="form.logged_on" type="date" :max="today" class="input mt-0.5 w-full !py-1">
          </label>
          <label class="text-label text-cream-300">
            Làm xong (cái)
            <input v-model="form.units_done" type="number" min="0" class="input mt-0.5 w-full !py-1" placeholder="VD: 120">
          </label>
          <label class="text-label text-cream-300">
            Trong đó lỗi (cái)
            <input v-model="form.units_defect" type="number" min="0" class="input mt-0.5 w-full !py-1" placeholder="VD: 4">
          </label>
          <label class="text-label text-cream-300">
            Ghi chú
            <input v-model="form.note" class="input mt-0.5 w-full !py-1" :maxlength="(data.shape.limits || {}).note_max || 300" placeholder="VD: xưởng nghỉ 2 giờ vì mất điện">
          </label>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <button type="button" class="btn-brand btn-sm" :disabled="store.productionSaving" @click="save">
            <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ store.productionSaving ? 'Đang lưu…' : 'Lưu sản lượng' }}
          </button>
          <span class="text-label text-cream-400">Một ngày chỉ có MỘT dòng — ghi lại cùng ngày là sửa số cũ.</span>
          <span v-if="formError" class="text-label text-warn">↳ {{ formError }}</span>
        </div>
      </div>

      <!-- KHÔNG CÓ KẾ HOẠCH -->
      <p v-if="progress.status === 'no_plan'" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
        Bộ sưu tập chưa có kế hoạch sản xuất nên chưa so được tiến độ. Số bạn ghi vẫn được lưu — mở
        <b class="text-cream-100">Agent Studio → Định hướng → Sản xuất &amp; lãi</b> rồi lưu vào bộ, tiến độ sẽ tự tính.
      </p>

      <!-- LỊCH SỬ -->
      <div v-if="items.length" class="space-y-1">
        <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Lịch sử ghi ({{ summary.total }} ngày)</p>
        <div class="max-h-72 space-y-1 overflow-y-auto pr-1">
          <div v-for="row in items" :key="row.id" class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-900/60 px-2.5 py-1.5">
            <span class="w-20 text-label whitespace-nowrap text-cream-200">{{ row.logged_on_label }}</span>
            <span class="text-label text-cream-100">{{ money(row.units_done) }} cái</span>
            <span v-if="row.units_defect" class="rounded-full bg-warn/15 px-2 py-0.5 text-tiny text-warn">lỗi {{ money(row.units_defect) }}<span v-if="row.defect_pct !== null"> ({{ row.defect_pct }}%)</span></span>
            <span v-if="row.note" class="min-w-0 flex-1 truncate text-tiny text-cream-400">{{ row.note }}</span>
            <span v-if="row.created_by" class="text-tiny text-cream-400">{{ row.created_by }}</span>
            <button type="button" class="icon-btn ml-auto h-7 w-7" :class="pendingDelete === row.id ? '!text-danger' : ''"
                    :title="pendingDelete === row.id ? 'Bấm lần nữa để xoá ngày này' : 'Xoá ngày này'"
                    :aria-label="'Xoá ngày ' + row.logged_on_label" :disabled="store.productionSaving" @click="remove(row)">
              <StudioIcon name="trash" size="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      </div>

      <p v-else class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
        Chưa ghi ngày sản lượng nào. Ghi số cái xưởng làm xong mỗi ngày — từ đó hệ thống tính tiến độ, nhịp,
        ngày dự kiến xong và cảnh báo chậm.
      </p>

      <p class="text-label leading-4 text-cream-400">
        Tiến độ · nhịp · ngày dự kiến xong · số ngày chậm đều do MÁY TÍNH từ (kế hoạch trong bộ + sản lượng bạn
        ghi + hôm nay); không có con số nào lưu sẵn nên sửa hay xoá một ngày là mọi số tính lại. Tỉ lệ lỗi ở đây
        là <b class="text-cream-100">số thật trên sản lượng</b>, đối chiếu được với “tỉ lệ lỗi” bạn tự đoán trong kế hoạch.
        <span v-if="plan.assumed_defect_pct">Kế hoạch đang giả định {{ plan.assumed_defect_pct }}%<span v-if="actual.defect_pct"> — thực tế {{ actual.defect_pct }}%</span>.</span>
      </p>
    </div>
  </section>
</template>
