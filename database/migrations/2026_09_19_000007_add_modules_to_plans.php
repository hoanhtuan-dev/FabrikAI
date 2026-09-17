<?php

use App\Support\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GÓI ĐĂNG KÝ = CÔNG TẮC CẤP PHÁT TÍNH NĂNG (2026-09-19).
 *
 * `plans.modules` liệt kê các MODULE (xem App\Support\ModuleRegistry) mà gói đó cấp cho khách. Nhờ vậy:
 *   · chủ dự án bật/tắt tính năng theo gói bằng dữ liệu, không phải sửa mã;
 *   · thêm module mới ⇒ nối vào gói theo `'plans' => [...]` trong bản khai, không phải sửa 5 nơi;
 *   · gói mới tạo sau này nhận đúng các module có `'*'` (mọi gói) — không bị "quên" module nền tảng.
 *
 * Bản ghi cũ được ĐỔI SANG dữ liệu mới theo đúng bản khai; gói mới thêm sau này tự nhận `'*'` lúc tạo
 * (AdminController::storePlan) nên không cần migration nữa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // null = "chưa cấu hình" ⇒ suy từ ModuleRegistry (giữ tương thích với gói do seeder tạo).
            $table->json('modules')->nullable()->after('features');
        });

        // Đổ ĐỦ module cho các gói đang có: hôm nay mọi gói dùng được mọi tính năng, nên deploy KHÔNG được
        // lấy mất tính năng của khách đang trả tiền. Việc siết theo gói là quyết định của chủ dự án, làm
        // sau bằng dữ liệu trong trang Quản trị (có nút áp dụng đề xuất từ ModuleRegistry).
        $all = json_encode(ModuleRegistry::allIds(), JSON_UNESCAPED_UNICODE);
        foreach (DB::table('plans')->orderBy('id')->get(['id']) as $plan) {
            DB::table('plans')->where('id', $plan->id)->update(['modules' => $all]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
