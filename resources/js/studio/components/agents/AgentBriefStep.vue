<script setup>
/**
 * BƯỚC 3 — ĐỊNH HƯỚNG, chia thành 7 VIỆC CON (2026-09-25).
 *
 * Trước đây bước này là MỘT màn với 5 tab: nhập prompt ở cột trái, kết quả ở cột phải, và mọi thứ khác
 * (cơ cấu, mood, size, đơn giá, lệnh cắt) nằm trong các tab. Trên điện thoại nó thành ba bốn cuộn dài,
 * người dùng không biết mình đang ở đâu và còn phải làm gì.
 *
 * Nay cùng ngần ấy nội dung, nhưng chia theo VIỆC: mô tả → số lượng SKU → bảng size → bảng mood → đơn
 * giá → kế hoạch → xem lại. Mỗi màn một quyết định, và mọi thứ đã đặt đều KIỂM LẠI ĐƯỢC ở việc 7.
 *
 * Khung nội dung vẫn do useAgentStudio.js cung cấp qua provide() — component này chỉ lo bày biện.
 */
import { inject } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';
import SourceLibraryPicker from '../SourceLibraryPicker.vue';
import AgentSubSteps from './AgentSubSteps.vue';
import AgentSkuStep from './AgentSkuStep.vue';
import AgentSizeStep from './AgentSizeStep.vue';
import AgentMoodStep from './AgentMoodStep.vue';
import AgentCostStep from './AgentCostStep.vue';
import AgentPlanStep from './AgentPlanStep.vue';
import AgentReviewStep from './AgentReviewStep.vue';

const store = useStudioStore();
const sub = inject('sub');
const setSub = inject('setSub');
const prompt = inject('prompt');
const promptInput = inject('promptInput');
const collectionError = inject('collectionError');
const refPickerOpen = inject('refPickerOpen');
const shopPaste = inject('shopPaste');
const shopParseError = inject('shopParseError');
const shopOpen = inject('shopOpen');
const refImages = inject('refImages');
const referenceNote = inject('referenceNote');
const cacheAgeLabel = inject('cacheAgeLabel');
const radar = inject('radar');
const collection = inject('collection');
const selectedRegion = inject('selectedRegion');
const trends = inject('trends');
const selectedTrendCount = inject('selectedTrendCount');
const selectedTrendObjects = inject('selectedTrendObjects');
const briefStale = inject('briefStale');
const modelReady = inject('modelReady');
const modelShort = inject('modelShort');
const modelTitle = inject('modelTitle');
// Số ĐO của lượt chạy: có tìm nguồn ngoài thật hay không, hỏi gì, được mấy tin (xem shell).
const toolSearchLine = inject('toolSearchLine');
const appliedAi = inject('appliedAi');
const briefModeMismatch = inject('briefModeMismatch');
const shopSummary = inject('shopSummary');
const shopRowCount = inject('shopRowCount');
const onPickReference = inject('onPickReference');
const removeReference = inject('removeReference');
const importShopPaste = inject('importShopPaste');
const addShopRow = inject('addShopRow');
const removeShopRow = inject('removeShopRow');
const saveShop = inject('saveShop');
const trendTitle = inject('trendTitle');
const createBrief = inject('createBrief');
</script>

