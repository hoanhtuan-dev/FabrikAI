<script setup>
/**
 * Agent Studio — luồng ba bước: đọc tín hiệu → định hướng → thực thi (Canvas).
 *
 * Ba bước rõ ràng:
 *   1. Tín hiệu  — đọc radar, lọc, chọn trend.
 *   2. Định hướng — prompt + trend → brief, mood board, cấu trúc, phối, size, giá.
 *   3. Thực thi  — Canvas Kit: prompt, tỉ lệ, biến thể, negative, chi phí → tạo ảnh / tạo bộ sưu tập.
 *
 * Nguyên tắc:
 *   · một workspace lớn thay vì popup chật, rail tiến trình + vùng nội dung + action bar,
 *   · tab/step theo .seg/.seg-btn, a11y đầy đủ (tablist/tabpanel/aria-live/role=alert),
 *   · nhãn tiếng Việt thống nhất; gắn nhãn rõ cái nào là tin thật, cái nào là bộ có sẵn,
 *   · trạng thái brief cũ, tìm/lọc trend, copy prompt/màu, bộ đếm ký tự, Ctrl+Enter,
 *   · không tự đổi resolution hay ghi đè negative prompt người dùng đã đặt.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { useStudioStore } from '../store.js';
import BaseModal from './BaseModal.vue';
import StudioIcon from './StudioIcon.vue';
import SourceLibraryPicker from './SourceLibraryPicker.vue';
// Đọc bảng dán từ Excel nằm ở MODULE RIÊNG để kiểm được bằng máy (scripts/check-shop-paste.mjs) —
// logic tiền nằm trong file .vue thì không test nào chạm tới, và đó đúng là cách lỗi cũ lọt qua.
import { parseShopRows } from '../shopPaste.js';
import AgentDnaStep from './agents/AgentDnaStep.vue';
import AgentRadarStep from './agents/AgentRadarStep.vue';
import AgentBriefStep from './agents/AgentBriefStep.vue';
import AgentCanvasStep from './agents/AgentCanvasStep.vue';

const store = useStudioStore();
const prompt = ref('');
const promptInput = ref(null);
const collectionError = ref('');
// Đang tạo vỏ dự án từ brief — khoá nút để bấm nhanh hai lần không sinh hai dự án.
const creatingCollection = ref(false);
const trendQuery = ref('');
const trendCategory = ref('all');
/** Chỉ hiện hướng có tin thật (mặc định TẮT: người mới vẫn thấy đủ bộ hướng để bắt đầu). */
const liveOnly = ref(false);
const lifecycleFilter = ref('all');
const sizePreset = ref('standard');
const briefTab = ref('overview');
const canvasLang = ref('vi');
const canvas = ref({ ratio: '4:5', variantCount: 2, useNegative: true, negativePrompt: '' });

const STEPS = [
  // Bước 0 — DNA: khai "shop tôi là ai" TRƯỚC khi đọc xu hướng. Trước đây DNA do hệ thống ĐOÁN
  // (đếm dự án + dò từ khoá trong prompt, không có gì thì dùng câu mặc định cứng) và chủ shop không
  // có chỗ nào để sửa — nay là bước đầu tiên, có thể sửa, và mọi brief sau đều dùng bản họ khai.
  { id: 'dna', label: 'DNA shop', hint: 'Khai định vị, khách hàng, phong cách để agent viết đúng', icon: 'sparkles' },
  { id: 'radar', label: 'Tín hiệu', hint: 'Đọc xu hướng và chọn hướng đi', icon: 'scan' },
  { id: 'brief', label: 'Định hướng', hint: 'Dựng brief, mood board và cấu trúc', icon: 'briefcase' },
  { id: 'canvas', label: 'Thực thi', hint: 'Chốt prompt và tạo ảnh', icon: 'wand' },
];
const BRIEF_TABS = [
  { id: 'overview', label: 'Tổng quan' },
  { id: 'money', label: 'Sản xuất & lãi' },
  { id: 'moodboard', label: 'Mood board' },
  { id: 'structure', label: 'Cấu trúc' },
  { id: 'fit', label: 'Phối & size' },
];
/**
 * Đơn giá & định mức của kế hoạch sản xuất — CHỦ XƯỞNG TỰ NHẬP. Đây là điểm khác biệt cốt lõi:
 * con số tiền phải do người bỏ vốn kiểm soát, không phải do model đoán.
 */
const PLAN_FIELDS = [
  { key: 'units_per_sku', label: 'Số lượng mỗi mã', suffix: 'cái', hint: 'Mỗi SKU dự kiến sản xuất bao nhiêu cái.' },
  { key: 'fabric_price_per_m', label: 'Giá vải', suffix: 'đ/m', hint: 'Giá mét vải theo khổ đang dùng.' },
  { key: 'fabric_width_cm', label: 'Khổ vải', suffix: 'cm', hint: 'Khổ vải dùng để tính định mức.' },
  { key: 'wastage_pct', label: 'Tiêu hao khi cắt', suffix: '%', hint: 'Hao hụt khi trải vải và cắt.' },
  { key: 'fabric_safety_pct', label: 'Đặt dư vải', suffix: '%', hint: 'Dự phòng đầu khúc, canh sợi.' },
  { key: 'trim_cost', label: 'Phụ liệu', suffix: 'đ/cái', hint: 'Khoá, chỉ, bo, dựng, nhãn…' },
  { key: 'sewing_cost', label: 'Giá công may', suffix: 'đ/cái', hint: 'Tiền công xưởng cho mỗi cái.' },
  { key: 'packaging_cost', label: 'Bao bì', suffix: 'đ/cái', hint: 'Túi, tag, hộp.' },
  { key: 'defect_pct', label: 'Tỉ lệ lỗi', suffix: '%', hint: 'Phải làm lại — cộng thẳng vào giá vốn.' },
  { key: 'channel_discount_pct', label: 'Chiết khấu kênh', suffix: '%', hint: 'Sàn/affiliate/CTV ăn bao nhiêu trên giá bán.' },
  { key: 'target_margin_pct', label: 'Lãi mong muốn', suffix: '%', hint: 'Trên giá vốn, để tính giá bán gợi ý.' },
  { key: 'daily_capacity', label: 'Năng lực xưởng', suffix: 'cái/ngày', hint: 'Để tính số ngày ra hàng mỗi đợt.' },
  { key: 'fixed_cost', label: 'Chi phí cố định', suffix: 'đ', hint: 'Rập, mẫu, chụp ảnh… cho cả bộ sưu tập.' },
];
const SIZE_PRESETS = [
  { id: 'standard', label: 'Chuẩn S–XL', values: { S: 20, M: 35, L: 30, XL: 15 } },
  { id: 'women', label: 'Nữ ưu tiên S–M', values: { XS: 10, S: 30, M: 35, L: 20, XL: 5 } },
  { id: 'unisex', label: 'Unisex', values: { S: 25, M: 35, L: 30, XL: 10 } },
];
const RATIO_OPTIONS = ['1:1', '4:5', '3:4', '9:16', '4:3'];
const CATEGORY_LABELS = { color: 'Màu sắc', silhouette: 'Dáng', fabric: 'Chất liệu', price: 'Giá', detail: 'Chi tiết' };
const LIFECYCLE_LABELS = { emerging: 'Mới nổi', peak: 'Đang đỉnh', declining: 'Giảm dần' };

