# FabrikAI Studio — HƯỚNG DẪN PHONG CÁCH THIẾT KẾ & UX CHUNG

> **Tài liệu chuẩn DUY NHẤT cho giao diện và trải nghiệm.** Áp dụng cho **mọi card/panel/popup trong
> /studio** (và các trang Cài đặt/Quản trị dùng chung bảng màu). Storefront có bộ class riêng ở nửa dưới
> `app.css` — không trộn hai bộ vào nhau.
>
> Đọc file này TRƯỚC khi thêm một card mới, "làm đẹp" một card cũ, hoặc sửa một luồng có giao diện.
> Mục tiêu không phải là "trông giống nhau" cho vui, mà để: (1) người dùng học MỘT lần rồi dùng được mọi
> card, (2) sửa một chỗ là toàn app đổi theo, (3) không có hai cách làm cho cùng một việc.
>
> **Hợp nhất 2026-09-22.** File này nay gộp trọn `docs/UX_PERSONA_STRATEGY.md` (persona ·
> nguyên tắc UX · gói cước · 21 vòng triển khai kèm số đo) vào một nguồn chân lý. Cách đọc:
>
> | Phần | Nội dung | Tính chất |
> |---|---|---|
> | **§1 – §10** | Luật giao diện: **hệ thống theme Sáng/Tối + token màu** · chuyển động · chữ · class/component dùng chung · trình bày cho người mới · viền · thông báo · bố cục · trợ năng · icon | **LUẬT** — khoá bằng test |
> | **§11 – §13** | Người dùng & việc cần làm · bảy nguyên tắc UX · gói cước & credit trên giao diện | **LUẬT** sản phẩm |
> | **§14 – §15** | Luật rút ra từ thực tế (mỗi luật đã trả giá bằng một lỗi thật) · khung Studio đã chốt | **LUẬT** kinh nghiệm |
> | **§16 – §17** | Lịch sử 21 vòng có số đo · quyết định đã chốt & việc còn nợ | Tham chiếu |

---

## 1. Một nguồn chân lý — không tự nghĩ ra màu/con số mới

### 1.1 Màu — HAI DẢI NGỮ NGHĨA + token cố định (`resources/css/app.css`)

> **[2026-09-23] Đổi gốc: chữ KHÔNG còn dùng ĐỘ MỜ để tạo bậc.** Khiếu nại thật: *"chữ có độ tương
> phản hơi thấp, khó đọc"*. Đo lại thì đúng — toàn bộ bậc chữ phụ được tạo bằng opacity trên một
> sắc duy nhất: **741 chỗ** `text-cream-300/25 … /85`, trong đó `/40` chỉ đạt **2,9:1** (WCAG AA cần
> ≥ 4,5:1 cho chữ thường). Độ mờ trộn với NỀN nên tương phản phụ thuộc chỗ đặt — không kiểm soát
> được. Nay: **4 bậc nội dung ĐẶC**, mỗi bậc đạt AA trên mọi bề mặt của **cả hai** theme.

> **[2026-09-23 · bổ sung] Bảng màu lấy theo THEME daisyUI mà chủ dự án đưa làm tham chiếu.**
> Học ở đây không phải "ý tưởng chung chung" mà là hai thứ ĐO ĐƯỢC: (a) **bộ tên token ngữ nghĩa** của
> daisyUI, (b) **chính các giá trị màu** của theme đó cho nền và màu trạng thái. Màu **thương hiệu xanh
> lá GIỮ NGUYÊN** và đóng vai `primary`. (Bài học của chính đợt này: đổi *cấu trúc* token mà giữ
> *giá trị* cũ thì người dùng KHÔNG THẤY GÌ — xem §14 luật 41.)

| Lớp | Token (dùng được ngay trong class) | Vai trò |
|---|---|---|
| **Bề mặt** (tên daisyUI) | `base-100 · base-200 · base-300` · `base-content` | nền card → panel → nền trang, và chữ trên chúng. Trỏ vào dải `ink-*`/`cream-*` nên tự đúng ở cả hai theme |
| **Bề mặt** (tên cũ của app) | `ink-950 · 900 · 800 · 700 · 600 · 500` | y hệt `base-*`, chỉ khác tên (3.000+ chỗ đang dùng) |
| **Nội dung** | `cream-100 · 200 · 300 · 400` (+ `cream-50`) | 4 bậc chữ/icon: chính → phụ → ghi chú → ghi chú rất phụ |
| **Thương hiệu** | `brand-50 … brand-950` → `primary` (+ `primary-content`) | nút chính · đang chọn · nhấn mạnh |
| **Điểm nhấn phụ** | `clay-500/600` → `secondary` · `gold-400/500` → `accent` · `neutral` | nhãn phụ · cảnh báo mềm (layer khoá) · nền chìm |
| **Trạng thái** | `error · warning · success · info` (+ mỗi cái một `-content`) | chữ/viền/badge; tint bằng alpha (`bg-error/10 · border-warning/40`). Bí danh cũ `danger · warn · ok` trỏ về đây |
| **CỐ ĐỊNH** (không theo theme) | `invert · invert-content · invert-hover · on-accent` · `canvas-*` · `scrim(-content)` | khối đảo màu · chữ trên nền màu · nền canvas · lớp phủ trên ảnh |

**Giá trị nền/màu trạng thái lấy từ theme tham chiếu** (oklch → hex, tính bằng công thức):

| Vai | Theme TỐI (đang chạy) | Theme SÁNG |
|---|---|---|
| Nền trang · panel · card | `#15191e · #191e24 · #1d232a` (xám nguội) | `#eef1f5 · #f7f9fb · #ffffff` |
| Chữ chính | `#ecf9ff` (base-content của theme tham chiếu) | `#121821` |
| Lỗi · cảnh báo · thành công · thông tin | `#ff627d · #fcb700 · #00d390 · #00bafe` | `#b3261e · #7a5000 · #116b4c · #0b5d8f` |
| Thương hiệu (GIỮ NGUYÊN) | `#2d6f4d` (primary) | `#2d6f4d` |

**Bảng tương phản đã đo** (độ chói tương đối theo WCAG 2.1, giữ bằng `tests/Feature/ThemeSystemTest.php`):

| Bậc nội dung | Theme tối · trên card | Theme sáng · trên card | Ngưỡng |
|---|---|---|---|
| `cream-100` (chính) | 14,8 : 1 | 17,8 : 1 | ≥ 4,5 |
| `cream-200` | 11,0 : 1 | 13,0 : 1 | ≥ 4,5 |
| `cream-300` (phụ) | 7,8 : 1 | 8,7 : 1 | ≥ 4,5 |
| `cream-400` (ghi chú) | 6,0 : 1 | 6,0 : 1 | ≥ 4,5 |
| `brand-300` · `error` · `warning` · `success` · `info` | 5,0 – 9,5 : 1 | 5,4 – 6,2 : 1 | ≥ 4,5 |
| chữ trắng trên nút `primary` | 6,0 : 1 | 6,0 : 1 | ≥ 4,5 |

**Bảy quy tắc:**

1. **`ink-*` = BỀ MẶT, `cream-*` = NỘI DUNG.** Không dùng lẫn vai (`text-ink-700` để viết chữ
   trên nền tối là sai — xem quy tắc 4). Hai dải này **đảo vai theo theme** nên cùng một class
   đúng ở cả Sáng lẫn Tối.
2. **Không dùng opacity cho chữ.** Chọn bậc: ghi chú phụ = `text-cream-400`, chữ phụ = `text-cream-300`,
   chữ thường = `text-cream-200`, chữ chính/tiêu đề = `text-cream-100`/`cream-50`. Ngoại lệ duy nhất là
   placeholder trong ô nhập — và nó cũng phải dùng bậc đặc (`placeholder:text-cream-400`).
   Test **ĐỎ** nếu thấy `text-cream-*/NN` trong mã.
3. **Màu trạng thái dùng token ngữ nghĩa**, không viết sắc độ thô: `text-danger` (không `text-red-300`),
   `text-warn`, `text-ok`, `text-info`. Sắc độ 100–400 chỉ đủ tương phản trên nền TỐI; ở
   theme sáng chúng thành chữ vàng nhạt trên nền trắng. Test cũng **ĐỎ** nếu thấy `text-red-*/amber/emerald/sky-NN`.
4. **Chữ/icon đặt TRÊN nền màu thì dùng token CỐ ĐỊNH**: `text-on-accent` (trên `bg-amber-500` ·
   `bg-emerald-500/80` · `bg-brand-500` · `bg-white`), và `bg-invert text-invert-content` cho khối
   đảo màu. Nền đó không theo theme nên chữ cũng không được theo — nếu dùng `text-ink-900` thì theme
   sáng sẽ ra chữ sáng trên nền sáng.
5. **Môi trường ẢNH là cố định, không theo theme.** Ba nhóm, cả ba đều dùng token CỐ ĐỊNH (khai
   MỘT lần trong `@theme`, **không** định nghĩa lại ở theme sáng):
   · nền canvas — `.canvas-bg-dark/white/cream` đọc `--color-canvas-*` (người dùng chọn "nền Kem"
     để nhìn ảnh; nếu theo theme thì ở theme sáng nó thành nền ĐEN, mất đúng thứ họ vừa chọn);
   · lớp phủ TRÊN ảnh (chip "Trước/Sau", nhãn kéo-thả, thanh hành động) — `bg-scrim/NN` +
     `text-scrim-content`; nếu chữ theo theme thì ở theme sáng nó thành chữ đen trên scrim tối
     (đo được **2,9:1** trước khi sửa);
   · viền trắng vẽ trên ảnh — `border-white/*` ở tay cầm crop, con trỏ cọ, ô màu trong suốt.
6. **Không viết mã màu mới trong `<style scoped>` — và KHÔNG card nào có màu/bề mặt riêng.**
   [2026-09-23] Ngoại lệ "lớp nền gradient nhận diện của MỘT card" đã **GỠ HẲN**: 9 card từng có 9 gradient
   khác nhau (xanh · tím · cam · xanh dương…) bằng style inline ⇒ người dùng phải "học" lại từng card và
   bảng màu có thêm những sắc thái không thuộc hệ. Nay **mọi card dùng chung lớp `.card`** (bề mặt
   `base-100` của theme) — khoá bằng `DesignSystemTest::test_every_card_uses_the_one_shared_surface`.
7. **Sao chép màu là tạo nguồn lệch thứ hai.** Ô swatch tự vẽ màu bằng inline style chỉ cần một lần đổi
   token là lệch ngay (đã xảy ra với 4 ô nền canvas). Cách sửa rẻ và bền: cho cả hai chỗ dùng **cùng một
   class** (`.canvas-bg-grid/dark/white/cream`).

### 1.4 Theme Sáng/Tối — cách hoạt động (2026-09-23)

| Việc | Ở đâu |
|---|---|
| Khai token của hai theme | `resources/css/app.css`: khối `@theme` (giá trị của theme **TỐI**) + khối **ngoài layer** `[data-theme='light']` (đảo vai hai dải) |
| Quyết định theme trước lần vẽ đầu | `resources/views/partials/theme.blade.php` (script inline trong `<head>`, `@include` ở **mọi** blade) + `data-theme="{{ theme_resolved() }}"` render sẵn trên thẻ `<html>` |
| Người dùng chọn | **Cài đặt của tôi → Giao diện** (`AppearanceSection.vue`: Sáng · Tối · Theo hệ điều hành) + nút đổi nhanh ở **thanh trạng thái Studio** |
| Lưu | `users.theme` (migration `2026_09_23_000001`) qua `PUT /api/theme` — whitelist cứng `light|dark|system`; localStorage `fabrikai.theme` là **cache** để vào trang là đúng ngay |
| Hàm PHP | `theme_pref()` (mặc định `dark` khi chưa chọn) · `theme_resolved()` (`system` ⇒ trả `dark`, script sửa lại trước khi vẽ) |
| Hàm JS | `window.FabrikAITheme` · bọc Vue: `composables/useTheme.js` |
| **Xem bảng token + tỉ lệ tương phản** | **`/he-thong-thiet-ke`** (cấp OWNER, server-render) — đọc thẳng `resources/css/app.css` qua `App\Support\ThemePalette`, CÙNG lớp mà `ThemeSystemTest` dùng nên trang và test không thể lệch số |

**Đo lại bằng Chrome thật (2026-09-23)** — bốn màn hình (bảng giá · Studio · Cài đặt của tôi ·
Bộ sưu tập) × hai theme, **2.578 phần tử chữ mỗi theme**: **0 chỗ** dưới ngưỡng WCAG AA. Phép đo
tính cả alpha của nền (nền trong suốt được trộn lên), nên nó bắt được cả những chỗ mà mắt thường
bỏ qua — và chính nó tìm ra hai lỗi còn sót sau khi đã đổi token: chip trạng thái (dùng mã hex của
server cho phần chữ ⇒ 2,7–3,0:1) và chip đặt trên ảnh (chữ theo theme ⇒ 2,9:1 trên scrim tối).

Ba quyết định đáng nhớ:

- **Mặc định là TỐI**, không phải "theo hệ điều hành": người dùng hiện hữu không bị đổi giao diện
  sau khi tính năng lên. `users.theme = NULL` = "chưa từng chọn" (khác hẳn `'dark'` = đã chọn Tối), nhờ
  vậy sau này đổi mặc định sản phẩm vẫn áp được cho người chưa chọn mà không ghi đè lựa chọn của ai.
- **Script theme KHÔNG nằm trong bundle**: bundle tải bất đồng bộ nên sẽ có một nháy sai màu; và các
  trang server-render (bảng giá · chia sẻ · đăng nhập · trang lỗi) không chạy app JS nào nhưng vẫn
  phải đổi được theme.
- **Không bị công tắc gói chặn**: `/api/theme` nằm ngoài nhóm `can-studio` và không thuộc module nào
  (đã khai `'theme'` vào `INFRA_PREFIXES` của `ModuleRegistryTest`) — giao diện là quyền của mọi tài khoản.

### 1.2 Chuyển động — token, không viết số

| Token | Nhịp | Dùng cho |
|---|---|---|
| `--motion-dur-instant` | 90ms | phản hồi chạm (hover nút nhỏ) |
| `--motion-dur-fast` | 150ms | đổi màu · viền · opacity |
| `--motion-dur-base` | 220ms | mặt UI chung: card · popup · tab |
| `--motion-dur-slow` | 320ms | mặt lớn: ngăn kéo · modal |
| `--motion-dur-dock` | 260ms | co/giãn dock (JS đọc lại chính biến này) |
| `--motion-dur-reveal` | 600ms | hiệu ứng cuộn/vòng xoay có chủ đích |

- Viết trong markup thì dùng tiện ích theo TÊN NGHĨA: `duration-fast`, `duration-base`,
  `ease-standard`, `ease-emphasized` — chúng trỏ về đúng token trên.
