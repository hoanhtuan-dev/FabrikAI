# FabrikAI · KẾ HOẠCH NÂNG CẤP (v1.1 · 2026-09-17 · đã chốt Q1–Q4)

> **File này là KẾ HOẠCH, không phải báo cáo.** Nó gộp 4 tài liệu (`STUDIO_REVIEW.md` v3 ·
> `STUDIO_REVIEW_PROGRESS.md` v3 · `STUDIO_REVIEW_PRODUCT.md` v2 · `STUDIO_REVIEW_DEEPDIVE.md`) thành
> **một hàng việc có thứ tự, có DoD, có cách nghiệm thu**, và **đã đối chiếu lại với code hôm nay**.
>
> **Neo đo của file này — đo lại bằng lệnh, không lấy từ tài liệu cũ:**
> `git rev-parse --short HEAD` = **66023d7** · `php artisan route:list | tail` = **127 route** ·
> `vendor/bin/phpunit` = **309 test / 1.629 assertion — OK, 35 file test** · `app/` = 6 controller ·
> 11 service · 17 model · `StudioController.php` **4.643 dòng** · `helpers.php` **1.610** ·
> `store.js` **3.987** · `StudioApp.vue` **755**.
> Production: **fabrikai.shop đang chạy** (MySQL, 25 bảng).
>
> ⚠️ **Bốn tài liệu kia đã lệch neo** — xem §6. Đừng trích số từ chúng mà không đo lại.

---

## §1 — CHẨN ĐOÁN GỘP: 7 nhóm vấn đề, xếp theo mức chặn

Đây là kết luận gộp. Mọi mục đều đã được **kiểm lại trên code ở `66023d7`** (đánh dấu ✅) hoặc
**lấy từ tài liệu và chưa tự kiểm lại** (đánh dấu ⚠️).

| # | Nhóm | Bản chất | Vì sao chặn | Bằng chứng |
|---|---|---|---|---|
| **A** | **Không có đường vào cho người tự đăng ký** | Đăng ký tạo role `customer` ⇒ **toàn bộ `/api/*` nằm sau `auth+admin`** ⇒ 403 mọi call; `store.needsLogin` được set nhưng **không render ở đâu**; Cài đặt (`/settings` — nơi nhập API key) chỉ render khi `user.is_admin` | Sản phẩm chỉ dùng được cho tài khoản do admin tạo tay. Một chủ shop tự đăng ký kết luận "app lỗi" trong 60 giây | ✅ `User.php:23,49` · `routes/web.php:35` · `store.js:415` · `StudioApp.vue:497` |
| **B** | **Sáu hành vi IM LẶNG** (người dùng nhận thứ khác mà không biết) | ① nút 1K/2K **không có tác dụng** ② tỉ lệ **4:5 bị bóp thành 3:4**, 21:9/19:6 → 16:9 ③ **không có API key ⇒ thao tác SỬA trả lại ĐÚNG ẢNH GỐC nhưng báo `completed`** ④ `seed`/`negative_prompt` **bị bỏ qua ở mọi đường EDIT** ⑤ **ảnh khuôn mặt mẫu bị bỏ âm thầm** ⇒ lookbook không cùng người mẫu ⑥ **fallback tự vẽ nền** khi AI không xoá được, không báo | Đây là "lỗi niềm tin": chỉ cần một khách gặp ca ③ là mất khách. Rẻ hơn nhiều so với làm tính năng mới | ✅ ①`ImageAIService::sizeFor()` **vẫn nhận `$resolution` rồi không dùng** (`:1559-1571`) ②cùng hàm ③`copySample()` **vẫn `resolveSamplePath($preferred)` = ảnh nguồn** (`:531-537`) ④⑤⑥ ⚠️ DEEPDIVE §3.2 |
| **C** | **Tiến trình là MÔ PHỎNG** | `setInterval` cộng ngẫu nhiên 4–12%, khoá ở 90%, đổi "giai đoạn" theo ngưỡng % chứ **không** theo backend; API trả về là **set 100% + "Hoàn tất!"** dù ảnh còn `pending` | Người dùng bị "lừa" đúng lúc dễ bỏ đi nhất; cùng màn hình Outputs lại hiện trạng thái THẬT ⇒ hai câu chuyện trái nhau | ✅ `store.js:459-466` (setInterval) · `:501-503` (ép 100%) |
| **D** | **Mobile vỡ** | Nút "Kết quả" mở drawer **rỗng ruột**; "Nguồn ảnh"/"Thư viện" chỉ có ở rail `hidden lg:flex` (≥1024px); `SourcePanel.vue` **import nhưng không render** | Chủ shop chính là người dùng điện thoại | ✅ `StudioApp.vue:722-726` (drawer rỗng — `div` trong suông) · `:687` (`hidden lg:flex`) · `:15` (import chết) |
| **E** | **Giá trị không rời khỏi app** | Không có gói xuất: `grep ZipArchive` toàn repo = **0** · tải từng ảnh, **tên file là UUID** · **0 notification/email** ⇒ render 3–8 phút phải canh màn hình · không có mô tả/caption/hashtag · không có Channel Validator | Không có lý do trả tiền; mọi giá trị dừng trong app | ✅ `grep -ri ZipArchive app/` = 0 · `grep 'Notification\|Mail::' app/` chỉ ra `use Notifiable` của `User` |
| **F** | **Nợ vận hành production** | Chưa có cron worker cho FabrikAI (app sống nhờ lưới an toàn inline 90s) · không monitoring · không backup · `auth+admin` không throttle · migration thiếu `down()` | Ảnh xong chậm ~90–100s; không có tín hiệu khi hỏng | ⚠️ `STUDIO_REVIEW.md` §9.6 · ✅ `config/studio.php:134` `queue_worker` default **false** |
| **G** | **Cấu trúc sản phẩm lệch** | Trạng thái nằm ở **Project** không ở **artifact**; không có khái niệm **lô (batch)**; 89 chuỗi prompt hardcode là "code" chứ không phải tài sản; điều hướng chỉ 2 tầng (Studio/Library) | Không điều hành được nhiều artifact; chất lượng đứng yên; không bán được "chất riêng của brand" | ⚠️ PRODUCT §1.3–§2 |

