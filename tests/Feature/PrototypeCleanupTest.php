<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RÀ SOÁT — DỌN TRÙNG LẶP · ĐÚNG URL · ĐÚNG FONT (đợt 62 · 2026-09-26).
 *
 * Ba nhóm bất biến, mỗi nhóm ứng với một việc chủ dự án yêu cầu:
 *   A. MỘT VIỆC — MỘT LỐI VÀO trên mỗi màn hình (không còn hai nút cho cùng một hành động/đích);
 *   B. MỌI LIÊN KẾT NỘI BỘ PHẢI GỌI ĐÚNG URL: phân giải được thành route GET có thật, và KHÔNG dùng
 *      đường cũ (alias) khi đã có đường chính thức;
 *   C. FONT THEO PROTOTYPE: ba họ chữ (Inter · Fraunces · Space Grotesk) có token và được nạp thật,
 *      và nhãn nhỏ in hoa dùng họ mono qua MỘT lớp dùng chung.
 */
class PrototypeCleanupTest extends TestCase
{
    private function src(string $rel): string
    {
        $p = resource_path('js/studio/'.$rel);
        $this->assertFileExists($p, 'Thiếu tệp nguồn: '.$rel);

        return (string) file_get_contents($p);
    }

    private function code(string $s): string
    {
        $s = (string) preg_replace('#<!--.*?-->#s', '', $s);
        $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);

