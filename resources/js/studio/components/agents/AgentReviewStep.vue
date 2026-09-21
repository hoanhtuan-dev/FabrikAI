<script setup>
/**
 * BƯỚC CON 3.7 — XEM LẠI & CHỐT (2026-09-25).
 *
 * Một màn để trả lời "tôi vừa chốt những gì" trước khi biến nó thành ảnh: mô tả, cơ cấu SKU, bảng size,
 * bảng mood, số mẫu sẽ làm. Thiếu màn này thì người dùng sang bước Thực thi mà không biết mình đã đặt gì.
 *
 * Đồng thời là chỗ LƯU PHIÊN: nói rõ phiên đang được lưu ở đâu, lưu lúc nào, và chốt phiên.
 */
import { computed, inject, ref } from 'vue';
import StudioIcon from '../StudioIcon.vue';

const collection = inject('collection');
const briefStale = inject('briefStale');
const createBrief = inject('createBrief');
const prompt = inject('prompt');
const categoryRows = inject('categoryRows');
const palette = inject('palette');
const moodboardItems = inject('moodboardItems');
const sizeRowsInput = inject('sizeRowsInput');
const setSub = inject('setSub');
const advance = inject('advance');
const formatNumber = inject('formatNumber');
const formatVnd = inject('formatVnd');
const sessionProjectId = inject('sessionProjectId');
const sessionSavedAt = inject('sessionSavedAt');
const sessionSaving = inject('sessionSaving');
const sessionError = inject('sessionError');
const saveSession = inject('saveSession');
const closeSession = inject('closeSession');
const buildSamples = inject('buildSamples');
const samples = inject('samples');

const busy = ref(false);
async function regenerate(ai) {
  busy.value = true;
  try { await createBrief({ force: true, ai }); } finally { busy.value = false; }
}
async function finish() {
  // Sang bước Thực thi: dựng danh sách mẫu từ cơ cấu VỪA CHỐT rồi mới đi.
  buildSamples();
  await advance();
}

const savedLabel = computed(() => {
  if (sessionSaving.value) return 'Đang lưu…';
  if (!sessionSavedAt.value) return sessionProjectId.value ? 'Đã lưu' : 'Chưa lưu lần nào';
  try {
    return 'Đã lưu lúc ' + new Date(sessionSavedAt.value).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
  } catch (e) { return 'Đã lưu'; }
});
const moodPhrase = computed(() => moodboardItems.value
  .map((row) => String(row.label || '').trim())
  .filter(Boolean)
  .slice(0, 6)
  .join(' · '));
</script>

