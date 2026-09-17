# FabrikAI · PHÂN TÍCH LUỒNG & THIẾT KẾ LẠI WORKFLOW THEO PERSONA · v2 · 2026-09-17

> **v1 → v2 thay đổi gì.** v1 (cùng file, cùng ngày) lập bảng *khoảng trống* theo persona. v2 đi sâu vào câu
> hỏi khó hơn mà v1 chưa trả lời: **thiết kế LUỒNG hiện tại sai ở đâu về mặt cấu trúc, và luồng đúng cho
> workflow thiết kế thời trang phải trông như thế nào.** v2 không lặp lại bảng khoảng trống của v1.
>
> **Cách đọc:** §1 hiện trạng ĐO ĐƯỢC · §2 chẩn đoán (vì sao luồng không khớp công việc thật) ·
> §3–§5 thiết kế · §6–§9 triển khai (lộ trình, đo lường, rủi ro).
>
> Mọi con số đo lại trên working tree FabrikAI ngày **2026-09-17**, sau khi app đã lên production
> (fabrikai.shop, MySQL, **300 test xanh** — đo lại 2026-09-17; bản gốc ghi 304).

---

## §1 — Hiện trạng (đo lại, không lấy từ báo cáo cũ)

### 1.1 Nền tảng đã đủ chắc để bán

| Hạng mục | Số đo |
|---|---|
| Route | **127** *(đo lại 2026-09-17 tại `eee1bba`; bản gốc ghi 131)* |
| Test | **300 test / 1.595 assertion xanh** (đo lại 2026-09-17; chạy `vendor/bin/phpunit` = OK) — bản gốc ghi 304/1627 |
| Bảng DB | **25** — 0 bảng thương mại điện tử còn sót |
| Bảo mật | SSRF · IDOR · path traversal · mass-assignment · XSS · brute-force **đã siết thật** |
| Vận hành | MySQL, queue, cache, lưới an toàn khi thiếu worker, cảnh báo vận hành trong log |
| Production | https://fabrikai.shop — verify 7 trang 200 · đăng nhập thật · **10 lượt API 200** (bản gốc ghi 8) · tạo ảnh đầu-cuối completed |

**Đây là tài sản thật.** Phần còn lại chỉ nói về *luồng*, không nói code có sạch hay không.

### 1.2 Luồng hiện tại — mô tả CHÍNH XÁC bằng code

**Luồng không được thiết kế như một luồng. Nó là 6 công cụ xếp ngang hàng trên một canvas.**
*(Lúc viết bản v2 là 9; 3 mục Pattern · Try-On · Thay người mẫu đã bị gỡ cùng ngày — xem bảng dưới.)*

`resources/js/studio/StudioApp.vue:41-49` — activity bar (**6 mục còn lại**; 3 mục 7·8·9 đã bị gỡ 2026-09-17):

| # | Nhãn UI | Card | Việc thật sự làm |
|---|---|---|---|
| 1 | Tạo ảnh | `SuggestCard` + `ConceptCard` | prompt → ảnh; **phân tích ảnh tham chiếu → prompt** |
| 2 | Fitting Room | `RefImageCard` | giữ trang phục, đổi người mẫu/bối cảnh |
| 3 | Sửa ảnh | `InpaintCard` + `RegionTools` | inpaint / xoá vùng / tái tạo nền |
| 4 | Ghép ảnh | `ComposeCard` | ghép nhiều ảnh/layer |
| 5 | Upscale | `UpscaleCard` | nâng độ phân giải |
| 6 | Kịch bản quay | `DirectorCard` | prompt → video catwalk |
| ~~7~~ | ~~Pattern~~ | ~~`PatternCard`~~ | **ĐÃ GỠ 2026-09-17** |
| ~~8~~ | ~~Try-On~~ | ~~`TryOnCard`~~ | **ĐÃ GỠ 2026-09-17** — thử đồ vẫn còn ở Fitting Room (chế độ "Thử đồ") |
| ~~9~~ | ~~Thay người mẫu~~ | ~~`SwapCard`~~ | **ĐÃ GỠ 2026-09-17** — kéo theo SwapModelJob + toàn bộ đường swap |

**Ba phát hiện cấu trúc — phần quan trọng nhất của tài liệu này:**

#### (a) "Con trỏ luồng" là LAYER ĐANG CHỌN trên canvas

