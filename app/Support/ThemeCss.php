<?php

namespace App\Support;

use RuntimeException;

/**
 * SINH PHẦN BẢNG MÀU CỦA resources/css/app.css (2026-09-25).
 *
 * Vì sao tách khỏi lệnh artisan: cùng một phép sinh này được dùng ở BA nơi — lệnh "theme:sync" (ghi
 * ra tệp), chế độ "--check" của lệnh đó (CI), và bài test đối chiếu (tests/Feature/ThemeImportTest).
 * Nếu mỗi nơi tự ghép chuỗi thì chúng lệch nhau, và cái lệch đó KHÔNG làm đỏ bất cứ thứ gì cho tới
 * khi có người nhìn thấy giao diện sai màu.
 *
 * Hai khối được sinh:
 *   · khối trong @theme          — theme GỐC: bảng màu của chế độ Tối + token hình học;
 *   · khối trong [data-theme='light'] — BẢN ĐỐI ỨNG Sáng do ThemeDeriver sinh ra.
 * Cả hai đều nằm giữa cặp dấu "/* @theme:sync:start *\/" và "…:end *\/" nên việc ghi lại là thay
 * đúng ruột, không đụng tới phần viết tay (canvas · scrim · invert · cỡ chữ · chuyển động).
 */
final class ThemeCss
{
    public const FILE = 'css/app.css';

    public const MARK_START = '/* @theme:sync:start */';
    public const MARK_END = '/* @theme:sync:end */';

    /**
     * Thứ tự phát token + tiêu đề nhóm.
     *
     * Vì sao phải có bảng thứ tự: thứ tự khoá trong ThemeRamp là thứ tự TÍNH TOÁN (tiện cho mã), còn
     * thứ tự trong tệp CSS là thứ tự ĐỌC (tiện cho người). Token nào không có trong bảng này vẫn
     * được phát ra ở cuối — cố ý: thêm một token mới vào ThemeRamp mà quên khai ở đây thì nó vẫn
     * xuất hiện trong CSS, chỉ là chưa được xếp vào nhóm.
     */
    private const GROUPS = [
        'BỀ MẶT (nền trang → panel → card → ba mức viền)' => [
            '--color-ink-950', '--color-ink-900', '--color-ink-800',
            '--color-ink-700', '--color-ink-600', '--color-ink-500',
        ],
        'BỐN BẬC NỘI DUNG (chữ chính → ghi chú phụ; mọi bậc đạt WCAG AA)' => [
            '--color-cream-50', '--color-cream-100', '--color-cream-200', '--color-cream-300', '--color-cream-400',
        ],
        'DẢI NHẤN (50–300 là bậc CHỮ, 400–600 là nút/viền, 700–950 là nền tint)' => [
            '--color-brand-50', '--color-brand-100', '--color-brand-200', '--color-brand-300', '--color-brand-400',
            '--color-brand-500', '--color-brand-600', '--color-brand-700', '--color-brand-800', '--color-brand-900',
            '--color-brand-950',
        ],
        'HAI DẢI PHỤ (badge: clay ← secondary · gold ← accent)' => [
            '--color-clay-500', '--color-clay-600', '--color-gold-400', '--color-gold-500',
        ],
        'MÀU VAI TRÒ CỦA THEME (mỗi màu kèm màu chữ "-content")' => [
            '--color-primary', '--color-primary-content',
            '--color-secondary', '--color-secondary-content',
            '--color-accent', '--color-accent-content',
            '--color-neutral', '--color-neutral-content',
            '--color-info', '--color-info-content',
            '--color-success', '--color-success-content',
            '--color-warning', '--color-warning-content',
            '--color-error', '--color-error-content',
        ],
        'HÌNH HỌC (từ theme: bán kính · kích thước · độ dày viền · độ sâu)' => [
            '--radius-selector', '--radius-field', '--radius-box',
            '--size-selector', '--size-field', '--border', '--depth', '--noise',
        ],
    ];

    /**
     * Ruột của khối trong @theme (theme gốc: màu của chế độ Tối + hình học).
     */
    public static function darkBlock(): string
    {
        return self::render(ThemeRamp::full(ThemeLibrary::builtin('dark')), 'theme gốc · chế độ TỐI');
    }

    /**
     * Ruột của khối trong [data-theme='light'] (bản đối ứng Sáng).
     *
     * Cố ý KHÔNG phát token hình học ở đây: bán kính/độ dày viền không đổi theo chế độ, khai hai lần
     * là hai chỗ để lệch nhau.
     */
    public static function lightBlock(): string
    {
        return self::render(ThemeRamp::tokens(ThemeLibrary::builtin('light')), 'bản đối ứng · chế độ SÁNG');
    }

    /** @return list<string> [khối @theme, khối [data-theme='light']] */
    public static function blocks(): array
    {
        return [self::darkBlock(), self::lightBlock()];
    }

    /** Nội dung app.css ĐÁNG LẼ phải có (đã thay ruột hai khối sinh). */
    public static function expected(): string
    {
        return self::apply((string) file_get_contents(resource_path(self::FILE)));
    }

    /** Thay ruột hai khối sinh trong một nội dung CSS cho trước. */
    public static function apply(string $css): string
    {
        $offset = 0;

        foreach (self::blocks() as $i => $block) {
            $start = strpos($css, self::MARK_START, $offset);
            $end = $start === false ? false : strpos($css, self::MARK_END, $start);

            if ($start === false || $end === false) {
                throw new RuntimeException(sprintf(
                    'app.css thiếu cặp dấu %s / %s cho khối thứ %d — không biết ghi bảng màu vào đâu.',
                    self::MARK_START, self::MARK_END, $i + 1
                ));
            }

            $replacement = self::MARK_START."\n".$block."\n    ".self::MARK_END;
            $css = substr($css, 0, $start).$replacement.substr($css, $end + strlen(self::MARK_END));
            $offset = $start + strlen($replacement);
        }

        return $css;
    }

    /** app.css đang khớp với theme gốc? (dùng cho --check và cho test) */
    public static function inSync(): bool
    {
        return self::expected() === (string) file_get_contents(resource_path(self::FILE));
    }

    /**
     * @param array<string,string> $tokens
     */
    private static function render(array $tokens, string $title): string
    {
        $lines = [];
        $written = [];

        foreach (self::GROUPS as $heading => $keys) {
            $group = [];
            foreach ($keys as $key) {
                if (! array_key_exists($key, $tokens)) {
                    continue;
                }
                $group[] = '    '.$key.': '.$tokens[$key].';';
                $written[$key] = true;
            }

            if ($group !== []) {
                $lines[] = '    /* '.$heading.' */';
                $lines = array_merge($lines, $group);
                $lines[] = '';
            }
        }

        // Token chưa được xếp nhóm: VẪN phát ra (nếu không, thêm token mới vào ThemeRamp sẽ im lặng
        // biến mất khỏi CSS — loại lỗi chỉ lộ ra khi có người thấy màu sai).
        $rest = array_diff_key($tokens, $written);
        if ($rest !== []) {
            $lines[] = '    /* Chưa xếp nhóm (token mới) */';
            foreach ($rest as $key => $value) {
                $lines[] = '    '.$key.': '.$value.';';
            }
            $lines[] = '';
        }

        array_unshift($lines, '    /* ── '.$title.' · '.count($tokens).' token · sinh bởi "php artisan theme:sync" ── */');

        return implode("\n", array_slice($lines, 0, -1));
    }
}
