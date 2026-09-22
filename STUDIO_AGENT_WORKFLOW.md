# FabrikAI · AGENT STUDIO → QUY TRÌNH SẢN XUẤT — BẢN ĐỒ 8 GIAI ĐOẠN + ĐIỂM NGỌT + LỘ TRÌNH MỞ RỘNG · 2026-09-22

> **File này là phần TIẾP NỐI của `STUDIO_AGENT_LEARNING.md`** (phân tích sâu Agent Studio theo trục "tự học").
> File kia trả lời *"Agent Studio có tự thông minh lên không"*; file này trả lời câu hỏi còn lại:
> *"Kiến trúc chuẩn của một luồng từ phòng thiết kế → sản xuất (Ý tưởng → Giao hàng) đã được FabrikAI phủ đến đâu, và đâu là điểm ngọt để phủ tiếp."*
>
> **Neo đo:** HEAD `4a2fca6` · 352 commit · 219 route · `app/` 120 file / 36.823 dòng (đo lại `scripts/measure.sh --no-tests` 2026-09-22 08:57).
> **Quy ước:** ✅ = đã tự đọc code · ❌ = chưa có · ⚠️ = có một phần / có mầm.
> **Nguồn nội dung:** bản thiết kế quy trình nghiệp vụ "Ý tưởng → Thiết kế → Tech Pack → BOM & Costing → Làm mẫu → Kế hoạch SX → Sản xuất → QC → Giao hàng" với AI Agent hỗ trợ xuyên suốt.

---

## §0 — TÓM TẮT ĐIỀU HÀNH: 5 điều trước khi đọc

| # | Kết luận | Bằng chứng |
|---|---|---|
| 1 | **FabrikAI phủ ĐÚNG 3/8 giai đoạn — và đó là 3 giai đoạn KHÓ nhất về mặt "con số tiền".** Ý tưởng & Thiết kế (radar/brief), BOM & Costing + Kế hoạch SX (cả hai nằm gọn trong `CollectionPlanService`), và Duyệt mẫu (shot review). 5 giai đoạn còn lại là *trạng thái + theo dõi + cảnh báo + báo cáo* — rẻ hơn nhiều so với thứ đã xong. | ✅ §1 |
| 2 | **Cái workflow gọi là "AI Agent gọi BOMCalculatorTool/CostingEstimatorTool/ProductionPlanningTool", FabrikAI đã làm TỐT HƠN: gộp 3 tool thành MỘT service TẤT ĐỊNH.** `CollectionPlanService` tính định mức vải, lệnh cắt, giá vốn, lãi gộp, 3 đợt sản xuất — *"AI viết chữ, hệ thống tính tiền"*. Đây là kiến trúc ĐÚNG cho con số tiền, không phải thiếu sót. | ✅ `CollectionPlanService.php:6-19`, §2.2 |
| 3 | **Bảng "6 điểm kiểm soát & phê duyệt" của workflow khớp 1:1 với 2 state machine FabrikAI ĐÃ có.** Duyệt concept = `ProjectWorkflowService` · Duyệt mẫu = `SHOT_TRANSITIONS` · Duyệt giá = `CollectionPlanService` (tất định). Chỉ còn *tech pack sign-off* và *duyệt QC* là 2 trạng thái cần THÊM — dùng lại đúng pattern whitelist đã chạy ổn. | ✅ `Generation.php:78-86` · `ProjectWorkflowService.php:87-98` |
| 4 | **Nguyên tắc đóng của workflow — *"AI không thay thế con người ở sáng tạo & phê duyệt"* — đã là TƯỜNG CHỊU LỰC của FabrikAI, không phải lời hứa phải xây.** Tách 2 tầng (AI trả chữ) + `REVIEWER_GATES` (người duyệt) + whitelist chặn nhảy cóc = ba cái neo đúng cái workflow đang nói. | ✅ §2.1 |
| 5 | **Gap DUY NHẤT không thể làm rẻ bằng cách khác là FileSearch** (tìm tài liệu thiết kế cũ trong kho). Nó là lý do CỤ THỂ để làm vector retrieval (việc 3.4 của file trước) — mọi thứ khác trong workflow đều làm được bằng trạng thái + luật + cấu hình sẵn có. | ✅ §2.5 |

---

## §1 — BẢN ĐỒ: 8 giai đoạn workflow → FabrikAI hiện có

### Giai đoạn 1 — Ý tưởng & Thiết kế ✅ (phủ gần hết)

| Bước workflow | FabrikAI | Bằng chứng |
|---|---|---|
| Tra cứu xu hướng qua WebSearch | ✅ `WebSearchTool` (model hỏi, máy chủ tìm) + `WebSourceService` (RSS/JSON) + `MarketSignalService` (đo từ khoá) | `WebSearchTool.php:7-27` |
| Tạo moodboard, sketch | ✅ bước Định hướng (moodboard · bảng màu · cơ cấu SKU) + prompt ảnh | `useAgentStudio.js:97-105` |
| Gợi ý chất liệu/phụ liệu | ✅ `MarketSignalService::VOCABULARY` (nhóm fabric/detail) + `CollectionPlanService::FABRIC_M` (định mức theo nhóm hàng) | `MarketSignalService.php:66-80` |
| **Tìm tài liệu thiết kế cũ (FileSearch)** | ❌ **CHƯA CÓ** — kho chỉ lưu ảnh + prompt, không tìm kiếm theo tương đồng ngữ nghĩa | (grep `FileSearch` = 0) |
| Duyệt concept | ✅ `ProjectWorkflowService` (draft→in_progress→review→approved) | `ProjectWorkflowService.php:50-98` |

### Giai đoạn 2 — Tech Pack ⚠️ (có mầm: gói xuất xưởng, chưa phải tài liệu kỹ thuật)

| Bước | FabrikAI | Bằng chứng |
|---|---|---|
| Sinh tech pack từ mô tả | ⚠️ `ProjectExportService` xuất ZIP cho xưởng: ảnh tham chiếu + **phiếu kỹ thuật** (chất liệu, màu, đường may) + **bảng size** + manifest đọc được bằng máy | `ProjectExportService.php:11-24` |
| Chỉnh thông số (đường may, chất liệu, bảng size, hướng dẫn may) | ⚠️ Có bảng size + size chart THAM CHIẾU trong `CollectionPlanService::SIZE_CHART`, nhưng **không có màn hình tech pack tương tác** | `CollectionPlanService.php:61-75` |
| Xuất PDF | ⚠️ ZIP (không PDF) | `ProjectExportService::build()` |
| Tech pack sign-off | ❌ chưa có trạng thái riêng | — |

