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

**Neo đo MỚI 2026-09-20 19:25 (commit `92344b6` + việc chưa commit — `scripts/measure.sh --no-tests`):**
`HEAD 92344b6` · **246 commit** · **204 route** · PHP `app/` **97 file / 28.638 dòng** · `app/Services` 24 · `app/Models` 29 · migrations **55** ·
JS/Vue studio **69 file (51 .vue) / 25.060 dòng** · `public_html` 22 MB · **suite 933 test / 6.750 assert XANH** (`vendor/bin/phpunit`, 1m40s).
Điểm nóng: `store.js` **5.658** · `StudioController.php` **5.395** · `helpers.php` **2.677** · `DesignAgentService.php` ~2.250.
*(Neo cũ 2026-09-17: `436675d` · 99 commit · 148 route · 471 test/2.518 assert · `app/` 15.658 dòng — giữ để đối chiếu.)*

**Neo đo 2026-09-17 15:53 (commit `436675d` — chạy `scripts/measure.sh`):**
`HEAD 436675d` · **99 commit** · **148 route** · **471 test / 2.518 assertion XANH · 53 file test** ·
PHP `app/` **58 file / 15.658 dòng** · `app/Services` 14 · `app/Models` 20 · migrations 35 ·
JS/Vue studio **51 file (38 .vue) / 14.239 dòng** · `public_html` **21 MB** ·
**production `fabrikai.shop` ĐANG CHẠY** (MySQL, 29 bảng · đang chạy commit `436675d`).
Điểm nóng monolith: `StudioController.php` **4.805** · `store.js` **4.044** · `helpers.php` **1.718**.
*(Neo trước `93dfd86`: 127 route · 309 test — giữ lại để đối chiếu lịch sử.)*

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
| Việc song song (gói cước · sổ cái credit · quản trị user) | ✅ ĐÃ ĐỐI CHIẾU | `0afec4e` + `10d142b` + `adc2700` | 21 test (4+6+11) chạy xanh; **mutation-test lại**: bỏ guard tự-hạ-quyền ⇒ RED ⇒ test thật sự bắt lỗi. Hạ tầng (`Plan`·`CreditTransaction`·`PlanService`·`CreditService`·`AdminController`·`AdminApp.vue`·`admin.js`) đã có sẵn và nhất quán (vite input · manifest · blade · route). ➜ **Chi tiết đầy đủ (gồm tự-đăng-ký-gói `436675d` + deploy production) ở mục "GÓI CƯỚC · SỔ CÁI CREDIT · QUẢN TRỊ OWNER" bên dưới** |
| Build CSS **KHÔNG TẤT ĐỊNH** — chạy test xong build ra hash khác bản đã commit | ✅ ĐÃ VÁ | `32ba8ac` | `app.css` quét `storage/framework/views/*.php` (view ĐÃ BIÊN DỊCH); test Đợt 1.5 render `mail::message` ⇒ Tailwind nhặt thêm class mail (`.break-all`) ⇒ hash đổi. Đã gỡ dòng đó; `view:clear` + build ⇒ ra **đúng hash đã commit**, và build lại SAU khi chạy test vẫn không đổi. `BuildDeterminismTest` khoá bất biến |
| **Deploy production** `eee1bba` → `c4afbf8` | ✅ ĐÃ LÊN | — | Sao lưu DB trước (`~/fabrikai-db-backup-20260917-064320.sql`, 26 bảng) → pull → `dump-autoload` (BẮT BUỘC vì có class mới) → `package:discover` tay (`proc_open` bị chặn) → **7 migration** → cache → `queue:restart`. Verify: `/` `/dang-nhap` `/settings` `/presets` `/stylist-data` `/up` = 200 · khách `/admin` = **302 → /dang-nhap** · admin `/admin` = **200** · `jobs=0 failed=0` · log sạch · DB 29 bảng |
| **Quyết định người dùng 2026-09-17**: `/settings` → cấp OWNER · `/presets` + `/stylist-data` → cấp USER + lưu CỤC BỘ trên máy khách | ✅ XONG | `76c6139` | `/settings` = `auth+admin`; 2 trang kia = `auth+can-studio`. Bản tùy chỉnh lưu `localStorage` **theo từng userId** (`useLocalCatalog.js`: custom/edits/hidden); bảng toàn cục chỉ còn là giá trị mặc định; admin giữ thêm chế độ “Bản dùng chung” nên không mất khả năng sửa catalog toàn cục. `UserCatalogTest` (10 test) + `scripts/check-local-catalog.mjs` (23 phép kiểm Node, nối vào suite vì repo không có JS test runner) |
| CSS build **phụ thuộc VĂN BẢN TÀI LIỆU** — chữ `break-all` trong ghi chú sinh utility trong CSS bán cho khách | ✅ ĐÃ VÁ | `560392f` | Tailwind v4 mặc định tự quét cả cây dự án (kể cả `.md`). Đã tắt bằng `source(none)` + `@source` tường minh; đo được **tất định hoàn toàn** (build sau khi chạy full test và sau khi sửa tài liệu đều ra cùng hash). 1492→1487 rule, mất đúng 5 rule rác từ tài liệu |
| Ghi chú trong `app.css` dùng `//` (không phải cú pháp CSS) ⇒ `npm run build` ĐỎ, mà asset cũ còn sót nên dễ tưởng build xong | ✅ ĐÃ VÁ | `560392f` | Đổi sang comment khối CSS; thêm test **manifest phải trỏ tới file có thật trên đĩa** để chặn đúng cái bẫy “build đỏ mà `ls` vẫn thấy file” |

### THƯ VIỆN RIÊNG THEO USER + SẮP XẾP UI (2026-09-17)

| # | Việc | Trạng thái | Commit | Ghi chú |
|---|---|---|---|---|
| 0.1b | **Thư viện ảnh RIÊNG theo user** (nợ ghi sổ từ Đợt 0.1 — nay ĐÓNG) | ✅ XONG | `84bf377` | Ảnh mới lưu `studio/ref/u<id>/`; người dùng chỉ thấy/xoá ảnh của mình; **owner thấy & xoá TẤT CẢ**; kho phẳng cũ vẫn đọc được (không mất ảnh đã chèn vào dự án); `studio/assets` vẫn dùng chung. 4 route chuyển sang nhóm STUDIO (dọn mồ côi giữ ở ADMIN). `UserLibraryScopeTest` (9 test) + mutation-test RED |
| — | **Sửa lỗi CÓ SẴN**: `refImages()` khai báo kiểu trả về `\Illuminate\JsonResponse` (class không tồn tại) | ✅ ĐÃ VÁ | `84bf377` | Mọi lời gọi thật đều TypeError/500; ẩn vì endpoint chỉ ở nhóm ADMIN và chưa test nào gọi tới đích |
| — | **UI**: icon Cài đặt ở **góc trái dưới**; Prompt Tạo Ảnh + Trợ lý thiết kế **dời lên nhóm trên** | ✅ XONG | `3b04d3c` | Menu Cài đặt = lối vào **trang cài đặt preset cho người dùng** (`/presets`) + dữ liệu Trợ lý + thư viện; admin thêm mục Cài đặt hệ thống + Quản trị Owner. Đóng menu bằng Escape; mobile có lối vào tương đương |
| — | **Tách card "Ảnh mới từ ảnh mẫu" (1 card · 2 chip) → 2 CARD RIÊNG** | ✅ XONG | `6c14d27` | Chip "Tạo ảnh mới" → card **"Tạo biến thể ảnh"**; chip "Thử đồ" → card **"Mặc thử đồ"**. Gỡ hẳn seg + `setMode` + 2 biến lưu prompt tạm: mỗi card là một instance riêng nên state tách tự nhiên. Thêm **2 icon SVG chuẩn ngành** (Lucide): `variations` = "images" (chồng 2 khung ảnh) và `hanger` = móc treo quần áo; mỗi card có nút hành động riêng kèm icon. `RefCardSplitTest` (6 test) + mutation-test RED |
| — | **XOÁ nhóm "Fitting Room"** — 2 card thành **2 MỤC RIÊNG** trên thanh công cụ trái | ✅ XONG | `4cd002a` + `c76dcb7` | Xoá activity `ref`; thêm `variation` (icon `variations` · "Tạo biến thể ảnh") và `tryon` (icon `hanger` · "Mặc thử đồ"), mỗi mục có panel + icon riêng. Sửa `activeActivity` khỏi trỏ id đã xoá. **Verify trên production bắt được nhãn sót**: bundle vẫn còn chuỗi "Fitting Room" ở nút primary của `GalleryModal` (`Chỉnh sửa → Fitting Room`) và nhãn nhóm `swap` trong `SettingsApp` — đã sửa thành "Tạo biến thể từ ảnh này" / "Mặc thử đồ". Thêm guard **soi chính bundle đã build** (bắt cả nhãn sót lẫn bundle chưa rebuild) |
| — | **Trang quản lý GIAO DIỆN cho owner** — thanh công cụ trái (thứ tự · nhãn · icon · ẩn/hiện) | ✅ XONG | `305392e` | `StudioGuiConfig` lưu ở bảng `settings` (cấu hình TOÀN CỤC, đi theo mọi bản deploy). `GET /api/gui` cho Studio đọc; `GET/PUT/POST /api/admin/gui*` cho owner (chỉ admin). Tab **🎨 Giao diện** trong `/admin`: xem trước trực tiếp, đổi thứ tự bằng nút ▲▼ (dùng được cả bàn phím), chọn icon có xem trước, sửa nhãn, bật/tắt hiện, Lưu + Khôi phục mặc định. Sai dữ liệu trả **422 kèm thông báo cụ thể** (id lạ · icon không tồn tại · nhãn quá dài). `StudioGuiConfigTest` (15 test) + 2 guard chống lệch PHP↔Vue. Verify production: vòng đời đọc → sửa → áp dụng → khôi phục chạy đúng |
| — | **Icon dùng CHUNG một nguồn duy nhất** — thêm icon là tự động có mặt mọi nơi | ✅ XONG | `f3c4a59` | `resources/js/studio/icons.json` là nguồn DUY NHẤT (`{name:{svg,note?}}`), cả Vue (`StudioIcon.vue` 146→26 dòng) lẫn PHP (`App\Support\IconRegistry`) đọc cùng file. Bỏ hằng số `ICONS` trùng trong `StudioGuiConfig`. Thêm icon = thêm 1 khoá JSON, **không sửa PHP**. Nền tảng cho quản lý icon toàn hệ thống sau này: registry đã có `note`, đổi sang DB/API không phải sửa nơi gọi. Guard mới *“mọi icon ĐANG DÙNG phải có trong registry”* **bắt được lỗi có sẵn**: `ConceptCard.vue` dùng `name="arrowLeft"` mà registry không có ⇒ nút render TRỐNG bấy lâu nay (đã bổ sung, 122 icon). Mutation-test: đổi tên icon đang dùng → RED · trả lại `const ICONS` trong PHP → RED |
| — | **Liệt kê ĐẦY ĐỦ nút thanh công cụ trái** (người dùng phát hiện thiếu) | ✅ ĐÃ VÁ | `6f5117b` | Trang quản trị chỉ có 7 nhóm card, **thiếu nút Prompt Tạo Ảnh · Trợ lý thiết kế** (bị viết cứng trong template) và cả nút menu Cài đặt. Nay `DEFAULTS` có **10 mục** kèm `kind`: `panel` (7) · `action` (prompt · stylist — mở popup) · `menu` (settings — **ghim đáy**). `kind` lấy từ CODE nên client không đổi được hành vi nút; `PINNED_IDS` đưa mục ghim xuống cuối. Thanh công cụ trái + menu mobile nay **sinh từ một vòng lặp cấu hình**, không còn markup viết cứng. Guard mới chặn đúng lớp lỗi này (mọi nút phải có trong cấu hình; không còn markup viết cứng; fallback JS khớp `kind` của PHP). `StudioGuiConfigTest` 22 test; mutation-test: viết cứng lại nút Prompt → RED · bỏ `prompt` khỏi DEFAULTS → RED |
| — | **Cài đặt KHUÔN MẶT (model) + DÁNG POSE (người mẫu)** — cấp USER | ✅ XONG | `dc58604` + `a666f18` | `studio_assets` thêm `user_id` (NULL = catalog dùng chung có từ trước). Người dùng thêm mặt/dáng **của mình** (chỉ họ thấy), **owner thấy & xoá tất cả**; 2 route `/api/assets` chuyển sang nhóm STUDIO. Trang `/model-settings` (2 tab) + 2 mục trong menu Cài đặt. `UserModelSettingsTest` (10 test) + mutation-test RED |

> ⚠️ **Vì sao mặt/dáng lưu ở SERVER, không như `/presets` · `/stylist-data` (localStorage):** khi tạo ảnh, studio gửi **id** của mặt/dáng lên và backend phải tra ra ảnh tham chiếu (`VirtualTryOnService::pickModel/pickPose`). Id chỉ nằm trong localStorage thì backend **không tra được** ⇒ tính năng vô hiệu. Test `own_asset_resolves_at_generation_time` khoá đúng điều này.

### GÓI CƯỚC · SỔ CÁI CREDIT · QUẢN TRỊ OWNER · TỰ ĐĂNG KÝ GÓI (2026-09-18) — mở khoá PLAN Đợt 3 mục (2)

> Việc này chạy **SONG SONG** với các đợt 0–1 ở trên (dòng "Việc song song" ở bảng XÁC MINH CHÉO bên dưới).
> Nguồn số: `scripts/measure.sh` @ `436675d` — **471 test / 2.518 assertion XANH · 148 route**.

