<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Preset;
use App\Models\User;
use App\Models\UserCatalog;
use App\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [2026-09-22] STUDIO — hai endpoint của luồng mới: catalog CHIP (đọc từ "Cài đặt của tôi") + dựng
 * prompt. Cả hai TẤT ĐỊNH (không gọi model, không tốn credit) nên xem/sửa prompt thoải mái.
 */
class PhotoStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    public function test_guests_cannot_use_the_studio_endpoints(): void
    {
        $this->postJson('/api/studio/shoot/catalog')->assertUnauthorized();
        $this->postJson('/api/studio/shoot/plan', ['prompt' => 'x'])->assertUnauthorized();
    }

    public function test_catalog_serves_chips_from_my_settings(): void
    {
        $response = $this->actingAs($this->customer())
            ->postJson('/api/studio/shoot/catalog')
            ->assertOk();

        $this->assertNotEmpty($response->json('groups'), 'Phải có chip lấy từ PRESET trong Cài đặt của tôi.');
        // CHIP NHANH CHỈ 3 NHÓM: Bối cảnh · Góc máy · Ống kính (các nhóm mô tả sản phẩm không dùng ở đây).
        foreach ($response->json('groups') as $group) {
            $this->assertContains($group['id'], ['background', 'camera', 'lens'], 'Nhóm chip lạ trong Studio: '.$group['id']);
        }
        $this->assertSame(3, count($response->json('slots')), 'Ba ô ảnh: người mẫu · bối cảnh · tham chiếu thêm.');
        $this->assertSame('Người mẫu mặc trang phục', $response->json('slots.0.name'));
        $this->assertTrue($response->json('slots.0.required'));
        $this->assertFalse($response->json('slots.1.required'), 'Ảnh bối cảnh là TÙY CHỌN.');
        $this->assertSame('/cai-dat/presets', $response->json('settings_url'));
        $this->assertNotEmpty($response->json('ratios'));

        // Mọi chip đều phải có nhãn + đoạn chèn (không có chip rỗng).
        foreach ($response->json('groups') as $group) {
            $this->assertNotEmpty($group['items']);
            foreach ($group['items'] as $item) {
                $this->assertNotSame('', (string) $item['label']);
                $this->assertNotSame('', (string) $item['injection']);
            }
        }
    }

    public function test_catalog_respects_the_users_own_hidden_edits_and_custom_items(): void
    {
        $user = $this->customer();
        // Chỉ chọn preset thuộc 3 nhóm của Studio (nhóm khác bị lọc khỏi chip nhanh).
        $preset = Preset::where('category', 'background')->orderBy('sort_order')->firstOrFail();
        $other = Preset::where('category', 'camera')->orderBy('sort_order')->firstOrFail();

        UserCatalog::create([
            'user_id' => $user->id,
            'name' => 'presets',
            'data' => [
                'custom' => [[
                    'id' => 'local-1', 'category' => 'background',
                    'ui_label' => 'Sân thượng của tôi', 'prompt_injection' => 'my rooftop at sunset', 'sort_order' => 0,
                ]],
                'edits' => [(string) $preset->id => ['ui_label' => 'Nhãn tôi đổi', 'prompt_injection' => 'my edited injection']],
                'hidden' => [(string) $other->id],
            ],
        ]);

        $groups = collect($this->actingAs($user)->postJson('/api/studio/shoot/catalog')->assertOk()->json('groups'));
        $flat = $groups->flatMap(fn ($g) => $g['items'])->keyBy('id');

        $this->assertSame('Nhãn tôi đổi', $flat[(string) $preset->id]['label'], 'Studio phải thấy bản CHỈNH của người dùng.');
        $this->assertSame('my edited injection', $flat[(string) $preset->id]['injection']);
        $this->assertTrue($flat->has('local-1'), 'Mục người dùng TỰ THÊM trong Cài đặt phải thành chip trong Studio.');
        $this->assertFalse($flat->has((string) $other->id), 'Mục người dùng đã ẨN không được lộ ra trong Studio.');
    }

    /**
     * BẢO ĐẢM ĐẦU-CUỐI: preset người dùng thêm ở /cai-dat/presets phải thành CHIP trong Studio và đoạn
     * chèn của nó phải đi thẳng vào prompt. Test đi ĐÚNG đường mà trang Cài đặt dùng
     * (PUT /api/user-catalogs/presets — xem resources/js/studio/composables/useLocalCatalog.js).
     */
    public function test_a_preset_added_in_my_settings_becomes_a_studio_chip(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->putJson('/api/user-catalogs/presets', [
            'data' => [
                'custom' => [[
                    'id' => 'local-test-1', 'category' => 'background',
                    'ui_label' => 'Sân thượng hoàng hôn', 'prompt_injection' => 'rooftop at golden hour, city skyline behind',
                    'note' => 'Thử nghiệm', 'sort_order' => 0,
                ]],
                'edits' => [],
                'hidden' => [],
            ],
        ])->assertOk();

        $groups = collect($this->actingAs($user)->postJson('/api/studio/shoot/catalog')->assertOk()->json('groups'));
        $background = $groups->firstWhere('id', 'background');

        $this->assertNotNull($background, 'Nhóm Bối cảnh phải có trong chip của Studio.');
        $this->assertTrue(
            collect($background['items'])->contains(fn ($i) => $i['id'] === 'local-test-1' && $i['label'] === 'Sân thượng hoàng hôn'),
            'Preset vừa thêm ở /cai-dat/presets phải hiện thành chip trong Studio.'
        );

        $plan = $this->actingAs($user)->postJson('/api/studio/shoot/plan', [
            'prompt' => 'đổi bối cảnh',
            'chips' => ['local-test-1'],
            'image_count' => 2,
        ])->assertOk();

        $this->assertStringContainsString('rooftop at golden hour', (string) $plan->json('prompt'));
        $this->assertSame('local-test-1', $plan->json('used_chips.0.id'));
    }

    public function test_plan_endpoint_builds_the_prompt_without_calling_any_model(): void
    {
        $user = $this->customer();
        $preset = Preset::where('category', 'lens')->orderBy('sort_order')->firstOrFail();

        $response = $this->actingAs($user)
            ->postJson('/api/studio/shoot/plan', [
                'prompt' => 'đặt cô ấy vào quán cà phê, giữ nguyên trang phục',
                'chips' => [(string) $preset->id],
                'image_count' => 2,
                'variants' => 1,
                'ratio' => '4:5',
            ])
            ->assertOk();

        $response->assertJsonPath('engine', 'studio-scene-v2')
            ->assertJsonPath('ratio', '4:5')
            ->assertJsonPath('image_count', 2);

        $prompt = (string) $response->json('prompt');
        $this->assertStringContainsString('100% fidelity', $prompt);
        $this->assertStringContainsString('SECOND image', $prompt, 'Có ảnh bối cảnh ⇒ bám phối cảnh ảnh 2.');
        $this->assertStringContainsString('DIRECTION: đặt cô ấy vào quán cà phê', $prompt);
        $this->assertSame($preset->prompt_injection, $response->json('used_chips.0.injection'), 'Đoạn chèn luôn tra từ Cài đặt, không lấy từ client.');
        $this->assertFalse($response->json('image_ready'), 'Test chưa có key model ảnh ⇒ phải nói thật là chế độ demo.');
    }

    public function test_plan_endpoint_validates_input(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->postJson('/api/studio/shoot/plan', ['ratio' => '16:9'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ratio');

        $this->actingAs($user)
            ->postJson('/api/studio/shoot/plan', ['chips' => array_fill(0, 25, 'x')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('chips');
    }

    public function test_module_switch_blocks_the_studio_endpoints(): void
    {
        $user = $this->customer();
        $plan = Plan::where('slug', 'pro')->firstOrFail();
        $plan->forceFill(['modules' => array_values(array_diff(ModuleRegistry::ids(), ['compose']))])->save();
        $user->forceFill(['plan_id' => $plan->id, 'plan_expires_at' => null])->save();

        $this->actingAs($user->fresh())
            ->postJson('/api/studio/shoot/catalog')
            ->assertForbidden()
            ->assertJsonPath('code', 'module_locked');
    }

    public function test_compose_accepts_a_single_reference_image(): void
    {
        // Studio chỉ có ảnh người mẫu (chưa chọn ảnh bối cảnh) vẫn phải chạy được: prompt của Studio
        // được gửi nguyên văn qua final_prompt nên phần "ghép nhiều ảnh" không dùng tới.
        $response = $this->actingAs($this->customer())
            ->postJson('/api/compose', [
                'images' => ['/samples/studio-demo.jpg'],
                'prompt' => 'scene prompt',
                'final_prompt' => 'Professional fashion photograph. keep the garment exactly.',
            ]);

        $this->assertNotSame(422, $response->status(), 'Một ảnh duy nhất không được coi là dữ liệu sai.');
    }
}
