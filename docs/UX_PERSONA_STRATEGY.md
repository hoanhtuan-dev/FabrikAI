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

## 11. Đợt 7 — Ảnh ở Outputs KHÔNG lưu / KHÔNG nạp được vào Thư viện & Bộ sưu tập (2026-09-20)

### 11.1 Vấn đề — đo được, không suy đoán

Khiếu nại: *ảnh outputs không lưu | load từ library | bộ sưu tập*. Đo tách riêng hai chiều thì thấy
**chỉ MỘT chiều hỏng**, và hỏng đúng ở chỗ ít ai ngờ:

- **Chiều NP: ĐÚNG.** Đã kiểm chứng đầu-cuối bằng một generation thật (id 7) gắn vào dự án 1:
  `/api/latest`, `/api/library/data` và `/api/projects/1` đều trả về nó kèm `project_id = 1`.
  `StudioLibraryService::list()` duyệt **toàn bộ** `$user->generations()` (có phân trang) nên không hề lọc mất;
  `LibraryApp.vue` được mount bằng `v-if` và gọi `loadLibrary(true)` trong `onMounted` — mở lại là nạp lại.
  → Không có lỗi nào để sửa ở chiều này. *(Nếu chỉ sửa theo cảm giác thì đã sửa nhầm chỗ.)*
- **Chiều LƯU: HỎNG.** Chỉ `/api/generate` và `/api/render-video` nhận và ghi `project_id`.
  Bằng chứng trực tiếp: `POST /api/reframe` kèm `project_id: 1` trả **200** và tạo ảnh — nhưng dòng
  trong CSDL có `project_id = NULL`. Endpoint **âm thầm bỏ qua** tham số, không báo lỗi.
- Dữ liệu dev khớp với kết luận: **5 generation, 3 dự án của chủ tài khoản, 0 ảnh nào được gắn** (một trong
  số đó chính là một ảnh `reframe`).

**9 phép phái sinh không gửi/không lưu `project_id`:** inpaint, reimagine, removeBackground, refgen, compose,
upscale, look, regionEdit, reframe (processAndStore) — cộng thêm ảnh TẢI LÊN.

Hệ quả với người dùng: `store.applyProject()` hiện toast *“ảnh/video tạo mới sẽ tự gắn vào”* — một lời hứa
bị vi phạm ở **mọi thao tác trừ tạo ảnh mới**. Người dùng sửa/nâng cấp ảnh trong một bộ sưu tập và ảnh rơi ra ngoài.

### 11.2 Đã làm

**a) Máy chủ — nhận và lưu `project_id` ở cả 9 phép phái sinh.**
Thêm `resolveProjectId(Request, ?Generation $source)`: đọc `project_id` từ request; nếu id đó **không tồn tại
hoặc không thuộc tài khoản** thì **bỏ qua** và lùi về dự án của ảnh nguồn. Cố ý **không** dùng `Rule::exists`:
một id dự án cũ còn sót ở client sẽ làm hỏng cả thao tác sửa ảnh — hỏng nặng hơn nhiều so với việc ảnh không được gắn.
4 chỗ tạo generation trực tiếp (regionEdit, upscale, look, processAndStore) và 5 chỗ đi qua `queueGeneration`
(inpaint, reimagine, removeBackground, refgen, compose) đều đã nhận.

**b) Máy chủ — trả `project_id` về trong phản hồi** (`queueGeneration`, `show()`, regionEdit, và cả 3 phản hồi
`media_url/generation_id`), để client lưu được **ngay** mà không phải chờ `/api/latest`.

**c) Client — gửi `project_id` ở cả 8 phép** qua `store.projectField()`, và `addGen()` tự điền `project_id`/`project`
cho ảnh vừa tạo; `pollGeneration` đồng bộ lại khi job xong.

**d) Ảnh TẢI LÊN — bảng liên kết riêng `upload_project_links`.**
Ảnh tải lên là **FILE trên đĩa**, không có dòng nào trong CSDL (được liệt kê bằng `glob()`), nên **không thể**
thêm cột `project_id` cho chúng. Bảng riêng với `unique(user_id, rel)`. **Cố ý KHÔNG dùng `studio_assets`**:
đó là kho tài nguyên dùng chung cho picker “Nguồn ảnh” (model/pose/trang phục) — nhét ảnh tải lên vào đó sẽ làm
chúng hiện nhầm trong picker. Mọi thao tác ghi đi qua đúng hai lớp của đường xoá: `normalizeUploadRel()`
(chặn leo thư mục) và `studio_upload_visible_to()` (chặn gắn ảnh của người khác).
Xoá file ⇒ xoá liên kết; xoá dự án ⇒ ảnh rời khỏi bộ sưu tập nhưng **không** bị xoá khỏi đĩa.

**e) Giao diện** — mỗi ảnh tải lên trong Thư viện có một ô chọn bộ sưu tập (cả dạng lưới và dạng danh sách).

### 11.3 Ràng buộc đã giữ

- **Giữ nguyên hành vi hiện tại**: không đổi route cũ, không đổi hình dạng phản hồi cũ (chỉ **thêm** trường).
- Client cũ gửi lên mà không có `project_id` ⇒ hành vi **y hệt trước** (không gắn gì), trừ khi ảnh nguồn đã
  thuộc một bộ sưu tập — khi đó kết quả **ở lại** đúng bộ sưu tập đó, đúng như người dùng mong đợi.
- Không nới lỏng `$fillable` của `Project`/`User` chỉ để tiện cho test; test đi đúng đường `unguarded` như seeder.

### 11.4 Đo được

- `vendor/bin/phpunit`: **658 test / 4089 khẳng định — XANH** (thêm 19 test mới: 10 cho 9 phép phái sinh, 9 cho ảnh tải lên).
- `npm run build`: thoát 0. `node scripts/check-local-catalog.mjs`: **23/23 ĐẠT**.
- **Chrome thật** (đăng nhập `owner@fabrikai.shop`, DOM thật): 50 ảnh tải lên đều có ô chọn bộ sưu tập với 4 lựa chọn
  (1 “chưa gắn” + 3 dự án của tài khoản); phát sự kiện `change` như người dùng ⇒ **đúng 1** ảnh đổi, `/api/uploads`
  trả `project_id = 1`; **tải lại trang** ⇒ vẫn `project_id = 1` và ô chọn hiện đúng dự án.
- **Kịch bản gốc trên server dev**: `POST /api/reframe` kèm `project_id = 1` ⇒ 200, `project_id = 1` ở **cả phản hồi
  lẫn `/api/latest`**. Trước khi sửa, đúng request đó tạo ảnh với `project_id = NULL`.
- Ảnh vừa tạo **hiện ngay** dưới bộ lọc “chỉ outputs của dự án đang áp dụng” (trước đây vô hình cho tới lần nạp sau).
- Dọn dẹp: dự án dev trở lại **5 generation / 0 liên kết / 5 dự án**, file test đã xoá.

### 11.5 Bài học tự bắt được trong đợt này

- **“Không lưu” và “không nạp” là hai lỗi khác nhau — phải đo tách ra.** Chiều nạp hoàn toàn đúng; nếu gộp chung
  rồi sửa cả hai thì vừa mất công vừa có nguy cơ làm hỏng phần đang chạy tốt.
- **Tham số bị bỏ qua âm thầm nguy hiểm hơn tham số bị từ chối.** Endpoint trả 200 nên không ai nghi ngờ;
  chỉ khi đọc thẳng dòng trong CSDL mới thấy `NULL`. Bài test mới khoá đúng chỗ đó.
- **Nhất quán giữa hai loại dữ liệu cùng nằm một chỗ.** Ảnh AI tạo và ảnh người dùng tải lên nằm chung một Thư viện;
  chỉ một loại vào được bộ sưu tập là điều vô lý với người dùng, dù về kỹ thuật chúng khác nhau hoàn toàn
  (một loại là dòng CSDL, một loại là file trên đĩa).
- **Không nới bảo mật để tiện.** `studio_assets` là đường tắt hấp dẫn nhưng sẽ làm rò ảnh cá nhân vào picker chung;
  và mọi thao tác ghi lên ảnh tải lên đều phải đi qua đúng hai lớp kiểm tra mà đường xoá đang dùng.

## 12. Đợt 8 — Nút “Lưu Output” RẤT CHẬM và KHÔNG lưu được vào Thư viện (2026-09-20)

### 12.1 Vấn đề — đo được, không suy đoán

Nút **“Lưu Output”** ở bảng Lớp (`LayersPanel` → `store.saveActiveLayerToOutput()`) có hai lỗi cùng lúc:

**a) Không hề lưu — chỉ DIỄN.** Hàm chỉ TẢI FILE lên rồi tự chèn một bản ghi **GIẢ** vào bộ nhớ trình duyệt:
`id: 'layer-' + Date.now()` — một CHUỖI, không có dòng nào trong CSDL. Đo thật trên dev:

| Đo | Kết quả |
|---|---|
| Bản ghi tạo ra | `{ id: "layer-1789726248857", provider: "layer" }` — id KHÔNG phải số |
| Có trong `/api/library/data` | **false** |
| Có trong `/api/latest` | **false** |
| Sau khi tải lại trang | **0 bản ghi còn lại** — biến mất hoàn toàn |

Trong khi giao diện vẫn báo *“Đã lưu layer vào Output.”* Bản thân mã nguồn đã **tự biết** điều này:
`deleteGen()` có một nhánh riêng cho các bản ghi giả ấy (nhận diện bằng `id.startsWith('layer-')`) và chỉ xoá
cục bộ, kèm chú thích *“KHÔNG có record server”*. Tức là hệ thống đã quen với việc bản ghi này không tồn tại —
thay vì sửa nó cho tồn tại thật.

**b) Rất chậm — vì tải lên lại một ảnh ĐÃ Ở TRÊN MÁY CHỦ.** Đo thật:

| Trường hợp | Kích thước phải tải lên |
|---|---|
| Layer lấy từ Output (2K) | **889.470 B ≈ 0,9 MB** |
| Layer ghép 2400×2400 trên canvas | **4.576.401 B ≈ 4,4 MB** |

Với ảnh vốn đã nằm sẵn dưới `/storage/…`, việc tải lại từng byte là **hoàn toàn vô ích**: thao tác chỉ cần ghi
một dòng CSDL. Trên mạng thật, 4,4 MB ở đường lên của người dùng là hàng chục giây chờ cho một cú bấm “Lưu”.
Thêm nữa, file tải lên đó còn bị tính là **file mồ côi** trong tab “Ảnh tải lên” — tốn đĩa cho một thao tác
không lưu được gì.

### 12.2 Đã làm

**a) Endpoint mới `POST /api/layers/save` — TẠO BẢN GHI THẬT.**

- Có `source_url` (ảnh đã ở trên máy chủ) ⇒ **CHỈ ghi dòng CSDL, không tải byte nào** (đường nhanh).
- Chỉ khi layer được **GHÉP trên canvas** (data URL — ảnh chưa hề có trên máy chủ) mới thật sự tải lên.
- Trả về `generation_id` **thật** + `media_url` + `project_id`.
- Ghi luôn vào **bộ sưu tập đang áp dụng** qua `resolveProjectId()` — cùng cơ chế với 9 phép phái sinh ở mục 11.

