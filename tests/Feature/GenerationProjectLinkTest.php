<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * nh SINH RA từ MỌI thao tác phải được LƯU vào đúng bộ sưu tập (dự án) đang áp dụng.
 *
 * Vì sao có file này: trước 2026-09-20 CHỈ `/api/generate` và `/api/render-video` nhận
 * `project_id`. Mọi thao tác phái sinh tạo generation với `project_id = null` — kể cả khi client
 * GỬI KÈM project_id thì endpoint vẫn ÂM THẦM BỎ QUA. Đã đo thật trên môi trường dev:
 * POST /api/reframe kèm `project_id = 1` trả 200 và tạo ảnh, nhưng trong DB `project_id = NULL`.
 *
 * Hệ quả với người dùng: ảnh sửa/ghép/nâng cấp rơi RA NGOÀI bộ sưu tập, trái với lời hứa ghi
 * ngay trong `store.applyProject()` — "ảnh/video tạo mới sẽ tự gắn vào".
 */
class GenerationProjectLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::where('email', 'admin@fabrikai.shop')->firstOrFail();
        $this->actingAs($this->user);
        // `user_id`/`status` CỐ Ý không nằm trong $fillable (chống mass-assignment) — seeder cũng
        // dùng unguarded, nên test đi đúng con đường đó thay vì nới lỏng model vì một bài test.
        $this->project = Project::unguarded(fn () => Project::create([
            'user_id' => $this->user->id,
            'name' => 'BST Kiểm Chứng',
            'status' => 'active',
        ]));
    }

    /** Ảnh nguồn thật trên đĩa (endpoint đọc file bằng GD, không gọi provider). */
    private function makeSource(string $file, int $w = 64, int $h = 64): void
    {
        $img = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, ($x * 5) % 256, ($y * 5) % 256, (($x + $y) * 3) % 256));
            }
        }
        imagepng($img, storage_path('app/public/'.$file));
        imagedestroy($img);
    }

    private function makeGeneration(array $attrs = []): Generation
    {
        return Generation::create(array_merge([
            'user_id' => $this->user->id,
            'type' => 'image',
            'status' => 'completed',
            'prompt' => 'nguồn',
            'provider' => 'qwen',
            'model' => 'test',
            'media_url' => '/storage/plink-src.png',
            'credits_cost' => 0,
        ], $attrs));
    }

    // ── 4 endpoint tạo generation TRỰC TIẾP (trước đây không hề có project_id) ──

    public function test_reframe_saves_the_result_into_the_applied_project(): void
    {
        $this->makeSource('plink-reframe.png');
        $res = $this->postJson('/api/reframe', [
            'image' => '/storage/plink-reframe.png',
            'ratio' => '3:4',
            'project_id' => $this->project->id,
        ])->assertOk();

        $gen = Generation::findOrFail($res->json('generation_id'));
        $this->assertSame($this->project->id, $gen->project_id, 'Ảnh cắt ra phải nằm trong bộ sưu tập đang áp dụng.');
        $this->assertSame($this->project->id, $res->json('project_id'), 'Phản hồi phải trả project_id để client lưu ngay.');
    }

    public function test_upscale_saves_the_result_into_the_applied_project(): void
    {
        $this->makeSource('plink-upscale.png');
        $res = $this->postJson('/api/upscale', [
            'image' => '/storage/plink-upscale.png',
            'scale' => 2,
            'refine' => 0,
            'project_id' => $this->project->id,
        ])->assertOk();

        $gen = Generation::findOrFail($res->json('generation_id'));
        $this->assertSame($this->project->id, $gen->project_id, 'Ảnh nâng cấp phải nằm trong bộ sưu tập đang áp dụng.');
    }

    public function test_look_saves_the_result_into_the_applied_project(): void
    {
        $this->makeSource('plink-look.png');
        $res = $this->postJson('/api/look', [
            'image' => '/storage/plink-look.png',
            'look' => 'cinematic',
            'level' => 5,
            'project_id' => $this->project->id,
        ])->assertOk();

        $gen = Generation::findOrFail($res->json('generation_id'));
        $this->assertSame($this->project->id, $gen->project_id, 'nh áp Look phải nằm trong bộ sưu tập đang áp dụng.');
    }

    public function test_region_edit_saves_the_result_into_the_applied_project(): void
    {
        $this->makeSource('plink-region.png');
        $src = $this->makeGeneration(['media_url' => '/storage/plink-region.png']);

        $res = $this->postJson('/api/generations/'.$src->id.'/region', [
            'op' => 'erase',
            'region' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.3],
            'source_url' => '/storage/plink-region.png',
            'project_id' => $this->project->id,
        ])->assertOk();

        $gen = Generation::findOrFail($res->json('generation_id'));
        $this->assertSame($this->project->id, $gen->project_id, 'Ảnh xoá vùng phải nằm trong bộ sưu tập đang áp dụng.');
    }

    // ── THỪA HƯỞNG: không gửi project_id thì lấy dự án của ảnh NGUỒN ──

    public function test_region_edit_inherits_the_source_project_when_none_is_sent(): void
    {
        $this->makeSource('plink-inherit.png');
        $src = $this->makeGeneration(['media_url' => '/storage/plink-inherit.png', 'project_id' => $this->project->id]);

        $res = $this->postJson('/api/generations/'.$src->id.'/region', [
            'op' => 'erase',
            'region' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.3],
            'source_url' => '/storage/plink-inherit.png',
        ])->assertOk();

        $gen = Generation::findOrFail($res->json('generation_id'));
        $this->assertSame($this->project->id, $gen->project_id, 'Sửa một ảnh thuộc bộ sưu tập nào thì kết quả phải ở lại bộ sưu tập đó.');
    }

    public function test_inpaint_inherits_the_source_project_when_none_is_sent(): void
    {
        $this->makeSource('plink-inpaint-inh.png');
        $src = $this->makeGeneration(['media_url' => '/storage/plink-inpaint-inh.png', 'project_id' => $this->project->id]);

        $res = $this->postJson('/api/generations/'.$src->id.'/inpaint', [
            'prompt' => 'đổi màu váy sang xanh',
        ])->assertOk();

        $gen = Generation::findOrFail($res->json('generation_id'));
        $this->assertSame($this->project->id, $gen->project_id);
    }

    // ── Endpoint đi qua queueGeneration: phải nhận project_id từ client ──

    public function test_inpaint_saves_the_result_into_the_applied_project(): void
    {
        $this->makeSource('plink-inpaint.png');
        $res = $this->postJson('/api/inpaint', [
            'prompt' => 'bỏ logo trên túi',
            'source_url' => '/storage/plink-inpaint.png',
            'project_id' => $this->project->id,
        ])->assertOk();

        $gen = Generation::findOrFail($res->json('generation_id'));
        $this->assertSame($this->project->id, $gen->project_id, 'Ảnh sửa phải nằm trong bộ sưu tập đang áp dụng.');
    }

    public function test_generate_response_returns_the_project_id(): void
    {
        $res = $this->postJson('/api/generate', [
            'prompt' => 'a silk gown',
            'project_id' => $this->project->id,
        ])->assertOk();

        $id = $res->json('items.0.generation_id');
        $this->assertSame($this->project->id, $res->json('items.0.project_id'), 'Phản hồi tạo ảnh phải trả project_id để client lưu ngay, không chờ /api/latest.');
        $this->assertSame($this->project->id, Generation::findOrFail($id)->project_id);
    }

    public function test_show_returns_the_project_id_and_name(): void
    {
        $gen = $this->makeGeneration(['project_id' => $this->project->id]);

        $this->getJson('/api/generations/'.$gen->id)
            ->assertOk()
            ->assertJsonPath('project_id', $this->project->id)
            ->assertJsonPath('project', 'BST Kiểm Chứng');
    }

    // ── An toàn: dự án của NGƯỜI KHÁC không được gắn, nhưng thao tác vẫn phải thành công ──

    public function test_a_project_of_another_user_is_ignored_without_breaking_the_operation(): void
    {
        $other = User::create([
            'name' => 'Người khác',
            'email' => 'nguoi-khac@example.com',
            'password' => bcrypt('x'),
        ]);
        $foreign = Project::unguarded(fn () => Project::create(['user_id' => $other->id, 'name' => 'BST của người khác', 'status' => 'active']));

        $this->makeSource('plink-foreign.png');
        $res = $this->postJson('/api/reframe', [
            'image' => '/storage/plink-foreign.png',
            'ratio' => '3:4',
            'project_id' => $foreign->id,
        ]);

        $res->assertOk();   // thao tác KHÔNG được hỏng vì một id dự án không hợp lệ
        $gen = Generation::findOrFail($res->json('generation_id'));
        $this->assertNull($gen->project_id, 'Không được gắn ảnh vào dự án của người khác.');
    }
}
