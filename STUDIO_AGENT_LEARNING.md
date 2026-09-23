# FabrikAI · AGENT STUDIO — PHÂN TÍCH SÂU + ĐIỂM NGỌT CỦA KIẾN TRÚC TỰ HỌC (Domain Index + Long-Term Memory) · 2026-09-22

> **File này trả lời hai câu hỏi, không trùng ba tài liệu review kia:**
> 1. Agent Studio **thực sự là gì** khi soi theo lăng kính *"AI tự học ngày càng thông minh"* — không phải soi theo luồng UI hay bảo mật như `STUDIO_REVIEW*.md`.
> 2. Đâu là **điểm ngọt** (sweet spot) khi đối chiếu kiến trúc chuẩn của một hệ thời trang tự học — *Domain-Specific Index + Long-Term Memory* — với thứ FabrikAI **đã dựng**.
>
> **Neo đo (đo lại bằng `scripts/measure.sh --no-tests` lúc 08:57 2026-09-22):** HEAD `4a2fca6` · **352 commit** · **219 route** · `app/` **120 file / 36.823 dòng** · `app/Services` 26 · `app/Models` 31 · migrations 58 · JS/Vue studio **109 file (65 .vue) / 29.306 dòng**. Điểm nóng: `DesignAgentService.php` **4.493 dòng** · `StudioController.php` 5.420.
> **Quy ước bằng chứng:** ✅ = đã tự đọc code · ⚠️ = lấy từ ghi chú trong code, chưa chạy lại.

---

## §0 — TÓM TẮT ĐIỀU HÀNH: 5 điều cần biết trước khi đọc

| # | Kết luận | Bằng chứng |
|---|---|---|
| 1 | **FabrikAI ĐÃ dựng cả hai trụ của kiến trúc tự học — nhưng ở dạng THÔ, không vector, không graph.** Chỉ mục kiến thức = `WebSourceService` (ingestion) + `MarketSignalService` (đo bằng thuật toán) + `trendCatalog()` (catalog mẫu). Trí nhớ dài hạn = `BrandLearningService` (GĐ1) + `BrandDnaService` + `ShopSignal`. | ✅ §1.2, §1.3 |
| 2 | **Nguyên tắc sâu nhất của Agent Studio đã ĐÚNG đúng cái mà Domain Index được sinh ra để làm:** *"AI chỉ được trả CHỮ; mọi con số do tầng dữ liệu quyết định."* Đây chính là cơ chế chống bịa số liệu — thứ mà kiến trúc *retrieval-over-index* hứa hẹn. | ✅ `DesignAgentService.php:16-30` (header contract) |
| 3 | **Vòng lặp tự học mới đi được 1/5.** Hiện có **Retrieve → Act** (preferences() đọc gu → brief). Thiếu **Reflect → Extract → Consolidate** — và quan trọng nhất là thiếu *"bài học"* (lesson) tách khỏi *"prompt thô"*: `brand_learning` ghi "đầm linen trắng ngà", KHÔNG ghi "khách thích linen trắng ngà, TRÁNH bóng hoạ tiết to". | ✅ §1.4, `BrandLearningService.php:22-47` |
| 4 | **Trong 3 loại trí nhớ, thiếu đúng 1 loại — và đó là loại giá trị nhất:** *Procedural Memory* ("khi hỏi công sở thì ưu tiên màu trung tính"). Episodic ≈ `brand_learning` · Semantic ≈ `brand_dna` · **Procedural = CHƯA CÓ CHỖ LƯU**. | ✅ §2.4 |
| 5 | **Điểm ngọt thực dụng nhất:** không cần xây vector DB ngay. Cái rẻ hơn nhiều và đúng tinh thần ReasoningBank là **thêm một lớp "trích xuất bài học" sau mỗi lần duyệt/loại ảnh** — biến prompt thô thành lesson, và lưu lesson đó vào `brand_learning` thay vì prompt thô. | ✅ §3.1 |

---

## §1 — PHÂN TÍCH SÂU: Agent Studio là gì, khi soi bằng lăng kính "tự học"

### 1.1 Bản đồ kiến trúc — đã có HAI TRỤ, chỉ là chưa ai gọi đúng tên

