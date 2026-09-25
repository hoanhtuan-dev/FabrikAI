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

        /* [Đợt 64] SÁU lối tắt (2×3) + 1 CTA, và KHÔNG còn nút «Việc khác» trên màn chính.
           Prototype gốc có 4 ô; nay thêm «Sửa ảnh» và «Đổi khung» — hai việc trước đây nằm sau nút
           «Việc khác» đã gỡ. Điều bất biến giữ nguyên: MỖI VIỆC ĐÚNG MỘT LỐI VÀO (không ô nào trùng ô nào). */
        $this->assertSame(6, substr_count($phone, 'data-phone-quick='), 'Studio có ĐÚNG 6 lối tắt, mỗi ô một việc.');
        $this->assertStringNotContainsString('data-phone-actions-door', $phone,
            'Nút «Việc khác» trên màn chính đã gỡ: ba việc của nó đã có chỗ đúng hơn.');
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
    }

    /**
     * TRANG CHỦ — CHẠM MỘT ẢNH LÀ XEM ẢNH ĐÓ (lỗi người dùng báo, đợt 63).
     *
     * Trước đây cả dải «Gần đây» là `<a href="/studio?view=library">`: chạm vào một TẤM ẢNH lại nhảy
     * sang màn THƯ VIỆN. Một tấm ảnh hứa "xem tôi", không hứa "mở danh sách". Bài test khoá cả hai vế:
     * mỗi ảnh đi tới ĐÚNG ảnh đó và mở TRÌNH XEM; còn «Xem tất cả» tới LƯỚI KẾT QUẢ (nơi duy nhất để
     * duyệt ảnh AI) — KHÔNG còn trỏ vào Thư viện.
     */
    public function test_trang_chu_cham_anh_la_mo_trinh_xem(): void
    {
        $home = $this->code($this->src('HomeApp.vue'));

        $this->assertStringContainsString("'/studio?id=' + g.id + '&open=viewer'", $home,
            'Mỗi ảnh gần đây phải mở ĐÚNG ảnh đó trong TRÌNH XEM, không phải chuyển sang màn khác.');
        $this->assertStringNotContainsString("href=\"/studio?view=library\"", $home,
            'Trang chủ không được trỏ vào Thư viện để xem ảnh AI — Thư viện nay là tệp tải lên & prompt.');
        $this->assertStringContainsString('href="/studio?open=results"', $home,
            '«Xem tất cả» phải mở LƯỚI KẾT QUẢ (màn duy nhất để xem/duyệt ảnh AI).');

        // StudioApp phải HIỂU hai tham số đó (nếu không thì liên kết chỉ là ước muốn).
        $app = $this->code($this->src('StudioApp.vue'));
        $this->assertStringContainsString("params.get('open') === 'viewer'", $app);
        $this->assertStringContainsString('store.openViewer(store.preview)', $app);
        $this->assertStringContainsString("params.get('open') === 'results'", $app);
        $this->assertStringContainsString('PHONE_NAV.RESULTS', $app, 'Nhánh điện thoại phải mở màn Kết quả qua hằng số dùng chung.');
        $this->assertStringContainsString('PHONE_NAV.RESULTS', $this->code($this->src('components/StudioPhone.vue')));
    }

    /**
     * MỘT MÀN XEM ẢNH — Thư viện KHÔNG còn tab «Ảnh đã tạo» (đợt 63).
     *
     * Hai màn cùng liệt kê ảnh AI là hai chỗ để lệch nhau; nay lưới Kết quả là màn duy nhất, còn Thư
     * viện giữ đúng thứ nó có mà lưới không có: FILE tải lên và PROMPT đã lưu. Chế độ QUẢN LÝ vẫn còn
     * nhưng chỉ mở từ Kết quả.
     */
    public function test_mot_man_xem_anh_duy_nhat(): void
    {
        $lib = $this->code($this->src('LibraryApp.vue'));

        $this->assertStringNotContainsString("switchTab('generations')", $lib,
            'Tab «Ảnh đã tạo» phải được gỡ: ảnh AI xem ở lưới Kết quả, không ở Thư viện.');
        $this->assertStringContainsString("switchTab('uploads')", $lib);
        $this->assertStringContainsString("switchTab('suggest')", $lib);
        $this->assertStringContainsString('data-library-manage-note', $lib,
            'Vào chế độ quản lý phải có dòng nhắc nói rõ đang ở đâu + lối «Về Kết quả».');

        // Cửa vào chế độ quản lý nằm ở lưới Kết quả (cả hai nhánh), không ở Thư viện.
        $this->assertStringContainsString('data-phone-results-manage', $this->code($this->src('components/StudioPhone.vue')));
        $this->assertStringContainsString('data-header-action="manage"', $this->code($this->src('StudioApp.vue')));
        $this->assertStringContainsString("store.libraryTab = 'generations'", $this->code($this->src('components/StudioPhone.vue')));
    }

    /**
     * TRÌNH XEM ẢNH CÓ LUỒNG — không còn bức tường nút (đợt 63).
     *
     * ĐO ĐƯỢC trước khi sửa: panel trình xem có **21 nút** trải phẳng. Nay: hàng nút CHÍNH đúng 3
     * («Sửa ảnh» · «Tải» · «Việc khác»), phần còn lại nằm sau nút «Việc khác» có đếm số việc.
     */
    public function test_trinh_xem_co_luong_khong_buc_tuong_nut(): void
    {
        $viewer = $this->code($this->src('components/GalleryModal.vue'));

        $this->assertStringContainsString('data-viewer-primary', $viewer);
        foreach (['edit', 'download'] as $a) {
            $this->assertStringContainsString('data-viewer-primary-action="'.$a.'"', $viewer, 'Thiếu nút chính '.$a.'.');
        }
        $this->assertStringContainsString('data-viewer-more', $viewer, 'Thiếu nút «Việc khác» (nhóm phụ).');
        $this->assertStringContainsString('const moreOpen = ref(false)', $viewer,
            'Nhóm phụ phải ĐÓNG sẵn — mở ra là mặc định thấy 3 nút chính, không phải 21.');
        $this->assertStringContainsString('moreCount', $viewer, 'Nút «Việc khác» phải nói trước có bao nhiêu việc.');
        $this->assertStringContainsString('const moreCount = computed(() => (props.actions?.length || 0) + 4)', $viewer,
            'Số việc phải ĐẾM từ danh sách thật (props.actions), không viết cứng.');

        // Nút chính «Sửa ảnh» đi qua ĐÚNG kênh điều phối (không tự mở panel).
        $this->assertStringContainsString("@click=\"dispatch('inpaint')\"", $viewer);
        /* ⚠️ TRONG SCRIPT phải là `props.actions`, không phải `actions`.
           Đã trúng lỗi này: `actions.value.length` trong script gây `ReferenceError: actions is not
           defined` NGAY LÚC RENDER — template thấy prop trực tiếp, script thì không. Triệu chứng nhìn
           thấy chỉ là "trình xem thiếu nút", còn lỗi nằm ở console. Bài này khoá đúng chỗ đó. */
        $script = substr($viewer, 0, (int) strpos($viewer, '<template>'));
        $this->assertStringNotContainsString('actions.value', $script,
            'Trong <script> phải dùng props.actions — `actions` chỉ tồn tại trong template.');
        $this->assertStringContainsString('props.actions?.length', $script);

        // Đếm nút: phần chính không được phình lại thành bức tường.
        // Cắt ở ĐẦU thẻ <button> của nút «Việc khác», không phải ở thuộc tính data-viewer-more của nó —
        // thuộc tính nằm TRONG thẻ, nên cắt theo thuộc tính là tính luôn nút thứ ba (đã trúng bẫy này).
        $primaryStart = strpos($viewer, 'data-viewer-primary');
        $moreStart = strpos($viewer, 'data-viewer-more');
        $moreBtnStart = strrpos(substr($viewer, 0, (int) $moreStart), '<button');
        $band = substr($viewer, (int) $primaryStart, (int) $moreBtnStart - (int) $primaryStart);
        $this->assertSame(2, substr_count($band, '<button'),
            'Hàng nút CHÍNH chỉ có 2 nút (Sửa · Tải); nút thứ ba là «Việc khác» mở nhóm phụ.');
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