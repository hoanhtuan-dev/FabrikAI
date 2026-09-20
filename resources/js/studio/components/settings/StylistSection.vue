<script setup>
import { ref, computed, onMounted } from 'vue';
import { useLocalCatalog, isAdminUser } from '../../composables/useLocalCatalog.js';
import { notify } from '../../composables/useSettingsToast.js';
import StudioIcon from '../StudioIcon.vue';
import SettingsSkeleton from './SettingsSkeleton.vue';
import SettingsEmpty from './SettingsEmpty.vue';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * MỤC "TRỢ LÝ THIẾT KẾ" — refactor từ components/StylistDataManager.vue (bản cũ).
 *
 * Bản cũ đã tốt hơn 2 trang kia ở chỗ dùng modal xác nhận thay vì window.confirm(), nhưng vẫn tự chế
 * khay thông báo riêng (pill ở top-4) và còn nhiều emoji trang trí. Nay dùng chung ConfirmDialog +
 * khay thông báo của khu Cài đặt, và icon lấy từ icons.json như phần còn lại của app.
 *
 * GIỮ NGUYÊN hợp đồng API: GET /api/stylist-data/data · POST /api/stylist-data/types ·
 * POST /api/stylist-data/questions · DELETE 2 đường dẫn tương ứng. Không đổi endpoint nào.
 *
 * Hai catalog tùy chỉnh của user: stylist.types và stylist.questions (nay lưu theo TÀI KHOẢN).
 */
const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

const typeCatalog = useLocalCatalog('stylist.types');
const questionCatalog = useLocalCatalog('stylist.questions');
const isAdmin = isAdminUser();
const mode = ref('mine');

const tab = ref('types');
const baseTypes = ref([]);
const baseQuestions = ref([]);
const types = ref([]);
const questions = ref([]);
const loading = ref(true);
const loadError = ref('');
const saving = ref(false);

const typeQuery = ref('');
const questionQuery = ref('');

const typeForm = ref({ id: null, slug: '', name: '', emoji: '', color: '#4a7a90' });
const editingType = ref(false);
const qForm = ref({ id: null, key: '', q: '', optsText: '' });
const editingQuestion = ref(false);

/** Hộp thoại xác nhận dùng chung: giữ nguyên mẫu "hỏi rồi mới làm" của bản cũ. */
const pending = ref(null);

function recompute() {
  types.value = typeCatalog.merge(baseTypes.value);
  questions.value = questionCatalog.merge(baseQuestions.value);
}

