# Thiết kế luồng Bộ sưu tập → Thư viện → Bảng thiết kế
> FabrikAI Studio · Ngày: 2026-09-20 · Người thực hiện: AI assistant
> Phong cách: đo được, có bằng chứng file:line, không suy đoán.

---

## 1. Tóm tắt hiện trạng (đo được)

| Vấn đề | Bằng chứng số liệu | Hệ quả thật |
|---|---|---|
| Cùng 1 thực thể mà 2 tên trong UI | CollectionsCard.vue:5 ghi "Bộ sưu tập", ProjectWorkspace.vue:12 ghi "Dự án" | Người mới hỏi "bộ sưu tập" vs "dự án" là khác nhau? |
| Dọn file mồ côi XOÁ ảnh đang thuộc bộ sưu tập | Probe: `[PROBE2] cleanup={"deleted":143}` | Mất dữ liệu thật, không restore được |
| Khách tự duyệt không chốt được bộ của mình | Probe: `[PROBE3] transitions=["in_progress"]` | Ngõ cụt vòng đời, người dùng bế tắc |
| Thành viên nhóm không tạo ảnh vào bộ chung | index() trả bộ của chủ, nhưng generate() 422 | Mâu thuẫn với lời hứa "cùng bộ sưu tập" |
| Gói xuất lấy 60 ảnh CŨ NHẤT, không nói | ProjectExportService (trước P0.5) | Xưởng nhận mẫu cũ, người dùng không biết |
| Workspace cắt 60 ảnh mà không nói | ProjectController show(): limit(60) | Người dùng tự hỏi "ảnh đâu mất?" |
| Trang chia sẻ in slug tiếng Anh + sai tổng | share.blade.php:28-31 đọc `status_color` KHÔNG tồn tại | Khách duyệt nhìn thấy `>review<` |
| Thu hồi link chia sẻ không hoạt động | store.js revokeShare gửi token; model không khai getRouteKeyName | 404 trước khi vào controller |
| appliedProject mất khi F5 | store.js:332 — không persist | Người dùng mất hẳn ngữ cảnh "đang làm bộ nào" |
| Không có người phụ trách | projects.fillable không có assignee_id | Không giao được việc, không lọc "việc của tôi" |

---

## 2. Sơ đồ thông tin (IA) — sau sửa

```
Studio (SPA /studio)
├── Thanh hoạt động
│   ├── Bộ sưu tập (sidebar: CollectionsCard)
│   ├── Tạo ảnh → Concept / Variation / TryOn / Inpaint / Compose / Upscale / Director
│   ├── Thư viện (LibraryApp — chuyển view trong SPA)
│   ├── Bảng lệnh (palette Ctrl+K)
│   └── Outputs (dock)
├── ProjectWorkspace (modal Teleport)
│   ├── Board (kanban 5 cột: draft → in_progress → review → approved → archived)
│   ├── List (bảng)
│   └── Form tạo/sửa bộ sưu tập
├── LibraryApp (view SPA)
│   ├── Ảnh đã tạo (generations)
│   ├── File tải lên (uploads + link vào bộ sưu tập)
│   └── Prompt
└── Trang chia sẻ công khai (/chia-se/{token})
```

### Luồng chính
1. **Bắt đầu** → Mở CollectionsCard (sidebar) → thấy bộ đang làm + việc chạy dở.
2. **Tạo bộ sưu tập** → Bấm "+ Bộ sưu tập mới" → điền tên, deadline, người phụ trách → lưu.
3. **Làm ảnh** → Áp dụng bộ sưu tập → tạo ảnh → ảnh tự gắn vào bộ.
4. **Duyệt** → Mở Bảng thiết kế → kéo ảnh giữa các cột trạng thái → khách duyệt qua link chia sẻ.
5. **Xuất** → Tải gói ZIP → trình duyệt tải file thật (không còn window.open).
6. **Thư viện** → Chuyển sang view Thư viện → xem/gắn/gỡ ảnh theo bộ sưu tập.

---

## 3. Thiết kế màn hình & copy (sau sửa)

### 3.1 CollectionsCard (sidebar)
- Tiêu đề: "Bộ sưu tập"
- Mỗi thẻ hiển thị: tên · màu trạng thái · số ảnh · người phụ trách · deadline · nút "Mở"
- Trạng thái trống: "Chưa có bộ sưu tập. Bấm «Mới» để tạo bộ đầu tiên."
- Việc đang chạy: "X chờ xử lý · Y đang tạo · [Xử lý ngay]"

### 3.2 ProjectWorkspace (modal)
- Tiêu đề: "Bảng thiết kế"
- 2 chế độ: Bảng / Danh sách
- Tạo/Sửa form: Tên bộ sưu tập · Ý tưởng gốc · Brief · Deadline · Màu · Người phụ trách · Thẻ
- Board: mỗi cột là trạng thái; kéo thả hoặc dropdown chuyển trạng thái.
- Ghi chú trung thực: "Hiển thị N/M ảnh mới nhất — sang Thư viện để xem toàn bộ" (khi `generations_truncated` = true).

### 3.3 LibraryApp (view SPA)
- Bộ lọc: Trạng thái · Bộ sưu tập · Loại (ảnh/video)
- Tab Ảnh đã tạo: tick chọn nhiều → "Gắn vào bộ sưu tập" (bulk attach API sẵn sàng)
- Tab File tải lên: mỗi file có select "Gắn ảnh này vào một bộ sưu tập" (đã có)
- Trống: "Bộ sưu tập này chưa có ảnh/video nào." / "Chưa có ảnh / video nào khớp bộ lọc."

