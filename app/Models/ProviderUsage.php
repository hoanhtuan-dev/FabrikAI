<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SỔ CHI PHÍ — MỘT DÒNG CHO MỘT LẦN GỌI NHÀ CUNG CẤP.
 *
 * VÌ SAO THEO TỪNG LẦN GỌI, KHÔNG PHẢI TỪNG ẢNH: `config/studio.php` khai chuỗi ưu tiên
 * `qwen → custom → flux → gemini`. Một ảnh có thể thử 3 nhà cung cấp rồi mới thành công ⇒ MỘT
 * generation sinh NHIỀU lần gọi có tính tiền. Ghi giá vốn vào `generations` là ghi sai (chỉ giữ
 * được lần cuối) và làm mất luôn câu trả lời cho "chuỗi dự phòng đang ngốn bao nhiêu?".
 *
 * outcome:
 *   ok            — chạy xong, có tính tiền
 *   failed        — nhà cung cấp báo lỗi (fal KHÔNG tính tiền lỗi 5xx; DashScope cũng không)
 *   timeout       — quá hạn chờ
 *   unknown_cost  — ĐÃ GỌI nhưng chưa chắc đơn giá. Cố ý để cost = NULL thay vì đoán.
 */
class ProviderUsage extends Model
{
    protected $table = 'provider_usage';

    protected $fillable = [
        'generation_id', 'user_id', 'provider', 'model', 'attempt', 'outcome',
        'unit', 'units', 'unit_price_usd', 'cost_usd', 'fx_rate', 'cost_vnd',
        'fal_request_id', 'inference_ms',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'units' => 'float',
        'unit_price_usd' => 'float',
        'cost_usd' => 'float',
        'fx_rate' => 'float',
        'cost_vnd' => 'float',
        'inference_ms' => 'integer',
    ];

    public const OUTCOME_OK = 'ok';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_TIMEOUT = 'timeout';
    public const OUTCOME_UNKNOWN = 'unknown_cost';

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
