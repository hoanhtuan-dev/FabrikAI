<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [Yêu cầu 2026-09-20] VÙNG TOOLBAR PHẢI CÓ CHIỀU CAO CỐ ĐỊNH — và MỌI thanh ngữ cảnh phải tôn trọng nó.
 *
 * Cách hỏng THẬT đã đo được (Chrome headless, harness dựng đúng component thật + CSS build thật):
 *   · Trước: mỗi thanh ngữ cảnh tự đặt `flex flex-wrap ... py-2` ⇒ thanh tự do xuống dòng, rail
 *     `min-h-12` vì thế CAO LÊN theo công cụ: đo được 36px (không công cụ) · 52px (vùng chọn, crop,
 *     film look, chọn layer, quét chọn, di chuyển) · 56px · và 94px ở "Vẽ tự do" (9 ô thông số dồn
 *     thành 2 hàng). Vùng canvas bị đẩy xuống mỗi lần đổi công cụ — đúng cảm giác "giật/nhảy".
 *   · Sau: rail cao ĐÚNG 48px (clientHeight 47 sau khi trừ border-b 1px) cho CẢ 10 biến thể, mỗi
 *     thanh cao đúng 36px, không phần tử con nào thò ra ngoài (đo childOverflowY = 0), và khi nội
 *     dung dài hơn bề ngang thì rail CUỘN THEO TRỤC X (đo được: "Vẽ tự do" rộng 1351px trong rail
 *     1254px ⇒ scrollWidth 1375 > clientWidth 1254 = cuộn ngang được).
 *
 * Bài test này khoá đúng ba bất biến đó ở mức MÃ NGUỒN, để lần sau thêm một biến thể thanh ngữ cảnh
 * mà quên khuôn chung là ĐỎ ngay — thay vì phải mở trình duyệt đo lại mới phát hiện.
 */
class ToolbarAreaTest extends TestCase
{
    /** Chiều cao vùng toolbar desktop (h-12 = 48px) — MỘT con số duy nhất cho mọi công cụ. */
    private const RAIL_HEIGHT = 'h-12';

    /** Chiều cao mỗi thanh ngữ cảnh bên trong (h-9 = 36px) — phải NHỎ HƠN rail để không tràn. */
    private const BAR_HEIGHT = 'h-9';

    private function vue(string $name): string
    {
        return (string) file_get_contents(resource_path('js/studio/components/'.$name));
    }

