# BỎ LAYER · BỎ TOOLBAR · GIỮ INPAINT + MASK · HÀNG ĐỢI FAL.AI · UI MOBILE-FIRST

> **Trạng thái: ĐỀ XUẤT — chưa sửa một dòng mã nào.** Ngày lập: 2026-09-26.
> Tiếp nối `docs/BO_CANVAS_PHUONG_AN.md` (phân tích bỏ canvas). Tài liệu này trả lời ba việc:
> **(1)** xoá layer + bỏ toolbar nhưng giữ inpaint + mask · **(2)** chuyển worker/hàng đợi sang fal.ai,
> bỏ phụ thuộc cron hPanel · **(3)** thiết kế lại GUI/UX/UI theo thứ tự ưu tiên **mobile → tablet → desktop**.
> Mọi con số "đo được" là đọc từ chính repo hoặc từ tài liệu fal.ai, có ghi nguồn.

---

## 0. KẾT LUẬN NGẮN

1. **Ba việc này là MỘT hướng, không phải ba.** Bỏ layer ⇒ bỏ luôn phần lớn lý do phải có toolbar.
   Bỏ toolbar ⇒ màn hình đủ chỗ cho mobile. Chuyển hàng đợi sang fal ⇒ bỏ luôn thứ đang làm
   hỏng trải nghiệm chờ (poll 500 ms + request bị giữ 8 phút).
2. **Dựng hàng đợi trên cron hPanel là sai** — và chính mã trong repo đã ghi lại sự cố thật đó. Xem §1.
3. **Giữ inpaint + mask là đủ và đúng** — nhưng phải đổi vai: mask từ **bắt buộc** thành **tuỳ chọn**.
   Đó là thay đổi lớn nhất của phần UI, và là thứ duy nhất làm cho nó chạy được trên điện thoại.
4. **Đã ĐO được 5 cái bẫy kỹ thuật khi nối fal** — trong đó **mask của fal NGƯỢC với mask của ta**
   (§2.3a). Đây là loại lỗi không báo gì, chỉ ra ảnh sai.
5. **fal trả về `queue_position` THẬT** ⇒ lần đầu tiên có tiến trình thật để hiện, thay cho thanh %
   mô phỏng đã bị gỡ ở Đợt 0.2. Điểm cộng trực tiếp cho §12 nguyên tắc 7.

---

## 1. VÌ SAO "HÀNG ĐỢI TRÊN hPANEL" LÀ SAI — CHÍNH MÃ REPO NÓI RA

### 1.1 Bối cảnh hạ tầng (đọc từ mã)

| Sự thật | Nguồn |
|---|---|
| Web root là `public_html` — **Hostinger shared hosting** | `bootstrap/app.php:42–45` |
| `QUEUE_CONNECTION=database` | `.env:39` |
| Queue `retry_after` = 660 s, ghi rõ "**MUST exceed the longest job timeout**" | `config/queue.php:47–53` |
| Máy chủ **không có cron** | `DEPLOY_LOG.md` nhắc ở nhiều đợt; xem trích dẫn §1.2 |

### 1.2 Hai nhánh hiện tại — CẢ HAI ĐỀU SAI

`config/studio.php:173–195` mô tả đúng hai nhánh, và tự nói ra cái giá:

| Nhánh | Hành vi | Cái giá (nguyên văn trong config) |
|---|---|---|
| `STUDIO_QUEUE_WORKER=false` **(mặc định)** | Request của trình duyệt **tự chạy render inline** | *"request có thể bị giữ tới **~8 phút** vì các service phải `sleep()` chờ provider (ảnh ~3 phút, video tới ~8 phút). **Vài request đồng thời là cạn pool PHP-FPM ⇒ sập cả site.**"* |
| `true` | Chỉ enqueue; cần `queue:work` chạy nền | *"⚠️ Bật cờ này mà KHÔNG chạy worker ⇒ **generation không bao giờ được xử lý**"* |

Và nhánh `true` **đã nổ thật** — ghi ngay trong mã, `StudioController.php:1497–1517`:

