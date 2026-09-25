<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * STUDIO TRÊN ĐIỆN THOẠI — TÍNH NĂNG GỐC ĐÃ CÓ ĐƯỢC BÙ LẠI (đợt 59 · 2026-09-26).
 *
 * BỐI CẢNH, vì sao có tệp test này: đợt "shell 2026" chuyển Studio sang GUI mới và BỎ CANVAS khỏi
 * điện thoại (Phase 2). Quyết định đó đúng, nhưng bản Phase 2 chỉ để lại ảnh đang làm việc · Tác vụ
 * ảnh · rail kết quả · danh sách lớp · thanh lệnh — nên 9 công cụ của xưởng, trợ lý, lưới kết quả có
 * lọc, nguồn ảnh, thư viện, bộ sưu tập, thông báo… KHÔNG còn lối vào nào trên điện thoại. Người dùng
 * điện thoại mất phần lớn sản phẩm, không phải chỉ mất canvas.
 *
 * Tệp này khoá BA nhóm bất biến, mỗi nhóm ứng với một lỗi ĐO ĐƯỢC trên Chrome thật (390×844):
 *
 *   A. LỚP PHỦ DÙNG CHUNG PHẢI Ở CẤP GỐC. Lỗi thật: toàn bộ khối lớp phủ nằm lọt trong
 *      `<div v-else-if="!booting">` của nhánh màn rộng (thẻ đóng của nó ở dòng CUỐI tệp), nên điện
 *      thoại không có trình xem ảnh · menu không gian · trợ lý · màn Chỉnh ảnh · bộ chọn nguồn ·
 *      bảng bộ sưu tập · trung tâm thông báo · hộp xác nhận xoá. Không exception, không log — chỉ là
 *      bấm mà màn hình đứng yên.
 *   B. MỌI CÔNG CỤ GỐC PHẢI CÓ LỐI VÀO TỪ STUDIOPHONE, và đi qua CÙNG cấu hình owner quản lý
 *      (activityNav · toolbarActions) — không khai lại danh sách công cụ ở bản điện thoại.
 *   C. ĐIỆN THOẠI VẪN KHÔNG CÓ CANVAS, và vẫn theo §7.2 (h-dvh · chừa chỗ cho thanh lệnh · thang
 *      tầng cố định) lẫn §15.7 (Review → Options → Action, lùi được từng cấp).
 */
class PhoneStudioParityTest extends TestCase
{
    private function src(string $rel): string
    {
        $p = resource_path('js/studio/'.$rel);
        $this->assertFileExists($p, 'Thiếu tệp nguồn: '.$rel);

        return (string) file_get_contents($p);
    }

    /** Bóc chú thích — tệp trong repo ghi lịch sử ngay tại chỗ, kiểm cả chú thích là cấm chính tài liệu. */
    private function code(string $s): string
    {
        $s = (string) preg_replace('#<!--.*?-->#s', '', $s);
        $s = (string) preg_replace('#/\*.*?\*/#s', '', $s);

        return (string) preg_replace('#(?<!:)//[^\n]*#', '', $s);
    }

    // ── A. LỚP PHỦ DÙNG CHUNG Ở CẤP GỐC ────────────────────────────────────────