Không có bàn giao tường minh giữa các card. App dùng **một trạng thái toàn cục**: layer đang active chính là
ảnh đầu vào cho công cụ kế tiếp.

- `store.js:298-299` — `upscaleSrc` = ảnh layer active **hoặc** `editSource` **hoặc** `preview.media_url`
- `store.js:1770` — `_setActive()`: layer `kind='source'` ⇒ gán `editSource`; layer có `genId` ⇒ gán `preview`
- `store.js:2820` — `editSource = { url, name }`

⇒ Mô hình luồng thật là **Photoshop**: *"ảnh đang chọn là ngữ cảnh"*. Nhất quán và hợp lý cho một **trình biên
tập**, nhưng nó chỉ giữ được **MỘT** artifact tại một thời điểm.

#### (b) Khái niệm "bước" (step) là di tích chết

- `store.js:24` `step: 1`; `store.js:439-442` đọc `?step=2|3&amp;id=` từ deep-link Thư viện
- `StudioApp.vue:75` — bước được **suy ra ngược** từ activity: `director→3`, `concept→1`, còn lại `→2`
- **Không có chỉ báo bước nào trong UI** — `grep "Bước"` ở tầng điều hướng = 0 kết quả

⇒ "step" là tàn dư của thời app có nhiều trang `/studio?step=N`. Nó **không dẫn dắt người dùng**; chỉ là số
thứ tự để khôi phục vị trí khi bấm từ Thư viện sang. **Người dùng không được hướng dẫn bước nào cả.**

#### (c) Điều hướng chỉ có 2 tầng

`store.js:261` `studioView: 'studio' | 'library'` ⇒ `StudioApp.vue:508,520,528`. Không có màn hình
"bảng công việc theo giai đoạn", không có màn hình "xuất bản".

### 1.3 Mô hình dữ liệu cho luồng — và chỗ hụt

| Đối tượng | Giữ gì | Vai trò trong luồng |
|---|---|---|
| `Generation` | user_id, project_id, type, **status** (pending/processing/completed/failed), prompt, media_url, base_image, mask_image, credits_cost, model, provider, resolution, ratio, duration, elapsed_ms, meta | **Artifact nguyên tử.** Trạng thái chỉ nói *job chạy xong chưa* — **không** nói *thiết kế đang ở giai đoạn nào* |
| `Project` | name, base_concept, brief, deadline, thumbnail_url, tags, color, sort, archived, settings, started_at, completed_at, **status** (draft→in_progress→review→approved→archived) + status_history | **Vùng chứa + state machine** |
| `project_assets` | project_id, studio_asset_id, **role** (reference·model·pose·background), sort | Chỉ áp cho **ảnh tải lên**, không áp cho ảnh sinh ra |

**Ba khoảng hụt về cấu trúc:**

1. **Trạng thái nằm ở PROJECT, không ở ARTIFACT.** `ProjectWorkflowService::STATES` (`ProjectWorkflowService.php:44-90`)
   cho **5 trạng thái cho cả dự án**. Một bộ sưu tập thật có 20 thiết kế, mỗi cái ở một giai đoạn khác nhau ⇒
   khi 1 thiết kế đã duyệt mà 19 cái còn đang làm thì project **không biểu diễn được**.
2. **Không có trạng thái "đã chọn".** Không cờ nào trên `Generation`/asset nói "đây là ảnh chốt". Output là
   **lưới phẳng** (`OutputModule.vue`) chỉ có: xem lớn · kéo vào canvas · lọc theo dự án. Không "chọn ảnh
   hero", không "loại", không "duyệt".
3. **Không có đối tượng cho LÔ (batch).** ~~9~~ **6** card đều là **một lần chạy = một output** (trừ biến thể 1–4 trong
   ConceptCard). "Làm 20 SKU" phải bấm tay 20 lần, không nơi nào theo dõi "lô này tới đâu".

### 1.4 Chất lượng nằm trong 89 chuỗi ký tự hardcode

| File | Số chuỗi prompt ≥120 ký tự |
|---|---|
| `StudioController.php` | **31** |
| `VirtualTryOnService.php` | **29** |
| `ImageAIService.php` | 8 |
| `StyleSuggestService.php` | 5 |
| `StylistService.php` | 3 |
| `VideoAIService.php` | 1 |
| **Tổng** | **89** |

