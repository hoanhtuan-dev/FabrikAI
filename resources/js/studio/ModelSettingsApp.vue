<script setup>
import { ref, computed, onMounted } from 'vue';
import { isAdminUser } from './composables/useLocalCatalog.js';

const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

/**
 * [Yêu cầu 2026-09-17] Cài đặt KHUÔN MẶT (model) + DÁNG POSE (người mẫu).
 *
 * Khác /presets và /stylist-data (lưu localStorage), mặt/dáng lưu ở SERVER và gắn `user_id`:
 * khi tạo ảnh, studio gửi LÊN id của mặt/dáng rồi backend tra ra ảnh tham chiếu
 * (VirtualTryOnService::pickModel/pickPose). Id chỉ nằm trong localStorage thì backend không
 * tra được ⇒ tính năng vô hiệu. Vì vậy ở đây phân quyền theo `user_id` chứ không theo máy khách.
 *
 *   user_id NULL  ⇒ catalog DÙNG CHUNG (dữ liệu có trước khi tách theo user)
 *   user_id = tôi ⇒ mặt/dáng RIÊNG của tôi (chỉ tôi thấy; owner thấy tất cả)
 */
const isAdmin = isAdminUser();

const TABS = [
  { id: 'model', label: '👤 Khuôn mặt (model)', hint: 'Khuôn mặt người mẫu dùng cho Thay người mẫu / Ghép ảnh.' },
  { id: 'pose', label: '🧍 Dáng pose (người mẫu)', hint: 'Dáng đứng của người mẫu khi tạo ảnh.' },
];

const tab = ref('model');
const loading = ref(true);
const error = ref('');
const toast = ref(null);
const saving = ref(false);

const faces = ref([]);
const poses = ref([]);
const assets = ref([]);

const form = ref({ name: '' });
const file = ref(null);
const fileInput = ref(null);

function flash(msg, ok = true) { toast.value = { msg, ok }; setTimeout(() => { toast.value = null; }, 2800); }

const ownIds = computed(() => new Set(assets.value.filter((a) => a.user_id != null).map((a) => String(a.id))));
const myCount = computed(() => assets.value.filter((a) => a.user_id != null).length);

const list = computed(() => {
  const src = tab.value === 'model' ? faces.value : poses.value;
  return src.map((it) => ({ ...it, mine: ownIds.value.has(String(it.id)) }));
});

const activeTab = computed(() => TABS.find((t) => t.id === tab.value) || TABS[0]);