> *"[Gặp thật khi deploy 2026-09-17] Bật STUDIO_QUEUE_WORKER=true mà trên máy chủ KHÔNG có worker nền
> nào chạy (cron chưa tạo, hoặc worker chết) thì job nằm mãi trong bảng jobs: **generation không bao
> giờ rời pending và người dùng MẤT CREDIT mà không có kết quả**. Đã kiểm chứng: **cron của host chỉ
> có của domain khác**."*

Câu log ở dòng 1510 còn chỉ đích danh nơi phải sửa: *"Kiểm tra cron `queue:work --stop-when-empty`
đã được tạo trong **hPanel** chưa."*

### 1.3 Bằng chứng thứ ba: nhịp POLL

`resources/js/studio/store/actions/generation.js:311–355`:
- poll **mỗi 500 ms**, trần **10 phút**, mỗi generation một vòng, single-flight.
- ⇒ **4 biến thể = 8 request/giây** vào server. Một job treo 10 phút = **~1.200 request** cho MỘT ảnh.

Cộng ba thứ lại: **máy chủ vừa phải giữ request 8 phút, vừa không có cron, vừa bị poll 2 lần/giây.**
Đây không phải vấn đề tinh chỉnh — đây là **đặt sai chỗ**.

### 1.4 Nguyên tắc mới

> **Máy chủ chỉ RA LỆNH và NHẬN KẾT QUẢ. Không ai `sleep()` trong request. Không cần cron.**

---

## 2. KIẾN TRÚC MỚI: fal.ai LÀ HÀNG ĐỢI

### 2.1 Luồng

```
POST /api/generate
 ├ tạo Generation(pending) + trừ credit                    (giữ nguyên như cũ)
 ├ POST https://queue.fal.run/{model}?fal_webhook=<url>     ← webhook là QUERY PARAM
 ├ lưu meta.fal = { request_id, status_url, response_url, cancel_url, model, submitted_at }
 └ trả về NGAY (~0,3 s)          ← thay cho 3–8 phút bị giữ
        │
        │   fal chạy trên hạ tầng của fal — 0 giây CPU của ta
        ▼
POST /api/webhooks/fal                      ← route MỚI, public, không CSRF, không auth
 ├ xác thực chữ ký Ed25519 (khoá công khai từ JWKS của fal) + token khó đoán trong URL
 ├ CAS processing → completed        (idempotent: fal gửi lại tới 31 lần)
 ├ TẢI ẢNH VỀ /storage NGAY          (URL của fal không sống mãi)
 ├ notify user (Notification đã có sẵn)
 └ trả 2xx trong <1 s
        │
        │   LƯỚI AN TOÀN — không phải đường chính
        ▼
GET /api/generations/{id}                  ← client poll 5 s (thay 500 ms)
 └ nếu > 20 s vẫn pending VÀ có meta.fal.request_id:
     hỏi status_url MỘT lần (timeout 5 s)
       ├ COMPLETED → chốt y hệt webhook (CAS lo phần trùng)
       ├ FAILED    → hoàn credit (studio_finalize_generation)
       └ IN_QUEUE  → trả queue_position THẬT để UI hiện "đang ở vị trí N"
```

### 2.2 Hợp đồng webhook của fal — ĐO ĐƯỢC từ tài liệu chính chủ

Nguồn: `fal.ai/docs/documentation/model-apis/inference/webhooks.md` (bản `.md` — trang HTML
là client-rendered nên phải đọc thẳng markdown mới có nội dung).

**Gửi:** thêm `?fal_webhook=<url>` vào URL submit. Response:
`{ request_id, gateway_request_id, status_url, response_url, cancel_url }`.

**Nhận:** `POST` tới webhook của ta, body:

```json
{
  "request_id": "123e4567-…",
  "gateway_request_id": "123e4567-…",
  "status": "OK",
  "payload": {
    "images": [{ "url": "https://v3b.fal.media/files/…", "content_type": "image/png",
                  "file_name": "image.png", "file_size": 1824075, "width": 1024, "height": 1024 }],
    "seed": 196619188014358660
  }
}
```
Lỗi: `status: "ERROR"` + `error` + `payload.detail[]`. Payload không serialize được JSON
thì `payload: null` + `payload_error`.

