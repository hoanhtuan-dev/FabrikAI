# FabrikAI — Chiến lược UX/UI · luồng công việc · gói cước theo khách hàng

> Ngày lập: 2026-09-18 · Người lập: phiên làm việc kỹ thuật (goal `goal-7e1e7fc0`)
> Mọi kết luận dưới đây đều kèm bằng chứng `file:dòng` đọc trực tiếp từ mã nguồn tại thời điểm lập.
> Tài liệu này là **kim chỉ nam cho các đợt cải tiến tiếp theo**, không phải mô tả mong muốn.

---

## 1. Hiện trạng (đo được, không suy đoán)

### 1.1 Nền tảng kỹ thuật

| Hạng mục | Thực tế |
|---|---|
| Ứng dụng | Laravel 13 + Vue 3 (6 shell SPA: `/` · `/settings` · `/presets` · `/stylist-data` · `/model-settings` · `/admin`) |
| Kiểm thử | **491 test / 2.657 assert** (`vendor/bin/phpunit`, đo 2026-09-18) |
| Production | fabrikai.shop · Hostinger shared hosting · **không có daemon** (chỉ cron hPanel) · asset build commit trong git |
| Dữ liệu lõi | `users` (credit, plan) · `plans` · `credit_transactions` (sổ cái) · `projects` (brief/status/deadline/tags) · `generations` · `studio_models` · `studio_api_keys` |

### 1.2 Studio có gì

- **7 nhóm card** trên thanh công cụ trái: `concept` (Tạo ảnh) · `variation` (Biến thể) · `tryon` (Mặc thử) · `inpaint` (Sửa ảnh) · `compose` (Ghép trang phục) · `upscale` (Nâng cấp ảnh) · `director` (Giám đốc sáng tạo) — nguồn `resources/js/studio/StudioApp.vue` (`ACTIVITY_CARDS`) + `app/Services/StudioGuiConfig.php`.
- **3 mục điều khiển**: `prompt` (Prompt Tạo Ảnh) · `stylist` (Trợ lý thiết kế) · `settings` (menu Cài đặt, ghim đáy).
- **Quản lý dự án đã có nền**: bảng `projects` có `status` (Nháp → Đang làm → Chờ duyệt → Đã duyệt…), `brief`, `deadline`, `tags`, `settings`, `thumbnail_url`; luồng chuyển trạng thái có whitelist (`app/Services/ProjectWorkflowService.php:42`).
- **Thư viện/kết quả**: `GalleryModal`, `LayersPanel`, multi-select, lịch sử — đủ cho làm việc đơn lẻ.

### 1.3 Hệ credit hiện tại (điểm yếu nhất của sản phẩm)

| Sự thật | Bằng chứng |
|---|---|
| Chỉ có **1 chỗ** ghi sổ tiêu credit | `app/Http/Controllers/StudioController.php:1675` |
| Chi phí đọc từ **setting toàn cục**, không theo gói | 9 chỗ `studio_config('image_credits'\|'video_credits')` trong `StudioController.php` (dòng 186, 219, 321, 502, 552, 721, 788, 1156, …) |
| **KHÔNG chặn** khi hết credit | `queueGeneration()`: *"Internal admin tool: never hard-block on credits. Track usage (balance may go negative)"* — `StudioController.php:1600`; grep `credits_balance <` toàn repo = **0 kết quả** |
| `/api/boot` **không trả** thông tin gói | `app/Http/Controllers/StudioController.php:46-56` chỉ có `credits_balance`, `is_admin`, `is_super_admin` |
| UI chỉ hiện số dư, không hiện gói | `StudioApp.vue:578, 644` — badge `store.creditsLeft` |

---

## 2. Ba nhóm khách hàng & việc họ phải hoàn thành (JTBD)

| | **Nhà thiết kế thời trang** | **Chủ doanh nghiệp / thương hiệu** | **Chủ xưởng may** |
|---|---|---|---|
| Việc hằng ngày | Ra ý tưởng → phác thảo → nhiều biến thể → lookbook để chào khách | Duyệt bộ sưu tập, kiểm soát chi phí, giao việc cho nhóm, giữ nhận diện thương hiệu | Nhận yêu cầu mẫu, chuẩn hoá ảnh kỹ thuật + mô tả chất liệu, gửi thợ may |
| Cần từ công cụ | Nhiều biến thể nhanh, giữ đúng phom/chất liệu, prompt tái dùng | Con số (chi phí/ảnh, tiến độ), tài khoản cho nhân viên, quy trình duyệt | Ảnh đúng kỹ thuật, mô tả rõ, xuất được gói file để sản xuất |
| Hiện có | Tạo ảnh + biến thể + sửa ảnh + ghép trang phục + preset prompt | `projects` có status/duyệt + sổ credit + trang quản trị người dùng | Ảnh chi tiết + stylist data (loại trang phục) |
| **Thiếu hẳn** | Không có "bộ sưu tập" xuyên suốt để gom ảnh/biến thể theo mùa vụ | Không có phân quyền nhân viên theo gói, không có báo cáo chi phí/tiến độ theo dự án, không có gói dùng chung | **Không xuất được gói file cho xưởng** (ảnh + mô tả + bảng size + chất liệu); không có mẫu kỹ thuật |

> Ghi chú: `projects` hiện là *dự án cá nhân của từng user* (`user_id`), chưa có khái niệm **tổ chức/nhóm** ⇒ chủ doanh nghiệp và xưởng (nhiều người) chưa được phục vụ đúng cách.

---

## 3. Phân tích gói đăng ký — 6 lỗ hổng (có bằng chứng)

### 3.1 Gói hiện tại (`database/seeders/PlanSeeder.php`, đọc từ DB production)

| Gói | Giá/tháng | `credits_per_month` | `bonus_credits` | credit/ảnh | credit/video | cap |
|---|---|---|---|---|---|---|
| Miễn phí (`free`) | 0 ₫ | 0 | 0 | 1 | 10 | 1K |
| Khởi nghiệp (`starter`) | 199.000 ₫ | 120 | 30 | 1 | 10 | 2K |
| Chuyên nghiệp (`pro`) | 499.000 ₫ | 350 | 100 | 1 | 10 | 2K |
| Studio (`studio`) | 1.490.000 ₫ | 1.200 | 400 | 1 | 10 | 2K |

### 3.2 Sáu lỗ hổng

