<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useStudioStore } from '../store.js';
import BaseModal from './BaseModal.vue';
import StudioIcon from './StudioIcon.vue';
const store = useStudioStore();
const CSRF = () => {
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};
// popup=true: chỉ mount BaseModal (không render card) — dùng cho nút right-toolbar.
defineProps({ popup: { type: Boolean, default: false } });

// ── Tab navigation trong modal ──
const activeTab = ref('prompt'); // 'prompt' | 'body' | 'hair' | 'pose' | 'advanced'

// ── Sub-panels ──
const showAdvanced = ref(false);
const promptLoading = ref(false);
// Pose mẫu (kế thừa từ chip Thử đồ — tab Tư thế)
const imagePoses = ref([]);
const imagePosesLoaded = ref(false);
const showHistory = ref(false);
const showTemplates = ref(false);
const showPresets = ref(false);
const presets = ref([]);
const presetsLoading = ref(false);
const presetTypes = ref([]);
const presetType = ref('');
const showEnrich = ref(false);
const history = ref([]);
const historyLoading = ref(false);
const enrichPreview = ref('');
const enrichLoading = ref(false);
const enrichError = ref('');

// ── Character counter ──
const MAX_CHARS = 4000;
const charCount = computed(() => (store.imagePromptEn || '').length);
const charWarning = computed(() => charCount.value > MAX_CHARS * 0.85);
const charDanger = computed(() => charCount.value >= MAX_CHARS);

// ── Undo/Redo ──
const undoStack = ref([]);
const redoStack = ref([]);
const MAX_UNDO = 30;
let ignoreNextInput = false;
function pushUndo(text) {
  if (ignoreNextInput) { ignoreNextInput = false; return; }
  const last = undoStack.value[undoStack.value.length - 1];
  if (last === text) return;
  undoStack.value.push(text);
  if (undoStack.value.length > MAX_UNDO) undoStack.value.shift();
  redoStack.value = [];
}
function undo() {
  if (undoStack.value.length < 2) return;
  const current = undoStack.value.pop();
  redoStack.value.push(current);
  const prev = undoStack.value[undoStack.value.length - 1];
  ignoreNextInput = true;
  store.imagePromptEn = prev;
}
function redo() {
  if (!redoStack.value.length) return;
  const next = redoStack.value.pop();
  undoStack.value.push(next);
  ignoreNextInput = true;
  store.imagePromptEn = next;
}
function onPromptKeydown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) { e.preventDefault(); redo(); }
}
let undoPushTimer = null;
watch(() => store.imagePromptEn, (val) => {
  if (undoPushTimer) clearTimeout(undoPushTimer);
  undoPushTimer = setTimeout(() => pushUndo(val || ''), 500);
});

// ── Auto-save draft ──
const DRAFT_KEY = 'fabrikai.prompt-draft';
function saveDraft() {
  try {
    const draft = {
      prompt: store.imagePromptEn || '',
      creativeLevel: store.creativeLevel,
      texture: store.texture,
      variantCount: store.variantCount,
      imageRatio: store.imageRatio,
      imageRes: store.imageRes,
      negativePrompt: store.negativePromptEn || '',
      bodyHeight: store.bodyHeight, bodyBuild: store.bodyBuild,
      bodyWaist: store.bodyWaist, bodyShoulders: store.bodyShoulders,
      bodyHips: store.bodyHips,
      hairStyle: store.hairStyle, hairColor: store.hairColor,
      imageSeed: store.imageSeed || '',
      timestamp: Date.now()
    };
    localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
  } catch (e) { console.error('studio operation failed', e); }
}
function loadDraft() {
  try {
    const raw = localStorage.getItem(DRAFT_KEY);
    if (!raw) return false;
    const draft = JSON.parse(raw);
    if (!draft || typeof draft !== 'object') return false;
    if (typeof draft.prompt !== 'string' || !draft.prompt) return false;

    // §5.2: draft đọc từ localStorage KHÔNG được kiểm kiểu/giới hạn — dữ liệu cũ hoặc bị sửa tay
    // có thể đẩy giá trị sai (chuỗi vào ô số, tỷ lệ/độ phân giải lạ -> 422 từ server). Nay clamp
    // đúng như ô nhập.
    const num = (v, lo, hi, fallback = null) => {
      const n = Number(v);
      return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : fallback;
    };
    const str = (v, max) => (typeof v === 'string' ? v.slice(0, max) : null);

    store.imagePromptEn = draft.prompt.slice(0, 4000);

    const cl = num(draft.creativeLevel, 1, 10);
    if (cl !== null) { store.creativeLevel = cl; localCreative.value = cl; }
    const tx = num(draft.texture, 0, 10);
    if (tx !== null) { store.texture = tx; localTexture.value = tx; }
    const vc = num(draft.variantCount, 1, 4);
    if (vc !== null) { store.variantCount = vc; localVariant.value = vc; }

    const ratios = ['1:1', '4:3', '3:4', '16:9', '9:16', '4:5', '21:9', '19:6'];
    if (typeof draft.imageRatio === 'string' && ratios.includes(draft.imageRatio)) store.imageRatio = draft.imageRatio;
    // imageRes là NHÃN (1K/2K) chứ không phải số -> chỉ nhận đúng 2 giá trị hợp lệ.
    if (typeof draft.imageRes === 'string' && ['1K', '2K'].includes(draft.imageRes)) store.imageRes = draft.imageRes;

    const neg = str(draft.negativePrompt, 2000);
    if (neg) store.negativePromptEn = neg;

    const b = num(draft.bodyHeight, 1, 10); if (b !== null) store.bodyHeight = b;
    const bb = num(draft.bodyBuild, 1, 10); if (bb !== null) store.bodyBuild = bb;
    const bw = num(draft.bodyWaist, 1, 10); if (bw !== null) store.bodyWaist = bw;
    const bs = num(draft.bodyShoulders, 1, 10); if (bs !== null) store.bodyShoulders = bs;
    const bh = num(draft.bodyHips, 1, 10); if (bh !== null) store.bodyHips = bh;

    const hs = str(draft.hairStyle, 100); if (hs) store.hairStyle = hs;
    const hc = str(draft.hairColor, 100); if (hc) store.hairColor = hc;
    const seed = str(draft.imageSeed, 64); if (seed) store.imageSeed = seed;
    return true;
  } catch (e) { return false; }
}
function clearDraft() {
  try { localStorage.removeItem(DRAFT_KEY); } catch (e) { console.error('studio operation failed', e); }
}
let draftSaveTimer = null;
watch(() => [
  store.imagePromptEn, store.creativeLevel, store.texture, store.variantCount,
  store.imageRatio, store.imageRes, store.negativePromptEn,
  store.bodyHeight, store.bodyBuild, store.bodyWaist, store.bodyShoulders, store.bodyHips,
  store.hairStyle, store.hairColor
], () => {
  if (draftSaveTimer) clearTimeout(draftSaveTimer);
  draftSaveTimer = setTimeout(saveDraft, 1000);
}, { deep: true });
const origGenerate = store.generateImage.bind(store);
store.generateImage = function() { clearDraft(); return origGenerate(); };

// ── Prompt templates ──
const templates = ref([
  { id: 'studio', name: 'Chụp studio', prompt: 'professional fashion photography, studio lighting, clean background' },
  { id: 'lookbook', name: 'Lookbook', prompt: 'fashion lookbook, outdoor natural light, editorial style, model walking' },
  { id: 'detail', name: 'Cận chi tiết', prompt: 'extreme close-up, fabric texture detail, macro fashion photography' },
  { id: 'minimal', name: 'Tối giản', prompt: 'minimalist fashion, clean aesthetic, soft natural light, simple composition' },
  { id: 'luxury', name: 'Luxury', prompt: 'luxury fashion editorial, high-end magazine, dramatic lighting, premium quality' },
  { id: 'street', name: 'Street style', prompt: 'street style fashion, urban background, candid shot, natural pose' },
]);
function loadTemplates() {
  try {
    const saved = JSON.parse(localStorage.getItem('fabrikai.prompt-templates') || '[]');
    if (Array.isArray(saved) && saved.length) {
      templates.value = [...templates.value, ...saved.map((t, i) => ({ ...t, id: 'custom-' + i }))];
    }
  } catch (e) { console.error('studio operation failed', e); }
}
loadTemplates();

