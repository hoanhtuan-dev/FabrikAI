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
 * Logic các bước nằm ở composables/useAgentStudio.js — trang này chỉ lo KHUNG.
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
import ShellChrome from './components/ShellChrome.vue';
import AgentDnaStep from './components/agents/AgentDnaStep.vue';
import AgentRadarStep from './components/agents/AgentRadarStep.vue';
import AgentBriefStep from './components/agents/AgentBriefStep.vue';
import AgentCanvasStep from './components/agents/AgentCanvasStep.vue';
// [2026-09-26] Bước 5 — HỎI ĐÁP. Trang này chỉ cần biết nó TỒN TẠI và nằm CUỐI chuỗi; mọi thứ bên
// trong (khung chat · luồng chữ · trích dẫn) nằm ở component riêng, đúng ranh giới của §21.4.
import AgentChatStep from './components/agents/AgentChatStep.vue';

const agent = useAgentStudio();
// Hợp đồng provide()/inject() với các bước — giữ nguyên như bản modal, xem useAgentStudio.js.
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
  // Cờ này vẫn là "Agent Studio đang mở" (phím tắt 1…N · Ctrl+← → trong composable đọc nó).
  store.designAgentOpen = true;
  // QUYỀN THEO GÓI trước tiên: mở thẳng URL mà không biết mình có module hay không thì các bước sẽ
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
 * các bước chạy rồi mỗi lượt gọi API trả về một dòng lỗi — người dùng sẽ tưởng sản phẩm hỏng.
 */
const planLocked = computed(() => store.moduleLocked('stylist'));

const CONTEXT_LABEL = { done: 'Xong', ready: 'Sẵn sàng', loading: 'Đang đọc…', locked: 'Cần brief' };
function readinessLabel(id) { return CONTEXT_LABEL[readiness.value[id]] || ''; }

/**
 * SỐ BƯỚC ĐÃ XONG — cho dải tiến trình (2026-09-26 · thiết kế lại).
 *
 * Vì sao cần: các bước là một CHUỖI, nhưng giao diện chỉ nói "đang ở bước nào". Người dùng phải tự
 * đếm xem còn mấy bước, và tự nhớ bước nào đã xong. Một dải tiến trình trả lời cả hai câu đó bằng một
 * cái liếc mắt — đúng vai của stepper trong Material.
 */
