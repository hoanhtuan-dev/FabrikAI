<script setup>
/**
 * THEO DÕI MẪU VẬT LÝ (fit · PP · TOP) — Việc #4, 2026-09-26.
 *
 * VÌ SAO CÓ MÀN HÌNH NÀY: vòng đời ẢNH đã có (Duyệt mẫu theo lô), nhưng mẫu THẬT mà xưởng may ra thì
 * chưa có chỗ nào — nên việc theo dõi mẫu và mọi cảnh báo trễ hạn nằm ngoài ứng dụng. Sau khi chốt ảnh,
 * chủ shop phải tự nhớ mẫu nào đang ở đâu, mẫu nào trễ.
 *
 * BA QUYẾT ĐỊNH GIAO DIỆN:
 *   1. CẢNH BÁO HIỆN NGAY TRONG BẢNG (tính từ dữ liệu ở máy chủ) — không phụ thuộc việc có cron hay
 *      không, và tự đúng lại mỗi ngày.
 *   2. CHỈ MỘT NÚT TIẾN cho mỗi hàng ("bước kế" do máy chủ gợi ý) + một nút "không đạt": mười nút cho
 *      mười trạng thái là bắt người dùng học máy trạng thái.
 *   3. NHẢY CÓC KHÔNG CÓ NÚT NÀO: máy chủ từ chối và trả lý do; giao diện chỉ mời bước hợp lệ.
 */