### Giai đoạn 3 — BOM & Costing ✅✅ (phủ ĐẦY ĐỦ, tất định)

| Bước | FabrikAI | Bằng chứng |
|---|---|---|
| Tính BOM (vải, chỉ, nút, khoá, nhãn) | ✅ định mức vải theo nhóm hàng × size × khổ vải + tiêu hao; phụ liệu (`trim_cost`); `fabric_m_total` + `fabric_order_m` (lượng đặt) | `CollectionPlanService.php:200-239` |
| Tính costing (nguyên liệu, CMT, vận chuyển, lợi nhuận) | ✅ `fabric_cost + trim_cost + sewing_cost + packaging_cost` → `unit_cost` → `suggested_price_vnd` → lãi gộp | `CollectionPlanService.php:205-239` |
| Đề xuất giá (FOB) | ✅ `sell_price_vnd` + cảnh báo "giá vốn CAO HƠN dải giá thị trường" | `CollectionPlanService.php:475` |
| Duyệt giá | ⚠️ số là tất định + chủ xưởng sửa `assumptions`; **chưa có nút "chốt giá" ghi sổ** | `DesignAgentController.php:101-135` |

### Giai đoạn 4 — Làm mẫu ⚠️ (duyệt mẫu CÓ, theo dõi mẫu CHƯA)

| Bước | FabrikAI | Bằng chứng |
|---|---|---|
| May mẫu fit/PP/TOP | ❌ không có vòng đời "mẫu vật lý" | — |
| **SampleTrackingTool** (trạng thái, deadline, quá hạn) | ❌ chưa có (chỉ có `status` render pending→completed) | — |
| Đánh giá mẫu + comment | ✅ `shot_state` (idea→…→campaign_ready→approved/rejected) + `note` + duyệt theo lô | `Generation.php:38-114` |
| Duyệt mẫu (đạt → sang giai đoạn 5; không đạt → về tech pack) | ✅ whitelist cho phép `approved→campaign_ready` (quay lại) và `rejected→drafted` | `Generation.php:78-86` |
| **Ghi nhớ gu từ duyệt mẫu** | ✅ `BrandLearningService::record()` (đây là mắt xích nối "duyệt mẫu" → "trí nhớ dài hạn") | `ProjectController.php:266-270` |

### Giai đoạn 5 — Lập kế hoạch SX ⚠️ (có đợt + capacity, chưa phân line/đặt hàng)

| Bước | FabrikAI | Bằng chứng |
|---|---|---|
| Phân bổ line, tính capacity | ⚠️ `daily_capacity` → `days_total` (số ngày ra hàng); chưa phân line | `CollectionPlanService.php:277` |
| Lịch sản xuất theo ngày/tuần | ⚠️ 3 ĐỢT sản xuất (`wave-1/2/3` theo tỉ lệ 25/40/35, đợt 1 cắt mỏng để sửa sai) | `CollectionPlanService.php:82-87,282-329` |
| Cảnh báo quá tải / thiếu nguyên phụ liệu | ❌ chưa có (có `fabric_order_m` nhưng không đối chiếu tồn kho) | — |
| Duyệt kế hoạch | ❌ chưa có gate riêng | — |

### Giai đoạn 6 — Sản xuất ❌ (chưa phủ)

Theo dõi tiến độ + so thực tế với kế hoạch + cảnh báo chậm — **chưa có** (cơ chế "theo dõi" gần nhất là `Generation` status + `NotificationCenter` + email `GenerationCompleted`, nhưng đó là theo dõi RENDER, không phải sản xuất).

### Giai đoạn 7 — QC ❌ (chỉ có mầm `defect_pct`)

Checklist QC · ghi lỗi · AQL · đề xuất khắc phục — **chưa có**. Mầm duy nhất: `CollectionPlanService::DEFAULTS['defect_pct']` (tỉ lệ lỗi phải làm lại) đã tham gia giá vốn.

### Giai đoạn 8 — Giao hàng & Tổng kết ⚠️ (có báo cáo chi phí, chưa có tổng kết dự án)

| Bước | FabrikAI | Bằng chứng |
|---|---|---|
| Tổng hợp: tiến độ vs kế hoạch, chi phí vs dự toán, tỉ lệ lỗi | ⚠️ `ReportController` (chi phí theo nhóm, từ `generations.credits_cost`) — nhưng là chi phí CREDIT, chưa phải chi phí sản xuất | `ReportController.php:10-30` |
| Lưu trữ cho dự án sau | ⚠️ `brand_learning` + `shop_signals` là "tài sản cho lần sau" — nhưng chưa có "bài học dự án" | `STUDIO_AGENT_LEARNING.md` §1.3 |

---

## §2 — ĐIỂM NGỌT: đối chiếu workflow → thứ FabrikAI nên nhặt về

### Điểm ngọt 1 — "AI không thay thế con người ở sáng tạo & phê duyệt" ĐÃ là tường chịu lực, không phải việc phải xây

Workflow khép lại bằng câu: *"AI Agent không thay thế con người ở các bước sáng tạo và phê duyệt, mà đóng vai trò tăng tốc và giảm sai sót trong các tác vụ lặp lại, tính toán và tra cứu."*

FabrikAI đã hiện thực hoá đúng câu này bằng BA neo, không phải một:
1. **AI chỉ trả CHỮ** — `DesignAgentService.php:16-30` (contract 2 tầng: model không được bịa số).
2. **Người phê duyệt = `REVIEWER_GATES`** — `ProjectWorkflowService.php:98`: trạng thái `approved`/`archived` cần Super Admin.
3. **Chặn nhảy cóc** — `Generation::SHOT_TRANSITIONS` whitelist (idea không nhảy thẳng approved).

**Điểm ngọt:** đừng "bổ sung nguyên tắc" này — nó đã là nền. Việc còn lại chỉ là *mở rộng 3 neo đó sang các giai đoạn chưa phủ* (thêm gate cho tech pack / QC / kế hoạch SX bằng đúng pattern whitelist).

### Điểm ngọt 2 — "AI gọi BOMCalculatorTool/CostingEstimatorTool/ProductionPlanningTool" thực ra ĐÃ được FabrikAI làm TỐT HƠN: một service tất định