// ── DNA thương hiệu: hồ sơ chủ shop tự khai (bước 0) ──────────────────────────────────────────
const dna = computed(() => store.brandDna || null);
const dnaDraft = computed(() => store.brandDnaDraft || {});
const DNA_LISTS = [
  { key: 'styles', label: 'Phong cách', hint: 'VD: tối giản, thanh lịch, công sở' },
  { key: 'colors', label: 'Màu chủ đạo', hint: 'VD: trắng ngà, be, xanh rêu' },
  { key: 'categories', label: 'Nhóm hàng chính', hint: 'VD: đầm linen, áo sơ mi, quần tây' },
  { key: 'materials', label: 'Chất liệu', hint: 'VD: linen, cotton, lụa' },
  { key: 'avoid', label: 'KHÔNG làm', hint: 'VD: hàng bóng, họa tiết to, giá dưới 200k' },
];
const dnaListMax = (key) => Number(store.brandDna?.limits?.lists?.[key]?.[0] || 8);
const dnaListText = (key) => (Array.isArray(dnaDraft.value[key]) ? dnaDraft.value[key].join(', ') : '');
function setDnaText(field, value) { store.brandDnaDraft = { ...dnaDraft.value, [field]: value }; }
/** Ô danh sách nhập bằng dấu phẩy: gọn cho người dùng, nhưng vẫn tôn trọng trần số mục của máy chủ. */
function setDnaList(key, value) {
  const items = String(value)
    .split(',')
    .map((part) => part.trim())
    .filter((part) => part !== '')
    .slice(0, dnaListMax(key));
  store.brandDnaDraft = { ...dnaDraft.value, [key]: items };
}
const dnaDirty = computed(() => JSON.stringify(dnaDraft.value) !== JSON.stringify(dna.value?.dna || {}));

// ── VAI ĐỌC ẢNH: ảnh mẫu để AI bám phong cách (tối đa 3) ──────────────────────────────────────
const refPickerOpen = ref(false);
const refImages = computed(() => store.briefReferenceImages || []);
function onPickReference(item) {
  const url = String((item && (item.url || item.media_url)) || '');
  if (!url || refImages.value.includes(url) || refImages.value.length >= 3) return;
  store.briefReferenceImages = [...refImages.value, url];
}
function removeReference(url) {
  store.briefReferenceImages = refImages.value.filter((u) => u !== url);
}
/** Câu mô tả kết quả vai đọc ảnh — chỉ hiện khi brief vừa chạy và AI THỰC SỰ đã nhìn ảnh. */
const referenceNote = computed(() => {
  const row = collection.value?.reference_style;
  return row && row.used ? String(row.note || '') : '';
});
// ── Nguồn dữ liệu: nhãn TIẾNG NGƯỜI DÙNG (không để chữ kỹ thuật trong template) ──────────────
/** Đang có tin thật để AI đọc? (máy chủ tự lấy, không phải model tự tìm kiếm) */
const liveSources = computed(() => !!(store.webSources && store.webSources.mode === 'live'));
/** Số hướng đang được ĐO từ tin thật (khác hướng của bộ có sẵn) — hiện trên chip lọc. */
const liveTrendCount = computed(() => trends.value.filter((trend) => trend.evidence_mode === 'live').length);
const newsItems = computed(() => (store.webSources?.items || []).slice(0, 6));
const activeSourceCount = computed(() => ((store.webSources?.sources || []).filter((row) => row.ok)).length);
const fetchedAtLabel = computed(() => {
  const at = store.webSources?.fetched_at;
  if (!at) return '';
  try { return new Date(at).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; }
});
const shortDate = (iso) => {
  if (!iso) return '';
  try { return new Date(iso).toLocaleDateString('vi-VN'); } catch (e) { return ''; }
};
const refreshLabel = computed(() => (store.webSourcesLoading ? 'Đang lấy…' : 'Cập nhật tin'));
/** Lịch chạy nền của máy chủ có thật đang chạy không — server ĐO rồi trả về, giao diện không hứa hộ. */
const autoRefresh = computed(() => store.webSources?.auto_refresh || {
  alive: false,
  label: 'Chưa xác định được lịch làm mới tin của máy chủ.',
});

// ── TÍN HIỆU THỊ TRƯỜNG ĐO TỪ TIN THẬT (2026-09-23) ─────────────────────────────
// Đây là phần trả lời "model không có tìm kiếm web thì lấy đâu ra dữ liệu": máy chủ lấy tin, rồi ĐO bằng
// thuật toán (từ khoá · số tin · tăng/giảm · dải giá) — không cần model nào chạy.
const market = computed(() => radar.value?.market || store.webSources?.market || collection.value?.market || null);
const marketLive = computed(() => (market.value?.mode || 'empty') === 'live');
const marketSignals = computed(() => market.value?.signals || []);
/**
 * CHỦ ĐỀ đọc từ chính tin (cụm từ lặp lại trong tiêu đề/mô tả) — KHÁC từ khoá ngành: đây là thứ báo chí
 * đang nói, không phải danh mục tôi khai sẵn. Hiện riêng để người dùng thấy phần "phân tích từ nguồn".
 */
const marketTopics = computed(() => market.value?.topics || []);
const marketPrices = computed(() => market.value?.prices || null);
const marketAgeLabel = computed(() => {
  const minutes = Number(market.value?.age_minutes);
  if (!Number.isFinite(minutes)) return '';
  if (minutes < 60) return minutes + ' phút trước';
  if (minutes < 60 * 24) return Math.round(minutes / 60) + ' giờ trước';
  return Math.round(minutes / (60 * 24)) + ' ngày trước';
});
/** Tin làm căn cứ cho MỘT tín hiệu (tối đa 2, để tự kiểm chứng con số). */
const signalSamples = (signal) => (Array.isArray(signal?.samples) ? signal.samples.slice(0, 2) : []);
/** Câu mô tả con số của một hướng: hướng có tin nói bằng SỐ ĐO, hướng còn lại nói rõ là bộ có sẵn. */
const trendSignalLabel = (trend) => {
  if (trend?.evidence_mode !== 'live') {
    return 'Đà tăng ' + (trend?.momentum ?? 0) + '/100 · ' + formatNumber(trend?.evidence_count) + ' bằng chứng của bộ có sẵn';
  }
  const live = trend.live || {};
  const parts = [(live.mentions || 0) + ' tin thật nhắc tới', (live.source_count || 0) + ' nguồn'];
  if (live.change_pct === null || live.change_pct === undefined) parts.push('lần đo đầu tiên');
  else parts.push((live.change_pct >= 0 ? 'tăng ' : 'giảm ') + Math.abs(live.change_pct) + '% so với lần đo trước');
  return parts.join(' · ');
};
/** Radar đọc lúc nào + phần chữ do đâu — ĐỌC TỪ server (methodology), không chép tay lại một câu gần giống. */
const radarMethodLine = computed(() => String(radar.value?.methodology?.ai_reasoning || ''));
const radarReadAt = computed(() => {
  const at = radar.value?.generated_at;
  if (!at) return '';
  try { return new Date(at).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }); } catch (e) { return ''; }
});
/**
 * Trạng thái một nguồn, nói ĐÚNG việc đang xảy ra — năm mức chứ không phải hai.
 * Chỉ đọc ok/không-ok thì nguồn bị bỏ qua vì khác vùng hiện lên thành "Không lấy được" (bịa lỗi).
 */
