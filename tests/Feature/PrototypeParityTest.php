<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ĐỐI CHIẾU PROTOTYPE 2026 — khoá những gì đã port (đợt 60 · 2026-09-26).
 *
 * Tài liệu đối chiếu: `docs/PROTOTYPE_2026_DOI_CHIEU.md`. Bản prototype (đã duyệt) nằm ở
 * `prototype/` trong repo và cũng được phục vụ tại `/prototype`.
 *
 * Tệp này giữ BA nhóm bất biến, mỗi nhóm ứng với một việc chủ dự án yêu cầu trực tiếp:
 *   A. NÚT ĐIỀU HƯỚNG VỀ TRANG CHỦ («Tạo») trong Studio — ở CẢ HAI nhánh.
 *   B. MÀN CÒN THIẾU: 3 slide chào mừng cho khách chưa đăng nhập (prototype `#/onboarding`).
 *   C. GUI CHƯA ĐÚNG: Trang chủ (4 intent · chuông · radar) và Studio điện thoại (CTA có giá credit ·
 *      lối tắt 2×2 · danh sách ảnh điều khiển được) — cùng luật "hai lối vào, MỘT bản logic".
 */
class PrototypeParityTest extends TestCase
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

    // ── A. NÚT VỀ TRANG CHỦ ────────────────────────────────────────────────────

    /**
     * Studio phải có nút «về Trang chủ» ở CẢ HAI nhánh, và nó phải là LIÊN KẾT THẬT (`<a href="/">`),
     * không phải một hàm JS điều hướng: như vậy mở tab mới / sao chép liên kết vẫn đúng.
     */
    public function test_studio_co_nut_ve_trang_chu_o_ca_hai_nhanh(): void
    {
        $phone = $this->code($this->src('components/StudioPhone.vue'));
        $app = $this->code($this->src('StudioApp.vue'));

        // Nhánh điện thoại: hàng đầu của prototype (← về Tạo · nhãn ngữ cảnh · hành động phải).
        $this->assertStringContainsString('data-phone-home', $phone);
        $this->assertMatchesRegularExpression('/<a\s+href="\/"\s+class="icon-btn[^"]*"[\s\S]{0,200}?data-phone-home/s', $phone,
            'Nút về Trang chủ trên điện thoại phải là <a href="/"> — không phải nút gọi JS.');
        $this->assertStringContainsString('aria-label="Về Trang chủ', $phone, 'Nút chỉ có icon phải có aria-label (§8).');

        // Nhánh màn rộng: nút «về» riêng, không phải nằm trong menu Không gian.
        $this->assertStringContainsString('data-header-home', $app);
        $this->assertMatchesRegularExpression('/<a href="\/" class="order-1[^"]*"[^>]*data-header-home/s', $app,
            'Nút về Trang chủ ở thanh tiêu đề (màn rộng) phải là liên kết thật và ở cấp gốc của header.');
    }

    // ── B. MÀN CÒN THIẾU: CHÀO MỪNG ────────────────────────────────────────────

    public function test_khach_chua_dang_nhap_thay_man_chao_mung_ba_slide(): void
    {
        $home = $this->src('HomeApp.vue');
        $ob = $this->src('components/OnboardingSlides.vue');

        // Trang chủ render màn chào cho khách (thay thẻ chào hai dòng cũ).
        $this->assertStringContainsString("import OnboardingSlides from './components/OnboardingSlides.vue'", $home);
        $this->assertStringContainsString('<OnboardingSlides v-if="!user" />', $home);
        $this->assertStringNotContainsString('Xưởng thiết kế thời trang AI — concept, lookbook và tech pack trong một chạm', $home,
            'Thẻ chào hai dòng cũ đã được thay bằng màn 3 slide.');

        // Ba slide + chấm vị trí + nút Tiếp/Bắt đầu + lối đăng nhập cho người quay lại.
        $this->assertSame(3, substr_count($ob, 'title:'), 'Phải có ĐÚNG 3 slide như prototype.');
        $this->assertStringContainsString('data-onboarding-dot', $ob);
        $this->assertStringContainsString('data-onboarding-next', $ob);
        $this->assertStringContainsString("last ? 'Bắt đầu' : 'Tiếp'", $ob);
        $this->assertStringContainsString("window.location.href = '/dang-nhap'", $ob);
        $this->assertStringContainsString('@pointerup="up"', $ob, 'Vuốt ngang phải hoạt động (prototype vuốt để đổi slide).');
        $this->assertStringContainsString('href="/dang-nhap"', $ob, 'Người đã có tài khoản phải có lối vào một chạm.');
        // Không ảnh giả: màn chào KHÔNG được nhúng ảnh/placeholder vải như prototype demo.
        $this->assertStringNotContainsString('<img', $ob, 'Không dùng ảnh giả ở màn chào — sản phẩm này bán ảnh thật.');
    }

    // ── C. GUI CHƯA ĐÚNG ───────────────────────────────────────────────────────

    public function test_trang_chu_bon_intent_chuong_va_radar(): void
    {
        $home = $this->code($this->src('HomeApp.vue'));

        // 4 intent theo prototype, đi thẳng vào công cụ qua ?panel= (một từ vựng với các trang khác).
        foreach (["label: 'Concept'", "label: 'Photoshoot'", "label: 'Lookbook'", "label: 'Tech pack'"] as $l) {
            $this->assertStringContainsString($l, $home, 'Thiếu intent '.$l.' của prototype.');
        }
        $this->assertStringContainsString('/studio?panel=concept', $home);
        $this->assertStringContainsString('/studio?panel=compose', $home);
        $this->assertStringContainsString('/studio?panel=outfit', $home);
        $this->assertStringNotContainsString("label: 'Agent'", $home, 'Intent không còn là điều hướng trộn vào việc (§ prototype).');

        // Chuông: dữ liệu THẬT, mọi trạng thái (không lọc media_url như rail).
        $this->assertStringContainsString('data-home-bell', $home);
        $this->assertStringContainsString("fetch('/api/latest'", $home);
        $this->assertStringContainsString('STATUS_LABEL', $home, 'Nhãn trạng thái phải có — không in mã trạng thái thô.');

        // Radar: chỉ hiện khi CÓ dữ liệu thật từ sổ nguồn của agent.
        $this->assertStringContainsString("fetch('/api/design-agent/findings?limit=1'", $home);
        $this->assertStringContainsString('data-home-radar', $home);
        $this->assertMatchesRegularExpression('/v-if="radar"/', $home, 'Thẻ radar phải ẩn khi không có dữ liệu — thẻ rỗng là thẻ nói dối.');
    }

    public function test_studio_dien_thoai_theo_prototype(): void
    {
        $phone = $this->code($this->src('components/StudioPhone.vue'));

        // CTA chính có GIÁ CREDIT (prototype: «Tạo biến thể AI · 20 credit»; luật 10 §0).
        $this->assertStringContainsString('data-phone-primary', $phone);
        $this->assertStringContainsString('imageCost', $phone);
        $this->assertStringContainsString("store.planCostImage", $phone);

        // Nhãn ngữ cảnh + tag tỉ lệ đọc từ ẢNH THẬT (không viết cứng như prototype demo).
        $this->assertStringContainsString('data-phone-context', $phone);
        $this->assertStringContainsString('onPreviewLoad', $phone);
        $this->assertStringContainsString('naturalWidth', $phone);

        /* SÁU lối tắt (2×3) — đợt 64: «Sửa ảnh» và «Đổi khung» được ĐƯA LÊN ĐÂY khỏi nút «Việc khác»
           (đã gỡ), vì đó là hai việc chính với một tấm ảnh. Mỗi việc vẫn ĐÚNG một lối vào. */
        foreach (['edit', 'upscale', 'reframe', 'download', 'share', 'techpack'] as $q) {
            $this->assertStringContainsString('data-phone-quick="'.$q.'"', $phone, 'Thiếu lối tắt '.$q.'.');
        }
        $this->assertSame(6, substr_count($phone, 'data-phone-quick='), 'Đúng 6 lối tắt — không thêm ô nào trùng việc.');
        $this->assertStringNotContainsString('data-phone-actions-door', $phone,
            'Nút «Việc khác» trên màn chính đã gỡ: sửa ảnh + đổi khung thành lối tắt, xoá nằm trong trình xem.');

        /* [Đợt 64 — ĐẢO QUYẾT ĐỊNH] KHÔNG còn khối «Lớp» trên điện thoại.
           Yêu cầu trực tiếp: "bỏ các tính năng xếp lớp và scale trên điện thoại". Trước đó tôi đã thêm
           khối này theo prototype; nay gỡ vì đo được nó VÔ NGHĨA TẠI CHỖ: bảng ghép không tồn tại trên
           điện thoại (§15.6), nên kéo thanh độ mờ hay bấm con mắt KHÔNG đổi gì trên màn hình. */
        $this->assertStringNotContainsString('data-phone-layer-eye', $phone, 'Điện thoại không còn điều khiển lớp.');
        $this->assertStringNotContainsString('data-phone-layer-opacity', $phone);
        $this->assertStringNotContainsString('store.toggleLayerVisible', $phone);
        $this->assertStringNotContainsString('store.setLayerOpacity', $phone);
        $this->assertStringNotContainsString('data-phone-layers', $phone, 'Không còn danh sách lớp trên điện thoại.');
    }

    /**
     * HAI LỐI VÀO — MỘT BẢN LOGIC.
     *
     * Hàng chip ở màn Studio và sheet «Tác vụ ảnh» cùng gọi biến thể/nâng cấp/đổi khung/tải/chia sẻ.
     * Bản sao thứ hai là chỗ chắc chắn lệch: sửa endpoint một nơi, nơi kia gọi đường cũ.
     */
    public function test_hai_loi_vao_hanh_dong_dung_mot_ban_logic(): void
    {
        $comp = $this->code($this->src('composables/useImageActions.js'));
        $phone = $this->code($this->src('components/StudioPhone.vue'));
        $actions = $this->code($this->src('components/PhoneActions.vue'));

        foreach (["'/api/upscale'", "'/api/reframe'"] as $endpoint) {
            $this->assertStringContainsString($endpoint, $comp, 'Composable thiếu lời gọi '.$endpoint.'.');
        }
        $this->assertStringContainsString('store.refgen(', $comp);

        foreach (['useImageActions'] as $needle) {
            $this->assertStringContainsString($needle, $phone, 'Studio điện thoại phải dùng composable dùng chung.');
            $this->assertStringContainsString($needle, $actions, 'Sheet Tác vụ ảnh phải dùng composable dùng chung.');
        }

        // Không nơi nào trong hai component còn tự gọi endpoint của hành động đã tách.
        foreach ([[$phone, 'StudioPhone'], [$actions, 'PhoneActions']] as [$src, $name]) {
            $this->assertStringNotContainsString("'/api/upscale'", $src, $name.' còn tự gọi /api/upscale — phải đi qua composable.');
            $this->assertStringNotContainsString("'/api/reframe'", $src, $name.' còn tự gọi /api/reframe — phải đi qua composable.');
        }
    }

    public function test_hub_co_thanh_credit_va_dang_xuat(): void
    {
        $hub = $this->code($this->src('SettingsHubApp.vue'));

        $this->assertStringContainsString('data-hub-account', $hub);
        $this->assertStringContainsString("fetch('/api/plan/status'", $hub, 'Thẻ credit phải đọc ĐÚNG endpoint bảng Gói & credit đang dùng.');
        $this->assertStringContainsString('credits_per_month', $hub);
        $this->assertStringContainsString('data-hub-logout', $hub);
        $this->assertStringContainsString("'/dang-xuat'", $hub, 'Đăng xuất phải đi qua route thật của Laravel.');
    }

    /** Deep-link `?panel=` phải tới được nhánh ĐANG render (điện thoại ⇄ màn rộng). */
    public function test_deep_link_panel_toi_dung_nhanh(): void
    {
        $app = $this->code($this->src('StudioApp.vue'));

        $this->assertStringContainsString("const panel = params.get('panel')", $app);
        $this->assertStringContainsString('if (isPhone.value) phoneToolRequest.value', $app,
            'Trên điện thoại, ?panel= phải đi sang StudioPhone — nhánh đó không render bảng trái.');
    }

    // ── D. MÀN CHI TIẾT BỘ SƯU TẬP (prototype #/collection/:id) ────────────────

    /**
     * Màn chi tiết bộ sưu tập — màn CUỐI trong bảng đối chiếu còn thiếu.
     *
     * Bốn bất biến, mỗi cái ứng với một quyết định đã ghi trong mã:
     *   · có ĐƯỜNG DẪN THẬT + route trả về được khi F5 (không phải một modal không link được);
     *   · ảnh lấy qua ĐÚNG nguồn `store.loadProjectShots` (không thêm endpoint đọc mới);
     *   · chip lọc dựng từ vòng đời ảnh THẬT (`shot_state`) và chỉ hiện bước đang có ảnh;
     *   · chạm ảnh mở TRÌNH XEM dùng chung với ngữ cảnh là ảnh của bộ này.
     */
    public function test_man_chi_tiet_bo_suu_tap(): void
    {
        $page = $this->code($this->src('pages/CollectionsPage.vue'));

        // 1. Mở/đóng bằng URL thật + back của trình duyệt.
        $this->assertStringContainsString('data-collection-open', $page, 'Thẻ bộ sưu tập phải có lối vào màn chi tiết.');
        $this->assertStringContainsString('data-collection-detail', $page);
        $this->assertStringContainsString("history.pushState({ collection: Number(p.id) }, '', '/bo-suu-tap/' + p.id)", $page,
            'Mở màn chi tiết phải ghi URL — nếu không, gửi link cho đồng nghiệp là mở lại từ danh sách.');
        $this->assertStringContainsString("addEventListener('popstate', onDetailPop)", $page,
            'Nút back phải đóng màn chi tiết (bám URL), không văng khỏi trang.');
        $this->assertStringContainsString('onDetailPop()', $page, 'F5 hoặc mở link trực tiếp phải vào đúng bộ.');

        // 2. Route phía máy chủ trả về được cùng một view (SPA tự đọc pathname).
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString("Route::get('/bo-suu-tap/{project}'", $routes);
        $this->assertStringContainsString("->whereNumber('project')", $routes,
            'Chỉ nhận id số — không mở một route bắt mọi chuỗi dưới /bo-suu-tap.');

        // 3. Nguồn ảnh + chip lọc + trình xem.
        $this->assertStringContainsString('store.loadProjectShots(', $page);
        $this->assertStringContainsString('data-collection-chip', $page);
        $this->assertStringContainsString('WORKFLOW_STEPS.filter((s) => counts[s.state])', $page,
            'Chip lọc chỉ hiện bước ĐANG CÓ ảnh — chip 0 ảnh là chip vô nghĩa.');
        $this->assertStringContainsString('store.openViewer(', $page);
        $this->assertStringContainsString("import GalleryModal from '../components/GalleryModal.vue'", $page,
            'Phải dùng TRÌNH XEM dùng chung, không dựng trình xem thứ hai.');
        // Dạng dữ liệu THẬT của ảnh-theo-bộ là `thumb` (không phải `media_url`) — chỗ dễ sai nhất.
        $this->assertStringContainsString('s.thumb || s.media_url', $page,
            'Ảnh của bộ dùng khoá `thumb`; quên lớp chuyển đổi là lưới ảnh trắng và chạm không mở gì.');
    }

    /** Tài liệu đối chiếu phải tồn tại và nói đúng tên các màn của prototype. */
    public function test_tai_lieu_doi_chieu_prototype(): void
    {
        $path = base_path('docs/PROTOTYPE_2026_DOI_CHIEU.md');
        $this->assertFileExists($path, 'Thiếu tài liệu đối chiếu prototype.');
        $doc = (string) file_get_contents($path);

        foreach (['onboarding', 'studio', 'review', 'collections', 'collection/:id', 'pricing', 'hub'] as $screen) {
            $this->assertStringContainsString($screen, $doc, 'Tài liệu đối chiếu thiếu màn '.$screen.'.');
        }
        $this->assertStringContainsString('đã loại bỏ canvas', mb_strtolower($doc), 'Tài liệu phải ghi rõ quyết định bỏ canvas.');
        $this->assertFileExists(base_path('prototype/index.html'), 'Prototype phải nằm trong repo (nguồn của bản đối chiếu).');
    }
}
