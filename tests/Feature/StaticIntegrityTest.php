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

    private function rel(string $abs): string
    {
        return str_replace(base_path().'/', '', $abs);
    }
}