**(1) `credits_per_month` KHÔNG BAO GIỜ được cấp — nặng nhất.**
- Không có scheduler/command nào cấp credit định kỳ: `routes/console.php` chỉ có lệnh `inspire`; `grep 'Schedule::|->monthly|->daily'` trong `app/` `routes/` `bootstrap/` = **0 kết quả**.
- `PlanService::assign()` chỉ tặng `bonus_credits` **một lần** khi lần đầu đổi gói (`app/Services/PlanService.php:44-53`).
- ⇒ Khách trả 499.000 ₫ cho "350 credit/tháng" **không nhận được credit nào** sau tháng đầu. Đây vừa là lỗi doanh thu, vừa là rủi ro mất uy tín.

**(2) Chi phí credit không phân hoá theo gói.** Cột `plans.image_credit_cost` / `video_credit_cost` **không được dùng ở bất kỳ đâu** trong pipeline (grep toàn `app/`: chỉ có ở `AdminController` để nhập/trả JSON và `BillingController::catalog()` để hiển thị). ⇒ Mọi gói tiêu credit như nhau.

**(3) `resolution_cap` không được thực thi.** Chỉ xuất hiện ở `BillingController.php:38` (trả JSON). Không có chỗ nào trong pipeline ảnh chặn/hạ độ phân giải theo gói ⇒ gói Miễn phí 1K và Studio 2K giống hệt nhau.

**(4) Không có giao diện gói cước.** `grep -rn 'billing' resources/js` = **0 kết quả**: 2 endpoint `GET /api/billing/plans` (công khai) và `POST /api/billing/subscribe` **không có ai gọi**. Khách không xem được gói, không nâng cấp được, không biết mình đang ở gói nào.

**(5) Không có ràng buộc nào theo gói ⇒ gói cước chưa có tác dụng.** Không giới hạn số ảnh/ngày, không giới hạn tốc độ/hàng đợi, không giới hạn số ghế, và **không chặn khi hết credit** (`StudioController.php:1600`). ⇒ Không có lý do kỹ thuật nào buộc khách nâng cấp.

**(6) Không có trang giá công khai.** `resources/views/` không có view pricing; `resources/views/auth/register.blade.php` không nhắc gói nào ⇒ khách không biết có gói gì trước khi đăng ký.

**Ghi chú trung thực cần nêu trên UI:** `POST /api/billing/subscribe` hiện **kích hoạt gói trả phí mà chưa thu tiền** (ghi rõ trong `BillingController` — "MVP chưa có cổng thanh toán VNĐ"). Khi mở UI gói cước **phải** nói rõ trạng thái này, nếu không là quảng cáo sai sự thật.

### 3.3 Unit economics (từ `PRICING.md` + cấu hình thật)

- 1 credit = 1 ảnh 1K (base/2512) ≈ **500 ₫** chi phí API; ảnh edit ≈ **750 ₫**; 1 video = 10 credit ≈ **5.000 ₫**.
- Biên gộp hiện tại: Khởi nghiệp ~70% · Chuyên nghiệp ~65% · Studio ~60% (nếu cấp đủ credit như cam kết).
- **Nhưng vì lỗ hổng (1)**, biên thực tế của tháng thứ 2 trở đi là ~100% (không giao credit) — tức là đang "lãi" bằng cách không thực hiện cam kết. Phải sửa trước khi bán thật.

---

## 4. Định hướng thiết kế: TIÊN TIẾN – THUẬN TIỆN – TỐI ƯU – TIẾT KIỆM THỜI GIAN

### 4.1 Bảy nguyên tắc bắt buộc

1. **Một việc = một luồng.** Người dùng chọn *việc* ("Ra 5 ảnh lookbook cho bộ Thu 2026"), không chọn *công cụ*.
2. **Không nhập lại.** Mọi thứ đã nhập (chất liệu, người mẫu, bối cảnh, phom) thuộc về **Bộ sưu tập/Dự án** và tự có mặt ở mọi card.
3. **Chi phí hiển thị TRƯỚC khi bấm** ("1 ảnh = 1 credit · còn 34"), không bao giờ trừ xong mới báo.
4. **Không chặn giữa chừng.** Cảnh báo sớm khi credit thấp; khi hết thì đề xuất nâng cấp ngay tại chỗ (không đá ra trang khác).
5. **Kết quả luôn có bước tiếp theo.** Sau mỗi ảnh: *Biến thể · Sửa · Ghép · Tải · Gửi duyệt* — một cú bấm.
6. **Tái dùng là mặc định.** Preset prompt theo ngành (lookbook, sàn TMĐT, mẫu kỹ thuật xưởng), prompt library, người mẫu/dáng đã lưu.
7. **Trạng thái luôn nhìn thấy.** Gói hiện tại · credit còn lại · hạn mức còn lại của chu kỳ · việc đang chạy — ở một chỗ, không phải đi tìm.

### 4.2 Gói cước phải khác nhau ở thứ khách CẢM NHẬN được

| Trục khác biệt | Miễn phí | Khởi nghiệp | Chuyên nghiệp | Studio/Xưởng |
|---|---|---|---|---|
| Ảnh/tháng (credit) | 100 dùng thử | 120 | 350 | 1.200 |
| Độ phân giải | 1K | 2K | 2K | 2K + Max |
| Chi phí credit/ảnh theo gói | 1 | 1 | 1 | 1 (giảm khi mua thêm) |
| Số ghế (nhân viên) | 1 | 1 | 3 | 10 |
| Hàng đợi | thường | thường | ưu tiên | ưu tiên cao |
| Xuất gói cho xưởng | — | — | ✓ | ✓ + bảng size |
| Duyệt nội bộ/khách | — | — | ✓ | ✓ + nhiều cấp |
| Bộ sưu tập lưu trữ | 1 | 5 | 20 | Không giới hạn |

> Nguyên tắc: **credit là đơn vị công việc**, còn **gói là đơn vị năng lực**. Nếu hai gói chỉ khác số credit thì khách sẽ luôn chọn gói rẻ và mua lẻ.

---

## 5. Roadmap 4 đợt (có tiêu chí đo)

### Đợt 1 — Gói cước THẬT — ✅ ĐÃ TRIỂN KHAI (2026-09-19, đo lại bằng test + dữ liệu thật)
- ✅ Cấp `credits_per_month` theo chu kỳ, **idempotent** (CAS bằng UPDATE có điều kiện + transaction):
  `PlanService::syncCycleCredits()` · cột mốc `users.plan_credits_granted_at` · migration `2026_09_19_000002`.
