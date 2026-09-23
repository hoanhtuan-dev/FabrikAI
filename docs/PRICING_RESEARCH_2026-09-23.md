# Nghiên cứu giá: OpenArt AI · fal.ai · Qwen/DashScope

> Ngày truy cập dữ liệu: **2026-09-23 (UTC)**. Mọi con số đều kèm URL nguồn.
> Phương pháp: curl trực tiếp trang chính thức → bóc tách HTML/RSC payload nhúng sẵn
> (các trang Next.js vẫn chứa dữ liệu giá trong HTML server-render và trong bundle JS).
> Ô nào ghi "KHÔNG TÌM THẤY" nghĩa là đã thử nhiều nguồn và **không bịa số**.

---

# PHẦN A — OpenArt AI pricing

## A0. Nguồn gốc số liệu (quan trọng)

OpenArt **không công bố bảng giá credit theo model**. Con số trong tài liệu này lấy từ 3 nguồn:

1. **Trang giá chính thức** https://openart.ai/pricing (HTML server-render).
2. **Bundle JS chính thức của OpenArt** — file `/suite/_next/static/chunks/c00822d5.js` (chứa
   `SubscriptionLevelConfig`) và `37a51d58.js` (chứa `PRICES`, tên gói, FAQ). Đây là **hằng số
   trong code production của OpenArt**, tải công khai từ https://openart.ai/pricing.
3. **Help Center** https://openart.ai/suite/help-center (nội dung FAQ nằm trong chunk
   `090b0d7a.js`) và **Terms** https://openart.ai/suite/terms.

---

## A1. Các gói trả phí hiện tại

### A1.1 Gói cá nhân (tab "Individual plans")

Trang mặc định đang bật toggle **"Bill annually"** (aria-checked="true"), nên giá hiển thị là
**giá/tháng khi trả theo năm**, còn giá gạch ngang là **giá trả theo tháng**.

| Tên gói (hiển thị) | Tên nội bộ | Credit/tháng | Giá trả theo THÁNG | Giá trả theo NĂM (quy ra /tháng) | Tổng/năm |
|---|---|---|---|---|---|
| **Starter** | Essential | **4.000** | **$14** | **$13** (giảm 10%) | $156 |
| **Plus** | Advanced | **12.000** | **$34** | **$27** (giảm 20%) | $324 |
| **Pro** ⭐ *Most Popular* | Infinite | **24.000** | **$56** | **$44** (giảm 22%) | $528 |
| **Wonder** | Wonder | **106.000** | **$240** | **$175** (giảm 27%) | $2.100 |

Nguồn: https://openart.ai/pricing (giá render) + hằng số `creditsAmount` trong
`c00822d5.js` + bảng `PRICES` trong `37a51d58.js`.

`PRICES` trong bundle ghi: `Essential {monthly:14}, Advanced {monthly:34},
Infinite {monthly:56}, Wonder {monthly:240}` → khớp đúng giá gạch ngang trên trang.

⚠️ **Điểm chưa nhất quán cần nói rõ:** cùng bundle `37a51d58.js` còn có trường
`yearly` = **7 / 17 / 28 / 120** (đúng bằng 50% giá tháng). Con số này **KHÔNG khớp** với giá
đang hiển thị trên trang ($13/$27/$44/$175 = giảm 10/20/22/27%). Giá render trên trang khớp
chính xác với công thức `monthly × (1 − annualDiscount)` dùng các hằng số
`{Essential:10, Advanced:20, Infinite:22, Wonder:27}` trong chunk `225230`.
→ **Kết luận: dùng bộ giá render ($14/$34/$56/$240 tháng; $13/$27/$44/$175 khi trả năm).**
Trường `yearly` 7/17/28/120 nhiều khả năng là dữ liệu cũ/không còn dùng — chưa xác minh được.

### A1.2 Gói doanh nghiệp (tab "Business plans") — có slider chọn số credit

`BusinessTeam*` là **một gói duy nhất với slider**, giá thay đổi theo lượng credit:

| Gói | Credit/tháng | Giá/tháng | Giá/năm (quy ra /tháng) |
|---|---|---|---|
| Team (mỗi seat) | 12.000 / seat | $34,90 | $26,18 |
| **Business 50K** | 50.000 | $227 | $165,71 |
| **Business 100K** | 100.000 | $453 | $330,69 |
| **Business 150K** | 150.000 | $680 | $496,40 |
| **Business 250K** | 250.000 | $1.133 | $827,09 |
| **Business 500K** | 500.000 | $2.265 | $1.653,45 |
| **Business 750K** | 750.000 | $3.398 | $2.480,54 |
| **Business 1M** | 1.000.000 | $4.530 | $3.306,90 |
| Enterprise | 12.000+ | Liên hệ | Liên hệ |

Nguồn: bảng `PRICES` trong `37a51d58.js` + `SubscriptionLevelConfig` trong `c00822d5.js`.
Giảm giá năm: Business **27%**, Team **25%** (`BUSINESS_ANNUAL_DISCOUNT_PERCENT`,
`TEAM_ANNUAL_DISCOUNT_PERCENT`).

---

## A2. 1 credit đổi được gì? — Có tài liệu "credit cost per model" không?

### ❌ KHÔNG có bảng giá credit chính thức theo từng model.

OpenArt **không có trang tài liệu nào liệt kê credit cost cho từng model**. Bằng chứng:

- FAQ chính thức chỉ đưa **khoảng ước lượng**, không có bảng:
  > *"Credit costs vary by model. The interface shows the credit cost before you generate. As a
  > rough guide: **Image generation: 5–50 credits** depending on the model and resolution.
  > **Video generation: 100–800+ credits** depending on model, duration, and resolution."*
  — https://openart.ai/suite/help-center (mục Credit Usage)
- FAQ khác nói rõ giá credit **phụ thuộc giá nhà cung cấp model** và có thể đổi:
  > *"Credit costs for individual models are tied to the underlying costs from our AI model
  > providers, which occasionally adjust their pricing... the new cost will be shown in the
  > generation form before you confirm."*
- FAQ khẳng định: **"Is there API access? — No public API is available currently."**
- Terms §4.4 **cấm truy cập tự động**: *"No automated access, bots, or scripts."*
  → Không thể scrape/app-integrate để tự lấy bảng giá credit.
