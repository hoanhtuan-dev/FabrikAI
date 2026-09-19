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


---

## Phiên 2026-09-22 (Đợt 3 — Card 'Gợi ý từ ảnh' đi theo Model Registry + luồng ưu tiên)

**Commit:** `b82ff50`. **Đã deploy production** (không migration, không đổi frontend).

### Nguyên nhân (điều tra theo yêu cầu)

Card '💡 Gợi ý từ ảnh' chạy **pipeline riêng**, cứng `qwen|gemini` ở 3 chỗ và KHÔNG đọc Model Registry / nhóm công việc `vision` / luồng ưu tiên:

| Chỗ | Trước |
|---|---|
| `helpers.php` `studio_suggest_provider()` | ép mọi giá trị khác về `qwen` |
| `StyleSuggestService::suggest()` | chỉ dựng `$attempts` từ qwen + gemini (chuỗi `deepseek` xuất hiện **0 lần** trong cả `app/Services/`) |
| `StudioController::updateSuggestSettings()` | validate `in:gemini,qwen` ⇒ không lưu được deepseek/custom |

**Hệ quả thật trên production:** key `qwen` đã tắt, không có key gemini, chỉ còn key `deepseek` đang bật ⇒ **danh sách thử RỖNG** ⇒ card lặng lẽ rơi về phân tích màu GD (không có suy luận nào), dù registry đã có dòng `deepseek:deepseek-flash` trong nhóm `vision`.

**Kiểm chứng thêm:** `deepseek-flash` ĐỌC ĐƯỢC ẢNH (test 1 lời gọi nhỏ: `POST api.deepseek.com/chat/completions` với `image_url` → HTTP 200, có `reasoning_content`) — giả định 'DeepSeek không có vision' là sai với API hiện tại (`GET /models` → `deepseek-flash`, `deepseek-v4-pro`).

### Đã sửa

| # | Thay đổi | File |
|---|---|---|
| 1 | `suggest_provider`: `''`/`auto` = AUTO theo registry (mặc định mới); slug cụ thể = ÉP provider đó | `helpers.php`, `config/studio.php` |
| 2 | `registryVisionCandidates()`: candidate từ nhóm `vision` theo đúng thứ tự `studio_task_group_models()` (default nhóm → rank nhóm → ưu tiên provider → ưu tiên model); candidate không có key bị loại ngay | `StyleSuggestService` |
| 3 | `suggestViaOpenAiVision()`: transport chung cho MỌI gateway OpenAI-compatible — DeepSeek (base trong catalog) + custom provider (base/protocol trong Settings); đọc cả `content` lẫn `reasoning_content` | `StyleSuggestService` |
| 4 | Gộp implementation: `suggestViaQwenModel()` dùng chung cho đường legacy và đường registry; gemini nhận model cụ thể; đường cũ giữ nguyên làm lưới an toàn | `StyleSuggestService` |
| 5 | Endpoint `/api/settings/suggest` nhận mọi slug (`auto`, `deepseek`, `ckey`…) | `StudioController` |

### Verify trên production (chạy THẬT)

```
suggest_provider : ""  (AUTO theo registry)
Candidate vision (thứ tự sẽ thử):
  1. deepseek:deepseek-flash  transport=openai  base=https://api.deepseek.com  keys=1

Gọi thật với ảnh mẫu public_html/samples/2aOboQq…jpg (84 KB) — 7.7 giây:
  styles        : minimal elegant, soft feminine, resort evening
  garment_type  : satin halter top + embroidered midi skirt set
  color_palette : ivory cream, muted lilac purple, soft white, black hair
  fabric        : liquid satin with sheen | silhouette: fitted halter top with flowy asymmetric midi skirt
  detail_notes  : Two-piece ivory satin set: sleeveless halter top with a knotted twist at the neck …
```

Log: không có lỗi mới (4 dòng gần nhất đều từ 18–19/09). `/` `/dang-nhap` `/up` → 200.