const promptPreview = computed(() => { const t = store.imagePromptEn || ''; return t.length > 54 ? t.slice(0, 54) + '…' : (t || 'Nhập/áp dụng prompt…'); });
const textureLabel = computed(() => {
  const t = store.texture;
  if (t <= 0) return 'Không'; if (t <= 2) return 'Mịn phẳng';
  if (t <= 4) return 'Dệt nhẹ'; if (t <= 6) return 'Rõ vừa';
  if (t <= 8) return 'Chi tiết cao'; return 'Siêu chi tiết';
});
const creditEstimate = computed(() => { const base = 1; return base * store.variantCount; });

// ── Debounce for sliders ──
const debounceTimers = {};
function debouncedSet(key, value, delay = 300) {
  if (debounceTimers[key]) clearTimeout(debounceTimers[key]);
  debounceTimers[key] = setTimeout(() => { store[key] = value; }, delay);
}
const localCreative = ref(store.creativeLevel);
const localTexture = ref(store.texture);
const localVariant = ref(store.variantCount);
const localBodyHeight = ref(store.bodyHeight);
const localBodyBuild = ref(store.bodyBuild);
const localBodyWaist = ref(store.bodyWaist);
const localBodyShoulders = ref(store.bodyShoulders);
const localBodyHips = ref(store.bodyHips);
watch(() => store.creativeLevel, (v) => { localCreative.value = v; });
watch(() => store.texture, (v) => { localTexture.value = v; });
watch(() => store.variantCount, (v) => { localVariant.value = v; });
watch(() => store.bodyHeight, (v) => { localBodyHeight.value = v; });
watch(() => store.bodyBuild, (v) => { localBodyBuild.value = v; });
watch(() => store.bodyWaist, (v) => { localBodyWaist.value = v; });
watch(() => store.bodyShoulders, (v) => { localBodyShoulders.value = v; });
watch(() => store.bodyHips, (v) => { localBodyHips.value = v; });
watch(localCreative, (v) => { debouncedSet('creativeLevel', v); });
watch(localTexture, (v) => { debouncedSet('texture', v); });
watch(localVariant, (v) => { debouncedSet('variantCount', v); });
watch(localBodyHeight, (v) => { debouncedSet('bodyHeight', v); });
watch(localBodyBuild, (v) => { debouncedSet('bodyBuild', v); });
watch(localBodyWaist, (v) => { debouncedSet('bodyWaist', v); });
watch(localBodyShoulders, (v) => { debouncedSet('bodyShoulders', v); });
watch(localBodyHips, (v) => { debouncedSet('bodyHips', v); });

// ── Two-way sync prompt_prefix/prompt_suffix với Settings ──
let _syncPrefixTimer = null;
let _syncSuffixTimer = null;
function syncPrefixToSettings(v) {
  clearTimeout(_syncPrefixTimer);
  _syncPrefixTimer = setTimeout(() => {
    fetch('/api/settings/sync-prompt', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ prompt_prefix: v }) }).catch(() => {});
  }, 800);
}
function syncSuffixToSettings(v) {
  clearTimeout(_syncSuffixTimer);
  _syncSuffixTimer = setTimeout(() => {
    fetch('/api/settings/sync-prompt', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ prompt_suffix: v }) }).catch(() => {});
  }, 800);
}
watch(() => store.promptPrefix, (v) => { if (v !== undefined) syncPrefixToSettings(v); });
watch(() => store.promptSuffix, (v) => { if (v !== undefined) syncSuffixToSettings(v); });
// ── Ghi nhớ local TOÀN BỘ cài đặt Prompt (prompt text, creative, texture, variants,
//    ratio/res, body, hair, seed, prefix/suffix/negative + checkbox) — ưu tiên hơn DB khi tải lại ──
watch(() => [
    store.imagePromptEn, store.creativeLevel, store.texture, store.variantCount,
    store.imageRatio, store.imageRes, store.imageSeed,
    store.promptPrefix, store.promptSuffix, store.negativePromptEn,
    store.promptUsePrefix, store.promptUseSuffix, store.promptUseNegative,
    store.bodyHeight, store.bodyBuild, store.bodyWaist, store.bodyShoulders, store.bodyHips,
    store.hairStyle, store.hairColor, store.imagePoseId,
  ],
  () => store.savePromptMemory(), { deep: true });

// ── Pose mẫu (kế thừa từ chip Thử đồ — load lười khi mở tab Tư thế) ──
async function loadImagePoses() {
  if (imagePosesLoaded.value) return;
  imagePosesLoaded.value = true;
  try {
    const r = await fetch('/api/swap-poses', { headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    const d = await r.json();
    if (Array.isArray(d.items)) imagePoses.value = d.items;
  } catch (e) { /* giữ mặc định */ }
}
// Watch tab switch để load poses khi vào tab Tư thế
watch(activeTab, (tab) => { if (tab === 'pose') loadImagePoses(); });

// ── Draft state ──
const showDraftNotice = ref(false);
const draftTime = ref('');

async function openPrompt() {
  if (!store.defaultsLoaded) {
    promptLoading.value = true;
    try { await store.loadDefaults(); } catch (e) { console.error('studio operation failed', e); }
    finally { promptLoading.value = false; }
  }
  localCreative.value = store.creativeLevel;
  localTexture.value = store.texture;
  localVariant.value = store.variantCount;
  localBodyHeight.value = store.bodyHeight;
  localBodyBuild.value = store.bodyBuild;
  localBodyWaist.value = store.bodyWaist;
  localBodyShoulders.value = store.bodyShoulders;
  localBodyHips.value = store.bodyHips;
  enrichPreview.value = '';
  try {
    const raw = localStorage.getItem(DRAFT_KEY);
    if (raw) {
      const draft = JSON.parse(raw);
      if (draft.prompt) {
        showDraftNotice.value = true;
        const d = new Date(draft.timestamp);
        draftTime.value = d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }) + ' ' + d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' });
      }
    }
  } catch (e) { console.error('studio operation failed', e); }
  store.promptOpen = true;
}
function restoreDraft() { loadDraft(); showDraftNotice.value = false; undoStack.value = [store.imagePromptEn || '']; redoStack.value = []; store.toast('Đã khôi phục bản nháp.'); }
function dismissDraft() { showDraftNotice.value = false; clearDraft(); }
function resetToDefaults() {
  store.clearPromptMemory(); // xóa cài đặt prompt local (prefix/suffix/negative + checkbox) trước khi reset
  store.applyDefaults();
  localCreative.value = store.creativeLevel; localTexture.value = store.texture;
  localVariant.value = store.variantCount; localBodyHeight.value = store.bodyHeight;
  localBodyBuild.value = store.bodyBuild; localBodyWaist.value = store.bodyWaist;
  localBodyShoulders.value = store.bodyShoulders; localBodyHips.value = store.bodyHips;
  clearDraft(); undoStack.value = []; redoStack.value = []; store.hairStyle = ''; store.hairColor = '';
  store.toast('Đã đặt lại về mặc định hệ thống.');
}

