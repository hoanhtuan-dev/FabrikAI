<?php

namespace App\Support;

/**
 * [Single source of truth — 2026-09-17] REGISTRY ICON dùng chung cho toàn hệ thống.
 *
 * Nguồn DUY NHẤT: `resources/js/studio/icons.json` — ĐÚNG file mà `StudioIcon.vue` import để
 * render SVG. Nhờ vậy thêm một icon mới = thêm một khoá vào JSON, KHÔNG phải sửa cả PHP lẫn Vue.
 *
 * Vì sao cần lớp này (thay cho một hằng số PHP): trước đây PHP giữ danh sách icon song song với
 * Vue, hai bên lệch nhau thì owner chọn phải icon không tồn tại ⇒ nút render TRỐNG mà không có
 * lỗi nào. Nay mọi nơi hỏi cùng một nguồn.
 *
 * Đây cũng là điểm mở rộng cho tính năng QUẢN LÝ ICON TOÀN HỆ THỐNG sau này: registry đã có sẵn
 * `note` (tài liệu ngắn) và đọc từ file nên có thể thay bằng DB/API mà không phải sửa nơi gọi.
 */
class IconRegistry
{
    /** @var array<string, array{svg?: string, note?: string}>|null */
    private static ?array $cache = null;

    /** Đường dẫn tới nguồn duy nhất — cũng là file Vue import, KHÔNG có bản sao thứ hai. */
    public static function path(): string
    {
        return resource_path('js/studio/icons.json');
    }

    /** @return array<string, array{svg?: string, note?: string}> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = is_file(self::path()) ? (string) file_get_contents(self::path()) : '';
        $decoded = json_decode($raw, true);

        return self::$cache = is_array($decoded) ? $decoded : [];
    }

    /** @return array<int, string> Tên mọi icon đã đăng ký, theo đúng thứ tự trong JSON. */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /** @return array<int, string> Tên icon đã sắp xếp A→Z (cho ô chọn trong trang quản trị). */
    public static function sortedNames(): array
    {
        $names = self::names();
        sort($names);

        return $names;
    }

    public static function has(string $name): bool
    {
        return $name !== '' && array_key_exists($name, self::all());
    }

    public static function svg(string $name): string
    {
        return (string) (self::all()[$name]['svg'] ?? '');
    }

    /** @return array<int, array{name: string, note: string|null}> Danh sách kèm tài liệu ngắn. */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::sortedNames() as $name) {
            $out[] = ['name' => $name, 'note' => self::all()[$name]['note'] ?? null];
        }

        return $out;
    }

    /** Chỉ dùng trong test: xoá cache sau khi ghi lại file hoặc giả lập lỗi đọc. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
