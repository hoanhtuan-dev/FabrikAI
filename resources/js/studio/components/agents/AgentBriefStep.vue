<script setup>
// TÁCH từ DesignAgents.vue (đợt tối ưu 2026-09-24) — BƯỚC BRIEF.
// Shell cung cấp toàn bộ trạng thái/logic qua provide(); component này chỉ inject đúng bề mặt nó dùng
// rồi giữ NGUYÊN VĂN template của bước. Xem shell để biết định nghĩa gốc.
import { inject } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';
import SourceLibraryPicker from '../SourceLibraryPicker.vue';
const store = useStudioStore();
const prompt = inject('prompt');
const promptInput = inject('promptInput');
const collectionError = inject('collectionError');
const sizePreset = inject('sizePreset');
const briefTab = inject('briefTab');
const refPickerOpen = inject('refPickerOpen');
const shopPaste = inject('shopPaste');
const shopParseError = inject('shopParseError');
const shopOpen = inject('shopOpen');
const BRIEF_TABS = inject('BRIEF_TABS');
const PLAN_FIELDS = inject('PLAN_FIELDS');
const SIZE_PRESETS = inject('SIZE_PRESETS');
const dna = inject('dna');
const refImages = inject('refImages');
const referenceNote = inject('referenceNote');
const cacheAgeLabel = inject('cacheAgeLabel');
const step = inject('step');
const radar = inject('radar');
const collection = inject('collection');
const selectedRegion = inject('selectedRegion');
const trends = inject('trends');
const selectedTrendCount = inject('selectedTrendCount');
const selectedTrendObjects = inject('selectedTrendObjects');
const palette = inject('palette');
const moodboardItems = inject('moodboardItems');
const categoryRows = inject('categoryRows');
const outfitRows = inject('outfitRows');
const sizeRows = inject('sizeRows');
const priceBand = inject('priceBand');
const canvasSettings = inject('canvasSettings');
const briefStale = inject('briefStale');
const modelReady = inject('modelReady');
const modelShort = inject('modelShort');
const modelTitle = inject('modelTitle');
const appliedAi = inject('appliedAi');
const briefModeMismatch = inject('briefModeMismatch');
const plan = inject('plan');
const planTotals = inject('planTotals');
const planWaves = inject('planWaves');
const planLines = inject('planLines');
const planSizeChart = inject('planSizeChart');
const planScenarios = inject('planScenarios');
const shopSummary = inject('shopSummary');
const shopRowCount = inject('shopRowCount');
const onPickReference = inject('onPickReference');
const removeReference = inject('removeReference');
const schedulePlan = inject('schedulePlan');
const loadPlanNow = inject('loadPlanNow');
const planFieldValue = inject('planFieldValue');
const importShopPaste = inject('importShopPaste');
const addShopRow = inject('addShopRow');
const removeShopRow = inject('removeShopRow');
const saveShop = inject('saveShop');
const exportCutSheet = inject('exportCutSheet');
const exportSizeChart = inject('exportSizeChart');
const copyCutSheet = inject('copyCutSheet');
const setStep = inject('setStep');
const trendTitle = inject('trendTitle');
const formatNumber = inject('formatNumber');
const formatVnd = inject('formatVnd');
const createBrief = inject('createBrief');
const copyText = inject('copyText');
</script>

