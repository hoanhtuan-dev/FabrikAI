<script setup>
/**
 * AGENT STUDIO — TRANG ĐẦY ĐỦ (2026-09-25).
 *
 * Trước đây Agent Studio là một MODAL "gần toàn màn hình" mở từ trong /studio
 * (components/DesignAgents.vue + BaseModal). Hệ quả đo được:
 *   · bốn tầng thanh xếp chồng (đầu modal · tiến trình · bối cảnh · đầu bước) ăn mất ~200px
 *     chiều cao trước khi tới nội dung, trong khi nội dung mới là thứ người dùng cần;
 *   · lớp phủ khoá phần còn lại của Studio nhưng KHÔNG cho thêm chỗ — cửa sổ 1366×768 chỉ còn
 *     ~660px cho một luồng 4 bước;
 *   · không đánh dấu được bước đang làm (không URL) ⇒ gửi link cho đồng nghiệp là mở lại từ đầu;
 *   · F5 là mất bước đang mở (bản nháp cứu được prompt, không cứu được vị trí).
 *
 * Nay: MỘT TRANG riêng tại /agent-studio, cùng một SPA (Pinia store dùng chung).
 *
 * Phong cách — TỐI GIẢN + MATERIAL, và cách hai thứ đó gặp nhau ở đây:
 *   TỐI GIẢN  · hai thanh thay vì bốn (thanh trên + thanh hành động); rail bước giữ tiến trình
 *               nên không cần thêm dải trạng thái nào; nội dung canh giữa, rộng tối đa 5xl;
 *               khoảng cách thay cho đường kẻ ở mọi chỗ không cần phân cách.
 *   MATERIAL  · ba tầng bề mặt rõ ràng (nền trang ink-950 → thanh/rail ink-900 → thẻ .card
 *               ink-800) · tầng nổi bằng BÓNG (.elev-bar · .elev-bar-up) chứ không bằng viền ·
 *               LỚP TRẠNG THÁI (.state-layer) khi trỏ/bấm · GỢN NƯỚC ở nút chính (v-ripple) ·
 *               rail điều hướng đúng kiểu Material (chấm số + nhãn + chỉ báo đang chọn).
 *   KHÔNG thêm màu, không thêm biến thể nút: mọi thứ đọc token đã có nên theme Sáng/Tối vẫn đúng
 *   (docs/DESIGN_SYSTEM.md §1, §2, §5).
 *
 * Logic 4 bước nằm ở composables/useAgentStudio.js — trang này chỉ lo KHUNG.
 */
import { computed, onBeforeUnmount, onMounted, provide, watch } from 'vue';
import { useAgentStudio } from './composables/useAgentStudio.js';
import { useTheme } from './composables/useTheme.js';
import { applyGuiConfig, fetchGuiConfig } from './guiConfig.js';
import { toastClientErrors } from './clientErrors.js';
import StudioIcon from './components/StudioIcon.vue';
import AuthNotice from './components/AuthNotice.vue';
import Notice from './components/Notice.vue';
import NotificationCenter from './components/NotificationCenter.vue';
import AgentDnaStep from './components/agents/AgentDnaStep.vue';
import AgentRadarStep from './components/agents/AgentRadarStep.vue';
import AgentBriefStep from './components/agents/AgentBriefStep.vue';
import AgentCanvasStep from './components/agents/AgentCanvasStep.vue';

const agent = useAgentStudio();
// Hợp đồng provide()/inject() với 4 bước — giữ nguyên như bản modal, xem useAgentStudio.js.
agent.provideAll(provide);

const {
  store, STEPS, step, stepIndex, readiness, regionName, selectedTrendCount,
  collection, briefStale,
  modelReady, modelShort, modelTitle, modelCandidates, modelNeedsAttention, aiToggleTitle, toggleAi,
  activeModel, setStep, advance, back, applyCanvas,
} = agent;

