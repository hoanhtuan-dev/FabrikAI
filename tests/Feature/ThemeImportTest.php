<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use App\Support\DaisyTheme;
use App\Support\DaisyThemeLink;
use App\Support\ThemeColor;
use App\Support\ThemeCss;
use App\Support\ThemeDeriver;
use App\Support\ThemeLibrary;
use App\Support\ThemeRamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * IMPORT THEME TỪ LIÊN KẾT daisyUI THEME GENERATOR (2026-09-25 · docs/DESIGN_SYSTEM.md §1.1).
 *
 * Bốn nhóm bất biến được khoá ở đây — mỗi nhóm ứng với một cách tính năng này có thể hỏng ÂM THẦM:
 *
 *   (a) ĐỌC LIÊN KẾT: đúng định dạng của daisyUI (base64url + zlib), đọc được liên kết thật, mã hoá
 *       lại rồi đọc lại ra ĐÚNG theme đó, và TỪ CHỐI mọi thứ khác — kể cả chuỗi cố tình phá CSS.
 *       Giá trị của theme đi thẳng vào <style> của mọi trang, nên đây là đường XSS nếu lớp kiểm hở.
 *   (b) SINH BẢNG MÀU: mọi bậc chữ và màu trạng thái đạt WCAG AA ở CẢ HAI chế độ, và app.css (đã
 *       commit) khớp đúng theme gốc — lệch nghĩa là tệp CSS bán cho khách không còn là bảng màu
 *       mà Quản trị viên nhìn thấy trên trang.
 *   (c) LƯU NHIỀU THEME MỖI LẦN IMPORT: mỗi liên kết sinh ra NHIỀU dòng (bản gốc + bản đối ứng
 *       Sáng/Tối), dán nhiều liên kết thì nhân lên, và import lại đúng liên kết đó KHÔNG sinh bản sao.
 *   (d) ĐƯỜNG ĐI: chỉ Quản trị viên vào được, mỗi chế độ có ĐÚNG một theme đang bật, và theme đang
 *       bật được phát vào <head> (không nháy màu) — còn khi dùng theme gốc thì KHÔNG phát gì thêm.
 */
class ThemeImportTest extends TestCase
{
    use RefreshDatabase;

    /** Liên kết thật người dùng đưa: theme "dark" của daisyUI Theme Generator. */
    private const LINK = 'https://daisyui.com/theme-generator/#theme=eJxtlOuO2yAQhV8FIVVqpWTEDBfjvI1j48Zax0TgaLe76rtXhm1iB_9k5juHM4D44lNzdfzEuya88QNv_ejDMbYXt64ej7l-bqI7ohD8xP3b2F5-kgYpfzABAg0jTaDo1ytPa14CmcwrRloCFrhc4wioM06MtAJRF3zrp9lN80NTV2BFlURUM9IGrKrWqlsYrk348xBou8AkJaOqAsQ9uNzlewrLqCKQqNai6Fo_des9TJqCFDKpFUhhd_FyF5XnsEwqAtIbWdO2a7ZKM6MmhhahRirZwl-m0YWRDK0FhWatmdx9Ds34gDGHEZqR1WBJ7sDlBJRFipE1IDehhqn3z_jJfXlFksAYfAULZ6pzdsNISUC9ubZ4b1sX49M9XRdWFUMjgbbZv-HydPIrSiIL9eaK35swDdPvB2vTnGhrZhUoqnfYwl9h0iAxpaEWG38Xgg_P-JmsFUMJimxBlqeTX4PQDAnE_-yh6YZ7PEY3unZO_gJ0cNd1sx_c2KUOvbbO_mMricOn27rRtrdndvahcwuNt49U6NxtvizrtJr8EJevR_AD71zf3MeZn_pmjO7Ab8H1LsT0K-Xa33-M5lV3';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        ThemeLibrary::flush();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    /** Một theme THỨ HAI để thử import: cùng khung, khác bảng màu (xanh lá) — sinh bằng chính bộ mã hoá. */
    private function secondLink(): string
    {
        $base = DaisyTheme::fromArray(DaisyThemeLink::decode(self::LINK)[0]->toArray());

        return DaisyThemeLink::encode($base->withName('Rừng xanh')->withTokens([
            '--color-primary' => '#1f7a4d',
            '--color-secondary' => '#a35b1f',
            '--color-accent' => '#2f6fb0',
            '--color-base-100' => '#10201a',
            '--color-base-200' => '#0d1a15',
            '--color-base-300' => '#0a1511',
        ]));
    }