**Chữ ký (PHẢI kiểm):** bốn header `X-Fal-Webhook-Request-Id` · `X-Fal-Webhook-User-Id` ·
`X-Fal-Webhook-Timestamp` · `X-Fal-Webhook-Signature` (hex).
Chuỗi cần kiểm = bốn dòng nối bằng ký tự xuống dòng: request-id · user-id · timestamp ·
**hex(sha256(body thô))**. Giải mã chữ ký hex → bytes, verify **Ed25519** bằng khoá `x` (base64url)
trong `https://rest.fal.ai/.well-known/jwks.json` (cache 24 h). Lệch timestamp > **300 s** thì từ chối.

**Chính sách thử lại:** phải trả **2xx**. Lần đầu timeout **15 s**, lần thử lại **120 s**.
Trả 4xx/5xx hoặc lỗi mạng ⇒ thử lại **tăng dần, tối đa 31 lần**.
⚠️ **KHÔNG đi theo redirect** — trả `3xx` là **thất bại vĩnh viễn, không thử lại**.

### 2.3 NĂM CÁI BẪY ĐÃ ĐO — mỗi cái đều là lỗi IM LẶNG

| # | Bẫy | Bằng chứng | Cách chặn |
|---|---|---|---|
| **a** | ⚠️⚠️ **MASK CỦA FAL NGƯỢC VỚI MASK CỦA TA** | fal `fal-ai/flux-pro/v1/fill`: *"mask image where **white (255) marks pixels to erase** and **black (0) marks pixels to keep**"*. Ta (`StudioController::buildMaskImage`): *"**TRẮNG = giữ nguyên, ĐEN = vùng chỉnh sửa**"* | **ĐẢO mask trước khi gửi.** Gửi nguyên xi ⇒ fal sửa **đúng vùng ta muốn giữ**, không lỗi, không cảnh báo |
| **b** | Tưởng phải có URL công khai | Docs fal: *"Most inputs in the API accept file URLs. Whenever that's the case you can pass your own URL **or a Base64 data URI**"* | Gửi **data URI** ⇒ gỡ hẳn ràng buộc URL công khai. Ta đã có sẵn `mask_data` base64 |
| **c** | Model nào có mask? | Đo từ trang `/models/<id>/api`: `flux-pro/v1/fill` **mask_url BẮT BUỘC** · `qwen-image-edit` **có** · `nano-banana/edit` **KHÔNG** · `image-editing/background-change` **KHÔNG**. Và mask *"Needs to match the dimensions of the input image"* | Ánh xạ vào 2 chế độ UI: **"Tả" → model không mask · "Khoanh/Cọ" → model có mask**. Phóng/thu ảnh thì phóng/thu mask **cùng tỉ lệ** |
| **d** | **Redirect giết webhook im lặng** | Docs: *"Redirects are not followed… `3xx` is treated as a **permanent failure** and is **not** retried. Common pitfalls include `http://` URLs that redirect to `https://` and paths that redirect to add or remove a trailing slash."* | `APP_URL` phải **https public**, URL webhook phải là **URL cuối**, không dấu `/` thừa. **Có test khẳng định route sinh đúng URL đó** |
| **e** | Giữ URL ảnh của fal | Webhook docs: kết quả lưu **~1 giờ**, riêng kết quả ≥ 10 KB **~6 phút**. Bảng retention: generated media "Configurable" (không công bố mặc định) | **Tải về `/storage` NGAY trong webhook.** Dùng lại `storeRemoteImage()` — đừng viết mới |

**Thêm hai điều bắt buộc:**
- **Idempotent**: fal thử lại tới 31 lần ⇒ `studio_claim_generation()` (CAS) đã có sẵn, dùng đúng nó.
- **Allowlist SSRF**: nếu chủ dự án có đặt `studio.remote_image_hosts`, phải thêm `fal.media`,
  `v3.fal.media`, `fal.run`, `rest.fal.ai` — không thì `studio_fetch_remote_bytes()` trả `null`
  và **ảnh tải về thất bại mà không có cảnh báo nào** (`helpers.php:519–535`).

### 2.4 fal trả TIẾN TRÌNH THẬT — điểm cộng trực tiếp cho §12

