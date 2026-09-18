<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GN ẢNH TẢI LÊN VÀO BỘ SU TẬP (dự án).
 *
 * Vì sao cần bảng riêng: ảnh tải lên KHÔNG có dòng nào trong CSDL — chúng là FILE trên đĩa
 * (studio/ref/u<id>/…, studio/assets/…) được liệt kê bằng glob() trong
 * StudioLibraryService::uploadedFiles(). Vì vậy không có chỗ nào để lưu project_id.
 *
 * KHÔNG dùng bảng studio_assets: đó là kho TÀI NGUYÊN dùng chung cho picker «Nguồn ảnh»
 * (model/pose/trang phục). Nhét ảnh tải lên vào đó sẽ làm chúng xuất hiện nhầm trong picker đó.
 *
 * rel là đường dẫn TƯƠNG ĐI trong disk public — đúng khoá mà giao diện Thư viện đang dùng.
 * Mọi thao tác ghi đều phải đi qua normalizeUploadRel() (chặn leo thư mục) và
 * studio_upload_visible_to() (chặn gắn ảnh của người khác) trước khi chạm bảng này.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_project_links', function (Blueprint $table) {
            $table->id();
            // Xoá tài khoản ⇒ xoá liên kết của tài khoản đó (không để lại rác mồ côi).
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rel', 255);
            // Xoá dự án ⇒ ảnh tự rời khỏi bộ sưu tập, KHÔNG bị xoá khỏi đĩa.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Một ảnh chỉ thuộc MỘT bộ sưu tập tại một thời điểm.
            $table->unique(['user_id', 'rel']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_project_links');
    }
};
