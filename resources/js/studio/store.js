import { defineStore } from 'pinia';
import { markRaw } from 'vue';

const CSRF = () => {
  // Standalone SPA: Laravel đặt cookie XSRF-TOKEN (đã mã hoá) sau request GET đầu tiên (/api/boot).
  // Gửi lại qua header X-XSRF-TOKEN — VerifyCsrfToken sẽ tự giải mã để khớp token phiên.
  const m = (typeof document !== 'undefined' && document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)) || null;
  return m ? decodeURIComponent(m[1]) : '';
};

function readBoot() {
  return (typeof window !== 'undefined' && window.__STUDIO_BOOT__) || null;
}
function bootUser() {
  return (readBoot() && readBoot().user) || null;
}
function bootProjectStatuses() {
  return (readBoot() && readBoot().project_statuses) || null;
}

// Shared studio store — each card component reads/writes this so they can share data.
export const useStudioStore = defineStore('studio', {
  state: () => ({
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
    inpaintFillColor: '#ffffff',  // màu tô cho nút "🎨 Tô" của vùng chọn
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
    suggestEnabled: true,   // bật/tắt tính năng "💡 Gợi ý từ ảnh" (cấu hình Studio)
    suggestLang: 'en',      // ngôn ngữ hiển thị mặc định (en | vi)
    suggestAdherence: 0,     // 0 = tự theo creative; 1..10 ép bám ảnh gốc (cao = tái tạo chính xác trang phục gốc)
    suggestDetailLevel: 8,
    suggestSkipHair: true,        // Bỏ qua phân tích kiểu tóc (mặc định bật)
    suggestSkipLogo: true,         // Bỏ qua logo, chữ, watermark (mặc định bật)
    suggestSkipBackground: true,   // Bỏ qua phân tích bối cảnh (mặc định bật)   // 1..10 mức chi tiết phân tích ảnh gốc (màu/đường may/hoạ tiết/độ dài/cổ/tay...)
    suggestSaving: false,       // đang lưu kết quả vào thư viện prompt
    // ── Thư viện Prompt phân tích (💡 Gợi ý từ ảnh) — kế thừa pattern từ libraryItems ──
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
    // [Đợt 2] Card trong sidebar render bằng <component :is> nên KHÔNG nhận prop/event; muốn mở
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
  }),
  getters: {
    /** Tên gói đang dùng (hiển thị cạnh số credit). */
    planName() { return (this.planStatus && this.planStatus.plan) ? this.planStatus.plan.name : ''; },
    /** Chi phí credit theo GÓI (server trả) — fallback về giá trị mặc định đã nạp. */
    planCostImage() { return (this.planStatus && this.planStatus.costs && this.planStatus.costs.image) || this.imageCreditCost || 1; },
    planCostVideo() { return (this.planStatus && this.planStatus.costs && this.planStatus.costs.video) || 10; },
    /** Sắp cạn credit: còn ít hơn 3 thao tác ảnh ⇒ tô đậm nút để khách biết trước. */
    creditsLow() { return this.creditsLeft < this.planCostImage * 3; },
    upscaleSrc() { if (this.activeLayerId) { const l = this.canvasLayers.find(x => x.id === this.activeLayerId && x.visible !== false); if (l && l.image) return l.image; } return (this.editSource && this.editSource.url) || (this.preview && this.preview.media_url) || ''; },
    upscaleName() { if (this.activeLayerId) { const l = this.canvasLayers.find(x => x.id === this.activeLayerId); if (l) return l.name; } return (this.editSource && this.editSource.name) || (this.preview ? 'Ảnh kết quả #' + this.preview.id : 'Ảnh đang chọn'); },

    activeBatch() { return this.generations.filter(g => this.lastBatch.includes(g.id)); },
    // Nguồn ảnh cho GalleryModal: viewerList (context riêng, vd outputs của dự án) →
    // thư viện (nếu đang ở /api/library) → outputs Studio.
    viewerItems() {
      if (this.viewerList && this.viewerList.length) return this.viewerList.filter(g => g.media_url);
      return (this.libraryItems && this.libraryItems.length) ? this.libraryItems.filter(g => g.media_url) : this.generations.filter(g => g.media_url);
    },
    // Outputs hiển thị ở Studio: lọc theo dự án đang áp dụng khi bật toggle.
    visibleGenerations() {
      const pid = this.appliedProjectId();
      if (this.outputFilterProject && pid) return this.generations.filter(g => Number(g.project_id) === Number(pid));
      return this.generations;
    },
    activeLayer() { return this.canvasLayers.find(x => x.id === this.activeLayerId) || null; },
    // ── Chọn nhiều layer (shift+click) ──
    selectedIds() { const a = [this.activeLayerId, ...this.selectedLayerIds].filter(Boolean); return [...new Set(a)]; },
    selection() { return this.canvasLayers.filter(l => this.selectedIds.includes(l.id) && l.visible !== false); },
    selectionCount() { return this.selection.length; },
    selectionUnitCount() { return this._selectionUnits().length; },
    // Nhãn các ĐỐI TƯỢNG đang chọn (group = 1 đối tượng) — popup xác nhận xóa đọc từ đây để nói
    // RÕ sắp xóa cái gì, thay vì chỉ hỏi "xóa 3 đối tượng?" mà người dùng không biết là cái nào.
    selectionUnitLabels() {
      return this._selectionUnits().map((u) => {
        if (u.type === 'group') return 'Nhóm ' + (this.groupLabel(u.layers[0]) || 'không tên');
        const l = u.layers[0] || {};
        return l.name || 'Layer';
      });
    },
    // Số đối tượng đang chọn bị KHÓA — hiện trước khi bấm xóa để không "xóa mà im lặng bỏ qua".
    lockedSelectionCount() { return this._selectionUnits().filter((u) => u.layers.some((l) => l.locked)).length; },
    /**
     * Ảnh của layer ĐANG CHỌN đã có trong Output chưa?
     *
     * Vì sao cần: nút "Lưu Output" phải SÁNG khi có ảnh MỚI và phải im khi ảnh đã có — nếu không,
     * người dùng bấm Lưu nhiều lần cho cùng một ảnh và Outputs đầy bản trùng. Ba cách nhận biết, theo
     * đúng thứ tự danh tính của ảnh:
     *   1. Đã lưu trong phiên này (l.savedOutputId) → chắc chắn trùng, không cần đoán;
     *   2. Layer vốn là một ảnh KẾT QUẢ (l.genId) → trùng khi generation đó còn trong danh sách;
     *   3. Ảnh đã nằm trên máy chủ (/storage/…) → so ĐƯỜNG DẪN với media_url của các kết quả.
     * Layer ghép cục bộ (data URL) chưa từng lưu thì không thể trùng ⇒ trả false.
     */
    activeLayerInOutputs() {
      const l = this.activeLayer;
      if (!l || !l.image) return false;
      if (l.savedOutputId && this.generations.some((g) => Number(g.id) === Number(l.savedOutputId))) return true;
      if (l.genId && this.generations.some((g) => Number(g.id) === Number(l.genId))) return true;
      const img = String(l.image);
      const isServerUrl = img.startsWith('/storage/') || (typeof location !== 'undefined' && img.startsWith(location.origin + '/storage/'));
      if (!isServerUrl) return false;
      const rel = typeof location !== 'undefined' ? img.replace(location.origin, '') : img;
      return this.generations.some((g) => g.media_url && String(g.media_url).replace(typeof location !== 'undefined' ? location.origin : '', '') === rel);
    },
    // Có ảnh MỚI để lưu vào Output không (nút "Lưu Output" chỉ sáng khi true).
    canSaveActiveLayerToOutput() { return !!(this.activeLayer && this.activeLayer.image) && !this.activeLayerInOutputs; },
    visibleLayers() { return this.canvasLayers.filter(l => l.visible !== false); },
    // Danh sách layer hiển thị front-first (layer TRƯỚC NHẤT ở trên cùng) — chuẩn trình chỉnh
    // ảnh. canvasLayers giữ thứ tự vẽ (zIndex), getter này chỉ đảo để hiển thị panel.
    layersFrontFirst() { return this.canvasLayers.slice().reverse(); },
    // Kích thước LAYOUT (CSS px, trước transform) của <img> layer đang active — khớp thẻ hiển thị
    // (cạnh dài tối đa 512). Dùng làm khung toạ độ cho overlay preview neo vào layer.
    frameLayout() {
      const l = this.activeLayer;
      let w = l ? Number(l.baseW) : 0, h = l ? Number(l.baseH) : 0;
      if ((!w || w < 1 || !h || h < 1) && this.cvImg) {
        const nw = this.cvImg.naturalWidth || 0, nh = this.cvImg.naturalHeight || 0;
        if (nw > 1 && nh > 1) { const cap = Math.min(1, 512 / nw, 512 / nh); w = nw * cap; h = nh * cap; }
      }
      return (w > 0 && h > 0) ? { w, h } : null;
    },
  },
  actions: {
    /**
     * [Đợt 0.1] Cập nhật trạng thái xác thực từ mã HTTP. Một chỗ duy nhất để mọi đường
     * gọi API cùng phân loại 401/403 giống nhau — tránh mỗi call-site đoán một kiểu.
     * @param {number} status mã HTTP (hoặc 200 khi thành công)
     */
    setAuthStatus(status) {
      if (status === 403) {
        // Đã đăng nhập nhưng không đủ quyền — KHÁC hẳn "chưa đăng nhập".
        this.authState = 'unauthorized';
      } else if (status === 401) {
        // Có user trong boot nhưng bị 401 ⇒ phiên đã hết. Không có user ⇒ khách.
        this.authState = this.user ? 'expired' : 'guest';
      } else if (status === 200) {
        this.authState = 'ok';
      }
    },
    async api(url, body = {}, signal = null) {
      const res = await fetch(url, { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body), signal });
      const ct = res.headers.get('content-type') || '';
      const data = await res.json().catch(() => ({}));
      // M04: hết phiên -> Laravel redirect về /dang-nhap và trả HTML 200. Nếu chỉ check res.ok thì
      // HTML đó parse thành {} và MỌI caller mutation tưởng là thành công. Guard y như _libraryFetch
      // và deleteGen.
      if (!res.ok || res.redirected || !ct.includes('application/json')) {
        // redirected ⇒ Laravel đá về /dang-nhap và trả HTML ⇒ coi như HẾT PHIÊN, không phải 403.
        if (res.redirected || (res.url && res.url.includes('/dang-nhap'))) this.setAuthStatus(401);
        else this.setAuthStatus(res.status);
        // [Q1 — 2026-09-19] Hết credit (402 code=out_of_credits) ⇒ MỞ THẲNG bảng nâng cấp kèm danh mục
        // gói, thay vì để khách đọc một câu lỗi rồi không biết bấm vào đâu. Xử lý ở MỘT chỗ này nên
        // mọi đường tạo ảnh/video (8 endpoint) đều có cùng trải nghiệm.
        // [Modules] 403 module_locked ⇒ mở bảng nâng cấp (giống hết cách xử lý hết credit).
        if (res.status === 403 && data && data.code === 'module_locked') {
          this.planOpen = true;
          this.planCatalogOpen = true;
          this.upgradeOpen = false;
          this.loadPlanStatus(true);
        }
        if (res.status === 402 && data && data.code === 'out_of_credits') {
          this.planOpen = true;
          this.planCatalogOpen = true;
          this.upgradeOpen = false;
          this.loadPlanStatus(true);
        }
        const err = new Error(data.message || 'Phiên đăng nhập đã hết hoặc máy chủ trả dữ liệu không hợp lệ — hãy tải lại trang.');
        err.status = res.status;
        err.code = data && data.code;
        err.data = data;
        throw err;
      }
      return data;
    },
    /**
     * Dự án đang áp dụng — gửi kèm MỌI thao tác SINH ẢNH để kết quả nằm đúng bộ sưu tập.
     *
     * Vì sao: trước đây chỉ tạo ảnh mới và video gửi `project_id`; sửa ảnh · biến thể · xoá nền ·
     * ghép · nâng cấp · look · sửa vùng · cắt đều KHÔNG gửi, nên kết quả rơi ra ngoài bộ sưu tập
     * dù người dùng đang áp dụng một dự án.
     *
     * Gửi null khi không áp dụng dự án: server hiểu là "không chỉ định" và sẽ THỪA HƯỞNG dự án của
     * ảnh nguồn — sửa một ảnh thuộc bộ sưu tập nào thì kết quả ở lại bộ sưu tập đó.
     */
    projectField() { return { project_id: this.appliedProjectId() }; },
    addGen(g) {
      // Ảnh vừa tạo phải mang theo dự án NGAY, không chờ lần nạp lại kế tiếp: thiếu trường này thì
      // nó vô hình với bộ lọc "chỉ outputs của dự án đang áp dụng" cho tới khi /api/latest về.
      if (g.project_id === undefined) {
        const pid = this.appliedProjectId();
        if (pid) { g.project_id = pid; g.project = this.appliedProject ? this.appliedProject.name : null; }
      }
      const existing = this.generations.find(x => x.id === g.id);
      if (existing) Object.assign(existing, g);
      else this.generations.unshift(g);
      this.previewId = g.id;
      if (g.status === 'completed') { this.preview = { id: g.id, media_url: g.media_url, type: 'image', status: 'completed' }; }
      if (g.media_url) { this.pushCanvasLayer(String(g.id), 'gen', 'Ảnh #' + g.id, g.media_url, g.id); this.setActiveLayer(String(g.id)); }
    },
    setPreview(g) { if (g) { this.previewId = g.id; this.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed' }; } },
    /**
     * Nạp trạng thái GÓI của chính người dùng: gói · hạn mức · chi phí · danh mục gói.
     * Server cũng cấp credit theo chu kỳ ở đây (idempotent) nên số dư trả về luôn là số thật.
     */
    async loadPlanStatus(force = false) {
      // force=true: gọi lại sau khi gửi yêu cầu nâng cấp (yêu cầu đang mở phải hiện ngay).
      if (!this.user || (this._planLoading && !force)) return;
      this._planLoading = true;
      try {
        const res = await fetch('/api/plan/status', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const d = await res.json();
        this.planStatus = d;
        if (d.credits && d.credits.balance != null) this.creditsLeft = Number(d.credits.balance);
        if (d.costs && d.costs.image) this.imageCreditCost = Number(d.costs.image);
      } catch (e) { console.error('loadPlanStatus failed', e); }
      finally { this._planLoading = false; }
    },
    togglePlanPopover() {
      this.planOpen = !this.planOpen;
      if (this.planOpen) { this.loadPlanStatus(); this.loadTeam(); }
      else { this.planCatalogOpen = false; }
    },
    /**
     * [Q2] Mở form YÊU CẦU NÂNG CẤP cho một gói (gói trả phí không tự kích hoạt được nữa).
     * Điền sẵn tên/SĐT nếu đã biết để khách không phải gõ lại.
     */
    openUpgrade(plan) {
      if (!plan) return;
      this.upgradePlanId = plan.id;
      this.upgradeResult = null;
      this.upgradeOpen = true;
      if (!this.upgradeForm.name) this.upgradeForm.name = this.user?.name || '';
      // Mốc mua đầu tiên của CHÍNH GÓI đó (gói tháng ⇒ 1 tháng, gói xưởng ⇒ 1 vụ).
      this.upgradeForm.units = (plan.units && plan.units.length) ? Number(plan.units[0]) : 1;
      this.upgradeForm.method = 'bank_transfer';
    },
    closeUpgrade() { this.upgradeOpen = false; this.upgradePlanId = null; },
    // ── [Q4] Nhóm làm việc theo số ghế ───────────────────────────────────────────────────
    /** Nạp tình trạng nhóm: tổng ghế · đã dùng · danh sách thành viên (một lời gọi duy nhất). */
    async loadTeam(force = false) {
      if (!this.user) return null;
      if (!force && this.team) return this.team;
      try {
        const r = await fetch('/api/team', { headers: { Accept: 'application/json' } });
        if (!r.ok) return null;
        const d = await r.json();
        this.team = d;
        return d;
      } catch (e) { console.error('loadTeam failed', e); return null; }
    },
    toggleTeam() {
      this.teamOpen = !this.teamOpen;
      if (this.teamOpen) { this.teamResult = null; this.loadTeam(true); }
    },
    /**
     * Mời một thành viên vào nhóm. Mật khẩu tạm do MÁY CHỦ sinh và chỉ trả về đúng lần này — giao diện
     * hiển thị để chủ nhóm gửi cho nhân viên, không lưu lại ở đâu khác.
     */
    async inviteMember() {
      if (this.teamBusy) return null;
      const email = String(this.teamForm.email || '').trim();
      if (!email) { this.toast('Nhập email của thành viên.', 'error'); return null; }
      this.teamBusy = true;
      try {
        const d = await this.api('/api/team/members', {
          email,
          name: this.teamForm.name || null,
          phone: this.teamForm.phone || null,
        });
        this.teamResult = { member: d.member, temp_password: d.temp_password };
        this.teamForm = { name: '', email: '', phone: '' };
        await this.loadTeam(true);
        if (d.seats) this.team = { ...(this.team || {}), seats: d.seats };
        this.toast(d.message || ('Đã thêm ' + (d.member?.name || '') + ' vào nhóm.'), 'success');
        return d;
      } catch (e) {
        this.toast(e.message || 'Không thêm được thành viên.', 'error');
        return null;
      } finally { this.teamBusy = false; }
    },
    /** Bỏ một thành viên khỏi nhóm (giải phóng ghế). Tài khoản + ảnh đã tạo vẫn giữ nguyên. */
    async removeMember(id) {
      if (this.teamBusy || !id) return null;
      this.teamBusy = true;
      try {
        const d = await this.api('/api/team/members/' + id, { _method: 'DELETE' });
        await this.loadTeam(true);
        if (d.seats) this.team = { ...(this.team || {}), seats: d.seats };
        this.toast(d.message || 'Đã bỏ thành viên khỏi nhóm.', 'info');
        return d;
      } catch (e) {
        this.toast(e.message || 'Không bỏ được thành viên.', 'error');
        return null;
      } finally { this.teamBusy = false; }
    },
    /**
     * Gửi yêu cầu nâng cấp. Máy chủ kiểm gói/số tháng/phương thức/SĐT và trả MÃ THEO DÕI; ở đây chỉ
     * hiển thị đúng những gì máy chủ trả về (không tự bịa mã, không tự bịa số tiền).
     */
    async submitUpgrade() {
      if (this.upgradeBusy || !this.upgradePlanId) return null;
      this.upgradeBusy = true;
      try {
        const d = await this.api('/api/billing/upgrade-request', {
          plan_id: this.upgradePlanId,
          units: Number(this.upgradeForm.units) || 1,
          method: this.upgradeForm.method,
          contact_name: this.upgradeForm.name || null,
          contact_phone: this.upgradeForm.phone,
          note: this.upgradeForm.note || null,
        });
        this.upgradeResult = d.request || null;
        if (d.payment) this.planStatus = { ...(this.planStatus || {}), payment: d.payment };
        await this.loadPlanStatus(true);
        this.toast(d.message || ('Đã gửi yêu cầu ' + (d.request?.code || '')), d.reused ? 'info' : 'success');
        return d;
      } catch (e) {
        this.toast(e.message || 'Không gửi được yêu cầu nâng cấp.', 'error');
        return null;
      } finally {
        this.upgradeBusy = false;
      }
    },
    async subscribePlan(planId) {
      if (this.planBusy) return;
      this.planBusy = true;
      try {
        const res = await fetch('/api/billing/subscribe', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ plan_id: planId }),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) {
          // [Q2] Gói trả phí không tự kích hoạt được nữa: máy chủ trả 402 payment_required ⇒ mở ngay
          // form YÊU CẦU NÂNG CẤP cho đúng gói khách vừa chọn (không bắt họ đi tìm lại).
          if (res.status === 402 && d.code === 'payment_required') {
            const plan = (this.planStatus?.catalog || []).find((x) => Number(x.id) === Number(planId));
            this.toast('Gói trả phí cần xác nhận thanh toán — điền thông tin để FabrikAI liên hệ.', 'info');
            this.openUpgrade(plan || { id: planId });
            return;
          }
          throw new Error(d.message || ('HTTP ' + res.status));
        }
        await this.loadPlanStatus(true);
        this.toast('Đã chuyển sang gói ' + ((d.plan && d.plan.name) || ''), 'info');
      } catch (e) {
        this.toast('Không đổi được gói: ' + e.message, 'error');
      }
      this.planBusy = false;
    },
    // ── [Modules] Quyền theo gói ─────────────────────────────────────────────────────────
    /** Nhận danh sách quyền module từ máy chủ (/api/gui hoặc /api/boot). */
    setModuleAccess(status, allowedIds, catalog) {
      if (Array.isArray(status) && status.length) this.modulesStatus = status;
      else if (Array.isArray(allowedIds)) {
        // /api/boot chỉ trả id được phép ⇒ đánh dấu phần còn lại là bị khoá (chi tiết lấy sau từ /api/gui).
        this.modulesStatus = this.modulesStatus.length
          ? this.modulesStatus
          : allowedIds.map((id) => ({ id, name: id, allowed: true, reason: null, depends_on: [] }));
      }
      if (Array.isArray(catalog) && catalog.length) this.modulesCatalog = catalog;
    },
    /** Module có bị KHOÁ theo gói không? (chưa biết ⇒ coi như mở, để không chặn nhầm người dùng). */
    moduleLocked(id) {
      const row = this.modulesStatus.find((m) => m.id === id);
      return !!row && row.allowed === false;
    },
    moduleInfo(id) {
      return this.modulesStatus.find((m) => m.id === id) || this.modulesCatalog.find((m) => m.id === id) || null;
    },
    /** Gói nào đang cấp module này — lấy từ /api/plan/status (danh mục gói) để mời nâng cấp cho đúng. */
    plansWithModule(id) {
      const plans = (this.planStatus && this.planStatus.catalog) || [];
      return plans.filter((p) => Array.isArray(p.modules) && p.modules.includes(id));
    },
    /** Số module được cấp / tổng — hiển thị trong popup gói. */
    moduleCounts() {
      const total = this.modulesStatus.length || this.modulesCatalog.length || 0;
      const allowed = this.modulesStatus.filter((m) => m.allowed).length;
      return { allowed, total };
    },
    toast(msg, type = 'info', opts = {}) {
      // Use the Vue studio's own toast (works standalone); fall back to Alpine if present.
      this.flashMsg = msg; this.flashType = type;
      if (this._flashTimer) clearTimeout(this._flashTimer);
      this._flashTimer = setTimeout(() => { this.flashMsg = ''; }, 2600);
      if (window.Alpine?.store?.('toast')) window.Alpine.store('toast').show(msg, type);
      // [Trục 2] Đẩy vào hàng đợi thông báo. Lỗi giữ lâu hơn để kịp đọc và còn dấu vết sau khi tắt.
      this.notify(msg, type, opts);
    },
    /**
     * [Trục 2 — 2026-09-20] Thông báo kiểu VSCode: xếp chồng ở góc phải-dưới, tự tắt theo loại,
     * đóng tay được. Giữ tối đa 4 mục để không che canvas.
     */
    notify(msg, type = 'info', opts = {}) {
      const text = String(msg == null ? '' : msg);
      if (!text) return null;
      const id = ++this._notifSeq;
      const ttl = opts.sticky ? 0 : (opts.ttl != null ? opts.ttl : (type === 'error' ? 8000 : 4200));
      this.notifications.push({ id, msg: text, type, ttl, action: opts.action || null, at: Date.now() });
      // Giữ 4 mục gần nhất — mục cũ nhất tự rụng (không để hàng đợi phình vô hạn).
      if (this.notifications.length > 4) this.notifications.splice(0, this.notifications.length - 4);
      return id;
    },
    dismissNotification(id) {
      const i = this.notifications.findIndex((n) => n.id === id);
      if (i !== -1) this.notifications.splice(i, 1);
    },
    clearNotifications() { this.notifications = []; },
    async loadDefaults() {
      // §5.2: trước đây catch nuốt lỗi hoàn toàn (không log) -> /api/defaults fail thì người dùng
      // chỉ thấy giá trị mặc định mà không có dấu vết nào để chẩn đoán.
      try {
        const cfg = await fetch('/api/defaults', { headers: { Accept: 'application/json' } });
        const defaults = await cfg.json();
        this._applyDefaultValues(defaults);
        this.defaultsLoaded = true;
        return defaults;
      } catch (e) { console.error('studio loadDefaults failed', e); return null; }
      finally {
        // ƯU TIÊN local (luôn chạy, kể cả khi fetch defaults lỗi): khôi phục cài đặt prompt
        // người dùng đã lưu — ghi đè giá trị từ database.
        this.restorePromptMemory();
      }
    },
    _applyDefaultValues(defaults) {
      if (!defaults) return;
      if (defaults.creative_level != null) this.creativeLevel = Number(defaults.creative_level);
      if (defaults.texture != null) this.texture = Number(defaults.texture);
      if (defaults.image_resolution) this.imageRes = defaults.image_resolution;
      if (defaults.image_ratio) this.imageRatio = defaults.image_ratio;
      if (defaults.video_duration) this.videoDuration = defaults.video_duration;
      if (defaults.video_resolution) this.videoRes = defaults.video_resolution;
      if (defaults.negative_prompt !== undefined) this.negativePromptEn = defaults.negative_prompt;
      if (defaults.prompt_prefix !== undefined) this.promptPrefix = defaults.prompt_prefix;
      if (defaults.prompt_suffix !== undefined) this.promptSuffix = defaults.prompt_suffix;
      // 💡 Gợi ý từ ảnh — trạng thái + ngôn ngữ + độ bám/chi tiết mặc định.
      if (defaults.suggest_enabled !== undefined) this.suggestEnabled = !!defaults.suggest_enabled;
      if (defaults.suggest_default_lang) this.suggestLang = defaults.suggest_default_lang === 'vi' ? 'vi' : 'en';
      if (defaults.suggest_adherence != null) this.suggestAdherence = Number(defaults.suggest_adherence);
      if (defaults.suggest_detail_level != null) this.suggestDetailLevel = Number(defaults.suggest_detail_level);
      if (defaults.image_credits != null) this.imageCreditCost = Number(defaults.image_credits);
      // Card Sửa ảnh: danh sách model chỉnh sửa (mặc định đứng đầu).
      if (Array.isArray(defaults.inpaint_models)) this.inpaintModels = defaults.inpaint_models;
      // Task groups: model theo nhóm công việc — selector trên từng card (Cài đặt → 🎯 Nhóm công việc).
      if (defaults.task_groups && typeof defaults.task_groups === 'object') this.taskGroups = defaults.task_groups;
      // Card "Kịch bản quay": preset video_scene từ Prompt Templates (Cài đặt).
      if (Array.isArray(defaults.video_scenes)) this.videoScenes = defaults.video_scenes;
      // Card "Sửa ảnh": preset chỉnh sửa (category inpaint) từ Prompt Templates (Cài đặt).
      if (Array.isArray(defaults.inpaint_presets)) this.inpaintEditPresets = defaults.inpaint_presets;
    },
    applyDefaults() {
      // Re-fetch and apply default values (used by reset button)
      this.loadDefaults();
    },
    async load() {
      // Reset kết quả/canvas trước khi nạp lại để luôn phản ánh đúng dữ liệu server —
      // ảnh đã xóa sẽ không "sống lại" sau khi tải lại trang (khởi động lại).
      this.generations = [];
      this.previewId = null;
      this.preview = null;
      this.editSource = null;
      this.canvasLayers = [];
      this.activeLayerId = '';
      this.loadUpscaleMemory();
      // Load settings defaults from backend (set in Studio Settings page)
      await this.loadDefaults();
      // Chưa có người dùng đăng nhập (server không truyền user) → dừng sớm, hiển thị banner Đăng nhập.
      if (!this.user) { this.setAuthStatus(401); return; }
      try {
        const res = await fetch('/api/latest', { headers: { Accept: 'application/json' } });
        if (res.status === 403) { this.setAuthStatus(403); return; }
        if (res.status === 401 || res.redirected || (res.url && res.url.includes('/dang-nhap'))) { this.setAuthStatus(401); return; }
        this.setAuthStatus(200);
        // [Đợt 1 — 2026-09-19] Nạp gói & hạn mức credit (1 request, không chặn luồng chính).
        this.loadPlanStatus();
        const d = await res.json();
        const items = d.items || d.generations || [];
        if (Array.isArray(items)) this.generations = items;
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        // Khôi phục bố cục layer đã lưu (chỉ giữ layer còn hợp lệ) — thay cho việc tự chọn ảnh kết quả cũ.
        // KHÔNG tự in lại các kết quả cũ vào layer: việc này làm layer "sống lại" sau mỗi lần tải lại
        // và khiến người dùng không thể xóa chúng khỏi canvas. Kết quả vẫn hiển thị ở Output/Thư viện.
        this.restoreLayerLayout();
        this.restoreBarSettings();
        // Deep-link từ Studio Library: /studio?step=2|3&id=<genId> — khôi phục đúng bước + ảnh.
        const sp = new URLSearchParams(window.location.search);
        const stepParam = parseInt(sp.get('step') || '', 10);
        if (stepParam >= 1 && stepParam <= 3) this.step = stepParam;
        const idParam = parseInt(sp.get('id') || '', 10);
        if (idParam) {
          const target = this.generations.find(g => g.id === idParam);
          if (target) this.select(target);
        }
      } catch (e) { console.error('studio load failed', e); }
    },
    select(g) { if (!g) return; this.previewId = g.id; this.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed' }; if (g.media_url) { this.pushCanvasLayer(String(g.id), 'gen', 'Ảnh #' + g.id, g.media_url, g.id); this.setActiveLayer(String(g.id)); } },
    // Đảm bảo một ảnh kết quả được "in" vào layer canvas (id duy nhất, không trùng).
    syncLayerForGen(id, mediaUrl, name, setActive) {
      if (!id || !mediaUrl) return;
      const lid = String(id);
      if (this.canvasLayers.some((l) => l.id === lid)) return;
      this.pushCanvasLayer(lid, 'gen', name || 'Ảnh #' + id, mediaUrl, id);
      if (setActive) this.setActiveLayer(lid);
    },
    async processQueue() {
      try { await fetch('/api/process', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } }); } catch (e) { console.error('studio operation failed', e); }
      // refresh the generations so the processed images appear
      try { const res = await fetch('/api/latest', { headers: { Accept: 'application/json' } }); const d = await res.json(); const items = d.items || d.generations || []; if (Array.isArray(items)) this.generations = items; } catch (e) { console.error('studio operation failed', e); }
    },
    /**
     * Bộ dựng payload cho MỘT lần tạo ảnh 2D — dùng CHUNG cho tạo lẻ (generateImage) và tạo HÀNG LOẠT
     * (generateBatch). Tách ra để hai đường không bao giờ lệch cấu hình (tỉ lệ, độ phân giải, phom
     * dáng, prefix/suffix, dự án đang áp dụng, model đang chọn…).
     */
    /**
     * TẠO HÀNG LOẠT — mỗi dòng là một sản phẩm/ý tưởng, dùng CHUNG cài đặt đang chọn trên card.
     *
     * Vì sao cần (tiết kiệm thời gian): việc thật của nhà thiết kế/chủ shop là ra ảnh cho CẢ BỘ —
     * 8 SKU × 2 bối cảnh = 16 ảnh. Trước đây phải sửa prompt rồi bấm tạo 16 lần.
     *
     * Cách làm: gọi tuần tự /api/generate cho từng mục (KHÔNG thêm endpoint mới) — nhờ vậy mỗi ảnh
     * vẫn đi đúng đường cũ: trừ credit THEO GÓI, ghi sổ cái, hạ độ phân giải theo cap của gói (trả
     * `notice`), và mục lỗi KHÔNG làm hỏng cả lượt (báo rõ mục nào lỗi).
     */
    async generateBatch(prompts, variantsPerItem = 1) {
      const list = (prompts || []).map((p) => String(p).trim()).filter(Boolean).slice(0, 12);
      if (!list.length || this.generating) return null;

      const per = Math.max(1, Math.min(4, Number(variantsPerItem) || 1));
      let sent = 0;
      let failed = 0;
      const allIds = [];
      let noticeShown = false;

      this.generating = true;
      this.generateStage = 'preparing';
      this.lastBatch = [];
      this.batchFailed = [];
      // Theo dõi TỪNG MỤC (prompt · xong/lỗi · id ảnh) để giao diện hiện tiến trình thật và cho phép
      // CHẠY LẠI CHỈ NHỮNG MỤC LỖI — trước đây mục lỗi chỉ hiện toast rồi mất, người dùng phải tự nhớ.
      this.batchSend = { total: list.length, done: 0, failed: 0, current: list[0], images: 0, items: [] };

      try {
        for (let i = 0; i < list.length; i++) {
          this.batchSend.current = list[i];
          const entry = { prompt: list[i], ok: false, error: null, ids: [] };
          this.batchSend.items.push(entry);
          try {
            const d = await this.api('/api/generate', this.imagePayload(list[i], per));
            const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
            items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: 1, created_at: 'Hàng loạt' }));
            items.forEach((it) => { if (it.generation_id) { allIds.push(it.generation_id); entry.ids.push(it.generation_id); } });
            entry.ok = true;
            sent++;
            // Nói thật khi gói giới hạn độ phân giải (backend hạ cap) — chỉ báo MỘT lần cho cả lượt.
            if (!noticeShown && d.notice) { noticeShown = true; this.toast(d.notice, 'info'); }
            if (d.credit_warning) this.toast(d.credit_warning, 'error');
            if (d.credits_left != null) this.creditsLeft = d.credits_left;
          } catch (e) {
            failed++;
            entry.error = e.message || 'không rõ nguyên nhân';
            this.batchFailed.push(list[i]);
            this.toast('Mục ' + (i + 1) + ' lỗi: ' + entry.error, 'error');
          }
          this.batchSend.done = i + 1;
          this.batchSend.failed = failed;
          this.batchSend.images = allIds.length;
        }

        if (allIds.length) {
          this.setBatch(allIds);
          this.processQueue();
          this.generatedCount = allIds.length;
          this.syncBatchProgress();
          allIds.forEach((id) => this.pollGeneration(id));
        }
      } finally {
        this.generating = false;
        const failNote = failed ? ' · ' + failed + ' mục lỗi (bấm «Chạy lại mục lỗi»)' : '';
        this.toast('Đã gửi ' + sent + '/' + list.length + ' mục · ' + allIds.length + ' ảnh đang tạo' + failNote, failed && !sent ? 'error' : 'info');
        // Giữ danh sách mục lỗi lại (không xoá cùng batchSend) để còn chạy lại được.
        setTimeout(() => { if (this.batchSend) this.batchSend = null; }, 8000);
      }

      return allIds;
    },
    imagePayload(prompt, variants = 1) {
      return {
        prompt,
        creative_level: this.creativeLevel,
        texture: this.texture,
        // Model do người dùng chọn trên card ('' = default nhóm image — Cài đặt → 🎯 Nhóm công việc).
        ...(this.selectedTaskModel('image') ? { provider: this.selectedTaskModel('image').provider, model: this.selectedTaskModel('image').model } : {}),
        // Tôn trọng checkbox: tắt → gửi rỗng → backend bỏ qua prefix/suffix/negative.
        negative_prompt: this.promptUseNegative ? (this.negativePromptEn || '') : '',
        prompt_prefix: this.promptUsePrefix ? (this.promptPrefix || '') : '',
        prompt_suffix: this.promptUseSuffix ? (this.promptSuffix || '') : '',
        resolution: this.imageRes,
        ratio: this.imageRatio,
        variants: Math.max(1, Math.min(4, Number(variants) || 1)),
        // Phom dáng + tóc
        body_height: this.bodyHeight,
        body_build: this.bodyBuild,
        body_waist: this.bodyWaist,
        body_shoulders: this.bodyShoulders,
        body_hips: this.bodyHips,
        hair_style: this.hairStyle || '',
        hair_color: this.hairColor || '',
        pose_id: this.imagePoseId || '',
        // "Dự án hiện tại": ảnh tạo ra sẽ tự gắn vào dự án đang áp dụng
        // (null khi đang ở chế độ duyệt → không gắn vào dự án người khác).
        project_id: this.appliedProjectId(),
        // Gieo quẻ (seed): nếu có → gửi lên backend để tạo ảnh nhất quán
        seed: this.imageSeed || null,
      };
    },
    async generateImage() {
      if (!this.imagePromptEn || this.generating) return;
      this.generating = true;
      this.generateProgress = 0;
      this.generateStage = 'preparing';
      this.generatedCount = 0;
      this.lastBatch = [];
      const variants = Number(this.variantCount) || 1;
      // [Đợt 0.2] BỎ tiến trình mô phỏng: thanh % trước đây là bộ đếm lặp cộng ngẫu nhiên 4–12%,
      // khoá ở 90% rồi API trả về là set 100% + "Hoàn tất!" trong khi ảnh còn nằm 'pending' ở queue.
      // Người dùng bị "lừa" đúng lúc dễ bỏ đi nhất (xem STUDIO_REVIEW_PLAN.md Đợt 0.2). Tiến trình
      // BÂY GIỜ được cộng dồn từ TRẠNG THÁI THẬT của từng generation qua syncBatchProgress().
      try {
        // Payload dựng qua imagePayload() — DÙNG CHUNG với tạo hàng loạt, để hai đường không lệch
        // cấu hình (tỉ lệ, độ phân giải, phom dáng, prefix/suffix, dự án đang áp dụng, model đang chọn).
        const d = await this.api('/api/generate', this.imagePayload(this.imagePromptEn, variants));
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: 1, created_at: 'Vừa gửi' }));
        this.setBatch(items.map(it => it.generation_id));
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        this.processQueue();
        // TIẾN TRÌNH THẬT: không tuyên bố "Hoàn tất!" khi backend mới trả 'pending' → theo dõi
        // từng ảnh; mỗi lần trạng thái đổi, syncBatchProgress() cộng dồn % theo số ảnh ĐÃ XONG.
        this.generatedCount = items.length;
        this.syncBatchProgress();
        items.forEach((it) => { if (it.generation_id) this.pollGeneration(it.generation_id); });
      } catch (e) { this.toast(e.message || 'Lỗi tạo ảnh.', 'error'); }
      finally {
        this.generating = false;
      }
    },
    // i2i — Tạo lại ảnh từ ảnh cho trước (Reimagine / Variation)
    // process=true (mặc định) chạy processQueue ngay sau khi tạo. Render đa góc truyền process=false
    // để tạo TẤT CẢ góc trước rồi gọi processQueue MỘT lần — tránh nhiều processQueue chạy song song,
    // cùng "nhặt" các generation đang chờ và xử lý lặp/đè nhau (gây tốn quota + lỗi "chỉ ra 1 ảnh").
    // model=null dùng Qwen Edit cấu hình; truyền {provider,model} để tôn trọng model đang chọn trên card.
    async reimagine(image, prompt, similarity = 70, variants = 1, process = true, model = null) {
      if (!image || !(prompt || '').trim()) { this.toast('Chọn ảnh + nhập mô tả.', 'error'); return null; }
      try {
        const payload = { image, prompt, similarity: Number(similarity) || 70, variants: Number(variants) || 1, ...this.projectField() };
        if (model && model.provider && model.model) { payload.provider = model.provider; payload.model = model.model; }
        const d = await this.api('/api/reimagine', payload);
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: 1, created_at: 'Vừa tạo lại ảnh' }));
        if (items.length) this.setBatch(items.map(it => it.generation_id));
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        if (process) this.processQueue();
        return items;
      } catch (e) { this.toast(e.message || 'Lỗi tạo lại ảnh.', 'error'); return null; }
    },
    // i2i — Tạo ẢNH MỚI từ ảnh tham chiếu (card "Tạo ảnh mới từ ảnh mẫu", mode='refgen').
    // KHÁC reimagine/edit: dùng model SINH ẢNH (mặc định qwen-image-3.0-pro) + ảnh tham chiếu
    // làm base → tạo bức ảnh mới giống mẫu theo % tương đồng, không sửa trên ảnh gốc.
    // tryon=true (chip "Thử đồ"): gửi 1 ảnh trang phục → sinh ảnh người mẫu mặc đúng đồ đó
    // (rẻ hơn tryon-by-edit, dùng model sinh ảnh). Kế thừa body directive + khuôn mặt mẫu (faceModelId)
    // + pose mẫu (poseId — AI đọc ẢNH pose để tạo mô tả tư thế, không gửi ảnh pose vào model).
    // background (cả 2 chế độ) / angle (chỉ "Tạo ảnh mới"): gửi RIÊNG, không nối vào prompt.
    async refgen(image, prompt = '', similarity = 70, variants = 1, model = null, tryon = false, body = null, faceModelId = '', poseId = '', background = '', angle = '') {
      if (!image) { this.toast('Chọn ảnh tham chiếu.', 'error'); return null; }
      try {
        const payload = { image, prompt: prompt || '', similarity: Number(similarity) || 70, variants: Number(variants) || 1, ...this.projectField() };
        // Ưu tiên: model truyền từ card > default nhóm image (Cài đặt → 🎯 Nhóm công việc).
        const taskModel = this.selectedTaskModel('image');
        const eff = (model && model.provider && model.model) ? model : taskModel;
        if (eff) { payload.provider = eff.provider; payload.model = eff.model; }
        // Nền studio áp dụng cho cả 2 chế độ; góc chụp chỉ cho "Tạo ảnh mới".
        if (background) payload.background_prompt = background;
        if (!tryon && angle) payload.angle_prompt = angle;
        if (tryon) {
          payload.tryon = true;
          if (faceModelId) payload.face_model_id = faceModelId;
          if (poseId) payload.pose_id = poseId;
          // Kế thừa body directive (tạo ảnh 2D) — chỉ gửi khi người dùng đã chỉnh (khác default 5).
          if (body) {
            if (body.height != null) payload.body_height = Number(body.height);
            if (body.build != null) payload.body_build = Number(body.build);
            if (body.waist != null) payload.body_waist = Number(body.waist);
            if (body.shoulders != null) payload.body_shoulders = Number(body.shoulders);
            if (body.hips != null) payload.body_hips = Number(body.hips);
          }
        }
        const d = await this.api('/api/refgen', payload);
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: 1, created_at: 'Vừa tạo ảnh mới' }));
        if (items.length) this.setBatch(items.map(it => it.generation_id));
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        this.processQueue();
        // Poll từng kết quả chưa hoàn tất để khi backend xong → tự "in" vào canvas (pushCanvasLayer)
        // + liệt kê vào Layers panel (setActiveLayer), giống cơ chế Inpaint/Swap. Mặc định
        // processQueue chỉ refresh /api/latest nên kết quả pending sẽ kẹt ở Output mà không lên canvas.
        items.forEach((it) => { if (it.generation_id && it.status !== 'completed') this.pollGeneration(it.generation_id); });
        return items;
      } catch (e) { this.toast(e.message || 'Lỗi tạo ảnh từ ảnh mẫu.', 'error'); return null; }
    },
    // (Đã gỡ action xóa nền AI cùng nút của nó — xem ghi chú ở bảng Lớp.)
    // i2i — Ghép 2–3 ảnh thành 1 (Compose / Blend).
    async compose(images, prompt, variants = 1, mode = 'compose', creativeLevel = 6, style = '', ornamentLevel = 3, overridePrompt = '') {
      if (!Array.isArray(images) || images.length < 2 || !(prompt || '').trim()) { this.toast('Chọn ít nhất 2 ảnh + nhập mô tả.', 'error'); return null; }
      this.composeStage = 'send';
      this.composeError = '';
      this.composeStartTs = Date.now();
      const n = Number(variants) || 1;
      try {
        const payload = { images, prompt, variants: n, mode, creative_level: Number(creativeLevel) || 6, style: style || '', ornament_level: Number(ornamentLevel) ?? 3, ...this.projectField() };
        if (overridePrompt && String(overridePrompt).trim()) payload.final_prompt = String(overridePrompt).trim();
        const d = await this.api('/api/compose', payload);
        const items = Array.isArray(d.items) ? d.items : (d.generation_id ? [d] : []);
        items.forEach((it) => this.addGen({ id: it.generation_id, type: 'image', status: it.status, model: it.model, provider: it.provider, media_url: it.media_url, error: it.error, credits_cost: it.credits_cost ?? 1, created_at: 'Vừa ghép ảnh' }));
        const ids = items.map(it => it.generation_id).filter(Boolean);
        this.composeGenIds = ids;
        this.composeStage = 'processing';
        if (items.length) this.setBatch(ids);
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        ids.forEach((id) => this.pollGeneration(id));
        return items;
      } catch (e) {
        this.composeError = e.message || 'Lỗi ghép ảnh.';
        this.composeStage = 'error';
        this.toast(this.composeError, 'error');
        return null;
      }
    },
    // Kiểm tra khi mọi biến thể compose đã về trạng thái cuối → chuyển stage done/error.
    _checkComposeDone() {
      if (!this.composeGenIds.length || this.composeStage !== 'processing') return;
      const gens = this.composeGenIds.map(id => this.generations.find(g => g.id === Number(id))).filter(Boolean);
      if (gens.length < this.composeGenIds.length) return; // chưa đủ thông tin
      if (!gens.every(g => ['completed', 'failed', 'cancelled'].includes(g.status))) return;
      const done = gens.filter(g => g.status === 'completed').length;
      const failed = gens.filter(g => g.status === 'failed').length;
      if (done > 0) {
        this.composeStage = 'done';
        const warn = failed > 0 ? ' — ' + failed + ' bản thất bại.' : '';
        this.toast('✅ Đã ghép xong ' + done + ' biến thể.' + warn);
      } else {
        this.composeStage = 'error';
        this.composeError = gens.find(g => g.error)?.error || 'Ghép ảnh thất bại.';
        this.toast(this.composeError, 'error');
      }
    },
    async cancelCompose() {
      for (const id of this.composeGenIds) {
        try { await this.api('/api/generations/' + id + '/cancel', {}); } catch (e) { console.error('studio operation failed', e); }
      }
      this.composeStage = 'cancelled';
      this.toast('Đã hủy ghép ảnh.');
    },
    clearComposeStatus() { this.composeStage = ''; this.composeError = ''; this.composeGenIds = []; this.composeStartTs = 0; },
    async loadPalette(id) {
      if (!id) { this.palette = []; return; }
      try { const res = await fetch('/api/generations/' + id + '/palette', { headers: { Accept: 'application/json' } }); if (!res.ok) throw new Error('HTTP ' + res.status); const d = await res.json(); this.palette = d.colors || []; }
      catch (e) { this.palette = []; }
    },
    // Palette màu cho ẢNH BẤT KỲ (source/uploaded/product/result/dataURL) — trích màu NGAY TRÊN TRÌNH DUYỆT.
    async loadPaletteFromImage(url) {
      if (!url) { this.palette = []; return; }
      try { this.palette = await this._extractColors(url); }
      catch (e) { this.palette = []; }
    },
    _extractColors(url) {
      return new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
          try {
            const W = 64;
            const H = Math.max(1, Math.round((img.naturalHeight || 1) * (W / (img.naturalWidth || 1))));
            const c = document.createElement('canvas'); c.width = W; c.height = H;
            const ctx = c.getContext('2d');
            ctx.drawImage(img, 0, 0, W, H);
            const d = ctx.getImageData(0, 0, W, H).data;
            // Bucket màu giống backend: quantize /32, cộng dồn trung bình, lấy 8 màu nhiều nhất.
            const buckets = new Map();
            for (let i = 0; i < d.length; i += 4) {
              const a = d[i + 3];
              if (a < 128) continue; // bỏ pixel trong suốt
              const r = d[i], g = d[i + 1], b = d[i + 2];
              const key = ((r / 32) | 0) + ',' + ((g / 32) | 0) + ',' + ((b / 32) | 0);
              let bk = buckets.get(key);
              if (!bk) { bk = { n: 0, r: 0, g: 0, b: 0 }; buckets.set(key, bk); }
              bk.n++; bk.r += r; bk.g += g; bk.b += b;
            }
            const sorted = [...buckets.values()].sort((a, b) => b.n - a.n).slice(0, 8);
            const colors = sorted.map((bk) => {
              const rr = Math.round(bk.r / bk.n), gg = Math.round(bk.g / bk.n), bb = Math.round(bk.b / bk.n);
              return '#' + [rr, gg, bb].map((v) => v.toString(16).padStart(2, '0')).join('');
            });
            resolve(colors);
          } catch (e) { resolve([]); }
        };
        img.onerror = () => resolve([]);
        img.src = url;
      });
    },
    async renderVideo() {
      const srcImage = this.upscaleSrc || '';
      if (!this.videoPromptEn && !srcImage) { this.toast('Nhập prompt video hoặc chọn ảnh nguồn.', 'error'); return; }
      if (this.videoBusy) return;
      this.videoBusy = true;
      try {
        // Prompt video: ưu tiên người dùng nhập, nếu trống thì ghép từ prompt ảnh đang có,
        // cuối cùng rơi về mô tả catwalk mặc định (giống luồng Alpine cũ tại index.blade.php).
        let prompt = this.videoPromptEn;
        if (!prompt && this.imagePromptEn) {
          prompt = 'Cinematic fashion catwalk: ' + this.imagePromptEn + ', dynamic fabric motion, professional fashion video.';
        }
        if (!prompt) {
          prompt = 'a fashion model walking on a runway, cinematic fashion catwalk, dynamic fabric motion, professional fashion video';
        }
        const d = await this.api('/api/video', {
          prompt,
          camera: this.videoSceneCamera(),
          base_image: srcImage,
          // Model do người dùng chọn trên card Kịch bản quay; '' = default nhóm video (Cài đặt → 🎯).
          ...(this.selectedTaskModel('video') ? { provider: this.selectedTaskModel('video').provider, model: this.selectedTaskModel('video').model } : {}),
          duration: this.videoDuration,
          resolution: this.videoRes,
          project_id: this.appliedProjectId(),
        });
        this.addGen({ id: d.generation_id, type: 'video', status: d.status || 'processing', model: d.model || this.videoModel, provider: d.provider || 'video', media_url: d.media_url || null, error: null, credits_cost: d.credits_cost || 10, created_at: 'Vừa gửi' });
      } catch (e) { this.toast(e.message || 'Lỗi render video.', 'error'); }
      finally { this.videoBusy = false; }
    },
    // Đọc preset "Kịch bản quay" (video_scene) đang chọn từ danh sách nạp ở Cài đặt → Prompt Templates.
    videoSceneCamera() {
      const sc = this.videoScenes.find(s => String(s.id) === String(this.videoScene));
      return sc ? sc.prompt : '';
    },
    // ── Task groups: model theo nhóm công việc (Cài đặt → 🎯 Nhóm công việc) ──
    // Danh sách model của một nhóm + default đang dùng — selector trên từng card.
    taskGroupModels(group) { return (this.taskGroups[group] || {}).models || []; },
    taskGroupDefault(group) { return (this.taskGroups[group] || {}).default || ''; },
    // [provider, model] đang chọn cho một nhóm: selector trên card > default nhóm.
    selectedTaskModel(group) {
      const sel = group === 'video' ? this.videoModelSel : this.imageModelSel;
      if (sel && sel.includes(':')) { const [p, m] = sel.split(':'); return { provider: p, model: m }; }
      const d = this.taskGroupDefault(group);
      if (d && d.includes(':')) { const [p, m] = d.split(':'); return { provider: p, model: m }; }
      const first = this.taskGroupModels(group)[0];
      return first ? { provider: first.provider, model: first.model } : null;
    },
    // Zoom theo điểm chuột (cx, cy = px so với TÂM khung) — điểm ảnh dưới con trỏ
    // không trôi khi phóng/thu (pan' = c*(1-k) + pan*k).
    zoomAt(cx, cy, factor) {
      const z0 = this.zoom;
      const z1 = Math.max(0.1, Math.min(8, z0 * factor));
      if (z1 === z0) return;
      const k = z1 / z0;
      this.zoom = z1;
      this.pan = { x: cx * (1 - k) + this.pan.x * k, y: cy * (1 - k) + this.pan.y * k };
    },
    wheelZoom(e) {
      // e có thể là event (kèm clientX/Y) hoặc số deltaY (tương thích cũ)
      let cx = 0, cy = 0, delta;
      if (typeof e === 'number') { delta = e; }
      else {
        delta = e.deltaY;
        const cont = this.canvasZoom;
        if (cont) {
          const r = cont.getBoundingClientRect();
          cx = e.clientX - r.left - r.width / 2;
          cy = e.clientY - r.top - r.height / 2;
        }
      }
      this.zoomAt(cx, cy, delta > 0 ? 1 / 1.15 : 1.15);
    },
    // ── Canvas refs (set by StudioApp template refs; markRaw so Vue never proxies DOM nodes) ──
    setCanvasRefs(img, zoom) { this.cvImg = img ? markRaw(img) : null; this.canvasZoom = zoom ? markRaw(zoom) : null; },
    setBrushCanvas(el) { this.brushOverlay = el ? markRaw(el) : null; },
    // ── Reframe / Crop ──
    _clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); },
    ratioAspect() { const p = (this.reframeRatio || '3:4').split(':').map(Number); return p[1] ? p[0] / p[1] : 0.75; },
    // CSS transform dùng chung cho layer (stack + isolate + overlay neo khung) — 1 công thức duy
    // nhất để overlay/preview bám CHÍNH XÁC theo <img> (kể cả xoay/lật/scale của layer).
    layerTransformStyle(l) {
      if (!l) return 'none';
      const s = Math.max(0.05, Math.min(8, Number(l.scale) || 1));
      return 'translate(-50%, -50%) translate(' + (Number(l.x) || 0) + 'px, ' + (Number(l.y) || 0) + 'px) rotate(' + (Number(l.rotation) || 0) + 'deg) scale(' + (s * (l.flipX ? -1 : 1)) + ', ' + (s * (l.flipY ? -1 : 1)) + ')';
    },
    // Đo bbox thật của <img> (dùng khi layer còn xoay/lật — crop/reframe) — nhánh DOM gốc.
    _domMetrics(img, cr) {
      const ir = img.getBoundingClientRect();
      if (!ir.width || !ir.height || !cr.width || !cr.height) return null;
      const iw = img.naturalWidth || 1, ih = img.naturalHeight || 1;
      const ia = iw / ih, box = ir.width / ir.height;
      let vw, vh;
      if (ia > box) { vw = ir.width; vh = ir.width / ia; } else { vh = ir.height; vw = ir.height * ia; }
      return { vw, vh, vx: ir.left - cr.left + (ir.width - vw) / 2, vy: ir.top - cr.top + (ir.height - vh) / 2, crW: cr.width, crH: cr.height, crLeft: cr.left, crTop: cr.top, ia, iw, ih };
    },
    // Geometry của vùng ảnh HIỂN THỊ trong pan container (toạ độ container px).
    // Layer đang "identity" (không xoay/lật — mọi công cụ sửa pixel đều flatten trước) → TÍNH
    // thuần bằng số liệu store (zoom/pan/x/y/scale/baseW/baseH) thay vì đo getBoundingClientRect
    // của <img>. Đo DOM khiến overlay/preview phụ thuộc thứ tự render giữa các component: khi
    // phóng to, một component có thể đọc rect lúc style zoom chưa được patch → preview bị TRÔI
    // khỏi vùng thật (thu nhỏ về thì hết). Vì hàm này chỉ đọc state reactive nên mọi computed
    // tự cập nhật đúng ngay trong cùng một flush — khớp 100% ở mọi mức zoom/pan.
    // (Layer còn xoay/lật hoặc ảnh chưa sẵn layout → fallback đo DOM như cũ.)
    canvasMetrics() {
      const img = this.cvImg, cont = this.canvasZoom;
      if (!img || !cont) return null;
      const cr = cont.getBoundingClientRect();
      if (!cr.width || !cr.height) return null;
      const l = this.activeLayer;
      const rot = l ? Math.abs((Number(l.rotation) || 0) % 360) : 0;
      if (!l || (rot > 0.5 && rot < 359.5) || l.flipX || l.flipY) return this._domMetrics(img, cr);
      const fl = this.frameLayout; // kích thước layout của <img> (baseW/baseH)
      if (!fl) return this._domMetrics(img, cr);
      const iw = img.naturalWidth || 1, ih = img.naturalHeight || 1;
      const z = Math.max(0.05, this.zoom || 1);
      const s = Math.max(0.05, Math.min(8, Number(l.scale) || 1));
      const f = s * z; // tỉ lệ hiển thị thực (base px → màn hình)
      const x = Number(l.x) || 0, y = Number(l.y) || 0;
      // Tâm ảnh cách tâm container: z*(x,y) + pan (pan ở screen px, x/y ở layout px).
      return {
        vw: fl.w * f,
        vh: fl.h * f,
        vx: cr.width / 2 + (this.pan ? this.pan.x : 0) + z * x - (fl.w * f) / 2,
        vy: cr.height / 2 + (this.pan ? this.pan.y : 0) + z * y - (fl.h * f) / 2,
        crW: cr.width, crH: cr.height, crLeft: cr.left, crTop: cr.top,
        ia: (iw > 1 && ih > 1) ? iw / ih : fl.w / fl.h, iw, ih,
      };
    },
    cropStyle() {
      const m = this.canvasMetrics(); if (!m) return { display: 'none' };
      const b = this.cropBox || { x: 0.15, y: 0.15, w: 0.7, h: 0.7 };
      return { left: ((m.vx + b.x * m.vw) / m.crW * 100) + '%', top: ((m.vy + b.y * m.vh) / m.crH * 100) + '%', width: (b.w * m.vw / m.crW * 100) + '%', height: (b.h * m.vh / m.crH * 100) + '%' };
    },
    cropSizeLabel() {
      const img = this.cvImg, b = this.cropBox;
      if (!img || !b) return this.reframeRatio;
      const w = Math.max(1, Math.round(b.w * (img.naturalWidth || 1)));
      const h = Math.max(1, Math.round(b.h * (img.naturalHeight || 1)));
      return this.reframeRatio + ' · ' + w + '×' + h;
    },
    // (Re)create the crop box: 70% tall, keeping the current ratio, centered on the image.
    initCropBox() {
      const m = this.canvasMetrics();
      const ia = m ? m.ia : (this.cropBox && this.cropBox.h ? this.cropBox.w / this.cropBox.h : 0.75);
      const r = this.ratioAspect();
      const ratioFrac = r / ia;
      let h = 0.7; if (h * ratioFrac > 1) h = 1 / ratioFrac;
      let w = h * ratioFrac;
      if (w > 1) { w = 1; h = w / ratioFrac; }
      this.cropBox = { x: (1 - w) / 2, y: (1 - h) / 2, w, h };
    },
    // Re-fit an existing crop box to a new ratio, keeping its center and height where possible.
    refitCropBox() {
      const m = this.canvasMetrics(); if (!m) return;
      const old = this.cropBox || { x: 0.15, y: 0.15, w: 0.7, h: 0.7 };
      const cx = old.x + old.w / 2, cy = old.y + old.h / 2;
      const ratioFrac = this.ratioAspect() / m.ia;
      let h = Math.max(0.2, Math.min(0.9, old.h));
      let w = h * ratioFrac;
      if (w > 1) { w = 1; h = w / ratioFrac; }
      if (h > 1) { h = 1; w = h * ratioFrac; }
      this.cropBox = { x: this._clamp(cx - w / 2, 0, 1 - w), y: this._clamp(cy - h / 2, 0, 1 - h), w, h };
    },
    toggleCrop() {
      this.cropMode = !this.cropMode;
      if (this.cropMode) {
        // Nếu mask đang active → lấy luôn vùng mask làm crop box
        if (this.inpaintMaskMode !== 'none' && (this.inpaintMaskBox.w || 0) >= 0.02) {
          this.cropBox = { ...this.inpaintMaskBox };
        } else {
          this.initCropBox();
        }
      } else {
        this._cropStop(null);
      }
    },
    onCanvasImgLoad() {
      this.imgTick++; // overlay đang bật cần đo lại sau khi ảnh decode xong (kích thước thật)
      if (this.cropMode) this.initCropBox();
      // Ảnh load xong có thể làm overlay erase/draw được gắn LÚC ẢNH CHƯA DECODE bị sai kích thước
      // (gắn với ratio mặc định vuông). Re-attach để khớp tỉ lệ thật của ảnh; nét vẽ trước đó sẽ
      // bị xoá nhưng trước khi ảnh hiển thị thì chưa thể vẽ gì có ý nghĩa — nên an toàn.
      if (this.eraseMode && this._eraseCanvas) this.attachEraseCanvas(this._eraseCanvas);
      if (this.drawMode && this._drawCanvas) this.attachDrawCanvas(this._drawCanvas);
    },
    cropStart(e, key) {
      if (!this.cropMode) return;
      // NOTE: no preventDefault() here — canceling pointerdown would also suppress the
      // compatibility dblclick used for "double-click to cancel". touch-action:none (CSS)
      // already blocks scroll/zoom and select-none blocks text selection.
      e.stopPropagation();
      // A previous drag may still be armed (e.g. a fast second press before the first release) — close it first so its window listeners are removed.
      if (this._cropDrag) this._cropStop(this._cropDrag.handlers);
      const handlers = { move: (ev) => this._cropQueue(ev), up: () => this._cropStop(handlers) };
      this._cropDrag = { key, sx: e.clientX, sy: e.clientY, box: { ...(this.cropBox || { x: 0.15, y: 0.15, w: 0.7, h: 0.7 }) }, handlers };
      window.addEventListener('pointermove', handlers.move);
      window.addEventListener('pointerup', handlers.up);
      window.addEventListener('pointercancel', handlers.up);
    },
    // Batch pointermoves through rAF so dragging never triggers a layout read per event.
    _cropQueue(e) {
      if (!this._cropDrag) return;
      this._cropPending = e;
      if (this._cropRaf) return;
      const flush = () => { this._cropRaf = null; const ev = this._cropPending; this._cropPending = null; if (ev && this._cropDrag) this.cropMove(ev); };
      if (typeof requestAnimationFrame === 'function') this._cropRaf = requestAnimationFrame(flush);
      else flush();
    },
    _cropStop(handlers) {
      this._cropDrag = null;
      this._cropPending = null;
      if (this._cropRaf != null) { if (typeof cancelAnimationFrame === 'function') cancelAnimationFrame(this._cropRaf); this._cropRaf = null; }
      if (handlers) {
        window.removeEventListener('pointermove', handlers.move);
        window.removeEventListener('pointerup', handlers.up);
        window.removeEventListener('pointercancel', handlers.up);
      }
    },
    cropMove(e) {
      const d = this._cropDrag; if (!d || !this.cropMode) return;
      const m = this.canvasMetrics(); if (!m) return;
      const bx = (e.clientX - d.sx) / m.vw, by = (e.clientY - d.sy) / m.vh;
      const b = { ...d.box };
      const MIN = 0.05;
      if (d.key === 'move') {
        b.x = this._clamp(b.x + bx, 0, 1 - b.w);
        b.y = this._clamp(b.y + by, 0, 1 - b.h);
      } else {
        // Corner resize, ratio-locked, opposite corner anchored.
        const ratioFrac = this.ratioAspect() / m.ia;
        const maxRight = 1 - b.x, maxBottom = 1 - b.y;
        const right = b.x + b.w, bottom = b.y + b.h;
        let nw = b.w, nh = b.h, x = b.x, y = b.y;
        if (d.key === 'se' || d.key === 'resize') { nh = this._clamp(b.h + Math.max(bx, by), MIN, Math.max(MIN, Math.min(maxBottom, maxRight / ratioFrac))); nw = nh * ratioFrac; }
        else if (d.key === 'sw') { nh = this._clamp(b.h + Math.max(-bx, by), MIN, Math.max(MIN, Math.min(maxBottom, right / ratioFrac))); nw = nh * ratioFrac; x = right - nw; }
        else if (d.key === 'ne') { nw = this._clamp(b.w + Math.max(bx, -by), MIN, Math.max(MIN, Math.min(maxRight, bottom * ratioFrac))); nh = nw / ratioFrac; y = bottom - nh; }
        else if (d.key === 'nw') { nw = this._clamp(b.w + Math.max(-bx, -by), MIN, Math.max(MIN, Math.min(right, bottom * ratioFrac))); nh = nw / ratioFrac; x = right - nw; y = bottom - nh; }
        b.w = this._clamp(nw, MIN, 1); b.h = this._clamp(nh, MIN, 1); b.x = x; b.y = y;
      }
      this.cropBox = b;
    },
    async confirmCrop() {
      if (!this.cropMode || this.reframing) return;
      const img = this.cvImg;
      if (!img) { this.toast('Chưa có ảnh trên canvas.', 'error'); return; }
      const iw = img.naturalWidth, ih = img.naturalHeight;
      if (!iw || !ih) { this.toast('Ảnh chưa tải xong.', 'error'); return; }
      const b = this.cropBox || { x: 0.15, y: 0.15, w: 0.7, h: 0.7 };
      const x = Math.max(0, Math.round(b.x * iw)), y = Math.max(0, Math.round(b.y * ih));
      const w = Math.max(1, Math.min(iw - x, Math.round(b.w * iw))), h = Math.max(1, Math.min(ih - y, Math.round(b.h * ih)));
      this.reframing = true;
      try {
        const d = await this.api('/api/reframe', { image: this.upscaleSrc, ratio: this.reframeRatio, x, y, w, h, ...this.projectField() });
        this.addGen({ id: d.generation_id, type: 'image', status: 'completed', model: 'reframe', provider: 'reframe', media_url: d.media_url, error: null, credits_cost: 0, created_at: 'Vừa cắt' });
        this.cropMode = false; this._cropStop(null);
        this.toast('Đã cắt vùng đã chọn.');
      } catch (err) { this.toast(err.message || 'Lỗi cắt.', 'error'); }
      finally { this.reframing = false; }
    },
    async reframeCenter() {
      if (!this.upscaleSrc || this.reframing) return;
      this.reframing = true;
      try {
        const d = await this.api('/api/reframe', { image: this.upscaleSrc, ratio: this.reframeRatio, ...this.projectField() });
        this.addGen({ id: d.generation_id, type: 'image', status: 'completed', model: 'reframe', provider: 'reframe', media_url: d.media_url, error: null, credits_cost: 0, created_at: 'Vừa cắt' });
        this.toast('Đã cắt giữa ' + this.reframeRatio + '.');
      } catch (e) { this.toast(e.message || 'Lỗi cắt.', 'error'); }
      finally { this.reframing = false; }
    },
    async applyFilmLook() {
      if (!this.upscaleSrc || this.looking) return;
      this.looking = true;
      try {
        const d = await this.api('/api/look', { image: this.upscaleSrc, look: this.lookPreset, level: Number(this.lookLevel) || 5, ...this.projectField() });
        this.addGen({ id: d.generation_id, type: 'image', status: 'completed', model: 'look', provider: 'look', media_url: d.media_url, error: null, credits_cost: 0, created_at: 'Vừa áp dụng' });
        this.toast('Đã áp dụng Look ' + this.lookPreset + '.');
      } catch (e) { this.toast(e.message || 'Lỗi áp dụng Look.', 'error'); }
      finally { this.looking = false; }
    },
    upscaleCfg() { return { scale: this.upscaleScale, refine: this.upscaleRefine, vibrance: this.vibrance }; },
    loadUpscaleMemory() {
      try { const m = JSON.parse(localStorage.getItem('fabrikai.upscale') || '{}'); if (m.settings) Object.assign(this, { upscaleScale: m.settings.scale ?? 2, upscaleRefine: m.settings.refine ?? 0, vibrance: m.settings.vibrance ?? 3 }); } catch (e) { console.error('studio operation failed', e); }
    },
    saveUpscaleMemory() { try { localStorage.setItem('fabrikai.upscale', JSON.stringify({ settings: this.upscaleCfg() })); } catch (e) { console.error('studio operation failed', e); } },
    zoomIn() { this.zoomAt(0, 0, 1.5); },
    zoomOut() { this.zoomAt(0, 0, 1 / 1.5); },
    // "Vừa": thu/phóng cho VỪA KHUNG nhìn — tính bbox các layer đang hiển thị (ở zoom 1, đã tính
    // rotation/scale) rồi chọn zoom = min(cw/bbw, ch/bbh). Trước đây chỉ reset zoom=1/pan=0 nên
    // ảnh nhỏ/ảnh đã di chuyển không bao giờ "vừa" được với vùng canvas.
    zoomFit() {
      const cont = this.canvasZoom;
      const layers = this.canvasLayers.filter((l) => l.visible !== false && l.image);
      if (!cont || !layers.length) { this.zoom = 1; this.pan = { x: 0, y: 0 }; return; }
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      layers.forEach((l) => {
        const bw = Number(l.baseW) || 512, bh = Number(l.baseH) || 512;
        const s = Number(l.scale) || 1;
        const rot = ((Number(l.rotation) || 0) * Math.PI) / 180;
        const cos = Math.cos(rot), sin = Math.sin(rot);
        const hw = (bw * s) / 2, hh = (bh * s) / 2;
        const x = Number(l.x) || 0, y = Number(l.y) || 0;
        [[-hw, -hh], [hw, -hh], [hw, hh], [-hw, hh]].forEach(([cx, cy]) => {
          const px = cx * cos - cy * sin + x;
          const py = cx * sin + cy * cos + y;
          if (px < minX) minX = px; if (px > maxX) maxX = px;
          if (py < minY) minY = py; if (py > maxY) maxY = py;
        });
      });
      const r = cont.getBoundingClientRect();
      const cw = Math.max(1, r.width - 64), ch = Math.max(1, r.height - 64);
      const bw2 = Math.max(1, maxX - minX), bh2 = Math.max(1, maxY - minY);
      // Giới hạn trên 100% để "Vừa" luôn là cái nhìn tổng thể (không phóng to hơn ảnh thật).
      const z = Math.max(0.05, Math.min(1, Math.min(cw / bw2, ch / bh2)));
      const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
      this.zoom = z;
      this.pan = { x: -cx * z, y: -cy * z };
    },
    // Fit view KHUNG các layer/group ĐANG CHỌN (chỉ chúng, không toàn bộ canvas).
    zoomFitSelection() {
      const cont = this.canvasZoom;
      const ids = this.selectedIds;
      if (!cont || !ids.length) return;
      const layers = this.canvasLayers.filter(l => ids.includes(l.id) && l.visible !== false && l.image);
      if (!layers.length) return;
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      layers.forEach((l) => {
        const bw = Number(l.baseW) || 512, bh = Number(l.baseH) || 512;
        const s = Number(l.scale) || 1;
        const rot = ((Number(l.rotation) || 0) * Math.PI) / 180;
        const cos = Math.cos(rot), sin = Math.sin(rot);
        const hw = (bw * s) / 2, hh = (bh * s) / 2;
        const x = Number(l.x) || 0, y = Number(l.y) || 0;
        [[-hw, -hh], [hw, -hh], [hw, hh], [-hw, hh]].forEach(([cx, cy]) => {
          const px = cx * cos - cy * sin + x;
          const py = cx * sin + cy * cos + y;
          if (px < minX) minX = px; if (px > maxX) maxX = px;
          if (py < minY) minY = py; if (py > maxY) maxY = py;
        });
      });
      const r = cont.getBoundingClientRect();
      const cw = Math.max(1, r.width - 64), ch = Math.max(1, r.height - 64);
      const bw2 = Math.max(1, maxX - minX), bh2 = Math.max(1, maxY - minY);
      // Giới hạn max zoom = 4x cho fit selection (có thể nhỏ hơn ảnh thật để dễ thao tác).
      const z = Math.max(0.05, Math.min(4, Math.min(cw / bw2, ch / bh2)));
      const cx = (minX + maxX) / 2, cy = (minY + maxY) / 2;
      this.zoom = z;
      this.pan = { x: -cx * z, y: -cy * z };
    },
    // Pinch-zoom (2 ngón) trên cảm ứng.
    beginPinch(t1, t2) { this._pinch = { dist: Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY) || 1, zoom: this.zoom }; this._drag = null; },
    pinchMove(t1, t2) { if (!this._pinch) return; const dist = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY) || 1; this.zoom = Math.max(0.1, Math.min(8, this._pinch.zoom * (dist / this._pinch.dist))); },
    endPinch() { this._pinch = null; },
    panStart(e) { if (this._cropDrag) return; this._drag = { x: e.clientX, y: e.clientY, px: this.pan.x, py: this.pan.y }; },
    panMove(e) {
      if (!this._drag) return;
      // Pan TỰ DO, không giới hạn, không đọc layout mỗi event → kéo mượt không khựng.
      this.pan.x = this._drag.px + (e.clientX - this._drag.x);
      this.pan.y = this._drag.py + (e.clientY - this._drag.y);
    },
    panEnd() { this._drag = null; },
    async deleteGen(g) {
      // Layer lưu cục bộ từ canvas (saveActiveLayerToOutput) KHÔNG có record server → xóa cục bộ.
      const isLocal = !!(g && (g.provider === 'layer' || g.model === 'layer' || String(g.id || '').startsWith('layer-')));
      if (isLocal) {
        this._removeGenLocal(g);
        this.toast('Đã xóa layer khỏi Output.');
        return true;
      }
      try {
        const r = await fetch('/api/generations/' + g.id, { method: 'DELETE', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' } });
        const ct = (r.headers.get('content-type') || '');
        const d = ct.includes('application/json') ? await r.json().catch(() => ({})) : {};
        // Redirect về trang login (chưa đăng nhập/hết phiên) trả HTML 200 — phải coi là THẤT BẠI
        // để không báo "Đã xóa" trong khi server thực tế chưa xóa.
        if (!r.ok || r.redirected || !ct.includes('application/json')) throw new Error(d.message || 'Không xóa được — hãy tải lại trang và thử lại.');
        this._removeGenLocal(g);
        this.toast('Đã xóa.');
        return true;
      }
      catch (e) { this.toast(e.message || 'Lỗi xóa.', 'error'); return false; }
    },
    // Gỡ generation khỏi store + layer canvas liên quan (dùng chung cho xóa server & xóa cục bộ).
    _removeGenLocal(g) {
      this.generations = this.generations.filter(x => x.id !== g.id);
      if (this.libraryItems && this.libraryItems.some(x => x.id === g.id)) {
        this.libraryItems = this.libraryItems.filter(x => x.id !== g.id);
        this.libraryTotal = Math.max(0, this.libraryTotal - 1);
      }
      this.librarySelection = (this.librarySelection || []).filter(id => id !== g.id);
      const lid = String(g.id);
      if (this.canvasLayers.some((l) => l.id === lid)) this.canvasLayers = this.canvasLayers.filter((l) => l.id !== lid);
      const orphanSource = g.media_url ? this.canvasLayers.find((l) => l.kind === 'source' && l.image === g.media_url) : null;
      if (orphanSource) this.canvasLayers = this.canvasLayers.filter((l) => !(l.kind === 'source' && l.image === g.media_url));
      if (this.activeLayerId === lid || this.previewId === g.id || (orphanSource && this.activeLayerId === orphanSource.id)) {
        const next = this.canvasLayers.find((x) => x.visible !== false);
        if (next) this.selectLayer(next);
        else { this.activeLayerId = ''; this.editSource = null; this.previewId = null; this.preview = null; }
      }
      this.saveLayerLayout();
    },
    // ── Thư viện (/api/library) — quản lý + xóa ảnh cũ / ảnh rác ──
    async _libraryFetch(url, body = null) {
      const opts = body == null
        ? { method: 'GET', headers: { Accept: 'application/json' } }
        : { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) };
      const res = await fetch(url, opts);
      const d = await res.json().catch(() => ({}));
      if (!res.ok || res.redirected || !(res.headers.get('content-type') || '').includes('application/json')) {
        throw new Error(d.message || 'Có lỗi xảy ra.');
      }
      return d;
    },
    async loadLibrary(reset = true) {
      if (this.libraryLoading) return;
      this.libraryLoading = true;
      if (reset) { this.libraryItems = []; this.librarySelection = []; this.libraryFilters.page = 1; }
      try {
        const qs = new URLSearchParams();
        if (this.libraryFilters.type) qs.set('type', this.libraryFilters.type);
        if (this.libraryFilters.status) qs.set('status', this.libraryFilters.status);
        if (this.libraryFilters.project_id !== '' && this.libraryFilters.project_id != null) qs.set('project_id', String(this.libraryFilters.project_id));
        if (this.libraryFilters.q) qs.set('q', this.libraryFilters.q);
        qs.set('page', String(this.libraryFilters.page || 1));
        qs.set('per_page', String(this.libraryFilters.per_page || 48));
        qs.set('old_days', String(this.libraryFilters.old_days || 30));
        if (this.librarySort) qs.set('sort', this.librarySort);
        const d = await this._libraryFetch('/api/library/data?' + qs.toString());
        const items = Array.isArray(d.items) ? d.items : [];
        this.libraryItems = reset ? items : this.libraryItems.concat(items.filter(x => !this.libraryItems.some(y => y.id === x.id)));
        this.libraryTotal = d.total ?? items.length;
        this.libraryStats = { ...(this.libraryStats || {}), ...(d.stats || {}) };
        this.libraryHasMore = !!d.has_more;
        this.libraryFilters.page = d.current_page || (this.libraryFilters.page + 1);
      } catch (e) { this.toast(e.message || 'Không tải được thư viện.', 'error'); }
      finally { this.libraryLoading = false; }
    },
    async loadMoreLibrary() {
      if (!this.libraryHasMore || this.libraryLoading) return;
      // M05: page CHỈ lấy từ response (loadLibrary set this.libraryFilters.page = d.current_page).
      // Trước đây tăng ở đây RỒI tăng lần nữa trong loadLibrary -> nhảy cóc trang khi backend
      // không trả current_page. Không tăng trước nữa.
      await this.loadLibrary(false);
    },
    setLibraryFilter(key, value) {
      this.libraryFilters[key] = value;
      this.loadLibrary(true);
    },
    async refreshLibraryScan() {
      if (this.libraryScanning) return;
      this.libraryScanning = true;
      try {
        const d = await this._libraryFetch('/api/library/scan', { old_days: this.libraryFilters.old_days || 30 });
        this.libraryStats = {
          ...(this.libraryStats || {}),
          junk_count: d.junk?.count ?? 0,
          junk_bytes: d.junk?.bytes ?? 0,
          old_count: d.old?.count ?? 0,
          old_bytes: d.old?.bytes ?? 0,
          orphan_count: d.orphans?.count ?? 0,
          orphan_bytes: d.orphans?.bytes ?? 0,
        };
        return d;
      } catch (e) { this.toast(e.message || 'Không quét được thư viện.', 'error'); return null; }
      finally { this.libraryScanning = false; }
    },
    toggleLibrarySelect(id) {
      const i = this.librarySelection.indexOf(id);
      if (i >= 0) this.librarySelection.splice(i, 1);
      else this.librarySelection.push(id);
    },
    librarySelectAll() {
      this.librarySelection = this.libraryItems.map(g => g.id);
    },
    librarySelectNone() { this.librarySelection = []; },
    librarySelectJunk() {
      this.librarySelection = this.libraryItems.filter(g => g.status === 'failed' || g.status === 'cancelled').map(g => g.id);
    },
    librarySelectOld() {
      const days = Number(this.libraryFilters.old_days) || 30;
      const cutoff = Date.now() - days * 86400000;
      // §5.2: trước đây chỉ dựa created_ts; bản ghi thiếu created_ts bị LOẠI ÂM THẦM khỏi "chọn ảnh cũ"
      // (người dùng thấy danh sách thiếu mà không hiểu vì sao). Nay fallback created_at rồi tới id.
      const tsOf = (g) => {
        if (g.created_ts) return g.created_ts * 1000;
        const t = g.created_at ? Date.parse(g.created_at) : NaN;
        return Number.isFinite(t) ? t : null;
      };
      this.librarySelection = this.libraryItems
        .filter(g => {
          if (g.status !== 'completed' || !g.media_url) return false;
          const t = tsOf(g);
          return t !== null && t < cutoff;
        })
        .map(g => g.id);
    },
    async libraryBulkDelete() {
      const ids = this.librarySelection.filter(Boolean);
      if (!ids.length) { this.toast('Chưa chọn ảnh nào để xóa.', 'error'); return false; }
      if (this.libraryCleaning) return false;
      this.libraryCleaning = true;
      try {
        const d = await this._libraryFetch('/api/library/bulk-delete', { ids });
        this.librarySelection = [];
        this.toast('Đã xóa ' + (d.deleted || 0) + ' mục · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadLibrary(true);
        return true;
      } catch (e) { this.toast(e.message || 'Lỗi xóa hàng loạt.', 'error'); return false; }
      finally { this.libraryCleaning = false; }
    },
    async libraryCleanup(scope) {
      if (this.libraryCleaning) return false;
      this.libraryCleaning = true;
      try {
        const d = await this._libraryFetch('/api/library/cleanup', { scope, old_days: this.libraryFilters.old_days || 30 });
        this.librarySelection = [];
        this.toast('Đã dọn xong · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadLibrary(true);
        await this.refreshLibraryScan();
        return true;
      } catch (e) { this.toast(e.message || 'Lỗi dọn dẹp.', 'error'); return false; }
      finally { this.libraryCleaning = false; }
    },
    formatBytes(bytes) {
      const n = Number(bytes) || 0;
      if (n < 1024) return n + ' B';
      if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
      if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
      return (n / 1073741824).toFixed(2) + ' GB';
    },
    // ── Files đã tải lên — quản lý + dọn file mồ côi ──
    async loadUploads() {
      if (this.uploadLoading) return;
      this.uploadLoading = true;
      try {
        const d = await this._libraryFetch('/api/uploads');
        this.uploadItems = Array.isArray(d.items) ? d.items : [];
        this.uploadStats = d.stats || null;
      } catch (e) { this.toast(e.message || 'Không tải được danh sách file.', 'error'); }
      finally { this.uploadLoading = false; }
    },
    /**
     * Gắn / bỏ gắn một ẢNH TẢI LÊN vào bộ sưu tập (dự án).
     *
     * nh tải lên là FILE trên đĩa (không có dòng trong CSDL) nên quan hệ này nằm ở bảng riêng
     * `upload_project_links` — xem POST /api/projects/{project}/uploads.
     *
     * Vì sao cần: hai loại ảnh nằm CÙNG một Thư viện nhưng trước đây chỉ ảnh do AI tạo mới vào được
     * bộ sưu tập; ảnh người dùng tự tải lên thì không, dù đó thường là ảnh gốc của cả bộ.
     */
    async attachUploadProject(projectId, rel, action = 'attach') {
      if (!rel) return false;
      if (action === 'attach' && !projectId) return false;
      try {
        const d = await this.api('/api/projects/' + projectId + '/uploads', { rel, action });
        const it = this.uploadItems.find(f => f.rel === rel);
        if (it) it.project_id = (d && d.project_id != null) ? d.project_id : null;
        this.toast(action === 'detach' ? 'Đã bỏ ảnh khỏi bộ sưu tập.' : 'Đã gắn ảnh vào bộ sưu tập.');
        return true;
      } catch (e) { this.toast(e.message || 'Không gắn được ảnh vào bộ sưu tập.', 'error'); return false; }
    },
    toggleUploadSelect(rel) {
      const i = this.uploadSelection.indexOf(rel);
      if (i >= 0) this.uploadSelection.splice(i, 1);
      else this.uploadSelection.push(rel);
    },
    uploadSelectUnused() {
      this.uploadSelection = this.uploadItems.filter(f => !f.used).map(f => f.rel);
    },
    uploadSelectNone() { this.uploadSelection = []; },
    async uploadBulkDelete() {
      const rels = this.uploadSelection.filter(Boolean);
      if (!rels.length) { this.toast('Chưa chọn file nào để xóa.', 'error'); return false; }
      if (this.uploadCleaning) return false;
      this.uploadCleaning = true;
      try {
        const d = await this._libraryFetch('/api/uploads/delete', { rels });
        this.uploadSelection = [];
        this.toast('Đã xóa ' + (d.deleted || 0) + ' file · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadUploads();
        return true;
      } catch (e) { this.toast(e.message || 'Lỗi xóa file.', 'error'); return false; }
      finally { this.uploadCleaning = false; }
    },
    async deleteUpload(rel) {
      if (!rel) return false;
      if (this.uploadCleaning) return false;
      this.uploadCleaning = true;
      try {
        const d = await this._libraryFetch('/api/uploads/delete', { rels: [rel] });
        this.uploadSelection = this.uploadSelection.filter(r => r !== rel);
        this.toast('Đã xóa ' + (d.deleted || 0) + ' file · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadUploads();
        return true;
      } catch (e) { this.toast(e.message || 'Lỗi xóa file.', 'error'); return false; }
      finally { this.uploadCleaning = false; }
    },
    async uploadCleanup() {
      if (this.uploadCleaning) return false;
      this.uploadCleaning = true;
      try {
        const d = await this._libraryFetch('/api/uploads/cleanup', {});
        this.uploadSelection = [];
        this.toast('Đã dọn xong · giải phóng ' + this.formatBytes(d.freed_bytes || 0) + '.');
        await this.loadUploads();
        return true;
      } catch (e) { this.toast(e.message || 'Lỗi dọn dẹp.', 'error'); return false; }
      finally { this.uploadCleaning = false; }
    },
    // ── Thư viện Prompt phân tích (💡 Gợi ý từ ảnh) — kế thừa pattern từ libraryItems ──
    async loadSuggestLib(reset = true) {
      if (this.suggestLibLoading) return;
      this.suggestLibLoading = true;
      if (reset) { this.suggestLibFilters.page = 1; }
      try {
        const q = new URLSearchParams();
        if (this.suggestLibFilters.q) q.set('q', this.suggestLibFilters.q);
        if (this.suggestLibFilters.garment_type) q.set('garment_type', this.suggestLibFilters.garment_type);
        if (this.suggestLibFilters.project_id) q.set('project_id', this.suggestLibFilters.project_id);
        q.set('page', String(this.suggestLibFilters.page));
        q.set('per_page', String(this.suggestLibFilters.per_page));
        if (this.suggestLibSort) q.set('sort', this.suggestLibSort);
        const d = await fetch('/api/suggest-library/data?' + q.toString(), { headers: { Accept: 'application/json' } });
        if (!d.ok) throw new Error('Không tải được thư viện prompt.');
        const data = await d.json();
        this.suggestLibItems = data.items || [];
        this.suggestLibTotal = data.total || 0;
        this.suggestLibStats = data.stats || null;
        this.suggestLibHasMore = data.has_more || false;
        this.suggestLibFilters.page = data.current_page || 1;
      } catch (e) { this.toast(e.message || 'Lỗi tải thư viện prompt.', 'error'); }
      finally { this.suggestLibLoading = false; }
    },
    setSuggestLibFilter(key, value) {
      this.suggestLibFilters[key] = value;
      this.suggestLibSelection = [];
      this.loadSuggestLib(true);
    },
    setSuggestLibSort(v) {
      this.suggestLibSort = v;
      this.suggestLibSelection = [];
      this.loadSuggestLib(true);
    },
    setLibrarySort(v) {
      this.librarySort = v;
      this.librarySelection = [];
      this.loadLibrary(true);
    },
    setUploadSort(v) {
      this.uploadSort = v; // client-side — không cần gọi lại API
    },
    suggestLibNextPage() {
      if (!this.suggestLibHasMore || this.suggestLibLoading) return;
      this.suggestLibFilters.page++;
      this.loadSuggestLib(false);
    },
    toggleSuggestLibSelect(id) {
      const i = this.suggestLibSelection.indexOf(id);
      if (i >= 0) this.suggestLibSelection.splice(i, 1);
      else this.suggestLibSelection.push(id);
    },
    suggestLibSelectAll() {
      this.suggestLibSelection = this.suggestLibItems.map(x => x.id);
    },
    suggestLibSelectNone() { this.suggestLibSelection = []; },
    async suggestLibBulkDelete() {
      const ids = this.suggestLibSelection.filter(Boolean);
      if (!ids.length) { this.toast('Chưa chọn prompt nào để xóa.', 'error'); return false; }
      try {
        const res = await fetch('/api/suggest-library/bulk-delete', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ ids }),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.message || 'Lỗi xóa.');
        this.suggestLibSelection = [];
        this.toast('Đã xóa ' + (d.deleted || 0) + ' prompt.');
        await this.loadSuggestLib(true);
        return true;
      } catch (e) { this.toast(e.message || 'Lỗi xóa prompt.', 'error'); return false; }
    },
    // ── CRUD prompt trong Thư viện Prompt (thêm / sửa / xóa đơn lẻ) ──
    async savePrompt(payload) {
      try {
        const d = await this._libraryFetch('/api/suggest-library', payload);
        this.toast('Đã thêm prompt vào Thư viện Prompt.');
        await this.loadSuggestLib(true);
        return d;
      } catch (e) { this.toast(e.message || 'Lỗi thêm prompt.', 'error'); return null; }
    },
    async updatePrompt(id, payload) {
      try {
        const res = await fetch('/api/suggest-library/' + id, {
          method: 'PUT',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(payload),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.message || 'Lỗi cập nhật.');
        this.toast('Đã cập nhật prompt.');
        await this.loadSuggestLib(true);
        return d;
      } catch (e) { this.toast(e.message || 'Lỗi cập nhật prompt.', 'error'); return null; }
    },
    async deletePrompt(id) {
      try {
        const res = await fetch('/api/suggest-library/' + id, {
          method: 'DELETE',
          headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' },
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.message || 'Lỗi xóa.');
        this.suggestLibSelection = this.suggestLibSelection.filter(x => x !== id);
        this.toast('Đã xóa prompt.');
        await this.loadSuggestLib(true);
        return true;
      } catch (e) { this.toast(e.message || 'Lỗi xóa prompt.', 'error'); return false; }
    },
    async saveSuggestResult() {
      if (!this.suggestResult || !this.suggestResult.image_prompt_en) {
        this.toast('Chưa có kết quả phân tích để lưu.', 'error');
        return;
      }
      if (this.suggestSaving) return;
      this.suggestSaving = true;
      try {
        const body = {
          reference_url: this.upscaleSrc || '',
          project_id: this.appliedProjectId() || null,
          ...this.suggestResult,
        };
        const res = await fetch('/api/suggest-library/save', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify(body),
        });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.message || 'Lỗi lưu.');
        this.toast('💾 Đã lưu prompt vào Thư viện Prompt.');
      } catch (e) { this.toast(e.message || 'Lỗi lưu prompt.', 'error'); }
      finally { this.suggestSaving = false; }
    },
    async applySuggestPrompt(item) {
      if (!item || !item.image_prompt_en) {
        this.toast('Prompt trống, không thể áp dụng.', 'error');
        return;
      }
      // Đưa prompt vào ô Tạo ảnh
      this.imagePromptEn = item.image_prompt_en;
      if (item.creative_level != null) this.creativeLevel = Number(item.creative_level);
      if (item.texture != null) this.texture = Number(item.texture);
      if (item.negative_prompt) this.negativePromptEn = item.negative_prompt;
      this.promptOpen = true;
      // Đánh dấu đã áp dụng
      try {
        await fetch('/api/suggest-library/apply/' + item.id, {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' },
          body: '{}',
        });
        // Cập nhật local item
        const local = this.suggestLibItems.find(x => x.id === item.id);
        if (local) { local.apply_count = (local.apply_count || 0) + 1; local.applied_at = new Date().toLocaleString('vi-VN'); }
      } catch (e) { /* bỏ qua lỗi — prompt vẫn được áp dụng */ }
      this.toast('Đã áp dụng prompt vào ô Tạo ảnh.');
    },
    // ═══════════════════════════════════════════════════════════════════
    // Dự án thiết kế (Project Workspace) — CRUD + workflow cho Designer.
    // ═══════════════════════════════════════════════════════════════════
    async loadProjects() {
      // §5.2: khi đang tải mà có yêu cầu mới, hàm cũ bỏ qua luôn -> nơi gọi (toggleArchived/
      // togglePending) tưởng đã refresh nhưng danh sách vẫn là bản cũ tới lần tải kế tiếp.
      // Nay đánh dấu để chạy lại ngay sau khi lượt đang chạy xong.
      if (this.projectLoading) { this._projectReloadQueued = true; return this.projects; }
      this.projectLoading = true;
      // Hàng đợi duyệt (Super Admin): ?scope=pending — bỏ param archived.
      const mkQuery = () => this.projectScope === 'pending'
        ? new URLSearchParams({ scope: 'pending' }).toString()
        : new URLSearchParams({ archived: this.projectsArchived ? '1' : '0' }).toString();
      try {
        let res = await fetch('/api/projects?' + mkQuery(), { headers: { Accept: 'application/json' } });
        if (res.status === 403 && this.projectScope === 'pending') {
          // Không (còn) là Super Admin → quay về scope cá nhân rồi tải lại.
          const msg = (await res.json().catch(() => ({}))).message || 'Bạn không có quyền xem hàng đợi duyệt.';
          this.projectScope = 'own';
          this.projectCanReview = false;
          this.toast(msg, 'error');
          res = await fetch('/api/projects?' + mkQuery(), { headers: { Accept: 'application/json' } });
        }
        if (res.status === 401 || res.status === 403) { this.setAuthStatus(res.status); return []; }
        const d = await res.json();
        this.projects = Array.isArray(d.items) ? d.items : [];
        if (d.statuses) this.projectStatuses = d.statuses;
        this.projectCanReview = !!d.can_review;
        if (d.scope === 'pending' || d.scope === 'own') this.projectScope = d.scope;
        if (Array.isArray(d.assignable)) this.assignableUsers = d.assignable;
        this.projectLoaded = true;
        return this.projects;
      } catch (e) {
        this.toast(e.message || 'Không tải được dự án.', 'error');
        return this.projects;
      } finally {
        this.projectLoading = false;
        if (this._projectReloadQueued) { this._projectReloadQueued = false; this.loadProjects(); }
      }
    },
    async loadProject(id, opts = {}) {
      // reviewOnly: mở dự án người khác (scope=pending) → chỉ xem, KHÔNG áp dụng
      // cho phiên tạo ảnh (tránh gắn output của reviewer vào dự án của designer).
      this.activeProjectReviewOnly = !!opts.reviewOnly;
      try {
        const res = await fetch('/api/projects/' + id, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || 'Không tải được dự án.');
        const d = await res.json();
        this.activeProject = d;
        this.activeProjectGenerations = d.generations || [];
        // Mở dự án của mình = đang làm việc trên nó → tự ÁP DỤNG cho phiên tạo ảnh/video.
        if (!this.activeProjectReviewOnly) this.appliedProject = d;
        return d;
      } catch (e) { this.toast(e.message || 'Lỗi tải dự án.', 'error'); return null; }
    },
    // ── Chia sẻ bộ sưu tập cho khách duyệt (Đợt 4) ────────────────────────────────────────
    // Card trong sidebar KHÔNG tự gọi API (bất biến: một đường dữ liệu đi qua store) — ba action dưới
    // đây là đường duy nhất tới /api/projects/{id}/share.
    async loadShareStatus(projectId) {
      try {
        const r = await fetch('/api/projects/' + projectId + '/share', { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return await r.json();
      } catch (e) {
        this.toast('Không tải được trạng thái chia sẻ: ' + e.message, 'error');
        return null;
      }
    },
    async createShare(projectId, days = 30) {
      try {
        const d = await this.api('/api/projects/' + projectId + '/share', { days: Number(days) || 30 });
        await navigator.clipboard?.writeText(d.url).then(() => this.toast('Đã tạo link chia sẻ và copy vào bộ nhớ tạm.')).catch(() => this.toast('Đã tạo link chia sẻ — bấm «Copy link» để lấy.'));
        return d;
      } catch (e) {
        this.toast('Không tạo được link: ' + e.message, 'error');
        return null;
      }
    },
    async revokeShare(projectId, token) {
      try {
        await this.api('/api/projects/' + projectId + '/share/' + token, { _method: 'DELETE' });
        this.toast('Đã thu hồi link — khách mở lại sẽ không xem được nữa.');
        return true;
      } catch (e) {
        this.toast('Không thu hồi được: ' + e.message, 'error');
        return false;
      }
    },
    /**
     * [Đợt 2] Thống kê một bộ sưu tập: số ảnh xong/đang chạy/lỗi · credit đã dùng · hạn còn lại · phản hồi.
     * Nạp theo yêu cầu và nhớ theo id (bấm qua lại giữa các bộ không gọi lại liên tục).
     */
    async loadProjectStats(projectId, force = false) {
      if (!projectId) return null;
      if (!force && this.projectStats[projectId]) return this.projectStats[projectId];
      try {
        const r = await fetch('/api/projects/' + projectId + '/stats', { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        this.projectStats = { ...this.projectStats, [projectId]: d };
        return d;
      } catch (e) {
        console.error('loadProjectStats failed', e);
        return null;
      }
    },
    /**
     * [Đợt 2] ẢNH của một bộ sưu tập kèm trạng thái duyệt — nguồn cho khối "Duyệt mẫu theo lô".
     *
     * Dùng lại GET /api/projects/{id} (đã trả generations) thay vì thêm endpoint đọc mới: dữ liệu
     * vốn có, chỉ thiếu đường tới giao diện. Lọc bỏ ảnh chưa tạo xong vì chưa có gì để duyệt.
     */
    async loadProjectShots(projectId, force = false) {
      if (!projectId) return [];
      const cached = this.projectShots[projectId];
      if (!force && cached && cached.items) return cached.items;
      try {
        const r = await fetch('/api/projects/' + projectId, { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        const items = (d.project?.generations || d.generations || [])
          .filter((g) => g.status === 'completed')
          .map((g) => ({
            id: g.id,
            thumb: g.media_url,
            shot_state: g.shot_state || 'drafted',
            shot_label: g.shot_label || 'Bản nháp',
            prompt: (g.prompt || '').slice(0, 120),
            created_at: g.created_at,
          }));
        this.projectShots = { ...this.projectShots, [projectId]: { items, loadedAt: Date.now() } };
        return items;
      } catch (e) {
        console.error('loadProjectShots failed', e);
        this.toast('Không nạp được danh sách ảnh của bộ sưu tập.', 'error');
        return [];
      }
    },
    /**
     * [Đợt 2] Duyệt/loại NHIỀU ảnh trong một lượt — trả về { reviewed, failed, results }.
     *
     * Máy chủ là nơi quyết định (quyền · ảnh thuộc bộ · ảnh đã xong · whitelist trạng thái); ở đây chỉ
     * cập nhật lại trạng thái của những ảnh ĐÃ đổi để giao diện khỏi phải nạp lại cả danh sách.
     */
    async reviewShots(projectId, ids, state, note = '') {
      if (!projectId || !ids || !ids.length) return null;
      try {
        const d = await this.api('/api/projects/' + projectId + '/shots/review', { ids, state, note });
        const entry = this.projectShots[projectId];
        if (entry && Array.isArray(entry.items)) {
          const changed = {};
          (d.results || []).forEach((r) => { if (r.ok) changed[r.id] = r.shot_state; });
          entry.items = entry.items.map((it) => (
            changed[it.id]
              ? { ...it, shot_state: changed[it.id], shot_label: this.shotLabel(changed[it.id]) }
              : it
          ));
          this.projectShots = { ...this.projectShots, [projectId]: entry };
        }
        return d;
      } catch (e) {
        this.toast('Không duyệt được: ' + e.message, 'error');
        return null;
      }
    },
    /** Nhãn tiếng Việt của trạng thái duyệt ảnh — KHỚP Generation::SHOT_LABELS ở máy chủ. */
    shotLabel(state) {
      const map = {
        idea: 'Ý tưởng', drafted: 'Bản nháp', selected: 'Đã chọn', fitted: 'Đã lên phom',
        campaign_ready: 'Chờ duyệt', approved: 'Đã duyệt', rejected: 'Đã loại',
      };
      return map[state] || 'Bản nháp';
    },
    // ── Mẫu việc theo ngành (Đợt 2) ───────────────────────────────────────────────────────
    async loadJobTemplates() {
      if (this.jobTemplatesLoaded) return this.jobTemplates;
      try {
        const r = await fetch('/api/job-templates', { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        this.jobTemplates = Array.isArray(d.templates) ? d.templates : [];
        this.jobTemplatesLoaded = true;
      } catch (e) { console.error('loadJobTemplates failed', e); }
      return this.jobTemplates;
    },
    /**
     * Áp một MẪU VIỆC: đặt tỉ lệ + độ phân giải, giữ bảng size/ghi chú cho gói xuất xưởng,
     * và trả về danh sách prompt để card đổ vào ô "Hàng loạt".
     */
    applyJobTemplate(tpl) {
      if (!tpl) return [];
      if (tpl.ratio) this.imageRatio = tpl.ratio;
      if (tpl.resolution) this.imageRes = tpl.resolution;
      this.pendingExport = tpl.export
        ? { from: tpl.title, sizes: tpl.export.sizes || '', note: tpl.export.note || '' }
        : null;
      const n = (tpl.prompts || []).length;
      this.toast('Đã áp mẫu «' + tpl.title + '»: ' + n + ' mục · tỉ lệ ' + tpl.ratio + ' · ' + tpl.resolution
        + (tpl.export ? ' · kèm bảng size cho xưởng' : ''));
      return tpl.prompts || [];
    },
    /** Yêu cầu StudioApp mở workspace Dự án/Bộ sưu tập (gọi từ card trong sidebar). */
    requestWorkspace() { this.workspaceOpenRequest = (this.workspaceOpenRequest || 0) + 1; },
    /**
     * [Trục 3 — 2026-09-20] Yêu cầu StudioApp CHUYỂN sang một nhóm công cụ (activity).
     *
     * Vì sao cần: nút "Biến thể"/"Sửa ảnh" nằm ở dock Outputs (component riêng), mà activity lại do
     * StudioApp quản lý — card render bằng <component :is> không nhận được event. Cùng cách đã dùng
     * cho requestWorkspace(): đếm yêu cầu, StudioApp theo dõi và đổi panel.
     */
    requestActivity(id) {
      if (!id) return;
      this.activityRequest = { id, n: ((this.activityRequest && this.activityRequest.n) || 0) + 1 };
    },
    /**
     * [Trục 1 — 2026-09-20] Đưa danh sách prompt của một MẪU VIỆC vào tab "Hàng loạt" của ConceptCard.
     *
     * Vì sao cần kênh riêng: màn hình canvas trống cho bấm mẫu việc, nhưng ô dán danh sách nằm trong
     * state CỤC BỘ của ConceptCard. Trước đây không có cách nào với tới nó ngoài việc tự tìm DOM —
     * cách đó vỡ ngay khi card đổi cấu trúc. Nay card tự đăng ký hàm nhận, ai cần thì gọi.
     */
    requestBatchPrompts(prompts, meta = {}) {
      const list = (prompts || []).map((p) => String(p).trim()).filter(Boolean);
      if (!list.length) return;
      this.batchFillRequest = { prompts: list, meta, n: ((this.batchFillRequest && this.batchFillRequest.n) || 0) + 1 };
      this.requestActivity('concept');
    },
    async createProject(payload) {
      try {
        const d = await this.api('/api/projects/new', payload);
        this.projects.unshift(d);
        this.toast('Đã tạo dự án "' + d.name + '".');
        return d;
      } catch (e) { this.toast(e.message || 'Lỗi tạo dự án.', 'error'); return null; }
    },
    async updateProject(id, payload) {
      try {
        const d = await this.api('/api/projects/' + id, { ...payload, _method: 'PUT' });
        const i = this.projects.findIndex(p => p.id === id);
        if (i >= 0) this.projects.splice(i, 1, d);
        if (this.activeProject && this.activeProject.id === id) this.activeProject = d;
        this.toast('Đã cập nhật dự án.');
        return d;
      } catch (e) { this.toast(e.message || 'Lỗi cập nhật.', 'error'); return null; }
    },
    async deleteProject(id) {
      try {
        await this.api('/api/projects/' + id, { _method: 'DELETE' });
        this.projects = this.projects.filter(p => p.id !== id);
        if (this.activeProject && this.activeProject.id === id) { this.activeProject = null; this.activeProjectGenerations = []; this.activeProjectReviewOnly = false; }
        if (this.appliedProject && this.appliedProject.id === id) this.appliedProject = null;
        this.toast('Đã xóa dự án (output được giữ lại).');
        return true;
      } catch (e) { this.toast(e.message || 'Lỗi xóa dự án.', 'error'); return false; }
    },
    async transitionProject(id, to, note = '') {
      try {
        const d = await this.api('/api/projects/' + id + '/transition', { to, note });
        const i = this.projects.findIndex(p => p.id === id);
        if (i >= 0) this.projects.splice(i, 1, d);
        if (this.activeProject && this.activeProject.id === id) this.activeProject = d;
        this.toast('Dự án → ' + (d.status_label || d.status) + '.');
        return d;
      } catch (e) { this.toast(e.message || 'Không thể chuyển trạng thái.', 'error'); return null; }
    },
    // Gắn/gỡ 1 HOẶC NHIỀU generation khỏi dự án + ĐỒNG BỘ mọi state liên quan (library, outputs
    // Studio, workspace đang mở, bộ đếm) — một nơi duy nhất để UI gọi.
    async attachGenerationToProject(projectId, generationId, action = 'attach') {
      const ids = Array.isArray(generationId) ? generationId : [generationId];
      const single = ids.length === 1;
      try {
        const body = single ? { generation_id: ids[0], action } : { ids, action };
        const res = await this.api('/api/projects/' + projectId + '/generations', body);
        const changed = (res && res.changed) || ids.length;
        const failed = (res && res.failed) || 0;
        const syncOne = (gid, newPid) => {
          const proj = this.projects.find(p => Number(p.id) === Number(newPid))
            || (this.appliedProject && Number(this.appliedProject.id) === Number(newPid) ? this.appliedProject : null)
            || (this.activeProject && Number(this.activeProject.id) === Number(newPid) ? this.activeProject : null);
          const pname = proj ? proj.name : null;
          const sync = (g) => { if (g && Number(g.id) === Number(gid)) { g.project_id = newPid; g.project = pname; } };
          (this.libraryItems || []).forEach(sync);
          (this.generations || []).forEach(sync);
          if (this.viewer && Number(this.viewer.id) === Number(gid)) { this.viewer.project_id = newPid; this.viewer.project = pname; }
          if (this.activeProject) {
            const inDetail = this.activeProjectGenerations.some(g => Number(g.id) === Number(gid));
            if (inDetail && Number(this.activeProject.id) !== Number(newPid)) {
              this.activeProjectGenerations = this.activeProjectGenerations.filter(g => Number(g.id) !== Number(gid));
              this.activeProject.generations_count = Math.max(0, (this.activeProject.generations_count || 1) - 1);
              if (this.viewerList) this.viewerList = this.viewerList.filter(g => Number(g.id) !== Number(gid));
            }
          }
          const bump = (pid, d) => { const p = this.projects.find(x => Number(x.id) === Number(pid)); if (p) p.generations_count = Math.max(0, (p.generations_count || 0) + d); };
          if (action === 'detach') bump(projectId, -1); else bump(newPid, +1);
        };
        if (single) {
          const newPid = (res && res.generation) ? res.generation.project_id : (action === 'detach' ? null : projectId);
          syncOne(ids[0], newPid);
          this.toast(action === 'attach' ? 'Đã gắn ảnh vào bộ sưu tập.' : 'Đã gỡ ảnh khỏi bộ sưu tập.');
        } else {
          const results = Array.isArray(res.results) ? res.results : ids.map(id => ({ id, ok: true }));
          results.forEach(r => {
            const newPid = r.ok ? (action === 'attach' ? projectId : null) : null;
            syncOne(r.id, newPid);
          });
          const done = results.filter(r => r.ok).length;
          if (failed) {
            this.toast('Đã xử lý ' + done + '/' + ids.length + ' ảnh. ' + failed + ' ảnh không thể ' + (action === 'attach' ? 'gắn' : 'gỡ') + ' (không đủ quyền hoặc không thuộc bộ).', 'error');
          } else {
            this.toast('Đã ' + (action === 'attach' ? 'gắn' : 'gỡ') + ' ' + done + ' ảnh vào bộ sưu tập.');
          }
        }
        return { ok: true, changed, failed };
      } catch (e) { this.toast(e.message || 'Lỗi gắn ảnh.', 'error'); return { ok: false, changed: 0, failed: ids.length }; }
    },
    // id dự án đang được ÁP DỤNG cho phiên tạo ảnh/video (Dự án hiện tại).
    // Tách khỏi activeProject: áp dụng tồn tại độc lập, không mất khi đóng workspace.
    appliedProjectId() {
      return this.appliedProject ? (this.appliedProject.id || null) : null;
    },
    // ÁP DỤNG 1 bộ sưu tập cụ thể cho phiên tạo ảnh/video — cơ chế tường minh,
    // dùng được từ workspace / header / thư viện mà không cần mở detail.
    applyProject(p) {
      if (!p || !p.id) return false;
      if (this.user && p.user_id && Number(p.user_id) !== Number(this.user.id)) {
        this.toast('Chỉ áp dụng được bộ sưu tập của chính bạn.', 'error');
        return false;
      }
      this.appliedProject = p;
      if (typeof localStorage !== 'undefined') {
        try { localStorage.setItem('studio_applied_project', JSON.stringify({ id: p.id, name: p.name })); } catch (e) {}
      }
      this.toast('Đã áp dụng bộ sưu tập "' + (p.name || ('#' + p.id)) + '" — ảnh/video tạo mới sẽ tự gắn vào.');
      return true;
    },
    // Ngắt "Bộ sưu tập hiện tại" — gỡ khỏi phiên tạo ảnh, quay về không áp dụng bộ nào.
    unapplyProject() {
      if (!this.appliedProject) return;
      this.appliedProject = null;
      this.outputFilterProject = false;
      if (typeof localStorage !== 'undefined') {
        try { localStorage.removeItem('studio_applied_project'); } catch (e) {}
      }
      this.toast('Đã ngắt bộ sưu tập hiện tại.');
    },
    // [P0.4] Khôi phục bộ sưu tập đang áp dụng từ localStorage sau khi tải lại trang (trước đây mất hết
    // khi F5 — người dùng mất hẳn ngữ cảnh "đang làm bộ nào").
    async restoreAppliedProject() {
      if (typeof localStorage === 'undefined') return;
      try {
        const raw = localStorage.getItem('studio_applied_project');
        if (!raw) return;
        const saved = JSON.parse(raw);
        if (!saved || !saved.id) return;
        if (!this.projects.length && !this.projectLoaded) await this.loadProjects();
        const p = this.projects.find(x => Number(x.id) === Number(saved.id));
        if (p) {
          this.appliedProject = p;
        } else {
          localStorage.removeItem('studio_applied_project');
        }
      } catch (e) { /* ignore */ }
    },
    // Đóng detail trong workspace — CHỈ thoát chế độ xem, GIỮ dự án đang áp dụng.
    clearActiveProject() {
      this.activeProject = null;
      this.activeProjectGenerations = [];
      this.activeProjectReviewOnly = false;
    },
    // Mở GalleryModal với ngữ cảnh danh sách rõ ràng (mặc định: thư viện / outputs).
    openViewer(g, list = null) {
      this.viewerList = list;
      this.viewer = g;
    },
    // Điều hướng chuẩn khi bấm "Chỉnh sửa" / "Tạo video" từ GalleryModal —
    // hoạt động ở MỌI nơi GalleryModal được mở (Studio 1 trang / Studio Library / …):
    //  - đang ở trang studio (có step 1/2/3): chọn ảnh + chuyển step ngay.
    //  - đang ở trang library (không có step): redirect về /studio?step=&id= để
    //    StudioApp (qua load()) khôi phục đúng bước + đúng ảnh đang chọn.
    goEditor(g, step) {
      if (!g) return;
      this.viewer = null;
      this.select(g);
      const onLibrary = window.location.pathname.includes('/library');
      if (onLibrary) {
        window.location.href = '/?step=' + step + '&id=' + g.id;
      } else {
        this.step = step;
      }
    },
    goEdit(g) { this.goEditor(g, 2); },
    goVideo(g) { this.goEditor(g, 3); },
    pushCanvasLayer(id, kind, name, image, genId) {
      if (!id || !image) return;
      if (this.canvasLayers.some(l => l.id === id)) return;
      const i = this.canvasLayers.length;
      const x = (i % 3) * 360, y = Math.floor(i / 3) * 460; // vị trí tạm, sẽ căn lại theo kích thước thật
      this.canvasLayers.push({ id, kind, name, image, genId, visible: true, locked: false, x, y, scale: 1, rotation: 0, opacity: 1, blend: 'normal', flipX: false, flipY: false });
      this.saveLayerLayout();
      this._positionByImageSize(id, image);
    },
    // Map tỷ lệ khung hình sang kích thước layer (base = 1024 cho cạnh DÀI hơn).
    ratioToSize(r) {
      const map = { '1:1': [1, 1], '4:3': [4, 3], '3:4': [3, 4], '9:16': [9, 16], '16:9': [16, 9], '4:5': [4, 5], '21:9': [21, 9], '2:3': [2, 3] };
      const [rw, rh] = map[r] || [1, 1];
      const base = 1024;
      const w = Math.round(rw >= rh ? base : base * (rw / rh));
      const h = Math.round(rh >= rw ? base : base * (rh / rw));
      return { w, h };
    },
    // Thêm 1 layer TRỐNG (trong suốt) để vẽ — nền tảng cho hiệu ứng sau (brush/vẽ tự do GIMP/PS).
    async addBlankLayer(bg = null, ratio = null) {
      this.pushHistory();
      const src = this.activeLayer;
      // Ưu tiên 1: nếu đang có ẢNH CHỌN (active layer có image) → tạo layer mới ĐÚNG KÍCH THƯỚC ảnh hiện tại.
      // Ưu tiên 2: tạo layer TRỐNG (không chọn ảnh) → tôn trọng preset tỷ lệ khung hình (imageRatio / ratio).
      let w = 0, h = 0;
      if (src && src.image) {
        try {
          const img = await this._loadImageSrc(src.image);
          if (img.naturalWidth && img.naturalHeight) { w = img.naturalWidth; h = img.naturalHeight; }
        } catch (e) { /* giữ mặc định (theo tỷ lệ) */ }
      }
      if (!w || !h) { const rt = this.ratioToSize(ratio || this.imageRatio); w = rt.w; h = rt.h; }
      // baseW/baseH = kích thước HIỂN THỊ (cạnh dài tối đa 512 — khớp CSS max-w/max-h của <img>).
      // Ảnh canvas vẫn giữ FULL w×h, chỉ có baseW/baseH được cap để overlay/mask/pointer khớp 1:1.
      const MAXB = 512;
      const bcap = Math.min(1, MAXB / w, MAXB / h);
      const bw = Math.max(1, Math.round(w * bcap));
      const bh = Math.max(1, Math.round(h * bcap));
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      if (bg) {
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, w, h);
      }
      const url = canvas.toDataURL('image/png');
      const id = 'blank-' + Date.now();
      // Thêm TRÙNG KHỚP với layer active (cùng x/y/scale/rotation/blend), không flow ra chỗ khác.
      this.canvasLayers.push({
        id, kind: 'source', name: bg ? 'Layer màu' : 'Layer trong suốt', image: url, genId: null,
        visible: true, locked: false,
        x: src ? (src.x || 0) : 0, y: src ? (src.y || 0) : 0,
        scale: src ? (src.scale || 1) : 1, rotation: src ? (src.rotation || 0) : 0,
        opacity: src ? (src.opacity != null ? src.opacity : 1) : 1,
        blend: src ? (src.blend || 'normal') : 'normal', flipX: false, flipY: false,
        baseW: bw, baseH: bh,
      });
      this.saveLayerLayout();
      // Highlight layer mới (viền nổi bật) rồi tự tắt sau 2.5s hoặc khi người dùng chọn layer khác.
      this.highlightLayerId = id;
      clearTimeout(this._highlightTimer);
      this._highlightTimer = setTimeout(() => { if (this.highlightLayerId === id) this.highlightLayerId = ''; }, 2500);
      this.setActiveLayer(id);
      this.toast(bg ? 'Đã thêm layer màu (trùng vị trí layer đang chọn).' : 'Đã thêm layer trong suốt (trùng vị trí layer đang chọn).');
    },
    // Tô màu TOÀN BỘ layer đang chọn bằng màu hiện tại (inpaintFillColor).
    async fillActiveLayer() {
      const l = this.activeLayer;
      if (!l) { this.toast('Chưa có layer để tô — thêm 1 layer trước.', 'error'); return; }
      this.pushHistory();
      let w = 1024, h = 1024;
      if (l.image) {
        try {
          const img = await this._loadImageSrc(l.image);
          if (img.naturalWidth && img.naturalHeight) { w = img.naturalWidth; h = img.naturalHeight; }
        } catch (e) { /* giữ mặc định */ }
      }
      const canvas = document.createElement('canvas');
      canvas.width = w; canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = this.inpaintFillColor || '#ffffff';
      ctx.fillRect(0, 0, w, h);
      l.image = canvas.toDataURL('image/png');
      this.saveLayerLayout();
      this.toast('Đã tô màu toàn bộ layer.');
    },
    // Lưu layer active (kể cả layer vẽ/fill/duplicate chưa từng là generation) vào Output.
    async saveActiveLayerToOutput() {
      const l = this.activeLayer;
      if (!l || !l.image) { this.toast('Chưa có layer để lưu.', 'error'); return; }
      // KHÔNG lưu trùng: ảnh đã có trong Output thì dừng ngay, không gọi máy chủ (tránh rác Outputs).
      if (this.activeLayerInOutputs) { this.toast('Ảnh này đã có trong Output — không lưu trùng.', 'error'); return; }
      try {
        // Ảnh ĐÃ nằm trên máy chủ (layer lấy từ Output / ảnh nguồn) ⇒ gửi ĐƯỜNG DẪN, KHÔNG tải lại byte nào.
        // Trước đây luôn tải lên lại: đo thật layer 2K ≈ 0,9 MB và layer ghép 2400×2400 ≈ 4,4 MB cho MỘT
        // thao tác lưu — trên mạng thật là hàng chục giây chờ vô ích.
        const raw = String(l.image);
        const isServerUrl = raw.startsWith('/storage/') || raw.startsWith(location.origin + '/storage/');
        let d;
        if (isServerUrl) {
          d = await this.api('/api/layers/save', {
            source_url: raw.replace(location.origin, ''),
            name: l.name || '',
            ...this.projectField(),
          });
        } else {
          // Layer GHÉP trên canvas (data URL): chưa có trên máy chủ nên buộc phải tải lên.
          const blob = await (await fetch(raw)).blob();
          const fd = new FormData();
          fd.append('image', new File([blob], 'layer-' + Date.now() + '.png', { type: 'image/png' }));
          fd.append('name', l.name || '');
          const pid = this.appliedProjectId();
          if (pid) fd.append('project_id', String(pid));
          const res = await fetch('/api/layers/save', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' }, body: fd });
          d = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(d.message || 'Không lưu được.');
        }
        if (!d || !d.generation_id) throw new Error((d && d.message) || 'Không lưu được.');
        // Bản ghi THẬT (id trong CSDL) ⇒ ảnh vào được Thư viện và còn nguyên sau khi tải lại trang.
        // Dùng unshift trực tiếp thay vì addGen: addGen đẩy thêm một layer canvas nữa, mà layer đang lưu
        // đã có sẵn trên canvas rồi (sẽ thành hai layer trùng nhau).
        this.generations.unshift({
          id: d.generation_id, type: 'image', status: 'completed', model: 'layer', provider: 'layer',
          media_url: d.media_url, prompt: 'Lưu layer từ canvas', error: null, credits_cost: 0,
          created_at: 'Vừa lưu', project_id: d.project_id ?? null,
          project: (d.project_id && this.appliedProject && Number(this.appliedProject.id) === Number(d.project_id)) ? this.appliedProject.name : null,
        });
        // Đánh dấu NGAY layer này đã có bản trong Output ⇒ nút "Lưu Output" hết sáng, và lần bấm sau
        // bị chặn bởi activeLayerInOutputs (layer ghép bằng data URL không đổi danh tính sau khi lưu,
        // nên nếu không đánh dấu thì người dùng vẫn thấy nút sáng và bấm lại sẽ tạo bản trùng).
        l.savedOutputId = d.generation_id;
        this.toast('Đã lưu layer vào Output và Thư viện.');
      } catch (e) { this.toast(e.message || 'Không lưu được.', 'error'); }
    },
    // Đo kích thước thật của ảnh rồi xếp theo flow: hàng ngang (có gap), tự xuống hàng khi quá rộng.
    _positionByImageSize(id, image) {
      const MAX = 512, GAP = 24;
      const img = new Image();
      img.onload = () => {
        const l = this.canvasLayers.find((x) => x.id === id);
        if (!l) return;
        const base = Math.min(1, MAX / img.naturalWidth, MAX / img.naturalHeight);
        l.baseW = img.naturalWidth * base;
        l.baseH = img.naturalHeight * base;
        const prev = this.canvasLayers.filter((x) => x.id !== id && x.baseW != null);
        if (!prev.length) { l.x = 0; l.y = 0; }
        else {
          const last = prev[prev.length - 1];
          const lw = (last.baseW || MAX) * (last.scale || 1);
          const lh = (last.baseH || MAX) * (last.scale || 1);
          let nx = (last.x || 0) + lw / 2 + l.baseW / 2 + GAP;
          let ny = last.y || 0;
          const ROW_MAX = 3 * (MAX + GAP);
          if (nx > ROW_MAX) { nx = 0; ny = (last.y || 0) + lh + GAP; }
          l.x = nx; l.y = ny;
        }
        this.saveLayerLayout();
      };
      img.onerror = () => {};
      img.src = image;
    },
    _setActive(id) { if (!id) { this.activeLayerId = ''; this.editSource = null; this.previewId = null; this.preview = null; return; } const l = this.canvasLayers.find(x => x.id === id); if (!l) return; if (l.visible === false) l.visible = true; this.activeLayerId = id; if (this.highlightLayerId && this.highlightLayerId !== id) this.highlightLayerId = ''; if (l.kind === 'source') { this.editSource = { url: l.image, name: l.name }; this.previewId = null; this.preview = null; } else if (l.genId) { const g = this.generations.find(x => x.id === l.genId); if (g) { this.previewId = g.id; this.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed' }; } this.editSource = null; } },
    setActiveLayer(id) { this._setActive(id); this.selectedLayerIds = []; this.saveLayerLayout(); },
    // Shift+click: thêm/bỏ một layer vào nhóm chọn nhiều (không xáo trộn nhóm).
    isSelected(id) { return this.selectedIds.includes(id); },
    // Nhóm có đang là ĐỐI TƯỢNG được chọn trọn (để highlight folder thay vì layer thành viên).
    isGroupActive(gid) { const a = this.activeLayer; if (!a || a.groupId !== gid) return false; const g = this.layerGroups.find(x => x.id === gid); return !!g && g.layerIds.length > 1 && this.selectedIds.length === g.layerIds.length; },
    shiftSelectLayer(id) { const l = this.canvasLayers.find(x => x.id === id); if (!l) return; const g = l.groupId ? this.layerGroups.find(x => x.id === l.groupId) : null; const toggles = (g && g.layerIds.length > 1) ? g.layerIds.filter(xs => this.canvasLayers.some(l2 => l2.id === xs)) : [id]; const prev = this.activeLayerId; const already = toggles.some(xs => this.selectedIds.includes(xs)); if (already) { this.selectedLayerIds = this.selectedLayerIds.filter(xs => !toggles.includes(xs)); if (toggles.includes(prev)) { const rest = this.selectedIds.length ? this.selectedIds[this.selectedIds.length - 1] : ''; this._setActive(rest); } } else { this.selectedLayerIds = this.selectedLayerIds.filter(xs => !toggles.includes(xs)); if (prev && !this.selectedLayerIds.includes(prev)) this.selectedLayerIds.push(prev); toggles.forEach(xs => { if (xs !== prev && !this.selectedLayerIds.includes(xs)) this.selectedLayerIds.push(xs); }); this._setActive(toggles[toggles.length - 1]); } this.saveLayerLayout(); },
    clearSelection() { this.selectedLayerIds = []; if (this.activeLayerId) this.saveLayerLayout(); },
    // Trả về layer của một nhóm (nếu layer thuộc nhóm) cho thao tác "chọn cả nhóm".
    selectLayerWithGroup(id) {
      const lid = (id && typeof id === 'object') ? id.id : id; // chấp nhận cả object lẫn id
      const l = this.canvasLayers.find(x => x.id === lid); if (!l) return;
      const g = l.groupId ? this.layerGroups.find(x => x.id === l.groupId) : null;
      if (g && g.layerIds.length > 1 && g.layerIds.includes(id)) {
        this._setActive(id);
        this.selectedLayerIds = g.layerIds.filter(x => x !== id && this.canvasLayers.some(l2 => l2.id === x));
      } else {
        this.setActiveLayer(id);
      }
      this.saveLayerLayout();
    },
    // Tạo NHÓM từ các layer đang chọn (≥2) — tiền đề cho tính năng group đầy đủ sau.
    groupSelection() {
      const ids = this.selectedIds; if (ids.length < 2) { this.toast('Chọn ít nhất 2 layer để tạo nhóm.', 'error'); return; }
      if (ids.some(id => { const l = this.canvasLayers.find(x => x.id === id); return l && l.groupId; })) { this.toast('Đã có layer thuộc nhóm — chọn các layer chưa nhóm.', 'error'); return; }
      const gid = 'g-' + Date.now();
      this.layerGroups.push({ id: gid, name: 'Nhóm ' + (this.layerGroups.length + 1), layerIds: ids, rotation: 0, scale: 1 });
      this.canvasLayers.forEach(l => { if (ids.includes(l.id)) l.groupId = gid; });
      this._setActive(ids[0]);
      this.selectedLayerIds = ids.filter(x => x !== ids[0]);
      this.saveLayerLayout();
      this.toast('Đã tạo nhóm ' + ids.length + ' layer — click 1 layer trong nhóm sẽ chọn cả nhóm.');
    },
    // Tách nhóm: bỏ groupId của các layer đang chọn + xóa nhóm rỗng.
    ungroupSelection() {
      const gids = new Set();
      this.selectedIds.forEach(id => { const l = this.canvasLayers.find(x => x.id === id); if (l && l.groupId) gids.add(l.groupId); });
      if (!gids.size) { this.toast('Không có nhóm nào để tách.', 'error'); return; }
      this.canvasLayers.forEach(l => { if (l.groupId && gids.has(l.groupId)) l.groupId = ''; });
      this.layerGroups = this.layerGroups.filter(g => !gids.has(g.id));
      this.saveLayerLayout();
      this.toast('Đã tách nhóm — các layer trở về độc lập.');
    },
    // Chọn toàn bộ layer của một nhóm (khi nhấn folder).
    selectGroup(gid) { const g = this.layerGroups.find(x => x.id === gid); if (!g) return; const ids = g.layerIds.filter(id => this.canvasLayers.some(l => l.id === id)); if (!ids.length) return; this._setActive(ids[ids.length - 1]); this.selectedLayerIds = ids.filter(x => x !== ids[ids.length - 1]); this.saveLayerLayout(); },
    // Tách riêng một nhóm (nút trong dock thuộc tính).
    ungroupGroup(gid) { const g = this.layerGroups.find(x => x.id === gid); if (!g) return; this.canvasLayers.forEach(l => { if (l.groupId === gid) l.groupId = ''; }); this.layerGroups = this.layerGroups.filter(x => x.id !== gid); this.saveLayerLayout(); this.toast('Đã tách nhóm.'); },
    // ── Nhóm layer: khóa / nhân đôi / xóa (giống tính năng của layer) ──
    toggleGroupLock(gid) {
      const g = this.layerGroups.find(x => x.id === gid); if (!g) return;
      const members = this.canvasLayers.filter(l => g.layerIds.includes(l.id));
      if (!members.length) return;
      const anyUnlocked = members.some(l => !l.locked);
      members.forEach(l => { l.locked = anyUnlocked; });
      this.saveLayerLayout();
      this.toast(anyUnlocked ? 'Đã khóa nhóm.' : 'Đã mở khóa nhóm.');
    },
    duplicateGroup(gid) {
      const g = this.layerGroups.find(x => x.id === gid); if (!g) return;
      const originals = g.layerIds.map(id => this.canvasLayers.find(l => l.id === id)).filter(Boolean);
      if (!originals.length) return;
      this.pushHistory();
      const ts = Date.now(), gid2 = 'g2-' + ts, newIds = [];
      originals.forEach(l => {
        const cid = l.id + '-dup-' + ts + '-' + newIds.length;
        this.canvasLayers.push({ id: cid, kind: l.kind, name: (l.name || 'Ảnh') + ' (bản sao)', image: l.image, genId: l.genId, visible: true, locked: false, x: (l.x || 0) + 40, y: (l.y || 0) + 40, scale: l.scale || 1, rotation: l.rotation || 0, opacity: l.opacity != null ? l.opacity : 1, blend: l.blend || 'normal', flipX: !!l.flipX, flipY: !!l.flipY, baseW: l.baseW, baseH: l.baseH, groupId: gid2 });
        newIds.push(cid);
      });
      this.layerGroups.push({ id: gid2, name: 'Nhóm ' + (this.layerGroups.length + 1), layerIds: newIds, rotation: 0, scale: 1 });
      this._setActive(newIds[newIds.length - 1]);
      this.selectedLayerIds = newIds.filter(x => x !== newIds[newIds.length - 1]);
      this.saveLayerLayout();
      this.toast('Đã nhân đôi nhóm (' + newIds.length + ' layer).');
    },
    deleteGroup(gid) {
      const g = this.layerGroups.find(x => x.id === gid); if (!g) return;
      this.pushHistory();
      if (g.layerIds.includes(this.activeLayerId)) this._setActive('');
      this.canvasLayers = this.canvasLayers.filter(l => l.groupId !== gid);
      this.layerGroups = this.layerGroups.filter(x => x.id !== gid);
      this.selectedLayerIds = [];
      this.saveLayerLayout();
      this.toast('Đã xóa nhóm.');
    },
    // ── Tải ảnh một layer về máy (dùng cho tải hàng loạt) ──
    async _downloadLayerImage(l) {
      if (!l || !l.image) return false;
      try {
        const res = await fetch(l.image); if (!res.ok) throw new Error();
        const blob = await res.blob();
        const ext = blob.type === 'image/png' ? 'png' : blob.type === 'image/webp' ? 'webp' : blob.type === 'image/gif' ? 'gif' : 'jpg';
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a'); a.href = url; a.download = (((l.name || 'anh').replace(/\.[^.]+$/, '') || 'anh') + '.' + ext);
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        return true;
      } catch (e) { return false; }
    },
    // Tải HÀNG LOẠT các layer đang chọn (popup chọn nhiều).
    downloadSelection() {
      const sels = this.selection; if (!sels.length) { this.toast('Chưa chọn layer.', 'error'); return; }
      let ok = 0;
      sels.forEach((l, i) => setTimeout(async () => { if (await this._downloadLayerImage(l)) ok++; if (i === sels.length - 1) this.toast('Đã tải ' + ok + '/' + sels.length + ' layer.'); }, i * 300));
    },
    // Đổi tên nhóm (có thể rename trên canvas).
    renameGroup(gid, name) {
      const g = this.layerGroups.find(x => x.id === gid); if (!g) return;
      const n = (name || '').trim(); if (!n) return;
      g.name = n;
      this.saveLayerLayout();
    },
    // Tên nhóm của layer NẾU layer này là đại diện (trên cùng) của nhóm — để hiện badge canvas.
    groupLabel(l) {
      if (!l || !l.groupId) return '';
      const g = this.layerGroups.find(x => x.id === l.groupId); if (!g) return '';
      const members = this.canvasLayers.filter(x => x.groupId === l.groupId);
      const top = members[members.length - 1];
      return (top && top.id === l.id) ? (g.name || 'Nhóm') : '';
    },
    groupOf(id) { const l = this.canvasLayers.find(x => x.id === id); return l ? (this.layerGroups.find(g => g.id === l.groupId) || null) : null; },
    // Quét (marquee) chọn nhiều layer trong một hình chữ nhật — toạ độ canvas.
    selectInRect(x0, y0, x1, y1) {
      const mnx = Math.min(x0, x1), mxx = Math.max(x0, x1), mny = Math.min(y0, y1), mxy = Math.max(y0, y1);
      const hits = [];
      this.canvasLayers.forEach(l => {
        if (l.visible === false) return;
        const b = this._layerBox(l); const cx = l.x || 0, cy = l.y || 0;
        const lx = cx - b.w / 2, rx = cx + b.w / 2, ty = cy - b.h / 2, by = cy + b.h / 2;
        if (lx <= mxx && rx >= mnx && ty <= mxy && by >= mny) hits.push(l);
      });
      if (!hits.length) { this.deselectAll(); return; }
      const ids = new Set();
      hits.forEach(l => { ids.add(l.id); const g = l.groupId ? this.layerGroups.find(x => x.id === l.groupId) : null; if (g) g.layerIds.forEach(x => ids.add(x)); });
      const arr = [...ids];
      this._setActive(arr[arr.length - 1]);
      this.selectedLayerIds = arr.filter(x => x !== arr[arr.length - 1]);
      this.saveLayerLayout();
    },
    selectLayer(item) { if (!item) return; this.setActiveLayer(item.id); },
    // Gỡ layer KHỎI CANVAS (chỉ ảnh hưởng hiển thị) — KHÔNG xóa output/ảnh kết quả hay file nguồn.
    deleteLayer(item) {
      if (!item) return;
      if (item.locked) { this.toast('Layer đang khóa — mở khóa trước khi gỡ.', 'error'); return; }
      this.pushHistory();
      const wasSource = item.kind === 'source' || item.id === 'source';
      this.canvasLayers = this.canvasLayers.filter((l) => l.id !== item.id);
      if (wasSource) this.editSource = null;
      if (this.activeLayerId === item.id) {
        const next = this.canvasLayers.find((x) => x.visible !== false);
        if (next) this.selectLayer(next);
        else { this.activeLayerId = ''; this.editSource = null; this.previewId = null; this.preview = null; }
      }
      this.saveLayerLayout();
    },
    // Bỏ ảnh nguồn khỏi canvas (không xóa file/output).
    clearSource() {
      this.editSource = null;
      this.canvasLayers = this.canvasLayers.filter((l) => l.id !== 'source');
      if (this.activeLayerId === 'source') {
        const next = this.canvasLayers.find((x) => x.visible !== false);
        if (next) this.selectLayer(next);
        else { this.activeLayerId = ''; this.previewId = null; this.preview = null; }
      }
      this.saveLayerLayout();
    },
    // Bỏ ĐÚNG ảnh nguồn đang dùng (id 'source' HOẶC các layer src-* do thêm nhiều ảnh) khỏi canvas.
    // clearSource() cũ chỉ xoá layer id 'source' → với ảnh nguồn có id 'src-…' thì ảnh vẫn nằm
    // trên canvas dù nút "Bỏ ảnh nguồn" đã ẩn (trạng thái treo, gây nhầm).
    removeEditSource() {
      const src = this.editSource;
      if (!src) return;
      const pool = this.canvasLayers.filter((x) => x.kind === 'source' && x.image === src.url);
      if (!pool.length) { this.editSource = null; this.saveLayerLayout(); return; }
      // Ưu tiên layer đang active (nhiều layer có thể dùng chung URL sau duplicate).
      const l = pool.find((x) => x.id === this.activeLayerId) || pool[0];
      this.deleteLayer(l); // xoá layer + chuyển active đúng cách (tôn trọng lock)
    },
    // Mở popup xác nhận dọn canvas (LayersPanel) — tự reset sau 5s nếu không confirm.
    openClearCanvasConfirm() {
      this.confirmClearCanvasOpen = true;
      clearTimeout(this._clearCanvasTimer);
      this._clearCanvasTimer = setTimeout(() => { this.confirmClearCanvasOpen = false; }, 5000);
    },
    // Dọn sạch canvas + toàn bộ layer (chỉ xóa trạng thái hiển thị — KHÔNG xóa output/ảnh kết quả).
    cleanCanvas() {
      this.pushHistory();
      this.confirmClearCanvasOpen = false;
      this.previewId = null;
      this.preview = null;
      this.editSource = null;
      this.canvasLayers = [];
      this.layerGroups = [];
      this.selectedLayerIds = [];
      this.activeLayerId = '';
      this.palette = [];
      this.pan = { x: 0, y: 0 };
      this.zoom = 1;
      this.saveLayerLayout();
      this.toast('Đã dọn canvas — ảnh kết quả vẫn còn trong Kết quả/Thư viện.');
    },
    // Đổi tên layer. Với layer ảnh kết quả (gen), tên mới cũng được lưu vào generation
    // để hiển thị đồng bộ ở Output/Thư viện.
    renameLayer(id, name) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      if (l.locked) { this.toast('Layer đang khóa.', 'error'); return; }
      const n = (name || '').trim();
      if (!n) return;
      l.name = n;
      if (l.kind === 'source' && this.editSource) this.editSource.name = n;
      if (l.kind === 'gen' && l.genId) {
        const g = this.generations.find((x) => x.id === l.genId);
        if (g) { g.meta = Object.assign({}, g.meta || {}, { name: n }); }
        // §5.2: trước đây .catch(() => {}) nuốt lỗi rồi VẪN toast "Đã đổi tên layer." — UI báo
        // thành công kể cả khi server không lưu (hết phiên / 500). Nay phản ánh đúng kết quả.
        fetch('/api/generations/' + l.genId + '/rename', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ name: n }) })
          .then((r) => {
            this.saveLayerLayout();
            if (!r.ok) throw new Error('HTTP ' + r.status);
            this.toast('Đã đổi tên layer.');
          })
          .catch((e) => {
            console.error('studio rename generation failed', e);
            this.toast('Đã đổi tên trên canvas nhưng CHƯA lưu được lên máy chủ — thử lại sau khi tải lại trang.', 'error');
          });
        return;
      }
      this.saveLayerLayout();
      this.toast('Đã đổi tên layer.');
    },
    // Di chuyển layer lên/xuống TRONG NGĂN XẾP (không ảnh hưởng output).
    // Quy ước: mảng canvasLayers[0..n-1] = dưới→trên (zIndex = vị trí mảng); ngăn xếp HIỂN THỊ
    // front-first (layer trên cùng danh sách = đang ở TRƯỚC). Vì vậy:
    //   'up'   = lên đầu danh sách = RA PHÍA TRƯỚC (index +1)
    //   'down' = xuống cuối danh sách = VỀ PHÍA SAU (index -1)
    moveLayer(id, dir) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l || l.locked) return;
      this.pushHistory();
      const i = this.canvasLayers.findIndex((x) => x.id === id);
      const j = i + (dir === 'up' ? 1 : -1);
      if (i < 0 || j < 0 || j >= this.canvasLayers.length) return;
      const arr = this.canvasLayers.slice();
      const t = arr[i]; arr[i] = arr[j]; arr[j] = t;
      this.canvasLayers = arr;
      this.saveLayerLayout();
    },
    // ── Transform layer (vị trí / kích thước / xoay / opacity / blend) ──
    updateLayerTransform(id, patch) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      Object.assign(l, patch);
      this.saveLayerLayout();
    },
    resetLayerTransform(id) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      this.pushHistory();
      Object.assign(l, { x: 0, y: 0, scale: 1, rotation: 0, opacity: 1, blend: 'normal' });
      this.saveLayerLayout();
    },
    // Nhân đôi layer (giữ nguyên transform, tạo id + tên mới). Bản sao được đẩy lệch 12px để
    // phân biệt ngay với bản gốc (đang chồng khít) + viền highlight như layer mới.
    duplicateLayer(id) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      this.pushHistory();
      const copy = {
        ...l,
        id: id + '-copy-' + Date.now(),
        name: (l.name || 'Layer') + ' (bản sao)',
        x: (Number(l.x) || 0) + 12,
        y: (Number(l.y) || 0) + 12,
        groupId: '', // bản sao ĐƠN lẻ không kế thừa group nguồn
      };
      this.canvasLayers.push(copy);
      this.highlightLayerId = copy.id;
      clearTimeout(this._highlightTimer);
      this._highlightTimer = setTimeout(() => { if (this.highlightLayerId === copy.id) this.highlightLayerId = ''; }, 2500);
      this.setActiveLayer(copy.id);
      this.saveLayerLayout();
    },
    // Đưa layer lên trên cùng ('front') hoặc xuống dưới cùng ('back').
    bringLayerTo(id, where) {
      const i = this.canvasLayers.findIndex((x) => x.id === id);
      if (i < 0) return;
      this.pushHistory();
      const arr = this.canvasLayers.slice();
      const item = arr.splice(i, 1)[0];
      if (where === 'front') arr.push(item);
      else arr.unshift(item);
      this.canvasLayers = arr;
      this.saveLayerLayout();
    },
    // Đưa ĐƠN VỊ (group = nguyên nhóm, giữ thứ tự nội bộ) lên đầu / xuống đáy.
    bringUnitTo(id, where) {
      const l = this.canvasLayers.find((x) => x.id === id); if (!l) return;
      const ids = l.groupId ? this.canvasLayers.filter(x => x.groupId === l.groupId).map(x => x.id) : [id];
      this.pushHistory();
      const rest = this.canvasLayers.filter(x => !ids.includes(x.id));
      const unit = this.canvasLayers.filter(x => ids.includes(x.id));
      this.canvasLayers = where === 'front' ? [...rest, ...unit] : [...unit, ...rest];
      this.saveLayerLayout();
    },
    // Bật/tắt panel Layers dock (Designer Workspace) — CanvasStatusBar/LayersPanel header.
    toggleInspector() { this.inspectorOpen = !this.inspectorOpen; },
    toggleOutputDock() { this.outputDockOpen = !this.outputDockOpen; },
    // Kéo-thả sắp xếp: đặt layer 'id' NGAY TRƯỚC (placeAfter=false) hoặc NGAY SAU (true)
    // 'targetId' trong stack canvasLayers (index 0 = dưới cùng, cuối = trước nhất).
    // Layer bị khóa không cho kéo; thả quanh target khóa vẫn hợp lệ. No-op khi kéo lên chính nó.
    reorderLayer(id, targetId, placeAfter) {
      const arr = this.canvasLayers.slice();
      const i = arr.findIndex((x) => x.id === id);
      const t = arr.findIndex((x) => x.id === targetId);
      if (i < 0 || t < 0 || i === t) return;
      if (arr[i].locked) return;
      this.pushHistory();
      const item = arr.splice(i, 1)[0];
      let j = arr.findIndex((x) => x.id === targetId);
      if (placeAfter) j += 1;
      arr.splice(j, 0, item);
      this.canvasLayers = arr;
      this.saveLayerLayout();
    },
    // Lật ngang / lật dọc layer.
    toggleFlipX(id) { const l = this.canvasLayers.find((x) => x.id === id); if (!l) return; this.pushHistory(); l.flipX = !l.flipX; this.saveLayerLayout(); },
    toggleFlipY(id) { const l = this.canvasLayers.find((x) => x.id === id); if (!l) return; this.pushHistory(); l.flipY = !l.flipY; this.saveLayerLayout(); },
    // ── Xóa vùng (erase brush + feather) ──
    async toggleErase() {
      const l = this.activeLayer;
      if (l && l.locked) { this.toast('Layer đang khóa — mở khóa trước khi xóa.', 'error'); return; }
      if (!this.eraseMode && !(await this.flattenActiveLayerTransform())) return;
      this.eraseMode = !this.eraseMode;
      // KHÔNG reset zoom/pan — đóng băng vị trí & độ thu phóng hiện tại khi chọn công cụ.
      if (!this.eraseMode) this.applyErase();
    },
    // Gắn canvas overlay (DOM) làm mask để vẽ + xem trước realtime.
    attachEraseCanvas(el) {
      if (!el) { this._eraseCanvas = null; this._eraseCtx = null; return; }
      // Canvas theo TỈ LỆ HIỂN THỊ của ảnh trên canvas (không vuông cứng) — nếu vuông thì nét xóa
      // bị méo & lệch vị trí khi bake. Dùng vw/vh (vùng ảnh hiển thị) thay vì naturalWidth:
      // đúng tỉ lệ NGAY CẢ khi ảnh chưa decode xong (naturalWidth = 0) vì vw/vh đã có sau layout.
      const m = this.canvasMetrics();
      const base = 1024;
      const ratio = m && m.vw && m.vh ? m.vw / m.vh : 1;
      let w = base, h = base;
      if (ratio >= 1) h = Math.max(1, Math.round(base / ratio));
      else w = Math.max(1, Math.round(base * ratio));
      el.width = w; el.height = h;
      this._eraseCanvas = el;
      this._eraseCtx = el.getContext('2d');
      this._eraseCtx.clearRect(0, 0, w, h);
      this._eraseHasStrokes = false; // canvas mới → chưa có nét
    },
    setEraseFeather(v) { this.eraseFeather = Number(v) || 0; },
    _eraseRadius() { return Math.max(3, Math.min(150, Number(this.eraseBrushSize) || 24)); },
    _erasePoint(e) {
      const m = this.canvasMetrics();
      if (!m) return { nx: 0.5, ny: 0.5 };
      return { nx: this._clamp((e.clientX - m.crLeft - m.vx) / m.vw, 0, 1), ny: this._clamp((e.clientY - m.crTop - m.vy) / m.vh, 0, 1) };
    },
    _drawEraseDot(p) {
      const c = this._eraseCtx; if (!c) return;
      const w = this._eraseCanvas.width, h = this._eraseCanvas.height, r = this._eraseRadius();
      const f = Math.max(0, Math.min(1, (Number(this.eraseFeather) || 0) / 60)); // feather 0-60
      const hard = Math.max(0.15, 0.9 - f * 0.75); // mép cứng (f=0) → rất mềm (f=1)
      const g = c.createRadialGradient(p.nx * w, p.ny * h, 0, p.nx * w, p.ny * h, r);
      g.addColorStop(0, 'rgba(0,0,0,1)');
      g.addColorStop(hard, 'rgba(0,0,0,0.85)');
      g.addColorStop(1, 'rgba(0,0,0,0)');
      c.fillStyle = g;
      c.beginPath(); c.arc(p.nx * w, p.ny * h, r, 0, Math.PI * 2); c.fill();
    },
    _drawEraseLine(from, to) {
      const c = this._eraseCtx; if (!c) return;
      const w = this._eraseCanvas.width, r = this._eraseRadius();
      const steps = Math.max(1, Math.ceil(Math.hypot(to.nx - from.nx, to.ny - from.ny) * w / (r / 2)));
      for (let s = 0; s <= steps; s++) this._drawEraseDot({ nx: from.nx + (to.nx - from.nx) * (s / steps), ny: from.ny + (to.ny - from.ny) * (s / steps) });
    },
    beginEraseBrush(e) { if (!this.eraseMode) return; if (e.currentTarget && e.currentTarget.setPointerCapture && e.pointerId != null) { try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {} } this._eraseDrawing = true; this._eraseLast = this._erasePoint(e); this._eraseHasStrokes = true; this._drawEraseDot(this._eraseLast); },
    eraseBrushMove(e) { if (!this._eraseDrawing) return; const p = this._erasePoint(e); this._drawEraseLine(this._eraseLast || p, p); this._eraseLast = p; },
    endEraseBrush() { this._eraseDrawing = false; this._eraseLast = null; },
    applyErase() {
      if (this._eraseBusy) return Promise.resolve();
      // Không có nét thật → không push history / không bake (tránh undo rác khi chỉ bật/tắt công cụ).
      if (!this._eraseHasStrokes) return Promise.resolve();
      this.pushHistory();
      this._eraseBusy = true;
      this._eraseHasStrokes = false;
      return new Promise((resolve) => {
        const l = this.activeLayer;
        const ec = this._eraseCanvas;
        if (!l || l.locked || !ec) {
          if (l && l.locked) this.toast('Layer đang khóa — mở khóa trước khi xóa.', 'error');
          this._eraseBusy = false;
          resolve();
          return;
        }
        const img = new Image();
        img.onload = () => {
          const w = img.naturalWidth, h = img.naturalHeight;
          const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0);
          ctx.globalCompositeOperation = 'destination-out';
          ctx.drawImage(ec, 0, 0, w, h);
          l.image = canvas.toDataURL('image/png');
          this.saveLayerLayout();
          this.toast('Đã xóa vùng.');
          this._eraseBusy = false;
          resolve();
        };
        img.onerror = () => { this.toast('Không xóa được ảnh.', 'error'); this._eraseBusy = false; resolve(); };
        img.src = l.image;
      });
    },
    // "🗑 Xóa": áp dụng nét đã vẽ vào layer rồi xoá canvas — GIỮ chế độ để vẽ tiếp (không tự thoát).
    applyEraseNow() {
      this.applyErase().then(() => {
        if (this._eraseCtx && this._eraseCanvas) this._eraseCtx.clearRect(0, 0, this._eraseCanvas.width, this._eraseCanvas.height);
      });
    },
    // "✓ Xong": hoàn tất xóa — áp dụng nét còn lại rồi thoát chế độ.
    finishErase() { if (!this.eraseMode) return; this.applyErase().then(() => { this.eraseMode = false; }); },
    // "✕ Hủy": thoát chế độ erase KHÔNG áp dụng (bỏ nét đã vẽ).
    cancelErase() { if (!this.eraseMode) return; this.eraseMode = false; this.toast('Đã hủy xóa.'); },
    // ── Vẽ tự do (paint brush): tô màu lên layer active ──
    async toggleDraw() {
      const l = this.activeLayer;
      if (l && l.locked) { this.toast('Layer đang khóa — mở khóa trước khi vẽ.', 'error'); return; }
      if (!this.drawMode && !(await this.flattenActiveLayerTransform())) return;
      this.drawMode = !this.drawMode;
      if (!this.drawMode) this.applyDraw();
    },
    attachDrawCanvas(el) {
      if (!el) { this._drawCanvas = null; this._drawCtx = null; return; }
      // Canvas theo TỈ LỆ HIỂN THỊ (không vuông cứng) — khớp đúng tỉ lệ ảnh kể cả khi chưa decode.
      const m = this.canvasMetrics();
      const base = 1024;
      const ratio = m && m.vw && m.vh ? m.vw / m.vh : 1;
      let w = base, h = base;
      if (ratio >= 1) h = Math.max(1, Math.round(base / ratio));
      else w = Math.max(1, Math.round(base * ratio));
      el.width = w; el.height = h;
      this._drawCanvas = el;
      this._drawCtx = el.getContext('2d');
      this._drawCtx.clearRect(0, 0, w, h);
      this._drawHasStrokes = false; // canvas mới → chưa có nét
    },
    _drawRadius() { const base = Math.max(3, Math.min(150, Number(this.drawBrushSize) || 24)); return base * (0.4 + 0.6 * (Number(this._drawPressure) || 1)); },
    _hexToRgba(hex, a = 1) {
      const mm = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
      if (!mm) return 'rgba(255,255,255,' + a + ')';
      return 'rgba(' + parseInt(mm[1], 16) + ',' + parseInt(mm[2], 16) + ',' + parseInt(mm[3], 16) + ',' + a + ')';
    },
    _drawBlendOp() { return { normal: 'source-over', multiply: 'multiply', screen: 'screen', overlay: 'overlay', darken: 'darken', lighten: 'lighten' }[this.drawBlend] || 'source-over'; },
    _drawPoint(e) {
      const m = this.canvasMetrics();
      if (!m) return { nx: 0.5, ny: 0.5 };
      return { nx: this._clamp((e.clientX - m.crLeft - m.vx) / m.vw, 0, 1), ny: this._clamp((e.clientY - m.crTop - m.vy) / m.vh, 0, 1) };
    },
    _drawPaintDot(p) {
      const c = this._drawCtx; if (!c) return;
      const w = this._drawCanvas.width, h = this._drawCanvas.height, r = this._drawRadius();
      const op = Math.max(0.01, Math.min(1, (Number(this.drawOpacity) || 1) * (Number(this.drawFlow) || 1))); // opacity × flow
      const feather = Math.max(0, Math.min(1, (Number(this.drawSoftness) || 0) / 60)); // 0..1
      // Plateau (độ cứng) = hardness trừ bớt phần mềm; rồi GAUSS smooth ramp tới mép — KHÔNG có mid-stop
      // (mid-stop tạo vòng/banding). Hardness cao = mép sắc; thấp = mềm mượt.
      const core = Math.max(0.03, Math.min(0.98, (Number(this.drawHardness) || 80) / 100 * (1 - feather * 0.5)));
      const g = c.createRadialGradient(p.nx * w, p.ny * h, 0, p.nx * w, p.ny * h, r);
      const stops = [[0, op]];
      if (core > 0.05) stops.push([core, op]);
      stops.push([1, 0]);
      stops.forEach((s) => g.addColorStop(s[0], this._hexToRgba(this.inpaintFillColor, s[1])));
      c.save();
      c.globalCompositeOperation = this._drawBlendOp();
      c.fillStyle = g;
      c.beginPath(); c.arc(p.nx * w, p.ny * h, r, 0, Math.PI * 2); c.fill();
      c.restore();
    },
    _drawPaintLine(from, to) {
      const c = this._drawCtx; if (!c) return;
      const w = this._drawCanvas.width, r = this._drawRadius();
      const spacing = Math.max(0.5, r * 2 * Math.min(1, Math.max(0.03, Number(this.drawSpacing) || 0.15)));
      const dist = Math.hypot(to.nx - from.nx, to.ny - from.ny) * w;
      const steps = Math.max(1, Math.ceil(dist / spacing));
      for (let s = 0; s <= steps; s++) this._drawPaintDot({ nx: from.nx + (to.nx - from.nx) * (s / steps), ny: from.ny + (to.ny - from.ny) * (s / steps) });
    },
    beginDrawBrush(e) { if (!this.drawMode) return; if (e.currentTarget && e.currentTarget.setPointerCapture && e.pointerId != null) { try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {} } this._drawPressure = (e.pointerType === 'pen' && e.pressure != null && e.pressure > 0) ? e.pressure : 1; this._drawDrawing = true; const p = this._drawPoint(e); this._drawSmooth = p; this._drawLast = p; this._drawCursor = { x: e.clientX, y: e.clientY }; this._drawHasStrokes = true; this._drawPaintDot(p); },
    drawBrushMove(e) { if (!this._drawDrawing) return; if (e.pointerType === 'pen' && e.pressure != null && e.pressure > 0) this._drawPressure = e.pressure; let p = this._drawPoint(e); const sm = Math.max(0, Math.min(100, Number(this.drawSmoothing) || 0)); if (sm > 0 && this._drawSmooth) { const k = Math.max(0.05, 1 - sm / 100); p = { nx: this._drawSmooth.nx + (p.nx - this._drawSmooth.nx) * k, ny: this._drawSmooth.ny + (p.ny - this._drawSmooth.ny) * k }; } this._drawSmooth = p; this._drawCursor = { x: e.clientX, y: e.clientY }; this._drawPaintLine(this._drawLast || p, p); this._drawLast = p; },
    endDrawBrush() { this._drawDrawing = false; this._drawLast = null; this._drawSmooth = null; this._drawCursor = null; },
    applyDraw() {
      if (this._drawBusy) return Promise.resolve();
      // Không có nét thật → không push history / không bake.
      if (!this._drawHasStrokes) return Promise.resolve();
      this.pushHistory();
      this._drawBusy = true;
      this._drawHasStrokes = false;
      return new Promise((resolve) => {
        const l = this.activeLayer;
        const dc = this._drawCanvas;
        if (!l || l.locked || !dc) {
          if (l && l.locked) this.toast('Layer đang khóa — mở khóa trước khi vẽ.', 'error');
          this._drawBusy = false;
          resolve();
          return;
        }
        const img = new Image();
        img.onload = () => {
          const w = img.naturalWidth, h = img.naturalHeight;
          const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0);
          ctx.globalCompositeOperation = 'source-over';
          ctx.drawImage(dc, 0, 0, w, h);
          l.image = canvas.toDataURL('image/png');
          this.saveLayerLayout();
          this.toast('Đã vẽ lên layer.');
          this._drawBusy = false;
          resolve();
        };
        img.onerror = () => { this.toast('Không vẽ được.', 'error'); this._drawBusy = false; resolve(); };
        img.src = l.image;
      });
    },
    applyDrawNow() {
      this.applyDraw().then(() => {
        if (this._drawCtx && this._drawCanvas) this._drawCtx.clearRect(0, 0, this._drawCanvas.width, this._drawCanvas.height);
      });
    },
    finishDraw() { if (!this.drawMode) return; this.applyDraw().then(() => { this.drawMode = false; }); },
    cancelDraw() { if (!this.drawMode) return; this.drawMode = false; this.toast('Đã hủy vẽ.'); },
    exitErase() { if (this.eraseMode) { this.eraseMode = false; this.applyErase(); } },
    // "Thoát công cụ" thông minh: gọi khi CHUYỂN SANG TÁC VỤ KHÁC / THOÁT ẢNH TIÊU ĐIỂM
    // (đổi bước, mở trình xem ảnh, mở popup Prompt Tạo Ảnh, mở popup tải ảnh nguồn, bỏ chọn layer…).
    exitCanvasTools() {
      if (this.drawMode) this.finishDraw();
      if (this.eraseMode) this.exitErase();
      if (this.inpaintMaskMode !== 'none') this.clearInpaintMask();
      this.reframeOpen = false;
      this.cropMode = false;
      if (this._cropStop) this._cropStop(null);
      this.filmOpen = false;
      this.selectTool = false;
      this.panMode = false;
    },
    // ── Chuẩn hoá transform layer (xoay/lật) trước khi sửa pixel ──
    // Overlay vẽ/xóa/lasso tính theo khung hiển thị (chưa kể rotation/flip) nên trên layer bị XOAY
    // hoặc LẬT, nét cọ/đường lasso và vùng áp dụng LỆCH khỏi nội dung người dùng nhìn thấy (khi
    // phóng to lệch càng rõ). Khi bật công cụ sửa pixel trên layer như vậy ta "rasterize" transform:
    // nội dung được xoay/lật vào 1 ảnh mới (giữ NGUYÊN vị trí/kích thước hiển thị) rồi reset
    // rotation/flip → mọi overlay chuẩn hoá theo ảnh hoạt động chính xác. Ctrl+Z hoàn lại bản gốc.
    _needsFlatten(l) {
      if (!l || !l.image) return false;
      const rot = Math.abs((Number(l.rotation) || 0) % 360);
      return (rot > 0.5 && rot < 359.5) || !!l.flipX || !!l.flipY;
    },
    // Gọi trước khi bật erase/draw/region-select. Trả true khi sẵn sàng sửa (đã flatten nếu cần).
    async flattenActiveLayerTransform() {
      if (this._flattenBusy) return false;
      const l = this.activeLayer;
      if (!this._needsFlatten(l)) return true;
      try {
        this._flattenBusy = true;
        const img = await this._loadImageSrc(l.image);
        const nw = img.naturalWidth, nh = img.naturalHeight;
        if (!nw || !nh) return false;
        const rot = ((Number(l.rotation) || 0) * Math.PI) / 180;
        const cosR = Math.abs(Math.cos(rot)), sinR = Math.abs(Math.sin(rot));
        const W = Math.max(1, Math.ceil(nw * cosR + nh * sinR));
        const H = Math.max(1, Math.ceil(nw * sinR + nh * cosR));
        const canvas = document.createElement('canvas');
        canvas.width = W; canvas.height = H;
        const ctx = canvas.getContext('2d');
        ctx.translate(W / 2, H / 2);
        // Khớp CSS `transform: rotate(θ) scale(sx,sy)` (scale trước rồi rotate) → ctx.rotate rồi scale.
        ctx.rotate(rot);
        ctx.scale(l.flipX ? -1 : 1, l.flipY ? -1 : 1);
        ctx.drawImage(img, -nw / 2, -nh / 2);
        const MAX = 512;
        const k = Math.min(1, MAX / W, MAX / H);
        const newBaseW = W * k, newBaseH = H * k;
        // Kích thước hiển thị CŨ (không gian base, trước zoom) để giữ layer KHÔNG nhảy cỡ.
        const kOld = Math.min(1, MAX / nw, MAX / nh);
        const oldBaseW = Number(l.baseW) || (nw * kOld);
        const oldBaseH = Number(l.baseH) || (nh * kOld);
        const sOld = Math.max(0.05, Math.min(8, Number(l.scale) || 1));
        const visW = oldBaseW * cosR + oldBaseH * sinR; // bbox hiển thị (CSS px, chưa zoom)
        const visH = oldBaseW * sinR + oldBaseH * cosR;
        // newBase tỉ lệ đúng visW/visH (đồng dạng) nên 1 hệ số scale giữ đúng cả 2 chiều.
        let sNew = (visW * sOld) / Math.max(1, newBaseW);
        sNew = Math.max(0.05, Math.min(8, sNew));
        this.pushHistory(); // undo = khôi phục layer gốc (ảnh + transform)
        l.image = canvas.toDataURL('image/png');
        l.rotation = 0; l.flipX = false; l.flipY = false;
        l.baseW = newBaseW; l.baseH = newBaseH;
        l.scale = sNew;
        // Pixel đã khác generation gốc (nếu layer là kết quả AI) → trở thành ảnh cục bộ để
        // download/lưu/inpaint không nhầm với file gốc trên server.
        if (l.kind === 'gen' && l.genId) {
          l.kind = 'source'; l.genId = null;
          if (this.activeLayerId === l.id) { this.editSource = { url: l.image, name: l.name }; this.previewId = null; this.preview = null; }
        }
        this.saveLayerLayout();
        this.toast('Đã áp xoay/lật vào ảnh để chỉnh sửa đúng vị trí — Ctrl+Z nếu muốn hoàn lại.');
        return true;
      } catch (e) {
        this.toast('Không chuẩn hoá được ảnh xoay/lật.', 'error');
        return false;
      } finally { this._flattenBusy = false; }
    },
    // Kéo layer trên canvas để di chuyển — hỗ trợ di chuyển NHIỀU layer cùng lúc (nhóm).
    beginLayerDrag(id, e) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l || l.locked) return;
      // Nếu layer bị kéo nằm trong NHÓM chọn nhiều → kéo cả nhóm; ngược lại chọn riêng nó.
      let ids = this.selectedIds;
      if (!ids.includes(id)) { this.setActiveLayer(id); ids = [id]; }
      const items = this.canvasLayers.filter(x => ids.includes(x.id) && x.visible !== false && !x.locked).map(x => ({ id: x.id, ox: Number(x.x) || 0, oy: Number(x.y) || 0 }));
      if (!items.length) return;
      // Snapshot lúc bắt đầu kéo (push vào history khi kết thúc) — tránh làm bẩn lịch sử.
      this._layerDrag = { id, ids, items, sx: e.clientX, sy: e.clientY, snap: this._snapshot() };
    },
    layerDragMove(e) {
      const d = this._layerDrag;
      if (!d) return;
      const prim = d.items.find(x => x.id === d.id) || d.items[0];
      if (!prim) return;
      let dx = (e.clientX - d.sx) / (this.zoom || 1);
      let dy = (e.clientY - d.sy) / (this.zoom || 1);
      const SNAP = 20 / (this.zoom || 1);  // ≈20px màn hình
      const G = ((this.snapGrid || 0) / (this.zoom || 1)); // lưới bắt điểm (preset)
      let tx = prim.ox + dx, ty = prim.oy + dy;
      let sx = null, sy = null;
      // Bắt điểm LƯỚI preset trước (ưu tiên rơi vào nút lưới).
      if (G > 0) { tx = Math.round(tx / G) * G; ty = Math.round(ty / G) * G; }
      // Bắt điểm tâm canvas (0,0) + cạnh các layer NGOÀI nhóm.
      if (Math.abs(tx) < SNAP) { tx = 0; sx = 0; }
      if (Math.abs(ty) < SNAP) { ty = 0; sy = 0; }
      this.canvasLayers.forEach((o) => {
        if (d.ids.includes(o.id) || o.visible === false) return;
        if (Math.abs(tx - (o.x || 0)) < SNAP) { tx = o.x || 0; sx = tx; }
        if (Math.abs(ty - (o.y || 0)) < SNAP) { ty = o.y || 0; sy = ty; }
      });
      // Áp delta đã bắt điểm cho toàn bộ nhóm.
      dx = tx - prim.ox; dy = ty - prim.oy;
      d.items.forEach(it => { const m = this.canvasLayers.find(x => x.id === it.id); if (m) { m.x = it.ox + dx; m.y = it.oy + dy; } });
      this.snapX = sx;
      this.snapY = sy;
    },
    endLayerDrag() {
      if (!this._layerDrag) return;
      const snap = this._layerDrag.snap;
      this._layerDrag = null;
      this.snapX = null;
      this.snapY = null;
      this.saveLayerLayout();
      if (snap) { this.undoStack.push(snap); if (this.undoStack.length > 50) this.undoStack.shift(); this.redoStack = []; }
    },
    // Di chuyển TOÀN BỘ layer đang chọn theo (dx,dy) — dùng phím mũi tên.
    nudgeSelection(dx, dy) {
      const ids = this.selectedIds; if (!ids.length) return;
      let moved = false;
      this.canvasLayers.forEach(l => { if (ids.includes(l.id) && !l.locked) { l.x = (l.x || 0) + dx; l.y = (l.y || 0) + dy; moved = true; } });
      if (moved) { this.saveLayerLayout(); }
    },
    setSnapGrid(v) { this.snapGrid = Number(v) || 0; },
    // Kích thước hiển thị (bbox) của layer — dùng cho căn/chia đều.
    _layerBox(l) { const w = Math.max(1, (Number(l.baseW) || 0) * (Number(l.scale) || 1)); const h = Math.max(1, (Number(l.baseH) || 0) * (Number(l.scale) || 1)); return { w, h, cx: (l.x || 0), cy: (l.y || 0) }; },
    // ĐƠN VỊ căn/chia đều: mỗi NHÓM = 1 khối cứng (gồm toàn bộ thành viên), layer đơn lẻ = 1 khối.
    _selectionUnits() {
      const sels = this.selection; const byGroup = {}; const singles = [];
      sels.forEach(l => { if (l.groupId) { (byGroup[l.groupId] = byGroup[l.groupId] || []).push(l); } else singles.push(l); });
      const units = [];
      Object.keys(byGroup).forEach(gid => {
        const all = this.canvasLayers.filter(x => x.groupId === gid && x.visible !== false);
        units.push({ type: 'group', gid, layers: all, box: this._unitBox(all) });
      });
      singles.forEach(l => units.push({ type: 'layer', id: l.id, layers: [l], box: this._layerBox(l) }));
      return units;
    },
    _unitBox(layers) { let L = Infinity, R = -Infinity, T = Infinity, B = -Infinity; layers.forEach(l => { const b = this._layerBox(l); const cx = l.x || 0, cy = l.y || 0; L = Math.min(L, cx - b.w / 2); R = Math.max(R, cx + b.w / 2); T = Math.min(T, cy - b.h / 2); B = Math.max(B, cy + b.h / 2); }); return { w: R - L, h: B - T, cx: (L + R) / 2, cy: (T + B) / 2 }; },
    _translateUnit(u, dx, dy) { u.layers.forEach(l => { if (dx) l.x = (l.x || 0) + dx; if (dy) l.y = (l.y || 0) + dy; }); },
    // ── Đơn vị đang được xử lý (group = 1 khối) cho scale/rotate/duplicate ──
    _editUnitLayers() {
      const a = this.activeLayer; if (!a) return [];
      if (a.groupId) {
        const g = this.layerGroups.find(x => x.id === a.groupId);
        if (g && g.layerIds.length > 1 && this.selectedIds.length === g.layerIds.length) {
          return this.canvasLayers.filter(l => l.groupId === g.id && l.visible !== false);
        }
      }
      return [a];
    },
    groupBox(gid) { const all = this.canvasLayers.filter(l => l.groupId === gid && l.visible !== false); if (!all.length) return null; const b = this._unitBox(all); return { x: b.cx, y: b.cy, w: b.w, h: b.h }; },
    _editUnitCenter() {
      const layers = this._editUnitLayers(); if (!layers.length) return null;
      const b = this._unitBox(layers); return { x: b.cx, y: b.cy };
    },
    // Scale CẢ đơn vị (nguyên nhóm) quanh tâm — factor k (không push history, gọi trong drag).
    scaleSelectionBy(k) {
      const layers = this._editUnitLayers(); if (!layers.length || !Number.isFinite(k) || k <= 0) return;
      const c = this._unitBox(layers);
      layers.forEach(l => { const dx = (l.x || 0) - c.cx, dy = (l.y || 0) - c.cy; l.x = c.cx + dx * k; l.y = c.cy + dy * k; l.scale = Math.max(0.05, Math.min(8, (l.scale || 1) * k)); });
      const g = (this.activeLayer && this.activeLayer.groupId) ? this.layerGroups.find(x => x.id === this.activeLayer.groupId) : null;
      if (g) g.scale = (g.scale || 1) * k;
      this.saveLayerLayout();
    },
    // Xoay CẢ đơn vị (nguyên nhóm) quanh tâm — deg (không push history, gọi trong drag).
    rotateSelectionBy(deg) {
      const layers = this._editUnitLayers(); if (!layers.length) return;
      const c = this._unitBox(layers); const a = (deg * Math.PI) / 180, cos = Math.cos(a), sin = Math.sin(a);
      layers.forEach(l => { const dx = (l.x || 0) - c.cx, dy = (l.y || 0) - c.cy; l.x = c.cx + dx * cos - dy * sin; l.y = c.cy + dx * sin + dy * cos; let r = ((l.rotation || 0) + deg) % 360; if (r > 180) r -= 360; if (r < -180) r += 360; l.rotation = Math.round(r); });
      const g = (this.activeLayer && this.activeLayer.groupId) ? this.layerGroups.find(x => x.id === this.activeLayer.groupId) : null;
      if (g) g.rotation = (((g.rotation || 0) + deg) % 360 + 360) % 360;
      this.saveLayerLayout();
    },
    // Cập nhật thuộc tính cho ĐƠN VỊ (group = áp dụng cả nhóm, thống nhất với handle canvas).
    updateUnitTransform(field, value) {
      const a = this.activeLayer; if (!a) return;
      const g = a.groupId ? this.layerGroups.find(x => x.id === a.groupId) : null;
      const isGroup = !!(g && g.layerIds.length > 1 && this.selectedIds.length === g.layerIds.length);
      if (field === 'rotation') {
        if (isGroup) { const delta = (Number(value) || 0) - (g.rotation || 0); this.rotateSelectionBy(delta); return; }
        this.updateLayerTransform(a.id, { rotation: Number(value) || 0 }); return;
      }
      if (field === 'scale') {
        if (isGroup) { const cur = g.scale || 1; const k = (Number(value) || 1) / cur; if (k > 0) this.scaleSelectionBy(k); return; }
        this.updateLayerTransform(a.id, { scale: Number(value) || 1 }); return;
      }
      const layers = this._editUnitLayers(); if (!layers.length) return;
      layers.forEach(l => { l[field] = value; });
      this.saveLayerLayout();
    },
    // Reset rotation cho ĐƠN VỊ: group = xoay vị trí thành viên NGƯỢC lại góc đã xoay (giữ layout nội bộ) + rotation=0.
    resetActiveUnitRotation() {
      const a = this.activeLayer; if (!a) return;
      const g = a.groupId ? this.layerGroups.find(x => x.id === a.groupId) : null;
      this.pushHistory();
      if (g && g.layerIds.length > 1) {
        const layers = this.canvasLayers.filter(l => l.groupId === g.id && l.visible !== false);
        const c = this._unitBox(layers);
        const deg = -(g.rotation || 0); const rad = deg * Math.PI / 180, cos = Math.cos(rad), sin = Math.sin(rad);
        layers.forEach(l => { const dx = (l.x || 0) - c.cx, dy = (l.y || 0) - c.cy; l.x = c.cx + dx * cos - dy * sin; l.y = c.cy + dx * sin + dy * cos; l.rotation = 0; });
        g.rotation = 0;
      } else {
        a.rotation = 0;
      }
      this.saveLayerLayout();
    },
    // Nhân đôi ĐƠN VỊ đang active: nếu group được chọn trọn → nhân đôi nhóm, ngược lại nhân đôi layer.
    duplicateActiveUnit() {
      const a = this.activeLayer; if (!a) return;
      const g = a.groupId ? this.layerGroups.find(x => x.id === a.groupId) : null;
      // GROUP = 1 đối tượng: nhân đôi TOÀN BỘ nhóm (không phụ thuộc số layer đang chọn).
      if (g) { this.duplicateGroup(g.id); return; }
      this.duplicateLayer(a.id);
    },
    // Căn lề theo ĐƠN VỊ (group là 1 khối): left/hcenter/right/top/vcenter/bottom.
    alignSelection(kind) {
      const units = this._selectionUnits(); if (units.length < 2) return;
      let L = Infinity, R = -Infinity, T = Infinity, B = -Infinity;
      units.forEach(u => { L = Math.min(L, u.box.cx - u.box.w / 2); R = Math.max(R, u.box.cx + u.box.w / 2); T = Math.min(T, u.box.cy - u.box.h / 2); B = Math.max(B, u.box.cy + u.box.h / 2); });
      const hc = (L + R) / 2, vc = (T + B) / 2;
      this.pushHistory();
      units.forEach(u => { let dx = 0, dy = 0; if (kind === 'left') dx = (L + u.box.w / 2) - u.box.cx; else if (kind === 'hcenter') dx = hc - u.box.cx; else if (kind === 'right') dx = (R - u.box.w / 2) - u.box.cx; else if (kind === 'top') dy = (T + u.box.h / 2) - u.box.cy; else if (kind === 'vcenter') dy = vc - u.box.cy; else if (kind === 'bottom') dy = (B - u.box.h / 2) - u.box.cy; this._translateUnit(u, dx, dy); });
      this.saveLayerLayout();
    },
    // Chia đều khoảng cách theo ĐƠN VỊ (group di chuyển nguyên khối, giữ VỊ TRÍ NỘI BỘ).
    distributeSelection(kind) {
      const units = this._selectionUnits(); if (units.length < 3) return;
      const prop = kind === 'y' ? 'cy' : 'cx';
      const sorted = units.slice().sort((a, b) => a.box[prop] - b.box[prop]);
      const first = sorted[0].box[prop], last = sorted[sorted.length - 1].box[prop];
      const step = (last - first) / (sorted.length - 1);
      this.pushHistory();
      sorted.forEach((u, i) => { if (i === 0 || i === sorted.length - 1) return; const target = first + step * i; this._translateUnit(u, kind === 'y' ? 0 : target - u.box[prop], kind === 'y' ? target - u.box[prop] : 0); });
      this.saveLayerLayout();
    },
    // Phím Delete → mở popup xác nhận xóa NHIỀU layer.
    deleteSelection() { if (this.selectionUnitCount) this.confirmDeleteOpen = true; },
    confirmDeleteSelection() {
      // GROUP = 1 đối tượng: xóa theo ĐƠN VỊ (nguyên nhóm + layer đơn), không phải từng layer.
      const units = this._selectionUnits(); if (!units.length) { this.confirmDeleteOpen = false; return; }
      // Layer KHÓA không bị xóa — đúng luật đã áp ở nút xóa trong bảng Lớp (disabled) và deleteLayer().
      // Trước đây đường xóa theo lựa chọn bỏ qua hoàn toàn khóa ⇒ phím Delete xóa được cả layer đã khóa.
      const locked = units.filter(u => u.layers.some(l => l.locked));
      const units2 = units.filter(u => !u.layers.some(l => l.locked));
      if (!units2.length) {
        this.confirmDeleteOpen = false;
        this.toast('Đối tượng đang KHÓA — mở khóa rồi mới xóa được.', 'error');
        return;
      }
      const ids = new Set(); const delGids = new Set();
      units2.forEach(u => { u.layers.forEach(l => ids.add(l.id)); if (u.type === 'group') delGids.add(u.gid); });
      const wasActive = this.activeLayerId;
      this.pushHistory();
      this.canvasLayers = this.canvasLayers.filter(l => !ids.has(l.id));
      this.layerGroups = this.layerGroups.filter(g => !delGids.has(g.id));
      const rest = this.canvasLayers.filter(l => l.visible !== false);
      const at = rest.findIndex(l => l.id === wasActive);
      const next = rest[at] || rest[rest.length - 1] || null;
      if (next) this._setActive(next.id); else this._setActive('');
      this.selectedLayerIds = [];
      this.confirmDeleteOpen = false;
      this.saveLayerLayout();
      this.toast('Đã xóa ' + units2.length + ' đối tượng.'
        + (locked.length ? ' Giữ lại ' + locked.length + ' đối tượng đang khóa.' : ''),
        locked.length ? 'error' : 'success');
    },
    // clearCanvas + confirmClearCanvas đã gộp vào cleanCanvas() (có pushHistory + confirm popup qua LayersPanel).
    // Bỏ chọn layer active (nhấp khoảng trống trên canvas).
    selectAll() {
      const visible = this.canvasLayers.filter(l => l.visible !== false && l.locked === false);
      if (!visible.length) { this.toast('Không có layer nào để chọn.', 'error'); return; }
      this._setActive(visible[visible.length - 1].id);
      this.selectedLayerIds = visible.filter(x => x.id !== visible[visible.length - 1].id).map(x => x.id);
      this.saveLayerLayout();
      this.toast('Đã chọn ' + visible.length + ' layer.', 'success');
    },
    deselectAll() {
      this.activeLayerId = '';
      this.editSource = null;
      this.previewId = null;
      this.preview = null;
      this.selectedLayerIds = [];
      this.saveLayerLayout();
    },
    // Gộp tất cả layer đang hiển thị thành 1 ảnh PNG (data URL) theo đúng transform/opacity/blend.
    async compositeVisible() {
      const layers = this.canvasLayers.filter((l) => l.visible !== false && l.image);
      if (!layers.length) throw new Error('Chưa có layer để gộp.');
      const MAX = 512; // khớp với max-h/max-w hiển thị trên canvas → tỷ lệ gộp khớp với màn hình
      const loaded = await Promise.all(layers.map((l) => new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve({ layer: l, img });
        img.onerror = () => resolve({ layer: l, img: null });
        img.src = l.image;
      })));
      const valid = loaded.filter((x) => x.img && x.img.naturalWidth);
      if (!valid.length) throw new Error('Không tải được ảnh layer.');
      // Dùng đúng kích thước hiển thị thật của mỗi layer (natural bị giới hạn về MAX giống canvas).
      const items = valid.map(({ layer: l, img }) => {
        const nw = img.naturalWidth, nh = img.naturalHeight;
        const base = Math.min(1, MAX / nw, MAX / nh);
        return { l, img, w: nw * base, h: nh * base };
      });
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      items.forEach(({ l, w, h }) => {
        const s = l.scale || 1;
        const rot = ((l.rotation || 0) * Math.PI) / 180;
        const cos = Math.cos(rot), sin = Math.sin(rot);
        const hw = (w * s) / 2, hh = (h * s) / 2;
        [[-hw, -hh], [hw, -hh], [hw, hh], [-hw, hh]].forEach(([cx, cy]) => {
          const px = cx * cos - cy * sin + (l.x || 0);
          const py = cx * sin + cy * cos + (l.y || 0);
          if (px < minX) minX = px; if (px > maxX) maxX = px;
          if (py < minY) minY = py; if (py > maxY) maxY = py;
        });
      });
      const pad = 8;
      const W = Math.max(1, Math.ceil(maxX - minX + pad * 2));
      const H = Math.max(1, Math.ceil(maxY - minY + pad * 2));
      const canvas = document.createElement('canvas');
      canvas.width = W; canvas.height = H;
      const ctx = canvas.getContext('2d');
      items.forEach(({ l, img, w, h }) => {
        ctx.save();
        ctx.translate(-minX + pad, -minY + pad);
        ctx.translate(l.x || 0, l.y || 0);
        ctx.rotate(((l.rotation || 0) * Math.PI) / 180);
        ctx.scale((l.scale || 1) * (l.flipX ? -1 : 1), (l.scale || 1) * (l.flipY ? -1 : 1));
        ctx.globalAlpha = l.opacity != null ? l.opacity : 1;
        ctx.globalCompositeOperation = (l.blend && l.blend !== 'normal') ? l.blend : 'source-over';
        ctx.drawImage(img, -w / 2, -h / 2, w, h);
        ctx.restore();
      });
      return canvas.toDataURL('image/png');
    },
    // Xuất ảnh gộp (tải xuống PNG).
    async exportComposite() {
      try {
        const url = await this.compositeVisible();
        const a = document.createElement('a');
        a.href = url;
        a.download = 'composite-' + Date.now() + '.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
        this.toast('Đã xuất ảnh gộp.');
      } catch (e) { this.toast(e.message || 'Không gộp được.', 'error'); }
    },
    // Gộp layer thành 1 layer mới (upload lên server để lưu lâu dài).
    async flattenToLayer() {
      try {
        const url = await this.compositeVisible();
        const blob = await (await fetch(url)).blob();
        const fd = new FormData();
        fd.append('image', new File([blob], 'composite.png', { type: 'image/png' }));
        const res = await fetch('/api/upload-ref', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' }, body: fd });
        const d = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(d.message || 'Không gộp được.');
        const id = 'flat-' + Date.now();
        this.pushCanvasLayer(id, 'source', 'Gộp layer', d.url);
        this.setActiveLayer(id);
        this.toast('Đã gộp layer thành ảnh mới.');
      } catch (e) { this.toast(e.message || 'Không gộp được.', 'error'); }
    },
    // Tên hiển thị của một generation (dùng tên tuỳ chỉnh nếu có, ngược lại "Ảnh #id").
    genName(g) { return (g && g.meta && g.meta.name) ? g.meta.name : (g ? 'Ảnh #' + g.id : 'Ảnh'); },
    // (Đã gỡ downloadActive() cùng nút "Tải ảnh đang chọn" ở thanh trạng thái: việc tải ảnh đã có
    //  đường riêng — Xuất PNG ở bảng Lớp và nút tải ở Kết quả/Thư viện — giữ lại chỉ gây trùng.)
    // Ẩn/hiện layer (eye toggle). Ẩn layer đang active thì chuyển sang layer hiển thị kế tiếp.
    toggleLayerVisible(id) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      l.visible = !l.visible;
      // [2026-09-20] Ẩn layer KHÔNG chuyển "đang chọn" sang layer khác nữa — GIỮ nguyên layer đó.
      // Vì sao: người dùng vừa ẩn ĐÚNG layer đang làm việc; chuyển active đi thì tay cầm chỉnh kích cỡ
      // nhảy sang một layer khác (trông như "mất tay cầm"), và bật lại layer cũ cũng vẫn không có tay
      // cầm vì nó không còn là layer đang chọn. Các trình chỉnh ảnh (Photoshop…) cũng giữ layer đang
      // chọn khi ẩn. Hàng trong bảng Lớp vẫn sáng nên luôn biết đang chọn layer nào.
      this.saveLayerLayout();
    },
    // Khóa/mở khóa layer (chống xóa/đổi tên/di chuyển nhầm).
    toggleLayerLock(id) {
      const l = this.canvasLayers.find((x) => x.id === id);
      if (!l) return;
      l.locked = !l.locked;
      this.saveLayerLayout();
    },
    // Lưu bố cục layer (danh sách + layer active) để khôi phục khi tải lại trang.
    saveLayerLayout() {
      // §5.2: canvasLayers chứa ảnh dạng data-URL (canvas.toDataURL) nên mỗi mutation ghi vài MB
      // vào localStorage (quota ~5MB) và được gọi ở 57 chỗ. QuotaExceededError TRƯỚC ĐÂY bị nuốt
      // (chỉ console.error) -> người dùng mất bố cục mà không biết vì sao. Nay báo rõ MỘT LẦN.
      // (Không tự ý bỏ data-URL khi lưu: bản khôi phục chưa hỗ trợ layer thiếu ảnh — cần làm cả 2 phía.)
      try {
        localStorage.setItem('fabrikai.layers', JSON.stringify({ layers: this.canvasLayers, activeLayerId: this.activeLayerId, selectedLayerIds: this.selectedLayerIds, layerGroups: this.layerGroups }));
      } catch (e) {
        console.error('studio saveLayerLayout failed (vượt quota localStorage?)', e);
        if (!this._layoutSaveWarned) {
          this._layoutSaveWarned = true;
          this.toast('Trang vẽ quá lớn để tự lưu vào trình duyệt — bố cục có thể mất khi tải lại. Hãy xuất/lưu bớt ảnh.', 'error');
        }
      }
    },
    // Lưu VẬT LÝ (nút Save): flush toàn bộ trạng thái + thông báo thành công.
    saveNow() { this.saveLayerLayout(); this.toast('Đã lưu trang.'); },
    // ── Cài đặt status bar (snap · nền canvas · inspector · bề rộng dock) ──
    saveBarSettings() { try { localStorage.setItem('fabrikai.bar', JSON.stringify({ snapGrid: this.snapGrid, canvasBg: this.canvasBg, inspectorOpen: this.inspectorOpen, leftPanelOpen: this.leftPanelOpen, outputDockOpen: this.outputDockOpen, leftDockWidth: this.leftDockWidth, outputDockWidth: this.outputDockWidth, inspectorWidth: this.inspectorWidth })); } catch (e) { /* bỏ qua */ } },
    restoreBarSettings() { try { const d = JSON.parse(localStorage.getItem('fabrikai.bar') || 'null'); if (!d) return; if (d.snapGrid != null) this.snapGrid = Number(d.snapGrid) || 0; if (d.canvasBg) this.canvasBg = d.canvasBg; if (d.inspectorOpen != null) this.inspectorOpen = !!d.inspectorOpen; if (d.leftPanelOpen != null) this.leftPanelOpen = !!d.leftPanelOpen; if (d.outputDockOpen != null) this.outputDockOpen = !!d.outputDockOpen; if (d.leftDockWidth != null) this.leftDockWidth = Number(d.leftDockWidth) || this.leftDockWidth; if (d.outputDockWidth != null) this.outputDockWidth = Number(d.outputDockWidth) || this.outputDockWidth; if (d.inspectorWidth != null) this.inspectorWidth = Number(d.inspectorWidth) || this.inspectorWidth; } catch (e) { /* bỏ qua */ } },
    // ── Ghi nhớ cài đặt prompt của người dùng (localStorage) — ƯU TIÊN hơn dữ liệu DB ──
    // Lưu negative prompt + prefix/suffix + 3 checkbox bật/tắt. Khi load lại trang,
    // giá trị local ghi đè lên defaults từ database (nếu đã từng chỉnh sửa ở đây).
    savePromptMemory() {
      try {
        localStorage.setItem('fabrikai.prompt-cfg', JSON.stringify({
          // Toàn bộ cài đặt người dùng trong Prompt Tạo Ảnh (tab Prompt + Nâng cao):
          imagePromptEn: this.imagePromptEn || '',
          creativeLevel: this.creativeLevel,
          texture: this.texture,
          variantCount: this.variantCount,
          imageRatio: this.imageRatio,
          imageRes: this.imageRes,
          imageSeed: this.imageSeed || '',
          negativePromptEn: this.negativePromptEn || '',
          promptPrefix: this.promptPrefix || '',
          promptSuffix: this.promptSuffix || '',
          promptUsePrefix: !!this.promptUsePrefix,
          promptUseSuffix: !!this.promptUseSuffix,
          promptUseNegative: !!this.promptUseNegative,
          bodyHeight: this.bodyHeight, bodyBuild: this.bodyBuild,
          bodyWaist: this.bodyWaist, bodyShoulders: this.bodyShoulders, bodyHips: this.bodyHips,
          hairStyle: this.hairStyle || '', hairColor: this.hairColor || '',
          imagePoseId: this.imagePoseId || '',
        }));
      } catch (e) { /* bỏ qua */ }
    },
    restorePromptMemory() {
      try {
        const d = JSON.parse(localStorage.getItem('fabrikai.prompt-cfg') || 'null');
        if (!d) return;
        if (typeof d.imagePromptEn === 'string') this.imagePromptEn = d.imagePromptEn;
        if (d.creativeLevel != null) this.creativeLevel = Number(d.creativeLevel);
        if (d.texture != null) this.texture = Number(d.texture);
        if (d.variantCount != null) this.variantCount = Number(d.variantCount);
        if (d.imageRatio) this.imageRatio = d.imageRatio;
        if (d.imageRes) this.imageRes = d.imageRes;
        if (d.imageSeed) this.imageSeed = d.imageSeed;
        if (typeof d.negativePromptEn === 'string') this.negativePromptEn = d.negativePromptEn;
        if (typeof d.promptPrefix === 'string') this.promptPrefix = d.promptPrefix;
        if (typeof d.promptSuffix === 'string') this.promptSuffix = d.promptSuffix;
        if (d.promptUsePrefix != null) this.promptUsePrefix = !!d.promptUsePrefix;
        if (d.promptUseSuffix != null) this.promptUseSuffix = !!d.promptUseSuffix;
        if (d.promptUseNegative != null) this.promptUseNegative = !!d.promptUseNegative;
        if (d.bodyHeight != null) this.bodyHeight = Number(d.bodyHeight);
        if (d.bodyBuild != null) this.bodyBuild = Number(d.bodyBuild);
        if (d.bodyWaist != null) this.bodyWaist = Number(d.bodyWaist);
        if (d.bodyShoulders != null) this.bodyShoulders = Number(d.bodyShoulders);
        if (d.bodyHips != null) this.bodyHips = Number(d.bodyHips);
        if (typeof d.hairStyle === 'string') this.hairStyle = d.hairStyle;
        if (typeof d.hairColor === 'string') this.hairColor = d.hairColor;
        if (d.imagePoseId) this.imagePoseId = d.imagePoseId;
      } catch (e) { /* bỏ qua */ }
    },
    clearPromptMemory() {
      try { localStorage.removeItem('fabrikai.prompt-cfg'); } catch (e) { /* bỏ qua */ }
      this.imagePromptEn = ''; this.promptPrefix = ''; this.promptSuffix = ''; this.negativePromptEn = '';
      this.imageSeed = ''; this.hairStyle = ''; this.hairColor = ''; this.imagePoseId = '';
      this.promptUsePrefix = true; this.promptUseSuffix = true; this.promptUseNegative = true;
    },
    /**
     * VÁ KÍCH THƯỚC cho layer thiếu baseW/baseH — dữ liệu lưu từ phiên bản TRƯỚC khi có hai trường này
     * sẽ khôi phục về với baseW/baseH = null. Hệ quả đo được (không có lỗi nào hiện ra):
     *   · tay cầm chỉnh kích cỡ KHÔNG hiện, vì vị trí tay cầm tính theo kích thước layer;
     *   · khung logic của layer thành 1×1px nên căn lề · chia đều · fit chọn đều sai.
     * Nay đo lại từ chính ảnh của layer (cạnh dài tối đa 512 — đúng quy ước của pushCanvasLayer) rồi
     * ghi vào: layer cũ TỰ LÀNH sau một lần tải, người dùng không phải xóa rồi thêm lại.
     * KHÔNG đụng tới vị trí/scale đã lưu (khác _positionByImageSize — hàm đó còn xếp lại chỗ đứng).
     */
    ensureLayerSizes() {
      const MAX = 512;
      this.canvasLayers.forEach((l) => {
        if (!l.image || (Number(l.baseW) > 0 && Number(l.baseH) > 0)) return;
        const img = new Image();
        img.onload = () => {
          const nw = img.naturalWidth || 0, nh = img.naturalHeight || 0;
          if (nw < 1 || nh < 1) return;
          const cap = Math.min(1, MAX / nw, MAX / nh);
          l.baseW = Math.max(1, Math.round(nw * cap));
          l.baseH = Math.max(1, Math.round(nh * cap));
          this.saveLayerLayout();
        };
        img.src = l.image;
      });
    },
    // Khôi phục bố cục layer; bỏ layer 'gen' đã bị xóa khỏi output, giữ layer 'source' (URL vẫn hợp lệ).
    restoreLayerLayout() {
      try {
        const raw = localStorage.getItem('fabrikai.layers');
        if (!raw) return;
        const d = JSON.parse(raw);
        const genIds = new Set((this.generations || []).map((g) => g.id));
        this.canvasLayers = (Array.isArray(d.layers) ? d.layers : [])
          .filter((l) => l && l.image)
          .filter((l) => l.kind !== 'gen' || (l.genId != null && genIds.has(Number(l.genId))))
          .map((l) => ({ id: l.id, kind: l.kind, name: l.name, image: l.image, genId: l.genId, visible: l.visible !== false, locked: !!l.locked, x: Number(l.x) || 0, y: Number(l.y) || 0, scale: (l.scale != null ? Number(l.scale) : 1) || 1, rotation: Number(l.rotation) || 0, opacity: l.opacity != null ? Number(l.opacity) : 1, blend: l.blend || 'normal', baseW: Number(l.baseW) || null, baseH: Number(l.baseH) || null, flipX: !!l.flipX, flipY: !!l.flipY, groupId: l.groupId || '' }));
        // Khôi phục NHÓM (giữ nhóm còn ≥2 thành viên; nhóm thiếu thành viên → tách).
        const ids = new Set(this.canvasLayers.map((l) => l.id));
        this.layerGroups = (Array.isArray(d.layerGroups) ? d.layerGroups : [])
          .map((g) => ({ id: g.id, name: g.name || 'Nhóm', layerIds: (Array.isArray(g.layerIds) ? g.layerIds : []).filter((id) => ids.has(id)), rotation: Number(g.rotation) || 0, scale: Number(g.scale) || 1 }))
          .filter((g) => g.layerIds.length >= 2);
        const gids = new Set(this.layerGroups.map((g) => g.id));
        this.canvasLayers.forEach((l) => { if (l.groupId && !gids.has(l.groupId)) l.groupId = ''; });
        const active = this.canvasLayers.find((l) => l.id === d.activeLayerId && l.visible !== false);
        if (active) this._setActive(active.id); else this._setActive('');
        this.selectedLayerIds = (Array.isArray(d.selectedLayerIds) ? d.selectedLayerIds : []).filter((id) => this.canvasLayers.some((l) => l.id === id) && id !== this.activeLayerId);
        // Vá kích thước cho layer CŨ rồi mới lưu (xem ensureLayerSizes — thiếu bước này thì layer khôi
        // phục từ phiên bản trước về với baseW/baseH = null và KHÔNG có tay cầm chỉnh kích cỡ).
        this.ensureLayerSizes();
        this.saveLayerLayout();
      } catch (e) { this.canvasLayers = []; this.activeLayerId = ''; this.saveLayerLayout(); }
    },
    setBatch(ids) { this.lastBatch = (ids || []).filter(Boolean); this.showBatch = this.lastBatch.length > 1; },
    hideBatch() { this.showBatch = false; },
    /**
     * [Đợt 0.2] Tiến trình THẬT cho lô đang chạy — suy ra từ trạng thái THẬT của từng generation
     * trong lô, KHÔNG phải hoạt ảnh. Được gọi mỗi khi trạng thái một ảnh trong lô đổi
     * (pollGeneration) và ngay sau khi tạo lô.
     *
     * Quy ước hiển thị:
     *   queued   — đã gửi, backend còn 'pending' (chưa tới lượt xử lý)
     *   rendering— có ít nhất một ảnh đang 'processing'
     *   done     — MỌI ảnh đã 'completed'
     *   failed   — lô kết thúc mà tất cả đều lỗi/bị huỷ
     * % = số ảnh ĐÃ KẾT THÚC (completed + failed/cancelled) / tổng số ảnh của lô.
     */
    syncBatchProgress() {
      const ids = (this.lastBatch || []).map(Number).filter(Boolean);
      if (!ids.length) return;
      const items = ids.map((id) => this.generations.find((g) => Number(g.id) === id)).filter(Boolean);
      if (!items.length) return;

      const total = items.length;
      const done = items.filter((g) => g.status === 'completed').length;
      const dead = items.filter((g) => g.status === 'failed' || g.status === 'cancelled').length;
      const processing = items.filter((g) => g.status === 'processing').length;
      const settled = done + dead;

      this.generateProgress = Math.round((settled / total) * 100);
      this.generatedCount = done;

      if (settled === total && done === 0) this.generateStage = 'failed';
      else if (settled === total) this.generateStage = 'done';
      else if (processing) this.generateStage = 'rendering';
      else this.generateStage = 'queued';
    },
    setSource(url, name) {
      this.editSource = { url, name: name || 'Ảnh nguồn' };
      // Bỏ layer 'source' CŨ trước khi thêm ảnh mới — nếu không pushCanvasLayer bị chặn
      // do trùng id 'source' → ảnh mới không vào được canvas khi canvas vẫn còn ảnh cũ.
      this.canvasLayers = this.canvasLayers.filter((l) => l.id !== 'source');
      this.pushCanvasLayer('source', 'source', this.editSource.name, url);
      this.setActiveLayer('source');
      this.toast('Đã chọn ảnh nguồn.');
    },
    async uploadRef(file, select = true) {
      if (!file) { this.toast('Chọn file ảnh.', 'error'); return; }
      const fd = new FormData(); fd.append('image', file);
      const res = await fetch('/api/upload-ref', { method: 'POST', headers: { 'X-XSRF-TOKEN': CSRF(), Accept: 'application/json' }, body: fd });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) { this.toast(d.message || 'Lỗi tải ảnh.', 'error'); return; }
      if (select) this.setSource(d.url, file.name);
      return d;
    },
    pickFromProduct(p) { this.setSource(p.url, p.name); },
    pickFromResult(g) { this.setSource(g.media_url, 'Ảnh kết quả #' + g.id); },
    // Thêm NHIỀU ảnh vào canvas — KHÔNG xóa ảnh/layer cũ (mỗi ảnh 1 layer nguồn riêng).
    addImagesToCanvas(items) {
      const list = (items || []).filter((it) => it && it.url);
      if (!list.length) return;
      const ts = Date.now();
      let lastId = '';
      list.forEach((img, i) => {
        const id = 'src-' + ts + '-' + i;
        if (this.canvasLayers.some((l) => l.id === id)) return;
        this.pushCanvasLayer(id, 'source', img.name || 'Ảnh nguồn', img.url);
        lastId = id;
      });
      if (lastId) this.setActiveLayer(lastId);
      this.saveLayerLayout();
      this.toast('Đã thêm ' + list.length + ' ảnh vào canvas.');
    },
    async translate(promptTo) {
      if (!promptTo) { this.toast('Nhập prompt.', 'error'); return; }
      this.suggestResult = this.suggestResult || {};
      try {
        const d = await this.api('/api/translate', { text: promptTo, direction: 'vi' });
        // §5.2: trước đây 'd.text || d' — thiếu d.text thì lưu NGUYÊN OBJECT vào ô văn bản, hiển thị
        // ra "[object Object]". Nay luôn là chuỗi.
        const text = typeof d?.text === 'string' ? d.text : (typeof d === 'string' ? d : '');
        if (!text) { this.toast('Máy dịch không trả nội dung — thử lại.', 'error'); return; }
        this.suggestResult.prompt_vi = text;
        this.toast('Đã dịch sang tiếng Việt.');
      } catch (e) { this.toast(e.message || 'Lỗi dịch.', 'error'); }
    },
    async suggestStyle(image) {
      if (!this.suggestEnabled) { this.toast('Tính năng "Gợi ý từ ảnh" đang bị tắt trong cài đặt.', 'error'); return; }
      if (!image) { this.toast('Chọn ảnh nguồn để gợi ý.', 'error'); return; }
      this.suggesting = true;
      try {
        const payload = { reference_url: image, creative_level: this.creativeLevel };
        // Gửi độ bám/chi tiết để backend ép bám ảnh gốc (0 = tự theo creative).
        if (this.suggestAdherence) payload.adherence = this.suggestAdherence;
        if (this.suggestDetailLevel) payload.detail_level = this.suggestDetailLevel;
        // Cờ bỏ qua phân tích (mặc định false → AI phân tích bình thường).
        payload.skip_hair = this.suggestSkipHair ? 1 : 0;
        payload.skip_logo = this.suggestSkipLogo ? 1 : 0;
        payload.skip_background = this.suggestSkipBackground ? 1 : 0;
        const d = await this.api('/api/suggest', payload);
        this.suggestResult = d;
        const styles = (d.styles || []).join(', ');
        const extras = [styles, d.garment_type, d.background].filter(Boolean).join(' · ');
        this.toast(extras ? 'Đã gợi ý: ' + extras : 'Đã gợi ý.');
      }
      catch(e){ this.toast(e.message || 'Lỗi gợi ý.', 'error'); }
      finally { this.suggesting = false; }
    },
    statusLabel(s) { return { pending: 'Đang chờ', processing: 'Đang xử lý', completed: 'Hoàn tất', failed: 'Lỗi', cancelled: 'Đã hủy' }[s] || s || ''; },
    // Lazy worker poll: theo dõi một generation qua /api/generations/{id} (backend tự xử lý
    // job đang pending và tự "heal" job kẹt). Single-flight: không bao giờ gửi 2 request song song.
    // opts.select=false (Render đa góc): chỉ cập nhật trạng thái trong this.generations, KHÔNG
    // tự select/đẩy layer canvas — để 4 góc không chèn 4 layer vào canvas đè lên nhau.
    pollGeneration(id, opts = {}) {
      const autoSelect = opts.select !== false;
      if (this._pollTimers[id]) return;
      // §5.2: trước đây poll 500ms KHÔNG có trần — generation kẹt 'processing' (job bị giết,
      // worker không chạy) sẽ poll vô hạn, tốn request và không bao giờ dừng. Nay có trần 10 phút
      // (backend tự "heal" job kẹt sau 6-8 phút, nên 10 phút là đủ rộng).
      const startedAt = Date.now();
      const MAX_POLL_MS = 10 * 60 * 1000;
      const tick = async () => {
        if (Date.now() - startedAt > MAX_POLL_MS) {
          delete this._pollTimers[id];
          console.error('studio pollGeneration: quá thời gian theo dõi', id);
          this.toast('Quá 10 phút chưa có kết quả — đã dừng theo dõi. Bấm "Xử lý ngay" hoặc tải lại trang để cập nhật.', 'error');
          return;
        }
        try {
          const res = await fetch('/api/generations/' + id, { headers: { Accept: 'application/json' } });
          if (!res.ok) { delete this._pollTimers[id]; return; }
          const g = await res.json();
          const item = this.generations.find(x => x.id === Number(g.id));
          if (item) { item.status = g.status; item.media_url = g.media_url; item.error = g.error; item.model = g.model; item.provider = g.provider; item.elapsed_ms = g.elapsed_ms; if (g.meta && typeof g.meta === 'object') item.meta = g.meta; item.is_demo = !!g.is_demo; item.demo_reason = g.demo_reason || null;
            // Dự án có thể đã đổi ở tab khác (gắn/bỏ gắn) — show() trả về nên đồng bộ lại.
            if (g.project_id !== undefined) { item.project_id = g.project_id; item.project = g.project; } }
          // [Đợt 0.2] trạng thái THẬT vừa đổi ⇒ cập nhật thanh tiến trình của lô (không mô phỏng).
          this.syncBatchProgress();
          if (['completed', 'failed', 'cancelled'].includes(g.status)) {
            delete this._pollTimers[id];
            const isInpaint = String(id) === String(this.inpaintGenId);
            const isCompose = this.composeGenIds.length && this.composeGenIds.includes(Number(id));
            if (g.status === 'completed' && g.media_url) {
              if (isInpaint) { this.inpaintStage = 'done'; this.toast('✅ Đã sửa xong ảnh.'); }
              if (autoSelect) this.select({ id: g.id, media_url: g.media_url, type: 'image', status: 'completed' });
            } else if (g.status === 'failed') {
              if (isInpaint) { this.inpaintStage = 'error'; this.inpaintError = g.error || 'Sửa ảnh thất bại.'; this.toast(this.inpaintError, 'error'); }
            } else {
              if (isInpaint) { this.inpaintStage = 'cancelled'; this.toast('Đã hủy sửa ảnh.'); }
            }
            if (isCompose) this._checkComposeDone();
            return;
          }
        } catch (e) { delete this._pollTimers[id]; return; }
        this._pollTimers[id] = setTimeout(tick, 500);
      };
      this._pollTimers[id] = setTimeout(tick, 2000);
    },
    async inpaint(prompt) {
      // Nguồn ảnh = ẢNH ĐANG CHỌN TRÊN CANVAS (bất kỳ: upload / sản phẩm / kết quả / đã chỉnh sửa).
      const src = this.upscaleSrc || (this.preview && this.preview.media_url) || '';
      if (!src || this.inpainting) { this.toast('Chọn một ảnh trên canvas để sửa.', 'error'); return; }
      if (!(prompt || '').trim()) { this.toast('Nhập mô tả chỉnh sửa.', 'error'); return; }
      this.inpainting = true;
      this.inpaintError = '';
      this.inpaintStage = 'send';
      this.inpaintStartTs = Date.now();
      try {
        const body = { prompt, preserve_background: this.inpaintPreserveBg, preserve_face: this.inpaintPreserveFace, source_url: src, feather: Number(this.inpaintFeather) || 0, ...this.projectField() };
        // Model do người dùng CHỌN trên card Sửa ảnh ('' = mặc định: Qwen Edit trong Cài đặt).
        const selModel = this.inpaintModel ? this.inpaintModels.find(o => o.provider + ':' + o.model === this.inpaintModel) : null;
        if (selModel) { body.provider = selModel.provider; body.model = selModel.model; }
        // Gửi mask đã LƯU (bấm "Xong") — dù overlay công cụ đã tắt vẫn xử lý đúng vùng.
        if (this.inpaintMaskDone && this._inpaintMaskKind) {
          body.mask_mode = this._inpaintMaskKind;
          body.region = this.inpaintMaskBox;
          if (this._inpaintMaskKind === 'brush' && this.inpaintBrushData) {
            body.mask_data = this.inpaintBrushData;
          }
        }
        // Endpoint source-agnostic: nhận ẢNH BẤT KỲ (không phụ thuộc generation cha của Outputs).
        const d = await this.api('/api/inpaint', body);
        if (!d.generation_id) { throw new Error(d.message || 'Không tạo được yêu cầu sửa ảnh.'); }
        this.inpaintGenId = d.generation_id;
        this.inpaintStage = 'processing';
        this.addGen({ id: d.generation_id, type: 'image', status: d.status || 'pending', model: d.model || 'inpaint', provider: d.provider || 'qwen', media_url: d.media_url, error: d.error, credits_cost: d.credits_cost ?? 1, created_at: 'Vừa gửi' });
        if (d.credits_left != null) this.creditsLeft = d.credits_left;
        this.pollGeneration(d.generation_id);
      } catch (e) {
        this.inpaintError = e.message || 'Lỗi sửa ảnh.';
        this.inpaintStage = 'error';
        this.toast(this.inpaintError, 'error');
      } finally { this.inpainting = false; }
    },
    async cancelInpaint() {
      if (!this.inpaintGenId || !this.inpaintStage) return;
      try { await this.api('/api/generations/' + this.inpaintGenId + '/cancel', {}); this.inpaintStage = 'cancelled'; this.toast('Đã hủy sửa ảnh.'); }
      catch (e) { this.toast(e.message || 'Lỗi hủy.', 'error'); }
    },
    clearInpaintStatus() { this._inpaintStopDrag && this._inpaintStopDrag(); this.inpaintStage = ''; this.inpaintError = ''; this.inpaintGenId = null; this.inpaintStartTs = 0; this.inpaintMaskMode = 'none'; this.inpaintMaskDone = false; this._inpaintMaskKind = ''; this.inpaintBrushData = ''; this.inpaintErase = false; this._inpaintMaskCanvas = null; this._inpaintMaskCtx = null; this.inpaintMaskBox = { x: 0.425, y: 0.425, w: 0.15, h: 0.15 }; },
    // ── Inpaint Mask: chọn vùng trên ảnh preview (integrated into InpaintCard) ──
    // "Xong": áp dụng vùng chọn/vùng vẽ, thoát công cụ, LƯU mask vào store để Sửa ảnh dùng.
    confirmInpaintMask() {
      if (this.inpaintMaskMode === 'brush') {
        if (this._inpaintDrag) this._inpaintStopDrag(); // đang kéo dở → finalize trước
        if (!this.inpaintBrushData) { this.toast('Chưa vẽ mask — vẽ vùng cần sửa trước.', 'error'); return; }
        this._inpaintMaskKind = 'brush';
      } else if (this.inpaintMaskMode === 'rect') {
        // Rect đã ĐẢO (có mask_data) → gửi như brush mask, không dùng box nữa.
        this._inpaintMaskKind = this.inpaintBrushData ? 'brush' : 'rect';
      } else if (this.inpaintMaskMode === 'freehand') {
        if (this._inpaintFreehandActive) { this.freehandStop(); }
        if (!this.inpaintBrushData) { this.toast('Chưa vẽ vùng chọn — vẽ tự do để khoanh vùng.', 'error'); return; }
        this._inpaintMaskKind = 'brush'; // freehand tạo mask_data như brush
      } else if (this.inpaintMaskMode === 'path') {
        if (!this.inpaintBrushData) { this.toast('Chưa đóng vùng chọn — thêm điểm rồi bấm "Đóng".', 'error'); return; }
        this._inpaintMaskKind = 'brush'; // path tạo mask_data như brush
      } else if (this.inpaintMaskMode === 'magic') {
        if (!this.inpaintBrushData) { this.toast('Chưa chọn vùng — bấm vào ảnh để chọn theo màu.', 'error'); return; }
        this._inpaintMaskKind = 'brush'; // magic tạo mask_data như brush
      } else {
        return;
      }
      this._inpaintStopDrag && this._inpaintStopDrag();
      this.inpaintMaskMode = 'none'; // tắt overlay
      this.inpaintErase = false;
      this.inpaintFreehandPoints = [];
      this.inpaintFreehandPaths = [];
      this.inpaintPathPoints = [];
      this.inpaintPathRegions = [];
      this._pathEditingRegion = -1;
      this._pathHoverRegion = -1;
      if (this.inpaintMaskSource === 'canvas') {
        // Vùng chọn trên canvas: chỉ thoát + xoá dữ liệu, không giữ làm mask inpaint.
        this.inpaintMaskDone = false;
        this._inpaintMaskKind = '';
        this.inpaintBrushData = '';
        this.inpaintMaskSource = 'inpaint';
        this.toast('Đã thoát vùng chọn.');
      } else {
        this.inpaintMaskDone = true;
        this.toast('Đã lưu vùng — bấm "Sửa ảnh" để xử lý.');
      }
    },
    // "Bỏ mask": xoá hoàn toàn vùng chọn/vẽ (rect + brush).
    clearInpaintMask() {
      this._inpaintStopDrag && this._inpaintStopDrag();
      this.inpaintMaskMode = 'none';
      this.inpaintMaskDone = false;
      this._inpaintMaskKind = '';
      this.inpaintBrushData = '';
      this.inpaintErase = false;
      this.inpaintMaskSource = 'inpaint';
      this.inpaintFreehandPoints = [];
      this.inpaintFreehandPaths = [];
      this.inpaintPathPoints = [];
      this.inpaintPathRegions = [];
      this._pathEditingRegion = -1;
      this._pathHoverRegion = -1;
      this.inpaintMaskBox = { x: 0.425, y: 0.425, w: 0.15, h: 0.15 };
    },
    async toggleInpaintMask(mode) {
      if (this.inpaintMaskMode === mode) { this.clearInpaintMask(); return; }
      if (this.inpaintStage === 'send' || this.inpaintStage === 'processing') { this.toast('Đang xử lý — chờ xong rồi chọn vùng.', 'error'); return; }
      // Vẽ mask trên layer bị xoay/lật → vùng chọn lệch khỏi nội dung hiển thị; chuẩn hoá trước.
      if (!(await this.flattenActiveLayerTransform())) return;
      if (this._inpaintDrag) this._inpaintStopDrag();
      this.inpaintMaskSource = 'inpaint';
      this.inpaintMaskMode = mode;
      this.inpaintMaskDone = false;
      this._inpaintMaskKind = '';
      this.inpaintBrushData = '';
      this.inpaintMaskBox = { x: 0.425, y: 0.425, w: 0.15, h: 0.15 }; // khung mặc định 15% khi bật — nhỏ, dễ kéo/chỉnh
      if (mode === 'brush') { this._initInpaintBrush(); this.inpaintErase = false; }
      if (mode === 'freehand') { this.inpaintFreehandPoints = []; this.inpaintFreehandPaths = []; this._initInpaintBrush(); }
      if (mode === 'path') { this.inpaintPathPoints = []; this.inpaintPathRegions = []; this.inpaintPathCloseHover = false; this._pathEditingRegion = -1; this._pathHoverRegion = -1; this._initInpaintBrush(); }
      if (mode === 'magic') { this._initInpaintBrush(); }
    },
    // Mở vùng chọn từ THANH CÔNG CỤ CANVAS (rect/freehand) — dùng chung overlay chính xác của Inpaint,
    // nhưng hành động là Xóa / Tô màu / Feather tại chỗ (không phải mask AI inpaint).
    async startCanvasSelect(mode) {
      if (this.inpaintStage === 'send' || this.inpaintStage === 'processing') { this.toast('Đang xử lý — chờ xong rồi chọn vùng.', 'error'); return; }
      if (this.inpaintMaskMode === mode) { this.clearInpaintMask(); return; }
      // Lasso/rect/vùng chọn cũng thao tác theo pixel layer → cần layer thẳng hàng (không xoay/lật).
      if (!(await this.flattenActiveLayerTransform())) return;
      this.inpaintMaskSource = 'canvas';
      if (this._inpaintDrag) this._inpaintStopDrag();
      this.inpaintMaskMode = mode;
      this.inpaintMaskDone = false;
      this._inpaintMaskKind = '';
      this.inpaintBrushData = '';
      this.inpaintMaskBox = { x: 0.425, y: 0.425, w: 0.15, h: 0.15 };
      if (mode === 'freehand') { this.inpaintFreehandPoints = []; this.inpaintFreehandPaths = []; this._initInpaintBrush(); }
      if (mode === 'path') { this.inpaintPathPoints = []; this.inpaintPathRegions = []; this.inpaintPathCloseHover = false; this._pathEditingRegion = -1; this._pathHoverRegion = -1; this._initInpaintBrush(); }
      if (mode === 'magic') { this._initInpaintBrush(); }
    },
    // ── Freehand (lasso) select: vẽ tự do tạo vùng kín → mask ──
    freehandStart(e) {
      if (this.inpaintMaskMode !== 'freehand') return;
      e.stopPropagation();
      const p = this.inpaintMaskPointer(e); if (!p) return;
      this._inpaintFreehandActive = true;
      this.inpaintFreehandPoints = [p];
      // Chế độ 'new' → xoá nét cũ; 'add'/'subtract' → giữ mask + paths để cộng/trừ vào vùng hiện có.
      if (this.inpaintSelectMode === 'new') {
        if (this._inpaintMaskCtx && this._inpaintMaskCanvas) {
          this._inpaintMaskCtx.clearRect(0, 0, this._inpaintMaskCanvas.width, this._inpaintMaskCanvas.height);
        }
        this.inpaintFreehandPaths = [];
      }
    },
    setInpaintSelectMode(mode) { this.inpaintSelectMode = (this.inpaintSelectMode === mode) ? 'new' : mode; },
    freehandMove(e) {
      if (!this._inpaintFreehandActive) return;
      const p = this.inpaintMaskPointer(e); if (!p) return;
      const pts = this.inpaintFreehandPoints;
      const last = pts[pts.length - 1];
      if (last && Math.hypot(p.nx - last.nx, p.ny - last.ny) < 0.002) return;
      pts.push(p);
    },
    freehandStop() {
      if (!this._inpaintFreehandActive) return;
      this._inpaintFreehandActive = false;
      const pts = this.inpaintFreehandPoints;
      if (pts.length < 3) { this.inpaintFreehandPoints = []; return; }
      if (!this._inpaintMaskCtx) { this._initInpaintBrush(); }
      const c = this._inpaintMaskCanvas, ctx = this._inpaintMaskCtx;
      if (!c || !ctx) { return; }
      const w = c.width, h = c.height;
      ctx.globalCompositeOperation = this.inpaintSelectMode === 'subtract' ? 'destination-out' : 'source-over';
      ctx.beginPath();
      pts.forEach((pt, i) => { const x = pt.nx * w, y = pt.ny * h; if (i === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); } });
      ctx.closePath();
      ctx.fillStyle = 'rgba(220,38,38,0.6)';
      ctx.fill();
      ctx.globalCompositeOperation = 'source-over';
      this._finalizeInpaintBrush();
      // Lưu nét đã hoàn thành vào paths để preview hiển thị ĐỦ các lần cộng/trừ.
      this.inpaintFreehandPaths.push(pts.map((p) => ({ nx: p.nx, ny: p.ny })));
      this.inpaintFreehandPoints = [];
      // Sau vòng lasso đầu tiên chuyển sang 'add' (giống path/magic) — vẽ tiếp sẽ CỘNG DỒN
      // vùng thay vì vô tình XOÁ vùng vừa khoanh (bấm nút ➕ nếu muốn chủ động thêm).
      if (this.inpaintSelectMode === 'new') this.inpaintSelectMode = 'add';
    },
    // ── Path (curve) select: click thêm điểm neo → đường cong mượt → đóng để tạo vùng chọn ──
    // Bán kính grab node/handle — lớn hơn trên touch/bút (pointer: coarse) để dễ bấm bằng ngón tay.
    _grabR() {
      const coarse = typeof matchMedia !== 'undefined' && matchMedia('(pointer: coarse)').matches;
      return coarse ? 22 : 12;
    },
    // ── Bezier curve selection (Krita Pen tool): click=neo (kéo=handle), kéo NODE/HANDLE để chỉnh, right-click=xóa ──
    _pathHit(p) {
      const m = this.canvasMetrics();
      if (!m) return null;
      const d = (nx, ny) => Math.hypot((p.nx - nx) * m.vw, (p.ny - ny) * m.vh);
      const R = this._grabR();
      for (let i = this.inpaintPathPoints.length - 1; i >= 0; i--) {
        const nd = this.inpaintPathPoints[i];
        // Bỏ qua tay điều khiển SUY BIẾN (độ dài ~0) để kéo NODE thay vì kéo tay lệch.
        if (Math.hypot((nd.ox || 0) * m.vw, (nd.oy || 0) * m.vh) > 4 && d(nd.nx + (nd.ox || 0), nd.ny + (nd.oy || 0)) < R) return { type: 'handle', i, side: 'out' };
        if (Math.hypot((nd.ix || 0) * m.vw, (nd.iy || 0) * m.vh) > 4 && d(nd.nx + (nd.ix || 0), nd.ny + (nd.iy || 0)) < R) return { type: 'handle', i, side: 'in' };
      }
      for (let i = this.inpaintPathPoints.length - 1; i >= 0; i--) {
        const nd = this.inpaintPathPoints[i];
        if (d(nd.nx, nd.ny) < R + 2) return { type: 'node', i };
      }
      return null;
    },
    _pathScreenDist(nx, ny, px, py) {
      const m = this.canvasMetrics(); if (!m) return Infinity;
      return Math.hypot((nx - px) * m.vw, (ny - py) * m.vh);
    },
    // Hover gần điểm BẮT ĐẦU (snap để đóng kín): highlight node đầu + guide line nối về điểm đầu.
    // Đồng thời: khi không đang vẽ, hover gần đường bao vùng ĐÃ ĐÓNG → đánh dấu vùng để hiện nút 'Sửa'.
    _clearPathHover() {
      if (this._pathHoverTimer) { clearTimeout(this._pathHoverTimer); this._pathHoverTimer = null; }
      this._pathHoverRegion = -1;
      this._pathHoverPoint = null;
    },
    pathHover(e) {
      if (this.inpaintMaskMode !== 'path' || this._pathDrag) { this.inpaintPathCloseHover = false; this._clearPathHover(); return; }
      const p = this.inpaintMaskPointer(e);
      if (!p) { this.inpaintPathCloseHover = false; this._clearPathHover(); return; }
      if (this.inpaintPathPoints.length >= 3) {
        const s = this.inpaintPathPoints[0];
        this.inpaintPathCloseHover = this._pathScreenDist(p.nx, p.ny, s.nx, s.ny) < (this._grabR() + 8);
      } else {
        this.inpaintPathCloseHover = false;
      }
      // Chỉ hover vùng ĐÃ ĐÓNG khi không đang vẽ điểm mới.
      if (this.inpaintPathPoints.length === 0) {
        const hit = this._regionHoverHit(p);
        if (hit.region >= 0) {
          // Đang hover: giữ nút + hủy timer ẩn.
          if (this._pathHoverTimer) { clearTimeout(this._pathHoverTimer); this._pathHoverTimer = null; }
          this._pathHoverRegion = hit.region;
          this._pathHoverPoint = { nx: hit.x, ny: hit.y };
        } else if (this._pathHoverRegion >= 0 && !this._pathHoverTimer) {
          // Rời vùng → đếm 450ms mới ẩn nút (đủ thời gian di tới bấm).
          this._pathHoverTimer = setTimeout(() => { this._pathHoverRegion = -1; this._pathHoverPoint = null; this._pathHoverTimer = null; }, 450);
        }
      } else {
        this._clearPathHover();
      }
    },
    // Khoảng cách điểm-đoạn thẳng (px màn hình).
    _pointSegDist(px, py, x1, y1, x2, y2) {
      const dx = x2 - x1, dy = y2 - y1;
      const len2 = dx * dx + dy * dy;
      let t = len2 ? ((px - x1) * dx + (py - y1) * dy) / len2 : 0;
      t = Math.max(0, Math.min(1, t));
      const qx = x1 + t * dx, qy = y1 + t * dy;
      return Math.hypot(px - qx, py - qy);
    },
    // Điểm gần nhất trên đoạn thẳng AB với điểm P (px màn hình).
    _closestOnSeg(px, py, x1, y1, x2, y2) {
      const dx = x2 - x1, dy = y2 - y1;
      const len2 = dx * dx + dy * dy;
      let t = len2 ? ((px - x1) * dx + (py - y1) * dy) / len2 : 0;
      t = Math.max(0, Math.min(1, t));
      return { x: x1 + t * dx, y: y1 + t * dy };
    },
    // Vùng đã đóng gần con trỏ nhất: trả {region, x, y} (x,y = điểm gần nhất, normalized) — anchor nút 'Sửa'.
    _regionHoverHit(p) {
      const m = this.canvasMetrics(); if (!m) return { region: -1, x: 0, y: 0 };
      const sx = p.nx * m.vw, sy = p.ny * m.vh;
      let best = { region: -1, x: p.nx, y: p.ny }, bestDist = this._grabR() + 6;
      for (let r = 0; r < this.inpaintPathRegions.length; r++) {
        const arr = this.inpaintPathRegions[r]; const n = arr.length;
        for (let i = 0; i < n; i++) {
          const a = arr[i], b = arr[(i + 1) % n];
          const ax = a.nx * m.vw, ay = a.ny * m.vh, bx = b.nx * m.vw, by = b.ny * m.vh;
          const d = this._pointSegDist(sx, sy, ax, ay, bx, by);
          if (d < bestDist) {
            const cp = this._closestOnSeg(sx, sy, ax, ay, bx, by);
            bestDist = d; best = { region: r, x: cp.x / m.vw, y: cp.y / m.vh };
          }
        }
      }
      return best;
    },
    // Mở vùng đã đóng để chỉnh sửa (từ nút 'Sửa').
    enterEditRegion(r) {
      if (this.inpaintMaskMode !== 'path') return;
      if (this._pathEditingRegion >= 0) return;
      if (this._loadEditRegion(r)) { this._clearPathHover(); this.inpaintPathCloseHover = false; }
    },
    // Hit node (KHÔNG xét tay điều khiển) — dùng cho Ctrl+click đổi kiểu node.
    _pathNodeHit(p) {
      const m = this.canvasMetrics(); if (!m) return -1;
      for (let i = this.inpaintPathPoints.length - 1; i >= 0; i--) {
        const nd = this.inpaintPathPoints[i];
        if (Math.hypot((p.nx - nd.nx) * m.vw, (p.ny - nd.ny) * m.vh) < (this._grabR() + 2)) return i;
      }
      return -1;
    },
    // Ctrl+click node → xoay vòng kiểu: smooth (mượt) → cusp (góc cong lệch) → sharp (góc nhọn).
    _zeroHandles(nd) { return !(nd.ox || 0) && !(nd.oy || 0) && !(nd.ix || 0) && !(nd.iy || 0); },
    // Sinh tay điều khiển mặc định cho node khi chuyển kiểu KHỎI 'sharp' (đang không có tay).
    _initHandlesForKind(i, kind) {
      const pts = this.inpaintPathPoints; const n = pts.length;
      const nd = pts[i]; if (!nd) return;
      if (n < 2) { nd.ox = 0.09; nd.oy = 0; nd.ix = -0.09; nd.iy = 0; nd.sym = kind === 'smooth'; return; }
      const prev = pts[(i - 1 + n) % n], next = pts[(i + 1) % n];
      const tox = next.nx - nd.nx, toy = next.ny - nd.ny;
      const tx = prev.nx - nd.nx, ty = prev.ny - nd.ny;
      const lto = Math.hypot(tox, toy), lti = Math.hypot(tx, ty);
      nd.ox = (lto > 0.0001 ? tox * 0.4 : 0.09); nd.oy = (lto > 0.0001 ? toy * 0.4 : 0);
      if (kind === 'smooth') { nd.ix = -nd.ox; nd.iy = -nd.oy; nd.sym = true; }
      else if (kind === 'cusp') { if (lti > 0.0001) { nd.ix = tx * 0.4; nd.iy = ty * 0.4; } else { nd.ix = -nd.ox * 0.5; nd.iy = -nd.oy * 0.5; } nd.sym = false; }
      else { nd.ox = 0; nd.oy = 0; nd.ix = 0; nd.iy = 0; nd.sym = false; }
    },
    pathSetNodeKind(i) {
      if (this.inpaintMaskMode !== 'path') return;
      const nd = this.inpaintPathPoints[i]; if (!nd) return;
      this._snapshotInpaintPath();
      const order = ['smooth', 'cusp', 'sharp'];
      const cur = nd.kind || 'smooth';
      const next = order[(order.indexOf(cur) + 1) % order.length];
      const hadNoHandles = cur === 'sharp' || this._zeroHandles(nd);
      nd.kind = next;
      // Chuyển KIỂU → đảm bảo tay điều khiển TƯƠNG ỨNG hiện ra (nếu node đang không có tay).
      if ((next === 'smooth' || next === 'cusp') && hadNoHandles) this._initHandlesForKind(i, next);
      else if (next === 'smooth') nd.sym = true;
      else if (next === 'cusp') nd.sym = false;
      else if (next === 'sharp') { nd.ox = 0; nd.oy = 0; nd.ix = 0; nd.iy = 0; nd.sym = false; }
      this.toast(next === 'smooth' ? 'Node: Mượt' : next === 'cusp' ? 'Node: Cusp' : 'Node: Góc nhọn');
    },
    // Hit node của vùng ĐÃ ĐÓNG (để mở lại chỉnh sửa) — trả về {region, index}.
    _regionNodeHit(p) {
      const m = this.canvasMetrics(); if (!m) return { region: -1, index: -1 };
      for (let r = 0; r < this.inpaintPathRegions.length; r++) {
        const arr = this.inpaintPathRegions[r];
        for (let i = arr.length - 1; i >= 0; i--) {
          const nd = arr[i];
          if (Math.hypot((p.nx - nd.nx) * m.vw, (p.ny - nd.ny) * m.vh) < (this._grabR() + 2)) return { region: r, index: i };
        }
      }
      return { region: -1, index: -1 };
    },
    // Nạp vùng đã đóng vào inpaintPathPoints để chỉnh sửa (giữ _pathEditingRegion để đóng là cập nhật).
    _loadEditRegion(region) {
      const arr = this.inpaintPathRegions[region]; if (!arr) return false;
      this._pathEditingRegion = region;
      this.inpaintPathPoints = arr.map((p) => ({ nx: p.nx, ny: p.ny, ox: p.ox || 0, oy: p.oy || 0, ix: p.ix || 0, iy: p.iy || 0, sym: p.sym !== false, kind: p.kind || 'smooth' }));
      return true;
    },
    // Vẽ lại mask từ TẤT CẢ vùng đã đóng (dùng để cập nhật sau khi sửa 1 vùng / thêm vùng mới).
    _rebakePathRegions() {
      if (!this._inpaintMaskCtx) this._initInpaintBrush();
      const c = this._inpaintMaskCanvas, ctx = this._inpaintMaskCtx;
      if (!c || !ctx) return;
      const w = c.width, h = c.height;
      ctx.globalCompositeOperation = 'source-over';
      ctx.clearRect(0, 0, w, h);
      for (const arr of this.inpaintPathRegions) {
        ctx.globalCompositeOperation = (arr._mode === 'subtract') ? 'destination-out' : 'source-over';
        const P = arr.map((p) => ({ x: p.nx * w, y: p.ny * h, ox: (p.ox || 0) * w, oy: (p.oy || 0) * h, ix: (p.ix || 0) * w, iy: (p.iy || 0) * h }));
        const n = P.length;
        ctx.beginPath();
        ctx.moveTo(P[0].x, P[0].y);
        for (let i = 0; i < n; i++) {
          const a = P[i], b = P[(i + 1) % n];
          ctx.bezierCurveTo(a.x + a.ox, a.y + a.oy, b.x + b.ix, b.y + b.iy, b.x, b.y);
        }
        ctx.closePath();
        ctx.fillStyle = 'rgba(220,38,38,0.6)';
        ctx.fill();
      }
      ctx.globalCompositeOperation = 'source-over';
      this._finalizeInpaintBrush();
    },
    pathDown(e) {
      if (this.inpaintMaskMode !== 'path') return;
      e.stopPropagation();
      const p = this.inpaintMaskPointer(e); if (!p) return;
      const pts = this.inpaintPathPoints;
      // Ctrl(hoặc ⌘)+click node → đổi kiểu node (đang vẽ hoặc của vùng đã đóng).
      if (e.ctrlKey || e.metaKey) {
        const ni = this._pathNodeHit(p);
        if (ni >= 0) { this.pathSetNodeKind(ni); return; }
        const ri = this._regionNodeHit(p);
        if (ri.region >= 0) { this._loadEditRegion(ri.region); this.pathSetNodeKind(ri.index); return; }
        return;
      }
      // Không có điểm đang vẽ (đã đóng hết) → bấm node của vùng ĐÃ ĐÓNG để mở lại chỉnh sửa nó.
      if (pts.length === 0) {
        const ri = this._regionNodeHit(p);
        if (ri.region >= 0) {
          this._loadEditRegion(ri.region);
          this._pathDrag = { type: 'node', i: ri.index, sx: p.nx, sy: p.ny, moved: false };
          return;
        }
      }
      // Snap/đóng kín: >=3 điểm & nhấp gần điểm BẮT ĐẦU → tap = đóng, kéo = di chuyển node đầu.
      if (pts.length >= 3 && this._pathScreenDist(p.nx, p.ny, pts[0].nx, pts[0].ny) < (this._grabR() + 10)) {
        this.inpaintPathCloseHover = false;
        this._pathDrag = { type: 'close', i: 0, sx: p.nx, sy: p.ny, moved: false };
        return;
      }
      this._snapshotInpaintPath();
      const hit = this._pathHit(p);
      if (hit) { this._pathDrag = { ...hit, broke: false, sx: p.nx, sy: p.ny, moved: false }; return; }
      // Khoảng trống → neo mới, push NGAY vào mảng (hiển thị ngay ở lần nhấp đầu).
      // Kéo (move) chỉnh tay điều khiển của chính node này in-place để preview sống.
      const i = this.inpaintPathPoints.push({ nx: p.nx, ny: p.ny, ox: 0, oy: 0, ix: 0, iy: 0, sym: true, kind: 'smooth' }) - 1;
      this._pathDrag = { type: 'pending', i, nx: p.nx, ny: p.ny, ox: 0, oy: 0, ix: 0, iy: 0, sym: true, kind: 'smooth', sx: p.nx, sy: p.ny, moved: false };
    },
    pathMove(e) {
      if (this.inpaintMaskMode !== 'path' || !this._pathDrag) return;
      const p = this.inpaintMaskPointer(e); if (!p) return;
      const dr = this._pathDrag;
      if (dr.type === 'close') {
        // Kéo >4px → chuyển thành di chuyển node đầu (không đóng nữa).
        if (this._pathScreenDist(p.nx, p.ny, dr.sx, dr.sy) > 4) dr.moved = true;
        if (dr.moved) dr.type = 'node';
        else return;
      }
      if (dr.type === 'pending') {
        const nd = this.inpaintPathPoints[dr.i]; if (!nd) return;
        nd.ox = (p.nx - dr.nx) * 0.5; nd.oy = (p.ny - dr.ny) * 0.5;
        nd.ix = -nd.ox; nd.iy = -nd.oy; nd.sym = true;
        return;
      }
      if (dr.type === 'node') { const nd = this.inpaintPathPoints[dr.i]; if (nd) { nd.nx = p.nx; nd.ny = p.ny; } return; }
      if (dr.type === 'handle') {
        const nd = this.inpaintPathPoints[dr.i]; if (!nd) return;
        const dx = p.nx - nd.nx, dy = p.ny - nd.ny;
        if (e.altKey) dr.broke = true; // Alt = phá đối xứng (asymmetric)
        if (dr.side === 'out') { nd.ox = dx; nd.oy = dy; if (nd.sym !== false && !dr.broke) { nd.ix = -dx; nd.iy = -dy; } }
        else { nd.ix = dx; nd.iy = dy; if (nd.sym !== false && !dr.broke) { nd.ox = -dx; nd.oy = -dy; } }
        if (dr.broke) nd.sym = false;
      }
    },
    pathUp() {
      if (this.inpaintMaskMode !== 'path') return;
      const dr = this._pathDrag; this._pathDrag = null;
      // Nhấp (không kéo) gần điểm BẮT ĐẦU → đóng kín vùng chọn, rồi bắt đầu vùng mới.
      if (dr && dr.type === 'close' && !dr.moved) { this.pathClose(); return; }
      // Neo đã push ở pathDown; chỉ kết thúc kéo (giữ node + tay điều khiển vừa chỉnh).
    },
    // ── Undo/Redo riêng cho mask path ──
    _snapshotInpaintPath() {
      this._inpaintPathUndo = this._inpaintPathUndo || [];
      this._inpaintPathRedo = [];
      this._inpaintPathUndo.push({ points: this.inpaintPathPoints.map((p) => ({ ...p })), regions: this.inpaintPathRegions.map((r) => r.map((p) => ({ ...p }))) });
      if (this._inpaintPathUndo.length > 30) this._inpaintPathUndo.shift();
    },
    _applyInpaintSnap(snap) {
      this.inpaintPathPoints = snap.points.map((p) => ({ ...p }));
      this.inpaintPathRegions = snap.regions.map((r) => r.map((p) => ({ ...p })));
      this._pathEditingRegion = -1;
      this._rebakePathRegions();
      this._finalizeInpaintBrush();
    },
    inpaintPathUndo() {
      if (this.inpaintMaskMode !== 'path') return;
      const snap = (this._inpaintPathUndo || []).pop();
      if (!snap) { this.toast('Không còn gì để hoàn tác.', 'info'); return; }
      this._inpaintPathRedo = this._inpaintPathRedo || [];
      this._inpaintPathRedo.push({ points: this.inpaintPathPoints.map((p) => ({ ...p })), regions: this.inpaintPathRegions.map((r) => r.map((p) => ({ ...p }))) });
      this._applyInpaintSnap(snap);
      this.toast('Đã hoàn tác.');
    },
    inpaintPathRedo() {
      if (this.inpaintMaskMode !== 'path') return;
      const snap = (this._inpaintPathRedo || []).pop();
      if (!snap) { this.toast('Không còn gì để làm lại.', 'info'); return; }
      this._inpaintPathUndo = this._inpaintPathUndo || [];
      this._inpaintPathUndo.push({ points: this.inpaintPathPoints.map((p) => ({ ...p })), regions: this.inpaintPathRegions.map((r) => r.map((p) => ({ ...p }))) });
      this._applyInpaintSnap(snap);
      this.toast('Đã làm lại.');
    },
    pathDeleteNode(i) { if (this.inpaintMaskMode !== 'path') return; this._snapshotInpaintPath(); if (i >= 0 && i < this.inpaintPathPoints.length) this.inpaintPathPoints.splice(i, 1); },
    pathUndoPoint() { if (this.inpaintMaskMode !== 'path') return; this._snapshotInpaintPath(); this.inpaintPathPoints.pop(); },
    pathClose() {
      if (this.inpaintMaskMode !== 'path') return;
      this.inpaintPathCloseHover = false;
      this._snapshotInpaintPath();
      const pts = this.inpaintPathPoints;
      if (pts.length < 3) { this.toast('Cần ít nhất 3 điểm để tạo vùng chọn.', 'error'); return; }
      const mode = this.inpaintSelectMode === 'subtract' ? 'subtract' : 'add';
      const clone = pts.map((p) => ({ nx: p.nx, ny: p.ny, ox: p.ox || 0, oy: p.oy || 0, ix: p.ix || 0, iy: p.iy || 0, sym: p.sym !== false, kind: p.kind || 'smooth' }));
      // Nếu đang chỉnh sửa lại vùng đã đóng → thay thế vùng đó (giữ nguyên mode gốc).
      let editing = this._pathEditingRegion != null && this._pathEditingRegion >= 0 && this._pathEditingRegion < this.inpaintPathRegions.length;
      // Chế độ 'new' luôn bắt đầu vùng mới (xóa sạch vùng cũ).
      if (this.inpaintSelectMode === 'new') { editing = false; this.inpaintPathRegions = []; this._pathEditingRegion = -1; }
      if (editing) {
        const j = this._pathEditingRegion;
        const old = this.inpaintPathRegions[j];
        clone._mode = (old && old._mode) || mode; // giữ chế độ gốc khi sửa
        this.inpaintPathRegions.splice(j, 1, clone);
      } else {
        clone._mode = mode;
        this.inpaintPathRegions.push(clone);
      }
      this._pathEditingRegion = -1;
      this.inpaintPathPoints = [];
      this._rebakePathRegions();
      if (this.inpaintSelectMode === 'new') this.inpaintSelectMode = 'add';
      // Card "Sửa ảnh" (source=inpaint): vẽ xong & ĐÓNG → LẤY NGAY vùng chọn làm mask (không cần bấm "Xong" riêng).
      if (this.inpaintMaskSource === 'inpaint') {
        this._inpaintMaskKind = 'brush';
        this.inpaintMaskDone = true;
        this.inpaintMaskMode = 'none';
        this._pathHoverRegion = -1;
        this._pathHoverPoint = null;
        this.toast('Đã lấy vùng chọn làm mask — bấm "Sửa ảnh" để xử lý.');
      } else {
        this.toast(editing ? 'Đã cập nhật vùng chọn Bezier.' : 'Đã tạo vùng chọn Bezier — vẽ tiếp hoặc bấm Xóa/Tô/Nhân đôi/Xong.');
      }
    },
    // ── Magic Wand: click chọn vùng theo màu tương tự (flood-fill theo ngưỡng) ──
    async magicWand(e) {
      if (this.inpaintMaskMode !== 'magic') return;
      e.stopPropagation();
      const p = this.inpaintMaskPointer(e); if (!p) return;
      const l = this.activeLayer;
      if (!l || !l.image) { this.toast('Chọn 1 layer ảnh để dùng Magic Wand.', 'error'); return; }
      const tol = Math.max(1, Math.min(128, Number(this.magicTolerance) || 32));
      try {
        const img = await this._loadImageSrc(l.image);
        const w = img.naturalWidth, h = img.naturalHeight;
        // Độ chính xác: flood-fill ở 1024 (gấp đôi 512) rồi thu nhỏ về mask canvas
        // → mép vùng chọn được anti-alias (sub-pixel), bớt răng cưa khi phóng to ảnh.
        const base = 1024, ia = w / h;
        const mw = ia >= 1 ? base : Math.max(1, Math.round(base * ia));
        const mh = ia >= 1 ? Math.max(1, Math.round(base / ia)) : base;
        const c = document.createElement('canvas'); c.width = mw; c.height = mh;
        const ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0, mw, mh);
        const id = ctx.getImageData(0, 0, mw, mh);
        const d = id.data;
        const sx = Math.min(mw - 1, Math.max(0, Math.round(p.nx * mw)));
        const sy = Math.min(mh - 1, Math.max(0, Math.round(p.ny * mh)));
        const si = (sy * mw + sx) * 4;
        const sr = d[si], sg = d[si + 1], sb = d[si + 2];
        // Flood-fill (stack/DFS) theo ngưỡng màu — EUCLIDEAN distance (chính xác hơn Manhattan).
        const visited = new Uint8Array(mw * mh);
        const stack = [sy * mw + sx];
        visited[sy * mw + sx] = 1;
        const threshSq = tol * tol;
        while (stack.length) {
          const idx = stack.pop();
          const x = idx % mw, y = (idx / mw) | 0;
          const neighbors = y > 0 ? [idx - mw] : [];
          if (y < mh - 1) neighbors.push(idx + mw);
          if (x > 0) neighbors.push(idx - 1);
          if (x < mw - 1) neighbors.push(idx + 1);
          for (const ni of neighbors) {
            if (visited[ni]) continue;
            const pi = ni * 4;
            const dr = d[pi] - sr, dg = d[pi + 1] - sg, db = d[pi + 2] - sb;
            if (dr * dr + dg * dg + db * db <= threshSq) { visited[ni] = 1; stack.push(ni); }
          }
        }
        // Tạo temp canvas chứa vùng chọn (đỏ), rồi vẽ lên mask canvas theo add/subtract.
        if (!this._inpaintMaskCtx) this._initInpaintBrush();
        const mc = this._inpaintMaskCanvas, mctx = this._inpaintMaskCtx;
        if (!mc || !mctx) return;
        if (this.inpaintSelectMode === 'new') { mctx.clearRect(0, 0, mc.width, mc.height); this.inpaintPathRegions = []; }
        const temp = document.createElement('canvas'); temp.width = mw; temp.height = mh;
        const tctx = temp.getContext('2d');
        const td = tctx.createImageData(mw, mh);
        for (let i = 0; i < mw * mh; i++) {
          if (visited[i]) { td.data[i * 4] = 220; td.data[i * 4 + 1] = 38; td.data[i * 4 + 2] = 38; td.data[i * 4 + 3] = 255; }
        }
        tctx.putImageData(td, 0, 0);
        // Độ mịn: blur mép vùng chọn → mask có viền mềm, chống răng cưa.
        // Nhân 2 vì xử lý ở 1024 (gấp đôi mask 512) → giá trị slider giữ nguyên cảm giác cũ.
        let selCanvas = temp;
        const feather = Math.max(0, Math.min(20, Number(this.magicFeather) || 0)) * 2;
        if (feather > 0) {
          const blurred = document.createElement('canvas'); blurred.width = mw; blurred.height = mh;
          const bctx = blurred.getContext('2d');
          bctx.filter = 'blur(' + feather + 'px)';
          bctx.drawImage(temp, 0, 0);
          selCanvas = blurred;
        }
        mctx.globalCompositeOperation = this.inpaintSelectMode === 'subtract' ? 'destination-out' : 'source-over';
        // Thu 1024 → kích thước mask canvas (512) — drawImage resize cho anti-alias mép.
        mctx.drawImage(selCanvas, 0, 0, mc.width, mc.height);
        mctx.globalCompositeOperation = 'source-over';
        this._finalizeInpaintBrush();
        if (this.inpaintSelectMode === 'new') this.inpaintSelectMode = 'add';
        this.toast('Đã chọn vùng theo màu.');
      } catch (err) { this.toast('Không chọn được vùng.', 'error'); }
    },
    // ── Đảo ngược vùng chọn (invert selection) ──
    async invertSelection() {
      const mode = this.inpaintMaskMode;
      if (mode === 'none') { this.toast('Chưa có vùng chọn để đảo.', 'error'); return; }
      if (mode === 'rect' && !this.inpaintBrushData) {
        const b = this.inpaintMaskBox;
        if (!b || b.w < 0.02 || b.h < 0.02) { this.toast('Chưa có vùng chọn.', 'error'); return; }
        if (!this._inpaintMaskCtx) this._initInpaintBrush();
        const c = this._inpaintMaskCanvas, ctx = this._inpaintMaskCtx;
        if (!c || !ctx) return;
        const w = c.width, h = c.height;
        ctx.clearRect(0, 0, w, h);
        ctx.fillStyle = 'rgba(220,38,38,0.6)';
        ctx.fillRect(b.x * w, b.y * h, b.w * w, b.h * h);
        this._finalizeInpaintBrush();
        this._inpaintMaskKind = 'brush'; // rect → mask để đảo
      }
      if (!this.inpaintBrushData) { this.toast('Chưa có vùng chọn.', 'error'); return; }
      try {
        const mimg = await this._loadImageSrc('data:image/png;base64,' + this.inpaintBrushData);
        const w = mimg.naturalWidth, h = mimg.naturalHeight;
        const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(mimg, 0, 0);
        const id = ctx.getImageData(0, 0, w, h);
        const d = id.data;
        for (let i = 0; i < d.length; i += 4) {
          const v = 255 - d[i]; // đảo đen↔trắng
          d[i] = v; d[i + 1] = v; d[i + 2] = v; d[i + 3] = 255;
        }
        ctx.putImageData(id, 0, 0);
        this.inpaintBrushData = canvas.toDataURL('image/png').replace(/^data:image\/png;base64,/, '');
        // Đồng bộ overlay mask canvas (đỏ) theo mask đã đảo → add/subtract/vẽ tiếp vẫn đúng.
        const mc = this._inpaintMaskCanvas, mctx = this._inpaintMaskCtx;
        if (mc && mctx && mc.width === w && mc.height === h) {
          mctx.globalCompositeOperation = 'source-over';
          mctx.clearRect(0, 0, w, h);
          const rd = mctx.createImageData(w, h);
          for (let i = 0; i < w * h; i++) {
            const v = d[i * 4]; // 0=vùng chọn, 255=ngoài
            rd.data[i * 4] = 220; rd.data[i * 4 + 1] = 38; rd.data[i * 4 + 2] = 38;
            rd.data[i * 4 + 3] = Math.round((255 - v) * (153 / 255)); // đỏ theo độ chọn
          }
          mctx.putImageData(rd, 0, 0);
        }
        this.toast('Đã đảo ngược vùng chọn.');
      } catch (err) { this.toast('Không đảo được vùng chọn.', 'error'); }
    },
    inpaintMaskPointer(e) {
      const m = this.canvasMetrics();
      if (!m) return null;
      // Convert clientX/Y to normalized coords (0..1) on the visible image, accounting for zoom/pan
      const nx = this._clamp((e.clientX - m.crLeft - m.vx) / m.vw, 0, 1);
      const ny = this._clamp((e.clientY - m.crTop - m.vy) / m.vh, 0, 1);
      return { nx, ny };
    },
    // Bắt đầu kéo 1 thao tác với KEY TƯỜNG MINH — gọi từ .stop trên chính box/handle
    // (giống hệt crop: mỗi vùng biết mình là gì, KHÔNG hit-test, không bao giờ nhầm
    // 'resize' ↔ 'move', và không vô tình tạo vùng mới khi bấm lệch ra ngoài).
    beginInpaintDrag(key, e) {
      if (this.inpaintMaskMode === 'none') return;
      e.stopPropagation();
      const p = this.inpaintMaskPointer(e); if (!p) return;
      // Drag cũ còn dở → đóng sạch trước.
      if (this._inpaintDrag) this._inpaintStopDrag();
      const handlers = { move: (ev) => this._inpaintQueue(ev), up: () => this._inpaintStopDrag() };
      if (key === 'brush') {
        this._inpaintBrushDrawing = true;
        this._inpaintDrag = { key: 'brush', sx: e.clientX, sy: e.clientY, last: p, handlers };
        // Snapshot để Ctrl+Z hoàn tác từng nét (giới hạn 30 bước).
        if (this._inpaintMaskCanvas && this._inpaintMaskCtx) {
          const snap = this._inpaintMaskCtx.getImageData(0, 0, this._inpaintMaskCanvas.width, this._inpaintMaskCanvas.height);
          this._inpaintUndoStack.push(snap);
          if (this._inpaintUndoStack.length > 30) this._inpaintUndoStack.shift();
        }
        this._drawInpaintBrushDot(p);
      } else if (key === 'draw') {
        // Kéo tạo vùng mới từ điểm nhấn. Lưu box cũ để khôi phục nếu chỉ click nhầm.
        const prev = this.inpaintMaskBox;
        this._inpaintPrevBox = (prev && prev.w >= 0.02 && prev.h >= 0.02) ? { ...prev } : null;
        this._inpaintDrew = false;
        this._inpaintDrag = { key: 'draw', sx: e.clientX, sy: e.clientY, box: { x: p.nx, y: p.ny, w: 0, h: 0 }, handlers };
        this.inpaintMaskBox = { x: p.nx, y: p.ny, w: 0, h: 0 };
      } else {
        // 'move' hoặc 1 trong 'nw'/'ne'/'sw'/'se' — bám vào box hiện tại
        const b = { ...(this.inpaintMaskBox || { x: 0, y: 0, w: 0, h: 0 }) };
        this._inpaintDrag = { key, sx: e.clientX, sy: e.clientY, box: b, handlers };
      }
      window.addEventListener('pointermove', handlers.move);
      window.addEventListener('pointerup', handlers.up);
      window.addEventListener('pointercancel', handlers.up);
    },
    // Container overlay: bấm ngoài box → bắt đầu vẽ vùng mới. Box cũ được lưu lại
    // (_inpaintPrevBox); nếu chỉ click nhầm (không kéo) thì khôi phục — không mất vùng.
    inpaintMaskStart(e) {
      if (this.inpaintMaskMode === 'none') return;
      this.beginInpaintDrag(this.inpaintMaskMode === 'brush' ? 'brush' : 'draw', e);
    },
    // Reset về chế độ vẽ vùng mới (gọi từ nút "🔄 Vẽ lại" hoặc double-click trên box)
    resetInpaintMaskBox() {
      if (this._inpaintDrag) this._inpaintStopDrag();
      this.inpaintMaskBox = { x: 0, y: 0, w: 0, h: 0 };
      this._inpaintPrevBox = null;
      this._inpaintDrew = false;
      this.toast?.('Kéo chọn vùng mới trên ảnh.');
    },

    // Batch pointermoves qua rAF — kéo không giật, không đọc layout mỗi event.
    _inpaintQueue(e) {
      if (!this._inpaintDrag) return;
      this._inpaintPending = e;
      if (this._inpaintRaf) return;
      const flush = () => {
        this._inpaintRaf = null;
        const ev = this._inpaintPending; this._inpaintPending = null;
        if (ev && this._inpaintDrag) this._inpaintDragMove(ev);
      };
      if (typeof requestAnimationFrame === 'function') this._inpaintRaf = requestAnimationFrame(flush);
      else flush();
    },
    _inpaintDragMove(e) {
      const d = this._inpaintDrag; if (!d) return;
      const m = this.canvasMetrics(); if (!m) return;
      // Đánh dấu đã kéo thật (ngưỡng 4px) — phân biệt với click nhầm
      if (d.key === 'draw' && (Math.abs(e.clientX - d.sx) > 4 || Math.abs(e.clientY - d.sy) > 4)) {
        this._inpaintDrew = true;
      }
      if (d.key === 'brush') {
        const p = this.inpaintMaskPointer(e); if (!p) return;
        this._drawInpaintBrushLine(d.last || p, p);
        d.last = p;
        return;
      }
      const bx = (e.clientX - d.sx) / m.vw, by = (e.clientY - d.sy) / m.vh;
      const b = { ...(d.box || { x: 0.425, y: 0.425, w: 0.15, h: 0.15 }) };
      const MIN = 0.02;
      const cl = (v, lo, hi) => this._clamp(v, lo, Math.max(lo, hi));
      if (d.key === 'move') {
        b.x = this._clamp(b.x + bx, 0, 1 - b.w);
        b.y = this._clamp(b.y + by, 0, 1 - b.h);
      } else if (d.key === 'draw') {
        const ox = d.box.x, oy = d.box.y;
        const cx = this._clamp(ox + bx, 0, 1), cy = this._clamp(oy + by, 0, 1);
        b.x = Math.min(ox, cx); b.y = Math.min(oy, cy);
        b.w = Math.max(MIN, Math.abs(cx - ox));
        b.h = Math.max(MIN, Math.abs(cy - oy));
      } else {
        // Kéo handle: góc đối diện neo cố định
        const { x: x0, y: y0, w: w0, h: h0 } = d.box;
        const right = x0 + w0, bottom = y0 + h0;
        let x = x0, y = y0, w = w0, h = h0;
        if (d.key === 'se') { w = cl(w0 + bx, MIN, 1 - x0); h = cl(h0 + by, MIN, 1 - y0); }
        else if (d.key === 'sw') { w = cl(w0 - bx, MIN, right); x = right - w; h = cl(h0 + by, MIN, 1 - y0); }
        else if (d.key === 'ne') { w = cl(w0 + bx, MIN, 1 - x0); h = cl(h0 - by, MIN, bottom); y = bottom - h; }
        else if (d.key === 'nw') { w = cl(w0 - bx, MIN, right); x = right - w; h = cl(h0 - by, MIN, bottom); y = bottom - h; }
        b.x = x; b.y = y; b.w = w; b.h = h;
      }
      this.inpaintMaskBox = b;
    },
    // Kết thúc drag: gỡ window listeners, finalize brush, reset nếu vùng quá nhỏ.
    _inpaintStopDrag() {
      const d = this._inpaintDrag;
      this._inpaintDrag = null;
      this._inpaintHandle = null;
      this._inpaintPending = null;
      if (this._inpaintRaf != null) {
        if (typeof cancelAnimationFrame === 'function') cancelAnimationFrame(this._inpaintRaf);
        this._inpaintRaf = null;
      }
      if (d && d.handlers) {
        window.removeEventListener('pointermove', d.handlers.move);
        window.removeEventListener('pointerup', d.handlers.up);
        window.removeEventListener('pointercancel', d.handlers.up);
      }
      if (this._inpaintBrushDrawing) {
        this._inpaintBrushDrawing = false;
        this._inpaintBrushLast = null;
        this._finalizeInpaintBrush();
      } else if (this.inpaintMaskMode === 'rect') {
        const wasDraw = d && d.key === 'draw';
        const b = this.inpaintMaskBox;
        if (wasDraw && !this._inpaintDrew && this._inpaintPrevBox) {
          // Bấm ngoài box nhưng KHÔNG kéo (click nhầm) → khôi phục vùng cũ
          this.inpaintMaskBox = this._inpaintPrevBox;
        } else if (!b || b.w < 0.02 || b.h < 0.02) {
          // Vùng quá nhỏ và không có gì để khôi phục → reset trống
          this.inpaintMaskBox = { x: 0, y: 0, w: 0, h: 0 };
        }
      }
      this._inpaintPrevBox = null;
      this._inpaintDrew = false;
    },
    // Alias cho pointerup fallback nếu template vẫn gọi
    inpaintMaskStop() { this._inpaintStopDrag(); },
    _inpaintHitTest(p, b) {
      if (!b || b.w < 0.02 || b.h < 0.02) return null;
      // Margin bắt handle theo PIXEL thật trên màn hình (≈ 26px quanh góc) —
      // không phải % cố định → dễ bắt góc dù ảnh nhỏ hay zoom xa.
      const m = this.canvasMetrics();
      const M = m ? Math.max(0.025, 26 / m.vw) : 0.06;
      const corners = [['nw', b.x, b.y], ['ne', b.x + b.w, b.y], ['sw', b.x, b.y + b.h], ['se', b.x + b.w, b.y + b.h]];
      for (const [k, cx, cy] of corners) { if (Math.abs(p.nx - cx) <= M && Math.abs(p.ny - cy) <= M) return k; }
      if (p.nx >= b.x && p.nx <= b.x + b.w && p.ny >= b.y && p.ny <= b.y + b.h) return 'move';
      return null;
    },
    _initInpaintBrush() {
      // Canvas theo TỈ LỆ KHUNG ẢNH LAYER (frameLayout = baseW/baseH) — khớp hệ toạ độ nx/ny mà con trỏ
      // dùng để vẽ (tránh mask bị ép méo/tỷ lệ sai khi hiển thị hoặc tô màu). Vuông 512×512 chỉ là mặc định.
      const fl = this.frameLayout;
      let w = 512, h = 512;
      if (fl && fl.w && fl.h) {
        // Dùng ĐÚNG kích thước khung layer (baseW/baseH) — 1 px mask = 1 px khung vẽ → vùng chọn & tô màu khớp 100%.
        const cap = 1024;
        const s = Math.min(1, cap / fl.w, cap / fl.h);
        w = Math.max(1, Math.round(fl.w * s));
        h = Math.max(1, Math.round(fl.h * s));
      }
      const c = document.createElement('canvas');
      c.width = w; c.height = h;
      this._inpaintMaskCanvas = c;
      // willReadFrequently: _finalizeInpaintBrush đọc lại (getImageData) sau mỗi nét → tránh cảnh báo + nhanh hơn.
      this._inpaintMaskCtx = c.getContext('2d', { willReadFrequently: true });
      this._inpaintUndoStack = [];
    },
    // Ctrl+Z: khôi phục canvas về trước nét vẽ gần nhất, rồi snapshot lại mask data.
    undoInpaintBrush() {
      if (this.inpaintMaskMode !== 'brush') return;
      const c = this._inpaintMaskCtx; if (!c) return;
      const snap = this._inpaintUndoStack.pop();
      if (!snap) { this.toast('Không còn nét để hoàn tác.', 'info'); return; }
      const w = this._inpaintMaskCanvas.width, h = this._inpaintMaskCanvas.height;
      c.globalCompositeOperation = 'source-over';
      c.clearRect(0, 0, w, h);
      c.putImageData(snap, 0, 0);
      this._finalizeInpaintBrush();
      this.toast('Đã hoàn tác nét vẽ.');
    },
    // Gắn canvas DOM thật (overlay trên ảnh) làm nơi vẽ mask → nét vẽ HIỂN THỊ realtime.
    // Khi tắt brush (el = null) → gỡ tham chiếu để không vẽ vào element đã unmount.
    attachBrushCanvas(el) {
      if (!el) { this._inpaintMaskCanvas = null; this._inpaintMaskCtx = null; return; }
      const c = markRaw(el);
      const ctx = c.getContext('2d', { willReadFrequently: true });
      ctx.clearRect(0, 0, c.width, c.height);
      this._inpaintMaskCanvas = c;
      this._inpaintMaskCtx = ctx;
    },
    _inpaintBrushRadius() { const s = Math.max(2, Math.min(48, Number(this.inpaintBrushSize) || 10)); return this.inpaintErase ? s * 1.6 : s; },
    _inpaintBrushWidth() { return this._inpaintBrushRadius() * 2; },
    _drawInpaintBrushDot(p) {
      const c = this._inpaintMaskCtx; if (!c) return;
      const w = this._inpaintMaskCanvas.width, h = this._inpaintMaskCanvas.height;
      // Tẩy: destination-out xoá hẳn pixel (cả nét vẽ lẫn mask) — sửa khi vẽ lỡ.
      const erase = !!this.inpaintErase;
      c.globalCompositeOperation = erase ? 'destination-out' : 'source-over';
      c.fillStyle = erase ? 'rgba(0,0,0,1)' : 'rgba(220,38,38,0.6)'; // đỏ 60% — rõ mà không che ảnh
      c.beginPath(); c.arc(p.nx * w, p.ny * h, this._inpaintBrushRadius(), 0, Math.PI * 2); c.fill();
      c.globalCompositeOperation = 'source-over';
    },
    _drawInpaintBrushLine(from, to) {
      const c = this._inpaintMaskCtx; if (!c) return;
      const w = this._inpaintMaskCanvas.width, h = this._inpaintMaskCanvas.height;
      const erase = !!this.inpaintErase;
      c.globalCompositeOperation = erase ? 'destination-out' : 'source-over';
      c.strokeStyle = erase ? 'rgba(0,0,0,1)' : 'rgba(220,38,38,0.6)';
      c.lineWidth = this._inpaintBrushWidth(); c.lineCap = 'round'; c.lineJoin = 'round';
      c.beginPath(); c.moveTo(from.nx * w, from.ny * h); c.lineTo(to.nx * w, to.ny * h); c.stroke();
      c.globalCompositeOperation = 'source-over';
    },
    _finalizeInpaintBrush() {
      const el = this._inpaintMaskCanvas; if (!el || !this._inpaintMaskCtx) return;
      const w = el.width, h = el.height;
      const ctx = this._inpaintMaskCtx;
      const src = ctx.getImageData(0, 0, w, h);
      const mask = document.createElement('canvas'); mask.width = w; mask.height = h;
      const mctx = mask.getContext('2d', { willReadFrequently: true });
      const out = mctx.createImageData(w, h);
      for (let i = 0; i < w * h; i++) {
        // Grayscale mask chống răng cưa: nét đỏ 60% (alpha≈153) chuẩn hoá → đen(0=edit),
        // mép anti-aliased cho gray chuyển mềm, nền chưa vẽ → trắng(255=keep).
        const a = src.data[i * 4 + 3];
        const v = 255 - Math.min(255, Math.round(a * (255 / 153)));
        out.data[i * 4] = v; out.data[i * 4 + 1] = v; out.data[i * 4 + 2] = v; out.data[i * 4 + 3] = 255;
      }
      mctx.putImageData(out, 0, 0);
      this.inpaintBrushData = mask.toDataURL('image/png').replace(/^data:image\/png;base64,/, '');
      let minX = w, minY = h, maxX = 0, maxY = 0, found = false;
      for (let y = 0; y < h; y++) { for (let x = 0; x < w; x++) {
        if (src.data[(y * w + x) * 4 + 3] > 40) { found = true; if (x < minX) minX = x; if (x > maxX) maxX = x; if (y < minY) minY = y; if (y > maxY) maxY = y; }
      }}
      if (found && minX <= maxX && minY <= maxY) this.inpaintMaskBox = { x: minX / w, y: minY / h, w: (maxX - minX + 1) / w, h: (maxY - minY + 1) / h };
      // KHÔNG null canvas: giữ nét để vẽ TIẾP nhiều nét trên cùng mask (finalize chỉ snapshot
      // dữ liệu). Canvas bị xoá khi bật brush mới / thoát chế độ (attachBrushCanvas clearRect).
    },
    // ── Hành động vùng chọn (Xóa / Tô màu) cho rect + freehand ──
    _loadImageSrc(src) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => reject(new Error('Không tải được ảnh.'));
        img.src = src;
      });
    },
    // Canvas alpha (trắng=vùng chọn, trong suốt=ngoài) theo kích thước layer, có feather.
    async _buildSelectionAlpha(w, h, maskData = null) {
      const sel = document.createElement('canvas'); sel.width = w; sel.height = h;
      const sctx = sel.getContext('2d');
      const mode = this.inpaintMaskMode;
      const data = maskData ?? this.inpaintBrushData;
      if (mode === 'rect' && !data) {
        const b = this.inpaintMaskBox;
        if (!b || b.w < 0.02 || b.h < 0.02) throw new Error('Chưa có vùng chọn.');
        sctx.fillStyle = '#fff';
        sctx.fillRect(b.x * w, b.y * h, b.w * w, b.h * h);
      } else {
        // freehand/brush/path/magic → mask PNG (đen=vùng chọn); rect đã ĐẢO cũng dùng mask.
        // Dùng maskData ĐÃ CAPTURE (ổn định, không đổi giữa chừng).
        if (!data) throw new Error('Chưa vẽ vùng chọn.');
        const mimg = await this._loadImageSrc('data:image/png;base64,' + data);
        const tmp = document.createElement('canvas'); tmp.width = w; tmp.height = h;
        const tctx = tmp.getContext('2d');
        tctx.drawImage(mimg, 0, 0, w, h);
        const id = tctx.getImageData(0, 0, w, h);
        const d = id.data;
        for (let i = 0; i < d.length; i += 4) {
          // Cứng hoá mép (ngưỡng) để Xóa/Tô/Nhân đôi/Nâng che khuất TRỌN vùng chọn —
          // bỏ viền mờ do anti-alias (mép bán trong suốt làm màu tô lộ ảnh nền một ít).
          const sel = 255 - d[i];
          d[i] = 255; d[i + 1] = 255; d[i + 2] = 255; d[i + 3] = sel >= 128 ? 255 : 0;
        }
        sctx.putImageData(id, 0, 0);
      }
      const feather = Number(this.inpaintFeather) || 0;
      if (feather > 0) {
        const blurred = document.createElement('canvas'); blurred.width = w; blurred.height = h;
        const bctx = blurred.getContext('2d');
        bctx.filter = `blur(${feather}px)`;
        bctx.drawImage(sel, 0, 0);
        return blurred;
      }
      return sel;
    },
    async _applySelectionToLayer(action, color) {
      const l = this.activeLayer;
      if (!l || !l.image) { this.toast('Chọn 1 layer ảnh trước.', 'error'); return; }
      if (l.locked) { this.toast('Layer đang khóa — mở khóa trước khi xóa/tô vùng chọn.', 'error'); return; }
      const maskData = this.inpaintBrushData; // capture đồng bộ
      this.pushHistory();
      try {
        const img = await this._loadImageSrc(l.image);
        const w = img.naturalWidth, h = img.naturalHeight;
        const sel = await this._buildSelectionAlpha(w, h, maskData);
        const out = document.createElement('canvas'); out.width = w; out.height = h;
        const octx = out.getContext('2d');
        octx.drawImage(img, 0, 0);
        if (action === 'delete') {
          octx.globalCompositeOperation = 'destination-out';
          octx.drawImage(sel, 0, 0);
        } else {
          const colorCanvas = document.createElement('canvas'); colorCanvas.width = w; colorCanvas.height = h;
          const cctx = colorCanvas.getContext('2d');
          cctx.fillStyle = color || this.inpaintFillColor;
          cctx.fillRect(0, 0, w, h);
          cctx.globalCompositeOperation = 'destination-in';
          cctx.drawImage(sel, 0, 0);
          octx.drawImage(colorCanvas, 0, 0);
        }
        l.image = out.toDataURL('image/png');
        this.saveLayerLayout();
        this.toast(action === 'delete' ? 'Đã xóa nội dung vùng chọn.' : 'Đã tô màu vùng chọn.');
      } catch (e) {
        this.toast(e.message || 'Không áp dụng được.', 'error');
      }
    },
    deleteSelectedRegion() { this._applySelectionToLayer('delete'); },
    fillSelectedRegion() { this._applySelectionToLayer('fill'); },
    // Nhân đôi vùng chọn (rect/freehand) thành 1 layer mới chứa đúng phần được chọn.
    async duplicateSelectedRegion() {
      const src = this.activeLayer;
      if (!src || !src.image) { this.toast('Chọn 1 layer ảnh trước.', 'error'); return; }
      if (src.locked) { this.toast('Layer đang khóa — mở khóa trước khi nhân đôi vùng chọn.', 'error'); return; }
      const maskData = this.inpaintBrushData; // capture đồng bộ
      this.pushHistory();
      try {
        const img = await this._loadImageSrc(src.image);
        const w = img.naturalWidth, h = img.naturalHeight;
        const sel = await this._buildSelectionAlpha(w, h, maskData);
        const out = document.createElement('canvas'); out.width = w; out.height = h;
        const octx = out.getContext('2d');
        octx.drawImage(img, 0, 0);
        octx.globalCompositeOperation = 'destination-in';
        octx.drawImage(sel, 0, 0); // giữ đúng pixel trong vùng chọn, ngoài trong suốt
        const url = out.toDataURL('image/png');
        const id = 'dup-' + Date.now();
        // Nhân đôi NGAY tại vị trí vùng chọn: cùng transform với layer gốc (chồng khít, dễ kéo đi).
        this.canvasLayers.push({
          id, kind: 'source', name: 'Nhân đôi vùng chọn', image: url, genId: null,
          visible: true, locked: false,
          x: src.x || 0, y: src.y || 0, scale: src.scale || 1, rotation: src.rotation || 0,
          opacity: src.opacity != null ? src.opacity : 1, blend: src.blend || 'normal', flipX: false, flipY: false,
          baseW: src.baseW, baseH: src.baseH,
        });
        this.saveLayerLayout();
        this.setActiveLayer(id);
        this.toast('Đã nhân đôi vùng chọn tại vị trí.');
      } catch (e) {
        this.toast(e.message || 'Không nhân đôi được.', 'error');
      }
    },
    // Nâng (float/cut) vùng chọn: cắt nội dung khỏi layer gốc → đưa lên layer mới chồng khít để kéo đi.
    async floatSelectedRegion() {
      const src = this.activeLayer;
      if (!src || !src.image) { this.toast('Chọn 1 layer ảnh trước.', 'error'); return; }
      if (src.locked) { this.toast('Layer đang khóa — mở khóa trước khi nâng vùng chọn.', 'error'); return; }
      const maskData = this.inpaintBrushData; // capture đồng bộ
      this.pushHistory();
      try {
        const img = await this._loadImageSrc(src.image);
        const w = img.naturalWidth, h = img.naturalHeight;
        const sel = await this._buildSelectionAlpha(w, h, maskData);
        // 1) Layer mới chứa đúng phần chọn (floating)
        const fc = document.createElement('canvas'); fc.width = w; fc.height = h;
        const fctx = fc.getContext('2d');
        fctx.drawImage(img, 0, 0);
        fctx.globalCompositeOperation = 'destination-in';
        fctx.drawImage(sel, 0, 0);
        const floatUrl = fc.toDataURL('image/png');
        // 2) Cắt phần chọn khỏi layer gốc
        const cc = document.createElement('canvas'); cc.width = w; cc.height = h;
        const cctx = cc.getContext('2d');
        cctx.drawImage(img, 0, 0);
        cctx.globalCompositeOperation = 'destination-out';
        cctx.drawImage(sel, 0, 0);
        src.image = cc.toDataURL('image/png');
        // 3) Thêm layer floating trùng vị trí gốc
        const id = 'float-' + Date.now();
        this.canvasLayers.push({
          id, kind: 'source', name: 'Nâng vùng chọn', image: floatUrl, genId: null,
          visible: true, locked: false,
          x: src.x || 0, y: src.y || 0, scale: src.scale || 1, rotation: src.rotation || 0,
          opacity: src.opacity != null ? src.opacity : 1, blend: src.blend || 'normal', flipX: false, flipY: false,
          baseW: src.baseW, baseH: src.baseH,
        });
        this.saveLayerLayout();
        this.setActiveLayer(id);
        this.toast('Đã nâng vùng chọn thành layer mới — kéo để di chuyển.');
      } catch (e) {
        this.toast(e.message || 'Không nâng được.', 'error');
      }
    },
    // ── Undo / Redo (lịch sử layer) ──
    _snapshot() { return { layers: this.canvasLayers.map((l) => ({ ...l })), activeLayerId: this.activeLayerId }; },
    pushHistory() {
      this.undoStack.push(this._snapshot());
      if (this.undoStack.length > 50) this.undoStack.shift();
      this.redoStack = [];
    },
    _restoreSnapshot(snap) {
      this.canvasLayers = snap.layers.map((l) => ({ ...l }));
      this.activeLayerId = snap.activeLayerId;
      const l = this.activeLayer;
      if (l) {
        if (l.kind === 'source') { this.editSource = { url: l.image, name: l.name }; this.previewId = null; this.preview = null; }
        else if (l.genId) {
          const g = this.generations.find((x) => x.id === l.genId);
          if (g) { this.previewId = g.id; this.preview = { id: g.id, media_url: g.media_url, type: g.type || 'image', status: g.status || 'completed' }; }
          this.editSource = null;
        } else { this.editSource = null; this.previewId = null; this.preview = null; }
      } else { this.editSource = null; this.previewId = null; this.preview = null; }
      this.saveLayerLayout();
    },
    undo() {
      const snap = this.undoStack.pop();
      if (!snap) { this.toast('Không còn thao tác để hoàn tác.', 'info'); return; }
      this.redoStack.push(this._snapshot());
      this._restoreSnapshot(snap);
      this.toast('Đã hoàn tác.');
    },
    redo() {
      const snap = this.redoStack.pop();
      if (!snap) { this.toast('Không còn thao tác để làm lại.', 'info'); return; }
      this.undoStack.push(this._snapshot());
      this._restoreSnapshot(snap);
      this.toast('Đã làm lại.');
    },
  },
});