Ví dụ `StudioController.php:716-721` — **prompt giá trị nhất của cả sản phẩm**, thứ biến ảnh chụp sản phẩm
thành ảnh người mẫu mặc *đúng sản phẩm đó*: *"reproduce this IDENTICAL garment on a new model: identical color,
identical fabric, identical pattern/print, identical cut… do not redesign, restyle, recolor"*.

Nó là **một chuỗi trong một file PHP**. Không có: phiên bản, ghi đè theo brand/loại trang phục, A/B, hay cách
nào để người dùng xem/sửa. ⇒ **Chất lượng không cải tiến được hệ thống, và thương hiệu không "mã hoá" được
phong cách của mình.**

---

## §2 — Chẩn đoán: 5 lệch pha cấu trúc

Không phải "thiếu tính năng". Đây là **năm lệch pha giữa mô hình của app và mô hình của công việc**.

| # | Lệch pha | App đang làm | Công việc thật cần | Hệ quả |
|---|---|---|---|---|
| **M1** | **Một con trỏ vs. một bộ sưu tập** | 1 layer active = 1 ngữ cảnh (§1.2a) | N artifact song song, mỗi cái có ngữ cảnh riêng | Không làm lô được; mọi thứ tuần tự bằng tay |
| **M2** | **Trạng thái ở vùng chứa vs. ở artifact** | 5 trạng thái cho cả Project | Mỗi thiết kế/ảnh có giai đoạn riêng; *chọn* và *duyệt* là hành động trên từng ảnh | Không biết "cái nào đã chốt"; không giao việc được; không đo được nút thắt |
| **M3** | **Công cụ vs. công việc** | 6 mục đặt tên theo *kỹ thuật* (Upscale, Inpaint, Fitting Room) | Người dùng nghĩ theo *việc*: "ra mắt sản phẩm mới", "đổi người mẫu cho 20 SKU", "lookbook chiến dịch" | Chủ shop không biết bắt đầu từ đâu. **Một phần M3 đã xử lý 2026-09-17**: gỡ Pattern/Try-On/Thay người mẫu ⇒ hết cảnh 3 tính năng trùng vai, activity bar 9 → 6 |
| **M4** | **Giá trị trong app vs. deliverable** | Output = lưới ảnh; tải **từng ảnh một** (`routes/web.php:111`) | 1 gói: N ảnh × đúng tỉ lệ kênh × tên file theo SKU × (tuỳ chọn) watermark + caption/hashtag | **Giá trị không rời khỏi app được** ⇒ không có lý do trả tiền |
| **M5** | **Prompt là code vs. prompt là tài sản** | 89 chuỗi hardcode (§1.4) | Prompt có phiên bản, ghi đè theo brand/loại trang phục, đo được cái nào tốt hơn | Chất lượng đứng yên; không bán được "phong cách của bạn" |

**Ba lệch pha vận hành đi kèm** (không phải kiến trúc, nhưng cũng chặn chuyển đổi):

- **Không có thông báo nào.** `grep "Mail::|Notification"` toàn `app/` = **0**. Render mất hàng phút (video
  tới ~8 phút) ⇒ người dùng phải **ngồi canh màn hình**. Đây là lý do bỏ app phổ biến nhất ở công cụ AI.
- **Vách cấu hình cao.** `SettingsApp.vue` 588 dòng / **47 ô nhập**, toàn về API key &amp; model registry;
  `config/studio.php` có **75 `env()`**. Chủ shop phải "làm dev" trước khi thấy giá trị đầu tiên.
- **Thước đo sai đối tượng.** Chỉ số duy nhất được theo dõi là **số test xanh**. Không có time-to-first-image,
  tỉ lệ export, chi phí/ảnh, thời gian mỗi bước duyệt.

---

## §3 — Thiết kế lại: các ĐỐI TƯỢNG NGHIỆP VỤ còn thiếu

Đây là gốc rễ. Không thêm đối tượng thì mọi cải tiến UI chỉ là trang trí.

### 3.1 Bảy đối tượng tối thiểu