const sourceStatus = (row) => row?.state_label || (row?.ok ? 'Đang dùng' : 'Không lấy được');
const sourceStatusTone = (row) => (row?.ok ? 'text-ok' : (row?.state === 'skipped' ? 'text-cream-400' : 'text-warn'));
/** Khả năng truy cập internet: câu kết luận ĐO ĐƯỢC + các nhóm công việc chưa chạy được. */
const internetVerdict = computed(() => String(store.webAccess?.verdict_label || ''));
const groupsNeedingSetup = computed(() => (store.webAccess?.task_groups || []).filter((row) => !row.configured || row.needs_key));
/** Nhãn tuổi của bản brief lấy từ bộ đệm — để người dùng biết có nên bấm "Chạy lại bằng AI" không. */
const cacheAgeLabel = computed(() => {
  const age = Number(collection.value?.model?.cache_age_s);
  if (!Number.isFinite(age) || age < 0) return 'trước đó';
  if (age < 60) return 'cách đây ' + age + ' giây';
  return 'cách đây ' + Math.round(age / 60) + ' phút';
});
/**
 * Vì sao nút "Lưu DNA" đang bị khoá — MỘT nguồn cho cả điều kiện khoá lẫn câu giải thích
 * (docs/DESIGN_SYSTEM.md §4 quy tắc 4): hai chỗ viết riêng thì sớm muộn lệch nhau.
 * Cờ đang-chạy không tính là lý do (nhãn nút đã đổi thành "Đang lưu…").
 */
const dnaBlockReason = computed(() => {
  if (store.brandDnaSaving) return '';
  if (!dnaDirty.value) return 'Chưa có thay đổi nào để lưu.';
  return '';
});
const dnaEmpty = computed(() => !dna.value?.is_set);

const step = computed({
  get: () => store.designAgentStep || 'radar',
  set: (value) => store.setDesignAgentStep(value),
});
const stepIndex = computed(() => Math.max(0, STEPS.findIndex((item) => item.id === step.value)));
const radar = computed(() => store.trendRadar);
const collection = computed(() => store.collectionBrief);
const regions = computed(() => (radar.value?.regions?.length ? radar.value.regions : [
  { id: 'all', name: 'Toàn quốc' },
  { id: 'hcm', name: 'TP.HCM' },
  { id: 'hanoi', name: 'Hà Nội' },
  { id: 'danang', name: 'Đà Nẵng' },
]));
const selectedRegion = computed({
  get: () => radar.value?.region || 'all',
  set: (region) => { store.selectedTrendIds = []; loadRadar(region); },
});
const trends = computed(() => radar.value?.trends || []);
const sources = computed(() => radar.value?.sources || []);
const sourceMode = computed(() => radar.value?.source_mode || 'demo');
/**
 * Bốn ô số liệu đầu màn hình. Thứ tự CÓ CHỦ Ý: số ĐO ĐƯỢC từ tin thật lên trước, số của bộ có sẵn và số
 * của chính tài khoản xuống sau — trước đây ô đầu là "Thuộc tính theo dõi: 5" (một hằng số cứng) và ô
 * "Ảnh phân tích mỗi tháng: 0", tức là hai ô vô nghĩa chiếm chỗ của số thật.
 */
const summaryItems = computed(() => {
  const summary = radar.value?.summary || {};
  const order = ['live_sources', 'market_signals', 'active_trends', 'internal_products', 'internal_generations', 'tracked_attributes', 'images_analyzed_monthly'];
  const labels = {
    live_sources: 'Nguồn tin đang dùng',
    market_signals: 'Từ khoá từ tin thật',
    active_trends: 'Hướng đang theo dõi',
    internal_products: 'Sản phẩm của bạn',
    internal_generations: 'Ảnh bạn đã tạo',
    tracked_attributes: 'Thuộc tính theo dõi',
    images_analyzed_monthly: 'Ảnh phân tích mỗi tháng',
  };
  const live = new Set(['live_sources', 'market_signals']);
  return order
    .filter((key) => key in summary)
    .map((key) => ({ key, label: labels[key] || key, value: summary[key], fromNews: live.has(key) }));
});
const selectedTrendIds = computed(() => (store.selectedTrendIds || []).map(String));
const selectedTrendCount = computed(() => selectedTrendIds.value.length);
const selectedTrendObjects = computed(() => {
  const ids = new Set(selectedTrendIds.value);
  return trends.value.filter((trend) => ids.has(String(trend.id)));
});
const trendCategories = computed(() => {
  const counts = new Map();
  trends.value.forEach((trend) => {
    const id = String(trend.category || 'other');
    counts.set(id, (counts.get(id) || 0) + 1);
  });
  return [...counts.entries()].map(([id, count]) => ({ id, count, label: CATEGORY_LABELS[id] || id }));
});
const lifecycles = computed(() => ['emerging', 'peak', 'declining'].map((id) => ({
  id,
  label: LIFECYCLE_LABELS[id],
  count: trends.value.filter((trend) => trend.lifecycle === id).length,
})).filter((row) => row.count > 0));
const visibleTrends = computed(() => {
  const query = trendQuery.value.trim().toLowerCase();
  return trends.value.filter((trend) => {
    // Lọc "chỉ hướng có tin thật": người dùng nối nguồn xong vẫn thấy 8 thẻ bộ có sẵn là cảm giác
    // "phân tích không dùng dữ liệu của tôi" — chip này cho họ nhìn đúng phần dữ liệu thật.
    if (liveOnly.value && trend.evidence_mode !== 'live') return false;
    if (trendCategory.value !== 'all' && String(trend.category || '') !== trendCategory.value) return false;
    if (lifecycleFilter.value !== 'all' && String(trend.lifecycle || '') !== lifecycleFilter.value) return false;
    if (!query) return true;
    return [trend.title, trend.description, trend.recommended_action, trend.category]
      .map((value) => String(value || '').toLowerCase()).join(' ').includes(query);
  });
});
const palette = computed(() => collection.value?.palette || []);
const moodboardItems = computed(() => collection.value?.moodboard?.items || []);
const categoryRows = computed(() => collection.value?.structure?.categories || []);
const outfitRows = computed(() => collection.value?.outfit_matching || []);
const sizeRows = computed(() => collection.value?.size_distribution || []);
const priceBand = computed(() => collection.value?.price_bands || null);
const canvasSettings = computed(() => collection.value?.canvas || null);
const sizeDistribution = computed(() => (SIZE_PRESETS.find((item) => item.id === sizePreset.value) || SIZE_PRESETS[0]).values);
const currentBriefInput = computed(() => ({
  prompt: prompt.value.trim(),
  region: selectedRegion.value,
  trend_ids: selectedTrendIds.value,
  // [BUG ĐÃ SỬA] size_distribution PHẢI nằm trong input so sánh: thiếu nó thì brief vừa tạo LUÔN bị
  // coi là "đã cũ" (bảng size được ghi vào brief khi tạo, nhưng chỗ kiểm tra lại không đưa vào so sánh)
  // ⇒ người dùng chưa đổi gì vẫn thấy "Prompt/trend đã đổi. Bấm «Tạo lại brief»".
  size_distribution: sizeDistribution.value,
}));
const briefStale = computed(() => store.collectionBriefStale(currentBriefInput.value));
const canvasPrompt = computed(() => {
  if (!collection.value) return prompt.value.trim();
  return canvasLang.value === 'en'
    ? (collection.value.prompt_en || collection.value.canvas?.prompt_en || '')
    : (collection.value.prompt_vi || collection.value.canvas?.prompt_vi || '');
});
const estimatedCredits = computed(() => Math.max(1, Number(store.planCostImage) || 1) * Math.max(1, Number(canvas.value.variantCount) || 1));
const readiness = computed(() => ({
  // DNA: 'done' khi chủ shop ĐÃ khai — không có DNA thì vẫn 'ready' (không chặn đường), chỉ là chưa xong.
  dna: dna.value?.is_set ? 'done' : 'ready',
  radar: radar.value ? 'done' : (store.trendRadarLoading ? 'loading' : 'ready'),
  brief: collection.value && !briefStale.value ? 'done' : (radar.value ? 'ready' : 'locked'),
  canvas: collection.value && !briefStale.value ? 'ready' : 'locked',
}));