- Trang model https://openart.ai/ai-model/ **không** chứa credit cost trong HTML.
- Đã thử các endpoint API (`/api/credit/model-cost`, `/api/models/credit-cost`,
  `/api/credit-cost`, `/api/model/creditCost`, `/api/credits/model-costs`) → tất cả **404**.
  Credit cost được trả về **runtime** qua API nội bộ sau khi đăng nhập.

### ✅ Bảng tham khảo (NGUỒN THỨ CẤP — không phải OpenArt)

Từ blog của một nhà bán API (WentuoAI/APIYI), đăng 2026-01-25 — **chỉ để tham khảo, độ tin cậy thấp**:
https://blog.wentuo.ai/en/openart-nano-banana-pro-cost-reduction-api-en.html

| Loại model | Credit/ảnh | Tương quan |
|---|---|---|
| Model SD base | ~1 credit | 1x |
| Thao tác trong Editor | ~5 credits | 5x |
| Generate từ model đã train | ~20 credits | 20x |
| **Nano Banana Pro** | **60 credits** | 60x |

> Con số "~1 credit cho model base" khớp với claim chính thức của OpenArt trên trang giá:
> Starter "**Up to ~4.000 images**" cho 4.000 credits → **1 credit/ảnh** ở model rẻ nhất.
> Nhưng **bảng trên là nguồn thứ cấp, chưa được OpenArt xác nhận.**

### Đơn vị tính credit (chính thức, từ trang giá)

OpenArt niêm yết credit theo **"What Your Credits Can Create"**, quy đổi kèm:
- Starter: 4.000 credits ≈ **~4.000 ảnh** · ~50 video · ~13 nhân vật nhất quán · ~5 One-Click Stories · 8 generation song song
- Plus: 12.000 credits ≈ ~12.000 ảnh · ~150 video · ~40 nhân vật · ~17 stories · 16 song song
- Pro: 24.000 credits ≈ ~24.000 ảnh · ~300 video · ~80 nhân vật · ~34 stories · 32 song song

---

## A3. Gói miễn phí được gì?

| Hạng mục | Trả lời | Nguồn |
|---|---|---|
| Số credit | **40 credit, cấp MỘT LẦN khi đăng ký** (không phải mỗi ngày) | `c00822d5.js`: tier Free có `creditsAmount:40`, `creditField:"trial_credit_balance"`; FAQ: *"When you create an account, you'll automatically receive a set of free credits"* |
| Credit miễn phí hằng ngày | **KHÔNG TÌM THẤY** bất kỳ cơ chế credit/ngày nào. Đã grep toàn bộ 65 chunk JS với các khoá `daily_credit`, `dailyCredit`, `free_daily`, `DAILY_FREE` → 0 kết quả | — |
| Watermark | **CÓ.** Terms §4.5: *"Output generated on a free plan **may include a visible OpenArt watermark**. You may not remove, crop, obscure, hide, or alter the watermark..."* FAQ video: *"Free users cannot remove the watermark"* | https://openart.ai/suite/terms §4.5 |
| Giới hạn model | **Hầu như KHÔNG giới hạn theo gói.** FAQ: *"**Most models are available across all tiers** — the biggest difference between plans is how much you can generate per month, not which tools you can access"* | https://openart.ai/suite/help-center |
| Dùng thương mại | **KHÔNG.** Terms §4.1: *"For all subscription levels, you may freely use Output you generate for **non-commercial purposes**... For subscription levels at, and above, the **'Plus'** level..., you may also freely use Output you generate for **commercial purposes**"* | https://openart.ai/suite/terms §4.1 |
| Tính năng bị khoá | Free: `turbo:0`, `canUseSearch:false`, `canUseBrandKit:false`, `maxConcurrentSize:4` | `c00822d5.js` |
| Lưu trữ file | *"Never subscribed: Creations are stored for 7 days, then automatically deleted"* | https://openart.ai/suite/help-center |
| Unlimited Generation | Chỉ có ở gói **Pro** và **Wonder**, chỉ với một số model nhất định | FAQ + `37a51d58.js` |

> **Kết luận A3: 40 credit free (một lần), có watermark, không được dùng thương mại.**
> Muốn dùng thương mại phải từ gói **Plus ($34/tháng)** trở lên.

---

## A4. Có bán "credit pack" một lần (one-time top-up) không?

**Có credit pack, NHƯNG không bán rời — bắt buộc phải có subscription đang hoạt động.**

| Câu hỏi | Trả lời | Nguồn |
|---|---|---|
| Mua thêm credit không cần subscription? | ❌ **"No. Extra credits can only be purchased as an add-on to an active subscription."** | FAQ chính thức |
| Kích thước mỗi gói | **5.000 credit** (`EXTRA_CREDIT_CREDITS_PER_UNIT = 5000`) | `84de1dd7.js` (hằng số production) |
| **Giá mỗi gói** | **$15 / tháng** (`EXTRA_CREDIT_PRICE_PER_UNIT = 15`) | `84de1dd7.js` |
| Số gói tối đa | **40 gói** = 200.000 credit, $600/tháng (`MAX_LEVEL = 40`) | `84de1dd7.js` |
| Hình thức | **Thuê bao lặp lại hằng tháng**, tự động gia hạn | FAQ: *"add-on credit packs are **recurring monthly charges** — they automatically renew each billing cycle unless you cancel them"* |
| Khuyến mãi | Tháng đầu **giảm 50%** (`CREDIT_PACK_FIRST_MONTH_DISCOUNT_PERCENT = 50`), hết hạn 2026-05-16 (`CREDIT_PACK_50_OFF_END_MS`) | `37a51d58.js` |
| Điều kiện gói | Add-on chỉ mở từ gói **Plus trở lên** (`tierIsAdvancedOrAbove`); Free/Starter thấy nút *"Subscribe to Plus to Unlock"* | `84de1dd7.js` |
| Hủy add-on | Profile → Subscriptions → Add-ons; thay đổi có hiệu lực từ kỳ sau | FAQ |
| Mua rời thật sự (one-time)? | ⚠️ Bundle có sản phẩm tên `CreditPack` và hàm `hasLiveOneTimePack()` kiểm tra `one_time_pack.expires_at` → **có tồn tại cơ chế pack một lần**, nhưng **không có giá công khai** ở bất kỳ trang nào. **KHÔNG TÌM THẤY giá.** | `c00822d5.js`, `281133` |

**Quy đổi: $15 / 5.000 credit = $3,00 mỗi 1.000 credit = $0,003/credit** (đắt hơn giá mua theo gói).

