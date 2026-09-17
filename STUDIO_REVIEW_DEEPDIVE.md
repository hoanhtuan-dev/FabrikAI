# FabrikAI · PHÂN TÍCH SÂU: TÀI LIỆU · PIPELINE · DOANH NGHIỆP (trọn bộ: tài liệu · pipeline · UX · doanh nghiệp · thị trường) · 2026-09-17

> **File này bổ sung cái 3 tài liệu kia không có: KIỂM CHỨNG ĐỘC LẬP + lớp THỊ TRƯỜNG.**
> - `STUDIO_REVIEW.md` = audit kỹ thuật/an toàn · `STUDIO_REVIEW_PROGRESS.md` = sổ trạng thái · `STUDIO_REVIEW_PRODUCT.md` v2 = thiết kế lại luồng.
> - File này = *"những gì cả ba đang nói SAI hoặc đã cũ"* + *"pipeline thật sự tạo ra gì"* + *"doanh nghiệp cần gì"*.
>
> **Neo đo (bắt buộc trích kèm khi tham chiếu):** HEAD `eee1bba` · 01:40 2026-09-17 · **47 commit** · **127 route** (`php artisan route:list`) · **300 test / 1.595 assertion · 34 file test** (chạy thật: `vendor/bin/phpunit` OK) · MySQL production `fabrikai.shop`.
> **⚠️ Cảnh báo race:** trong 40 phút tôi đọc tài liệu, repo đã nhận **9 commit mới** từ một phiên làm việc song song (kể cả commit gỡ 3 card và một lần deploy). Mọi số liệu dưới đây chỉ đúng tại HEAD ghi trên — **đo lại trước khi dùng**.
> Quy ước bằng chứng: ✅ = tôi đã tự đọc lại code · ⚠️ = có bằng chứng file:dòng do vòng phân tích cung cấp, tôi **chưa** tự đọc lại.
> **ĐÃ VÁ (2026-09-17, `66023d7`):** nhóm lỗi tiền của file này (M-c · M-d · M-h) đã hợp nhất thành **một helper CAS duy nhất** (`studio_finalize_generation`) + 9 test + mutation-test 3 hướng. Baseline nay **309 test / 1.629 assertion**.
> **SỔ XÁC MINH ĐỘC LẬP (6 vòng quét song song) nay ở `STUDIO_REVIEW.md` §10** — gồm phán quyết từng mục T1–T17 / M01–M22 / N-list, danh sách tham chiếu chết, và 10 việc MỚI (M-a…M-j) phát hiện trong đợt này.

---

## §0 — TÓM TẮT ĐIỀU HÀNH: 10 việc, xếp theo mức CHẶN

| # | Việc | Loại | Vì sao phải trước |
|---|---|---|---|
| 1 | **Mở đường cho người tự đăng ký** (đổi role/quyền HOẶC render thông báo rõ ràng) — §5.1 | CHẶN | Hiện 100% người tự đăng ký bị 403 im lặng ⇒ sản phẩm không có đường self-serve |
| 2 | **Nói thật mọi chỗ đang im lặng**: chế độ DEMO (§6.2/0.4), 2K vô hiệu, 4:5→3:4, tiến trình mô phỏng (§5.3), ảnh mặt bị bỏ (§3.2 S5) | CHẶN | Một khách gặp ca "sửa ảnh trả lại ảnh cũ mà báo thành công" là mất khách |
| 3 | **Mobile dùng được** (drawer Kết quả đang rỗng; không có Nguồn/Thư viện < 1024px) — §5.2 | CHẶN | Chủ shop chụp bằng điện thoại |
| 4 | **Hợp nhất 5 đường hoàn credit thành 1 hàm có CAS** + test đua — §4.2 | CHẶN | Lỗi tiền thật; hiện `cancel()` có thể hoàn 2 lần |
| 5 | **`shots` + `runs` + ExportBundle** (zip · preset kênh · tên file SKU · caption) — §6.3 | GIÁ TRỊ | "Last mile": không có nó thì giá trị không rời khỏi app, không có lý do trả tiền |
| 6 | **Channel Validator (pre-flight)** trước khi đăng: kích thước/nền trắng/tỉ lệ/watermark — §11.4 | KHÁC BIỆT | Không đối thủ nào làm cho sàn VN; dễ demo, tạo niềm tin, chặn lỗi "bị sàn ẩn" |
| 7 | **Thông báo khi render xong** + cron worker thật trên host | GIÁ TRỊ | Render 3–8 phút; hiện phải canh màn hình; prod còn phụ thuộc lưới an toàn 90s |
| 8 | **Mô tả + caption + hashtag** (XÂY MỚI — không phải "hồi sinh" `ProductAIService`) | GIÁ TRỊ | Chủ shop phải rời app cho việc này |
| 9 | **Ma trận colorway + khoá seed** và **model lock** — §6.4 | GIÁ TRỊ (designer) | Ranh giới giữa "công cụ vẽ" và "công cụ thiết kế" |
| 10 | **Chốt có ghi sổ**: Pattern (in ấn) bỏ hẳn hay làm lại; PWA bỏ hẳn hay làm lại | QUYẾT ĐỊNH | Đang lửng lơ ⇒ tài liệu/đào tạo nói sai năng lực sản phẩm |

## §1 — Kiểm toán bộ tài liệu: điểm mù lớn nhất là tài liệu TỰ MÂU THUẪN

Ba tài liệu đang mô tả **ba thời điểm khác nhau của cùng một repo**, và không tài liệu nào ghi rõ mình neo vào đâu.

### 1.1 `STUDIO_REVIEW.md` — đóng băng ở 01:22, giờ sai ở đúng các mục lớn nhất

| Nội dung trong tài liệu | Thực tế tại `eee1bba` | Đánh giá |
|---|---|---|
| "136 route · 112 auth+admin+nostore" | **127 route** ✅ | sai |
| "PHP 113 file / 21.298 dòng" | app/ nhỏ hơn nhiều sau khi gỡ sạch lớp TMĐT ✅ | sai |
| §1 liệt kê **T1, T2, T5, T6, T7 = MỞ** | cả 5 **đã đóng** (sổ `2bis` ghi rõ, production đã chạy) ✅ | sai |
| "N11 còn mở: `sleep(5)` tới 8 phút trong request" | đã xử lý bằng cờ queue + lưới an toàn inline 90s ✅ | sai |
| §6.3 N1–N10 "bề mặt mới chưa review" | phần lớn đã vá; 3 card liên quan (Pattern/Try-On/Swap) **đã bị gỡ hẳn** ✅ | lỗi thời |
| §7 mục 3 "báo cáo tự nhận phủ ~100% vẫn sót 9 file" | **bài học này giờ áp vào chính nó**: tài liệu không tự kiểm lại sau 9 commit ✅ | tự mâu thuẫn |

### 1.2 `STUDIO_REVIEW_PROGRESS.md` — §1/§2 và §2bis nói hai chuyện trái nhau

Đây là lỗi nặng nhất, vì §7 của chính file dặn *"đọc file này TRƯỚC"*:

| Chỉ số | §1/§2 (dòng 10–49) | §2bis (cùng file) | Thực tế `eee1bba` |
|---|---|---|---|
| Git | "**KHÔNG có `.git`**" | có `origin/main`, đã push | có, 47 commit ✅ |
| Tests | "**KHÔNG có `tests/`** (phpunit.xml dangling)" | 293 → 300 test xanh | 35 file test, **300 test** ✅ |
| Route | 139 | 131 → 127 | **127** ✅ |
| Số liệu PHP | 72 file / 17.319 dòng | — | đã giảm tiếp sau khi gỡ TMĐT ✅ |
| T5/T6/T7 | **MỞ** | ✅ xong hết | xong ✅ |
| Production | "chưa có deploy nào" | **fabrikai.shop đang chạy** | đang chạy ✅ |

