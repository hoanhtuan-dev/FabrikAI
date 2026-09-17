<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * XUẤT GÓI CHO XƯỞNG (Đợt 4 — 2026-09-19).
 *
 * Chủ xưởng may không cần "một tấm ảnh đẹp" — họ cần một GÓI đủ nghĩa để cắt may: ảnh tham chiếu +
 * phiếu kỹ thuật + bảng size, và một bản đọc được bằng máy. Người nhận KHÔNG dùng FabrikAI nên gói
 * phải tự giải thích được.
 *
 * Bất biến khoá ở đây:
 *   (a) quyền: khách chưa đăng nhập bị chặn; người khác không tải được gói của người ta;
 *   (b) ZIP chứa đủ 5 thành phần (ảnh · phiếu kỹ thuật · bảng size · thông tin bộ · manifest);
 *   (c) TRUNG THỰC: ảnh không tải được thì phải được GHI RÕ trong gói, không im lặng bỏ qua;
 *   (d) bộ sưu tập chưa có ảnh vẫn xuất được (kèm hướng dẫn), không được 500.
 */
class ProjectExportTest extends TestCase
{
    use RefreshDatabase;

    /** 1 ảnh JPEG 8x8 hợp lệ — đủ để getimagesizefromstring nhận dạng phần mở rộng. */
    private const TINY_JPEG_B64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gNzAK/9sAQwAKBwcIBwYKCAgICwoKCw4YEA4NDQ4dFRYRGCMfJSQiHyIhJis3LyYpNCkhIjBBMTQ5Oz4+PiUuRElDPEg3PT47/9sAQwEKCwsODQ4cEBAcOygiKDs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A52iiipPmT//Z';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function project(User $u, array $attrs = []): Project
    {
        // Tạo qua QUAN HỆ (giống controller): `user_id` không nằm trong fillable của Project.
        return $u->projects()->create(array_merge([
            'name' => 'Thu Đông 2026 · Lookbook',
            'brief' => '12 SKU nền trắng + 4 ảnh ngoài trời',
            'tags' => ['Thu Đông 2026'],
            'deadline' => now()->addDays(10),
        ], $attrs));
    }

    private function generation(User $u, Project $p, string $mediaUrl): Generation
    {
        return Generation::create([
            'user_id' => $u->id,
            'project_id' => $p->id,
            'type' => 'image',
            'status' => 'completed',
            'prompt' => 'áo sơ mi linen trắng form rộng, studio lighting',
            'model' => 'qwen-image-3.0-pro',
            'provider' => 'qwen',
            'resolution' => '2K',
            'media_url' => $mediaUrl,
            'credits_cost' => 1,
        ]);
    }

