<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\UploadProjectLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ẢNH TẢI LÊN phải gắn được vào BỘ SƯU TẬP như ảnh do AI tạo.
 *
 * Vì sao cần: ảnh tải lên là FILE trên đĩa, KHÔNG có dòng trong CSDL, nên trước đây không có cách
 * nào đưa chúng vào bộ sưu tập — dù chúng nằm CÙNG một Thư viện với ảnh AI tạo và thường chính là
 * ảnh gốc của cả bộ. Quan hệ nay nằm ở bảng upload_project_links.
 */
class UploadProjectLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    /** Tạo file thật trong thư mục RIÊNG của user: studio/ref/u<id>/… */
    private function putUpload(User $user, string $name = 'anh.png'): string
    {
        $rel = 'studio/ref/u'.$user->id.'/'.$name;
        $abs = storage_path('app/public/'.$rel);
        @mkdir(dirname($abs), 0777, true);
        $img = imagecreatetruecolor(32, 32);
        imagepng($img, $abs);
        imagedestroy($img);

        return $rel;
    }

    private function makeProject(User $owner, string $name = 'BST Kiểm Chứng'): Project
    {
        return Project::unguarded(fn () => Project::create([
            'user_id' => $owner->id,
            'name' => $name,
            'status' => 'active',
        ]));
    }

    private function attach(Project $project, string $rel, string $action = 'attach')
    {
        return $this->postJson('/api/projects/'.$project->id.'/uploads', ['rel' => $rel, 'action' => $action]);
    }

    public function test_an_upload_can_be_attached_to_a_project(): void
    {
        $this->actingAs($this->admin);
        $project = $this->makeProject($this->admin);
        $rel = $this->putUpload($this->admin);

        $this->attach($project, $rel)->assertOk()->assertJsonPath('project_id', $project->id);

        $this->assertDatabaseHas('upload_project_links', [
            'user_id' => $this->admin->id,
            'rel' => $rel,
            'project_id' => $project->id,
        ]);
    }

    public function test_the_upload_listing_reports_the_project_it_belongs_to(): void
    {
        $this->actingAs($this->admin);
        $project = $this->makeProject($this->admin);
        $rel = $this->putUpload($this->admin);
        $this->attach($project, $rel)->assertOk();

        $items = collect($this->getJson('/api/uploads')->assertOk()->json('items'));
        $item = $items->firstWhere('rel', $rel);

        $this->assertNotNull($item, 'File vừa tạo phải có trong danh sách ảnh tải lên.');
        $this->assertSame($project->id, $item['project_id'], 'Danh sách phải nói ảnh này thuộc bộ sưu tập nào.');
    }

    public function test_an_upload_can_be_detached_from_a_project(): void
    {
        $this->actingAs($this->admin);
        $project = $this->makeProject($this->admin);
        $rel = $this->putUpload($this->admin);
        $this->attach($project, $rel)->assertOk();

        $this->attach($project, $rel, 'detach')->assertOk()->assertJsonPath('project_id', null);

        $this->assertDatabaseMissing('upload_project_links', ['rel' => $rel]);
    }

    public function test_attaching_again_moves_the_upload_to_the_new_project(): void
    {
        $this->actingAs($this->admin);
        $first = $this->makeProject($this->admin, 'BST Một');
        $second = $this->makeProject($this->admin, 'BST Hai');
        $rel = $this->putUpload($this->admin);

        $this->attach($first, $rel)->assertOk();
        $this->attach($second, $rel)->assertOk();

        $this->assertSame(1, UploadProjectLink::where('rel', $rel)->count(), 'Một ảnh chỉ thuộc MỘT bộ sưu tập.');
        $this->assertDatabaseHas('upload_project_links', ['rel' => $rel, 'project_id' => $second->id]);
    }

    public function test_a_path_traversal_attempt_is_rejected(): void
    {
        $this->actingAs($this->admin);
        $project = $this->makeProject($this->admin);

        $this->attach($project, 'studio/ref/../../../etc/passwd')->assertStatus(403);
        $this->attach($project, 'khong/thuoc/kho/nao.png')->assertStatus(403);

        $this->assertSame(0, UploadProjectLink::count(), 'Không được ghi liên kết cho đường dẫn không hợp lệ.');
    }

    public function test_an_upload_of_another_user_cannot_be_attached(): void
    {
        $customer = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $this->actingAs($customer);
        $project = $this->makeProject($customer);

        // Thư mục riêng của NGỜI KHÁC — customer không phải admin nên không được chạm vào.
        $foreign = $this->putUpload($this->admin, 'cua-nguoi-khac.png');

        $this->attach($project, $foreign)->assertStatus(403);
        $this->assertSame(0, UploadProjectLink::count(), 'Không được gắn ảnh của tài khoản khác.');
    }

    public function test_a_project_of_another_user_cannot_be_used(): void
    {
        $this->actingAs($this->admin);
        $rel = $this->putUpload($this->admin);

        $customer = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $foreignProject = $this->makeProject($customer, 'BST của người khác');

        $this->attach($foreignProject, $rel)->assertStatus(403);
    }

    public function test_deleting_the_file_removes_the_link(): void
    {
        $this->actingAs($this->admin);
        $project = $this->makeProject($this->admin);
        $rel = $this->putUpload($this->admin);
        $this->attach($project, $rel)->assertOk();

        $this->postJson('/api/uploads/delete', ['rels' => [$rel]])->assertOk();

        // (tham số 3 của assertDatabaseMissing là CONNECTION, không phải thông báo — đừng truyền vào)
        $this->assertDatabaseMissing('upload_project_links', ['rel' => $rel]);
    }

    public function test_deleting_the_project_removes_the_link(): void
    {
        $this->actingAs($this->admin);
        $project = $this->makeProject($this->admin);
        $rel = $this->putUpload($this->admin);
        $this->attach($project, $rel)->assertOk();

        $this->deleteJson('/api/projects/'.$project->id)->assertOk();

        $this->assertDatabaseMissing('upload_project_links', ['rel' => $rel]);
    }
}