Workflow mô tả 3 tool AI gọi để tính BOM, costing và lập kế hoạch. FabrikAI **gộp cả ba vào `CollectionPlanService` và cố ý KHÔNG cho AI đụng vào con số** — `DesignAgentController::plan()` trả về `reason: 'plan_is_deterministic'` kèm dòng *"AI không tham gia vào con số"*.

Đây là điểm ngọt lớn nhất của cả bản đối chiếu: **với con số TIỀN, "AI gọi tool" là một lớp thừa.** Workflow nói *"AI Agent gọi tool để tính"*, nhưng tính tiền phải tái lập được 100% và do người bỏ vốn kiểm soát — vì vậy FabrikAI bỏ AI khỏi vòng, để thẳng một công thức tất định. Đây không phải thiếu sót; đây là kiến trúc ĐÚNG hơn.

**Hệ quả thực dụng:** các "tool" còn lại của workflow (TechPackTool, QualityControlTool…) nên đi theo **cùng nguyên tắc** — *AI viết chữ (checklist, mô tả lỗi, hướng dẫn may), hệ thống tính số (AQL, định mức, deadline)*. Không tái hiện "AI gọi tool tính tiền".

### Điểm ngọt 3 — Bảng "6 điểm kiểm soát" khớp 1:1 với 2 state machine đã có + 2 trạng thái cần thêm

| Điểm kiểm soát (workflow) | FabrikAI | Trạng thái |
|---|---|---|
| Duyệt concept | `ProjectWorkflowService::STATES` (draft→review→approved) | ✅ có |
| Duyệt mẫu | `Generation::SHOT_TRANSITIONS` + `ShotReviewTest` | ✅ có (còn ghi `brand_learning`) |
| Duyệt giá | `CollectionPlanService` (tất định + assumptions sửa được) | ⚠️ có số, thiếu nút "chốt" |
| Duyệt kế hoạch SX | — | ❌ cần thêm state |
| Tech pack sign-off | — | ❌ cần thêm state |
| Duyệt QC | — | ❌ cần thêm state |

**Điểm ngọt:** mở rộng 6 gate chỉ là *thêm trạng thái vào whitelist + policy quyền* — cái pattern (transition map + reviewer gate + audit `status_history`) đã chạy ổn và có test. Chi phí mỗi gate ≈ vài dòng state + 1 test, không phải tính năng mới.

### Điểm ngọt 4 — "Tool" đã có pattern sẵn: `WebSearchTool` là bản mẫu cho 6 tool còn lại

Workflow liệt kê 7 tool: WebSearch, FileSearch, TechPackTool, BOMCalculatorTool, CostingEstimatorTool, SampleTrackingTool, ProductionPlanningTool, QualityControlTool. **Một nửa đã tồn tại dưới tên khác** (WebSearch = `WebSearchTool` · BOM/Costing/Planning = `CollectionPlanService`). Các tool còn lại cùng MỘT hình dạng mà `WebSearchTool` đã định nghĩa:

- function-calling (model hỏi, máy chủ chạy) ✅ đã có
- "kết quả là DỮ LIỆU, không phải mệnh lệnh" (chống prompt-injection) ✅ đã có
- có trần số lần gọi ✅ đã có

**Điểm ngọt:** hạ tầng tool đã xong; thêm TechPackTool/QualityControlTool là **việc viết NỘI DUNG** (một service + một mô tả hàm), không phải xây nền. Điều này hạ giá trị/công sức của cả 5 giai đoạn còn thiếu xuống mức "vài ngày mỗi tool".

### Điểm ngọt 5 — FileSearch là gap DUY NHẤT biện minh cho vector retrieval, và là lý do CỤ THỂ cho việc 3.4

Trong toàn bộ workflow, **FileSearch** ("tìm tài liệu thiết kế cũ trong kho") là thứ DUY NHẤT **không thể** làm bằng trạng thái + luật + cấu hình. Tìm "áo khoác màu be từng thiết kế 2 mùa trước" cần *tương đồng ngữ nghĩa* — thứ mà FabrikAI hiện chỉ có dưới dạng *đếm + dò từ khoá cứng* trong `internalBrandSignal()` (tìm "be"/"beige" trong 120 prompt).

**Điểm ngọt:** đây chính là lời biện minh kinh doanh mà file trước chỉ nói chung chung. Việc 3.4 (vector + pgvector) không còn là "đúng sách vở" — nó là **điều kiện để có FileSearch**, và FileSearch là năng lực `Designer` (trong bảng vai trò) đòi hỏi ở chính Giai đoạn 1. Xếp nó vào lộ trình với một đầu bài cụ thể, không phải hạ tầng trừu tượng.

### Điểm ngọt 6 — "Theo dõi · Cảnh báo · Tổng hợp" đã có sẵn cơ chế, thiếu đúng "đối tượng để theo dõi"

Ba vai "AI Agent" còn lại (theo dõi, cảnh báo, tổng hợp) trong workflow đều có primitives sẵn:
- **Theo dõi** → `Generation.status` (pending/processing/completed/failed) + poll `syncBatchProgress()`.
- **Cảnh báo** → `NotificationCenter` + email `GenerationCompleted` (đã có từ Đợt 1.5).
- **Tổng hợp** → `ReportController` (đọc từ `generations.credits_cost`).

**Điểm ngọt:** thứ thiếu KHÔNG phải máy móc theo dõi/cảnh báo — nó là **một vòng đời "mẫu vật lý" để theo dõi** (requested → in_factory → fit → PP → TOP → approved) và **một bảng tiến độ sản xuất để so với kế hoạch**. Khi đã có đối tượng, ba vai trên gắn vào gần như miễn phí.

---

## §3 — LỘ TRÌNH MỞ RỘNG: tiếp nối 4 việc của file trước, xếp theo GIÁ TRỊ / CÔNG SỨC

> **Nguyên tắc xếp (giữ nguyên từ file trước):** nỗ lực nhỏ nhất tạo cảm giác "nó học tôi / nó điều hành giúp tôi" rõ nhất thì làm trước. Track "Tự học" = 4 việc của `STUDIO_AGENT_LEARNING.md` §3; track "Sản xuất" = từ workflow này.

