<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudioGuiConfig;
use App\Support\IconRegistry;
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

    public function test_icons_come_from_exactly_one_shared_source(): void
    {
        IconRegistry::flush();

        $path = resource_path('js/studio/icons.json');
        $this->assertFileExists($path, 'Thiếu registry icon dùng chung.');

        $json = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($json);
        $this->assertNotEmpty($json);
        $this->assertSame(array_keys($json), IconRegistry::names(),
            'IconRegistry phải đọc ĐÚNG file JSON đó (không có bản sao).');

        // Vue phải IMPORT chính file ấy, không giữ danh sách riêng.
        $vue = (string) file_get_contents(resource_path('js/studio/components/StudioIcon.vue'));
        $this->assertStringContainsString("import ICONS from '../icons.json'", $vue,
            'StudioIcon.vue phải import registry chung thay vì tự khai báo danh sách icon.');

        // PHP KHÔNG được giữ danh sách song song — đây chính là lỗi đã sửa.
        $cfg = (string) file_get_contents(app_path('Services/StudioGuiConfig.php'));
        $this->assertStringNotContainsString('const ICONS', $cfg,
            'StudioGuiConfig không được giữ danh sách icon riêng: thêm icon sẽ phải sửa 2 chỗ và lệch nhau.');
    }

    /**
     * Thêm icon mới vào registry là MỌI nơi tự có: không phải sửa PHP, không phải sửa Vue.
     * Test này mô phỏng đúng việc đó bằng cách ghi thêm một icon vào JSON rồi đọc lại.
     */
    public function test_a_newly_registered_icon_is_available_everywhere(): void
    {
        $path = resource_path('js/studio/icons.json');
        $original = (string) file_get_contents($path);

        try {
            $json = json_decode($original, true);
            $json['iconThuNghiem'] = ['svg' => '<circle cx="12" cy="12" r="9"/>', 'note' => 'icon thử nghiệm'];
            file_put_contents($path, json_encode($json, JSON_UNESCAPED_UNICODE));
            IconRegistry::flush();

            $this->assertTrue(IconRegistry::has('iconThuNghiem'), 'Icon mới phải có mặt ngay.');
            $this->assertContains('iconThuNghiem', $this->svc()->save([
                ['id' => 'concept', 'label' => 'X', 'icon' => 'iconThuNghiem', 'visible' => true],
            ])[0]['icon'] === 'iconThuNghiem' ? ['iconThuNghiem'] : [],
                'Cấu hình phải nhận icon vừa đăng ký mà KHÔNG cần sửa PHP.');

            $names = array_column(IconRegistry::catalog(), 'name');
            $this->assertContains('iconThuNghiem', $names, 'Danh sách cho ô chọn phải tự có icon mới.');
        } finally {
            file_put_contents($path, $original);
            IconRegistry::flush();
        }
    }

    /**
     * Mọi icon ĐANG DÙNG trong app phải tồn tại trong registry.
     *
     * Guard này đặc biệt quan trọng sau khi gộp về một nguồn: đổi nguồn icon mà quên một chỗ thì
     * nút render TRỐNG, không có lỗi nào để lần ra.
     */
    public function test_every_icon_used_in_the_app_exists_in_the_registry(): void
    {
        IconRegistry::flush();
        $known = IconRegistry::names();

        $files = array_merge(
            glob(resource_path('js/studio/components/*.vue')) ?: [],
            glob(resource_path('js/studio/*.vue')) ?: [],
            glob(resource_path('views/**/*.blade.php')) ?: [],
            glob(resource_path('views/*.blade.php')) ?: [],
        );
        $this->assertNotEmpty($files, 'Không tìm thấy file nào để quét.');

        $missing = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            preg_match_all('/<StudioIcon\b[^>]*>/s', $src, $tags);
            foreach ($tags[0] ?? [] as $tag) {
                if (preg_match('/\bname="([a-zA-Z][a-zA-Z0-9]*)"/', $tag, $m) === 1) {
                    if (! in_array($m[1], $known, true)) {
                        $missing[] = $rel.' → name="'.$m[1].'"';
                    }
                }
                if (preg_match('/:name="([^"]*)"/s', $tag, $m) === 1) {
                    preg_match_all("/'([a-zA-Z][a-zA-Z0-9]*)'/", $m[1], $lits);
                    foreach ($lits[1] ?? [] as $lit) {
                        if (! in_array($lit, $known, true)) {
                            $missing[] = $rel.' → :name … \''.$lit.'\'';
                        }
                    }
                }
            }
        }

        $this->assertSame([], $missing,
            "Có icon được dùng nhưng KHÔNG có trong registry (nút sẽ render trống):\n".implode("\n", $missing));
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
        // CHỈ mục kind='panel' mới có card; 'action' (popup) và 'menu' (Cài đặt) thì không.
        $phpIds = StudioGuiConfig::panelIds();
        sort($phpIds);

        $this->assertSame($vueIds, $phpIds,
            'Bộ id PANEL trong StudioGuiConfig LỆCH với ACTIVITY_CARDS — mục bị ẩn oan hoặc không có card.');
    }

    /**
     * [Sửa 2026-09-17] LỖI NGƯỜI DÙNG PHÁT HIỆN: danh sách quản trị THIẾU nút 'Prompt Tạo Ảnh' và
     * 'Trợ lý thiết kế' (chúng bị viết cứng trong template nên không ai quản lý được), và thiếu cả
     * nút menu 'Cài đặt'. Guard này khoá bất biến: MỌI nút trên thanh công cụ trái đều có trong
     * cấu hình.
     */
    public function test_every_left_toolbar_button_is_managed_by_the_config(): void
    {
        $ids = StudioGuiConfig::defaultIds();

        foreach (['prompt' => 'action', 'stylist' => 'action', 'settings' => 'menu'] as $id => $kind) {
            $this->assertContains($id, $ids, "Nút '{$id}' KHÔNG có trong cấu hình ⇒ owner không quản lý được.");
            $row = collect(StudioGuiConfig::DEFAULTS)->firstWhere('id', $id);
            $this->assertSame($kind, $row['kind'], "Mục '{$id}' phải có kind='{$kind}'.");
        }

        // Nền tảng cũ (panel) vẫn nguyên vẹn.
        $this->assertCount(7, StudioGuiConfig::panelIds(), 'Phải còn đủ 7 nhóm card.');
        $this->assertSame(['settings'], StudioGuiConfig::PINNED_IDS);
    }

    public function test_toolbar_renders_from_the_config_not_hardcoded_buttons(): void
    {
        $src = (string) file_get_contents(resource_path('js/studio/StudioApp.vue'));

        // Thanh công cụ trái phải sinh nút từ cấu hình...
        $this->assertStringContainsString('v-for="a in activityBar"', $src,
            'Thanh công cụ trái phải render từ cấu hình (activityBar).');
        // ...và KHÔNG còn markup viết cứng cho 2 nút popup (đúng thứ đã bị bỏ sót).
        $this->assertStringNotContainsString('@click="store.promptOpen = true; stylistPopupOpen = false', $src,
            'Nút Prompt Tạo Ảnh còn bị viết cứng ⇒ lại thoát khỏi danh sách quản trị.');
        $this->assertStringNotContainsString('@click="stylistPopupOpen = true; store.promptOpen = false', $src,
            'Nút Trợ lý thiết kế còn bị viết cứng ⇒ lại thoát khỏi danh sách quản trị.');

        // Fallback trong JS phải khớp cấu hình PHP (không thì lúc API lỗi sẽ thiếu nút).
        preg_match_all("/\\{ id: '([a-z_]+)', kind: '([a-z]+)'/", $src, $m, PREG_SET_ORDER);
        $fallback = [];
        foreach ($m as $row) {
            $fallback[$row[1]] = $row[2];
        }
        foreach (StudioGuiConfig::DEFAULTS as $d) {
            $this->assertSame($d['kind'], $fallback[$d['id']] ?? null,
                "Fallback JS thiếu hoặc sai kind cho '{$d['id']}'.");
        }
    }

    public function test_admin_ui_pinned_list_matches_the_backend(): void
    {
        $src = (string) file_get_contents(resource_path('js/studio/AdminApp.vue'));
        $this->assertStringContainsString("const GUI_PINNED = ['" . implode("', '", StudioGuiConfig::PINNED_IDS) . "']", $src,
            'Danh sách ghim ở trang quản trị phải khớp StudioGuiConfig::PINNED_IDS.');
    }

    /** Mục ghim luôn nằm CUỐI dù client gửi thứ tự nào — nếu không, UI và thực tế lệch nhau. */
    public function test_pinned_entry_stays_last_whatever_order_is_saved(): void
    {
        $items = $this->svc()->all();
        // Đưa 'settings' lên ĐẦU rồi lưu.
        $settings = null;
        $rest = [];
        foreach ($items as $it) {
            if ($it['id'] === 'settings') { $settings = $it; } else { $rest[] = $it; }
        }
        $saved = $this->svc()->save(array_merge([$settings], $rest));

        $this->assertSame('settings', end($saved)['id'], 'Mục ghim phải luôn ở cuối.');
    }

    /** `kind` do CODE quyết định — client không được đổi hành vi của nút. */
    public function test_client_cannot_change_the_kind(): void
    {
        $saved = $this->svc()->save([
            ['id' => 'settings', 'kind' => 'panel', 'label' => 'X', 'icon' => 'gear', 'visible' => true],
        ]);

        $row = collect($saved)->firstWhere('id', 'settings');
        $this->assertSame('menu', $row['kind'], 'kind phải lấy từ code, không nhận từ client.');
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
        $iconNames = array_column($resp->json('icons'), 'name');
        $this->assertContains('hanger', $iconNames, 'Phải kèm danh sách icon cho trang quản trị.');
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
