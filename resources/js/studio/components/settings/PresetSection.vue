<script setup>
import { ref, computed, onMounted } from 'vue';
import { useLocalCatalog, isAdminUser } from '../../composables/useLocalCatalog.js';
import { notify } from '../../composables/useSettingsToast.js';
import StudioIcon from '../StudioIcon.vue';
import SettingsSkeleton from './SettingsSkeleton.vue';
import SettingsEmpty from './SettingsEmpty.vue';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * MỤC "PRESET" của khu Cài đặt — refactor từ PresetsApp.vue (bản cũ).
 *
 * Giữ NGUYÊN toàn bộ logic (api /api/presets, catalog 3 phần custom-edits-hidden, chế độ admin sửa bản
 * dùng chung). Chỉ làm lại phần trình bày theo các lỗi UX đã chỉ ra:
 *   · bỏ emoji trong tiêu đề (🗂️) — dùng đúng hệ icon của app (icons.json qua StudioIcon);
 *   · thay toast tự chế bằng khay thông báo dùng chung;
 *   · thay window.confirm() bằng ConfirmDialog (BaseModal có Esc + bẫy tiêu điểm);
 *   · thêm TÌM KIẾM — trước đây 9 danh mục × nhiều mục, muốn tìm một preset phải cuộn bằng mắt;
 *   · thêm khung xương + trạng thái rỗng phân biệt "chưa có dữ liệu" và "bộ lọc không khớp";
 *   · lọc theo danh mục bằng chip có số lượng, thay vì bắt cuộn qua mọi danh mục.
 */
// Nhãn nhóm preset — PHẢI khớp CHIP_CATEGORY_LABELS ở App\Services\PhotoStudioService (chip nhanh
// trong Studio dùng cùng nhãn) và phủ HẾT danh mục đang có trong bảng presets: trước đây thiếu
// 8 danh mục (màu sắc, cổ áo, tay áo, độ vừa vặn, họa tiết, chi tiết, dịp mặc, mùa) nên chip lọc
// hiện ra mã thô kiểu "color"/"neckline" thay vì tiếng Việt.
const CAT_LABELS = {
  fabric: 'Chất liệu', color: 'Màu sắc', silhouette: 'Phom dáng', neckline: 'Cổ áo', sleeve: 'Tay áo',
  fit: 'Độ vừa vặn', pattern: 'Họa tiết', detail: 'Chi tiết', style: 'Phong cách', occasion: 'Dịp mặc',
  season: 'Mùa', background: 'Bối cảnh', pose: 'Dáng đứng', camera: 'Góc máy', lens: 'Ống kính',
  video_scene: 'Kịch bản quay', inpaint: 'Sửa ảnh (Inpaint)',
};

const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

const catalog = useLocalCatalog('presets');
const isAdmin = isAdminUser();
const mode = ref('mine');   // 'mine' | 'global' — admin mới thấy công tắc này

const categories = ref([]);
const baseline = ref([]);
const merged = ref([]);
const loading = ref(true);
const error = ref('');
const saving = ref(false);

const query = ref('');
const catFilter = ref('all');
const addOpen = ref(false);
const resetOpen = ref(false);
const pendingDelete = ref(null);

const form = ref({ category: 'fabric', ui_label: '', prompt_injection: '', note: '', sort_order: 0 });
const editId = ref(null);
const edit = ref({ ui_label: '', prompt_injection: '', note: '', sort_order: 0 });

async function api(path, method = 'GET', body = null) {
  const opts = { method, headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } };
  if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const r = await fetch('/api/presets' + (path || ''), opts);
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
  return d;
}

function recompute() { merged.value = catalog.merge(baseline.value); }