| # | Việc | Track | Vì sao | Bản chất | Ước lượng |
|---|---|---|---|---|---|
| **1** | Extract→lesson (ReasoningBank) | Tự học | Nối "duyệt mẫu" → "rút kinh nghiệm" — là mắt xích duy nhất biến ghi nhớ thành thông minh | +vai `agent_reflect` +job +cột `lesson` | 2–3 ngày |
| **2** | Procedural Memory (`brand_rules`) | Tự học | Lấp loại trí nhớ thiếu ("khi công sở → trung tính"); không cần vector | Bảng nhỏ + 1 khối prompt | 1–2 ngày |
| **3** | **Tech pack tối thiểu** — nâng `ProjectExportService` thành "phiếu kỹ thuật TƯƠNG TÁC" | Sản xuất | Đã có 80% (ZIP + phiếu + bảng size); thiếu màn hình sửa thông số + xuất PDF | +form thông số + PDF | 3–4 ngày |
| **4** | **Vòng đời mẫu vật lý + SampleTracking** (requested→fit→PP→TOP→approved + deadline) | Sản xuất | Làm đối tượng để "theo dõi/cảnh báo" gắn vào; duyệt mẫu đã có sẵn | +bảng/state + job nhắc hạn | 2–3 ngày |
| **5** | Consolidate (trọng số ký ức, sao chép mẫu snapshot) | Tự học | Làm "trí nhớ" thành TRÍ NHỚ (củng cố/suy yếu) | +cột `weight` | 1–2 ngày |
| **6** | **QC tool** (checklist theo loại hàng + ghi lỗi + AQL + đề xuất khắc phục) | Sản xuất | Mầm `defect_pct` đã có; checklist do AI viết CHỮ, AQL do hệ thống tính SỐ | +service + tool + state "duyệt QC" | 3–4 ngày |
| **7** | **2 gate còn thiếu** (tech pack sign-off · duyệt kế hoạch SX · duyệt QC) | Sản xuất | 3 gate × mẫu whitelist đã chạy ổn — rẻ | +state + policy + test | 1–2 ngày |
| **8** | **Theo dõi sản xuất** (so thực tế vs kế hoạch, cảnh báo chậm) | Sản xuất | Cần sau khi có kế hoạch (việc đã có) + đối tượng mẫu (việc 4) | +bảng tiến độ + job | 3–4 ngày |
| **9** | **FileSearch = vector retrieval** (`pgvector`, nhúng prompt/tài liệu) | Tự học | Gap duy nhất cần vector; đầu bài CỤ THỂ: "tìm thiết kế cũ tương tự" | Migration + 1 service nhúng | 1 tuần+ |

> **Cố ý đặt FileSearch (vector) CUỐI** dù nó "đắt" nhất: nó chỉ đáng làm khi đã có **kho tài liệu đủ lớn để tìm** và **người dùng thật sự đau vì không tìm được**. Làm vector trước khi có lesson/procedural rule + tech pack + QC là đốt công sức vào hạ tầng chưa ai dùng — đúng cảnh báo của các review trước.
> **Cố ý KHÔNG đưa "Phân bổ line" và "đối chiếu tồn kho nguyên phụ liệu" vào lộ trình gần:** chúng cần dữ liệu NHÀ MÁY thật (line, tồn kho) mà FabrikAI chưa có connector — cùng hạng "connector TMĐT/POS/ERP" mà code đã ghi rõ là chưa chạy.

---

## §4 — BẢNG ĐỐI CHIẾU TỔNG (workflow ↔ FabrikAI ↔ việc)

| Thành phần workflow | FabrikAI hiện có | Việc cần làm |
|---|---|---|
| Vai trò 6 người (Designer…Quản lý) | Team + role + `REVIEWER_GATES` (owner/admin) | Tách vai "Technical Designer / Merchandiser / Planner / QC" nếu cần phân quyền mịn hơn |
| WebSearch / FileSearch | WebSearch ✅ · FileSearch ✅ (đợt 11) | (xong — hai chế độ: embedding + từ khoá, có trần quét) |
| TechPackTool | `ProjectExportService` (ZIP phiếu kỹ thuật) | việc 3 (tech pack tương tác) |
| BOMCalculator + CostingEstimator | `CollectionPlanService` (tất định) | (đủ; chỉ thêm nút "chốt giá") |
| SampleTrackingTool | `shot_state` duyệt mẫu + `brand_learning` | việc 4 (vòng đời mẫu vật lý) |
| ProductionPlanningTool | `CollectionPlanService` (waves + capacity) | (đủ; thêm gate duyệt) |
| QualityControlTool | mầm `defect_pct` | việc 6 |
| Theo dõi / cảnh báo / tổng hợp | status + Notification + `ReportController` | việc 8 (gắn vào đối tượng mẫu + tiến độ) |
| 6 điểm kiểm soát | concept ✅ · mẫu ✅ · giá ⚠️ | việc 7 (thêm 3 gate) |
| "AI không thay thế người" | contract 2 tầng + gate + whitelist | (đã là nền — mở rộng, không xây lại) |

---

> **Ghi chú đóng:** workflow này và file trước cùng kết luận về một điều, theo hai góc nhìn khác nhau: **nền đã có, vòng chưa khép.** File trước nói theo trục "trí nhớ" (thiếu Reflect→Extract→Consolidate); file này nói theo trục "quy trình sản xuất" (đã có duyệt mẫu + costing tất định, thiếu tech pack tương tác + theo dõi mẫu + QC + FileSearch). Cả hai trục hội tụ ở **cùng một nguyên tắc triển khai**: mở rộng bằng *trạng thái + luật + tool đã có*, đừng dựng hạ tầng mới khi chưa có người dùng đau vì thiếu nó.

---

## §5 — BẢN ĐỒ HIỆN TRẠNG (2026-09-26, sau việc #1–#6): AGENT STUDIO ↔ BỘ SƯU TẬP

