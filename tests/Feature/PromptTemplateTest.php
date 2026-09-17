<?php

namespace Tests\Feature;

use App\Models\PromptTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Đợt 1.7 — 2026-09-17] prompt_templates: prompt giá trị nhất ra khỏi code, vào DB.
 *
 * Bất biến: đổi template trong DB ⇒ nội dung gửi provider đổi KHÔNG cần deploy;
 * phiên bản cao nhất đang active được chọn; fallback (chuỗi cũ) giữ khi DB trống.
 */
class PromptTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_returns_fallback_when_no_row_exists(): void
    {
        $this->assertSame('chuoi-cu', studio_prompt_template('garment.lock', [], 'chuoi-cu'));
        $this->assertSame('', studio_prompt_template('garment.lock'));
    }

    public function test_resolver_returns_db_body_over_fallback(): void
    {
        PromptTemplate::create(['key' => 'garment.lock', 'scope' => 'global', 'version' => 1, 'body' => 'prompt-moi-tu-DB', 'is_active' => true]);

        $this->assertSame('prompt-moi-tu-DB', studio_prompt_template('garment.lock', [], 'chuoi-cu'));
    }

    public function test_resolver_picks_the_highest_active_version(): void
    {
        PromptTemplate::create(['key' => 'garment.lock', 'version' => 1, 'body' => 'v1', 'is_active' => true]);
        PromptTemplate::create(['key' => 'garment.lock', 'version' => 2, 'body' => 'v2', 'is_active' => true]);

        $this->assertSame('v2', studio_prompt_template('garment.lock'));
    }

    public function test_inactive_higher_version_falls_back_to_active_lower(): void
    {
        PromptTemplate::create(['key' => 'garment.lock', 'version' => 1, 'body' => 'v1', 'is_active' => true]);
        PromptTemplate::create(['key' => 'garment.lock', 'version' => 2, 'body' => 'v2-draft', 'is_active' => false]);

        $this->assertSame('v1', studio_prompt_template('garment.lock'));
    }

    public function test_placeholders_are_substituted(): void
    {
        PromptTemplate::create(['key' => 'caption', 'version' => 1, 'body' => 'Ao dai {name} - {color}', 'is_active' => true]);

        $this->assertSame('Ao dai hoa - do', studio_prompt_template('caption', ['name' => 'hoa', 'color' => 'do']));
    }

    public function test_garment_lock_in_controller_comes_from_the_template(): void
    {
        // Static guard: controller phải resolve 'garment.lock' qua studio_prompt_template — nếu ai
        // đó gỡ bản vá 1.7 và quay lại hardcode thì guard này đỏ.
        $src = (string) file_get_contents(app_path('Http/Controllers/StudioController.php'));
        $this->assertStringContainsString("studio_prompt_template('garment.lock'", $src,
            'Controller phải resolve prompt garment.lock từ bảng prompt_templates (Đợt 1.7).');
        $this->assertStringContainsString('$garmentLockFallback', $src,
            'Controller phải giữ chuỗi cũ làm fallback khi DB chưa có hàng.');
    }
}