- ✅ Hai đường cấp: **lazy** (`/api/boot` và trước mỗi lần tạo ảnh — khách gia hạn có credit ngay) và
  **cron** `php artisan studio:grant-plan-credits` (có `--dry-run`).
- ✅ Chi phí credit **theo gói**: `studio_credit_cost('image'|'video')` — thay **8 chỗ** trong pipeline
  vốn luôn đọc setting toàn cục (fallback giữ nguyên hành vi cũ khi gói để mặc định 1/10).
- ✅ `/api/boot` trả `user.plan` (tên · giá · credit/tháng · chi phí ảnh/video · cap độ phân giải · hạn · mốc cấp).
- ✅ Loại sổ cái mới `plan_grant` ("Cấp theo gói") + hiện trong bộ lọc Sổ credit của trang Quản trị.
- **Đo được**: 8 test mới (`PlanCreditCycleTest`) · **499 test / 2.682 assert xanh** · chạy thật trên dữ liệu
  thật: gán gói Khởi nghiệp cho một tài khoản ⇒ 200 → **350 credit** (+30 bonus +120 kỳ đầu) và **đúng một**
  dòng sổ cái `plan_grant`; `--dry-run` không ghi gì.
- ✅ **Thực thi đặc quyền của gói** (`resolution_cap`): yêu cầu vượt cap bị **hạ xuống cap** (không chặn
  việc đang làm) và trả `notice` nói rõ; cap video suy từ cap ảnh (1K ⇒ 720p, 2K ⇒ 1080p).
- ✅ **Cờ `studio_enforce_credits`** (mặc định **TẮT** để không phá trải nghiệm khách đang dùng): bật thì
  thao tác thiếu credit trả **402** kèm hướng dẫn nâng cấp; tắt thì giữ nguyên hành vi cũ. Kèm
  `credit_warning` trả sớm khi credit sắp cạn (< 3 thao tác) — cảnh báo TRƯỚC, không chặn giữa chừng.
- ✅ **UI "Gói & credit" trong Studio**: badge credit trên thanh công cụ nay là **nút** mở popup — gói đang
  dùng · credit còn lại · chi phí **theo gói** của ảnh/video · độ phân giải tối đa của gói · hạn gói ·
  cảnh báo sắp cạn · và **danh mục gói để đổi/nâng cấp ngay trong Studio** (nói rõ hệ thống chưa có cổng
  thanh toán). Dữ liệu từ `GET /api/plan/status`.
- ✅ **Trang giá công khai `/bang-gia`** (render phía máy chủ, không cần JS): định vị theo **3 persona**
  (nỗi đau + việc cần làm + gói khởi đầu/gói lớn lên), bảng gói lấy **trực tiếp từ DB** (không có con số
  nào ghi cứng), bảng so sánh, 4 bước từ ý tưởng tới ảnh bán được, 6 câu hỏi thường gặp, CTA theo trạng thái
  đăng nhập (khách → /dang-ky · đã đăng nhập → Vào Studio / Kích hoạt trong Studio) và **nói thật** về việc
  chưa có cổng thanh toán. Link tới trang giá được thêm ở trang Đăng nhập và Đăng ký.
- **Đợt 1 đã ĐÓNG** (trừ 2 việc phụ thuộc quyết định của chủ dự án: **bật** cờ enforce và **cổng thanh toán** — Q1/Q2).

### Đợt 3 — Tối ưu thao tác — ✅ PHẦN ĐẦU ĐÃ TRIỂN KHAI (2026-09-19)
- ✅ **Tạo hàng loạt trong card Tạo ảnh** (tab mới "Hàng loạt"): dán danh sách sản phẩm/ý tưởng (mỗi dòng một mục,
  tối đa 12) × số biến thể (1–4) → **một lần bấm** ra cả bộ ảnh; hiển thị trước **số ảnh và credit ước tính theo
  gói**, cảnh báo khi không đủ credit, tiến trình gửi từng mục, và **mục lỗi không làm hỏng cả lượt**.
  Không thêm endpoint mới — mỗi mục vẫn đi đúng đường `/api/generate` cũ (trừ credit theo gói, ghi sổ cái,
  hạ cap độ phân giải theo gói); tạo lẻ và tạo hàng loạt dùng **chung một bộ dựng payload** (`imagePayload()`)
  nên không bao giờ lệch cấu hình.
- **Đo được**: 4 test mới (`BatchGenerationTest`) · 515 test xanh · chạy thật trong Studio (Chrome CDP):
  **24/24 bước** — tạo lẻ 1 lần gọi đúng payload; hàng loạt 3 mục × 2 biến thể = 3 lần gọi, đúng prompt từng
  dòng, đúng số biến thể, credit trừ đúng 6 (200 → 194), có tiến trình + toast tổng kết.
- ✅ **Tiến trình theo TỪNG MỤC** trong lượt hàng loạt (`batchSend.items`) + nút **"Chạy lại N mục lỗi"**
  (danh sách prompt lỗi được GIỮ LẠI sau khi lượt kết thúc — trước đây chỉ hiện toast rồi mất).
- ✅ **PHÍM TẮT cho khối duyệt mẫu**: `S` chọn ảnh chờ duyệt · `N` chuyển bước tiếp · `A` duyệt · `R` loại ·
  `Esc` đóng. Ba chốt an toàn: chỉ chạy khi khối duyệt đang mở · **không cướp phím khi đang gõ** (ô prompt,
  ô ghi chú duyệt…) · **nhường phím khi modal/công cụ canvas đang chạy** (Esc của modal vẫn phải đóng modal).
  Phím tắt được nhắc ngay trong khối và trong `title` từng nút (không có phím tắt "ẩn").
- **Đợt 3 đã ĐÓNG.**

### Đợt 2 — Không gian làm việc theo nghề — ✅ PHẦN ĐẦU ĐÃ TRIỂN KHAI (2026-09-19)
- ✅ **Panel "Bộ sưu tập"** (nhóm card MỚI, đứng đầu thanh công cụ trái) trả lời đúng 3 câu hỏi người làm
  nghề tự hỏi mỗi phiên: **đang làm bộ nào** (áp dụng cho phiên tạo ảnh — đổi/bỏ trong 1 cú bấm) ·
  **các bộ gần đây** kèm trạng thái (màu do máy chủ trả về) · số ảnh · **hạn chót đếm ngược** (quá hạn/còn N ngày) ·
  **việc đang chạy** (chờ xử lý / đang tạo) + nút **Xử lý ngay**.
