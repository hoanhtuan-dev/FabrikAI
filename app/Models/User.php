<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    protected $fillable = [
        'name', 'email', 'password', 'role', 'phone', 'avatar', 'is_active', 'credits_balance',
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

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function wishlistProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists')->withTimestamps();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    // ---------- Trillfa Studio ----------

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
}