<template>
  <section id="agent-step-brief" role="region" aria-label="Định hướng" :aria-busy="store.collectionBriefLoading">
    <AgentSubSteps step-id="brief" />

    <!-- ── 3.1 MÔ TẢ & ẢNH MẪU ─────────────────────────────────────────────── -->
    <div v-if="sub === 'prompt'" class="grid gap-4 lg:grid-cols-[minmax(320px,400px)_1fr]">
      <div class="space-y-4">
        <div class="card p-4">
          <h2 class="font-display text-base font-semibold text-brand-300">Bộ sưu tập này cho ai?</h2>
          <p class="mt-0.5 text-xs text-cream-400">Mô tả khách hàng, dịp mặc, chất liệu, màu sắc hoặc định vị giá.</p>
          <label for="collection-prompt" class="label mt-4">Mô tả tiếng Việt <span class="font-normal text-cream-400">(bắt buộc)</span></label>
          <textarea
            id="collection-prompt" ref="promptInput" v-model="prompt" rows="5" maxlength="2000"
            aria-describedby="collection-prompt-help"
            class="input w-full resize-none !text-sm"
            placeholder="Ví dụ: Bộ sưu tập công sở mùa hè cho nữ văn phòng, ưu tiên linen thoáng và màu pastel dịu…"
            @keydown.ctrl.enter="createBrief()" @keydown.meta.enter="createBrief()"
          ></textarea>
          <div class="mt-1.5 flex items-start justify-between gap-3">
            <p id="collection-prompt-help" class="text-label leading-4 text-cream-400">Ctrl+Enter để tạo brief.</p>
            <span class="shrink-0 text-label tabular-nums text-cream-400">{{ prompt.length }}/2000</span>
          </div>

          <div class="mt-4 rounded-lg border border-ink-700 bg-ink-900/60 p-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="text-body font-semibold text-cream-100">Ảnh mẫu để AI bám phong cách <span class="font-normal text-cream-400">(tuỳ chọn, tối đa 3)</span></p>
              <button type="button" class="tool-btn state-layer" :disabled="refImages.length >= 3" @click="refPickerOpen = true">
                <StudioIcon name="library" size="h-3 w-3" /> Chọn ảnh mẫu
              </button>
              <span v-if="refImages.length >= 3" class="text-label text-cream-400">↳ Đã đủ 3 ảnh mẫu — bỏ một ảnh nếu muốn đổi.</span>
            </div>
            <div v-if="refImages.length" class="mt-2 flex flex-wrap gap-2">
              <div v-for="url in refImages" :key="url" class="relative">
                <img :src="url" alt="Ảnh mẫu" class="h-14 w-14 rounded-lg border border-ink-600 object-cover">
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
            <div class="mb-2 flex items-center justify-between">
              <span class="text-body font-semibold uppercase tracking-wide text-cream-400">Hướng đã chọn</span>
              <span class="text-label text-cream-400">{{ selectedTrendCount }} / {{ trends.length }}</span>
            </div>
            <div v-if="selectedTrendObjects.length" class="flex flex-wrap gap-2">
              <span v-for="trend in selectedTrendObjects" :key="trend.id" class="flex items-center gap-1.5 rounded-lg border border-brand-500/40 bg-brand-500/10 px-2 py-1 text-body text-brand-200">
                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: trend.color }"></span>{{ trendTitle(trend) }}
              </span>
            </div>
            <p v-else class="text-xs leading-5 text-cream-400">Chưa chọn hướng nào — hệ thống sẽ dùng nhóm mặc định.</p>
          </div>

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
              : (collection ? 'Tạo lại brief (có AI)' : 'Tạo brief bộ sưu tập') }}
          </button>
          <div v-if="collectionError || store.collectionBriefError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-danger/40 bg-danger/10 p-3 text-xs leading-5 text-danger">
            <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ collectionError || store.collectionBriefError }}</span>
          </div>
        </div>

        <div v-if="radar" class="card p-4">
          <h3 class="text-xs font-semibold text-cream-100">Tín hiệu thương hiệu</h3>
          <p class="mt-1.5 text-body leading-5 text-cream-300">{{ radar.internal_brand_signal?.narrative || 'Chưa có narrative.' }}</p>
        </div>

        <div class="card p-4">
          <button
            type="button"
            class="motion-ui flex w-full items-start justify-between gap-2 text-left"
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
            <div v-if="shopSummary && shopSummary.row_count" class="rounded-lg border border-ok/30 bg-ok/10 p-3 text-body leading-5 text-ok">
              {{ shopSummary.narrative }}
              <p v-if="shopSummary.period_days_mixed" class="mt-1.5 text-warn">
                ↳ Dữ liệu đang trộn {{ (shopSummary.periods || []).length }} kỳ báo cáo khác nhau ({{ (shopSummary.periods || []).join(' · ') }} ngày) — nên nhập lại theo CÙNG một kỳ để so sánh cho đúng.
              </p>
            </div>
            <p v-else class="rounded-lg border border-ink-700 bg-ink-800 p-3 text-body leading-5 text-cream-400">
              Chưa có dữ liệu. Nhập 2–3 dòng cũng đã giúp cơ cấu SKU bám đúng nhóm bán chạy của shop thay vì đoán theo từ khoá.
            </p>

            <label class="block">
              <span class="text-label font-semibold uppercase tracking-wide text-cream-400">Dán từ Excel: Tên, Nhóm, Đã bán, Tồn, Đổi trả, Giá bán</span>
              <textarea v-model="shopPaste" rows="3" class="input mt-1 w-full resize-none !text-body" placeholder="Áo linen tay dài, Áo, 120, 18, 5, 520000"></textarea>
            </label>
            <div class="flex flex-wrap gap-2">
              <button type="button" class="tool-btn state-layer" @click="importShopPaste"><StudioIcon name="plus" size="h-3 w-3" /> Đọc &amp; thêm dòng</button>
              <button type="button" class="tool-btn state-layer" @click="addShopRow">Thêm dòng trống</button>
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
                    <td class="p-1"><input v-model.number="row.units_sold" type="number" min="0" :aria-label="'Số bán của ' + (row.name || 'dòng ' + (index + 1))" class="input !w-16 !py-1 !text-label tabular-nums"></td>
                    <td class="p-1"><input v-model.number="row.stock_on_hand" type="number" min="0" :aria-label="'Tồn kho của ' + (row.name || 'dòng ' + (index + 1))" class="input !w-16 !py-1 !text-label tabular-nums"></td>
                    <td class="p-1"><input v-model.number="row.returns" type="number" min="0" :aria-label="'Số đổi trả của ' + (row.name || 'dòng ' + (index + 1))" class="input !w-14 !py-1 !text-label tabular-nums"></td>
                    <td class="p-1"><input v-model.number="row.price_vnd" type="number" min="0" :aria-label="'Giá bán của ' + (row.name || 'dòng ' + (index + 1))" class="input !w-24 !py-1 !text-label tabular-nums"></td>
                    <td class="p-1">
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
              <button v-if="store.shopRows.length" type="button" class="tool-btn state-layer" @click="store.shopRows = []">Xoá hết</button>
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
          <div class="card p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p class="text-label font-semibold uppercase tracking-wide text-brand-300">Bộ sưu tập đề xuất</p>
                <h2 class="mt-1 text-lg font-semibold text-cream-100">Phương án cho bộ sưu tập của bạn</h2>
              </div>
              <span class="rounded-lg bg-ink-800 px-2.5 py-1 text-label text-cream-400">Khu vực: {{ collection.input?.region || selectedRegion }}</span>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-label">
              <span class="rounded-full px-2 py-0.5 font-semibold" :class="modelReady ? 'bg-ok/15 text-ok' : 'bg-warn/15 text-warn'" :title="modelTitle">{{ modelShort }}</span>
              <span v-for="row in appliedAi" :key="row" class="rounded bg-brand-500/15 px-2 py-0.5 text-brand-200">AI viết: {{ row }}</span>
              <span v-if="collection.model?.cached" class="rounded bg-ink-800 px-2 py-0.5 text-cream-400" title="Cùng yêu cầu trước đó nên không cần tạo lại">Đã tạo {{ cacheAgeLabel }}</span>
              <span v-else-if="modelReady" class="text-cream-400">Vừa phân tích xong</span>
              <span v-if="toolSearchLine" class="w-full text-cream-400">{{ toolSearchLine }}</span>
            </div>

            <p class="mt-3 text-sm leading-6 text-cream-200">{{ collection.brief }}</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
              <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-label uppercase tracking-wide text-cream-400">Tổng SKU</p><p class="mt-1 text-lg font-semibold text-cream-100">{{ collection.structure?.total_skus || 0 }}</p></div>
              <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-label uppercase tracking-wide text-cream-400">Dải giá</p><p class="mt-1 text-sm font-semibold text-cream-100">{{ collection.price_bands?.recommended_label || '—' }}</p></div>
              <div class="rounded-lg border border-ink-700 bg-ink-800 p-3"><p class="text-label uppercase tracking-wide text-cream-400">Bảng mood</p><p class="mt-1 text-lg font-semibold text-cream-100">{{ collection.moodboard?.count || 0 }}</p></div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2">
              <button type="button" class="tool-btn state-layer" @click="setSub('sku')">
                <StudioIcon name="package" size="h-3 w-3" /> Việc 2: chọn số lượng SKU
              </button>
              <button type="button" class="tool-btn state-layer" @click="setSub('review')">
                <StudioIcon name="briefcase" size="h-3 w-3" /> Việc 7: xem lại &amp; chốt
              </button>
            </div>
          </div>

          <div v-if="briefStale" role="status" class="mt-3 flex gap-2 rounded-lg border border-warn/40 bg-warn/10 p-3 text-xs leading-5 text-warn">
            <StudioIcon name="info" size="h-4 w-4" class="shrink-0" /><span>Prompt, hướng hoặc các lựa chọn đã đổi. Sang việc 7 để áp dụng — chạy tất định nên tức thì.</span>
          </div>
          <div v-else-if="briefModeMismatch" role="status" class="mt-3 flex gap-2 rounded-lg border border-warn/40 bg-warn/10 p-3 text-xs leading-5 text-warn">
            <StudioIcon name="info" size="h-4 w-4" class="shrink-0" /><span>Brief này được dựng ở chế độ {{ collection.model?.mode === 'ai' ? 'AI' : 'tất định' }} — sang việc 7 nếu muốn dựng lại theo công tắc hiện tại.</span>
          </div>
        </template>

        <div v-else class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-10 text-center">
          <StudioIcon name="briefcase" size="h-8 w-8" class="mx-auto text-brand-300" />
          <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief bộ sưu tập</p>
          <p class="mt-1 text-xs leading-5 text-cream-400">Nhập mô tả ở bên trái rồi bấm «Tạo brief bộ sưu tập». Các việc sau (SKU · size · mood · đơn giá) sẽ có số liệu để chia.</p>
        </div>
      </div>
    </div>

    <!-- ── 3.2 → 3.7 ───────────────────────────────────────────────────────── -->
    <AgentSkuStep v-else-if="sub === 'sku'" />
    <AgentSizeStep v-else-if="sub === 'size'" />
    <AgentMoodStep v-else-if="sub === 'mood'" />
    <AgentCostStep v-else-if="sub === 'cost'" />
    <AgentPlanStep v-else-if="sub === 'plan'" />
    <AgentReviewStep v-else />
  </section>
</template>
