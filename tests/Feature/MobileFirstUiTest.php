<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * MOBILE-FIRST (đợt 52, 2026-09-26) — khoá những gì ĐO ĐƯỢC trên Chrome thật ở 320/375px.
 *
 * Mỗi bất biến ở đây ứng với một số đo cụ thể, không phải một ý thích:
 *   · card lưới có 4 nút chữ nhỏ cạnh nhau trong ~150px ⇒ ô chạm không ô nào đủ to;
 *   · thanh trượt cao 16px, ô đánh dấu 14x14px ⇒ dưới sàn chạm;
 *   · lưới ảnh nhận thumbnail 160px cho ô rộng tới 320px ⇒ nhòe đúng chỗ nhìn kỹ nhất;
 *   · mặt lưới vẫn phơi nút của canvas (hoàn tác/zoom/nền/bắt điểm/số lớp);
 *   · dải ảnh trong trình xem nằm TRONG khung ảnh, đè lên ảnh và chồng thanh thu/phóng.
 */
class MobileFirstUiTest extends TestCase
{
    private function src(string $rel): string
    {
        $p = resource_path('js/studio/'.$rel);
        $this->assertFileExists($p, 'Thiếu tệp nguồn: '.$rel);

        return (string) file_get_contents($p);
    }

    /**
     * Bóc chú thích để chỉ kiểm MÃ SỐNG — gồm CẢ chú thích HTML trong template.
     *
     * Vì sao phải bóc: các tệp ở đây ghi lại lịch sử ngay tại chỗ ("trước đây dải ảnh nằm trong
     * khung ảnh ở absolute bottom-14", "trước đây tab này luôn hiện"). Không bóc thì bài test cấm
     * chính tài liệu của nó — đã mắc đúng lỗi này hai lần trong đợt 51 và 52.
     */
    private function code(string $s): string
    {
        $s = (string) preg_replace('#<!--.*?-->#s', '', $s);
        $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);