Status endpoint trả `queue_position` khi `IN_QUEUE` và `metrics.inference_time` khi
`COMPLETED` (`queue.md`). Đợt 0.2 đã **gỡ thanh % mô phỏng** vì nó "lừa người dùng
đúng lúc dễ bỏ đi nhất". Nay có số THẬT để hiện:

> "Đang xếp hàng — còn 3 lượt trước bạn" → "Đang dựng ảnh…" → xong.

Đây là lần đầu tiên yêu cầu *"tiến trình phải là số đo của máy chủ, không phải của giao diện"*
(chú thích `agentChatLastMeta` trong `state.js`) thoả được cho khâu render ảnh.

### 2.5 Cái gì BỎ, cái gì GIỮ sau khi chuyển

| Bỏ | Ở đâu |
|---|---|
| `STUDIO_QUEUE_WORKER` + `queue:work` + cron hPanel **cho khâu render** | `config/studio.php:195`, `.env.example:100` |
| `processPendingInline()` + `QUEUE_FALLBACK_SECONDS` (giữ request 8 phút) | `StudioController.php:1457–1521` |
| Vai trò "chạy provider" của `RenderImageJob` / `RenderVideoJob` | `app/Jobs/` |
| `sleep()` 150 s trong `ImageAIService::tryFal()` và `FalImageGateway::awaitResult()` | `ImageAIService.php:419–450`, `FalImageGateway.php:131–167` |
| Poll 500 ms | `generation.js:352` → đổi thành **5 000 ms** |
| `/api/process` như một đường BẮT BUỘC | `StudioController.php:5221` — giữ làm nút "Thử lại" thủ công, không còn là cơ chế |

| Giữ | Vì sao |
|---|---|
| Laravel queue cho `CleanOrphanFilesJob` · `ReflectBrandMemoryJob` | Không chặn người dùng — nhưng **cần cron**, xem dưới |
| `studio_claim_generation` / `studio_finalize_generation` | Đã là CAS + hoàn credit ĐÚNG MỘT LẦN — webhook dùng lại y hệt |
| `FalProvider` · `FalImageGateway` (phần SDK) | Đã có; chỉ cần **bỏ vòng poll**, thêm `webhook_url` |

**Về cron:** máy chủ không có `crontab`. Cách đúng **không phụ thuộc hPanel** là **cron từ NGOÀI**:
một route `POST /api/cron/tick?token=<bí mật>` gọi bởi cron-job.org / GitHub Actions mỗi 5 phút.
Việc nhỏ, và nó gỡ hẳn món nợ "máy chủ không có cron" đã bị nhắc ở **nhiều đợt** trong DEPLOY_LOG.

---

## 3. XOÁ LAYER + BỎ TOOLBAR — CÒN LẠI ĐÚNG MỘT MÀN HÌNH

### 3.1 Bảng xoá / giữ

| Bỏ hẳn | Dòng | Vì sao không còn chỗ đứng |
|---|---|---|
| `LayersPanel.vue` | 323 | Không còn layer |
| `ContextToolbar.vue` (9 nhánh) | 211 | 9/9 nhánh là công cụ layer/canvas |
| `CanvasStatusBar.vue` | 210 | Zoom % · nền canvas · snap · undo layer |
| `RegionTools.vue` | 164 | Cột nút nổi trên canvas |
| `MultiSelectBar.vue` | 48 | **Đã chết sẵn** |
| `layerCore.js` (CRUD/nhóm/thứ tự/căn lề/chia đều) | 493 | Không còn layer |
| `layerTransform.js` phần canvas (composite/transform/kéo/snap) | ~430 | Giữ lại `savePromptMemory` / `restoreBarSettings` (không phải logic canvas) |
| `canvasView.js` phần zoom/pan/fit/crop-style | ~200 | Xem §3.2 — thay bằng 1 hàm |
| `pathTool.js` (Bezier pen kiểu Krita) | 332 | Công cụ hoạ sĩ; chưa có bằng chứng ai dùng |
| `brushes.js` phần paint/erase | 223 | Vẽ pixel tự do — không phải việc của sản phẩm này |
| `StudioApp.vue` | 1.919 → **~600** | Bỏ nhánh isolate/stack/tay cầm/marquee/crop/nhóm |

