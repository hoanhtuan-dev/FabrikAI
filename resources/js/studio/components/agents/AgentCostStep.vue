<script setup>
/**
 * BƯỚC CON 3.5 — ĐƠN GIÁ & ĐỊNH MỨC CỦA XƯỞNG BẠN (2026-09-25).
 *
 * Đây là điểm khác biệt cốt lõi của cả luồng: mọi con số TIỀN do người bỏ vốn nhập, hệ thống chỉ tính.
 * Tách thành một việc riêng để trên điện thoại người dùng nhập được từng ô một mà không phải cuộn qua
 * bảng lệnh cắt dài ba màn hình.
 */
import { inject, ref } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';

const PLAN_FIELDS = inject('PLAN_FIELDS');
const planFieldValue = inject('planFieldValue');
const schedulePlan = inject('schedulePlan');
const loadPlanNow = inject('loadPlanNow');
const planTotals = inject('planTotals');
const plan = inject('plan');
const briefStale = inject('briefStale');
const collection = inject('collection');
const store = useStudioStore();
const formatNumber = inject('formatNumber');
const formatVnd = inject('formatVnd');

/** Nhóm ô theo việc chủ xưởng thật sự làm — 13 ô liền một khối thì không ai biết bắt đầu từ đâu. */
const GROUPS = [
  { id: 'volume', title: 'Sản lượng', hint: 'Một mã làm bao nhiêu cái và xưởng ra được bao nhiêu mỗi ngày', keys: ['units_per_sku', 'daily_capacity'] },
  { id: 'fabric', title: 'Vải', hint: 'Giá vải, khổ vải và hao hụt khi cắt', keys: ['fabric_price_per_m', 'fabric_width_cm', 'wastage_pct', 'fabric_safety_pct'] },
  { id: 'making', title: 'Công may & phụ liệu', hint: 'Tiền công, phụ liệu, bao bì và tỉ lệ phải làm lại', keys: ['sewing_cost', 'trim_cost', 'packaging_cost', 'defect_pct'] },
  { id: 'selling', title: 'Bán hàng', hint: 'Chiết khấu kênh, lãi mong muốn và chi phí cố định của cả bộ', keys: ['channel_discount_pct', 'target_margin_pct', 'fixed_cost'] },
];
const fieldOf = (key) => PLAN_FIELDS.find((row) => row.key === key) || { key, label: key, suffix: '', hint: '' };
const openGroup = ref('volume');
</script>

