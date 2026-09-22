<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SẢN LƯỢNG THẬT THEO NGÀY của một bộ sưu tập (Việc #8 · 2026-09-26).
 *
 * Vì sao là bảng riêng: kế hoạch sản xuất nằm trong projects.settings.plan (JSON) và là KẾ HOẠCH — nó không
 * đổi mỗi ngày. Sản lượng thật thì đổi mỗi ngày, cần cộng theo thời gian, và cần vết ai ghi. Nhét vào JSON
 * là mọi câu hỏi kiểu "đợt này chậm mấy ngày" thành quét cả bảng.
 *
 * unique(project_id, logged_on): MỘT DÒNG MỘT NGÀY. Ghi lại cùng ngày là SỬA dòng đó, không phải cộng
 * thêm — nếu không, ghi hai lần trong ngày (sáng 50 cái, chiều sửa thành 60) sẽ thành 110 cái mà không ai
 * biết. Đây là bảng duy nhất trong repo chọn ràng buộc này, và lý do là con số ở đây CỘNG DỒN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('logged_on');
            $table->unsignedInteger('units_done')->default(0);
            // Hàng lỗi phải làm lại — cột này là chỗ ĐỐI CHIẾU với @@defect_pct@@ mà chủ xưởng tự đoán
            // trong kế hoạch (cùng vai trò với biên bản QC, nhưng ở tầng sản lượng chứ không phải lấy mẫu).
            $table->unsignedInteger('units_defect')->default(0);
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'logged_on']);
            $table->index(['project_id', 'logged_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_logs');
    }
};