### 1.1 Việc đã XONG (đừng làm lại — đã tự kiểm lại hôm nay)

- **Bảo mật: hết nợ nghiêm trọng.** SSRF (kể cả 2 site bypass), IDOR `project_id`, path traversal (kể cả
  **xoá file tuỳ ý** ở `/api/uploads/delete`), mass-assignment/leo quyền, XSS đầu ra, brute-force `/dang-nhap`
  `/dang-ky`, **mật khẩu super admin hardcode trong repo PUBLIC** — tất cả đã vá kèm test.
- **Tiền: 5 đường hoàn credit đã hợp nhất** thành 1 helper CAS (`studio_finalize_generation`) ở `66023d7`.
- **Hạ tầng:** git + 47 commit · 309 test xanh · production LIVE trên MySQL · queue-first/inline-fallback ·
  cache production bật · `LOG_LEVEL=warning` để cảnh báo vận hành không bị nuốt.
- **Dọn dẹp:** storefront gỡ sạch (19 model · 27 migration · 31 helper) · PWA gỡ hẳn · dead code chính đã xoá ·
  `public_html` 32 → 21 MB.

---

## §2 — LỘ TRÌNH NÂNG CẤP: 4 đợt

Nguyên tắc xếp thứ tự: **(1) chặn cứng đường vào** → **(2) hành vi im lặng (niềm tin)** → **(3) last-mile (tiền)**
→ **(4) năng lực theo persona**. Không mở đợt sau khi đợt trước chưa có DoD xanh.

### ĐỢT 0 — "Cứu niềm tin + mở đường vào" (không thêm tính năng mới)