// ── NHẬN BIẾT MODEL: hai agent chạy bằng AI hay bằng engine tất định? ──────────
// Backend trả khối `model` (mode/provider/model/candidates/latency/reason) để giao diện nói
// THẬT đang dùng gì — trước đây Agent Studio chạy thuần rule-based và không hề cho biết điều đó.
// Câu hiển thị cho NGƯỜI DÙNG: nói TRẠNG THÁI, không nêu model/khoá/nhóm công việc
// (docs/DESIGN_SYSTEM.md §6). Chi tiết kỹ thuật vẫn nằm trong payload trả về và trong log.
const MODEL_REASON_LABELS = {
  no_model_key: 'Phần suy luận AI chưa được bật nên hai agent đang chạy bằng bộ quy tắc có sẵn.',
  model_error: 'AI không phản hồi — đã tự chuyển sang bộ quy tắc có sẵn (kết quả vẫn đầy đủ).',
  invalid_output: 'AI trả về dữ liệu không dùng được — đã tự chuyển sang bộ quy tắc có sẵn.',
  ai_disabled: 'Bạn đang tắt suy luận AI nên hai agent chạy bằng bộ quy tắc có sẵn.',
};
const activeModel = computed(() => collection.value?.model || radar.value?.model || null);
const modelReady = computed(() => activeModel.value?.mode === 'ai');
const modelShort = computed(() => {
  const m = activeModel.value;
  if (!m) return 'AI: đang kiểm tra…';
  if (m.mode === 'ai') return 'Có suy luận AI';
  return 'Bộ quy tắc có sẵn';
});
const modelTitle = computed(() => {
  const m = activeModel.value;
  if (!m) return 'Chưa có thông tin — mở bước Tín hiệu để đọc radar.';
  if (m.mode === 'ai') {
    return 'Phần định hướng do AI viết trên dữ liệu mẫu ở trên'
      + (m.cached ? ' · kết quả lấy từ lần phân tích gần nhất' : '');
  }
  return MODEL_REASON_LABELS[m.reason] || 'Đang chạy bằng bộ quy tắc có sẵn.';
});
const modelCandidates = computed(() => activeModel.value?.available || []);
const aiToggleTitle = computed(() => (store.designAgentAi
  ? 'Đang BẬT: mỗi lần đọc radar/tạo brief sẽ nhờ AI phân tích.'
  : 'Đang TẮT: chỉ dùng bộ quy tắc có sẵn, không gọi AI.'));
const directions = computed(() => radar.value?.directions || []);
const appliedAi = computed(() => {
  const a = collection.value?.ai_applied;
  if (!a) return [];
  const rows = [];
  if (a.narrative) rows.push('DNA thương hiệu');
  if (a.brief) rows.push('brief');
  if (a.moodboard_captions) rows.push(a.moodboard_captions + ' caption mood board');
  if (a.category_rationale) rows.push('lý do cơ cấu danh mục');
  if (a.outfit_goals) rows.push('mục tiêu phối đồ');
  if (a.prompts) rows.push('prompt ảnh');
  if (a.next_steps) rows.push('bước tiếp theo');
  return rows;
});
/** Brief hiện tại được dựng ở chế độ khác với công tắc AI hiện tại? */
const briefModeMismatch = computed(() => {
  const mode = collection.value?.model?.mode;
  if (mode !== 'ai' && mode !== 'rule') return false;
  return mode !== (store.designAgentAi ? 'ai' : 'rule');
});
function directionConfidence(value) {
  return value == null ? null : Math.round(Number(value) * 100);
}
function directionPriceLabel(value) {
  return { entry: 'Entry', mid: 'Mid-range', premium: 'Premium' }[value] || null;
}
function trendNameById(id) {
  const found = trends.value.find((trend) => String(trend.id) === String(id));
  return found ? trendTitle(found) : String(id);
}
/** Chọn nhanh các trend mà 3 định hướng mạnh nhất đang nhắc tới. */
function focusDirections() {
  const ids = new Set();
  const ranked = directions.value.filter((row) => row.source === 'ai');
  (ranked.length ? ranked : directions.value).slice(0, 3)
    .forEach((row) => (row.trend_ids || []).forEach((id) => ids.add(String(id))));
  if (!ids.size) return;
  store.selectedTrendIds = Array.from(ids).slice(0, 6);
}
function toggleAi() {
  store.setDesignAgentAi(!store.designAgentAi);
  loadRadar(selectedRegion.value, { force: true });
}

// ── KẾ HOẠCH SẢN XUẤT & LỢI NHUẬN ────────────────────────────────────────────
const plan = computed(() => store.plan);
const planTotals = computed(() => plan.value?.totals || null);
const planWaves = computed(() => plan.value?.waves || []);
const planLines = computed(() => plan.value?.cut_lines || []);
const planSizeChart = computed(() => plan.value?.size_chart || []);
const planScenarios = computed(() => plan.value?.selling || []);
const planInput = computed(() => ({
  prompt: prompt.value.trim(),
  region: selectedRegion.value,
  trend_ids: selectedTrendIds.value,
  size_distribution: sizeDistribution.value,
}));
let planTimer = null;
/** Tính lại kế hoạch (gộp nhiều lần gõ số liên tiếp thành 1 lượt gọi — backend tất định, rất nhanh). */
function schedulePlan() {
  if (planTimer) clearTimeout(planTimer);
  planTimer = setTimeout(() => { loadPlanNow(); }, 500);
}
async function loadPlanNow() {
  if (prompt.value.trim().length < 3) {
    store.planError = 'Nhập prompt bộ sưu tập (tối thiểu 3 ký tự) trước khi lập kế hoạch sản xuất.';
    return;
  }
  try { await store.loadPlan(planInput.value); } catch (error) { /* store giữ lỗi */ }
}
function planFieldValue(key) { return store.planAssumptions[key]; }

// ── DỮ LIỆU BÁN HÀNG THẬT CỦA SHOP ──────────────────────────────────────────
const shopPaste = ref('');
const shopParseError = ref('');
const shopOpen = ref(false);
const shopSummary = computed(() => store.shopSignal || radar.value?.internal_brand_signal?.shop || null);
const shopRowCount = computed(() => (store.shopRows || []).filter((row) => String(row.name || '').trim()).length);