**b) Bảo mật — không nhận đường dẫn tuỳ tiện.** `source_url` phải: (1) đi qua `safeLocalFile()` (chặn leo thư mục,
giải symlink, chỉ nhận file thật trong vùng `storage/app/public`/`public`), và (2) qua `studio_upload_visible_to()`
(chặn lưu ảnh trong thư mục riêng của người khác). Sai ⇒ **422**, chứ KHÔNG âm thầm tạo một output trỏ vào hư
không — đúng kiểu lỗi vừa sửa.

**c) Client** — `saveActiveLayerToOutput()` nhận biết ảnh đã ở trên máy chủ (`/storage/…`) và gửi đường dẫn thay
vì tải file; dùng `generation_id` THẬT thay cho chuỗi `layer-…`. Vẫn `unshift` trực tiếp (KHÔNG dùng `addGen`) vì
`addGen` sẽ đẩy thêm một layer canvas trùng với layer đang lưu.

**d) Khai module** — route mới được khai vào `ModuleRegistry` (module `compose`, cùng không gian canvas với bảng Lớp).

> Đây không phải việc tuỳ chọn: `ModuleRegistryTest::test_every_studio_api_route_is_claimed_by_a_module` **đỏ ngay**
> khi thêm route mà quên khai — nhờ vậy một endpoint không thuộc module nào (⇒ công tắc gói không chặn được) không
> thể lọt vào sản phẩm.

### 12.3 Ràng buộc đã giữ

- Hai đường dùng `upload-ref` còn lại **vẫn đúng và không bị đụng tới**: *Gộp layer* và *Tải ảnh nguồn* đều tạo ảnh
  MỚI thật sự, nên tải lên là cần thiết.
- Layer ghép vẫn dùng đúng định dạng PNG như trước (giữ kênh trong suốt) — không đổi chất lượng ảnh.
- Không đổi route/định dạng phản hồi cũ nào; endpoint cũ giữ nguyên.

### 12.4 Đo được

| Đo | Trước | Sau |
|---|---|---|
| Thời gian lưu layer 2K (cùng một ảnh 889 KB) | **85 ms** (kèm tải lên 0,9 MB) | **22 ms** |
| Byte phải tải lên khi ảnh đã ở trên máy chủ | 889.470 B | **0** |
| `id` tạo ra | chuỗi `layer-…` | **số thật** (10) |
| Có trong `/api/library/data` | **false** | **true** |
| Có trong `/api/latest` | **false** | **true** |
| Sau khi tải lại trang | **0** bản ghi | **còn nguyên** (id 10) |
| Layer ghép 2400×2400 | 4,4 MB tải lên | vẫn tải lên (buộc phải thế) nhưng **ghi bản ghi thật** (id 11) |

- `vendor/bin/phpunit`: **666 test / 4115 khẳng định — XANH** (thêm 8 test cho đường lưu layer).
- `npm run build`: thoát 0.
- Kiểm bằng Chrome thật: đường nhanh **22 ms**, vào Thư viện + Outputs, **còn sau khi tải lại**; layer ghép ghi được
  bản ghi thật với file nằm đúng thư mục riêng của người dùng.
- Dọn dp: dev trở lại **5 generation / 0 layer / 0 liên kết**. Ảnh nguồn có sẵn **không** bị xoá (đường nhanh dùng
  lại file cũ nên việc dọn phải phân biệt file do thao tác lưu tạo ra với ảnh gốc của người dùng).

### 12.5 Bài học tự bắt được trong đợt này

- **“Báo thành công” mà không ghi gì là lỗi nặng hơn “báo lỗi”.** Người dùng tin là đã lưu, đóng tab, và mất ảnh.
  Đây là lần thứ hai trong hai đợt liên tiếp gốc rễ nằm ở chỗ *thao tác trông như thành công nhưng không có dòng
  nào trong CSDL* — nên quy tắc rút ra: **mọi nút “Lưu” phải kết thúc bằng một dòng CSDL, hoặc một lỗi rõ ràng.**
- **Nghi ngờ con số “chậm” bằng cách đo byte, không đo cảm giác.** 85 ms trên localhost nghe rất nhanh — nhưng đó
  là 0,9 MB đi qua vòng lặp của bộ. Điều đáng đo không phải mili-giây mà là **số byte phải đi qua mạng**: nó mới là
  thứ quyết định trải nghiệm thật, và ở đây nó bằng 0 thay vì 0,9–4,4 MB.
- **Rào chắn của dự án đã bắt lỗi thay tôi.** Thêm route mới mà quên khai module ⇒ test đỏ ngay lập tức. Giữ những
  bài test kiểu này đáng giá hơn nhiều bài test chỉ kiểm tra đúng thứ vừa viết.

---

## 13. Đợt 9 — Dock trái & dock Outputs CO/GIÃN ĐƯỢC + cơ sở chuyển động dùng chung (2026-09-20)

### 13.1 Vấn đề — đo được, không suy đoán

| Sự thật | Bằng chứng |
|---|---|
| Bề rộng dock là HẰNG SỐ trong class Tailwind | `StudioApp.vue` cũ: `w-72` (bảng trái) và `w-[156px]` (Outputs) — người dùng không tự chỉnh được |
| Ẩn/hiện bằng `v-if` ⇒ GỠ khỏi DOM | cùng hai thẻ `<aside v-if="store.leftPanelOpen">` / `v-if="store.outputDockOpen"` |
| Mỗi dock một kiểu | dock Outputs không có nút ẩn trong panel, bảng trái có chevron — hai hành vi khác nhau cho cùng một việc |
| Không dock nào có đường BÀN PHÍM | không có `role="separator"` hay `tabindex` nào trong `resources/js/studio` |
| Chuyển động rải rác, mỗi chỗ một số | `0.15s ease` viết cứng trong `app.css`, `transition-all duration-300` trong component, không nơi nào dùng chung một nhịp |

Hệ quả thật: ảnh thumbnail trong Outputs bị ép vào một con số do lập trình viên chọn hộ; ẩn rồi mở lại bảng trái là
mất trạng thái card (ô đang gõ, vị trí cuộn) vì card bị dựng lại; và người dùng bật "giảm chuyển động" của hệ điều
hành vẫn nhận đủ hiệu ứng.

### 13.2 Đã làm — ba tầng, một nguồn

1. **Cơ sở chuyển động trong `app.css`**: token `--motion-dur-{instant,fast,base,slow,dock}` +
   `--motion-ease-{standard,emphasized,exit}`; lớp dùng lại `.motion-ui` (chỉ khai báo đúng bộ thuộc tính hay đổi —
   KHÔNG dùng `transition-all` vì nó kéo theo cả `width/height` gây giật bố cục) và `.motion-{fade,pop,rise,slide-in-*}-in`.
   Bật `prefers-reduced-motion` ⇒ MỌI token về `0ms`: cả app tắt chuyển động bằng một công tắc, không phải đi tìm
   từng chỗ.
2. **`composables/useDockResize.js` + `components/DockResizer.vue`**: một bộ điều khiển + một vách ngăn dùng CHUNG
   cho mọi dock — kéo bằng pointer (có bắt pointer nên kéo lệch khỏi vách 7px vẫn dính), bàn phím (←/→, Shift = bước
   lớn, Home/End, Enter ẩn/hiện, Esc về mặc định), nhấp đúp = mặc định, kẹp `[min, max]` với trần mềm theo bề rộng
   cửa sổ. Bề rộng là **writable ref trỏ vào store** ⇒ store vẫn là nguồn sự thật duy nhất, và chỉ ghi khi thả tay.
3. **Ẩn mà không gỡ khỏi DOM**: dock thu bề rộng về 0 kèm `data-collapsed` (CSS lo hiệu ứng), nên card bên trong giữ
   nguyên trạng thái và canvas nở ra theo từng frame. `visibility` trễ đúng bằng thời lượng để nội dung không biến
   mất trước khi khung co xong; `:inert` để Tab không chui vào vùng vô hình.

### 13.3 Ràng buộc đã giữ

- **Mặc định không đổi**: 288px và 156px — vào trang lần đầu bố cục y hệt trước.
- Cờ ẩn/hiện VẪN là `leftPanelOpen` / `outputDockOpen` cũ: activity bar, bảng lệnh (Ctrl+K) và nút chevron vẫn điều
  khiển đúng dock đó, không sinh trạng thái thứ hai.
- Không thêm endpoint, không đụng luồng tạo ảnh; bề rộng đi cùng khoá `fabrikai.bar` đã có (không thêm khoá
  localStorage mới).
- Vách ngăn chỉ có ở desktop; dưới `lg` dock vẫn là ngăn kéo trượt như trước (nay có thêm hiệu ứng vào).

### 13.4 Đo được (Chrome thật, viewport 1600×1000, đăng nhập owner)

| Đo | Trước | Sau |
|---|---|---|
| Bề rộng bảng trái | cố định **288px** | kéo được **200–560px**; đo thật: kéo +140px ⇒ **428px** đúng từng pixel |
| Bề rộng dock Outputs | cố định **156px** | kéo được **140–420px**; đo thật: kéo +100px ⇒ **256px** |
| Trần mềm theo cửa sổ | — | cửa sổ 1600px ⇒ `max=560`; kéo +2000px vẫn **dừng ở 560** |
| Bàn phím trên vách ngăn | không có | 2× → = **+32px** (`aria-valuenow` 528), Home/End, Enter, Esc |
| Ẩn/hiện | `v-if`: 0 hiệu ứng, mất trạng thái card | thu bề rộng về 0 trong **260ms**; canvas **1006 → 1301px** |
| Nhớ bề rộng sau khi tải lại | không có | `fabrikai.bar` = `leftDockWidth:288 · outputDockWidth:256` ⇒ tải lại **giữ đúng** |
| Focus sau khi ẩn bằng bàn phím | rơi về `<body>` (không còn điểm dừng) | về **nút mở lại** (`[data-activity]` / `[data-dock-toggle=outputs]`); Enter lần nữa là mở lại |
| `prefers-reduced-motion: reduce` | hiệu ứng vẫn chạy | token = **0ms**, thu gọn **tức thì** (< 80ms) |
| Trong lúc kéo | — | `data-resizing=true` + tắt transition ⇒ dock theo tay **tức thì** |
| CSS thật của dock | — | `transition-duration: 0.26s` · `min-width: 0` · vách ngăn `cursor: col-resize` |

- `vendor/bin/phpunit`: **676 test / 4343 khẳng định — XANH** (thêm 10 test cho dock: cơ sở dùng chung · ARIA · không
  còn bề rộng CỨNG · vẫn nằm trong DOM khi ẩn · luật CSS bắt buộc (`min-width:0`) · token không lệch với JS · công tắc
  giảm chuyển động · lưu bền · **bundle đã build có chứa cơ sở này** · không bỏ rơi focus).
- `npm run build`: thoát 0; CSS/JS trong `public_html/build` đã cập nhật theo nguồn.

### 13.5 Bài học tự bắt được trong đợt này

- **"Ẩn" bằng `v-if` là mất hai thứ cùng lúc: hiệu ứng và trạng thái.** Thu bề rộng về 0 vừa cho hiệu ứng mượt vừa
  giữ nguyên card bên trong — nhưng kéo theo một cái bẫy CSS: flex item mặc định `min-width: auto` nên KHÔNG co được
  xuống 0. Thiếu `min-width: 0` thì "co dock" trông như bị đứng, và test bất biến đã khoá đúng dòng đó lại.
