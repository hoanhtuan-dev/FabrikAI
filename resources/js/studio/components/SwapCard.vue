<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue';
import { useStudioStore } from '../store.js';
import BaseModal from './BaseModal.vue';
import LoadingSpinner from './LoadingSpinner.vue';
const store = useStudioStore();
const now = ref(Date.now()); let _timer = null;
onMounted(() => { _timer = setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => { if (_timer) clearInterval(_timer); });
const open = ref(false);
const models = ref([]), poses = ref([]), bgs = ref([]), assets = ref([]);
const addType = ref('model'); const addName = ref(''); const addFile = ref(null); const addFileEl = ref(null);
const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};
const swapBg = ref('');
const swapTone = ref('none'); // Hiệu ứng tông màu mặc định: không áp dụng
const changeFace = ref(true); // MẶC ĐỊNH ĐỔI khuôn mặt theo người mẫu; tắt = giữ nguyên khuôn mặt gốc

const toneOptions = [
  { v: 'auto', label: '🎨 Tự động (theo bối cảnh)' },
  { v: 'warm', label: '☀️ Ấm' },
  { v: 'cool', label: '❄️ Lạnh' },
  { v: 'cinematic', label: '🎬 Điện ảnh' },
  { v: 'film', label: '🎞️ Film' },
  { v: 'dramatic', label: '🌑 Kịch tính' },
  { v: 'mono', label: '⚪ Trắng đen' },
  { v: 'none', label: '🚫 Không' },
];
// Persist swap settings across sessions (like upscale memory).
const SWAP_KEY = 'fabrikai.swap';
function loadSwapMemory() {
  try {
    const m = JSON.parse(localStorage.getItem(SWAP_KEY) || '{}');
    if (m.tone) swapTone.value = m.tone;
    if (typeof m.bg === 'string') swapBg.value = m.bg;
    // changeFace KHÔNG persist — luôn mặc định BẬT mỗi phiên.
  } catch (e) { console.error('studio operation failed', e); }
}
function saveSwapMemory() {
  try {
    localStorage.setItem(SWAP_KEY, JSON.stringify({ tone: swapTone.value, bg: swapBg.value }));
  } catch (e) { console.error('studio operation failed', e); }
}
watch([swapBg, swapTone], saveSwapMemory);
onMounted(async () => {
  loadSwapMemory();
  try { const r = await fetch('/api/swap-models', { headers: { Accept: 'application/json' } }); if (!r.ok) throw new Error('HTTP ' + r.status); const d = await r.json(); models.value = d.items || []; if (changeFace.value && !store.swapModelIds.length && models.value.length) store.swapModelIds = [String(models.value[0].id)]; } catch(e){ console.error('studio request failed', e); }
  try { const r = await fetch('/api/swap-poses', { headers: { Accept: 'application/json' } }); if (!r.ok) throw new Error('HTTP ' + r.status); const d = await r.json(); poses.value = d.items || []; } catch(e){ console.error('studio request failed', e); }
  try { const r = await fetch('/api/swap-backgrounds', { headers: { Accept: 'application/json' } }); if (!r.ok) throw new Error('HTTP ' + r.status); const d = await r.json(); bgs.value = d.items || []; } catch(e){ console.error('studio request failed', e); }
  try { const r = await fetch('/api/assets', { headers: { Accept: 'application/json' } }); if (!r.ok) throw new Error('HTTP ' + r.status); const d = await r.json(); assets.value = d.items || []; } catch(e){ console.error('studio request failed', e); }
});
const faceList = computed(() => [...models.value, ...assets.value.filter(a => a.type === 'model').map(a => ({ id: String(a.id), name: a.name, image: a.path, custom: true }))]);
const poseList = computed(() => [...poses.value, ...assets.value.filter(a => a.type === 'pose').map(a => ({ id: String(a.id), name: a.name, image: a.path, custom: true }))]);
// Face: single-select (one reference face). Poses: multi-select (apply creates one result per pose).
function selectOne(list, id, e) { e.stopPropagation(); if (list.length === 1 && list[0] === id) { list.splice(0, 1); } else { list.splice(0, list.length); list.push(id); } }
function toggle(list, id, e) { e.stopPropagation(); const i = list.indexOf(id); if (i >= 0) list.splice(i,1); else list.push(id); }
const selFaceImgs = computed(() => faceList.value.filter(f => store.swapModelIds.includes(f.id)));
const selPoseImgs = computed(() => poseList.value.filter(p => store.swapPoseIds.includes(p.id)));
const canApply = computed(() => !store.swapLoading && store.swapPoseIds.length > 0 && (changeFace.value ? store.swapModelIds.length > 0 : true));
const elapsedSec = computed(() => store.swapStartTs ? Math.max(0, Math.floor((now.value - store.swapStartTs) / 1000)) : 0);
const fmt = (s) => String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
const running = computed(() => store.swapStage === 'send' || store.swapStage === 'processing');
const activeModel = computed(() => { const id = store.swapGenIds[0]; const g = id != null ? store.generations.find((x) => String(x.id) === String(id)) : null; return g ? g.model : ''; });
const doneCount = computed(() => store.swapGenIds.filter((id) => { const g = store.generations.find((x) => String(x.id) === String(id)); return g && g.status === 'completed'; }).length);
const hasSelection = computed(() => store.swapModelIds.length > 0 || store.swapPoseIds.length > 0 || !!swapBg.value);
function resetSelections() {
  store.swapModelIds = [];
  store.swapPoseIds = [];
  swapBg.value = '';
  swapTone.value = 'none';
  store.toast('Đã bỏ lựa chọn khuôn mặt / dáng / bối cảnh.');
}
async function addAsset() {
  if (!addName.value || !addFile.value) { store.toast('Nhập tên + chọn file.', 'error'); return; }
  const fd = new FormData(); fd.append('type', addType.value); fd.append('name', addName.value); fd.append('image', addFile.value);
  const res = await fetch('/api/assets', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' }, body: fd });
  const d = await res.json().catch(() => ({})); if (!res.ok) { store.toast(d.message || 'Lỗi thêm.', 'error'); return; }
  addName.value = ''; addFile.value = null; if (addFileEl.value) addFileEl.value.value = '';
  const r = await fetch('/api/assets', { headers: { Accept: 'application/json' } }); if (!r.ok) { store.toast('Lỗi tải thư viện.', 'error'); return; } assets.value = (await r.json()).items || [];
  store.toast('Đã thêm ' + (addType.value === 'model' ? 'khuôn mặt' : 'dáng') + '.');
}
async function delAsset(a) {
  // M21: validate id trước khi ghép URL — trước đây nối thẳng a.id nên id lạ có thể tạo ra
  // request tới URL không mong muốn. Backend vẫn là phòng thủ thật, đây là lớp client.
  const assetId = Number(a && a.id);
  if (!Number.isInteger(assetId) || assetId <= 0) { store.toast('Không xóa được: id không hợp lệ.', 'error'); return; }
  const r = await fetch('/api/assets/' + assetId, { method: 'DELETE', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } }); if (r.ok) { assets.value = assets.value.filter(x => x.id !== a.id); const id = String(a.id); if (store.swapModelIds.includes(id)) { store.swapModelIds = store.swapModelIds.filter(x => x !== id); } if (store.swapPoseIds.includes(id)) { store.swapPoseIds = store.swapPoseIds.filter(x => x !== id); } store.toast('Đã xóa.'); } }
