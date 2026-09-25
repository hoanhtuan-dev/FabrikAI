# ĐỐI CHIẾU PROTOTYPE 2026 ⇄ SẢN PHẨM THẬT

> **Prototype được duyệt nằm ở `/prototype`** (mã nguồn: thư mục `prototype/` trong repo — bản trong repo
> và bản trên máy chủ **khớp md5 từng tệp**, đã kiểm 2026-09-26). Tài liệu này là bản đối chiếu **từng
> màn** giữa thiết kế đã duyệt và sản phẩm đang chạy, để không phải mở hai cửa sổ rồi đoán.
>
> **Quyết định nền (đã chốt, không mở lại):** *trên điện thoại KHÔNG có canvas*. Ta **đã loại bỏ canvas**
> khỏi bản điện thoại (quyết định 2026-09-24), nên mọi việc "khớp prototype" ở đây hiểu theo nhánh
> KHÔNG-canvas của chính prototype (`renderPhone()`). Prototype cũng đã có
> sẵn nhánh này — `prototype/js/screens/studio.js` tách hẳn `renderPhone()` (bảng điều khiển) và
> `renderDesktop()` (canvas cử chỉ). Vì vậy "khớp prototype" ở màn Studio nghĩa là **khớp
> `renderPhone()`**, không phải dựng lại canvas.

---

## 1. Bảng đối chiếu 10 màn

| # | Prototype (`#/…`) | Sản phẩm thật | Trạng thái | Việc phải làm |
|---|---|---|---|---|
| 1 | `onboarding` — 3 slide vuốt ngang, hero vải, dots, "Tiếp/Bắt đầu" | **KHÔNG CÓ** (khách vào `/` chỉ thấy một thẻ chào 2 dòng) | **THIẾU MÀN** | Dựng lại 3 slide + vuốt + dots cho khách ở `/` |
| 2 | `auth` — brand mark gradient, 2 ô field, CTA magic, Quên MK/Tạo TK | `/dang-nhap` (blade 2 cột, daisyUI) | **LỆCH (nhẹ)** | Giữ blade (nó đã tốt ở màn rộng); thêm brand mark + nhịp "magic" cho điện thoại |
| 3 | `home` — prompt-first · **4 intent (Concept · Photoshoot · Lookbook · Tech pack)** · rail gần đây · **thẻ Radar xu hướng** · **chuông** | `/` (HomeApp) — prompt-first ✓ · 4 intent **KHÁC NGHĨA** (Concept · Agent · Bộ sưu tập · Thư viện) · rail ✓ · **thiếu Radar** · **thiếu chuông** | **LỆCH + THIẾU** | Đổi 4 intent theo prototype (trỏ đúng công cụ trong Studio) · thêm thẻ Radar (dữ liệu THẬT từ `/api/design-agent/findings`) · thêm chuông |
| 4 | `studio` (phone) — **← về Tạo** · nhãn look · **hoàn tác** · preview có tag tỉ lệ + "Xem lớn" · **CTA "Tạo biến thể AI · 20 credit"** · **2×2 chip (Upscale 4K · Tải · Chia sẻ · Tech pack)** · **lớp: mắt ẩn/hiện + thanh độ mờ** · **sheet "Chỉnh bằng AI" có chip gợi ý** | `/studio` (StudioPhone) — đã có preview · "Tác vụ ảnh" · 4 cổng · dải công cụ · rail · lớp chỉ đọc · thanh lệnh | **LỆCH** | **← về Trang chủ (yêu cầu trực tiếp)** · nhãn look · hoàn tác · tag tỉ lệ + "Xem lớn" · CTA có **giá credit** · 2×2 chip thay vì giấu trong sheet · lớp có mắt + độ mờ · sheet prompt có chip gợi ý |
| 5 | `review` — triage vuốt, stamp GIỮ/BỎ, 3 nút (✕ · 👁 · ✓), **màn kết thúc "N/N phương án vào bộ sưu tập"** | `TriageDeck.vue` (chỉ trên StudioPhone) | **GẦN KHỚP** | Thêm nút 👁 (xem chi tiết) + màn kết thúc có 2 lựa chọn (Làm thêm · Mở BST) |
| 6 | `agent` — feed hội thoại + **thanh 6 bước** + chip trả lời nhanh + "đang online" | `/agent-studio` (AgentStudioApp, 4–5 bước, có chat) | **KHÁC KIẾN TRÚC (tốt hơn)** | Không dựng lại; chỉ đồng bộ nhãn/trạng thái bước nếu lệch |
| 7 | `collections` — **lưới bất đối xứng** (thẻ thứ 3n chiếm 2 cột) · chip lọc · thẻ có mood + mũi tên | `/bo-suu-tap` (CollectionsPage) | **LỆCH (nhẹ)** | Canh lại nhịp lưới + chip lọc theo prototype |
| 8 | `collection/:id` — **MÀN CHI TIẾT BST**: nhãn "N LOOK · ĐANG CHẠY", tên + mood, chip lọc, lưới shots (ô đầu to), sheet Chia sẻ | `/bo-suu-tap` chỉ có bảng thiết kế (modal `ProjectWorkspace`), **không có màn chi tiết riêng trong URL** | **THIẾU MÀN** | Màn chi tiết BST mở được từ thẻ bộ sưu tập (đường dẫn riêng hoặc surface toàn màn trên điện thoại) |
| 9 | `pricing` — **rail snap ngang**, gói "hot" viền magic, công tắc Tháng/Năm | `/bang-gia` (blade) | **LỆCH** | Canh lại: rail snap trên điện thoại + viền "hot" + công tắc chu kỳ (nếu dữ liệu có) |
| 10 | `hub` — thẻ tài khoản · **thanh tiến trình credit** · 3 nhóm (Xưởng · Trải nghiệm · Hệ thống) · **công tắc theme thật** · **Đăng xuất** | `/cai-dat` (SettingsHubApp) — có khu vực + theme ở mục Giao diện; **không có thanh credit** · **không có Đăng xuất** | **LỆCH + THIẾU** | Thêm thẻ tài khoản có thanh credit + nút Đăng xuất |

