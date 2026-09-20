<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SuggestLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [Đợt 0.8 — 2026-09-17] Ba nợ bảo mật / hiệu năng vừa:
 *   (a) StylistDataController::data()/presets() nằm trong nhóm route PUBLIC nhưng gọi
 *       ensureTables() → Schema::create ⇒ khách VÔ DANH chỉ cần GET là chạy DDL trên DB.
 *   (b) SuggestLibraryService::counts() chạy 4 lệnh COUNT riêng = 4 vòng SQL cho cùng bảng.
 *   (c) studio_generation_error() trả NGUYÊN message thô cho client khi APP_DEBUG=true,
 *       lộ đường dẫn tuyệt đối + SQL (nếu host vô tình bật debug).
 */
class StudioDebtFixTest extends TestCase
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

    // ── (a) DDL không được chạy từ GET công khai ──────────────────────────

    public function test_public_stylist_read_does_not_run_ddl(): void
    {
        // Gỡ sạch 3 bảng để kiểm chứng "đọc KHÔNG tạo bảng".
        Schema::dropIfExists('stylist_garment_types');
        Schema::dropIfExists('stylist_questions');
        Schema::dropIfExists('stylist_presets');

        $this->assertFalse(Schema::hasTable('stylist_garment_types'), 'Tiền đề: bảng đã bị gỡ.');

        $resp = $this->getJson('/api/stylist-data/data');
        $resp->assertOk();
        $resp->assertJsonStructure(['types', 'questions']);

        $this->assertFalse(Schema::hasTable('stylist_garment_types'),
            'GET công khai /api/stylist-data/data KHÔNG được tạo bảng (DDL). Trả dữ liệu mặc định thôi.');
        $this->assertFalse(Schema::hasTable('stylist_questions'));
        $this->assertFalse(Schema::hasTable('stylist_presets'));

        // presets cũng là đường đọc công khai.
        $this->getJson('/api/stylist/presets')->assertOk();
        $this->assertFalse(Schema::hasTable('stylist_presets'),
            'GET /api/stylist/presets cũng không được chạy DDL.');
    }

    // ── (b) counts() chỉ 1 query ─────────────────────────────────────────

    public function test_counts_collapses_four_count_queries_into_one(): void
    {
        $u = $this->admin();
        // 3 bản ghi: 1 applied + có ảnh, 1 applied + không ảnh, 1 chưa dùng.
        $u->suggestResults()->create(['apply_count' => 2, 'reference_url' => '/storage/x.jpg']);
        $u->suggestResults()->create(['apply_count' => 1, 'reference_url' => null]);
        $u->suggestResults()->create(['apply_count' => 0, 'reference_url' => null]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $counts = app(SuggestLibraryService::class)->counts($u);

        $this->assertSame(3, $counts['total']);
        $this->assertSame(2, $counts['applied']);
        $this->assertSame(1, $counts['unused']);
        $this->assertSame(1, $counts['with_image']);
        $this->assertSame(1, $queries,
            'counts() phải gom về MỘT query aggregate, không phải 4 lần count().');
    }

    // ── (c) lỗi không lộ path/SQL kể cả khi debug ─────────────────────────

    public function test_generation_error_sanitizes_paths_and_sql_even_in_debug(): void
    {
        config(['app.debug' => true]);

        $e = new \RuntimeException(
            'Lỗi tại /home/fabrikai/app/Services/ImageAIService.php:242 — SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'
        );

        $out = studio_generation_error($e);

        // Phần nhạy cảm PHẢI bị lọc, dù đang debug.
        $this->assertStringNotContainsString('/home/fabrikai/app/Services', $out,
            'Đường dẫn tuyệt đối không được lộ về client, kể cả khi debug.');
        $this->assertStringNotContainsString('SQLSTATE[23000]', $out,
            'Chi tiết SQLSTATE/SQL thô không được lộ về client.');
        $this->assertStringNotContainsString('Integrity constraint violation', $out);

        // Phần diễn giải + tên class vẫn giữ cho dev dò (lỗi có thể vẫn hữu ích nhưng đã an toàn).
        $this->assertStringContainsString('ImageAIService.php', $out, 'Giữ tên file (không phải đường dẫn đầy đủ).');
        $this->assertStringContainsString('SQLSTATE[...]', $out, 'SQLSTATE bị gom gọn nhưng vẫn báo là lỗi SQL.');
        $this->assertStringContainsString('[RuntimeException]', $out, 'Giữ tên class lỗi cho dev.');
    }

    // ══════════════════════════════════════════════════════════════════════════════════════════════
    // [2026-09-23] BỐN VIỆC CÒN NỢ trong DEPLOY_LOG — mỗi việc một bất biến máy giữ hộ.
    // ══════════════════════════════════════════════════════════════════════════════════════════════

    /** Khách bấm "Duyệt" ở link chia sẻ ⇒ bộ sưu tập tự sang "Đã duyệt" — nhưng CHỈ khi luật cho phép. */
    public function test_client_approval_auto_advances_the_collection_status(): void
    {
        $owner = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $workflow = app(\App\Services\ProjectWorkflowService::class);

        // Bộ sưu tập phải đi qua đúng đường trạng thái: draft → in_progress → review (chưa thể nhảy thẳng).
        $project = $owner->projects()->create(['name' => 'BST tự duyệt']);
        $project = $workflow->transition($project, \App\Models\Project::STATUS_IN_PROGRESS, $owner);
        $project = $workflow->transition($project, \App\Models\Project::STATUS_REVIEW, $owner);

        $share = \App\Models\ProjectShare::create([
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'token' => 'tok-'.uniqid(),
            'expires_at' => now()->addDays(30),
        ]);

        $this->post('/chia-se/'.$share->token.'/phan-hoi', [
            'author_name' => 'Khách A',
            'decision' => 'approved',
            'message' => 'Đẹp, chốt.',
        ])->assertRedirect();

        $this->assertSame(\App\Models\Project::STATUS_APPROVED, $project->fresh()->status,
            'Khách duyệt mà bộ sưu tập KHÔNG tự chuyển sang Đã duyệt (việc còn nợ chưa làm).');
        $this->assertDatabaseHas('project_feedback', ['project_id' => $project->id, 'decision' => 'approved']);

        // "Yêu cầu sửa" thì KHÔNG được đổi trạng thái (khách góp ý không phải là chốt).
        $project2 = $owner->projects()->create(['name' => 'BST xin sửa']);
        $project2 = $workflow->transition($project2, \App\Models\Project::STATUS_IN_PROGRESS, $owner);
        $share2 = \App\Models\ProjectShare::create([
            'project_id' => $project2->id, 'created_by' => $owner->id,
            'token' => 'tok2-'.uniqid(), 'expires_at' => now()->addDays(30),
        ]);
        $this->post('/chia-se/'.$share2->token.'/phan-hoi', ['author_name' => 'Khách B', 'decision' => 'changes'])
            ->assertRedirect();
        $this->assertSame(\App\Models\Project::STATUS_IN_PROGRESS, $project2->fresh()->status,
            'Yêu cầu sửa KHÔNG được tự đổi trạng thái.');
    }

    /** Tên file trong gói xuất mang TIỀN TỐ KÊNH BÁN, và kênh lạ bị 422. */
    public function test_export_filename_carries_the_channel_prefix(): void
    {
        $owner = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $project = $owner->projects()->create(['name' => 'BST kênh bán']);

        $ok = $this->actingAs($owner)->get('/api/projects/'.$project->id.'/export?channel=shopee');
        $ok->assertOk();
        $this->assertStringContainsString('shopee', (string) $ok->headers->get('content-disposition'),
            'Tên file gói xuất không mang tiền tố kênh bán.');

        $this->actingAs($owner)->get('/api/projects/'.$project->id.'/export?channel=../../etc')
            ->assertStatus(422);
        $this->assertSame('shopee', export_channel_prefix('shopee'));
        $this->assertSame('', export_channel_prefix('khong-ton-tai'), 'Kênh lạ phải rơi về mặc định, không vào tên file.');
    }

    /** Lỗi hệ thống có MÃ TRA CỨU, ghi ở cả payload (cho khách đọc) lẫn log (cho hỗ trợ grep). */
    public function test_system_errors_carry_a_lookup_code(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $response = studio_fail(new \RuntimeException('DB exploded at /var/www/secret'), 'Tạo ảnh');
        $payload = $response->getData(true);

        $this->assertMatchesRegularExpression('/^L-[A-Z0-9]{4}$/', (string) ($payload['error_code'] ?? ''),
            'Lỗi hệ thống phải có mã tra cứu dạng L-XXXX để khách đọc cho tổng đài.');
        $this->assertStringNotContainsString('/var/www/secret', (string) ($payload['message'] ?? ''),
            'Mã tra cứu KHÔNG được kéo theo chi tiết kỹ thuật vào câu hiển thị.');

        $code = $payload['error_code'];
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, $code))
            ->once();
    }

    /** Báo cáo chi phí theo nhóm: cấp Owner, số liệu lấy từ chính bảng generations. */
    public function test_team_cost_report_is_owner_only_and_counts_from_generations(): void
    {
        $admin = $this->admin();
        $customer = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        $this->actingAs($customer)->get('/bao-cao-nhom')->assertForbidden();

        $project = $customer->projects()->create(['name' => 'BST chi phí']);
        \App\Models\Generation::create([
            'user_id' => $customer->id, 'project_id' => $project->id, 'prompt' => 'ảnh 1',
            'status' => 'completed', 'credits_cost' => 7, 'media_url' => 'https://example.test/a.jpg',
        ]);

        $this->actingAs($admin)->get('/bao-cao-nhom')
            ->assertOk()
            ->assertSee('Chi phí theo nhóm', false)
            ->assertSee($customer->name, false)
            ->assertSee('7', false);
    }
}
