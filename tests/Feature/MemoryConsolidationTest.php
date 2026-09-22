<?php

namespace Tests\Feature;

use App\Jobs\ReflectBrandMemoryJob;
use App\Models\BrandLearning;
use App\Models\Generation;
use App\Models\Project;
use App\Models\User;
use App\Services\BrandLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CỦNG CỐ TRÍ NHỚ — GĐ3 (Việc #5, 2026-09-26).
 *
 * Khoá tám bất biến:
 *   (a) ĐỘ GIỐNG NHAU là hàm THUẦN, tất định, và đúng trên các ca thật của ngành (có ca ĐO được);
 *   (b) quyết định TRÙNG thì CỦNG CỐ ký ức cũ (weight + hits + refreshed_at), không tạo thêm một ký ức yếu;
 *   (c) ký ức đã có lesson ⇒ hàng mới KẾ THỪA lesson và KHÔNG gọi model (tiết kiệm một lượt gọi thật);
 *   (d) không trùng ⇒ vẫn đẩy job rút bài học như cũ (không được im lặng bỏ);
 *   (e) preferences() xếp theo ĐỘ MẠNH, không theo mới nhất — đây là điều làm "gu" tồn tại qua một buổi
 *       duyệt 40 ảnh;
 *   (f) decay() suy yếu ký ức lâu không dùng, có SÀN (không xuống dưới 1), và chỉ quên khi đã yếu + đã cũ;
 *   (g) --dry-run KHÔNG ghi gì;
 *   (h) ký ức mạnh phải tới được prompt qua ĐƯỜNG CONTAINER (lưới bắt lỗi inject — xem BrandRuleTest).
 */
class MemoryConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function shot(User $u, string $prompt): Generation
    {
        $project = $u->projects()->create(['name' => 'Thu Đông 2026']);

        return Generation::create([
            'user_id' => $u->id,
            'project_id' => $project->id,
            'type' => 'image',
            'status' => 'completed',
            'prompt' => $prompt,
            'media_url' => '/storage/studio/t/a.jpg',
            'credits_cost' => 1,
            'shot_state' => 'campaign_ready',
        ]);
    }

    private function memory(User $u, string $prompt, string $decision = BrandLearning::DECISION_APPROVED, array $extra = []): BrandLearning
    {
        return BrandLearning::create(array_merge([
            'user_id' => $u->id,
            'decision' => $decision,
            'prompt' => $prompt,
            'source' => 'shot_review',
        ], $extra));
    }

    // ── (a) ĐỘ GIỐNG NHAU ──────────────────────────────────────────────────────────────────────

    public function test_similarity_is_deterministic_and_sane_on_real_fashion_prompts(): void
    {
        $svc = BrandLearningService::class;

        $this->assertSame(1.0, $svc::similarity('đầm linen trắng ngà', 'đầm linen trắng ngà'));
        $this->assertSame(0.0, $svc::similarity('', 'đầm linen'));
        $this->assertSame(0.0, $svc::similarity('đầm linen', ''));

        // CÙNG phong cách (chỉ khác một chi tiết) ⇒ phải vượt ngưỡng.
        $same = $svc::similarity('đầm linen trắng ngà dáng suông', 'đầm linen trắng ngà dáng rộng');
        $this->assertGreaterThanOrEqual(BrandLearningService::SIMILARITY_THRESHOLD, $same, 'Cùng phong cách phải khớp (đo được: 0.83).');

        // KHÁC phong cách ⇒ phải dưới ngưỡng.
        $different = $svc::similarity('áo sơ mi linen', 'đầm dạ hội sequin đen');
        $this->assertLessThan(BrandLearningService::SIMILARITY_THRESHOLD, $different, 'Khác phong cách phải KHÔNG khớp (đo được: 0.00).');

        // CÙNG loại hàng nhưng KHÁC CHI TIẾT ⇒ vẫn phải khớp (đây là ca củng cố hay gặp nhất).
        $sameGarment = $svc::similarity('áo sơ mi linen trắng', 'áo sơ mi linen be');
        $this->assertGreaterThanOrEqual(BrandLearningService::SIMILARITY_THRESHOLD, $sameGarment);

        // KHÁC LOẠI HÀNG (sơ mi vs thun) dù cùng vải ⇒ CỐ Ý không khớp: đó là hai sản phẩm khác nhau, gộp
        // chúng lại là nói sai về shop. Đo được 0,57 — ngay dưới ngưỡng, và đúng là phải ở dưới.
        $differentGarment = $svc::similarity('áo sơ mi linen form rộng', 'áo thun linen form rộng');
        $this->assertLessThan(BrandLearningService::SIMILARITY_THRESHOLD, $differentGarment);

        // Từ NGẮN nhưng mang nghĩa phải ĐƯỢC GIỮ: bỏ chữ 2 ký tự ("áo") là tự tay xoá một tín hiệu thật.
        // So hai cặp giống hệt nhau trừ chữ "áo" — cặp CÓ "áo" phải giống nhau HƠN.
        $withA = $svc::similarity('áo linen form rộng', 'áo cotton form rộng');
        $withoutA = $svc::similarity('linen form rộng', 'cotton form rộng');
        $this->assertGreaterThan($withoutA, $withA, 'Cắt từ ở 3 ký tự sẽ làm hai con số này BẰNG NHAU.');

        // Từ ĐỆM không được làm hai prompt khác nhau thành "trùng".
        $stopWords = $svc::similarity('áo của và với cho', 'quần của và với cho');
        $this->assertLessThan(BrandLearningService::SIMILARITY_THRESHOLD, $stopWords);
    }

    // ── (b) CỦNG CỐ ────────────────────────────────────────────────────────────────────────────

    public function test_recording_a_similar_decision_reinforces_the_existing_memory(): void
    {
        Queue::fake();
        $u = $this->customer();

        app(BrandLearningService::class)->record($this->shot($u, 'đầm linen trắng ngà dáng suông'), BrandLearning::DECISION_APPROVED);
        $first = BrandLearning::firstOrFail();
        $this->assertSame(BrandLearningService::DEFAULT_WEIGHT, $first->weight);
        $this->assertSame(1, $first->hits);
        $this->assertNull($first->refreshed_at);

        app(BrandLearningService::class)->record($this->shot($u, 'đầm linen trắng ngà dáng rộng'), BrandLearning::DECISION_APPROVED);

        $first->refresh();
        $this->assertSame(BrandLearningService::DEFAULT_WEIGHT + 1, $first->weight, 'Ký ức cũ phải MẠNH LÊN.');
        $this->assertSame(2, $first->hits);
        $this->assertNotNull($first->refreshed_at, 'Phải đóng dấu thời điểm củng cố để lần sau không bị suy yếu.');
        $this->assertSame(2, BrandLearning::count(), 'Vẫn giữ hàng mới làm dấu vết (generation_id) — không nuốt mất.');
    }

    /** (c) Ký ức đã có bài học ⇒ hàng mới dùng luôn, KHÔNG tốn thêm một lượt gọi model. */
    public function test_a_consolidated_memory_inherits_the_lesson_without_calling_a_model(): void
    {
        Queue::fake();
        $u = $this->customer();

        $existing = $this->memory($u, 'đầm linen trắng ngà dáng suông', BrandLearning::DECISION_APPROVED, ['lesson' => 'Shop chuộng linen trắng ngà, dáng suông.']);

        app(BrandLearningService::class)->record($this->shot($u, 'đầm linen trắng ngà dáng rộng'), BrandLearning::DECISION_APPROVED);

        $new = BrandLearning::where('id', '!=', $existing->id)->firstOrFail();
        $this->assertSame('Shop chuộng linen trắng ngà, dáng suông.', $new->lesson);
        $this->assertSame($existing->id, (int) ($new->context['inherited_from'] ?? 0));
        Queue::assertNotPushed(ReflectBrandMemoryJob::class, 'Đã có bài học rồi thì KHÔNG gọi model lại.');
    }

    /** (d) Không trùng ⇒ vẫn rút bài học như cũ. */
    public function test_an_unrelated_decision_still_dispatches_the_reflect_job(): void
    {
        Queue::fake();
        $u = $this->customer();

        $this->memory($u, 'đầm linen trắng ngà', BrandLearning::DECISION_APPROVED);
        app(BrandLearningService::class)->record($this->shot($u, 'áo dạ hội sequin đen'), BrandLearning::DECISION_APPROVED);

        Queue::assertPushed(ReflectBrandMemoryJob::class);
    }

    /** Duyệt và LOẠI là hai ký ức khác nhau — ký ức "đã loại" không được củng cố bởi một lượt duyệt. */
    public function test_approved_and_rejected_memories_never_consolidate_into_each_other(): void
    {
        Queue::fake();
        $u = $this->customer();

        app(BrandLearningService::class)->record($this->shot($u, 'đầm linen trắng ngà dáng suông'), BrandLearning::DECISION_REJECTED);
        app(BrandLearningService::class)->record($this->shot($u, 'đầm linen trắng ngà dáng rộng'), BrandLearning::DECISION_APPROVED);

        foreach (BrandLearning::all() as $row) {
            $this->assertSame(BrandLearningService::DEFAULT_WEIGHT, $row->weight, 'Khác quyết định thì không củng cố nhau.');
            $this->assertSame(1, $row->hits);
        }
    }

    // ── (e) preferences() THEO ĐỘ MẠNH ─────────────────────────────────────────────────────────

    public function test_preferences_rank_by_strength_not_recency(): void
    {
        $u = $this->customer();

        // Ký ức CŨ NHẤT nhưng mạnh nhất — đây là "gu" đã đúng suốt nhiều tháng.
        $this->memory($u, 'đầm linen trắng ngà dáng suông', BrandLearning::DECISION_APPROVED, ['weight' => 10, 'hits' => 6]);

        // Rồi một buổi duyệt 12 ảnh mới (weight mặc định) — bản cũ lấy "mới nhất" sẽ ĐẨY ký ức mạnh ra ngoài.
        for ($i = 1; $i <= 12; $i++) {
            $this->memory($u, 'mẫu mới '.$i, BrandLearning::DECISION_APPROVED);
        }

        $prefs = app(BrandLearningService::class)->preferences($u, limitApproved: 3);

        $this->assertSame('đầm linen trắng ngà dáng suông', $prefs['approved'][0], 'Ký ức MẠNH NHẤT phải đứng đầu.');
    }

    // ── (f)(g) SUY YẾU + QUÊN ──────────────────────────────────────────────────────────────────

    public function test_decay_weakens_stale_memories_and_never_goes_below_the_floor(): void
    {
        $u = $this->customer();

        $stale = $this->memory($u, 'ký ức cũ', BrandLearning::DECISION_APPROVED, ['weight' => 5]);
        $stale->forceFill(['created_at' => now()->subDays(BrandLearningService::DECAY_AFTER_DAYS + 5)])->save();

        $fresh = $this->memory($u, 'ký ức mới', BrandLearning::DECISION_APPROVED, ['weight' => 5]);

        $result = app(BrandLearningService::class)->decay();

        $this->assertSame(1, $result['decayed']);
        $this->assertSame(4, $stale->fresh()->weight, 'Ký ức để lâu không dùng phải yếu đi.');
        $this->assertSame(5, $fresh->fresh()->weight, 'Ký ức mới thì chưa đụng tới.');

        // Chạy nhiều lượt nữa: weight KHÔNG được xuống dưới sàn.
        for ($i = 0; $i < 8; $i++) {
            app(BrandLearningService::class)->decay();
        }
        $this->assertSame(BrandLearningService::WEIGHT_MIN, $stale->fresh()->weight, 'Có SÀN — không âm, không về 0.');
    }

    public function test_an_old_and_weak_memory_is_eventually_forgotten_but_a_strong_one_is_kept(): void
    {
        $u = $this->customer();

        $weak = $this->memory($u, 'ký ức yếu đã cũ', BrandLearning::DECISION_APPROVED, ['weight' => BrandLearningService::WEIGHT_MIN]);
        $weak->forceFill(['created_at' => now()->subDays(BrandLearningService::FORGET_AFTER_DAYS + 10)])->save();

        $strong = $this->memory($u, 'ký ức mạnh cũng cũ', BrandLearning::DECISION_APPROVED, ['weight' => 9]);
        $strong->forceFill(['created_at' => now()->subDays(BrandLearningService::FORGET_AFTER_DAYS + 10)])->save();

        $result = app(BrandLearningService::class)->decay();

        $this->assertSame(1, $result['forgotten']);
        $this->assertNull($weak->fresh(), 'Yếu + đã cũ ⇒ QUÊN hẳn.');
        $this->assertNotNull($strong->fresh(), 'Mạnh thì KHÔNG quên dù đã cũ — nó chỉ yếu dần từng bước.');
    }

    /** (g) --dry-run chỉ đếm. */
    public function test_dry_run_changes_nothing(): void
    {
        $u = $this->customer();
        $row = $this->memory($u, 'ký ức cũ', BrandLearning::DECISION_APPROVED, ['weight' => 4]);
        $row->forceFill(['created_at' => now()->subDays(BrandLearningService::DECAY_AFTER_DAYS + 5)])->save();

        $result = app(BrandLearningService::class)->decay(dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['decayed'], 'Vẫn phải ĐẾM đúng để người vận hành biết trước.');
        $this->assertSame(4, $row->fresh()->weight, 'Chạy thử thì KHÔNG được ghi gì.');
    }

    public function test_the_consolidate_command_reports_before_and_after(): void
    {
        $u = $this->customer();
        $row = $this->memory($u, 'ký ức cũ', BrandLearning::DECISION_APPROVED, ['weight' => 3]);
        $row->forceFill(['created_at' => now()->subDays(BrandLearningService::DECAY_AFTER_DAYS + 1)])->save();

        $this->artisan('studio:memory:consolidate --dry-run')->assertExitCode(0);
        $this->assertSame(3, $row->fresh()->weight);

        $this->artisan('studio:memory:consolidate')->assertExitCode(0);
        $this->assertSame(2, $row->fresh()->weight);
    }

    public function test_the_command_is_quiet_on_an_empty_memory(): void
    {
        $this->artisan('studio:memory:consolidate')->assertExitCode(0);
        $this->assertSame(0, BrandLearning::count());
    }

    // ── (h) LƯỚI BẮT LỖI INJECT ────────────────────────────────────────────────────────────────

    public function test_the_strongest_memory_reaches_the_prompt_through_the_container(): void
    {
        $u = $this->customer();
        $this->memory($u, 'đầm linen trắng ngà dáng suông', BrandLearning::DECISION_APPROVED, [
            'weight' => 10,
            'lesson' => 'Shop chuộng linen trắng ngà, dáng suông.',
        ]);
        $this->memory($u, 'mẫu mới hơn', BrandLearning::DECISION_APPROVED);

        $signal = app(\App\Services\DesignAgentService::class)->radar($u, 'all', false)['internal_brand_signal'];

        $this->assertSame('đầm linen trắng ngà dáng suông', $signal['brand_memory']['approved'][0] ?? null);
        $this->assertSame('Shop chuộng linen trắng ngà, dáng suông.', $signal['brand_memory']['lessons']['approved'][0] ?? null);
    }
}