function importShopPaste() {
  shopParseError.value = '';
  const rows = parseShopRows(shopPaste.value);
  if (!rows.length) {
    shopParseError.value = 'Không đọc được dòng nào. Mỗi dòng theo thứ tự: Tên, Nhóm, Đã bán, Tồn, Đổi trả, Giá bán.';
    return;
  }
  store.shopRows = [...store.shopRows, ...rows].slice(0, 200);
  shopPaste.value = '';
  store.toast('Đã thêm ' + rows.length + ' dòng nháp — kiểm tra lại rồi bấm «Lưu dữ liệu shop».');
  // Giá bán đọc sai (0đ hoặc dưới 1.000đ) làm dải giá và cả kế hoạch sản xuất ra số vô nghĩa — nói NGAY,
  // thay vì để chủ xưởng phát hiện lúc bảng kịch bản bán trống.
  const badPrice = rows.filter((row) => !row.price_vnd || row.price_vnd < 1000).length;
  if (badPrice) {
    shopParseError.value = badPrice + ' dòng có giá bán trống hoặc quá nhỏ (dưới 1.000đ). Kiểm tra lại cột Giá bán — nếu để 0 thì dải giá và kế hoạch sản xuất sẽ không dùng được.';
  }
}
function addShopRow() {
  if (store.shopRows.length >= 200) { store.toast('Tối đa 200 dòng dữ liệu shop.', 'error'); return; }
  store.shopRows = [...store.shopRows, { name: '', category: '', units_sold: 0, stock_on_hand: 0, returns: 0, price_vnd: 0, period_days: 30, source: 'manual' }];
}
function removeShopRow(index) { store.shopRows = store.shopRows.filter((_, i) => i !== index); }
async function saveShop() {
  const rows = (store.shopRows || []).filter((row) => String(row.name || '').trim());
  await store.saveShopSignals(rows, 'manual');
  await loadRadar(selectedRegion.value, { force: true });
  if (collection.value && !briefStale.value) await createBrief();
}

// ── XUẤT FILE CHO THỢ CẮT / XƯỞNG ────────────────────────────────────────────
function csvCell(value) {
  const text = String(value ?? '');
  return /[",;\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
}
function downloadCsv(filename, rows) {
  // BOM UTF-8 để Excel trên Windows hiển thị đúng tiếng Việt.
  const csv = '\uFEFF' + rows.map((row) => row.map(csvCell).join(',')).join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
  store.toast('Đã tải ' + filename + '.');
}
function exportCutSheet() {
  if (!plan.value) return;
  const rows = [
    ['LỆNH CẮT — ' + (collection.value?.project_payload?.name || 'bộ sưu tập')],
    ['Nhóm hàng', 'Size', 'Số lượng', 'Vải/cái (m)', 'Tổng vải (m)', 'Giá vốn/cái', 'Giá bán', 'Lãi/cái', '% lãi'],
  ];
  planLines.value.forEach((line) => rows.push([
    line.category, line.size, line.qty, line.fabric_m_per_unit, line.fabric_m_total,
    line.unit_cost, line.sell_price_vnd, line.profit_unit, line.margin_pct,
  ]));
  rows.push([]);
  rows.push(['TỔNG', '', planTotals.value.units, '', planTotals.value.fabric_order_m, planTotals.value.avg_unit_cost_vnd, '', planTotals.value.avg_profit_unit_vnd, planTotals.value.margin_pct]);
  rows.push(['Vải cần đặt (m)', planTotals.value.fabric_order_m, 'Vốn cần', planTotals.value.capital_needed_vnd, 'Lãi gộp (chưa trừ chi phí cố định)', planTotals.value.profit_vnd]);
  if (planTotals.value.fixed_cost_vnd) rows.push(['Chi phí cố định', planTotals.value.fixed_cost_vnd, '', '', 'Lãi sau chi phí cố định', planTotals.value.profit_after_fixed_vnd]);
  planWaves.value.forEach((wave) => rows.push([wave.name, wave.share_pct + '%', wave.units, '', wave.fabric_order_m, wave.cost_vnd, wave.revenue_vnd, wave.profit_vnd, wave.days + ' ngày']));
  downloadCsv('lenh-cat-' + Date.now() + '.csv', rows);
}
function exportSizeChart() {
  if (!plan.value || !planSizeChart.value.length) return;
  const rows = [['BẢNG SIZE (cm) — đối chiếu với rập thật của xưởng']];
  planSizeChart.value.forEach((chart) => {
    rows.push([]);
    rows.push([chart.category]);
    const measures = Object.keys(chart.rows[0]?.measures || {});
    rows.push(['Size', 'Số cái', ...measures]);
    chart.rows.forEach((row) => rows.push([row.size, row.units_planned, ...measures.map((m) => row.measures[m])]));
  });
  downloadCsv('bang-size-' + Date.now() + '.csv', rows);
}
function copyCutSheet() {
  if (!plan.value) return;
  const lines = planLines.value.map((line) => [
    line.category + ' · ' + line.size + ': ' + line.qty + ' cái',
    line.fabric_m_total + 'm vải',
    'vốn ' + formatVnd(line.unit_cost) + '/cái',
    'bán ' + formatVnd(line.sell_price_vnd),
  ].join(' — '));
  copyText([
    'LỆNH CẮT — ' + (collection.value?.project_payload?.name || ''),
    ...lines,
    'Tổng: ' + planTotals.value.units + ' cái · vải cần đặt ' + planTotals.value.fabric_order_m + 'm · vốn ' + formatVnd(planTotals.value.capital_needed_vnd) + ' · lãi gộp (chưa trừ chi phí cố định) ' + formatVnd(planTotals.value.profit_vnd),
  ].join('\n'), 'lệnh cắt');
}

function setStep(id) {
  store.setDesignAgentStep(id);
  if (id === 'radar' && !store.trendRadar) loadRadar(selectedRegion.value);
}

async function loadRadar(region = selectedRegion.value || 'all', options = {}) {
  try { await store.loadTrendRadar(region, options); } catch (error) { /* store giữ lỗi */ }
}

function toggleTrend(id) {
  const value = String(id);
  const ids = new Set(selectedTrendIds.value);
  if (ids.has(value)) ids.delete(value); else ids.add(value);
  store.selectedTrendIds = Array.from(ids);
}
function clearTrends() { store.selectedTrendIds = []; }
function selectSuggested() {
  store.selectedTrendIds = [...trends.value].sort((a, b) => (b.momentum || 0) - (a.momentum || 0)).slice(0, 3).map((trend) => String(trend.id));
}
function trendTitle(trend) { return trend?.title || trend?.id || 'Xu hướng'; }
function categoryLabel(value) { return CATEGORY_LABELS[value] || value || 'Khác'; }
function lifecycleLabel(value) { return LIFECYCLE_LABELS[value] || value || 'Đang theo dõi'; }
function lifecycleClass(value) {
  if (value === 'emerging') return 'text-ok';
  if (value === 'peak') return 'text-brand-300';
  return 'text-cream-400';
}
function formatNumber(value) {
  const number = Number(value);
  return Number.isFinite(number) ? new Intl.NumberFormat('vi-VN').format(number) : '—';
}
function formatVnd(value) {
  const number = Number(value);
  return Number.isFinite(number)
    ? new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 }).format(number)
    : '—';
}