| # | Việc | Đặc tả | DoD / cách nghiệm thu | Ước lượng |
|---|---|---|---|---|
| **0.1** | **Chốt & làm mô hình truy cập** *(quyết định của người dùng)* | Chọn **một**: **(a)** mở studio cho `customer` với tập quyền hẹp (tạo/sửa ảnh, dùng credit của mình; **không** vào Settings/Presets/Stylist-data/Cài đặt model) — đổi `ADMIN_ROLES` thành middleware `can-studio` + policy theo endpoint; hoặc **(b)** giữ `admin` nhưng đổi đăng ký thành **xin quyền**: role `pending`, màn hình chờ có nội dung, admin duyệt trong Settings. **Kèm bắt buộc:** render `needsLogin` thành banner phân biệt 3 trạng thái (*chưa đăng nhập* / *đã đăng nhập chưa được cấp quyền* / *hết phiên*) | Test mới: user tự đăng ký ⇒ **(a)** tạo được generation đầu tiên và bị 403 ở `/api/settings-vue/*`; **(b)** thấy màn hình chờ, không thấy toast chứa chữ "khu vực quản trị". Không còn ca 403 im lặng nào | 1–2 ngày |
| **0.2** | **Tiến trình THẬT** (C) | Bỏ `setInterval` mô phỏng. `generateImage()` gọi `pollGeneration()` cho mọi item vừa tạo; thanh tiến trình đọc `status` thật (`pending` → "Đang xếp hàng", `processing` → "Đang xử lý", `completed` → "Xong", `failed` → lỗi + nút Thử lại). Bỏ việc ép `generateProgress = 100` ngay sau khi API trả về | Test (Vitest/JS hoặc Feature qua DOM khó ⇒ dùng **bất biến tĩnh** + test store): `grep setInterval` trong luồng generate = 0; sau khi gọi `generateImage()` có ≥1 lần `pollGeneration`. Thủ công: tạo ảnh rồi **không cần F5**, thumbnail chuyển trạng thái | 2–3 giờ |
| **0.3** | **Nói thật chế độ DEMO** (B③⑥) | Đánh dấu kết quả sinh ra bằng fallback GD/stub: thêm `generations.is_demo` + `demo_reason` (migration mới) — **đặt ở tầng service**, không sửa 8 call-site. API trả `demo: true`; UI hiện banner *"Chưa cấu hình API key — kết quả là ảnh mẫu/ảnh gốc, KHÔNG phải AI"* trên card + trên ảnh trong Outputs | Test: chạy không key ⇒ mọi generation `demo=true`; có key ⇒ `demo=false`. Không còn ca "bấm Sửa ảnh, nhận lại ảnh cũ, báo thành công" mà không giải thích | 1 ngày |
| **0.4** | **Nút nào bấm được thì phải có tác dụng** (B①②) | `sizeFor()` dùng `$resolution` thật; map thêm tỉ lệ mà provider hỗ trợ (**4:5 = 1104×1380**, 21:9/19:6) — nếu provider từ chối thì **gỡ nút khỏi UI** và ghi rõ tỉ lệ hỗ trợ thật. Thêm test khoá: mọi `ratio` UI cho chọn ⇒ `sizeFor()` trả size **khác** `default` | Test mới: bảng tỉ lệ UI ↔ `sizeFor()` khớp; `resolution` 1K ≠ 2K ⇒ size khác nhau (hoặc nút không tồn tại). Verify thật 1 ảnh 4:5 ra đúng khung | 1 ngày |
| **0.5** | **Mobile dùng được** (D) | Render `OutputModule` vào drawer "Kết quả" đang rỗng (component **đã import sẵn**) · đưa "Nguồn ảnh" + "Thư viện" vào drawer dưới 1024px (dùng lại `SourcePanel.vue`/`LibraryCard.vue` đang import chết) · `ProjectWorkspace` bảng dự án thêm `overflow-x-auto` | Thủ công ở **375px**: tạo ảnh → **xem được kết quả** → thêm được ảnh nguồn → mở được thư viện. Test tĩnh mới: `SourcePanel`/`LibraryCard`/`OutputModule` phải **có mặt trong template** (không chỉ `import`) — chống tái phát đúng lớp bug này | 1–2 ngày |
| **0.6** | **Dư lượng UI chết** | Gỡ nút "Cài đặt ứng dụng" + `beforeinstallprompt`/`appinstalled` listener trong `StudioApp.vue` (**giữ** `killLegacyServiceWorker()` — đúng chủ đích) · gỡ `store.step` + `store.js:439-442` + `StudioApp.vue:75` (di tích "bước" chết) · sửa comment `helpers.php` còn nhắc `swap : SwapCard` · thêm `removeEventListener('keydown', onGlobalKey)` còn thiếu | `grep -i 'beforeinstallprompt\|store.step\|SwapCard' app/ resources/ routes/ config/` = 0 (ngoài changelog). Test mới: số `addEventListener` = số `removeEventListener` trong `StudioApp.vue` | 2–3 giờ |
| **0.7** | **A11y hoàn tất** | Trích composable `useDialog()` (Esc + `tabindex="-1"` + nhận focus + **focus trap**) và áp cho **19 overlay** (`grep focus-trap` = 0 hiện nay). Thêm `aria-label` cho ~60 nút chỉ-icon · `aria-hidden` cho `StudioIcon` | Test mở rộng `test_full_screen_overlays_declare_their_role`: mọi overlay có `role="dialog"` ⇒ dùng `useDialog`. Thủ công: Tab trong modal **không** thoát ra sau | 1 ngày |
| **0.8** | **Đóng nợ bảo mật mức vừa còn lại** | ⬜ `GET /api/stylist-data/data` **public nhưng chạy DDL** (`Schema::create` qua `ensureTables()`): bỏ auto-DDL khỏi request path (chuyển sang migration/command) · ⬜ `SuggestLibraryService::counts()` = 4 COUNT mỗi `list()` ⇒ gộp 1 query + đưa vào test ngân sách query · ⬜ sanitizer `Generation.error` chỉ lọc khi `APP_DEBUG=false` ⇒ lọc **luôn**, giữ chi tiết trong log | 3 test mới/xanh: `GET /api/stylist-data/data` **không** phát sinh DDL (assert số query/không có `Schema::` trong path) · `/api/suggest-library` ≤ N query (ngân sách) · `error` không chứa body provider dù `APP_DEBUG=true` | 1 ngày |
| **0.9** | **Sổ sách: một nguồn số liệu duy nhất** | Thêm `scripts/measure.sh` in ra: `HEAD · route count · test count · assertion · dòng file lớn`. Mở mỗi vòng bằng chạy nó rồi dán vào đầu file trạng thái | Chạy được, output khớp `php artisan route:list`/`phpunit`. Mọi tài liệu trích đúng số đó | 1 giờ |