    /**
     * Nhánh màn rộng phải ĐÓNG trước khối lớp phủ dùng chung.
     *
     * Cách kiểm: trong tệp này, node cấp gốc của template được thụt lề ĐÚNG 2 dấu cách (mọi thứ trong
     * nhánh thụt từ 4 trở lên). Đó là quy ước của chính tệp, và nó là thứ duy nhất phân biệt "ở cấp
     * gốc" với "nằm lọt trong nhánh" mà không cần biên dịch Vue. Bài test vì thế kiểm HAI điều:
     *   1. có dấu mốc đóng nhánh màn rộng;
     *   2. mọi lớp phủ dùng chung đứng SAU dấu mốc đó (và ở cấp gốc).
     */
    public function test_lop_phu_dung_chung_nam_ngoai_ca_hai_nhanh(): void
    {
        $app = $this->src('StudioApp.vue');

        $marker = '<!-- /NHÁNH MÀN RỘNG';
        $this->assertStringContainsString($marker, $app,
            'Thiếu dấu mốc đóng nhánh màn rộng — không có nó thì không ai biết khối lớp phủ ở trong hay ngoài nhánh.');
        $cut = (string) strpos($app, $marker);
        $inside = substr($app, 0, (int) $cut);
        $outside = substr($app, (int) $cut);

        // Nhánh màn rộng và nhánh điện thoại là ANH EM ở cấp gốc (v-if / v-else-if trên hai node).
        $this->assertMatchesRegularExpression('/^ {2}<StudioPhone\b/m', $inside);
        $this->assertMatchesRegularExpression('/^ {2}<div v-else-if="!booting"/m', $inside);

        // Mọi lớp phủ dùng chung: cấp gốc (2 dấu cách) VÀ sau dấu mốc.
        // [Đợt 64] `<EditImageModal` ĐÃ RỜI danh sách này: nó không còn là lớp phủ dùng chung mà là
        // BỀ MẶT CHỈNH ẢNH nhúng trong công cụ «Sửa ảnh» (InpaintCard) — xem test riêng bên dưới.
        foreach ([
            '<GalleryModal', '<PopMenu', '<SourcePickerPopup', '<ProjectWorkspace',
            '<ChatModal', '<NotificationCenter', '<AuthNotice',
            '<ConfirmDialog', '<ConceptCard',
        ] as $tag) {
            $this->assertMatchesRegularExpression('/^ {2}'.$tag.'\b/m', $outside,
                $tag.' phải ở CẤP GỐC của template (sau dấu mốc đóng nhánh màn rộng). Nằm lọt trong '
                .'nhánh màn rộng nghĩa là điện thoại KHÔNG có nó — lỗi im lặng của đợt 59.');

            $this->assertStringNotContainsString('  '.$tag, $this->stripRootLevelOnly($inside),
                $tag.' còn nằm trong nhánh màn rộng (cấp gốc trong nhánh = 4 dấu cách).');
        }
    }

    /** Bỏ mọi dòng thụt lề ≥ 4 dấu cách — còn lại là node cấp gốc của nhánh. */
    private function stripRootLevelOnly(string $s): string
    {
        return implode("\n", array_filter(explode("\n", $s), fn ($l) => ! preg_match('/^ {4,}\S/', $l)));
    }

    /** Trình xem ảnh trên điện thoại phải nhận DANH SÁCH TÍNH NĂNG (suy ra từ cấu hình owner). */
    public function test_trinh_xem_anh_tren_dien_thoai_co_danh_sach_tinh_nang(): void
    {
        $app = $this->code($this->src('StudioApp.vue'));
        $this->assertMatchesRegularExpression('/<GalleryModal v-if="store\.viewer" :actions="viewerActions"/', $app,
            'GalleryModal thiếu :actions ⇒ chạm ảnh trên điện thoại chỉ xem được, không làm gì tiếp.');
    }

    // ── B. LỐI VÀO MỌI CÔNG CỤ GỐC ──────────────────────────────────────────────