    private function openZip(string $content): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'ziptest-').'.zip';
        file_put_contents($path, $content);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Nội dung tải về phải là ZIP hợp lệ.');

        return $zip;
    }

    public function test_guest_cannot_download_the_bundle(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $this->getJson('/api/projects/'.$p->id.'/export')->assertStatus(401);
    }

    public function test_another_user_cannot_download_someone_elses_bundle(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);

        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/export')->assertForbidden();
    }

    public function test_bundle_contains_images_tech_sheet_size_chart_info_and_manifest(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        Storage::disk('public')->put('studio/t/ao-so-mi.jpg', base64_decode(self::TINY_JPEG_B64));
        $this->generation($u, $p, '/storage/studio/t/ao-so-mi.jpg');

        $res = $this->actingAs($u)->get('/api/projects/'.$p->id.'/export?sizes='.urlencode("S, 84, 68, 92, 58\nM, 88, 72, 96, 59").'&note='.urlencode('Vải linen 100%, màu trắng ngà'));
        $res->assertOk();
        $this->assertSame('application/zip', $res->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('.zip', (string) $res->headers->get('content-disposition'));

        $zip = $this->openZip($res->streamedContent() ?: $res->getContent());

        foreach (['README.txt', 'thong-tin-bo-suu-tap.txt', 'bang-size.csv', 'phieu-ky-thuat.txt', 'manifest.json'] as $entry) {
            $this->assertNotFalse($zip->locateName($entry), "Gói thiếu '{$entry}'.");
        }
        // Ảnh phải nằm trong thư mục anh/ với số thứ tự.
        $imageEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'anh/01-') && str_ends_with($name, '.jpg')) {
                $imageEntry = $name;
            }
        }
        $this->assertNotNull($imageEntry, 'Gói phải chứa ảnh tham chiếu đánh số trong anh/.');

        $info = (string) $zip->getFromName('thong-tin-bo-suu-tap.txt');
        $this->assertStringContainsString('Thu Đông 2026 · Lookbook', $info);
        $this->assertStringContainsString('12 SKU nền trắng', $info, 'Brief của khách phải có trong gói.');
        $this->assertStringContainsString('Vải linen 100%', $info, 'Ghi chú khi xuất gói phải được ghi lại.');

        $sizes = (string) $zip->getFromName('bang-size.csv');
        $this->assertStringContainsString('size,nguc(cm)', $sizes);
        $this->assertStringContainsString('"S","84","68"', $sizes, 'Bảng size do người dùng nhập phải vào gói nguyên vẹn.');

        $tech = (string) $zip->getFromName('phieu-ky-thuat.txt');
        $this->assertStringContainsString('MẪU 01', $tech);
        $this->assertStringContainsString('Chất liệu', $tech, 'Phiếu kỹ thuật phải có ô cho xưởng điền.');

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame($p->id, $manifest['project']['id']);
        $this->assertCount(1, $manifest['images']);
        $this->assertSame([], $manifest['skipped'], 'Ảnh tải được thì KHÔNG được nằm trong danh sách bỏ qua.');
        $this->assertStringContainsString('THAM CHIẾU', (string) $manifest['disclaimer'], 'Phải nói rõ ảnh AI là ảnh tham chiếu.');

        $readme = (string) $zip->getFromName('README.txt');
        $this->assertStringContainsString('KHÔNG in hoặc cắt thẳng', $readme, 'README phải cảnh báo không sản xuất thẳng từ ảnh AI.');
    }

    public function test_unreachable_image_is_reported_inside_the_bundle_not_silently_dropped(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $g = $this->generation($u, $p, '/storage/studio/t/khong-ton-tai.jpg');

        $res = $this->actingAs($u)->get('/api/projects/'.$p->id.'/export');
        $res->assertOk();
        $zip = $this->openZip($res->streamedContent() ?: $res->getContent());

        $warn = (string) $zip->getFromName('anh/_KHONG_TAI_DUOC.txt');
        $this->assertNotFalse($zip->locateName('anh/_KHONG_TAI_DUOC.txt'), 'Ảnh lỗi phải có file cảnh báo trong gói.');
        $this->assertStringContainsString((string) $g->id, $warn, 'Phải nêu rõ ảnh nào không tải được.');

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertCount(1, $manifest['skipped']);
        $this->assertSame($g->id, $manifest['skipped'][0]['generation_id']);
        $this->assertStringContainsString('CẢNH BÁO', (string) $zip->getFromName('README.txt'));
    }

    public function test_project_without_images_still_exports_with_instructions(): void
    {
        $u = $this->customer();
        $p = $this->project($u, ['name' => 'BST chưa có ảnh']);

        $res = $this->actingAs($u)->get('/api/projects/'.$p->id.'/export');
        $res->assertOk();
        $zip = $this->openZip($res->streamedContent() ?: $res->getContent());

        $tech = (string) $zip->getFromName('phieu-ky-thuat.txt');
        $this->assertStringContainsString('chưa có ảnh nào', $tech);
        // Bảng size rỗng ⇒ phát MẪU để xưởng điền, không để trống trơn.
        $sizes = (string) $zip->getFromName('bang-size.csv');
        $this->assertStringContainsString('XL', $sizes);
    }
}
