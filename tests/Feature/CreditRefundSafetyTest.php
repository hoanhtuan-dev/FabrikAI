<?php

namespace Tests\Feature;

use App\Jobs\RenderImageJob;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AN TOÀN HOÀN CREDIT (M-c · M-d · M-h — phát hiện 2026-09-17 trong đợt xác minh độc lập).
 *
 * Ba lỗi gốc đều là "đường hoàn tiền thứ hai thiếu CAS":
 *  · M-c: cancel() đọc trạng thái trên instance cũ rồi update + increment VÔ ĐIỀU KIỆN ⇒ chạy song
 *         song với failStuck()/reconcileStuckCredits() là HOÀN 2 LẦN.
 *  · M-d: RenderImageJob/RenderVideoJob ghi trạng thái cuối VÔ ĐIỀU KIỆN ⇒ job xong sau khi người
 *         dùng Huỷ sẽ "hồi sinh" row đã cancelled trong khi credit đã được hoàn.
 *  · M-h: queueGeneration() trừ credit TRƯỚC khi tạo row ⇒ create lỗi là mất credit vô chủ.
 *
 * Nay mọi đường đi qua studio_finalize_generation()/studio_claim_generation() (app/Support/helpers.php):
 * CHỈ request giành được row bằng CAS mới được hoàn tiền, nên không thể hoàn hai lần.
 */
class CreditRefundSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const COST = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function generation(User $u, array $attrs = []): Generation
    {
        return $u->generations()->create(array_merge([
            'type' => 'image', 'status' => 'processing', 'prompt' => 'x',
            'provider' => 'qwen', 'model' => 'm', 'credits_cost' => self::COST,
        ], $attrs));
    }

    /** Làm row "kẹt" quá ngưỡng heal của show() (6 phút với ảnh). */
    private function age(Generation $g, int $minutes): void
    {
        Generation::where('id', $g->id)->update(['updated_at' => now()->subMinutes($minutes)]);
    }

    // ── M-c: đua giữa heal-kẹt và cancel ──────────────────────────────────

    public function test_stuck_heal_then_cancel_refunds_exactly_once(): void
    {
        $u = $this->admin();
        $this->actingAs($u);
        $before = (int) $u->fresh()->credits_balance;

        $g = $this->generation($u);
        $this->age($g, 10);

        // 1) Poll quá hạn ⇒ heal processing → failed + hoàn credit (đường CAS).
        $this->getJson('/api/generations/'.$g->id)->assertOk();
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance);

        // 2) Cancel ngay sau đó: row đã rời processing ⇒ bị từ chối, KHÔNG hoàn lần hai.
        $this->postJson('/api/generations/'.$g->id.'/cancel')->assertStatus(422);
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance, 'hoàn đúng MỘT lần');
    }

    public function test_cancel_then_stuck_heal_refunds_exactly_once(): void
    {
        $u = $this->admin();
        $this->actingAs($u);
        $before = (int) $u->fresh()->credits_balance;

        $g = $this->generation($u);
        $this->postJson('/api/generations/'.$g->id.'/cancel')->assertOk();
        $this->assertSame('cancelled', $g->fresh()->status);
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance);

        // Poll sau đó (row đã cũ) KHÔNG được heal lại thành failed và KHÔNG hoàn thêm.
        $this->age($g, 10);
        $this->getJson('/api/generations/'.$g->id)->assertOk();
        $this->assertSame('cancelled', $g->fresh()->status);
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance);
    }

    public function test_cancel_twice_refunds_exactly_once(): void
    {
        $u = $this->admin();
        $this->actingAs($u);
        $before = (int) $u->fresh()->credits_balance;
        $g = $this->generation($u);

        $this->postJson('/api/generations/'.$g->id.'/cancel')->assertOk();
        $this->postJson('/api/generations/'.$g->id.'/cancel')->assertStatus(422);
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance);
    }

    // ── M-d: job không được "hồi sinh" row đã rời processing ──────────────

    public function test_success_write_is_rejected_after_cancel(): void
    {
        $u = $this->admin();
        $before = (int) $u->fresh()->credits_balance;
        $g = $this->generation($u);

        // Người dùng Huỷ trong lúc job đang render ⇒ hoàn credit + row cancelled.
        $this->assertTrue(studio_finalize_generation($g, 'cancelled'));
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance);

        // Job render xong và cố ghi 'completed' ⇒ CAS phải từ chối.
        $claimed = studio_claim_generation($g, ['processing'], [
            'status' => 'completed',
            'media_url' => '/storage/studio/khong-duoc-ghi.jpg',
        ]);
        $this->assertFalse($claimed, 'Job KHÔNG được ghi kết quả lên row đã cancelled');

        $fresh = $g->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertNull($fresh->media_url, 'Không được hồi sinh media_url của row đã huỷ');
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance, 'Không hoàn thêm lần nữa');
    }

    public function test_failed_write_after_cancel_does_not_double_refund(): void
    {
        $u = $this->admin();
        $before = (int) $u->fresh()->credits_balance;
        $g = $this->generation($u);

        $this->assertTrue(studio_finalize_generation($g, 'cancelled'));
        $this->assertFalse(studio_finalize_generation($g, 'failed', ['processing'], 'boom'));
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance);
    }

    public function test_job_failed_hook_refunds_once_and_is_idempotent(): void
    {
        $u = $this->admin();
        $before = (int) $u->fresh()->credits_balance;
        $g = $this->generation($u, ['status' => 'pending']);

        (new RenderImageJob($g->id))->failed(new \RuntimeException('boom'));
        $this->assertSame('failed', $g->fresh()->status);
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance);

        // Worker gọi lại failed() ⇒ không hoàn thêm.
        (new RenderImageJob($g->id))->failed(new \RuntimeException('boom again'));
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance);
    }

    // ── M-h: trừ credit phải ATOMIC với việc tạo row ──────────────────────

    public function test_credit_is_rolled_back_when_generation_create_fails(): void
    {
        $u = $this->admin();
        $this->actingAs($u);
        $before = (int) $u->fresh()->credits_balance;

        DB::listen(function ($q) {
            if (stripos($q->sql, 'insert into') !== false && stripos($q->sql, 'generations') !== false) {
                throw new \RuntimeException('forced generations.insert failure (test M-h)');
            }
        });

        try {
            $this->postJson('/api/generate', ['prompt' => 'x', 'history_id' => null]);
        } catch (\Throwable $e) {
            // Mong đợi: exception thoát khỏi transaction ⇒ rollback.
        }

        $this->assertSame($before, (int) $u->fresh()->credits_balance, 'create lỗi ⇒ credit phải rollback');
        $this->assertSame(0, Generation::count(), 'Không được để lại row generation dở dang');
    }
