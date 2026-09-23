<script setup>
// TÁCH từ DesignAgents.vue (đợt tối ưu 2026-09-24) — BƯỚC RADAR.
// Shell cung cấp toàn bộ trạng thái/logic qua provide(); component này chỉ inject đúng bề mặt nó dùng
// rồi giữ NGUYÊN VĂN template của bước. Xem shell để biết định nghĩa gốc.
import { computed, inject, onMounted, watch } from 'vue';
import { MOOD_COLOR } from '../../dataColors.js';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';
import LoadingSpinner from '../LoadingSpinner.vue';
import AgentSubSteps from './AgentSubSteps.vue';
const store = useStudioStore();
const sub = inject('sub');
const trendQuery = inject('trendQuery');
const trendCategory = inject('trendCategory');
const liveOnly = inject('liveOnly');
const lifecycleFilter = inject('lifecycleFilter');
const liveSources = inject('liveSources');
const liveTrendCount = inject('liveTrendCount');
const aiTrendCount = inject('aiTrendCount');
// Hướng AI ĐÃ TRA mà không ra tin — trạng thái thứ ba, tách khỏi "bộ có sẵn".
const aiCheckedCount = inject('aiCheckedCount');
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
// Số đo "AI có tự ra internet không" — hiện NGAY trên đầu khối định hướng (không gấp lại).
const aiSearchShort = inject('aiSearchShort');
const aiSearchQueries = inject('aiSearchQueries');
const aiSearchSources = inject('aiSearchSources');
const aiSearchItems = inject('aiSearchItems');
const aiSearchMode = inject('aiSearchMode');
const aiSearchOn = inject('aiSearchOn');
const step = inject('step');
const radar = inject('radar');
// HƯỚNG MẪU ĐÃ BỊ ẨN (2026-09-26): máy chủ chỉ trả về hướng có bằng chứng THẬT khi lượt này đã có dữ liệu
// thật, và bỏ các hướng của BỘ CÓ SẴN sang khoá riêng. Giao diện phải NÓI RA số đã ẩn — nếu không, người
// dùng thấy danh sách ngắn đi mà không hiểu vì sao.
const demoHidden = computed(() => Number(radar.value?.demo_hidden || 0));

// ── NÓI THẬT KHI LƯỢT NÀY KHÔNG TRA ĐƯỢC HƯỚNG NÀO (2026-09-26) ────────────────────────────────
// [ĐỔI CHÍNH SÁCH 2026-09-26] Trước đây lượt không tra được gì vẫn hiện đủ bộ hướng MẪU, nên màn hình
// không bao giờ trống và người dùng đọc dữ liệu mẫu như số liệu thị trường. Nay trends chỉ có hướng có
// bằng chứng thật ⇒ có thể RỖNG, và khi rỗng thì phải NÓI RA: tra bằng gì, vì sao không ra, làm gì tiếp.
// Lý do cụ thể lấy từ MÁY CHỦ (radar.external_evidence.search_error) — giao diện không tự đoán.
const emptyTrendNote = computed(() => {
  const reason = String(radar.value?.external_evidence?.search_error || '');
  const hidden = demoHidden.value ? ' (' + demoHidden.value + ' hướng mẫu đã được tách ra)' : '';
  const why = reason ? ' Lý do: ' + reason + '.' : '';
  return 'Lượt này chưa tra được hướng nào có bằng chứng thật trên web.'
    + ' FabrikAI không lấp chỗ trống bằng danh mục có sẵn' + hidden + '.' + why
    + ' Bấm «Tải lại» để tra lại, hoặc kiểm tra nguồn tìm kiếm ở khối «Nguồn dữ liệu cho phân tích» bên dưới.';
});
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

// ── SỔ NGUỒN AI ĐÃ TRA (2026-09-26) ────────────────────────────────────────────────────────────
// Khối này đọc THẲNG store (agentFindings*) thay vì qua provide(): nó là dữ liệu của MỘT khối trong
// đúng bước này, không phải bề mặt dùng chung của cả 4 bước — thêm một tầng provide() chỉ để chuyển
// tiếp thì bốn bước khác phải mang theo thứ chúng không dùng.
//
// Trần 6 nguồn là CÓ CHỦ Ý: khối này trả lời "AI tra được gì, tôi giữ cái nào", không phải một thư viện.
// Sổ đầy hơn thì người dùng lọc «Chỉ nguồn đã lưu» (server lọc) thay vì cuộn một danh sách dài.
const MAX_FINDINGS = 6;
const findings = computed(() => store.agentFindings || []);
const findingsStats = computed(() => store.agentFindingsStats || { total: 0, saved: 0, fresh: 0 });
const visibleFindings = computed(() => findings.value.slice(0, MAX_FINDINGS));
const findingsHidden = computed(() => Math.max(0, findings.value.length - MAX_FINDINGS));

