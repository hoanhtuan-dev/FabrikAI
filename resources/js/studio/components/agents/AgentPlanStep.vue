<script setup>
/**
 * BƯỚC CON 3.6 — KẾ HOẠCH SẢN XUẤT & LÃI (2026-09-25).
 *
 * Tách khỏi ô nhập đơn giá: nhập là MỘT việc, đọc lệnh cắt là VIỆC KHÁC. Trên điện thoại, gộp hai thứ
 * vào một màn là người dùng vừa gõ số vừa phải cuộn qua bảng chín cột.
 */
import { inject } from 'vue';
import StudioIcon from '../StudioIcon.vue';

const collection = inject('collection');
const plan = inject('plan');
const planTotals = inject('planTotals');
const planWaves = inject('planWaves');
const planLines = inject('planLines');
const planSizeChart = inject('planSizeChart');
const planScenarios = inject('planScenarios');
const exportCutSheet = inject('exportCutSheet');
const exportSizeChart = inject('exportSizeChart');
const copyCutSheet = inject('copyCutSheet');
const formatNumber = inject('formatNumber');
const formatVnd = inject('formatVnd');
const setSub = inject('setSub');
</script>

<template>
  <div>
    <div v-if="!collection" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-8 text-center">
      <StudioIcon name="receipt" size="h-7 w-7" class="mx-auto text-brand-300" />
      <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief để lập kế hoạch</p>
      <p class="mt-1 text-xs leading-5 text-cream-400">Tạo brief ở việc 1 rồi quay lại đây.</p>
    </div>

    <template v-else>
      <div v-if="!planTotals" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-8 text-center">
        <StudioIcon name="receipt" size="h-7 w-7" class="mx-auto text-brand-300" />
        <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có kế hoạch sản xuất</p>
        <p class="mt-1 text-xs leading-5 text-cream-400">Nhập đơn giá ở việc 5 — kế hoạch tự tính lại sau mỗi ô bạn sửa.</p>
        <button type="button" class="tool-btn state-layer mt-3" @click="setSub('cost')">
          <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" /> Sang việc 5: Đơn giá xưởng
        </button>
      </div>

      <template v-else>
        <div class="card p-4 sm:p-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <h2 class="font-display text-base font-semibold text-brand-300">Lệnh cắt — {{ planLines.length }} dòng</h2>
              <p class="mt-0.5 text-body leading-5 text-cream-400">
                Số cái mỗi mã theo từng size, vải cần cho mỗi dòng và giá vốn/cái. Đây là bảng đưa thẳng cho thợ cắt.
                <span v-if="planTotals.days_total"> · khoảng {{ planTotals.days_total }} ngày nếu chạy {{ plan.assumptions.daily_capacity }} cái/ngày.</span>
              </p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" class="tool-btn state-layer" @click="exportCutSheet"><StudioIcon name="download" size="h-3 w-3" /> Lệnh cắt (CSV)</button>
              <button type="button" class="tool-btn state-layer" @click="exportSizeChart"><StudioIcon name="download" size="h-3 w-3" /> Bảng size (CSV)</button>
              <button type="button" class="tool-btn state-layer" @click="copyCutSheet"><StudioIcon name="copy" size="h-3 w-3" /> Sao chép</button>
            </div>
          </div>

          <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[46rem] text-left text-body">
              <thead>
                <tr class="border-b border-ink-700 text-cream-400">
                  <th class="pb-2 pr-2 font-semibold">Nhóm hàng</th>
                  <th class="pb-2 pr-2 font-semibold">Size</th>
                  <th class="pb-2 pr-2 text-right font-semibold">Cái</th>
                  <th class="pb-2 pr-2 text-right font-semibold">Vải/cái</th>
                  <th class="pb-2 pr-2 text-right font-semibold">Tổng vải</th>
                  <th class="pb-2 pr-2 text-right font-semibold">Giá vốn/cái</th>
                  <th class="pb-2 pr-2 text-right font-semibold">Giá bán</th>
                  <th class="pb-2 pr-2 text-right font-semibold">Lãi/cái</th>
                  <th class="pb-2 text-right font-semibold">% lãi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-ink-800">
                <tr v-for="(line, index) in planLines" :key="index" class="text-cream-200">
                  <td class="py-2 pr-2">
                    {{ line.category }}
                    <span v-if="line.source === 'shop'" class="ml-1 rounded bg-ok/15 px-1 py-0.5 text-tiny text-ok">theo shop</span>
                    <span v-else-if="line.source === 'prompt'" class="ml-1 rounded bg-brand-500/15 px-1 py-0.5 text-tiny text-brand-200">theo prompt</span>
                  </td>
                  <td class="py-2 pr-2 font-semibold">{{ line.size }}</td>
                  <td class="py-2 pr-2 text-right tabular-nums">{{ formatNumber(line.qty) }}</td>
                  <td class="py-2 pr-2 text-right tabular-nums text-cream-300">{{ line.fabric_m_per_unit }}m</td>
                  <td class="py-2 pr-2 text-right tabular-nums text-cream-300">{{ line.fabric_m_total }}m</td>
                  <td class="py-2 pr-2 text-right tabular-nums">{{ formatVnd(line.unit_cost) }}</td>
                  <td class="py-2 pr-2 text-right tabular-nums">{{ formatVnd(line.sell_price_vnd) }}</td>
                  <td class="py-2 pr-2 text-right tabular-nums" :class="line.profit_unit > 0 ? 'text-ok' : 'text-danger'">{{ formatVnd(line.profit_unit) }}</td>
                  <td class="py-2 text-right tabular-nums text-cream-300">{{ line.margin_pct }}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-3">
          <div v-for="wave in planWaves" :key="wave.id" class="card p-4">
            <div class="flex items-center justify-between gap-2">
              <h4 class="text-xs font-semibold text-cream-100">{{ wave.name }}</h4>
              <span class="rounded bg-ink-800 px-2 py-0.5 text-label text-cream-400">{{ wave.share_pct }}%</span>
            </div>
            <p class="mt-2 text-xl font-semibold tabular-nums text-cream-100">{{ formatNumber(wave.units) }} cái</p>
            <p class="text-body text-cream-400">Vải {{ wave.fabric_order_m }}m · vốn {{ formatVnd(wave.cost_vnd) }}<span v-if="wave.days"> · {{ wave.days }} ngày</span></p>
            <p class="mt-1 text-body text-ok">Lãi gộp (chưa trừ chi phí cố định) {{ formatVnd(wave.profit_vnd) }}</p>
            <p class="mt-2 text-label leading-4 text-cream-400">{{ wave.note }}</p>
            <details class="mt-2">
              <summary class="cursor-pointer text-label font-semibold text-cream-400">Chi tiết {{ wave.lines.length }} dòng</summary>
              <ul class="mt-1.5 space-y-0.5 text-label text-cream-300">
                <li v-for="(row, index) in wave.lines" :key="index">{{ row.category }} · {{ row.size }}: {{ row.qty }}</li>
              </ul>
            </details>
          </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
          <div class="card p-4 sm:p-5">
            <h3 class="font-display text-base font-semibold text-brand-300">Bảng size (cm)</h3>
            <p class="mt-0.5 text-body leading-5 text-cream-400">Số đo tham chiếu dáng nữ VN — phải đối chiếu rập thật của xưởng và độ co của vải.</p>
            <div v-for="chart in planSizeChart" :key="chart.category" class="mt-3 overflow-x-auto">
              <p class="text-body font-semibold text-brand-200">{{ chart.category }}</p>
              <table class="mt-1 w-full text-left text-label">
                <thead>
                  <tr class="text-cream-400">
                    <th class="pr-2 font-semibold">Size</th>
                    <th class="pr-2 font-semibold">Cái</th>
                    <th v-for="(value, name) in chart.rows[0].measures" :key="name" class="pr-2 font-semibold">{{ name }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-ink-800 text-cream-200">
                  <tr v-for="row in chart.rows" :key="row.size">
                    <td class="py-1.5 pr-2 font-semibold">{{ row.size }}</td>
                    <td class="py-1.5 pr-2 tabular-nums">{{ row.units_planned }}</td>
                    <td v-for="(value, name) in row.measures" :key="name" class="py-1.5 pr-2 tabular-nums">{{ value }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card p-4 sm:p-5">
            <h3 class="font-display text-base font-semibold text-brand-300">Ba mức giá — lãi tương ứng</h3>
            <p class="mt-0.5 text-body leading-5 text-cream-400">Đã trừ chiết khấu kênh {{ plan.assumptions.channel_discount_pct }}% và cộng chi phí cố định.</p>
            <div class="mt-3 space-y-2">
              <div v-for="scenario in planScenarios" :key="scenario.price_vnd" class="rounded-lg border border-ink-700 bg-ink-800 p-3">
                <div class="flex items-center justify-between gap-2">
                  <span class="text-xs font-semibold text-cream-100">{{ formatVnd(scenario.price_vnd) }}</span>
                  <span class="rounded px-2 py-0.5 text-label" :class="scenario.profit_vnd > 0 ? 'bg-ok/15 text-ok' : 'bg-danger/15 text-danger'">{{ scenario.label }}</span>
                </div>
                <p class="mt-1 text-body text-cream-300">Lãi (đã trừ chi phí cố định) {{ formatVnd(scenario.profit_vnd) }} · biên {{ scenario.margin_pct }}%<span v-if="scenario.breakeven_units"> · hoà vốn ở {{ formatNumber(scenario.breakeven_units) }} cái</span></p>
              </div>
            </div>
            <ul class="mt-3 space-y-1 text-label leading-4 text-cream-400">
              <li v-for="(note, index) in plan.notes" :key="index">• {{ note }}</li>
            </ul>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
