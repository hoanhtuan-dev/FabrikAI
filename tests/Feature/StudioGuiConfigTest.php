<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudioGuiConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Yêu cầu 2026-09-17] OWNER QUẢN LÝ GIAO DIỆN — thanh công cụ TRÁI của Studio.
 *
 * Bất biến khoá ở đây:
 *   (a) chỉ owner GHI được; mọi tài khoản Studio ĐỌC được (cần để render đúng);
 *   (b) id lạ · icon không tồn tại · id lặp · danh sách rỗng đều bị TỪ CHỐI kèm thông báo rõ;
 *   (c) mục MỚI thêm ở bản cập nhật sau không bị mất vì cấu hình cũ của owner;
 *   (d) danh sách icon của PHP PHẢI khớp StudioIcon.vue, và bộ id PHẢI khớp ACTIVITY_CARDS
 *       trong StudioApp.vue — lệch là hỏng giao diện mà không ai biết vì sao.
 */
class StudioGuiConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function svc(): StudioGuiConfig
    {
        return app(StudioGuiConfig::class);
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    // ── (d) CHỐNG LỆCH giữa PHP và Vue ────────────────────────────────────

    public function test_icon_list_matches_studio_icon_component(): void
    {
        $src = (string) file_get_contents(resource_path('js/studio/components/StudioIcon.vue'));
        preg_match_all('/^  ([a-zA-Z][a-zA-Z0-9]*):/m', $src, $m);
        $vueIcons = $m[1] ?? [];

        $this->assertNotEmpty($vueIcons, 'Không đọc được danh sách icon từ StudioIcon.vue.');
        sort($vueIcons);
        $phpIcons = StudioGuiConfig::ICONS;
        sort($phpIcons);

        $this->assertSame($vueIcons, $phpIcons,
            'Danh sách icon của StudioGuiConfig LỆCH với StudioIcon.vue — owner chọn icon sẽ ra nút TRỐNG.');
    }

    public function test_activity_ids_match_the_cards_map_in_the_studio_app(): void
    {
        $src = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));
        $start = strpos($src, 'const ACTIVITY_CARDS');
        $end = strpos($src, '};', (int) $start);
        $block = substr($src, (int) $start, (int) $end - (int) $start);
        preg_match_all('/^\s{2}([a-z][a-zA-Z0-9]*):\s*\[/m', $block, $m);
        $vueIds = $m[1] ?? [];

        $this->assertNotEmpty($vueIds, 'Không đọc được ACTIVITY_CARDS từ StudioApp.vue.');
        sort($vueIds);
        $phpIds = StudioGuiConfig::defaultIds();
        sort($phpIds);

        $this->assertSame($vueIds, $phpIds,
            'Bộ id trong StudioGuiConfig LỆCH với ACTIVITY_CARDS — mục bị ẩn oan hoặc không có card.');
    }

    // ── Mặc định ───────────────────────────────────────────────────────────

    public function test_defaults_when_nothing_saved(): void
    {
        $this->assertSame(StudioGuiConfig::DEFAULTS, $this->svc()->all());
        $this->assertSame('concept', $this->svc()->all()[0]['id'], 'Thứ tự gốc: Tạo ảnh đứng đầu.');
    }

    // ── Lưu ────────────────────────────────────────────────────────────────

    public function test_save_persists_order_label_icon_and_visibility(): void
    {
        $items = $this->svc()->all();
        // Đảo thứ tự 2 mục đầu, đổi nhãn + icon mục đầu, ẩn mục thứ ba.
        $items[0]['label'] = 'Tạo ảnh AI';
        $items[0]['icon'] = 'wand';
        $items[2]['visible'] = false;
        $first = $items[0];
        $items[0] = $items[1];
        $items[1] = $first;

        $saved = $this->svc()->save($items);

        $this->assertSame($items[0]['id'], $saved[0]['id'], 'Thứ tự mới phải được giữ.');
        $this->assertSame('Tạo ảnh AI', $saved[1]['label']);
        $this->assertSame('wand', $saved[1]['icon']);
        $this->assertFalse($saved[2]['visible']);

        // Đọc lại từ DB (không dựa vào cache trong bộ nhớ).
        $this->assertSame($saved, $this->svc()->all());
    }

    public function test_save_rejects_unknown_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->save([['id' => 'khong_ton_tai', 'label' => 'X', 'icon' => 'gear', 'visible' => true]]);
    }

    public function test_save_rejects_unknown_icon(): void
    {
        // Icon lạ ⇒ nút render TRỐNG và không ai biết vì sao. Phải chặn ngay ở server.
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->save([['id' => 'concept', 'label' => 'X', 'icon' => 'khong-co-icon-nay', 'visible' => true]]);
    }

    public function test_save_rejects_duplicate_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->save([
            ['id' => 'concept', 'label' => 'A', 'icon' => 'gear', 'visible' => true],
            ['id' => 'concept', 'label' => 'B', 'icon' => 'gear', 'visible' => true],
        ]);
    }

    public function test_save_rejects_empty_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->save([]);
    }

    public function test_save_rejects_overlong_label(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc()->save([['id' => 'concept', 'label' => str_repeat('a', StudioGuiConfig::LABEL_MAX + 1), 'icon' => 'gear', 'visible' => true]]);
    }

    /** Nhãn rỗng ⇒ quay về nhãn gốc, KHÔNG để nút không tên. */
    public function test_blank_label_falls_back_to_default(): void
    {
        $saved = $this->svc()->save([['id' => 'concept', 'label' => '   ', 'icon' => 'gear', 'visible' => true]]);
        $concept = collect($saved)->firstWhere('id', 'concept');
        $this->assertSame('Tạo ảnh', $concept['label']);
    }

    // ── (c) tương thích tiến: mục mới thêm sau này không bị mất ───────────

    public function test_new_default_activity_is_appended_not_lost(): void
    {
        // Owner chỉ gửi 1 mục (như bản cấu hình cũ trước khi có các mục khác).
        $saved = $this->svc()->save([['id' => 'tryon', 'label' => 'Mặc thử', 'icon' => 'hanger', 'visible' => true]]);

        $ids = array_column($saved, 'id');
        $this->assertSame('tryon', $ids[0], 'Mục owner gửi phải giữ vị trí đầu.');
        $this->assertCount(count(StudioGuiConfig::DEFAULTS), $ids, 'Không được mất mục nào.');
        foreach (StudioGuiConfig::defaultIds() as $id) {
            $this->assertContains($id, $ids, "Mục '{$id}' bị mất sau khi lưu cấu hình một phần.");
        }
    }

    public function test_reset_restores_defaults(): void
    {
        $items = $this->svc()->all();
        $items[0]['label'] = 'Đổi rồi';
        $this->svc()->save($items);

        $this->assertSame(StudioGuiConfig::DEFAULTS, $this->svc()->reset());
        $this->assertSame(StudioGuiConfig::DEFAULTS, $this->svc()->all());
    }

    // ── (a) phân quyền ─────────────────────────────────────────────────────

    public function test_only_owner_can_write_the_config(): void
    {
        $payload = ['items' => [['id' => 'concept', 'label' => 'X', 'icon' => 'gear', 'visible' => true]]];

        $this->actingAs($this->customer())->putJson('/api/admin/gui/activity-bar', $payload)->assertForbidden();
        $this->actingAs($this->admin())->putJson('/api/admin/gui/activity-bar', $payload)->assertOk();

        $this->actingAs($this->customer())->postJson('/api/admin/gui/activity-bar/reset')->assertForbidden();
        $this->actingAs($this->admin())->postJson('/api/admin/gui/activity-bar/reset')->assertOk();
    }

    public function test_studio_users_can_read_the_config(): void
    {
        $this->getJson('/api/gui')->assertUnauthorized();

        $resp = $this->actingAs($this->customer())->getJson('/api/gui')->assertOk();
        $this->assertCount(count(StudioGuiConfig::DEFAULTS), $resp->json('activityBar'));
        $this->assertContains('hanger', $resp->json('icons'), 'Phải kèm danh sách icon cho trang quản trị.');
    }

    public function test_invalid_payload_returns_422_with_a_specific_message(): void
    {
        $resp = $this->actingAs($this->admin())->putJson('/api/admin/gui/activity-bar', [
            'items' => [['id' => 'concept', 'label' => 'X', 'icon' => 'khong-co-icon-nay', 'visible' => true]],
        ])->assertStatus(422);

        $this->assertStringContainsString('Icon', (string) $resp->json('message'),
            'Thông báo phải nói RÕ chỗ sai để owner biết đường sửa.');
    }
}
