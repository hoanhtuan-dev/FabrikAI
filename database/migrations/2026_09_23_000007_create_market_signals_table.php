<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TÍN HIỆU THỊ TRƯỜNG ĐO TỪ NGUỒN NGOÀI (2026-09-23).
 *
 * Vì sao có bảng này: model đang chạy (DeepSeek) KHÔNG có tìm kiếm web thật — đo trên production thì
 * tham số tìm kiếm bị bỏ qua. Nên toàn bộ dữ liệu thị trường phải đến từ MÁY CHỦ tự đi lấy tin (RSS/JSON).
 * Nhưng bản thân danh sách tin chỉ là CHỮ: để agent có SỐ LIỆU (từ khoá nào đang lên, bao nhiêu tin nhắc
 * tới, giá nào xuất hiện trong tin) thì phải ĐO bằng thuật toán — không cần AI, không cần model tìm kiếm.
 *
 * Một hàng = MỘT LẦN ĐO (snapshot), không phải một lần người dùng bấm:
 *   · giữ được LỊCH SỬ ⇒ mới nói được "tăng/giảm so với kỳ trước" (một lần đo đơn lẻ không có xu hướng);
 *   · đo lại cùng một dữ liệu thì KHÔNG ghi thêm hàng (khoá theo dấu vân tay của tin) ⇒ bảng không phình;
 *   · cột signals/prices là JSON vì đây là KẾT QUẢ ĐO, cấu trúc còn đổi; đổi cấu trúc không phải migrate lại.
 *
 * Bảng này là dữ liệu TOÀN CỤC (không có user_id): tin thị trường không thuộc về tài khoản nào.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_signals', function (Blueprint $table) {
            $table->id();
            // Vùng radar đã đo ('all' | 'hcm' | 'hanoi' | 'danang').
            $table->string('region', 10)->default('all');
            // Cửa sổ tin được xét (ngày) — lưu lại để đọc số cũ vẫn hiểu đúng bối cảnh.
            $table->unsignedSmallInteger('window_days')->default(60);
            $table->timestamp('captured_at');
            $table->unsignedSmallInteger('item_count')->default(0);
            $table->unsignedSmallInteger('source_count')->default(0);
            // Kết quả đo: danh sách tín hiệu và dải giá ghi nhận trong tin.
            $table->json('signals')->nullable();
            $table->json('prices')->nullable();
            // Dấu vân tay của đúng bộ tin đã đo ⇒ cùng dữ liệu thì không ghi thêm snapshot.
            $table->string('fingerprint', 32)->default('');
            $table->timestamps();

            // Truy vấn thật: "bản mới nhất của vùng này" + "lịch sử N ngày của vùng này".
            $table->index(['region', 'captured_at']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_signals');
    }
};
