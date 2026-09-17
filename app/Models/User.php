<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_CUSTOMER = 'customer';

    /** Danh sách role có quyền vào khu vực quản trị. */
    public const ADMIN_ROLES = [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN];

    /**
     * CHỈ các field được phép điền qua mass-assignment.
     *
     * [BẢO MẬT] 'role', 'is_active' và 'credits_balance' CỐ Ý không nằm ở đây: chúng là field đặc
     * quyền/tiền. Nếu để trong $fillable, bất kỳ endpoint tương lai nào làm
     * $user->update($request->all()) sẽ cho phép tự nâng quyền lên admin VÀ tự cộng credit.
     * Đường ghi chính thức phải tường minh: forceFill(...)->save() (seeder + service).
     * Đây đúng là cách K.1 F4 đã siết cho Project (rút user_id/status khỏi $fillable).
     */
    protected $fillable = [
        'name', 'email', 'password', 'phone', 'avatar',
    ];

    /**
     * Mặc định ở tầng MODEL, không phụ thuộc default của cột trong DB.
     *
     * [BUG ĐÃ SỬA 2026-09-17] `AuthController::register()` có dòng `'role' => 'customer'` trong
     * `User::create([...])` — nhưng `role` KHÔNG nằm trong `$fillable` (cố ý, chống leo quyền), nên
     * dòng đó là **no-op**: nó im lặng bị bỏ qua. Đăng ký vẫn ra 'customer' **chỉ nhờ default của cột
     * DB**, tức hành vi đúng đang phụ thuộc vào schema chứ không phải vào code.
     * Nay khai báo tường minh ở đây để `User::create()` luôn có role đúng, và xoá dòng gây hiểu nhầm
     * ở controller.
     */
    protected $attributes = [
        'role' => self::ROLE_CUSTOMER,
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'plan_expires_at' => 'datetime',
        ];
    }

    /** Admin thường + Super Admin đều vào được khu vực quản trị. */
    public function isAdmin(): bool
    {
        return in_array($this->role, self::ADMIN_ROLES, true);
    }

    /** Chỉ Super Admin — có quyền quản lý tài khoản (xem/sửa/xóa/đặt lại mật khẩu). */
    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPER_ADMIN => 'Super Admin',
            self::ROLE_ADMIN => 'Admin',
            default => 'Khách hàng',
        };
    }

    // ---------- Studio ----------

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(PromptsHistory::class);
    }

    public function suggestResults(): HasMany
    {
        return $this->hasMany(SuggestResult::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Gói hiện hành (chưa hết hạn). Gói không có plan_expires_at được coi là gói vĩnh viễn
     * (gán tay bởi admin, hoặc gói miễn phí). Null = chưa gán gói hoặc gói đã hết hạn.
     */
    public function activePlan(): ?Plan
    {
        if ($this->plan_expires_at === null) {
            return $this->plan;
        }

        return $this->plan_expires_at->isFuture() ? $this->plan : null;
    }

    /** Đang trả phí (gói active khác miễn phí) hay không. */
    public function isSubscribed(): bool
    {
        $plan = $this->activePlan();

        return $plan !== null && ! $plan->isFree();
    }
}