// ── M-d (đua THẬT ở tầng job): user Huỷ GIỮA lúc đang render ──────────

    public function test_job_discards_result_when_user_cancels_mid_render(): void
    {
        $u = $this->admin();
        $before = (int) $u->fresh()->credits_balance;
        $g = $this->generation($u, ['status' => 'pending']);

        // Service giả: NGAY GIỮA lúc render thì người dùng bấm Huỷ (đúng kịch bản đua thật),
        // sau đó vẫn trả về một URL như provider thật.
        $this->app->bind(\App\Services\ImageAIService::class, function () use ($g) {
            return new class($g) extends \App\Services\ImageAIService {
                public function __construct(private \App\Models\Generation $target) {}

                public function generate(string $prompt, ?string $baseImage = null, ?string $maskImage = null, ?string $resolution = null, ?string $ratio = null, ?string $faceRef = null, ?string $providerOverride = null, ?string $modelOverride = null, ?string $negativePrompt = null, array $refImages = [], ?string $mode = null, ?int $seed = null): string
                {
                    studio_finalize_generation($this->target->fresh(), 'cancelled');

                    return '/storage/studio/ket-qua-sau-khi-da-huy.jpg';
                }
            };
        });

        \App\Jobs\RenderImageJob::dispatchSync($g->id);

        $fresh = $g->fresh();
        $this->assertSame('cancelled', $fresh->status, 'Job KHÔNG được hồi sinh row đã huỷ');
        $this->assertNull($fresh->media_url, 'Kết quả render phải bị BỎ khi row đã rời processing');
        $this->assertSame($before + self::COST, (int) $u->fresh()->credits_balance, 'Hoàn đúng một lần');
    }

    // ── Bất biến: chỉ MỘT đường đụng vào credit ───────────────────────────

    public function test_only_one_credit_mutation_path_exists_in_app(): void
    {
        $increment = [];
        $decrement = [];

        foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
            $source = (string) file_get_contents($file->getRealPath());
            $rel = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

            if (str_contains($source, "increment('credits_balance'")) {
                $increment[] = $rel;
            }
            if (str_contains($source, "decrement('credits_balance'")) {
                $decrement[] = $rel;
            }
        }

        // HOÀN tiền chỉ được nằm trong helper CAS dùng chung — thêm đường thứ hai là mở lại M-c.
        $this->assertSame(['app/Support/helpers.php'], $increment, 'Hoàn credit phải đi qua ĐÚNG MỘT helper có CAS');
        // TRỪ tiền chỉ được nằm ở một chỗ tạo generation (trong transaction — M-h).
        $this->assertSame(['app/Http/Controllers/StudioController.php'], $decrement, 'Trừ credit phải đi qua một điểm duy nhất');
    }
}

