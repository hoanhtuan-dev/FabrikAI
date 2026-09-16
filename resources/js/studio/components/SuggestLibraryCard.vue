<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

const showDetail = ref(null); // id của item đang xem chi tiết, null = đóng

const stats = computed(() => store.suggestLibStats || {});
const selectedCount = computed(() => store.suggestLibSelection.length);
const allSelected = computed(() => store.suggestLibItems.length > 0 && store.suggestLibSelection.length === store.suggestLibItems.length);

function toggleAll() { allSelected.value ? store.suggestLibSelectNone() : store.suggestLibSelectAll(); }
function isSelected(id) { return store.suggestLibSelection.includes(id); }

function fmtNum(n) { const v = Number(n) || 0; return v >= 1000 ? v.toLocaleString('vi-VN') : String(v); }

let searchTimer = null;
function onSearchInput(e) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => store.setSuggestLibFilter('q', e.target.value || ''), 350);
}

// ── Xác nhận 2 bước ──
const confirmDelete = ref(false);
let confirmTimer = null;
function askBulkDelete() {
  if (!selectedCount.value) { store.toast('Chưa chọn prompt nào để xóa.', 'error'); return; }
  confirmDelete.value = true;
  clearTimeout(confirmTimer);
  confirmTimer = setTimeout(() => { confirmDelete.value = false; }, 5000);
}
function cancelConfirm() { confirmDelete.value = false; clearTimeout(confirmTimer); }
async function runConfirm() {
  confirmDelete.value = false;
  clearTimeout(confirmTimer);
  await store.suggestLibBulkDelete();
}

function openDetail(item) { showDetail.value = item.id; }
function closeDetail() { showDetail.value = null; }

