<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mốc CẤP CREDIT THEO CHU KỲ GÓI.
 *
 * Vì sao cần cột này: `plans.credits_per_month` trước đây chỉ được HIỂN THỊ (BillingController
 * catalog + trang Quản trị) — không có chỗ nào cấp credit định kỳ, nên khách trả tiền gói
 * "350 credit/tháng" mà không nhận được gì sau tháng đầu. Cột này là mốc để việc cấp credit
 * trở nên IDEMPOTENT: mỗi chu kỳ chỉ cấp một lần, dù cron và request người dùng chạy song song.
 *
 * NULL = chưa từng cấp theo chu kỳ (người dùng cũ) ⇒ lần vào app kế tiếp sẽ được cấp bù cho
 * chu kỳ hiện tại, đúng một lần.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('plan_credits_granted_at')->nullable()->after('plan_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('plan_credits_granted_at');
        });
    }
};
