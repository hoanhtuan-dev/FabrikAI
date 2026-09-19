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

---

## Phiên 2026-09-22 (Provider: xoá/sửa custom provider · ưu tiên trong nhóm · template)

**Commit:** `fb48d49` (trên `ac72756`). **Trạng thái: đã commit + push, CHƯA deploy production**
(production vẫn ở `31c4667`).

### Thay đổi chính

| # | Thay đổi | File | Mô tả |
|---|---|---|---|
| 1 | Bind route theo slug | `app/Models/StudioProvider.php` | Thêm `getRouteKeyName() = 'slug'`. Model khai `routeKey()` trước đây là code CHẾT — Laravel chỉ đọc `getRouteKeyName()`, nên bind theo `id` trong khi payload không có `id` ⇒ PUT/DELETE luôn 404. |
| 2 | `data()` trả `id` + `priority` | `StudioSettingsController` | Provider payload trước đây thiếu `id` (UI gọi `/providers/undefined`) và thiếu `priority`. |
| 3 | Ưu tiên trong nội bộ nhóm | migration `2026_09_22_000001` + `helpers.php` | Cột `studio_providers.priority` (0–100, mặc định 5). Thứ tự xếp hạng mới: rank nhóm → **ưu tiên provider** → ưu tiên model. Trước đây mọi custom provider cùng rank 10 nên thứ tự rơi vào `id`. |
| 4 | `studio_provider_priority()` + memo | `helpers.php` | Built-in lấy từ catalog (qwen 10 · qwen_edit 9 · dashscope 8 · wan 7 · gemini 10 · veo 9 · fal 10 · replicate 5 · deepseek 5); custom lấy từ cột DB. Memo `studio_custom_provider_map()` xoá được qua model event (an toàn cho worker queue). |
| 5 | CKEY → template | `helpers.php` + `SettingsApp.vue` | `studio_provider_templates()`: 9 mẫu (OpenAI-compatible tổng quát, CKEY, OpenRouter, Together, Groq, SiliconFlow, DeepInfra, DashScope quốc tế, Gemini-compatible). Nút 'Preset CKEY' hardcode đã bị thay bằng ô chọn 'Mẫu khai báo' + nút 'Dùng mẫu'. |
| 6 | UI provider modal | `SettingsApp.vue` | Ô 'Ưu tiên trong nhóm Custom', badge 'Ưu tiên N', chỉ số `#thứ-tự thực tế`, khoá `v-for` theo slug. |

### ⚠️ Ghi chú phối hợp phiên song song (đọc trước khi deploy)

- **Asset build:** `public_html/build` trong commit này được build từ **worktree sạch** (`HEAD` + chỉ thay đổi của phiên này) để KHÔNG đóng gói code đang làm dở của phiên khác. Vì vậy `app.css`/`base` chunk có thể khác bản build từ cây làm việc có WIP — đó là chủ ý, không phải lỗi.
  → Phiên đang làm song song: chạy lại `npm run build` sau khi xong và commit asset của mình.
- **KHÔNG đụng tới** các file WIP của phiên khác: `ModuleRegistry.php` · `StudioApp.vue` · `BaseModal.vue` · `CanvasEmptyState.vue` · `store.js` · `routes/web.php` · `CanvasControlsTest.php` · `DesignAgent*`.
- **6 test đỏ ở HEAD** (đã kiểm chứng bằng cách chạy trong worktree sạch, KHÔNG do phiên này):
  `CollectionsHubTest > hub card only uses existing store actions` ·
  `JobTemplatesTest > applying a factory template also feeds the export dialog` ·
  `MotionFoundationTest > every hover surface animates` (CollectionsCard.vue, CollectionsPage.vue: `hover:text-white` thiếu `.motion-ui`) ·
  `ShotReviewTest > ui actually drives the shot lifecycle` · `review shortcuts are wired and safe` ·
  `StaticIntegrityTest > full screen overlays declare their role`.
- Nếu deploy `main` lúc này, production sẽ nhận CẢ commit `ac72756` (redesign Collections) của phiên kia — không chỉ phần provider.

### Cần làm tiếp

- [ ] Chạy migration: `php artisan migrate --force` (KHÔNG có migration nào khác đang chờ).
- [ ] Sau deploy: kiểm tra xoá được custom provider + ô Ưu tiên + ô chọn mẫu trong `/settings`.
- [ ] Phiên Collections/DesignAgent: sửa 6 test đỏ rồi rebuild asset.


### Kết quả deploy (đã chạy thật)

Production **trước đó đã ở `ac72756`** (phiên Collections đã deploy trước), nên `git pull` chỉ thêm `fb48d49`.

