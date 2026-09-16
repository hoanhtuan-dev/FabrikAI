<?php

use App\Http\Controllers\AuthController;
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
| - API JSON: /api/* (auth+admin), public read/image ở /api/*.
| - Auth: /dang-nhap, /dang-ky, /dang-xuat.
*/

// ── Auth ──
Route::get('/dang-nhap', [AuthController::class, 'showLogin'])->name('login');
// throttle:login — chống brute-force (5 lần/phút theo email+IP; xem AppServiceProvider).
Route::post('/dang-nhap', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
Route::get('/dang-ky', [AuthController::class, 'showRegister'])->name('register');
// throttle:register — chống spam tạo tài khoản (5 lần/phút theo IP).
Route::post('/dang-ky', [AuthController::class, 'register'])->middleware('throttle:register')->name('register.store');
Route::post('/dang-xuat', [AuthController::class, 'logout'])->name('logout');

// ── SPA pages (Blade shells) ──
Route::get('/', [StudioController::class, 'appIndex'])->name('home');
Route::get('/settings', [StudioController::class, 'settingsPage'])->name('settings.page');
Route::get('/presets', [StudioController::class, 'presetsPage'])->name('presets.page');
Route::get('/stylist-data', [StudioController::class, 'stylistDataPage'])->name('stylist-data.page');

// ── FabrikAI API (auth + admin + no-store) ──
Route::middleware(['auth', 'admin', 'nostore'])->prefix('api')->name('api.')->group(function () {
    // Project workflow (Designer design board) — CRUD + transitions + attach generations.
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects/new', [ProjectController::class, 'store'])->name('projects.create');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/transition', [ProjectController::class, 'transition'])->name('projects.transition');
    Route::post('/projects/{project}/generations', [ProjectController::class, 'attachGeneration'])->name('projects.attach');
    // Models (legacy CRUD)
    Route::get('/models', [StudioController::class, 'models'])->name('models');
    Route::post('/models', [StudioController::class, 'storeModel'])->name('models.store');
    Route::put('/models/{model}', [StudioController::class, 'updateModel'])->name('models.update');
    Route::get('/models/{model}/test', [StudioController::class, 'testModel'])->name('models.test');
    Route::delete('/models/{model}', [StudioController::class, 'deleteModel'])->name('models.delete');
    // API keys (legacy CRUD)
    Route::post('/keys', [StudioController::class, 'storeApiKey'])->name('keys.store');
    Route::put('/keys/{key}', [StudioController::class, 'updateApiKey'])->name('keys.update');
    Route::delete('/keys/{key}', [StudioController::class, 'deleteApiKey'])->name('keys.delete');
    // Face / pose presets (swap)
    Route::post('/face-presets', [StudioController::class, 'facePresetStore'])->name('face-presets.store');
    Route::put('/face-presets/{preset}', [StudioController::class, 'facePresetUpdate'])->name('face-presets.update');
    Route::delete('/face-presets/{preset}', [StudioController::class, 'facePresetDestroy'])->name('face-presets.destroy');
    Route::post('/pose-presets', [StudioController::class, 'posePresetStore'])->name('pose-presets.store');
    Route::put('/pose-presets/{preset}', [StudioController::class, 'posePresetUpdate'])->name('pose-presets.update');
    Route::delete('/pose-presets/{preset}', [StudioController::class, 'posePresetDestroy'])->name('pose-presets.destroy');
    // Generation pipelines
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
    Route::get('/assets', [StudioController::class, 'assetIndex'])->name('assets');
    Route::post('/assets', [StudioController::class, 'assetStore'])->name('assets.store');
    Route::delete('/assets/{asset}', [StudioController::class, 'assetDestroy'])->name('assets.destroy');
    Route::post('/generations/{generation}/inpaint', [StudioController::class, 'inpaint'])->name('inpaint');
    Route::post('/inpaint', [StudioController::class, 'inpaintSource'])->name('inpaint.source');
    Route::post('/reimagine', [StudioController::class, 'reimagine'])->name('reimagine');
    Route::post('/refgen', [StudioController::class, 'refgen'])->name('refgen');
    Route::post('/compose', [StudioController::class, 'compose'])->name('compose');
    Route::post('/compose/preview', [StudioController::class, 'composePreview'])->name('compose.preview');
    Route::get('/outfit-settings', [StudioController::class, 'outfitSettings'])->name('outfit-settings');
    Route::post('/outfit-settings', [StudioController::class, 'saveOutfitSettings'])->name('outfit-settings.save');
    Route::post('/remove-bg', [StudioController::class, 'removeBackground'])->name('remove-bg');
    Route::post('/generations/{generation}/region', [StudioController::class, 'regionEdit'])->name('region');
    Route::post('/process', [StudioController::class, 'processQueue'])->name('process');
    // Library (generated assets) + uploads
    Route::get('/library/data', [StudioController::class, 'libraryData'])->name('library.data');
    Route::post('/library/scan', [StudioController::class, 'libraryScan'])->name('library.scan');
    Route::post('/library/bulk-delete', [StudioController::class, 'libraryBulkDelete'])->name('library.bulk-delete');
    Route::post('/library/cleanup', [StudioController::class, 'libraryCleanup'])->name('library.cleanup');
    Route::get('/uploads', [StudioController::class, 'uploadedFiles'])->name('uploads');
    Route::post('/uploads/delete', [StudioController::class, 'uploadedFilesDelete'])->name('uploads.delete');
    Route::post('/uploads/cleanup', [StudioController::class, 'uploadedFilesCleanup'])->name('uploads.cleanup');
    // Prompt suggestion library ("Gợi ý từ ảnh")
    Route::get('/suggest-library/data', [StudioController::class, 'suggestLibraryData'])->name('suggest-library.data');
    Route::post('/suggest-library/save', [StudioController::class, 'suggestLibrarySave'])->name('suggest-library.save');
    Route::post('/suggest-library/apply/{id}', [StudioController::class, 'suggestLibraryApply'])->name('suggest-library.apply');
    Route::post('/suggest-library/bulk-delete', [StudioController::class, 'suggestLibraryBulkDelete'])->name('suggest-library.bulk-delete');
    Route::post('/suggest-library', [StudioController::class, 'suggestLibraryStore'])->name('suggest-library.store');
    Route::put('/suggest-library/{id}', [StudioController::class, 'suggestLibraryUpdate'])->name('suggest-library.update');
    Route::delete('/suggest-library/{id}', [StudioController::class, 'suggestLibraryDestroy'])->name('suggest-library.destroy');
    // Generations
    Route::get('/generations/{generation}/download', [StudioController::class, 'download'])->name('generations.download');
    Route::get('/generations/{generation}/palette', [StudioController::class, 'palette'])->name('generations.palette');
    Route::get('/generations/{generation}', [StudioController::class, 'show'])->name('generations.show');
    Route::post('/generations/{generation}/cancel', [StudioController::class, 'cancel'])->name('generations.cancel');
    Route::delete('/generations/{generation}', [StudioController::class, 'destroy'])->name('generations.destroy');
    Route::post('/generations/{generation}/rename', [StudioController::class, 'renameGeneration'])->name('generations.rename');
    // References / presets / defaults
    Route::get('/latest', [StudioController::class, 'latest'])->name('latest');
    Route::get('/presets', [StudioController::class, 'presets'])->name('presets');
    Route::post('/presets', [StudioController::class, 'storePreset'])->name('presets.store');
    Route::put('/presets/{preset}', [StudioController::class, 'updatePreset'])->name('presets.update');
    Route::delete('/presets/{preset}', [StudioController::class, 'destroyPreset'])->name('presets.destroy');
    // Prompt helpers
    Route::post('/suggest', [StudioController::class, 'suggest'])->name('suggest');
    Route::post('/upload-ref', [StudioController::class, 'uploadRef'])->name('uploadRef');
    Route::get('/ref-images', [StudioController::class, 'refImages'])->name('ref-images');
    Route::delete('/ref-images/{name}', [StudioController::class, 'refImageDelete'])->name('ref-images.delete');
    Route::post('/translate', [StudioController::class, 'translate'])->name('translate');
    Route::get('/defaults', [StudioController::class, 'defaults'])->name('defaults');
    Route::get('/prompt-history', [StudioController::class, 'promptHistory'])->name('prompt-history');
    Route::post('/preview-enrich', [StudioController::class, 'previewEnrich'])->name('preview-enrich');
    // Settings SPA (Vue) — JSON API
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
    // Legacy settings JSON endpoints (giữ để không vỡ tham chiếu cũ)
    Route::get('/settings/data', [StudioController::class, 'settingsData'])->name('settings.data');
    Route::post('/settings/save', [StudioController::class, 'settingsSave'])->name('settings.save');
    Route::post('/settings/sync-prompt', [StudioController::class, 'syncPromptSettings'])->name('settings.sync-prompt');
    Route::post('/settings', [StudioController::class, 'updateSettings'])->name('settings.update');
    Route::post('/settings/models', [StudioController::class, 'updateModelSettings'])->name('settings.models');
    Route::post('/settings/suggest', [StudioController::class, 'updateSuggestSettings'])->name('settings.suggest');
    Route::post('/settings/faceswap', [StudioController::class, 'saveFaceswapPrompt'])->name('settings.faceswap');
    // Stylist data (Trợ lý thiết kế) — CRUD (admin)
    Route::post('/stylist-data/types', [StylistDataController::class, 'saveType'])->name('stylist.data.types.save');
    Route::delete('/stylist-data/types/{id}', [StylistDataController::class, 'deleteType'])->name('stylist.data.types.delete');
    Route::post('/stylist-data/questions', [StylistDataController::class, 'saveQuestion'])->name('stylist.data.questions.save');
    Route::delete('/stylist-data/questions/{id}', [StylistDataController::class, 'deleteQuestion'])->name('stylist.data.questions.delete');
    Route::delete('/stylist/presets/{id}', [StylistDataController::class, 'deletePreset'])->name('stylist.presets.delete');
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
});

// Public garment avatar + image serving (bypass auth, immutable cache)
Route::get('/api/garment/{id}', [StudioController::class, 'garmentAvatar'])->name('garment.avatar');
Route::get('/api/garment/{id}/thumb', [StudioController::class, 'garmentThumb'])->name('garment.thumb');
Route::get('/api/image/{path}', [StudioController::class, 'studioImage'])->where('path', '.*')->name('studio.image');
Route::get('/api/image-thumb/{path}', [StudioController::class, 'studioImageThumb'])->where('path', '.*')->name('studio.image.thumb');
