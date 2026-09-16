<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const prompt = ref('');
const busy = ref(false);
const items = ref([]);
const projects = ref([]);
const projectId = ref('');
const _t = {};

async function loadProjects() {
  try {
    const res = await fetch('/api/projects', { headers: { Accept: 'application/json' } });
    if (!res.ok) return;
    const d = await res.json();
    projects.value = Array.isArray(d.items) ? d.items : [];
  } catch (e) { /* ignore */ }
}

function addItem(g) {
  const e = items.value.find((x) => String(x.id) === String(g.id));
  if (e) Object.assign(e, g);
  else items.value.unshift(g);
}

async function poll(id) {
  if (_t[id]) return;
  _t[id] = setInterval(async () => {
    try {
      const res = await fetch('/api/generations/' + id, { headers: { Accept: 'application/json' } });
      if (!res.ok) return;
      const g = await res.json();
      const it = items.value.find((x) => String(x.id) === String(g.id));
      if (it) { it.status = g.status; it.media_url = g.media_url; it.error = g.error; }
      const idx = store.generations.findIndex((x) => String(x.id) === String(g.id));
      if (idx >= 0) { store.generations[idx] = { ...store.generations[idx], status: g.status, media_url: g.media_url, error: g.error }; }
      if (['completed', 'failed', 'cancelled'].includes(g.status)) { clearInterval(_t[id]); delete _t[id]; }
    } catch (e) { /* ignore */ }
  }, 3000);
}

async function generate() {
  if (!prompt.value.trim() || busy.value) return;
  busy.value = true;
  try {
    const body = { prompt: prompt.value };
    if (projectId.value) body.project_id = projectId.value;
    const d = await store.api('/api/pattern', body);
    const g = { id: d.generation_id, status: d.status || 'pending', media_url: d.media_url, error: d.error };
    addItem(g);
    store.addGen({ id: d.generation_id, type: 'image', status: g.status, model: 'pattern', provider: 'image', media_url: d.media_url, error: d.error, credits_cost: 1, created_at: 'Vừa gửi' });
    prompt.value = '';
    if (['pending', 'processing'].includes(g.status)) poll(g.id);
  } catch (e) {
    store.toast(e.message || 'Lỗi.', 'error');
  } finally {
    busy.value = false;
  }
}

onMounted(loadProjects);
onBeforeUnmount(() => { Object.values(_t).forEach((t) => clearInterval(t)); });
</script>

<template>
  <div class="card p-5" style="background: linear-gradient(160deg, rgba(56,129,90,.12), rgba(201,164,95,.05));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-200">
      <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-brand-600/20 text-brand-300"><StudioIcon name="grid" size="h-4 w-4" /></span>
      Pattern Maker
      <span class="rounded-full bg-brand-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-brand-200">họa tiết</span>
    </h2>
    <p class="mt-1 text-[11px] text-cream-300/60">Tạo họa tiết vải / rập liền mạch từ mô tả.</p>

    <p class="label mt-4">Mô tả họa tiết</p>
    <textarea v-model="prompt" rows="3" class="input mt-1 !text-xs" placeholder="VD: hoa cúc vintage màu ngà trên nền be, phong cách toile" @keydown.enter.prevent="generate()"></textarea>

    <div v-if="projects.length" class="mt-3">
      <label class="label">Gắn vào dự án (không bắt buộc)</label>
      <select v-model="projectId" class="input !py-2 !text-xs">
        <option value="">-- Không gắn --</option>
        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
    </div>

    <button @click="generate()" :disabled="busy || !prompt.trim()" class="btn-brand mt-4 w-full whitespace-nowrap">
      <span v-if="busy" class="flex items-center justify-center gap-2"><StudioIcon name="refresh" size="h-4 w-4" class="animate-spin" /> Đang tạo…</span>
      <span v-else class="flex items-center justify-center gap-2"><StudioIcon name="grid" size="h-4 w-4" /> Tạo họa tiết</span>
    </button>

    <h3 class="mt-5 mb-2 font-display text-sm font-semibold text-cream-200">Kết quả</h3>
    <div v-if="items.length" class="grid grid-cols-2 gap-2">
      <div v-for="g in items" :key="g.id" class="overflow-hidden rounded-lg border border-ink-700 bg-ink-800">
        <img v-if="g.status === 'completed' && g.media_url" :src="g.media_url" class="aspect-[3/4] w-full object-cover" @error="$event.target.src = '/images/placeholder.svg'" alt="họa tiết">
        <div v-else class="grid aspect-[3/4] w-full place-items-center bg-ink-900 p-2 text-center">
          <span v-if="['pending','processing'].includes(g.status)" class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-brand-500 border-t-transparent"></span>
          <p v-else class="text-[11px] text-red-400">{{ g.error || 'Lỗi' }}</p>
        </div>
      </div>
    </div>
    <p v-else class="mt-1 rounded-md border border-dashed border-white/10 bg-white/5 p-3 text-center text-[11px] text-cream-300/60">Nhập mô tả rồi bấm “Tạo họa tiết”.</p>
  </div>
</template>
