<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Yêu cầu 2026-09-20] CATALOG TÙY CHỈNH CẤP TÀI KHOẢN — API lưu trên SERVER.
 *
 * Bối cảnh: /presets và /stylist-data trước đây lưu bản tùy chỉnh trong localStorage
 * (useLocalCatalog.js) với khoá kèm userId. Nay chuyển lên server theo tài khoản.
 *
 * Bất biến khoá ở file này, theo thứ tự quan trọng:
 *   (a) HAI TÀI KHOẢN KHÔNG THẤY DỮ LIỆU CỦA NHAU — đây là lý do tồn tại của cả tính năng;
 *       localStorage vốn đã tách theo user, nếu API không tách thì việc chuyển lên server là
 *       một bước LÙI về quyền riêng tư.
 *   (b) round-trip: PUT rồi GET trả về y hệt — frontend ghi rồi đọc lại ở lần tải trang sau.
 *   (c) whitelist tên catalog CỨNG ⇒ 404 (không phải 422): tên nằm trên đường dẫn.
 *   (d) dữ liệu sai cấu trúc ⇒ 422, kiểm ở SERVER chứ không tin client.
 *   (e) ghi nhiều lần vẫn chỉ MỘT hàng (UNIQUE user_id + name).
 */
class UserCatalogApiTest extends TestCase
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

    /** Một payload hợp lệ, đủ cả ba phần — dùng làm mốc cho các ca round-trip. */
    private function sample(): array
    {
        return [
            'custom' => [
                ['id' => 'local-1', 'name' => 'Preset của tôi', 'prompt' => 'áo dài trắng', 'sort_order' => 2],
                ['id' => 'local-2', 'name' => 'Preset thứ hai', 'prompt' => 'vest đen', 'sort_order' => 1],
            ],
            'edits' => [
                '7' => ['name' => 'Đã đổi tên'],
            ],
            'hidden' => ['3', '5'],
        ];
    }

    private function save(User $as, string $name, array $data)
    {
        return $this->actingAs($as)->putJson('/api/user-catalogs/'.$name, ['data' => $data]);
    }

    // ── (0) khách chưa đăng nhập ─────────────────────────────────────────

    public function test_guest_cannot_read_or_write(): void
    {
        $this->getJson('/api/user-catalogs/presets')->assertUnauthorized();
        $this->putJson('/api/user-catalogs/presets', ['data' => []])->assertUnauthorized();
    }

    // ── (a) hai user KHÔNG thấy dữ liệu của nhau ─────────────────────────

    public function test_two_users_do_not_see_each_others_data(): void
    {
        $a = $this->customer();
        $b = User::factory()->create();

        $this->save($a, 'presets', $this->sample())->assertOk();
        $this->save($b, 'presets', ['custom' => [['id' => 'b-1', 'name' => 'Của B']], 'hidden' => ['9']])->assertOk();

        $mine = $this->actingAs($a)->getJson('/api/user-catalogs/presets')->assertOk()->json('data');
        $theirs = $this->actingAs($b)->getJson('/api/user-catalogs/presets')->assertOk()->json('data');

        $this->assertSame($this->sample(), $mine, 'A phải đọc lại ĐÚNG bản của A.');
        $this->assertSame([['id' => 'b-1', 'name' => 'Của B']], $theirs['custom'], 'B phải đọc lại ĐÚNG bản của B.');
        $this->assertSame(['9'], $theirs['hidden']);
        $this->assertNotSame($mine, $theirs, 'Hai tài khoản KHÔNG được dùng chung một bản.');

        // Và ghi của B KHÔNG được đè lên hàng của A.
        $this->assertDatabaseHas('user_catalogs', ['user_id' => $a->id, 'name' => 'presets']);
        $this->assertDatabaseHas('user_catalogs', ['user_id' => $b->id, 'name' => 'presets']);
    }

    public function test_user_without_data_gets_an_empty_shape_not_another_users_row(): void
    {
        $a = $this->customer();
        $b = User::factory()->create();

        $this->save($a, 'stylist.types', $this->sample())->assertOk();

        // B chưa từng lưu: phải nhận shape RỖNG, tuyệt đối không phải bản của A.
        $resp = $this->actingAs($b)->getJson('/api/user-catalogs/stylist.types')->assertOk();
        $this->assertSame(UserCatalog::emptyData(), $resp->json('data'));
        $this->assertNull($resp->json('updated_at'));
    }

    // ── (b) GET khi chưa có dữ liệu ──────────────────────────────────────

    public function test_get_returns_empty_shape_and_null_timestamp_when_never_saved(): void
    {
        $resp = $this->actingAs($this->customer())->getJson('/api/user-catalogs/presets')->assertOk();

        $this->assertSame('presets', $resp->json('name'));
        $this->assertSame(['custom' => [], 'edits' => [], 'hidden' => []], $resp->json('data'));
        $this->assertNull($resp->json('updated_at'));
        $this->assertDatabaseCount('user_catalogs', 0);
    }

    public function test_get_defaults_missing_groups_to_empty(): void
    {
        $u = $this->customer();

        // Chỉ gửi custom: hai nhóm còn lại phải về rỗng chứ không thành null (frontend luôn lặp qua chúng).
        $this->save($u, 'presets', ['custom' => [['id' => 'x']]])->assertOk();

        $data = $this->actingAs($u)->getJson('/api/user-catalogs/presets')->assertOk()->json('data');
        $this->assertSame([['id' => 'x']], $data['custom']);
        $this->assertSame([], $data['edits']);
        $this->assertSame([], $data['hidden']);
    }

    // ── (c) round-trip PUT -> GET ────────────────────────────────────────

    public function test_put_then_get_round_trips_identical_data(): void
    {
        $u = $this->customer();

        $put = $this->save($u, 'presets', $this->sample())->assertOk();
        $this->assertSame($this->sample(), $put->json('data'), 'PUT phải trả về đúng dữ liệu đã lưu.');
        $this->assertNotNull($put->json('updated_at'));

        $got = $this->actingAs($u)->getJson('/api/user-catalogs/presets')->assertOk();
        $this->assertSame($this->sample(), $got->json('data'));
        $this->assertSame('presets', $got->json('name'));
    }

    public function test_round_trip_works_for_every_whitelisted_name(): void
    {
        $u = $this->customer();

        foreach (UserCatalog::NAMES as $name) {
            $this->save($u, $name, $this->sample())->assertOk();
            $this->assertSame($this->sample(),
                $this->actingAs($u)->getJson('/api/user-catalogs/'.$name)->assertOk()->json('data'),
                $name.' phải round-trip được.');
        }
    }

    public function test_edits_map_keys_survive_as_strings(): void
    {
        $u = $this->customer();

        // Khoá map id trong JSON luôn là chuỗi; nếu bị đổi thành mảng thì frontend tra edits[id] sẽ trượt.
        $this->save($u, 'stylist.questions', ['edits' => ['12' => ['q' => 'Câu hỏi mới'], 'abc' => ['q' => 'x']]])->assertOk();

        $edits = $this->actingAs($u)->getJson('/api/user-catalogs/stylist.questions')->assertOk()->json('data.edits');
        $this->assertSame(['q' => 'Câu hỏi mới'], $edits['12']);
        $this->assertSame(['q' => 'x'], $edits['abc']);
    }

    // ── (d) whitelist tên catalog ────────────────────────────────────────

    public function test_name_outside_whitelist_is_404_on_both_methods(): void
    {
        $u = $this->customer();

        foreach (['khong-ton-tai', 'presets2', 'stylist', 'settings'] as $bad) {
            $this->actingAs($u)->getJson('/api/user-catalogs/'.$bad)->assertNotFound();
            $this->save($u, $bad, $this->sample())->assertNotFound();
        }

        $this->assertDatabaseCount('user_catalogs', 0);
    }

    // ── (e) dữ liệu sai cấu trúc ⇒ 422 ───────────────────────────────────

    public function test_data_must_be_an_object(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->putJson('/api/user-catalogs/presets', ['data' => 'không phải object'])->assertStatus(422);
        $this->actingAs($u)->putJson('/api/user-catalogs/presets', ['data' => null])->assertStatus(422);
        $this->actingAs($u)->putJson('/api/user-catalogs/presets', [])->assertStatus(422);
    }

    public function test_custom_must_be_an_array(): void
    {
        $u = $this->customer();

        $this->save($u, 'presets', ['custom' => 'không phải mảng'])->assertStatus(422);
        $this->save($u, 'presets', ['custom' => 42])->assertStatus(422);
        $this->assertDatabaseCount('user_catalogs', 0);
    }

    public function test_every_custom_item_must_carry_a_non_empty_string_id(): void
    {
        $u = $this->customer();

        $this->save($u, 'presets', ['custom' => [['name' => 'thiếu id']]])->assertStatus(422);
        $this->save($u, 'presets', ['custom' => [['id' => '', 'name' => 'id rỗng']]])->assertStatus(422);
        $this->save($u, 'presets', ['custom' => [['id' => 123, 'name' => 'id không phải chuỗi']]])->assertStatus(422);
        $this->save($u, 'presets', ['custom' => [['id' => 'ok'], ['name' => 'phần tử thứ hai hỏng']]])->assertStatus(422);

        // Một mục hỏng thì KHÔNG được lưu gì cả (không lưu nửa vời).
        $this->assertDatabaseCount('user_catalogs', 0);
    }

    public function test_hidden_must_be_an_array(): void
    {
        $u = $this->customer();

        $this->save($u, 'presets', ['hidden' => 'không phải mảng'])->assertStatus(422);
        $this->assertDatabaseCount('user_catalogs', 0);
    }

    public function test_more_than_500_items_is_rejected(): void
    {
        $u = $this->customer();

        $custom = [];
        for ($i = 0; $i < 501; $i++) {
            $custom[] = ['id' => 'local-'.$i];
        }
        $this->save($u, 'presets', ['custom' => $custom])->assertStatus(422);

        $this->save($u, 'presets', ['hidden' => array_map(fn ($i) => (string) $i, range(0, 500))])->assertStatus(422);

        $edits = [];
        for ($i = 0; $i < 501; $i++) {
            $edits['e'.$i] = ['name' => 'x'];
        }
        $this->save($u, 'presets', ['edits' => $edits])->assertStatus(422);

        $this->assertDatabaseCount('user_catalogs', 0);
    }

    public function test_exactly_500_items_is_accepted(): void
    {
        $u = $this->customer();

        // Biên trên phải MỞ: chặn nhầm ở 500 là mất dữ liệu của người dùng hợp lệ.
        $custom = [];
        for ($i = 0; $i < 500; $i++) {
            $custom[] = ['id' => 'local-'.$i];
        }

        $this->save($u, 'presets', ['custom' => $custom])->assertOk();
        $this->assertCount(500, $this->actingAs($u)->getJson('/api/user-catalogs/presets')->json('data.custom'));
    }

    public function test_payload_larger_than_256kb_is_rejected(): void
    {
        $u = $this->customer();

        // 500 mục × ~600 byte ⇒ ~300 KB > 256 KB, nhưng vẫn dưới trần 500 phần tử nên chỉ có
        // phép đo KÍCH THƯỚC mới bắt được ca này.
        $blob = str_repeat('a', 600);
        $custom = [];
        for ($i = 0; $i < 500; $i++) {
            $custom[] = ['id' => 'local-'.$i, 'prompt' => $blob];
        }

        $this->save($u, 'presets', ['custom' => $custom])->assertStatus(422);
        $this->assertDatabaseCount('user_catalogs', 0);
    }

    public function test_data_well_under_the_size_limit_is_accepted(): void
    {
        $u = $this->customer();

        $this->save($u, 'presets', ['custom' => [['id' => 'big', 'prompt' => str_repeat('b', 20000)]]])->assertOk();
    }

    // ── (f) ghi nhiều lần ⇒ ĐÚNG MỘT hàng ────────────────────────────────

    public function test_repeated_writes_keep_exactly_one_row(): void
    {
        $u = $this->customer();

        for ($i = 0; $i < 5; $i++) {
            $this->save($u, 'presets', ['custom' => [['id' => 'v'.$i]]])->assertOk();
        }

        $this->assertSame(1, UserCatalog::where('user_id', $u->id)->where('name', 'presets')->count(),
            'Ghi đè nhiều lần phải chỉ để lại MỘT hàng (UNIQUE user_id + name).');
        $this->assertDatabaseCount('user_catalogs', 1);

        // Và nội dung là bản GHI SAU CÙNG.
        $this->assertSame([['id' => 'v4']], $this->actingAs($u)->getJson('/api/user-catalogs/presets')->json('data.custom'));
    }

    public function test_each_name_gets_its_own_row(): void
    {
        $u = $this->customer();

        foreach (UserCatalog::NAMES as $name) {
            $this->save($u, $name, $this->sample())->assertOk();
        }

        $this->assertSame(count(UserCatalog::NAMES), UserCatalog::where('user_id', $u->id)->count());
    }

    // ── (g) bảng: UNIQUE ở tầng DB + cascade theo tài khoản ──────────────

    public function test_unique_constraint_is_enforced_by_the_database(): void
    {
        $u = $this->customer();

        UserCatalog::create(['user_id' => $u->id, 'name' => 'presets', 'data' => UserCatalog::emptyData()]);

        // Ghi thẳng qua model (bỏ qua updateOrCreate của controller) phải bị DB chặn: ràng buộc
        // nằm ở schema, không phụ thuộc việc code có nhớ kiểm tra hay không.
        $this->expectException(\Illuminate\Database\QueryException::class);
        UserCatalog::create(['user_id' => $u->id, 'name' => 'presets', 'data' => UserCatalog::emptyData()]);
    }

    public function test_rows_are_deleted_with_the_account(): void
    {
        $u = User::factory()->create();
        $this->save($u, 'presets', $this->sample())->assertOk();
        $this->assertDatabaseCount('user_catalogs', 1);

        $u->delete();

        $this->assertDatabaseCount('user_catalogs', 0);
    }

    // ── (h) model: chuẩn hoá dữ liệu đọc từ DB ──────────────────────────

    public function test_for_user_normalises_junk_read_from_the_database(): void
    {
        $u = $this->customer();

        // Mô phỏng hàng do bản ghi cũ / ghi tay để lại: thiếu khoá, sai kiểu, phần tử rác.
        UserCatalog::create([
            'user_id' => $u->id,
            'name' => 'presets',
            'data' => ['custom' => [['id' => 'ok'], 'rác'], 'edits' => 'không phải map', 'hidden' => ['a', 5, null]],
        ]);

        $this->assertSame(
            ['custom' => [['id' => 'ok']], 'edits' => [], 'hidden' => ['a']],
            UserCatalog::forUser($u->id, 'presets'),
            'Dữ liệu hỏng phải được chuẩn hoá về shape 3 phần, không làm vỡ trang.'
        );
    }

    public function test_for_user_returns_empty_shape_for_unknown_user_or_name(): void
    {
        $u = $this->customer();
        $this->save($u, 'presets', $this->sample())->assertOk();

        $this->assertSame(UserCatalog::emptyData(), UserCatalog::forUser($u->id, 'stylist.types'));
        $this->assertSame(UserCatalog::emptyData(), UserCatalog::forUser($u->id + 9999, 'presets'));
    }
}
