<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * YÊU CẦU NÂNG CẤP GÓI (Q2 — 2026-09-19).
 *
 * Vì sao có bảng này: hệ thống CHƯA có cổng thanh toán, nhưng nút "Nâng cấp" trong Studio lại gọi
 * POST /api/billing/subscribe ⇒ khách bấm một cái là gói trả phí được kích hoạt MIỄN PHÍ (không có
 * dấu vết ai đã trả tiền, trả bao nhiêu, bằng cách nào). Bảng này biến việc nâng cấp thành một
 * YÊU CẦU có mã theo dõi: khách chọn gói + số tháng + cách thanh toán (chuyển khoản ngân hàng /
 * VNPay khi mở / nhờ hỗ trợ), chủ dự án xác nhận tiền rồi kích hoạt trong trang Quản trị.
 *
 * Lưu SỐ TIỀN tại thời điểm gửi (amount_vnd) — giá có thể đổi, hoá đơn đã gửi thì không.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();                 // mã theo dõi: UP-2609-0007
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('months')->default(1);   // 1 · 3 · 6 · 12 (3 = theo vụ)
            $table->unsignedBigInteger('amount_vnd')->default(0); // giá × số tháng, chốt tại thời điểm gửi
            $table->string('method', 20)->default('bank_transfer');
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 32);
            $table->string('note', 1000)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('admin_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_requests');
    }
};
