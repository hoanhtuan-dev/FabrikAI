<script setup>
/**
 * PHIẾU KỸ THUẬT TƯƠNG TÁC (tech pack) — Việc #3, 2026-09-26.
 *
 * VÌ SAO CÓ MÀN HÌNH NÀY: gói xuất cho xưởng đã có từ lâu, nhưng "phiếu kỹ thuật" trong đó chỉ là tệp
 * chữ với các dòng chấm để xưởng tự điền. Chủ shop không có chỗ nào GHI thông số trong ứng dụng ⇒ mỗi
 * lần xuất là một tờ giấy trắng mới. Đây là mắt xích mất giữa "ảnh AI" và "lệnh cho xưởng".
 *
 * BA QUYẾT ĐỊNH GIAO DIỆN:
 *   1. Ô nào chưa điền thì hiện GỢI Ý (placeholder), KHÔNG điền sẵn giá trị — hệ thống không bịa thông số.
 *   2. Thanh "đã đủ để gửi xưởng chưa" CHỈ CẢNH BÁO, không khoá nút Lưu: thiếu ô không phải lỗi, và bắt
 *      điền hết mới cho lưu là biến công cụ thành cửa chặn.
 *   3. Dòng có SỐ ĐO mà chưa có ĐIỂM ĐO thì KHOÁ nút Lưu kèm lý do — vì máy chủ bỏ những dòng đó
 *      (không có tên điểm đo thì không ai đọc được), và bỏ im lặng là ghi đè công sức người ta gõ.
 */
import { computed, onMounted } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const props = defineProps({
  projectId: { type: Number, required: true },
});

const store = useStudioStore();

onMounted(() => { store.loadTechPack(props.projectId); });

const EMPTY = { fields: {}, sizes: [], measurements: [] };

const shape = computed(() => store.techPack?.shape || { fields: [], limits: {} });
const pack = computed(() => store.techPackDraft || EMPTY);
const completeness = computed(() => store.techPack?.completeness || { filled: 0, total: 0, missing: [], ready: false });
const dirty = computed(() => store.techPackDirty());
const limits = computed(() => shape.value.limits || {});
const maxSizes = computed(() => Number(limits.value.max_sizes || 8));
const pointMax = computed(() => Number(limits.value.point_max || 60));
const toleranceMax = computed(() => Number(limits.value.tolerance_max || 20));
const valueMax = computed(() => Number(limits.value.value_max || 16));
const maxRows = computed(() => Number(limits.value.max_measurements || 30));

function patch(next) { store.techPackDraft = { ...pack.value, ...next }; }
function setField(key, value) { patch({ fields: { ...(pack.value.fields || {}), [key]: value } }); }

/** Size nhập một ô bằng dấu phẩy (gọn cho điện thoại) — máy chủ cắt trần và khử trùng lần nữa. */
const sizesText = computed(() => (pack.value.sizes || []).join(', '));
function onSizesInput(value) {
  const list = String(value)
    .split(',')
    .map((s) => s.trim().toUpperCase())
    .filter((s) => s !== '')
    .filter((s, i, all) => all.indexOf(s) === i)
    .slice(0, maxSizes.value);
  patch({ sizes: list });
}

function rows() { return pack.value.measurements || []; }
function setRow(index, key, value) {
  patch({ measurements: rows().map((row, i) => (i === index ? { ...row, [key]: value } : row)) });
}
function setCell(index, size, value) {
  patch({
    measurements: rows().map((row, i) => (i === index ? { ...row, values: { ...(row.values || {}), [size]: value } } : row)),
  });
}
function cellValue(row, size) { return (row.values || {})[size] || ''; }
function addRow() {
  if (rows().length >= maxRows.value) return;
  const values = {};
  (pack.value.sizes || []).forEach((s) => { values[s] = ''; });
  patch({ measurements: [...rows(), { point: '', tolerance: '', values }] });
}
function removeRow(index) {
  patch({ measurements: rows().filter((_, i) => i !== index) });
}

/** Dòng có số đo nhưng chưa có điểm đo — máy chủ sẽ BỎ, nên phải nói ra TRƯỚC khi bấm Lưu. */
const rowsWithoutPoint = computed(() => rows().filter((row) => {
  const named = String(row.point || '').trim() !== '';
  const hasValue = Object.values(row.values || {}).some((v) => String(v || '').trim() !== '');
  return !named && hasValue;
}).length);