### ĐỢT 1 — "Last mile": giá trị phải rời khỏi app (nơi tiền nằm)

Phụ thuộc **bắt buộc**: 0.4 (`sizeFor`) phải xong trước — nếu không, gói xuất sẽ crop từ **khung sai**.

| # | Việc | Đặc tả | DoD | Ước lượng |
|---|---|---|---|---|
| **1.1** | **`Shot.state` + `is_selected`** | Nâng `Generation` thành artifact có trạng thái: `idea → drafted → selected → fitted → campaign_ready → approved → rejected`, cộng `is_selected`, `note`, `sort`. Đây là **thay đổi nhỏ nhất mở khoá mọi thứ sau** | Migration + state machine + test transition (whitelist cạnh hợp lệ, chặn nhảy cóc) + test quyền (chỉ owner/admin). `OutputModule` đổi từ "xem" thành "chọn" | 3–4 ngày |
| **1.2** | **`Run` (lô)** | Bảng `runs`: `type, params(JSON), total, done, failed, credits_spent, status` + API `POST /api/runs` · `GET /api/runs/{id}`. "6 ảnh cho 1 SKU" = **1 lô**, không phải 2 lần bấm tay (giới hạn `variants max:4`) | Test: tạo run 12 ảnh ⇒ `done=12`; **chi phí credit của cả lô = tổng credit trừ**, khớp sổ cái; đứt giữa chừng ⇒ resume được, không trừ 2 lần | 1 tuần |
| **1.3** | **ExportBundle** | `chọn shots → resize theo preset kênh → watermark (tuỳ chọn) → đặt tên `{sku}-{channel}-{n}.jpg` → zip (+ `caption.txt`)`. Preset: **Shopee 1:1 ≥1000px** · **IG/FB 4:5** · **TikTok 9:16** · **Web 3:4** | Test: gói zip có đúng N file, **đúng kích thước từng preset**, tên file theo SKU (không UUID), caption kèm. Tải 1 lần ra cả gói | 1 tuần |
| **1.4** | **Channel Validator (pre-flight)** | Kiểm trước khi đăng: cạnh ≥ ngưỡng kênh · dung lượng ≤ 2/5/8 MB · tỉ lệ đúng · **nền có thật sự #FFFFFF** · có watermark/chữ không · sản phẩm chiếm ≥70% khung. **Không đối thủ nào làm cho sàn VN** | Test trên ảnh dựng sẵn: ảnh nền 235 ⇒ **cảnh báo đỏ**; ảnh 900px cho Shopee ⇒ cảnh báo; ảnh đạt ⇒ "Sẵn sàng đăng" | 4–5 ngày |
| **1.5** | **Thông báo khi render xong** | Hiện **0 notification** trong `app/`. Email trước (Zalo/Telegram sau) khi `Run`/generation hoàn tất | Test: `Notification::fake()` ⇒ có gửi đúng 1 lần/lô, không spam từng ảnh | 1–2 ngày |
| **1.6** | **Mô tả + caption + hashtag** | ⚠️ **XÂY MỚI** — `ProductAIService` **đã bị gỡ** ở `729ceb0`, **không thể "hồi sinh"**. (Khôi phục tạm: `git show 729ceb0^:app/Services/ProductAIService.php` rồi bỏ phụ thuộc setting storefront — nhưng đường sạch là viết lại) | Endpoint + UI tại Look; test: output không rỗng, đúng ngôn ngữ, có hashtag; có **nút xác nhận của người dùng** trước khi đưa vào gói xuất | 2–3 ngày |
| **1.7** | **`prompt_templates`** | Thay dần 89 chuỗi hardcode bằng bảng có `key · scope(global/brand/category) · version · body · is_active`. Bắt đầu bằng **prompt giá trị nhất**: `garment.lock` ("reproduce this IDENTICAL garment…" — `StudioController.php:690`) | Test: đổi template trong DB ⇒ nội dung gửi provider đổi **không cần deploy**; template cũ vẫn truy được theo version | 3–4 ngày |