| **GIỮ — đây là phần phải cứu** | Dòng | Ghi chú |
|---|---|---|
| `maskBrush.js` — kéo rect (8 tay cầm) · cọ vẽ mask · **magic wand** · đảo vùng | 376 | Giữ **thuật toán**, viết lại **lớp toạ độ** |
| `maskSelect.js` — chốt/xoá mask | 146 | Giữ nguyên |
| `regionOps.js` — `_buildSelectionAlpha` (có feather) · `pushHistory`/`undo`/`redo` | 196 | Bỏ 4 hàm thao tác layer |
| `CanvasMaskTools.vue` | 296 | → lớp phủ của màn "Chỉnh ảnh" |
| Backend: `buildMaskImage` · `featherMaskEdges` · 2 đường inpaint | — | **Hợp đồng `mask_mode` + `region` + `mask_data` + `feather` KHÔNG ĐỔI** |
| `CompareSlider.vue` | 56 | Trước/sau — hợp mobile |
| `GalleryModal.vue` | 531 | Đã độc lập canvas |

**Tổng: xoá ~2.900 dòng · giữ và chuyển chỗ ~1.300 dòng.** `StudioApp.vue` giảm **~2/3**.

### 3.2 Hệ toạ độ mới: từ ~130 dòng xuống 1 hàm

Hôm nay, mọi công cụ mask đều đo qua `canvasView.js::canvasMetrics()` (dòng 62–86): đọc
`getBoundingClientRect()` của `canvasZoom` **và** `cvImg`, cộng `zoom` + `pan` +
`rotation` + `scale` + `flip`, có nhánh dự phòng `_domMetrics()` khi layer bị xoay/lật.

Ở màn "Chỉnh ảnh" chỉ có **MỘT ảnh, không xoay, không layer** ⇒ hình chữ nhật của thẻ `<img>`
**CHÍNH LÀ** vùng ảnh:

```js
// toàn bộ phép biến đổi toạ độ của editor mới
function toImageCoords(el, clientX, clientY) {
  const r = el.getBoundingClientRect();              // r = vùng ảnh THẬT trên màn hình
  return {
    nx: Math.min(1, Math.max(0, (clientX - r.left) / r.width)),
    ny: Math.min(1, Math.max(0, (clientY - r.top)  / r.height)),
  };
}
```

Đây là **điểm giảm phức tạp lớn nhất** của cả phương án: 130 dòng hình học + 21 chỗ đọc
`store.canvasZoom` biến mất, và loại luôn cả một lớp bug đã từng xảy ra thật — `canvasMetrics()`
sinh ra vì *"đo DOM khiến overlay/preview phụ thuộc thứ tự render, nên preview bị TRÔI"*.

---

## 4. UI MỚI — THIẾT KẾ MOBILE TRƯỚC, RỒI TABLET, RỒI DESKTOP

### 4.1 Sản phẩm còn đúng ba màn hình

| Màn | Route | Việc |
|---|---|---|
| **Tạo ảnh** | `/` | Ô mô tả + lưới kết quả |
| **Chỉnh ảnh** | `/sua-anh/{id}` | **inpaint + mask** — trái tim của yêu cầu này |
| **Thư viện / Bộ sưu tập** | `/thu-vien` · `/bo-suu-tap` | Đã có, giữ nguyên |

Điều hướng dưới (mobile) **4 mục**: `Tạo` · `Thư viện` · `Bộ sưu tập` · `Trợ lý` —
dùng lại dock sẵn có ở `StudioApp.vue:1439–1463`.

### 4.2 Màn "Chỉnh ảnh" — bản MOBILE (375 × 812) trước tiên

