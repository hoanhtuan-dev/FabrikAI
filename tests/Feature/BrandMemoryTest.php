<?php

namespace Tests\Feature;

use App\Models\BrandLearning;
use App\Models\User;
use App\Services\BrandLearningService;
use App\Support\Vocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TRÍ NHỚ ĐÃ HỌC — màn hình đọc lại + quên (2026-09-26).
 *
 * Khoá bảy bất biến:
 *   (a) CHỈ thấy ký ức của chính mình (lọc theo tài khoản, không nhận id người khác);
 *   (b) XẾP THEO ĐỘ MẠNH rồi id — đúng thứ tự brief đọc, màn hình không nói khác thứ agent dùng;
 *   (c) QUÊN được một ký ức SAI, và chỉ xoá đúng dòng đó;
 *   (d) xoá ký ức của người khác ⇒ 404 (không xác nhận là nó tồn tại);
 *   (e) số liệu (stats) khớp với danh sách sau mỗi lần xoá — không tự trừ tay ở máy khách;
 *   (f) trần số dòng trả về có hiệu lực, và tham số limit không vượt được trần;
 *   (g) phép TÁCH TỪ dùng chung (App\Support\Vocabulary) giữ nguyên hành vi cũ của
 *       BrandLearningService::similarity — tách ra mà đổi kết quả thì mọi ngưỡng trùng đều lệch.
 */
