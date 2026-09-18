<script setup>
import { ref, onMounted, computed } from 'vue';
import { useStudioStore } from '../store.js';
import BaseModal from './BaseModal.vue';
import StylistSection from './settings/StylistSection.vue';
import StudioIcon from './StudioIcon.vue';
import LoadingSpinner from './LoadingSpinner.vue';
const store = useStudioStore();
// popup=true: chỉ mount BaseModal (không render card) — dùng cho nút right-toolbar.
// open là v-model để parent (StudioApp) điều khiển mở/đóng popup.
defineProps({ popup: { type: Boolean, default: false } });
const open = defineModel({ type: Boolean, default: false });
const settingsOpen = ref(false);
const types = ref([]);
const step = ref('type'); // type | survey | result
const type = ref('');
const questions = ref([]);
const answers = ref({});   // { key: [selected opts] }
const customNotes = ref({});
const loading = ref(false);
const promptLang = ref('en');
const promptEn = ref(''), promptVi = ref('');
const typeName = computed(() => types.value.find(t => t.id === type.value)?.name || type.value);
onMounted(async () => { try { const r = await fetch('/api/stylist/types', { headers: { Accept: 'application/json' } }); if (!r.ok) throw new Error('HTTP ' + r.status); const d = await r.json(); types.value = d.types || d.items || []; } catch(e){ console.error('studio request failed', e); } });
function pickType(t) { type.value = t.id; step.value = 'survey'; answers.value = {}; customNotes.value = {}; loadCluster(); }
async function loadCluster() {
  loading.value = true;
  try { const d = await store.api('/api/stylist/cluster', { type: type.value }); questions.value = d.questions || []; questions.value.forEach(q => { if (!answers.value[q.key]) answers.value[q.key] = []; }); }
  catch(e){ store.toast(e.message || 'Lỗi tải câu hỏi.', 'error'); }
  finally { loading.value = false; }
}
function toggleOpt(key, opt) { const a = answers.value[key]; const i = a.indexOf(opt); if (i>=0) a.splice(i,1); else a.push(opt); }
function selectedCount(key) { return answers.value[key]?.length || 0; }
function buildAnswers() { const flat = {}; Object.keys(answers.value).forEach(k => { const arr = (answers.value[k]||[]).slice(); if (customNotes.value[k] && customNotes.value[k].trim()) arr.push(customNotes.value[k].trim()); if (arr.length) flat[k] = arr.join(', '); }); return flat; }
async function submitPrompt() {
  loading.value = true;
  try { const d = await store.api('/api/stylist/prompt', { type: type.value, answers: buildAnswers() }); promptEn.value = d.prompt_en; promptVi.value = d.prompt_vi; promptLang.value = 'en'; step.value = 'result'; store.imagePromptEn = d.prompt_en; }
  catch(e){ store.toast(e.message || 'Lỗi tạo prompt.', 'error'); }
  finally { loading.value = false; }
}
async function refine() {
  loading.value = true;
  try {
    const d = await store.api('/api/stylist/refine', { type: type.value, prompt_en: promptEn.value, answers: buildAnswers() });
    if (d.error) {
      // AI không khả dụng — hiển thị lỗi rõ ràng thay vì âm thầm giữ prompt cũ
      store.toast(d.error_message || 'Không thể tinh chỉnh: AI không phản hồi.', 'error');
      // Vẫn hiển thị advice nếu có (advice tĩnh dự phòng)
      if (d.advice) {
        store.toast('💡 Gợi ý: ' + d.advice.replace(/[•\-]/g, '').trim().substring(0, 200), 'info');
      }
      loading.value = false;
      return;
    }
    if (d.refined_en && d.refined_en !== promptEn.value) {
      promptEn.value = d.refined_en;
    }
    if (d.refined_vi) {
      promptVi.value = d.refined_vi;
    }
    store.toast('Đã tinh chỉnh prompt.');
    if (d.advice) {
      // Hiển thị lời khuyên chuyên gia trong toast thứ 2
      setTimeout(() => {
        store.toast('💡 ' + d.advice.replace(/[•\-]/g, '').trim().substring(0, 200), 'info');
      }, 500);
    }
  }
  catch(e){ store.toast(e.message || 'Lỗi tinh chỉnh.', 'error'); }
  finally { loading.value = false; }
}
function backToSurvey() { step.value = 'survey'; }
const shownPrompt = computed({
  get: () => promptLang.value === 'vi' ? promptVi.value : promptEn.value,
  set: (v) => { if (promptLang.value === 'vi') promptVi.value = v; else promptEn.value = v; },
});
function applyToGenerate() {
  store.imagePromptEn = promptEn.value;
  store.promptOpen = true; // mở popup Prompt Tạo Ảnh (ConceptCard)
  open.value = false;      // thoát Trợ lý thiết kế
}
function openSettings() { settingsOpen.value = true; }
</script>
<template>
  <div v-if="!popup" class="card p-5" style="background: linear-gradient(160deg, rgba(74,122,144,.14), rgba(124,58,237,.06));">
    <div class="flex items-center gap-2">
      <button @click="open=true; step='type'" class="flex min-w-0 flex-1 items-center justify-between rounded-lg border border-white/10 bg-white/5 p-3 text-left transition hover:border-brand-400">
        <span class="min-w-0 flex-1"><span class="flex items-center gap-2 text-sm font-semibold text-brand-300"><StudioIcon name="sparkles" /> Trợ lý thiết kế</span></span>
        <span class="ml-1 shrink-0 text-lg text-cream-200">›</span>
      </button>
      <button @click="openSettings" title="Quản lý data Trợ lý thiết kế" class="grid h-11 w-11 shrink-0 place-items-center rounded-lg border border-white/10 bg-white/5 text-brand-300 transition hover:border-brand-400 hover:text-brand-200"><StudioIcon name="gear" /></button>
    </div>
  </div>

    <BaseModal v-model="open" title="Trợ lý thiết kế" wide>
      <div class="p-5">
      <!-- step: type -->
      <template v-if="step === 'type'">
        <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-cream-300/60">Chọn loại trang phục</p>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
          <button v-for="t in types" :key="t.id" type="button" @click="pickType(t)"
            class="group relative aspect-square overflow-hidden rounded-md border-2 border-ink-700 bg-ink-900 transition-all duration-150 hover:border-brand-400 hover:shadow-lg hover:shadow-brand-500/15 active:scale-[0.97]">
            <img :src="t.thumb || t.img" :alt="t.name" loading="lazy" decoding="async" class="h-full w-full object-cover opacity-90 transition-transform duration-200 group-hover:scale-105">
            <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/35 to-transparent px-1.5 pb-1.5 pt-6 text-center text-[10px] font-medium leading-tight text-white">{{ t.name }}</span>
          </button>
        </div>
      </template>

      <!-- step: survey -->
      <template v-else-if="step === 'survey'">
        <div class="mb-3 flex items-center justify-between">
          <p class="text-xs font-semibold text-cream-100">{{ typeName }}</p>
          <button @click="step='type'" title="Chọn loại trang phục khác" class="btn-outline btn-sm">Đổi loại</button>
        </div>
        <LoadingSpinner v-if="loading" text="Đang tải câu hỏi…" size="sm" />
        <div v-else class="space-y-3">
          <div v-for="q in questions" :key="q.key" class="rounded-lg border border-ink-700 bg-ink-800/60 p-3">
            <p class="mb-2 text-xs font-semibold text-brand-200">{{ q.q }}</p>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="opt in q.opts" :key="opt" type="button" @click="toggleOpt(q.key, opt)" class="rounded-full border px-3 py-1.5 text-xs transition-colors" :class="answers[q.key]?.includes(opt) ? 'border-brand-400 bg-brand-500/25 text-white' : 'border-ink-600 text-cream-200 hover:border-brand-400'">{{ opt }}</button>
            </div>
            <input v-model="customNotes[q.key]" placeholder="Hoặc nhập tùy chỉnh…" class="mt-2 w-full rounded-lg border border-ink-700 bg-ink-900/60 px-3 py-1.5 text-xs text-white placeholder:text-white/40 focus:border-brand-400">
            <p v-if="selectedCount(q.key)" class="mt-1 text-[10px] text-cream-300/50">Đã chọn {{ selectedCount(q.key) }} mục</p>
          </div>
        </div>
        <button @click="submitPrompt" :disabled="loading" title="Tạo prompt thiết kế từ câu trả lời" class="btn-brand mt-3 w-full">{{ loading ? 'Đang tạo…' : 'Tạo prompt thiết kế' }}</button>
      </template>

      <!-- step: result -->
      <template v-else>
        <div class="mb-3 flex items-center justify-between">
          <div class="flex gap-1.5">
            <div class="seg w-28 shrink-0">
              <button @click="promptLang='en'" title="Hiển thị prompt tiếng Anh" :class="promptLang === 'en' ? 'is-active' : ''" class="seg-btn">EN</button>
              <button @click="promptLang='vi'" title="Hiển thị prompt tiếng Việt" :class="promptLang === 'vi' ? 'is-active' : ''" class="seg-btn">VI</button>
            </div>
          </div>
          <button @click="backToSurvey" title="Quay lại chỉnh câu trả lời" class="btn-outline btn-sm">Chỉnh lại</button>
        </div>
        <textarea v-model="shownPrompt" rows="5" class="input !text-xs"></textarea>
        <button @click="refine" :disabled="loading" title="Tinh chỉnh & nâng cấp prompt bằng AI" class="btn-outline btn-sm mt-2 w-full">{{ loading ? 'Đang tinh chỉnh…' : 'Tinh chỉnh & nâng cấp' }}</button>
        <button @click="applyToGenerate" title="Đưa prompt vào ô Prompt Tạo Ảnh" class="btn-brand mt-2 w-full">Đưa vào Tạo Ảnh</button>
      </template>
      </div>
    </BaseModal>

    <BaseModal v-model="settingsOpen" title="Quản lý data Trợ lý thiết kế" wide>
      <div class="p-5"><StylistSection /></div>
    </BaseModal>
</template>