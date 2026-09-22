<script setup>
/**
 * TÌM THIẾT KẾ CŨ — FileSearch (Việc #9, 2026-09-26).
 *
 * VÌ SAO CÓ MÀN HÌNH NÀY: cả dự án chỉ có MỘT việc thật sự cần tới vec-tơ, và đây là nó. Trước đây "tìm
 * thiết kế cũ" là ĐẾM + DÒ TỪ KHOÁ cứng trong 120 prompt gần nhất — gõ "áo khoác màu be mùa trước" thì
 * không tìm được prompt viết "khoác dạ be" vì hai câu không dùng chung một từ nào.
 *
 * BỐN QUYẾT ĐỊNH GIAO DIỆN:
 *   1. NÓI RÕ ĐANG TÌM BẰNG GÌ. Có vec-tơ thì ghi "tìm theo NGỮ NGHĨA (n chiều)"; không có thì ghi "tìm
 *      theo TỪ KHOÁ — đây KHÔNG phải tìm ngữ nghĩa" kèm lý do. Gọi cả hai là "AI tìm kiếm" là nói sai.
 *   2. CHỈ MỤC LÀ VIỆC NHÌN THẤY ĐƯỢC: hiện đã nhúng bao nhiêu / tổng, và một nút lập chỉ mục ngay (có
 *      trần) — người dùng không phải chờ cron đêm mới tìm được.
 *   3. KẾT QUẢ NÓI NÓ Ở ĐÂU RA: loại tài liệu + tên bộ sưu tập. Một dòng "khớp 82%" mà không biết khớp
 *      cái gì thì không dùng được.
 *   4. CẢNH BÁO TRẦN: khi số tài liệu quét chạm trần thì nói ra — kết quả vẫn đúng nhưng có thể thiếu
 *      tài liệu cũ, và im lặng về điều đó là để người dùng tin nhầm là đã tìm hết.
 */
import { computed, onMounted, ref } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

onMounted(() => { store.loadDesignSearchStatus(); });

const data = computed(() => store.search || null);
const items = computed(() => (data.value && data.value.items) || []);
const status = computed(() => store.searchStatus || null);
const stats = computed(() => (status.value && status.value.stats) || {});
const limits = computed(() => ((status.value && status.value.shape) || {}).limits || {});

const query = ref('');
const searched = ref(false);

async function run() {
  const result = await store.runDesignSearch(query.value);
  searched.value = !!result;
}

async function indexNow() {
  await store.indexDesignSearch(50);
}

function sourceClass(type) {
  if (type === 'generation') return 'bg-brand-600/20 text-brand-200';
  if (type === 'lesson') return 'bg-ok/15 text-ok';
  if (type === 'tech_pack') return 'bg-ink-700 text-cream-200';
  return 'bg-warn/15 text-warn';
}
</script>