        return (string) preg_replace('#(?<!:)//[^\n]*#', '', $s);
    }

    // ── A. MỘT VIỆC — MỘT LỐI VÀO ──────────────────────────────────────────────

    /**
     * STUDIO ĐIỆN THOẠI: mỗi hành động chỉ có MỘT lối vào trên màn.
     *
     * ĐO ĐƯỢC trước khi sửa: nút «Tác vụ ảnh — tất cả» mở danh sách 8 việc, trong đó 5 việc đã nằm NGAY
     * TRÊN MÀN (CTA «Tạo biến thể AI» + 4 chip) ⇒ cùng một việc có hai lối vào trên một màn hình.
     */
    public function test_studio_dien_thoai_moi_viec_mot_loi_vao(): void
    {
        $phone = $this->code($this->src('components/StudioPhone.vue'));
        $actions = $this->code($this->src('components/PhoneActions.vue'));

        // 5 việc đã có mặt trên màn ⇒ KHÔNG được lặp lại trong sheet.
        foreach (["go('variant')", "go('upscale')", 'download', 'share', '/bo-suu-tap'] as $dup) {
            $this->assertStringNotContainsString($dup, $this->optionsBlock($actions),
                'Sheet «Việc khác» còn lặp lại một việc đã có trên màn Studio ('.$dup.').');
        }

        // Ba việc KHÔNG có trên màn thì PHẢI còn trong sheet.
        $options = $this->optionsBlock($actions);
        foreach (["go('reframe')", "go('delete')", 'goEdit'] as $keep) {
            $this->assertStringContainsString($keep, $options, 'Sheet thiếu việc '.$keep.' — không được bỏ cả lối vào.');
        }

        // Trên màn: đúng 4 chip + 1 CTA (prototype), và nhãn nút mở sheet nói rõ nó KHÔNG còn là 'tất cả'.
        $this->assertSame(4, substr_count($phone, 'data-phone-quick='), 'Studio phải có ĐÚNG 4 lối tắt như prototype.');
        $this->assertStringContainsString('Việc khác — sửa ảnh · đổi khung · xoá', $phone,
            'Nhãn cũ «Tác vụ ảnh — tất cả» nay SAI: danh sách đã thu hẹp còn 3 việc.');
    }

    /** Bóc đúng khối CẤP 1 (options) của PhoneActions để kiểm việc lặp. */
    private function optionsBlock(string $actions): string
    {
        $start = strpos($actions, "v-if=\"level === 'options'\"");
        $this->assertNotFalse($start, 'Không tìm thấy cấp options trong PhoneActions.');
        $end = strpos($actions, "v-else-if=\"level === 'variant'\"", (int) $start);
        $this->assertNotFalse($end, 'Không tìm thấy mốc kết thúc cấp options.');

        return substr($actions, (int) $start, (int) $end - (int) $start);
    }

    /** TRANG CHỦ: bỏ hàng lối khác — bốn đích đó đã có lối vào khác ngay trên màn / trên thanh lệnh. */
    public function test_trang_chu_khong_con_hang_loi_khac(): void
    {
        $home = $this->code($this->src('HomeApp.vue'));

        $this->assertStringNotContainsString('Lối khác', $home, 'Hàng «Lối khác» đã được gỡ (trùng với menu Không gian + thẻ Radar).');
        // Đúng MỘT lối tới Agent: thẻ Radar. (Trước đây hàng «Lối khác» là lối thứ hai, cộng menu Không gian.)
        $this->assertSame(1, substr_count($home, 'href="/agent-studio"'),
            'Trang chủ phải có ĐÚNG một lối tới Agent (thẻ Radar) — thêm hàng lối khác là lối thứ hai.');
        // Nhưng lối vào vẫn phải tồn tại — không phải gỡ rồi để ngõ cụt.
        $this->assertStringContainsString('/studio?panel=concept', $home);
        $this->assertStringContainsString('/studio?view=library', $home, 'Lối vào Thư viện phải còn (qua «Xem tất cả»).');
    }

    /** HUB: credit chỉ hiện ở MỘT chỗ (thẻ tài khoản có hạn mức + thanh tiến trình). */
    public function test_hub_credit_chi_hien_mot_cho(): void
    {
        $hub = $this->code($this->src('SettingsHubApp.vue'));

        // Kiểm theo VÙNG, không theo số lần xuất hiện: credit phải NẰM TRONG thẻ tài khoản và KHÔNG
        // được nằm trong thanh tiêu đề. (Hai nhánh v-if/v-else trong thẻ là loại trừ nhau — vẫn là MỘT
        // chỗ hiện; đếm chuỗi thô sẽ không phân biệt được điều đó.)
        $headerStart = strpos($hub, '<header');
        $headerEnd = strpos($hub, '</header>', (int) $headerStart);
        $this->assertNotFalse($headerStart); $this->assertNotFalse($headerEnd);
        $header = substr($hub, (int) $headerStart, (int) $headerEnd - (int) $headerStart);
        $this->assertStringNotContainsString('creditsLabel', $header,
            'Thanh tiêu đề Hub không được hiện credit nữa — thẻ tài khoản ngay dưới đã có credit KÈM hạn mức.');

        $accountStart = strpos($hub, 'data-hub-account');
        $this->assertNotFalse($accountStart, 'Thiếu thẻ tài khoản (data-hub-account).');
        $this->assertStringContainsString('data-hub-credits', $hub);
        $this->assertStringContainsString('creditsLabel', substr($hub, (int) $accountStart),
            'Credit phải hiện trong thẻ tài khoản.');
    }

    // ── B. ĐÚNG URL ───────────────────────────────────────────────────────────

    /**
     * MỌI LIÊN KẾT NỘI Bộ trong giao diện phải phân giải được thành route GET có thật.
     *
     * Đây là lưới an toàn cho câu hỏi "nút này gọi đúng URL chưa": một href gõ sai chỉ lộ ra khi có
     * người bấm đúng nút đó, ở đúng trạng thái đó — quá muộn. Bài test quét MỌI tệp Vue/Blade.
     */
    public function test_moi_lien_ket_noi_bo_phan_giai_duoc(): void
    {
        $files = array_merge(
            glob(resource_path('js/studio/*.vue')) ?: [],
            glob(resource_path('js/studio/components/*.vue')) ?: [],
            glob(resource_path('js/studio/pages/*.vue')) ?: [],
            glob(resource_path('js/studio/components/settings/*.vue')) ?: [],
            glob(resource_path('views/**/*.blade.php')) ?: [],
            glob(resource_path('views/*.blade.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        // Tiền tố KHÔNG phải route trang (tệp tĩnh · API · liên kết do máy chủ sinh).
        $staticPrefixes = ['/build/', '/storage/', '/icons/', '/images/', '/samples/', '/api/', '/prototype', '/up'];

        $broken = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            // href="/..." (bỏ qua href động {{ }} · :href · mailto · http)
            preg_match_all('/href="(\/[^"{}$]*)"/', $src, $m);
            foreach ($m[1] ?? [] as $path) {
                $clean = strtok($path, '?#');
                if ($this->isStaticOrRoute($clean, $staticPrefixes)) {
                    continue;
                }
                $broken[] = $rel.' → href="'.$path.'"';
            }

            // window.location.href = '/...' và location.assign('/...')
            preg_match_all("/location(?:\.href)?\s*=\s*'(\/[^']*)'|location\.assign\('(\/[^']*)'\)/", $src, $m2, PREG_SET_ORDER);
            foreach ($m2 as $set) {
                $path = $set[1] !== '' ? $set[1] : $set[2];
                $clean = strtok($path, '?#');
                if ($this->isStaticOrRoute($clean, $staticPrefixes)) {
                    continue;
                }
                $broken[] = $rel.' → location = \''.$path.'\'';
            }
        }

        $this->assertSame([], array_values(array_unique($broken)),
            "Có liên kết nội bộ KHÔNG phân giải được thành route GET — bấm vào là 404:\n".implode("\n", $broken));
    }

    private function isStaticOrRoute(string $path, array $staticPrefixes): bool
    {
        foreach ($staticPrefixes as $p) {
            if (str_starts_with($path, $p)) {
                return true;
            }
        }

        return Route::has(ltrim($path, '/')) || $this->matchesAnyGetUri($path);
    }

    /** Route của app khai bằng URI (không phải name) nên phải so với danh sách URI GET thật. */
    private function matchesAnyGetUri(string $path): bool
    {
        $needle = trim($path, '/');
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = trim($route->uri(), '/');
            if ($uri === $needle) {
                return true;
            }
            // URI có tham số ({project}) thì khớp theo đoạn đầu.
            $prefix = strstr($uri, '{', true);
            if ($prefix !== false && $prefix !== '' && str_starts_with($needle.'/', $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * KHÔNG dùng ĐƯỜNG CŨ khi đã có đường chính thức.
     *
     * /presets · /model-settings · /stylist-data vẫn chạy (giữ cho bookmark cũ) nhưng chúng là ALIAS của
     * các mục trong khu Cài đặt — nút trong app phải dùng /cai-dat/presets · /cai-dat/model ·
     * /cai-dat/stylist, nếu không thì hai nút cùng đích mang hai URL khác nhau, và lần sau đổi khu Cài
     * đặt là phải đi sửa từng nút.
     */
    public function test_giao_dien_khong_dung_duong_cu_cua_khu_cai_dat(): void
    {
        $legacy = ['/presets', '/model-settings', '/stylist-data'];
        $offenders = [];

        $files = array_merge(
            glob(resource_path('js/studio/*.vue')) ?: [],
            glob(resource_path('js/studio/components/*.vue')) ?: [],
            glob(resource_path('js/studio/pages/*.vue')) ?: [],
            glob(resource_path('views/**/*.blade.php')) ?: [],
            glob(resource_path('views/*.blade.php')) ?: [],
        );

        foreach ($files as $file) {
            $code = $this->code((string) file_get_contents($file));
            foreach ($legacy as $path) {
                foreach (['href="'.$path.'"', "location.href = '".$path."'", "location.assign('".$path."'"] as $needle) {
                    if (str_contains($code, $needle)) {
                        $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).' → '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "Giao diện còn dùng đường CŨ của khu Cài đặt (dùng /cai-dat/... — xem SettingsAreas):\n".implode("\n", $offenders));

        // Và các đường chính thức phải thật sự tồn tại.
        foreach (['/cai-dat', '/cai-dat/presets', '/cai-dat/model', '/cai-dat/stylist'] as $p) {
            $this->assertTrue($this->matchesAnyGetUri($p), 'Thiếu route chính thức: '.$p);
        }
    }

    // ── C. FONT THEO PROTOTYPE ────────────────────────────────────────────────

    public function test_ba_ho_chu_theo_prototype(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("--font-sans: 'Inter'", $css, 'Thiếu họ chữ giao diện (Inter).');
        $this->assertStringContainsString("--font-display: 'Fraunces'", $css,
            'Chữ TIÊU ĐỀ phải là Fraunces như prototype (prototype/css/tokens.css: --f-display).');
        $this->assertStringContainsString("--font-mono: 'Space Grotesk'", $css,
            'Thiếu họ chữ MONO cho nhãn nhỏ in hoa (prototype: --f-mono · .micro).');

        // Nạp font: cả ba họ phải được khai ở vite (nếu không, token chỉ là tên suông).
        $vite = (string) file_get_contents(base_path('vite.config.js'));
        foreach (['Inter', 'Fraunces', 'Space Grotesk'] as $family) {
            $this->assertStringContainsString("bunny('".$family."'", $vite, 'vite.config.js thiếu họ chữ '.$family.'.');
        }

        // Nhãn nhỏ in hoa dùng MỘT lớp dùng chung (không rải font-mono khắp nơi).
        $this->assertStringContainsString('.micro-label {', $css);
        $this->assertStringContainsString('font-family: var(--font-mono);', $css);
        $this->assertStringContainsString('.micro-label-accent {', $css);

        // Các màn theo prototype dùng lớp đó (và KHÔNG tự viết lại chuỗi tiện ích cũ).
        foreach (['HomeApp.vue', 'components/OnboardingSlides.vue', 'components/StudioPhone.vue', 'components/TriageDeck.vue'] as $rel) {
            $src = $this->src($rel);
            $this->assertStringContainsString('micro-label', $src, $rel.' chưa dùng lớp nhãn nhỏ dùng chung.');
        }
    }
}