Kiến trúc chuẩn của một hệ thời trang tự học có 5 giai đoạn (Ingestion → Embedding → Indexing → Memory → Retrieval & Self-Evolution). Đặt Agent Studio lên bản đồ đó:

| Giai đoạn chuẩn | FabrikAI hiện có | File ✅ |
|---|---|---|
| **Ingestion** (crawl dữ liệu) | `WebSourceService` — máy chủ tự lấy RSS/JSON, nguồn là CẤU HÌNH trong bảng `web_sources` (thêm nguồn không sửa mã), lọc vùng, làm sạch thẻ HTML | `app/Services/WebSourceService.php:31-1431` |
| **Processing** | `MarketSignalService` — đo từ khoá ngành (màu/dáng/chất liệu/chi tiết/phong cách) bằng thuật toán, khớp theo RANH GIỚI TỪ, ra momentum/confidence/lifecycle + giá thị trường | `app/Services/MarketSignalService.php:53-81` |
| **Indexing** | Catalog xu hướng mẫu 8 hướng (`trendCatalog()`, `evidence_mode=demo`) — **CHƯA có vector, CHƯA có embedding, CHƯA có BM25/Knowledge Graph** | `DesignAgentService.php:3431-3463` |
| **Memory** | `brand_learning` (duyệt/loại ảnh) + `brand_dna` (chủ shop khai) + `shop_signals` (số bán) | §1.3 |
| **Retrieval & Self-Evolution** | `internalBrandSignal()` gộp tất cả vào prompt; vòng phản hồi DUY NHẤT là approve/reject → preferences() → brief | `DesignAgentService.php:3475-3564` |

**Kết luận:** FabrikAI không phải đang "chưa có gì" — nó đã dựng **nửa trên của pipeline** (ingestion + đo tín hiệu) và **nửa dưới của memory** (ghi nhớ quyết định). Cái thiếu là **lớp giữa** (embedding/vector/retrieval ngữ nghĩa) và **lớp sau** (reflect/extract/consolidate).

### 1.2 Trụ 1 — "Chỉ mục kiến thức chuyên ngành" đã có, nhưng chưa phải *index*

Ba lớp hiện tại tương ứng với "chỉ mục" theo nghĩa rộng:

1. **Ingestion thật, không phải demo** — `WebSourceService` là một crawler RSS/JSON đúng nghĩa: cấu hình nguồn trong DB, đệm 30 phút/nguồn, giữ "bản lấy thành công gần nhất 24h" khi nguồn chết tạm, phân biệt 4 trạng thái (chết · không tin · bị lọc hết · dùng bản cũ). Đây là *đúng tinh thần* mục "Thu thập dữ liệu (Crawling)" của kiến trúc đề xuất — khác ở chỗ đề xuất nói Firecrawl/Playwright cho trang JS, còn FabrikAI chọn RSS/JSON cho rẻ và ít phụ thuộc.

2. **"Index" thực ra là từ-vựng-đóng + thuật toán, không phải vector** — `MarketSignalService::VOCABULARY` là một *ontology thô* (5 nhóm: color/silhouette/fabric/detail/style, mỗi từ khoá gắn sẵn một nhóm hàng nên kết quả giải thích được). Nó KHÔNG nhúng ngữ nghĩa, KHÔNG tìm ảnh, nhưng nó **tái lập được 100%** và **không tốn token** — đúng thứ mà một Domain Index tất định nên làm trước khi ném model vào.

3. **Retrieval bằng "nhét hết vào prompt"** — `evidence()` trả tối đa 14 tin, `market` tối đa 12 tín hiệu, rồi `aiBrief()`/radar đưa nguyên khối vào chỉ dẫn. Đây là *RAG thô*: không có truy xuất top-k theo độ tương đồng, chỉ có cắt trần token. Càng nhiều nguồn, càng phải cắt — **đây là nơi vector DB sẽ trả giá trị đầu tiên**.