- **Kiểm bằng trình duyệt thật mới lộ lỗi focus.** Bấm Enter để ẩn dock làm vách ngăn bị gỡ khỏi DOM và nút chevron
  nằm trong panel vừa `inert` ⇒ trình duyệt đẩy focus về `<body>`; người dùng bàn phím không còn điểm dừng nào để mở
  lại. Không bài test tĩnh nào nhìn ra việc này — và bản vá đầu tiên của tôi còn SAI: đọc `document.activeElement`
  lúc đã quá muộn (DOM patch xong rồi). Phải chụp focus trong watcher (chạy TRƯỚC khi patch) rồi mới chuyển ở `nextTick`.
- **Một nguồn số cho chuyển động, kể cả khi JS cần biết thời lượng.** Thay vì chép `260` vào JS, `motion.js` đọc lại
  đúng biến CSS `--motion-dur-dock`; test đối chiếu bảng dự phòng trong JS với token trong CSS để hai bên không trôi
  khỏi nhau.

---

## 14. Đợt 10 — Áp dụng chuyển động SÂU RỘNG + tay cầm icon cho vách ngăn kéo (2026-09-20)

### 14.1 Vấn đề — đo được, không suy đoán

| Sự thật | Bằng chứng (đo trên mã nguồn trước đợt này) |
|---|---|
| Token mới chỉ phủ phần NHỎ của giao diện | **199 chỗ** dùng tiện ích `transition*` trong class (124 `transition` · 59 `transition-colors` · 13 `transition-all` · 2 `transition-opacity` · 1 `transition-transform`) đi theo mặc định của Tailwind: **150ms + cubic-bezier(.4,0,.2,1) cứng**, không theo token và **không tắt** khi người dùng bật "giảm chuyển động" |
| Còn số ms viết tay | 11 chỗ trong `<style>` của .vue (`.fade-enter-active 0.2s` · `.cf-* .18s` · `genflow 0.35s/0.4s`) + 6 chỗ trong app.css/js (`duration-150/200/300/500` · `transition: background .2s` · `reveal 0.6s`) |
| Có hover mà KHÔNG có chuyển động | 6 chỗ: 3 `<tr>` bảng admin, 1 `<tr>` danh sách dự án, 2 nút xoá (slot ảnh · preset) |
| Vách ngăn kéo không có chỉ báo | chỉ 1 đường kẻ 1px — người chưa biết không có cách nào đoán chỗ đó kéo được |
| Hiệu ứng vòng lặp không tắt theo token | `animate-pulse` · `shimmer` · `dotBlink`… chạy mãi kể cả khi bật giảm chuyển động |

### 14.2 Đã làm — bốn tầng, vẫn MỘT nguồn số

1. **Một dòng cho toàn app**: `@theme { --default-transition-duration: var(--motion-dur-fast);
   --default-transition-timing-function: var(--motion-ease-standard) }`. Tailwind v4 phát ra
   `.transition{transition-duration:var(--tw-duration,var(--default-transition-duration))}`, nên ghi đè
   hai biến này là **199 chỗ** tự chạy theo token — và tự về 0ms khi bật giảm chuyển động.
2. **Thời lượng thành tiện ích theo TÊN NGHĨA**: nhờ namespace `--transition-duration-*` của Tailwind v4,
   nay viết được `duration-fast · duration-base · duration-slow · duration-dock` (và `ease-standard ·
   ease-emphasized · ease-exit`) — đọc là biết nhịp, không ai phải nhớ con số, tất cả trỏ về token.
3. **`.motion-ui` khớp ĐÚNG bộ thuộc tính Tailwind v4** — thêm `translate · scale · rotate` (v4 dùng
   thuộc tính riêng, không gộp vào `transform`) cùng `outline-color · text-decoration-color ·
   backdrop-filter`; thêm `.motion-ui--size` cho chỗ CẦN chuyển động kích thước (thanh tiến trình) và
   `.motion-row` cho hàng bảng. 6 chỗ hở đã bịt.
4. **Tay cầm cho vách ngăn**: tay cầm 14×30px có icon grip nằm giữa vách ngăn 7px, **hiện sẵn ở mức mờ
   0.45** ngay khi tải trang (chỉ hiện khi hover thì người mới không bao giờ thấy nó), sáng rõ + nở ra
   khi trỏ vào · focus bàn phím · đang kéo; đường kẻ dày 1px → 3px màu thương hiệu.
5. **Công tắc giảm chuyển động chặn cả vòng lặp**: `animation-duration: 1ms` + `animation-iteration-count: 1`
   cho mọi phần tử (giữ 1 vòng để trạng thái cuối của keyframes vẫn được áp — nếu không, vài chỉ báo có
   thể trở nên vô hình).

### 14.3 Ràng buộc đã giữ

- **Nhịp cũ không đổi**: mặc định của Tailwind vốn đã là 150ms = `--motion-dur-fast`, nên 199 chỗ kia giữ
  nguyên thời lượng, chỉ đổi đường cong sang token.
- Không đổi cấu trúc DOM nào ngoài **một span trang trí** (`aria-hidden`) trong vách ngăn; tên/giá trị
  vẫn nằm trên `role="separator"`.
- Kéo từ chính tay cầm **không thêm đường kéo thứ hai**: pointerdown vẫn bắt ở vách ngăn
  (`e.currentTarget`) nên không sinh hai nguồn sự thật cho cùng một thao tác.
- Hiệu ứng cuộn `v-reveal` (0.6s) giữ nguyên nhịp — chỉ chuyển thành token `--motion-dur-reveal`.
- Không thêm endpoint, không đụng luồng tạo ảnh.

### 14.4 Đo được (Chrome thật 1600×1000 + phpunit)

| Đo | Trước | Sau |
|---|---|---|
| Tiện ích `transition*` theo token | `0.15s` + `cubic-bezier(0.4, 0, 0.2, 1)` (cứng) | `0.15s` + `cubic-bezier(0.2, 0.8, 0.2, 1)` (token) |
| Số ms viết tay trong transition | 17 chỗ (11 trong `<style>` · 6 trong css/js) | **0** |
| Bề mặt hover không có chuyển động | 6 | **0** |
| Tay cầm trên vách ngăn | không có (chỉ kẻ 1px) | **14×30px**, icon grip; `opacity 0.45` → **1.0** khi hover, `scale` 1.12, viền `rgb(85,155,120)` + quầng sáng 4px |
| Kéo TỪ CHÍNH tay cầm | — | 288 → **408px** khi kéo +120px (`data-resizing=true`, tay cầm đổi nền brand-600) |
| Bàn phím trên vách ngăn | — | `ArrowLeft` ⇒ 408 → **392px**, `aria-valuenow`=392, tay cầm vẫn sáng rõ khi focus |
| Ảnh chụp tay cầm (phân tích điểm ảnh) | — | nghỉ: icon mờ hai cột điểm; hover: **1.954** điểm sáng + **10.801** điểm xanh; đang kéo: **31.500** điểm xanh (nền brand-600) |
| Giảm chuyển động | tiện ích `transition*` vẫn chạy 150ms | `--default-transition-duration` = **0ms**; mọi transition = 1ms; vòng lặp = 1 vòng; thu gọn dock tức thì |
| `vendor/bin/phpunit` | 676 test / 4343 khẳng định | **683 test / 4462 khẳng định — XANH** (thêm 7 test bất biến) |
| `npm run build` | — | thoát 0; bundle có `.duration-base`, `--default-transition-duration:var(--motion-dur-fast)`, `.dock-resizer__knob`, `gripVertical` |

### 14.5 Bài học tự bắt được trong đợt này

- **"Đã có token" KHÁC "cả app theo token".** Đợt trước tôi tưởng xong vì đã có token + vài class, nhưng
  199 chỗ vẫn đi đường riêng của Tailwind. Chỗ nối hoá ra chỉ là **hai biến theme** — đáng ra phải tìm
  chỗ nối đó TRƯỚC khi viết class dùng chung.
- **Tailwind v4 dùng `translate/scale/rotate` làm thuộc tính RIÊNG.** `transition-property` thiếu chúng
  thì hiệu ứng "nhấc thẻ khi hover" đứng im mà **không có lỗi nào** — chỉ nhìn mới biết. Đây là loại lỗi
  im lặng, nên test bất biến phải khoá danh sách thuộc tính lại.
- **Ảnh chụp màn hình đen thui chưa chắc là app hỏng.** Ba ảnh của tôi giống hệt nhau vì script đăng nhập
  **không await** promise nên trang vẫn ở trạng thái "chưa đăng nhập" và bị lớp phủ `z-[98]` che. Bài học:
  kiểm tra TRẠNG THÁI trang trước khi chụp, và khi không xem được ảnh thì phân tích điểm ảnh (lưới độ
  sáng) là cách đọc ảnh bằng số.

---

## 15. Đợt 11 — XÓA đối tượng đang chọn · nền canvas CHÍNH XÁC · bật/tắt layer có hiệu ứng (2026-09-20)

### 15.1 Vấn đề — đo được, không suy đoán

| Sự thật | Bằng chứng (đo trên mã nguồn trước đợt này) |
|---|---|
| **Bấm Delete / nút thùng rác KHÔNG có gì xảy ra** | `deleteSelection()` đặt `confirmDeleteOpen = true`, nhưng **không có popup nào render cờ đó**: grep `confirmDeleteOpen` toàn repo chỉ ra state + 2 chỗ chặn phím + nơi bật cờ. Tệ hơn: cờ treo lại và `onLayerKeys` `return` sớm ⇒ **mất luôn phím tắt layer** cho tới khi tải lại trang |
| Xóa theo lựa chọn **bỏ qua layer KHÓA** | `confirmDeleteSelection()` lọc theo `ids` không hề kiểm `locked`, trong khi nút xóa ở bảng Lớp thì `disabled` và `deleteLayer()` thì từ chối — hai đường, hai luật |
| Nền canvas "lưới" **trong suốt** | `bgClass` trỏ tới class bàn cờ mà **không hề có định nghĩa nào** trong toàn bộ CSS ⇒ bấm nút "lưới" trông như nút hỏng |
| Ô màu nền **lệch màu thật của canvas** | ô "tối" một sắc, canvas `ink-950` một sắc khác; ô "kem" một sắc, canvas `cream-100` một sắc khác — vì ô màu tự vẽ bằng inline style |
| Nút **tải ảnh đang chọn** trùng chức năng | đã có *Xuất PNG* ở bảng Lớp và nút tải ở Kết quả/Thư viện |
| Bật/tắt layer **biến mất tức thì** | layer ẩn bị gỡ khỏi `visibleLayers` nên phần tử rời DOM ngay: bấm mắt xong không thấy vừa tắt cái gì |

### 15.2 Đã làm

1. **`ConfirmDialog.vue` — popup xác nhận DÙNG CHUNG**, kế thừa đúng hình dáng popup "⚠️ Dọn toàn bộ canvas?"
   (nền đen mờ · khung `max-w-xs` · viền đỏ khi nguy hiểm · hai nút [hành động | Hủy]). Kèm trợ năng mà các
   popup chép tay trước đây không có: **Esc** để hủy (bắt ở pha capture để không chạy tiếp phím tắt của app),
   bấm nền để hủy, **focus vào nút hành động khi mở và TRẢ focus về chỗ cũ khi đóng**.
2. **Ba nơi dùng nó**: dọn toàn bộ canvas · xóa nền AI · **XÓA ĐỐI TƯỢNG ĐANG CHỌN** (mới). Popup xóa đặt ở
   shell chứ không trong bảng Lớp — vì bảng Lớp có thể đang bị thu gọn mà phím Delete vẫn phải chạy.
