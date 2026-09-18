<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Nút "Lưu Output" (LayersPanel) phải tạo BẢN GHI THT và không tải lại ảnh đã có trên máy chủ.
 *
 * Vì sao có file này: bản cũ chỉ tải file lên rồi chèn một bản ghi GIẢ vào bộ nhớ trình duyệt
 * (id dạng `layer-<số>`). Đo thật trên dev: bản ghi KHÔNG có trong /api/library/data lẫn /api/latest
 * và BIẾN MẤT sau khi tải lại trang — trong khi giao diện vẫn báo đã lưu.
 */
class StudioLayerSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@fabrikai.shop')->firstOrFail();
        $this->actingAs($this->admin);
    }

    /** Ghi một ảnh PNG thật vào storage/app/public/<rel> và trả URL công khai của nó. */
    private function putImage(string $rel): string
    {
        $abs = storage_path('app/public/'.$rel);
        @mkdir(dirname($abs), 0777, true);
        $img = imagecreatetruecolor(48, 48);
        imagefilledrectangle($img, 0, 0, 47, 47, imagecolorallocate($img, 20, 90, 160));
        imagepng($img, $abs);
        imagedestroy($img);

        return '/storage/'.$rel;
    }

    private function refFiles(): array
    {
        $dir = storage_path('app/public/studio/ref/u'.$this->admin->id);

        return is_dir($dir) ? (glob($dir.'/*') ?: []) : [];
    }

    public function test_saving_a_layer_from_a_server_image_creates_a_real_output(): void
    {
        $url = $this->putImage('studio/layer-src.png');

        $res = $this->postJson('/api/layers/save', ['source_url' => $url, 'name' => 'Layer 1'])->assertOk();

        $id = $res->json('generation_id');
        $this->assertIsInt($id, 'Phải trả id THT trong CSDL, không phải chuỗi tạm kiểu layer-...');
        $this->assertDatabaseHas('generations', ['id' => $id, 'model' => 'layer', 'provider' => 'layer', 'media_url' => $url]);
        $this->assertSame($url, $res->json('media_url'), 'Đường nhanh phải dùng LẠI đúng file cũ, không tạo bản sao.');
    }

    public function test_the_saved_layer_appears_in_the_library_and_latest(): void
    {
        $url = $this->putImage('studio/layer-lib.png');
        $this->postJson('/api/layers/save', ['source_url' => $url, 'name' => 'Layer lib'])->assertOk();

        $lib = collect($this->getJson('/api/library/data?per_page=100')->assertOk()->json('items'))->firstWhere('media_url', $url);
        $this->assertNotNull($lib, 'Ảnh vừa lưu PHẢI có trong Thư viện — đây đúng là lỗi người dùng báo.');

        $latest = collect($this->getJson('/api/latest')->assertOk()->json('items'))->firstWhere('media_url', $url);
        $this->assertNotNull($latest, 'Ảnh vừa lưu phải có trong Outputs.');
    }

    public function test_the_fast_path_does_not_upload_anything(): void
    {
        $url = $this->putImage('studio/layer-fast.png');
        $before = $this->refFiles();

        $this->postJson('/api/layers/save', ['source_url' => $url, 'name' => 'Layer fast'])->assertOk();

        $this->assertSame($before, $this->refFiles(), 'nh đã ở trên máy chủ thì KHÔNG được tải lên bản sao nào.');
    }

    public function test_a_composed_layer_is_uploaded_and_saved_for_real(): void
    {
        $res = $this->post('/api/layers/save', [
            'image' => UploadedFile::fake()->image('layer.png', 64, 64),
            'name' => 'Layer ghép',
        ])->assertOk();

        $id = $res->json('generation_id');
        $this->assertDatabaseHas('generations', ['id' => $id, 'model' => 'layer']);

        $url = $res->json('media_url');
        $this->assertStringContainsString('/studio/ref/u'.$this->admin->id.'/', $url, 'Layer ghép phải nằm trong thư mục riêng của người dùng.');
        $this->assertFileExists(storage_path('app/public/'.ltrim(str_replace('/storage/', '', $url), '/')));
    }

    public function test_the_saved_layer_goes_into_the_applied_project(): void
    {
        $project = Project::unguarded(fn () => Project::create([
            'user_id' => $this->admin->id,
            'name' => 'BST Layer',
            'status' => 'active',
        ]));
        $url = $this->putImage('studio/layer-proj.png');

        $res = $this->postJson('/api/layers/save', ['source_url' => $url, 'name' => 'L', 'project_id' => $project->id])->assertOk();

        $this->assertSame($project->id, $res->json('project_id'));
        $this->assertSame($project->id, Generation::findOrFail($res->json('generation_id'))->project_id);
    }

    public function test_a_source_url_that_is_not_a_real_local_file_is_rejected(): void
    {
        $this->postJson('/api/layers/save', ['source_url' => '/storage/khong-he-ton-tai.png'])->assertStatus(422);
        $this->postJson('/api/layers/save', ['source_url' => '/storage/../../etc/passwd'])->assertStatus(422);
        $this->postJson('/api/layers/save', ['source_url' => 'https://example.com/anh.png'])->assertStatus(422);

        $this->assertSame(0, Generation::where('model', 'layer')->count(), 'Không được tạo output trỏ vào hư không.');
    }

    public function test_a_payload_without_any_image_is_rejected(): void
    {
        $this->postJson('/api/layers/save', ['name' => 'Thiếu ảnh'])->assertStatus(422);
    }

    public function test_another_users_private_upload_cannot_be_saved(): void
    {
        $this->putImage('studio/ref/u'.$this->admin->id.'/rieng-cua-admin.png');

        $customer = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $this->actingAs($customer);

        // Customer KHÔNG phải admin nên không được lưu ảnh trong thư mục riêng của người khác.
        $this->postJson('/api/layers/save', ['source_url' => '/storage/studio/ref/u'.$this->admin->id.'/rieng-cua-admin.png'])
            ->assertStatus(422);

        $this->assertSame(0, Generation::where('user_id', $customer->id)->where('model', 'layer')->count());
    }
}
