<?php

namespace App\Support;

use App\Models\Theme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * THƯ VIỆN THEME — nguồn sự thật của bảng màu lúc chạy (2026-09-25 · docs/DESIGN_SYSTEM.md §1.1).
 *
 * Mô hình:
 *   · THEME GỐC của sản phẩm nằm trong resources/themes/daisyui-dark.theme.json — đúng payload của
 *     liên kết daisyUI mà người dùng đưa. Bản Sáng của nó do ThemeDeriver sinh ra, không viết tay.
 *   · app.css (đã commit) được SINH từ cặp đó bằng "php artisan theme:sync" ⇒ mở trang lên là đúng
 *     bảng màu, kể cả khi database trống.
 *   · Khi Quản trị viên IMPORT một liên kết mới và KÍCH HOẠT nó, trang web phát thêm một khối
 *     <style> trong <head> ghi đè token của chế độ tương ứng (xem overrideCss). Không cần build lại
 *     CSS, không nháy màu: khối đó do server render ngay trong <head>.
 *
 * Bất biến: mỗi chế độ (Sáng/Tối) có NHIỀU NHẤT MỘT theme đang bật. Con trỏ nằm ở bảng settings
 * (một chỗ duy nhất) chứ không phải một cột boolean trên themes — hai cột boolean thì hai dòng cùng
 * bật được, và đó là loại lỗi chỉ lộ ra khi nhìn thấy giao diện đổi màu lung tung.
 */
final class ThemeLibrary
{
    /** Khoá settings giữ id theme đang bật cho từng chế độ. */
    public const SETTING_ACTIVE = ['dark' => 'theme.active_dark', 'light' => 'theme.active_light'];

    public const SCHEMES = ['dark', 'light'];

    /** Tệp payload gốc (đúng thứ Theme Generator phát ra). */
    public const BUILTIN_FILE = 'themes/daisyui-dark.theme.json';

    public const BUILTIN_NAME = [
        'dark' => 'FabrikAI · Tối',
        'light' => 'FabrikAI · Sáng',
    ];

    /** Ghi nhớ trong một request: bảng token và theme gốc đọc từ đĩa (trang Quản trị hỏi nhiều lần). */
    private static array $memo = [];

    // ─────────────────────────────────────────────────────────────────────────
    // ĐỌC
    // ─────────────────────────────────────────────────────────────────────────

    /** Theme GỐC của sản phẩm cho một chế độ (đọc từ resources/themes, không đọc database). */
    public static function builtin(string $scheme): DaisyTheme
    {
        return self::$memo['builtin'][$scheme] ??= (function () use ($scheme): DaisyTheme {
            $path = resource_path(self::BUILTIN_FILE);
            $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            if (! is_array($payload)) {
                throw new RuntimeException('Thiếu tệp payload theme gốc: resources/'.self::BUILTIN_FILE.'.');
            }

            $base = DaisyTheme::fromArray($payload);

            if ($scheme === $base->scheme()) {
                return $base->withName(self::BUILTIN_NAME[$scheme]);
            }

            return ThemeDeriver::counterpart($base)['theme']->withName(self::BUILTIN_NAME[$scheme]);
        })();
    }

    /** Dòng theme đang bật cho một chế độ (null = đang dùng theme gốc của sản phẩm). */
    public static function active(string $scheme): ?Theme
    {
        if (! isset(self::SETTING_ACTIVE[$scheme])) {
            return null;
        }

        // Ghi nhớ trong request: đầu trang nào cũng hỏi 2–3 lần (meta theme-color · khối CSS ghi đè ·
        // bảng token) — không ghi nhớ thì mỗi lần là một query.
        if (array_key_exists('active_'.$scheme, self::$memo)) {
            return self::$memo['active_'.$scheme];
        }

        $id = setting(self::SETTING_ACTIVE[$scheme]);
        $theme = $id ? Theme::find((int) $id) : null;

        // Theme bị xoá, hoặc bị đổi chế độ ⇒ coi như chưa bật (không render một theme sai chế độ).
        return self::$memo['active_'.$scheme] = ($theme !== null && $theme->scheme === $scheme) ? $theme : null;
    }

    /** Theme đang thực sự dùng cho một chế độ, đã giải về theme gốc khi chưa bật gì. */
    public static function activeTheme(string $scheme): DaisyTheme
    {
        return self::active($scheme)?->toDaisyTheme() ?? self::builtin($scheme);
    }

