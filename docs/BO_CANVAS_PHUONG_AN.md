# BỎ CANVAS — PHÂN TÍCH & PHƯƠNG ÁN

> **Trạng thái: ĐỀ XUẤT — chưa sửa một dòng mã nào.**
> Ngày lập: 2026-09-26 · Phạm vi: giao diện `/studio` (`StudioApp.vue` + `store/actions/*`).
> Mọi con số trong tài liệu này **đo được từ chính repo**, không phải ước lượng.

---

## 0. KẾT LUẬN NGẮN

1. **Bỏ canvas được, và rẻ hơn vẻ ngoài của nó.** Không có endpoint nào nhận `layers[]`; bố cục
   canvas **chỉ sống trong `localStorage`**, không có một bản ghi nào ở máy chủ để phải di trú.
2. **Nút thắt thật sự chỉ có 6 điểm rò rỉ, mỗi điểm 1–2 dòng** — không phải "viết lại app".
3. **Nhưng "bỏ canvas" không có nghĩa là bỏ hết công cụ đang nằm trên canvas.** Ba thứ đang chạy
   trên canvas vẫn phải còn, chỉ đổi chỗ: **sửa vùng (mask)**, **cắt khung**, **xem ảnh lớn**.
   Đề xuất: **canvas từ "cái vỏ" xuống "một công cụ"** — mở ra khi cần, đóng lại khi xong.
4. **Đích đến trùng với hướng đi đã chốt ở đợt 46**: "trợ lý là trung tâm · trả canvas trống sạch".
   Tài liệu này là bước tiếp theo của đúng hướng đó, không phải đổi hướng.

---

## 1. HIỆN TRẠNG: CANVAS ĐANG LÀ **CÁI VỎ**, KHÔNG PHẢI MỘT TÍNH NĂNG

### 1.1 Số đo

| Chỉ số | Giá trị | Nguồn |
|---|---|---|
| Khoá state trong `store/state.js` | **377** | đếm dòng khai báo |
| Khoá **chỉ canvas dùng** | **119 (31,6 %)** | `state.js:117–475` |
| File actions | 16 file · **6.245 dòng** · 425 hàm | `store/actions/*.js` |
| Actions **canvas thuần** | **8 file · 2.649 dòng (42,4 %) · 217 hàm (51,1 %)** | canvasView · layerCore · layerTransform · maskBrush · maskSelect · pathTool · regionOps · brushes |
| Component | 69 file · **19 chạm canvas · 50 không chạm** | `components/**` |
| Component **chỉ sống vì canvas** | **6 file · 1.314 dòng** | ContextToolbar · CanvasMaskTools · CanvasStatusBar · RegionTools · CanvasEmptyState · LayersPanel |
| Route HTTP | **241** · canvas có call-site ở **9** (3,7 %) | `routes/web.php` |
| Test class | 131 · **13 phụ thuộc canvas** | `tests/**` |
| `StudioApp.vue` | **1.919 dòng — 85 lần** đọc state/hàm canvas | `StudioApp.vue` |

### 1.2 Bố cục hiện tại (kiểu IDE, không kiểu "app tạo ảnh")

```
┌─ header: navbar + bộ sưu tập + credit + tài khoản ─────────────────────┐
├────┬──────────────────────────────────────────┬───────────────────────┤
│    │  rail công cụ ngữ cảnh (h-12)            │  BẢNG LAYERS          │
│ A  │ ┌──────────────────────────────────────┐ │  (dock phải, kéo được)│
│ c  │ │                                      │ │                       │
│ t  │ │        VÙNG CANVAS (canvasZoom)      │ ├───────────────────────┤
│ i  │ │  zoom · pan · mask · crop · vẽ · xoá │ │  DOCK OUTPUTS (156px) │
│ v  │ │  tay cầm layer · marquee · snap      │ │                       │
│ i  │ └──────────────────────────────────────┘ │                       │
│ t  │  [ChatFab]                               │                       │
│ y  │  ── CanvasStatusBar (zoom % · nền · snap · undo) ──                │
└────┴──────────────────────────────────────────┴───────────────────────┘
   ↑ card công cụ nằm trong CỘT TRÁI, canvas là TRUNG TÂM
```

Đây là **Photoshop/VSCode**, không phải **OpenArt**. Ba hệ quả trực tiếp:

- Mọi việc đều **đi qua canvas**: `OutputModule.vue` gọi `store.select(g)` → `pushCanvasLayer()`
  → `setActiveLayer()` (dòng 44–47) trước khi mở công cụ Biến thể / Sửa ảnh.
- **Mọi ảnh tạo ra tự nhảy vào canvas**: `account.js:90` — `addGen()` đẩy mọi generation có
  `media_url` thành một layer rồi chọn nó làm layer đang làm việc.
