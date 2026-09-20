<script setup>
import { computed } from 'vue';
import BaseModal from './BaseModal.vue';
import StudioIcon from './StudioIcon.vue';

const props = defineProps({ settings: { type: Object, default: () => ({}) }, name: { type: String, default: 'Bộ sưu tập' } });
const open = defineModel({ type: Boolean, default: false });

const s = computed(() => props.settings || {});
const palette = computed(() => s.value.palette || []);
const moodboard = computed(() => s.value.moodboard || []);
const categories = computed(() => s.value.structure?.categories || []);
const totalSkus = computed(() => s.value.structure?.total_skus || 0);
const outfits = computed(() => s.value.outfit_matching || []);
const sizes = computed(() => s.value.size_distribution || []);
const price = computed(() => s.value.price_bands || null);
const plan = computed(() => s.value.plan || null);
const planTotals = computed(() => plan.value?.totals || null);
const planNotes = computed(() => plan.value?.notes || []);
const trends = computed(() => s.value.selected_trends || []);

const MOOD_LABELS = {
  'Silhouette': 'Dáng', 'Color story': 'Câu chuyện màu', 'Fabric': 'Chất liệu', 'Detail': 'Chi tiết',
  'Styling': 'Phối đồ', 'Runway cue': 'Gợi ý sàn diễn', 'Office wear': 'Đồ công sở', 'Texture': 'Bề mặt chất liệu',
};
const moodLabel = (l) => MOOD_LABELS[l] || l;

const fmt = (v) => { const n = Number(v); return Number.isFinite(n) ? new Intl.NumberFormat('vi-VN').format(n) : '—'; };
const fmtVnd = (v) => { const n = Number(v); return Number.isFinite(n) ? new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(n) : '—'; };
</script>

<template>
  <BaseModal v-model="open" :title="'Xem lại thiết kế — ' + name">
    <div class="space-y-5 px-5 py-4">
      <div v-if="!s.agent_studio" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-4 text-center text-body text-cream-400">
        Bộ sưu tập này chưa có dữ liệu thiết kế từ Agent Studio (tạo từ brief mới có).
      </div>

      <template v-else>
        <!-- Bảng màu -->
        <section>
          <h3 class="font-display text-sm font-semibold text-brand-300">Bảng màu</h3>
          <div class="mt-2 flex flex-wrap gap-2">
            <span v-for="c in palette" :key="c.hex || c.name" class="inline-flex items-center gap-2 rounded-lg border border-ink-700 bg-ink-800 px-2.5 py-1.5 text-label" :title="c.role">
              <span class="h-5 w-5 rounded border border-ink-600" :style="{ backgroundColor: c.hex }"></span>
              <span class="text-cream-100">{{ c.name || c.role }}</span>
              <code class="text-cream-400">{{ c.hex }}</code>
            </span>
          </div>
        </section>

        <!-- Bảng mood -->
        <section v-if="moodboard.length">
          <h3 class="font-display text-sm font-semibold text-brand-300">Bảng mood ({{ moodboard.length }} ô)</h3>
          <div class="mt-2 grid grid-cols-4 gap-1.5 sm:grid-cols-6 md:grid-cols-8">
            <div v-for="m in moodboard" :key="m.id" class="aspect-square rounded-md border border-ink-700" :style="{ backgroundColor: m.color || '#b9c8c2' }" :title="(m.caption || '')" role="img" :aria-label="moodLabel(m.label) + (m.caption ? ': ' + m.caption : '')">
              <span class="block px-1 pt-0.5 text-tiny font-semibold leading-3 text-white/90">{{ moodLabel(m.label) }}</span>
            </div>
          </div>
        </section>

        <!-- Cơ cấu danh mục -->
        <section v-if="categories.length">
          <h3 class="font-display text-sm font-semibold text-brand-300">Cơ cấu danh mục ({{ totalSkus }} SKU)</h3>
          <ul class="mt-2 space-y-1.5">
            <li v-for="c in categories" :key="c.category" class="rounded-lg bg-ink-800 px-3 py-2 text-label">
              <span class="flex items-center justify-between"><span class="font-semibold text-cream-100">{{ c.category }}</span><span class="text-brand-200">{{ c.count }} SKU · {{ c.share || 0 }}%</span></span>
              <span v-if="c.rationale" class="mt-0.5 block text-cream-400">{{ c.rationale }}</span>
            </li>
          </ul>
        </section>

        <!-- Kế hoạch sản xuất -->
        <section v-if="planTotals">
          <h3 class="font-display text-sm font-semibold text-brand-300">Kế hoạch sản xuất</h3>
          <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2"><p class="text-tiny text-cream-400">Tổng sản xuất</p><p class="mt-1 text-base font-semibold tabular-nums text-cream-100">{{ fmt(planTotals.units) }} cái</p></div>
            <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2"><p class="text-tiny text-cream-400">Vải cần đặt</p><p class="mt-1 text-base font-semibold tabular-nums text-cream-100">{{ fmt(planTotals.fabric_order_m) }} m</p></div>
            <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2"><p class="text-tiny text-cream-400">Vốn cần</p><p class="mt-1 text-base font-semibold tabular-nums text-cream-100">{{ fmtVnd(planTotals.capital_needed_vnd) }}</p></div>
            <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2"><p class="text-tiny text-cream-400">Lãi gộp</p><p class="mt-1 text-base font-semibold tabular-nums text-ok">{{ fmtVnd(planTotals.profit_vnd) }} <span class="text-tiny">({{ planTotals.margin_pct }}%)</span></p></div>
          </div>
          <ul v-if="planNotes.length" class="mt-2 space-y-0.5 text-label text-cream-300">
            <li v-for="(n, i) in planNotes" :key="i">• {{ n }}</li>
          </ul>
        </section>

        <!-- Trend đã chọn -->
        <section v-if="trends.length">
          <h3 class="font-display text-sm font-semibold text-brand-300">Hướng đã chọn</h3>
          <p class="mt-1.5 leading-5"><span v-for="(t, i) in trends" :key="t.id" class="text-label text-cream-200">{{ t.title }}<span v-if="i < trends.length - 1" class="text-cream-400"> · </span></span></p>
        </section>
      </template>
    </div>
  </BaseModal>
</template>
