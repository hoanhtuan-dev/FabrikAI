# KẾ HOẠCH — BỘ CÔNG CỤ WEB + CHAT STREAMING CHO DEEPSEEK (AGENT STUDIO)

**Ngày:** 2026-09-23 · **Trạng thái:** ĐỀ XUẤT — CHƯA VIẾT MỘT DÒNG CODE NÀO · **Repo:** FabrikAI (Laravel 13 + Vue 3/Pinia)
**Yêu cầu gốc:** *"viết tools giúp DeepSeek có thể tìm kiếm trên web và chat với người dùng mượt mà"*.

> **[TRẠNG THÁI 2026-09-26 — đọc TRƯỚC tài liệu này]** Bản kế hoạch viết 2026-09-23 khi chưa có dòng mã nào.
> Đến 2026-09-26 **PHẦN B đã được triển khai** (bộ công cụ của PHẦN A cũng xong từ đợt 36), và vì vậy **BA CÂU
> trong tài liệu này đã SAI so với mã hiện tại** — chúng được đánh dấu `[ĐÍNH CHÍNH 2026-09-26]` NGAY TẠI CHỖ
> (§0 hàng 2 · §0 đính chính 3 · §1.2 hàng *"Streaming ở tầng model"*), KHÔNG xoá câu cũ: đây là bản ghi lúc
> khảo sát. Toàn bộ trạng thái từng đợt nằm ở **§11**. **Không câu nào ở §1–§10 bị viết lại.**

> Tài liệu này là bản KHẢO SÁT HIỆN TRẠNG + ĐỀ XUẤT. Mọi kết luận đều kèm `file:dòng` đọc được trong mã.
> Chỗ nào CHƯA kiểm chứng thì ghi thẳng **CHƯA XÁC MINH** — không suy đoán.

---

## 0. BA VIỆC ĐƯỢC YÊU CẦU — VÀ SỰ THẬT ĐỌC ĐƯỢC TỪ MÃ

| # | Việc | Hiện trạng ĐỌC ĐƯỢC | Kết luận |
|---|---|---|---|
| 1 | Tool `web_search` để model TỰ GỌI qua function calling | **ĐÃ CÓ và chạy thật**: `WebSearchTool` + vòng lặp chuẩn OpenAI trong `AiModelGateway::callWithTools()` | **MỞ RỘNG** — không xây mới |
| 2 | Chat (hội thoại) streaming mượt cho người dùng | **CHƯA CÓ**: tab "Trò chuyện" hiện có chỉ **khớp từ khoá ở TRÌNH DUYỆT**, không gọi model, không stream; tầng model **không có streaming** | **XÂY MỚI** |
| 3 | Bộ tool mở rộng: tìm web · đọc trang · lưu nguồn · trích dẫn | 1/4 đã có (tìm web). "Đọc trang", "lưu kết quả tìm", "trích dẫn có id" **CHƯA CÓ** | **THÊM 3** |

> **[ĐÍNH CHÍNH 2026-09-26 — cột "Hiện trạng" ở hàng 2 nay KHÔNG còn đúng]** Tầng model **ĐÃ CÓ streaming**
> (`app/Services/AiModelGateway.php:251` — `stream()`, đọc SSE `data: …` của `/chat/completions` tại
> `streamOnce()` `:462`) và máy chủ **ĐÃ CÓ chat thật theo luồng** (`app/Services/AgentChatService.php` ·
> `app/Http/Controllers/AgentChatController.php` · `POST /api/design-agent/chat/stream`, `routes/web.php:329-330`).
> Phần **CHƯA** của hàng này nay là **giao diện**: tab Trò chuyện ở `/studio` vẫn khớp từ khoá ở trình duyệt
> (`resources/js/studio/components/CanvasEmptyState.vue:204-259`); còn giao diện chat MỚI thì **ĐÃ CÓ trong mã**
> (bước **«Hỏi đáp»**, `AgentChatStep.vue` + `store/actions/agentChat.js`, mốc **01:00–01:02 ngày 2026-09-23**)
> nhưng **CHƯA deploy** — xem §11.2. **Câu cũ giữ nguyên** vì đây là bản ghi khảo sát ngày 2026-09-23.

### Ba ĐÍNH CHÍNH TIỀN ĐỀ (đọc trước khi bàn giải pháp)

1. **"Cần thêm tool web_search" — KHÔNG ĐÚNG NỮA.** Công cụ đã tồn tại và đã nối vào 2 lượt chạy:
   `app/Services/WebSearchTool.php:31` (`NAME = 'web_search'`), `:66` (`definition()` — schema function-calling),
   `:122` (`handle()` — chạy thật, KHÔNG BAO GIỜ ném lỗi), `:214` (`report()` — số đo cho giao diện),
   và vòng lặp `app/Services/AiModelGateway.php:516` (`callWithTools()`: gửi `tools` + `tool_choice:'auto'` ở `:558`,
   đọc `choices.0.message.tool_calls` ở `:581`, trả kết quả bằng `role: "tool"` ở `:621`).
   Lượt chạy đã bật công cụ: **radar** (`app/Services/DesignAgentService.php:2191-2195`) và **brief** (`:2620-2623`).

2. **"Tab trò chuyện" hiện KHÔNG phải chat.** `resources/js/studio/components/CanvasEmptyState.vue` có tab
   `chat` (`:29`, nhãn "Trò chuyện về xu hướng" `:367`), nhưng câu trả lời được **ghép ở trình duyệt** bằng
   so khớp từ khoá (`words()` `:141-146`, `matchScore()` `:150-153`, `ask()` `:204-259`) trên dữ liệu radar
   đã có. Nó gọi 3 đường KHÔNG có model nào: `POST /api/design-agent/radar`, `GET /api/design-agent/sources`,
   `GET /api/design-search`. Và ô này chỉ được mount khi canvas TRỐNG (`StudioApp.vue:1566`).
   ⇒ Người dùng đang tưởng mình "chat với AI" trong khi thực tế là **truy hồi + xếp hạng**.

3. **Tầng model KHÔNG có streaming.** `grep 'stream'` trong `app/Services/AiModelGateway.php` (1.107 dòng) = **0 kết quả**.
   Mọi lời gọi đều blocking: `postChat()` `:911`, `/responses` `:702`. Thứ duy nhất chạy được ở production là
   **NDJSON SỰ KIỆN** của `StudioController::suggestStream()` (`:2191-2275`) — nó stream **tiến trình**, còn
   kết quả vẫn về một cục ở sự kiện `result`.

> **[ĐÍNH CHÍNH 2026-09-26]** Ba câu trong mục 3 này KHÔNG còn đúng với mã hiện tại: `grep 'stream'` trong
> `app/Services/AiModelGateway.php` (nay **1.483 dòng**) **KHÔNG còn là 0 kết quả** — tệp có `stream()`
> (`:251`), `streamConversation()` (`:320`), `streamOnce()` (`:462`). Chat KHÔNG còn "chỉ có NDJSON sự kiện":
> nó phát **cả sự kiện tiến trình LẪN chữ chảy theo từng mảnh** (`type: "token"`), và kết quả cuối vẫn là một
> khối `result` — tức là CẢ HAI điều cùng đúng. Câu cũ giữ nguyên vì đây là bản ghi khảo sát 2026-09-23.

### Một LỆCH TÀI LIỆU cần sửa luôn khi làm (nợ có thật)
`DEPLOY_LOG.md:2428` ghi *"trần `MAX_CALLS=3` mỗi lần thử"*, nhưng mã hiện tại là **5**
(`app/Services/WebSearchTool.php:40`, kèm chú thích "2026-09-21: nâng 3 → 5"). Tài liệu đang nói ngược mã.