<template>
          <section id="agent-step-brief" role="tabpanel" aria-label="Định hướng" :aria-busy="store.collectionBriefLoading" class="grid gap-5 xl:grid-cols-[minmax(320px,380px)_1fr]">
            <div class="space-y-4">
              <div class="card p-4">
                <h2 class="font-display text-base font-semibold text-brand-300">Brief đầu vào</h2>
                <p class="mt-0.5 text-xs text-cream-400">Mô tả khách hàng, dịp mặc, chất liệu, màu sắc hoặc định vị giá.</p>
                <label for="collection-prompt" class="label mt-4">Prompt tiếng Việt <span class="font-normal text-cream-400">(bắt buộc)</span></label>
                <textarea id="collection-prompt" ref="promptInput" v-model="prompt" rows="5" maxlength="2000" aria-describedby="collection-prompt-help" class="input w-full resize-none !text-sm" placeholder="Ví dụ: Bộ sưu tập công sở mùa hè cho nữ văn phòng, ưu tiên linen thoáng và màu pastel dịu…" @keydown.ctrl.enter="createBrief" @keydown.meta.enter="createBrief"></textarea>
                <div class="mt-1.5 flex items-start justify-between gap-3"><p id="collection-prompt-help" class="text-label leading-4 text-cream-400">Ctrl+Enter để tạo brief.</p><span class="shrink-0 text-label tabular-nums text-cream-400">{{ prompt.length }}/2000</span></div>

                <!-- VAI ĐỌC ẢNH: chọn tối đa 3 ảnh mẫu để AI nhìn và bám phong cách thật của shop. -->
                <div class="mt-4 rounded-lg border border-ink-700 bg-ink-900/60 p-3">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-body font-semibold text-cream-100">Ảnh mẫu để AI bám phong cách <span class="font-normal text-cream-400">(tuỳ chọn, tối đa 3)</span></p>
                    <button type="button" class="tool-btn" :disabled="refImages.length >= 3" @click="refPickerOpen = true">
                      <StudioIcon name="library" size="h-3 w-3" /> Chọn ảnh mẫu
                    </button>
                    <span v-if="refImages.length >= 3" class="text-label text-cream-400">↳ Đã đủ 3 ảnh mẫu — bỏ một ảnh nếu muốn đổi.</span>
                  </div>
                  <div v-if="refImages.length" class="mt-2 flex flex-wrap gap-2">
                    <div v-for="url in refImages" :key="url" class="relative">
                      <img :src="url" alt="Ảnh mẫu" class="h-14 w-14 rounded-lg border border-ink-600 object-cover">
                      <!-- 24x24 là ngưỡng chạm tối thiểu của WCAG 2.2 (SC 2.5.8); h-5/w-5 = 20px là quá nhỏ. -->
                      <button type="button" class="absolute -right-2 -top-2 grid h-6 w-6 place-items-center rounded-full border border-ink-600 bg-ink-800 text-cream-300" aria-label="Bỏ ảnh mẫu" @click="removeReference(url)">
                        <StudioIcon name="x" size="h-3 w-3" />
                      </button>
                    </div>
                  </div>
                  <p v-else class="mt-1.5 text-label leading-5 text-cream-400">Chọn 1-3 ảnh bạn thích — AI sẽ đọc chúng và mô tả chất liệu, tông màu, phom dáng để brief sát shop hơn.</p>
                  <p v-if="referenceNote" class="mt-2 rounded-lg border border-brand-500/30 bg-brand-500/10 px-2.5 py-2 text-label leading-5 text-cream-200">AI đọc ảnh mẫu: {{ referenceNote }}</p>
                </div>

                <SourceLibraryPicker v-model="refPickerOpen" mode="pick" @pick="onPickReference" />

                <div class="mt-4">
                  <div class="mb-2 flex items-center justify-between"><span class="text-body font-semibold uppercase tracking-wide text-cream-400">Trend đã chọn</span><span class="text-label text-cream-400">{{ selectedTrendCount }} / {{ trends.length }}</span></div>
                  <div v-if="selectedTrendObjects.length" class="flex flex-wrap gap-2">
                    <span v-for="trend in selectedTrendObjects" :key="trend.id" class="flex items-center gap-1.5 rounded-lg border border-brand-500/40 bg-brand-500/10 px-2 py-1 text-body text-brand-200"><span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: trend.color }"></span>{{ trendTitle(trend) }}</span>
                  </div>
                  <p v-else class="text-xs leading-5 text-cream-400">Chưa chọn hướng nào — hệ thống sẽ dùng nhóm mặc định.</p>
                </div>

                <div class="mt-4">
                  <span class="text-body font-semibold uppercase tracking-wide text-cream-400">Bảng size dự kiến</span>
                  <!-- Nhóm chọn-một: màu KHÔNG được là tín hiệu duy nhất ⇒ có aria-pressed cho trình đọc màn hình. -->
                  <div class="seg mt-2" role="group" aria-label="Bảng size dự kiến">
                    <button v-for="preset in SIZE_PRESETS" :key="preset.id" type="button" class="seg-btn" :class="{ 'is-active': sizePreset === preset.id }" :aria-pressed="sizePreset === preset.id" @click="sizePreset = preset.id">{{ preset.label }}</button>
                  </div>
                </div>

                <!-- MỘT hành động chính cho mỗi màn: đã có brief thì nút "Tạo lại" lùi về thứ yếu
                     (nút chính lúc đó là «Chốt brief & sang Canvas» ở thanh dưới). -->
                <button
                  type="button"
                  class="btn-sm mt-5 flex w-full items-center justify-center gap-2"
                  :class="collection ? 'tool-btn !py-2.5' : 'btn-brand'"
                  :disabled="store.collectionBriefLoading"
                  @click="createBrief()"
                >
                  <StudioIcon name="wand" size="h-3.5 w-3.5" :class="store.collectionBriefLoading ? 'animate-spin' : ''" />
                  {{ store.collectionBriefLoading
                    ? (store.designAgentAi ? 'AI đang xây brief…' : 'Đang xây dựng brief…')
                    : (collection ? 'Tạo lại brief' : 'Tạo brief bộ sưu tập') }}
                </button>
                <div v-if="collectionError || store.collectionBriefError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-xs leading-5 text-danger"><StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ collectionError || store.collectionBriefError }}</span></div>
                <div v-if="briefStale" role="status" class="mt-3 flex gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs leading-5 text-warn"><StudioIcon name="info" size="h-4 w-4" class="shrink-0" /><span>Prompt/trend đã đổi. Bấm «Tạo lại brief» để cập nhật trước khi sang Canvas.</span></div>
                <div v-else-if="briefModeMismatch" role="status" class="mt-3 flex gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs leading-5 text-warn"><StudioIcon name="info" size="h-4 w-4" class="shrink-0" /><span>Brief này được dựng ở chế độ {{ collection?.model?.mode === 'ai' ? 'AI' : 'tất định' }} — bấm «Tạo lại brief» nếu muốn theo đúng công tắc hiện tại.</span></div>
              </div>

              <div v-if="radar" class="card p-4">
                <h3 class="text-xs font-semibold text-cream-100">Tín hiệu thương hiệu</h3>
                <p class="mt-1.5 text-body leading-5 text-cream-300">{{ radar.internal_brand_signal?.narrative || 'Chưa có narrative.' }}</p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                  <span v-for="(item, index) in [...(radar.internal_brand_signal?.top_categories || []), ...(radar.internal_brand_signal?.top_colors || [])]" :key="index + '-' + item" class="rounded bg-brand-500/15 px-2 py-0.5 text-label text-brand-200">{{ item }}</span>
                </div>
              </div>

              <div class="card p-4">
                <button
                  type="button"
                  class="motion-ui flex w-full items-start justify-between gap-2 text-left transition"
                  :aria-expanded="shopOpen"
                  @click="shopOpen = !shopOpen"
                >
                  <span class="min-w-0">
                    <span class="block text-xs font-semibold text-cream-100">Dữ liệu bán hàng của shop</span>
                    <span class="mt-0.5 block text-label leading-4 text-cream-400">
                      {{ shopRowCount ? shopRowCount + ' dòng — cơ cấu SKU và dải giá bám theo số bán THẬT' : 'Nhập tay hoặc dán từ Excel để lời khuyên sát shop của bạn' }}
                    </span>
                  </span>
                  <StudioIcon name="chevronDown" size="h-3.5 w-3.5" class="mt-0.5 shrink-0 text-cream-400 transition" :class="shopOpen ? 'rotate-180' : ''" />
                </button>

                <div v-if="shopOpen" class="mt-3 space-y-3">
                  <div v-if="shopSummary && shopSummary.row_count" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-body leading-5 text-ok">
                    {{ shopSummary.narrative }}
                    <!-- Kỳ báo cáo trộn nhau: cộng dồn số bán của các kỳ khác nhau rồi kể như MỘT con số là
                         nói sai, nên phải nói ra và mời nhập lại theo cùng một kỳ. -->
                    <p v-if="shopSummary.period_days_mixed" class="mt-1.5 text-warn">
                      ↳ Dữ liệu đang trộn {{ (shopSummary.periods || []).length }} kỳ báo cáo khác nhau ({{ (shopSummary.periods || []).join(' · ') }} ngày) — nên nhập lại theo CÙNG một kỳ để so sánh cho đúng.
                    </p>
                  </div>
                  <p v-else class="rounded-lg border border-ink-700 bg-ink-800 p-3 text-body leading-5 text-cream-400">
                    Chưa có dữ liệu. Nhập 2–3 dòng cũng đã giúp cơ cấu SKU bám đúng nhóm bán chạy của shop thay vì đoán theo từ khoá.
                  </p>

                  <label class="block">
                    <span class="text-label font-semibold uppercase tracking-wide text-cream-400">Dán từ Excel: Tên, Nhóm, Đã bán, Tồn, Đổi trả, Giá bán</span>
                    <textarea
                      v-model="shopPaste"
                      rows="3"
                      class="input mt-1 w-full resize-none !text-body"
                      placeholder="Áo linen tay dài, Áo, 120, 18, 5, 520000"
                    ></textarea>
                  </label>
                  <div class="flex flex-wrap gap-2">
                    <button type="button" class="tool-btn" @click="importShopPaste"><StudioIcon name="plus" size="h-3 w-3" /> Đọc &amp; thêm dòng</button>
                    <button type="button" class="tool-btn" @click="addShopRow">Thêm dòng trống</button>
                  </div>
                  <p v-if="shopParseError" role="alert" class="text-body text-danger">{{ shopParseError }}</p>

                  <div v-if="store.shopRows.length" class="max-h-64 overflow-auto rounded-lg border border-ink-700">
                    <table class="w-full min-w-[26rem] text-left text-label">
                      <thead class="sticky top-0 bg-ink-800 text-cream-400">
                        <tr>
                          <th class="p-1.5 font-semibold">Tên</th>
                          <th scope="col" class="p-1.5 font-semibold">Nhóm</th>
                          <th scope="col" class="p-1.5 font-semibold">Bán</th>
                          <th scope="col" class="p-1.5 font-semibold">Tồn</th>
                          <th scope="col" class="p-1.5 font-semibold">Đổi</th>
                          <th scope="col" class="p-1.5 font-semibold">Giá</th>
                          <th class="p-1.5"></th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-ink-800">
                        <tr v-for="(row, index) in store.shopRows" :key="index">
                          <td class="p-1"><input v-model="row.name" class="input !py-1 !text-label" placeholder="Tên sản phẩm"></td>
                          <td class="p-1"><input v-model="row.category" class="input !w-20 !py-1 !text-label" placeholder="Áo"></td>
                          <!-- Ô số không có nhãn thì trình đọc màn hình chỉ đọc "edit text" — phải có aria-label
                               nêu ĐÚNG ô nào của dòng nào. -->
                          <td class="p-1"><input v-model.number="row.units_sold" type="number" min="0" :aria-label="'Số bán của ' + (row.name || 'dòng ' + (index + 1))" class="input !w-16 !py-1 !text-label tabular-nums"></td>
                          <td class="p-1"><input v-model.number="row.stock_on_hand" type="number" min="0" :aria-label="'Tồn kho của ' + (row.name || 'dòng ' + (index + 1))" class="input !w-16 !py-1 !text-label tabular-nums"></td>
                          <td class="p-1"><input v-model.number="row.returns" type="number" min="0" :aria-label="'Số đổi trả của ' + (row.name || 'dòng ' + (index + 1))" class="input !w-14 !py-1 !text-label tabular-nums"></td>
                          <td class="p-1"><input v-model.number="row.price_vnd" type="number" min="0" :aria-label="'Giá bán của ' + (row.name || 'dòng ' + (index + 1))" class="input !w-24 !py-1 !text-label tabular-nums"></td>
                          <td class="p-1">
                            <!-- Nút chỉ có icon PHẢI có aria-label (§8 luật 1) và vùng chạm ≥ 24px (WCAG 2.2). -->
                            <button type="button" class="tool-btn !px-2 !py-1.5" :aria-label="'Xoá ' + (row.name || 'dòng ' + (index + 1))" title="Xoá dòng" @click="removeShopRow(index)"><StudioIcon name="trash" size="h-3.5 w-3.5" /></button>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>

                  <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn-brand btn-sm" :disabled="store.shopSaving || !shopRowCount" @click="saveShop">
                      {{ store.shopSaving ? 'Đang lưu…' : 'Lưu dữ liệu shop' }}
                    </button>
                    <p v-if="!store.shopSaving && !shopRowCount" class="mt-1.5 text-label leading-4 text-warn">↳ Chưa có dòng dữ liệu nào — thêm ít nhất một dòng ở bảng trên rồi lưu.</p>
                    <button v-if="store.shopRows.length" type="button" class="tool-btn" @click="store.shopRows = []">Xoá hết</button>
                  </div>
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
                    <!-- KHÔNG in tên nội bộ của agent hay tên model ra màn hình khách (§6). -->
                    <div><p class="text-label font-semibold uppercase tracking-wide text-brand-300">Bộ sưu tập đề xuất</p><h2 class="mt-1 text-lg font-semibold text-cream-100">Phương án cho bộ sưu tập của bạn</h2></div>
                    <span class="rounded-lg bg-ink-800 px-2.5 py-1 text-label text-cream-400">Khu vực: {{ collection.input?.region || selectedRegion }}</span>
                  </div>
                  <div class="mt-2 flex flex-wrap items-center gap-1.5 text-label">
                    <span
                      class="rounded-full px-2 py-0.5 font-semibold"
                      :class="modelReady ? 'bg-emerald-500/15 text-ok' : 'bg-amber-500/15 text-warn'"
                      :title="modelTitle"
                    >{{ modelShort }}</span>
                    <span v-for="row in appliedAi" :key="row" class="rounded bg-brand-500/15 px-2 py-0.5 text-brand-200">AI viết: {{ row }}</span>
                    <!-- Nói THẬT bản này mới chạy model hay lấy từ bộ đệm (và cũ bao lâu). -->
                    <span v-if="collection.model?.cached" class="rounded bg-ink-800 px-2 py-0.5 text-cream-400" title="Cùng yêu cầu trước đó nên không cần tạo lại">Đã tạo {{ cacheAgeLabel }}</span>
                    <span v-else-if="modelReady" class="text-cream-400">Vừa phân tích xong</span>
                    <button
                      v-if="collection"
                      type="button"
                      class="tool-btn !py-0.5"
                      :disabled="store.collectionBriefLoading"
                      title="Tạo lại brief mới (tốn thêm một lượt gọi AI)"
                      @click="createBrief({ force: true })"
                    >
                      <StudioIcon name="refresh" size="h-3 w-3" /> Chạy lại bằng AI
                    </button>
                  </div>

                  <p class="mt-3 text-sm leading-6 text-cream-200">{{ collection.brief }}</p>
                  <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-label uppercase tracking-wide text-cream-400">Tổng SKU</p><p class="mt-1 text-lg font-semibold text-cream-100">{{ collection.structure?.total_skus || 0 }}</p></div>
                    <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-label uppercase tracking-wide text-cream-400">Dải giá</p><p class="mt-1 text-sm font-semibold text-cream-100">{{ priceBand?.recommended_label || '—' }}</p><p class="text-label text-cream-400">{{ formatVnd(priceBand?.min_vnd) }} — {{ formatVnd(priceBand?.max_vnd) }}</p></div>
                    <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-label uppercase tracking-wide text-cream-400">Mood board</p><p class="mt-1 text-lg font-semibold text-cream-100">{{ moodboardItems.length }}</p><p class="text-label text-cream-400">ô màu đại diện</p></div>
                  </div>
                </div>

                <div class="seg mt-4 w-full sm:w-auto" role="tablist" aria-label="Nội dung brief">
                  <button v-for="tab in BRIEF_TABS" :key="tab.id" type="button" role="tab" class="seg-btn" :class="{ 'is-active': briefTab === tab.id }" :aria-selected="briefTab === tab.id" @click="briefTab = tab.id">{{ tab.label }}</button>
                </div>

                <div v-if="briefTab === 'overview'" class="mt-4 grid gap-4 md:grid-cols-2">
                  <div class="card p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                      <h3 class="font-display text-base font-semibold text-brand-300">Câu chuyện thương hiệu</h3>
                      <!-- Nói RÕ brief này dựa trên DNA nào: bạn khai hay hệ thống suy ra. -->
                      <button type="button" class="rounded-full px-2 py-0.5 text-label font-semibold" :class="collection.brand_dna?.source === 'owner' ? 'bg-brand-500/20 text-brand-200' : 'bg-amber-500/15 text-warn'" @click="setStep('dna')">
                        {{ collection.brand_dna?.source_label || 'Chưa rõ nguồn DNA' }}
                      </button>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-cream-200">{{ collection.brand_narrative?.narrative || '—' }}</p>
                    <p v-if="collection.brand_dna?.fields?.avoid?.length" class="mt-2 text-label leading-5 text-cream-400">Không đề xuất: {{ collection.brand_dna.fields.avoid.join(', ') }}</p>
                  </div>
                  <div class="card p-5"><h3 class="font-display text-base font-semibold text-brand-300">Gợi ý cấu hình Canvas</h3><div v-if="canvasSettings" class="mt-2 flex flex-wrap gap-1.5 text-label"><span class="rounded bg-ink-800 px-2 py-0.5 text-cream-200">Tỉ lệ {{ canvasSettings.ratio }}</span><span class="rounded bg-ink-800 px-2 py-0.5 text-cream-200">{{ canvasSettings.variant_count }} biến thể</span><span class="rounded bg-ink-800 px-2 py-0.5 text-cream-200">Negative prompt</span></div><p class="mt-2 text-body leading-5 text-cream-400">{{ canvasSettings?.note }}</p></div>
                </div>

                <div v-else-if="briefTab === 'money'" class="mt-4 space-y-4">
                  <div class="card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                      <div class="min-w-0">
                        <h3 class="font-display text-base font-semibold text-brand-300">Đơn giá &amp; định mức của xưởng bạn</h3>
                        <p class="mt-0.5 text-body leading-5 text-cream-400">
                          Con số tiền do BẠN quyết định: sửa ô nào là kế hoạch tính lại ngay. Giá thành và lợi nhuận do hệ thống tính từ đúng những ô này — không dùng model AI cho con số.
                        </p>
                      </div>
                      <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="tool-btn" @click="store.resetPlanAssumptions(); schedulePlan()">Về mặc định</button>
                        <button type="button" class="btn-brand btn-sm flex items-center gap-2" :disabled="store.planLoading || briefStale" @click="loadPlanNow">
                          <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="store.planLoading ? 'animate-spin' : ''" />
                          {{ store.planLoading ? 'Đang tính…' : 'Tính lại kế hoạch' }}
                        </button>
                        <p v-if="!store.planLoading && briefStale" class="mt-1.5 text-label leading-4 text-warn">↳ Brief đã cũ so với dữ liệu shop — tạo lại brief rồi mới tính kế hoạch.</p>
                      </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                      <label v-for="field in PLAN_FIELDS" :key="field.key" class="block" :title="field.hint">
                        <span class="flex items-center justify-between gap-2 text-label font-semibold uppercase tracking-wide text-cream-400">
                          {{ field.label }}<span class="text-cream-400">{{ field.suffix }}</span>
                        </span>
                        <input
                          type="number" min="0" step="1"
                          class="input mt-1 w-full !py-1.5 !text-xs tabular-nums"
                          :value="planFieldValue(field.key)"
                          @input="store.setPlanAssumption(field.key, $event.target.value); schedulePlan()"
                        >
                      </label>
                    </div>

                    <p v-if="briefStale" role="status" class="mt-3 flex gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-body leading-5 text-warn">
                      <StudioIcon name="info" size="h-4 w-4" class="shrink-0" /><span>Brief đã cũ so với prompt/trend hiện tại — bấm «Tạo lại brief» rồi mới tin kế hoạch này.</span>
                    </p>
                    <p v-if="store.planError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-body leading-5 text-danger">
                      <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ store.planError }}</span>
                    </p>
                  </div>

                  <template v-if="planTotals">
                    <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                      <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5"><p class="text-label text-cream-400">Tổng sản xuất</p><p class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatNumber(planTotals.units) }} cái</p></div>
                      <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5"><p class="text-label text-cream-400">Vải cần đặt</p><p class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatNumber(planTotals.fabric_order_m) }} m</p><p class="text-label text-cream-400">{{ formatVnd(planTotals.fabric_order_cost_vnd) }}</p></div>
                      <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5"><p class="text-label text-cream-400">Vốn cần</p><p class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatVnd(planTotals.capital_needed_vnd) }}</p><p class="text-label text-cream-400">Giá vốn TB {{ formatVnd(planTotals.avg_unit_cost_vnd) }}/cái</p></div>
                      <!-- HAI con số lợi nhuận phải nói rõ tên: phần tổng là TRƯỚC chi phí cố định, bảng kịch bản
                           bán là SAU khi trừ. Trước đây hai chỗ trả hai số khác nhau mà không nhãn nào phân biệt. -->
                      <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2.5">
                        <p class="text-label text-ok">Lãi gộp (chưa trừ chi phí cố định)</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-ok">{{ formatVnd(planTotals.profit_vnd) }}</p>
                        <p class="text-label text-ok">{{ planTotals.margin_pct }}% · {{ formatVnd(planTotals.avg_profit_unit_vnd) }}/cái</p>
                        <p v-if="planTotals.fixed_cost_vnd" class="mt-1 text-label text-ok">Sau chi phí cố định {{ formatVnd(planTotals.fixed_cost_vnd) }}: <b>{{ formatVnd(planTotals.profit_after_fixed_vnd) }}</b> ({{ planTotals.margin_after_fixed_pct }}%)</p>
                      </div>
                    </div>

                    <div
                      v-if="plan.price_check && plan.price_check.status !== 'within_band' && plan.price_check.status !== 'unknown'"
                      role="status"
                      class="flex gap-2 rounded-lg border p-3 text-body leading-5"
                      :class="plan.price_check.status === 'above_band' ? 'border-red-500/40 bg-red-500/10 text-danger' : 'border-emerald-500/40 bg-emerald-500/10 text-ok'"
                    >
                      <StudioIcon :name="plan.price_check.status === 'above_band' ? 'alertTriangle' : 'info'" size="h-4 w-4" class="shrink-0" />
                      <span>
                        {{ plan.price_check.message }}
                        <span class="mt-0.5 block text-cream-400">
                          Giá bán gợi ý theo giá vốn: {{ formatVnd(plan.price_check.suggested_price_vnd) }} · dải giá brief: {{ formatVnd(plan.price_check.band_min_vnd) }} – {{ formatVnd(plan.price_check.band_max_vnd) }}
                        </span>
                      </span>
                    </div>

                    <div class="card p-5">
                      <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                          <h3 class="font-display text-base font-semibold text-brand-300">Lệnh cắt — {{ planLines.length }} dòng</h3>
                          <p class="mt-0.5 text-body text-cream-400">
                            Số cái mỗi mã theo từng size, vải cần cho mỗi dòng và giá vốn/cái. Đây là bảng đưa thẳng cho thợ cắt.
                            <span v-if="planTotals.days_total" class="text-cream-400"> · khoảng {{ planTotals.days_total }} ngày nếu chạy {{ plan.assumptions.daily_capacity }} cái/ngày.</span>
                          </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                          <button type="button" class="tool-btn" @click="exportCutSheet"><StudioIcon name="download" size="h-3 w-3" /> Lệnh cắt (CSV)</button>
                          <button type="button" class="tool-btn" @click="exportSizeChart"><StudioIcon name="download" size="h-3 w-3" /> Bảng size (CSV)</button>
                          <button type="button" class="tool-btn" @click="copyCutSheet"><StudioIcon name="copy" size="h-3 w-3" /> Sao chép lệnh</button>
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
                                <span v-if="line.source === 'shop'" class="ml-1 rounded bg-emerald-500/15 px-1 py-0.5 text-tiny text-ok">theo shop</span>
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

                    <div class="grid gap-3 lg:grid-cols-3">
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

                    <div class="grid gap-4 lg:grid-cols-2">
                      <div class="card p-5">
                        <h3 class="font-display text-base font-semibold text-brand-300">Bảng size (cm)</h3>
                        <p class="mt-0.5 text-body leading-5 text-cream-400">Số đo tham chiếu dáng nữ VN — phải đối chiếu rập thật của xưởng và độ co của vải.</p>
                        <div v-for="chart in planSizeChart" :key="chart.category" class="mt-3 overflow-x-auto">
                          <p class="text-body font-semibold text-brand-200">{{ chart.category }}</p>
                          <table class="mt-1 w-full text-left text-label">
                            <thead><tr class="text-cream-400"><th class="pr-2 font-semibold">Size</th><th class="pr-2 font-semibold">Cái</th><th v-for="(value, name) in chart.rows[0].measures" :key="name" class="pr-2 font-semibold">{{ name }}</th></tr></thead>
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

                      <div class="card p-5">
                        <h3 class="font-display text-base font-semibold text-brand-300">Ba mức giá — lãi tương ứng</h3>
                        <p class="mt-0.5 text-body leading-5 text-cream-400">Đã trừ chiết khấu kênh {{ plan.assumptions.channel_discount_pct }}% và cộng chi phí cố định.</p>
                        <div class="mt-3 space-y-2">
                          <div v-for="scenario in planScenarios" :key="scenario.price_vnd" class="rounded-lg border border-ink-700 bg-ink-800 p-3">
                            <div class="flex items-center justify-between gap-2">
                              <span class="text-xs font-semibold text-cream-100">{{ formatVnd(scenario.price_vnd) }}</span>
                              <span class="rounded px-2 py-0.5 text-label" :class="scenario.profit_vnd > 0 ? 'bg-emerald-500/15 text-ok' : 'bg-red-500/15 text-danger'">{{ scenario.label }}</span>
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

                  <div v-else-if="!store.planLoading" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-8 text-center">
                    <StudioIcon name="receipt" size="h-7 w-7" class="mx-auto text-brand-300" />
                    <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có kế hoạch sản xuất</p>
                    <p class="mt-1 text-xs leading-5 text-cream-400">Bấm «Tính lại kế hoạch» để ra lệnh cắt, giá thành và lợi nhuận từ cấu trúc SKU ở tab «Cấu trúc».</p>
                  </div>
                </div>

                <div v-else-if="briefTab === 'moodboard'" class="mt-4 card p-5">
                  <div class="mb-3 flex items-center justify-between"><h3 class="font-display text-base font-semibold text-brand-300">Bảng mood</h3><span class="text-label text-cream-400">{{ moodboardItems.length }} ô</span></div>
                  <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-6">
                    <div v-for="(item, index) in moodboardItems" :key="item.id || index" class="group relative aspect-square overflow-hidden rounded-lg border border-ink-700" :style="{ backgroundColor: item.color || palette[index % Math.max(1, palette.length)]?.hex || '#b9c8c2' }" role="img" :aria-label="(item.label || 'Mood ' + (index + 1)) + ': ' + (item.caption || '')" :title="item.caption || item.label || 'Mood board'">
                      <span class="absolute inset-x-0 bottom-0 p-1.5 text-tiny font-semibold leading-3 text-white shadow-[0_-12px_16px_-8px_rgba(0,0,0,0.8)]">{{ item.label || 'Mood ' + (index + 1) }}</span>
                    </div>
                  </div>
                  <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <button v-for="(color, index) in palette" :key="color.hex || index" type="button" class="flex items-center gap-2 rounded-lg border border-ink-600 bg-ink-800 p-2 text-left" :title="'Sao chép ' + color.hex" @click="copyText(color.hex, 'mã màu ' + color.hex)">
                      <span class="h-6 w-6 shrink-0 rounded border border-ink-700" :style="{ backgroundColor: color.hex }"></span><span class="min-w-0 flex-1"><span class="block truncate text-body font-semibold text-cream-100">{{ color.name || 'Màu ' + (index + 1) }}</span><span class="block text-label text-cream-400">{{ color.role || color.hex }}</span></span><code class="text-label text-cream-400">{{ color.hex }}</code>
                    </button>
                  </div>
                </div>

                <div v-else-if="briefTab === 'structure'" class="mt-4 card p-5">
                  <h3 class="font-display text-base font-semibold text-brand-300">Cấu trúc danh mục</h3><p class="mt-1 text-xs text-cream-400">{{ collection.structure?.rationale }}</p>
                  <div class="mt-3 space-y-2.5">
                    <div v-for="row in categoryRows" :key="row.category" class="rounded-lg bg-ink-800 p-3"><div class="flex items-center justify-between text-xs"><span class="font-semibold text-cream-100">{{ row.category }}</span><span class="text-brand-200">{{ row.count }} SKU · {{ row.share || 0 }}%</span></div><div class="mt-1.5 h-1 rounded bg-ink-700"><span class="block h-full rounded bg-brand-500" :style="{ width: Math.min(100, Number(row.share || 0)) + '%' }"></span></div><p class="mt-1.5 text-label leading-4 text-cream-400">{{ row.rationale }}</p></div>
                  </div>
                </div>

                <div v-else class="mt-4 grid gap-4 md:grid-cols-2">
                  <div class="card p-5"><h3 class="font-display text-base font-semibold text-brand-300">Phối outfit</h3><div class="mt-3 space-y-2.5"><div v-for="look in outfitRows" :key="look.id" class="rounded-lg border border-ink-700 bg-ink-800 p-3"><div class="flex items-center justify-between gap-2"><span class="text-xs font-semibold text-cream-100">{{ look.name }}</span><span class="text-label text-cream-400">{{ look.goal }}</span></div><p class="mt-1.5 text-body leading-4 text-cream-300">{{ (look.items || []).join(' · ') }}</p><div class="mt-2 flex gap-1"><span v-for="(color, index) in (look.palette || []).slice(0, 3)" :key="index" class="h-3 flex-1 rounded" :style="{ backgroundColor: color }"></span></div></div></div></div>
                  <div class="card p-5"><h3 class="font-display text-base font-semibold text-brand-300">Phân bổ size</h3><div class="mt-3 space-y-2.5"><div v-for="row in sizeRows" :key="row.size" class="flex items-center gap-3 text-xs"><span class="w-8 rounded bg-ink-800 py-1 text-center font-semibold text-cream-100">{{ row.size }}</span><span class="h-2 flex-1 rounded bg-ink-800"><span class="block h-full rounded bg-brand-500" :style="{ width: Math.min(100, Number(row.share || 0)) + '%' }"></span></span><span class="w-20 text-right text-cream-400">{{ row.count }} · {{ row.share || 0 }}%</span></div></div></div>
                </div>
              </template>

              <div v-else class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-10 text-center"><StudioIcon name="briefcase" size="h-8 w-8" class="mx-auto text-brand-300" /><p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief bộ sưu tập</p><p class="mt-1 text-xs leading-5 text-cream-400">Nhập prompt ở bên trái rồi bấm «Tạo brief bộ sưu tập».</p></div>
            </div>
          </section>
</template>
