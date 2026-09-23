# CREDIT · GÓI · LỢI NHUẬN — THIẾT KẾ LẠI

> **Trạng thái: ĐỀ XUẤT — chưa sửa một dòng mã nào.** Ngày lập: 2026-09-26.
> Trả lời 4 yêu cầu mới: **(1)** mặc định mở khoá full tính năng cho MỌI gói kể cả free, rồi thiết kế lại
> trong Settings · **(2)** giá credit tham khảo OpenArt, cạnh tranh ở thị trường Việt Nam ·
> **(3)** phương án tốt nhất cho `ext-sodium` · **(4)** theo dõi token/credit giữa nhà cung cấp và
> khách hàng để tính lợi nhuận.
>
> **Nguồn số:** `docs/PRICING_RESEARCH_2026-09-23.md` (bảng giá OpenArt · fal.ai · DashScope, có URL
> từng dòng) · `PRICING.md` · `database/seeders/PlanSeeder.php` · `app/Support/ModuleRegistry.php` ·
> và **SSH trực tiếp vào máy chủ production** để đo (§6).

> ---
> ### ✅ CẬP NHẬT 2026-09-26 — BỐN ĐIỀU ĐÃ CHỐT, §4 ĐÃ TÍNH LẠI
> · **E4:** bỏ `veo3` khỏi UI (giữ endpoint) · video dùng **kling $0,07/giây**.
> · **E5:** sửa ảnh dùng **`fal-ai/flux-pro/v1/fill`** — DashScope giữ vai dự phòng.
> · **Biên mục tiêu: 40 % cho MỌI giao dịch** (không phải bình quân).
> · **Bảng credit + bảng gói cước đã tính lại ở §4** — mọi dòng ≥ 41,7 %, và **khách được nhiều credit
>   hơn** so với bản đang seed (155 / 405 / 1.240 thay cho 120 / 350 / 1.200) **mà không tăng giá gói**.
>
> ⚠️ §3.5 dưới đây là **chẩn đoán theo giá CŨ** (trước khi chốt E4/E5) — giữ lại để thấy vì sao phải sửa.
> Con số để **thi hành** nằm ở **§4**.

---

## 0. KẾT LUẬN NGẮN

1. **"Mở khoá full tính năng cho mọi gói" — hệ thống ĐÃ đúng như vậy.** Migration
   `2026_09_19_000007_add_modules_to_plans` đổ **toàn bộ module** cho **mọi gói**, và gói tạo mới tự nhận
   `'*'`. Việc còn thiếu chỉ là **màn Settings để chủ dự án siết dần bằng dữ liệu**. §2.
