<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TRÍ NHỚ THỦ TỤC của Agent Studio: một quy tắc "khi <tình huống> thì <cách làm>" của chủ shop.
 *
 * Khác `BrandLearning` (một SỰ KIỆN đã xảy ra) và `BrandDna` (một hồ sơ SỞ THÍCH phẳng): đây là
 * QUY TRÌNH — câu điều kiện áp dụng lúc viết brief. Xem BrandRuleService cho chỗ đọc/ghi tập trung và
 * trần độ dài (model chỉ đọc/ghi thô, không tự quyết định hình dạng dữ liệu).
 */
class BrandRule extends Model
{
    protected $table = 'brand_rules';

    protected $fillable = ['user_id', 'trigger', 'action', 'weight', 'source', 'is_active', 'sort'];

    protected $casts = [
        'is_active' => 'boolean',
        'weight' => 'integer',
        'sort' => 'integer',
    ];

    /** Chủ shop tự đặt (đường biên tập trong Agent Studio). */
    public const SOURCE_OWNER = 'owner';

    /** Agent rút ra từ kinh nghiệm (đường học — chưa nối ở bản này, nhưng chừa sẵn chỗ phân biệt). */
    public const SOURCE_LEARNED = 'learned';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