const doneCount = computed(() => STEPS.filter((s) => readiness.value[s.id] === 'done').length);
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
    <header class="elev-bar relative z-30 flex shrink-0 items-center gap-1.5 bg-ink-900 px-2 py-1.5 sm:gap-2 sm:px-4 sm:py-2">
      <a href="/studio" class="icon-btn motion-ui h-8 w-8 shrink-0" aria-label="Về Studio" title="Về Studio (xưởng thiết kế)">
        <StudioIcon name="arrowLeft" size="h-4 w-4" />
      </a>

      <div class="flex min-w-0 items-center gap-2">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-600/15 text-brand-300">
          <StudioIcon name="sparkles" size="h-4 w-4" />
        </span>
        <div class="min-w-0">
          <h1 class="truncate text-title font-semibold leading-5 text-cream-50">Agent Studio</h1>
          <p class="hidden truncate text-label text-cream-400 xl:block">Từ tín hiệu xu hướng tới ảnh hoàn chỉnh</p>
        </div>
      </div>

      <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-1.5">
        <!-- Badge model: dot-only trên mobile, mở rộng từ md+ -->
        <span
          class="flex items-center gap-1.5 rounded-full py-1 text-label font-semibold sm:px-3"
          :class="modelReady ? 'bg-ok/15 text-ok' : 'bg-warn/15 text-warn'"
          :title="modelTitle"
        >
          <span class="h-1.5 w-1.5 rounded-full" :class="modelReady ? 'bg-ok' : 'bg-warn'"></span>
          <span class="hidden md:inline">{{ modelShort }}</span>
        </span>

        <button
          type="button"
          class="tool-btn state-layer !px-2 !py-1.5"
          :class="{ 'is-active': store.designAgentAi }"
          :aria-pressed="store.designAgentAi"
          :title="aiToggleTitle"
          @click="toggleAi"
        >
          <StudioIcon name="sparkles" size="h-3.5 w-3.5" />
          <span class="hidden lg:inline">Suy luận AI</span>
        </button>

        <button
          type="button"
          class="tool-btn state-layer !px-2 !py-1.5"
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
      <nav class="hidden w-52 shrink-0 flex-col gap-0.5 overflow-y-auto border-r border-ink-700/60 bg-ink-900 px-2 py-3 lg:flex" aria-label="Tiến trình thiết kế">
        <p class="px-2 text-label font-semibold uppercase tracking-[0.14em] text-cream-400">Tiến trình</p>

        <!-- Dải tiến trình: trả lời "còn mấy bước" bằng một cái liếc mắt thay vì để người dùng tự đếm. -->
        <div class="px-2 pb-2 pt-1.5">
          <p class="text-tiny text-cream-400">{{ doneCount }}/{{ STEPS.length }} bước xong</p>
          <progress class="progress progress-primary mt-1 h-1.5 w-full" :value="doneCount" :max="STEPS.length"
                    :aria-label="doneCount + '/' + STEPS.length + ' bước đã xong'"></progress>
        </div>

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

        <!-- Tổng quan nhanh: khu vực · hướng · brief · SKU — gọn trong một khối -->
        <dl class="mt-2 space-y-1.5 rounded-lg bg-ink-800/80 px-2.5 py-2.5 text-label">
          <div class="flex items-center gap-1.5">
            <StudioIcon name="pin" size="h-3 w-3" class="shrink-0 text-brand-300" />
            <dt class="sr-only">Khu vực</dt>
            <dd class="truncate font-semibold text-cream-200">{{ regionName }}</dd>
          </div>
          <div class="flex items-center gap-1.5">
            <StudioIcon name="scan" size="h-3 w-3" class="shrink-0 text-brand-300" />
            <dt class="sr-only">Hướng đã chọn</dt>
            <dd class="truncate" :class="selectedTrendCount ? 'font-semibold text-cream-200' : 'text-cream-400'">
              {{ selectedTrendCount ? selectedTrendCount + ' hướng' : 'Chưa chọn hướng' }}
            </dd>
          </div>
          <div class="flex items-center gap-1.5">
            <StudioIcon name="briefcase" size="h-3 w-3" class="shrink-0 text-brand-300" />
            <dt class="sr-only">Brief</dt>
            <dd class="truncate" :class="collection && !briefStale ? 'font-semibold text-ok' : (collection ? 'font-semibold text-warn' : 'text-cream-400')">
              {{ collection && !briefStale ? 'Brief sẵn sàng' : (collection ? 'Brief cũ' : 'Chưa có brief') }}
            </dd>
          </div>
          <div v-if="collection" class="flex items-center gap-1.5">
            <StudioIcon name="package" size="h-3 w-3" class="shrink-0 text-brand-300" />
            <dt class="sr-only">Số mã hàng</dt>
            <dd class="truncate font-semibold text-cream-200">{{ collection.structure?.total_skus || 0 }} mã</dd>
          </div>
        </dl>

        <!-- Phím tắt: gấp lại (thứ ít dùng — §4 luật 5) nhưng VẪN tìm thấy, thay vì nằm trong tài liệu. -->
        <details class="mt-auto rounded-lg bg-ink-800/80 px-2.5 py-2 text-tiny text-cream-300">
          <summary class="cursor-pointer font-semibold text-cream-200">Phím tắt</summary>
          <ul class="mt-1.5 space-y-1 leading-4">
            <li><b class="text-cream-100">Ctrl + →</b> bước kế</li>
            <li><b class="text-cream-100">Ctrl + ←</b> quay lại</li>
            <li><b class="text-cream-100">1…{{ STEPS.length }}</b> nhảy tới bước</li>
            <li><b class="text-cream-100">Ctrl+Enter</b> chốt bước</li>
          </ul>
        </details>
      </nav>

      <div class="flex min-w-0 flex-1 flex-col">
        <!-- ══ DẢI BƯỚC cho màn hẹp — pill tiến trình, không phải nút hành động ══ -->
        <div class="shrink-0 border-b border-ink-700/60 bg-ink-900 px-2 pb-1.5 pt-2 lg:hidden">
          <!-- Bước đang làm in ra bằng CHỮ trước, rồi mới tới dãy pill: trên điện thoại dãy pill phải cuộn
               ngang nên pill đang chọn có thể nằm ngoài tầm nhìn — câu chữ thì luôn thấy. -->
          <div class="flex items-center justify-between gap-2 pb-1.5">
            <p class="min-w-0 truncate text-label font-semibold text-cream-200">
              Bước {{ stepIndex + 1 }}: {{ STEPS[stepIndex].label }}
            </p>
            <p class="shrink-0 text-tiny text-cream-400">{{ doneCount }}/{{ STEPS.length }} xong</p>
          </div>
          <progress class="progress progress-primary h-1 w-full" :value="doneCount" :max="STEPS.length"
                    :aria-label="doneCount + '/' + STEPS.length + ' bước đã xong'"></progress>
        </div>

        <div class="scrollbar-hide shrink-0 overflow-x-auto border-b border-ink-700/60 bg-ink-900 px-2 py-1.5 lg:hidden">
          <div class="flex w-max items-center gap-1">
            <button
              v-for="(item, index) in STEPS"
              :key="item.id"
              type="button"
              class="step-pill state-layer"
              :class="{ 'is-active': step === item.id, 'is-done': readiness[item.id] === 'done' }"
              :aria-current="step === item.id ? 'step' : undefined"
              @click="setStep(item.id)"
            >
              <span class="step-pill__num">
                <StudioIcon v-if="readiness[item.id] === 'done'" name="check" size="h-3 w-3" />
                <span v-else>{{ index + 1 }}</span>
              </span>
              <span class="truncate">{{ item.label }}</span>
            </button>
          </div>
        </div>

        <!-- ══ NỘI DUNG ══ -->
        <!-- Vùng nội dung là "giếng" CHÌM HƠN thanh và rail: ba tầng bề mặt của Material
             (thanh/rail ink-900 -> nội dung ink-950 -> thẻ .card ink-800). Nền nằm ở ĐÂY chứ không
             ở thẻ gốc vì .studio-shell (không thuộc layer nào) đã đặt bg-ink-900 cho thẻ gốc — CSS
             ngoài layer luôn thắng tiện ích trong layer, nên đặt ở gốc là bị ghi đè im lặng. -->
        <main class="min-h-0 flex-1 overflow-y-auto bg-ink-950">
          <div class="mx-auto w-full max-w-6xl px-3 py-4 sm:px-5 sm:py-6">
            <!-- Bảng chỉ đường của bước: LÀM GÌ + ĐẦU RA DÙNG VÀO VIỆC GÌ (§4 luật 1–2). -->
            <header class="mb-4">
              <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <p class="text-label font-semibold uppercase tracking-[0.14em] text-brand-300">
                  Bước {{ stepIndex + 1 }}/{{ STEPS.length }} · {{ STEPS[stepIndex].label }}
                </p>
                <span class="rounded-full px-2 py-0.5 text-tiny normal-case tracking-normal" :class="STEPS[stepIndex].required ? 'bg-brand-600/20 text-brand-200' : 'bg-ink-800 text-cream-300'">
                  {{ STEPS[stepIndex].required ? 'bắt buộc' : 'tùy chọn' }}
                </span>
              </div>
              <h2 class="mt-1 text-balance font-display text-lg font-semibold leading-6 text-cream-100 sm:text-xl sm:leading-7">{{ STEPS[stepIndex].hint }}</h2>
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

            <!-- Gói không có module: KHÔNG dựng các bước để chúng lần lượt báo lỗi. Một màn hình,
                 một lời giải thích, một hành động — và vẫn để rail bên trái cho biết sẽ nhận gì. -->
            <div v-if="planLocked" class="card p-5 text-center sm:p-7">
              <span class="mx-auto grid h-11 w-11 place-items-center rounded-2xl bg-warn/15 text-warn">
                <StudioIcon name="lock" size="h-5 w-5" />
              </span>
              <h3 class="mt-3 font-display text-base font-semibold text-cream-100 sm:text-lg">Agent Studio chưa có trong gói của bạn</h3>
              <p class="mx-auto mt-2 max-w-lg text-body leading-5 text-cream-300 sm:leading-6">
                Luồng 5 bước (DNA shop → Tín hiệu → Định hướng → Thực thi → Hỏi đáp) thuộc gói <b class="text-cream-100">Pro · Studio · Xưởng</b>.
                Xem từng bước ở cột bên trái; mở gói là dùng được ngay.
              </p>
              <!-- Preview các bước để người dùng thấy họ sẽ nhận gì -->
              <div class="mx-auto mt-4 flex max-w-sm flex-wrap items-center justify-center gap-1.5 opacity-60">
                <span v-for="(item, i) in STEPS" :key="item.id" class="flex items-center gap-1 text-tiny text-cream-400">
                  <span class="grid h-5 w-5 place-items-center rounded-full bg-ink-700 text-tiny font-bold">{{ i + 1 }}</span>
                  {{ item.label }}
                  <StudioIcon v-if="i < STEPS.length - 1" name="chevronRight" size="h-3 w-3" class="text-cream-400" />
                </span>
              </div>
              <div class="mt-5 flex flex-wrap items-center justify-center gap-2.5">
                <a href="/bang-gia" class="btn-brand state-layer flex items-center gap-2 !px-5 !py-2.5 text-sm" title="Xem bảng giá và các gói có Agent Studio">
                  <StudioIcon name="coins" size="h-4 w-4" /> Xem gói &amp; nâng cấp
                </a>
                <a href="/studio" class="btn-ghost btn-sm state-layer">Về Studio</a>
              </div>
            </div>

            <template v-else>
              <div :key="step" class="motion-fade-in">
                <AgentDnaStep v-if="step === 'dna'" />
                <AgentRadarStep v-else-if="step === 'radar'" />
                <AgentBriefStep v-else-if="step === 'brief'" />
                <AgentCanvasStep v-else-if="step === 'canvas'" />
                <!-- Bước CUỐI trong chuỗi STEPS (order theo STEPS, không theo thứ tự viết ở đây). -->
                <AgentChatStep v-else />
              </div>
            </template>
          </div>
        </main>

        <!-- ══ THANH HÀNH ĐỘNG (Material bottom app bar) — MỘT hành động chính ══ -->
        <footer class="elev-bar-up relative z-20 flex shrink-0 items-center gap-2 bg-ink-900 px-3 py-2.5 sm:px-5 sm:py-3">
          <button
            v-if="!planLocked && stepIndex > 0"
            type="button"
            class="btn-ghost btn-sm state-layer !px-3 !py-2"
            title="Quay lại bước trước (Ctrl+←)"
            @click="back"
          >
            <StudioIcon name="arrowLeft" size="h-4 w-4" /><span class="hidden sm:inline"> Quay lại</span>
          </button>
          <span v-else-if="!planLocked" class="text-tiny text-cream-400 sm:text-label">Bắt đầu bằng hồ sơ shop — hoặc bỏ qua.</span>
          <span v-else class="text-tiny text-cream-400 sm:text-label">Mở gói là dùng được ngay.</span>

          <span class="ml-auto text-tiny tabular-nums text-cream-400 sm:text-label sm:text-cream-300">{{ stepIndex + 1 }}/{{ STEPS.length }}</span>

          <!-- Gói thiếu module: hành động chính là ĐƯỜNG NÂNG CẤP, không phải nút bị khoá mờ. -->
          <a
            v-if="planLocked"
            v-ripple
            href="/bang-gia"
            class="btn-brand state-layer flex items-center gap-2 !px-4 !py-2 text-sm sm:!px-5"
            title="Xem bảng giá và các gói có Agent Studio"
          >
            <StudioIcon name="coins" size="h-4 w-4" /> <span class="hidden sm:inline">Xem gói &amp; nâng cấp</span><span class="sm:hidden">Nâng cấp</span>
          </a>
          <button
            v-else-if="step !== 'canvas' && step !== 'chat'"
            v-ripple
            type="button"
            class="btn-brand state-layer flex items-center gap-2 !px-4 !py-2 text-sm sm:!px-5"
            :disabled="step === 'brief' && store.collectionBriefLoading"
            title="Tiếp tục (Ctrl+→)"
            @click="advance"
          >
            {{ primaryLabel }}
            <StudioIcon name="arrowRight" size="h-4 w-4" />
          </button>
          <button
            v-else-if="step === 'canvas'"
            v-ripple
            type="button"
            class="btn-brand state-layer flex items-center gap-2 !px-4 !py-2 text-sm sm:!px-5"
            title="Đưa prompt, tỉ lệ và số biến thể sang ô Tạo Ảnh trong Studio"
            @click="applyCanvas"
          >
            <StudioIcon name="zap" size="h-4 w-4" /> {{ primaryLabel }}
          </button>
          <!-- Bước Hỏi đáp là bước CUỐI: hành động chính của nó là nút «Hỏi» NẰM TRONG BƯỚC, ngay cạnh ô
               nhập. Dựng thêm một nút chính ở thanh dưới là có HAI nút chính cùng lúc — đúng thứ §4 luật 3
               cấm. Chỗ này chỉ còn một câu chỉ đường. -->
          <span v-else class="text-tiny text-cream-400 sm:text-label">Gõ câu hỏi ở ô phía trên rồi nhấn Enter.</span>
        </footer>
      </div>
    </div>

    <NotificationCenter />
    <!-- [Phase 4 · shell 2026] Thanh lệnh chung + spacer. -->
    <div class="h-24 shrink-0" aria-hidden="true"></div>
    <ShellChrome space="agent" placeholder="Hỏi Agent hoặc mô tả thiết kế…" />
  </div>
</template>