> Mục §1–§4 viết ngày 2026-09-22 (trước khi làm việc #1–#6). Mục này **không viết lại lịch sử** — nó ghi
> TRẠNG THÁI ĐÃ ĐO được sau 6 việc, để phiên sau không phải suy lại từ mã.

### 5.1 Agent Studio gồm những gì
| Lớp | Nội dung | Ở đâu |
|---|---|---|
| **Trang** | Một TRANG riêng `/agent-studio` (không còn modal trong `/studio`) · bước đang làm đồng bộ lên URL `?buoc=` (gửi link là mở đúng bước, F5 không mất vị trí) | `resources/js/studio/AgentStudioApp.vue` |
| **Luồng 5 bước** | **DNA shop** (tuỳ chọn) → **Tín hiệu** (bắt buộc) → **Định hướng** (bắt buộc) → **Thực thi** (bắt buộc) → **Hỏi đáp** (tuỳ chọn, thêm 2026-09-26 — xem §5.7) | `composables/useAgentStudio.js` (`STEPS`, bước `chat` ở `:54`) |
| **Bước con** | DNA: định vị · phong cách · **quy tắc làm việc** ∥ Tín hiệu: nguồn & số đo · chọn hướng ∥ Định hướng: mô tả · số lượng SKU · bảng size · mood · đơn giá xưởng · **kế hoạch & lãi** · xem lại & chốt ∥ Thực thi: danh sách mẫu · sinh prompt từng mẫu · áp dụng & lưu | (cùng tệp, `SUBSTEPS`) |
| **5 tab của brief** | Tổng quan · Sản xuất & lãi · Mood board · Cấu trúc · Phối & size | (`BRIEF_TABS`) |
| **Hợp đồng 2 tầng** | TẦNG SỐ LIỆU tất định (mọi con số) ⊥ TẦNG SUY LUẬN (AI **chỉ trả CHỮ**). Không có model ⇒ quay về engine tất định và **nói thật** trong khối `model` (mode=rule + lý do) | `app/Services/DesignAgentService.php` |
| **Ba vai AI + 1 vai nhớ** | `agent_reason` (viết) · `agent_vision` (đọc ảnh) · `agent_search` (tra nguồn ngoài) · `agent_reflect` (rút kinh nghiệm). Bỏ trống ⇒ rơi về nhóm nền `prompt` | (cùng tệp, các `*_GROUP`) |
| **Đường API** | `design-agent/session` (GET · PUT · `/close` · `/reopen`) · `radar` · `collection` · `plan` · `shop-signals` · `sample-prompt` · `web-access` · `sources` · **`findings`** (GET · PUT — SỔ NGUỒN của công cụ tìm kiếm, 2026-09-26) · **`chat/stream`** (POST — CHAT THEO LUỒNG, 2026-09-26, `throttle:20,1`, thuộc module `collection_bot`); `brand-rules` (GET · PUT · DELETE) nằm **ngoài** nhóm `can-studio` | `routes/web.php` L261–330 · L172–174 · nhóm `auth + can-studio + nostore` ở L212 · `ModuleRegistry.php` L179 |
| **Cấp theo gói** | Module `trend_radar` + `collection_bot` (`depends_on: collections`) + `stylist` ⇒ gói **pro / studio / factory_season**. `EnforceModules` chặn ở BACKEND: ẩn nút không phải phân quyền | `app/Support/ModuleRegistry.php` L156–175 |
| **Trí nhớ** | `brand_dna` (DNA shop) · `brand_learning` (+`lesson` · `weight` · `hits` · `refreshed_at`) · `brand_rules` (quy tắc thủ tục) · `shop_signals` · `web_sources` + `market_signals` (chỉ mục nguồn) · **`web_findings`** (SỔ NGUỒN của công cụ tìm kiếm — theo TÀI KHOẢN, không dùng chung) · `projects.settings.agent_session` (bản nháp) | 7 migration 2026_09_22 → 2026_09_26 |

### 5.2 Mối liên quan với BỘ SƯU TẬP — năm sợi dây, đều đi qua bảng `projects`
| # | Sợi dây | Bằng chứng trong mã |
|---|---|---|
| 1 | **Phiên nháp LÀ một bộ sưu tập** (`MỘT BẢN NHÁP = MỘT PHIÊN`, lưu ở `projects.settings.agent_session` + cờ `settings.agent_studio`). Tên phiên ghi thẳng vào `projects.name`. Đóng/mở phiên bằng `close`/`reopen` — **cố ý không đụng `projects.status`**, mọi chuyển trạng thái phải qua `ProjectWorkflowService` | `AgentSessionController` L18 · L60–67 · L83 |
| 2 | **Chốt brief ⇒ tạo bộ sưu tập**: `createCollectionFromBrief(project_payload + settings.plan)` → đường tạo dự án chuẩn. Kế hoạch sản xuất (`store.plan`) đi CÙNG để bộ giữ trọn bản thiết kế, không chỉ dữ liệu brief | `useAgentStudio.js` L1152–1170 · `store/actions/agentStudio.js` L493 |
| 3 | **Thực thi ⇒ ảnh thuộc bộ sưu tập**: `applyCanvas()` đẩy prompt (kèm tỉ lệ · số biến thể · negative) sang ô Tạo Ảnh của Studio, ảnh sinh ra gắn `generations.project_id` | `useAgentStudio.js` L1136–1151 |
| 4 | **Sau khi có bộ, Agent Studio không rời đi**: phiếu kỹ thuật (`tech_packs`) · mẫu vật lý (`samples`) · **biên bản QC** (`qc_inspections`) · gói xuất xưởng · báo cáo chi phí — tất cả treo vào **cùng một `project_id`** | `Project` L119–173 (7 quan hệ) |
| 5 | **Vòng khép kín**: duyệt/loại ẢNH trong bộ (`shot_state`) → `BrandLearningService::record()` → job `ReflectBrandMemoryJob` rút `lesson` → `preferences()` (xếp theo `weight`) quay lại brief lần sau | `ProjectController` L209–278 · `app/Jobs/ReflectBrandMemoryJob.php` |

### 5.3 Vòng tự học 5 bước — nay đã KHÉP KÍN
| Bước | Trạng thái | Cơ chế |
|---|---|---|
| Retrieve | ✅ | `BrandLearningService::preferences()` — đọc theo **độ mạnh**, không theo mới nhất |
| Act | ✅ | `DesignAgentService::aiBrief()` (tín hiệu nội bộ có `brand_memory` + `lessons` + `brand_rules`) |
| Reflect | ✅ | `reviewShots` → `shot_state` (whitelist `Generation::SHOT_TRANSITIONS`) |
| Extract | ✅ | `ReflectBrandMemoryJob` (đợt 1) — quyết định duyệt/loại ⇒ một câu bài học |
| Consolidate | ✅ | `studio:memory:consolidate --dry-run` chạy 04:00 (đợt 8) — củng cố · suy yếu · quên |

### 5.4 Trạng thái lộ trình §3 (cập nhật, không sửa bảng gốc)
| # | Việc | Trạng thái |
|---|---|---|
| 1 | Extract→lesson (ReasoningBank) | ✅ đợt 1 |
| 2 | Procedural Memory (`brand_rules`) | ✅ đợt 2 |
| 3 | Tech pack tương tác | ✅ đợt 3 (xuất A4 để in — **không** dùng thư viện PDF: host chặn `proc_open`) |
| 4 | Vòng đời mẫu vật lý + SampleTracking | ✅ đợt 4 |
| 5 | Consolidate (trọng số ký ức) | ✅ đợt 8 |
| 6 | QC tool (checklist · lỗi · AQL) | ✅ đợt 9 |
| 7 | 3 gate còn thiếu (tech pack sign-off · duyệt kế hoạch SX · duyệt QC) | ✅ đợt 10 — `ProjectGateService` + `project_gates`; thêm luật "duyệt rồi mà dữ liệu đổi thì mất hiệu lực" |
| 8 | Theo dõi sản xuất (thực tế vs kế hoạch) | ✅ đợt 11 — `production_logs` + `ProductionTrackingService` (tiến độ · nhịp · ngày dự kiến xong · cảnh báo kèm số) |
| 9 | `FileSearch` = vector retrieval | ✅ đợt 11 — `design_embeddings` + `DesignSearchService`; đo trên production: 49/49 tài liệu, quét 49 trong 115–180 ms, chế độ embedding (qwen-paygo 1024 chiều). MariaDB không có chỉ mục vec-tơ ⇒ quét trong trần 2.000 tài liệu, có đường lùi từ khoá và **nói rõ đang chạy chế độ nào** |

### 5.5 Điều §1–§4 nói mà nay đã KHÁC (đọc kèm để không tin bản cũ)
| Chỗ | Bản 2026-09-22 | Nay |
|---|---|---|
| Giai đoạn 2 — Tech Pack | ⚠️ có mầm, chưa phải tài liệu kỹ thuật | ✅ phiếu kỹ thuật tương tác + trang in A4 |
| Giai đoạn 4 — Làm mẫu | ⚠️ duyệt mẫu CÓ, theo dõi mẫu CHƯA | ✅ `samples` + máy trạng thái + nhắc hạn 08:00 |
| Giai đoạn 7 — QC | ❌ chỉ có mầm `defect_pct` | ✅ biên bản QC + AQL; `defect_pct` nay **đối chiếu được** với lỗi thật |
| Giai đoạn 6 — Sản xuất | ❌ chưa phủ | 🟡 **một phần** (việc #8): đã có sản lượng theo ngày + tiến độ; **chưa** có chia line · đặt hàng · tồn kho NPL (cần dữ liệu nhà máy) |
| Giai đoạn 8 — Giao hàng & tổng kết | ⚠️ có báo cáo chi phí, chưa tổng kết dự án | ⚠️ **vẫn vậy** |
| Điểm ngọt 4 (§2) — *"`WebSearchTool` là bản mẫu cho 6 tool còn lại"* | bản mẫu là MỘT công cụ: model hỏi → máy chủ tìm → kết quả vào prompt, rồi HẾT (tra xong là quên) | Nay là **`AgentToolbox`** — MỘT CỔNG cho mọi công cụ (`definitions()` · `handle()` · `beginAttempt()` · `report()`) + SỔ TRÍCH DẪN `src_N`. Hàm `makeSearchTool()` **không còn trong mã**: nay là `makeToolbox()` (`DesignAgentService.php:2027`; wiring radar `:2311` · brief `:2749`) |
| §1 Giai đoạn 1 — *"Tra cứu xu hướng qua WebSearch ✅ `WebSearchTool`"* | kết quả chỉ có TIÊU ĐỀ + URL; muốn biết nội dung thì model phải gọi thêm nhiều lượt tìm (trần 5) | Kết quả tìm nay kèm **`snippet`** (`WebSearchTool.php:170`) và có thêm công cụ **`read_page`** đọc NỘI DUNG trang (`ReadPageTool.php`, trần **3 trang** mỗi lần thử) — CHỈ đọc địa chỉ đã nằm trong kết quả tìm kiếm của chính lượt đó |
| Tab **Trò chuyện** ở `/studio` (đợt 27 gọi là *"tìm thông tin & xu hướng bằng dữ liệu THẬT"*) | câu trả lời được ghép NGAY Ở TRÌNH DUYỆT bằng so khớp từ khoá trên dữ liệu radar đã có — `words()` `CanvasEmptyState.vue:141-146`, `matchScore()` `:150-153`, `ask()` `:204-259`; KHÔNG có lời gọi model nào, và ô đó chỉ được mount khi canvas TRỐNG (`StudioApp.vue:1566`) | Máy chủ đã có **CHAT THẬT theo luồng** (`AgentChatService` + `AiModelGateway::stream()`, 2026-09-26) — xem **§5.7**. **Nhưng tab cũ CHƯA được nối sang endpoint mới**, nên nó vẫn là so khớp từ khoá |
| §4 dòng *"WebSearch / FileSearch"* | tìm được nguồn rồi QUÊN: kết quả chỉ sống trong ĐÚNG một lời gọi model | Nguồn tìm được ghi vào **SỔ NGUỒN** `web_findings` theo tài khoản, lượt sau **DÙNG LẠI** được kể cả khi mạng không có gì (`recall()`), và **QUAY VỀ khối DỮ LIỆU** của Agent Studio (`mergeFindings()`, `DesignAgentService.php:999`) |

### 5.6 SỔ NGUỒN của công cụ tìm kiếm (2026-09-26) — mắt xích "tra xong không còn quên"
| Mắt xích | Cơ chế | Ở đâu |
|---|---|---|
| **LƯU** | mỗi nguồn tìm được ghi vào sổ kèm từ khoá (và bản CHUẨN HOÁ `query_key`) · vùng · đoạn trích · thời điểm; gặp lại CÙNG URL thì CẬP NHẬT và tăng `hits` (không đẻ hàng trùng) | `app/Services/WebFindingService.php:66` (`remember()`) · `:50` (`queryKey()`) |
| **DÙNG LẠI** | lượt tra mới KHÔNG ra kết quả ⇒ trả nguồn ĐÃ TRA cho cùng từ khoá, mỗi nguồn mang cờ `reused = true` kèm lời nhắc nói RÕ là nguồn cũ | `AgentToolbox.php:170-182` · `WebFindingService.php:137` (`recall()`) |
| **QUAY VỀ** | nguồn trong sổ được trộn vào CHÍNH khối DỮ LIỆU của lượt radar/brief sau; thứ tự ưu tiên: nguồn ĐÃ LƯU → tin feed mới → nguồn AI tra chưa lưu; trần tổng `EVIDENCE_LIMIT = 14` | `DesignAgentService.php:999` (`mergeFindings()`) · `WebSourceService.php:49` |
| **ĐỌC TRANG** | `read_page` chỉ đọc URL đã có trong kết quả tìm kiếm của lượt; việc đọc đi qua rào SSRF sẵn có (`assertPublicUrl` · `isPublicHost` · `options()`) | `app/Services/ReadPageTool.php` · `WebSourceService.php:329` (`fetchUrl()`) · `PAGE_MAX_CHARS = 8000` `:314` |
| **NGƯỜI DÙNG** | xem sổ và LƯU nguồn qua API; nguồn đã lưu xếp TRƯỚC trong mọi lần dùng lại và trong khối DỮ LIỆU | `DesignAgentController.php:83` · `:112` · `routes/web.php:321-324` · module `trend_radar` (`ModuleRegistry.php:163`) |

**Mặt NHÌN THẤY (đọc trong cây mã 2026-09-26):** khối **"Nguồn AI đã tra được"** ở bước Tín hiệu
(`resources/js/studio/components/agents/AgentRadarStep.vue`, trần **6 nguồn**) — dòng số đo của sổ · danh sách
nguồn bấm ra trang gốc · *"Câu hỏi đã tra"* · *"gặp N lần"* · nút **Lưu / Bỏ lưu** từng nguồn · bộ lọc
**Chỉ nguồn đã lưu** (MÁY CHỦ lọc). Dữ liệu đi qua `store/actions/agentStudio.js` (`loadFindings()` ·
`toggleFindingSaved()`) tới `GET /api/design-agent/findings` và `PUT /api/design-agent/findings/{id}`.

**Đã đo:** bản build có khối này — chuỗi *"Nguồn AI đã tra"* nằm trong `public_html/build/assets/agent-studio-B8H8iLZK.js`
(00:39, cùng `Câu hỏi đã tra` và `design-agent/findings`). Nhãn hiển thị chốt ở `docs/DESIGN_SYSTEM.md` §6.7.

**CHƯA kiểm chứng:** chưa mở trình duyệt đo bằng mắt; migration `2026_09_26_000010` CHƯA chạy ở máy chủ;
9 test của tính năng (67 assertion) đã xanh nhưng **toàn bộ suite chưa chạy lại** trong phiên viết tài liệu này.

### 5.7 CHAT THEO LUỒNG của Agent Studio (2026-09-26) — "hỏi đáp thật", không còn là so khớp từ khoá

#### a) Bản cũ nói X, nay là Y
| Chỗ | Bản cũ | Nay |
|---|---|---|
| **Chat** trong sản phẩm | Thứ duy nhất mang tên "chat" là tab **Trò chuyện** ở `/studio`: câu trả lời ghép **ngay ở TRÌNH DUYỆT** bằng so khớp từ khoá trên dữ liệu radar đã có. Ba hàm chứng minh: `words()` (`CanvasEmptyState.vue:141-146`), `matchScore()` (`:150-153`), `ask()` (`:204-259`). KHÔNG có lời gọi model nào để TRẢ LỜI (đường tìm kho thiết kế cũ có gọi model nhúng để tìm) — người dùng tưởng đang hỏi AI, thực tế là TRUY HỒI + XẾP HẠNG. Ô đó còn bị chôn sau điều kiện "canvas TRỐNG" (`StudioApp.vue:1566`) | **Máy chủ có chat THẬT theo luồng**: `POST /api/design-agent/chat/stream` → `AgentChatService::chat()` → `AiModelGateway::stream()`. Chữ chảy về theo từng mảnh, lượt chạy **có công cụ web**, và nguồn tra được **vào SỔ NGUỒN** như mọi lượt radar/brief |
| **Tầng model** | *"Tầng model KHÔNG có streaming"* — câu này nằm ở `KE_HOACH_TOOL_WEB_VA_CHAT_STREAM.md` §0 hàng 2 · §0 đính chính 3 · §1.2 (viết 2026-09-23, đúng với mã lúc đó) | Nay có **`AiModelGateway::stream()`** (`app/Services/AiModelGateway.php:251`), đọc SSE `data: …` của `/chat/completions` (`streamOnce()` `:462`). Tài liệu kế hoạch đã được đánh dấu **ĐÍNH CHÍNH [2026-09-26]** tại chỗ, KHÔNG viết lại kế hoạch |

#### b) Ba lớp của đường chat (đọc từ mã)
| Lớp | Việc | Ở đâu |
|---|---|---|
| **Cửa HTTP** | Sáu bước của khuôn NDJSON đã chạy production ở `/api/suggest/stream`: validate (**422 TRƯỚC khi mở luồng**) · `$live = ! app()->runningUnitTests()` · `@set_time_limit(180)` · `@ob_end_flush()` + `@ob_flush()`/`@flush()` · `application/x-ndjson` + `X-Accel-Buffering: no` · bắt `Throwable` ⇒ log + sự kiện `error` (KHÔNG ném giữa luồng) | `app/Http/Controllers/AgentChatController.php:32-82` |
| **Nghiệp vụ** | Dựng chỉ dẫn (DNA + quy tắc làm việc + luật công cụ) · chuẩn hoá hội thoại (12 lượt × 4.000 ký tự) · phát sự kiện `phase`/`token`/`tool`/`tool_result`/`citation`/`provider`/`result`/`error` · trả khối `result` nói THẬT `streamed` | `app/Services/AgentChatService.php:60` · `:264-292` · `:300-323` |
| **Cổng model** | `stream()` → `streamConversation()` → `streamOnce()`: vòng lặp công cụ GIỮ NGUYÊN (mỗi vòng đọc theo luồng, lượt CUỐI không gửi `tools`), mảnh `tool_calls` **cộng dồn theo `index`**, provider không chảy chữ thì rơi về `callPlain` + `streamed=false` | `app/Services/AiModelGateway.php:251` · `:320` · `:462` · cộng dồn `:562-573` · rơi về `:359-378` · `:503-521` |

#### c) Vì sao chat dùng CHUNG bộ công cụ với radar/brief (đây là quyết định, không phải tiện tay)
`AgentChatService` dựng công cụ bằng **`new AgentToolbox(...)->withSearch()`** (`AgentChatService.php:80`) — ĐÚNG lớp mà `DesignAgentService` dùng cho radar và brief. Hệ quả, cả ba đường cùng chia:

| Chia gì | Nghĩa là |
|---|---|
| **SỔ TRÍCH DẪN `src_N`** | Nguồn chat tra được cấp mã ổn định y như ở radar/brief; `read_page` chỉ đọc được địa chỉ ĐÃ có trong sổ của chính lượt đó |
| **SỔ NGUỒN `web_findings`** | Nguồn chat tra được **ghi vào sổ của tài khoản** (`stored`), lượt radar/brief sau **dùng lại** được — test số 2 khoá đúng mắt xích này (`stored = 1` + có hàng `WebFinding` đúng `url` và `query`) |
| **Trần của công cụ** | `web_search` 5 lượt/lần thử (`WebSearchTool.php:40`) · `read_page` 3 trang/lần thử (`ReadPageTool.php:28`) · 8.000 ký tự/trang (`WebSourceService.php:314`) — chat KHÔNG tự đặt trần riêng, nên không có hai chuẩn để lệch nhau |

**Vì sao:** một bộ công cụ thứ hai cho chat là hai chỗ để lệch nhau; và nguồn chat tra được mà không vào sổ thì lại đúng cái lỗi "tra xong rồi quên" mà đợt 36 vừa vá.

#### d) Trần của một lượt chat (số lấy từ hằng số trong mã)
| Trần | Giá trị | Ở đâu |
|---|---|---|
| Cả lượt | **45 giây** | `AgentChatService.php:31` |
| Token trả lời | **1.200** | `:33` |
| Hội thoại | **12 lượt** × **4.000 ký tự** | `:36` · `:38` |
| Vòng công cụ | **1** (1 vòng tra + 1 vòng trả lời) | `:41` |
| Throttle | **20 lượt/phút** | `routes/web.php:330` |

#### e) Giao diện — ĐÃ CÓ trong mã, và nó xuất hiện GIỮA phiên viết tài liệu này
| Thành phần | Ở đâu | Ghi chú |
|---|---|---|
| Miền store `agentChat` | `resources/js/studio/store/actions/agentChat.js` (**302 dòng**, tạo **01:00** ngày 2026-09-23) | Vòng đọc NDJSON bê nguyên cách đã chạy production của `sources.js`; `AbortController` giữ ở **biến cấp module** (state Pinia phải tuần tự hoá được); sự kiện `provider` **bị bỏ hẳn**, không vào state hiển thị; cờ `stopped`/`failed` **giữ phần chữ đã nhận** |
| Khung `AgentChatStep.vue` | `resources/js/studio/components/agents/AgentChatStep.vue` (**242 dòng**, **01:01**) | Nút **Dừng** khi đang chảy (`:216-224`) · **trích dẫn là link thật** `target="_blank" rel="noopener"` (`:156-179`) · dòng số đo + cảnh báo lấy từ khối `result` của MÁY CHỦ |
| Bước **«Hỏi đáp»** trong luồng | `useAgentStudio.js:54` (nhãn «Hỏi đáp», `required: false`, đặt CUỐI) · render `AgentStudioApp.vue:359` · `:392` | Agent Studio nay có **5 bước**: `dna · radar · brief · canvas · chat` |
| State + nối vào store | `resources/js/studio/store/state.js:301-307` (7 khoá `agentChat*`) · `store.js:15` · `:36` | Cùng khuôn với miền `suggest*` đã có |
| Bản build | `public_html/build/assets/agent-studio-HFIlmlOe.js` (**01:02**, 213.093 byte) · endpoint ở chunk `pageBoot-LtBE6p5P.js` | Chứa `Hỏi đáp` ×2 · `Hội thoại mới` · `không hiện dần` · `nguồn đã tra trước đó` · `Nguồn để bạn tự kiểm`. **CHƯA deploy** |

⚠️ **Mốc thời gian quan trọng:** khi mục §5.7 này bắt đầu viết, hai tệp giao diện **chưa tồn tại** và `STEPS` mới có 4 bước.
Một tiến trình SONG SONG tạo chúng lúc **01:00–01:01** rồi đóng gói lại bản build lúc **01:02**; phiên viết tài liệu
này ĐỌC LẠI mã và cập nhật mục này. Mọi `file:dòng` ở bảng trên là đọc ở **01:0x**.

#### f) PHẦN CHƯA LÀM — nói thẳng
1. **Tab Trò chuyện cũ ở `/studio` CHƯA nối sang endpoint mới** — nó vẫn so khớp từ khoá ở trình duyệt (`CanvasEmptyState.vue:204-259`). Hai đường chat cùng tồn tại là trạng thái TẠM, không phải đích.
2. **Lịch sử hội thoại CHƯA lưu phía máy chủ.** Client phải **gửi lại** tối đa 12 lượt gần nhất mỗi lần hỏi (`MAX_TURNS`; phía client `agentChat.js:26` · `:61-69`); đóng trình duyệt là mất hội thoại, nút «Hội thoại mới» chỉ xoá ở màn hình. Kế hoạch §B5 đề xuất nhét vào `projects.settings.agent_session` — CHƯA làm.
3. **Chữ chảy ở MỌI vòng, không phải chỉ vòng cuối như kế hoạch đề xuất.** Kế hoạch §B1 chọn "chỉ stream token ở vòng CUỐI" để tiết kiệm; mã hiện tại truyền CÙNG một `$onDelta` cho MỌI vòng (`AiModelGateway.php:357`) và `streamOnce` luôn đặt `'stream' => true` (`:464`). Hệ quả phải biết: `$text` cộng dồn qua mọi vòng (`:384`), nên câu chữ đệm trước khi gọi công cụ cũng nằm trong `result.text`.

#### g) Đã kiểm chứng tới đâu
```
vendor/bin/phpunit --no-coverage --filter AgentChatStreamTest
.......                                                             7 / 7 (100%)
OK (7 tests, 63 assertions)
```
**CHƯA kiểm chứng:** chưa chạy lại toàn bộ suite (một tiến trình khác đang chạy) · **chưa deploy production** nên KHÔNG có phép đo "chữ có chảy thật không" trên máy chủ thật · **chưa mở trình duyệt đo bằng mắt** (mọi câu về giao diện ở §5.7(e) đọc từ mã + bản build, không phải ảnh chụp màn hình).