```
┌──────────────────────────────────┐
│ ← Chỉnh ảnh        [Trước/Sau] ⟳ │  header 56px, nút to ≥44px
├──────────────────────────────────┤
│                                  │
│         VÙNG ẢNH                 │  chiếm hết chỗ còn lại
│    (ảnh vừa khung, 1 ngón = vẽ,  │  touch-action:none
│     2 ngón = phóng/di chuyển)    │  + KÍNH LÚP 2× khi kéo cọ
│                                  │
├──────────────────────────────────┤
│ ▁▁▁▁  (tay nắm bottom sheet)     │
│ [Tả] [Khoanh] [Cọ] [Tẩy vùng]    │  4 nút, cao 48px
│ ┌──────────────────────────────┐ │
│ │ Tả thay đổi bạn muốn…        │ │  textarea tự giãn
│ └──────────────────────────────┘ │
│ Cỡ cọ ▬▬▬●▬▬▬  Làm mềm mép ▬●▬  │  chỉ hiện khi ở [Cọ]
├──────────────────────────────────┤
│  [     Sửa ảnh  ·  ~1 credit    ] │  STICKY, luôn thấy
└──────────────────────────────────┘  + env(safe-area-inset-bottom)
```

**Bốn quyết định thiết kế, mỗi cái vì một lý do đo được:**

1. **Bottom sheet 3 nấc** (`peek` → `half` → `full`). Ảnh phải luôn nhìn thấy; bàn phím ảo che
   nửa dưới màn hình nên ô mô tả **không được** nằm cố định ở đáy.
2. **KÍNH LÚP 2× khi kéo cọ.** Ngón tay che đúng chỗ đang vẽ — đây là chi tiết quyết định giữa
   "dùng được" và "vứt đi" trên điện thoại. Vẽ lớp phủ 2× phía trên ngón, lệch lên ~64 px.
3. **Hai ngón = phóng/di chuyển; một ngón = vẽ.** Chuẩn của Procreate/Photoshop iPad. Phải chặn
   zoom của trang: `touch-action: none` trên vùng ảnh + `overscroll-behavior: contain`.
4. **Nút chạy STICKY ở đáy + giá credit ngay trên nút** (§12 nguyên tắc 3: chi phí hiện TRƯỚC khi bấm).

### 4.3 Ba chế độ — và vì sao đây là thay đổi quan trọng nhất

Học đúng cách chia của OpenArt `/suite/edit-image`: *"**Describe, Annotate, Inpaint Area** to edit"*.

| Chế độ | Gửi lên | Model | Dùng trên |
|---|---|---|---|
| **Tả** (mặc định) | chỉ `prompt` — **KHÔNG mask** | model không có `mask_url` (nano-banana/edit…) | ☑ **ĐA SỐ**, và là đường **duy nhất chạy tốt trên điện thoại** |
| **Khoanh** | `mask_mode='rect'` + `region` | model có `mask_url` | Kéo một khung — nhanh, đủ chính xác |
| **Cọ** | `mask_mode='brush'` + `mask_data` | model có `mask_url` | Vùng méo, cần chính xác |

**Vì sao mask phải thành TUỲ CHỌN:** hôm nay `inpaint()` (`generation.js:356–391`) chỉ gửi mask khi
`inpaintMaskDone && _inpaintMaskKind`, nhưng **toàn bộ UI xoay quanh việc vẽ mask** —
`inpaintMaskMode` xuất hiện ở **78 chỗ trong 15 file**, cộng **39 khoá state** riêng cho vùng chọn.
Bắt người dùng điện thoại vẽ mask trước khi được sửa ảnh là **chặn ở bước khó nhất**. Đảo lại:
**tả trước, khoanh sau, và chỉ khi cần.**

### 4.4 Tablet (≥ 768 px)

- **Hai cột**: ảnh trái (lấy hết chỗ còn lại) · panel điều khiển phải **320 px**.
- Vẫn là cảm ứng ⇒ target **≥ 48 px**, không dùng hover làm lối vào duy nhất.
- Bottom sheet **biến mất**; panel phải là chỗ chứa công cụ. **Cùng một component**, chỉ đổi chỗ đặt.
- Có bàn phím rời ⇒ bật thêm phím tắt, nhưng **không bắt buộc**.

### 4.5 Desktop (≥ 1280 px)

- Vẫn hai cột; panel phải **380 px** (đủ cho tham số nâng cao: giữ nền · giữ mặt · feather · model).
- Phím tắt **chỉ là tăng tốc**: `B` cọ · `R` khung · `Z` hoàn tác · `Enter` chạy.
  **Không đường nào chỉ đi được bằng bàn phím-và-chuột.**