    // ── (a) ĐỌC LIÊN KẾT ────────────────────────────────────────────────────────────────

    public function test_the_link_decodes_to_the_exact_tokens_of_the_theme(): void
    {
        $themes = DaisyThemeLink::decode(self::LINK);

        $this->assertCount(1, $themes);
        $theme = $themes[0];

        $this->assertSame('dark', $theme->name());
        $this->assertSame('dark', $theme->scheme());

        // Đúng giá trị của liên kết (đã đổi từ oklch sang hex — cùng một màu, dạng kiểm được bằng số).
        $this->assertSame('#1d232a', $theme->raw('--color-base-100'));
        $this->assertSame('#191e24', $theme->raw('--color-base-200'));
        $this->assertSame('#15191e', $theme->raw('--color-base-300'));
        $this->assertSame('#ecf9ff', $theme->raw('--color-base-content'));
        $this->assertSame('#605dff', $theme->raw('--color-primary'));
        $this->assertSame('#f43098', $theme->raw('--color-secondary'));
        $this->assertSame('#00d3bb', $theme->raw('--color-accent'));
        $this->assertSame('#00bafe', $theme->raw('--color-info'));
        $this->assertSame('#00d390', $theme->raw('--color-success'));
        $this->assertSame('#fcb700', $theme->raw('--color-warning'));
        $this->assertSame('#ff627d', $theme->raw('--color-error'));

        // Hình học cũng đi theo theme, không chỉ màu.
        $this->assertSame('0.5rem', $theme->raw('--radius-box'));
        $this->assertSame('1px', $theme->raw('--border'));
        $this->assertTrue($theme->flag('--depth'));
    }

    public function test_the_theme_survives_a_round_trip_through_the_encoder(): void
    {
        $theme = DaisyThemeLink::decode(self::LINK)[0];
        $again = DaisyThemeLink::decode(DaisyThemeLink::url($theme));

        $this->assertCount(1, $again);
        $this->assertSame($theme->checksum(), $again[0]->checksum(), 'Mã hoá rồi đọc lại phải ra ĐÚNG theme đó.');
    }

    public function test_the_decoder_accepts_the_shapes_a_user_can_paste(): void
    {
        $payload = substr(self::LINK, strpos(self::LINK, 'theme=') + 6);

        // Liên kết đầy đủ · liên kết không có tiền tố · chỉ mỗi chuỗi payload.
        foreach ([self::LINK, 'https://daisyui.com/theme-generator/#theme='.$payload, $payload] as $input) {
            $this->assertCount(1, DaisyThemeLink::decode($input), 'Không đọc được dạng: '.substr($input, 0, 40));
        }

        // Nhiều liên kết trong một lần dán ⇒ nhiều theme (đây là "lưu nhiều theme mỗi lần import").
        $this->assertCount(2, DaisyThemeLink::decode(self::LINK."\n".$this->secondLink()));
    }

    public function test_the_decoder_rejects_broken_or_hostile_input(): void
    {
        // Không có liên kết / bị cắt khi copy / không phải base64.
        foreach (['', 'https://daisyui.com/theme-generator/', 'theme=eJx', 'theme=@@@khong-phai-base64@@@'] as $bad) {
            try {
                DaisyThemeLink::decode($bad);
                $this->fail('Phải từ chối nội dung: '.$bad);
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage(), 'Câu từ chối phải nói được vướng ở đâu.');
            }
        }

        // Giá trị màu không thuộc allowlist ⇒ KHÔNG được nhận, vì nó đi thẳng vào <style>.
        $base = DaisyThemeLink::decode(self::LINK)[0]->toArray();
        foreach (['red;} body{display:none}', 'url(javascript:alert(1))', '#fff;}', 'var(--x)', 'expression(1)'] as $evil) {
            try {
                DaisyTheme::fromArray(array_merge($base, ['--color-primary' => $evil]));
                $this->fail('Phải từ chối giá trị màu: '.$evil);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('--color-primary', $e->getMessage());
            }
        }

