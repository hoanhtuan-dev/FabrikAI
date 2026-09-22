<script setup>
/**
 * KIỂM TRA CHẤT LƯỢNG (QC) — Việc #6, 2026-09-26.
 *
 * VÌ SAO CÓ MÀN HÌNH NÀY: chuỗi của một bộ sưu tập đang dừng ở chỗ GỬI xưởng (gói ZIP + phiếu kỹ thuật).
 * Hàng về thì không có chỗ nào ghi lại lô đó có bao nhiêu lỗi — trong khi tầng giá thành vẫn đang nhân
 * với một tỉ lệ lỗi do người dùng TỰ ĐOÁN (@@defect_pct@@). Đây là chỗ duy nhất ghi lỗi THẬT.
 *
 * NĂM QUYẾT ĐỊNH GIAO DIỆN:
 *   1. KẾ HOẠCH LẤY MẪU HIỆN NGAY KHI MỞ BIÊN BẢN (cỡ mẫu · Ac · Re), vì người đi kiểm phải biết cầm
 *      bao nhiêu cái và bao nhiêu lỗi thì lô bị loại — TRƯỚC khi xuống xưởng, không phải sau.
 *   2. CÂU CỦA BẢNG ĐƯỢC TRÍCH NGUYÊN VĂN khi kế hoạch đi theo mũi tên hoặc rơi ra ngoài bảng tham chiếu.
 *      Giấu chuyện đó đi là để người dùng tin một con số chưa đối chiếu tiêu chuẩn.
 *   3. KHÔNG CÓ NÚT "ĐẠT/KHÔNG ĐẠT". Kết luận do máy chủ tính từ số lỗi so với Ac; giao diện chỉ hiện
 *      phép tính. Cho người dùng bấm kết luận là biến nghiệm thu thành ý kiến.
 *   4. CHƯA GHI NGÀY KIỂM THÌ LÀ "CHƯA KẾT LUẬN" — không mặc định là đạt (mặc định-đạt là tự ký hộ khách).
 *   5. Ba mức lỗi ghi riêng (nghiêm trọng · nặng · nhẹ). Lỗi nhẹ KHÔNG quyết định kết luận — gộp ba mức
 *      vào một số rồi so với Ac của một mức là kiểu sai làm lô đạt bị loại oan.
 */
