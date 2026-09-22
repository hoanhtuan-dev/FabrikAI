<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectGate;
use App\Models\QcInspection;
use App\Models\User;
use App\Services\ProjectGateService;
use App\Services\QcService;
use App\Services\TechPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * BA CỔNG DUYỆT của một bộ sưu tập — Việc #7, 2026-09-26.
 *
 * Khoá mười bất biến:
 *   (a) THỨ TỰ: cổng sau không mở khi cổng trước chưa duyệt, và câu từ chối NÓI RÕ cổng nào;
 *   (b) KHÔNG DUYỆT TRÊN LỜI HỨA: thiếu dữ liệu (phiếu kỹ thuật · kế hoạch · biên bản QC) ⇒ từ chối kèm
 *       danh sách còn thiếu, KHÔNG ghi một quyết định rỗng;
 *   (c) DUYỆT RỒI MÀ DỮ LIỆU ĐỔI ⇒ mất hiệu lực (stale) nhưng QUYẾT ĐỊNH CŨ VẪN CÒN trong hồ sơ;
 *   (d) RÚT CỔNG TRƯỚC ⇒ cổng sau đã duyệt thành VÔ HIỆU (cascade), không âm thầm giữ hiệu lực;
 *   (e) LÔ KHÔNG ĐẠT chặn nghiệm thu QC — đúng thứ tự này (thứ tự trước, dữ liệu sau);
 *   (f) KHÔNG DUYỆT mà không có LÝ DO ⇒ từ chối;
 *   (g) mọi cổng LUÔN có đường lùi (rút lại · mở lại), không có trạng thái ngõ cụt;
 *   (h) máy trạng thái chặn no-op và trả về các bước được phép;
 *   (i) quyền: khách 401 · người ngoài 403 · chủ bộ 200;
 *   (j) vết kiểm toán: ai duyệt · khi nào; rút lại thì xoá cùng lúc (không để dấu vết trỏ vào quyết định
 *       không còn tồn tại).
 */
