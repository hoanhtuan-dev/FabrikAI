<script setup>
// TÁCH từ DesignAgents.vue (đợt tối ưu 2026-09-24) — BƯỚC RADAR.
// Shell cung cấp toàn bộ trạng thái/logic qua provide(); component này chỉ inject đúng bề mặt nó dùng
// rồi giữ NGUYÊN VĂN template của bước. Xem shell để biết định nghĩa gốc.
import { inject } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';
const store = useStudioStore();
const trendQuery = inject('trendQuery');
const trendCategory = inject('trendCategory');
const liveOnly = inject('liveOnly');
const lifecycleFilter = inject('lifecycleFilter');
const liveSources = inject('liveSources');
const liveTrendCount = inject('liveTrendCount');
const newsItems = inject('newsItems');
const activeSourceCount = inject('activeSourceCount');
const fetchedAtLabel = inject('fetchedAtLabel');
const refreshLabel = inject('refreshLabel');
const autoRefresh = inject('autoRefresh');
const market = inject('market');
const marketLive = inject('marketLive');
const marketSignals = inject('marketSignals');
const marketTopics = inject('marketTopics');
const marketPrices = inject('marketPrices');
const marketAgeLabel = inject('marketAgeLabel');
const internetVerdict = inject('internetVerdict');
const groupsNeedingSetup = inject('groupsNeedingSetup');
const toolSearchLine = inject('toolSearchLine');
const step = inject('step');
const radar = inject('radar');
const regions = inject('regions');
const selectedRegion = inject('selectedRegion');
const trends = inject('trends');
const sources = inject('sources');
const summaryItems = inject('summaryItems');
const selectedTrendIds = inject('selectedTrendIds');
const selectedTrendCount = inject('selectedTrendCount');
const trendCategories = inject('trendCategories');
const lifecycles = inject('lifecycles');
const visibleTrends = inject('visibleTrends');
const directions = inject('directions');
const shortDate = inject('shortDate');
const signalSamples = inject('signalSamples');
const trendSignalLabel = inject('trendSignalLabel');
const sourceStatus = inject('sourceStatus');
const sourceStatusTone = inject('sourceStatusTone');
const directionConfidence = inject('directionConfidence');
const directionPriceLabel = inject('directionPriceLabel');
const trendNameById = inject('trendNameById');
const focusDirections = inject('focusDirections');
const loadRadar = inject('loadRadar');
const toggleTrend = inject('toggleTrend');
const clearTrends = inject('clearTrends');
const selectSuggested = inject('selectSuggested');
const trendTitle = inject('trendTitle');
const categoryLabel = inject('categoryLabel');
const lifecycleLabel = inject('lifecycleLabel');
const lifecycleClass = inject('lifecycleClass');
const formatNumber = inject('formatNumber');
const formatVnd = inject('formatVnd');
</script>