- ✅ **Tạo bộ sưu tập ngay trong panel**: tên · mùa/vụ (→ tags) · hạn chót · brief ⇒ tạo xong **tự áp dụng**
  cho phiên làm việc (không phải vào workspace rồi quay lại bật "áp dụng").
- ✅ Panel **tự nạp dữ liệu** khi mở (trước đây dữ liệu dự án chỉ tải khi mở popover "Dự án" ⇒ vào panel sẽ thấy trống).
- ✅ Nút **Mở workspace** hoạt động từ trong card (card render bằng `<component :is>` không nhận event ⇒
  thêm cầu nối `store.requestWorkspace()` + StudioApp theo dõi).
- **Đo được**: 4 test mới (`CollectionsHubTest`) + 2 test cũ cập nhật có ý thức (7 → 8 panel; panel Bộ sưu tập
  đứng đầu thứ tự gốc) · **519 test xanh** · chạy thật trong Studio (Chrome CDP): **17/17 bước** — panel liệt kê
  đúng bộ + số ảnh + hạn chót, chọn bộ thì áp dụng, tạo bộ mới gửi đúng payload (tên/brief/hạn/mùa vụ) và tự áp
  dụng, mở được workspace.
- ✅ **Mẫu việc theo ngành** (`GET /api/job-templates` + khối "Bắt đầu từ mẫu việc" trong tab **Hàng loạt**):
  bốn mẫu trọn vẹn — **Lookbook bộ sưu tập** (6 kiểu ảnh) · **Ảnh đăng sàn TMĐT** (4 kiểu, 1:1 nền sạch) ·
  **Mẫu kỹ thuật gửi xưởng** (4 kiểu + **bảng size và ghi chú kỹ thuật điền sẵn cho gói xuất**) ·
  **Catalogue nhiều SKU**. Bấm một mẫu là có ngay: danh sách prompt cho tạo hàng loạt + tỉ lệ + độ phân giải;
  mẫu của xưởng còn **điền sẵn khối "Xuất gói cho xưởng"** (không bắt gõ lại bảng size).
  Nguồn dữ liệu ở **một chỗ duy nhất** (PHP) — JS đọc qua API, có test cấm chép prompt vào JS.
- ✅ **Chi phí & tiến độ theo bộ sưu tập** (`GET /api/projects/{id}/stats` + khối trong panel "Bộ sưu tập"):
  số ảnh **xong / đang chạy / lỗi** · **credit đã dùng** · **hạn còn lại** (âm = quá hạn) · **phản hồi mới nhất
  của khách**. Số liệu lấy trực tiếp từ bảng `generations` (không đếm lại ở client) nên không lệch.
- ✅ **Chạy lại CHỈ mục lỗi** trong lượt tạo hàng loạt (kèm tiến trình từng mục): một lượt 12 mục mà 2 mục lỗi
  thì không phải làm lại cả lượt — danh sách prompt lỗi được giữ lại và chạy lại bằng một cú bấm.
- ✅ **Duyệt mẫu theo lô** (`POST /api/projects/{id}/shots/review` + khối "Duyệt mẫu" trong panel Bộ sưu tập):
  chốt / loại / **đẩy lên bước kế tiếp** cho NHIỀU ảnh trong một lượt, trả **kết quả TỪNG ẢNH**. Trước đó vòng đời
  duyệt ảnh (đã có từ Đợt 1.1) là **tính năng chết**: `grep -rn 'shot-state' resources/js` = 0 và `show()`
  không trả `shot_state`. Hàng đợi duyệt chéo của Super Admin vẫn dùng `?scope=pending` + `transition` sẵn có.
- **Đợt 2 đã ĐÓNG** (quy trình duyệt nay có cả nền lẫn giao diện).

### Đợt 3 — Tối ưu thao tác
- Tạo hàng loạt 1 cú bấm (N SKU × M bối cảnh), hàng đợi có tiến trình thật, so sánh trước/sau, phím tắt.
- Hiển thị chi phí trước khi chạy + cảnh báo credit thấp + nâng cấp tại chỗ.
- Đo: giảm ≥ 40% số cú bấm cho cùng kết quả; Time-to-first-image < 5 phút.

### Đợt 4 — Xuất hàng & cộng tác — ✅ PHẦN ĐẦU ĐÃ TRIỂN KHAI (2026-09-19)
- ✅ **Xuất gói cho xưởng** (`GET /api/projects/{id}/export` → 1 file ZIP) gồm: **ảnh tham chiếu** đánh số
  (`anh/01-<mô tả>.jpg`) · **phiếu kỹ thuật** từng mẫu (ô trống cho xưởng điền: chất liệu · màu · đường may ·
  chi tiết cần lưu ý) · **bảng size CSV** (dùng số đo người dùng nhập, không nhập thì phát mẫu S/M/L/XL) ·
  **thông tin bộ sưu tập + brief của khách** · **manifest.json** cho hệ thống của xưởng · **README** hướng dẫn.
- ✅ **Trung thực là bất biến**: ảnh nào không tải được thì ghi rõ vào `anh/_KHONG_TAI_DUOC.txt` **và**
  `manifest.skipped` + cảnh báo trong README (không im lặng bỏ qua — xưởng nhận thiếu ảnh mà không biết là tai họa);
  README nói rõ **ảnh AI là ảnh THAM CHIẾU**, không in/cắt thẳng để sản xuất hàng loạt.
- ✅ **UI**: trong panel "Bộ sưu tập" (bộ đang áp dụng) có nút **Xuất gói cho xưởng** → nhập bảng size +
  ghi chú kỹ thuật → tải ZIP; nêu rõ gói gồm những gì trước khi tải.
- **Đo được**: 5 test mới (`ProjectExportTest`: quyền · đủ 5 thành phần · bảng size đi nguyên vào gói ·
  ảnh lỗi được báo trong gói · bộ chưa có ảnh vẫn xuất được) · **524 test xanh** · UI kiểm bằng Chrome CDP
  **12/12 bước** (URL tải gói đúng endpoint + mang đúng bảng size/ghi chú đã mã hoá) · chạy thật trên
  production (tinker: tạo bộ tạm → dựng ZIP → đọc lại nội dung → xoá bộ tạm).