- Bảng lệnh `Ctrl+K` giữ nguyên (đã có, 4 nhóm nguồn) — nó là đường *tắt*, không phải đường *duy nhất*.

### 4.6 Số đo BẮT BUỘC đạt trước khi coi là xong

| Đo gì | Ngưỡng | Cách đo |
|---|---|---|
| Tràn ngang | `scrollWidth === innerWidth` | Chrome headless + CDP ở **320 · 375 · 414 · 768 · 1280** |
| Nút bấm được | `elementFromPoint` tại tâm trả về **chính nút** | cùng phiên |
| Kích thước vùng chạm | **≥ 44 px** (Apple) — nhắm **48 px** | đo `getBoundingClientRect` |
| Ô mô tả không bị bàn phím che | sau `focus()`, ô vẫn nằm trong `visualViewport` | CDP `Emulation.setDeviceMetricsOverride` |
| Vẽ mask bằng cảm ứng | toạ độ chuẩn hoá sai số **< 1 %** so với ảnh | gieo `Input.dispatchTouchEvent`, so `region` gửi lên |
| Lỗi JS | **0 exception** | `Runtime.exceptionThrown` |

Đây đúng là lối đã dựng ở **đợt 46** — dùng lại, đừng nghĩ lại từ đầu.

---

## 5. LỘ TRÌNH

| GĐ | Việc | Ngày | Cổng nghiệm thu |
|---|---|---|---|
| **0** | **Đo trước:** đếm số lần dùng layer-op / path / paint qua `POST /api/client-errors` (đường sẵn có). Đăng ký **cron ngoài** `POST /api/cron/tick` | 0,5 | Có số thật; nếu layer-op < 5 % số phiên thì đi tiếp |
| **1** | **Tách `workingImage` khỏi layer** — 6 điểm, mỗi điểm 1–2 dòng (xem tài liệu trước, §5 GĐ 1) | 1–2 | 131 test còn xanh, giao diện chưa đổi |
| **2** | **fal.ai thành hàng đợi:** route webhook + verify chữ ký + tải ảnh + CAS; submit không poll; poll 500 ms → 5 s | 3–4 | Ảnh về **không cần tab mở**; tắt `STUDIO_QUEUE_WORKER` mà mọi thứ vẫn chạy |
| **3** | **Màn "Chỉnh ảnh"** với 3 chế độ Tả/Khoanh/Cọ; bottom sheet; kính lúp; hệ toạ độ 1 hàm | 4–6 | Vẽ mask trên Chrome mobile giả lập, sai số < 1 % |
| **4** | **Xoá layer + toolbar** (§3.1) | 2–3 | `StudioApp.vue` ≤ 700 dòng; mỗi test bị xoá đi **cùng lúc** với mã nó khoá |
| **5** | **Kiểm bằng trình duyệt thật** — 5 khổ | 1 | Bảng §4.6 xanh hết |

**Tổng: ~12–17 ngày công.** GĐ 1–2 **đảo ngược được**; chỉ GĐ 4 là một chiều.

---

## 6. RỦI RO

| Rủi ro | Mức | Chặn |
|---|---|---|
| **Mask gửi sai chiều** ⇒ sửa đúng vùng muốn giữ | **CAO** | Test khoá: mask gửi đi **phải đảo** so với mask lưu; và một bài so pixel: vùng NGOÀI mask không đổi |
| Webhook không tới (URL redirect / http) | **CAO** | Test route webhook sinh **https** và **không** có `/` thừa; log mọi lần fal gọi vào; **lưới an toàn poll** bắt được cả khi webhook chết hẳn |
| Hoàn credit hai lần (webhook + poll cùng chốt) | Trung bình | `studio_claim_generation` CAS — **đã có**, đừng viết đường thứ hai |
| `ext-sodium` thiếu trên shared hosting | Trung bình | Kiểm `php -m` **trước**; nếu thiếu: vẫn kiểm **token khó đoán** + log cảnh báo, và ghi rõ đây là giảm bảo mật có ý thức |
| Giá fal khác giả định 1 credit/ảnh | Trung bình | **Bảng giá theo model** trước khi bật; `studio_credit_cost()` đang bị gọi ở **8 chỗ** |
| Ảnh 2K vượt giới hạn megapixel của model | Trung bình | Thu nhỏ **ảnh + mask cùng tỉ lệ** trước khi gửi; ghi độ phân giải thật vào `meta` |
| Người dùng đang dùng layer/path bị mất | **Cao** | GĐ 0 bắt buộc + báo trước + nút "Lưu bố cục ra ảnh" (bài học Playground AI — xem tài liệu trước §3.3c) |
| Cache/throttle chặn webhook của fal | Thấp | Route webhook: **không** `throttle`, **không** `auth`, **không** CSRF |