    /**
     * StudioPhone phải mở được MỌI công cụ của thanh công cụ, và danh sách phải sinh từ cấu hình
     * owner quản lý (prop từ StudioApp) — không giữ bản sao thứ hai.
     */
    public function test_dien_thoai_co_loi_vao_moi_cong_cu(): void
    {
        $phone = $this->code($this->src('components/StudioPhone.vue'));
        $app = $this->code($this->src('StudioApp.vue'));

        // 1. Nhận cấu hình qua PROP (nguồn duy nhất), không khai lại danh sách công cụ.
        foreach (['activityNav', 'toolbarActions', 'settingsEntry'] as $prop) {
            $this->assertStringContainsString($prop.': { type:', $phone, 'StudioPhone thiếu prop '.$prop.'.');
            $kebab = strtolower((string) preg_replace('/([A-Z])/', '-$1', $prop));
            $this->assertStringContainsString(':'.$kebab.'="'.$prop.'"', $app,
                'StudioApp phải truyền '.$prop.' xuống StudioPhone (một nguồn, không bản sao).');
        }

        /* 2. DẢI CÔNG CỤ LÀ LỐI VÀO DUY NHẤT của 9 công cụ (đợt 64).
           Trước đây danh sách công cụ có HAI chỗ: dải công cụ ngay trên màn VÀ lưới 9 công cụ trong
           sheet «Công cụ» — hai lối vào cho cùng một việc. Nay sheet chỉ còn nhóm "đổi không gian /
           nguồn dữ liệu" (Nguồn ảnh · Tệp & nguồn · Bộ sưu tập · Cài đặt · Agent · Tài khoản). */
        $this->assertStringContainsString('data-phone-tool-rail', $phone, 'Dải công cụ là lối vào chính.');
        $this->assertStringContainsString('v-for="a in activityNav"', $phone, 'Dải công cụ sinh từ cấu hình owner.');
        $this->assertStringContainsString("a.locked ? 'lock' : a.icon", $phone,
            'Công cụ bị khoá theo gói vẫn phải HIỆN (kèm ổ khoá) — giấu đi là giấu luôn tính năng khách có thể mua.');
        $this->assertStringNotContainsString('data-phone-tool-list', $phone,
            'Lưới 9 công cụ trong sheet PHẢI được gỡ — nó trùng với dải công cụ.');
        $this->assertStringNotContainsString('data-phone-tool-item', $phone);
        $this->assertStringContainsString('data-phone-more-entries', $phone, 'Sheet «Khác» mở từ dải công cụ.');

        // 3. Chọn công cụ ⇒ màn chiếm trọn render ĐÚNG card của nó (không bản sao công cụ).
        $this->assertStringContainsString('data-phone-tool-panel', $phone);
        $this->assertMatchesRegularExpression(
            '/data-phone-tool-panel[\s\S]{0,400}?<component :is="c" v-for="\(c, i\) in surfaceTool\.cards"/',
            $phone,
            'Màn công cụ phải render chính card của xưởng (cùng component với màn rộng).'
        );

        // 4. Bốn cửa còn lại của sản phẩm, mỗi cửa MỘT chỗ. Cửa đầu nay tên «other» (sheet «Khác»).
        foreach (['other', 'results', 'assistant', 'collections'] as $gate) {
            $this->assertStringContainsString('data-phone-gate="'.$gate.'"', $phone, 'Thiếu cửa '.$gate.'.');
        }
        $this->assertSame(4, substr_count($phone, 'data-phone-gate='), 'Bốn cửa — không thêm cửa thứ năm ở đây (§4: một màn một hành động chính).');

        // 5. Nguồn ảnh · Thư viện · Bộ sưu tập · Cài đặt · Agent đều có lối vào.
        foreach (["emit('open-source')", "emit('open-library')", "emit('open-collections')", '/cai-dat', '/agent-studio'] as $entry) {
            $this->assertStringContainsString($entry, $phone, 'Thiếu lối vào '.$entry.' trên điện thoại.');
        }

        // 6. Lưới kết quả THẬT (không phải rail 12 ảnh) + thanh lọc của chính nó.
        $this->assertStringContainsString('<ResultGrid />', $phone);
        $this->assertStringContainsString('data-phone-results-filter', $phone);
        $this->assertStringContainsString('data-phone-results-search', $phone);
        $this->assertStringContainsString("store.outputSheet = 'search'", $phone,
            'Mở ô tìm phải đi qua ĐÚNG cờ dùng chung (store.outputSheet) để con trỏ tự đặt vào ô tìm.');

        // 7. TÀI KHOẢN: danh tính · gói · cài đặt · ĐĂNG XUẤT — menu tài khoản của màn rộng neo vào
        //    thanh tiêu đề (không có ở nhánh điện thoại), nên thiếu chỗ này là người dùng điện thoại
        //    KHÔNG có cách nào đăng xuất trong app.
        $this->assertStringContainsString('data-phone-account', $phone);
        $this->assertStringContainsString('data-phone-logout', $phone);
        $this->assertStringContainsString("emit('logout')", $phone);
        $this->assertStringContainsString('@logout="logout"', $app,
            'Đăng xuất phải đi qua ĐÚNG hàm logout() của StudioApp (một bản logic: fetch + XSRF + điều hướng).');

        /* [Đợt 64] MỘT MÀN SỬA ẢNH: bề mặt chỉnh ảnh (tả · khoanh · cọ) nằm TRONG công cụ «Sửa ảnh»,
           không còn là màn riêng mở bằng cờ `store.editImageOpen`. Trên điện thoại, mọi lối vào «Sửa
           ảnh» đều mở ĐÚNG công cụ đó (toàn màn), nên nó chạy được bằng ngón tay. */
        $actions = $this->code($this->src('components/PhoneActions.vue'));
        $this->assertStringContainsString('data-phone-action="edit"', $actions);
        $this->assertStringContainsString("emit('edit')", $actions);
        $this->assertStringContainsString("props.activityNav.find((a) => a.id === 'inpaint')", $phone,
            'Nhánh điện thoại phải mở CÔNG CỤ «Sửa ảnh» — công cụ này chứa bề mặt chỉnh ảnh.');
        $this->assertStringNotContainsString('editImageOpen', $phone,
            'Không còn màn «Chỉnh ảnh» riêng — bỏ hẳn cờ đó.');

        // Và công cụ gốc phải THẬT SỰ nhúng bề mặt chỉnh ảnh (không chỉ nói suông trong tài liệu).
        $inpaint = $this->code($this->src('components/InpaintCard.vue'));
        $this->assertStringContainsString("import EditImageModal from './EditImageModal.vue'", $inpaint);
        $this->assertStringContainsString('<EditImageModal />', $inpaint,
            'Công cụ «Sửa ảnh» phải nhúng bề mặt chỉnh ảnh — nếu không, hai nửa lại tách rời như trước.');
    }

