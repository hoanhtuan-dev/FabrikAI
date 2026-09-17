# FabrikAI · Chiến lược giá & gói cước (thị trường Việt Nam)

> Tài liệu đi kèm tính năng "gói cước + trang Quản trị cho Owner".
> Cập nhật: 2026-09-18. Nguồn giá model: QwenCloud (qwencloud.com/models) + bảng giá
> Model Studio của Alibaba Cloud + giá tham chiếu fal.ai (cập nhật 2026).

---

## 1. Tóm tắt nghiên cứu model (qwencloud.com/models)

FabrikAI hiện đã cấu hình sẵn hệ provider Qwen (DashScope/QwenCloud) cho cả ảnh, sửa ảnh,
video và suy luận prompt. Các model trên QwenCloud dùng được cho nhu cầu "ảnh sản phẩm thời trang":

| Nhóm | Model hiện có trong app | Vai trò | Ghi chú |
|---|---|---|---|
| Sinh ảnh | qwen-image-3.0-pro / qwen-image / qwen-image-2512 | Tạo ảnh sản phẩm từ prompt/ảnh mẫu | Pro: chữ + bố cục dày đặc, 12 ngôn ngữ; base/2512: rẻ cho ảnh chuẩn |
| Sửa ảnh | qwen-image-edit / qwen-image-edit-2511 | Inpaint, thay nền, giữ nguyên đồ | Chỉnh sửa theo chỉ dẫn, là "xưởng" của FabrikAI |
| Video | wan3.0-video / wan2.5-t2v | Video catwalk | Tốn kém hơn ảnh ~10 lần |
| Suy luận | qwen3.8-flash / qwen3.8-max | Vision + prompt (Thuật sỹ ảo, Gợi ý từ ảnh) | Flash rất rẻ, multimodal |

### 1.1 Giá gốc (để tính biên)

Giá gốc là chi phí API FabrikAI trả cho provider, **chưa gồm** server/queue/lưu trữ/nhân sự.

**Ảnh (sinh + sửa):**
| Model | Đơn vị tính | Giá | ≈ VNĐ (24.000 ₫/USD) |
|---|---|---|---|
| Qwen Image Base / 2512 | mỗi megapixel | $0.02/MP | ~480 ₫/MP |
| Qwen Image Edit 2511 | mỗi megapixel | $0.03/MP | ~720 ₫/MP |
| Qwen Image Max | mỗi ảnh | $0.075 | ~1.800 ₫ |
| Qwen Image Layered (transparent) | mỗi ảnh | $0.05 | ~1.200 ₫ |

Ảnh 1:1 1024×1024 = 1.05 MP ⇒ base/2512 ≈ **500 ₫/ảnh**, edit ≈ **750 ₫/ảnh**, Max ≈ **1.800 ₫/ảnh**.

**Video:** Wan dùng đơn vị theo giây/độ phân giải, đắt hơn ảnh đáng kể. FabrikAI đang để
`video_credits = 10` (1 video = 10 credit) — khớp đúng tỷ lệ chi phí thực tế. Khi ra gói bán
video nên tính riêng biên (hoặc giới hạn video ở gói cao).

**Suy luận (prompt/vision):** qwen3.8-flash ≈ $0.15/1M token (~3,6 ₫/1K token) — chi phí prompt
gần như không đáng kể so với ảnh, không cần tính riêng.

### 1.2 Đối thủ (để định vị)

| Đối thủ | Giá | Hình thức |
|---|---|---|
| FLUX 2 Pro | $0.05/ảnh (~1.200 ₫) | Pay-per-use |
| Ideogram 3.0 | $0.08/ảnh | Pay-per-use |
| Seedream | $0.04/ảnh | Pay-per-use |
| Midjourney | $10–60/tháng | Subscription |
| Shopee AI Creation | **miễn phí** | Tích hợp sẵn trong sàn |

**Hệ quả định vị:** FabrikAI không thể cạnh tranh bằng "ảnh rẻ" với Shopee (miễn phí). Moat nằm ở
**workflow nhiều bước + tuân thủ kênh + tiếng Việt + giá VNĐ + quy trình duyệt** (đã chốt ở
STUDIO_REVIEW_PLAN.md §8). Giá bán phải neo theo **giá trị thay thế** (thuê nhiếp ảnh gia
50.000–500.000 ₫/ảnh sản phẩm, hoặc 1–3 triệu ₫/buổi chụp), không neo theo chi phí API.

---

## 2. Đơn vị kinh tế (unit economics)

- **1 credit = 1 ảnh 1K (base/2512)** hoặc **1/10 video**. (Khớp cấu hình `image_credits=1`,
  `video_credits=10`.)