// Lỗi trình duyệt hiện kèm mã tra cứu (clientErrors.js) — mọi trang SPA đều bật bộ này.
toastClientErrors((text) => store.toast(text, 'error'));

const { resolved: themeResolved, setTheme } = useTheme();
function toggleTheme() { setTheme(themeResolved.value === 'light' ? 'dark' : 'light'); }

/**
 * ĐỒNG BỘ BƯỚC LÊN URL (?buoc=…) — nay Agent Studio là trang nên bước đang làm phải ĐÁNH DẤU ĐƯỢC:
 * gửi link cho đồng nghiệp là họ mở đúng bước, và F5 không quay về đầu luồng.
 * URL THẮNG bản nháp trong máy: người mở link phải thấy đúng thứ link trỏ tới.
 */
const STEP_PARAM = 'buoc';
const wanted = new URLSearchParams(window.location.search).get(STEP_PARAM);
if (STEPS.some((s) => s.id === wanted)) {
  store.setDesignAgentStep(wanted);
  // Khoá bước theo URL: phiên làm việc nạp BẤT ĐỒNG BỘ về sau, không được ghi đè bước của link.
  agent.lockStepToUrl();
}
// immediate: URL phải phản ánh bước NGAY từ lần vẽ đầu, không chỉ sau khi người dùng bấm đổi bước —
// nếu không, người mở /agent-studio (không tham số) vẫn thấy thanh địa chỉ trống trong khi màn hình
// đã ở bước 2, và copy link ở thời điểm đó là mất bước.
watch(step, (id) => {
  const url = new URL(window.location.href);
  if (id === STEPS[0].id) url.searchParams.delete(STEP_PARAM);
  else url.searchParams.set(STEP_PARAM, id);
  window.history.replaceState(null, '', url.pathname + url.search);
}, { immediate: true });

onMounted(() => {
  // Cờ này vẫn là "Agent Studio đang mở" (phím tắt 1–4 · Ctrl+← → trong composable đọc nó).
  store.designAgentOpen = true;
  // QUYỀN THEO GÓI trước tiên: mở thẳng URL mà không biết mình có module hay không thì bốn bước sẽ
  // chạy rồi mỗi lượt gọi API trả về một dòng lỗi (xem guiConfig.js).
  fetchGuiConfig().then((cfg) => applyGuiConfig(store, cfg));
  // Dữ liệu của chính luồng này — không chờ /api/latest để màn hình có số ngay.
  agent.bootstrap();
  // Nền: giá trị mặc định + credit + trạng thái đăng nhập.
  store.load();
});
onBeforeUnmount(() => { store.designAgentOpen = false; });

/**
 * GÓI CƯỚC — Agent Studio là module trả tiền (`stylist` trong ModuleRegistry: Pro · Studio · Xưởng
 * theo vụ). Trước đây KHÔNG cần xử lý riêng vì lối vào duy nhất nằm trong /studio, nơi activity bar
 * đã biết khoá mục theo gói; nay đây là một TRANG nên mở thẳng URL vẫn tới được.
 *
 * Cách xử lý: nói thẳng + đề xuất nâng cấp NGAY TRÊN TRANG (§6 luật 1, §12 nguyên tắc 4) thay vì để
 * bốn bước chạy rồi mỗi lượt gọi API trả về một dòng lỗi — người dùng sẽ tưởng sản phẩm hỏng.
 */
const planLocked = computed(() => store.moduleLocked('stylist'));

const CONTEXT_LABEL = { done: 'Xong', ready: 'Sẵn sàng', loading: 'Đang đọc…', locked: 'Cần brief' };
function readinessLabel(id) { return CONTEXT_LABEL[readiness.value[id]] || ''; }
function stepNeeds(id) { return STEPS.find((s) => s.id === id)?.need || ''; }