/** @param {{force?: boolean}} opts  force = bỏ qua bộ đệm (nút "Tạo lại") */
async function createBrief(opts = {}) {
  collectionError.value = '';
  const value = prompt.value.trim();
  if (value.length < 3) {
    collectionError.value = 'Vui lòng nhập prompt tiếng Việt tối thiểu 3 ký tự.';
    return false;
  }
  try {
    await store.createCollectionBrief({
      prompt: value,
      region: selectedRegion.value,
      trend_ids: selectedTrendIds.value,
      size_distribution: sizeDistribution.value,
    }, { force: !!opts.force });
    return true;
  } catch (error) {
    collectionError.value = store.collectionBriefError || error.message || 'Không tạo được brief bộ sưu tập.';
    return false;
  }
}

async function advance() {
  // Bước DNA không chặn đường: người dùng có thể chưa khai gì mà vẫn đọc xu hướng (khi đó hệ thống
  // nói rõ đang dùng phần SUY RA). Chỉ nhắc một lần để họ biết có chỗ khai.
  if (step.value === 'dna') {
    if (dnaDirty.value && dna.value?.is_set === false) {
      store.toast('Chưa lưu DNA — phần phân tích sẽ dùng dữ liệu suy ra từ tài khoản của bạn.', 'info');
    }
    setStep('radar');
    if (!store.trendRadar) await loadRadar(selectedRegion.value);
    return;
  }
  if (step.value === 'radar') {
    setStep('brief');
    await nextTick();
    promptInput.value?.focus();
    return;
  }
  if (step.value === 'brief') {
    if (!collection.value || briefStale.value) {
      const ok = await createBrief();
      if (!ok) return;
    }
    setStep('canvas');
    return;
  }
  applyCanvas();
}
function back() {
  if (step.value === 'canvas') setStep('brief');
  else if (step.value === 'brief') setStep('radar');
  else if (step.value === 'radar') setStep('dna');
}
function applyCanvas() {
  const value = canvasPrompt.value;
  if (!value) {
    collectionError.value = 'Chưa có prompt để áp dụng vào Canvas.';
    return;
  }
  store.applyAgentPrompt(value, {
    ratio: canvas.value.ratio,
    variant_count: canvas.value.variantCount,
    negative_prompt: canvas.value.useNegative ? canvas.value.negativePrompt : '',
  });
}
async function createCollection() {
  const payload = collection.value?.project_payload;
  if (!payload) {
    collectionError.value = 'Chưa có dữ liệu bộ sưu tập để tạo.';
    return;
  }
  if (creatingCollection.value) return;   // chống bấm hai lần ⇒ hai dự án trùng
  creatingCollection.value = true;
  try {
    await store.createCollectionFromBrief(payload);
  } finally {
    creatingCollection.value = false;
  }
}
async function copyText(value, label) {
  const text = String(value || '').trim();
  if (!text) { store.toast('Không có nội dung để sao chép.', 'error'); return; }
  try { await navigator.clipboard.writeText(text); store.toast('Đã sao chép ' + label + '.'); }
  catch (error) { store.toast('Không sao chép được — hãy chọn và sao chép thủ công.', 'error'); }
}

