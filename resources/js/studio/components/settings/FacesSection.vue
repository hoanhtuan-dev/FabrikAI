<script setup>
import { ref, computed, onMounted } from 'vue';
import { isAdminUser } from '../../composables/useLocalCatalog.js';
import { notify } from '../../composables/useSettingsToast.js';
import StudioIcon from '../StudioIcon.vue';
import SettingsSkeleton from './SettingsSkeleton.vue';
import SettingsEmpty from './SettingsEmpty.vue';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * MỤC "KHUÔN MẶT" và "DÁNG POSE" — refactor từ ModelSettingsApp.vue (bản cũ).
 *
 * Một component phục vụ HAI mục của sidebar, phân biệt bằng prop `kind`. Bản cũ dùng 2 tab trong cùng
 * một trang nên menu phải có 2 dòng trỏ vào cùng URL chỉ khác ?tab= — vừa thừa vừa dễ mở nhầm mục.
 *
 * GIỮ NGUYÊN hợp đồng API: GET /api/assets · GET /api/swap-models · GET /api/swap-poses ·
 * POST /api/assets · DELETE /api/assets/{id}. Không đổi endpoint nào.
 *
 * Vì sao mặt/dáng nằm ở server chứ không như preset (localStorage): khi tạo ảnh, studio gửi LÊN id của
 * mặt/dáng rồi backend tra ảnh tham chiếu (VirtualTryOnService::pickModel/pickPose). Id chỉ nằm trên máy
 * khách thì backend không tra được ⇒ tính năng vô hiệu. Nên phân quyền ở đây theo user_id:
 *   user_id NULL  ⇒ catalog DÙNG CHUNG (dữ liệu có trước khi tách theo user)
 *   user_id = tôi ⇒ mục RIÊNG của tôi (chỉ tôi thấy; owner thấy tất cả)
 */
const props = defineProps({ kind: { type: String, default: 'model' } });

const isAdmin = isAdminUser();
const isModel = computed(() => props.kind === 'model');
const NOUN = computed(() => (isModel.value ? 'khuôn mặt' : 'dáng pose'));

const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const addOpen = ref(false);
const pendingDelete = ref(null);

const faces = ref([]);
const poses = ref([]);
const assets = ref([]);

const form = ref({ name: '' });
const file = ref(null);
const fileInput = ref(null);

const query = ref('');
const scope = ref('all');   // all | mine | shared

const ownIds = computed(() => new Set(assets.value.filter((a) => a.user_id != null).map((a) => String(a.id))));
const myCount = computed(() => assets.value.filter((a) => a.user_id != null).length);

const list = computed(() => {
  const src = isModel.value ? faces.value : poses.value;
  const q = query.value.trim().toLowerCase();
  return src
    .map((it) => ({ ...it, mine: ownIds.value.has(String(it.id)) }))
    .filter((it) => {
      if (scope.value === 'mine' && !it.mine) return false;
      if (scope.value === 'shared' && it.mine) return false;
      if (!q) return true;
      return [it.name, it.ethnicity, it.skeleton, it.id].some((v) => String(v || '').toLowerCase().includes(q));
    });
});

const total = computed(() => (isModel.value ? faces.value : poses.value).length);
const isFiltering = computed(() => query.value.trim() !== '' || scope.value !== 'all');