> **Điểm ngọt ẩn:** FabrikAI đã vô tình chọn đúng triết lý mà đề xuất mất vài trang để nói — *"chỉ mục giúp AI truy xuất thông tin chính xác, còn trí nhớ dài hạn cho nó tích luỹ kinh nghiệm"*. Bằng chứng là contract 2 tầng ở đầu `DesignAgentService`: **TẦNG SỐ LIỆU** (catalog + dữ liệu user, luôn khai `source_mode=demo`) tách bạch khỏi **TẦNG SUY LUẬN** (model đọc tầng số liệu rồi viết chữ; AI không được bịa số). Đây là bản hiện thực hoá của *"AI truy xuất, không phải AI bịa"*.

### 1.3 Trụ 2 — Trí nhớ dài hạn đã có "GĐ1", và code tự vạch sẵn lộ trình GĐ2/GĐ3

`BrandLearningService` + bảng `brand_learning` là một **Episodic Memory sơ khai**: mỗi dòng = một sự kiện "chủ shop duyệt/loại ảnh X vào lúc Y, với prompt Z".

Điều quý giá nhất là **lộ trình đã nằm sẵn trong code**, ngay trong câu lệnh gửi model (`DesignAgentService.php:2567-2572`):

- **GĐ1 — học từ duyệt/loại** (`brand_memory.approved/rejected`) ✅ đã chạy.
- **GĐ2 — học từ bán hàng thật** (`shop_data.best_sellers/slow_movers/category_demand`) ✅ đã có dữ liệu (`shop_signals`) và đã vào prompt.
- **GĐ3 — dự báo từ thị trường** (`market_signals.signals.change_pct`) ✅ đã có (`MarketSignalService`).

Tức là **ba "nguồn trí nhớ" của Agent Studio đã được thiết kế và nối dây** — điều còn thiếu không phải là "thêm trí nhớ", mà là **làm cho trí nhớ tự rút kinh nghiệm** (xem §1.4).

So 3 loại trí nhớ chuẩn:

| Loại trí nhớ chuẩn | FabrikAI hiện có | Khoảng trống |
|---|---|---|
| **Episodic** (sự kiện cụ thể) | `brand_learning` (quyết định duyệt/loại) ✅ | Chưa ghi *ngữ cảnh* ("mùa hè", "dự tiệc"), chỉ ghi prompt trần |
| **Semantic** (kiến thức khái quát) | `brand_dna` (owner khai) + DNA suy ra (derived) ✅ | DNA derived suy bằng đếm + dò từ khoá, chưa khái quát từ các lesson |
| **Procedural** (chiến lược/quy trình) | ❌ **CHƯA CÓ** | "Khi hỏi công sở → ưu tiên màu trung tính, chất ít nhăn" không có chỗ lưu |

### 1.4 Vòng lặp tự học — mới 1/5, và đoạn thiếu là đoạn quyết định "thông minh dần"

Vòng chuẩn **Retrieve → Act → Reflect → Extract → Consolidate** đối chiếu với Agent Studio:

| Bước | FabrikAI | Bằng chứng ✅ |
|---|---|---|
| **Retrieve** | `preferences()` đọc 10 approved + 5 rejected gần nhất → nhét vào brief | `BrandLearningService.php:54-70` |
| **Act** | `aiBrief()`/radar sinh brief/định hướng | `DesignAgentService.php:2531+` |
| **Reflect** | *Người dùng* duyệt/loại ảnh — không có LLM thứ hai tự đánh giá | `ProjectController.php:266-270` |
| **Extract** | ❌ **Chỉ ghi prompt thô** (`Str::limit(prompt, 500)`), KHÔNG trích "bài học" | `BrandLearningService.php:28` |
| **Consolidate** | ❌ Không có trọng số, không củng cố/suy yếu ký ức, không khử trùng ngữ nghĩa | (grep `BrandLearning` chỉ có create + 2 query) |

**Hệ quả đo được từ code:** `brand_learning` ghi *"đầm linen trắng ngà dáng suông"* (đã duyệt) và *"áo bóng hoạ tiết to"* (đã loại). Đó là **sự kiện**, chưa phải **bài học**. Mô hình ReasoningBank (Google Research) nói rõ sự khác biệt: sau khi hành động, dùng một LLM **khác** để tự đánh giá, rồi trích *"bài học thành công / bài học thất bại"* — vd. *"đừng làm X vì nó dẫn đến Y"*. FabrikAI đang dừng ở bước **ghi lại**, chưa tới bước **rút ra**.