// ── Prompt History ──
async function loadHistory() {
  historyLoading.value = true;
  try {
    const res = await fetch('/api/prompt-history', { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const d = await res.json();
    history.value = d.items || [];
  } catch (e) { history.value = []; }
  finally { historyLoading.value = false; }
}
function applyHistory(item) {
  store.imagePromptEn = item.prompt;
  if (item.creative_level != null) { store.creativeLevel = Number(item.creative_level); localCreative.value = store.creativeLevel; }
  if (item.texture != null) { store.texture = Number(item.texture); localTexture.value = store.texture; }
  if (item.negative_prompt) store.negativePromptEn = item.negative_prompt;
  showHistory.value = false; store.toast('Đã áp dụng prompt từ lịch sử.');
}

// Chế độ chèn: 'replace' | 'append' | 'prepend'
const insertMode = ref('append');
function insertPrompt(text) {
  if (!text) return;
  const cur = (store.imagePromptEn || '').trim();
  if (insertMode.value === 'append') {
    store.imagePromptEn = cur ? cur + ', ' + text : text;
  } else if (insertMode.value === 'prepend') {
    store.imagePromptEn = cur ? text + ', ' + cur : text;
  } else {
    store.imagePromptEn = text;
  }
}
function applyTemplate(tpl) {
  insertPrompt(tpl.prompt);
  showTemplates.value = false;
  store.toast('Đã thêm template: ' + tpl.name);
}

// ── Preset ──
async function loadPresets() {
  presetsLoading.value = true;
  try {
    const tRes = await fetch('/api/stylist/types', { headers: { Accept: 'application/json' } });
    if (!tRes.ok) throw new Error('HTTP ' + tRes.status);
    const tD = await tRes.json().catch(() => ({}));
    presetTypes.value = tD.types || tD.items || [];
  } catch (e) { presetTypes.value = []; }
  try {
    const pRes = await fetch('/api/stylist/presets', { headers: { Accept: 'application/json' } });
    if (!pRes.ok) throw new Error('HTTP ' + pRes.status);
    const pD = await pRes.json().catch(() => ({}));
    presets.value = pD.presets || pD.items || [];
  } catch (e) { presets.value = []; }
  presetsLoading.value = false;
}
const filteredPresets = computed(() => {
  if (!presetType.value) return presets.value;
  return presets.value.filter((p) => p.type === presetType.value);
});
function applyPreset(p) {
  if (p.prompt) insertPrompt(p.prompt);
  showPresets.value = false;
  store.toast('Đã thêm preset: ' + (p.name || p.id));
}

// ── Live Enrich Preview ──
let enrichAbort = null;
async function openEnrichPreview() {
  if (!store.imagePromptEn?.trim()) { store.toast('Nhập prompt trước khi preview enrich.', 'error'); return; }
  showEnrich.value = true; await doEnrichPreview();
}
async function doEnrichPreview() {
  const p = store.imagePromptEn?.trim();
  if (!p) { enrichPreview.value = ''; enrichLoading.value = false; return; }
  if (enrichAbort) { enrichAbort.abort(); enrichAbort = null; }
  const controller = new AbortController(); enrichAbort = controller;
  enrichLoading.value = true; enrichError.value = '';
  try {
    const res = await fetch('/api/preview-enrich', {
      method: 'POST', signal: controller.signal,
      headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ prompt: p, creative_level: store.creativeLevel, texture: store.texture, negative_prompt: store.negativePromptEn || null, body_height: store.bodyHeight, body_build: store.bodyBuild, body_waist: store.bodyWaist, body_shoulders: store.bodyShoulders, body_hips: store.bodyHips, hair_style: store.hairStyle || '', hair_color: store.hairColor || '' })
    });
    if (!res.ok) throw new Error('Lỗi preview');
    const d = await res.json();
    if (enrichAbort === controller) {
      enrichPreview.value = d.enriched || '';
      if (d.negative_prompt) enrichPreview.value += '\n\n— Negative: ' + d.negative_prompt;
      enrichLoading.value = false; enrichAbort = null;
    }
  } catch (e) {
    if (e.name === 'AbortError') return;
    if (enrichAbort === controller) { enrichError.value = e.message; enrichLoading.value = false; enrichAbort = null; }
  }
}

// ── Kiểu tóc thời thượng (30+ kiểu) ──
const hairStyles = [
  { id: '', name: 'Không ép', emoji: '🚫' },
  // Ngắn
  { id: 'pixie cut', name: 'Pixie Cắt Ngắn', emoji: '✂️', group: 'Ngắn' },
  { id: 'bob cut', name: 'Bob Cổ Điển', emoji: '💇', group: 'Ngắn' },
  { id: 'blunt bob', name: 'Bob Cắt Bằng', emoji: '💇', group: 'Ngắn' },
  { id: 'asymmetrical bob', name: 'Bob Bất Đối Xứng', emoji: '💇', group: 'Ngắn' },
  { id: 'french bob', name: 'Bob Pháp', emoji: '🥖', group: 'Ngắn' },
  { id: 'bixie cut', name: 'Bixie (Bob+Pixie)', emoji: '✂️', group: 'Ngắn' },
  // Trung bình
  { id: 'wolf cut', name: 'Wolf Cut Layer', emoji: '🐺', group: 'Trung bình' },
  { id: 'shag cut', name: 'Shag Cut Rối', emoji: '🎸', group: 'Trung bình' },
  { id: 'butterfly cut', name: 'Butterfly Cut Bươm Bướm', emoji: '🦋', group: 'Trung bình' },
  { id: 'curtain bangs', name: 'Mái Curtain', emoji: '🎭', group: 'Trung bình' },
  { id: 'wispy bangs', name: 'Mái Thưa Wispy', emoji: '🌬️', group: 'Trung bình' },
  { id: 'layered mid-length', name: 'Layer Trung Bình', emoji: '💇', group: 'Trung bình' },
  { id: 'clavicut', name: 'Clavicut Xương Quai Xanh', emoji: '✨', group: 'Trung bình' },
  { id: 'hush cut', name: 'Hush Cut Nhẹ Nhàng', emoji: '🤫', group: 'Trung bình' },
  // Dài
  { id: 'long straight', name: 'Dài Thẳng Mượt', emoji: '👩', group: 'Dài' },
  { id: 'long layered', name: 'Dài Tỉa Layer', emoji: '💇', group: 'Dài' },
  { id: 'v-cut long', name: 'Dài Đuôi V-Cut', emoji: '🔽', group: 'Dài' },
  { id: 'u-cut long', name: 'Dài Đuôi U-Cut', emoji: '🔽', group: 'Dài' },
  { id: 'jellyfish cut', name: 'Jellyfish Cut Sứa', emoji: '🪼', group: 'Dài' },
  { id: 'long curtain bangs', name: 'Dài Mái Curtain', emoji: '🎭', group: 'Dài' },
  // Xoăn / texture
  { id: 'beach waves', name: 'Sóng Biển Beach Waves', emoji: '🌊', group: 'Xoăn/Texture' },
  { id: 'loose curls', name: 'Xoăn Lơi Tự Nhiên', emoji: '🌀', group: 'Xoăn/Texture' },
  { id: 'tight curls', name: 'Xoăn Chặt Tight Curls', emoji: '🪢', group: 'Xoăn/Texture' },
  { id: 'body wave perm', name: 'Uốn Sóng Body Wave', emoji: '🌊', group: 'Xoăn/Texture' },
  { id: 'digital perm', name: 'Uốn Digital Perm', emoji: '💻', group: 'Xoăn/Texture' },
  { id: 'water wave', name: 'Sóng Nước Water Wave', emoji: '💧', group: 'Xoăn/Texture' },
  { id: 'natural afro', name: 'Afro Tự Nhiên', emoji: '🌿', group: 'Xoăn/Texture' },
  // Búi / tết
  { id: 'sleek bun', name: 'Búi Gọn Sleek', emoji: '🎀', group: 'Búi/Tết' },
  { id: 'messy bun', name: 'Búi Rối Messy', emoji: '🎀', group: 'Búi/Tết' },
  { id: 'low ponytail', name: 'Đuôi Ngựa Thấp', emoji: '🐴', group: 'Búi/Tết' },
  { id: 'high ponytail', name: 'Đuôi Ngựa Cao', emoji: '🐴', group: 'Búi/Tết' },
  { id: 'braided crown', name: 'Tết Vương Miện', emoji: '👑', group: 'Búi/Tết' },
  { id: 'french braid', name: 'Tết Pháp', emoji: '🥖', group: 'Búi/Tết' },
  { id: 'dutch braid', name: 'Tết Hà Lan', emoji: '🇳🇱', group: 'Búi/Tết' },
  { id: 'half up half down', name: 'Nửa Buộc Nửa Thả', emoji: '🔝', group: 'Búi/Tết' },
];
const hairColors = [
  { id: '', name: 'Không ép', hex: 'transparent' },
  { id: 'jet black', name: 'Đen tuyền', hex: '#1a1a2e' },
  { id: 'natural black', name: 'Đen tự nhiên', hex: '#2c2c2c' },
  { id: 'dark brown', name: 'Nâu đậm', hex: '#3e2723' },
  { id: 'chestnut brown', name: 'Nâu hạt dẻ', hex: '#5d4037' },
  { id: 'milk tea brown', name: 'Nâu trà sữa', hex: '#8d6e63' },
  { id: 'honey blonde', name: 'Vàng mật ong', hex: '#d4a574' },
  { id: 'platinum blonde', name: 'Bạch kim', hex: '#e8d5c4' },
  { id: 'ash grey', name: 'Xám khói', hex: '#9e9e9e' },
  { id: 'silver white', name: 'Bạc trắng', hex: '#cfd8dc' },
  { id: 'burgundy', name: 'Đỏ rượu', hex: '#6d1a1a' },
  { id: 'rose gold', name: 'Hồng vàng', hex: '#b76e79' },
  { id: 'pastel pink', name: 'Hồng pastel', hex: '#f8bbd0' },
  { id: 'lavender', name: 'Tím oải hương', hex: '#b39ddb' },
  { id: 'copper', name: 'Đồng', hex: '#c75b39' },
  { id: 'auburn', name: 'Nâu đỏ', hex: '#8b4513' },
  { id: 'ombre caramel', name: 'Ombre Caramel', hex: '#c68e5b' },
  { id: 'balayage', name: 'Balayage', hex: '#d4a373' },
];
const hairGroups = computed(() => {
  const groups = new Map();
  hairStyles.forEach(h => {
    if (!h.id) return;
    const g = h.group || 'Khác';
    if (!groups.has(g)) groups.set(g, []);
    groups.get(g).push(h);
  });
  return [...groups.entries()];
});