class BrandMemoryTest extends TestCase
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

    private function memory(User $u, array $attrs = []): BrandLearning
    {
        return BrandLearning::create(array_merge([
            'user_id' => $u->id,
            'decision' => BrandLearning::DECISION_APPROVED,
            'prompt' => 'đầm linen trắng ngà dáng suông',
            'lesson' => 'Shop theo đuổi tối giản, ưu tiên màu trắng ngà và dáng suông.',
            'weight' => BrandLearning::DEFAULT_WEIGHT,
            'hits' => 0,
        ], $attrs));
    }

    // ── (a)(b)(e)(f) ĐỌC ────────────────────────────────────────────────────────────────────

    public function test_a_guest_is_blocked(): void
    {
        $this->getJson('/api/brand-memory')->assertStatus(401);
    }

    public function test_it_returns_only_my_memories_ordered_by_strength(): void
    {
        $me = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $weak = $this->memory($me, ['weight' => 2, 'lesson' => 'Bài học yếu']);
        $strongOld = $this->memory($me, ['weight' => 9, 'lesson' => 'Bài học mạnh cũ']);
        $strongNew = $this->memory($me, ['weight' => 9, 'lesson' => 'Bài học mạnh mới']);
        $this->memory($other, ['weight' => 10, 'lesson' => 'Ký ức của người khác']);

        $res = $this->actingAs($me)->getJson('/api/brand-memory')->assertStatus(200);

        $this->assertSame(
            [$strongNew->id, $strongOld->id, $weak->id],
            array_column($res->json('items'), 'id'),
            'Mạnh trước, cùng độ mạnh thì mới nhất trước — đúng thứ tự brief đọc.',
        );
        $this->assertSame(3, $res->json('stats.total'), 'Không được đếm ký ức của tài khoản khác.');
    }

    public function test_the_shape_carries_what_the_screen_needs(): void
    {
        $me = $this->customer();
        $this->memory($me, ['weight' => 9, 'hits' => 3, 'source' => 'gemini · flash']);
        $this->memory($me, ['decision' => BrandLearning::DECISION_REJECTED, 'weight' => 2, 'lesson' => '']);

        $res = $this->actingAs($me)->getJson('/api/brand-memory')->assertStatus(200);
        $items = collect($res->json('items'))->keyBy('weight');

        $this->assertSame('Mạnh', $items[9]['strength_label']);
        $this->assertSame('Đã duyệt', $items[9]['decision_label']);
        $this->assertSame('Đã loại', $items[2]['decision_label']);
        $this->assertSame('Yếu', $items[2]['strength_label']);
        $this->assertSame(3, $items[9]['hits']);
        $this->assertSame('gemini · flash', $items[9]['source']);
        $this->assertNotNull($items[9]['created_at_label']);
        $this->assertSame(0, $items[9]['age_days']);

        $stats = $res->json('stats');
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['with_lesson'], 'Chỉ đếm ký ức ĐÃ có bài học.');
        $this->assertSame(1, $stats['strong']);
        $this->assertSame(1, $stats['weak']);
        $this->assertSame(5.5, $stats['avg_weight']);
    }

    /** Prompt dài bị cắt để một ký ức không chiếm cả màn hình — nhưng PHẢI có dấu hiệu bị cắt. */
    public function test_a_long_prompt_is_truncated_with_a_marker(): void
    {
        $me = $this->customer();
        $this->memory($me, ['prompt' => str_repeat('a', 400)]);

        $prompt = $this->actingAs($me)->getJson('/api/brand-memory')->json('items.0.prompt');

        $this->assertSame(300, mb_strlen(rtrim($prompt, '…')), 'Cắt ở 300 ký tự.');
        $this->assertStringEndsWith('…', $prompt, 'Cắt mà không báo là đọc như prompt thật.');
    }

    public function test_the_limit_is_clamped_to_the_cap(): void
    {
        $me = $this->customer();
        for ($i = 0; $i < 3; $i++) {
            $this->memory($me);
        }

        $this->assertCount(2, $this->actingAs($me)->getJson('/api/brand-memory?limit=2')->json('items'));
        $this->assertCount(3, $this->actingAs($me)->getJson('/api/brand-memory?limit=9999')->json('items'));
        $this->assertSame(BrandLearningService::MEMORY_MAX, $this->actingAs($me)->getJson('/api/brand-memory')->json('limits.max'));
    }

    // ── (c)(d) QUÊN ─────────────────────────────────────────────────────────────────────────

    public function test_i_can_forget_one_wrong_lesson(): void
    {
        $me = $this->customer();
        $wrong = $this->memory($me, ['lesson' => 'Bài học hiểu sai']);
        $keep = $this->memory($me, ['lesson' => 'Bài học đúng']);

        $res = $this->actingAs($me)->deleteJson('/api/brand-memory/'.$wrong->id)->assertStatus(200);

        $this->assertSame([$keep->id], array_column($res->json('items'), 'id'));
        $this->assertSame(1, $res->json('stats.total'), 'Số liệu trả về phải là số liệu SAU khi xoá.');
        $this->assertNull(BrandLearning::query()->find($wrong->id));
        $this->assertNotNull(BrandLearning::query()->find($keep->id), 'Chỉ xoá đúng dòng được chọn.');
    }

    public function test_i_cannot_forget_someone_elses_memory(): void
    {
        $me = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $theirs = $this->memory($other, ['lesson' => 'Ký ức của người khác']);

        $this->actingAs($me)->deleteJson('/api/brand-memory/'.$theirs->id)->assertStatus(404);

        $this->assertNotNull(BrandLearning::query()->find($theirs->id), 'Ký ức của người khác phải nguyên vẹn.');
    }

    // ── (g) PHÉP TÁCH TỪ DÙNG CHUNG ─────────────────────────────────────────────────────────

    public function test_the_shared_tokenizer_keeps_the_old_behaviour(): void
    {
        // Từ ngắn nhưng mang nghĩa trong ngành phải GIỮ (ngưỡng 2 ký tự, không phải 3).
        $this->assertSame(['áo', 'sơ', 'mi', 'linen'], Vocabulary::tokens('Áo sơ mi linen'));
        // Từ đệm phải bỏ — nếu không thì hai prompt khác hẳn nhau vẫn "trùng".
        $this->assertSame(['đầm', 'linen'], Vocabulary::tokens('đầm và của linen cho với'));
        $this->assertSame([], Vocabulary::tokens('và của cho'));

        // similarity() nay uỷ quyền cho Vocabulary — tách ra mà đổi kết quả là mọi ngưỡng trùng đều lệch.
        $pair = ['đầm linen trắng ngà dáng suông', 'đầm linen trắng ngà dáng rộng'];
        $this->assertSame(Vocabulary::jaccard($pair[0], $pair[1]), BrandLearningService::similarity($pair[0], $pair[1]));

        // Ngưỡng 0,6 vẫn tách đúng hai chuyện: cùng phong cách (khớp) vs khác loại hàng (không khớp).
        $this->assertGreaterThan(BrandLearningService::SIMILARITY_THRESHOLD, BrandLearningService::similarity(...$pair));
        $this->assertLessThan(
            BrandLearningService::SIMILARITY_THRESHOLD,
            BrandLearningService::similarity('áo sơ mi linen form rộng', 'áo thun linen form rộng'),
        );
    }
}