    /**
     * Bảng token đang dùng của một chế độ (màu + hình học).
     *
     * @return array<string,string>
     */
    public static function tokens(string $scheme): array
    {
        return self::$memo['tokens'][$scheme] ??= ThemeRamp::full(self::activeTheme($scheme));
    }

    /**
     * Màu thanh trình duyệt (meta theme-color) của từng chế độ — NỀN TRANG của chính theme đang dùng,
     * không phải một hằng số viết trong blade (nếu không, đổi theme là thanh trình duyệt lệch màu).
     *
     * @return array<string,string>
     */
    public static function metaColors(): array
    {
        return [
            'dark' => self::tokens('dark')['--color-ink-950'] ?? '#15191e',
            'light' => self::tokens('light')['--color-ink-950'] ?? '#eef1f5',
        ];
    }

    /**
     * Khối CSS ghi đè token cho MỘT chế độ, hoặc chuỗi rỗng khi chế độ đó đang dùng theme gốc
     * (theme gốc đã nằm sẵn trong app.css — phát lại chỉ làm trang nặng thêm).
     */
    public static function cssBlock(string $scheme): string
    {
        if (self::active($scheme) === null) {
            return '';
        }

        $lines = [];
        foreach (self::tokens($scheme) as $key => $value) {
            // Chốt chặn cuối trước khi ghép vào <style>: giá trị màu phải là hex đã kiểm, giá trị
            // hình học phải khớp mẫu số + đơn vị. Không có nhánh nào để lọt chuỗi tự do vào đây.
            $safe = str_starts_with($key, '--color-')
                ? (ThemeColor::isLiteral($value) ? $value : null)
                : (preg_match('/^(0|[0-9]{1,3}(\.[0-9]+)?(px|rem|em)|[01])$/', $value) === 1 ? $value : null);

            if ($safe === null) {
                continue;
            }
            $lines[] = $key.':'.$safe.';';
        }

        // ⚠️ KHÔNG dùng nháy trong chuỗi này. Khối CSS được phát vào blade bằng cặp ngoặc nhọn thường
        // (Blade CẤM xuất thô — xem StudioXssSinksTest), mà cặp đó thì escape & " ' < >. Bộ chọn thuộc tính
        // không nháy ([data-theme=dark]) là CSS hợp lệ, và giá trị ở đây chỉ có hex + số kèm đơn vị nên
        // chuỗi đi qua escape KHÔNG bị đổi một ký tự nào.
        return '[data-theme='.$scheme.']{'.implode('', $lines).'}';
    }

