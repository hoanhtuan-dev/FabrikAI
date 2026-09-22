<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VÒNG ĐỜI MẪU VẬT LÝ (sample tracking) — Việc #4, 2026-09-26.
 *
 * VÌ SAO CÓ BẢNG NÀY: Agent Studio đã có vòng đời cho ẢNH (Generation.shot_state: ý tưởng → nháp → chọn →
 * lên phom → chờ duyệt → duyệt/loại), nhưng KHÔNG có vòng đời cho MẪU THẬT mà xưởng may ra. Hệ quả: sau
 * khi chốt ảnh, toàn bộ việc theo dõi mẫu fit/PP/TOP nằm ngoài ứng dụng — không biết mẫu nào đang ở đâu,
 * mẫu nào trễ hạn, và "cảnh báo" thì không có gì để bám vào.
 *
 * MỘT HÀNG LÀ MỘT MẪU của một mã hàng trong một bộ sưu tập: mỗi vòng làm lại (fit lần 2, PP…) là một
 * hàng mới với `round` tăng — giữ được LỊCH SỬ các vòng, thứ mà "ghi đè trạng thái" làm mất.
 *
 * Cascade khi xoá bộ sưu tập: mẫu không có nghĩa gì khi bộ đã bị xoá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('style_no', 40)->nullable();   // mã hàng
            $table->string('name', 120)->nullable();      // tên mẫu
            $table->string('factory', 120)->nullable();   // xưởng / nhà cung cấp
            $table->string('stage', 20)->default('requested');
            $table->unsignedTinyInteger('round')->default(1);   // vòng mẫu thứ mấy
            $table->date('due_at')->nullable();           // hạn chót của VÒNG HIỆN TẠI
            $table->string('note', 400)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            // Đường đọc NÓNG: "mẫu CHƯA ĐẠT của một bộ, xếp theo hạn" (bảng theo dõi + lệnh nhắc hạn).
            $table->index(['project_id', 'stage', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('samples');
    }
};
