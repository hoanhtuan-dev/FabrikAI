<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BA CỔNG DUYỆT của một bộ sưu tập (Việc #7 · 2026-09-26).
 *
 * Vì sao là BẢNG RIÊNG chứ không phải cột trong projects.settings: quyết định duyệt phải có VẾT KIỂM TOÁN
 * (ai · khi nào · vì sao), và mỗi cổng là MỘT dòng để truy vấn được ("bộ nào còn cổng QC chưa duyệt").
 * Nhét vào JSON thì mọi câu hỏi kiểu đó thành quét toàn bảng và không có khoá ngoại nào bảo vệ.
 *
 * unique(project_id, gate): một cổng của một bộ chỉ có MỘT quyết định hiện hành — rút lại là GHI ĐÈ quyết
 * định cũ trong cùng dòng, còn lịch sử chi tiết nằm ở note + decided_at (giống cách projects.status_history
 * đang làm, không dựng thêm bảng log thứ hai khi chưa ai hỏi tới).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('gate', 32);
            // pending · approved · rejected — whitelist ở App\Models\ProjectGate
            $table->string('decision', 16)->default('pending');
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // Vân tay dữ liệu nguồn LÚC DUYỆT: dữ liệu đổi sau đó ⇒ cổng thành "cần duyệt lại".
            $table->string('fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'gate']);
            $table->index(['project_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_gates');
    }
};