3. **Xóa nói RÕ sắp xóa gì**: `selectionUnitLabels` (group = 1 đối tượng, hiện tên) + `lockedSelectionCount`
   (báo trước "N đối tượng đang KHÓA sẽ được giữ lại"). `confirmDeleteSelection()` **tôn trọng khóa** và
   **hạ cờ ở MỌI nhánh** (không có gì để xóa · toàn bộ bị khóa · xóa xong) ⇒ không còn trạng thái treo.
4. **Nền canvas MỘT NGUỒN**: bốn class `.canvas-bg-{grid,dark,white,cream}` trong `app.css`, dùng cho **cả**
   vùng canvas **và** ô màu ở thanh trạng thái (bỏ hẳn inline style). Ô màu thêm nhãn tiếng Việt + `aria-pressed`.
5. **Gỡ nút tải ảnh đang chọn** ở thanh trạng thái, kèm hàm `downloadActive()` đã thành mã chết.
6. **Bật/tắt layer có hiệu ứng**: `TransitionGroup` cho danh sách layer + lớp `.layer-vis-*` theo token
   (mờ dần + thu nhẹ 0.94). Kỹ thuật đáng nhớ: hiệu ứng **opacity phải đặt lên `<img>` bên trong** thẻ layer,
   vì chính thẻ đó mang opacity riêng của từng layer bằng inline style; còn `scale` đặt lên thẻ (Tailwind v4
   dùng thuộc tính riêng nên không đụng `transform` inline). Hàng trong bảng Lớp cũng mờ dần theo.

### 15.3 Ràng buộc đã giữ

- Đường tải ảnh KHÁC vẫn nguyên: *Xuất PNG* (bảng Lớp) và nút tải layer đang chọn trên thanh công cụ.
- Popup dọn canvas giữ nguyên câu hỏi và nhãn nút — chỉ đổi chỗ khai báo sang component dùng chung.
- Mọi hiệu ứng mới đều dùng token chuyển động ⇒ tự tắt khi người dùng bật "giảm chuyển động".
- Không thêm endpoint, không đụng CSDL, không đổi định dạng dữ liệu nào.

### 15.4 Đo được (Chrome thật 1600×1000 + phpunit)

| Đo | Trước | Sau |
|---|---|---|
| Bấm Delete khi chọn 1 đối tượng | **không có gì xảy ra** (cờ treo, chặn phím tắt layer) | popup **"⚠️ Xóa 1 đối tượng?"** + tên đối tượng; Esc/Hủy ⇒ giữ nguyên 2 lớp; Xóa ⇒ còn **1 lớp** + toast "Đã xóa 1 đối tượng." |
| Layer đang KHÓA | bị xóa (đường lựa chọn bỏ qua khóa) | popup báo trước "1 đối tượng đang KHÓA sẽ được giữ lại"; xác nhận ⇒ **giữ nguyên 1 lớp** + toast "Đối tượng đang KHÓA — mở khóa rồi mới xóa được." |
| Nền canvas vs ô màu | ô "lưới" ⇒ nền **trong suốt**; ô "tối"/"kem" lệch màu | cả 4 nền **khớp từng ký tự** computed style: grid `repeating-conic-gradient` · dark `rgb(14,13,9)` · white `rgb(255,255,255)` · cream `rgb(244,242,236)` |
| `aria-pressed` trên ô màu | không có | ô đang chọn = `true` |
| Nút tải ảnh đang chọn ở status bar | có | **không còn** (danh sách aria-label đã hết mục này) |
| Bật/tắt layer | biến mất tức thì | lớp `layer-vis-leave-active+leave-to`, `transition-duration` **0.22s** (thẻ + `<img>`); giữa hiệu ứng: `scale` 0.966 · `opacity` 0.435; sau 700ms phần tử rời canvas; bật lại ⇒ `opacity` 0.306 → **1.0** |
| `vendor/bin/phpunit` | 683 test / 4461 khẳng định | **689 test / 4539 khẳng định — XANH** (thêm 6 test bất biến) |
| `npm run build` | — | thoát 0; build lặp lại cho **đúng hash cũ** (tất định) |

### 15.5 Bài học tự bắt được trong đợt này

- **"Đặt cờ rồi quên render" là loại lỗi âm thầm tệ nhất.** Không có exception, không có log — chỉ là bấm nút
  không thấy gì. Nặng hơn: cờ đó còn được dùng làm điều kiện CHẶN phím tắt, nên nó biến một nút hỏng thành
  *cả bàn phím hỏng*. Từ nay quy tắc: **mỗi cờ mở modal phải có một test bất biến "cờ này có nơi render"**.
- **Class CSS không tồn tại thì không ai báo lỗi.** Tên class bàn cờ nằm trong template từ lâu mà không có
  định nghĩa nào trong CSS — trình duyệt im lặng bỏ qua. Test quét class phải **đối chiếu với CSS**, không chỉ
  kiểm sự có mặt của chuỗi trong template.
- **Sao chép màu là tạo nguồn lệch thứ hai.** Ô swatch tự vẽ màu bằng inline style nên chỉ cần một lần đổi token
  là lệch ngay. Cách sửa rẻ và bền: cho cả hai dùng **cùng một class**.
- **Đo computed style phải đợi DOM cập nhật.** Lần đo đầu của tôi cho ra "canvas trễ một nhịp" — thực ra là do
  đọc ngay sau `.click()` khi Vue chưa patch xong. Bài học: kết quả đo bất thường thì nghi cách ĐO trước khi
  nghi sản phẩm (đúng vệt với vụ ảnh chụp đen thui ở mục 14).

---

## 16. Đợt 12 — hiệu ứng bật/tắt layer cho ĐÚNG · gỡ "Xóa nền AI" · nút hành động luôn hiện · "Lưu Output" biết ảnh mới (2026-09-20)

### 16.1 Vấn đề — đo được, không suy đoán

| Sự thật | Bằng chứng (đo trên mã nguồn trước đợt này) |
|---|---|
| Hiệu ứng bật/tắt layer **chỉ mờ mỗi ảnh** | Lớp hiệu ứng đặt lên thẻ layer, nhưng opacity của thẻ do từng layer quy định (inline style) ⇒ CSS thua inline, nên chỉ `<img>` mờ được: **viền chọn, tay cầm kéo, nhãn nhóm đứng nguyên tới lúc phần tử bị gỡ** — đúng cảm giác "hiệu ứng chưa đúng" |
| Nút **"Xóa nền AI · 1 credit"** vẫn nằm đó | còn nguyên cả dây chuyền: UI + popup + `store.removeBackground()` + route `POST /api/remove-bg` + `StudioController::removeBackground()` + `buildBackgroundMask()` + khai endpoint trong `ModuleRegistry` |
| 3 nút hành động **chỉ hiện khi hover** | hai nhóm nút (hàng nhóm + hàng layer) đều `opacity-0` + `group-hover:opacity-100` ⇒ người mới không biết là có, và **trên thiết bị cảm ứng gần như không bấm được** (không có hover) |
| Nút "Lưu Output" **không phân biệt ảnh mới với ảnh đã lưu** | bấm nhiều lần cho cùng một ảnh ⇒ **Outputs đầy bản trùng**; không có chỗ nào so ảnh đang chọn với danh sách Output |

### 16.2 Đã làm

1. **Bật/tắt layer cho ĐÚNG**: layer ẩn **không bị gỡ khỏi DOM** nữa mà mờ tại chỗ —
   `opacity: calc(var(--layer-opacity, 1) * var(--layer-vis))`. Độ mờ riêng của layer nay đi qua **biến CSS**
   (JS đặt `--layer-opacity`) nên CSS **nhân** được với hệ số ẩn/hiện, và hiệu ứng phủ **cả thẻ layer**:
   ảnh + viền chọn + tay cầm kéo + nhãn nhóm mờ cùng nhau. Layer ẩn thêm `pointer-events: none` (bấm xuyên
   qua như khi không có layer) và `scale: .94`. Giữ trong DOM còn giữ ảnh đã nạp ⇒ bật lại không nháy.
2. **Gỡ trọn tính năng "Xóa nền AI"**: nút · state cục bộ · popup xác nhận · action trong store · route ·
   `StudioController::removeBackground()` + `buildBackgroundMask()` (chỉ nó dùng) · khai endpoint trong
   `ModuleRegistry`. **Giữ** nhánh hậu kỳ theo METADATA trong `RenderImageJob` (generation đã xếp hàng trước
   lúc gỡ vẫn cần nó) — và ghi rõ lý do ngay tại chỗ để người sau không tưởng là mã chết.
3. **Ba nút (khóa · nhân đôi · gỡ khỏi canvas) LUÔN hiện** ở cả hàng nhóm lẫn hàng layer: bỏ `opacity-0` và
   `group-hover:opacity-100`.
4. **"Lưu Output" biết ảnh mới**: getter `activeLayerInOutputs` nhận biết trùng theo **đúng thứ tự danh tính**
   (đã lưu trong phiên → `genId` là ảnh kết quả → đường dẫn `/storage/…` so với `media_url`), và
   `canSaveActiveLayerToOutput`. Nút **sáng màu thương hiệu khi có ảnh mới**, im khi ảnh đã có; hàm lưu
   **chặn ngay từ đầu** (không gọi máy chủ) và **đánh dấu `savedOutputId`** sau khi lưu — vì layer ghép bằng
   data URL không đổi danh tính sau khi lưu, không đánh dấu thì nút vẫn sáng và bấm lại là tạo bản trùng.

### 16.3 Ràng buộc đã giữ

- Composite/xuất ảnh vẫn dựa trên `visibleLayers` ⇒ **layer ẩn vẫn không lọt vào ảnh xuất** (chỉ đổi cách HIỂN THỊ).
- Không đụng dữ liệu: vị trí/scale/xoay/opacity của layer giữ nguyên; không thêm endpoint nào.
- Hiệu ứng dùng token chuyển động ⇒ tự tắt khi người dùng bật "giảm chuyển động".
- Các đường tải ảnh khác (`Xuất PNG` · nút tải layer đang chọn ở thanh công cụ) vẫn nguyên.

### 16.4 Đo được (Chrome thật 1600×1000 + phpunit)

| Đo | Trước | Sau |
|---|---|---|
| Chuỗi opacity khi TẮT layer (mỗi 45ms) | chỉ `<img>` mờ; viền chọn/tay cầm đứng nguyên | `1 → 0.268 → 0.109 → 0.0275 → 0.0035 → 0` và `scale 1 → 0.94` — mượt, dừng đúng ở 0/0.94 |
| Layer ẩn còn trong DOM? | bị gỡ ngay | **còn** (`div.layer-el` = 1), `pointer-events: none` |
| Bấm vào chỗ layer ẩn | (layer đã bị gỡ) | `elementFromPoint` trả về phần tử của canvas ⇒ **không chặn chuột** |
| Chuỗi opacity khi BẬT lại | hiện ra đột ngột | `0 → 0.305 → 0.830 → 0.955 → 0.99 → 1`; kết thúc `scale: none`, `pointer-events: auto` |
| Nút xóa nền AI | có (kèm cả dây chuyền phía sau) | **không còn** ở UI; bảng route không còn tên `remove-bg`; controller không còn 2 phương thức |
| 3 nút hành động lúc KHÔNG hover | `opacity: 0` | **`opacity: 1`**, hiện, 24×24 px (đo cho cả ba: khóa · nhân đôi · gỡ) |
| "Lưu Output" với ảnh mới | `ink-800` (im) | **`rgb(45,111,77)` = brand-600** + chữ trắng + title "Lưu layer đang chọn vào Output" |
| Sau khi lưu | vẫn sáng, bấm lại tạo bản trùng | về `rgb(47,44,35)` (im) + title "Ảnh này đã có trong Output — không lưu trùng"; bấm lần 2 ⇒ toast chặn, **không tạo bản ghi** |
| `vendor/bin/phpunit` | 689 test / 4539 khẳng định | **692 test / 4570 khẳng định — XANH** (9 test bất biến cho khung canvas) |
| `npm run build` | — | thoát 0; build lặp lại cho **đúng hash cũ** (tất định) |

