# FabrikAI · Studio module — Code review audit (BẢN GỘP v3 · 2026-09-16)

**Target hiện hành:** `/home/anhtuan/DEV/FabrikAI` — app standalone (Laravel 13.26.1 · Vue 3.5.42 · PHP 8.3.6), trong `routes/web.php` ghi rõ **"tách từ TrillfaShop"**.
**Bản chất file:** bản gộp **v3**. v2 (2026-09-07) gắn với TrillfaShop; v3 viết lại sau khi module **được tách sang app mới** và sau **43 commit (2026-09-08 → 2026-09-11) chưa từng được ghi vào sổ**.
**Nguồn bằng chứng:** mọi số liệu dưới đây đo lại trên working tree FabrikAI ngày **2026-09-16** bằng read/grep/diff/`php artisan route:list`. Số dòng là số dòng **THẬT ở FabrikAI**, không phải số trong v2 (v2 có chỗ ghi sai — xem §0.5).
**BẢN CHUẨN (chốt 2026-09-17):** `/home/anhtuan/DEV/FabrikAI/STUDIO_REVIEW.md` là **bản duy nhất có thẩm quyền** — module studio đã rời TrillfaShop (`f35704f`) nên bản gương ở repo cũ **không còn được cập nhật tự động**. Muốn giữ gương thì phải copy tay và ghi rõ ngày; nếu lệch, **luôn tin bản ở FabrikAI**.

---

## §0 — HIỆN TRẠNG (2026-09-16)

### 0.1 Module đã TÁCH sang app mới — thay đổi lớn nhất kể từ v2

| Bằng chứng | Chi tiết |
|---|---|
| Code hiện hành | `/home/anhtuan/DEV/FabrikAI`: `app/Http/Controllers/StudioController.php` ~~4.820~~ **4.644 dòng** · `app/Services/*` ~~15~~ **11** service · `app/Support/helpers.php` ~~1.590~~ **1.551 dòng** *(đo lại 2026-09-17 @`eee1bba`; số cũ là số của 2026-09-16)* · `resources/js/studio/` ~~45~~ **13 file JS/Vue + 28 component** (**store.js ~~4.054~~ 3.987 dòng**) · `routes/web.php` ~~179~~ **175 dòng** |
| Repo cũ | `/home/anhtuan/DEV/TrillfaShop`: working tree đã **XÓA 176 file** module studio (+ 14 M, 23 ??) — **tất cả chưa stage**, `git diff --cached` rỗng |
| Việc tách KHÔNG được ghi vào git | `git log --all --oneline \| grep -icE 'fabrik'` = **0** *(đúng tại 2026-09-16)*. **✅ CẬP NHẬT 2026-09-17: nay = 4** — đã có commit ghi lại việc tách, quan trọng nhất là `f35704f refactor(studio): tách module studio sang app FabrikAI + dọn test của module đã đi` (xem `STUDIO_REVIEW_PROGRESS.md` §2bis, T6). Bằng chứng ý định nằm ở file chưa commit: `TrillfaShop/resources/js/admin/useStudioThumb.js:2` *"Studio đã tách thành app FabrikAI riêng nên không còn endpoint /studio/image-thumb/."* và `.env.example:6` `FABRIKAI_URL=http://localhost:5173` |
| Đối chiếu file | FabrikAI vs `TrillfaShop@HEAD`: **SAME 76 · DIFF 31 · chỉ-có-ở-cũ 7 · chỉ-có-ở-mới 7** |
| Framework | FabrikAI: Laravel **13.26.1** (composer `^13.17`) — TrillfaShop cũ: Laravel 11. `php artisan --version` chạy được (app boot OK) |
| App mới KHÔNG có version control | ✅ **ĐÃ ĐÓNG 2026-09-17 (T2)**: `.git` tồn tại, `git rev-list --count HEAD` = **47 commit**, remote `origin` (github.com/hoanhtuan-dev/FabrikAI, public). *Số liệu "No such file or directory" là của 2026-09-16, nay sai — xem §0.4 cùng file.* |

**7 file CHỈ CÓ Ở BẢN MỚI** (không tracked ở `TrillfaShop@HEAD`): ~~`PatternCard.vue` (105)~~ · ~~`TryOnCard.vue` (140)~~ *(cả hai **ĐÃ BỊ GỠ** @`eee1bba` cùng card "Thay người mẫu") · `resources/js/studio/PresetsApp.vue` (167) · `boot.js` (23) · `main.js` (25 → **18** sau khi tách `pageBoot.js`) · `presets.js` (3 → **6**) · `resources/views/studio/index.blade.php` (37 → **40** dòng).

**7 file CHỈ CÓ Ở BẢN CŨ** (bị bỏ khi tách): `resources/js/studio/app.js` · `resources/views/studio/api.blade.php` · `library.blade.php` · `pattern.blade.php` · `settings-vue.blade.php` · `tryon.blade.php` · `vue.blade.php`. Nghĩa là: **5 trang blade riêng gộp về 1 SPA shell `index.blade.php`**, và pattern/tryon chuyển từ trang riêng thành card trong SPA.

### 0.2 Đổi đường dẫn — mọi URL đổi tiền tố

| Cũ (TrillfaShop) | Mới (FabrikAI) | Bằng chứng |
|---|---|---|
| `/studio` (SPA) | `/` | `routes/web.php:27` `Route::get('/', [StudioController::class, 'appIndex'])` |
| `/studio/settings` · `/studio/presets` · `/studio/stylist-data` | `/settings` · `/presets` · `/stylist-data` | `routes/web.php:28-30` |
| `/studio/library` | `/` (view Thư viện nhúng trong SPA) | `routes/web.php:34` `Route::redirect('/studio/library', '/')` |
| `/studio/<api...>` (~106 route) | `/api/<api...>` | `routes/web.php:37` `Route::middleware(['auth','admin','nostore'])->prefix('api')->name('api.')` |
| `/studio/image/{path}` · `/studio/image-thumb/{path}` | `/api/image/{path}` · `/api/image-thumb/{path}` | `routes/web.php:178-179`; `helpers.php:425/439/444` đổi 3 dòng URL |
| `/garment/{id}` · `/garment/{id}/thumb` | `/api/garment/{id}` · `/api/garment/{id}/thumb` | `routes/web.php:176-177` |
| (mới) | `/api/boot` — payload boot cho SPA | `routes/web.php:167` · `StudioController::boot()` `StudioController.php:37` |

**Bản đồ route thực tế** (`php artisan route:list` = **136 route**): **112 route** trong nhóm `auth+admin+nostore` (dòng 37–163) · **11 route public** (auth 20–24, SPA shell 27–30, redirect legacy 33–34) · **6 route public API** (dòng 166–173: `/api/boot`, `/api/stylist/{types,cluster,prompt}`, `/api/stylist-data/data`, `/api/stylist/presets`) · **4 route ảnh public** (dòng 176–179).

**Không còn sót URL `/studio` chức năng nào**: `grep "fetch('/studio"` = 0 · `grep "route('studio"` = 0 · `grep 'href="/studio'` = 0. 16 kết quả grep `/studio` còn lại đều là **comment** hoặc **dương tính giả** (`@vite` path, tên file icon `/icons/studio-*.png`).

### 0.3 Những gì MẤT khi tách (rủi ro cao)

1. **Toàn bộ test suite biến mất.** FabrikAI **không có thư mục `tests/`**, nhưng `phpunit.xml:9,12` vẫn trỏ `tests/Unit` + `tests/Feature` → dangling. TrillfaShop HEAD có **19 file test / 199 test method**; working tree TrillfaShop chỉ còn `tests/Feature/ShopFlowTest.php` + `TestCase.php` (**42 test method**), 18 file test đã bị xóa và **không file nào được chuyển sang FabrikAI**. `ShopFlowTest` bị cắt từ **64 → 42** test method (xóa 608 dòng, thêm 0) — 22 test `test_studio_*` bị gỡ. → **Baseline "210 pass / 0 FAIL" của v2 KHÔNG tái lập được ở bất kỳ repo nào hiện tại** (`.phpunit.result.cache` chỉ là lịch sử tích lũy: 80 Failure + 35 Error + 11 Risky).
2. **PWA hỏng.** `manifest-studio.json` + `sw-studio.js` (scope `/studio`, thành quả 4 phiên AJ/AK/AL/AM) **đã bị xóa**. Thay bằng `public_html/manifest.json` (FabrikAI, scope `/`) + `public_html/sw.js` (cache `fabrikai-v1`) — **nhưng không nơi nào đăng ký SW**: `resources/js/studio/main.js:7-16` **chủ động unregister mọi SW + xóa mọi cache**; `resources/js/app.js:570-573` ghi *"PWA / Service Worker đã LOẠI BỎ … không còn nơi nào đăng ký lại /sw.js nữa"*. → `public_html/sw.js` là **code chết**, PWA không còn installable.
3. **Không có deploy/CI nào cho app mới.** `find FabrikAI -maxdepth 2 -name 'deploy*'` = 0; không `scripts/`, không `DEPLOY.md`, không `.github`; `grep -rn 'trillfa.shop' FabrikAI` (trừ vendor/node_modules) = **0**. Script duy nhất `TrillfaShop/scripts/deploy.sh` **hardcode** `APP_DIR="domains/trillfa.shop"` + `git pull --ff-only origin main` → **không dùng được** (FabrikAI không có git).
4. **Storefront trở thành dead code** nhưng vẫn nằm trong cây: `bootstrap/providers.php` **chỉ** đăng ký `AppServiceProvider` + `StudioModuleServiceProvider` → `StorefrontModuleServiceProvider` không bao giờ nạp. `route:list` = **0 route** thuộc Shop/Cart/Checkout/Product/Blog/Page/Wishlist/Account/Admin. Kéo theo: ~9 controller, `CartService`, `CheckoutService`, `StorefrontBridge` (837 dòng), ~10 helper storefront trong `helpers.php`, và entry `resources/js/app.js` (**574 dòng**, build ra nhưng `grep "@vite"` không view nào nạp) đều là **code chết**. `StorefrontController` còn trả `view('storefront.account')` trong khi `resources/views` **không còn thư mục `storefront`** → sẽ 500 nếu provider được nạp.

### 0.4 Số liệu hiện tại

> **ĐO LẠI LẦN CUỐI 2026-09-17 tại `eee1bba` (dùng bảng này; các số dưới là lịch sử).**

| Hạng mục | `@eee1bba` (chuẩn) | 2026-09-17 (bản cũ) | 2026-09-16 (gốc) |
|---|---|---|---|
| Test | **309 test / 1.629 assertion · 35 file** (chạy OK — +9 ở 66023d7) | 293 / 1.603 | 0 (chưa port) |
| Route | **127** (105 auth+admin · 9 public · 6 public API · 4 ảnh · 2 storage · 1 up) | 139 | 136 |
| PHP `app/` | **47 file / 13.777 dòng / 653.636 B** | 72 file / 17.319 dòng | 113 file / 21.298 dòng |
| `StudioController.php` | **4.644 dòng** (90 route dùng nó) | 4.820 | 4.820 |
| `helpers.php` · `store.js` | **1.551** · **3.987** | 1.590 · 4.054 | 1.590 · 4.054 |
| JS/Vue studio | **42 file (33 .vue) / 12.828 dòng** | 45 / 13.827 | 45 / 13.827 (36 Vue) |
| Models · Services · Migrations | **17 · 11 · 27** | 36 · 14 · 52 | 36 · 15 · 52 |
| Git | `main` `eee1bba`, đã push `origin` (47 commit) | `22477ef` | không có `.git` |
| Icon/favicon · `public_html` | **52 KB (2 file) · 15.086 B · 21 MB** | 1,1 MB · 16 KB · 22 MB | 9,6 MB · 1,5 MB · 32 MB |
| Blade | **12 file / 189 dòng** (studio: 4) | 12 file | 12 file / 186 dòng |
| `views/studio/index.blade.php` | **2.014 B / 40 dòng** | 37 dòng | 1.613 B |

### 0.4bis Số liệu gốc (2026-09-16)

| Hạng mục | Số đo |
|---|---|
| PHP | 113 file / **21.298 dòng** / 921 KB |
| JS/Vue | 45 file / **13.827 dòng** / 908 KB (36 file Vue) |
| Blade | 12 file / 186 dòng (studio: 4 file; `index.blade.php` là shell SPA thật) |
| Route | 136 (112 auth+admin+nostore · 24 public) |
| DB dev | `database/database.sqlite` 577 KB · 52 bảng · users 8 · projects 1 · generations **0** · studio_assets **0** |
| `public_html` | **32 MB** — bất thường: `samples/` 19 MB (50 `.jpg`) · `icons/` 9,6 MB (10 file, nhiều file **trùng lặp đúng 1.521.739 byte**) · `favicon.ico` **1,5 MB** · `build/` 1,4 MB (29 asset, build lúc 2026-09-13 22:09) |
| `.env` local | `APP_NAME="Trillfa Fa"` (**còn thương hiệu cũ**) · `APP_ENV=local` · `APP_DEBUG=true` · `DB_CONNECTION=sqlite` · `APP_URL=http://localhost:8000` |
| `storage/logs/laravel.log` | 1,7 MB / 10.658 dòng / **66 record ERROR** — nhưng **mang từ repo cũ sang** (6.220 dòng nhắc `/home/anhtuan/DEV/TrillfaShop` vs 79 dòng nhắc FabrikAI) → **không dùng làm bằng chứng sức khỏe của app mới** |

Lỗi lặp đáng chú ý trong log (phần lớn thuộc quá trình tách): 8× `Class "AppModulesStudio\ServiceProvider" not found` · 8× `Class "App\Http\Controllers\App\Models\StudioModel" not found` (**namespace bị phá khi chuyển code**) · 7× `signal "9"` (SIGKILL/OOM) · 6× `Undefined array key "url"` · 4+4× DashScope **401** (chưa cấu hình key ở local).

### 0.5 Đính chính số liệu v2

- v2 ghi `StudioController.php` **4.732 dòng** — không khớp cả hai: `TrillfaShop@HEAD` = **4.911**, FabrikAI = **4.820**.
- v2 ghi *"group public chỉ 2 endpoint read-only"* — **sai**: nhóm public ở bản cũ đã có **5** endpoint, trong đó **2 POST** (`/stylist/cluster`, `/stylist/prompt`) và **`/stylist/prompt` GHI DB** qua `StylistCatalog::savePreset()` (xem §5.1). Câu này lặp lại nguyên ở v3 nếu không đính chính.
- v2 ghi *"`index.blade.php` 140.236 B dead code đã xóa"* — ở FabrikAI `index.blade.php` **là shell SPA thật** (1.613 B, render bởi `StudioController.php:71`). Đừng nhầm lại.

---

## §1 — NHIỆM VỤ (bảng gốc + phán quyết tái xác minh)

> ✅ **TÁI XÁC MINH 2026-09-17 tại `eee1bba`: KHÔNG còn mục nào trong T1–T17 ở trạng thái MỞ thật.** 15 mục đã đóng (có bằng chứng trong code), T7 không còn áp dụng, T9 đóng đúng chủ đích ở phần `samples`, T3/T10 còn phần dở. **Chi tiết từng mục + 3 việc MỚI: `STUDIO_REVIEW_PROGRESS.md` §2 và §2ter.**
>
> ⚠️ **Cột "Trạng thái" trong bảng dưới là của 2026-09-16** (toàn bộ ghi "MỞ") — **đã lỗi thời**, giữ để đối chiếu lịch sử.

