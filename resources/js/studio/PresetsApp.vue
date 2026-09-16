<script setup>
import { ref, computed, onMounted } from 'vue';

const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

const CAT_LABELS = {
  fabric: 'Chất liệu', silhouette: 'Phom dáng', style: 'Phong cách', background: 'Bối cảnh',
  pose: 'Dáng đứng', camera: 'Góc máy', lens: 'Ống kính', video_scene: 'Kịch bản quay', inpaint: 'Sửa ảnh (Inpaint)',
};

const categories = ref([]);
const presets = ref({});     // { category: [ ...items ] }
const loading = ref(true);
const error = ref('');
const toast = ref(null);

// form thêm mới
const form = ref({ category: 'fabric', ui_label: '', prompt_injection: '', note: '', sort_order: 0 });
const saving = ref(false);
const editId = ref(null);
const edit = ref({ ui_label: '', prompt_injection: '', note: '', sort_order: 0 });

function flash(msg, ok = true) { toast.value = { msg, ok }; setTimeout(() => { toast.value = null; }, 2600); }

async function api(path, method = 'GET', body = null) {
  const opts = { method, headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } };
  if (body !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const r = await fetch('/api/presets' + (path ? path : ''), opts);
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.message || ('HTTP ' + r.status));
  return d;
}

async function load() {
  loading.value = true; error.value = '';
  try {
    const d = await api('');
    categories.value = Array.isArray(d.categories) ? d.categories : [];
    presets.value = d.presets || {};
    if (!form.value.category && categories.value.length) form.value.category = categories.value[0];
  } catch (e) { error.value = e.message; }
  finally { loading.value = false; }
}
onMounted(load);

const byCategory = computed(() => (cat) => presets.value[cat] || []);

async function create() {
  if (!form.value.ui_label.trim() || !form.value.prompt_injection.trim()) { flash('Cần key (nhãn) và value (prompt).', false); return; }
  saving.value = true;
  try {
    await api('', 'POST', { ...form.value, sort_order: Number(form.value.sort_order) || 0 });
    form.value = { ...form.value, ui_label: '', prompt_injection: '', note: '', sort_order: 0 };
    flash('Đã thêm preset.'); await load();
  } catch (e) { flash(e.message, false); }
  finally { saving.value = false; }
}

function beginEdit(p) { editId.value = p.id; edit.value = { ui_label: p.ui_label, prompt_injection: p.prompt_injection, note: p.note, sort_order: p.sort_order }; }
function cancelEdit() { editId.value = null; }

async function saveEdit(id) {
  try {
    await api('/' + id, 'PUT', { ...edit.value, sort_order: Number(edit.value.sort_order) || 0 });
    editId.value = null; flash('Đã cập nhật preset.'); await load();
  } catch (e) { flash(e.message, false); }
}

async function remove(id) {
  if (!confirm('Xóa preset này?')) return;
  try { await api('/' + id, 'DELETE'); flash('Đã xóa preset.'); await load(); }
  catch (e) { flash(e.message, false); }
}
</script>

