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
- **Còn lại của đợt 3**: tiến trình theo từng ảnh trong lượt hàng loạt (hiện có % chung), phím tắt, và
  "chạy lại chỉ những mục lỗi".

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
- **Còn lại của đợt 2**: màn **Duyệt theo lô** cho chủ doanh nghiệp (nền `?scope=pending` + transition đã có,
  chưa có màn hình gọn) và **báo cáo chi phí/tiến độ theo bộ sưu tập** (số ảnh · credit đã dùng · ảnh lỗi).

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
| Q1 | **Bật chặn khi hết credit?** (hiện không chặn) | Ảnh hưởng trực tiếp trải nghiệm khách đang dùng; nên có cờ `studio_enforce_credits` mặc định TẮT, bật khi đã có thanh toán |
| Q2 | **Cổng thanh toán** (VNPay/MoMo/chuyển khoản) | Chưa có ⇒ nút "Nâng cấp" chỉ được nói thật là "kích hoạt ngay, thanh toán sau" |
| Q3 | **Gói theo mùa vụ/xưởng** (bán theo đơn hàng thay vì theo tháng) | Chủ xưởng may mua theo vụ, không mua theo tháng |
| Q4 | **Số ghế theo gói** | Cần cho chủ doanh nghiệp; hiện mỗi tài khoản là một người dùng riêng |