class ProjectGateTest extends TestCase
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
        return $u->projects()->create(array_merge(['name' => 'Đầm linen Thu Đông'], $attrs));
    }

    /** Phiếu kỹ thuật ĐÃ CÓ NỘI DUNG — đi qua đúng service đang chạy, không tự đắp JSON vào bảng. */
    private function setTechPack(Project $p, string $styleNo = 'FD-2610'): void
    {
        app(TechPackService::class)->save($p, [
            'fields' => [
                'style_no' => $styleNo,
                'style_name' => 'Đầm linen cổ V',
                'category' => 'Váy',
                'fabric' => 'Linen 55% · cotton 45% · khổ 150cm',
                'construction' => 'May 1cm, vắt sổ 3 chỉ',
            ],
            'sizes' => ['S', 'M', 'L'],
            'measurements' => [
                ['point' => 'Dài áo', 'tolerance' => '±1', 'values' => ['S' => '62', 'M' => '64', 'L' => '66']],
            ],
        ]);
    }

    /** Kế hoạch sản xuất & giá nằm trong settings của bộ sưu tập — đúng chỗ Agent Studio ghi. */
    private function setPlan(Project $p, int $units = 1200): void
    {
        $p->settings = ['plan' => [
            'currency' => 'VND',
            'totals' => ['units' => $units, 'cut_lines' => 4, 'avg_unit_cost_vnd' => 210000],
            'selling' => [
                ['label' => 'Thấp', 'price_vnd' => 559000, 'margin_pct' => 55.2],
                ['label' => 'Vừa', 'price_vnd' => 659000, 'margin_pct' => 62.0],
                ['label' => 'Cao', 'price_vnd' => 759000, 'margin_pct' => 67.0],
            ],
        ]];
        $p->save();
    }

    private function inspection(Project $p, int $lot, int $major, string $aql = '2.5'): QcInspection
    {
        $plan = QcService::resolvePlan($lot, $aql);

        return QcInspection::create([
            'project_id' => $p->id,
            'style_no' => 'FD-2610',
            'stage' => 'final',
            'lot_size' => $lot,
            'aql' => $aql,
            'plan' => $plan,
            'major' => $major,
            'inspected_at' => now(),
            'result' => QcService::verdict([
                'plan' => $plan, 'critical' => 0, 'major' => $major, 'inspected_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function gateOf(User $u, Project $p, string $gate): array
    {
        $items = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/gates')->assertStatus(200)->json('items');

        return collect($items)->firstWhere('gate', $gate);
    }

    private function approve(User $u, Project $p, string $gate, string $note = ''): TestResponse
    {
        return $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/gates/'.$gate, array_filter([
            'decision' => 'approved', 'note' => $note,
        ]));
    }

    // ── (a)(b) THỨ TỰ + DỮ LIỆU ──────────────────────────────────────────────────────────────

    public function test_the_gates_must_be_approved_in_order(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setPlan($p);

        // Cổng 2 có đủ dữ liệu (kế hoạch đã có) nhưng cổng 1 chưa duyệt ⇒ vẫn bị chặn, và câu từ chối
        // phải nói TÊN cổng còn thiếu chứ không chỉ nói "chưa được".
        $res = $this->approve($u, $p, 'production_plan');
        $res->assertStatus(422);
        $this->assertStringContainsString('Chốt phiếu kỹ thuật', (string) $res->json('message'));

        // Cổng 3 cũng vậy, và lần này cổng 2 cũng chưa xong.
        $this->approve($u, $p, 'qc')->assertStatus(422);
    }

    public function test_a_gate_cannot_be_approved_without_its_data(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $res = $this->approve($u, $p, 'tech_pack');
        $res->assertStatus(422);
        $this->assertStringContainsString('Chưa lập phiếu kỹ thuật', (string) $res->json('message'));

        // Không có quyết định nào được ghi: 422 mà vẫn tạo dòng "đã duyệt" là kiểu sai tệ nhất.
        $this->assertSame(0, ProjectGate::query()->where('project_id', $p->id)->count());
    }

    /** (c) Kế hoạch có số 0 cái vẫn là "chưa có kế hoạch dùng được". */
    public function test_a_plan_without_units_is_not_a_plan(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->approve($u, $p, 'tech_pack')->assertStatus(200);

        $p->settings = ['plan' => ['totals' => ['units' => 0], 'selling' => [['label' => 'Vừa', 'price_vnd' => 1000]]]];
        $p->save();

        $res = $this->approve($u, $p, 'production_plan');
        $res->assertStatus(422);
        $this->assertStringContainsString('0 cái', (string) $res->json('message'));
    }

    /** (e) Thứ tự ưu tiên khi từ chối: nói cổng trước trước, rồi mới tới dữ liệu. */
    public function test_a_failing_lot_blocks_the_qc_sign_off(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->setPlan($p);
        $this->approve($u, $p, 'tech_pack')->assertStatus(200);
        $this->approve($u, $p, 'production_plan')->assertStatus(200);

        // Lô 1.200 ⇒ mã J · n=80 · Ac=5. 9 lỗi nặng ⇒ KHÔNG ĐẠT.
        $this->inspection($p, 1200, 9);

        $res = $this->approve($u, $p, 'qc');
        $res->assertStatus(422);
        $this->assertStringContainsString('KHÔNG ĐẠT', (string) $res->json('message'));
    }

    public function test_a_full_happy_path_walks_all_three_gates(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->setPlan($p);
        $this->inspection($p, 1200, 2);

        $this->approve($u, $p, 'tech_pack', 'Đã đối chiếu với xưởng')->assertStatus(200);
        $this->approve($u, $p, 'production_plan')->assertStatus(200);
        $done = $this->approve($u, $p, 'qc')->assertStatus(200);

        $this->assertSame(3, $done->json('summary.approved'));
        $this->assertTrue($done->json('summary.ready'), 'Ba cổng duyệt xong ⇒ bộ sưu tập sẵn sàng bàn giao.');
        $this->assertNull($done->json('summary.next_gate'));

        // Thông tin cho NGƯỜI DUYỆT đọc: cổng QC phải nói ra số đo thật, không chỉ nói "đủ điều kiện".
        $qc = $this->gateOf($u, $p, 'qc');
        $this->assertStringContainsString('2,5%', implode(' ', $qc['facts']), 'Tỉ lệ lỗi thật (2/80) phải hiện trước khi ký.');
    }

    // ── (c)(d) DỮ LIỆU ĐỔI ⇒ MẤT HIỆU LỰC, CÓ CASCADE ────────────────────────────────────────

    public function test_changing_the_tech_pack_makes_the_sign_off_stale(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->approve($u, $p, 'tech_pack')->assertStatus(200);
        $this->assertSame('approved', $this->gateOf($u, $p, 'tech_pack')['effective']);

        // Sửa vải sau khi đã ký ⇒ thông số đi ra xưởng không còn là thứ đã được duyệt.
        $this->setTechPack($p, 'FD-2611');

        $item = $this->gateOf($u, $p, 'tech_pack');
        $this->assertSame('stale', $item['effective']);
        $this->assertSame('approved', $item['decision'], 'Quyết định CŨ vẫn còn trong hồ sơ — chỉ mất hiệu lực.');
        $this->assertTrue($item['fingerprint_changed']);
        $this->assertStringContainsString('thay đổi sau khi duyệt', (string) $item['effective_reason']);

        $ready = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/gates')->json('summary');
        $this->assertSame(0, $ready['approved']);
        $this->assertFalse($ready['ready']);
    }

    public function test_changing_the_plan_makes_the_sign_off_stale(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->setPlan($p);
        $this->approve($u, $p, 'tech_pack')->assertStatus(200);
        $this->approve($u, $p, 'production_plan')->assertStatus(200);
        $this->assertSame('approved', $this->gateOf($u, $p, 'production_plan')['effective']);

        $this->setPlan($p, 2000);

        $plan = $this->gateOf($u, $p, 'production_plan');
        $this->assertSame('stale', $plan['effective']);

        // Dữ liệu của cổng vẫn đủ để duyệt — cái mất là HIỆU LỰC của quyết định cũ, không phải điều kiện.
        $this->assertTrue($plan['requirements_ok']);
        $this->assertTrue($plan['can_approve']);

        // Nhưng cổng QC đứng SAU một cổng hết hiệu lực thì không còn đủ điều kiện duyệt (cascade).
        $qc = $this->gateOf($u, $p, 'qc');
        $this->assertFalse($qc['previous_ok']);
        $this->assertFalse($qc['can_approve']);
    }

    public function test_a_new_qc_result_after_the_sign_off_makes_it_stale(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->setPlan($p);
        $row = $this->inspection($p, 1200, 2);
        $this->approve($u, $p, 'tech_pack')->assertStatus(200);
        $this->approve($u, $p, 'production_plan')->assertStatus(200);
        $this->approve($u, $p, 'qc')->assertStatus(200);
        $this->assertSame('approved', $this->gateOf($u, $p, 'qc')['effective']);

        // Sửa CHÍNH kết quả kiểm của lô đã kiểm: 12 lỗi nặng ⇒ không đạt.
        $row->major = 12;
        $row->result = QcService::verdict([
            'plan' => $row->plan, 'critical' => 0, 'major' => 12, 'inspected_at' => now()->toIso8601String(),
        ]);
        $row->save();

        $qc = $this->gateOf($u, $p, 'qc');
        $this->assertSame('stale', $qc['effective'], 'Nghiệm thu đã ký mà kết quả kiểm đổi thì phải duyệt lại.');
        $this->assertStringContainsString('KHÔNG ĐẠT', implode(' ', $qc['missing']));
    }

    public function test_withdrawing_an_earlier_gate_voids_the_later_ones(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->setPlan($p);
        $this->inspection($p, 1200, 2);
        $this->approve($u, $p, 'tech_pack')->assertStatus(200);
        $this->approve($u, $p, 'production_plan')->assertStatus(200);
        $this->approve($u, $p, 'qc')->assertStatus(200);

        // Rút cổng 1 về "chưa duyệt": hai cổng sau được duyệt DỰA TRÊN nó nên không thể còn hiệu lực.
        $after = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/gates/tech_pack', ['decision' => 'pending'])
            ->assertStatus(200);

        $items = collect($after->json('items'))->keyBy('gate');
        $this->assertSame('pending', $items['tech_pack']['effective']);
        $this->assertSame('void', $items['production_plan']['effective']);
        $this->assertSame('void', $items['qc']['effective']);
        $this->assertStringContainsString('Cổng phía trước', (string) $items['qc']['effective_reason']);
        $this->assertFalse($after->json('summary.ready'));
    }

    // ── (f)(g)(h) MÁY TRẠNG THÁI ────────────────────────────────────────────────────────────

    public function test_rejection_requires_a_reason_and_always_has_a_way_back(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);

        // Từ chối mà không nói vì sao: người sau không biết sửa gì.
        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/gates/tech_pack', ['decision' => 'rejected'])
            ->assertStatus(422);

        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/gates/tech_pack', [
            'decision' => 'rejected', 'note' => 'Thiếu bảng đo size L — xưởng không cắt được.',
        ])->assertStatus(200);

        $rejected = $this->gateOf($u, $p, 'tech_pack');
        $this->assertSame('rejected', $rejected['effective']);
        $this->assertContains('pending', $rejected['allowed'], 'Đã từ chối thì phải mở lại được.');
        $this->assertContains('approved', $rejected['allowed'], 'Sửa xong thì phải duyệt được.');

        // Mở lại ⇒ duyệt được, và đường lùi vẫn còn.
        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/gates/tech_pack', ['decision' => 'pending'])
            ->assertStatus(200);
        $approved = $this->approve($u, $p, 'tech_pack')->assertStatus(200);
        $this->assertSame('approved', collect($approved->json('items'))->firstWhere('gate', 'tech_pack')['effective']);
        $this->assertContains('pending', collect($approved->json('items'))->firstWhere('gate', 'tech_pack')['allowed']);
    }

    public function test_the_state_machine_blocks_a_no_op_and_lists_what_is_allowed(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->approve($u, $p, 'tech_pack')->assertStatus(200);

        $res = $this->approve($u, $p, 'tech_pack');
        $res->assertStatus(422);
        $this->assertSame(['pending', 'rejected'], $res->json('allowed'), 'Bước được phép phải trả về để giao diện vẽ lại nút.');
        $this->assertStringContainsString('Đã duyệt', (string) $res->json('message'));
    }

    public function test_an_unknown_gate_is_rejected(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/gates/giao_hang', ['decision' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cổng không hợp lệ: giao_hang');
    }

    // ── (i)(j) QUYỀN + VẾT KIỂM TOÁN ────────────────────────────────────────────────────────

    public function test_guest_and_outsiders_are_blocked(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);

        $this->getJson('/api/projects/'.$p->id.'/gates')->assertStatus(401);
        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/gates')->assertForbidden();
        $this->actingAs($other)
            ->postJson('/api/projects/'.$p->id.'/gates/tech_pack', ['decision' => 'approved'])
            ->assertForbidden();
    }

    public function test_the_audit_trail_records_who_decided_and_when(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);
        $this->approve($u, $p, 'tech_pack', 'Đã gọi xưởng xác nhận')->assertStatus(200);

        $item = $this->gateOf($u, $p, 'tech_pack');
        $this->assertSame($u->name, $item['decided_by']);
        $this->assertNotNull($item['decided_at']);
        $this->assertSame('Đã gọi xưởng xác nhận', $item['note']);
        $this->assertNotNull(ProjectGate::query()->where('project_id', $p->id)->first()->fingerprint);

        // Rút lại: dấu vết ai-khi-nao bị xoá CÙNG LÚC với quyết định, không để lại vết trỏ vào hư không.
        $after = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/gates/tech_pack', ['decision' => 'pending'])
            ->assertStatus(200);
        $item = collect($after->json('items'))->firstWhere('gate', 'tech_pack');
        $this->assertNull($item['decided_by']);
        $this->assertNull($item['decided_at']);
        $this->assertNull(ProjectGate::query()->where('project_id', $p->id)->first()->fingerprint);
    }

    public function test_the_note_is_capped(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $this->setTechPack($p);

        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/gates/tech_pack', [
            'decision' => 'approved', 'note' => str_repeat('a', ProjectGateService::NOTE_MAX + 1),
        ])->assertStatus(422)->assertJsonValidationErrors('note');
    }

    public function test_the_shape_declares_the_three_gates_in_order(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $shape = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/gates')->assertStatus(200)->json('shape');

        $this->assertSame(['tech_pack', 'production_plan', 'qc'], array_column($shape['gates'], 'id'));
        $this->assertSame([1, 2, 3], array_column($shape['gates'], 'order'));
        $this->assertSame(ProjectGateService::NOTE_MAX, $shape['limits']['note_max']);
        $this->assertSame(ProjectGateService::REJECT_NOTE_MIN, $shape['limits']['reject_note_min']);
    }
}
