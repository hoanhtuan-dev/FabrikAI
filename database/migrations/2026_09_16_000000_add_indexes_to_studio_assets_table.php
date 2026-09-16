<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M17 (§5.1): bảng studio_assets tạo ra không có index nào — mọi truy vấn theo type/sort đều
 * quét toàn bảng. Thêm ở migration MỚI (không sửa migration cũ) để DB đã triển khai vẫn nhận được.
 *
 * Chỉ thêm index THƯỜNG, cố ý KHÔNG đặt unique trên path: nếu dữ liệu hiện có bản ghi trùng path
 * thì migration sẽ fail giữa đường. Việc chống trùng đã do tầng ứng dụng xử lý.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studio_assets', function (Blueprint $table) {
            $table->index(['type', 'sort'], 'studio_assets_type_sort_index');
        });
    }

    public function down(): void
    {
        Schema::table('studio_assets', function (Blueprint $table) {
            $table->dropIndex('studio_assets_type_sort_index');
        });
    }
};