    /**
     * KHÔNG mount lại lớp phủ dùng chung ở StudioPhone.
     *
     * Chúng là singleton (usePopmenu · store.chatOpen · store.viewer): mount hai nơi là hai lớp phủ
     * cùng lúc — menu không gian hiện hai lần, trình xem mở hai tầng. Lỗi loại này chỉ lộ ra khi bấm.
     */
    public function test_studio_phone_khong_mount_lai_lop_phu_dung_chung(): void
    {
        $phone = $this->code($this->src('components/StudioPhone.vue'));

        foreach (['PopMenu', 'NotificationCenter', 'GalleryModal', 'ChatModal', 'ConfirmDialog', 'SourcePickerPopup', 'ProjectWorkspace', 'EditImageModal'] as $shared) {
            $this->assertStringNotContainsString("import $shared from", $phone,
                $shared.' là lớp phủ DÙNG CHUNG — StudioApp mount một lần cho cả hai nhánh; mount thêm ở đây là bản sao thứ hai.');
        }
    }

    /**
     * YÊU CẦU ĐIỀU HƯỚNG TRÊN ĐIỆN THOẠI KHÔNG ĐƯỢC RƠI VÀO KHOẢNG KHÔNG.
     *
     * `store.requestActivity(id)` là kênh duy nhất mà thẻ trong Trợ lý / nút trong trình xem ảnh dùng
     * để "đưa tôi tới công cụ X". Trên điện thoại, hai cờ của nhánh tablet (toolsListOpen ·
     * mobileToolOpen) KHÔNG được render ⇒ trước đợt 59 yêu cầu đó bật một cờ vô hình và màn hình đứng
     * yên. Nay nó phải đi sang đúng nhánh đang render.
     */
    public function test_yeu_cau_dieu_huong_tren_dien_thoai_di_sang_dung_nhanh(): void
    {
        $app = $this->code($this->src('StudioApp.vue'));

        $this->assertStringContainsString('if (isPhone.value) { phoneToolRequest.value', $app,
            'revealActivity phải rẽ nhánh điện thoại TRƯỚC nhánh màn hẹp của tablet.');
        $this->assertStringContainsString('const phoneToolRequest = ref(', $app);
        $this->assertStringContainsString(':tool-request="phoneToolRequest"', $app);
        $this->assertStringContainsString('watch(() => props.toolRequest && props.toolRequest.n', $this->code($this->src('components/StudioPhone.vue')),
            'StudioPhone phải NGHE yêu cầu điều hướng và mở công cụ đó — nếu không, prop chỉ để trang trí.');

        // Ngưỡng điện thoại là MỘT nguồn (§15.6 luật 1): hằng số khai một lần, không rải số 520.
        $this->assertStringContainsString("const PHONE_MAX_W = 520;", $app);
        $this->assertSame(1, substr_count($app, "'(max-width: ' + PHONE_MAX_W + 'px)'"),
            'Ngưỡng điện thoại phải dựng từ hằng số dùng chung, không viết lại chuỗi media query.');
    }

    // ── C. KHÔNG CANVAS · §7.2 · §15.7 ──────────────────────────────────────────