<template>
  <div>
    <div v-if="!collection" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-8 text-center">
      <StudioIcon name="receipt" size="h-7 w-7" class="mx-auto text-brand-300" />
      <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief để tính giá vốn</p>
      <p class="mt-1 text-xs leading-5 text-cream-400">Giá vốn cần cơ cấu SKU và bảng size — quay lại việc 1 để tạo brief trước.</p>
    </div>

    <template v-else>
      <div class="card p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="font-display text-base font-semibold text-brand-300">Đơn giá &amp; định mức của xưởng bạn</h2>
            <p class="mt-0.5 text-body leading-5 text-cream-400">
              Sửa ô nào là kế hoạch tính lại ngay. Giá thành và lợi nhuận do hệ thống TÍNH từ đúng những ô này — không dùng model AI cho con số.
              <span v-if="store.planRecalculating" class="text-cream-400"> Đang tính lại…</span>
            </p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="tool-btn state-layer" @click="store.resetPlanAssumptions(); schedulePlan()">
              <StudioIcon name="refresh" size="h-3 w-3" /> Về mặc định
            </button>
            <button type="button" class="btn-brand btn-sm flex items-center gap-2" :disabled="store.planLoading || briefStale" @click="loadPlanNow">
              <StudioIcon name="receipt" size="h-3.5 w-3.5" :class="store.planLoading ? 'animate-spin' : ''" />
              {{ store.planLoading ? 'Đang tính…' : 'Tính lại kế hoạch' }}
            </button>
            <p v-if="!store.planLoading && briefStale" class="mt-1.5 text-label leading-4 text-warn">↳ Brief đã cũ so với dữ liệu shop — cập nhật cơ cấu ở việc 2 rồi mới tính kế hoạch.</p>
          </div>
        </div>

        <div class="mt-4 space-y-2">
          <div v-for="group in GROUPS" :key="group.id" class="rounded-lg border border-ink-700 bg-ink-900">
            <button
              type="button"
              class="motion-ui flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left"
              :aria-expanded="openGroup === group.id"
              @click="openGroup = openGroup === group.id ? '' : group.id"
            >
              <span class="min-w-0">
                <span class="block text-body font-semibold text-cream-100">{{ group.title }}</span>
                <span class="block text-label leading-4 text-cream-400">{{ group.hint }}</span>
              </span>
              <StudioIcon name="chevronDown" size="h-4 w-4" class="shrink-0 text-cream-400 transition" :class="openGroup === group.id ? 'rotate-180' : ''" />
            </button>

            <div v-if="openGroup === group.id" class="grid gap-3 border-t border-ink-700 p-3 sm:grid-cols-2">
              <label v-for="key in group.keys" :key="key" class="block" :title="fieldOf(key).hint">
                <span class="flex items-center justify-between gap-2 text-label font-semibold uppercase tracking-wide text-cream-400">
                  {{ fieldOf(key).label }}<span class="text-cream-400">{{ fieldOf(key).suffix }}</span>
                </span>
                <input
                  type="number" min="0" step="1"
                  class="input mt-1 w-full !py-2 !text-body tabular-nums"
                  :value="planFieldValue(key)"
                  :aria-label="fieldOf(key).label + (fieldOf(key).suffix ? ' (' + fieldOf(key).suffix + ')' : '')"
                  @input="store.setPlanAssumption(key, $event.target.value); schedulePlan()"
                >
                <span class="mt-1 block text-tiny leading-4 text-cream-400">{{ fieldOf(key).hint }}</span>
              </label>
            </div>
          </div>
        </div>

        <p v-if="store.planError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-danger/40 bg-danger/10 p-3 text-body leading-5 text-danger">
          <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ store.planError }}</span>
        </p>
      </div>

      <div v-if="planTotals" class="card mt-4 p-4 sm:p-5">
        <h3 class="font-display text-base font-semibold text-brand-300">Kết quả vừa tính</h3>
        <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
          <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
            <p class="text-label text-cream-400">Tổng sản xuất</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatNumber(planTotals.units) }} cái</p>
          </div>
          <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
            <p class="text-label text-cream-400">Vải cần đặt</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatNumber(planTotals.fabric_order_m) }} m</p>
            <p class="text-label text-cream-400">{{ formatVnd(planTotals.fabric_order_cost_vnd) }}</p>
          </div>
          <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
            <p class="text-label text-cream-400">Vốn cần</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatVnd(planTotals.capital_needed_vnd) }}</p>
            <p class="text-label text-cream-400">Giá vốn TB {{ formatVnd(planTotals.avg_unit_cost_vnd) }}/cái</p>
          </div>
          <div class="rounded-lg border border-ok/40 bg-ok/10 px-3 py-2.5">
            <p class="text-label text-ok">Lãi gộp (chưa trừ chi phí cố định)</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-ok">{{ formatVnd(planTotals.profit_vnd) }}</p>
            <p class="text-label text-ok">{{ planTotals.margin_pct }}% · {{ formatVnd(planTotals.avg_profit_unit_vnd) }}/cái</p>
            <p v-if="planTotals.fixed_cost_vnd" class="mt-1 text-label text-ok">Sau chi phí cố định {{ formatVnd(planTotals.fixed_cost_vnd) }}: <b>{{ formatVnd(planTotals.profit_after_fixed_vnd) }}</b> ({{ planTotals.margin_after_fixed_pct }}%)</p>
          </div>
        </div>

        <div
          v-if="plan.price_check && plan.price_check.status !== 'within_band' && plan.price_check.status !== 'unknown'"
          role="status"
          class="mt-3 flex gap-2 rounded-lg border p-3 text-body leading-5"
          :class="plan.price_check.status === 'above_band' ? 'border-danger/40 bg-danger/10 text-danger' : 'border-ok/40 bg-ok/10 text-ok'"
        >
          <StudioIcon :name="plan.price_check.status === 'above_band' ? 'alertTriangle' : 'info'" size="h-4 w-4" class="shrink-0" />
          <span>
            {{ plan.price_check.message }}
            <span class="mt-0.5 block text-cream-400">
              Giá bán gợi ý theo giá vốn: {{ formatVnd(plan.price_check.suggested_price_vnd) }} · dải giá brief: {{ formatVnd(plan.price_check.band_min_vnd) }} – {{ formatVnd(plan.price_check.band_max_vnd) }}
            </span>
          </span>
        </div>
      </div>
    </template>
  </div>
</template>
