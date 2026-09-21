<script setup>
/**
 * BƯỚC CON 3.3 — BẢNG SIZE DỰ KIẾN ĐẦY ĐỦ (2026-09-25).
 *
 * Trước đây chỉ có BA preset cứng (Chuẩn S–XL · Nữ ưu tiên S–M · Unisex) và không thêm/bớt được size nào.
 * Nhưng chính bảng này là thứ `CollectionPlanService` đọc để ra LỆNH CẮT: size nào bao nhiêu cái, đặt
 * bao nhiêu mét vải, giá vốn mỗi cái. Shop có bảng size riêng thì mọi con số sản xuất đều sai.
 *
 * Nay: chọn size nào cũng được, đặt tỉ lệ % từng size, và tổng luôn được nhìn thấy. Preset chỉ còn là
 * ĐIỂM BẮT ĐẦU — bấm một cái là đổ số vào bảng rồi sửa tiếp.
 */
import { computed, inject } from 'vue';
import StudioIcon from '../StudioIcon.vue';

const sizeRowsInput = inject('sizeRowsInput');
const sizePctTotal = inject('sizePctTotal');
const sizeTotalOk = inject('sizeTotalOk');
const applySizePreset = inject('applySizePreset');
const addSizeRow = inject('addSizeRow');
const removeSizeRow = inject('removeSizeRow');
const setSizeRow = inject('setSizeRow');
const evenSizeRows = inject('evenSizeRows');
const SIZE_PRESETS = inject('SIZE_PRESETS');
const collection = inject('collection');
const skuTotal = inject('skuTotal');

/** Số cái mỗi size theo tổng SKU đang chọn — đây là con số đi thẳng vào lệnh cắt. */
const totalSkus = computed(() => Number(skuTotal.value) || Number(collection.value?.structure?.total_skus) || 0);
function unitsFor(pct) {
  if (!totalSkus.value) return 0;
  return Math.round((Number(pct) || 0) / 100 * totalSkus.value);
}

/**
 * CÂN VỀ 100%: dồn phần lệch vào size có tỉ lệ LỚN NHẤT.
 * Vì sao không chia đều phần lệch: chia đều làm xáo trộn cả bảng vừa nhập, còn dồn vào size lớn nhất
 * thì hình dạng bảng giữ nguyên và chênh lệch chỉ vài phần trăm.
 */
function normalize() {
  const rows = sizeRowsInput.value;
  if (!rows.length) return;
  const total = sizePctTotal.value;
  if (total === 100) return;
  if (total <= 0) { evenSizeRows(); return; }
  const biggest = rows.reduce((best, row, i) => (Number(row.pct) > Number(rows[best].pct) ? i : best), 0);
  setSizeRow(biggest, { pct: Math.max(0, Number(rows[biggest].pct) + (100 - total)) });
}
</script>

