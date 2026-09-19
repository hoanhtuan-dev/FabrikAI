<script setup>
/**
 * Agent Studio — thiết kế lại toàn diện flow TrendRadar → CollectionBot → Canvas.
 *
 * Ba bước rõ ràng:
 *   1. Tín hiệu  — đọc radar, lọc, chọn trend.
 *   2. Định hướng — prompt + trend → brief, mood board, cấu trúc, phối, size, giá.
 *   3. Thực thi  — Canvas Kit: prompt, tỉ lệ, biến thể, negative, chi phí → tạo ảnh / tạo bộ sưu tập.
 *
 * Nguyên tắc:
 *   · một workspace lớn thay vì popup chật, rail tiến trình + vùng nội dung + action bar,
 *   · tab/step theo .seg/.seg-btn, a11y đầy đủ (tablist/tabpanel/aria-live/role=alert),
 *   · nhãn tiếng Việt thống nhất; dữ liệu demo/local nói thẳng,
 *   · trạng thái brief cũ, tìm/lọc trend, copy prompt/màu, bộ đếm ký tự, Ctrl+Enter,
 *   · không tự đổi resolution hay ghi đè negative prompt người dùng đã đặt.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import BaseModal from './BaseModal.vue';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const prompt = ref('');
const promptInput = ref(null);
const collectionError = ref('');
const trendQuery = ref('');
const trendCategory = ref('all');
const lifecycleFilter = ref('all');
const sizePreset = ref('standard');
const briefTab = ref('overview');
const canvasLang = ref('vi');
const canvas = ref({ ratio: '4:5', variantCount: 2, useNegative: true, negativePrompt: '' });

const STEPS = [
  { id: 'radar', label: 'Tín hiệu', hint: 'Đọc xu hướng và chọn hướng đi', icon: 'scan' },
  { id: 'brief', label: 'Định hướng', hint: 'Dựng brief, mood board và cấu trúc', icon: 'briefcase' },
  { id: 'canvas', label: 'Thực thi', hint: 'Chốt prompt và tạo ảnh', icon: 'wand' },
];
const BRIEF_TABS = [
  { id: 'overview', label: 'Tổng quan' },
  { id: 'moodboard', label: 'Mood board' },
  { id: 'structure', label: 'Cấu trúc' },
  { id: 'fit', label: 'Phối & size' },
];
const SIZE_PRESETS = [
  { id: 'standard', label: 'Chuẩn S–XL', values: { S: 20, M: 35, L: 30, XL: 15 } },
  { id: 'women', label: 'Nữ ưu tiên S–M', values: { XS: 10, S: 30, M: 35, L: 20, XL: 5 } },
  { id: 'unisex', label: 'Unisex', values: { S: 25, M: 35, L: 30, XL: 10 } },
];
const RATIO_OPTIONS = ['1:1', '4:5', '3:4', '9:16', '4:3'];
const CATEGORY_LABELS = { color: 'Màu sắc', silhouette: 'Dáng', fabric: 'Chất liệu', price: 'Giá', detail: 'Chi tiết' };
const LIFECYCLE_LABELS = { emerging: 'Mới nổi', peak: 'Đang đỉnh', declining: 'Giảm dần' };

const step = computed({
  get: () => store.designAgentStep || 'radar',
  set: (value) => store.setDesignAgentStep(value),
});
const stepIndex = computed(() => Math.max(0, STEPS.findIndex((item) => item.id === step.value)));
const radar = computed(() => store.trendRadar);
const collection = computed(() => store.collectionBrief);
const regions = computed(() => (radar.value?.regions?.length ? radar.value.regions : [
  { id: 'all', name: 'Toàn quốc' },
  { id: 'hcm', name: 'TP.HCM' },
  { id: 'hanoi', name: 'Hà Nội' },
  { id: 'danang', name: 'Đà Nẵng' },
]));
const selectedRegion = computed({
  get: () => radar.value?.region || 'all',
  set: (region) => { store.selectedTrendIds = []; loadRadar(region); },
});
const trends = computed(() => radar.value?.trends || []);
const sources = computed(() => radar.value?.sources || []);
const sourceMode = computed(() => radar.value?.source_mode || 'demo');
const summaryItems = computed(() => {
  const summary = radar.value?.summary || {};
  const labels = {
    tracked_attributes: 'Thuộc tính theo dõi',
    images_analyzed_monthly: 'Ảnh phân tích / tháng (CV chưa bật)',
    active_trends: 'Xu hướng đang theo dõi',
    internal_products: 'Sản phẩm nội bộ',
    internal_generations: 'Lịch sử tạo ảnh',
  };
  return Object.entries(summary).map(([key, value]) => ({ key, label: labels[key] || key, value }));
});
const selectedTrendIds = computed(() => (store.selectedTrendIds || []).map(String));
const selectedTrendCount = computed(() => selectedTrendIds.value.length);
const selectedTrendObjects = computed(() => {
  const ids = new Set(selectedTrendIds.value);
  return trends.value.filter((trend) => ids.has(String(trend.id)));
});
const trendCategories = computed(() => {
  const counts = new Map();
  trends.value.forEach((trend) => {
    const id = String(trend.category || 'other');
    counts.set(id, (counts.get(id) || 0) + 1);
  });
  return [...counts.entries()].map(([id, count]) => ({ id, count, label: CATEGORY_LABELS[id] || id }));
});
const lifecycles = computed(() => ['emerging', 'peak', 'declining'].map((id) => ({
  id,
  label: LIFECYCLE_LABELS[id],
  count: trends.value.filter((trend) => trend.lifecycle === id).length,
})).filter((row) => row.count > 0));
const visibleTrends = computed(() => {
  const query = trendQuery.value.trim().toLowerCase();
  return trends.value.filter((trend) => {
    if (trendCategory.value !== 'all' && String(trend.category || '') !== trendCategory.value) return false;
    if (lifecycleFilter.value !== 'all' && String(trend.lifecycle || '') !== lifecycleFilter.value) return false;
    if (!query) return true;
    return [trend.title, trend.description, trend.recommended_action, trend.category]
      .map((value) => String(value || '').toLowerCase()).join(' ').includes(query);
  });
});
const palette = computed(() => collection.value?.palette || []);
const moodboardItems = computed(() => collection.value?.moodboard?.items || []);
const categoryRows = computed(() => collection.value?.structure?.categories || []);
const outfitRows = computed(() => collection.value?.outfit_matching || []);
const sizeRows = computed(() => collection.value?.size_distribution || []);
const priceBand = computed(() => collection.value?.price_bands || null);
const canvasSettings = computed(() => collection.value?.canvas || null);
const currentBriefInput = computed(() => ({
  prompt: prompt.value.trim(),
  region: selectedRegion.value,
  trend_ids: selectedTrendIds.value,
}));
const briefStale = computed(() => store.collectionBriefStale(currentBriefInput.value));
const sizeDistribution = computed(() => (SIZE_PRESETS.find((item) => item.id === sizePreset.value) || SIZE_PRESETS[0]).values);
const canvasPrompt = computed(() => {
  if (!collection.value) return prompt.value.trim();
  return canvasLang.value === 'en'
    ? (collection.value.prompt_en || collection.value.canvas?.prompt_en || '')
    : (collection.value.prompt_vi || collection.value.canvas?.prompt_vi || '');
});
const estimatedCredits = computed(() => Math.max(1, Number(store.planCostImage) || 1) * Math.max(1, Number(canvas.value.variantCount) || 1));
const readiness = computed(() => ({
  radar: radar.value ? 'done' : (store.trendRadarLoading ? 'loading' : 'ready'),
  brief: collection.value && !briefStale.value ? 'done' : (radar.value ? 'ready' : 'locked'),
  canvas: collection.value && !briefStale.value ? 'ready' : 'locked',
}));

// ── NHẬN BIẾT MODEL: hai agent chạy bằng AI hay bằng engine tất định? ──────────
// Backend trả khối `model` (mode/provider/model/candidates/latency/reason) để giao diện nói
// THẬT đang dùng gì — trước đây Agent Studio chạy thuần rule-based và không hề cho biết điều đó.
const MODEL_REASON_LABELS = {
  no_model_key: 'Chưa có model/khoá nào dùng được cho nhóm “prompt” nên hai agent đang chạy engine tất định.',
  model_error: 'Model không phản hồi — đã tự quay về engine tất định (kết quả vẫn đầy đủ).',
  invalid_output: 'Model trả về dữ liệu không dùng được — đã tự quay về engine tất định.',
  ai_disabled: 'Bạn đang tắt suy luận AI nên hai agent chạy engine tất định.',
};
const activeModel = computed(() => collection.value?.model || radar.value?.model || null);
const modelReady = computed(() => activeModel.value?.mode === 'ai');
const modelShort = computed(() => {
  const m = activeModel.value;
  if (!m) return 'AI: đang kiểm tra…';
  if (m.mode === 'ai') return 'AI · ' + (m.provider || '') + (m.model ? ' · ' + m.model : '');
  return 'Engine tất định';
});
const modelTitle = computed(() => {
  const m = activeModel.value;
  if (!m) return 'Chưa có thông tin model — mở bước Tín hiệu để đọc radar.';
  if (m.mode === 'ai') {
    return 'Suy luận do ' + m.provider + ':' + m.model + ' (nhóm công việc “' + (m.group || 'prompt') + '”)'
      + (m.cached ? ' · lấy từ cache 10 phút' : (m.latency_ms != null ? ' · ' + m.latency_ms + ' ms' : ''));
  }
  return MODEL_REASON_LABELS[m.reason] || 'Đang chạy engine tất định.';
});
const modelCandidates = computed(() => activeModel.value?.available || []);
const aiToggleTitle = computed(() => (store.designAgentAi
  ? 'Đang BẬT: mỗi lần đọc radar/tạo brief sẽ gọi model của nhóm “prompt”.'
  : 'Đang TẮT: chỉ dùng engine tất định, không gọi model.'));
const directions = computed(() => radar.value?.directions || []);
const appliedAi = computed(() => {
  const a = collection.value?.ai_applied;
  if (!a) return [];
  const rows = [];
  if (a.narrative) rows.push('DNA thương hiệu');
  if (a.brief) rows.push('brief');
  if (a.moodboard_captions) rows.push(a.moodboard_captions + ' caption mood board');
  if (a.category_rationale) rows.push('lý do cơ cấu danh mục');
  if (a.outfit_goals) rows.push('mục tiêu phối đồ');
  if (a.prompts) rows.push('prompt ảnh');
  if (a.next_steps) rows.push('bước tiếp theo');
  return rows;
});
/** Brief hiện tại được dựng ở chế độ khác với công tắc AI hiện tại? */
const briefModeMismatch = computed(() => {
  const mode = collection.value?.model?.mode;
  if (mode !== 'ai' && mode !== 'rule') return false;
  return mode !== (store.designAgentAi ? 'ai' : 'rule');
});
function directionConfidence(value) {
  return value == null ? null : Math.round(Number(value) * 100);
}
function directionPriceLabel(value) {
  return { entry: 'Entry', mid: 'Mid-range', premium: 'Premium' }[value] || null;
}
function trendNameById(id) {
  const found = trends.value.find((trend) => String(trend.id) === String(id));
  return found ? trendTitle(found) : String(id);
}
/** Chọn nhanh các trend mà 3 định hướng mạnh nhất đang nhắc tới. */
function focusDirections() {
  const ids = new Set();
  const ranked = directions.value.filter((row) => row.source === 'ai');
  (ranked.length ? ranked : directions.value).slice(0, 3)
    .forEach((row) => (row.trend_ids || []).forEach((id) => ids.add(String(id))));
  if (!ids.size) return;
  store.selectedTrendIds = Array.from(ids).slice(0, 6);
}
function toggleAi() {
  store.setDesignAgentAi(!store.designAgentAi);
  loadRadar(selectedRegion.value, { force: true });
}

