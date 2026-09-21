<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * QUYỀN TUỲ CHỌN CỦA NGƯỜI DÙNG trong Agent Studio (2026-09-25).
 *
 * Bốn thứ trước đây THUẬT TOÁN quyết và người dùng chỉ đọc được:
 *   (a) TỔNG SỐ SKU — cơ cấu danh mục do máy chủ tính, không có ô nào để đổi;
 *   (b) BẢNG SIZE — chỉ có 3 preset cứng, không thêm/bớt size nào;
 *   (c) BẢNG MÀU — do máy chủ chọn;
 *   (d) BẢNG MOOD — lưới màu để NHÌN: sửa ô không đổi được prompt ảnh nào.
 *
 * Bất biến khoá ở đây:
 *   1. Tổng SKU người dùng chọn phải THẮNG, và cơ cấu danh mục giữ nguyên hình dạng (chia theo tỉ lệ
 *      + làm tròn phần dư) — không phải ghi đè một nhóm rồi để tổng lệch.
 *   2. Bảng size người dùng đặt đi thẳng vào brief, ĐÚNG THỨ TỰ họ nhập, và là thứ kế hoạch sản xuất đọc.
 *   3. Bảng màu + bảng mood người dùng sửa đi vào PROMPT (đây là phần làm bảng mood "hoạt động thật").
 *   4. Mỗi MẪU một prompt khác nhau — 12 mẫu dùng chung một prompt là 12 ảnh giống nhau.
 *   5. Không có model nào chạy thì mẫu VẪN có prompt dùng được ngay (tất định), không chặn giữa việc.
 */