## 2. Ba luật của prototype (đối chiếu thì phải giữ)

1. **MỘT thanh lệnh** biến hình theo ngữ cảnh; mọi thứ phụ là **sheet trượt từ đáy**. → Sản phẩm đã theo
   (`CommandBar.vue` · `BottomSheet.vue`).
2. **Một màn chỉ có MỘT điểm "magic"** (gradient signal→magic) — dùng cho nút TẠO và trạng thái AI đang
   chạy. → Sản phẩm đã theo (`.btn-magic` · `.aurora-ring`).
3. **Chi phí hiện TRƯỚC khi bấm.** → Prototype ghi thẳng "Tạo biến thể AI · 20 credit"; **sản phẩm chưa
   làm ở màn Studio điện thoại** — đây là việc của đợt này.

## 3. Ranh giới: cái gì KHÔNG port

| Prototype | Vì sao không port |
|---|---|
| Khung điện thoại + thanh trạng thái giả trên desktop (`.stage` · `.device` · `.statusbar`) | Đó là **khung để review** prototype, không phải giao diện sản phẩm |
| Bản đồ màn hình (`.sitemap`) | Công cụ review |
| `renderDesktop()` của studio (canvas cử chỉ: pan/pinch/nhấn-giữ) | Canvas đã bỏ khỏi điện thoại; màn rộng đã có bảng ghép thật |
| Dữ liệu giả (`DB.shots` · `DB.collections` · gradient `.fabric`) | Sản phẩm dùng dữ liệu thật; **không** đưa ảnh giả vào |
| Bảng màu/token của prototype (ink-0..4 · signal · magic · Fraunces/Space Grotesk) | Sản phẩm có hệ token riêng đã sinh từ theme daisyUI (§1 tài liệu thiết kế) và bị ~1.400 test khoá. Đổi token là một dự án riêng, **không** trộn vào đợt này — nhưng **cấu trúc màn hình, nhịp bố cục và hành vi** thì port |

---

## 4. Đã triển khai (đợt 60 · 2026-09-26) — và số đo

### 4.1 Việc đã làm, theo yêu cầu

