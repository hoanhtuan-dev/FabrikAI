<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CollectionPlanService;
use App\Services\DesignAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NỢ ĐÃ TRẢ (Đợt 31 — 2026-09-23) — bốn lỗi tốn tiền/thời gian đã ghi sổ từ vòng kiểm tra sâu.
 *
 * Mỗi test dưới đây khoá ĐÚNG một lỗi đã có thật, không phải kiểm hình thức:
 *   1. "Khổ vải" được nhập mà KHÔNG vào công thức ⇒ đổi khổ xong giá vốn đứng yên (quyết định mua vải sai);
 *   2. HAI con số lợi nhuận trong cùng một kế hoạch mà không nhãn nào phân biệt;
 *   3. Dữ liệu shop trộn nhiều kỳ nhưng vẫn kể như MỘT con số của một kỳ;
 *   4. Dán từ Excel: "520.000" ⇒ 520 và "1.200.000" ⇒ 0 ⇒ dải giá 0, cả ba kịch bản bán biến mất.
 */
class DebtFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    private function brief(): array
    {
        return [
            'structure' => [
                'basis' => 'heuristic',
                'categories' => [
                    ['category' => 'Áo / blouse', 'count' => 2, 'source' => 'default'],
                    ['category' => 'Váy', 'count' => 1, 'source' => 'default'],
                ],
            ],
            'size_distribution' => [
                ['size' => 'S', 'count' => 20], ['size' => 'M', 'count' => 40], ['size' => 'L', 'count' => 40],
            ],
            'price_bands' => ['min_vnd' => 400000, 'max_vnd' => 700000, 'recommended_label' => 'Mid-range'],
        ];
    }

    // ── (1) KHỔ VẢI PHẢI THAY ĐỔI ĐỊNH MỨC ──────────────────────────────────
    /** Khổ rộng hơn ⇒ ít mét vải hơn cho cùng một chi tiết, và giá vốn phải GIẢM theo. */
    public function test_fabric_width_changes_the_fabric_consumption_and_cost(): void
    {
        $planner = app(CollectionPlanService::class);

        $narrow = $planner->plan($this->brief(), ['fabric_width_cm' => 150]);
        $wide = $planner->plan($this->brief(), ['fabric_width_cm' => 180]);

        $this->assertLessThan(
            $narrow['totals']['fabric_m'],
            $wide['totals']['fabric_m'],
            'Khổ 180cm phải dùng ÍT mét vải hơn khổ 150cm — trước đây khổ vải không vào công thức nên hai bên bằng nhau.',
        );
        $this->assertLessThan($narrow['totals']['cost_total_vnd'], $wide['totals']['cost_total_vnd']);
        $this->assertGreaterThan(0, $narrow['cut_lines'][0]['fabric_m_per_unit'] ?? 0);
    }

    /** Câu ghi chú của bảng size phải in khổ vải NGƯỜI DÙNG NHẬP (trước đây in khổ mặc định). */
    public function test_size_chart_note_uses_the_users_fabric_width(): void
    {
        $plan = app(CollectionPlanService::class)->plan($this->brief(), ['fabric_width_cm' => 180]);

        $this->assertStringContainsString('180cm', (string) ($plan['size_chart'][0]['note'] ?? ''));
        $this->assertStringNotContainsString('150cm', (string) ($plan['size_chart'][0]['note'] ?? ''));
    }

    // ── (2) HAI CON SỐ LỢI NHUẬN PHẢI NÓI RÕ TÊN ────────────────────────────
    /** Phần tổng trả lợi nhuận TRƯỚC chi phí cố định, kèm ngay số SAU khi trừ; kịch bản bán tự khai basis. */
    public function test_profit_has_two_named_numbers(): void
    {
        $plan = app(CollectionPlanService::class)->plan($this->brief(), ['fixed_cost' => 5_000_000]);

        $totals = $plan['totals'];
        $this->assertSame('before_fixed_cost', $totals['profit_basis']);
        $this->assertSame(5_000_000, $totals['fixed_cost_vnd']);
        $this->assertSame($totals['profit_vnd'] - 5_000_000, $totals['profit_after_fixed_vnd']);
        $this->assertNotNull($totals['margin_after_fixed_pct']);

        foreach ($plan['selling'] as $scenario) {
            $this->assertSame('after_fixed_cost', $scenario['profit_basis'],
                'Kịch bản bán trừ chi phí cố định nên phải tự khai basis để giao diện không đoán.');
        }

        // Và phải có MỘT câu giải thích hai con số khác nhau ở đâu.
        $this->assertNotEmpty(array_filter($plan['notes'], fn (string $n) => str_contains($n, 'chi phí cố định')));
    }

    // ── (3) KỲ BÁO CÁO CỦA DỮ LIỆU SHOP ─────────────────────────────────────
    /** Nhập nhiều kỳ ⇒ KHÔNG được trả một con số kỳ duy nhất; phải có cờ mixed + danh sách kỳ. */
    public function test_shop_periods_are_not_mixed_silently(): void
    {
        $agents = app(DesignAgentService::class);
        $user = $this->customer();

        $agents->saveShopSignals($user, [
            ['name' => 'Đầm linen', 'category' => 'Váy', 'units_sold' => 40, 'price_vnd' => 500000, 'period_days' => 30],
            ['name' => 'Áo sơ mi', 'category' => 'Áo', 'units_sold' => 60, 'price_vnd' => 400000, 'period_days' => 90],
        ]);

        $mixed = $agents->radar($user, 'all', false)['internal_brand_signal']['shop'];
        $this->assertTrue($mixed['period_days_mixed']);
        $this->assertNull($mixed['period_days']);
        $this->assertSame([30, 90], $mixed['periods']);
        $this->assertStringContainsString('2 kỳ báo cáo', $mixed['narrative']);

        // Cùng một kỳ thì trả về đúng MỘT con số và không kêu ca gì.
        $agents->saveShopSignals($user, [
            ['name' => 'Đầm linen', 'category' => 'Váy', 'units_sold' => 40, 'price_vnd' => 500000, 'period_days' => 30],
            ['name' => 'Áo sơ mi', 'category' => 'Áo', 'units_sold' => 60, 'price_vnd' => 400000, 'period_days' => 30],
        ]);
        $same = $agents->radar($user, 'all', false)['internal_brand_signal']['shop'];
        $this->assertFalse($same['period_days_mixed']);
        $this->assertSame(30, $same['period_days']);
    }

    // ── (4) DÁN TỪ EXCEL ────────────────────────────────────────────────────
    /**
     * Logic đọc bảng dán nằm ở `resources/js/studio/shopPaste.js` và được kiểm bằng Node
     * (`scripts/check-shop-paste.mjs`): repo không có JS test runner nên đây là đường kiểm thật.
     */
    public function test_excel_paste_parser_passes_its_node_checks(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Không có node trong môi trường này.');
        }

        @exec(escapeshellcmd($node).' '.escapeshellarg(base_path('scripts/check-shop-paste.mjs')).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "check-shop-paste.mjs thất bại:
".implode("
", $out));
        $this->assertStringContainsString('Tất cả phép kiểm ĐẠT', implode("
", $out));
    }

    /** Và giao diện phải DÙNG module đó (không được dựng lại hàm đọc số ngay trong .vue — đó là cách lỗi cũ lọt). */
    public function test_the_screen_uses_the_shared_paste_module(): void
    {
        $view = (string) file_get_contents(resource_path('js/studio/components/DesignAgents.vue'));

        $this->assertStringContainsString("from '../shopPaste.js'", $view);
        $this->assertStringNotContainsString('function parseNumberCell', $view, 'Hàm đọc số không được quay lại nằm trong .vue.');
    }

    /** Giá dán vào mà bằng 0 thì phải CẢNH BÁO ngay (im lặng là cách lỗi này sống lâu). */
    public function test_the_screen_warns_about_broken_pasted_prices(): void
    {
        $view = (string) file_get_contents(resource_path('js/studio/components/DesignAgents.vue'));

        $this->assertStringContainsString('quá nhỏ (dưới 1.000đ)', $view);
        $this->assertStringContainsString('kế hoạch sản xuất sẽ không dùng được', $view);
    }
}