### 16.5 Bài học tự bắt được trong đợt này

- **"Có class hiệu ứng" chưa chắc "hiệu ứng đúng chỗ".** Đợt trước tôi đặt lớp hiệu ứng lên thẻ layer nhưng
  opacity của thẻ bị inline style chi phối, nên thực tế chỉ mỗi ảnh mờ — mắt thấy "chưa đúng" mà test của tôi
  vẫn xanh vì nó chỉ kiểm *có class* và *có transition-duration*. Bài học: test phải kiểm **phần tử nào đổi
  thuộc tính nào**, và khi một thuộc tính vừa do inline style vừa do CSS quản thì phải đưa về **một biến**.
- **Gỡ một tính năng là gỡ cả DÂY CHUYỀN, nhưng phải biết chỗ nào CỐ Ý giữ.** Ở đây route/controller/action/
  module/UI đều gỡ; riêng nhánh hậu kỳ trong job đọc theo metadata thì giữ vì generation đã xếp hàng trước
  đó vẫn cần — và ghi lý do ngay tại chỗ, nếu không người sau sẽ "dọn mã chết" và làm hỏng hàng đợi cũ.
- **Ẩn theo hover là bẫy trên thiết bị cảm ứng.** Ba nút hành động chỉ hiện khi hover: trên máy tính bảng/
  điện thoại không có hover ⇒ không có cách nào bấm. "Gọn mắt" không đáng đánh đổi khả năng dùng được.
- **Chống trùng phải dựa trên DANH TÍNH, và danh tính có thể KHÔNG đổi sau khi lưu.** Với layer ghép (data URL),
  lưu xong ảnh vẫn là chính nó nên so sánh kiểu "có trong danh sách chưa" luôn trả "chưa" ⇒ phải đánh dấu
  ngay trên layer vừa lưu.

---

## 17. Đợt 13 — TAY CẦM CHỈNH KÍCH CỠ luôn bấm được · hiệu ứng bật/tắt layer hết "che" và hết trễ (2026-09-20)

### 17.1 Vấn đề — đo được, không suy đoán

| Sự thật | Bằng chứng (đo trên máy, không suy luận) |
|---|---|
| **Tay cầm bị CẮT mất** | Tay cầm là CON của thẻ layer, mà vùng canvas có `overflow:hidden` ⇒ layer phóng to / kéo ra mép / zoom lên là góc layer rơi ra ngoài vùng nhìn thấy và tay cầm biến mất. Đo tại tâm tay cầm: `elementFromPoint` trả về **`DIV.relative z-30 …` = THANH TRẠNG THÁI** (không phải tay cầm) ⇒ bấm/kéo không có gì xảy ra |
| **Layer KHÓA là tay cầm BIẾN MẤT** | Điều kiện render là `l.id === activeLayerId && !l.locked` ⇒ khóa layer xong không còn tay cầm nào và không có lời giải thích nào |
| **Ẩn layer là mất tay cầm** | `toggleLayerVisible` chuyển "đang chọn" sang layer KHÁC khi ẩn layer đang chọn; bật lại layer cũ thì nó vẫn không phải layer đang chọn ⇒ vẫn không có tay cầm |
| **Hiệu ứng mờ bị màn hình trống che** | Ẩn layer CUỐI cùng thì `CanvasEmptyState` hiện ngay ở **z-20**, còn layer đang mờ ở **z-1** ⇒ người dùng không thấy hiệu ứng, chỉ thấy màn hình đổi phắt |
| **Thanh trượt Độ mờ bị TRỄ 220ms** | Đợt trước tôi gộp độ mờ riêng của layer và hệ số ẩn/hiện vào CÙNG thuộc tính `opacity` rồi transition ⇒ kéo thanh trượt Độ mờ bị "dính tay" (hồi quy do chính đợt trước) |

### 17.2 Đã làm

1. **Tay cầm chuyển sang LỚP PHỦ theo toạ độ màn hình và được KẸP vào trong khung**
   (`activeHandles` trong `StudioApp.vue`): vị trí tính từ số liệu store (x · y · scale · rotation ·
   baseW/baseH · zoom · pan) nên vẫn bám đúng **góc đã xoay** của layer, nhưng luôn nằm trong vùng nhìn
   thấy (lề 14px) ⇒ **luôn bấm được**, kể cả khi layer nằm phần lớn ngoài khung. Tay cầm to hơn (18px),
   có quầng tối để nổi trên mọi nền, phóng nhẹ khi trỏ vào (theo token).
2. **Layer KHÓA vẫn có tay cầm** — đổi sang màu hổ phách, chú thích "bấm để mở khóa", và bấm vào là
   **mở khóa luôn** kèm lời nhắc (thay vì biến mất không giải thích).
3. **Kéo chỉnh kích cỡ CHỐT HƯỚNG ngay lúc bấm**: hệ số scale tính bằng **hình chiếu** chuyển động lên
   hướng đã chốt, không dùng thẳng khoảng cách tới tâm. Vì tay cầm có thể bị kẹp (điểm bấm không còn
   nằm đúng góc layer), cách cũ cho ra chiều NGƯỢC: kéo ra xa mà layer nhỏ đi (đo được 1.35 → 0.9).
4. **Ẩn layer GIỮ NGUYÊN layer đang chọn** (`toggleLayerVisible` không còn `setActiveLayer`): bật lại là
   có tay cầm ngay, và không còn cảnh viền chọn/tay cầm "nhảy" sang layer khác giữa lúc mờ.
5. **Màn hình trống xuống `z-0`** (nằm DƯỚI các layer) ⇒ hiệu ứng mờ của layer cuối vẫn nhìn thấy; layer
   ẩn không nhận chuột nên màn hình trống vẫn bấm được bình thường.
6. **Tách hai nguồn độ mờ và chuyển động đúng nguồn**: đăng ký `@property --layer-vis` và cho
   `.layer-el` chuyển động **chính biến này** (không chuyển động thẳng `opacity`) ⇒ bật/tắt layer vẫn
   mượt, mà kéo thanh trượt Độ mờ thì **tức thì**.

### 17.3 Ràng buộc đã giữ

- Toạ độ tay cầm tính thuần bằng số liệu store (không đo DOM trong computed) ⇒ không gây vòng lặp render.
- Tay cầm không chặn canvas: lớp phủ là `pointer-events:none`, chỉ hai tay cầm bật `pointer-events:auto`.
- Chế độ isolate (crop · inpaint · tẩy · vẽ) vẫn không hiện tay cầm layer như trước.
- Composite/xuất ảnh vẫn theo `visibleLayers`; hiệu ứng dùng token nên tắt được bằng công tắc giảm chuyển động.

### 17.4 Đo được (Chrome thật 1600×1000 + phpunit)

| Đo | Trước | Sau |
|---|---|---|
| `elementFromPoint` tại tâm tay cầm | trả về **thanh trạng thái** (tay cầm bị cắt, không bấm được) | trả về **chính tay cầm** (`nhan: true`) ở mọi trạng thái: thường · sau khi phóng to · sau khi kéo layer ra mép · khi khóa |
| Kéo tay cầm +96px | không đổi gì (bấm vào thanh trạng thái) | scale **1 → 2** (đúng chiều); kéo tiếp ra tới trần |
| Kéo tay cầm khi layer KHÓA | (không có tay cầm) | có tay cầm hổ phách `rgb(201,164,95)`; bấm ⇒ toast "Đã mở khóa layer…" và mở khóa thật |
| Kéo tay cầm XOAY | — | xoay **0° → 8°**, sau đó cả hai tay cầm vẫn bấm được |
| Ẩn layer rồi bật lại | layer đang chọn **đổi sang layer khác** ⇒ không có tay cầm | vẫn là **đúng layer đó**, tay cầm còn nguyên (2 tay cầm) |
| Ẩn layer CUỐI (chuỗi 45ms) | màn hình trống z-20 che hết | layer mờ dần `0.267 → 0.070 → 0.016 → 0.0035 → 0` **trên** màn hình trống (empty state `z=0`, layer `z=1..N`), bấm được vào màn hình trống |
| Thanh trượt Độ mờ | trễ 220ms (dính tay) | **0.5 ngay sau 30ms** |
| `vendor/bin/phpunit` | 692 test / 4570 khẳng định | **695 test / 4619 khẳng định — XANH** (12 test bất biến cho khung canvas) |
| `npm run build` | — | thoát 0; build lặp lại cho **đúng hash cũ** |

### 17.5 Bài học tự bắt được trong đợt này

- **`pointer-events` là thuộc tính KẾ THỪA.** Lớp phủ để `pointer-events:none` (cho khỏi chặn canvas) làm
  hai tay cầm con CÂM luôn: vẽ ra đủ, toạ độ đúng, kích thước đúng, mà bấm/kéo không có gì xảy ra. Chỉ
  `elementFromPoint` mới lộ ra — kiểm "có phần tử" và "có kích thước" là KHÔNG đủ.
- **`overflow:hidden` + phần tử con định vị ngoài khung = mất chức năng im lặng.** Tay cầm nằm ngoài vùng
  nhìn thấy thì không có lỗi nào cả, chỉ là người dùng không bao giờ thấy nó.
- **Kẹp vị trí làm sai GIẢ ĐỊNH của thuật toán kéo.** Thuật toán cũ giả định "điểm bấm nằm đúng góc";
  sau khi kẹp, giả định đó sai và kết quả là kéo ra xa mà layer NHỎ đi. Sửa bằng cách chốt HƯỚNG lúc bấm.
- **Hai nguồn dữ liệu khác nhau không được dùng chung một thuộc tính đang chuyển động.** Gộp "độ mờ riêng
  của layer" và "hệ số ẩn/hiện" vào một `opacity` rồi transition ⇒ thanh trượt bị trễ. Tách ra và
  chuyển động đúng nguồn cần chuyển động (`@property` + transition trên chính biến đó).
- **Giữ nguyên "đang chọn" khi ẩn/hiện là quyết định UX, không phải chi tiết kỹ thuật.** Chuyển active đi
  chỗ khác khiến tay cầm "biến mất" theo mắt người dùng — và đó là toàn bộ nội dung khiếu nại.

---

## 18. Đợt 14 — VÌ SAO "VẪN KHÔNG CÓ TAY CẦM": trạng thái kiểm thử của tôi KHÁC trạng thái thật của người dùng (2026-09-20)

### 18.1 Vấn đề — và vì sao vòng trước tôi kết luận SAI

Sau khi deploy đợt 13, khiếu nại y nguyên. Nguyên nhân không nằm ở thứ tôi đã sửa, mà ở chỗ **tôi kiểm thử
trên trạng thái sạch còn người dùng làm việc trên trạng thái CŨ**:

