<?php

namespace App\Policies;

use App\Models\User;

/**
 * Phân quyền quản lý tài khoản — chuẩn Laravel Policy.
 *
 * Chỉ Super Admin mới có quyền xem / tạo / sửa / xóa / đặt lại mật khẩu
 * của các tài khoản khác. Admin thường vào được khu vực quản trị nhưng
 * không được quyền đụng tới quản lý người dùng.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    /**
     * Super Admin có thể sửa tài khoản khác, và cũng có thể tự sửa
     * thông tin cá nhân của chính mình (tên/email/SĐT). Controller sẽ
     * ngăn không cho tự đổi role / tự khóa / tự đặt lại mật khẩu.
     */
    public function update(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin();
    }

    /**
     * Không được tự xóa chính mình (tránh mất quyền quản trị cuối cùng).
     */
    public function delete(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() && $actor->id !== $target->id;
    }

    /**
     * Không được tự đặt lại mật khẩu của chính mình qua đây —
     * đổi mật khẩu cá nhân phải qua trang Tài khoản (cần mật khẩu hiện tại).
     */
    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() && $actor->id !== $target->id;
    }
}