async function load() {
  loading.value = true; loadError.value = '';
  try {
    const r = await fetch('/api/stylist-data/data', { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    baseTypes.value = d.types || [];
    baseQuestions.value = d.questions || [];
    recompute();
  } catch (e) { loadError.value = 'Lỗi tải dữ liệu (' + e.message + ').'; }
  finally { loading.value = false; }
}

onMounted(async () => {
  // Nạp tùy chỉnh của tài khoản TRƯỚC khi ghép, nếu không sẽ hiện thiếu dữ liệu của chính người dùng.
  await Promise.all([typeCatalog.load(), questionCatalog.load()]);
  await load();
});

async function postJson(url, body) {
  const r = await fetch(url, { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.message || 'Lỗi lưu.');
  return d;
}
async function del(url) {
  const r = await fetch(url, { method: 'DELETE', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } });
  if (!r.ok) throw new Error('Lỗi xóa.');
}

function newType() { typeForm.value = { id: null, slug: '', name: '', emoji: '', color: '#4a7a90' }; editingType.value = true; }
function editType(t) { typeForm.value = { id: t.id, slug: t.slug, name: t.name, emoji: t.emoji || '', color: t.color || '#4a7a90' }; editingType.value = true; }
function cancelType() { editingType.value = false; typeForm.value = { id: null, slug: '', name: '', emoji: '', color: '#4a7a90' }; }

async function saveType() {
  saving.value = true;
  try {
    const payload = { slug: typeForm.value.slug, name: typeForm.value.name, emoji: typeForm.value.emoji, color: typeForm.value.color };
    if (mode.value === 'global') {
      await postJson('/api/stylist-data/types', { id: typeForm.value.id, ...payload });
      await load();
      notify.ok('Đã lưu loại trang phục dùng chung.');
    } else {
      if (typeForm.value.id) typeCatalog.update(typeForm.value.id, payload);
      else typeCatalog.create(payload);
      recompute();
      notify.ok('Đã lưu vào bản của bạn.');
    }
    cancelType();
  } catch (e) { notify.err(e.message); }
  finally { saving.value = false; }
}

function deleteType(t) {
  pending.value = {
    title: 'Xoá loại trang phục',
    message: 'Xoá loại trang phục "' + t.name + '"?',
    detail: mode.value === 'global' ? 'Đây là dữ liệu DÙNG CHUNG — mọi người sẽ mất loại này.' : 'Loại này sẽ bị ẩn khỏi bản của bạn; bản dùng chung không đổi.',
    action: async () => {
      try {
        if (mode.value === 'global') { await del('/api/stylist-data/types/' + t.id); await load(); }
        else { typeCatalog.remove(t.id); recompute(); }
        notify.ok('Đã xoá.');
      } catch (e) { notify.err(e.message); }
    },
  };
}

function newQuestion() { qForm.value = { id: null, key: '', q: '', optsText: '' }; editingQuestion.value = true; }
function editQuestion(q) { qForm.value = { id: q.id, key: q.key, q: q.q, optsText: (q.opts || []).join('\n') }; editingQuestion.value = true; }
function cancelQuestion() { editingQuestion.value = false; qForm.value = { id: null, key: '', q: '', optsText: '' }; }

async function saveQuestion() {
  saving.value = true;
  try {
    const opts = qForm.value.optsText.split('\n').map((s) => s.trim()).filter(Boolean);
    const payload = { key: qForm.value.key, q: qForm.value.q, opts };
    if (mode.value === 'global') {
      await postJson('/api/stylist-data/questions', { id: qForm.value.id, ...payload });
      await load();
      notify.ok('Đã lưu câu hỏi dùng chung.');
    } else {
      if (qForm.value.id) questionCatalog.update(qForm.value.id, payload);
      else questionCatalog.create(payload);
      recompute();
      notify.ok('Đã lưu vào bản của bạn.');
    }
    cancelQuestion();
  } catch (e) { notify.err(e.message); }
  finally { saving.value = false; }
}

function deleteQuestion(q) {
  pending.value = {
    title: 'Xoá câu hỏi',
    message: 'Xoá câu hỏi "' + q.key + '"?',
    detail: mode.value === 'global' ? 'Đây là dữ liệu DÙNG CHUNG — mọi người sẽ mất câu hỏi này.' : 'Câu hỏi sẽ bị ẩn khỏi bản của bạn; bản dùng chung không đổi.',
    action: async () => {
      try {
        if (mode.value === 'global') { await del('/api/stylist-data/questions/' + q.id); await load(); }
        else { questionCatalog.remove(q.id); recompute(); }
        notify.ok('Đã xoá.');
      } catch (e) { notify.err(e.message); }
    },
  };
}

function resetMine() {
  pending.value = {
    title: 'Khôi phục bản mặc định',
    message: 'Khôi phục về bản mặc định?',
    detail: 'Mọi loại trang phục và câu hỏi bạn tự thêm hoặc sửa sẽ bị xoá khỏi tài khoản của bạn. Dữ liệu dùng chung không bị ảnh hưởng.',
    danger: true,
    confirmLabel: 'Khôi phục',
    action: () => { typeCatalog.reset(); questionCatalog.reset(); recompute(); notify.ok('Đã khôi phục bản mặc định.'); },
  };
}

async function runPending() {
  const job = pending.value;
  pending.value = null;
  if (job && typeof job.action === 'function') await job.action();
}

/**
 * Đọc types.value/questions.value để computed này PHỤ THUỘC vào reactivity: catalog là state thường
 * (không phải ref) nên nếu chỉ gọi hasOverrides() trực tiếp thì nút "Khôi phục mặc định" sẽ không
 * tự hiện/mất sau khi người dùng thêm hoặc xoá mục.
 */
const hasOverrides = computed(() => {
  void types.value.length;
  void questions.value.length;
  return typeCatalog.hasOverrides() || questionCatalog.hasOverrides();
});

const visibleTypes = computed(() => {
  const q = typeQuery.value.trim().toLowerCase();
  if (!q) return types.value;
  return types.value.filter((t) => [t.name, t.slug].some((v) => String(v || '').toLowerCase().includes(q)));
});

const visibleQuestions = computed(() => {
  const q = questionQuery.value.trim().toLowerCase();
  if (!q) return questions.value;
  return questions.value.filter((x) => [x.key, x.q].some((v) => String(v || '').toLowerCase().includes(q)));
});
</script>
<template>
  <div>
    <!-- Phạm vi + khôi phục -->
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-ink-700 bg-ink-800/60 px-3 py-2">
      <StudioIcon name="info" size="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-300" />
      <p class="min-w-0 flex-1 text-body leading-relaxed text-cream-300">
        <b class="text-cream-100">Bản của bạn</b> lưu theo <b>tài khoản</b> — mở máy khác vẫn còn.
        <span v-if="isAdmin" class="text-warn">Bạn là owner: <b>Dùng chung</b> sửa dữ liệu cho MỌI người.</span>
      </p>
      <div v-if="isAdmin" class="flex overflow-hidden rounded-md border border-ink-600">
        <button @click="mode='mine'" :class="mode==='mine' ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'" class="px-2.5 py-1 text-body font-medium transition">Bản của tôi</button>
        <button @click="mode='global'" :class="mode==='global' ? 'bg-amber-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'" class="px-2.5 py-1 text-body font-medium transition">Dùng chung</button>
      </div>
      <button v-if="hasOverrides" @click="resetMine" class="btn-outline btn-sm whitespace-nowrap" title="Xoá mọi tùy chỉnh của bạn, quay về bản mặc định">
        <StudioIcon name="rotateCcw" size="h-3.5 w-3.5" /> Khôi phục mặc định
      </button>
    </div>

    <!-- Tab trong mục (khác sidebar: đây là 2 bảng dữ liệu của cùng một mục) -->
    <div class="mb-4 flex flex-wrap items-center gap-2">
      <div class="flex overflow-hidden rounded-lg border border-ink-600">
        <button @click="tab='types'" :class="tab==='types' ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'" class="flex items-center gap-1.5 px-3 py-1.5 text-body font-medium transition">
          <StudioIcon name="shirt" size="h-3.5 w-3.5" /> Loại trang phục ({{ types.length }})
        </button>
        <button @click="tab='questions'" :class="tab==='questions' ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'" class="flex items-center gap-1.5 px-3 py-1.5 text-body font-medium transition">
          <StudioIcon name="search" size="h-3.5 w-3.5" /> Câu hỏi ({{ questions.length }})
        </button>
      </div>
      <div class="relative min-w-[10rem] flex-1">
        <StudioIcon name="search" size="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cream-400" />
        <input v-if="tab==='types'" v-model="typeQuery" type="search" placeholder="Tìm loại trang phục…" class="input !py-2 pl-9" aria-label="Tìm loại trang phục" />
        <input v-else v-model="questionQuery" type="search" placeholder="Tìm câu hỏi theo mã hoặc nội dung…" class="input !py-2 pl-9" aria-label="Tìm câu hỏi" />
      </div>
      <button v-if="tab==='types'" @click="newType" class="btn-brand btn-sm whitespace-nowrap">
        <StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm loại
      </button>
      <button v-else @click="newQuestion" class="btn-brand btn-sm whitespace-nowrap">
        <StudioIcon name="plus" size="h-3.5 w-3.5" /> Thêm câu hỏi
      </button>
    </div>

    <!-- Thân -->
    <SettingsSkeleton v-if="loading" :rows="4" />

    <div v-else-if="loadError" class="card border-red-500/40 p-5">
      <p class="flex items-center gap-2 text-sm text-danger"><StudioIcon name="alertTriangle" size="h-4 w-4" /> {{ loadError }}</p>
      <button @click="load" class="btn-outline btn-sm mt-3">Thử lại</button>
    </div>

    <template v-else-if="tab==='types'">
      <p class="mb-3 text-body text-cream-400">
        Ảnh đại diện phục vụ tự động theo slug: <span class="text-brand-300">/garment/{slug}</span>
      </p>
      <SettingsEmpty v-if="!visibleTypes.length" :filtered="!!typeQuery.trim()" icon="shirt"
                     :title="typeQuery.trim() ? 'Không có loại nào khớp bộ lọc' : 'Chưa có loại trang phục nào'"
                     :hint="typeQuery.trim() ? 'Thử xoá từ khoá tìm kiếm.' : 'Loại trang phục là các lựa chọn Trợ lý thiết kế đưa ra khi tư vấn.'">
        <button v-if="typeQuery.trim()" @click="typeQuery=''" class="btn-outline btn-sm">Xoá bộ lọc</button>
        <button v-else @click="newType" class="btn-brand btn-sm">Thêm loại đầu tiên</button>
      </SettingsEmpty>
      <div v-else class="space-y-2">
        <div v-for="t in visibleTypes" :key="t.id || t.slug" class="flex items-center gap-3 rounded-md border border-ink-700 bg-ink-900/60 p-2.5">
          <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-ink-900 text-lg" :style="{ boxShadow: 'inset 0 0 0 1px ' + (t.color || '#4a7a90') }">
            <template v-if="t.emoji">{{ t.emoji }}</template>
            <StudioIcon v-else name="shirt" size="h-4 w-4 text-cream-400" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-xs font-semibold text-cream-100">{{ t.name }}
              <span v-if="t._local" class="ml-1 rounded bg-brand-600/30 px-1.5 py-0.5 text-tiny font-semibold text-brand-200">của bạn</span>
            </p>
            <p class="truncate text-label text-cream-400">slug: {{ t.slug }}</p>
          </div>
          <span class="h-5 w-5 shrink-0 rounded-full border border-ink-600" :style="{ background: t.color || '#4a7a90' }"></span>
          <button @click="editType(t)" class="tool-btn" :title="'Sửa ' + t.name">
            <StudioIcon name="pencil" size="h-3 w-3" /> Sửa
          </button>
          <button @click="deleteType(t)" class="tool-btn !text-danger hover:!bg-red-600/25" :title="'Xoá ' + t.name">
            <StudioIcon name="trash" size="h-3 w-3" /> Xoá
          </button>
        </div>
      </div>
    </template>

    <template v-else>
      <p class="mb-3 text-body text-cream-400">
        Câu hỏi hiển thị theo thứ tự; dùng <span class="text-brand-300">{name}</span> để chèn tên loại trang phục.
      </p>
      <SettingsEmpty v-if="!visibleQuestions.length" :filtered="!!questionQuery.trim()" icon="search"
                     :title="questionQuery.trim() ? 'Không có câu hỏi nào khớp bộ lọc' : 'Chưa có câu hỏi nào'"
                     :hint="questionQuery.trim() ? 'Thử xoá từ khoá tìm kiếm.' : 'Câu hỏi là những bước Trợ lý thiết kế hỏi bạn trước khi tư vấn.'">
        <button v-if="questionQuery.trim()" @click="questionQuery=''" class="btn-outline btn-sm">Xoá bộ lọc</button>
        <button v-else @click="newQuestion" class="btn-brand btn-sm">Thêm câu hỏi đầu tiên</button>
      </SettingsEmpty>
      <div v-else class="space-y-2">
        <div v-for="q in visibleQuestions" :key="q.id || q.key" class="rounded-md border border-ink-700 bg-ink-900/60 p-3">
          <div class="flex items-start gap-3">
            <span class="mt-0.5 shrink-0 rounded bg-ink-900 px-1.5 py-0.5 font-mono text-label text-brand-300">{{ q.key }}</span>
            <p class="min-w-0 flex-1 text-xs font-semibold text-cream-100">{{ q.q }}
              <span v-if="q._local" class="ml-1 rounded bg-brand-600/30 px-1.5 py-0.5 text-tiny font-semibold text-brand-200">của bạn</span>
            </p>
            <button @click="editQuestion(q)" class="tool-btn shrink-0" :title="'Sửa ' + q.key">
              <StudioIcon name="pencil" size="h-3 w-3" /> Sửa
            </button>
            <button @click="deleteQuestion(q)" class="tool-btn shrink-0 !text-danger hover:!bg-red-600/25" :title="'Xoá ' + q.key">
              <StudioIcon name="trash" size="h-3 w-3" /> Xoá
            </button>
          </div>
          <p class="mt-1.5 text-label text-cream-400">{{ (q.opts || []).length }} lựa chọn</p>
        </div>
      </div>
    </template>

    <!-- Biểu mẫu sửa/thêm (giữ nguyên hành vi của bản cũ, chỉ đổi phần trình bày) -->
    <div v-if="editingType" class="mt-4 rounded-lg border border-brand-500/40 bg-ink-900 p-4">
      <p class="mb-3 text-xs font-semibold text-brand-300">{{ typeForm.id ? 'Sửa loại trang phục' : 'Thêm loại trang phục' }}</p>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div><label class="label">Slug (mã)</label><input v-model="typeForm.slug" class="input !py-2" placeholder="dress"></div>
        <div><label class="label">Tên</label><input v-model="typeForm.name" class="input !py-2" placeholder="Đầm"></div>
        <div><label class="label">Emoji (không bắt buộc)</label><input v-model="typeForm.emoji" class="input !py-2" placeholder=""></div>
        <div><label class="label">Màu</label><div class="flex items-center gap-1.5"><input type="color" v-model="typeForm.color" class="h-9 w-10 cursor-pointer rounded-lg border border-ink-600 bg-ink-900" aria-label="Chọn màu"><input v-model="typeForm.color" class="input !py-2" aria-label="Mã màu"></div></div>
      </div>
      <div class="mt-3 flex justify-end gap-2">
        <button @click="cancelType" class="btn-outline btn-sm">Huỷ</button>
        <button @click="saveType" :disabled="saving" class="btn-brand btn-sm">{{ saving ? 'Đang lưu…' : 'Lưu' }}</button>
      </div>
    </div>

    <div v-if="editingQuestion" class="mt-4 rounded-lg border border-brand-500/40 bg-ink-900 p-4">
      <p class="mb-3 text-xs font-semibold text-brand-300">{{ qForm.id ? 'Sửa câu hỏi' : 'Thêm câu hỏi' }}</p>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="label">Key (mã)</label><input v-model="qForm.key" class="input !py-2" placeholder="fabric"></div>
      </div>
      <div class="mt-2"><label class="label">Câu hỏi</label><input v-model="qForm.q" class="input !py-2" placeholder="Chất liệu (kỹ thuật dệt):"></div>
      <div class="mt-2"><label class="label">Lựa chọn (mỗi dòng một lựa chọn)</label><textarea v-model="qForm.optsText" rows="5" class="input !text-xs" placeholder="Lụa satin mềm&#10;Chiffon mỏng nhẹ"></textarea></div>
      <div class="mt-3 flex justify-end gap-2">
        <button @click="cancelQuestion" class="btn-outline btn-sm">Huỷ</button>
        <button @click="saveQuestion" :disabled="saving" class="btn-brand btn-sm">{{ saving ? 'Đang lưu…' : 'Lưu' }}</button>
      </div>
    </div>

    <ConfirmDialog :model-value="!!pending" :title="pending ? pending.title : ''" :danger="!!(pending && pending.danger)"
                   :message="pending ? pending.message : ''" :detail="pending ? pending.detail : ''"
                   :confirm-label="pending && pending.confirmLabel ? pending.confirmLabel : 'Xoá'"
                   @update:model-value="pending = null" @confirm="runPending" />
  </div>
</template>
