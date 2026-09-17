<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\StudioSettingsController;
use App\Http\Controllers\StylistDataController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FabrikAI — AI fashion design studio (fabrikai.shop).
|--------------------------------------------------------------------------
| - SPA Vue: / (StudioApp), /settings, /presets, /stylist-data.
| - API JSON: /api/*  → TÁCH 2 NHÓM (2026-09-17, kế hoạch Đợt 0.1):
|     · STUDIO  (auth + can-studio) : người dùng thường (admin VÀ customer) tạo/sửa ảnh,
|                                     dự án, thư viện, cài đặt CỦA CHÍNH MÌNH.
|     · ADMIN   (auth + admin)      : cấu hình & quản trị TOÀN CỤC (API key, model registry,
|                                     preset dùng chung, catalog face/pose, dọn dẹp toàn cục).
| - Auth: /dang-nhap, /dang-ky, /dang-xuat.
|
| ⚠️ NGUYÊN TẮC PHÂN LOẠI (đọc trước khi thêm route mới):
|   Một bảng/model KHÔNG có cột `user_id` nghĩa là dữ liệu TOÀN CỤC dùng chung cho mọi người
|   ⇒ route ghi/xoá nó PHẢI nằm ở nhóm ADMIN. Hiện các bảng toàn cục là:
|   `studio_assets` · `presets` · `face_presets` · `pose_presets` · `stylist_presets`
|   · `studio_models` · `studio_providers` · `studio_api_keys` · `settings`.
|   Thư mục `storage/app/public/studio/ref` hiện cũng là kho CHUNG, không phân theo user
|   ⇒ các endpoint liệt kê/xoá nó (`/uploads`, `/ref-images`) tạm giữ ở ADMIN.
|   ⬅ VIỆC CÒN LẠI ĐÃ GHI SỔ: kế hoạch `STUDIO_REVIEW_PLAN.md` mục 0.1b — scope ảnh tham chiếu
|   tải lên theo từng user rồi mới chuyển 4 endpoint đó sang nhóm STUDIO.
*/