| # | Nhiệm vụ | Loại | Vì sao ưu tiên | Trạng thái |
|---|---|---|---|---|
| **T1** | **Khôi phục test suite**: `git checkout HEAD -- tests` ở TrillfaShop để lấy lại 18 file test, rồi **port các test studio sang FabrikAI** (đổi `/studio/*` → `/api/*`, `/studio` → `/`), đồng thời thêm `tests/` vào FabrikAI + sửa `phpunit.xml` | Test | Không còn lưới an toàn nào; 199 test method đang chỉ tồn tại trong git object của repo cũ | **MỞ — khẩn** |
| **T2** | **`git init` cho FabrikAI** + commit baseline + `.gitignore` (đã có `.gitignore` nhưng chưa có repo) | Vận hành | App đang chạy không có version control: không rollback, không diff, không lịch sử | **MỞ — khẩn** |
| **T3** | **Vá 4 rủi ro mới ở `VideoAIService`** (xem §6): `@file_get_contents` tải video không timeout/allowlist/cap (SSRF + treo worker + đầy disk) · `sleep(5)` tới **8 phút** trong request/job · stub trả `/samples/studio-catwalk.mp4` **không tồn tại** → generation "completed" nhưng 404 · rò body provider 240 ký tự ra `error` hiển thị cho user | Bảo mật/ổn định | File **chưa từng được review** (grep tên trong cả 2 báo cáo = 0) | **MỞ** |
| **T4** | **Vá `SuggestLibraryService::resolveLocalImage()`** (`SuggestLibraryService.php:261`) — bản sao resolver **KHÔNG có containment realpath**, chỉ `preg_replace('#^(storage/)+#')` rồi `Storage::disk('public')->path($rel)`; `../` vẫn đi qua. Đổi sang `studio_safe_public_file()` | Bảo mật | Lệch chuẩn so với S1 đã vá; file chưa từng được review | **MỞ** |
| **T5** | **Sửa PWA**: hoặc đăng ký lại SW + trỏ manifest, hoặc gỡ hẳn PWA cho khỏi nửa vời (`manifest.json` + `sw.js` hiện là code chết, `main.js` còn unregister SW) | Tính năng | Mất tính năng đã làm xong ở 4 phiên AJ/AK/AL/AM | **MỞ** |
| **T6** | **Commit việc tách ở TrillfaShop** — 213 mục chưa stage (176 D/14 M/23 ??); hiện không ai đọc được lịch sử là module đã chuyển đi đâu | Repo | Rủi ro mất/hiểu nhầm toàn bộ module | **MỞ** |
| **T7** | **Deploy production** (kế thừa T1 v2, nay đổi bản chất): production `trillfa.shop` vẫn chạy code TRƯỚC `234d405` (bằng chứng stack frame **#47290**). Nay còn thêm: **app mới chưa có deploy nào** → phải quyết định app nào là production | Vận hành | Blocker của mọi thứ khác | **MỞ — việc người dùng** |
| **T8** | **Dọn dead code**: storefront (~9 controller + 2 service + bridge + ~10 helper + `resources/js/app.js` 574 dòng + `/sw.js`) và `StudioController::redirectToFabrikai()` (dòng 60, **không route nào gọi**, còn `env()` trong code app) | Dọn dẹp | Giảm bề mặt tấn công + thời gian đọc code | **MỞ** |
| **T9** | **Phình asset**: `public_html` 32 MB — `samples/` 19 MB, `icons/` 9,6 MB với nhiều file **trùng lặp byte-for-byte**, `favicon.ico` 1,5 MB | Perf | Ảnh hưởng tải trang + dung lượng deploy | **MỞ** |
| **T10** | **Backlog medium/low** (§5) | Vá | ~18 medium + ~45 low/info | **MỞ — ưu tiên thấp** |
| **T11** | **Vá N1 (mức CAO)** — 2 site **bypass bản vá S3** trong `StudioController.php:3116` (`applySuperResolution`) và `:3154` (`applyFaceEnhance`): `// storeRemoteImage is protected — use a direct store via file_get_contents` → `@file_get_contents($url)` thô (không scheme check / timeout / cap 50 MiB / allowlist host). Đổi sang `Http` client như `ImageAIService::storeRemoteImage()` | Bảo mật | Lỗ hổng CÙNG LỚP S3 vẫn mở, đã được vá ở file khác nhưng bị bỏ sót ở 2 site này | **MỞ — cao** |
| **T12** | **Vá N6** — `project_id` ở suggest-library dùng `exists:projects,id` **không scope ownership** (`StudioController.php:1711`, `:1818`) → thêm `Rule::exists(...)->where('user_id', …)` như 4 site K.8 | Bảo mật | IDOR-lite, gắn bản ghi vào dự án user khác | **MỞ** |
| **T13** | **Siết N7** — `POST /api/stylist/prompt` **ghi DB không cần đăng nhập** (`routes/web.php:170` → `StudioController:2663` `savePreset()`); `/api/stylist-data/data` + `/api/stylist/presets` public còn chạy `Schema::create` qua `ensureTables()`. Cân nhắc: bỏ auto-save preset khỏi endpoint public, hoặc chuyển sang auth+admin + throttle | Bảo mật | Ghi dữ liệu/DDL không auth | **MỞ** |
| **T14** | **Sửa S2** — cap pixel (`helpers.php:257`) đang chạy **SAU** `imagecreatefromstring` (`:248`) nên **không** chặn được decompression bomb như v2 tuyên bố. Chuyển sang kiểm `getimagesizefromstring()` **trước** khi decode; thêm key `image_max_pixels` vào `config/studio.php` (hiện grep = 0, chỉ có default trong code) | Bảo mật/ổn định | Vá sai chỗ — cần sửa lại cho khớp tuyên bố | **MỞ** |
| **T15** | **Sửa N3** — `routes/web.php:71` `Route::get('/studiosample/{file}', [StudioController::class, 'assetSample'])` trỏ tới **method không tồn tại** (reflection: MISSING) → 500. Xóa route hoặc viết lại method | Lỗi | URL chết gây 500 | **MỞ** |
| **T16** | **Siết N5 + N8/N10** — `svg` nằm trong whitelist đuôi của endpoint ảnh **public** (`StudioController.php:3477`, phục vụ bằng `response()->file()`) → SVG render cùng origin; và rò exception/provider body qua cột `Generation.error` (`:1374`, `:1387` + `VideoAIService:85,112`) | Bảo mật | Defense-in-depth | **MỞ** |
| **T17** | **Vá N12** — `LayersPanel.vue:80` `navigator.clipboard.writeText(c)` **không await** → toast thành công giả khi bị chặn quyền. Sửa cùng lúc 2 site `GalleryModal.vue:175,182` | Vá nhỏ | Cùng họ bug đã biết | **MỞ** |

### Tiến độ thực hiện (cập nhật 2026-09-17)

**Repo:** `https://github.com/hoanhtuan-dev/FabrikAI` (public) · remote `origin` · nhánh `main` · baseline `362234b` (369 file, 51.921 dòng).
**Bảo mật:** repo public nên `.gitignore` đã chặn `.env` · `database/*.sqlite` · `storage/logs` · `vendor` · `node_modules` và cả `STUDIO_REVIEW*.md` (báo cáo chứa chi tiết lỗ hổng CHƯA vá — cùng quy ước với TrillfaShop).

| # | Việc | Trạng thái | Bằng chứng |
|---|---|---|---|
| T2 | `git init` + baseline + push | ✅ **XONG** | `362234b` — 369 file; audit staged: **không có** `.env`/`.sqlite`/`vendor`/`node_modules` |
| T11 | N1 — 2 site bypass SSRF | ✅ **XONG** | `62ce15c` — helper dùng chung `studio_fetch_remote_bytes()` (`helpers.php`); thay **4** site thô (ImageAIService::storeRemoteImage bỏ guard copy tại chỗ + StudioController applySuperResolution/applyFaceEnhance + VideoAIService cap 200 MiB). Verify: `file://` → null, URL chết → null, `grep file_get_contents($url` = 0 |
| T12 | N6 — `project_id` thiếu scope | ✅ **XONG** | `62ce15c` — 2 chỗ → `Rule::exists(...)->where('user_id', …)` |
| T13 | N7 — ghi DB ẩn danh | ✅ **XONG** | `62ce15c` — `stylistPrompt()` chỉ `savePreset()` khi `auth()->check()` + `throttle:60,1` cho nhóm public API |
| T14 | S2 — cap pixel đặt sai chỗ | ✅ **XONG** | `62ce15c` — `getimagesizefromstring()` kiểm **TRƯỚC** `imagecreatefromstring` (giữ check sau decode làm lớp 2); thêm key `config/studio.php` `image_max_pixels` + `remote_image_hosts`. Verify: ngưỡng 1 px loại được ảnh 8×8, ảnh hợp lệ vẫn qua |
| T15 | N3 — route trỏ method không tồn tại | ✅ **XONG** | `62ce15c` — xóa route `/studiosample/{file}` (0 tham chiếu; `route:list` 136 → 135 route) |
| T17 | N12 — clipboard không await | ✅ **XONG** | `62ce15c` — 3 site (`GalleryModal` ×2, `LayersPanel`) thành `async` + `await` |
| T4 | N2 — resolver thứ hai thiếu containment | ✅ **XONG** | `4c47e5a` — `SuggestLibraryService::resolveLocalImage()` → `studio_safe_public_file()`. Verify: `/storage/../../.env` → null |
| T16 | N5 + N8/N10 | ✅ **XONG** | `4c47e5a` — bỏ `svg` khỏi whitelist `studioServePath()` (storage không có .svg nào) · helper `studio_generation_error()` áp cho **8** site ghi `Generation.error`. Verify: prod ẩn chi tiết, dev giữ nguyên văn |
| T3 | VideoAIService (N4 ✅ · N9 ✅ · N10 ✅ · **N11 ✅ vòng 26**) | ✅ **XONG** | N4+N9+N10 ở `62ce15c`/`4c47e5a`. **N11 đóng ở vòng 26:** cờ `STUDIO_QUEUE_WORKER` — production có worker thì request chỉ enqueue, `sleep()` chỉ còn trong job nền. Xem bảng tiến độ. |
| T1 | Khôi phục + port test suite | ✅ **XONG** | `531c464` — 17 file test studio port từ `TrillfaShop@HEAD` (phpunit.xml trỏ `tests/`, DB `:memory:`); `ShopFlowTest` (storefront) không port vì storefront đã bỏ. **3 bug thật lộ ra ngay** (`953ca5c`) — xem §3/N14–N16 |
| — | **Test hồi quy cho các vá vòng 1–6** | ✅ `2412b54` | `tests/Feature/StudioRegressionFixesTest.php` — **13 test** phủ N1/N4 · M07 · N6 · N7 · M02 · M16 · N8/N10 · N5 · T15 |
| — | **Test phân quyền mọi endpoint generation-scoped** | ✅ `vòng 8` | `tests/Feature/StudioOwnershipTest.php` — **26 test**: 8 endpoint × (admin khác → 403 · guest → 401 · customer → 403) + chiều ngược lại (chủ sở hữu PHẢI dùng được). Kiểm kê xác nhận đủ **8/8** phương thức có `abort_unless(owner, 403)`. Cả 2 file test mới đều đã **mutation-test** để chứng minh không rỗng. |
| — | **Test an toàn cho `studio:clean-storage`** | ✅ `vòng 9` | `tests/Feature/StudioCleanStorageTest.php` — **5 test** cho code **XOÁ FILE** (trước đây 0 test): ảnh kết quả/base/mask không bị xoá · thư mục tài nguyên người dùng được bảo vệ · file mới không bị xoá · mồ côi+cũ THÌ bị xoá · `--dry-run` không xoá · `--days` kẹp ≥1 · 3 dạng URL đều được nhận. Kèm phát hiện: mục bảo vệ `'ref/'` trong command là **code chết** (so với `basename()` nên không bao giờ khớp) → đã bỏ. |
| — | **Test + vá cho `studio:process`** | ✅ `vòng 10` | `tests/Feature/StudioProcessCommandTest.php` — **8 test**. Kèm **vá thật**: khối "heal" của command update + increment **không điều kiện** = đúng lớp bug **M02** vẫn còn sót ở đây (comment đầu class tự nhận "an toàn chạy song song" nhưng câu đó chỉ nói về CAS của các JOB) → đã thêm CAS cho nhất quán. Test đua được mô phỏng **tất định** bằng `DB::listen`, và đã **mutation-test** xác nhận bắt được. |
| — | **Test S3 cho `storeRemoteImage`** | ✅ `vòng 11` | +**2 test**: enforce **allowlist host** (host ngoài → `null` và không gọi ra ngoài; đồng thời xác nhận `storeRemoteImage` uỷ quyền cho helper chung sau vá N1) · **đuôi file theo magic bytes** (PNG/JPEG lưu đúng đuôi, nằm dưới `studio/`, file tồn tại thật). Cả 2 mutation-test đều bắt được. |
| — | **Test an toàn các đường XOÁ của thư viện** | ✅ `c20adb4` | `tests/Feature/StudioLibraryDeleteSafetyTest.php` — **7 test**. Kèm **vá [CAO] N17**: xoá file tuỳ ý qua path traversal ở `/api/uploads/delete` (xem §3). |
| — | **Khoá 2 đường xoá còn lại cùng lớp bug** | ✅ `vòng 13` | +**3 test**: `refImageDelete` (guard `basename()`) · `assetDestroy` (containment `realpath()` với path DB bị nhiễm `../`) · `studioServePath()` từ chối `..`/dotfile/`.php` (bảo vệ `dirname($path)` khi tính thư mục thumbnail). Cả 2 mutation đều bắt được. **Rà soát âm tính:** `$tmpPath` (UUID) · `$thumbFile` (`basename`) · `uploadRef` (`storeAs` UUID) đều không nhận đường dẫn người dùng. |
| — | **Siết `User::$fillable` (chống leo quyền)** | ✅ `vòng 14` | **[tiềm ẩn]** `$fillable` chứa `role`/`is_active`/`credits_balance` → bất kỳ endpoint tương lai làm `$user->update($request->all())` là **tự nâng super_admin + tự cộng credit**. Hiện chưa khai thác được (0 route quản lý tài khoản). **Đã siết theo đúng tiền lệ K.1 F4** (đã làm cho `Project`) + cập nhật seeder sang `forceFill()`. +**5 test**. Mutation chứng minh: khôi phục `$fillable` → mass-assignment **thật sự** set được `role = super_admin`. |
| — | **Test bất biến "không rò khoá API" + vá 2 endpoint luôn 500** | ✅ `vòng 15` | **[BUG]** `settingsData()`/`settingsSave()` khai kiểu trả về `IlluminateHttpJsonResponse` — **mất dấu \\ đầu** → PHP resolve thành class không tồn tại → **luôn TypeError → 500 mọi lúc**; có từ TRƯỚC khi tách app, chưa từng bị phát hiện vì **không test nào gọi 2 route này**. +**5 test** khoá bất biến *"vật liệu khoá không bao giờ rời server"* (duyệt JSON **đệ quy**, có assert dương; báo cáo gốc gọi đây là "diện rò" của top-5#2 nhưng tính an toàn phụ thuộc hoàn toàn vào `$hidden` và chưa được test). **Audit âm tính:** raw SQL — 1 `selectRaw` chuỗi hằng, mọi `orderBy` hardcode, `sort` qua `switch`. |
| — | **Test toàn vẹn tĩnh + audit tham chiếu** | ✅ `vòng 16` | `tests/Feature/StaticIntegrityTest.php` — **5 test** khoá: route→class+method tồn tại · không route name trùng · mọi `view()` có blade · mọi `@vite` entry có file · mọi asset tĩnh có trên disk. **Audit cơ học:** 0 vi phạm ở cả 6 lớp; riêng **`route('...')` hỏng = 9, TẤT CẢ nằm trong dead code storefront** còn giữ từ T8 (`CartService` · `MenuItem` · `CustomPage` · `Seo.php` · `helpers.php` → `shop.index`/`product.show`/… không tồn tại ở FabrikAI) — không phải bug sống nhưng **định lượng được phần dư dead code**; 6 `studio_config()` key không có trong `config/studio.php` nhưng **đều có default hợp lệ**. |
| — | **Test quyền sở hữu tài nguyên ngoài generation** | ✅ `vòng 17` | `tests/Feature/StudioResourceOwnershipTest.php` — **8 test**. Rà lại: chỉ **4 bảng** trong module có `user_id` — `generations` (đã test 8/8 vòng 8) · `projects` (đã test) · **`suggest_results`** (code có scope nhưng **chưa test**) · **`studio_outfit_settings`** (chưa test). Các bảng còn lại (`studio_assets` · `presets` · `face_presets` · `pose_presets` · `studio_api_keys` · `studio_models` · `stylist_presets`) **không có `user_id` → cấu hình GLOBAL**, có test khẳng định điều đó là chủ đích. Mutation chứng minh scope `suggest-library` là thật. |
| — | **[CAO] Chống brute-force ở `/dang-nhap` + `/dang-ky`** | ✅ `vòng 18` | Chỉ **6/135** route có throttle và **login KHÔNG có giới hạn nào** → xác minh bằng chạy thật: **12 lần sai mật khẩu liên tiếp → tất cả 302**, không 429 ⇒ brute-force vô hạn; đăng ký 15/15 tạo được tài khoản. **Đã vá:** named limiter `login` = 5/phút theo **email+IP** · `register` = 5/phút theo IP + `auth_throttle_key()` dùng chung. +**4 test**. **Bài học tự bắt được:** bản đầu tôi thêm `RateLimiter::clear()` khi đăng nhập đúng — mutation-test cho thấy **bỏ dòng đó test vẫn xanh**; đào vendor mới rõ `ThrottleRequests:134` lưu bộ đếm dưới khoá `md5($limiterName.$limitKey)` nên `clear()` tra khoá thô là **no-op trang trí** và test đọc khoá thô **pass rỗng** → đã bỏ dòng vô tác dụng + viết lại test theo **hành vi quan sát được**. |
| — | **Khoá lớp XSS đầu ra** | ✅ `vòng 19` | `tests/Feature/StudioXssSinksTest.php` — **5 test**. Audit: `{!! !!}` = **0** · `innerHTML`/`insertAdjacentHTML`/`document.write`/`outerHTML` = **0** · `v-html` chỉ còn **2** (RefImageCard đã đổi sang primitives; StudioIcon là const hardcode — chấp nhận, có danh sách allowlist trong test). **Payload SPA `window.__STUDIO_BOOT__ = @json($boot)` chứa name/email người dùng** → test tạo user có tên `</script><script>…</script>` và khẳng định không phá được thẻ script. Mutation: đổi sang `{!! json_encode($boot) !!}` → **3 test đỏ**. |
| — | **Sửa N+1 ở `/api/defaults` + test ngân sách query** | ✅ `vòng 20` | Đo thực tế: `GET /api/defaults` tốn **75 query mỗi lần** (store gọi lúc khởi động SPA): **52×** `select * from settings where key = ?` (`Setting::get()` cache theo **từng key** → 52 key khác nhau = 52 query) + **21×** `select * from studio_models` (`studio_task_group_models()` query lại cho **mỗi nhóm**, 7 nhóm). **Đã vá:** cache cả bảng settings 1 khoá · nạp Model Registry 1 lần cho mọi nhóm · `StudioModel::booted()` xoá cache khi registry đổi. **Kết quả: 75 → 9 query (giảm 88%)**. +**3 test ngân sách query** (chống N+1 quay lại, khẳng định số query registry là **hằng số theo số nhóm**). **Baseline lúc đó: 267 test / 1535 assertion XANH** |
| — | **Hệ quả của tối ưu vòng 20: cache bảng nguyên bảng bị CŨ** | ✅ `vòng 21` (`22477ef`) | Việc gộp cache ở vòng 20 tạo ra **lớp bug mới**: `Setting::get()` nay cache **cả bảng** dưới một khoá `settings:all`, nhưng **chỉ `Setting::set()` mới xoá khoá đó** — mọi đường ghi khác đều để lại dữ liệu cũ. Tìm được **1 đường ghi thật**: `DatabaseSeeder` ghi thẳng `Setting::updateOrCreate()` cho 40+ key ⇒ **re-seed trên máy chủ đang chạy** (thao tác vận hành bình thường) sẽ **không** có hiệu lực cho tới khi cache hết hạn. **Đã vá:** thêm `Setting::flushCache()` công khai; seeder đi qua `set_setting()`. `Setting::set()` và `StudioModel::booted()` (vòng 20) đã xoá cache đúng — rà lại xác nhận. +**5 test** `tests/Feature/CacheInvalidationTest.php` (ghi setting → đọc lại thấy giá trị mới · 2 key khác nhau chỉ tốn **≤1 query** chứng minh cache dùng chung · seeder làm mới cache · Model Registry create/update/delete đều xoá cache). **Mutation-test cả 3 đường invalidation** — bỏ `Cache::forget` ở `Setting::set()` → 2 test đỏ · bỏ `static::saved/deleted` ở `StudioModel::booted()` → 2 test đỏ · seeder quay lại `updateOrCreate` → 1 test đỏ. Không mutation nào rỗng. **Baseline mới: 272 test / 1549 assertion XANH** · `route:list` 139 không đổi |
| — | **Biến phát hiện "9 tham chiếu `route()` hỏng" thành bất biến thường trực** | ✅ `vòng 22` (`9edafec`) | Vòng 16 phát hiện 9 `route()` trỏ tới route name **không đăng ký** — nhưng đó chỉ là **kết quả rà tay một lần**, không có gì chặn cái thứ 10 xuất hiện. **Đã khoá:** `test_route_references_resolve_to_registered_names` quét `app`+`routes`+`config`+`database`+`bootstrap` **và cả blade** (`{{ route('login.store') }}` — 4 site thật, trước đây không được kiểm) → mọi `route('[name]')` phải là name đã đăng ký. 9 mục cũ khai tường minh trong hằng `DEAD_ROUTE_REFS` **theo cặp (file, name)**, kèm bất biến *"chỉ được phép NGẮN ĐI"*. **Test thứ 2 `test_storefront_dead_code_is_not_reachable_from_live_entry_points`** chặn đúng bước nối dây nguy hiểm: nếu `CustomPage`/`MenuItem`/`CartService`/`Seo`/`menu_items()`/`seo()`/`cart_count()` xuất hiện trong `app/Http` · `routes/` · `resources/views/` thì **đỏ ngay** — vì lúc đó `route()` hỏng thành **500 thật**. Xác minh trước khi khoá: 0 route trỏ tới 4 class này · 0 blade gọi helper của chúng ⇒ hôm nay vô hại. **Mutation-test 4 đường, cả 4 đều bắt:** `route()` hỏng trong controller · `route()` hỏng trong blade · nối `menu_items()` vào blade · **gỡ 1 mục khỏi whitelist** (chứng minh whitelist đang gánh tham chiếu THẬT chứ không phải danh sách rỗng). **Baseline: 274 test / 1551 assertion XANH** |
| **FIX** | **Hợp nhất khởi động 4 entry SPA** — đúng lớp bug "vá một chỗ, quên chỗ tương đương" | ✅ `vòng 23` (`7b6da4e`) | `main.js` gỡ service worker cũ + guard element gốc trước khi mount; `settings.js`/`presets.js`/`stylist-data.js` thì **không**. Hệ quả: vào thẳng `/settings`·`/presets`·`/stylist-data` vẫn bị SW cũ (scope `/`, đăng ký từ thời `/studio`) phục vụ asset cũ → **SPA trắng trang không có lỗi rõ ràng**; và blade thiếu element gốc thì `mount()` ném lỗi Vue khó đọc. **Đã vá:** trích `resources/js/studio/pageBoot.js` (`killLegacyServiceWorker()` + `mountGuarded()`), cả 4 entry import và gọi. Toàn bộ `resources/js` nay chỉ còn **đúng 1** chỗ `.mount(` và **1** chỗ `serviceWorker`. `test_spa_entries_all_boot_through_the_shared_module` khoá bất biến. `npm run build` lại (assets production trong `public_html/build`). |
| **FIX** | **14 lớp phủ toàn màn hình thiếu `role="dialog"`** (a11y) | ✅ `vòng 24` (`d5ffd8d`) | §5.3 mới ghi 3 chỗ; **rà lại bằng máy ra 16** — vá a11y trước đây chỉ áp ở **2/16** (`BaseModal.vue`, `ProjectWorkspace.vue`). **Đã vá 14 chỗ:** `GalleryModal` · `CompareSlider` · `LayersPanel` (xóa nền AI, dọn canvas) · `StylistDataManager` (xác nhận xóa) · `SourcePickerPopup` · `SourceLibraryPicker` · `PromptLibraryTab` (chi tiết + sửa) · `SuggestLibraryCard` · `ConceptCard` (mẫu phom dáng, enrich preview, preset) · `StudioApp` (bảng lệnh, drawer menu, drawer kết quả). **Chỉ thêm thuộc tính — không đổi hành vi/bố cục**; `CompareSlider` dựng lại theo mẫu `BaseModal` (Esc + nhận focus). Miễn trừ đúng: backdrop rỗng và backdrop `@pointerdown` của menu. `test_full_screen_overlays_declare_their_role` khoá bất biến. **Mutation-test: gỡ role khỏi 1 dialog → đỏ; thêm overlay mới không role ngay TRƯỚC thẻ có role → đỏ.** Bản test đầu dùng "cửa sổ ±3 dòng" và **mutation chứng minh nó quá lỏng** → đã viết lại thành **tách thẻ HTML thật** sau khi bỏ chú thích. |
| — | **Sửa lại §5.2/§5.3 — mục đã CŨ so với code** | ✅ `vòng 23-24` | Đối chiếu từng mục §5.2/§5.3 với code hiện tại: **toàn bộ 8 mục `store.js` và 12/13 mục Vue đã đóng từ các vòng 5–6 nhưng §5.2 vẫn liệt kê là MỞ** (danh sách đó chưa được cập nhật khi các vá đó lên). Đã viết lại §5.2/§5.3 theo đúng số dòng hôm nay. **Baseline: 276 test / 1553 assertion XANH** |
| **T5** | Gỡ hẳn PWA (quyết định người dùng) | ✅ **XONG** \`7451d6b\` | Xoá \`public_html/sw.js\` + \`manifest.json\` + 8 icon chỉ phục vụ manifest (**icons 1,1 MB → 52 KB**), bỏ \`<link rel=manifest>\`. **GIỮ \`killLegacyServiceWorker()\`**: xoá file trên máy chủ KHÔNG tự gỡ SW đã cài trong trình duyệt người dùng cũ. Test khoá cả 2 chiều; mutation-test 3 đường đều bắt. |
| **T6** | Commit + push việc tách ở TrillfaShop (quyết định người dùng) | ✅ **XONG** \`f35704f\` (đã push) | 176 D + 16 M + 23 ?? = **215 mục**. Phát hiện & xử lý kèm: cây làm việc repo cũ đang **HỎNG** (210 test / **128 error / 40 fail**) vì module đã đi mà test còn ở lại — kiểm tra từng file cho thấy **100% lỗi là test của studio**, không có lỗi storefront nào. Đã xoá 17 file test chỉ kiểm studio (đã port sang FabrikAI từ T1) và gỡ đúng **22 method \`test_studio_*\`** khỏi \`ShopFlowTest\` (file trộn, giữ 42 test storefront). **Repo cũ nay xanh: 42 test / 216 assertion**, 0 route studio, 146 route. |
| **T7** | Chốt deploy production (quyết định người dùng: **FabrikAI là production**) | ✅ **XONG** \`vòng 26\` | Viết \`DEPLOY.md\`: env production · web root \`public_html\` · \`storage:link\` · supervisor cho worker (\`--timeout=900 --tries=1\`) · kiểm tra sau deploy · bảo trì · rollback. **Lưu ý:** \`DEPLOY.md\` bị \`.gitignore\` chặn **có chủ đích** — bản cũ của repo kia chứa SSH host + mật khẩu admin THẬT trong đúng file tên này; FabrikAI là repo PUBLIC nên quy ước đó được giữ. |
| **N11/M13** | Đưa \`sleep()\` ra khỏi request path (T7 đã mở khoá) | ✅ **XONG** \`vòng 26\` | Cờ \`config('studio.queue_worker')\` / \`STUDIO_QUEUE_WORKER\` (mặc định **false** để dev/test không phụ thuộc worker). \`show()\` tách 2 nhánh: \`enqueuePending()\` (chỉ enqueue) vs \`processPendingInline()\` (hành vi cũ). Có **chốt chống dội queue** (\`Cache::add('studio:queued:<id>')\`, 10 phút) — client poll mỗi ~1 giây nên không có chốt thì 5 phút = ~300 job cho cùng một ảnh. \`processQueue()\` tôn trọng cùng cờ. +**8 test**; mutation-test 4 đường đều bắt. **Tự bắt được 1 test yếu của chính mình:** test inline ban đầu chỉ assert *"status đổi khỏi pending"* — nó XANH ở CẢ HAI chế độ vì \`phpunit.xml\` đặt \`QUEUE_CONNECTION=sync\` nên \`dispatch()\` cũng chạy ngay; đã viết lại bằng \`Queue::fake()\` + \`assertNothingPushed()\`. |
| **N18** | **[CAO] Mật khẩu super admin hardcode trong repo PUBLIC** | ✅ **XONG** \`vòng 27\` | Xem §3/N18. Vá + **8 test** \`SeederCredentialSafetyTest\`; mutation-test 4 đường (đưa mật khẩu lại · quay về \`updateOrCreate\` · bỏ chốt production · bỏ cổng chặn demo) đều bắt. **Baseline: 293 test / 1603 assertion XANH** |
| **MỚI** | **Gỡ sạch module thương mại điện tử (FabrikAI là app HOÀN TOÀN MỚI)** | ✅ `vòng 28` (`729ceb0`) | Quyết định người dùng 2026-09-17: FabrikAI không phải bản sao của trillfa.shop ⇒ gỡ hết dấu vết. **Đã xoá:** 19 model (Product/ProductVariant/Category/Order/OrderItem/Cart/CartItem/Coupon/ShippingMethod/PaymentMethod/Review/Wishlist/Address/Banner/BlogCategory/Post/CustomPage/MenuItem/NewsletterSubscriber) · 3 service (CartService/CheckoutService/ProductAIService) · job GenerateProductSuggestion · app/Support/Seo.php · **27 migration** tạo bảng storefront · **31 helper** trong helpers.php (format_price · get_cart · cart_payload · seo · category_* · widget_* · deepseek_chat · menu_tree · 18 × product_ai_*) · **2 endpoint chết** (POST /api/settings/product-ai, GET /api/references) · tab "Sản phẩm" trong SourcePickerPopup.vue (335 → 239 dòng) · toàn bộ dữ liệu demo storefront trong seeder (641 → 213 dòng). **Kết quả:** route 133 → 131 · `migrate:fresh` trên DB trống ra **25 bảng, 0 bảng storefront** · grep -ri trillfa trên app/config/routes/resources/database/tests = **0**. |
| **BUG CÓ SẴN** | `helpers.php` thiếu `}` đóng khối guard `menu_tree` | ✅ `vòng 28` | Phát hiện khi gỡ: khối `if (! function_exists('menu_tree'))` **không có dấu } đóng**, nên toàn bộ helper phía sau (`studio_model_catalog`, `resolve_studio_model`, …) bị **lồng vào trong nó**. Chạy được chỉ nhờ may mắn (`menu_tree` không tồn tại ⇒ guard luôn đúng ⇒ thân vẫn thực thi). Đã xử lý đúng: xoá guard + hàm, bỏ dấu `}` mồ côi ở xa. Xác minh 48 helper studio còn nguyên, chỉ mất đúng 31 hàm mục tiêu. |
| **FIX** | `Setting` chỉ xoá cache trong `set()` | ✅ `vòng 28` | Cùng lớp N-vòng-21 nhưng ở tầng sâu hơn: mọi đường ghi qua Eloquent KHÁC `set()` (updateOrCreate hàng loạt, console command, seeder tương lai) đều để lại cache CŨ. Nay `Setting::booted()` tự xoá ở `saved`/`deleted`. **Ghi nhận đúng giới hạn:** ghi/xoá HÀNG LOẠT qua query builder KHÔNG kích hoạt model event (giới hạn của Eloquent, không phải bug repo) — có test riêng khẳng định điều đó và chỉ ra `flushCache()` là cách xử lý. |
| **BRAND** | Đổi toàn bộ branding + email sang domain mới | ✅ `vòng 28` | `APP_NAME` "Trillfa Fa" → "FabrikAI"; email tài khoản seed chuyển sang `owner@fabrikai.shop` / `admin@fabrikai.shop` / `user@fabrikai.shop` **và cho phép đổi qua env** (`SEED_SUPER_ADMIN_EMAIL`, `SEED_ADMIN_EMAIL`) — gỡ luôn email cá nhân khỏi repo PUBLIC; 20 file test đổi theo; bỏ 2 legacy redirect `/studio`; dọn mọi comment nhắc app cũ. |
| **DEPLOY** | Đưa lên fabrikai.shop (Hostinger shared hosting) | 🟡 **CODE XONG — CHỜ DATABASE** | Xem §9 bên dưới. Đã xong: clone code, `composer install` (vendor 71 MB), `.env` production + `key:generate`, symlink `public_html/storage` (bằng **shell** vì PHP bị chặn `symlink`), quyền ghi, asset phục vụ đúng (`/build/manifest.json` 200 · CSS 200 · JS 566 KB 200 · icon 200). **Còn thiếu DUY NHẤT: database** — user MySQL hiện có chỉ được cấp quyền trên `u310846799_trillfa` và **không thể tạo DB mới**, nên phải tạo trong hPanel. Site đang ở **maintenance mode** (503) cho tới khi có DB. |
| T5 | PWA | ✅ **XONG** | Gỡ hẳn (quyết định người dùng 2026-09-17) — `7451d6b`. Xem bảng tiến độ bên dưới. |
| T6 | Commit việc tách ở TrillfaShop | ✅ **XONG** | `f35704f` (đã push). Kèm dọn 17 file test studio + 22 method trộn để repo cũ xanh lại 42/42. |
| T7 | Deploy production | ✅ **CHỐT** | Người dùng quyết 2026-09-17: **FabrikAI LÀ production**. Runbook ở `DEPLOY.md` (bị gitignore — xem lý do trong file). Việc còn lại là THAO TÁC trên máy chủ, không phải việc code. |
| T8 | Dọn dead code | ✅ **XONG** | `23c12cb` (entry Alpine `app.js` 574 dòng + `redirectToFabrikai()`) + `3b69d2b` (**41 file**: 35 controller 0-route + `app/Modules/Storefront/**` chưa đăng ký + `OrderConfirmation` mail chết). **Giữ có chủ đích:** `app/Services/*` (14) + `app/Jobs/*` — không phải bề mặt tấn công và đang giữ coverage S4; `CleanStudioStorage` (auto-discovered, vẫn chạy); middleware/policy/Seo; models/migrations/seeders. Gỡ 1 test S5 cùng đối tượng đã xóa, có ghi chú tại chỗ. `route:list` 139 không đổi · `php artisan list` đủ 2 command studio: |
| T9 | Phình asset | 🟡 **MỘT PHẦN** | ✅ mọi icon đều là **cùng một ảnh 1935×1935** copy vào mọi slot (6 file trùng md5): `icon-512` khai 512 mà thật 1935, `favicon.ico` nặng **1,5 MB** → resize về đúng khai báo: **icons 9,6 → 1,1 MB · favicon 1,5 MB → 16 KB · public_html 32 → 22 MB**. ⚠️ `samples/` (20 MB, 51 file) **KHÔNG xóa** — `ImageAIService.php:533` dùng `glob(public_path('samples/2aOboQq*.jpg'))` làm ảnh demo |
| T10 | Backlog medium/low (§5) | ✅ **22/22 mục §5.1** | Đã đóng M01–M22 **hết**. **M13 đóng ở vòng 26** cùng N11 (T7 đã chốt FabrikAI là production ⇒ có queue worker ⇒ không còn phải chạy `sleep()` trong request path). §5.2/§5.3 xem mục riêng bên dưới. |
| — | **§5.2/§5.3 (low/info)** | 🟡 **ĐANG CHẠY** | Đã đóng **20 mục** (`2032225` · `7a18955` · `vòng 6`): quota localStorage nuốt lỗi · reload dự án bị bỏ · **a11y modal (role/aria-modal/Esc/focus)** · lỗi tải sản phẩm hiện như "chưa có sản phẩm" · lưu nhầm dự án khi form mở, renameLayer báo sai thành công · pollGeneration không trần · UpscaleCard hardcode status/credits · LoadingSpinner **animation không chạy** (verify trên CSS build) · LibraryApp rò timer khi unmount · ProjectWorkspace prototype pollution · GalleryModal thiếu guard VIDEO · main.js mount không guard · ContextToolbar computed chết + import thừa · 2 catch nuốt lỗi · librarySelectOld loại âm thầm · translate lưu object · double DELETE · ConceptCard draft không clamp. **Còn ~10 mục low/info** |
| — | `StudioIcon.vue:140` `v-html="ICONS[name]"` | ⚪ **CHẤP NHẬN CÓ ĐIỀU KIỆN** | 90 icon / **382 element** thuộc 6 loại (path/line/circle/rect/polyline/polygon) — chuyển đổi có rủi ro hỏng icon mà không xác minh được bằng mắt. `ICONS` là **const hardcode trong chính file**, chỉ tra theo key nên **không thể chèn markup** hôm nay. **Mở lại nếu `ICONS` đổi sang nguồn dữ liệu** (lúc đó đây là stored XSS thật). |

**Nhiệm vụ v2 ĐÃ ĐÓNG (không theo dõi nữa):** T2 v2 (tái xác minh 11 nhóm high/critical — phiên N) · T3 v2 (9 lỗi ShopFlowTest — phiên N, 64/64 lúc đó) · T4 v2 (M6+M8 CanvasMaskTools) · T5 v2 (xóa 2 component mồ côi) · T6 v2 (feature mini K.8: pattern/tryon gửi `project_id`, move 1-chạm, popover 20) · T7 v2 (commit 2 file báo cáo — phiên AB/AW).

**Nợ tài liệu:** 43 commit `05baac8..11ac3d0` (2026-09-08 → 09-11) chưa từng được ghi vào sổ — nay ghi ở §8.2.

---

## §2 — ĐÃ VÁ — bảng tra cứu (đã TÁI XÁC MINH trên FabrikAI 2026-09-16)

> Cột "v3" = kết quả đọc lại code FabrikAI hôm nay. **12/14 mục còn nguyên**; 2 mục có vấn đề (S2-residual, S10) — chi tiết ở §3.

| Fix (v2) | Bằng chứng trên FabrikAI | v3 |
|---|---|---|
| S1 · helper chống path traversal dùng chung | `helpers.php:279` `studio_safe_public_file()` — chặn `..` (:283) + charset whitelist (:286) + realpath containment 2 root (:290,:303). `StudioController.php:3536` `safeLocalFile()` uỷ quyền. **24 call-site** `resolveLocalImage()` (v2 ghi 21 — nay tăng) + **6** call-site trực tiếp helper (ImageAIService :527/:738/:1459 · helpers :464 · StudioController :3536 · AdminProductController :287) | **PRESENT** |
| Top-5#2 · API key không xuống trình duyệt | `StudioApiKey.php:17` `protected $hidden = ['value']` + `:26` `setValueAttribute()` mã hoá (`:30` `Crypt::encryptString`). `StudioSettingsController.php:399` chỉ trả `has_value` | **PRESENT** |
| Top-5#5 · không leak `getMessage()` ở 500 | `helpers.php:199` `studio_fail()` — log server-side (:201), message chung (:206), chi tiết chỉ khi APP_DEBUG (:208-209). `grep "response()->json" \| grep "$e->"` = **đúng 3 dòng, tất cả 422** (ProjectController:188 · CartApi:37 · CouponApi:28). Lưu ý: `studio_fail` có **5 call-site** (không phải 6 như v2 ghi) | **PRESENT** |
| S3 · `storeRemoteImage` chống SSRF | `ImageAIService.php:1137` — scheme http/https (:1143), `Http::timeout(30)` (:1159), connect_timeout 10 + redirect ≤2 (:1160), cap 50 MiB (:1171). Allowlist host đọc qua `studio_config('remote_image_hosts')` → setting DB, không phải literal | **PRESENT** ⚠️ **nhưng có 2 site bypass — xem §3/N1** |
| S4 · XSS stub ProductAI | `ProductAIService.php:1043,1049,1056,1125,1155-1161` — `e()` bọc mọi biến user/AI trong HTML; field plain-text vẫn raw (đúng chủ đích) | **PRESENT** |
| S5 · `AdminProductController::resolveImagePath` | `:287` `return studio_safe_public_file((string) parse_url($url, PHP_URL_PATH));` | **PRESENT** |
| S6 · serve ảnh public | `StudioController.php:3467` `studioServePath()` đủ 4 lớp (chặn `..`/charset :3469 · chặn dotfile segment :3472-3474 · whitelist đuôi :3477 · containment **3 root** :3481,:3494); gọi ở `:1956` (studioImage) + `:1973` (thumb). Residual vision data-uri qua helper: `helpers.php:464` | **PRESENT** ⚠️ whitelist có `svg` — §3/N5 |
| S8 · cleanup scope whitelist | `StudioController.php:3757` `'scope' => ['required','string','in:orphans,junk,old']` | **PRESENT** |
| S9 · input API key | `SettingsApp.vue:305` (sửa) và `:332` (thêm mới) đều `type="password" autocomplete="new-password"`. `settings.blade.php` nay chỉ 14 dòng shell SPA | **PRESENT** (đổi chỗ blade→Vue) |
| S2-residual · cap pixel chống decompression bomb | `helpers.php:230` cap 64 MiB · `:256` cap pixel 30 MP · `:257` kiểm tra — **nhưng nằm SAU `imagecreatefromstring` (:248)** | **CHANGED — không đạt mục tiêu tuyên bố** (§3) |
| S10 · VirtualTryOn prompt injection | Verdict "dương tính giả" giữ nguyên về **tác động**, nhưng **lý do trong v2 SAI** | **CHANGED** (§3) |
| K.8 · scope `project_id` theo owner | 4 endpoint còn `Rule::exists('projects','id')->where('user_id',…)`: generate :98 · renderVideo :214 · pattern :4497 · tryon :4514 | **PRESENT** ⚠️ còn **2 site thiếu scope** ở suggest-library — §3/N6 |
| F1 · reviewer gate + `$fillable` | `ProjectWorkflowService.php:121-128` gate thích ứng + `:138` `isOnlySuperAdmin` + `:194` `self_approved`; `ProjectController.php:174` owner/super-admin; `Project.php:50-63` `$fillable` KHÔNG có `user_id`/`status` | **PRESENT** |
| L.2 · refgen pre-validate | `StudioController.php:753` `if (! $this->resolveLocalImage($baseImage))` → 422 tại `:754-756`, `downscaleSource` đã hoist lên `:748` (trước vòng lặp variants `:760`) | **PRESENT** |
| J.2-J.6 · 4 pattern đòn bẩy | `studio_image_decode` phủ 43 call-site · `res.ok` guard · catch rỗng → `console.error` · `getMessage` 500 = 0 | **PRESENT** (xem §5 để biết residual từng nhóm) |
| K.4 · đệ quy vô hạn `studio_image_decode` | `helpers.php:248` `@imagecreatefromstring` trực tiếp (không đệ quy) | **PRESENT** |

---

## §3 — HIGH/CRITICAL: kết quả TÁI XÁC MINH v3 (2026-09-16)

| # | Nhóm (v2) | v3 | Ghi chú |
|---|---|---|---|
| S1 | traversal ImageAIService | **PRESENT** | 24 call-site; helper còn nguyên 3 lớp |
| S2 | unbounded memory per-pixel | **CHANGED — cap đặt sai chỗ** | Cap pixel (`helpers.php:257`) chạy **SAU** `imagecreatefromstring` (`:248`): bitmap đã cấp phát trước khi bị từ chối → **đúng cái OOM ở bước decode mà v2 nói đã vá vẫn xảy ra**. Cap chỉ bảo vệ các vòng lặp per-pixel phía sau. Thêm nữa: `image_max_pixels` **không có trong `config/studio.php`** (grep = 0) — giá trị chỉ đến từ setting DB hoặc default 30 MP trong code. **Fix đúng:** kiểm tra `getimagesizefromstring()` TRƯỚC khi decode |
| S3 | SSRF storeRemoteImage | **PRESENT + 2 BYPASS MỚI** | Xem N1 |
| S4 | stored XSS stub | **PRESENT** | |
| S5 | ProductAI đọc file cục bộ | **PRESENT** | |
| S6 | studio_image_url / vision data-uri | **PRESENT** | |
| S7 | orphan scan unscoped | **SAI (giữ nguyên)** | Route trong group auth+admin+nostore |
| S8 | cleanup scope | **PRESENT** | |
| S9 | SettingsApp key exposure | **PRESENT** | |
| S10 | VirtualTryOn prompt injection | **CHANGED — lý do v2 SAI** | Verdict "dương tính giả" vẫn hợp lý về tác động (route admin-only, nạn nhân tự injection vào prompt của chính mình), **nhưng** v2 viết *"background/modelDesc từ store_outfit settings (admin)… không có đường user input tới prompt"* — **SAI**: `background` **là** input người dùng (`StudioController.php:3230` validate `max:400` → `:3271` lưu meta → `:3302` đọc lại → `VirtualTryOnService.php:382` `$bgInstr = 'Replace the ENTIRE background…'.$background`). `grep store_outfit app/` = 0. Chỉ `modelDesc` là đúng (từ catalog) |
| S11 | StyleSuggest downscaleBase64 | **PRESENT** | qua `studio_image_decode` + containment |

### Lỗi MỚI phát hiện trong đợt v3 (chưa từng có trong báo cáo)

| # | Vị trí | Mô tả | Mức |
|---|---|---|---|
| **N1** | `StudioController.php:3116` và `:3154` | **Bypass có chủ đích bản vá S3**: `// storeRemoteImage is protected — use a direct store via file_get_contents` → `$contents = @file_get_contents($upscaledUrl);` (applySuperResolution :3095) và `@file_get_contents($enhancedUrl)` (applyFaceEnhance :3136). URL lấy từ response provider (:3112). **Không** scheme check, **không** timeout, **không** cap 50 MiB, **không** allowlist host → SSRF + treo worker + đầy disk. Cùng lỗi đã được vá ở `ImageAIService` nhưng 2 site này không đi qua helper | **cao** |
| **N2** | `SuggestLibraryService.php:261` | Resolver thứ hai **không containment**: chỉ `preg_replace('#^(storage/)+#')` (:268) rồi `Storage::disk('public')->path($rel)` (:270) + `is_file` (:271). Không chặn `..`, không charset whitelist, không realpath. Đường: `POST /api/suggest-library/save` → `StudioController:1733` → `SuggestLibraryService:103` → `:222`. Tác động bị chặn một phần vì kết quả chỉ đưa vào `studio_image_decode()` (:227) | **trung bình** |
| **N3** | `routes/web.php:71` | Route trỏ tới **method KHÔNG tồn tại**: `Route::get('/studiosample/{file}', [StudioController::class, 'assetSample'])`. `grep assetSample app/` = 0; **reflection xác nhận MISSING** (trong khi `boot`/`download`/`studioImage`… đều EXISTS) → URL `/api/studiosample/...` ném `BadMethodCallException` (**500**) thay vì 404. `route:list` vẫn in bình thường nên lỗi không lộ | **thấp** |
| **N4** | `VideoAIService.php:131` | `@file_get_contents($url)` tải video — cùng lớp S3, file **chưa từng được review**; không cap dung lượng trước khi ghi `storage/app/public` (:137) | **trung bình** |
| **N5** | `StudioController.php:3477` + `:1960` | Whitelist đuôi của endpoint ảnh **public** có **`svg`**, phục vụ bằng `response()->file()` (mime suy từ đuôi) → SVG render như document **cùng origin**. Cần quyền admin để upload (mọi upload site đều trong group auth+admin) | **thấp** |
| **N6** | `StudioController.php:1711`, `:1818` | **IDOR-lite còn sót**: suggest-library dùng `exists:projects,id` **không scope ownership** (`suggestLibrarySave` :1707; `validateSuggestLibrary` :1814 dùng cho store :1779 + update :1790) → gắn được bản ghi prompt vào `project_id` của user khác. Khác hẳn 4 site đã vá ở K.8. Các thao tác còn lại đã owner-scoped đúng (:1759/:1793/:1804) | **trung bình** |
| **N7** | `routes/web.php:169-170` + `:171-172` | **2 POST endpoint nằm ở nhóm PUBLIC**: `/api/stylist/cluster`, `/api/stylist/prompt` — và `stylistPrompt()` **GHI DB** qua `StylistCatalog::savePreset()` (`StudioController.php:2663` → `StylistCatalog.php:255`) mà **không cần đăng nhập**. Thêm nữa `/api/stylist-data/data` + `/api/stylist/presets` public đều gọi `ensureTables()` → **chạy `Schema::create`** trên route không auth. **Không phải regression do tách repo** (bản cũ cũng vậy) nhưng comment "READ-ONLY" của bản cũ SAI nên chưa bao giờ được xử lý | **trung bình** |
| **N8** | `StudioController.php:1374`, `:1387` | Rò exception qua cột `Generation.error` (`$generation->update(['error' => $e->getMessage()])`) — cột này nằm trong `$fillable` (`Generation.php:15`) và được trả cho client qua `show()` (:1350, owner-scoped :1352) → chủ sở hữu đọc được message thô của provider/DB, trái tinh thần vá J.4 (chỉ áp cho response HTTP, không áp cho cột DB) | **thấp** |
| **N9** | `VideoAIService.php:43` | Stub trả `/samples/studio-catwalk.mp4` **không tồn tại** (`ls public_html/samples/*.mp4` = no such file) → generation báo `completed` với `media_url` 404 | **thấp** |
| **N10** | `VideoAIService.php:85`, `:112` | Rò body provider 240 ký tự vào `error` của Generation → hiển thị cho user (cùng họ N8) | **thấp** |
| **N11** | `VideoAIService.php:95`, `:106` | `$deadline = microtime(true) + 480` + `sleep(5)` trong `while` → chiếm PHP-FPM worker tới **8 phút** | **trung bình — ĐÃ ĐÓNG vòng 26** (`STUDIO_QUEUE_WORKER`) |
| **N12** | `resources/js/studio/components/LayersPanel.vue:80` | Site **mới** cùng họ bug clipboard-không-await (đã có ở `GalleryModal.vue:175,182`): toast "Đã chọn màu" **giả** khi bị chặn quyền/insecure context | **thấp** |
| **N17** | `StudioLibraryService::normalizeUploadRel()` + `safeUnlink()` | **[CAO — đã vá `c20adb4`] Xoá file tuỳ ý qua path traversal.** `POST /api/uploads/delete` với `rels: ["studio/ref/../../../<file>"]`: `normalizeUploadRel()` chỉ kiểm **tiền tố** `studio/ref/` (không chặn `..`) → lọt; `safeUnlink()` so **chuỗi** với root nên `<root>/studio/ref/../../../x` vẫn "bắt đầu bằng root" → lọt, trong khi `is_file()`/`unlink()` resolve `..` ra file **ngoài root** ⇒ **xoá file tuỳ ý**. v2/v3 xếp mục này là *"defense-in-depth, medium"* — **đánh giá thấp**: nó là xoá file thật vì `safeUnlink` không resolve đường dẫn. Vá 2 lớp (`..` segment + `realpath` containment), mutation matrix chứng minh **cả 2 lớp đều load-bearing** | **cao — đã vá** |
| **N13** | `main.js:7-16` + `public_html/sw.js` | PWA chết: `main.js` unregister mọi SW + xoá mọi cache mỗi lần tải; không nơi nào đăng ký `/sw.js`; `app.js:570-573` xác nhận *"không còn nơi nào đăng ký lại /sw.js nữa"* → `sw.js` + `manifest.json` là **code chết** | **thấp (mất tính năng)** |
| **N14** | `StudioController::show()` ~:1384 + `RenderImageJob/VideJob::handle()` :40-46 | **[cao] Generation kẹt `processing` vĩnh viễn.** `show()` set `status='processing'` TRƯỚC rồi mới `dispatchSync()`, nhưng `handle()` mới thêm CAS *"chỉ claim khi `pending`"* → CAS thất bại → job `return` ngay. Ảnh/hình **không bao giờ** render xong ở đường lazy (đường chính khi không có queue worker). Bản TrillfaShop HEAD không có CAS nên không dính. **Đã vá `953ca5c`** | **cao — đã vá** |
| **N15** | `StudioController::resolveLocalImage()` ~:3515 + `helpers.php:549` | **[trung bình] Tiền tố URL cũ còn sót sau khi đổi `/studio/*` → `/api/*`**: 2 chỗ vẫn strip đúng `'studio/image/'` trong khi chính app phát ra `'/api/image/...'` (`helpers.php:532`) → URL do app phát ra **không resolve ngược** được. **Đã vá `953ca5c`** (nhận cả 2 tiền tố, vì `media_url` cũ vẫn là `/studio/image/`) | **trung bình — đã vá** |
| **N16** | `VideoAIService.php:43` | **Demo video 404**: stub trả `/samples/studio-catwalk.mp4` nhưng file này **CHƯA TỪNG tồn tại** (kiểm tra toàn bộ git history TrillfaShop: không có blob `.mp4` nào) → generation `completed` với `media_url` 404. **Đã vá `953ca5c`**: giữ DEMO MODE (đúng quy ước module — ảnh cũng dùng `samples/*.jpg`) và ship file demo thật 4,5s · 640×640 · 168 KB; test khoá thêm `assertFileExists(media_url)` để không mất lại | **thấp — đã vá** |
| **N18** | `database/seeders/DatabaseSeeder.php` | **[CAO — đã vá vòng 27] Mật khẩu super admin hardcode trong repo PUBLIC.** Seeder tạo `tuan.ho.designer@gmail.com` với `Hash::make('hattf2768')` — mật khẩu literal nằm trong git của repo **công khai**, ai đọc repo cũng biết. Tệ hơn: dùng `updateOrCreate` nên **mỗi lần `db:seed` là GHI ĐÈ mật khẩu** về giá trị đã lộ (đổi mật khẩu xong vẫn bị reset lần seed sau). Cùng chỗ còn `admin@trillfa.com / 'password'`. Phát hiện khi đối chiếu với `DEPLOY.md` của repo cũ — chính tài liệu đó ghi *"đăng nhập Super Admin (…/hattf2768)"*, tức mật khẩu này từng là mật khẩu THẬT trên production. **Đã vá:** `seedPassword()` đọc từ `SEED_*_PASSWORD` + `guardProductionSeeding()` ném lỗi khi production thiếu env (fail-closed) + `firstOrCreate` (không bao giờ ghi đè) + chặn dữ liệu demo ở production. Vá kèm một lỗi phụ: `email_verified_at` không nằm trong `$fillable` nên đường `updateOrCreate` cũ đã **nuốt** trường này → tài khoản seed ở trạng thái chưa xác thực email. | **cao** |

**Kết luận §3 (đã viết lại 2026-09-17 — bản cũ SAI cả 2 vế):** ✅ **N1 (cao) nay ĐÃ VÁ** ở `62ce15c` (`StudioController.php:3154` + `:3193` dùng `studio_fetch_remote_bytes()`; `grep 'file_get_contents($url'` = 0) — bản cũ ghi "đang mở" là sai. ✅ **S2 (cap pixel) nay ĐÚNG CHỖ**: `helpers.php:160` `getimagesizefromstring()` kiểm **TRƯỚC** `:172` `imagecreatefromstring()`, và key `image_max_pixels` **đã có** trong `config/studio.php:23` (bản cũ ghi "grep = 0" là sai) — bản cũ ghi "vá sai chỗ" là sai. ⚠️ **Còn lại thật:** N6 (`SuggestLibraryService::counts()` = 4 COUNT mỗi `list()`), nhánh **inline vẫn `sleep()` trong request** khi `queue_worker=false`, **DDL công khai** ở `GET /api/stylist-data/data`, sanitizer `Generation.error` **chỉ lọc khi `APP_DEBUG=false`**. Xem §10.

---

## §4 — Claim bị loại (giữ nguyên từ v2, đã đối chiếu lại)

| Claim bị loại | Bằng chứng bác bỏ | Nguồn |
|---|---|---|
| `[high]` "config/studio.php đọc env() lúc parse → rotation key bị bỏ qua" | Pattern chuẩn Laravel (**284 vị trí `env()` trong `config/`** ở FabrikAI). Đường rotation THẬT là DB-first: registry `StudioApiKey` mã hoá → setting DB → config fallback | G.1 + H.0 |
| `[high]` "StylistData `page()` public cho mọi khách" | Route nằm trong group `[auth,admin,nostore]`. **ĐÍNH CHÍNH v3:** nhóm **public** thật ra có **11 route** (không phải "2 endpoint read-only"), trong đó 2 POST và 1 POST **ghi DB** — xem N7. Claim gốc vẫn loại, nhưng mô tả nhóm public của v2 sai | E + §3/N7 |
| `[medium]` "csrf-mass-assignment `storeProject()`" | `validate()` chỉ trả `name`/`base_concept`; `user_id` do relation gán | D |
| `[high]`+`[medium]`×2 blade XSS | `{{ }}` escape mặc định; `@json` dùng `JSON_HEX_*`; `grep '{!!' resources/views/studio/` = **0** | F |
| `[medium]` "StylistCatalog DDL chạy mỗi request, unauthenticated" | `Schema::create` chỉ chạy khi thiếu bảng. **ĐÍNH CHÍNH v3:** 2 route public `/api/stylist-data/data` + `/api/stylist/presets` **thật sự** gọi `ensureTables()` không cần auth (N7) — verdict "có chủ đích cho shared hosting" giữ, nhưng nay nên siết | F + §3/N7 |
| `[medium]` "ProjectWorkspace load/open/move không có user feedback" | Store bắt + toast. **v3:** nay đã thêm in-flight guard `openingId`/`movingId` (`ProjectWorkspace.vue:138,150`) | I.8 + F-27 |
| `[low]` "StudioIcon không guard r.ok" · "LoadingSpinner progress không clamp" | Tự đính chính: guard có sẵn · clamp có sẵn. **v3:** `StudioIcon.vue` nay không còn `fetch()` nào (chỉ map ICONS) | I.5, I.6 + F-22 |
| `[medium]` store.js IDOR generation/project id | Server-side enforce đã xác minh (`abort_unless` owner-scoped ở nhiều site + K.8) | A→C→K.8 |

---

## §5 — Backlog CÒN MỞ (đã tái xác minh v3 — số dòng là số dòng THẬT ở FabrikAI)

> Quy ước: `[s] file:vị trí — triệu chứng → hướng fix`. Trạng thái v3: **MỞ** (còn nguyên) · **MỞ-MỘT-PHẦN** · **ĐÓNG** (đã vá) · **N/A** (không còn áp dụng).

### 5.1 Medium (22 mục §5.1 v2 → v3)

| # | Trạng thái v3 | Vị trí & ghi chú |
|---|---|---|
| M01 | **MỞ** | `StudioController.php:3260` `'credits_cost' => 1` → `:3369` `$credits = max(1, $svc->calls())` → `:3373`; `swapModel()` dispatch `SwapModelJob` (`:3276`) **không** đi qua `queueGeneration()` nên không chạm `decrement` duy nhất của luồng (`:1566`). Ghi chú `:1564`: *"Internal admin tool: never hard-block on credits"* → có thể là lệch thiết kế, nhưng `credits_left`/`studio_usage()` vẫn báo sai |
| M02 | **MỞ** | `StudioController.php:1474-1487` `reconcileStuckCredits()` — SELECT processing >30' (không `lockForUpdate`) → update + `increment` không transaction/conditional. Gọi ở `:1565` đầu `queueGeneration()`; `/api/reimagine` gọi tuần tự nhiều lần (`:532-541`). `grep "lockForUpdate\|DB::transaction" StudioController.php` = **0**. Cùng họ ở `failStuck()` :1339-1345 |
| M03 | **MỞ-MỘT-PHẦN** | `StylistDataController.php:83,92,133,142` check-then-act + `sort_order=max+1` còn nguyên. **Mới:** DB **đã có** unique index (`migration:13,23`) → race ném ConstraintViolation → `:104` `studio_fail(...,500)` → trả **500 thay vì 422** (không tạo trùng, nhưng không sạch). `StylistCatalog::savePreset()` :252 dedup trên cột TEXT **không unique** + `:261-263` nuốt lỗi → vẫn có thể trùng |
| M04 | **MỞ** | `store.js:337-342` `api()` đã throw khi `!res.ok` (`:340`) nhưng **không** check `res.redirected`/content-type. Guard đúng tồn tại ở `:419` (needsLogin) và `:1115` (`_libraryFetch`) nhưng không áp cho `api()` |
| M05 | **MỞ-MỘT-PHẦN (latent)** | `store.js:1140`+`:1146` vẫn double-increment `page`, nhưng backend **luôn** trả `current_page` (`StudioLibraryService.php:91` `$paginator->currentPage()` ≥1, không falsy) → nhánh fallback không chạy |
| M06 | **MỞ** | `store.js:718` `prompt = 'Cinematic fashion catwalk: ' + this.imagePromptEn + …` → prompt người dùng nối thẳng vào template, gửi raw `:723-732` |
| M07 | **MỞ-MỘT-PHẦN** | Server **đã cap**: `StudioController.php:513` `variants max:4` (lặp :100,:642,:793,:852) · `:788` `images min:2 max:3`. Client vẫn `:461` `Number(this.variantCount) || 1` (không trần) và pose không cap (`:3943`,`:3960`). Đường edit đã ép local (`ImageAIService.php:738`), **đường vision vẫn forward URL http tuỳ ý** (`helpers.php:413`) |
| M08 | **ĐÓNG** | Toast nay render bằng text interpolation: `StudioApp.vue:506` `{{ store.flashMsg }}`; Alpine store `app.js:54-64` không có markup tiêu thụ (`grep "$store.toast"` = 0; `x-html` = 0) |
| M09 | **MỞ** | `StylistService.php:77-89` heredoc nội suy `{$promptEn}` vào instruction LLM không delimiter; output chỉ cast chuỗi (`:103-104`), không validate schema/độ dài |
| M10 | **MỞ (N+1) / MỞ-MỘT-PHẦN (log)** | `StyleSuggestService.php:338` `Preset::category($category)->get()` trong vòng lặp **lồng**; `:454-458` 5 query riêng. `suggest()` nay **có** log provider+message (`:56`) nhưng vẫn vứt context (status/model đã thử) và `:65` throw không aggregate |
| M11 | **MỞ** | `VirtualTryOnService.php:328` `$hasVision = (bool) (studio_api_key('qwen') ?: …)` — chỉ biết key **tồn tại**, không biết key **hỏng**; QA `:430` + `pickBestCandidate` :406-421 trả null âm thầm khi mọi lần score fail (`:404`) |
| M12 | **MỞ** | `GeminiService.php:129` `if ($resp->successful()) {` — không `else`, non-2xx im lặng, không log status/body, không backoff. (Nhánh Qwen có log: `:89`) |
| M13 | **ĐÓNG** (vòng 26) | Cùng gốc với N11: `ImageAIService.php:611` `microtime(true)+180` + `:614` `sleep(4)` · `:1096` `sleep($wait)` · `:1131` `sleep($backoffs[…])` — các `sleep()` này **vẫn còn**, nhưng nay chỉ chạy trong **job nền** khi `STUDIO_QUEUE_WORKER=true`. Request chỉ enqueue rồi trả về (`StudioController::enqueuePending()`), nên không còn giữ chặt PHP-FPM. Đường inline cũ vẫn tồn tại cho dev/máy chủ chưa có worker và có ghi rõ cảnh báo trong `config/studio.php`. |
| M14 | **MỞ** | `ImageAIService.php:1013-1019` `$body = Str::limit($resp->body(),240)` → `dashscopeError` → `:119` throw `RuntimeException($msg)` → `:251`. `providerErrorMessage()` :254-270 chỉ thay pattern đã biết, `:272 return $msg` thô |
| M15 | **MỞ** | `ProductAIService.php:60-106` `brandContext()` (đọc About page) nhúng vào prompt `:756` và `:810` (brandBlock) **không framing tin cậy**; field người dùng `:818` nội suy trực tiếp không delimiter |
| M16 | **MỞ (cả 4 tiểu mục)** | `StudioLibraryService.php` — `counts()` gọi trong **mỗi** `/api/library/data` (`:94`): 8 count riêng (`:105-118`) + quét đĩa `:119` `scanOrphanFiles()` (:371-409) + cursor 4 bảng (:425-444) · LIKE không escape `:54-56` (`grep addcslashes\|escapeLike` = 0) · TOCTOU `:288`→`:292` · `normalizeUploadRel()` :319 không reject `..` |
| M17 | **MỞ** | `migration 2026_08_31_000100`: `studio_assets` không `user_id` (:13,:15,:16 không index/unique) · `helpers.php:503-507` `studio_api_keys_for()` load **mọi** key enabled rồi filter in-memory · `:519` falsy coalescing · `:554` nhánh config plaintext |
| M18 | **MỞ (latent)** | `RefImageCard.vue:229` `v-html="a.svg"` — nguồn vẫn hardcode (`:114-119`), chưa có đường API |
| M19 | **ĐÓNG** | `StudioController.php:3806` (download) + `:3841` (palette) nay đi qua `resolveLocalImage()` → `safeLocalFile()` → helper containment (kèm abort 404) |
| M20 | **MỞ-MỘT-PHẦN** | Decode tập trung ở helper nhưng trả `false` **không log** khi `imagecreatefromstring` fail (`helpers.php:248-251`); call-site trả im lặng: `StudioController.php:429-430` `return $url` · `:2104-2105` `'decode failed'` 500 không log path |
| M21 | **MỞ (SwapCard) / ĐÓNG (SuggestCard)** | `SwapCard.vue:84` vẫn `fetch('/api/assets/' + a.id)` không validate/encode. `SuggestCard.applyPrompt` **không còn tồn tại**; thay bằng `generateNow()` có null-guard (`:68`) + `store.applySuggestPrompt` guard (`store.js:1416`) |
| M22 | **ĐÓNG + 1 NEW** | `StylistCard.vue` **không còn `v-html`** — `stylistFeedback` đi qua `store.toast(…)` (`:50`) → escape ở `StudioApp.vue:506`. `grep v-html resources/js/studio/` = **2**, cả hai là SVG hardcode (`RefImageCard.vue:229`, `StudioIcon.vue:140`). **NEW:** `StudioIcon.vue:140` `v-html="ICONS[name] || ''"` là latent sink nếu map ICONS đổi sang dữ liệu API |

### 5.2 Low — Frontend JS/Vue (39 mục đã đọc lại)

> **ĐÃ VIẾT LẠI 2026-09-17 (vòng 23–24).** Danh sách "MỞ" cũ của §5.2 **đã lỗi thời**: nó chưa được cập nhật sau các vòng 5–6, nên vẫn liệt kê là MỞ trong khi **cả 8 mục `store.js` và 12/13 mục Vue đã được vá** (code hiện có chú thích `// §5.2: …` ngay tại chỗ vá). Dưới đây là trạng thái **đối chiếu lại từng dòng code hôm nay**.

**CÒN MỞ (2 mục, đều là mức "nit"):**
- `SwapCard.vue:8` `setInterval` 1s **vô điều kiện** — dù đã có `clearInterval` ở `onBeforeUnmount` (`:9`), đồng hồ vẫn tick mỗi giây kể cả khi không màn hình nào cần đếm. → chỉ nên chạy khi có generation đang xử lý.
- `SwapCard.vue:47` `onMounted` **ghi thẳng vào store** (`store.swapModelIds = …`) trong lúc tải danh sách — mutation ngoài ý muốn khi component mount; nên đặt mặc định ở `store` hoặc trong `watch` có điều kiện.

**ĐÃ ĐÓNG — `store.js` (đối chiếu 2026-09-17):** `loadDefaults()` log + toast lỗi (:366) · `renameLayer()` chỉ toast thành công **sau khi** server xác nhận, lỗi thì báo "CHƯA lưu được lên máy chủ" (:1983-1993) · `saveLayerLayout()` `QuotaExceededError` nay báo rõ **một lần** cho người dùng (:2672) · `librarySelectOld()` fallback `created_at` rồi `id` thay vì loại âm thầm (:1194) · `translate()` luôn ra **chuỗi**, không còn lưu object (:2855) · `pollGeneration()` có **trần 10 phút** + toast (:2895-2903) · `loadProjects()` xếp hàng yêu cầu thay vì bỏ qua (:1466-1499).

**ĐÃ ĐÓNG — Vue (đối chiếu 2026-09-17):** `ConceptCard.vue:103` `loadDraft()` **clamp** đủ trường · `GalleryModal.vue:63` `deleting` pending flag + nút `:disabled` (:499) · `:175/:182` clipboard **có await** (T17) · `:274-278` guard `onKey` **đã có `VIDEO`** · `SourcePickerPopup.vue:143` lỗi tải không còn hiện empty-state gây hiểu lầm · `UpscaleCard` không còn hardcode status/credits · `LoadingSpinner.vue` animation chạy · `ProjectWorkspace.vue:35` `Object.create(null)` (hết prototype pollution) · `submitEdit` giữ id dự án **lúc mở form** (:101) · `ContextToolbar` computed chết đã bỏ · `LibraryApp.vue` có `onBeforeUnmount` dọn timer · `main.js` guard element gốc — **nay gom vào `pageBoot.js` `mountGuarded()` dùng chung cho cả 4 entry** (vòng 23) · `settings.js`/`presets.js`/`stylist-data.js` **đã có** teardown SW (vòng 23).

**ĐÓNG:** `SettingsApp.vue` 3 form ref riêng (`:68/:113/:151`) · `ComposeCard.vue:157,195` guard `res.ok` · `ProjectWorkspace.vue:138,150` in-flight guard · `OutputModule.vue:48` nhánh `cancelled` · `useStudioThumb.js:12/49` whitelist thay `encodeURIComponent` + `:67-68` `dataset.fallback` · `processQueue()` `console.error` (`store.js:451,453`).

**N/A (không còn áp dụng):** toàn bộ nhóm `settings.blade.php` (H.5/H.6 — file nay chỉ **14 dòng** shell SPA; `grep studioTestModel` = 0, `grep "onsubmit="` trong resources = 0) · toàn bộ nhóm `library.blade.php` (H.7 — file **đã xóa**) và `vue.blade.php` (đã xóa) · `RegionTools.removeRegion` (`grep` = 0) · `StudioIcon` fetch/`.catch(() => '')` (`grep fetch(` = 0) · `OutputModule` anchor download (đã bỏ; nút tải nay ở `GalleryModal.vue:452` trỏ route server `routes/web.php:111`) · `OutputModule`/`GalleryModal` badge `cancelled` (đã vá) · `useStudioThumb` `dataset.fallback` (đã có).

### 5.3 Info (chọn lọc — cập nhật v3)

- **a11y**: **đã vá 2026-09-17 (vòng 24).** Danh sách cũ ghi 3 chỗ; **rà bằng máy ra 16** — vá trước đây chỉ áp ở **2/16** (`BaseModal.vue`, `ProjectWorkspace.vue`). Nay **cả 16** lớp phủ toàn màn hình đều khai `role="dialog"` + `aria-modal="true"` + `aria-label`; riêng `CompareSlider.vue` còn được dựng lại theo mẫu `BaseModal` (Esc + nhận focus). Miễn trừ đúng: backdrop rỗng và backdrop `@pointerdown` của menu (không phải hộp thoại). Khoá bằng `test_full_screen_overlays_declare_their_role`.
  - **CÒN THIẾU (ghi rõ để không tưởng là xong):** 12 overlay mới vá mới chỉ có **thuộc tính** — chúng **chưa** có `tabindex="-1"`/`focus()` khi mở và **chưa** có **focus trap**, nên phím Tab vẫn đi xuyên ra sau lớp phủ. `aria-modal="true"` đã đúng về mặt ngữ nghĩa (AT bỏ qua nội dung nền) nhưng trải nghiệm bàn phím chưa trọn. Việc còn lại: trích một composable `useDialog()` (Esc + focus + trap) và áp cho cả 16 chỗ — hiện chỉ `BaseModal`, `CompareSlider`, `GalleryModal`, `ProjectWorkspace` có phần focus/Esc.
- **Badge `cancelled`**: đã vá ở `OutputModule.vue:48` + `store.js:2824`; nửa còn lại (`library.blade:119`) **N/A** cùng file đã xóa.
- **ImageAIService**: `sizeFor()` nhận `$resolution` không dùng · dead code `$base = $base = rtrim(...)` — chưa kiểm lại v3.
- **CreativeDirectionService**: `str_replace` để lại `", "` mồ côi trong negative prompt (:282) · `prompt_prefix/suffix` admin-editable không audit log (:366-367,:385) — chưa kiểm lại v3.
- **GeminiService/ProductAIService**: `generateCreativeDirector()` không clamp `$creativeLevel` ở entry · `attempt()` unknown provider skip im lặng — chưa kiểm lại v3.
- **StylistService `chat()`** :214-268 `break`/`catch` skip mọi key còn lại → `continue 2` — chưa kiểm lại v3.
- **`testApi/testModel`** — relay response provider sống về browser → chỉ trả `{ok,status,message}` — chưa kiểm lại v3.
- **`StudioController` monolith 4.820 dòng** (~90 endpoint trộn HTTP+GD+provider) → extract service. **Nay còn nặng hơn** vì app đã bỏ storefront mà controller vẫn giữ nguyên kích thước.
- **Dead code mới (v3)**: `redirectToFabrikai()` (`StudioController.php:60`, không route gọi, còn `env()`) · `resources/js/app.js` 574 dòng (build ra nhưng 0 view nạp) · `public_html/sw.js` + `manifest.json` (SW không được đăng ký) · toàn bộ storefront PHP.
- **Phình asset (v3)**: `public_html` 32 MB — `samples/` 19 MB · `icons/` 9,6 MB (nhiều file **trùng byte-for-byte** 1.521.739 B) · `favicon.ico` 1,5 MB.

---

## §6 — BỀ MẶT MỚI CHƯA TỪNG ĐƯỢC REVIEW

> Đây là phần **giá trị nhất** của v3: v2 tự nhận "module phủ ~100% diện tích" nhưng thực tế **bỏ sót 9 file** (grep tên file trong cả `STUDIO_REVIEW.md` lẫn `STUDIO_REVIEW_PROGRESS.md` = 0), cộng thêm 7 file + 6 method sinh ra khi tách app.

### 6.1 File SỐNG nhưng chưa từng được nêu tên trong báo cáo cũ

| File | Dòng | Vì sao quan trọng |
|---|---|---|
| `app/Services/VideoAIService.php` | 176 → **181** | Render video catwalk qua DashScope async; gọi từ `routes/web.php:64` → `StudioController:232` `queueGeneration('video',…)` → `Jobs/RenderVideoJob.php:68`. **4 rủi ro — nay chỉ còn 1 mở** (N2 sleep 480s); N1/N3/N4 đã vá, xem sổ xác minh ở §6.3 |
| `app/Services/SuggestLibraryService.php` | 310 | "Thư viện Prompt phân tích" (lưu & tái dùng kết quả Gợi ý từ ảnh); 7 endpoint `/api/suggest-library*` (`routes/web.php:103-109`, `StudioController:1707-1839`); ghi bảng `suggest_results` (`:105`). **1 rủi ro** — §6.3 |
| `app/Http/Controllers/StudioSettingsController.php` | 439 | 14 public method phủ **14 route** `/api/settings-vue/*` (`routes/web.php:129-142`) — *tài liệu ghi "15 route" là SAI, đếm lại bằng `grep -c settings-vue routes/web.php` = 14* — toàn bộ redesign API keys + provider registry |
| `resources/js/studio/components/PromptLibraryTab.vue` | 513 | Component Vue **lớn thứ 6** của module (*sau ConceptCard 893 · StudioApp 755 · LibraryApp 642 · SettingsApp 588 · GalleryModal 525*) |
| `resources/js/studio/components/LayersPanel.vue` | 315 | Panel layer — **nay đã được review nội dung** (`STUDIO_REVIEW_DEEPDIVE.md` §5). ~~Bug clipboard~~ ✅ **đã vá**: `await navigator.clipboard.writeText()` tại `:81`, xem §6.3/N10 |
| `resources/js/studio/components/CanvasStatusBar.vue` | **121** | **[BỔ SUNG 2026-09-17]** Thanh trạng thái canvas — file này **chưa từng được nêu tên ở BẤT KỲ tài liệu nào** (grep cả 4 file = 0) ⇒ vẫn là điểm mù |
| `app/Http/Controllers/AuthController.php` | **86** | **[BỔ SUNG]** Đăng nhập/đăng ký + throttle — chưa từng nêu ở 3 tài liệu cũ; nay có bằng chứng trong DEEPDIVE §5.1 (đăng ký ⇒ role `customer` ⇒ 403 toàn bộ API) |
| `resources/js/studio/components/SourcePanel.vue` | **41** | **[BỔ SUNG]** Được **import nhưng không render ở đâu** ⇒ trên màn <1024px không có đường mở "Nguồn ảnh" (DEEPDIVE §5.2) |
| `resources/js/studio/components/SuggestLibraryCard.vue` | 243 | Card thư viện prompt |
| `resources/js/studio/components/StylistDataManager.vue` | 180 | Quản lý dữ liệu trợ lý thiết kế |
| `resources/js/studio/libraryLayout.js` | 31 | Layout thư viện |
| `resources/js/studio/StylistDataApp.vue` | 15 | Entry SPA `/stylist-data` |

### 6.2 Sinh ra khi tách app (7 file + 6 method)

- **File mới:** `PatternCard.vue` · `TryOnCard.vue` · `PresetsApp.vue` (+ `presets.js`) · `boot.js` · `main.js` · `views/studio/index.blade.php`.
- **Method mới trong `StudioController`**: ~~`redirectToFabrikai()` :60~~ ✅ **ĐÃ XOÁ @`23c12cb`** (cùng `resources/js/app.js`, T8) · `appIndex()` :68 · `settingsPage()` :73 · `presetsPage()` :75 · `stylistDataPage()` :77 · `boot()` :42 (public, `routes/web.php:163`).
- **Method bị bỏ:** `api` · `index` · `library` · `libraryRedirect` · `patternPage` · `settings` · `settingsVue` · `studioVue` · `tryonPage` (160 tên hàm cũ → 157 tên mới).

### 6.3 Rủi ro cụ thể tìm được ở bề mặt mới

> **📌 TÁI XÁC MINH 2026-09-17 @`eee1bba` (bảng dưới là trạng thái 2026-09-16):** chỉ **N2** và **N6** còn mở; 8 mục còn lại đã vá hoặc không còn áp dụng:
> N1 ✅ vá @`62ce15c` (nay `VideoAIService.php:136` dùng `studio_fetch_remote_bytes($url, 209715200)`; helper `helpers.php:318` chặn scheme + timeout 30s + cap 200 MiB — *lưu ý allowlist host chỉ áp khi có setting `remote_image_hosts`*) · N2 ⬜ **CÒN MỞ** (`:99` `+480`, `:110` `sleep(5)`) · N3 ✅ vá @`953ca5c` (`samples/studio-catwalk.mp4` 168.530 B tồn tại thật) · N4 ✅ vá @`4c47e5a` (`RenderVideoJob.php:104` dùng `studio_generation_error()`) · N5 ✅ vá @`4c47e5a` (`SuggestLibraryService.php:271` → `studio_safe_public_file()`) · N6 ⬜ **CÒN MỞ** (4 `count()` trong mọi `list()`) · N7 ✅ vá @`62ce15c` (`StudioController.php:2700` chỉ `savePreset()` khi `auth()->check()` + `throttle:60,1`) · N8 ✅ **đã xoá** @`23c12cb` · N9 ✅ **đổi bản chất**: PWA đã gỡ hẳn @T5 (`sw.js`/`manifest.json` không còn), `main.js:9` chỉ gọi `killLegacyServiceWorker()` — *giữ có chủ đích, xem `pageBoot.js:20-22`* · N10 ✅ vá @`62ce15c` (`await` tại `LayersPanel.vue:81`).
>
> **⚠️ CẢNH BÁO TRÙNG KÝ HIỆU:** dãy **N** ở §6.3 này **xung đột** với dãy N dùng trong `STUDIO_REVIEW_PROGRESS.md` §3bis và trong comment code. Cùng ký hiệu, khác nội dung: §6.3 N2/N3/N8/N9/N10 = PROGRESS N11/N9/N3/N4/N12. **Khi trích dẫn phải ghi rõ "N… của §6.3" hay "N… của PROGRESS §3bis".**


| # | Vị trí | Vấn đề |
|---|---|---|
| N1 | `VideoAIService.php:131` | `$contents = @file_get_contents($url);` tải video — **không** Http client, không timeout, không allowlist host/scheme, không cap dung lượng. Lệch hẳn chuẩn S3 đã áp cho ảnh. → SSRF + treo PHP-FPM + đầy disk |
| N2 | `VideoAIService.php:95,106` | `$deadline = microtime(true) + 480;` + `sleep(5)` trong `while` → chiếm worker tới **8 phút** (cùng họ §5.2 với `ImageAIService::callDashscopeAsync`) |
| N3 | `VideoAIService.php:43` | Stub `return '/samples/studio-catwalk.mp4';` nhưng `ls public_html/samples/*.mp4` = **No such file or directory** → khi chưa cấu hình key, generation báo `completed` với `media_url` **404** |
| N4 | `VideoAIService.php:85,112` | `throw new \RuntimeException('DashScope video ('.$submit->status().'): '.Str::limit($body, 240));` → `RenderVideoJob.php:104` ghi vào `error` của Generation → **hiển thị cho user** |
| N5 | `SuggestLibraryService.php:261` | `resolveLocalImage()` **không containment realpath** (`../` đi qua), khác helper `studio_safe_public_file()` đã vá ở S1 |
| N6 | `SuggestLibraryService.php:85` | `counts()` = 4 COUNT riêng, gọi trong **mọi** `list()` (`:78`) → 5 query mỗi lần tải thư viện |
| N7 | `routes/web.php:167-173` | Nhóm public chứa `POST /api/stylist/cluster` và `POST /api/stylist/prompt`; **`stylistPrompt()` GHI DB** qua `StylistCatalog::savePreset()` (`StudioController.php:2664`) → **endpoint ghi không cần đăng nhập**. Có từ bản cũ nhưng v2 mô tả nhóm public là "read-only" (sai) nên chưa bao giờ được vá |
| N8 | `StudioController.php:60-63` | `redirectToFabrikai()` — **không route nào gọi** (`grep redirectToFabrikai routes/web.php` = 0) và gọi `env()` trong code ứng dụng (`env('FABRIKAI_URL','http://localhost:5173')`) → code chết, vỡ nếu `config:cache` |
| N9 | `main.js:7-16` + `resources/js/app.js:570-573` + `public_html/sw.js` | SW bị unregister ở mọi lần tải + không nơi nào đăng ký lại → PWA chết |
| N10 | `resources/js/studio/components/LayersPanel.vue:80` | Site **mới** cùng họ bug clipboard-không-await (đã có ở `GalleryModal.vue:175,182`): `try { navigator.clipboard.writeText(c); store.toast('Đã chọn màu ' + c); } catch (e) {…}` → toast thành công **giả** khi bị chặn quyền/insecure context |

---

## §7 — BÀI HỌC QUY TRÌNH (bổ sung v3)

1. **Tách app mà không port test = mất lưới an toàn.** 199 test method nằm lại trong git object của repo cũ; app mới có `phpunit.xml` trỏ vào thư mục không tồn tại. Việc tách phải là **một commit** gồm cả `tests/`, nếu không thì "baseline xanh" chỉ còn là ký ức.
2. **Không commit việc di chuyển = lịch sử không đọc được.** 176 file bị xóa ở TrillfaShop nhưng **0 commit** ghi lại; người sau chỉ suy ra được nhờ `useStudioThumb.js:2` và `FABRIKAI_URL` — cả hai đều **chưa commit**.
3. **Báo cáo tự nhận "phủ ~100%" vẫn sót 9 file.** Cách duy nhất phát hiện: `grep <tên file> STUDIO_REVIEW*.md` cho **từng** file sống, thay vì tin vào bảng "đã phủ".
4. **Đổi tiền tố URL là thay đổi cơ học dễ sót** — lần này may là sạch (`fetch('/studio'` = 0), nhưng phải grep lại cả 3 dạng: `fetch('/studio`, `route('studio.`, `href="/studio`.
5. **Đừng tin số dòng trong báo cáo cũ** (4.732 vs 4.911 vs 4.820). Luôn `wc -l` lại trước khi ghi.
6. **"Đã vá" phải kiểm chứng lại sau khi chuyển repo.** Lần này may mắn: `helpers.php` khác bản cũ **đúng 3 dòng URL**, `config/studio.php` **giống hệt** → toàn bộ vá bảo mật §2 đã đi theo. Nhưng `SuggestLibraryService` cho thấy **một resolver thứ hai** không đi qua helper chung — mẫu hình "bản sao lệch chuẩn" vẫn tái diễn.
7. **Log cũ mang theo repo mới gây nhiễu chẩn đoán**: 6.220/10.658 dòng nhắc đường dẫn TrillfaShop. Kiểm tra provenance của log trước khi kết luận.
9. **Rủi ro lớn nhất không nằm ở code mà ở DỮ LIỆU SEED.** N18 (mật khẩu super admin hardcode) sống sót qua toàn bộ v2 và v3 — mọi đợt rà soát đều tập trung vào controller/service, không ai đọc `database/seeders/`. Trong khi đó đây là thứ **có quyền cao nhất** và **được commit lên repo public**. Cách phát hiện lần này rất rẻ: đọc `DEPLOY.md` của repo cũ và thấy chính mật khẩu đó được ghi là mật khẩu đăng nhập production. **Bài học: khi repo là PUBLIC, phải grep mật khẩu/khoá literal trong TOÀN BỘ cây, kể cả seeder/migration/fixture/test — không chỉ `app/`.** Kèm một cái bẫy phụ: `updateOrCreate` + mật khẩu hardcode nghĩa là **mọi lần seed lại đều reset** mật khẩu người vận hành đã đổi — lỗi này chỉ lộ ra khi chạy seeder LẦN THỨ HAI.
10. **Cây làm việc hỏng không tự báo.** Repo cũ để lại 210 test / **128 error** (module đã xoá, test còn nguyên) suốt 5 ngày mà không ai thấy, vì **không ai chạy test ở repo đó nữa**. Trước khi commit một đợt di chuyển lớn, phải chạy bộ test của **repo bị bỏ lại** — không chỉ repo mới. Phân loại lỗi theo file trước khi kết luận: ở đây 100% lỗi thuộc module đã đi, 0 lỗi storefront, nên cách xử lý là xoá test của module đã đi chứ không phải đi sửa storefront.
8. **Tối ưu hiệu năng TỰ SINH lớp bug mới — phải test luôn đường VÔ HIỆU cache, không chỉ đường ĐỌC cache.** Vòng 20 gộp 52 query settings thành **một khoá cache cho cả bảng** (75 → 9 query). Vòng 21 phát hiện: mọi đường ghi **không đi qua `Setting::set()`** giờ đều để lại **dữ liệu cũ vô thời hạn** — cụ thể là `DatabaseSeeder`, tức thao tác *re-seed trên máy chủ đang chạy*. **Quy tắc rút ra:** khi gộp cache từ "theo key" sang "cả bảng", phải `grep` **toàn bộ** đường ghi vào bảng đó, không chỉ đường ghi chính. Cùng lớp với bài học "bản sao lệch chuẩn" ở mục 6 — chỉ khác là lần này bản sao nằm ở **đường GHI** chứ không phải đường đọc.

---

## §8 — LỊCH SỬ & ÁNH XẠ

### 8.1 Vòng đời tài liệu
| Bản | Ngày | Neo vào |
|---|---|---|
| v1 | 2026-09-07 | Báo cáo gốc 995 dòng (Phần A–L) trong TrillfaShop |
| v2 | 2026-09-07 | Bản gộp §0–§7 (263 dòng) — TrillfaShop, prefix `/studio` |
| **v3** | **2026-09-16** | **FabrikAI (app tách riêng), prefix `/api`, sau 43 commit chưa ghi sổ** |

Nguyên bản cũ: `git -C /home/anhtuan/DEV/TrillfaShop show 8817f85:STUDIO_REVIEW.md` (Phần A–K.8) · `git show 05baac8:STUDIO_REVIEW.md` (bản v2).

### 8.2 43 commit chưa từng được ghi vào sổ (2026-09-08 → 2026-09-11)

Gom theo chủ đề (chi tiết từng commit ở `STUDIO_REVIEW_PROGRESS.md` §"Đợt chưa ghi sổ"):

- **Thư viện Prompt phân tích** (mới): `b22fe2e` — lưu & tái dùng kết quả "Gợi ý từ ảnh" → `SuggestLibraryService` + `SuggestLibraryCard.vue` + `PromptLibraryTab.vue` (7 endpoint) · `6e92438` + `11ac3d0` tinh chỉnh (xóa nhanh prompt, xóa nhanh file tải lên).
- **Prompt Tạo Ảnh**: `4bf7b6e` Gieo quẻ (seed) · `ee5d40b` chuyển seed ra tab Prompt · `33370be` tab Tư thế · `02f0426` + `0258f35` + `3aad4b4` + `34b73e3` Prompt Prefix/Suffix + ghi nhớ localStorage · `5643fd2`→`e561810` redesign popup (header/tab cố định, body cuộn, bỏ emoji → SVG icon).
- **Gợi ý từ ảnh**: `a35af7b` checkbox bỏ qua phân tích · `6289a4e`→`067188a` nút "Tạo ảnh ngay" (popup xác nhận) · `c1b8ddf` bump SW cache v3→v4.
- **Canvas/layer**: `8142388` + `1ad52bc` ContextToolbar cho công cụ Lựa chọn + Pan (tablet), gộp MultiSelectBar vào ContextToolbar · `96005df` + `fb02da2` + `8c18f79` popup xác nhận dọn canvas, icon đồng bộ · `55ccc0c` fix `clearTimeout is not a function`.
- **Cài đặt**: `a1f01f8` redesign API keys + custom provider registry + Vue SPA settings (`StudioSettingsController`, `StudioProvider`, migration) · `f30755f` task-group model assignment · `4947177` fix TypeError shadowing.
- **Khác**: `900f547` `studio_config()` bỏ qua empty string từ DB · `565c1a8` preview-enrich nhận body/hair · `e9ed8e5` khôi phục `settings.blade.php` bị cắt 184 dòng · `e5175c6`/`1b804ed` bo góc VSCode toàn diện · `b3327d1` chuyển `/studio/library` vào SPA · `6e16472` fix 500 image-thumb + popup không hiển thị · `d674560` fix cross-world SW mismatch · `d6572a1` render ProjectWorkspace popup · `14eee6b`/`2249124` tách 2 nút Prompt & Trợ lý thiết kế.

---

## §9 — DEPLOY PRODUCTION: fabrikai.shop (Hostinger shared hosting)

> Trạng thái 2026-09-17: **code đã lên, chỉ còn thiếu DATABASE.**

| | |
|---|---|
| URL | https://fabrikai.shop |
| Thư mục app | `/home/u310846799/domains/fabrikai.shop` |
| Web root | `public_html` |
| SSH | `ssh -p 65002 u310846799@145.79.25.57` |
| PHP | 8.3.33 · Composer 2.9.8 · **KHÔNG có node/npm** |

### 9.1 Đã xong

| Bước | Bằng chứng |
|---|---|
| Đưa code lên | `git clone --depth 1` (repo public) vào thẳng thư mục domain |
| Dependencies | `composer install --no-dev --optimize-autoloader` → vendor 71 MB, `php artisan --version` = Laravel 13.26.1 |
| `.env` production | Đã tạo, `chmod 600`, `key:generate` xong. `APP_DEBUG=false` · `APP_ENV=production` · `SESSION_SECURE_COOKIE=true` · `STUDIO_QUEUE_WORKER=true` |
| Symlink storage | `ln -s ../storage/app/public public_html/storage` (bằng SHELL — xem 9.3) |
| Quyền ghi | `storage/` + `bootstrap/cache/` đã `chmod -R 775` |
| Health check | `https://fabrikai.shop/up` → **200** |
| Asset tĩnh | `/build/manifest.json` 200 · CSS 136 KB 200 · JS 566 KB 200 · `/icons/studio-favicon-32.png` 200 · `/favicon.ico` 200 |
| Trang lỗi | `/ ` trả **500 bằng blade của app** (không rò stack trace) — chứng minh `APP_DEBUG=false` có tác dụng |
| Cron | Cron `maychuan-queue-worker` của domain khác đang chạy; cron FabrikAI chưa thấy log |

### 9.2 Đang chờ: database

Site 500 vì **chưa có database**. Thông tin cần: tên DB · user · mật khẩu (Hostinger luôn có tiền tố `u310846799_`).

Đã kiểm tra: user MySQL hiện có (`u310846799_atd`) **chỉ** được cấp quyền trên `u310846799_trillfa` và
**không thể tạo database mới** ⇒ bắt buộc tạo trong hPanel → Databases.

**Không dùng lại DB cũ:** nó chứa bảng + `migrations` + tài khoản của app thương mại điện tử cũ — đúng thứ vừa
được gỡ sạch. Sau khi có DB mới, chạy:

```bash
cd ~/domains/fabrikai.shop
# 1) điền DB_* vào .env (bỏ 3 dòng CHUA_TAO_DB)
php artisan migrate --force
php artisan db:seed --force        # tạo owner@fabrikai.shop + admin@fabrikai.shop
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up                      # đang ở maintenance mode
```

### 9.3 Ba cái bẫy của host này (đã gặp thật, không phải suy đoán)

1. **PHP bị chặn `symlink`** (`disable_functions` = `system, exec, shell_exec, passthru, popen, proc_open, symlink, link, …`).
   ⇒ `php artisan storage:link` **thất bại**. Phải tạo symlink bằng shell SSH — shell KHÔNG bị chặn, chỉ PHP bị.
2. **Không có `crontab` CLI** ⇒ cron phải tạo trong hPanel. Và shared hosting **không chạy daemon**
   ⇒ worker phải là cron `queue:work --stop-when-empty --max-time=55` mỗi phút (chạy hết việc rồi thoát).
   `--stop-when-empty` là cờ quan trọng nhất: thiếu nó tiến trình treo và host sẽ giết.
3. **`php artisan about` lỗi** ("The Process class relies on proc_open") — chỉ là lệnh đó cần `proc_open`,
   app không hỏng. `package:discover` cũng có thể lỗi trong `composer install`; chạy tay một lần là xong.


### 9.4 Ba bug CHỈ lộ ra ở production (đã vá, đã verify lại bằng chạy thật)

Cả ba đều KHÔNG bị bộ test bắt — vì test chạy cấu hình khác production. Đây là giá trị thật của việc
deploy và tự tay gọi endpoint, không chỉ tin vào 293 test xanh.

| # | Bug | Vì sao test không bắt được | Bằng chứng |
|---|---|---|---|
| **P1** | **Cache Eloquent Collection ⇒ 500 ở MỌI lần gọi thứ hai.** `studio_models()` và `studio_models_enabled_by_group()` cache thẳng kết quả `->get()`. Laravel không hỗ trợ cache model/collection: ghi thì được, đọc lại ném *"The script tried to call a method on an incomplete object … Illuminate\Database\Eloquent\Collection … unserialize()"*. | `phpunit.xml` đặt `CACHE_STORE=array` — store array giữ giá trị trong BỘ NHỚ, **không serialize**, nên vòng ghi→đọc không bao giờ chạm `unserialize()`. | `GET /api/defaults` trả **500**; log production chỉ đúng `StudioController->defaults()`. Sau vá: gọi 3 lần liên tiếp đều **200** (8349b). |
| **P2** | **Job chỉ được enqueue lúc client POLL.** Nếu người dùng tạo ảnh rồi đóng tab ngay thì không ai poll ⇒ generation nằm `pending` VĨNH VIỄN trong khi **credit đã bị trừ**. Bộ "heal" chỉ xử lý `processing`, không đụng tới `pending`. | Test nào cũng poll sau khi tạo, nên không bao giờ dựng lại kịch bản "tạo rồi bỏ đi". | Chạy thật: tạo generation xong bảng `jobs` vẫn **rỗng** cho tới khi có request poll. Sau vá: `jobs=1` NGAY sau khi tạo, chưa poll lần nào. |
| **P3** | **Bật cờ queue mà máy chủ không có worker ⇒ kẹt vĩnh viễn.** Cờ `STUDIO_QUEUE_WORKER=true` nhưng cron chưa được tạo thì job nằm mãi trong bảng `jobs`. | Đây là lỗi VẬN HÀNH, không phải lỗi code — test không thể biết máy chủ có cron hay không. | `storage/logs/worker.log` **không tồn tại**; `~/.logs/` chỉ có cron của domain khác (`maychuan-queue-worker`). |

**Cách vá P2 + P3 (gộp trong một thiết kế):** thêm helper dùng chung `studio_dispatch_generation()` (chọn job theo loại + chốt chống dội queue `Cache::add` 10 phút), gọi từ **`Generation::booted()` → `created()`** — đặt ở model event vì có ~8 đường tạo generation, sửa từng controller là đúng mẫu lỗi "vá một nơi, quên chỗ tương đương". Và trong `show()` thêm **lưới an toàn queue-first/inline-fallback**: quá `QUEUE_FALLBACK_SECONDS = 90` mà vẫn `pending` thì tự xử lý inline (90 > chu kỳ cron 1 phút, nên khi CÓ worker thật thì nhánh này không bao giờ chạy).

### 9.5 Trạng thái LIVE (verify 2026-09-17)

| Hạng mục | Kết quả |
|---|---|
| `/up` (health) | **200** |
| `/ · /dang-nhap · /dang-ky · /settings · /presets · /stylist-data` | **200** cả 6 |
| Đăng nhập thật (CSRF + session + auth) | POST 302 → `/ ; boot payload có `"email":"admin@fabrikai.shop" is_admin:true` |
| API: `/api/defaults ×3 · /api/projects · /api/library/data · /api/settings-vue/data · /api/presets · /api/ref-images · /api/assets · /api/prompt-history` | **200** toàn bộ |
| Tạo ảnh đầu-cuối | POST /api/generate → job vào queue → worker chạy → `status=completed` + ảnh JPEG 157 KB trên đĩa |
| Ảnh phục vụ qua web | `/storage/studio/<uuid>.jpg` → **200 · image/jpeg** |
| Lưới an toàn (không chạy worker tay) | generation #3: pending → pending → **completed ở t=95s** |

### 9.6 Hai điều cần biết về hạ tầng (không phải bug)

1. **Hostinger CDN (`server: hcdn`, `x-hcdn-cache-status: HIT`) tự tối ưu ảnh trên đường ra.** File gốc trên đĩa 157.184 byte, ảnh phục vụ qua web 97.995 byte (960×1280) — **cùng một URL nhưng nội dung đã được CDN mã hoá lại**, cache 7 ngày. An toàn cho app này vì tên file sinh ra là UUID duy nhất mỗi generation, nên không có chuyện CDN trả ảnh cũ. Nếu sau này có chỗ ghi đè ảnh trên cùng một tên file thì phải cache-bust.
2. **Chưa có cron worker cho FabrikAI.** App vẫn chạy được nhờ lưới an toàn ở 9.4 (chậm hơn ~90 giây và request xử lý inline có thể dài). Tạo cron trong hPanel sẽ bỏ được độ trễ đó: `cd /home/u310846799/domains/fabrikai.shop && /usr/bin/php artisan queue:work --stop-when-empty --max-time=55 --tries=1 --timeout=900 >> storage/logs/worker.log 2>&1`, lịch mỗi phút. Kiểm chứng cron đã chạy: file `storage/logs/worker.log` xuất hiện.


### 9.7 Đã chuyển sang MySQL (2026-09-17, cùng ngày)

Người dùng tạo DB trong hPanel: `u310846799_fabrik_ai` / user `u310846799_anh_tuan`.
Đã kiểm: kết nối được cả socket `/tmp/mysql.sock` và TCP, MariaDB 11.8.9, ALL PRIVILEGES trên DB đó,
và DB **rỗng** trước khi migrate.

| Bước | Kết quả |
|---|---|
| `migrate --force` trên MySQL | **25 bảng**, `show tables | grep -i "product\|order\|cart\|coupon\|post\|banner\|wishlist\|review\|menu_items\|custom_page"` = **rỗng** |
| `db:seed --force` | `owner@fabrikai.shop` (super_admin) · `admin@fabrikai.shop` (admin), cả hai `email_verified` |
| Verify lại toàn bộ | 7 trang **200** · đăng nhập thật **302** → boot có `role:super_admin` · 10 lượt API **200** · tạo ảnh đầu-cuối **completed** · ảnh qua web **200 · image/jpeg** |
| Cache production | `config:cache` (25 KB) + `route:cache` (169 KB) + `view:cache` (32 view) — **đã bật** |
| Dọn dẹp | SQLite tạm + 3 ảnh mồ côi của thời SQLite đã xoá; ảnh trên đĩa nay khớp **1-1** với generation trong DB |

**Đổi `LOG_LEVEL` từ `error` → `warning`.** Phát hiện khi test: cảnh báo lưới an toàn dùng
`logger()->warning()` nên bị `LOG_LEVEL=error` **lọc mất** — tức là cơ chế báo động vận hành im lặng đúng lúc cần nó nhất.
Sau khi đổi, log đã ghi đúng: *"Không có queue worker xử lý generation #2 sau 90s — chuyển sang xử lý inline."*

**Thêm một bug phát hiện nhờ chính lần chuyển DB này** (đã vá, commit `57c4c0e`): lưới an toàn xử lý inline
nhưng KHÔNG dọn job đã enqueue ⇒ **mỗi ảnh để lại một job chết** trong bảng `jobs` vĩnh viễn. Kiểm chứng trên
production: sau generation đầu tiên `jobs=1` với một job không ai nhặt. Nay `studio_drop_queued_generation()` xoá nó.

**Cấu hình production cuối cùng:** `APP_ENV=production` · `APP_DEBUG=false` · `SESSION_SECURE_COOKIE=true` ·
`SESSION_HTTP_ONLY=true` · `SESSION_ENCRYPT=true` · `LOG_LEVEL=warning` · `STUDIO_QUEUE_WORKER=true`
(queue/cache/session đều trên DB).

**Còn lại duy nhất:** cron worker trong hPanel (xem 9.6). Chưa có thì ảnh vẫn xong, chỉ chậm ~90–100 giây do
phải chờ lưới an toàn.

---

## §10 — SỔ XÁC MINH ĐỘC LẬP 2026-09-17 (`eee1bba`)

> Sinh ra từ **6 vòng quét song song** (mỗi vòng read-only, xác minh bằng đọc code/số đo, do điều phối tự đối chứng lại các claim rủi ro cao).
> Quy ước phán quyết: **ĐÓNG** (đã vá/không còn đúng) · **N/A** (đối tượng đã bị gỡ) · **MỘT PHẦN** (còn tiểu mục) · **MỞ** (còn nguyên).

### 10.1 Số liệu chuẩn — xem §0.4. Neo đợt xác minh: `eee1bba` · 47 commit · 127 route · 300 test/1.595 assertion · app/ 47 file/13.777 dòng. **Sau khi vá nhóm lỗi tiền: `66023d7` · 48 commit · 309 test / 1.629 assertion** (xem §10.7).

### 10.2 T1–T17 — tất cả đã hết MỞ
Chi tiết từng mục: `STUDIO_REVIEW_PROGRESS.md` §2. Tóm tắt: 15 **ĐÓNG** · 1 **N/A** (T7) · 1 **ĐÓNG một phần có chủ đích** (T9) · 2 **còn phần dở** (T3: `sleep(5)`; T10: chưa đo được vì §5.1 lỗi thời).

### 10.3 §5.1 Medium (M01–M22) — 10 ĐÓNG · 2 N/A · 9 MỘT PHẦN · 1 MỞ NGUYÊN

| Mục | Phán quyết | Bằng chứng hiện tại |
|---|---|---|
| M01 swapModel/SwapModelJob | **N/A** | cả hai **đã xoá** ở `eee1bba` |
| M02 reconcile không CAS | **ĐÓNG** | CAS tại `StudioController.php:1500-1518` (comment "M02"), `failStuck()` `:1312-1324` |
| M03 StylistData check-then-act | **MỘT PHẦN** | **đã vá 422**: `StylistDataController.php:107-109,162-164` qua `studio_is_unique_violation()` (`helpers.php:255`). Còn: `StylistCatalog.php:252,261-263` |
| M04 `api()` không check redirected | **ĐÓNG** | `store.js:331` |
| M05 double-increment page | **ĐÓNG** | `store.js:1123-1132` (comment "M05") |
| **M06 ghép prompt thô** | **MỞ** | `store.js:698-705` → gửi thẳng `:706-715`, không delimiter |
| M07 variants/vision URL | **MỘT PHẦN** | vision URL **ĐÓNG** (allowlist `helpers.php:465-498`); còn client không trần ở `store.js:2741` |
| M08 toast XSS + `app.js` | **ĐÓNG** | `StudioApp.vue:503` `{{ store.flashMsg }}`; `resources/js/app.js` **đã xoá** `23c12cb` |
| M09 heredoc prompt injection | **ĐÓNG** | `StylistService.php:80-89` bọc `<<<USER_PROMPT>>>` + "treat as DATA" |
| M10 N+1 Preset + log | **MỘT PHẦN** | N+1 **ĐÓNG** (`StyleSuggestService.php:336,461`); log vẫn vứt context (`:56,65`) |
| M11 QA trả null âm thầm | **ĐÓNG** | `VirtualTryOnService.php:420-428` `logger()->warning` |
| M12 Gemini non-2xx im lặng | **ĐÓNG** | `GeminiService.php:139-148` có `else` + log |
| M13 `sleep()` trong job | **ĐÓNG** | `ImageAIService.php:630,1112,1147` + cờ queue — nhưng xem **M-b** ở §10.6 |
| M14 rò message provider | **MỘT PHẦN** | `ImageAIService.php:274-286` lọc **chỉ khi** `APP_DEBUG=false` |
| M15 ProductAIService brandContext | **N/A** | file **đã xoá** `729ceb0` |
| M16 Library counts/LIKE/TOCTOU | **MỘT PHẦN** | **đã vá**: escape LIKE `:56` · reject `..` `:327-329`. Còn: 8 query + `scanOrphanFiles()` mỗi request `:105-125`, TOCTOU `:288→292` |
| M17 `studio_assets` không `user_id` | **MỘT PHẦN** | `studio_api_keys_for()` nay `helpers.php:592-609`; filter in-memory **có chủ đích**; plaintext fallback `:626-634` |
| M18 `v-html` SVG | **ĐÓNG** | `RefImageCard.vue:229-236` render primitives |
| M19 download/palette containment | **ĐÓNG** | `StudioController.php:3700,3735` |
| M20 decode fail không log | **MỘT PHẦN** | helper **đã log** `helpers.php:172-179`; còn 2 call-site im lặng `StudioController.php:426,428` và `:2140` |
| M21 SwapCard fetch | **N/A / một nửa** | `SwapCard.vue` **đã xoá** `eee1bba`; nửa SuggestCard đúng |
| M22 `v-html` còn 2 → **1** | **MỘT PHẦN** | nay chỉ `StudioIcon.vue:140`; cảnh báo latent **vẫn đúng** |

### 10.4 §5.2 / §5.3

- **§5.2 nay có 0 mục MỞ** — 2 mục "CÒN MỞ" trỏ `SwapCard.vue` (**đã xoá**). Danh sách "ĐÃ ĐÓNG" **đúng về hành vi**, sai gần hết số dòng. **1 mô tả sai nội dung:** `loadDefaults()` chỉ `console.error`, **không có toast** (`store.js:356-362`).
- **§5.3 sai số liệu:** overlay `role="dialog"` = **19** (không phải 16) · thiếu `tabindex="-1"` = **16** (không phải 12; `grep focus-trap` = **0**) · `public_html` **21 MB**/icons **52 KB**/favicon **15 KB** (không phải 32 MB/9,6 MB/1,5 MB) · monolith **4.644** dòng (không phải 4.820).
- **§5.3 "đã xóa" là SAI SỰ THẬT:** `library.blade.php` và `vue.blade.php` **chưa từng tồn tại** trong repo này (`git log --all` = rỗng) ⇒ phải sửa thành "chưa từng tồn tại", không phải "đã xóa".
- **§5.3 badge `cancelled`**: `OutputModule.vue:48` đúng; `store.js:2824` nay là `addImagesToCanvas` → nhãn ở `store.js:2864`.