- **Không dùng `transition-all`** (kéo theo cả `width/height` ⇒ giật bố cục). Dùng `.motion-ui`
  (đã liệt kê đúng bộ thuộc tính hay đổi). Cần chuyển động kích thước thì thêm `.motion-ui--size`.
- **Bật "giảm chuyển động" của hệ điều hành là TOÀN APP tắt** (`prefers-reduced-motion` đưa mọi
  token về 0ms). Hiệu ứng CHẠY VÒNG LẶP (pulse · spin · shimmer) **không** tự tắt theo token — phải
  dùng token hoặc chấp nhận bị luật chung tắt; đừng viết `animation: x 1s infinite` với số cứng.
- **Một nguồn số, kể cả khi JS cần biết thời lượng:** `motion.js` đọc lại đúng biến CSS
  `--motion-dur-dock` thay vì chép `260` vào JS; test đối chiếu bảng dự phòng trong JS với token
  trong CSS để hai bên không trôi khỏi nhau.

### 1.3 Chữ

- Tiêu đề card: `font-display` (Fraunces) + `text-base font-semibold text-brand-300` + icon 16px.

**[2026-09-23] Cỡ chữ là TOKEN THEO VAI, nhân với một công tắc toàn cục.** Trước đây chrome studio dùng
**941 chỗ** cỡ chữ viết thẳng bằng px (`text-[10px]` · `text-[11px]` · `text-[9px]`…) — px là số cứng nên
**không thể** có cài đặt cỡ chữ cho người dùng. Nay mỗi bậc là một token, và mọi token nhân với
`--font-scale` (người dùng chỉnh ở **Cài đặt của tôi → Giao diện → Cỡ chữ**: 90% · 100% · 115% · 130%):

| Vai | Token | Cỡ (ở 100%) | Thay cho |
|---|---|---|---|
| Nhãn rất phụ | `text-micro` | 9px | `text-[8px]` |
| Nhãn / ghi chú | `text-tiny` | 10px | `text-[9px]` |
| Nhãn trong card | `text-label` | 11px | `text-[10px]` |
| Chữ thường trong card | `text-body` | 12,5px | `text-[11px]` |
| Chữ đọc kỹ | `text-body-lg` | 13px | `text-[12px]` |
| Tiêu đề nhỏ | `text-title` | 14px | `text-[13px]` |

- **Đã nhích +1px mọi bậc** so với trước (phản hồi thật: "tỷ lệ chữ vẫn hơi nhỏ"), và **mọi bậc rem
  mặc định của Tailwind** (`text-xs/sm/base/lg/xl/2xl/3xl`) cũng nhân theo cùng công tắc — nếu không thì
  nửa app to lên còn nửa kia đứng yên.
- **Không viết `text-[Npx]` trong mã** (`DesignSystemTest` khoá luật này). **Không nhỏ hơn 9px.**
- Cỡ chữ áp NGAY khi bấm (không có nút Lưu), **lưu theo tài khoản** (`users.font_scale`) + cache ở máy, và
  **server render sẵn** `style="--font-scale: …"` trên thẻ `<html>` nên không nháy cỡ chữ khi tải trang.
- Viết hoa nhỏ (`uppercase tracking-wide`) chỉ cho **tên nhóm**, không cho câu.

---

## 2. Class dùng chung — bảng "cần gì thì dùng gì"

| Cần | Dùng | KHÔNG tự làm |
|---|---|---|
| Khung card | `.card` (+ `p-4`) | tự vẽ `rounded/border/bg` |
| Nút chính | `.btn-brand` | nút gradient tự chế |
| Nút phụ | `.btn-ghost` (+ `.btn-sm`) · `.btn-outline` | |
| Nút nhỏ trong card | `.tool-btn` · `.icon-btn` | |
| Tab / chế độ / EN-VI | `.seg` + `.seg-btn` (+ `.is-active`) | tự vẽ tab |
| Ô nhập · nhãn | `.input` · `.label` | |
| Đầu panel/card | `.panel-head` + `.panel-title` | |
| Hàng bảng | `.motion-row` | |
| Chuyển động | `.motion-ui` (+ `--instant/--base/--slow`) | `transition-all` |
| Xuất hiện | `.motion-fade-in` · `.motion-pop-in` · `.motion-rise-in` · `.motion-slide-in-*` | animation chép tay |
| Dock co/giãn | `.dock-panel` (+ `data-collapsed`) + `DockResizer` | |
| Nền canvas | `.canvas-bg-grid/dark/white/cream` | tự khai `rgba()` cho ô màu |
| Thanh cuộn ẩn | `.scrollbar-hide` | |

---

## 3. Component dùng chung — không vẽ lại thứ đã có

| Component | Thay cho |
|---|---|
| `StudioIcon.vue` (`icons.json` — **132 icon**, cũng là nguồn cho PHP `App\Support\IconRegistry`) | mọi `<svg>` chép tay, mọi emoji |
| `LoadingSpinner.vue` (`text · subtext · progress`) | **mọi bộ tiến trình tự chế** (chấm · thanh · phần trăm) |
| `ConfirmDialog.vue` | popup xác nhận tự viết (đã từng có 3 bản khác nhau) |
| `BaseModal.vue` | khung modal tự viết |
| `SourceLibraryPicker.vue` | tự làm danh sách chọn ảnh |
| `CompareSlider.vue` | tự làm trượt so sánh trước/sau |
| `DockResizer.vue` (+ `useDockResize.js`) | tự làm vách ngăn kéo — dùng chung cho **cả ba** dock |
| `CanvasEmptyState.vue` | màn hình canvas trống kiểu "một dòng chữ" |
| `NotificationCenter.vue` | khay thông báo tự chế (từng có 3 kiểu, 3 vị trí, 3 thời lượng) |
| `SettingsSkeleton.vue` · `SettingsToasts.vue` | khung xương + khay thông báo tự chế ở khu Cài đặt |
| `StylistSection.vue` | bản sao trình cài đặt Trợ lý thiết kế trong từng app |
| `AppearanceSection.vue` | mục Giao diện (Sáng/Tối/Theo hệ điều hành) tự viết lại ở từng app |
| `MultiSelectBar.vue` · `GalleryModal.vue` | thanh chọn nhiều · trình xem thư viện tự viết |

> **Luật:** nếu hành vi đã có người làm rồi thì **dùng lại**; chỉ tạo mới khi bài toán KHÁC về bản
> chất, và khi đó viết vào file này một dòng để người sau biết nó tồn tại.

---

## 4. Trình bày cho NGƯỜI MỚI — 6 quy tắc bắt buộc

> Sáu quy tắc này được chốt ở đợt thiết kế lại card Studio (2026-09-22) và nay áp cho mọi card.

1. **Đánh số bước ①②③④, mỗi bước MỘT câu giải thích.**
   Người mới cần biết "làm gì trước", không cần biết kiến trúc.
2. **Việc bắt buộc phải TO NHẤT và ghi rõ chữ `bắt buộc` / `tùy chọn`** ngay cạnh tiêu đề bước.
3. **Mỗi card chỉ có MỘT hành động chính tại một thời điểm.** Hai nút to ngang nhau = người dùng
   đứng hình. Khi trạng thái đổi (đã có kết quả) thì nút cũ phải **lùi về thứ yếu**, không phải
   thêm một nút chính thứ hai.
4. **Nút bị khoá phải NÓI RÕ LÝ DO ngay dưới nó**: `↳ Chưa có ảnh nguồn — chọn một ảnh trên canvas…`.
   Không bao giờ để người dùng đoán vì sao nút mờ. **Một nguồn**: câu lý do và điều kiện khoá cùng
   suy ra từ MỘT computed (mẫu: `StudioCard.vue` — `blockReason` rồi `canRun = !blockReason && !busy`), nên
   chúng không thể lệch nhau. **Miễn trừ duy nhất:** khoá vì ĐANG CHẠY (`busy/saving/loading`…) —
   lúc đó nhãn nút đã đổi thành "Đang gửi…" nên không cần thêm dòng lý do.
   Khoá bằng `DesignSystemTest::test_blocked_primary_buttons_explain_the_reason` (12 nút đã được bổ sung lý do
   trong đợt 2026-09-23).
5. **Thứ ít dùng / nâng cao thu vào `<details>`, mặc định ĐÓNG** (nhãn ghi rõ có gì bên trong:
   "Nâng cao: biến thể · tỉ lệ · prompt gửi AI"). Trạng thái mở không cần nhớ giữa các lần.
6. **Chỉ lỗi/cảnh báo nằm gần nút chạy; ghi chú, giải thích dài, thông số kỹ thuật đưa vào khối Nâng cao.**
   Trộn cả hai làm người mới tưởng cái gì cũng là lỗi.

**Ví dụ đo được — card "Gợi ý từ ảnh" (2026-09-22):**

| Trước | Sau |
|---|---|
| 2 nút chính to ngang nhau ("Gợi ý phong cách & prompt" + "Tạo ảnh ngay") | **1** nút chính; nút "Phân tích lại" tự lùi về thứ yếu khi đã có kết quả |
| Không rõ vì sao nút mờ | `↳` lý do cụ thể ngay dưới nút |
| 2 hệ tiến trình tự chế (chấm + thanh, 2 bộ CSS trùng nhau ~90 dòng) | **1** `LoadingSpinner` dùng chung cho cả phân tích và tạo ảnh |
| Emoji rải khắp chip/nhãn (🎯⚖️✨🛍️📸🚀💾⏱ + 8 emoji đặc điểm) | **0 emoji** — toàn bộ bằng `StudioIcon` |
| 40 mã `rgba()` tự khai trong `<style scoped>` | token + tiện ích dùng chung; còn **1** dòng gradient nhận diện |
| Danh sách "Gợi ý gần đây" luôn chiếm chỗ | gấp trong `<details>` |
| 586 dòng | 455 dòng |

---

## 5. Viền — một nghĩa, MỘT token

> Đo trước khi đồng bộ (2026-09-22): **30 biến thể viền** trên các phần tử bấm được. Cùng một nghĩa
> bị viết bằng nhiều token — "nút nghỉ" có **hai** token (`border-ink-700` 58 chỗ và `border-ink-600`
> 54 chỗ), "đang chọn" có **hai** (`border-brand-400` 21 chỗ và `border-brand-500` 18 chỗ), mỗi màu
> ngữ nghĩa bị rải ra 3–4 mức alpha (`red-500/30 · /40 · /60`, `amber-500/40 · /50`,
> `emerald-400 · /40 · /50 · /80`), và 10 nút dùng ngôn ngữ "kính trắng" `border-white/5–/20`.
> Sau khi đồng bộ: **16 token**, mỗi nghĩa đúng một token (bớt luôn ~1,6 kB CSS phải gửi đi).

### 5.1 Bảng từ vựng (ĐÓNG — thêm token mới là test ĐỎ)

| Nghĩa | Token | Ghi chú |
|---|---|---|
| Nút nghỉ (mặc định) | `border-ink-600` | **mọi** nút/chip/ô bấm được |
| Hover nút thường | `hover:border-brand-400` | |
| Hover nút rất phụ | `hover:border-ink-500` | nút "êm" trong hàng dày |
| **Đang chọn / đang bật** | `border-brand-500` | thường đi kèm `bg-brand-600 text-white` |
| Nguy hiểm (viền nghỉ) | `border-red-500/40` | nút xoá |
| Nguy hiểm (hover · xác nhận) | `hover:border-red-500` · `border-red-500` | |
| Cảnh báo | `border-amber-500/40` | |
| Thành công | `border-emerald-500/40` | |
| Thông tin | `border-sky-500/40` | |
| Giữ chỗ cho hover | `border-transparent` | hàng bảng đổi viền khi chọn |
| **Khối CHỨA (không bấm được)** | `border-ink-700` | card con · `<details>` · hàng danh sách · biểu mẫu |
| Ô nhập | `border-ink-700` → `focus:border-brand-400` | `.studio-shell .input` |
| Bảng "tông chú ý" (badge) | `...-500/40` cho cả 4 tông | danger · warn · info · ok |

**HAI ngoại lệ DUY NHẤT, phải kèm lý do trong mã:**

1. **Checkbox chọn ảnh** đặt TRÊN ảnh: `border-cream-300/50` → `hover:border-cream-200`
   (viền xám tan biến trên nền ảnh bất kỳ).
2. **Nút kiểu `.btn-outline`** (nút phụ toàn app): hover ĐẢO màu bằng token cố định
   `hover:bg-invert hover:text-invert-content` + `hover:border-brand-400` — đúng ở cả hai theme nên
   không cần nhánh riêng cho studio như trước.

> **[2026-09-23] Ngoại lệ "MÀU NHẤN RIÊNG CỦA CARD" đã bị GỠ HẲN.** Ba card từng được phép dùng emerald
> (`RefImageCard.vue` · `ConceptCard.vue` · `InpaintCard.vue`) đã được thiết kế lại:
> trạng thái "đang chọn" về `border-brand-500 + bg-brand-600/20 + ring-brand-500/40`, "khối chứa" về
> `border-ink-700 + bg-ink-900`, còn thứ đúng nghĩa "thành công" thì dùng token ngữ nghĩa `ok`
> (`border-ok/40 · bg-ok/10 · text-ok`). Lý do gỡ: cùng một trạng thái "đang chọn" mà chỗ thì xanh lá
> thương hiệu, chỗ thì emerald ⇒ người dùng phải học hai lần, và bảng màu có thêm một họ màu không thuộc hệ.
> Danh sách miễn trừ trong `DesignSystemTest` cũng đã xoá: nay **bất kỳ token emerald nào làm viền nút là ĐỎ**.

### 5.2 Vì sao phải là từ vựng ĐÓNG

`tests/Feature/DesignSystemTest.php` quét **mọi** `<button|a|label>` trong `resources/js/studio` và
ĐỎ nếu gặp token ngoài bảng trên. Nhờ vậy "hai nút cạnh nhau lệch màu viền" trở thành lỗi bắt được
bằng máy, không phải thứ chỉ lộ ra khi có người ngồi nhìn. Muốn thêm token: sửa bảng này + danh sách
trong test **trong cùng một commit** — đó là chủ ý, không phải tai nạn.

### 5.3 Nền của nút — một trạng thái, MỘT token (đồng bộ 2026-09-23)

> Đo trước khi đồng bộ: nút nghỉ có **ba** kiểu nền — `bg-ink-800` (đa số), "kính mờ" `bg-white/5`
> và `bg-ink-900/90`. Hai nút cạnh nhau lệch nền mà không ai cố ý; đây đúng vết lặp của lỗi **viền**
> đã sửa ở §5.2.

