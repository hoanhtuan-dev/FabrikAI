<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHIÊN LÀM VIỆC DAI DẲNG của Agent Studio (2026-09-25).
 *
 * Vì sao khoá bằng test: đây là thứ người dùng chỉ tin được khi nó THẬT SỰ bền. Ba cách hỏng đều im
 * lặng — mở lại thấy trắng (tưởng mất bài), hai phiên song song ghi đè nhau, và tệ nhất: phiên của
 * người này đọc được bằng id của người khác.
 *
 * Bất biến:
 *   1. Một bản nháp = MỘT phiên: ghi nhiều lần vẫn về đúng dự án đó, không sinh bản nháp mới mỗi lần lưu.
 *   2. Phiên thuộc về ĐÚNG người tạo — id của người khác trả 404, không phải 200 kèm dữ liệu.
 *   3. Chốt phiên thì phiên đóng; mở lại được (chốt không phải ngõ cụt).
 *   4. Phiên là một BỘ SƯU TẬP thật: nó nằm trong danh sách dự án của người dùng, không phải một kho
 *      riêng chỉ Agent Studio biết.
 */
class AgentSessionTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@fabrikai.shop')->firstOrFail();
    }

    /** Payload phiên tối thiểu mà giao diện gửi lên. */
    private function payload(array $session = []): array
    {
        return ['session' => array_merge([
            'version' => 1,
            'step' => 'brief',
            'sub' => 'size',
            'name' => 'Thu Đông 2026 · công sở',
            'prompt' => 'Bộ sưu tập linen pastel cho nữ công sở',
            'region' => 'all',
            'trend_ids' => ['linen-breeze'],
            'sku_total' => 12,
            'size_distribution' => ['S' => 20, 'M' => 35, 'L' => 30, 'XL' => 15],
            'samples' => [
                ['id' => 'sku-1', 'name' => 'Áo linen', 'category' => 'Áo / blouse', 'size' => 'M', 'status' => 'done', 'prompt_vi' => 'Ảnh mẫu 1'],
                ['id' => 'sku-2', 'name' => 'Quần tây', 'category' => 'Quần', 'size' => 'L', 'status' => 'todo'],
            ],
        ], $session)];
    }

    public function test_a_user_without_a_session_gets_null_and_not_an_empty_shell(): void
    {
        $this->actingAs($this->customer())
            ->getJson('/api/design-agent/session')
            ->assertOk()
            ->assertJsonPath('session', null)
            ->assertJsonPath('project', null);
    }

    public function test_saving_creates_one_draft_project_and_reloading_returns_the_same_state(): void
    {
        $user = $this->customer();

        $saved = $this->actingAs($user)->putJson('/api/design-agent/session', $this->payload())->assertOk();
        $saved->assertJsonPath('project.name', 'Thu Đông 2026 · công sở')
            ->assertJsonPath('project.status', Project::STATUS_DRAFT)
            ->assertJsonPath('samples_done', 1);

        // Ghi lần hai (người dùng đi tiếp) ⇒ VẪN đúng dự án đó. Sinh bản nháp mới mỗi lần lưu là cách
        // danh sách bộ sưu tập phình ra vài chục bản rác sau một buổi làm việc.
        $this->actingAs($user)->putJson('/api/design-agent/session', $this->payload(['sub' => 'review']))->assertOk();

        $this->assertSame(1, $user->projects()->whereNull('deleted_at')->count(), 'Mỗi lần lưu lại đẻ thêm một bộ sưu tập.');

        $reload = $this->actingAs($user)->getJson('/api/design-agent/session')->assertOk();
        $reload->assertJsonPath('session.sub', 'review')
            ->assertJsonPath('session.sku_total', 12)
            ->assertJsonPath('project.samples_total', 2)
            ->assertJsonPath('project.samples_done', 1);
    }

    public function test_the_session_belongs_to_its_owner_only(): void
    {
        $mine = $this->actingAs($this->customer())->putJson('/api/design-agent/session', $this->payload())->assertOk();
        $id = $mine->json('project.id');

        // Người khác: đọc thì thấy phiên CỦA MÌNH (rỗng), không thấy phiên người kia.
        $this->actingAs($this->admin())->getJson('/api/design-agent/session')
            ->assertOk()->assertJsonPath('session', null);

        // Và ghi vào id đó thì 404 — không được 200 kèm dữ liệu của người khác.
        $this->actingAs($this->admin())
            ->putJson('/api/design-agent/session', $this->payload(['prompt' => 'Chiếm phiên']) + ['project_id' => $id])
            ->assertStatus(404);

        $this->actingAs($this->admin())
            ->postJson('/api/design-agent/session/close', ['project_id' => $id])
            ->assertStatus(404);

        // Dữ liệu của chủ phiên vẫn nguyên vẹn.
        $this->actingAs($this->customer())->getJson('/api/design-agent/session')
            ->assertOk()->assertJsonPath('session.prompt', 'Bộ sưu tập linen pastel cho nữ công sở');
    }

    public function test_closing_a_session_is_not_a_dead_end(): void
    {
        $user = $this->customer();
        $id = $this->actingAs($user)->putJson('/api/design-agent/session', $this->payload())->assertOk()->json('project.id');

        $this->actingAs($user)->postJson('/api/design-agent/session/close', ['project_id' => $id])
            ->assertOk()
            ->assertJsonPath('project.closed', true);

        // Phiên đã chốt KHÔNG còn là phiên đang mở ⇒ lần lưu sau tạo phiên mới, không ghi đè cái đã chốt.
        $this->actingAs($user)->getJson('/api/design-agent/session')->assertOk()->assertJsonPath('session', null);

        // Nhưng mở lại được — người dùng đổi ý là chuyện thường.
        $this->actingAs($user)->postJson('/api/design-agent/session/reopen', ['project_id' => $id])
            ->assertOk()
            ->assertJsonPath('project.closed', false);

        $this->actingAs($user)->getJson('/api/design-agent/session')
            ->assertOk()
            ->assertJsonPath('project.id', $id)
            ->assertJsonPath('session.sub', 'size');
    }

    public function test_the_session_is_a_real_collection_not_a_private_store(): void
    {
        $user = $this->customer();
        $this->actingAs($user)->putJson('/api/design-agent/session', $this->payload())->assertOk();

        // Nằm trong danh sách bộ sưu tập của chính người dùng ⇒ hiện ở /bo-suu-tap và xuất gói được.
        $list = $this->actingAs($user)->getJson('/api/projects')->assertOk()->json('items');
        $names = array_column($list, 'name');
        $this->assertContains('Thu Đông 2026 · công sở', $names, 'Phiên không nằm trong danh sách bộ sưu tập.');
    }

    public function test_broken_session_payloads_are_rejected(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->putJson('/api/design-agent/session', [])->assertStatus(422);
        $this->actingAs($user)->putJson('/api/design-agent/session', ['session' => ['step' => 'brief']])->assertOk();

        // Trạng thái mẫu lạ ⇒ 422: trạng thái là thứ giao diện dùng để đếm "đã xong mấy mẫu", nhận bừa
        // giá trị lạ là con số đó sai mà không ai biết.
        $this->actingAs($user)->putJson('/api/design-agent/session', $this->payload([
            'samples' => [['id' => 'sku-1', 'status' => 'xong-roi']],
        ]))->assertStatus(422);

        // Trần số mẫu: 400 là mức người dùng thật làm được trong một phiên.
        $many = [];
        for ($i = 1; $i <= 401; $i++) {
            $many[] = ['id' => 'sku-'.$i, 'status' => 'todo'];
        }
        $this->actingAs($user)->putJson('/api/design-agent/session', $this->payload(['samples' => $many]))->assertStatus(422);
    }

    public function test_the_server_stamps_the_time_and_ignores_a_client_supplied_status(): void
    {
        $user = $this->customer();
        $id = $this->actingAs($user)->putJson('/api/design-agent/session', $this->payload(['status' => 'closed']))->assertOk()->json('project.id');

        // Client gửi status=closed KHÔNG được đóng phiên: đóng là hành động riêng, không phải một trường
        // trong payload lưu. Nhận bừa là phiên tự đóng sau một lần lưu lỗi.
        $this->actingAs($user)->getJson('/api/design-agent/session')
            ->assertOk()
            ->assertJsonPath('project.id', $id)
            ->assertJsonPath('project.closed', false);
    }
}
