/**
 * LÕI CỦA AGENT STUDIO — trạng thái + hành động của luồng 4 bước, tách khỏi phần RENDER.
 *
 * [2026-09-25] Agent Studio chuyển từ MODAL trong /studio thành MỘT TRANG RIÊNG (/agent-studio).
 * Nếu để nguyên trong một file .vue thì bản trang phải chép lại ~950 dòng — đúng kiểu "hai bản sao
 * rồi lệch nhau" mà repo này đã trả giá nhiều lần. Nay: logic ở ĐÂY, khung hiển thị ở
 * AgentStudioApp.vue, còn 4 bước (components/agents/*.vue) nhận đúng bề mặt cũ qua provideAll()
 * nên không phải sửa một dòng nào.
 *
 * Hợp đồng provide()/inject() với 4 bước được GIỮ NGUYÊN (đợt tách 2026-09-24): đổi sang props là
 * sửa cả 4 bước mà không đổi hành vi.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useStudioStore, userFacingError } from '../store.js';
// CSRF nằm trong helpers (store.js chỉ re-export apiError · safeMessage · userFacingError).
import { CSRF } from '../store/helpers.js';
// Đọc bảng dán từ Excel nằm ở MODULE RIÊNG để kiểm được bằng máy (scripts/check-shop-paste.mjs) —
// logic tiền nằm trong file .vue thì không test nào chạm tới, và đó đúng là cách lỗi cũ lọt qua.
import { parseShopRows } from '../shopPaste.js';

export function useAgentStudio() {
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
    // hint nói rõ ĐẦU RA của bước + dùng vào việc gì (không chỉ tên thao tác) — người mới hiểu vì sao phải làm bước này.
    // required/need: §4 luật 2 — việc bắt buộc phải nói RÕ chữ "bắt buộc"/"tùy chọn" ngay cạnh
    // tiêu đề bước. Trang Agent Studio in hai trường này ở đầu bước; rail chỉ in nhãn ngắn.
    { id: 'dna', label: 'DNA shop', hint: 'Khai shop bạn là ai → agent dùng nó để viết brief đúng chất shop', icon: 'sparkles', required: false, need: 'Đầu ra: hồ sơ phong cách · màu · nhóm hàng · chất liệu · thứ bạn KHÔNG làm. Bỏ trống vẫn chạy — khi đó agent suy ra từ dự án và ảnh bạn đã tạo.' },
    { id: 'radar', label: 'Tín hiệu', hint: 'Chọn hướng thời trang đang lên → những hướng này sẽ đi vào brief', icon: 'scan', required: true, need: 'Đầu ra: 2–4 hướng đã chọn, kèm số đo từ tin thật khi có nguồn. Không chọn gì thì brief dùng nhóm mặc định.' },
    { id: 'brief', label: 'Định hướng', hint: 'Dựng bản thiết kế bộ sưu tập + kế hoạch sản xuất & lãi gộp', icon: 'briefcase', required: true, need: 'Đầu ra: brief · mood board · cơ cấu danh mục · phối & bảng size · giá bán và lãi gộp. Đây là thứ bước Thực thi biến thành ảnh.' },
    { id: 'canvas', label: 'Thực thi', hint: 'Biến brief thành ảnh thật để đăng bán', icon: 'wand', required: true, need: 'Đầu ra: prompt tiếng Việt/Anh + tỉ lệ + số biến thể, đưa thẳng sang ô Tạo Ảnh của Studio.' },
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
  /**
   * BƯỚC CON — mỗi bước chính chia thành nhiều việc nhỏ, mỗi việc MỘT quyết định.
   *
   * Vì sao: trên điện thoại, một màn hình dài 3-4 cuộn thì người dùng không biết mình đang ở đâu và
   * còn phải làm gì. Bước con cho biết "3/7" và mỗi màn chỉ có một việc. Trên màn rộng vẫn giữ nguyên
   * thứ tự này — cùng một đường đi, chỉ khác cách bày.
   */
  const SUBSTEPS = {
    dna: [
      { id: 'positioning', label: 'Định vị', hint: 'Bạn bán cho ai, ở tầm giá nào' },
      { id: 'style', label: 'Phong cách', hint: 'Phong cách · màu · chất liệu · nhóm hàng · thứ KHÔNG làm' },
    ],
    radar: [
      { id: 'signals', label: 'Nguồn & số đo', hint: 'Tin thật đang có và những gì đo được từ tin' },
      { id: 'trends', label: 'Chọn hướng', hint: 'Chọn 2–4 hướng sẽ đi vào brief' },
    ],
    brief: [
      { id: 'prompt', label: 'Mô tả & ảnh mẫu', hint: 'Bộ sưu tập này cho ai và nhìn như thế nào' },
      { id: 'sku', label: 'Số lượng SKU', hint: 'Bao nhiêu mã hàng, chia cho nhóm nào' },
      { id: 'size', label: 'Bảng size', hint: 'Size nào, mỗi size bao nhiêu phần trăm' },
      { id: 'mood', label: 'Bảng mood', hint: 'Bảng màu + tâm trạng — đi thẳng vào prompt ảnh' },
      { id: 'cost', label: 'Đơn giá xưởng', hint: 'Giá vải, công may, phụ liệu… do bạn nhập' },
      { id: 'plan', label: 'Kế hoạch & lãi', hint: 'Lệnh cắt, giá vốn, ba mức giá bán' },
      { id: 'review', label: 'Xem lại & chốt', hint: 'Kiểm tra rồi tạo brief để sang bước Thực thi' },
    ],
    canvas: [
      { id: 'list', label: 'Danh sách mẫu', hint: 'Mỗi mã hàng là một mẫu, cần một prompt riêng' },
      { id: 'prompts', label: 'Sinh prompt từng mẫu', hint: 'Làm từng mẫu một, xong mẫu nào chốt mẫu đó' },
      { id: 'apply', label: 'Áp dụng & lưu', hint: 'Đưa prompt sang Canvas và lưu phiên' },
    ],
  };
  /** Bước con đang mở của TỪNG bước chính — nhớ riêng để quay lại là về đúng chỗ đang làm. */
  const subSteps = ref({ dna: 'positioning', radar: 'signals', brief: 'prompt', canvas: 'list' });
  const subList = computed(() => SUBSTEPS[step.value] || []);
  const subIndex = computed(() => Math.max(0, subList.value.findIndex((s) => s.id === subSteps.value[step.value])));
  const sub = computed(() => subList.value[subIndex.value]?.id || '');
  function setSub(id) {
    if (!SUBSTEPS[step.value]?.some((s) => s.id === id)) return;
    subSteps.value = { ...subSteps.value, [step.value]: id };
  }
  function subNext() { const next = subList.value[subIndex.value + 1]; if (next) setSub(next.id); }
  function subPrev() { const prev = subList.value[subIndex.value - 1]; if (prev) setSub(prev.id); }

  /**
   * BƯỚC ĐANG MỞ do URL quyết định (link chia sẻ).
   *
   * Vì sao cần cờ này: phiên làm việc được nạp BẤT ĐỒNG BỘ, về sau khi trang đã vẽ. Không có cờ thì
   * phiên ghi đè bước vừa đọc từ URL — gửi link cho đồng nghiệp mà họ lại mở đúng chỗ cũ của chính
   * họ, và tham số trên URL thành ra vô nghĩa. URL thắng, giống hệt luật với bản nháp trên máy.
   */
  const stepLockedByUrl = ref(false);
  function lockStepToUrl() { stepLockedByUrl.value = true; }

  // ── SỐ LƯỢNG SKU do người dùng chọn (0 = để hệ thống đề xuất) ────────────────────────────
  const skuTotal = ref(0);
  const skuTotalSource = computed(() => collection.value?.structure?.total_skus_source || (skuTotal.value ? 'owner' : 'system'));

  /**
   * BẢNG SIZE người dùng đặt: danh sách size + tỉ lệ %. Tổng LUÔN được khoá về 100.
   *
   * Vì sao không dùng 3 preset cứng như trước: `CollectionPlanService` đọc CHÍNH bảng này để ra lệnh cắt
   * (size nào bao nhiêu cái, đặt bao nhiêu mét vải). Shop có bảng size riêng thì mọi con số sản xuất đều
   * sai. Preset nay chỉ còn là ĐIỂM BẮT ĐẦU — bấm vào là đổ số vào bảng, rồi sửa tiếp.
   */
  const sizeRowsInput = ref(Object.entries(SIZE_PRESETS[0].values).map(([size, pct]) => ({ size, pct })));
  const sizePctTotal = computed(() => sizeRowsInput.value.reduce((sum, row) => sum + (Number(row.pct) || 0), 0));
  const sizeTotalOk = computed(() => sizePctTotal.value === 100 && sizeRowsInput.value.length > 0);
  function applySizePreset(preset) {
    const found = SIZE_PRESETS.find((item) => item.id === preset);
    if (!found) return;
    sizeRowsInput.value = Object.entries(found.values).map(([size, pct]) => ({ size, pct }));
  }
  function addSizeRow() {
    if (sizeRowsInput.value.length >= 12) return;
    const used = new Set(sizeRowsInput.value.map((r) => r.size));
    const free = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'].find((s) => !used.has(s)) || 'SIZE';
    sizeRowsInput.value = [...sizeRowsInput.value, { size: free, pct: 0 }];
  }
  function removeSizeRow(index) {
    if (sizeRowsInput.value.length <= 1) return;   // bảng size rỗng thì lệnh cắt không có gì để chia
    sizeRowsInput.value = sizeRowsInput.value.filter((_, i) => i !== index);
  }
  function setSizeRow(index, patch) {
    sizeRowsInput.value = sizeRowsInput.value.map((row, i) => (i === index ? { ...row, ...patch } : row));
  }
  /** Chia đều 100% cho các size đang có — nút "chia đều" khi người dùng chưa biết tỉ lệ nào hợp lý. */
  function evenSizeRows() {
    const n = sizeRowsInput.value.length;
    if (!n) return;
    const base = Math.floor(100 / n);
    let rest = 100 - base * n;
    sizeRowsInput.value = sizeRowsInput.value.map((row) => {
      const pct = base + (rest > 0 ? 1 : 0);
      if (rest > 0) rest--;
      return { ...row, pct };
    });
  }

  /**
   * BẢNG CƠ CẤU NHÓM HÀNG do người dùng tự đặt — cùng họ với bảng size ở trên.
   *
   * Vì sao cần: chọn TỔNG số mã hàng mới chỉ nói quy mô. Chia cho nhóm nào là quyết định của người bỏ
   * vốn, mà trước đây chỗ chia là của thuật toán (theo đúng tỉ lệ cũ) — muốn dồn 8 mã cho nhóm áo cũng
   * không có cách nào nói ra. Nay bảng sửa được y như bảng size: thêm/bớt nhóm, đặt số mã từng nhóm.
   *
   * `structureEdited` là thứ quyết định bảng này có được GỬI LÊN hay không. Không có nó thì bảng vừa
   * được đổ từ đề xuất của hệ thống sẽ bị gửi lên như thể người dùng tự đặt, và nhãn "do bạn đặt" thành
   * lời nói dối ngay ở lượt đầu tiên.
   */
  const structureRowsInput = ref([]);
  const structureEdited = ref(false);
  /** Số mã hàng của TỪNG nhóm theo bảng đang hiện — tổng của nó là con số đi vào lệnh cắt. */
  const structureCountTotal = computed(() => structureRowsInput.value
    .filter((row) => String(row.category || '').trim() !== '')
    .reduce((sum, row) => sum + Math.max(0, Number(row.count) || 0), 0));
  /** Bảng sửa rồi mà chưa áp vào brief? (so với chính bảng đang nằm trong brief) */
  const structureDirty = computed(() => {
    if (!structureEdited.value) return false;
    const rows = structureSignature(structureRowsInput.value);
    return rows !== structureSignature(categoryRows.value);
  });
  /** Bảng gửi lên máy chủ: chỉ gửi khi người dùng THẬT SỰ đặt, để nhãn nguồn nói đúng sự thật. */
  const structureInput = computed(() => (structureEdited.value
    ? structureRowsInput.value
      .filter((row) => String(row.category || '').trim() !== '')
      .map((row) => ({ category: String(row.category).trim(), count: Math.max(0, Number(row.count) || 0) }))
    : []));
  function structureSignature(rows) {
    return (rows || [])
      .map((row) => String(row?.category || '').trim() + ':' + (Math.max(0, Number(row?.count) || 0)))
      .join('|');
  }
  /** Đổ bảng từ cơ cấu đang có trong brief — đây là ĐIỂM BẮT ĐẦU để sửa tiếp, như preset của bảng size. */
  function seedStructureRows(rows) {
    const next = (rows || []).map((row) => ({
      category: String(row?.category || ''),
      count: Math.max(0, Number(row?.count) || 0),
      rationale: String(row?.rationale || ''),
    }));
    // Gán lại một mảng y hệt là thay đổi GIẢ: watcher ghi phiên bắn lên và một lượt ghi vô nghĩa được
    // xếp hàng ngay lúc trang vừa dựng — đúng cái bẫy đã xoá mất bản brief của người dùng.
    if (structureSignature(next) === structureSignature(structureRowsInput.value)) return;
    structureRowsInput.value = next;
  }
  function setStructureRow(index, patch) {
    structureEdited.value = true;
    structureRowsInput.value = structureRowsInput.value.map((row, i) => (i === index ? { ...row, ...patch } : row));
  }
  function addStructureRow() {
    if (structureRowsInput.value.length >= 12) return;
    structureEdited.value = true;
    structureRowsInput.value = [...structureRowsInput.value, { category: '', count: 0, rationale: '' }];
  }
  function removeStructureRow(index) {
    if (structureRowsInput.value.length <= 1) return;   // bảng rỗng thì lệnh cắt không có gì để chia
    structureEdited.value = true;
    structureRowsInput.value = structureRowsInput.value.filter((_, i) => i !== index);
  }
  /** Chia đều tổng đang chọn cho các nhóm đang có — phần dư dồn vào nhóm đầu (largest remainder). */
  function evenStructureRows() {
    const rows = structureRowsInput.value;
    if (!rows.length) return;
    const target = Math.max(rows.length, Number(skuTotal.value) || structureCountTotal.value || rows.length);
    structureEdited.value = true;
    const base = Math.floor(target / rows.length);
    let rest = target - base * rows.length;
    structureRowsInput.value = rows.map((row) => {
      const count = base + (rest > 0 ? 1 : 0);
      if (rest > 0) rest--;
      return { ...row, count };
    });
  }
  /**
   * KHỚP TỔNG: dồn phần lệch vào nhóm ĐANG NHIỀU NHẤT.
   *
   * Không chia đều phần lệch: chia đều làm xáo trộn cả bảng vừa nhập, còn dồn vào nhóm lớn nhất thì hình
   * dạng bảng giữ nguyên. Cùng luật với nút "Cân về 100%" của bảng size.
   */
  function normalizeStructureRows() {
    const rows = structureRowsInput.value;
    if (!rows.length) return;
    const target = Number(skuTotal.value) || structureCountTotal.value;
    const diff = target - structureCountTotal.value;
    if (diff === 0) return;
    const biggest = rows.reduce((best, row, i) => (Number(row.count) > Number(rows[best].count) ? i : best), 0);
    structureEdited.value = true;
    structureRowsInput.value = rows.map((row, i) => (i === biggest
      ? { ...row, count: Math.max(0, Math.min(400, Number(row.count) + diff)) }
      : row));
  }
  /** Bỏ bảng của người dùng, quay về đề xuất của hệ thống cho lượt dựng brief kế tiếp. */
  function useAutoStructure() {
    structureEdited.value = false;
    structureRowsInput.value = [];
  }

  // ── BẢNG MÀU & BẢNG MOOD do người dùng sửa (rỗng = dùng bản hệ thống dựng) ──────────────
  const paletteRows = ref([]);
  const moodRows = ref([]);
  /**
   * LẦN SỬA ĐẦU TIÊN thì chép bảng hệ thống dựng thành dòng sửa được.
   *
   * Vì sao không chép ngay khi có brief: chép là `paletteRows` khác rỗng, mà `paletteRows` nằm trong
   * đầu vào so sánh brief ⇒ brief VỪA TẠO đã bị coi là "đã cũ" dù người dùng chưa đổi gì. Chỉ chép
   * khi người dùng THẬT SỰ sửa thì tín hiệu "brief đã cũ" mới đúng nghĩa.
   */
  function ensurePaletteRows() {
    if (!paletteRows.value.length) {
      paletteRows.value = (collection.value?.palette || []).map((row) => ({ ...row }));
    }
  }
  function ensureMoodRows() {
    if (!moodRows.value.length) {
      moodRows.value = (collection.value?.moodboard?.items || []).map((row) => ({ ...row }));
    }
  }
  function resetPaletteRows() { paletteRows.value = []; }
  function resetMoodRows() { moodRows.value = []; }
  function addPaletteRow() {
    ensurePaletteRows();
    if (paletteRows.value.length >= 12) return;
    paletteRows.value = [...paletteRows.value, { name: 'Màu ' + (paletteRows.value.length + 1), hex: '#CCCCCC', role: '' }];
  }
  function removePaletteRow(index) { paletteRows.value = paletteRows.value.filter((_, i) => i !== index); }
  function setPaletteRow(index, patch) {
    ensurePaletteRows();
    paletteRows.value = paletteRows.value.map((row, i) => (i === index ? { ...row, ...patch } : row));
  }
  function addMoodRow() {
    ensureMoodRows();
    if (moodRows.value.length >= 40) return;
    const hex = paletteRows.value[0]?.hex || palette.value[0]?.hex || '#CCCCCC';
    moodRows.value = [...moodRows.value, { id: 'mood-' + Date.now(), label: '', caption: '', color: hex }];
  }
  function removeMoodRow(index) { moodRows.value = moodRows.value.filter((_, i) => i !== index); }
  function setMoodRow(index, patch) {
    ensureMoodRows();
    moodRows.value = moodRows.value.map((row, i) => (i === index ? { ...row, ...patch } : row));
  }
  /** Đổi thứ tự ô mood — thứ tự này cũng là thứ tự đưa vào prompt nên kéo lên/xuống là đổi prompt. */
  function moveMoodRow(index, delta) {
    ensureMoodRows();
    const to = index + delta;
    if (to < 0 || to >= moodRows.value.length) return;
    const next = [...moodRows.value];
    const [row] = next.splice(index, 1);
    next.splice(to, 0, row);
    moodRows.value = next;
  }

  // ── MẪU: mỗi mã hàng một prompt, người dùng chốt từng mẫu ────────────────────────────────
  const samples = ref([]);
  const sampleBusyId = ref('');
  const sampleError = ref('');
  const sampleProgress = computed(() => {
    const rows = samples.value;
    return {
      total: rows.length,
      done: rows.filter((s) => s.status === 'done').length,
      skipped: rows.filter((s) => s.status === 'skipped').length,
      todo: rows.filter((s) => s.status !== 'done' && s.status !== 'skipped').length,
    };
  });
  /** Mẫu kế tiếp cần làm — nút chính ở bước Thực thi luôn nhắm vào đúng mẫu này. */
  const nextSample = computed(() => samples.value.find((s) => s.status !== 'done' && s.status !== 'skipped') || null);
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
  /** Có tin thật để AI đọc? (máy chủ tự lấy, không phải model tự tìm kiếm).
   *  [BUG ĐÃ SỬA] Trước đây chỉ đọc webSources.mode ⇒ radar ĐÃ chạy bằng tin thật (source_mode=live) mà
   *  giao diện vẫn báo "Chưa có tin thật nào" — hai tầng lệch nhau. Nay ưu tiên trạng thái THẬT của radar. */
  const liveSources = computed(() => (store.trendRadar?.source_mode === 'live') || !!(store.webSources && store.webSources.mode === 'live'));
  /** Số hướng đang được ĐO từ tin thật (khác hướng của bộ có sẵn) — hiện trên chip lọc. */
  const liveTrendCount = computed(() => trends.value.filter((trend) => trend.evidence_mode === 'live').length);
  /**
   * Trong số hướng "đo từ tin thật", bao nhiêu hướng có bằng chứng đến từ CÂU HỎI CỦA MODEL (máy chủ chạy lại
   * câu hỏi đó trên nguồn tìm kiếm thật) — khác với tin của feed định kỳ. Người dùng cần phân biệt được hai
   * nguồn này: một cái là ảnh chụp định kỳ, một cái là do AI chủ động đi tra trong lượt này.
   */
  const aiTrendCount = computed(() => trends.value.filter((trend) => (trend.live?.origin || trend.evidence_origin) === 'ai').length);
  /**
   * Hướng AI ĐÃ TRA nhưng KHÔNG ra tin — trạng thái THỨ BA, tách khỏi "bộ có sẵn".
   *
   * Gộp hai chuyện này là nói thiếu: "chưa ai tra hướng đó" khác hẳn "đã tra và không có tin nào nhắc tới".
   * Người dùng cần biết hệ thống đã thử, và cần biết con số đang hiện vẫn là số mẫu.
   */
  const aiCheckedCount = computed(() => trends.value.filter((trend) => trend.evidence_mode !== 'live' && trend.checked_by_ai).length);
  /** Tin hiển thị ưu tiên lấy từ radar (thứ phân tích THẬT SỰ đã dùng), rơi về báo cáo nguồn khi radar chưa có. */
  const newsItems = computed(() => {
    const items = (store.trendRadar?.external_evidence?.items?.length ? store.trendRadar.external_evidence.items : (store.webSources?.items || []));
    return items.slice(0, 6);
  });
  const activeSourceCount = computed(() => {
    const evidenceSources = store.trendRadar?.external_evidence?.sources;
    if (Array.isArray(evidenceSources) && evidenceSources.length) {
      return evidenceSources.filter((row) => (Number(row.count) || 0) > 0).length;
    }
    return ((store.webSources?.sources || []).filter((row) => row.ok)).length;
  });
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
      const base = 'Đà tăng ' + (trend?.momentum ?? 0) + '/100 · ' + formatNumber(trend?.evidence_count) + ' bằng chứng của bộ có sẵn';
      // Hướng AI ĐÃ TRA mà không ra tin: nói rõ là đã thử — để người dùng không tưởng hệ thống bỏ qua nó,
      // và để con số mẫu kia không bị đọc như số đo.
      // Lý do cụ thể do MÁY CHỦ ghi (model phán "có" mà dẫn nguồn không có thật, hoặc tra mà không thấy) —
      // ưu tiên câu của máy chủ vì nó là bên đã đối chiếu URL.
      if (trend?.check_note) return base + ' · ' + trend.check_note;
      return trend?.checked_by_ai
        ? base + ' · AI đã tra trong lượt này nhưng chưa thấy tin nào nhắc tới, nên số trên vẫn là số mẫu'
        : base;
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
  /** Tên khu vực đang đọc — rail của trang in tên, không in mã ('hcm' là mã, không phải chữ cho người). */
  const regionName = computed(() => (regions.value.find((r) => r.id === selectedRegion.value) || {}).name || selectedRegion.value);
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
    // [TỐI GIẢN] Bỏ 2 ô hằng số vô nghĩa ("Thuộc tính theo dõi: 5" · "Ảnh phân tích mỗi tháng: 0") — chỉ giữ
    // các số THẬT người dùng dùng để quyết định.
    const order = ['live_sources', 'market_signals', 'active_trends', 'internal_products', 'internal_generations'];
    const labels = {
      live_sources: 'Nguồn tin đang dùng',
      market_signals: 'Từ khoá từ tin thật',
      active_trends: 'Hướng đang theo dõi',
      internal_products: 'Sản phẩm của bạn',
      internal_generations: 'Ảnh bạn đã tạo',
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
  // Ưu tiên bản NGƯỜI DÙNG đang sửa; chưa sửa gì thì dùng bản hệ thống dựng trong brief.
  const palette = computed(() => (paletteRows.value.length ? paletteRows.value : (collection.value?.palette || [])));
  const moodboardItems = computed(() => (moodRows.value.length ? moodRows.value : (collection.value?.moodboard?.items || [])));
  const categoryRows = computed(() => collection.value?.structure?.categories || []);
  const outfitRows = computed(() => collection.value?.outfit_matching || []);
  const sizeRows = computed(() => collection.value?.size_distribution || []);
  const priceBand = computed(() => collection.value?.price_bands || null);
  const canvasSettings = computed(() => collection.value?.canvas || null);
  /**
   * Bảng size gửi lên máy chủ: { size: tỉ lệ }. Máy chủ chỉ cần TỈ LỆ (nó tự quy về % và số cái), nên
   * gửi thẳng phần trăm là đúng và không phải quy đổi hai lần.
   */
  const sizeDistribution = computed(() => Object.fromEntries(
    sizeRowsInput.value
      .filter((row) => String(row.size || '').trim() !== '')
      .map((row) => [String(row.size).trim().toUpperCase(), Math.max(0, Number(row.pct) || 0)]),
  ));
  /** Đầu vào của brief — GỒM cả ba lựa chọn mới: đổi chúng là phần chữ của brief cũ không còn đúng. */
  const currentBriefInput = computed(() => ({
    prompt: prompt.value.trim(),
    region: selectedRegion.value,
    trend_ids: selectedTrendIds.value,
    sku_total: skuTotal.value,
    palette: paletteRows.value,
    moodboard: moodRows.value,
    // [BUG ĐÃ SỬA] size_distribution PHẢI nằm trong input so sánh: thiếu nó thì brief vừa tạo LUÔN bị
    // coi là "đã cũ" (bảng size được ghi vào brief khi tạo, nhưng chỗ kiểm tra lại không đưa vào so sánh)
    // ⇒ người dùng chưa đổi gì vẫn thấy "Prompt/trend đã đổi. Bấm «Tạo lại brief»".
    size_distribution: sizeDistribution.value,
    // Bảng cơ cấu sửa rồi cũng làm brief cũ sai: nhóm hàng nào bao nhiêu mã là thứ đi vào lệnh cắt,
    // và khoá bộ đệm của MÁY CHỦ có phần này.
    structure: structureInput.value,
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
  // [SỬA LỖI THẬT — phản hồi chủ dự án 2026-09-21] Hai câu này trước đây nói SAI về TRẠNG THÁI:
  //   · no_model_key nói "AI chưa được bật" trong khi đúng ra là "CHƯA GÁN model AI" (người dùng
  //     bật công tắc rồi mà vẫn đọc thấy mình "chưa bật" ⇒ mất niềm tin vào công tắc);
  //   · ai_disabled nói "Bạn ĐANG tắt" trong khi đây chỉ là bản brief ĐƯỢC DỰNG LÚC đang tắt — công
  //     tắc có thể đã bật lại từ lâu. Nói snapshot bằng thì hiện tại là nói sai.
  // [SỬA LỖI THẬT — phản hồi chủ dự án 2026-09-21] Hai câu này trước đây nói SAI về TRẠNG THÁI:
  //   · no_model_key nói "AI chưa được bật" trong khi đúng ra là "CHƯA GÁN model AI";
  //   · ai_disabled nói "Bạn ĐANG tắt" trong khi đây chỉ là bản brief ĐƯỢC DỰNG LÚC đang tắt.
  // Và nay CÂU CHỮ ĐÃ ĐƯỢC RÚT GỌN: nhãn chỉ nói NGUYÊN NHÂN. Trạng thái "đang chạy bằng bộ quy tắc"
  // do CHIP ở thanh trên nói (modelShort) — nhắc lại ở đây thì một màn hình có ba chỗ nói cùng một câu
  // (chip thanh trên · chip trong card · cuối câu lý do), đúng thứ người dùng phản hồi là rối.
  const MODEL_REASON_LABELS = {
    no_model_key: 'Chưa cấu hình AI cho nhóm công việc.',
    model_error: 'AI không phản hồi ở lượt này — kết quả vẫn đầy đủ.',
    invalid_output: 'AI trả về dữ liệu không dùng được ở lượt này — kết quả vẫn đầy đủ.',
    ai_disabled: 'Brief này được dựng khi Suy luận AI đang TẮT.',
    // Người dùng TỰ bấm cập nhật số liệu: đây không phải sự cố, nên câu chữ cũng không được như sự cố.
    rules_refresh: 'Số liệu vừa được cập nhật bằng bộ quy tắc (không gọi AI).',
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
      // `note` là câu NÓI ĐÚNG chuyện vừa xảy ra: lượt cập nhật số liệu giữ nguyên phần chữ của AI và
      // chỉ tính lại các con số. Không có nhánh này thì chip chỉ nói "Có suy luận AI" — đúng về phần chữ
      // nhưng bỏ mất việc người dùng vừa bấm, mà đó lại là thứ khiến họ tin được các con số đang nhìn.
      if (m.note) return m.note;
      return 'Phần định hướng do AI viết trên dữ liệu mẫu ở trên'
        + (m.cached ? ' · kết quả lấy từ lần phân tích gần nhất' : '');
    }
    return MODEL_REASON_LABELS[m.reason] || 'Đang chạy bằng bộ quy tắc có sẵn.';
  });
  const modelCandidates = computed(() => activeModel.value?.available || []);
  /**
   * NHỮNG LÝ DO ĐÁNG DỰNG BĂNG CẢNH BÁO — chỉ SỰ CỐ THẬT của lượt vừa rồi.
   *
   * Cố ý KHÔNG có `ai_disabled`, và đây là chỗ bản trước sai: đó là chuyện ĐÃ XẢY RA với bản brief
   * (dựng lúc AI tắt) chứ không phải trạng thái hiện tại, mà trong dữ liệu đã lưu nó KHÔNG phân biệt
   * được với lượt "cập nhật số liệu" do chính người dùng bấm. Bản brief đi theo phiên làm việc, nên
   * cờ dựa trên nó bật lên ở MỌI lần mở lại trang — đúng phản hồi "dai dẳng". Việc "brief lệch công
   * tắc" vẫn được nói, nhưng ở đúng chỗ CÓ HÀNH ĐỘNG: băng briefModeMismatch trong bước Định hướng.
   */
  const MODEL_ALARM_REASONS = ['no_model_key', 'model_error', 'invalid_output'];
  const modelNeedsAttention = computed(() => MODEL_ALARM_REASONS.includes(activeModel.value?.reason));
  /**
   * TÌM KIẾM BẰNG CÔNG CỤ — SỐ ĐO, không phải lời hứa (docs/DESIGN_SYSTEM.md §18.2).
   *
   * Backend trả khối `model.tool_search` cho MỌI lượt chạy: đã bật công cụ chưa · nhà cung cấp có nhận tham
   * số công cụ không · model đã gọi mấy lượt · hỏi từ khoá gì · được bao nhiêu tin. Giao diện chỉ được nói
   * những gì khối này đo được — trước đây chỗ này là câu văn tĩnh nên nói sai cả khi agent không hề tìm.
   */
  const toolSearch = computed(() => activeModel.value?.tool_search || null);
  /**
   * NHÃN NGẮN cho phần "AI có tự ra internet không" — hiện NGAY TRÊN ĐẦU khối định hướng, không gấp lại.
   *
   * Vì sao phải đổi chỗ: bản trước đặt câu số đo này bên trong <details> "Nguồn dữ liệu cho phân tích" nên
   * mở màn hình ra chỉ thấy danh sách tin MÁY CHỦ LẤY SẴN theo nguồn cấu hình — người dùng kết luận (đúng theo
   * những gì nhìn thấy) rằng "vẫn dùng nguồn trong Cài đặt, không dùng công cụ tìm kiếm". Số đo thì vẫn đúng,
   * chỉ là bị chôn.
   */
  const aiSearchCalls = computed(() => Number(toolSearch.value?.calls || 0));
  const aiSearchQueries = computed(() => (toolSearch.value?.queries || []).filter(Boolean));
  const aiSearchSources = computed(() => (toolSearch.value?.sources || []).filter(Boolean));
  /**
   * TIN MÁY CHỦ LẤY ĐƯỢC khi chạy lại câu hỏi của model — kèm URL để người dùng tự kiểm.
   *
   * Đây là phần biến "AI đã tra" thành DỮ LIỆU KIỂM CHỨNG ĐƯỢC: API /responses chỉ trả về câu hỏi model đã
   * hỏi (không trả kết quả), nên máy chủ chạy lại chính câu hỏi đó trên nguồn tìm kiếm thật.
   */
  const aiSearchItems = computed(() => (toolSearch.value?.items || []).filter((row) => row && row.url));
  const aiSearchMode = computed(() => String(toolSearch.value?.mode || 'off'));
  const aiSearchOn = computed(() => aiSearchMode.value !== 'off' && toolSearch.value?.enabled !== false);
  const aiSearchShort = computed(() => {
    if (!aiSearchOn.value) return '';
    if (aiSearchMode.value === 'hosted' && aiSearchCalls.value === 0) return 'AI không tìm trên internet lượt này';
    // Đường "nhà cung cấp tự tìm bằng tham số": máy chủ KHÔNG đo được, nên nhãn không được nói như đã tìm.
    if (aiSearchMode.value === 'native' && toolSearch.value?.claim === false) return 'Chưa xác nhận được AI có tra internet hay không';

    // Đếm TRUY VẤN, không đếm "lượt gọi công cụ": một lời gọi của DeepSeek có thể mang 6 câu hỏi, nên nhãn
    // "1 lượt" vừa rồi đọc lên như thể model chỉ tra một thứ — trong khi nó tra sáu chủ đề.
    if (aiSearchQueries.value.length) {
      return 'AI tự tìm trên internet: ' + aiSearchQueries.value.length + ' truy vấn';
    }

    return aiSearchCalls.value > 0 ? 'AI tự tìm trên internet: ' + aiSearchCalls.value + ' lượt' : 'AI có công cụ tìm kiếm (không dùng)';
  });
  const toolSearchLine = computed(() => {
    const t = toolSearch.value;
    if (!t || t.mode === 'off') return '';
    if (t.mode === 'native') {
      // ĐO THẬT 2026-09-21: cùng một khoá/model Qwen đang chạy production, gửi cờ `enable_search` →
      // HTTP 200 mà nhà cung cấp KHÔNG tìm gì (model tự trả lời "không truy cập được internet"), trong khi
      // màn hình vẫn nói "tìm kiếm do nhà cung cấp thực hiện". Câu đó chỉ được nói khi có cơ sở.
      if (t.claim === false) {
        return 'Lượt này có gửi yêu cầu tìm kiếm tới nhà cung cấp model, nhưng họ không trả về nguồn nào để đối chiếu — hệ thống KHÔNG xác nhận được đã tra hay chưa, nên đừng coi là có dẫn nguồn.';
      }
      return 'Lượt này tìm kiếm nguồn ngoài do chính nhà cung cấp model thực hiện.';
    }
    // mode === 'hosted': công cụ tìm kiếm CỦA nhà cung cấp qua endpoint riêng. Đây là chỗ dễ nói dối nhất:
    // model nhỏ NHẬN tham số rồi trả lời trơn tru mà không tìm gì (đo thật: có model còn bịa cả tin lẫn URL)
    // ⇒ chỉ được nói "đã tìm" khi phản hồi có lời gọi tìm kiếm thật.
    if (t.mode === 'hosted') {
      if (Number(t.calls) > 0) {
        const q = (t.queries || []).filter(Boolean);
        const who = q.length ? ' theo từ khoá “' + q.join('”, “') + '”' : '';
        const src = (t.sources || []).length ? ' · mở ' + t.sources.length + ' trang nguồn' : '';
        return 'Model đã tự tìm trên internet ' + t.calls + ' lượt' + who + src;
      }
      return 'Model này nhận yêu cầu tìm kiếm nhưng KHÔNG thực hiện lượt tìm nào — lượt này không có nguồn ngoài từ model. Đổi sang model có hỗ trợ tìm kiếm nếu cần dẫn nguồn.';
    }
    // mode === 'tool': công cụ do MÁY CHỦ chạy. Ba mức rất khác nhau, không được gộp thành một câu.
    if (t.accepted === false) {
      return 'Model bạn chọn KHÔNG nhận công cụ tìm kiếm nên lượt này không đọc được nguồn ngoài — đổi model cho vai «Tìm kiếm nguồn ngoài» trong Cài đặt nếu cần dẫn nguồn.';
    }
    if (Number(t.calls) > 0) {
      const queries = (t.queries || []).filter(Boolean);
      const who = queries.length ? ' theo từ khoá “' + queries.join('”, “') + '”' : '';
      const sources = (t.sources || []).length ? ' từ ' + t.sources.length + ' nguồn' : '';
      return 'Đã tự tìm trên internet ' + t.calls + ' lượt' + who + ': ' + Number(t.results || 0) + ' tin' + sources
        + (t.error ? ' · ' + t.error : '');
    }
    return t.enabled
      ? 'Lượt này CÓ công cụ tìm kiếm nhưng model không cần dùng — câu trả lời dựa trên dữ liệu đã đưa vào.'
      : '';
  });
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
  /**
   * Brief hiện tại được dựng ở chế độ khác với công tắc AI hiện tại?
   *
   * Miễn trừ: brief vừa được CẬP NHẬT SỐ LIỆU theo yêu cầu của chính người dùng (`rules_refresh`).
   * Lúc đó chế độ lệch công tắc là ĐÚNG Ý MUỐN — báo "lệch" chỉ tạo ra một băng dính mãi mà không có
   * việc gì để làm.
   */
  const briefModeMismatch = computed(() => {
    const model = collection.value?.model;
    const mode = model?.mode;
    if (mode !== 'ai' && mode !== 'rule') return false;
    if (model?.reason === 'rules_refresh') return false;
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
  const planInput = computed(() => serverInput());
  let planTimer = null;
  /** Tự tính lại khi GÕ số liệu — gộp nhiều lần gõ thành 1 lượt, chạy IM LẶNG để không nhảy nút "Đang tính…". */
  function schedulePlan() {
    if (planTimer) clearTimeout(planTimer);
    planTimer = setTimeout(() => { loadPlanSilently(); }, 500);
  }
  /** Nút "Tính lại kế hoạch" (bấm tay) — CÓ trạng thái loading để người dùng biết đang chạy. */
  async function loadPlanNow() {
    if (prompt.value.trim().length < 3) {
      store.planError = 'Nhập prompt bộ sưu tập (tối thiểu 3 ký tự) trước khi lập kế hoạch sản xuất.';
      return;
    }
    try { await store.loadPlan(planInput.value); } catch (error) { /* store giữ lỗi */ }
  }
  /** Tự tính lại khi gõ — IM LẶNG: không bật planLoading, không xoá kết quả cũ khi lỗi ⇒ không nhảy layout. */
  async function loadPlanSilently() {
    if (prompt.value.trim().length < 3) return;
    try { await store.loadPlan(planInput.value, { silent: true }); } catch (error) { /* store giữ lỗi */ }
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

  /**
   * Payload gửi máy chủ cho CẢ brief và kế hoạch — một chỗ duy nhất, vì hai đường phải nhận đúng cùng
   * một đầu vào (cấu trúc danh mục, bảng size, dải giá). Thêm lựa chọn mới mà chỉ sửa một đường là
   * màn hình hiện một đằng, lệnh cắt ra một nẻo.
   */
  function serverInput() {
    return {
      prompt: prompt.value.trim(),
      region: selectedRegion.value,
      trend_ids: selectedTrendIds.value,
      size_distribution: sizeDistribution.value,
      sku_total: skuTotal.value || undefined,
      // Bảng cơ cấu CHỈ gửi khi người dùng tự đặt (xem `structureEdited`) — gửi bảng vừa đổ từ đề xuất
      // của hệ thống thì nhãn nguồn sẽ nói "do bạn đặt" ngay ở lượt đầu, sai sự thật.
      structure: structureInput.value.length ? structureInput.value : undefined,
      palette: paletteRows.value.length ? paletteRows.value : undefined,
      moodboard: moodRows.value.length ? moodRows.value : undefined,
    };
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
      // `opts.ai === false` = lượt CẬP NHẬT SỐ LIỆU: người dùng vừa sửa SKU/size/mood và chỉ cần các
      // con số cùng cơ cấu tính lại — tức thì, không tốn lượt gọi AI.
      //
      // Ba cờ đi CÙNG NHAU, thiếu một cái là hỏng một chuyện khác nhau:
      //   · ai: false    — không gọi model (nhanh, không tốn tiền);
      //   · refresh: 1   — máy chủ ghi lý do là "người dùng yêu cầu cập nhật số liệu", KHÔNG phải
      //                    "AI đang tắt" (trước đây gộp làm một nên bản brief bị đóng dấu sai và câu
      //                    sai ấy theo vào cả phiên làm việc);
      //   · keepText     — giữ phần CHỮ do AI viết của bản trước, chỉ thay phần số.
      const payload = serverInput();
      if (opts.ai === false) payload.refresh = 1;
      await store.createCollectionBrief(payload, {
        force: !!opts.force,
        ai: opts.ai,
        keepText: opts.ai === false,
      });
      // GHI NGAY, không chờ nhịp gộp 1,5 giây: bộ theo dõi ghi phiên bên dưới không theo dõi bản brief,
      // nên trước đây lượt "Cập nhật số liệu" chỉ đổi màn hình mà KHÔNG ghi gì — F5 là quay về con số cũ
      // (đo được: biểu đồ hiện 18 mã, tải lại về 12 mã, và nút lại mời bấm đúng việc vừa bấm).
      await saveSession();
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
  /**
   * BƯỚC CUỐI RỜI KHỎI TRANG NÀY — "Áp dụng vào Canvas".
   *
   * [2026-09-25] Trước đây việc này chỉ ghi vào store rồi ĐÓNG MODAL, nên ở lại trong /studio là
   * đúng. Nay Agent Studio là một TRANG riêng: store là bộ nhớ TRONG TRANG, điều hướng sang /studio
   * là mất sạch. Vì vậy phải làm đủ ba việc, theo đúng thứ tự này:
   *   1. ghi prompt/tỉ lệ/biến thể vào store (để lần vẽ này phản ánh ngay nếu còn ở lại),
   *   2. ghi BẢN BỀN — dùng đúng khoá `fabrikai.prompt-cfg` mà ConceptCard vẫn dùng, KHÔNG mở khoá
   *      lưu thứ hai (trang /studio khôi phục lại từ đó lúc khởi động),
   *   3. điều hướng sang Studio, mở sẵn panel Tạo ảnh + bảng Prompt.
   *
   * Chỉ đi khi bước 1 THÀNH CÔNG: prompt rỗng thì ở lại và nói lý do, không đá người dùng sang
   * trang khác rồi mới báo lỗi.
   */
  function applyCanvas() {
    const value = String(canvasPrompt.value || '').trim();
    if (!value) {
      collectionError.value = 'Chưa có prompt để áp dụng vào Canvas.';
      return false;
    }
    const ok = store.applyAgentPrompt(value, {
      ratio: canvas.value.ratio,
      variant_count: canvas.value.variantCount,
      negative_prompt: canvas.value.useNegative ? canvas.value.negativePrompt : '',
    });
    if (!ok) return false;
    store.savePromptMemory();
    window.location.href = '/?panel=concept&open=prompt';
    return true;
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
      // [Lưu kế hoạch sản xuất] Kế hoạch tính ở tab "Sản xuất & lãi" (store.plan) — gộp vào settings để
      // bộ sưu tập giữ trọn bản thiết kế (mood · palette · cơ cấu · kế hoạch), không chỉ dữ liệu brief.
      await store.createCollectionFromBrief({
        ...payload,
        settings: { ...(payload.settings || {}), plan: store.plan || null },
      });
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

  // ── LƯU NHÁP BỀN Ở MÁY (2026-09-24 · mở rộng 2026-09-25) ─────────────────────────────────
  // Đây là TẦNG THỨ NHẤT: cứu F5 và máy tự tải lại, chạy cả khi mất mạng / chưa đăng nhập lại được.
  // Tầng thứ hai (bền theo TÀI KHOẢN, mở máy khác vẫn thấy) nằm ở phiên làm việc — xem saveSession().
  // Giữ cả hai là cố ý: localStorage cứu ngay lập tức, phiên máy chủ cứu khi đổi thiết bị.
  const DRAFT_KEY = 'fabrikai.agentStudio.draft';
  function saveAgentDraft() {
    try {
      localStorage.setItem(DRAFT_KEY, JSON.stringify({
        v: 2,
        prompt: prompt.value,
        step: store.designAgentStep || 'dna',
        subSteps: subSteps.value,
        skuTotal: skuTotal.value,
        sizeRows: sizeRowsInput.value,
        paletteRows: paletteRows.value,
        moodRows: moodRows.value,
        structureRows: structureRowsInput.value,
        structureEdited: structureEdited.value,
        samples: samples.value,
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
      if (d.subSteps && typeof d.subSteps === 'object') subSteps.value = { ...subSteps.value, ...d.subSteps };
      if (Number(d.skuTotal) > 0) skuTotal.value = Number(d.skuTotal);
      if (Array.isArray(d.sizeRows) && d.sizeRows.length) sizeRowsInput.value = d.sizeRows;
      if (Array.isArray(d.structureRows) && d.structureRows.length) {
        structureEdited.value = !!d.structureEdited;
        seedStructureRows(d.structureRows);
      }
      if (Array.isArray(d.paletteRows)) paletteRows.value = d.paletteRows;
      if (Array.isArray(d.moodRows)) moodRows.value = d.moodRows;
      if (Array.isArray(d.samples)) samples.value = d.samples;
      if (Array.isArray(d.selectedTrendIds)) store.selectedTrendIds = d.selectedTrendIds.map(String);
    } catch (e) { /* bỏ qua bản nháp hỏng */ }
  }
  restoreAgentDraft();
  watch([
    prompt, () => store.designAgentStep, subSteps, skuTotal, sizeRowsInput, structureRowsInput,
    structureEdited,
    paletteRows, moodRows, samples, () => store.selectedTrendIds,
    // Bản brief cũng là TRẠNG THÁI PHIÊN: thiếu nó thì mọi thay đổi chỉ nằm trong bộ nhớ trang và mất
    // khi tải lại. Đây là bất biến, không phải đường đi — nên nó thuộc về danh sách này.
    collection,
  ], () => { saveAgentDraft(); scheduleSessionSave(); }, { deep: true });

  watch(prompt, () => { collectionError.value = ''; store.collectionBriefError = ''; });
  watch(collection, (value) => {
    const settings = value?.canvas || {};
    canvas.value = {
      ratio: RATIO_OPTIONS.includes(settings.ratio) ? settings.ratio : '4:5',
      variantCount: Math.max(1, Math.min(4, Number(settings.variant_count) || 2)),
      useNegative: !!settings.negative_prompt,
      negativePrompt: String(settings.negative_prompt || ''),
    };
    // Bảng cơ cấu luôn ĐỔ TỪ bản brief đang có, trừ khi người dùng đã tự sửa (lúc đó bảng của họ là
    // thứ đang chờ áp dụng, ghi đè lên là mất công họ vừa nhập).
    if (!structureEdited.value) seedStructureRows(value?.structure?.categories || []);
  }, { immediate: true });
  /**
   * NẠP LẦN ĐẦU — gọi MỘT lần khi trang Agent Studio dựng xong.
   *
   * [2026-09-25] Việc này trước đây nằm trong `watch(store.designAgentOpen)` vì Agent Studio là modal
   * trong /studio. Nay nó là một TRANG nên "mở" không còn là một sự kiện: giữ watcher thì thứ tự
   * (đặt cờ trước hay sau khi watch đăng ký) quyết định việc nạp có chạy hay không — đúng loại lỗi
   * im lặng. Gọi thẳng thì không phụ thuộc thứ tự.
   *
   * Nạp DNA + số đo khả năng internet NGAY: cả hai là dữ liệu người dùng phải nhìn thấy trước khi
   * tin vào phần phân tích phía sau.
   */
  function bootstrap() {
    if (!store.brandDna) store.loadBrandDna();
    if (!store.webAccess) store.loadWebAccess();
    if (!store.webSources) store.loadWebSources(false, selectedRegion.value);
    if (!store.trendRadar) loadRadar(selectedRegion.value);
    // PHIÊN LÀM VIỆC — nạp SAU cùng và không chặn: màn hình vẽ được ngay bằng bản nháp trên máy, phiên
    // của tài khoản về sau thì ghi đè. Chặn ở đây là mở trang phải chờ một vòng mạng mới thấy gì đó.
    loadSession().then((restored) => {
      if (restored) store.toast('Đã mở lại phiên làm việc đang dở của bạn.');
    });
  }


  // ══════════════════ PHIÊN LÀM VIỆC DAI DẲNG (tầng 2: theo TÀI KHOẢN) ══════════════════
  // Tầng 1 là bản nháp localStorage ở trên (cứu F5). Tầng này cứu thứ localStorage không cứu được:
  // đổi máy, đổi trình duyệt, xoá cache. Một bản nháp = MỘT phiên, lưu ở bảng projects (xem
  // AgentSessionController) nên phiên cũng chính là bộ sưu tập trong /bo-suu-tap.
  const sessionProjectId = ref(null);
  const sessionSavedAt = ref('');
  const sessionSaving = ref(false);
  const sessionError = ref('');
  const sessionClosed = ref(false);
  const sessionName = ref('');
  let sessionTimer = null;
  let sessionHydrating = false;
  /**
   * ĐÃ ĐỌC XONG phiên của tài khoản chưa?
   *
   * [LỖI THẬT — đo được 2026-09-21] Trang vừa dựng đã có thứ làm bộ theo dõi ghi phiên bắn lên (bản nháp
   * trên máy, hoặc chính bảng cơ cấu được đổ từ brief). Nếu lượt GHI đó chạy TRƯỚC khi lượt ĐỌC xong thì
   * nó ghi đè phiên cũ bằng trạng thái rỗng: `collection` còn null nên `brief_snapshot` thành null, và
   * người dùng mở lại trang thấy "Chưa có brief" — mất cả bộ sưu tập đang dựng. Đúng loại lỗi im lặng:
   * không có thông báo nào, chỉ có bài làm biến mất.
   *
   * Nên: cấm ghi cho tới khi biết mình đang ghi lên cái gì.
   */
  let sessionReady = false;

  /** Rút gọn brief trước khi lưu: chỉ giữ phần giao diện CẦN để vẽ lại màn hình đang làm dở. */
  function briefSnapshot(value) {
    if (!value) return null;
    return {
      brief: value.brief || '',
      prompt_vi: value.prompt_vi || '',
      prompt_en: value.prompt_en || '',
      canvas: value.canvas || null,
      palette: value.palette || [],
      moodboard: value.moodboard || null,
      structure: value.structure || null,
      size_distribution: value.size_distribution || [],
      price_bands: value.price_bands || null,
      outfit_matching: value.outfit_matching || [],
      brand_narrative: { narrative: value.brand_narrative?.narrative || '' },
      brand_dna: value.brand_dna || null,
      reference_style: value.reference_style || null,
      model: value.model || null,
      ai_applied: value.ai_applied || null,
      input: value.input || null,
      project_payload: value.project_payload || null,
      next_steps: value.next_steps || [],
      generated_at: value.generated_at || '',
    };
  }

  function sessionSnapshot() {
    return {
      version: 1,
      step: step.value,
      sub: sub.value,
      name: sessionName.value || collection.value?.project_payload?.name || '',
      prompt: prompt.value,
      region: selectedRegion.value,
      trend_ids: selectedTrendIds.value,
      sku_total: skuTotal.value || undefined,
      size_distribution: sizeDistribution.value,
      // Bảng cơ cấu + cờ "do người dùng đặt": mở lại phiên là bảng còn nguyên và vẫn được gửi lên,
      // không phải nhập lại từ đầu.
      structure_rows: structureRowsInput.value,
      structure_edited: structureEdited.value,
      palette: paletteRows.value,
      moodboard: moodRows.value,
      plan_assumptions: { ...store.planAssumptions },
      samples: samples.value,
      brief: collection.value?.brief || '',
      brief_snapshot: briefSnapshot(collection.value),
      brief_input: currentBriefInput.value,
    };
  }

  /** Lưu NGAY (không gộp). Trả về true khi ghi được. */
  async function saveSession() {
    if (sessionHydrating) return false;
    // Chưa đọc xong phiên thì MỌI lượt ghi đều là ghi đè mù — xem chú thích ở `sessionReady`.
    if (!sessionReady) return false;
    sessionSaving.value = true;
    sessionError.value = '';
    try {
      const body = { session: sessionSnapshot() };
      if (sessionProjectId.value) body.project_id = sessionProjectId.value;
      const res = await fetch('/api/design-agent/session', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': CSRF() },
        body: JSON.stringify(body),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data?.message || 'Không lưu được phiên làm việc.');
      sessionProjectId.value = data?.project?.id || sessionProjectId.value;
      sessionSavedAt.value = data?.saved_at || '';
      sessionClosed.value = !!data?.project?.closed;
      return true;
    } catch (e) {
      // Im lặng ở đây là cách tệ nhất: người dùng tin là đã lưu rồi đóng máy và mất bài.
      sessionError.value = userFacingError(e, 'Không lưu được phiên làm việc — bản nháp trên máy này vẫn còn.');
      return false;
    } finally {
      sessionSaving.value = false;
    }
  }

  /** Gộp nhiều thay đổi liên tiếp thành MỘT lần ghi — người dùng gõ phím thì không gọi mạng mỗi ký tự. */
  function scheduleSessionSave() {
    if (sessionHydrating) return;
    if (sessionTimer) clearTimeout(sessionTimer);
    sessionTimer = setTimeout(() => { sessionTimer = null; saveSession(); }, 1500);
  }

  function hydrateSession(session) {
    if (!session || typeof session !== 'object') return;
    sessionHydrating = true;
    try {
      if (typeof session.prompt === 'string' && session.prompt !== '') prompt.value = session.prompt;
      if (session.step && !stepLockedByUrl.value && STEPS.some((s) => s.id === session.step)) store.setDesignAgentStep(session.step);
      if (session.sub && typeof session.sub === 'string') setSub(session.sub);
      if (Number(session.sku_total) > 0) skuTotal.value = Number(session.sku_total);
      if (Array.isArray(session.size_distribution) || (session.size_distribution && typeof session.size_distribution === 'object')) {
        const rows = Array.isArray(session.size_distribution)
          ? session.size_distribution
          : Object.entries(session.size_distribution).map(([size, pct]) => ({ size, pct: Number(pct) }));
        if (rows.length) sizeRowsInput.value = rows;
      }
      if (Array.isArray(session.structure_rows) && session.structure_rows.length) {
        structureEdited.value = !!session.structure_edited;
        seedStructureRows(session.structure_rows);
      }
      if (Array.isArray(session.palette)) paletteRows.value = session.palette;
      if (Array.isArray(session.moodboard)) moodRows.value = session.moodboard;
      if (Array.isArray(session.samples)) samples.value = session.samples;
      if (session.plan_assumptions && typeof session.plan_assumptions === 'object') {
        store.planAssumptions = { ...store.planAssumptions, ...session.plan_assumptions };
      }
      if (session.name) sessionName.value = String(session.name);
      // Khôi phục BRIEF đã dựng: không có nó thì mở lại phiên là màn hình trắng và người dùng phải
      // chạy lại model (tốn ~28 giây + token) chỉ để nhìn lại thứ mình đã làm hôm qua.
      const snap = session.brief_snapshot;
      if (snap && typeof snap === 'object' && !collection.value) {
        // KHÔNG dựng lại `agent`/`engine` ở đây: hai trường đó là mã nội bộ của máy chủ, giao diện
        // không hiển thị chúng (UserFacingMessagesTest cấm lộ chữ kỹ thuật ra màn hình khách).
        store.collectionBrief = { ...snap, restored_from_session: true };
        if (session.brief_input) store.collectionBriefInput = store.designBriefInput(session.brief_input);
      }
    } finally {
      // Nhả cờ ở nhịp sau: watcher deep bắn ngay trong cùng tick với các phép gán trên, nên hạ cờ
      // đồng bộ sẽ để lọt một lượt ghi đè phiên vừa đọc lên chính nó.
      setTimeout(() => { sessionHydrating = false; }, 0);
    }
  }

  /** Nạp phiên đang mở của tài khoản. Không có phiên nào thì im lặng — người mới không cần thấy lỗi. */
  async function loadSession() {
    try {
      const res = await fetch('/api/design-agent/session', { headers: { Accept: 'application/json' } });
      if (!res.ok) return false;
      const data = await res.json().catch(() => ({}));
      sessionProjectId.value = data?.project?.id || null;
      sessionClosed.value = !!data?.project?.closed;
      sessionSavedAt.value = data?.project?.updated_at || '';
      if (data?.session) {
        hydrateSession(data.session);
        return true;
      }
      return false;
    } catch (e) {
      return false;
    } finally {
      // Mở cờ ở CẢ hai nhánh: người CHƯA có phiên nào cũng phải ghi được phiên đầu tiên của họ.
      sessionReady = true;
    }
  }

  /** CHỐT PHIÊN — lưu lần cuối rồi đóng. Bộ sưu tập vẫn nằm trong /bo-suu-tap. */
  async function closeSession() {
    if (!sessionProjectId.value && !(await saveSession())) return false;
    if (!sessionProjectId.value) return false;
    try {
      const res = await fetch('/api/design-agent/session/close', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': CSRF() },
        body: JSON.stringify({ project_id: sessionProjectId.value }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data?.message || 'Không chốt được phiên.');
      sessionClosed.value = true;
      store.toast('Đã lưu phiên vào bộ sưu tập «' + (data?.project?.name || '') + '». Mở lại được bất cứ lúc nào.');
      return true;
    } catch (e) {
      sessionError.value = userFacingError(e, 'Không chốt được phiên làm việc.');
      return false;
    }
  }

  // ══════════════════ MẪU: mỗi mã hàng một prompt ══════════════════
  /**
   * Dựng danh sách mẫu từ CƠ CẤU SKU người dùng đã chốt: mỗi mã hàng là một mẫu, size lấy theo vòng
   * từ bảng size (tỉ lệ size nào cao thì xuất hiện nhiều hơn).
   *
   * GIỮ NGUYÊN mẫu cũ theo id: dựng lại danh sách không được xoá prompt người dùng đã sinh và đã chốt.
   */
  function buildSamples() {
    const cats = categoryRows.value;
    if (!cats.length) {
      sampleError.value = 'Chưa có cơ cấu SKU — tạo brief ở bước Định hướng trước.';
      return;
    }
    const sizes = sizeRowsInput.value.length ? sizeRowsInput.value : [{ size: 'M', pct: 100 }];
    const pool = [];
    sizes.forEach((row) => {
      const weight = Math.max(1, Math.round((Number(row.pct) || 0) / 10));
      for (let i = 0; i < weight; i++) pool.push(String(row.size || 'M').toUpperCase());
    });

    const previous = new Map(samples.value.map((row) => [row.id, row]));
    const rows = [];
    let n = 0;
    cats.forEach((cat) => {
      const count = Math.max(0, Number(cat.count) || 0);
      for (let i = 1; i <= count; i++) {
        n += 1;
        const id = 'sku-' + n;
        const base = {
          id,
          name: String(cat.category || 'Mẫu') + ' #' + i,
          category: String(cat.category || ''),
          size: pool[(n - 1) % Math.max(1, pool.length)] || 'M',
          status: 'todo',
          prompt_vi: '', prompt_en: '', negative_prompt: '', note: '', context: '',
        };
        rows.push(previous.has(id) ? { ...base, ...previous.get(id), name: base.name, category: base.category, size: base.size } : base);
      }
    });
    samples.value = rows;
    sampleError.value = '';
  }

  /** Sinh prompt cho MỘT mẫu. Đây là hành động tốn lượt gọi model nên chỉ chạy khi người dùng bấm. */
  async function generateSamplePrompt(id) {
    const index = samples.value.findIndex((row) => row.id === id);
    if (index < 0) return false;
    if (prompt.value.trim().length < 3) {
      sampleError.value = 'Nhập mô tả bộ sưu tập ở bước Định hướng trước khi sinh prompt cho mẫu.';
      return false;
    }
    const row = samples.value[index];
    sampleBusyId.value = id;
    sampleError.value = '';
    samples.value = samples.value.map((r, i) => (i === index ? { ...r, status: 'generating', error: '' } : r));
    try {
      const data = await store.api('/api/design-agent/sample-prompt', {
        prompt: prompt.value.trim(),
        region: selectedRegion.value,
        trend_ids: selectedTrendIds.value,
        brief: collection.value?.brief || '',
        size_distribution: sizeDistribution.value,
        sku_total: skuTotal.value || undefined,
        palette: paletteRows.value,
        moodboard: moodRows.value,
        reference_images: (store.briefReferenceImages || []).slice(0, 3),
        ai: store.designAgentAi,
        sample: {
          id: row.id, name: row.name, category: row.category, size: row.size,
          index: index + 1, total: samples.value.length,
        },
      });
      samples.value = samples.value.map((r, i) => (i === index ? {
        ...r,
        status: 'todo',
        prompt_vi: data?.prompt_vi || '',
        prompt_en: data?.prompt_en || '',
        negative_prompt: data?.negative_prompt || '',
        note: data?.note || '',
        context: data?.photo_context || '',
        model_mode: data?.model?.mode || 'rule',
        model_note: data?.model?.note || '',
        generated_at: data?.generated_at || '',
      } : r));
      scheduleSessionSave();
      return true;
    } catch (e) {
      const text = userFacingError(e, 'Không sinh được prompt cho mẫu này.');
      sampleError.value = text;
      samples.value = samples.value.map((r, i) => (i === index ? { ...r, status: 'todo', error: text } : r));
      return false;
    } finally {
      sampleBusyId.value = '';
    }
  }

  /**
   * ĐƯA PROMPT CỦA MỘT MẪU SANG CANVAS — cùng đường đi với applyCanvas() nhưng nội dung là của mẫu đó.
   *
   * Prompt của mẫu đã có sẵn từ bước trước nên không tốn thêm gì; vẫn phải ghi BẢN BỀN trước khi điều
   * hướng vì store là bộ nhớ trong trang (xem applyCanvas).
   */
  function applySampleToCanvas(sample) {
    const value = String(sample?.prompt_vi || sample?.prompt_en || '').trim();
    if (!value) {
      sampleError.value = 'Mẫu này chưa có prompt — bấm «Sinh prompt» cho mẫu trước đã.';
      return false;
    }
    const ok = store.applyAgentPrompt(value, {
      ratio: canvas.value.ratio,
      variant_count: canvas.value.variantCount,
      negative_prompt: canvas.value.useNegative ? (sample.negative_prompt || canvas.value.negativePrompt) : '',
    });
    if (!ok) return false;
    store.savePromptMemory();
    window.location.href = '/?panel=concept&open=prompt';
    return true;
  }

  /** Người dùng QUYẾT ĐỊNH một mẫu đã xong (hoặc bỏ qua) — đây là bước chốt của từng mẫu. */
  function setSampleStatus(id, status) {
    const allowed = ['todo', 'done', 'skipped'];
    if (!allowed.includes(status)) return;
    samples.value = samples.value.map((row) => (row.id === id ? { ...row, status, error: '' } : row));
    scheduleSessionSave();
  }
  function editSamplePrompt(id, field, value) {
    if (!['prompt_vi', 'prompt_en', 'negative_prompt'].includes(field)) return;
    samples.value = samples.value.map((row) => (row.id === id ? { ...row, [field]: value } : row));
  }
  /**
   * Cung cấp bề mặt dùng chung cho 4 bước (components/agents/*.vue) — NGUYÊN VĂN hợp đồng cũ.
   * Danh sách này và object trả về bên dưới cùng rút từ MỘT nguồn nên không thể lệch nhau.
   */
  function provideAll(provide) {
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
    provide('aiTrendCount', aiTrendCount);
    provide('aiCheckedCount', aiCheckedCount);
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
    provide('modelNeedsAttention', modelNeedsAttention);
    provide('modelShort', modelShort);
    provide('modelTitle', modelTitle);
    provide('modelCandidates', modelCandidates);
    provide('toolSearch', toolSearch);
    provide('toolSearchLine', toolSearchLine);
    provide('aiSearchShort', aiSearchShort);
    provide('aiSearchQueries', aiSearchQueries);
    provide('aiSearchSources', aiSearchSources);
    provide('aiSearchItems', aiSearchItems);
    provide('aiSearchMode', aiSearchMode);
    provide('aiSearchOn', aiSearchOn);
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
    provide('SUBSTEPS', SUBSTEPS);
    provide('sub', sub);
    provide('subIndex', subIndex);
    provide('subList', subList);
    provide('setSub', setSub);
    provide('lockStepToUrl', lockStepToUrl);
    provide('subNext', subNext);
    provide('subPrev', subPrev);
    provide('skuTotal', skuTotal);
    provide('skuTotalSource', skuTotalSource);
    provide('structureRowsInput', structureRowsInput);
    provide('structureEdited', structureEdited);
    provide('structureCountTotal', structureCountTotal);
    provide('structureDirty', structureDirty);
    provide('setStructureRow', setStructureRow);
    provide('addStructureRow', addStructureRow);
    provide('removeStructureRow', removeStructureRow);
    provide('evenStructureRows', evenStructureRows);
    provide('normalizeStructureRows', normalizeStructureRows);
    provide('useAutoStructure', useAutoStructure);
    provide('sizeRowsInput', sizeRowsInput);
    provide('sizePctTotal', sizePctTotal);
    provide('sizeTotalOk', sizeTotalOk);
    provide('applySizePreset', applySizePreset);
    provide('addSizeRow', addSizeRow);
    provide('removeSizeRow', removeSizeRow);
    provide('setSizeRow', setSizeRow);
    provide('evenSizeRows', evenSizeRows);
    provide('paletteRows', paletteRows);
    provide('moodRows', moodRows);
    provide('ensurePaletteRows', ensurePaletteRows);
    provide('ensureMoodRows', ensureMoodRows);
    provide('resetPaletteRows', resetPaletteRows);
    provide('resetMoodRows', resetMoodRows);
    provide('addPaletteRow', addPaletteRow);
    provide('removePaletteRow', removePaletteRow);
    provide('setPaletteRow', setPaletteRow);
    provide('addMoodRow', addMoodRow);
    provide('removeMoodRow', removeMoodRow);
    provide('setMoodRow', setMoodRow);
    provide('moveMoodRow', moveMoodRow);
    provide('samples', samples);
    provide('sampleProgress', sampleProgress);
    provide('nextSample', nextSample);
    provide('sampleBusyId', sampleBusyId);
    provide('sampleError', sampleError);
    provide('buildSamples', buildSamples);
    provide('generateSamplePrompt', generateSamplePrompt);
    provide('setSampleStatus', setSampleStatus);
    provide('editSamplePrompt', editSamplePrompt);
    provide('applySampleToCanvas', applySampleToCanvas);
    provide('sessionProjectId', sessionProjectId);
    provide('sessionSavedAt', sessionSavedAt);
    provide('sessionSaving', sessionSaving);
    provide('sessionError', sessionError);
    provide('sessionClosed', sessionClosed);
    provide('saveSession', saveSession);
    provide('loadSession', loadSession);
    provide('closeSession', closeSession);
    provide('briefSnapshot', briefSnapshot);
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

  }

  return {
    store,
    bootstrap,
    saveAgentDraft,
    SUBSTEPS,
    sub,
    subIndex,
    subList,
    setSub,
    lockStepToUrl,
    subNext,
    subPrev,
    skuTotal,
    skuTotalSource,
    structureRowsInput,
    structureEdited,
    structureCountTotal,
    structureDirty,
    setStructureRow,
    addStructureRow,
    removeStructureRow,
    evenStructureRows,
    normalizeStructureRows,
    useAutoStructure,
    sizeRowsInput,
    sizePctTotal,
    sizeTotalOk,
    applySizePreset,
    addSizeRow,
    removeSizeRow,
    setSizeRow,
    evenSizeRows,
    paletteRows,
    moodRows,
    ensurePaletteRows,
    ensureMoodRows,
    resetPaletteRows,
    resetMoodRows,
    addPaletteRow,
    removePaletteRow,
    setPaletteRow,
    addMoodRow,
    removeMoodRow,
    setMoodRow,
    moveMoodRow,
    samples,
    sampleProgress,
    nextSample,
    sampleBusyId,
    sampleError,
    buildSamples,
    generateSamplePrompt,
    setSampleStatus,
    editSamplePrompt,
    applySampleToCanvas,
    sessionProjectId,
    sessionSavedAt,
    sessionSaving,
    sessionError,
    sessionClosed,
    saveSession,
    loadSession,
    closeSession,
    briefSnapshot,
    prompt,
    promptInput,
    collectionError,
    creatingCollection,
    trendQuery,
    trendCategory,
    liveOnly,
    lifecycleFilter,
    sizePreset,
    briefTab,
    canvasLang,
    canvas,
    refPickerOpen,
    shopPaste,
    shopParseError,
    shopOpen,
    STEPS,
    BRIEF_TABS,
    PLAN_FIELDS,
    SIZE_PRESETS,
    RATIO_OPTIONS,
    CATEGORY_LABELS,
    LIFECYCLE_LABELS,
    DNA_LISTS,
    dna,
    dnaDraft,
    dnaDirty,
    dnaBlockReason,
    dnaEmpty,
    refImages,
    referenceNote,
    liveSources,
    liveTrendCount,
    aiTrendCount,
    aiCheckedCount,
    newsItems,
    activeSourceCount,
    fetchedAtLabel,
    refreshLabel,
    autoRefresh,
    market,
    marketLive,
    marketSignals,
    marketTopics,
    marketPrices,
    marketAgeLabel,
    internetVerdict,
    groupsNeedingSetup,
    cacheAgeLabel,
    step,
    stepIndex,
    radar,
    collection,
    regions,
    selectedRegion,
    regionName,
    trends,
    sources,
    sourceMode,
    summaryItems,
    selectedTrendIds,
    selectedTrendCount,
    selectedTrendObjects,
    trendCategories,
    lifecycles,
    visibleTrends,
    palette,
    moodboardItems,
    categoryRows,
    outfitRows,
    sizeRows,
    priceBand,
    canvasSettings,
    currentBriefInput,
    briefStale,
    sizeDistribution,
    canvasPrompt,
    estimatedCredits,
    readiness,
    activeModel,
    modelReady,
    modelShort,
    modelTitle,
    modelNeedsAttention,
    modelCandidates,
    toolSearch,
    toolSearchLine,
    aiSearchShort,
    aiSearchQueries,
    aiSearchSources,
    aiSearchItems,
    aiSearchMode,
    aiSearchOn,
    aiToggleTitle,
    directions,
    appliedAi,
    briefModeMismatch,
    plan,
    planTotals,
    planWaves,
    planLines,
    planSizeChart,
    planScenarios,
    planInput,
    shopSummary,
    shopRowCount,
    dnaListMax,
    dnaListText,
    setDnaText,
    setDnaList,
    onPickReference,
    removeReference,
    shortDate,
    signalSamples,
    trendSignalLabel,
    sourceStatus,
    sourceStatusTone,
    directionConfidence,
    directionPriceLabel,
    trendNameById,
    focusDirections,
    toggleAi,
    schedulePlan,
    loadPlanNow,
    planFieldValue,
    importShopPaste,
    addShopRow,
    removeShopRow,
    saveShop,
    csvCell,
    downloadCsv,
    exportCutSheet,
    exportSizeChart,
    copyCutSheet,
    setStep,
    loadRadar,
    toggleTrend,
    clearTrends,
    selectSuggested,
    trendTitle,
    categoryLabel,
    lifecycleLabel,
    lifecycleClass,
    formatNumber,
    formatVnd,
    createBrief,
    advance,
    back,
    applyCanvas,
    createCollection,
    copyText,
    provideAll,
  };
}