| # | Việc | Trạng thái | Commit | Ghi chú |
|---|---|---|---|---|
| — | **Sổ cái credit** (`credit_transactions`) — mọi biến động `credits_balance` ghi đúng MỘT dòng (amount có dấu · `balance_after` · reference · `admin_id`) | ✅ XONG | `10d142b` | `CreditService::mutate/record/apply` là **điểm DUY NHẤT** còn giữ literal `increment/decrement(credits_balance)`. Đường TRỪ (`StudioController::queueGeneration`) và đường HOÀN (`helpers.php::studio_finalize_generation`) nay **uỷ quyền về đây** ⇒ bất biến "một đường credit" siết chặt hơn; `CreditRefundSafetyTest::test_only_one_credit_mutation_path_exists_in_app` đã cập nhật theo (đỏ nếu có file thứ hai mutate). 4 test `CreditLedgerTest` |
| — | **Gói cước** (`plans`) + `users.plan_id` / `plan_expires_at` | ✅ XONG | `10d142b` | Bảng `plans` (giá VNĐ · credit/tháng · bonus · trần phân giải · `features` JSON · `is_default` · thứ tự). `PlanService::assign()` là đường **DUY NHẤT** đổi gói: đặt hạn N tháng · tặng bonus **MỘT LẦN** khi lần đầu chuyển gói · ghi sổ cái. Đăng ký mới tự gán gói mặc định + ghi dòng `signup` (`AuthController::assignDefaultPlan`) |
| — | **4 gói VNĐ** seed sẵn | ✅ XONG | `10d142b` (seeder) · đã chạy trên **cả dev và production** | Miễn phí (0₫ · mặc định · 100 credit dùng thử khi đăng ký) · Khởi nghiệp 199.000₫/120cr (+30) · Chuyên nghiệp 499.000₫/350cr (+100) · Studio 1.490.000₫/1.200cr (+400). Biên gộp ≥60% ở mô hình Qwen base — luận cứ + bảng chi phí gốc ở `PRICING.md` |
| — | **Trang quản trị Owner** `/admin` (SPA Vue) | ✅ XONG | `0afec4e` | `AdminController` (391 dòng) + `AdminApp.vue` (604) + `admin.js` + `admin.blade.php`: 4 tab **Tổng quan · Người dùng · Gói cước · Sổ credit**. Phân quyền 2 tầng: dashboard/plans/ledger = `admin`; CRUD tài khoản = `superadmin` (+ `UserPolicy`). Chặn **tự hạ quyền / tự khoá chính mình**; modal `role=dialog` + Esc; xoá có bước xác nhận. 11 test `AdminUsersTest` + 6 test `PlanManagementTest` + mutation-test (bỏ guard tự-hạ-quyền ⇒ RED). Sau đó thêm tab **🎨 Giao diện** (`305392e`, việc song song) |
| — | **Người dùng TỰ đăng ký gói** (self-service) | ✅ XONG | `436675d` | `BillingController`: `GET /api/billing/plans` (công khai — cho trang giá) + `POST /api/billing/subscribe` (`auth`+`can-studio`) → `PlanService::assign`. 6 test `BillingSubscribeTest`. ⚠️ **CHƯA có cổng thanh toán VNĐ** — đăng ký gói trả phí hiện = kích hoạt gói; thu tiền (VNPay/MoMo/chuyển khoản) là bước sau, đã ghi ở `PRICING.md` §4 |
| — | **Chiến lược giá VNĐ** | ✅ XONG | `adc2700` | `PRICING.md`: chi phí gốc Qwen (base/2512 ≈500₫/ảnh 1K · edit ≈750₫ · Max ≈1.800₫) vs giá bán · đối thủ (Shopee AI miễn phí ⇒ moat phải là workflow/tiếng Việt/giá VNĐ) · đơn vị kinh tế · gói nạp thêm · lộ trình 4 giai đoạn. Nguồn: qwencloud.com/models + bảng giá Model Studio (Alibaba) |
| — | **Deploy production** (đưa tới `436675d`) | ✅ ĐÃ LÊN | — | `git pull --ff-only` → `dump-autoload` (**BỊ CHẶN `proc_open` trên Hostinger** — PSR-4 vẫn nạp được `BillingController`, đã verify `class_exists` = true) → `package:discover` tay → `db:seed --class=PlanSeeder --force` → `config:cache` + `route:cache` + `view:cache` → `queue:restart`. **Không có migration mới** (tính năng billing dùng bảng `plans` sẵn có ⇒ rủi ro schema = 0). Verify: `/` `/dang-nhap` `/up` = **200** · khách `/admin` = **302** · `/api/billing/plans` = **200 (đủ 4 gói)** · `plans` = 4 · log **không có ERROR/CRITICAL mới** |

> ⚠️ **Nợ còn lại của mảng này:** (a) chưa có cổng thanh toán VNĐ (đăng ký gói = kích hoạt, không thu tiền); (b) gia hạn tự động theo chu kỳ chưa có (hạn đặt tay lúc gán gói); (c) gói chưa phân hoá trọng số credit theo model (cột `image_credit_cost`/`video_credit_cost` đã có sẵn trong `plans` nhưng pipeline còn đọc `config/studio.php`).

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
- `29339c6` **Thiết kế lại UX/UI trang `/settings`** (đã deploy 2026-09-18 — xem `DEPLOY.md` §9.1sexies): `resources/js/studio/SettingsApp.vue` viết lại (789 → 1.470 dòng), `resources/js/studio/icons.json` +5 icon (`activity`, `key`, `globe`, `server`, `shieldCheck` — thêm 1 khoá là mọi nơi tự có). **Không đổi backend**: giữ nguyên 15 endpoint `/api/settings-vue/*`, payload, thứ tự ưu tiên provider và luật key write-only.
  · Cấu trúc mới: điều hướng dọc theo 4 NHÓM (Bắt đầu · Nhà cung cấp · Model · Vận hành) có badge trạng thái; màn < 1024px gom thành dải cuộn ngang. Mục **Tổng quan** mới: 4 thẻ số liệu + danh sách "Việc cần xử lý" (bấm là mở sẵn form đã điền trước) + chuỗi fallback + số liệu sử dụng.
  · Thêm/sửa chuyển vào hộp thoại `BaseModal` (focus trap sẵn có) ⇒ trang chỉ còn danh sách; xoá qua hộp thoại xác nhận nói rõ hậu quả thay cho `confirm()`; thêm tìm kiếm/lọc cho Keys · Providers · Models; khối giải thích dài gom vào `<details>`; deep-link `?tab=`.
  · **Sửa lỗi dữ liệu**: thẻ "Sử dụng" cũ đọc `images`/`videos`/`credits_used` — các field KHÔNG tồn tại trong `studio_usage()` nên luôn hiển thị 0; nay dùng `balance`/`used_total`/`used_today`/`limit`/`quota_resets_at`.
  · Đã kiểm: `npm run build` ✓ · `php artisan test` **491 pass / 2657 assert** ✓ · đo bằng Chrome headless trên 390/500/820/1024/1500px: 0 tràn ngang, 0 icon rỗng, 0 lỗi JS, **0/867 nút chữ dưới WCAG AA** (đã nâng các mức `text-cream-300/40–60` lên `/75–/85`) · 6 kịch bản thao tác (thêm/xoá key, gán model nhóm, đổi thứ tự luồng, trạng thái chưa lưu của Cấu hình, tìm kiếm) đều đạt · smoke test app thật: đăng nhập → `/settings` 200 + bundle mới.

### 3.5bis Quản trị /admin (thiết kế lại UX/UI, 2026-09-18)

- `d4817bb` **Thiết kế lại UX/UI trang `/admin`** (đã deploy 2026-09-18 — xem `DEPLOY.md` §9.1septies): `resources/js/studio/AdminApp.vue` viết lại (715 → 1.270 dòng), `icons.json` +2 icon (`package`, `receipt`). **Không đổi backend** — vẫn đúng bộ endpoint `/api/admin/*` + `/api/boot` và phân quyền 2 tầng (admin cho dashboard/plans/ledger/gui; super_admin cho users).
- Cấu trúc mới: danh mục dọc theo 3 nhóm (Bắt đầu · Người & credit · Hệ thống) có badge trạng thái; màn < 1024px gom thành dải cuộn ngang; mục **Tổng quan** có "Việc cần xử lý" (chưa có gói mặc định · tất cả gói bị ẩn · chưa ai trả phí · chưa phát sinh tiêu credit 30 ngày · nút đang bị ẩn) + phân bố gói kèm số người và %; deep-link `?tab=`.
- **Phân quyền được HIỂN THỊ**: vai trò không phải Owner không còn thấy mục Người dùng rồi nhận 403 khô khan — mục bị ẩn, có băng cảnh báo và bảng giải thích khi vào thẳng `?tab=users`. Đã đối chiếu với máy chủ: `/api/boot.is_super_admin=false` ⇒ `GET /api/admin/users` = **403** (khớp đúng UI).
- Hộp thoại chuyển sang `BaseModal` (focus trap + Esc + `aria-modal`) thay cho 6 lớp phủ tự viết tay không giữ focus; xoá/khôi phục mặc định qua hộp thoại xác nhận nói rõ hậu quả (thay `confirm()`); mọi nút đổi từ emoji sang `StudioIcon` + nhãn chữ/`aria-label`.
- Dùng dữ liệu máy chủ trước đây bị bỏ: `type_label` và **người thực hiện** (`admin`) trong sổ cái, `role_label`, `users_count`/`price_label` của gói, `phone`, và tham số lọc `user_id` (nút "Xem sổ credit" của từng người). Thêm chọn số dòng/trang (20/50/100) và tìm kiếm/lọc cho cả 3 bảng.
- **Lỗi bắt được khi kiểm thử**: hàm `run()` cũ trả về giá trị của callback; với closure `async () => { await api(...) }` (không return) thì kết quả là `undefined` ⇒ tạo người dùng thành công nhưng **hộp thoại không đóng và danh sách không nạp lại**. Nay `run()` trả `true/false`, chỗ cần dữ liệu trả về (khôi phục thanh công cụ) gọi API trực tiếp.
- Đã kiểm: `php artisan test` **491 pass / 2657 assert** ✓ · đo bằng Chrome headless ở 390/500/820/1024/1500px: 0 tràn ngang · 0 icon rỗng · 0 lỗi JS · header/sidebar sticky đúng · **0/563 đoạn chữ dưới WCAG AA** · **47/47 bước của 11 kịch bản thao tác** (tạo người dùng, chặn submit khi thiếu dữ liệu, cộng/trừ credit, khoá tài khoản, xoá người dùng, tạo gói + đặc quyền, lọc sổ cái theo người & theo loại, sửa+lưu thanh công cụ, tìm kiếm, phân trang, vai trò không phải Owner) đều đạt · smoke test app thật: owner → `/admin` 200 + 5 endpoint 200; vai trò Quản trị → `/admin` 200 nhưng `/api/admin/users` 403.

### 3.5ter Gói cước THẬT — cấp credit theo chu kỳ + chi phí theo gói (2026-09-19)

**Lỗ hổng phát hiện khi phân tích gói cước** (mọi kết luận đều có bằng chứng đọc mã):
1. `plans.credits_per_month` **chưa từng được cấp**: `routes/console.php` chỉ có `inspire`; grep `Schedule::|->monthly|->daily` trong `app/` `routes/` `bootstrap/` = **0**; `PlanService::assign()` chỉ tặng `bonus_credits` một lần ⇒ khách trả 499.000 ₫ cho "350 credit/tháng" mà không nhận được gì sau tháng đầu.
2. `plans.image_credit_cost` / `video_credit_cost` là **cột trang trí**: pipeline đọc `studio_config('image_credits')` ở **9 chỗ** (`StudioController.php` 186 · 219 · 321 · 502 · 552 · 721 · 788 · 1156) ⇒ mọi gói tiêu credit như nhau.
3. `resolution_cap` không được thực thi (chỉ xuất hiện ở `BillingController.php:38`).
4. **Không có giao diện gói cước**: `grep -rn 'billing' resources/js` = 0 ⇒ 2 endpoint `/api/billing/*` không có ai gọi; `/api/boot` không trả thông tin gói (`StudioController.php:46-56`).
5. **Không chặn khi hết credit**: `StudioController.php:1600` — *"never hard-block on credits"*; grep `credits_balance <` toàn repo = 0.
6. Không có trang giá công khai (`resources/views/` không có view pricing).

**Đã sửa (đợt 1)**:
- `PlanService::syncCycleCredits()` — cấp credit chu kỳ **idempotent** bằng CAS (`UPDATE … WHERE plan_credits_granted_at IS NULL OR < mốc chu kỳ`) trong transaction; chu kỳ = 1 tháng tính ngược từ `plan_expires_at` (gói trả phí) hoặc tháng dương lịch (gói miễn phí); gói **hết hạn ⇒ không cấp**.
- Migration `2026_09_19_000002_add_plan_credits_granted_at_to_users_table.php` (nullable — người dùng cũ được cấp bù đúng một lần) + cast `datetime` trong `User::casts()`.
- Lệnh cron `php artisan studio:grant-plan-credits [--dry-run] [--limit=] ` (đăng ký ở `bootstrap/app.php`) **+ đường lazy** trong `/api/boot` và `queueGeneration()` ⇒ không phụ thuộc cron trên host.
- `studio_credit_cost('image'|'video')` (helper mới) — **8 chỗ** trong pipeline nay lấy chi phí THEO GÓI, fallback setting toàn cục (tương thích ngược: gói mặc định 1/10 cho kết quả y hệt).
- `CreditTransaction::TYPE_PLAN_GRANT = 'plan_grant'` ("Cấp theo gói") + bổ sung vào bộ lọc Sổ credit ở trang Quản trị.
- `/api/boot` trả `user.plan` (slug · giá · credit/tháng · chi phí ảnh/video · cap · hạn · mốc cấp · is_subscribed).
- 2 test cũ khoá **hành vi sai** đã cập nhật theo hợp đồng đúng: `BillingSubscribeTest` (đăng ký gói cấp bonus **+ credit kỳ đầu**) và `PlanManagementTest`.

**Kiểm chứng**: `PlanCreditCycleTest` 8 test mới (cấp một lần/chu kỳ · gia hạn cấp tiếp · gói hết hạn không cấp · gói miễn phí 0 credit · dry-run không ghi · chi phí theo gói khi tạo ảnh · boot trả gói) · **499 test / 2.682 assert xanh** (trước: 491/2.657) · chạy thật trên DB local: gán gói Khởi nghiệp cho `user@fabrikai.shop` ⇒ credit 200 → **350** và **đúng một** dòng `plan_grant` *"Cấp 120 credit theo gói Khởi nghiệp (kỳ từ 17/09/2026)"*.

**Tài liệu chiến lược kèm theo**: `docs/DESIGN_SYSTEM.md` §11–§13 (3 persona · 6 lỗ hổng gói cước có bằng chứng · 7 nguyên tắc thiết kế · roadmap có tiêu chí đo · 4 câu hỏi cần chủ dự án quyết) — tài liệu `docs/UX_PERSONA_STRATEGY.md` đã được **hợp nhất** vào đó ngày 2026-09-22.

**Còn nợ (các vòng sau)**: UI gói cước trong Studio · cờ `studio_enforce_credits` (hiện vẫn không chặn khi hết credit) · thực thi `resolution_cap` · trang giá công khai · không gian làm việc theo persona (bộ sưu tập/đơn/xuất gói cho xưởng).