- ✅ **Chia sẻ link cho khách duyệt** (`/chia-se/{token}`): khách/nhân viên duyệt **KHÔNG cần tài khoản
  FabrikAI** vẫn xem được ảnh + brief + hạn chót và bấm **Duyệt / Yêu cầu sửa** kèm ghi chú; phản hồi được
  **lưu vào bộ sưu tập** (tên · quyết định · nội dung · thời điểm) và hiện ngay trong panel "Bộ sưu tập"
  cho designer. Link **có hạn** (7/30/90 ngày), **thu hồi được**, đếm lượt xem; hết hạn/thu hồi ⇒ 404
  (không dò được token); trang công khai **noindex**; gửi phản hồi có throttle theo IP.
- **Còn lại của đợt 4**: phân quyền nhân viên theo gói (số ghế) · preset kênh bán (sàn TMĐT/catalogue)
  cho tên file ảnh · tự động chuyển trạng thái bộ sưu tập khi khách bấm "Duyệt" (hiện CỐ Ý chỉ ghi phản hồi,
  không tự đổi trạng thái — chuyển trạng thái là quyết định của chủ, có whitelist riêng).

---

## 6. Việc phải làm ngay (đợt 1 — chi tiết kỹ thuật)

1. `users.plan_credits_granted_at` (timestamp) — mốc cấp credit gần nhất của chu kỳ hiện tại.
2. `PlanService::grantCycleCredits(User)` — CAS (`UPDATE … WHERE plan_credits_granted_at IS NULL OR < mốc chu kỳ`) rồi mới cộng credit ⇒ an toàn khi chạy song song; ghi sổ cái loại `plan_grant` có tham chiếu gói + chu kỳ.
3. `php artisan studio:grant-plan-credits` — cho cron hPanel (chạy hằng ngày, tự bỏ qua người chưa tới kỳ).
4. Lazy grant ở `/api/boot` và trước khi tạo ảnh (người gia hạn xong có credit ngay, không phải chờ cron).
5. `studio_credit_cost($kind, $user)` — đọc `plan.*_credit_cost`, fallback setting toàn cục (tương thích ngược tuyệt đối).
6. `/api/boot` trả `plan` (tên, slug, hạn, credit/tháng, cap) + `cycle` (đã cấp bao nhiêu, cấp lúc nào).

---

## 7. Phụ lục — quyết định cần chủ dự án xác nhận

| # | Việc | Vì sao cần hỏi |
|---|---|---|
| Q1 | ~~Bật chặn khi hết credit?~~ **✅ ĐÃ QUYẾT: BẬT (2026-09-19)** | Mặc định nay là BẬT (`config/studio.php` + `studio_plan_limits()`); tắt lại bằng setting `studio_enforce_credits=0`. 402 có cấu trúc + tự mở bảng nâng cấp; Super Admin được miễn chặn để không tự khoá mình |
| Q2 | ~~Cổng thanh toán~~ **✅ ĐÃ QUYẾT: chuyển khoản + hỗ trợ trước, VNPay sau (2026-09-19)** | Nút "Nâng cấp" nay là **yêu cầu có mã theo dõi** ({{B}}upgrade_requests{{B}}); khách chuyển khoản theo mã, Super Admin kích hoạt. Lỗ hổng "khách tự kích hoạt gói trả phí miễn phí" đã đóng |
| Q3 | ~~Gói theo mùa vụ/xưởng~~ **✅ ĐÃ QUYẾT + ĐÃ LÀM: bán theo VỤ (2026-09-19)** | Gói "Xưởng theo vụ": 3.290.000 ₫/vụ (3 tháng) · 3.000 credit cấp MỘT LẦN cho cả vụ · mốc mua 1/2/4 vụ; `plans.unit_months · cycle_months · units` + `upgrade_requests.units` cho phép mọi gói khai báo đơn vị bán của mình |
| Q4 | ~~Số ghế theo gói~~ **✅ ĐÃ QUYẾT + ĐÃ LÀM: nhóm làm việc theo ghế (2026-09-19)** | Ghế: Miễn phí 1 · Khởi nghiệp 1 · Chuyên nghiệp 3 · Studio 10 · Xưởng theo vụ 5. Chủ nhóm mời thành viên bằng email (mật khẩu tạm một lần); cả nhóm dùng chung gói + credit + bộ sưu tập, ảnh ghi rõ ai tạo; thành viên không mua gói/không xoá bộ sưu tập |

---

## 8. KẾT QUẢ ĐÃ TRIỂN KHAI (đóng goal — 2026-09-19)

Mục tiêu: *TIÊN TIẾN – THUẬN TIỆN – TỐI ƯU – TIẾT KIỆM THỜI GIAN* cho 3 nhóm khách. Dưới đây là đối chiếu
từng chặng với **bằng chứng đo được**, không phải mô tả ý định.

### 8.1 Bốn chặng — trạng thái

| Chặng | Nội dung | Trạng thái | Bằng chứng |
|---|---|---|---|
| 1 | Phân tích sâu dự án (kiến trúc · luồng thật · điểm nghẽn · số liệu) | ✅ | §1 tài liệu này — mọi kết luận kèm `file:line` |
| 2 | Phân tích + thiết kế lại gói cước | ✅ | §3 (6 lỗ hổng có bằng chứng) + Đợt 1 (đã bỏ hẳn 3 lỗ hổng: credit không được cấp · chi phí theo gói không dùng · cap không thực thi) |
| 3 | Tài liệu chiến lược UX/UI/workflow theo persona + roadmap có tiêu chí đo | ✅ | §2 · §4 · §5 (4 đợt, mỗi đợt có số đo) |
| 4 | Triển khai cải tiến giá trị cao nhất + kiểm thử + đo + deploy | ✅ | 11 vòng deploy lên production (bảng dưới) |

### 8.2 Đã giao cho từng persona (việc thật, không phải tính năng trang trí)

**Nhà thiết kế** — *"ra ảnh cho cả bộ, không phải bấm 16 lần"*
- Tạo **hàng loạt** (12 mục × 1–4 biến thể) trong một lượt, có **tiến trình từng mục** và **chạy lại chỉ mục lỗi**.
- **Mẫu việc theo ngành** (4 mẫu: lookbook · sàn TMĐT · mẫu kỹ thuật gửi xưởng · catalogue) điền sẵn prompt + tỉ lệ + độ phân giải.
- **Bộ sưu tập** là điểm vào công việc hằng ngày: đang làm bộ nào, còn bao nhiêu ảnh, hạn còn mấy ngày.
- **Phím tắt duyệt mẫu** (`S/N/A/R/Esc`) — không phải rời bàn phím giữa buổi duyệt hàng chục ảnh.

