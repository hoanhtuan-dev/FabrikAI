<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DNA thương hiệu do CHÍNH chủ shop khai (một hàng cho một tài khoản).
 *
 * Khác hẳn dữ liệu SUY RA: shop_signals là số bán thật, project/generation là dấu vết công việc —
 * còn bảng này là điều chủ shop MUỐN được hiểu như vậy. Hai nguồn này không được trộn lẫn khi hiển
 * thị (xem BrandDnaService::get trả `is_set`), vì "hệ thống đoán" và "chủ shop khai" có độ tin cậy khác nhau.
 *
 * Việc chuẩn hoá/giới hạn nằm ở BrandDnaService — model chỉ đọc/ghi thô để không có hai nơi cùng
 * quyết định hình dạng dữ liệu.
 */
class BrandDna extends Model
{
    protected $table = 'brand_dna';

    protected $fillable = ['user_id', 'data'];

    protected $casts = ['data' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Hồ sơ DNA của MỘT người (chưa có ⇒ []). Luôn lọc theo user_id — không có biến thể theo id hàng. */
    public static function rawForUser(int $userId): array
    {
        $row = static::query()->where('user_id', $userId)->first();

        return is_array($row?->data) ? $row->data : [];
    }
}
