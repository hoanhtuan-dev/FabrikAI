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

---

## Phiên 2026-09-22 (Đợt 7 — Agent Studio: tầng TIỀN + dữ liệu bán hàng thật của shop)

**Commit:** `08ed60a` (tầng tiền + dữ liệu shop) + `6367796` (đối chiếu giá). **Đã deploy production**, có migration
`2026_09_22_000003_create_shop_signals_table` (đã chạy: DONE 194ms) và rebuild asset `main-Bfw9_p_k.js`.

### Vì sao làm đợt này

Đặt mình vào người TRẢ TIỀN (chủ xưởng muốn ra bộ sưu tập để sản xuất → bán chạy → lợi nhuận): Agent Studio đợt 6
dừng ở "định hướng + brief chữ + 24 ô màu", tức là **chưa trả lời được câu hỏi ra tiền**: cắt bao nhiêu cái, đặt
bao nhiêu mét vải, giá vốn bao nhiêu, bán giá nào thì lãi, cần bao nhiêu vốn. Ba thiếu sót quyết định giá trị:

| Thiếu | Hệ quả với chủ xưởng | Đợt này |
|---|---|---|
| Không có giá thành/lợi nhuận | "Dải giá Mid-range" không giúp quyết định gì | ✅ `CollectionPlanService` |
| Không có lệnh sản xuất | "12 SKU" vô nghĩa nếu không biết cắt gì trước, bao nhiêu | ✅ lệnh cắt + 3 đợt |
| Không có dữ liệu thật của shop | Mọi lời khuyên là phỏng đoán theo từ khoá | ✅ bảng `shop_signals` |

### 1. Tầng TIỀN — `CollectionPlanService` (mới, TẤT ĐỊNH, không dùng AI cho con số)

- **Lệnh cắt** theo NHÓM × SIZE; chia số lượng bằng **phần dư lớn nhất** nên tổng LUÔN khớp `số mã × số lượng/mã`
  (làm tròn từng dòng riêng lẻ sinh lệch 2 cái — chủ xưởng đọc tổng không khớp là mất tin ngay).
- **Giá vốn/cái** = vải (định mức theo nhóm × hệ số size × tiêu hao) + phụ liệu + giá công + bao bì + tỉ lệ lỗi.
- **3 đợt 25/40/35** — cắt thử mỏng (đợt duy nhất còn sửa sai được) → dồn cho mã bán chạy → dứt điểm; mỗi đợt có
  số cái, mét vải, vốn, lãi, số ngày và danh sách mã.
- **Bảng size số đo (cm)** theo nhóm hàng + số cái cần cắt mỗi size (ghi rõ phải đối chiếu rập thật của xưởng).
- **3 kịch bản giá** (sàn/mục tiêu/trần) kèm điểm hoà vốn; chặn giá trị vô lý (âm, % > 100).
- `price_check` + ghi chú: đối chiếu giá bán gợi ý (giá vốn × lãi mong muốn) với dải giá thị trường →
  `above_band` (bán cao hơn thị trường mới đủ lãi ⇒ phải giảm giá vải/định mức TRƯỚC khi cắt) hoặc `below_band`
  (dư địa lãi, nhưng đừng bán rẻ hơn mức khách chấp nhận).

### 2. Dữ liệu bán hàng THẬT — bảng `shop_signals` (mới)

- 6 cột số tối giản (tên · nhóm · đã bán · tồn · đổi trả · giá bán) — người bán qua Facebook/Zalo/POS nhập tay hoặc
  **dán thẳng từ Excel**; giới hạn 200 dòng/tài khoản, lưu theo `user_id` (cascade khi xoá tài khoản).
- `internalBrandSignal()` nay dùng số THẬT: bán chạy, tồn, đổi trả, giá bình quân, sell-through, hàng chậm — thay cho
  việc dò từ khoá trong prompt. `data_mode` = `local` khi có dữ liệu, `empty` khi chưa.
- **Cơ cấu SKU ăn dữ liệu thật**: +1 SKU cho nhóm BÁN CHẠY NHẤT, −1 SKU cho nhóm TỒN NHIỀU mà bán chậm; `structure.basis`
  = `shop_data`. **Dải giá NEO quanh giá bán bình quân thật (±20%)** thay vì bám từ khoá.
- Dữ liệu shop chỉ gửi cho model ở đường brief (đường này KHÔNG dùng cache chung) — radar vẫn cache dùng chung an toàn.

### 3. Endpoint + giao diện

- `POST /api/design-agent/plan` (tất định, `Http::assertNothingSent` trong test) và `POST /api/design-agent/shop-signals`
  (chỉ ghi dữ liệu của chính người dùng) — gắn vào module `collection_bot`.
- Tab mới **"Sản xuất & lãi"**: form **13 đơn giá/định mức chỉnh được** (sửa là tính lại, gộp 500ms), KPI (tổng cái ·
  vải cần đặt · vốn cần · lãi gộp), bảng lệnh cắt 16 dòng, 3 thẻ đợt, bảng size, 3 kịch bản giá, callout đối chiếu giá.