---

## A5. Credit hết giữa tháng, rollover, huỷ gói

| Tình huống | Chính sách chính thức | Nguồn |
|---|---|---|
| **Hết credit giữa tháng** | *"Upgrade prompts may appear when your available credits have been fully used. To continue generating, you can either **upgrade your subscription or purchase additional credits**."* → hoặc nâng gói, hoặc mua add-on. Không có "credit âm". | FAQ |
| **Credit tháng có rollover?** | ❌ **KHÔNG.** *"Subscription credits do not carry over to the next month"* / *"Monthly credits reset each billing cycle and do not carry over."* | FAQ |
| **Add-on credit có rollover?** | ✅ **CÓ.** *"Unused add-on credits **roll over** to the next month (unlike monthly plan credits)."* | FAQ |
| Ví dụ rollover (từ FAQ) | Gói Pro 24.000 credit + 15 add-on (75.000) = 99.000. Dùng 10.000 → còn 89.000. Tháng sau: 24.000 base reset + 65.000 add-on rollover = **89.000**. | FAQ |
| **Ngày reset credit** | Theo **"billing anchor date"** (ngày ký gói), **không phải ngày 1**. Gói năm: reset hằng tháng vào đúng ngày kỷ niệm. | FAQ |
| **Chưa dùng hết mà hủy gói** | *"Your remaining credits stay available until the end of your current billing period, but **unused monthly credits do not roll over after cancellation**."* | FAQ |
| **Downgrade/hủy** | Hiệu lực từ kỳ sau. *"you'll move to the lower plan (or Free), and unused monthly credits will not carry over."* Add-on bị hủy kèm. | FAQ |
| **Thời điểm hủy an toàn** | *"To avoid being charged for the next cycle, cancel at least **5 days before** your renewal date."* | FAQ |
| **Hoàn tiền** | ❌ *"we're generally unable to issue refunds for completed generations or active subscription periods."* | FAQ |
| **Đổi năm → tháng** | Được, hoàn phần chưa dùng, **trừ phí xử lý tối thiểu 10%** | FAQ |
| **Nâng gói** | Nhận ngay credit + tính **pro-rated difference** | FAQ |
| **Generate lỗi có mất credit?** | *"A generation in progress has reserved credits — they'll be returned if it fails"* | FAQ |
| **Dữ liệu sau khi hết gói** | Có 14 ngày để tải về, sau đó xóa | FAQ |

---

## A6. Tỉ lệ quy đổi USD → credit, và giá vốn/giá bán mỗi ảnh

### A6.1 Bảng $/1.000 credit (tính từ dữ liệu A1)

| Gói | Credit/tháng | Giá tháng | **$/1.000 credit (tháng)** | Giá năm (q/tháng) | **$/1.000 credit (năm)** |
|---|---|---|---|---|---|
| Starter | 4.000 | $14 | **$3,500** | $13 | **$3,250** |
| Plus | 12.000 | $34 | **$2,833** | $27 | **$2,250** |
| Pro | 24.000 | $56 | **$2,333** | $44 | **$1,833** |
| **Wonder** | 106.000 | $240 | **$2,264** | $175 | **$1,651** ← rẻ nhất |
| Add-on | 5.000 | $15 | **$3,000** | — | — |
| Business 50K | 50.000 | $227 | **$4,540** | $165,71 | **$3,314** |
| Business 100K | 100.000 | $453 | **$4,530** | $330,69 | **$3,307** |
| Business 1M | 1.000.000 | $4.530 | **$4,530** | $3.306,90 | **$3,307** |

*(Bảng này là **tính toán của tôi** từ các con số A1, không phải số OpenArt công bố.)*

→ **1 USD ≈ 220–440 credit** tuỳ gói (rẻ nhất: Wonder trả năm ≈ **606 credit/USD**;
đắt nhất: Business trả tháng ≈ **220 credit/USD**).

### A6.2 Giá vốn / giá bán mỗi ảnh (tính toán)

Dùng mốc chính thức "1 credit ≈ 1 ảnh ở model rẻ nhất" và khoảng chính thức 5–50 credit/ảnh:

| Loại | Số credit | Chi phí nếu mua gói **Starter trả tháng** ($3,50/1.000) | Chi phí nếu mua **Wonder trả năm** ($1,651/1.000) |
|---|---|---|---|
| Ảnh model rẻ nhất | 1 | **$0,0035** | **$0,00165** |
| Ảnh rẻ (đáy khoảng 5 credit) | 5 | **$0,0175** | **$0,0083** |
| Ảnh đắt (đỉnh khoảng 50 credit) | 50 | **$0,175** | **$0,0826** |
| Nano Banana Pro *(nguồn thứ cấp: 60 credit)* | 60 | **$0,21** | **$0,099** |
| Video ngắn (100 credit) | 100 | **$0,35** | **$0,165** |
| Video dài (800 credit) | 800 | **$2,80** | **$1,32** |

**Nhận xét cho FabrikAI:**
- OpenArt bán lẻ ở mức **~$1,65–3,50 / 1.000 credit**. Trong khi **giá vốn fal.ai** cho các model
  rẻ (flux/schnell $0,003/MP, flux/dev $0,025/MP, qwen-image-edit $0,03/MP) thấp hơn rất nhiều.
  → OpenArt **markup rất dày ở model rẻ**, mỏng hơn ở model đắt.
- Mô hình của OpenArt là **subscription + credit phải dùng trong tháng** (không rollover) →
  đây chính là chỗ họ kiếm lãi: khách trả trước, credit hết hạn.
- FabrikAI hiện đang seed gói **1.242–1.658 ₫/credit** (PRICING.md §3), tức **~$0,052–0,069/credit**.
  So với OpenArt ($0,00165–0,0035/credit), **giá FabrikAI/credit đang đắt gấp ~15–40 lần** — nhưng
  khác đơn vị: 1 credit FabrikAI = 1 ảnh thật, còn 1 credit OpenArt có thể chỉ là 1/50 ảnh.
  **Cần so theo "giá mỗi ẢNH", không so theo credit.**
- ⚠️ **OpenArt KHÔNG dùng làm backend được**: không có public API và Terms §4.4 cấm
  automated access/bots/scripts. Chỉ dùng làm **tham chiếu định giá**.

---

# PHẦN B — Giá fal.ai cho model edit / inpaint

