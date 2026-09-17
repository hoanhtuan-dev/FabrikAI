<?php

namespace Tests\Feature;

use App\Models\StudioApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * [2026-09-17 · kế hoạch Đợt 0.1 — quyết định Q1: "mở studio cho customer, quyền HẸP"]
 *
 * Khoá BẤT BIẾN của việc tách nhóm route (routes/web.php):
 *
 *   ┌────────────────────────┬──────────────────────┬──────────┬──────────┬────────┐
 *   │ Nhóm                   │ Middleware           │ guest    │ customer │ admin  │
 *   ├────────────────────────┼──────────────────────┼──────────┼──────────┼────────┤
 *   │ STUDIO (xưởng)         │ auth + can-studio    │ 401      │ OK       │ OK     │
 *   │ ADMIN  (quản trị)      │ auth + admin         │ 401      │ 403      │ OK     │
 *   └────────────────────────┴──────────────────────┴──────────┴──────────┴────────┘
 *   + tài khoản is_active=false ⇒ 403 ở CẢ HAI nhóm.
 *
 * Test này tồn tại vì bản vá 0.1 nới quyền cho `customer` — nếu chỉ có chiều "customer
 * vào được" thì một bản vá "mở hết mọi thứ" cũng sẽ pass. Phải khoá CẢ HAI chiều.
 */
class StudioCustomerAccessTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    /**
     * Endpoint ĐỌC thuộc nhóm STUDIO — customer phải dùng được.
     * (Chọn toàn endpoint đọc để test không gọi provider AI thật.)
     */
    public static function studioReadEndpoints(): array
    {
        return [
            'defaults' => ['/api/defaults'],
            'latest' => ['/api/latest'],
            'presets' => ['/api/presets'],
            'projects' => ['/api/projects'],
            'outfit-settings' => ['/api/outfit-settings'],
            'library' => ['/api/library/data'],
            'suggest-library' => ['/api/suggest-library/data'],
            'prompt-history' => ['/api/prompt-history'],
            'models-catalog' => ['/api/swap-models'],
        ];
    }

    #[DataProvider('studioReadEndpoints')]
    public function test_customer_can_use_studio_endpoints(string $uri): void
    {
        $this->actingAs($this->customer())
            ->getJson($uri)
            ->assertOk();
    }

    /** Endpoint QUẢN TRỊ — customer PHẢI bị chặn (đây là nửa quan trọng của bất biến). */
    public static function adminOnlyEndpoints(): array
    {
        return [
            'settings-vue.data' => ['GET', '/api/settings-vue/data'],
            'settings-vue.models' => ['GET', '/api/settings-vue/models'],
            'settings.data' => ['GET', '/api/settings/data'],
            'settings.update' => ['POST', '/api/settings'],
            'settings-vue.config' => ['POST', '/api/settings-vue/config'],
            'models.store' => ['POST', '/api/models'],
            'keys.store' => ['POST', '/api/keys'],
            'presets.store' => ['POST', '/api/presets'],
            'face-presets.store' => ['POST', '/api/face-presets'],
            // dùng POST (không có route-model binding) để chắc chắn chạm middleware admin
            'assets.store' => ['POST', '/api/assets'],
            'uploads' => ['GET', '/api/uploads'],
            'ref-images' => ['GET', '/api/ref-images'],
            'stylist-data.types.save' => ['POST', '/api/stylist-data/types'],
            'stylist-data.questions.save' => ['POST', '/api/stylist-data/questions'],
        ];
    }

    #[DataProvider('adminOnlyEndpoints')]
    public function test_customer_is_forbidden_from_admin_endpoints(string $method, string $uri): void
    {
        $this->actingAs($this->customer())
            ->json($method, $uri, [])
            ->assertForbidden();
    }

    public function test_guest_gets_401_on_both_groups(): void
    {
        $this->getJson('/api/defaults')->assertStatus(401);          // STUDIO
        $this->getJson('/api/settings-vue/data')->assertStatus(401); // ADMIN
        $this->postJson('/api/models', [])->assertStatus(401);       // ADMIN
    }

    public function test_inactive_account_is_blocked_even_when_admin(): void
    {
        $blocked = $this->admin();
        $blocked->forceFill(['is_active' => false])->save();

        $this->actingAs($blocked->fresh())->getJson('/api/defaults')->assertForbidden();
        $this->actingAs($blocked->fresh())->getJson('/api/settings-vue/data')->assertForbidden();
    }

    public function test_studio_gate_rejects_unknown_role(): void
    {
        // Role lạ (dữ liệu cũ / gán tay) không được lọt vào xưởng.
        $weird = User::factory()->create();
        $weird->forceFill(['role' => 'editor'])->save();

        $this->actingAs($weird->fresh())->getJson('/api/defaults')->assertForbidden();
    }

    /**
     * RÒ RỈ: nhóm STUDIO nay mở cho `customer`, nên mọi payload trả về phải được kiểm lại.
     * Khoá bất biến: ciphertext của API key (và bản rõ) KHÔNG được xuất hiện trong response.
     */
    public function test_studio_endpoints_do_not_leak_api_key_to_customer(): void
    {
        $secret = 'sk-super-secret-value-1234567890';
        StudioApiKey::create([
            'provider' => 'qwen', 'label' => 'key rò rỉ?', 'value' => $secret,
            'kind' => 'api_key', 'scopes' => ['image'], 'priority' => 1, 'enabled' => true,
        ]);
        $cipher = (string) StudioApiKey::first()->getRawOriginal('value');

        foreach (['/api/defaults', '/api/boot', '/api/latest'] as $uri) {
            $raw = $this->actingAs($this->customer())->getJson($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString($secret, $raw, "$uri KHÔNG được trả bản rõ của API key.");
            $this->assertStringNotContainsString($cipher, $raw, "$uri KHÔNG được trả ciphertext của API key.");
        }
    }

    /**
     * Bất biến của bản vá: nhóm "xưởng" phải THẬT SỰ rộng hơn nhóm "quản trị".
     * Nếu ai đó (trong tương lai) đổi `can-studio` về lại `admin`, test này đỏ ngay —
     * đó chính là hồi quy mà Đợt 0.1 sinh ra để chặn.
     */
    public function test_studio_group_is_strictly_wider_than_admin_group(): void
    {
        $customer = $this->customer();

        $studioOk = $this->actingAs($customer)->getJson('/api/defaults')->getStatusCode();
        $adminBlocked = $this->actingAs($customer)->getJson('/api/settings-vue/data')->getStatusCode();

        $this->assertSame(200, $studioOk, 'customer phải vào được xưởng.');
        $this->assertSame(403, $adminBlocked, 'customer phải bị chặn ở khu quản trị.');
    }
}