import { computed, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const props = defineProps({
  projectId: { type: Number, required: true },
});

const store = useStudioStore();

onMounted(() => { store.loadSamples(props.projectId); });

const data = computed(() => store.samples || { items: [], counts: {}, alerts: {}, summary: {}, shape: {} });
const items = computed(() => data.value.items || []);
const alerts = computed(() => data.value.alerts || { overdue: 0, due_soon: 0 });
const summary = computed(() => data.value.summary || {});

const form = ref({ style_no: '', name: '', factory: '', due_at: '' });
const formError = ref('');

function dueLabel(row) {
  if (!row.due_at_label) return 'chưa khai hạn';
  const days = row.days_left;
  if (days === null || days === undefined) return row.due_at_label;
  if (days < 0) return row.due_at_label + ' · quá ' + Math.abs(days) + ' ngày';
  if (days === 0) return row.due_at_label + ' · hôm nay';
  return row.due_at_label + ' · còn ' + days + ' ngày';
}

function alertClass(row) {
  if (row.alert === 'overdue') return 'border-danger/50 bg-danger/10 text-danger';
  if (row.alert === 'due_soon') return 'border-warn/50 bg-warn/10 text-warn';
  return 'border-ink-600 text-cream-300';
}

async function add() {
  formError.value = '';
  if (!String(form.value.style_no).trim() && !String(form.value.name).trim()) {
    formError.value = 'Ghi ít nhất MÃ HÀNG hoặc TÊN MẪU — để bảng còn biết dòng này là mẫu nào.';
    return;
  }

  const ok = await store.createSample(props.projectId, {
    style_no: form.value.style_no,
    name: form.value.name,
    factory: form.value.factory,
    due_at: form.value.due_at,
  });

  if (ok) form.value = { style_no: '', name: '', factory: '', due_at: '' };
}

/** Đổi hạn chót tại chỗ — đường riêng, KHÔNG đụng trạng thái. */
async function saveDue(row, value) {
  if (String(value) === String(row.due_at || '')) return;
  await store.updateSample(props.projectId, row.id, { due_at: value });
}

async function advance(row) {
  if (!row.next_stage) return;
  await store.transitionSample(props.projectId, row.id, row.next_stage);
}

async function reject(row) {
  await store.transitionSample(props.projectId, row.id, 'rejected');
}

async function remove(row) {
  await store.deleteSample(props.projectId, row.id);
}
</script>

<template>
  <section aria-label="Mẫu vật lý" :aria-busy="store.samplesLoading">
    <div v-if="store.samplesLoading" class="p-5 text-body text-cream-400">Đang tải bảng theo dõi mẫu…</div>

    <p v-else-if="store.samplesError" role="alert" class="m-4 rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-body text-danger">
      {{ store.samplesError }}
    </p>

    <div v-else class="space-y-4 p-4 sm:p-5">
      <!-- TÌNH TRẠNG -->
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label font-semibold text-cream-200">{{ summary.total || 0 }} mẫu</span>
        <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-300">{{ summary.open || 0 }} đang làm</span>
        <span class="rounded-full bg-ok/15 px-2.5 py-0.5 text-label text-ok">{{ summary.approved || 0 }} đạt</span>
        <span v-if="alerts.overdue" class="flex items-center gap-1 rounded-full bg-danger/15 px-2.5 py-0.5 text-label font-semibold text-danger">
          <StudioIcon name="alertTriangle" size="h-3.5 w-3.5" /> {{ alerts.overdue }} quá hạn
        </span>
        <span v-if="alerts.due_soon" class="flex items-center gap-1 rounded-full bg-warn/15 px-2.5 py-0.5 text-label font-semibold text-warn">
          <StudioIcon name="clock" size="h-3.5 w-3.5" /> {{ alerts.due_soon }} sắp tới hạn
        </span>
      </div>

      <p v-if="!items.length" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
        Chưa có mẫu nào. Sau khi duyệt ảnh xong, ghi mẫu cần xưởng làm ở đây để theo dõi FIT · PP · TOP và hạn chót.
      </p>

      <!-- BẢNG -->
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-body font-semibold text-cream-100">
                {{ row.style_no || ('Mẫu #' + row.id) }}
                <span class="font-normal text-cream-400">{{ row.name ? '· ' + row.name : '' }}</span>
              </p>
              <p class="mt-0.5 text-label text-cream-400">
                Vòng {{ row.round }}<span v-if="row.factory"> · {{ row.factory }}</span>
              </p>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
              <span class="rounded-full px-2 py-0.5 text-tiny font-semibold"
                    :class="row.stage === 'approved' ? 'bg-ok/15 text-ok' : (row.stage === 'rejected' ? 'bg-danger/15 text-danger' : 'bg-brand-600/20 text-brand-200')">
                {{ row.stage_label }}
              </span>
            </div>
          </div>

          <div class="mt-2 flex flex-wrap items-center gap-2">
            <span class="flex items-center gap-1 rounded-full border px-2 py-0.5 text-tiny" :class="alertClass(row)">
              <StudioIcon name="calendar" size="h-3 w-3" /> {{ dueLabel(row) }}
            </span>
            <label class="flex items-center gap-1 text-tiny text-cream-400">
              Hạn
              <input type="date" class="input !py-0.5 !px-1.5 text-tiny" :value="row.due_at || ''" @change="saveDue(row, $event.target.value)">
            </label>
          </div>

          <p v-if="row.note" class="mt-1.5 text-label leading-5 text-cream-300">{{ row.note }}</p>

          <div class="mt-2 flex flex-wrap items-center gap-1.5">
            <button v-if="row.next_stage" type="button" class="btn-brand btn-sm" :disabled="store.samplesSaving" @click="advance(row)">
              <StudioIcon name="arrowRight" size="h-3.5 w-3.5" /> {{ row.next_stage_label }}
            </button>
            <span v-else class="text-label text-cream-400">↳ Đã ở bước cuối.</span>

            <button v-if="row.stage !== 'approved'" type="button" class="tool-btn btn-sm !text-danger hover:!bg-danger/15" :disabled="store.samplesSaving" @click="reject(row)">
              <StudioIcon name="x" size="h-3.5 w-3.5" /> Không đạt
            </button>
            <button type="button" class="icon-btn ml-auto h-7 w-7" title="Xoá mẫu khỏi bảng" aria-label="Xoá mẫu" :disabled="store.samplesSaving" @click="remove(row)">
              <StudioIcon name="trash" size="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      </div>

      <!-- THÊM MẪU -->
      <div class="rounded-lg border border-ink-700 bg-ink-900/40 p-3">
        <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Thêm mẫu cần theo dõi</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
          <input v-model="form.style_no" class="input w-full" maxlength="40" placeholder="Mã hàng — VD: FD-2610">
          <input v-model="form.name" class="input w-full" maxlength="120" placeholder="Tên mẫu — VD: Đầm linen cổ V">
          <input v-model="form.factory" class="input w-full" maxlength="120" placeholder="Xưởng — VD: Xưởng Bình Tân">
          <label class="flex items-center gap-2 text-label text-cream-300">
            Hạn chót
            <input v-model="form.due_at" type="date" class="input w-full !py-1.5">
          </label>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <button type="button" class="btn-brand btn-sm" :disabled="store.samplesSaving" @click="add">
            <StudioIcon name="plus" size="h-3.5 w-3.5" /> {{ store.samplesSaving ? 'Đang lưu…' : 'Thêm mẫu' }}
          </button>
          <span v-if="formError" class="text-label text-warn">↳ {{ formError }}</span>
        </div>
      </div>

      <p class="text-label leading-4 text-cream-400">
        Mẫu mới luôn bắt đầu ở “Đã yêu cầu xưởng”. Máy chủ chỉ cho đi theo bước hợp lệ nên không nhảy cóc được;
        hạn chót chỉ để NHẮC — nó không tự đổi trạng thái.
      </p>
    </div>
  </section>
</template>