### ĐỢT 2 — Nhân đôi năng lực cho DESIGNER

| # | Việc | Vì sao là *luồng* chứ không phải tính năng | DoD |
|---|---|---|---|
| **2.1** | **Ma trận biến thể + khoá seed** | 1 thiết kế × 4 màu × 2 chất liệu = 8 lần bấm tay hôm nay, và **form lệch nhau** vì `seed` bị bỏ qua ở đường EDIT (B④) | 4 colorway cùng seed ⇒ form không lệch; test assert seed lan tới payload provider ở **mọi** đường |
| **2.2** | **Model lock cho lookbook** | Ảnh trong cùng lookbook phải cùng một người mẫu. Hiện **không tồn tại** (ảnh mặt bị bỏ âm thầm — B⑤) | Lưu reference + kiểm lại sau render + **cảnh báo khi không giữ được**; test: bỏ mặt ⇒ có cảnh báo, không im lặng |
| **2.3** | **Lineage / versioning** | Không biết "sửa tiếp từ ảnh nào", không so v1/v2/v3. `Generation.meta` đã là JSON ⇒ thêm `parent_shot_id` | UI so sánh 2 phiên bản; test lineage không tạo chu trình |
| **2.4** | **Duyệt có bình luận + ghim trên ảnh** | Đã có 5 trạng thái + audit (tốt) nhưng **không nói được "sửa chỗ vai"**, và `status_history` **ghi vào DB mà không trả ra API** | Trả `status_history` + comment thread + toạ độ ghim; test round-trip |
| **2.5** | **Pattern: CHỐT có ghi sổ** | Card đã gỡ ở `eee1bba` nhưng tài liệu vẫn mô tả như còn ⇒ đội ngũ hiểu sai năng lực | Chọn: **làm lại đúng cách** (seamless/tileable + tile/DPI — điều kiện sống còn để in vải) **hoặc** tuyên bố không phục vụ in ấn. Ghi vào changelog |
| **2.6** | **Tech pack tối thiểu** | 0 dòng code về dpi/cmyk/icc hôm nay | **Chỉ làm sau khi 1.x ổn định** |