- **Tám card công cụ không tự chọn được ảnh nguồn** — chúng đọc ảnh từ canvas qua một getter duy
  nhất: `upscaleSrc` (`getters.js:11`). `SuggestCard.vue` ghi thẳng trong chú thích:
  *"① Ảnh nguồn (bắt buộc — lấy từ canvas/Thư viện)"*.

### 1.3 Sự thật quan trọng nhất: canvas đang bị dùng như một **bảng ảnh**, không phải một **trang ghép**

`layerCore.js:_positionByImageSize()` (dòng 140–165) tự xếp ảnh mới vào **luồng lưới**:
cạnh dài tối đa 512 px · khe 24 px · **3 ảnh một hàng rồi tự xuống dòng**.

Nghĩa là hành vi mặc định của canvas là **dàn ảnh ra cạnh nhau để nhìn** — đúng việc của một
**thư viện ảnh**, chỉ khác là nó làm việc đó bằng zoom/pan/tay cầm/undo/quota thay vì bằng lưới.

Kèm theo đó là ba cái giá đã trả thật, ghi ngay trong mã:

| Cái giá | Bằng chứng |
|---|---|
| **Ảnh base64 nhét vào localStorage** — trần ~5 MB | `layerTransform.js:392–405`: gọi ở **57 chỗ**; `QuotaExceededError` trước đây bị nuốt, nay phải báo *"Trang vẽ quá lớn để tự lưu vào trình duyệt — bố cục có thể mất khi tải lại."* |
| **Mất bố cục khi vượt quota** — dữ liệu người dùng, không phải cache | cùng dòng trên |
| **Layer cũ thiếu `baseW/baseH` ⇒ không có tay cầm, căn lề sai** | `layerTransform.js:481–496` (`ensureLayerSizes`) — phải tự vá sau khi khôi phục |

---

## 2. VÌ SAO "CANVAS LÀM VỎ" SAI VỚI SẢN PHẨM NÀY

Đối chiếu với chính ba persona đã chốt ở `docs/DESIGN_SYSTEM.md` §11:

| Persona | Việc hằng ngày (§11) | Canvas giúp được gì? |
|---|---|---|
| **Nhà thiết kế thời trang** | Ra ý tưởng → nhiều biến thể → lookbook | **Gần như không.** Việc là *sinh ảnh*, không phải *ghép pixel* |
| **Chủ doanh nghiệp** | Duyệt bộ sưu tập, chi phí, giao việc | **Không.** Họ không mở canvas |
| **Chủ xưởng may** | Ảnh kỹ thuật, mô tả chất liệu, gói file | **Không.** Đường của họ là phiếu kỹ thuật + ZIP |

Và bảy nguyên tắc UX bắt buộc (§12) đang **bị canvas cản**:

| Nguyên tắc | Canvas cản thế nào |
|---|---|
| **1. Một việc = một luồng** | Người dùng phải chọn *công cụ* trên activity bar, rồi hiểu rằng *công cụ lấy ảnh từ canvas*, rồi biết ảnh nào đang là layer active |
| **3. Chi phí hiện TRƯỚC khi bấm** | Không sai, nhưng nằm lẫn trong chrome của IDE |
| **5. Kết quả luôn có bước tiếp theo** | Có 4 nút — nhưng nút "Sửa" **bắt buộc đi qua canvas** (`select(g)` trước) |
| **7. Trạng thái luôn nhìn thấy** | "Đang làm ảnh nào" chỉ hiện ra gián tiếp qua viền layer đang chọn |

Thêm ba lập luận kỹ thuật:

1. **Không có đường nào cần canvas.** `/api/compose` nhận `images[]` (mảng **URL**, tối đa 3) rồi
   để **AI** ghép — không phải ghép pixel trên trình duyệt. `grep "'layers'"` trong
   `app/Http/Controllers/` = **0 kết quả**.
2. **Không có gì để di trú.** Bố cục layer nằm **duy nhất** ở `localStorage['fabrikai.layers']`.
   Bỏ canvas không làm mất một dòng CSDL nào.
3. **Mặt bằng trình duyệt đã chật.** Sau đợt 46, đã phải **gỡ hẳn ô mô tả tạo ảnh khỏi canvas**
   (*"BA CHỖ VIẾT MỘT VIỆC"* — `CanvasEmptyState.vue`) và đo trên Chrome thật ở **375 / 320 / 414 px**
   rằng nút nổi phải né thanh dưới, không tràn ngang. Một IDE ba cột trên màn 375 px là bố cục
   chống lại người dùng.

---

## 3. ĐỐI CHIẾU: CÁC APP TẠO ẢNH "THÔNG THƯỜNG" LÀM GÌ

