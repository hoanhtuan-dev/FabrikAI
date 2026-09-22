<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SessionIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHIÊN LÀM VIỆC DÙNG CHUNG cho cả ba khu của trang hợp nhất (2026-09-26 · đợt 25).
 *
 * Trước đợt này mỗi khu tự lo danh tính: AdminApp gọi /api/boot và giữ một ref riêng, MySettingsApp
 * đọc data-user-id từ DOM, thanh chung không biết người dùng là ai. Hệ quả thật: sửa TÊN của chính
 * mình ở khu Quản trị xong, các khu khác vẫn hiện tên cũ cho tới khi nạp lại cả trang.
 *
 * Bốn bài dưới đây khoá bốn điều: danh tính chỉ có MỘT hình dạng · chỉ có MỘT nguồn · ba khu đọc
 * cùng một store · và luật "sửa người khác thì không đụng danh tính phiên này" chạy đúng (self-check
 * JS ở scripts/check-session-store.mjs).
 */
class SharedSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function owner(): User
    {
        return User::where('email', 'owner@fabrikai.shop')->firstOrFail();
    }

    /** Đọc khối danh tính mà blade nhúng sẵn cho store dùng chung. */
    private function identityInHtml(string $html): array
    {
        preg_match("/data-me='([^']+)'/", $html, $m);
        $this->assertNotEmpty($m, 'Trang hợp nhất thiếu data-me ⇒ store dùng chung không có danh tính.');

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true) ?: [];
    }

    /** 1. Danh tính nhúng ở trang hợp nhất và /api/boot phải GIỐNG NHAU (cùng một lớp dựng). */
    public function test_the_hub_identity_and_boot_agree(): void
    {
        $owner = $this->owner();
        $expected = SessionIdentity::for($owner);

        $embedded = $this->identityInHtml($this->actingAs($owner)->get('/admin')->assertOk()->getContent());

        $boot = $this->actingAs($owner)->getJson('/api/boot')->assertOk()->json('user');

        foreach (['id', 'name', 'email', 'role', 'role_label', 'credits_balance', 'is_admin', 'is_super_admin'] as $key) {
            $this->assertArrayHasKey($key, $embedded, "data-me thiếu trường {$key}.");
            $this->assertSame($expected[$key], $embedded[$key], "data-me lệch SessionIdentity ở trường {$key}.");
            $this->assertSame($expected[$key], $boot[$key], "/api/boot lệch SessionIdentity ở trường {$key}.");
        }

        $this->assertTrue($embedded['is_admin'], 'Owner phải được đánh dấu is_admin — khu Cài đặt dựa vào đó.');
    }

    /** 2. Hình dạng ấy chỉ được khai Ở MỘT CHỖ (App\Support\SessionIdentity). */
    public function test_the_identity_shape_has_exactly_one_source(): void
    {
        $boot = (string) file_get_contents(base_path('app/Http/Controllers/StudioController.php'));
        $blade = (string) file_get_contents(base_path('resources/views/studio/hub.blade.php'));

        $this->assertStringContainsString('SessionIdentity::for(', $boot, '/api/boot phải lấy danh tính từ SessionIdentity.');
        $this->assertStringContainsString('SessionIdentity::for(', $blade, 'hub.blade.php phải lấy danh tính từ SessionIdentity.');
        $this->assertStringNotContainsString("'is_super_admin' => \$user->isSuperAdmin(),", $boot,
            'StudioController còn tự dựng lại danh tính ⇒ hai nguồn sẽ lệch nhau.'
        );
    }

    /** 3. Ba khu đọc danh tính từ CÙNG một store, không khu nào tự gọi API riêng. */
    public function test_the_three_areas_read_identity_from_the_shared_store(): void
    {
        $store = (string) file_get_contents(base_path('resources/js/studio/store/session.js'));
        $this->assertStringContainsString('export const useSessionStore', $store);
        $this->assertStringContainsString('applyUser(', $store, 'Thiếu applyUser() ⇒ không có đường lan truyền khi sửa người dùng.');

        foreach (['AdminApp.vue', 'MySettingsApp.vue', 'SettingsHubApp.vue'] as $app) {
            $src = (string) file_get_contents(resource_path('js/studio/'.$app));
            $this->assertStringContainsString('useSessionStore', $src, $app.' chưa dùng store dùng chung.');
        }

        $admin = (string) file_get_contents(resource_path('js/studio/AdminApp.vue'));
        $this->assertStringNotContainsString('const me = ref(null)', $admin,
            'AdminApp còn giữ bản danh tính riêng ⇒ sửa tên ở đây sẽ không tới được các khu khác.'
        );

        // Đường lan truyền: hàm lưu người dùng phải đẩy kết quả vào store.
        $save = substr($admin, strpos($admin, 'async function saveUser()'));
        $save = substr($save, 0, strpos($save, 'async function saveCredit()'));
        $this->assertStringContainsString('session.applyUser(', $save,
            'saveUser() không đẩy kết quả vào store ⇒ thanh chung và các khu khác vẫn thấy tên cũ.'
        );
    }

    /** 4. Luật của store được kiểm bằng Node (hydrate · applyUser · chia sẻ request). */
    public function test_the_session_store_passes_its_node_self_check(): void
    {
        $node = @shell_exec('command -v node 2>/dev/null');
        if (! is_string($node) || trim($node) === '') {
            $this->markTestSkipped('Không có node trong PATH — bỏ qua self-check JS.');
        }

        $out = [];
        $rc = 1;
        @exec(escapeshellcmd(trim($node)).' '.escapeshellarg(base_path('scripts/check-session-store.mjs')).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "check-session-store.mjs thất bại:\n".implode("\n", $out));
    }
}