// ── Body shape labels ──
const bodyHeightLabel = computed(() => {
  const v = localBodyHeight.value;
  if (v <= 2) return 'Rất thấp'; if (v <= 4) return 'Hơi thấp';
  if (v <= 6) return 'Trung bình'; if (v <= 8) return 'Cao';
  return 'Siêu cao';
});
const bodyBuildLabel = computed(() => {
  const v = localBodyBuild.value;
  if (v <= 2) return 'Siêu gầy'; if (v <= 4) return 'Thon gọn';
  if (v <= 6) return 'Cân đối'; if (v <= 8) return 'Đầy đặn';
  return 'Curvy';
});
const bodyWaistLabel = computed(() => {
  const v = localBodyWaist.value;
  if (v <= 2) return 'Thẳng'; if (v <= 4) return 'Ít eo';
  if (v <= 6) return 'Cân đối'; if (v <= 8) return 'Eo thon';
  return 'Đồng hồ cát';
});
const bodyShouldersLabel = computed(() => {
  const v = localBodyShoulders.value;
  if (v <= 2) return 'Rất hẹp'; if (v <= 4) return 'Hẹp';
  if (v <= 6) return 'Cân đối'; if (v <= 8) return 'Rộng';
  return 'Rất rộng';
});
const bodyHipsLabel = computed(() => {
  const v = localBodyHips.value;
  if (v <= 2) return 'Rất hẹp'; if (v <= 4) return 'Hẹp';
  if (v <= 6) return 'Cân đối'; if (v <= 8) return 'Nở';
  return 'Rất nở';
});
</script>