    public function test_dien_thoai_khong_tao_dom_canvas_va_theo_luat_bo_cuc(): void
    {
        $app = $this->src('StudioApp.vue');
        $phone = $this->src('components/StudioPhone.vue');

        // Không canvas: nhánh điện thoại là v-if, không phải v-show/hidden.
        $this->assertMatchesRegularExpression('/<StudioPhone\s+v-if="!booting && isPhone"/', $app,
            'Nhánh điện thoại phải là v-if — v-show/hidden là vẫn tạo DOM canvas (§15.6).');
        $this->assertStringNotContainsString('data-main-view="canvas"', $phone);
        $this->assertStringNotContainsString('LayersPanel', $phone);

        // §7.2 luật 1 + 3: h-dvh và chừa chỗ cho thanh lệnh bằng SPACER (không padding-bottom).
        $this->assertStringContainsString('h-dvh', $phone);
        $this->assertStringContainsString('class="h-24 shrink-0" aria-hidden="true"', $phone,
            'Thiếu spacer h-24 ⇒ nội dung cuối bị thanh lệnh che (§7.2 luật 3).');

        // §7.2 luật 4: màn chiếm trọn nhận đúng tầng 90 của thang tầng.
        $surface = $this->src('components/PhoneSurface.vue');
        $this->assertStringContainsString('z-[90]', $surface, 'Màn chiếm trọn phải ở tầng 90 (deck/trình xem cùng tầng).');
        $this->assertStringContainsString('env(safe-area-inset-top', $surface);

        // §15.7 luật 4: mỗi cấp có ĐƯỜNG LÙI — ← lùi một cấp và ✕ đóng hết, đều có aria-label.
        $this->assertStringContainsString('data-phone-surface-back', $surface);
        $this->assertStringContainsString('data-phone-surface-close', $surface);
        $this->assertStringContainsString('aria-label="Lùi một cấp"', $surface);
        $this->assertStringContainsString('aria-label="Đóng"', $surface);

        // Back của máy lùi từng cấp: ngăn xếp là NGUỒN SỰ THẬT, ba cờ hiển thị chỉ là hình chiếu.
        $this->assertStringContainsString("const top = computed(() => nav.state.stack[nav.state.stack.length - 1] || '')", $phone);
        $this->assertStringContainsString("const toolsOpen = computed(() => top.value === 'tools')", $phone);
        $this->assertStringContainsString("const resultsOpen = computed(() => top.value === 'results')", $phone);
        $this->assertStringContainsString("if (nested) nav.push('tool'); else nav.open('tool');", $phone,
            'Mở công cụ từ danh sách là ĐI SÂU một cấp (để ← quay lại danh sách), mở từ màn chính là mở chuỗi mới.');

        // Đường NÂNG CẤP của điện thoại: bảng «Gói & credit» là popover neo vào nút tài khoản ở thanh
        // tiêu đề — không có ở nhánh điện thoại ⇒ bật store.planOpen ở đó là mở một bề mặt không
        // render (chỉ ra toast rồi hết). Điện thoại phải đi tới TRANG /bang-gia.
        $this->assertStringContainsString("window.location.href = '/bang-gia';", $app,
            'Công cụ bị khoá theo gói trên điện thoại phải có đường nâng cấp THẬT (§15.10: không ngõ cụt).');

        // §15.9: rung rất nhẹ, và không bao giờ là tín hiệu duy nhất — haptic chỉ đi kèm đổi trạng thái.
        $this->assertStringContainsString("import { haptic } from '../composables/useHaptics.js'", $phone);
        $this->assertStringNotContainsString('navigator.vibrate', $phone);
    }

    /**
     * TÀI LIỆU PHẢI NÓI ĐÚNG VỀ ĐIỆN THOẠI.
     *
     * §15.6 và §15.10 từng khẳng định khoanh vùng / ghép layer / compose là "không có" trên điện
     * thoại — đúng với CÔNG CỤ CANVAS, nhưng SAI với màn Chỉnh ảnh (một ảnh, ba chế độ, chạy bằng
     * ngón tay) và sai với 9 công cụ nay đã có lối vào. Tài liệu lệch mã là lỗi thật: người sau đọc
     * bảng đó rồi tưởng điện thoại không làm được gì.
     */
    public function test_tai_lieu_noi_dung_ve_dien_thoai(): void
    {
        $doc = (string) file_get_contents(base_path('docs/DESIGN_SYSTEM.md'));

        foreach ([
            'StudioPhone.vue',
            'PhoneSurface.vue',
            'Tác vụ ảnh',
            'Công cụ',
            'Kết quả',
        ] as $needle) {
            $this->assertStringContainsString($needle, $doc, 'Tài liệu thiếu mô tả về «'.$needle.'» của bản điện thoại.');
        }

        // Bảng "điện thoại KHÔNG có" không được còn câu phủ định toàn bộ công cụ một-ảnh.
        $this->assertStringNotContainsString('Khoanh vùng/thay vùng, ghép layer, compose | Ba dock kéo giãn được', $doc,
            'Bảng §15.6 còn nói điện thoại không có khoanh vùng — sai: màn Chỉnh ảnh chạy trên điện thoại.');
        $this->assertStringContainsString('cần màn hình lớn', $doc,
            'Chỗ nào thật sự chỉ có ở màn rộng thì phải nói thẳng (§15.10) — nay là xếp lớp/kéo giãn/ba dock.');

        // Tài liệu nhắc tên tệp/test thì tên đó phải có thật (luật của chính tài liệu này).
        $this->assertFileExists(base_path('tests/Feature/PhoneStudioParityTest.php'));
    }
}