        return (string) preg_replace('#(?<!:)//[^\n]*#', '', $s);
    }

    // ── 1. CỠ THUMBNAIL THEO TỪNG CHỖ ──────────────────────────────────────────

    public function test_luoi_chinh_tra_thumbnail_theo_dung_be_rong_o(): void
    {
        $grid = $this->src('components/ResultGrid.vue');

        // srcset nhiều cỡ để trình duyệt tự chọn theo bề rộng thật + DPR.
        $this->assertStringContainsString(':srcset="gridSrcset(g.media_url)"', $grid);
        $this->assertStringContainsString(':sizes="GRID_SIZES"', $grid);

        // Bốn cỡ PHẢI khớp whitelist cứng của backend (StudioController::studioImageThumb).
        $this->assertStringContainsString('const THUMB_SIZES = [160, 320, 480, 640];', $grid,
            'srcset chỉ được trỏ vào bốn cỡ backend thật sự tạo (160|320|480|640).');

        // sizes phải khai ĐÚNG breakpoint của lưới, nếu không trình duyệt tải sai cỡ.
        foreach (['1536px', '1280px', '640px'] as $bp) {
            $this->assertStringContainsString($bp, $grid, 'sizes thiếu breakpoint '.$bp.' của lưới.');
        }
    }

    /**
     * Ô XEM TRƯỚC NHỎ KHÔNG ĐƯỢC NẠP ẢNH GỐC.
     *
     * ĐO ĐƯỢC: các card nạp thẳng store.upscaleSrc (ảnh 2K-4K) vào một ô 56-64px. Trên điện thoại
     * mỗi lần mở card là một lần tải ảnh lớn cho một ô vuông nhỏ.
     */
    public function test_o_xem_truoc_nho_dung_thumbnail_khong_nap_anh_goc(): void
    {
        $cases = [
            'components/UpscaleCard.vue' => 'store.upscaleSrc',
            'components/SuggestCard.vue' => 'store.upscaleSrc',
            'components/DirectorCard.vue' => 'store.upscaleSrc',
            'components/InpaintCard.vue' => 'activeImg',
            'components/RefImageCard.vue' => 'img',
        ];

        foreach ($cases as $file => $bind) {
            $code = $this->code($this->src($file));
            $this->assertStringContainsString(
                'thumbUrl('.$bind.')',
                $code,
                $file.': ô xem trước nhỏ phải đi qua thumbUrl(), không nạp '.$bind.' nguyên cỡ.'
            );
            $this->assertStringContainsString(
                "useStudioThumb.js",
                $code,
                $file.': thiếu import thumbUrl từ composable dùng chung.'
            );
        }
    }

    // ── 2. SÀN CHẠM CHO Ô ĐIỀU KHIỂN KHÔNG PHẢI NÚT ────────────────────────────

    public function test_thanh_truot_va_o_danh_dau_theo_san_cham(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("body input[type='range'] {", $css,
            'Thiếu luật sàn chạm cho thanh trượt (đo được: cao 16px ở 320px).');
        $this->assertStringContainsString('min-height: 2.75rem;', $css,
            'Thanh trượt phải cao 44px trên màn hẹp.');

        $this->assertStringContainsString("body input[type='checkbox'],", $css,
            'Thiếu luật cho ô đánh dấu (đo được: 14x14px).');
        $this->assertStringContainsString("body label:has(input[type='checkbox'])", $css,
            'Vùng chạm thật của ô đánh dấu là NHÃN, không phải ô vuông — phải nới cả nhãn.');

        // Liên kết tự vẽ kiểu nút: opt-in tường minh, KHÔNG nới luật cho mọi thẻ <a>.
        $this->assertStringContainsString('body .touch-target {', $css);
        $this->assertStringContainsString('touch-target', $this->src('components/StudioCard.vue'),
            'Nút «Sửa chip» là <a> tự vẽ kiểu (cao 24px) nên phải mang lớp .touch-target.');
    }

    // ── 3. MẶT LƯỚI KHÔNG CÒN LÀ GIAO DIỆN CANVAS ─────────────────────────────

    /**
     * MẶT LƯỚI KHÔNG ĐƯỢC CÒN MỘT MẢNH CHROME NÀO CỦA CANVAS.
     *
     * [đợt 53] Đợt 52 tôi ẩn TỪNG NHÓM của thanh trạng thái khi ở mặt lưới và giữ lại nút đổi mặt.
     * Nhưng giữ nút đó ở đây nghĩa là mặt lưới vẫn nuôi cả thanh 40px. Nay nút đổi mặt lên thanh tiêu
     * đề, và MỌI mảnh chrome của canvas ẩn/hiện theo MẶT, ở cấp gốc:
     *   · thanh trạng thái (CanvasStatusBar) — cấp gốc của chính component;
     *   · rail công cụ ngữ cảnh h-12 (ContextToolbar) — data-canvas-toolbar;
     *   · nút Outputs trên thanh tiêu đề — dock Outputs trùng với chính lưới.
     * Kiểm ở cấp gốc thay vì kiểm từng nhóm bên trong: bớt được một tầng điều kiện, tức là bớt chỗ
     * cho một nhóm lọt lưới như lần trước.
     */
    public function test_moi_manh_chrome_canvas_deu_an_theo_mat(): void
    {
        $bar = $this->src('components/CanvasStatusBar.vue');
        $app = $this->code($this->src('StudioApp.vue'));

        // 1. Thanh trạng thái: ẩn + inert ở cấp gốc, có dấu hiệu để tầng kiểm thử trình duyệt tìm được.
        $this->assertStringContainsString('data-canvas-status', $bar);
        $this->assertStringContainsString('v-show="store.mainView === \'canvas\'"', $bar);
        $this->assertStringContainsString(':inert="store.mainView === \'canvas\' ? null : true"', $bar);

        // 2. Rail công cụ ngữ cảnh: cùng một luật.
        $this->assertStringContainsString('data-canvas-toolbar', $app);
        $this->assertStringContainsString(':inert="store.mainView === \'canvas\' ? null : true"', $app);

        // 3. Nút Outputs trên thanh tiêu đề: dock của nó là bản sao thu nhỏ của chính lưới.
        $this->assertMatchesRegularExpression(
            '/data-header-action="outputs"[^>]*>|v-show="store\.mainView === .canvas."[^>]*data-header-action="outputs"/',
            $app
        );
        $i = strpos($app, 'data-header-action="outputs"');
        $tag = substr($app, max(0, $i - 500), 520);
        $this->assertStringContainsString('v-show="store.mainView === \'canvas\'"', $tag,
            'Nút Outputs phải ẩn ở mặt lưới — lưới đã chính là danh sách ảnh đã tạo.');

        // 4. Nút đổi mặt KHÔNG còn trong thanh trạng thái (nó đã lên thanh tiêu đề), và ở thanh tiêu
        //    đề nó là MỘT công tắc — không phải khối hai nút (đo được ở 320px: khối hai nút rộng 86px
        //    và đẩy nút «Bộ sưu tập» ra ngoài mép phải thanh tiêu đề).
        $this->assertStringNotContainsString('data-main-view-switch', $bar);
        $appCode = $this->code($this->src('StudioApp.vue'));
        $this->assertSame(1, substr_count($appCode, 'data-main-view-switch'),
            'Đổi mặt là MỘT công tắc, không phải hai nút — hai nút làm tràn thanh tiêu đề ở 320px.');
    }

    public function test_o_mat_luoi_thi_khong_con_tab_ket_qua_trung_lap(): void
    {
        $app = $this->src('StudioApp.vue');

        // Tab "Kết quả" của dock điện thoại ẩn theo mặt (v-show + inert, KHÔNG v-if — luật MainViewTest).
        // So khớp CHUỖI TƯỜNG MINH thay vì regex nhiều tầng: đọc ra là biết ngay nó đòi gì.
        $this->assertStringContainsString(
            'data-dock-tab="outputs" v-show="store.mainView === \'canvas\'"',
            $app,
            'Tab Kết quả trùng với chính mặt lưới ⇒ phải ẩn khi đang ở mặt lưới (bằng v-show, không v-if).'
        );
        $this->assertStringContainsString(
            ':inert="store.mainView === \'canvas\' ? null : true"',
            $app,
            'Ẩn bằng v-show thì phải inert — nếu không Tab vẫn nhảy vào tab vô hình.'
        );
        // Và nút "Kết quả" thứ hai trên thanh tiêu đề (cùng mở một ngăn kéo) đã bị gỡ.
        // Nhắm ĐÚNG nút đã gỡ (title + aria-label của nó), không nhắm chuỗi con — ngăn kéo vẫn còn
        // aria-label="Kết quả tạo ảnh" và đó là chuyện khác.
        $this->assertStringNotContainsString('title="Kết quả" aria-label="Kết quả"', $app,
            'Hai nút mở cùng một ngăn kéo là trùng lặp — nút trên thanh tiêu đề đã gỡ.');
        $this->assertStringNotContainsString('class="order-6 icon-btn shrink-0 lg:hidden"', $app,
            'Nút Kết quả cũ trên thanh tiêu đề điện thoại phải không còn.');
    }

    /**
     * LƯỚI CÓ BỘ LỌC TRẠNG THÁI — và bộ lọc KHÔNG được nói dối về chính nó.
     *
     * [đợt 53] Trước đây một lượt tạo 8-12 ảnh nằm lẫn trong lưới, ảnh đang chạy không có cách nào
     * tách ra: chip «N đang tạo» nói CÓ bao nhiêu nhưng không chỉ RA ô nào.
     *
     * Bất biến quan trọng nhất ở đây không phải "có bộ lọc", mà là HAI TRẠNG THÁI RỖNG PHẢI KHÁC NHAU:
     * lọc ra rỗng thì đừng mời "tạo ảnh đầu tiên" với người đã có 120 ảnh.
     */
    public function test_luoi_co_bo_loc_trang_thai_va_hai_trang_thai_rong_khac_nhau(): void
    {
        $grid = $this->code($this->src('components/ResultGrid.vue'));

        foreach (["id: 'all'", "id: 'running'", "id: 'done'", "id: 'failed'"] as $f) {
            $this->assertStringContainsString($f, $grid, 'Bộ lọc thiếu mục '.$f.'.');
        }

        // Đếm trên chip tính từ danh sách ĐẦY ĐỦ, không tính từ danh sách đã lọc.
        $this->assertStringContainsString('const all = computed(() => store.visibleGenerations || [])', $grid);
        $this->assertStringContainsString('out[f.id] = all.value.filter(f.test).length', $grid,
            'Số trên chip phải tính từ danh sách ĐẦY ĐỦ — nếu không con số tự nói dối về chính nó.');
        $this->assertStringContainsString('const pending = computed(() => filterCounts.value.running)', $grid,
            'Chip «đang tạo» là sự thật của CẢ LƯỚI, không của bộ lọc đang bật.');

        // Lọc ở LOCAL, không đụng kho dữ liệu (visibleGenerations có 6 nơi khác đang đọc).
        $this->assertStringNotContainsString('store.visibleGenerations =', $grid,
            'Bộ lọc màn hình không được ghi ngược vào kho dữ liệu.');

        // HAI trạng thái rỗng khác nhau, và trạng thái "lọc ra rỗng" phải có lối thoát.
        $this->assertStringContainsString('const filteredEmpty = computed(() => !items.value.length && all.value.length > 0)', $grid);
        $this->assertStringContainsString('v-else-if="filteredEmpty"', $grid,
            'Lọc ra rỗng phải có nhánh riêng — không được rơi vào màn «Chưa có ảnh nào».');
        $this->assertStringContainsString('data-output-filter-clear', $grid,
            'Màn «lọc ra rỗng» phải có nút bỏ lọc — nếu không người dùng mắc kẹt trong lưới trống.');
        $this->assertStringContainsString('Xem tất cả', $grid);
    }

    /**
     * KHỐI "CANVAS TRỐNG" PHẢI CHỪA CHỖ CHO NÚT NỔI TRỢ LÝ.
     *
     * ĐO ĐƯỢC ở 320x640: nút nổi (ChatFab: bottom-32 + nút 48px) chiếm 128-176px ở đáy vùng canvas;
     * hàng nút cuối của khối này chồng lên nó 2 cặp (9x6 và 7x34 px). Nội dung khối cao ~300px trong
     * vùng ~500px, thiếu đúng ~24px.
     */
    public function test_khoi_canvas_trong_chua_cho_nut_noi_tro_ly(): void
    {
        $empty = $this->src('components/CanvasEmptyState.vue');

        $this->assertStringContainsString('pb-52', $empty,
            'Khối canvas trống phải chừa vùng nút nổi ở đáy (176px) trước khi canh giữa nội dung.');
        $this->assertStringContainsString('min-h-full', $empty,
            'Phải canh giữa trong phần KHÔNG gian còn lại, không phải trong cả khung.');
        $this->assertStringContainsString('hidden h-12 w-12 place-items-center rounded-full bg-brand-600/15 text-brand-300 sm:grid', $empty,
            'Hình minh hoạ phải ẩn dưới sm — ở 320px nó là 60px làm hàng nút chồng lên nút nổi.');
    }

    // ── 4. TRÌNH XEM ẢNH LÀ TRUNG TÂM ĐIỀU PHỐI ────────────────────────────────

    public function test_dai_anh_ra_ngoai_khung_anh_va_an_tren_dien_thoai(): void
    {
        $v = $this->src('components/GalleryModal.vue');

        // Dải ảnh là CỘT riêng, chỉ hiện từ lg, KHÔNG nằm trong khung ảnh nữa.
        $this->assertStringContainsString('aria-label="Chọn ảnh khác"', $v);
        $this->assertMatchesRegularExpression(
            '/v-show="items\.length > 1"[\s\S]{0,400}?hidden w-\[72px\][\s\S]{0,200}?lg:flex/',
            $v,
            'Dải ảnh phải là cột 72px chỉ hiện từ lg (điện thoại ẩn — bề ngang là thứ đắt nhất ở đó).'
        );
        // Không còn dải nào neo trong khung ảnh. Kiểm MÃ SỐNG: chú thích ở đầu tệp ghi lại lịch sử
        // ("trước đây nằm trong khung ảnh ở absolute bottom-14") nên kiểm cả tệp là cấm chính tài liệu.
        $this->assertStringNotContainsString('absolute bottom-14', $this->code($v),
            'Dải ảnh cũ nằm TRONG khung ảnh (bottom-14) và chồng thanh thu/phóng — phải không còn.');

        // Mũi tên chuyển ảnh neo vào KHUNG ẢNH, không neo theo hộp thoại (nếu không sẽ đè lên cột dải ảnh).
        $this->assertStringContainsString('aria-label="Ảnh trước', $v);
        $this->assertDoesNotMatchRegularExpression(
            '/rounded-full bg-ink-900\/90 text-xl text-cream-100 transition hover:bg-brand-600 sm:h-10/',
            $v,
            'Mũi tên ‹ › cũ neo theo lớp phủ (đè lên cột dải ảnh sau khi dải ra ngoài).'
        );
    }

    public function test_thong_tin_anh_va_thong_tin_ky_thuat_mac_dinh_an(): void
    {
        $v = $this->src('components/GalleryModal.vue');

        $this->assertStringContainsString('const fieldsOpen = ref(false)', $v,
            'Thông tin ảnh phải ĐÓNG sẵn — người mở trình xem là để LÀM gì đó với ảnh.');
        $this->assertStringContainsString('const techOpen = ref(false)', $v,
            'Model/nhà cung cấp phải nằm sau một tầng nữa, cũng đóng sẵn.');

        // Model/provider KHÔNG được nằm trong lưới thông tin mặc định.
        $info = substr($v, strpos($v, 'const fields = ['), strpos($v, 'const techFields = [') - strpos($v, 'const fields = ['));
        $this->assertStringNotContainsString("'Model'", $info, 'Model không được ở lưới thông tin mặc định.');
        $this->assertStringNotContainsString("'Provider'", $info, 'Provider không được ở lưới thông tin mặc định.');
        $this->assertStringContainsString("k: 'model'", $v, 'Model phải được chuyển sang nhóm Kỹ thuật, không phải xoá.');
    }

    public function test_trinh_xem_dieu_phoi_qua_dung_kenh_va_danh_sach_sinh_tu_cau_hinh(): void
    {
        $v = $this->src('components/GalleryModal.vue');
        $app = $this->src('StudioApp.vue');

        $this->assertStringContainsString('store.requestActivity(id)', $v,
            'Điều phối phải đi qua kênh có sẵn, không dựng kênh thứ hai.');
        $this->assertStringContainsString('defineProps({', $v);
        $this->assertStringContainsString('actions: { type: Array', $v,
            'Danh sách tính năng phải nhận qua PROP — không giữ bản sao thứ hai của cấu hình owner.');

        $this->assertStringContainsString(':actions="viewerActions"', $app);
        $this->assertStringContainsString('const viewerActions = computed', $app);
        // Suy ra từ activityNav (đã lọc theo cấu hình owner + gói cước), không khai mảng mới.
        $this->assertMatchesRegularExpression('/viewerActions = computed\(\(\) =>[\s\S]{0,200}?activityNav\.value/', $app,
            'viewerActions phải suy ra từ activityNav, nếu không owner đổi nhãn ở /admin là lệch.');
    }

    /** Nhóm bị KHOÁ theo gói phải được kênh điều phối xử lý — nếu không, vào thẳng bảng của module chưa trả tiền. */
    public function test_kenh_dieu_phoi_chan_nhom_bi_khoa(): void
    {
        $app = $this->src('StudioApp.vue');
        $code = $this->code($app);

        $this->assertMatchesRegularExpression(
            '/function revealActivity\(id\)\s*\{[\s\S]{0,900}?locked\)\s*\{\s*openUpgradeFor\(id\);\s*return;/',
            $code,
            'revealActivity phải mở hộp thoại nâng cấp khi nhóm đích bị khoá theo gói.'
        );
    }
}