### 10.5 N-list (§3 và §6.3) — không còn lỗi nào MỞ NGUYÊN

| Phán quyết | Mục |
|---|---|
| **ĐÓNG (12)** | N1 · N3 · N5 · N8 · N9 · N10 · N12 · N13 · N14 · N15 · N16 · N17 · N18 |
| **MỘT PHẦN (4)** | N2/N11 (sleep chỉ hết hại khi có worker) · N4/§3-N8 (sanitizer chỉ lọc khi `APP_DEBUG=false`) · N7 (write đã chặn, **DDL vẫn công khai**) |
| **MỞ NGUYÊN (1)** | **N6** — `SuggestLibraryService::counts()` = 4 COUNT mỗi `list()` |
| **N/A** | N13 (PWA đã gỡ hẳn `7451d6b`) |

> ⚠️ **TRÙNG KÝ HIỆU N:** §3 (dòng ~210-231) dùng dãy **N1–N18** *khác nội dung* với §6.3 (dòng ~339-352) cũng dùng **N1–N10**. Cùng ký hiệu, khác nghĩa (§6.3-N2 = PROGRESS-N11; §6.3-N3 = PROGRESS-N9; §6.3-N8 = PROGRESS-N3; §6.3-N10 = PROGRESS-N12). **Bắt buộc ghi rõ "N… của §3" hay "N… của §6.3" khi trích dẫn.** Đề xuất: chỉ dùng **một** dãy của `PROGRESS.md` §3bis.