/** Nhãn nút chính của từng bước — MỘT hành động chính tại một thời điểm (§4 luật 3). */
const primaryLabel = computed(() => {
  if (step.value === 'dna') return 'Đọc tín hiệu thị trường';
  if (step.value === 'radar') return selectedTrendCount.value ? 'Phân tích thành brief' : 'Tiếp tục với mặc định';
  if (step.value === 'brief') return 'Chốt brief & sang Thực thi';
  return 'Áp dụng vào Canvas';
});

// "Áp dụng vào Canvas" nằm ở LÕI (applyCanvas của useAgentStudio) chứ không bọc lại ở đây: bước
// Thực thi cũng có nút cùng tên, và hai đường cùng làm một việc là cách chúng lệch nhau.
</script>

<template>
  <div class="studio-shell flex h-full min-h-0 flex-col text-cream-100">
    <AuthNotice />

    <!-- ══ THANH TRÊN (Material top app bar) — một thanh duy nhất cho nhận diện + điều khiển ══ -->
    <header class="elev-bar relative z-30 flex shrink-0 items-center gap-2 bg-ink-900 px-3 py-2 sm:px-5 sm:py-2.5">
      <a href="/" class="icon-btn motion-ui h-9 w-9 shrink-0" aria-label="Về Studio" title="Về Studio (xưởng thiết kế)">
        <StudioIcon name="arrowLeft" size="h-4 w-4" />
      </a>

      <div class="flex min-w-0 items-center gap-2.5">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-600/15 text-brand-300">
          <StudioIcon name="sparkles" size="h-4 w-4" />
        </span>
        <div class="min-w-0">
          <h1 class="truncate text-title font-semibold leading-5 text-cream-50">Agent Studio</h1>
          <p class="hidden truncate text-label text-cream-400 sm:block">Từ tín hiệu xu hướng tới ảnh hoàn chỉnh</p>
        </div>
      </div>

      <div class="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
        <span
          class="hidden items-center gap-1.5 rounded-full px-3 py-1.5 text-label font-semibold sm:inline-flex"
          :class="modelReady ? 'bg-ok/15 text-ok' : 'bg-warn/15 text-warn'"
          :title="modelTitle"
        >
          <span class="h-1.5 w-1.5 rounded-full" :class="modelReady ? 'bg-ok' : 'bg-warn'"></span>
          {{ modelShort }}
        </span>

        <button
          type="button"
          class="tool-btn state-layer"
          :class="{ 'is-active': store.designAgentAi }"
          :aria-pressed="store.designAgentAi"
          :title="aiToggleTitle"
          @click="toggleAi"
        >
          <StudioIcon name="sparkles" size="h-3.5 w-3.5" />
          <span class="hidden sm:inline">Suy luận AI</span>
        </button>

        <div class="hidden h-5 w-px bg-ink-700 sm:block" aria-hidden="true"></div>

        <button
          type="button"
          class="tool-btn state-layer"
          :title="'Giao diện đang là ' + (themeResolved === 'light' ? 'Sáng' : 'Tối') + ' — bấm để đổi (muốn theo hệ điều hành: Cài đặt của tôi → Giao diện)'"
          :aria-label="'Đổi giao diện Sáng/Tối, đang là ' + (themeResolved === 'light' ? 'Sáng' : 'Tối')"
          @click="toggleTheme"
        >
          <StudioIcon v-if="themeResolved === 'light'" name="sun" size="h-3.5 w-3.5" />
          <StudioIcon v-else name="moon" size="h-3.5 w-3.5" />
        </button>
      </div>
    </header>

    <div class="flex min-h-0 flex-1">
      <!-- ══ RAIL BƯỚC (Material navigation rail) — tiến trình ở lại trên màn hình rộng ══ -->
      <nav class="hidden w-64 shrink-0 flex-col gap-1 overflow-y-auto border-r border-ink-700/60 bg-ink-900 px-3 py-4 lg:flex" aria-label="Tiến trình thiết kế">
        <p class="px-2 pb-2 text-label font-semibold uppercase tracking-[0.14em] text-cream-400">Tiến trình</p>

        <button
          v-for="(item, index) in STEPS"
          :key="item.id"
          type="button"
          class="nav-step"
          :class="{ 'is-active': step === item.id }"
          :aria-current="step === item.id ? 'step' : undefined"
          @click="setStep(item.id)"
        >
          <span class="nav-step__dot" :class="{ 'is-done': readiness[item.id] === 'done' }">
            <StudioIcon v-if="readiness[item.id] === 'done'" name="check" size="h-3.5 w-3.5" />
            <span v-else>{{ index + 1 }}</span>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-body font-semibold leading-4">{{ item.label }}</span>
            <span class="block truncate text-tiny leading-4 opacity-80">{{ readinessLabel(item.id) }}</span>
          </span>
        </button>

        <!-- Phím tắt: gấp lại (thứ ít dùng — §4 luật 5) nhưng VẪN tìm thấy, thay vì nằm trong tài liệu. -->
        <details class="mt-auto rounded-xl bg-ink-800 px-3 py-2.5 text-label text-cream-300">
          <summary class="cursor-pointer font-semibold text-cream-200">Phím tắt</summary>
          <ul class="mt-2 space-y-1.5 leading-5">
            <li><b class="text-cream-100">Ctrl + →</b> bước kế tiếp</li>
            <li><b class="text-cream-100">Ctrl + ←</b> quay lại</li>
            <li><b class="text-cream-100">1…4</b> nhảy tới bước (khi không gõ trong ô nhập)</li>
            <li><b class="text-cream-100">Ctrl + Enter</b> chốt/tiếp tục bước hiện tại</li>
          </ul>
        </details>

        <dl class="mt-3 space-y-2 rounded-xl bg-ink-800 px-3 py-3 text-label">
          <div class="flex items-center gap-2">
            <StudioIcon name="pin" size="h-3 w-3" class="shrink-0 text-brand-300" />
            <dt class="sr-only">Khu vực</dt>
            <dd class="truncate font-semibold text-cream-200">{{ regionName }}</dd>
          </div>
          <div class="flex items-center gap-2">
            <StudioIcon name="scan" size="h-3 w-3" class="shrink-0 text-brand-300" />
            <dt class="sr-only">Hướng đã chọn</dt>
            <dd class="truncate" :class="selectedTrendCount ? 'font-semibold text-cream-200' : 'text-cream-400'">
              {{ selectedTrendCount ? selectedTrendCount + ' hướng đã chọn' : 'Chưa chọn hướng' }}
            </dd>
          </div>
          <div class="flex items-center gap-2">
            <StudioIcon name="briefcase" size="h-3 w-3" class="shrink-0 text-brand-300" />
            <dt class="sr-only">Brief</dt>
            <dd class="truncate" :class="collection && !briefStale ? 'font-semibold text-ok' : (collection ? 'font-semibold text-warn' : 'text-cream-400')">
              {{ collection && !briefStale ? 'Brief sẵn sàng' : (collection ? 'Brief cần cập nhật' : 'Chưa có brief') }}
            </dd>
          </div>
          <div v-if="collection" class="flex items-center gap-2">
            <StudioIcon name="package" size="h-3 w-3" class="shrink-0 text-brand-300" />
            <dt class="sr-only">Số mã hàng</dt>
            <dd class="truncate font-semibold text-cream-200">{{ collection.structure?.total_skus || 0 }} mã hàng</dd>
          </div>
        </dl>
      </nav>

      <div class="flex min-w-0 flex-1 flex-col">
        <!-- ══ DẢI BƯỚC cho màn hẹp — cùng dữ liệu, khác hình dạng (rail không vừa) ══ -->
        <div class="scrollbar-hide shrink-0 overflow-x-auto border-b border-ink-700/60 bg-ink-900 px-3 py-2 lg:hidden">
          <div class="flex w-max items-center gap-1.5">
            <button
              v-for="(item, index) in STEPS"
              :key="item.id"
              type="button"
              class="tool-btn state-layer"
              :class="{ 'is-active': step === item.id }"
              :aria-current="step === item.id ? 'step' : undefined"
              @click="setStep(item.id)"
            >
              <StudioIcon v-if="readiness[item.id] === 'done'" name="check" size="h-3.5 w-3.5" />
              <span v-else class="text-tiny font-bold">{{ index + 1 }}</span>
              {{ item.label }}
            </button>
          </div>
        </div>

        <!-- ══ NỘI DUNG ══ -->
        <!-- Vùng nội dung là "giếng" CHÌM HƠN thanh và rail: ba tầng bề mặt của Material
             (thanh/rail ink-900 -> nội dung ink-950 -> thẻ .card ink-800). Nền nằm ở ĐÂY chứ không
             ở thẻ gốc vì .studio-shell (không thuộc layer nào) đã đặt bg-ink-900 cho thẻ gốc — CSS
             ngoài layer luôn thắng tiện ích trong layer, nên đặt ở gốc là bị ghi đè im lặng. -->
        <main class="min-h-0 flex-1 overflow-y-auto bg-ink-950">
          <div class="mx-auto w-full max-w-6xl px-4 py-5 sm:px-6 sm:py-8">
            <!-- Bảng chỉ đường của bước: LÀM GÌ + ĐẦU RA DÙNG VÀO VIỆC GÌ (§4 luật 1–2). -->
            <header class="mb-5">
              <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-label font-semibold uppercase tracking-[0.14em] text-brand-300">
                <span>Bước {{ stepIndex + 1 }}/{{ STEPS.length }} · {{ STEPS[stepIndex].label }}</span>
                <span class="rounded-full px-2 py-0.5 text-tiny normal-case tracking-normal" :class="STEPS[stepIndex].required ? 'bg-brand-600/20 text-brand-200' : 'bg-ink-800 text-cream-300'">
                  {{ STEPS[stepIndex].required ? 'bắt buộc' : 'tùy chọn' }}
                </span>
              </p>
              <h2 class="mt-1.5 text-balance font-display text-xl font-semibold leading-7 text-cream-100 sm:text-2xl">{{ STEPS[stepIndex].hint }}</h2>
              <p class="mt-1 text-body leading-5 text-cream-400">{{ stepNeeds(STEPS[stepIndex].id) }}</p>
            </header>

            <!-- Vì sao đang chạy bằng bộ quy tắc: nói thẳng lý do + nơi bật (§18.2).
                 Dạng băng CÓ THỂ ĐÓNG: trước đây là một <p> dính mãi, người dùng không gạt đi được. -->
            <Notice v-if="!planLocked && modelNeedsAttention" tone="warn" class="mb-5" :watch-key="(activeModel?.reason || '') + ':' + store.designAgentAi + ':' + modelCandidates.length">
              <p>{{ modelTitle }}</p>
              <p v-if="!store.designAgentAi" class="mt-1 text-cream-300">Bật «Suy luận AI» ở thanh trên để phần phân tích do AI thực hiện.</p>
              <p v-else-if="modelCandidates.length === 0" class="mt-1 text-cream-300">Cấu hình tại Cài đặt → Nhóm công việc → “Suy luận prompt” và thêm khoá trong Quản lý API.</p>
              <p v-else class="mt-1 text-cream-300">Bấm «Tạo lại brief» (việc 7 của bước Định hướng) để dựng lại bằng AI.</p>
            </Notice>

            <!-- Gói không có module: KHÔNG dựng bốn bước để chúng lần lượt báo lỗi. Một màn hình,
                 một lời giải thích, một hành động — và vẫn để rail bên trái cho biết sẽ nhận gì. -->
            <div v-if="planLocked" class="card p-6 text-center sm:p-8">
              <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-warn/15 text-warn">
                <StudioIcon name="lock" size="h-5 w-5" />
              </span>
              <h3 class="mt-4 font-display text-lg font-semibold text-cream-100">Agent Studio chưa có trong gói của bạn</h3>
              <p class="mx-auto mt-2 max-w-xl text-body leading-6 text-cream-300">
                Luồng bốn bước (DNA shop → Tín hiệu → Định hướng → Thực thi) thuộc các gói <b class="text-cream-100">Pro · Studio · Xưởng theo vụ</b>.
                Bạn xem được từng bước ở cột bên trái để biết mình sẽ nhận gì; mở gói là dùng được ngay, không phải nhập lại gì.
              </p>
              <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
                <a href="/bang-gia" class="btn-brand state-layer flex items-center gap-2 !px-5 !py-2.5 text-sm" title="Xem bảng giá và các gói có Agent Studio">
                  <StudioIcon name="coins" size="h-4 w-4" /> Xem gói &amp; nâng cấp
                </a>
                <a href="/" class="btn-ghost btn-sm state-layer">Về Studio</a>
              </div>
            </div>

            <template v-else>
              <AgentDnaStep v-if="step === 'dna'" />
              <AgentRadarStep v-else-if="step === 'radar'" />
              <AgentBriefStep v-else-if="step === 'brief'" />
              <AgentCanvasStep v-else />
            </template>
          </div>
        </main>

        <!-- ══ THANH HÀNH ĐỘNG (Material bottom app bar) — MỘT hành động chính ══ -->
        <footer class="elev-bar-up relative z-20 flex shrink-0 items-center gap-3 bg-ink-900 px-3 py-3 sm:px-6">
          <button
            v-if="!planLocked && stepIndex > 0"
            type="button"
            class="btn-ghost btn-sm state-layer"
            title="Quay lại bước trước (Ctrl+←)"
            @click="back"
          >
            <StudioIcon name="arrowLeft" size="h-4 w-4" /><span class="hidden sm:inline"> Quay lại</span>
          </button>
          <span v-else-if="!planLocked" class="text-label text-cream-400">Bắt đầu bằng hồ sơ shop — hoặc bỏ qua để đọc tín hiệu ngay.</span>
          <span v-else class="text-label text-cream-400">Xem từng bước ở cột bên trái — mở gói là dùng được ngay.</span>

          <span class="ml-auto hidden text-label text-cream-400 sm:inline">Bước {{ stepIndex + 1 }}/{{ STEPS.length }}</span>

          <!-- Gói thiếu module: hành động chính là ĐƯỜNG NÂNG CẤP, không phải nút bị khoá mờ. -->
          <a
            v-if="planLocked"
            v-ripple
            href="/bang-gia"
            class="btn-brand state-layer flex items-center gap-2 !px-5 !py-2.5 text-sm"
            title="Xem bảng giá và các gói có Agent Studio"
          >
            <StudioIcon name="coins" size="h-4 w-4" /> Xem gói &amp; nâng cấp
          </a>
          <button
            v-else-if="step !== 'canvas'"
            v-ripple
            type="button"
            class="btn-brand state-layer flex items-center gap-2 !px-5 !py-2.5 text-sm"
            :disabled="step === 'brief' && store.collectionBriefLoading"
            title="Tiếp tục (Ctrl+→)"
            @click="advance"
          >
            {{ primaryLabel }}
            <StudioIcon name="arrowRight" size="h-4 w-4" />
          </button>
          <button
            v-else
            v-ripple
            type="button"
            class="btn-brand state-layer flex items-center gap-2 !px-5 !py-2.5 text-sm"
            title="Đưa prompt, tỉ lệ và số biến thể sang ô Tạo Ảnh trong Studio"
            @click="applyCanvas"
          >
            <StudioIcon name="zap" size="h-4 w-4" /> {{ primaryLabel }}
          </button>
        </footer>
      </div>
    </div>

    <NotificationCenter />
  </div>
</template>
