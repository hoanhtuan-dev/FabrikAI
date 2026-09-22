// TÁCH NGUYÊN VĂN từ store.js (đợt tối ưu 2026-09-24) — khối state() của studio store.
import { PLAN_ASSUMPTION_DEFAULTS, bootUser, bootProjectStatuses } from './helpers.js';
export function studioState() {
  return {
    step: 1,
    opening: false,
    defaultsLoaded: false,
    // [2026-09-17 · Đợt 0.1 — Q1] Trạng thái xác thực TƯỜNG MINH, để UI nói được ĐÚNG chuyện
    // đang xảy ra. Thay cho cờ `needsLogin` cũ — cờ đó KHÔNG được render ở đâu cả
    // (grep toàn bộ resources/js chỉ ra store.js) ⇒ người dùng tự đăng ký chỉ thấy toast nguyên
    // văn của backend "Bạn không có quyền truy cập khu vực quản trị."
    //   'ok'           — gọi API bình thường
    //   'guest'        — chưa đăng nhập (server không truyền user)
    //   'expired'      — đã đăng nhập nhưng phiên hết (401 / bị đá về /dang-nhap)
    //   'unauthorized' — đã đăng nhập nhưng KHÔNG đủ quyền (403)
    authState: bootUser() ? 'ok' : 'guest',
    // Người dùng đã đăng nhập (server truyền qua window.__STUDIO_BOOT__ ở vue.blade.php).
    user: bootUser(),
    previewId: null,
    preview: null,
    generations: [],
    creditsLeft: (bootUser() && Number(bootUser().credits_balance)) || 0,
    imageCreditCost: 1,  // chi phí credit cho 1 ảnh (load từ defaults)
    // ── Gói & credit (Đợt 1 — 2026-09-19) ──────────────────────────────────────────────
    // Trước đây SPA chỉ biết mỗi con số credit: không biết mình ở gói nào, mỗi thao tác tốn bao
    // nhiêu, được tối đa độ phân giải nào, và không có đường nâng cấp (grep 'billing' = 0).
    // planStatus nạp từ GET /api/plan/status — MỘT chỗ để UI nói đúng mọi thứ về gói.
    planStatus: null,
    // Tiến trình GỬI của lượt tạo hàng loạt (khác generateProgress = % RENDER thật của từng ảnh).
    batchSend: null,
    // Prompt của các mục LỖI trong lượt hàng loạt gần nhất — dùng cho nút "Chạy lại mục lỗi".
    batchFailed: [],
    // [Đợt 2] Thống kê chi phí/tiến độ theo từng bộ sưu tập (nạp khi cần, không nạp cả danh sách).
    projectStats: {},
    // [Đợt 2] Danh sách ẢNH của từng bộ sưu tập kèm trạng thái DUYỆT (shot_state) — phục vụ màn
    // "Duyệt mẫu theo lô". Nạp theo yêu cầu, nhớ theo id.
    projectShots: {},
    // ── PHIẾU KỸ THUẬT (tech pack) — Việc #3, 2026-09-26 ───────────────────────────────────
    // Thông số THẬT của một bộ sưu tập: vải, màu, đường may, bảng thông số theo size. Trước đây
    // "phiếu kỹ thuật" chỉ là tệp chữ có dòng chấm trong gói ZIP — chủ shop không có chỗ nào ghi.
    techPack: null,            // { tech_pack, is_set, updated_at, completeness, shape }
    techPackProjectId: null,   // phiếu đang mở (mỗi bộ sưu tập một phiếu — không lẫn giữa các bộ)
    techPackDraft: null,       // bản đang sửa (chỉ ghi DB khi bấm Lưu)
    techPackLoading: false,
    techPackSaving: false,
    techPackError: '',
    planOpen: false,        // popup "Gói & credit" ở thanh công cụ
    planCatalogOpen: false, // mở danh mục gói bên trong popup
    planBusy: false,
    // [Q2] YÊU CẦU NÂNG CẤP GÓI: chưa có cổng thanh toán nên nâng cấp là một yêu cầu có mã theo dõi
    // (chuyển khoản ngân hàng · VNPay khi mở · nhờ hỗ trợ), chủ dự án kích hoạt sau khi nhận tiền.
    upgradeOpen: false,
    upgradePlanId: null,
    upgradeBusy: false,
    upgradeResult: null,    // yêu cầu vừa gửi: { code, amount_label, method_label, ... }
    upgradeForm: { units: 1, method: 'bank_transfer', phone: '', name: '', note: '' },
    // [Modules 2026-09-19] QUYỀN THEO GÓI: module nào khách được dùng (từ /api/gui hoặc /api/boot).
    // Giao diện KHÔNG tự suy luận quyền — chỉ đọc đúng dữ liệu máy chủ trả về.
    modulesStatus: [],      // [{ id, name, group, kind, allowed, reason, depends_on }]
    modulesCatalog: [],     // danh mục đầy đủ (kể cả module không có nút)
    plansWithModuleMap: {}, // module id → [{ slug, name, price_label }] (gói nào đang cấp) — nạp khi cần
    // [Q4] NHÓM LÀM VIỆC THEO SỐ GHẾ: chủ nhóm mời/bỏ thành viên; thành viên dùng chung credit + bộ sưu tập.
    team: null,              // { seats, is_owner, owner, members: [...] }
    teamOpen: false,
    teamBusy: false,
    teamResult: null,        // { member, temp_password } — mật khẩu tạm hiện ĐÚNG MỘT LẦN
    teamForm: { name: '', email: '', phone: '' },
    // film / reframe share the source image (editSource || preview)
    editSource: null,
    texture: 5,
    // upscale params (fabric-weave slider removed — it affected dark skin & detail edges)
    upscaleScale: 2,
    upscaleRefine: 0,  // Tinh chỉnh AI: 0 = tắt, 1-10 = độ chi tiết (AI refine)
    vibrance: 3,       // Màu sống động: 0-10 (độ bão hòa màu, bảo vệ tone da)
    upscaling: false,
    // film look
    lookPreset: 'studio',
    lookLevel: 5,
    looking: false,
    // reframe
    reframeRatio: '3:4',
    reframing: false,
    cropMode: false,
    reframeOpen: false,  // hiển thị toolbar crop (dock phía trên)
    filmOpen: false,      // hiển thị toolbar film look (dock phía trên)
    cropBox: { x: 0.15, y: 0.15, w: 0.7, h: 0.7 },
    cvImg: null,
    canvasZoom: null,
    _cropDrag: null,
    _cropRaf: null,
    _cropPending: null,
    // canvas foundation (zoom/pan/background)
    zoom: 1,
    pan: { x: 0, y: 0 },
    canvasBg: 'grid',
    _drag: null,
    _layerDrag: null,
    snapX: null,
    snapY: null,
    snapGrid: 8, // lưới bắt điểm (px) khi kéo layer — mặc định BẬT 8px (0 = tắt)
    selectTool: false, // công cụ LỰA CHỌN: bật thì kéo vùng trống = quét chọn (còn lại = pan như cũ)
    panMode: false,   // công cụ DI CHUYỂN CANVAS (hand tool): khi bật, mọi kéo vùng trống = pan (giống Ctrl+Space). Trên desktop có thể dùng Space/Ctrl, trên tablet cần nút riêng.
    confirmDeleteOpen: false, // popup xác nhận xóa nhiều layer
    confirmClearCanvasOpen: false, // popup xác nhận dọn toàn bộ canvas
    _pinch: null,
    // Xóa vùng (erase) với feather
    eraseMode: false,
    eraseFeather: 15,       // độ mềm mép nét xóa (0-60)
    eraseBrushSize: 24,     // độ dày cọ xóa (px 3-150)
    _eraseCanvas: null,
    _eraseCtx: null,
    _eraseDrawing: false,
    _eraseLast: null,
    _eraseHasStrokes: false, // đã có nét thật? (tránh push history/bake khi không xoá gì)
    _eraseBusy: false,   // chống bake trùng khi đang áp dụng nét xóa
    // Vẽ tự do (paint brush) — tô màu lên layer
    drawMode: false,
    drawBrushSize: 24,   // độ dày cọ (px 3-150)
    drawOpacity: 1,      // độ đậm nét (0-1)
    drawSoftness: 15,    // độ mềm mép (0-60)
    drawHardness: 80,   // độ cứng cọ 0-100 (%)
    drawFlow: 1,        // lượng mực 0.01-1 (thấp = nét nhạt, tô dày dần)
    drawSpacing: 0.08,  // khoảng cách giữa các chấm cọ (tỉ lệ đường kính 0.03-1) — gần => mịn, không hạt
    drawSmoothing: 0,   // làm mượt nét 0-100 (0 = tắt)
    drawBlend: 'normal', // chế độ hòa trộn nét vẽ
    _drawCanvas: null,
    _drawCtx: null,
    _drawCursor: null,  // {x,y} client coords cho vòng cọ
    _drawSmooth: null,  // điểm đã làm mượt
    _drawDrawing: false,
    _drawLast: null,
    _drawHasStrokes: false, // đã có nét thật? (tránh push history/bake khi không vẽ gì)
    _drawPressure: 1,   // áp lực bút stylus (0-1) cho cọ vẽ
    _drawBusy: false,    // chống bake trùng khi đang áp dụng nét vẽ
    _flattenBusy: false, // đang chuẩn hoá transform layer (xoay/lật) — chống gọi song song
    // concept
    imagePromptEn: '',
    negativePromptEn: '',
    imagePoseId: '',          // Pose mẫu được chọn trong tab Tư thế (kế thừa từ chip Thử đồ)
    promptPrefix: '',      // Prompt prefix từ Settings (tự động thêm vào đầu) — đồng bộ 2 chiều
    promptSuffix: '',      // Prompt suffix từ Settings (tự động thêm vào cuối) — đồng bộ 2 chiều
    // ── Bật/tắt dùng Prompt Prefix · Prompt Suffix · Negative Prompt (ghi nhớ local, mặc định BẬT) ──
    promptUsePrefix: true,
    promptUseSuffix: true,
    promptUseNegative: true,
    creativeLevel: 6,
    variantCount: 1,
    imageRatio: '1:1',
    imageRes: '1K',
    imageSeed: '',          // Gieo quẻ: seed cố định để tạo ảnh nhất quán (để trống = random)
    generating: false,
    generateProgress: 0,      // 0-100 tiến trình generate (hoạt ảnh)
    generateStage: '',        // 'preparing' | 'enriching' | 'rendering' | 'done'
    generatedCount: 0,        // số ảnh đã tạo xong trong batch
    // ── Kiểm soát phom dáng nhân vật (inject vào prompt) ──
    bodyHeight: 5,       // 1-10: 1=rất thấp, 5=trung bình, 10=siêu cao
    bodyBuild: 5,         // 1-10: 1=siêu gầy, 5=cân đối, 10=đầy đặn/curvy
    bodyWaist: 5,         // 1-10: 1=eo to/straight, 5=cân đối, 10=eo siêu thon (hourglass)
    bodyShoulders: 5,     // 1-10: 1=hẹp, 5=cân đối, 10=rộng
    bodyHips: 5,          // 1-10: 1=hẹp, 5=cân đối, 10=rộng/pear
    hairStyle: '',        // tên kiểu tóc (để trống = không ép)
    hairColor: '',        // màu tóc (để trống = không ép)
    // palette / texture
    palette: [],
    // director
    videoModel: '',
    videoScenes: [],      // Kịch bản quay — preset video_scene từ Prompt Templates (Cài đặt)
    videoScene: '',
    inpaintEditPresets: [], // Sửa ảnh — preset chỉnh sửa (category inpaint) từ Prompt Templates
    videoDuration: '5',
    videoRes: '720',
    videoPromptEn: '',
    videoBusy: false,
    videoSourceId: null,
    // Compose progress (giống Inpaint)
    composeStage: '',     // '' | 'send' | 'processing' | 'done' | 'error' | 'cancelled'
    composeError: '',
    composeStartTs: 0,
    composeGenIds: [],    // generation ids của lần ghép hiện tại
    inpainting: false,
    inpaintPrompt: '',
    // Inpaint progress/status state (rõ ràng cho người dùng)
    inpaintGenId: null,       // generation đang chạy inpaint
    inpaintStage: '',         // '' | 'send' | 'processing' | 'done' | 'error' | 'cancelled'
    inpaintStartTs: 0,        // timestamp bắt đầu (đếm thời gian)
    inpaintError: '',         // thông báo lỗi cuối
    inpaintPreserveBg: true,  // giữ nguyên nền
    inpaintPreserveFace: true,// giữ nguyên khuôn mặt
    inpaintModels: [],        // các model chỉnh sửa được phép chọn (load từ /api/defaults)
    inpaintModel: '',         // model đang chọn cho card Sửa ảnh — '' = mặc định (Qwen Edit cấu hình)
    taskGroups: {},            // model theo nhóm công việc (image/edit/video/vision/prompt/translate) — load từ /api/defaults
    imageModelSel: '',         // model đang chọn cho Tạo Ảnh 2D + Ảnh mới từ ảnh mẫu ('' = default nhóm image)
    videoModelSel: '',         // model đang chọn cho Kịch bản quay ('' = default nhóm video)
    // ── Inpaint Mask (tích hợp region selection vào Inpaint) ──
    inpaintMaskMode: 'none',   // 'none' | 'rect' | 'brush' — chọn vùng cần sửa
    inpaintMaskBox: { x: 0.425, y: 0.425, w: 0.15, h: 0.15 }, // vùng mask mặc định = 15% ảnh (giữa), nhỏ để dễ thao tác
    inpaintBrushData: '',      // base64 PNG của brush mask
    _inpaintMaskCanvas: null,  // canvas DOM cho brush mask (tạm thời)
    _inpaintMaskCtx: null,     // 2d context
    _inpaintBrushDrawing: false,
    _inpaintBrushLast: null,
    inpaintErase: false,   // true = cọ tẩy (xoá nét đã vẽ thay vì tô thêm)
    inpaintBrushSize: 10,  // bán kính cọ vẽ (px trên canvas mask 512) — điều chỉnh được
    _inpaintUndoStack: [], // snapshot canvas trước mỗi nét — Ctrl+Z hoàn tác
    inpaintMaskDone: false, // đã bấm "Xong" — mask được LƯU để xử lý dù overlay đã tắt
    _inpaintMaskKind: '',   // 'rect' | 'brush' — loại mask đang được lưu để gửi khi Sửa ảnh
    _inpaintDrag: null,
    _inpaintHandle: null,
    _inpaintRaf: null,           // rAF id cho drag batching (mượt như crop)
    _inpaintPending: null,       // pointermove chờ flush
    _inpaintPrevBox: null,       // box trước khi bắt đầu vẽ vùng mới (để khôi phục nếu click nhầm)
    _inpaintDrew: false,         // đã kéo thật (>4px) khi vẽ vùng mới?
    inpaintFreehandPoints: [],    // path lasso đang vẽ [{nx, ny}] — hiển thị đường GIMP
    inpaintFreehandPaths: [],     // các nét lasso ĐÃ hoàn thành
    inpaintPathPoints: [],        // điểm neo {nx,ny,hx,hy} — h = tay điều khiển (normalized) cho Bezier curve selection (Krita Pen tool)
    inpaintPathRegions: [],       // các vùng path ĐÃ đóng (để preview hiển thị đủ nhiều vùng)
    _pathPending: null,           // neo đang kéo (khi vẽ Bezier)
    inpaintPathCloseHover: false, // hover gần điểm BẮT ĐẦU (snap để đóng kín) → highlight node đầu + guide
    _pathEditingRegion: -1,     // index vùng ĐÃ ĐÓNG đang được chỉnh sửa lại (-1 = không)
    _pathHoverRegion: -1,       // index vùng ĐÃ ĐÓNG đang HOVER (để hiện nút 'Sửa') — -1 = không
    _pathHoverPoint: null,      // điểm gần nhất trên đường bao vùng hover (anchor nút 'Sửa')
    magicTolerance: 32,           // ngưỡng màu cho Magic Wand (1-128)
    magicFeather: 0,             // độ mịn Magic Wand (blur px 0-20) — làm mềm mép vùng chọn
    _inpaintFreehandActive: false,
    inpaintFeather: 0,            // feather (px 0-50) — làm mềm mép vùng chọn
    inpaintFillColor: '#ffffff',  // màu tô cho nút " Tô" của vùng chọn
    inpaintSelectMode: 'new',   // lasso: 'new' | 'add' | 'subtract' — chế độ cộng/trừ vùng chọn
    inpaintMaskSource: 'inpaint',  // 'inpaint' (từ card Sửa ảnh) | 'canvas' (từ thanh công cụ vùng chọn trên canvas)
    // Canvas mask overlay (dùng chung cho Inpaint brush trên canvas chính)
    brushOverlay: null,
    _brushCanvas: null,
    _brushCtx: null,
    _brushDrawing: false,
    _brushLast: null,
    _pollTimers: {},          // single-flight poll per generation id
    suggesting: false,
    suggestResult: null,
    suggestEnabled: true,   // bật/tắt tính năng " Gợi ý từ ảnh" (cấu hình Studio)
    suggestLang: 'en',      // ngôn ngữ hiển thị mặc định (en | vi)
    suggestAdherence: 0,     // 0 = tự theo creative; 1..10 ép bám ảnh gốc (cao = tái tạo chính xác trang phục gốc)
    suggestDetailLevel: 8,
    suggestSkipHair: true,        // Bỏ qua phân tích kiểu tóc (mặc định bật)
    suggestSkipLogo: true,         // Bỏ qua logo, chữ, watermark (mặc định bật)
    suggestSkipBackground: true,   // Bỏ qua phân tích bối cảnh (mặc định bật)   // 1..10 mức chi tiết phân tích ảnh gốc (màu/đường may/hoạ tiết/độ dài/cổ/tay...)
    suggestSaving: false,       // đang lưu kết quả vào thư viện prompt
    // ── Tiến trình "Gợi ý từ ảnh" (stream NDJSON) — card hiện AI NÀO đang suy luận + giai đoạn ──
    suggestPhase: '',            // key giai đoạn: prepare | vision | fallback | color
    suggestPhaseLabel: '',       // nhãn tiếng Việt của giai đoạn hiện tại
    suggestProvider: '',         // provider đang suy luận (deepseek/qwen/gemini/slug custom…)
    suggestModel: '',            // model id đang chạy
    suggestStartedAt: 0,         // mốc bắt đầu (ms) — card tự đếm giây, không cần server
    suggestLastMeta: null,       // { provider, model, elapsed_ms } của lần chạy xong gần nhất
    suggestError: '',            // lỗi gần nhất để card hiện khối lỗi có ngữ cảnh
    // ── Danh sách 10 gợi ý từ ảnh MỚI NHẤT (bảng suggest_results của chính người dùng) ──
    suggestRecent: [],           // danh sách HIỂN THỊ = lịch sử client + kết quả đã lưu (server)
    suggestRecentServer: [],     // kết quả ĐÃ LƯU trong bảng suggest_results
    suggestLocalRecent: [],      // 10 phân tích gần nhất ở trình duyệt (chưa cần Lưu)
    suggestRecentTotal: 0,
    suggestRecentLoading: false,
    // ── Thư viện Prompt phân tích ( Gợi ý từ ảnh) — kế thừa pattern từ libraryItems ──
    suggestLibItems: [],
    suggestLibTotal: 0,
    suggestLibStats: null,
    suggestLibFilters: { q: '', garment_type: '', project_id: '', page: 1, per_page: 48 },
    suggestLibSort: 'newest', // 'newest' | 'oldest' | 'name_asc' | 'name_desc' | 'used_desc'
    suggestLibHasMore: false,
    suggestLibLoading: false,
    suggestLibSelection: [],   // danh sách id đang được chọn (checkbox)
    suggestLibManage: false,   // bật chế độ quản lý (chọn/xóa hàng loạt)
    promptOpen: false,
    // Agent thiết kế hợp nhất: TrendRadar → CollectionBot → Canvas.
    designAgentOpen: false,
    designAgentTab: 'trend',   // tương thích cũ: trend | collection
    designAgentStep: 'radar',   // wizard mới: radar → brief → canvas
    // Bật/tắt SUY LUẬN AI cho Agent Studio (nhóm công việc 'prompt'). Tắt ⇒ engine tất định,
    // nhanh và không tốn lượt gọi model — người dùng chủ động chọn.
    designAgentAi: true,
    // Kế hoạch SẢN XUẤT & LỢI NHUẬN (giá thành · lệnh cắt · đợt · bảng size) — tính TẤT ĐỊNH ở
    // backend từ chính đơn giá chủ xưởng nhập, nên đổi đơn giá là bấm tính lại, không tốn model.
    plan: null,
    planBasis: null,
    planLoading: false,
    // Tự tính lại (debounce khi gõ đơn giá) KHÔNG bật planLoading để nút không nhảy "Đang tính…" —
    // chỉ bật cờ này cho một chỉ báo mờ không đẩy layout.
    planRecalculating: false,
    planError: '',
    planAssumptions: { ...PLAN_ASSUMPTION_DEFAULTS },
    // Dữ liệu bán hàng THẬT của shop (nhập tay / dán Excel).
    shopRows: [],
    shopSignal: null,
    shopSaving: false,
    shopDataDirty: false,
    // STUDIO: catalog chip (từ Cài đặt của tôi) + setup + prompt dựng sẵn + bản sửa tay.
    sceneCatalog: null,
    scenePlan: null,
    sceneLoading: false,
    sceneError: '',
    sceneEditedPrompt: '',
    sceneSetup: { prompt: '', chips: [], variants: 1, ratio: '' },
    trendRadar: null,
    trendRadarCache: {},      // region → payload đã tải (tránh gọi lại khi đổi tab/đổi vùng)
    trendRadarRequest: 0,     // chống race: chỉ nhận kết quả của lần gọi MỚI NHẤT
    trendRadarLoading: false,
    trendRadarError: '',
    collectionBrief: null,
    collectionBriefLoading: false,
    collectionBriefError: '',
    collectionBriefInput: null, // input đã sinh brief hiện tại — để UI phát hiện brief cũ
    selectedTrendIds: [],
    // ── DNA THƯƠNG HIỆU (Đợt 22 — 2026-09-23) ──────────────────────────────────────────────
    // Hồ sơ chủ shop TỰ KHAI. Tách khỏi mọi thứ SUY RA (số bán, prompt cũ) vì hai nguồn này có độ
    // tin cậy khác nhau — giao diện phải nói rõ cái nào do người dùng khai.
    brandDna: null,          // { dna, is_set, updated_at, summary, labels, price_bands, limits }
    brandDnaLoading: false,
    brandDnaSaving: false,
    brandDnaError: '',
    brandDnaDraft: null,     // bản đang sửa (chỉ ghi vào DB khi bấm Lưu)
    // ── QUY TẮC LÀM VIỆC = TRÍ NHỚ THỦ TỤC (GĐ2 — 2026-09-26) ────────────────────────────
    // "Khi <tình huống> thì <cách làm>" do chủ shop đặt. Khác DNA: DNA là SỞ THÍCH phẳng, còn
    // đây là QUAN HỆ ĐIỀU KIỆN — thứ DNA không diễn đạt được.
    brandRules: null,        // { rules: [...], limits: {...} }
    brandRulesLoading: false,
    brandRulesSaving: false,
    brandRulesError: '',
    brandRulesDraft: [],     // bản đang sửa (mảng hàng; chỉ ghi vào DB khi bấm Lưu)
    // Khả năng truy cập internet của agent — ĐO THẬT, không phải câu văn tĩnh.
    webAccess: null,
    webAccessLoading: false,
    webAccessError: '',
    // ── NGUỒN DỮ LIỆU NGOÀI (Đợt 27) ──────────────────────────────────────────────────────
    // Máy chủ tự đi lấy tin RSS/JSON rồi đưa vào prompt kèm URL + thời điểm. Giao diện hiển thị NGUYÊN
    // TRẠNG thứ đang được dùng (nguồn nào chết, tin nào sắp vào prompt), không phải câu văn mô tả.
    webSources: null,
    webSourcesLoading: false,
    webSourcesError: '',
    // Ảnh mẫu cho VAI ĐỌC ẢNH (tối đa 3): người dùng chọn ở bước Định hướng, AI nhìn rồi bám phong cách.
    briefReferenceImages: [],
    viewer: null,
    flashMsg: '',
    flashType: 'info',
    _flashTimer: null,
    // [Trục 2 — 2026-09-20] Hàng đợi thông báo kiểu VSCode: nhiều thông báo cùng lúc, tự tắt theo
    // loại (lỗi giữ lâu hơn), có thể đóng tay, và KHÔNG nuốt thông báo này khi thông báo khác tới.
    // Trước đây flashMsg là MỘT ô duy nhất: toast sau ghi đè toast trước nên thông báo quan trọng
    // (vd "mục 3 lỗi") có thể biến mất trước khi người dùng đọc.
    notifications: [],
    _notifSeq: 0,
    _highlightTimer: null,
    lastBatch: [],
    showBatch: false,
    // ── Thư viện (/api/library) — quản lý + xóa ảnh cũ / ảnh rác ──
    libraryItems: [],
    libraryTotal: 0,
    libraryStats: null,
    libraryFilters: { type: '', status: '', project_id: '', q: '', page: 1, per_page: 48, old_days: 30 },
    librarySort: 'newest', // 'newest' | 'oldest' | 'name_asc' | 'name_desc' | 'cost_desc' | 'cost_asc'
    libraryHasMore: false,
    libraryLoading: false,
    librarySelection: [],   // danh sách id đang được chọn (checkbox)
    libraryScanning: false,
    libraryCleaning: false,
    libraryManage: false,   // bật chế độ quản lý (chọn/xóa hàng loạt)
    // ── Files đã tải lên — quản lý file tải lên + dọn file mồ côi ──
    libraryTab: 'generations', // 'generations' | 'uploads' | 'suggest' — tab Thư viện Prompt
    uploadSort: 'newest', // 'newest' | 'oldest' | 'name_asc' | 'name_desc' | 'size_desc' | 'size_asc' (client-side)
    libraryView: 'grid',   // 'grid' | 'list' — chế độ hiển thị chung cho cả 3 tab
    libraryGrid: 'm',      // 's' | 'm' | 'l' — cỡ lưới ảnh chung cho cả 3 tab
    studioView: 'studio',   // 'studio' | 'library' — view SPA hiện tại của /studio (Thư viện nhúng trong SPA)
    uploadItems: [],
    uploadStats: null,
    uploadLoading: false,
    uploadSelection: [],    // danh sách rel đang được chọn
    uploadCleaning: false,
    canvasLayers: [],
    layerGroups: [], // nhóm layer (group): { id, name, layerIds:[] } — mỗi layer có groupId
    activeLayerId: '',
    selectedLayerIds: [], // các layer được chọn (shift+click) ngoài active — hỗ trợ chọn/di chuyển nhiều layer
    // Panel Layers: dock phải khung canvas trên desktop, drawer đè canvas trên mobile.
    // Mặc định mở trên desktop, đóng trên mobile để không che canvas lúc vào trang.
    inspectorOpen: (typeof window !== 'undefined' && window.innerWidth < 1024) ? false : true,
    leftPanelOpen: true,   // sidebar card trái (ẩn/mở bằng nút chevron)
    outputDockOpen: true,  // dock phải Outputs (ẩn/mở)
    // [2026-09-20] BỀ RỘNG DOCK (px) — kéo được ở vách ngăn, xem composables/useDockResize.js.
    // Đây là NGUỒN SỰ THẬT duy nhất về bề rộng; composable kẹp lại trong [min, max] khi khôi
    // phục nên giá trị cũ/rác trong localStorage không phá bố cục. Mặc định giữ ĐÚNG bề rộng
    // đang dùng trước đây (w-72 = 288px · w-[156px]) để vào trang không thấy xa lạ.
    leftDockWidth: 288,
    outputDockWidth: 156,
    inspectorWidth: 256,   // bề rộng bảng Layers (dock phải trong khung canvas) — kéo được, nhớ lại
    sourcePickerOpen: false, // popup chọn nguồn ảnh (mở trực tiếp từ activity bar)
    undoStack: [],   // lịch sử hoàn tác (snapshot layers + activeLayerId)
    redoStack: [],   // lịch sử làm lại
    highlightLayerId: '',  // layer mới tạo cần viền nổi bật tạm thời
    imgTick: 0,            // tăng mỗi khi ảnh isolate load xong — overlay đo lại vị trí/kích thước
    // ── Dự án thiết kế (Project Workspace) — quản lý dự án cho Designer ──
    projects: [],                 // danh sách dự án (đã map)
    projectStatuses: bootProjectStatuses() || {},  // metadata trạng thái workflow (từ studioBoot)
    projectLoading: false,
    projectLoaded: false,
    activeProject: null,          // dự án đang XEM chi tiết trong workspace (tách khỏi appliedProject)
    activeProjectGenerations: [], // generations của dự án đang xem
    activeProjectReviewOnly: false, // true khi mở dự án của NGƯỜI KHÁC (scope=pending, Super Admin duyệt) → KHÔNG áp dụng cho phiên tạo ảnh
    appliedProject: null,         // "Bộ sưu tập hiện tại" — được ÁP DỤNG cho phiên tạo ảnh/video; tồn tại độc lập với việc đang mở workspace
    outputFilterProject: false,   // Outputs (Studio): chỉ hiện output thuộc dự án đang áp dụng
    viewerList: null,             // danh sách tùy chỉnh cho GalleryModal (vd: outputs của 1 dự án) — ưu tiên cao nhất trong viewerItems
    projectView: 'board',         // 'board' (kanban) | 'list'
    projectsArchived: false,      // lọc dự án đã lưu trữ
    projectScope: 'own',          // 'own' (bộ sưu tập của mình) | 'pending' (hàng đợi duyệt — Super Admin)
    assignableUsers: [],           // [P1.2] Danh sách người CÓ THỂ giao việc (chủ + thành viên nhóm).
    // [Đợt 2] Card trong sidebar render bằng <component:is> nên KHÔNG nhận prop/event; muốn mở
    // workspace Dự án từ card thì tăng bộ đếm này — StudioApp theo dõi và mở popup tương ứng.
    workspaceOpenRequest: 0,
    // [Trục 3 — 2026-09-20] Yêu cầu chuyển nhóm công cụ (activity) — xem requestActivity().
    activityRequest: { id: '', n: 0 },
    // [Trục 1 — 2026-09-20] Yêu cầu đổ prompt vào tab "Hàng loạt" của ConceptCard.
    batchFillRequest: { prompts: [], meta: null, n: 0 },
    // [Đợt 2] MẪU VIỆC THEO NGÀNH: danh sách mẫu (server là nguồn duy nhất) + mẫu đang chờ điền vào
    // gói xuất xưởng (bảng size/ghi chú kỹ thuật) khi người dùng mở khối "Xuất gói cho xưởng".
    jobTemplates: [],
    jobTemplatesLoaded: false,
    pendingExport: null,
    projectCanReview: false,      // true khi user là Super Admin (được duyệt/lưu trữ dự án của người khác)
    };
}
