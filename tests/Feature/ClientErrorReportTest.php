<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientErrorController;
use App\Support\ThemePalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * MÃ TRA CỨU LỖI CHO LỖI PHÁT SINH CHỈ Ở TRÌNH DUYỆT (docs/DESIGN_SYSTEM.md §6.5 — món nợ cuối).
 *
 * Trước đây chỉ lỗi do MÁY CHỦ sinh mới có mã L-XXXX (studio_fail → studio_error_code). Lỗi sinh ngay
 * trong trình duyệt — mất mạng · fetch hỏng · canvas/Blob · exception không ai bắt — hiện ra giao diện
 * mà KHÔNG có mã nào, và cũng không có dòng nào trong storage/logs/laravel.log để hỗ trợ tra.
 *
 * Bộ test này khoá cả hai nửa của lời hứa:
 *   (A) đường nhận báo cáo phía máy chủ (log · định dạng mã · chống bơm log · throttle);
 *   (B) bất biến phía JavaScript (bảng chữ trùng PHP · mọi câu lỗi hiển thị đều đi qua đường có mã ·
 *       mã tra cứu của máy chủ không bị ném bỏ khi client dựng Error).
 */
class ClientErrorReportTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = [
        'code' => 'L-8F3K',
        'message' => 'TypeError: Failed to fetch',
        'context' => 'tao-anh',
        'userMessage' => 'Không kết nối được máy chủ.',
        'page' => '/',
    ];

    private function js(string $relative): string
    {
        $path = resource_path('js/studio/'.$relative);
        $this->assertTrue(File::exists($path), 'Thiếu file JS: '.$relative);

        return File::get($path);
    }

    /** Loại bỏ dòng chú thích để quét mã nguồn mà không bắt oan ví dụ trong bình luận. */
    private function code(string $source): string
    {
        $out = [];
        foreach (explode("\n", $source) as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*')) continue;
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    // ── (A) ĐƯỜNG NHẬN BÁO CÁO PHÍA MÁY CHỦ ────────────────────────────────

    /** Mã khách đọc cho tổng đài phải TRA ĐƯỢC: cùng mã có trong log máy chủ. */
    public function test_a_browser_error_is_logged_with_the_code_the_user_reads(): void
    {
        Log::spy();

        // KHÔNG đăng nhập: lỗi có thể nổ ngay ở trang đăng nhập — endpoint phải mở.
        $this->postJson('/api/client-errors', self::VALID)
            ->assertOk()
            ->assertJson(['ok' => true, 'code' => 'L-8F3K', 'logged' => true]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $ctx = []) => str_contains($message, 'client_error[L-8F3K]')
                && str_contains($message, 'tao-anh')
                && str_contains((string) ($ctx['message'] ?? ''), 'Failed to fetch'))
            ->once();
    }

    /** Câu trả về KHÔNG được chứa chi tiết kỹ thuật (chính endpoint này cũng theo §6). */
    public function test_the_response_never_echoes_technical_detail(): void
    {
        $payload = $this->postJson('/api/client-errors', self::VALID)->assertOk()->json();

        $this->assertSame(['ok', 'code', 'logged'], array_keys($payload));
        $this->assertStringNotContainsString('Failed to fetch', json_encode($payload));
    }

    /** Mã sai định dạng (chữ dễ đọc nhầm · sai độ dài · chữ thường) bị từ chối. */
    public function test_codes_outside_the_shared_alphabet_are_rejected(): void
    {
        // 'L-0O1I' và 'L-OABC'/'L-IABC': ký tự dễ đọc nhầm KHÔNG bao giờ được sinh ra ⇒ không nhận.
        foreach (['L-0O1I', 'L-OABC', 'L-IABC', 'L-8F3', 'L-8F3KX', 'l-8f3k', 'L-8f3K', '../../etc', ''] as $bad) {
            $this->postJson('/api/client-errors', ['code' => $bad, 'message' => 'x'])
                ->assertStatus(422);
        }

        $this->assertSame('/^L-[A-HJ-NP-Z2-9]{4}$/', ClientErrorController::CODE_PATTERN);
    }

    /** Gửi lại cùng một mã (mất mạng rồi gửi bù · tải lại trang) chỉ ghi log MỘT lần. */
    public function test_the_same_code_is_logged_only_once(): void
    {
        Log::spy();

        $this->postJson('/api/client-errors', self::VALID)->assertJson(['logged' => true]);
        $this->postJson('/api/client-errors', self::VALID)->assertJson(['logged' => false]);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'client_error[L-8F3K]'))->once();
    }

    /** Endpoint mở cho khách nên PHẢI bị chặn theo IP — không được thành đường bơm log. */
    public function test_it_is_throttled_per_ip(): void
    {
        $status = 0;
        for ($i = 0; $i < 31; $i++) {
            $status = $this->postJson('/api/client-errors', self::VALID)->getStatusCode();
        }

        $this->assertSame(429, $status, 'Quá 30 báo cáo/phút từ một IP phải bị chặn.');
    }

    // ── (B) BẤT BIẾN PHÍA TRÌNH DUYỆT ─────────────────────────────────────

    /** Bảng chữ sinh mã PHẢI giống hệt studio_error_code() — lệch một bên là test ĐỎ. */
    public function test_the_js_alphabet_matches_the_php_alphabet(): void
    {
        $js = $this->js('clientErrors.js');
        $php = File::get(app_path('Support/helpers.php'));

        preg_match('/CLIENT_CODE_ALPHABET = \'([A-Z0-9]+)\';/', $js, $jsHit);
        preg_match('/\\$alphabet = \'([A-Z0-9]+)\';/', $php, $phpHit);

        $this->assertNotEmpty($jsHit[1] ?? '', 'clientErrors.js phải khai CLIENT_CODE_ALPHABET.');
        $this->assertSame($phpHit[1] ?? '', $jsHit[1],
            'Bảng chữ sinh mã tra cứu ở JS và PHP đã lệch nhau: mã khách đọc sẽ không khớp định dạng máy chủ.');
        $this->assertStringNotContainsString('0', $jsHit[1]);
        $this->assertStringNotContainsString('O', $jsHit[1]);
    }

    /** Mọi câu lỗi hiển thị từ exception phải đi qua đường CÓ MÃ (failToast / userFacingError). */
    public function test_visible_errors_always_carry_a_lookup_code(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('js/studio')) as $file) {
            if (! in_array($file->getExtension(), ['js', 'vue'], true)) continue;
            $src = $this->code(File::get($file->getPathname()));

            // (a) Không chỗ nào còn đẩy e.message thô vào toast lỗi.
            if (preg_match('/toast\(\s*e\.message[^)]*,\s*\'error\'/', $src)) {
                $offenders[] = $file->getFilename().': toast(e.message) không có mã tra cứu';
            }
            // (b) Không chỗ nào còn ném lỗi từ phản hồi máy chủ mà BỎ error_code.
            if (preg_match('/throw new Error\(\s*(d|err|payload)\.message/', $src)) {
                $offenders[] = $file->getFilename().': throw new Error(d.message) làm mất error_code';
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)),
            'Lỗi hiển thị cho người dùng phải có mã tra cứu (docs/DESIGN_SYSTEM.md §6.5): dùng store.failToast(e, ...) '.
            'cho toast lỗi và apiError() khi dựng Error từ phản hồi máy chủ.');
    }

    /** Bộ bắt lỗi toàn cục phải được BẬT ở mọi entry SPA (không entry nào lọt lưới). */
    public function test_every_spa_entry_installs_the_browser_error_reporters(): void
    {
        $pageBoot = $this->js('pageBoot.js');
        $this->assertStringContainsString('installClientErrorReporters()', $pageBoot,
            'pageBoot.js phải bật bộ bắt lỗi trình duyệt — đó là chỗ dùng chung của cả 6 entry.');

        $client = $this->js('clientErrors.js');
        $this->assertStringContainsString("'error'", $client);
        $this->assertStringContainsString("'unhandledrejection'", $client);
        $this->assertStringContainsString('/api/client-errors', $client);

        // 6 entry SPA: nạp pageBoot ⇒ bộ bắt lỗi có mặt ở tất cả.
        foreach (['main.js', 'settings.js', 'my-settings.js', 'admin.js', 'collections.js'] as $entry) {
            $this->assertMatchesRegularExpression('/from \'\.\/pageBoot\.js\'/', $this->js($entry),
                $entry.' không nạp pageBoot.js ⇒ lỗi trình duyệt ở shell này sẽ không có mã tra cứu.');
        }

        // Mọi root có ô hiển thị thông báo đều phải nối bộ báo lỗi vào ô đó.
        foreach (['StudioApp.vue', 'LibraryApp.vue', 'pages/CollectionsPage.vue', 'MySettingsApp.vue', 'AdminApp.vue', 'SettingsApp.vue'] as $root) {
            $this->assertStringContainsString('toastClientErrors(', $this->js($root),
                $root.' chưa nối bộ báo lỗi trình duyệt ⇒ người dùng không thấy mã tra cứu.');
        }
    }

    /** Mã tra cứu của MÁY CHỦ không được bị ném bỏ khi client dựng Error từ phản hồi. */
    public function test_the_studio_keeps_the_server_lookup_code(): void
    {
        $store = static::studioStoreSource();

        $this->assertStringContainsString('function apiError(', $store);
        $this->assertStringContainsString('err.error_code = payload.error_code;', $store);
        $this->assertGreaterThanOrEqual(10, substr_count($store, 'throw apiError('),
            'Các chỗ dựng Error từ phản hồi máy chủ phải dùng apiError() để giữ error_code.');
        $this->assertStringContainsString('reportClientError(e ||', $store,
            'userFacingError phải tự sinh mã cho lỗi chỉ có ở trình duyệt.');
        $this->assertMatchesRegularExpression('/\(mã tra cứu: /', $store);
    }

    /** Trang token (§1.4) không được nói sai về cơ chế mã tra cứu. */
    public function test_the_design_doc_no_longer_lists_browser_codes_as_debt(): void
    {
        $doc = File::get(base_path('docs/DESIGN_SYSTEM.md'));

        $this->assertStringContainsString('client-errors', $doc, 'Tài liệu phải ghi đường nhận báo cáo lỗi trình duyệt.');
        $this->assertStringNotContainsString('- [ ] `error_code` cho lỗi phát sinh CHỈ ở trình duyệt', $doc,
            'Món nợ này đã xong thì không được để lại trong danh sách nợ.');
    }

    /** Nơi gọi đã hiển thị lỗi rồi thì KHÔNG bắn thêm sự kiện — nếu không khách nhận hai thẻ cho một lỗi. */
    public function test_the_store_does_not_double_notify(): void
    {
        $store = static::studioStoreSource();

        $this->assertStringContainsString('silent: true', $store,
            'userFacingError phải gọi reportClientError với silent: true — nơi gọi (failToast/toast) đã hiển thị lỗi.');
        $this->assertStringContainsString('if (!options.silent) emit(record);', $this->code($this->js('clientErrors.js')),
            'clientErrors phải bỏ qua việc báo cho ô thông báo khi silent.');
    }

    /**
     * LUẬT 19 (§14): "đặt cờ rồi quên render". Bộ sưu tập và Thư viện đều gọi store.toast() nhưng KHÔNG
     * render NotificationCenter ⇒ mọi thông báo (kể cả mã tra cứu) vô hình với người dùng.
     */
    public function test_every_shell_that_toasts_also_renders_the_notification_center(): void
    {
        $missing = [];

        foreach (['StudioApp.vue', 'LibraryApp.vue', 'pages/CollectionsPage.vue'] as $root) {
            $src = $this->js($root);
            if (! str_contains($src, 'store.toast(') && ! str_contains($src, 'failToast(')) continue;
            if (! preg_match('/<NotificationCenter\s*\/>/', $src)) {
                $missing[] = $root;
            }
        }

        $this->assertSame([], $missing,
            'Shell gọi store.toast() mà không render <NotificationCenter /> ⇒ thông báo (kèm mã tra cứu) vô hình.');
    }

    /**
     * MA TRA CUU chi tra duoc khi log noi DUNG cho hong. Lan khach bao L-P7CR, log chi co cau loi ma
     * KHONG co endpoint => ho tro phai doan mo. Nay moi loi tu loi goi API mang theo ngu canh
     * "api <duong dan> -> <tinh huong>" va ngu canh do di thang vao log.
     */
    public function test_api_errors_carry_their_endpoint_into_the_log_context(): void
    {
        $store = static::studioStoreSource();

        $this->assertStringContainsString('err.api_context =', $store, 'Loi tu API phai mang ngu canh endpoint.');
        $this->assertStringContainsString('(e && e.api_context)', $store, 'userFacingError phai dung ngu canh do khi bao loi.');
        // Hai tình huống nói KHÁC nhau: hết phiên (tải lại là xong) và máy chủ trả dữ liệu hỏng.
        $this->assertStringContainsString('Phiên làm việc đã hết. Hãy tải lại trang để đăng nhập lại.', $store);
        $this->assertStringContainsString('Không tải được dữ liệu. Hãy tải lại trang và thử lại.', $store);
        $this->assertStringNotContainsString('máy chủ trả dữ liệu không hợp lệ', $store);
    }
}