// ── Auth ──
Route::get('/dang-nhap', [AuthController::class, 'showLogin'])->name('login');
// throttle:login — chống brute-force (5 lần/phút theo email+IP; xem AppServiceProvider).
Route::post('/dang-nhap', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
Route::get('/dang-ky', [AuthController::class, 'showRegister'])->name('register');

// ── Trang GIÁ công khai (không cần đăng nhập) ──
// [Đợt 1 — 2026-09-19] Trước đây khách chưa đăng nhập KHÔNG có cách nào xem gói cước (không có view
// pricing, trang đăng ký không nhắc gói nào) ⇒ phải tạo tài khoản mới biết. Trang render phía máy
// chủ; giá/credit đọc từ bảng plans đang mở bán nên không bao giờ lệch với hệ thống.
Route::get('/bang-gia', [BillingController::class, 'pricingPage'])->name('pricing.page');
// throttle:register — chống spam tạo tài khoản (5 lần/phút theo IP).
Route::post('/dang-ky', [AuthController::class, 'register'])->middleware('throttle:register')->name('register.store');
Route::post('/dang-xuat', [AuthController::class, 'logout'])->name('logout');

// ── SPA pages (Blade shells) ──
Route::get('/', [StudioController::class, 'appIndex'])->name('home');
// [Quyết định 2026-09-17] /settings là CÀI ĐẶT TOÀN CỤC (API key · model registry) ⇒ cấp OWNER.
// Trước đây shell này công khai: khách tải được vỏ trang cấu hình (API của nó vốn đã ở nhóm ADMIN).
Route::middleware(['auth', 'admin', 'nostore'])->get('/settings', [StudioController::class, 'settingsPage'])->name('settings.page');

// [Quyết định 2026-09-17] /presets + /stylist-data là TÙY CHỈNH CẤP USER, lưu CỤC BỘ trên máy khách:
// mỗi người có bản riêng (localStorage), bảng toàn cục chỉ còn là GIÁ TRỊ MẶC ĐỊNH. Vì vậy shell
// mở cho MỌI tài khoản studio (auth + can-studio), không phải chỉ admin.
Route::middleware(['auth', 'can-studio', 'nostore'])->group(function () {
    Route::get('/presets', [StudioController::class, 'presetsPage'])->name('presets.page');
    Route::get('/stylist-data', [StudioController::class, 'stylistDataPage'])->name('stylist-data.page');
    // [Yêu cầu 2026-09-17] Cài đặt KHUÔN MẶT (model) + DÁNG POSE (người mẫu) — cấp USER, lưu cục bộ.
    Route::get('/model-settings', [StudioController::class, 'modelSettingsPage'])->name('model-settings.page');
});
// [Xác minh 2026-09-17] /admin là CONSOLE OWNER — KHÔNG được để chung nhóm shell công khai.
// Trước đây ai cũng tải được vỏ quản trị (khách 200, customer 200), trái mô hình ở đầu file
// ("ADMIN (auth + admin): cấu hình & quản trị TOÀN CỤC"); AdminApp.vue cũng không tự chặn.
// Nay: khách ⇒ về /dang-nhap · customer ⇒ 403 · admin ⇒ 200.
Route::middleware(['auth', 'admin', 'nostore'])->get('/admin', [AdminController::class, 'adminPage'])->name('admin.page');

// ══════════════════════════════════════════════════════════════════════════════
// NHÓM STUDIO — dùng được với MỌI tài khoản đã kích hoạt (admin + customer).
// Chỉ chứa thao tác trên dữ liệu THUỘC VỀ CHÍNH người dùng đó.
// ══════════════════════════════════════════════════════════════════════════════
Route::middleware(['auth', 'can-studio', 'nostore'])->prefix('api')->name('api.')->group(function () {
    // ── Project workflow (bảng thiết kế của designer) — owner-scoped ──
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects/new', [ProjectController::class, 'store'])->name('projects.create');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/transition', [ProjectController::class, 'transition'])->name('projects.transition');
    Route::post('/projects/{project}/generations', [ProjectController::class, 'attachGeneration'])->name('projects.attach');

    // ── Generation pipelines (mọi thứ tạo ra ảnh/video của chính user) ──
    Route::post('/generate', [StudioController::class, 'generate'])->name('generate');
    Route::post('/video', [StudioController::class, 'renderVideo'])->name('video');
    Route::get('/swap-models', [StudioController::class, 'swapCatalog'])->defaults('kind', 'models')->name('swap-models');
    Route::get('/swap-poses', [StudioController::class, 'swapCatalog'])->defaults('kind', 'poses')->name('swap-poses');
    Route::get('/swap-backgrounds', [StudioController::class, 'swapBackgrounds'])->name('swap-backgrounds');
    // (T15) Đã xóa Route::get('/studiosample/{file}') — trỏ tới StudioController::assetSample()
    // KHÔNG tồn tại (reflection = MISSING) nên mọi request vào URL này ném BadMethodCallException 500.
    // Không có call-site nào tham chiếu route name 'studio.asset' hay URL /studiosample.
    Route::post('/stylist', [StudioController::class, 'stylist'])->name('stylist');
    Route::post('/stylist/refine', [StudioController::class, 'stylistRefine'])->name('stylist.refine');
    Route::post('/upscale', [StudioController::class, 'upscale'])->name('upscale');
    Route::post('/look', [StudioController::class, 'look'])->name('look');
    Route::post('/reframe', [StudioController::class, 'reframe'])->name('reframe');
    Route::post('/generations/{generation}/inpaint', [StudioController::class, 'inpaint'])->name('inpaint');
    Route::post('/inpaint', [StudioController::class, 'inpaintSource'])->name('inpaint.source');
    Route::post('/reimagine', [StudioController::class, 'reimagine'])->name('reimagine');
    Route::post('/refgen', [StudioController::class, 'refgen'])->name('refgen');
    Route::post('/compose', [StudioController::class, 'compose'])->name('compose');
    Route::post('/compose/preview', [StudioController::class, 'composePreview'])->name('compose.preview');
    Route::post('/remove-bg', [StudioController::class, 'removeBackground'])->name('remove-bg');
    Route::post('/generations/{generation}/region', [StudioController::class, 'regionEdit'])->name('region');
    Route::post('/process', [StudioController::class, 'processQueue'])->name('process');

    // ── Trạng thái GÓI của chính người dùng (gói · hạn mức · chi phí · danh mục gói) ──
    // [Đợt 1 — 2026-09-19] Một chỗ để SPA nói đúng "bạn ở gói nào, còn bao nhiêu credit, được
    // tối đa độ phân giải nào" và mở bảng nâng cấp ngay trong Studio. Trước đây /api/boot không
    // trả gói và không có UI gói cước nào (grep 'billing' trong resources/js = 0).
    Route::get('/plan/status', [StudioController::class, 'planStatus'])->name('plan.status');

    // ── Cài đặt Ghép Trang Phục — theo TỪNG user (`studio_outfit_settings.user_id`) ──
    Route::get('/outfit-settings', [StudioController::class, 'outfitSettings'])->name('outfit-settings');
    Route::post('/outfit-settings', [StudioController::class, 'saveOutfitSettings'])->name('outfit-settings.save');

    // ── Library (ảnh ĐÃ TẠO của user) + upload ── mọi method đều nhận `auth()->user()`
    Route::get('/library/data', [StudioController::class, 'libraryData'])->name('library.data');
    Route::post('/library/scan', [StudioController::class, 'libraryScan'])->name('library.scan');
    Route::post('/library/bulk-delete', [StudioController::class, 'libraryBulkDelete'])->name('library.bulk-delete');
    Route::post('/library/cleanup', [StudioController::class, 'libraryCleanup'])->name('library.cleanup');

    // ── [Đợt 0.1b] Ảnh nguồn + file tải lên: MỖI NGƯỜI QUẢN LÝ PHẦN CỦA MÌNH ──
    // Ảnh mới lưu vào `studio/ref/u<id>/`; người dùng chỉ thấy/xoá phần của mình, owner
    // (admin) thấy và xoá được TẤT CẢ. Kho phẳng `studio/ref/` cũ vẫn đọc được để không
    // làm mất ảnh người dùng đã chèn vào dự án trước khi tách theo user.
    Route::get('/uploads', [StudioController::class, 'uploadedFiles'])->name('uploads');
    Route::post('/uploads/delete', [StudioController::class, 'uploadedFilesDelete'])->name('uploads.delete');
    Route::get('/ref-images', [StudioController::class, 'refImages'])->name('ref-images');
    Route::delete('/ref-images/{name}', [StudioController::class, 'refImageDelete'])->name('ref-images.delete');

    // ── [Yêu cầu 2026-09-17] KHUÔN MẶT (model) + DÁNG POSE (người mẫu) — cấp USER ──
    // `studio_assets` nay có `user_id`: user thêm mặt/dáng CỦA MÌNH (chỉ họ thấy; owner thấy tất cả);
    // hàng có user_id NULL là catalog DÙNG CHUNG có từ trước. Lưu ở SERVER (không phải localStorage)
    // vì lúc tạo ảnh studio gửi lên id và backend phải tra ra ảnh tham chiếu.
    Route::get('/assets', [StudioController::class, 'assetIndex'])->name('assets');
    Route::post('/assets', [StudioController::class, 'assetStore'])->name('assets.store');
    Route::delete('/assets/{asset}', [StudioController::class, 'assetDestroy'])->name('assets.destroy');
    Route::post('/upload-ref', [StudioController::class, 'uploadRef'])->name('uploadRef');

    // ── Thư viện Prompt phân tích ("Gợi ý từ ảnh") — bảng `suggest_results` có user_id ──
    Route::get('/suggest-library/data', [StudioController::class, 'suggestLibraryData'])->name('suggest-library.data');
    Route::post('/suggest-library/save', [StudioController::class, 'suggestLibrarySave'])->name('suggest-library.save');
    Route::post('/suggest-library/apply/{id}', [StudioController::class, 'suggestLibraryApply'])->name('suggest-library.apply');
    Route::post('/suggest-library/bulk-delete', [StudioController::class, 'suggestLibraryBulkDelete'])->name('suggest-library.bulk-delete');
    Route::post('/suggest-library', [StudioController::class, 'suggestLibraryStore'])->name('suggest-library.store');
    Route::put('/suggest-library/{id}', [StudioController::class, 'suggestLibraryUpdate'])->name('suggest-library.update');
    Route::delete('/suggest-library/{id}', [StudioController::class, 'suggestLibraryDestroy'])->name('suggest-library.destroy');

    // ── Generations — tất cả đều `abort_unless(owner, 403)` (đã kiểm ở vòng 8/26 test) ──
    Route::get('/generations/{generation}/download', [StudioController::class, 'download'])->name('generations.download');
    Route::get('/generations/{generation}/palette', [StudioController::class, 'palette'])->name('generations.palette');
    Route::get('/generations/{generation}', [StudioController::class, 'show'])->name('generations.show');
    Route::post('/generations/{generation}/cancel', [StudioController::class, 'cancel'])->name('generations.cancel');
    Route::delete('/generations/{generation}', [StudioController::class, 'destroy'])->name('generations.destroy');
    Route::post('/generations/{generation}/rename', [StudioController::class, 'renameGeneration'])->name('generations.rename');
    // [Đợt 1.1] vòng đời shot — chuyển trạng thái idea→…→approved theo whitelist (chỉ owner/admin).
    Route::post('/generations/{generation}/shot-state', [StudioController::class, 'transitionShot'])->name('generations.shot-state');

    // ── Ảnh mới nhất của CHÍNH user (`auth()->user()->generations()`) ──
    Route::get('/latest', [StudioController::class, 'latest'])->name('latest');

    // ── Đọc preset dùng chung (GET thôi — ghi/xoá ở nhóm ADMIN) ──
    // [Yêu cầu 2026-09-17] Cấu hình giao diện (thanh công cụ trái) — MỌI người dùng Studio đọc
    // được để render đúng; chỉ owner GHI được (nhóm ADMIN ở trên).
    Route::get('/gui', [StudioController::class, 'gui'])->name('gui');

    Route::get('/presets', [StudioController::class, 'presets'])->name('presets');

    // ── Prompt helpers ──
    Route::post('/suggest', [StudioController::class, 'suggest'])->name('suggest');
    Route::post('/translate', [StudioController::class, 'translate'])->name('translate');
    Route::get('/defaults', [StudioController::class, 'defaults'])->name('defaults');
    Route::get('/prompt-history', [StudioController::class, 'promptHistory'])->name('prompt-history');
    Route::post('/preview-enrich', [StudioController::class, 'previewEnrich'])->name('preview-enrich');

    // ── Gói đăng ký: người dùng TỰ đăng ký một gói (auth + can-studio) ──
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
});

