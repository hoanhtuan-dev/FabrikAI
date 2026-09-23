<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_video_credit_scales_with_duration(): void
    {
        // Video tính theo GIÂY: 5s tốn một nửa 10s. Cùng model, cùng độ phân giải.
        $five = studio_credit_cost_for('video', 'dashscope', 'wan3.0-video', '720', '', null, 5);
        $ten = studio_credit_cost_for('video', 'dashscope', 'wan3.0-video', '720', '', null, 10);
        $twenty = studio_credit_cost_for('video', 'dashscope', 'wan3.0-video', '720', '', null, 20);

        $this->assertGreaterThan(0, $five);
        // ceil làm tròn LÊN nên 10s không bao giờ bằng ĐÚNG 2×5s (36,1→37, 72,2→73) —
        // khẳng định tăng đều và nằm trong ±1 của tỉ lệ, đủ để bắt lỗi "giá cố định bất kể giây".
        $this->assertGreaterThan($five, $ten);
        $this->assertGreaterThan($ten, $twenty);
        $this->assertLessThanOrEqual($five * 2 + 1, $ten);
        $this->assertLessThanOrEqual($ten * 2 + 1, $twenty);
    }

    public function test_video_credit_keeps_the_margin_floor_at_the_worst_duration(): void
    {
        // 1080P (giá đã khai là 1080P) ở mọi thời lượng đều phải ≥ 40 % ở đơn giá tệ nhất.
        // Công thức dùng chung mẫu số 720 nên bất biến này được khoá tự động — bài này là lưới an toàn.
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $user->forceFill(['plan_id' => Plan::where('slug', 'studio')->value('id')])->save();

        foreach (['wan3.0-video', 'wan2.7-t2v', 'wan2.7-i2v'] as $model) {
            foreach ([5, 10, 20] as $seconds) {
                $credits = studio_credit_cost_for('video', 'dashscope', $model, '1080', '', $user, $seconds);
                $this->assertGreaterThanOrEqual(
                    (int) ceil($seconds * ($model === 'wan3.0-video' ? 0.20 : 0.15) * 26000 / 720),
                    $credits,
                    $model.' '.$seconds.'s thiếu credit.',
                );
            }
        }
    }

    public function test_a_video_with_no_priced_model_falls_back_safely(): void
    {
        // Model video lạ (chưa khai giá vốn) rơi về giá của GÓI (10) thay vì ném lỗi.
        $this->assertSame(
            studio_credit_cost('video'),
            studio_credit_cost_for('video', 'nha-cung-cap-la', 'video-model-la', '720', '', null, 10),
        );
    }

    public function test_the_generate_endpoint_charges_video_by_duration(): void
    {
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $user->forceFill(['plan_id' => Plan::where('slug', 'studio')->value('id'), 'credits_balance' => 100000])->save();

        $this->actingAs($user)->postJson('/api/video', [
            'prompt' => 'catwalk',
            'provider' => 'dashscope',
            'model' => 'wan2.7-t2v',
            'duration' => '10',
            'resolution' => '720',
        ])->assertOk();

        $gen = Generation::where('user_id', $user->id)->latest('id')->firstOrFail();
        $expected = studio_credit_cost_for('video', 'dashscope', 'wan2.7-t2v', '720', '', $user, 10);
        $this->assertSame($expected, (int) $gen->credits_cost);
        $this->assertGreaterThan(10, (int) $gen->credits_cost, 'Video 10s phải đắt hơn giá cố định cũ (10).');
    }
}
