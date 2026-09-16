<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Khoá lớp XSS ở tầng ĐẦU RA: sink nguy hiểm + payload nhúng vào <script>.
 *
 * Audit vòng 19 (ghi lại kết quả): blade {!! !!} = 0 · innerHTML/insertAdjacentHTML/document.write/
 * outerHTML = 0 · v-html chỉ còn 2 chỗ (RefImageCard đã đổi sang primitives có cấu trúc ở vòng 12;
 * StudioIcon.vue:140 v-html="ICONS[name]" là CONST hardcode trong chính file, tra theo key nên không
 * chèn được markup — chấp nhận có điều kiện, xem STUDIO_REVIEW.md).
 *
 * File này giữ các bất biến đó bằng test để không tái phát âm thầm.
 */
class StudioXssSinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ── Payload SPA nhúng trong <script> ─────────────────────────────────

    public function test_boot_payload_cannot_break_out_of_script_tag(): void
    {
        // index.blade.php: window.__STUDIO_BOOT__ = @json($boot) — payload CHỨA DỮ LIỆU NGƯỜI DÙNG
        // (name/email/avatar). Nếu @json mất các flag JSON_HEX_* thì tên chứa "</script>" sẽ đóng
        // thẻ script và chèn script mới -> stored XSS.
        $payload = '</script><script>window.__XSS__=1</script>';

        $u = User::factory()->create([
            'name' => $payload,
            'role' => User::ROLE_ADMIN,
        ]);

        $html = $this->actingAs($u)->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            '</script><script>window.__XSS__=1</script>',
            $html,
            'Payload boot đóng được thẻ script -> stored XSS.'
        );
        // Dấu < phải được JSON_HEX_TAG escape thành \u003C.
        $this->assertStringContainsString('\u003C', $html, '@json phải escape < thành \\u003C (JSON_HEX_TAG).');
        $this->assertStringNotContainsString('<script>window.__XSS__', $html);
    }

    public function test_boot_payload_escapes_quotes_and_ampersand(): void
    {
        // Kiểm ở tầng sinh mã: nếu ai đó truyền flag vào @json làm mất JSON_HEX_APOS/QUOT/AMP thì
        // thuộc tính/chuỗi có thể bị phá. Dùng chuỗi chứa cả nháy đơn, nháy kép và &.
        $u = User::factory()->create([
            'name' => 'A\'B"C&D',
            'role' => User::ROLE_ADMIN,
        ]);

        $html = $this->actingAs($u)->get('/')->assertOk()->getContent();

        // JSON_HEX_APOS  -> \u0027 ; JSON_HEX_QUOT -> \u0022 ; JSON_HEX_AMP -> \u0026
        $this->assertStringContainsString('\u0027', $html);
        $this->assertStringContainsString('\u0022', $html);
        $this->assertStringContainsString('\u0026', $html);
    }

    // ── Bất biến tĩnh: không có sink thô ─────────────────────────────────

    public function test_blades_contain_no_raw_output_sink(): void
    {
        $bad = [];
        foreach ($this->files(resource_path('views'), '.blade.php') as $file) {
            $src = (string) file_get_contents($file);
            if (str_contains($src, '{!!')) {
                $bad[] = $this->rel($file);
            }
        }

        $this->assertSame([], $bad, "Blade dùng {!! !!} (raw) — phải dùng {{ }}:\n".implode("\n", $bad));
    }

    public function test_js_contains_no_raw_html_sinks(): void
    {
        $bad = [];
        foreach ($this->files(resource_path('js'), null) as $file) {
            if (! in_array(pathinfo($file, PATHINFO_EXTENSION), ['js', 'vue'], true)) {
                continue;
            }
            $src = (string) file_get_contents($file);
            foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $sink) {
                if (str_contains($src, $sink)) {
                    $bad[] = $this->rel($file)." -> {$sink}";
                }
            }
        }

        $this->assertSame([], $bad, "JS dùng sink HTML thô:\n".implode("\n", $bad));
    }

    public function test_only_documented_v_html_sinks_remain(): void
    {
        // Danh sách này là CHỦ ĐÍCH và đã được rà: nếu xuất hiện v-html mới ở file khác, test đỏ để
        // buộc người thêm phải nêu lý do (và cập nhật danh sách này một cách có ý thức).
        $allowed = [
            'resources/js/studio/components/StudioIcon.vue',   // ICONS = const hardcode, tra theo key
        ];

        $found = [];
        foreach ($this->files(resource_path('js'), null) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'vue') {
                continue;
            }
            $src = (string) file_get_contents($file);
            // bỏ qua dòng comment (đã có file ghi chú lý do đổi khỏi v-html)
            $code = implode("\n", array_filter(
                explode("\n", $src),
                fn ($l) => ! str_contains(ltrim($l), '<!--')
            ));
            if (preg_match('/v-html\s*=/', $code)) {
                $found[] = $this->rel($file);
            }
        }

        $unexpected = array_values(array_diff($found, $allowed));
        $this->assertSame([], $unexpected, "v-html mới chưa được rà:\n".implode("\n", $unexpected));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return string[] */
    private function files(string $dir, ?string $suffix): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
            if (! $f->isFile()) {
                continue;
            }
            if ($suffix !== null && ! str_ends_with($f->getFilename(), $suffix)) {
                continue;
            }
            $out[] = $f->getPathname();
        }

        return $out;
    }

    private function rel(string $abs): string
    {
        return str_replace(base_path().'/', '', $abs);
    }
}