> ### ⚠️ ĐÍNH CHÍNH (2026-09-26) — bản đầu của tài liệu này viết SAI một chi tiết
> Bản đầu viết *"OpenArt để canvas ở `/suite/world`, ngang hàng với `/suite/edit-image`"* — **SAI**.
> Khảo sát sâu hơn (render DOM bằng headless Chrome · **đo toạ độ layout bằng Chrome DevTools
> Protocol** · dump 38 feature flag của Suite · so byte-level hai trang · đối chiếu Wayback 2023→2025)
> cho thấy: **OpenArt hiện KHÔNG có canvas.**
>
> - `/suite/canvas` trả **HTTP 200** nhưng render **đúng nội dung của `/suite/video`** (so byte-level:
>   trùng danh sách tool *Frame to Video · Text to Video · Smart Shot · Edit Video · Lip-Sync…*) ⇒ là
>   **route cũ**, không phải canvas.
> - Grep DOM 3 trang `/suite/create-image`, `/suite/edit-image`, `/suite/canvas`: chữ "canvas" chỉ có
>   trong **URL analytics** và **feature-flag `spotify-canvas-allowlisted`** (định dạng Spotify Canvas).
> - 38 feature flag của Suite: **không có flag nào** về canvas/board/whiteboard.
> - `/suite/world` là **"Create World · Browse Library · 3D World Cam · Cast in Scene"** — **thế giới 3D**,
>   không phải board 2D.
> - Wayback 4 mốc 2023-10 → 2025-03: nav tiến hoá `Discover/Create/Train` → `Create/Edit/Discover/Train`
>   → `CREATE` + `EDIT` — **0 lần nhắc "canvas"** ở bất kỳ giai đoạn nào.
>
> Kết luận đúng **mạnh hơn** kết luận cũ: OpenArt không "hạ canvas xuống hàng tool" — **họ không có nó**.

### 3.1 OpenArt — toạ độ THẬT của `/suite/create-image` (viewport 1500×807)

URL thật sau render: `openart.ai/suite/create-image/nano-banana-2` — **model là một path segment**.
Bố cục **3 cột, KHÔNG có canvas**:

| Cột | Đo được |
|---|---|
| **Trái** (x 0→264) | nav rail: `Home` · `Agents` · toggle `Chat Mode` / `Director Mode` · `Tools` · `Assets` · `Inspire` · `Pinned Tools` |
| **Giữa** (x 264→700) | **FORM**: `Create Image` · `Model → Nano Banana 2` · prompt **"Describe your image"** · ảnh tham chiếu (`Images | Characters & Worlds`, `0/14`) · `Auto Polish` · **`Output → 4:3 | 1K`** · bộ đếm **`/8`** · nút `Create for Free` |
| **Phải** (x 765→1467) | **THƯ VIỆN ASSET**: tab `Unsorted · Labels · Folders · Templates` + `Search`, chọn nhiều + `Download` |

Sửa ảnh là **route riêng `/suite/edit-image`**, phụ đề chính thức *"Describe, **Annotate, Inpaint
Area** to edit"*, điểm vào *"Drag and drop to upload, **or choose from History**"*.
Mỗi tool một trang: `/suite/create-image` · `/suite/create-video` · `/suite/edit-image` ·
`/suite/modify-video` · `/suite/animate-video` · `/suite/lip-sync` · `/suite/audio` ·
`/suite/character` · `/suite/assets` · `/suite/inspire` · `/suite/ai-tools` …
Legacy `/create` nay **redirect sang `/image/create`** kèm banner *"Go to the new workspace"*.

### 3.2 Toàn cảnh: canvas nằm ở đâu trong từng app

| App | Màn hình mặc định | Có canvas? | Vị trí canvas trong luồng |
|---|---|---|---|
| **OpenArt** | form + asset library | **KHÔNG** | — |
| **Krea** | `Home` hub (đo: `Home` là mục duy nhất `data-active="true"`) | Có — **`Node Editor`** ở `/nodes` | item **thứ 5** trong sidebar; `Realtime` chỉ là 1 tool + 1 shortcut ở Home |
| **Leonardo** | home page prompt bar + **feed** | Có — Canvas Editor (route riêng) + Realtime Canvas | Realtime **giấu trong menu `More`**; tài liệu tự khuyên dùng Editor thay thế |
| **Freepik/Magnific** | form + preview mỗi tool | Có — **`Spaces`** (node canvas) | dưới nhóm `Workspaces`, ngang hàng Image/Video/3D |
| **Ideogram** | prompt box + community feed | **Canvas + Editor = LEGACY** | *"not visible to new accounts and no longer developed"* — thay bằng **Image Studio** |
| **Playground** | template + prompt box | **ĐÃ XOÁ** | Canvas khai tử **30/9/2024**; Board **6/1/2025** |
| **Midjourney web** | prompt bar + feed | Không (chỉ lightbox edit) | — |
| **Recraft** | **Projects → canvas project** | **Có — và LÀ màn hình làm việc** | **Ngoại lệ duy nhất thật sự canvas-first** |
| **Higgsfield** | tool picker / Explore | Có (`/canvas`, node board) | top-nav, phải bấm "Canvas → New canvas" |

