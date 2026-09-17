<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StudioLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Đợt 0.1b — 2026-09-17] THƯ VIỆN ẢNH RIÊNG THEO USER.
 *
 * Trước đây mọi ảnh tải lên đổ chung vào `studio/ref/` nên KHÔNG THỂ phân quyền: buộc phải giữ
 * endpoint liệt kê/xoá ở nhóm ADMIN, người dùng thường không quản lý được ảnh của mình.
 *
 * Nay: ảnh mới vào `studio/ref/u<id>/`; mỗi người quản lý phần của mình; OWNER quản lý TẤT CẢ.
 * Bất biến khoá ở đây:
 *   (a) ảnh tải lên nằm trong thư mục RIÊNG của user;
 *   (b) người dùng KHÔNG thấy và KHÔNG xoá được ảnh của người khác;
 *   (c) owner THẤY và XOÁ được tất cả;
 *   (d) kho phẳng CŨ vẫn đọc được (tương thích ngược — ảnh đã chèn vào dự án không biến mất).
 */
class UserLibraryScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $made = [];
    /** @var array<int, string> */
    private array $madeDirs = [];

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        // Gỡ luôn thư mục u<id>/ do test tạo, tránh để rác trong storage thật.
        foreach (array_reverse($this->madeDirs) as $d) {
            if (is_dir($d)) {
                @rmdir($d);
            }
        }
        parent::tearDown();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    /** Đặt một ảnh THẬT vào storage (controller đọc thẳng storage_path, không qua Storage::fake). */
    private function putRef(?int $userId, string $name): string
    {
        $rel = $userId === null ? 'studio/ref' : 'studio/ref/u'.$userId;
        $dir = storage_path('app/public/'.$rel);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
            $this->madeDirs[] = $dir;
        }
        $path = $dir.'/'.$name;
        file_put_contents($path, base64_decode(self::PNG));
        $this->made[] = $path;

        return $path;
    }

    private function refNames(User $as): array
    {
        $resp = $this->actingAs($as)->getJson('/api/ref-images')->assertOk();

        return array_column($resp->json('items'), 'name');
    }

    // ── (a) ảnh mới nằm trong thư mục riêng của user ─────────────────────

    public function test_upload_goes_into_the_users_own_folder(): void
    {
        $c = $this->customer();
        $resp = $this->actingAs($c)->post('/api/upload-ref', [
            'image' => \Illuminate\Http\UploadedFile::fake()->image('ao.jpg', 20, 20),
        ])->assertOk();

        $url = (string) $resp->json('url');
        $this->assertStringContainsString('/studio/ref/u'.$c->id.'/', $url,
            'Ảnh tải lên phải nằm trong thư mục riêng của user, không đổ chung vào studio/ref/.');

        $abs = storage_path('app/public/studio/ref/u'.$c->id.'/'.basename($url));
        $this->made[] = $abs;
        $this->madeDirs[] = storage_path('app/public/studio/ref/u'.$c->id);
        $this->assertFileExists($abs, 'File phải tồn tại đúng nơi API báo về.');
    }

    // ── (b) không thấy / không xoá được ảnh của người khác ───────────────

    public function test_user_does_not_see_another_users_image(): void
    {
        $c = $this->customer();
        $other = User::factory()->create();
        $this->putRef($other->id, 'rieng-cua-nguoi-khac.png');
        $this->putRef($c->id, 'cua-toi.png');

        $names = $this->refNames($c);

        $this->assertContains('cua-toi.png', $names, 'Phải thấy ảnh của CHÍNH MÌNH.');
        $this->assertNotContains('rieng-cua-nguoi-khac.png', $names, 'KHÔNG được thấy ảnh của người khác.');
    }

    public function test_user_cannot_delete_another_users_image(): void
    {
        $c = $this->customer();
        $other = User::factory()->create();
        $victim = $this->putRef($other->id, 'khong-duoc-xoa.png');

        $this->actingAs($c)->deleteJson('/api/ref-images/khong-duoc-xoa.png')->assertNotFound();
        $this->assertFileExists($victim, 'Ảnh của người khác KHÔNG được bị xoá.');
    }

    // ── (c) owner thấy và xoá được tất cả ────────────────────────────────

    public function test_owner_sees_every_users_image(): void
    {
        $c = $this->customer();
        $this->putRef($c->id, 'anh-cua-khach.png');
        $this->putRef(999, 'anh-cua-nguoi-khac.png');

        $names = $this->refNames($this->admin());

        $this->assertContains('anh-cua-khach.png', $names);
        $this->assertContains('anh-cua-nguoi-khac.png', $names, 'Owner phải quản lý được TẤT CẢ.');
    }

    public function test_owner_can_delete_another_users_image(): void
    {
        $victim = $this->putRef(999, 'owner-xoa-duoc.png');

        $this->actingAs($this->admin())->deleteJson('/api/ref-images/owner-xoa-duoc.png')->assertOk();
        $this->assertFileDoesNotExist($victim, 'Owner phải xoá được ảnh của user khác.');
    }

    // ── (d) tương thích ngược với kho phẳng cũ ───────────────────────────

    public function test_legacy_flat_pool_still_visible(): void
    {
        $this->putRef(null, 'anh-cu-truoc-khi-tach.png');

        foreach ([$this->customer(), $this->admin()] as $u) {
            $this->assertContains('anh-cu-truoc-khi-tach.png', $this->refNames($u),
                'Ảnh trong kho phẳng CŨ phải vẫn đọc được — nếu không, ảnh người dùng đang dùng sẽ biến mất.');
        }
    }

    public function test_user_can_delete_their_own_image(): void
    {
        $c = $this->customer();
        $mine = $this->putRef($c->id, 'cua-toi-xoa-duoc.png');

        $this->actingAs($c)->deleteJson('/api/ref-images/cua-toi-xoa-duoc.png')->assertOk();
        $this->assertFileDoesNotExist($mine);
    }

    // ── (e) quy tắc sở hữu (hàm thuần, dễ suy luận) ──────────────────────

    public function test_ownership_rule(): void
    {
        $this->assertTrue(studio_upload_visible_to('studio/ref/u5/a.png', 5, false), 'Ảnh của mình thì được.');
        $this->assertFalse(studio_upload_visible_to('studio/ref/u6/a.png', 5, false), 'Ảnh người khác thì không.');
        $this->assertTrue(studio_upload_visible_to('studio/ref/u6/a.png', 5, true), 'Owner thì được mọi thứ.');
        $this->assertTrue(studio_upload_visible_to('studio/ref/a.png', 5, false), 'Kho phẳng cũ: dùng chung.');
        $this->assertTrue(studio_upload_visible_to('studio/assets/a.png', 5, false), 'Tài nguyên chung.');
        $this->assertSame(5, studio_ref_owner_of('studio/ref/u5/a.png'));
        $this->assertNull(studio_ref_owner_of('studio/ref/a.png'));
        $this->assertNull(studio_ref_owner_of('studio/assets/u5/a.png'), 'Chỉ studio/ref mới phân theo user.');
        $this->assertSame('studio/ref/u7', studio_ref_user_dir(7));
    }

    // ── (f) danh sách file đã tải lên cũng theo user ─────────────────────

    public function test_uploaded_files_listing_is_scoped(): void
    {
        $svc = app(StudioLibraryService::class);
        $c = $this->customer();
        $other = User::factory()->create();
        $this->putRef($c->id, 'svc-cua-toi.png');
        $this->putRef($other->id, 'svc-cua-nguoi-khac.png');

        $mine = array_column($svc->uploadedFiles($c)['items'], 'name');
        $all = array_column($svc->uploadedFiles($this->admin())['items'], 'name');

        $this->assertContains('svc-cua-toi.png', $mine);
        $this->assertNotContains('svc-cua-nguoi-khac.png', $mine, 'Danh sách file phải theo user.');
        $this->assertContains('svc-cua-nguoi-khac.png', $all, 'Owner thấy tất cả.');
    }
}