> Phương pháp: mỗi trang model fal.ai nhúng sẵn JSON máy đọc được
> `"endpointBilling":{"endpoint":...,"billing_unit":...,"price":...}` **và** câu giá hiển thị.
> Hai nguồn này khớp nhau ở mọi model dưới đây. Đã kiểm chứng độc lập 2 lần (tôi + 1 subagent).

## B1. Bảng giá

| Model (endpoint) | Tính theo | Giá USD | Ghi chú | Nguồn |
|---|---|---|---|---|
| `fal-ai/flux-pro/v1/fill` *(inpaint)* | **per megapixel** | **$0,05/MP** | Làm tròn LÊN MP gần nhất. 1MP = $0,05 | https://fal.ai/models/fal-ai/flux-pro/v1/fill |
| `fal-ai/flux/schnell` | per megapixel | **$0,003/MP** | Rẻ nhất họ FLUX | https://fal.ai/models/fal-ai/flux/schnell |
| `fal-ai/flux/dev` | per megapixel | **$0,025/MP** | | https://fal.ai/models/fal-ai/flux/dev |
| `fal-ai/qwen-image-edit` | per megapixel | **$0,03/MP** | | https://fal.ai/models/fal-ai/qwen-image-edit |
| `fal-ai/qwen-image-edit-plus` | per megapixel | **$0,03/MP** | | https://fal.ai/models/fal-ai/qwen-image-edit-plus |
| `fal-ai/nano-banana/edit` | **per image** | **$0,0398/ảnh** | | https://fal.ai/models/fal-ai/nano-banana/edit |
| `fal-ai/nano-banana-pro/edit` | per image | **$0,15/ảnh** | **4K tính GẤP ĐÔI = $0,30**; dùng web search **+$0,015** | https://fal.ai/models/fal-ai/nano-banana-pro/edit |
| `fal-ai/nano-banana-pro` | per image | **$0,15/ảnh** | Cùng chính sách | https://fal.ai/models/fal-ai/nano-banana-pro |
| `fal-ai/ideogram/v3` | per image | **$0,03 TURBO · $0,06 BALANCED · $0,09 QUALITY** | | https://fal.ai/models/fal-ai/ideogram/v3 |
| `ideogram/v4` | per MP | $0,0075 / $0,015 / $0,025 (TURBO/BALANCED/QUALITY) | 2048² = $0,03/$0,06/$0,10. *(Lấy từ text giá, trang không nhúng `endpointBilling`)* | https://fal.ai/models/ideogram/v4 |
| `fal-ai/topaz/upscale/image` | theo dải MP đầu ra | **$0,08 ≤24MP · $0,16 ≤48MP · $0,32 ≤96MP · tối đa $1,36 @512MP** | ⚠️ `endpointBilling` ghi `megapixels, 0.01` — **KHÔNG khớp** với bảng giá hiển thị | https://fal.ai/models/fal-ai/topaz/upscale/image |
| `fal-ai/clarity-upscaler` | per megapixel | **$0,03/MP** | | https://fal.ai/models/fal-ai/clarity-upscaler |
| `fal-ai/esrgan` | **per compute-second** | **$0,00111/compute-giây** | GPU A6000. `endpointBilling` ghi price=0 (endpoint pending) | https://fal.ai/models/fal-ai/esrgan |
| `fal-ai/kling-video/v2.5-turbo/pro/text-to-video` | **per second** | **$0,07/giây** → video 5s = **$0,35** | | https://fal.ai/models/fal-ai/kling-video/v2.5-turbo/pro/text-to-video |
| `fal-ai/veo3` | per second | **$0,40/giây** (có audio) · **$0,20/giây** (tắt audio) | 5s có audio = **$2,00** | https://fal.ai/models/fal-ai/veo3 |
| `fal-ai/minimax/hailuo-02/standard/text-to-video` | per second | **$0,045/giây** → 6s = **$0,27** | 512P rẻ hơn ~$0,017/s | https://fal.ai/models/fal-ai/minimax/hailuo-02/standard/text-to-video |

### Model trong đề bài KHÔNG tồn tại (HTTP 404 đã kiểm tra)

| Tên trong đề bài | Kết quả | Tên đúng |
|---|---|---|
| `fal-ai/flux-1.1-schnell` | ❌ 404 | Dùng `fal-ai/flux/schnell` hoặc `fal-ai/flux-1/schnell` (đều $0,003/MP) |
| `fal-ai/flux-2/flex` | ❌ 404 | Đúng là `fal-ai/flux-2-flex` |
| `fal-ai/flux-2/pro` | ❌ 404 | Gần nhất: `fal-ai/flux-2-max`, `fal-ai/flux-2-pro/outpaint` |

### Bonus — FLUX.2 (mới, rẻ hơn cho edit)

| Endpoint | Đơn vị | Giá | Nguồn |
|---|---|---|---|
| `fal-ai/flux-2/flash` | per MP (in+out) | $0,005/MP | https://fal.ai/models/fal-ai/flux-2/flash |
| `fal-ai/flux-2/turbo` | per MP | $0,008/MP | https://fal.ai/models/fal-ai/flux-2/turbo |
| `fal-ai/flux-2` | per MP (in+out) | $0,012/MP | https://fal.ai/models/fal-ai/flux-2 |
| `fal-ai/flux-2-flex/edit` | per processed MP | **$0,05/MP** | https://fal.ai/models/fal-ai/flux-2-flex/edit |
| `fal-ai/flux-pro/v1.1-ultra` | per image | $0,06/ảnh | https://fal.ai/models/fal-ai/flux-pro/v1.1-ultra |

### Kiểm chứng chéo từ trang tổng hợp chính thức
https://fal.ai/pricing ghi: Kling 2.5 Turbo Pro = **$0,07/second**, Veo 3 = **$0,4/second**,
Nanobanana = **$0,0398/image**, Seedream V4 = $0,03/image, Flux Kontext Pro = $0,04/image,
Qwen = **$0,02/megapixel**.
⚠️ **Mâu thuẫn chính thức:** trang /pricing ghi Qwen $0,02/MP, còn trang model
`fal-ai/qwen-image-edit` ghi **$0,03/MP**. Con số dùng để lập trình/thanh toán là **$0,03/MP**
(theo trang model + `endpointBilling`). Nên xác minh lại bằng API có key trước khi chốt ngân sách.

---

## B7. fal có phí tối thiểu / phí tháng không? Có free credit khi đăng ký không?

