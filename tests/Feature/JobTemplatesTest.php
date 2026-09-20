<?php

namespace Tests\Feature;

use App\Support\IndustryTemplates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MẪU VIỆC THEO NGÀNH (Đợt 2 — 2026-09-19).
 *
 * Người mới mở Studio gặp ô prompt TRỐNG và tab "Hàng loạt" TRỐNG: họ biết việc cần làm nhưng không biết
 * gõ gì, chọn tỉ lệ nào, cần mấy kiểu ảnh. Mẫu việc điền sẵn prompt + tỉ lệ + độ phân giải (+ bảng size
 * cho xưởng) để bắt đầu trong một cú bấm.
 *
 * Bất biến khoá ở đây:
 *   (a) endpoint cần đăng nhập (dữ liệu nội bộ của studio);
 *   (b) MỌI mẫu phát ra đều HỢP LỆ với validation của /api/generate — tỉ lệ thuộc whitelist, độ phân giải
 *       1K/2K, prompt không rỗng. Mẫu hỏng = người dùng bấm vào rồi bị 422 mà không hiểu vì sao;
 *   (c) bốn nhóm nghề đều có mẫu, và mẫu cho XƯỞNG phải kèm bảng size + ghi chú kỹ thuật (để đổ vào gói xuất);
 *   (d) id không trùng và nguồn dữ liệu nằm ở ĐÚNG MỘT chỗ (PHP) — giao diện đọc qua API, không có bản sao.
 */
class JobTemplatesTest extends TestCase
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

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/job-templates')->assertStatus(401);
    }

    public function test_endpoint_returns_all_four_industry_templates(): void
    {
        $res = $this->actingAs($this->customer())->getJson('/api/job-templates')->assertOk();

        $ids = array_column($res->json('templates'), 'id');
        foreach (['lookbook', 'ecommerce', 'factory', 'catalogue'] as $id) {
            $this->assertContains($id, $ids, "Thiếu mẫu việc '{$id}'.");
        }
    }

    public function test_every_template_is_valid_for_the_generate_endpoint(): void
    {
        $ratios = ['1:1', '4:3', '3:4', '16:9', '9:16', '4:5', '21:9', '19:6'];

        foreach (IndustryTemplates::all() as $tpl) {
            $this->assertNotSame('', $tpl['title'], 'Mẫu phải có tiêu đề.');
            $this->assertNotSame('', $tpl['hint'], 'Mẫu phải có mô tả cho người dùng.');
            $this->assertContains($tpl['ratio'], $ratios, "Tỉ lệ '{$tpl['ratio']}' không nằm trong whitelist của /api/generate.");
            $this->assertContains($tpl['resolution'], ['1K', '2K'], "Độ phân giải '{$tpl['resolution']}' không hợp lệ.");
            $this->assertNotEmpty($tpl['prompts'], 'Mẫu phải có ít nhất một prompt.');
            foreach ($tpl['prompts'] as $p) {
                $this->assertNotSame('', trim((string) $p), 'Prompt trong mẫu không được rỗng.');
                $this->assertLessThanOrEqual(4000, mb_strlen($p), 'Prompt phải nằm trong giới hạn 4000 ký tự của API.');
            }
            // Ít nhất một mẫu phải dùng được ngay cho tab Hàng loạt (từ 2 mục trở lên).
            $this->assertGreaterThanOrEqual(2, count($tpl['prompts']), 'Mẫu nên có từ 2 mục để dùng với tạo hàng loạt.');
        }
    }

    public function test_factory_template_carries_size_chart_and_technical_note(): void
    {
        $factory = collect(IndustryTemplates::all())->firstWhere('id', 'factory');

        $this->assertNotNull($factory, 'Phải có mẫu cho chủ xưởng may.');
        $this->assertNotNull($factory['export'], 'Mẫu cho xưởng phải kèm dữ liệu cho gói xuất.');
        $this->assertStringContainsString('S,', $factory['export']['sizes'], 'Bảng size mẫu phải có ít nhất size S.');
        $this->assertStringContainsString('Vải', $factory['export']['note'], 'Ghi chú kỹ thuật mẫu phải nêu chất liệu.');
    }

    public function test_template_ids_are_unique_and_source_is_single(): void
    {
        $templates = IndustryTemplates::all();
        $ids = array_column($templates, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'Id mẫu việc không được trùng.');

        // Nguồn dữ liệu nằm ở PHP; JS chỉ gọi API (không có bản sao prompt trong resources/js).
        $store = (string) file_get_contents(resource_path('js/studio/store.js'));
        $this->assertStringContainsString("fetch('/api/job-templates'", $store, 'Store phải nạp mẫu việc qua API.');
        $card = (string) file_get_contents(resource_path('js/studio/components/ConceptCard.vue'));
        $this->assertStringContainsString('useTemplate(tpl)', $card, 'Tab Hàng loạt phải có nút áp mẫu việc.');
        $this->assertStringNotContainsString('Lookbook bộ sưu tập', $card, 'Prompt mẫu KHÔNG được chép vào JS — một nguồn duy nhất ở PHP.');
    }

    public function test_applying_a_factory_template_also_feeds_the_export_dialog(): void
    {
        // Bất biến hành vi phía giao diện: mẫu có \`export\` ⇒ store giữ lại để khối "Xuất gói cho xưởng"
        // điền sẵn bảng size/ghi chú (không bắt người dùng gõ lại).
        $store = (string) file_get_contents(resource_path('js/studio/store.js'));
        $this->assertStringContainsString('pendingExport', $store, 'Store phải giữ mẫu xuất đang chờ.');

        /* [2026-09-23] Khối xuất gói nay có ở HAI bề mặt (card sidebar + trang /bo-suu-tap) và logic
           điền sẵn đã gom về store.applyPendingExport() — trước đây mỗi màn hình chép lại 5 dòng đọc
           store.pendingExport nên sửa một bên là hai bên lệch. Bất biến không đổi: MỞ khối xuất gói thì
           bảng size/ghi chú phải được điền sẵn từ mẫu việc, người dùng không phải gõ lại.
           Tên hàm thật là openExport() (test cũ ghi toggleExport() — tên đã đổi từ lâu). */
        $this->assertStringContainsString('applyPendingExport(', $store, 'Store phải có hàm điền sẵn khối xuất gói.');

        foreach (['components/CollectionsCard.vue', 'pages/CollectionsPage.vue'] as $rel) {
            $ui = (string) file_get_contents(resource_path('js/studio/'.$rel));
            $this->assertStringContainsString('function openExport()', $ui, $rel.' thiếu hàm mở khối xuất gói.');
            $this->assertStringContainsString('store.applyPendingExport(', $ui,
                $rel.' mở khối xuất gói mà không điền sẵn bảng size/ghi chú từ mẫu việc.');
        }
    }
}
