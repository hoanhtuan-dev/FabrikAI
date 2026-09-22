<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SettingsAreas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TRANG HỢP NHẤT "CÀI ĐẶT & QUẢN TRỊ" — khoá bất biến của đợt gộp trang (2026-09-26 · đợt 24).
 *
 * Vì sao cần: đợt này xoá ba entry + ba blade và thay bằng MỘT entry + MỘT thanh chung. Kiểu hỏng im
 * lặng của nó là: một khu còn nằm trong danh sách nhưng trỏ vào liên kết chết; thanh của trang máy chủ
 * render lệch khỏi thanh của SPA; hoặc danh sách khu bị khai lại ở chỗ thứ hai rồi lệch dần.
 */
class SettingsHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    /** Danh sách khu mà MÁY CHỦ đã render xuống trang (nguồn duy nhất: SettingsAreas). */
    private function areasInHtml(string $html): array
    {
        preg_match("/data-areas='([^']+)'/", $html, $m);
        $this->assertNotEmpty($m, 'Trang thiếu data-areas ⇒ thanh chung không có danh sách khu để vẽ.');

        $json = html_entity_decode($m[1], ENT_QUOTES);

        return array_column(json_decode($json, true) ?: [], 'id');
    }

    /** 1. Mọi khu trong danh sách phải MỞ ĐƯỢC thật — không khu nào là liên kết chết. */
    public function test_every_area_in_the_list_opens_for_the_owner(): void
    {
        $admin = $this->admin();

        foreach (SettingsAreas::all() as $area) {
            $html = $this->actingAs($admin)->get($area['href'])->assertOk()->getContent();

            $this->assertStringContainsString('data-area="'.$area['id'].'"', $html,
                "Khu {$area['id']} ({$area['href']}) không tự khai đúng khu đang mở."
            );
        }
    }

    /** 2. Hai trang MÁY CHỦ render phải dùng ĐÚNG thanh chung, và chỉ có MỘT <h1>. */
    public function test_the_server_rendered_pages_use_the_shared_bar(): void
    {
        $admin = $this->admin();

        foreach (['/he-thong-thiet-ke' => 'design', '/bao-cao-nhom' => 'costs'] as $url => $id) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('aria-label="Khu vực cài đặt"', $html,
                "{$url} thiếu bộ chuyển khu của thanh chung."
            );
            $this->assertStringContainsString('data-area="'.$id.'"', $html,
                "{$url} không khai đúng khu đang mở."
            );
            $this->assertStringContainsString('aria-current="page"', $html,
                "{$url} không đánh dấu khu đang mở trên thanh."
            );
            $this->assertSame(1, substr_count($html, '<h1'),
                "{$url} có nhiều hơn một <h1> (tiêu đề khu đã do thanh chung render)."
            );
        }
    }

    /** 3. Tài khoản thường chỉ thấy khu của mình; owner thấy đủ năm khu. */
    public function test_the_bar_only_offers_the_areas_the_user_may_open(): void
    {
        $ownerIds = array_column(SettingsAreas::visibleFor($this->admin()), 'id');
        $customerIds = array_column(SettingsAreas::visibleFor($this->customer()), 'id');

        $this->assertSame(['mine', 'system', 'admin', 'design', 'costs'], $ownerIds);
        $this->assertSame(['mine'], $customerIds,
            'Tài khoản thường không được thấy khu chỉ owner — máy chủ vẫn chặn thật bằng middleware.'
        );

        // Và điều đó phải thể hiện ở HTML thật, không chỉ ở lớp PHP.
        $customerHtml = $this->actingAs($this->customer())->get('/cai-dat')->assertOk()->getContent();
        $this->assertSame(['mine'], $this->areasInHtml($customerHtml));

        $ownerHtml = $this->actingAs($this->admin())->get('/cai-dat')->assertOk()->getContent();
        $this->assertSame($ownerIds, $this->areasInHtml($ownerHtml));
    }

    /** 4. Ba trang + ba entry cũ đã BIẾN MẤT — không để lại mã chết trỏ vào trang không còn tồn tại. */
    public function test_the_old_pages_and_entries_are_gone(): void
    {
        $gone = [
            'resources/js/studio/settings.js',
            'resources/js/studio/admin.js',
            'resources/js/studio/my-settings.js',
            'resources/views/studio/settings.blade.php',
            'resources/views/studio/admin.blade.php',
            'resources/views/studio/my-settings.blade.php',
        ];

        foreach ($gone as $rel) {
            $this->assertFileDoesNotExist(base_path($rel), "{$rel} vẫn còn dù đã gộp vào trang hợp nhất.");
        }

        $vite = (string) file_get_contents(base_path('vite.config.js'));
        foreach (['settings.js', 'admin.js', 'my-settings.js'] as $name) {
            $this->assertStringNotContainsString("studio/{$name}", $vite,
                "vite.config.js còn khai entry studio/{$name} đã xoá ⇒ build sẽ đỏ."
            );
        }
        $this->assertStringContainsString('studio/hub.js', $vite, 'Thiếu entry của trang hợp nhất.');
    }

    /** 5. Danh sách khu chỉ được khai Ở MỘT CHỖ: hai thanh cùng đọc SettingsAreas. */
    public function test_the_area_list_lives_in_exactly_one_place(): void
    {
        $blade = (string) file_get_contents(base_path('resources/views/studio/hub.blade.php'));
        $partial = (string) file_get_contents(base_path('resources/views/studio/partials/hub-bar.blade.php'));
        $vue = (string) file_get_contents(base_path('resources/js/studio/SettingsHubApp.vue'));

        $this->assertStringContainsString('data-areas', $blade, 'Trang hợp nhất phải truyền danh sách khu xuống.');
        $this->assertStringContainsString('SettingsAreas::visibleFor', $blade);
        $this->assertStringContainsString('SettingsAreas::visibleFor', $partial,
            'Thanh của trang máy chủ render phải đọc CÙNG danh sách khu.'
        );
        $this->assertStringContainsString('data-areas', $vue, 'Thanh trong SPA phải đọc danh sách khu từ máy chủ.');

        foreach (["'/settings'", "'/admin'", "'/he-thong-thiet-ke'", "'/bao-cao-nhom'"] as $href) {
            $this->assertStringNotContainsString($href, $vue,
                "SettingsHubApp.vue tự khai lại đường dẫn {$href} ⇒ sớm muộn lệch với SettingsAreas."
            );
        }
    }
}