### 3.3 Ba bài học đắt nhất, có nguồn

**(a) Ideogram — cách hạ cấp canvas ĐÚNG: neo vào một ảnh, không bỏ board trắng.**
Tài liệu hiện hành: *"Legacy: Canvas and the Editor are legacy features — they are **not visible to
new accounts and are no longer developed**. **Image Studio is their replacement** for editing
workflows."* Và Image Studio *"**starts by asking for an image**: Upload image… or Browse Library"*.
→ **Đúng là GĐ 3 của tài liệu này**: editor mở ra TỪ MỘT ẢNH, không phải từ một board trắng.

**(b) Leonardo — giảm ma sát hơn cả một editor: Inline Editor.**
*"Omni Editing… facilitated by the **Inline Editor**, a **prompt bar that appears when viewing a
generated image**"* — sửa ngay khi đang xem, không rời trang.
→ **Bổ sung cho GĐ 2:** nút **Sửa** trên thẻ kết quả chỉ cần mở **thanh mô tả ngay trên ảnh lớn**
(`GalleryModal.vue` đã có sẵn); chỉ mở **editor đầy đủ** (GĐ 3) khi người dùng thật sự cần mask.
Leonardo cũng nói thẳng canvas là tạm thời: *"**Anything that happens in Canvas editor is temporary**"*.

**(c) Playground AI — bài học ĐẮT NHẤT, và nó về CON NGƯỜI, không phải kỹ thuật.**
Canvas khai tử **30/9/2024**, Board **6/1/2025**. Phản ứng người dùng, nguyên văn:
> *"there's no board, there's no canvas. It's all gone… I cannot view any of the images I previously
> made, at all. So all my hard work for months is just gone. Lost. Evaporated… **they told no-one.
> No email or nothing.**"*
> *"removing the canvas feature from their website is truly their **biggest pitfall**."*

Và thứ người dùng thương tiếc **không phải tấm board** mà là **năng lực sửa cục bộ**:
> *"I could crudely draw a different outfit in myself and it would generate **OVER** my image, keeping
> all relevant part exactly the same."*
> *"**I'm not interested in canvas**, but I loved the 'create variations'."*

→ **Ba luật BẮT BUỘC cho GĐ 4 của FabrikAI:** (1) **báo trước** trong Ứng dụng; (2) **cho thời gian
và ĐƯỜNG XUẤT** — thêm nút "Lưu bố cục canvas ra ảnh" chạy `compositeVisible()` → `/api/layers/save`;
(3) **tuyệt đối không làm mất ảnh người dùng** — ảnh trong Thư viện/Outputs đã ở máy chủ nên an toàn,
**chỉ bố cục là ở localStorage**, phải xuất nốt nó trước khi xoá.

### 3.4 Điểm chung rút ra

| Đặc điểm | Chuẩn của thị trường | FabrikAI hiện tại |
|---|---|---|
| Đơn vị điều hướng | **Công cụ = một route** (`/suite/edit-image`, `/apps/edit/remove-object`) | Canvas = cái vỏ, công cụ là panel bên trái |
| Màn hình mặc định | **Form + LƯỚI KẾT QUẢ** | Canvas trống + rail công cụ |
| Sửa ảnh | **Không gian riêng**: route riêng (OpenArt) · neo-ảnh (Ideogram) · prompt bar nổi (Leonardo) | Sửa tại chỗ trên canvas nhiều layer |
| Canvas | **Tầng THỨ HAI**, có lối vào TỪ gallery ("Open in Canvas") | **Là toàn bộ sản phẩm** |
| Lịch sử | Luôn có **Library/Assets/History dạng lưới tách riêng** — kể cả Recraft | Outputs có, canvas thì ở localStorage |
| Mobile | **Không app nào đặt cược canvas trên mobile** (Recraft tự nhận canvas editor là desktop-only) | Canvas chạy cả trên 375 px |

> **Kết luận §3:** "cách thông thường" **không phải** là "có canvas nhưng để ở chỗ khác" — mà là
> **form + lưới kết quả là mặc định, canvas là tầng thứ hai (nếu có)**. OpenArt đi xa nhất: **bỏ hẳn**.
> FabrikAI đang làm **ngược lại**: biến tầng thứ hai thành tầng duy nhất.

---

