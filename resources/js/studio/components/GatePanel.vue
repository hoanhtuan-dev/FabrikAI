<script setup>
/**
 * BA CỔNG DUYỆT của một bộ sưu tập — Việc #7, 2026-09-26.
 *
 * VÌ SAO CÓ MÀN HÌNH NÀY: bộ sưu tập đã có công cụ cho cả ba thứ đi ra nhà máy (phiếu kỹ thuật · kế hoạch
 * sản xuất & giá · biên bản QC) nhưng KHÔNG có chỗ nào nói "phần này đã được ai đó CHỐT". Trạng thái bộ
 * sưu tập (Nháp → Đang làm → Chờ duyệt → Đã duyệt) là duyệt BẢN THIẾT KẾ — duyệt ảnh không có nghĩa là
 * đã ký thông số, đã chốt tiền và đã nghiệm thu chất lượng.
 *
 * BỐN QUYẾT ĐỊNH GIAO DIỆN:
 *   1. HIỆN THÔNG TIN TRƯỚC, HIỆN NÚT SAU. Mỗi cổng in ra những gì người duyệt CẦN ĐỌC (số lượng, giá
 *      vốn, tỉ lệ lỗi thật, phiếu còn thiếu ô nào) — ký mà không thấy số là ký cho xong.
 *   2. NÚT DUYỆT TỰ KHOÁ khi chưa đủ điều kiện, kèm CÂU NÓI VÌ SAO (thiếu dữ liệu gì / cổng nào chưa
 *      xong) — không để người dùng bấm rồi nhận lỗi.
 *   3. TRẠNG THÁI HIỆU LỰC do MÁY CHỦ tính (đã duyệt · cần duyệt lại · hết hiệu lực) và giao diện chỉ
 *      HIỆN. Tự suy ra ở máy khách là chỗ để hai bên nói hai chuyện khác nhau.
 *   4. RÚT LẠI / MỞ LẠI LUÔN CÓ NÚT: một cổng không rút lại được là một cổng sẽ bị duyệt cho xong.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const props = defineProps({
  projectId: { type: Number, required: true },
});

const store = useStudioStore();

onMounted(() => { store.loadGates(props.projectId); });

const EMPTY = { items: [], summary: {}, shape: { gates: [], limits: {} } };
const data = computed(() => store.gates || EMPTY);
const items = computed(() => data.value.items || []);
const summary = computed(() => data.value.summary || {});
const limits = computed(() => (data.value.shape || {}).limits || { reject_note_min: 3, note_max: 300 });

/** Lý do ghi kèm quyết định — giữ theo từng cổng, nạp lại từ bản trên máy chủ khi bảng đổi. */
const notes = ref({});
watch(items, (rows) => {
  const next = { ...notes.value };
  for (const row of rows) {
    // Chỉ nạp khi ô đang TRỐNG: người dùng đang gõ dở mà bảng tự làm mới thì không được nuốt chữ của họ.
    if (!next[row.gate]) next[row.gate] = row.note || '';
  }
  notes.value = next;
}, { immediate: true });

function effectiveClass(item) {
  if (item.effective === 'approved') return 'bg-ok/15 text-ok';
  if (item.effective === 'rejected') return 'bg-danger/15 text-danger';
  if (item.effective === 'stale' || item.effective === 'void') return 'bg-warn/15 text-warn';
  return 'bg-ink-700 text-cream-300';
}

function borderClass(item) {
  if (item.effective === 'approved') return 'border-ok/40';
  if (item.effective === 'rejected') return 'border-danger/40';
  if (item.effective === 'stale' || item.effective === 'void') return 'border-warn/50';
  return 'border-ink-700';
}

function can(item, decision) {
  return (item.allowed || []).includes(decision);
}

/** Vì sao nút Duyệt đang khoá — nói ra, đừng để người dùng tự đoán. */
function blockedReason(item) {
  if (item.can_approve) return '';
  if (!item.previous_ok) return 'Phải duyệt xong cổng phía trước trước đã.';
  if (!item.requirements_ok) return 'Còn thiếu dữ liệu để duyệt — xem danh sách phía trên.';
  return '';
}

function noteFor(gate) {
  return notes.value[gate] || '';
}

async function decide(item, decision) {
  await store.decideGate(props.projectId, item.gate, decision, noteFor(item.gate));
}
</script>