- Chi phí biến đổi/credit ở mô hình base: **≈ 500 ₫** (ảnh 1:1 1K), edit **≈ 750 ₫**, Max **≈ 1.800 ₫**.
- Mục tiêu biên gộp (đã chốt ở PLAN §4): **> 70%** cho mô hình base, **> 40%** cho Max.

Để đạt biên > 70% ở base: giá bán hiệu dụng phải ≥ **1.700 ₫/credit**.
Để đạt biên > 40% ở Max: giá bán hiệu dụng phải ≥ **3.000 ₫/credit**.

---

## 3. Gói cước đề xuất (đã seed vào `plans`)

Nguyên tắc: **(1)** miễn phí để mở đường vào (giải bài toán onboarding/403); **(2)** giá "lẻ" đủ
cao để đẩy khách lên gói; **(3)** gói càng lớn biên tuyệt đối càng cao dù biên % thấp hơn chút.

| Gói | Giá/tháng | Credit/tháng | ₫/credit | Biên gộp (base 500₫) |
|---|---|---|---|---|
| **Miễn phí** | 0 ₫ | 100 dùng thử (1 lần) | — | — |
| **Khởi nghiệp** | 199.000 ₫ | 120 (+30 tặng) | ~1.658 ₫ | ~70% |
| **Chuyên nghiệp** ⭐ | 499.000 ₫ | 350 (+100 tặng) | ~1.426 ₫ | ~65% |
| **Studio** | 1.490.000 ₫ | 1.200 (+400 tặng) | ~1.242 ₫ | ~60% |

> ⭐ Chuyên nghiệp là gói "chủ lực" — nên hiển thị nổi bật khi làm trang giá công khai.

**Gói nạp thêm (top-up) đề xuất** — chưa seed, dùng tính năng "Điều chỉnh credit" của trang Quản trị:
| Gói nạp | Giá | ₫/credit |
|---|---|---|
| 100 credit | 129.000 ₫ | 1.290 ₫ |
| 500 credit | 499.000 ₫ | 998 ₫ |
| 1.200 credit | 990.000 ₫ | 825 ₫ |

### 3.1 Vì sao con số này

1. **Miễn phí 100 credit** — đủ để chủ shop tạo ~30–50 ảnh đầu, trải nghiệm hết luồng, tự kết luận
   "dùng được" (bài toán §A của PLAN: người tự đăng ký bị 403 im lặng nay đã mở).
2. **199k là ngưỡng tâm lý** của SMB Việt Nam (ngang một phần mềm POS/bán hàng), rẻ hơn thuê thợ
   chụp 1 buổi.
3. **499k cho "ra ảnh đều đặn mỗi ngày"** — 350 credit ≈ 350 ảnh/tháng ≈ 12 ảnh/ngày, đủ cho shop
   vài chục SKU. Đây là gói có giá trị/giá tốt nhất nên được đánh dấu khuyến nghị.
4. **1.490k cho studio/đội** — đã tính chỗ ngồi + API + xuất hàng loạt (mở sau), biên tuyệt đối lớn.

### 3.2 Quy tắc tính credit theo model (đề xuất áp khi đủ volume)

Để không "lỗ khi khách dùng Max", gán trọng số credit theo model:
- Ảnh base/2512 (1K) = 1 credit · ảnh base 2K = 2 credit · **Max = 3 credit** · Edit = 1 credit ·
  video = 10–30 credit tuỳ độ dài/độ phân giải.
Hiện `image_credits`/`video_credits` đã là setting toàn cục (trang Cài đặt); `plans` có cột
`image_credit_cost`/`video_credit_cost` để sau này phân hoá theo gói mà không cần sửa code.

---

## 4. Chiến lược giá (rollout)

| Giai đoạn | Việc | Mục tiêu |
|---|---|---|
| **Bây giờ** | Mở đăng ký tự do + gói Miễn phí (100 credit) | Đo Time-to-first-image < 5 phút |
| **Tuần 1–2** | Hiện giá công khai + nút "Nâng cấp" | Đo tỉ lệ free → trả phí (mục tiêu > 5%) |
| **Tuần 3+** | Bật thanh toán VNĐ (VNPay/MoMo/chuyển khoản) | Thu tiền thật, đo biên |
| **Sau khi có Export** | Tăng giá 15–20% (gói giá trị đã rời khỏi app) | Biên > 70% |

**Chỉ số cần đo (đồng bộ PLAN §4):** chi phí/ảnh · /look · /tháng, biên > 70%, tỉ lệ hoàn credit
bất thường = 0 (đã có sổ cái `credit_transactions` để đối soát).

---

## 5. Nguồn

- QwenCloud Models: <https://www.qwencloud.com/models>
- Alibaba Cloud Model Studio pricing: <https://www.alibabacloud.com/help/en/model-studio/model-pricing>
- Qwen Image pricing tham khảo: <https://qwenimage-2.com/blog/qwen-image-pricing>
