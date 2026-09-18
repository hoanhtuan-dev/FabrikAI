<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\UploadProjectLink;
use App\Models\User;
use App\Services\ProjectExportService;
use App\Services\StudioLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * BỘ SƯU TẬP → THƯ VIỆN → BẢNG THIẾT KẾ — các bất biến P0 (2026-09-20).
 *
 * Mỗi test ở đây khoá một lỗi ĐÃ ĐO ĐƯỢC trên bản trước, không phải một mong muốn chung chung:
 *
 *   (1) DỌN FILE MỒ CÔI XOÁ ẢNH ĐANG THUỘC BỘ SƯU TẬP (mất dữ liệu thật). Tập "đang được tham
 *       chiếu" không gom bảng upload_project_links ⇒ ảnh gốc của bộ bị coi là rác và bị xoá khỏi đĩa.
 *   (2) KHÁCH TỰ PHỤC VỤ KHÔNG BAO GIỜ CHỐT ĐƯỢC BỘ SƯU TẬP CỦA MÌNH (ngõ cụt vòng đời) —
 *       xem thêm tests/Unit/ProjectWorkflowServiceTest.php.
 *   (3) THÀNH VIÊN NHÓM KHÔNG TẠO ĐƯỢC ẢNH VÀO BỘ SƯU TẬP CHUNG (mâu thuẫn với lời hứa của gói ghế).
 *   (4) GÓI XUẤT CHO XƯỞNG LẤY 60 ẢNH CŨ NHẤT VÀ KHÔNG NÓI LÀ ĐÃ CẮT.
 *   (5) BẢNG THIẾT KẾ CẮT CÒN 60 OUTPUT MÀ VẪN GHI TỔNG THẬT (nói không thật về số lượng).
 *   (6) TRANG KHÁCH DUYỆT IN SLUG TRẠNG THÁI TIẾNG ANH + SAI TỔNG SỐ ẢNH.
 *   (7) GẮN ẢNH TẢI LÊN VÀO BỘ SƯU TẬP XONG KHÔNG THẤY Ở ĐÂU (chỉ ghi, không có đường đọc).
 */
