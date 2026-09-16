<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const prompt = ref('');
const person = ref(null);
const personUrl = ref('');
const busy = ref(false);
const items = ref([]);
const projects = ref([]);
const projectId = ref('');
const fileInput = ref(null);
const _t = {};

const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

async function loadProjects() {
  try {
    const res = await fetch('/api/projects', { headers: { Accept: 'application/json' } });
    if (!res.ok) return;
    const d = await res.json();
    projects.value = Array.isArray(d.items) ? d.items : [];
  } catch (e) { /* ignore */ }
}

function onFile(e) {
  const f = e.target.files && e.target.files[0];
  if (!f) return;
  if (personUrl.value && personUrl.value.startsWith('blob:')) URL.revokeObjectURL(personUrl.value);
  person.value = f;
  personUrl.value = URL.createObjectURL(f);
}

function clearPerson() { person.value = null; personUrl.value = ''; if (fileInput.value) fileInput.value.value = ''; }

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
    const form = new FormData();
    form.append('prompt', prompt.value);
    if (person.value) form.append('image', person.value);
    if (projectId.value) form.append('project_id', projectId.value);
    const res = await fetch('/api/tryon', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' }, body: form });
    const d = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(d.message || 'Lỗi.');
    const g = { id: d.generation_id, status: d.status || 'pending', media_url: d.media_url, error: d.error };
    addItem(g);
    store.addGen({ id: d.generation_id, type: 'image', status: g.status, model: 'tryon', provider: 'image', media_url: d.media_url, error: d.error, credits_cost: 1, created_at: 'Vừa gửi' });
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
  <div class="card p-5" style="background: linear-gradient(160deg, rgba(124,58,237,.10), rgba(201,164,95,.05));">
    <h2 class="flex items-center gap-2 font-display text-base font-semibold text-cream-200">
      <span class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-violet-600/20 text-violet-300"><StudioIcon name="user" size="h-4 w-4" /></span>
      Virtual Try-On
      <span class="rounded-full bg-violet-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-violet-200">beta</span>
    </h2>
    <p class="mt-1 text-[11px] text-cream-300/60">Thử trang phục lên ảnh người mẫu (best-effort với model ảnh hiện có).</p>

    <div class="mt-4 grid gap-3">
      <div>
        <label class="label">Ảnh người mẫu (không bắt buộc)</label>
        <button type="button" @click="fileInput && fileInput.click()" class="tool-btn w-full justify-center">Chọn ảnh</button>
        <input ref="fileInput" type="file" accept="image/*" @change="onFile" class="hidden">
        <div v-if="personUrl" class="relative mt-2 overflow-hidden rounded-xl border border-ink-700">
          <img :src="personUrl" class="h-36 w-full bg-ink-900 object-cover" alt="người mẫu">
          <button @click="clearPerson" class="absolute right-2 top-2 grid h-7 w-7 place-items-center rounded-full bg-ink-900/70 text-white" title="Bỏ ảnh">×</button>
        </div>
      </div>
      <div>
        <label class="label">Trang phục cần thử</label>
        <textarea v-model="prompt" rows="3" class="input !text-xs" placeholder="VD: váy lụa màu kem dáng chữ A, tay ngắn"></textarea>
      </div>
    </div>

    <div v-if="projects.length" class="mt-3">
      <label class="label">Gắn vào dự án (không bắt buộc)</label>
      <select v-model="projectId" class="input !py-2 !text-xs">
        <option value="">-- Không gắn --</option>
        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
    </div>

    <button @click="generate()" :disabled="busy || !prompt.trim()" class="btn-brand mt-4 w-full whitespace-nowrap">
      <span v-if="busy" class="flex items-center justify-center gap-2"><StudioIcon name="refresh" size="h-4 w-4" class="animate-spin" /> Đang xử lý…</span>
      <span v-else class="flex items-center justify-center gap-2"><StudioIcon name="user" size="h-4 w-4" /> Thử đồ</span>
    </button>

    <h3 class="mt-5 mb-2 font-display text-sm font-semibold text-cream-200">Kết quả</h3>
    <div v-if="items.length" class="grid grid-cols-2 gap-2">
      <div v-for="g in items" :key="g.id" class="overflow-hidden rounded-lg border border-ink-700 bg-ink-800">
        <img v-if="g.status === 'completed' && g.media_url" :src="g.media_url" class="aspect-[3/4] w-full object-cover" @error="$event.target.src = '/images/placeholder.svg'" alt="try-on">
        <div v-else class="grid aspect-[3/4] w-full place-items-center bg-ink-900 p-2 text-center">
          <span v-if="['pending','processing'].includes(g.status)" class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-violet-500 border-t-transparent"></span>
          <p v-else class="text-[11px] text-red-400">{{ g.error || 'Lỗi' }}</p>
        </div>
      </div>
    </div>
    <p v-else class="mt-1 rounded-md border border-dashed border-white/10 bg-white/5 p-3 text-center text-[11px] text-cream-300/60">Nhập mô tả trang phục rồi bấm “Thử đồ”.</p>
  </div>
</template>