- **Xuất file cho thợ cắt**: CSV lệnh cắt + CSV bảng size (BOM UTF-8 để Excel hiện đúng tiếng Việt) + sao chép lệnh cắt.
- Khối **"Dữ liệu bán hàng của shop"**: dán từ Excel (Tab/phẩy/chấm phẩy, tự bỏ dòng tiêu đề), bảng nhập tay, lưu và
  **tự tạo lại brief** theo số mới.

### Đo THẬT trên production sau deploy (gọi service, không mock)

```
12 mã × 30 cái = 360 cái · 16 dòng cắt · vải 547,7m → đặt 580m (49,3tr)
Giá vốn TB 230.023đ/cái · giá bán mục tiêu 875.000đ · lãi 474.850đ/cái (66,2%)
Vốn cần 82.808.200đ · doanh thu 258.300.000đ · lãi gộp 175.491.800đ (67,9%) · 15 ngày
Đợt 1: 91 cái / 150m / vốn 20,9tr / 4 ngày   Đợt 2: 144 cái / 240m / 33,1tr / 6 ngày   Đợt 3: 125 cái / 200m / 28,7tr / 5 ngày
Bảng size áo S: Ngực 84 · Eo 68 · Mông 92 · Dài áo 60,5 · Vai 37 (tăng dần theo size)
3 mức giá: sàn 550.000 → lãi 79,5tr (49%) · mục tiêu 875.000 → 175,5tr (67,9%) · trần 1.200.000 → 271,4tr (76,6%)
```

Chính phép đo này lộ ra một con số dễ gây tin sai (biên lãi 66%) ⇒ đó là lý do `price_check` ra đời ở commit sau:
công thức đúng nhưng thị trường chưa chắc trả mức đó, nên hệ thống phải NÓI RA độ lệch thay vì để chủ xưởng tin.

### Verify production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `6367796` |
| Migration | `2026_09_22_000003_create_shop_signals_table` → DONE · `shop_signals: OK` |
| Route | `POST api/design-agent/plan` · `POST api/design-agent/shop-signals` có trong `route:list` |
| Trang | `/` `/dang-nhap` `/up` → **200** |
| Bundle | `main-Bfw9_p_k.js` chứa "Sản xuất & lãi" · "LỆNH CẮT" · "Dữ liệu bán hàng của shop" · "Lệnh cắt (CSV)" · "Bảng size (cm)" |

**Test:** 17 test mới `CollectionPlanTest` (lệnh cắt khớp tổng · 3 đợt cộng đúng · đổi đơn giá ⇒ đổi kết quả ·
chặn giá trị vô lý · endpoint không gọi AI · `above_band`/`below_band` · dữ liệu shop theo tài khoản và ẩn giữa
các user · validate). Full suite **793 pass**; 6 fail vẫn đúng 6 lỗi SẴN CÓ ở HEAD (Collections/Canvas).

### Còn lại để "đáng trả tiền" trọn vẹn

- [ ] **Ảnh thật**: mood board vẫn 24 ô màu (`image_url: null`) và production CHƯA có key model ảnh (nhóm `image/edit/`
      `video/swap` đều rỗng) ⇒ chuỗi giá trị vẫn đứt ở bước nhìn thấy sản phẩm. Cần nối model ảnh hoặc ảnh tham chiếu thật.
- [ ] **Đối chiếu dự đoán với thực tế**: lưu kế hoạch tại thời điểm chốt rồi sau 2–4 tuần so với số bán thật để biết hướng
      nào đúng/sai (hiện mới có chiều nhập dữ liệu vào).
- [ ] Dữ liệu xu hướng vẫn là catalog mẫu (`evidence_mode: demo`) — chưa có connector thật, và điều đó vẫn phải nói thật.

---

## Phiên 2026-09-22 (Đợt 8 — Vùng toolbar cao CỐ ĐỊNH + mọi thanh ngữ cảnh phải tôn trọng, cuộn trục X)

**Phạm vi:** 2 file nguồn + 1 file test + asset build. **KHÔNG migration, KHÔNG route mới, KHÔNG lớp PHP mới.**
Commit `2c393b6` (`b3507ca → 2c393b6`).

### 1. Lỗi

Vùng toolbar phía trên canvas **cao theo công cụ**: rail dùng `min-h-12` (chiều cao TỐI THIỂU) còn mỗi thanh
ngữ cảnh tự đặt `flex flex-wrap … py-2` nên tự do xuống dòng. Đổi công cụ là thân canvas bị đẩy xuống.

### 2. Đo THẬT trước/sau (Chrome headless · component THẬT + CSS build THẬT · 10 biến thể)

| Biến thể | TRƯỚC | SAU |
|---|---|---|
| Không công cụ (placeholder) | 36px | **47px** |
| Vùng chọn · crop · film look · chọn layer · quét chọn · di chuyển · xoá vùng | 52–56px | **47px** |
| **Vẽ tự do** (9 ô thông số) | **94px** (dồn thành 2 hàng) | **47px** |