**Vòng 2 (2026-09-19) — thực thi đặc quyền gói + UI Gói & credit trong Studio:**
- `studio_plan_limits()` (helper) + `clampResolutionToPlan()`: **hạ** độ phân giải vượt cap của gói thay vì chặn, kèm `notice` nói rõ lý do; cap video suy từ cap ảnh (1K⇒720p, 2K⇒1080p). Trước đây `plans.resolution_cap` không được kiểm ở đâu ⇒ gói Miễn phí 1K và gói Studio 2K cho ra ảnh giống hệt nhau.
- Cờ `studio_enforce_credits` (config + setting DB, **mặc định TẮT**): bật thì thiếu credit ⇒ **402** kèm hướng dẫn; tắt thì giữ nguyên "never hard-block". Thêm `credit_warning` cho mọi response tạo ảnh/video khi credit < 3 thao tác ⇒ cảnh báo TRƯỚC, không chặn giữa việc.
- **`GET /api/plan/status`** (nhóm STUDIO): gói hiện tại · credit · hạn mức · chi phí theo gói · cảnh báo · danh mục gói đang mở bán.
- **UI**: badge credit trên thanh công cụ Studio (trước đây là `<span>` chỉ hiện con số) nay là **nút mở popup "Gói & credit"** — tên gói · credit còn lại · chi phí ảnh/video theo gói · độ phân giải tối đa · hạn gói · danh mục gói + nút đổi gói (nói rõ *hệ thống chưa có cổng thanh toán*). Store thêm `planStatus`, `loadPlanStatus()`, `subscribePlan()`, getters `planName`/`planCostImage`/`planCostVideo`/`creditsLow`.
- **Kiểm chứng**: `PlanLimitsTest` 7 test mới (hạ 2K→1K + notice · gói 2K giữ nguyên · cap video theo gói · mặc định KHÔNG chặn · bật cờ thì 402 và không tạo generation/không trừ credit · /api/plan/status đủ trường · 401 cho khách) · **506 test / 2.708 assert xanh** · dựng harness Studio thật (stub API) + điều khiển Chrome qua CDP: **16/16 bước** (nút credit hiện đúng số + tên gói · popup mở · đủ chi phí/hạn mức/hạn gói · mở danh mục · gói đang dùng bị khoá · nói thật về thanh toán · **đổi gói xong credit 200 → 320**) · popup nằm trong khung nhìn, 0 tràn ngang, 0 icon rỗng, **0/34 đoạn chữ dưới WCAG AA**.

**Vòng 3 (2026-09-19) — trang GIÁ công khai `/bang-gia` (đóng Đợt 1):**
- Lỗ hổng đã đóng: khách **chưa đăng nhập** không có cách nào xem gói — không view pricing, trang đăng ký không nhắc gói nào, toàn bộ UI gói nằm sau đăng nhập ⇒ phải tạo tài khoản mới biết giá.
- `BillingController::pricingPage()` + view `resources/views/pricing.blade.php` (render phía máy chủ, **không cần JS**, xem được trên mọi thiết bị, chia sẻ được qua Zalo): hero · **3 persona** (nhà thiết kế · chủ doanh nghiệp · chủ xưởng may: nỗi đau + việc cần làm + gói khởi đầu/gói lớn lên) · bảng gói **đọc từ DB** (`plans` đang mở bán) · bảng so sánh · 4 bước từ ý tưởng tới ảnh bán được · 6 câu hỏi thường gặp · CTA theo trạng thái đăng nhập · ghi rõ **chưa có cổng thanh toán trực tuyến**.
- Bất biến chống "bảng giá mẫu": giá/credit/số ảnh quy đổi đều tính từ `plans` ⇒ chủ dự án đổi giá trong trang Quản trị là trang giá đổi theo; gói `is_active=false` **không lộ ra** (có test).
- Giữ bất biến bảo mật: blade **không dùng raw echo** (4 icon trang trí viết markup tĩnh — cùng path với `icons.json`) ⇒ `test_blades_contain_no_raw_output_sink` vẫn xanh.
- **Kiểm chứng**: `PricingPageTest` 5 test mới (khách xem được · đủ mọi gói đang bán + số liệu sống theo DB · gói ẩn không lộ · nói thật về thanh toán + có 3 persona · có link đăng ký) · **511 test / 2.726 assert xanh** (trước 506) · đo trên trình duyệt thật (Chrome CDP) ở **1500/1024/820/500/390px**: 0 tràn ngang, **0/192 đoạn chữ dưới WCAG AA** (đã sửa 1 lỗi: class `.kicker` vốn dành cho theme sáng, chỉ đạt 3.04 trên nền tối) · kiểm luồng thật: khách bấm CTA → `/dang-ky`; đăng nhập thật bằng form → CTA đổi thành "Vào Studio"/"Kích hoạt trong Studio".