<template>
  <section aria-label="Tìm thiết kế cũ" :aria-busy="store.searchLoading">
    <div class="space-y-4 p-4 sm:p-5">
      <!-- Ô TÌM -->
      <div class="flex flex-wrap items-end gap-2">
        <label class="min-w-[16rem] flex-1 text-label text-cream-300">
          Tìm trong kho của bạn (ảnh đã tạo · brief · bài học · phiếu kỹ thuật)
          <input v-model="query" class="input mt-0.5 w-full" :maxlength="limits.query_max || 200"
                 placeholder="VD: áo khoác màu be mùa trước · đầm linen trắng ngà"
                 @keyup.enter="run">
        </label>
        <button type="button" class="btn-brand btn-sm" :disabled="store.searchLoading" @click="run">
          <StudioIcon name="search" size="h-3.5 w-3.5" /> {{ store.searchLoading ? 'Đang tìm…' : 'Tìm' }}
        </button>
      </div>

      <p v-if="store.searchError" role="alert" class="rounded-lg border border-danger/40 bg-danger/15 px-3 py-2 text-label text-danger">
        {{ store.searchError }}
      </p>

      <!-- CHỈ MỤC -->
      <div class="rounded-lg border border-ink-700 bg-ink-900/40 p-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-200">
            Chỉ mục: {{ stats.indexed || 0 }}/{{ stats.total || 0 }} tài liệu<span v-if="stats.percent !== null && stats.percent !== undefined"> ({{ stats.percent }}%)</span>
          </span>
          <!-- KHÔNG hiện tên nhà cung cấp AI hay model ở đây (docs/DESIGN_SYSTEM.md §6): người dùng cần
               biết "tìm theo ngữ nghĩa có bật không", không cần biết hạ tầng nào đang chạy. -->
          <span v-if="stats.can_embed" class="rounded-full bg-brand-600/20 px-2.5 py-0.5 text-label text-brand-200">
            Tìm theo ngữ nghĩa: đã bật
          </span>
          <span v-else class="rounded-full bg-warn/15 px-2.5 py-0.5 text-label text-warn">
            Tìm theo ngữ nghĩa: chưa bật (đang tìm theo từ khoá)
          </span>
          <button type="button" class="tool-btn btn-sm ml-auto" :disabled="store.searchBusy" @click="indexNow">
            <StudioIcon name="sparkles" size="h-3.5 w-3.5" /> {{ store.searchBusy ? 'Đang lập chỉ mục…' : 'Lập chỉ mục ngay (50 tài liệu)' }}
          </button>
        </div>
      </div>

      <!-- CHẾ ĐỘ TÌM -->
      <div v-if="data" class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full px-2.5 py-0.5 text-label font-semibold"
                :class="data.mode === 'embedding' ? 'bg-ok/15 text-ok' : 'bg-warn/15 text-warn'">{{ data.mode_label }}</span>
          <span class="rounded-full bg-ink-700 px-2.5 py-0.5 text-label text-cream-300">quét {{ data.scanned }} tài liệu · {{ data.took_ms }} ms</span>
        </div>

        <p v-if="data.reason" class="rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-label leading-5 text-warn">{{ data.reason }}</p>
        <p v-if="data.capped" class="rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-label leading-5 text-warn">
          Chỉ mục đã chạm trần {{ limits.max_scan }} tài liệu mỗi lượt quét — kết quả đúng nhưng có thể thiếu
          tài liệu CŨ HƠN. Máy chủ đang dùng cơ sở dữ liệu không có chỉ mục vec-tơ nên chỉ quét được trong trần này.
        </p>

        <p v-if="searched && !items.length" class="rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-label leading-5 text-cream-300">
          Không tìm thấy gì khớp. Thử từ khác, hoặc bấm <b class="text-cream-100">Lập chỉ mục ngay</b> nếu chỉ mục còn trống.
        </p>

        <!-- KẾT QUẢ -->
        <ul v-if="items.length" class="space-y-1.5">
          <li v-for="(item, i) in items" :key="i" class="rounded-lg border border-ink-700 bg-ink-900/60 p-2.5">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-label font-semibold text-cream-100">{{ item.title }}</p>
                <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-tiny text-cream-400">
                  <span class="rounded-full px-2 py-0.5" :class="sourceClass(item.source_type)">{{ item.source_label }}</span>
                  <span v-if="item.project_name">· {{ item.project_name }}</span>
                </p>
              </div>
              <span class="shrink-0 rounded-full bg-ink-700 px-2 py-0.5 text-tiny font-semibold text-cream-200">{{ item.score_pct }}%</span>
            </div>

            <p class="mt-1 text-label leading-5 text-cream-300">{{ item.snippet }}</p>

            <p v-if="item.matched && item.matched.length" class="mt-1 text-tiny text-cream-400">
              Khớp từ: {{ item.matched.join(' · ') }}
            </p>
          </li>
        </ul>
      </div>

      <p class="text-label leading-4 text-cream-400">
        Kho tài liệu gồm ảnh bạn đã tạo, brief của các bộ sưu tập, bài học agent đã rút và phiếu kỹ thuật — tất cả
        của <b class="text-cream-100">chính tài khoản bạn</b>. Khi có nhà cung cấp nhúng văn bản, tìm kiếm xếp theo
        độ gần NGỮ NGHĨA; khi không có, hệ thống tìm theo TỪ KHOÁ và ghi rõ như vậy. Chỉ mục được cập nhật tự động
        04:30 hằng ngày, và chỉ nhúng lại tài liệu đã đổi chữ.
      </p>
    </div>
  </section>
</template>