⇒ Người đọc theo đúng quy trình sẽ nhận **trạng thái cũ hơn 9 commit và một lần lên production**.

### 1.3 Số liệu không có nguồn chuẩn duy nhất

| Nguồn | Route | Test |
|---|---|---|
| `DEPLOY.md` §7 | ~139 | **285** |
| Sổ `2bis` (giữa) | 131 → 127 | **293 → 300** |
| `STUDIO_REVIEW_PRODUCT.md` v2 §1.1 | **131** | **304** |
| `STUDIO_REVIEW.md` | 136 | (không đo) |
| **Đo lại hôm nay** ✅ | **127** | **300** |

**Đề xuất:** ghi một dòng "nguồn chuẩn" ở đầu cả 3 file, và lệnh đo kèm theo:
`php artisan route:list | wc -l` · `vendor/bin/phpunit --list-tests | wc -l` · `git rev-parse --short HEAD`.

### 1.4 Claim ĐÃ CHẾT nhưng vẫn được v2 kế thừa (kế thừa từ bản v1 của chính tôi)

| Claim trong v2 | Sự thật tại `eee1bba` | Hệ quả |
|---|---|---|
| "**`ProductAIService` (800+ dòng) mồ côi** — hồi sinh thành nút Viết mô tả" (§4.0, §4A, Đợt 1 #5) | File **không còn tồn tại** — `app/Services/` chỉ có 11 file, không có `ProductAIService.php` ✅ (đã gỡ ở `729ceb0`) | "Hồi sinh" là **bất khả thi**; phải viết lại thành hạng mục xây mới, hoặc khôi phục từ `git show 729ceb0^:app/Services/ProductAIService.php` |
| "**BẬT `STUDIO_SWAP_ENABLED`**" (§4A) | §6.1 **cùng file** ghi đã gỡ hẳn | tự mâu thuẫn trong một file |
| "`PatternCard.vue` chỉ là textarea…" (§4B) + Đợt 2 #11 "Pattern seamless + xuất in" | Card đã **bị gỡ** (`eee1bba`) | cần **quyết định có ghi sổ**: Pattern là năng lực lõi của designer — bỏ hẳn hay làm lại đúng cách? |
| §1.1 "131 route / 304 test" | 127 / 300 ✅ | cũ sau đúng một commit |

### 1.5 Hạ tầng tài liệu

- `.gitignore` chặn theo **tên literal** trong khi `DEPLOY.md` mô tả là wildcard `STUDIO_REVIEW*.md` ⇒ mọi tài liệu mới cùng họ **sẽ lên repo public**. (Đã sửa thành wildcard trong lần này.)
- Quy ước "2 bản gương" + **nhiều phiên chạy song song** = race thật: bản v1 của tôi bị thay bằng v2 trong vài phút, không ai thông báo. §7.6 của sổ đã cảnh báo đúng — nhưng cảnh báo suông thì không chặn được.

---

## §2 — Trạng thái đo được và các QUYẾT ĐỊNH SẢN PHẨM đã xảy ra trong lúc rà soát

| Việc đã xảy ra | Commit | Ý nghĩa sản phẩm |
|---|---|---|
| Gỡ **sạch** dấu vết TrillfaShop (19 model · 3 service · 27 migration · 31 helper · 2 endpoint · tab Sản phẩm · demo seeder) | `729ceb0` | Hết "rác mồ côi gây hiểu nhầm định hướng" tôi từng nêu ở v1 ✅ |
| Gỡ **3 activity**: Pattern (họa tiết) · Try-On · Thay người mẫu (+ chip thay khuôn mặt trong Ghép ảnh) | `eee1bba` | 6 activity còn lại; thử đồ **còn** dưới dạng chế độ trong Fitting Room (đường `/api/refgen`) ✅ |
| Queue: dispatch ngay lúc tạo + lưới an toàn inline sau 90s + dọn job chết | `f7710bb` `2bb650b` `57c4c0e` | Bịt đúng lỗ "tạo ảnh rồi đóng tab = kẹt pending + mất credit" ✅ |
| Sửa cache Eloquent Collection → 500 ở lần gọi thứ 2; `LOG_LEVEL` nuốt cảnh báo vận hành | `da2545a` / vòng 28 | Hai lỗi chỉ xuất hiện ở production thật, test cũ không bắt |
| Chuyển production sang MySQL + deploy `fabrikai.shop` | sổ vòng 28 | App đã có người dùng thật ⇒ mọi đề xuất dưới đây phải tính tới **di trú dữ liệu**, không phải greenfield |

**Dư lượng cần dọn (tôi tự đọc):**
- **Nút "Cài đặt ứng dụng" (PWA) vẫn còn trong SPA** — `StudioApp.vue:54-64,506` vẫn nghe `beforeinstallprompt` và render nút, trong khi `public_html/manifest.json` + `sw.js` **đã bị xoá** ⇒ trình duyệt sẽ không bao giờ bắn sự kiện đó ⇒ **UI chết** ✅.
- `pageBoot.js:20-28` vẫn giữ hàm gỡ service worker cũ (có chủ đích, đúng) — nhưng chính vì thế mà "cài app" không còn đường quay lại: cần chốt PWA là **bỏ hẳn** hay **làm lại đúng**.

---

## §3 — Kiểm toán năng lực PIPELINE: "thao tác này thật sự tạo ra cái gì?"

### 3.1 Bảng thật (mặc định trong `config/studio.php` + `ImageAIService`)

| Thao tác | Provider/model mặc định | Hậu kỳ | Trần thật |
|---|---|---|---|
| Tạo ảnh 2D (`/api/generate`) | Qwen/Wan/Gemini (nhánh `fal/replicate` **không nối** — `ImageAIService.php:196-197` ⚠️) | không QA, không sharpen | **1328² / 1472×1104 / 1104×1472 / 1664×928 / 928×1664** ✅ |
| Refgen + Thử đồ (`/api/refgen`) | chỉ nhóm DashScope | — | ảnh nguồn hạ về ≤1600 ✅ |
| Sửa ảnh/Inpaint (`/api/inpaint`) | Qwen edit | ép về đúng size nguồn; có mask thì composite feather 3% | trần **1600px** cạnh dài ✅ |
| Vùng chọn (`/api/generations/{id}/region`) | Qwen edit trên **crop** | paste lại toạ độ gốc; AI no-op ⇒ **tự nội suy viền** | crop ≤2048 ⚠️ |
| Ghép ảnh / Xoá nền / Upscale / Look / Reframe | Qwen edit + GD thuần | upscale = resample **GD** (không thêm chi tiết) | 4096 cạnh dài ⚠️ |
| Video catwalk (`/api/video`) | `wan2.5-t2v` | poll 5s, trần 480s | 480/720/1080 · 5–20s · MP4 ⚠️ |
| Gợi ý từ ảnh (`/api/suggest`) | `qwen3.8-flash` (fallback Gemini; không key ⇒ phân tích màu GD) | — | ảnh ≤1024 ⚠️ |

### 3.2 SÁU LỖI IM LẶNG — người dùng không biết mình đang nhận thứ khác

Đây là phần quan trọng nhất của §3, vì **chủ shop không đọc log**:

| # | Lỗi | Bằng chứng | Hậu quả với người bán |
|---|---|---|---|
| **S1** | **Nút 1K/2K KHÔNG có tác dụng** — `sizeFor()` nhận `$resolution` rồi **không dùng** | ✅ `ImageAIService.php:1559-1571`; key `image_resolution` `config/studio.php:70` | Trả credit như nhau, ảnh luôn ≤1,76 MP; không đủ để crop/zoom chi tiết đường may |
| **S2** | **Tỉ lệ bị bóp**: chọn `4:5` nhận **3:4**; chọn `21:9`/`19:6` nhận **16:9** | ✅ `ImageAIService.php:1563-1570` (8 tỉ lệ → 4 size) | Ảnh Instagram/Reels **sai khung ngay từ gốc**, phải crop lại ngoài app — đúng kênh chủ shop dùng nhiều nhất |
| **S3** | **Chế độ không có API key: thao tác SỬA trả về ĐÚNG ẢNH GỐC nhưng báo `completed`** — `copySample()` copy chính ảnh nguồn và lưu thành file mới | ✅ `ImageAIService.php:171-172` + `:528-537` | Người dùng dùng thử tưởng app "không làm gì cả"/"AI lỗi", hoặc tệ hơn: tưởng đã sửa xong mà đăng ảnh cũ |
| **S4** | **`seed` và `negative_prompt` bị bỏ qua ở MỌI đường EDIT** (chỉ áp cho tạo mới) | ✅ `:1559-1571` đọc kèm `:578-584` vs `:773`/`:1016` ⚠️ | Không tái lập được kết quả; "no logo, no text" không tới model ⇒ ảnh dính chữ/lỗi |
| **S5** | **Ảnh khuôn mặt mẫu bị bỏ ÂM THẦM**: model từ chối nhiều ảnh ⇒ tự gửi lại **không kèm mặt**; ở Tạo Ảnh 2D `faceRef` không đi tới payload | ⚠️ `ImageAIService.php:989-998` và `:597-604` | "Cùng một người mẫu cho cả lookbook" **không tồn tại** — mỗi ảnh một khuôn mặt khác |
| **S6** | **Fallback tự vẽ nền** khi AI không xoá được vật thể, không báo user | ⚠️ `ImageAIService.php:1338-1348,1366-1399` | Ảnh ra trông rõ là ghép, nhưng người dùng không biết vì sao |

### 3.3 Trần kỹ thuật đối chiếu yêu cầu THẬT của kênh bán

| Yêu cầu | Thực tế FabrikAI | Khoảng cách |
|---|---|---|
| Ảnh sàn cần cạnh ≥1000px, khuyến nghị 1500×1500+ | tạo mới ≤1328²; **sửa ảnh trần 1600px** ✅ | đủ ở mức tối thiểu, **không dư địa** để crop/zoom |
| Instagram/Facebook 4:5 | bị bóp thành 3:4 ✅ | phải crop lại ngoài app |
| TikTok/Reels 9:16 | có (928×1664) | dùng được |
| In ấn (300 DPI, CMYK, khổ vải) | **0 dòng code** nhắc dpi/cmyk/icc ⚠️ | không phục vụ sản xuất |
| Nền trắng chuẩn sàn | flood-fill ≥235, **không kiểm tra** nền ra có đúng 255,255,255, không ép khung vuông ⚠️ | rủi ro bị sàn từ chối ảnh |
| Watermark/logo thương hiệu | **không có**; key `brand_name` trong config **không được đọc ở đâu** ⚠️ | không bảo vệ ảnh khi giao khách |

### 3.4 Pipeline "xịn" đã chết nhưng còn dấu vết trong code

QA 4 tiêu chí · kiểm duyệt ảnh (moderation) · super-resolution 2×/4× · face-enhance · remove.bg + composite + depth + outpaint · `VirtualTryOnService::fallbackEdit()` (không caller) · best-of-N `swap_candidates=1` — **không đường nào được gọi** ⚠️ (`StudioController.php:3036,3072,3134,3174`; `config/studio.php:51-58`).

⇒ Hệ quả kép: (a) **không có lưới chất lượng nào** — ảnh lỗi (mặt méo, mất chi tiết) đi thẳng tới khách; (b) tài liệu đào tạo/nội bộ dựa trên các tên "Thay người mẫu · Pattern Maker · super-res" đều **sai với thực tế**.

### 3.5 Cần gạt chất lượng: ai thực sự chỉnh được

| Cần gạt | Mặc định | Người dùng chỉnh? | Ghi chú |
|---|---|---|---|
| creative_level · texture | 6 · 5 ✅ | ✔ | chỉ đổi **câu chữ** directive, không đổi tham số model ⚠️ |
| negative · seed | dài · null ✅ | ✔ (tạo mới) | **vô hiệu ở edit** (S4) |
| resolution 1K/2K | 2K ✅ | ✔ Settings | **vô hiệu hoàn toàn** (S1) |
| variants | 1, tối đa 4 ⚠️ | ✔ | 6 ảnh ⇒ tối thiểu 2 lượt chạy |
| edit_source_max · edit_postprocess · image_max_pixels · queue_worker | 2560 · false · 30MP · false ⚠️ | ✘ (env/DB) | quyết định chất lượng & tốc độ nhưng nằm ngoài tầm người dùng |

---

## §4 — Mức sẵn sàng DOANH NGHIỆP + hai lỗ hổng TIỀN

### 4.1 Mô hình người dùng

3 role (`super_admin/admin/customer`) ✅; toàn bộ `/api/*` sau `auth+admin` nên **customer không làm được gì** ⚠️; middleware `superadmin` **có class nhưng không route nào dùng** ⚠️; **không có tenant/team/workspace** — grep `organization|tenant|workspace` = 0 ⚠️; mọi dữ liệu neo `user_id`, riêng `studio_assets`/keys/providers/settings là **toàn cục** ⚠️.

### 4.2 Credit — 5 đường hoàn tiền, **4 có CAS, 1 không**

| Đường | CAS | Bằng chứng |
|---|---|---|
| `failStuck()` | ✅ | ✅ `StudioController.php:1317-1323` |
| `reconcileStuckCredits()` | ✅ | ✅ `:1510-1516` |
| `studio:process` heal | ✅ | ⚠️ `ProcessStudioGenerations.php:48-61` |
| Job `failed()` | idempotent theo status | ✅ `RenderImageJob.php:149-172` |
| **`cancel()`** | ❌ **KHÔNG** | ✅ `:1466-1481` — đọc trạng thái trên **instance cũ**, `update()` không kèm điều kiện status trong WHERE, rồi `increment` vô điều kiện |
| **nhánh inline-catch** | ❌ đặt `failed` **không hoàn**; `reconcile` chỉ quét `processing` | ✅ `:1368-1371` so với `:1502-1504` |

- **[XÁC NHẬN] Hoàn 2 lần**: poll `show()` (quá 6/8 phút → `failStuck`) chạy đồng thời với Cancel ⇒ cả hai cùng hoàn cho một generation.
- **[RỦI RO, chưa chứng minh]** Mất credit ở nhánh inline-catch: phụ thuộc việc Laravel có gọi `failed()` cho `dispatchSync` hay không. Điểm **chắc chắn đúng**: logic hoàn tiền nằm ở **5 nơi**, không nơi nào là nguồn duy nhất ⇒ mọi sửa đổi sau này đều có thể tạo lỗ mới.
- Nhóm `auth+admin` **không có throttle** (chỉ nhóm public có `throttle:60,1`) ✅ ⇒ admin có thể spam generation; credit **được phép âm** (`:1595` "never hard-block") ⚠️.

### 4.3 Vận hành (production thật)

Queue `database` ⚠️ · **chưa có cron worker trên host** ⇒ phụ thuộc lưới an toàn 90s ⚠️ · job `tries=1` (cố ý, chống double-bill) ⚠️ · heal 3 lớp ⚠️ · **không scheduler, không monitoring, không backup tự động** ⚠️ · migration phần lớn **không có `down()` an toàn** ⚠️.

### 4.4 Cộng tác / duyệt

5 trạng thái + whitelist transition + chỉ super_admin duyệt + cờ `self_approved` (điểm mạnh thật) ⚠️ — nhưng **audit trail `status_history` ghi vào DB rồi KHÔNG được trả ra API** (`ProjectController.php:241-263` không có key `settings`) ⚠️ ⇒ "tách nhiệm vụ" không kiểm chứng được trên UI; không bình luận, không ghim trên ảnh, không assignee, không thông báo, deadline không nhắc ⚠️.

### 4.5 Tuân thủ / riêng tư (rủi ro thật khi bán cho doanh nghiệp)

- **`/api/image/{path}` và `/api/image-thumb/{path}` phục vụ ảnh KHÔNG cần đăng nhập** ✅ (`routes/web.php`, ngoài nhóm auth) — với ảnh try-on/face-swap của **người thật**, đây là rò rỉ quyền riêng tư, không chỉ là chi tiết kỹ thuật.
- Ảnh khuôn mặt/người mẫu nằm trên **public disk** ⚠️; **không consent, không retention theo hợp đồng, không xoá tài khoản, không 2FA/SSO** ⚠️.

### 4.6 Bảng "thiếu gì để bán cho doanh nghiệp" (16 mục, theo công sức)

**Lớn:** tenant/organization + `organization_id` + global scope · membership & lời mời + role chi tiết.
**Vừa:** gate theo quyền/plan thay vì `admin` toàn cục (customer hiện 403 mọi thứ) · ví credit theo tổ chức + **sổ cái** · giá theo provider/model (`studio_models` không có cột giá) · ngân sách theo kỳ/team (`quota_limit` chỉ hiển thị, `max_generations` **chết**) · bình luận/duyệt theo luồng · thông báo + assignee + nhắc deadline · signed URL + disk riêng cho ảnh người thật · consent/retention/xoá dữ liệu · 2FA/SSO · worker bền + monitoring + backup diễn tập.
**Nhỏ:** chặn cứng khi hết credit · **hợp nhất 5 đường hoàn tiền thành 1 hàm có CAS** · trả `status_history` ra API + màn hình lịch sử · rate limit cho nhóm `auth+admin` · scope `studio_assets` theo tổ chức.

---

---

## §5 — Kiểm toán UX/IA: sản phẩm đang CHẶN chính người dùng mục tiêu

### 5.1 [CHẶN CỨNG — đã tự kiểm chứng] Người tự đăng ký KHÔNG dùng được sản phẩm, và không ai nói cho họ biết

Chuỗi nhân quả, mỗi mắt xích đều đọc trực tiếp:

| # | Mắt xích | Bằng chứng |
|---|---|---|
| 1 | Đăng ký thành công tạo user role `customer` | ✅ `User.php:20,48-50` (khai báo tường minh ở `$attributes`) + `AuthController::register()` `:62-70` (cố ý không truyền role) |
| 2 | Quyền vào studio = `ADMIN_ROLES = [super_admin, admin]` | ✅ `User.php:23` |
| 3 | **Toàn bộ** `/api/*` của studio nằm sau `auth+admin` | ✅ `routes/web.php:35` |
| 4 | ⇒ mọi call của user vừa đăng ký trả **403** | ✅ (hệ quả 2+3) |
| 5 | `store.load()` gặp 401/403 thì đặt `needsLogin = true` rồi **return im lặng** | ✅ `store.js:415` |
| 6 | `needsLogin` **không được render ở bất kỳ đâu** | ⚠️ grep chỉ khớp `store.js` |
| 7 | Thao tác đầu tiên ⇒ toast lỗi **nguyên văn backend**: "Bạn không có quyền truy cập khu vực quản trị." | ⚠️ `store.js:331-334` + `EnsureUserIsAdmin.php:14` |
| 8 | Link `/settings` (nơi cấu hình API key) **chỉ render khi `user.is_admin`** ⇒ không có đường self-serve | ⚠️ `StudioApp.vue:497` |
| 9 | Credit khởi tạo 100 là **vô nghĩa** vì mọi call đều bị chặn | ⚠️ migration `2026_01_01_000028` |

⇒ **Sản phẩm đang chạy trên `fabrikai.shop` chỉ dùng được cho người được admin tạo tay.** Một chủ shop tự đăng ký sẽ kết luận "app lỗi/trống" trong vòng 60 giây. Với một sản phẩm muốn bán cho chủ shop, đây là **lỗi số 1**, trên cả mọi lỗi pipeline ở §3.

### 5.2 Mobile vỡ thật — trong khi chủ shop chính là người dùng điện thoại

| Lỗi | Bằng chứng |
|---|---|
| Nút "Kết quả" trên top bar mở **drawer rỗng** (khung có, ruột không) | ⚠️ `StudioApp.vue:722-726` |
| "Nguồn ảnh" và "Thư viện" chỉ tồn tại ở rail phải `hidden lg:flex` (≥1024px); `SourcePanel`/`LibraryCard` được **import nhưng không render ở đâu** | ⚠️ `StudioApp.vue:15,18,687-698` |
| Hệ quả: trên điện thoại **không xem được ảnh đã tạo, không thêm được ảnh nguồn** | ⚠️ (hệ quả 2 lỗi trên) |
| Inspector Layers `w-64` (256px) đè canvas trên màn 375px → còn ~119px | ⚠️ `LayersPanel.vue:90` |
| Bảng Dự án 5 cột trong `overflow-hidden`, không có `overflow-x-auto` → bóp chữ | ⚠️ `ProjectWorkspace.vue:301-336` |
| PWA đã bị gỡ (`7451d6b`) nhưng **nút cài app vẫn còn trong code** | ✅ `StudioApp.vue:54-64,506` |

### 5.3 Tiến trình là **MÔ PHỎNG** — người dùng bị "lừa" đúng lúc dễ bỏ đi nhất

- Thanh % ở Tạo Ảnh cộng **ngẫu nhiên 4–12%** bằng `setInterval`, khoá ở **90%**, đổi "giai đoạn" theo ngưỡng % chứ **không** theo backend ⚠️ `store.js:458-466`.
- API trả về là set **100% + "Hoàn tất!"** dù ảnh còn nằm trong queue (generate là async) ⚠️ `store.js:501-503`.
- `generateImage()` **không gọi** `pollGeneration()` ⇒ thumbnail có thể đứng "Đang chờ/Đang xử lý" tới khi tải lại trang ⚠️ `store.js:451-510`.
- Cùng màn hình có **hai câu chuyện tiến trình khác nhau**: Outputs hiển thị trạng thái THẬT (pending/processing/failed/cancelled) ⚠️ `OutputModule.vue:43-54`.

### 5.4 Thuật ngữ: giao diện Việt hoá ~93% nhưng **toàn bộ khái niệm khó vẫn tiếng Anh**

Số đo tự động trên `resources/js/studio/**/*.vue`: **734 nhãn** khác nhau (620 có dấu), riêng **~93 nhãn EN/jargon** tập trung đúng vào các khái niệm khó ⚠️.

| Nhãn | Nơi | Người bán hàng hiểu là gì | Nên đổi |
|---|---|---|---|
| `Fitting Room` | `StudioApp.vue:39` | "phòng thử đồ của Tây" | Thử đồ lên người mẫu |
| `Upscale` · `Outputs` · `Layers` · `Texture` | `StudioApp.vue:42,681,694`; `LayersPanel.vue:95`; `ConceptCard.vue:655` | không hiểu | Làm nét · Ảnh đã tạo · Lớp ảnh · Độ chi tiết vải |
| `Blend` + Normal/Multiply/Screen… (6 chế độ) | `LayersPanel.vue:228-237` | không hiểu | Ẩn sau "Nâng cao" + mô tả tiếng Việt |
| `Gieo quẻ (Seed)` · `Enrich` | `ConceptCard.vue:658-663,669` | ẩn dụ + từ kỹ thuật | Mã tạo ảnh lặp lại · Xem câu lệnh sẽ gửi |
| `Magic Wand · Tolerance · Feather · Bezier · lasso · mask` | `RegionTools.vue:59,49`; `ContextToolbar.vue:45-46`; `InpaintCard.vue:79` | bộ từ Photoshop | Chọn theo màu · Độ nhạy · Làm mềm mép · Đường cong |
| `credit` | `StudioApp.vue:495` | không biết quy ra tiền | Lượt tạo ảnh + tỉ giá |
| `@image1/@image2` | `ComposeCard.vue:257` | cú pháp lạ | Ảnh 1 / Ảnh 2 / Ảnh 3 |

**Lỗi điều hướng cụ thể:** hai nút **liền nhau** ở activity bar dùng **cùng icon `sparkles`** nhưng làm hai việc khác hẳn ("Tạo ảnh" vs "Prompt Tạo Ảnh") ⚠️ `StudioApp.vue:529,533`; thêm nữa activity bar **không có chữ**, chỉ tooltip.

### 5.5 Empty state & thông báo lỗi

- Canvas rỗng: *"Chọn/hiện một ảnh (Nguồn hoặc Kết quả) để làm việc"* — vô nghĩa với người vừa vào vì **chưa có gì để chọn** ⚠️ `StudioApp.vue:584,607`.
- Dock Outputs **không có empty state nào** ⚠️ `OutputModule.vue:33-58`.
- Toast tự tắt sau **2,6 giây**, `pointer-events-none` (không đọc lại/copy được), và **info/error cùng một vị trí, cùng một kiểu** ✅ `store.js:346-352`.
- ✅ Điểm sáng: `InpaintCard`/`ComposeCard` có khối lỗi + nút "Thử lại".

### 5.6 A11y

Không có **focus trap** ở bất kỳ modal nào (kể cả `BaseModal` có `role=dialog`) ⇒ Tab đi xuyên ra sau lớp phủ ⚠️ `BaseModal.vue:16-33`; **137 nút chỉ có icon, 60 nút thiếu `aria-label`**; `StudioIcon` render `<svg>` trần, không `aria-hidden` ⇒ trình đọc màn hình đọc rác ⚠️ `StudioIcon.vue:139-141`.

---

## §6 — Thiết kế cải tiến có ĐẶC TẢ (đủ để giao việc)

### 6.1 Nguyên tắc

1. **Một engine, ba tầng trần.** Không fork sản phẩm theo persona: cùng card/engine, khác *điểm vào*, *từ ngữ* và *mức phơi bày tham số*.
2. **Niềm tin là tính năng.** Mọi hành vi im lặng (S1–S6 ở §3.2, §5.1, §5.3) phải bị coi là bug P0 — vì chủ shop **không đọc log**, họ chỉ kết luận "app đểu".
3. **Giá trị phải rời khỏi app.** Không có gói xuất thì không có lý do trả tiền.
4. **Trạng thái phải nằm ở ARTIFACT, không ở Project** (giữ nguyên chẩn đoán của v2 §3.2 — đúng).

### 6.2 Đợt 0 — "Cứu niềm tin" (làm trước mọi tính năng mới)

| # | Vấn đề | Việc cần làm | Tiêu chí nghiệm thu |
|---|---|---|---|
| 0.1 | §5.1 chặn cứng | **Quyết định mô hình truy cập**: (a) mở studio cho `customer` có giới hạn (mặc định: chỉ tạo ảnh, dùng credit của mình), hoặc (b) giữ `admin` nhưng đổi luồng đăng ký thành **xin quyền** + màn hình chờ có nội dung. Đồng thời **render `needsLogin`** thành banner phân biệt 3 trạng thái: *chưa đăng nhập* / *đã đăng nhập nhưng chưa được cấp quyền* / *hết phiên*. | 1 tài khoản tự đăng ký tạo được ảnh đầu tiên **hoặc** nhìn thấy thông báo nói rõ cần làm gì; không còn toast chứa chữ "khu vực quản trị" |
| 0.2 | §5.2 mobile | Render `OutputModule` vào drawer "Kết quả"; đưa Nguồn ảnh + Thư viện vào drawer dưới 1024px (dùng lại 2 component dead import); Inspector <lg thành drawer đè có nút đóng; bảng Dự án thêm `overflow-x-auto` | Trên 375px: tạo ảnh → xem kết quả → thêm ảnh nguồn → mở thư viện đều làm được |
| 0.3 | §5.3 tiến trình giả | Bỏ `setInterval`; đọc trạng thái thật từ generation; gọi `pollGeneration()` cho `generateImage()`; hiển thị "Đang xếp hàng / Đang xử lý / Xong" | Không còn "100% Hoàn tất" khi ảnh còn pending; F5 không cần thiết |
| 0.4 | S3 (§3.2) | Chế độ **DEMO** phải nói rõ: banner "Chưa cấu hình API key — kết quả là ảnh mẫu/ảnh gốc, không phải AI" khi chạy `copySample()` | Không còn ca "bấm Sửa ảnh, nhận lại ảnh cũ, app báo thành công" mà người dùng không hiểu vì sao |
| 0.5 | §4.2 tiền | **Hợp nhất 5 đường hoàn credit thành 1 hàm duy nhất có CAS**; `cancel()` dùng CAS; nhánh inline-catch gọi cùng hàm đó; thêm test đua | Có test chứng minh: cancel đua với failStuck ⇒ hoàn **đúng 1 lần** |
| 0.6 | §3.1 S1/S2 | Sửa `sizeFor()` dùng `$resolution` **hoặc** gỡ nút 1K/2K cho trung thực; map thêm tỉ lệ 4:5 (1104×1380) và 21:9 nếu provider cho phép, **hoặc** ghi rõ trên UI tỉ lệ nào được hỗ trợ thật | Nút nào bấm được thì phải có tác dụng thật |
| 0.7 | §2 dư lượng | Gỡ nút cài PWA + listener (hoặc làm lại PWA đúng cách); gỡ route/preview `/api/swap-*` nếu thử đồ đã đi đường refgen; xoá các method chết đã liệt kê ở §3.4 | `grep` tên tính năng đã gỡ = 0 ngoài changelog |
| 0.8 | §5.4/§5.6 | Việt hoá jargon lớp 1 (bảng §5.4); đổi icon nút "Prompt Tạo Ảnh"; focus trap + `aria-label` cho 60 nút icon-only + `aria-hidden` cho `StudioIcon` | Không còn 2 nút cùng icon; Tab không thoát khỏi modal |

### 6.3 Đợt 1 — "Last mile" cho chủ shop (nơi tiền nằm)

**Đặc tả tối thiểu (đề xuất schema — chưa có trong DB hôm nay):**

| Bảng | Cột chính | Ghi chú |
|---|---|---|
| `products` | `user_id, sku, name, category, price, base_image` | Thực thể chủ shop nhận ra ngay |
| `looks` | `product_id, collection_id?, channel, ratio, model_ref, status` | "Bộ ảnh cho kênh nào" |
| `shots` | `look_id, generation_id, state (idea→drafted→selected→fitted→approved→rejected), is_selected, note, sort` | **Nâng cấp `Generation` thành artifact có trạng thái** |
| `runs` | `type, params(JSON), total, done, failed, credits_spent, status` | Lô 20 ảnh — hiện **không có khái niệm lô** |
| `exports` | `look_id, preset, files(JSON), caption, watermark, created_at` | Bằng chứng đã giao gì cho ai |

**API mới (đường dẫn đề xuất, theo prefix `/api` hiện có):** `POST /api/runs` (tạo lô) · `GET /api/runs/{id}` (tiến trình) · `POST /api/shots/select` · `POST /api/exports` · `GET /api/exports/{id}/download`.

**Pipeline xuất (đây là chỗ "0 kết quả `ZipArchive` trong toàn repo" phải đổi):**
`chọn shots → resize theo preset kênh → watermark/logo (nếu bật) → đặt tên {sku}-{channel}-{n}.jpg → zip → kèm caption.txt`.
Preset kênh đề xuất: **Shopee 1:1 ≥1000px** · **Instagram/Facebook 4:5** · **TikTok/Reels 9:16** · **Web 3:4**. ⚠️ Lưu ý §3.3: hiện 4:5 bị bóp thành 3:4, nên **preset kênh phải đi kèm việc sửa `sizeFor()`**, nếu không gói xuất sẽ crop từ khung sai.

**Ba việc rẻ, đòn bẩy lớn:**
1. **Thông báo khi render xong** (email trước) — hiện **0 notification** trong toàn `app/`; render 3–8 phút mà người dùng phải canh màn hình.
2. **Mô tả + caption + hashtag** — `ProductAIService` **đã bị gỡ**, nên đây là **việc XÂY MỚI** (0.5–1 ngày cho prompt template + 1 endpoint), hoặc khôi phục `git show 729ceb0^:app/Services/ProductAIService.php`; **không** được mô tả là "hồi sinh code đang có" như v2 đang viết.
3. **Cron worker trên host** — hiện production phụ thuộc lưới an toàn 90s; có worker thật thì ảnh xong ~60s.

### 6.4 Đợt 2 — Designer (nhân đôi năng lực, không thêm card mới)

| Việc | Vì sao là LUỒNG | Cần gì |
|---|---|---|
| **Ma trận colorway + khoá seed** | 1 thiết kế × 4 màu × 2 chất liệu = 8 lần bấm tay; không khoá seed ⇒ form lệch giữa các màu | `variants` hiện ≤4 ⇒ cần `Run` sinh theo ma trận, **kèm seed lan truyền** (hiện seed chỉ áp cho tạo mới — S4) |
| **Model lock** | Ảnh trong cùng lookbook phải cùng một người mẫu | Hiện **không tồn tại**: ảnh mặt bị bỏ âm thầm (S5). Cần: lưu reference + kiểm tra lại sau render + **cảnh báo khi không giữ được** |
| **Lineage/versioning** | Không biết "sửa tiếp từ ảnh nào", không so v1/v2/v3 | `Generation.meta` đã là JSON ⇒ thêm `parent_shot_id` + màn hình so sánh |
| **Duyệt có bình luận + ghim trên ảnh** | Đã có 5 trạng thái + audit (tốt) nhưng **không nói được "sửa chỗ vai"**; hơn nữa audit **ghi mà không hiển thị** | Trả `status_history` ra API + comment thread + toạ độ ghim |
| **Pattern (họa tiết) — QUYẾT ĐỊNH CẦN GHI SỔ** | Card đã bị gỡ (`eee1bba`) nhưng Đợt 2 của v2 vẫn giữ "Pattern seamless + xuất in" | Chọn: **làm lại đúng cách** (kiểm tra lặp liền mạch + tile/DPI) hoặc **tuyên bố không phục vụ in ấn**. Không để lửng lơ — đây là năng lực lõi của designer |
| **Tech pack tối thiểu** | Không có gì (0 dòng code về dpi/cmyk) | Bảng size + ghi chú chất liệu + xuất PDF — chỉ nên làm **sau khi** có 6.3 ổn định |

### 6.5 Đợt 3 — Doanh nghiệp (bán được cho brand)

Theo bảng 16 mục ở §4.6, nhưng **thứ tự phải là**: (1) tenant/org + membership; (2) **sổ cái credit + giá theo model** (không có cái này thì không định giá được); (3) **signed URL cho ảnh người thật + disk riêng** (rủi ro pháp lý đang mở: ảnh try-on phục vụ công khai không cần đăng nhập); (4) consent/retention/xoá dữ liệu; (5) thông báo + assignee + nhắc deadline; (6) worker bền + monitoring + backup **có diễn tập restore**; (7) brand kit; (8) SSO/2FA.

---

## §7 — Lộ trình gộp (v2 + kiểm chứng ở file này) kèm tiêu chí nghiệm thu

| Đợt | Nội dung | Vì sao thứ tự này | DoD (tiêu chí nghiệm thu) |
|---|---|---|---|
| **0** | §6.2 (8 việc) | Chặn cứng onboarding + 6 lỗi im lặng + mobile + 2 lỗ hổng tiền | Người lạ tự đăng ký dùng được (hoặc hiểu ngay vì sao chưa); 1 tài khoản mới tạo được 1 ảnh trên 375px; không còn hành vi im lặng nào trong danh sách S1–S6 |
| **1** | `shots`+`runs`+`exports` · thông báo · mô tả/caption · cron worker | Đây là "last mile": biến ~30 thao tác + 4 việc ngoài app (số của v2 §4.0) thành 1 luồng trong app | 1 SKU ⇒ 1 gói zip đúng tỉ lệ kênh + tên file theo SKU + caption, **không rời app lần nào**; có thông báo khi xong |
| **2** | §6.4 | Nhân đôi giá trị cho designer — tệp trả tiền cao nhất | 4 colorway cùng seed ⇒ form không lệch; 6 ảnh lookbook cùng người mẫu, sai thì **có cảnh báo**; so sánh được 2 phiên bản |
| **3** | §6.5 | Chỉ mở khi 1–2 đã chứng minh giữ chân được người dùng | 2 brand khác nhau dùng chung 1 deployment mà không thấy dữ liệu của nhau; có báo cáo chi phí theo dự án/tháng |

---

## §8 — Bộ KPI (bổ sung cho §7 của v2 — thêm guardrail và nguồn dữ liệu)

| Chỉ số | Nguồn ghi | Ngưỡng đủ tốt | Guardrail |
|---|---|---|---|
| Time-to-first-image (đăng ký → ảnh hoàn tất đầu tiên) | event server-side | **< 5 phút** | đo cả tỉ lệ **0 ảnh trong 24h** (hiện tại: ~100% vì 403) |
| Tỉ lệ dùng lại trong 7 ngày (D7) | event | > 30% | — |
| Tỉ lệ EXPORT / số look | bảng `exports` | > 60% | nếu export thấp mà render cao ⇒ **chất lượng**, không phải UX |
| Tỉ lệ **ảnh lỗi người dùng tự phát hiện** (xoá/sửa lại ngay sau khi render) | event | < 20% | chỉ số gián tiếp của "QA đã chết" |
| Lỗi theo provider/model (4xx/5xx/timeout) | log + event | < 5% | cảnh báo tự động khi > 15% trong 15 phút |
| Chi phí thật / ảnh, / look, / tháng | **cần sổ cái credit** | biên > 70% | chặn cứng khi vượt hạn mức org |
| Tỉ lệ hoàn credit bất thường (hoàn > số lần tạo) | sổ cái | = 0 | phát hiện double-refund như §4.2 |

**Nguyên tắc:** chỉ đếm sự kiện (id, model, thời lượng, chi phí) — **không log nội dung prompt/ảnh**, tránh tự tạo rủi ro riêng tư mới.

---

## §9 — Rủi ro (xếp theo khả năng × thiệt hại)

1. **Rủi ro số 1 không nằm ở code: tài liệu đang mô tả 3 thời điểm khác nhau** (§1). Người/vòng sau đọc sai trạng thái sẽ làm lại việc đã xong hoặc tưởng việc đã xong là chưa ⇒ mỗi vòng rà soát tự sinh chi phí.
2. **Rủi ro nhân sự/quy trình:** hai phiên chạy song song trên cùng repo, cùng file tài liệu. Cần **chốt quyền ghi** cho từng file (hoặc tách file theo phiên) trước khi mở thêm vòng.
3. **Rủi ro niềm tin:** chỉ cần một khách hàng thật gặp ca "Sửa ảnh trả lại ảnh cũ mà báo thành công" (S3) là mất khách; ca này **đang có thật** trên production khi chưa cấu hình key.
4. **Rủi ro pháp lý:** ảnh người thật (try-on/face-swap) đang phục vụ qua URL công khai, không consent, không retention ⇒ rào cản thật khi bán cho doanh nghiệp.
5. **Rủi ro vận hành:** production chưa có cron worker, không monitoring, không backup tự động, migration thiếu `down()`.
6. **Rủi ro định vị:** đã bỏ Pattern (in ấn) và Thay người mẫu mà **chưa ghi quyết định** ⇒ đội ngũ sẽ hiểu sai năng lực sản phẩm; tài liệu đào tạo dựa trên tên cũ sẽ sai (xem §3.4).

---

## §10 — Cách cập nhật file này

1. **Đo lại trước khi trích số**: `git rev-parse --short HEAD` · `php artisan route:list | wc -l` · `vendor/bin/phpunit --list-tests | wc -l`.
2. Mọi khẳng định phải có `file:dòng` **hoặc** được đánh dấu ⚠️ (chưa tự kiểm chứng) / ✅ (đã tự kiểm chứng).
3. Khi một mục ở §6/§7 hoàn thành: chuyển sang `STUDIO_REVIEW_PROGRESS.md` §2 kèm commit, **giữ nguyên tiêu chí nghiệm thu ở đây** để đối chiếu.
4. **Không** sửa file này song song ở hai phiên: `stat` mtime + đọc lại vùng sắp ghi trước khi ghi (bài học §1.5).
---

## §11 — THỊ TRƯỜNG & ĐỐI THỦ (vòng phân tích thị trường, giá tra ngày 16–17/09/2026)

### 11.1 Ai đang ở đâu

| Nhóm | Đại diện | Mô hình giá thật | Điểm mạnh | Điểm KHÔNG làm được |
|---|---|---|---|---|
| Ảnh sản phẩm phổ thông | **Photoroom** ($7.5–20.99/tháng; free 250 export) · **Pebblely** ($9) · **Meitu Design Studio** | Theo gói + credit | Tốc độ, batch, app mobile, phân phối | Không phải công cụ thời trang: không giữ họa tiết/logo phức tạp, **không có try-on**, không có spec sàn VN |
| Thời trang chuyên | **Botika** (~$22–100/tháng; 1 credit/ảnh, 5/video) · **WeShop AI** (free 400 credit/tháng, $9.99–45/tháng, có bản tiếng Việt + template Tết/11.11) · **ZMO AI** | Credit | Xử lý váy/áo khoác tốt, 4K, retouch người thật | Không chạm bước thiết kế/tech pack; **credit hết hạn theo kỳ** |
| Model riêng của brand | **Flair.ai** ($8–38/tháng) | Gói + credit | Train model riêng, **giữ pattern & logo** | Credit **không rollover**; model "instant" chất lượng thấp |
| Enterprise / catalog lớn | **Claid.ai** ($9–59/tháng; API ~$0.13–0.24/ảnh) · **Vue.ai** · **Stylitics** | API/credit, nhiều gói **custom, không self-serve** | API + volume + graph dữ liệu (AOV/UPT) | Không có UI cho shop nhỏ; Vue.ai bị đánh giá "không phù hợp team nhỏ" |
| Video/UGC | **Topview** ($29–180/seat) · **Vmake AI** | Gói/credit | URL-to-video, avatar cầm sản phẩm | Tối ưu ad phương Tây; **không ai xuất đúng spec TikTok Shop VN** |
| Công cụ thiết kế 3D | **CLO3D · Browzwear · Style3D** | Subscription/quote | Chuẩn công nghiệp, may ảo, fit/drape | **Không công cụ nào sinh đủ tech pack** (BOM, dung sai, giá thành) |
| In-house của brand lớn | **Fermat (ASOS)** — sketch → ảnh photoreal; ASOS báo **tiết kiệm 75–80% thời gian** ở khâu thiết kế · **The New Black AI** ($30/agent/tháng) | Không bán / USD | Agent soạn tech pack, đọc bảng giá NCC → BOM | Không bán cho thị trường VN; không hiểu Shopee/TikTok Shop |
| **Mối đe trực tiếp nhất ở VN** | **Shopee AI Creation** (miễn phí, nằm trong Seller Centre) · **Meitu** (bộ 7 ảnh 1:1 chuẩn Shopee) | **0 đồng** | Phân phối + đúng spec sàn + giá 0 | Chỉ tối ưu ảnh có sẵn; không lookbook, không thời trang, Meitu không có tiếng Việt |

### 11.2 Yêu cầu THẬT của kênh bán (số cụ thể)

| Kênh | Ảnh | Video | Điều cấm |
|---|---|---|---|
| **Shopee** | ≥500×500, khuyến nghị **800×800**, trần 2000×2000, **≤2MB**, tỉ lệ **1:1 hoặc 3:4**, sản phẩm **≥70%** khung, tối đa 9 ảnh | ≤60s | Ảnh bìa **không được có chữ khuyến mãi, giá, logo, watermark, logo sàn khác** |
| **TikTok Shop** | **1:1 bắt buộc**, min 600×600, khuyến nghị **1000×1000**, **nền trắng tuyệt đối #FFFFFF**, ≤5MB, sản phẩm ≥60% | **9:16 bắt buộc** (16:9 và 1:1 bị **từ chối**, không crop), **9–60s** (ngọt nhất 15–30s), sản phẩm xuất hiện **trong 3 giây đầu**, MP4/H.264, **không watermark** (CapCut/Instagram = từ chối), text overlay chỉ trong 80% trung tâm | Video sai tỉ lệ bị từ chối thẳng |
| **Meta (FB/IG catalog)** | min 500×500, khuyến nghị **1024×1024**, **<8MB**, HTTPS, **1:1 là tốt nhất** (Meta tự crop vuông) | — | Hay bị từ chối vì **text overlay, watermark, ảnh <500px** |
| **Tiki / Lazada** | Tiki 1:1 1200×1200, sản phẩm ≥80% khung, 5–15 ảnh · Lazada 1:1, min 330×330, max 5000×5000 | — | — |

### 11.3 Đối chiếu năng lực FabrikAI với spec sàn (tổng hợp của tôi từ §3 + §11.2)

| Yêu cầu | FabrikAI hôm nay | Kết luận |
|---|---|---|
| Shopee 800×800 1:1 | tạo mới 1328×1328 ✅ | **ĐẠT** (có dư địa) |
| TikTok 1000×1000 1:1 | 1328×1328 ✅ | **ĐẠT** |
| Meta 1024×1024 | 1328×1328 ✅ | **ĐẠT** |
| TikTok **nền trắng tuyệt đối #FFFFFF** | xoá nền = flood-fill ngưỡng **≥235** ⚠️, không kiểm tra nền ra có đúng 255 | **KHÔNG ĐẢM BẢO** |
| Tỉ lệ 4:5 (IG/FB feed) | bị bóp thành **3:4** ✅(đã kiểm) | **SAI** — phải crop lại ngoài app |
| Video TikTok 9:16 | có 9:16 video ⚠️; nhưng **không có kiểm tra tỉ lệ/độ dài/hook 3s** | **THIẾU kiểm soát** |
| Tên file theo SKU | `response()->download($abs, basename($abs))` — tên là **UUID** ✅(đã kiểm) | **SAI** |
| Xuất 1 gói nhiều ảnh | **0 dòng `ZipArchive`** trong repo ✅(đã kiểm) | **THIẾU HOÀN TOÀN** |
| Ảnh bìa **không watermark/logo** | FabrikAI không có watermark — **tình cờ đúng** cho sàn, nhưng cũng **không có** cho lookbook/gửi khách | cần **watermark tuỳ chọn theo đích** (bật cho social/preview, **tắt** cho ảnh bìa sàn) |
| Mô tả/caption/hashtag | không có | **THIẾU** |
| Kiểm tra trước khi đăng | không có | **THIẾU** |

### 11.4 Ba hàm ý sản phẩm MỚI mà dữ liệu thị trường đưa vào (chưa có trong v2)

1. **Channel Validator (pre-flight)** — kiểm tra ngay trong app: cạnh ≥ ngưỡng kênh · dung lượng ≤ 2/5/8 MB · tỉ lệ đúng · **nền có thực sự trắng 255,255,255** · có watermark/chữ không · sản phẩm chiếm ≥70% khung. Không đối thủ nào làm cho sàn VN; đây là tính năng **dễ demo nhất** và trực tiếp chặn lỗi "sản phẩm bị ẩn/từ chối".
2. **Preset xuất theo kênh phải đi kèm sửa `sizeFor()`** — vì 4:5 đang bị bóp thành 3:4, gói xuất sẽ crop từ **khung sai**. Đây là phụ thuộc kỹ thuật bắt buộc, không phải việc trang trí.
3. **Định vị "AI có trách nhiệm"** — thị trường VN đang có phản ứng thật: người mua tuyên bố "nói không với hàng dùng mẫu AI" vì sợ lệch dáng/màu/họa tiết. FabrikAI có sẵn lợi thế: prompt *"reproduce this IDENTICAL garment… do not redesign, restyle, recolor"* là **tài sản giá trị nhất** trong toàn bộ code. Nên biến nó thành tính năng nhìn thấy được: **cam kết giữ nguyên sản phẩm + nhãn "ảnh tạo bởi AI" + liên kết ảnh gốc**.

### 11.5 Vùng THẮNG · vùng THUA · ba rủi ro cạnh tranh

- **Có thể thắng:** thời trang + **đa kênh** + last-mile (export/validator) + tiếng Việt + giá VNĐ + quy trình duyệt.
- **Chắc chắn thua:** "ảnh sản phẩm generic giá rẻ" — đối đầu trực diện với **Shopee AI Creation (miễn phí)** và Photoroom.
- **Rủi ro 1 — disintermediation (lớn nhất, bị đánh giá thấp):** một cá nhân ở Hà Nội tự dựng người mẫu AI bằng Nano Banana Pro + Veo 3 + Kling, đạt **100–200 đơn/ngày**. Nghĩa là người biết prompt **không cần app**. Moat phải là *workflow + tuân thủ sàn + tiếng Việt*, **không** phải "model đẹp".
- **Rủi ro 2 — Photoroom/WeShop thêm preset sàn Đông Nam Á:** lợi thế last-mile biến mất trong một release (Photoroom đã có app mobile + API + Shopify app).
- **Rủi ro 3 — niềm tin/pháp lý:** làn sóng "nói không với mẫu AI" + ảnh người thật đang phục vụ công khai không consent (§4.5) ⇒ một chiến dịch truyền thông xấu có thể đè nhanh hơn mọi đối thủ.

---

## §12 — Điều chỉnh so với `STUDIO_REVIEW_PRODUCT.md` v2 (sau khi có dữ liệu thị trường)

| Thay đổi | Lý do |
|---|---|
| **THÊM** Channel Validator vào Đợt 1 (ngang hàng ExportBundle) | Không đối thủ nào có; chặn lỗi bị sàn ẩn; dễ demo |
| **THÊM** định vị "AI có trách nhiệm" (nhãn AI + liên kết ảnh gốc + cam kết giữ sản phẩm) | Phản ứng thị trường VN đang có thật; FabrikAI đã có tài sản prompt tốt nhất |
| **SỬA** "hồi sinh `ProductAIService`" → **xây mới** module mô tả/caption | File đã bị gỡ (`729ceb0`) — v2 kế thừa claim sai từ v1 |
| **SỬA** "BẬT `STUDIO_SWAP_ENABLED`" → đã gỡ hẳn (`eee1bba`); nếu muốn làm lại thì `git revert eee1bba` | v2 tự mâu thuẫn (§4A vs §6.1) |
| **HẠ** ưu tiên tính năng video | Cạnh tranh trực diện với Topview/Vmake đã rất mạnh; chỉ nên làm **spec TikTok Shop VN**, không làm video chung |
| **GIỮ NGUYÊN** chẩn đoán M1–M5 và 7 đối tượng nghiệp vụ của v2 | Đúng và đã được dữ liệu ở file này xác nhận |


---

## §13 — Ba câu chốt lại toàn bộ tài liệu

1. **Vấn đề lớn nhất hôm nay không phải chất lượng AI, mà là NIỀM TIN và ĐƯỜNG VÀO**: người tự đăng ký bị 403 im lặng (§5.1), ảnh Sửa/Ghép trả lại ảnh cũ mà báo thành công khi chưa có key (§3.2 S3), thanh tiến trình là mô phỏng (§5.3), nút 2K và tỉ lệ 4:5 không có tác dụng đúng (§3.2 S1–S2). Sửa 4 nhóm này rẻ hơn nhiều so với làm tính năng mới, và là điều kiện để mọi tính năng sau đó có nghĩa.
2. **Giá trị chỉ rời khỏi app khi có GÓI XUẤT**: hôm nay tải ảnh là từng file với tên UUID, không có `ZipArchive` trong repo, không có mô tả/caption. Đây là lý do trả tiền rõ ràng nhất, và cũng là chỗ không đối thủ nào phục vụ đúng sàn VN (Shopee/TikTok/Meta) — kèm **Channel Validator** để không bị sàn từ chối.
3. **Đối thủ đáng sợ nhất không phải một app khác, mà là "không cần app"**: Shopee AI Creation miễn phí trong Seller Centre, và một cá nhân biết prompt có thể tự dựng người mẫu AI. Moat của FabrikAI phải là **workflow nhiều bước + tuân thủ kênh + tiếng Việt + giá VNĐ + quy trình duyệt có kiểm soát**, không phải "model đẹp nhất".