function setStep(id) {
  store.setDesignAgentStep(id);
  if (id === 'radar' && !store.trendRadar) loadRadar(selectedRegion.value);
}

async function loadRadar(region = selectedRegion.value || 'all', options = {}) {
  try { await store.loadTrendRadar(region, options); } catch (error) { /* store giữ lỗi */ }
}

function toggleTrend(id) {
  const value = String(id);
  const ids = new Set(selectedTrendIds.value);
  if (ids.has(value)) ids.delete(value); else ids.add(value);
  store.selectedTrendIds = Array.from(ids);
}
function clearTrends() { store.selectedTrendIds = []; }
function selectSuggested() {
  store.selectedTrendIds = [...trends.value].sort((a, b) => (b.momentum || 0) - (a.momentum || 0)).slice(0, 3).map((trend) => String(trend.id));
}
function trendTitle(trend) { return trend?.title || trend?.id || 'Xu hướng'; }
function categoryLabel(value) { return CATEGORY_LABELS[value] || value || 'Khác'; }
function lifecycleLabel(value) { return LIFECYCLE_LABELS[value] || value || 'Đang theo dõi'; }
function lifecycleClass(value) {
  if (value === 'emerging') return 'text-emerald-300';
  if (value === 'peak') return 'text-brand-300';
  return 'text-cream-300/50';
}
function sourceMethod(source) { return source.status === 'local' ? 'Dữ liệu nội bộ' : 'Chưa kết nối thực tế'; }
function sourceFrequency(source) { return source.status === 'local' ? 'Theo dữ liệu shop' : 'Bản demo'; }
function formatNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? new Intl.NumberFormat('vi-VN').format(number) : '—';
}
function formatVnd(value) {
  const number = Number(value);
  return Number.isFinite(number)
    ? new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(number)
    : '—';
}

async function createBrief() {
  collectionError.value = '';
  const value = prompt.value.trim();
  if (value.length < 3) {
    collectionError.value = 'Vui lòng nhập prompt tiếng Việt tối thiểu 3 ký tự.';
    return false;
  }
  try {
    await store.createCollectionBrief({
      prompt: value,
      region: selectedRegion.value,
      trend_ids: selectedTrendIds.value,
      size_distribution: sizeDistribution.value,
    });
    return true;
  } catch (error) {
    collectionError.value = store.collectionBriefError || error.message || 'Không tạo được brief bộ sưu tập.';
    return false;
  }
}