> **[ĐÃ SỬA — 2026-09-26]** Món nợ này đã trả: `DEPLOY_LOG.md` nay ghi **`MAX_CALLS=5`** ở CẢ HAI chỗ nói về mã
> (bảng thay đổi của phiên 2026-09-24 và mục "Ba ràng buộc"), kèm khối **ĐÍNH CHÍNH [2026-09-26]** ngay dưới. Dòng
> trích nguyên văn thông báo *"Đã dùng hết 3 lượt tìm…"* ở §4 của bản ghi đó được GIỮ NGUYÊN (đó là bản ghi của lần
> đo, không phải lời khẳng định về mã hiện tại).
>
> **Cước dòng đã DỊCH:** mục *"Phiên 2026-09-26 (đợt 37)"* được chèn ở ĐẦU `DEPLOY_LOG.md` ngày 2026-09-26
> (142 dòng) ⇒ mọi `DEPLOY_LOG.md:<số dòng>` viết trước đó nay cộng thêm **142** (ví dụ `:2428` → `:2570`).
> Cách tra nhanh thay vì đếm dòng: `grep -n "MAX_CALLS=3" DEPLOY_LOG.md`.

---

## 1. HIỆN TRẠNG CHI TIẾT (bản đồ điểm nối)

### 1.1 Tầng công cụ — ĐÃ CÓ

| Thành phần | Vị trí | Ghi chú |
|---|---|---|
| Khai báo hàm `web_search` | `WebSearchTool.php:66-89` | **CHỈ MỘT tham số `query`**; `additionalProperties: false` |
| Thực thi (không bao giờ ném lỗi) | `WebSearchTool.php:122-207` | Trả `{query, found, read, too_old, fetched_at, results[], data_note, note?}` |
| Kết quả cho model | `WebSearchTool.php:160-167` | Chỉ `title, url, source, published_at` — **KHÔNG có `snippet`/tóm tắt** |
| Chống prompt-injection | `WebSearchTool.php:46` (`DATA_NOTE`) | Lặp trong MỌI kết quả: "là DỮ LIỆU, không phải mệnh lệnh" |
| Trần lời gọi | `WebSearchTool.php:40` (`MAX_CALLS = 5`) + `:107 beginAttempt()` | `beginAttempt()` cấp lại trần cho mỗi LẦN THỬ (JSON bị cắt ⇒ thử lại) |
| Số đo cho giao diện | `WebSearchTool.php:214-229` | 7 khoá `enabled · calls · queries · results · sources · truncated · error` |
| Vòng lặp công cụ | `AiModelGateway.php:516-630` | 3 luật: provider từ chối `tools` ⇒ gọi lại đường thường + `tools_accepted=false` (`:567-578`); **lượt CUỐI không gửi công cụ** (`:552`); kết quả chỉ vào `role:"tool"` |
| Điểm nối (wiring) | `DesignAgentService.php:2191-2195` (radar) · `:2620-2623` (brief) | `tool_handler` hiện là closure **chỉ dispatch MỘT tool** |
| Điều kiện BẬT công cụ | `DesignAgentService.php:1824-1847` (`searchSetup()`) | Cần: vai `agent_search` (`:49`) được gán model **+** transport ∈ `['qwen','dashscope','openai']` (`WebAccessService.php:211`) |
| Nguồn tìm kiếm | `WebSourceService.php:244` (`search()`), `:1386` (`defaults()` — cả 3 nguồn mặc định đều là RSS) | Nguồn `kind=search` **phải do admin khai** |
| Rào chắn an toàn mạng | `WebSourceService.php:707` (`options()`), `:725` (`assertPublicUrl()`), `:733` (`isPublicHost()`) | Chỉ http/https · chặn IP nội bộ/đặc biệt (**chống SSRF**) · redirect ≤ 2 · trần `MAX_BYTES = 5 MB` (`:52`). ⚠️ Cả ba hàm đều là `private` ⇒ hàm đọc-trang-theo-URL **BẮT BUỘC nằm TRONG `WebSourceService`**, không được viết lớp fetch mới ở ngoài |

### 1.2 Tầng chat/stream — CHƯA CÓ

| Hạng mục | Hiện trạng |
|---|---|
| Endpoint hội thoại | **KHÔNG CÓ**. `grep 'chat'` trong `routes/` = 0. Route stream duy nhất của repo: `POST /api/suggest/stream` (`routes/web.php:417`) |
| Streaming ở tầng model | **KHÔNG CÓ** (xem đính chính 3) — **[ĐÍNH CHÍNH 2026-09-26: nay CÓ. `AiModelGateway::stream()` `app/Services/AiModelGateway.php:251`; chi tiết ở §11.1]** |
| State hội thoại ở client | **KHÔNG CÓ** (`store/state.js` không có khoá `chat/message/thread`; `messages` là `ref` CỤC BỘ trong `CanvasEmptyState.vue:121`) |
| Lịch sử nhiều lượt | **KHÔNG CÓ** — mỗi câu hỏi là một lượt độc lập |
| SSE / `EventSource` | **KHÔNG CÓ** ở bất kỳ file `resources/js` nào |
| Huỷ giữa chừng | **KHÔNG CÓ** — `suggestStyleStream` chạy tới `done`; `api()` có tham số `signal` (`store/actions/account.js:16`) nhưng chưa nơi nào truyền |
| Lưu nguồn / citation bền vững | **KHÔNG CÓ** — kết quả tìm chỉ sống trong prompt và trong đệm radar (`DesignAgentService.php:2335`); bảng `web_sources` là **CRUD cấu hình của admin** (`AdminWebSourceController::store`) |

### 1.3 Khuôn mẫu PHẢI tái dùng (đã chạy production)

**Server** — `StudioController.php:2191-2275` (hợp đồng ghi ngay tại docblock `:2185-2187`):

```php
// Lỗi TRƯỚC khi mở stream vẫn trả JSON 422 bình thường (client đọc res.ok).   // :2213
// Trong test, Laravel bắt nội dung stream bằng output buffer của chính nó —   // :2232
$live = ! app()->runningUnitTests();

return response()->stream(function () use ($live) {
    @set_time_limit(180);
    if ($live) { while (ob_get_level() > 0) { @ob_end_flush(); } }
    $write = function (array $event) use ($live): void {
        echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        if ($live) { if (ob_get_level() > 0) { @ob_flush(); } @flush(); }
    };
    // …
}, 200, [
    'Content-Type' => 'application/x-ndjson; charset=utf-8',
    'Cache-Control' => 'no-cache, no-store, must-revalidate',
    'X-Accel-Buffering' => 'no',
]);
```

**Client** — `resources/js/studio/store/actions/sources.js:146-213` (`suggestStyleStream` + `_handleSuggestEvent`).
Bốn đặc điểm phải giữ: `decoder.decode(..., { stream: !done })` · `buf = lines.pop()` giữ dòng dở ·
xử lý nốt `buf` sau `done` · nhánh `401/redirected` + fallback JSON khi không có `ReadableStream`.
Nhãn hiển thị đi qua `safeMessage()` **tại biên** (`:200`, `:210`).

**Ranh giới kiến trúc**: service KHÔNG biết gì về HTTP/NDJSON — nó chỉ `$emit`
(`StyleSuggestService::suggest(..., ?callable $onProgress = null)`, `StyleSuggestService.php:22`). Controller lo định dạng.

---

## 2. KIẾN TRÚC ĐỀ XUẤT

