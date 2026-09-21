<?php

namespace Tests\Feature;

use App\Models\PromptTemplate;
use App\Services\DesignAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PROMPT LINH HOẠT — chỉ dẫn lấy được từ cấu hình, KHÔNG cần deploy (2026-09-22).
 *
 * Khoá ba bất biến, vì đây là lớp có thể làm hỏng MỌI lượt AI cùng lúc nếu sai:
 *   1. KHÔNG cấu hình ⇒ hành vi Y NHƯ CŨ (trả về đúng chuỗi dựng trong mã). Đây là điều kiện để thay đổi
 *      này an toàn: bật/tắt bằng DỮ LIỆU, không bằng deploy.
 *   2. Cấu hình RỖNG ⇒ coi như chưa cấu hình. Một hàng rỗng làm lượt chạy mất hết chỉ dẫn mà không ai thấy
 *      lỗi — đúng loại hỏng im lặng phải chặn.
 *   3. Cấu hình CÓ ⇒ thay thế được, và {placeholder} phải được thay bằng giá trị thật.
 *
 * Dùng lại bảng prompt_templates có sẵn từ Đợt 1.7 — KHÔNG tạo cơ chế cấu hình thứ hai.
 */
class PromptConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Cache::flush();
    }

    /** Gọi thẳng hàm cấu hình hoá — nó là private và đó là chủ ý (chỉ service này dùng). */
    private function hook(string $key, string $built, array $vars = []): string
    {
        $svc = app(DesignAgentService::class);
        $m = new \ReflectionMethod($svc, 'instruction');
        $m->setAccessible(true);

        return (string) $m->invoke($svc, $key, $built, $vars);
    }

    public function test_without_a_configured_row_the_built_in_instruction_is_returned_unchanged(): void
    {
        $built = 'CHUỖI DỰNG TRONG MÃ';

        $this->assertSame($built, $this->hook('agent.radar.instruction', $built),
            'Chưa cấu hình thì phải trả về ĐÚNG chuỗi trong mã — nếu không, thay đổi này đã đổi hành vi.');
    }

    public function test_a_configured_row_replaces_the_instruction_and_fills_placeholders(): void
    {
        PromptTemplate::create([
            'key' => 'agent.radar.instruction',
            'body' => 'Bạn là TrendRadar. Hôm nay là {today}. Khu vực {region_name}.',
            'version' => 1,
            'is_active' => true,
        ]);

        $out = $this->hook('agent.radar.instruction', 'CHUỖI CŨ', ['today' => '22/09/2026', 'region_name' => 'TP.HCM']);

        $this->assertStringContainsString('Hôm nay là 22/09/2026', $out, 'Placeholder phải được thay bằng giá trị thật.');
        $this->assertStringContainsString('Khu vực TP.HCM', $out);
        $this->assertStringNotContainsString('CHUỖI CŨ', $out, 'Cấu hình phải THAY THẾ, không phải nối thêm.');
    }

    public function test_a_blank_configured_row_is_treated_as_not_configured(): void
    {
        PromptTemplate::create([
            'key' => 'agent.radar.instruction',
            'body' => '   ',
            'version' => 1,
            'is_active' => true,
        ]);

        $this->assertSame('CHUỖI DỰNG TRONG MÃ', $this->hook('agent.radar.instruction', 'CHUỖI DỰNG TRONG MÃ'),
            'Hàng rỗng KHÔNG được làm lượt chạy mất hết chỉ dẫn.');
    }

    public function test_the_highest_active_version_wins_and_inactive_rows_are_ignored(): void
    {
        PromptTemplate::create(['key' => 'agent.radar.instruction', 'body' => 'BẢN 1', 'version' => 1, 'is_active' => true]);
        PromptTemplate::create(['key' => 'agent.radar.instruction', 'body' => 'BẢN 3', 'version' => 3, 'is_active' => true]);
        PromptTemplate::create(['key' => 'agent.radar.instruction', 'body' => 'BẢN TẮT', 'version' => 9, 'is_active' => false]);

        $this->assertSame('BẢN 3', $this->hook('agent.radar.instruction', 'MẶC ĐỊNH'),
            'Phải lấy version CAO NHẤT trong các hàng đang bật; hàng tắt không được tính.');
    }

    public function test_the_three_agent_studio_prompts_are_wired_through_the_hook(): void
    {
        // Bất biến về ĐƯỜNG ĐI: ba chỉ dẫn lớn của Agent Studio phải thật sự đi qua mốc cấu hình, nếu không
        // thì "prompt linh hoạt" chỉ đúng trên giấy.
        $src = (string) file_get_contents(app_path('Services/DesignAgentService.php'));

        foreach (['agent.radar.instruction', 'agent.collection_brief.instruction', 'agent.sample_prompt.instruction'] as $key) {
            $this->assertStringContainsString("'".$key."'", $src, 'Thiếu mốc cấu hình: '.$key);
        }
    }
}
