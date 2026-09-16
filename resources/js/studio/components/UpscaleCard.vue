<script setup>
import { ref } from 'vue';
import { useStudioStore } from '../store.js';
import CompareSlider from './CompareSlider.vue';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();
const beforeUrl = ref(''), afterUrl = ref(''), compareOpen = ref(false);

async function runUpscale() {
  if (!store.upscaleSrc || store.upscaling) return;
  store.upscaling = true;
  try {
    beforeUrl.value = store.upscaleSrc;
    const d = await store.api('/api/upscale', {
      image: store.upscaleSrc,
      scale: Number(store.upscaleScale) || 2,
      refine: Number(store.upscaleRefine) || 0,
      vibrance: Number(store.vibrance) || 0,
    });
    store.addGen({ id: d.generation_id, type: 'image', status: 'completed', model: 'upscale', provider: 'upscale', media_url: d.media_url, error: null, credits_cost: 0, created_at: 'Vừa nâng cấp' });
    afterUrl.value = d.media_url || '';
    store.toast('Đã nâng cấp ảnh (' + store.upscaleScale + 'x).');
  } catch (e) { store.toast(e.message || 'Lỗi nâng cấp ảnh.', 'error'); }
  finally { store.upscaling = false; }
}

function setv(field, val) { store[field] = Number(val); store.saveUpscaleMemory(); }
</script>

<template>
  <div class="card p-5" style="background: linear-gradient(160deg, rgba(232,150,120,.13), rgba(74,122,144,.06));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-brand-300"><StudioIcon name="maximize" /> Nâng cấp ảnh</h2>

    <template v-if="store.upscaleSrc">
      <div class="mt-3 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-2.5">
        <img :src="store.upscaleSrc" class="h-16 w-16 rounded-md bg-ink-900 object-cover">
        <div class="min-w-0 text-xs text-cream-200">
          <p class="truncate font-semibold">{{ store.upscaleName }}</p>
          <p class="text-cream-300/60">{{ store.upscaleScale }}x</p>
        </div>
      </div>
    </template>
    <div v-else class="mt-3 text-xs text-cream-300/60">Chọn ảnh để nâng cấp.</div>

    <!-- Độ phóng to -->
    <label class="label mt-3">Độ phóng to</label>
    <div class="flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-xs">
      <span class="shrink-0 font-medium text-cream-200">Độ phóng</span>
      <input type="range" min="1" max="4" step="1" :value="store.upscaleScale" @input="setv('upscaleScale', $event.target.value)" class="h-2 w-full cursor-pointer accent-brand-500">
      <span class="shrink-0 font-semibold text-cream-50">{{ store.upscaleScale }}x</span>
    </div>

    <!-- Tinh chỉnh AI -->
    <div class="mt-2 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-xs">
      <span class="w-32 shrink-0 font-medium text-cream-200">Tinh chỉnh AI</span>
      <input type="range" min="0" max="10" step="1" :value="store.upscaleRefine" @input="setv('upscaleRefine', $event.target.value)" class="h-2 w-full cursor-pointer accent-brand-500" title="0 = tắt · AI tăng chi tiết da/tóc/viền ảnh">
      <span class="shrink-0 font-semibold text-cream-50">{{ store.upscaleRefine }}</span>
    </div>

    <!-- Màu sống động -->
    <div class="mt-2 flex items-center gap-3 rounded-lg border border-white/10 bg-white/5 px-3 py-2.5 text-xs">
      <span class="w-32 shrink-0 font-medium text-cream-200">Màu sống động</span>
      <input type="range" min="0" max="10" step="1" :value="store.vibrance" @input="setv('vibrance', $event.target.value)" class="h-2 w-full cursor-pointer accent-brand-500" title="Tăng độ sống động màu, bảo vệ tone da">
      <span class="shrink-0 font-semibold text-cream-50">{{ store.vibrance }}</span>
    </div>

    <button @click="runUpscale" :disabled="store.upscaling || !store.upscaleSrc" class="btn-brand mt-3 w-full whitespace-nowrap">{{ store.upscaling ? 'Đang nâng cấp…' : 'Nâng cấp Ảnh' }}</button>
    <button v-if="beforeUrl && afterUrl" @click="compareOpen = true" title="So sánh ảnh trước/sau khi nâng cấp" class="btn-outline mt-1.5 w-full whitespace-nowrap"><StudioIcon name="columns" size="h-3.5 w-3.5" /> So sánh Trước/Sau</button>

    <CompareSlider v-model="compareOpen" :before="beforeUrl" :after="afterUrl" title="So sánh Trước/Sau khi nâng cấp" />
  </div>
</template>