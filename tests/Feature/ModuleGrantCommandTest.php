<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GẮN MODULE VÀO GÓI — `studio:modules-grant` (2026-09-26).
 *
 * Vì sao có test này: đo trên production 2026-09-26, ba gói trả tiền ĐÃ có 'stylist' nhưng THIẾU
 * 'trend_radar' và 'collection_bot' (hai module mà 'stylist' phụ thuộc). Hệ quả: `module_allowed('stylist')`
 * = false ⇒ KHÁCH TRẢ TIỀN BỊ CHẶN AGENT STUDIO + CHAT, trong khi quản trị (được miễn công tắc gói) vẫn vào
 * được — lỗi IM LẶNG. Bản khai là một nguồn duy nhất, nhưng "gói nào cấp gì" nằm trong DỮ LIỆU, nên phải có
 * một việc LÀM LẠI ĐƯỢC để gắn module và một việc RÀ ĐƯỢC để bắt lệch.
 *
 * Test khoá bốn bất biến:
 *   (a) cấp module theo ĐỀ XUẤT của bản khai (không đụng gói không được đề xuất);
 *   (b) cấp KÈM module phụ thuộc — cấp con mà thiếu cha là cấp một quyền KHÔNG DÙNG ĐƯỢC;
 *   (c) KHÔNG BAO GIỜ ghi vào gói chưa cấu hình (modules = NULL = "đủ module"): ghi vào là âm thầm cắt
 *       tính năng của khách đang dùng;
 *   (d) `--check` trả mã lỗi khi có gói vi phạm phụ thuộc, và xanh khi mọi gói đủ.
 */
class ModuleGrantCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** Ghi danh sách module ĐANG LƯU cho một gói — mô phỏng dữ liệu production đã cấu hình từ trước. */
    private function store(string $slug, array $ids): void
    {
        Plan::where('slug', $slug)->firstOrFail()->forceFill(['modules' => array_values($ids)])->save();
    }

    /** @return list<string>|null */
    private function stored(string $slug): ?array
    {
        return Plan::where('slug', $slug)->firstOrFail()->modules;
    }

    /** Mọi id module TRỪ một số id — dựng cảnh "gói đã cấu hình nhưng thiếu vài module mới". */
    private function idsExcept(array $except): array
    {
        return array_values(array_diff(ModuleRegistry::ids(), $except));
    }

    public function test_cap_module_theo_de_xuat_cua_ban_khai(): void
    {
        // Đúng cảnh production: ba gói trả tiền thiếu collection_bot/trend_radar/outfit.
        foreach (['pro', 'studio', 'factory_season'] as $slug) {
            $this->store($slug, $this->idsExcept(['collection_bot', 'trend_radar', 'outfit']));
        }
        $this->store('free', $this->idsExcept(['collection_bot']));

        $this->artisan('studio:modules-grant', ['modules' => ['collection_bot']])->assertExitCode(0);

        foreach (['pro', 'studio', 'factory_season'] as $slug) {
            $this->assertContains('collection_bot', $this->stored($slug), 'Gói '.$slug.' phải được cấp module chat.');
        }

        // Gói miễn phí KHÔNG nằm trong đề xuất của 'collection_bot' ⇒ không được tự thêm.
        $this->assertNotContains('collection_bot', $this->stored('free'), 'Không được cấp module ngoài đề xuất.');
    }

    public function test_cap_kem_module_phu_thuoc(): void
    {
        // 'stylist' (Agent thiết kế) depends_on ['trend_radar', 'collection_bot'].
        $this->store('pro', $this->idsExcept(['stylist', 'trend_radar', 'collection_bot']));

        $this->artisan('studio:modules-grant', ['modules' => ['stylist'], '--plans' => 'pro'])->assertExitCode(0);

        $stored = $this->stored('pro');
        $this->assertContains('stylist', $stored);
        $this->assertContains('trend_radar', $stored, 'Cấp con mà thiếu cha thì con KHÔNG dùng được.');
        $this->assertContains('collection_bot', $stored, 'Cấp con mà thiếu cha thì con KHÔNG dùng được.');
    }

    public function test_no_deps_thi_khong_cap_kem(): void
    {
        $this->store('pro', $this->idsExcept(['stylist', 'trend_radar', 'collection_bot']));

        $this->artisan('studio:modules-grant', ['modules' => ['stylist'], '--plans' => 'pro', '--no-deps' => true])
            ->assertExitCode(0);

        $stored = $this->stored('pro');
        $this->assertContains('stylist', $stored);
        $this->assertNotContains('trend_radar', $stored, '--no-deps là cờ THOÁT HIỂM, phải tôn trọng đúng nghĩa.');
        $this->assertNotContains('collection_bot', $stored);
    }

    public function test_all_plans_cap_cho_moi_goi_dang_mo_ban(): void
    {
        $this->store('free', $this->idsExcept(['collection_bot']));

        $this->artisan('studio:modules-grant', ['modules' => ['collection_bot'], '--all-plans' => true])->assertExitCode(0);

        $this->assertContains('collection_bot', $this->stored('free'));
    }

    public function test_goi_chua_cau_hinh_khong_bao_gio_bi_ghi(): void
    {
        // 'starter' để nguyên modules = NULL = "đủ module" (xem Plan::modules()).
        $this->assertNull($this->stored('starter'));

        $this->artisan('studio:modules-grant', ['modules' => ['collection_bot'], '--all-plans' => true])->assertExitCode(0);

        $this->assertNull($this->stored('starter'), 'Ghi vào gói chưa cấu hình là ÂM THẦM CẮT tính năng đang có.');
        $this->assertTrue(Plan::where('slug', 'starter')->firstOrFail()->grantsModule('collection_bot'));
    }

    public function test_dry_run_khong_ghi_gi(): void
    {
        $before = $this->idsExcept(['collection_bot', 'trend_radar', 'outfit']);
        $this->store('pro', $before);

        $this->artisan('studio:modules-grant', ['modules' => ['collection_bot'], '--dry-run' => true])->assertExitCode(0);

        $this->assertSame($before, $this->stored('pro'), '--dry-run chỉ được IN RA, không được ghi.');
    }

    public function test_check_bao_loi_khi_goi_cap_con_thieu_cha(): void
    {
        // Đúng cảnh production trước khi sửa: có 'stylist' nhưng thiếu hai module cha.
        $this->store('pro', $this->idsExcept(['trend_radar', 'collection_bot']));

        $this->artisan('studio:modules-grant', ['--check' => true])
            ->expectsOutputToContain('THIẾU PHỤ THUỘC')
            ->assertExitCode(1);
    }

    public function test_check_xanh_khi_moi_goi_du_phu_thuoc(): void
    {
        foreach (['free', 'starter', 'pro', 'studio', 'factory_season'] as $slug) {
            $this->store($slug, ModuleRegistry::ids());
        }

        $this->artisan('studio:modules-grant', ['--check' => true])->assertExitCode(0);
    }

    public function test_id_la_bi_tu_choi_va_khong_ghi_gi(): void
    {
        $before = $this->idsExcept(['collection_bot', 'trend_radar', 'outfit']);
        $this->store('pro', $before);

        $this->artisan('studio:modules-grant', ['modules' => ['khong-co-module-nay']])
            ->expectsOutputToContain('Không có module nào tên')
            ->assertExitCode(1);

        $this->assertSame($before, $this->stored('pro'));
    }
}
