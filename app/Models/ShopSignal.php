<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng dữ liệu BÁN HÀNG THẬT của shop (nhập tay hoặc dán từ Excel/POS).
 *
 * Đây là tầng "số liệu thật" của Agent Studio: TrendRadar đọc nó để biết shop đang bán gì chạy,
 * CollectionBot dùng nó để phân bổ SKU và neo dải giá vào giá bán THẬT thay vì phỏng đoán.
 */
class ShopSignal extends Model
{
    protected $table = 'shop_signals';

    protected $fillable = [
        'user_id', 'name', 'category', 'units_sold', 'stock_on_hand',
        'returns', 'price_vnd', 'period_days', 'source', 'note',
    ];

    protected $casts = [
        'units_sold' => 'integer',
        'stock_on_hand' => 'integer',
        'returns' => 'integer',
        'price_vnd' => 'integer',
        'period_days' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