## 4. PHƯƠNG ÁN ĐỀ XUẤT: **HẠ CANVAS TỪ "CÁI VỎ" XUỐNG "MỘT CÔNG CỤ"**

Không phải "xoá sạch rồi làm lại". Ba câu mô tả đúng tinh thần:

> **1.** Đổi chỗ: **form + lưới kết quả** thành trung tâm, canvas thành công cụ mở khi cần.
> **2.** Đổi khái niệm: **"layer đang chọn" → "ảnh đang làm việc"** (một ảnh, không phải một chồng).
> **3.** Giữ lại đúng ba thứ canvas làm tốt, dưới dạng **editor một ảnh**: sửa vùng, cắt khung, xem lớn.

### 4.1 Kiến trúc đích

```
┌─ header (GIỮ NGUYÊN: bộ sưu tập · credit · tài khoản) ─────────────────┐
├──────────────────────────────┬─────────────────────────────────────────┤
│  CỘT CÔNG CỤ (~380px)        │  LƯỚI KẾT QUẢ (phần còn lại)            │
│                              │                                         │
│  [Tạo ảnh ▾] công cụ đang    │  ┌────┐ ┌────┐ ┌────┐ ┌────┐             │
│  chọn (ConceptCard /         │  │    │ │    │ │    │ │    │             │
│  Variation / Sửa ảnh /       │  └────┘ └────┘ └────┘ └────┘             │
│  Upscale / Studio / Outfit / │  mỗi thẻ: Tải · Biến thể · Sửa ·         │
│  Director / Gợi ý từ ảnh)    │           Upscale · Dùng làm nguồn        │
│                              │                                         │
│  Ảnh nguồn: [thẻ ảnh + X]    │  (lọc: phiên này · bộ sưu tập · tất cả) │
│  -- prompt -- tham số --     │                                         │
│  [Chạy · ~1 credit]          │                                         │
└──────────────────────────────┴─────────────────────────────────────────┘
   Canvas KHÔNG còn ở đây. Nó mở ra như một MODAL/TRANG khi bấm "Sửa vùng" / "Cắt khung".
```

**Ánh xạ route đề xuất:**

| Route | Việc | Ghi chú |
|---|---|---|
| `/studio` (giữ nguyên `/`) | Tạo ảnh: form + lưới kết quả | Mặc định |
| `/studio/tao-anh`, `/studio/bien-the`, `/studio/man-thu`, `/studio/ghep-trang-phuc`, `/studio/studio-anh`, `/studio/upscale`, `/studio/kich-ban`, `/studio/goi-y-tu-anh` | **Mỗi công cụ một URL** (đúng lối OpenArt `/suite/create-image`) | Dán link được, back/forward chạy, Analytics đếm được từng công cụ |
| `/studio/sua-anh/{id}` | **Editor một ảnh** (mask · cắt · vẽ · xoá) | Tương đương `/suite/edit-image` |
| `/studio/ban-ghep` | **Canvas/bảng ghép** (nếu giữ) | Tương đương `Node Editor` của Krea (`/nodes`) hay `Spaces` của Freepik — **tầng thứ hai, không phải cửa vào** |
| `/thu-vien` | Thư viện (đã có `LibraryApp.vue`) | Giữ |
| `/bo-suu-tap` | Bộ sưu tập (đã có) | Giữ |

---

## 5. KẾ HOẠCH DI TRÚ THEO GIAI ĐOẠN

> Nguyên tắc: **mỗi giai đoạn là một bản deploy xanh được**, có test, có thể dừng lại giữa đường.
> Không có giai đoạn nào là "big bang".

### GĐ 0 — ĐO TRƯỚC KHI QUYẾT (0,5 ngày)

Trước khi xoá thứ gì, **đếm xem ai dùng cái gì**. Repo đã có sẵn đường ghi log phía trình duyệt
(`POST /api/client-errors`, §6.5) — dùng đúng đường đó để ghi **tên công cụ** (không ghi nội dung ảnh):

- `mask` (rect/brush/lasso/path/magic) · `crop` · `draw` · `erase` · `layer-op` (nhóm/căn lề/đổi thứ tự)
- `canvas-open` (đếm số lần canvas thực sự có >1 layer)

**Cổng quyết định:** nếu trong 2 tuần, **layer-op < 5 % số phiên**, thì GĐ 3–4 dưới đây là an toàn.
Nếu cao, giữ canvas lại làm `/studio/ban-ghep` và chỉ làm GĐ 1–2.

### GĐ 1 — TÁCH "ẢNH ĐANG LÀM VIỆC" KHỎI "LAYER" (1–2 ngày) ⭐ **QUAN TRỌNG NHẤT**

Đây là bước **không đổi một pixel giao diện** nhưng tháo được nút thắt. Làm đúng bước này thì mọi
bước sau chỉ là đổi bố cục.