async function advance() {
  if (step.value === 'radar') {
    setStep('brief');
    await nextTick();
    promptInput.value?.focus();
    return;
  }
  if (step.value === 'brief') {
    if (!collection.value || briefStale.value) {
      const ok = await createBrief();
      if (!ok) return;
    }
    setStep('canvas');
    return;
  }
  applyCanvas();
}
function back() {
  if (step.value === 'canvas') setStep('brief');
  else if (step.value === 'brief') setStep('radar');
}
function applyCanvas() {
  const value = canvasPrompt.value;
  if (!value) {
    collectionError.value = 'Chưa có prompt để áp dụng vào Canvas.';
    return;
  }
  store.applyAgentPrompt(value, {
    ratio: canvas.value.ratio,
    variant_count: canvas.value.variantCount,
    negative_prompt: canvas.value.useNegative ? canvas.value.negativePrompt : '',
  });
}
async function createCollection() {
  const payload = collection.value?.project_payload;
  if (!payload) {
    collectionError.value = 'Chưa có dữ liệu bộ sưu tập để tạo.';
    return;
  }
  await store.createCollectionFromBrief(payload);
}
async function copyText(value, label) {
  const text = String(value || '').trim();
  if (!text) { store.toast('Không có nội dung để sao chép.', 'error'); return; }
  try { await navigator.clipboard.writeText(text); store.toast('Đã sao chép ' + label + '.'); }
  catch (error) { store.toast('Không sao chép được — hãy chọn và sao chép thủ công.', 'error'); }
}

watch(prompt, () => { collectionError.value = ''; store.collectionBriefError = ''; });
watch(collection, (value) => {
  const settings = value?.canvas || {};
  canvas.value = {
    ratio: RATIO_OPTIONS.includes(settings.ratio) ? settings.ratio : '4:5',
    variantCount: Math.max(1, Math.min(4, Number(settings.variant_count) || 2)),
    useNegative: !!settings.negative_prompt,
    negativePrompt: String(settings.negative_prompt || ''),
  };
}, { immediate: true });
watch(() => store.designAgentOpen, (open) => {
  if (open && !store.trendRadar) loadRadar(selectedRegion.value);
});
</script>

