<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LỊCH SỬ THAY ĐỔI QUYỀN THEO GÓI / CÔNG TẮC TOÀN CỤC (2026-09-26).
     *
     * VÌ SAO CẦN: siết tính năng là việc ẢNH HƯỞNG KHÁCH ĐANG TRẢ TIỀN. Không có lịch sử thì không truy
     * được "ai tắt, lúc nào, và bao nhiêu khách bị ảnh hưởng". Đây đúng là chỗ Playground AI trả giá
     * (xoá canvas không báo ai, người dùng phẫn nộ) — xem docs/BO_CANVAS_PHUONG_AN.md §3.3c.
     *
     * Mỗi dòng = MỘT lần bấm lưu (không phải một dòng cho một module) — ghi đủ danh sách trước/sau để
     * HOÀN TÁC được bằng một bấm.
     */
    public function up(): void
    {
        Schema::create('module_change_log', function (Blueprint $table) {
            $table->id();
            // NULL = công tắc TOÀN CỤC (tắt/mở module cho mọi gói); có plan = thay đổi theo GÓI.
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            // 'plan_modules' (đổi theo gói) | 'global_disable' (công tắc toàn cục)
            $table->string('kind', 20);
            $table->json('added')->nullable();      // danh sách module MỚI được cấp / bật
            $table->json('removed')->nullable();    // danh sách module BỊ GỠ / tắt
            // Số khách bị ảnh hưởng tại THỜI ĐIỂM LƯU — ghi lại, không phải tính sau (số đổi theo thời gian).
            $table->unsignedInteger('affected_users')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_change_log');
    }
};