| # | Việc | Chỗ sửa |
|---|---|---|
| 1 | Thêm `store.workingImage = { id, url, name, kind: 'generation'\|'upload'\|'source' }` + `setWorkingImage(g)` | `store/state.js`, action mới |
| 2 | **Định nghĩa lại `upscaleSrc` / `upscaleName`** đọc từ `workingImage` (giữ fallback cũ trong giai đoạn chuyển) | `getters.js:11–12` — **một cửa duy nhất**, 6 card hưởng lợi ngay |
| 3 | `addGen()`: **ngừng** `pushCanvasLayer` — chỉ `setWorkingImage` | `account.js:90` (1 dòng) |
| 4 | `OutputModule` nút "Sửa"/"Biến thể": gọi `setWorkingImage(g)` thay vì `select(g)` | `OutputModule.vue:44–47` |
| 5 | `projects.js:goEditor`: gọi `setWorkingImage` thay vì `select` | `projects.js:391` |
| 6 | `sources.js:setSource`: đặt `workingImage`, bỏ tạo layer `source` | `sources.js:43–45` |

**Kiểm chứng GĐ 1:** mở ảnh từ Outputs → bấm "Sửa ảnh" → card Sửa ảnh nhận **đúng ảnh đó**; canvas
vẫn hiển thị như cũ (chưa đổi gì). **Toàn bộ 131 test phải còn xanh.**

### GĐ 2 — LƯỚI KẾT QUẢ THÀNH MẶT CHÍNH (2–3 ngày)

| Việc | Tái dùng gì |
|---|---|
| Thay `<main>` vùng canvas bằng **lưới kết quả** | Thẻ ảnh + thanh hành động đã có sẵn trong `OutputModule.vue` (135 dòng) — **mở rộng nó, không viết lại** |
| Bấm ảnh = **xem lớn** | `GalleryModal.vue` (531 dòng, **đã độc lập canvas**) |
| Card công cụ chuyển từ cột trái sang **cột công cụ** | Giữ nguyên `<component :is>` + `ACTIVITY_CARDS`; chỉ đổi khung bao |
| Ảnh nguồn hiện **ngay trong card** (thẻ ảnh + nút X) | `SourceLibraryPicker.vue` (223 dòng, đã độc lập canvas) |
| URL theo công cụ | Dùng lại `syncUrl()` sẵn có ở `StudioApp.vue:118` |
| **Nút «Sửa» mở THANH MÔ TẢ ngay trên ảnh lớn** (không mở editor) | Lối **Inline Editor** của Leonardo — `GalleryModal.vue` chỉ cần thêm một ô prompt + nút chạy. Rẻ hơn cả GĐ 3 và phủ phần lớn nhu cầu sửa |

**Kết quả GĐ 2:** người dùng mới vào `/studio` thấy **ô mô tả + lưới ảnh** — đúng mô hình OpenArt.
Canvas vẫn còn nhưng đã bị đẩy xuống dưới (chỉ mở khi bấm "Ban ghép").

### GĐ 3 — EDITOR MỘT ẢNH (3–5 ngày) — **đây là chỗ tái dùng, không phải chỗ xoá**

Mở `/studio/sua-anh/{id}`: ảnh **vừa khung**, không pan/zoom tự do (chỉ zoom trong editor), công cụ
nằm ở rail dưới, nút **Xong** trả kết quả về lưới.

| Giữ nguyên (chỉ đổi hệ toạ độ) | File |
|---|---|
| Mask rect (8 tay cầm) · cọ vẽ mask · lasso · **magic wand** · đảo vùng | `maskBrush.js` (376 dòng) |
| Chốt/xoá mask | `maskSelect.js` (146 dòng) |
| Xoá vùng · tô vùng · nhân bản vùng · tách vùng nổi · **undo/redo** | `regionOps.js` (196 dòng) |
| Cọ vẽ + cọ xoá (có áp lực bút, blend) | `brushes.js` (223 dòng) |
| Cắt khung theo tỉ lệ | `cropStyle/initCropBox/cropStart/confirmCrop` trong `canvasView.js` |
| Uprecale · Look · Reframe · Inpaint | backend **không đổi** |

**Cắt bớt trong editor** (không đưa sang): `pathTool.js` (332 dòng — Bezier pen kiểu Krita) và
`regionOps.floatSelectedRegion`. Đây là công cụ cho hoạ sĩ chuyên nghiệp; nếu GĐ 0 chứng minh có
người dùng thật thì giữ lại theo yêu cầu, **không giữ theo mặc định**.

**Kiểm chứng:** mask `mask_data` gửi lên **giống hệt** trước và sau (cùng hợp đồng
`mask_mode` + `region` + `mask_data` + `feather`) ⇒ backend không phải sửa.