| Trạng thái | Token | Ghi chú |
|---|---|---|
| Nút nghỉ | `bg-ink-800` | **mọi** nút/chip/ô bấm được |
| Hover | `hover:bg-ink-700` | |
| Đang chọn / nhấn mạnh | `bg-brand-600` (+ `text-white`) · tint `bg-brand-600/20` | |
| Ngữ nghĩa | `bg-danger/10` · `bg-warn/15` · `bg-ok/15` · `bg-info/15` | tint theo màu trạng thái |
| **Nút đặt TRÊN ẢNH** | `bg-scrim/85` + `text-scrim-content` | môi trường ảnh ⇒ CỐ ĐỊNH (§1.1 quy tắc 5) |
| Khối CHỨA (không bấm được) | `bg-ink-900` · `bg-ink-900/95` | panel/thanh dính — KHÔNG dùng cho nút |

Khoá bằng `DesignSystemTest::test_button_backgrounds_use_one_token_per_state`: nút dùng lại nền
"kính mờ" (`bg-cream-50/5`, `bg-ink-900/90`, `bg-white/5`…) là **test ĐỎ** — muốn thêm ngoại lệ thì
sửa bảng này **và** danh sách trong test trong cùng một commit.

---

## 6. Thông báo · chỉ báo · tiến trình — nói với NGƯỜI DÙNG, không nói với lập trình viên

> **[Yêu cầu 2026-09-22]** Giao diện **KHÔNG rò rỉ chi tiết kỹ thuật phía backend**: tên **model AI**,
> tên **nhà cung cấp (provider)**, mã HTTP, JSON, lệnh CLI, đường dẫn file, tên bảng/cột, tên lớp
> ngoại lệ. Những thứ đó là việc của lập trình viên — chúng thuộc về log, không thuộc về màn hình
> của khách hàng.

### 6.1 Sáu luật