```bash
ssh -p 65002 u310846799@145.79.25.57
cd ~/domains/fabrikai.shop
mysqldump --socket=/tmp/mysql.sock … > ~/fabrikai-db-backup-20260919-165959.sql   # 156 KB (truoc migrate)
git config core.fileMode false && git pull --ff-only origin main                  # ac72756 -> fb48d49
php artisan migrate --force        # 2026_09_22_000001_add_priority_to_studio_providers
php artisan package:discover
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

**Verify E2E trên production (đăng nhập admin thật, HTTP thật):**

| Thao tác | Kết quả |
|---|---|
| `POST /api/settings-vue/providers` (priority 25) | **201** |
| `PUT /api/settings-vue/providers/tpltest` (đổi priority → 60) | **200** |
| `DELETE /api/settings-vue/providers/tpltest` | **200** `{ok:true}` |
| `GET /api/settings-vue/data` | **9 template** (`openai-compatible, ckey, openrouter, together, groq, siliconflow, deepinfra, dashscope-intl, custom-gemini`); provider `qwen` có `priority` |
| `/` `/dang-nhap` `/up` | **200** · khách `/settings` **302** · khách `/bo-suu-tap` **302** |
| `migrate:status` | 0 pending |

### Quan sát vận hành (cho phiên sau)

- Luồng ưu tiên trên production hiện là **`custom,qwen,flux,gemini`** (admin đã đổi qua tab 🔥, khác mặc định) ⇒ `rank(qwen)=10`, `rank(custom)=0`.
- Log có **HTTP 429**: `qwen vision suggest failed: token-plan 1-week quota…` — hạn mức Qwen (token-plan) đã cạn.
- Registry hiện **không còn dòng `fal:flux-1.1-schnell`** (admin đã xoá) ⇒ khi Qwen 429 thì KHÔNG còn fallback ảnh. Bấm **🔄 Đồng bộ catalog** ở tab 🔥 sẽ thêm lại (idempotent, không đè dòng đã sửa tay; muốn dùng thì bật lại dòng đó).
- Có 2 custom provider: `ckey` (prio 5) và `deepseek-custom` (prio 5) — trùng ưu tiên nên thứ tự rơi về slug; đặt ưu tiên khác nhau nếu muốn route cụ thể thử trước.


---

## Phiên 2026-09-22 (Đợt 2 — DeepSeek vào luồng ưu tiên, trước Gemini)

**Commit:** `7563701` + `bd9f92a`. **Đã deploy production:** `603ddb4` → `7563701` → `bd9f92a`.

### Thay đổi

| # | Thay đổi | File | Mô tả |
|---|---|---|---|
| 1 | Một nguồn cho danh sách nhóm | `helpers.php` | `studio_provider_families()` (qwen, custom, flux, **deepseek**, gemini, other) + `studio_provider_default_flow()` (bỏ `other` — nhóm HỨNG). Flow helper, validate ở Settings và `flow_counts` đều lấy từ đây. |
| 2 | Luồng mặc định | `config/studio.php` | `qwen,custom,flux,deepseek,gemini`. |
| 3 | Family riêng cho DeepSeek | `helpers.php` | Provider `deepseek` từ nhóm `other` → nhóm `deepseek`. |
| 4 | 3 model DeepSeek | `helpers.php` | `deepseek-chat` + `deepseek-reasoner` (nhóm prompt), `deepseek-chat` (nhóm translate). Catalog 27 → 30 dòng. |
| 5 | Sync có lọc | `StudioSyncModels` + `studio_sync_model_catalog($provider)` | `php artisan studio:sync-models --provider=deepseek` — nhập MỘT nhóm, **không hồi sinh** dòng catalog admin đã cố ý xoá (vd dòng `fal`). |
| 6 | UI | `SettingsApp.vue` | Thẻ DeepSeek trong tab 🔥; `other` đổi thành nhóm hứng chung. |
| 7 | Migration | `2026_09_22_000002_add_deepseek_to_provider_flow.php` | Chèn `deepseek` NGAY TRƯỚC `gemini` trong setting đã lưu, giữ nguyên thứ tự admin đặt; idempotent. |
| 8 | Vá cache | cùng migration | Dùng `Setting::set()` + `flushCache()` thay vì `DB::table()->update()`. |

### ⚠️ Bẫy thật đã gặp (ghi để phiên sau không mất thời gian)

Migration bản đầu ghi thẳng `DB::table('settings')->update(...)` ⇒ **không kích hoạt model event** ⇒ cache `settings:all` giữ giá trị CŨ.
Triệu chứng: DB đã có `custom,qwen,flux,deepseek,gemini`, migration báo DONE, nhưng `setting()` vẫn trả luồng cũ và `rank(deepseek)=990` (không được xếp hạng).

→ **Quy tắc:** đổi setting trong migration/seeder phải đi qua `Setting::set()` (hoặc gọi `Setting::flushCache()` sau khi ghi); deploy có migration đụng settings thì chạy thêm `php artisan cache:clear`.
→ Đã có test hồi quy `test_deepseek_migration_invalidates_settings_cache` (đỏ nếu quay lại cách ghi cũ).

### Verify trên production (chạy thật)

| Kiểm tra | Kết quả |
|---|---|
| `setting('studio_provider_priority')` | `custom,qwen,flux,deepseek,gemini` (admin giữ thứ tự custom-first) |
| rank | qwen 10 · flux 20 · **deepseek 30** · gemini 40 |
| candidate `prompt` | qwen3.8-flash → qwen3.8-max → **deepseek-chat → deepseek-reasoner** |
| candidate `translate` | qwen3.8-flash → **deepseek-chat** |
| candidate `vision` | không đổi (deepseek-chat không đọc ảnh) |
| `/` `/dang-nhap` `/up` | 200 |
| Dòng `fal` admin đã xoá | vẫn 0 — sync có lọc không hồi sinh |

**Test:** 31/31 `ProviderPriorityFlowTest` xanh. Full suite 736 pass; 6 fail vẫn là lỗi SẴN CÓ ở HEAD thuộc phiên Collections/Canvas (không đổi).
Asset build từ worktree sạch (HEAD + chỉ thay đổi của phiên này) — WIP của phiên song song vẫn nguyên, không bị đóng gói.

### Cần làm tiếp

- [ ] Thêm API key DeepSeek ở tab 🔑 (provider `deepseek`) thì nhóm DeepSeek mới thực sự gọi được; chưa có key thì candidate vẫn được liệt kê nhưng sẽ lỗi và rơi xuống nhóm sau.
- [ ] Phiên Collections/Canvas: sửa 6 test đỏ rồi rebuild asset (xem mục phiên trước).

