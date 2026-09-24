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

**Tổng: 20/20 phép kiểm trên Chrome thật** (390×844) cho đợt này; full suite PHP **1400 xanh**.

### 4.2 Việc CÒN LẠI (nói thẳng, không giấu)

| Việc | Vì sao chưa làm | Ghi chú |
|---|---|---|
| **Màn chi tiết BST** (`#/collection/:id` của prototype) | Trang `/bo-suu-tap` đã có đủ công cụ quản trị (bảng thiết kế · tech pack · mẫu · QC · gate · sản xuất · chia sẻ) nhưng **thiếu một màn "một bộ sưu tập"** có lưới shots + chip lọc như prototype | Việc lớn nhất còn lại; cần một surface toàn màn + `pushState` để back hoạt động đúng |
| **Bảng giá theo rail snap** (`#/pricing`) | `/bang-gia` render từ CSDL và đã đúng nội dung; khác prototype ở BỐ CỤC (rail ngang + công tắc Tháng/Năm) | Chỉ canh lại bố cục, không đổi dữ liệu |
| **Lưới bất đối xứng ở Bộ sưu tập** (`#/collections`) | Trang thật có thẻ bộ sưu tập + trạng thái + tiến trình duyệt (nhiều thông tin hơn prototype) | Cân nhắc khi làm màn chi tiết |
| **Agent dạng feed hội thoại** (`#/agent`) | `/agent-studio` là luồng 4–5 bước có chat — KHÁC kiến trúc nhưng nhiều năng lực hơn | Cố ý không hạ cấp về bản mock |
| **Token màu/typography của prototype** (ink-0..4 · signal · magic · Fraunces/Space Grotesk) | Hệ token hiện tại sinh từ theme daisyUI và bị ~1.400 test khoá | Việc riêng, phải làm thành một đợt có kế hoạch — không trộn vào đợt port cấu trúc |

### 4.3 Ba luật của prototype — trạng thái sau đợt này

1. **MỘT thanh lệnh** — ✓ đã theo từ Phase 0 (`CommandBar.vue`).
2. **Một màn một điểm magic** — ✓ (`.btn-magic` cho CTA · `.aurora-ring` khi AI chạy). Màn Studio nay có
   **đúng một** nút magic (CTA biến thể); hàng lối tắt dùng nút thường — cố ý.
3. **Chi phí hiện TRƯỚC khi bấm** — ✓ nay đã có trên điện thoại («Tạo biến thể AI · N credit», lấy từ
   `store.planCostImage`).