---

## §2 — ĐIỂM NGỌT: đối chiếu kiến trúc đề xuất → thứ FabrikAI nên nhặt về

> "Điểm ngọt" = chỗ mà kiến trúc chuẩn và thứ FabrikAI đã dựng **trùng khớp sẵn** — nơi một thay đổi NHỎ cho ra giá trị LỚN, vì nền đã có.

### Điểm ngọt 1 — "AI chỉ được trả CHỮ" đã là Domain Index, chỉ cần làm nó *truy xuất được thay vì nhét hết vào prompt*

Kiến trúc đề xuất tốn cả một phần để nói *"chỉ mục giúp AI truy xuất thông tin chính xác"*. FabrikAI đã có câu trả lời còn chặt hơn: **tách 2 tầng, AI không được quyết định con số**. Nhưng cách "truy xuất" hiện tại là *đổ toàn bộ tin + tín hiệu + trí nhớ vào chỉ dẫn*, rồi cắt trần (14 tin / 12 tín hiệu / 10 approved / 5 rejected).

**Điểm ngọt:** khi nguồn tin nhiều lên (và khi thêm trí nhớ 3 loại), trần token sẽ ép phải CHỌN. Đúng lúc đó, một **retrieval top-k theo ngữ nghĩa** (embedding prompt + tin/ký ức vào cùng không gian, chọn k cái gần nhất) sẽ thay thế việc "lấy N cái mới nhất" — và đây là việc duy nhất trong cả kiến trúc đề xuất **bắt buộc phải có vector DB** (`pgvector` — đã có sẵn PostgreSQL production). Mọi thứ khác có thể làm rẻ hơn.

### Điểm ngọt 2 — `brand_learning` là MemoriesDB đã có 2/3 chiều, thiếu đúng chiều "graph + vector"

MemoriesDB định nghĩa mỗi ký ức đồng thời là **mốc thời gian + vector ngữ nghĩa + nút trong đồ thị quan hệ**. `brand_learning` đã có:

- **Thời gian** ✅ (`id` tự tăng + `created_at`).
- **Ngữ nghĩa** ⚠️ — có prompt text, nhưng *chưa có vector*.
- **Quan hệ** ❌ — mỗi dòng đứng độc lập, không nối "đầm linen" ↔ "áo bóng" ↔ "brand_dna tránh bóng".

**Điểm ngọt:** không cần đổi bảng. Chỉ cần **thêm cột** (`embedding vector`, `lesson text`, `context json`) và một bảng `memory_edges` (cạnh có trọng số). Phần "đồ thị quan hệ" của đề xuất (FashionEcoKG) là thứ XA nhất so với FabrikAI hiện tại — nên KHÔNG làm ngay; trước mắt chỉ cần *cạnh lesson ↔ dna/lesson cùng nhóm* là đủ cho truy xuất theo chuỗi nhân quả.

### Điểm ngọt 3 — ReasoningBank không cần framework mới: nó là một "LLM thứ hai" đọc `brand_learning` rồi viết lesson

Đề xuất gọi ReasoningBank là "framework liên tục học từ thành công và thất bại". Bản chất có thể rút gọn về một **job chạy sau mỗi lần duyệt/loại**:

1. Đọc N quyết định gần nhất của shop (đã có trong `brand_learning`).
2. Gọi **một vai model riêng** (thêm nhóm `agent_reflect` bên cạnh `agent_reason/vision/search` đã có) để trích *"bài học"*: *"shop này thích {chất liệu/dáng/màu} và tránh {…}"*.
3. Ghi lesson vào `brand_learning.lesson` (hoặc bảng con), kèm `context` (mùa · dịp · nhóm hàng).

**Điểm ngọt:** hạ tầng "nhiều vai model" đã có sẵn (`DesignAgentService::REASON_GROUP/VISION_GROUP/SEARCH_GROUP` + fallback về `prompt`) — thêm vai `agent_reflect` chỉ là thêm một hằng số + một nhánh trong `chainFor()`. Không cần LangGraph để điều phối bước này.

### Điểm ngọt 4 — Procedural Memory là khoảng trống RẺ nhất để lấp, và nó chính là "gu công sở"

