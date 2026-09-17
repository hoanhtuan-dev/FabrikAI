# STUDIO REVIEW — LEDGER TIẾN ĐỘ (bộ nhớ bền bỉ) · v3 · 2026-09-16

> **File này là trạng thái, không phải quy trình.** Quy trình ở `DELEGATION_PLAYBOOK.md` (TrillfaShop).
> Mỗi goal round / session mới: đọc file này TRƯỚC, làm việc, rồi cập nhật lại nó.
> Báo cáo đầy đủ (findings, bằng chứng từng dòng) ở **`STUDIO_REVIEW.md` v3**.
> **BẢN CHUẨN (chốt 2026-09-17):** `/home/anhtuan/DEV/FabrikAI/STUDIO_REVIEW_PROGRESS.md` là bản duy nhất có thẩm quyền (module studio đã rời TrillfaShop ở `f35704f`). Bản gương ở repo cũ đã lệch và **không** được cập nhật tự động.

---

## 1. TRẠNG THÁI HIỆN TẠI — **LUÔN ĐO LẠI BẰNG `bash scripts/measure.sh`**

> **QUY ƯỚC TÀI LIỆU (chốt 2026-09-17 — lý do ở `STUDIO_REVIEW_PLAN.md` §6):**
> - **File này = SỔ TRẠNG THÁI DUY NHẤT.** Chỉ sửa file này khi đóng một việc.
> - **`STUDIO_REVIEW_PLAN.md` = BẢNG VIỆC DUY NHẤT** (lộ trình 4 đợt, có DoD).
> - `STUDIO_REVIEW.md` · `STUDIO_REVIEW_DEEPDIVE.md` · `STUDIO_REVIEW_PRODUCT.md` = **KHO BẰNG CHỨNG LỊCH SỬ**.
>   Đọc để tra cứu; **KHÔNG trích số trực tiếp** (cả 3 đã lệch neo ít nhất một lần, có file tự mâu thuẫn).
> - Mở **mỗi vòng** bằng `bash scripts/measure.sh` rồi dán output vào đây.

**Neo đo 2026-09-17 (commit `93dfd86` — chạy `scripts/measure.sh`):**
`HEAD 93dfd86` · **50 commit** · **127 route** · **309 test / 1.629 assertion XANH · 35 file test** ·
PHP `app/` **47 file / 13.839 dòng** · `app/Services` 11 · `app/Models` 17 · migrations 27 ·
JS/Vue studio **42 file (33 .vue) / 12.828 dòng** · `public_html` **21 MB** ·
**production `fabrikai.shop` ĐANG CHẠY** (MySQL, 25 bảng).
Điểm nóng monolith: `StudioController.php` **4.643** · `store.js` **3.987** · `helpers.php` **1.610**.

### Tiến độ THỰC THI kế hoạch nâng cấp (STUDIO_REVIEW_PLAN.md)

| # | Việc | Trạng thái | Commit / sổ | Ghi chú |
|---|---|---|---|---|
| 0.9 | scripts/measure.sh | ✅ XONG | `93dfd86` | nguồn số liệu chuẩn cho mọi vòng |
| 0.1 | Mở studio cho customer (quyền hẹp) + banner 3 trạng thái | ✅ XONG | `4703b58` + `ce55a7d` | tách route 2 nhóm; 338 test |
| 0.1b | Scope ảnh ref/uploads theo user | ⬜ CHƯA | — | 4 endpoint (/uploads*, /ref-images*) TẠM để ADMIN vì thư mục ref là kho chung — phải có cột user_id rồi mới chuyển về STUDIO. **Ưu tiên** vì khách cần xem/liệt kê ảnh tham chiếu của mình |
| 0.2 | Tiến trình thật (bỏ mô phỏng) | ✅ XONG | `46a9ae2` | bỏ bộ đếm lặp cộng % ngẫu nhiên ở generateImage; % nay suy từ trạng thái THẬT qua `syncBatchProgress()`; generateImage còn thiếu `pollGeneration` nên thumbnail kẹt "Đang chờ" tới khi F5 — đã thêm |
| 0.3 | Nói thật chế độ DEMO | ✅ XONG | `9833c58` | thêm cột `generations.is_demo` + `demo_reason`; `ImageAIService::lastStubReason()` ghi khi rơi vào `copySample` (ảnh mẫu **hoặc chính ảnh gốc** ở đường EDIT) rồi reset mỗi lần gọi; job ghi cờ xuống DB; `show`/`latest` trả ra UI; lưới Kết quả gắn nhãn DEMO + banner |
| 0.4 | Nút 1K/2K + tỉ lệ trung thực | ✅ XONG | `6235e93` | `sizeFor()` nhận `$resolution` mà **không dùng** ⇒ 1K/2K ra cùng một ảnh; `4:5`/`21:9` bị đổi âm thầm thành `3:4`/`16:9`. Nay `normalizeOutputSize()` cắt giữa về ĐÚNG tỉ lệ rồi hạ cạnh dài về 1024/2048, **không phóng to**; chỉ áp cho đường TẠO, đường SỬA giữ nguyên kích thước ảnh gốc |
| 0.5 | Mobile dùng được | ✅ XONG | `0afec4e`+`b1e0d94` | drawer "Kết quả" trước đây RỖNG (OutputModule đã import nhưng không render) — nay render lưới kết quả; "Nguồn ảnh"+"Thư viện" trước chỉ ở rail `hidden lg:flex` — nay thêm vào drawer menu mobile; gỡ import chết SourcePanel/LibraryCard; bảng dự án thêm overflow-x-auto |
| 0.6 | Dư lượng UI chết | ✅ XONG | `10d142b` | Gỡ 2 nút cài PWA + 4 hàm/ref + 2 listener (Q4 bỏ PWA). **GHI CHÚ**: `store.step` bị liệt là "chết" nhưng THỰC TẾ là tín hiệu SỐNG nối activeActivity ↔ GalleryModal/LayersPanel — giữ nguyên |
| 0.7 | a11y focus trap | ✅ XONG | `b93ad2b` | Thêm `useFocusTrap` composable (giữ Tab vòng trong hộp thoại + trả focus khi đóng + gỡ listener khi unmount); nối vào BaseModal (đế chung 5 component) + ProjectWorkspace. 14/19 lớp phủ còn lại có role=dialog nhưng chưa trap — ghi nhận cho vòng sau |
| 0.8 | 3 nợ bảo mật vừa | ✅ XONG | `54bbf2f` | (a) cấm DDL trên GET công khai — `ensureTables` chỉ còn ở đường GHI; thêm migration `stylist_presets`. (b) `counts()` gom 4 COUNT → 1 aggregate. (c) `studio_sanitize_error()` lọc path/SQL kể cả khi debug |

