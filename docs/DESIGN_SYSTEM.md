# FabrikAI Studio — HƯỚNG DẪN PHONG CÁCH THIẾT KẾ CHUNG

> Áp dụng cho **mọi card/panel/popup trong /studio** (và các trang Cài đặt/Quản trị dùng chung
> bảng màu). Storefront có bộ class riêng ở nửa dưới `app.css` — không trộn hai bộ vào nhau.
>
> Đọc file này TRƯỚC khi thêm một card mới hoặc "làm đẹp" một card cũ. Mục tiêu không phải là
> "trông giống nhau" cho vui, mà để: (1) người dùng học MỘT lần rồi dùng được mọi card,
> (2) sửa một chỗ là toàn app đổi theo, (3) không có hai cách làm cho cùng một việc.

---

## 1. Một nguồn chân lý — không tự nghĩ ra màu/con số mới

### 1.1 Màu — `@theme` trong `resources/css/app.css`

| Nhóm | Token | Dùng cho |
|---|---|---|
| Thương hiệu | `brand-50 … brand-950` (xanh lá) | nút chính · trạng thái đang chọn · nhấn mạnh |
| Nền sáng | `cream-50 … cream-400` | chữ trên nền tối · viền sáng · nền storefront |
| Nền tối (studio) | `ink-500 · 600 · 700 · 800 · 900 · 950` | nền panel/card · viền · chữ mờ |
| Điểm nhấn phụ | `clay-500/600` · `gold-400/500` | cảnh báo mềm (layer khoá) · nhãn phụ |

Quy tắc:

- **Không viết mã màu mới trong `<style scoped>`.** Dùng tiện ích Tailwind (`bg-ink-800`,
  `border-ink-700`, `text-cream-200`, `bg-brand-600/30`…) hoặc `var(--color-…)`.
- **Ngoại lệ duy nhất:** lớp nền gradient nhận diện của MỘT card — đúng **một dòng**, alpha thấp
  (≤ 0.15), và phải lấy từ màu trong bảng trên. Ví dụ đang dùng:
  `background: linear-gradient(160deg, rgba(85,155,120,.13), transparent 62%)` (card Gợi ý từ ảnh).
- Độ mờ của chữ nói lên tầm quan trọng: `text-cream-100` (chính) → `text-cream-200` →
  `text-cream-300/70` → `/45` (ghi chú phụ). **Không xuống dưới `/45`** — đã từng có đợt phải
  nâng hàng loạt mức `/40–/60` lên `/75–/85` vì không đạt WCAG AA.

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

### 1.3 Chữ

- Tiêu đề card: `font-display` (Fraunces) + `text-base font-semibold text-brand-300` + icon 16px.
- Thang cỡ chữ trong chrome studio: **9px** (nhãn rất phụ) · **10px** (nhãn, ghi chú) ·
  **11px** (chữ thường trong card) · **13–14px** (nội dung cần đọc kỹ, ô nhập). **Không nhỏ hơn 9px.**
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
| Nền canvas | `.canvas-bg-grid/dark/white/cream` | |
| Thanh cuộn ẩn | `.scrollbar-hide` | |

---

## 3. Component dùng chung — không vẽ lại thứ đã có

| Component | Thay cho |
|---|---|
| `StudioIcon.vue` (`icons.json` — **129 icon**, cũng là nguồn cho PHP `App\Support\IconRegistry`) | mọi `<svg>` chép tay, mọi emoji |
| `LoadingSpinner.vue` (`text · subtext · progress`) | **mọi bộ tiến trình tự chế** (chấm · thanh · phần trăm) |
| `ConfirmDialog.vue` | popup xác nhận tự viết (đã từng có 3 bản khác nhau) |
| `BaseModal.vue` | khung modal tự viết |
| `SourceLibraryPicker.vue` | tự làm danh sách chọn ảnh |
| `CompareSlider.vue` | tự làm trượt so sánh trước/sau |
| `DockResizer.vue` | tự làm vách ngăn kéo |

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
   Không bao giờ để người dùng đoán vì sao nút mờ.
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
| Ô nhập | `border-ink-700` → `focus:border-brand-400` | `.studio-dark .input` |
| Bảng "tông chú ý" (badge) | `...-500/40` cho cả 4 tông | danger · warn · info · ok |