### GĐ 4 — DỌN (2–3 ngày)

| Xoá | Dòng | Ghi chú |
|---|---|---|
| `LayersPanel.vue` | 323 | Trừ khi chọn giữ `/studio/ban-ghep` |
| `CanvasStatusBar.vue` | 211 | Nền canvas/snap/undo: chuyển vào editor |
| `ContextToolbar.vue` | 211 | 9 nhánh đều là công cụ canvas |
| `RegionTools.vue` | 164 | Rail nổi → rail của editor |
| `CanvasMaskTools.vue` | 296 | → lớp phủ của editor |
| `MultiSelectBar.vue` | 48 | **Đã chết sẵn** (chỉ còn 2 dòng comment trong `StudioApp.vue`) |
| `layerCore` nhóm/thứ tự/căn lề/chia đều · `layerTransform` composite/transform | ~700 | Giữ lại phần `savePromptMemory`/`saveBarSettings` (**không phải logic canvas**) |
| Khoá `localStorage['fabrikai.layers']` | — | **Thêm một lần dọn khoá cũ** — không thì ~5 MB dữ liệu mồ côi nằm lại trong máy khách mãi mãi |
| — | — | **BẮT BUỘC trước khi xoá: nút «Lưu bố cục ra ảnh»** (`compositeVisible()` → `/api/layers/save`) + **báo trước trong Ứng dụng**. Lý do ở §3.3(c): đây đúng là chỗ Playground AI mất người dùng |
| `StudioApp.vue` | 1.919 → **khoảng 700–900** | Sau khi bỏ nhánh isolate/stack/tay cầm/marquee/crop |

**Ước lượng tổng:** xoá/ngưng dùng **~2.000–2.500 dòng**; **giữ và chuyển chỗ ~1.300 dòng**
(mask + cọ + vùng + crop); `StudioApp.vue` giảm **hơn một nửa**.

### GĐ 5 — KIỂM CHỨNG BẰNG TRÌNH DUYỆT THẬT (1 ngày)

Theo đúng lối đã dựng ở đợt 46: **Chrome headless + CDP**, bốn khổ **375 · 320 · 414 · 1280**.
Đo: không tràn ngang · mọi nút bấm được (`elementFromPoint`) · lưới kết quả cuộn đúng ·
`textarea` của form đếm đúng · **0 exception**.

---

## 6. CÁI GÌ MẤT — NÓI THẲNG

| Mất | Ai mất | Phương án |
|---|---|---|
| **Ghép nhiều ảnh bằng pixel** (layer chồng, opacity, blend, nhóm, căn lề, chia đều) | Chỉ người dùng tự nguyện đi tìm tính năng này | **Đây là lý do duy nhất để giữ `/studio/ban-ghep`.** Nếu chủ dự án xác nhận không ai dùng → bỏ hẳn |
| **Undo/redo toàn cục 50 bước** trên cả trang | Người dùng canvas | Undo/redo **thu về trong editor một ảnh** (`regionOps.js:162–196` giữ nguyên) |
| **Bezier pen / path tool** | Hoạ sĩ chuyên nghiệp | Cắt mặc định; xem GĐ 0 |
| **Snap theo lưới · marquee chọn nhiều · bàn phím tắt layer** | Người dùng thành thạo | Mất cùng canvas; bảng lệnh `Ctrl+K` **giữ nguyên** |
| **Bố cục canvas tự khôi phục khi tải lại** | Người dùng quen "để đó mai làm tiếp" | **Thay bằng thứ tốt hơn: bộ sưu tập (đã có, lưu ở máy chủ)** — hiện canvas lưu ở localStorage nên đổi máy là mất |
| **`/api/layers/save`** ("Lưu Output" cho layer ghép) | Người dùng canvas | Route **giữ nguyên** (không xoá API), chỉ không còn call-site. Editor vẫn lưu kết quả qua đường generation thường |

---

## 7. ẢNH HƯỞNG TỚI MÁY CHỦ: **GẦN NHƯ BẰNG KHÔNG**

| Hạng mục | Kết luận |
|---|---|
| Route mới bắt buộc | **0** |
| Route phải sửa | **0** — hợp đồng `/api/inpaint` (`mask_mode` · `region` · `mask_data` · `feather`) giữ nguyên |
| Route mất người dùng | `POST /api/layers/save` (1 call-site) · `POST /api/generations/{id}/region` (**đã không còn call-site trong SPA**) |
| Di trú dữ liệu | **Không có.** Bố cục layer chỉ ở `localStorage` |
| Việc **nên** làm thêm (không bắt buộc) | Phiên tạo ảnh lưu ở **máy chủ** (như `design-agent/session` đã làm) để "đang làm dở" theo được sang máy khác — hiện đã mất khi đổi máy |

