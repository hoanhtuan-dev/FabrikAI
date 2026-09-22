<?php

namespace Tests\Feature;

use App\Models\BrandLearning;
use App\Models\BrandRule;
use App\Models\User;
use App\Services\BrandRuleService;
use App\Services\DesignAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TRÍ NHỚ THỦ TỤC (Procedural Memory — GĐ2): quy tắc "khi <tình huống> thì <cách làm>".
 *
 * Khoá sáu bất biến:
 *   (a) chuẩn hoá THUẦN: bỏ dòng thiếu một vế và ĐẾM lại — không im lặng nuốt chữ người dùng gõ;
 *   (b) ghi đè đúng tập hợp đang thấy: hàng có id thì sửa TẠI CHỖ (id không đổi), hàng mới thì tạo,
 *       hàng vắng mặt thì xoá;
 *   (c) quy tắc là CỦA RIÊNG một tài khoản — không đọc/ghi được của người khác;
 *   (d) trần MAX_RULES chặn và NÓI RA là đã chặn;
 *   (e) active() chỉ trả hàng đang bật, xếp theo weight giảm dần;
 *   (f) ⚠️ QUAN TRỌNG NHẤT — quy tắc VÀ trí nhớ dài hạn phải tới được prompt qua ĐƯỜNG CONTAINER.
 *       Bài này là lưới bắt lỗi inject: bản trước khai tham số trí nhớ là nullable-có-default nên
 *       Container::resolveClass() trả về default null ⇒ cả hai khối trí nhớ LUÔN rỗng trong production,
 *       trong khi mọi bài test gọi service TRỰC TIẾP vẫn xanh.
 */
class BrandRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
        // Chặn mọi lượt đi mạng: radar có thể chạm trình kết nối nguồn ngoài, mà bài này không đo việc đó.
        Http::fake();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function rule(string $trigger, string $action, array $extra = []): array
    {
        return array_merge(['trigger' => $trigger, 'action' => $action], $extra);
    }

    // ── (a) CHUẨN HOÁ THUẦN ────────────────────────────────────────────────────────────────────

    public function test_normalize_drops_half_filled_rows_and_counts_them(): void
    {
        $result = BrandRuleService::normalizeRows([
            $this->rule('  đồ công sở  ', '  ưu tiên màu trung tính, chất ít nhăn  '),
            ['trigger' => 'chỉ có vế này'],
            ['action' => 'chỉ có vế này'],
            'không phải mảng',
            $this->rule('đồ công sở', 'ưu tiên màu trung tính, chất ít nhăn'),
        ]);

        $this->assertCount(1, $result['rows'], 'Chỉ giữ quy tắc có ĐỦ hai vế, và khử trùng.');
        $this->assertSame('đồ công sở', $result['rows'][0]['trigger']);
        $this->assertSame('ưu tiên màu trung tính, chất ít nhăn', $result['rows'][0]['action']);
        $this->assertSame(4, $result['dropped'], 'Ba dòng thiếu vế + một dòng trùng phải được ĐẾM, không nuốt im lặng.');
        $this->assertSame(0, $result['truncated']);
    }

    public function test_normalize_clamps_weight_and_whitelists_source(): void
    {
        $result = BrandRuleService::normalizeRows([
            $this->rule('a', 'b', ['weight' => 999, 'source' => 'hacker']),
            $this->rule('c', 'd', ['weight' => -5, 'source' => BrandRule::SOURCE_LEARNED]),
        ]);

        $this->assertSame(BrandRuleService::WEIGHT_MAX, $result['rows'][0]['weight']);
        $this->assertSame(BrandRule::SOURCE_OWNER, $result['rows'][0]['source']);
        $this->assertSame(BrandRuleService::WEIGHT_MIN, $result['rows'][1]['weight']);
        $this->assertSame(BrandRule::SOURCE_LEARNED, $result['rows'][1]['source']);
    }

    // ── (b) GHI ĐÈ ĐÚNG TẬP HỢP ĐANG THẤY ──────────────────────────────────────────────────────

    public function test_save_updates_in_place_creates_new_and_prunes_the_missing(): void
    {
        $user = $this->customer();
        $service = app(BrandRuleService::class);

        $first = $service->save($user, [
            $this->rule('đồ công sở', 'ưu tiên màu trung tính'),
            $this->rule('đồ dự tiệc', 'ưu tiên chất liệu rũ, màu đậm'),
        ]);
        $this->assertCount(2, $first['rules']);
        $keptId = $first['rules'][0]['id'];
        $droppedId = $first['rules'][1]['id'];

        $second = $service->save($user, [
            ['id' => $keptId, 'trigger' => 'đồ công sở', 'action' => 'màu trung tính, chất ÍT NHĂN'],
            $this->rule('đồ mùa hè', 'ưu tiên linen, phom rộng'),
        ]);

        $this->assertCount(2, $second['rules']);
        $byId = collect($second['rules'])->keyBy('id');
        $this->assertTrue($byId->has($keptId), 'Hàng có id phải được SỬA TẠI CHỖ, không tạo hàng mới.');
        $this->assertSame('màu trung tính, chất ÍT NHĂN', $byId[$keptId]['action']);
        $this->assertFalse($byId->has($droppedId), 'Hàng vắng mặt trong payload = người dùng đã xoá.');
    }

    public function test_saving_an_empty_list_clears_everything(): void
    {
        $user = $this->customer();
        $service = app(BrandRuleService::class);
        $service->save($user, [$this->rule('a', 'b')]);
        $this->assertSame(1, BrandRule::where('user_id', $user->id)->count());

        $service->save($user, []);

        $this->assertSame(0, BrandRule::where('user_id', $user->id)->count());
    }

    // ── (c) CỦA RIÊNG MỘT TÀI KHOẢN ────────────────────────────────────────────────────────────

    public function test_rules_are_scoped_to_the_owner(): void
    {
        $mine = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $service = app(BrandRuleService::class);

        $service->save($mine, [$this->rule('của tôi', 'chỉ tôi thấy')]);
        $service->save($other, [$this->rule('của người khác', 'không được lộ')]);

        $this->assertCount(1, $service->all($mine));
        $this->assertSame('của tôi', $service->all($mine)[0]['trigger']);

        // Ghi của tôi KHÔNG được đụng hàng của người khác.
        $service->save($mine, []);
        $this->assertSame(1, BrandRule::where('user_id', $other->id)->count());
    }

    public function test_api_cannot_read_or_write_another_users_rules(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        BrandRule::create(['user_id' => $other->id, 'trigger' => 'bí mật', 'action' => 'của người khác']);

        $this->getJson('/api/brand-rules')->assertStatus(401);

        $mine = $this->actingAs($this->customer())->getJson('/api/brand-rules')->assertOk();
        $this->assertSame([], $mine->json('rules'));
    }

    // ── (d) TRẦN ───────────────────────────────────────────────────────────────────────────────

    public function test_max_rules_cap_is_enforced_and_reported(): void
    {
        $rows = [];
        for ($i = 1; $i <= BrandRuleService::MAX_RULES + 3; $i++) {
            $rows[] = $this->rule('tình huống '.$i, 'cách làm '.$i);
        }

        $saved = app(BrandRuleService::class)->save($this->customer(), $rows);

        $this->assertCount(BrandRuleService::MAX_RULES, $saved['rules']);
        $this->assertSame(3, $saved['truncated'], 'Vượt trần phải NÓI RA, không cắt im lặng.');
    }

    // ── (e) ĐƯỜNG ĐỌC CHO PROMPT ───────────────────────────────────────────────────────────────

    public function test_active_returns_only_enabled_rules_ordered_by_weight(): void
    {
        $user = $this->customer();
        $service = app(BrandRuleService::class);
        $service->save($user, [
            $this->rule('nhẹ', 'weight thấp', ['weight' => 2]),
            $this->rule('nặng', 'weight cao', ['weight' => 9]),
            $this->rule('đang tắt', 'không được vào prompt', ['weight' => 10, 'is_active' => false]),
        ]);

        $active = $service->active($user);

        $this->assertCount(2, $active);
        $this->assertSame('weight cao', $active[0]['action'], 'Weight cao phải đứng trước.');
        $this->assertArrayNotHasKey('id', $active[0], 'Prompt chỉ cần chữ + trọng số.');
    }

    // ── (f) LƯỚI BẮT LỖI INJECT — bài quan trọng nhất của tệp này ───────────────────────────────

    public function test_rules_and_memory_reach_the_prompt_through_the_container(): void
    {
        $user = $this->customer();

        BrandLearning::create([
            'user_id' => $user->id,
            'decision' => BrandLearning::DECISION_APPROVED,
            'prompt' => 'đầm linen trắng ngà dáng suông',
            'source' => 'shot_review',
        ]);
        app(BrandRuleService::class)->save($user, [
            $this->rule('đồ công sở', 'ưu tiên màu trung tính, chất liệu ít nhăn'),
        ]);

        // ĐI QUA CONTAINER (không tự new) — đây chính là chỗ lỗi inject lộ ra.
        $signal = app(DesignAgentService::class)->radar($user, 'all', false)['internal_brand_signal'];

        $this->assertSame(
            'đầm linen trắng ngà dáng suông',
            $signal['brand_memory']['approved'][0] ?? null,
            'Trí nhớ dài hạn (GĐ1) phải tới được prompt: tham số nullable-có-default sẽ khiến khối này RỖNG.'
        );
        $this->assertSame(
            'ưu tiên màu trung tính, chất liệu ít nhăn',
            $signal['brand_rules'][0]['action'] ?? null,
            'Trí nhớ thủ tục (GĐ2) phải tới được prompt qua container.'
        );
        $this->assertSame('đồ công sở', $signal['brand_rules'][0]['trigger'] ?? null);
    }

    public function test_anonymous_radar_still_carries_the_memory_keys(): void
    {
        // Hợp đồng: hai khối trí nhớ phải CÓ MẶT (rỗng) cả khi chưa đăng nhập — thiếu khoá là
        // "Undefined array key" ở nơi đọc, đúng lớp lỗi mà nhánh này được viết ra để chặn.
        $signal = app(DesignAgentService::class)->radar(null, 'all', false)['internal_brand_signal'];

        $this->assertSame([], $signal['brand_rules']);
        $this->assertSame(['approved' => [], 'rejected' => [], 'lessons' => ['approved' => [], 'rejected' => []]], $signal['brand_memory']);
    }

    // ── API ────────────────────────────────────────────────────────────────────────────────────

    public function test_api_round_trip_reports_what_it_dropped(): void
    {
        $user = $this->customer();

        $put = $this->actingAs($user)->putJson('/api/brand-rules', [
            'rules' => [
                ['trigger' => 'đồ công sở', 'action' => 'màu trung tính'],
                ['trigger' => 'thiếu vế kia'],
            ],
        ])->assertOk();

        $this->assertSame(1, $put->json('dropped'));
        $this->assertCount(1, $put->json('rules'));

        $get = $this->actingAs($user)->getJson('/api/brand-rules')->assertOk();
        $this->assertSame('đồ công sở', $get->json('rules.0.trigger'));
        $this->assertSame(BrandRuleService::MAX_RULES, $get->json('limits.max_rules'));

        $this->actingAs($user)->deleteJson('/api/brand-rules')->assertOk();
        $this->assertSame(0, BrandRule::where('user_id', $user->id)->count());
    }

    public function test_api_rejects_oversized_trigger(): void
    {
        $this->actingAs($this->customer())->putJson('/api/brand-rules', [
            'rules' => [['trigger' => str_repeat('x', BrandRuleService::TRIGGER_MAX + 1), 'action' => 'ok']],
        ])->assertStatus(422)->assertJsonValidationErrors('rules.0.trigger');
    }
}