/** Nút "Tải lại": LUÔN gọi thật (force) — bấm mà không thấy gì đổi thì nút là đồ trang trí. */
function reloadFindings() {
  store.loadFindings(selectedRegion.value, true);
}

/** Đổi bộ lọc ⇒ nạp lại từ server: lọc ở client thì dòng số đo và danh sách sẽ nói hai chuyện khác nhau. */
function toggleFindingsSavedOnly() {
  store.agentFindingsSavedOnly = !store.agentFindingsSavedOnly;
  store.loadFindings(selectedRegion.value, true);
}

// Nạp khi MỞ bước này. Không dùng "chỉ nạp khi rỗng" như khối nguồn ngoài: sổ này lớn lên theo MỖI lượt
// agent tự tra (kể cả lượt chạy ở bước Định hướng), nên danh sách cũ là danh sách thiếu — và người dùng
// vừa trả tiền cho lượt chạy đó. Một lượt GET nhỏ, có chốt chống gọi trùng trong action.
onMounted(() => store.loadFindings(selectedRegion.value));

// Đổi khu vực ⇒ sổ phải theo khu vực đó (server lọc theo vùng, vùng 'all' luôn được tính kèm).
watch(selectedRegion, (region, previous) => {
  if (region === previous) return;
  store.loadFindings(region, true);
});
</script>

