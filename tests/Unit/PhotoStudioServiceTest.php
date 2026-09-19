<?php

namespace Tests\Unit;

use App\Services\PhotoStudioService;
use PHPUnit\Framework\TestCase;

/**
 * [2026-09-22] STUDIO — phòng chụp thời trang: catalog + dựng danh sách ảnh (shot list).
 *
 * Ba bất biến phải khoá bằng test:
 *   1. MỌI tấm trong một buổi chụp dùng CHUNG một "look signature" (ảnh phải đồng bộ như một bộ);
 *   2. prompt luôn có điều khoản GIỮ NGUYÊN TRANG PHỤC (sản phẩm không bị AI thiết kế lại);
 *   3. catalog/số ảnh/credit tính TẤT ĐỊNH — xem trước đúng bằng cái sẽ chạy.
 */
class PhotoStudioServiceTest extends TestCase
{
    private PhotoStudioService $studio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->studio = new PhotoStudioService();
    }

    public function test_catalog_covers_a_full_photo_shoot(): void
    {
        $catalog = $this->studio->catalog();

        foreach (['backdrops', 'lighting', 'cameras', 'poses', 'shots', 'styles', 'ratios'] as $key) {
            $this->assertNotEmpty($catalog[$key], 'Thiếu nhóm: '.$key);
        }
        $this->assertGreaterThanOrEqual(10, count($catalog['backdrops']), 'Bối cảnh chủ đề phải đủ dùng cho nhiều bộ sưu tập.');
        $this->assertGreaterThanOrEqual(6, count($catalog['shots']), 'Danh sách loại ảnh phải đủ cho một bộ lookbook.');
        $this->assertSame(12, PhotoStudioService::MAX_SHOTS);

        foreach ($catalog['backdrops'] as $backdrop) {
            $this->assertNotEmpty($backdrop['name']);
            $this->assertNotEmpty($backdrop['prompt'], 'Bối cảnh phải có mô tả tiếng Anh để đưa vào prompt.');
            $this->assertNotEmpty($backdrop['palette']);
            $this->assertNotEmpty($backdrop['light_name'], 'Bối cảnh phải gợi ý sơ đồ đèn.');
        }
    }

    public function test_all_shots_share_one_look_signature(): void
    {
        $plan = $this->studio->plan([
            'backdrop' => 'hanoi-autumn',
            'lighting' => 'golden-hour',
            'camera' => '85-18',
            'pose' => 'walking',
            'style' => 'magazine',
            'shots' => ['full-body', 'three-quarter', 'fabric-detail', 'back-view'],
        ], 2);

        $this->assertSame(4, $plan['total_shots']);
        $this->assertStringStartsWith('LOOK-', $plan['look_id']);

        foreach ($plan['shots'] as $shot) {
            $this->assertStringContainsString($plan['look_signature'], $shot['prompt'], 'Mọi tấm phải mang CÙNG look signature.');
            $this->assertStringContainsString('100% fidelity', $shot['prompt'], 'Prompt phải yêu cầu giữ nguyên trang phục.');
            $this->assertStringContainsString('No text, no watermark', $shot['prompt']);
        }

        // Mỗi tấm phải khác nhau ở phần KHUNG HÌNH (nếu không thì cả bộ là một ảnh lặp lại).
        $prompts = array_column($plan['shots'], 'prompt');
        $this->assertCount(4, array_unique($prompts));
    }

    public function test_look_id_tracks_the_set_but_not_the_shot_list(): void
    {
        $base = ['backdrop' => 'studio-white', 'lighting' => 'softbox-even', 'camera' => '50-28', 'pose' => 'stand-34', 'style' => 'ecommerce-clean'];

        $a = $this->studio->plan($base + ['shots' => ['full-body']], 2);
        $b = $this->studio->plan($base + ['shots' => ['full-body', 'back-view']], 2);
        $c = $this->studio->plan(array_merge($base, ['backdrop' => 'tet-red']) + ['shots' => ['full-body']], 2);

        $this->assertSame($a['look_id'], $b['look_id'], 'Thêm/bớt loại ảnh KHÔNG đổi look — vẫn cùng buổi chụp.');
        $this->assertNotSame($a['look_id'], $c['look_id'], 'Đổi bối cảnh là đổi look.');
    }

    public function test_plan_counts_images_and_credits_deterministically(): void
    {
        $plan = $this->studio->plan([
            'shots' => ['full-body', 'three-quarter', 'ecommerce'],
            'variants' => 2,
        ], 2, true, 3);

        $this->assertSame(3, $plan['total_shots']);
        $this->assertSame(6, $plan['total_images'], 'Số ảnh = số loại × biến thể.');
        $this->assertSame(18, $plan['total_credits'], 'Credit = số ảnh × giá mỗi ảnh.');
        foreach ($plan['shots'] as $shot) {
            $this->assertSame(2, $shot['variants']);
        }
    }

    public function test_plan_warns_when_inputs_are_missing(): void
    {
        $few = $this->studio->plan(['shots' => ['full-body']], 1, true);
        $demo = $this->studio->plan(['shots' => ['full-body']], 2, false);

        $this->assertSame('error', $few['warnings'][0]['level'], 'Thiếu ảnh tham chiếu phải là LỖI (không chạy được).');
        $this->assertSame(false, $demo['image_ready']);
        $this->assertNotEmpty(array_filter($demo['warnings'], fn ($w) => str_contains($w['message'], 'demo')));
    }

    public function test_plan_falls_back_safely_on_unknown_ids(): void
    {
        $plan = $this->studio->plan([
            'backdrop' => 'khong-ton-tai',
            'lighting' => 'khong-ton-tai',
            'camera' => 'khong-ton-tai',
            'pose' => 'khong-ton-tai',
            'style' => 'khong-ton-tai',
            'shots' => ['khong-ton-tai'],
        ], 2);

        $this->assertSame('studio-white', $plan['setup']['backdrop'], 'Bối cảnh lạ ⇒ về studio trắng (an toàn cho ảnh bán hàng).');
        $this->assertSame(1, $plan['total_shots'], 'Không có loại ảnh hợp lệ ⇒ dùng loại đầu tiên.');
        $this->assertSame('full-body', $plan['shots'][0]['id']);
        $this->assertNotEmpty($plan['setup']['lighting']);
    }

    public function test_plan_caps_the_shot_list_and_uses_custom_backdrop(): void
    {
        $all = array_column($this->studio->shots(), 'id');
        $plan = $this->studio->plan([
            'shots' => array_merge($all, $all),          // gửi trùng + vượt trần
            'backdrop' => 'custom',
            'backdrop_note' => 'sân thượng Sài Gòn lúc hoàng hôn, lan can sắt',
        ], 2);

        $this->assertLessThanOrEqual(12, $plan['total_shots']);
        $this->assertCount($plan['total_shots'], array_unique(array_column($plan['shots'], 'id')), 'Loại ảnh phải được khử trùng.');
        $this->assertStringContainsString('sân thượng Sài Gòn', $plan['shots'][0]['prompt'], 'Bối cảnh tự nhập phải vào prompt.');
    }

    public function test_ratio_override_applies_to_every_shot(): void
    {
        $plan = $this->studio->plan(['shots' => ['full-body', 'fabric-detail'], 'ratio' => '9:16'], 2);

        foreach ($plan['shots'] as $shot) {
            $this->assertSame('9:16', $shot['ratio']);
        }
        $default = $this->studio->plan(['shots' => ['full-body', 'fabric-detail']], 2);
        $this->assertSame('4:5', $default['shots'][0]['ratio'], 'Không chọn tỉ lệ ⇒ theo gợi ý từng loại ảnh.');
        $this->assertSame('1:1', $default['shots'][1]['ratio']);
    }
}
