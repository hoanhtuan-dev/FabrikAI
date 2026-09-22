<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHỈ MỤC TÌM KIẾM THIẾT KẾ CŨ (Việc #9 · 2026-09-26).
 *
 * Vì sao bảng này: trước đây "tìm thiết kế cũ" chỉ là ĐẾM + DÒ TỪ KHOÁ cứng trong 120 prompt gần nhất —
 * không tìm được "áo khoác màu be mùa trước" nếu bạn gõ "be". Bảng này giữ VEC-TƠ của từng tài liệu để tìm
 * theo NGỮ NGHĨA.
 *
 * BA QUYẾT ĐỊNH KỸ THUẬT, mỗi cái có lý do ĐO ĐƯỢC:
 *   1. VEC-TƠ LƯU DẠNG NHỊ PHÂN (float32 đóng gói), không phải JSON. JSON của 1.536 chiều là ~15 KB/dòng
 *      (chữ số thập phân); float32 là 6 KB — đọc 2.000 dòng để so là 12 MB thay vì 30 MB.
 *   2. KHÔNG có cột kiểu VEC-TOR/INDEX: máy chủ đang chạy MariaDB (không có kiểu vector, không có chỉ mục
 *      ANN). Vì vậy việc so điểm nằm ở PHP và CÓ TRẦN số dòng quét — trần đó được khai trong mã
 *      (DesignSearchService::MAX_SCAN) chứ không giấu đi.
 *   3. text_hash để CHỈ nhúng lại khi chữ đã đổi: nhúng là lời gọi ra ngoài (tốn tiền, tốn thời gian), nên
 *      chạy lại lệnh lập chỉ mục không được nhúng lại toàn bộ kho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            // generation · brief · lesson · tech_pack · sample
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('text');
            // sha1 của text: đổi chữ ⇒ đổi hash ⇒ biết dòng nào cần nhúng lại.
            $table->string('text_hash', 40);
            $table->string('provider', 40)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('dims')->default(0);
            // float32 đóng gói (pack('g*')) — xem ghi chú 1 ở trên.
            $table->binary('vector')->nullable();
            $table->timestamps();

            // MỘT tài liệu chỉ có MỘT vec-tơ: lập chỉ mục lại là GHI ĐÈ, không nhân bản.
            $table->unique(['user_id', 'source_type', 'source_id']);
            $table->index(['user_id', 'source_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_embeddings');
    }
};