| # | Yêu cầu | Đã làm | Bằng chứng |
|---|---|---|---|
| 1 | **Thêm nút điều hướng về home — Tạo — cho Studio** | **CẢ HAI nhánh**: điện thoại có nút ← ở hàng đầu (`data-phone-home`, là `<a href="/">`); màn rộng/tablet có nút nhà riêng ở thanh tiêu đề (`data-header-home`), không phải nằm trong menu Không gian nữa | Chrome thật 390×844: `data-phone-home` có mặt, `href="/"`; 1440: `data-header-home` có mặt |
| 2 | **Màn còn thiếu** | **Màn CHÀO MỪNG 3 slide** cho khách chưa đăng nhập (`components/OnboardingSlides.vue`) — trước đây khách gặp một thẻ chào hai dòng rồi bị hỏi mật khẩu | 4/4 phép kiểm: 3 slide · chấm vị trí · slide cuối đổi thành «Bắt đầu» · đi tới `/dang-nhap` |
| 3 | **GUI chưa đúng — Trang chủ** | 4 intent theo prototype (**Concept · Photoshoot · Lookbook · Tech pack**) đi thẳng vào công cụ qua `?panel=` · **chuông** mở sheet Hoạt động (dữ liệu thật, MỌI trạng thái) · **thẻ Radar xu hướng** chỉ hiện khi có dữ liệu thật từ `/api/design-agent/findings` | 6/6 phép kiểm (intent · href · chuông · sheet · radar) |
| 4 | **GUI chưa đúng — Studio điện thoại** | Hàng đầu ← · nhãn ngữ cảnh · credit; **CTA chính có GIÁ credit**; **lối tắt 2×2**; cửa đầy đủ «Tác vụ ảnh — tất cả»; **tag tỉ lệ · kích thước đọc từ chính tấm ảnh**; **danh sách ảnh điều khiển được** (chạm đặt ảnh đang làm việc · mắt ẩn/hiện · thanh độ mờ) | 8/8 phép kiểm, gồm "bấm mắt đổi trạng thái thật" và "CTA mở thẳng cấp Tạo biến thể" |
| 5 | **Deep-link `?panel=`** (để 4 intent ở Trang chủ là thật, không phải trang trí) | StudioApp đẩy yêu cầu sang nhánh đang render; StudioPhone nhận **ngay khi mount** (`immediate`) | `/studio?panel=compose` mở đúng công cụ; công cụ **bị khoá theo gói** đi tới `/bang-gia` (đường nâng cấp thật) |
| 6 | *(phát hiện khi đối chiếu)* **Hub thiếu Đăng xuất + thanh credit** | Thẻ tài khoản ở `/cai-dat`: danh tính · **thanh tiến trình credit** (số dư / hạn mức tháng, đọc từ `/api/plan/status`) · **Đăng xuất** | 1 bài test khoá; trước đây đăng xuất chỉ có trong menu tài khoản ở Studio màn rộng |
| 7 | **Màn chi tiết một bộ sưu tập** (`#/collection/:id`) | Màn CHIẾM TRỌN có **URL riêng** `/bo-suu-tap/{id}`: hàng đầu («N ảnh · trạng thái» · ← · ✕) · tên + brief · **chip lọc theo vòng đời ảnh** · **lưới ảnh (ô đầu to gấp đôi)** · chạm ảnh → **trình xem dùng chung với ngữ cảnh là ảnh của bộ này** · hàng việc «Áp dụng cho phiên này» / «Mở trong Studio» | **13/13 phép kiểm** trên Chrome thật: mở từ thẻ · URL riêng · back của trình duyệt đóng màn · **deep-link `/bo-suu-tap/1` mở thẳng đúng bộ** · chip lọc thu hẹp lưới thật · tầng 95, không tràn ngang |
| 8 | **Lưới bất đối xứng ở Bộ sưu tập** (`#/collections`) | Trên điện thoại: lưới **2 cột**, **mỗi thẻ thứ ba chiếm trọn 2 cột**; từ `sm` trở lên về lưới đều | Nằm trong bộ kiểm 13/13 (không có lỗi tràn ngang ở 390px) |
| 9 | **Gói nổi bật ở bảng giá** (`#/pricing`) | Gói mặc định có **viền nhận diện** (`border-brand-500`) thay cho vòng `ring-brand-500/20` gần như vô hình — đúng từ vựng viền §5, không thêm gradient cho card | Ở lưới một cột trên điện thoại trước đây không thẻ nào nổi lên; nay gói dành cho người mới thấy được ngay |

**Tổng: 20/20 phép kiểm trên Chrome thật** (390×844) cho đợt này; full suite PHP **1400 xanh**.

### 4.2 Việc CÒN LẠI (nói thẳng, không giấu)

