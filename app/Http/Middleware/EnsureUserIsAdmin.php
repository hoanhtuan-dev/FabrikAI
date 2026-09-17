<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Bạn không có quyền truy cập khu vực quản trị.');
        }

        // [2026-09-17 · Đợt 0.1] Tài khoản bị khoá phải MẤT luôn quyền quản trị.
        // Trước đây nhánh này chỉ kiểm role: một admin bị đặt is_active=false vẫn vào
        // được TOÀN BỘ khu quản trị (đổi API key, xoá model…) — vô hiệu hoá tài khoản
        // là vô nghĩa. So sánh `=== false` (không dùng `! ...`) vì NULL nghĩa là
        // attribute chưa được nạp, KHÔNG phải "bị khoá" — xem EnsureUserCanUseStudio.
        if ($user->is_active === false) {
            abort(403, 'Tài khoản của bạn đang bị tạm khoá. Vui lòng liên hệ quản trị viên.');
        }

        return $next($request);
    }
}