| Câu hỏi | Trả lời | Nguồn |
|---|---|---|
| Phí thuê bao tháng bắt buộc? | ❌ **KHÔNG.** Model API là **prepaid credit, pay-as-you-go**. *"Direct API usage remains pay as you go."* | https://fal.ai/docs/documentation/agent/access-and-pricing |
| Có gói subscription? | ✅ **CÓ nhưng TÙY CHỌN**, chỉ cho fal Agent (Early Access): **Starter $50/tháng** ($50 credit, không giảm) · **Pro $200/tháng** ($200 credit, **giảm 5%**) · **Max $1.000/tháng** ($1.000 credit, **giảm 10%**) · Enterprise (custom). Credit dùng chung mọi sản phẩm, **roll-over mỗi tháng**, hủy bất cứ lúc nào. Giảm giá **KHÔNG** áp cho API. | https://fal.ai/docs/documentation/agent/access-and-pricing |
| Free credit khi đăng ký? **Bao nhiêu?** | ⚠️ **KHÔNG TÌM THẤY con số chính thức.** FAQ chỉ xác nhận free credit **có tồn tại**: *"Free credits and coupons have variable expiration depending on the specific grant, ranging from 1 week to 1 year."* Không nêu số lượng. Ngoài ra có chương trình complimentary credits tới **$1.000** nhưng chỉ cho fal Agent, phải apply: https://fal.ai/agent-access/claim | https://fal.ai/docs/documentation/model-apis/faq |
| Ngưỡng nạp tối thiểu? | ⚠️ **KHÔNG TÌM THẤY** trên bất kỳ trang công khai nào. `fal.ai/dashboard/billing` yêu cầu đăng nhập. *(Nguồn thứ cấp costbench.com nói "no minimum commitment" — CHƯA được fal xác nhận.)* | Đã thử: /pricing, /dashboard/billing, /login, docs pricing, docs faq, docs accounts-and-identity, /terms |
| Credit có hết hạn? | ✅ Có — credit mua hết hạn sau **365 ngày**; free credit/coupon 1 tuần–1 năm | https://fal.ai/docs/documentation/model-apis/faq |
| Hết credit thì sao? | Tài khoản bị **lock**, request bị từ chối cho tới khi nạp thêm | https://fal.ai/docs/documentation/model-apis/faq |
| Rate limit | Tài khoản mới **2 request đồng thời**, tăng theo lượng credit đã mua, tối đa **40** | https://fal.ai/docs/documentation/model-apis/faq |
| GPU tự deploy | H100 từ **$1,89/giờ**, B200 $3,49/h, B300 $4,49/h, RTX PRO 6000 $1,10/h | https://fal.ai/pricing |

---

## B8. fal tính tiền lần gọi THÀNH CÔNG hay cả lần LỖI?

**Trả lời: KHÔNG tính tiền lỗi server (5xx) và thời gian chờ queue — NHƯNG lỗi 422 (input sai) CÓ THỂ bị tính.**

Trích nguyên văn tài liệu chính thức
(https://fal.ai/docs/documentation/model-apis/pricing và
https://fal.ai/docs/documentation/model-apis/faq):

> *"You pay only for successful outputs, and you are **never charged for server errors or time
> spent waiting in the queue**."*
>
> *"Server errors (HTTP 500+) are never charged. If a runner fails to process your request due to
> an infrastructure issue, you pay nothing. **Client-side errors like invalid inputs (HTTP 422)
> may still be charged if a runner spent GPU time processing the request before the error was
> detected.**"*
>
> *"Do I pay for cold starts? **No.** For Model API endpoints, you are billed only for inference
> time. Cold start time, including container pull and model loading, is not charged."*

⚠️ **Hệ quả thực tế cho FabrikAI:** phải **validate ảnh + mask + tham số ở phía mình**
(kích thước, định dạng, URL ảnh còn sống, mask khớp chiều) **trước khi gọi hàng loạt**, vì lỗi 422
vẫn có thể bị trừ credit.

**Cách tự kiểm chứng giá bằng API (cần FAL_KEY):**
```
GET https://api.fal.ai/v1/models/pricing?endpoint_id=fal-ai/flux/dev
Authorization: Key $FAL_KEY
→ { "prices":[{"endpoint_id":"fal-ai/flux/dev","unit_price":0.025,"unit":"image","currency":"USD"}] }
```
*(Đã thử gọi không có key → trả `{"error":{"type":"authorization_error"}}`. Có endpoint
`/v1/account/billing` trả số dư credit, và `/v1/models/billing-events` trả chi tiết từng lần trừ tiền.)*

---

# PHẦN C — Giá Qwen / DashScope (Alibaba Cloud Model Studio)

> Nguồn chính: https://www.alibabacloud.com/help/en/model-studio/model-pricing (bảng HTML
> server-render, tải được bằng curl). Ngày truy cập **2026-09-23**.

## C0. Quy tắc tính tiền (chính thức)

- **Ảnh:** *"You are charged for output based on the number of successfully generated images.
  Formula: **Cost = Input image unit price × Number of input images + Image unit price × Number
  of images generated**."*
- **Lỗi:** *"**Failed requests incur no cost and do not consume your free quota.**"* Ví dụ chính thức:
  gọi sinh 4 ảnh nhưng chỉ 3 ảnh trả về thành công → **chỉ tính 3 ảnh**.
- **Qwen Image Editing:** *"**Only output is billed.**"* (ảnh input của edit **miễn phí**)
- **Qwen Text-to-Image:** *"Only output is billed."*
- **Đơn vị tính: theo ẢNH, KHÔNG theo megapixel** (khác với fal.ai). Chỉ model
  `qwen-image-3.0` / `3.0-pro` mới tính thêm **input $0,003/ảnh** cho ảnh tham chiếu.

## C1. Qwen Image — sinh ảnh (text-to-image)

### Khu vực **International (Singapore)** — có free quota

| Model ID | Giá output | Free quota (90 ngày) |
|---|---|---|
| `qwen-image-3.0-pro` | **$0,04/ảnh (1k)** · **$0,075/ảnh (2k)** + input $0,003/ảnh | 10 ảnh |
| `qwen-image-3.0` | **$0,03/ảnh (1k)** · **$0,03/ảnh (2k)** + input $0,003/ảnh | 10 ảnh |
| `qwen-image-2.0-pro` (= `…-2026-04-22`) | **$0,075/ảnh** | 100 ảnh |
| `qwen-image-2.0` (= `…-2026-03-03`) | **$0,035/ảnh** | 100 ảnh |
| `qwen-image-max` | **$0,075/ảnh** | 100 ảnh |
| `qwen-image-plus` (= `qwen-image`) | **$0,03/ảnh** | 100 ảnh |
| **`qwen-image`** | **$0,035/ảnh** | 100 ảnh |

