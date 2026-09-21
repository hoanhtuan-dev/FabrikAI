<script setup>
/**
 * BƯỚC 4 — THỰC THI: danh sách mẫu · sinh prompt TỪNG MẪU · áp dụng & lưu (2026-09-25).
 *
 * Vì sao đổi hẳn cách làm: trước đây cả bộ sưu tập dùng CHUNG một prompt, nên 12 mã ra 12 tấm ảnh giống
 * nhau, và người dùng không có chỗ nào để biết "mình đã làm xong mẫu nào". Nay mỗi mã hàng là một MẪU
 * có prompt riêng, sinh lần lượt, và người dùng tự chốt từng mẫu.
 *
 * Ba việc con đúng theo nhịp làm thật: dựng danh sách → làm từng mẫu → đưa sang Canvas.
 */
import { computed, inject } from 'vue';
import { useStudioStore } from '../../store.js';
import StudioIcon from '../StudioIcon.vue';

const collection = inject('collection');
const sub = inject('sub');
const setSub = inject('setSub');
const buildSamples = inject('buildSamples');
const samples = inject('samples');
const sampleProgress = inject('sampleProgress');
const nextSample = inject('nextSample');
const sampleBusyId = inject('sampleBusyId');
const sampleError = inject('sampleError');
const generateSamplePrompt = inject('generateSamplePrompt');
const setSampleStatus = inject('setSampleStatus');
const editSamplePrompt = inject('editSamplePrompt');
const applySampleToCanvas = inject('applySampleToCanvas');
const canvas = inject('canvas');
const RATIO_OPTIONS = inject('RATIO_OPTIONS');
const estimatedCredits = inject('estimatedCredits');
const collectionError = inject('collectionError');
const creatingCollection = inject('creatingCollection');
const createCollection = inject('createCollection');
const sessionSaving = inject('sessionSaving');
const sessionSavedAt = inject('sessionSavedAt');
const saveSession = inject('saveSession');
const closeSession = inject('closeSession');
const store = useStudioStore();
const formatNumber = inject('formatNumber');
const copyText = inject('copyText');
const setStep = inject('setStep');

const STATUS = {
  todo: { label: 'Chưa làm', cls: 'bg-ink-800 text-cream-300' },
  generating: { label: 'Đang sinh…', cls: 'bg-brand-600/20 text-brand-200' },
  done: { label: 'Đã xong', cls: 'bg-ok/15 text-ok' },
  skipped: { label: 'Bỏ qua', cls: 'bg-warn/15 text-warn' },
};
const statusOf = (row) => STATUS[row.status] || STATUS.todo;

const current = computed(() => nextSample.value);
const doneRows = computed(() => samples.value.filter((row) => row.status === 'done'));
const pct = computed(() => (sampleProgress.value.total ? Math.round((sampleProgress.value.done / sampleProgress.value.total) * 100) : 0));

function addSample() {
  const n = samples.value.length + 1;
  samples.value = [...samples.value, {
    id: 'sku-' + n + '-' + Date.now().toString(36),
    name: 'Mẫu thêm #' + n,
    category: 'Mẫu thêm',
    size: samples.value[samples.value.length - 1]?.size || 'M',
    status: 'todo', prompt_vi: '', prompt_en: '', negative_prompt: '', note: '', context: '',
  }];
}
function removeSample(id) { samples.value = samples.value.filter((row) => row.id !== id); }
function renameSample(id, patch) {
  samples.value = samples.value.map((row) => (row.id === id ? { ...row, ...patch } : row));
}
async function runCurrent() {
  if (!current.value) return;
  await generateSamplePrompt(current.value.id);
}
async function finishAndNext(id) {
  setSampleStatus(id, 'done');
}
</script>