class CollectionFlowP0Test extends TestCase
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

    private function project(User $u, array $attrs = []): Project
    {
        return $u->projects()->create(array_merge(['name' => 'BST Kiểm Chứng'], $attrs));
    }

    /** Ảnh PNG thật trong thư mục riêng của user, trả về rel dạng "studio/ref/u<id>/<name>". */
    private function putUpload(User $u, string $name = 'vai.png'): string
    {
        $rel = 'studio/ref/u'.$u->id.'/'.$name;
        $abs = Storage::disk('public')->path($rel);
        @mkdir(dirname($abs), 0777, true);
        $img = imagecreatetruecolor(24, 24);
        imagepng($img, $abs);
        imagedestroy($img);

        return $rel;
    }

    private function uploadLink(User $u, Project $p, string $rel): UploadProjectLink
    {
        return UploadProjectLink::create(['user_id' => $u->id, 'rel' => $rel, 'project_id' => $p->id]);
    }

    // ── (1) Mất dữ liệu: ảnh thuộc bộ sưu tập không được coi là rác ────────────────────────────────

    public function test_an_upload_attached_to_a_collection_counts_as_in_use(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $rel = $this->putUpload($u);
        $this->uploadLink($u, $p, $rel);

        $data = app(StudioLibraryService::class)->uploadedFiles($u);
        $item = collect($data['items'])->firstWhere('rel', $rel);

        $this->assertNotNull($item);
        $this->assertTrue($item['used'], 'Ảnh đang thuộc bộ sưu tập phải được coi là ĐANG DÙNG.');
        $this->assertSame($p->id, (int) $item['project_id']);

        // Danh sách "file mồ côi" (nút Dọn) tuyệt đối không được chứa ảnh này.
        $unusedRels = collect($data['items'])->reject(fn ($i) => $i['used'])->pluck('rel')->all();
        $this->assertNotContains($rel, $unusedRels, 'Ảnh của bộ sưu tập bị xếp vào nhóm "file mồ côi".');
    }

    public function test_orphan_cleanup_never_deletes_an_upload_attached_to_a_collection(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $rel = $this->putUpload($u, 'vai-quan-trong.png');
        $this->uploadLink($u, $p, $rel);

        $abs = Storage::disk('public')->path($rel);
        // Đẩy mtime ra ngoài cửa sổ ân hạn 10 phút để file đủ điều kiện bị coi là mồ côi.
        touch($abs, time() - 3600);

        app(StudioLibraryService::class)->cleanupUploadedOrphans();

        $this->assertTrue(is_file($abs), 'Ảnh GỐC của bộ sưu tập đã bị "dọn file mồ côi" xoá khỏi đĩa.');
        $this->assertSame(1, UploadProjectLink::where('rel', $rel)->count(), 'Liên kết bộ sưu tập phải còn nguyên.');
    }

    public function test_deleting_a_ref_image_that_belongs_to_a_collection_is_refused(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $rel = $this->putUpload($u, 'chan-vai.png');
        $this->uploadLink($u, $p, $rel);

        $this->actingAs($u)
            ->deleteJson('/api/ref-images/chan-vai.png')
            ->assertStatus(422);

        $this->assertTrue(is_file(Storage::disk('public')->path($rel)));
        $this->assertSame(1, UploadProjectLink::where('rel', $rel)->count());
    }

    // ── (3) Thành viên nhóm ghi được vào bộ sưu tập chung ─────────────────────────────────────────

    public function test_team_member_may_write_into_the_shared_collection(): void
    {
        $owner = User::where('email', 'admin@fabrikai.shop')->firstOrFail();
        app(\App\Services\PlanService::class)->assign($owner, \App\Models\Plan::where('slug', 'pro')->firstOrFail());

        // TeamService::invite() trả về [thành viên, mật khẩu tạm].
        $member = app(\App\Services\TeamService::class)
            ->invite($owner, 'nv@fabrikai.shop', 'Nhân viên', null)[0];

        $project = $this->project($owner);

        $this->assertTrue(studio_project_writable_by($member, $project->id), 'Thành viên nhóm phải ghi được vào bộ sưu tập chung.');
        $this->assertFalse(
            studio_project_writable_by(User::factory()->create(['role' => User::ROLE_CUSTOMER]), $project->id),
            'Người ngoài nhóm không được ghi vào bộ sưu tập này.'
        );

        // Đường thật: lưu layer từ canvas vào bộ sưu tập chung (không cần provider, tạo bản ghi thật).
        // Ảnh nguồn là ảnh CỦA CHÍNH thành viên — quyền đọc file tải lên vẫn theo từng người.
        $src = $this->putUpload($member, 'nguon.png');
        $this->actingAs($member)
            ->postJson('/api/layers/save', ['source_url' => '/storage/'.$src, 'name' => 'Layer của nhân viên', 'project_id' => $project->id])
            ->assertOk()
            ->assertJsonPath('project_id', $project->id);
    }

    /**
     * Người ngoài gửi một bộ sưu tập không thuộc quyền: thao tác KHÔNG hỏng (người dùng không mất
     * việc đang làm) nhưng kết quả KHÔNG được rơi vào bộ của người khác, và phải có CẢNH BÁO —
     * im lặng chính là lỗi cũ.
     */
    public function test_stranger_cannot_write_into_someone_elses_collection(): void
    {
        $owner = $this->customer();
        $project = $this->project($owner);
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $src = $this->putUpload($stranger, 'cua-toi.png');

        $res = $this->actingAs($stranger)->postJson('/api/layers/save', [
            'source_url' => '/storage/'.$src,
            'project_id' => $project->id,
        ])->assertOk();

        $this->assertNull($res->json('project_id'), 'Kết quả không được rơi vào bộ sưu tập của người khác.');
        $this->assertNotEmpty($res->json('project_warning'), 'Phải nói rõ vì sao không vào được bộ đã chọn.');
        $this->assertSame(0, Generation::where('project_id', $project->id)->count());
    }

    /** Nhưng ở đường TỐN CREDIT (/api/generate) thì phải chặn hẳn — báo lỗi đúng trường project_id. */
    public function test_generate_refuses_an_unwritable_collection_with_a_field_error(): void
    {
        $owner = $this->customer();
        $project = $this->project($owner);
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($stranger)
            ->postJson('/api/generate', ['prompt' => 'ao dai', 'project_id' => $project->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_id');

        $this->assertSame(0, Generation::where('project_id', $project->id)->count());
    }

    // ── (P1.3) Gắn hàng loạt ảnh vào bộ sưu tập ───────────────────────────────────────────────

    public function test_bulk_attach_links_multiple_generations_and_reports_per_item(): void
    {
        $u = $this->customer();
        $project = $this->project($u);
        $rel = $this->putUpload($u, 'src.png');
        $g1 = Generation::create(['user_id' => $u->id, 'project_id' => null, 'type' => 'image', 'status' => 'completed', 'media_url' => '/storage/'.$rel, 'prompt' => 'a', 'credits_cost' => 0]);
        $g2 = Generation::create(['user_id' => $u->id, 'project_id' => null, 'type' => 'image', 'status' => 'completed', 'media_url' => '/storage/'.$rel, 'prompt' => 'b', 'credits_cost' => 0]);

        $res = $this->actingAs($u)->postJson('/api/projects/'.$project->id.'/generations', [
            'ids' => [$g1->id, $g2->id],
            'action' => 'attach',
        ])->assertOk();

        $this->assertSame(2, $res->json('changed'));
        $this->assertSame(0, $res->json('failed'));
        $this->assertSame(2, Generation::where('project_id', $project->id)->count());
        $this->assertSame(2, $res->json('done'));
    }

    public function test_bulk_attach_skips_foreign_generations_and_reports_failures(): void
    {
        $u = $this->customer();
        $project = $this->project($u);
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $rel = $this->putUpload($stranger, 's.png');
        $mine = Generation::create(['user_id' => $u->id, 'project_id' => null, 'type' => 'image', 'status' => 'completed', 'media_url' => '/storage/'.$rel, 'prompt' => 'm', 'credits_cost' => 0]);
        $theirs = Generation::create(['user_id' => $stranger->id, 'project_id' => null, 'type' => 'image', 'status' => 'completed', 'media_url' => '/storage/'.$rel, 'prompt' => 't', 'credits_cost' => 0]);

        $res = $this->actingAs($u)->postJson('/api/projects/'.$project->id.'/generations', [
            'ids' => [$mine->id, $theirs->id],
            'action' => 'attach',
        ])->assertOk();

        $this->assertSame(1, $res->json('changed'));
        $this->assertSame(1, $res->json('failed'));
        $this->assertSame(1, Generation::where('project_id', $project->id)->count());
    }

    // ── (4) Gói xuất: ảnh mới nhất + nói thật khi bị cắt ──────────────────────────────────────────

    public function test_export_bundle_keeps_the_newest_images_and_flags_truncation(): void
    {
        $u = $this->customer();
        $project = $this->project($u);

        // 61 ảnh (vượt trần 60) cùng trỏ vào MỘT file thật — đủ để kiểm thứ tự và cờ "bị cắt".
        $rel = $this->putUpload($u, 'anh.png');
        $media = '/storage/'.$rel;
        $ids = [];
        for ($i = 0; $i < 61; $i++) {
            $ids[] = Generation::create([
                'user_id' => $u->id, 'project_id' => $project->id, 'type' => 'image',
                'status' => 'completed', 'media_url' => $media, 'prompt' => 'ao so '.$i, 'credits_cost' => 0,
            ])->id;
        }

        $bundle = app(ProjectExportService::class)->build($project);
        $newest = max($ids);
        $oldest = min($ids);

        try {
            $manifest = $bundle['manifest'];

            $this->assertSame(61, $manifest['images_total_in_collection']);
            $this->assertSame(60, $manifest['images_in_bundle']);
            $this->assertTrue($manifest['truncated'], 'Gói bị cắt mà manifest không nói gì.');
            $this->assertStringContainsString('MỚI NHẤT', (string) $manifest['truncated_note']);

            $packed = array_column($manifest['images'], 'generation_id');
            $this->assertContains($newest, $packed, 'Ảnh MỚI NHẤT phải có trong gói.');
            $this->assertNotContains($oldest, $packed, 'Gói không được lấy ảnh cũ nhất thay vì ảnh mới nhất.');

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($bundle['path']) === true);
            $readme = (string) $zip->getFromName('README.txt');
            $zip->close();
            $this->assertStringContainsString('LƯU Ý VỀ SỐ LƯỢNG', $readme, 'README phải nói rõ gói đã bị cắt.');
        } finally {
            @unlink($bundle['path']);
        }
    }

    // ── (5)(7) Bảng thiết kế: nói thật số lượng + thấy được ảnh gốc ───────────────────────────────

    public function test_workspace_payload_carries_reference_images_and_honest_counts(): void
    {
        $u = $this->customer();
        $project = $this->project($u);

        $rel = $this->putUpload($u, 'vai-goc.png');
        $this->uploadLink($u, $project, $rel);

        Generation::create([
            'user_id' => $u->id, 'project_id' => $project->id, 'type' => 'image',
            'status' => 'completed', 'media_url' => '/storage/'.$rel, 'prompt' => 'ao', 'credits_cost' => 0,
        ]);

        $res = $this->actingAs($u)->getJson('/api/projects/'.$project->id)->assertOk();

        // (7) Ảnh tải lên phải có ĐƯỜNG ĐỌC phía bộ sưu tập — trước đây gắn xong là mất dấu.
        $res->assertJsonPath('uploads.0.name', 'vai-goc.png');
        $res->assertJsonPath('uploads.0.url', '/storage/'.$rel);

        // (5) Ba con số tường minh để giao diện không phải đoán.
        $this->assertSame(1, (int) $res->json('generations_count'));
        $this->assertSame(1, (int) $res->json('generations_shown'));
        $this->assertFalse((bool) $res->json('generations_truncated'));
    }

    // ── (6) Trang khách duyệt: nhãn tiếng Việt + tổng số thật ─────────────────────────────────────

    public function test_share_page_shows_vietnamese_status_and_the_real_total(): void
    {
        $u = $this->customer();
        $project = $this->project($u);
        $project->forceFill(['status' => Project::STATUS_REVIEW])->save();

        $rel = $this->putUpload($u, 'anh.png');
        Generation::create([
            'user_id' => $u->id, 'project_id' => $project->id, 'type' => 'image',
            'status' => 'completed', 'media_url' => '/storage/'.$rel, 'prompt' => 'ao', 'credits_cost' => 0,
        ]);

        $token = $this->actingAs($u)->postJson('/api/projects/'.$project->id.'/share')->assertOk()->json('token');

        $res = $this->get('/chia-se/'.$token);
        $res->assertOk();
        // Nhãn do ProjectWorkflowService sinh ra — KHÔNG phải slug tiếng Anh.
        $res->assertSee('Chờ duyệt', false);
        $res->assertDontSee('>review<', false);
        $res->assertSee('1 ảnh', false);
    }

    // ── (Bổ sung) Link chia sẻ là việc QUẢN LÝ, không phải việc XEM ────────────────────────────────

    public function test_team_member_cannot_create_or_revoke_the_client_share_link(): void
    {
        $owner = User::where('email', 'admin@fabrikai.shop')->firstOrFail();
        app(\App\Services\PlanService::class)->assign($owner, \App\Models\Plan::where('slug', 'pro')->firstOrFail());
        $member = app(\App\Services\TeamService::class)
            ->invite($owner, 'nv2@fabrikai.shop', 'Nhân viên 2', null)[0];

        $project = $this->project($owner);
        $token = $this->actingAs($owner)->postJson('/api/projects/'.$project->id.'/share')->assertOk()->json('token');

        // Thành viên vẫn XEM được trạng thái (đọc), nhưng không tạo và không thu hồi được.
        $this->actingAs($member)->getJson('/api/projects/'.$project->id.'/share')->assertOk();
        $this->actingAs($member)->postJson('/api/projects/'.$project->id.'/share')->assertForbidden();
        $this->actingAs($member)->deleteJson('/api/projects/'.$project->id.'/share/'.$token)->assertForbidden();
    }
}
