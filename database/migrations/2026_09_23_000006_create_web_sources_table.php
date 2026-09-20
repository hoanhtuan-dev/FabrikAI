<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRÌNH KẾT NỐI NGUỒN DỮ LIỆU NGOÀI (2026-09-23) — danh sách nguồn RSS/JSON/API do quản trị viên khai.
 *
 * Vì sao: model không tự ra internet được (đo thật: DeepSeek nhận `enable_search` rồi bỏ qua), mà
 * máy chủ thì RA ĐƯỢC internet. Vậy để MÁY CHỦ đi lấy dữ liệu thật rồi đưa vào lời nhắc kèm URL + thời
 * điểm — model chỉ việc đọc và dẫn nguồn.
 *
 * Bảng này là CẤU HÌNH (không phải dữ liệu người dùng): một hàng = một nguồn. Khai được trong
 * Cài đặt → Nguồn dữ liệu ngoài, không phải sửa .env rồi deploy lại.
 *
 * Trường ánh xạ (`items_path`/`*_field`) để nguồn JSON/API tự khai hình dạng dữ liệu của nó — nhờ vậy
 * thêm một API mới KHÔNG phải viết mã.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_sources', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->string('url', 500);
            // rss = XML/RSS/Atom · json = API trả JSON (ánh xạ bằng items_path + *_field)
            $table->string('kind', 10)->default('rss');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('priority')->default(5);
            // Lọc theo từ khoá (CSV, rỗng = giữ hết) — vì nguồn tin tổng hợp trả về đủ thứ.
            $table->string('keywords', 300)->nullable();
            // Vùng: rỗng = dùng cho mọi vùng; 'hcm'/'hanoi'/'danang' = ưu tiên khi radar chạy vùng đó.
            $table->string('region', 10)->nullable();
            $table->unsignedSmallInteger('max_items')->default(8);
            // Ánh xạ cho nguồn JSON (dot path). Rỗng với nguồn RSS.
            $table->string('items_path', 120)->nullable();
            $table->string('title_field', 60)->nullable();
            $table->string('link_field', 60)->nullable();
            $table->string('date_field', 60)->nullable();
            $table->string('summary_field', 60)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_sources');
    }
};