**Chủ doanh nghiệp / thương hiệu** — *"biết mình đang chi bao nhiêu, ai duyệt, khách nói gì"*
- **Chi phí & tiến độ theo bộ sưu tập**: ảnh xong/đang chạy/lỗi · **credit đã dùng** · hạn còn lại · phản hồi mới nhất của khách.
- **Chia sẻ link cho khách duyệt** (không cần tài khoản): có hạn · thu hồi được · đếm lượt xem · khách bấm Duyệt/Yêu cầu sửa và phản hồi lưu vào bộ.
- **Trang giá công khai** đọc trực tiếp từ DB, nói thật về việc chưa có cổng thanh toán; gói & credit hiển thị ngay trong Studio.

**Chủ xưởng may** — *"nhận được gói đủ nghĩa để cắt may"*
- **Xuất gói cho xưởng**: 1 ZIP gồm ảnh tham chiếu đánh số + phiếu kỹ thuật + bảng size CSV + manifest + README;
  ảnh nào không tải được thì **ghi rõ** trong gói (không im lặng bỏ qua); README nói rõ ảnh AI là **ảnh tham chiếu**.

### 8.3 Số đo (trước → sau)

| Chỉ số | Trước goal | Sau goal |
|---|---|---|
| Test tự động | 491 test / 2.657 assert | **554 test / 3.069 assert** (0 đỏ) |
| Credit theo chu kỳ | **không bao giờ được cấp** (grep `Schedule::` = 0) | cấp idempotent (CAS) + lazy + lệnh cron |
| Chi phí ảnh/video theo gói | **cột trang trí** (9 chỗ đọc setting toàn cục) | `studio_credit_cost()` — 8 chỗ trong pipeline dùng theo gói |
| `resolution_cap` của gói | **không được kiểm ở đâu** | hạ xuống cap + trả `notice` nói rõ lý do |
| Trang giá cho khách chưa đăng nhập | **không có** | `/bang-gia` render từ DB |
| Vòng đời duyệt ảnh | có backend, **không giao diện nào gọi** | duyệt theo lô + phím tắt + nhãn trạng thái trên từng ảnh |
| Số cú bấm cho một bộ 8 SKU × 2 bối cảnh | 16 lần sửa prompt + 16 lần bấm | **1 lần dán danh sách + 1 lần bấm** (12 mục × biến thể) |

### 8.4 Việc còn lại (cần quyết định của chủ dự án, không phải việc kỹ thuật)

- **Q1** bật chặn khi hết credit (`studio_enforce_credits`, hiện **TẮT**) — nên bật khi đã có thanh toán.
- **Q2** cổng thanh toán (VNPay/MoMo/chuyển khoản) — hiện nút "Nâng cấp" nói thật là "kích hoạt, thanh toán sau".
- **Q3** gói theo mùa vụ/xưởng (bán theo đơn thay vì theo tháng).
- **Q4** số ghế theo gói (chủ doanh nghiệp cần nhiều người dùng chung một gói).
- Việc kỹ thuật còn nợ (không chặn khách): preset tên file theo kênh bán; tự động chuyển trạng thái bộ sưu tập khi
  khách bấm "Duyệt" (hiện CỐ Ý chỉ ghi phản hồi — chuyển trạng thái là quyết định của chủ, có whitelist riêng);
  cron `studio:grant-plan-credits` trên hPanel (đường lazy đã chạy nên chưa gấp).

---

## 9. Đợt 5 — Tối ưu UX/UI theo ảnh hưởng VSCode + OpenArt.ai (2026-09-20)

> Bối cảnh: Studio **đã có** xương sống kiểu VSCode từ trước (activity bar · command palette `Ctrl+K` ·
> status bar · panel trái/phải · phím tắt canvas). Đợt này **không xây lại** phần đó — chỉ vá đúng bốn
> chỗ mà người dùng vẫn phải "tự tìm đường", lấy cảm hứng từ **OpenArt.ai** (luồng tạo ảnh prompt-first,
> kết quả có hành động ngay trên ảnh) và **VSCode** (thông báo xếp chồng, quick-open đa nguồn).

### 9.1 Bốn việc đã làm

| # | Việc | Ảnh hưởng từ | Trước đây | Nay |
|---|---|---|---|---|
| 1 | **Màn hình canvas trống giàu nội dung** (`CanvasEmptyState.vue`) | OpenArt | một dòng chữ *"Chọn/hiện một ảnh (Nguồn hoặc Kết quả) để làm việc."* | thanh prompt tạo ảnh **ngay trên canvas** + chọn biến thể + 7 tỉ lệ + số credit còn lại + **4 mẫu việc theo ngành** + ảnh gần đây + gợi ý phím tắt |
| 2 | **Trung tâm thông báo** (`NotificationCenter.vue`) | VSCode | `flashMsg` là **một ô duy nhất**: toast sau **ghi đè** toast trước, tự tắt sau 2,6s ⇒ thông báo *"mục 3 lỗi"* của lượt hàng loạt có thể biến mất trước khi đọc | hàng đợi **xếp chồng** góc phải-dưới (tối đa 4) · lỗi giữ **8s** (thường 4,2s) · đóng tay từng mục · nút **Xoá hết** · kèm **thẻ tiến trình** đọc số THẬT (`generateProgress` · `batchSend` · số ảnh đang chạy) |
| 3 | **Hành động ngay trên ảnh kết quả** (`OutputModule.vue`) | OpenArt | thumbnail chỉ có **1** hành động (nhấn = mở viewer) + gợi ý kéo-thả | mỗi ảnh có thanh hành động hiện khi rê chuột **hoặc focus bàn phím**: **Canvas** (thành layer) · **Tải** · **Biến thể** · **Sửa** — đúng nguyên tắc 5 (*"kết quả luôn có bước tiếp theo"*) |
| 4 | **Quick Open đa nguồn** (mở rộng palette) | VSCode | palette chỉ có **lệnh** | thêm 3 nguồn dữ liệu thật — **Bộ sưu tập & dự án** · **Mẫu việc theo ngành** · **Ảnh đã tạo** — hiện theo **nhóm có tiêu đề**, kèm tiền tố quen thuộc: `>` lệnh · `#` dự án · `@` ảnh |

### 9.2 Nguyên tắc đã tuân thủ (mục 4.1)

