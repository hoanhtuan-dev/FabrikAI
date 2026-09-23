<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * CRON NGOÀI — endpoint /api/cron/tick thay cho cron hPanel đang lỗi.
 *
 * Bất biến (đo trên production: crontab không tồn tại, cron hPanel lỗi proc_open 65 lần):
 *   (a) chưa đặt token ⇒ TỰ KHOÁ (403);
 *   (b) token sai ⇒ 401, so bằng hash_equals;
 *   (c) token đúng ⇒ chạy schedule:run + studio:process, trả SỐ ĐO THẬT;
 *   (d) KHÔNG cần auth, KHÔNG cần CSRF;
 *   (e) nhịp tim được ghi + studio:process heal generation kẹt (việc THẬT, không no-op).
 */
class ExternalCronTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_tick_is_locked_without_a_token(): void
    {
        Config::set('studio.cron_token', '');
        Cache::forget('studio:cron:tick:lock');

        $this->postJson('/api/cron/tick')->assertStatus(403)->assertJsonPath('ok', false);
    }

    public function test_tick_rejects_a_wrong_token(): void
    {
        Config::set('studio.cron_token', 'dung-token');
        Cache::forget('studio:cron:tick:lock');

        $this->postJson('/api/cron/tick?token=sai-token')->assertStatus(401)->assertJsonPath('ok', false);
    }

    public function test_tick_runs_with_the_right_token_without_auth_or_csrf(): void
    {
        Config::set('studio.cron_token', 'dung-token');
        Cache::forget('studio:cron:tick:lock');

        // KHÔNG actingAs (không đăng nhập), KHÔNG X-CSRF-TOKEN — đây là điểm của cron ngoài.
        $res = $this->postJson('/api/cron/tick?token=dung-token');

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ran.0.task', 'schedule:run')
            ->assertJsonPath('ran.1.task', 'studio:process')
            ->assertJsonStructure(['ms', 'at']);

        $this->assertNotNull(Cache::get('studio:cron:tick'));
    }

    public function test_tick_does_real_work_by_healing_a_stuck_generation(): void
    {
        // studio:process là nửa còn lại: HEAL generation kẹt 'processing' thay vì để nói "Đang xử lý"
        // mãi mãi. Chứng minh đường tick chạy việc THẬT, không chỉ trả JSON rỗng.
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $gen = $user->generations()->create([
            'type' => 'image',
            'status' => 'processing',
            'credits_cost' => 1,
            'prompt' => 'kẹt',
        ]);
        // updated_at KHÔNG nằm trong $fillable (timestamp do Eloquent quản) ⇒ phải forceFill sau create.
        $gen->forceFill(['updated_at' => now()->subMinutes(15)])->save();

        Config::set('studio.cron_token', 'dung-token');
        Cache::forget('studio:cron:tick:lock');

        $this->postJson('/api/cron/tick?token=dung-token')->assertOk();

        $this->assertSame('failed', $gen->fresh()->status, 'generation kẹt phải được heal thành failed.');
    }
}