---

## 8. RỦI RO & CÁCH CHẶN

| Rủi ro | Mức | Cách chặn |
|---|---|---|
| Người dùng thật đang dùng layer/ghép | **Cao** | **GĐ 0 bắt buộc chạy trước** — đo bằng số, không bằng cảm tính |
| 22 test trong `CanvasControlsTest.php` (608 dòng) đỏ hàng loạt | Trung bình | Mỗi giai đoạn xoá test **cùng lúc** với mã nó khoá; thay bằng test của luồng mới (**không** để test đỏ kéo dài — §14 luật E) |
| `DesignSystemTest` :492 đọc `maskBrush.js` (token `maskVeil()`) | Thấp | Giữ file mask ⇒ test này **không đổi** |
| `AgentStudioPageTest` :160 đọc `layerTransform.js` để khoá `fabrikai.prompt-cfg` | Thấp | Chuyển `savePromptMemory`/`restorePromptMemory` sang file khác **trước khi** xoá — nhớ cập nhật đường dẫn trong test |
| Người dùng mất bố cục canvas đang dở | Trung bình | GĐ 1–2 **giữ canvas chạy song song**; chỉ xoá ở GĐ 4, sau khi đã thông báo trong Ứng dụng |
| **Mất lòng tin / phẫn nộ vì bỏ canvas không báo trước** — rủi ro ĐÃ XẢY RA ở nơi khác | **Cao** | **Playground AI**: xoá canvas 30/9/2024 rồi Board 6/1/2025, ảnh cũ **không truy cập được**; người dùng viết *"they told no-one. No email or nothing."* (§3.3c). Ba việc bắt buộc: **báo trước · đường xuất bố cục · không mất ảnh** |
| Bản build đang bị khoá bởi test đọc bundle | Thấp | `CanvasControlsTest:199–213` đọc `public_html/build/.../main.js` — phải sửa **sau** khi build lại |

**Đường lùi:** GĐ 1 và GĐ 2 **đảo ngược được** (canvas vẫn nguyên trong mã). Chỉ GĐ 4 mới là một
chiều — vì thế nó đứng **cuối**, và chỉ chạy sau khi GĐ 0 + GĐ 5 đã có số.

---

## 9. VIỆC CẦN CHỦ DỰ ÁN CHỐT

| # | Câu hỏi | Vì sao phải chốt |
|---|---|---|
| **C1** | Có giữ **bảng ghép nhiều ảnh** (`/studio/ban-ghep`) không, hay bỏ hẳn? | Quyết định xoá hay giữ `LayersPanel` + nhóm + căn lề (~1.000 dòng) |
| **C2** | **Path tool / Bezier pen** có người dùng thật không? | 332 dòng chỉ để vẽ vùng chọn kiểu Krita |
| **C3** | Có cho chạy **GĐ 0 (đo 2 tuần)** trước không? | Rẻ nhất và chặn rủi ro lớn nhất |
| **C4** | Giữ **trợ lý trong chat** là lối vào chính, hay **form** là lối vào chính? | Đợt 46 đã đẩy việc tạo ảnh vào chat; OpenArt **đo được** thì để **form** làm chính, còn Chat chỉ là **một panel gắn nhãn `NEW`** trong nav. Hai lựa chọn **loại trừ nhau** ở vị trí "ô nhập đầu tiên" |
| **C5** | Khi bỏ canvas: **báo trước bao lâu**, và có làm **nút xuất bố cục** không? | Playground AI mất người dùng đúng ở chỗ này (§3.3c). Đây là quyết định về **thương hiệu**, không phải về mã |

---

## 10. TÓM TẮT MỘT CÂU

> Canvas trong FabrikAI **không phải một tính năng — nó là cái vỏ của cả sản phẩm**, và gánh nặng
> đó (119 khoá state · 2.649 dòng action · 1.314 dòng component · 85 điểm chạm trong `StudioApp.vue`)
> đang chống lại chính ba persona đã chốt. Bỏ nó **rẻ** (6 điểm rò rỉ, 0 route máy chủ phải sửa,
> 0 dữ liệu phải di trú) **và đúng hướng đã chốt ở đợt 46**. Việc phải làm không phải "xoá canvas"
> mà là **hạ nó xuống đúng vai**: một công cụ mở ra khi cần — đúng như **OpenArt đã bỏ hẳn canvas**,
> **Ideogram neo editor vào một ảnh**, **Leonardo mở thanh mô tả ngay trên ảnh đang xem**.
> Và bài học phải nhớ trước khi xoá bất cứ thứ gì: **Playground AI mất người dùng vì xoá canvas
> mà không báo ai** — xem §3.3(c).