| Đối tượng | Trả lời câu hỏi | Thuộc tính chính | Phục vụ |
|---|---|---|---|
| **Product (SKU)** | "Đây là sản phẩm nào?" | code, name, category, attributes, price, ảnh gốc | Chủ shop |
| **Collection** | "Đây là bộ nào, mùa nào?" | name, season, brief, deadline, brand kit | Designer / brand |
| **Look** (bộ ảnh) | "Bộ ảnh này của sản phẩm/bộ nào, cho kênh nào?" | product_id **hoặc** collection_id, channel, ratio, model_ref, trạng thái | Cả ba |
| **Shot** (ảnh trong look) | "Ảnh này đã chốt chưa, ai duyệt?" | look_id, generation_id, **state**, is_selected, note, sort | Cả ba |
| **Variant** | "Biến thể màu/chất liệu nào?" | look_id, colorway, fabric, **seed_lock**, parent_shot_id | Designer |
| **Run** (lô) | "Lô 20 ảnh tới đâu, tốn bao nhiêu?" | type, params, total, done, failed, credits_spent, status | Cả ba |
| **ExportBundle** | "Đã xuất gì, cho kênh nào?" | look_id, preset, files[], caption, watermark, created_at | Chủ shop / brand |

> **Điểm mấu chốt:** `Shot` là `Generation` **được nâng cấp thành thực thể có trạng thái và được chọn**.
> Đây là thay đổi nhỏ nhất tạo khác biệt lớn nhất: biến "lưới ảnh" thành "bảng chọn ảnh".

### 3.2 State machine cho ARTIFACT (thay vì chỉ cho Project)

```text
                   ┌──────────── (tạo lại / thử biến thể) ────────────┐
                   ▼                                                  │
  idea ──▶ drafted ──▶ selected ──▶ fitted ──▶ campaign_ready ──▶ approved ──▶ published
             │            │           │              │               │
             └────────────┴───────────┴──────────────┴───▶ rejected ─┘
                                                            (kèm lý do)
```

- **Project giữ vai trò VÙNG CHỨA**, không giữ trạng thái chi tiết. Project "approved" = *mọi shot bắt buộc đã approved*.
- **`selected`** là hành động rẻ nhất và giá trị nhất: designer bỏ 200 ảnh, giữ 12. Hiện chưa có cờ này.
- **`approved`/`rejected`** gắn được **bình luận + ghim trên ảnh** ⇒ duyệt thành hành động thật, không chỉ
  đổi trạng thái dự án.

### 3.3 Prompt trở thành TÀI SẢN có phiên bản

Thay 89 chuỗi hardcode bằng bảng `prompt_templates`:

```text
key             | scope                | version | body                                 | is_active
────────────────┼──────────────────────┼─────────┼──────────────────────────────────────┼──────────
garment.lock    | global / brand / cat | v3      | "reproduce this IDENTICAL garment …" | ✓
lookbook.studio | brand:ABC            | v2      | "editorial studio, soft box, …"      | ✓
pattern.tile    | category:dress       | v1      | "seamless repeating tile, 300dpi, …" | ✓
```

Lợi ích: (1) sửa chất lượng **không cần deploy**; (2) brand ghi đè để ra "chất riêng"; (3) ghi được **cái nào ra ảnh
tốt hơn** ⇒ vòng cải tiến chất lượng có số liệu; (4) giảm rủi ro sửa prompt làm vỡ luồng khác.

---

## §4 — Luồng thiết kế lại theo từng persona

### 4.0 — Định lượng ma sát: "6 ảnh người mẫu cho 1 sản phẩm" tốn bao nhiêu thao tác

*Số thao tác dưới đây suy ra từ đọc code/UI (không phải bấm đồng hồ bấm giây) — nhưng mọi giới hạn đều là
sự thật đã kiểm trong code.*

**Bốn ràng buộc đã kiểm chứng, quyết định toàn bộ chi phí của luồng:**

| Ràng buộc | Bằng chứng | Hệ quả cho người dùng |
|---|---|---|
| **Một lần chạy tối đa 4 ảnh** | `variants` validate `min:1, max:4` (`StudioController.php:641`), UI chỉ có chip 1·2·3·4 (`RefImageCard.vue:372`), mặc định `ref(1)` (`:25`) | Cần 6 ảnh ⇒ **tối thiểu 2 lần chạy**, mỗi lần cấu hình lại |
| **Không có preset tỉ lệ theo kênh** | không có nơi nào map `1:1 Shopee · 4:5 IG · 9:16 TikTok` | Phải crop thủ công **ngoài app** cho từng kênh |
| **Tải từng ảnh, tên file là UUID** | `download()` = `response()->download($abs, basename($abs))` — `basename` là UUID sinh ra (`6045e90a-6f44-…jpg`) | Phải **đổi tên thủ công theo SKU**; không có tải hàng loạt (chỉ có `librarySelectAll()` để xoá) |
| **Không có mô tả/caption** | `ProductAIService` **ĐÃ BỊ GỠ** (`729ceb0` — gỡ sạch lớp TMĐT) ⇒ **không còn code nào** để "hồi sinh"; muốn có phải **XÂY MỚI** (hoặc khôi phục tạm từ `git show 729ceb0^:app/Services/ProductAIService.php`) | Phải viết mô tả + hashtag **ngoài app** |