</script>
<template>
  <div class="card p-4" style="background: linear-gradient(160deg, rgba(232,87,125,.08), rgba(74,122,144,.05));">
    <div class="flex items-center justify-between gap-2">
      <div class="min-w-0">
        <h2 class="font-display text-sm font-semibold text-brand-300">🪄 Thay Đổi Người Mẫu</h2>
        <p class="mt-0.5 text-[11px] text-cream-300/60">{{ changeFace ? 'Thay khuôn mặt + dáng' : 'Giữ nguyên khuôn mặt · chỉ đổi dáng' }}</p>
      </div>
      <button v-if="hasSelection && !store.swapLoading && !store.swapProcessing" type="button" @click="resetSelections" class="shrink-0 rounded-full bg-ink-800 px-2.5 py-1 text-[10px] text-cream-300/70 hover:text-red-300" title="Bỏ lựa chọn khuôn mặt / dáng / bối cảnh">↺ Bỏ chọn</button>
    </div>

    <!-- 👩 Đổi khuôn mặt (mặc định BẬT) -->
    <div class="mt-3 flex items-center justify-between gap-3 rounded-lg border px-3 py-2" :class="changeFace ? 'border-brand-500/50 bg-brand-600/10' : 'border-ink-700 bg-ink-800/40'">
      <div class="min-w-0">
        <p class="text-xs font-semibold text-cream-200">👩 Đổi khuôn mặt</p>
        <p class="truncate text-[10px] text-cream-300/60">{{ changeFace ? 'Theo khuôn mặt người mẫu đã chọn' : 'Giữ nguyên khuôn mặt gốc' }}</p>
      </div>
      <button type="button" @click="changeFace = !changeFace" class="relative h-6 w-11 shrink-0 rounded-full transition-colors" :class="changeFace ? 'bg-brand-500' : 'bg-ink-600'" :title="changeFace ? 'Bỏ đổi khuôn mặt' : 'Bật đổi khuôn mặt'"><span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all" :class="changeFace ? 'left-[22px]' : 'left-0.5'"></span></button>
    </div>

    <div v-if="!changeFace || store.swapModelIds.length || store.swapPoseIds.length || swapBg" class="mt-2 flex flex-wrap gap-1.5 text-[10px]">
      <span v-if="!changeFace" class="rounded-full bg-ink-800 px-2 py-0.5 text-cream-300/80">👩 Giữ nguyên khuôn mặt</span>
      <span v-if="changeFace && selFaceImgs[0]" class="rounded-full bg-ink-800 px-2 py-0.5 text-cream-300/80">👩 {{ selFaceImgs[0].name }}</span>
      <span v-for="p in selPoseImgs" :key="p.id" class="rounded-full bg-ink-800 px-2 py-0.5 text-cream-300/80">🧍 {{ p.name }}</span>
      <span v-if="swapBg" class="rounded-full bg-ink-800 px-2 py-0.5 text-cream-300/80">🖼 {{ bgs.find(b=>b.value===swapBg)?.label || swapBg }}</span>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-1.5">
      <button @click="open=true" class="btn-outline btn-sm">🪄 Chọn</button>
      <button v-if="store.swapLoading || store.swapProcessing" @click="store.cancelSwap()" class="btn-brand btn-sm">{{ store.swapLoading ? ('⏳ ' + store.swapDone + '/' + store.swapTotal) : '⏳ Đang xử lý' }}</button>
      <button v-else @click="store.runSwap({ background: swapBg, tone: swapTone, change_face: changeFace })" :disabled="!canApply" class="btn-brand btn-sm">Áp dụng</button>
    </div>
    <p v-if="!canApply && !running" class="mt-1 text-[10px] text-cream-300/50">{{ changeFace ? 'Chọn 1 khuôn mặt + ít nhất 1 dáng.' : 'Chọn ít nhất 1 dáng.' }}</p>

    <!-- Trạng thái hoạt động (LoadingSpinner dùng chung) -->
    <div v-if="running" class="mt-3 rounded-lg border border-brand-500/30 bg-brand-900/30 p-3">
      <LoadingSpinner
        :text="store.swapStage === 'send' ? 'Đang gửi yêu cầu tới AI…' : 'AI đang đổi người mẫu…'"
        :subtext="'⏱ ' + fmt(elapsedSec) + ' · ' + doneCount + '/' + (store.swapGenIds.length || store.swapTotal) + ' dáng' + (activeModel ? ' · Model: ' + activeModel : '')" />
      <div class="mt-2 flex justify-end">
        <button @click="store.cancelSwap()" class="rounded-full bg-red-600/25 px-2.5 py-1 text-[10px] font-semibold text-red-200 hover:bg-red-600">✕ Hủy</button>
      </div>
      <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-white/10"><div class="h-full animate-pulse rounded-full bg-brand-400" style="width:60%"></div></div>
    </div>

    <div v-if="store.swapStage === 'done'" class="mt-3 flex items-center gap-2 rounded-lg border border-emerald-500/40 bg-emerald-900/25 p-3 text-xs text-emerald-200">
      ✅ Đã đổi xong — kết quả đã được in vào layer canvas.
      <button @click="store.clearSwapStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>

    <div v-if="store.swapStage === 'error' && store.swapError" class="mt-3 rounded-lg border border-red-500/40 bg-red-900/25 p-3 text-xs text-red-200">
      <p class="font-semibold">⚠️ Thay đổi người mẫu thất bại</p>
      <p class="mt-1 whitespace-pre-line leading-relaxed">{{ store.swapError }}</p>
      <div class="mt-2 flex gap-2">
        <button @click="store.runSwap({ background: swapBg, tone: swapTone, change_face: changeFace })" class="btn-brand btn-sm">🔄 Thử lại</button>
        <button @click="store.clearSwapStatus()" class="btn-ghost btn-sm">Đóng</button>
      </div>
    </div>

    <div v-if="store.swapStage === 'cancelled'" class="mt-3 flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 p-3 text-xs text-cream-200">
      🛑 Đã hủy yêu cầu đổi người mẫu.
      <button @click="store.clearSwapStatus()" class="ml-auto rounded-full bg-white/10 px-2 py-0.5 hover:bg-white/20">Đóng</button>
    </div>
    <BaseModal v-model="open" title="🪄 Chọn người mẫu / dáng / bối cảnh" wide>
      <div class="p-5">
        <!-- Khuôn mặt -->
        <template v-if="changeFace">
        <p class="mb-2 text-xs font-semibold text-cream-200">👩 Khuôn mặt</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="f in faceList" :key="f.id" @click="selectOne(store.swapModelIds, f.id, $event)" class="relative h-20 w-16 overflow-hidden rounded-lg border-2" :class="store.swapModelIds.includes(f.id) ? 'border-brand-500' : 'border-ink-700'"><img v-if="f.image" :src="f.image" class="h-full w-full bg-ink-900 object-cover"><span v-else class="grid h-full w-full place-items-center bg-ink-800 text-xl">👩</span><span class="absolute inset-x-0 bottom-0 bg-black/60 px-1 py-0.5 text-[9px] text-cream-200">{{ f.name }}</span><button v-if="f.custom" @click.stop="delAsset({id:f.id})" class="absolute right-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-red-600/90 text-[9px] text-white">🗑</button></button>
        </div>
        </template>
        <div v-else class="mb-px rounded-lg border border-dashed border-ink-600 bg-ink-800/60 p-3 text-xs leading-relaxed text-cream-300/70">👩 Đang <b class="text-cream-100">giữ nguyên khuôn mặt gốc</b> — chỉ đổi dáng, hậu cảnh và tông màu. Bật nút phía trên để chọn khuôn mặt người mẫu khác.</div>
        <!-- Dáng -->
        <p class="mb-2 mt-4 text-xs font-semibold text-cream-200">🧍 Dáng</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="p in poseList" :key="p.id" @click="toggle(store.swapPoseIds, p.id, $event)" class="relative h-20 w-16 overflow-hidden rounded-lg border-2" :class="store.swapPoseIds.includes(p.id) ? 'border-brand-500' : 'border-ink-700'"><img v-if="p.image" :src="p.image" class="h-full w-full bg-ink-900 object-cover"><span v-else class="grid h-full w-full place-items-center bg-ink-800 text-xl">🧍</span><span class="absolute inset-x-0 bottom-0 bg-black/60 px-1 py-0.5 text-[9px] text-cream-200">{{ p.name }}</span><button v-if="p.custom" @click.stop="delAsset({id:p.id})" class="absolute right-1 top-1 grid h-5 w-5 place-items-center rounded-full bg-red-600/90 text-[9px] text-white">🗑</button></button>
        </div>
        <!-- Bối cảnh -->
        <p class="mb-2 mt-4 text-xs font-semibold text-cream-200">🖼 Bối cảnh</p>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="b in bgs" :key="b.value" @click="swapBg = swapBg === b.value ? '' : b.value" class="rounded-full border px-3 py-1.5 text-xs" :class="swapBg === b.value ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-700 text-cream-200 hover:border-brand-400'">{{ b.label }}</button>
        </div>
        <!-- Hiệu ứng tông màu -->
        <div class="mt-4">
          <p class="mb-1 text-xs font-semibold text-cream-200">🎨 Hiệu ứng tông màu <span class="text-cream-300/60">(hợp với bối cảnh)</span></p>
          <div class="flex flex-wrap gap-1.5">
            <button v-for="t in toneOptions" :key="t.v" @click="swapTone = t.v" class="rounded-full border px-3 py-1.5 text-xs" :class="swapTone === t.v ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-700 text-cream-200 hover:border-brand-400'">{{ t.label }}</button>
          </div>
        </div>
        <!-- Thêm / xóa -->
        <div class="mt-5 rounded-lg border border-dashed border-cream-300/40 p-3">
          <p class="mb-2 text-xs font-semibold text-cream-200">➕ Thêm {{ addType === 'model' ? 'khuôn mặt' : 'dáng' }} (bộ sưu tập riêng)</p>
          <div class="flex flex-wrap items-center gap-2">
            <select v-model="addType" class="input !py-1.5 !w-32"><option value="model">Khuôn mặt</option><option value="pose">Dáng</option></select>
            <input v-model="addName" class="input !py-1.5 !w-40" placeholder="Tên">
            <input ref="addFileEl" type="file" accept="image/*" @change="e => addFile = e.target.files?.[0]" class="input !py-1.5 !w-48">
            <button @click="addAsset" class="btn-brand btn-sm">Thêm</button>
          </div>
        </div>
        <button @click="open=false" class="btn-brand mt-4 w-full">✅ Chọn xong</button>
      </div>
    </BaseModal>
  </div>
</template>