### ĐỢT 1 — Giá trị rời khỏi app

| # | Việc | Trạng thái | Commit | Ghi chú |
|---|---|---|---|---|
| 1.1 | `Shot.state` + `is_selected` | ✅ XONG | `1763714` | thêm `shot_state` (idea→drafted→selected→fitted→campaign_ready→approved·rejected) + `is_selected`/`note`/`sort`; whitelist chặn nhảy cóc; chỉ owner/admin; `show()`/`latest()` trả ra cho UI |
| 1.2 | `Run` (lô) | ⬜ CHƯA | — | bảng `runs` + API; lô N ảnh = 1 lô, trừ credit cả lô 1 lần, resume không trừ 2 lần |
| 1.3 | `ExportBundle` | ⬜ CHƯA | — | zip `{sku}-{channel}-{n}.jpg` theo preset kênh + caption.txt |
| 1.4 | Channel Validator | ⬜ CHƯA | — | pre-flight: cạnh ≥ ngưỡng · dung lượng · nền #FFFFFF · watermark · sản phẩm ≥70% khung |
| 1.5 | Thông báo khi render xong | ✅ XONG | `745832b` | `GenerationCompleted` (mail, nói thật khi ảnh DEMO); `RenderImageJob` + `RenderVideoJob` notify SAU guard CAS ⇒ đúng 1 thông báo/generation, chạy lại job không spam |
| 1.6 | Mô tả + caption + hashtag | ⬜ CHƯA | — | XÂY MỚI (ProductAIService đã bị gỡ ở 729ceb0) |
| 1.7 | `prompt_templates` | ✅ XONG | `f44f6c0` | bảng `prompt_templates` + `studio_prompt_template()` (chọn version cao nhất active, placeholder, fallback); `garment.lock` ở StudioController nay resolve từ DB, chuỗi cũ giữ làm fallback |

### XÁC MINH CHÉO 2026-09-17 (đối chiếu việc song song)

| Phát hiện | Trạng thái | Commit | Ghi chú |
|---|---|---|---|
| `/admin` là console Owner nhưng KHÔNG middleware — khách 200 · customer 200 | ✅ ĐÃ VÁ | `3acf968` | nay `['auth','admin','nostore']`; `AdminConsoleAccessTest` (3 test) khoá bất biến |
| Việc song song (gói cước · sổ cái credit · quản trị user) | ✅ ĐÃ ĐỐI CHIẾU | (commit riêng) | 21 test (4+6+11) chạy xanh; **mutation-test lại**: bỏ guard tự-hạ-quyền ⇒ RED ⇒ test thật sự bắt lỗi. Hạ tầng (`Plan`·`CreditTransaction`·`PlanService`·`CreditService`·`AdminController`·`AdminApp.vue`·`admin.js`) đã có sẵn và nhất quán (vite input · manifest · blade · route) |
| Build CSS **KHÔNG TẤT ĐỊNH** — chạy test xong build ra hash khác bản đã commit | ✅ ĐÃ VÁ | `32ba8ac` | `app.css` quét `storage/framework/views/*.php` (view ĐÃ BIÊN DỊCH); test Đợt 1.5 render `mail::message` ⇒ Tailwind nhặt thêm class mail (`.break-all`) ⇒ hash đổi. Đã gỡ dòng đó; `view:clear` + build ⇒ ra **đúng hash đã commit**, và build lại SAU khi chạy test vẫn không đổi. `BuildDeterminismTest` khoá bất biến |
| **Deploy production** `eee1bba` → `c4afbf8` | ✅ ĐÃ LÊN | — | Sao lưu DB trước (`~/fabrikai-db-backup-20260917-064320.sql`, 26 bảng) → pull → `dump-autoload` (BẮT BUỘC vì có class mới) → `package:discover` tay (`proc_open` bị chặn) → **7 migration** → cache → `queue:restart`. Verify: `/` `/dang-nhap` `/settings` `/presets` `/stylist-data` `/up` = 200 · khách `/admin` = **302 → /dang-nhap** · admin `/admin` = **200** · `jobs=0 failed=0` · log sạch · DB 29 bảng |
| Shell `/settings` · `/presets` · `/stylist-data` vẫn công khai | ⚠️ GHI SỢ NỢ | — | Cũng là console quản trị (API ghi toàn cục đã ở nhóm ADMIN). **Chưa siết** vì `ConceptCard.vue` (khách dùng) có link `/settings` KHÔNG gate `is_admin` ⇒ cần quyết định sản phẩm: ẩn link cho customer, hay tách cài đặt theo user |

> 🛑 **BẢNG DƯỚI ĐÂY LÀ ẢNH CHỤP 2026-09-16 — ĐÃ SAI HOÀN TOÀN, CHỈ GIỮ ĐỂ ĐỐI CHIẾU LỊCH SỬ.**
> Nó nói *"KHÔNG có `.git`"*, *"KHÔNG có `tests/`"*, *"chưa có deploy"* — cả ba đều **SAI** kể từ 2026-09-17.
> (Đây chính là lỗi mà `STUDIO_REVIEW_DEEPDIVE.md` §1.2 đã chỉ ra: §1/§2 nói ngược §2bis trong cùng file.)

**Code hiện hành KHÔNG còn ở TrillfaShop.** Module studio đã **tách sang app standalone `/home/anhtuan/DEV/FabrikAI`** (Laravel 13.26.1 · Vue 3.5.42 · PHP 8.3.6), ghi rõ trong `routes/web.php`: *"FabrikAI — AI fashion design studio (app độc lập, tách từ TrillfaShop)"*.

| | FabrikAI (hiện hành) | TrillfaShop (cũ) |
|---|---|---|
| Git | **KHÔNG có `.git`** | có (`main`, HEAD `11ac3d0` 2026-09-11) |
| Tests | **KHÔNG có `tests/`** (phpunit.xml dangling) | HEAD: 19 file / 199 test method; working tree còn **42** test method |
| Module studio | đầy đủ, prefix `/api` | **đã xóa khỏi working tree** (176 D, chưa stage) |
| Deploy | **chưa có gì** | `scripts/deploy.sh` hardcode `trillfa.shop` |

