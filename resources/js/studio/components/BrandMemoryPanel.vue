<script setup>
/**
 * TRÍ NHỚ ĐÃ HỌC — màn hình đọc lại những gì agent tự rút ra (2026-09-26).
 *
 * VÌ SAO CÓ: từ đợt 1 agent đã tự rút bài học sau mỗi lần bạn duyệt/loại ảnh, nhưng KHÔNG có chỗ nào cho
 * thấy nó học gì — người dùng chỉ thấy brief "tự nhiên" đổi giọng. Trí nhớ không đọc lại được thì không
 * sửa được; và nó chỉ SAI DẦN mà không ai biết.
 *
 * HAI QUYẾT ĐỊNH GIAO DIỆN:
 *   1. XẾP THEO ĐỘ MẠNH — đúng thứ tự brief đọc, nên màn hình này nói cùng một chuyện với thứ agent dùng.
 *   2. XOÁ BẰNG HAI NHỊP, KHÔNG dùng hộp thoại: danh sách này có thể dài và thao tác lặp lại — hộp thoại
 *      cho từng dòng là biến việc dọn trí nhớ thành một buổi làm việc. Nhịp hai (nút đổi thành "Chắc chắn
 *      quên?") vẫn đủ để không xoá nhầm.
 *
 * Bài học do AGENT viết, không phải người dùng gõ — nên ở đây không có ô nhập. Sửa bài học sai = XOÁ nó,
 * không phải viết một bài ngược lại để hai cái đánh nhau trong prompt.
 */
import { computed, ref } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

const open = ref(false);
const pendingForget = ref(0);

const data = computed(() => store.brandMemory || { items: [], stats: {}, limits: {} });
const items = computed(() => data.value.items || []);
const stats = computed(() => data.value.stats || {});

function toggle() {
  open.value = !open.value;
  // Nạp khi mở LẦN ĐẦU: danh sách này không cần cho bốn bước, gọi sẵn lúc mở trang là một lời gọi mạng
  // không ai xem.
  if (open.value && !store.brandMemory) store.loadBrandMemory();
}

function strengthClass(row) {
  if (row.strength === 'strong') return 'bg-ok/15 text-ok';
  if (row.strength === 'weak') return 'bg-ink-700 text-cream-400';
  return 'bg-brand-600/20 text-brand-200';
}

async function askForget(row) {
  if (pendingForget.value !== row.id) {
    pendingForget.value = row.id;
    return;
  }
  pendingForget.value = 0;
  await store.forgetBrandMemory(row.id);
}
</script>

<template>
  <div class="mt-2">
    <button type="button" class="tool-btn btn-sm" :aria-expanded="open" @click="toggle">
      <StudioIcon name="history" size="h-3.5 w-3.5" />
      {{ open ? 'Đóng trí nhớ' : 'Xem trí nhớ agent đã học' }}
      <span v-if="stats.total" class="ml-1 rounded-full bg-ink-700 px-1.5 text-tiny text-cream-300">{{ stats.total }}</span>
    </button>

    <div v-if="open" class="mt-2 rounded-lg border border-ink-700 bg-ink-900/60 p-3">
      <div v-if="store.brandMemoryLoading" class="text-body text-cream-400">Đang tải trí nhớ…</div>
      <p v-else-if="store.brandMemoryError" role="alert" class="rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-label text-danger">
        {{ store.brandMemoryError }}
      </p>

      <div v-else class="space-y-2.5">
        <!-- SỐ LIỆU -->
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label font-semibold text-cream-200">{{ stats.total || 0 }} ký ức</span>
          <span class="rounded-full bg-ok/15 px-2.5 py-0.5 text-label text-ok">{{ stats.approved || 0 }} lần duyệt</span>
          <span class="rounded-full bg-danger/15 px-2.5 py-0.5 text-label text-danger">{{ stats.rejected || 0 }} lần loại</span>
          <span class="rounded-full bg-brand-600/20 px-2.5 py-0.5 text-label text-brand-200">{{ stats.with_lesson || 0 }} đã rút bài học</span>
          <span v-if="stats.avg_weight" class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-300">độ mạnh TB {{ stats.avg_weight }}</span>
        </div>

        <p v-if="!items.length" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
          Chưa có ký ức nào. Mở một bộ sưu tập → <b class="text-cream-100">Duyệt mẫu</b> → duyệt hoặc loại vài ảnh:
          mỗi quyết định được ghi lại, và một job nền rút ra một câu bài học mà agent dùng cho brief lần sau.
        </p>

        <ul v-else class="max-h-80 space-y-1.5 overflow-y-auto pr-1">
          <li v-for="row in items" :key="row.id" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <p class="min-w-0 flex-1 text-label leading-5 text-cream-100">
                {{ row.lesson || '(chưa rút được bài học — job sẽ thử lại)' }}
              </p>
              <div class="flex shrink-0 items-center gap-1.5">
                <span class="rounded-full px-2 py-0.5 text-tiny font-semibold"
                      :class="row.decision === 'approved' ? 'bg-ok/15 text-ok' : 'bg-danger/15 text-danger'">{{ row.decision_label }}</span>
                <span class="rounded-full px-2 py-0.5 text-tiny" :class="strengthClass(row)">{{ row.strength_label }} · {{ row.weight }}</span>
              </div>
            </div>

            <p v-if="row.prompt" class="mt-1 text-tiny leading-4 text-cream-400">{{ row.prompt }}</p>

            <div class="mt-1 flex flex-wrap items-center gap-2 text-tiny text-cream-400">
              <span v-if="row.created_at_label">{{ row.created_at_label }}</span>
              <span v-if="row.age_days !== null">· {{ row.age_days }} ngày trước</span>
              <span v-if="row.hits">· nhắc lại {{ row.hits }} lần</span>
              <!-- Cột `source` của ký ức chứa "nhà cung cấp · model · thời điểm" — thông tin hạ tầng,
                   không hiện cho người dùng (docs/DESIGN_SYSTEM.md §6). Nó vẫn nằm trong dữ liệu trả về
                   để bộ phận kỹ thuật tra cứu. -->

              <button type="button" class="tool-btn btn-sm ml-auto !py-0.5 text-tiny"
                      :class="pendingForget === row.id ? '!border-danger/50 !text-danger' : ''"
                      :disabled="store.brandMemoryBusy" @click="askForget(row)">
                <StudioIcon name="trash" size="h-3 w-3" />
                {{ pendingForget === row.id ? 'Chắc chắn quên?' : 'Quên' }}
              </button>
            </div>
          </li>
        </ul>

        <p class="text-label leading-4 text-cream-400">
          Xếp theo <b class="text-cream-100">độ mạnh</b> — đúng thứ tự brief đọc. Ký ức trùng nhau được củng cố
          (mạnh lên), lâu không dùng thì yếu dần rồi bị quên (04:00 hằng ngày). Bài học do agent rút ra từ ảnh
          <b class="text-cream-100">bạn</b> duyệt/loại; nếu nó hiểu sai thì cách sửa đúng là <b class="text-cream-100">quên nó đi</b>.
        </p>
      </div>
    </div>
  </div>
</template>
