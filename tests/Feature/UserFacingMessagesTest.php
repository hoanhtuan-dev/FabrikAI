<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * THÔNG BÁO · CHỈ BÁO · TIẾN TRÌNH — KHÔNG RÒ RỈ CHI TIẾT KỸ THUẬT.
 *
 * [Yêu cầu 2026-09-22] Luật đầy đủ ở docs/DESIGN_SYSTEM.md §6. Tóm tắt: giao diện chỉ nói NGƯỜI
 * DÙNG cần biết (chuyện gì xảy ra + làm gì tiếp); tên model AI, tên nhà cung cấp, mã HTTP, JSON,
 * lệnh CLI, đường dẫn file… là việc của LẬP TRÌNH VIÊN — chúng đi vào storage/logs/laravel.log và
 * console của trình duyệt.
 *
 * Đã từng lọt ra giao diện thật (đo được trước khi sửa):
 *   · "Qwen vision: HTTP 429: {"error":{"message":"Your token-plan 1-week quota has been exhausted…"}}"
 *     — nguyên văn lỗi của nhà cung cấp, hiện trong card "Gợi ý từ ảnh";
 *   · "AI đang đọc ảnh và suy luận… (deepseek · deepseek-flash)" — nhãn tiến trình nêu tên model;
 *   · "Provider này lỗi — đang thử provider kế tiếp…" — thuật ngữ hạ tầng;
 *   · "Không đọc được cài đặt Ghép Trang Phục (bảng dữ liệu chưa được tạo?). Chạy lệnh:
 *      php artisan migrate --force" — HƯỚNG DẪN CHO LẬP TRÌNH VIÊN hiện cho khách hàng;
 *   · "Tính năng Thay vùng cần key AI (Qwen Edit / DashScope).";
 *   · "Chưa cấu hình model tạo/sửa ảnh (nhóm “edit”)…" · "Chưa cấu hình API key…".
 *
 * Bài test này giữ BỐN tầng, để không phải sửa lại từ đầu:
 *   1. Nhãn tiến trình phía PHP sạch;
 *   2. Lỗi ném cho trình duyệt đi qua đường an toàn (không `$e->getMessage()` thô);
 *   3. Những câu đã gỡ không quay lại;
 *   4. Phía JS: có CỬA CHẶN ở biên (toast/notify) + không còn chỗ nào nhét lỗi thô vào state hiển thị.
 */
class UserFacingMessagesTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(base_path($rel));
    }

    /** Tên model/nhà cung cấp + dấu hiệu kỹ thuật — không được có trong câu nói với người dùng. */
    private const LEAK = '/(deepseek|qwen|gemini|dashscope|replicate|fal\.ai|\\bveo\\b|\\bwan\\b|\\bflux\\b|provider|\\bmodel\\b|http\\s*\\d{3}|\\bjson\\b|php artisan|api key|endpoint|sqlstate)/i';

    public function test_progress_labels_never_name_the_ai_or_the_provider(): void
    {
        $service = $this->src('app/Services/StyleSuggestService.php');
        preg_match_all("/'label'\s*=>\s*'([^']*)'/", $service, $m);

        $this->assertGreaterThanOrEqual(4, count($m[1]), 'Không đọc được các nhãn tiến trình của luồng Gợi ý từ ảnh.');

        foreach ($m[1] as $label) {
            $this->assertDoesNotMatchRegularExpression(self::LEAK, $label,
                'Nhãn tiến trình để lộ chi tiết kỹ thuật: "'.$label.'" — xem docs/DESIGN_SYSTEM.md §6.');
        }

        // Nhãn phải nói NGƯỜI DÙNG đang chờ việc gì.
        $this->assertStringContainsString("'Đang chuẩn bị ảnh nguồn…'", $service);
        $this->assertStringContainsString("'AI đang đọc ảnh và suy luận…'", $service, 'Nhãn tiến trình chính phải nói người dùng đang chờ gì.');
    }

    public function test_stream_errors_go_through_the_safe_path(): void
    {
        $controller = $this->src('app/Http/Controllers/StudioController.php');

        // Lỗi của luồng NDJSON phải là câu hướng dẫn, KHÔNG phải message thô của exception.
        // (Chỉ soi đúng chỗ GHI RA STREAM — `logger()->error([... 'message' => $e->getMessage()])`
        //  là chỗ ĐƯỢC PHÉP và BẮT BUỘC, vì đó là log cho lập trình viên.)
        $this->assertDoesNotMatchRegularExpression(
            '/\$write\(\[[^\]]*\'message\'\s*=>\s*\$e->getMessage\(\)/s',
            $controller,
            'Luồng stream lại gửi nguyên văn lỗi của nhà cung cấp cho trình duyệt.'
        );
        $this->assertStringContainsString("logger()->error('suggestStream failed'", $controller,
            'Chi tiết kỹ thuật của luồng stream phải được ghi log để lập trình viên còn chẩn đoán.');
        $this->assertStringContainsString('Không phân tích được ảnh này. Bạn thử lại sau ít phút', $controller,
            'Thông báo lỗi cho người dùng phải nói họ nên làm gì tiếp.');

        // Mọi action gọi model ở luồng gợi ý không được ném lỗi thô ra ngoài.
        $service = $this->src('app/Services/StyleSuggestService.php');
        $this->assertDoesNotMatchRegularExpression('/throw new \\RuntimeException\(\'[^\']*(Lỗi cuối|API key)/',
            $service, 'Ngoại lệ ném cho controller còn kèm chi tiết kỹ thuật.');
    }

    public function test_removed_technical_messages_never_come_back(): void
    {
        $banned = [
            'php artisan migrate --force' => 'Hướng dẫn CLI của lập trình viên từng hiện cho khách hàng.',
            'Qwen Edit / DashScope' => 'Thông báo có tên nhà cung cấp.',
            'Provider này lỗi' => 'Thuật ngữ hạ tầng trong nhãn tiến trình.',
            '(nhóm “edit”)' => 'Nhóm công việc nội bộ lọt vào cảnh báo người dùng.',
            'kiểm tra cài đặt API/model' => 'Câu dự phòng nêu API/model.',
        ];

        foreach ($banned as $needle => $why) {
            foreach ([
                'app/Http/Controllers/StudioController.php',
                'app/Services/StyleSuggestService.php',
                'app/Services/PhotoStudioService.php',
                'app/Services/ImageAIService.php',
                'app/Services/DesignAgentService.php',
                'app/Support/helpers.php',
                'resources/js/studio/components/SuggestCard.vue',
                'resources/js/studio/components/StudioCard.vue',
                'resources/js/studio/components/OutputModule.vue',
                'resources/js/studio/AgentStudioApp.vue',
                'resources/js/studio/composables/useAgentStudio.js',
                'resources/js/studio/components/agents/AgentDnaStep.vue',
                'resources/js/studio/components/agents/AgentRadarStep.vue',
                'resources/js/studio/components/agents/AgentBriefStep.vue',
                'resources/js/studio/components/agents/AgentCanvasStep.vue',
            ] as $file) {
                // Cho phép nhắc trong CHÚ THÍCH (giải thích lịch sử), cấm trong MÃ.
                $code = preg_replace(['/\/\/[^\n]*/', '/\/\*.*?\*\//s', '/<!--.*?-->/s', '/^\s*\*.*$/m', '/^\s*\/\/.*$/m'], '', $this->src($file));
                $this->assertStringNotContainsString($needle, (string) $code, $why.' ('.$file.')');
            }
        }
    }

    public function test_the_frontend_has_a_boundary_that_blocks_technical_text(): void
    {
        $store = static::studioStoreSource();

        // (1) Có bộ lọc dùng chung + nó thật sự được dùng ở hai cửa hiển thị.
        $this->assertStringContainsString('export function safeMessage(', $store, 'Thiếu bộ lọc câu nói với người dùng.');
        $this->assertStringContainsString('export function userFacingError(', $store, 'Thiếu hàm bắt lỗi hướng người dùng.');
        $this->assertMatchesRegularExpression('/toast\(msg, type = .info., opts = \{\}\) \{[^}]*safeMessage\(msg/s',
            $store, 'toast() PHẢI là cửa chặn: mọi thông báo đi qua đây.');
        $this->assertMatchesRegularExpression('/notify\(msg, type = .info., opts = \{\}\) \{[^}]*safeMessage\(msg/s',
            $store, 'notify() cũng được gọi trực tiếp — phải lọc lại.');

        // (2) Không còn chỗ nào nhét lỗi thô vào state hiển thị. (Bỏ chú thích trước khi soi —
        //     khối chú thích giải thích lịch sử có nhắc tới chính câu lệnh cũ.)
        $storeCode = (string) preg_replace(['/\/\/[^\n]*/', '/\/\*.*?\*\//s'], '', $store);
        preg_match_all('/this\.[a-zA-Z]+Error\s*=\s*[^;]+;/', $storeCode, $m);
        $this->assertNotEmpty($m[0], 'Không đọc được các state lỗi của store.');
        foreach ($m[0] as $assignment) {
            $this->assertDoesNotMatchRegularExpression('/=\s*(e|error|err|ev)\.message/',
                $assignment, 'Lỗi thô được gán thẳng vào state hiển thị — phải dùng userFacingError()/safeMessage(): '.$assignment);
        }

        // (3) Card không được render tên AI/model ở chỉ báo & tiến trình.
        $card = $this->src('resources/js/studio/components/SuggestCard.vue');
        $this->assertStringNotContainsString('providerLabel', $card, 'Card lại hiển thị tên AI.');
        $this->assertStringNotContainsString('store.suggestModel', $card, 'Card lại hiển thị tên model.');
        foreach (['DeepSeek', 'Qwen', 'Gemini', 'DashScope', 'Fal.ai', 'Replicate'] as $name) {
            $this->assertStringNotContainsString($name, preg_replace('/^\s*(\/\/|\*).*$/m', '', $card),
                'Card còn tên nhà cung cấp: '.$name);
        }

        // (4) Chỉ báo của Agent Studio cũng là "chỉ báo" — không được nêu provider/model.
        $agents = static::designAgentsSource();

        preg_match('/const modelShort = computed\((.*?)const modelCandidates/s', $agents, $m);
        $this->assertNotEmpty($m[1] ?? '', 'Không đọc được phần chỉ báo trạng thái AI của Agent Studio.');
        $this->assertDoesNotMatchRegularExpression('/\.provider|\.model\b/', $m[1],
            'Chỉ báo trạng thái AI lại dựng câu từ provider/model.');

        preg_match('/const MODEL_REASON_LABELS = \{(.*?)\};/s', $agents, $m2);
        $this->assertNotEmpty($m2[1] ?? '', 'Không đọc được các nhãn lý do của Agent Studio.');
        preg_match_all("/:\s*'([^']*)'/", $m2[1], $labels);
        $this->assertNotEmpty($labels[1], 'Không đọc được nội dung nhãn lý do.');
        foreach ($labels[1] as $label) {
            $this->assertDoesNotMatchRegularExpression(self::LEAK, $label,
                'Nhãn lý do trong Agent Studio để lộ chi tiết kỹ thuật: "'.$label.'"');
        }
    }

    public function test_the_rule_is_written_down_in_the_shared_guide(): void
    {
        $guide = $this->src('docs/DESIGN_SYSTEM.md');

        $this->assertStringContainsString('## 6. Thông báo · chỉ báo · tiến trình', $guide,
            'Thiếu mục quy tắc về thông báo trong hướng dẫn thiết kế chung.');
        foreach ([
            'KHÔNG rò rỉ chi tiết kỹ thuật',
            'tên model AI',
            'nhà cung cấp',
            'storage/logs/laravel.log',
            'console',
            'ngoại lệ',
        ] as $needle) {
            $this->assertStringContainsString($needle, $guide, 'Mục §6 của hướng dẫn thiếu nội dung: '.$needle);
        }
    }
}