// ── PHÍM TẮT (2026-09-24) ──────────────────────────────────────────────────────────────
// Agent Studio là workspace nhiều bước — phím tắt điều hướng nhanh, không phá a11y:
//   · Ctrl/Cmd + → / ←   chuyển bước tới/lui (dùng modifier để KHÔNG đụng mũi tên điều hướng con trỏ)
//   · phím 1–4            nhảy thẳng tới bước (chỉ khi KHÔNG đang gõ trong ô nhập)
//   · Ctrl/Cmd + Enter    trong ô prompt = chốt/tiếp tục bước hiện tại (gọi advance)
function isTypingTarget(target) {
  if (!target) return false;
  const tag = String(target.tagName || '').toUpperCase();
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
}
function agentKeydown(event) {
  if (!store.designAgentOpen) return;
  const mod = event.ctrlKey || event.metaKey;
  const typing = isTypingTarget(event.target);
  if (mod && event.key === 'ArrowRight') { event.preventDefault(); advance(); return; }
  if (mod && event.key === 'ArrowLeft') { event.preventDefault(); back(); return; }
  // Ctrl+Enter khi KHÔNG gõ trong ô prompt = tiếp tục bước (advance). Trong ô prompt, chính textarea
  // đã có @keydown.ctrl.enter="createBrief" nên phím này tạo brief mà KHÔNG nhảy bước — có chủ đích.
  if (mod && event.key === 'Enter' && !typing) { event.preventDefault(); advance(); return; }
  if (!mod && !typing && /^[1-4]$/.test(event.key)) {
    const id = STEPS[Number(event.key) - 1]?.id;
    if (id) { event.preventDefault(); setStep(id); }
  }
}
onMounted(() => window.addEventListener('keydown', agentKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', agentKeydown));

// ── LƯU NHÁP BỀN (2026-09-24) ─────────────────────────────────────────────────────────────
// Prompt · bước đang mở · trend đã chọn · size preset KHÔNG mất khi tải lại trang / đóng modal.
// Agent Studio là "trợ thủ" thì người dùng không được mất công sức vì lỡ F5 hay máy tự reload.
const DRAFT_KEY = 'fabrikai.agentStudio.draft';
function saveAgentDraft() {
  try {
    localStorage.setItem(DRAFT_KEY, JSON.stringify({
      prompt: prompt.value,
      step: store.designAgentStep || 'dna',
      sizePreset: sizePreset.value,
      selectedTrendIds: (store.selectedTrendIds || []).slice(),
    }));
  } catch (e) { /* chế độ riêng tư / đầy bộ nhớ — không làm hỏng luồng chính */ }
}
function restoreAgentDraft() {
  try {
    const d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null');
    if (!d) return;
    if (typeof d.prompt === 'string') prompt.value = d.prompt;
    if (d.step && STEPS.some((s) => s.id === d.step)) store.setDesignAgentStep(d.step);
    if (d.sizePreset && SIZE_PRESETS.some((s) => s.id === d.sizePreset)) sizePreset.value = d.sizePreset;
    if (Array.isArray(d.selectedTrendIds)) store.selectedTrendIds = d.selectedTrendIds.map(String);
  } catch (e) { /* bỏ qua bản nháp hỏng */ }
}
restoreAgentDraft();
watch([prompt, () => store.designAgentStep, sizePreset, () => store.selectedTrendIds], saveAgentDraft);

watch(prompt, () => { collectionError.value = ''; store.collectionBriefError = ''; });
watch(collection, (value) => {
  const settings = value?.canvas || {};
  canvas.value = {
    ratio: RATIO_OPTIONS.includes(settings.ratio) ? settings.ratio : '4:5',
    variantCount: Math.max(1, Math.min(4, Number(settings.variant_count) || 2)),
    useNegative: !!settings.negative_prompt,
    negativePrompt: String(settings.negative_prompt || ''),
  };
}, { immediate: true });
watch(() => store.designAgentOpen, (open) => {
  if (!open) return;
  // Nạp hồ sơ DNA và số đo khả năng internet MỘT lần khi mở: cả hai đều là dữ liệu mà người dùng
  // phải nhìn thấy trước khi tin vào phần phân tích phía sau.
  if (!store.brandDna) store.loadBrandDna();
  if (!store.webAccess) store.loadWebAccess();
  if (!store.webSources) store.loadWebSources(false, selectedRegion.value);
  if (!store.trendRadar) loadRadar(selectedRegion.value);
});

// ── CUNG CẤP bề mặt dùng chung cho 4 bước (tách 2026-09-24) ──
// Mỗi bước inject() đúng tên cần dùng; ref/computed/hàm cung cấp nguyên bản nên hành vi y hệt file gốc.
provide('prompt', prompt);
provide('promptInput', promptInput);
provide('collectionError', collectionError);
provide('creatingCollection', creatingCollection);
provide('trendQuery', trendQuery);
provide('trendCategory', trendCategory);
provide('liveOnly', liveOnly);
provide('lifecycleFilter', lifecycleFilter);
provide('sizePreset', sizePreset);
provide('briefTab', briefTab);
provide('canvasLang', canvasLang);
provide('canvas', canvas);
provide('refPickerOpen', refPickerOpen);
provide('shopPaste', shopPaste);
provide('shopParseError', shopParseError);
provide('shopOpen', shopOpen);
provide('STEPS', STEPS);
provide('BRIEF_TABS', BRIEF_TABS);
provide('PLAN_FIELDS', PLAN_FIELDS);
provide('SIZE_PRESETS', SIZE_PRESETS);
provide('RATIO_OPTIONS', RATIO_OPTIONS);
provide('CATEGORY_LABELS', CATEGORY_LABELS);
provide('LIFECYCLE_LABELS', LIFECYCLE_LABELS);
provide('DNA_LISTS', DNA_LISTS);
provide('dna', dna);
provide('dnaDraft', dnaDraft);
provide('dnaDirty', dnaDirty);
provide('dnaBlockReason', dnaBlockReason);
provide('dnaEmpty', dnaEmpty);
provide('refImages', refImages);
provide('referenceNote', referenceNote);
provide('liveSources', liveSources);
provide('liveTrendCount', liveTrendCount);
provide('newsItems', newsItems);
provide('activeSourceCount', activeSourceCount);
provide('fetchedAtLabel', fetchedAtLabel);
provide('refreshLabel', refreshLabel);
provide('autoRefresh', autoRefresh);
provide('market', market);
provide('marketLive', marketLive);
provide('marketSignals', marketSignals);
provide('marketTopics', marketTopics);
provide('marketPrices', marketPrices);
provide('marketAgeLabel', marketAgeLabel);
provide('internetVerdict', internetVerdict);
provide('groupsNeedingSetup', groupsNeedingSetup);
provide('cacheAgeLabel', cacheAgeLabel);
provide('step', step);
provide('stepIndex', stepIndex);
provide('radar', radar);
provide('collection', collection);
provide('regions', regions);
provide('selectedRegion', selectedRegion);
provide('trends', trends);
provide('sources', sources);
provide('sourceMode', sourceMode);
provide('summaryItems', summaryItems);
provide('selectedTrendIds', selectedTrendIds);
provide('selectedTrendCount', selectedTrendCount);
provide('selectedTrendObjects', selectedTrendObjects);
provide('trendCategories', trendCategories);
provide('lifecycles', lifecycles);
provide('visibleTrends', visibleTrends);
provide('palette', palette);
provide('moodboardItems', moodboardItems);
provide('categoryRows', categoryRows);
provide('outfitRows', outfitRows);
provide('sizeRows', sizeRows);
provide('priceBand', priceBand);
provide('canvasSettings', canvasSettings);
provide('currentBriefInput', currentBriefInput);
provide('briefStale', briefStale);
provide('sizeDistribution', sizeDistribution);
provide('canvasPrompt', canvasPrompt);
provide('estimatedCredits', estimatedCredits);
provide('readiness', readiness);
provide('activeModel', activeModel);
provide('modelReady', modelReady);
provide('modelShort', modelShort);
provide('modelTitle', modelTitle);
provide('modelCandidates', modelCandidates);
provide('aiToggleTitle', aiToggleTitle);
provide('directions', directions);
provide('appliedAi', appliedAi);
provide('briefModeMismatch', briefModeMismatch);
provide('plan', plan);
provide('planTotals', planTotals);
provide('planWaves', planWaves);
provide('planLines', planLines);
provide('planSizeChart', planSizeChart);
provide('planScenarios', planScenarios);
provide('planInput', planInput);
provide('shopSummary', shopSummary);
provide('shopRowCount', shopRowCount);
provide('dnaListMax', dnaListMax);
provide('dnaListText', dnaListText);
provide('setDnaText', setDnaText);
provide('setDnaList', setDnaList);
provide('onPickReference', onPickReference);
provide('removeReference', removeReference);
provide('shortDate', shortDate);
provide('signalSamples', signalSamples);
provide('trendSignalLabel', trendSignalLabel);
provide('sourceStatus', sourceStatus);
provide('sourceStatusTone', sourceStatusTone);
provide('directionConfidence', directionConfidence);
provide('directionPriceLabel', directionPriceLabel);
provide('trendNameById', trendNameById);
provide('focusDirections', focusDirections);
provide('toggleAi', toggleAi);
provide('schedulePlan', schedulePlan);
provide('loadPlanNow', loadPlanNow);
provide('planFieldValue', planFieldValue);
provide('importShopPaste', importShopPaste);
provide('addShopRow', addShopRow);
provide('removeShopRow', removeShopRow);
provide('saveShop', saveShop);
provide('csvCell', csvCell);
provide('downloadCsv', downloadCsv);
provide('exportCutSheet', exportCutSheet);
provide('exportSizeChart', exportSizeChart);
provide('copyCutSheet', copyCutSheet);
provide('setStep', setStep);
provide('loadRadar', loadRadar);
provide('toggleTrend', toggleTrend);
provide('clearTrends', clearTrends);
provide('selectSuggested', selectSuggested);
provide('trendTitle', trendTitle);
provide('categoryLabel', categoryLabel);
provide('lifecycleLabel', lifecycleLabel);
provide('lifecycleClass', lifecycleClass);
provide('formatNumber', formatNumber);
provide('formatVnd', formatVnd);
provide('createBrief', createBrief);
provide('advance', advance);
provide('back', back);
provide('applyCanvas', applyCanvas);
provide('createCollection', createCollection);
provide('copyText', copyText);
</script>

<template>
  <BaseModal
    v-model="store.designAgentOpen"
    full
    height="min(94vh, 960px)"
    title="Agent Studio — từ tín hiệu xu hướng tới ảnh hoàn chỉnh"
  >
    <div class="flex h-full min-h-0 flex-col">
      <!-- Đầu modal: nhận diện + điều khiển AI — gọn, không nhồi chip trạng thái -->
      <header class="shrink-0 border-b border-ink-700 bg-ink-900/80 px-4 py-2.5 sm:px-5">
        <div class="flex items-center justify-between gap-3">
          <div class="flex min-w-0 items-center gap-2.5">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand-600/20 text-brand-300"><StudioIcon name="sparkles" size="h-4 w-4" /></span>
            <div class="min-w-0">
              <p class="truncate text-sm font-bold text-cream-50">Agent Studio</p>
              <p class="hidden truncate text-label text-cream-400 sm:block">Từ tín hiệu xu hướng đến ảnh hoàn chỉnh</p>
            </div>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-label font-semibold" :class="modelReady ? 'bg-emerald-500/15 text-ok' : 'bg-amber-500/15 text-warn'" :title="modelTitle">
              <span class="h-1.5 w-1.5 rounded-full" :class="modelReady ? 'bg-emerald-300' : 'bg-amber-300'"></span>
              <span class="hidden md:inline">{{ modelShort }}</span>
              <span class="md:hidden">{{ modelReady ? 'Có AI' : 'Chưa bật AI' }}</span>
            </span>
            <button type="button" class="seg-btn !px-2.5 !py-1.5" :class="{ 'is-active': store.designAgentAi }" :aria-pressed="store.designAgentAi" :title="aiToggleTitle" @click="toggleAi">
              <StudioIcon name="sparkles" size="h-3.5 w-3.5" /> Suy luận AI
            </button>
          </div>
        </div>
        <!-- Vì sao đang chạy tất định? Nói thẳng lý do + nơi cấu hình. -->
        <p v-if="activeModel && !modelReady" role="status" class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-body leading-5 text-warn">
          <StudioIcon name="info" size="h-3.5 w-3.5" class="shrink-0" />
          <span>{{ modelTitle }}</span>
          <span v-if="!store.designAgentAi" class="text-warn">Bật «Suy luận AI» ở trên để phần phân tích do AI thực hiện.</span>
          <span v-else-if="modelCandidates.length === 0" class="text-warn">Cấu hình tại Cài đặt → Nhóm công việc → “Suy luận prompt” và thêm khoá trong Quản lý API.</span>
        </p>
      </header>

      <!-- Thanh tiến trình NGANG — một thanh dùng chung cho MỌI kích thước (mobile-first) -->
      <nav class="shrink-0 border-b border-ink-700 bg-ink-900/50 px-3 py-2.5 sm:px-5" aria-label="Tiến trình thiết kế">
        <div class="flex items-center gap-1 sm:gap-2">
          <template v-for="(item, index) in STEPS" :key="item.id">
            <button
              type="button"
              class="group flex min-w-0 flex-1 items-center gap-2 rounded-lg px-1.5 py-1 text-left transition sm:px-2"
              :class="step === item.id ? 'bg-brand-600/10' : 'hover:bg-ink-800'"
              :aria-current="step === item.id ? 'step' : undefined"
              @click="setStep(item.id)"
            >
              <span
                class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-label font-bold transition"
                :class="readiness[item.id] === 'done' ? 'bg-emerald-500/20 text-ok' : step === item.id ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-400 group-hover:text-cream-200'"
              >
                <StudioIcon v-if="readiness[item.id] === 'done'" name="check" size="h-3.5 w-3.5" />
                <span v-else>{{ index + 1 }}</span>
              </span>
              <span class="min-w-0">
                <span class="block truncate text-body font-semibold" :class="step === item.id ? 'text-cream-50' : 'text-cream-300'">{{ item.label }}</span>
                <span class="hidden truncate text-tiny text-cream-400 md:block">{{ item.hint }}</span>
              </span>
            </button>
            <span v-if="index < STEPS.length - 1" class="h-px w-2 shrink-0 bg-ink-600 sm:w-4" aria-hidden="true"></span>
          </template>
        </div>
      </nav>

      <!-- Bối cảnh hiện tại — một dòng gọn, hiện trên mọi kích thước -->
      <div class="shrink-0 border-b border-ink-700/60 bg-ink-900/30 px-4 py-1.5 sm:px-5">
        <dl class="flex flex-wrap items-center gap-x-4 gap-y-1 text-label text-cream-400">
          <div class="flex items-center gap-1.5"><StudioIcon name="pin" size="h-3 w-3" class="text-brand-300" /><dt class="sr-only">Khu vực</dt><dd class="font-semibold text-cream-200">{{ regions.find((r) => r.id === selectedRegion)?.name || selectedRegion }}</dd></div>
          <div class="flex items-center gap-1.5"><StudioIcon name="scan" size="h-3 w-3" class="text-brand-300" /><dt class="sr-only">Trend chọn</dt><dd :class="selectedTrendCount ? 'font-semibold text-cream-200' : ''">{{ selectedTrendCount ? selectedTrendCount + ' trend đã chọn' : 'Chưa chọn trend' }}</dd></div>
          <div class="flex items-center gap-1.5"><StudioIcon name="briefcase" size="h-3 w-3" class="text-brand-300" /><dt class="sr-only">Brief</dt><dd :class="collection && !briefStale ? 'font-semibold text-ok' : collection ? 'font-semibold text-warn' : ''">{{ collection && !briefStale ? 'Brief sẵn sàng' : collection ? 'Brief cần cập nhật' : 'Chưa có brief' }}</dd></div>
          <div v-if="collection" class="flex items-center gap-1.5"><StudioIcon name="package" size="h-3 w-3" class="text-brand-300" /><dt class="sr-only">SKU</dt><dd class="font-semibold text-cream-200">{{ collection.structure?.total_skus || 0 }} SKU</dd></div>
        </dl>
      </div>

      <!-- Nội dung bước -->
      <main class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
        <!-- Đầu bước: nhắc nhẹ đang ở đâu + việc cần làm — giúp người mới không lạc -->
        <header class="mb-4 flex items-baseline gap-2 sm:mb-5">
          <p class="shrink-0 text-label font-semibold uppercase tracking-[0.14em] text-brand-300">Bước {{ stepIndex + 1 }}</p>
          <p class="truncate text-body text-cream-400">{{ STEPS[stepIndex].hint }}</p>
        </header>
        <AgentDnaStep v-if="step === 'dna'" />
        <AgentRadarStep v-else-if="step === 'radar'" />
        <AgentBriefStep v-else-if="step === 'brief'" />
        <AgentCanvasStep v-else />
      </main>

      <!-- Action bar -->
      <footer class="shrink-0 border-t border-ink-700 bg-ink-900/90 px-4 py-3 sm:px-5">
        <div class="flex items-center justify-between gap-3">
          <button v-if="stepIndex > 0" type="button" class="tool-btn !px-3 !py-2.5" title="Quay lại (Ctrl+←)" @click="back"><StudioIcon name="arrowLeft" size="h-4 w-4" /><span class="hidden sm:inline"> Quay lại</span></button>
          <span v-else></span>
          <div class="flex items-center gap-3">
            <span class="hidden text-label text-cream-400 sm:inline">Bước {{ stepIndex + 1 }}/{{ STEPS.length }}</span>
            <button v-if="step !== 'canvas'" type="button" class="btn-brand flex items-center gap-2 !px-4 !py-2.5 text-sm" :disabled="(step === 'brief' && store.collectionBriefLoading)" title="Tiếp tục (Ctrl+→)" @click="advance">
              {{ step === 'dna' ? 'Đọc tín hiệu thị trường' : (step === 'radar' ? (selectedTrendCount ? 'Phân tích thành brief' : 'Tiếp tục với mặc định') : 'Chốt brief & sang Canvas') }}
              <StudioIcon name="arrowRight" size="h-4 w-4" />
            </button>
            <button v-else type="button" class="btn-brand flex items-center gap-2 !px-4 !py-2.5 text-sm" @click="applyCanvas">
              <StudioIcon name="zap" size="h-4 w-4" /> Áp dụng vào Canvas
            </button>
          </div>
        </div>
      </footer>
    </div>
  </BaseModal>
</template>