async function jget(url) {
  const r = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

async function load() {
  loading.value = true; error.value = '';
  try {
    const [a, f, p] = await Promise.all([
      jget('/api/assets'),
      jget('/api/swap-models'),
      jget('/api/swap-poses'),
    ]);
    assets.value = Array.isArray(a.items) ? a.items : [];
    faces.value = Array.isArray(f.items) ? f.items : [];
    poses.value = Array.isArray(p.items) ? p.items : [];
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
onMounted(() => {
  // Cho phép vào thẳng một tab: /model-settings?tab=pose
  const q = new URLSearchParams(window.location.search).get('tab');
  if (q === 'model' || q === 'pose') tab.value = q;
  load();
});

function onPick(e) { file.value = (e.target.files && e.target.files[0]) || null; }

async function add() {
  if (!form.value.name.trim()) { flash('Cần đặt tên cho ' + (tab.value === 'model' ? 'khuôn mặt' : 'dáng pose') + '.', false); return; }
  if (!file.value) { flash('Cần chọn một ảnh.', false); return; }
  saving.value = true;
  try {
    const fd = new FormData();
    fd.append('type', tab.value);
    fd.append('name', form.value.name.trim());
    fd.append('image', file.value);
    const r = await fetch('/api/assets', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' },
      body: fd,
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
    form.value = { name: '' };
    file.value = null;
    if (fileInput.value) fileInput.value.value = '';
    flash('Đã thêm. Chỉ bạn thấy mục này.');
    await load();
  } catch (e) { flash(e.message, false); }
  finally { saving.value = false; }
}

async function remove(it) {
  if (!confirm('Xoá "' + it.name + '"? Ảnh cũng bị xoá khỏi kho của bạn.')) return;
  try {
    const r = await fetch('/api/assets/' + it.id, {
      method: 'DELETE',
      headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' },
    });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    flash('Đã xoá.');
    await load();
  } catch (e) { flash(e.message, false); }
}
</script>

<template>
  <div class="studio-dark w-full p-5">
    <div v-if="toast" :class="toast.ok ? 'bg-emerald-600' : 'bg-red-600'" class="fixed bottom-5 right-5 z-50 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-lg">{{ toast.msg }}</div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-xl font-semibold text-cream-50">⚙️ Cài đặt Khuôn mặt &amp; Dáng pose</h1>
        <p class="mt-0.5 text-xs text-ink-500">Mặt/dáng bạn thêm là <b>của riêng bạn</b> — người khác không thấy. Mục “dùng chung” là catalog sẵn có.</p>
      </div>
      <a href="/" class="btn-outline btn-sm whitespace-nowrap">← Về FabrikAI</a>
    </div>

    <div class="card mb-4 flex flex-wrap items-center justify-between gap-3 p-3">
      <p class="text-xs text-ink-400">
        Bạn đang có <b class="text-cream-200">{{ myCount }}</b> mục riêng.
        <span v-if="isAdmin" class="ml-1 text-amber-300/80">Là owner, bạn thấy và xoá được mặt/dáng của mọi người.</span>
      </p>
      <div class="flex gap-1.5">
        <button v-for="t in TABS" :key="t.id" @click="tab = t.id" :class="tab === t.id ? 'bg-brand-600 text-white' : 'bg-ink-700 text-cream-200 hover:bg-ink-600'" class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors">{{ t.label }}</button>
      </div>
    </div>

    <!-- Thêm mới -->
    <div class="card mb-4 p-4">
      <h2 class="mb-1 font-display text-sm font-semibold text-cream-100">Thêm {{ tab === 'model' ? 'khuôn mặt' : 'dáng pose' }}</h2>
      <p class="mb-3 text-[11px] text-ink-500">{{ activeTab.hint }}</p>
      <div class="grid gap-3 sm:grid-cols-3">
        <div>
          <label class="label">Tên</label>
          <input v-model="form.name" type="text" class="input !py-2" :placeholder="tab === 'model' ? 'VD: Nữ Việt 25 tuổi' : 'VD: Đứng nghiêng tay chống hông'">
        </div>
        <div>
          <label class="label">Ảnh tham chiếu</label>
          <input ref="fileInput" type="file" accept="image/*" class="input !py-1.5 text-xs" @change="onPick">
        </div>
        <div class="flex items-end">
          <button @click="add" :disabled="saving" class="btn-brand w-full !py-2">{{ saving ? 'Đang lưu…' : '＋ Thêm' }}</button>
        </div>
      </div>
    </div>

    <div v-if="loading" class="card p-10 text-center text-sm text-ink-500">Đang tải…</div>
    <div v-else-if="error" class="card border-red-300 p-6 text-sm text-red-600">{{ error }} — <button class="underline" @click="load">thử lại</button></div>
    <template v-else>
      <p class="mb-2 text-xs text-ink-500">{{ list.length }} mục trong {{ tab === 'model' ? 'khuôn mặt' : 'dáng pose' }}.</p>
      <div v-if="!list.length" class="card p-8 text-center text-sm text-ink-500">Chưa có mục nào.</div>
      <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="it in list" :key="it.id" class="card flex items-center gap-3 p-3">
          <img v-if="it.thumb || it.image" :src="it.thumb || it.image" loading="lazy" alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover ring-1 ring-white/15">
          <span v-else class="grid h-14 w-14 shrink-0 place-items-center rounded-lg bg-ink-700 text-lg">{{ tab === 'model' ? '👤' : '🧍' }}</span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-xs font-semibold text-cream-100">
              {{ it.name }}
              <span v-if="it.mine" class="ml-1 rounded bg-brand-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-brand-200">của bạn</span>
              <span v-else class="ml-1 rounded bg-ink-700 px-1.5 py-0.5 text-[9px] font-semibold text-cream-300/60">dùng chung</span>
            </p>
            <p class="truncate text-[10px] text-cream-300/50">#{{ it.id }}<span v-if="it.ethnicity"> · {{ it.ethnicity }}</span><span v-else-if="it.skeleton"> · {{ it.skeleton }}</span></p>
          </div>
          <button v-if="it.mine || isAdmin" @click="remove(it)" class="shrink-0 rounded-lg bg-red-600/25 px-2 py-1 text-[11px] text-red-200 hover:bg-red-600 hover:text-white">Xoá</button>
        </div>
      </div>
    </template>
  </div>
</template>
