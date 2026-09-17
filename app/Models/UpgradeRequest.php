<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yêu cầu nâng cấp gói (Q2 — 2026-09-19).
 *
 * Trạng thái: pending (khách vừa gửi) → contacted (đã liên hệ, chờ tiền về) → activated (đã kích hoạt
 * gói) · cancelled (khách đổi ý / trùng). Chỉ Super Admin/Admin đổi được trạng thái; khách chỉ TẠO.
 */
class UpgradeRequest extends Model
{
    use HasFactory;

    public const METHOD_BANK = 'bank_transfer';
    public const METHOD_VNPAY = 'vnpay';
    public const METHOD_SUPPORT = 'support';

    public const METHODS = [self::METHOD_BANK, self::METHOD_VNPAY, self::METHOD_SUPPORT];

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_ACTIVATED = 'activated';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CONTACTED, self::STATUS_ACTIVATED, self::STATUS_CANCELLED];

    /** Số tháng cho phép: 1 tháng · 1 vụ (3) · nửa năm (6) · cả năm (12). */
    public const MONTHS = [1, 3, 6, 12];

    protected $fillable = [
        'code', 'user_id', 'plan_id', 'months', 'amount_vnd', 'method',
        'contact_name', 'contact_phone', 'note', 'status', 'handled_by', 'handled_at', 'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'months' => 'integer',
            'amount_vnd' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            self::METHOD_BANK => 'Chuyển khoản ngân hàng',
            self::METHOD_VNPAY => 'VNPay (chờ mở)',
            default => 'Nhờ FabrikAI hỗ trợ',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CONTACTED => 'Đã liên hệ',
            self::STATUS_ACTIVATED => 'Đã kích hoạt',
            self::STATUS_CANCELLED => 'Đã huỷ',
            default => 'Chờ xử lý',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONTACTED], true);
    }

    public function amountLabel(): string
    {
        return number_format($this->amount_vnd, 0, ',', '.').' ₫';
    }

    /**
     * Mã theo dõi đọc được qua điện thoại: UP-<yy><mm>-<4 số tăng dần trong tháng>.
     * Sinh trong transaction của người gọi; duy nhất nhờ unique index (đụng thì gọi lại).
     */
    public static function nextCode(): string
    {
        $prefix = 'UP-'.now()->format('ym').'-';
        $last = static::where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');
        $n = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
