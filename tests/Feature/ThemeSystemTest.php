<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ModuleRegistry;
use App\Support\ThemePalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * HỆ THỐNG THEME — Sáng / Tối, một bảng token, tương phản đạt WCAG AA (2026-09-23).
 *
 * Vì sao có file test này: khiếu nại gốc là "chữ có độ tương phản hơi thấp, khó đọc". Đó là loại
 * lỗi KHÔNG tự lộ ra: không exception, không log, chỉ có người ngồi nhìn thấy mỏi mắt. Trước đợt
 * này toàn bộ bậc chữ phụ được tạo bằng ĐỘ MỜ trên một sắc duy nhất (đo được 741 chỗ
 * text-cream-300/25…/85, trong đó /40 chỉ đạt ~2.9:1 — dưới cả ngưỡng AA 4.5:1).
 *
 * Bốn nhóm bất biến được khoá ở đây:
 *   (a) CẤU TRÚC: hai theme khai CÙNG một bộ token, và theme sáng thật sự khác theme tối;
 *   (b) TƯƠNG PHẢN: mọi bậc nội dung + màu nhấn/màu trạng thái đạt ≥ 4.5:1 trên mọi bề mặt của
 *       CẢ HAI theme (tính bằng công thức độ chói tương đối của WCAG, không phải cảm nhận);
 *   (c) KỶ LUẬT VIẾT: không còn chữ dùng opacity, không còn sắc độ trạng thái viết thẳng
 *       (text-red-300 …) — hai thứ vừa bị dọn;
 *   (d) ĐƯỜNG ĐI: partial theme phải có mặt ở MỌI blade, tùy chọn lưu theo tài khoản qua
 *       PUT /api/appearance (whitelist cứng), và endpoint này KHÔNG bị công tắc gói chặn.
 */
class ThemeSystemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * [2026-09-23] Việc ĐỌC token và TÍNH tương phản nay nằm ở App\Support\ThemePalette — CÙNG lớp
     * mà trang xem token cho người thiết kế dùng (/he-thong-thiet-ke). Trước đây test tự parse app.css
     * theo cách riêng, nên nếu làm thêm một trang đọc token thì đã có HAI chỗ tính và chúng lệch nhau
     * lúc nào không biết.
     */
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /** @return array<string,string> token của theme TỐI, ĐÃ giải bí danh var() */
    private function darkTokens(): array
    {
        ThemePalette::flush();
        $tokens = ThemePalette::tokens('dark');
        $this->assertNotEmpty($tokens, 'Không đọc được khối @theme trong app.css.');

        return $tokens;
    }

    /** @return array<string,string> token của theme SÁNG (đã trộn ghi đè + giải bí danh) */
    private function lightTokens(): array
    {
        ThemePalette::flush();
        $tokens = ThemePalette::tokens('light');
        $this->assertNotEmpty($tokens, "Thiếu khối [data-theme='light'] trong app.css.");

        return $tokens;
    }

    private function luminance(string $hex): float
    {
        return ThemePalette::luminance($hex);
    }

    private function contrast(string $a, string $b): float
    {
        return ThemePalette::contrast($a, $b);
    }

    public function test_both_themes_declare_the_same_token_ramps(): void
    {
        $dark = $this->darkTokens();
        $light = $this->lightTokens();

        foreach (['ink-950', 'ink-900', 'ink-800', 'ink-700', 'ink-600', 'ink-500',
            'cream-50', 'cream-100', 'cream-200', 'cream-300', 'cream-400'] as $key) {
            $this->assertArrayHasKey($key, $dark, 'Theme tối thiếu token '.$key.'.');
            $this->assertArrayHasKey($key, $light, 'Theme sáng thiếu token '.$key.' — hai theme phải cùng bộ token, '
                .'nếu không thì class dùng chung sẽ ra màu của theme kia.');
        }

        // Bậc nội dung phải ĐẢO chiều sáng/tối giữa hai theme — nếu không, theme sáng chỉ là
        // bản sao của theme tối (lỗi copy-paste dễ xảy ra nhất khi thêm theme thứ hai).
        $this->assertGreaterThan(
            $this->luminance($dark['ink-950']),
            $this->luminance($light['ink-950']),
            'Nền trang của theme sáng phải SÁNG HƠN nền trang của theme tối.'
        );
        $this->assertLessThan(
            $this->luminance($dark['cream-100']),
            $this->luminance($light['cream-100']),
            'Chữ chính của theme sáng phải TỐI HƠN chữ chính của theme tối (hai dải ink/cream đảo vai).'
        );

        // Màu trạng thái + khối đảo màu phải có ở cả hai theme (mỗi theme chọn sắc độ riêng).
        foreach (['danger', 'warn', 'ok', 'info', 'invert', 'invert-content'] as $key) {
            $this->assertArrayHasKey($key, $light, 'Theme sáng thiếu token trạng thái: '.$key);
        }
    }

    public function test_every_content_level_meets_wcag_aa_on_every_surface_in_both_themes(): void
    {
        $surfaces = ['ink-950', 'ink-900', 'ink-800', 'ink-700'];

        foreach (['theme tối' => $this->darkTokens(), 'theme sáng' => $this->lightTokens()] as $name => $tokens) {
            foreach ($surfaces as $surface) {
                foreach (['cream-100', 'cream-200', 'cream-300', 'cream-400'] as $level) {
                    $ratio = $this->contrast($tokens[$level], $tokens[$surface]);
                    $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf(
                        '%s: chữ %s trên nền %s chỉ đạt %.2f:1 (WCAG AA cần ≥ 4.5:1). Đây đúng là lỗi '
                        .'"chữ mờ khó đọc" — hãy chỉnh bậc token, đừng thêm opacity.',
                        $name, $level, $surface, $ratio
                    ));
                }
            }
        }
    }

    public function test_accent_and_status_colours_are_readable_on_all_surfaces(): void
    {
        foreach (['theme tối' => $this->darkTokens(), 'theme sáng' => $this->lightTokens()] as $name => $tokens) {
            foreach (['ink-950', 'ink-900', 'ink-800', 'ink-700'] as $surface) {
                foreach (['brand-200', 'brand-300', 'danger', 'warn', 'ok', 'info'] as $token) {
                    $this->assertArrayHasKey($token, $tokens, $name.' thiếu token '.$token);
                    $ratio = $this->contrast($tokens[$token], $tokens[$surface]);
                    $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf(
                        '%s: màu %s trên nền %s chỉ đạt %.2f:1 — màu trạng thái/nhấn cũng là CHỮ, '
                        .'phải đọc được như mọi bậc nội dung khác.', $name, $token, $surface, $ratio
                    ));
                }
            }
        }

        // Chữ trắng trên nút thương hiệu (bg-brand-600) — nút chính của toàn app.
        foreach (['theme tối' => $this->darkTokens(), 'theme sáng' => $this->lightTokens()] as $name => $tokens) {
            $this->assertGreaterThanOrEqual(4.5, $this->contrast('#ffffff', $tokens['brand-600']),
                $name.': chữ trắng trên nền brand-600 không đạt WCAG AA.');
        }
    }

    public function test_text_no_longer_uses_opacity_for_hierarchy(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            preg_match_all('/text-(?:cream|ink)-[0-9]+\/[0-9]+/', (string) file_get_contents($file), $m);
            foreach ($m[0] as $hit) {
                $offenders[] = str_replace(base_path().'/', '', $file).': '.$hit;
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            "Chữ dùng ĐỘ MỜ để tạo bậc là thứ vừa bị gỡ (2026-09-23): độ mờ cho ra tương phản không "
            .'kiểm soát được (text-cream-300/40 ≈ 2.9:1, dưới WCAG AA). Dùng 4 bậc ĐẶC: '
            .'cream-100 (chính) · cream-200 · cream-300 · cream-400 (ghi chú). Xem docs/DESIGN_SYSTEM.md §1.1.');
    }

    public function test_status_text_uses_semantic_tokens_instead_of_raw_shades(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            preg_match_all('/text-(?:red|amber|emerald|sky)-[0-9]+/', (string) file_get_contents($file), $m);
            foreach ($m[0] as $hit) {
                $offenders[] = str_replace(base_path().'/', '', $file).': '.$hit;
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            'Màu trạng thái phải dùng token ngữ nghĩa (text-danger · text-warn · text-ok · text-info): '
            .'các sắc độ text-red-300/text-amber-200 chỉ đủ tương phản trên nền TỐI, nên theme sáng sẽ '
            .'cho ra chữ vàng nhạt trên nền trắng. Nền tint vẫn dùng được bg-danger/10 · border-warn/40.');

        // Và các token đó phải THẬT SỰ được dùng (đề phòng "dọn xong rồi không dùng gì").
        $all = '';
        foreach ($this->sourceFiles() as $file) {
            $all .= (string) file_get_contents($file);
        }
        foreach (['text-danger', 'text-warn', 'text-ok', 'text-info'] as $token) {
            $this->assertStringContainsString($token, $all, 'Chưa nơi nào dùng '.$token.' — token khai mà không dùng là token sẽ trôi.');
        }
    }

    public function test_every_shell_declares_the_theme_before_first_paint(): void
    {
        $blades = [
            'resources/views/studio/index.blade.php',
            'resources/views/studio/settings.blade.php',
            'resources/views/studio/my-settings.blade.php',
            'resources/views/studio/admin.blade.php',
            'resources/views/studio/collections.blade.php',
            'resources/views/layouts/app.blade.php',
        ];

        foreach ($blades as $rel) {
            $src = (string) file_get_contents(base_path($rel));
            $this->assertStringContainsString('data-theme="{{ theme_resolved() }}"', $src,
                $rel.' chưa render sẵn data-theme ⇒ người dùng theme sáng sẽ thấy nháy nền tối khi tải trang.');
            $this->assertStringContainsString("@include('partials.theme')", $src,
                $rel.' thiếu script theme ⇒ chế độ "theo hệ điều hành" và đổi theme không chạy được.');
        }

        $partial = (string) file_get_contents(base_path('resources/views/partials/theme.blade.php'));
        $this->assertStringContainsString('localStorage', $partial, 'Thiếu cache localStorage ⇒ mở trang phải chờ mạng mới biết theme.');
        $this->assertStringContainsString('prefers-color-scheme', $partial, 'Thiếu nhánh "theo hệ điều hành".');
        $this->assertStringContainsString('/api/appearance', $partial, 'Thiếu đường lưu tùy chọn lên tài khoản.');
    }

    public function test_canvas_backgrounds_do_not_follow_the_theme(): void
    {
        $css = $this->css();

        // Nền canvas là MÔI TRƯỜNG ẢNH: người dùng chọn "Trắng"/"Kem" để nhìn ảnh, nên chúng
        // phải là màu cố định. Nay chúng đọc token riêng --color-canvas-* (khai MỘT lần trong
        // @theme, KHÔNG định nghĩa lại ở theme sáng) — nếu trỏ vào token bề mặt như trước thì ở
        // theme sáng "nền kem" sẽ thành nền ĐEN, mất đúng thứ người dùng vừa chọn.
        foreach (['dark', 'white', 'cream'] as $id) {
            $this->assertStringContainsString('.canvas-bg-'.$id.' { background: var(--color-canvas-'.$id.'); }', $css,
                'Nền canvas "'.$id.'" phải đọc token CỐ ĐỊNH --color-canvas-'.$id.'.');
        }
        foreach (['--color-canvas-dark:', '--color-canvas-white:', '--color-canvas-cream:'] as $token) {
            $this->assertStringContainsString($token, $css, 'Thiếu token cố định '.$token);
        }

        // Vế quan trọng nhất: theme sáng KHÔNG được định nghĩa lại chúng.
        preg_match("/\[data-theme='light'\]\s*\{(.*?)\n\}/s", $css, $m);
        $light = $m[1] ?? '';
        $this->assertNotEmpty($light, "Không đọc được khối [data-theme='light'].");
        $this->assertStringNotContainsString('--color-canvas-', $light,
            'Token nền canvas bị định nghĩa lại ở theme sáng ⇒ nền canvas đổi theo theme.');
    }

    public function test_image_overlays_use_fixed_scrim_tokens(): void
    {
        $css = $this->css();

        // Lớp phủ TRÊN ẢNH: scrim + chữ đều cố định (xem docs/DESIGN_SYSTEM.md §1.1 quy tắc 5).
        $this->assertStringContainsString('--color-scrim:', $css, 'Thiếu token cố định --color-scrim.');
        $this->assertStringContainsString('--color-scrim-content:', $css, 'Thiếu token cố định --color-scrim-content.');

        preg_match("/\[data-theme='light'\]\s*\{(.*?)\n\}/s", $css, $m);
        $this->assertStringNotContainsString('--color-scrim', $m[1] ?? '',
            'Token scrim bị định nghĩa lại ở theme sáng ⇒ chữ trên ảnh sẽ thành chữ ĐEN trên nền tối.');

        // Không nơi nào được đặt chữ THEO THEME lên một scrim màu đen cố định.
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            preg_match_all('/class="([^"]*)"/', (string) file_get_contents($file), $classes);
            foreach ($classes[1] as $cls) {
                if (str_contains($cls, 'bg-black/') && preg_match('/text-cream-/', $cls)) {
                    $offenders[] = str_replace(base_path().'/', '', $file);
                }
            }
        }
        $this->assertSame([], array_values(array_unique($offenders)),
            'Có chip/nhãn đặt TRÊN ẢNH dùng scrim đen cố định nhưng lại lấy chữ theo theme '
            .'(text-cream-*): ở theme sáng chữ đó là màu tối trên nền tối — đo được 2,9:1. '
            .'Dùng bg-scrim/NN + text-scrim-content.');
    }

    // ── Đường đi: tùy chọn lưu theo tài khoản ────────────────────────────────────────────
    public function test_theme_preference_is_stored_per_account_and_validated(): void
    {
        $this->seed();
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        // Chưa từng chọn ⇒ mặc định của sản phẩm là TỐI (không đổi giao diện của người đang dùng).
        $this->assertNull($user->theme);
        $this->actingAs($user);
        $this->assertSame('dark', theme_pref());
        $this->assertSame('dark', theme_resolved());

        // Lưu được và ĐỌC LẠI từ DB (không phải chỉ trong phiên).
        $this->actingAs($user)->putJson('/api/appearance', ['theme' => 'light'])
            ->assertOk()
            ->assertJson(['theme' => 'light']);
        $this->assertSame('light', $user->fresh()->theme);
        $this->assertSame('light', theme_resolved());

        // 'system' render 'dark' ở server (máy chủ không biết ý hệ điều hành) và script sửa lại
        // trước khi vẽ — đây là hành vi CỐ Ý, khoá lại để người sau không "sửa" thành light.
        $this->actingAs($user)->putJson('/api/appearance', ['theme' => 'system'])->assertOk();
        $this->assertSame('system', theme_pref());
        $this->assertSame('dark', theme_resolved());

        // Giá trị lạ bị 422: cột này được render thẳng vào thuộc tính data-theme của thẻ <html>
        // ở mọi blade ⇒ nhận chuỗi tự do ở đây là một lỗ XSS tiềm năng.
        foreach (['" onload="alert(1)', 'LIGHT', 'blue', '', 'light;dark', 'systemx'] as $bad) {
            $this->actingAs($user)->putJson('/api/appearance', ['theme' => $bad])->assertStatus(422);
        }
        $this->assertSame('system', $user->fresh()->theme, 'Giá trị sai không được ghi vào DB.');

        // Khoảng trắng thừa: middleware TrimStrings của Laravel cắt trước khi kiểm tra, nên
        // 'dark ' được nhận NHƯ 'dark' — và thứ ghi xuống DB phải là giá trị CHUẨN (không có
        // khoảng trắng), vì giá trị đó đi thẳng vào thuộc tính data-theme của thẻ <html>.
        $this->actingAs($user)->putJson('/api/appearance', ['theme' => 'dark '])->assertOk();
        $this->assertSame('dark', $user->fresh()->theme);

        // Khách chưa đăng nhập: không đổi được theme của ai cả.
        auth()->logout();
        $this->putJson('/api/appearance', ['theme' => 'light'])->assertUnauthorized();
    }

    public function test_font_scale_is_stored_per_account_and_validated(): void
    {
        $this->seed();
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $this->actingAs($user);

        // Chưa từng chọn ⇒ 100%. NULL (chưa chọn) KHÁC 100 (đã chọn "Vừa").
        $this->assertNull($user->font_scale);
        $this->assertSame(100, font_scale());
        $this->assertSame('1', font_scale_ratio());

        $this->actingAs($user)->putJson('/api/appearance', ['font_scale' => 115])
            ->assertOk()
            ->assertJson(['font_scale' => 115]);
        $this->assertSame(115, $user->fresh()->font_scale);
        $this->assertSame('1.15', font_scale_ratio());

        // Ngoài whitelist ⇒ 422 (cỡ chữ là biến CSS nhân vào MỌI bậc chữ: nhận số tự do là nhận
        // một giá trị có thể làm vỡ bố cục toàn app).
        foreach ([0, 12, 99, 101, 200, 99999] as $bad) {
            $this->actingAs($user)->putJson('/api/appearance', ['font_scale' => $bad])->assertStatus(422);
        }
        $this->assertSame(115, $user->fresh()->font_scale, 'Giá trị sai không được ghi vào DB.');

        // Không gửi trường nào ⇒ 422, KHÔNG trả 200 như đã lưu.
        $this->actingAs($user)->putJson('/api/appearance', [])->assertStatus(422);

        // CÙNG một endpoint cho cả hai tùy chọn hiển thị — và chúng không ghi đè nhau.
        $this->actingAs($user)->putJson('/api/appearance', ['theme' => 'light'])->assertOk();
        $this->assertSame('light', $user->fresh()->theme);
        $this->assertSame(115, $user->fresh()->font_scale, 'Đổi giao diện KHÔNG được ghi đè cỡ chữ.');
    }

    public function test_font_scale_is_a_token_scale_not_a_hard_coded_number(): void
    {
        $css = $this->css();

        // Mọi bậc chữ phải là token theo VAI và nhân với --font-scale ⇒ cùng một công tắc chỉnh cả app.
        foreach (['micro' => 9, 'tiny' => 10, 'label' => 11, 'body' => 12.5, 'body-lg' => 13, 'title' => 14] as $role => $px) {
            $this->assertStringContainsString('--text-'.$role.': calc('.$px.'px * var(--font-scale, 1));', $css,
                'Thiếu token cỡ chữ theo vai: --text-'.$role.' (đã nhích lên '.$px.'px).');
        }
        $this->assertStringContainsString('--font-scale: 1;', $css, 'Thiếu giá trị mặc định --font-scale.');

        // Bậc rem mặc định của Tailwind cũng phải theo công tắc (nếu không, nửa app to nửa app đứng yên).
        foreach (['xs', 'sm', 'base', 'lg'] as $step) {
            $this->assertMatchesRegularExpression('/--text-'.$step.': calc\([0-9.]+rem \* var\(--font-scale, 1\)\)/', $css,
                'Bậc text-'.$step.' chưa theo công tắc cỡ chữ.');
        }

        // Mọi shell render sẵn hệ số ⇒ không nháy cỡ chữ khi tải trang.
        foreach ([
            'resources/views/studio/index.blade.php',
            'resources/views/studio/my-settings.blade.php',
            'resources/views/layouts/app.blade.php',
        ] as $rel) {
            $this->assertStringContainsString('--font-scale: {{ font_scale_ratio() }}', (string) file_get_contents(base_path($rel)),
                $rel.' chưa render --font-scale ⇒ cỡ chữ sẽ nháy khi tải.');
        }
    }

    public function test_theme_endpoint_is_not_gated_by_plans_or_modules(): void
    {
        $route = RouteFacade::getRoutes()->getByName('api.appearance.update');
        $this->assertNotNull($route, 'Thiếu route PUT /api/appearance.');

        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertNotContains('can-studio', $middleware,
            'Đổi giao diện là tùy chọn hiển thị của MỌI tài khoản — không được đòi quyền vào Studio.');

        // Không thuộc module nào ⇒ công tắc gói KHÔNG tắt được giao diện của người dùng.
        $this->assertSame([], ModuleRegistry::modulesForUri('theme'),
            'Endpoint theme bị gán vào một module ⇒ một gói thiếu module sẽ làm người dùng không đổi được giao diện.');
    }

    public function test_settings_hub_exposes_the_appearance_section(): void
    {
        $app = (string) file_get_contents(resource_path('js/studio/MySettingsApp.vue'));
        $this->assertStringContainsString("{ id: 'appearance'", $app, 'Khu Cài đặt của tôi thiếu mục Giao diện.');
        $this->assertStringContainsString('AppearanceSection', $app, 'Mục Giao diện chưa được render.');
        $this->assertFileExists(resource_path('js/studio/components/settings/AppearanceSection.vue'));

        // Deep-link /cai-dat/appearance phải mở được (whitelist ở routes/web.php).
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString("'stylist', 'appearance'", $routes,
            "Thiếu 'appearance' trong whitelist của /cai-dat/{section} ⇒ deep-link 404.");

        $this->seed();
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        // KHÔNG chỉ "trả 200": phải đúng MỤC. Ba whitelist (route · StudioController::SETTINGS_SECTIONS
        // · SECTIONS trong app) phải khớp nhau, nếu không thì trang vẫn 200 mà mở nhầm mục Preset —
        // đúng loại lỗi im lặng mà một khẳng định "200" không bắt được.
        $this->actingAs($user)->get('/cai-dat/appearance')
            ->assertOk()
            ->assertSee('data-section="appearance"', false);

        // Ba biểu tượng của mục Giao diện phải có thật trong icons.json (nguồn icon duy nhất).
        $icons = json_decode((string) file_get_contents(resource_path('js/studio/icons.json')), true);
        foreach (['sun', 'moon', 'monitor'] as $icon) {
            $this->assertArrayHasKey($icon, $icons, 'Thiếu icon '.$icon.' cho mục Giao diện.');
        }
    }

    public function test_the_designer_token_page_shows_the_same_numbers_as_the_test(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@fabrikai.shop')->firstOrFail();
        $customer = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        // Trang nội bộ của người làm sản phẩm: khách KHÔNG vào được (đây không phải trang cho khách dùng).
        $this->actingAs($customer)->get('/he-thong-thiet-ke')->assertForbidden();

        $this->actingAs($admin)->get('/he-thong-thiet-ke')
            ->assertOk()
            ->assertSee('Bảng token hai theme', false)
            ->assertSee('Theme Tối', false)
            ->assertSee('Theme Sáng', false)
            // Đúng những bậc token đang có, và con số lấy từ CÙNG lớp ThemePalette mà test này dùng.
            ->assertSee('cream-400', false)
            ->assertSee('--color-scrim', false)
            ->assertSee(number_format(ThemePalette::contrast(ThemePalette::resolved('dark')['cream-400'], ThemePalette::resolved('dark')['ink-800']), 2, ',', '.'), false);

        // Bất biến quan trọng nhất: trang KHÔNG có nguồn dữ liệu riêng — nó phải gọi ThemePalette.
        $controller = (string) file_get_contents(app_path('Http/Controllers/ThemeController.php'));
        $this->assertStringContainsString('ThemePalette::matrix(', $controller,
            'Trang token phải đọc số liệu từ App\Support\ThemePalette, không tự tính lại.');
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        foreach ([resource_path('js/studio'), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $f) {
                if (in_array($f->getExtension(), ['vue', 'js', 'blade', 'php'], true)) {
                    $files[] = $f->getPathname();
                }
            }
        }

        return $files;
    }
}