| Việc | Vì sao chưa làm | Ghi chú |
|---|---|---|
| **Bảng giá theo rail snap NGANG** (`#/pricing`) | Prototype là bản mock 3 gói; `/bang-gia` đọc từ CSDL và có 4+ gói, bảng so sánh, khối persona, FAQ. Thẻ gói thật CAO (credit · độ phân giải · ghế · giá vốn · CTA), nên một rail ngang trên điện thoại bắt người dùng vuốt qua những thẻ cao hơn màn hình — **tệ hơn** xếp dọc. | **CỐ Ý KHÔNG PORT**; giữ phần tinh thần (gói nổi bật — xem §4.1 dòng 9). Ghi lại đây để lần sau không ai tưởng là bỏ sót. |
| **Công tắc Tháng/Năm ở bảng giá** | Máy chủ bán theo **đơn vị của từng gói** (`unit_label` · gói xưởng bán theo VỤ 3 tháng) — không phải mọi gói đều có giá theo năm | Thêm công tắc sẽ là bịa một lựa chọn không tồn tại |
| **Agent dạng feed hội thoại** (`#/agent`) | `/agent-studio` là luồng 4–5 bước có chat — KHÁC kiến trúc nhưng nhiều năng lực hơn | Cố ý không hạ cấp về bản mock |
| **Token màu/typography của prototype** (ink-0..4 · signal · magic · Fraunces/Space Grotesk) | Hệ token hiện tại sinh từ theme daisyUI và bị ~1.400 test khoá | Việc riêng, phải làm thành một đợt có kế hoạch — không trộn vào đợt port cấu trúc |

### 4.2b Đợt 62 — DỌN TRÙNG LẶP · ĐÚNG URL · ĐÚNG FONT

| Việc | Trước (đo được) | Sau |
|---|---|---|
| **Studio điện thoại — hai lối vào cho một việc** | Nút «Tác vụ ảnh — tất cả» mở danh sách **8 việc**, trong đó **5 việc đã nằm ngay trên màn** (CTA «Tạo biến thể AI» + 4 chip: Nâng cấp 4× · Tải xuống · Chia sẻ · Tech pack) | Mỗi việc **một lối vào**: 5 việc ở trên màn, sheet còn đúng **3 việc không có mặt trên màn** (Sửa ảnh · Đổi khung · Xoá) và đổi tên thành «Việc khác — sửa ảnh · đổi khung · xoá» |
| **Trang chủ — hàng «Lối khác»** | 4 liên kết (Design Agent · Bộ sưu tập · Thư viện · Cài đặt) **trùng** với menu Không gian (3 đích) và với thẻ Radar + «Xem tất cả» (2 đích) | Gỡ hẳn hàng đó — màn Trang chủ đúng nhịp prototype (đầu màn · 4 việc · gần đây · radar); mọi đích vẫn còn lối vào |
| **Hub — credit hai chỗ** | Chip credit trần ở thanh tiêu đề **+** thẻ tài khoản (credit kèm hạn mức tháng và thanh tiến trình) | Chip ở thanh tiêu đề gỡ; credit chỉ còn trong thẻ tài khoản (danh tính vẫn ở thanh tiêu đề) |
| **URL của nút** | Nút trong app còn dùng **đường cũ**: `/presets` (lệnh trong bảng lệnh) · `/model-settings` (2 liên kết trong khu Hệ thống) | Dùng **đường chính thức** `/cai-dat/presets` · `/cai-dat/model`; đường cũ vẫn chạy cho bookmark, nhưng app chỉ dùng **một từ vựng URL** |
| **Font chữ (yêu cầu trực tiếp)** | Ba họ chữ chỉ là *tên trong CSS*: **không trang nào nạp `fonts.css`** mà plugin đã phát ra ⇒ mọi máy rơi về `system-ui`; Fraunces bị tải mà không dùng ở đâu | `partials/fonts.blade.php` nạp `fonts.css` + preload 1 weight/họ. `--font-display` = **Fraunces**, thêm `--font-mono` = **Space Grotesk** cho nhãn nhỏ in hoa qua lớp `.micro-label`. Đo lại trên Chrome: **22 mặt chữ · cả 3 họ `loaded` · woff2 trả 200** |

**Ba lỗi thật bắt được trong đợt này** (đều là loại im lặng):