```
Người dùng hỏi
   │
   ▼
[Client] AgentChatStep.vue ── fetch NDJSON ──► POST /api/design-agent/chat/stream   (auth + can-studio + throttle)
   ▲                                              │
   │  phase · tool · tool_result · token ·        ▼
   │  citation · result · error            [Controller] ChatStreamController (mỏng: validate → mở stream)
   │                                              │
   │                                              ▼
   │                                    [Service] AgentChatService
   │                                       · dựng system prompt (DNA + radar + luật nguồn)
   │                                       · gọi gateway ở chế độ STREAM
   │                                       · chạy tool qua ToolRegistry (search · read_page)
   │                                       · giữ SỔ TRÍCH DẪN (id ổn định) cho lượt này
   │                                              │
   │                                              ▼
   │                                    [Gateway] AiModelGateway::stream()
   │                                       · vòng công cụ: các vòng CÓ tools chạy như hiện tại
   │                                       · vòng CUỐI (không gửi tools) = vòng trả lời ⇒ mở SSE token
   │                                              │
   │                                              ▼
   │                                    [Tool] WebSearchTool (mở rộng +snippet)
   │                                           ReadPageTool (MỚI — đi qua WebSourceService)
   └──── NDJSON: từng token hiện ra ────────────┘
```

**Điểm khớp quan trọng nhất của thiết kế**: `callWithTools()` **đã có sẵn luật "lượt CUỐI không gửi công cụ"**
(`AiModelGateway.php:552,583` — chú thích `:508-509`: *"model buộc phải trả lời bằng dữ liệu đang có"*).
Vòng cuối đó CHÍNH LÀ vòng trả lời cho người dùng ⇒ **chỉ cần stream token ở vòng cuối**, còn các vòng công cụ
giữ nguyên blocking và phát sự kiện `tool`/`tool_result`. Nhờ vậy:

* vòng lặp công cụ (phần khó nhất: ghép `tool_calls` từ delta) **không phải viết lại**;
* thời gian ra chữ đầu tiên = thời gian tra web + 1 lời gọi model (không phải chờ cả JSON);
* vẫn giữ được `web_search` — điều mà đường SDK không làm được (xem §2.1).

### 2.1 Vì sao KHÔNG đi đường SDK cho chat
`laravel/ai v0.11.2` CÓ `Promptable::stream()` (`vendor/laravel/ai/src/Promptable.php:102`), nhưng:
* `App\Ai\RegistryAgent` **không** implements `HasTools` (`app/Ai/RegistryAgent.php:23`) ⇒ đi SDK là **MẤT công cụ**;
* `StreamsText.php` ném `InvalidArgumentException('Streaming structured output is not currently supported.')` khi agent có structured output ⇒ **stream + JSON mode là hai đường chưa gặp nhau**;
* `SdkTextEngine` hiện cũng chỉ gọi `prompt()` (`SdkTextEngine.php:69`), và chính mã ghi rõ "không gọi công cụ tìm kiếm".

⇒ Chọn: **viết `AiModelGateway::stream()` ở tầng HTTP tự viết** (đọc SSE `data:` của `/chat/completions`),
nơi đã có đủ ràng buộc cần giữ: `applyThinkingOff()` (`:924`), `applySearch()` (`:952`), `remainingSeconds()` (`:473`),
`chatBase()` (`:484`), `lastAttempts()` (`:234`).

---

## 3. PHẦN A — BỘ CÔNG CỤ

### A1. Mở rộng `web_search` (KHÔNG đổi giao diện hàm)

| Việc | Chi tiết | Vì sao |
|---|---|---|
| Thêm `snippet`/`summary` vào kết quả trả model | `WebSearchTool.php:160-167` — bổ sung trường đã có sẵn trong item (`WebSourceService::parseRss()` `:968`, `summary_field`) | Model hiện chỉ thấy TIÊU ĐỀ ⇒ muốn biết nội dung phải gọi thêm lần nữa, mà trần chỉ 5 |
| Giữ NGUYÊN một tham số `query` | Không thêm `region`/`site`/`limit` ở v1 | `region` do MÁY CHỦ truyền (`:192` radar, `:2621` brief "all"); thêm tham số là tăng khả năng model gọi sai |
| Ghi `findings` vào sổ trích dẫn của lượt | xem A4 | Cho citation + lưu nguồn |
| Sửa lệch tài liệu | `DEPLOY_LOG.md:2428` (3 → 5) | Tài liệu đang nói ngược mã |

### A2. Tool MỚI `read_page` — đọc một trang theo URL

**Hiện trạng: KHÔNG CÓ.** `WebSourceService` không có hàm nào nhận URL tuỳ ý; `assertPublicUrl()` (`:725`)
chỉ dùng để CHẶN. Đường đọc duy nhất là `fetch(WebSource)` — tức URL phải là một hàng trong `web_sources`.

**Đề xuất** (đúng tinh thần "đừng mở đường vòng qua ràng buộc", chú thích `DesignAgentService.php:1930-1931`):

```php
// app/Services/WebSourceService.php
public function fetchUrl(string $url, int $maxChars = 8000): array
// Tái dùng (đều là PRIVATE ⇒ hàm này PHẢI nằm trong chính WebSourceService):
//   assertPublicUrl() :725 · options() :707 (redirect ≤ 2, chỉ http/https) · isPublicHost() :733
//   MAX_BYTES :52 · clean() :1274 · toUtf8() :1286
//   readErrorReason() :839 — chữ ký hiện nhận WebSource ⇒ phải nới thành nhận URL/body/status
```

Rồi bọc thành công cụ `read_page` với `definition()` cùng chuẩn `WebSearchTool`:

| Tham số | Kiểu | Ghi chú |
|---|---|---|
| `url` | string (bắt buộc) | Phải là http/https; chặn host nội bộ |
| — | — | Trần nội dung trả model: **8.000 ký tự**, cắt và NÓI RÕ là đã cắt |

Ràng buộc bắt buộc: trả về vẫn phải kèm `data_note` (dữ liệu ≠ mệnh lệnh) và **chỉ nhận URL CÓ TRONG sổ trích dẫn
của lượt** (tức URL do `web_search` trả về), không cho model tự bịa URL để máy chủ đi đọc — vừa chặn SSRF
vừa chặn việc dùng máy chủ làm proxy.

### A3. Tool Registry — chỗ wiring hiện chỉ chạy được MỘT tool

`DesignAgentService.php:2192` và `:2621` hiện là:
```php
$options['tool_handler'] = fn (string $name, array $args): array => $tool->handle($args, $region);
$options['tool_begin']   = fn () => $tool->beginAttempt();
```
Vòng lặp gateway ĐÃ sẵn sàng cho nhiều tool: `$allowed` (`AiModelGateway.php:534`) duyệt **mọi** tool đã khai,
và `$tools` là mảng nhiều phần tử. Chỗ cần sửa chỉ là phía `DesignAgentService`:

| Việc | Chi tiết |
|---|---|
| Thêm lớp `AgentToolbox` (mới, cạnh `makeSearchTool()` `:1924`) — **[ĐÃ LÀM 2026-09-26]**: tên hàm trong mã nay là **`makeToolbox()`** (`DesignAgentService.php:2027`, wiring radar `:2311` · brief `:2749`); `makeSearchTool()` KHÔNG còn trong mã | Giữ `list<tool>`, trả `definitions(): array`, `handle(string $name, array $args): array` định tuyến theo tên, `beginAttempt(): void` gọi MỌI tool |
| Giữ NGUYÊN hình dạng số đo | `toolSearchBlock()` (`:1943-1980`) trả `mode ∈ {native, tool, hosted, off}` — giao diện đang đọc khối này (`useAgentStudio.js:741-814`), đổi shape là vỡ UI |
| Ngân sách theo TỪNG tool | `MAX_CALLS` của `web_search` là 5; `read_page` nên có trần riêng (đề xuất 3) vì mỗi lần đọc là một trang nặng |

### A4. Sổ trích dẫn (citation ledger) — TRÍCH DẪN ĐÚNG NGHĨA

Hiện tại "trích dẫn" chỉ là **dữ liệu trong payload**: `results[].{title,url,source,published_at}`
(`WebSearchTool.php:160-167`), còn `report()['sources']` là **nhãn nguồn dạng CHUỖI, không phải URL** (`:170-175`).

