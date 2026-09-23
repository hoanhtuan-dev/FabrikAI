<?php

namespace Tests\Feature;

use App\Http\Controllers\StudioController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hop dong MASK cua man "Chinh anh" — khoa o CA HAI dau.
 *
 * Vi sao can: mask gui sai thi KHONG co loi nao ca. Backend chi thay mot anh mask;
 * gui sai chieu (den<->trang) thi model sua DUNG VUNG MUON GIU va tra ve mot anh
 * trong nhu khong co gi xay ra. Vi vay phai khoa bang so do pixel that.
 *
 * Quy uoc (StudioController::buildMaskImage): TRANG = giu nguyen, DEN = vung sua.
 * Moi che do gui DUNG mot dang du lieu:
 *   · rect  -> mask_mode='rect'  + region {x,y,w,h}
 *   · brush -> mask_mode='brush' + mask_data (PNG base64)
 */
class MaskContractTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/public/studio/test-mask');
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    // ── dung cu ────────────────────────────────────────────────────────────────

    /** Anh nguon that 200x200 -> /storage URL ma resolveLocalImage() tim thay. */
    private function source(): string
    {
        $im = imagecreatetruecolor(200, 200);
        imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, 10, 120, 200));
        imagepng($im, $this->dir.'/src.png');
        imagedestroy($im);

        return '/storage/studio/test-mask/src.png';
    }

    /** PNG co: nen TRANG + mot net DEN ngang giua anh (dung quy uoc gui len). */
    private function brushData(int $w = 200, int $h = 200): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 255, 255, 255));
        imagesetthickness($im, 24);
        imageline($im, 20, (int) ($h / 2), $w - 20, (int) ($h / 2), imagecolorallocate($im, 0, 0, 0));
        ob_start();
        imagepng($im);
        $raw = (string) ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,'.base64_encode($raw);
    }

    private function build(string $src, array $region, string $mode, ?string $brush, int $feather = 0): ?string
    {
        $ctrl = app(StudioController::class);
        $m = new \ReflectionMethod($ctrl, 'buildMaskImage');

        return $m->invoke($ctrl, $src, $region, $mode, $brush, $feather);
    }

    /** Doc diem anh (x,y) cua mask da luu. */
    private function pixel(string $maskUrl, int $x, int $y): array
    {
        $path = storage_path('app/public/'.ltrim(str_replace('/storage/', '', $maskUrl), '/'));
        $this->assertFileExists($path, 'Mask phai duoc luu ra storage.');
        $im = imagecreatefrompng($path);
        $c = imagecolorat($im, $x, $y);
        $rgb = ['r' => ($c >> 16) & 0xFF, 'g' => ($c >> 8) & 0xFF, 'b' => $c & 0xFF];
        imagedestroy($im);

        return $rgb;
    }

    // ── CHIEU CUA MASK: TRANG = giu, DEN = sua ─────────────────────────────────

    public function test_mask_co_net_TRANG_o_goc_va_DEN_o_net_co(): void
    {
        $mask = $this->build($this->source(), [], 'brush', $this->brushData());
        $this->assertNotNull($mask, 'Net co phai dung duoc mask.');

        $corner = $this->pixel($mask, 4, 4);
        $this->assertGreaterThan(200, $corner['r'], 'Goc phai TRANG (= giu nguyen).');
        $this->assertGreaterThan(200, $corner['g']);
        $this->assertGreaterThan(200, $corner['b']);

        $stroke = $this->pixel($mask, 100, 100);
        $this->assertLessThan(120, $stroke['r'], 'Net co phai DEN (= vung sua).');
        $this->assertLessThan(120, $stroke['g']);
        $this->assertLessThan(120, $stroke['b']);
    }

    /**
     * MIN da va: truoc day dieu kien dung mask doi PHAI co 'region' cho CA HAI che do,
     * nen mask co chi song nho khung mac dinh 15% trong state.js. Bo khung do di la
     * mask bi nuot am tham (sua toan anh, khong loi). Nay che do Co chi can mask_data.
     */
    public function test_net_co_dung_duoc_mask_KHONG_can_region(): void
    {
        $this->assertNotNull(
            $this->build($this->source(), [], 'brush', $this->brushData()),
            'Che do Co phai dung duoc mask tu mask_data ma KHONG can region.'
        );
    }

    /** Net co hong -> tra null (KHONG mask), khong tra ve tam mask TRANG tron. */
    public function test_net_co_hong_thi_tra_null_chu_khong_tra_mask_trang(): void
    {
        $this->assertNull(
            $this->build($this->source(), ['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2], 'brush', 'data:image/png;base64,@@khong-phai-png@@'),
            'Khong giai ma duoc net co thi phai tra null, khong duoc tra mask trang tron.'
        );
    }

    /** Che do Khoanh van dung hinh chu nhat DEN tu region. */
    public function test_khoanh_dung_hinh_chu_nhat_DEN_tu_region(): void
    {
        $mask = $this->build($this->source(), ['x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5], 'rect', null);
        $this->assertNotNull($mask);

        $inside = $this->pixel($mask, 100, 100);
        $this->assertLessThan(120, $inside['r'], 'Trong khung phai DEN (= vung sua).');

        $outside = $this->pixel($mask, 10, 10);
        $this->assertGreaterThan(200, $outside['r'], 'Ngoai khung phai TRANG (= giu nguyen).');
    }

    // ── PAYLOAD: moi che do gui DUNG mot dang ──────────────────────────────────

    public function test_client_gui_dung_du_lieu_theo_tung_che_do(): void
    {
        $js = file_get_contents(base_path('resources/js/studio/store/actions/generation.js'));

        $this->assertStringContainsString("body.mask_mode = this._inpaintMaskKind", $js);
        $this->assertStringContainsString("if (this._inpaintMaskKind === 'brush')", $js);
        $this->assertStringContainsString('body.mask_data = this.inpaintBrushData', $js);
        $this->assertStringContainsString('body.region = this.inpaintMaskBox', $js);

        // Che do Co KHONG duoc gui kem 'region' (khung mac dinh 15% chi lam sai nghia payload).
        $brushBranch = substr($js, strpos($js, "if (this._inpaintMaskKind === 'brush')"));
        $brushBranch = substr($brushBranch, 0, strpos($brushBranch, '} else {'));
        $this->assertStringNotContainsString(
            'body.region',
            $brushBranch,
            "Nhanh 'brush' KHONG duoc gan body.region — box chi la khung mac dinh."
        );
    }

    public function test_backend_doi_dung_du_lieu_theo_tung_che_do(): void
    {
        $php = file_get_contents(base_path('app/Http/Controllers/StudioController.php'));

        $q = chr(39); // nhay don — tranh noi suy chuoi cua PHP trong chinh bai test

        $this->assertStringContainsString(
            '$maskMode === '.$q.'rect'.$q.' && ! empty($data['.$q.'region'.$q.'])',
            $php,
            "Che do Khoanh phai doi dung 'region'."
        );
        $this->assertStringContainsString(
            '$maskMode === '.$q.'brush'.$q.' && ! empty($data['.$q.'mask_data'.$q.'])',
            $php,
            "Che do Co phai doi dung 'mask_data' (khong doi 'region')."
        );
    }

    /** Quy uoc mau phai duoc ghi ngay tai cho dung mask — de nguoi sau khong dao chieu. */
    public function test_quy_uoc_mau_con_duoc_ghi_ro(): void
    {
        $modal = file_get_contents(base_path('resources/js/studio/components/EditImageModal.vue'));
        $this->assertStringContainsString('NỀN TRẮNG + NÉT ĐEN', $modal);

        $php = file_get_contents(base_path('app/Http/Controllers/StudioController.php'));
        $this->assertStringContainsString('Nền TRẮNG = giữ nguyên', $php);
    }
}