<template>
  <div v-if="!popup" class="card p-5" style="background: linear-gradient(160deg, rgba(124,58,237,.12), rgba(74,122,144,.06));">
    <!-- Header card: click mở modal -->
    <button v-if="!popup" @click="openPrompt" class="flex w-full items-center justify-between rounded-lg border border-white/10 bg-white/5 p-4 text-left transition hover:border-brand-400 hover:bg-white/[0.07] group">
      <span class="min-w-0 flex-1 overflow-hidden">
        <span class="flex items-center gap-2 text-sm font-semibold text-brand-300">
          <span class="flex h-7 w-7 items-center justify-center rounded-md bg-brand-500/20 text-brand-300"><StudioIcon name="sliders" size="h-4 w-4" /></span>
          Prompt Tạo Ảnh
        </span>
        <span class="mt-1 block w-full truncate text-[11px] text-ink-500">{{ promptPreview }}</span>
      </span>
      <span class="ml-2 shrink-0 text-lg text-cream-200 transition group-hover:translate-x-0.5">›</span>
    </button>

    <!-- Progress bar (hiển thị ngoài card khi đang generate) -->
    <div v-if="store.generating || store.generateProgress > 0" class="mt-3 overflow-hidden rounded-md border border-brand-500/20 bg-brand-900/20 p-3">
      <div class="mb-1.5 flex items-center justify-between text-[11px]">
        <span class="flex items-center gap-1.5 font-semibold text-brand-200">
          <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-brand-400"></span>
          {{ store.generateStage === 'preparing' ? 'Đang chuẩn bị…' : store.generateStage === 'queued' ? 'Đang xếp hàng xử lý…' : store.generateStage === 'rendering' ? 'Đang tạo ảnh…' : store.generateStage === 'failed' ? 'Tất cả ảnh đều lỗi' : 'Hoàn tất!' }}
        </span>
        <span class="font-semibold text-brand-300">{{ Math.round(store.generateProgress) }}%</span>
      </div>
      <div class="h-2 w-full overflow-hidden rounded-full bg-ink-800">
        <div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-purple-400 transition-all duration-500 ease-out" :style="{ width: store.generateProgress + '%' }"></div>
      </div>
      <p v-if="store.generateStage === 'done'" class="mt-1.5 flex items-center gap-1 text-[10px] text-emerald-300/70"><StudioIcon name="check" size="h-3 w-3" /> Đã tạo {{ store.generatedCount }} ảnh</p>
      <p v-if="store.generateStage === 'failed'" class="mt-1.5 text-[10px] text-red-400">Không ảnh nào tạo thành công — bấm "Tạo lại" hoặc kiểm tra cấu hình model/API key.</p>
    </div>
  </div>

    <!-- ===== MODAL ===== (luôn mount; hiện khi store.promptOpen — dùng chung cho card & popup) -->
    <BaseModal :model-value="store.promptOpen" @update:model-value="store.promptOpen = $event" title="Prompt Tạo Ảnh" wide height="92vh">
      <div v-if="promptLoading" class="py-16 text-center">
        <div class="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-2 border-brand-400 border-t-transparent"></div>
        <p class="text-sm text-cream-300/60">Đang tải cài đặt mặc định…</p>
      </div>
      <template v-else>
        <!-- ── Tab navigation (sticky — cố định dưới header khi cuộn nội dung) ── -->
        <div class="sticky top-0 z-10 border-b border-ink-700 bg-ink-900 px-5 py-2.5">
          <div class="seg">
            <button v-for="tab in [
              { id: 'prompt', icon: 'pencil', label: 'Prompt', tooltip: 'Nhập và chỉnh sửa prompt tạo ảnh' },
              { id: 'body', icon: 'body', label: 'Phom dáng', tooltip: 'Điều chỉnh chiều cao, vóc dáng, eo, vai, hông của người mẫu' },
              { id: 'hair', icon: 'hair', label: 'Kiểu tóc', tooltip: 'Chọn kiểu tóc và màu tóc thời thượng' },
              { id: 'pose', icon: 'pose', label: 'Tư thế', tooltip: 'Chọn tư thế người mẫu (kế thừa từ chip Thử đồ)' },
              { id: 'advanced', icon: 'gear', label: 'Nâng cao', tooltip: 'Negative prompt và các tùy chọn nâng cao' },
            ]" :key="tab.id" @click="activeTab = tab.id" :title="tab.tooltip" class="seg-btn"
              :class="activeTab === tab.id ? 'is-active' : ''">
              <StudioIcon :name="tab.icon" size="h-3.5 w-3.5" />
              <span class="hidden sm:inline">{{ tab.label }}</span>
            </button>
          </div>
        </div>

        <div class="p-5">

        <!-- ===== TAB: PROMPT ===== -->
        <div v-show="activeTab === 'prompt'" class="space-y-3">
          <!-- Top bar -->
          <div class="flex items-center gap-2">
            <button @click="showHistory = !showHistory; if (showHistory) { loadHistory(); showTemplates = false; showPresets = false }" title="Xem lại các prompt đã dùng trước đây" class="tool-btn" :class="showHistory ? 'is-active' : ''"><span class="flex items-center gap-1"><StudioIcon name="history" size="h-3.5 w-3.5" /> Lịch sử</span></button>
            <button @click="showTemplates = !showTemplates; if (showTemplates) { showHistory = false; showPresets = false }" title="Chọn mẫu prompt có sẵn để bắt đầu nhanh" class="tool-btn" :class="showTemplates ? 'is-active' : ''"><span class="flex items-center gap-1"><StudioIcon name="template" size="h-3.5 w-3.5" /> Templates</span></button>
            <button @click="showPresets = !showPresets; if (showPresets) { showHistory = false; showTemplates = false; loadPresets() }" title="Prompt đã lưu từ Trợ lý thiết kế" class="tool-btn" :class="showPresets ? 'is-active' : ''"><span class="flex items-center gap-1"><StudioIcon name="sparkles" size="h-3.5 w-3.5" /> Preset</span></button>
            <span class="ml-auto text-xs text-cream-300/40" title="Số credit ước tính cho lần tạo này">~{{ creditEstimate }} credit</span>
          </div>

          <!-- History panel -->
          <div v-if="showHistory" class="max-h-44 overflow-y-auto rounded-md border border-brand-500/30 bg-ink-800 p-2">
            <div v-if="historyLoading" class="py-3 text-center text-xs text-cream-300/50">Đang tải lịch sử…</div>
            <div v-else-if="!history.length" class="py-3 text-center text-xs text-cream-300/50">Chưa có prompt nào.</div>
            <button v-for="h in history" :key="h.id" @click="applyHistory(h)" title="Nhấn để áp dụng prompt này" class="mb-1 w-full rounded-lg border border-white/5 bg-white/5 p-2 text-left text-[11px] transition hover:border-brand-400">
              <p class="truncate text-cream-100">{{ h.prompt }}</p>
              <p class="mt-0.5 text-cream-300/40">{{ h.created_at }} · Sáng tạo {{ h.creative_level || '—' }}/10</p>
            </button>
          </div>

          <!-- Prompt textarea -->
          <div class="relative">
            <textarea v-model="store.imagePromptEn" @keydown="onPromptKeydown" rows="5" class="input !text-sm !py-3 !pr-16 !rounded-md" placeholder="Mô tả trang phục, phong cách, bối cảnh, ánh sáng… (EN hoặc VI)"></textarea>
            <div class="absolute bottom-2 right-2 flex items-center gap-1">
              <button @click="undo" :disabled="undoStack.length < 2" class="icon-btn !h-6 !w-6" title="Hoàn tác thay đổi gần nhất (Ctrl+Z)"><StudioIcon name="undo" size="h-3.5 w-3.5" /></button>
              <button @click="redo" :disabled="!redoStack.length" class="icon-btn !h-6 !w-6" title="Làm lại thay đổi đã hoàn tác (Ctrl+Y)"><StudioIcon name="redo" size="h-3.5 w-3.5" /></button>
              <span class="text-[10px] font-semibold" :class="charDanger ? 'text-red-400' : charWarning ? 'text-amber-400' : 'text-cream-300/50'" :title="'Số ký tự: ' + charCount + '/' + MAX_CHARS">{{ charCount }}/{{ MAX_CHARS }}</span>
            </div>
          </div>

          <!-- Templates popup -->
          <div v-if="showTemplates" role="dialog" aria-modal="true" aria-label="Mẫu phom dáng" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4" @click.self="showTemplates = false">
            <div class="max-h-[80vh] w-full max-w-md overflow-y-auto rounded-lg border border-brand-500/40 bg-ink-900 p-5 shadow-2xl" @click.stop>
              <div class="mb-3 flex items-center justify-between">
                <span class="flex items-center gap-2 text-sm font-semibold text-brand-300"><StudioIcon name="template" /> Prompt Templates</span>
                <button @click="showTemplates = false" class="grid h-8 w-8 place-items-center rounded-full bg-ink-700 text-cream-200 hover:text-white" title="Đóng"><StudioIcon name="x" size="h-4 w-4" /></button>
              </div>
              <!-- Chế độ chèn -->
              <div class="mb-3 flex items-center gap-1.5 text-[10px]">
                <span class="ml-1 text-cream-300/50">Chèn vào:</span>
                <div class="seg">
                  <button @click="insertMode = 'append'" :class="insertMode === 'append' ? 'is-active' : ''" class="seg-btn" title="Thêm vào cuối prompt hiện tại">Cuối</button>
                  <button @click="insertMode = 'prepend'" :class="insertMode === 'prepend' ? 'is-active' : ''" class="seg-btn" title="Thêm vào đầu prompt hiện tại">Đầu</button>
                  <button @click="insertMode = 'replace'" :class="insertMode === 'replace' ? 'is-active' : ''" class="seg-btn" title="Thay thế toàn bộ prompt hiện tại">Ghi đè</button>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <button v-for="t in templates" :key="t.id" @click="applyTemplate(t)" :title="'Nhấn để ' + (insertMode === 'replace' ? 'ghi đè' : insertMode === 'append' ? 'thêm vào cuối' : 'thêm vào đầu') + ' prompt'" class="rounded-md border border-white/10 bg-white/5 p-3 text-left transition hover:border-brand-400 hover:bg-brand-600/10">
                  <span class="block text-sm font-semibold text-cream-100">{{ t.name }}</span>
                  <span class="mt-1 block text-[11px] leading-snug text-cream-300/50 line-clamp-2">{{ t.prompt }}</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Sáng tạo + Biến thể -->
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Độ sáng tạo của AI: 1=bám sát prompt, 10=tự do sáng tạo">
              <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><StudioIcon name="sparkles" size="h-3.5 w-3.5" /> Sáng tạo</span><span class="font-semibold text-brand-300">{{ localCreative }}/10</span></p>
              <input type="range" min="1" max="10" v-model.number="localCreative" class="w-full cursor-pointer accent-brand-500">
            </div>
            <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Số lượng ảnh tạo cùng lúc (tốn thêm credit)">
              <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><StudioIcon name="grid" size="h-3.5 w-3.5" /> Biến thể</span><span class="font-semibold text-brand-300">{{ localVariant }}</span></p>
              <input type="range" min="1" max="4" step="1" v-model.number="localVariant" class="w-full cursor-pointer accent-brand-500">
            </div>
          </div>

          <!-- Model + Ratio + Resolution -->
          <div v-if="store.taskGroupModels('image').length > 1" class="flex items-center gap-2">
            <span class="shrink-0 text-[10px] font-medium text-cream-300/60">🤖</span>
            <select v-model="store.imageModelSel" class="input !py-2 !text-xs !rounded-md" title="Model tạo ảnh — danh sách từ Cài đặt → 🎯 Nhóm công việc (image)">
              <option value="">Mặc định ({{ store.taskGroupModels('image')[0]?.label || 'auto' }})</option>
              <option v-for="m in store.taskGroupModels('image')" :key="m.provider + m.model" :value="m.provider + ':' + m.model">{{ m.label }}</option>
            </select>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <select v-model="store.imageRatio" class="input !py-2.5 !text-sm !rounded-md" title="Tỷ lệ khung hình ảnh đầu ra"><option v-for="r in ['1:1','4:3','3:4','9:16','16:9','4:5','21:9']" :key="r" :value="r">{{ r }}</option></select>
            <select v-model="store.imageRes" class="input !py-2.5 !text-sm !rounded-md" title="Độ phân giải ảnh đầu ra"><option value="1K">1K</option><option value="2K">2K</option></select>
          </div>

          <!-- Texture + Gieo quẻ (Seed) -->
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Mức độ chi tiết chất liệu vải hiển thị trong ảnh">
              <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><StudioIcon name="layers" size="h-3.5 w-3.5" /> Texture</span><span class="font-semibold text-brand-300">{{ textureLabel }}</span></p>
              <input type="range" min="0" max="10" step="1" v-model.number="localTexture" class="w-full cursor-pointer accent-brand-500">
            </div>
            <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Seed cố định để tạo ảnh nhất quán. Để trống = random.">
              <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><span class="text-sm">🎲</span> Gieo quẻ</span></p>
              <div class="flex gap-1">
                <input v-model="store.imageSeed" type="text" inputmode="numeric" class="input !text-xs !py-1.5 !rounded-lg flex-1" placeholder="Seed..." title="Nhập số seed để tạo ảnh nhất quán">
                <button @click="store.imageSeed = String(Math.floor(Math.random() * 2147483647) + 1)" class="shrink-0 rounded-lg bg-brand-600 px-2 py-1 text-xs text-white hover:bg-brand-500 transition" title="Tạo seed ngẫu nhiên">🎲</button>
              </div>
            </div>
          </div>

          <!-- Enrich Preview -->
          <button @click="openEnrichPreview" class="flex w-full items-center justify-center gap-1.5 rounded-md border border-ink-600 bg-ink-800 px-4 py-2.5 text-xs font-semibold text-cream-200 transition hover:border-brand-400 hover:bg-brand-600/10" title="Xem trước prompt sau khi được làm giàu bởi AI">
            <StudioIcon name="wand" size="h-3.5 w-3.5" /> Preview Enrich Prompt
          </button>
        </div>

        <!-- ===== TAB: PHOM DÁNG ===== -->
        <div v-show="activeTab === 'body'" class="space-y-3">
          <div class="rounded-lg border border-purple-500/20 bg-purple-900/10 p-3">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-purple-200"><StudioIcon name="body" size="h-3.5 w-3.5" /> Điều chỉnh phom dáng người mẫu</p>
            <p class="mt-0.5 text-[10px] text-purple-300/50">Để ở mức 5 (trung bình) nếu không muốn ép phom dáng cụ thể.</p>
          </div>

          <!-- Chiều cao -->
          <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Điều chỉnh chiều cao người mẫu từ rất thấp đến siêu cao">
            <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><StudioIcon name="height" size="h-3.5 w-3.5" /> Chiều cao</span><span class="font-semibold text-brand-300">{{ bodyHeightLabel }}</span></p>
            <input type="range" min="1" max="10" step="1" v-model.number="localBodyHeight" class="w-full cursor-pointer accent-purple-400">
            <div class="mt-1 flex justify-between text-[9px] text-cream-300/40"><span>1.5m</span><span>1.65m</span><span>1.80m+</span></div>
          </div>

          <!-- Độ gầy/béo -->
          <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Điều chỉnh vóc dáng từ siêu gầy đến đầy đặn/curvy">
            <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><StudioIcon name="body" size="h-3.5 w-3.5" /> Vóc dáng</span><span class="font-semibold text-brand-300">{{ bodyBuildLabel }}</span></p>
            <input type="range" min="1" max="10" step="1" v-model.number="localBodyBuild" class="w-full cursor-pointer accent-purple-400">
            <div class="mt-1 flex justify-between text-[9px] text-cream-300/40"><span>Siêu gầy</span><span>Cân đối</span><span>Curvy</span></div>
          </div>

          <!-- Eo -->
          <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Điều chỉnh vòng eo từ thẳng đến đồng hồ cát thon gọn">
            <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><StudioIcon name="waist" size="h-3.5 w-3.5" /> Eo</span><span class="font-semibold text-brand-300">{{ bodyWaistLabel }}</span></p>
            <input type="range" min="1" max="10" step="1" v-model.number="localBodyWaist" class="w-full cursor-pointer accent-purple-400">
            <div class="mt-1 flex justify-between text-[9px] text-cream-300/40"><span>Thẳng</span><span>Cân đối</span><span>Đồng hồ cát</span></div>
          </div>

          <!-- Vai -->
          <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Điều chỉnh độ rộng vai từ hẹp đến rộng">
            <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><StudioIcon name="shoulder" size="h-3.5 w-3.5" /> Vai</span><span class="font-semibold text-brand-300">{{ bodyShouldersLabel }}</span></p>
            <input type="range" min="1" max="10" step="1" v-model.number="localBodyShoulders" class="w-full cursor-pointer accent-purple-400">
            <div class="mt-1 flex justify-between text-[9px] text-cream-300/40"><span>Hẹp</span><span>Cân đối</span><span>Rộng</span></div>
          </div>

          <!-- Hông -->
          <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 px-4 py-3" title="Điều chỉnh độ nở hông từ hẹp đến rất nở">
            <p class="mb-1 flex items-center justify-between text-xs"><span class="flex items-center gap-1.5 font-medium text-cream-200"><StudioIcon name="hip" size="h-3.5 w-3.5" /> Hông</span><span class="font-semibold text-brand-300">{{ bodyHipsLabel }}</span></p>
            <input type="range" min="1" max="10" step="1" v-model.number="localBodyHips" class="w-full cursor-pointer accent-purple-400">
            <div class="mt-1 flex justify-between text-[9px] text-cream-300/40"><span>Hẹp</span><span>Cân đối</span><span>Nở</span></div>
          </div>
        </div>

        <!-- ===== TAB: KIỂU TÓC ===== -->
        <div v-show="activeTab === 'hair'" class="space-y-3">
          <div class="rounded-lg border border-pink-500/20 bg-pink-900/10 p-3">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-pink-200"><StudioIcon name="hair" size="h-3.5 w-3.5" /> Kiểu tóc & Màu tóc</p>
            <p class="mt-0.5 text-[10px] text-pink-300/50">Chọn kiểu tóc thời thượng và màu tóc. Để trống nếu không muốn ép.</p>
          </div>

          <!-- Kiểu tóc grid -->
          <div class="space-y-2.5">
            <div v-for="[groupName, styles] in hairGroups" :key="groupName">
              <p class="mb-1.5 text-[10px] font-semibold text-cream-300/50">{{ groupName }}</p>
              <div class="flex flex-wrap gap-1.5">
                <button v-for="h in styles" :key="h.id" @click="store.hairStyle = store.hairStyle === h.id ? '' : h.id" :title="'Chọn kiểu tóc ' + h.name + (store.hairStyle === h.id ? ' (đang chọn — nhấn để bỏ)' : '')" class="rounded-lg px-3 py-1.5 text-[11px] font-semibold transition"
                  :class="store.hairStyle === h.id ? 'bg-pink-600 text-white shadow-lg shadow-pink-600/30' : 'bg-ink-800 text-cream-200 hover:bg-ink-700 border border-ink-700'">
                  <span class="mr-1">{{ h.emoji }}</span>{{ h.name }}
                </button>
              </div>
            </div>
          </div>

          <!-- Màu tóc -->
          <div class="mt-3">
            <p class="mb-2 text-[10px] font-semibold text-cream-300/50">Màu tóc</p>
            <div class="flex flex-wrap gap-2">
              <button v-for="c in hairColors" :key="c.id" @click="store.hairColor = store.hairColor === c.id ? '' : c.id" :title="'Chọn màu tóc ' + c.name + (store.hairColor === c.id ? ' (đang chọn — nhấn để bỏ)' : '')" class="flex items-center gap-1.5 rounded-lg px-3 py-2 text-[11px] font-semibold transition"
                :class="store.hairColor === c.id ? 'bg-pink-600 text-white shadow-lg shadow-pink-600/30' : 'bg-ink-800 text-cream-200 hover:bg-ink-700 border border-ink-700'">
                <span v-if="c.hex !== 'transparent'" class="inline-block h-3.5 w-3.5 rounded-full border border-white/20" :style="{ background: c.hex }"></span>
                {{ c.name }}
              </button>
            </div>
          </div>
        </div>

        <!-- ===== TAB: TƯ THẾ (kế thừa từ chip Thử đồ) ===== -->
        <div v-show="activeTab === 'pose'" class="space-y-3">
          <div class="rounded-lg border border-emerald-500/20 bg-emerald-900/10 p-3">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-emerald-200"><StudioIcon name="pose" size="h-3.5 w-3.5" /> Tư thế người mẫu</p>
            <p class="mt-0.5 text-[10px] text-emerald-300/50">Kế thừa từ chip Thử đồ. AI sẽ đọc ảnh pose để tạo mô tả tư thế. Để trống = AI tự chọn.</p>
          </div>
          <div v-if="!imagePosesLoaded" class="py-4 text-center"><div class="mx-auto h-5 w-5 animate-spin rounded-full border-2 border-emerald-400 border-t-transparent"></div></div>
          <div v-else class="grid grid-cols-2 gap-1.5">
            <button v-for="p in imagePoses" :key="p.id" @click="store.imagePoseId = (String(store.imagePoseId) === String(p.id)) ? '' : String(p.id)"
                    :class="String(store.imagePoseId) === String(p.id) ? 'border-emerald-400 bg-emerald-600/25 ring-1 ring-emerald-400/40' : 'border-ink-700 bg-ink-800 hover:border-emerald-400/50'"
                    class="flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-[10px] font-semibold text-cream-200 transition">
              <img v-if="p.thumb || p.image" :src="p.thumb || p.image" loading="lazy" class="h-9 w-9 shrink-0 rounded-lg object-cover ring-1 ring-white/20">
              <span v-else class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-ink-700 text-sm">🧍</span>
              <span class="truncate">{{ p.name }}</span>
            </button>
            <span v-if="!imagePoses.length" class="col-span-2 text-[10px] text-cream-300/50">Chưa có pose mẫu — để trống để AI tự chọn.</span>
          </div>
        </div>

        <!-- ===== TAB: NÂNG CAO ===== -->
        <div v-show="activeTab === 'advanced'" class="space-y-3">
          <!-- Prompt Prefix (đồng bộ từ Settings) -->
          <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 p-4" :class="!store.promptUsePrefix ? 'opacity-60' : ''">
            <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-cream-200">
              <button type="button" @click="store.promptUsePrefix = !store.promptUsePrefix" class="grid h-4 w-4 shrink-0 place-items-center rounded border transition-colors" :class="store.promptUsePrefix ? 'border-brand-400 bg-brand-500/40 text-white' : 'border-ink-600 text-transparent'" :title="store.promptUsePrefix ? 'Đang dùng Prompt Prefix — bấm để tắt' : 'Không dùng Prompt Prefix — bấm để bật'" :aria-label="store.promptUsePrefix ? 'Tắt dùng Prompt Prefix' : 'Bật dùng Prompt Prefix'"><StudioIcon name="check" size="h-3 w-3" :class="store.promptUsePrefix ? '' : 'opacity-0'" /></button>
              <StudioIcon name="arrowRight" size="h-3.5 w-3.5" /> Prompt Prefix (tự động thêm vào đầu)
            </label>
            <p class="mb-2 text-[10px] text-cream-300/50">Đồng bộ 2 chiều với <a href="/settings" target="_blank" class="text-brand-400 underline">Cài đặt Studio</a>. Để trống = dùng mặc định.</p>
            <textarea v-model="store.promptPrefix" rows="8" :disabled="!store.promptUsePrefix" class="input !text-sm !py-2 !rounded-md disabled:opacity-50" placeholder="High-fashion editorial photograph, professional fashion photography" title="Tự động ghép vào ĐẦU prompt khi tạo ảnh"></textarea>
          </div>
          <!-- Prompt Suffix (đồng bộ từ Settings) -->
          <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 p-4" :class="!store.promptUseSuffix ? 'opacity-60' : ''">
            <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-cream-200">
              <button type="button" @click="store.promptUseSuffix = !store.promptUseSuffix" class="grid h-4 w-4 shrink-0 place-items-center rounded border transition-colors" :class="store.promptUseSuffix ? 'border-brand-400 bg-brand-500/40 text-white' : 'border-ink-600 text-transparent'" :title="store.promptUseSuffix ? 'Đang dùng Prompt Suffix — bấm để tắt' : 'Không dùng Prompt Suffix — bấm để bật'" :aria-label="store.promptUseSuffix ? 'Tắt dùng Prompt Suffix' : 'Bật dùng Prompt Suffix'"><StudioIcon name="check" size="h-3 w-3" :class="store.promptUseSuffix ? '' : 'opacity-0'" /></button>
              <StudioIcon name="arrowLeft" size="h-3.5 w-3.5" /> Prompt Suffix (tự động thêm vào cuối)
            </label>
            <p class="mb-2 text-[10px] text-cream-300/50">Đồng bộ 2 chiều với <a href="/settings" target="_blank" class="text-brand-400 underline">Cài đặt Studio</a>. Để trống = dùng mặc định.</p>
            <textarea v-model="store.promptSuffix" rows="4" :disabled="!store.promptUseSuffix" class="input !text-sm !py-2 !rounded-md disabled:opacity-50" placeholder="soft diffused studio lighting, clean minimal background, ultra detailed, 4k, sharp focus" title="Tự động ghép vào CUỐI prompt khi tạo ảnh"></textarea>
          </div>
          <!-- Negative prompt -->
          <div class="rounded-lg border border-ink-700 bg-gradient-to-br from-ink-800 to-ink-800/70 p-4" :class="!store.promptUseNegative ? 'opacity-60' : ''">
            <label class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold text-cream-200">
              <button type="button" @click="store.promptUseNegative = !store.promptUseNegative" class="grid h-4 w-4 shrink-0 place-items-center rounded border transition-colors" :class="store.promptUseNegative ? 'border-brand-400 bg-brand-500/40 text-white' : 'border-ink-600 text-transparent'" :title="store.promptUseNegative ? 'Đang dùng Negative Prompt — bấm để tắt' : 'Không dùng Negative Prompt — bấm để bật'" :aria-label="store.promptUseNegative ? 'Tắt dùng Negative Prompt' : 'Bật dùng Negative Prompt'"><StudioIcon name="check" size="h-3 w-3" :class="store.promptUseNegative ? '' : 'opacity-0'" /></button>
              <StudioIcon name="x" size="h-3.5 w-3.5" /> Negative Prompt
            </label>
            <p class="mb-2 text-[10px] text-cream-300/50">Điều model KHÔNG nên tạo. Để trống sẽ dùng mặc định từ Cài đặt.</p>
            <textarea v-model="store.negativePromptEn" rows="4" :disabled="!store.promptUseNegative" class="input !text-sm !py-2 !rounded-md disabled:opacity-50" placeholder="blurry, low quality, distorted proportions, extra limbs, deformed hands, watermark, text, logo..." title="Nhập các yếu tố bạn muốn AI tránh tạo ra trong ảnh"></textarea>
          </div>


          <!-- Enrich Preview trong tab nâng cao -->
          <button @click="openEnrichPreview" class="flex w-full items-center justify-center gap-1.5 rounded-md border border-ink-600 bg-ink-800 px-4 py-2.5 text-xs font-semibold text-cream-200 transition hover:border-brand-400 hover:bg-brand-600/10" title="Xem trước prompt sau khi được làm giàu bởi AI">
            <StudioIcon name="wand" size="h-3.5 w-3.5" /> Preview Enrich Prompt
          </button>
        </div>

        <!-- Enrich Preview popup -->
        <div v-if="showEnrich" role="dialog" aria-modal="true" aria-label="Xem trước prompt đã làm giàu" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4" @click.self="showEnrich = false">
          <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-emerald-500/40 bg-ink-900 p-5 shadow-2xl" @click.stop>
            <div class="mb-3 flex items-center justify-between">
              <span class="flex items-center gap-2 text-sm font-semibold text-emerald-300"><StudioIcon name="wand" /> Prompt Enrich Preview</span>
              <div class="flex items-center gap-2">
                <button @click="doEnrichPreview" :disabled="enrichLoading" class="rounded-full bg-ink-700 px-3 py-1 text-[10px] text-cream-200 hover:bg-brand-600" title="Tải lại bản xem trước prompt đã làm giàu">{{ enrichLoading ? 'Đang xử lý…' : 'Làm mới' }}</button>
                <button @click="showEnrich = false" class="grid h-8 w-8 place-items-center rounded-full bg-ink-700 text-cream-200 hover:text-white" title="Đóng"><StudioIcon name="x" size="h-4 w-4" /></button>
              </div>
            </div>
            <div class="mb-3 rounded-md border border-ink-600 bg-ink-800 p-3">
              <p class="mb-1 text-[10px] text-cream-300/40">Prompt gốc:</p>
              <p class="text-xs leading-relaxed text-cream-200 whitespace-pre-wrap">{{ store.imagePromptEn || '(chưa nhập prompt)' }}</p>
            </div>
            <div class="rounded-md border border-emerald-500/30 bg-emerald-900/20 p-3">
              <p class="mb-1 text-[10px] text-emerald-300/60">Prompt sau khi enrich (gửi lên model):</p>
              <div v-if="enrichLoading" class="py-4 text-center"><div class="mx-auto h-5 w-5 animate-spin rounded-full border-2 border-brand-400 border-t-transparent"></div></div>
              <div v-else-if="enrichError" class="text-xs text-red-300/60">{{ enrichError }}</div>
              <p v-else-if="enrichPreview" class="max-h-60 overflow-y-auto text-xs leading-relaxed text-emerald-100 whitespace-pre-wrap">{{ enrichPreview }}</p>
              <p v-else class="text-xs text-cream-300/40">Bấm "Làm mới" để xem prompt đã enrich.</p>
            </div>
            <div class="mt-3 grid grid-cols-4 gap-2 text-[10px] text-cream-300/40">
              <span>Sáng tạo: {{ store.creativeLevel }}/10</span><span>Texture: {{ textureLabel }}</span>
              <span>Ratio: {{ store.imageRatio }}</span><span>Res: {{ store.imageRes }}</span>
            </div>
          </div>
        </div>

        <!-- Preset popup -->
        <div v-if="showPresets" role="dialog" aria-modal="true" aria-label="Preset — Prompt đã lưu" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/70 p-4" @click.self="showPresets = false">
          <div class="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-lg border border-brand-500/40 bg-ink-900 p-5 shadow-2xl" @click.stop>
            <div class="mb-3 flex items-center justify-between">
              <span class="flex items-center gap-2 text-sm font-semibold text-brand-300"><StudioIcon name="sparkles" /> Preset — Prompt đã lưu</span>
              <button @click="showPresets = false" class="grid h-8 w-8 place-items-center rounded-full bg-ink-700 text-cream-200 hover:text-white" title="Đóng"><StudioIcon name="x" size="h-4 w-4" /></button>
            </div>
            <!-- Chế độ chèn cho preset -->
            <div class="mb-3 flex items-center gap-1.5 text-[10px]">
              <span class="ml-1 text-cream-300/50">Chèn vào:</span>
              <div class="seg">
                <button @click="insertMode = 'append'" :class="insertMode === 'append' ? 'is-active' : ''" class="seg-btn" title="Thêm vào cuối prompt hiện tại">Cuối</button>
                <button @click="insertMode = 'prepend'" :class="insertMode === 'prepend' ? 'is-active' : ''" class="seg-btn" title="Thêm vào đầu prompt hiện tại">Đầu</button>
                <button @click="insertMode = 'replace'" :class="insertMode === 'replace' ? 'is-active' : ''" class="seg-btn" title="Thay thế toàn bộ prompt hiện tại">Ghi đè</button>
              </div>
            </div>
            <div class="mb-3 flex flex-wrap gap-1.5">
              <button @click="presetType = ''" :class="presetType === '' ? 'is-active' : ''" class="tool-btn" title="Hiển thị tất cả loại preset">Tất cả</button>
              <button v-for="t in presetTypes" :key="t.id" @click="presetType = t.id" :class="presetType === t.id ? 'is-active' : ''" class="tool-btn" :title="'Lọc preset loại ' + t.name">{{ t.emoji }} {{ t.name }}</button>
            </div>
            <p v-if="presetsLoading" class="py-6 text-center text-xs text-cream-300/60">Đang tải preset…</p>
            <div v-else-if="!filteredPresets.length" class="py-6 text-center text-xs text-cream-300/50">Chưa có preset.</div>
            <div v-else class="space-y-2">
              <button v-for="p in filteredPresets" :key="p.id" @click="applyPreset(p)" :title="'Nhấn để ' + (insertMode === 'replace' ? 'ghi đè' : insertMode === 'append' ? 'thêm vào cuối' : 'thêm vào đầu') + ' prompt'" class="w-full rounded-md border border-white/10 bg-white/5 p-3 text-left transition hover:border-brand-400 hover:bg-brand-600/10">
                <span class="block text-sm font-semibold text-cream-100">{{ p.name }}</span>
                <span class="mt-1 block text-[11px] leading-snug text-cream-300/50 line-clamp-2">{{ p.prompt }}</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Draft restore notice -->
        <div v-if="showDraftNotice" class="my-3 rounded-md border border-amber-500/30 bg-amber-900/20 p-2.5">
          <div class="flex items-center justify-between">
            <p class="flex items-center gap-1.5 text-[11px] text-amber-300/80" title="Bạn có một bản nháp chưa gửi từ lần làm việc trước"><StudioIcon name="pencil" size="h-3.5 w-3.5" /> Có bản nháp từ lúc {{ draftTime }}</p>
            <div class="flex gap-1.5">
              <button @click="restoreDraft" class="rounded-lg bg-amber-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-amber-500" title="Khôi phục toàn bộ cài đặt và prompt từ bản nháp">Khôi phục</button>
              <button @click="dismissDraft" class="rounded-lg bg-ink-700 px-2 py-1 text-[10px] text-cream-200 hover:bg-red-600" title="Xóa bản nháp và bắt đầu mới">Bỏ qua</button>
            </div>
          </div>
        </div>

        <!-- Reset + Generate (fixed bottom) -->
        <div class="sticky bottom-0 z-10 -mx-1 mt-4 border-t border-ink-700/50 bg-ink-900/95 backdrop-blur-sm px-1 pb-1 pt-3">
          <div class="flex items-center justify-between mb-2">
            <button @click="resetToDefaults" class="text-xs text-cream-300/50 underline hover:text-brand-300" title="Đưa tất cả cài đặt về mặc định hệ thống">Đặt lại mặc định</button>
          </div>
          <button @click="store.promptOpen = false; store.generateImage()" :disabled="store.generating || !store.imagePromptEn" class="btn-brand w-full whitespace-nowrap !py-3.5 !text-sm !rounded-md !font-bold tracking-wide"
            :title="store.generating ? 'Đang tạo ảnh, vui lòng chờ…' : !store.imagePromptEn ? 'Vui lòng nhập prompt trước' : 'Gửi prompt và tạo ảnh'">
            <span v-if="store.generating" class="flex items-center justify-center gap-2">
              <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              Đang tạo ảnh…
            </span>
            <span v-else class="flex items-center justify-center gap-2">
              <StudioIcon name="zap" size="h-4 w-4" /> Tạo Ảnh
            </span>
          </button>
        </div>
        </div>
      </template>
    </BaseModal>
</template>