Đề xuất:
1. Mỗi kết quả tìm được cấp **id ổn định trong lượt** (`src_1`, `src_2`…), giữ nguyên trong mọi lần gọi lại của cùng lượt.
2. Prompt được phát kèm danh sách có STT (`[1] tiêu đề — url`) và yêu cầu model chỉ được dẫn nguồn CÓ TRONG danh sách
   (luật này ĐÃ CÓ: `DesignAgentService.php:2145`, `:2572`).
3. Payload trả client mang theo `citations: [{id, title, url, source, published_at}]` để UI render link THẬT.
4. **KHÔNG** nhét danh sách trích dẫn vào phần chỉ dẫn hệ thống — luật 3 của gateway (`AiModelGateway.php:510-511`).
5. Điểm đã kiểm URL thật: `applyTrendChecks()` (`DesignAgentService.php:1168`) — đổi từ so-URL sang so-id.

### A5. Lưu nguồn — DÙNG BẢNG MỚI, KHÔNG cho model ghi vào `web_sources`

| Phương án | Đánh giá |
|---|---|
| Cho model ghi vào `web_sources` | **KHÔNG NÊN**: đây là bảng CẤU HÌNH của admin (`AdminWebSourceController::store:44`), có `slug`, `priority`, `keywords`, và các trường này ảnh hưởng tới MỌI người dùng khác. Model ghi vào đây = leo thang quyền |
| Bảng mới `agent_findings` (owner-scoped) | ✅ **ĐÃ LÀM 2026-09-26 — tên THẬT là `web_findings`** (đổi tên khi viết mã: sổ này là NGUỒN WEB, không phải finding chung). Cột thật: `id, user_id, query, query_key, region, url, url_hash, title, source_name, snippet, published_at, hits, first_seen_at, last_seen_at, saved_at`. Ghi tự động khi tìm được (upsert theo `user_id + url`), người dùng bấm "Lưu nguồn" mới đánh dấu `saved_at` |
| Không bảng nào (chỉ sống trong lượt + đệm) | Rẻ nhất, nhưng "lưu nguồn" của yêu cầu không được đáp ứng |

Migration đặt cạnh `database/migrations/2026_09_23_000006_create_web_sources_table.php`.
⚠️ Repo yêu cầu **sao lưu DB trước khi migrate** (`DEPLOY.md:227-228`).

### A6. Một cạm bẫy cấu hình phải nói rõ

Công cụ CHỈ bật khi `searchSetup()` (`:1824-1847`) thấy vai `agent_search` (`:49`) ĐƯỢC GÁN MODEL và
transport ∈ `['qwen','dashscope','openai']` (`WebAccessService.php:211`). Nghĩa là: **nếu production để trống vai
"Agent Studio — Tìm kiếm nguồn ngoài" thì dù mã có tool, model cũng KHÔNG BAO GIỜ được gọi nó.**
Đây đúng là lỗi tinh thần đã ghi ở `WebSearchTool.php:10-14`. ⇒ Việc đầu tiên khi triển khai là kiểm tra
`php artisan studio:web-access --force` (`WebAccessCheck.php`) và gán đúng vai.

---

## 4. PHẦN B — CHAT STREAMING

### B1. Transport: GIỮ NDJSON (không đổi sang SSE)

| Tiêu chí | NDJSON (đang dùng) | SSE |
|---|---|---|
| Client đã có khuôn | ✅ `sources.js:146-172` | ❌ phải viết mới |
| Test đã khoá | ✅ `SuggestStreamTest.php:101-103` (`json_decode` từng dòng) | ❌ phải sửa cả test cũ |
| Đổi giữa chừng | không cần | tốn công, lợi ích ~0 (client vẫn đọc byte thô) |

⇒ **NDJSON**, `Content-Type: application/x-ndjson; charset=utf-8`, mỗi dòng một object JSON, `X-Accel-Buffering: no`.

### B2. Hợp đồng sự kiện (wire protocol) — CHỐT TRƯỚC KHI CODE

| `type` | Payload | Ai đọc | Luật nhãn |
|---|---|---|---|
| `phase` | `{key, label}` — `key ∈ prepare\|context\|thinking\|answering\|saving` | Người dùng | **TUYỆT ĐỐI sạch**: không provider/model/http/json (test regex `SuggestStreamTest.php:132`) |
| `tool` | `{name, query?/url?}` | Người dùng ("Đang tra: …") | Tên tool là TIẾNG VIỆT ở tầng nhãn, KHÔNG phơi `web_search`/`read_page` |
| `tool_result` | `{name, found, sources[]}` | Người dùng (đếm nguồn) | Chỉ số đo, không nội dung thô |
| `token` | `{text}` | Người dùng | Chính là chữ của model |
| `citation` | `{id,title,url,source,published_at}` | Người dùng (link thật) | URL chỉ được là URL ĐÃ QUA `assertPublicUrl` |
| `provider` | `{provider,model,transport,keys}` | Khối kỹ thuật (ẩn) | Đây là chỗ DUY NHẤT được nêu chi tiết kỹ thuật — đúng tiền lệ `StudioController.php:2186` |
| `result` | `{data:{text, citations[], tool_search{}, model{}, elapsed_ms}}` | Người dùng | Sự kiện **CUỐI** khi thành công |
| `error` | `{message}` | Người dùng | Câu hướng dẫn; chi tiết thô CHỈ vào `logger()` (`StudioController.php:2260-2268`) |

### B3. Trần & hạn chót (chống lặp lại lỗi 504 đã ghi ở `AiModelGateway.php:518-523`)

| Tham số | Đề xuất cho chat | Vì sao |
|---|---|---|
| `deadline_ts` | truyền xuống gateway | Đã có sẵn cơ chế (`remainingSeconds()` `:473`) |
| Trần tổng lượt chat | **45 s** | Nhỏ hơn radar (60 s, `AI_CALL_CEILING_MS` `DesignAgentService.php:94`) vì chat phải "mượt" |
| `tool_rounds` | **2** | Mặc định gateway là 3 (`AiModelGateway.php:532`); chat không cần quay vòng |
| Trần lời gọi tool | 3 cho `web_search`, 2 cho `read_page` | Mỗi lời gọi là một lần người dùng chờ |
| Lịch sử gửi lên | tối đa **12 lượt**, mỗi lượt ≤ 4.000 ký tự | Chống đốt token + chống prompt rác |
| `max_tokens` câu trả lời | 1.200 | Đủ cho câu trả lời chat |

### B4. Endpoint, route, phân quyền

| Việc | Chi tiết | Bắt buộc? |
|---|---|---|
| Route | `POST /design-agent/chat/stream` trong nhóm `auth + can-studio + nostore` + `prefix api` (`routes/web.php:211-440`), cạnh `:299-317` | ✅ |
| Throttle | `throttle:20,1` — theo đúng chuẩn nhóm (`radar` 30/phút, `sample-prompt` 90/phút). **KHÔNG** lặp lại nợ của `/api/suggest*` (đang KHÔNG có throttle dù mỗi lượt là một lời gọi model trả tiền) | ✅ |
| Gating theo gói | Phải khai `design-agent/chat/stream` vào `ModuleRegistry.php` (`endpoints` của `trend_radar` `:160` hoặc `collection_bot` `:173`). **Khai thiếu = route KHÔNG bị chặn theo gói** (`EnforceModules` chỉ soi những gì `ModuleRegistry::modulesForUri()` `app/Support/ModuleRegistry.php:418-441` biết) | ✅ |
| Controller | Tách `ChatStreamController` (mỏng) hoặc thêm action vào `DesignAgentController`. Bắt buộc sao chép **đủ 6 bước** của `suggestStream` — gồm cả `$live = ! app()->runningUnitTests();` | ✅ |
| Test tĩnh | `StaticIntegrityTest` (`:22` route→class@method, `:46` trùng tên route) chạy NGAY khi thêm route | tự động |

