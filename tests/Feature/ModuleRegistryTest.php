<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use App\Services\StudioGuiConfig;
use App\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * MODULE REGISTRY — MỘT NGUỒN DUY NHẤT CHO MỌI TÍNH NĂNG (2026-09-19).
 *
 * Mục tiêu: khai một lần ở `App\Support\ModuleRegistry`, mọi bề mặt suy ra từ đó — thanh công cụ Studio,
 * công tắc bật/tắt toàn cục, quyền theo GÓI (`plans.modules`), màn Quản trị, trang giá — và THỰC THI ở
 * backend bằng middleware, không chỉ ẩn nút.
 *
 * Test này khoá những bất biến khiến việc MỞ RỘNG QUY MÔ an toàn:
 *   (a) bản khai hợp lệ (id duy nhất, snake_case, kind hợp lệ);
 *   (b) GUI ⇄ registry khớp CẢ HAI CHIỀU (không còn nhãn/icon khai hai nơi);
 *   (c) MỌI endpoint Studio đều thuộc một module, và mọi endpoint khai báo đều có route thật ⇒ thêm
 *       endpoint mới mà quên khai module là test đỏ;
 *   (d) khớp URI theo tiền tố DÀI NHẤT (xuất gói ≠ bộ sưu tập · shot-state ≠ thư viện);
 *   (e) gói là công tắc: không cấp module ⇒ 403 `module_locked`, cấp lại ⇒ qua;
 *   (f) công tắc toàn cục tắt MỘT module không ảnh hưởng module khác (yêu cầu cốt lõi của chủ dự án);
 *   (g) module phụ thuộc bị khoá theo module cha;
 *   (h) tài khoản nội bộ (admin) không bị công tắc gói chặn;
 *   (i) tài khoản CHƯA có gói dùng gói mặc định (không mất sạch tính năng);
 *   (j) gói lưu id module lạ thì id lạ bị bỏ qua.
 */
class ModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route hạ tầng: không thuộc tính năng nào để bật/tắt theo gói.
     *
     * 'appearance' (2026-09-23): tùy chọn HIỂN THỊ của chính người dùng (giao diện Sáng/Tối + cỡ chữ). Không phải
     * tính năng bán theo gói ⇒ cố ý KHÔNG khai vào ModuleRegistry, nếu không thì một gói
     * thiếu module sẽ làm người dùng không đổi được giao diện của chính mình.
     *
     * 'client-errors' (2026-09-23): đường CHẨN ĐOÁN — trình duyệt gửi lỗi phía client về để mã tra cứu
     * L-XXXX có mặt trong log. Không phải tính năng bán theo gói, và phải chạy được kể cả khi mọi module
     * đều bị tắt (nếu không thì lúc hệ thống hỏng nhất lại mất đúng dấu vết cần nhất).
     */
    protected const INFRA_PREFIXES = [
        'admin', 'boot', 'defaults', 'gui', 'plan/status', 'billing', 'settings', 'settings-vue',
        'image', 'image-thumb', 'process', 'appearance', 'client-errors',
        // 'brand-dna' (2026-09-23): HỒ SƠ của chính người dùng (định vị · khách hàng · phong cách…).
        // Cùng lý do với 'appearance': không phải tính năng bán theo gói, và công tắc gói không được
        // làm người dùng mất dữ liệu hay không sửa được hồ sơ của chính mình.
        'brand-dna',
        // 'brand-rules' (2026-09-26): QUY TẮC LÀM VIỆC của chính người dùng (trí nhớ thủ tục).
        // Cùng lý do với 'brand-dna' ở trên — đây là dữ liệu người dùng TỰ VIẾT, không phải tính năng
        // bán theo gói: công tắc gói không được làm họ mất quy tắc đã soạn hay không sửa được nó.
        'brand-rules',
        // 'brand-memory' (2026-09-26): TRÍ NHỚ ĐÃ HỌC của chính người dùng (đọc lại + quên một bài học).
        // Cùng lý do với 'brand-dna'/'brand-rules': dữ liệu người dùng tự tạo qua việc duyệt ảnh của họ —
        // công tắc gói không được làm họ không xem được hoặc không xoá được trí nhớ của chính mình.
        'brand-memory',
        // 'design-search' (2026-09-26): TÌM THIẾT KẾ CŨ trên chính kho tài liệu của người dùng. Cùng lý
        // do: đây là đường ĐỌC LẠI việc họ đã làm, không phải tính năng bán theo gói.
        'design-search',
        // 'cron' (2026-09-26): CRON NGOÀI — máy chủ tự gọi schedule:run. Không phải tính năng khách dùng,
        // không nằm dưới công tắc gói. Xác thực bằng token riêng, không phải bằng module.
        'cron',
        // 'webhooks' (2026-09-26): webhook từ nhà cung cấp (fal.ai) — POST từ bên thứ ba, không phải
        // tính năng khách dùng, không nằm dưới công tắc gói.
        'webhooks',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    /** Gán gói cho khách rồi trả về tài khoản đã làm mới. */
    private function withPlan(User $u, string $slug): User
    {
        app(PlanService::class)->assign($u, $this->plan($slug));

        return $u->fresh();
    }

    public function test_registry_is_well_formed(): void
    {
        $ids = ModuleRegistry::ids();
        $this->assertSame($ids, array_values(array_unique($ids)), 'Id module không được trùng.');
        $this->assertGreaterThanOrEqual(20, count($ids), 'Bản khai phải phủ hết tính năng đang có.');

        foreach (ModuleRegistry::all() as $m) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', (string) $m['id'], 'Id phải là snake_case: '.$m['id']);
            $this->assertNotSame('', trim((string) $m['name']), 'Module thiếu tên: '.$m['id']);
            $this->assertNotSame('', trim((string) $m['group']), 'Module thiếu nhóm: '.$m['id']);
            $this->assertContains($m['kind'], ['panel', 'action', 'menu', 'feature'], 'kind lạ ở '.$m['id']);
            foreach ($m['depends_on'] ?? [] as $parent) {
                $this->assertTrue(ModuleRegistry::has((string) $parent), 'Module cha không tồn tại: '.$m['id'].' → '.$parent);
            }
            foreach ($m['plans'] ?? [] as $slug) {
                $this->assertTrue($slug === '*' || Plan::where('slug', $slug)->exists(), 'Đề xuất trỏ gói không có: '.$m['id'].' → '.$slug);
            }
        }
    }

    public function test_gui_defaults_are_derived_from_the_registry(): void
    {
        $defaults = StudioGuiConfig::defaults();
        $guiIds = ModuleRegistry::guiIds();

        $this->assertSame($guiIds, array_column($defaults, 'id'), 'Mục thanh công cụ phải sinh từ bản khai module, đúng thứ tự.');
        $this->assertCount(9, StudioGuiConfig::panelIds(), 'Vẫn đủ 9 nhóm card (Ghép ảnh và Ghép trang phục là 2 card riêng).');
        $this->assertSame('settings', end($guiIds) ?: null, 'Menu Cài đặt vẫn là mục ghim đáy.');

        foreach ($defaults as $row) {
            $m = ModuleRegistry::get($row['id']);
            $this->assertSame($m['name'], $row['label'], 'Nhãn phải lấy từ bản khai: '.$row['id']);
            $this->assertSame($m['icon'], $row['icon'], 'Icon phải lấy từ bản khai: '.$row['id']);
            $this->assertSame($m['kind'], $row['kind'], 'Loại mục phải khớp bản khai: '.$row['id']);
        }

        // Vue: bản đồ id → card phải khớp đúng các module 'panel', và bản dự phòng (khi chưa tải được
        // cấu hình) phải khớp toàn bộ danh sách — nếu không, thêm module mới là thanh công cụ lệch.
        $app = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
        foreach (StudioGuiConfig::panelIds() as $id) {
            $this->assertStringContainsString($id.': [', $app, 'Thiếu card cho module panel: '.$id);
        }
        foreach ($guiIds as $id) {
            $this->assertStringContainsString("id: '".$id."'", $app, 'Bản dự phòng thanh công cụ thiếu: '.$id);
        }
    }

    public function test_every_studio_api_route_is_claimed_by_a_module(): void
    {
        $unclaimed = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $rel = substr($uri, 4);
            foreach (self::INFRA_PREFIXES as $skip) {
                if ($rel === $skip || str_starts_with($rel, $skip.'/') || str_starts_with($rel, $skip.'{')) {
                    continue 2;
                }
            }

            if (ModuleRegistry::modulesForUri($rel) === []) {
                $unclaimed[] = $uri;
            }
        }

        $this->assertSame([], array_values(array_unique($unclaimed)),
            'Endpoint Studio chưa thuộc module nào ⇒ công tắc gói không chặn được nó. Hãy khai vào ModuleRegistry.');
    }

    public function test_every_declared_endpoint_exists_as_a_route(): void
    {
        $uris = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $uris[] = substr($route->uri(), 4);
            }
        }

        foreach (ModuleRegistry::all() as $m) {
            foreach ($m['endpoints'] ?? [] as $pattern) {
                $exists = false;
                foreach ($uris as $uri) {
                    if (ModuleRegistry::modulesForUri($uri) !== [] && in_array($m['id'], ModuleRegistry::modulesForUri($uri), true)
                        && (str_starts_with($uri, trim((string) $pattern, '/')) || $uri === trim((string) $pattern, '/'))) {
                        $exists = true;
                        break;
                    }
                }
                $this->assertTrue($exists, 'Module '.$m['id'].' khai endpoint không có route thật: '.$pattern);
            }
        }
    }

    public function test_uri_matching_picks_the_most_specific_module(): void
    {
        $this->assertSame(['project_export'], ModuleRegistry::modulesForUri('projects/12/export'));
        $this->assertSame(['project_stats'], ModuleRegistry::modulesForUri('projects/12/stats'));
        $this->assertSame(['project_share'], ModuleRegistry::modulesForUri('projects/12/share'));
        $this->assertSame(['shot_review'], ModuleRegistry::modulesForUri('projects/12/shots/review'));
        $this->assertSame(['shot_review'], ModuleRegistry::modulesForUri('generations/9/shot-state'));
        $this->assertSame(['library'], ModuleRegistry::modulesForUri('generations/9'));
        $this->assertContains('collections', ModuleRegistry::modulesForUri('projects'));
        $this->assertContains('collections', ModuleRegistry::modulesForUri('projects/12'));
        $this->assertSame(['prompt'], ModuleRegistry::modulesForUri('generate'));
        // refgen phục vụ cả "Tạo biến thể" và "Mặc thử đồ" ⇒ cả hai module đều là chủ sở hữu.
        $this->assertEqualsCanonicalizing(['variation', 'tryon'], ModuleRegistry::modulesForUri('refgen'));
        $this->assertSame([], ModuleRegistry::modulesForUri('khong/co/that'));
    }

    public function test_plan_modules_are_the_feature_switch(): void
    {
        $u = $this->withPlan($this->customer(), 'pro');
        $plan = $this->plan('pro');

        // Gói đang cấp 'job_templates' ⇒ khách dùng được.
        $this->assertTrue($plan->grantsModule('job_templates'));
        $this->actingAs($u)->getJson('/api/job-templates')->assertOk();

        // Rút module khỏi gói ⇒ 403 có cấu trúc (nói rõ module + gói nào đang có nó).
        $plan->forceFill(['modules' => array_values(array_diff($plan->modules(), ['job_templates']))])->save();

        $res = $this->actingAs($u->fresh())->getJson('/api/job-templates')->assertStatus(403);
        $res->assertJsonPath('code', 'module_locked')
            ->assertJsonPath('module', 'job_templates')
            ->assertJsonPath('reason', 'plan');
        $this->assertNotEmpty($res->json('plans_with_module'), 'Phải nói được gói nào đang cấp module này để gợi ý nâng cấp.');

        // Cấp lại ⇒ qua cổng.
        $plan->forceFill(['modules' => array_merge($plan->modules(), ['job_templates'])])->save();
        $this->actingAs($u->fresh())->getJson('/api/job-templates')->assertOk();
    }

    public function test_global_switch_turns_off_one_module_without_touching_others(): void
    {
        $u = $this->withPlan($this->customer(), 'studio');

        // Tắt DUY NHẤT module 'job_templates' toàn cục.
        set_setting(ModuleRegistry::SETTING_DISABLED, json_encode(['job_templates']));

        $res = $this->actingAs($u->fresh())->getJson('/api/job-templates')->assertStatus(403);
        $res->assertJsonPath('reason', 'disabled');
        $this->assertStringContainsString('tạm tắt', (string) $res->json('message'));

        // Các module khác KHÔNG bị ảnh hưởng — đây là yêu cầu cốt lõi khi tách tính năng thành module.
        $this->actingAs($u->fresh())->getJson('/api/latest')->assertOk();
        $this->actingAs($u->fresh())->getJson('/api/library/data')->assertOk();
        $this->actingAs($u->fresh())->getJson('/api/projects')->assertOk();
        $this->assertTrue(module_allowed($u->fresh(), 'library'));
        $this->assertFalse(module_allowed($u->fresh(), 'job_templates'));

        // Bật lại ⇒ dùng được ngay, không cần deploy.
        set_setting(ModuleRegistry::SETTING_DISABLED, json_encode([]));
        $this->actingAs($u->fresh())->getJson('/api/job-templates')->assertOk();
    }

    public function test_dependencies_lock_the_child_module(): void
    {
        $u = $this->withPlan($this->customer(), 'pro');
        $plan = $this->plan('pro');

        // 'batch' cần 'prompt'. Rút 'prompt' ⇒ 'batch' cũng khoá dù gói còn liệt kê nó.
        $plan->forceFill(['modules' => array_values(array_diff($plan->modules(), ['prompt']))])->save();
        $fresh = $u->fresh();

        $this->assertFalse(module_allowed($fresh, 'prompt'));
        $this->assertTrue($this->plan('pro')->grantsModule('batch'), 'Gói vẫn liệt kê batch…');
        $this->assertFalse(module_allowed($fresh, 'batch'), '…nhưng thiếu module cha thì phải khoá.');
        $this->assertSame('dependency', collect(modules_status($fresh))->firstWhere('id', 'batch')['reason']);
    }

    public function test_admins_are_not_blocked_by_plan_toggles(): void
    {
        $admin = User::where('email', 'admin@fabrikai.shop')->firstOrFail();
        $plan = $this->plan('free');
        $plan->forceFill(['modules' => ['library']])->save();

        $this->assertTrue(module_allowed($admin, 'job_templates'), 'Tài khoản nội bộ phải vào được mọi màn để hỗ trợ khách.');
        $this->actingAs($admin)->getJson('/api/job-templates')->assertOk();
    }

    public function test_user_without_a_plan_falls_back_to_the_default_plan(): void
    {
        $u = $this->customer();
        $u->forceFill(['plan_id' => null, 'plan_expires_at' => null])->save();

        $default = Plan::defaultPlan();
        $this->assertNotNull($default, 'Phải có gói mặc định (thường là Miễn phí).');
        // activePlan() vẫn null (tài khoản chưa gán gói) — nhưng QUYỀN MODULE phải rơi về gói mặc định.
        $this->assertNull($u->fresh()->activePlan());
        $this->assertSame($default->modules(), user_modules($u->fresh()), 'Không có gói ⇒ quyền module theo gói mặc định.');

        // Và vì mặc định là ĐỦ module, khách cũ không bị mất tính năng khi bật công tắc.
        $this->actingAs($u->fresh())->getJson('/api/job-templates')->assertOk();
        $this->actingAs($u->fresh())->getJson('/api/projects')->assertOk();
    }

    public function test_unknown_module_ids_stored_in_a_plan_are_ignored(): void
    {
        $plan = $this->plan('pro');
        $plan->forceFill(['modules' => ['khong_ton_tai', 'upscale']])->save();

        $this->assertSame(['upscale'], $plan->fresh()->modules(), 'Id lạ không được biến thành quyền mơ hồ.');
        $this->assertTrue($plan->fresh()->grantsModule('upscale'));
        $this->assertFalse($plan->fresh()->grantsModule('khong_ton_tai'));
    }

    public function test_suggested_matrix_is_sane_and_never_empty(): void
    {
        foreach (['free', 'starter', 'pro', 'studio', 'factory_season'] as $slug) {
            $suggested = ModuleRegistry::suggestedForPlan($slug);
            $this->assertNotEmpty($suggested, 'Gói '.$slug.' phải có đề xuất module.');
            foreach ($suggested as $id) {
                $this->assertTrue(ModuleRegistry::has($id), 'Đề xuất chứa module lạ: '.$id);
            }
        }

        // Module nền tảng ('*') có mặt ở MỌI gói ⇒ thêm gói mới không phải sửa bản khai.
        $free = ModuleRegistry::suggestedForPlan('free');
        $this->assertContains('collections', $free);
        $this->assertContains('library', $free);
        // Tính năng bán thêm thì KHÔNG nằm trong gói miễn phí.
        $this->assertNotContains('team_seats', $free);
        $this->assertNotContains('project_export', $free);
        $this->assertContains('team_seats', ModuleRegistry::suggestedForPlan('studio'));
    }

    public function test_ui_surfaces_expose_modules_and_lock_reasons(): void
    {
        $u = $this->withPlan($this->customer(), 'free');
        $plan = $this->plan('free');
        $plan->forceFill(['modules' => array_values(array_diff($plan->modules(), ['stylist']))])->save();

        // /api/gui: Studio vẽ ổ khoá + gợi ý nâng cấp từ đúng danh sách này.
        $gui = $this->actingAs($u->fresh())->getJson('/api/gui')->assertOk();
        $this->assertCount(count(ModuleRegistry::all()), $gui->json('modules'));
        $this->assertNotContains('stylist', $gui->json('modules_allowed'));
        $this->assertCount(count(ModuleRegistry::all()), $gui->json('modules_catalog'));
        $this->assertNotNull(collect($gui->json('modules'))->firstWhere('id', 'stylist')['reason'] ?? null);

        // /api/boot: SPA biết quyền ngay từ lần nạp đầu.
        $this->actingAs($u->fresh())->getJson('/api/boot')
            ->assertOk()
            ->assertJsonPath('user.modules', user_modules($u->fresh()));

        // /api/plan/status: popup gói nói đúng module nào có, module nào khoá và vì sao.
        $this->actingAs($u->fresh())->getJson('/api/plan/status')
            ->assertOk()
            ->assertJsonPath('modules.allowed', user_modules($u->fresh()))
            ->assertJsonCount(count(ModuleRegistry::all()), 'modules.status')
            ->assertJsonCount(count(ModuleRegistry::all()), 'modules.catalog');
    }

    public function test_registry_growth_reaches_every_surface(): void
    {
        // Bất biến "mở rộng quy mô": mọi module trong bản khai phải xuất hiện ở MỌI bề mặt suy ra từ nó —
        // nếu một bề mặt bị viết tay (không suy ra), thêm module mới sẽ lộ ngay ở đây.
        $catalog = ModuleRegistry::catalog();
        $this->assertCount(count(ModuleRegistry::all()), $catalog);

        foreach (ModuleRegistry::all() as $m) {
            $row = collect($catalog)->firstWhere('id', $m['id']);
            $this->assertNotNull($row, 'Danh mục module thiếu: '.$m['id']);
            $this->assertSame($m['name'], $row['name']);

            if (! empty($m['gui'])) {
                $this->assertContains($m['id'], ModuleRegistry::guiIds());
                $this->assertContains($m['id'], StudioGuiConfig::defaultIds());
            }

            $suggested = collect(ModuleRegistry::suggestedForPlan('studio'));
            $this->assertTrue(
                $suggested->contains($m['id']) || in_array('*', $m['plans'] ?? [], true) || ! empty($m['plans']),
                'Module '.$m['id'].' không có đề xuất gói nào ⇒ Quản trị không biết gợi ý cho ai.'
            );
        }
    }

    /**
     * ĐỀ XUẤT CỦA BẢN KHAI KHÔNG ĐƯỢC CẤP CON MÀ THIẾU CHA (2026-09-26).
     *
     * Vì sao: `module_allowed()` đi NGƯỢC LÊN theo `depends_on` — thiếu một module cha là module con KHÔNG
     * dùng được, dù gói có ghi tên nó. Đo trên production: gói trả tiền có 'stylist' nhưng thiếu
     * 'trend_radar'/'collection_bot' ⇒ khách bị chặn Agent Studio và chat, còn quản trị (được miễn công tắc
     * gói) vẫn vào được nên không ai thấy. Bất biến này chặn đúng loại lệch đó NGAY Ở BẢN KHAI, trước khi nó
     * kịp thành dữ liệu của gói.
     *
     * Chỉ rà các gói THẬT SỰ được bản khai nhắc tên; '*' không phải slug nên không rà ở đây.
     */
    public function test_de_xuat_cua_ban_khai_khong_bao_gio_cap_con_thieu_cha(): void
    {
        $slugs = [];
        foreach (ModuleRegistry::all() as $m) {
            foreach ((array) ($m['plans'] ?? []) as $slug) {
                if ((string) $slug !== '*') {
                    $slugs[] = (string) $slug;
                }
            }
        }
        $this->assertNotEmpty($slugs, 'Bản khai phải có ít nhất một đề xuất theo slug gói để bất biến này có nghĩa.');

        foreach (array_values(array_unique($slugs)) as $slug) {
            $suggested = ModuleRegistry::suggestedForPlan($slug);

            foreach ($suggested as $id) {
                foreach ((array) (ModuleRegistry::get($id)['depends_on'] ?? []) as $parent) {
                    $this->assertContains(
                        (string) $parent,
                        $suggested,
                        'Đề xuất cho gói '.$slug.' có module '.$id.' nhưng thiếu module cha '.$parent
                            .' ⇒ gói đó cấp một tính năng KHÔNG DÙNG ĐƯỢC.'
                    );
                }
            }
        }
    }
}
