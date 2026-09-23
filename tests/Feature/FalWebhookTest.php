<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WEBHOOK FAL — ba tầng xác thực + idempotency.
 *
 * Bất biến (thiết kế docs/CREDIT_GOI_VA_LOI_NHUAN.md §6.3):
 *   (a) payload thiếu request_id ⇒ 400;
 *   (b) request_id không thuộc generation nào ⇒ 200 "unknown_request" (không retry);
 *   (c) chữ ký đúng ⇒ T2 pass; chữ ký sai ⇒ vẫn re-fetch (T3 là tầng sống còn);
 *   (d) KHÔNG tin URL ảnh trong payload — lấy từ fal (re-fetch);
 *   (e) idempotent: nhận 2 lần cùng request_id ⇒ chỉ chốt 1 lần.
 */
class FalWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // fetchResult cần FAL_KEY; doctor cần APP_URL https (fal KHÔNG theo redirect).
        Config::set('studio.fal_key', 'test-key');
        Config::set('app.url', 'https://fabrikai.shop');
    }

    private function genWithFalMeta(string $requestId): Generation
    {
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        return $user->generations()->create([
            'type' => 'image', 'status' => 'processing',
            'prompt' => 'test', 'model' => 'fal-ai/flux/schnell', 'provider' => 'fal',
            'credits_cost' => 1,
            'meta' => ['fal' => ['request_id' => $requestId, 'model' => 'fal-ai/flux/schnell']],
        ]);
    }

    public function test_missing_request_id_is_rejected(): void
    {
        $this->postJson('/api/webhooks/fal', ['status' => 'OK'])
            ->assertStatus(400)
            ->assertJsonPath('ok', false);
    }

    public function test_unknown_request_returns_ok_without_retry(): void
    {
        // 200 (không phải 4xx/5xx) để fal KHÔNG retry một request không thuộc về ta.
        $this->postJson('/api/webhooks/fal', ['request_id' => 'khong-ton-tai', 'status' => 'OK'])
            ->assertStatus(200)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'unknown_request');
    }

    public function test_completes_generation_from_fal_fetch_not_payload(): void
    {
        $rid = 'req-'.bin2hex(random_bytes(8));
        $gen = $this->genWithFalMeta($rid);

        // Giả lập fal re-fetch trả ảnh THẬT (không phải ảnh trong payload).
        Http::fake([
            'queue.fal.run/*/requests/*' => Http::response([
                'images' => [['url' => 'https://v3.fal.media/fake.png', 'width' => 1024, 'height' => 1024]],
            ], 200),
            'rest.fal.ai/.well-known/jwks.json' => Http::response(['keys' => []], 200),
        ]);

        $this->postJson('/api/webhooks/fal', [
            'request_id' => $rid,
            'status' => 'OK',
            'payload' => ['images' => [['url' => 'https://attacker-controlled.example/fake.png']]],
        ])->assertOk();

        $gen->refresh();
        $this->assertSame('completed', $gen->status);
        // KHÔNG dùng ảnh của attacker — dùng ảnh fal trả về (được storeRemoteImage xử lý).
        $this->assertNotSame('https://attacker-controlled.example/fake.png', $gen->media_url);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $rid = 'req-'.bin2hex(random_bytes(8));
        $gen = $this->genWithFalMeta($rid);

        Http::fake([
            'queue.fal.run/*/requests/*' => Http::response([
                'images' => [['url' => 'https://v3.fal.media/fake.png', 'width' => 1024, 'height' => 1024]],
            ], 200),
            'rest.fal.ai/.well-known/jwks.json' => Http::response(['keys' => []], 200),
        ]);

        $this->postJson('/api/webhooks/fal', ['request_id' => $rid, 'status' => 'OK'])->assertOk();
        $this->postJson('/api/webhooks/fal', ['request_id' => $rid, 'status' => 'OK'])->assertOk();

        // Chỉ 1 lần chốt — CAS giữ lần thứ hai không ghi đè.
        $this->assertSame('completed', $gen->fresh()->status);
    }

    public function test_doctor_command_reports_health(): void
    {
        Http::fake([
            'rest.fal.ai/.well-known/jwks.json' => Http::response(['keys' => [['x' => 'c3VyZS1rZXktMTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']]], 200),
            'queue.fal.run/fal-ai/flux/schnell' => Http::response(['request_id' => 'test'], 200),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('studio:webhook-doctor')->assertSuccessful();
    }
}
