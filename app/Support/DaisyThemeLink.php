<?php

namespace App\Support;

use RuntimeException;

/**
 * ĐỌC / GHI LIÊN KẾT CỦA daisyUI THEME GENERATOR (2026-09-25).
 *
 * Liên kết có dạng:  https://daisyui.com/theme-generator/#theme=<payload>
 * trong đó <payload> = base64url( zlib( JSON của theme ) ). Đây KHÔNG phải định dạng do FabrikAI
 * nghĩ ra — nó là định dạng của chính daisyUI, và cả bốn việc dưới đây đều bám đúng nó:
 *   · đọc được liên kết đầy đủ, đoạn "#theme=…", hoặc chỉ mỗi payload;
 *   · một lần dán NHIỀU liên kết (mỗi liên kết một dòng) ⇒ nhiều theme;
 *   · một payload chứa MẢNG theme cũng đọc được (nhiều theme trong một liên kết);
 *   · mã hoá NGƯỢC LẠI thành liên kết — nhờ vậy bản Sáng tự sinh vẫn dán lại được vào trang
 *     Theme Generator của daisyUI để chỉnh tiếp, thay vì chết trong database của mình.
 *
 * Vì sao phải giới hạn kích thước: payload do người dùng dán vào. Một chuỗi nén nhỏ có thể bung ra
 * hàng trăm MB ("decompression bomb") và làm treo máy chủ. Mọi phép bung nén ở đây đều có trần.
 */
final class DaisyThemeLink
{
    /** Tên tham số trong liên kết của daisyUI. */
    public const PARAM = 'theme';

    /** Trần kích thước phần đã mã hoá của MỘT theme (liên kết thật dài ~2 KB). */
    private const MAX_PAYLOAD_BYTES = 20000;

    /** Trần kích thước JSON sau khi bung nén. */
    private const MAX_JSON_BYTES = 262144;

    /** Trần số theme trong một lần import — chặn một lần dán làm treo trang Quản trị. */
    public const MAX_THEMES_PER_IMPORT = 20;

    /**
     * Đọc mọi theme trong một lần dán.
     *
     * @return list<DaisyTheme>
     * @throws RuntimeException khi không tìm thấy liên kết hợp lệ hoặc payload hỏng
     */
    public static function decode(string $input): array
    {
        $payloads = self::payloads($input);

        if ($payloads === []) {
            throw new RuntimeException('Không tìm thấy liên kết theme trong nội dung đã dán. Hãy dán liên kết đầy đủ từ daisyui.com/theme-generator (phần "#theme=…" là bắt buộc).');
        }

        if (count($payloads) > self::MAX_THEMES_PER_IMPORT) {
            throw new RuntimeException(sprintf('Một lần import tối đa %d theme, nội dung đã dán có %d.', self::MAX_THEMES_PER_IMPORT, count($payloads)));
        }

        $themes = [];
        foreach ($payloads as $index => $payload) {
            foreach (self::decodePayload($payload) as $data) {
                try {
                    $themes[] = DaisyTheme::fromArray($data, self::PARAM.'='.$payload);
                } catch (RuntimeException $e) {
                    throw new RuntimeException(sprintf('Theme thứ %d không hợp lệ: %s', $index + 1, $e->getMessage()), 0, $e);
                }
            }
        }

        if ($themes === []) {
            throw new RuntimeException('Không đọc được theme nào từ nội dung đã dán.');
        }

        return $themes;
    }

    /**
     * Tách các payload base64url ra khỏi nội dung người dùng dán.
     *
     * @return list<string>
     */
    public static function payloads(string $input): array
    {
        $found = [];

        foreach (preg_split('/\R/', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match_all('/'.self::PARAM.'=([A-Za-z0-9\-_]+={0,2})/', $line, $m)) {
                foreach ($m[1] as $payload) {
                    $found[] = $payload;
                }

                continue;
            }

            // Không có "theme=" nhưng cả dòng là một payload trần (người dùng chỉ copy phần sau dấu #).
            if (preg_match('/^[A-Za-z0-9\-_]{60,}={0,2}$/', $line)) {
                $found[] = $line;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Bung nén + đọc JSON của MỘT payload.
     *
     * @return list<array<string,mixed>>
     */
    private static function decodePayload(string $payload): array
    {
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new RuntimeException('Liên kết theme quá dài — có thể đây không phải liên kết của Theme Generator.');
        }

        $binary = base64_decode(strtr($payload, '-_', '+/').str_repeat('=', (4 - strlen($payload) % 4) % 4), true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Phần "#theme=…" không phải chuỗi base64 hợp lệ.');
        }

        $json = self::inflate($binary);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new RuntimeException('Nội dung sau khi giải nén không phải JSON của theme daisyUI.');
        }

        // Payload chuẩn là MỘT đối tượng theme; mảng là trường hợp nhiều theme trong một liên kết.
        if (array_is_list($data)) {
            $themes = [];
            foreach ($data as $item) {
                if (! is_array($item)) {
                    throw new RuntimeException('Một phần tử trong danh sách theme không phải đối tượng JSON.');
                }
                $themes[] = $item;
            }

            return $themes;
        }

        return [$data];
    }

    /** Bung nén zlib (định dạng daisyUI dùng), chấp nhận cả raw deflate và gzip của các bản cũ. */
    private static function inflate(string $binary): string
    {
        $max = self::MAX_JSON_BYTES;

        foreach (['gzuncompress', 'gzinflate', 'gzdecode'] as $fn) {
            $out = @$fn($binary, $max);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        throw new RuntimeException('Không giải nén được nội dung theme (liên kết có thể đã bị cắt khi copy).');
    }

    /** Mã hoá một theme thành liên kết dán lại được vào trang Theme Generator. */
    public static function encode(DaisyTheme $theme): string
    {
        $json = json_encode($theme->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $deflated = gzcompress((string) $json, 9);

        return self::PARAM.'='.rtrim(strtr(base64_encode($deflated), '+/', '-_'), '=');
    }

    /** Liên kết đầy đủ (mở được bằng trình duyệt) của một theme. */
    public static function url(DaisyTheme $theme): string
    {
        return 'https://daisyui.com/theme-generator/#'.self::encode($theme);
    }
}
