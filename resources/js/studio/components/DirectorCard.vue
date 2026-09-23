<script setup>
import { useStudioStore } from '../store.js';
import { thumbUrl } from '../composables/useStudioThumb.js';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();
</script>
<template>
  <div class="card p-5">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-200">
      <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-brand-600/20 text-brand-300"><StudioIcon name="film" size="h-4 w-4" /></span>
      Kịch bản quay
      <span class="rounded-full bg-brand-600/30 px-1.5 py-0.5 text-tiny font-semibold text-brand-200">video</span>
    </h2>

    <!-- Model video — danh sách từ Cài đặt →  Nhóm công việc (video) -->
    <div v-if="store.taskGroupModels('video').length > 1" class="mt-4 flex items-center gap-2">
      <StudioIcon name="bot" size="h-3.5 w-3.5" class="shrink-0 text-cream-400" />
      <select v-model="store.videoModelSel" class="input !py-2 !text-xs" title="Model render video — danh sách từ Cài đặt → Nhóm công việc (video)">
        <option value="">Mặc định ({{ store.taskGroupModels('video')[0]?.label || 'auto' }})</option>
        <option v-for="m in store.taskGroupModels('video')" :key="m.provider + m.model" :value="m.provider + ':' + m.model">{{ m.label }}</option>
      </select>
    </div>

    <!-- Thời lượng -->
    <div class="mt-4 flex items-center justify-between">
      <p class="label"><StudioIcon name="clock" size="h-3.5 w-3.5" class="-mt-0.5 mr-1 inline text-brand-300" /> Thời lượng</p>
    </div>
    <div class="mt-1 seg">
      <button v-for="d in ['5','8','10','15','20']" :key="d" @click="store.videoDuration = d" :class="store.videoDuration === d ? 'is-active' : ''" class="seg-btn">{{ d }}s</button>
    </div>

    <!-- Độ phân giải -->
    <div class="mt-4 flex items-center justify-between">
      <p class="label"><StudioIcon name="scan" size="h-3.5 w-3.5" class="-mt-0.5 mr-1 inline text-brand-300" /> Độ phân giải</p>
    </div>
    <div class="mt-1 seg">
      <button v-for="r in ['480','720','1080']" :key="r" @click="store.videoRes = r" :class="store.videoRes === r ? 'is-active' : ''" class="seg-btn">{{ r }}p</button>
    </div>

    <!-- Kịch bản quay (preset video_scene từ Prompt Templates) -->
    <div class="mt-4 flex items-center justify-between">
      <p class="label"><StudioIcon name="sliders" size="h-3.5 w-3.5" class="-mt-0.5 mr-1 inline text-brand-300" /> Kịch bản quay</p>
      <span class="text-tiny font-medium text-cream-400">{{ store.videoScenes.length }} mẫu</span>
    </div>
    <div v-if="store.videoScenes.length" class="mt-1 grid grid-cols-2 gap-1.5">
      <button v-for="sc in store.videoScenes" :key="sc.id" @click="store.videoScene = String(store.videoScene) === String(sc.id) ? '' : sc.id" :title="sc.prompt"
              class="flex items-center gap-2 rounded-md border px-2 py-1.5 text-left text-label font-semibold motion-ui motion-ui--size"
              :class="String(store.videoScene) === String(sc.id) ? 'border-brand-500 bg-brand-600/25 text-cream-50 shadow-brand-500/20' : 'border-ink-600 bg-ink-800 text-cream-200 hover:border-brand-400 hover:bg-ink-700'">
        <span class="grid h-5 w-5 shrink-0 place-items-center rounded-lg bg-ink-700/70 text-brand-300"><StudioIcon name="film" size="h-3 w-3" /></span>
        <span class="truncate">{{ sc.label }}</span>
      </button>
    </div>
    <p v-else class="mt-1 rounded-md border border-dashed border-ink-700 bg-cream-50/5 p-3 text-body text-cream-400">Chưa có preset Kịch bản quay — thêm ở <b>Cài đặt → Prompt Templates</b> (category video_scene).</p>

    <!-- Nguồn ảnh -->
    <div class="mt-4 flex items-center justify-between">
      <p class="label"><StudioIcon name="image" size="h-3.5 w-3.5" class="-mt-0.5 mr-1 inline text-brand-300" /> Nguồn ảnh</p>
    </div>
    <div v-if="store.upscaleSrc" class="mt-1 flex items-center gap-3 rounded-lg border border-ink-700 bg-cream-50/5 p-2.5">
      <img :src="thumbUrl(store.upscaleSrc)" class="h-14 w-14 shrink-0 rounded-md bg-ink-900 object-cover" alt="nguồn video">
      <div class="min-w-0 text-xs text-cream-200">
        <p class="truncate font-semibold">{{ store.upscaleName || 'Ảnh đang chọn' }}</p>
        <p class="text-cream-400">Dùng làm frame đầu của video</p>
      </div>
    </div>
    <div v-else class="mt-1 rounded-lg border border-dashed border-ink-700 bg-cream-50/5 p-3 text-xs text-cream-400">Chọn ảnh trên canvas làm frame đầu (bỏ trống = text-to-video).</div>

    <!-- Prompt video -->
    <p class="label mt-4"><StudioIcon name="pencil" size="h-3.5 w-3.5" class="-mt-0.5 mr-1 inline text-brand-300" /> Prompt video</p>
    <textarea v-model="store.videoPromptEn" rows="3" class="input mt-1 !text-xs" placeholder="(để trống để ghép tự động từ prompt ảnh)"></textarea>

    <button @click="store.renderVideo()" :disabled="store.videoBusy || (!store.videoPromptEn && !store.upscaleSrc)" class="btn-brand mt-4 w-full whitespace-nowrap">
      <span v-if="store.videoBusy" class="flex items-center justify-center gap-2"><StudioIcon name="refresh" size="h-4 w-4" class="animate-spin" /> Đang gửi…</span>
      <span v-else class="flex items-center justify-center gap-2"><StudioIcon name="film" size="h-4 w-4" /> Render Video thời trang</span>
    </button>
    <p v-if="!store.videoBusy && !store.videoPromptEn && !store.upscaleSrc" class="mt-1.5 text-label leading-4 text-warn">↳ Chưa có nội dung — nhập mô tả video, hoặc chọn ảnh trên canvas để ghép tự động.</p>
  </div>
</template>