async function load() {
  loading.value = true; error.value = '';
  try {
    const d = await api('');
    categories.value = Array.isArray(d.categories) ? d.categories : [];
    const flat = [];
    const grouped = d.presets || {};
    for (const cat of Object.keys(grouped)) {
      for (const it of grouped[cat] || []) flat.push({ ...it, category: cat });
    }
    baseline.value = flat;
    recompute();
    if (!form.value.category && categories.value.length) form.value.category = categories.value[0];
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}

// Nạp tùy chỉnh của tài khoản (nay ở SERVER) TRƯỚC khi ghép với baseline, nếu không sẽ hiện thiếu
// dữ liệu của chính người dùng trong tích tắc đầu.
onMounted(async () => { await catalog.load(); await load(); });

// ── Lọc + nhóm ──────────────────────────────────────────────────────────────
const allCategories = computed(() => {
  const set = new Set(categories.value);
  merged.value.forEach((p) => set.add(p.category));
  return Array.from(set);
});

const visible = computed(() => {
  const q = query.value.trim().toLowerCase();
  return merged.value.filter((p) => {
    if (catFilter.value !== 'all' && p.category !== catFilter.value) return false;
    if (!q) return true;
    return [p.ui_label, p.prompt_injection, p.note, p.id].some((v) => String(v || '').toLowerCase().includes(q));
  });
});

const grouped = computed(() => {
  const g = {};
  for (const it of visible.value) (g[it.category] = g[it.category] || []).push(it);
  return g;
});

const isFiltering = computed(() => query.value.trim() !== '' || catFilter.value !== 'all');
const countFor = (cat) => merged.value.filter((p) => p.category === cat).length;

// ── Thao tác ────────────────────────────────────────────────────────────────
async function create() {
  if (!form.value.ui_label.trim() || !form.value.prompt_injection.trim()) { notify.err('Cần nhập Key (nhãn) và Value (prompt).'); return; }
  saving.value = true;
  try {
    const payload = { ...form.value, sort_order: Number(form.value.sort_order) || 0 };
    if (mode.value === 'global') { await api('', 'POST', payload); await load(); }
    else { await catalog.create(payload); recompute(); }
    form.value = { ...form.value, ui_label: '', prompt_injection: '', note: '', sort_order: 0 };
    addOpen.value = false;
    notify.ok(mode.value === 'global' ? 'Đã thêm preset dùng chung.' : 'Đã thêm preset vào bản của bạn.');
  } catch (e) { notify.err(e.message); }
  finally { saving.value = false; }
}

function beginEdit(p) {
  editId.value = p.id;
  edit.value = { ui_label: p.ui_label, prompt_injection: p.prompt_injection, note: p.note, sort_order: p.sort_order || 0 };
}
function cancelEdit() { editId.value = null; }

async function saveEdit(id) {
  try {
    const payload = { ...edit.value, sort_order: Number(edit.value.sort_order) || 0 };
    if (mode.value === 'global') { await api('/' + id, 'PUT', payload); await load(); }
    else { await catalog.update(id, payload); recompute(); }
    editId.value = null;
    notify.ok(mode.value === 'global' ? 'Đã cập nhật preset dùng chung.' : 'Đã lưu vào bản của bạn.');
  } catch (e) { notify.err(e.message); }
}

function askRemove(p) { pendingDelete.value = p; }

async function confirmRemove() {
  const p = pendingDelete.value;
  if (!p) return;
  pendingDelete.value = null;
  try {
    if (mode.value === 'global') { await api('/' + p.id, 'DELETE'); await load(); }
    else { await catalog.remove(p.id); recompute(); }
    notify.ok(mode.value === 'global' ? 'Đã xoá preset dùng chung.' : 'Đã xoá khỏi bản của bạn.');
  } catch (e) { notify.err(e.message); }
}

async function doReset() {
  resetOpen.value = false;
  await catalog.reset();
  recompute();
  notify.ok('Đã khôi phục về bản mặc định.');
}

const deleteLabel = computed(() => (pendingDelete.value ? (pendingDelete.value.ui_label || pendingDelete.value.id) : ''));
// Ghép câu hỏi xác nhận ở SCRIPT chứ không nhồi dấu nháy lồng nhau trong template — dễ sai và khó đọc.
const deleteMessage = computed(() => 'Xoá preset "' + deleteLabel.value + '"?');
</script>

<template>
  <div>
    <!-- ── Thanh công cụ ─────────────────────────────────────────────────── -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="relative min-w-[12rem] flex-1">
        <StudioIcon name="search" size="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cream-300/50" />
        <input v-model="query" type="search" placeholder="Tìm preset theo nhãn, prompt hoặc chú giải…"
               class="input !py-2 pl-9" aria-label="Tìm preset" />
      </div>
      <button @click="addOpen = true" class="btn-brand btn-sm whitespace-nowrap">
        <StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm preset
      </button>
      <button v-if="catalog.hasOverrides()" @click="resetOpen = true" class="btn-outline btn-sm whitespace-nowrap" title="Xoá mọi tùy chỉnh của bạn, quay về bản mặc định">
        <StudioIcon name="rotateCcw" size="h-3.5 w-3.5" /> Khôi phục mặc định
      </button>
    </div>

    <!-- Admin: chuyển giữa bản của mình và bản dùng chung -->
    <div v-if="isAdmin" class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2">
      <StudioIcon name="lock" size="h-3.5 w-3.5 text-amber-300" />
      <p class="min-w-0 flex-1 text-[11px] leading-relaxed text-amber-100/90">
        Bạn là owner. <b>Bản của tôi</b> chỉ ảnh hưởng bạn; <b>Dùng chung</b> sửa preset cho MỌI người.
      </p>
      <div class="flex overflow-hidden rounded-md border border-ink-600">
        <button @click="mode = 'mine'" :class="mode === 'mine' ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'"
                class="px-2.5 py-1 text-[11px] font-medium transition">Bản của tôi</button>
        <button @click="mode = 'global'" :class="mode === 'global' ? 'bg-amber-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'"
                class="px-2.5 py-1 text-[11px] font-medium transition">Dùng chung</button>
      </div>
    </div>

    <!-- ── Chip lọc theo danh mục ────────────────────────────────────────── -->
    <div v-if="!loading && !error" class="mb-4 flex flex-wrap gap-1.5">
      <button @click="catFilter = 'all'"
              :class="catFilter === 'all' ? 'border-brand-400 bg-brand-600/20 text-brand-100' : 'border-ink-600 bg-ink-800/60 text-cream-300 hover:border-ink-500'"
              class="rounded-full border px-2.5 py-1 text-[11px] font-medium transition">
        Tất cả <span class="text-cream-300/60">{{ merged.length }}</span>
      </button>
      <button v-for="cat in allCategories" :key="cat" @click="catFilter = cat"
              :class="catFilter === cat ? 'border-brand-400 bg-brand-600/20 text-brand-100' : 'border-ink-600 bg-ink-800/60 text-cream-300 hover:border-ink-500'"
              class="rounded-full border px-2.5 py-1 text-[11px] font-medium transition">
        {{ CAT_LABELS[cat] || cat }} <span class="text-cream-300/60">{{ countFor(cat) }}</span>
      </button>
    </div>

    <!-- ── Thân ──────────────────────────────────────────────────────────── -->
    <SettingsSkeleton v-if="loading" :rows="4" />

    <div v-else-if="error" class="card border-red-500/40 p-5">
      <p class="flex items-center gap-2 text-sm text-red-200"><StudioIcon name="alertTriangle" size="h-4 w-4" /> {{ error }}</p>
      <button @click="load" class="btn-outline btn-sm mt-3">Thử lại</button>
    </div>

    <SettingsEmpty v-else-if="!visible.length" :filtered="isFiltering"
                   :title="isFiltering ? 'Không có preset nào khớp bộ lọc' : 'Chưa có preset nào'"
                   :hint="isFiltering ? 'Thử xoá từ khoá tìm kiếm hoặc chọn danh mục khác.' : 'Preset là cặp key: value — value được chèn thẳng vào prompt khi bạn chọn nó trong Studio.'"
                   icon="palette">
      <button v-if="isFiltering" @click="query = ''; catFilter = 'all'" class="btn-outline btn-sm">Xoá bộ lọc</button>
      <button v-else @click="addOpen = true" class="btn-brand btn-sm">Thêm preset đầu tiên</button>
    </SettingsEmpty>

    <template v-else>
      <p class="mb-3 text-[11px] text-cream-300/60">
        Hiện {{ visible.length }} / {{ merged.length }} preset.
        <span v-if="mode === 'mine'">Bản của bạn được lưu theo tài khoản — mở máy khác vẫn còn.</span>
      </p>

      <div v-for="cat in allCategories" :key="cat">
        <template v-if="grouped[cat] && grouped[cat].length">
          <div class="mb-2.5 mt-6 flex items-center gap-2 first:mt-0">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-cream-200">{{ CAT_LABELS[cat] || cat }}</h3>
            <span class="rounded-full bg-ink-700 px-2 py-0.5 text-[10px] text-cream-300">{{ grouped[cat].length }}</span>
          </div>
          <div class="grid gap-3 md:grid-cols-2">
            <div v-for="p in grouped[cat]" :key="p.id" class="card p-4">
              <template v-if="editId === p.id">
                <div class="grid gap-2">
                  <div class="flex items-center gap-2">
                    <input v-model="edit.ui_label" type="text" class="input !py-1.5 text-sm" aria-label="Key (nhãn)">
                    <input v-model="edit.sort_order" type="number" min="0" title="Thứ tự" class="input !py-1.5 w-20 text-sm" aria-label="Thứ tự">
                  </div>
                  <textarea v-model="edit.prompt_injection" rows="2" class="input !text-xs" aria-label="Value (prompt)"></textarea>
                  <textarea v-model="edit.note" rows="1" class="input !text-xs" placeholder="Chú giải…" aria-label="Chú giải"></textarea>
                  <div class="flex items-center justify-end gap-2">
                    <button @click="cancelEdit" class="btn-outline btn-sm">Huỷ</button>
                    <button @click="saveEdit(p.id)" class="btn-brand btn-sm">Lưu</button>
                  </div>
                </div>
              </template>
              <template v-else>
                <div class="flex items-start gap-2">
                  <span class="min-w-0 flex-1 text-sm font-semibold text-cream-50">{{ p.ui_label }}</span>
                  <span v-if="p._local" class="shrink-0 rounded bg-brand-600/30 px-1.5 py-0.5 text-[9px] font-semibold text-brand-200">của bạn</span>
                  <span class="shrink-0 font-mono text-[10px] text-cream-300/40">#{{ p.id }}</span>
                </div>
                <p class="mt-1.5 line-clamp-2 text-xs leading-relaxed text-cream-300/70">{{ p.prompt_injection }}</p>
                <p v-if="p.note" class="mt-1 text-[10px] italic text-cream-300/50">{{ p.note }}</p>
                <div class="mt-3 flex items-center justify-end gap-2">
                  <button @click="beginEdit(p)" class="tool-btn" title="Sửa preset">
                    <StudioIcon name="pencil" size="h-3 w-3" /> Sửa
                  </button>
                  <button @click="askRemove(p)" class="tool-btn !text-red-300 hover:!bg-red-600/25" title="Xoá preset">
                    <StudioIcon name="trash" size="h-3 w-3" /> Xoá
                  </button>
                </div>
              </template>
            </div>
          </div>
        </template>
      </div>
    </template>

    <!-- ── Thêm preset ───────────────────────────────────────────────────── -->
    <div v-if="addOpen" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-label="Thêm preset" @click.self="addOpen = false">
      <div class="w-full max-w-lg rounded-lg border border-ink-700 bg-ink-900 p-5 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-cream-50">Thêm preset</h3>
          <button @click="addOpen = false" class="grid h-8 w-8 place-items-center rounded-full bg-ink-800 text-cream-300 hover:bg-ink-700 hover:text-white" aria-label="Đóng">
            <StudioIcon name="x" size="h-4 w-4" />
          </button>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="label" for="np-cat">Danh mục</label>
            <select id="np-cat" v-model="form.category" class="input !py-2">
              <option v-for="cat in allCategories" :key="cat" :value="cat">{{ CAT_LABELS[cat] || cat }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="np-order">Thứ tự</label>
            <input id="np-order" v-model="form.sort_order" type="number" min="0" class="input !py-2">
          </div>
        </div>
        <div class="mt-3">
          <label class="label" for="np-label">Key (nhãn hiển thị)</label>
          <input id="np-label" v-model="form.ui_label" type="text" class="input !py-2" placeholder="VD: Old Money / Classic">
        </div>
        <div class="mt-3">
          <label class="label" for="np-value">Value (prompt tiếng Anh)</label>
          <textarea id="np-value" v-model="form.prompt_injection" rows="3" class="input !text-xs" placeholder="VD: old money aesthetic, timeless elegance, tailored wool"></textarea>
        </div>
        <div class="mt-3">
          <label class="label" for="np-note">Chú giải (tiếng Việt) — không bắt buộc</label>
          <textarea id="np-note" v-model="form.note" rows="2" class="input !text-xs" placeholder="VD: Chụp Lookbook thương mại…"></textarea>
        </div>
        <div class="mt-5 flex items-center justify-end gap-2">
          <button @click="addOpen = false" class="btn-outline btn-sm">Huỷ</button>
          <button @click="create" :disabled="saving" class="btn-brand btn-sm">{{ saving ? 'Đang lưu…' : 'Thêm preset' }}</button>
        </div>
      </div>
    </div>

    <ConfirmDialog v-model="resetOpen" title="Khôi phục bản mặc định" danger
                   message="Khôi phục về bản mặc định?"
                   detail="Mọi preset bạn tự thêm, sửa hoặc ẩn sẽ bị xoá khỏi tài khoản của bạn. Preset dùng chung không bị ảnh hưởng."
                   confirm-label="Khôi phục" @confirm="doReset" />

    <ConfirmDialog :model-value="!!pendingDelete" title="Xoá preset" danger
                   :message="deleteMessage"
                   :detail="mode === 'global' ? 'Đây là preset DÙNG CHUNG — mọi người sẽ mất preset này.' : 'Preset sẽ bị ẩn khỏi bản của bạn; bản dùng chung không đổi.'"
                   confirm-label="Xoá"
                   @update:model-value="pendingDelete = null" @confirm="confirmRemove" />
  </div>
</template>
