<?php

namespace App\Http\Middleware;

use App\Support\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THỰC THI CÔNG TẮC MODULE Ở BACKEND (2026-09-19).
 *
 * Vì sao cần: "ẩn nút trên giao diện" KHÔNG phải phân quyền — khách gói thấp vẫn gọi thẳng API được. Middleware
 * này biến bản khai `ModuleRegistry` thành luật thật:
 *
 *   · lấy URI của request, tra xem module nào phục vụ nó (khớp tiền tố DÀI NHẤT),
 *   · nếu không module nào khai ⇒ cho qua (an toàn ngược: endpoint mới không bị chặn oan trước khi khai),
 *   · nếu có ⇒ chỉ cần MỘT module trong số đó được cấp là qua (vd `refgen` phục vụ cả "Tạo biến thể"
 *     và "Mặc thử đồ": gói có một trong hai thì vẫn dùng được),
 *   · bị khoá ⇒ 403 kèm `code: module_locked`, tên module, gói nào đang cấp nó, và lý do (gói / bị tắt
 *     toàn cục / thiếu module phụ thuộc) để giao diện nói đúng thay vì "lỗi 403" chung chung.
 *
 * Nhờ tra theo BẢN KHAI, thêm module mới chỉ cần khai `endpoints` — không phải sửa route hay middleware.
 */
class EnforceModules
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        if (! str_starts_with($path, 'api/')) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            // Chưa đăng nhập: việc xác thực do middleware auth lo, không phải việc ở đây.
            return $next($request);
        }

        $modules = ModuleRegistry::modulesForUri(substr($path, 4));
        if (! $modules || $modules === []) {
            return $next($request);
        }

        foreach ($modules as $id) {
            if (module_allowed($user, $id)) {
                return $next($request);
            }
        }

        $primary = $modules[0];
        $status = modules_status($user);
        $row = collect($status)->firstWhere('id', $primary) ?: [];

        return response()->json([
            'code' => 'module_locked',
            'message' => 'Tính năng «'.ModuleRegistry::name($primary).'» không có trong gói của bạn'
                .($row['reason'] === 'disabled' ? ' (tính năng đang tạm tắt).' : '. Nâng cấp gói để dùng tính năng này.'),
            'module' => $primary,
            'module_name' => ModuleRegistry::name($primary),
            'reason' => $row['reason'] ?? 'plan',
            'plans_with_module' => self::plansWithModule($primary),
            'upgrade_url' => '/bang-gia',
        ], 403);
    }

    /** Gói nào đang cấp module này (để giao diện gợi ý đúng gói cần nâng lên). */
    protected static function plansWithModule(string $id): array
    {
        return \App\Models\Plan::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->filter(fn ($p) => $p->grantsModule($id))
            ->map(fn ($p) => ['slug' => $p->slug, 'name' => $p->name, 'price_label' => $p->priceLabel()])
            ->values()
            ->all();
    }
}
