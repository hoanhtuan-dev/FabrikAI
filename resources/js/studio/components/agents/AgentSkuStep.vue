<script setup>
/**
 * BƯỚC CON 3.2 — SỐ LƯỢNG SKU (2026-09-25).
 *
 * Trước đây tổng số mã hàng do thuật toán quyết và người dùng chỉ ĐỌC được con số đó ở tab "Cấu trúc".
 * Nhưng tổng SKU là quyết định của người bỏ vốn (năng lực xưởng, vốn lưu động, sức bán), không phải của
 * thuật toán. Nay người dùng chọn quy mô, hệ thống CHIA LẠI theo đúng tỉ lệ nhóm hàng đang có.
 *
 * [BỔ SUNG — cùng ngày] Chọn TỔNG mới chỉ nói QUY MÔ; chia cho nhóm nào cũng là quyết định của người
 * bỏ vốn. Trước đây chỗ chia là của thuật toán (theo đúng tỉ lệ cũ) nên muốn dồn 8 mã cho nhóm áo cũng
 * không có cách nào nói ra. Nay BẢNG CƠ CẤU sửa được y như bảng size ở việc 3: thêm/bớt nhóm, đặt số mã
 * từng nhóm, chia đều, khớp về tổng đang chọn — và bảng đó đi thẳng vào lệnh cắt.
 */
import { computed, inject, ref, watch } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';

const skuTotal = inject('skuTotal');
const skuTotalSource = inject('skuTotalSource');
const categoryRows = inject('categoryRows');
const collection = inject('collection');
const briefStale = inject('briefStale');
const createBrief = inject('createBrief');
const structureRowsInput = inject('structureRowsInput');
const structureEdited = inject('structureEdited');
const structureCountTotal = inject('structureCountTotal');
const structureDirty = inject('structureDirty');
const setStructureRow = inject('setStructureRow');
const addStructureRow = inject('addStructureRow');
const removeStructureRow = inject('removeStructureRow');
const evenStructureRows = inject('evenStructureRows');
const normalizeStructureRows = inject('normalizeStructureRows');
const useAutoStructure = inject('useAutoStructure');
const store = useStudioStore();

const QUICK = [6, 9, 12, 18, 24, 30, 40];
const chosen = ref(Math.max(1, Number(collection.value?.structure?.total_skus) || 12));
let applying = ref(false);

watch(collection, (value) => {
  const total = Number(value?.structure?.total_skus) || 0;
  if (total > 0) chosen.value = total;
}, { immediate: true });

const autoTotal = computed(() => Number(collection.value?.structure?.total_skus) || 0);
const totalChanged = computed(() => Number(skuTotal.value) > 0 && Number(skuTotal.value) !== autoTotal.value);
/** Có gì để áp dụng: đổi quy mô, HOẶC sửa bảng cơ cấu. */
const canApply = computed(() => totalChanged.value || structureDirty.value);
/** Quy mô đang nhắm tới: số người dùng chọn, không thì con số hệ thống đề xuất. */
const targetTotal = computed(() => Number(skuTotal.value) || autoTotal.value || structureCountTotal.value);
/**
 * Bảng cộng ra khác quy mô đang chọn?
 *
 * Đây là chỗ dễ sai nhất của một bảng số: người dùng sửa vài ô rồi tổng lệch mà không ai nói. Lệnh cắt
 * đọc TỔNG CỦA BẢNG, nên nếu lệch thì con số sản xuất không còn là quy mô họ tưởng.
 */
const totalMismatch = computed(() => structureEdited.value
  && structureCountTotal.value !== targetTotal.value);
/** Trần thanh trượt: đủ rộng cho mọi ô đang có, không cắt cụt giá trị người dùng vừa nhập. */
const countMax = computed(() => Math.max(20, targetTotal.value,
  ...structureRowsInput.value.map((row) => Number(row.count) || 0)));

function pick(value) {
  chosen.value = Math.max(1, Math.min(400, Number(value) || 1));
  skuTotal.value = chosen.value;
}
function shareOf(count) {
  const total = structureCountTotal.value;
  return total > 0 ? Math.round((Number(count) || 0) / total * 100) : 0;
}

/** Cập nhật cơ cấu bằng đường TẤT ĐỊNH: con số giống hệt bản AI, nhưng tức thì và không tốn lượt gọi. */
async function applyStructure() {
  applying.value = true;
  try {
    await createBrief({ force: true, ai: false });
  } finally {
    applying.value = false;
  }
}
/** Trả cả quy mô LẪN bảng cơ cấu về đề xuất của hệ thống. */
function useAuto() {
  useAutoStructure();
  skuTotal.value = 0;
  createBrief({ force: true, ai: false });
}
</script>