Trong 3 loại trí nhớ, thứ còn thiếu duy nhất là **Procedural** ("khi hỏi X thì ưu tiên Y"). Mà đó lại là thứ **chủ shop trả tiền để có**: họ muốn Agent Studio *nhớ cách họ muốn làm*, không chỉ *nhớ họ đã chọn gì*.

**Điểm ngọt:** Procedural Memory KHÔNG cần vector, KHÔNG cần graph. Nó là một bảng nhỏ `brand_rules` (`{trigger: "công sở", action: "ưu tiên màu trung tính, chất ít nhăn", weight, source: owner|learned}`) được đưa vào prompt như một khối riêng. Khởi tạo từ `brand_dna.avoid` (đã có) và dần bổ sung bằng lesson ở Điểm ngọt 3. Đây là con đường ngắn nhất từ "ghi nhớ" sang "biết cách".

### Điểm ngọt 5 — Consolidate (củng cố/suy yếu) đã có sẵn "mẫu để bắt chước": `MarketSignalService` snapshot

Đề xuất nói *"ký ức cũ được củng cố (tăng trọng số) hoặc suy yếu (giảm trọng số)"*. FabrikAI **đã làm đúng cơ chế này ở một chỗ khác**: `MarketSignalService` ghi snapshot theo vân tay, giữ lịch sử 120 ngày, so cửa sổ 14 ngày để tính tăng/giảm.

**Điểm ngọt:** cơ chế "trọng số theo thời gian" của trí nhớ có thể **sao chép nguyên mẫu snapshot** từ `MarketSignalService`: ký ức nào được duyệt lại nhiều lần → tăng `weight`; ký ức cũ không được nhắc lại → giảm. Không phát minh lại — chỉ chép một pattern đã chạy ổn trong chính repo.

### Điểm ngọt 6 — "Điểm ngọt" theo nghĩa sản phẩm: cái khiến Agent Studio *dùng càng lâu càng giá trị* đã được viết ra trong code

Bình luận ở `DesignAgentController::shopSignals()` ghi đúng câu trả lời: *"Đây là phần khiến Agent Studio gắn bó lâu dài: càng nhập nhiều kỳ, cơ cấu SKU, dải giá và lời khuyên càng sát cái shop THẬT SỰ bán được."* Đó chính là lời hứa của *Long-Term Memory* — và nó ĐÃ có dữ liệu (`shop_signals`) lẫn đường vào prompt (`best_sellers/slow_movers`).

**Điểm ngọt:** đừng xây thêm tính năng mới để "thông minh hơn". Hãy **đóng vòng** cái đã có: duyệt/loại → lesson → procedural rule → brief sau bám đúng hơn. Đó là "điểm ngọt" đúng nghĩa — nơi nỗ lực nhỏ nhất tạo ra cảm giác "nó học tôi" rõ ràng nhất cho người dùng.

---

## §3 — LỘ TRÌNH: 4 việc, xếp theo tỉ lệ *giá trị / công sức* (không phải theo thứ tự chuẩn sách vở)

| # | Việc | Tại sao trước | Bản chất | Ước lượng |
|---|---|---|---|---|
| **3.1** | **Extract → lesson** (thêm vai `agent_reflect`, job sau duyệt/loại trích "bài học" thay vì prompt thô) | Đây là **ReasoningBank** — biến "ghi nhớ" thành "rút kinh nghiệm". Rẻ, vì hạ tầng nhiều vai đã có | +1 nhóm vai +1 job +1 cột `lesson` | 2–3 ngày |
| **3.2** | **Procedural Memory** (`brand_rules` + khối riêng trong prompt) | Lấp đúng loại trí nhớ còn thiếu; không cần vector/graph | Bảng nhỏ + 1 khối chỉ dẫn | 1–2 ngày |
| **3.3** | **Consolidate** (trọng số ký ức, sao chép mẫu snapshot của `MarketSignalService`) | Làm "trí nhớ" thành TRÍ NHỚ (củng cố/suy yếu) | +cột `weight` + cập nhật theo lần truy xuất/duyệt | 1–2 ngày |
| **3.4** | **Vector + retrieval top-k** (`pgvector`, embedding prompt/tin/ký ức) | Chỉ làm khi số nguồn/ký ức vượt trần token — **đây là việc đắt nhất và cần nhất cuối** | Migration + 1 service nhúng | 1 tuần+ |

