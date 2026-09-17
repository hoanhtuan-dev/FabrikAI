<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một dòng trong sổ cái credit. amount có dấu (dương = cộng, âm = trừ).
 */
class CreditTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_GRANT = 'grant';
    public const TYPE_SPEND = 'spend';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUST = 'adjust';
    public const TYPE_SIGNUP = 'signup';
    public const TYPE_RENEW = 'renew';
    /** Cấp credit theo CHU KỲ GÓI (khi gia hạn/đầu kỳ) — tách khỏi 'grant' (tặng tay) và 'renew' (mốc gia hạn). */
    public const TYPE_PLAN_GRANT = 'plan_grant';

    public const TYPES = [
        self::TYPE_PURCHASE,
        self::TYPE_GRANT,
        self::TYPE_SPEND,
        self::TYPE_REFUND,
        self::TYPE_ADJUST,
        self::TYPE_SIGNUP,
        self::TYPE_RENEW,
        self::TYPE_PLAN_GRANT,
    ];

    public const TYPE_LABELS = [
        self::TYPE_PURCHASE => 'Nạp gói',
        self::TYPE_GRANT => 'Tặng credit',
        self::TYPE_SPEND => 'Tiêu credit',
        self::TYPE_REFUND => 'Hoàn credit',
        self::TYPE_ADJUST => 'Điều chỉnh',
        self::TYPE_SIGNUP => 'Đăng ký',
        self::TYPE_RENEW => 'Gia hạn',
        self::TYPE_PLAN_GRANT => 'Cấp theo gói',
    ];

    protected $fillable = [
        'user_id', 'type', 'amount', 'balance_after',
        'reference_type', 'reference_id', 'admin_id', 'note',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
