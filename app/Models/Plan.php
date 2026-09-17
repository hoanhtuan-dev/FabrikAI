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
        'name', 'slug', 'tagline', 'price_vnd', 'credits_per_month', 'bonus_credits',
        'image_credit_cost', 'video_credit_cost', 'resolution_cap', 'features',
        'is_active', 'is_default', 'sort',
    ];

    protected $casts = [
        'features' => 'array',
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
}