> **Cố ý KHÔNG xếp Knowledge Graph (FashionEcoKG) vào lộ trình gần:** nó là thứ "suy luận qua quan hệ phức tạp" mà FabrikAI hiện **chưa có bài toán nào đòi**. Làm graph trước khi có lesson/procedural rule là đốt công sức vào hạ tầng chưa ai dùng — đúng loại "làm tính năng chưa có người hỏi" mà các review trước đã cảnh báo.

---

## §4 — BẢNG ĐỐI CHIẾU TỔNG (kiến trúc đề xuất ↔ FabrikAI hiện có ↔ việc cần làm)

| Thành phần đề xuất | FabrikAI hiện có ✅ | Việc cần làm |
|---|---|---|
| Crawling (Firecrawl/Scrapy/Playwright) | `WebSourceService` (RSS/JSON, nguồn cấu hình DB) | (đủ; thêm nguồn là việc của Cài đặt, không phải code) |
| Multimodal embedding (CLIP) | ❌ chưa có | Gắn vào Điểm ngọt 3.4 (nhúng text trước, ảnh sau) |
| Vector DB (pgvector/FAISS) | ❌ chưa có (PostgreSQL production sẵn) | `pgvector` khi tới 3.4 |
| Hybrid search / Knowledge Graph | ❌ chưa có | Lùi; không làm khi chưa có bài toán |
| Episodic Memory | `brand_learning` (GĐ1) | Thêm `context` + `lesson` (3.1) |
| Semantic Memory | `brand_dna` (owner + derived) | Khái quát từ lesson (3.1) |
| Procedural Memory | ❌ **thiếu** | `brand_rules` (3.2) |
| MemoriesDB (thời gian + vector + graph) | thời gian ✅ · vector ❌ · graph ❌ | 3.4 (vector) + 3.1 (lesson) |
| ReasoningBank (success/failure lesson) | ❌ (chỉ ghi prompt thô) | **3.1 — việc ưu tiên nhất** |
| Vòng Retrieve→Act→Reflect→Extract→Consolidate | Retrieve ✅ · Act ✅ · Reflect (người dùng) · Extract ❌ · Consolidate ❌ | 3.1 + 3.3 |
| LlamaIndex / LangGraph | (không dùng) | Không cần: `SdkTextEngine` + `RegistryProviders` đã là trái tim điều phối |

---

## §5 — ĐIỀU KIỆN ĐO ĐƯỢC (DoD) cho 4 việc ở §3

- **3.1 Extract:** sau khi duyệt/loại 1 ảnh, có 1 dòng `brand_learning.lesson` KHÁC prompt thô (test: lesson chứa *"thích"/"tránh"*, không phải bản sao prompt). Thêm test mutation: bỏ job → lesson rỗng → RED.
- **3.2 Procedural:** prompt brief có khối `brand_rules` khi shop đã khai `avoid`; test round-trip rule → prompt.
- **3.3 Consolidate:** ký ức được duyệt lại ≥2 lần có `weight` tăng; ký ức cũ không nhắc sau N ngày có `weight` giảm (test bằng dữ liệu dựng sẵn).
- **3.4 Vector:** top-k retrieval trả về k mẩu gần nhất theo ngữ nghĩa (test: prompt "áo khoác be" → trả về ký ức/tin về "áo khoác màu be", không phải k mới nhất theo thời gian).

---

> **Ghi chú đóng:** ba tài liệu review (`STUDIO_REVIEW.md` / `STUDIO_REVIEW_DEEPDIVE.md` / `STUDIO_REVIEW_PRODUCT.md`) soi Agent Studio theo *luồng UX*, *bảo mật* và *doanh nghiệp*. File này soi theo trục còn thiếu: **nó có tự thông minh lên theo thời gian không**. Câu trả lời ngắn: **nền đã có, vòng chưa khép — và việc đáng làm nhất là đóng vòng, không phải dựng thêm hạ tầng.**
