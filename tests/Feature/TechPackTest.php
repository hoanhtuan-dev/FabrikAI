<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\TechPack;
use App\Models\User;
use App\Services\TechPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * PHIẾU KỸ THUẬT TƯƠNG TÁC (tech pack) — Việc #3, 2026-09-26.
 *
 * Khoá sáu bất biến:
 *   (a) chuẩn hoá THUẦN: cắt theo trần, khử trùng size, bỏ dòng chưa có điểm đo, nhận cả cách nhập
 *       nhanh bằng dấu gạch chéo;
 *   (b) completeness NÓI RÕ còn thiếu gì — nhưng KHÔNG chặn lưu/xuất (thiếu ô không phải lỗi);
 *   (c) quyền: khách 401 · người khác 403 · chủ bộ sưu tập 200 (đọc và ghi khác nhau);
 *   (d) GÓI XUẤT dùng THÔNG SỐ THẬT và BỎ mẫu chấm trống khi phiếu đã có nội dung;
 *   (e) chưa lập phiếu ⇒ gói vẫn xuất được với mẫu trắng + câu chỉ đường (không phá hành vi cũ);
 *   (f) bản IN A4 trả 200 cho chủ bộ sưu tập và KHÔNG lộ cho người khác.
 */
class TechPackTest extends TestCase
{
    use RefreshDatabase;