### B5. Lịch sử hội thoại — v1 KHÔNG cần migration

`AgentSessionController` đã lưu bản nháp phiên vào `projects.settings.agent_session` (blob JSON, `show/store/close/reopen`,
`routes/web.php:290-294`). Đề xuất v1: **nhét lịch sử chat vào chính blob đó** ⇒ không migration, không bảng mới,
người dùng mở máy khác vẫn thấy hội thoại. Chỉ khi cần tìm kiếm/lịch sử dài mới tách bảng `agent_chats`.

### B6. Giao diện — làm Ở ĐÂU?

| Phương án | Ưu | Nhược |
|---|---|---|
| **(b1)** Nâng cấp tab "Trò chuyện" sẵn có ở `/studio` (`CanvasEmptyState.vue:367`) thành chat thật | Người dùng đã thấy nó; ít việc UI | Ô này bị chôn sau điều kiện "canvas TRỐNG" (`StudioApp.vue:1566`) — phải tách ra khỏi `CanvasEmptyState` hoặc đổi điều kiện mount |
| **(b2)** Thêm mục chat vào Agent Studio | Đúng chỗ cho "trợ lý thiết kế" (có DNA, radar, brief trong tay) | `useAgentStudio.js` đã 2086 dòng |

**ĐỀ XUẤT: làm (b2) là CHÍNH** (chat có ngữ cảnh DNA/radar/brief ⇒ trả lời sát việc), **và** nối (b1) sang cùng
endpoint để ô chat cũ không còn "giả vờ" là AI.

Cấu trúc file đề xuất:

| File | Mới/Sửa | Việc |
|---|---|---|
| `resources/js/studio/components/agents/AgentChatStep.vue` | **MỚI** | Khung chat: log cuộn, `textarea` + nút Hỏi, khối trích dẫn (link thật), nút Dừng. Bê template từ `CanvasEmptyState.vue:367-476`; tiến trình dùng `LoadingSpinner.vue` (`text/subtext/progress`) — quy ước `SuggestCard.vue:14`: KHÔNG tự vẽ bộ chấm riêng |
| `resources/js/studio/store/actions/agentChat.js` | **MỚI** | `agentChatStream(payload, signal)` — bê **nguyên** vòng đọc `sources.js:146-172` + bộ phân giải sự kiện `_handleSuggestEvent` kiểu `:191-213`. KHÔNG dùng `this.api()` (nó ép `Accept: application/json`) |
| `resources/js/studio/store/state.js` | SỬA | Khối `agentChat*` theo khuôn `suggest*` (`:286-293`): `agentChatMessages · agentChatPhase · agentChatPhaseLabel · agentChatStreaming · agentChatError · agentChatCitations · agentChatToolLine` |
| `resources/js/studio/store.js` | SỬA | Spread `agentChatActions` vào `actions` |
| `resources/js/studio/composables/useAgentStudio.js` | SỬA | Thêm state/hàm chat vào **CẢ HAI** danh sách: `provideAll()` (`:1629`) **và** `return` (`:1857`) |
| `resources/js/studio/AgentStudioApp.vue` | SỬA | Thêm bước/tab chat vào `STEPS` render (`:351-354`), rail (`:198-215`), pill màn hẹp (`:274-288`) |
| `resources/js/studio/components/CanvasEmptyState.vue` | SỬA (đợt sau) | Trỏ `ask()` (`:204-259`) sang endpoint thật, hoặc bỏ hẳn đường khớp từ khoá |
| `tests/TestCase.php` | SỬA | `designAgentsSource()` (`:25-40`) hiện chỉ đọc 3 nhóm tệp (`AgentStudioApp.vue` + `composables/useAgentStudio.js` + `components/agents/*.vue`). Nếu chat dùng file mới ngoài 3 nhóm đó thì **test nhãn sẽ KHÔNG quét tới** ⇒ mở rộng thêm `composables/*.js` |

**Mượt mà — cụ thể là gì** (để không nói suông):
1. Chữ hiện dần theo `token` (cảm giác phản hồi < 1 s sau khi tra xong web).
2. Trong lúc tra: dòng trạng thái THẬT *"Đang tra: xu hướng tweed 2026"* + số nguồn đã đọc (lấy từ `tool_result`).
3. Nút **Dừng** (AbortController — client đã có sẵn tham số `signal` ở `account.js:16`, chỉ chưa ai dùng).
4. Trích dẫn là **link thật** ngay dưới câu trả lời.
5. Không có chuyện "đang suy luận…" treo vô hạn: mọi nhánh đều bị chặn bởi trần thời gian (bài học `CanvasEmptyState.vue:155-162` — đã từng thấy quá 100 giây mà giao diện vẫn hiện "Đang đọc tín hiệu…").
6. Render văn bản: repo **CHƯA có thư viện Markdown** (xem `package.json`) ⇒ v1 render văn bản thuần + danh sách trích dẫn; muốn Markdown thì phải thêm thư viện và test an toàn XSS.

---

## 5. RỦI RO & CÁCH CHẶN

| Rủi ro | Mức | Cách chặn |
|---|---|---|
| Proxy/FPM đệm làm stream "đứng" rồi trào một cục | CAO | Đã có công thức chạy thật: `@ob_end_flush` + `@ob_flush/@flush` + `X-Accel-Buffering: no` (`StudioController.php:2239-2252`). `DEPLOY_LOG.md:2974-2985` ghi **ĐO THẬT**: sự kiện tiến trình về client sau ~80 ms |
| Một lượt chat giữ worker PHP-FPM 45 s | CAO | Trần 45 s + `set_time_limit(180)` + throttle 20/phút; cân nhắc tách hàng đợi nếu tải cao |
| Stream + vòng lặp công cụ chồng nhau (khó nhất) | TRUNG BÌNH | Chỉ stream token ở **vòng cuối** (vòng không gửi `tools` — luật đã có sẵn `AiModelGateway.php:552`) |
| Provider không hỗ trợ `stream: true` / trả JSON một cục | TRUNG BÌNH | Phát hiện `Content-Type` không phải `text/event-stream` ⇒ rơi về đọc cả body rồi phát 1 sự kiện `token` + `result`. Thêm test riêng |
| Prompt injection qua nội dung trang web | CAO | Giữ `DATA_NOTE` (`WebSearchTool.php:46`) + kết quả CHỈ vào `role:"tool"` (`AiModelGateway.php:621`) + `read_page` chỉ nhận URL có trong sổ trích dẫn |
| SSRF qua `read_page` | CAO | Bắt buộc đi qua `assertPublicUrl()`/`isPublicHost()`/`options()` (`WebSourceService.php:707-733`); KHÔNG viết lớp fetch mới |
| Model bịa nguồn khi 0 kết quả | CAO | Giữ luật "0 kết quả ⇒ nói THẲNG, TUYỆT ĐỐI không bịa" (`WebSearchTool.php:183-204`) + `applyTrendChecks()` `:1168` kiểm URL có thật |
| Chat lỗi mà không biết VÌ SAO | TRUNG BÌNH | `AiModelGateway` hiện **không bao giờ đọc thân lỗi** của provider (`callPlain` `:810-880` chỉ `! successful()`) ⇒ thêm đọc `error.message` vào `lastAttempts()` (`:234`) |
| Đốt token (chat nhiều lượt × tool) | TRUNG BÌNH | Trần lượt/lịch sử ở §B3 + `throttle` + đệm theo (từ khoá · nguồn) đã có ở `WebSourceService.php:354` |
| Nhãn tiến trình lộ chi tiết kỹ thuật | THẤP nhưng ĐỎ TEST | Mọi nhãn đi qua `safeMessage()`; provider/model CHỈ ở sự kiện `provider` |
| Không có nguồn `kind=search` mặc định | TRUNG BÌNH | `WebSourceService::defaults()` (`:1386`) hiện cả 3 nguồn đều RSS ⇒ `web_search` phụ thuộc admin khai nguồn tìm kiếm. Cân nhắc thêm 1 nguồn search mặc định |