/**
 * Vì sao nút Lưu đang bị khoá — MỘT nguồn cho cả điều kiện khoá lẫn câu giải thích
 * (docs/DESIGN_SYSTEM.md §4 quy tắc 4). Cờ đang-lưu không tính là lý do (nhãn nút đã đổi).
 */
const blockReason = computed(() => {
  if (store.techPackSaving) return '';
  if (rowsWithoutPoint.value > 0) {
    return 'Còn ' + rowsWithoutPoint.value + ' dòng có số đo nhưng chưa ghi điểm đo — đặt tên điểm đo hoặc xoá dòng đó.';
  }
  if (!dirty.value) return 'Chưa có thay đổi nào để lưu.';
  return '';
});
</script>

<template>
  <section aria-label="Phiếu kỹ thuật" :aria-busy="store.techPackLoading">
    <div v-if="store.techPackLoading" class="p-5 text-body text-cream-400">Đang tải phiếu kỹ thuật…</div>

    <p v-else-if="store.techPackError" role="alert" class="m-4 rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-body text-danger">
      {{ store.techPackError }}
    </p>

    <div v-else class="space-y-4 p-4 sm:p-5">
      <!-- TÌNH TRẠNG: nói thật còn thiếu gì, nhưng KHÔNG chặn lưu -->
      <div class="rounded-lg border px-3 py-2 text-label leading-5"
           :class="completeness.ready ? 'border-ok/40 bg-ok/10 text-ok' : 'border-warn/40 bg-warn/10 text-warn'">
        <b>{{ completeness.ready ? 'Phiếu đã đủ để gửi xưởng.' : 'Phiếu còn thiếu ' + (completeness.total - completeness.filled) + '/' + completeness.total + ' mục.' }}</b>
        <span v-if="!completeness.ready" class="text-cream-300"> Thiếu: {{ (completeness.missing || []).join(' · ') }}</span>
        <span v-if="completeness.ready" class="text-cream-300"> Gói xuất sẽ dùng đúng các thông số dưới đây.</span>
      </div>

      <!-- THÔNG SỐ -->
      <div class="grid gap-3 sm:grid-cols-2">
        <div v-for="field in shape.fields" :key="field.key" :class="field.key === 'construction' || field.key === 'notes' || field.key === 'qc_notes' ? 'sm:col-span-2' : ''">
          <label class="label" :for="'tp-' + field.key">
            {{ field.label }}
            <span v-if="(shape.core_fields || []).includes(field.key)" class="text-brand-300" title="Trường cốt lõi — thiếu là phiếu chưa gửi xưởng được">•</span>
          </label>
          <textarea v-if="field.max > 200" :id="'tp-' + field.key" class="input w-full" rows="2" :maxlength="field.max"
                    :value="pack.fields[field.key] || ''" :placeholder="field.hint" @input="setField(field.key, $event.target.value)"></textarea>
          <input v-else :id="'tp-' + field.key" class="input w-full" :maxlength="field.max"
                 :value="pack.fields[field.key] || ''" :placeholder="field.hint" @input="setField(field.key, $event.target.value)">
        </div>
      </div>

      <!-- SIZE -->
      <div>
        <label class="label" for="tp-sizes">Size có trong bộ (cách nhau bằng dấu phẩy, tối đa {{ maxSizes }})</label>
        <input id="tp-sizes" class="input w-full sm:max-w-md" :value="sizesText" placeholder="S, M, L, XL" @input="onSizesInput($event.target.value)">
        <p class="mt-1 text-label leading-4 text-cream-400">Size ở đây là CỘT của bảng thông số bên dưới. Sửa ô này thì các cột cũng đổi theo.</p>
      </div>

      <!-- BẢNG THÔNG SỐ -->
      <div>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-title font-semibold text-cream-100">Bảng thông số (cm)</span>
          <button type="button" class="btn-ghost btn-sm" :disabled="rows().length >= maxRows" @click="addRow">
            <StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm điểm đo
          </button>
        </div>

        <p v-if="!(pack.sizes || []).length" class="mt-2 rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label text-cream-300">
          Chưa khai size — điền ô “Size có trong bộ” ở trên trước, rồi bảng này sẽ có cột để nhập.
        </p>

        <div v-else-if="!rows().length" class="mt-2 rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label text-cream-300">
          Chưa có điểm đo nào. Bấm “Thêm điểm đo” để bắt đầu bảng (VD: Ngực · Dài áo · Ngang vai).
        </div>

        <div v-else class="mt-2 overflow-x-auto">
          <table class="w-full min-w-[640px] border-collapse text-label">
            <thead>
              <tr class="text-cream-300">
                <th class="border-b border-ink-700 px-1.5 py-1.5 text-left font-semibold">Điểm đo</th>
                <th class="border-b border-ink-700 px-1.5 py-1.5 text-left font-semibold">Dung sai</th>
                <th v-for="size in pack.sizes" :key="size" class="border-b border-ink-700 px-1.5 py-1.5 text-left font-semibold">{{ size }}</th>
                <th class="border-b border-ink-700 px-1.5 py-1.5"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in rows()" :key="index">
                <td class="px-1.5 py-1">
                  <input class="input !py-1 w-full min-w-[9rem]" :maxlength="pointMax" :value="row.point || ''" placeholder="Ngực" @input="setRow(index, 'point', $event.target.value)">
                </td>
                <td class="px-1.5 py-1">
                  <input class="input !py-1 w-20" :maxlength="toleranceMax" :value="row.tolerance || ''" placeholder="±1" @input="setRow(index, 'tolerance', $event.target.value)">
                </td>
                <td v-for="size in pack.sizes" :key="size" class="px-1.5 py-1">
                  <input class="input !py-1 w-20" :maxlength="valueMax" :value="cellValue(row, size)" placeholder="—" @input="setCell(index, size, $event.target.value)">
                </td>
                <td class="px-1.5 py-1 text-right">
                  <button type="button" class="icon-btn h-7 w-7" title="Xoá điểm đo này" aria-label="Xoá điểm đo" @click="removeRow(index)">
                    <StudioIcon name="trash" size="h-3.5 w-3.5" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="rowsWithoutPoint > 0" class="mt-2 rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-label leading-5 text-warn">
          Còn {{ rowsWithoutPoint }} dòng có số đo nhưng chưa ghi điểm đo — máy chủ không lưu được dòng đó, nên nút Lưu đang khoá.
        </p>
      </div>

      <!-- HÀNH ĐỘNG -->
      <div class="flex flex-wrap items-center gap-2 border-t border-ink-700 pt-3">
        <button type="button" class="btn-brand btn-sm" :disabled="store.techPackSaving || !!blockReason" @click="store.saveTechPack()">
          <StudioIcon name="save" size="h-3.5 w-3.5" /> {{ store.techPackSaving ? 'Đang lưu…' : 'Lưu phiếu' }}
        </button>
        <span v-if="blockReason" class="text-label text-cream-400">↳ {{ blockReason }}</span>
        <button type="button" class="btn-ghost btn-sm" :disabled="store.techPackSaving || !dirty" @click="store.discardTechPackDraft()">Bỏ thay đổi</button>
        <button type="button" class="btn-ghost btn-sm" :disabled="store.techPackSaving || !store.techPack?.is_set" @click="store.resetTechPack()">Xoá phiếu</button>
        <a class="btn-ghost btn-sm ml-auto" :href="'/du-an/' + projectId + '/phieu-ky-thuat'" target="_blank" rel="noopener" title="Mở bản in A4 — trong hộp thoại in chọn “Lưu thành PDF”">
          <StudioIcon name="download" size="h-3.5 w-3.5" /> In / Lưu PDF
        </a>
      </div>
      <p class="text-label leading-4 text-cream-400">
        Phiếu này đi thẳng vào gói ZIP xuất cho xưởng (tệp <span class="font-mono">phieu-ky-thuat.txt</span> và
        <span class="font-mono">bang-thong-so.csv</span>) — chưa lập phiếu thì gói vẫn xuất được, chỉ là xưởng
        nhận mẫu trắng để điền tay.
      </p>
    </div>
  </section>
</template>