<template>
  <div class="studio-dark w-full p-5">
    <div v-if="toast" :class="toast.ok ? 'bg-emerald-600' : 'bg-red-600'" class="fixed bottom-5 right-5 z-50 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-lg">{{ toast.msg }}</div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="font-display text-xl font-semibold text-cream-50">🗂️ Prompt Templates</h1>
        <p class="mt-0.5 text-xs text-ink-500">Quản lý mẫu prompt (preset) dùng trong Studio — mỗi preset là cặp <b>key: value</b>; value tự chèn vào câu lệnh khi chọn.</p>
      </div>
      <a href="/" class="btn-outline btn-sm whitespace-nowrap">← Về FabrikAI</a>
    </div>

    <div v-if="loading" class="card p-10 text-center text-sm text-ink-500">Đang tải…</div>
    <div v-else-if="error" class="card border-red-300 p-6 text-sm text-red-600">{{ error }} — <button class="underline" @click="load">thử lại</button></div>
    <template v-else>
      <!-- Thêm preset -->
      <div class="card p-5">
        <h2 class="mb-3 font-display text-base font-semibold text-cream-100">Thêm preset</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label class="label">Danh mục</label>
            <select v-model="form.category" class="input !py-2">
              <option v-for="cat in categories" :key="cat" :value="cat">{{ CAT_LABELS[cat] || cat }}</option>
            </select>
          </div>
          <div>
            <label class="label">Key (nhãn hiển thị)</label>
            <input v-model="form.ui_label" type="text" class="input !py-2" placeholder="VD: Old Money / Classic">
          </div>
          <div>
            <label class="label">Thứ tự</label>
            <input v-model="form.sort_order" type="number" min="0" class="input !py-2">
          </div>
          <div class="sm:col-span-2 lg:col-span-1">
            <label class="label">&nbsp;</label>
            <button @click="create" :disabled="saving" class="btn-brand w-full">Thêm</button>
          </div>
        </div>
        <div class="mt-3">
          <label class="label">Value (prompt tiếng Anh)</label>
          <textarea v-model="form.prompt_injection" rows="2" class="input !text-xs" placeholder="VD: old money aesthetic, timeless elegance, tailored linen and cashmere, neutral tones…"></textarea>
        </div>
        <div class="mt-3">
          <label class="label">Chú giải (tiếng Việt)</label>
          <textarea v-model="form.note" rows="1" class="input !text-xs" placeholder="VD: Chụp Lookbook thương mại…"></textarea>
        </div>
      </div>

      <!-- Danh sách theo danh mục -->
      <div v-for="cat in categories" :key="cat" class="mt-8">
        <div class="mb-3 flex items-center gap-2">
          <span class="badge bg-cream-100 text-ink-700">{{ CAT_LABELS[cat] || cat }}</span>
          <span class="text-xs text-ink-500">{{ byCategory(cat).length }} mẫu</span>
        </div>
        <div v-if="!byCategory(cat).length" class="text-sm text-ink-500">Chưa có preset nào ở danh mục này.</div>
        <div v-else class="grid gap-3 md:grid-cols-2">
          <div v-for="p in byCategory(cat)" :key="p.id" class="card p-4">
            <template v-if="editId === p.id">
              <div class="grid gap-2">
                <div class="flex items-center gap-2">
                  <input v-model="edit.ui_label" type="text" class="input !py-1.5 text-sm">
                  <input v-model="edit.sort_order" type="number" min="0" title="Thứ tự" class="input !py-1.5 text-sm w-20">
                </div>
                <textarea v-model="edit.prompt_injection" rows="2" class="input !text-xs"></textarea>
                <textarea v-model="edit.note" rows="1" class="input !text-xs" placeholder="Chú giải…"></textarea>
                <div class="flex items-center justify-end gap-2">
                  <button @click="saveEdit(p.id)" class="btn-outline btn-sm">Lưu</button>
                  <button @click="cancelEdit" class="btn-outline btn-sm">Hủy</button>
                </div>
              </div>
            </template>
            <template v-else>
              <div class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-cream-50">{{ p.ui_label }}</span>
                <span class="text-[10px] text-ink-500">#{{ p.id }}</span>
              </div>
              <p class="mt-1 line-clamp-2 text-xs text-ink-500">{{ p.prompt_injection }}</p>
              <p v-if="p.note" class="mt-1 text-[10px] italic text-ink-500">{{ p.note }}</p>
              <div class="mt-3 flex items-center justify-end gap-2">
                <button @click="beginEdit(p)" class="tool-btn" title="Sửa">Sửa</button>
                <button @click="remove(p.id)" class="tool-btn !text-red-300 hover:!bg-red-600/25" title="Xóa">Xóa</button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