<template>
          <section id="agent-step-radar" role="region" aria-label="Tín hiệu" :aria-busy="store.trendRadarLoading">
            <AgentSubSteps step-id="radar" />
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
              <div class="min-w-0">
                <h2 class="text-sm font-semibold text-cream-100 sm:text-base">Tín hiệu thị trường</h2>
                <p class="mt-0.5 text-tiny text-cream-400 sm:text-xs">Chọn 2–4 hướng phù hợp nhất với DNA shop. Chưa chọn cũng chạy được.</p>
              </div>
              <div class="flex shrink-0 items-center gap-1.5">
                <label for="agent-region" class="sr-only">Khu vực đọc tín hiệu</label>
                <select id="agent-region" v-model="selectedRegion" :disabled="store.trendRadarLoading" class="input !w-auto !bg-ink-800 !py-1.5 !text-xs !text-cream-100">
                  <option v-for="region in regions" :key="region.id" :value="region.id">{{ region.name }}</option>
                </select>
                <button type="button" class="tool-btn !px-2 !py-1.5" :disabled="store.trendRadarLoading" @click="loadRadar(selectedRegion, { force: true })">
                  <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="store.trendRadarLoading ? 'animate-spin' : ''" /> <span class="hidden sm:inline">Tải lại</span>
                </button>
              </div>
            </div>

            <div v-if="store.trendRadarError" role="alert" class="mb-4 flex gap-2 rounded-lg border border-danger/40 bg-danger/10 p-3 text-xs text-danger">
              <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ store.trendRadarError }}</span>
            </div>

            <div v-if="radar" class="mb-3">
              <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="item in summaryItems" :key="item.key" class="rounded-lg border border-ink-700 bg-ink-900 px-2.5 py-2">
                  <p class="text-tiny leading-3 text-cream-400">{{ item.label }}</p>
                  <p class="mt-0.5 text-base font-semibold tabular-nums text-cream-100 sm:text-lg">{{ formatNumber(item.value) }}</p>
                  <p class="text-tiny leading-3 text-cream-400">{{ item.fromNews ? 'tin thật' : 'của bạn' }}</p>
                </div>
              </div>
              <!-- Nguồn của con số phải nằm NGAY DƯỚI số, không gấp trong khối khác (§18.2). -->
              <p class="mt-1 text-tiny leading-4 text-cream-400 sm:text-label sm:leading-5">
                {{ marketLive
                  ? 'Số đầu tiên là số ĐO từ kết quả máy chủ tra trên web; mỗi hướng ghi rõ nó có bằng chứng thật hay không.'
                  : 'Lượt này chưa tra được tin nào trên web, nên chưa có số liệu thị trường nào để hiển thị.' }}
              </p>
              <!-- [ĐỔI CHÍNH SÁCH 2026-09-26 — KHÔNG DỮ LIỆU MẪU Ở BƯỚC 2] Bộ có sẵn LUÔN bị tách khỏi danh
                   sách hướng (nằm ở khoá riêng, không bị xoá), không chỉ khi lượt này có hướng thật. Nói RA
                   số đã tách: nếu im lặng thì người dùng chỉ thấy danh sách ngắn đi mà không hiểu vì sao. -->
              <p v-if="demoHidden" class="mt-1 text-tiny leading-4 text-cream-400 sm:text-label sm:leading-5">
                Đã tách {{ demoHidden }} hướng thuộc bộ có sẵn của FabrikAI ra khỏi danh sách — lượt này chỉ hiện hướng có bằng chứng thật, không lấp chỗ trống bằng dữ liệu mẫu.
              </p>
              <!-- CÁCH DỮ LIỆU ĐƯỢC LƯU · QUẢN LÝ · TÁI SỬ DỤNG — trả lời "lưu ở đâu, ai lưu, dùng lại ra sao". -->
              <details class="mt-1">
                <summary class="cursor-pointer text-tiny text-cream-400 underline decoration-dotted sm:text-label">Cách dữ liệu này được lưu &amp; tái sử dụng</summary>
                <div class="mt-1 space-y-1 rounded-lg bg-ink-900 px-2.5 py-2 text-tiny leading-4 text-cream-300 sm:text-label sm:leading-5">
                  <p><b class="text-cream-200">Lưu tự động:</b> mỗi lần đo, hệ thống ghi "ảnh chụp" nếu dữ liệu đổi hoặc cũ hơn 12 giờ — bạn không phải bấm lưu.</p>
                  <p><b class="text-cream-200">Quản lý:</b> ảnh chụp cũ hơn 120 ngày tự xoá.</p>
                  <p><b class="text-cream-200">Tái sử dụng:</b> số "tăng/giảm %" được tính bằng cách so ảnh chụp mới nhất với các lần trước — thấy hướng nào đang lên hay chậm lại.</p>
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
              <template v-if="sub === 'signals'">
              <!-- TÍN HIỆU ĐO TỪ TIN THẬT: thu gọn + giải thích ngôn ngữ thường. Số liệu thật, đo bằng
                   thuật toán KHÔNG cần AI — nhưng không nên chiếm hết màn hình: mặc định thu khi nhiều tín hiệu. -->
              <details v-if="marketLive" class="mb-5 rounded-xl border border-ink-700 bg-ink-900/70" :open="marketSignals.length <= 4">
                <summary class="cursor-pointer select-none px-4 py-3">
                  <span class="flex flex-wrap items-center gap-2">
                    <StudioIcon name="scan" size="h-4 w-4" class="text-ok" />
                    <span class="font-display text-base font-semibold text-cream-50">Tín hiệu đo từ kết quả tìm kiếm ({{ marketSignals.length }})</span>
                    <!-- Nhãn cũ "đo tự động · không cần AI" đọc lên thành "agent không dùng AI" — trong khi
                         lượt chạy có thể đang dùng công cụ tìm kiếm của model. Nhãn này nói ĐÚNG phạm vi của
                         nó: CON SỐ ở khối này do thuật toán đo, không phải AI đoán. -->
                    <span class="rounded-full bg-ok/15 px-2 py-0.5 text-label font-semibold text-ok" title="Các con số trong khối này do thuật toán đếm từ bài viết tra được trên web, không phải AI viết ra">số đo từ kết quả tìm kiếm · không phải AI đoán</span>
                  </span>
                  <span class="mt-1 block text-label leading-4 text-cream-400">Máy chủ tự tra trên web bằng các câu hỏi chung về ngành, rồi đếm từ khoá đang được nhắc tới trong chính kết quả tra được — số liệu THẬT kèm nguồn, không phải dự đoán của AI.{{ marketAgeLabel ? ' · đo ' + marketAgeLabel : '' }}</span>
                </summary>
                <div class="border-t border-ink-700 px-4 pb-3 pt-2.5">
                  <div class="flex flex-wrap gap-1.5">
                    <span v-for="signal in marketSignals" :key="signal.category + signal.term" class="inline-flex items-center gap-1.5 rounded-lg border border-ink-700 bg-ink-900 px-2.5 py-1.5 text-label">
                      <span class="font-semibold text-cream-100">{{ signal.term }}</span>
                      <span class="text-cream-400">{{ signal.mentions }} tin · {{ signal.source_count }} nguồn</span>
                      <span v-if="signal.change_pct === null || signal.change_pct === undefined" class="text-tiny text-cream-400">lần đo đầu</span>
                      <span v-else class="rounded px-1 py-0.5 text-tiny font-semibold" :class="signal.change_pct >= 0 ? 'bg-ok/15 text-ok' : 'bg-warn/15 text-warn'">{{ signal.change_pct >= 0 ? '↗' : '↘' }} {{ Math.abs(signal.change_pct) }}%</span>
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
              <div v-if="directions.length" class="mb-4 rounded-xl border border-ink-700 bg-ink-900/70 p-3 sm:p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <h3 class="font-display text-base font-semibold text-brand-300">Định hướng từ TrendRadar ({{ directions.length }})</h3>
                    <p class="mt-0.5 text-body leading-5 text-cream-400">Chọn trend gắn với hướng bạn muốn — AI sẽ dựng brief bám theo các trend đã chọn.</p>
                    <!-- AI CÓ TỰ RA INTERNET KHÔNG — hỏi thẳng câu người dùng hay hỏi, trả lời bằng SỐ ĐO của
                         lượt chạy này. Trước đây câu này nằm trong <details> nên mở màn hình chỉ thấy danh
                         sách tin máy chủ lấy sẵn ⇒ tưởng agent không hề dùng công cụ tìm kiếm. -->
                    <p v-if="aiSearchShort" class="mt-1.5 flex flex-wrap items-center gap-1.5 text-label">
                      <span
                        class="rounded-full px-2 py-0.5 font-semibold"
                        :class="aiSearchQueries.length ? 'bg-brand-500/15 text-brand-200' : 'bg-ink-800 text-cream-400'"
                        :title="toolSearchLine"
                      >{{ aiSearchShort }}</span>
                      <span v-if="aiSearchQueries.length" class="text-cream-400">từ khoá: {{ aiSearchQueries.slice(0, 3).join(' · ') }}<template v-if="aiSearchQueries.length > 3"> · …</template></span>
                      <span v-if="aiSearchSources.length" class="text-cream-400">· mở {{ aiSearchSources.length }} trang nguồn</span>
                    </p>
                    <!-- TIN THẬT lấy được từ chính câu hỏi của model — có URL để người dùng tự kiểm. Đây là
                         câu trả lời cho "AI tìm rồi, nhưng tìm được gì?": không có phần này thì việc AI tự tra
                         không để lại dấu vết nào kiểm chứng được. -->
                    <details v-if="aiSearchItems.length" class="mt-2">
                      <summary class="cursor-pointer text-label text-cream-400">AI tìm được {{ aiSearchItems.length }} tin — bấm để xem nguồn</summary>
                      <ul class="mt-1.5 space-y-1 text-label leading-4 text-cream-300">
                        <li v-for="item in aiSearchItems.slice(0, 6)" :key="item.url">
                          · <a :href="item.url" target="_blank" rel="noopener" class="underline decoration-dotted hover:text-cream-100">{{ item.title }}</a>
                          <span class="text-cream-400"> — {{ item.source_name }}<template v-if="item.published_at"> · {{ shortDate(item.published_at) }}</template></span>
                        </li>
                      </ul>
                    </details>
                  </div>
                  <button type="button" class="tool-btn" title="Chọn các trend mà 3 định hướng mạnh nhất đang nhắc tới" @click="focusDirections">
                    <StudioIcon name="target" size="h-3 w-3" /> Chọn 3 hướng đầu
                  </button>
                </div>
                <div class="mt-2.5 grid gap-2 lg:grid-cols-2">
                  <article v-for="row in directions" :key="row.id" class="rounded-lg border border-ink-700 bg-ink-800 p-2.5 sm:p-3">
                    <div class="flex items-start justify-between gap-2">
                      <h4 class="text-sm font-semibold leading-5 text-cream-50">{{ row.title }}</h4>
                      <span
                        class="shrink-0 rounded px-1.5 py-0.5 text-tiny font-semibold uppercase tracking-wide"
                        :class="row.source === 'ai' ? 'bg-ok/15 text-ok' : 'bg-ink-700 text-cream-400'"
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

              </template>

              <template v-else>
              <div class="mb-2 flex flex-wrap items-center gap-1.5">
                <div class="relative min-w-[10rem] flex-1">
                  <StudioIcon name="search" size="h-3.5 w-3.5" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-cream-400" />
                  <label for="trend-search" class="sr-only">Tìm xu hướng</label>
                  <input id="trend-search" v-model="trendQuery" type="search" class="input !py-1.5 !pl-8 !text-xs" placeholder="Tìm theo tên, mô tả…">
                </div>
                <!-- Nói NGAY trên đầu danh sách: bao nhiêu hướng có tin thật, bao nhiêu là bộ có sẵn. -->
                <button
                  type="button"
                  class="tool-btn !px-2 !py-1.5 !text-tiny"
                  :class="{ 'is-active': liveOnly }"
                  :aria-pressed="liveOnly"
                  :disabled="!liveTrendCount"
                  :title="liveTrendCount ? 'Chỉ hiện các hướng máy chủ đo được từ tin thật' : 'Chưa có hướng nào gắn với tin thật — hãy cập nhật tin hoặc kiểm tra nguồn'"
                  @click="liveOnly = !liveOnly"
                >Có tin thật ({{ liveTrendCount }})</button>
                <span v-if="!liveTrendCount" class="text-label text-cream-400">↳ Lượt này chưa tra được hướng nào có bằng chứng — xem khối «Nguồn dữ liệu cho phân tích» bên dưới.</span>
                <button type="button" class="tool-btn" :class="{ 'is-active': trendCategory === 'all' && !liveOnly }" :aria-pressed="trendCategory === 'all' && !liveOnly" @click="trendCategory = 'all'; liveOnly = false">Tất cả ({{ trends.length }})</button>
                <button v-for="category in trendCategories" :key="category.id" type="button" class="tool-btn" :class="{ 'is-active': trendCategory === category.id }" @click="trendCategory = category.id">{{ category.label }} ({{ category.count }})</button>
                <button v-for="row in lifecycles" :key="row.id" type="button" class="tool-btn" :class="{ 'is-active': lifecycleFilter === row.id }" @click="lifecycleFilter = lifecycleFilter === row.id ? 'all' : row.id">{{ row.label }} ({{ row.count }})</button>
                <button type="button" class="tool-btn" title="Chọn nhanh 3 trend có đà tăng cao nhất" @click="selectSuggested"><StudioIcon name="zap" size="h-3 w-3" /> Gợi ý 3</button>
                <button v-if="selectedTrendCount" type="button" class="tool-btn" @click="clearTrends"><StudioIcon name="trash" size="h-3 w-3" /> Bỏ chọn</button>
              </div>

              <div class="mb-2 flex flex-wrap items-center gap-2 text-label text-cream-400">
                <span>Hiện {{ visibleTrends.length }}/{{ trends.length }} hướng</span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-ok/15 px-2 py-0.5 font-semibold text-ok"><span class="h-1.5 w-1.5 rounded-full bg-ok"></span>{{ liveTrendCount }} đo từ tin thật</span>
                <!-- TÁCH RIÊNG phần do AI chủ động tra trong lượt này: đó không phải ảnh chụp định kỳ của
                     nguồn cấu hình, và gộp vào "đo từ tin thật" là nói thiếu sự thật về nguồn gốc số liệu. -->
                <span v-if="aiTrendCount" class="inline-flex items-center gap-1.5 rounded-full bg-brand-500/15 px-2 py-0.5 font-semibold text-brand-200" title="Hướng này khớp tin mà AI tự tra trong lượt này — máy chủ chạy lại câu hỏi của model trên nguồn tìm kiếm thật">
                  <span class="h-1.5 w-1.5 rounded-full bg-brand-400"></span>{{ aiTrendCount }} AI tìm thấy
                </span>
                <!-- TRẠNG THÁI THỨ BA: đã tra mà không ra tin. Tách khỏi "bộ có sẵn" vì "chưa ai tra" và
                     "đã tra, không có tin" là hai chuyện khác nhau — gộp lại thì người dùng không biết hệ
                     thống đã thử hay chưa. -->
                <span v-if="aiCheckedCount" class="inline-flex items-center gap-1.5 rounded-full bg-ink-700 px-2 py-0.5 font-semibold text-cream-200" title="AI đã tra hướng này trong lượt chạy (máy chủ chạy lại đúng câu hỏi của model) nhưng không có tin nào nhắc tới — số liệu vẫn là số mẫu">
                  <span class="h-1.5 w-1.5 rounded-full bg-cream-400"></span>{{ aiCheckedCount }} AI đã tra, chưa có tin
                </span>
                <span v-if="demoHidden" class="inline-flex items-center gap-1.5 rounded-full bg-warn/15 px-2 py-0.5 font-semibold text-warn" title="Hướng mẫu của FabrikAI — đã tách khỏi danh sách vì lượt này chỉ hiện hướng có bằng chứng thật"><span class="h-1.5 w-1.5 rounded-full bg-warn"></span>{{ demoHidden }} bộ có sẵn (đã tách)</span>
                <details class="ml-auto">
                  <summary class="cursor-pointer text-tiny text-cream-400 underline decoration-dotted">Giải thích</summary>
                  <p class="mt-1 max-w-md rounded-lg bg-ink-900 px-2.5 py-2 text-tiny leading-4 text-cream-300">
                    <b class="text-cream-200">Đo từ kết quả tìm kiếm</b> = hướng xuất hiện trong những bài máy chủ tự tra trên web bằng các câu hỏi chung về ngành (kèm nguồn để bạn bấm vào kiểm). <b class="text-cream-200">AI tìm thấy</b> = hướng khớp tin mà AI tự tra trong lượt này: model quyết định hỏi gì, máy chủ chạy lại đúng câu hỏi đó trên nguồn tìm kiếm thật rồi đếm — cũng có nguồn để bấm vào kiểm. <b class="text-cream-200">AI đã tra, chưa có tin</b> = lượt chạy NÀY đã hỏi thẳng về hướng đó nhưng không nguồn nào nhắc tới — số liệu vẫn là số mẫu, đừng đọc như số đo. <b class="text-cream-200">Bộ có sẵn</b> = hướng mẫu của FabrikAI, lượt này chưa tra tới.
                  </p>
                </details>
              </div>

              <div v-if="visibleTrends.length" class="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
                <button
                  v-for="trend in visibleTrends"
                  :key="trend.id"
                  type="button"
                  class="group motion-ui relative overflow-hidden rounded-xl border bg-ink-900 p-3 text-left transition hover:border-brand-400 hover:bg-ink-800 sm:p-4"
                  :class="selectedTrendIds.includes(String(trend.id)) ? 'border-brand-500 ring-1 ring-brand-500/50' : 'border-ink-600'"
                  :aria-pressed="selectedTrendIds.includes(String(trend.id))"
                  @click="toggleTrend(trend.id)"
                >
                  <span class="absolute inset-y-0 left-0 w-1" :style="{ backgroundColor: trend.color || MOOD_COLOR }"></span>
                  <span class="flex items-start justify-between gap-3">
                    <span class="min-w-0">
                      <span class="flex flex-wrap items-center gap-1.5 text-label font-semibold uppercase tracking-wide text-cream-400">
                        {{ categoryLabel(trend.category) }}
                        <span class="rounded bg-ink-800 px-1.5 py-0.5 normal-case tracking-normal" :class="lifecycleClass(trend.lifecycle)">{{ lifecycleLabel(trend.lifecycle) }}</span>
                        <!-- BA nhãn, không phải hai: tin của feed định kỳ · tin do AI tự tra trong lượt này ·
                             hướng mẫu chưa gắn tin nào. Gộp hai cái đầu là nói thiếu nguồn gốc số liệu. -->
                        <span v-if="(trend.live?.origin || trend.evidence_origin) === 'ai'" class="rounded bg-brand-500/15 px-1.5 py-0.5 normal-case tracking-normal text-brand-200" title="Hướng này khớp tin mà AI tự tra trong lượt này (model hỏi, máy chủ đi lấy) — số liệu bên dưới đo từ chính những tin đó, có link để bạn kiểm">AI tìm thấy</span>
                        <span v-else-if="trend.evidence_mode === 'live'" class="rounded bg-ok/15 px-1.5 py-0.5 normal-case tracking-normal text-ok" title="Hướng này có tin thật nhắc tới — số liệu bên dưới là số ĐO từ các tin đó">có tin thật</span>
                        <span v-else-if="trend.checked_by_ai" class="rounded bg-ink-700 px-1.5 py-0.5 normal-case tracking-normal text-cream-200" title="Lượt chạy này đã tra thẳng hướng này (máy chủ chạy lại đúng câu hỏi của model) nhưng không nguồn nào nhắc tới — số liệu bên dưới vẫn là số mẫu của FabrikAI">AI đã tra · chưa có tin</span>
                        <span v-else class="rounded bg-warn/15 px-1.5 py-0.5 normal-case tracking-normal text-warn" :title="'Hướng mẫu của FabrikAI — lượt chạy này chưa tra tới hướng này'">bộ có sẵn</span>
                      </span>
                      <span class="mt-1.5 block text-sm font-semibold text-cream-100">{{ trendTitle(trend) }}</span>
                      <span class="mt-1 block text-body leading-4 text-cream-400">{{ trend.description }}</span>
                    </span>
                    <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border" :class="selectedTrendIds.includes(String(trend.id)) ? 'border-brand-400 bg-brand-600 text-primary-content' : 'border-ink-600 text-cream-400'">
                      <StudioIcon :name="selectedTrendIds.includes(String(trend.id)) ? 'check' : 'square'" size="h-3 w-3" />
                    </span>
                  </span>
                  <span class="mt-2 block text-label text-cream-400">{{ trendSignalLabel(trend) }}</span>
                  <span class="mt-1 block h-1 overflow-hidden rounded bg-ink-700"><span class="block h-full bg-gradient-to-r from-brand-500 to-warn" :style="{ width: Math.min(100, Number(trend.momentum || 0)) + '%' }"></span></span>
                  <span class="mt-2 block text-body leading-4 text-cream-400">{{ trend.recommended_action }}</span>
                </button>
              </div>
              <p v-else class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-6 text-center text-xs text-cream-400">
                {{ trends.length ? 'Không có xu hướng nào khớp bộ lọc hiện tại — bỏ bộ lọc để xem tất cả.' : emptyTrendNote }}
              </p>

              <!-- SỔ NGUỒN AI ĐÃ TRA — mặt NHÌN THẤY của vòng khép kín: công cụ tìm kiếm của agent đã ghi
                   mọi nguồn vào sổ theo tài khoản, nguồn đó quay lại khối DỮ LIỆU ở lượt chạy sau. Trước
                   đây chỗ này chỉ có CON SỐ ĐẾM nên người dùng không bấm vào đâu được và không giữ lại
                   được nguồn nào — không kiểm chứng được câu trả lời, và gu của họ không nuôi lượt sau.
                   Nút «Lưu» KHÔNG phải trang trí: nguồn đã lưu được xếp trước khi agent dùng lại. -->
              <div class="mt-4 rounded-xl border border-ink-700 bg-ink-900/70 p-3 sm:p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div class="min-w-0">
                    <p class="text-xs font-semibold text-cream-200">Nguồn AI đã tra được</p>
                    <!-- Dòng SỐ ĐO lấy nguyên từ sổ của máy chủ — không tự đếm ở trình duyệt rồi khoe một
                         con số khác với con số máy chủ đang dùng để xếp hạng nguồn. -->
                    <p class="mt-0.5 text-label leading-4 text-cream-400">
                      AI đã tra <b class="text-cream-100">{{ findingsStats.total }}</b> nguồn · <b class="text-cream-100">{{ findingsStats.saved }}</b> nguồn bạn đã lưu<template v-if="findingsStats.fresh !== findingsStats.total"> · {{ findingsStats.fresh }} còn dùng lại được</template>
                    </p>
                  </div>
                  <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                    <button
                      type="button"
                      class="tool-btn !px-2 !py-1.5 !text-tiny"
                      :class="{ 'is-active': store.agentFindingsSavedOnly }"
                      :aria-pressed="store.agentFindingsSavedOnly"
                      title="Chỉ hiện những nguồn bạn đã bấm Lưu"
                      @click="toggleFindingsSavedOnly"
                    ><StudioIcon name="save" size="h-3.5 w-3.5" /> Chỉ nguồn đã lưu ({{ findingsStats.saved }})</button>
                    <button type="button" class="tool-btn !px-2 !py-1.5 !text-tiny" :disabled="store.agentFindingsLoading" @click="reloadFindings">
                      <StudioIcon name="refresh" size="h-3.5 w-3.5" :class="store.agentFindingsLoading ? 'animate-spin' : ''" /> Tải lại
                    </button>
                  </div>
                </div>

                <p v-if="store.agentFindingsError" role="alert" class="mt-2 text-body text-danger">{{ store.agentFindingsError }}</p>

                <!-- Bộ đếm dùng CHUNG <LoadingSpinner> (§3) — không tự vẽ bộ chấm riêng cho khối này. -->
                <LoadingSpinner v-if="store.agentFindingsLoading && !visibleFindings.length" size="sm" text="Đang tải nguồn AI đã tra…" />

                <ul v-else-if="visibleFindings.length" class="mt-2 space-y-1.5">
                  <li v-for="row in visibleFindings" :key="row.id" class="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-ink-700 bg-ink-900 px-2.5 py-2">
                    <div class="min-w-0 flex-1">
                      <a :href="row.url" target="_blank" rel="noopener" class="text-label font-semibold text-cream-100 underline decoration-dotted hover:text-brand-200">{{ row.title || row.url }}</a>
                      <p class="mt-0.5 text-tiny leading-4 text-cream-400">
                        {{ row.source_name || 'Nguồn trên internet' }}<template v-if="row.published_at"> · bài đăng {{ shortDate(row.published_at) }}</template><template v-else-if="row.last_seen_at"> · tra {{ shortDate(row.last_seen_at) }}</template><template v-if="Number(row.hits) > 1"> · gặp {{ row.hits }} lần</template><template v-if="row.saved"> · <span class="text-ok">bạn đã lưu</span></template>
                      </p>
                      <!-- CÂU HỎI ĐÃ TRA: thiếu nó thì người dùng chỉ thấy một dãy link rời rạc và không
                           biết vì sao nguồn này lại nằm ở đây, cũng không biết hỏi lại thế nào. -->
                      <p v-if="row.found_query" class="mt-0.5 text-tiny leading-4 text-cream-400">Câu hỏi đã tra: {{ row.found_query }}</p>
                    </div>
                    <button
                      type="button"
                      class="tool-btn !px-2 !py-1.5 !text-tiny"
                      :disabled="store.agentFindingBusyId === row.id"
                      :title="row.saved
                        ? 'Bỏ lưu: nguồn này thôi được ưu tiên khi agent dùng lại ở lượt chạy sau'
                        : 'Giữ nguồn này lại — nguồn bạn lưu được xếp trước khi agent dùng lại ở lượt chạy sau'"
                      @click="store.toggleFindingSaved(row.id, !row.saved)"
                    >
                      <StudioIcon :name="row.saved ? 'x' : 'save'" size="h-3.5 w-3.5" />
                      {{ store.agentFindingBusyId === row.id ? (row.saved ? 'Đang bỏ lưu…' : 'Đang lưu…') : (row.saved ? 'Bỏ lưu' : 'Lưu') }}
                    </button>
                  </li>
                </ul>

                <p v-else class="mt-2 text-body leading-5 text-cream-400">
                  {{ store.agentFindingsSavedOnly
                    ? 'Bạn chưa lưu nguồn nào — bỏ lọc «Chỉ nguồn đã lưu» để xem mọi nguồn AI đã tra.'
                    : 'Chưa có nguồn nào — nguồn sẽ xuất hiện ở đây sau khi AI tự tra.' }}
                </p>

                <p v-if="findingsHidden" class="mt-2 text-tiny leading-4 text-cream-400">
                  Còn {{ findingsHidden }} nguồn nữa trong sổ (khối này chỉ hiện {{ MAX_FINDINGS }} nguồn gần nhất) — nguồn bạn lưu luôn được xếp trước.
                </p>
              </div>

              <details class="mt-4 rounded-xl border border-ink-700 bg-ink-900/70 p-3 sm:p-4">
                <summary class="cursor-pointer text-xs font-semibold text-cream-200">Nguồn dữ liệu cho phân tích</summary>
                <!-- NGUỒN DỮ LIỆU: một câu trạng thái + tin thật đang dùng; chi tiết kỹ thuật gấp lại.
                     Không đưa tên tham số API, mã HTTP hay tên nhà cung cấp ra bề mặt người dùng. -->
                <div class="mt-3 rounded-xl border border-ink-700 bg-ink-900 p-3">
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-body leading-5 text-cream-100">
                      <template v-if="liveSources">
                        Đang có <b class="text-ok">{{ newsItems.length }} tin thật</b> từ {{ activeSourceCount }} nguồn · cập nhật {{ fetchedAtLabel }}.
                      </template>
                      <template v-else>
                        Chưa tra được tin nào trên web cho lượt này — phần phân tích đang dựa trên dữ liệu của chính bạn.
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
                    <!-- [ĐỔI CHÍNH SÁCH 2026-09-26] Bảng dưới đây là DANH SÁCH CẤU HÌNH (nguồn nào đã khai
                         trong Cài đặt, nguồn nào đang chết), KHÔNG phải nguồn của những con số phía trên:
                         từ nay số liệu của bước này đo trên KẾT QUẢ TÌM KIẾM. Ghi rõ ngay ở nhãn mở/đóng —
                         để trống thì người đọc tự hiểu là "RSS đang nuôi phân tích", đúng thứ vừa bị bỏ. -->
                    <summary class="cursor-pointer text-label text-cream-400">Nguồn đã khai trong Cài đặt &amp; cách hoạt động <span class="text-cream-400">(cấu hình — không phải nguồn của số liệu ở trên)</span></summary>
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
                    <!-- HAI LƯỢT TRA, NÓI RÕ LƯỢT NÀO RA CÁI GÌ: lượt tra CHUNG của máy chủ (nuôi khối dữ
                         liệu và cả phần đo tín hiệu thị trường), và lượt AI tự tìm trong lượt này (ảnh
                         hưởng phần chữ AI viết). [ĐỔI CHÍNH SÁCH 2026-09-26]: bỏ chữ "nguồn cấu hình" —
                         nguồn kind=rss/page không còn nuôi bước này. -->
                    <p v-if="aiSearchOn" class="mt-2 rounded-lg border border-ink-700 bg-ink-900 p-2.5 text-label leading-5 text-cream-300">
                      <b class="text-cream-100">Hai lượt tra khác nhau:</b>
                      danh sách trên là kết quả <b class="text-cream-100">máy chủ tự tra trên web</b> bằng các câu hỏi chung về ngành (cũng chính là số liệu của khối tín hiệu thị trường);
                      <template v-if="aiSearchQueries.length">
                        còn AI <b class="text-cream-100">tự tìm trên internet {{ aiSearchQueries.length }} truy vấn</b> trong lượt này:
                        <span v-for="(q, i) in aiSearchQueries" :key="q">{{ q }}<span v-if="i < aiSearchQueries.length - 1"> · </span></span>
                      </template>
                      <template v-else>còn AI <b class="text-cream-100">không tự tìm trên internet</b> trong lượt này.</template>
                    </p>
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
            </template>
          </section>
</template>
