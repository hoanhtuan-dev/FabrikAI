<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIÊN BẢN KIỂM TRA CHẤT LƯỢNG — QC (Việc #6 · 2026-09-26).
 *
 * VÌ SAO CÓ BẢNG NÀY: tầng giá thành ĐÃ có @@defect_pct@@ (tỉ lệ lỗi phải làm lại) — tức là hệ thống đã
 * TÍNH tiền cho lỗi, nhưng KHÔNG có chỗ nào GHI lỗi thật. Hệ quả: con số @@defect_pct@@ là giả định của
 * chủ xưởng và không bao giờ được đối chiếu với thực tế; còn việc kiểm hàng (checklist · AQL · biên bản)
 * nằm hoàn toàn ngoài ứng dụng.
 *
 * BA CỘT ĐÁNG CHÚ Ý:
 *   · @@plan@@  — kế hoạch lấy mẫu đã CHỐT cho lô này (cỡ mẫu · Ac · Re). Lưu LẠI thay vì tính lúc đọc:
 *     tiêu chuẩn có thể đổi theo hợp đồng, và một biên bản đã ký không được phép đổi số hồi tố.
 *   · @@checklist@@ — từng điểm kiểm kèm kết quả đạt/không đạt/không áp dụng. Bỏ trống cũng chạy được.
 *   · @@result@@ — KẾT LUẬN, do máy tính từ (lỗi nặng · lỗi nhẹ) so với (Ac · Re). AI không tham gia vào
 *     kết luận này: đạt hay không đạt là con số, không phải ý kiến.
 *
 * Cascade khi xoá bộ sưu tập; xoá MẪU thì biên bản vẫn giữ (set null) — biên bản QC đã lập là dấu vết,
 * không được biến mất theo thứ khác.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sample_id')->nullable()->constrained('samples')->nullOnDelete();
            $table->string('style_no', 40)->nullable();
            $table->string('stage', 20)->default('final');   // inline | final | pre_shipment
            $table->unsignedInteger('lot_size')->default(0); // số lượng của lô được kiểm
            $table->string('aql', 10)->default('2.5');
            $table->json('plan')->nullable();                // {code, sample_size, ac, re, source, note}
            $table->unsignedInteger('critical')->default(0);
            $table->unsignedInteger('major')->default(0);
            $table->unsignedInteger('minor')->default(0);
            $table->json('checklist')->nullable();
            $table->string('result', 10)->default('pending'); // pending | pass | fail
            $table->string('notes', 400)->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspections');
    }
};