<template>
  <div>
    <div v-if="!collection" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-10 text-center">
      <StudioIcon name="wand" size="h-8 w-8" class="mx-auto text-brand-300" />
      <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief để chuyển sang Canvas</p>
      <p class="mt-1 text-xs text-cream-400">Quay lại bước Định hướng và chốt brief trước.</p>
      <button type="button" class="btn-brand btn-sm mt-4" @click="setStep('brief')">Quay lại Định hướng</button>
    </div>

    <template v-else>
      <!-- ── 4.1 DANH SÁCH MẪU ─────────────────────────────────────────────── -->
      <template v-if="sub === 'list'">
        <div class="card p-4 sm:p-5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <h2 class="font-display text-base font-semibold text-brand-300">Danh sách mẫu cần làm</h2>
              <p class="mt-0.5 text-body leading-5 text-cream-400">
                Mỗi mã hàng trong cơ cấu là một MẪU. Danh sách này dựng tự động từ cơ cấu SKU và bảng size bạn đã chốt — sửa lại tên hoặc size ở đây nếu xưởng gọi khác.
              </p>
            </div>
            <span class="rounded-full bg-ink-800 px-2.5 py-1 text-label text-cream-300">{{ samples.length }} mẫu</span>
          </div>

          <div class="mt-4 flex flex-wrap items-center gap-2">
            <button type="button" class="btn-brand btn-sm flex items-center gap-2" @click="buildSamples">
              <StudioIcon name="list" size="h-3.5 w-3.5" /> {{ samples.length ? 'Dựng lại từ cơ cấu' : 'Dựng danh sách mẫu' }}
            </button>
            <button type="button" class="tool-btn state-layer" @click="addSample">
              <StudioIcon name="plus" size="h-3 w-3" /> Thêm mẫu
            </button>
          </div>
          <p class="mt-1.5 text-label leading-4 text-cream-400">↳ Dựng lại KHÔNG xoá prompt đã sinh: mẫu trùng mã được giữ nguyên, chỉ thêm/bớt theo cơ cấu mới.</p>

          <p v-if="sampleError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-danger/40 bg-danger/10 p-3 text-body leading-5 text-danger">
            <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ sampleError }}</span>
          </p>

          <ul v-if="samples.length" class="mt-4 space-y-2">
            <li v-for="(row, index) in samples" :key="row.id" class="flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-800 p-2.5">
              <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ink-900 text-tiny font-bold text-cream-300 tabular-nums">{{ index + 1 }}</span>
              <input
                class="input min-w-0 flex-1 !py-1.5 !text-body"
                maxlength="120"
                :value="row.name"
                :aria-label="'Tên mẫu ' + (index + 1)"
                @input="renameSample(row.id, { name: $event.target.value })"
              >
              <input
                class="input !w-16 !py-1.5 !text-body font-semibold uppercase"
                maxlength="8"
                :value="row.size"
                :aria-label="'Size của mẫu ' + (index + 1)"
                @input="renameSample(row.id, { size: $event.target.value.toUpperCase() })"
              >
              <span class="rounded-full px-2 py-0.5 text-tiny font-semibold" :class="statusOf(row).cls">{{ statusOf(row).label }}</span>
              <button type="button" class="tool-btn !px-2 !py-1.5" :aria-label="'Xoá mẫu ' + (index + 1)" title="Xoá mẫu này" @click="removeSample(row.id)">
                <StudioIcon name="trash" size="h-3.5 w-3.5" />
              </button>
            </li>
          </ul>
          <div v-else class="mt-4 rounded-lg border border-dashed border-ink-700 bg-ink-900/60 p-6 text-center">
            <p class="text-body text-cream-300">Chưa có mẫu nào. Bấm «Dựng danh sách mẫu» để lấy từ cơ cấu SKU đã chốt.</p>
          </div>
        </div>
      </template>

      <!-- ── 4.2 SINH PROMPT TỪNG MẪU ──────────────────────────────────────── -->
      <template v-else-if="sub === 'prompts'">
        <div class="card p-4 sm:p-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
              <h2 class="font-display text-base font-semibold text-brand-300">Làm từng mẫu một</h2>
              <p class="mt-0.5 text-body leading-5 text-cream-400">Sinh prompt cho mẫu đang mở, đọc lại, sửa nếu cần, rồi chốt «Đã xong». Mẫu chưa chốt vẫn giữ nguyên prompt nếu bạn đóng máy.</p>
            </div>
            <span class="rounded-full bg-ink-800 px-2.5 py-1 text-label tabular-nums text-cream-300">{{ sampleProgress.done }}/{{ sampleProgress.total }} xong</span>
          </div>

          <div class="mt-3 h-1.5 rounded bg-ink-700" role="img" :aria-label="'Đã xong ' + sampleProgress.done + ' trên ' + sampleProgress.total + ' mẫu'">
            <span class="block h-full rounded bg-ok motion-ui--size" :style="{ width: pct + '%' }"></span>
          </div>

          <p v-if="!samples.length" class="mt-4 rounded-lg border border-dashed border-ink-700 bg-ink-900/60 p-6 text-center text-body text-cream-300">
            Chưa có mẫu nào — quay lại việc 1 để dựng danh sách.
          </p>

          <template v-else-if="current">
            <div class="mt-4 rounded-lg border border-brand-500/40 bg-brand-600/10 p-3 sm:p-4">
              <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-brand-600 px-2 py-0.5 text-tiny font-bold text-primary-content">ĐANG LÀM</span>
                <p class="min-w-0 flex-1 truncate text-body font-semibold text-cream-100">{{ current.name }} · size {{ current.size }}</p>
                <span class="text-label text-cream-300">mẫu {{ samples.indexOf(current) + 1 }}/{{ samples.length }}</span>
              </div>

              <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  class="btn-brand btn-sm flex items-center gap-2"
                  :disabled="sampleBusyId === current.id"
                  @click="runCurrent"
                >
                  <StudioIcon name="wand" size="h-3.5 w-3.5" :class="sampleBusyId === current.id ? 'animate-spin' : ''" />
                  {{ sampleBusyId === current.id ? 'Đang viết prompt…' : (current.prompt_vi ? 'Sinh lại prompt mẫu này' : 'Sinh prompt cho mẫu này') }}
                </button>
                <button v-if="current.prompt_vi" type="button" class="tool-btn state-layer" @click="finishAndNext(current.id)">
                  <StudioIcon name="check" size="h-3 w-3" /> Đã xong, sang mẫu kế
                </button>
                <button v-if="current.prompt_vi" type="button" class="tool-btn state-layer" @click="setSampleStatus(current.id, 'skipped')">
                  <StudioIcon name="ban" size="h-3 w-3" /> Bỏ qua mẫu này
                </button>
              </div>
            </div>

            <div v-if="current.prompt_vi" class="mt-4 grid gap-4 lg:grid-cols-2">
              <div class="rounded-lg border border-ink-700 bg-ink-900 p-3">
                <div class="flex items-center justify-between gap-2">
                  <h3 class="text-body font-semibold text-cream-100">Prompt tiếng Việt</h3>
                  <button type="button" class="tool-btn !px-2 !py-1" :aria-label="'Sao chép prompt tiếng Việt của ' + current.name" title="Sao chép" @click="copyText(current.prompt_vi, 'prompt tiếng Việt')">
                    <StudioIcon name="copy" size="h-3 w-3" />
                  </button>
                </div>
                <textarea
                  class="input mt-2 w-full resize-none !py-2 !text-body leading-5"
                  rows="6"
                  maxlength="1600"
                  :value="current.prompt_vi"
                  :aria-label="'Prompt tiếng Việt của ' + current.name"
                  @input="editSamplePrompt(current.id, 'prompt_vi', $event.target.value)"
                ></textarea>
              </div>
              <div class="rounded-lg border border-ink-700 bg-ink-900 p-3">
                <div class="flex items-center justify-between gap-2">
                  <h3 class="text-body font-semibold text-cream-100">Prompt tiếng Anh (dùng để tạo ảnh)</h3>
                  <button type="button" class="tool-btn !px-2 !py-1" :aria-label="'Sao chép prompt tiếng Anh của ' + current.name" title="Sao chép" @click="copyText(current.prompt_en, 'prompt tiếng Anh')">
                    <StudioIcon name="copy" size="h-3 w-3" />
                  </button>
                </div>
                <textarea
                  class="input mt-2 w-full resize-none !py-2 !text-body leading-5"
                  rows="6"
                  maxlength="1600"
                  :value="current.prompt_en"
                  :aria-label="'Prompt tiếng Anh của ' + current.name"
                  @input="editSamplePrompt(current.id, 'prompt_en', $event.target.value)"
                ></textarea>
              </div>
            </div>

            <div v-if="current.prompt_vi" class="mt-3 space-y-2">
              <p v-if="current.context" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">Bối cảnh chụp: {{ current.context }}</p>
              <p v-if="current.note" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">{{ current.note }}</p>
              <label class="block">
                <span class="text-label font-semibold uppercase tracking-wide text-cream-400">Negative prompt của mẫu</span>
                <input
                  class="input mt-1 w-full !py-2 !text-body"
                  maxlength="600"
                  :value="current.negative_prompt"
                  :aria-label="'Negative prompt của ' + current.name"
                  @input="editSamplePrompt(current.id, 'negative_prompt', $event.target.value)"
                >
              </label>
              <p v-if="current.model_mode === 'rule'" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-400">
                Prompt này do hệ thống dựng từ dữ liệu bạn đã chốt (không tốn lượt gọi AI). Bật «Suy luận AI» ở thanh trên nếu muốn AI viết lại cho mượt.
              </p>
            </div>
          </template>

          <div v-else class="mt-4 rounded-lg border border-ok/40 bg-ok/10 p-4 text-center">
            <StudioIcon name="check" size="h-6 w-6" class="mx-auto text-ok" />
            <p class="mt-2 text-sm font-semibold text-ok">Đã xử lý hết {{ samples.length }} mẫu</p>
            <p class="mt-1 text-body text-cream-300">Sang việc 3 để đưa prompt sang Canvas và lưu phiên.</p>
            <button type="button" class="btn-brand btn-sm mt-3" @click="setSub('apply')">Sang việc 3: Áp dụng &amp; lưu</button>
          </div>

          <p v-if="sampleError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-danger/40 bg-danger/10 p-3 text-body leading-5 text-danger">
            <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ sampleError }}</span>
          </p>
        </div>

        <!-- Bảng trạng thái tất cả mẫu: biết ngay mẫu nào còn, mẫu nào xong -->
        <div v-if="samples.length" class="card mt-4 p-4 sm:p-5">
          <h3 class="font-display text-base font-semibold text-brand-300">Tất cả mẫu</h3>
          <ul class="mt-3 space-y-1.5">
            <li v-for="(row, index) in samples" :key="row.id" class="flex flex-wrap items-center gap-2 rounded-lg bg-ink-800 px-3 py-2">
              <span class="w-6 shrink-0 text-tiny tabular-nums text-cream-400">{{ index + 1 }}</span>
              <span class="min-w-0 flex-1 truncate text-body text-cream-100">{{ row.name }}</span>
              <span class="text-label text-cream-400">{{ row.size }}</span>
              <span class="rounded-full px-2 py-0.5 text-tiny font-semibold" :class="statusOf(row).cls">{{ statusOf(row).label }}</span>
              <button v-if="row.prompt_vi" type="button" class="tool-btn !px-2 !py-1" :aria-label="'Đưa prompt của ' + row.name + ' sang Canvas'" title="Đưa mẫu này sang Canvas" @click="applySampleToCanvas(row)">
                <StudioIcon name="zap" size="h-3 w-3" />
              </button>
              <button v-if="row.status === 'done' || row.status === 'skipped'" type="button" class="tool-btn !px-2 !py-1" :aria-label="'Mở lại mẫu ' + row.name" title="Mở lại mẫu này" @click="setSampleStatus(row.id, 'todo')">
                <StudioIcon name="refresh" size="h-3 w-3" />
              </button>
            </li>
          </ul>
        </div>
      </template>

      <!-- ── 4.3 ÁP DỤNG & LƯU ─────────────────────────────────────────────── -->
      <template v-else>
        <div class="card p-4 sm:p-5">
          <h2 class="font-display text-base font-semibold text-brand-300">Đưa sang Canvas &amp; lưu lại</h2>
          <p class="mt-0.5 text-body leading-5 text-cream-400">
            Prompt đã sinh cho mẫu nào thì đưa thẳng mẫu đó sang ô Tạo Ảnh của Studio. Phần còn lại của bộ sưu tập được lưu theo tài khoản.
          </p>

          <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="rounded-lg border border-ink-700 bg-ink-900 p-3">
              <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Đã xong {{ doneRows.length }}/{{ samples.length }} mẫu</p>
              <ul v-if="doneRows.length" class="mt-2 space-y-1.5">
                <li v-for="row in doneRows" :key="row.id" class="flex items-center gap-2">
                  <span class="min-w-0 flex-1 truncate text-body text-cream-100">{{ row.name }}</span>
                  <button type="button" class="tool-btn !px-2 !py-1" :aria-label="'Đưa prompt của ' + row.name + ' sang Canvas'" title="Đưa sang Canvas" @click="applySampleToCanvas(row)">
                    <StudioIcon name="zap" size="h-3 w-3" />
                  </button>
                </li>
              </ul>
              <p v-else class="mt-2 text-body leading-5 text-cream-400">Chưa mẫu nào được chốt. Sang việc 2 để làm từng mẫu.</p>
              <button v-if="!doneRows.length" type="button" class="tool-btn state-layer mt-3" @click="setSub('prompts')">
                <StudioIcon name="arrowLeft" size="h-3 w-3" /> Sang việc 2: Sinh prompt từng mẫu
              </button>
            </div>

            <div class="space-y-3">
              <div class="rounded-lg border border-ink-700 bg-ink-900 p-3">
                <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Cấu hình tạo ảnh dùng chung</p>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                  <div>
                    <p class="text-label text-cream-400">Tỉ lệ khung</p>
                    <div class="seg mt-1.5" role="group" aria-label="Tỉ lệ khung ảnh">
                      <button v-for="ratio in RATIO_OPTIONS" :key="ratio" type="button" class="seg-btn" :class="{ 'is-active': canvas.ratio === ratio }" :aria-pressed="canvas.ratio === ratio" @click="canvas.ratio = ratio">{{ ratio }}</button>
                    </div>
                  </div>
                  <div>
                    <p class="text-label text-cream-400">Số biến thể</p>
                    <div class="seg mt-1.5" role="group" aria-label="Số biến thể ảnh">
                      <button v-for="n in [1, 2, 3, 4]" :key="n" type="button" class="seg-btn" :class="{ 'is-active': Number(canvas.variantCount) === n }" :aria-pressed="Number(canvas.variantCount) === n" @click="canvas.variantCount = n">{{ n }}</button>
                    </div>
                  </div>
                </div>
                <div class="mt-3 flex items-center justify-between text-body">
                  <span class="text-cream-400">Chi phí ước tính mỗi lần tạo</span>
                  <span class="font-semibold text-cream-100">~{{ estimatedCredits }} credit</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-body">
                  <span class="text-cream-400">Credit còn lại</span>
                  <span class="text-cream-200">{{ formatNumber(store.creditsLeft) }}</span>
                </div>
              </div>

              <div class="rounded-lg border border-ink-700 bg-ink-900 p-3">
                <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Bộ sưu tập</p>
                <p class="mt-1.5 text-body text-cream-200">{{ collection.project_payload?.name }}</p>
                <p class="mt-1 text-label leading-4 text-cream-400">Tạo bộ sưu tập chỉ tạo vỏ dự án; ảnh vẫn do bạn chủ động tạo trong Canvas.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <button type="button" class="tool-btn state-layer" :disabled="creatingCollection" @click="createCollection">
                    <StudioIcon name="briefcase" size="h-3 w-3" /> {{ creatingCollection ? 'Đang tạo…' : 'Tạo bộ sưu tập từ brief' }}
                  </button>
                  <button type="button" class="tool-btn state-layer" :disabled="sessionSaving" @click="saveSession()">
                    <StudioIcon name="save" size="h-3 w-3" /> {{ sessionSaving ? 'Đang lưu…' : 'Lưu phiên' }}
                  </button>
                  <button type="button" class="tool-btn state-layer" :disabled="sessionSaving" @click="closeSession()">
                    <StudioIcon name="archive" size="h-3 w-3" /> Chốt phiên &amp; lưu trữ
                  </button>
                </div>
                <p v-if="sessionSavedAt" class="mt-2 text-label text-cream-400">Lần lưu gần nhất: {{ new Date(sessionSavedAt).toLocaleString('vi-VN') }}</p>
              </div>
            </div>
          </div>

          <div v-if="collectionError || store.collectionBriefError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-danger/40 bg-danger/10 p-3 text-body leading-5 text-danger">
            <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ collectionError || store.collectionBriefError }}</span>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
