<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [Yêu cầu 2026-09-17] TÁCH CARD: "Ảnh mới từ ảnh mẫu" (1 card · 2 chip) → 2 CARD RIÊNG:
 *   · chip "Tạo ảnh mới" → card "Tạo biến thể ảnh"
 *   · chip "Thử đồ"      → card "Mặc thử đồ"
 *
 * Bất biến khoá ở đây: hai card là hai THÀNH PHẦN riêng có state riêng; chip chuyển chế độ đã
 * bị gỡ hẳn; mỗi card có ICON SVG chuẩn ngành (variations · hanger) và nút hành động riêng.
 */
class RefCardSplitTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(resource_path('js/studio/'.$rel));
    }

    /** Chỉ phần KHAI BÁO mục thanh công cụ (bỏ comment giải thích xung quanh). */
    private function nav(): string
    {
        $app = $this->src('StudioApp.vue');
        $start = strpos($app, 'const activityNav');
        $end = strpos($app, '];', (int) $start);
        $block = substr($app, (int) $start, (int) $end - (int) $start);

        // Bỏ comment `//` (kể cả comment nằm TRONG mảng): ghi chú được phép nhắc tên nhóm đã xoá.
        return (string) preg_replace('#//[^\n]*#', '', $block);
    }

    public function test_two_separate_card_components_exist(): void
    {
        $this->assertFileExists(resource_path('js/studio/components/VariationCard.vue'));
        $this->assertFileExists(resource_path('js/studio/components/TryOnCard.vue'));

        $this->assertStringContainsString('variant="variation"', $this->src('components/VariationCard.vue'));
        $this->assertStringContainsString('variant="tryon"', $this->src('components/TryOnCard.vue'));
    }

    public function test_each_card_is_its_own_activity_item(): void
    {
        $app = $this->src('StudioApp.vue');

        // CHỈ soi mảng activityNav — comment giải thích được phép NHẮC tới tên nhóm đã xoá
        // (chính file này ghi lại lý do), soi cả file sẽ bắt oan ghi chú.
        $nav = $this->nav();

        // [Yêu cầu 2026-09-17] Nhóm "Fitting Room" bị XOÁ: mỗi card nay là MỘT MỤC RIÊNG trên
        // thanh công cụ, có panel và icon của chính nó.
        $this->assertStringNotContainsString('Fitting Room', $nav,
            'Nhóm "Fitting Room" phải bị xoá khỏi activityNav.');
        $this->assertStringNotContainsString("id: 'ref'", $nav, 'Activity id \'ref\' phải bị xoá.');

        $this->assertStringContainsString(
            "{ id: 'variation', icon: 'variations', label: 'Tạo biến thể ảnh', cards: [VariationCard] }",
            $nav,
            'Phải có MỤC RIÊNG "Tạo biến thể ảnh" với icon chuẩn ngành variations.'
        );
        $this->assertStringContainsString(
            "{ id: 'tryon', icon: 'hanger', label: 'Mặc thử đồ', cards: [TryOnCard] }",
            $nav,
            'Phải có MỤC RIÊNG "Mặc thử đồ" với icon móc treo.'
        );

        $this->assertStringContainsString("import VariationCard from './components/VariationCard.vue'", $app);
        $this->assertStringContainsString("import TryOnCard from './components/TryOnCard.vue'", $app);
    }

    public function test_no_activity_points_at_a_removed_id(): void
    {
        // Bẫy thật đã gặp: xoá activity 'ref' nhưng còn chỗ gán activeActivity = 'ref' ⇒ panel
        // rơi về mục đầu và người dùng mất ngữ cảnh. Bất biến: mọi id gán phải CÒN tồn tại.
        $app = $this->src('StudioApp.vue');

        preg_match_all("/id: '([A-Za-z0-9_\\-]+)'/u", $app, $m);
        $ids = $m[1] ?? [];
        $this->assertContains('variation', $ids, 'Thiếu activity id \'variation\'.');
        $this->assertContains('tryon', $ids, 'Thiếu activity id \'tryon\'.');

        // Quét MỌI câu gán vào activeActivity rồi lấy các literal trong đó. Bản trước chỉ khớp
        // đúng một biểu thức ternary với [a-z]+ nên id chứa '_' (vd 'ref_da_xoa') LỌT — mutation-test
        // phát hiện. Nay quét theo CÂU LỆNH nên không phụ thuộc hình dạng biểu thức.
        preg_match_all('/activeActivity\.value\s*=\s*([^;]+);/', $app, $stmts);
        $used = [];
        foreach ($stmts[1] ?? [] as $stmt) {
            preg_match_all("/'([A-Za-z0-9_\\-]+)'/", (string) $stmt, $mm);
            $used = array_merge($used, $mm[1] ?? []);
        }

        $this->assertNotEmpty($used, 'Không tìm thấy câu gán activeActivity nào — guard sẽ vô hiệu.');
        foreach (array_unique($used) as $id) {
            $this->assertContains($id, $ids, "activeActivity gán id '{$id}' nhưng id đó không tồn tại trong activityNav.");
        }
    }

    public function test_mode_chips_are_gone(): void
    {
        $card = $this->src('components/RefImageCard.vue');

        $this->assertStringNotContainsString('setMode', $card,
            'Chip chuyển chế độ phải bị GỠ HẲN — chế độ nay do card quyết định qua prop variant.');
        $this->assertStringNotContainsString('@click="setMode(', $card, 'Không còn nút chip chuyển chế độ.');
        $this->assertStringContainsString('defineProps', $card, 'Card phải nhận prop variant.');
        $this->assertStringContainsString("mode = computed(", $card, 'Chế độ nay suy ra TỪ prop, không phải state bấm tay.');
    }

    public function test_each_card_has_its_own_title_and_action_button(): void
    {
        $card = $this->src('components/RefImageCard.vue');

        $this->assertStringContainsString('Tạo biến thể ảnh', $card, 'Thiếu tiêu đề card "Tạo biến thể ảnh".');
        $this->assertStringContainsString('Mặc thử đồ', $card, 'Thiếu tiêu đề card "Mặc thử đồ".');
        $this->assertStringContainsString("'Tạo ' + variants + ' biến thể'", $card, 'Nút hành động của card biến thể.');
        $this->assertStringContainsString("'Mặc thử đồ ' + variants + ' bản'", $card, 'Nút hành động của card mặc thử đồ.');
    }

    public function test_industry_standard_icons_are_used(): void
    {
        $icons = $this->src('components/StudioIcon.vue');
        $card = $this->src('components/RefImageCard.vue');

        foreach (['variations', 'hanger'] as $name) {
            $this->assertStringContainsString($name.':', $icons, "Thiếu icon SVG '.$name.' trong StudioIcon.");
        }

        $this->assertStringContainsString(":name=\"isTryon ? 'hanger' : 'variations'\"", $card,
            'Mỗi card phải dùng icon chuẩn ngành của mình (móc treo · chồng ảnh).');
    }

    public function test_the_two_flows_keep_separate_state(): void
    {
        // Trước đây 2 chip dùng CHUNG state nên phải lưu/khôi phục prompt qua lại. Nay mỗi card
        // là một instance riêng ⇒ state tách tự nhiên, không còn biến lưu tạm nào.
        $card = $this->src('components/RefImageCard.vue');

        $this->assertStringNotContainsString('refgenPrompt', $card);
        $this->assertStringNotContainsString('tryonPrompt', $card);
    }
}