| | Trạng thái tôi kiểm (mỗi lần đều tạo layer MỚI) | Trạng thái thật của người dùng |
|---|---|---|
| `baseW/baseH` | luôn có (do `pushCanvasLayer` đặt) | **null** — layer lưu từ phiên bản TRƯỚC khi có hai trường này |
| Tay cầm chỉnh kích cỡ | hiện đầy đủ ⇒ tôi tưởng đã xong | **KHÔNG hiện tay cầm nào** vì vị trí tay cầm tính theo `baseW/baseH` |

**Tái hiện được 100%**: gieo `localStorage['fabrikai.layers']` hai layer KHÔNG có `baseW/baseH` rồi tải
trang ⇒ canvas hiện đủ 2 layer (ảnh 400×300) nhưng `document.querySelectorAll('.layer-handle').length` = **0**.
Đây đúng là ảnh chụp màn hình người dùng đang thấy: layer có, tay cầm không.

Lưu ý phương pháp: lần đầu tôi gieo dữ liệu NGAY TRÊN trang studio nên bị chính app ghi đè lúc
`beforeunload` (canvas rỗng) ⇒ test ra "0 layer" và suýt dẫn tôi tới kết luận sai nữa. Phải gieo **trước khi
Studio mount** (ở trang đăng nhập) rồi mới điều hướng vào.

### 18.2 Đã làm

1. **Vá kích thước cho layer cũ** — `ensureLayerSizes()`: layer nào thiếu `baseW/baseH` thì đo lại từ chính
   ảnh của nó (cạnh dài tối đa 512 — đúng quy ước hiện có) rồi ghi vào, **không đụng tới vị trí/scale đã lưu**.
   Gọi ngay trong `restoreLayerLayout()` ⇒ layer cũ **TỰ LÀNH** sau một lần tải, người dùng không phải xóa
   rồi thêm lại. Việc này sửa luôn một lỗi im lặng thứ hai: khung logic của layer cũ là **1×1px** nên căn lề ·
   chia đều · "Fit chọn" đều sai.
2. **Đường đo dự phòng từ DOM** cho tay cầm: `layerBaseSize()` lấy `baseW/baseH` → nếu thiếu thì đo
   `img.offsetWidth/offsetHeight` (`offset*` KHÔNG tính transform nên vẫn đúng khi layer đang xoay/phóng to)
   → cuối cùng mới tới `frameLayout`. Thẻ layer có `data-layer-id` để đo đúng ảnh của nó.
3. **Bỏ phụ thuộc `@property`**: hiệu ứng bật/tắt layer nay dùng **hai tầng** — thẻ ngoài `.layer-el` giữ hệ số
   ẩn/hiện và chuyển động **opacity** (thuộc tính chuyển động được ở MỌI trình duyệt), thẻ trong `.layer-body`
   giữ **độ mờ riêng của layer** bằng inline style **không transition**. Nhờ vậy: hiệu ứng chạy cả trên trình
   duyệt không hỗ trợ `@property` (nếu không hỗ trợ thì cách cũ **không chạy hiệu ứng nào**), mà thanh trượt
   Độ mờ vẫn tức thì.

### 18.3 Ràng buộc đã giữ

- Không đổi toạ độ/scale/xoay/độ mờ đã lưu của layer — chỉ ĐO và ghi kích thước còn thiếu.
- Composite/xuất ảnh vẫn theo `visibleLayers`; kéo layer, kéo tay cầm, xoay, khóa đều chạy như đợt 13.

### 18.4 Đo được (Chrome thật 1600×1000 + phpunit)

| Đo | Trước (với layer CŨ) | Sau |
|---|---|---|
| Số tay cầm khi layer thiếu `baseW/baseH` | **0** | **2** (18×18, `elementFromPoint` trả về chính tay cầm) |
| `baseW/baseH` trong localStorage sau khi tải | `null` | **`400x300`** (đo từ chính ảnh của layer) |
| Kéo tay cầm trên layer CŨ | không có gì để kéo | scale **1 → 1.55** |
| Chuỗi mờ khi ẩn (45ms/lần) | — | `0.694 → 0.169 → 0.070 → 0.016 → 0.001 → 0`; khi hiện: `0.306 → 0.831 → 0.955 → 0.984 → 0.999 → 1` |
| Thanh trượt Độ mờ | — | `0.5` ngay sau **30ms** (thẻ `.layer-body`) |
| Canvas MỚI (kiểm hồi quy) | — | 2 layer · 2 `.layer-body` · tay cầm bấm được · kéo tay cầm 1 → **1.3** · kéo layer di chuyển bình thường, tay cầm vẫn bấm được |
| `vendor/bin/phpunit` | 695 test / 4619 khẳng định | **696 test / 4627 khẳng định — XANH** |
| `npm run build` | — | thoát 0; build lặp lại cho **đúng hash cũ** |

### 18.5 Bài học tự bắt được trong đợt này

- **Kiểm thử trên trạng thái SẠCH có thể che đúng cái lỗi người dùng đang gặp.** Mọi lần kiểm trước đây tôi
  đều TẠO layer mới ⇒ dữ liệu luôn đầy đủ ⇒ tay cầm luôn hiện. Chỉ khi gieo **dữ liệu cũ** mới tái hiện được.
  Từ nay với lỗi liên quan dữ liệu người dùng, phải kiểm bằng **dữ liệu cũ / thiếu trường**, không chỉ dữ liệu mới.
- **Trường phái sinh trong bộ nhớ phải có đường TỰ VÁ.** `baseW/baseH` là dữ liệu dẫn xuất được lưu kèm; phiên
  bản trước không có nó ⇒ mọi thứ tính theo nó im lặng sai (tay cầm biến mất, khung logic 1×1px). Dữ liệu dẫn
  xuất nên được tính lại khi thiếu, chứ không phải giả định là luôn có.
- **Dựa vào tính năng nền tảng mới thì phải có đường lùi.** `@property` + transition trên biến đã đăng ký là
  cách hay, nhưng trình duyệt không hỗ trợ thì **không có hiệu ứng nào** và cũng không có lỗi nào. Hai tầng
  (opacity ở thẻ ngoài + độ mờ riêng ở thẻ trong) chạy ở mọi nơi và vẫn giữ được yêu cầu "thanh trượt tức thì".
- **Gieo dữ liệu kiểm thử phải làm TRƯỚC khi app mount.** App có handler `beforeunload` ghi đè localStorage;
  gieo sai thời điểm thì bài test đo chính cái rỗng do mình vừa tạo.

---

## 19. Đợt 15 — AUDIT TOÀN BỘ TRẠNG THÁI: tay cầm lệch khi vùng canvas đổi kích thước · bị ngăn kéo che trên mobile (2026-09-20)

### 19.1 Vì sao phải audit thay vì sửa tiếp theo phỏng đoán

Khiếu nại lặp lại y nguyên sau hai vòng sửa ⇒ cách làm "đoán chỗ hỏng rồi sửa" đã hết tác dụng. Lần này tôi
**duyệt có hệ thống 12 trạng thái** của khung canvas và ghi lại với mỗi trạng thái: có tay cầm không · có bấm
được không · bị phần tử nào che (đo bằng `elementFromPoint`) · layer nào đang chọn.

Kết quả audit (trước khi sửa):

| Trạng thái | Tay cầm | Bấm được |
|---|---|---|
| mặc định desktop 1600 · công cụ Lựa chọn · Di chuyển canvas · Reframe/Crop · zoom 2 bước · layer KHÓA | 2 | ✅ |
| Vẽ tự do (drawMode) · Xóa vùng (eraseMode) | 0 | — (chế độ isolate: chỉ hiện 1 ảnh, **cố ý** không có tay cầm layer) |
| layer đang chọn bị ẨN | 0 | — (cố ý: layer vô hình thì không có tay cầm) |
| **khung nhìn MOBILE 390×844** | 2 | ❌ **bị FOOTER của bảng Lớp che** |

Và khi đo toạ độ ở khung 390px thì lộ ra lỗi thứ hai, nặng hơn: tay cầm có `left: 734px` trong khi
**vùng canvas chỉ rộng 364px** ⇒ nằm ngoài màn hình (`elementFromPoint` trả về `null`).

### 19.2 Hai nguyên nhân

1. **Toạ độ tay cầm bị "đóng băng" theo khung cũ.** `activeHandles` là computed chỉ tính lại khi phụ thuộc
   reactive đổi — mà đổi kích thước vùng canvas (đổi cửa sổ · kéo dock · mở/đóng bảng Lớp · xoay máy) thì
   `zoom`/`pan`/dữ liệu layer KHÔNG đổi ⇒ không có gì kích hoạt tính lại. Phép KẸP vì thế chỉ đúng ở lần
   tính đầu tiên; sau đó toạ độ cũ được giữ nguyên và rơi ra ngoài màn hình.
2. **Kẹp vào vùng canvas chưa đủ** vì còn lớp phủ ĐÈ LÊN canvas: trên mobile bảng Lớp là ngăn kéo (`z-50`)
   phủ nửa phải, thanh công cụ floating nằm ở đáy. Tay cầm kẹp vào đúng chỗ bị che ⇒ có tay cầm mà bấm không được.

### 19.3 Đã làm

1. **Nhịp khung nhìn `viewportTick`**: tăng khi vùng canvas đổi (hàm xử lý `resize` sẵn có) **và** bằng
   `ResizeObserver` trên chính phần tử canvas — vì cửa sổ có thể không đổi mà vùng canvas vẫn đổi (kéo dock,
   bật/tắt bảng Lớp, thanh trạng thái xuống dòng). `activeHandles` phụ thuộc nhịp này nên **luôn tính lại**.
2. **Kẹp vào vùng ĐƯỢC PHÉP = vùng canvas TRỪ các lớp phủ đang che nó**: mỗi lớp phủ tự khai hướng che qua
   `[data-covers-canvas="right|bottom"]` (ngăn kéo bảng Lớp · thanh công cụ floating của mobile); hàm
   `handleClampBox()` bỏ qua lớp phủ đang ẩn và chỉ co trần khi THẬT SỰ giao nhau. Vùng co lại quá nhỏ thì
   lùi về giữa khung để tay cầm vẫn còn chỗ bấm.

### 19.4 Đo được (Chrome thật)

| Khung nhìn | Trước | Sau |
|---|---|---|
| MOBILE 390×844 (bảng Lớp mở) | tay cầm 2 nhưng **bị FOOTER bảng Lớp che** | cả hai tay cầm **trong màn hình** và `elementFromPoint` trả về **chính tay cầm** |
| MOBILE (đóng bảng Lớp) | — | cả hai bấm được |
| DESKTOP 1600×1000 | ✅ | ✅ |
| ĐỔI CỬA SỔ 1024×700 | toạ độ cũ ⇒ lệch/ra ngoài | cả hai **trong màn hình**, bấm được |
| ĐỔI CỬA SỔ 800×600 | — | cả hai **trong màn hình**, bấm được |
| Chuỗi mờ khi ẩn (40ms) | — | `1 → 0.436 → 0.109 → 0.045 → 0.008 → 0.001 → 0`; khi hiện `0 → 0.565 → 0.891 → 0.955 → 0.992` |
| `vendor/bin/phpunit` | 696 test / 4627 khẳng định | **698 test / 4639 khẳng định — XANH** (15 test bất biến cho khung canvas) |