<template>
  <div>
    <div v-if="!collection" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-8 text-center">
      <StudioIcon name="package" size="h-7 w-7" class="mx-auto text-brand-300" />
      <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief để chia SKU</p>
      <p class="mt-1 text-xs leading-5 text-cream-400">Quay lại việc 1 (Mô tả &amp; ảnh mẫu) và tạo brief trước — cơ cấu nhóm hàng là thứ để chia quy mô.</p>
    </div>

    <template v-else>
      <div class="card p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="font-display text-base font-semibold text-brand-300">Tổng số mã hàng (SKU)</h2>
            <p class="mt-0.5 text-body leading-5 text-cream-400">
              Bạn quyết định quy mô; hệ thống chia lại cho các nhóm hàng theo ĐÚNG tỉ lệ đang có. Số này đi thẳng vào lệnh cắt và giá vốn.
            </p>
          </div>
          <span class="rounded-full px-2.5 py-1 text-label font-semibold" :class="skuTotalSource === 'owner' ? 'bg-brand-600/20 text-brand-200' : 'bg-ink-800 text-cream-300'">
            {{ skuTotalSource === 'owner' ? 'Do bạn chọn' : 'Hệ thống đề xuất' }}
          </span>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
          <button
            v-for="value in QUICK"
            :key="value"
            type="button"
            class="tool-btn state-layer tabular-nums"
            :class="{ 'is-active': Number(skuTotal) === value || (!skuTotal && autoTotal === value) }"
            :aria-pressed="Number(skuTotal) === value"
            @click="pick(value)"
          >
            {{ value }}
          </button>
          <label class="flex items-center gap-2">
            <span class="sr-only">Số mã hàng khác</span>
            <input
              type="number" min="1" max="400" step="1"
              class="input !w-24 !py-1.5 !text-body tabular-nums"
              aria-label="Số mã hàng khác"
              :value="chosen"
              @input="pick($event.target.value)"
            >
            <span class="text-label text-cream-400">mã</span>
          </label>
        </div>

        <p v-if="totalChanged" role="status" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-body leading-5 text-warn">
          <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
          <span>Cơ cấu đang hiển thị vẫn của {{ autoTotal }} mã. Bấm «Cập nhật số liệu» để chia lại theo {{ skuTotal }} mã.</span>
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="btn-brand btn-sm flex items-center gap-2"
            :disabled="applying || !canApply"
            @click="applyStructure"
          >
            <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="applying ? 'animate-spin' : ''" />
            {{ applying ? 'Đang chia lại…' : 'Cập nhật số liệu (không gọi AI)' }}
          </button>
          <button type="button" class="tool-btn state-layer" :disabled="applying" @click="useAuto">
            <StudioIcon name="wand" size="h-3 w-3" /> Để hệ thống đề xuất
          </button>
        </div>
        <p class="mt-1.5 text-label leading-4 text-cream-400">
          ↳ Cập nhật chạy bằng bộ quy tắc của hệ thống nên TỨC THÌ và không tốn lượt gọi AI. Phần CHỮ do AI viết ở lượt trước được GIỮ NGUYÊN, chỉ các con số được tính lại.
        </p>
      </div>

      <div class="card mt-4 p-4 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-display text-base font-semibold text-brand-300">Cơ cấu theo nhóm hàng</h3>
          <div class="flex flex-wrap items-center gap-2">
            <span
              class="rounded-full px-2.5 py-1 text-label font-semibold tabular-nums"
              :class="totalMismatch ? 'bg-warn/15 text-warn' : 'bg-ink-800 text-cream-300'"
            >{{ structureCountTotal }} mã · {{ structureRowsInput.length }} nhóm</span>
            <span class="rounded-full px-2.5 py-1 text-label font-semibold" :class="structureEdited ? 'bg-brand-600/20 text-brand-200' : 'bg-ink-800 text-cream-300'">
              {{ structureEdited ? 'Do bạn đặt' : 'Hệ thống đề xuất' }}
            </span>
          </div>
        </div>
        <p class="mt-0.5 text-body leading-5 text-cream-400">
          Nhóm nào bao nhiêu mã là quyết định của bạn — sửa thẳng vào bảng dưới rồi bấm «Cập nhật số liệu». Đây là con số lệnh cắt đọc.
        </p>

        <div class="mt-3 flex flex-wrap gap-1.5">
          <button type="button" class="tool-btn state-layer" :disabled="structureRowsInput.length >= 12" @click="addStructureRow">
            <StudioIcon name="plus" size="h-3 w-3" /> Thêm nhóm
          </button>
          <button type="button" class="tool-btn state-layer" title="Chia đều số mã đang chọn cho các nhóm hiện có" @click="evenStructureRows">
            <StudioIcon name="list" size="h-3 w-3" /> Chia đều
          </button>
          <button v-if="structureEdited" type="button" class="tool-btn state-layer" @click="useAuto">
            <StudioIcon name="wand" size="h-3 w-3" /> Bỏ bảng của tôi
          </button>
        </div>
        <p v-if="structureRowsInput.length >= 12" class="mt-1.5 text-label text-cream-400">↳ Đã đủ 12 nhóm — bỏ một nhóm nếu muốn thêm nhóm khác.</p>

        <ul class="mt-3 space-y-2">
          <li v-for="(row, index) in structureRowsInput" :key="index" class="rounded-lg border border-ink-700 bg-ink-800 p-3">
            <div class="flex flex-wrap items-center gap-2">
              <label class="flex min-w-0 flex-1 items-center gap-1.5">
                <span class="sr-only">Tên nhóm hàng thứ {{ index + 1 }}</span>
                <input
                  class="input !w-40 !py-1.5 !text-body font-semibold"
                  maxlength="60"
                  placeholder="Tên nhóm hàng"
                  :value="row.category"
                  :aria-label="'Tên nhóm hàng thứ ' + (index + 1)"
                  @input="setStructureRow(index, { category: $event.target.value })"
                >
              </label>

              <label class="flex items-center gap-2">
                <span class="sr-only">Số mã hàng của nhóm {{ row.category }}</span>
                <input
                  type="range" min="0" :max="countMax" step="1"
                  class="min-w-0 accent-primary"
                  :value="Number(row.count) || 0"
                  :aria-label="'Số mã hàng của nhóm ' + row.category"
                  @input="setStructureRow(index, { count: Number($event.target.value) })"
                >
                <input
                  type="number" min="0" max="400" step="1"
                  class="input !w-16 !py-1.5 !text-body tabular-nums"
                  :value="Number(row.count) || 0"
                  :aria-label="'Số mã hàng (ô số) của nhóm ' + row.category"
                  @input="setStructureRow(index, { count: Math.max(0, Math.min(400, Number($event.target.value) || 0)) })"
                >
                <span class="text-label text-cream-400">mã</span>
              </label>

              <span v-if="structureCountTotal" class="rounded bg-ink-900 px-2 py-1 text-label tabular-nums text-cream-200" :title="'Tỉ lệ của nhóm ' + row.category + ' trong tổng số mã'">
                {{ shareOf(row.count) }}%
              </span>

              <button
                type="button"
                class="tool-btn !px-2 !py-1.5"
                :disabled="structureRowsInput.length <= 1"
                :aria-label="'Bỏ nhóm ' + (row.category || index + 1)"
                title="Bỏ nhóm này"
                @click="removeStructureRow(index)"
              >
                <StudioIcon name="trash" size="h-3.5 w-3.5" />
              </button>
            </div>
            <p v-if="row.rationale" class="mt-1.5 text-label leading-4 text-cream-400">{{ row.rationale }}</p>
          </li>
        </ul>
        <p v-if="structureRowsInput.length <= 1" class="mt-1.5 text-label text-cream-400">↳ Phải còn ít nhất một nhóm — bảng rỗng thì lệnh cắt không có gì để chia.</p>

        <div v-if="totalMismatch" role="status" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-2 rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-body leading-5 text-warn">
          <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
          <span>Bảng đang cộng ra {{ structureCountTotal }} mã, trong khi quy mô bạn chọn là {{ targetTotal }} mã.</span>
          <button type="button" class="tool-btn state-layer !py-1" @click="normalizeStructureRows">Khớp về {{ targetTotal }} mã</button>
        </div>

        <p class="mt-3 text-label leading-4 text-cream-400">{{ collection.structure?.rationale }}</p>
      </div>

      <p v-if="briefStale" role="status" class="mt-4 flex gap-2 rounded-lg border border-warn/40 bg-warn/10 p-3 text-body leading-5 text-warn">
        <StudioIcon name="info" size="h-4 w-4" class="shrink-0" />
        <span>Prompt, hướng, bảng size hoặc bảng cơ cấu đã đổi so với lần dựng brief gần nhất — bấm «Cập nhật số liệu» ở trên để mọi con số khớp lại.</span>
      </p>
    </template>
  </div>
</template>
