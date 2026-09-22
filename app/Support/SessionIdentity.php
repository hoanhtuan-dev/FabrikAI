<?php

namespace App\Support;

use App\Models\User;

/**
 * DANH TÍNH CỦA PHIÊN LÀM VIỆC — MỘT NGUỒN cho cả hai đường.
 *
 * [2026-09-26 · đợt 25] Trước đây hình dạng này chỉ sống trong StudioController::boot() (trả JSON),
 * còn trang hợp nhất thì không có gì. Sau khi ba khu gộp thành một trang, ba app con đều cần biết
 * "tôi là ai" — và câu trả lời phải GIỐNG NHAU ở mọi khu, kể cả ngay sau khi Quản trị vừa sửa tên
 * người dùng. Nay:
 *   · GET /api/boot        → 'user' => SessionIdentity::for($user) + phần riêng của boot (gói · nhóm · module);
 *   · studio/hub.blade.php → data-me  => SessionIdentity::for(auth()->user()) cho store dùng chung.
 * Thêm một trường danh tính = sửa DUY NHẤT file này; cả hai đường tự có.
 */
final class SessionIdentity
{
    /** @return array<string, mixed>|null */
    public static function for(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'avatar' => $user->avatar,
            // [Q4] Thành viên nhóm thấy SỐ DƯ CỦA NHÓM (bể credit của chủ nhóm) — nếu trả số dư
            // riêng của tài khoản phụ thì thanh công cụ hiện một con số không dùng được.
            'credits_balance' => studio_credit_balance($user),
            'is_admin' => $user->isAdmin(),
            'is_super_admin' => $user->isSuperAdmin(),
        ];
    }
}