### 10.6 Tham chiếu CHẾT (đã bị gỡ — đừng trích lại)

| Đối tượng | Xoá ở commit |
|---|---|
| `PatternCard.vue` · `TryOnCard.vue` · `SwapCard.vue` · `SwapModelJob.php` (+ `StudioSwapTest.php`) | `eee1bba` |
| `resources/js/app.js` (575 dòng) · `redirectToFabrikai()` | `23c12cb` |
| `public_html/sw.js` · `public_html/manifest.json` (+ 8 icon) | `7451d6b` |
| `ProductAIService.php` · `CartService` · `CheckoutService` · `StorefrontBridge` · `Seo.php` · `AdminProductController.php` · `app/Models/{{Product,Order,Cart,Coupon,Post,…}}` | `729ceb0` |
| `Route::redirect('/studio/library','/')` | `729ceb0` |
| `StudioController::assetSample()` | **chưa từng tồn tại** (chỉ còn comment `routes/web.php:68`) |

**Số dòng đã dịch (đừng tin số cũ trong tài liệu):** `routes/web.php` 27→**29** · 28-30→**30-32** · 34→**35** · 37→**35** · 167→**163** · 176-179→**172-175**; `boot()` 37→**42** · `appIndex()` 69→**68** · `settingsPage()` 74→**73** · `presetsPage()` 76→**75** · `stylistDataPage()` 78→**77**; `LayersPanel.vue` 80→**81** · `GalleryModal.vue` 175,182→**190,197**; `helpers.php` 248/257→**172/160**; `StudioController.php` 3477→**3362**; `store.js` nhiều mục lệch; `StudioController.php` 3116/3154→**3154/3193**; 1711/1818→**1744/1853**.