**Luồng hôm nay (ước lượng):**

```text
  1. Tải ảnh sản phẩm lên canvas ...................... ~4 thao tác
  2. Chọn layer vừa thêm ............................. 1
  3. Chuyển activity "Fitting Room" .................. 1
  4. Cấu hình (người mẫu · pose · nền · độ giống · model) ~3–6
  5. Chọn số ảnh = 4 rồi bấm tạo ..................... 2
  6. Chờ kết quả (stub ~50ms · provider thật 30–90s)
  7. Lặp 4–5 cho 2 ảnh còn lại (cấu hình lại) ........ ~5–8
  8. Mỗi ảnh: mở viewer → Tải (file tên UUID) ........ 2 × 6 = 12
  9. Đổi tên file theo SKU .......................... NGOÀI APP
 10. Crop theo tỉ lệ từng kênh ...................... NGOÀI APP
 11. Viết mô tả + hashtag ........................... NGOÀI APP
 12. Upload từng ảnh lên Shopee / TikTok ............ NGOÀI APP

  ⇒ ~28–34 thao tác TRONG app + 4 việc phải rời app
```

**Luồng thiết kế lại:**

```text
  1. Wizard bước 1: chụp/tải 1 ảnh + mã SKU .......... 3
  2. Wizard bước 2: chọn gói (số ảnh · người mẫu · tỉ lệ kênh) 4
  3. Chờ lô chạy — CÓ TIẾN TRÌNH + THÔNG BÁO ......... 0 (rời màn hình được)
  4. Wizard bước 3: tick 6 ảnh giữ lại ............... 6
  5. Wizard bước 4: Xuất gói (zip theo kênh + tên SKU + caption) 2

  ⇒ ~15 thao tác, 0 việc phải rời app
```

> Đây là lý do §6 xếp **`Shot.state` + `Run` (batch) + `ExportBundle`** vào Đợt 1: ba thứ đó biến
> ~30 thao tác + 4 công cụ ngoài thành ~15 thao tác trong một màn hình.

---

### A. CHỦ SHOP — luồng "Ra mắt một sản phẩm" (mục tiêu 10 phút/SKU)

**Hiện tại:** người dùng tự đoán phải dùng card nào trong ~~9~~ **6** card, làm từng ảnh, tải từng ảnh, tự viết mô tả.

**Thiết kế mới — wizard 4 bước, đặt tên theo VIỆC chứ không theo kỹ thuật:**

```text
[1] Sản phẩm   →  [2] Bộ ảnh        →  [3] Chọn ảnh        →  [4] Xuất & đăng
    tải 1 ảnh       chọn gói:            lưới 20 ảnh,         zip theo kênh
    chụp từ ĐT      - số ảnh (4/8/12)    tick chọn 6          + tên file theo SKU
    + giá/mã SKU    - người mẫu          kéo thả sắp thứ tự   + caption/hashtag
                    - pose/nền          (state=selected)     + watermark/logo
                    - tỉ lệ kênh                             + chi phí đã dùng
```

| Bước | Cần xây | Tái dùng gì đã có |
|---|---|---|
| 1 | `Product` + upload từ điện thoại | `RefImageCard`, `StudioLibraryService` |
| 2 | **`Run` (batch)**: N ảnh × tham số, tiến trình, retry, chi phí | Job hiện có (`RenderImageJob`) + queue đã dựng |
| 3 | **`Shot.state` + `is_selected`** + lưới chọn | `OutputModule.vue` (đổi từ "xem" thành "chọn") |
| 4 | **`ExportBundle`**: zip · preset tỉ lệ · tên file SKU · watermark · caption | ZipArchive, GD/Imagick (đã có); **caption/mô tả: chưa có gì** (ProductAIService đã bị gỡ — phải xây mới) |

**Ba việc rẻ mà đòn bẩy lớn:**