47px = `h-12` trừ `border-b` 1px. Mọi thanh cao đúng 36px (`h-9`); `childOverflowY = 0` — **không phần tử nào
thò ra ngoài** khuôn. Khi tràn bề ngang thì rail **cuộn trục X**: "Vẽ tự do" rộng 1351px trong rail 1254px ⇒
`scrollWidth 1375 > clientWidth 1254`. Mobile: khối nổi cao cố định 44px, cuộn X ở "Vẽ tự do" và "Quét chọn".

### 3. Sửa

- `ContextToolbar.vue`: 9 biến thể + placeholder dùng **CHUNG một khuôn** `bar`
  (`h-9 w-max shrink-0 flex-nowrap whitespace-nowrap`) thay cho `flex-wrap py-2` riêng lẻ.
- `StudioApp.vue`: rail desktop **`h-12` cố định** + lớp trong `mx-auto flex w-max` để cuộn trục X
  (`justify-center` trên khung cuộn sẽ đẩy mép **TRÁI** ra ngoài tầm với — bấm/kéo không tới được);
  `scrollbar-hide` để thanh cuộn không ăn mất chiều cao đã cố định. Khối nổi mobile: `h-11` + cuộn X.
- `tests/Feature/ToolbarAreaTest.php` (mới, 4 test): rail cao cố định (cấm `min-h-12`/`py-`) · cuộn X không cuộn Y ·
  **MỌI gốc template** của thanh ngữ cảnh phải theo khuôn chung. Đã **thử đột biến**: cho `bar` quay lại `flex-wrap`
  → ĐỎ; cho rail quay lại `min-h-12` + `justify-center` → ĐỎ.

### 4. Verify production (đã chạy thật)

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `2c393b6` (trước pull: `6367796`) |
| Migration | `Nothing to migrate` (đúng — không có migration mới) |
| Cache | `config:cache` · `route:cache` · `view:cache` · `queue:restart` → **exit=0** cả bốn |
| Trang | `/` `/dang-nhap` `/up` `/bang-gia` → **200** |
| Asset mới | `app-DBHwKOWn.css` **200 · 165.897 B** · `main-CAUgn08q.js` **200 · 558.717 B** — **md5 GIỐNG bản build ở máy** |
| Asset cũ | `app-BS-WmmGX.css` → **404** (đã thay) |
| CSS phục vụ thật | có `.h-12` `.h-11` `.w-max` `.items-stretch` `.flex-nowrap` — **không còn** `.min-h-12` |
| JS phục vụ thật | chứa `flex h-9 w-max shrink-0 flex-nowrap` + `mx-auto flex w-max items-center gap-2 px-3` |
| Nhật ký | **không phát sinh ERROR mới** (8 dòng cũ: 17–19/09 — Eloquent cache · generation failed · qwen hết quota) |

**Test:** full suite **803 test / 5561 assert**, 6 fail — **đúng 6 lỗi SẴN CÓ** (đã đối chiếu bằng cách stash thay đổi
rồi chạy lại trên HEAD sạch: CollectionsHub · JobTemplates · MotionFoundation · ShotReview ×2 · StaticIntegrity).
Nhóm test thanh công cụ (CanvasControls · StudioGuiConfig · BatchGeneration): **45/45 xanh**.

### 5. Ghi chú vận hành (không do đợt này)

- `jobs = 1` · `failed_jobs = 0` · `generations pending = 0` — job cũ 1 ngày vẫn nằm trong hàng đợi, **không có
  generation nào kẹt** ⇒ không ảnh hưởng người dùng.
- `storage/logs/worker.log` **không tồn tại** ⇒ cron worker (DEPLOY.md §3) vẫn chưa được tạo; hệ thống đang chạy
  bằng lưới an toàn 90 giây. Vẫn đúng như đã ghi ở §8.3, **chưa xử lý trong đợt này**.
- Đồng hồ máy chủ lệch (báo `Sat Sep 19 15:50 UTC`) — giờ địa phương lúc deploy: `2026-09-19 22:50 +07`.

---

## Phiên 2026-09-22 (Đợt 9 — Tách "Ghép trang phục" khỏi card "Ghép ảnh" thành CARD RIÊNG)

**Commit:** `905ac49`. **Đã deploy production** (không migration), rebuild asset `main-DA-_QEtX.js`.

### Vì sao tách

Hai việc khác nhau về bản chất — để chung một card thì người dùng phải ĐỔI CHẾ ĐỘ rồi mới thấy đúng
công cụ của mình, còn card "Ghép ảnh" bị đội thêm 6 khối điều khiển chỉ dùng cho chế độ kia:

| | Ghép ảnh | Ghép trang phục |
|---|---|---|
| Việc | dựng BỐ CỤC | LAI TẠO BIẾN THỂ |
| Slot | @image1 nền chính · @image2/3 ảnh ghép | @image1+@image2 trang phục nguồn · @image3 bối cảnh |
| Tham số | không có | phong cách · trang trí · sáng tạo · preset theo tài khoản · biến thể theo trục |

### Thay đổi