**Vòng 4 (2026-09-19) — TẠO HÀNG LOẠT (tiết kiệm thao tác, Đợt 3):**
- Việc thật của nhà thiết kế/chủ shop là ra ảnh cho CẢ BỘ (8 SKU × 2 bối cảnh = 16 ảnh); trước đây phải sửa prompt rồi bấm tạo **16 lần**.
- **Tab "Hàng loạt"** trong card Tạo ảnh (`ConceptCard.vue`): dán danh sách mỗi dòng một sản phẩm (tối đa **12**/lượt) × **biến thể 1–4**, hiện trước **số ảnh + credit ước tính theo gói**, cảnh báo khi không đủ credit, tiến trình gửi từng mục, toast tổng kết; **mục lỗi không làm hỏng cả lượt** (báo riêng mục nào).
- **Store**: tách `imagePayload(prompt, variants)` — bộ dựng payload **dùng chung** cho tạo lẻ và tạo hàng loạt (chống lệch cấu hình) + thêm `generateBatch()` gọi tuần tự `/api/generate` (KHÔNG thêm endpoint mới, nên mỗi ảnh vẫn đi đúng đường cũ: trừ credit theo gói · ghi sổ cái · hạ cap độ phân giải theo gói và trả `notice`). Thêm state `batchSend` cho tiến trình gửi (khác `generateProgress` = % render thật).
- Giới hạn chủ đích: 12 mục/lượt, 4 biến thể/mục ⇒ tối đa 48 ảnh/lượt, không dội hàng đợi provider.
- **Kiểm chứng**: `BatchGenerationTest` 4 test mới (1 request variants=N ⇒ đúng N generation + trừ đúng N credit · mô phỏng vòng lặp của UI: 3 mục × 2 ⇒ 3 nhóm prompt riêng, mỗi nhóm 2 ảnh · store có `generateBatch` + **cả hai đường gọi cùng `imagePayload()`** và **vẫn không có `setInterval(`** theo bất biến Đợt 0.2 · `ConceptCard` có tab batch + `BATCH_MAX_ITEMS` và **không thêm nút mới** vào thanh công cụ (giữ bất biến 10 mục/7 panel của `StudioGuiConfig`).
- **Chạy thật trong Studio** (harness + Chrome CDP): **24/24 bước** — tạo lẻ: 1 lần gọi, payload đúng prompt/variants/resolution; hàng loạt: 3 mục × 2 biến thể ⇒ **3 lần gọi**, đúng prompt từng dòng, đúng số biến thể, **credit 200 → 194**, có tiến trình gửi + toast tổng kết.
- **Toàn bộ suite**: **515 test / 2.751 assert xanh** (trước 511).
- Phát hiện & sửa trong vòng: bản vá "tách payload dùng chung" lần đầu **không áp được** (lỗi parse chặn cả chương trình) — test mới `substr_count(store, 'this.imagePayload(') === 2` đã bắt đúng và buộc hoàn tất việc tách.

**Vòng 5 (2026-09-19) — PANEL "BỘ SƯU TẬP" (không gian làm việc theo nghề, Đợt 2):**
- Lỗ hổng: người làm nghề nghĩ theo **bộ sưu tập/đơn hàng**, nhưng thông tin đó nằm rải rác — popover "Dự án" chỉ để ÁP DỤNG; tiến độ, hạn chót, việc đang chạy không thấy ở đâu ⇒ mỗi phiên phải tự nhớ đang ở bộ nào.
- **Nhóm card MỚI `collections`** ("Bộ sưu tập") đứng đầu thanh công cụ trái: (1) **Đang làm** — bộ đang áp dụng + trạng thái + số ảnh + hạn chót đếm ngược + nút Mở workspace/Bỏ áp dụng; (2) **Tạo bộ sưu tập** ngay trong panel (tên · mùa/vụ → tags · hạn chót · brief) rồi **tự áp dụng**; (3) **Bộ sưu tập gần đây** (5 bộ, trạng thái màu theo máy chủ, số ảnh, hạn chót, bấm là làm việc trên bộ đó); (4) **Việc đang chạy** (chờ xử lý/đang tạo, nút Xử lý ngay).
- **Sửa lỗi thật khi kiểm**: panel không tự nạp danh sách ⇒ vào panel thấy TRỐNG dù đã có bộ sưu tập. Nay gọi `loadProjects()` khi mount (cùng mẫu với LibraryApp/PromptLibraryTab).
- **Cầu nối mới**: card render bằng `<component :is>` nên không nhận prop/event ⇒ `store.requestWorkspace()` + watcher ở StudioApp để nút "Mở workspace" chạy được từ trong card.
- Cấu hình owner an toàn: `StudioGuiConfig::all()` nối id mới vào cuối bản đã lưu ⇒ không mất mục nào; 2 test cũ cập nhật **có ý thức** (7 → **8** panel; thứ tự gốc nay Bộ sưu tập → Tạo ảnh).
- **Kiểm chứng**: `CollectionsHubTest` 4 test mới (hợp đồng API tạo bộ giữ brief/hạn/mùa vụ + danh sách trả đủ trường card dùng + wiring tĩnh + card chỉ dùng action có sẵn, không tự gọi endpoint) · **519 test / 2.789 assert xanh** (trước 515) · chạy thật trong Studio (Chrome CDP): **17/17 bước**.

**Vòng 6 (2026-09-19) — XUẤT GÓI CHO XƯỞNG (Đợt 4, phần đầu):**
- Lỗ hổng: chủ xưởng may không cần "một tấm ảnh đẹp" — cần **gói đủ nghĩa để cắt may** (ảnh tham chiếu + phiếu kỹ thuật + bảng size). Trước đây chỉ có ảnh trong thư viện, muốn gửi xưởng phải tải từng ảnh rồi tự soạn mô tả.
- `ProjectExportService` + `GET /api/projects/{project}/export` ⇒ 1 file ZIP: `anh/NN-<mô tả>.<ext>` (đánh số, nhận dạng đuôi bằng `getimagesizefromstring`) · `phieu-ky-thuat.txt` (ô trống cho xưởng điền chất liệu/màu/đường may + ô xác nhận hai bên) · `bang-size.csv` (nhập thì dùng, không nhập thì phát mẫu S/M/L/XL) · `thong-tin-bo-suu-tap.txt` (brief của khách + ghi chú khi xuất) · `manifest.json` · `README.txt`.
- **Bất biến TRUNG THỰC**: ảnh không tải được ⇒ ghi rõ vào `anh/_KHONG_TAI_DUOC.txt` + `manifest.skipped` + cảnh báo trong README. README cũng nói rõ **ảnh AI là ảnh tham chiếu**, không in/cắt thẳng để sản xuất hàng loạt.
- Kỹ thuật: đọc ảnh từ data URI · URL http(s) (qua `studio_fetch_remote_bytes`, có allowlist host) · `/storage/...` (qua `StudioLibraryService::urlToPath`, có chặn traversal) · file trong `public_html`; ZIP dựng bằng `ZipArchive` (đã kiểm có ở CẢ local và host), `deleteFileAfterSend(true)` nên không để rác trong `/tmp`.
- **UI**: nút **Xuất gói cho xưởng** trong panel "Bộ sưu tập" (bộ đang áp dụng) → nhập **bảng size** + **ghi chú kỹ thuật** → tải ZIP; mô tả rõ gói gồm gì trước khi tải.
- Lỗi bắt được khi viết test: `projects.tags` cast `ArrayObject` ⇒ `implode()` ném TypeError (500). Đã chuẩn hoá qua một hàm `tags()` dùng chung trong service.
- **Kiểm chứng**: `ProjectExportTest` 5 test mới (khách 401 · người khác 403 · đủ 5 thành phần + bảng size đi nguyên vào gói + brief/ghi chú có trong gói + disclaimer tham chiếu · ảnh lỗi được BÁO trong gói · bộ chưa có ảnh vẫn xuất được) · **524 test / 2.825 assert xanh** (trước 519) · UI Chrome CDP **12/12 bước** · chạy thật trên production bằng tinker (tạo bộ tạm → dựng ZIP → mở ZIP đọc lại → xoá bộ tạm).

**Vòng 7 (2026-09-19) — CHIA SẺ LINK CHO KHÁCH DUYỆT (Đợt 4, phần còn lại):**
- Lỗ hổng: chủ doanh nghiệp/thương hiệu cần gửi bộ sưu tập cho KHÁCH hoặc người duyệt nội bộ — người **không có tài khoản FabrikAI**. Trước đây chỉ có workspace trong app ⇒ thực tế vẫn chụp màn hình gửi Zalo, phản hồi trôi mất.
- **Bảng mới** `project_shares` (token 48 ký tự · `expires_at` · `revoked_at` · `views` · `last_viewed_at`) và `project_feedback` (`author_name` · `decision` approved/changes · `message`).
- **Trang công khai** `/chia-se/{token}` (Blade render phía máy chủ, khách không cần đăng nhập, **noindex**): tên bộ · trạng thái · hạn chót · tags · brief của khách · lưới ảnh (bấm xem ảnh gốc) · form phản hồi (tên + Duyệt/Yêu cầu sửa + ghi chú) · danh sách phản hồi trước đó.
- **API**: `POST /api/projects/{id}/share` (tạo, dùng lại link còn hiệu lực — không rải nhiều link) · `GET` trạng thái + phản hồi · `DELETE /api/projects/{id}/share/{share}` thu hồi. Quyền: chủ bộ sưu tập hoặc Super Admin (giống show/export).
- **An toàn**: token ngẫu nhiên 48 ký tự; hết hạn/thu hồi/không tồn tại đều **404 giống nhau** (không dò token); `throttle:share-feedback` 10 lần/phút theo IP; validate tên (bắt buộc, ≤120) · quyết định (in approved,changes) · ghi chú (≤1000).
- **Cố ý KHÔNG tự đổi trạng thái** bộ sưu tập khi khách bấm "Duyệt": chuyển trạng thái có whitelist + phân quyền riêng ở `ProjectWorkflowService` — ở đây chỉ GHI LẠI phản hồi để chủ quyết.
- **UI**: nút **Chia sẻ cho khách** trong panel "Bộ sưu tập": chọn hiệu lực 7/30/90 ngày → tạo link (tự copy) → hiện lượt xem/hạn/xem gần nhất → Copy link · Thu hồi; hiển thị 3 phản hồi mới nhất.
- **Kiểm chứng**: `ProjectShareTest` 8 test mới (quyền 401/403 · dùng lại link · khách xem được ảnh+brief+noindex · token sai/hết hạn/thu hồi ⇒ 404 · thu hồi làm link ngừng hoạt động · phản hồi được lưu và chủ thấy qua API · validate · không xem được trạng thái của người khác) · **532 test / 2.871 assert xanh** (trước 524) · trang công khai đo trên Chrome CDP ở **1500/820/390**: 0 tràn ngang, **0/23 đoạn chữ dưới WCAG AA** · **gửi phản hồi thật bằng trình duyệt** (guest) ⇒ có dòng trong DB + banner cảm ơn + phản hồi hiện trong danh sách · chạy thật trên production (tạo bộ + link tạm → mở link công khai → gửi phản hồi bằng curl → xoá dữ liệu tạm).
- Test bất biến của chính tôi bắt được một vi phạm: UI chia sẻ ban đầu gọi `fetch('/api/...')` **trực tiếp trong card** — đã chuyển thành 3 action trong store (`loadShareStatus`/`createShare`/`revokeShare`) để giữ bất biến "một đường dữ liệu đi qua store".

**Vòng 8 (2026-09-19) — MẪU VIỆC THEO NGÀNH (Đợt 2):**
- Vấn đề: người mới mở Studio gặp **ô prompt trống** và tab **Hàng loạt trống** — họ biết việc cần làm ("chụp lookbook bộ Thu Đông", "ra ảnh đăng sàn", "gửi mẫu kỹ thuật cho xưởng") nhưng không biết gõ gì, chọn tỉ lệ nào, cần mấy kiểu ảnh. Đó là chỗ tốn thời gian nhất của lần dùng đầu.
- **`App\Support\IndustryTemplates`** (nguồn DUY NHẤT, ở PHP) + `GET /api/job-templates`: 4 mẫu trọn vẹn — **Lookbook bộ sưu tập** (6 prompt · 4:5 · 2K) · **Ảnh đăng sàn TMĐT** (4 · 1:1 · 2K) · **Mẫu kỹ thuật gửi xưởng** (4 · 3:4 · 2K · **kèm bảng size + ghi chú kỹ thuật**) · **Catalogue nhiều SKU** (3 · 4:5 · 1K).
- **UI**: khối "Bắt đầu từ mẫu việc" trong tab Hàng loạt — bấm một mẫu là **điền sẵn** danh sách prompt, **đặt luôn tỉ lệ + độ phân giải**, và với mẫu của xưởng thì **điền sẵn khối "Xuất gói cho xưởng"** (bảng size + ghi chú) qua `store.pendingExport`. Mẫu chỉ được nạp khi người dùng mở tab Hàng loạt (không tốn request lúc mở Studio).
- **Chống lệch nguồn dữ liệu**: test cấm chép prompt mẫu vào JS (`assertStringNotContainsString('Lookbook bộ sưu tập', ConceptCard)`) — sửa/thêm mẫu ở PHP là mọi nơi có ngay.
- **Kiểm chứng**: `JobTemplatesTest` 6 test mới (cần đăng nhập · đủ 4 mẫu · **mọi mẫu hợp lệ với whitelist của /api/generate** (tỉ lệ · 1K/2K · prompt không rỗng · ≤4000 ký tự · ≥2 mục để dùng hàng loạt) · mẫu xưởng có bảng size + ghi chú chất liệu · id không trùng + nguồn dữ liệu một chỗ · mẫu xưởng chảy vào khối xuất gói) · **538 test / 2.967 assert xanh** (trước 532) · UI chạy thật (Chrome CDP): **14/14 bước** — chip mẫu hiện đủ 4, bấm mẫu xưởng ⇒ ô hàng loạt có **đúng 4 dòng** prompt kỹ thuật, tỉ lệ/độ phân giải đổi theo mẫu, và khối xuất gói **được điền sẵn** "S, 96, 80, 100…" + "cotton poplin".

**Vòng 9 (2026-09-19) — CHI PHÍ/TIẾN ĐỘ THEO BỘ SƯU TẬP + CHẠY LẠI CHỈ MỤC LỖI (Đợt 2 & 3):**
- **Thống kê bộ sưu tập** `GET /api/projects/{id}/stats` (chủ bộ sưu tập hoặc Super Admin): đếm theo trạng thái + **cộng `credits_cost`** bằng MỘT truy vấn `group by status` (không N+1) · **hạn còn lại dạng số ngày** (âm = quá hạn) · gộp **phản hồi mới nhất của khách** (từ link chia sẻ). UI: khối "Chi phí & tiến độ" trong panel Bộ sưu tập, có nút nạp lại; store nhớ theo id (`projectStats`) để bấm qua lại giữa các bộ không gọi liên tục.
- **Tiến trình từng mục + chạy lại chỉ mục lỗi** trong lượt hàng loạt: `batchSend.items` ghi lại từng prompt (xong/lỗi + lý do), danh sách `batchFailed` được GIỮ LẠI sau khi lượt kết thúc ⇒ nút **"Chạy lại N mục lỗi"** chạy lại đúng những mục đó thay vì bắt người dùng nhớ và gõ lại (trước đây mục lỗi chỉ hiện toast rồi mất).
- Lỗi tự bắt khi viết code: `stats(): JsonResponse` không import `Illuminate\Http\JsonResponse` ⇒ PHP hiểu là `App\Http\Controllers\JsonResponse` và ném `TypeError` (endpoint 500). Test bắt ngay ở lần chạy đầu (5 test đỏ) — đã sửa thành FQCN. Đây là **lần thứ hai** trong chuỗi goal gặp đúng lớp lỗi này (lần trước với `User`), nên khi thêm method có type hint trong controller phải dùng FQCN.
- **Kiểm chứng**: `ProjectStatsTest` 6 test mới (quyền 401/403 · đếm theo trạng thái + cộng credit chính xác (2 ảnh completed tốn 1+3 credit ⇒ used=7) · hạn còn lại kể cả quá hạn (3 và −2 ngày) · gộp phản hồi mới nhất · bộ trống trả 0 không lỗi · bất biến tĩnh cho UI chạy lại mục lỗi) · **544 test / 2.998 assert xanh** (trước 538) · UI chạy thật (Chrome CDP): **15/15 bước** — thống kê hiện đủ 7 ảnh xong/1 đang chạy/1 lỗi/11 credit/còn 3 ngày/phản hồi khách; lượt hàng loạt có 1 mục lỗi ⇒ hiện đúng dòng lỗi + nút chạy lại, bấm nút ⇒ **đúng 1 lời gọi với đúng prompt bị lỗi**.

**Vòng 10 (2026-09-19) — DUYỆT MẪU THEO LÔ: vòng đời duyệt ảnh từ "tính năng chết" thành việc dùng được (Đợt 2):**
- **Lỗ hổng (greppable)**: máy trạng thái duyệt ảnh đã có từ Đợt 1.1 — `Generation::SHOT_STATES` (idea → drafted → selected → fitted → campaign_ready → approved/rejected) + whitelist `SHOT_TRANSITIONS` + `transitionShotState()` + endpoint lẻ `POST /api/generations/{id}/shot-state` — nhưng **`grep -rn 'shot-state' resources/js` = 0** và `show()` **không trả `shot_state`**. Nghĩa là: với người dùng, tính năng duyệt ảnh hoàn toàn KHÔNG TỒN TẠI (không thấy ảnh nào đã chốt/đã loại, không có chỗ để duyệt).
- **`POST /api/projects/{id}/shots/review`** — duyệt NHIỀU ảnh trong MỘT lượt, ba thao tác: `approved` (chốt) · `rejected` (loại) · `next` (đẩy lên bước kế tiếp trên đường thuận). Một buổi duyệt thật là "xem 20 ảnh, chốt 12, loại 8" ⇒ gọi 20 request lẻ vừa chậm vừa không có chỗ báo "3 ảnh không duyệt được vì sao". Endpoint trả **kết quả TỪNG ẢNH** (`ok` + `error`) nên giao diện nói thật thay vì im lặng bỏ qua.
- **Luật giữ nguyên, không nới cho thao tác theo lô**: mọi bước chuyển vẫn đi qua `transitionShotState()` (whitelist của model); `next` được giải thành trạng thái cụ thể bằng `Generation::nextShotState()` **rồi vẫn qua whitelist**. Thêm `SHOT_APPROVED`/`SHOT_REJECTED`/`SHOT_FLOW`/`SHOT_LABELS` (một nguồn nhãn + luật). Quyền: chủ bộ sưu tập hoặc Super Admin; ảnh phải THUỘC bộ sưu tập đang gọi; chỉ ảnh đã tạo xong; tối đa 60 ảnh/lô; id lặp chỉ xử lý một lần.
- **`show()` trả `shot_state` · `shot_label` · `note` · `is_selected`** ⇒ giao diện biết ảnh nào chờ duyệt/đã chốt/đã loại (trước đây phải tự đoán).
- **UI (panel Bộ sưu tập)**: nút **Duyệt mẫu (N)** kèm badge số ảnh đang chờ duyệt; khối duyệt gồm lưới thumbnail có **nhãn trạng thái**, chọn nhiều ảnh (tự chọn sẵn ảnh *Chờ duyệt*), 3 nút **Chuyển bước tiếp / Duyệt N ảnh / Loại N ảnh**, ô **ghi chú duyệt**, dòng nhắc giải thích từng bước, và **danh sách LÝ DO** cho những ảnh không đổi được (kèm ảnh đang ở bước nào). Store thêm `loadProjectShots()` + `reviewShots()` + `shotLabel()` (card KHÔNG tự gọi API — giữ bất biến một đường dữ liệu qua store).
- **Kiểm chứng**: `ShotReviewTest` 9 test mới (quyền 401/403 · duyệt lô chỉ đổi ảnh hợp lệ + lý do cho ảnh nhảy cóc · `next` tiến ĐÚNG một bước + chặn ở bước cuối · ảnh ngoài bộ sưu tập bị từ chối từng ảnh · ảnh chưa render xong không duyệt được · lưu ghi chú + validate lô rỗng/61 ảnh/trạng thái lạ + id lặp · ảnh bị loại quay lại được bản nháp · `show()` lộ trạng thái duyệt · **bất biến chống "tính năng chết"**: store + card THẬT SỰ gọi vòng đời này và controller đi qua model) · **553 test / 3.054 assert xanh** (trước 544/2.998).
- **Chạy thật trong Studio** (harness + Chrome CDP): **33/33 bước** ở 1500px **và** 1024px — badge "Duyệt mẫu (2)" · mở khối · nhãn trạng thái trên 4 thẻ ảnh · tự chọn sẵn 2 ảnh chờ duyệt · thêm ảnh nháp vào lô (3/4) · **đúng 1 lời gọi** với ids `[101,102,104]` + note · 2 ảnh đổi sang *Đã duyệt* (bộ đếm 3) · ảnh nháp **giữ nguyên** *Bản nháp* + hiện lý do whitelist "Ảnh #104 (Bản nháp): Không thể chuyển shot từ 'drafted' sang 'approved'…" · **Chuyển bước tiếp** ⇒ ảnh lên *Đã chọn* · **Loại** ⇒ *Đã loại* + bộ đếm "1 đã loại" · 0 tràn ngang · **0 đoạn chữ dưới WCAG AA** · ảnh thumbnail tải thật (120×160). Mobile 390px (qua nút "Mở menu công cụ"): **8/8 bước**, 0 tràn ngang, 0 lỗi tương phản; 820px: khối nằm gọn trong panel, 0 tràn.
- **Chạy thật trên PRODUCTION** (sau deploy `010d5ae`, user thật `tuan.ho.designer@gmail.com`, dữ liệu tạm đã xoá — **SẠCH**): lượt 1 duyệt 2 ảnh ⇒ `HTTP 200 · reviewed=1 · failed=1` với ảnh còn ở *drafted* trả đúng câu lý do tiếng Việt; lượt 2 `next` ⇒ `drafted → selected`; ghi chú lưu đúng vào `generations.note`; `route:list` có `api/projects/{project}/shots/review` (middleware `web,auth,can-studio,nostore`); bundle `main-7M2HaMqS.js` 200 chứa "Chuyển bước tiếp"/"Duyệt mẫu"/"shots/review"; `/up` `/` `/bang-gia` = 200; log vẫn **đúng 6 ERROR cũ**, không sinh lỗi mới.
- Ghi chú trung thực: khách POST vào endpoint này nhận **419** (CSRF của nhóm `web`) — **giống hệt** các endpoint POST cũ (`/api/projects/1/transition`, `/api/projects` cũng 419), nên đây là hành vi có sẵn của hệ thống chứ không phải lỗ hổng mới; khách GET `/stats` vẫn 401.

**Vòng 11 (2026-09-19) — PHÍM TẮT CHO KHỐI DUYỆT + ĐÓNG GOAL (Đợt 3):**
- **Việc tốn thời gian nhất của một buổi duyệt là rời tay khỏi bàn phím** để bấm chuột từng lượt (một bộ 12 SKU × 2 bối cảnh = 24 ảnh). Thêm 5 phím cho khối "Duyệt mẫu": `S` chọn ảnh chờ duyệt · `N` chuyển bước tiếp · `A` duyệt · `R` loại · `Esc` đóng.
- **Ba chốt an toàn (đều có test khoá lại)**: (1) phím CHỈ chạy khi khối duyệt đang mở; (2) **không cướp phím khi người dùng đang gõ** (ô prompt, ô ghi chú duyệt, đổi tên layer — nhận diện qua `INPUT/TEXTAREA/SELECT/isContentEditable`); (3) **nhường toàn bộ phím khi có modal/công cụ canvas đang chạy** (`viewer` · `promptOpen` · `planOpen` · `reframeOpen` · `filmOpen` · `cropMode` · mask/draw/erase/select/pan) — nếu không thì `Esc` của modal sẽ đóng nhầm khối duyệt. Thêm nữa: bỏ qua mọi tổ hợp `Ctrl/Cmd/Alt` và gỡ listener khi card bị tháo.
- **Khám phá được, không phải phím tắt "ẩn"**: dòng nhắc ngay trong khối ("Phím tắt khi khối này đang mở: S · N · A · R · Esc") + `title` ghi phím trên từng nút; gửi khi chưa chọn ảnh thì **nhắc** chứ không gửi lời gọi rỗng.
- **Kiểm chứng**: `ShotReviewTest` nay **10 test / 71 assert** (thêm bất biến phím tắt: có listener + gỡ listener · chỉ khi khối mở · chặn khi đang gõ · nhường khi có modal · đúng 5 phím đúng việc · có nhắc trong giao diện · card vẫn KHÔNG tự gọi API) · **toàn bộ suite 554 test / 3.069 assert xanh**.
- **Chạy thật bằng SỰ KIỆN BÀN PHÍM THẬT** (Chrome CDP `Input.dispatchKeyEvent`, không phải `dispatchEvent` giả): **16/16 bước** — `A` gửi đúng 1 lượt duyệt với đúng 2 id đang chọn + giao diện lên "3 đã duyệt" · `N` khi chưa chọn ảnh ⇒ **không gửi lời gọi nào** + hiện nhắc "Chọn ảnh trước" · chọn ảnh #104 rồi `N` ⇒ đúng 1 lời gọi `next` cho id 104, ảnh lên "Đã chọn" · `R` ⇒ "Đã loại" · **gõ chữ "a" trong ô ghi chú ⇒ KHÔNG duyệt và chữ trong ô còn nguyên** · `S` chọn lại ảnh chờ duyệt · `Esc` đóng khối.
- **Không hồi quy**: chạy lại kịch bản duyệt theo lô 33 bước ⇒ **33/33**.
- **Deploy**: `e0980d9 → d18b0ae`, production verify: bundle `main-CTz8TrVd.js` 200 (569.648 B) chứa "Phím tắt khi khối này đang mở" + "Chọn ảnh trước" · `/up` `/` `/bang-gia` 200 · log vẫn **đúng 6 ERROR cũ**.
- **Đóng goal**: `docs/DESIGN_SYSTEM.md` **§16** (hợp nhất từ `docs/UX_PERSONA_STRATEGY.md` §8 ngày 2026-09-22) giữ **kết quả đã triển khai** — đối chiếu 4 chặng với bằng chứng, việc đã giao cho từng persona, bảng số đo trước → sau, và danh sách việc còn lại (Q1–Q4 chờ chủ dự án quyết). Cả 4 đợt trong roadmap nay đã ĐÓNG phần cốt lõi.

**Vòng 12 (2026-09-19) — Q1 BẬT CHẶN KHI HẾT CREDIT + Q2 YÊU CẦU NÂNG CẤP (chủ dự án đã quyết):**
- **Q1 — BẬT chặn khi hết credit.** Mặc định đổi ở **HAI chỗ** (bài học: lần sửa đầu chỉ đổi tham số mặc định của `studio_config()` nên **vô hiệu**, vì `config/studio.php` được ưu tiên trước): `config/studio.php` + `studio_plan_limits()`. Tắt lại KHÔNG cần sửa mã: setting `studio_enforce_credits=0` hoặc env `STUDIO_ENFORCE_CREDITS=false`.
- **402 có CẤU TRÚC** thay cho một câu lỗi: `code=out_of_credits` · `needed` · `balance` · `plan` · `upgrade_url`. Store xử lý ở **MỘT chỗ** (`api()`) nên cả 8 đường tạo ảnh/video đều có cùng trải nghiệm: **tự mở bảng «Gói & credit» kèm danh mục gói** — trước đây khách chỉ thấy một câu lỗi rồi không biết bấm vào đâu.
- **Ngoại lệ có chủ đích**: **Super Admin không bị chặn**. Đo trên production: tài khoản `owner@fabrikai.shop` đang có **0 credit** ⇒ chặn cả owner là chủ dự án tự khoá mình khỏi sản phẩm của mình. Chặn là để bảo vệ doanh thu từ KHÁCH; muốn thử trải nghiệm bị chặn thì dùng tài khoản khách.
- **Q2 — nút "Nâng cấp" nay là YÊU CẦU NÂNG CẤP có mã theo dõi**: bảng `upgrade_requests` (+ model `UpgradeRequest`) — mã `UP-yymm-nnnn` đọc được qua điện thoại · **chốt số tiền tại thời điểm gửi** (giá đổi sau không làm sai hoá đơn đã báo) · trạng thái pending/contacted/activated/cancelled · vết ai xử lý + lúc nào · ghi chú nội bộ.
- **ĐÓNG LỖ HỔNG DOANH THU**: `POST /api/billing/subscribe` trước đây gán được **cả gói trả phí** — khách bấm một nút là có gói 499.000 ₫, không cổng thanh toán, không dấu vết ai trả tiền. Nay chỉ còn **gói miễn phí** (402 `payment_required`); Super Admin vẫn gán trực tiếp được (đường quản trị).
- **API khách**: `POST/GET /api/billing/upgrade-request` (throttle 5/giờ theo user) — chọn gói + **1/3/6/12 tháng** + phương thức (**chuyển khoản ngân hàng** · **VNPay khi mở** · **nhờ hỗ trợ**) + SĐT (chuẩn hoá khoảng trắng, kiểm số Việt Nam) + ghi chú; **gửi trùng thì DÙNG LẠI yêu cầu đang mở** (không rải yêu cầu rác cho chủ dự án).
- **API quản trị**: `GET /api/admin/upgrade-requests` (hàng đợi + số đếm theo trạng thái + tuổi yêu cầu theo giờ) · `POST …/{{id}}{` }}` đổi trạng thái "đã liên hệ"/"huỷ" · **`POST …/activate` chỉ Super Admin** — đi qua `PlanService::assign($user, $plan, $months)` nên gói + hạn (đúng số tháng) + bonus + credit chu kỳ đều đúng đường; kích hoạt hai lần bị chặn (`already_activated`). `GET/POST /api/admin/payment-info` — STK + kênh hỗ trợ, **một nguồn** cho trang giá, popup Studio và màn Quản trị.
- **Trang giá `/bang-gia`**: nay nói được **bước tiếp theo** (3 bước: gửi yêu cầu → nhận MÃ → chuyển khoản theo mã → FabrikAI kích hoạt), hiện STK/hotlink hỗ trợ khi đã cấu hình, và vẫn nói thật VNPay **chưa mở**. Chưa cấu hình STK thì **không bịa số tài khoản** — báo "FabrikAI sẽ gửi thông tin thanh toán".
- **Kiểm chứng**: `UpgradeRequestTest` **12 test / 80 assert** mới (khách không tự kích hoạt gói trả phí · gói miễn phí vẫn đổi được · mã + số tiền chốt + SĐT chuẩn hoá · gửi trùng dùng lại · validate gói/miễn phí/số tháng/phương thức/SĐT · quyền xem hàng đợi · kích hoạt đúng 3 tháng + credit · kích hoạt hai lần bị chặn · admin thường chỉ đánh dấu "đã liên hệ" · STK trả rỗng khi chưa cấu hình + chỉ admin sửa được) · cập nhật **có ý thức** 3 test cũ khoá hành vi cũ (`PlanLimitsTest`: mặc định nay CHẶN + có đường tắt cờ + owner không bị khoá; `BillingSubscribeTest`: khách không tự gán gói trả phí; `PricingPageTest`: trang giá nói 3 bước) · **568 test / 3.160 assert xanh**.
- **Chạy thật trên trình duyệt** (CDP, harness): **36/36 bước** — mở danh mục gói · nút gói trả phí ghi "Yêu cầu nâng cấp", gói miễn phí ghi "Dùng gói miễn phí" · gửi **thiếu SĐT ⇒ hiện đúng lý do, KHÔNG hiện khối thành công** · điền đủ ⇒ **đúng 1 lời gọi** với gói/3 tháng/chuyển khoản/SĐT/ghi chú chính xác, hiện **MÃ UP-2609-0007**, số tiền 3 tháng, **STK**, **nội dung chuyển khoản = mã**, số hỗ trợ, và câu "chỉ kích hoạt SAU khi thanh toán được xác nhận" · **402 out_of_credits ⇒ popup TỰ MỞ** và nêu đúng lý do · 0 tràn ngang · **0 đoạn chữ dưới WCAG AA**. Không hồi quy: duyệt mẫu **33/33**, phím tắt **16/16**.
- **Bắt được lỗi THẬT nhờ đo tương phản**: nút của gói đang dùng bị trình duyệt áp style `:disabled` của chính nó, và quy tắc **không nằm trong layer luôn thắng layer của Tailwind v4** ⇒ chữ gần như vô hình (tương phản 1:1). Sửa bằng inline style. Cũng trong lúc đo mới phát hiện harness đang trỏ **CSS cũ đã bị xoá** (đổi hash sau build) — tức các phép đo tương phản trước đó chạy trên trang **thiếu CSS**; đã trỏ lại đúng file và đo lại toàn bộ.
- **Deploy + kiểm chứng trên PRODUCTION** (`7437021 → 8dc5ce5`): `migrate --force` tạo bảng `upgrade_requests` · `enforce_credits = BẬT` (config true, không có override trong DB/`.env`) · **chạy thật cả luồng** bằng tài khoản khách TẠM: gửi yêu cầu ⇒ `201` mã `UP-2609-0001` · 3 tháng · **597.000 ₫** · SĐT chuẩn hoá `0901234567`; gửi lại ⇒ `reused=true` và DB vẫn **1** yêu cầu; Super Admin kích hoạt ⇒ `200`, gói Khởi nghiệp, **hạn 17/12/2026** (đúng 3 tháng), credit **250** (= 100 dùng thử + 30 bonus + 120 chu kỳ đầu), trạng thái "Đã kích hoạt", người xử lý `owner@fabrikai.shop`; **dọn dẹp SẠCH** (0 yêu cầu, 0 tài khoản tạm, 0 dòng sổ cái thừa) · `/up` `/` `/bang-gia` `/dang-nhap` = 200 · khách gọi API mới: POST 419 (CSRF như mọi POST cũ), GET 401 · log vẫn **đúng 6 ERROR cũ**.
- **Còn nợ của vòng này**: **màn hình Quản trị cho yêu cầu nâng cấp** (API đã xong + đã test; giao diện làm ở vòng sau) và **điền STK/hotline** (hiện rỗng — cần chủ dự án cung cấp). Tiếp theo: **Q3 gói theo mùa vụ/xưởng** và **Q4 số ghế theo gói**.

**Vòng 13 (2026-09-19) — Q2 HOÀN TẤT (màn Quản trị) + Q3 GÓI THEO MÙA VỤ/XƯỞNG:**
- **Q2 — màn Quản trị «Yêu cầu nâng cấp»** (mục mới trong menu, có badge số việc đang chờ + nhắc trong "việc cần xử lý" ở Tổng quan): bảng hàng đợi (mã · khách + email + **SĐT bấm gọi được** · gói + số kỳ · số tiền · phương thức · ghi chú của khách · trạng thái · ai xử lý), cảnh báo **yêu cầu quá 24 giờ**, lọc theo trạng thái, nút **Đã liên hệ khách** (mọi admin) và **Kích hoạt** (**chỉ Owner**); khối **Thông tin nhận tiền & hỗ trợ** (STK · chủ TK · chi nhánh · hotline · email · Zalo · giờ làm việc) — **một nguồn** cho trang giá + popup Studio + Quản trị; chưa điền thì mọi bề mặt nói thật "FabrikAI sẽ gửi thông tin thanh toán" chứ **không bịa STK**.
- **Q3 — GÓI THEO MÙA VỤ/XƯỞNG** (chủ dự án giao tôi chọn cách tối ưu): chủ xưởng mua theo **VỤ** (một bộ sưu tập / một đơn lớn), không mua theo tháng. Ba khái niệm mới trong `plans`: `unit_months` (1 đơn vị = mấy tháng) · `cycle_months` (bao lâu cấp credit một lần) · `units` (các mốc mua được); `upgrade_requests` thêm `units` (khách mua mấy vụ) bên cạnh `months` (tổng tháng).
  - **Gói mới "Xưởng theo vụ"**: **3.290.000 ₫/vụ** (3 tháng) · **3.000 credit/vụ** · 2K · mua được 1 · 2 · 4 vụ · +300 tặng lần đầu. Đơn giá/credit rẻ hơn gói Studio theo tháng (≈1.097 ₫ vs 1.242 ₫/credit) — đúng ý "mua theo vụ thì rẻ hơn".
  - **Credit cấp MỘT LẦN cho cả vụ** (một bể dùng cho cả vụ), không nhỏ giọt theo tháng: `cycleStartFor()` nay trừ theo `cycle_months` của gói; mua gói vụ 1 lần ⇒ hạn **+3 tháng** (trước đây mọi gói đều +1 tháng).
  - **Số tiền = giá MỘT đơn vị × số đơn vị** (2 vụ = 2 lần giá, KHÔNG nhân theo tháng) — đây là chỗ dễ tính sai nhất và đã có test riêng.
  - **Mốc mua do TỪNG GÓI khai báo**: gói tháng [1,3,6,12], gói vụ [1,2,4]; xin mốc không hợp lệ ⇒ **422 `units_invalid`** kèm danh sách mốc hợp lệ (không im lặng làm tròn).
  - Migration **chỉ TẠO gói khi chưa có, KHÔNG bao giờ ghi đè** — cố ý không chạy lại `PlanSeeder` trên production vì seeder dùng `updateOrCreate` sẽ xoá giá/chi phí chủ dự án đã chỉnh (production đang đặt `image_credit_cost = 2` cho các gói cũ; đã kiểm sau deploy: **giữ nguyên**).
- **Kiểm chứng**: `SeasonPlanTest` 8 test mới (đơn vị/mốc mua · mua 1 vụ ⇒ hạn ~3 tháng + credit cấp một lần + chạy lại trong cùng vụ KHÔNG cấp thêm · gia hạn vụ thứ hai có credit riêng và hạn cộng dồn · yêu cầu 2 vụ ⇒ months=6 và tiền = 2×giá · mốc không hợp lệ bị chặn kèm `allowed_units` · kích hoạt tôn trọng số vụ đã mua · catalog/trang giá hiện "vụ" và "₫/vụ" · /api/plan/status trả `units` cho form) · cập nhật có ý thức `UpgradeRequestTest` (units) + 2 test đếm số gói (4 → 5) · **577 test / 3.222 assert xanh**.
- **Kiểm chứng giao diện thật** (CDP): kịch bản gói vụ **13/13** (danh mục hiện "Xưởng theo vụ" + "3.000 credit/vụ" + "mua 1 vụ = 3 tháng" · form hiện mốc **1/2/4 vụ** · gửi **units=2** (không gửi months) · khối thành công "2 vụ (6 tháng)") · màn Quản trị **31/31** (hàng đợi + badge + đổi trạng thái + kích hoạt + lưu STK + lọc) và vai trò không phải Owner **5/5** (thấy hàng đợi, KHÔNG có nút Kích hoạt) · không hồi quy: nâng cấp **36/36**, duyệt mẫu **33/33**.
- **Bắt được 2 lỗi THẬT nhờ chạy thật**: (1) ô chọn "Số kỳ mua" vẫn còn `v-model` trỏ vào `upgradeForm.months` đã đổi tên ⇒ giao diện gửi mãi `units = 1` (test PHP không thể thấy, chỉ lộ ra khi bấm thật); (2) nhãn thành công in "3 tháng (3 tháng)" với gói tháng — đã sửa để chỉ in phần trong ngoặc khi đơn vị KHÁC tháng. Ngoài ra phát hiện `scrollWidth` 5118px của trang Quản trị trong harness là do **script đo cũ** còn sót trong trang harness, không phải lỗi app (đã tạo harness sạch, đo lại = 0 tràn).
- **Deploy + kiểm chứng trên PRODUCTION** (`730ae67 → c2fc427`): `migrate --force` tạo gói vụ + 2 cột mới · **chạy thật cả luồng** bằng khách tạm: yêu cầu **2 vụ** ⇒ `201`, `UP-2609-0001`, **6 tháng**, **6.580.000 ₫** (= 2 × 3.290.000); mốc 3 vụ ⇒ `422` kèm "chỉ mua được 1 vụ · 2 vụ · 4 vụ"; Owner kích hoạt ⇒ **hạn 17/03/2027**, credit **3.400** (= 100 + 300 bonus + **3.000 credit cả vụ**), chạy lại cấp credit trong cùng vụ ⇒ **không đổi** · **dọn dẹp SẠCH** · 4 gói cũ **không bị ghi đè** (giá + `image_credit_cost=2` giữ nguyên) · `/bang-gia` 200 có "Xưởng theo vụ" + "mỗi vụ (3 tháng)" · bundle 200 · log vẫn **đúng 6 ERROR cũ**.
- **Còn lại của goal**: **Q4 — SỐ GHẾ theo gói** (chủ doanh nghiệp cần nhiều người dùng chung một gói).

**Vòng 14 (2026-09-19) — Q4 SỐ GHẾ THEO GÓI + NHÓM LÀM VIỆC (đóng 4/4 quyết định):**
- **Vấn đề**: chủ doanh nghiệp/studio không làm một mình, nhưng mỗi tài khoản là một người dùng riêng — 3 người muốn làm chung một bộ sưu tập thì phải mua 3 gói, mỗi người một bể credit, và bộ sưu tập của người này không hiện với người kia.
- **Mô hình**: `plans.seats` (Miễn phí 1 · Khởi nghiệp 1 · **Chuyên nghiệp 3** · **Studio 10** · **Xưởng theo vụ 5**) + `users.team_owner_id` (thành viên thuộc nhóm của ai). Thành viên **dùng chung gói, credit và bộ sưu tập** của chủ nhóm; ảnh vẫn **ghi rõ ai tạo**.
- **An toàn (đều có test)**: mời thành viên **TẠO TÀI KHOẢN MỚI** (không âm thầm kéo tài khoản đang có vào nhóm — làm vậy là chiếm credit của người khác); **mật khẩu tạm do máy chủ sinh, trả về ĐÚNG MỘT LẦN**; hết ghế ⇒ **422** kèm câu "Nâng cấp gói để thêm người"; thành viên **KHÔNG** mua gói, **KHÔNG** xoá/đổi trạng thái/sửa bộ sưu tập của nhóm, **KHÔNG** quản lý ghế; chủ nhóm không tự bỏ ghế của mình; người ngoài nhóm ⇒ 403.
- **Ba lỗi THẬT bắt được khi làm (đều do test/kịch bản bắt, không phải suy đoán)**:
  1. **Nhân bản credit**: `syncCycleCredits()` đọc `activePlan()` (nay đi theo nhóm) nên khi gọi với một THÀNH VIÊN, tài khoản phụ nhận thêm 350 credit của gói nhóm. Sửa: cấp credit chu kỳ cho **người trả tiền** (chủ nhóm).
  2. **Sổ cái sai người**: dòng `spend` ghi ở tài khoản bấm (thành viên) trong khi số dư bị trừ ở chủ nhóm ⇒ sổ lệch người và `balance_after` sai. Sửa: ghi ở người bị trừ tiền, kèm ghi chú "(bởi <tên>)".
  3. **Vượt ngân sách query**: `/api/boot` từ 3 lên 4 query do đếm ghế hai lần (gọi `usedSeats()` rồi `remainingSeats()`). Sửa: tính một lần. Đồng thời bỏ cache trong service — Laravel giữ container giữa các request trong test nên cache làm "mời người thứ 3 khi đã hết ghế" vẫn trả 201.
- **API/UI**: `GET /api/team` · `POST /api/team/members` · `DELETE /api/team/members/{user}`; `/api/boot` + `/api/plan/status` trả khối ghế/nhóm; catalog + **trang giá** (hàng "Số ghế" + câu hỏi thường gặp "nhiều người dùng chung một gói?") + **trang Quản trị** (hiện ghế từng gói + sửa được trong form gói); Studio: khối **"Nhóm làm việc"** trong popup «Gói & credit» (số ghế · danh sách thành viên · mời · mật khẩu tạm một lần · bỏ ghế) và **chế độ thành viên** ("bạn là thành viên trong nhóm của X — nhờ chủ nhóm nâng cấp").
- **Kiểm chứng**: `TeamSeatsTest` 10 test / 80 assert (ghế theo gói + catalog · mời đủ ghế rồi chặn · không chiếm tài khoản người khác · thành viên tiêu credit chủ nhóm + sổ cái đúng người + ảnh ghi ai tạo · thành viên thấy bộ sưu tập nhóm nhưng không xoá/đổi trạng thái/sửa · không mua gói/không quản lý ghế · gói + số dư + ghế hiện đúng cho thành viên · bỏ ghế giải phóng và giữ ảnh · chỉ chủ nhóm bỏ được · giao diện + trang giá có ghế) · **587 test / 3.302 assert xanh**.
- **Chạy thật trên trình duyệt** (CDP): **21/21** bước chế độ chủ nhóm (số ghế 1/3 · mời đúng 1 lời gọi với email/tên · hiện MẬT KHẨU TẠM "chỉ hiện một lần" · ghế 2/3 · bỏ ghế ⇒ ghế 1/3 · hết ghế ⇒ báo đúng lý do "đã dùng hết … nâng cấp gói" · 0 tràn ngang · 0 chữ dưới WCAG AA) + **7/7** chế độ thành viên (thấy nhóm của Công ty ABC, dùng chung credit + bộ sưu tập, **KHÔNG** có nút mời) · không hồi quy: nâng cấp **36/36**, gói vụ **13/13**, duyệt mẫu **33/33**, Quản trị **31/31**, vai trò thường **5/5**.
- **Deploy + kiểm chứng trên PRODUCTION** (`23c320a → c2a7301`): `migrate --force` (seats + team_owner_id) · ghế theo gói: 1/1/3/10/5 · **dựng nhóm thật** bằng tài khoản tạm: mời người 1 ⇒ **201** + mật khẩu tạm `oHsS4wm62Q`, mời người 2 ⇒ 3/3 ghế, mời người 3 ⇒ **422** *"Gói Chuyên nghiệp chỉ có 3 ghế và đã dùng hết. Nâng cấp gói để thêm người."* · thành viên thấy gói Chuyên nghiệp + số dư nhóm **490** (credit riêng 100) · **mở** bộ sưu tập của chủ nhóm 200, **xoá** ⇒ **403** · **tạo ảnh bằng tài khoản thành viên** ⇒ credit chủ nhóm **490 → 488**, credit riêng của thành viên **không đổi**, sổ cái ghi *"Tạo image (bởi Nhan vien 1)"* · **dọn dẹp SẠCH**, 0 thành viên còn lại.
- **Trạng thái goal**: **Q1 · Q2 · Q3 · Q4 đều đã triển khai, kiểm thử và deploy production.**

**Vòng 15 (2026-09-19) — MODULE REGISTRY: MỘT NGUỒN DUY NHẤT + CÔNG TẮC THEO GÓI (nền tảng):**
- **Vấn đề**: tính năng Studio nằm rải ở 5 nơi — nhãn/icon ở `StudioGuiConfig::DEFAULTS`, id→card ở `ACTIVITY_CARDS` (StudioApp.vue), endpoint ở routes, quyền ở từng controller, "gói nào có gì" viết tay ở trang giá. Thêm/đổi một tính năng phải sửa đủ 5 chỗ; quên một chỗ thì khách thấy nút mà bấm không được, hoặc gói rẻ vẫn gọi được API của gói đắt.
- **`App\Support\ModuleRegistry` — BẢN KHAI DUY NHẤT, 23 module** (kiểm kê đầy đủ tính năng đang có):
  · **8 panel**: Bộ sưu tập · Tạo ảnh · Tạo biến thể ảnh · Mặc thử đồ · Sửa ảnh · Ghép ảnh · Upscale · Kịch bản quay (video)
  · **2 popup**: Prompt Tạo Ảnh · Trợ lý thiết kế · **1 menu**: Cài đặt
  · **12 module tính năng** (không có nút nhưng vẫn là quyền cấp theo gói): Tạo ảnh hàng loạt · Mẫu việc theo ngành · Chi phí & tiến độ · Xuất gói cho xưởng · Chia sẻ cho khách duyệt · Duyệt mẫu theo lô · Thư viện ảnh · Nhóm làm việc (ghế) · Thư viện mặt & dáng · Film Look · Cắt & mở rộng khung · Tạo lại từ ảnh.
  Mỗi module khai: id · tên · nhóm · kind · icon · mô tả · `endpoints` · `depends_on` · đề xuất gói.
- **Một nguồn, mọi bề mặt suy ra**: `StudioGuiConfig::defaults()` nay **sinh từ registry** (nhãn/icon không còn khai hai nơi — lớp GUI chỉ giữ phần owner chỉnh: thứ tự · nhãn · icon · ẩn/hiện); `plans.modules` + `Plan::modules()`/`grantsModule()` ⇒ **gói đăng ký là công tắc cấp phát tính năng**; Quản trị/trang giá/popup gói sẽ đọc từ `ModuleRegistry::catalog()` (vòng sau).
- **THỰC THI ở backend, không chỉ ẩn nút**: middleware `EnforceModules` tra URI theo bản khai (khớp **tiền tố DÀI NHẤT** để "Xuất gói" ≠ "Bộ sưu tập", "shot-state" ≠ "Thư viện") và trả **403 `module_locked`** kèm tên module · gói nào đang cấp nó · lý do (gói / bị tắt toàn cục / thiếu module phụ thuộc). Không khai module nào ⇒ cho qua (endpoint mới không bị chặn oan trước khi khai).
- **Quyết định quan trọng để KHÔNG lấy mất tính năng của khách**: mặc định `plans.modules` = **ĐỦ module** cho mọi gói (hôm nay mọi gói đều dùng được mọi tính năng) — bản khai chỉ giữ **đề xuất** (`suggestedForPlan()`) để Quản trị gợi ý; việc siết theo gói là **dữ liệu**, chủ dự án quyết. Tài khoản **chưa có gói** rơi về **gói mặc định** (Miễn phí) thay vì mất sạch quyền; tài khoản **nội bộ (admin)** được miễn công tắc gói.
- **Kiểm chứng**: `ModuleRegistryTest` 14 test / **510 assert** — bản khai hợp lệ · GUI ⇄ registry khớp **hai chiều** (kể cả bản dự phòng trong StudioApp.vue) · **MỌI endpoint Studio (123 route) đều thuộc một module** + mọi endpoint khai đều có route thật (thêm endpoint mà quên khai ⇒ đỏ) · khớp tiền tố dài nhất · gói cấp/thu hồi module ⇒ 200/403 · **tắt MỘT module toàn cục không ảnh hưởng module khác** · phụ thuộc khoá module con (`batch` cần `prompt`) · admin không bị chặn · tài khoản chưa có gói dùng gói mặc định · id lạ trong `plans.modules` bị bỏ qua · bất biến "mở rộng quy mô" (mọi module có mặt ở mọi bề mặt suy ra). **Suite 601 test / 3.812 assert xanh** (trước 587/3.302).
- **Deploy + kiểm chứng trên PRODUCTION** (`8f3c3e6 → b410eb4`): migration thêm `plans.modules` (5 gói × 23 module) · **kiểm bằng HTTP thật**: đăng nhập tài khoản khách tạm (gói pro đã rút `job_templates`) ⇒ `GET /api/job-templates` = **403** `module_locked` *"Tính năng «Mẫu việc theo ngành» không có trong gói của bạn…"*, còn `/api/latest` `/api/projects` `/api/gui` = **200** · `/api/gui` báo đúng **1 module bị khoá, lý do "plan"** trên tổng 23 · **tắt một module toàn cục** bằng setting ⇒ chỉ module đó khoá, các module khác vẫn CHO PHÉP, bật lại là dùng được ngay (không cần deploy) · **khôi phục nguyên trạng** (gói pro 23 module, 0 tài khoản tạm, 0 module bị tắt) · `/up` `/` `/bang-gia` `/dang-nhap` 200 · log vẫn **đúng 6 ERROR cũ**.
- **Bắt được 1 lỗi thật khi làm**: biểu thức khớp URI chỉ thay dấu MỞ của placeholder (`preg_quote` escape cả cặp `\{project\}`) ⇒ `projects/12/export` rơi nhầm vào module "Bộ sưu tập" thay vì "Xuất gói cho xưởng". Test khớp tiền tố dài nhất bắt đúng và đã sửa.
- **Còn lại của goal**: màn **Quản trị Module** (bật/tắt toàn cục · gói nào cấp module nào · nút áp dụng đề xuất) · **checklist module trong form gói** · **Studio hiện ổ khoá + gợi ý nâng cấp** cho module bị khoá · **trang giá liệt kê module theo gói** (suy từ registry, bỏ chữ viết tay).

**Vòng 16 (2026-09-19) — MÀN QUẢN TRỊ "TÍNH NĂNG & GÓI" + Ổ KHOÁ TRONG STUDIO + BẢNG TÍNH NĂNG Ở TRANG GIÁ (đóng goal module):**
- **Quản trị — mục mới «Tính năng & gói»** (nhóm Hệ thống, có badge số module đang tắt): (1) **bật/tắt toàn cục** từng module kèm mô tả · loại (nhóm card/popup/menu/tính năng) · phụ thuộc · **gói nào đang cấp nó**; (2) **công tắc theo GÓI**: thẻ từng gói với chip module tick/bỏ tick, nút **Lưu** và nút **Áp đề xuất** (lấy gợi ý từ bản khai). Mọi thứ **sinh từ ModuleRegistry** nên thêm module là màn tự có dòng.
- **API quản trị**: `GET /api/admin/modules` (danh mục + ma trận gói × module + đề xuất + `ungranted` + `missing_suggested`) · `POST /api/admin/modules` (công tắc toàn cục) · `PUT /api/admin/plans/{plan}/modules` · `POST …/modules/suggested`. Id lạ **bỏ qua nhưng BÁO LẠI** (`ignored`) — không im lặng nuốt lỗi, cũng không chặn việc. Form gói cũng gửi được `modules`; **không gửi thì giữ nguyên** (không xoá quyền oan).
- **Studio — ổ khoá theo gói**: module không có trong gói hiện **mờ + huy hiệu ổ khoá** trên thanh công cụ (cả desktop và drawer mobile); bấm vào **mở bảng «Gói & credit»** và nói rõ tên tính năng + **gói nào đang có nó** (dữ liệu từ `planStatus.catalog[].modules`), thay vì để khách bấm rồi bị máy chủ chặn mà không hiểu vì sao. Popup gói thêm khối **«Tính năng theo gói»** (đang dùng được N/M + danh sách còn thiếu kèm gói có nó). 403 `module_locked` cũng **tự mở bảng nâng cấp** (giống cách xử lý hết credit).
- **Trang giá `/bang-gia`** — bảng **«Tính năng nào có ở gói nào»** SINH TỪ registry + `plans.modules` (bỏ phần viết tay): 23 dòng module × 5 gói, nhóm theo chức năng, dấu ✓/— theo dữ liệu thật. Đổi quyền trong Quản trị là trang giá đổi theo ⇒ không bao giờ lệch.
- **Mở rộng quy mô — đã CHỨNG MINH bằng thí nghiệm**: thêm **một mục** vào bản khai ⇒ module mới xuất hiện ngay ở catalog (Quản trị + trang giá), ở danh sách đề xuất gói, và (sau khi tick) ở quyền của gói. Ngược lại, module mới **không tự cấp cho ai** (quyền theo gói là dữ liệu tường minh) nhưng **không nằm im**: màn Quản trị hiện badge *"N chưa gói nào cấp"* + chip *"thiếu N so với đề xuất"* ở từng gói để xử lý trong một cú bấm.
- **Kiểm chứng**: `ModulesAdminTest` 8 test (quyền · lưu công tắc toàn cục + id lạ được báo lại · chuẩn hoá module của gói và **hiệu lực thật với khách** · áp đề xuất · form gói không xoá quyền oan · module mới hiện là "chưa gói nào cấp" rồi hết sau khi áp đề xuất · giao diện nối đúng API) + `PricingPageTest` kiểm bảng tính năng **theo dữ liệu** (rút module khỏi một gói ⇒ dòng đó hiện "—") · **suite 610 test / 3.874 assert xanh**.
- **Đo trên trình duyệt thật** (CDP): màn Quản trị **21/21 bước** (mở màn · 8 module theo nhóm · hiện mô tả/loại/phụ thuộc/gói đang cấp · **tắt 1 module toàn cục rồi Lưu ⇒ đúng 1 lời gọi với đúng 1 id** · bỏ tick 1 module khỏi gói ⇒ gói còn 7/8 · **Áp đề xuất ⇒ về 4 module theo đề xuất** · 0 tràn ngang · **0 đoạn chữ dưới WCAG AA**) · Studio **12/12 bước** (2 nút bị khoá có huy hiệu ổ khoá · bấm nút bị khoá **không mở panel** mà mở bảng nâng cấp và nêu đúng tên tính năng + gói có nó · khối "Tính năng theo gói" liệt kê module còn thiếu · nút trong gói vẫn mở panel bình thường).
- **Deploy + kiểm chứng trên PRODUCTION** (`b410eb4 → 103baa6`): `GET /api/admin/modules` **200** · 23 module · 5 gói · 8 nhóm · `team_seats` đang bật, cả 5 gói cấp; **rút** `team_seats` khỏi gói pro qua API thật ⇒ 200, còn 22 module, id lạ `["id_la"]` được báo lại, **khách ở gói pro mất quyền đúng module đó trong khi `job_templates` vẫn dùng được**; **áp đề xuất** ⇒ về 23 module; **khôi phục nguyên trạng** (gói pro 23 module, khách về gói cũ, 0 module bị tắt) · `/bang-gia` 200 có bảng tính năng (**115 dấu ✓ = 23 module × 5 gói**) · khách gọi `/api/admin/modules` ⇒ 401 · bundle admin/main 200 · `/up` `/` `/dang-nhap` 200 · log vẫn **đúng 6 ERROR cũ**.
- **Trạng thái goal module**: (1) kiểm kê ✅ (23 module) · (2) một nguồn + test ràng buộc GUI/route/component ✅ · (3) công tắc toàn cục + theo gói ✅ · (4) thực thi backend + hiện khoá/nâng cấp ở Studio ✅ · (5) tự đồng bộ khi mở rộng quy mô ✅ · (6) test + đo trình duyệt thật + deploy ✅.

**Vòng 17 (2026-09-19) — ĐỒNG BỘ GÓI CƯỚC ↔ QUYỀN CẤP TÍNH NĂNG + GHI CHÚ HIỂN THỊ NHẬP TAY:**
- **Yêu cầu**: gói cước phải đồng bộ với "gói cấp tính năng nào", NHƯNG owner vẫn nhập tay được phần chữ hiển thị cho khách — phần nhập tay **chỉ để đọc, không phải công tắc**.
- **`Plan::moduleNames()` / `modulesByGroup()`** — tên tính năng **suy TỪ quyền thật** (`plans.modules` + tên trong ModuleRegistry) nên đổi quyền/đổi tên tính năng là phần hiển thị tự đổi theo, không phải viết lại ở trang giá. **`Plan::manualFeatures()`** — ghi chú owner nhập tay, cố ý KHÔNG cấp quyền gì.
- **Trang giá**: mỗi thẻ gói có 2 khối tách bạch — **"Có trong gói"** (N/M tính năng + danh sách theo nhóm, mở/đóng được, suy từ công tắc) và **"Thông tin thêm"** (ghi chú nhập tay). Thêm `id="goi-<slug>"` cho từng thẻ ⇒ chia sẻ link tới đúng gói và test khoanh vùng được đúng thẻ.
- **Quản trị**: thẻ gói hiện badge **"N tính năng"** (công tắc, tooltip liệt kê tên) + **"M ghi chú"** (nhập tay) — nhìn là biết gói cấp gì mà không phải mở form. Form gói tách **hai khối có nhãn rõ**: (1) *"Tính năng gói này CẤP cho khách"* = chip tick module (kèm **Chọn tất cả · Bỏ chọn hết · Áp đề xuất**) với câu giải thích "đây là công tắc thật"; (2) *"Ghi chú hiển thị cho khách"* với cảnh báo **"CHỈ để khách đọc — không cấp quyền"**, kèm **xem trước khách sẽ thấy gì** (số tính năng + tên + ghi chú) ngay trong form.
- **API**: `mapPlan()` trả thêm `module_names` · `modules_by_group` · `manual_features` để mọi bề mặt Quản trị dùng chung một dữ liệu.
- **Bắt được 1 lỗi thật**: chú thích kiểu **Blade** (`{{-- ... --}}`) đặt trong file `.vue` bị Vue hiểu là interpolation ⇒ build đỏ (*"Invalid left-hand side in prefix operation"*). Đã sửa (3 chỗ) và thêm **test bất biến** cấm cú pháp đó trong `AdminApp.vue`/`StudioApp.vue`/`store.js` để không tái diễn.
- **Kiểm chứng**: `PricingPageTest` +2 test (thẻ gói hiển thị đúng tập tính năng đã tick, KHÔNG quảng cáo tính năng chưa cấp, ghi chú nằm khối riêng và **ghi chú không cấp quyền**; bảng ma trận khoanh vùng đúng để không bắt nhầm tên gói ở khối persona) · `ModulesAdminTest` +2 test (ghi chú nhập tay không đổi quyền · tên hiển thị suy từ công tắc + payload Quản trị đủ trường · cấm chú thích Blade) · **suite 613 test / 3.899 assert xanh**.
- **Đo trên trình duyệt thật** (CDP): **trang giá 9/9 bước** (mở `<details>` ⇒ thấy tính năng theo nhóm: "Không gian làm việc: Bộ sưu tập…" · "Đội nhóm: Nhóm làm việc (ghế) · Thư viện mặt & dáng" · N/M tính năng · khối "Thông tin thêm" · 0 tràn ngang · **0 đoạn chữ dưới WCAG AA** trong thẻ gói) · **form gói Quản trị 17/17 bước** (2 khối có nhãn đúng · bỏ tick "Trợ lý thiết kế" ⇒ bộ đếm 8→7 và **xem trước đổi theo** · bấm Lưu ⇒ payload gửi `modules` không còn module vừa bỏ tick và vẫn giữ `features`).
- **Deploy + kiểm chứng trên PRODUCTION** (`9f7c98b → ba4c4b2`): `/bang-gia` 200 với **5 thẻ gói đều có "Có trong gói" + "Thông tin thêm"**, bảng ma trận **99 dấu ✓ / 16 dấu —** (= 23 module × 5 gói), `id="goi-pro"` có mặt · bundle admin 200 · log vẫn **đúng 6 ERROR cũ**.
- **PHÁT HIỆN cần chủ dự án xác nhận**: trên production hiện **gói Miễn phí cấp 12/23** và **Khởi nghiệp 18/23** module (khớp đúng *đề xuất* trong bản khai), còn Chuyên nghiệp/Studio/Xưởng theo vụ **23/23**. Đây là dữ liệu quyền, không phải lỗi mã; nhiều khả năng do **bấm «Áp đề xuất»** ở màn Quản trị. Nếu là chủ ý thì giữ nguyên (khách gói thấp sẽ thấy ổ khoá + gợi ý nâng cấp); nếu không thì tick lại hoặc bấm "Chọn tất cả" trong form gói.

**Vòng 18 (2026-09-19) — CẢNH BÁO ẢNH HƯỞNG KHI ĐỔI QUYỀN TÍNH NĂNG + ĐƯỜNG KHÔI PHỤC:**
- **Bối cảnh**: chủ dự án xác nhận **đã bấm «Áp đề xuất»** ở màn Quản trị (nên Miễn phí còn 12/23 và Khởi nghiệp 18/23 tính năng). Thao tác đúng chức năng, nhưng giao diện **không nói trước hậu quả** — đó là lỗ hổng trải nghiệm cần bịt.
- **Đo ảnh hưởng thật trên production** (trước khi làm gì thêm): chỉ **1 người ở gói Miễn phí** và **1 người ở gói Studio**, 4 tài khoản chưa gán gói (dùng gói mặc định); khách gói Miễn phí có **0 ảnh/video đã tạo** và 0 ảnh gắn bộ sưu tập; toàn hệ thống mới có **2 generation**. ⇒ Việc rút tính năng **không làm ai mất thứ họ đang dùng**.
- **Cảnh báo ảnh hưởng (mới)**: `GET /api/admin/modules` trả thêm `users_count` từng gói; màn Quản trị hiện chip **"N người dùng"** trên mỗi thẻ gói; bấm **«Áp đề xuất»** hoặc **«Lưu»** mà **rút** tính năng khỏi gói **đang có người dùng** ⇒ hiện **hộp xác nhận** nêu rõ *"Gói này đang có N người dùng. Thao tác sẽ RÚT M tính năng: … "*, huỷ thì **không gọi API**, xác nhận thì gọi đúng một lần.
- **Đường khôi phục 1 cú bấm**: nút **«Cấp tất cả»** trên từng thẻ gói (cấp lại toàn bộ tính năng đang mở, có xác nhận) — phòng khi lỡ rút nhầm; kèm ghi chú trong hộp xác nhận "có thể bấm Cấp tất cả nếu muốn giữ nguyên quyền cũ".
- **Kiểm chứng**: `ModulesAdminTest` +1 test (payload có `users_count` · UI có hàm xác nhận ảnh hưởng với câu "SẼ RÚT" + số người dùng · có `grantAllModules`/«Cấp tất cả» và dùng đúng hộp xác nhận chuẩn) · **suite 614 test / 3.908 assert xanh**.
- **Đo trên trình duyệt thật** (CDP): kịch bản **11/11 bước** — thẻ gói hiện số người dùng · có nút «Cấp tất cả» · bấm «Áp đề xuất» ở gói **có 7 người dùng** ⇒ **hộp xác nhận hiện ra**, nêu *"đang có 7 người dùng"*, liệt kê tính năng sắp rút (có "Trợ lý thiết kế"), **chưa gọi API**; bấm Huỷ ⇒ **0 lời gọi**; bấm «Rút và lưu» ⇒ **đúng 1 lời gọi** áp đề xuất.
- **Deploy + kiểm chứng trên PRODUCTION** (`2e71eca → a7be776`): `GET /api/admin/modules` 200 với **số người dùng từng gói** (Miễn phí 1 · Studio 1 · còn lại 0) · cả 5 gói nay **khớp đề xuất** (`missing_suggested = 0`) · **không module nào bị tắt toàn cục** · log vẫn **đúng 6 ERROR cũ**.

**Vòng 19 (2026-09-23) — TÍN HIỆU THỊ TRƯỜNG TỪ NGUỒN NGOÀI: biến TIN thành DỮ LIỆU (Đợt 31):**
- **Yêu cầu**: *"kiểm tra sâu Agent Studio → tối ưu GUI/UX/UI → tối ưu hoá dữ liệu → kiểm tra cách tạo dữ liệu → tạo dữ liệu từ nguồn ngoài (RSS) khi không có model có khả năng tìm kiếm web."*
- **Phát hiện cốt lõi**: sau Đợt 27, máy chủ ĐÃ lấy được tin RSS, nhưng **tin chỉ là CHỮ để model đọc** và **mọi con số trên màn hình vẫn là hằng số** của bộ xu hướng có sẵn (`momentum` 86/91/88 · `evidence_count` 18.420/24.180). Không có model ⇒ agent đọc lại 8 hướng mẫu. Tức là chủ xưởng vẫn quyết định bằng số mẫu.
- **Tầng mới — ĐO bằng thuật toán** (`app/Services/MarketSignalService.php` + bảng `market_signals`): từ khoá theo **5 nhóm hàng** · số tin · số nguồn · **tăng/giảm so với lần đo trước** · **dải giá đọc trong tin** (trung vị). Mỗi lần đo là một **ảnh chụp** (cùng dữ liệu ⇒ không ghi thêm; >12h ⇒ ghi mẫu mới; >120 ngày ⇒ `prune`) vì **không có lịch sử thì không nói được "đang lên hay chậm lại"**.
- **Khớp từ khoá dùng CHUNG một định nghĩa** (`App\Support\VietnameseText`): theo **ranh giới từ** (`"áo"` không khớp trong `"báo"`), cụm từ khớp thêm bản không dấu, từ một tiếng thì không hạ chuẩn.
- **Agent dùng số ĐO**: hướng có tin thật mang số đo + link + nhãn **có tin thật**; hướng còn lại ghi rõ **bộ có sẵn**; từ khoá chỉ có trong tin thành **hướng mới** (`live-…`, chọn được, không 422); **engine tất định (không model) dẫn số đo thật** — đúng yêu cầu "tạo dữ liệu từ nguồn ngoài khi không có model tìm kiếm web". Thứ tự: **hướng có tin thật lên trước** (xếp thuần theo "đà tăng" thì số mẫu luôn thắng số đo).
- **Lịch nền 30 phút** (`routes/console.php`) + lệnh `studio:market-signals [--force|--region|--prune]` ⇒ câu "tự động lấy tin mỗi 30 phút" trên giao diện **từ nay là câu ĐÚNG** (trước đây không có lịch nào).
- **18 lỗi THẬT đã sửa** (chi tiết + hậu quả: `DEPLOY_LOG.md` Đợt 31 §4): giải mã thực thể sau khi bỏ thẻ làm **thẻ HTML sống lại trong prompt** · lọc từ khoá bằng `str_contains` (`"áo"` khớp `"báo"`) · gọi nguồn tuần tự (3 nguồn = tối đa 36 giây) · không chặn redirect về địa chỉ nội bộ · nguồn khác vùng hiện "Không lấy được" · mất sạch tin khi nguồn chết · `strtotime` hiểu sai ngày `d/m/Y` · chống trùng chỉ theo URL · **thiếu `use Http`** làm vai đọc ảnh qua URL chết âm thầm · radar cắt `shop_rows` còn 100 trong khi đường ghi xoá hết rồi ghi lại (**mất dữ liệu**) · khoá bộ đệm brief thiếu ảnh mẫu/brief/bảng size/nội dung shop · khoá tính theo nhóm suy luận thay vì nhóm tìm kiếm · tắt AI vẫn gọi model đọc ảnh · khối `model` báo sai nhóm · đếm ảnh tạo bị chặn ở 120 · cache radar không bọc lỗi · `sources_mode` luôn `'demo'` · **khối "khả năng đọc tin từ internet" nạp mà không hiện ở đâu**.
- **Giao diện**: khối **"Tín hiệu đo từ tin thật"** · KPI thật + ghi rõ nguồn từng số · bảng nguồn **5 trạng thái** · bỏ chữ kỹ thuật (tên model/provider · `latency_ms` · `rule-based-v1` · `CollectionBot` · nhóm `prompt` · chip "Nguồn: demo / Nội bộ: local") · a11y (nhãn ô nhập, `scope=col`, `aria-pressed`, **vùng chạm ≥24px**) · nút Quay lại chết ở bước DNA đã bỏ · chống bấm hai lần tạo hai dự án · giữ kết quả cũ khi radar lỗi · mỗi màn một nút chính · nút khoá nói lý do.
- **Kiểm chứng**: `MarketSignalTest` **22 test / 82 assert** · **suite 933 test / 6.750 assert XANH** (trước 911/6.668) · test bắt được **lỗi thật của chính bản mới** (phép hợp mảng giữ giá trị cũ ⇒ số đo không thay được số mẫu) · chạy thật 3 nguồn RSS: **16 tin · 8 từ khoá** · radar chạy thật không model: `source_mode=live` + 2 hướng có tin thật lên đầu · **Chrome thật (CDP)**: khối tín hiệu hiện đủ, nhãn đúng, **0 lỗi console**, không còn chữ kỹ thuật.
- **TRẢ NỢ (cùng ngày)**: cả **7 món nợ đã đóng** — khổ vải vào công thức (150cm ⇒ 58,8 m/8.019.000đ vs 180cm ⇒ 48,9 m/7.177.500đ) · dán Excel hiểu định dạng vi-VN (tách ra `resources/js/studio/shopPaste.js` + `scripts/check-shop-paste.mjs` 18 phép kiểm chạy trong suite) · hai con số lợi nhuận có tên (`profit_basis`/`profit_after_fixed_vnd`/`margin_after_fixed_pct`; kịch bản bán tự khai `after_fixed_cost`) · kỳ báo cáo shop (`period_days_mixed` + `periods`) · từ khoá mơ hồ cần ngữ cảnh ngành (`MarketSignalService::AMBIGUOUS`) · 19 tiêu đề card theo §1.3 · `.tool-btn.is-active` về `border-brand-500` + **DesignSystemTest quét cả app.css** (đóng lỗ test). Nợ mới phát sinh khi deploy cũng đã xử lý ngay (xem dưới).
- **DEPLOY PRODUCTION** (`92344b6 → c76dfbd → 0ee9a54`): sao lưu DB trước (37 bảng/285 KB) · `migrate --force` tạo `market_signals` · cache 3 lớp · `schedule:list` có `studio:market-signals` (*/30) + `studio-scheduler-heartbeat` (*/5) · **đo thật trên máy chủ: 16 tin/3 nguồn/6–8 từ khoá, có tăng/giảm (+33%)** · **radar thật: `source_mode=live`, market=live, hướng có tin thật lên đầu, định hướng tất định dẫn số đo** · HTTP `/up` `/` `/bang-gia` `/dang-nhap` 200, API 401 · **log không phát sinh lỗi mới**.
- **Phát hiện khi deploy — HOST KHÔNG CHẠY CRON NÀO**: `schedule:list` có job nhưng bảng `cache` **0 khoá mutex của lịch**, **không có** `storage/logs/worker.log`, **7 job `RenderImageJob` nằm chờ** từ 18–20/09 ⇒ câu "tự động lấy tin mỗi 30 phút" trên giao diện là **câu SAI**. Đã sửa theo hướng **ĐO, KHÔNG HỨA** (commit `0ee9a54`): lịch chạy nền ghi **nhịp tim** 5 phút/lần ⇒ `studio_scheduler_alive()` ⇒ `auto_refresh {alive, label}` ⇒ giao diện nói đúng *"chưa bật lịch chạy nền — tin mới khi bạn mở màn hình hoặc bấm «Cập nhật tin»"*; `SchedulerHonestyTest` (5 test) khoá luật. Dọn tồn đọng: `queue:work --stop-when-empty` ⇒ 7/7 job xong trong 0,9 ms/job (CAS), `jobs=0 failed=0`.
- **VIỆC CÒN LẠI — chỉ chủ dự án làm được (hPanel → Cron Jobs, mỗi phút 1 lần)**: `schedule:run` và `queue:work --stop-when-empty --max-time=55 --tries=1 --timeout=900`. Chưa thêm thì hệ thống **vẫn dùng được** (tín hiệu đo khi mở màn hình, generation chạy inline) nhưng chậm hơn và **giao diện nói thật là chưa có lịch chạy nền**. Chi tiết: `DEPLOY.md` §9.1undecies.

**Vòng 20 (2026-09-23) — "CÓ NGUỒN NGOÀI SAO VẪN DÙNG BỘ CÓ SẴN?": ba nguyên nhân, sửa cả ba (Đợt 32):**
- **Câu hỏi của chủ dự án** sau khi deploy Đợt 31. Đo trên production thì cả ba nguyên nhân đều THẬT: (1) 3 nguồn trả **210 tin** nhưng máy đo chỉ nhận **16** (trần `max_items` 8/6/5 dùng chung cho prompt LẪN việc đo) ⇒ chỉ **1 hướng** sinh từ tin; (2) máy đo chỉ biết **từ vựng khai sẵn** nên tin nói "tuần lễ thời trang" mà chưa khai từ khoá thì không thành dữ liệu; (3) model suy luận **đốt hết ngân sách token vào phần nghĩ rồi bị cắt** (`finish_reason=length`, `reasoning_only=true`, 9.677 ký tự) ⇒ **0 định hướng do AI viết**, mọi lượt rơi về engine tất định đọc bộ có sẵn (log: 10 dòng "không trả về nội dung" + 3 dòng "JSON không dùng được").
- **Sửa**: (1) đệm giữ **bản đọc được** (60 tin/nguồn) + **trần riêng cho việc đo** (`MEASURE_PER_SOURCE=30`, cắt lại từ đệm không gọi lại mạng) ⇒ **16 → 40 tin**; (2) trích **CHỦ ĐỀ 2–4 tiếng từ chính tin** (bỏ từ dừng, ≥2 tin, dọn cụm con/chồng nhau, bỏ tên toà soạn ở tầng đọc tin) ⇒ **14 từ khoá + 10 chủ đề**, hướng sinh từ tin **2 → 9**; (3) ngân sách **6.000/16.000** + timeout 90s + `enable_thinking:false` cho Qwen3 (tự gọi lại nếu provider không hiểu cờ) + **đường lui giữa các nhóm model** + `lastAttempts()` ghi rõ vì sao từng model hỏng.
- **Thêm**: định hướng do AI viết nay mang **bằng chứng đo được** ("Dựa trên N tin thật · M nguồn" + link); giao diện có khối **"Chủ đề đang được nói tới trong tin"** + chip **"Có tin thật (N)"** + dòng đếm hướng thật/bộ có sẵn.
- **Kiểm chứng**: đo 16→40 tin · 6→14 từ khoá + 10 chủ đề · hướng live 2/9 → **9/13** · **AI viết 8/8 định hướng, 6 dựa trên tin thật** (có ngày + tên báo) · gọi model thật **11,7s · engine=ai-v1 · deepseek-flash** · `MarketAnalysisFromSourcesTest` 9 test · **suite 954 test / 6.827 assert XANH** · deploy production `dfdef9f → 2e0ccd9`.

**Vòng 21 (2026-09-24) — TỐI ƯU HIỆU NĂNG TẢI TRANG + TÁCH CODE THEO MIỀN (Đợt 33):**
- **Yêu cầu**: "tối ưu hóa/nâng cấp Agent Studio + tối ưu hóa/tinh chỉnh/nâng cấp GUI/UX/UI". Chủ dự án chọn 2 trục: hiệu năng tải trang + tái cấu trúc code.
- **Hiệu năng**: main entry **599 → 179 KB (−70%)** — 15 component nặng thành async (9 card activity bar + DesignAgents/ConceptCard/GalleryModal/ProjectWorkspace/SourcePickerPopup/LibraryApp), popup nạp ở lần mở đầu rồi giữ mount (không mất nháp); bỏ 3 setInterval(1s) thường trực bằng useJobTicker (chỉ chạy khi job chạy).
- **Tách store.js 5.658 dòng → 13 module** (store/: helpers · state · getters + 10 module actions). Cắt NGUYÊN VĂN bằng script tự kiểm chứng (382 action, ghép lát == khối gốc). API công khai giữ nguyên — 37 file import không đổi.
- **Tách DesignAgents.vue 1.742 dòng → shell (963) + 4 bước** (components/agents/AgentDnaStep.vue · AgentRadarStep.vue · AgentBriefStep.vue · AgentCanvasStep.vue): shell giữ script + provide() 139 binding, mỗi bước inject đúng bề mặt và dán nguyên văn template.
- **Test khoá luật thích nghi**: thêm TestCase::studioStoreSource() + TestCase::designAgentsSource() (nối module/component con) để test quét source vẫn đọc đủ mọi mảnh; 2 test khoá import eager đổi sang dạng async (luật "card vẫn một nguồn" giữ nguyên).
- **Kiểm chứng**: vite build OK · **suite 954 test / 6.844 assert XANH** · commit bbf837e + 082c6c9.
- **Nối tiếp (cùng phiên)**: tách tiếp layers/selection → 7 module con (6b79e6a) · phím tắt điều hướng Agent Studio (9511da6) · **đã deploy production** HEAD 9511da6 (2 lần pull, 0 migration, homepage + chunk mới 200). Skeleton + empty state đã có sẵn từ đợt trước, không làm lại.
- **Vai Tìm kiếm internet cho Agent Studio** (c210731): nhóm agent_search ĐÃ có từ Đợt 30 nhưng aiBrief/radarDirections kiểm nhóm suy luận trước nhóm tìm kiếm ⇒ chỉ khai nhóm tìm kiếm thì agent rơi về rule. Đã sửa (chạy độc lập + báo đúng model) + 3 test khoá (AgentRolesTest 7→10) · suite 957 test XANH · đã deploy HEAD c210731.
- **Model Registry nhận 3 vai Agent Studio** (857509a): dropdown "Vai trò" (SettingsApp.vue ROLE_ORDER) và validation backend (StudioSettingsController in:image,...) đều THIẾU agent_reason/agent_vision/agent_search. Thêm studio_model_group_slugs() (một nguồn: studio_task_groups + 2 vai cũ inference/text) + sửa 4 chỗ đọc api_key_ref nullable. Test 11/48 assert · suite 958 XANH · đã deploy HEAD 857509a.
- **Sửa TDZ api() + radar 504** (2013328): (1) api() dùng biến `expired` trước khi khai báo ⇒ mọi HTTP lỗi ném "Cannot access 't' before initialization" (L-E79B) thay vì câu thật; (2) radarDirections gọi nhóm agent_search khi CÓ model mà KHÔNG cần model tìm kiếm được (webSearch=false) ⇒ gọi nhầm ckey:sypham98/qwen3.8-fast (api.xah.io chết) ⇒ treo 90s ⇒ 504. Sửa runner/fingerprint/cache-key: chỉ dùng nhóm tìm kiếm khi webSearch=true. Test 12/51 · suite 959 XANH · đã deploy HEAD 2013328.
- **Thiết kế lại shell Agent Studio mobile-first** (b0ba214): thanh tiến trình ngang duy nhất (bỏ rail trái desktop + lưới mobile) · header gọn (bỏ 4 chip trạng thái) · bối cảnh 1 dòng hiện mọi kích thước · đầu bước nhắc việc cần làm. Đã deploy HEAD b0ba214.

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