1. **Câu hiển thị phải trả lời đúng hai câu hỏi**: *chuyện gì đã xảy ra* và *giờ tôi làm gì*.
   Không mô tả cơ chế bên trong. ("Không phân tích được ảnh này. Bạn thử lại sau ít phút, hoặc đổi
   sang ảnh rõ hơn." — đạt. "Model trả về JSON không đọc được." — không đạt.)
2. **Không nêu tên model AI hay nhà cung cấp** trong thông báo · chỉ báo · tiến trình. Ở luồng công
   việc người dùng **không chọn được** model, nên biết tên không giúp gì — chỉ để lộ hạ tầng phía sau.
3. **Tiến trình nói ĐANG LÀM GÌ, không nói AI NÀO**: "AI đang đọc ảnh và suy luận…", "Đang thử cách
   phân tích khác…" — không kèm "(deepseek · deepseek-flash)".
4. **Lỗi không bao giờ là `$e->getMessage()` hay `e.message` thô.** Dùng đúng hàm có sẵn:
   · PHP: `studio_fail()` · `studio_generation_error()` (đã log chi tiết, trả câu an toàn);
   · JS: `userFacingError(e, fallback)` cho state lỗi, `safeMessage(text, fallback)` cho chuỗi từ server.
5. **Chi tiết kỹ thuật KHÔNG bị mất** — nó đi đúng chỗ của nó: PHP → **`storage/logs/laravel.log`**;
   JS → **`console`** với tiền tố `[studio:…]`. Nhờ vậy vẫn chẩn đoán được mà khách không phải đọc.
6. **Ngoại lệ (có lý do, phải giữ đúng):**
   · **Cài đặt / Quản trị** — ở đó việc khai báo model + nhà cung cấp CHÍNH LÀ tính năng;
   · **ô CHỌN model** trong card (người dùng đang tự chọn thì phải biết mình đang chọn gì);
   · **câu kiểm tra dữ liệu của Laravel** ("Chưa chọn ảnh ①…") — do người viết sản phẩm đặt, hướng người dùng.

### 6.2 Bảng dịch — những câu ĐÃ GỠ và câu thay thế thật

| Đã gỡ khỏi giao diện | Câu nói với người dùng |
|---|---|
| `Qwen vision: HTTP 429: {"error":…quota has been exhausted…}` (nguyên văn lỗi nhà cung cấp) | "Không phân tích được ảnh này. Bạn thử lại sau ít phút, hoặc đổi sang ảnh rõ hơn." |
| "AI đang đọc ảnh và suy luận… (deepseek · deepseek-flash)" | "AI đang đọc ảnh và suy luận…" |
| "Provider này lỗi — đang thử provider kế tiếp…" | "Đang thử cách phân tích khác…" |
| "Chưa có provider AI khả dụng — phân tích màu ngoại tuyến…" | "Đang phân tích màu trực tiếp trên ảnh…" |
| "Không đọc được cài đặt… **Chạy lệnh: php artisan migrate --force**" | "Không mở được cài đặt… Vui lòng thử lại, nếu vẫn lỗi hãy báo cho quản trị viên." |
| "Tính năng Thay vùng cần **key AI (Qwen Edit / DashScope)**." | "Tính năng Thay vùng chưa được bật. Vui lòng báo cho quản trị viên…" |
| "Chưa cấu hình **model** tạo/sửa ảnh (**nhóm “edit”**)…" | "Tính năng tạo ảnh chưa được bật — kết quả sẽ là ẢNH MẪU (chế độ demo)…" |
| "Chưa cấu hình **API key** — kết quả là ẢNH GỐC…" | "Tính năng sửa ảnh chưa được bật — kết quả là ẢNH GỐC…" |
| Chip "DeepSeek · deepseek-chat" trên card | "Đã phân tích xong · Xong trong 8,8s · mức bám: Cao" |
| "Agent Studio sẽ dùng **model AI của nhóm “prompt”**." | "Đã bật AI — phần phân tích sẽ do AI thực hiện." |
| "**Model** không phản hồi — đã tự quay về **engine tất định**." | "AI không phản hồi — đã tự chuyển sang bộ quy tắc có sẵn (kết quả vẫn đầy đủ)." |

### 6.3 Ba tầng chặn (để không phải sửa lại từ đầu)

1. **Tầng PHP** — nhãn tiến trình và thông báo lỗi viết sẵn theo câu hướng người dùng; lỗi hệ thống
   đi qua `studio_fail()`/`studio_generation_error()`; luồng NDJSON ghi log rồi mới gửi câu an toàn.
2. **Tầng BIÊN ở JS** — `store.toast()` và `store.notify()` là **cửa chặn cuối**: mọi thông báo đều
   đi qua đó, nên chỉ cần một chỗ kiểm tra là không câu nào lọt ra kèm chi tiết kỹ thuật (kể cả câu
   từ nơi khác chưa kịp sửa). Nhãn tiến trình do server gửi cũng lọc ở biên khi nhận.
3. **Tầng STATE ở JS** — mọi state lỗi hiển thị trong template (`*Error`) gán bằng
   `userFacingError()`, không bao giờ bằng `e.message`.

### 6.4 Khoá bằng test

`tests/Feature/UserFacingMessagesTest.php` giữ bốn tầng trên: nhãn tiến trình phía PHP sạch · lỗi
luồng stream đi đường an toàn · **những câu đã gỡ không quay lại** · JS còn đủ cửa chặn ở biên và
không còn chỗ nào nhét lỗi thô vào state. `SuggestStreamTest` khẳng định thẳng: nhãn tiến trình và
thông báo lỗi **không được** chứa tên model/provider.

> **Quy tắc rút ra:** người dùng chỉ cần biết *chuyện gì* và *làm gì tiếp*. Mọi thứ giải thích
> *tại sao hỏng ở tầng nào* là để trong log — nơi lập trình viên đọc, không phải nơi khách hàng đọc.

### 6.5 MÃ TRA CỨU LỖI — khách đọc 6 ký tự, hỗ trợ tra ra đúng dòng log

**Đã làm xong 2026-09-23** (trước đó là món nợ cuối của mục này). Câu hỏi gốc: *"lỗi lúc mấy giờ, tài khoản
nào?"* — hỗ trợ phải hỏi khách rồi tự dò log. Nay mọi câu lỗi người dùng nhìn thấy đều kết thúc bằng
`(mã tra cứu: L-XXXX)`, và **cùng mã đó có trong `storage/logs/laravel.log`**.

Có **HAI nguồn sinh lỗi**, nên phải có **hai đường gắn mã** — thiếu một đường là mất một nửa dấu vết:

| Nguồn lỗi | Ai sinh mã | Ghi ở đâu | Tra bằng gì |
|---|---|---|---|
| **Máy chủ** (exception trong controller/service) | `studio_error_code()` — `app/Support/helpers.php` | `studio_fail[L-XXXX]: <ngữ cảnh>` (Log::warning) | `grep 'studio_fail\[L-' storage/logs/laravel.log` |
| **Trình duyệt** (mất mạng · fetch hỏng · canvas/Blob · exception không ai bắt) | `newClientCode()` — `resources/js/studio/clientErrors.js` | `client_error[L-XXXX]: <ngữ cảnh>` qua `POST /api/client-errors` | `grep 'client_error\[L-' storage/logs/laravel.log` |

**Bảng chữ dùng chung** (bỏ `0 O 1 I` vì khách đọc qua điện thoại): `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` —
khai ở cả hai phía và `ClientErrorReportTest` khoá bất biến *hai bảng chữ phải giống nhau*.

Bốn quy tắc của đường thứ hai (lỗi trình duyệt):

1. **Chỉ sinh mã khi máy chủ không có mã.** `userFacingError(e, fallback)` ưu tiên `error_code` của máy chủ;
   chỉ khi lỗi phát sinh thuần trong trình duyệt mới tự sinh mã + gửi chi tiết về `/api/client-errors`.
2. **Mã tra cứu của máy chủ không được bị ném bỏ trên đường về.** Mọi chỗ dựng `Error` từ phản hồi dùng
   `apiError(payload, fallback, res)` (export từ `store.js`) để **giữ `error_code`** — trước đây
   `throw new Error(d.message)` làm rơi mất mã mà máy chủ vừa gửi.
3. **Một lỗi = một mã = một dòng log.** Lỗi lặp lại trong cùng lần tải trang chỉ hiện **một lần** và gửi
   **một lần** (trần 12 lần gửi/trang). Mất mạng thì bản ghi vào hàng đợi `localStorage` và gửi bù sau.
4. **Endpoint mở cho cả khách chưa đăng nhập** (lỗi có thể nổ ngay ở trang đăng nhập) nhưng bị chặn theo
   IP (30/phút) và gộp trùng theo mã (10 phút) ⇒ không thể dùng để bơm log.

**Lỗi vẫn KHÔNG lộ chi tiết kỹ thuật**: mã là 6 ký tự vô nghĩa với người dùng; câu hiển thị vẫn qua cửa
chặn §6.3, còn `message` kỹ thuật chỉ đi vào log.

### 6.6 Còn nợ (đề xuất)

- Vài câu lỗi còn dài dòng kiểu hệ thống ("Lỗi hệ thống, vui lòng thử lại.") — nên nói rõ người dùng
  làm gì tiếp.

---

## 7. Bố cục & cuộn

- **Vùng dùng chung mà cao cố định ⇒ MỌI biến thể phải theo CÙNG một khuôn.** Ví dụ vùng toolbar
  phía trên canvas: rail cao cố định `h-12`, mỗi thanh ngữ cảnh dùng chung hằng số `bar`
  (`h-9 w-max shrink-0 flex-nowrap`) — thêm một biến thể mà quên khuôn là **test ĐỎ**
  (`tests/Feature/ToolbarAreaTest.php`).
- **Khung cuộn NGANG: lớp trong `w-max` + `mx-auto`.**
  **Không** dùng `justify-center` trên chính khung cuộn — nó đẩy mép TRÁI ra ngoài vùng cuộn và
  người dùng không bao giờ kéo tới được phần đầu.
- Dock: bề rộng do JS đặt qua inline style, CSS lo chuyển động + trạng thái thu gọn. Bề rộng là
  **state của store** (nguồn sự thật duy nhất), chỉ ghi khi thả tay, và lưu bền cùng khoá
  `fabrikai.bar` đã có — không thêm khoá localStorage mới.
- **Flex item mặc định `min-width: auto` nên KHÔNG co được xuống 0** — thiếu `min-width: 0` thì
  "co dock" trông như bị đứng. Đã khoá bằng test.
- Không để nội dung tràn ngang trong card: đo bằng Chrome thật ở **260px và 300px**
  (đo cả khi đã mở hết `<details>`).

---

## 8. Trợ năng (không phải việc "làm sau")

- Nút chỉ có icon **phải có** `aria-label`; nút có chữ thì thêm `title` giải thích kết quả.
- Tiến trình: `role="status" aria-live="polite"`; lỗi: `role="alert"`.
- Không dùng MÀU làm tín hiệu duy nhất (thêm icon/chữ: "đã lưu", dấu ✓).
- Trạng thái ẩn/hiện bằng CSS (opacity/visibility) phải đi kèm `inert` + `aria-hidden` để bàn phím
  và trình đọc màn hình không đi vào vùng đã ẩn.
- Focus bàn phím phải NHÌN THẤY; nếu đã có tín hiệu khác (đường kẻ đổi màu) thì bỏ vòng focus mặc
  định để không chồng hai tín hiệu.
- **Ẩn bằng `v-if` là mất hai thứ cùng lúc: hiệu ứng VÀ trạng thái.** Thu bề rộng về 0 giữ nguyên
  card bên trong (ô đang gõ, vị trí cuộn) và cho canvas nở ra theo từng frame.
- **Ẩn xong phải TRẢ focus về chỗ còn dùng được** (nút mở lại), không để rơi về `<body>`. Chụp focus
  trong watcher (chạy TRƯỚC khi patch DOM) rồi mới chuyển ở `nextTick`.
- **Kẹp vào "vùng của tôi" chưa đủ — phải kẹp vào "vùng còn NHÌN THẤY".** Lớp phủ của chính ứng dụng
  (ngăn kéo bảng Lớp, thanh công cụ floating) cũng che mất điều khiển; muốn biết chỗ nào bấm được thì
  phải hỏi `elementFromPoint`, và các lớp phủ nên **tự khai** vùng chúng chiếm
  (`[data-covers-canvas="right|bottom"]`).

---

## 9. Icon & emoji

- Icon **chỉ** lấy từ `resources/js/studio/icons.json` qua `StudioIcon` — cùng nguồn với PHP
  (`App\Support\IconRegistry`) nên thêm icon là thêm một khoá JSON, không phải sửa hai nơi.
- **Không dùng emoji trong chrome giao diện.** Ba lý do thật: (a) mỗi hệ điều hành vẽ một kiểu nên
  bố cục lệch nhau; (b) emoji không theo bảng màu nên phá vỡ tông của card; (c) trình đọc màn hình
  đọc tên emoji thành tiếng, chen vào giữa nhãn.
  Emoji chỉ còn chấp nhận trong **nội dung do người dùng/AI sinh ra**.
- **ĐÃ DỌN SẠCH (2026-09-23): 23/65 file · 134 lần xuất hiện → 0.** Nhiều nhất trước đó là
  `ConceptCard.vue` (43, gồm cả một trường `emoji` trong bảng dữ liệu kiểu tóc — đã bỏ hẳn trường đó),
  `store.js` (18, phần lớn là chuỗi toast), `AdminApp.vue` (7). Cách xử lý: chỗ là **icon** thì đổi sang
  `StudioIcon` (bot · image · save · eye · zoomIn · user · shirt · sparkles · wand · lock), chỗ chỉ là **trang trí** thì bỏ.
  **KÝ HIỆU CHỮ được giữ** (không phải emoji hình, và §8 dùng chúng làm tín hiệu phi màu): ✓ · ✕ · ✗ · ★.
  Khoá bằng `DesignSystemTest::test_no_pictographic_emoji_in_studio_chrome` — thêm emoji mới vào chrome là **test ĐỎ**.

---

## 10. Checklist trước khi merge một thay đổi giao diện

- [ ] Không thêm mã màu mới trong `<style scoped>` (ngoại lệ: 1 dòng gradient nhận diện).
- [ ] Thời lượng/đường cong chuyển động lấy từ token, không viết ms.
- [ ] Không có `transition-all`; hiệu ứng vòng lặp tắt được khi bật "giảm chuyển động".
- [ ] Dùng class/component dùng chung ở §2–§3 thay vì tự vẽ lại.
- [ ] Viền nút theo ĐÚNG từ vựng §5.1 (nút nghỉ `border-ink-600` · đang chọn `border-brand-500` ·
      hover `hover:border-brand-400`) — không thêm token mới, không dùng `border-white/*` cho nút.
- [ ] Đúng 1 hành động chính; nút khoá có dòng lý do `↳`.
- [ ] Thứ nâng cao nằm trong `<details>` đóng sẵn.
- [ ] 0 emoji mới; icon lấy từ `StudioIcon`.
- [ ] **Thông báo/tiến trình không rò rỉ chi tiết kỹ thuật** (§6): không tên model/nhà cung cấp,
      không `e.message` thô — dùng `userFacingError()` / `studio_fail()`.
- [ ] Nút chỉ-icon có `aria-label`; tiến trình `role="status"`, lỗi `role="alert"`.
- [ ] **Đúng ở CẢ HAI theme**: mở màn hình vừa sửa ở theme Sáng **và** Tối (nút đổi nhanh ở thanh
      trạng thái Studio, hoặc Cài đặt của tôi → Giao diện) — không chỉ theme đang dùng để code.
- [ ] Chữ dùng **bậc nội dung** (`text-cream-100/200/300/400`), **không** dùng `text-cream-*/NN` (§1.1).
- [ ] Màu trạng thái dùng token ngữ nghĩa (`text-danger|warn|ok|info`); chữ trên nền màu dùng
      `text-on-accent`; khối đảo màu dùng `bg-invert text-invert-content` — không dùng `text-ink-*` làm chữ.
- [ ] Nền canvas và viền vẽ TRÊN ẢNH (`.canvas-bg-*` · `border-white/*`) giữ màu CỐ ĐỊNH (§1.1 quy tắc 5).
- [ ] **Việc người dùng làm có nằm trong §11–§12 không** (đúng một luồng · không nhập lại · chi phí
      hiện TRƯỚC khi bấm · kết quả có bước tiếp theo · trạng thái nhìn thấy) — và **không rơi vào
      luật nào ở §14** (đặc biệt: nút "Lưu" phải kết thúc bằng một dòng CSDL; không có điều kiện
      tiên quyết ngầm; điều khiển phải bấm được ở mọi trạng thái).
- [ ] Đo lại bằng Chrome thật ở bề ngang hẹp nhất (260–300px) — không tràn ngang.
- [ ] `npm run build` rồi commit asset (máy chủ không có node).

**Test khoá bất biến:** `tests/Feature/DesignSystemTest.php` — (a) card Gợi ý từ ảnh: không tự chế
tiến trình, không emoji, không mã màu cứng, có nút chính + lý do khoá; (b) **từ vựng viền nút của
toàn studio** (§5.2); (c) tài liệu phải tồn tại và không được nói sai. Và
`tests/Feature/ToolbarAreaTest.php` (vùng toolbar cao cố định + cuộn trục X).

---

## 11. Người dùng & việc họ phải hoàn thành (3 persona)

Mọi quyết định giao diện phải trả lời được: **persona nào, đang làm việc gì, và việc đó dễ hơn ở chỗ nào**.

| | **Nhà thiết kế thời trang** | **Chủ doanh nghiệp / thương hiệu** | **Chủ xưởng may** |
|---|---|---|---|
| Việc hằng ngày | Ra ý tưởng → phác thảo → nhiều biến thể → lookbook để chào khách | Duyệt bộ sưu tập, kiểm soát chi phí, giao việc cho nhóm, giữ nhận diện thương hiệu | Nhận yêu cầu mẫu, chuẩn hoá ảnh kỹ thuật + mô tả chất liệu, gửi thợ may |
| Cần từ công cụ | Nhiều biến thể nhanh, giữ đúng phom/chất liệu, prompt tái dùng | Con số (chi phí/ảnh, tiến độ), tài khoản cho nhân viên, quy trình duyệt | Ảnh đúng kỹ thuật, mô tả rõ, xuất được gói file để sản xuất |
| Đã giao | Tạo hàng loạt · mẫu việc theo ngành · bộ sưu tập là điểm vào · phím tắt duyệt mẫu | Chi phí & tiến độ theo bộ · chia sẻ link khách duyệt (không cần tài khoản) · trang giá công khai | Xuất gói cho xưởng (ZIP: ảnh + phiếu kỹ thuật + bảng size + manifest) |
| Còn thiếu | Bộ sưu tập theo mùa vụ xuyên suốt (đã có nền, chưa đủ sâu) | Báo cáo chi phí/tiến độ theo nhóm | Mẫu kỹ thuật chuyên sâu hơn |

> Ghi chú kiến trúc: `projects` **hiện là dự án của từng user**, chưa có khái niệm tổ chức đầy đủ —
> ghế theo gói + nhóm dùng chung đã có (Q4), nhưng báo cáo theo nhóm thì chưa.

---

## 12. Bảy nguyên tắc UX bắt buộc

> Định hướng: **TIÊN TIẾN – THUẬN TIỆN – TỐI ƯU – TIẾT KIỆM THỜI GIAN.** Bảy nguyên tắc dưới đây là
> tiêu chí chấm mọi thay đổi giao diện.

1. **Một việc = một luồng.** Người dùng chọn *việc* ("Ra 5 ảnh lookbook cho bộ Thu 2026"), không chọn
   *công cụ*.
2. **Không nhập lại.** Mọi thứ đã nhập (chất liệu, người mẫu, bối cảnh, phom) thuộc về **Bộ sưu
   tập/Dự án** và tự có mặt ở mọi card.
3. **Chi phí hiển thị TRƯỚC khi bấm** ("1 ảnh = 1 credit · còn 34"), không bao giờ trừ xong mới báo.
4. **Không chặn giữa chừng.** Cảnh báo sớm khi credit thấp; khi hết thì đề xuất nâng cấp ngay tại chỗ
   (không đá ra trang khác).
5. **Kết quả luôn có bước tiếp theo.** Sau mỗi ảnh: *Biến thể · Sửa · Ghép · Tải · Gửi duyệt* — một
   cú bấm. (Đã làm: thanh hành động ngay trên thumbnail — Canvas · Tải · Biến thể · Sửa.)
6. **Tái dùng là mặc định.** Preset prompt theo ngành (lookbook, sàn TMĐT, mẫu kỹ thuật xưởng),
   prompt library, người mẫu/dáng đã lưu — đặt ngay chỗ bắt đầu, không phải vào card rồi mới tìm.
7. **Trạng thái luôn nhìn thấy.** Gói hiện tại · credit còn lại · hạn mức còn lại của chu kỳ · việc
   đang chạy — ở một chỗ, không phải đi tìm.

**Cách kiểm đã áp dụng thật** (đợt UX theo VSCode/OpenArt, 2026-09-20):

- Nguyên tắc 3: màn hình canvas trống hiện `~N credit` và **co giãn theo số biến thể**
  (đo trên trình duyệt thật: 1 biến thể `~1` · 2 → `~2` · 4 → `~4`).
- Nguyên tắc 5: 4 hành động một cú bấm ngay trên thumbnail (đo: 5 ảnh × 4 nút; thanh hành động
  **luôn hiện**, không ẩn theo hover — ẩn theo hover là bẫy trên thiết bị cảm ứng).
- Nguyên tắc 6–7: mẫu việc theo ngành + gói/credit/việc đang chạy nằm cùng chỗ với hành động.

---

## 13. Gói cước & credit trên giao diện

### 13.1 Luật hiển thị

1. **Chi phí TRƯỚC khi bấm** (nguyên tắc 3) — kể cả ước tính cho lượt hàng loạt (số ảnh × credit
   theo gói), luôn kèm credit còn lại.
2. **Cảnh báo trước, không chặn giữa việc.** Khi credit sắp cạn (< 3 thao tác) trả `credit_warning`
   và hiện cảnh báo; khi đã hết và cờ `studio_enforce_credits` BẬT thì trả **402 kèm hướng dẫn
   nâng cấp ngay tại chỗ**. Super Admin được miễn chặn để không tự khoá mình.
3. **Vượt hạn mức của gói thì HẠ xuống mức gói cho phép, không chặn** (`resolution_cap`), và
   **nói rõ lý do** trong `notice` — người dùng đang làm dở thì không bị chặn ngang.
4. **Nói thật về thanh toán.** Hệ thống **chưa có cổng thanh toán VNĐ**: nút "Nâng cấp" phải nói rõ
   là "kích hoạt, thanh toán sau". Quảng cáo sai sự thật là lỗi sản phẩm, không phải chi tiết kỹ thuật.
5. **Bảng gói đọc TRỰC TIẾP TỪ DB** (`/bang-gia` render phía máy chủ) — không có con số nào ghi cứng
   trong template, để giá/credit đổi ở một chỗ.
6. **Gói và credit là trạng thái luôn nhìn thấy** (nguyên tắc 7): badge credit trên thanh công cụ
   Studio là **nút mở popup "Gói & credit"** — gói đang dùng · credit còn lại · chi phí **theo gói**
   của ảnh/video · độ phân giải tối đa · hạn gói · cảnh báo sắp cạn · danh mục gói để đổi ngay.
   Dữ liệu từ `GET /api/plan/status`; `/api/boot` trả `user.plan` + `cycle`.

### 13.2 Gói phải khác nhau ở thứ khách CẢM NHẬN được

| Trục khác biệt | Miễn phí | Khởi nghiệp | Chuyên nghiệp | Studio | Xưởng theo vụ |
|---|---|---|---|---|---|
| Ảnh/tháng (credit) | 100 dùng thử | 120 | 350 | 1.200 | 3.000 / vụ (3 tháng, cấp MỘT LẦN) |
| Độ phân giải | 1K | 2K | 2K | 2K + Max | 2K + Max |
| Số ghế (nhân viên) | 1 | 1 | 3 | 10 | 5 |
| Hàng đợi | thường | thường | ưu tiên | ưu tiên cao | ưu tiên cao |
| Xuất gói cho xưởng | — | — | ✓ | ✓ + bảng size | ✓ + bảng size |
| Duyệt nội bộ/khách | — | — | ✓ | ✓ + nhiều cấp | ✓ + nhiều cấp |
| Bộ sưu tập lưu trữ | 1 | 5 | 20 | Không giới hạn | Không giới hạn |

> Nguyên tắc: **credit là đơn vị công việc**, còn **gói là đơn vị năng lực**. Nếu hai gói chỉ khác số
> credit thì khách sẽ luôn chọn gói rẻ và mua lẻ.
>
> **Bài học doanh thu (đã sửa):** cam kết "N credit/tháng" mà không cấp credit thì biên gộp tháng thứ
> hai trở đi là ~100% — tức là "lãi" bằng cách không thực hiện cam kết. Cấp credit chu kỳ nay là
> **idempotent (CAS)** và có cả đường **lazy** (boot + trước khi tạo ảnh) lẫn **cron**
> `php artisan studio:grant-plan-credits`, để không phụ thuộc hPanel.

---

## 14. Luật rút ra từ thực tế — mỗi luật đã trả giá bằng một lỗi thật

> Đây là phần đắt nhất của tài liệu. Mỗi luật dưới đây từng là một khiếu nại thật của người dùng
> hoặc một lần test xanh mà sản phẩm vẫn hỏng. Đọc trước khi sửa một lỗi "trông có vẻ đơn giản".

### A. Đo và chẩn đoán

1. **Đo tách từng chiều trước khi sửa.** "Không lưu" và "không nạp" là hai lỗi khác nhau — chiều nạp
   hoàn toàn đúng, chỉ chiều lưu hỏng; gộp chung rồi sửa cả hai là vừa mất công vừa phá phần đang tốt.
2. **Tham số bị bỏ qua ÂM THẦM nguy hiểm hơn tham số bị từ chối.** `POST /api/reframe` kèm
   `project_id` trả **200** nhưng dòng CSDL có `project_id = NULL`; chỉ khi đọc thẳng CSDL mới thấy.
3. **Nghi ngờ "chậm" bằng cách đo BYTE phải đi qua mạng, không đo cảm giác.** Lưu layer "chỉ" 85 ms
   trên localhost — nhưng đó là 0,9–4,4 MB đi qua đường lên của người dùng. Số đúng phải đo là **byte**.
4. **Test ĐỎ chưa chắc là sản phẩm sai — nghi cách ĐO trước.** Đã có lần phải sửa TEST vì đo sai:
   CSS `uppercase` đổi chuỗi; icon SVG chen giữa `innerText`; đo `<aside>` thay vì phần tử
   `sticky`; dò `#app` thay vì `#studio-root`; đọc computed style trước khi Vue patch xong.
5. **Kiểm chứng trên môi trường THẬT bắt được lỗi mà test xanh không thấy.** `UserCatalogApiTest`
   (23 test) xanh vì `RefreshDatabase` tự migrate — nhưng DB dev chưa migrate nên API trả **500**.
6. **Kiểm thử trên trạng thái SẠCH có thể che đúng cái lỗi người dùng đang gặp.** Layer mới luôn đủ
   `baseW/baseH`; chỉ khi gieo **dữ liệu cũ/thiếu trường** mới tái hiện được "không có tay cầm".
7. **Gieo dữ liệu kiểm thử phải làm TRƯỚC khi app mount** — app có handler `beforeunload` ghi đè
   localStorage; gieo sai thời điểm thì bài test đo chính cái rỗng do mình vừa tạo.
8. **Audit theo TRẠNG THÁI hiệu quả hơn sửa theo phỏng đoán.** Duyệt 12 trạng thái khung canvas chỉ ra
   ngay 2 lỗi mà ba vòng sửa trước không thấy.
9. **Khi một khiếu nại lặp lại y nguyên, tìm TRẠNG THÁI làm cả hai điều cùng sai** — đừng sửa sâu hơn
   cái đã sửa, và **hỏi "đã tải lại trang chưa"** trước khi sửa mã (deploy KHÔNG cập nhật tab đang mở).
10. **Danh từ mơ hồ phải hỏi lại trước khi sửa.** "Layer dock" là **bảng Layers**, không phải layer vẽ
    trên canvas — hiểu sai một danh từ tốn nhiều đợt sửa sai đối tượng.

### B. Dữ liệu và lưu trữ

11. **Mọi nút "Lưu" phải kết thúc bằng MỘT DÒNG CSDL, hoặc một lỗi rõ ràng.** "Báo thành công mà không
    ghi gì" nặng hơn "báo lỗi": người dùng tin là đã lưu, đóng tab, và mất ảnh (đã xảy ra 2 đợt liền).
12. **Trường phái sinh trong bộ nhớ phải có đường TỰ VÁ.** `ensureLayerSizes()` đo lại kích thước cho
    layer cũ ⇒ layer cũ tự lành sau một lần tải, người dùng không phải xoá rồi thêm lại.
13. **Chuyển lớp lưu trữ là thay đổi có thể MẤT DỮ LIỆU — phải có đường lùi và một test khẳng định.**
    Chỉ cần quên `await catalog.load()` ở một chỗ là người dùng không thấy tùy chỉnh của chính mình.
14. **Chống trùng phải dựa trên DANH TÍNH — và danh tính có thể KHÔNG đổi sau khi lưu.** Layer ghép
    (data URL) lưu xong vẫn là chính nó ⇒ phải **đánh dấu** ngay trên layer vừa lưu, không chỉ so sánh.
15. **Nhất quán giữa hai loại dữ liệu cùng nằm một chỗ.** Ảnh AI tạo và ảnh người dùng tải lên nằm
    chung một Thư viện; chỉ một loại vào được bộ sưu tập là điều vô lý với người dùng, dù về kỹ thuật
    chúng khác nhau hoàn toàn.
16. **Không nới bảo mật để tiện.** `studio_assets` là đường tắt hấp dẫn nhưng sẽ làm rò ảnh cá nhân
    vào picker chung; mọi thao tác ghi lên ảnh tải lên đi qua đúng hai lớp mà đường xoá đang dùng.
17. **Xoá một tính năng là xoá cả DÂY CHUYỀN, nhưng phải biết chỗ CỐ Ý giữ** — và ghi lý do ngay tại
    chỗ, nếu không người sau sẽ "dọn mã chết" và làm hỏng hàng đợi cũ.

### C. Trạng thái và điều kiện

18. **Điều kiện tiên quyết NGẦM là nguồn của "tính năng không tồn tại".** Tay cầm *có* trong mã, *có*
    hiệu ứng, *có* test — nhưng chỉ khi layer đang được chọn. Với người dùng, "chỉ hiện khi X" đọc
    thành "không có". **Bỏ điều kiện trước khi viết thêm hướng dẫn.**
19. **"Đặt cờ rồi quên render" là loại lỗi âm thầm tệ nhất.** `confirmDeleteOpen` được bật nhưng không
    có popup nào render cờ đó — và tệ hơn, cờ treo ấy còn **chặn luôn phím tắt** của cả bảng Lớp.
    Mỗi cờ mở modal phải có một test bất biến "cờ này CÓ nơi render".
20. **Ẩn bằng `v-if` là mất hai thứ cùng lúc: hiệu ứng VÀ trạng thái bên trong.**
21. **Computed có ĐO DOM thì phải có phụ thuộc cho MỌI thứ nó đo.** Toạ độ tay cầm phụ thuộc kích
    thước vùng canvas, nhưng computed chỉ biết `zoom`/`pan`/`layer` ⇒ "đóng băng" sau lần tính đầu.
    Cách sửa đúng là thêm một NGUỒN SỰ THẬT cho kích thước (`viewportTick` + `ResizeObserver`).
22. **Đường LÙI (fallback) của hàm hiển thị có thể che mất hành vi người dùng đang kiểm.**
    `upscaleSrc` lùi sang ảnh khác để "luôn có gì đó để xem" — chính nó làm thao tác ẩn/hiện layer
    trở nên VÔ HÌNH.
23. **Chế độ ẩn của ứng dụng phải TỰ KHAI.** "Chỉ hiện 1 layer" là chế độ hợp lệ, nhưng không có nhãn
    thì người dùng đọc nó thành "ứng dụng hỏng". Một nhãn + một nút thoát rẻ hơn nhiều một vòng sửa lỗi.
24. **Hai nguồn dữ liệu khác nhau KHÔNG được dùng chung một thuộc tính đang chuyển động.** Gộp "độ mờ
    riêng của layer" và "hệ số ẩn/hiện" vào một `opacity` rồi transition ⇒ thanh trượt bị trễ 220ms.
25. **Thuộc tính vừa do inline style vừa do CSS quản thì phải đưa về MỘT biến** — nếu không, hiệu ứng
    chỉ chạy trên `<img>` còn viền chọn/tay cầm đứng nguyên tới lúc phần tử bị gỡ.

### D. Giao diện và chuyển động

26. **"Đã có token" KHÁC "cả app theo token".** Chỗ nối hoá ra chỉ là **hai biến theme**
    (`--default-transition-duration` · `--default-transition-timing-function`): ghi đè hai biến đó là
    **199 chỗ** dùng `transition*` tự chạy theo token. Tìm chỗ nối TRƯỚC khi viết class dùng chung.
27. **Tailwind v4 dùng `translate/scale/rotate` làm thuộc tính RIÊNG.** `transition-property` thiếu
    chúng thì hiệu ứng "nhấc thẻ khi hover" đứng im mà **không có lỗi nào** — test phải khoá danh sách
    thuộc tính lại.
28. **Class CSS không tồn tại thì không ai báo lỗi.** Tên class bàn cờ nằm trong template từ lâu mà
    không có định nghĩa nào trong CSS — trình duyệt im lặng bỏ qua. Test quét class phải **đối chiếu
    với CSS**, không chỉ kiểm sự có mặt của chuỗi trong template.
29. **`overflow:hidden` + phần tử con định vị ngoài khung = mất chức năng im lặng**; và
    **`pointer-events` là thuộc tính KẾ THỪA** — lớp phủ `pointer-events:none` làm hai tay cầm con
    CÂM luôn: vẽ ra đủ, toạ độ đúng, mà bấm không có gì xảy ra. Chỉ `elementFromPoint` mới lộ ra.
30. **Ẩn theo hover là bẫy trên thiết bị cảm ứng.** "Gọn mắt" không đáng đánh đổi khả năng dùng được.
31. **Dựa vào tính năng nền tảng mới thì phải có đường lùi.** `@property` + transition trên biến đã
    đăng ký: trình duyệt không hỗ trợ thì **không có hiệu ứng nào** và cũng không có lỗi nào.
32. **Sửa quá tay cũng là một loại lỗi.** Các bản vá thêm dần (tay cầm mọi layer, nhãn chữ trên canvas,
    đổi nguồn ảnh chế độ 1 layer) đều không được yêu cầu và làm không gian làm việc rối hơn. Điểm ngọt
    = giữ phần sửa ĐÚNG lỗi, bỏ phần trang trí thêm vào.

### F. Theme và tương phản (thêm 2026-09-23 — đợt theme Sáng/Tối)

37. **ĐỘ MỜ không phải là cách tạo bậc chữ.** Toàn bộ thang chữ phụ của app từng được dựng bằng
    opacity trên một sắc duy nhất: **741 chỗ** `text-cream-300/25…/85`, trong đó `/40` chỉ đạt **2,9:1**
    (WCAG AA cần 4,5:1). Loại lỗi này **không có triệu chứng trong mã** — không exception, không log,
    test nào cũng xanh; chỉ người ngồi đọc mới thấy mỏi mắt. Cách sửa bền: **4 bậc ĐẶC** (cream-100 →
    400) + một test tính tương phản bằng công thức WCAG. Đo bằng cảm nhận là đo sai công cụ.
38. **Thêm theme thứ hai là bài kiểm tra mọi chỗ viết màu theo lối "nền sáng".** Vỏ `.studio-dark` cũ
    phải dịch hộ hơn 20 class (`bg-cream-*` → `bg-ink-*`, `text-ink-900` → `text-cream-50`, `bg-white` → `bg-ink-800`…)
    chỉ để cứu những chỗ viết ngược vai. Cách đúng — và nay đang dùng — là **để hai dải token ĐẢO VAI
    theo theme** (`ink-*` = bề mặt, `cream-*` = nội dung) + **4 token CỐ ĐỊNH** cho những nền không theo
    theme (`invert` · `invert-content` · `invert-hover` · `on-accent`). Nhờ vậy không class nào phải
    nhân đôi cho hai theme, và không còn khối "dịch màu" nào để mục nát dần.
39. **Giá trị người dùng chọn mà đi thẳng vào thuộc tính HTML thì phải có whitelist CỨNG.** `users.theme`
    được render vào `data-theme` của thẻ `<html>` ở MỌI blade ⇒ nhận chuỗi tự do ở endpoint là một lỗ XSS
    (thuộc tính do người dùng kiểm soát). Whitelist `light|dark|system` + test 422 cho sáu giá trị lạ, và test
    khẳng định thứ ghi xuống DB là giá trị CHUẨN (middleware TrimStrings cắt khoảng trắng trước khi kiểm).

40. **Đo tương phản phải chuẩn hoá MỌI cú pháp màu — và alpha có HAI cú pháp.** Lần đo đầu của
    đợt này báo **529 chỗ dưới chuẩn** ở theme sáng; sự thật là **0**. Nguyên nhân: bộ đo chỉ hiểu
    `rgba(r,g,b,a)` nên đọc `rgba(0,0,0,0)` (TRONG SUỐT) thành ĐEN ĐẶC và lấy nền sai; Chrome
    còn trả màu bằng `oklch()`/`oklab()`/`color(srgb …)` (bảng màu Tailwind v4) và `color-mix()` cho
    các tiện ích có alpha. Bài học kép: (1) chuẩn hoá màu qua canvas rồi mới tính; (2) **khi phép đo
    cho kết quả bất thường, nghi phép đo trước** — đúng vệt với các lần "test đỏ mà sản phẩm đúng"
    ở nhóm A.

41. **Đổi CẤU TRÚC mà giữ nguyên GIÁ TRỊ thì người dùng không thấy gì — và họ nói đúng.** Đợt theme
    đầu tiên đã làm đúng về kỹ thuật (hai dải token, đảo vai theo theme, tương phản AA, lưu theo tài
    khoản) nhưng **bảng màu giữ nguyên tông nâu ấm cũ**, tên token cũng cũ. Khiếu nại: *"chưa thấy thay
    đổi về giao diện, không thấy ảnh hưởng gì từ theme tham chiếu"*. Bài học: khi được đưa một **theme
    tham chiếu**, phải tiếp nhận thứ ĐO ĐƯỢC từ nó — **bộ tên token** và **giá trị màu** — chứ không
    phải chỉ "lấy tinh thần". Và phải trả lời được câu: *người dùng sẽ nhìn thấy gì khác?*
42. **Tính năng mới phải có ĐƯỜNG VÀO ở chỗ người dùng đang đứng.** Mục "Giao diện" đã có thật, có test,
    có trang riêng — nhưng menu Cài đặt (bánh răng) trong Studio chỉ có 4 mục cũ, còn nút đổi nhanh ở
    thanh trạng thái là **icon trần giữa ~20 icon khác**. Kết quả: *"không thấy cài đặt theme"* — một
    tính năng chết vì không có lối vào. Quy tắc: tính năng mới phải xuất hiện ở **nơi người dùng đã
    quen bấm** (menu/thanh công cụ hiện có), và nếu là điều khiển quan trọng thì **có chữ**, không chỉ
    icon.

43. **Số cứng thì không có cài đặt.** 941 chỗ cỡ chữ viết bằng px nghĩa là "không thể cho người dùng chỉnh
    cỡ chữ" — muốn có công tắc thì TRƯỚC HẾT phải biến số cứng thành token theo vai. Cùng bài học với
    màu (token) và chuyển động (token). Khi một yêu cầu nghe như "thêm một tùy chọn", hãy hỏi: *dữ liệu
    đó đang là hằng số ở bao nhiêu chỗ?*
44. **Mỗi card một màu = người dùng phải học lại từng card.** 9 gradient nhận diện riêng (xanh · tím ·
    cam · xanh dương…) trông "có cá tính" nhưng phá đúng mục tiêu của tài liệu này: học MỘT lần, dùng mọi
    card. Sắc thái riêng chỉ nên đến từ DỮ LIỆU (ảnh, màu trạng thái), không từ khung của card.
45. **Đo màu bằng chuỗi là đo SAI âm thầm — phải để TRÌNH DUYỆT giải màu.** Tailwind v4 phát màu dạng
    `oklab(0.489 -0.081 0.032 / 0.2)`; đọc chuỗi đó bằng biểu thức số rồi coi là RGB cho ra một bảng
    "14 chỗ dưới AA" **hoàn toàn không tồn tại** — và che mất một chỗ dưới AA **có thật**. Cách đo đúng:
    nạp màu vào `ctx.fillStyle` → `fillRect` → `getImageData` để lấy sRGB, rồi **hợp alpha (composite)
    theo cả cây tổ tiên** chứ không lấy nền của phần tử gần nhất. (Bài học 4 nhắc lại: test đỏ chưa chắc
    sản phẩm sai — nhưng số đo sai thì cũng chưa chắc sản phẩm đúng.)
46. **Bậc chữ MỜ NHẤT phải đo cả trên NỀN TINT, không chỉ nền phẳng.** `cream-400` của theme Sáng đạt
    6,04:1 trên nền trắng nhưng chỉ **4,46:1** khi nằm trên hàng đang chọn (`bg-brand-600/20`) — nền tint
    làm nền tối đi. Trạng thái "đang chọn/đang bật" là nơi chữ mờ nhất hay xuất hiện nhất, nên nó phải
    nằm trong bộ đo tương phản bắt buộc.

47. **Mã tra cứu phải có ở CẢ HAI phía sinh lỗi — thiếu một phía là mất một nửa dấu vết.** Mã `L-XXXX` ban
    đầu chỉ có ở lỗi MÁY CHỦ. Nhưng lỗi người dùng thật hay gặp lại sinh ngay trong TRÌNH DUYỆT (mất mạng,
    canvas không xuất được blob, exception không ai bắt) — nhóm đó không đi qua controller nào nên không
    có dòng log nào để tra, dù khách vẫn đọc được một câu lỗi. Khi thiết kế cơ chế chẩn đoán, hãy hỏi:
    *lỗi này sinh ra ở MẤY nơi, và mỗi nơi đã có đường ghi dấu vết chưa?*
48. **Không được NÉM BỎ dữ liệu chẩn đoán trên đường về.** Server gửi kèm `error_code`, nhưng client viết
    `throw new Error(d.message)` là mất mã ngay tại đó — log có, mã tra cứu trên màn hình không. Đây là
    dạng lỗi "hai đầu đều đúng, chỗ nối làm rơi": trước khi tin một cơ chế đã hoạt động, phải đo ở ĐẦU
    CUỐI của chuỗi (câu người dùng đọc), không phải ở chỗ mình vừa viết.

49. **Đừng trả lời một câu hỏi ĐO ĐƯỢC bằng một câu VĂN.** Giao diện ghi "nguồn ngoài đang ở chế độ
    demo" suốt nhiều tháng, trong khi câu hỏi thật của người dùng là *"agent có ra được internet không?"* —
    và câu trả lời phụ thuộc HAI tầng khác nhau (máy chủ · nhà cung cấp model). Câu văn tĩnh không sai
    nhưng vô dụng: nó không đổi khi mọi thứ đổi. Khi một dòng chữ mô tả NĂNG LỰC của hệ thống, hãy biến
    nó thành phép đo có thời điểm, có số, và có nút đo lại.
50. **Dữ liệu do NGƯỜI DÙNG khai và dữ liệu hệ thống SUY RA phải để RIÊNG, và phải nói rõ đang dùng
    bản nào.** DNA shop trước đây chỉ là suy đoán (đếm dự án, dò từ khoá, không có gì thì câu mặc định)
    mà giao diện vẫn gọi chung là "DNA shop" — người dùng tin rằng hệ thống đã hiểu họ. Nay có hồ sơ
    riêng, có thứ tự ưu tiên rõ, và khoá `source` + nhãn tiếng Việt đi kèm trong payload.

### E. Quy trình và kiểm thử

33. **Mỗi route mới phải khai vào `ModuleRegistry`** — nếu không, công tắc gói không chặn được nó, và
    `ModuleRegistryTest` ĐỎ ngay. Rào chắn của dự án bắt lỗi thay người viết; giữ những test kiểu này
    đáng giá hơn nhiều test chỉ kiểm tra đúng thứ vừa viết.
34. **Không tự ý sửa DOM của component khác.** Bản đầu của màn hình trống tự `querySelector` ô prompt
    của card khác để điền chữ — vỡ ngay khi card đổi bố cục. Dùng **kênh store**
    (`requestWorkspace()` · `requestActivity()` · `requestBatchPrompts()`).
35. **Cơ sở dùng chung chỉ có giá trị khi thành phần THỨ BA dùng nó.** Dock Layers chỉ cần khai preset
    + gắn vách ngăn, không chép logic kéo/kẹp/lưu/trả focus — đó là bằng chứng thiết kế dock đã đúng.
36. **Kiểm chứng bằng mắt phải so khớp đúng thứ NGƯỜI DÙNG thấy**, không phải thứ mình viết; và khi
    không xem được ảnh thì **phân tích điểm ảnh** (lưới độ sáng) là cách đọc ảnh bằng số.

---

## 15. Khung Studio đã chốt — không sửa lại từ đầu

> Studio đã có xương sống kiểu VSCode (activity bar · command palette · status bar · dock trái/phải ·
> phím tắt canvas) và đã qua 21 vòng tinh chỉnh. Dưới đây là những thứ **đã đúng, đừng làm lại** —
> chỉ vá đúng chỗ còn phải "tự tìm đường".

### 15.1 Shell & điều hướng

- **8 nhóm card** trên activity bar trái: `collection` (Bộ sưu tập — đứng ĐẦU) · `concept` (Tạo ảnh) ·
  `variation` · `tryon` · `inpaint` · `compose` · `upscale` · `director` — cộng 3 mục điều khiển
  `prompt` · `stylist` · `settings` (ghim đáy).
- **Quick Open `Ctrl+K` đa nguồn**: 4 nhóm (Lệnh · Bộ sưu tập & dự án · Mẫu việc theo ngành · Ảnh đã
  tạo) với tiền tố quen thuộc `>` lệnh · `#` dự án · `@` ảnh.
- **Kênh nối card ↔ shell qua store, không đụng DOM** (luật 34 ở §14).
- **Khu Cài đặt là MỘT app** (`/cai-dat` + `/cai-dat/{mục}`) với sidebar 5 mục: Preset · Khuôn mặt ·
  Dáng pose · Trợ lý thiết kế · **Giao diện** (Sáng · Tối · Theo hệ điều hành); 4 URL cũ (`/presets` · `/stylist-data` · `/model-settings?tab=…`) vẫn
  trả 200 và render cùng blade. Tùy chỉnh lưu **theo tài khoản** (`user_catalogs`), không chỉ localStorage.

### 15.2 Dock (trái · Outputs · Layers)

- Ba dock dùng **cùng một cơ sở**: preset `DOCK_PRESETS` (trái 288px · Outputs 156px · Layers 256px =
  đúng kích thước cũ), `useDockResize.js` + `DockResizer.vue`.
- Kéo bằng pointer (bắt pointer nên lệch 7px vẫn dính) · bàn phím (←/→, Shift = bước lớn, Home/End,
  Enter ẩn/hiện, Esc về mặc định) · nhấp đúp = mặc định · kẹp `[min, max]` với trần mềm theo bề rộng
  cửa sổ (Layers: 42%).
- **Ẩn = thu bề rộng về 0** kèm `data-collapsed` + `inert` (KHÔNG dùng `v-if`); `visibility` trễ đúng
  bằng thời lượng để nội dung không biến mất trước khi khung co xong.
- Bề rộng **lưu bền** trong khoá `fabrikai.bar`; tay cầm vách ngăn hiện sẵn ở mức mờ 0.45 (chỉ hiện
  khi hover thì người mới không bao giờ thấy nó).

### 15.3 Canvas

- **Tay cầm chỉnh kích cỡ**: layer **đang chọn** (đủ kích cỡ + xoay) và layer **đang trỏ vào** (mờ).
  Kéo tay cầm của layer chưa chọn = tự chọn layer đó rồi chỉnh luôn.
- Tay cầm là **lớp phủ theo toạ độ màn hình, KẸP vào vùng còn nhìn thấy** (trừ vùng các lớp phủ tự
  khai) ⇒ không bao giờ bị `overflow:hidden` cắt và luôn bấm được.
- Layer **khoá vẫn có tay cầm** (màu hổ phách, bấm để mở khoá) — biến mất không giải thích là lỗi UX.
- **Bật/tắt layer**: giữ trong DOM, mờ tại chỗ (`opacity` ở thẻ ngoài + độ mờ riêng ở thẻ trong, không
  transition) ⇒ hiệu ứng chạy trên mọi trình duyệt và thanh trượt Độ mờ vẫn tức thì.
- Nền canvas: bốn class `.canvas-bg-{grid,dark,white,cream}` dùng cho **cả** vùng canvas **và** ô màu
  ở thanh trạng thái.
- **Màn hình canvas trống giàu nội dung** (`CanvasEmptyState.vue`): thanh prompt ngay trên canvas ·
  biến thể · 7 tỉ lệ · credit còn lại · mẫu việc theo ngành · ảnh gần đây · gợi ý phím tắt.
- **Nhắc trạng thái nằm ở thanh trạng thái**, không dán nhãn chữ lên canvas.

### 15.4 Thông báo & kết quả

- `NotificationCenter.vue`: hàng đợi **xếp chồng** góc phải-dưới (tối đa 4) · lỗi giữ **8s** (thường
  4,2s) · đóng tay từng mục · nút **Xoá hết** · kèm thẻ tiến trình đọc số THẬT.
  `store.toast(msg, type)` vẫn là API duy nhất mọi nơi gọi (chỉ đẩy thêm vào hàng đợi).
- **Mỗi ảnh kết quả có thanh hành động luôn hiện** (không ẩn theo hover): **Canvas · Tải · Biến thể ·
  Sửa** — nguyên tắc 5.
- **Đổi giao diện nhanh** ngay tại chỗ làm việc: nút Sáng/Tối ở thanh trạng thái Studio (đổi tức
  thì, không tải lại trang); ba lựa chọn đầy đủ nằm ở Cài đặt của tôi → Giao diện.
- **Bộ sưu tập** là điểm vào công việc hằng ngày: đang làm bộ nào · số ảnh · hạn chót đếm ngược ·
  việc đang chạy · chi phí & tiến độ · phản hồi khách · nút Xuất gói cho xưởng.

---

## 16. Lịch sử triển khai — 29 vòng, mỗi vòng có số đo

> Bảng này là **bản ghi rút gọn** của các vòng đã làm. Bản đầy đủ (bối cảnh, bằng chứng từng bước, bài
> học chi tiết) nằm trong lịch sử git của `docs/UX_PERSONA_STRATEGY.md` — tài liệu đó đã được hợp nhất
> vào đây ngày 2026-09-22. Cột "Số đo chốt" là con số kiểm chứng được, không phải mô tả ý định.

| # | Ngày | Vấn đề | Đã làm | Số đo chốt |
|---|---|---|---|---|
| 1 | 2026-09-19 | `credits_per_month` **không bao giờ được cấp**; chi phí/cap theo gói là cột trang trí; không có UI gói | Cấp credit chu kỳ idempotent (CAS) + lazy + cron · `studio_credit_cost()` (8 chỗ) · hạ `resolution_cap` kèm `notice` · cờ `studio_enforce_credits` (402 + `credit_warning`) · UI "Gói & credit" · trang `/bang-gia` đọc từ DB | 8 test mới · 499 test/2.682 assert · gán gói thật: 200 → **350** credit, đúng **một** dòng `plan_grant` |
| 2 | 2026-09-19 | Người làm nghề không có "bộ sưu tập" xuyên suốt | Panel Bộ sưu tập (8 panel, đứng đầu) · tạo bộ trong panel + tự áp dụng · `/api/job-templates` 4 mẫu việc · `/api/projects/{id}/stats` · duyệt mẫu theo lô | 4 test mới + 2 test cũ cập nhật có ý thức · 519 test · Chrome CDP **17/17** |
| 3 | 2026-09-19 | Tạo nhiều ảnh phải bấm/sửa prompt từng lần | Tab **Hàng loạt** (12 mục × 1–4 biến thể) · tiến trình từng mục · "Chạy lại N mục lỗi" · phím tắt duyệt mẫu `S/N/A/R/Esc` | 4 test mới · 515 test · CDP **24/24** · credit trừ đúng 6 (200 → 194) |
| 4 | 2026-09-19 | Xưởng không nhận được gói đủ nghĩa; khách duyệt phải có tài khoản | Xuất gói ZIP (ảnh đánh số + phiếu kỹ thuật + bảng size CSV + manifest + README; **ghi rõ ảnh không tải được**) · link chia sẻ `/chia-se/{token}` (hạn 7/30/90 · thu hồi · đếm lượt xem · noindex) | 5 test mới · 524 test · CDP **12/12** · chạy thật trên production |
| 5 | 2026-09-20 | Người dùng phải "tự tìm đường" (canvas trống, toast ghi đè, thumbnail 1 hành động) | `CanvasEmptyState.vue` · `NotificationCenter.vue` xếp chồng · 4 hành động trên ảnh kết quả · Quick Open 4 nguồn | 614 test/3.908 assert (không sửa test nào) · credit `1→~1 · 2→~2 · 4→~4` · 3 toast cùng sống |
| 6 | 2026-09-20 | Cài đặt là 4 trang rời rạc; **tùy chỉnh nằm trong localStorage ⇒ đổi máy là mất** | Một app `/cai-dat` + sidebar · bảng `user_catalogs` + API · di trú localStorage một lần · Studio dùng lại `StylistSection.vue` | 639 test/4.039 assert · CDP **21/21** · di trú **7/7** · WCAG AA 0 chỗ dưới chuẩn |
| 7 | 2026-09-20 | Ảnh phái sinh **không được gắn vào bộ sưu tập** (9 phép bỏ qua `project_id` âm thầm) | `resolveProjectId()` cho cả 9 phép + trả `project_id` về client · bảng `upload_project_links` cho ảnh tải lên | 658 test/4.089 assert · CDP: 50 ảnh tải lên, 1 ảnh đổi ⇒ giữ đúng sau khi tải lại |
| 8 | 2026-09-20 | "Lưu Output" **không lưu** (chỉ chèn bản ghi GIẢ `layer-…`) và rất chậm (tải lại 0,9–4,4 MB) | `POST /api/layers/save`: có `source_url` ⇒ chỉ ghi dòng CSDL, không tải byte nào | 85 ms → **22 ms** · byte tải lên 889.470 → **0** · id chuỗi → **số thật** · 666 test |
| 9 | 2026-09-20 | Dock có bề rộng HẰNG SỐ, ẩn bằng `v-if` (mất trạng thái), chuyển động mỗi chỗ một số | Token chuyển động + `.motion-ui` · `useDockResize` + `DockResizer.vue` · thu bề rộng về 0 kèm `inert` | 676 test/4.343 assert · kéo 288 → **428px** · thu gọn **260ms** · `prefers-reduced-motion` ⇒ 0ms |
| 10 | 2026-09-20 | **199 chỗ** `transition*` đi theo mặc định cứng của Tailwind; 17 chỗ ms viết tay; vách ngăn không có chỉ báo | Ghi đè **2 biến theme** ⇒ cả 199 chỗ theo token · `duration-*`/`ease-*` theo tên nghĩa · tay cầm 14×30 · vòng lặp tắt theo công tắc | 683 test/4.462 assert · ms viết tay **17 → 0** · bề mặt hover thiếu chuyển động **6 → 0** |
| 11 | 2026-09-20 | Bấm Delete **không có gì xảy ra** (cờ treo còn chặn phím tắt); nền "lưới" trong suốt; ô màu lệch màu thật | `ConfirmDialog.vue` dùng chung (Esc · focus trả về chỗ cũ) · 4 class `.canvas-bg-*` · `TransitionGroup` cho layer | 689 test/4.539 assert · 4 nền khớp computed style · hiệu ứng layer 0.22s |
| 12 | 2026-09-20 | Hiệu ứng layer chỉ mờ mỗi ảnh; nút hành động chỉ hiện khi hover; "Lưu Output" tạo bản trùng | `--layer-opacity` (CSS nhân với hệ số ẩn/hiện) · gỡ trọn "Xóa nền AI" (UI→route→controller→module) · 3 nút luôn hiện · chống trùng theo danh tính | 692 test/4.570 assert · opacity `1 → 0` mượt · nút hành động `opacity 0 → 1` |
| 13 | 2026-09-20 | Tay cầm bị `overflow:hidden` **cắt mất**; layer khoá mất tay cầm; thanh trượt Độ mờ trễ 220ms | Tay cầm thành lớp phủ theo toạ độ màn hình + kẹp · chốt HƯỚNG khi kéo · tách hai nguồn độ mờ | 695 test/4.619 assert · `elementFromPoint` trả về chính tay cầm · độ mờ **0.5 sau 30ms** |
| 14 | 2026-09-20 | **Vẫn không có tay cầm**: layer cũ thiếu `baseW/baseH` (trạng thái thử của tôi khác trạng thái thật) | `ensureLayerSizes()` tự vá + đường đo dự phòng từ DOM · bỏ phụ thuộc `@property` | 696 test/4.627 assert · gieo dữ liệu cũ: tay cầm **0 → 2** |
| 15 | 2026-09-20 | Toạ độ tay cầm "đóng băng" khi vùng canvas đổi; trên mobile bị ngăn kéo che | `viewportTick` + `ResizeObserver` · kẹp vào **vùng còn nhìn thấy** (lớp phủ tự khai `data-covers-canvas`) | 698 test/4.639 assert · mobile 390×844 và 1024×700: cả hai tay cầm bấm được |
| 16 | 2026-09-20 | Gốc rễ cuối: chế độ "CHỈNH 1 LAYER" làm mất tay cầm **và** làm con mắt như không có tác dụng | Tay cầm ở cả hai chế độ · chế độ 1 layer hiện ĐÚNG ảnh của layer đang chọn · nhãn chế độ + nút Thoát | 699 test/4.646 assert · ẩn layer ⇒ ảnh lớn trên canvas 1 → 0 |
| 17 | 2026-09-20 | **Bỏ điều kiện tiên quyết**: tay cầm chỉ có cho layer đang chọn ⇒ 4 trạng thái "không có tay cầm" | `layerHandles`: mọi layer đang hiện đều có tay cầm; kéo = tự chọn rồi chỉnh · thumbnail bảng Lớp mờ khi layer tắt | 700 test/4.654 assert · "bỏ chọn hết" tay cầm **0 → 2** |
| 18 | 2026-09-20 | Yêu cầu thật là **DOCK LAYERS** (bảng Layers) — đã hiểu sai thành layer trên canvas suốt nhiều vòng | Preset `DOCK_PRESETS.inspector` + vách ngăn + lưu bền · bỏ `v-if` · canvas lấy **điểm ngọt** (bỏ tay cầm mọi layer, bỏ nhãn trên canvas) | 701 test/4.675 assert · bảng Layers 256 → **376px** kéo được · bật/tắt mượt |
| 19 | 2026-09-22 | Card «Gợi ý từ ảnh» vi phạm gần hết quy ước chung (2 nút chính · emoji · 40 mã `rgba()` · 2 hệ tiến trình) | Viết lại theo 6 quy tắc (§4) · ra đời **tài liệu này** + `DesignSystemTest` | **586 → 455 dòng** · 7 trạng thái: 1 nút chính · 0 emoji · không tràn ngang ở 260/300px |
| 20 | 2026-09-22 | **30 biến thể viền** cho ~8 nghĩa trên nút toàn Studio | Từ vựng ĐÓNG 16 token (§5) · `.tool-btn` và badge về cùng quy ước `/40` | 26 file · 134 chỗ thay thế · CSS gửi cho khách **−1,64 kB** (166,25 → 164,61) |
| 21 | 2026-09-22 | Giao diện **nói với lập trình viên**: lộ nguyên văn lỗi nhà cung cấp, tên model, lệnh CLI | Ba tầng chặn (§6): PHP · cửa chặn ở biên `toast/notify` · state dùng `userFacingError()` (14 chỗ) · gỡ chip "DeepSeek · deepseek-chat" | `UserFacingMessagesTest` + `SuggestStreamTest` · 6 phép đột biến ĐỎ đúng chỗ |
| 22 | 2026-09-23 | **Chữ dùng ĐỘ MỜ để tạo bậc ⇒ khó đọc**: 741 chỗ `text-cream-300/NN`, trong đó `/40` ≈ **2,9:1** (WCAG AA cần 4,5:1); app chỉ có MỘT giao diện (tối) và không cài đặt được | **4 bậc nội dung ĐẶC** + token trạng thái ngữ nghĩa · **theme Sáng/Tối** (hai dải token đảo vai, script chạy TRƯỚC khi vẽ, lưu theo tài khoản qua `users.theme` + `PUT /api/theme`) · mục **Giao diện** ở Cài đặt + nút đổi nhanh ở thanh trạng thái Studio | 50 file dọn màu (741 chỗ opacity + 345 chỗ sắc độ trạng thái) · mọi bậc chữ **6,7–18,3 : 1** · Chrome thật: 4 màn hình × 2 theme, **2.578 phần tử chữ/theme — 0 chỗ dưới AA** · CSS build **164,61 → 155,33 kB** (−9,3 kB) · 11 test mới (`ThemeSystemTest` 10 + 1 ở `CanvasControlsTest`) · icon 129 → **132** |
| 23 | 2026-09-23 | **6 test đỏ tồn đọng** ở HEAD (Bộ sưu tập · Duyệt mẫu · a11y lớp phủ · chuyển động hover) + **5 món nợ** đã ghi trong DEPLOY_LOG | Sửa **SẢN PHẨM** ở chỗ là lỗi thật: 4 lớp phủ thiếu `role/aria-modal` · 2 bề mặt hover thiếu nhịp · state `reviewErrors` chết (nay hiện lỗi **từng ảnh kèm bước**) · trả lại nút **Xử lý ngay** (`processQueue`) đã mất khi card sidebar thu gọn · gom đường xuất gói về `store.exportProject()` (bỏ 2 bản fetch trùng 26 dòng) · 2 test cập nhật theo **bề mặt thật** | 6 → **0 test đỏ** · emoji **134 → 0** (23 file) · xoá ~115 dòng CSS chết + `.section-title` · `transition-all` 19 → **0** · nền nút 3 kiểu → **1 token** (51 thẻ) · thêm trang **`/he-thong-thiet-ke`** · 10 test mới/ cập nhật |
| 24 | 2026-09-23 | **3 card còn MÀU NHẤN RIÊNG** (emerald: RefImageCard · ConceptCard · InpaintCard) + **12 nút chính bị khoá không nói vì sao** + 2 màn hình có **2 nút chính cùng lúc** | Thiết kế lại 3 card về đúng từ vựng: "đang chọn" = `border-brand-500 + bg-brand-600/20 + ring-brand-500/40` · "khối chứa" = `border-ink-700 + bg-ink-900` · "thành công" = token `ok` · nút chính của Inpaint về gradient thương hiệu · 12 nút khoá có dòng `↳` suy ra từ MỘT computed `blockReason` · hạ "Mở Agent Studio" xuống nút phụ · ẩn thanh CTA khi ở tab Hàng loạt | emerald trong 3 card **26 → 0** · **xoá hẳn danh sách miễn trừ emerald** trong `DesignSystemTest` · 2 test mới/bổ sung · `DesignSystemTest` **9/9 XANH** |
| 25 | 2026-09-23 | Khiếu nại: **"chưa thấy thay đổi giao diện + không thấy cài đặt theme + không thấy ảnh hưởng gì từ theme daisyUI đã đưa"** | Lấy **đúng giá trị màu của theme tham chiếu** (nền xám nguội `#15191e/#191e24/#1d232a` · chữ `#ecf9ff` · trạng thái `#ff627d/#fcb700/#00d390/#00bafe`) + đưa **bộ tên token daisyUI** (`base-100/200/300 · base-content · primary · secondary · accent · neutral · info/success/warning/error + -content`) thành lớp ngữ nghĩa chính, giữ xanh lá làm `primary` · **mở đường vào**: thêm mục "Giao diện (Sáng · Tối · Theo máy)" vào menu Cài đặt trong Studio + đổi nút thanh trạng thái thành **chip có chữ** · khối chọn giao diện dùng chính `bg-base-100/base-200/base-300 text-base-content bg-primary` và nói rõ nguồn bảng màu | nền tối `#17150f → #191e24` · chữ `#f4f2ec → #ecf9ff` · menu bánh răng **4 → 5 mục** · chip trạng thái nay **có chữ "Sáng/Tối"** · Chrome thật: 5 màn hình × 2 theme **0 chỗ dưới AA** · 847 test XANH |
| 26 | 2026-09-23 | Yêu cầu: **mọi card chung một màu** · **chữ hơi nhỏ, thêm cài đặt cỡ chữ** · làm nốt 6 việc còn nợ | Bỏ 9 gradient nhận diện riêng của card · thang cỡ chữ thành **6 token theo vai** (+1px mỗi bậc) + công tắc `--font-scale` (90/100/115/130%, lưu theo tài khoản, render sẵn ở server) · chọn ảnh nguồn NGAY trong card Gợi ý từ ảnh (`SourceLibraryPicker`) · **mã tra cứu lỗi L-XXXX** ở payload + log · **tên file theo kênh bán** (Shopee/Lazada/TikTok/catalogue/xưởng) · **tự chuyển trạng thái** khi khách bấm Duyệt (đi qua đúng whitelist) · trang **/bao-cao-nhom** (chi phí theo nhóm từ bảng generations) · 14 file bỏ viền trắng trên bề mặt | 941 `text-[Npx]` → **0** · 9 → **0** gradient nhận diện · `border-white/*` trên bề mặt **→ 0** · 4 test mới · full suite **XANH** |

| 27 | 2026-09-23 | Đo lại tương phản bằng Chrome thật: phép đo cũ đọc màu `oklab()` như RGB ⇒ **báo 14 chỗ dưới AA không có thật** và **che mất 1 chỗ dưới AA có thật** | Đo lại bằng canvas (`fillStyle` → `getImageData`) + hợp alpha theo cả cây tổ tiên; `--color-cream-400` theme Sáng `#59646f` → **`#525c67`** | Trước: **1 chỗ 4,46:1** (nhãn mô tả trên hàng đang chọn `bg-brand-600/20`) · Sau: **0 chỗ dưới AA** trên **10 tổ hợp** (5 màn hình × 2 theme), 56 phần tử bỏ qua vì nền gradient |

| 28 | 2026-09-23 | **Mã tra cứu lỗi chỉ có ở phía máy chủ**: lỗi sinh trong trình duyệt (mất mạng · fetch hỏng · canvas/Blob · exception không ai bắt) hiện ra giao diện mà KHÔNG có mã và KHÔNG có dòng log nào — hỗ trợ không tra được gì | Client sinh mã cùng bảng chữ với PHP + gửi chi tiết về `POST /api/client-errors` (mở cho khách, throttle 30/phút, gộp trùng theo mã) · `userFacingError` chọn đúng nguồn mã · **54 chỗ `toast(e.message)` → `failToast(e, …)`** · **`apiError()` giữ `error_code` của máy chủ** (trước đây bị ném bỏ) · bắt cả `window.onerror` + `unhandledrejection` | 54 chỗ toast lỗi + 20 chỗ ném lỗi nay đi qua đường có mã · hàng đợi `localStorage` gửi bù khi mất mạng · 10 test mới (`ClientErrorReportTest`) |

| 29 | 2026-09-23 | **Agent nói dữ liệu ngoài là "demo" bằng CÂU VĂN TĨNH** (không đo gì) · **DNA shop chỉ được SUY RA** (đếm dự án + dò từ khoá, không có thì dùng câu mặc định cứng) và chủ shop không sửa được | Giao diện nay đọc **số ĐO THẬT**: máy chủ có ra internet không (HEAD + mã HTTP + độ trễ) và model đang cấu hình có tìm kiếm tích hợp không — ba câu kết luận đúng thực tế · **DNA thành hồ sơ riêng, sửa được** (bảng `brand_dna`, bước 0 trong Agent Studio), ưu tiên `owner → shop_data → derived → default` và **đi thẳng vào prompt brief** · cờ `search` chỉ bật khi transport hỗ trợ (`enable_search`/`google_search`) và nằm trong khoá cache radar | 16 test mới (`BrandDnaTest`) · đo trên production: máy chủ **CÓ** internet (2/2 đích 200/204), model đang chạy `deepseek-flash` **KHÔNG** có tìm kiếm |

### 16.1 Số đo trước → sau của cả hành trình

| Chỉ số | Trước | Sau |
|---|---|---|
| Test tự động | 491 test / 2.657 assert | **701 test / 4.675 assert** (0 đỏ) — số đo tại 2026-09-22; bộ test vẫn tiếp tục lớn lên sau đó |
| Credit theo chu kỳ | **không bao giờ được cấp** | cấp idempotent (CAS) + lazy + cron |
| Chi phí ảnh/video theo gói | **cột trang trí** (9 chỗ đọc setting toàn cục) | `studio_credit_cost()` — 8 chỗ dùng theo gói |
| `resolution_cap` | **không được kiểm ở đâu** | hạ xuống cap + `notice` nói rõ lý do |
| Trang giá cho khách chưa đăng nhập | **không có** | `/bang-gia` render từ DB |
| Vòng đời duyệt ảnh | có backend, **không giao diện nào gọi** | duyệt theo lô + phím tắt + nhãn trạng thái |
| Một bộ 8 SKU × 2 bối cảnh | 16 lần sửa prompt + 16 lần bấm | **1 lần dán + 1 lần bấm** |
| Viền nút | 30 biến thể | **16 token** · CSS −1,64 kB |
| Tùy chỉnh người dùng | `localStorage` (mất khi đổi máy) | theo tài khoản (`user_catalogs`) + di trú một lần |
| "Lưu layer" | 85 ms · 0,9 MB · **không ghi CSDL** | 22 ms · **0 byte** · dòng CSDL thật |
| **Tương phản chữ ghi chú phụ** | `text-cream-300/40` ≈ **2,9 : 1** (dưới WCAG AA) | bậc thấp nhất **6,7 : 1** (Tối) · **6,9 : 1** (Sáng) |
| **Giao diện** | chỉ có Tối, không cài đặt được | Sáng · Tối · Theo hệ điều hành — lưu theo tài khoản, áp trước khi vẽ (không nháy màu) |
| CSS gửi cho khách | 164,61 kB | **155,33 kB** (−9,3 kB: bỏ hàng chục tiện ích opacity/sắc độ chỉ dùng một lần) |
| Deploy lên production | — | 11 vòng, mỗi vòng `npm run build` thoát 0 + asset trong `public_html/build` |

---

## 17. Phụ lục — quyết định đã chốt & việc còn nợ

### 17.1 Bốn câu hỏi của chủ dự án — ĐÃ CHỐT

| # | Việc | Quyết định |
|---|---|---|
| Q1 | Chặn khi hết credit? | **ĐÃ QUYẾT: BẬT** (2026-09-19). Mặc định BẬT (`config/studio.php` + `studio_plan_limits()`); tắt bằng setting `studio_enforce_credits=0`. 402 có cấu trúc + tự mở bảng nâng cấp; Super Admin được miễn chặn |
| Q2 | Cổng thanh toán (VNPay/MoMo/chuyển khoản) | **CHƯA CÓ.** Nút "Nâng cấp" phải nói thật là "kích hoạt, thanh toán sau" (§13.1 luật 4) |
| Q3 | Gói theo mùa vụ/xưởng | **ĐÃ LÀM: bán theo VỤ.** "Xưởng theo vụ" 3.290.000 ₫/vụ (3 tháng) · 3.000 credit cấp MỘT LẦN · `plans.unit_months · cycle_months · units` |
| Q4 | Số ghế theo gói | **ĐÃ LÀM: nhóm làm việc theo ghế.** Miễn phí 1 · Khởi nghiệp 1 · Chuyên nghiệp 3 · Studio 10 · Xưởng 5. Chủ nhóm mời bằng email; cả nhóm dùng chung gói + credit + bộ sưu tập |

### 17.2 Việc còn nợ (không chặn khách)

> **Đã dọn trong đợt 2026-09-23** (xem §16 vòng 23–24): mã chết "Storefront Vue SPA" · **nợ emoji (134 → 0)** ·
> **nền nút** (ba kiểu → một token) · **trang xem token** (`/he-thong-thiet-ke`) · file thăm dò lạ trên production ·
> 6 test đỏ sẵn có ở HEAD · **3 card hết màu nhấn riêng** (emerald 26 → 0, bỏ luôn danh sách miễn trừ trong test) ·
> **12 nút chính bị khoá nay có dòng lý do** `↳` · hết cảnh **2 nút chính cùng lúc** (màn hình canvas trống · tab Hàng loạt).
> Còn nợ duy nhất thuộc nhóm này: các nút bị khoá vì **ĐANG CHẠY** thì cố ý KHÔNG thêm dòng lý do (nhãn nút đã đổi thành "Đang gửi…").

> **Đã dọn tiếp trong đợt 2026-09-23 (Đợt 20)** — xem §16 vòng 27: card dùng CHUNG một bề mặt ·
> **cỡ chữ toàn cục có cài đặt** (90/100/115/130%) · chọn **ảnh nguồn ngay trong card «Gợi ý từ ảnh»** ·
> **mã tra cứu lỗi `L-XXXX`** ghi ở cả giao diện và log · **tên file ảnh theo kênh bán** ·
> **khách bấm "Duyệt" thì bộ sưu tập tự chuyển trạng thái** · **báo cáo chi phí theo nhóm** (`/bao-cao-nhom`) ·
> **bề mặt giao diện dùng `border-white/*` đã đổi hết sang token** (48 → 11 chỗ, 11 chỗ còn lại đều vẽ TRÊN ẢNH).

- [x] `border-white/*` — 11 chỗ còn lại là viền vẽ TRÊN ẢNH (tay cầm crop · con trỏ cọ · vòng xoay trên nút
      màu) — cố ý cố định theo §1.1 quy tắc 5. Mọi chỗ dùng cho BỀ MẶT giao diện đã đổi sang `border-ink-600`.
- [x] Card «Gợi ý từ ảnh» **đã cho chọn ảnh nguồn ngay trong card** (`SourceLibraryPicker`).
- [x] **Mã tra cứu lỗi** `L-XXXX` **đã có** ở cả giao diện (`(mã tra cứu: L-…)`) và log (`studio_fail[L-…]`).
- [x] Preset **tên file ảnh theo kênh bán** (`export_channels()` + ô chọn trong hộp xuất gói + `manifest.channel`).
- [x] Khách bấm "Duyệt" trên trang chia sẻ ⇒ bộ sưu tập **tự chuyển trạng thái** (qua `ProjectWorkflowService`,
      vẫn ghi lý do vào log khi bị từ chối).
- [ ] Cron `studio:grant-plan-credits` trên hPanel — **máy chủ KHÔNG có lệnh `crontab`** nên phải bấm tay
      trong hPanel (lệnh chính xác ghi ở DEPLOY_LOG Đợt 20 §4; đường lazy đã chạy nên chưa gấp).
- [x] Báo cáo chi phí/tiến độ **theo nhóm** cho chủ doanh nghiệp (`/bao-cao-nhom`).
- [x] `error_code` cho lỗi phát sinh CHỈ ở trình duyệt — client sinh mã cùng định dạng, gửi về
      `POST /api/client-errors` để máy chủ ghi `client_error[L-XXXX]`; xem §6.5.
- [ ] Card «Gợi ý từ ảnh» chưa cho **chọn ảnh nguồn ngay trong card** (`SourceLibraryPicker` đã có sẵn).
- [ ] **Mã tra cứu lỗi** cho người dùng đọc cho tổng đài (`L-8F3K`) — ghi ở cả giao diện và log (§6.5).
- [ ] Preset **tên file ảnh theo kênh bán** (sàn TMĐT/catalogue).
- [ ] Tự động chuyển trạng thái bộ sưu tập khi khách bấm "Duyệt" — hiện **CỐ Ý** chỉ ghi phản hồi
      (chuyển trạng thái là quyết định của chủ, có whitelist riêng).
- [ ] Cron `studio:grant-plan-credits` trên hPanel (đường lazy đã chạy nên chưa gấp).
- [ ] Báo cáo chi phí/tiến độ **theo nhóm** cho chủ doanh nghiệp.

### 17.3 Bộ test đang giữ các luật trong tài liệu này

| Test | Giữ luật |
|---|---|
| `tests/Feature/DesignSystemTest.php` | §1–§5 (tài liệu tồn tại & không nói sai · component nêu tên phải có thật · số icon khớp `icons.json` · không tự chế tiến trình · không emoji · không bảng màu riêng · 1 nút chính + lý do khoá · **từ vựng viền**) |
| `tests/Feature/ThemeSystemTest.php` | §1.1 + §1.4 (hai theme cùng bộ token · **tương phản WCAG AA** của mọi bậc nội dung/màu trạng thái ở cả hai theme · không còn chữ dùng opacity · không còn sắc độ trạng thái thô · nền canvas cố định · mọi blade render `data-theme` + có script theme · whitelist + lưu theo tài khoản của `PUT /api/theme` · theme không bị công tắc gói chặn) |
| `tests/Feature/UserFacingMessagesTest.php` | §6 (không rò rỉ chi tiết kỹ thuật ở nhãn tiến trình, luồng stream, cửa chặn ở biên, state lỗi) |
| `tests/Feature/SuggestStreamTest.php` | §6 (cấm nêu tên model/provider trong nhãn tiến trình & lỗi) |
| `tests/Feature/ToolbarAreaTest.php` | §7 (vùng toolbar cao cố định + cuộn trục X) |
| `tests/Feature/ClientErrorReportTest.php` | §6.5 (mã tra cứu của lỗi trình duyệt: log · định dạng mã · gộp trùng · throttle · bảng chữ JS = PHP · không chỗ nào còn đẩy `e.message` thô vào toast lỗi hay ném bỏ `error_code` của máy chủ) |
| `tests/Feature/ModuleRegistryTest.php` | §14 luật 33 (mọi route studio phải thuộc một module của gói) |
| `tests/Feature/UserCatalogTest.php` | §15.1 (4 URL cài đặt cũ vẫn trả 200) |

---

## 18. Agent Studio — DNA thương hiệu & khả năng truy cập internet

> Đợt 22 (2026-09-23). Hai câu hỏi người dùng đặt ra: *"agent có truy cập internet không?"* và
> *"quản lý/sửa DNA ở đâu?"*. Trước đó cả hai đều được trả lời bằng **văn bản tĩnh**, trong khi sự thật
> nằm ở hai chỗ khác hẳn nhau.

### 18.1 DNA thương hiệu — hai nguồn, KHÔNG được trộn

| Nguồn | Ở đâu | Độ tin cậy |
|---|---|---|
| **Chủ shop tự khai** | bảng `brand_dna` (1 hàng/tài khoản) · `BrandDnaService` | cao nhất — sửa được, có ngày cập nhật |
| Số bán thật của shop | `shop_signals` (nhập tay/dán Excel) | cao, nhưng chỉ là số liệu |
| Suy ra từ mô tả ảnh đã tạo | dò từ khoá trong `generations.prompt` | thấp (đoán mò) |
| Mặc định của hệ thống | hằng số trong `DesignAgentService` | không phải dữ liệu của shop |

Bốn quy tắc:

1. **Thứ tự ưu tiên `owner` → `shop_data` → `derived` → `default`**, và agent trả về khoá
   `brand_dna.source` + `source_label` để giao diện nói RÕ đang dùng bản nào (badge "Do bạn khai" /
   "Suy ra từ mô tả ảnh đã tạo"). Gộp nhãn sẽ khiến người dùng tin nhầm rằng hệ thống đã hiểu shop họ.
2. **Câu tóm tắt DNA CHỈ nói điều chủ shop đã khai** (`BrandDnaService::summary()` trả `''` khi chưa
   khai) — nơi gọi tự quyết định dùng phần suy ra, KHÔNG trộn nửa thật nửa đoán vào một câu.
3. **Chưa khai KHÔNG chặn đường**: bước DNA luôn bỏ qua được; hệ thống chỉ nhắc một lần.
4. **Mọi trường có trần** (`TEXT_FIELDS` · `LIST_FIELDS`) và trần đó là MỘT nguồn dùng chung cho
   validate ở controller, chuẩn hoá ở service và giao diện — dữ liệu này đi thẳng vào prompt.

DNA đi vào **đường brief** (không cache, mỗi tài khoản một lần chạy). **KHÔNG** đi vào đường radar:
radar dùng cache CHUNG giữa các tài khoản (chỉ gửi danh mục xu hướng theo vùng) — nhét dữ liệu riêng
của người dùng vào đó là rò rỉ chéo tài khoản.

### 18.2 Khả năng truy cập internet — ĐO, không hứa

Hai tầng phải tách bạch vì chúng có thể lệch nhau:

| Tầng | Ai quyết định | Cách đo |
|---|---|---|
| **Máy chủ** | nhà hosting | HEAD thật tới `config('studio.web_probe_targets')`, ghi mã HTTP + độ trễ (`WebAccessService`) |
| **Model** | CÀI ĐẶT (Model Registry · Nhóm công việc · Luồng ưu tiên · Custom Providers) | `WebAccessService::planFor($candidate)`: nhà cung cấp **tự khai** `studio_providers.search_param` trước, sau đó tới **giao thức** (`SEARCH_DIALECTS`: qwen/dashscope → `enable_search` · gemini → `google_search`). Giao thức lạ ⇒ `null` = KHÔNG hứa |

- Giao diện **không** được viết "đang ở chế độ demo" như một câu văn tĩnh: khối "Khả năng truy cập
  internet" trong Agent Studio đọc số ĐO (kèm nút *Kiểm tra lại* → `?force=1`), và câu kết luận có ba
  biến thể đúng với thực tế: `no_internet` · `internet_no_search` · `internet_and_search`.
- Cờ `search` chỉ được bật khi transport của candidate ĐẦU TIÊN thật sự hỗ trợ, và **nằm trong khoá cache**
  của radar (nội dung trả lời khác nhau ⇒ không dùng chung cache).
- Khi **không** có tìm kiếm, prompt giữ luật cũ: cấm nói như thể đã đọc Shopee/TikTok/POS/ERP. Khi **có**
  tìm kiếm, luật đổi thành: chỉ được dẫn nguồn mà kết quả tìm kiếm thật sự trả về.
- Nguồn ngoài vẫn là **dữ liệu mẫu** (`sources_mode=demo`) cho tới khi có connector thật — câu đó hiển
  thị ngay dưới số đo để không ai đọc "máy chủ có internet" thành "dữ liệu thị trường là thật".

Bốn câu kết luận (đừng gộp — mỗi câu là một việc cần làm KHÁC nhau):

| verdict | Khi nào | Người dùng cần làm gì |
|---|---|---|
| `no_internet` | máy chủ không ra được internet | báo quản trị hosting |
| `no_model_configured` | chưa có model **dùng được** cho nhóm suy luận (thiếu key · chưa gán model) | vào Cài đặt thêm key/model — agent đang chạy bằng bộ quy tắc có sẵn |
| `internet_no_search` | có model nhưng model đó không có tìm kiếm web | muốn nguồn thật thì chọn model/nhà cung cấp có tìm kiếm, hoặc khai `search_param` cho Custom Provider |
| `internet_and_search` | có model VÀ có tìm kiếm | không cần làm gì |

Kết quả đo còn có khối `task_groups` cho 5 nhóm công việc (`prompt` · `vision` · `image` · `edit` · `video`),
đọc thẳng từ Cài đặt và tách **HAI** chuyện rất khác nhau:

| Trường | Nghĩa | Ví dụ thật trên production |
|---|---|---|
| `configured` · `candidates` | đã gán model cho nhóm trong Cài đặt | nhóm `image`: **3 model đã gán** |
| `usable` · `usable_models` | có candidate **chạy được** (key đang bật) | nhóm `image`: **0 dùng được** |
| `needs_key` | đã gán nhưng thiếu key | `image` ⇒ true |

Gộp hai thứ này là nói sai với người dùng: "đã gán model" khiến họ tưởng đã xong, còn "0 dùng được" mà
không nói vì sao thì khiến họ tưởng hệ thống hỏng. Giao diện hiển thị đúng ba trạng thái: *chưa gán model* ·
*đã gán nhưng chưa có key dùng được (chờ cài đặt key)* · *đang chạy*.

**Không viết cứng tên nhà cung cấp ở bất kỳ đâu quyết định hành vi**: mã nguồn chỉ biết *giao thức*;
việc chọn provider/model là của Cài đặt. Câu kết luận cũng không nêu tên nhà cung cấp nào (test khoá).

Kiểm tra nhanh trên máy chủ: `php artisan studio:web-access --force`.

### 18.3 Bộ test giữ hai luật này

`tests/Feature/BrandDnaTest.php` (16 test): hồ sơ tách theo tài khoản · validate + chuẩn hoá · xoá DNA
không xoá dự án · DNA thắng phần suy ra và **có mặt trong payload gửi model** · đo internet có/không có
mạng · model không tìm kiếm phải nói thẳng là không · cache + `force` đo lại.
