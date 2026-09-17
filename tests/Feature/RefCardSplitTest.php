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

    public function test_two_separate_card_components_exist(): void
    {
        $this->assertFileExists(resource_path('js/studio/components/VariationCard.vue'));
        $this->assertFileExists(resource_path('js/studio/components/TryOnCard.vue'));

        $this->assertStringContainsString('variant="variation"', $this->src('components/VariationCard.vue'));
        $this->assertStringContainsString('variant="tryon"', $this->src('components/TryOnCard.vue'));
    }

    public function test_both_cards_are_registered_in_the_fitting_room_panel(): void
    {
        $app = $this->src('StudioApp.vue');

        $this->assertStringContainsString('cards: [VariationCard, TryOnCard]', $app,
            'Panel phải đăng ký CẢ HAI card riêng, không gộp lại thành 1 card 2 chip.');
        $this->assertStringContainsString("import VariationCard from './components/VariationCard.vue'", $app);
        $this->assertStringContainsString("import TryOnCard from './components/TryOnCard.vue'", $app);
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
