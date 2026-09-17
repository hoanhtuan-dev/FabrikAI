<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SỐ GHẾ THEO GÓI — NHÓM LÀM VIỆC (Q4 — 2026-09-19).
 *
 * Vì sao: chủ doanh nghiệp/studio không làm một mình. Trước đây mỗi tài khoản là một người dùng riêng:
 * muốn 3 người cùng làm một bộ sưu tập thì phải mua 3 gói, mỗi người một bể credit riêng, và bộ sưu tập
 * của người này không hiện với người kia — đúng thứ làm khách doanh nghiệp bỏ đi.
 *
 * Cách làm: `plans.seats` = số NGƯỜI tối đa dùng chung một gói; `users.team_owner_id` = thành viên
 * thuộc nhóm của ai. Thành viên:
 *   · dùng GÓI + CREDIT + hạn mức của chủ nhóm (không có bể credit riêng để lệch số),
 *   · thấy và làm việc trên BỘ SƯU TẬP của chủ nhóm (ảnh vẫn ghi rõ ai tạo),
 *   · KHÔNG được mua gói, không đổi gói, không xoá bộ sưu tập — đó là việc của chủ nhóm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Số ghế mặc định 1 = "một người dùng" — giữ nguyên hành vi cho mọi gói hiện có.
            $table->unsignedSmallInteger('seats')->default(1)->after('resolution_cap');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('team_owner_id')->nullable()->after('plan_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['team_owner_id', 'is_active']);
        });

        // Số ghế theo đúng định vị từng gói (chỉ đặt khi gói còn đang để mặc định 1 ⇒ không ghi đè
        // con số chủ dự án đã chỉnh trong Quản trị).
        $seats = [
            'free' => 1,
            'starter' => 1,
            'pro' => 3,
            'studio' => 10,
            'factory_season' => 5,
        ];
        foreach ($seats as $slug => $n) {
            DB::table('plans')->where('slug', $slug)->where('seats', 1)->update(['seats' => $n]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_owner_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('seats');
        });
    }
};
