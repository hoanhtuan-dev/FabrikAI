<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\QcInspection;
use App\Models\Sample;
use App\Models\TechPack;
use App\Models\User;
use App\Services\QcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KIỂM TRA CHẤT LƯỢNG (QC) — Việc #6, 2026-09-26.
 *
 * Khoá mười bất biến:
 *   (a) BẢNG THAM CHIẾU TỰ NHẤT QUÁN: cỡ mẫu không giảm khi lô to lên, Ac không giảm khi AQL nới ra,
 *       và Re LUÔN = Ac + 1 (không có vùng lưỡng lự);
 *   (b) NGUYÊN TẮC MŨI TÊN đổi CẢ CỠ MẪU, không chỉ số chấp nhận — lấy mẫu thiếu rồi kết luận sai là
 *       lỗi đắt nhất của bảng AQL;
 *   (c) ô bảng KHÔNG PHỦ thì SIẾT chứ không nới, và lô to lên không bao giờ lấy mẫu ít đi;
 *   (d) lô dưới 2 cái ⇒ nói thẳng phải kiểm 100%, không bịa một cỡ mẫu;
 *   (e) KẾT LUẬN LÀ CON SỐ: chưa ghi ngày kiểm ⇒ "chưa kết luận", không mặc định là đạt; có lỗi nghiêm
 *       trọng ⇒ không đạt bất kể Ac;
 *   (f) kế hoạch lấy mẫu được CHỐT vào biên bản lúc mở, đổi cỡ lô thì chốt lại;
 *   (g) checklist mặc định bám NHÓM HÀNG khai trong phiếu kỹ thuật (khớp tương đối, người dùng gõ gì
 *       cũng ra) — nếu không thì bộ điểm riêng của váy/quần không bao giờ chạy;
 *   (h) quyền: khách 401 · người ngoài 403 · chủ bộ 200;
 *   (i) IDOR: biên bản của bộ KHÁC trả 404 dù id có thật;
 *   (j) tỉ lệ lỗi chỉ tính trên số cái ĐÃ KIỂM — biên bản nháp không được làm mẫu số.
 */