1. ~~**BẬT `STUDIO_SWAP_ENABLED`**~~ ✅ **ĐÃ QUYẾT 2026-09-17: GỠ HẲN** — `eee1bba` xoá `SwapCard.vue` + `swapModel()` + `SwapModelJob` + 3 route `swap-*`. Muốn làm lại: `git revert eee1bba` rồi sửa cho đủ tốt (đừng để nửa sống nửa chết). *(Câu gốc ở đây khuyên "BẬT", trái với §6.1 cùng file — đã sửa.)*
2. **XÂY MỚI module "Viết mô tả + hashtag"** ngay tại Look. ⚠️ Không thể "hồi sinh" `ProductAIService`: file **đã bị gỡ ở `729ceb0`**; chỉ có thể khôi phục tạm bằng `git show 729ceb0^:app/Services/ProductAIService.php` rồi bỏ phụ thuộc setting `about_*` của storefront (đã không còn UI).
3. **Thông báo khi render xong** (email trước, Zalo/Telegram sau) ⇒ người dùng rời màn hình được.

### B. DESIGNER — luồng "Bộ sưu tập" (brief → bàn giao)

```text
brief &amp; moodboard ─▶ concept (n biến thể, SEED LOCK) ─▶ hoạ tiết &amp; colorway (MA TRẬN)
        ─▶ fitting ─▶ lookbook (MODEL LOCK) ─▶ duyệt có bình luận ─▶ tech pack / bàn giao
```

Bốn thứ phải xây, xếp theo mức đau:

| Ưu tiên | Việc | Vì sao là *luồng* chứ không phải tính năng |
|---|---|---|
| **1** | **Ma trận biến thể + khoá seed** | 1 thiết kế × 4 màu × 2 chất liệu = **8 lần bấm tay** hôm nay, và **form lệch nhau** vì không khoá seed. Đây là khác biệt giữa "công cụ vẽ" và "công cụ thiết kế" |
| **2** | **Model lock cho lookbook** | Ảnh trong cùng lookbook phải **cùng một người mẫu**. Hiện không có cách nào giữ |
| **3** | **Lineage / versioning** | Không biết "sửa tiếp từ ảnh nào"; không so v1/v2/v3; không quay lại bước trước. `Generation.meta` đã có JSON nhưng **chưa có UI lịch sử** |
| **4** | **Review có bình luận + ghim trên ảnh** | Đã có 5 trạng thái + audit `status_history` + cờ chống tự duyệt (rất tốt) — nhưng **không nói được "sửa chỗ vai"**. Duyệt mà không ghim được thì designer phải hỏi lại |

**Pattern phải dùng được cho in:** ⚠️ **`PatternCard.vue` ĐÃ BỊ GỠ** ở `eee1bba` (cùng Try-On và Thay người mẫu) — đoạn dưới đây mô tả trạng thái trước khi gỡ. Cần làm nếu muốn khôi phục: kiểm tra **lặp liền mạch (seamless/tileable)** — điều kiện sống còn để in vải — và xuất **tile/DPI/khổ**. **Đây là quyết định cần ghi sổ: làm lại đúng cách hay tuyên bố không phục vụ in ấn.**

### C. CHỦ DOANH NGHIỆP / BRAND CÓ TEAM — luồng "Nhiều người, nhiều brand"

| Ưu tiên | Việc | Ghi chú |
|---|---|---|
| **1** | **Brand kit** (logo, watermark, palette, tone of voice, prefix/suffix, người mẫu mặc định) | Hiện `prompt_prefix/suffix` là setting **toàn cục** — agency nhiều brand **không dùng chung được** |
| **2** | **Sổ chi phí** (giá provider × model × lượt → chi phí look/dự án/tháng + hạn mức) | `image_credits=1`, `video_credits=10` là **phẳng** (`config/studio.php:12,33`) ⇒ không biết 1 lookbook tốn bao nhiêu ⇒ **không bán được cho doanh nghiệp** |
| **3** | **Team/workspace + role chi tiết** (owner · designer · reviewer · marketer · khách duyệt) | Hiện `admin/customer/super_admin` và **mọi dữ liệu gắn `user_id`** ⇒ 1 deployment = 1 công ty |
| **4** | **Link duyệt cho khách không cần tài khoản** | Chủ shop gửi khách xem mẫu — hiện bắt buộc có tài khoản |
| **5** | **Provenance/consent** (face swap + try-on trên người thật) | Cần: consent, watermark nguồn, thời hạn lưu, xoá theo yêu cầu. Rủi ro pháp lý thật |
| **6** | **DAM/webhook + health/metrics + plan/seat/hoá đơn** | Đóng vòng vận hành |