1. **Font chưa bao giờ được nạp** — nhìn mã thì tưởng đã cấu hình xong (vite có `bunny(...)`, CSS có tên họ chữ); chỉ khi đếm `document.fonts` và soi `<link>` mới thấy không có gì được tải.
2. **Build đỏ mà tưởng xanh** — xem kết quả build qua `| tail -2` nên chỉ thấy dòng cuối; build đã thất bại (SFC lỗi thẻ đóng) mà vẫn tưởng thành công. Từ nay đọc thẳng dấu `✓ built` / `Build failed`.
3. **Blade comment trong tệp Vue** — repo có test cấm điều này; viết `{{-- --}}` trong `SettingsApp.vue` và build đỏ ngay. Đúng lỗi mà luật đã lường trước.

**Khoá bằng test** — `tests/Feature/PrototypeCleanupTest.php` (6 bài): mỗi việc một lối vào ở Studio · Trang chủ không còn hàng lối khác · Hub credit một chỗ · **mọi liên kết nội bộ phải phân giải được thành route GET** (quét MỌI tệp Vue/Blade) · **không dùng đường cũ** của khu Cài đặt · ba họ chữ có token + được khai ở vite + nhãn nhỏ dùng lớp chung.

### 4.2c Đợt 64 — HỆ THỐNG LẠI LUỒNG ĐIỆN THOẠI (một màn sửa ảnh · bỏ lớp/scale · trình xem một ảnh)

| Việc | Trước | Sau |
|---|---|---|
| **Hai màn sửa ảnh** | Công cụ gốc «Sửa ảnh» có prompt/preset/model/giá nhưng chọn vùng phải **vẽ trên canvas** (điện thoại không có) · «Màn Chỉnh ảnh» làm được vùng sửa nhưng thiếu hết tham số | **MỘT màn**: công cụ gốc NHÚNG bề mặt chỉnh ảnh (tả · khoanh · cọ) ⇒ chạy bằng ngón tay ở mọi bề rộng; cờ `editImageOpen` và mount riêng đã gỡ |
| **Action mới trong công cụ** | Sau khi sửa phải rời màn mới tải/chia sẻ/nâng cấp được | Hàng **«Việc tiếp theo»**: Tải xuống · Chia sẻ (tại chỗ, composable dùng chung) · Nâng cấp · Tạo biến thể (qua `requestActivity`) |
| **Xếp lớp + scale trên điện thoại** | Khối «Ảnh trong phiên» có mắt ẩn/hiện + thanh độ mờ từng hàng — mà bảng ghép KHÔNG tồn tại trên điện thoại ⇒ kéo/bấm không đổi gì trên màn hình | Đã gỡ (kèm `pickLayer` và action `setLayerOpacity` không còn nơi gọi). Ảnh đang làm việc đổi từ dải «Kết quả gần đây» |
| **Trình xem** | Dải thumbnail 72px · hai mũi tên ‹ › · bộ đếm "N/M" · tải trước ảnh kề · phím ← → · danh sách ngữ cảnh `viewerList` (mỗi nơi mở truyền một kiểu) | **Chỉ xem MỘT ảnh** — chuyển ảnh là việc của lưới Kết quả |
| **Màn Studio điện thoại** | 4 lối tắt + nút «Việc khác» (3 việc) + sheet «Công cụ» có **lưới 9 công cụ trùng dải công cụ** | **6 lối tắt** (Sửa ảnh · Nâng cấp 4× · Đổi khung · Tải xuống · Chia sẻ · Tech pack) + sheet **«Khác»** chỉ chứa nhóm đổi không gian/nguồn dữ liệu. Nút «Việc khác» gỡ: Sửa ảnh + Đổi khung lên lối tắt, Xoá vào trình xem |

### 4.3 Ba luật của prototype — trạng thái sau đợt này

1. **MỘT thanh lệnh** — ✓ đã theo từ Phase 0 (`CommandBar.vue`).
2. **Một màn một điểm magic** — ✓ (`.btn-magic` cho CTA · `.aurora-ring` khi AI chạy). Màn Studio nay có
   **đúng một** nút magic (CTA biến thể); hàng lối tắt dùng nút thường — cố ý.
3. **Chi phí hiện TRƯỚC khi bấm** — ✓ nay đã có trên điện thoại («Tạo biến thể AI · N credit», lấy từ
   `store.planCostImage`).