class QcInspectionTest extends TestCase
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

    private function sample(Project $p, array $attrs = []): Sample
    {
        return Sample::create(array_merge([
            'project_id' => $p->id,
            'style_no' => 'FD-2610',
            'name' => 'Đầm linen cổ V',
            'factory' => 'Xưởng Bình Tân',
            'stage' => Sample::STAGE_REQUESTED,
            'round' => 1,
        ], $attrs));
    }

    // ── (a)(b)(c)(d) BẢNG THAM CHIẾU + KẾ HOẠCH LẤY MẪU ────────────────────────────────────────

    /**
     * Bất biến của bảng, kiểm bằng SỐ ĐO chứ không bằng cách chép lại bảng vào test (chép lại thì test
     * chỉ nói "bảng giống chính nó").
     */
    public function test_the_reference_table_is_internally_consistent(): void
    {
        $lots = [2, 8, 9, 15, 16, 25, 26, 50, 51, 90, 91, 150, 151, 280, 281, 500, 501, 1200, 1201,
            3200, 3201, 10000, 10001, 35000, 35001, 150000, 150001, 500000, 500001, 1000000];

        foreach (QcService::AQL_LEVELS as $aql) {
            $previous = 0;
            foreach ($lots as $lot) {
                $plan = QcService::resolvePlan($lot, $aql);

                // Lô TO HƠN không bao giờ được lấy mẫu ÍT HƠN. Đây là bất biến chống lỗi đắt nhất:
                // một bảng sai theo hướng này làm lô lớn được nghiệm thu bằng mẫu nhỏ.
                $this->assertGreaterThanOrEqual($previous, $plan['sample_size'],
                    'Lô '.$lot.' ở AQL '.$aql.' lấy mẫu ít hơn lô nhỏ hơn ('.$previous.').');

                $this->assertSame($plan['ac'] + 1, $plan['re'],
                    'Re phải bằng Ac + 1 — lấy mẫu đơn không có vùng lưỡng lự (lô '.$lot.' · AQL '.$aql.').');

                $previous = $plan['sample_size'];
            }
        }

        // Nới AQL thì số chấp nhận KHÔNG được chặt lại.
        foreach ([26, 500, 3200, 35001, 500001] as $lot) {
            $acs = array_map(fn (string $aql) => QcService::resolvePlan($lot, $aql)['ac'], QcService::AQL_LEVELS);
            $sorted = $acs;
            sort($sorted);
            $this->assertSame($sorted, $acs, 'AQL nới ra mà Ac lại giảm ở lô '.$lot.': '.implode('/', $acs));
        }
    }

    /** (b) Nguyên tắc mũi tên: ô trống ⇒ đổi CẢ cỡ mẫu, không chỉ số chấp nhận. */
    public function test_the_arrow_rule_moves_the_sample_size_too(): void
    {
        // Lô 5 cái = mã A (cỡ mẫu 2). AQL 1.5 KHÔNG có kế hoạch ở mã A ⇒ mũi tên trỏ xuống cỡ mẫu 3.
        $plan = QcService::resolvePlan(5, '1.5');

        $this->assertSame('A', $plan['code'], 'Mã cỡ mẫu vẫn theo CỠ LÔ, không đổi theo mũi tên.');
        $this->assertSame(3, $plan['sample_size'], 'Mũi tên phải đổi cỡ mẫu thật sự dùng (2 → 3).');
        $this->assertNotNull($plan['note'], 'Đã đi theo mũi tên thì phải nói ra, không im lặng.');
        $this->assertStringContainsString('3', $plan['note']);

        // Cùng lô đó ở mức AQL có sẵn kế hoạch thì KHÔNG đi mũi tên.
        $direct = QcService::resolvePlan(5, '2.5');
        $this->assertSame(2, $direct['sample_size']);
        $this->assertNull($direct['note']);
    }

    /** (c) Ô bảng không phủ ⇒ SIẾT hơn, và lô to lên không bao giờ lấy mẫu ít đi. */
    public function test_cells_outside_the_table_tighten_instead_of_loosening(): void
    {
        // Lô 200.000 (mã P, cỡ mẫu 800) ở AQL 2.5: đáy cột, không còn kế hoạch nào lớn hơn để trỏ tới.
        $plan = QcService::resolvePlan(200000, '2.5');

        $this->assertSame(800, $plan['sample_size'], 'Giữ cỡ mẫu theo mã lô — KHÔNG lùi về cỡ mẫu nhỏ hơn.');
        $this->assertSame(21, $plan['ac'], 'Dùng số chấp nhận cao nhất của cột.');
        $this->assertStringContainsString('CHẶT HƠN', $plan['note'], 'Phải nói rõ đây là chỗ bảng chưa phủ.');

        // Hệ quả bắt buộc: lô 500.000 (mã P) và lô 600.000 (mã Q) vẫn phải ≥ cỡ mẫu của lô 200.000.
        $this->assertGreaterThanOrEqual(800, QcService::resolvePlan(500000, '2.5')['sample_size']);
        $this->assertGreaterThanOrEqual(800, QcService::resolvePlan(600000, '2.5')['sample_size']);
    }

    /** (d) Lô dưới 2 cái: không có kế hoạch lấy mẫu nào áp được — nói thẳng, đừng bịa cỡ mẫu. */
    public function test_a_lot_of_one_is_declared_a_full_inspection(): void
    {
        $plan = QcService::resolvePlan(1, '2.5');

        $this->assertSame(1, $plan['sample_size']);
        $this->assertStringContainsString('100%', $plan['note']);
    }

    // ── (e) KẾT LUẬN LÀ CON SỐ ────────────────────────────────────────────────────────────────

    public function test_verdict_is_computed_never_assumed(): void
    {
        $plan = ['ac' => 5, 're' => 6];

        // Chưa ghi ngày kiểm ⇒ CHƯA kết luận được. Mặc định "đạt" ở đây là tự ký nghiệm thu hộ khách.
        $this->assertSame(QcInspection::RESULT_PENDING, QcService::verdict([
            'plan' => $plan, 'critical' => 0, 'major' => 0, 'minor' => 0, 'inspected_at' => null,
        ]));

        // Đúng bằng Ac ⇒ đạt; vượt một cái ⇒ không đạt.
        $this->assertSame(QcInspection::RESULT_PASS, QcService::verdict([
            'plan' => $plan, 'critical' => 0, 'major' => 5, 'minor' => 99, 'inspected_at' => now()->toIso8601String(),
        ]));
        $this->assertSame(QcInspection::RESULT_FAIL, QcService::verdict([
            'plan' => $plan, 'critical' => 0, 'major' => 6, 'minor' => 0, 'inspected_at' => now()->toIso8601String(),
        ]));

        // Lỗi NGHIÊM TRỌNG không có mức chấp nhận — một cái là hỏng cả lô.
        $this->assertSame(QcInspection::RESULT_FAIL, QcService::verdict([
            'plan' => $plan, 'critical' => 1, 'major' => 0, 'minor' => 0, 'inspected_at' => now()->toIso8601String(),
        ]));

        // Lỗi NHẸ chỉ để theo dõi, không quyết định kết luận (muốn siết thì hạ AQL, không cộng dồn).
        $this->assertSame(QcInspection::RESULT_PASS, QcService::verdict([
            'plan' => $plan, 'critical' => 0, 'major' => 0, 'minor' => 500, 'inspected_at' => now()->toIso8601String(),
        ]));
    }

    // ── (f)(g)(h)(i)(j) API ──────────────────────────────────────────────────────────────────

    public function test_guest_and_outsiders_are_blocked(): void
    {
        $owner = $this->customer();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = $this->project($owner);

        $this->getJson('/api/projects/'.$p->id.'/qc-inspections')->assertStatus(401);
        $this->actingAs($other)->getJson('/api/projects/'.$p->id.'/qc-inspections')->assertForbidden();
        $this->actingAs($other)->postJson('/api/projects/'.$p->id.'/qc-inspections', ['lot_size' => 100])->assertForbidden();
    }

    /** (i) IDOR: biên bản có THẬT nhưng thuộc bộ khác ⇒ 404, không xác nhận là nó tồn tại. */
    public function test_an_inspection_from_another_project_is_not_reachable(): void
    {
        $owner = $this->customer();
        $mine = $this->project($owner, ['name' => 'Bộ của tôi']);
        $theirs = $this->project($owner, ['name' => 'Bộ khác']);
        $foreign = QcInspection::create([
            'project_id' => $theirs->id, 'lot_size' => 200, 'aql' => '2.5', 'plan' => QcService::resolvePlan(200, '2.5'),
        ]);

        $this->actingAs($owner)
            ->patchJson('/api/projects/'.$mine->id.'/qc-inspections/'.$foreign->id, ['major' => 3])
            ->assertStatus(404);

        $this->actingAs($owner)
            ->deleteJson('/api/projects/'.$mine->id.'/qc-inspections/'.$foreign->id)
            ->assertStatus(404);

        $this->assertSame(0, (int) $foreign->fresh()->major, 'Biên bản của bộ khác phải nguyên vẹn.');
    }

    public function test_api_round_trip_open_record_verdict_and_delete(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $sample = $this->sample($p);

        // Mở biên bản: kế hoạch lấy mẫu được CHỐT ngay và trả về kèm.
        $created = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/qc-inspections', [
            'sample_id' => $sample->id,
            'style_no' => 'FD-2610',
            'stage' => 'final',
            'lot_size' => 1200,
            'aql' => '2.5',
        ])->assertStatus(201);

        $id = (int) $created->json('created.id');
        $this->assertSame(1200, $created->json('created.lot_size'));
        $this->assertSame(80, $created->json('created.plan.sample_size'), 'Lô 1.200 ⇒ mã J ⇒ cỡ mẫu 80.');
        $this->assertSame(5, $created->json('created.ac'));
        $this->assertSame('pending', $created->json('created.result'), 'Mới mở thì CHƯA kết luận.');
        $this->assertSame(1, $created->json('summary.total'));

        // Ghi kết quả: 5 lỗi nặng ≤ Ac 5 ⇒ ĐẠT. Ai gõ kết luận vào payload cũng bị bỏ qua — máy tự tính.
        $passed = $this->actingAs($u)->patchJson('/api/projects/'.$p->id.'/qc-inspections/'.$id, [
            'major' => 5,
            'minor' => 40,
            'result' => 'fail',
            'inspected_at' => now()->toDateString(),
        ])->assertStatus(200);

        $this->assertSame('pass', $passed->json('updated.result'));
        $this->assertSame(45, $passed->json('updated.defects_total'));
        $this->assertSame(1, $passed->json('counts.pass'));

        // Thêm một lỗi nặng ⇒ vượt Ac ⇒ KHÔNG ĐẠT.
        $failed = $this->actingAs($u)->patchJson('/api/projects/'.$p->id.'/qc-inspections/'.$id, ['major' => 6])
            ->assertStatus(200);
        $this->assertSame('fail', $failed->json('updated.result'));

        $this->actingAs($u)->deleteJson('/api/projects/'.$p->id.'/qc-inspections/'.$id)->assertStatus(200);
        $this->assertSame(0, $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/qc-inspections')->json('summary.total'));
    }

    /** (f) Đổi cỡ lô ⇒ CHỐT LẠI kế hoạch; kết luận cũng được tính lại theo kế hoạch mới. */
    public function test_changing_the_lot_size_recomputes_the_plan_and_the_verdict(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $created = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/qc-inspections', [
            'lot_size' => 100, 'aql' => '2.5',
        ])->assertStatus(201);
        $id = (int) $created->json('created.id');
        $this->assertSame(20, $created->json('created.plan.sample_size'), 'Lô 100 ⇒ mã F ⇒ cỡ mẫu 20.');
        $this->assertSame(1, $created->json('created.ac'));

        // 3 lỗi nặng: đạt ở lô nhỏ (Ac 1? không) — 3 > 1 ⇒ hỏng. Đổi lên lô 3.200 (Ac 7) ⇒ đạt.
        $this->actingAs($u)->patchJson('/api/projects/'.$p->id.'/qc-inspections/'.$id, [
            'major' => 3, 'inspected_at' => now()->toDateString(),
        ])->assertJsonPath('updated.result', 'fail');

        $grown = $this->actingAs($u)->patchJson('/api/projects/'.$p->id.'/qc-inspections/'.$id, ['lot_size' => 3200])
            ->assertStatus(200);

        $this->assertSame(125, $grown->json('updated.plan.sample_size'), 'Lô 3.200 ⇒ mã K ⇒ cỡ mẫu 125.');
        $this->assertSame(7, $grown->json('updated.ac'));
        $this->assertSame('pass', $grown->json('updated.result'), 'Cùng số lỗi nhưng kế hoạch mới ⇒ kết luận tính lại.');
    }

    /** (g) Checklist mặc định bám NHÓM HÀNG khai trong phiếu kỹ thuật — khớp tương đối. */
    public function test_the_default_checklist_follows_the_category_from_the_tech_pack(): void
    {
        $u = $this->customer();
        $p = $this->project($u);
        $sample = $this->sample($p, ['name' => 'Đầm linen cổ V']);

        // Người dùng khai nhóm hàng kiểu gì cũng phải ra bộ điểm của váy.
        TechPack::updateOrCreate(['project_id' => $p->id], ['data' => ['category' => 'đầm dạ hội']]);

        $created = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/qc-inspections', [
            'sample_id' => $sample->id, 'lot_size' => 500,
        ])->assertStatus(201);

        $labels = array_column($created->json('created.checklist'), 'label');

        $this->assertContains('Lai váy đều', $labels, 'Nhóm hàng "váy" phải kéo theo điểm kiểm riêng của váy.');
        $this->assertContains('Đúng mã hàng / đúng mẫu đã duyệt', $labels, 'Điểm kiểm chung luôn có mặt.');

        // Không có phiếu kỹ thuật ⇒ suy từ TÊN MẪU; tên cũng không khớp thì rơi về danh sách chung, và
        // KHÔNG được nổ ra thành lỗi.
        $lonely = $this->project($u, ['name' => 'Bộ chưa có phiếu']);
        $this->assertSame('Váy', QcService::matchCategory('Đầm linen cổ V'));
        $this->assertNull(QcService::matchCategory('Bàn ghế gỗ'));
        $this->assertNull(QcService::matchCategory(null));

        $generic = $this->actingAs($u)->postJson('/api/projects/'.$lonely->id.'/qc-inspections', ['lot_size' => 500])
            ->assertStatus(201);
        $this->assertCount(7, $generic->json('created.checklist'), 'Không nhận ra nhóm hàng ⇒ checklist chung.');
    }

    /** (j) Tỉ lệ lỗi chỉ tính trên số cái ĐÃ KIỂM — biên bản nháp không được làm mẫu số. */
    public function test_defect_rate_counts_only_inspections_that_were_actually_carried_out(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $done = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/qc-inspections', ['lot_size' => 1200])
            ->assertStatus(201);
        $this->actingAs($u)->patchJson('/api/projects/'.$p->id.'/qc-inspections/'.(int) $done->json('created.id'), [
            'major' => 2, 'inspected_at' => now()->toDateString(),
        ])->assertStatus(200);

        // Biên bản NHÁP: có ghi lỗi nhưng chưa kiểm (chưa có ngày) ⇒ không được vào tử số lẫn mẫu số.
        $draft = $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/qc-inspections', ['lot_size' => 200000])
            ->assertStatus(201);
        $this->actingAs($u)->patchJson('/api/projects/'.$p->id.'/qc-inspections/'.(int) $draft->json('created.id'), [
            'critical' => 9, 'major' => 500,
        ])->assertStatus(200);

        $overview = $this->actingAs($u)->getJson('/api/projects/'.$p->id.'/qc-inspections')->assertStatus(200);

        $this->assertSame(80, $overview->json('summary.inspected_units'), 'Chỉ cộng số cái của biên bản đã kiểm.');
        $this->assertSame(2.5, $overview->json('summary.defect_rate_pct'), '2 lỗi / 80 cái đã kiểm = 2,5%.');
        $this->assertSame(['defects' => 2, 'units' => 80], $overview->json('summary.defect_rate_basis'),
            'Tỉ lệ phải kèm TỬ SỐ và MẪU SỐ — trộn hai tập dữ liệu khác nhau là ra số vô nghĩa.');
        $this->assertSame(511, $overview->json('summary.defects_tracked'), 'Số lỗi theo dõi vẫn đếm cả nháp.');
        $this->assertSame(1, $overview->json('counts.pending'), 'Biên bản nháp vẫn là "chưa kết luận".');
        $this->assertSame(1, $overview->json('counts.pass'), 'Biên bản đã kiểm (2 lỗi ≤ Ac 5) là ĐẠT.');
        $this->assertSame(511, $overview->json('defects.major') + $overview->json('defects.critical'),
            'Số lỗi vẫn ĐẾM ĐỦ để theo dõi, chỉ không chia vào tỉ lệ.');
    }

    /** Mở biên bản mà không biết lô bao nhiêu cái là biên bản không dùng được ⇒ cỡ lô bắt buộc. */
    public function test_opening_an_inspection_requires_a_lot_size(): void
    {
        $u = $this->customer();
        $p = $this->project($u);

        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/qc-inspections', [])
            ->assertStatus(422)->assertJsonValidationErrors('lot_size');

        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/qc-inspections', ['lot_size' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('lot_size');

        // Mức AQL lạ ⇒ rơi về mức mặc định chứ không phải lỗi: người dùng không phải học thuộc bốn mức.
        $this->actingAs($u)->postJson('/api/projects/'.$p->id.'/qc-inspections', ['lot_size' => 500, 'aql' => '7.5'])
            ->assertStatus(422);
    }

    /** Checklist do người dùng gửi lên được DỌN: bỏ mục rỗng, kết quả chỉ nhận pass/fail/na, cắt theo trần. */
    public function test_the_checklist_is_cleaned_and_capped(): void
    {
        $raw = [
            ['label' => '  Đúng màu  ', 'result' => 'pass', 'note' => '  so với mẫu duyệt  '],
            ['label' => '', 'result' => 'fail'],
            ['label' => 'Sai nhãn', 'result' => 'chắc là sai'],
            ['label' => 'Thiếu dòng'],
        ];
        for ($i = 0; $i < 60; $i++) {
            $raw[] = ['label' => 'Mục '.$i];
        }

        $clean = QcService::cleanChecklist($raw);

        $this->assertCount(QcService::MAX_CHECKLIST_ITEMS, $clean, 'Checklist bị cắt theo trần.');
        $this->assertSame('Đúng màu', $clean[0]['label'], 'Nhãn được cắt khoảng trắng.');
        $this->assertSame('pass', $clean[0]['result']);
        $this->assertSame('so với mẫu duyệt', $clean[0]['note']);
        $this->assertNull($clean[1]['result'], 'Kết quả lạ không được nhận — để trống, không đoán.');
        $this->assertNull($clean[2]['note'], 'Ghi chú rỗng ⇒ null, không phải chuỗi rỗng.');
        $this->assertSame('c1', $clean[0]['key'], 'Mục không có khoá thì máy tự đánh số.');
    }
}
