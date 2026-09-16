<script setup>
// SourcePanel — card Nguồn (tối giản): chỉ còn 1 slot duy nhất.
// - Trống: icon thư viện (imagePlus) — bấm mở popup.
// - Đã có ảnh: hiển thị ảnh nguồn đang dùng (store.editSource) + nút X bỏ ảnh (góc trên phải).
// Không tiêu đề, không chữ chú thích — mọi tên chỉ hiện khi hover (title/aria).
// Bấm slot → mở SourcePickerPopup: popup gộp 2 nguồn (Thư viện ảnh + Sản phẩm) thành 2 tab.
import { useStudioStore } from '../store.js';
import { thumbUrl, onThumbError } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();
</script>
<template>
  <div class="card overflow-hidden">
    <div class="p-2">
      <!-- Slot duy nhất: icon (trống) | ảnh nguồn (đã có) — bấm mở popup 2 tab -->
      <div
        @click="store.sourcePickerOpen = true; store.exitCanvasTools()"
        role="button"
        tabindex="0"
        @keydown.enter.prevent="store.sourcePickerOpen = true"
        class="group relative block w-full cursor-pointer overflow-hidden rounded-md transition-colors focus:outline-none"
        :class="store.editSource ? 'border border-ink-700 bg-ink-900 hover:border-brand-500/70' : 'border border-dashed border-ink-600 bg-ink-800/50 hover:border-brand-500/70 hover:bg-ink-800'"
        :title="store.editSource ? 'Ảnh nguồn: ' + (store.editSource.name || 'Ảnh nguồn') + ' — bấm để thay / thêm (thư viện · sản phẩm)' : 'Thêm ảnh nguồn — thư viện đã tải lên · sản phẩm'"
        :aria-label="'Thêm ảnh nguồn'"
      >
        <template v-if="store.editSource">
          <img :src="thumbUrl(store.editSource.url)" class="aspect-square w-full bg-ink-900 object-cover" alt="" loading="lazy" @error="onThumbError($event, store.editSource.url)">
          <button
            @click.stop="store.removeEditSource()"
            class="absolute right-1 top-1 grid h-5 w-5 place-items-center rounded-md bg-black/55 text-cream-200 transition-colors hover:bg-red-600 hover:text-white"
            title="Bỏ ảnh nguồn khỏi canvas"
            :aria-label="'Bỏ ảnh nguồn khỏi canvas'"
          ><StudioIcon name="x" size="h-3 w-3"/></button>
        </template>
        <span v-else class="flex aspect-square w-full items-center justify-center text-cream-300/70 transition-colors group-hover:text-brand-300">
          <StudioIcon name="imagePlus" size="h-6 w-6"/>
        </span>
      </div>
    </div>

  </div>
</template>