### 19.5 Bài học tự bắt được trong đợt này

- **Computed có ĐO DOM thì phải có phụ thuộc cho mọi thứ nó đo.** Toạ độ tay cầm phụ thuộc kích thước vùng
  canvas, nhưng computed chỉ biết `zoom`/`pan`/dữ liệu layer ⇒ "đóng băng" sau lần tính đầu. Cách sửa đúng
  là thêm một NGUỒN SỰ THẬT cho kích thước (nhịp + ResizeObserver), không phải rải `getBoundingClientRect`
  khắp nơi.
- **Kẹp vào "vùng của tôi" chưa đủ — phải kẹp vào "vùng còn nhìn thấy".** Lớp phủ của chính ứng dụng (ngăn
  kéo, thanh công cụ) cũng che mất điều khiển; muốn biết chỗ nào bấm được thì phải hỏi `elementFromPoint`,
  và các lớp phủ nên TỰ KHAI vùng chúng chiếm.
- **Audit theo trạng thái hiệu quả hơn sửa theo phỏng đoán.** 12 dòng kết quả chỉ ra ngay 2 lỗi mà 3 vòng
  sửa trước không thấy, vì mỗi lần tôi chỉ thử đúng một trạng thái quen thuộc (desktop, layer mới).
- **Một bản deploy KHÔNG cập nhật tab đang mở.** Ứng dụng là SPA: tab đang chạy giữ nguyên JS cũ cho tới khi
  tải lại. Đây là lý do rất có thể khiến người dùng vẫn thấy hành vi cũ sau nhiều lần deploy — cần nói rõ
  "tải lại trang" mỗi lần giao bản sửa, và nên có chỉ báo "có bản mới" trong ứng dụng.

---

## 20. Đợt 16 — GỐC RỄ CUỐI CÙNG: chế độ "CHỈNH 1 LAYER" làm mất tay cầm VÀ làm con mắt như không có tác dụng (2026-09-20)

### 20.1 Cách tìm ra

Sau khi người dùng xác nhận **đã tải lại trang (Ctrl+Shift+R) mà vẫn không thấy tay cầm**, tôi mới đặt câu hỏi
đúng: *có trạng thái nào của ứng dụng mà CẢ HAI khiếu nại cùng đúng không?* Câu trả lời là **chế độ chỉnh 1
layer** — bật bởi một công cụ canvas đang hoạt động. Đo được:

| Trong chế độ "chỉnh 1 layer" (bật công cụ Vẽ tự do / Xóa vùng / vùng chọn inpaint) | Trước |
|---|---|
| Tay cầm chỉnh kích cỡ · xoay | **0 tay cầm** — điều kiện `isolateActive` loại bỏ tay cầm từ đầu |
| Bấm con mắt để ẩn layer đang chọn | Canvas **KHÔNG đổi gì**: khung xem 1 layer dùng `store.upscaleSrc`, mà hàm này có ĐƯỜNG LÙI sang ảnh khác ⇒ thay ảnh ẩn bằng ảnh khác, nhìn như "bật/tắt layer không có tác dụng" |
| Người dùng có biết mình đang ở chế độ đó? | Không — không có nhãn nào nói canvas đang chỉ hiện 1 layer |

Đây là lời giải thích khớp với **cả hai** câu khiếu nại cùng lúc, và khớp với việc chúng lặp lại y nguyên sau
nhiều lần deploy: chỉ cần một công cụ canvas đang bật là mọi bản sửa về tay cầm đều không hiện ra.

### 20.2 Đã làm

1. **Tay cầm hiện ở CẢ HAI chế độ xem.** Chỉ ẩn khi đang Crop/Reframe (khung crop có tay cầm riêng của nó)
   hoặc khi layer đang ẩn (không thấy thì không chỉnh được).
2. **Chế độ 1 layer hiện ĐÚNG ảnh của layer đang chọn** (`:src="store.activeLayer.image"`) thay vì
   `upscaleSrc` có đường lùi ⇒ bấm con mắt là canvas **đổi ngay**, và khi layer đang ẩn thì hiện dòng chữ
   "Layer đang chọn đang bị ẨN — bấm con mắt trong bảng Lớp để hiện lại".
3. **Nhãn CHẾ ĐỘ trên canvas**: "Đang chỉnh 1 layer (Vẽ tự do · Xóa vùng · lasso …) — canvas chỉ hiện layer
   đang chọn" kèm nút **Thoát** một chạm (`store.exitCanvasTools()`). Người dùng không còn phải đoán vì sao
   chỉ thấy một layer, và thoát được ngay tại chỗ.

### 20.3 Đo được (Chrome thật)

| Kiểm | Trước | Sau |
|---|---|---|
| Tay cầm khi đang bật "Vẽ tự do" | 0 | **2**, cả hai `elementFromPoint` trả về chính tay cầm |
| Kéo tay cầm trong chế độ đó | (không có) | scale **1 → 1.25** |
| Bấm con mắt để ẩn layer đang chọn | canvas không đổi (ảnh dự phòng) | **ảnh lớn trên canvas: 1 → 0** + hiện dòng "đang bị ẨN" |
| Nút "Thoát" trên nhãn chế độ | (không có nhãn) | thoát công cụ, nhãn biến mất, **cả 2 layer hiện lại** |
| Bỏ chọn hết (canvas có layer) | tay cầm 0, **không nói gì** | tay cầm 0 **kèm nhãn** "Chưa chọn layer nào — bấm vào một layer (hoặc một hàng trong bảng Lớp) để chỉnh kích cỡ · xoay"; chọn lại ⇒ tay cầm trở về |
| `vendor/bin/phpunit` | 698 test / 4639 khẳng định | **699 test / 4646 khẳng định — XANH** (16 test bất biến cho khung canvas) |

### 20.4 Bài học tự bắt được trong đợt này

- **Khi một khiếu nại lặp lại y nguyên, phải đi tìm TRẠNG THÁI làm cả hai điều cùng sai** — chứ không phải
  sửa sâu hơn cái đã sửa. Ở đây chỉ một câu hỏi đúng ("trạng thái nào khiến cả hai cùng đúng?") là ra gốc rễ,
  sau khi đã tốn ba vòng sửa.
- **Đường LÙI (fallback) của hàm hiển thị có thể che mất hành vi người dùng đang kiểm.** `upscaleSrc` lùi
  sang ảnh khác để "luôn có gì đó để xem" — nhưng chính nó làm thao tác ẩn/hiện layer trở nên VÔ HÌNH. Chỗ
  hiển thị thì phải hiển thị ĐÚNG thứ người dùng vừa thao tác, và nói rõ khi không có gì để hiện.
- **Chế độ ẩn của ứng dụng phải TỰ KHAI.** "Chỉ hiện 1 layer" là chế độ hợp lệ, nhưng không có nhãn thì người
  dùng đọc nó thành "ứng dụng hỏng". Một nhãn + một nút thoát rẻ hơn rất nhiều so với một vòng sửa lỗi.
- **Một lần nữa: deploy không cập nhật tab đang mở.** Ghi lại ở đây để lần sau câu hỏi đầu tiên luôn là
  "đã tải lại trang chưa", trước khi đi sửa mã.

---

## 21. Đợt 17 — BỎ ĐIỀU KIỆN TIÊN QUYẾT: tay cầm chỉnh kích cỡ có ở MỌI layer (2026-09-20)

### 21.1 Vì sao lại phải bỏ điều kiện thay vì giải thích thêm

Sau khi đã sửa ba nguyên nhân đo được (layer cũ thiếu `baseW/baseH` · toạ độ đóng băng khi vùng canvas đổi ·
chế độ "chỉnh 1 layer" không có tay cầm), khiếu nại vẫn lặp lại. Nhìn lại toàn bộ các trạng thái thì thấy
một **điều kiện tiên quyết ngầm** vẫn còn: tay cầm chỉ có cho **layer ĐANG CHỌN**. Mọi trạng thái làm mất
"đang chọn" đều dẫn tới "không có tay cầm":

| Trạng thái | Tay cầm (trước đợt này) |
|---|---|
| Bấm ra vùng trống (bỏ chọn) | **0** |
| Vừa mở trang, chưa chọn layer nào | **0** |
| Đang bật công cụ canvas mà chưa chọn layer | **0** |
| Chọn layer A, muốn chỉnh layer B | phải chọn B trước mới có tay cầm |

Mỗi trạng thái đều "hợp lệ" — nên thay vì thêm nhãn giải thích cho từng cái, cách bền hơn là **bỏ điều kiện**.

### 21.2 Đã làm

1. `layerHandles` (thay `activeHandles`): **MỌI layer đang hiện đều có tay cầm chỉnh kích cỡ**. Layer đang
   chọn có thêm tay cầm XOAY và tay cầm đậm hơn; layer khác mờ hơn (`.layer-handle--other`) nhưng **luôn có**.
2. **Kéo tay cầm của layer chưa chọn = tự chọn layer đó rồi chỉnh luôn** (`startResizeFromHandle`) ⇒ không
   cần chọn trước; tay cầm nào cũng dùng được ngay.
3. Chế độ "chỉnh 1 layer" chỉ giới hạn khi THẬT SỰ có layer đang chọn (lúc đó canvas chỉ hiện layer đó);
   không chọn gì thì canvas hiện dạng nhiều layer nên tay cầm của mọi layer đều đúng chỗ.
4. Bảng Lớp: ảnh thu nhỏ **mờ + mất màu** khi layer bị tắt (theo token) ⇒ bật/tắt layer thấy được ngay
   trong bảng, không chỉ trên canvas.

### 21.3 Đo được (Chrome thật)

| Trạng thái | Tay cầm | Bấm được |
|---|---|---|
| 2 layer, 1 layer đang chọn | 3 (2 của layer đang chọn + 1 mờ của layer kia) | ✅ (tay cầm của layer bị layer trên che thì vẫn chọn được layer đó từ bảng Lớp) |
| **Bỏ chọn hết** | **2** (trước: 0) | ✅ |
| Chế độ 1 layer, chưa chọn layer | **2** (trước: 0) | ✅ |
| Chế độ 1 layer, đã chọn layer | 2 (kích cỡ + xoay) | ✅ — kéo ⇒ scale **1 → 1.25** |
| `vendor/bin/phpunit` | — | **700 test / 4654 khẳng định — XANH** (17 test bất biến cho khung canvas) |

### 21.4 Bài học tự bắt được trong đợt này

- **Điều kiện tiên quyết ngầm là nguồn của "tính năng không tồn tại".** Tay cầm *có* trong mã, *có* hiệu ứng,
  *có* test — nhưng chỉ khi một điều kiện khác đã đúng. Với người dùng, "chỉ hiện khi X" đọc thành "không có".
  Khi một điều khiển bị phàn nàn là "không có", hãy **bỏ điều kiện** trước khi viết thêm hướng dẫn.
- **Sửa theo từng trạng thái không bao giờ đủ nếu còn điều kiện chung.** Ba vòng trước tôi sửa ba trạng thái
  cụ thể; trạng thái thứ tư (chưa chọn layer) vẫn hỏng. Bỏ điều kiện chung thì mọi trạng thái cùng đúng.

---

## 22. Đợt 18 — ĐÚNG THỨ ĐƯỢC YÊU CẦU: **DOCK LAYERS kéo được + có hiệu ứng bật/tắt** · canvas lấy ĐIỂM NGỌT (2026-09-20)