import { computed, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';
import ConfirmDialog from './ConfirmDialog.vue';

const props = defineProps({
  projectId: { type: Number, required: true },
});

const store = useStudioStore();

onMounted(() => { store.loadQc(props.projectId); });

const EMPTY = { items: [], counts: {}, defects: {}, summary: {}, shape: {} };
const data = computed(() => store.qc || EMPTY);
const items = computed(() => data.value.items || []);
const summary = computed(() => data.value.summary || {});
const counts = computed(() => data.value.counts || {});
const shape = computed(() => data.value.shape || { stages: [], aql_levels: [], limits: {} });

const form = ref({ lot_size: '', aql: '2.5', stage: 'final', style_no: '', notes: '' });
const formError = ref('');
/** Biên bản đang mở để ghi kết quả — mở một cái một lúc cho bảng khỏi thành một rừng ô nhập. */
const openId = ref(null);
const pendingDelete = ref(null);

function todayIso() {
  return new Date().toISOString().slice(0, 10);
}

function planLine(row) {
  const plan = row.plan || {};
  const parts = [];
  if (plan.code) parts.push('mã ' + plan.code);
  parts.push('kiểm ' + row.sample_size_actual + ' cái');
  if (row.ac !== null && row.ac !== undefined) parts.push('Ac ' + row.ac + ' / Re ' + row.re);
  return 'Lô ' + row.lot_size + ' cái · ' + parts.join(' · ');
}

/** Câu giải thích kết luận — chép đúng phép tính máy chủ đã dùng, không phải một nhãn suông. */
function verdictReason(row) {
  if (row.result === 'pending') {
    return row.inspected_at ? 'Chưa đủ dữ liệu để kết luận.' : 'Chưa ghi ngày kiểm nên chưa kết luận — máy không mặc định là đạt.';
  }
  if (row.critical > 0) return row.critical + ' lỗi NGHIÊM TRỌNG — mức này không có số chấp nhận.';
  if (row.result === 'pass') return row.major + ' lỗi nặng ≤ Ac ' + row.ac + '.';
  return row.major + ' lỗi nặng > Ac ' + row.ac + ' (Re ' + row.re + ').';
}

function resultClass(result) {
  if (result === 'pass') return 'bg-ok/15 text-ok';
  if (result === 'fail') return 'bg-danger/15 text-danger';
  return 'bg-ink-700 text-cream-300';
}

async function openInspection() {
  formError.value = '';
  const lot = Number(form.value.lot_size);
  if (!Number.isInteger(lot) || lot < 1) {
    formError.value = 'Ghi CỠ LÔ (số cái của lô này) — cỡ lô mới quyết định phải kiểm bao nhiêu cái.';
    return;
  }

  const data = await store.createQcInspection(props.projectId, {
    lot_size: lot,
    aql: form.value.aql,
    stage: form.value.stage,
    style_no: form.value.style_no,
    notes: form.value.notes,
  });

  if (data) {
    openId.value = data.created ? data.created.id : null;
    form.value = { lot_size: '', aql: form.value.aql, stage: form.value.stage, style_no: '', notes: '' };
  }
}

/** Ghi một phần của biên bản; máy chủ trả cả bảng mới nên số tổng luôn khớp máy chủ. */
async function save(row, patch) {
  await store.updateQcInspection(props.projectId, row.id, patch);
}

function saveCount(row, field, value) {
  const n = Math.max(0, Math.floor(Number(value) || 0));
  if (n === row[field]) return;
  save(row, { [field]: n });
}

function saveDate(row, value) {
  const next = String(value || '');
  const current = row.inspected_at ? String(row.inspected_at).slice(0, 10) : '';
  if (next === current) return;
  save(row, { inspected_at: next });
}

/** Đổi kết quả MỘT mục kiểm rồi gửi cả danh sách — máy chủ chuẩn hoá lại (bỏ mục rỗng, cắt theo trần). */
function setItemResult(row, index, result) {
  const list = (row.checklist || []).map((item, i) => (i === index ? { ...item, result: item.result === result ? null : result } : item));
  save(row, { checklist: list });
}

function addItem(row, label) {
  const text = String(label || '').trim();
  if (!text) return;
  const list = (row.checklist || []).concat([{ label: text, result: null }]);
  save(row, { checklist: list });
}

function confirmDelete() {
  const row = pendingDelete.value;
  pendingDelete.value = null;
  if (row) store.deleteQcInspection(props.projectId, row.id);
}

const newItemLabel = ref({});

function onAddItem(row) {
  addItem(row, newItemLabel.value[row.id]);
  newItemLabel.value = { ...newItemLabel.value, [row.id]: '' };
}
</script>

<template>
  <section aria-label="Kiểm tra chất lượng" :aria-busy="store.qcLoading">
    <div v-if="store.qcLoading" class="p-5 text-body text-cream-400">Đang tải biên bản kiểm tra…</div>

    <p v-else-if="store.qcError" role="alert" class="m-4 rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-body text-danger">
      {{ store.qcError }}
    </p>

    <div v-else class="space-y-4 p-4 sm:p-5">
      <!-- TÌNH TRẠNG -->
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label font-semibold text-cream-200">{{ summary.total || 0 }} biên bản</span>
        <span class="rounded-full bg-ok/15 px-2.5 py-0.5 text-label text-ok">{{ counts.pass || 0 }} đạt</span>
        <span class="rounded-full bg-danger/15 px-2.5 py-0.5 text-label text-danger">{{ counts.fail || 0 }} không đạt</span>
        <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-300">{{ counts.pending || 0 }} chưa kết luận</span>
        <span v-if="summary.defect_rate_pct !== null && summary.defect_rate_pct !== undefined"
              class="rounded-full bg-brand-600/20 px-2.5 py-0.5 text-label font-semibold text-brand-200"
              :title="'Lỗi trên số cái ĐÃ KIỂM: ' + (summary.defect_rate_basis?.defects || 0) + ' lỗi / ' + (summary.defect_rate_basis?.units || 0) + ' cái đã kiểm'">
          tỉ lệ lỗi {{ summary.defect_rate_pct }}%
          <span class="font-normal">({{ summary.defect_rate_basis?.defects || 0 }}/{{ summary.defect_rate_basis?.units || 0 }})</span>
        </span>
      </div>

      <p v-if="!items.length" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
        Chưa có biên bản nào. Khi hàng về, mở một biên bản ở đây: ghi cỡ lô, hệ thống chốt luôn số cái phải kiểm
        và số lỗi được phép (Ac/Re) theo mức AQL bạn chọn.
      </p>

      <!-- BẢNG BIÊN BẢN -->
      <div v-else class="space-y-2">
        <div v-for="row in items" :key="row.id" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="truncate text-body font-semibold text-cream-100">
                {{ row.style_no || ('Biên bản #' + row.id) }}
                <span class="font-normal text-cream-400">· {{ row.stage_label }}</span>
              </p>
              <p class="mt-0.5 text-label text-cream-400">{{ planLine(row) }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
              <span class="rounded-full px-2 py-0.5 text-tiny font-semibold" :class="resultClass(row.result)">{{ row.result_label }}</span>
              <button type="button" class="tool-btn btn-sm" :disabled="store.qcSaving" @click="openId = openId === row.id ? null : row.id">
                <StudioIcon :name="openId === row.id ? 'check' : 'pencil'" size="h-3.5 w-3.5" /> {{ openId === row.id ? 'Xong' : 'Ghi kết quả' }}
              </button>
              <button type="button" class="icon-btn h-7 w-7" title="Xoá biên bản" aria-label="Xoá biên bản" :disabled="store.qcSaving" @click="pendingDelete = row">
                <StudioIcon name="trash" size="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <!-- CÂU CỦA BẢNG THAM CHIẾU: mũi tên / ngoài phạm vi / kiểm 100% -->
          <p v-if="row.plan && row.plan.note" class="mt-1.5 rounded border border-warn/40 bg-warn/10 px-2 py-1 text-label leading-5 text-warn">
            {{ row.plan.note }}
          </p>

          <p class="mt-1.5 text-label leading-5" :class="row.result === 'fail' ? 'text-danger' : 'text-cream-400'">
            {{ verdictReason(row) }}
          </p>

          <!-- GHI KẾT QUẢ -->
          <div v-if="openId === row.id" class="mt-2 space-y-2 border-t border-ink-700 pt-2">
            <div class="flex flex-wrap items-end gap-3">
              <label class="text-label text-cream-300">
                Lỗi nghiêm trọng
                <input type="number" min="0" class="input mt-0.5 w-20 !py-1" :value="row.critical" @change="saveCount(row, 'critical', $event.target.value)">
              </label>
              <label class="text-label text-cream-300">
                Lỗi nặng
                <input type="number" min="0" class="input mt-0.5 w-20 !py-1" :value="row.major" @change="saveCount(row, 'major', $event.target.value)">
              </label>
              <label class="text-label text-cream-300">
                Lỗi nhẹ
                <input type="number" min="0" class="input mt-0.5 w-20 !py-1" :value="row.minor" @change="saveCount(row, 'minor', $event.target.value)">
              </label>
              <label class="text-label text-cream-300">
                Ngày kiểm
                <input type="date" class="input mt-0.5 !py-1" :value="row.inspected_at ? String(row.inspected_at).slice(0, 10) : ''" @change="saveDate(row, $event.target.value)">
              </label>
              <button v-if="!row.inspected_at" type="button" class="tool-btn btn-sm" :disabled="store.qcSaving" @click="save(row, { inspected_at: todayIso() })">
                <StudioIcon name="check" size="h-3.5 w-3.5" /> Đã kiểm hôm nay
              </button>
            </div>

            <div>
              <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Điểm kiểm</p>
              <ul class="mt-1 space-y-1">
                <li v-for="(item, index) in row.checklist" :key="item.key || index" class="flex items-center gap-2">
                  <span class="min-w-0 flex-1 truncate text-label" :class="item.result === 'fail' ? 'text-danger' : 'text-cream-200'">{{ item.label }}</span>
                  <button type="button" class="icon-btn h-6 w-6" :class="item.result === 'pass' ? '!text-ok' : 'opacity-60'" title="Đạt" :disabled="store.qcSaving" @click="setItemResult(row, index, 'pass')">
                    <StudioIcon name="check" size="h-3.5 w-3.5" />
                  </button>
                  <button type="button" class="icon-btn h-6 w-6" :class="item.result === 'fail' ? '!text-danger' : 'opacity-60'" title="Không đạt" :disabled="store.qcSaving" @click="setItemResult(row, index, 'fail')">
                    <StudioIcon name="x" size="h-3.5 w-3.5" />
                  </button>
                  <button type="button" class="icon-btn h-6 w-6" :class="item.result === 'na' ? '!text-cream-200' : 'opacity-60'" title="Không áp dụng" :disabled="store.qcSaving" @click="setItemResult(row, index, 'na')">
                    <span class="text-tiny font-semibold">—</span>
                  </button>
                </li>
              </ul>
              <div class="mt-1.5 flex items-center gap-2">
                <input v-model="newItemLabel[row.id]" class="input !py-1 text-label" maxlength="120" placeholder="Thêm điểm kiểm riêng — VD: túi hai bên đối xứng"
                       @keyup.enter="onAddItem(row)">
                <button type="button" class="tool-btn btn-sm" :disabled="store.qcSaving || !newItemLabel[row.id]" @click="onAddItem(row)">
                  <StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm
                </button>
              </div>
            </div>

            <label class="block text-label text-cream-300">
              Ghi chú của người kiểm
              <textarea class="input mt-0.5 w-full !py-1 text-label" rows="2" maxlength="400" :value="row.notes"
                        placeholder="VD: lô 2 tay áo lệch 0,5cm — đã yêu cầu xưởng sửa ở lô sau"
                        @change="save(row, { notes: $event.target.value })"></textarea>
            </label>
          </div>

          <p v-else-if="row.checklist && row.checklist.length" class="mt-1 text-label text-cream-400">
            {{ row.checklist.length }} điểm kiểm · {{ row.checklist.filter(i => i.result === 'fail').length }} mục không đạt
          </p>
        </div>
      </div>

      <!-- MỞ BIÊN BẢN -->
      <div class="rounded-lg border border-ink-700 bg-ink-900/40 p-3">
        <p class="text-label font-semibold uppercase tracking-wide text-cream-300">Mở biên bản kiểm cho một lô</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
          <label class="text-label text-cream-300">
            Cỡ lô (số cái) <span class="text-danger">*</span>
            <input v-model="form.lot_size" type="number" min="1" class="input mt-0.5 w-full !py-1" placeholder="VD: 1200">
          </label>
          <label class="text-label text-cream-300">
            Mức AQL
            <select v-model="form.aql" class="input mt-0.5 w-full !py-1">
              <option v-for="aql in shape.aql_levels" :key="aql" :value="aql">AQL {{ aql }}{{ aql === '2.5' ? ' (mặc định ngành)' : '' }}</option>
            </select>
          </label>
          <label class="text-label text-cream-300">
            Điểm kiểm
            <select v-model="form.stage" class="input mt-0.5 w-full !py-1">
              <option v-for="stage in shape.stages" :key="stage.id" :value="stage.id">{{ stage.label }}</option>
            </select>
          </label>
          <label class="text-label text-cream-300">
            Mã hàng
            <input v-model="form.style_no" class="input mt-0.5 w-full !py-1" maxlength="40" placeholder="VD: FD-2610">
          </label>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <button type="button" class="btn-brand btn-sm" :disabled="store.qcSaving" @click="openInspection">
            <StudioIcon name="shieldCheck" size="h-3.5 w-3.5" /> {{ store.qcSaving ? 'Đang mở…' : 'Mở biên bản' }}
          </button>
          <span v-if="formError" class="text-label text-warn">↳ {{ formError }}</span>
        </div>
      </div>

      <p class="text-label leading-4 text-cream-400">
        Kế hoạch lấy mẫu được CHỐT vào biên bản lúc mở (đổi cỡ lô thì chốt lại) nên sửa bảng tham chiếu không
        làm đổi biên bản cũ. Kết luận đạt/không đạt do máy tính từ số lỗi so với Ac — lỗi nghiêm trọng không có
        mức chấp nhận, lỗi nhẹ chỉ để theo dõi. Cỡ lô và mức AQL là SỐ BẠN KHAI; bảng tra là tham chiếu, đối
        chiếu với tiêu chuẩn hai bên thoả thuận trước khi dùng cho hợp đồng.
      </p>
    </div>

    <ConfirmDialog
      :open="!!pendingDelete"
      title="Xoá biên bản kiểm tra?"
      confirm-label="Xoá biên bản"
      @confirm="confirmDelete"
      @cancel="pendingDelete = null"
    >
      Biên bản <b>{{ pendingDelete?.style_no || ('#' + pendingDelete?.id) }}</b> và toàn bộ điểm kiểm, số lỗi đã ghi sẽ bị xoá.
      Số liệu tỉ lệ lỗi của bộ sưu tập sẽ tính lại mà không có biên bản này.
    </ConfirmDialog>
  </section>
</template>
