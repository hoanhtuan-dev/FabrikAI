# DEPLOY LOG — FabrikAI

> Ghi lại các lần deploy, thay đổi phiên làm việc, và công lao từng phiên chat.
> Mục tiêu: khi có nhiều phiên song song, ai cũng đọc được ai đã làm gì, deploy khi nào, cần làm gì tiếp theo.

---

## Phiên 2026-09-20 (Tối ưu UX/UI "Bộ sưu tập")

### Thay đổi chính

| # | Thay đổi | File | Mô tả |
|---|---|---|---|
| 1 | Thêm route `/bo-suu-tap` | `routes/web.php` | Trang đầy đủ "Bộ sưu tập", middleware `auth + can-studio`. |
| 2 | Thêm `collectionsPage()` | `app/Http/Controllers/StudioController.php` | Render view `studio.collections`. |
| 3 | Tạo shell blade mới | `resources/views/studio/collections.blade.php` | Inject `window.__STUDIO_BOOT__`, mount vào `#collections-root`. |
| 4 | Tạo entry point JS | `resources/js/studio/collections.js` | Mount `CollectionsPage.vue` với Pinia + pageBoot. |
| 5 | Viết lại `CollectionsPage.vue` | `resources/js/studio/pages/CollectionsPage.vue` | Trang full-page: onboarding 4 bước, hero card, thanh tiến trình 6 bước duyệt mẫu, gợi ý "bước tiếp theo", modal duyệt lô, chia sẻ, xuất gói. |
| 6 | Viết lại `CollectionsCard.vue` | `resources/js/studio/components/CollectionsCard.vue` | Sidebar card: chip 6 bước duyệt, actions chuyên sâu (Duyệt · Chia sẻ · Xuất · Studio), link rõ sang `/bo-suu-tap`. |
| 7 | Thêm `collections.js` vào Vite | `vite.config.js` | Build thành công `collections-*.js` (~37 KB). |
| 8 | Fix namespace blade | `resources/views/studio/collections.blade.php` | Sửa `AppServicesProjectWorkflowService` → `\\App\\Services\\ProjectWorkflowService`. |
| 9 | Fix thiếu biến `onboardingSteps` | `CollectionsPage.vue` | Đổi thành `ONBOARDING_STEPS` (static) — bản trước dùng trong template mà không khai báo. |
| 10 | Thay `requestWorkspace()` → điều hướng `/?bo=<id>&panel=collections` | `CollectionsPage.vue` + `CollectionsCard.vue` | Trang độc lập không có StudioApp, nên navigate sang `/` với params. |
| 11 | Khôi phục applied project từ localStorage | `CollectionsPage.vue` | Gọi `store.restoreAppliedProject()` trong `onMounted`. |
| 12 | Mở card "Bộ sưu tập" khi về từ `/bo-suu-tap` | `StudioApp.vue` | Đọc `?panel=collections` → `activeActivity = 'collections'` + `leftPanelOpen = true`. |
| 13 | Lưu trạng thái panel/dock | `StudioApp.vue` | Watch `leftPanelOpen`, `outputDockOpen`, `leftDockWidth`, `outputDockWidth` → `saveBarSettings()`. |
| 14 | Lưu khi rời trang | `StudioApp.vue` | `onBeforeUnload` gọi `saveBarSettings()` thêm 1 lần cuối. |
| 15 | Nhớ card đang mở lần cuối | `StudioApp.vue` | Lưu `fabrikai.lastActivity` vào localStorage khi `activeActivity` đổi; khôi phục khi mount. |
| 16 | Chờ boot xong mới render (anti-flash) | `StudioApp.vue` | Thêm `booting` ref, gốc template `v-if="!booting"`, `onMounted` bọc `try/finally` để chỉ set `booting = false` sau khi mọi async settle. |

### Kết quả build

```
✓ built in 747ms
public_html/build/assets/collections-*.css   0.11 kB
public_html/build/assets/collections-*.js   37.30 kB │ gzip: 11.26 kB
```

### Luồng điều hướng mới

```
/bo-suu-tap (trang tổng quan)
    ↓ click "Vào Studio" hoặc "Mở trong Studio"
/?bo=<id>&panel=collections
    ↓ StudioApp đọc params
    ├── applyProject(id)
    ├── leftPanelOpen = true
    ├── activeActivity = 'collections'
    └── render UI (chờ boot xong → không flash)
```

### Cần làm tiếp (ghi nhớ)

- [ ] Unit test cho `CollectionsPage.vue` (shot progress, next-step suggestion).
- [ ] E2E test: F5 từ `/bo-suu-tap` → card collections phải mở đúng.
- [ ] Kiểm tra `localStorage` trên Safari Private Mode (có thể ném `SecurityError`).

---

## Phiên 2026-09-19 (Đợt 2 — Project workflow + Collections card)

| # | Thay đổi | File | Mô tả |
|---|---|---|---|
| 1 | `ProjectController` + workflow service | `app/Http/Controllers/ProjectController.php` + `app/Services/ProjectWorkflowService.php` | CRUD bộ sưu tập + state machine 5 trạng thái. |
| 2 | Review batch API | `routes/web.php` + `ProjectController::reviewShots` | Duyệt/loại/chuyển bước nhiều ảnh trong 1 lượt. |
| 3 | Collections card (sidebar) | `resources/js/studio/components/CollectionsCard.vue` | Card "Bộ sưu tập" đầu tiên, chưa tối ưu. |
| 4 | Share + Export | `ProjectShareController` + endpoints | Chia sẻ link công khai, xuất gói ZIP. |

---

## Quy ước ghi công

- Mỗi phiên chat có một mục **"Phiên <ngày>"** riêng.
- Cột **"Công lao"** ghi rõ ai (session id / tên dev) đã viết/đọc/sửa file nào.
- Nếu có nhiều phiên song song, dùng prefix `[S1]`, `[S2]` để phân biệt.

### Bảng công lao phiên 2026-09-20

| Session | Thao tác | File đụng đến |
|---|---|---|
| S1 | Thiết kế lại `CollectionsPage.vue`, `CollectionsCard.vue` | `resources/js/studio/pages/CollectionsPage.vue`, `resources/js/studio/components/CollectionsCard.vue` |
| S1 | Sửa `StudioApp.vue` (panel persistence, activity persistence, anti-flash) | `resources/js/studio/StudioApp.vue` |
| S1 | Thêm route + controller + blade + entry JS | `routes/web.php`, `app/Http/Controllers/StudioController.php`, `resources/views/studio/collections.blade.php`, `resources/js/studio/collections.js` |
| S1 | Fix bug namespace + thiếu biến + build | `resources/views/studio/collections.blade.php`, `vite.config.js` |

---

## Checklist deploy lần này

- [x] `npm run build` → `public_html/build/` cập nhật
- [x] `php artisan view:clear` → xóa compiled view cũ
- [x] Kiểm tra `routes/web.php` + `StudioController.php` không có syntax error
- [x] Kiểm tra manifest có `collections-*.js`
- [ ] Push code lên GitHub (repo public)
- [ ] SSH vào production → `git pull` + `composer install --no-dev`
- [ ] SSH → `php artisan migrate --force` (nếu có migration mới)
- [ ] SSH → `php artisan config:cache` + `php artisan route:cache` + `php artisan view:cache`
- [ ] Clear storage cache: `php artisan cache:clear`
- [ ] Kiểm tra `/bo-suu-tap` và card sidebar hoạt động
- [ ] Kiểm tra F5 không flash (booting gate)
- [ ] Kiểm tra panel/dock nhớ trạng thái sau F5