---

## 6. KẾ HOẠCH TEST (viết test TRƯỚC, mỗi luật một test)

| Test mới | Rào chắn điều gì | Khuôn để bê |
|---|---|---|
| `tests/Feature/AgentChatStreamTest.php` | (a) có `phase` + `provider`; (b) sự kiện CUỐI là `result`; (c) MỌI `label` sạch theo regex `/(deepseek\|qwen\|gemini\|dashscope\|replicate\|fal\|veo\|wan\|flux\|provider\|model\|http\s*\d{3}\|json)/i`; (d) provider lỗi ⇒ `type:error` + câu hướng dẫn KHÔNG lộ tên provider; (e) input sai ⇒ **422 TRƯỚC khi mở stream**; (f) có ít nhất 1 sự kiện `token` và chúng ghép lại ≠ rỗng; (g) lượt có công cụ ⇒ có `tool` + `tool_result`; (h) `citation` chỉ chứa URL có thật trong kết quả tìm | `tests/Feature/SuggestStreamTest.php:90-137` |
| `test_stream_handles_a_provider_that_ignores_streaming` | Provider trả JSON một cục ⇒ vẫn ra `result`, KHÔNG treo | `fallbackWithoutHostedSearch` (`AiModelGateway.php:719`) |
| Bổ sung `tests/Feature/ToolSearchTest.php` (1.224 dòng, 33 test) | Với từng tool mới: "chạy THẬT và kết quả vào prompt" · "provider từ chối `tools` ⇒ KHÔNG nói là đã đọc" · "trần lượt gọi" · "0 kết quả ⇒ `found:0` + câu 'TUYỆT ĐỐI không được bịa'" · "`read_page` từ chối URL nội bộ" | `:127`, `:210`, `:240`, `:301` |
| `UserFacingMessagesTest` (bổ sung) | Nhãn chat không nêu provider/model; state lỗi phải qua `userFacingError()/safeMessage()` | `:138-163` |
| `DesignSystemTest` | Thêm nhãn mới ⇒ phải viết vào `docs/DESIGN_SYSTEM.md §6`, nếu không test ĐỎ | `docs/DESIGN_SYSTEM.md:443` |
| `StaticIntegrityTest` | Route mới: class@method tồn tại, không trùng tên route — **chạy tự động** | `:22`, `:46` |

⚠️ Bẫy đã ghi trong repo: `Http::fake()` **CỘNG DỒN** stub chứ không thay thế (`DEPLOY.md:1857-1859`) — test stream
dùng nhiều URL phải đăng ký MỘT lần.

---

## 7. LỘ TRÌNH ĐỀ XUẤT (4 đợt, mỗi đợt tự đứng được)

> **TRẠNG THÁI [2026-09-26]:** đợt **1 · 2 · 3** đã triển khai ở tầng MÁY CHỦ, đợt **4** đã làm (bảng
> `web_findings` + sửa `MAX_CALLS 3 → 5`). Bảng dưới **giữ NGUYÊN như bản đề xuất**; trạng thái từng đợt và
> những chỗ mã làm KHÁC kế hoạch nằm ở **§11**.

| Đợt | Việc | Sản phẩm nhìn thấy được | Điều kiện XONG |
|---|---|---|---|
| **1. Nền chat** | `AiModelGateway::stream()` (đọc SSE, chỉ stream ở vòng cuối) · `POST /api/design-agent/chat/stream` + route + throttle + ghi `ModuleRegistry` · `AgentChatService` (system prompt + ngân sách) · UI `AgentChatStep.vue` + store action (bê khuôn NDJSON) | Chat thật, chữ hiện dần, KHÔNG cần công cụ web | Test `AgentChatStreamTest` XANH · `phpunit` toàn bộ XANH · `npm run build` + commit `public_html/build/**` · vào `DEPLOY_LOG.md` |
| **2. Chat có công cụ** | `AgentToolbox` (dispatch nhiều tool) · sự kiện `tool`/`tool_result` · trần riêng từng tool · nút Dừng (AbortController) | Trả lời "đang tra gì, đọc được mấy nguồn" NGAY trong chat | Test: lượt có công cụ ⇒ có `tool`+`tool_result`; provider từ chối `tools` ⇒ nói thật |
| **3. Đọc trang + trích dẫn** | `WebSourceService::fetchUrl()` (đi qua rào SSRF sẵn có) · tool `read_page` · sổ trích dẫn (`src_N`) · `citations[]` trong payload + render link | Model đọc được nội dung trang, mỗi câu có nguồn bấm được | Test `read_page` từ chối host nội bộ; `citation` khớp URL thật |
| **4. Lưu nguồn + dọn nợ** — ✅ ĐÃ LÀM 2026-09-26 (bảng thật `web_findings`) | Bảng `web_findings` (owner-scoped) + nút "Lưu nguồn" · sửa `DEPLOY_LOG.md:2428` (MAX_CALLS 3→5) · nối tab chat cũ ở `/studio` sang endpoint thật · (tuỳ chọn) đọc `error.message` của provider vào `lastAttempts()` | Nguồn đã đọc nằm trong thư viện, xem lại được | Migration + sao lưu DB; tài liệu khớp mã |

---

## 8. QUY TRÌNH TRIỂN KHAI (theo `DEPLOY.md §7`, nguyên thứ tự)

```bash
php artisan config:clear
vendor/bin/phpunit --no-coverage     # TOÀN BỘ suite phải XANH
npm run build                        # CHẠY SAU TEST — có sửa resources/js|css là BẮT BUỘC
git status                           # phải SẠCH (build tất định: build 2 lần ra cùng hash)
```

* Máy chủ production **KHÔNG có node/npm** ⇒ `public_html/build/**` **phải commit** (`DEPLOY.md:31`).
* Có route mới ⇒ `php artisan route:cache` khi deploy (`DEPLOY.md:223`).
* Có migration ⇒ sao lưu DB trước (`DEPLOY.md:227-228`).
* Mọi phiên phải ghi `DEPLOY_LOG.md` (mẫu gần nhất cho đúng loại việc này: `DEPLOY_LOG.md:2961` — chính `/api/suggest/stream`).
* Tính năng người dùng nhìn thấy ⇒ cập nhật `HUONG_DAN_TINH_NANG_MOI.md`; năng lực agent đổi ⇒ `STUDIO_AGENT_WORKFLOW.md §5`.

---

## 9. VIỆC CẦN CHỐT TRƯỚC KHI CODE (cần bạn quyết)

1. **Chat đặt ở đâu?** Agent Studio (đề xuất chính) — và có nâng cấp luôn tab Trò chuyện ở `/studio` không?
2. **Token thật hay chỉ sự kiện?** Đề xuất: token thật, nhưng CHỈ ở vòng cuối (rẻ hơn nhiều so với stream cả vòng lặp công cụ).
3. ~~**Có làm bảng `agent_findings`** cho "lưu nguồn" không?~~ → **Đã quyết và đã làm (2026-09-26)**: bảng `web_findings` theo tài khoản + nút Lưu/Bỏ lưu trên màn hình Tín hiệu.
4. **`read_page` có được bật ở radar/brief** (không chỉ chat) không? Bật = tốn thêm thời gian chờ ở 2 lượt đang chạy 30-55 s.
5. **Trần lượt gọi tool trong chat**: đề xuất 3 (`web_search`) + 2 (`read_page`).
6. **Có sửa luôn việc đọc `error.message` của provider** vào `lastAttempts()` không? (chat cần biết vì sao hỏng).