<template>
  <BaseModal
    v-model="store.designAgentOpen"
    full
    height="min(94vh, 960px)"
    title="Agent Studio — TrendRadar → CollectionBot → Canvas"
  >
    <div class="flex h-full min-h-0 flex-col">
      <!-- Thanh tiến trình + trạng thái -->
      <header class="shrink-0 border-b border-ink-700 bg-ink-900/80 px-4 py-3 sm:px-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-brand-300">Agent Studio</p>
            <p class="mt-0.5 truncate text-sm font-semibold text-cream-100">Từ tín hiệu xu hướng đến ảnh hoàn chỉnh</p>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-[10px]">
            <span
              class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 font-semibold"
              :class="modelReady ? 'bg-emerald-500/15 text-emerald-200' : 'bg-amber-500/15 text-amber-200'"
              :title="modelTitle"
            >
              <span class="h-1.5 w-1.5 rounded-full" :class="modelReady ? 'bg-emerald-300' : 'bg-amber-300'"></span>
              {{ modelShort }}
            </span>
            <span class="rounded-full bg-amber-500/15 px-2 py-0.5 font-semibold text-amber-200">Nguồn: {{ sourceMode }}</span>
            <span class="rounded-full bg-emerald-500/15 px-2 py-0.5 font-semibold text-emerald-200">Nội bộ: local</span>
            <span v-if="selectedTrendCount" class="rounded-full bg-brand-500/15 px-2 py-0.5 font-semibold text-brand-100">{{ selectedTrendCount }} trend đã chọn</span>
            <button
              type="button"
              class="motion-ui rounded-full border px-2 py-0.5 font-semibold transition"
              :class="store.designAgentAi ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200 hover:bg-emerald-500/20' : 'border-ink-600 bg-ink-800 text-cream-300/70 hover:bg-ink-700'"
              :aria-pressed="store.designAgentAi"
              :title="aiToggleTitle"
              @click="toggleAi"
            >
              Suy luận AI: {{ store.designAgentAi ? 'BẬT' : 'TẮT' }}
            </button>
          </div>
        </div>

        <!-- Vì sao đang chạy tất định? Nói thẳng lý do + nơi cấu hình, không để người dùng đoán. -->
        <p
          v-if="activeModel && !modelReady"
          role="status"
          class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-[11px] leading-5 text-amber-100"
        >
          <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
          <span>{{ modelTitle }}</span>
          <span v-if="!store.designAgentAi" class="text-amber-200/80">Bật «Suy luận AI» ở trên để dùng model đã cấu hình.</span>
          <span v-else-if="modelCandidates.length === 0" class="text-amber-200/80">Cấu hình tại Cài đặt → Nhóm công việc → “Suy luận prompt (Giám đốc sáng tạo / Thuật sỹ ảo)” và thêm khoá trong Quản lý API.</span>
        </p>
        <nav class="mt-3 grid grid-cols-3 gap-1.5 lg:hidden" role="tablist" aria-label="Tiến trình thiết kế">
          <button
            v-for="(item, index) in STEPS"
            :key="item.id"
            type="button"
            role="tab"
            class="seg-btn !flex-col !items-start !gap-0.5 !px-2 !py-2 text-left"
            :class="{ 'is-active': step === item.id }"
            :aria-selected="step === item.id"
            :aria-controls="'agent-step-' + item.id"
            @click="setStep(item.id)"
          >
            <span class="text-[9px] opacity-70">Bước {{ index + 1 }}</span>
            <span class="text-[11px] font-semibold">{{ item.label }}</span>
          </button>
        </nav>
      </header>

      <div class="flex min-h-0 flex-1">
        <!-- Rail tiến trình (desktop) -->
        <nav class="hidden w-60 shrink-0 flex-col gap-1 border-r border-ink-700 bg-ink-900/40 p-3 lg:flex" aria-label="Tiến trình thiết kế">
          <button
            v-for="(item, index) in STEPS"
            :key="item.id"
            type="button"
            class="motion-ui flex w-full items-start gap-3 rounded-lg border px-3 py-3 text-left transition"
            :class="step === item.id ? 'border-brand-500/60 bg-brand-600/15' : 'border-transparent hover:border-ink-600 hover:bg-ink-800'"
            :aria-current="step === item.id ? 'step' : undefined"
            :aria-controls="'agent-step-' + item.id"
            @click="setStep(item.id)"
          >
            <span
              class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-[10px] font-bold"
              :class="readiness[item.id] === 'done' ? 'bg-emerald-500/20 text-emerald-200' : step === item.id ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-300/60'"
            >
              <StudioIcon v-if="readiness[item.id] === 'done'" name="check" size="h-3 w-3" />
              <span v-else>{{ index + 1 }}</span>
            </span>
            <span class="min-w-0">
              <span class="block text-xs font-semibold text-cream-100">{{ item.label }}</span>
              <span class="mt-0.5 block text-[10px] leading-4 text-cream-300/55">{{ item.hint }}</span>
            </span>
          </button>

          <div class="mt-3 rounded-lg border border-ink-700 bg-ink-900 p-3">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-cream-300/50">Bối cảnh hiện tại</p>
            <dl class="mt-2 space-y-1.5 text-[11px]">
              <div class="flex items-center justify-between gap-2"><dt class="text-cream-300/55">Khu vực</dt><dd class="font-semibold text-cream-100">{{ regions.find((r) => r.id === selectedRegion)?.name || selectedRegion }}</dd></div>
              <div class="flex items-center justify-between gap-2"><dt class="text-cream-300/55">Trend chọn</dt><dd class="font-semibold text-cream-100">{{ selectedTrendCount }}</dd></div>
              <div class="flex items-center justify-between gap-2"><dt class="text-cream-300/55">Brief</dt><dd class="font-semibold" :class="collection && !briefStale ? 'text-emerald-200' : collection ? 'text-amber-200' : 'text-cream-300/50'">{{ collection && !briefStale ? 'Sẵn sàng' : collection ? 'Cần cập nhật' : 'Chưa có' }}</dd></div>
              <div v-if="collection" class="flex items-center justify-between gap-2"><dt class="text-cream-300/55">SKU đề xuất</dt><dd class="font-semibold text-cream-100">{{ collection.structure?.total_skus || 0 }}</dd></div>
            </dl>
          </div>
        </nav>

        <!-- Nội dung theo bước -->
        <main class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5">
          <!-- BƯỚC 1: TÍN HIỆU -->
          <section v-if="step === 'radar'" id="agent-step-radar" role="tabpanel" aria-label="Tín hiệu" :aria-busy="store.trendRadarLoading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
              <div>
                <h2 class="text-base font-semibold text-cream-100">Tín hiệu thị trường</h2>
                <p class="mt-0.5 text-xs text-cream-300/55">Chọn 2–4 hướng phù hợp nhất với DNA shop. Chưa chọn cũng chạy được với nhóm mặc định.</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <select v-model="selectedRegion" :disabled="store.trendRadarLoading" class="input !w-auto !bg-ink-800 !py-2 !text-xs !text-cream-100">
                  <option v-for="region in regions" :key="region.id" :value="region.id">{{ region.name }}</option>
                </select>
                <button type="button" class="tool-btn" :disabled="store.trendRadarLoading" @click="loadRadar(selectedRegion, { force: true })">
                  <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="store.trendRadarLoading ? 'animate-spin' : ''" /> Tải lại
                </button>
              </div>
            </div>

            <div v-if="store.trendRadarError" role="alert" class="mb-4 flex gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-xs text-red-200">
              <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ store.trendRadarError }}</span>
            </div>

            <div v-if="radar" class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
              <div v-for="item in summaryItems.slice(0, 4)" :key="item.key" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
                <p class="text-[10px] leading-4 text-cream-300/50">{{ item.label }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatNumber(item.value) }}</p>
              </div>
            </div>

            <p v-if="store.trendRadarLoading && store.designAgentAi" class="mb-3 flex items-center gap-2 text-[11px] text-brand-200" role="status" aria-live="polite">
              <StudioIcon name="sparkles" size="h-3.5 w-3.5" class="animate-pulse" /> Đang gọi model của nhóm “prompt” để viết định hướng — có thể mất vài giây…
            </p>

            <div v-if="store.trendRadarLoading && !radar" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="status" aria-live="polite">
              <span class="sr-only">{{ store.designAgentAi ? 'Đang gọi model để suy luận xu hướng…' : 'Đang tải TrendRadar…' }}</span>
              <div v-for="i in 6" :key="i" class="h-40 animate-pulse rounded-xl border border-ink-700 bg-ink-800"></div>
            </div>

            <template v-else-if="radar">
              <!-- ĐỊNH HƯỚNG: phần suy luận (AI hoặc tất định) — nói rõ nguồn của từng hướng. -->
              <div v-if="directions.length" class="mb-5 rounded-xl border border-ink-700 bg-ink-900/70 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-cream-100">Định hướng từ TrendRadar ({{ directions.length }})</h3>
                    <p class="mt-0.5 text-[11px] leading-5 text-cream-300/55">
                      {{ modelReady
                        ? 'Model viết trên đúng dữ liệu mẫu bên dưới — số liệu không do AI tạo.'
                        : 'Engine tất định dựng từ catalog mẫu (chưa dùng model).' }}
                    </p>
                  </div>
                  <button type="button" class="tool-btn" title="Chọn các trend mà 3 định hướng mạnh nhất đang nhắc tới" @click="focusDirections">
                    <StudioIcon name="target" size="h-3 w-3" /> Chọn trend theo 3 hướng đầu
                  </button>
                </div>
                <div class="mt-3 grid gap-2.5 lg:grid-cols-2">
                  <article v-for="row in directions" :key="row.id" class="rounded-lg border border-ink-700 bg-ink-800 p-3.5">
                    <div class="flex items-start justify-between gap-2">
                      <h4 class="text-xs font-semibold leading-5 text-cream-100">{{ row.title }}</h4>
                      <span
                        class="shrink-0 rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide"
                        :class="row.source === 'ai' ? 'bg-emerald-500/15 text-emerald-200' : 'bg-ink-700 text-cream-300/60'"
                      >{{ row.source === 'ai' ? 'AI' : 'tất định' }}</span>
                    </div>
                    <p v-if="row.thesis" class="mt-1.5 text-[11px] leading-5 text-cream-200">{{ row.thesis }}</p>
                    <dl class="mt-2 space-y-1 text-[10px] leading-4">
                      <div v-if="row.why_now" class="flex gap-1.5"><dt class="shrink-0 font-semibold text-brand-200">Vì sao:</dt><dd class="text-cream-300/70">{{ row.why_now }}</dd></div>
                      <div v-if="row.action" class="flex gap-1.5"><dt class="shrink-0 font-semibold text-brand-200">Việc làm:</dt><dd class="text-cream-300/70">{{ row.action }}</dd></div>
                      <div v-if="row.risk" class="flex gap-1.5"><dt class="shrink-0 font-semibold text-amber-200">Rủi ro:</dt><dd class="text-cream-300/70">{{ row.risk }}</dd></div>
                    </dl>
                    <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                      <span v-if="directionPriceLabel(row.price_band)" class="rounded bg-ink-700 px-1.5 py-0.5 text-[9px] text-cream-200">{{ directionPriceLabel(row.price_band) }}</span>
                      <span v-if="directionConfidence(row.confidence) !== null" class="rounded bg-ink-700 px-1.5 py-0.5 text-[9px] text-cream-200">Tin cậy {{ directionConfidence(row.confidence) }}%</span>
                      <button
                        v-for="id in row.trend_ids"
                        :key="id"
                        type="button"
                        class="motion-ui rounded border px-1.5 py-0.5 text-[9px] transition"
                        :class="selectedTrendIds.includes(String(id)) ? 'border-brand-500/60 bg-brand-500/15 text-brand-100 hover:bg-brand-500/25' : 'border-ink-600 text-cream-300/70 hover:bg-ink-700'"
                        @click="toggleTrend(id)"
                      >{{ trendNameById(id) }}</button>
                    </div>
                  </article>
                </div>
              </div>

              <div class="mb-3 flex flex-wrap items-center gap-2">
                <div class="relative min-w-[12rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-cream-300/50" />
                  <label for="trend-search" class="sr-only">Tìm xu hướng</label>
                  <input id="trend-search" v-model="trendQuery" type="search" class="input !py-2 !pl-8 !text-xs" placeholder="Tìm theo tên, mô tả, hành động…">
                </div>
                <button type="button" class="tool-btn" :class="{ 'is-active': trendCategory === 'all' }" @click="trendCategory = 'all'">Tất cả ({{ trends.length }})</button>
                <button v-for="category in trendCategories" :key="category.id" type="button" class="tool-btn" :class="{ 'is-active': trendCategory === category.id }" @click="trendCategory = category.id">{{ category.label }} ({{ category.count }})</button>
                <button v-for="row in lifecycles" :key="row.id" type="button" class="tool-btn" :class="{ 'is-active': lifecycleFilter === row.id }" @click="lifecycleFilter = lifecycleFilter === row.id ? 'all' : row.id">{{ row.label }} ({{ row.count }})</button>
                <button type="button" class="tool-btn" title="Chọn nhanh 3 trend có đà tăng cao nhất" @click="selectSuggested"><StudioIcon name="zap" size="h-3 w-3" /> Gợi ý 3</button>
                <button v-if="selectedTrendCount" type="button" class="tool-btn" @click="clearTrends"><StudioIcon name="trash" size="h-3 w-3" /> Bỏ chọn</button>
              </div>

              <div v-if="visibleTrends.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                  v-for="trend in visibleTrends"
                  :key="trend.id"
                  type="button"
                  class="group motion-ui relative overflow-hidden rounded-xl border bg-ink-900 p-4 text-left transition hover:border-brand-500/70 hover:bg-ink-800"
                  :class="selectedTrendIds.includes(String(trend.id)) ? 'border-brand-500 ring-1 ring-brand-500/50' : 'border-ink-700'"
                  :aria-pressed="selectedTrendIds.includes(String(trend.id))"
                  @click="toggleTrend(trend.id)"
                >
                  <span class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: trend.color || '#b9c8c2' }"></span>
                  <span class="flex items-start justify-between gap-3">
                    <span class="min-w-0">
                      <span class="flex flex-wrap items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-cream-300/50">
                        {{ categoryLabel(trend.category) }}
                        <span class="rounded bg-ink-800 px-1.5 py-0.5 normal-case tracking-normal" :class="lifecycleClass(trend.lifecycle)">{{ lifecycleLabel(trend.lifecycle) }}</span>
                        <span v-if="trend.evidence_mode === 'demo'" class="rounded bg-amber-500/15 px-1.5 py-0.5 normal-case tracking-normal text-amber-200">mẫu</span>
                      </span>
                      <span class="mt-1.5 block text-sm font-semibold text-cream-100">{{ trendTitle(trend) }}</span>
                      <span class="mt-1 block text-[11px] leading-4 text-cream-300/60">{{ trend.description }}</span>
                    </span>
                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border" :class="selectedTrendIds.includes(String(trend.id)) ? 'border-brand-400 bg-brand-600 text-white' : 'border-ink-600 text-ink-500'">
                      <StudioIcon :name="selectedTrendIds.includes(String(trend.id)) ? 'check' : 'square'" size="h-3 w-3" />
                    </span>
                  </span>
                  <span class="mt-3 block text-[10px] text-cream-300/45">Đà tăng {{ trend.momentum ?? 0 }} · tin cậy {{ Math.round((trend.confidence || 0) * 100) }}% · {{ formatNumber(trend.evidence_count) }} bằng chứng (mẫu)</span>
                  <span class="mt-1.5 block h-1 overflow-hidden rounded bg-ink-700"><span class="block h-full bg-gradient-to-r from-brand-500 to-amber-300" :style="{ width: Math.min(100, Number(trend.momentum || 0)) + '%' }"></span></span>
                  <span class="mt-2 block text-[11px] leading-4 text-cream-300/60">{{ trend.recommended_action }}</span>
                </button>
              </div>
              <p v-else class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-6 text-center text-xs text-cream-300/55">Không có xu hướng nào khớp bộ lọc hiện tại.</p>

              <details class="mt-5 rounded-xl border border-ink-700 bg-ink-900/70 p-4">
                <summary class="cursor-pointer text-xs font-semibold text-cream-200">Nguồn dữ liệu &amp; phương pháp ({{ sources.length }} nguồn)</summary>
                <p class="mt-2 text-[11px] leading-5 text-cream-300/60">Nguồn ngoài đang ở chế độ demo; dữ liệu nội bộ là project/generation của chính tài khoản. Không có scraping hay POS/ERP thật trong bản này.</p>
                <div class="mt-3 overflow-x-auto">
                  <table class="w-full min-w-[30rem] text-left text-[11px]">
                    <thead><tr class="border-b border-ink-700 text-cream-300/50"><th class="pb-2 pr-2 font-semibold">Nguồn</th><th class="pb-2 pr-2 font-semibold">Trạng thái</th><th class="pb-2 font-semibold">Kênh / nhịp</th></tr></thead>
                    <tbody class="divide-y divide-ink-800">
                      <tr v-for="source in sources" :key="source.id">
                        <td class="py-2.5 pr-2"><span class="block font-semibold text-cream-100">{{ source.name }}</span><span class="block text-cream-300/50">{{ source.channels }}</span></td>
                        <td class="py-2.5 pr-2"><span class="inline-flex rounded-full px-2 py-0.5 font-semibold" :class="source.status === 'local' ? 'bg-emerald-500/15 text-emerald-200' : 'bg-amber-500/15 text-amber-200'">{{ source.status === 'local' ? 'local' : 'demo' }}</span></td>
                        <td class="py-2.5 text-cream-300/60"><span class="block">{{ sourceMethod(source) }}</span><span class="block text-cream-300/45">{{ sourceFrequency(source) }}</span></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </details>
            </template>
          </section>

          <!-- BƯỚC 2: ĐỊNH HƯỚNG -->
          <section v-else-if="step === 'brief'" id="agent-step-brief" role="tabpanel" aria-label="Định hướng" :aria-busy="store.collectionBriefLoading" class="grid gap-5 xl:grid-cols-[minmax(320px,380px)_1fr]">
            <div class="space-y-4">
              <div class="card p-4">
                <h2 class="text-sm font-semibold text-cream-100">Brief đầu vào</h2>
                <p class="mt-0.5 text-xs text-cream-300/55">Mô tả khách hàng, dịp mặc, chất liệu, màu sắc hoặc định vị giá.</p>
                <label for="collection-prompt" class="label mt-4">Prompt tiếng Việt</label>
                <textarea id="collection-prompt" ref="promptInput" v-model="prompt" rows="5" maxlength="2000" aria-describedby="collection-prompt-help" class="input w-full resize-none !text-sm" placeholder="Ví dụ: Bộ sưu tập công sở mùa hè cho nữ văn phòng, ưu tiên linen thoáng và màu pastel dịu…" @keydown.ctrl.enter="createBrief"></textarea>
                <div class="mt-1.5 flex items-start justify-between gap-3"><p id="collection-prompt-help" class="text-[10px] leading-4 text-cream-300/45">Ctrl+Enter để tạo brief.</p><span class="shrink-0 text-[10px] tabular-nums text-cream-300/45">{{ prompt.length }}/2000</span></div>

                <div class="mt-4">
                  <div class="mb-2 flex items-center justify-between"><span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Trend đã chọn</span><span class="text-[10px] text-cream-300/45">{{ selectedTrendCount }} / {{ trends.length }}</span></div>
                  <div v-if="selectedTrendObjects.length" class="flex flex-wrap gap-2">
                    <span v-for="trend in selectedTrendObjects" :key="trend.id" class="flex items-center gap-1.5 rounded-lg border border-brand-500/40 bg-brand-500/10 px-2 py-1 text-[11px] text-brand-100"><span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: trend.color }"></span>{{ trendTitle(trend) }}</span>
                  </div>
                  <p v-else class="text-xs leading-5 text-cream-300/50">Chưa chọn trend — CollectionBot dùng nhóm mặc định.</p>
                </div>

                <div class="mt-4">
                  <span class="text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Bảng size dự kiến</span>
                  <div class="seg mt-2">
                    <button v-for="preset in SIZE_PRESETS" :key="preset.id" type="button" class="seg-btn" :class="{ 'is-active': sizePreset === preset.id }" @click="sizePreset = preset.id">{{ preset.label }}</button>
                  </div>
                </div>

                <button type="button" class="btn-brand btn-sm mt-5 flex w-full items-center justify-center gap-2" :disabled="store.collectionBriefLoading" @click="createBrief">
                  <StudioIcon name="wand" size="h-3.5 w-3.5" :class="store.collectionBriefLoading ? 'animate-spin' : ''" />
                  {{ store.collectionBriefLoading
                    ? (store.designAgentAi ? 'Đang gọi model xây brief…' : 'Đang xây dựng brief…')
                    : (collection ? 'Tạo lại brief' : 'Tạo brief bộ sưu tập') }}
                </button>
                <div v-if="collectionError || store.collectionBriefError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-xs leading-5 text-red-200"><StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ collectionError || store.collectionBriefError }}</span></div>
                <div v-if="briefStale" role="status" class="mt-3 flex gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs leading-5 text-amber-100"><StudioIcon name="info" size="h-4 w-4" class="shrink-0" /><span>Prompt/trend đã đổi. Bấm «Tạo lại brief» để cập nhật trước khi sang Canvas.</span></div>
                <div v-else-if="briefModeMismatch" role="status" class="mt-3 flex gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs leading-5 text-amber-100"><StudioIcon name="info" size="h-4 w-4" class="shrink-0" /><span>Brief này được dựng ở chế độ {{ collection?.model?.mode === 'ai' ? 'AI' : 'tất định' }} — bấm «Tạo lại brief» nếu muốn theo đúng công tắc hiện tại.</span></div>
              </div>

              <div v-if="radar" class="card p-4">
                <h3 class="text-xs font-semibold text-cream-100">Tín hiệu thương hiệu</h3>
                <p class="mt-1.5 text-[11px] leading-5 text-cream-300/70">{{ radar.internal_brand_signal?.narrative || 'Chưa có narrative.' }}</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                  <span v-for="(item, index) in [...(radar.internal_brand_signal?.top_categories || []), ...(radar.internal_brand_signal?.top_colors || [])]" :key="index + '-' + item" class="rounded bg-brand-500/15 px-2 py-0.5 text-[10px] text-brand-100">{{ item }}</span>
                </div>
              </div>
            </div>

            <div class="min-w-0">
              <div v-if="store.collectionBriefLoading && !collection" class="grid gap-3 sm:grid-cols-2" role="status" aria-live="polite">
                <span class="sr-only">Đang xây dựng brief bộ sưu tập…</span>
                <div v-for="i in 6" :key="i" class="h-28 animate-pulse rounded-xl border border-ink-700 bg-ink-800"></div>
              </div>

              <template v-else-if="collection">
                <div class="card p-5">
                  <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><p class="text-[10px] font-semibold uppercase tracking-wide text-brand-300">{{ collection.agent || 'CollectionBot' }} · {{ collection.engine || 'rule-based-v1' }}</p><h2 class="mt-1 text-lg font-semibold text-cream-100">Bộ sưu tập đề xuất</h2></div>
                    <span class="rounded-lg bg-ink-800 px-2.5 py-1 text-[10px] text-cream-300/60">Khu vực: {{ collection.input?.region || selectedRegion }}</span>
                  </div>
                  <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[10px]">
                    <span
                      class="rounded-full px-2 py-0.5 font-semibold"
                      :class="modelReady ? 'bg-emerald-500/15 text-emerald-200' : 'bg-amber-500/15 text-amber-200'"
                      :title="modelTitle"
                    >{{ modelReady ? 'AI: ' + (collection.model?.provider || '') + ' · ' + (collection.model?.model || '') : 'Engine tất định' }}</span>
                    <span v-for="row in appliedAi" :key="row" class="rounded bg-brand-500/15 px-2 py-0.5 text-brand-100">AI viết: {{ row }}</span>
                    <span v-if="modelReady && collection.model?.latency_ms != null" class="text-cream-300/50">{{ collection.model.latency_ms }} ms</span>
                  </div>

                  <p class="mt-3 text-sm leading-6 text-cream-200">{{ collection.brief }}</p>
                  <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-[10px] uppercase tracking-wide text-cream-300/50">Tổng SKU</p><p class="mt-1 text-lg font-semibold text-cream-100">{{ collection.structure?.total_skus || 0 }}</p></div>
                    <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-[10px] uppercase tracking-wide text-cream-300/50">Dải giá</p><p class="mt-1 text-sm font-semibold text-cream-100">{{ priceBand?.recommended_label || '—' }}</p><p class="text-[10px] text-cream-300/60">{{ formatVnd(priceBand?.min_vnd) }} — {{ formatVnd(priceBand?.max_vnd) }}</p></div>
                    <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-[10px] uppercase tracking-wide text-cream-300/50">Mood board</p><p class="mt-1 text-lg font-semibold text-cream-100">{{ moodboardItems.length }}</p><p class="text-[10px] text-cream-300/60">ô màu đại diện</p></div>
                  </div>
                </div>

                <div class="seg mt-4 w-full sm:w-auto" role="tablist" aria-label="Nội dung brief">
                  <button v-for="tab in BRIEF_TABS" :key="tab.id" type="button" role="tab" class="seg-btn" :class="{ 'is-active': briefTab === tab.id }" :aria-selected="briefTab === tab.id" @click="briefTab = tab.id">{{ tab.label }}</button>
                </div>

                <div v-if="briefTab === 'overview'" class="mt-4 grid gap-4 md:grid-cols-2">
                  <div class="card p-5"><h3 class="text-sm font-semibold text-cream-100">Câu chuyện thương hiệu</h3><p class="mt-2 text-xs leading-5 text-cream-200">{{ collection.brand_narrative?.narrative || '—' }}</p></div>
                  <div class="card p-5"><h3 class="text-sm font-semibold text-cream-100">Gợi ý cấu hình Canvas</h3><div v-if="canvasSettings" class="mt-2 flex flex-wrap gap-1.5 text-[10px]"><span class="rounded bg-ink-800 px-2 py-0.5 text-cream-200">Tỉ lệ {{ canvasSettings.ratio }}</span><span class="rounded bg-ink-800 px-2 py-0.5 text-cream-200">{{ canvasSettings.variant_count }} biến thể</span><span class="rounded bg-ink-800 px-2 py-0.5 text-cream-200">Negative prompt</span></div><p class="mt-2 text-[11px] leading-5 text-cream-300/60">{{ canvasSettings?.note }}</p></div>
                </div>

                <div v-else-if="briefTab === 'moodboard'" class="mt-4 card p-5">
                  <div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold text-cream-100">Bảng mood</h3><span class="text-[10px] text-cream-300/50">{{ moodboardItems.length }} ô</span></div>
                  <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-6">
                    <div v-for="(item, index) in moodboardItems" :key="item.id || index" class="group relative aspect-square overflow-hidden rounded-lg border border-ink-700" :style="{ backgroundColor: item.color || palette[index % Math.max(1, palette.length)]?.hex || '#b9c8c2' }" role="img" :aria-label="(item.label || 'Mood ' + (index + 1)) + ': ' + (item.caption || '')" :title="item.caption || item.label || 'Mood board'">
                      <span class="absolute inset-x-0 bottom-0 p-1.5 text-[9px] font-semibold leading-3 text-white shadow-[0_-12px_16px_-8px_rgba(0,0,0,0.8)]">{{ item.label || 'Mood ' + (index + 1) }}</span>
                    </div>
                  </div>
                  <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <button v-for="(color, index) in palette" :key="color.hex || index" type="button" class="flex items-center gap-2 rounded-lg border border-ink-700 bg-ink-800 p-2 text-left" :title="'Sao chép ' + color.hex" @click="copyText(color.hex, 'mã màu ' + color.hex)">
                      <span class="h-6 w-6 shrink-0 rounded border border-white/15" :style="{ backgroundColor: color.hex }"></span><span class="min-w-0 flex-1"><span class="block truncate text-[11px] font-semibold text-cream-100">{{ color.name || 'Màu ' + (index + 1) }}</span><span class="block text-[10px] text-cream-300/55">{{ color.role || color.hex }}</span></span><code class="text-[10px] text-cream-300/60">{{ color.hex }}</code>
                    </button>
                  </div>
                </div>

                <div v-else-if="briefTab === 'structure'" class="mt-4 card p-5">
                  <h3 class="text-sm font-semibold text-cream-100">Cấu trúc danh mục</h3><p class="mt-1 text-xs text-cream-300/60">{{ collection.structure?.rationale }}</p>
                  <div class="mt-3 space-y-2.5">
                    <div v-for="row in categoryRows" :key="row.category" class="rounded-lg bg-ink-800 p-3"><div class="flex items-center justify-between text-xs"><span class="font-semibold text-cream-100">{{ row.category }}</span><span class="text-brand-200">{{ row.count }} SKU · {{ row.share || 0 }}%</span></div><div class="mt-1.5 h-1 rounded bg-ink-700"><span class="block h-full rounded bg-brand-500" :style="{ width: Math.min(100, Number(row.share || 0)) + '%' }"></span></div><p class="mt-1.5 text-[10px] leading-4 text-cream-300/55">{{ row.rationale }}</p></div>
                  </div>
                </div>

                <div v-else class="mt-4 grid gap-4 md:grid-cols-2">
                  <div class="card p-5"><h3 class="text-sm font-semibold text-cream-100">Phối outfit</h3><div class="mt-3 space-y-2.5"><div v-for="look in outfitRows" :key="look.id" class="rounded-lg border border-ink-700 bg-ink-800 p-3"><div class="flex items-center justify-between gap-2"><span class="text-xs font-semibold text-cream-100">{{ look.name }}</span><span class="text-[10px] text-cream-300/50">{{ look.goal }}</span></div><p class="mt-1.5 text-[11px] leading-4 text-cream-300/70">{{ (look.items || []).join(' · ') }}</p><div class="mt-2 flex gap-1"><span v-for="(color, index) in (look.palette || []).slice(0, 3)" :key="index" class="h-3 flex-1 rounded" :style="{ backgroundColor: color }"></span></div></div></div></div>
                  <div class="card p-5"><h3 class="text-sm font-semibold text-cream-100">Phân bổ size</h3><div class="mt-3 space-y-2.5"><div v-for="row in sizeRows" :key="row.size" class="flex items-center gap-3 text-xs"><span class="w-8 rounded bg-ink-800 py-1 text-center font-semibold text-cream-100">{{ row.size }}</span><span class="h-2 flex-1 rounded bg-ink-800"><span class="block h-full rounded bg-brand-500" :style="{ width: Math.min(100, Number(row.share || 0)) + '%' }"></span></span><span class="w-20 text-right text-cream-300/60">{{ row.count }} · {{ row.share || 0 }}%</span></div></div></div>
                </div>
              </template>

              <div v-else class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-10 text-center"><StudioIcon name="briefcase" size="h-8 w-8" class="mx-auto text-brand-400/70" /><p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief bộ sưu tập</p><p class="mt-1 text-xs leading-5 text-cream-300/55">Nhập prompt ở bên trái rồi bấm «Tạo brief bộ sưu tập».</p></div>
            </div>
          </section>

          <!-- BƯỚC 3: THỰC THI (CANVAS KIT) -->
          <section v-else id="agent-step-canvas" role="tabpanel" aria-label="Thực thi">
            <div v-if="!collection" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-10 text-center">
              <StudioIcon name="wand" size="h-8 w-8" class="mx-auto text-brand-400/70" />
              <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief để chuyển sang Canvas</p>
              <p class="mt-1 text-xs text-cream-300/55">Quay lại bước Định hướng và tạo brief trước.</p>
              <button type="button" class="btn-brand btn-sm mt-4" @click="setStep('brief')">Quay lại Định hướng</button>
            </div>

            <template v-else>
              <div class="grid gap-5 xl:grid-cols-[1fr_minmax(300px,360px)]">
                <div class="space-y-4">
                  <div class="card p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <div><p class="text-[10px] font-semibold uppercase tracking-wide text-brand-300">Canvas Kit</p><h2 class="mt-1 text-lg font-semibold text-cream-100">Prompt &amp; cấu hình tạo ảnh</h2></div>
                      <div class="seg" role="tablist" aria-label="Ngôn ngữ prompt">
                        <button type="button" role="tab" class="seg-btn" :class="{ 'is-active': canvasLang === 'vi' }" :aria-selected="canvasLang === 'vi'" @click="canvasLang = 'vi'">Tiếng Việt</button>
                        <button type="button" role="tab" class="seg-btn" :class="{ 'is-active': canvasLang === 'en' }" :aria-selected="canvasLang === 'en'" @click="canvasLang = 'en'">Tiếng Anh</button>
                      </div>
                    </div>
                    <pre class="mt-3 max-h-64 overflow-y-auto whitespace-pre-wrap rounded-lg border border-ink-700 bg-ink-800 p-4 text-[12px] leading-6 text-cream-100">{{ canvasPrompt }}</pre>
                    <div class="mt-2 flex flex-wrap gap-2">
                      <button type="button" class="tool-btn" @click="copyText(canvasPrompt, 'prompt')"><StudioIcon name="copy" size="h-3 w-3" /> Sao chép prompt</button>
                      <button type="button" class="tool-btn" @click="copyText(collection.brief, 'brief')"><StudioIcon name="copy" size="h-3 w-3" /> Sao chép brief</button>
                    </div>
                  </div>

                  <div class="card p-5">
                    <h3 class="text-sm font-semibold text-cream-100">Cấu hình tạo ảnh</h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                      <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-cream-300/50">Tỉ lệ khung</p>
                        <div class="seg mt-2">
                          <button v-for="ratio in RATIO_OPTIONS" :key="ratio" type="button" class="seg-btn" :class="{ 'is-active': canvas.ratio === ratio }" @click="canvas.ratio = ratio">{{ ratio }}</button>
                        </div>
                      </div>
                      <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-cream-300/50">Số biến thể</p>
                        <div class="seg mt-2">
                          <button v-for="n in [1, 2, 3, 4]" :key="n" type="button" class="seg-btn" :class="{ 'is-active': Number(canvas.variantCount) === n }" @click="canvas.variantCount = n">{{ n }}</button>
                        </div>
                      </div>
                    </div>
                    <label class="mt-4 flex items-center gap-2 text-xs text-cream-200">
                      <input v-model="canvas.useNegative" type="checkbox" class="h-4 w-4 rounded border-ink-600 bg-ink-800">
                      Dùng negative prompt gợi ý (không ghi đè nếu bạn đã có cấu hình riêng)
                    </label>
                    <p class="mt-2 rounded-lg border border-ink-700 bg-ink-800 p-3 text-[11px] leading-5 text-cream-300/60">{{ canvas.negativePrompt || 'Chưa có negative prompt gợi ý.' }}</p>
                  </div>
                </div>

                <aside class="space-y-4">
                  <div class="card p-5">
                    <h3 class="text-sm font-semibold text-cream-100">Sẵn sàng tạo ảnh</h3>
                    <ul class="mt-3 space-y-2 text-xs">
                      <li class="flex items-center gap-2"><StudioIcon name="check" size="h-3.5 w-3.5" class="text-emerald-300" /><span class="text-cream-200">Prompt {{ canvasLang === 'en' ? 'tiếng Anh' : 'tiếng Việt' }} đã sẵn sàng</span></li>
                      <li class="flex items-center gap-2"><StudioIcon name="check" size="h-3.5 w-3.5" class="text-emerald-300" /><span class="text-cream-200">Tỉ lệ {{ canvas.ratio }} · {{ canvas.variantCount }} biến thể</span></li>
                      <li class="flex items-center gap-2"><StudioIcon :name="briefStale ? 'alertTriangle' : 'check'" size="h-3.5 w-3.5" :class="briefStale ? 'text-amber-300' : 'text-emerald-300'" /><span :class="briefStale ? 'text-amber-200' : 'text-cream-200'">{{ briefStale ? 'Brief đã cũ — nên tạo lại' : 'Brief khớp prompt/trend hiện tại' }}</span></li>
                    </ul>
                    <div class="mt-4 rounded-lg border border-ink-700 bg-ink-800 p-3">
                      <div class="flex items-center justify-between text-xs"><span class="text-cream-300/60">Chi phí ước tính</span><span class="font-semibold text-cream-100">~{{ estimatedCredits }} credit</span></div>
                      <div class="mt-1 flex items-center justify-between text-[11px]"><span class="text-cream-300/50">Credit còn lại</span><span class="text-cream-200">{{ formatNumber(store.creditsLeft) }}</span></div>
                    </div>
                    <button type="button" class="btn-brand btn-sm mt-4 flex w-full items-center justify-center gap-2" @click="applyCanvas">
                      <StudioIcon name="zap" size="h-3.5 w-3.5" /> Áp dụng &amp; mở Tạo ảnh
                    </button>
                    <button type="button" class="tool-btn mt-2 w-full justify-center !py-2.5" @click="createCollection">
                      <StudioIcon name="briefcase" size="h-3.5 w-3.5" /> Tạo bộ sưu tập từ brief
                    </button>
                    <button type="button" class="tool-btn mt-2 w-full justify-center !py-2.5" @click="setStep('brief')">
                      <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" /> Chỉnh lại brief
                    </button>
                    <div v-if="collectionError || store.collectionBriefError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-xs leading-5 text-red-200"><StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ collectionError || store.collectionBriefError }}</span></div>
                  </div>

                  <div class="card p-5">
                    <h3 class="text-sm font-semibold text-cream-100">Bộ sưu tập</h3>
                    <p class="mt-1.5 text-xs leading-5 text-cream-200">{{ collection.project_payload?.name }}</p>
                    <p class="mt-1 text-[10px] text-cream-300/50">Tạo bộ sưu tập chỉ tạo vỏ dự án; ảnh vẫn do bạn chủ động tạo trong Canvas.</p>
                  </div>
                </aside>
              </div>
            </template>
          </section>
        </main>
      </div>

      <!-- Action bar -->
      <footer class="shrink-0 border-t border-ink-700 bg-ink-900/90 px-4 py-3 sm:px-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0 text-[11px] text-cream-300/60">
            <span class="font-semibold text-cream-100">Bước {{ stepIndex + 1 }}/{{ STEPS.length }} · {{ STEPS[stepIndex].label }}</span>
            <span class="mx-1.5">·</span>
            <span v-if="step === 'radar'">{{ selectedTrendCount ? selectedTrendCount + ' trend đã chọn' : 'Chưa chọn trend' }}</span>
            <span v-else-if="step === 'brief'">{{ collection ? (briefStale ? 'Brief cần cập nhật' : 'Brief đã sẵn sàng') : 'Chưa có brief' }}</span>
            <span v-else>~{{ estimatedCredits }} credit cho {{ canvas.variantCount }} biến thể</span>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="step !== 'radar'" type="button" class="tool-btn !px-3 !py-2" @click="back"><StudioIcon name="arrowLeft" size="h-3.5 w-3.5" /> Quay lại</button>
            <button v-if="step !== 'canvas'" type="button" class="btn-brand btn-sm flex items-center gap-2" :disabled="step === 'brief' && store.collectionBriefLoading" @click="advance">
              {{ step === 'radar' ? (selectedTrendCount ? 'Phân tích thành brief' : 'Tiếp tục với mặc định') : 'Chốt brief & sang Canvas' }}
              <StudioIcon name="arrowRight" size="h-3.5 w-3.5" />
            </button>
            <button v-else type="button" class="btn-brand btn-sm flex items-center gap-2" @click="applyCanvas">
              <StudioIcon name="zap" size="h-3.5 w-3.5" /> Áp dụng vào Canvas
            </button>
          </div>
        </div>
      </footer>
    </div>
  </BaseModal>
</template>