    private function app(): string
    {
        return (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
    }

    /** Lấy đoạn markup của MỘT khung chứa toolbar: <div ...> (lồng) <ContextToolbar />. */
    private function toolbarBox(string $source, string $branch): string
    {
        // Chỉ lấy nhánh desktop (rail trên) hoặc mobile (khối nổi) quanh <ContextToolbar />.
        $pattern = $branch === 'desktop'
            ? '/<div class="([^"]*)"[^>]*>\s*<div class="([^"]*)"[^>]*>\s*<ContextToolbar \/>/s'
            : '/<div v-if="toolActive"[^>]*class="([^"]*)"[^>]*>\s*<div class="([^"]*)"[^>]*><ContextToolbar \/>/s';

        $this->assertSame(1, preg_match($pattern, $source, $m),
            "Không đọc được khung toolbar ($branch) trong StudioApp.vue — cấu trúc đã đổi.");

        return $m[0];
    }

    public function test_desktop_toolbar_rail_has_a_fixed_height(): void
    {
        $box = $this->toolbarBox($this->app(), 'desktop');

        $this->assertStringContainsString(self::RAIL_HEIGHT, $box,
            'Vùng toolbar desktop phải cao CỐ ĐỊNH ('.self::RAIL_HEIGHT.') — không để nội dung bên trong quyết định chiều cao.');
        $this->assertStringNotContainsString('min-h-12', $box,
            'min-h-12 là chiều cao TỐI THIỂU: thanh ngữ cảnh nhiều thông số sẽ đẩy vùng toolbar cao lên.');
        $this->assertStringNotContainsString('py-', $box,
            'Padding dọc cộng thêm vào chiều cao làm vùng toolbar không còn là một con số cố định.');
    }

    public function test_toolbar_rail_scrolls_on_the_x_axis_and_never_on_y(): void
    {
        foreach (['desktop', 'mobile'] as $branch) {
            $box = $this->toolbarBox($this->app(), $branch);

            $this->assertStringContainsString('overflow-x-auto', $box,
                "Khung toolbar ($branch) phải CUỘN THEO TRỤC X khi nội dung dài hơn bề ngang.");
            $this->assertStringContainsString('overflow-y-hidden', $box,
                "Khung toolbar ($branch) không được để nội dung tràn theo trục Y (sẽ phá chiều cao cố định).");
            $this->assertStringContainsString('w-max', $box,
                "Khung toolbar ($branch) cần một lớp w-max bên trong làm 'vật để cuộn' — thiếu nó thì flex sẽ co nội dung lại thay vì cuộn.");
            $this->assertStringContainsString('mx-auto', $box,
                "Khung toolbar ($branch) phải căn giữa bằng margin auto: justify-center trên khung cuộn sẽ đẩy mép TRÁI ra ngoài vùng cuộn (không kéo tới được).");
        }
    }

    public function test_mobile_toolbar_box_has_a_fixed_height_too(): void
    {
        $box = $this->toolbarBox($this->app(), 'mobile');

        $this->assertMatchesRegularExpression('/\bh-\d+/', $box,
            'Khối toolbar nổi trên mobile cũng phải cao cố định, không để thanh ngữ cảnh quyết định chiều cao.');
    }

    public function test_every_context_toolbar_variant_shares_one_nowrap_bar(): void
    {
        $ctx = $this->vue('ContextToolbar.vue');

        // (1) Đúng MỘT khuôn chung, và khuôn đó mang đủ 4 tính chất bắt buộc.
        $this->assertSame(1, preg_match("/const bar = '([^']+)';/", $ctx, $m),
            'Mọi thanh ngữ cảnh phải dùng CHUNG một hằng số khuôn (bar) — không mỗi thanh một kiểu.');
        $bar = $m[1] ?? '';

        foreach ([
            self::BAR_HEIGHT => 'Thanh phải cao cố định ('.self::BAR_HEIGHT.') để khớp vùng toolbar.',
            'flex-nowrap' => 'Thanh KHÔNG được xuống dòng — xuống dòng là nguyên nhân vùng toolbar cao lên.',
            'w-max' => 'Thanh phải rộng bằng nội dung, nếu không flex sẽ bóp các ô thông số lại.',
            'shrink-0' => 'Thanh không được co lại trong rail — co lại là mất chỗ cuộn ngang.',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $bar, $why);
        }

        // (2) KHÔNG còn thanh nào tự đặt flex-wrap (đúng cái bẫy đã gây lỗi).
        //     Bỏ ghi chú trước khi soi — chữ "flex-wrap" trong lời giải thích không phải là mã.
        $code = preg_replace(['/<!--.*?-->/s', '/\/\*.*?\*\//s', '/\/\/[^\n]*/'], '', $ctx);
        $this->assertStringNotContainsString('flex-wrap', $code,
            'Còn sót flex-wrap trong thanh ngữ cảnh ⇒ biến thể đó lại tự xuống dòng và phá chiều cao vùng toolbar.');

        // (3) MỌI gốc của template (mỗi biến thể là một gốc) đều phải theo khuôn chung.
        preg_match_all('/^  <(div|span)\b[^>]*>/m', $ctx, $roots);
        // [2026-09-26 · D1+D2] Từ 10 xuống 7 gốc: đã XOÁ ba biến thể theo quyết định của chủ dự án —
        // «Crop/Reframe» (D1 — không cần crop) và «Xoá vùng» + «Vẽ tự do» (D2 — bỏ hẳn paint/erase).
        // Con số này là LƯỚI BẮT LỖI ĐỌC FILE: nếu tụt xuống nữa thì hoặc là xoá nhầm, hoặc là cách
        // đọc file đã hỏng. Giữ đúng 7 để lần xoá sau phải CỐ Ý cập nhật con số.
        $this->assertGreaterThanOrEqual(7, count($roots[0]),
            'Số gốc template ít hơn số biến thể đã biết — hãy kiểm tra lại cách đọc file.');

        foreach ($roots[0] as $tag) {
            $ok = str_contains($tag, '[bar, ring]')                                   // thanh ngữ cảnh thật
                || (str_contains($tag, 'whitespace-nowrap') && str_contains($tag, 'shrink-0')); // placeholder chữ
            $this->assertTrue($ok,
                'Gốc template này không theo khuôn chung (sẽ tự quyết chiều cao): '.trim($tag));
        }
    }
}