- `ModuleRegistry`: thêm module **`outfit`** (panel, icon `shirt`, nhóm "Chỉnh ảnh") đặt NGAY SAU `compose` để
  hai nút nằm cạnh nhau; dùng CHUNG đường `/compose` + `/outfit-settings` với card Ghép ảnh — cùng một
  pipeline, hai cửa vào (đúng mẫu `refgen` dùng chung cho `variation` + `tryon`). `StudioGuiConfig` sinh mục
  thanh công cụ từ bản khai này nên owner đổi được nhãn/icon/thứ tự/ẩn-hiện như mọi card khác.
- `OutfitComposeCard.vue` (**mới**): 3 slot theo vai trò trang phục, prompt lai tạo + nút **"Prompt lai tạo mẫu"**
  (khôi phục prompt gốc), Phong cách · Trang trí · Sáng tạo, Preset phong cách (lưu theo tài khoản), số biến thể
  + trục Classic/Modern/Bold/Fluid, xem trước prompt, tiến độ "AI đang lai tạo trang phục…", so sánh Trước/Sau.
  Nạp cài đặt tài khoản TRƯỚC rồi mới điền prompt mẫu (không ghi đè dữ liệu đã lưu).
- `ComposeCard.vue`: gỡ toàn bộ chế độ `outfit` (tab chuyển chế độ, phong cách/trang trí/sáng tạo, preset, nạp/lưu
  cài đặt) — chỉ còn dựng bố cục; thêm dòng hướng dẫn vai trò slot. **Nhẹ hơn ~120 dòng.**
- `StudioApp.vue`: thêm `outfit` vào `ACTIVITY_CARDS` + mục dự phòng thanh công cụ.

### Verify production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `905ac49` |
| Panel | `collections · concept · variation · tryon · inpaint · compose · outfit · upscale · director` |
| Bundle | `main-DA-_QEtX.js`: có "Ghép trang phục" · "Prompt lai tạo mẫu" · "nền chính"; **không còn** "Ghép tự do" |
| Trang | `/` `/up` → **200** |
| Module | `outfit` (panel, `shirt`) cấp cho **mọi gói** (`plans: ['*']`) |

**Lưu ý production:** `studio_gui_activity_bar` ĐÃ có bản owner lưu, nên id mới được NỐI VÀO CUỐI (đúng cơ chế
`StudioGuiConfig::all()`). Đã sắp lại để "Ghép trang phục" đứng NGAY SAU "Ghép ảnh":
`… inpaint | compose | outfit | upscale | stylist | prompt | director | collections | settings`.

**Test:** `StudioGuiConfigTest` + `ModuleRegistryTest` đối chiếu PHP ⇄ Vue (id panel phải khớp `ACTIVITY_CARDS` và bản
dự phòng) — cập nhật số panel 8 → 9 một cách có ý thức. Full suite **797 pass**; 6 fail vẫn là 6 lỗi SẴN CÓ ở HEAD.

### Ghi chú kỹ thuật

- Hai card dùng CHUNG state tiến trình (`composeStage` · `composeGenIds` · `composeError`) và chung đường `/api/compose`;
  mỗi lúc chỉ một card được render (activity bar chọn 1 mục) nên tiến độ không bị lẫn giữa hai card.
- `compose()` truyền `mode='compose'` với `creative_level/style/ornament` trung tính: backend chỉ dùng ba tham số đó
  cho `mode='outfit'` nên hành vi ghép bố cục KHÔNG đổi.

---

## Phiên 2026-09-22 (Đợt 10 — "Ghép ảnh" → **STUDIO**: phòng chụp thời trang chuyên nghiệp)

**Commit:** `75b13ec`. **Đã deploy production** (không migration), rebuild asset `main-DRHkRUDe.js`.

### Phân tích sâu trước khi làm

Một buổi chụp thật KHÔNG bắt đầu từ "ghép mấy tấm ảnh". Nó bắt đầu từ **bối cảnh chủ đề + sơ đồ đèn +
ống kính + dáng**, rồi mới ra **danh sách ảnh (shot list)** — và mọi tấm trong bộ phải trông như **cùng một
buổi chụp**. Ba thứ quyết định ảnh có bán được hay không:

| # | Yếu tố | Trước đợt này | Sau đợt này |
|---|---|---|---|
| 1 | **Bối cảnh chủ đề** theo bộ sưu tập | không có (chỉ prompt tự do) | 14 preset bối cảnh + bối cảnh tự nhập |
| 2 | **Tính nhất quán** giữa các tấm | không có | `look signature` giống hệt nhau ở MỌI prompt |
| 3 | **Độ trung thực của trang phục** | không nêu rõ | điều khoản "100% fidelity" mở đầu mọi prompt |

### Backend — `PhotoStudioService` (mới, TẤT ĐỊNH, không gọi model nào)

- **14 bối cảnh chủ đề**: studio trắng vô cực · xám khói · đen kịch tính · tường vôi Hội An · phố cổ Hà Nội
  mùa thu · biển Đà Nẵng · vườn nhiệt đới · quán cà phê Sài Gòn · đêm neon · Tết cổ truyền · công sở tối giản ·
  đồi chè Tây Bắc · runway · vintage phim — mỗi bối cảnh có **bảng màu · đạo cụ · sơ đồ đèn gợi ý · mẹo dùng**.