        // Công tắc chỉ nhận 0/1; bán kính chỉ nhận số + đơn vị.
        foreach ([['--depth' => '2'], ['--border' => '1px solid red'], ['--radius-box' => 'calc(1rem)']] as $pair) {
            $this->expectExceptionMessageMatches('/--(depth|border|radius-box)/');
            DaisyTheme::fromArray(array_merge($base, $pair));
        }
    }

    public function test_a_decompression_bomb_is_refused_instead_of_hanging_the_server(): void
    {
        // 8 MB số 0 nén lại còn vài KB — đúng hình dạng của một quả bom nén.
        $bomb = DaisyThemeLink::PARAM.'='.rtrim(strtr(base64_encode(gzcompress(str_repeat('0', 8 * 1024 * 1024), 9)), '+/', '-_'), '=');

        try {
            DaisyThemeLink::decode($bomb);
            $this->fail('Payload bung ra quá lớn phải bị từ chối.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('giải nén', $e->getMessage());
        }
    }

    // ── (b) SINH BẢNG MÀU ───────────────────────────────────────────────────────────────

    public function test_the_builtin_theme_is_served_exactly_and_app_css_matches_it(): void
    {
        $dark = ThemeLibrary::tokens('dark');

        // Chế độ TỐI là CHÍNH theme gốc: mọi màu vai trò phải đúng giá trị của liên kết.
        $this->assertSame('#1d232a', $dark['--color-ink-800']);
        $this->assertSame('#605dff', $dark['--color-primary']);
        $this->assertSame('#f43098', $dark['--color-secondary']);
        $this->assertSame('#00d3bb', $dark['--color-accent']);
        $this->assertSame('#09090b', $dark['--color-neutral']);
        $this->assertSame('#ff627d', $dark['--color-error']);

        // …trừ ĐÚNG hai màu CHỮ mà daisyUI để dưới ngưỡng AA (đo được 4,13:1 và 3,04:1).
        $this->assertSame('#ffffff', $dark['--color-primary-content']);
        $this->assertLessThan(ThemeColor::AA, ThemeColor::contrast('#edf1fe', '#605dff'), 'Giá trị gốc phải thật sự chưa đạt, nếu không phép chỉnh là vô nghĩa.');
        $this->assertGreaterThanOrEqual(ThemeColor::AA, ThemeColor::contrast($dark['--color-primary-content'], '#605dff'));

        // app.css đã commit phải khớp từng ký tự với theme gốc — đây là chốt canh "sinh rồi quên commit".
        $this->assertTrue(ThemeCss::inSync(),
            'resources/css/app.css không khớp theme gốc. Chạy "php artisan theme:sync" rồi commit lại.');
    }

    public function test_both_schemes_meet_wcag_aa_after_any_import(): void
    {
        $this->assertAa(ThemeLibrary::tokens('dark'), 'theme gốc · Tối');
        $this->assertAa(ThemeLibrary::tokens('light'), 'bản đối ứng · Sáng');

        // Và cả với một theme KHÁC (màu nhận diện hoàn toàn khác) — bất biến không được phụ thuộc
        // vào việc theme gốc tình cờ đã đạt chuẩn.
        $foreign = DaisyThemeLink::decode($this->secondLink())[0];
        foreach (['dark' => $foreign, 'light' => ThemeDeriver::counterpart($foreign)['theme']] as $scheme => $theme) {
            $this->assertAa(ThemeRamp::full($theme), 'theme import · '.$scheme);
        }
    }

    /** @param array<string,string> $tokens */
    private function assertAa(array $tokens, string $label): void
    {
        $surfaces = [$tokens['--color-ink-950'], $tokens['--color-ink-900'], $tokens['--color-ink-800'], $tokens['--color-ink-700']];

        foreach (['--color-cream-100', '--color-cream-200', '--color-cream-300', '--color-cream-400',
            '--color-brand-100', '--color-brand-200', '--color-brand-300',
            '--color-error', '--color-warning', '--color-success', '--color-info'] as $key) {
            $worst = ThemeColor::worstContrast($tokens[$key], $surfaces);
            $this->assertGreaterThanOrEqual(ThemeColor::AA, $worst, sprintf(
                '%s: %s chỉ đạt %.2f:1 trên bề mặt xấu nhất (cần ≥ %.1f:1).', $label, $key, $worst, ThemeColor::AA
            ));
        }

        // Nút chính của toàn app: chữ trắng trên brand-600.
        $this->assertGreaterThanOrEqual(ThemeColor::AA, ThemeColor::contrast('#ffffff', $tokens['--color-brand-600']),
            $label.': chữ trắng trên nền brand-600 không đạt AA.');

        // Bề mặt phải đảo chiều giữa hai chế độ, nếu không "bản Sáng" chỉ là bản sao của bản Tối.
        $this->assertTrue(ThemeColor::isLiteral($tokens['--color-ink-950']), $label.': bề mặt không phải màu hợp lệ.');
    }

    public function test_the_light_scheme_is_a_real_counterpart_not_a_copy(): void
    {
        $dark = ThemeLibrary::tokens('dark');
        $light = ThemeLibrary::tokens('light');

        $this->assertGreaterThan(ThemeColor::luminance($dark['--color-ink-950']), ThemeColor::luminance($light['--color-ink-950']),
            'Nền trang của chế độ Sáng phải SÁNG HƠN nền trang của chế độ Tối.');
        $this->assertLessThan(ThemeColor::luminance($dark['--color-cream-100']), ThemeColor::luminance($light['--color-cream-100']),
            'Chữ chính của chế độ Sáng phải TỐI HƠN chữ chính của chế độ Tối.');

        // Màu NHẬN DIỆN giữ nguyên giữa hai chế độ — đổi chế độ là đổi nền/chữ, không đổi thương hiệu.
        foreach (['--color-primary', '--color-secondary', '--color-accent', '--color-brand-600'] as $key) {
            $this->assertSame($dark[$key], $light[$key], 'Màu nhận diện '.$key.' không được đổi giữa hai chế độ.');
        }

        // Còn màu TRẠNG THÁI ở chế độ Sáng phải đậm hơn (chúng là CHỮ trên nền sáng).
        $this->assertLessThan(ThemeColor::luminance($dark['--color-warning']), ThemeColor::luminance($light['--color-warning']));
    }

    // ── (c) IMPORT QUA ĐƯỜNG HTTP ───────────────────────────────────────────────────────

    public function test_one_import_saves_several_themes_and_activates_them_per_scheme(): void
    {
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => $this->secondLink()])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // MỘT liên kết ⇒ HAI theme được lưu (bản gốc + bản đối ứng), cùng một lô import.
        $this->assertSame(2, Theme::count());
        $saved = Theme::orderBy('id')->get();
        $this->assertSame(['dark', 'light'], $saved->pluck('scheme')->all());
        $this->assertSame(Theme::SOURCE_IMPORT, $saved[0]->source);
        $this->assertSame(Theme::SOURCE_DERIVED, $saved[1]->source);
        $this->assertSame($saved[0]->batch, $saved[1]->batch);
        $this->assertNotNull($saved[0]->created_by);

        // Cả hai chế độ đều đã được bật (trước đó chưa chọn theme nào) ⇒ đổi giao diện là thấy ngay.
        $this->assertSame($saved[0]->id, ThemeLibrary::active('dark')?->id);
        $this->assertSame($saved[1]->id, ThemeLibrary::active('light')?->id);
        $this->assertSame('#1f7a4d', ThemeLibrary::tokens('dark')['--color-primary']);

        // Dán NHIỀU liên kết một lượt ⇒ nhân lên theo số liên kết (đây là "lưu nhiều theme mỗi lần import").
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => self::LINK."\n".$this->secondLink()])
            ->assertRedirect()->assertSessionHasNoErrors();
        // Liên kết thứ hai đã có ⇒ chỉ thêm 2 theme của liên kết thứ nhất.
        $this->assertSame(4, Theme::count());
    }

    public function test_importing_the_same_link_twice_does_not_create_duplicates(): void
    {
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => $this->secondLink()])->assertRedirect();
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => $this->secondLink()])
            ->assertRedirect()
            ->assertSessionHas('theme_status', fn (array $s) => $s['kind'] === 'warn' && str_contains($s['title'], 'Không có theme mới'));

        $this->assertSame(2, Theme::count(), 'Import lại đúng liên kết đó không được sinh bản sao.');
    }

    public function test_a_broken_link_comes_back_as_a_readable_error_not_a_crash(): void
    {
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => 'https://daisyui.com/theme-generator/'])
            ->assertSessionHasErrors('link');

        $this->assertSame(0, Theme::count());
        $this->assertStringContainsString('Không tìm thấy liên kết', session('errors')->first('link'));
    }

    public function test_only_the_owner_can_touch_the_library(): void
    {
        $this->actingAs($this->customer())->post(route('theme.import'), ['link' => $this->secondLink()])->assertForbidden();
        $this->actingAs($this->customer())->get('/he-thong-thiet-ke')->assertForbidden();
        $this->assertSame(0, Theme::count());

        // Khách chưa đăng nhập (phải đăng xuất thật: actingAs ở trên còn hiệu lực cho các request sau).
        auth()->logout();
        $this->post(route('theme.import'), ['link' => $this->secondLink()])->assertRedirect('/dang-nhap');
        $this->assertSame(0, Theme::count());
    }

    public function test_the_active_theme_is_served_in_the_head_and_the_builtin_one_is_not(): void
    {
        // Đang dùng theme gốc ⇒ KHÔNG phát thêm <style> nào (bảng màu đã nằm trong app.css).
        $this->get('/')->assertOk()->assertDontSee('fabrikai-theme-override', false);

        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => $this->secondLink()])->assertRedirect();

        // Sau khi bật: token của theme mới phải có trong <head> — nếu không thì trang sẽ nháy màu cũ
        // rồi mới đổi (đúng lỗi mà partial theme được tạo ra để chặn).
        $this->get('/')
            ->assertOk()
            ->assertSee('fabrikai-theme-override', false)
            ->assertSee('[data-theme=dark]{', false)
            ->assertSee('#1f7a4d', false)
            ->assertSee('[data-theme=light]{', false)
            ->assertSee('theme-color', false);

        // Khối CSS phát qua blade {{ }} (repo cấm xuất thô) ⇒ nó KHÔNG được chứa ký tự bị HTML escape,
        // nếu không bộ chọn sẽ hỏng ngay trên trang thật.
        foreach (['dark', 'light'] as $scheme) {
            $block = ThemeLibrary::cssBlock($scheme);
            $this->assertSame([], array_values(array_filter(str_split($block), fn (string $c) => str_contains('&"\'<>', $c))),
                'Khối CSS ghi đè chứa ký tự bị HTML escape ('.$scheme.') — nó sẽ hỏng khi blade xuất bằng {{ }}.');
        }
    }

    public function test_deleting_or_resetting_returns_the_scheme_to_the_builtin_palette(): void
    {
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => $this->secondLink()])->assertRedirect();
        $dark = Theme::where('scheme', 'dark')->firstOrFail();
        $light = Theme::where('scheme', 'light')->firstOrFail();

        // Quay về bảng màu gốc bằng nút "Dùng bảng màu gốc" (không phải bằng cách xoá theme).
        $this->actingAs($this->admin())->post(route('theme.reset', 'dark'))->assertRedirect();
        $this->assertNull(ThemeLibrary::active('dark'));
        $this->assertSame('#605dff', ThemeLibrary::tokens('dark')['--color-primary']);
        $this->assertSame(2, Theme::count(), 'Quay về gốc KHÔNG được xoá theme khỏi thư viện.');

        // Xoá theme đang bật ⇒ chế độ đó tự quay về gốc, không để lại một con trỏ trỏ vào hư không.
        $this->actingAs($this->admin())->delete(route('theme.destroy', $light))->assertRedirect();
        $this->assertNull(ThemeLibrary::active('light'));
        $this->assertSame('#605dff', ThemeLibrary::tokens('light')['--color-primary']);
        $this->assertSame(1, Theme::count());

        // Hoàn tác cả lô ⇒ xoá nốt theme còn lại của lần import đó.
        $this->actingAs($this->admin())->delete(route('theme.batch.destroy', $dark->batch))->assertRedirect();
        $this->assertSame(0, Theme::count());
        $this->assertNull(ThemeLibrary::active('dark'));
    }

    public function test_only_one_theme_can_be_active_per_scheme(): void
    {
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => $this->secondLink()])->assertRedirect();
        $first = Theme::where('scheme', 'dark')->firstOrFail();

        // Theme thứ hai (cùng chế độ) được import rồi bật ⇒ theme trước đó phải NHẢ.
        $other = DaisyTheme::fromArray(DaisyThemeLink::decode($this->secondLink())[0]->toArray())
            ->withName('Xanh biển')->withTokens(['--color-primary' => '#1f5fa8']);
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => DaisyThemeLink::encode($other)])->assertRedirect();

        $newDark = Theme::where('scheme', 'dark')->where('name', 'Xanh biển')->firstOrFail();
        $this->actingAs($this->admin())->post(route('theme.activate', $newDark))->assertRedirect();

        $this->assertSame($newDark->id, ThemeLibrary::active('dark')?->id);
        $this->assertNotSame($first->id, ThemeLibrary::active('dark')?->id);
        $this->assertSame(1, Theme::where('scheme', 'dark')->whereIn('id', [ThemeLibrary::active('dark')?->id])->count());
    }

    public function test_the_library_page_shows_the_themes_and_the_active_ones(): void
    {
        $this->actingAs($this->admin())->post(route('theme.import'), ['link' => $this->secondLink()])->assertRedirect();

        $this->actingAs($this->admin())->get('/he-thong-thiet-ke')
            ->assertOk()
            ->assertSee('Thư viện theme', false)
            ->assertSee('Rừng xanh', false)                 // tên lấy từ chính payload
            ->assertSee('Rừng xanh · Sáng', false)          // bản đối ứng tự sinh
            ->assertSee('ĐANG DÙNG', false)
            ->assertSee('FabrikAI · Tối', false)            // theme gốc vẫn hiện để có đường quay về
            ->assertSee('Dùng bảng màu gốc', false)
            ->assertSee('Bảng token hai theme', false);      // phần đọc số liệu vẫn còn nguyên
    }

    /**
     * ĐƯỜNG VÀO phải có thật — nếu không thì tính năng coi như không tồn tại.
     *
     * Đây không phải lo xa: chính khối menu này đã có ghi chú về lần mục "Giao diện" bị thiếu lối vào
     * (tính năng đổi Sáng/Tối có từ trước nhưng người dùng không tìm thấy). Trang /he-thong-thiet-ke là
     * chỗ DUY NHẤT import được theme, nên nó phải có mặt ở cả hai nơi chủ sản phẩm hay đứng: menu
     * Cài đặt trong Studio (khối Quản trị) và console Owner.
     */
    public function test_the_owner_has_a_visible_way_to_the_theme_library(): void
    {
        $this->assertNotNull(RouteFacade::getRoutes()->getByName('design-tokens.page'), 'Thiếu route /he-thong-thiet-ke.');

        $studio = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));

        // Khối Quản trị trong menu Cài đặt là khối is_admin CUỐI CÙNG của tệp; link phải nằm TRONG đó
        // (sau dấu mở khối và trước </nav>) — nếu không thì khách cũng thấy một link dẫn tới 403.
        $ownerBlock = strripos($studio, 'v-if="store.user && store.user.is_admin"');
        $link = strpos($studio, 'href="/he-thong-thiet-ke"');
        $navEnd = strpos($studio, '</nav>', (int) $ownerBlock);

        $this->assertNotFalse($ownerBlock, 'Không tìm thấy khối Quản trị trong menu Cài đặt của Studio.');
        $this->assertNotFalse($link, 'Menu Cài đặt của Studio thiếu lối vào /he-thong-thiet-ke.');
        $this->assertGreaterThan($ownerBlock, $link, 'Lối vào /he-thong-thiet-ke phải nằm TRONG khối Quản trị (is_admin).');
        $this->assertLessThan($navEnd, $link, 'Lối vào /he-thong-thiet-ke phải nằm trong menu Cài đặt.');

        // Console Owner: /admin đã bị middleware admin chặn nên nút này không cần gate thêm.
        $admin = (string) file_get_contents(resource_path('js/studio/AdminApp.vue'));
        $this->assertStringContainsString('href="/he-thong-thiet-ke"', $admin,
            'Console Owner thiếu lối vào /he-thong-thiet-ke.');

        // Biểu tượng phải có thật trong icons.json (nguồn icon duy nhất của cả Vue lẫn PHP).
        $icons = json_decode((string) file_get_contents(resource_path('js/studio/icons.json')), true);
        $this->assertArrayHasKey('palette', $icons, 'Thiếu icon palette cho lối vào Hệ thống thiết kế.');
    }

    public function test_the_sync_command_reports_drift_instead_of_writing(): void
    {
        $this->artisan('theme:sync --check')->assertSuccessful();

        $original = (string) file_get_contents(resource_path(ThemeCss::FILE));
        file_put_contents(resource_path(ThemeCss::FILE), str_replace('--color-brand-600', '--color-brand-600x', $original));

        try {
            $this->artisan('theme:sync --check')->assertFailed();
        } finally {
            file_put_contents(resource_path(ThemeCss::FILE), $original);
        }

        $this->assertTrue(ThemeCss::inSync());
    }
}
