<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MỘT LẦN ĐO tín hiệu thị trường từ nguồn ngoài (RSS/JSON) — xem MarketSignalService.
 *
 * Vì sao là ẢNH CHỤP (snapshot) chứ không phải "bảng từ khoá cập nhật tại chỗ": xu hướng chỉ hiện ra khi
 * có LỊCH SỬ. Giữ nhiều lần đo thì mới trả lời được "từ khoá này đang lên hay đang chậm lại"; cập nhật
 * tại chỗ thì luôn chỉ có một con số hiện tại — không thể nói tăng hay giảm.
 *
 * Dữ liệu TOÀN CỤC (tin thị trường không thuộc tài khoản nào) nên không có cột user_id.
 */
class MarketSignal extends Model
{
    protected $table = 'market_signals';

    protected $fillable = [
        'region', 'window_days', 'captured_at', 'item_count', 'source_count',
        'signals', 'prices', 'fingerprint',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'window_days' => 'integer',
        'item_count' => 'integer',
        'source_count' => 'integer',
        'signals' => 'array',
        'prices' => 'array',
    ];
}