### 10.7 VIỆC MỚI phát hiện trong đợt này (chưa từng có trong tài liệu)

| # | Việc | Bằng chứng | Mức |
|---|---|---|---|
| ~~**M-c**~~ ✅ **ĐÃ VÁ `66023d7`** — mọi đường hoàn tiền nay đi qua `studio_finalize_generation()` (CAS duy nhất); `studio:process` cũng gộp vào. *(Bản gốc: *Hoàn tiền KHÔNG CAS ở `cancel()`* ⇒ cancel đua với `failStuck()`/`reconcile` ⇒ **hoàn 2 lần** | `StudioController.php:1470-1478` (CAS đã có ở `:1317-1323`, `:1510-1516`, `ProcessStudioGenerations.php:44-57`) | **cao (tiền)** |
| ~~**M-d**~~ ✅ **ĐÃ VÁ `66023d7`** — job dùng `studio_claim_generation()`; row đã rời `processing` ⇒ kết quả bị BỎ (có log). *(Bản gốc: *Job ghi trạng thái cuối vô điều kiện* ⇒ "hồi sinh" row đã cancel + hoàn lần 2 | `RenderImageJob.php:108-126,133-135,167-172` · `RenderVideoJob.php:79-97,103-106` | **cao (tiền)** |
| **M-b** | **Inline giữ `sleep()` trong request** (`queue_worker` default **false**) | `config/studio.php:134` · `StudioController.php:4467` · `VideoAIService.php:99,110` | **cao** nếu chưa có cron worker |
| **M-a** | `GET /api/stylist-data/data` **public + chạy DDL** | `StylistDataController.php:26` → `StylistCatalog.php:106` | vừa |
| ~~**M-h**~~ ✅ **ĐÃ VÁ `66023d7`** — trừ credit + tạo row trong cùng `DB::transaction`; create lỗi ⇒ rollback. *(Bản gốc: *Trừ credit **trước** khi tạo row* ⇒ create lỗi là mất credit, không hoàn | `StudioController.php:1597` → `:1620` | vừa |
| **M-e** | `counts()` / `StudioLibraryService` **không nằm trong test ngân sách query** | `StudioQueryBudgetTest.php:63-71` | vừa |
| **M-g** | `variantCount` khôi phục từ localStorage **không clamp** ⇒ 422 khi Tạo Ảnh | `store.js:2741` ↔ `StudioController.php:98` | vừa |
| **M-i** | `onBeforeUnmount` thiếu gỡ 1 trong 4 keydown listener | `StudioApp.vue:83` vs `:84` | thấp |
| **M-f** | Comment code còn mô tả tính năng đã gỡ (`"swap : SwapCard"`) | `helpers.php:1205` | thấp |
| **M-j** | **3 file bị bỏ sót mọi tài liệu**: `CanvasStatusBar.vue` (121), `AuthController.php` (86), `SourcePanel.vue` (41 — **import nhưng không render**, chặn "Nguồn ảnh" trên mobile) | grep cả 4 tài liệu = 0 | vừa |