// ══════════════════════════════════════════════════════════════════════════════
// NHÓM ADMIN — chỉ super_admin + admin. Mọi thứ chỉnh CẤU HÌNH TOÀN CỤC.
// ══════════════════════════════════════════════════════════════════════════════
Route::middleware(['auth', 'admin', 'nostore'])->prefix('api')->name('api.')->group(function () {
    // ── Model registry (legacy CRUD) — bảng toàn cục `studio_models` ──
    Route::get('/models', [StudioController::class, 'models'])->name('models');
    Route::post('/models', [StudioController::class, 'storeModel'])->name('models.store');
    Route::put('/models/{model}', [StudioController::class, 'updateModel'])->name('models.update');
    // testModel RELAY response của provider sống về trình duyệt → tuyệt đối không cho customer.
    Route::get('/models/{model}/test', [StudioController::class, 'testModel'])->name('models.test');
    Route::delete('/models/{model}', [StudioController::class, 'deleteModel'])->name('models.delete');

    // ── API keys (legacy CRUD) — bảng toàn cục `studio_api_keys` ──
    Route::post('/keys', [StudioController::class, 'storeApiKey'])->name('keys.store');
    Route::put('/keys/{key}', [StudioController::class, 'updateApiKey'])->name('keys.update');
    Route::delete('/keys/{key}', [StudioController::class, 'deleteApiKey'])->name('keys.delete');

    // ── Face / pose presets — TOÀN CỤC (không có user_id), dùng chung catalog model ──
    Route::post('/face-presets', [StudioController::class, 'facePresetStore'])->name('face-presets.store');
    Route::put('/face-presets/{preset}', [StudioController::class, 'facePresetUpdate'])->name('face-presets.update');
    Route::delete('/face-presets/{preset}', [StudioController::class, 'facePresetDestroy'])->name('face-presets.destroy');
    Route::post('/pose-presets', [StudioController::class, 'posePresetStore'])->name('pose-presets.store');
    Route::put('/pose-presets/{preset}', [StudioController::class, 'posePresetUpdate'])->name('pose-presets.update');
    Route::delete('/pose-presets/{preset}', [StudioController::class, 'posePresetDestroy'])->name('pose-presets.destroy');


    // ── DỌN file mồ côi: việc TOÀN CỤC ⇒ vẫn thuộc ADMIN ──
    // (endpoint liệt kê/xoá đã chuyển sang nhóm STUDIO ở mục 0.1b bên dưới.)
    Route::post('/uploads/cleanup', [StudioController::class, 'uploadedFilesCleanup'])->name('uploads.cleanup');

    // ── Preset dùng chung (`presets` TOÀN CỤC — ghi/xoá ảnh hưởng MỌI người dùng) ──
    Route::post('/presets', [StudioController::class, 'storePreset'])->name('presets.store');
    Route::put('/presets/{preset}', [StudioController::class, 'updatePreset'])->name('presets.update');
    Route::delete('/presets/{preset}', [StudioController::class, 'destroyPreset'])->name('presets.destroy');

    // ── Settings SPA (Vue) — JSON API: API key, provider, model registry, config ──
    Route::get('/settings-vue/data', [StudioSettingsController::class, 'data'])->name('settings-vue.data');
    Route::get('/settings-vue/models', [StudioSettingsController::class, 'models'])->name('settings-vue.models');
    Route::post('/settings-vue/keys', [StudioSettingsController::class, 'storeKey'])->name('settings-vue.keys.store');
    Route::put('/settings-vue/keys/{key}', [StudioSettingsController::class, 'updateKey'])->name('settings-vue.keys.update');
    Route::delete('/settings-vue/keys/{key}', [StudioSettingsController::class, 'deleteKey'])->name('settings-vue.keys.delete');
    Route::post('/settings-vue/keys/{key}/test', [StudioSettingsController::class, 'testKey'])->name('settings-vue.keys.test');
    Route::post('/settings-vue/providers', [StudioSettingsController::class, 'storeProvider'])->name('settings-vue.providers.store');
    Route::put('/settings-vue/providers/{provider}', [StudioSettingsController::class, 'updateProvider'])->name('settings-vue.providers.update');
    Route::delete('/settings-vue/providers/{provider}', [StudioSettingsController::class, 'deleteProvider'])->name('settings-vue.providers.delete');
    Route::post('/settings-vue/models', [StudioSettingsController::class, 'storeModel'])->name('settings-vue.models.store');
    Route::put('/settings-vue/models/{model}', [StudioSettingsController::class, 'updateModel'])->name('settings-vue.models.update');
    Route::delete('/settings-vue/models/{model}', [StudioSettingsController::class, 'deleteModel'])->name('settings-vue.models.delete');
    Route::post('/settings-vue/config', [StudioSettingsController::class, 'updateConfig'])->name('settings-vue.config');
    Route::post('/settings-vue/task-defaults', [StudioSettingsController::class, 'updateTaskDefault'])->name('settings-vue.task-defaults');
    // [2026-09-17] Luồng ưu tiên provider: qwen → custom → flux → gemini (đổi được thứ tự)
    // + đồng bộ catalog model QwenCloud mới nhất vào registry (cập nhật được về sau).
    Route::post('/settings-vue/provider-priority', [StudioSettingsController::class, 'providerPriority'])->name('settings-vue.provider-priority');
    Route::post('/settings-vue/sync-catalog', [StudioSettingsController::class, 'syncCatalog'])->name('settings-vue.sync-catalog');

    // ── Legacy settings JSON endpoints (giữ để không vỡ tham chiếu cũ) ──
    Route::get('/settings/data', [StudioController::class, 'settingsData'])->name('settings.data');
    Route::post('/settings/save', [StudioController::class, 'settingsSave'])->name('settings.save');
    Route::post('/settings/sync-prompt', [StudioController::class, 'syncPromptSettings'])->name('settings.sync-prompt');
    Route::post('/settings', [StudioController::class, 'updateSettings'])->name('settings.update');
    Route::post('/settings/models', [StudioController::class, 'updateModelSettings'])->name('settings.models');
    Route::post('/settings/suggest', [StudioController::class, 'updateSuggestSettings'])->name('settings.suggest');
    Route::post('/settings/faceswap', [StudioController::class, 'saveFaceswapPrompt'])->name('settings.faceswap');

    // ── Stylist data (Trợ lý thiết kế) — CRUD trên dữ liệu TOÀN CỤC ──
    Route::post('/stylist-data/types', [StylistDataController::class, 'saveType'])->name('stylist.data.types.save');
    Route::delete('/stylist-data/types/{id}', [StylistDataController::class, 'deleteType'])->name('stylist.data.types.delete');
    Route::post('/stylist-data/questions', [StylistDataController::class, 'saveQuestion'])->name('stylist.data.questions.save');
    Route::delete('/stylist-data/questions/{id}', [StylistDataController::class, 'deleteQuestion'])->name('stylist.data.questions.delete');
    Route::delete('/stylist/presets/{id}', [StylistDataController::class, 'deletePreset'])->name('stylist.presets.delete');
});