> **[SÁU CÂU HỎI NÀY ĐÃ ĐƯỢC MÃ TRẢ LỜI — 2026-09-26; KHÔNG phải chờ quyết nữa]** (1) Chat đặt ở **Agent Studio**:
> `POST /api/design-agent/chat/stream` (`routes/web.php:329-330`), module `collection_bot` — nhưng **giao diện chưa
> có trong mã**. (2) Chốt **token THẬT**, và **ở MỌI vòng** chứ không chỉ vòng cuối — xem §11.3 hàng 1.
> (3) Đã làm: bảng `web_findings`. (4) **CÓ bật ở radar/brief**: `makeToolbox()` gọi `withSearch()` cho MỌI lượt có
> công cụ (`DesignAgentService.php:2054` · `AgentToolbox.php:75-86`), nên `read_page` chạy cả ở radar/brief.
> (5) Chốt **1 vòng công cụ** (`AgentChatService::TOOL_ROUNDS = 1`, `AgentChatService.php:41`) — thay cho đề xuất
> 2 vòng + trần riêng từng tool ở §B3. (6) **CHƯA kiểm chứng** trong phiên viết tài liệu này — không kết luận.

---

## 10. PHỤ LỤC — `file:dòng` THEN CHỐT

| Việc | Vị trí |
|---|---|
| Khai báo tool cho model | `app/Services/WebSearchTool.php:66` |
| Thực thi tool | `app/Services/WebSearchTool.php:122` |
| Số đo tool cho giao diện | `app/Services/WebSearchTool.php:214` |
| Vòng lặp function-calling | `app/Services/AiModelGateway.php:516` (luật "lượt cuối không gửi tools" `:552`) |
| Cửa gọi model | `app/Services/AiModelGateway.php:140` (`text()`) |
| Gọi model + chạy tool ở radar | `app/Services/DesignAgentService.php:2191-2195` |
| Gọi model + chạy tool ở brief | `app/Services/DesignAgentService.php:2620-2623` |
| Quyết định BẬT tool | `app/Services/DesignAgentService.php:1824-1847` |
| Khối số đo gửi client | `app/Services/DesignAgentService.php:1943-1980` |
| Mẫu stream NDJSON (server) | `app/Http/Controllers/StudioController.php:2191-2275` |
| Mẫu đọc stream NDJSON (client) | `resources/js/studio/store/actions/sources.js:146-213` |
| State tiến trình mẫu | `resources/js/studio/store/state.js:286-293` |
| Rào chắn SSRF/trần byte | `app/Services/WebSourceService.php:52`, `:707`, `:725`, `:733` |
| Gating theo gói | `app/Support/ModuleRegistry.php:160`, `:173`, `:418-441` |
| Test khoá nhãn/stream | `tests/Feature/SuggestStreamTest.php:90-137` |
| Test công cụ tìm kiếm | `tests/Feature/ToolSearchTest.php` (33 test) |
| Nhóm tệp test đọc | `tests/TestCase.php:25-40` |

---

## 11. TRẠNG THÁI PHẦN B — ĐÃ TRIỂN KHAI TỚI ĐÂU (2026-09-26)

> Mục này **CHỈ thêm TRẠNG THÁI**. Không câu nào ở §0–§10 bị viết lại. Chỗ nào mã làm khác kế hoạch thì ghi rõ
> **"kế hoạch nói X, mã nay là Y"** — để phiên sau không đọc bản đề xuất rồi tin là bản mô tả hiện trạng.

### 11.1 Ba câu trong tài liệu này đã SAI so với mã (đã đánh dấu tại chỗ)
| Chỗ | Câu cũ (khảo sát 2026-09-23) | Sự thật trong mã (2026-09-26) |
|---|---|---|
| §0 bảng hàng 2 | *"tầng model không có streaming"* | **CÓ**: `AiModelGateway::stream()` (`app/Services/AiModelGateway.php:251`), `streamConversation()` (`:320`), `streamOnce()` (`:462`) |
| §0 đính chính 3 | *"`grep 'stream'` trong `AiModelGateway.php` (1.107 dòng) = **0 kết quả**"* — tệp nay **1.483 dòng** | `stream` có mặt ở nhiều chỗ; cả ba hàm stream ở trên |
| §1.2 hàng *"Streaming ở tầng model"* | **KHÔNG CÓ** | **CÓ** — xem hàng đầu |

### 11.2 Bốn đợt của §7 — XONG tới đâu
| Đợt | Nội dung đề xuất | Trạng thái | Bằng chứng |
|---|---|---|---|
| **1. Nền chat** | `AiModelGateway::stream()` · `POST /api/design-agent/chat/stream` + route + throttle + `ModuleRegistry` · `AgentChatService` · UI `AgentChatStep.vue` + store action | ✅ **XONG CẢ HAI** (máy chủ + giao diện) — ⚠️ UI do tiến trình SONG SONG viết, xuất hiện GIỮA phiên viết tài liệu (mốc **01:00–01:02** ngày 2026-09-23) | Máy chủ: `AiModelGateway.php:251` · `routes/web.php:329-330` · `ModuleRegistry.php:179` · `AgentChatService.php` · `AgentChatController.php`. UI: `resources/js/studio/store/actions/agentChat.js` (**302 dòng**, 01:00) · `components/agents/AgentChatStep.vue` (**242 dòng**, 01:01) · bước `chat` nhãn «Hỏi đáp» `useAgentStudio.js:54` · render `AgentStudioApp.vue:359` · build `agent-studio-HFIlmlOe.js` (01:02) |
| **2. Chat có công cụ** | `AgentToolbox` (dispatch nhiều tool) · sự kiện `tool`/`tool_result` · trần riêng từng tool · **nút Dừng** (AbortController) | ✅ **XONG CẢ HAI** | `AgentChatService.php:97-135` (phát `tool`/`tool_result`) · `AgentToolbox.php:112` (`handle()`) · `AgentToolbox.php:75-86` (`withSearch()`) · **nút Dừng: CÓ** — `AgentChatStep.vue:216-224` gọi `agentChatStop()` (`agentChat.js:168-177`), abort qua `AbortController` ở biến cấp module (`:36`) và **giữ phần chữ đã nhận** |
| **3. Đọc trang + trích dẫn** | `WebSourceService::fetchUrl()` · tool `read_page` · sổ trích dẫn `src_N` · `citations[]` trong payload + render link | ✅ **XONG CẢ HAI** (link đã render ở `AgentChatStep.vue:156-179`, `target="_blank" rel="noopener"`) | `WebSourceService.php:329` (`fetchUrl()`) · `ReadPageTool.php:25` (`NAME`) · `AgentToolbox.php:217` (`citations()`) · `AgentChatService.php:127-134` (sự kiện `citation`) · `:165` (`citations[]` trong `result`) |
| **4. Lưu nguồn + dọn nợ** | Bảng `web_findings` · nút "Lưu nguồn" · sửa `DEPLOY_LOG.md` MAX_CALLS 3→5 · **nối tab chat cũ ở `/studio` sang endpoint thật** | 🟡 **XONG 3/4** | `web_findings` + `WebFindingService` (đợt 36) · `DEPLOY_LOG.md` đã sửa kèm khối ĐÍNH CHÍNH · **tab chat cũ CHƯA nối**: `CanvasEmptyState.vue:204-259` vẫn khớp từ khoá ở trình duyệt |