**Ba ngoại lệ DUY NHẤT, phải kèm lý do trong mã:**

1. **Checkbox chọn ảnh** đặt TRÊN ảnh: `border-cream-300/50` → `hover:border-cream-200`
   (viền xám tan biến trên nền ảnh bất kỳ).
2. **Màu nhấn riêng của card** (hiện có: `RefImageCard.vue` · `ConceptCard.vue` · `InpaintCard.vue`
   dùng `emerald-400`): phải dùng **nhất quán cả viền + nền + icon** trong đúng card đó, và
   **KHÔNG BAO GIỜ** dùng cho nút hành động chung (Chạy · Lưu · Xoá · Tạo ảnh).
3. **Nút kiểu `.btn-outline` trên nền tối**: hover `border-cream-300` (đảo sáng), đúng như
   `.studio-dark .btn-outline` trong `app.css`.

### 5.2 Vì sao phải là từ vựng ĐÓNG

`tests/Feature/DesignSystemTest.php` quét **mọi** `<button|a|label>` trong `resources/js/studio` và
ĐỎ nếu gặp token ngoài bảng trên. Nhờ vậy "hai nút cạnh nhau lệch màu viền" trở thành lỗi bắt được
bằng máy, không phải thứ chỉ lộ ra khi có người ngồi nhìn. Muốn thêm token: sửa bảng này + danh sách
trong test **trong cùng một commit** — đó là chủ ý, không phải tai nạn.

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

---

## 7. Bố cục & cuộn

- **Vùng dùng chung mà cao cố định ⇒ MỌI biến thể phải theo CÙNG một khuôn.** Ví dụ vùng toolbar
  phía trên canvas: rail cao cố định `h-12`, mỗi thanh ngữ cảnh dùng chung hằng số `bar`
  (`h-9 w-max shrink-0 flex-nowrap`) — thêm một biến thể mà quên khuôn là **test ĐỎ**
  (`tests/Feature/ToolbarAreaTest.php`).
- **Khung cuộn NGANG: lớp trong `w-max` + `mx-auto`.**
  **Không** dùng `justify-center` trên chính khung cuộn — nó đẩy mép TRÁI ra ngoài vùng cuộn và
  người dùng không bao giờ kéo tới được phần đầu.
- Dock: bề rộng do JS đặt qua inline style, CSS lo chuyển động + trạng thái thu gọn.
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

---

## 9. Icon & emoji

- Icon **chỉ** lấy từ `resources/js/studio/icons.json` qua `StudioIcon` — cùng nguồn với PHP
  (`App\Support\IconRegistry`) nên thêm icon là thêm một khoá JSON, không phải sửa hai nơi.
- **Không dùng emoji trong chrome giao diện.** Ba lý do thật: (a) mỗi hệ điều hành vẽ một kiểu nên
  bố cục lệch nhau; (b) emoji không theo bảng màu nên phá vỡ tông của card; (c) trình đọc màn hình
  đọc tên emoji thành tiếng, chen vào giữa nhãn.
  Emoji chỉ còn chấp nhận trong **nội dung do người dùng/AI sinh ra**.
- **Nợ hiện có (đo 2026-09-22): 23/65 file còn emoji · 134 lần xuất hiện** — nhiều nhất là
  `ConceptCard.vue` (43), `store.js` (18, phần lớn là chuỗi toast), `AdminApp.vue` (7).
  Không cần dọn một đợt riêng: **sửa tới file nào thì dọn file đó**, và không thêm emoji mới.

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
- [ ] Đo lại bằng Chrome thật ở bề ngang hẹp nhất (260–300px) — không tràn ngang.
- [ ] `npm run build` rồi commit asset (máy chủ không có node).

**Test khoá bất biến:** `tests/Feature/DesignSystemTest.php` — (a) card Gợi ý từ ảnh: không tự chế
tiến trình, không emoji, không mã màu cứng, có nút chính + lý do khoá; (b) **từ vựng viền nút của
toàn studio** (§5.2); (c) tài liệu phải tồn tại và không được nói sai. Và
`tests/Feature/ToolbarAreaTest.php` (vùng toolbar cao cố định + cuộn trục X).
