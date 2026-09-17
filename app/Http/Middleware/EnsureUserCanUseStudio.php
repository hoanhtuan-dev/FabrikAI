<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [2026-09-17 · kế hoạch Đợt 0.1 — quyết định Q1] Cổng vào nhóm API "STUDIO".
 *
 * Trước đây TOÀN BỘ /api/* nằm sau middleware `admin`, nên tài khoản tự đăng ký
 * (role `customer`) nhận 403 ở MỌI lời gọi — sản phẩm không có đường tự phục vụ.
 *
 * Middleware này mở đúng phần "xưởng" (tạo/sửa ảnh, dự án, thư viện của CHÍNH mình)
 * cho mọi tài khoản ĐANG HOẠT ĐỘNG, nhưng KHÔNG mở phần quản trị: cấu hình, API key,
 * model registry, preset dùng chung… vẫn nằm ở nhóm `admin` (routes/web.php).
 *
 * Khác biệt với EnsureUserIsAdmin:
 *   - EnsureUserIsAdmin  → chỉ super_admin + admin, thông báo "khu vực quản trị".
 *   - EnsureUserCanUseStudio → admin + customer đang hoạt động, thông báo hướng dẫn.
 */
class EnsureUserCanUseStudio
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Bạn chưa đăng nhập.');
        }

        // Tài khoản bị khoá (is_active=false) thì không vào được xưởng, dù role là gì.
        // Cột có sẵn từ migration 0001_01_01_000000 (default true, cast 'boolean' ở User::casts()).
        //
        // ⚠️ So sánh `=== false` chứ KHÔNG dùng `! $user->is_active`: giá trị NULL nghĩa là
        // attribute CHƯA ĐƯỢC NẠP (model dựng trong bộ nhớ — test/factory/seeder), KHÔNG phải
        // "tài khoản bị khoá" (default của cột là true, chỉ đọc được khi hydrate từ DB).
        // Dùng `! ...` sẽ chặn oan mọi model chưa nạp cột → đúng lớp bug "null bị coi là false".
        if ($user->is_active === false) {
            abort(403, 'Tài khoản của bạn đang bị tạm khoá. Vui lòng liên hệ quản trị viên.');
        }

        if ($user->isAdmin() || $user->role === User::ROLE_CUSTOMER) {
            return $next($request);
        }

        // Role lạ (dữ liệu cũ / gán tay): từ chối, nhưng nói rõ KHÁC thông báo quản trị.
        abort(403, 'Tài khoản của bạn chưa được cấp quyền dùng FabrikAI. Vui lòng liên hệ quản trị viên.');
    }
}