---

## 7. QUYẾT ĐỊNH — ĐÃ CHỐT 2026-09-26

| # | Quyết định | Hệ quả lên mã |
|---|---|---|
| **D1** | ✅ **Không cần crop** | Bỏ khỏi UI. Endpoint `/api/reframe` **giữ nguyên trong mã** (không xoá API), chỉ hết call-site. Bỏ 6 khoá state (`cropMode` · `cropBox` · `_cropDrag` · `_cropRaf` · `_cropPending` · `reframeRatio`) + ~120 dòng ở `canvasView.js` + khối crop ở `StudioApp.vue:1678–1693` |
| **D2** | ✅ **Bỏ hẳn paint/erase** | Xoá **cả file** `brushes.js` (223 dòng) + **27 khoá state** (`eraseMode` · `drawMode` + 25 khoá phụ) + 2 overlay `<canvas>` ở `StudioApp.vue:1669–1672` + nút Vẽ/Xoá ở `RegionTools.vue` |
| **D3** | ✅ **Giữ Qwen/DashScope trực tiếp làm dự phòng** | fal = đường chính; DashScope = fallback. ⇒ `ImageAIService` **không được xoá**, chỉ đổi thứ tự ưu tiên. ⚠️ **Nhưng số liệu giá ở tài liệu mới đề nghị ĐẢO VAI cho một nhóm việc** — xem `docs/CREDIT_GOI_VA_LOI_NHUAN.md` §3.5 |
| **D4** | ✅ **Dùng cron ngoài** | Gỡ hẳn phụ thuộc hPanel. Xem §2.5. Và **đo được: `crontab` không tồn tại trên máy chủ** ⇒ việc này còn **cứu 7 tác vụ định kỳ đang chết** (xem tài liệu mới §7) |
| **D5** | ✅ **Mặc định màn Chỉnh ảnh là "Tả"** | Không mask là đường mặc định ⇒ **vẫn giữ mask nhưng KHÔNG bắt buộc vẽ**. Đây là điều kiện để màn này chạy được trên điện thoại (§4.3) |

### Việc mới phát sinh từ bốn quyết định này

| Việc | Vì sao phát sinh |
|---|---|
| **Thêm `/api/webhooks/fal`** không auth, không CSRF, không throttle | Webhook của fal (§2.2) |
| **Tài liệu mới:** `docs/CREDIT_GOI_VA_LOI_NHUAN.md` | Bốn yêu cầu mới: mở full tính năng cho mọi gói · giá credit theo OpenArt · `ext-sodium` · theo dõi giá vốn để tính lợi nhuận |
| **`docs/PRICING_RESEARCH_2026-09-23.md`** (bảng giá OpenArt · fal · DashScope, có URL từng dòng) | Căn cứ cho mọi con số giá |
| ⚠️ **Cập nhật `PRICING.md`** §1.1/§2/§3 | Tài liệu đang ghi giá Qwen **sai +50…75 %** ⇒ người sau sẽ tin số sai |

---

## 8. TÓM TẮT MỘT CÂU

> Bỏ layer và toolbar **không phải** là cắt tính năng — nó là **bỏ đúng cái vỏ đang chặn mobile**;
> giữ inpaint + mask **không phải** là giữ nguyên cách cũ — nó là **đổi mask từ bắt buộc thành tuỳ chọn**;
> và chuyển hàng đợi sang fal **không phải** là đổi nhà cung cấp — nó là **thôi bắt máy chủ shared
> hosting giữ request 8 phút và chờ một cái cron không tồn tại**.
> Ba việc, một hướng: **máy chủ ra lệnh, fal chạy, người dùng nhìn thấy tiến trình thật.**
