<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GIÁ VỐN nhà cung cấp — một đơn vị tốn bao nhiêu USD.
 *
 * `unit` là chỗ dễ sai nhất khi tích hợp, và sai ở đây thì MỌI con số lãi đều sai:
 *   · fal       → 'megapixel' (làm tròn LÊN) hoặc 'image'
 *   · DashScope → 'image'  ← tính theo ẢNH, KHÔNG theo megapixel
 *   · video     → 'second'
 *
 * KHÔNG hardcode giá trong mã: giá nhà cung cấp đổi theo thời gian. Đây là DỮ LIỆU, và khi chưa
 * chắc thì để trống dòng đó (xem ProviderCostService::record với outcome = unknown_cost) —
 * thà thiếu một dòng còn hơn ghi số sai.
 */
class ProviderPrice extends Model
{
    protected $table = 'provider_price';

    protected $fillable = ['provider', 'model', 'unit', 'unit_price_usd', 'first_unit_price_usd', 'billed_sides', 'note'];

    protected $casts = [
        'unit_price_usd' => 'float',
        'first_unit_price_usd' => 'float',
        'billed_sides' => 'integer',
    ];

    public const UNIT_IMAGE = 'image';
    public const UNIT_MEGAPIXEL = 'megapixel';
    public const UNIT_SECOND = 'second';

    /** Các đơn vị hợp lệ — dùng cho cả validate ở Quản trị và test. */
    public const UNITS = [self::UNIT_IMAGE, self::UNIT_MEGAPIXEL, self::UNIT_SECOND];
}