<template>
  <div>
    <div v-if="!collection" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-8 text-center">
      <StudioIcon name="briefcase" size="h-8 w-8" class="mx-auto text-brand-300" />
      <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có brief để chốt</p>
      <p class="mt-1 text-xs leading-5 text-cream-400">Nhập mô tả ở việc 1 rồi bấm «Tạo brief bộ sưu tập».</p>
      <button type="button" class="tool-btn state-layer mt-3" @click="setSub('prompt')">
        <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" /> Về việc 1
      </button>
    </div>

    <template v-else>
      <div class="card p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="font-display text-base font-semibold text-brand-300">Bạn vừa chốt những gì</h2>
            <p class="mt-0.5 text-body leading-5 text-cream-400">Kiểm tra nhanh trước khi biến nó thành {{ samples.length || collection.structure?.total_skus || 0 }} mẫu ảnh.</p>
          </div>
          <span class="rounded-full px-2.5 py-1 text-label font-semibold" :class="briefStale ? 'bg-warn/15 text-warn' : 'bg-ok/15 text-ok'">
            {{ briefStale ? 'Có thay đổi chưa vào brief' : 'Brief khớp mọi lựa chọn' }}
          </span>
        </div>

        <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
            <dt class="text-label text-cream-400">Mã hàng</dt>
            <dd class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ formatNumber(collection.structure?.total_skus || 0) }}</dd>
            <p class="text-label text-cream-400">{{ categoryRows.length }} nhóm · {{ collection.structure?.total_skus_source === 'owner' ? 'do bạn chọn' : 'hệ thống đề xuất' }}</p>
          </div>
          <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
            <dt class="text-label text-cream-400">Bảng size</dt>
            <dd class="mt-1 text-body font-semibold text-cream-100">{{ sizeRowsInput.map((r) => r.size).join(' · ') }}</dd>
            <p class="text-label text-cream-400">Tổng {{ sizeRowsInput.reduce((s, r) => s + (Number(r.pct) || 0), 0) }}%</p>
          </div>
          <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
            <dt class="text-label text-cream-400">Dải giá</dt>
            <dd class="mt-1 text-body font-semibold text-cream-100">{{ collection.price_bands?.recommended_label || '—' }}</dd>
            <p class="text-label text-cream-400">{{ formatVnd(collection.price_bands?.min_vnd) }} — {{ formatVnd(collection.price_bands?.max_vnd) }}</p>
          </div>
          <div class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2.5">
            <dt class="text-label text-cream-400">Bảng mood</dt>
            <dd class="mt-1 text-lg font-semibold tabular-nums text-cream-100">{{ moodboardItems.length }} ô</dd>
            <p class="truncate text-label text-cream-400" :title="moodPhrase">{{ moodPhrase || 'chưa có nhãn' }}</p>
          </div>
        </dl>

        <div class="mt-4 flex flex-wrap gap-1.5">
          <span v-for="color in palette.slice(0, 10)" :key="color.hex" class="h-7 w-7 rounded border border-ink-700" :style="{ backgroundColor: color.hex }" :title="color.name + ' ' + color.hex"></span>
        </div>

        <p class="mt-4 text-body leading-6 text-cream-200">{{ collection.brief }}</p>

        <div v-if="collection.next_steps?.length" class="mt-4">
          <p class="text-label font-semibold uppercase tracking-wide text-cream-400">Việc nên làm tiếp</p>
          <ul class="mt-1.5 space-y-1 text-body leading-5 text-cream-300">
            <li v-for="(step, index) in collection.next_steps" :key="index">• {{ step }}</li>
          </ul>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-2">
          <button type="button" class="btn-brand btn-sm flex items-center gap-2" :disabled="busy || briefStale" @click="finish">
            <StudioIcon name="arrowRight" size="h-3.5 w-3.5" /> Chốt &amp; sang Thực thi
          </button>
          <p v-if="briefStale" class="text-label leading-4 text-warn">↳ Còn thay đổi chưa vào brief — bấm «Áp dụng» bên dưới trước.</p>
          <button type="button" class="tool-btn state-layer" :disabled="busy" @click="regenerate(false)">
            <StudioIcon name="refresh" size="h-3 w-3" /> {{ busy ? 'Đang cập nhật…' : 'Cập nhật số liệu (không gọi AI)' }}
          </button>
          <button type="button" class="tool-btn state-layer" :disabled="busy" title="Nhờ AI viết lại phần chữ — tốn một lượt gọi, khoảng 30 giây">
            <StudioIcon name="sparkles" size="h-3 w-3" /> Tạo lại bằng AI
          </button>
        </div>
      </div>

      <!-- PHIÊN LÀM VIỆC: nói rõ đang lưu ở đâu, và cho chốt -->
      <div class="card mt-4 p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h3 class="font-display text-base font-semibold text-brand-300">Phiên làm việc</h3>
            <p class="mt-0.5 text-body leading-5 text-cream-400">
              Mọi thứ bạn đặt ở đây được lưu theo TÀI KHOẢN, không chỉ trong trình duyệt này — mở máy khác hay điện thoại vẫn thấy đúng chỗ đang làm.
            </p>
          </div>
          <span class="rounded-full bg-ink-800 px-2.5 py-1 text-label text-cream-300" role="status" aria-live="polite">{{ savedLabel }}</span>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button type="button" class="tool-btn state-layer" :disabled="sessionSaving" @click="saveSession()">
            <StudioIcon name="save" size="h-3 w-3" /> {{ sessionSaving ? 'Đang lưu…' : 'Lưu ngay' }}
          </button>
          <button type="button" class="tool-btn state-layer" :disabled="sessionSaving" @click="closeSession()">
            <StudioIcon name="archive" size="h-3 w-3" /> Chốt phiên &amp; lưu trữ
          </button>
          <span class="text-label leading-4 text-cream-400">↳ Chốt phiên thì bộ sưu tập vẫn nằm trong «Bộ sưu tập» và mở lại được bất cứ lúc nào.</span>
        </div>

        <p v-if="sessionError" role="alert" class="mt-3 flex gap-2 rounded-lg border border-danger/40 bg-danger/10 p-3 text-body leading-5 text-danger">
          <StudioIcon name="alertTriangle" size="h-4 w-4" class="shrink-0" /><span>{{ sessionError }}</span>
        </p>
      </div>

      <p v-if="!prompt.trim()" role="alert" class="mt-4 rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-body text-warn">
        Ô mô tả bộ sưu tập đang trống — quay lại việc 1 để nhập, nếu không bước Thực thi sẽ không sinh được prompt.
      </p>
    </template>
  </div>
</template>
