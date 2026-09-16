<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\StudioOutfitSetting;
use App\Models\SuggestResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quyền sở hữu trên các tài nguyên CÓ user_id, ngoài generation (đã phủ ở StudioOwnershipTest).
 *
 * Vòng 17 rà lại: chỉ 4 bảng có user_id trong module studio —
 *   generations (đã test 8/8 endpoint) · projects (đã test ở ProjectControllerTest)
 *   · suggest_results (code có scope nhưng CHƯA có test) · studio_outfit_settings (chưa có test)
 * Các bảng còn lại (studio_assets, presets, face_presets, pose_presets, studio_api_keys,
 * studio_models, stylist_presets) KHÔNG có user_id -> là cấu hình GLOBAL do admin quản lý.
 * Test cuối file khẳng định rõ điều đó để người sau không nhầm là lỗi IDOR.
 *
 * LƯU Ý cách chọn "kẻ tấn công": route nằm trong group ['auth','admin','nostore'], nên nếu dùng
 * tài khoản customer thì họ bị middleware 'admin' chặn TRƯỚC phần kiểm tra sở hữu và test sẽ pass
 * vì LÝ DO SAI (bài học vòng 8). Vì vậy kẻ tấn công ở đây là MỘT ADMIN KHÁC.
 */
class StudioResourceOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function foreignSuggestResult(): SuggestResult
    {
        $owner = $this->admin();

        return SuggestResult::create([
            'user_id' => $owner->id,
            'reference_url' => '/storage/studio/ref/x.png',
            'image_prompt_en' => 'prompt của chủ sở hữu',
            'prompt_vi' => 'prompt tiếng việt',
            'apply_count' => 0,
        ]);
    }

    public static function suggestLibraryMutatingEndpoints(): array
    {
        return [
            'update' => ['PUT', '/api/suggest-library/{id}', ['image_prompt_en' => 'bị sửa']],
            'destroy' => ['DELETE', '/api/suggest-library/{id}', []],
            'apply' => ['POST', '/api/suggest-library/apply/{id}', []],
        ];
    }

    #[DataProvider('suggestLibraryMutatingEndpoints')]
    public function test_other_admin_cannot_touch_foreign_suggest_result(string $method, string $uri, array $payload): void
    {
        $foreign = $this->foreignSuggestResult();
        $attacker = $this->admin();

        $url = str_replace('{id}', (string) $foreign->id, $uri);
        $res = $this->actingAs($attacker)->json($method, $url, $payload);

        // findOrFail trên where(user_id) -> bản ghi không tồn tại với kẻ tấn công -> 404.
        $this->assertSame(404, $res->getStatusCode(), "$method $uri phải 404 với prompt của admin khác.");

        // Và bản ghi của chủ sở hữu KHÔNG bị đổi.
        $this->assertDatabaseHas('suggest_results', [
            'id' => $foreign->id,
            'image_prompt_en' => 'prompt của chủ sở hữu',
            'apply_count' => 0,
        ]);
    }

    public function test_owner_can_manage_own_suggest_result(): void
    {
        // Chiều ngược lại: nếu chỉ kiểm 404 mà không kiểm chiều này, một bản vá "chặn tất cả" cũng pass.
        $owner = $this->admin();
        $row = SuggestResult::create([
            'user_id' => $owner->id, 'image_prompt_en' => 'gốc', 'apply_count' => 0,
        ]);

        $this->actingAs($owner)
            ->putJson('/api/suggest-library/'.$row->id, ['image_prompt_en' => 'đã sửa'])
            ->assertOk();
        $this->assertSame('đã sửa', $row->fresh()->image_prompt_en);

        $this->actingAs($owner)->deleteJson('/api/suggest-library/'.$row->id)->assertOk();
        $this->assertDatabaseMissing('suggest_results', ['id' => $row->id]);
    }

    public function test_suggest_bulk_delete_only_affects_own_rows(): void
    {
        $me = $this->admin();
        $other = $this->admin();

        $mine = SuggestResult::create(['user_id' => $me->id, 'image_prompt_en' => 'mine', 'apply_count' => 0]);
        $theirs = SuggestResult::create(['user_id' => $other->id, 'image_prompt_en' => 'theirs', 'apply_count' => 0]);

        $this->actingAs($me)
            ->postJson('/api/suggest-library/bulk-delete', ['ids' => [$mine->id, $theirs->id]])
            ->assertOk();

        $this->assertDatabaseMissing('suggest_results', ['id' => $mine->id]);
        $this->assertDatabaseHas('suggest_results', ['id' => $theirs->id]);
    }

    // ── studio_outfit_settings: mỗi user một dòng, KHÔNG đọc/ghi chéo ────

    public function test_outfit_settings_are_isolated_per_user(): void
    {
        $a = $this->admin();
        $b = $this->admin();

        $this->actingAs($a)->postJson('/api/outfit-settings', [
            'style' => 'phong cách của A', 'ornament_level' => 3, 'creative_level' => 9,
        ])->assertOk();

        // B chưa lưu gì -> phải nhận giá trị MẶC ĐỊNH, không phải giá trị của A.
        $bView = $this->actingAs($b)->getJson('/api/outfit-settings')->assertOk()->json();
        $this->assertNotSame('phong cách của A', $bView['style'] ?? null, 'B không được đọc cài đặt của A.');

        // B lưu giá trị riêng -> không đụng dòng của A.
        $this->actingAs($b)->postJson('/api/outfit-settings', [
            'style' => 'phong cách của B', 'ornament_level' => 1, 'creative_level' => 2,
        ])->assertOk();

        $aView = $this->actingAs($a)->getJson('/api/outfit-settings')->assertOk()->json();
        $this->assertSame('phong cách của A', $aView['style'] ?? null, 'Cài đặt của A bị B ghi đè.');

        // Mỗi user đúng MỘT dòng, và user_id là của chính họ.
        $this->assertSame(1, StudioOutfitSetting::where('user_id', $a->id)->count());
        $this->assertSame(1, StudioOutfitSetting::where('user_id', $b->id)->count());
    }

    public function test_outfit_settings_save_cannot_target_another_user(): void
    {
        // $fillable của model CÓ 'user_id' -> nếu đường ghi dùng mass-assignment thì kẻ tấn công
        // có thể ghi vào dòng của người khác. Đường ghi thật dùng updateOrCreate(['user_id'=>auth()->id()])
        // nên user_id bị khoá theo người gọi. Test này khoá tính chất đó.
        $victim = $this->admin();
        StudioOutfitSetting::create([
            'user_id' => $victim->id, 'style' => 'của nạn nhân', 'ornament_level' => 5, 'creative_level' => 7,
        ]);

        $attacker = $this->admin();
        $this->actingAs($attacker)->postJson('/api/outfit-settings', [
            'style' => 'bị chiếm', 'ornament_level' => 0, 'creative_level' => 1,
            // cố tình gửi user_id của nạn nhân
            'user_id' => $victim->id,
        ])->assertOk();

        $this->assertSame('của nạn nhân', StudioOutfitSetting::where('user_id', $victim->id)->first()->style,
            'user_id gửi kèm KHÔNG được đổi chủ dòng cài đặt.');
        $this->assertSame('bị chiếm', StudioOutfitSetting::where('user_id', $attacker->id)->first()->style);
    }

    // ── Ghi rõ: các tài nguyên GLOBAL là CÓ CHỦ ĐÍCH ─────────────────────

    public function test_global_admin_managed_resources_are_shared_by_design(): void
    {
        // studio_assets / presets / face_presets / pose_presets / studio_api_keys / studio_models /
        // stylist_presets KHÔNG có user_id: đây là cấu hình dùng chung do admin quản lý. Test này
        // khẳng định hành vi đó là CHỦ ĐÍCH (một admin khác vẫn quản lý được), để lần rà sau không
        // nhầm thành lỗi IDOR.
        $adminA = $this->admin();
        $adminB = $this->admin();

        $preset = \App\Models\Preset::create([
            'category' => 'style', 'ui_label' => 'Preset dùng chung', 'prompt_injection' => 'x', 'sort_order' => 1,
        ]);

        // Admin B sửa được preset do admin A tạo -> đúng thiết kế (cấu hình chung).
        $this->actingAs($adminB)
            ->putJson('/api/presets/'.$preset->id, [
                'category' => 'style', 'ui_label' => 'Đã sửa bởi B', 'prompt_injection' => 'y', 'sort_order' => 1,
            ])
            ->assertOk();

        $this->assertSame('Đã sửa bởi B', $preset->fresh()->ui_label);
    }
}