### 11.3 Kế hoạch nói X — mã nay là Y (MƯỜI điểm lệch, đọc để không tin bản đề xuất)
| # | Kế hoạch (§) | Mã hiện tại | Vì sao lệch |
|---|---|---|---|
| 1 | **§B1 · §9 câu 2:** *"token thật, nhưng CHỈ ở vòng CUỐI"* (để rẻ hơn nhiều so với stream cả vòng lặp công cụ) | **Chữ chảy ở MỌI vòng.** `streamConversation()` truyền CÙNG một `$onDelta` cho mọi vòng (`AiModelGateway.php:357`) và `streamOnce()` luôn đặt `'stream' => true` (`:464`) | Đã đọc SSE thì mảnh chữ đã về tới máy chủ — vứt đi là viết thêm mã để GIẤU thông tin, mà người dùng thì thấy agent "im lặng" trong lúc nó đang nói. **Hệ quả phải biết:** `$text` cộng dồn qua mọi vòng (`:384`) nên câu chữ đệm trước khi gọi công cụ cũng nằm trong `result.text`. Đây là **lệch CÓ Ý THỨC**, không phải bỏ sót |
| 2 | **§B3:** trần tổng lượt chat **45 s** | **45 s** — ĐÚNG kế hoạch | `AgentChatService.php:31` |
| 3 | **§B3:** `tool_rounds` = **2** | **1** (`TOOL_ROUNDS = 1`) | "Chat không phải nơi quay vòng tìm kiếm" (`AgentChatService.php:40-41`) — 1 vòng tra + 1 vòng trả lời |
| 4 | **§B3:** trần lời gọi tool **3 (`web_search`) + 2 (`read_page`)** | **Không đặt trần riêng cho chat**: giữ nguyên trần CỦA CÔNG CỤ — `web_search` **5** lượt/lần thử, `read_page` **3** trang/lần thử | Một bộ công cụ dùng chung (chat · radar · brief) thì trần phải là của công cụ, nếu không sẽ có hai chuẩn để lệch nhau |
| 5 | **§B4:** controller tên `ChatStreamController` | **`AgentChatController`** | Đổi tên khi viết mã; hành vi y kế hoạch (6 bước của `suggestStream`) |
| 6 | **§B4:** khai endpoint vào `trend_radar` **hoặc** `collection_bot` | Chốt **`collection_bot`** | Lý do ghi ngay trong mã: chat là phần "hỏi đáp trong lúc làm bộ sưu tập", đọc DNA + quy tắc và dùng chung bộ công cụ với brief (`ModuleRegistry.php:176-179`) |
| 7 | **§B5:** lịch sử hội thoại nhét vào `projects.settings.agent_session` (⇒ mở máy khác vẫn thấy) | **CHƯA lưu phía máy chủ.** Mỗi lượt, client phải **gửi lại** tối đa 12 lượt gần nhất (`AgentChatService::MAX_TURNS`, `:36`); `MAX_TOKENS`… không liên quan, `normaliseMessages()` cắt còn 12 lượt gần nhất (`:317`) | Việc của đợt sau; đóng trình duyệt hiện là **mất hội thoại** |
| 8 | **§B2:** khoá `phase.key` ∈ `prepare\|context\|thinking\|answering\|saving` | `prepare` · `context` · `thinking` · **`searching`** · **`reading`** · **`done`** | Thêm hai khoá để phân biệt "đang TRA" với "đang ĐỌC một trang" — người dùng nhìn thấy khác nhau (`AgentChatService.php:108`) |
| 9 | **§B2:** `citation` payload `{id, title, url, source, published_at}` | **`{ref, title, url, source_name, published_at, …}`** — khoá mã trích dẫn là **`ref`**, giá trị dạng `src_1` | `AgentToolbox` đã có sẵn tên gọi `ref` cho mã `src_N` (`AgentToolbox.php:217`); đổi tên ở tầng chat là đổi hai chỗ |
| 10 | **§B2:** *"Tên tool là TIẾNG VIỆT ở tầng nhãn, KHÔNG phơi `web_search`/`read_page`"* | **Nhãn `phase` thì SẠCH** (tiếng Việt, có test regex khoá), **nhưng sự kiện `tool` vẫn mang `name` KỸ THUẬT thô** (`web_search` / `read_page`) | Lệch ở TẦNG GIAO THỨC nhưng **không lộ ra màn hình**: `agentChat.js:205-210` đổi tên thô thành câu tiếng Việt (*"Đang đọc nội dung một trang…"* / *"Đang tra: …"*) trước khi hiển thị, và sự kiện `provider` thì bị BỎ HẲN (`agentChat.js:229-231`). Giữ `name` thô trong sự kiện là để tầng giao diện còn phân biệt được hai công cụ |

### 11.4 Còn nợ của PHẦN B (nói thẳng)
| # | Việc | Trạng thái đo được lúc viết |
|---|---|---|
| 1 | Giao diện chat (`agentChat.js` · `AgentChatStep.vue` · bước chat trong `useAgentStudio.js`) | ✅ **ĐÃ CÓ trong mã** (tiến trình SONG SONG viết, mốc **01:00–01:02** ngày 2026-09-23): `agentChat.js` 302 dòng · `AgentChatStep.vue` 242 dòng · `STEPS` nay **5 bước** (`useAgentStudio.js:54`). **CHƯA deploy** và **chưa đo bằng mắt trên trình duyệt** |
| 2 | Nút **Dừng** (AbortController) | ✅ **ĐÃ CÓ** — `AgentChatStep.vue:216-224` · `agentChat.js:168-177` |
| 3 | Nối tab Trò chuyện cũ ở `/studio` sang endpoint thật | **CHƯA** — vẫn khớp từ khoá ở trình duyệt (`CanvasEmptyState.vue:204-259`) |
| 4 | Lịch sử hội thoại lưu phía máy chủ | **CHƯA** (client gửi lại 12 lượt gần nhất) |
| 5 | Deploy production + đo "chữ có chảy thật không" | **CHƯA deploy** — không có phép đo nào trên máy chủ thật |
| 6 | Chạy lại TOÀN BỘ suite | **CHƯA** (một tiến trình khác đang chạy); riêng `AgentChatStreamTest` = **7 test XANH (63 assertion)** |

### 11.5 Bộ công cụ (PHẦN A) — trạng thái, để §11 này đứng một mình vẫn đủ
| Mục | Đề xuất | Trạng thái |
|---|---|---|
| A1 `snippet` trong kết quả `web_search` | thêm trường đã có sẵn trong item | ✅ `WebSearchTool.php:170` |
| A1 sửa lệch tài liệu `MAX_CALLS 3 → 5` | `DEPLOY_LOG.md` | ✅ đã sửa, kèm khối **ĐÍNH CHÍNH [2026-09-26]** |
| A2 `read_page` + `WebSourceService::fetchUrl()` | đi qua rào SSRF sẵn có | ✅ `ReadPageTool.php:25` · `WebSourceService.php:329` · `PAGE_MAX_CHARS = 8000` `:314` |
| A3 `AgentToolbox` (dispatch nhiều tool) | thay chỗ wiring một-tool | ✅ `AgentToolbox.php:93` (`definitions()`) · `:112` (`handle()`) · `:231` (`report()`); ở `DesignAgentService` là `makeToolbox()` `:2045` |
| A4 Sổ trích dẫn `src_N` | id ổn định trong lượt | ✅ `AgentToolbox.php:217` (`citations()`) |
| A5 Lưu nguồn vào bảng owner-scoped | tên đề xuất `agent_findings` | ✅ tên THẬT là **`web_findings`** (đổi khi viết mã) + API `GET/PUT /api/design-agent/findings` |
| A6 Cạm bẫy cấu hình: vai `agent_search` phải được gán model | — | ⚠️ **VẪN ĐÚNG**: `AgentChatService::group()` thử vai TÌM KIẾM trước, không có thì rơi về nhóm Suy luận (`AgentChatService.php:204-213`) |