**Test:** 7 test mới `SuggestRegistryTest` (deepseek khi chỉ nó có key · đổi luồng ⇒ đổi thứ tự thử · custom provider openai · ép provider giữ hành vi cũ · helper AUTO · endpoint nhận slug lạ · fallback màu). Full suite 744 pass; 6 fail vẫn là lỗi SẴN CÓ ở HEAD thuộc phiên Collections/Canvas.

### Còn lại (đề xuất)

- [ ] `faceDescription()` và `poseDescription()` (mô tả khuôn mặt/tư thế cho Thay khuôn mặt + Thử đồ) **vẫn chỉ dùng Qwen** qua `studio_suggest_qwen_models()` — với key qwen đang tắt thì hai đường này trả `null` (try-on chạy thiếu mô tả). Nên cho chúng dùng chung `registryVisionCandidates()`.
- [ ] Cân nhắc hiện `suggest_provider` (auto/qwen/gemini/deepseek/custom) trong tab Cài đặt — hiện chỉ đổi được qua API `/api/settings/suggest`.

---

## Phiên 2026-09-20 (Agent Studio — TrendRadar + CollectionBot + Canvas command center)

**Commit:** `997a81b` (push `b82ff50..997a81b`). **Đã deploy production** — không migration, có thay đổi frontend + build asset.

### Phạm vi

- Thêm `POST /api/design-agent/radar` và `POST /api/design-agent/collection` (module `trend_radar`, `collection_bot`; `stylist` phụ thuộc cả hai).
- `DesignAgentService` + `DesignAgentController`: rule-based, `source_mode=demo`, nguồn ngoài `demo` / nội bộ `local`; brief có `canvas` (ratio, variants, negative prompt) + `input_signature`.
- Agent Studio 3 bước (Tín hiệu → Định hướng → Thực thi) dùng `BaseModal full`, rail tiến trình, action bar.
- Canvas trống thiết kế lại thành command center: composer + Agent Studio + đi nhanh; **xóa** khối mẫu việc và ảnh gần đây khỏi Canvas (mẫu việc vẫn còn trong tab Hàng loạt của Prompt Tạo Ảnh).

### Deploy đã chạy