// ══════════════════════════════════════════════════════════════════════════════
// TRANG QUẢN TRỊ (/admin) — dành cho Owner. Dashboard/gói cước/sổ cái: admin + super_admin.
// Thao tác trên TÀI KHOẢN người dùng: chỉ super_admin (đúng UserPolicy).
// ══════════════════════════════════════════════════════════════════════════════
Route::middleware(['auth', 'admin', 'nostore'])->prefix('api/admin')->name('api.admin.')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/plans', [AdminController::class, 'plans'])->name('plans');
    Route::post('/plans', [AdminController::class, 'storePlan'])->name('plans.store');
    Route::put('/plans/{plan}', [AdminController::class, 'updatePlan'])->name('plans.update');
    Route::delete('/plans/{plan}', [AdminController::class, 'destroyPlan'])->name('plans.destroy');
    Route::get('/transactions', [AdminController::class, 'transactions'])->name('transactions');

    // ── [Yêu cầu 2026-09-17] GIAO DIỆN do OWNER quản lý (thanh công cụ trái của Studio) ──
    // Cấu hình TOÀN CỤC cho mọi người dùng Studio. Đặt ở đây (prefix api/admin) để KHÔNG trùng
    // với `GET /api/gui` mà Studio đọc — bản đầu tôi đặt nhầm vào nhóm prefix 'api' nên trùng route.
    Route::get('/gui', [AdminController::class, 'guiShow'])->name('gui.show');
    Route::put('/gui/activity-bar', [AdminController::class, 'guiActivityBarSave'])->name('gui.activity-bar.save');
    Route::post('/gui/activity-bar/reset', [AdminController::class, 'guiActivityBarReset'])->name('gui.activity-bar.reset');
});

