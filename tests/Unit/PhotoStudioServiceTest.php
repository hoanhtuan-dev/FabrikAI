<?php

namespace Tests\Unit;

use App\Services\PhotoStudioService;
use PHPUnit\Framework\TestCase;

/**
 * [2026-09-22] STUDIO (luồng mới): ảnh người mẫu + bối cảnh + prompt + CHIP NHANH.
 *
 * Bất biến phải khoá bằng test:
 *   1. chip lấy ĐÚNG dữ liệu "Cài đặt của tôi" — bản ghép phải giống hệt logic useLocalCatalog.merge()
 *      (ẩn · ghi đè · mục tự thêm nối cuối), nếu không chip trong Studio sẽ khác thứ người dùng thấy;
 *   2. prompt luôn giữ SẢN PHẨM nguyên vẹn và chỉ bám bối cảnh khi có ảnh 2;
 *   3. số ảnh/credit tính TẤT ĐỊNH — xem trước đúng bằng cái sẽ chạy.
 */
class PhotoStudioServiceTest extends TestCase
{
    private PhotoStudioService $studio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->studio = new PhotoStudioService();
    }

    /** Baseline giống bảng presets dùng chung. */
    private function baseline(): array
    {
        return [
            ['id' => 'p1', 'category' => 'fabric', 'ui_label' => 'Lụa mềm', 'prompt_injection' => 'soft silk fabric', 'note' => '', 'sort_order' => 1],
            ['id' => 'p2', 'category' => 'background', 'ui_label' => 'Studio trắng', 'prompt_injection' => 'seamless white studio backdrop', 'note' => '', 'sort_order' => 2],
            ['id' => 'p3', 'category' => 'style', 'ui_label' => 'Tối giản', 'prompt_injection' => 'minimal styling', 'note' => '', 'sort_order' => 3],
        ];
    }

    public function test_chip_groups_mirror_the_my_settings_catalog(): void
    {
        $groups = $this->studio->chipGroups($this->baseline(), [
            'hidden' => ['p3'],
            'edits' => ['p1' => ['ui_label' => 'Lụa mềm (bản của tôi)', 'prompt_injection' => 'my own silk wording']],
            'custom' => [['id' => 'local-1', 'category' => 'fabric', 'ui_label' => 'Vải thô', 'prompt_injection' => 'raw textured fabric', 'sort_order' => 0]],
        ]);

        $byId = collect($groups)->keyBy('id');
        $this->assertSame(['fabric', 'background'], array_column($groups, 'id'), 'Nhóm ẩn hết mục thì biến mất; nhóm còn mục thì giữ.');
        $this->assertSame('Chất liệu', $byId['fabric']['label']);
        $this->assertCount(2, $byId['fabric']['items']);
        $this->assertSame('Lụa mềm (bản của tôi)', $byId['fabric']['items'][0]['label'], 'Mục bị sửa phải dùng bản của người dùng.');
        $this->assertSame('my own silk wording', $byId['fabric']['items'][0]['injection']);
        $this->assertSame('Vải thô', $byId['fabric']['items'][1]['label'], 'Mục tự thêm nối vào CUỐI nhóm.');
        $this->assertSame('seamless white studio backdrop', $byId['background']['items'][0]['injection']);
    }

    public function test_chip_groups_skip_incomplete_rows(): void
    {
        $groups = $this->studio->chipGroups([
            ['id' => 'a', 'category' => 'fabric', 'ui_label' => '', 'prompt_injection' => 'x'],
            ['id' => 'b', 'category' => 'fabric', 'ui_label' => 'Có nhãn', 'prompt_injection' => ''],
            ['id' => 'c', 'category' => 'fabric', 'ui_label' => 'Hợp lệ', 'prompt_injection' => 'ok'],
        ]);

        $this->assertSame(1, count($groups));
        $this->assertCount(1, $groups[0]['items']);
        $this->assertSame('Hợp lệ', $groups[0]['items'][0]['label'], 'Preset thiếu nhãn hoặc thiếu đoạn chèn thì bỏ (không tạo chip rỗng).');
    }

    public function test_chip_index_prefers_the_users_own_catalog(): void
    {
        $index = $this->studio->chipIndex($this->baseline(), [
            'edits' => ['p2' => ['prompt_injection' => 'my studio wording']],
            'hidden' => ['p1'],
        ]);

        $this->assertArrayNotHasKey('p1', $index, 'Mục bị ẩn không được xuất hiện trong chip của Studio.');
        $this->assertSame('my studio wording', $index['p2']['injection']);
    }

    public function test_scene_maps_the_three_images_and_keeps_the_garment(): void
    {
        $index = $this->studio->chipIndex($this->baseline());

        $one = $this->studio->scene(['prompt' => 'đổi sang nền tường gạch'], $index, 1);
        $this->assertStringContainsString('FIRST image is the model wearing the garment', $one['prompt']);
        $this->assertStringContainsString('100% fidelity', $one['prompt']);
        $this->assertStringContainsString('do NOT redesign', $one['prompt']);
        $this->assertStringNotContainsString('SECOND image', $one['prompt'], 'Chưa có ảnh bối cảnh thì không được nhắc ảnh 2.');
        $this->assertStringContainsString('DIRECTION: đổi sang nền tường gạch', $one['prompt']);

        $two = $this->studio->scene(['prompt' => 'ra ngoài trời'], $index, 2);
        $this->assertStringContainsString('SECOND image', $two['prompt'], 'Có ảnh 2 ⇒ phải bám phối cảnh/ánh sáng của ảnh bối cảnh.');
        $this->assertStringNotContainsString('THIRD image', $two['prompt']);

        $three = $this->studio->scene([], $index, 3);
        $this->assertStringContainsString('THIRD image', $three['prompt'], 'Có ảnh 3 ⇒ nêu rõ vai trò tham chiếu thêm.');
    }

    public function test_scene_appends_selected_chips_from_my_settings(): void
    {
        $index = $this->studio->chipIndex($this->baseline());
        $scene = $this->studio->scene([
            'prompt' => 'đổi bối cảnh',
            'chips' => ['p1', 'p2', 'khong-ton-tai'],
        ], $index, 2);

        $this->assertStringContainsString('DETAILS: soft silk fabric · seamless white studio backdrop.', $scene['prompt']);
        $this->assertCount(2, $scene['used_chips']);
        $this->assertSame('fabric', $scene['used_chips'][0]['category']);
        $this->assertSame('Chất liệu', $scene['used_chips'][0]['category_label'], 'Giao diện cần NHÃN nhóm, không phải mã nhóm.');
        $this->assertNotEmpty(array_filter($scene['warnings'], fn ($w) => str_contains($w['message'], 'không còn tồn tại')));
    }

    public function test_scene_needs_an_image_and_a_direction(): void
    {
        $empty = $this->studio->scene([], [], 0);
        $levels = array_column($empty['warnings'], 'level');
        $this->assertContains('error', $levels);

        $messages = implode(' | ', array_column($empty['warnings'], 'message'));
        $this->assertStringContainsString('ảnh người mẫu', $messages);
        $this->assertStringContainsString('prompt hoặc chọn ít nhất một chip', $messages);
    }

    public function test_scene_counts_credits_and_flags_demo_mode(): void
    {
        $index = $this->studio->chipIndex($this->baseline());
        $scene = $this->studio->scene(['prompt' => 'x', 'variants' => 3, 'ratio' => '9:16'], $index, 2, false, 2);

        $this->assertFalse($scene['image_ready']);
        $this->assertSame(3, $scene['total_images']);
        $this->assertSame(6, $scene['total_credits']);
        $this->assertSame('9:16', $scene['ratio']);
        $this->assertSame(3, $scene['variants']);
        $this->assertNotEmpty(array_filter($scene['warnings'], fn ($w) => str_contains($w['message'], 'demo')));
    }

    public function test_scene_caps_chips_and_ignores_a_bad_ratio(): void
    {
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = ['id' => 'c'.$i, 'category' => 'style', 'ui_label' => 'Chip '.$i, 'prompt_injection' => 'injection '.$i];
        }
        $index = $this->studio->chipIndex($items);
        $scene = $this->studio->scene([
            'prompt' => 'x',
            'chips' => array_column($items, 'id'),
            'ratio' => '16:9',
        ], $index, 1);

        $this->assertCount(PhotoStudioService::MAX_CHIPS, $scene['used_chips']);
        $this->assertNull($scene['ratio'], 'Tỉ lệ lạ bị bỏ (không truyền xuống pipeline).');
        $this->assertNotEmpty(array_filter($scene['warnings'], fn ($w) => str_contains($w['message'], 'tối đa')));
    }
}
