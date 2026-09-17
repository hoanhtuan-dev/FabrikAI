<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Kiểm tra TÍNH TOÀN VẸN TĨNH của app — biến việc rà tay một lần thành rào chắn thường trực.
 *
 * Lý do có file này: đợt tách app đã để lại các tham chiếu hỏng (route trỏ method không tồn tại,
 * tiền tố URL cũ, kiểu trả về mất dấu '\\'). Chúng chỉ lộ ra khi tình cờ đọc/gọi đúng chỗ. Bộ test
 * này kiểm các bất biến có thể xác minh cơ học, để lần sau không phải rà lại bằng mắt.
 *
 * Đã kiểm bằng script một lần và tất cả đều SẠCH (vòng 16) — file này giữ nguyên trạng thái đó.
 */
class StaticIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_route_action_resolves_to_existing_class_and_method(): void
    {
        $broken = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;   // closure / redirect / view route
            }
            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class)) {
                $broken[] = $route->methods()[0].' '.$route->uri()." -> class không tồn tại: {$class}";
                continue;
            }
            if (! method_exists($class, $method)) {
                $broken[] = $route->methods()[0].' '.$route->uri()." -> method không tồn tại: {$class}@{$method}";
            }
        }

        $this->assertSame([], $broken, "Route trỏ tới class/method không tồn tại:\n".implode("\n", $broken));
    }

    public function test_no_duplicate_route_names(): void
    {
        $seen = [];
        $dups = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name) {
                continue;
            }
            if (isset($seen[$name])) {
                $dups[] = $name.' ('.$seen[$name].' vs '.$route->uri().')';
            }
            $seen[$name] = $route->uri();
        }

        $this->assertSame([], $dups, "Route name bị trùng:\n".implode("\n", $dups));
    }

    public function test_every_referenced_view_file_exists(): void
    {
        $broken = [];

        foreach ($this->sourceFiles(['php']) as $file) {
            preg_match_all("/view\(\s*'([a-zA-Z0-9_.\-]+)'/", (string) file_get_contents($file), $m);
            foreach (array_unique($m[1]) as $view) {
                if (str_contains($view, '::')) {
                    continue;   // view namespace (package)
                }
                $path = resource_path('views/'.str_replace('.', '/', $view).'.blade.php');
                if (! is_file($path)) {
                    $broken[] = $this->rel($file)." -> view('{$view}')";
                }
            }
        }

        $this->assertSame([], $broken, "view() không có file blade:\n".implode("\n", $broken));
    }

    public function test_every_blade_vite_entry_exists_on_disk(): void
    {
        $broken = [];

        foreach ($this->bladeFiles() as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all("/@vite\(\s*\[([^\]]+)\]/", $src, $m);
            foreach ($m[1] as $list) {
                preg_match_all("/'([^']+)'/", $list, $mm);
                foreach ($mm[1] as $entry) {
                    if (! is_file(base_path($entry))) {
                        $broken[] = $this->rel($file)." -> @vite {$entry}";
                    }
                }
            }
        }

        $this->assertSame([], $broken, "@vite trỏ tới file không tồn tại:\n".implode("\n", $broken));
    }

    public function test_static_assets_referenced_in_blades_exist(): void
    {
        $broken = [];

        foreach ($this->bladeFiles() as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all('#(?:href|src)="(/[a-zA-Z0-9_./\-]+\.(?:png|jpg|jpeg|svg|ico|webp|json|js|css|mp4))"#', $src, $m);
            foreach (array_unique($m[1]) as $asset) {
                if (! is_file(public_path(ltrim($asset, '/')))) {
                    $broken[] = $this->rel($file)." -> {$asset}";
                }
            }
        }

        $this->assertSame([], $broken, "Asset tĩnh trong blade không có trên disk:\n".implode("\n", $broken));
    }

    public function test_route_references_resolve_to_registered_names(): void
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if ($name = $route->getName()) {
                $names[] = $name;
            }
        }

        $broken = [];
        // Quét cả blade: `{{ route('login.store') }}` là chỗ dùng route() phổ biến thứ hai sau controller.
        foreach (array_merge($this->sourceFiles(['php']), $this->bladeFiles()) as $file) {
            $rel = $this->rel($file);
            preg_match_all("/route\(\s*'([a-zA-Z0-9_.\-]+)'/", (string) file_get_contents($file), $m);
            foreach (array_unique($m[1]) as $name) {
                if (in_array($name, $names, true)) {
                    continue;
                }
                if (in_array($rel.'|'.$name, self::DEAD_ROUTE_REFS, true)) {
                    continue;
                }
                $broken[] = "{$rel} -> route('{$name}')";
            }
        }

        $this->assertSame([], $broken,
            "route('...') trỏ tới route name KHÔNG đăng ký — gọi tới sẽ ném RouteNotFoundException (500):\n"
            .implode("\n", $broken)
            ."\n\nNếu đây là dead code mới: xoá nó, hoặc khai vào DEAD_ROUTE_REFS kèm lý do."
        );
    }

    /**
     * RỖNG — và phải giữ nguyên như vậy.
     *
     * Trước đây có 9 mục ở đây: tàn dư storefront (CustomPage · MenuItem · CartService · Seo ·
     * menu_items()) trỏ tới các route thuộc nhóm shop / product / blog không hề tồn tại. Khi đó chúng được
     * khai báo tường minh vì đã xác minh là KHÔNG reachable.
     *
     * Toàn bộ module thương mại điện tử đã được GỠ khỏi app (2026-09-17) nên 9 tham chiếu đó biến mất
     * cùng nó. Danh sách này chỉ được phép NGẮN ĐI; thêm mục mới nghĩa là vừa có dead code mới lọt vào
     * (hoặc một route sống bị xoá mà vẫn còn người gọi) — cả hai đều phải sửa, không phải khai báo.
     */
    private const DEAD_ROUTE_REFS = [];

    public function test_storefront_module_is_gone_for_good(): void
    {
        // Bất biến NGƯỢC với trước đây: module thương mại điện tử phải ở yên trong quá khứ.
        // Nếu một trong các tên này quay lại app/Http, routes/ hoặc resources/views thì hoặc là
        // code chết vừa được nối lại, hoặc ai đó đang port storefront ngược vào app studio.
        $symbols = ['CustomPage', 'MenuItem', 'CartService', 'CheckoutService', 'App\\Support\\Seo',
                    'menu_items(', 'seo()', 'cart_count(', 'App\\Models\\Product', 'App\\Models\\Order'];
        $liveDirs = ['app/Http', 'routes', 'resources/views'];

        $leaks = [];
        foreach ($liveDirs as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $f) {
                if (! $f->isFile()) {
                    continue;
                }
                $src = (string) file_get_contents($f->getPathname());
                foreach ($symbols as $sym) {
                    if (str_contains($src, $sym)) {
                        $leaks[] = $this->rel($f->getPathname()).' -> '.$sym;
                    }
                }
            }
        }

        $this->assertSame([], $leaks,
            "Tàn dư storefront bị nối vào đường chạy SỐNG (sẽ kéo theo route() hỏng):\n".implode("\n", $leaks)
        );
    }

    public function test_spa_entries_all_boot_through_the_shared_module(): void
    {
        // Lớp bug đã tái diễn nhiều lần trong repo: bản vá áp ở MỘT chỗ rồi bỏ quên chỗ tương đương.
        // Cụ thể ở đây: `main.js` gỡ service worker cũ + guard element gốc, còn `settings.js` /
        // `presets.js` / `stylist-data.js` thì không — nên vào thẳng /settings vẫn bị SW cũ phục vụ
        // asset cũ, và blade thiếu element thì lỗi mount khó đọc.
        //
        // Bất biến: việc khởi động SPA chỉ được định nghĩa ở ĐÚNG MỘT file (pageBoot.js).
        $entries = ['main.js', 'settings.js', 'presets.js', 'stylist-data.js', 'admin.js', 'model-settings.js'];
        $jsFiles = $this->jsFiles();
        $violations = [];

        foreach ($jsFiles as $file) {
            $src = (string) file_get_contents($file);
            $rel = $this->rel($file);

            if (str_contains($src, 'serviceWorker') && $rel !== 'resources/js/studio/pageBoot.js') {
                $violations[] = "{$rel} tự gỡ service worker thay vì dùng pageBoot.js";
            }
            if (str_contains($src, '.mount(') && $rel !== 'resources/js/studio/pageBoot.js') {
                $violations[] = "{$rel} tự mount thay vì dùng mountGuarded() của pageBoot.js";
            }
        }

        foreach ($entries as $entry) {
            $rel = 'resources/js/studio/'.$entry;
            if (! is_file(base_path($rel))) {
                $violations[] = "thiếu entry {$rel}";
                continue;
            }
            $src = (string) file_get_contents(base_path($rel));
            if (! str_contains($src, "from './pageBoot.js'")) {
                $violations[] = "{$rel} không import './pageBoot.js'";
            }
            foreach (['killLegacyServiceWorker', 'mountGuarded'] as $fn) {
                if (! str_contains($src, $fn.'(')) {
                    $violations[] = "{$rel} không gọi {$fn}()";
                }
            }
        }

        $this->assertSame([], $violations,
            "Entry SPA lệch chuẩn khởi động:\n".implode("\n", $violations)
        );
    }

    public function test_full_screen_overlays_declare_their_role(): void
    {
        // §5.2/§5.3: vá a11y (`role="dialog"` + `aria-modal`) trước đây chỉ áp ở BaseModal.vue và
        // ProjectWorkspace.vue; 12 overlay toàn màn hình khác bị bỏ sót — trình đọc màn hình không
        // biết đó là hộp thoại và vẫn đọc nội dung phía sau lớp phủ.
        //
        // Bất biến: mọi lớp phủ `fixed inset-0` CÓ nội dung đều phải khai `role`.
        // Miễn trừ hợp lệ (không phải hộp thoại):
        //   • backdrop chỉ để bắt cú click ra ngoài (thẻ rỗng, hoặc có @pointerdown)
        //   • dòng chú thích
        $violations = [];

        foreach ($this->vueFiles() as $file) {
            $rel = $this->rel($file);
            $src = (string) file_get_contents($file);

            // Bỏ chú thích trước khi tách thẻ: dòng mô tả "fixed inset-0" trong chú thích không phải markup.
            $src = preg_replace([
                '/<!--.*?-->/s',
                '/\{\{--.*?--\}\}/s',
                '/\/\*.*?\*\//s',
                '/^[ \t]*\/\/.*$/m',
            ], '', $src);

            // Tách THẺ THẬT (không phải từng dòng) — thẻ có thể trải nhiều dòng, và cách soi "cửa sổ
            // lân cận" đã bị chứng minh là quá lỏng: một overlay KHÔNG role đặt ngay trước một thẻ có
            // role sẽ được miễn trừ oan (mutation-test phát hiện).
            preg_match_all('/<[a-zA-Z][^>]*>/s', (string) $src, $m, PREG_OFFSET_CAPTURE);

            foreach ($m[0] as [$tag, $offset]) {
                if (! str_contains($tag, 'fixed inset-0')) {
                    continue;
                }
                if (str_contains($tag, '@pointerdown')) {
                    continue;   // backdrop của menu: chỉ bắt pointerdown để đóng popup
                }
                $after = substr((string) $src, $offset + strlen($tag), 8);
                if (str_starts_with($after, '</div>')) {
                    continue;   // backdrop rỗng: chỉ để bắt cú click ra ngoài
                }
                if (! str_contains($tag, 'role=')) {
                    $line = substr_count(substr((string) $src, 0, $offset), "\n") + 1;
                    $violations[] = $rel.':'.$line.' — lớp phủ thiếu role="dialog"';
                }
            }
        }

        $this->assertSame([], $violations,
            "Lớp phủ toàn màn hình thiếu role/aria-modal:\n".implode("\n", $violations)
        );
    }

    public function test_pwa_is_removed_but_stale_service_worker_cleanup_stays(): void
    {
        // T5 (chốt 2026-09-17): PWA gỡ HẲN. Trước đó service worker không bao giờ được đăng ký lại
        // (`main.js` chỉ gỡ) nên `/sw.js` + `/manifest.json` là code chết.
        //
        // Nhưng KHÔNG được gỡ theo `killLegacyServiceWorker()`: xoá file trên máy chủ không tự gỡ
        // service worker đã cài trong trình duyệt người dùng — nếu bỏ hàm đó, người dùng cũ sẽ mãi
        // bị SW cũ phục vụ asset cũ (SPA trắng trang). Test này khoá CẢ HAI chiều.
        foreach (['public_html/sw.js', 'public_html/manifest.json'] as $dead) {
            $this->assertFileDoesNotExist(base_path($dead), "T5: {$dead} phải đã bị xoá");
        }

        $refs = [];
        foreach (['resources/views', 'resources/js', 'routes', 'app', 'config'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $f) {
                if (! $f->isFile()) {
                    continue;
                }
                $src = (string) file_get_contents($f->getPathname());
                // Chỉ tính THAM CHIẾU THẬT: bỏ qua dòng chú thích mô tả việc gỡ.
                foreach (explode("\n", $src) as $line) {
                    if (! preg_match('#/manifest\.json|["\x27]/sw\.js#', $line)) {
                        continue;
                    }
                    if (preg_match('#^\s*(//|\*|/\*|\{\{--|<!--)#', $line)) {
                        continue;
                    }
                    $refs[] = $this->rel($f->getPathname()).' -> '.trim($line);
                }
            }
        }

        $this->assertSame([], $refs, "Còn tham chiếu tới file PWA đã xoá:\n".implode("\n", $refs));

        // Chiều ngược lại: dọn SW cũ VẪN phải còn.
        $boot = (string) file_get_contents(resource_path('js/studio/pageBoot.js'));
        $this->assertStringContainsString('export function killLegacyServiceWorker', $boot,
            'T5: xoá file PWA trên máy chủ KHÔNG thay thế được việc gỡ service worker đã cài ở trình duyệt người dùng.');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return string[] */
    private function sourceFiles(array $exts): array
    {
        $out = [];
        foreach (['app', 'routes', 'config', 'database', 'bootstrap'] as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $f) {
                if ($f->isFile() && in_array($f->getExtension(), $exts, true)) {
                    $out[] = $f->getPathname();
                }
            }
        }

        return $out;
    }

    public function test_mobile_drawer_renders_outputs_sources_and_library(): void
    {
        // [Đợt 0.5 — Mobile dùng được]
        //   Ba lỗi gốc: (a) drawer "Kết quả" trên điện thoại RỖNG — OutputModule đã import nhưng không
        //   render; (b) "Nguồn ảnh"/"Thư viện" chỉ nằm ở rail hidden lg:flex (≥1024px) nên điện thoại
        //   không có cách mở; (c) SourcePanel/LibraryCard import chết (đã bị thay bằng SourcePickerPopup/
        //   LibraryApp) làm người đọc tưởng mobile đang dùng chúng.
        $app = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));

        // (a) OutputModule PHẢI được render bên trong drawer "Kết quả" mobile, không chỉ import.
        $this->assertMatchesRegularExpression('/v-if="outputOpen"[^>]*>.*?<OutputModule \/>/s', $app,
            'Drawer "Kết quả" mobile phải RENDER <OutputModule /> (trước đây rỗng trong suông).');

        // (b) Nguồn ảnh + Thư viện phải mở được từ menu mobile (drawer), không chỉ từ rail desktop.
        $this->assertMatchesRegularExpression('/menuOpen[^>]*>.*?sourcePickerOpen.*?goLibrary/s', $app,
            'Menu mobile phải có "Nguồn ảnh" (sourcePickerOpen) và "Thư viện" (goLibrary).');

        // (c) Không còn import chết SourcePanel / LibraryCard.
        $this->assertStringNotContainsString("import SourcePanel", $app, 'Import chết SourcePanel phải bị gỡ.');
        $this->assertStringNotContainsString("import LibraryCard", $app, 'Import chết LibraryCard phải bị gỡ.');
    }

    public function test_dialogs_trap_focus_instead_of_letting_tab_escape(): void
    {
        // [Đợt 0.7 — focus trap]
        //   role="dialog" + aria-modal đã có từ vá §5.2/§5.3, nhưng KHÔNG lớp phủ nào giữ bàn phím:
        //   bấm Tab vài lần là focus đi xuyên ra sau lớp phủ, Enter kích hoạt nhầm hành động ẩn.
        //   Bất biến: composable useFocusTrap tồn tại, thực sự giữ Tab (vòng lại đầu/cuối) + trả
        //   focus khi đóng + gỡ listener khi unmount; và các hộp thoại NỀN (BaseModal dùng chung,
        //   ProjectWorkspace) phải DÙNG nó.
        $trapFile = resource_path('js/studio/composables/useFocusTrap.js');
        $this->assertFileExists($trapFile, 'Composable useFocusTrap phải tồn tại (Đợt 0.7).');
        $trap = (string) file_get_contents($trapFile);

        foreach (["e.key !== 'Tab'", 'shiftKey', 'last.focus', 'first.focus', 'removeEventListener', 'document.activeElement'] as $needle) {
            $this->assertStringContainsString($needle, $trap,
                "useFocusTrap thiếu logic giữ Tab: '$needle' — bất biến focus trap bị phá.");
        }
        $this->assertStringContainsString('lastFocused.focus', $trap, 'useFocusTrap phải TRẢ focus khi đóng.');

        $baseModal = (string) file_get_contents(resource_path('js/studio/components/BaseModal.vue'));
        $this->assertStringContainsString('useFocusTrap', $baseModal, 'BaseModal (đế chung của các hộp thoại) phải giữ focus.');
        $this->assertStringContainsString('trap.activate', $baseModal, 'BaseModal phải BẬT trap khi mở.');
        $this->assertStringContainsString('trap.deactivate', $baseModal, 'BaseModal phải TẮT trap (trả focus) khi đóng/unmount.');

        $workspace = (string) file_get_contents(resource_path('js/studio/components/ProjectWorkspace.vue'));
        $this->assertStringContainsString('useFocusTrap(dialogEl', $workspace, 'ProjectWorkspace (hộp thoại Dự án) phải giữ focus.');
    }

    public function test_no_pwa_install_ui_remains(): void
    {
        // [Đợt 0.6 — Chốt Q4: bỏ PWA HOÀN TOÀN]
        //   Sau khi gỡ service worker + manifest, hai nút "Cài đặt FabrikAI" (banner dưới + thanh
        //   công cụ) vẫn còn trong StudioApp.vue nhưng KHÔNG BAO GIỜ hiện: không còn gì bắn
        //   `beforeinstallprompt`. Đó là UI chết — người đọc code sau này sẽ tưởng PWA vẫn hoạt động.
        //   Bất biến: không tệp nguồn .vue/.js nào còn tham chiếu tới cơ chế cài đặt PWA.
        $bad = [];
        foreach ($this->vueFiles() as $f) {
            $src = (string) file_get_contents($f);
            foreach (['beforeinstallprompt', 'appinstalled', 'doInstall', 'showInstall', 'installPrompt'] as $needle) {
                if (str_contains($src, $needle)) {
                    $bad[] = str_replace(base_path().'/', '', $f).' → '.$needle;
                }
            }
        }
        $this->assertSame([], $bad,
            "Dư lượng PWA còn sót (chốt Q4 là BỎ PWA hoàn toàn, không để lại nút chết):\n".implode("\n", $bad));
    }

    public function test_generation_progress_is_real_not_simulated(): void
    {
        // [Đợt 0.2 — chống tái phát "thanh tiến trình mô phỏng"]
        //   (a) trước đây generateImage() dùng setInterval cộng ngẫu nhiên 4–12%, khoá 90% rồi API
        //       trả về là ép 100% + "Hoàn tất!" dù ảnh còn 'pending' ⇒ người dùng bị lừa.
        //   (b) generateImage() còn KHÔNG gọi pollGeneration() ⇒ thumbnail kẹt "Đang chờ" tới khi F5.
        $store = (string) file_get_contents(resource_path('js/studio/store.js'));

        // Dùng 'setInterval(' (có ngoặc) để không khớp chính dòng chú thích giải thích lịch sử.
        $this->assertStringNotContainsString('setInterval(', $store,
            'Đợt 0.2: generateImage phải bỏ tiến trình mô phỏng (bộ đếm lặp cộng % ngẫu nhiên).');

        // Thân hàm generateImage() phải THẬT SỰ theo dõi kết quả, không ép 100%.
        if (preg_match('/async generateImage\(\) \{(.*?)\n    \}/s', $store, $m)) {
            $this->assertStringContainsString('pollGeneration', $m[1], 'generateImage phải gọi pollGeneration cho từng ảnh.');
            $this->assertStringContainsString('syncBatchProgress', $m[1], 'generateImage phải cập nhật tiến trình từ trạng thái thật.');
        }

        // syncBatchProgress phải tồn tại và đọc TRẠNG THÁI / tổng số (thước đo khách quan, không ngẫu nhiên).
        $this->assertStringContainsString('syncBatchProgress()', $store);
    }

    public function test_auth_state_flags_are_rendered_not_just_stored(): void
    {
        // [Đợt 0.1 — chống lớp bug "cờ được SET nhưng không ai RENDER"]
        //
        // store.js đặt `needsLogin` ở 5 chỗ, nhưng grep cả resources/js thì cờ đó CHỈ xuất hiện
        // trong store.js — không template nào đọc ⇒ người tự đăng ký nhận 403 và KHÔNG được bảo gì
        // ngoài toast lỗi nguyên văn của backend. Đây là bug "im lặng", không phải bug logic:
        // không test nào bắt được vì mọi thứ vẫn "chạy đúng".
        //
        // Bất biến: trạng thái xác thực của store phải được TIÊU THỤ ở tầng view.
        // `needsLogin` cũ đã bị KHAI TỬ trong bản vá này (thay bằng `authState`), nên nó không
        // còn là trạng thái sống — chỉ còn trong chú thích giải thích lịch sử.
        $store = (string) file_get_contents(resource_path('js/studio/store.js'));
        $views = '';
        foreach ($this->vueFiles() as $f) {
            $views .= (string) file_get_contents($f);
        }

        $this->assertStringContainsString('authState', $store, "store.js phải khai báo trạng thái 'authState'.");
        $this->assertStringContainsString('authState', $views,
            'authState được set trong store.js nhưng KHÔNG template .vue nào render — đúng lớp bug 403 im lặng.');

        // Ba trạng thái phải phân biệt được với người dùng, không gộp thành một thông báo chung.
        $notice = (string) file_get_contents(resource_path('js/studio/components/AuthNotice.vue'));
        foreach (['guest', 'expired', 'unauthorized'] as $state) {
            $this->assertStringContainsString($state, $notice,
                "AuthNotice.vue phải xử lý riêng trạng thái '$state' (chưa đăng nhập ≠ hết phiên ≠ thiếu quyền).");
        }

        // Và thông báo của backend dành cho QUẢN TRỊ không được lộ ra như thể là lỗi của người dùng.
        $this->assertStringNotContainsString('khu vực quản trị', $notice,
            'Banner KHÔNG được lặp lại thông báo "khu vực quản trị" — đó là ngôn ngữ của backend dành cho admin.');
    }

    /** @return string[] */
    private function bladeFiles(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    /** @return string[] */
    private function jsFiles(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'))) as $f) {
            if ($f->isFile() && $f->getExtension() === 'js') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    /** @return string[] */
    private function vueFiles(): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js/studio'))) as $f) {
            if ($f->isFile() && $f->getExtension() === 'vue') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    private function rel(string $abs): string
    {
        return str_replace(base_path().'/', '', $abs);
    }
}