### ĐỢT 3 — Doanh nghiệp (chỉ mở khi Đợt 1–2 chứng minh giữ chân được người dùng)

Thứ tự bắt buộc: (1) tenant/org + membership · (2) **sổ cái credit + giá theo model** (không có thì không định giá được) ·
(3) **signed URL + disk riêng cho ảnh người thật** — ⚠️ hiện `/api/image/{path}` và `/api/image-thumb/{path}`
phục vụ ảnh **không cần đăng nhập** (`routes/web.php`, ngoài nhóm auth) — đây là rủi ro **pháp lý**, không chỉ kỹ thuật ·
(4) consent/retention/xoá dữ liệu · (5) thông báo + assignee + nhắc deadline · (6) worker bền + monitoring + backup
**có diễn tập restore** · (7) brand kit · (8) SSO/2FA.

---

## §3 — NỢ KỸ THUẬT ĐÃ BIẾT (giữ trong sổ, không chặn lộ trình)

| Hạng mục | Vị trí | Xử lý |
|---|---|---|
| `queue_worker` default **false** | `config/studio.php:134` | Prod đã set `STUDIO_QUEUE_WORKER=true` nhưng **chưa có cron** ⇒ vẫn rơi vào lưới an toàn 90s (M-b) |
| Cron worker FabrikAI | hPanel của host | Việc **vận hành**, không phải code — xem `DEPLOY.md` |
| `studio_assets` không `user_id` | migration `2026_08_31_000100` | Là cấu hình **GLOBAL có chủ đích**; chỉ đổi khi làm Đợt 3 (tenant) |
| `auth+admin` không throttle | `routes/web.php:35` | Admin spam generation được; credit **được phép âm** (chủ đích "never hard-block") — cân nhắc ở Đợt 3 |
| Migration thiếu `down()` an toàn | `database/migrations` | Rollback thủ công |
| `StudioController` 4.643 dòng | ~90 endpoint trộn HTTP+GD+provider | **Không refactor to** trong lúc này — chỉ **rút dần** mỗi khi sửa một nhóm (kèm test đã có). Refactor lớn không có test đặc tả là rủi ro cao hơn lợi ích |
| Prompt giá trị nhất là 1 chuỗi PHP | `StudioController.php:690` | Đưa vào `prompt_templates` (1.7) |
| **Pipeline "xịn" đã chết nhưng còn dấu vết** | QA 4 tiêu chí · moderation · super-res · face-enhance · `VirtualTryOnService::fallbackEdit()` không caller | Hoặc **nối lại**, hoặc **xoá** — đừng để lửng lơ (tài liệu đào tạo dựa vào tên này sẽ sai) |

---

## §4 — ĐO LƯỜNG (hiện chỉ đo test/route)

Nguyên tắc: **chỉ đếm sự kiện** (id · model · thời lượng · chi phí) — **không log nội dung prompt/ảnh**, tránh tự tạo rủi ro riêng tư mới.

| Chỉ số | Ngưỡng đủ tốt | Guardrail |
|---|---|---|
| Time-to-first-image (đăng ký → ảnh đầu) | **< 5 phút** | đo cả tỉ lệ **0 ảnh trong 24h** (hiện ~100% vì 403) |
| Thời gian hoàn thành 1 SKU | **< 10 phút** | so với baseline ~28–34 thao tác + 4 việc ngoài app |
| Tỉ lệ dùng lô (Run) | > 50% lượt tạo | — |
| **Tỉ lệ EXPORT / số look** | **> 60%** | export thấp mà render cao ⇒ **chất lượng**, không phải UX |
| Ảnh lỗi người dùng tự phát hiện | < 20% | chỉ số gián tiếp của "QA đã chết" |
| Lỗi theo provider/model | < 5% | cảnh báo tự động khi > 15% trong 15 phút |
| Chi phí/ảnh · /look · /tháng | biên > 70% | cần **sổ cái credit** (Đợt 3) |
| Tỉ lệ hoàn credit bất thường | **= 0** | phát hiện double-refund (đã có CAS từ `66023d7`) |