    /** 1 ảnh JPEG 8x8 hợp lệ — dùng lại cách của ProjectExportTest. */
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
        return $u->projects()->create(array_merge(['name' => 'Đầm linen Thu Đông'], $attrs));
    }

    private function generation(User $u, Project $p): Generation
    {
        return Generation::create([
            'user_id' => $u->id,
            'project_id' => $p->id,
            'type' => 'image',
            'status' => 'completed',
            'prompt' => 'đầm linen trắng ngà dáng suông',
            'model' => 'qwen-image-3.0-pro',
            'provider' => 'qwen',
            'resolution' => '2K',
            'media_url' => '/storage/studio/t/dam-linen.jpg',
            'credits_cost' => 1,
        ]);
    }

    private function openZip(string $content): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'tptest-').'.zip';
        file_put_contents($path, $content);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Nội dung tải về phải là ZIP hợp lệ.');

        return $zip;
    }

    /** Một phiếu đã đủ trường cốt lõi + bảng thông số. */
    private function fullPayload(): array
    {
        return [
            'fields' => [
                'style_no' => 'FD-2610',
                'style_name' => 'Đầm linen cổ V',
                'fabric' => 'Linen 55% · cotton 45% · 180 gsm',
                'colorways' => 'Trắng ngà (11-0602)',
                'construction' => 'Vai 2 kim · sườn 4 chỉ · lai cuốn 0.5cm',
            ],
            'sizes' => ['s', 'M', 'm', 'L'],
            'measurements' => [
                ['point' => 'Ngực', 'tolerance' => '±1', 'values' => ['S' => '96', 'M' => '100', 'L' => '104']],
                ['point' => 'Dài áo', 'tolerance' => '±0.5', 'values' => '62 / 64 / 66'],
            ],
        ];
    }

    // ── (a) CHUẨN HOÁ THUẦN ────────────────────────────────────────────────────────────────────

    public function test_normalize_caps_dedups_sizes_and_parses_slash_values(): void
    {
        $pack = TechPackService::normalize($this->fullPayload());

        $this->assertSame(['S', 'M', 'L'], $pack['sizes'], 'Size phải viết HOA, khử trùng, giữ thứ tự.');
        $this->assertSame('FD-2610', $pack['fields']['style_no']);
        // Dòng thứ hai nhập nhanh bằng gạch chéo — phải khớp đúng thứ tự cột size.
        $this->assertSame(['S' => '62', 'M' => '64', 'L' => '66'], $pack['measurements'][1]['values']);
        $this->assertSame('±1', $pack['measurements'][0]['tolerance']);
    }

    public function test_normalize_drops_rows_without_a_measurement_point(): void
    {
        // Dòng trống lúc đang gõ KHÔNG phải dữ liệu; nhưng cũng KHÔNG được làm hỏng cả phiếu.
        $pack = TechPackService::normalize([
            'fields' => ['style_no' => 'X'],
            'sizes' => ['M'],
            'measurements' => [['point' => '', 'values' => ['M' => '1']], ['point' => 'Eo', 'values' => ['M' => '70']]],
        ]);

        $this->assertCount(1, $pack['measurements']);
        $this->assertSame('Eo', $pack['measurements'][0]['point']);
    }

    public function test_normalize_truncates_overlong_values_instead_of_rejecting(): void
    {
        $pack = TechPackService::normalize([
            'fields' => ['style_no' => str_repeat('A', 200)],
            'sizes' => array_map(fn ($i) => 'S'.$i, range(1, 20)),
            'measurements' => [],
        ]);

        $this->assertSame(TechPackService::TEXT_FIELDS['style_no'], mb_strlen($pack['fields']['style_no']));
        $this->assertCount(TechPackService::MAX_SIZE_LABELS, $pack['sizes']);
    }

    // ── (b) COMPLETENESS ───────────────────────────────────────────────────────────────────────

    public function test_completeness_names_what_is_missing_without_blocking(): void
    {
        $empty = TechPackService::completeness(TechPackService::empty());
        $this->assertFalse($empty['ready']);
        $this->assertContains('Vải chính', $empty['missing']);
        $this->assertSame(0, $empty['filled']);

        $full = TechPackService::completeness(TechPackService::normalize($this->fullPayload()));
        $this->assertTrue($full['ready'], 'Đủ 4 trường cốt lõi + bảng thông số ⇒ sẵn sàng gửi xưởng.');
        $this->assertSame([], $full['missing']);
    }

    // ── (c) API + QUYỀN ────────────────────────────────────────────────────────────────────────

    public function test_guest_and_outsiders_are_blocked(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);

        $this->putJson('/api/projects/'.$p->id.'/tech-pack', $this->fullPayload())->assertStatus(401);
        $this->actingAs($other)->putJson('/api/projects/'.$p->id.'/tech-pack', $this->fullPayload())->assertForbidden();
        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/tech-pack')->assertForbidden();
        $this->assertSame(0, TechPack::count(), 'Người ngoài không được ghi được gì.');
    }

    public function test_api_round_trip_and_reset(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $put = $this->actingAs($u)->putJson('/api/projects/'.$p->id.'/tech-pack', $this->fullPayload())->assertOk();
        $this->assertTrue($put->json('is_set'));
        $this->assertTrue($put->json('completeness.ready'));
        $this->assertSame('FD-2610', $put->json('tech_pack.fields.style_no'));

        $get = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/tech-pack')->assertOk();
        $this->assertSame(['S', 'M', 'L'], $get->json('tech_pack.sizes'));
        $this->assertSame(TechPackService::MAX_SIZE_LABELS, $get->json('shape.limits.max_sizes'));

        $this->actingAs($u)->deleteJson('/api/projects/'.$p->id.'/tech-pack')->assertOk();
        $this->assertSame(0, TechPack::count());
    }

    public function test_api_rejects_an_overlong_field(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $this->actingAs($u)->putJson('/api/projects/'.$p->id.'/tech-pack', [
            'fields' => ['construction' => str_repeat('x', TechPackService::TEXT_FIELDS['construction'] + 1)],
        ])->assertStatus(422)->assertJsonValidationErrors('fields.construction');
    }

    // ── (d) + (e) GÓI XUẤT ─────────────────────────────────────────────────────────────────────

    public function test_export_carries_the_real_tech_pack_and_drops_the_blank_template(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        Storage::disk('public')->put('studio/t/dam-linen.jpg', base64_decode(self::TINY_JPEG_B64));
        $this->generation($u, $p);
        $this->actingAs($u)->putJson('/api/projects/'.$p->id.'/tech-pack', $this->fullPayload())->assertOk();

        $res = $this->actingAs($u)->get('/api/projects/'.$p->id.'/export');
        $res->assertOk();
        $zip = $this->openZip($res->streamedContent() ?: $res->getContent());

        $tech = (string) $zip->getFromName('phieu-ky-thuat.txt');
        $this->assertStringContainsString('FD-2610', $tech, 'Mã hàng thật phải nằm trong phiếu gửi xưởng.');
        $this->assertStringContainsString('Linen 55%', $tech);
        $this->assertStringContainsString('Ngực', $tech, 'Bảng thông số phải có trong phiếu.');
        $this->assertStringNotContainsString('Chất liệu      : .....', $tech,
            'Phiếu đã có nội dung thì KHÔNG được phát mẫu chấm trống cho từng mẫu nữa.');
        $this->assertStringContainsString('MẪU 01', $tech, 'Danh sách mẫu trong gói vẫn phải còn.');

        $this->assertNotFalse($zip->locateName('bang-thong-so.csv'), 'Phiếu có bảng thông số ⇒ gói phải có CSV.');
        $csv = (string) $zip->getFromName('bang-thong-so.csv');
        $this->assertStringContainsString('"diem_do","dung_sai","S","M","L"', $csv);
        $this->assertStringContainsString('"Ngực","±1","96","100","104"', $csv);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertTrue($manifest['tech_pack']['is_set']);
        $this->assertTrue($manifest['tech_pack']['ready']);
        $this->assertSame(2, $manifest['tech_pack']['measurement_rows']);
    }

    public function test_export_without_a_tech_pack_keeps_the_blank_template_and_shows_the_way(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        Storage::disk('public')->put('studio/t/dam-linen.jpg', base64_decode(self::TINY_JPEG_B64));
        $this->generation($u, $p);

        $res = $this->actingAs($u)->get('/api/projects/'.$p->id.'/export');
        $res->assertOk();
        $zip = $this->openZip($res->streamedContent() ?: $res->getContent());

        $tech = (string) $zip->getFromName('phieu-ky-thuat.txt');
        $this->assertStringContainsString('CHƯA lập phiếu kỹ thuật', $tech);
        $this->assertStringContainsString('Chất liệu      : .....', $tech, 'Chưa có phiếu ⇒ giữ mẫu trắng cho xưởng điền.');
        $this->assertStringContainsString('Phiếu kỹ thuật', $tech, 'Phải chỉ đường lập phiếu trong FabrikAI.');
        $this->assertFalse($zip->locateName('bang-thong-so.csv'), 'Chưa có bảng thông số ⇒ không phát CSV rỗng.');

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertFalse($manifest['tech_pack']['is_set']);
    }

    // ── (f) BẢN IN A4 ──────────────────────────────────────────────────────────────────────────

    /**
     * KHÁCH và NGƯỜI NGOÀI phải bị chặn.
     *
     * Chú ý cách viết: bài này KHÔNG gọi actingAs() trước lượt của khách — actingAs() đặt người dùng cho
     * CẢ PHẦN CÒN LẠI của bài test, nên trộn hai lượt vào một bài sẽ khiến lượt "khách" thật ra vẫn đang
     * đăng nhập và bài test xanh vì lý do sai (bản đầu của tệp này đã dính đúng bẫy đó).
     */
    public function test_print_view_is_blocked_for_guests_and_outsiders(): void
    {
        $owner = $this->customer();
        $p = $this->project($owner);

        $this->get('/du-an/'.$p->id.'/phieu-ky-thuat')->assertRedirect();   // khách ⇒ về đăng nhập

        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($other)->get('/du-an/'.$p->id.'/phieu-ky-thuat')->assertForbidden();
    }

    public function test_print_view_renders_for_the_owner(): void
    {
        $owner = $this->customer();
        $p = $this->project($owner);
        $this->actingAs($owner)->putJson('/api/projects/'.$p->id.'/tech-pack', $this->fullPayload())->assertOk();

        $res = $this->actingAs($owner)->get('/du-an/'.$p->id.'/phieu-ky-thuat')->assertOk();
        $res->assertSee('PHIẾU KỸ THUẬT', false);
        $res->assertSee('FD-2610', false);
        $res->assertSee('Đầm linen Thu Đông', false);
        $res->assertSee('Ngực', false);
        // Trang in KHÔNG được kéo theo vỏ ứng dụng (nếu không thì in ra giấy cả thanh điều hướng).
        $res->assertDontSee('@vite', false);
    }

    public function test_print_view_warns_when_the_pack_is_incomplete(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->actingAs($u)->putJson('/api/projects/'.$p->id.'/tech-pack', [
            'fields' => ['style_no' => 'FD-1'],
        ])->assertOk();

        $this->actingAs($u)->get('/du-an/'.$p->id.'/phieu-ky-thuat')
            ->assertOk()
            ->assertSee('Phiếu còn thiếu', false)
            ->assertSee('Vải chính', false);
    }
}