<template>
  <section aria-label="Ba cổng duyệt" :aria-busy="store.gatesLoading">
    <div v-if="store.gatesLoading" class="p-5 text-body text-cream-400">Đang tải ba cổng duyệt…</div>

    <p v-else-if="store.gatesError" role="alert" class="m-4 rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-body text-danger">
      {{ store.gatesError }}
    </p>

    <div v-else class="space-y-4 p-4 sm:p-5">
      <!-- TỔNG KẾT -->
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label font-semibold text-cream-200">
          Đã duyệt {{ summary.approved || 0 }}/{{ summary.total || 0 }}
        </span>
        <span v-if="summary.needs_attention" class="rounded-full bg-warn/15 px-2.5 py-0.5 text-label font-semibold text-warn">
          {{ summary.needs_attention }} cổng cần xử lý
        </span>
        <span v-if="summary.ready" class="flex items-center gap-1 rounded-full bg-ok/15 px-2.5 py-0.5 text-label font-semibold text-ok">
          <StudioIcon name="check" size="h-3.5 w-3.5" /> Sẵn sàng bàn giao
        </span>
        <span v-else-if="summary.next_gate_label" class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-300">
          Việc tiếp theo: {{ summary.next_gate_label }}
        </span>
      </div>

      <p v-if="summary.ready" class="rounded-lg border border-ok/40 bg-ok/10 px-3 py-2 text-label leading-5 text-ok">
        Cả ba cổng đã duyệt và còn hiệu lực: thông số, tiền và chất lượng đều đã có người chịu trách nhiệm.
        Sửa bất kỳ dữ liệu nào ở trên (phiếu kỹ thuật · kế hoạch · kết quả kiểm) thì cổng tương ứng tự về
        “cần duyệt lại”.
      </p>

      <!-- TỪNG CỔNG -->
      <div v-for="item in items" :key="item.gate" class="rounded-lg border bg-ink-900/60 p-3" :class="borderClass(item)">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-body font-semibold text-cream-100">
              <span class="mr-1.5 inline-grid h-5 w-5 place-items-center rounded-full bg-ink-700 text-tiny text-cream-300">{{ item.order }}</span>
              {{ item.label }}
            </p>
            <p class="mt-1 text-label leading-5 text-cream-400">{{ item.hint }}</p>
          </div>
          <span class="shrink-0 rounded-full px-2 py-0.5 text-tiny font-semibold" :class="effectiveClass(item)">{{ item.effective_label }}</span>
        </div>

        <!-- THÔNG TIN ĐỂ DUYỆT: đọc trước khi ký -->
        <ul v-if="item.facts && item.facts.length" class="mt-2 space-y-0.5">
          <li v-for="(fact, i) in item.facts" :key="i" class="text-label leading-5 text-cream-300">· {{ fact }}</li>
        </ul>

        <!-- LÝ DO CHƯA DUYỆT ĐƯỢC -->
        <ul v-if="item.missing && item.missing.length" class="mt-2 space-y-1">
          <li v-for="(reason, i) in item.missing" :key="i"
              class="flex items-start gap-1.5 rounded border border-danger/40 bg-danger/10 px-2 py-1 text-label leading-5 text-danger">
            <StudioIcon name="alertTriangle" size="h-3.5 w-3.5 shrink-0" /> <span>{{ reason }}</span>
          </li>
        </ul>

        <p v-if="item.effective_reason" class="mt-2 rounded border border-warn/40 bg-warn/10 px-2 py-1 text-label leading-5 text-warn">
          {{ item.effective_reason }}
        </p>

        <!-- VẾT KIỂM TOÁN -->
        <p v-if="item.decided_by || item.note" class="mt-2 text-label leading-5 text-cream-400">
          <template v-if="item.decided_by">Người quyết: <span class="text-cream-200">{{ item.decided_by }}</span>
            <template v-if="item.decided_at_label"> · {{ item.decided_at_label }}</template>
          </template>
          <template v-if="item.note"><span v-if="item.decided_by"> · </span>“{{ item.note }}”</template>
        </p>

        <!-- HÀNH ĐỘNG -->
        <div class="mt-2.5 flex flex-wrap items-end gap-2">
          <label class="min-w-[16rem] flex-1 text-label text-cream-300">
            Lý do / ghi chú (bắt buộc khi không duyệt)
            <textarea v-model="notes[item.gate]" rows="1" class="input mt-0.5 w-full !py-1 text-label"
                      :maxlength="limits.note_max" placeholder="VD: bảng đo size L chưa có — xưởng không cắt được"></textarea>
          </label>

          <!-- Nút phụ trước, nút CHÍNH sau: dòng ↳ giải thích lý do khoá phải nằm NGAY dưới nút chính
               (docs/DESIGN_SYSTEM.md §4 luật 4) — để nó cách xa là người dùng bấm nút khoá rồi mới đi tìm lý do. -->
          <button v-if="can(item, 'rejected')" type="button" class="tool-btn btn-sm !text-danger hover:!bg-danger/15"
                  :disabled="store.gatesSaving || noteFor(item.gate).trim().length < (limits.reject_note_min || 3)"
                  title="Không duyệt thì phải ghi lý do" @click="decide(item, 'rejected')">
            <StudioIcon name="x" size="h-3.5 w-3.5" /> Không duyệt
          </button>
          <button v-if="can(item, 'pending')" type="button" class="tool-btn btn-sm" :disabled="store.gatesSaving" @click="decide(item, 'pending')">
            <StudioIcon name="undo" size="h-3.5 w-3.5" /> {{ item.decision === 'approved' ? 'Rút lại' : 'Mở lại' }}
          </button>
          <button v-if="can(item, 'approved')" type="button" class="btn-brand btn-sm" :disabled="store.gatesSaving || !item.can_approve" @click="decide(item, 'approved')">
            <StudioIcon name="check" size="h-3.5 w-3.5" /> Duyệt
          </button>
        </div>

        <p v-if="!item.can_approve && blockedReason(item)" class="mt-1.5 text-label text-cream-400">↳ {{ blockedReason(item) }}</p>
      </div>

      <p class="text-label leading-4 text-cream-400">
        Cổng sau chỉ mở khi cổng trước đã duyệt và CÒN HIỆU LỰC. Máy chủ từ chối duyệt khi thiếu dữ liệu thật
        (phiếu kỹ thuật · kế hoạch có số lượng và giá · ít nhất một lô đã kiểm và không lô nào đang không đạt).
        Duyệt xong mà sửa dữ liệu nguồn thì cổng đó tự về “cần duyệt lại” — quyết định cũ vẫn nằm trong hồ sơ
        kèm tên người duyệt, chỉ mất hiệu lực. Rút một cổng trước thì các cổng sau thành “hết hiệu lực”.
      </p>
    </div>
  </section>
</template>