---

## §5 — QUY ƯỚC THỰC HIỆN MỖI VÒNG

1. **Mở vòng:** chạy `scripts/measure.sh` → dán số vào đầu file trạng thái. **Không tin số trong tài liệu cũ.**
2. **Làm:** mỗi việc phải kèm **test hồi quy mới** + **mutation-test** chứng minh test không rỗng (đúng chuẩn các vòng trước).
3. **Đóng vòng:** cập nhật **đúng một** file trạng thái (`STUDIO_REVIEW_PROGRESS.md`) — kèm commit hash. Các file còn lại
   **không sửa song song**.
4. **Chống race:** `stat` mtime + đọc lại vùng sắp ghi trước khi ghi (bài học §1.5 của DEEPDIVE: hai phiên chạy song song
   đã ghi đè lẫn nhau **trong vài phút**).
5. **Không tuyên bố "đã phủ 100%"** nếu chưa `grep <tên file>` cho **từng** file sống.

---

## §6 — BỐN TÀI LIỆU KIA ĐANG LỆCH NEO (sửa trước khi dùng)

| File | Vấn đề | Xử lý đề xuất |
|---|---|---|
| `STUDIO_REVIEW_PROGRESS.md` | §1/§2 (dòng 10–49) nói *"KHÔNG có `.git`"*, *"KHÔNG có `tests/`"*, T5/T6/T7 **MỞ**, "chưa có deploy" — **trái ngược hoàn toàn** với §2bis cùng file. Neo ghi `eee1bba` nhưng HEAD đã là `66023d7` | Xoá bảng cũ ở §1/§2 (đã có bảng mới ngay dưới), hoặc dán nhãn **"ẢNH CHỤP 2026-09-16 — KHÔNG DÙNG"** lên đúng bảng đó |
| `STUDIO_REVIEW.md` | Neo `eee1bba`; §5.1/§5.2/§5.3/§10 đã tự mâu thuẫn (vừa ghi MỞ vừa ghi ĐÓNG); nhiều số dòng lệch so với code | Giữ làm **kho bằng chứng lịch sử**; **không** dùng làm bảng việc. Bảng việc nay ở file này |
| `STUDIO_REVIEW_PRODUCT.md` v2 | Có mục tự mâu thuẫn (§4A "BẬT swap" vs §6.1 "đã gỡ") — đã sửa một phần tại chỗ; số route/test lệch | Dùng cho **lý do sản phẩm**; số liệu lấy từ file này §neo |
| `STUDIO_REVIEW_DEEPDIVE.md` | Neo `eee1bba`, tự cảnh báo race; §3.2 (6 lỗi im lặng) và §11 (thị trường) **vẫn là tài sản giá trị nhất** | Giữ nguyên; chỉ cập nhật §3.2 khi Đợt 0 đóng từng mục |

> **Quy ước đề xuất:** `STUDIO_REVIEW_PROGRESS.md` = **sổ trạng thái duy nhất** ·
> `STUDIO_REVIEW_PLAN.md` (file này) = **bảng việc duy nhất** · 3 file còn lại = **kho bằng chứng**, đọc để tra cứu,
> không trích số trực tiếp.

---

## §7 — CẦN NGƯỜI DÙNG QUYẾT (chặn lộ trình)

