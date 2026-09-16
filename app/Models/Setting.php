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
     * Xoá cache settings.
     *
     * KHÔNG cần gọi tay trong đa số trường hợp: model event bên dưới tự xoá ở MỌI đường ghi
     * (create/update/save/delete/updateOrCreate…). Hàm này giữ lại cho đường ghi hàng loạt không
     * kích hoạt model event (ví dụ \Illuminate\Support\Facades\DB::table('settings')->update(...)).
     */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_ALL);
    }

    /**
     * Tự xoá cache ở MỌI đường ghi qua Eloquent — không phụ thuộc việc người viết có nhớ gọi set()
     * hay flushCache() hay không.
     *
     * [BUG THẬT đã xảy ra — vòng 21] Cache "cả bảng" được thêm ở vòng 20, nhưng chỉ set() xoá nó.
     * Seeder khi đó ghi thẳng \Illuminate\Database\Eloquent\Model::updateOrCreate() cho 40+ key ⇒
     * re-seed trên máy chủ đang chạy KHÔNG có hiệu lực cho tới khi cache hết hạn. Đã vá bằng cách
     * cho seeder đi qua set_setting(), nhưng cách đó vẫn phụ thuộc vào việc NHỚ. Model event dưới
     * đây bịt hẳn lỗ đó — cùng cách StudioModel đã làm cho registry.
     */
    protected static function booted(): void
    {
        $forget = static fn () => static::flushCache();

        static::saved($forget);
        static::deleted($forget);
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
        // Cache đã được model event ở booted() xoá; xoá thêm ở đây cho chắc (idempotent, không tốn gì).
        self::flushCache();
        // Dọn luôn khoá per-key của bản cũ (nếu cache còn sót từ trước khi nâng cấp).
        Cache::forget('setting:'.$key);
    }

    public static function allAsArray(): array
    {
        return self::map();
    }
}