**Số liệu (đo 2026-09-17, SAU dọn dẹp):** PHP **72 file/17.319 dòng** trong `app/` · **272 test/1.549 assertion XANH** · **139 route** · `StudioController` 4.820 · `helpers.php` 1.590. *(Số liệu gốc lúc mở v3: PHP 113 file/21.298 dòng — xem bảng dưới.)*

**Số liệu gốc (2026-09-16):** PHP 113 file/21.298 dòng · JS-Vue 45 file/13.827 dòng (36 Vue, `store.js` 4.054) · `StudioController` 4.820 dòng · `helpers.php` 1.590 · blade 12 file (studio: 4) · **136 route** (112 auth+admin+nostore) · `public_html` 32 MB (bất thường).

**⚠️ Baseline "210 pass / 0 FAIL" của v2 KHÔNG còn tái lập được ở repo nào** — xem `STUDIO_REVIEW.md` §0.3.

## 2. VIỆC ĐANG MỞ (chi tiết + bằng chứng: `STUDIO_REVIEW.md` §1)

> ✅ **TÁI XÁC MINH 2026-09-17 tại `eee1bba` (đọc code từng mục): KHÔNG còn mục nào trong T1–T17 ở trạng thái MỞ thật.** Ba việc MỚI ở §2ter.

| # | Việc | Trạng thái 2026-09-17 | Bằng chứng |
|---|---|---|---|
| T1 | Port test suite | ✅ **XONG** `531c464` | *bảng cũ ghi "17 file" là sai: commit thực tế **19 file** (18 class test + `TestCase.php`)*; nay 34 file / 300 test |
| T2 | `git init` + baseline | ✅ **XONG** `362234b` | 369 file / 51.921 dòng · remote `origin` |
| T3 | 4 rủi ro `VideoAIService` | 🟡 **3/4** | N1 ✅ `62ce15c` (`:136` → `studio_fetch_remote_bytes`) · N3 ✅ `953ca5c` (file mp4 168.530 B có thật) · N4 ✅ `4c47e5a` (`:117` → `studio_generation_error()`) · **`sleep(5)` + `+480` VẪN còn** (`VideoAIService.php:99,110`) |
| T4 | Resolver containment | ✅ **XONG** `4c47e5a` | `SuggestLibraryService.php:271` = `studio_safe_public_file()` |
| T5 | PWA | ✅ **XONG** `7451d6b` | xoá `sw.js` + `manifest.json` + 8 icon. ⚠️ dư lượng UI chết: nút "Cài đặt ứng dụng" vẫn render — `StudioApp.vue:54,83,84,506` |
| T6 | Commit việc tách ở TrillfaShop | ✅ **XONG** `f35704f` | cây TrillfaShop nay **SẠCH**, HEAD `373bc35` |
| T7 | Deploy production | ✅ **XONG** | `DEPLOY.md` chốt **FabrikAI LÀ production**; `fabrikai.shop` LIVE trên MySQL |
| T8 | Dọn dead code | ✅ **XONG** `23c12cb` | `resources/js/app.js` (575 dòng) + `redirectToFabrikai()`; storefront xoá ở `3b69d2b`/`729ceb0` |
| T9 | Phình asset | ✅ **ĐÓNG** | `public_html` 32 → **21 MB** · icons → **52 KB/2 file** · favicon 15.086 B. **`samples/` 20 MB giữ có chủ đích** |
| T10 | Backlog medium/low | 🟡 **CHƯA ĐO ĐƯỢC ĐẦY ĐỦ** | §5.1 của `STUDIO_REVIEW.md` vẫn đánh MỞ 15/22; code: **M06 còn** (`store.js:698-705`) · **M16 còn** (`StudioLibraryService.php:123`) ⇒ không giữ câu "M01–M22 hết" |
| T11 | N1 — bypass SSRF | ✅ **XONG** `62ce15c` | `StudioController.php:3154` + `:3193`; `grep 'file_get_contents($url'` = 0 |
| T12 | N6 — scope `project_id` | ✅ **XONG** `62ce15c` | `:1744`, `:1853`. *"4 site K.8" ở §2 nay chỉ còn **2*** |
| T13 | N7 — ghi DB ẩn danh | ✅ **XONG** `62ce15c` | `StudioController:2700` chỉ lưu khi `auth()->check()`; nhóm public có `throttle:60,1` |
| T14 | S2 — cap pixel | ✅ **XONG** `62ce15c` | `helpers.php:160` kiểm **TRƯỚC** `:172`; `config/studio.php:23` có key |
| T15 | N3 — route chết | ✅ **XONG** | route đã xoá; `routes/web.php:68-70` chỉ còn comment |
| T16 | N5 + N8/N10 | ✅ **XONG** `4c47e5a` | whitelist `StudioController.php:3362` không còn `svg`; sanitizer dùng ở **5** site |
| T17 | N12 — clipboard | ✅ **XONG** `62ce15c` | `LayersPanel.vue:81` + `GalleryModal.vue:190,197` đều `await` |

### 2ter. VIỆC MỚI phát hiện khi tái xác minh (chưa có trong danh sách cũ)