onMounted(() => { if (!store.suggestLibItems.length) store.loadSuggestLib(); });
</script>
<template>
  <div class="flex flex-col h-full">
    <!-- Header: tìm kiếm + stats -->
    <div class="shrink-0 space-y-2 border-b border-ink-700 bg-ink-900/90 px-4 py-3">
      <div class="flex items-center gap-2">
        <div class="relative flex-1">
          <input
            type="text"
            @input="onSearchInput"
            placeholder="Tìm prompt hoặc loại trang phục…"
            class="w-full rounded-lg border border-ink-600 bg-ink-800 px-3 py-1.5 pl-8 text-xs text-cream-100 placeholder:text-cream-300/40 focus:border-brand-500 focus:outline-none"
          />
          <StudioIcon name="search" size="h-3.5 w-3.5" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-cream-300/40" />
        </div>
        <button
          v-if="store.suggestLibManage"
          @click="store.suggestLibManage = false"
          class="rounded-lg border border-ink-600 bg-ink-800 px-2.5 py-1.5 text-[10px] font-semibold text-cream-300 transition hover:bg-ink-700"
        >Xong</button>
        <button
          v-else
          @click="store.suggestLibManage = true"
          class="rounded-lg border border-ink-600 bg-ink-800 px-2.5 py-1.5 text-[10px] font-semibold text-cream-300 transition hover:bg-ink-700"
        >Chọn</button>
      </div>
      <div class="flex items-center gap-3 text-[10px] text-cream-300/50">
        <span>{{ fmtNum(stats.total || 0) }} prompt</span>
        <span v-if="stats.applied" class="text-brand-300/70">{{ stats.applied }} đã dùng</span>
        <span v-if="stats.unused" class="text-amber-300/70">{{ stats.unused }} chưa dùng</span>
      </div>
    </div>

    <!-- Gallery grid -->
    <div class="flex-1 overflow-y-auto scrollbar-hide p-3">
      <div v-if="store.suggestLibLoading && !store.suggestLibItems.length" class="py-16 text-center text-xs text-cream-300/50">
        Đang tải thư viện prompt…
      </div>
      <div v-else-if="!store.suggestLibItems.length" class="py-16 text-center">
        <p class="text-xs text-cream-300/50">Chưa có prompt nào được lưu.</p>
        <p class="mt-1 text-[10px] text-cream-300/30">Dùng card "💡 Gợi ý từ ảnh" → nhấn "💾 Lưu vào Thư viện Prompt".</p>
      </div>
      <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        <div
          v-for="item in store.suggestLibItems"
          :key="item.id"
          class="group relative cursor-pointer overflow-hidden rounded-lg border border-ink-700 bg-ink-800/70 transition hover:border-brand-500/50"
          @click="store.suggestLibManage ? store.toggleSuggestLibSelect(item.id) : openDetail(item)"
        >
          <!-- Checkbox (chế độ quản lý) -->
          <div v-if="store.suggestLibManage" class="absolute left-2 top-2 z-10">
            <input type="checkbox" :checked="isSelected(item.id)" class="h-4 w-4 accent-brand-500 rounded" @click.stop />
          </div>
          <!-- Thumbnail -->
          <div class="aspect-square overflow-hidden bg-ink-900">
            <img
              v-if="item.reference_thumb || item.reference_url"
              :src="item.reference_thumb || item.reference_url"
              class="h-full w-full object-cover transition group-hover:scale-105"
              @error="onThumbError"
              loading="lazy"
            />
            <div v-else class="flex h-full w-full items-center justify-center text-cream-300/30">
              <StudioIcon name="lightbulb" size="h-8 w-8" />
            </div>
          </div>
          <!-- Info overlay -->
          <div class="p-2">
            <div class="flex flex-wrap gap-1">
              <span
                v-for="s in (item.styles || []).slice(0, 2)"
                :key="s"
                class="truncate rounded-full bg-brand-600/20 px-1.5 py-0.5 text-[9px] text-brand-200"
              >{{ s }}</span>
              <span v-if="item.garment_type" class="truncate rounded-full bg-ink-700 px-1.5 py-0.5 text-[9px] text-cream-300/60">{{ item.garment_type }}</span>
            </div>
            <p class="mt-1 line-clamp-2 text-[10px] leading-relaxed text-cream-300/50">{{ item.image_prompt_en || '—' }}</p>
            <div class="mt-1.5 flex items-center justify-between text-[9px]">
              <span class="text-cream-300/40">{{ item.created_at }}</span>
              <span v-if="item.apply_count" class="rounded-full bg-emerald-800/40 px-1.5 py-0.5 text-emerald-200">Đã dùng {{ item.apply_count }}x</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Load more -->
      <div v-if="store.suggestLibHasMore" class="mt-3 text-center">
        <button
          @click="store.suggestLibNextPage()"
          :disabled="store.suggestLibLoading"
          class="rounded-lg border border-ink-600 bg-ink-800 px-4 py-2 text-xs text-cream-300 transition hover:bg-ink-700 disabled:opacity-40"
        >Tải thêm</button>
      </div>
    </div>

    <!-- Bottom bar: bulk actions -->
    <div v-if="store.suggestLibManage && selectedCount" class="shrink-0 border-t border-ink-700 bg-ink-900/90 px-4 py-2.5">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <button @click="toggleAll" class="text-[11px] text-brand-300 hover:text-brand-200">
            {{ allSelected ? 'Bỏ chọn tất cả' : 'Chọn tất cả' }}
          </button>
          <span class="text-[10px] text-cream-300/40">Đã chọn {{ selectedCount }}</span>
        </div>
        <div class="flex items-center gap-2">
          <template v-if="!confirmDelete">
            <button
              @click="askBulkDelete"
              class="rounded-lg border border-red-500/40 bg-red-900/30 px-3 py-1.5 text-[11px] font-semibold text-red-200 transition hover:bg-red-900/60"
            >Xóa {{ selectedCount }} prompt</button>
          </template>
          <template v-else>
            <span class="text-[10px] text-red-300/80">Xác nhận xóa?</span>
            <button @click="runConfirm" class="rounded-lg bg-red-600 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-red-500">Xóa</button>
            <button @click="cancelConfirm" class="rounded-lg border border-ink-600 bg-ink-800 px-2.5 py-1.5 text-[11px] text-cream-300 transition hover:bg-ink-700">Hủy</button>
          </template>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <div v-if="showDetail" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4" @click.self="closeDetail">
      <div class="w-full max-w-lg max-h-[85vh] overflow-y-auto rounded-lg border border-brand-500/40 bg-ink-900 shadow-2xl" @click.stop>
        <template v-for="item in store.suggestLibItems.filter(x => x.id === showDetail)" :key="item.id">
          <div class="flex items-center justify-between border-b border-ink-700 px-4 py-3">
            <h3 class="text-sm font-semibold text-brand-300">Chi tiết Prompt</h3>
            <button @click="closeDetail" class="grid h-7 w-7 place-items-center rounded-full bg-ink-700 text-cream-200 hover:bg-red-600" title="Đóng"><StudioIcon name="x" size="h-3.5 w-3.5" /></button>
          </div>
          <div class="space-y-3 p-4 text-xs">
            <!-- Ảnh nguồn -->
            <div v-if="item.reference_url" class="flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-2">
              <img :src="item.reference_thumb || item.reference_url" class="h-16 w-16 rounded-md bg-ink-900 object-cover" @error="onThumbError" />
              <span class="text-cream-300/60">Ảnh nguồn phân tích</span>
            </div>

            <!-- Tags -->
            <div class="flex flex-wrap gap-1.5">
              <span v-for="s in (item.styles || [])" :key="s" class="rounded-full bg-brand-600/20 px-2 py-0.5 text-[10px] text-brand-200">{{ s }}</span>
              <span v-if="item.garment_type" class="rounded-full bg-ink-700 px-2 py-0.5 text-[10px] text-cream-200">👕 {{ item.garment_type }}</span>
              <span v-if="item.embellishment" class="rounded-full bg-ink-700 px-2 py-0.5 text-[10px] text-cream-200">✨ {{ item.embellishment }}</span>
            </div>

            <!-- Chi tiết phân tích -->
            <div class="space-y-1.5 text-cream-200">
              <p v-if="item.background"><span class="text-cream-300/60">Bối cảnh:</span> {{ item.background }}</p>
              <p v-if="item.fabric"><span class="text-cream-300/60">Chất liệu:</span> {{ item.fabric }}</p>
              <p v-if="item.silhouette"><span class="text-cream-300/60">Dáng:</span> {{ item.silhouette }}</p>
              <p v-if="item.camera"><span class="text-cream-300/60">Góc máy:</span> {{ item.camera }}</p>
              <p v-if="item.pose"><span class="text-cream-300/60">Tư thế:</span> {{ item.pose }}</p>
            </div>

            <!-- Bảng màu -->
            <div v-if="(item.color_palette || []).length">
              <span class="text-cream-300/60">Bảng màu:</span>
              <div class="mt-1 flex flex-wrap gap-1">
                <span v-for="c in item.color_palette" :key="c" class="rounded-full bg-ink-700/70 px-2 py-0.5 text-[10px] text-cream-200">{{ c }}</span>
              </div>
            </div>

            <!-- Detail notes -->
            <div v-if="item.detail_notes" class="rounded-md border border-white/10 bg-white/5 p-2 leading-relaxed text-cream-100">
              <span class="text-cream-300/60">Chi tiết gốc:</span> {{ item.detail_notes }}
            </div>

            <!-- Prompt EN -->
            <div>
              <p class="mb-1 font-semibold text-cream-300/60">Prompt tiếng Anh</p>
              <div class="max-h-32 overflow-y-auto rounded-md border border-white/10 bg-white/5 p-2 leading-relaxed text-cream-100">{{ item.image_prompt_en || '—' }}</div>
            </div>

            <!-- Prompt VI -->
            <div v-if="item.prompt_vi">
              <p class="mb-1 font-semibold text-cream-300/60">Prompt tiếng Việt</p>
              <div class="max-h-32 overflow-y-auto rounded-md border border-white/10 bg-white/5 p-2 leading-relaxed text-cream-100">{{ item.prompt_vi }}</div>
            </div>

            <!-- Keywords -->
            <div v-if="(item.keywords || []).length" class="flex flex-wrap gap-1">
              <span v-for="k in item.keywords" :key="k" class="rounded-full bg-emerald-800/40 px-2 py-0.5 text-[10px] text-emerald-100">#{{ k }}</span>
            </div>

            <!-- Stats -->
            <div class="flex items-center gap-3 text-[10px] text-cream-300/40">
              <span>Lưu: {{ item.created_at }}</span>
              <span v-if="item.applied_at">Áp dụng: {{ item.applied_at }}</span>
              <span v-if="item.apply_count" class="text-brand-300/70">Dùng {{ item.apply_count }} lần</span>
            </div>

            <!-- Actions -->
            <div class="flex gap-2 pt-2">
              <button @click="closeDetail(); store.applySuggestPrompt(item)" class="btn-brand flex-1 text-xs">Áp dụng → Tạo Ảnh</button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