- **9 sơ đồ đèn** (softbox đều · beauty dish · cửa sổ mềm · nắng vàng · nắng gắt trưa · đèn cứng editorial ·
  neon · flash runway · nắng lốm đốm), **6 ống kính** (85/50/35/medium-format/phim/tele), **8 dáng**, 
  **9 loại ảnh** (kèm tỉ lệ gợi ý + mục đích), **6 phong cách hậu kỳ**.
- `plan()`: mỗi tấm một prompt hoàn chỉnh; **`look_id` + `look_signature` dùng CHUNG cho mọi tấm**
  (cùng người mẫu · nền · hướng sáng · grade) nên bộ ảnh đồng bộ; giữ trang phục là điều kiện số một.
- Đếm **đúng** số ảnh/credit; cảnh báo khi thiếu ảnh tham chiếu, khi vượt trần 12 ảnh/buổi, và **NÓI THẬT**
  khi chưa cấu hình model ảnh (`image_ready=false` ⇒ chế độ demo).
- Endpoint **`POST /api/studio/shoot/catalog`** + **`POST /api/studio/shoot/plan`** (throttle 60/phút, KHÔNG tạo
  generation, KHÔNG tốn credit). Module `compose` đổi **tên hiển thị → "Studio"**, icon `camera`, thêm endpoint
  `studio/shoot` — **giữ nguyên id `compose`** để không phá cấu hình thanh công cụ đã lưu và các đường /compose sẵn có.

### Frontend — `StudioCard.vue` (mới, thay `ComposeCard.vue` đã xoá)

