<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phân quyền trên MỌI endpoint generation-scoped: tầng GATE (admin) và tầng SỞ HỮU (owner).
 *
 * Lý do có file này: controller có 8 chỗ abort_unless(owner, 403) nhưng bộ test chỉ phủ 2
 * (region, inpaint) — vùng IDOR rủi ro nhất của module.
 *
 * LƯU Ý QUAN TRỌNG VỀ CÁCH VIẾT TEST (đã tự bắt lỗi một lần):
 * các route này nằm trong group ['auth','admin','nostore']. Nếu "kẻ tấn công" là tài khoản
 * customer thì họ bị middleware 'admin' chặn TRƯỚC khi tới phần kiểm tra sở hữu — test sẽ PASS
 * dù phần kiểm tra sở hữu có bị gỡ hay không (pass vì lý do SAI). Vì vậy kẻ tấn công ở đây phải
 * là MỘT ADMIN KHÁC (qua được gate, nhưng không phải chủ sở hữu) — chỉ khi đó 403 mới chứng minh
 * được tầng sở hữu hoạt động. Trường hợp customer bị gate chặn được kiểm riêng ở dưới.
 */
class StudioOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public static function foreignGenerationEndpoints(): array
    {
        return [
            'show' => ['GET', '/api/generations/{id}', []],
            'destroy' => ['DELETE', '/api/generations/{id}', []],
            'cancel' => ['POST', '/api/generations/{id}/cancel', []],
            'download' => ['GET', '/api/generations/{id}/download', []],
            'palette' => ['GET', '/api/generations/{id}/palette', []],
            'rename' => ['POST', '/api/generations/{id}/rename', ['name' => 'hacked']],
            'region' => ['POST', '/api/generations/{id}/region', ['op' => 'erase', 'region' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2]]],
            'inpaint' => ['POST', '/api/generations/{id}/inpaint', ['prompt' => 'hacked']],
        ];
    }

    private function admin(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => User::ROLE_ADMIN], $attrs));
    }

    /** Trả về [chủ sở hữu (admin), generation của họ]. */
    private function ownedGeneration(): array
    {
        $owner = $this->admin();
        $gen = $owner->generations()->create([
            'type' => 'image', 'status' => 'completed', 'prompt' => 'ảnh của chủ sở hữu',
            'media_url' => '/storage/studio/own.png', 'provider' => 'qwen', 'model' => 'm', 'credits_cost' => 0,
        ]);

        return [$owner, $gen];
    }

    // ── Tầng SỞ HỮU: admin khác vẫn phải bị chặn ─────────────────────────

    #[DataProvider('foreignGenerationEndpoints')]
    public function test_other_admin_cannot_touch_foreign_generation(string $method, string $uri, array $payload): void
    {
        [, $gen] = $this->ownedGeneration();
        $attacker = $this->admin();   // admin (qua được gate) nhưng KHÔNG phải chủ sở hữu

        $url = str_replace('{id}', (string) $gen->id, $uri);
        $res = $this->actingAs($attacker)->json($method, $url, $payload);

        $this->assertSame(403, $res->getStatusCode(), "$method $uri phải trả 403 cho generation của admin khác.");
        $this->assertDatabaseHas('generations', ['id' => $gen->id]);
    }

    // ── Tầng GATE: khách chưa đăng nhập ─────────────────────────────────

    #[DataProvider('foreignGenerationEndpoints')]
    public function test_guest_cannot_touch_generation_endpoints(string $method, string $uri, array $payload): void
    {
        [, $gen] = $this->ownedGeneration();

        $url = str_replace('{id}', (string) $gen->id, $uri);
        $res = $this->json($method, $url, $payload);

        // FabrikAI render JSON cho /api/* nên khách nhận 401 (không redirect về trang đăng nhập).
        $this->assertSame(401, $res->getStatusCode(), "$method $uri phải chặn khách chưa đăng nhập.");
        $this->assertDatabaseHas('generations', ['id' => $gen->id]);
    }

    // ── Tầng GATE: customer (không phải admin) ──────────────────────────

    #[DataProvider('foreignGenerationEndpoints')]
    public function test_customer_is_blocked_by_admin_gate(string $method, string $uri, array $payload): void
    {
        [, $gen] = $this->ownedGeneration();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $url = str_replace('{id}', (string) $gen->id, $uri);
        $this->actingAs($customer)->json($method, $url, $payload)->assertStatus(403);
        $this->assertDatabaseHas('generations', ['id' => $gen->id]);
    }

    // ── Chiều ngược lại: chủ sở hữu PHẢI dùng được ──────────────────────

    public function test_owner_can_read_and_delete_own_generation(): void
    {
        // Nếu chỉ kiểm 403 mà không kiểm chiều này, một bản vá "chặn tất cả" cũng sẽ pass.
        [$owner, $gen] = $this->ownedGeneration();

        $this->actingAs($owner)
            ->getJson('/api/generations/'.$gen->id)
            ->assertOk()
            ->assertJsonPath('id', $gen->id);

        $this->actingAs($owner)->getJson('/api/generations/'.$gen->id.'/palette')->assertOk();

        $this->actingAs($owner)->deleteJson('/api/generations/'.$gen->id)->assertOk();
        $this->assertDatabaseMissing('generations', ['id' => $gen->id]);
    }

    public function test_owner_can_rename_own_generation(): void
    {
        [$owner, $gen] = $this->ownedGeneration();

        $this->actingAs($owner)
            ->postJson('/api/generations/'.$gen->id.'/rename', ['name' => 'Tên mới'])
            ->assertOk();

        $this->assertDatabaseHas('generations', ['id' => $gen->id]);
    }
}