### Khu vực **China (Beijing)** — KHÔNG có free quota

| Model ID | Giá output |
|---|---|
| `qwen-image-3.0-pro` | $0,034380/ảnh (1k) · $0,068761/ảnh (2k) + input $0,002750 |
| `qwen-image-3.0` | $0,024754/ảnh (1k & 2k) + input $0,002750 |
| `qwen-image-2.0-pro` | $0,071676/ảnh |
| `qwen-image-2.0` | $0,028671/ảnh |
| `qwen-image-max` | $0,071677/ảnh |
| `qwen-image-plus` | $0,028671/ảnh |
| **`qwen-image`** | **$0,035/ảnh** |

*(Giá ở Bắc Kinh thấp hơn Singapore ~4–18%.)*

## C2. Qwen Image Edit — sửa ảnh (inpaint)

| Model ID | Khu vực | Giá output | Free quota |
|---|---|---|---|
| **`qwen-image-edit`** | International | **$0,045/ảnh** | **100 ảnh** |
| | China (Beijing) | **$0,043/ảnh** | — |
| **`qwen-image-edit-plus`** (= `…-2025-10-30`) | International | **$0,03/ảnh** | **100 ảnh** |
| | China (Beijing) | **$0,028671/ảnh** | — |
| **`qwen-image-edit-max`** (= `…-2026-01-16`) | International | **$0,075/ảnh** | **100 ảnh** |
| | China (Beijing) | **$0,071677/ảnh** | — |

## C3. Wan (text-to-image)

| Model ID | Khu vực | Giá | Free quota |
|---|---|---|---|
| `wan2.6-t2i` | International | **$0,03/ảnh** | 50 ảnh |
| `wan2.5-t2i-preview` | International | **$0,03/ảnh** | 50 ảnh |
| **`wan2.2-t2i-plus`** | International (Singapore) | **$0,05/ảnh** = 0,366962 元/张 | **100 ảnh** |
| | China (Beijing) | **0,20 元/ảnh** (bản ZH) — ⚠️ xem cảnh báo bên dưới | 100 ảnh |
| **`wan2.2-t2i-flash`** | International (Singapore) | **$0,025/ảnh** = 0,183481 元/张 | **100 ảnh** |
| | China (Beijing) | **0,14 元/ảnh** (bản ZH) — ⚠️ xem cảnh báo bên dưới | 100 ảnh |
| `wan2.1-t2i-plus` | International | $0,05/ảnh | 200 ảnh |
| `wan2.1-t2i-turbo` | International | $0,025/ảnh | 200 ảnh |