```bash
cd ~/domains/fabrikai.shop
git pull --ff-only origin main                # b82ff50..997a81b
composer dump-autoload -o --no-interaction    # exit=1 do proc_open; classmap đã ghi (grep DesignAgent = 2)
php artisan package:discover
php artisan migrate --force                   # Nothing to migrate
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

### Verify trên production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `997a81b` |
| Route | `POST api/design-agent/radar` · `POST api/design-agent/collection` có trong `route:list` |
| Classmap | 2 lớp `DesignAgentController` · `DesignAgentService` |
| Trang | `/` `/dang-nhap` `/up` → **200** |
| Asset | `manifest.json` → 200 · `main-CmvRKvs3.js` → 200, chứa **Agent Studio** + **Canvas trống** |
| API khách | `POST /api/design-agent/radar` không đăng nhập → **419** (CSRF; route tồn tại và được bảo vệ) |
| Log | Không phát sinh ERROR mới sau deploy (các ERROR cũ vẫn từ 17–19/09) |

**Test trước deploy:** 52 test trọng tâm xanh (DesignAgent + Canvas command center + ModuleRegistry + GUI). Full suite vẫn 6 fail SẴN CÓ ở HEAD thuộc phiên Collections/Canvas cũ (không đổi).

### Cần làm tiếp

- [ ] Kiểm thử endpoint design-agent bằng tài khoản thật để xác nhận module gating theo gói trên production.
- [ ] Phiên Collections/Canvas: sửa 6 test đỏ rồi rebuild asset (xem mục phiên trước).


---

## Phiên 2026-09-22 (Đợt 4 — Thiết kế lại card 'Gợi ý từ ảnh' + tiến trình AI thật + 10 gợi ý mới nhất)

**Commit:** `198065b`. **Đã deploy production** (không migration).

### 1. Tiến trình THẬT khi AI suy luận

| Việc | Chi tiết |
|---|---|
| Endpoint mới | `POST /api/suggest/stream` → NDJSON: `phase` → `provider` → `result` / `error` |
| Service | `StyleSuggestService::suggest(..., ?callable $onProgress)` phát sự kiện ở đúng ranh giới thật (chuẩn bị ảnh → chọn provider → AI đọc ảnh → fallback → phân tích màu) |
| JSON cũ | `POST /api/suggest` giữ nguyên, nay kèm `_meta {provider, model, elapsed_ms}` |
| Lỗi | Thông báo cuối nêu rõ provider/model hỏng (`Lỗi cuối: Vision deepseek-flash (HTTP 500)…`) |

**Đo THẬT trên production** (curl `-N`, mốc ms so với lúc gửi):

```
+    74 ms  {"type":"phase","key":"prepare","label":"Đang chuẩn bị ảnh nguồn…"}
+    84 ms  {"type":"provider","provider":"deepseek","model":"deepseek-flash","transport":"openai","keys":1}
+    87 ms  {"type":"phase","key":"vision","label":"AI đang đọc ảnh và suy luận… (deepseek · deepseek-flash)"}
+  8807 ms  {"type":"result","data":{…}}
TONG: 8810 ms
```

⇒ Sự kiện tiến trình về client sau **~80 ms**, kết quả sau **8.8 s**: card hiện đúng AI đang chạy + đồng hồ giây,
thay vì nút 'Đang phân tích…' đứng im. Hostinger KHÔNG buffer (nhờ `ob_end_flush()` + header `X-Accel-Buffering: no`).

### 2. Thiết kế lại card (SuggestCard.vue: 341 → 574 dòng)

- **5 chế độ nhanh** một cú bấm: Bám gốc tối đa · Cân bằng · Sáng tạo · Sàn TMĐT · Lookbook (thay 3 slider + 3 checkbox phải tự chỉnh).
- Khối **Tuỳ chỉnh nâng cao** thu gọn, có dòng tóm tắt trạng thái.
- Khung kết quả có cấu trúc: chip dữ kiện (chất liệu · dáng · góc máy · tư thế · bối cảnh · hoạ tiết), bảng màu, **tab** (Prompt ảnh EN/VI · Prompt video · Chi tiết gốc · Từ khoá), nút Copy prompt, Lưu Thư viện, Tạo ảnh với prompt này.
- Thanh tiến trình 4 chặng + chip AI + đồng hồ; khối lỗi có ngữ cảnh.
- Accessibility: `role=status`, `aria-live`, `aria-expanded`, `aria-label` cho slider; mọi bề mặt hover đều có `motion-ui`/`motion-row` (**không thêm vi phạm** cho MotionFoundationTest).

### 3. Danh sách 10 gợi ý từ ảnh mới nhất

- `GET /api/suggest/recent?limit=10` — 10 kết quả mới nhất **của chính người dùng**, đủ trường để nạp lại vào card (không gọi lại AI), kèm `ago` + số lần đã dùng.
- Card **gộp thêm lịch sử trên trình duyệt** (localStorage, 10 mục) nên danh sách có dữ liệu NGAY cả khi chưa bấm Lưu; mục chưa lưu có nút lưu nhanh, mục đã lưu hiện 'đã lưu'.
- Nạp lại mục cũ giữ **ảnh gốc của chính nó** (không lấy nhầm ảnh đang chọn).

### Verify production

- Route mới có mặt: `api/suggest/stream`, `api/suggest/recent`.
- `/` `/dang-nhap` `/up` → **200**.
- Bundle đã chứa UI mới (`Chế độ nhanh`, `Gợi ý gần đây`) và code stream (`suggest/stream`, `pushSuggestLocal` ở chunk chia sẻ).
- `/api/suggest/recent` trả đúng shape (hiện 0 mục vì Thư viện chưa có gì; lịch sử trình duyệt sẽ đổ vào sau lần phân tích đầu).

**Test:** 5 test mới `SuggestStreamTest`; full suite **749 pass**; 6 fail vẫn là lỗi SẴN CÓ ở HEAD (Collections/Canvas).

### ⚠️ Phối hợp phiên song song

Phiên khác đang refactor `AiModelGateway` + 5 service (Gemini/ImageAI/Stylist/VideoAI/VirtualTryOn) và **cũng đang sửa `StudioController.php`**.
Đã tách bằng cách chỉ stage **đúng hunk của phiên này** trong StudioController (`git apply --cached` với patch lọc theo `suggestStream`/`suggestRecent`),
nên commit `198065b` KHÔNG chứa WIP của họ — 6 file WIP của họ vẫn nguyên trong cây làm việc.

### Cần làm tiếp

- [ ] `faceDescription()`/`poseDescription()` vẫn chỉ dùng Qwen (xem đợt 3).
- [ ] Chưa có UI cho `suggest_provider` (auto/qwen/gemini/deepseek/custom) trong tab Cài đặt.
- [ ] Phiên Collections/Canvas: sửa 6 test đỏ (CollectionsCard/CollectionsPage thiếu `.motion-ui` ở `hover:text-white`).

---

## Phiên 2026-09-22 (Đợt 5 — "MỘT CỬA" cho mọi lời gọi model AI: tôn trọng Cài đặt + linh hoạt)

**Commit:** `60e639f`. **Đã deploy production** (không migration, **không đổi frontend ⇒ không rebuild asset**).
Production `198065b` → `60e639f` (fast-forward).

### Vấn đề (audit sâu mọi call-site gọi model)

Nhiều đường tự chọn provider/model bằng **hằng số hoặc setting rời**, nên đổi Model Registry / Nhóm công việc /
Luồng ưu tiên / Custom Provider trong Cài đặt **không tác động gì**: cấu hình hiển thị một đằng, gọi model một nẻo.

| Đường | Trước đây tự chọn bằng | Hệ quả |
|---|---|---|
| Giám đốc sáng tạo (`GeminiService`) | `prompt_provider` + `studio_api_key('qwen'\|'gemini')` | Không có key qwen/gemini ⇒ **luôn rơi về STUB**, DeepSeek không bao giờ được gọi |
| Thuật sỹ ảo (`StylistService::chat`) | cứng Qwen → Gemini | Cùng lý do ⇒ luôn `null` |
| Video catwalk (`VideoAIService::render`) | `studio_qwen_credentials('video')` + setting `video_model` | Model video gán trong Registry bị bỏ qua |
| Sửa ảnh / Inpaint (`ImageAIService`) | đúng MỘT model: setting `qwen_edit_model` | Model edit trong Registry không bao giờ chạy, không failover |
| Thử đồ (`VirtualTryOnService`) | `studio_swap_model()` | Model swap trong Registry bị bỏ qua |
| Vision QA (thử đồ + QA swap) | `studio_qwen_vision_models()` + key qwen\|dashscope | Model vision khác (DeepSeek…) không bao giờ chạy |
| Moderation / Super-resolution / Face-enhance | 3 slot `studio_api_key('dashscope'\|'qwen'\|'qwen_edit')` | Key gán cho một nhóm khác bị bỏ qua |

### Cách làm

`app/Services/AiModelGateway.php` (mới, 371 dòng) — **một cửa duy nhất**:

- `candidates($group)` lấy từ `studio_task_group_models()` ⇒ tôn trọng **default nhóm → Model Registry → luồng ưu tiên provider → ưu tiên model**;
- provider **không có key dùng được bị bỏ NGAY** (không gọi rồi mới lỗi), key lấy qua `studio_candidate_key()` (đúng scope nhóm/model);
- **custom provider** dùng đúng `protocol` + `base_url` + `api_key_ref` của nó;
- `text()` / `vision()` gọi lần lượt candidate × key, trả về **provider/model THẬT**;
- `credentials()` / `key()` / `dashscopeKey()` cho các đường không-phải-chat (video async, edit multimodal, moderation…).

Call-site đã chuyển: `translate()` → nhóm `translate` · `StylistService` + `GeminiService` → nhóm `prompt` ·
`VideoAIService` → nhóm `video` · `ImageAIService::editModelChain()` → nhóm `edit` · `VirtualTryOnService` → `vision` + `swap` ·
moderation/super-resolution/face-enhance → `dashscopeKey()` · `defaultProviderModel()` + `/api/defaults` → nhận đúng nhóm `edit`.
**Ròng −105 dòng** (xoá hẳn 2 nhánh provider viết tay trong `StylistService`/`GeminiService`).

### Đo THẬT trên production sau deploy (probe bằng cách bootstrap app rồi hỏi gateway)

```json
{
  "image": [], "edit": [], "video": [], "swap": [],
  "vision":    ["deepseek:deepseek-flash (openai, 1 keys)"],
  "prompt":    ["deepseek:deepseek-flash", "deepseek:deepseek-chat", "deepseek:deepseek-reasoner"],
  "translate": ["deepseek:deepseek-chat"],
  "dashscopeKey": "none"
}
```

⇒ Trên production hiện **chỉ có key DeepSeek**. Trước đợt này: Giám đốc sáng tạo + Thuật sỹ ảo **luôn trả STUB**
dù DeepSeek hoạt động (vì chúng chỉ tìm key qwen/gemini). Sau đợt này chúng **gọi DeepSeek thật** theo nhóm `prompt`.
Các nhóm `image/edit/video/swap` rỗng ⇒ giữ nguyên chế độ demo trung thực (`is_demo` + ảnh mẫu/ảnh gốc), **không hồi quy**.

### Verify production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `60e639f` |
| Lớp mới | `class_exists(App\Services\AiModelGateway)` = **true** |
| Trang | `/` `/dang-nhap` `/up` → **200** (qua https) · `build/manifest.json` → **200** |
| Log | Số dòng ERROR vẫn **8**, mục mới nhất vẫn từ 17–19/09 — **không phát sinh lỗi mới** |

### Test

**12 test mới** `tests/Feature/AiModelGatewayTest.php`: DeepSeek được gọi thật khi là default nhóm `prompt` · custom provider
(CKEY/`api.xah.io`) được gọi thật · `/api/translate` theo nhóm `translate` · `video` submit đúng model của nhóm ·
chuỗi model Sửa ảnh theo nhóm `edit` (và **rỗng khi không có key**) · model thử đồ theo nhóm `swap` ·
QA thử đồ chạy trên model nhóm `vision` · `dashscopeKey()` lấy từ nhóm đang cấu hình.

Full suite: **761 pass / 6 fail** — 6 fail vẫn đúng 6 lỗi **SẴN CÓ** ở HEAD (CollectionsHub · JobTemplates · MotionFoundation ·
ShotReview ×2 · StaticIntegrity), không liên quan đợt này (trước đợt: 749 pass / 6 fail).

### Còn lại (đề xuất)

- [ ] Nhánh **refgen / ảnh mới từ ảnh mẫu** chỉ chạy được trên DashScope-family (API multimodal nguyên bản) — candidate của
      custom provider/gemini trong nhóm `image` bị bỏ qua **có chủ ý**; muốn dùng cần viết transport i2i tương ứng.
- [ ] Khi thêm key Qwen/DashScope cho production: `vision` sẽ bật **best-of-N** cho Thử đồ (`swap_candidates`, mặc định 2) ⇒
      số lần gọi model edit tăng theo cấu hình đó — cân nhắc chỉnh `swap_candidates` = 1 nếu muốn tiết kiệm.
- [ ] 6 test đỏ sẵn có của phiên Collections/Canvas vẫn chưa sửa (thiếu `.motion-ui` ở `hover:text-white`).

---

## Phiên 2026-09-22 (Đợt 6 — Agent Studio BIẾT MODEL: hai agent suy luận thật qua nhóm 'prompt')

**Commit:** `b98b2c4` (feat) + `06ee658` (đọc JSON) + `da35124` (thang thử lại). **Đã deploy production**
(không migration; có thay đổi frontend ⇒ đã rebuild asset `main-B3d2N_ZM.js`).

### Vấn đề người dùng báo

«Agent Studio chưa nhận biết model hoặc chưa hoạt động.» — **đúng theo nghĩa đen**: cả `TrendRadar` lẫn
`CollectionBot` chạy **100% rule-based**, KHÔNG gọi model nào (`engine: rule-based-v1`), và giao diện không có
bất kỳ dấu hiệu nào cho biết AI có đang chạy. Đổi Model Registry / Nhóm công việc trong Cài đặt xong kết quả y nguyên.

### Kiến trúc mới: HAI TẦNG TÁCH BẠCH

| Tầng | Nguồn | Vai trò |
|---|---|---|
| **Số liệu** | catalog mẫu + project/generation của chính user | Quyết định MỌI con số. Luôn `source_mode=demo` / `evidence_mode=demo` |
| **Suy luận** | `AiModelGateway` → nhóm công việc **`prompt`** | Viết định hướng / brief / caption / prompt. **Chỉ trả về CHỮ** |

⇒ AI **không thể bịa số liệu thị trường**: mọi con số vẫn do tầng dữ liệu quyết định (test khoá điều này).

### Backend

- `DesignAgentService` nhận `AiModelGateway` (tham số **bắt buộc**, xem mục bẫy bên dưới).
- `radar()`: model viết **5–10 định hướng** (title/thesis/why_now/action/risk/price_band/confidence/trend_ids),
  mỗi hướng bám vào id xu hướng **có thật** trong catalog (id bịa bị loại, confidence được kẹp 0..1);
  cache **10 phút** theo vùng + model (payload gửi model KHÔNG chứa dữ liệu nội bộ của shop nên cache dùng chung an toàn).
- `collectionBrief()`: model viết narrative · brief · **24 caption mood board** · lý do từng nhóm hàng · mục tiêu từng look ·
  prompt VI/EN · 3 bước tiếp theo. **Brief người dùng tự viết luôn thắng.**
- Mọi phản hồi có khối `model`: `{group, mode, provider, model, candidates, available, latency_ms, cached, attempts, reason, attempted}`
  — `reason` là `no_model_key` | `model_error` | `invalid_output` | `ai_disabled` khi phải quay về tất định.
- Endpoint nhận cờ `ai` (mặc định **bật**): tắt ⇒ engine tất định, **không gọi model** (test xác nhận `assertNothingSent`).

### Frontend (Agent Studio)

- **Chip model ngay header**: `AI · deepseek · deepseek-flash` (xanh) hoặc `Engine tất định` (vàng) + tooltip nói rõ
  provider:model, nhóm công việc, số ms, có lấy từ cache không.
- Khi chạy tất định: dải **giải thích LÝ DO** + chỉ thẳng nơi cấu hình (Cài đặt → Nhóm công việc → “Suy luận prompt”).
- Công tắc **“Suy luận AI: BẬT/TẮT”** (bỏ cache radar khi đổi để kết quả không lẫn giữa 2 chế độ).
- Khối **ĐỊNH HƯỚNG** mới ở bước Tín hiệu: từng hướng ghi rõ nguồn `AI`/`tất định`, nút “Chọn trend theo 3 hướng đầu”,
  chip trend bấm được ngay trong thẻ.
- Bước Định hướng: hiện model + **những phần AI đã viết** (DNA, brief, 24 caption, …) + cảnh báo khi brief được dựng ở chế độ khác công tắc.
- Trạng thái chờ nói thẳng: “Đang gọi model của nhóm “prompt” để viết định hướng…”.

### Chẩn đoán THẬT trên production (3 vòng deploy)

| Vòng | Quan sát | Kết luận |
|---|---|---|
| 1 (`b98b2c4`) | cả hai agent chạm tới model (3 candidate deepseek) nhưng `reason=invalid_output` | model CÓ trả lời, bộ đọc JSON mới là chỗ hỏng |
| 2 (`06ee658`, thêm log đầu ra thô) | radar: model trả về đúng **6 ký tự** `{"dire`; collection: `content` **RỖNG**, chỉ còn `reasoning_content` là văn xuôi suy luận | `deepseek-flash` là model **SUY LUẬN** — token suy luận tính VÀO `max_tokens`, prompt dài ⇒ cạn ngân sách trước khi viết xong JSON |
| 3 (`da35124`) | `engine=ai-v1`, **6/6 định hướng do AI**, brief đầy đủ | xong |

Sửa ở vòng 2–3: đọc JSON chịu được **code fence** và **JSON bị cắt** (đếm ngoặc thật + cắt về phần tử hoàn chỉnh cuối rồi đóng ngoặc),
`max_tokens` 2048→3000 / 3000→4000, yêu cầu model viết ngắn, và **thang thử lại**: lần đầu không đọc được JSON ⇒ gọi lại MỘT lần
với ngân sách 8000 + timeout gấp đôi (`AiModelGateway::text()` nay trả thêm `finish_reason` + `reasoning_only` để biết vì sao).

```
⚠️ BẪY LARAVEL đã dính và đã sửa:
   __construct(?AiModelGateway $gateway = null)  -> container KHÔNG inject, luôn truyền default null
   __construct(?AiModelGateway $gateway)         -> inject đúng (tham số BẮT BUỘC, kiểu nullable)
   Triệu chứng: service im lặng chạy tất định dù Cài đặt đã có model. Đã khoá bằng test.
```

### Bằng chứng production sau deploy (gọi thật, không mock)

```json
{ "radar": { "engine": "ai-v1", "provider_model": "deepseek:deepseek-flash", "attempts": 1,
             "directions": 6, "ai_directions": 6,
             "first": { "title": "Blazer linen tối giản làm hero SKU",
                        "why_now": "Cả tailoring tối giản và linen đều đang ở đỉnh tín hiệu…",
                        "action": "May 3 màu trung tính, 2 form, dồn 60% lượng vải cho mã này.",
                        "risk": "Linen dễ nhăn, cần chọn vải pha để giữ form và giảm đổi trả.",
                        "price_band": "mid", "confidence": 0.88 } },
  "collection": { "engine": "ai-v1", "ai_applied": { "narrative": true, "brief": true,
                  "moodboard_captions": 24, "category_rationale": 4, "outfit_goals": 3,
                  "prompts": true, "next_steps": true }, "wall_ms": 12575 } }
```

Số liệu trong brief AI vẫn là số của HỆ THỐNG (12 SKU = 5 áo/blouse + 3 quần + 2 váy + 2 phụ kiện) — AI chỉ diễn đạt lại.

### Verify production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `da35124` |
| Trang | `/` `/dang-nhap` `/up` → **200** |
| Bundle | `main-B3d2N_ZM.js` chứa “Suy luận AI” · “Engine tất định” · “Định hướng từ TrendRadar” · “Chọn trend theo 3 hướng đầu” |
| Log | ERROR vẫn **8** dòng (không phát sinh lỗi mới); các WARNING cũ của 2 vòng chẩn đoán đã ngừng sinh |
| Độ trễ | radar ~13.6 s (lần đầu mỗi vùng, sau đó **cache 10 phút**), brief ~12.6 s |

### Test

**15 test** `DesignAgentAiTest` (mới) + 12 test `AiModelGatewayTest` (đợt 5). Full suite **776 pass / 6 fail** —
vẫn đúng 6 lỗi SẴN CÓ ở HEAD (Collections/Canvas), không liên quan đợt này.

### Còn lại (đề xuất)

- [ ] Radar mất ~13 s ở lần gọi đầu cho mỗi vùng: có thể hạ bằng model không-suy-luận (đặt `studio_task_prompt_model`,
      ví dụ `deepseek:deepseek-chat`) hoặc tăng thời gian cache.
- [ ] `DesignAgentService::radar()` gọi model cho CẢ khối catalog; nếu sau này có connector thật thì nên chunk theo nguồn.
- [ ] 6 test đỏ sẵn có của phiên Collections/Canvas vẫn chưa sửa.