---

## §5 — Kiến trúc thông tin mới (điều hướng)

**Hiện tại:** 2 tầng — `Studio` (canvas + ~~9~~ **6** card) · `Library`. Không có tầng nào cho *công việc*.

**Đề xuất:** 4 tầng, mỗi tầng trả lời một câu hỏi khác nhau.

| Tầng | Câu hỏi nó trả lời | Nội dung | Trạng thái |
|---|---|---|---|
| **Board** (nhà) | *"Hôm nay tôi phải làm gì?"* | Kanban theo **Shot.state**: Cần chọn · Cần fitting · Chờ duyệt · Đã duyệt · Đã xuất. Gộp theo Product/Collection | **MỚI** — thay `step` chết |
| **Look** (bộ ảnh) | *"Bộ ảnh này tới đâu rồi?"* | Lưới shot + chọn + sắp thứ tự + chạy lô + xuất | **MỚI** |
| **Studio** (xưởng) | *"Sửa ảnh này thế nào?"* | Canvas + ~~9~~ **6** card (giữ nguyên — đây là điểm mạnh) | đã có |
| **Library / Publish** | *"Tài sản của tôi ở đâu, đã xuất gì?"* | Thư viện + gói xuất + caption | Library có; Publish **MỚI** |

> **Nguyên tắc:** *Studio là nơi BIÊN TẬP một artifact. Board và Look là nơi ĐIỀU HÀNH nhiều artifact.*
> Hiện app chỉ có tầng biên tập ⇒ người dùng phải tự nhớ mình đang ở đâu trong công việc.

**Việc cần XOÁ / GỘP trong lần thiết kế lại này:**

1. ~~**Gộp 3 tính năng trùng vai**~~ ✅ **ĐÃ LÀM 2026-09-17** (commit `eee1bba`): thay vì gộp, người dùng quyết
   **GỠ HẲN** `PatternCard` · `TryOnCard` · `SwapCard` (activity bar 9 → 6) và bỏ chip "thay khuôn mặt"
   trong `ComposeCard`. Thử đồ vẫn còn: **Fitting Room → chế độ "Thử đồ"** (đường `/api/refgen`, không phải `/api/tryon`).
2. **Xoá `store.step`** và logic suy ra bước (`StudioApp.vue:75`) — thay bằng `Shot.state` thật.
3. **Việt hoá nhãn đang lai Anh–Việt**: `Fitting Room`, `Upscale`, `Pattern`, `Try-On`, `Studio`.
4. **Hai chế độ UI theo persona**: *"Đơn giản"* ẩn thuật ngữ (creative level, seed, negative prompt, texture,
   model registry) và *"Chuyên sâu"* hiện đủ. Một app, hai mức trần.

---

## §6 — Lộ trình (theo giá trị/chi phí, có phụ thuộc)

### Đợt 0 — Bịt rò rỉ giá trị (làm ngay, chi phí thấp)

| # | Việc | Vì sao trước tiên |
|---|---|---|
| 0.1 | ~~**Bật/ gỡ dứt điểm Thay người mẫu**~~ ✅ **ĐÃ QUYẾT 2026-09-17: GỠ HẲN** (`eee1bba`) — xoá card + `swapModel()` + `SwapModelJob` + `executeSwapFromGeneration()` + 7 key config chết. Khôi phục bằng `git revert eee1bba` nếu muốn làm lại |
| 0.2 | **Thông báo khi render xong** (email) | Render 3–8 phút mà không có thông báo ⇒ người dùng phải canh màn hình |
| 0.3 | **Cron worker** (đang thiếu trên production) | Ảnh xong sau ~90–100s thay vì ~1 phút; video càng chậm |
| 0.4 | **Đo lường cơ bản** (§7) | Không có số thì mọi ưu tiên sau đây là cảm tính |

### Đợt 1 — "Last mile" cho chủ shop *(giá trị/chi phí cao nhất)*

1. **`Shot.state` + `is_selected`** — thay đổi nhỏ nhất, mở khoá mọi thứ sau (§3.1)
2. **`Run` (batch)** + tiến trình + chi phí
3. **Wizard "Ra mắt sản phẩm"** 4 bước (§4A)
4. **`ExportBundle`**: zip · tên file SKU · preset kênh · watermark · caption
5. **XÂY MỚI module mô tả + hashtag** (⚠️ `ProductAIService` đã bị gỡ ở `729ceb0`, không còn để "hồi sinh")
6. **`prompt_templates`** (§3.3) — hạ tầng cho chất lượng

