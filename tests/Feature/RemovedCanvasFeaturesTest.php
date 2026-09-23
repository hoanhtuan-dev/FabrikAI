<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Khoa bat bien: crop (D1) va paint/erase (D2) da bi XOA that.
 *
 * Vi sao can bai nay: xoa UI ma de lai state/action thi lan sau co nguoi
 * "noi lai" bang cach goi thang store.toggleDraw() va tinh nang se song lai
 * ma khong ai biet. Bai nay doc chinh source JS/Vue.
 *
 * LUU Y: chi kiem MA SONG. Cac file co chu thich ghi ro "da bo drawMode ·
 * eraseMode (D2), cropMode · reframeOpen (D1)" — do la tai lieu co y, va
 * minifier cat sach nen bundle that su = 0. Vi vay phai boc chu thich truoc
 * khi kiem, neu khong bai test se cam chinh tai lieu cua no.
 */
class RemovedCanvasFeaturesTest extends TestCase
{
    private function js(string $rel): string
    {
        $p = base_path('resources/js/studio/' . $rel);
        $this->assertFileExists($p, "Thieu file: {$rel}");

        return file_get_contents($p);
    }

    /** Boc chu thich /* ... *\/ va // ... nhung GIU nguyen chuoi (https://). */
    private function code(string $rel): string
    {
        $s = $this->js($rel);
        $s = preg_replace('#/\*.*?\*/#s', '', $s);
        $s = preg_replace('#(?<!:)//[^\n]*#', '', $s);

        return $s;
    }

    private const DRAW = ['drawMode', 'eraseMode', 'toggleDraw', 'toggleErase', 'brushColor'];

    private const CROP = ['cropMode', 'cropStyle', 'confirmCrop', 'cropStart', 'reframeOpen', 'reframeCenter', 'ratioAspect'];

    private const FILES = [
        'store/state.js',
        'store/actions/canvasView.js',
        'StudioApp.vue',
        'components/RegionTools.vue',
        'components/ContextToolbar.vue',
        'components/CanvasStatusBar.vue',
        'components/CollectionsCard.vue',
    ];

    public function test_khong_con_ma_song_nao_ve_hoac_xoa_pixel(): void
    {
        foreach (self::FILES as $f) {
            $s = $this->code($f);

            foreach (self::DRAW as $k) {
                $this->assertStringNotContainsString(
                    $k,
                    $s,
                    "{$f} van con ma song '{$k}' — ve/xoa pixel phai o lai da xoa (D2)."
                );
            }
        }
    }

    public function test_khong_con_ma_song_nao_crop_hoac_reframe(): void
    {
        foreach (self::FILES as $f) {
            $s = $this->code($f);

            foreach (self::CROP as $k) {
                $this->assertStringNotContainsString(
                    $k,
                    $s,
                    "{$f} van con ma song '{$k}' — crop da bi xoa (D1)."
                );
            }
        }
    }

    /** File co ve da bi xoa han, khong phai chi bo goi. */
    public function test_file_brushes_da_bi_xoa(): void
    {
        $this->assertFileDoesNotExist(
            base_path('resources/js/studio/store/actions/brushes.js'),
            'brushes.js phai bi xoa han — exitCanvasTools() da chuyen sang canvasView.js.'
        );
    }

    /** Nhung thu PHAI con: mask (D5) va anh dang lam viec. */
    public function test_mask_va_working_image_van_con(): void
    {
        $this->assertStringContainsString('inpaintMaskMode', $this->code('store/state.js'));
        $this->assertStringContainsString('setWorkingImage', $this->code('store/actions/generation.js'));
        $this->assertStringContainsString('workingImage', $this->code('components/EditImageModal.vue'));
    }

    /** Endpoint bi bo call-site nhung API van phai song (chua duoc yeu cau xoa). */
    public function test_endpoint_reframe_va_look_van_con(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString('reframe', $routes, 'Endpoint /api/reframe phai con.');
        $this->assertStringContainsString('look', $routes, 'Endpoint /api/look phai con.');
    }

    /**
     * Hop dong mask KHONG doi: rect -> mask_mode + region, brush -> mask_data PNG.
     * Dung literal THAT trong file ('#fff' / '#000'), va dung CHIEU:
     * back-end buildMaskImage doc TRANG = giu nguyen, DEN = vung sua.
     */
    public function test_hop_dong_mask_khong_doi(): void
    {
        $modal = $this->code('components/EditImageModal.vue');
        $gen = $this->code('store/actions/generation.js');

        // Man Chinh anh KHONG tu dung payload — no dat state, generation.js dung.
        foreach (['_inpaintMaskKind', 'inpaintMaskBox', 'inpaintBrushData', 'inpaintFeather'] as $k) {
            $this->assertStringContainsString($k, $modal, "Man Chinh anh phai dat '{$k}'.");
        }
        $this->assertStringContainsString("'rect'", $modal, "Che do Khoanh phai bao kind='rect'.");
        $this->assertStringContainsString("'brush'", $modal, "Che do Co phai bao kind='brush'.");

        // generation.js moi la noi dung ba truong wire.
        foreach (['mask_mode', 'mask_data', 'region'] as $k) {
            $this->assertStringContainsString($k, $gen, "generation.js phai gui '{$k}'.");
        }

        $this->assertStringContainsString("'#fff'", $modal, 'Nen mask phai TRANG (giu nguyen).');
        $this->assertStringContainsString("'#000'", $modal, 'Net mask phai DEN (vung sua).');

        // Thu tu: ve net den TRUOC, roi moi do nen trang ra sau bang composite.
        $this->assertLessThan(
            strpos($modal, "octx.fillStyle = '#fff'"),
            strpos($modal, "ctx.fillStyle = '#000'"),
            'Phai ve net DEN truoc khi do nen TRANG.'
        );
    }
}