    /** Toàn bộ CSS ghi đè cần phát trong <head> (rỗng khi cả hai chế độ đang dùng theme gốc). */
    public static function overrideCss(): string
    {
        $blocks = array_filter([self::cssBlock('dark'), self::cssBlock('light')]);

        return $blocks === [] ? '' : implode("\n", $blocks);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GHI
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * IMPORT một hoặc nhiều liên kết daisyUI Theme Generator.
     *
     * Mỗi link có thể sinh RA NHIỀU THEME được lưu: bản gốc, và bản đối ứng Sáng/Tối do
     * ThemeDeriver sinh (để chế độ còn lại cũng đổi theo, không nói hai thứ tiếng khác nhau).
     * Dán nhiều dòng thì nhân lên theo số liên kết.
     *
     * @return array{batch:string,saved:list<Theme>,skipped:list<string>,adjusted:array<string,list<string>>,activated:list<string>}
     * @throws RuntimeException khi nội dung dán không đọc được (thông báo nói rõ vướng ở đâu)
     */
    public static function import(string $input, ?int $userId = null): array
    {
        $decoded = DaisyThemeLink::decode($input);
        $batch = (string) Str::uuid();

        $saved = [];
        $skipped = [];
        $adjusted = [];
        $activated = [];

        DB::transaction(function () use ($decoded, $userId, $batch, &$saved, &$skipped, &$adjusted, &$activated): void {
            foreach ($decoded as $theme) {
                $counterpart = ThemeDeriver::counterpart($theme);

                foreach ([[$theme, Theme::SOURCE_IMPORT, []], [$counterpart['theme'], Theme::SOURCE_DERIVED, $counterpart['adjusted']]] as [$candidate, $source, $changes]) {
                    if (Theme::where('checksum', $candidate->checksum())->exists()) {
                        $skipped[] = $candidate->name();

                        continue;
                    }

                    $row = Theme::create([
                        'slug' => self::uniqueSlug($candidate->slug()),
                        'name' => $candidate->name(),
                        'scheme' => $candidate->scheme(),
                        'source' => $source,
                        'batch' => $batch,
                        'checksum' => $candidate->checksum(),
                        'source_link' => $source === Theme::SOURCE_IMPORT ? $candidate->sourceLink() : null,
                        'payload' => $candidate->toArray(),
                        'created_by' => $userId,
                    ]);

                    $saved[] = $row;
                    if ($changes !== []) {
                        $adjusted[$candidate->name()] = $changes;
                    }

                    // Chế độ chưa có theme nào được bật ⇒ bật luôn theme vừa import. Nếu không, người
                    // dùng vừa dán liên kết xong mà giao diện không đổi gì và tưởng tính năng hỏng.
                    if (self::active($candidate->scheme()) === null && ! in_array($candidate->scheme(), $activated, true)) {
                        self::activate($row);
                        $activated[] = $candidate->scheme();
                    }
                }
            }
        });

        self::flush();

        return ['batch' => $batch, 'saved' => $saved, 'skipped' => $skipped, 'adjusted' => $adjusted, 'activated' => $activated];
    }

    /** Bật một theme cho CHÍNH chế độ của nó (mỗi chế độ chỉ có một theme đang bật). */
    public static function activate(Theme $theme): void
    {
        set_setting(self::SETTING_ACTIVE[$theme->scheme], (string) $theme->id);
        self::flush();
    }

    /** Trả một chế độ về theme GỐC của sản phẩm. */
    public static function reset(string $scheme): void
    {
        set_setting(self::SETTING_ACTIVE[$scheme], '');
        self::flush();
    }

    /** Xoá một theme khỏi thư viện (nếu đang bật thì chế độ đó quay về theme gốc). */
    public static function remove(Theme $theme): void
    {
        DB::transaction(function () use ($theme): void {
            if (self::active($theme->scheme)?->id === $theme->id) {
                self::reset($theme->scheme);
            }
            $theme->delete();
        });

        self::flush();
    }

    /**
     * Dữ liệu cho trang Quản trị: theme GỐC (hai dòng đầu, không nằm trong database) + mọi theme đã
     * import, kèm trạng thái đang bật và bảng màu để vẽ ô xem trước.
     *
     * Vì sao theme gốc vẫn được LIỆT KÊ dù không có dòng trong database: người quản trị cần một chỗ
     * bấm để QUAY VỀ bảng màu gốc sau khi thử một theme khác. Nếu nó chỉ tồn tại ngầm thì đường về
     * duy nhất là xoá theme đang bật — một thao tác phá huỷ để làm một việc không phá huỷ gì.
     *
     * @return array{active:array<string,?Theme>,rows:list<array<string,mixed>>}
     */
    public static function overview(): array
    {
        $active = ['dark' => self::active('dark'), 'light' => self::active('light')];
        $rows = [];

        foreach (self::SCHEMES as $scheme) {
            $tokens = self::tokens($scheme);
            $rows[] = [
                'theme' => null,
                'name' => self::BUILTIN_NAME[$scheme],
                'scheme' => $scheme,
                'source' => 'builtin',
                'active' => $active[$scheme] === null,
                'tokens' => $tokens,
                'created_at' => null,
                'author' => null,
                'batch' => null,
            ];
        }

        foreach (Theme::with('author')->orderByDesc('id')->get() as $theme) {
            $rows[] = [
                'theme' => $theme,
                'name' => $theme->name,
                'scheme' => $theme->scheme,
                'source' => $theme->source,
                'active' => $active[$theme->scheme]?->id === $theme->id,
                'tokens' => $theme->tokens(),
                'created_at' => $theme->created_at,
                'author' => $theme->author?->name,
                'batch' => $theme->batch,
            ];
        }

        return ['active' => $active, 'rows' => $rows];
    }

    /** Quên ghi nhớ trong request (test gọi sau khi đổi cấu hình trong cùng tiến trình). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    private static function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 1;

        while (Theme::where('slug', $slug)->exists()) {
            $slug = substr($base, 0, 55).'-'.(++$i);
        }

        return $slug;
    }
}
