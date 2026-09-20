<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KHÔNG GIAN LÀM VIỆC THEO NGHỀ — card "Bộ sưu tập" (Đợt 2 — 2026-09-19).
 *
 * Người làm nghề nghĩ theo BỘ SƯU TẬP/ĐƠN HÀNG, không theo ảnh lẻ: cần biết đang làm bộ nào, còn bao
 * nhiêu ảnh, hạn khi nào, còn việc gì chạy dở. Trước đây thông tin đó nằm rải rác (popover "Dự án"
 * chỉ để áp dụng) nên mỗi phiên làm việc phải tự nhớ.
 *
 * Bất biến khoá ở đây:
 *   (a) HỢP ĐỒNG API mà card dựa vào: tạo bộ sưu tập với brief/hạn chót/mùa vụ rồi ĐỌC LẠI đúng dữ liệu,
 *       kèm trạng thái + nhãn + màu (card hiển thị đúng những gì máy chủ nói, không tự bịa);
 *   (b) WIRING TĨNH: panel nằm trong cấu hình owner, có card trong ACTIVITY_CARDS, và card chỉ dùng
 *       các action đã tồn tại trong store (không gọi endpoint tự phát minh).
 */
class CollectionsHubTest extends TestCase
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

    public function test_creating_a_collection_keeps_brief_deadline_and_season(): void
    {
        $u = $this->customer();

        $res = $this->actingAs($u)->postJson('/api/projects/new', [
            'name' => 'Thu Đông 2026 · Lookbook',
            'brief' => '12 SKU, nền trắng sàn TMĐT + 4 ảnh lookbook ngoài trời',
            'deadline' => '2026-11-30',
            'tags' => ['Thu Đông 2026'],
        ])->assertCreated();

        $res->assertJsonPath('name', 'Thu Đông 2026 · Lookbook')
            ->assertJsonPath('status', Project::STATUS_DRAFT)
            ->assertJsonPath('generations_count', 0);

        // Card hiển thị trạng thái bằng chữ + màu do MÁY CHỦ trả về — không tự suy diễn ở client.
        $this->assertNotEmpty($res->json('status_label'));
        $this->assertNotEmpty($res->json('status_color'));
        $this->assertStringStartsWith('2026-11-30', (string) $res->json('deadline'));

        // Danh sách (nguồn dữ liệu của card) phải trả lại đúng bộ vừa tạo, kèm brief + tags.
        $list = $this->actingAs($u)->getJson('/api/projects')->assertOk()->json('items');
        $row = collect($list)->firstWhere('name', 'Thu Đông 2026 · Lookbook');
        $this->assertNotNull($row, 'Bộ sưu tập vừa tạo phải có trong danh sách.');
        $this->assertSame('Thu Đông 2026', $row['tags'][0] ?? null, 'Mùa/vụ đi vào tags.');
        $this->assertStringContainsString('12 SKU', (string) $row['brief']);
    }

    public function test_collection_list_carries_the_fields_the_hub_renders(): void
    {
        $u = $this->customer();
        $this->actingAs($u)->postJson('/api/projects/new', ['name' => 'Bộ A', 'deadline' => '2026-10-01'])->assertCreated();

        $row = $this->actingAs($u)->getJson('/api/projects')->assertOk()->json('items.0');

        foreach (['id', 'name', 'status', 'status_label', 'status_color', 'generations_count', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $row, "Card Bộ sưu tập cần trường '{$key}'.");
        }
    }

    public function test_hub_panel_is_declared_once_in_config_and_cards_map(): void
    {
        $this->assertContains('collections', \App\Services\StudioGuiConfig::panelIds(), 'Thiếu panel Bộ sưu tập trong cấu hình thanh công cụ.');

        $app = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
        $this->assertStringContainsString('collections: [CollectionsCard]', $app, 'Thiếu card Bộ sưu tập trong bản đồ ACTIVITY_CARDS.');
        // Từ đợt tối ưu 2026-09-24 card nặng nạp LƯỜI (async component) — luật khoá là
        // CollectionsCard vẫn được StudioApp tham chiếu đúng một nguồn, không bắt buộc import eager.
        $this->assertStringContainsString("CollectionsCard = asyncCard(() => import('./components/CollectionsCard.vue'))", $app);
        $this->assertStringContainsString('workspaceOpenRequest', $app, 'Phải có cầu nối mở workspace từ card (card không nhận được event).');
    }

    public function test_hub_card_only_uses_existing_store_actions(): void
    {
        $card = (string) file_get_contents(resource_path('js/studio/components/CollectionsCard.vue'));
        $store = static::studioStoreSource();

        /* [2026-09-23] Bất biến này nay kiểm DỮ LIỆU-DẪN-XUẤT thay vì một danh sách viết cứng.
           Lý do: card sidebar đã được thu gọn có chủ đích (việc chuyên sâu chuyển sang trang
           /bo-suu-tap), nên danh sách cũ ('store.processQueue()' · 'store.requestWorkspace()') đã lệch
           khỏi thực tế — nhưng LUẬT thì không đổi: card chỉ được gọi action CÓ THẬT trong store.
           Quét thẳng mọi lời gọi store.<action>( trong card ⇒ thêm lời gọi mới mà store không có là ĐỎ,
           kể cả khi không ai nhớ cập nhật danh sách này. */
        preg_match_all('/store\.([a-zA-Z][a-zA-Z0-9]*)\s*\(/', $card, $calls);
        $called = array_values(array_unique($calls[1]));
        $this->assertGreaterThanOrEqual(4, count($called),
            'Card Bộ sưu tập phải gọi các action của store (tạo · áp dụng · duyệt · chia sẻ/xuất gói).');

        foreach ($called as $action) {
            $this->assertMatchesRegularExpression('/\b'.preg_quote($action, '/').'\s*\(/', $store,
                "Card gọi store.{$action}() nhưng store KHÔNG có action đó — API tự phát minh.");
        }

        // Hai action cốt lõi của panel (tạo bộ + áp dụng bộ) vẫn phải có mặt.
        foreach (['store.createProject(', 'store.applyProject('] as $call) {
            $this->assertStringContainsString($call, $card, "Card phải dùng action có sẵn: {$call}");
        }

        // Không tự gọi endpoint ngoài hợp đồng đã có (nay ĐÚNG với cả card lẫn trang: đường xuất gói
        // đã gom về store.exportProject(), xem JobTemplatesTest).
        $this->assertStringNotContainsString("fetch('/api/", $card, 'Card không nên tự gọi API — đi qua store để giữ một đường dữ liệu.');

        // Khả năng "thúc hàng đợi ngay" (store.processQueue) từng nằm ở card; khi card thu gọn nó MẤT HẲN
        // khỏi giao diện ⇒ nay đặt ở chip "đang tạo" của trang Bộ sưu tập. Test khoá lại: phải có MỘT
        // bề mặt gọi nó, nếu không hàng đợi chỉ còn chạy theo nhịp cron và người dùng không thúc được.
        $page = (string) file_get_contents(resource_path('js/studio/pages/CollectionsPage.vue'));
        $this->assertStringContainsString('store.processQueue()', $page,
            'Không nơi nào gọi store.processQueue() — mất đường "Xử lý ngay" của người dùng.');
        $this->assertMatchesRegularExpression('/\bprocessQueue\s*\(/', $store, 'Store thiếu action processQueue().');
    }
}