| # | Quyết định | Ảnh hưởng | Đề xuất |
|---|---|---|---|
| **Q1** | **Mô hình truy cập** (0.1): mở cho `customer` có giới hạn, **hay** giữ `admin` + đổi thành luồng xin quyền? | Chặn **toàn bộ** Đợt 0 và mọi chỉ số onboarding | **(a) mở cho customer tập quyền hẹp** — sản phẩm nhắm chủ shop tự phục vụ; giữ `admin` cho Settings/Presets/model registry |
| **Q2** | **Pattern (in ấn)**: làm lại đúng cách (seamless + tile/DPI) hay tuyên bố không phục vụ in ấn? | Chặn Đợt 2.5 + tài liệu đào tạo | Chốt **có ghi sổ**; nếu chưa làm ngay thì ít nhất tuyên bố để tài liệu khỏi sai |
| **Q3** | **PWA**: bỏ hẳn (đã bỏ code) hay làm lại đúng cách? | Chỉ còn dư lượng UI (0.6) | **Bỏ hẳn** — đã gỡ `sw.js`/`manifest.json`; chỉ cần xoá nút chết |
| **Q4** | **Thứ tự ưu tiên**: `Run` + ExportBundle (tiền) trước, hay Model lock/seed (designer) trước? | Chọn tệp khách hàng đầu tiên | **Run + Export trước** — đây là chỗ "giá trị rời khỏi app" và là lý do trả tiền rõ nhất |

### 7.1 ✅ ĐÃ CHỐT (2026-09-17)

| # | Quyết định | Hệ quả trực tiếp cho kế hoạch |
|---|---|---|
| **Q1** | **Mở studio cho `customer` với quyền HẸP** | 0.1 làm theo nhánh **(a)**: customer **được** tạo/sửa ảnh bằng credit của mình; **không** được vào Settings · Presets · Stylist-data · model registry · mọi endpoint quản trị. Cần: middleware `can-studio` (thay `admin`) ở nhóm `/api/*` + **tách nhóm route** quản trị còn `admin`; cập nhật `tests/Feature/StudioOwnershipTest.php` (26 test hiện assert `customer → 403` cho 8 endpoint generation-scoped) — **các test đó phải đổi kỳ vọng theo bề mặt mới**, nếu không suite sẽ đỏ một cách đúng đắn. Vẫn bắt buộc render `needsLogin` thành banner 3 trạng thái |
| **Q2** | **`Run` (lô) + ExportBundle làm TRƯỚC** trong Đợt 1 | Giữ nguyên thứ tự 1.1 → 1.2 → 1.3 trong bảng; kéo `sửa sizeFor()` (0.4) thành **phụ thuộc cứng**, không được hoãn |
| **Q3** | **Pattern: TUYÊN BỐ chưa phục vụ in ấn** (làm lại sau nếu cần) | 2.5 đóng bằng **một dòng ghi sổ** (changelog + sửa PRODUCT/DEEPDIVE chỗ mô tả Pattern như còn), xoá khỏi Đợt 2. Đồng thời **xoá mọi tham chiếu "in ấn / 300 DPI / CMYK"** khỏi tài liệu đào tạo |
| **Q4** (phụ) | **PWA: bỏ hẳn** | 0.6 chỉ còn việc xoá nút chết + listener, không làm lại PWA |

---

## §8 — BA CÂU CHỐT

1. **Vấn đề lớn nhất không phải chất lượng AI, mà là NIỀM TIN và ĐƯỜNG VÀO**: người tự đăng ký bị 403 im lặng,
   Sửa/Ghép trả lại ảnh cũ mà báo thành công, thanh tiến trình là mô phỏng, nút 2K và tỉ lệ 4:5 không có tác dụng.
   **Sửa 4 nhóm này rẻ hơn nhiều so với làm tính năng mới**, và là điều kiện để mọi tính năng sau có nghĩa.
2. **Giá trị chỉ rời khỏi app khi có GÓI XUẤT**: hôm nay không có `ZipArchive`, tên file là UUID, không caption,
   không kiểm tra spec sàn. Kèm **Channel Validator** — thứ không đối thủ nào làm cho sàn VN.
3. **Đối thủ đáng sợ nhất không phải một app khác, mà là "không cần app"** (Shopee AI Creation miễn phí; một cá nhân
   biết prompt tự dựng người mẫu AI). Moat phải là **workflow nhiều bước + tuân thủ kênh + tiếng Việt + giá VNĐ +
   quy trình duyệt có kiểm soát** — không phải "model đẹp nhất".