### 22.1 Tôi đã hiểu sai yêu cầu ở đâu

Yêu cầu gốc là **"layer dock"** — bảng Layers ở cạnh phải khung canvas. Tôi lại đọc thành "layer trên canvas"
nên suốt nhiều đợt đã đi sửa tay cầm chỉnh kích cỡ **của layer vẽ trên canvas**, trong khi việc cần làm là:
**bảng Layers phải kéo được bề rộng và phải có hiệu ứng khi bật/tắt**. Đo lại mã nguồn thì đúng như vậy:

| Sự thật trong mã (trước đợt này) | Hệ quả người dùng thấy |
|---|---|
| Bảng Layers có bề rộng HẰNG SỐ `w-64` | **không có tay cầm nào để kéo** bề rộng bảng |
| Dock Layers ẩn bằng `v-if="store.inspectorOpen"` (gỡ khỏi DOM) | **bật/tắt là "giật"**: không có hiệu ứng thu/mở, mất luôn trạng thái bên trong panel |

### 22.2 Đã làm — dock Layers dùng ĐÚNG cơ sở chung của hai dock kia

1. **Preset `DOCK_PRESETS.inspector`** (mặc định 256px = đúng `w-64` cũ ⇒ vào trang không thấy xa lạ;
   min 200 · max 560 · trần mềm 42% bề rộng cửa sổ).
2. **Bề rộng là state của store** (`inspectorWidth`) và **lưu bền** cùng cài đặt thanh trạng thái ⇒ kéo xong
   tải lại trang vẫn đúng bề rộng.
3. **Vách ngăn kéo `<DockResizer controls="dock-inspector">`** ở mép trái bảng: kéo chuột · ←/→ · Enter
   ẩn/hiện · nhấp đúp về mặc định — y hệt hai dock kia (cùng một composable, không chép logic).
4. **Bỏ `v-if`**: dock vẫn nằm trong DOM, ẩn/hiện là **thu bề rộng về 0 có hiệu ứng** (`.dock-panel` +
   `data-collapsed` + `inert` để Tab không vào vùng vô hình). Bảng Layers bỏ luôn `w-64` — bề rộng do dock quy định.

### 22.3 Canvas: lấy ĐIỂM NGỌT giữa các bước (không revert cứng)

Giữ những gì các bước trước đã làm TỐT, bỏ những gì gây ồn:

| Giữ | Bỏ |
|---|---|
| Hiệu ứng MỜ khi bật/tắt layer (mượt, chạy trên mọi trình duyệt, thanh trượt Độ mờ vẫn tức thì) | Tay cầm cho **MỌI** layer cùng lúc (rối mắt) |
| Tay cầm chỉnh kích cỡ **không bị `overflow:hidden` cắt** (lớp phủ, kẹp vào vùng nhìn thấy) | Hai **nhãn chữ** dán trên canvas (chế độ · chưa chọn layer) — chuyển xuống thanh trạng thái |
| Vá kích thước cho layer cũ (baseW/baseH) · tôn trọng ẩn/hiện trong chế độ 1 layer | Đổi nguồn ảnh của chế độ 1 layer (giữ `upscaleSrc` như cũ, chỉ thêm điều kiện ẩn/hiện) |
| Vá lỗi `ensureLayerSizes` cho dữ liệu cũ | — |

Quy tắc tay cầm nay: **layer đang chọn** (đủ kích cỡ + xoay) và **layer đang trỏ vào** (mờ). Trỏ vào layer nào
là thấy tay cầm của layer đó, kéo là layer đó được chọn và chỉnh luôn ⇒ không rối mắt mà vẫn không bao giờ
"không có tay cầm".

### 22.4 Đo được (Chrome thật 1600×1000 + MOBILE 390×844)

| Đo | Trước | Sau |
|---|---|---|
| Bề rộng bảng Layers | **256px cứng** (`w-64`) | kéo được: **256 → 376px**, và `inspectorWidth=376` được lưu vào localStorage |
| Tay cầm của bảng Layers | **không có** | vách ngăn **7×692px** ở mép trái, bấm/kéo được |
| Bật/tắt bảng Layers (bấm nút ở thanh trạng thái) | **giật tức thì** (gỡ khỏi DOM) | chuỗi `bề rộng/độ mờ`: 375/0.99 → 361/0.96 → 330/0.87 → 277/0.73 → 186/0.49 → **0/0**; bật lại: 0 → 292/0.77 → 336/0.89 → 373/0.99 → **376/1** (đúng bề rộng đã nhớ) |
| Sau khi tắt | — | `data-collapsed=true` · `inert` · `visibility:hidden` |
| MOBILE 390×844 | — | dock là ngăn kéo 216px (đúng sàn min+step), **có vách ngăn**, canvas tránh vùng bị che |
| Tay cầm canvas | mọi layer (ồn) | **layer đang chọn** (kích cỡ + xoay) + **layer đang trỏ** (mờ) |
| Độ mờ khi bật/tắt layer | — | `1 → 0.43 → 0.10 → 0.02 → 0.00 → 0` (mượt) |
| Nhắc khi chưa chọn layer / đang bật công cụ | nhãn trên canvas | **thanh trạng thái**: "Chưa chọn layer — bấm vào một layer để chỉnh kích cỡ · xoay" · "Đang VẼ TỰ DO …" |
| Lỗi console | — | không có |
| `vendor/bin/phpunit` | 700 test | **701 test / 4675 khẳng định — XANH** |
| `npm run build` | — | thoát 0; build lặp lại cho **đúng hash cũ** |

### 22.5 Bài học tự bắt được trong đợt này

- **"Layer" trong yêu cầu phải được hỏi lại ngay từ đầu.** Người dùng nói "layer dock" — đó là DOCK, không
  phải layer trên canvas. Tôi đã tiêu nhiều đợt sửa sai đối tượng chỉ vì không hỏi một câu. Quy tắc: khi
  danh từ có thể trỏ vào hai thứ trong cùng một sản phẩm (layer vẽ · bảng Layers), **hỏi trước khi sửa**.
- **Cơ sở chung chỉ có giá trị khi dock THỨ BA dùng nó.** Việc dock Layers nay chỉ cần khai preset + gắn vách
  ngăn (không chép logic kéo/kẹp/lưu/trả focus) là bằng chứng cơ sở ở mục 13–14 đã đúng thiết kế.
- **Sửa quá tay cũng là một loại lỗi.** Các đợt trước tôi thêm dần: tay cầm mọi layer, nhãn trên canvas, đổi
  nguồn ảnh chế độ 1 layer — đều không được yêu cầu và làm không gian làm việc rối hơn. "Điểm ngọt" là giữ
  phần sửa đúng lỗi, bỏ phần trang trí thêm vào.












---

## 23. Tinh chỉnh card «Gợi ý từ ảnh» + HƯỚNG DẪN PHONG CÁCH THIẾT KẾ CHUNG (2026-09-22)

> Đây là đợt đầu tiên có **tài liệu chuẩn** cho giao diện: **`docs/DESIGN_SYSTEM.md`**.
> Từ nay mọi card/panel trong /studio đọc tài liệu đó trước khi thêm hoặc sửa giao diện.

### 23.1 Vấn đề — đo được, không suy đoán

Card «Gợi ý từ ảnh» (586 dòng) đã mọc thêm nhiều lớp qua các đợt trước và vi phạm gần hết quy ước
chung mà đợt 12 (card Studio) vừa chốt:

| # | Đo được | Hệ quả với người mới |
|---|---|---|
| 1 | **2 nút chính to ngang nhau** cùng hiện ("Gợi ý phong cách & prompt" + "Tạo ảnh ngay") | không biết bấm cái nào trước; bấm sai thì ăn toast lỗi |
| 2 | Nút mờ **không nói vì sao** | phải đoán |
| 3 | **2 hệ tiến trình tự chế** (~90 dòng CSS gần trùng nhau) | hai kiểu hiển thị cho cùng một việc |
| 4 | **Emoji trong chrome**: 5 chip chế độ + 1 nút + 8 nhãn đặc điểm | mỗi hệ điều hành vẽ một kiểu, không theo bảng màu, trình đọc màn hình đọc thành tiếng |
| 5 | **40 mã `rgba()` tự khai** trong `<style scoped>` | card lệch hẳn tông xanh của app |
| 6 | Danh sách "Gợi ý gần đây" luôn chiếm chỗ | card dài trước mắt người mới |

### 23.2 Đã làm

- **`SuggestCard.vue` viết lại theo đúng 6 quy tắc trình bày** (nay là §4 của tài liệu chuẩn):
  3 bước có số ①② (Ảnh nguồn · Kiểu gợi ý) + khối **Nâng cao** gấp sẵn; **một** hành động chính;
  nút khoá có dòng lý do `↳`; **0 emoji** (toàn bộ bằng `StudioIcon`); tiến trình dùng
  **`LoadingSpinner`** dùng chung cho cả phân tích lẫn tạo ảnh; "Gợi ý gần đây" gấp trong `<details>`.
  **586 → 455 dòng.**
- **`docs/DESIGN_SYSTEM.md`** (mới): nguồn chân lý về màu/chuyển động/chữ, bảng "cần gì → dùng class
  nào", danh sách component dùng chung, 6 quy tắc cho người mới, quy tắc bố cục & cuộn, trợ năng,
  icon/emoji, và checklist trước khi merge.
- **Test khoá bất biến** `tests/Feature/DesignSystemTest.php` (5 test): không tự chế tiến trình ·
  không emoji · không bảng màu riêng (style ≤ 35 dòng, đúng 1 gradient) · đúng 1 nút chính + có lý do
  khoá · tài liệu phải tồn tại, **mọi component nó bảo dùng phải có thật**, và **số icon ghi trong
  tài liệu phải khớp `icons.json`** (tài liệu không được phép nói sai).

### 23.3 Đo lại bằng Chrome thật (component thật + CSS build thật, 7 trạng thái)

| Trạng thái | Nút chính | Tràn ngang (300px) | Tràn ngang (260px, mở hết `<details>`) | Emoji |
|---|---|---|---|---|
| Chưa có ảnh · Có ảnh · Đã chọn chế độ · Đang phân tích · Có kết quả · Lỗi · Gần đây | **1** ở mọi trạng thái | **không** | **không** | **0** |

Chiều cao card giảm ở mọi trạng thái (riêng trạng thái "có kết quả": 1018 → 941px; "gần đây": 623 → 489px).
Test đã được **thử đột biến**: thêm emoji → ĐỎ · dựng lại tiến trình tự chế → ĐỎ · bỏ lùi nút chính → ĐỎ ·
tự khai mã màu hex → ĐỎ · xoá dòng lý do khoá → ĐỎ.

### 23.4 Còn lại (đề xuất)

- [ ] **Nợ emoji toàn studio**: đo được **23/65 file · 134 lần xuất hiện** (ConceptCard 43 · store.js 18 ·
      AdminApp 7 · StudioApp 6 · …). Không cần đợt riêng: sửa tới file nào dọn file đó.
- [ ] Nhiều card khác vẫn còn **2 nút chính** hoặc **nút khoá không nêu lý do** — nên rà theo checklist §8
      của tài liệu chuẩn khi có dịp sửa.
- [ ] Card «Gợi ý từ ảnh» chưa cho **chọn ảnh nguồn ngay trong card** (phải chọn trên canvas/Thư viện);
      `SourceLibraryPicker` đã có sẵn nên việc này rẻ.