<template>
          <section id="agent-step-radar" role="tabpanel" aria-label="Tín hiệu" :aria-busy="store.trendRadarLoading">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
              <div>
                <h2 class="text-base font-semibold text-cream-100">Tín hiệu thị trường</h2>
                <p class="mt-0.5 text-xs text-cream-400">Chọn 2–4 hướng phù hợp nhất với DNA shop. Chưa chọn cũng chạy được với nhóm mặc định.</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <label for="agent-region" class="sr-only">Khu vực đọc tín hiệu</label>
                <select id="agent-region" v-model="selectedRegion" :disabled="store.trendRadarLoading" class="input !w-auto !bg-ink-800 !py-2 !text-xs !text-cream-100">
                  <option v-for="region in regions" :key="region.id" :value="region.id">{{ region.name }}</option>
                </select>
                <button type="button" class="tool-btn" :disabled="store.trendRadarLoading" @click="loadRadar(selectedRegion, { force: true })">
                  <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="store.trendRadarLoading ? 'animate-spin' : ''" /> Tải lại
                </button>
              </div>
            </div>

            <div v-if="store.trendRadarError" role="alert" class="mb-4 flex gap-2 rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-xs text-danger">
              <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ store.trendRadarError }}</span>
            </div>

            <div v-if="radar" class="mb-4">
              <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="item in summaryItems" :key="item.key" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
                  <p class="text-label leading-4 text-cream-400">{{ item.label }}</p>
                  <p class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatNumber(item.value) }}</p>
                  <p class="text-tiny leading-3 text-cream-400">{{ item.fromNews ? 'từ tin thật' : 'từ dữ liệu của bạn' }}</p>
                </div>
              </div>
              <!-- Nguồn của con số phải nằm NGAY DƯỚI số, không gấp trong khối khác (§18.2). -->
              <p class="mt-1.5 text-label leading-5 text-cream-400">
                {{ marketLive
                  ? 'Số đầu tiên là số ĐO từ tin thật; các hướng còn lại ghi rõ hướng nào có tin thật, hướng nào thuộc bộ có sẵn.'
                  : 'Chưa có tin thật nào: các hướng đang hiển thị thuộc BỘ XU HƯỚNG CÓ SẴN của FabrikAI, không phải số liệu thị trường.' }}
              </p>
              <!-- CÁCH DỮ LIỆU ĐƯỢC LƯU · QUẢN LÝ · TÁI SỬ DỤNG — trả lời "lưu ở đâu, ai lưu, dùng lại ra sao". -->
              <details class="mt-1.5">
                <summary class="cursor-pointer text-label text-cream-400 underline decoration-dotted">Cách dữ liệu này được lưu &amp; tái sử dụng</summary>
                <div class="mt-1.5 space-y-1 rounded-lg bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
                  <p><b class="text-cream-200">Lưu tự động:</b> mỗi lần đo, hệ thống ghi một "ảnh chụp" (snapshot) nếu dữ liệu đổi hoặc đã cũ hơn 12 giờ — bạn không phải bấm lưu.</p>
                  <p><b class="text-cream-200">Quản lý:</b> ảnh chụp cũ hơn 120 ngày tự xoá để CSDL không phình.</p>
                  <p><b class="text-cream-200">Tái sử dụng:</b> số "tăng/giảm %" được tính bằng cách so ảnh chụp mới nhất với các lần đo trước — nhờ vậy bạn thấy hướng nào đang lên hay chậm lại theo thời gian, không cần AI.</p>
                </div>
              </details>
            </div>

            <p v-if="store.trendRadarLoading && store.designAgentAi" class="mb-3 flex items-center gap-2 text-body text-brand-200" role="status" aria-live="polite">
              <StudioIcon name="sparkles" size="h-3.5 w-3.5" class="animate-pulse" /> AI đang viết định hướng từ dữ liệu bên dưới — có thể mất vài giây…
            </p>

            <div v-if="store.trendRadarLoading && !radar" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="status" aria-live="polite">
              <span class="sr-only">{{ store.designAgentAi ? 'AI đang suy luận xu hướng…' : 'Đang tải TrendRadar…' }}</span>
              <div v-for="i in 6" :key="i" class="h-40 animate-pulse rounded-xl border border-ink-700 bg-ink-800"></div>
            </div>

            <template v-else-if="radar">
              <!-- TÍN HIỆU ĐO TỪ TIN THẬT: thu gọn + giải thích ngôn ngữ thường. Số liệu thật, đo bằng
                   thuật toán KHÔNG cần AI — nhưng không nên chiếm hết màn hình: mặc định thu khi nhiều tín hiệu. -->
              <details v-if="marketLive" class="mb-5 rounded-xl border border-ink-700 bg-ink-900/70" :open="marketSignals.length <= 4">
                <summary class="cursor-pointer select-none px-4 py-3">
                  <span class="flex flex-wrap items-center gap-2">
                    <StudioIcon name="scan" size="h-4 w-4" class="text-ok" />
                    <span class="font-display text-base font-semibold text-cream-50">Tín hiệu đo từ tin thật ({{ marketSignals.length }})</span>
                    <span class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-label font-semibold text-ok">đo tự động · không cần AI</span>
                  </span>
                  <span class="mt-1 block text-label leading-4 text-cream-400">Máy chủ đọc tin từ các nguồn đã nối rồi đếm từ khoá đang được nhắc tới — số liệu THẬT kèm nguồn, không phải dự đoán của AI.{{ marketAgeLabel ? ' · đo ' + marketAgeLabel : '' }}</span>
                </summary>
                <div class="border-t border-ink-700 px-4 pb-3 pt-2.5">
                  <div class="flex flex-wrap gap-1.5">
                    <span v-for="signal in marketSignals" :key="signal.category + signal.term" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-700 bg-ink-900 px-2.5 py-1.5 text-label">
                      <span class="font-semibold text-cream-100">{{ signal.term }}</span>
                      <span class="text-cream-400">{{ signal.mentions }} tin · {{ signal.source_count }} nguồn</span>
                      <span v-if="signal.change_pct === null || signal.change_pct === undefined" class="text-tiny text-cream-400">lần đo đầu</span>
                      <span v-else class="rounded px-1 py-0.5 text-tiny font-semibold" :class="signal.change_pct >= 0 ? 'bg-emerald-500/15 text-ok' : 'bg-amber-500/15 text-warn'">{{ signal.change_pct >= 0 ? '↗' : '↘' }} {{ Math.abs(signal.change_pct) }}%</span>
                    </span>
                  </div>

                  <p v-if="marketTopics.length" class="mt-3 text-label leading-5 text-cream-300">
                    <span class="font-semibold text-cream-200">Chủ đề đang được nói tới ({{ marketTopics.length }}):</span>
                    <template v-for="(topic, i) in marketTopics.slice(0, 8)" :key="topic.term"><span class="text-cream-200">{{ topic.term }}</span><span v-if="i < Math.min(marketTopics.length, 8) - 1" class="text-cream-400"> · </span></template>
                    <span v-if="marketTopics.length > 8" class="text-cream-400"> · …</span>
                    <span class="text-cream-400"> — cụm từ lặp lại trong bài báo, không phải danh mục FabrikAI khai sẵn.</span>
                  </p>

                  <p v-if="marketPrices && marketPrices.count" class="mt-2 text-label text-cream-300">Giá ghi trong tin ({{ marketPrices.count }} lần): <b class="text-cream-100">{{ formatVnd(marketPrices.min_vnd) }} – {{ formatVnd(marketPrices.median_vnd) }} – {{ formatVnd(marketPrices.max_vnd) }}</b> <span class="text-cream-400">(thấp · trung vị · cao)</span></p>

                  <details v-if="marketSignals.some((s) => signalSamples(s).length)" class="mt-2">
                    <summary class="cursor-pointer text-label text-cream-400 underline decoration-dotted">Xem nguồn của từng tín hiệu</summary>
                    <ul class="mt-1.5 space-y-1 text-label leading-4 text-cream-300">
                      <li v-for="signal in marketSignals.filter((s) => signalSamples(s).length)" :key="'src-' + signal.term">
                        <b class="text-cream-200">{{ signal.term }}</b>:
                        <template v-for="(sample, j) in signalSamples(signal)" :key="sample.url"><a :href="sample.url" target="_blank" rel="noopener" class="underline decoration-dotted hover:text-cream-100">{{ sample.title }}</a><span v-if="j < signalSamples(signal).length - 1"> · </span></template>
                      </li>
                    </ul>
                  </details>
                </div>
              </details>

              <!-- ĐỊNH HƯỚNG: thu gọn để người dùng TẬP TRUNG vào hướng thời trang + hiểu đang chọn gì,
                   để làm gì. Chi tiết (việc làm · rủi ro · giá · độ tin cậy · nguồn) gấp vào <details>. -->
              <div v-if="directions.length" class="mb-5 rounded-xl border border-ink-700 bg-ink-900/70 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <h3 class="font-display text-base font-semibold text-brand-300">Định hướng từ TrendRadar ({{ directions.length }})</h3>
                    <p class="mt-0.5 text-body leading-5 text-cream-400">Chọn trend gắn với hướng bạn muốn — AI sẽ dựng brief bám theo các trend đã chọn.</p>
                  </div>
                  <button type="button" class="tool-btn" title="Chọn các trend mà 3 định hướng mạnh nhất đang nhắc tới" @click="focusDirections">
                    <StudioIcon name="target" size="h-3 w-3" /> Chọn 3 hướng đầu
                  </button>
                </div>
                <div class="mt-3 grid gap-2.5 lg:grid-cols-2">
                  <article v-for="row in directions" :key="row.id" class="rounded-lg border border-ink-700 bg-ink-800 p-3">
                    <div class="flex items-start justify-between gap-2">
                      <h4 class="text-sm font-semibold leading-5 text-cream-50">{{ row.title }}</h4>
                      <span
                        class="shrink-0 rounded px-1.5 py-0.5 text-tiny font-semibold uppercase tracking-wide"
                        :class="row.source === 'ai' ? 'bg-emerald-500/15 text-ok' : 'bg-ink-700 text-cream-400'"
                      >{{ row.source === 'ai' ? 'AI' : 'tất định' }}</span>
                    </div>
                    <!-- MỘT câu lý do: giúp hiểu "hướng này để làm gì, vì sao nên chọn" -->
                    <p v-if="row.thesis || row.why_now" class="mt-1 text-body leading-5 text-cream-300">{{ row.thesis || row.why_now }}</p>
                    <!-- Trend gắn với hướng — đây là thứ người dùng BẤM để chọn -->
                    <div v-if="row.trend_ids && row.trend_ids.length" class="mt-2.5 flex flex-wrap items-center gap-1.5">
                      <span class="text-tiny text-cream-400">Trend gắn:</span>
                      <button
                        v-for="id in row.trend_ids"
                        :key="id"
                        type="button"
                        class="motion-ui rounded border px-2 py-1 text-tiny font-semibold transition"
                        :class="selectedTrendIds.includes(String(id)) ? 'border-brand-500 bg-brand-500/15 text-brand-200 hover:bg-brand-500/25' : 'border-ink-600 text-cream-300 hover:bg-ink-700'"
                        @click="toggleTrend(id)"
                      >{{ trendNameById(id) }}</button>
                    </div>
                    <!-- Chi tiết + nguồn gấp lại để không chiếm màn hình -->
                    <details class="mt-2">
                      <summary class="cursor-pointer text-tiny text-cream-400 underline decoration-dotted">Chi tiết &amp; nguồn</summary>
                      <dl class="mt-1.5 space-y-1 text-label leading-4">
                        <div v-if="row.action" class="flex gap-1.5"><dt class="shrink-0 font-semibold text-brand-200">Việc làm:</dt><dd class="text-cream-300">{{ row.action }}</dd></div>
                        <div v-if="row.risk" class="flex gap-1.5"><dt class="shrink-0 font-semibold text-warn">Rủi ro:</dt><dd class="text-cream-300">{{ row.risk }}</dd></div>
                      </dl>
                      <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <span v-if="directionPriceLabel(row.price_band)" class="rounded bg-ink-700 px-1.5 py-0.5 text-tiny text-cream-200">{{ directionPriceLabel(row.price_band) }}</span>
                        <span v-if="directionConfidence(row.confidence) !== null" class="rounded bg-ink-700 px-1.5 py-0.5 text-tiny text-cream-200">Tin cậy {{ directionConfidence(row.confidence) }}%</span>
                      </div>
                      <p v-if="row.evidence_mode === 'live' && row.live" class="mt-1.5 text-label leading-4 text-ok">
                        Dựa trên {{ row.live.mentions }} tin thật · {{ row.live.source_count }} nguồn
                        <template v-if="(row.evidence || []).length">
                          — <a v-for="item in row.evidence" :key="item.url" :href="item.url" target="_blank" rel="noopener" class="underline decoration-dotted hover:text-cream-100">{{ item.title }}</a>
                        </template>
                      </p>
                    </details>
                  </article>
                </div>
              </div>

              <div class="mb-3 flex flex-wrap items-center gap-2">
                <div class="relative min-w-[12rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-cream-400" />
                  <label for="trend-search" class="sr-only">Tìm xu hướng</label>
                  <input id="trend-search" v-model="trendQuery" type="search" class="input !py-2 !pl-8 !text-xs" placeholder="Tìm theo tên, mô tả, hành động…">
                </div>
                <!-- Nói NGAY trên đầu danh sách: bao nhiêu hướng có tin thật, bao nhiêu là bộ có sẵn. -->
                <button
                  type="button"
                  class="tool-btn"
                  :class="{ 'is-active': liveOnly }"
                  :aria-pressed="liveOnly"
                  :disabled="!liveTrendCount"
                  :title="liveTrendCount ? 'Chỉ hiện các hướng máy chủ đo được từ tin thật' : 'Chưa có hướng nào gắn với tin thật — hãy cập nhật tin hoặc kiểm tra nguồn'"
                  @click="liveOnly = !liveOnly"
                >Có tin thật ({{ liveTrendCount }})</button>
                <span v-if="!liveTrendCount" class="text-label text-cream-400">↳ Chưa có hướng nào từ tin thật — bấm «Cập nhật tin» ở khối nguồn bên dưới.</span>
                <button type="button" class="tool-btn" :class="{ 'is-active': trendCategory === 'all' && !liveOnly }" :aria-pressed="trendCategory === 'all' && !liveOnly" @click="trendCategory = 'all'; liveOnly = false">Tất cả ({{ trends.length }})</button>
                <button v-for="category in trendCategories" :key="category.id" type="button" class="tool-btn" :class="{ 'is-active': trendCategory === category.id }" @click="trendCategory = category.id">{{ category.label }} ({{ category.count }})</button>
                <button v-for="row in lifecycles" :key="row.id" type="button" class="tool-btn" :class="{ 'is-active': lifecycleFilter === row.id }" @click="lifecycleFilter = lifecycleFilter === row.id ? 'all' : row.id">{{ row.label }} ({{ row.count }})</button>
                <button type="button" class="tool-btn" title="Chọn nhanh 3 trend có đà tăng cao nhất" @click="selectSuggested"><StudioIcon name="zap" size="h-3 w-3" /> Gợi ý 3</button>
                <button v-if="selectedTrendCount" type="button" class="tool-btn" @click="clearTrends"><StudioIcon name="trash" size="h-3 w-3" /> Bỏ chọn</button>
              </div>

              <div class="mb-2 flex flex-wrap items-center gap-2 text-label text-cream-400">
                <span>Hiện {{ visibleTrends.length }}/{{ trends.length }} hướng</span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2 py-0.5 font-semibold text-ok"><span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>{{ liveTrendCount }} đo từ tin thật</span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-2 py-0.5 font-semibold text-warn"><span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>{{ trends.length - liveTrendCount }} bộ có sẵn</span>
                <details class="ml-auto">
                  <summary class="cursor-pointer text-tiny text-cream-400 underline decoration-dotted">Giải thích</summary>
                  <p class="mt-1 max-w-md rounded-lg bg-ink-900 px-2.5 py-2 text-tiny leading-4 text-cream-300">
                    <b class="text-cream-200">Đo từ tin thật</b> = hướng xuất hiện trong bài báo máy chủ vừa lấy (kèm nguồn). <b class="text-cream-200">Bộ có sẵn</b> = hướng mẫu của FabrikAI, chưa gắn với tin vừa lấy. Số liệu thật giúp bạn chọn đúng hướng đang được thị trường nhắc tới.
                  </p>
                </details>
              </div>

              <div v-if="visibleTrends.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                  v-for="trend in visibleTrends"
                  :key="trend.id"
                  type="button"
                  class="group motion-ui relative overflow-hidden rounded-xl border bg-ink-900 p-4 text-left transition hover:border-brand-400 hover:bg-ink-800"
                  :class="selectedTrendIds.includes(String(trend.id)) ? 'border-brand-500 ring-1 ring-brand-500/50' : 'border-ink-600'"
                  :aria-pressed="selectedTrendIds.includes(String(trend.id))"
                  @click="toggleTrend(trend.id)"
                >
                  <span class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: trend.color || '#b9c8c2' }"></span>
                  <span class="flex items-start justify-between gap-3">
                    <span class="min-w-0">
                      <span class="flex flex-wrap items-center gap-1.5 text-label font-semibold uppercase tracking-wide text-cream-400">
                        {{ categoryLabel(trend.category) }}
                        <span class="rounded bg-ink-800 px-1.5 py-0.5 normal-case tracking-normal" :class="lifecycleClass(trend.lifecycle)">{{ lifecycleLabel(trend.lifecycle) }}</span>
                        <span v-if="trend.evidence_mode === 'live'" class="rounded bg-emerald-500/15 px-1.5 py-0.5 normal-case tracking-normal text-ok" title="Hướng này có tin thật nhắc tới — số liệu bên dưới là số ĐO từ các tin đó">có tin thật</span>
                        <span v-else class="rounded bg-amber-500/15 px-1.5 py-0.5 normal-case tracking-normal text-warn" title="Hướng này lấy từ bộ xu hướng có sẵn của FabrikAI, chưa gắn với tin thị trường vừa lấy">bộ có sẵn</span>
                      </span>
                      <span class="mt-1.5 block text-sm font-semibold text-cream-100">{{ trendTitle(trend) }}</span>
                      <span class="mt-1 block text-body leading-4 text-cream-400">{{ trend.description }}</span>
                    </span>
                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border" :class="selectedTrendIds.includes(String(trend.id)) ? 'border-brand-400 bg-brand-600 text-white' : 'border-ink-600 text-cream-400'">
                      <StudioIcon :name="selectedTrendIds.includes(String(trend.id)) ? 'check' : 'square'" size="h-3 w-3" />
                    </span>
                  </span>
                  <span class="mt-3 block text-label text-cream-400">{{ trendSignalLabel(trend) }}</span>
                  <span class="mt-1.5 block h-1 overflow-hidden rounded bg-ink-700"><span class="block h-full bg-gradient-to-r from-brand-500 to-amber-300" :style="{ width: Math.min(100, Number(trend.momentum || 0)) + '%' }"></span></span>
                  <span class="mt-2 block text-body leading-4 text-cream-400">{{ trend.recommended_action }}</span>
                </button>
              </div>
              <p v-else class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-6 text-center text-xs text-cream-400">
                {{ trends.length ? 'Không có xu hướng nào khớp bộ lọc hiện tại — bỏ bộ lọc để xem tất cả.' : 'Chưa đọc được xu hướng nào. Bấm «Tải lại»; nếu vẫn trống, kiểm tra nguồn tin ở khối «Nguồn dữ liệu cho phân tích».' }}
              </p>

              <details class="mt-5 rounded-xl border border-ink-700 bg-ink-900/70 p-4">
                <summary class="cursor-pointer text-xs font-semibold text-cream-200">Nguồn dữ liệu cho phân tích</summary>
                <!-- NGUỒN DỮ LIỆU: một câu trạng thái + tin thật đang dùng; chi tiết kỹ thuật gấp lại.
                     Không đưa tên tham số API, mã HTTP hay tên nhà cung cấp ra bề mặt người dùng. -->
                <div class="mt-3 rounded-xl border border-ink-700 bg-ink-900 p-3">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-body leading-5 text-cream-100">
                      <template v-if="liveSources">
                        Đang đọc <b class="text-ok">{{ newsItems.length }} tin thật</b> từ {{ activeSourceCount }} nguồn · cập nhật {{ fetchedAtLabel }}.
                      </template>
                      <template v-else>
                        Chưa có tin thật nào — phần phân tích đang dựa trên dữ liệu của bạn và bộ xu hướng mẫu.
                      </template>
                    </p>
                    <button type="button" class="tool-btn" :disabled="store.webSourcesLoading" @click="store.loadWebSources(true, selectedRegion)">
                      <StudioIcon name="refresh" size="h-3 w-3" /> {{ refreshLabel }}
                    </button>
                  </div>

                  <p v-if="store.webSourcesError" role="alert" class="mt-2 text-body text-danger">{{ store.webSourcesError }}</p>

                  <ul v-if="newsItems.length" class="mt-2 space-y-1 text-label leading-5 text-cream-300">
                    <li v-for="item in newsItems" :key="item.url">
                      · <a :href="item.url" target="_blank" rel="noopener" class="underline decoration-dotted hover:text-cream-100">{{ item.title }}</a>
                      <span class="text-cream-400"> — {{ item.source_name }}<template v-if="item.published_at"> · {{ shortDate(item.published_at) }}</template></span>
                    </li>
                  </ul>

                  <details v-if="store.webSources" class="mt-2">
                    <summary class="cursor-pointer text-label text-cream-400">Danh sách nguồn &amp; cách hoạt động</summary>
                    <div class="mt-1.5 overflow-x-auto">
                      <table class="w-full min-w-[26rem] text-left text-label">
                        <thead><tr class="border-b border-ink-700 text-cream-400"><th scope="col" class="pb-1.5 pr-2 font-semibold">Nguồn</th><th scope="col" class="pb-1.5 pr-2 font-semibold">Trạng thái</th><th scope="col" class="pb-1.5 font-semibold">Tin dùng được</th></tr></thead>
                        <tbody class="divide-y divide-ink-800">
                          <tr v-for="row in (store.webSources?.sources || [])" :key="row.slug">
                            <td class="py-1.5 pr-2"><a :href="row.url" target="_blank" rel="noopener" class="text-cream-200 underline decoration-dotted">{{ row.name }}</a></td>
                            <!-- Trạng thái nói ĐÚNG việc đang xảy ra: đang dùng · bị lọc hết · nguồn không có tin ·
                                 đang dùng bản lấy trước · bỏ qua vì khác vùng. Không gộp thành "Không lấy được". -->
                            <td class="py-1.5 pr-2">
                              <span :class="sourceStatusTone(row)">{{ sourceStatus(row) }}</span>
                              <span v-if="row.parsed && row.count === 0" class="block text-cream-400">{{ row.parsed }} tin đọc được nhưng không tin nào qua bộ lọc</span>
                              <span v-else-if="row.parsed" class="block text-cream-400">{{ row.parsed }} tin đọc được</span>
                            </td>
                            <td class="py-1.5 tabular-nums text-cream-300">{{ row.count }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                    <!-- Số ĐO, không phải lời hứa: câu này đổi theo nhịp tim của lịch chạy nền trên máy chủ.
                         Bản trước viết cứng "tự lấy tin mỗi 30 phút" trong khi host KHÔNG có cron nào. -->
                    <p class="mt-1.5 text-label leading-5" :class="autoRefresh.alive ? 'text-cream-400' : 'text-warn'">
                      {{ autoRefresh.label }}
                      Chỉ giữ tin trong {{ store.webSources?.limits?.max_age_days }} ngày và tối đa {{ store.webSources?.limits?.limit }} tin cho mỗi lần phân tích.
                    </p>
                    <p class="mt-1 text-label leading-5 text-cream-400">
                      AI không tự ra internet: mọi tin đều do máy chủ FabrikAI đi lấy — kể cả khi model gọi
                      công cụ tìm kiếm thì người đi lấy vẫn là máy chủ.
                    </p>
                  </details>
                </div>

                <p class="mt-3 text-body leading-5 text-cream-400">
                  Dữ liệu nội bộ là dự án và ảnh của chính tài khoản bạn.
                  Kênh chưa kết nối: Shopee · TikTok Shop · Lazada · Instagram · sàn quốc tế · runway.
                </p>

                    <!-- KHẢ NĂNG TRUY CẬP INTERNET — số ĐO, không phải câu văn (docs/DESIGN_SYSTEM.md §18.2).
                         Trước đây khối này được nạp mỗi lần mở màn hình nhưng KHÔNG hiện ở đâu cả. -->
                    <div class="mt-3 rounded-lg border border-ink-700 bg-ink-900 p-3">
                      <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-body font-semibold text-cream-100">Khả năng đọc tin từ internet</p>
                        <button
                          type="button"
                          class="tool-btn"
                          :disabled="store.webAccessLoading"
                          title="Đo lại ngay: máy chủ có ra được internet hay không"
                          @click="store.loadWebAccess(true)"
                        >
                          <StudioIcon name="refresh" size="h-3 w-3" :class="store.webAccessLoading ? 'animate-spin' : ''" />
                          {{ store.webAccessLoading ? 'Đang kiểm tra…' : 'Kiểm tra lại' }}
                        </button>
                      </div>
                      <p v-if="store.webAccessError" role="alert" class="mt-2 text-body text-danger">{{ store.webAccessError }}</p>
                      <p v-else-if="internetVerdict" class="mt-1.5 text-body leading-5 text-cream-300">{{ internetVerdict }}</p>
                      <p v-else class="mt-1.5 text-body leading-5 text-cream-400">Chưa đo được — bấm «Kiểm tra lại».</p>
                      <!-- SỐ ĐO CỦA LƯỢT CHẠY NÀY: lượt vừa rồi có tìm thật hay không, hỏi gì, được mấy tin.
                           Đây là câu trả lời cho "nguồn ngoài ở đâu ra", nên chỉ hiện khi lượt chạy CÓ tìm kiếm. -->
                      <p v-if="toolSearchLine" class="mt-1.5 text-body leading-5 text-cream-300">{{ toolSearchLine }}</p>
                      <ul v-if="groupsNeedingSetup.length" class="mt-2 space-y-0.5 text-label leading-5 text-cream-300">
                        <li v-for="row in groupsNeedingSetup" :key="row.group">· {{ row.label }}: {{ row.configured ? 'chưa có khoá dùng được' : 'chưa gán model' }}</li>
                      </ul>
                    </div>
              </details>
            </template>
          </section>
</template>