- 3 slot tham chiếu: **trang phục (giữ nguyên)** · người mẫu/dáng · bối cảnh.
- Lưới **bối cảnh chủ đề** (màu + mùa + đèn gợi ý) và chế độ **bối cảnh tự nhập**.
- Chips **ánh sáng · ống kính · dáng · hậu kỳ**; ánh sáng bỏ trống = theo bối cảnh.
- **Checklist danh sách ảnh** 9 loại + biến thể + tỉ lệ (ghi đè hoặc theo từng loại).
- Tên **bộ sưu tập** (tự điền từ bộ đang áp dụng) + ghi chú **người mẫu** / **trang phục (AI phải giữ đúng)**.
- Khối kết quả: cảnh báo, xem/**sửa prompt từng tấm**, ước tính credit trước khi chạy, nút **"Chụp N ảnh"**.
- `runShoot()` chạy MỖI TẤM một generation qua đúng pipeline `/api/compose` sẵn có ⇒ dùng lại credit, hàng đợi,
  Outputs, bảng Lớp và huỷ giữa chừng.

### Đo THẬT trên production sau deploy

```
activity_bar: … | Sửa ảnh[inpaint/pencil] | Studio[compose/camera] | Ghép trang phục[outfit/shirt] | Upscale …
catalog: backdrops=14 lighting=9 cameras=6 poses=8 shots=9 styles=6
image_ready = no  ⇒ cảnh báo: chạy CHẾ ĐỘ DEMO, trả ảnh mẫu (chưa có key nhóm 'edit')
plan: LOOK-CCDC60 · 3 tấm · 3 ảnh · 6 credit
setup: Tường vôi Hội An · Nắng vàng cuối ngày · 85mm f/1.8 · Bước đi · Lookbook theo mùa
--- SHOT 1 ---
Professional fashion photograph for a lookbook. The model wears the EXACT garment shown in the reference
image — reproduce its fabric, colour, print, cut, seams, trims and proportions with 100% fidelity; do NOT
redesign… FRAME: full body from head to toe… MODEL: model walking toward the camera mid-stride…
--- SHOT 3 (cận chi tiết vải) --- … FRAME: tight macro detail of the fabric weave, stitching and finish …
```

### Verify production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `75b13ec` |
| Route | `POST api/studio/shoot/catalog` · `POST api/studio/shoot/plan` |
| Thanh công cụ | nhãn **Studio** + icon `camera` (đã cập nhật cả bản cấu hình owner ĐÃ LƯU, vì `all()` ưu tiên nhãn đã lưu) |
| Bundle | `main-DRHkRUDe.js` có "Phòng chụp thời trang" · "Bối cảnh chủ đề" · "Danh sách ảnh" · "Bối cảnh tự nhập"; **"Ghép ảnh" = 0** |
| Trang | `/` `/up` → **200** |

**Test:** 13 test mới — 8 unit `PhotoStudioServiceTest` (look signature dùng chung cho MỌI tấm · `look_id` chỉ đổi
khi đổi bối cảnh · đếm ảnh/credit · cảnh báo thiếu ảnh + chế độ demo · id lạ về mặc định an toàn · khử trùng + trần 12
ảnh · tỉ lệ) và 5 feature `PhotoStudioTest` (401 khách · catalog · plan KHÔNG gọi model · validate · module lock).
Full suite **810 pass**; 6 fail vẫn là 6 lỗi SẴN CÓ ở HEAD.

### Còn lại (đề xuất)

- [ ] **Chưa có key model ảnh trên production** (nhóm `image`/`edit`/`video`/`swap` đều rỗng) ⇒ Studio chạy demo;
      cần nối khoá để buổi chụp ra ảnh thật. Giao diện đã nói thẳng điều này thay vì trả ảnh mẫu im lặng.
- [ ] Chưa có **bối cảnh theo bộ sưu tập lưu sẵn** (mỗi bộ sưu tập nhớ bối cảnh/đèn/dáng của nó); hiện setup được
      chọn lại từng buổi. Nên gắn `look_id` vào project để mở lại đúng buổi chụp cũ.
- [ ] Chưa hỗ trợ **nhiều người mẫu trong cùng khung** (lookbook đôi) và **đổi bối cảnh giữ nguyên ảnh gốc**
      (retouch lại phần nền) — đều cần model ảnh thật mới kiểm chứng được.

---

## Phiên 2026-09-22 (Đợt 11 — STUDIO thiết kế lại luồng: ảnh người mẫu + bối cảnh + prompt + CHIP từ «Cài đặt của tôi»)

**Commit:** `202b6ac` (luồng mới) + `b7cfde1` (nhãn nhóm chip). **Đã deploy production** (không migration),
rebuild `main-508WxgnN.js` / `my-settings-Dwa9SIL2.js`.

### Luồng mới (theo yêu cầu)

```
ảnh 1 = NGƯỜI MẪU MẶC TRANG PHỤC (kết quả bước trước — GIỮ NGUYÊN, đây là sản phẩm)  [bắt buộc]
ảnh 2 = BỐI CẢNH                                                                        [tùy chọn]
ảnh 3 = THAM CHIẾU THÊM (chi tiết / phụ kiện / màu)                                    [tùy chọn]
+ ô nhập prompt  +  CHIP NHANH đọc từ PRESET trong «Cài đặt của tôi»
```

**Đã bỏ** theo yêu cầu: khối **Ánh sáng**, ô **Bộ sưu tập / chủ đề** — và cả bộ máy dựng sẵn trước đó
(14 bối cảnh chữ · ống kính · dáng · danh sách ảnh), vì người dùng đã có nguồn chân lý riêng: **PRESET**.

### Backend

- `PhotoStudioService` viết lại:
  - **`chipGroups()` / `chipIndex()`** ghép preset DÙNG CHUNG (bảng `presets`) với bản của CHÍNH tài khoản
    (`user_catalogs` name `presets`: `custom` / `edits` / `hidden`) theo **đúng logic `useLocalCatalog.merge()`**
    ở giao diện ⇒ Studio thấy đúng thứ người dùng thấy ở Cài đặt (mục tự thêm, mục bị ẩn, mục bị sửa);
  - **`scene()`** dựng prompt theo thứ tự ưu tiên **GIỮ SẢN PHẨM > BỐI CẢNH > CHỈ DẪN người dùng**, chỉ nhắc
    `SECOND`/`THIRD image` khi người dùng THỰC SỰ chọn ảnh đó;
  - chip gửi lên chỉ là **ID**, đoạn chèn luôn tra từ Cài đặt ⇒ không tin nội dung client gửi;
  - đếm đúng ảnh/credit; cảnh báo thiếu ảnh 1 · thiếu cả prompt lẫn chip · quá 12 chip · chip đã bị xoá/ẩn,
    và **NÓI THẬT** khi chưa cấu hình model ảnh (chế độ demo).
- Endpoint giữ nguyên đường: `POST /api/studio/shoot/catalog` (chip + 3 vai trò ô ảnh + tỉ lệ + `settings_url`)
  và `POST /api/studio/shoot/plan` (prompt dựng sẵn).
- **`/api/compose` + `/api/compose/preview` nhận TỪ 1 ẢNH** (trước bắt buộc 2): Studio chỉ có ảnh người mẫu khi
  người dùng không chọn ảnh bối cảnh; prompt của Studio đi qua `final_prompt` nên phần "ghép nhiều ảnh" không dùng tới.
  Không ảnh hưởng card khác (họ luôn gửi từ 2 ảnh).
- **Nhãn nhóm**: bảng presets có **17 danh mục** nhưng cả `CHIP_CATEGORY_LABELS` (Studio) lẫn `CAT_LABELS`
  (trang Cài đặt của tôi) chỉ khai 9 ⇒ bổ sung 8 nhãn còn thiếu (Màu sắc · Cổ áo · Tay áo · Độ vừa vặn ·
  Họa tiết · Chi tiết · Dịp mặc · Mùa) ở CẢ HAI nơi — trước đó chip lọc hiện mã thô `color`, `neckline`…

### Frontend — `StudioCard.vue` viết lại

- 3 ô ảnh theo đúng vai trò, ghi rõ **bắt buộc / tùy chọn** ngay trên từng ô;
- ô nhập prompt (đếm ký tự) + **CHIP NHANH nhóm theo danh mục preset**, chip "Đang dùng", nút **«Sửa chip»** mở
  thẳng `/cai-dat/presets`;
- số biến thể + tỉ lệ · **xem/sửa prompt gửi AI** (bản sửa tay được ưu tiên) · cảnh báo · ước tính credit · chạy 1 lần;
- `store.js`: `sceneCatalog/scenePlan/sceneSetup/sceneEditedPrompt` + `loadSceneCatalog`, `setSceneSetup`,
  `toggleSceneChip`, `clearSceneChips`, `planScene` (tất định), `scenePrompt`, `runScene` (qua `/api/compose`).

### Đo THẬT trên production sau deploy

```
preset dùng chung = 198 · tổng chip = 198 · 17 nhóm:
Chất liệu 21 · Màu sắc 16 · Phom dáng 12 · Cổ áo 10 · Tay áo 11 · Độ vừa vặn 8 · Họa tiết 12 · Phong cách 22 ·
Chi tiết 13 · Dịp mặc 10 · Mùa 5 · Bối cảnh 18 · Góc máy 8 · Ống kính 7 · Kịch bản quay 8 · Dáng đứng 12 · Sửa ảnh 5

scene(2 ảnh, 2 biến thể, 4:5) → images=2 credits=4 · used_chips=[Chất liệu/Lụa bóng, Chất liệu/Lụa sa tanh bóng]
warnings=[Chưa cấu hình model tạo/sửa ảnh (nhóm "edit") — kết quả sẽ là ẢNH MẪU (chế độ demo)…]

PROMPT: "Professional fashion photograph. The FIRST image is the model wearing the garment: keep her identity,
face, hair, body proportions and pose, and keep the garment EXACTLY as it is … 100% fidelity — do NOT redesign …
SCENE: place the model naturally into the setting shown in the SECOND image — match its perspective, camera height,
scale, ground contact and lighting direction … DIRECTION: đặt cô ấy vào quán cà phê Sài Gòn …
DETAILS: silk satin fabric with a subtle sheen · … QUALITY: photorealistic, sharp fabric texture …"
```

### Verify production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `b7cfde1` |
| Bundle | `main-508WxgnN.js` có "Chip nhanh" · "Đang dùng:" · "Xem/sửa prompt gửi AI" · "Sửa chip" · "Người mẫu mặc trang phục" |
| Trang | `/` `/up` → **200** |

**Test:** 15 test (9 unit `PhotoStudioServiceTest`: ghép catalog giống hệt Cài đặt — ẩn/sửa/tự thêm · bỏ preset thiếu dữ
liệu · vai trò 3 ảnh theo số ảnh THỰC chọn · nối chip vào prompt · cảnh báo thiếu ảnh/hướng dẫn · đếm credit + demo · trần
chip + tỉ lệ lạ; 6 feature `PhotoStudioTest`: 401 khách · catalog đọc từ Cài đặt của tôi · tôn trọng hidden/edits/custom ·
plan KHÔNG gọi model + validate · module lock · **compose nhận 1 ảnh**). Full suite **812 pass**; 6 fail vẫn là lỗi SẴN CÓ.

### Còn lại (đề xuất)

- [ ] **Chưa có key model ảnh trên production** (nhóm `image`/`edit`/`video`/`swap` rỗng) ⇒ Studio vẫn chạy demo;
      giao diện đã cảnh báo thay vì trả ảnh mẫu im lặng.
- [ ] Nên thêm **chip "giữ nguyên"** mặc định (giữ tư thế/không đổi mặt) và **ô ghi chú chip** trong Cài đặt để
      người dùng tự soạn cụm chỉ dẫn dài mà không phải gõ lại mỗi lần.
- [ ] Chưa lưu **preset đang chọn theo bộ sưu tập** (mở lại buổi chụp cũ phải chọn lại chip).

---

## Phiên 2026-09-22 (Đợt 12 — Studio: chip nhanh còn 3 nhóm + thiết kế lại UX/UI cho NGƯỜI MỚI)

**Commit:** `25b6f01`. **Đã deploy production** (không migration), rebuild `main-C39ULzbm.js`.

### 1. Chip nhanh chỉ còn 3 nhóm: Bối cảnh · Góc máy · Ống kính

`PhotoStudioService::CHIP_CATEGORIES` lọc ở **cả** `chipGroups()` (chip hiển thị) và `chipIndex()` (tra ngược khi dựng
prompt) nên hai đường không thể lệch nhau. Lý do chỉ 3 nhóm: các nhóm preset còn lại (chất liệu · phom dáng ·
màu sắc · họa tiết…) mô tả **chính sản phẩm** — mà sản phẩm trong Studio là **ảnh 1 và phải GIỮ NGUYÊN**; để chung
vừa thừa vừa dễ khiến người mới tưởng đang thiết kế lại trang phục.

```
Đo trên production: 198 preset → CHIP: Bối cảnh 18 · Góc máy 8 · Ống kính 7 = 33 chip
ví dụ: Studio trắng / Phố cổ / Thiên nhiên xanh (có cả ghi chú của preset)
```

### 2. Thiết kế lại UX/UI cho người mới

| Trước | Sau |
|---|---|
| 3 ô ảnh ngang nhau, chung một khối | **4 bước có số thứ tự ①②③④** + một câu giải thích mỗi bước |
| Ô ảnh người mẫu ngang hàng hai ô phụ | Ô ảnh ① **TO NHẤT**, ghi rõ “Giữ nguyên ảnh này”, hướng dẫn lấy ảnh từ bước 「Tạo ảnh」 |
| 17 nhóm chip (bức tường chip) | **3 nhóm**, mỗi nhóm hiện **6 chip đầu + “+N nữa”**; chip đã chọn hiện thành thẻ nhỏ bấm để bỏ |
| Biến thể · tỉ lệ · prompt · ghi chú trộn lẫn trong luồng | Thu vào khối **“Nâng cao”** (mặc định đóng) |
| Nút Tạo ảnh khoá không rõ vì sao | Nút khoá kèm **lý do cụ thể** ngay dưới (“Chưa chọn ảnh ①…” / “Chưa có nội dung…”) |
| Cảnh báo lẫn với ghi chú | Trên nút chỉ còn **lỗi/cảnh báo**; ghi chú nằm trong khối Nâng cao |

Người mới chỉ cần **2 việc**: chọn ảnh ① và bấm **Tạo ảnh** — mọi thứ khác là tùy chọn và nằm đúng chỗ.

### Verify production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `25b6f01` |
| Bundle | có “Ảnh người mẫu” · “khung hình” · “Chọn nhanh” · “+N nữa” · “Bấm để chọn ảnh người mẫu” · “Sửa chip” |
| Trang | `/` `/up` → **200** |

**Test:** 16 test Studio (thêm `test_chip_groups_only_keep_the_three_studio_categories` khoá bất biến 3 nhóm; test
feature cũng kiểm mọi nhóm trả về đều thuộc 3 nhóm đó). Full suite **813 pass**; 6 fail vẫn là lỗi SẴN CÓ ở HEAD.

### Còn lại (đề xuất)

- [ ] **Chưa có key model ảnh trên production** ⇒ Studio vẫn ở chế độ demo (đã cảnh báo trong giao diện).
- [ ] Chưa ghi nhớ **chip đã chọn theo bộ sưu tập** (mở lại buổi chụp cũ phải chọn lại).
- [ ] Nên thêm 1–2 chip mặc định cho người mới ngay cả khi Cài đặt của tôi trống (hiện chỉ hiện link thêm preset).

---

## Phiên 2026-09-22 (Đợt 13 — Studio: dòng vai trò @imageN + đánh dấu 3 danh mục chip tại «Cài đặt của tôi»)

**Commit:** `cc4b74f`. **Đã deploy production**, rebuild `main-YP5vg-4V.js` + `my-settings-DIru5JkW.js`.

### 1. Dòng vai trò ô ảnh — viết theo đúng cách của card «Ghép trang phục»

Đầu card Studio nay có dòng giải thích ngay:

```
@image1 = người mẫu mặc trang phục (giữ nguyên) · @image2 = bối cảnh (tùy chọn) · @image3 = tham chiếu thêm (tùy chọn)
```

Cùng cách viết với card Ghép trang phục ⇒ người dùng đọc MỘT lần là hiểu ô nào làm gì, ở cả hai card.

### 2. Bảo đảm chip đọc được từ `/cai-dat/presets`

Vấn đề thật: form «Thêm preset» mặc định chọn danh mục **Chất liệu**, mà Studio chỉ lấy chip từ **Bối cảnh · Góc máy ·
Ống kính** ⇒ người dùng thêm preset xong không thấy nó trong Studio và không có dấu hiệu nào giải thích. Đã sửa:

- Trang Cài đặt gắn nhãn **`Studio`** cho đúng 3 danh mục đó — cả trên **chip lọc danh mục** lẫn trong **ô chọn danh mục**
  khi thêm preset, kèm dòng gợi ý *«Preset này sẽ hiện thành chip nhanh trong card Studio»*.
- Thêm **test đầu-cuối**: `PUT /api/user-catalogs/presets` (đúng đường trang Cài đặt dùng) → `POST /api/studio/shoot/catalog`
  trả preset đó thành chip → chọn chip thì đoạn chèn đi thẳng vào prompt. Đây là bảo đảm bằng máy, không chỉ bằng mô tả.

### Verify production

| Kiểm tra | Kết quả |
|---|---|
| HEAD | `cc4b74f` |
| Bundle Studio | có `@image1 = người mẫu mặc trang phục` · `@image3 = tham chiếu thêm` |
| Bundle Cài đặt | có nhãn `chip Studio` + dòng `chip nhanh` |
| Trang | `/` → **200** · `/cai-dat/presets` → **302** (yêu cầu đăng nhập, đúng như thiết kế) |

**Test:** 17 test Studio (thêm `test_a_preset_added_in_my_settings_becomes_a_studio_chip`). Full suite **814 pass**;
6 fail vẫn là lỗi SẴN CÓ ở HEAD.