> ⚠️ **CẢNH BÁO MÂU THUẪN giữa bản EN và bản ZH của cùng trang giá Alibaba.**
> Bảng **China (Beijing)** trên bản **EN** ghi `wan2.2-t2i-plus = $0,020070/ảnh` và
> `wan2.2-t2i-flash = $0,028671/ảnh` — tức **flash đắt hơn plus**, vô lý và **ngược** với bảng
> Singapore (plus $0,05 > flash $0,025).
> Bản **ZH** (https://help.aliyun.com/zh/model-studio/model-pricing) ghi
> **plus = 0,20 元/ảnh**, **flash = 0,14 元/ảnh** — hợp lý và nhất quán với Singapore.
> → **Lấy bản ZH làm chuẩn. Hai dòng Beijing của bản EN nhiều khả năng bị hoán đổi.**
> (Tương tự: bản EN còn ghi `wan2.2-t2i-flash` Beijing $0,028671 trong khi `wan2.6-t2i` và
> `wanx2.1-t2i-plus` cũng $0,028671 — dấu hiệu copy nhầm ô.)

**Wan image (thế hệ mới):**

| Model ID | Khu vực | Giá | Free quota |
|---|---|---|---|
| `wan2.7-image-pro` | International | **$0,075/ảnh** | 50 ảnh |
| `wan2.7-image` | International | **$0,03/ảnh** | 50 ảnh |
| `wan2.6-image` | International | **$0,03/ảnh** | 50 ảnh |
| `wan2.5-i2i-preview` (image-to-image) | International | **$0,03/ảnh** | 50 ảnh |
| `wanx2.1-imageedit` | China (Beijing) | $0,020070/ảnh | — |

## C4. Wan video (image-to-video) — để tham chiếu

| Model ID | Khu vực | Giá |
|---|---|---|
| `wan2.2-i2v-flash` | International | 480P **$0,015/giây** · 720P **$0,036/giây** |
| `wan2.2-i2v-plus` | International | 480P **$0,02/giây** · 1080P **$0,10/giây** |
| `wan2.2-i2v-plus` | China (Beijing) | 480P $0,02007/giây · 1080P $0,100347/giây |
| `wan2.2-s2v` | — | 480P $0,071677/giây · 720P $0,129018/giây (**không có free quota**) |

## C5. Free quota & billing

| Câu hỏi | Trả lời | Nguồn |
|---|---|---|
| Free quota khi đăng ký? | ✅ **CÓ, nhưng CHỈ ở Singapore (International)**. *"The following models offer a free quota **only in Singapore**. **No free quota is available in other regions.**"* Hạn dùng **90 ngày** kể từ khi kích hoạt Model Studio / model ra mắt / đơn được duyệt. | https://www.alibabacloud.com/help/en/model-studio/model-pricing |
| Số lượng free | `qwen-image-edit`: **100 ảnh** · `qwen-image`: 100 ảnh · `qwen-image-max`: 100 ảnh · `wan2.2-t2i-plus/flash`: 100 ảnh · `wan2.1-t2i-plus/turbo`: 200 ảnh · `qwen-image-3.0`: 10 ảnh · `wan2.7-image`: 50 ảnh | như trên |
| Tính tiền lần lỗi? | ❌ **KHÔNG.** *"Failed requests incur no cost and do not consume your free quota."* | như trên |
| Trang free quota riêng | https://www.alibabacloud.com/help/en/model-studio/new-free-quota (EN) · https://help.aliyun.com/zh/model-studio/new-free-quota (ZH) | — |
| Quota tính bằng gì? | **Bằng SỐ ẢNH (张) hoặc số GIÂY video — KHÔNG bao giờ bằng CNY** | như trên |
| Điều kiện áp dụng | *"Only models in the Singapore region with the service deployment scope set to **International** are eligible for a free quota."* Bản ZH: *"仅华北 2（北京）地域模型享有免费额度，其他地域无免费额度"* | S8/S9 |
| Phạm vi | **Chỉ real-time inference.** KHÔNG áp cho: batch invocation, fine-tuning, model deployment, custom models, PAI-DSW, phí OSS | https://www.alibabacloud.com/help/en/model-studio/new-free-quota |
| Hết hạn / gia hạn | 90 ngày, **hết là mất, không cấp lại**. Đăng ký tài khoản mới **KHÔNG** được cấp thêm quota | như trên |
| Chế độ an toàn | Có thể bật **"Free Quota Only"** để dịch vụ tự dừng khi hết quota (tránh phát sinh phí ngoài ý muốn) | như trên |
| Quota chi tiết | `qwen-image-edit` **100 ảnh** · `qwen-image` 100 · `qwen-image-max` 100 · `qwen-image-plus` 100 · `qwen-image-2.0` 100 · `wan2.2-t2i-plus` 100 · `wan2.2-t2i-flash` 100 · `wan2.1-t2i-plus/turbo` 200 · `wanx2.1-t2i-*`/`wanx2.0-t2i-turbo` 500 · `qwen-image-3.0`/`3.0-pro` **10 ảnh** (gộp input+output) · `wan2.6-t2i`/`wan2.5-t2i-preview`/`wan2.7-image*`/`wan2.6-image` 50 ảnh · **video: 50 giây** | S1/S2 |

### C5.1 Giá gốc bằng CNY và tỷ giá ngầm định

Alibaba công bố **hai bản song song**: EN (USD) và ZH (CNY). **Giá gốc là CNY**, USD chỉ là quy đổi:

| Model | Singapore (USD) | Singapore (CNY) | Beijing (CNY) | Beijing (USD) |
|---|---|---|---|---|
| `qwen-image` | $0,035 | 0,256873 元 | **0,25 元** | $0,035 |
| `qwen-image-plus` | $0,03 | 0,220177 元 | **0,2 元** | $0,028671 |
| `qwen-image-max` | $0,075 | 0,550443 元 | **0,5 元** | $0,071677 |
| `qwen-image-edit` | $0,045 | 0,330266 元 | **0,3 元** | $0,043 |
| `qwen-image-edit-plus` | $0,03 | 0,220177 元 | **0,2 元** | $0,028671 |
| `qwen-image-edit-max` | $0,075 | 0,550443 元 | **0,5 元** | $0,071677 |
| `wan2.2-t2i-plus` | $0,05 | 0,366962 元 | **0,20 元** | (EN sai) |
| `wan2.2-t2i-flash` | $0,025 | 0,183481 元 | **0,14 元** | (EN sai) |

Tỷ giá ngầm định Alibaba dùng: **Beijing ≈ 6,976 CNY/USD**, **Singapore ≈ 7,34 CNY/USD**
(suy ra từ các cặp số trên — đây là **tính toán của tôi**, không phải công bố chính thức).
→ **Beijing luôn rẻ hơn Singapore ~0–9%** khi quy về cùng đơn vị.

### C5.2 Xác nhận: KHÔNG có model ảnh nào tính theo megapixel

Đã rà toàn bộ 273 bảng ở bản EN và 614 bảng ở bản ZH: **mọi model ảnh của Qwen/DashScope đều
tính theo ẢNH (per image / 元/张)**. Bậc giá theo độ phân giải chỉ có ở:
- `qwen-image-3.0` / `qwen-image-3.0-pro` (1k vs 2k)
- các model **video** (480P / 720P / 1080P)

Các model còn lại **giá phẳng mỗi ảnh** — kể cả `qwen-image-edit-plus` / `-max` dù cho phép
output tới 2048×2048 (theo API reference: width/height mỗi chiều 512–2048, mặc định ~1024×1024).
→ **Đây là điểm khác biệt cốt lõi với fal.ai (tính theo MP): ảnh càng lớn, DashScope càng rẻ
tương đối.**

---

# TỔNG HỢP SO SÁNH & KHUYẾN NGHỊ CHO FABRIKAI

## Giá vốn 1 lần EDIT/INPAINT ảnh ~1MP (1024×1024)

| Đường | Model | Chi phí 1 ảnh 1MP |
|---|---|---|
| **DashScope** | `qwen-image-edit-plus` | **$0,03** |
| **DashScope** | `qwen-image-edit` | **$0,045** |
| **DashScope** | `qwen-image-edit-max` | **$0,075** |
| **fal.ai** | `fal-ai/qwen-image-edit` | **$0,03/MP** |
| **fal.ai** | `fal-ai/nano-banana/edit` | **$0,0398** |
| **fal.ai** | `fal-ai/flux-pro/v1/fill` | **$0,05/MP** |
| **fal.ai** | `fal-ai/nano-banana-pro/edit` | **$0,15** (4K: $0,30) |

## ⚠️ Điểm cần sửa trong `PRICING.md` hiện tại

`PRICING.md` §1.1 đang ghi **"Qwen Image Edit 2511 — $0,03/MP"** và
**"Qwen Image Base/2512 — $0,02/MP"**. Đối chiếu bảng giá **chính thức của Alibaba Cloud**:

| Dòng trong PRICING.md | Thực tế trên Alibaba Model Studio | Chênh |
|---|---|---|
| Qwen Image Base/2512 = $0,02/MP | `qwen-image` = **$0,035/ẢNH** (không theo MP) | **+75%** nếu ảnh 1MP |
| Qwen Image Edit 2511 = $0,03/MP | `qwen-image-edit` = **$0,045/ảnh**; `-plus` = **$0,03/ảnh** | tuỳ biến thể |
| Qwen Image Max = $0,075 | `qwen-image-max` = **$0,075/ảnh** ✅ | khớp |
| Qwen Image Layered = $0,05 | **KHÔNG TÌM THẤY** trong bảng giá Model Studio | ? |

→ **Con số $0,02/MP và $0,03/MP trong PRICING.md khớp với giá fal.ai, KHÔNG khớp giá
DashScope trực tiếp.** Vì FabrikAI đang gọi DashScope/QwenCloud trực tiếp, **chi phí thật mỗi ảnh
đang bị tính thấp hơn thực tế**:

| | Giả định trong PRICING.md | Giá DashScope thật | Sai lệch |
|---|---|---|---|
| Ảnh base 1K | $0,02 | **$0,035/ảnh** | **+75%** |
| Ảnh edit 1K (nếu dùng `qwen-image-edit`) | $0,03 | **$0,045/ảnh** | **+50%** |
| Ảnh edit 1K (nếu dùng `qwen-image-edit-plus`) | $0,03 | **$0,03/ảnh** | 0% |

⇒ Với mốc PRICING.md §2 dùng **500 ₫/ảnh base** và **750 ₫/ảnh edit** (tỷ giá 24.000 ₫/USD):
- base thật ≈ **$0,035 × 24.000 = 840 ₫/ảnh** (không phải 500 ₫) → **+68%**
- edit thật ≈ **$0,045 × 24.000 = 1.080 ₫/ảnh** (không phải 750 ₫) → **+44%**

**Hệ quả trực tiếp lên biên gộp** (PRICING.md §2 đặt mục tiêu >70% base, >40% Max):

| Gói | Giá bán | Credit | ₫/credit | Biên theo giả định cũ (500₫) | **Biên thật (840₫)** |
|---|---|---|---|---|---|
| Khởi nghiệp | 199.000 ₫ | 120 | 1.658 ₫ | ~70% | **~49%** |
| Chuyên nghiệp | 499.000 ₫ | 350 | 1.426 ₫ | ~65% | **~41%** |
| Studio | 1.490.000 ₫ | 1.200 | 1.242 ₫ | ~60% | **~32%** |

→ **Không gói nào còn đạt mục tiêu biên >70%.** Cần hoặc tăng giá bán, hoặc chuyển sang
`qwen-image-edit-plus` ($0,03/ảnh = 720 ₫), hoặc giảm số credit/gói.
**Cần cập nhật PRICING.md §1.1, §2, §3 trước khi chốt giá bán.**

> ⚠️ **Bắt buộc kiểm chứng bằng hoá đơn thật.** Tôi **không** truy cập được qwencloud.com
> (trang không tải được bằng curl trong phiên này) và **không** biết FabrikAI đang gọi đúng
> model ID nào. Nếu hợp đồng QwenCloud có giá riêng thì các con số trên có thể khác.
> Cách kiểm tra nhanh: mở trang Billing/Usage của DashScope, lọc theo model ID trong 1 ngày
> có lưu lượng, chia tổng tiền cho số ảnh.

*(Lưu ý: tôi không tra được danh mục model thực tế trên qwencloud.com — trang đó không tải được
bằng curl trong phiên này. Nếu FabrikAI mua qua QwenCloud với hợp đồng riêng thì giá có thể khác.
Cần đối chiếu hoá đơn thực tế.)*

## Rút ra cho định giá

1. **OpenArt** bán **$1,65–3,50 / 1.000 credit**, credit **không rollover**, bắt trả gói tháng.
   Đây là mô hình "subscription + credit hết hạn" — markup dày ở model rẻ.
2. **Không thể dùng OpenArt làm backend** (không có API công khai + Terms §4.4 cấm bot/script).
3. **fal.ai** không có phí tháng bắt buộc cho API, tính theo output, **không tính lỗi 5xx**.
   Rẻ nhất cho edit: `qwen-image-edit` **$0,03/MP**.
4. **DashScope** tính **theo ẢNH** (không theo MP), **không tính lỗi**, free quota 100 ảnh
   (chỉ Singapore, 90 ngày).
5. Vì DashScope tính theo ảnh còn fal tính theo MP → **ảnh càng lớn thì fal càng đắt**,
   ảnh ≤1MP thì hai bên tương đương. Với ảnh sản phẩm 2K, **DashScope rẻ hơn rõ rệt**.

---

## Danh sách "KHÔNG TÌM THẤY" (không bịa số)

1. Bảng **credit cost chính thức theo từng model** của OpenArt — **không tồn tại công khai**
   (không có API, Terms cấm scrape).
2. Số **free credit khi đăng ký fal.ai** — fal không công bố số lượng.
3. **Ngưỡng nạp tối thiểu** của fal.ai — trang billing yêu cầu đăng nhập.
4. Giá **credit pack one-time** của OpenArt — có cơ chế (`CreditPack`, `hasLiveOneTimePack`)
   nhưng không có giá công khai.
5. OpenArt có credit miễn phí **hàng ngày** không — không tìm thấy cơ chế nào.
6. Giá **Qwen Image Layered** ($0,05 trong PRICING.md) — không có trong bảng giá Model Studio.
7. Giá trên **qwencloud.com** — trang không tải được trong phiên này.
8. Mâu thuẫn chưa giải quyết: trường `PRICES.yearly` = 7/17/28/120 của OpenArt
   vs. giá render trên trang = 13/27/44/175.
9. **Giá wan2.2-t2i-plus / -flash ở khu vực Beijing trên bản EN của Alibaba bị sai/hoán đổi**
   so với bản ZH gốc — chưa rõ bên nào đúng, đã lấy bản ZH làm chuẩn (xem cảnh báo ở C3).
10. Mức giá **1080P cho `wan2.2-i2v-flash`**: bảng Singapore không niêm yết, bảng Beijing (ZH)
    có 0,48 元/giây → có thể là khác biệt thật giữa 2 region, cần kiểm chứng nếu dùng.
11. Trang model info riêng cho `qwen-image-edit*` **không tồn tại** (404 hoặc redirect sang
    API reference) — giá chỉ có từ bảng tổng hợp.

## Việc nên làm tiếp

- [ ] Gọi `GET https://api.fal.ai/v1/models/pricing?endpoint_id=…` bằng FAL_KEY để chốt giá
      chính xác theo tài khoản (có thể có discount riêng).
- [ ] Đối chiếu **hoá đơn DashScope thực tế** với bảng giá trên để xác nhận model ID đang dùng
      (`qwen-image-edit` vs `qwen-image-edit-plus` vs `-2511`) — chênh lệch **$0,03 vs $0,045/ảnh
      = 50%**, ảnh hưởng trực tiếp tới biên.
- [ ] Cập nhật `PRICING.md` §1.1, §2, §3 theo số thật.
