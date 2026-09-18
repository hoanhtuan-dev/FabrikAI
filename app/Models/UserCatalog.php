<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * [Yêu cầu 2026-09-20] Bản tùy chỉnh catalog của MỘT tài khoản (thay localStorage).
 *
 * Mỗi hàng = một catalog của một người; dữ liệu nằm trong cột json `data` theo mô hình 3 phần:
 *   custom : mục do user tự tạo
 *   edits  : ghi đè lên một mục mặc định (theo id)
 *   hidden : mục mặc định bị user ẩn khỏi bản của mình
 *
 * Tên catalog KHÔNG tự do: chỉ nhận ba giá trị trong NAMES (whitelist CỨNG, xem
 * UserCatalogController). Danh sách nằm ở ĐÂY chứ không ở controller vì cả đường đọc (GET) lẫn
 * đường ghi (PUT) phải chặn cùng một danh sách — tách ra hai nơi là sớm muộn lệch nhau, và lệch
 * theo hướng "ghi được nhưng đọc không ra" thì dữ liệu người dùng coi như mất.
 */
class UserCatalog extends Model
{
    /** Whitelist CỨNG — thêm tên mới phải sửa cả frontend lẫn test, KHÔNG nhận tên từ client. */
    public const NAMES = ['presets', 'stylist.types', 'stylist.questions'];

    protected $fillable = ['user_id', 'name', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    /** Chủ sở hữu bản tùy chỉnh. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Tên catalog có nằm trong whitelist? */
    public static function isValidName(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }

    /** Shape rỗng — dùng chung cho hàng chưa tồn tại và cho dữ liệu hỏng. */
    public static function emptyData(): array
    {
        return ['custom' => [], 'edits' => [], 'hidden' => []];
    }

    /**
     * Chuẩn hoá dữ liệu thô (từ DB hoặc từ request) về ĐÚNG shape 3 phần.
     *
     * Luôn trả đủ ba khoá và đúng kiểu: cột json có thể chứa dữ liệu do bản ghi cũ hoặc ghi tay để
     * lại, còn client có thể PUT thiếu khoá. Thiếu bước này thì frontend nhận null ở chỗ nó luôn
     * lặp qua mảng ⇒ trắng trang.
     *
     * @param  mixed  $data
     */
    public static function normalizeData($data): array
    {
        if (! is_array($data)) {
            return self::emptyData();
        }

        // Duyệt và lọc thay vì ép kiểu: JSON phải LUÔN ra MẢNG cho custom/hidden (PHP mã hoá
        // [1 => x] thành {"1":"x"} chứ không phải [x], và frontend gọi .map/.filter trên đó sẽ vỡ),
        // còn edits phải là map id => object.
        $custom = [];
        foreach ((array) ($data['custom'] ?? []) as $item) {
            if (is_array($item)) {
                $custom[] = $item;
            }
        }

        $edits = [];
        foreach ((array) ($data['edits'] ?? []) as $key => $patch) {
            if (is_array($patch)) {
                $edits[(string) $key] = $patch;
            }
        }

        $hidden = [];
        foreach ((array) ($data['hidden'] ?? []) as $id) {
            if (is_string($id) && $id !== '') {
                $hidden[] = $id;
            }
        }

        return ['custom' => $custom, 'edits' => $edits, 'hidden' => $hidden];
    }

    /**
     * Bản tùy chỉnh của MỘT người cho MỘT catalog, đã chuẩn hoá; chưa có thì trả shape rỗng.
     *
     * Truy vấn LUÔN lọc theo $userId truyền vào — không có biến thể "lấy theo name" nào, vì đó
     * chính là đường rò rỉ dữ liệu giữa hai tài khoản.
     */
    public static function forUser(int $userId, string $name): array
    {
        $row = static::query()
            ->where('user_id', $userId)
            ->where('name', $name)
            ->first();

        return self::normalizeData($row?->data);
    }
}