class AgentStudioOptionsTest extends TestCase
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

    private function brief(array $extra = []): array
    {
        return $this->actingAs($this->customer())
            ->postJson('/api/design-agent/collection', array_merge([
                'prompt' => 'Bộ sưu tập linen pastel cho nữ công sở',
                'region' => 'all',
                'ai' => false,
            ], $extra))
            ->assertOk()
            ->json();
    }

    // ── (a) TỔNG SKU ────────────────────────────────────────────────────────────────

    public function test_the_owner_can_choose_the_total_sku_count_and_the_mix_keeps_its_shape(): void
    {
        $auto = $this->brief();
        $chosen = $this->brief(['sku_total' => 18]);

        $this->assertSame('system', $auto['structure']['total_skus_source']);
        $this->assertSame('owner', $chosen['structure']['total_skus_source']);

        // Tổng ĐÚNG BẰNG số đã chọn, và bằng tổng số mã của từng nhóm — hai con số không được lệch.
        $this->assertSame(18, $chosen['structure']['total_skus']);
        $this->assertSame(18, array_sum(array_column($chosen['structure']['categories'], 'count')));

        // Hình dạng cơ cấu giữ nguyên: nhóm nào nhiều mã nhất vẫn nhiều nhất.
        $before = collect($auto['structure']['categories'])->sortByDesc('count')->pluck('category')->all();
        $after = collect($chosen['structure']['categories'])->sortByDesc('count')->pluck('category')->all();
        $this->assertSame($before, $after, 'Đổi tổng SKU mà đảo thứ tự nhóm là đổi cả cơ cấu, không chỉ đổi quy mô.');

        // Không nhóm nào bị bỏ rơi về 0 mã — lệnh cắt thiếu hẳn một nhóm là lỗi sản xuất.
        foreach ($chosen['structure']['categories'] as $row) {
            $this->assertGreaterThan(0, (int) $row['count'], 'Nhóm '.$row['category'].' bị chia về 0 mã.');
        }
    }

    public function test_the_chosen_total_survives_the_plan(): void
    {
        $plan = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/plan', [
                'prompt' => 'Bộ sưu tập linen pastel cho nữ công sở',
                'region' => 'all',
                'sku_total' => 9,
                'assumptions' => ['units_per_sku' => 10],
            ])
            ->assertOk();

        // Lệnh cắt phải cộng ra ĐÚNG 9 mã × 10 cái — con số tiền phải bám ô người dùng vừa chọn.
        $lines = $plan->json('plan.cut_lines');
        $this->assertNotEmpty($lines);
        $this->assertSame(90, array_sum(array_column($lines, 'qty')));
    }

    // ── (b) BẢNG SIZE ───────────────────────────────────────────────────────────────

    public function test_the_owner_can_set_a_full_size_chart_and_the_order_is_kept(): void
    {
        $brief = $this->brief(['size_distribution' => ['XXS' => 4, 'XS' => 8, 'S' => 20, 'M' => 30, 'L' => 24, 'XL' => 14]]);

        $sizes = array_column($brief['size_distribution'], 'size');
        $this->assertSame(['XXS', 'XS', 'S', 'M', 'L', 'XL'], $sizes, 'Bảng size phải theo đúng thứ tự người dùng nhập (chuẩn XXS→XL).');

        $bySize = collect($brief['size_distribution'])->keyBy('size');
        $this->assertSame(30, $bySize['M']['count']);
        $this->assertSame(100, array_sum(array_column($brief['size_distribution'], 'share')));

        // Bỏ size nào thì size đó KHÔNG được tự quay lại (hệ thống không được thêm lại size đã bỏ).
        $this->assertFalse($bySize->has('XXL'));
    }

    public function test_a_size_only_chart_replaces_the_builtin_presets_entirely(): void
    {
        $brief = $this->brief(['size_distribution' => ['Freesize' => 50, 'M' => 50]]);

        $this->assertCount(2, $brief['size_distribution']);
        // Mã size được CHUẨN HOÁ HOA (đúng như bảng hệ số size của xưởng: XS/S/M/L/XL) — size lạ xếp
        // SAU các size chuẩn, và chỉ có đúng những size người dùng đặt.
        $this->assertSame(['M', 'FREESIZE'], array_column($brief['size_distribution'], 'size'));
    }

    // ── (c)+(d) BẢNG MÀU & BẢNG MOOD ĐI VÀO PROMPT ──────────────────────────────────

    public function test_the_owner_palette_and_moodboard_reach_the_image_prompt(): void
    {
        $brief = $this->brief([
            'palette' => [
                ['name' => 'Xanh rêu', 'hex' => '#2F4F3E', 'role' => 'Chủ đạo'],
                ['name' => 'Kem ngà', 'hex' => '#F3ECDD', 'role' => 'Nền'],
            ],
            'moodboard' => [
                ['id' => 'm1', 'label' => 'Bề mặt', 'caption' => 'linen thô, nhăn tự nhiên', 'color' => '#2F4F3E'],
                ['id' => 'm2', 'label' => 'Dáng', 'caption' => 'suông rộng, vai mềm', 'color' => '#F3ECDD'],
            ],
        ]);

        // Bảng màu + bảng mood của NGƯỜI DÙNG phải là thứ được echo về, không phải bản hệ thống tự nghĩ.
        $this->assertSame(['#2F4F3E', '#F3ECDD'], array_column($brief['palette'], 'hex'));
        $this->assertSame(['m1', 'm2'], array_column($brief['moodboard']['items'], 'id'));
        $this->assertSame('owner', $brief['moodboard']['items'][0]['source']);

        // VÀ đi vào prompt ảnh — đây là điều làm bảng mood "hoạt động thật" thay vì chỉ để nhìn.
        $this->assertStringContainsString('linen thô, nhăn tự nhiên', $brief['prompt_vi']);
        $this->assertStringContainsString('suông rộng, vai mềm', $brief['prompt_vi']);
        $this->assertStringContainsString('linen thô, nhăn tự nhiên', $brief['prompt_en']);
    }

    public function test_an_empty_owner_moodboard_falls_back_to_the_generated_one(): void
    {
        $brief = $this->brief(['moodboard' => []]);

        $this->assertSame(24, $brief['moodboard']['count'], 'Không đặt gì thì vẫn phải có bảng mood của hệ thống.');
    }

    // ── (e) PROMPT CHO TỪNG MẪU ─────────────────────────────────────────────────────

    public function test_each_sample_gets_its_own_prompt_without_any_model(): void
    {
        $base = [
            'prompt' => 'Bộ sưu tập linen pastel cho nữ công sở',
            'region' => 'all',
            'ai' => false,
            'moodboard' => [
                ['id' => 'm1', 'label' => 'Bề mặt', 'caption' => 'linen thô', 'color' => '#2F4F3E'],
            ],
        ];

        $one = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/sample-prompt', $base + [
                'sample' => ['id' => 'sku-1', 'name' => 'Áo linen tay dài', 'category' => 'Áo / blouse', 'size' => 'M', 'index' => 1, 'total' => 12],
            ])->assertOk();

        $two = $this->actingAs($this->customer())
            ->postJson('/api/design-agent/sample-prompt', $base + [
                'sample' => ['id' => 'sku-2', 'name' => 'Quần ống rộng', 'category' => 'Quần', 'size' => 'L', 'index' => 2, 'total' => 12],
            ])->assertOk();

        // Không có model nào chạy ⇒ vẫn phải có prompt dùng được ngay, và nói THẬT là bản tất định.
        $one->assertJsonPath('model.mode', 'rule');
        $this->assertNotSame('', $one->json('prompt_vi'));
        $this->assertNotSame('', $one->json('prompt_en'));

        // 12 mẫu dùng chung một prompt là 12 ảnh giống nhau — mỗi mẫu phải khác.
        $this->assertNotSame($one->json('prompt_vi'), $two->json('prompt_vi'));
        $this->assertNotSame($one->json('photo_context'), $two->json('photo_context'),
            'Bối cảnh chụp phải luân phiên, nếu không lookbook chỉ có một kiểu ảnh.');

        // Nội dung phải bám ĐÚNG mẫu và ĐÚNG bảng mood người dùng đặt.
        $this->assertStringContainsString('Áo linen tay dài', $one->json('prompt_vi'));
        $this->assertStringContainsString('size M', $one->json('prompt_vi'));
        $this->assertStringContainsString('linen thô', $one->json('prompt_vi'));
        $this->assertStringContainsString('Quần ống rộng', $two->json('prompt_vi'));
    }

    public function test_the_sample_endpoint_rejects_broken_input(): void
    {
        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/sample-prompt', ['prompt' => 'Bộ sưu tập linen'])
            ->assertStatus(422);

        $this->actingAs($this->customer())
            ->postJson('/api/design-agent/sample-prompt', [
                'prompt' => 'Bộ sưu tập linen',
                'palette' => [['name' => 'Sai', 'hex' => 'khong-phai-ma-mau']],
                'sample' => ['id' => 'sku-1', 'index' => 1],
            ])
            ->assertStatus(422);
    }
}