Route::middleware(['auth', 'superadmin', 'nostore'])->prefix('api/admin')->name('api.admin.')->group(function () {
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
    Route::put('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
    Route::delete('/users/{user}', [AdminController::class, 'destroyUser'])->name('users.destroy');
    Route::post('/users/{user}/credits', [AdminController::class, 'adjustCredits'])->name('users.credits');
    Route::post('/users/{user}/reset-password', [AdminController::class, 'resetPassword'])->name('users.reset-password');
});

// ── Public FabrikAI API (read-only + images, no auth) ──
// N7: nhóm này có 2 POST (stylist/cluster, stylist/prompt) — /stylist/prompt chỉ ghi DB khi đã
// đăng nhập (xem StudioController::stylistPrompt), và throttle 60 req/phút/IP chặn lạm dụng.
Route::prefix('api')->name('api.')->middleware('throttle:60,1')->group(function () {
    Route::get('/boot', [StudioController::class, 'boot'])->name('boot');
    Route::get('/stylist/types', [StudioController::class, 'stylistTypes'])->name('stylist.types');
    Route::post('/stylist/cluster', [StudioController::class, 'stylistCluster'])->name('stylist.cluster');
    Route::post('/stylist/prompt', [StudioController::class, 'stylistPrompt'])->name('stylist.prompt');
    Route::get('/stylist-data/data', [StylistDataController::class, 'data'])->name('stylist.data.json');
    Route::get('/stylist/presets', [StylistDataController::class, 'presets'])->name('stylist.presets');
    // Danh mục gói đăng ký công khai (trang giá / đăng ký).
    Route::get('/billing/plans', [BillingController::class, 'catalog'])->name('billing.plans');
});

// Public garment avatar + image serving (bypass auth, immutable cache)
Route::get('/api/garment/{id}', [StudioController::class, 'garmentAvatar'])->name('garment.avatar');
Route::get('/api/garment/{id}/thumb', [StudioController::class, 'garmentThumb'])->name('garment.thumb');
Route::get('/api/image/{path}', [StudioController::class, 'studioImage'])->where('path', '.*')->name('studio.image');
Route::get('/api/image-thumb/{path}', [StudioController::class, 'studioImageThumb'])->where('path', '.*')->name('studio.image.thumb');