async function jget(url) {
  const r = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

async function load() {
  loading.value = true; error.value = '';
  try {
    const [a, f, p] = await Promise.all([jget('/api/assets'), jget('/api/swap-models'), jget('/api/swap-poses')]);
    assets.value = Array.isArray(a.items) ? a.items : [];
    faces.value = Array.isArray(f.items) ? f.items : [];
    poses.value = Array.isArray(p.items) ? p.items : [];
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
onMounted(load);

function onPick(e) { file.value = (e.target.files && e.target.files[0]) || null; }

async function add() {
  if (!form.value.name.trim()) { notify.err('Cần đặt tên cho ' + NOUN.value + '.'); return; }
  if (!file.value) { notify.err('Cần chọn một ảnh.'); return; }
  saving.value = true;
  try {
    const fd = new FormData();
    fd.append('type', props.kind);
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
    addOpen.value = false;
    notify.ok('Đã thêm. Chỉ bạn thấy mục này.');
    await load();
  } catch (e) { notify.err(e.message); }
  finally { saving.value = false; }
}

async function confirmRemove() {
  const it = pendingDelete.value;
  if (!it) return;
  pendingDelete.value = null;
  try {
    const r = await fetch('/api/assets/' + it.id, {
      method: 'DELETE',
      headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' },
    });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    notify.ok('Đã xoá.');
    await load();
  } catch (e) { notify.err(e.message); }
}

const deleteMessage = computed(() => (pendingDelete.value ? 'Xoá "' + pendingDelete.value.name + '"?' : ''));
</script>

<template>
  <div>
    <!-- Thanh công cụ -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="relative min-w-[12rem] flex-1">
        <StudioIcon name="search" size="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cream-300/50" />
        <input v-model="query" type="search" :placeholder="'Tìm ' + NOUN + ' theo tên…'" class="input !py-2 pl-9" :aria-label="'Tìm ' + NOUN" />
      </div>
      <div class="flex overflow-hidden rounded-lg border border-ink-600">
        <button v-for="s in [{ id: 'all', label: 'Tất cả' }, { id: 'mine', label: 'Của tôi' }, { id: 'shared', label: 'Dùng chung' }]"
                :key="s.id" @click="scope = s.id"
                :class="scope === s.id ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'"
                class="px-2.5 py-1.5 text-[11px] font-medium transition">{{ s.label }}</button>
      </div>
      <button @click="addOpen = true" class="btn-brand btn-sm whitespace-nowrap">
        <StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm {{ NOUN }}
      </button>
    </div>

    <!-- Ai thấy gì -->
    <div class="card mb-4 flex flex-wrap items-center gap-2 p-3">
      <StudioIcon :name="isModel ? 'user' : 'pose'" size="h-4 w-4 text-brand-300" />
      <p class="min-w-0 flex-1 text-[11px] leading-relaxed text-cream-300/70">
        Bạn đang có <b class="text-cream-100">{{ myCount }}</b> mục riêng.
        Mục bạn thêm là <b>của riêng bạn</b> — người khác không thấy; mục <b>dùng chung</b> là catalog sẵn có.
        <span v-if="isAdmin" class="text-amber-300/80">Là owner, bạn thấy và xoá được mục của mọi người.</span>
      </p>
    </div>

    <!-- Thân -->
    <SettingsSkeleton v-if="loading" :rows="3" />

    <div v-else-if="error" class="card border-red-500/40 p-5">
      <p class="flex items-center gap-2 text-sm text-red-200"><StudioIcon name="alertTriangle" size="h-4 w-4" /> {{ error }}</p>
      <button @click="load" class="btn-outline btn-sm mt-3">Thử lại</button>
    </div>

    <SettingsEmpty v-else-if="!list.length" :filtered="isFiltering"
                   :icon="isModel ? 'user' : 'pose'"
                   :title="isFiltering ? ('Không có ' + NOUN + ' nào khớp bộ lọc') : ('Chưa có ' + NOUN + ' nào')"
                   :hint="isFiltering ? 'Thử xoá từ khoá tìm kiếm hoặc chọn phạm vi khác.' : (isModel ? 'Thêm ảnh khuôn mặt để dùng cho Thay người mẫu và Ghép ảnh.' : 'Thêm ảnh dáng đứng để áp cho người mẫu khi tạo ảnh.')">
      <button v-if="isFiltering" @click="query = ''; scope = 'all'" class="btn-outline btn-sm">Xoá bộ lọc</button>
      <button v-else @click="addOpen = true" class="btn-brand btn-sm">Thêm {{ NOUN }} đầu tiên</button>
    </SettingsEmpty>

    <template v-else>
      <p class="mb-3 text-[11px] text-cream-300/60">Hiện {{ list.length }} / {{ total }} mục.</p>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="it in list" :key="it.id" class="card flex items-center gap-3 p-3">
          <img v-if="it.thumb || it.image" :src="it.thumb || it.image" loading="lazy" alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover ring-1 ring-white/15">
          <span v-else class="grid h-14 w-14 shrink-0 place-items-center rounded-lg bg-ink-700 text-cream-300/50">
            <StudioIcon :name="isModel ? 'user' : 'pose'" size="h-5 w-5" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-xs font-semibold text-cream-100">
              {{ it.name }}
              <span v-if="it.mine" class="ml-1 rounded bg-brand-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-brand-200">của bạn</span>
              <span v-else class="ml-1 rounded bg-ink-700 px-1.5 py-0.5 text-[9px] font-semibold text-cream-300/60">dùng chung</span>
            </p>
            <p class="truncate text-[10px] text-cream-300/50">#{{ it.id }}<span v-if="it.ethnicity"> · {{ it.ethnicity }}</span><span v-else-if="it.skeleton"> · {{ it.skeleton }}</span></p>
          </div>
          <button v-if="it.mine || isAdmin" @click="pendingDelete = it"
                  class="tool-btn shrink-0 !text-red-300 hover:!bg-red-600/25" :title="'Xoá ' + it.name">
            <StudioIcon name="trash" size="h-3 w-3" />
          </button>
        </div>
      </div>
    </template>

    <!-- Thêm mới -->
    <div v-if="addOpen" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" :aria-label="'Thêm ' + NOUN" @click.self="addOpen = false">
      <div class="w-full max-w-lg rounded-lg border border-ink-700 bg-ink-900 p-5 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-cream-50">Thêm {{ NOUN }}</h3>
          <button @click="addOpen = false" class="grid h-8 w-8 place-items-center rounded-full bg-ink-800 text-cream-300 hover:bg-ink-700 hover:text-white" aria-label="Đóng">
            <StudioIcon name="x" size="h-4 w-4" />
          </button>
        </div>
        <p class="mb-4 text-[11px] leading-relaxed text-cream-300/60">
          {{ isModel ? 'Ảnh khuôn mặt rõ, chính diện, không bị che — dùng cho Thay người mẫu và Ghép ảnh.' : 'Ảnh toàn thân thể hiện rõ dáng đứng — dùng khi tạo ảnh người mẫu.' }}
        </p>
        <div>
          <label class="label" for="fs-name">Tên</label>
          <input id="fs-name" v-model="form.name" type="text" class="input !py-2"
                 :placeholder="isModel ? 'VD: Nữ Việt 25 tuổi' : 'VD: Đứng nghiêng tay chống hông'">
        </div>
        <div class="mt-3">
          <label class="label" for="fs-file">Ảnh tham chiếu</label>
          <input id="fs-file" ref="fileInput" type="file" accept="image/*" class="input !py-1.5 text-xs" @change="onPick">
        </div>
        <div class="mt-5 flex items-center justify-end gap-2">
          <button @click="addOpen = false" class="btn-outline btn-sm">Huỷ</button>
          <button @click="add" :disabled="saving" class="btn-brand btn-sm">{{ saving ? 'Đang lưu…' : 'Thêm' }}</button>
        </div>
      </div>
    </div>

    <ConfirmDialog :model-value="!!pendingDelete" :title="'Xoá ' + NOUN" danger
                   :message="deleteMessage"
                   detail="Ảnh tham chiếu cũng bị xoá khỏi kho của bạn. Ảnh đã tạo trước đó không bị ảnh hưởng."
                   confirm-label="Xoá"
                   @update:model-value="pendingDelete = null" @confirm="confirmRemove" />
  </div>
</template>