- **Nguyên tắc 3 — chi phí hiển thị TRƯỚC khi bấm**: màn hình trống hiện `~N credit` và **co giãn theo số biến thể**
  (đo trên trình duyệt thật: 1 biến thể `~1` · 2 biến thể `~2` · 4 biến thể `~4`).
- **Nguyên tắc 5 — kết quả luôn có bước tiếp theo**: 4 hành động một cú bấm ngay trên thumbnail.
- **Nguyên tắc 6 — tái dùng là mặc định**: mẫu việc theo ngành đặt ngay chỗ bắt đầu, không phải vào card rồi mới tìm.
- **Nguyên tắc 7 — trạng thái luôn nhìn thấy**: gói · credit · việc đang chạy nằm cùng chỗ với hành động.

### 9.3 Ràng buộc đã giữ

- **Không thêm endpoint nào.** Mọi nút đi qua API đã có của store (`select` · `openViewer` · `applyProject` ·
  `applyJobTemplate` · `/api/generations/{id}/download`).
- **Không đổi luồng generate.** `store.generateImage()` và `generateBatch()` giữ nguyên; màn hình trống chỉ gọi lại.
- **`store.toast(msg, type)` vẫn là API duy nhất** các nơi khác gọi — chỉ đẩy thêm vào hàng đợi, không phá chỗ gọi cũ.
- **Nối card ↔ màn hình bằng kênh store**, không đụng DOM: `requestActivity()` (đổi nhóm công cụ) và
  `requestBatchPrompts()` (đổ prompt mẫu việc vào tab *Hàng loạt*) — cùng cách đã dùng cho `requestWorkspace()`.

### 9.4 Đo được

| Kiểm chứng | Kết quả |
|---|---|
| Test tự động | **614 test / 3.908 assert — XANH toàn bộ** (không sửa test nào) |
| Build production | `npm run build` xanh, asset trong `public_html/build` |
| Màn hình trống (Chrome CDP, đã đăng nhập) | thanh prompt + `~1 credit` + 4 mẫu việc đúng dữ liệu `/api/job-templates` (Lookbook · sàn TMĐT · mẫu kỹ thuật · catalogue) + ảnh gần đây + phím tắt |
| Chi phí trước khi bấm | `1 → ~1` · `2 → ~2` · `4 → ~4` credit (**4/4 bước**) |
| Thông báo xếp chồng | bắn 3 toast liên tiếp ⇒ **3 mục cùng sống** (trước đây chỉ 1) · nút Xoá hết hoạt động · tự tắt đúng hạn · đóng tay đúng 1 mục |
| Hành động trên ảnh kết quả | 5 ảnh × 4 nút = **Canvas 10 · Tải 5 · Biến thể 5 · Sửa 6** (aria-label) · thanh hành động **ẩn mặc định** (opacity 0), hiện khi hover |
| Luồng một cú bấm | bấm **Canvas** ⇒ số layer `0 → 1` và màn hình trống tự biến mất · bấm **Biến thể** ⇒ panel trái đổi sang *Tạo biến thể ảnh* |
| Quick Open | mở bằng `Ctrl+K`: **4 nhóm** (Lệnh · Bộ sưu tập & dự án · Mẫu việc theo ngành · Ảnh đã tạo) · lọc `lookbook` ra đúng mẫu việc · tiền tố `#`/`@`/`>` lọc đúng nhóm |

### 9.5 Bài học tự bắt được trong đợt này

- **Kiểm chứng bằng mắt phải so khớp đúng thứ người dùng thấy, không phải thứ mình viết.** Hai lần test báo ĐỎ
  trong khi tính năng CHẠY ĐÚNG: (a) CSS `uppercase` biến `Bắt đầu từ mẫu việc` thành `BẮT ĐẦU TỪ MẪU VIỆC`;
  (b) icon SVG chèn giữa chuỗi nên `innerText` trả `"Canva"` thay vì `"Canvas"`. Cả hai lần đều **phải sửa TEST**,
  không phải sửa sản phẩm — nếu tin ngay vào test đỏ thì đã "sửa" một thứ đang đúng.
- **Test cũng có bug escape:** regex `\d` viết trong template literal bị JS nuốt thành `d` ⇒ test báo "không tìm thấy
  chi phí credit" trong khi chuỗi `~1 credit` nằm ngay đó. Đổi sang `[0-9]` là xanh.
- **Không tự ý sửa DOM của component khác.** Bản đầu của màn hình trống tự `querySelector` ô prompt của ConceptCard
  để điền chữ — cách đó vỡ ngay khi card đổi bố cục. Đã thay bằng kênh store `requestBatchPrompts()`.


## 10. Đt 6 — Khu "Cài đặt của tôi" HP NHẤT + tùy chỉnh lưu theo TÀI KHOẢN (2026-09-20)

### 10.1 Vấn đề (đo được, không suy đoán)

Cài đặt của người dùng là **4 trang SPA rời rạc**: `/presets` · `/stylist-data` · `/model-settings?tab=model` ·
`?tab=pose`. Mỗi trang một app riêng, chỉ có một lối thoát "← Về FabrikAI": muốn sang mục khác phải quay về
Studio rồi mở lại menu bánh răng. Đo trên mã nguồn:

| Lỗi | Bằng chứng trước khi sửa |
|---|---|
| Không có điều hướng giữa 4 mục | mỗi app chỉ render 1 link `href="/"` |
| 4 kiểu tiêu đề | `text-xl` + emoji 🗂️ ở một trang, `text-xl` + ⚙️ ở trang khác, 2 trang còn lại khác nữa |
| 3 khay thông báo tự chế | 2400ms (stylist) · 2600ms (presets) · 2800ms (model) — 3 vị trí, 3 kiểu |
| 2 kiểu hộp thoại xác nhận | `window.confirm()` ở presets + model · modal tự chế ở stylist |
| 2 lối vào cho CÙNG một trang | menu có 2 dòng `/model-settings?tab=model` và `?tab=pose` |
| Không tìm kiếm được | 9 danh mục × nhiều preset, phải cuộn bằng mắt |
| Tải không có khung xương | chữ "Đang tải…" trần; rỗng thì không nói phải làm gì |

Và lỗi nặng nhất, không phải giao diện: **tùy chỉnh nằm trong `localStorage`** ⇒ đổi máy hoặc đổi trình duyệt
là **mất sạch** preset, loại trang phục và câu hỏi người dùng đã tạo. Với người dùng làm việc trên nhiều máy
thì đây là mất dữ liệu thật.