<template>
  <div>
    <div class="card p-3 sm:p-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <h2 class="font-display text-base font-semibold text-brand-300">Bảng size dự kiến</h2>
          <p class="mt-0.5 text-body leading-5 text-cream-400">
            Size nào bán chạy thì để tỉ lệ cao hơn — số cái mỗi size lấy trực tiếp từ đây để ra lệnh cắt.
          </p>
        </div>
        <span
          class="rounded-full px-2.5 py-1 text-label font-semibold tabular-nums"
          :class="sizeTotalOk ? 'bg-ok/15 text-ok' : 'bg-warn/15 text-warn'"
        >Tổng {{ sizePctTotal }}%</span>
      </div>

      <!-- Preset = ĐIỂM BẮT ĐẦU, không phải lựa chọn duy nhất -->
      <div class="mt-4">
        <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Bắt đầu nhanh từ mẫu</p>
        <div class="mt-1.5 flex flex-wrap gap-1.5">
          <button
            v-for="preset in SIZE_PRESETS"
            :key="preset.id"
            type="button"
            class="tool-btn state-layer"
            :title="'Đổ ' + Object.entries(preset.values).map(([s, v]) => s + ' ' + v + '%').join(' · ') + ' vào bảng rồi sửa tiếp'"
            @click="applySizePreset(preset.id)"
          >
            <StudioIcon name="template" size="h-3 w-3" /> {{ preset.label }}
          </button>
          <button type="button" class="tool-btn state-layer" title="Chia đều 100% cho các size đang có" @click="evenSizeRows">
            <StudioIcon name="list" size="h-3 w-3" /> Chia đều
          </button>
        </div>
      </div>
    </div>

    <div class="card mt-3 p-3 sm:p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-display text-base font-semibold text-brand-300">Các size đang dùng</h3>
        <button type="button" class="tool-btn state-layer" :disabled="sizeRowsInput.length >= 12" @click="addSizeRow">
          <StudioIcon name="plus" size="h-3 w-3" /> Thêm size
        </button>
      </div>
      <p v-if="sizeRowsInput.length >= 12" class="mt-1.5 text-label text-cream-400">↳ Đã đủ 12 size — bỏ một size nếu muốn thêm size khác.</p>

      <ul class="mt-3 space-y-2">
        <li v-for="(row, index) in sizeRowsInput" :key="index" class="rounded-lg border border-ink-700 bg-ink-800 p-3">
          <div class="flex flex-wrap items-center gap-2">
            <label class="flex items-center gap-1.5">
              <span class="sr-only">Tên size thứ {{ index + 1 }}</span>
              <input
                class="input !w-20 !py-1.5 !text-body font-semibold uppercase"
                maxlength="8"
                :value="row.size"
                :aria-label="'Tên size thứ ' + (index + 1)"
                @input="setSizeRow(index, { size: $event.target.value.toUpperCase() })"
              >
            </label>

            <label class="flex min-w-0 flex-1 items-center gap-2">
              <span class="sr-only">Tỉ lệ phần trăm của size {{ row.size }}</span>
              <input
                type="range" min="0" max="100" step="1"
                class="min-w-0 flex-1 accent-primary"
                :value="Number(row.pct) || 0"
                :aria-label="'Tỉ lệ phần trăm của size ' + row.size"
                @input="setSizeRow(index, { pct: Number($event.target.value) })"
              >
              <input
                type="number" min="0" max="100" step="1"
                class="input !w-16 !py-1.5 !text-body tabular-nums"
                :value="Number(row.pct) || 0"
                :aria-label="'Tỉ lệ phần trăm (ô số) của size ' + row.size"
                @input="setSizeRow(index, { pct: Math.max(0, Math.min(100, Number($event.target.value) || 0)) })"
              >
              <span class="text-label text-cream-400">%</span>
            </label>

            <span v-if="totalSkus" class="rounded bg-ink-900 px-2 py-1 text-label tabular-nums text-cream-200" :title="'Số cái dự kiến cho size ' + row.size">
              ~{{ unitsFor(row.pct) }} cái
            </span>

            <button
              type="button"
              class="tool-btn !px-2 !py-1.5"
              :disabled="sizeRowsInput.length <= 1"
              :aria-label="'Bỏ size ' + (row.size || index + 1)"
              title="Bỏ size này"
              @click="removeSizeRow(index)"
            >
              <StudioIcon name="trash" size="h-3.5 w-3.5" />
            </button>
          </div>
        </li>
      </ul>
      <p v-if="sizeRowsInput.length <= 1" class="mt-1.5 text-label text-cream-400">↳ Phải còn ít nhất một size — bảng size rỗng thì lệnh cắt không có gì để chia.</p>

      <div v-if="!sizeTotalOk" role="status" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-2 rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-body leading-5 text-warn">
        <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
        <span>Tổng đang là {{ sizePctTotal }}% — nên bằng 100% để mọi con số sản xuất đọc đúng.</span>
        <button type="button" class="tool-btn state-layer !py-1" @click="normalize">Cân về 100%</button>
      </div>
    </div>

    <p v-if="totalSkus" class="mt-4 rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
      Với {{ totalSkus }} mã hàng và bảng size này, xưởng sẽ cắt khoảng
      <b class="text-cream-100">{{ sizeRowsInput.reduce((sum, row) => sum + unitsFor(row.pct), 0) }} cái</b>
      cho một mã — con số này cập nhật theo từng ô bạn sửa.
    </p>
  </div>
</template>
