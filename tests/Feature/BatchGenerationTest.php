<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TẠO HÀNG LOẠT (Đợt 3 — 2026-09-19).
 *
 * Việc thật của nhà thiết kế/chủ shop là ra ảnh cho CẢ BỘ (8 SKU × 2 bối cảnh). Trước đây phải sửa
 * prompt rồi bấm tạo từng lần; nay dán danh sách và bấm MỘT lần.
 *
 * Thiết kế: KHÔNG thêm endpoint mới — trình duyệt gọi tuần tự /api/generate cho từng mục, nên mỗi ảnh
 * vẫn đi đúng đường cũ (trừ credit theo gói, ghi sổ cái, hạ cap độ phân giải theo gói). Vì vậy bất
 * biến khoá ở đây gồm 2 phần:
 *   (a) HỢP ĐỒNG SERVER: một request với variants=N tạo đúng N generation và trừ đúng N × chi phí;
 *   (b) BẤT BIẾN TĨNH của phía giao diện: có hàm generateBatch dùng chung bộ dựng payload với tạo lẻ,
 *       và vẫn KHÔNG dùng setInterval (chống tái phát "tiến trình mô phỏng" — Đợt 0.2).
 */
class BatchGenerationTest extends TestCase
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

    public function test_one_request_with_variants_creates_that_many_generations_and_charges_each(): void
    {
        $u = $this->customer();
        $before = (int) $u->fresh()->credits_balance;

        $this->actingAs($u)
            ->postJson('/api/generate', ['prompt' => 'áo sơ mi linen trắng', 'variants' => 3])
            ->assertOk()
            ->assertJsonCount(3, 'items');

        $this->assertSame(3, Generation::where('user_id', $u->id)->count(), 'Mỗi biến thể là một generation riêng.');
        $this->assertSame($before - 3, (int) $u->fresh()->credits_balance, 'Trừ credit theo SỐ ẢNH, không phải theo số request.');
        $this->assertSame(3, CreditTransaction::where('user_id', $u->id)->where('type', CreditTransaction::TYPE_SPEND)->count());
    }

    public function test_a_batch_of_sequential_requests_behaves_like_the_ui_loop(): void
    {
        // Mô phỏng đúng cách generateBatch hoạt động: N request độc lập, mỗi request 1 mục × 2 biến thể.
        $u = $this->customer();
        $before = (int) $u->fresh()->credits_balance;
        $prompts = ['áo sơ mi linen trắng', 'quần tây ống suông đen', 'váy midi hoa nhí'];

        foreach ($prompts as $p) {
            $this->actingAs($u->fresh())
                ->postJson('/api/generate', ['prompt' => $p, 'variants' => 2])
                ->assertOk()
                ->assertJsonCount(2, 'items');
        }

        $this->assertSame(count($prompts) * 2, Generation::where('user_id', $u->id)->count());
        $this->assertSame($before - count($prompts) * 2, (int) $u->fresh()->credits_balance);

        // Mỗi mục giữ ĐÚNG prompt của nó: prompt lưu trong DB là prompt ĐÃ ENRICH (backend ghép
        // prefix/suffix/chỉ dẫn phom dáng) nên KHÔNG so khớp chuỗi thô — thay vào đó nhóm theo prompt
        // đã lưu: 3 mục ⇒ ĐÚNG 3 nhóm, mỗi nhóm ĐÚNG 2 ảnh (không trộn prompt giữa các mục).
        $groups = Generation::where('user_id', $u->id)->get()->groupBy('prompt');
        $this->assertCount(count($prompts), $groups, 'Ba mục phải cho ba prompt khác nhau.');
        foreach ($groups as $rows) {
            $this->assertCount(2, $rows, 'Mỗi mục phải tạo đúng số biến thể đã yêu cầu.');
        }
    }

    public function test_store_exposes_batch_generation_sharing_the_single_item_payload(): void
    {
        $store = (string) file_get_contents(resource_path('js/studio/store.js'));

        $this->assertStringContainsString('async generateBatch(', $store, 'Store phải có action tạo hàng loạt.');
        $this->assertStringContainsString('imagePayload(prompt, variants = 1)', $store, 'Phải có bộ dựng payload dùng CHUNG.');
        // Tạo lẻ và tạo hàng loạt phải dùng CÙNG một bộ dựng payload — lệch nhau là bug im lặng.
        $this->assertSame(2, substr_count($store, 'this.imagePayload('), 'Cả generateImage và generateBatch phải gọi imagePayload().');
        // Bất biến Đợt 0.2 vẫn nguyên: không có tiến trình mô phỏng bằng setInterval.
        $this->assertStringNotContainsString('setInterval(', $store, 'Không được quay lại bộ đếm % mô phỏng.');
    }

    public function test_concept_card_exposes_the_batch_tab_without_a_new_toolbar_entry(): void
    {
        $card = (string) file_get_contents(resource_path('js/studio/components/ConceptCard.vue'));

        $this->assertStringContainsString("id: 'batch'", $card, 'Card Tạo ảnh phải có tab "Hàng loạt".');
        $this->assertStringContainsString('store.generateBatch(', $card, 'Tab hàng loạt phải gọi action generateBatch.');
        $this->assertStringContainsString('BATCH_MAX_ITEMS', $card, 'Phải có giới hạn số mục mỗi lượt để không dội hàng đợi.');
        // Không thêm mục mới vào thanh công cụ trái (bất biến của StudioGuiConfig: đúng 10 mục, 7 panel).
        $gui = (string) file_get_contents(app_path('Services/StudioGuiConfig.php'));
        $this->assertStringNotContainsString("'batch'", $gui, 'Không thêm nút mới vào thanh công cụ — hàng loạt là tab trong card Tạo ảnh.');
    }
}