### 10.2 Đã làm

**Một khu thay cho bốn trang.** Một app duy nhất (`resources/js/studio/my-settings.js`) + một sidebar trái +
một khay thông báo + một hộp thoại xác nhận. Bốn mục: **Preset · Khuôn mặt · Dáng pose · Trợ lý thiết kế**.
Thêm địa chỉ chính thức `/cai-dat` và `/cai-dat/{mục}`; đổi mục thì `history.pushState` sang `/cai-dat/<mục>`
nên vẫn deep-link và back/forward được.

**Giữ 4 URL cũ thay vì chuyển hướng hết.** Chuyển hướng sẽ phá hợp đồng "trang cũ trả 200" đã khoá bằng
`tests/Feature/UserCatalogTest.php`, và làm chết bookmark đang dùng. Cả 4 URL cùng render MỘT blade; server
truyền `data-section` để app mở đúng mục — nhờ vậy `/model-settings?tab=pose` mở đúng *Dáng pose* mà không
phải đoán từ URL.

**Tùy chỉnh chuyển lên server theo tài khoản.** Bảng `user_catalogs` + `GET/PUT /api/user-catalogs/{name}`
(whitelist đúng 3 tên, trần 256 KB / 500 mục, mọi truy vấn khoá theo `auth()->id()`). Giữ nguyên mô hình 3 phần
`custom` / `edits` / `hidden` và toàn bộ ngữ nghĩa ghép `baseline ⊕ bản của user`.

**Di trú một lần, không bỏ rơi ai.** Máy nào còn bản cũ trong `localStorage` thì lần mở đầu tiên tự đẩy lên
server rồi xoá bản cũ — đã kiểm riêng đường này (7 phép kiểm, gồm cả "mở lại KHÔNG nhập trùng").

**Studio dùng LẠI `StylistSection`** thay vì bản sao `StylistDataManager`: một cách cài đặt duy nhất cho cả
khu Cài đặt lẫn thẻ Trợ lý thiết kế trong Studio.

### 10.3 Ràng buộc đã giữ

- **Không đổi luồng generate.** Không đụng tới đường tạo ảnh.
- **Không thêm endpoint nào ngoài thoả thuận.** Đúng 2 route mới `/api/user-catalogs/{name}` (GET · PUT).
- **`merge()` và `create()` vẫn ĐỒNG BỘ.** `create()` trả về id ngay, `merge()` đồng bộ — chỉ việc gửi mạng là
  lùi lại (debounce 350ms). Nhờ vậy component không phải chờ mạng mỗi lần render, và `merge()` giữ nguyên
  thứ tự "baseline trước, mục tự tạo nối vào cuối".
- **Module mới phải đăng ký vào gói cước.** `user-catalogs` được thêm vào cả module `prompt` lẫn `stylist` ở
  `ModuleRegistry` — bỏ qua bước này thì tài khoản ở gói không có module sẽ bị **403**, đúng loại lỗi đã gặp
  với `/api/job-templates` trước đây.

### 10.4 Đo được

| Kiểm chứng | Kết quả |
|---|---|
| Test tự động | **639 test / 4.039 assert — XANH** (trước 614/3908 ⇒ **+25 test, +131 assert**) |
| Guard `scripts/check-local-catalog.mjs` | **23/23 ĐẠT** (ngữ nghĩa ghép giữ nguyên) |
| Build production | `npm run build` xanh · entry 3 cái gộp còn 1 |
| Điều hướng (Chrome CDP) | **21/21** — mỗi lối vào mở đúng mục, sidebar 4 mục, bấm mục đổi cả nội dung lẫn URL |
| Lưu trữ theo tài khoản (dev) | **10/10** — thấy `PUT /api/user-catalogs/presets`, preset **còn sau khi tải lại trang**, `GET` trả về đúng, xoá được qua hộp thoại |
| Di trú localStorage → server | **7/7** — preset cũ hiện ra, được đẩy lên server, bản cũ bị xoá, mở lại không nhập trùng |
| Bố cục | 1600×1000: sidebar 256px, không tràn ngang · 900×800: sidebar ẩn, có nút chọn mục · panel sidebar `sticky`, bám lại ở đỉnh sau khi cuộn 900px |
| Tương phản chữ (WCAG AA) | **0 chỗ dưới chuẩn** trên cả 3 mục (đo bằng công thức độ chói tương đối) |
| Production (tài khoản thật) | **10/10** — đăng nhập · sidebar 4 mục · ghi lên server · còn sau khi tải lại · cả 3 mục render, 0 lỗi console |
| Dọn dẹp | tài khoản tạm đã xoá · `user_catalogs` = **0 dòng** · log ERROR giữ nguyên mức **7** |

### 10.5 Bài học tự bắt được trong đợt này

- **Kiểm chứng trên môi trường THẬT bắt được lỗi mà test xanh không thấy.** `UserCatalogApiTest` (23 test) xanh
  hoàn toàn — vì `RefreshDatabase` tự chạy migration. Nhưng DB dev **chưa migrate** nên `GET /api/user-catalogs/presets`
  trả **500**, `load()` thất bại và **không có lệnh ghi nào được gửi**. Nếu chỉ tin vào test xanh rồi deploy thì
  production cũng 500 y hệt. Vì vậy migration đã được chạy tường minh khi deploy.
- **Test ĐỎ chưa chắc là sản phẩm sai.** Hai lần báo đỏ trong đợt này đều do **đo nhầm thứ**: (a) đo chiều cao
  `<aside>` — vốn là flex item bị kéo giãn theo nội dung — trong khi phần tử `sticky` mới là thứ phải nằm trong
  khung nhìn; (b) dò `#app` trong khi phần tử gốc thật là `#studio-root`. Sửa TEST, không sửa sản phẩm.
- **Chuyển lớp lưu trữ là thay đổi có thể mất dữ liệu — phải có đường lùi.** Chỉ cần quên `await catalog.load()`
  ở một chỗ là người dùng **không thấy tùy chỉnh của chính mình** (baseline hiện ra như chưa từng sửa). Đã biến
  điều này thành một khẳng định trong test, và chính nó phát hiện `StylistDataManager` cũ (dùng trong Studio)
  không gọi `load()` — nếu để nguyên thì thẻ Trợ lý thiết kế sẽ hiện thiếu dữ liệu của người dùng.
