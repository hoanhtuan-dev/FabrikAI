<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gói cước (subscription plan) — FabrikAI Studio.
 *
 * Giá VNĐ, hạn mức credit, đặc quyền hiển thị. Dữ liệu này được chủ (Owner) quản trị từ
 * trang Quản trị, và được đọc bởi trang Đăng ký + SPA để hiển thị gói. Việc gán gói cho
 * người dùng đi qua App\Services\PlanService (không mass-assign vào User).
 */
class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'tagline', 'price_vnd', 'unit_months', 'cycle_months', 'units',
        'credits_per_month', 'bonus_credits',
        'image_credit_cost', 'video_credit_cost', 'resolution_cap', 'features',
        'is_active', 'is_default', 'sort',
    ];

    protected $casts = [
        'features' => 'array',
        'units' => 'array',
        'unit_months' => 'integer',
        'cycle_months' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'price_vnd' => 'integer',
        'credits_per_month' => 'integer',
        'bonus_credits' => 'integer',
        'image_credit_cost' => 'integer',
        'video_credit_cost' => 'integer',
        'sort' => 'integer',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Giá hiển thị VNĐ: "199.000 ₫" hoặc "Miễn phí". */
    public function priceLabel(): string
    {
        if ((int) $this->price_vnd === 0) {
            return 'Miễn phí';
        }

        return number_format((int) $this->price_vnd, 0, ',', '.').' ₫';
    }

    public function isFree(): bool
    {
        return (int) $this->price_vnd === 0;
    }

    // ── [Q3 — 2026-09-19] GÓI THEO MÙA VỤ ────────────────────────────────────────────────
    // Chủ xưởng may mua theo VỤ (một bộ sưu tập / một đơn lớn), không mua theo tháng. Ba khái niệm:
    //   unit_months  = một đơn vị mua bằng mấy tháng (1 = gói tháng, 3 = gói vụ)
    //   cycle_months = bao lâu cấp credit một lần (gói vụ cấp MỘT LẦN cho cả vụ)
    //   units        = các mốc mua được: [1,3,6,12] tháng · [1,2,4] vụ

    /** Số tháng của MỘT đơn vị mua (tối thiểu 1). */
    public function unitMonths(): int
    {
        return max(1, (int) ($this->unit_months ?: 1));
    }

    /** Bao lâu cấp credit một lần (tối thiểu 1 tháng). */
    public function cycleMonths(): int
    {
        return max(1, (int) ($this->cycle_months ?: 1));
    }

    /** Tên đơn vị mua: "tháng" hoặc "vụ" — dùng chung cho trang giá, Studio và hoá đơn. */
    public function unitLabel(): string
    {
        return match ($this->unitMonths()) {
            1 => 'tháng',
            3 => 'vụ',
            6 => 'nửa năm',
            12 => 'năm',
            default => 'kỳ '.$this->unitMonths().' tháng',
        };
    }

    /** Các mốc mua được (số đơn vị). Gói tháng mặc định 1/3/6/12; gói vụ 1/2/4. */
    public function allowedUnits(): array
    {
        $units = is_array($this->units) ? array_values(array_filter(array_map('intval', $this->units), fn ($n) => $n > 0)) : [];

        return $units ?: [1, 3, 6, 12];
    }

    /** Tổng số tháng khi mua N đơn vị. */
    public function monthsFor(int $units): int
    {
        return max(1, $units) * $this->unitMonths();
    }

    /** Số tiền khi mua N đơn vị (chốt tại thời điểm gọi). */
    public function amountFor(int $units): int
    {
        return (int) $this->price_vnd * max(1, $units);
    }

    /** Nhãn credit kèm ĐƠN vị: "1.200 credit/tháng" hoặc "3.000 credit/vụ". */
    public function creditsLabel(): string
    {
        return number_format((int) $this->credits_per_month, 0, ',', '.').' credit/'.$this->unitLabel();
    }

    /** Nhãn giá kèm ĐƠN vị: "3.290.000 ₫/vụ". */
    public function pricePerUnitLabel(): string
    {
        return $this->isFree() ? 'Miễn phí' : $this->priceLabel().'/'.$this->unitLabel();
    }

    /** Gói theo mùa vụ (một đơn vị > 1 tháng)? Dùng để ưu tiên hiển thị cho nhóm chủ xưởng. */
    public function isSeasonal(): bool
    {
        return $this->unitMonths() > 1;
    }
}