### Đợt 2 — Nhân đôi năng lực cho designer

7. Ma trận biến thể + khoá seed · 8. Model lock · 9. Lineage/versioning · 10. Review có bình luận + ghim ·
11. **Pattern seamless + xuất in — CHỜ QUYẾT ĐỊNH** (card đã bị gỡ `eee1bba`; nếu làm lại thì phải làm đúng: seamless + tile/DPI) · 12. Focus trap **19** overlay, hiện **16 chỗ** thiếu `tabindex="-1"`, `grep focus-trap` = **0** (hoàn tất a11y còn dở)

### Đợt 3 — Bán được cho doanh nghiệp

13. Brand kit theo brand/collection · 14. Sổ chi phí + hạn mức · 15. Team/workspace + role chi tiết ·
16. Link duyệt cho khách · 17. Provenance/consent · 18. DAM/webhook + plan/seat

---

## §7 — Thước đo (hiện chỉ đo test/route)

Đo **đếm sự kiện**, KHÔNG log nội dung prompt/ảnh.

| Chỉ số | Trả lời câu hỏi của ai | Ngưỡng "đủ tốt" gợi ý |
|---|---|---|
| Time-to-first-image (đăng ký → ảnh đầu) | Chủ shop có bỏ ở vách cấu hình? | **&lt; 5 phút** |
| Thời gian hoàn thành 1 SKU (ảnh gốc → gói xuất) | Luồng mới có nhanh hơn làm tay? | **&lt; 10 phút** |
| Tỉ lệ dùng wizard Look | Luồng mới có được dùng không | &gt; 50% lượt tạo |
| Số ảnh/look · **tỉ lệ EXPORT** | Giá trị có rời khỏi app? | export &gt; 60% look |
| Card dùng nhiều nhất · tỉ lệ lỗi theo provider/model | Bỏ công vào đâu; model nào hay hỏng | lỗi &lt; 5% |
| **Chi phí/ảnh · chi phí/SKU** | Chủ doanh nghiệp có mua được | biên &gt; 70% |
| Tỉ lệ dự án tới `approved` · thời gian mỗi bước | Nút thắt ở duyệt hay ở tạo | — |
| Retention 7/30 ngày | Sản phẩm hay chỉ là demo | D7 &gt; 30% |

---

## §8 — Rủi ro nếu giữ nguyên

1. **Một UI thuật ngữ kỹ thuật cho 3 persona** ⇒ designer chịu được, chủ shop bỏ ở màn hình thứ hai.
2. **Ba tính năng trùng vai mà cái mạnh nhất đang tắt** ⇒ người dùng không biết chọn gì.
3. **Không export / không mô tả** ⇒ mọi giá trị dừng trong app; **không có lý do trả tiền**.
4. **Trạng thái ở project** ⇒ 20 thiết kế ở 20 giai đoạn là **không biểu diễn được**; quản lý dự án chỉ để đẹp.
5. **89 prompt hardcode** ⇒ chất lượng đứng yên; không bán được "chất riêng của brand"; sửa prompt dễ vỡ luồng khác.
6. **Không có sổ chi phí** ⇒ không bán được cho doanh nghiệp **dù nền bảo mật đã đủ tốt để bán**.

---

## §9 — Cách cập nhật file này

1. Đọc **sau** `STUDIO_REVIEW_PROGRESS.md` (trạng thái) và **trước** khi lập kế hoạch vòng mới.
2. Mọi đề xuất phải neo vào **bằng chứng file:dòng** hoặc một chỉ số ở §7 — không nhận đề xuất cảm tính.
3. Khi một đề xuất được làm: chuyển sang `STUDIO_REVIEW_PROGRESS.md` §2 (kèm commit), giữ ở đây phần *lý do sản phẩm*.
4. Đo lại §1 bằng read/grep trên working tree và **ghi rõ ngày đo**.
5. `STUDIO_REVIEW_PRODUCT.md` đã được thêm vào `.gitignore` cùng nhóm `STUDIO_REVIEW*.md` (repo PUBLIC) —
   tài liệu mới cùng họ phải thêm dòng tương ứng.
