<script setup>
/**
 * BƯỚC CON 3.2 — SỐ LƯỢNG SKU (2026-09-25).
 *
 * Trước đây tổng số mã hàng do thuật toán quyết và người dùng chỉ ĐỌC được con số đó ở tab "Cấu trúc".
 * Nhưng tổng SKU là quyết định của người bỏ vốn (năng lực xưởng, vốn lưu động, sức bán), không phải của
 * thuật toán. Nay người dùng chọn quy mô, hệ thống CHIA LẠI theo đúng tỉ lệ nhóm hàng đang có.
 */
import { computed, inject, ref, watch } from 'vue';
import StudioIcon from '../StudioIcon.vue';

const skuTotal = inject('skuTotal');
const skuTotalSource = inject('skuTotalSource');
const categoryRows = inject('categoryRows');
const collection = inject('collection');
const briefStale = inject('briefStale');
const createBrief = inject('createBrief');
const store = inject('store');

const QUICK = [6, 9, 12, 18, 24, 30, 40];
const chosen = ref(Math.max(1, Number(collection.value?.structure?.total_skus) || 12));
let applying = ref(false);

watch(collection, (value) => {
  const total = Number(value?.structure?.total_skus) || 0;
  if (total > 0) chosen.value = total;
}, { immediate: true });

const autoTotal = computed(() => Number(collection.value?.structure?.total_skus) || 0);
const changed = computed(() => Number(skuTotal.value) > 0 && Number(skuTotal.value) !== autoTotal.value);

function pick(value) {
  chosen.value = Math.max(1, Math.min(400, Number(value) || 1));
  skuTotal.value = chosen.value;
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
function useAuto() {
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

        <p v-if="changed" role="status" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-body leading-5 text-warn">
          <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
          <span>Cơ cấu đang hiển thị vẫn của {{ autoTotal }} mã. Bấm «Cập nhật cơ cấu» để chia lại theo {{ skuTotal }} mã.</span>
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="btn-brand btn-sm flex items-center gap-2"
            :disabled="applying || !changed"
            @click="applyStructure"
          >
            <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="applying ? 'animate-spin' : ''" />
            {{ applying ? 'Đang chia lại…' : 'Cập nhật cơ cấu' }}
          </button>
          <button type="button" class="tool-btn state-layer" :disabled="applying" @click="useAuto">
            <StudioIcon name="wand" size="h-3 w-3" /> Để hệ thống đề xuất
          </button>
        </div>
        <p class="mt-1.5 text-label leading-4 text-cream-400">
          ↳ Cập nhật cơ cấu chạy bằng bộ quy tắc của hệ thống nên TỨC THÌ và không tốn lượt gọi AI — các con số giống hệt bản do AI dựng.
          Muốn phần chữ cũng do AI viết lại thì sang việc 7 và bấm «Tạo lại bằng AI».
        </p>
      </div>

      <div class="card mt-4 p-4 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-display text-base font-semibold text-brand-300">Cơ cấu theo nhóm hàng</h3>
          <span class="text-label text-cream-400">{{ autoTotal }} mã · {{ categoryRows.length }} nhóm</span>
        </div>
        <div class="mt-3 space-y-2.5">
          <div v-for="row in categoryRows" :key="row.category" class="rounded-lg bg-ink-800 p-3">
            <div class="flex items-center justify-between gap-2 text-xs">
              <span class="font-semibold text-cream-100">{{ row.category }}</span>
              <span class="tabular-nums text-brand-200">{{ row.count }} mã · {{ row.share || 0 }}%</span>
            </div>
            <div class="mt-1.5 h-1 rounded bg-ink-700">
              <span class="block h-full rounded bg-brand-500" :style="{ width: Math.min(100, Number(row.share || 0)) + '%' }"></span>
            </div>
            <p v-if="row.rationale" class="mt-1.5 text-label leading-4 text-cream-400">{{ row.rationale }}</p>
          </div>
        </div>
        <p class="mt-3 text-label leading-4 text-cream-400">{{ collection.structure?.rationale }}</p>
      </div>

      <p v-if="briefStale" role="status" class="mt-4 flex gap-2 rounded-lg border border-warn/40 bg-warn/10 p-3 text-body leading-5 text-warn">
        <StudioIcon name="info" size="h-4 w-4" class="shrink-0" />
        <span>Prompt, hướng hoặc bảng size đã đổi so với lần dựng brief gần nhất — bấm «Cập nhật cơ cấu» ở trên để mọi con số khớp lại.</span>
      </p>
    </template>
  </div>
</template>
