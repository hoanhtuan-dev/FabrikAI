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
}
