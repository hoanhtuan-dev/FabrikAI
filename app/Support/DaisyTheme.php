<?php

namespace App\Support;

use RuntimeException;

/**
 * MỘT THEME daisyUI — giá trị đã được KIỂM TRA, đọc từ liên kết của Theme Generator (2026-09-25).
 *
 * Vì sao có lớp này: từ nay bảng màu của FabrikAI không còn là một danh sách hex viết tay trong
 * app.css, mà được SINH từ một theme daisyUI. Theme đó đến từ bên ngoài (người dùng dán liên kết
 * vào trang Quản trị), nên nó là DỮ LIỆU KHÔNG TIN CẬY đi thẳng vào CSS của mọi trang:
 *   · mọi khoá phải nằm trong allowlist — khoá lạ bị BỎ QUA, không bao giờ được phát ra CSS;
 *   · mọi giá trị màu phải đọc được bằng ThemeColor (hex · oklch · rgb · hsl), không nhận chuỗi tự do;
 *   · độ dài/bán kính phải là số + đơn vị hợp lệ; --depth/--noise chỉ nhận 0 hoặc 1;
 *   · tên theme bị cắt và loại ký tự điều khiển (nó được in ra HTML ở trang Quản trị).
 * Nhờ vậy "import theme" không phải là một đường XSS: không có giá trị nào đi tới CSS mà chưa đi
 * qua đây, và ThemeRamp còn kiểm lại lần nữa trước khi ghép chuỗi.
 */
final class DaisyTheme
{
    /** 20 token màu của daisyUI v5 — ĐÚNG bộ tên, đúng thứ tự phát ra CSS. */
    public const COLOR_KEYS = [
        '--color-base-100', '--color-base-200', '--color-base-300', '--color-base-content',
        '--color-primary', '--color-primary-content',
        '--color-secondary', '--color-secondary-content',
        '--color-accent', '--color-accent-content',
        '--color-neutral', '--color-neutral-content',
        '--color-info', '--color-info-content',
        '--color-success', '--color-success-content',
        '--color-warning', '--color-warning-content',
        '--color-error', '--color-error-content',
    ];

    /** Token hình học: bán kính · kích thước · độ dày viền. */
    public const LENGTH_KEYS = [
        '--radius-selector', '--radius-field', '--radius-box',
        '--size-selector', '--size-field', '--border',
    ];

    /** Công tắc 0/1 (đổ bóng trong và nhiễu nền của daisyUI). */
    public const FLAG_KEYS = ['--depth', '--noise'];

    /** Khoá mô tả, không phải token CSS. */
    public const META_KEYS = ['name', 'color-scheme', 'default', 'prefersdark'];

    public const NAME_MAX = 60;

    /**
     * @param array<string,string> $tokens  token CSS → giá trị GỐC (giữ nguyên dạng đã nhận)
     * @param array<string,bool> $flags
     * @param list<string> $ignored  khoá lạ đã bỏ qua (báo lại cho người import biết)
     */
    private function __construct(
        private readonly string $name,
        private readonly string $scheme,
        private readonly array $tokens,
        private readonly array $flags,
        private readonly bool $isDefault,
        private readonly bool $prefersDark,
        private readonly array $ignored = [],
        private readonly string $sourceLink = '',
    ) {
    }