2. ⭐ **Cổng chặn của gói Miễn phí KHÔNG phải tính năng — mà là CREDIT.** Gói free hiện có
   `credits_per_month = 0` + 100 credit tặng một lần. Nghĩa là mô hình đúng ("mở hết tính năng, trả
   tiền theo lượng dùng") **đã có sẵn**.
3. 🔴 **PHÁT HIỆN NẶNG NHẤT: ở giá hiện tại, ít nhất 3 đường đang LỖ thật.**
   `PRICING.md` §1.1 giả định **$0,02/MP** (base) và **$0,03/MP** (edit) — hai số đó **khớp giá fal.ai,
   KHÔNG khớp giá DashScope trực tiếp** mà hệ thống đang gọi. Giá thật: **$0,035/ảnh** và **$0,045/ảnh**.
   Hệ quả: **biên gộp rơi từ ~70 % xuống ~49 %, ~41 %, ~32 %** — và hai đường đắt
   (`flux-pro/fill` ở 2K, `veo3`) **ÂM 262 % và −189 %**. §3.
4. **Nguyên nhân gốc: 1 credit = 1 ảnh, bất kể model.** Giá vốn giữa model rẻ nhất và đắt nhất lệch
   **hơn 300 lần** ($0,006 → $2,00). Đó là lý do yêu cầu #4 (theo dõi giá vốn) là yêu cầu **quan trọng
   nhất** trong bốn yêu cầu — nó biến "có lãi không" từ cảm tính thành số. §4, §5.
5. **`ext-sodium` KHÔNG có trên production — đã đo bằng SSH.** Phương án tốt nhất **không phụ thuộc
   extension**: 3 tầng, tầng cuối lấy kết quả từ chính fal bằng `request_id` của ta ⇒ **kể cả webhook
   giả cũng không chèn được ảnh lạ**. §6.
6. **Deploy đang thiếu một thứ rẻ mà đắt giá: `crontab` không tồn tại trên máy chủ**
   ⇒ `schedule:run` **chưa bao giờ chạy** ⇒ **7 tác vụ định kỳ đang chết**, trong đó có
   `studio:grant-plan-credits` (cấp credit hằng tháng cho khách trả tiền). §7.

---

## 1. QUYẾT ĐỊNH ĐÃ CHỐT (2026-09-26)

| Mã | Quyết định | Hệ quả lên mã |
|---|---|---|
| **D1** | **Không cần crop** | Bỏ khỏi UI. Endpoint `/api/reframe` **giữ nguyên** (không xoá API), chỉ hết call-site. Bỏ 6 khoá state + ~120 dòng ở `canvasView.js` |
| **D2** | **Bỏ hẳn paint/erase** | Xoá `brushes.js` (223 dòng) **hoàn toàn** + 27 khoá state (`eraseMode`, `drawMode` và 25 khoá phụ) + 2 overlay `<canvas>` trong `StudioApp.vue` |
| **D3** | **Giữ Qwen/DashScope trực tiếp làm dự phòng** | fal là đường chính; DashScope là fallback. ⚠️ **Nhưng số liệu ở §3.5 đề nghị đảo vai cho một nhóm việc** — đọc trước khi chốt |
| **D4** | **Dùng cron ngoài** | Gỡ hẳn phụ thuộc hPanel. §7 — và việc này **cứu cả 7 tác vụ nền** |
| **D5** | **Mặc định màn Chỉnh ảnh là "Tả"** | Không mask là đường mặc định; mask là tuỳ chọn |

---

## 2. GÓI & TÍNH NĂNG: MẶC ĐỊNH MỞ FULL, SIẾT BẰNG DỮ LIỆU

### 2.1 Hiện trạng — ĐÃ đúng, không phải làm lại

`database/migrations/2026_09_19_000007_add_modules_to_plans.php` viết thẳng trong chú thích:

> *"Đổ ĐỦ module cho các gói đang có: **hôm nay mọi gói dùng được mọi tính năng**, nên deploy KHÔNG được
> lấy mất tính năng của khách đang trả tiền. Việc siết theo gói là quyết định của chủ dự án, làm sau
> **bằng dữ liệu** trong trang Quản trị."*

Và `App\Support\ModuleRegistry` khai nguyên tắc: *"Mặc định = đủ module (giữ nguyên hành vi), chủ dự
án siết dần bằng dữ liệu."*

⇒ Yêu cầu **đã là hành vi thật**. Việc phải làm là **giữ nó** và **làm cho nó điều khiển được**.

### 2.2 Cái còn THIẾU

| Thiếu | Vì sao quan trọng |
|---|---|
| **Gói free không được nêu trong bản khai nào** | Nó *được* full module nhờ migration đổ hết — nhưng đó là **tình cờ**, không phải luật. Gói free tạo lại sau này có thể rơi nhánh khác |
| **Không có màn "Gói × Tính năng"** | Phải sửa từng gói một trong Quản trị; không nhìn được **một bảng** để biết gói nào có gì |
| **Không có "chính sách mặc định"** | Chưa có chỗ trả lời: *"mặc định của hệ thống là mở hết hay đóng hết?"* |
| **Không có lịch sử thay đổi** | Siết tính năng là việc **ảnh hưởng khách đang trả tiền**; không log thì không truy được |

### 2.3 Thiết kế màn Settings "Gói & tính năng"

Thêm **một mục thứ 6** vào Cài đặt (đã có sidebar 5 mục ở `/cai-dat`) — **không đẻ trang mới**.

```
┌─ Cài đặt của tôi → Gói & tính năng ─────────────────────────────┐
│                                                                 │
│  CHÍNH SÁCH MẶC ĐỊNH                              [ Mở hết  ▾ ]  │
│  ⓘ Đang: MỌI GÓI (kể cả Miễn phí) dùng được MỌI tính năng.     │
│    Cổng chặn của gói Miễn phí là CREDIT, không phải tính năng.  │
│                                                                 │
│  BẢNG GÓI × TÍNH NĂNG       [x] chỉ hiện chỗ KHÁC mặc định      │
│  ┌────────────────┬──────┬─────────┬────────┬────────┬────────┐ │
│  │ Tính năng      │ Miễn │ Khởi    │ Chuyên│ Studio │ Xưởng  │ │
│  │                │ phí  │ nghiệp  │ nghiệp │        │ theo vụ│ │
│  ├────────────────┼──────┼─────────┼────────┼────────┼────────┤ │
│  │ Tạo ảnh        │  ✓   │   ✓     │  ✓     │   ✓    │   ✓    │ │
│  │ Sửa ảnh (mask) │  ✓   │   ✓     │  ✓     │   ✓    │   ✓    │ │
│  │ Video          │  ✓   │   ✓     │  ✓     │   ✓    │   ✓    │ │
│  │ Ghép trang phục│  ✓   │   ✓     │  ✓     │   ✓    │   ✓    │ │
│  └────────────────┴──────┴─────────┴────────┴────────┴────────┘ │
│   «Áp dụng đề xuất của hệ thống» · «Mở hết» · «Hoàn tác»        │
│                                                                 │
│  GIỚI HẠN THEO GÓI (không phải công tắc bật/tắt)                │
│  ┌────────────────┬──────────┬───────────┬──────────────────┐   │
│  │ Gói            │ credit/th│ độ phân   │ số ghế           │   │
│  ├────────────────┼──────────┼───────────┼──────────────────┤   │
│  │ Miễn phí       │    0     │   1K      │ 1                │   │
│  │ Khởi nghiệp    │   120    │   2K      │ 1                │   │
│  └────────────────┴──────────┴───────────┴──────────────────┘   │
│                                                                 │
│  LỊCH SỬ  26/09 14:02 · owner · tắt Video ở gói Miễn phí ·      │
│           3 khách bị ảnh hưởng                          [Xem]   │
└─────────────────────────────────────────────────────────────────┘
```

**Bốn luật cho màn này:**

1. **Mặc định phải NHÌN THẤY ĐƯỢC**, không nằm trong mã — một dòng nói thẳng: *"gói Miễn phí chặn bằng
   CREDIT, không bằng tính năng."*
2. **Cảnh báo trước khi siết**: bỏ tick một tính năng ở gói đang có khách ⇒ hiện **số khách bị ảnh
   hưởng** và bắt xác nhận. (Đúng chỗ Playground AI trả giá — xem tài liệu trước §3.3c.)
3. **Lịch sử thay đổi là BẮT BUỘC**, không phải tính năng phụ.
4. **Hoàn tác một bấm** trong 24 h.

---

## 3. GIÁ: SỐ THẬT, VÀ CHỖ ĐANG LỖ

> Tỉ giá dùng trong tài liệu này: **1 USD = 24.000 ₫** (theo `PRICING.md`). Phải là **cấu hình**, không
> hardcode — xem §8 câu E1.

### 3.1 Đối thủ: OpenArt — số THẬT

| Gói | $/tháng (trả năm) | credit/tháng | $/1.000 credit |
|---|---|---|---|
| Starter | **$13** | 4.000 | $3,25 |
| Plus | **$27** | 12.000 | $2,25 |
| Pro | **$44** | 24.000 | $1,83 |
| Wonder | **$175** | 106.000 | $1,65 |

- Nạp thêm: **5.000 credit / $15/tháng** — nhưng **bắt buộc phải có gói đang chạy**, không bán rời.
- **Gói Miễn phí: 40 credit MỘT LẦN** (không phải mỗi ngày), **có watermark**, và **KHÔNG được dùng
  thương mại** (phải từ gói Plus trở lên).
- **Không công bố** bảng credit theo model (chỉ nói khoảng *"5–50 credit/ảnh"*), **không có API công khai**,
  và Terms §4.4 **cấm bot/script**.
- Credit tháng **KHÔNG rollover**; credit nạp thêm **có** rollover.

### 3.2 FabrikAI hiện tại

| Gói | Giá/tháng | credit/tháng | **₫/credit** | **$/credit** |
|---|---|---|---|---|
| Miễn phí | 0 ₫ | **0** (+100 tặng một lần) | — | — |
| Khởi nghiệp | 199.000 ₫ | 120 | 1.658 ₫ | **$0,0691** |
| Chuyên nghiệp | 499.000 ₫ | 350 | 1.426 ₫ | **$0,0594** |
| Studio | 1.490.000 ₫ | 1.200 | 1.242 ₫ | **$0,0518** |

### 3.3 Cái SAI đang nằm trong `PRICING.md`

| Chỗ | Đang ghi | **Số THẬT** | Lệch |
|---|---|---|---|
| Qwen Image Base | $0,02/MP | **$0,035/ảnh** (DashScope tính **theo ẢNH**, không theo MP) | **+75 %** |
| Qwen Image Edit | $0,03/MP | **$0,045/ảnh** (bản `-plus` mới là $0,03) | **+50 %** |

Quy ra VNĐ: base ≈ **840 ₫/ảnh** (không phải 500 ₫); edit ≈ **1.080 ₫/ảnh** (không phải 750 ₫).

⇒ **Cả 3 gói đều rơi khỏi mục tiêu biên > 70 %** đã chốt:

| Gói | Biên GIẢ ĐỊNH cũ | **Biên THẬT** |
|---|---|---|
| Khởi nghiệp | ~70 % | **~49 %** |
| Chuyên nghiệp | ~65 % | **~41 %** |
| Studio | ~60 % | **~32 %** |

### 3.4 ⚠️ Một chi tiết kỹ thuật làm giá fal ĐẮT HƠN VẺ NGOÀI

fal ghi rõ với `flux-pro/v1/fill`: *"Images are billed by **rounding up to the nearest
megapixel**."*

Ảnh **1024×1024 = 1,05 MP → làm tròn LÊN thành 2 MP**. Tức là **ảnh 1K bị tính gấp đôi**.
Ảnh **2048×2048 = 4,19 MP → 5 MP**.

| Việc | Model | Giá vốn THẬT (đã làm tròn MP) |
|---|---|---|
| Tạo ảnh nhanh 1K | `flux/schnell` $0,003/MP × 2 | **$0,006** |
| Tạo ảnh nhanh 2K | `flux/schnell` × 5 | **$0,015** |
| Tạo ảnh khá 1K | `flux/dev` $0,025/MP × 2 | **$0,050** |
| Sửa ảnh (mô tả) | `nano-banana/edit` (theo ảnh) | **$0,0398** |
| Sửa ảnh (mô tả, cao cấp) | `nano-banana-pro/edit` | **$0,150** |
| Sửa ảnh (có mask) 1K | `flux-pro/v1/fill` $0,05/MP × 2 | **$0,100** |
| **Sửa ảnh (có mask) 2K** | `flux-pro/v1/fill` × 5 | **$0,250** 🔴 |
| Sửa ảnh (có mask) 1K | `qwen-image-edit` trên fal $0,03/MP × 2 | **$0,060** |
| Upscale 1K→2K | `clarity-upscaler` $0,03/MP × 5 | **$0,150** |
| Video 5 giây | `kling-video/v2.5-turbo/pro` $0,07/s | **$0,350** |
| **Video 5 giây** | `veo3` $0,40/s | **$2,000** 🔴 |

**Và DashScope — tính theo ẢNH, giá PHẲNG, không phạt ảnh lớn:**

| Việc | Model | Giá vốn |
|---|---|---|
| Sửa ảnh | `qwen-image-edit` | **$0,045** |
| Sửa ảnh | `qwen-image-edit-plus` | **$0,030** |
| Sửa ảnh | `qwen-image-edit-max` | **$0,075** |
| Tạo ảnh | `qwen-image` | **$0,035** |

> fal **không tính tiền lỗi 5xx** (kể cả thời gian chờ trong hàng đợi). Nhưng **lỗi 422 CÓ THỂ bị tính**.
> ⇒ **phải validate ảnh/mask phía mình TRƯỚC khi gọi hàng loạt.**
> DashScope **không tính tiền lần lỗi**.

### 3.5 🔴 BẢNG LÃI/LỖ THEO GIÁ HIỆN TẠI (gói Khởi nghiệp, 1 credit = $0,0691)

| Việc | Giá vốn | Doanh thu | Lãi | **Biên** |
|---|---|---|---|---|
| Tạo ảnh nhanh 1K (schnell) | $0,006 | $0,0691 | +0,0631 | **91 %** ✅ |
| Tạo ảnh Qwen (DashScope) | $0,035 | $0,0691 | +0,0341 | **49 %** ⚠️ |
| Sửa ảnh mô tả (nano-banana) | $0,0398 | $0,0691 | +0,0293 | **42 %** ⚠️ |
| Sửa ảnh mask (DashScope edit) | $0,045 | $0,0691 | +0,0241 | **35 %** ❌ |
| Tạo ảnh khá (flux/dev) | $0,050 | $0,0691 | +0,0191 | **28 %** ❌ |
| Sửa ảnh mask (fal qwen-edit) 1K | $0,060 | $0,0691 | +0,0091 | **13 %** ❌ |
| **Sửa ảnh mask (flux-pro/fill) 1K** | $0,100 | $0,0691 | −0,0309 | **−45 %** 🔴 |
| **Sửa ảnh mask (flux-pro/fill) 2K** | $0,250 | $0,0691 | −0,1809 | **−262 %** 🔴 |
| Video kling 5s (10 credit) | $0,350 | $0,691 | +0,341 | **49 %** ⚠️ |
| **Video veo3 5s (10 credit)** | $2,000 | $0,691 | −1,309 | **−189 %** 🔴 |

**Đọc bảng này ra ba việc:**

1. **`veo3` phải bỏ hoặc đưa lên gói rất cao.** Ở 10 credit/video, mỗi lượt lỗ **$1,31**.
   Kling ($0,07/s) là lựa chọn video hợp lý.
2. **`flux-pro/fill` chỉ hợp lý ở 1K và phải tính ≥ 5 credit.** Ở 2K nó đắt gấp 2,5 lần DashScope
   ⇒ **đừng dùng nó cho ảnh 2K**.
3. ✅ **E4 + E5 — ĐÃ CHỐT 2026-09-26:**
   · **E4:** **bỏ `veo3` khỏi UI** (giữ endpoint), video dùng **`kling-video/v2.5-turbo/pro` — $0,07/giây**.
   · **E5:** **sửa ảnh dùng `fal-ai/flux-pro/v1/fill`** — không đảo vai sang DashScope. DashScope giữ
     đúng vai **dự phòng** (D3). Hệ quả: giá vốn sửa ảnh **theo megapixel, làm tròn LÊN**, nên **ảnh 1:1
     và ảnh 2K đắt hơn hẳn** — đã tính đủ ở §4.

---

## 4. MÔ HÌNH CREDIT MỚI — TÍNH LẠI ĐỂ BẢO ĐẢM BIÊN **40 %**

> **Mục tiêu đã chốt: mọi giao dịch ≥ 40 %.** Không phải biên bình quân 40 % — mà **từng dòng một**
> phải ≥ 40 %, để một khách dùng toàn model đắt cũng không kéo cả tháng xuống dưới ngưỡng.

### 4.1 Ba tầng, đừng trộn

| Tầng | Đơn vị | Ai đặt | Ở đâu |
|---|---|---|---|
| Giá vốn nhà cung cấp | USD | fal / DashScope công bố | bảng `provider_price` (chủ dự án cập nhật) |
| Giá vốn một lượt chạy | USD → VNĐ | **Hệ thống tự tính** | bảng `provider_usage` |
| Giá bán cho khách | **credit** | chủ dự án đặt theo model | bảng `model_credit_cost` |

Tỉ giá dùng để tính: **1 USD = 26.000 ₫** (cấu hình được — xem §8.2 câu E1).

### 4.2 ⚠️ Bước 0 — megapixel LÀM TRÒN LÊN (đây là chỗ giá vốn bị đội lên)

fal ghi rõ với `flux-pro/v1/fill`: *"Images are billed by **rounding up to the nearest megapixel**."*
Với các tỉ lệ app đang dùng (`falSizeFor`: 1K = cạnh dài 1024, 2K = 2048):

| Tỉ lệ | 1K | MP thật → **MP tính tiền** | 2K | MP thật → **MP tính tiền** |
|---|---|---|---|---|
| **1:1** | 1024×1024 | 1,049 → **2** ⚠️ | 2048×2048 | 4,194 → **5** ⚠️ |
| 4:3 · 3:4 | 1024×768 | 0,786 → **1** | 2048×1536 | 3,146 → **4** |
| 4:5 | 819×1024 | 0,839 → **1** | 1638×2048 | 3,355 → **4** |
| 16:9 · 9:16 | 1024×576 | 0,590 → **1** | 2048×1152 | 2,359 → **3** |
| 2:3 | 683×1024 | 0,699 → **1** | 1365×2048 | 2,796 → **3** |
| 21:9 · 19:6 | 1024×439 | 0,450 → **1** | 2048×878 | 1,798 → **2** |

**Hai điều rút ra:**
- **Ảnh 1:1 ở 1K bị tính GẤP ĐÔI** các tỉ lệ khác (2 MP thay vì 1 MP). Không phải lỗi của ta — là cách
  fal làm tròn — nhưng **phải phản ánh vào số credit**, không thì tỉ lệ 1:1 là tỉ lệ lỗ.
- **2K đắt gấp ~3–5 lần 1K**, không phải gấp đôi. Vì vậy **giới hạn độ phân giải theo gói** (1K cho
  Miễn phí, 2K cho gói trả tiền) là công cụ chống lỗ mạnh hơn cả việc tăng credit.

### 4.3 Giá vốn THẬT của từng việc (đã làm tròn MP)

| Việc | Model (E5: flux) | MP | Giá vốn $ | **Giá vốn ₫** |
|---|---|---|---|---|
| Tạo ảnh nhanh 1K (trừ 1:1) | `fal-ai/flux/schnell` | 1 | $0,0030 | **78 ₫** |
| Tạo ảnh nhanh 1K **1:1** | `fal-ai/flux/schnell` | 2 | $0,0060 | **156 ₫** |
| Tạo ảnh nhanh 2K 1:1 | `fal-ai/flux/schnell` | 5 | $0,0150 | **390 ₫** |
| Tạo ảnh khá 1K (trừ 1:1) | `fal-ai/flux/dev` | 1 | $0,0250 | **650 ₫** |
| Tạo ảnh khá 2K 4:5 | `fal-ai/flux/dev` | 4 | $0,1000 | **2.600 ₫** |
| **Sửa ảnh (mask) 1K (trừ 1:1)** | `fal-ai/flux-pro/v1/fill` | 1 | $0,0500 | **1.300 ₫** |
| **Sửa ảnh (mask) 1K 1:1** | `fal-ai/flux-pro/v1/fill` | 2 | $0,1000 | **2.600 ₫** |
| **Sửa ảnh (mask) 2K 16:9** | `fal-ai/flux-pro/v1/fill` | 3 | $0,1500 | **3.900 ₫** |
| **Sửa ảnh (mask) 2K 4:5** | `fal-ai/flux-pro/v1/fill` | 4 | $0,2000 | **5.200 ₫** |
| **Sửa ảnh (mask) 2K 1:1** | `fal-ai/flux-pro/v1/fill` | 5 | $0,2500 | **6.500 ₫** |
| Sửa ảnh mô tả (mọi cỡ) | `fal-ai/nano-banana/edit` | — | $0,0398 | **1.035 ₫** |
| Upscale 2K 4:5 | `fal-ai/clarity-upscaler` | 4 | $0,1200 | **3.120 ₫** |
| Upscale 2K 1:1 | `fal-ai/clarity-upscaler` | 5 | $0,1500 | **3.900 ₫** |
| Video 5 giây | `fal-ai/kling-video/v2.5-turbo/pro` | $0,07/s | $0,3500 | **9.100 ₫** |
| Video 10 giây | `fal-ai/kling-video/v2.5-turbo/pro` | $0,07/s | $0,7000 | **18.200 ₫** |
| *(dự phòng)* Sửa ảnh | `qwen-image-edit` (DashScope, giá PHẲNG) | — | $0,0450 | **1.170 ₫** |

### 4.4 Công thức — MỘT dòng, không ngoại lệ

```
credit(model, cỡ) = ceil( giá_vốn_VNĐ / 720 )        và tối thiểu 1 credit

trong đó  720 ₫ = 1.200 ₫/credit × (1 − 0,40)
```

**Vì sao chia cho 720 và không phải số khác:** 720 là mức chia **tệ nhất** trong mọi gói — nó giả định
khách đang trả **1.200 ₫/credit**, tức gói rẻ nhất về đơn giá. Mọi gói khác trả cao hơn ⇒ biên **cao hơn**
40 %. Đây chính là điều biến "bình quân 40 %" thành "**mọi dòng ≥ 40 %**".

### 4.5 BẢNG CREDIT CHỐT (biên tính ở 1.200 ₫/credit — mức xấu nhất)

| Việc | Cỡ | Giá vốn ₫ | **credit** | Doanh thu ₫ | **Biên** |
|---|---|---|---|---|---|
| Tạo ảnh nhanh (schnell) | 1K (trừ 1:1) | 78 | **1** | 1.200 | **93,5 %** |
| Tạo ảnh nhanh | 1K 1:1 | 156 | **1** | 1.200 | **87,0 %** |
| Tạo ảnh nhanh | 2K 1:1 | 390 | **1** | 1.200 | **67,5 %** |
| Tạo ảnh khá (flux/dev) | 1K (trừ 1:1) | 650 | **1** | 1.200 | **45,8 %** |
| Tạo ảnh khá | 1K 1:1 · 2K 16:9 | 1.300 | **2** | 2.400 | **45,8 %** |
| Tạo ảnh khá | 2K 4:5 | 2.600 | **4** | 4.800 | **45,8 %** |
| Tạo ảnh khá | 2K 1:1 | 3.250 | **5** | 6.000 | **45,8 %** |
| **Sửa ảnh mô tả** (nano-banana) | mọi cỡ | 1.035 | **2** | 2.400 | **56,9 %** |
| **Sửa ảnh mask 1K** | trừ 1:1 | 1.300 | **2** | 2.400 | **45,8 %** |
| **Sửa ảnh mask 1K 1:1** | 1:1 | 2.600 | **4** | 4.800 | **45,8 %** |
| **Sửa ảnh mask 2K 16:9** | 3 MP | 3.900 | **6** | 7.200 | **45,8 %** |
| **Sửa ảnh mask 2K 4:5** | 4 MP | 5.200 | **8** | 9.600 | **45,8 %** |
| **Sửa ảnh mask 2K 1:1** | 5 MP | 6.500 | **10** | 12.000 | **45,8 %** |
| Upscale 1K→2MP | 2 MP | 1.560 | **3** | 3.600 | **56,7 %** |
| Upscale 2K 4:5 | 4 MP | 3.120 | **5** | 6.000 | **48,0 %** |
| Upscale 2K 1:1 | 5 MP | 3.900 | **6** | 7.200 | **45,8 %** |
| **Video kling 5 giây** | 5s | 9.100 | **13** | 15.600 | **41,7 %** |
| **Video kling 10 giây** | 10s | 18.200 | **26** | 31.200 | **41,7 %** |
| *(dự phòng)* Sửa ảnh DashScope | mọi cỡ | 1.170 | **2** | 2.400 | **51,2 %** |

> ✅ **Mọi dòng ≥ 41,7 %.** Không có dòng nào dưới 40 %.

### 4.6 ⭐ Bất biến quan trọng nhất — và nó cho ra MỘT con số

Giá vốn **trên mỗi credit** khác nhau theo model. Tính ra:

| Việc | ₫/credit |
|---|---|
| **Video kling** (9.100 ÷ 13) | **700 ₫/credit** ← **CAO NHẤT** |
| Sửa mask flux-pro 2K (6.500 ÷ 10) | 650 ₫/credit |
| Tạo ảnh khá (650 ÷ 1) | 650 ₫/credit |
| Tạo ảnh nhanh (78 ÷ 1) | 78 ₫/credit |

=> **BẤT BIẾN: mọi gói phải giữ ₫/credit ≥ 700 ÷ 0,60 = 1.167 ₫.**

Đây là **một con số duy nhất** để kiểm tra mọi gói cước, hiện tại và tương lai — và **khoá được bằng
test tự động**: thêm một gói mới rẻ hơn ngưỡng là test ĐỎ. Không cần ai nhớ bảng giá.

Ta đặt ngưỡng an toàn cao hơn một chút: **1.200 ₫/credit** (đệm ~3 % cho thử lại và lỗi 422 có thể bị tính).

### 4.7 BẢNG GÓI CƯỚC CHỐT — giữ nguyên giá, chỉnh số credit

**Không tăng giá gói** (giữ lời hứa với khách đang trả) — chỉ chỉnh số credit để mọi gói nằm trên
ngưỡng 1.200 ₫/credit:

| Gói | Giá/tháng | **credit/tháng** | **₫/credit** | Biên **xấu nhất** (700 ₫/cr) | Biên **thường** |
|---|---|---|---|---|---|
| **Miễn phí** | 0 ₫ | **100** (tặng một lần, khoá 1K) | — | — | — |
| **Khởi nghiệp** | 199.000 ₫ | **155** | **1.284 ₫** | **45,5 %** ✅ | 47–94 % |
| **Chuyên nghiệp** ⭐ | 499.000 ₫ | **405** | **1.232 ₫** | **43,2 %** ✅ | 43–94 % |
| **Studio** | 1.490.000 ₫ | **1.240** | **1.202 ₫** | **41,8 %** ✅ | 42–94 % |

So với bản đang seed (120 / 350 / 1.200 credit): **khách được NHIỀU hơn** (155 / 405 / 1.240) mà biên
vẫn ≥ 40 % — vì trước đây giá vốn bị tính sai. Điểm rất đáng nói với khách: **không tăng giá, vẫn thêm credit**.

**Quy tắc đơn giá:** gói càng lớn càng rẻ hơn một chút (−4 % ở Chuyên nghiệp, −6,4 % ở Studio) nhưng
**không bao giờ xuống dưới 1.200 ₫/credit**.

### 4.8 Rủi ro của gói Miễn phí — đã đo

Gói free có **100 credit** và **khoá ở 1K** (đã có sẵn: `resolution_cap = '1K'`). Chi phí thật cho một
tài khoản free, ở hai thái cực:

| Kịch bản | Cách tiêu | Chi phí thật |
|---|---|---|
| Dùng đúng cách (tạo ảnh nhanh) | 100 × 1 credit × 78 ₫ | **7.800 ₫** (~$0,30) |
| **Khai thác tối đa ở 1K** | 25 lượt × sửa mask 1:1 (4 credit) × 2.600 ₫ | **65.000 ₫** (~$2,50) |

=> **Trần chi phí một tài khoản free là 65.000 ₫.** Đây là **chi phí thu hút khách (CAC)**, không phải lỗ —
và $2,50 là mức CAC rẻ cho khách B2B. **Đề xuất: giữ 100 credit**, nhưng **khoá 1K là BẮT BUỘC** — bỏ khoá
đó thì trần vọt lên 10 credit/lượt × 6.500 ₫, tức **gấp 2,5 lần**.

### 4.9 Vì sao KHÔNG tăng giá gói

| Cách | Ưu | Nhược |
|---|---|---|
| Tăng giá gói | Đơn giản | **Phá lời hứa** với khách đang trả; phải thông báo; dễ mất khách |
| **Đổi số credit theo model** ✅ | Khách cũ giữ nguyên số dư; giải thích được ("ảnh này 3 credit vì chất lượng cao hơn"); UI **đã có sẵn chỗ nói giá trước khi bấm** (§12 nguyên tắc 3); và lần này còn **thêm credit** | Phải làm bảng giá + UI hiện giá |

### 4.10 Điều BẮT BUỘC phải làm ở giao diện

Với bảng credit này, **"1 ảnh = 1 credit" KHÔNG còn đúng** ⇒ giao diện phải nói giá **theo model + cỡ**
**TRƯỚC khi bấm**, ở đúng ba chỗ:

| Chỗ | Hiện gì |
|---|---|
| Nút chạy của mỗi công cụ | `Sửa ảnh · 8 credit · còn 412` (đã có sẵn chỗ này — §12 nguyên tắc 3) |
| Ô chọn độ phân giải | Đổi 1K → 2K thì **số credit nhảy NGAY** trước khi bấm |
| Thư viện ảnh | Mỗi ảnh ghi **đã tốn bao nhiêu credit** (đọc từ `generations.credits_cost`) |

> ⚠️ Không làm §4.10 thì khách bấm "Sửa ảnh" tưởng 1 credit mà bị trừ 8 — **đó là loại lỗi làm mất khách
> nhanh nhất**, và nó chống lại đúng nguyên tắc 3 đã chốt.
---

## 5. THEO DÕI GIÁ VỐN & LỢI NHUẬN

### 5.1 Ghi theo TỪNG LẦN GỌI, không theo từng ảnh

**Vì sao:** `config/studio.php` khai chuỗi ưu tiên `qwen → custom → flux → gemini`. Một ảnh có thể
**thử 3 nhà cung cấp rồi mới thành công** ⇒ **một** generation sinh **nhiều** lần gọi có tính tiền.
Ghi giá vốn vào `generations` là ghi sai (chỉ giữ được lần cuối).

Hôm nay `generations` có **29 cột**, trong đó `credits_cost` (khách trả) — và
**không một cột nào ghi ta trả bao nhiêu**. Đó là lỗ hổng.

### 5.2 Schema

```sql
CREATE TABLE provider_price (
  id, provider VARCHAR(32),          -- fal | dashscope | gemini
  model    VARCHAR(120),
  unit     ENUM('image','megapixel','second','1k_tokens'),
  unit_price_usd DECIMAL(10,6),      -- vd 0.0500 cho flux-pro/fill (per megapixel)
  note, updated_at,
  UNIQUE(provider, model)
);

CREATE TABLE provider_usage (
  id,
  generation_id  BIGINT NULL,        -- NULL = lượt không thuộc generation (agent text/vision)
  user_id        BIGINT NULL,
  provider       VARCHAR(32),
  model          VARCHAR(120),
  attempt        TINYINT,            -- 1,2,3… thứ tự trong chuỗi dự phòng
  outcome        ENUM('ok','failed','timeout','unknown_cost'),
  unit           ENUM('image','megapixel','second','1k_tokens'),
  units          DECIMAL(10,4),      -- số megapixel / số ảnh / số giây
  unit_price_usd DECIMAL(10,6),      -- CHỤP LẠI giá lúc chạy
  cost_usd       DECIMAL(12,6),
  fx_rate        DECIMAL(12,2),
  cost_vnd       DECIMAL(14,2),
  fal_request_id VARCHAR(64) NULL,
  inference_ms   INT NULL,           -- metrics.inference_time của fal (số THẬT)
  created_at
);

CREATE TABLE model_credit_cost (      -- giá BÁN: model → bao nhiêu credit
  id, provider, model, credits INT, note, updated_at, UNIQUE(provider, model)
);
```

**Ba quyết định trong schema đáng nói:**

- `unit_price_usd` **chụp lại giá lúc chạy** — sổ kế toán không được đổi khi ta cập nhật bảng giá.
  (Cùng nguyên tắc với `balance_after` trong `credit_transactions`.)
- `attempt` + `outcome` — trả lời được câu *"chuỗi dự phòng đang ngốn bao nhiêu?"*, câu mà
  **hôm nay không thể trả lời**.
- `units` **bắt buộc** với đơn vị `megapixel` — vì fal làm tròn LÊN (§3.4), nên phải ghi **số MP
  đã làm tròn**, không phải số MP thật.

### 5.3 Bốn con số phải luôn xem được

| Số | Công thức | Trả lời |
|---|---|---|
| **Doanh thu** | Σ credit bán ra × ₫/credit của gói | Tháng này thu bao nhiêu |
| **Giá vốn** | Σ `provider_usage.cost_vnd` | Trả nhà cung cấp bao nhiêu |
| **Lãi gộp** | Doanh thu − Giá vốn | **Có lãi không** |
| **Biên** | Lãi gộp ÷ Doanh thu | Bao nhiêu % |

Cắt được theo: **ngày · gói · khách · model · provider · nhóm việc**.

### 5.4 Màn báo cáo (thêm vào khu Quản trị)

```
┌─ Quản trị → Lợi nhuận ─────────────────── tháng 09/2026 ───────┐
│  Doanh thu  12.400.000 ₫    Giá vốn  3.980.000 ₫               │
│  Lãi gộp     8.420.000 ₫    Biên     67,9 %                    │
│                                                                │
│  THEO MODEL                        [sắp theo lãi gộp ▾]        │
│  ┌──────────────────┬───────┬──────────┬──────────┬─────────┐  │
│  │ Model            │ lượt  │ giá vốn  │ doanh thu│ biên    │  │
│  ├──────────────────┼───────┼──────────┼──────────┼─────────┤  │
│  │ flux/schnell     │ 1.204 │   173.000│ 1.996.000│  91 %   │  │
│  │ veo3             │    18 │ 1.036.000│   214.000│ −384 %❗│  │
│  │ flux-pro/v1/fill │    96 │   576.000│   159.000│ −262 %❗│  │
│  └──────────────────┴───────┴──────────┴──────────┴─────────┘  │
│  ❗ 2 model ĐANG LỖ → bấm để sửa số credit                     │
│                                                                │
│  CHUỖI DỰ PHÒNG: 41 lượt phải thử lại · tốn thêm 186.000 ₫     │
│  ⚠️ 7 lượt chưa rõ giá vốn (unknown_cost) — kiểm bảng giá      │
└────────────────────────────────────────────────────────────────┘
```

**Ô "❗ đang lỗ" là thứ biến báo cáo thành công cụ** — không phải để nhìn, mà để **bấm vào và sửa số
credit ngay**.

### 5.5 Nguồn số — và luật "thà thiếu còn hơn sai"

| Provider | Lấy gì | Ở đâu |
|---|---|---|
| **fal** | số MP đầu ra (**ảnh trả về có `width`/`height` trong payload webhook**) → **làm tròn lên** × đơn giá/MP | `provider_price` |
| **fal** | `metrics.inference_time` | response của queue — số THẬT |
| **DashScope** | giá **theo ẢNH**, tra theo đúng model ID đang dùng | `provider_price` |
| **Chưa chắc giá** | ⚠️ ghi `unit_price_usd = NULL` + `outcome = 'unknown_cost'` | **Thà thiếu một dòng còn hơn ghi số sai** — số sai làm mọi báo cáo sau đó vô nghĩa |

> ⚠️ **Việc phải làm trước khi tin bảng giá:** đối chiếu **hoá đơn DashScope thật**, vì
> `qwen-image-edit` ($0,045) và `qwen-image-edit-plus` ($0,03) **chênh nhau 50 %** và ta **chưa
> xác nhận hệ thống đang gọi đúng model ID nào**.

---

## 6. ext-sodium — ĐO TRÊN PRODUCTION, KHÔNG ĐOÁN

### 6.1 Đã SSH vào máy chủ thật và đo

```
$ ssh -p 65002 u310846799@145.79.25.57

PHP=8.3.33
sodium_ext=false                     ← ❌ KHÔNG CÓ
fn_sodium_verify=false
openssl=true      OPENSSL 3.5.5
gd=true  bcmath=true  gmp=true  hash=true  curl=true  opcache=true
post_max_size=1536M   memory_limit=1536M   max_execution_time=0
disable_functions=…,exec,…,proc_open
composer 2.9.8   (PHP tại /opt/alt/php83/usr/bin/php)
```

**Bốn kết luận đo được:**

- ❌ **`ext-sodium` KHÔNG có** ⇒ `sodium_crypto_sign_verify_detached()` **không tồn tại**. Viết code
  giả định có nó thì **webhook chết ngay lần chạy thật đầu tiên**.
- ✅ PHP chạy từ **`/opt/alt/php83/`** ⇒ đây là bộ PHP do **hPanel quản lý**
  ⇒ **bật được extension ở hPanel → Select PHP Version → Extensions**, không cần deploy.
- ✅ **`gmp` CÓ** ⇒ nếu buộc verify Ed25519 bằng PHP thuần thì có sẵn phép toán số lớn nhanh.
- ✅ `rest.fal.ai/.well-known/jwks.json` trả **HTTP 200** từ chính máy chủ đó ⇒ **lấy được khoá công
  khai của fal**.

### 6.2 Một thí nghiệm phụ đã chạy (để LOẠI phương án)

Câu hỏi: *"OpenSSL (3.5.5, luôn có) verify được Ed25519 không?"* — dựng DER SubjectPublicKeyInfo từ khoá
thô 32 byte rồi gọi `openssl_verify()`:

```
openssl_pkey_get_public(pem Ed25519)  → OK, type=-1 bits=256
openssl_verify(msg, sig, key, 0)      → false     ← KHÔNG hỗ trợ
openssl_verify(msg, sig, key, SHA256) → -1        ← KHÔNG hỗ trợ
```

⇒ **PHP không verify Ed25519 qua OpenSSL.** Loại phương án "dùng openssl cho khỏi cần sodium".

### 6.3 PHƯƠNG ÁN TỐT NHẤT — ba tầng, tầng cuối KHÔNG cần crypto

| Tầng | Cơ chế | Phụ thuộc | Bắt buộc |
|---|---|---|---|
| **T1** | **Token 32 byte ngẫu nhiên trong URL webhook**, gắn với đúng generation + **`payload.request_id` phải khớp `meta.fal.request_id` đã lưu** | **không** | ✅ **LUÔN** |
| **T2** | Kiểm chữ ký **Ed25519** (`X-Fal-Webhook-Signature` + JWKS), **tự bật khi có** | `ext-sodium` **hoặc** `paragonie/sodium_compat` | khi có thì bật |
| **T3** | **KHÔNG tin URL ảnh trong payload.** Lấy kết quả bằng cách gọi **`response_url` của fal** với `request_id` **ta tự lưu** | **không** | ✅ **LUÔN** |

**Vì sao T3 là tầng quan trọng nhất:** nó làm cho **chữ ký trở thành lớp bảo vệ THÊM, không phải điều kiện
sống còn**. Kẻ giả mạo có được token cũng **không chèn được ảnh của hắn**, vì ta chỉ nhận ảnh mà **fal**
trả về cho **request_id của ta**. Đổi lại webhook chậm thêm ~200 ms — không đáng kể so với một lượt render.

### 6.4 Việc phải làm, theo thứ tự

1. **Bật `sodium` ở hPanel → Select PHP Version → Extensions** (~1 phút, không cần deploy).
   Đây là lựa chọn **tốt nhất** vì PHP đã do hPanel quản lý.
2. Nếu không bật được, hoặc muốn **không phụ thuộc hPanel** (đúng tinh thần D4): thêm
   **`paragonie/sodium_compat`** — thư viện thuần PHP mà WordPress/Joomla dùng đúng cho tình huống này;
   nó tự dùng `sodium` khi có, rơi về PHP thuần (**nhanh nhờ `gmp` đã đo là CÓ**) khi không.
   Cài được trên host: `DEPLOY.md` §F ghi rõ `composer install --no-dev --optimize-autoloader
   --no-scripts` **chạy được** (chỉ `--optimize` hỏng vì `proc_open` bị chặn; PSR-4 vẫn nạp được).
3. **Luôn ghi log đang ở tầng nào**: `fal_webhook[auth=T1+T2+T3]` hoặc
   `fal_webhook[auth=T1+T3 — sodium thiếu]`. **Không bao giờ để việc giảm bảo mật diễn ra im lặng.**
4. **Lệnh kiểm tra trước khi tin**: `php artisan studio:webhook-doctor` in ra
   `extension_loaded('sodium')` · `APP_URL` có https không · URL webhook có dấu `/` thừa không ·
   JWKS có tới được không · khoá fal còn sống không.

### 6.5 ⚠️ Bẫy URL webhook — ĐÃ ĐO TRÊN PRODUCTION

fal ghi rõ: *"Redirects are not followed… a `3xx` is a permanent failure and is **not** retried"*, và nêu
đúng hai cái bẫy: `http://` → `https://`, và dấu `/` thừa.

Đã thử trên `fabrikai.shop` thật:

| Thử | Kết quả ĐO ĐƯỢC |
|---|---|
| `POST https://fabrikai.shop/api/webhooks/fal-test` | **HTTP 404**, redirects=**0** ⇒ route `api/*` **KHÔNG** bị đẩy về trang đăng nhập. Webhook sẽ nhận **2xx**, không phải 3xx |
| `POST http://fabrikai.shop/...` | **HTTP 301** → https ⇒ **nếu `APP_URL` là http thì webhook CHẾT IM LẶNG** |
| `APP_URL` của `fabrikai.shop` và `trillfa.shop` | `https://…` ✅ đúng sẵn |
| `crontab` trên production | **không tồn tại** ⇒ D4 là **bắt buộc**, không phải lựa chọn |

⇒ Phải có **test tự động** khẳng định `route('fal.webhook')` sinh **https, không dấu `/` thừa** — vì đây
là lỗi **không có triệu chứng nào ở phía ta**.

---

## 7. CRON NGOÀI (D4) — VÀ NÓ CỨU NHIỀU HƠN CẢ HÀNG ĐỢI

### 7.1 Đo được: `crontab` KHÔNG tồn tại ⇒ `schedule:run` CHƯA BAO GIỜ CHẠY

Đọc từ `routes/console.php` — **7 tác vụ đang chết**:

| Tác vụ | Nhịp | Đang chết nghĩa là |
|---|---|---|
| `studio:grant-plan-credits` | 00:30 hằng ngày | 🔴 **Khách trả tiền theo tháng KHÔNG được cấp credit đúng hạn** |
| `studio:market-signals` | mỗi 30 phút | Tín hiệu thị trường không tự làm mới |
| `studio:market-signals --prune` | 03:30 | Bảng tín hiệu phình mãi |
| `studio:clean-storage --queue` | 03:00 | File mồ côi không được dọn |
| `studio:samples:remind` | 08:00 | Không nhắc hạn mẫu vật lý |
| `studio:search:index` | 04:30 | Chỉ mục tìm kiếm cũ dần |
| `studio:memory:consolidate` | 04:00 | Trí nhớ agent không được củng cố |
| `studio-scheduler-heartbeat` | 5 phút | Giao diện **nói sai** "máy chủ tự làm mới tin mỗi 30 phút" |

⇒ **Cron ngoài là việc RẺ NHẤT trong cả kế hoạch mà đem lại nhiều nhất**: một URL + một tài khoản
cron-job.org miễn phí, và **cả bộ máy nền sống lại**.

### 7.2 Thiết kế

```
POST /api/cron/tick?token=<bí mật 32 byte, lưu trong settings>
  ├ không auth, không CSRF, KHÔNG throttle
  ├ so token bằng hash_equals()
  ├ chạy:  php artisan schedule:run        ← đúng cái cron hPanel lẽ ra phải chạy
  ├ và:    nhặt generation "cần dự phòng" (D3) → chạy đường DashScope
  └ trả JSON: { ran: [...], pending_processed: N, ms: 412 }
```

**Năm luật:**

1. **So token bằng `hash_equals()`** — không so bằng `==`.
2. **Không throttle**, nhưng **có** khoá cache 55 s để hai lượt gọi chồng nhau không chạy đôi.
3. **Trả số đo thật** — để cron-job.org hiện "thành công, 412 ms" và để ta phát hiện khi nó ngừng chạy.
4. **Ghi heartbeat** vào cache; màn Quản trị đọc và **nói thật** "lần chạy cuối 4 phút trước".
   (Cơ chế heartbeat **đã có sẵn** — chỉ cần có người gọi nó.)
5. **Tài liệu hoá cách dựng lại** trong `DEPLOY.md`: cron-job.org (miễn phí, 1 phút/lần) hoặc GitHub
   Actions `schedule:` (5 phút, kém chính xác hơn nhưng ổn định).

---

## 8. LỘ TRÌNH & CÂU HỎI

### 8.1 Lộ trình (theo thứ tự "rẻ mà lãi nhất trước")

| GĐ | Việc | Ngày |
|---|---|---|
| **A** | **Cron ngoài** (`/api/cron/tick` + `schedule:run` + tài liệu) — *làm trước tiên: rẻ nhất, mở khoá 7 tác vụ, có cái cấp credit hằng tháng* | 0,5 |
| **B** | **`provider_price` + `provider_usage` + ghi giá vốn ở MỌI lần gọi provider** (kể cả đường DashScope) | 1,5 |
| **C** | **Báo cáo lợi nhuận** trong Quản trị + lệnh `studio:provider-costs` + đèn đỏ "model đang lỗ" | 1,5 |
| **D** | **`model_credit_cost` + đổi số credit theo model** — sau khi B/C cho thấy số thật. Kèm **bỏ veo3** | 1 |
| **E** | **Màn Settings "Gói & tính năng"** (bảng gói × tính năng · cảnh báo số khách · lịch sử · hoàn tác) | 2 |
| **F** | **sodium**: thử bật ở hPanel; nếu không thì `sodium_compat` + `studio:webhook-doctor` | 0,5–1 |
| **G** | **Cập nhật `PRICING.md`** §1.1/§2/§3 theo số thật (§3.3) — *tài liệu đang nói sai, người sau sẽ tin* | 0,25 |

### 8.2 Câu hỏi chốt

| # | Câu hỏi | Vì sao |
|---|---|---|
| **E1** | **Tỉ giá USD→VND** — cố định theo quý, hay lấy tỉ giá thật? | Ảnh hưởng mọi con số lãi. Đề xuất: **cấu hình tay, đổi theo quý** (tỉ giá thật dao động làm báo cáo nhảy múa) |
| **E2** | ✅ **ĐÃ CHỐT: biên ≥ 40 % cho MỌI giao dịch** | §4 tính đúng theo ngưỡng này; bảng credit cho ra mọi dòng ≥ 41,7 % |
| **E3** | Gói **Miễn phí** có cấp **credit hằng tháng** không (vd 10–20/tháng)? | Hiện là **0** ⇒ dùng hết 100 credit tặng là hết đường. Nhỏ giọt giữ được người dùng nhưng là **chi phí thật** |
| **E4** | ✅ **ĐÃ CHỐT: bỏ `veo3` khỏi UI, giữ endpoint** | Video dùng `kling-video/v2.5-turbo/pro` $0,07/giây = **9.100 ₫/5 giây** → **13 credit** (biên 41,7 %) |
| **E5** | ✅ **ĐÃ CHỐT: KHÔNG đảo — sửa ảnh dùng `fal-ai/flux-pro/v1/fill`** | Hệ quả đã tính ở §4.2: giá vốn **theo MP làm tròn LÊN** ⇒ ảnh **1:1 ở 1K bị tính gấp đôi**, và **2K đắt gấp 3–5 lần 1K**. Vì vậy **giới hạn độ phân giải theo gói** trở thành công cụ chống lỗ quan trọng |
| **E6** | Ẩn giá vốn khỏi mọi tài khoản không phải owner? | Đây là dữ liệu cạnh tranh |

---

## 9. ĐÃ THI HÀNH (2026-09-26) — mã đã có trong repo, test đã xanh

> Mục này ghi lại **thứ đã làm thật**, để phiên sau không phải đoán cái nào còn là đề xuất.

### 9.1 Lớp dữ liệu mới

| Thứ | Ở đâu | Việc |
|---|---|---|
| `model_credit_cost` | migration `2026_09_26_000011` | **BÁN**: model + cỡ + tỉ lệ ⇒ số credit. 96 dòng đã seed |
| `provider_price` | cùng migration | **VỐN**: nhà cung cấp tính bao nhiêu cho một đơn vị (image / megapixel / second) |
| `provider_usage` | cùng migration | **SỔ**: một dòng cho MỘT lần gọi provider (kể cả lần lỗi) |
| `generations.cost_vnd` | cùng migration | bản sao tổng giá vốn của một ảnh, để báo cáo khỏi JOIN |
| `plans.daily_image_limit` | cùng migration | **GIỚI HẠN TẠO ẢNH/NGÀY** — sửa được trong Quản trị (0 = không giới hạn) |

### 9.2 Mã

| File | Việc |
|---|---|
| `app/Services/ProviderCostService.php` | **MỘT chỗ** cho: đổi đơn vị tính tiền (megapixel **làm tròn lên**), báo giá, ghi sổ, tính credit bán, báo cáo lãi/lỗ |
| `app/Support/helpers.php` → `studio_credit_cost_for()` | tra giá: **model (cụ thể → mọi cỡ) → giá của GÓI → mặc định toàn cục** — không bao giờ ném ra ngoài |
| `StudioController::queueGeneration()` | **đảo thứ tự**: chốt provider/model TRƯỚC, rồi mới tính giá và kiểm credit; thêm chặn **trần ảnh/ngày** (429 có cấu trúc) |
| `RenderImageJob` / `RenderVideoJob` | ghi giá vốn vào sổ sau mỗi lượt (video theo **giây**) |
| `PlanSeeder` + seed mới `ProviderPriceSeeder`, `ModelCreditCostSeeder` | bảng gói mới; **giá bán SINH TỪ giá vốn**, không chép tay |
| `php artisan studio:pricing` | in bảng vốn → credit → biên; `--sync` tính lại; **exit 1 nếu có dòng dưới 40 %** |
| `/api/admin/model-credits` · `/api/admin/profit` | sửa giá bán và xem lãi/lỗ **không cần SSH** |
| `AdminApp.vue` (tab Gói cước) | bảng giá theo model **sửa tại chỗ** · cảnh báo đỏ dòng dưới 40 % · báo cáo lãi/lỗ · badge **₫/credit** trên từng gói |

### 9.3 ⚠️ Bất biến đã BẮT ĐƯỢC MỘT LỖI THẬT ngay lần chạy đầu

Test `test_every_plan_keeps_the_minimum_credit_rate` phát hiện gói **`factory_season`** (Xưởng theo vụ):

```
3.290.000 ₫ ÷ 3.000 credit = 1.096,7 ₫/credit  ⇒  biên ở dòng đắt nhất chỉ 36,2 %
```

**Đã sửa bằng TĂNG GIÁ lên 3.600.000 ₫** (giữ nguyên 3.000 credit), vì *"3.000 credit/vụ"* là lời hứa
trên trang giá — giảm nó là lấy đi thứ đã hứa, còn giá chỉ ảnh hưởng lượt mua sau.
Phương án khác nếu chủ dự án muốn giữ giá: đặt `credits_per_month = 2.740` (một con số, không phải sửa mã).

### 9.4 Kết quả đo được sau khi thi hành

| Việc | Giá vốn thật | **credit** | Biên ở đơn giá tệ nhất |
|---|---|---|---|
| Tạo ảnh nhanh 1K | 78 ₫ | **1** | **93,5 %** |
| Sửa ảnh mô tả (nano-banana) | 1.035 ₫ | **2** | **56,9 %** |
| Sửa mask flux-pro **1K 4:5** | 1.300 ₫ | **2** | **45,8 %** |
| Sửa mask flux-pro **1K 1:1** | 2.600 ₫ | **4** | **45,8 %** |
| Sửa mask flux-pro **2K 4:5** | 5.200 ₫ | **8** | **45,8 %** |
| Sửa mask flux-pro **2K 1:1** | 6.500 ₫ | **10** | **45,8 %** |
| Video kling 5 giây | 9.100 ₫ | **13** | **41,7 %** |

**Mọi dòng ≥ 41,7 %** — không còn dòng nào dưới 40 %.

| Gói | Giá/tháng | credit | **₫/credit** | Biên **xấu nhất** | Trần ảnh/ngày |
|---|---|---|---|---|---|
| Miễn phí | 0 ₫ | 100 tặng một lần | — | — | 20 |
| Khởi nghiệp | 199.000 ₫ | **155** | **1.284 ₫** | 45,5 % ✅ | 60 |
| Chuyên nghiệp | 499.000 ₫ | **405** | **1.232 ₫** | 43,2 % ✅ | 150 |
| Studio | 1.490.000 ₫ | **1.240** | **1.202 ₫** | 41,8 % ✅ | 400 |
| Xưởng theo vụ | **3.600.000 ₫** | 3.000 | **1.200 ₫** | 41,7 % ✅ | 400 |

### 9.5 Kiểm chứng

```
vendor/bin/phpunit --no-coverage   →  OK (1320 tests, 10146 assertions)   ← trước đợt này: 1305
npm run build                      →  ✓ built in 1.43s
php artisan studio:pricing         →  exit 0 · mọi dòng ≥ 40 % · mọi gói ≥ 1.200 ₫/credit
```

15 bài mới trong `tests/Feature/PricingMarginTest.php`, mỗi bài khoá MỘT bất biến — trong đó bài
quan trọng nhất chạy **đường tiền thật** qua `/api/generate` và khẳng định:
cùng model `flux/dev`, **1K 1:1 tốn 2 credit** còn **1K 4:5 tốn 1 credit** — đúng hệ quả của việc fal
làm tròn megapixel lên. Sổ cái `credit_transactions` phải khớp đúng tổng đã trừ.

### 9.6 CÒN LẠI (chưa làm — ghi rõ để không tưởng đã xong)

| Việc | Vì sao còn |
|---|---|
| **Ghi từng LƯỢT THỬ của chuỗi dự phòng** | Hiện chỉ ghi được lượt THÀNH CÔNG — các lượt thử hỏng ở giữa do `ImageAIService` tự nuốt. Cột `attempt` đã sẵn sàng; tới khi làm xong thì báo cáo **đánh giá THẤP** chi phí thật của các lượt phải thử lại |
| **Đối chiếu hoá đơn DashScope thật** | `qwen-image-edit` ($0,045) và `-plus` ($0,03) chênh **50 %**; chưa xác nhận hệ thống đang gọi đúng model ID nào |
| **Cập nhật `PRICING.md`** §1.1/§2/§3 | Tài liệu cũ vẫn ghi giá Qwen sai **+50…75 %** — người sau sẽ tin số sai |
| **Hiện giá theo model ở giao diện Studio** (§4.10) | Khách chưa thấy *"Sửa ảnh · 8 credit"* trước khi bấm — nếu không làm thì đây là lỗi làm mất khách nhanh nhất |

---
## 10. TÓM TẮT MỘT CÂU

> **Mở full tính năng cho mọi gói là việc đã xong** — và nó đúng, vì **cổng chặn là credit chứ không phải
> tính năng**; nhưng **ta đang không biết mình lãi hay lỗ**: `PRICING.md` giả định giá Qwen sai
> **+50…75 %**, khiến biên thật rơi còn **49 / 41 / 32 %**, và **ba đường đang lỗ thật** —
> `flux-pro/fill` 2K (−262 %), `veo3` (−189 %), `flux-pro/fill` 1K (−45 %).
> Cách sửa **không phải tăng giá gói** (phá lời hứa với khách đang trả) mà là **ghi giá vốn theo từng lần
> gọi** rồi **đổi số credit theo model** — và **đã tính xong ở §4**: công thức **`credit = ceil(giá_vốn ₫ / 720)`**
> cho **mọi dòng ≥ 41,7 %**, kèm **một bất biến duy nhất khoá được bằng test: mọi gói phải giữ
> ₫/credit ≥ 1.200**; **`ext-sodium` không có trên production (đã đo)** nên
> phương án đúng là **3 tầng, tầng cuối lấy kết quả từ chính fal bằng `request_id` của ta** — chữ ký
> thành lớp thêm, không phải điều kiện sống còn; và **cron ngoài** là việc rẻ nhất trong cả kế hoạch:
> một URL mở khoá **7 tác vụ nền đang chết**, trong đó có **cấp credit hằng tháng cho khách trả tiền**.
