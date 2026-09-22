<?php

namespace Tests\Feature;

use App\Ai\PromptCatalog;
use App\Models\PromptTemplate;
use App\Models\User;
use App\Services\DesignAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QUẢN LÝ CHỈ DẪN QUA GIAO DIỆN (2026-09-22) — thay cho việc phải SSH.
 *
 * Bất biến khoá ở đây:
 *   (a) chỉ quản trị viên đọc/ghi được; khách hàng bị chặn;
 *   (b) LƯU luôn TẠO PHIÊN BẢN MỚI và chỉ MỘT bản bật — bản cũ còn nguyên để khôi phục;
 *   (c) nội dung RỖNG và khoá LẠ đều bị từ chối (không tạo rác, không làm mất chỉ dẫn);
 *   (d) mọi khoá trong danh mục PHẢI được agent thật đọc — lệch là giao diện sửa mà hệ thống
 *       không dùng, kiểu hỏng im lặng nguy hiểm nhất;
 *   (e) chạy khi CHƯA cấu hình thì hệ thống ghi nhớ bản mặc định trong mã để giao diện hiển thị được.
 */
class PromptAdminTest extends TestCase
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

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function key(): string
    {
        return PromptCatalog::keys()[0];
    }

    // ── (a) PHÂN QUYỀN ────────────────────────────────────────────────────

    public function test_customer_cannot_read_or_write_instructions(): void
    {
        $c = $this->customer();

        $this->actingAs($c)->getJson('/api/admin/prompts')->assertForbidden();
        $this->actingAs($c)->postJson('/api/admin/prompts', ['key' => $this->key(), 'body' => 'x'])->assertForbidden();
        $this->actingAs($c)->postJson('/api/admin/prompts/off', ['key' => $this->key()])->assertForbidden();
        $this->actingAs($c)->postJson('/api/admin/prompts/activate', ['key' => $this->key(), 'version' => 1])->assertForbidden();

        $this->assertSame(0, PromptTemplate::count());
    }

    public function test_admin_sees_every_catalog_key_with_its_state(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/prompts')->assertOk();

        $res->assertJsonCount(count(PromptCatalog::keys()), 'prompts');
        foreach ($res->json('prompts') as $p) {
            $this->assertArrayHasKey('key', $p);
            $this->assertArrayHasKey('label', $p);
            $this->assertArrayHasKey('what', $p);
            $this->assertArrayHasKey('vars', $p);
            $this->assertArrayHasKey('versions', $p);
            // Chưa cấu hình gì ⇒ hệ thống đang chạy mặc định trong mã.
            $this->assertFalse($p['configured']);
        }
    }

    // ── (d) DANH MỤC KHÔNG ĐƯỢC LỆCH VỚI MÃ ───────────────────────────────

    public function test_every_catalog_key_is_really_read_by_the_agent(): void
    {
        $src = (string) file_get_contents(app_path('Services/DesignAgentService.php'));

        foreach (PromptCatalog::keys() as $key) {
            $this->assertStringContainsString("'".$key."'", $src,
                'Khoá '.$key.' có trong danh mục nhưng agent KHÔNG đọc — sửa trên giao diện sẽ vô tác dụng.');
        }

        // Và ngược lại: mọi mốc cấu hình trong mã đều phải có mặt trong danh mục.
        preg_match_all("/instruction\('([a-z_.]+)'/", $src, $m);
        $this->assertNotEmpty($m[1], 'Không tìm thấy mốc cấu hình nào trong mã — test này đã mất giá trị.');
        $this->assertSame([], array_values(array_diff(array_unique($m[1]), PromptCatalog::keys())),
            'Mã có mốc cấu hình nhưng danh mục thiếu ⇒ giao diện không sửa được chỉ dẫn đó.');
    }

    // ── (b) LƯU = PHIÊN BẢN MỚI ───────────────────────────────────────────

    public function test_save_creates_a_new_version_and_leaves_the_old_one(): void
    {
        $key = $this->key();
        PromptTemplate::create(['key' => $key, 'body' => 'BAN GOC', 'version' => 1, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/prompts', ['key' => $key, 'body' => 'BAN MOI', 'note' => 'thu nghiem'])
            ->assertOk()
            ->assertJsonPath('prompt.active_version', 2)
            ->assertJsonPath('prompt.configured', true)
            ->assertJsonPath('prompt.active_body', 'BAN MOI');

        $this->assertSame(2, PromptTemplate::where('key', $key)->count());
        $this->assertSame(1, PromptTemplate::where('key', $key)->where('is_active', true)->count());
        $this->assertSame('BAN MOI', PromptCatalog::state($key)['active_body']);
        $this->assertSame('thu nghiem', PromptTemplate::where('key', $key)->where('version', 2)->value('label'));
    }

    public function test_saved_instruction_is_what_the_agent_uses(): void
    {
        $key = 'agent.radar.instruction';

        $this->actingAs($this->admin())
            ->postJson('/api/admin/prompts', ['key' => $key, 'body' => 'CHI DAN MOI {today}'])
            ->assertOk();

        $svc = app(DesignAgentService::class);
        $m = new \ReflectionMethod($svc, 'instruction');
        $out = $m->invoke($svc, $key, 'MAC DINH', ['today' => '01/01/2026']);

        $this->assertSame('CHI DAN MOI 01/01/2026', $out);
    }

    // ── (c) TỪ CHỐI ĐẦU VÀO XẤU ──────────────────────────────────────────

    public function test_blank_body_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/prompts', ['key' => $this->key(), 'body' => "   \n  "])
            ->assertStatus(422);

        $this->assertSame(0, PromptTemplate::count());
    }

    public function test_unknown_key_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/prompts', ['key' => 'agent.khong.ton.tai', 'body' => 'x'])
            ->assertStatus(422);

        $this->assertSame(0, PromptTemplate::count());
    }

    public function test_missing_key_is_refused(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/prompts', ['body' => 'x'])->assertStatus(422);
    }

    // ── QUAY VỀ MẶC ĐỊNH · KHÔI PHỤC BẢN CŨ ──────────────────────────────

    public function test_off_returns_to_the_code_default(): void
    {
        $key = $this->key();
        PromptCatalog::put($key, 'BAN CAU HINH');
        $this->assertTrue(PromptCatalog::state($key)['configured']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/prompts/off', ['key' => $key])
            ->assertOk()
            ->assertJsonPath('prompt.configured', false);

        // Hàng cũ KHÔNG bị xoá — chỉ tắt, để còn khôi phục.
        $this->assertSame(1, PromptTemplate::where('key', $key)->count());
        $this->assertSame(0, PromptTemplate::where('key', $key)->where('is_active', true)->count());
    }

    public function test_activate_restores_an_older_version(): void
    {
        $key = $this->key();
        PromptCatalog::put($key, 'V1');
        PromptCatalog::put($key, 'V2');
        $this->assertSame('V2', PromptCatalog::state($key)['active_body']);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/prompts/activate', ['key' => $key, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('prompt.active_body', 'V1');

        $this->assertSame(1, PromptTemplate::where('key', $key)->where('is_active', true)->count());
    }

    public function test_activate_unknown_version_is_not_found(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/prompts/activate', ['key' => $this->key(), 'version' => 99])
            ->assertStatus(404);
    }

    // ── (e) GHI NHỚ BẢN MẶC ĐỊNH TRONG MÃ ───────────────────────────────

    public function test_running_without_configuration_remembers_the_code_default(): void
    {
        $key = 'agent.radar.instruction';
        PromptCatalog::turnOff($key);
        $this->assertNull(PromptCatalog::defaultBody($key), 'Chưa chạy lần nào thì chưa có gì để hiển thị.');

        $svc = app(DesignAgentService::class);
        $m = new \ReflectionMethod($svc, 'instruction');
        $out = $m->invoke($svc, $key, 'CHUOI MAC DINH TRONG MA');

        $this->assertSame('CHUOI MAC DINH TRONG MA', $out, 'Không cấu hình ⇒ phải chạy đúng chuỗi trong mã.');
        $this->assertSame('CHUOI MAC DINH TRONG MA', PromptCatalog::defaultBody($key),
            'Giao diện cần thấy bản mặc định đang chạy, nếu không owner chỉ sửa được trong bóng tối.');
    }
}