    /**
     * Đọc payload JSON của Theme Generator thành một theme ĐÃ KIỂM TRA.
     *
     * @param array<string,mixed> $payload
     * @throws RuntimeException khi payload thiếu token bắt buộc hoặc có giá trị không hợp lệ
     */
    public static function fromArray(array $payload, string $sourceLink = ''): self
    {
        $name = self::cleanName((string) ($payload['name'] ?? ''));
        $scheme = strtolower(trim((string) ($payload['color-scheme'] ?? '')));

        if (! in_array($scheme, ['light', 'dark'], true)) {
            throw new RuntimeException('Theme thiếu "color-scheme" (chỉ nhận light hoặc dark).');
        }

        $tokens = [];
        $missing = [];
        $ignored = [];

        foreach (self::COLOR_KEYS as $key) {
            $value = $payload[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;

                continue;
            }
            $hex = ThemeColor::toHex($value);
            if ($hex === null) {
                throw new RuntimeException(sprintf(
                    '%s có giá trị không đọc được: "%s". Chỉ nhận hex · oklch() · rgb() · hsl().',
                    $key, self::shorten($value)
                ));
            }
            // Lưu dạng ĐÃ CHUẨN HOÁ (#rrggbb): một dạng duy nhất ⇒ so sánh, băm checksum và kiểm
            // tương phản đều trên cùng một biểu diễn, không phụ thuộc cách viết của người gửi.
            $tokens[$key] = $hex;
        }

        if ($missing !== []) {
            throw new RuntimeException('Theme thiếu token bắt buộc: '.implode(', ', $missing).'.');
        }

        foreach (self::LENGTH_KEYS as $key) {
            $value = $payload[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (! self::isLength((string) $value)) {
                throw new RuntimeException(sprintf('%s phải là số kèm đơn vị (px/rem/em) hoặc 0, nhận được "%s".', $key, self::shorten((string) $value)));
            }
            $tokens[$key] = strtolower(preg_replace('/\s+/', '', (string) $value) ?? '');
        }

        $flags = [];
        foreach (self::FLAG_KEYS as $key) {
            $value = $payload[$key] ?? null;
            if ($value === null) {
                continue;
            }
            if (! in_array((string) $value, ['0', '1'], true)) {
                throw new RuntimeException(sprintf('%s chỉ nhận 0 hoặc 1, nhận được "%s".', $key, self::shorten((string) $value)));
            }
            $flags[$key] = ((string) $value) === '1';
        }

        foreach (array_keys($payload) as $key) {
            if (! in_array($key, [...self::COLOR_KEYS, ...self::LENGTH_KEYS, ...self::FLAG_KEYS, ...self::META_KEYS], true)) {
                $ignored[] = (string) $key;
            }
        }

        return new self(
            $name,
            $scheme,
            $tokens,
            $flags,
            (bool) ($payload['default'] ?? false),
            (bool) ($payload['prefersdark'] ?? false),
            $ignored,
            $sourceLink,
        );
    }

    /** Payload đúng dạng của Theme Generator (dùng để mã hoá lại thành liên kết). */
    public function toArray(): array
    {
        $out = ['name' => $this->name, 'color-scheme' => $this->scheme];
        foreach (self::COLOR_KEYS as $key) {
            $out[$key] = $this->tokens[$key];
        }
        foreach (self::LENGTH_KEYS as $key) {
            if (isset($this->tokens[$key])) {
                $out[$key] = $this->tokens[$key];
            }
        }
        foreach (self::FLAG_KEYS as $key) {
            if (isset($this->flags[$key])) {
                $out[$key] = $this->flags[$key] ? '1' : '0';
            }
        }
        $out['default'] = $this->isDefault;
        $out['prefersdark'] = $this->prefersDark;

        return $out;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function sourceLink(): string
    {
        return $this->sourceLink;
    }

    /** @return list<string> */
    public function ignoredKeys(): array
    {
        return $this->ignored;
    }

    /** Giá trị GỐC của một token (dạng chuỗi đã chuẩn hoá). */
    public function raw(string $key): ?string
    {
        return $this->tokens[$key] ?? null;
    }

    /** @return array<string,string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function flag(string $key): bool
    {
        return $this->flags[$key] ?? false;
    }

    /** Theme có khai công tắc này không (phân biệt "khai 0" với "không khai"). */
    public function hasFlag(string $key): bool
    {
        return array_key_exists($key, $this->flags);
    }

    /** Một bản sao với tên khác (dùng khi sinh bản Sáng/Tối đối ứng). */
    public function withName(string $name): self
    {
        return new self(self::cleanName($name), $this->scheme, $this->tokens, $this->flags,
            $this->isDefault, $this->prefersDark, $this->ignored, $this->sourceLink);
    }

    /** Một bản sao với màu đã chỉnh (khoá → hex) — dùng khi phải hạ sắc độ cho đạt AA. */
    public function withTokens(array $overrides): self
    {
        return new self($this->name, $this->scheme, array_merge($this->tokens, $overrides), $this->flags,
            $this->isDefault, $this->prefersDark, $this->ignored, $this->sourceLink);
    }

    public function withScheme(string $scheme): self
    {
        return new self($this->name, $scheme, $this->tokens, $this->flags,
            $this->isDefault, $this->prefersDark, $this->ignored, $this->sourceLink);
    }

    /**
     * Vân tay của theme — dùng để KHÔNG lưu trùng: import lại đúng liên kết đó lần thứ hai thì
     * không sinh thêm một bản sao trong thư viện.
     */
    public function checksum(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Slug an toàn cho URL/DB, sinh từ tên: "FabrikAI Tối" → "fabrikai-toi". */
    public function slug(): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $this->name) ?: $this->name;
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $ascii) ?? '');
        $slug = trim($slug, '-');

        return $slug === '' ? 'theme' : substr($slug, 0, 50);
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        // Tên được in ra HTML ở trang Quản trị ⇒ không nhận ký tự có nghĩa trong HTML/CSS.
        $name = str_replace(['<', '>', '&', '"', "'", '\\', '{', '}', ';'], '', $name);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return $name === '' ? 'Theme không tên' : mb_substr($name, 0, self::NAME_MAX);
    }

    /** Bán kính/độ dày: "0" hoặc số (tối đa 3 chữ số) kèm đơn vị. Không nhận %, không nhận calc(). */
    private static function isLength(string $value): bool
    {
        return preg_match('/^\s*(0|[0-9]{1,3}(\.[0-9]{1,4})?\s*(px|rem|em))\s*$/i', $value) === 1;
    }

    private static function shorten(string $value): string
    {
        $value = trim($value);

        return mb_strlen($value) > 40 ? mb_substr($value, 0, 40).'…' : $value;
    }
}