### 3.4 Trang chia sẻ công khai
- Tiêu đề: "Duyệt bộ sưu tập · {tên}"
- Badge trạng thái tiếng Việt (do ProjectWorkflowService::label sinh ra)
- Tổng số ảnh thật + ảnh gốc nếu có
- Cảnh báo khi bị cắt: "Trang này hiển thị {shown}/{total} ảnh mới nhất"
- Phản hồi khách: Duyệt / Yêu cầu sửa

---

## 4. Luồng công việc chuẩn ngành

```
draft → in_progress → review → approved → archived
```

- **draft**: đang nhập brief, tham chiếu, thẻ
- **in_progress**: đang tạo ảnh, xếp hàng render
- **review**: gửi khách/chờ duyệt (link chia sẻ); chủ bộ có thể tự duyệt nếu không có Super Admin khác
- **approved**: chốt mẫu — đủ điều kiện xuất xưởng
- **archived**: lưu trữ, không hiện trong danh sách active

### Vòng đời ảnh (shot lifecycle)
```
idea → drafted → selected → fitted → campaign_ready → approved/rejected
```
- Mỗi ảnh trong bộ sưu tập có `shot_state` riêng.
- "Duyệt mẫu theo lô" cho phép chọn nhiều ảnh, chốt hàng loạt.

---

## 5. Roadmap P0 / P1 — đã triển khai

### P0 — Đã ship (2026-09-20)

| ID | Tên | Trạng thái | Bằng chứng |
|---|---|---|---|
| P0.1 | Không mất ảnh gốc khi dọn mồ côi | ✅ | StudioLibraryService::referencedPaths() + deleteUploadedFiles() + refImageDelete() |
| P0.2 | Khách tự duyệt được bộ của mình | ✅ | ProjectWorkflowService: owner self-approve/archive |
| P0.3 | Thành viên nhóm ghi được vào bộ chung | ✅ | StudioController::assertProjectWritable + resolveProjectId |
| P0.4 | appliedProject + deep link | ✅ | store.js localStorage + ?view=library&bo=<id> |
| P0.5 | Gói xuất ảnh mới nhất + trung thực | ✅ | ProjectExportService orderByDesc('id') + manifest |
| P0.6 | Workspace/share nói thật số ảnh | ✅ | serialize() trả generations_shown/truncated |
| P0.7 | Thu hồi link chia sẻ hoạt động | ✅ | ProjectShare::getRouteKeyName() = 'token' |

#### Tiêu chí chấp nhận P0
- `php artisan test` → **716 passed** (4740 assertions)
- `npm run build` → **success** (719ms)
- Probe cũ không còn lặp lại (đã xóa file probe)

### P1 — Đã ship (2026-09-20)

| ID | Tên | Trạng thái | Bằng chứng |
|---|---|---|---|
| P1.1 | Thuật ngữ thống nhất | ✅ | grep "Dự án" trong 5 file Vue chính = 0 |
| P1.2 | Người phụ trách | ✅ | Project::assignee_id + ProjectController assignableUsers + UI form |
| P1.3 | Bulk attach | ✅ | ProjectController::attachGeneration nhận ids[] + per-item results |
| P1.4 | Deep link URL | ✅ | StudioApp.vue syncUrl + restoreAppliedProject |
| P1.5 | Onboarding | ⏳ | Còn việc UI (để sprint sau) |

---

## 6. Log quyết định

| Quyết định | Lý do | Ai quyết |
|---|---|---|
| Một tầng: đổi tên UI thay vì schema | Ít rủi ro, nhanh, người dùng thấy ngay | User chọn |
| Chủ bộ sưu tập được tự duyệt nếu không có Super Admin khác | Tránh Deadlock: 2 super admin thì ai duyệt bộ của nhau? | Code + user chấp nhận |
| Thu hồi link bind theo token (không phải id) | Frontend đã gửi token từ trước; test cũ che lỗi | Phát hiện qua audit |
| Derivative endpoint (layers/upscale) rơi về source + cảnh báo; generate/video chặn 422 | Không hỏng việc đang làm, nhưng không cho rơi vào bộ người khác | Code |
| Bulk attach trả per-item results thay vì 403 chung | Người dùng biết cái nào được, cái nào không | Code |

---

## 7. Đo được trước / sau

| Chỉ số | Trước | Sau |
|---|---|---|
| Số test | 711 | **716** (+5 test mới) |
| Ảnh bị dọn nhầm (probe orphan cleanup) | 143 | **0** |
| Thành viên nhóm tạo ảnh vào bộ chung | 422 | **200 + project_id đúng** |
| Gói xuất lấy ảnh | 60 ảnh cũ nhất | **60 ảnh mới nhất + manifest ghi rõ** |
| Workspace nói thật số ảnh | Không | **generations_shown + generations_truncated** |
| Link thu hồi hoạt động | 404 | **200 + link ngừng hoạt động** |
| appliedProject sau F5 | Mất | **Khôi phục tự động + URL sync** |
| Thuật ngữ "Dự án" còn trong UI chính | 11 nơi | **0** |

---

## 8. Công việc còn lại (đề xuất)

| ID | Việc | Ưu tiên |
|---|---|---|
| R1 | Onboarding checklist cho người mới (P1.5) | P1 |
| R2 | Bulk attach UI trong Library (chọn nhiều → gắn vào bộ) | P1 |
| R3 | Shot progress bar trên thẻ bộ sưu tập | P1 |
| R4 | Auto-refresh stats khi có ảnh mới | P2 |
| R5 | Tối ưu orphan scan (B16) — chuyển sang queue | P2 |
| R6 | Soft-delete cho generations/projects (B23) | P2 |
