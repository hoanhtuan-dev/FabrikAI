<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * KHÔNG RÒ RỈ CHI TIẾT KỸ THUẬT RA GIAO DIỆN NGƯỜI DÙNG (2026-09-26).
 *
 * Luật đã có từ trước (docs/DESIGN_SYSTEM.md §6) và đã có bộ lọc ở tầng tin nhắn
 * (resources/js/studio/store/helpers.js — TECH_LEAK + safeMessage). Nhưng bộ lọc đó chỉ chặn được
 * thông báo LỖI đi qua nó; chữ VIẾT THẲNG trong giao diện (nhãn · tiêu đề · placeholder · toast) thì
 * không đi qua bộ lọc nào. Đợt này đo được ba chỗ như vậy, nên luật cần một rào chắn ở tầng MÃ NGUỒN.
 *
 * PHẠM VI: các bề mặt của KHÁCH HÀNG. Trang quản trị và Cài đặt được MIỄN — ở đó chủ dự án phải thấy
 * tên nhà cung cấp/model thì mới cấu hình được; đó là thông tin của chính họ, không phải rò rỉ.
 *
 * CÁCH KIỂM: chỉ soi CHỮ HIỂN THỊ (nội dung giữa hai thẻ, giá trị của title/placeholder/aria-label,
 * và chuỗi trong toast), không soi mã — trong mã thì provider và model là TÊN TRƯỜNG dữ liệu hợp lệ.
 */
class TechnicalLeakTest extends TestCase
{
    /** Nhận dạng của nhà cung cấp / model AI — thứ KHÔNG được xuất hiện trong chữ hiển thị. */
    private const IDENTIFIERS = [
        'deepseek', 'qwen', 'dashscope', 'gemini', 'replicate', 'fal\\.ai', 'falai', 'openai', 'anthropic',
        'text-embedding', 'veo', 'sdxl', 'flux', 'gpt-', 'claude-', 'xah\\.io', 'ckey',
    ];

    /** @return list<string> các bề mặt của KHÁCH HÀNG */
    private function surfaces(): array
    {
        $files = [];

        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('js/studio')) as $file) {
            $rel = 'resources/js/studio/'.str_replace('\\', '/', $file->getRelativePathname());
            if ($this->exempt($rel)) {
                continue;
            }
            if (in_array($file->getExtension(), ['vue', 'js'], true)) {
                $files[] = $rel;
            }
        }

        foreach (\Illuminate\Support\Facades\File::allFiles(resource_path('views')) as $file) {
            $rel = 'resources/views/'.str_replace('\\', '/', $file->getRelativePathname());
            if ($this->exempt($rel)) {
                continue;
            }
            if ($file->getExtension() === 'php') {
                $files[] = $rel;
            }
        }

        return $files;
    }

    /**
     * Miễn trừ có lý do — khu vực CẤU HÌNH (chủ dự án thấy tên nhà cung cấp là ĐÚNG) và chính bộ lọc
     * (tệp đó chứa các từ khoá này theo thiết kế, để CHẶN chúng).
     */
    private function exempt(string $rel): bool
    {
        foreach ([
            'SettingsApp.vue', 'AdminApp.vue', 'components/settings/', 'MySettingsApp.vue',
            'views/studio/admin.blade.php', 'views/studio/settings.blade.php',
            'views/studio/design-tokens.blade.php', 'views/studio/my-settings.blade.php',
            'store/helpers.js', 'clientErrors.js',
        ] as $skip) {
            if (str_contains($rel, $skip)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> chữ hiển thị trong một tệp: nội dung thẻ · thuộc tính · chuỗi trong toast */
    private function displayTexts(string $src): array
    {
        $out = [];

        // (1) Nội dung giữa hai thẻ (chữ người dùng đọc).
        if (preg_match_all('/>([^<>{}]{4,})</u', $src, $m)) {
            foreach ($m[1] as $text) {
                $out[] = trim($text);
            }
        }

        // (2) Nhãn/tiêu đề/placeholder — cũng là chữ người dùng đọc.
        if (preg_match_all('/(?:title|placeholder|aria-label|alt)="([^"]{3,})"/u', $src, $m)) {
            foreach ($m[1] as $text) {
                $out[] = $text;
            }
        }

        // (3) Chuỗi đưa thẳng vào thông báo (toast) — không đi qua bộ lọc tin nhắn nào.
        if (preg_match_all('/\.toast\(\s*[\'"]([^\'"]{4,})[\'"]/u', $src, $m)) {
            foreach ($m[1] as $text) {
                $out[] = $text;
            }
        }

        return $out;
    }

    public function test_customer_facing_text_never_names_an_ai_provider_or_model(): void
    {
        $pattern = '/(?:'.implode('|', self::IDENTIFIERS).')/i';
        $violations = [];

        foreach ($this->surfaces() as $rel) {
            $src = (string) file_get_contents(base_path($rel));
            foreach ($this->displayTexts($src) as $text) {
                if (preg_match($pattern, $text) === 1) {
                    $violations[] = $rel.' → '.mb_substr(preg_replace('/\s+/', ' ', $text), 0, 80);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            'Chữ HIỂN THỊ cho người dùng có tên nhà cung cấp/model AI. Luật ở docs/DESIGN_SYSTEM.md §6: '
            .'giao diện chỉ nói người dùng cần biết (chuyện gì xảy ra + làm gì tiếp); chi tiết kỹ thuật đi '
            .'vào log. Trang quản trị và Cài đặt được miễn vì chủ dự án phải cấu hình nhà cung cấp.');
    }

    public function test_no_error_path_prints_a_raw_exception_to_the_user(): void
    {
        // Bắt đúng lỗi đã xảy ra thật: các chỗ gán thẳng e.message vào biến hiển thị mà KHÔNG đi qua
        // safeMessage() — người dùng đọc nguyên chuỗi exception của nhà cung cấp AI.
        $violations = [];

        foreach ($this->surfaces() as $rel) {
            if (! str_ends_with($rel, '.vue') && ! str_ends_with($rel, '.js')) {
                continue;
            }
            $src = (string) file_get_contents(base_path($rel));
            if (preg_match_all('/\bError\s*=\s*(?:e|err|error)\.message\b/', $src, $m)) {
                foreach ($m[0] as $hit) {
                    $violations[] = $rel.' → '.$hit;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($violations)),
            'Gán thẳng e.message vào chữ hiển thị là đưa exception thô ra giao diện. Dùng safeMessage(...) '
            .'(helpers.js) để câu kỹ thuật bị thay bằng câu hướng dẫn, bản gốc đi vào console + log.');
    }
}