| # | Việc | Bằng chứng | Mức |
|---|---|---|---|
| **M-a** | `GET /api/stylist-data/data` vẫn **PUBLIC** và vẫn chạy **DDL** (`Schema::create`) không cần đăng nhập | nhóm public `routes/web.php` → `StylistDataController.php:26` → `StylistCatalog.php:106` | vừa |
| **M-b** | Nhánh xử lý **inline vẫn giữ `sleep()` trong request**: `queue_worker` default = **false** ⇒ `processQueue()` fallback `dispatchSync` chờ provider ngay trong request (ảnh ~3 phút, video tới 8 phút) | `config/studio.php:134` · `StudioController.php:4467` · `VideoAIService.php:99,110` | **cao** nếu production chưa có cron worker |
| ~~**M-c**~~ ✅ **ĐÃ VÁ 66023d7** — nay mọi đường hoàn tiền đi qua helper CAS `studio_finalize_generation()`; hoàn CHỈ xảy ra ở request giành được row. | ~~**Hoàn tiền KHÔNG CAS ở `cancel()`** — đọc status trong PHP rồi `update()` vô điều kiện + `increment` ⇒ cancel đua với `failStuck()`/**`reconcile`** ⇒ **hoàn 2 lần** | `StudioController.php:1470-1478` (so với CAS đã có ở `:1317-1323` và `:1510-1516`) | **cao** — lỗi tiền |
| ~~**M-d**~~ ✅ **ĐÃ VÁ 66023d7** — job dùng `studio_claim_generation()`; row đã rời `processing` thì kết quả bị BỎ (có log). | ~~**Job ghi trạng thái cuối vô điều kiện** ⇒ có thể "hồi sinh" row đã cancel và hoàn tiền lần 2 | `RenderImageJob.php:108-126` + `:133-135` · `RenderVideoJob.php:79-97` + `:103-106` | vừa |
| **M-e** | `counts()` và `StudioLibraryService` **không nằm trong test ngân sách query** ⇒ N+1 có thể quay lại âm thầm | `StudioQueryBudgetTest.php:63-71` chỉ đo 4 URL | vừa |
| **M-f** | Comment trong code còn mô tả tính năng **đã gỡ** (`"swap : SwapCard"`) ⇒ người sau đi tìm file không còn | `helpers.php:1205` | thấp |
| **M-g** | `restorePromptMemory()` nhận `variantCount` từ localStorage **không clamp** ⇒ >4 bị `max:4` chặn → **422** khi bấm Tạo Ảnh | `store.js:2741` ↔ `StudioController.php:98` | vừa |
| ~~**M-h**~~ ✅ **ĐÃ VÁ 66023d7** — trừ credit + tạo row trong cùng `DB::transaction`; create lỗi thì rollback. | ~~`queueGeneration()` **trừ credit TRƯỚC** khi tạo row generation; nếu create ném lỗi ⇒ mất credit, không có generation, không hoàn | `StudioController.php:1597` → `:1620` | vừa |
| **M-i** | `onBeforeUnmount` **thiếu** `removeEventListener('keydown', onGlobalKey)` (đăng ký 4, gỡ 3) ⇒ rò listener | `StudioApp.vue:83` vs `:84` | thấp |

> 🛑 **BẢNG CŨ (2026-09-16) — ĐÃ BỊ THAY HOÀN TOÀN bởi bảng T1–T17 + §2ter ở trên. ĐỪNG DÙNG.**

| # | Việc | Ưu tiên |
|---|---|---|
| T1 | Khôi phục + port test suite sang FabrikAI (`git checkout HEAD -- tests` ở TrillfaShop → port) | **khẩn** |
| T2 | `git init` cho FabrikAI + commit baseline | **khẩn** |
| T3 | Vá 4 rủi ro `VideoAIService` (SSRF `@file_get_contents` · `sleep` 8 phút · stub 404 · leak body) | cao |
| T4 | Vá `SuggestLibraryService::resolveLocalImage()` — thiếu containment realpath | cao |
| T5 | Sửa hoặc gỡ PWA (SW bị unregister, `/sw.js` là code chết) | vừa |
| T6 | Commit việc tách ở TrillfaShop (213 mục chưa stage, 0 commit ghi lại) | vừa |
| T7 | Deploy production — **việc người dùng**; nay phải quyết định app nào là production | vừa |
| T8 | Dọn dead code (storefront + `resources/js/app.js` 574 dòng + `redirectToFabrikai()`) | thấp |
| T9 | Phình asset `public_html` 32 MB (samples 19 MB, icons 9,6 MB trùng lặp, favicon 1,5 MB) | thấp |
| T10 | Backlog medium/low (`STUDIO_REVIEW.md` §5) | thấp |
| **T11** | Vá **N1 (cao)**: 2 site bypass bản vá S3 — `@file_get_contents` thô ở `StudioController.php:3116` + `:3154` | **cao** |
| T12 | Vá **N6**: `project_id` không scope ownership ở suggest-library (`:1711`, `:1818`) | vừa |
| T13 | Siết **N7**: `POST /api/stylist/prompt` ghi DB + `ensureTables()` chạy trên route public | vừa |
| T14 | Sửa **S2**: cap pixel đang đặt SAU `imagecreatefromstring` → chuyển lên trước bằng `getimagesizefromstring()` | vừa |
| T15 | Sửa **N3**: route `/api/studiosample/{file}` trỏ method `assetSample` không tồn tại → 500 | thấp |
| T16 | Siết **N5 + N8/N10**: `svg` trong whitelist endpoint ảnh public · rò exception qua `Generation.error` | thấp |
| T17 | Vá **N12**: clipboard không await ở `LayersPanel.vue:80` (+2 site `GalleryModal.vue`) | thấp |

**Đã đóng:** T2/T3/T4/T5/T6/T7 của v2 (tái xác minh high/critical · ShopFlowTest · M6+M8 · 2 component mồ côi · feature mini K.8 · commit báo cáo).

### 2bis. Tiến độ (2026-09-16)

**Repo:** `https://github.com/hoanhtuan-dev/FabrikAI` (public) · `origin`/`main` · baseline `362234b`.

| # | Việc | TT |
|---|---|---|
| T2 | `git init` + baseline + push | ✅ `362234b` |
| T11 | N1 — 4 site tải remote thô → helper `studio_fetch_remote_bytes()` | ✅ `62ce15c` |
| T12 | N6 — scope `project_id` theo owner | ✅ `62ce15c` |
| T13 | N7 — không ghi DB khi ẩn danh + throttle nhóm public | ✅ `62ce15c` |
| T14 | S2 — cap pixel TRƯỚC decode + 2 key config mới | ✅ `62ce15c` |
| T15 | N3 — xóa route trỏ method không tồn tại | ✅ `62ce15c` |
| T17 | N12 — clipboard await ×3 | ✅ `62ce15c` |
| T4 | N2 — resolver containment | ✅ `4c47e5a` |
| T16 | N5 (bỏ svg) + N8/N10 (`studio_generation_error` ×8 site) | ✅ `4c47e5a` |
| T3 | N4+N9+N10 xong; **N11 (sleep 8 phút) còn mở** | 🟡 |
| T1 | Test suite — port 17 file từ TrillfaShop HEAD | ✅ `531c464` — **169 test / 694 assertion XANH** |
| — | Bug port phát hiện bởi test: kẹt `processing` (N14, cao) · tiền tố `/studio/image/` cũ (N15) · demo video 404 (N16) | ✅ `953ca5c` |
| T10 | M02 — CAS chống double-refund credit | ✅ `af5e07d` |
| T3 | N11 (sleep 8 phút trong request) | ⬜ (cần chốt queue-worker) |
| T8 | Dead code: app.js + redirectToFabrikai() | ✅ `23c12cb` |
| T8 | 41 file: 35 controller 0-route + module Storefront + mail chết | ✅ `3b69d2b` — route:list 139 không đổi · suite 168/168 |
| T9 | Icon về đúng kích thước (11,1 MB → 1,1 MB) | ✅ `52e9c62` |
| T10 | M04 · M05 · M12 · M16 | ✅ `ad719b0` |
| T10 | M01 · M14 · M20 | ✅ vòng 2 |
| T10 | M03 · M11 · M21 | ✅ vòng 2 |
| T10 | M09 · M10 | ✅ vòng 2 |
| T10 | M06 · M07 · M18 | ✅ `233f0e0` |
| T10 | M15 · M17 | ✅ `b98a6c6` — **21/22 mục §5.1 đã đóng**, còn M13 (chặn bởi T7) |
| §5.2 | 11 mục low (báo sai trạng thái · animation chết · rò timer · prototype · a11y phím) | ✅ `2032225` |
| §5.2 | 4 mục low (librarySelectOld · translate object · double DELETE · draft clamp) | ✅ `7a18955` |
| §5.2 | 5 mục low (quota localStorage · reload dự án · a11y modal · lỗi tải sản phẩm · lưu nhầm dự án) | ✅ vòng 6 — **20 mục low đã đóng** |
| Test | 13 test hồi quy cho vá vòng 1–6 (+ mutation-test chứng minh không rỗng) | ✅ `2412b54` |
| Test | 26 test phân quyền 8 endpoint generation-scoped | ✅ vòng 8 |
| Test | 5 test an toàn cho `studio:clean-storage` (code xoá file) + bỏ mục bảo vệ chết `'ref/'` | ✅ vòng 9 |
| Fix+Test | `studio:process`: vá thiếu CAS (double-refund, cùng lớp M02) + 8 test | ✅ vòng 10 |
| Test | S3: allowlist host + đuôi theo magic bytes cho `storeRemoteImage` | ✅ vòng 11 |
| **FIX [CAO]** | **N17 — xoá file tuỳ ý qua path traversal** ở `/api/uploads/delete` (vá 2 lớp) + 7 test an toàn đường xoá | ✅ `c20adb4` |
| Test | Khoá 2 đường xoá còn lại (`refImageDelete` · `assetDestroy`) + guard `studioServePath` | ✅ vòng 13 |
| **FIX** | Siết `User::$fillable` (chặn leo quyền + tự cộng credit) + seeder `forceFill` + 5 test | ✅ vòng 14 |
| **FIX** | 2 endpoint settings **luôn 500** (kiểu trả về mất dấu \) + 5 test bất biến không rò khoá API | ✅ vòng 15 |
| **FIX [CAO]** | Hợp nhất **5 đường hoàn credit** thành 1 helper CAS (M-c cancel thiếu CAS · M-d job ghi trạng thái cuối vô điều kiện · M-h trừ credit trước khi tạo row) — `studio:process` cũng gộp | ✅ `66023d7` — **+9 test** (hoàn đúng 1 lần khi đua · job không hồi sinh row đã huỷ · **bất biến chỉ MỘT đường đụng credit**); mutation-test 3 hướng đều ĐỎ đúng chỗ. **Baseline 309 test / 1.629 assertion** |
| Test | 5 test toàn vẹn tĩnh (route→method · route name · view · @vite · asset) + audit tham chiếu | ✅ vòng 16 |
| Test | 8 test quyền sở hữu `suggest_results` + `outfit_settings` (ngoài generation) + khẳng định tài nguyên GLOBAL | ✅ vòng 17 |
| **FIX [CAO]** | Chống brute-force `/dang-nhap` + `/dang-ky` (trước đây 0 giới hạn) + 4 test | ✅ vòng 18 |
| Test | 5 test khoá lớp XSS đầu ra (payload SPA trong `<script>` + bất biến sink + allowlist `v-html`) | ✅ vòng 19 |
| **PERF** | Sửa N+1 `/api/defaults` (75 → 9 query) + 3 test ngân sách query | ✅ vòng 20 — baseline 267 test |
| **FIX** | **Cache bảng nguyên bảng bị cũ**: `Setting::set()` không xoá `settings:all` (thêm ở vòng 20) + seeder ghi thẳng `updateOrCreate` → re-seed trên máy chủ đang chạy để lại giá trị CŨ | ✅ `22477ef` |
| Test | 5 test đảo ngược cache (`CacheInvalidationTest`) — mutation-test **3 đường** (Setting::set · StudioModel events · seeder) đều ĐỎ đúng chỗ | ✅ vòng 21 — **baseline 272 test / 1549 assertion** |
| **TEST** | Khoá 9 tham chiếu `route()` hỏng thành bất biến (quét cả blade) + trần dead code storefront (chặn nối vào đường sống) | ✅ `9edafec` — baseline 274 test |
| **FIX** | Hợp nhất khởi động 4 entry SPA (`pageBoot.js`: gỡ SW cũ + guard element) — 3 entry phụ trước đây bị bỏ sót | ✅ `7b6da4e` |
| **FIX** | 14 lớp phủ toàn màn hình thêm `role="dialog"`/`aria-modal` (vá a11y trước đây chỉ áp 2/16 chỗ) | ✅ `d5ffd8d` |
| Docs | Viết lại §5.2/§5.3 — danh sách "MỞ" đã **lỗi thời** (8 mục store.js + 12/13 mục Vue thật ra đã đóng từ vòng 5–6) | ✅ vòng 23–24 — **baseline 276 test / 1553 assertion** |
| **T5** | Gỡ hẳn PWA (chốt: gỡ) — sw.js + manifest.json + 8 icon (icons 1,1 MB → 52 KB) | ✅ `7451d6b` |
| **T6** | Commit + push việc tách ở TrillfaShop — kèm dọn 17 file test studio + 22 method trộn (**repo cũ xanh lại 42/42**) | ✅ `f35704f` |
| **T7** | Chốt **FabrikAI LÀ production** + viết `DEPLOY.md` (env · web root · supervisor worker · rollback) | ✅ vòng 26 |
| **N11/M13** | Đưa `sleep()` ra khỏi request path bằng cờ `STUDIO_QUEUE_WORKER` + 8 test | ✅ vòng 26 |
| **N18** | **[CAO]** Mật khẩu super admin hardcode trong repo PUBLIC + seeder ghi đè mật khẩu mỗi lần seed — vá + 8 test | ✅ vòng 27 — **baseline 293 test / 1603 assertion** |
| **GỠ SẠCH** | Module thương mại điện tử: 19 model · 3 service · 27 migration · 31 helper · 2 endpoint · tab Sản phẩm · demo seeder (641→213 dòng) | ✅ `729ceb0` — route 133→131 · migrate fresh ra **25 bảng** |
| **BUG CÓ SẴN** | `helpers.php`: khối guard `menu_tree` **thiếu dấu } đóng** → nhốt cả loạt helper studio vào trong | ✅ vòng 28 |
| **FIX** | `Setting::booted()` xoá cache ở MỌI đường ghi qua Eloquent (+ test ghi nhận giới hạn bulk query-builder) | ✅ vòng 28 |
| **BRAND** | APP_NAME + email seed → domain mới, cho phép đổi qua env (`SEED_*_EMAIL`) | ✅ vòng 28 — **293 test / 1598 assertion** |
| **DEPLOY** | fabrikai.shop **ĐANG CHẠY** — code + vendor + .env + symlink + migrate + seed + auth + tạo ảnh đầu-cuối đều đã verify bằng chạy thật | ✅ **LIVE** (DB tạm SQLite; MySQL chờ hPanel) |
| **BUG P1** | Cache Eloquent Collection → `/api/defaults` 500 ở mọi lần gọi thứ 2 (test không bắt vì `CACHE_STORE=array` không serialize) | ✅ `da2545a` + test dùng store serialize |
| **BUG P2** | Job chỉ enqueue lúc POLL → tạo ảnh rồi đóng tab = kẹt `pending` vĩnh viễn + mất credit | ✅ `f7710bb` (dispatch ở `Generation::created()`) |
| **BUG P3** | Bật cờ queue mà host không có worker (cron chưa tạo) → kẹt vĩnh viễn | ✅ `2bb650b` (lưới an toàn inline sau 90s) — verify: gen #3 tự xong ở t=95s |
| **DB** | Chuyển sang **MySQL** `u310846799_fabrik_ai` (25 bảng, 0 bảng storefront) + seed + cache production | ✅ verify lại toàn bộ: 7 trang 200 · đăng nhập 302 · 10 API 200 · tạo ảnh completed |
| **PHÂN TÍCH** | Viết **STUDIO_REVIEW_PRODUCT.md v2** — chẩn đoán 5 lệch pha cấu trúc của luồng + thiết kế lại workflow theo persona (7 đối tượng nghiệp vụ · state machine cho artifact · kiến trúc 4 tầng) | ✅ vòng này — xem file, §9 có quy tắc cập nhật |
| **GỠ CARD** | Gỡ 3 activity (Pattern · Try-On · Thay người mẫu) + chip "thay khuôn mặt" trong Ghép ảnh (giữ card) | ✅ `eee1bba` — route 131→**127** · component 31→**28** · **300 test xanh** (bỏ 6 test của tính năng đã gỡ) |
| **BUG P4** | Lưới an toàn không dọn job đã enqueue → mỗi ảnh để lại 1 job chết (`jobs=1` sau generation đầu) | ✅ `57c4c0e` + test dùng queue store thật |
| **FIX** | `LOG_LEVEL=error` lọc mất `logger()->warning()` của lưới an toàn → cảnh báo vận hành im lặng | ✅ đổi sang `warning`, log đã ghi đúng |

**.gitignore của repo public đã chặn:** `.env` · `database/*.sqlite` · `storage/logs` · `vendor` · `node_modules` · **`STUDIO_REVIEW*.md`** (báo cáo chứa chi tiết lỗ hổng chưa vá — cùng quy ước TrillfaShop).


## 3. ĐỢT CHƯA GHI SỔ — 43 commit (2026-09-08 → 2026-09-11)

> Sổ v2 dừng ở **Phiên AW** (commit `05baac8`, 2026-09-08). Sau đó có **43 commit** hoàn toàn không được ghi lại. Nay ghi bù, gom theo chủ đề (dùng `git -C /home/anhtuan/DEV/TrillfaShop log 05baac8..11ac3d0` để tra từng commit).

### 3.1 Thư viện Prompt phân tích (tính năng MỚI, chưa từng review)
- `b22fe2e` — "Thư viện Prompt phân tích": lưu & tái sử dụng kết quả từ card **Gợi ý từ ảnh** → service mới `SuggestLibraryService` (310 dòng), model `SuggestResult` (bảng `suggest_results`), **7 endpoint** `/api/suggest-library*`, component mới `SuggestLibraryCard.vue` (243) + `PromptLibraryTab.vue` (513) + `libraryLayout.js` (31), đổi `LibraryApp.vue`.
- `6e92438` + `11ac3d0` — tinh chỉnh: `StudioController`, `StudioLibraryService`, `SuggestLibraryService`, `GalleryModal.vue`, nút xóa nhanh prompt + xóa nhanh file tải lên.
- **Lưu ý phủ sóng:** `VideoAIService` (176), `SuggestLibraryService` (310), `StudioSettingsController` (439), `PromptLibraryTab`, `SuggestLibraryCard`, `LayersPanel`, `StylistDataManager`, `libraryLayout.js`, `StylistDataApp` — **grep tên trong CẢ HAI file báo cáo = 0** → lỗ hổng phủ sóng của v2, không phải file mới.

### 3.2 Prompt Tạo Ảnh
- `4bf7b6e` **Gieo quẻ (seed)** cho prompt + đổi nút Tạo Ảnh + fix 500 image-thumb · `ee5d40b` chuyển seed ra tab Prompt cùng hàng Texture · `33370be` thêm **tab Tư thế** · `a35af7b` checkbox bỏ qua phân tích trong card Gợi ý từ ảnh.
- `02f0426` ô **Prompt Prefix/Suffix** tab Nâng cao (đồng bộ 2 chiều với Settings) · `0258f35` ghi nhớ cài đặt prompt local + checkbox bật/tắt Prefix/Suffix/Negative · `3aad4b4` lưu toàn bộ vào localStorage + popup gần full màn hình · `34b73e3` số dòng mặc định textarea.
- `5643fd2` → `371bde7` → `e561810` redesign **popup Prompt** (header/tab cố định, body cuộn, 82vh→70vh) · `9349f2a` + `22e921e` bỏ emoji còn sót → icon SVG.

### 3.3 Card Gợi ý từ ảnh
- `6289a4e` nút **"Tạo ảnh ngay"** với tiến trình · `022c4f3` gộp 2 nút thành 1 luôn hiện · `067188a` refactor: mở **popup xác nhận** rồi mới tạo · `c1b8ddf` bump service worker cache **v3→v4**.

### 3.4 Canvas / Layer / Multi-select
- `8142388` menu ContextToolbar cho **công cụ Lựa chọn + Pan (tablet)** · `1ad52bc` gộp `MultiSelectBar` vào ContextToolbar + fix pan tool · `96005df` icon checkSquare/ungroup + popup xác nhận dọn canvas · `fb02da2` xóa nút Dọn canvas khỏi ContextToolbar → popup vào `LayersPanel`, xóa nút Bỏ ảnh nguồn · `55ccc0c` fix `clearTimeout is not a function` · `8c18f79` thêm popup xác nhận dọn canvas.

### 3.5 Cài đặt / API keys / model
- `a1f01f8` **redesign API keys + custom provider registry + Vue SPA settings**: controller mới `StudioSettingsController` (439 dòng), model mới `StudioProvider`, migration `2026_09_10_000000_create_studio_providers_table.php`, sửa `ImageAIService` + `helpers.php` + `routes/web.php` + `settings-vue.blade.php`, **15 route** `/api/settings-vue/*`.
- `f30755f` **task-group model assignment** (model theo từng nhóm công việc/card): `.gitignore`, `StudioController`, `StudioSettingsController`, `helpers.php`, `docs/TASK_GROUP_DESIGN.md` (mới), `SettingsApp`, `ConceptCard`, `DirectorCard`, `InpaintCard`, `RefImageCard`, `store.js`, `routes/web.php`.
- `4947177` fix TypeError (variable shadowing) ở Settings SPA.

### 3.6 Sửa lỗi / đồng bộ khác
- `900f547` `studio_config()` bỏ qua empty string từ DB → fallback config default · `565c1a8` preview-enrich nhận body/hair từ tab Phom dáng · `6e16472` fix 500 image-thumb + popup GalleryModal/SourcePickerPopup không hiển thị trong StudioApp · `d6572a1` render ProjectWorkspace popup + gọn prompt `StylistService` · `d674560` fix **cross-world SW resource mismatch** cho modulepreload.
- `e9ed8e5` khôi phục `settings.blade.php` bị cắt mất **184 dòng** · `c64ea21` fix CSS syntax + Vue missing closing tags · `1b804ed` + `e5175c6` bo góc **VSCode-style** toàn diện (card/input/btn/badge/chip + admin blade).
- `b3327d1` chuyển `/studio/library` vào SPA `/studio` + gọn card Nâng cấp ảnh · `14eee6b`/`2249124` tách 2 nút **Prompt & Trợ lý thiết kế** đổi vị trí right↔left toolbar · `c9d6f8f` docs deploy: `DB_SOCKET=/tmp/mysql.sock` bắt buộc trên Hostinger.

## 3bis. ĐỢT XÁC MINH v3 (2026-09-16) — 5 subagent song song + điều phối tự đối chứng

Chia việc: **A** bảo mật (S1–S11 + top-5 + K.8 + F1 + L.2 + quét endpoint mới) · **B** backlog medium §5.1 (22 mục) · **C** backlog low/info §5.2-5.3 (39 mục) · **D** kiểm kê thay đổi/file mới/route remap/tàn dư storefront · **E** hạ tầng (git · test · deploy · build · log). Điều phối tự đọc lại code đối chứng các claim quan trọng trước khi ghi.

**Kết quả v3:** 12/14 vá bảo mật của v2 còn nguyên trên FabrikAI · 3 medium §5.1 đóng (M08 toast XSS, M19 download/palette, M22 StylistCard v-html) · ~19 medium còn mở · ~20 low còn mở · **14 lỗi MỚI (N1–N13 + N7-hệ quả)** chưa từng có trong báo cáo, trong đó **N1 mức cao**.

**Điểm phải nhớ về v3:**
- `helpers.php` khác bản cũ **đúng 3 dòng URL**; `config/studio.php` **giống hệt** → vá bảo mật đi theo nguyên vẹn. Nhưng có **resolver thứ hai lệch chuẩn** (`SuggestLibraryService:261`) và **2 site bypass `storeRemoteImage`** → mẫu hình "bản sao lệch chuẩn" tái diễn.
- `StudioController.php` = **4.820 dòng**; v2 ghi 4.732 (sai), `TrillfaShop@HEAD` = 4.911.
- **PWA đã chết** ở app mới (SW bị unregister, không nơi nào đăng ký lại) — mất tính năng của 4 phiên AJ/AK/AL/AM.

## 4. ĐỢT TÁCH APP (2026-09-12 → 2026-09-13) — ✅ **nay ĐÃ commit** ở TrillfaShop (`f35704f`, đóng T6)

- Tạo app mới `/home/anhtuan/DEV/FabrikAI` (composer name `fabrikai/fabrikai`, Laravel ^13.17) và **port toàn bộ module studio** sang: `app/`, `app/Services/`, `app/Models/` (36 model, không mất cái nào), `app/Jobs/` (4), `app/Console/Commands/` (2), `database/migrations` (52), `database/seeders` (6), `resources/js/studio/` (45 file), blade.
- **Đổi tiền tố URL**: `/studio/*` → `/api/*`; SPA `/studio` → `/`; `/studio/settings` → `/settings`; `/studio/presets` → `/presets`; `/studio/stylist-data` → `/stylist-data`; giữ 2 `Route::redirect` cho URL cũ.
- **Gộp blade**: 5 trang riêng (`vue/api/library/pattern/tryon/settings-vue.blade.php`) → **1 shell SPA `index.blade.php`** (37 dòng) + entry `main.js` (thay `resources/js/studio/app.js`); pattern/tryon thành **card** trong SPA (`PatternCard.vue`, `TryOnCard.vue` mới); thêm `PresetsApp.vue` + `presets.js`.
- **PWA đổi tên + hỏng**: `manifest-studio.json`/`sw-studio.js` (scope /studio) bị xóa → `manifest.json`/`sw.js` (scope /, cache `fabrikai-v1`), nhưng `main.js:7-16` unregister mọi SW và **không nơi nào đăng ký lại** → SW là code chết.
- **Bỏ lại phía sau:** `tests/` (0 file được port), storefront JS (`resources/js/storefront/**` ~60 file bị xóa), `StorefrontModuleServiceProvider` không được đăng ký (`bootstrap/providers.php` chỉ có 2 provider) → ~9 controller + `CartService`/`CheckoutService`/`StorefrontBridge` (837 dòng) + ~10 helper + entry `resources/js/app.js` (574 dòng) thành **dead code**.
- **Ở TrillfaShop:** ~~176 file bị xóa + 14 M + 23 ?? — tất cả chưa stage; `git log --all | grep -i fabrik` = 0~~ → ✅ **ĐÃ ĐÓNG (T6, 2026-09-17):** commit `f35704f` (176 D · 16 M · 23 A); cây TrillfaShop nay **sạch**, HEAD `373bc35` (đã kiểm `git status --porcelain` = 0). Bằng chứng ý định (đều chưa commit): `resources/js/admin/useStudioThumb.js:2` và `.env.example:6` `FABRIKAI_URL=http://localhost:5173`.

## 5. LỊCH SỬ ĐÃ NÉN (chi tiết: `git show 05baac8:STUDIO_REVIEW_PROGRESS.md`)

| Đợt | Nội dung | Kết quả |
|---|---|---|
| Phần A–L (audit) | 30 area · **214 findings** (critical 2 · high 18 · medium 53 · low 98 · info 43) · 8 claim dương tính giả đã loại | Đã vá ~60 (§2 v3) |
| J (phase 2) | decode 41 site → `studio_image_decode()` · `studioServePath()` · `studio_fail()` · 22 catch rỗng → `console.error` · 17 site `res.ok` | có test |
| K | Project Management **11/11** + K.4 (đệ quy vô hạn `studio_image_decode` — root cause mọi SIGKILL) + K.8 hardening `project_id` | 53 test Project pass |
| L | production-refgen: chẩn đoán Hostinger còn code trước `234d405` + refgen 422 (`be3cfe7`) | **T7 vẫn mở** |
| M | Đồng bộ Quản lý dự án × Outputs/Library + `appliedProject` + icon | 5 test mới · 190/9 baseline |
| N | T2 tái xác minh 11 nhóm high/critical bằng workflow 11 agent → **6 CÒN vá hết** + cap pixel 30MP; T3 ShopFlow 64/64; T4/T5/T6 đóng | **210 pass / 0 FAIL** (lúc đó) |
| O–AA | Bézier path tool (Krita) + card Sửa ảnh 1 công cụ + `POST /studio/inpaint` | 13 phiên |
| AB–AV | Workspace **VSCode-style** (activity bar, dock ẩn được) + **PWA /studio** + Kịch bản quay đọc preset + card Sửa ảnh (chip màu/nền, undo-redo mask) + preset inpaint ở `/studio/presets` | 22 phiên, mỗi phiên vite build ✓ + deploy live |
| AW | Gộp PHẦN I.3 vào `STUDIO_REVIEW.md` + đóng T7 | commit `05baac8` |
| **Chưa ghi sổ** | **43 commit 2026-09-08 → 09-11** | **§3 trên** |
| **Tách app** | **2026-09-12 → 09-13** | **§4 trên** |

**PHẦN I (UI/UX Redesign)** — 37 phiên UI-1 → UI-3aj: chrome/layout · nguồn & thư viện · toolbar canvas · viewer GalleryModal · đa chọn & GROUP = 1 đối tượng · fix gốc lỗi chọn layer (truyền object thay vì id). Mọi commit đã push + deploy live (ở thời điểm đó).

## 6. VIỆC KHÔNG CẦN LÀM LẠI (đã kết luận, đừng để subagent báo lại)

- **XSS lớp blade**: `grep '{!!'` = 0 → cả lớp bị loại. (Blade studio nay chỉ còn 4 file shell.)
- **`env()` trong `config/*.php` KHÔNG phải lỗi** — pattern chuẩn Laravel (284 vị trí trong `config/` ở FabrikAI). Lỗi thật là `env()` NGOÀI `config/`; ở FabrikAI chỉ còn **4 vị trí**, trong đó `StudioController.php:62` là code CHẾT (`redirectToFabrikai`).
- **`deepseek-official` "bị rate-limit"**: đã bác bỏ — thủ phạm thật là `schema` + model bọc fence JSON (playbook §1).
- **`imagecreatefromstring` = 41 vị trí** ở bản cũ, KHÔNG phải ~10; đã thay bằng helper chung.
- **Route studio ở bản cũ nằm trong group `[auth,admin,nostore]`** (`routes/web.php:94`) — nhưng **nhóm public KHÔNG "read-only"**: có 5 endpoint, 2 POST, và `POST /stylist/prompt` **GHI DB** (`StudioCatalog::savePreset`). Xem `STUDIO_REVIEW.md` §6.3/N7.
- **`config/studio.php` KHÔNG có key `image_max_pixels`** (cả bản cũ và mới) — cap 30MP đến từ **tham số mặc định trong `studio_image_decode()`** (`helpers.php:256`), override qua setting DB.

## 7. CÁCH CẬP NHẬT FILE NÀY

1. Đọc file này TRƯỚC, rồi mở `STUDIO_REVIEW.md` §1 (việc mở) + §5 (backlog).
2. Làm việc; **verify bằng read/grep trên working tree, không tin số dòng cũ**.
3. Cập nhật: việc mở ở §2 (đóng/mở) · đợt mới thành mục §3.x · số liệu §1.
4. Đối chiếu lại bằng `grep`/`wc -l` sau khi ghi; ghi rõ NGÀY đo.
5. **Sửa cả 2 bản gương** (FabrikAI + TrillfaShop) — nếu chỉ sửa một bên thì ghi rõ bên nào là bản chuẩn.
6. Chống race phiên song song: `stat` mtime + đọc lại vùng sắp sửa trước khi ghi.
