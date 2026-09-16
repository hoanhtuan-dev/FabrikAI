<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    /**
     * Cache TOÀN BỘ bảng settings dưới MỘT khoá (thay vì mỗi key một khoá như trước).
     *
     * [HIỆU NĂNG — vòng 20] Bản cũ cache theo từng key ('setting:'.$key) nên lần đọc ĐẦU của mỗi
     * key vẫn là 1 query. Đo thực tế: GET /api/defaults = 75 query, trong đó **52 query
     * `select * from settings where key = ?`** (52 key khác nhau). Nạp cả bảng 1 lần -> 52 thành 1
     * lượt đọc cache (hit), áp dụng cho MỌI request vì setting() được gọi ở khắp nơi.
     */
    public const CACHE_ALL = 'settings:all';

    /**
     * Xoá cache settings. Dùng cho đường ghi KHÔNG đi qua set() (ví dụ seeder dùng
     * updateOrCreate hàng loạt) — nếu quên, cache 'settings:all' vẫn phục vụ giá trị cũ sau khi ghi.
     */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_ALL);
    }

    /** @return array<string, mixed> */
    private static function map(): array
    {
        return Cache::rememberForever(self::CACHE_ALL, function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    public static function get(string $key, $default = null)
    {
        $all = self::map();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_ALL);
        // Dọn luôn khoá per-key của bản cũ (nếu cache còn sót từ trước khi nâng cấp).
        Cache::forget('setting:'.$key);
    }

    public static function allAsArray(): array
    {
        return self::map();
    }
}
