<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SỔ NGUỒN ĐÃ TÌM ĐƯỢC — mắt xích biến "tra xong rồi quên" thành VÒNG KHÉP KÍN (2026-09-26).
 *
 * Vì sao cần bảng này: công cụ \`web_search\` đã chạy thật, nhưng kết quả của nó chỉ sống trong ĐÚNG một
 * lời gọi model — nằm trong prompt, rồi biến mất. Ba hệ quả đo được:
 *   · lượt radar sau hỏi ĐÚNG câu đó lại phải đi mạng lại từ đầu (mỗi lời gọi là tiền + thời gian chờ);
 *   · màn hình Agent Studio không có gì để hiển thị "AI đã tra được nguồn nào" — chỉ có con số đếm;
 *   · người dùng không có chỗ nào để GIỮ một nguồn hay, nên gu của họ không quay lại nuôi lượt chạy sau.
 *
 * Bảng này khép vòng đó: tìm → LƯU → lần chạy sau DÙNG LẠI (kể cả khi mạng hỏng) → hiện trên Agent Studio
 * → người dùng LƯU nguồn → nguồn đã lưu được ưu tiên cho mọi công cụ cần dữ kiện.
 *
 * Vì sao có \`user_id\` (khác \`web_sources\`/\`market_signals\` là dữ liệu toàn cục): TỪ KHOÁ người dùng hỏi
 * là dữ liệu riêng — "đối thủ X bán giá nào", "xưởng gia công ở Tân Bình"… Dùng chung giữa các tài khoản
 * là rò rỉ chiến lược kinh doanh của người này sang người kia. Thà mất phần dùng chung, giữ đúng ranh giới.
 *
 * Vì sao có \`url_hash\`: URL dài tới 500 ký tự (utf8mb4 = 4 byte/ký tự) vượt giới hạn khoá của InnoDB, mà
 * khoá duy nhất phải là (tài khoản · URL) để lần gặp lại là CẬP NHẬT chứ không đẻ thêm hàng trùng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Từ khoá NGUYÊN VĂN của model (để hiển thị "tra vì câu gì") và bản CHUẨN HOÁ (để dùng lại).
            $table->string('query', 160);
            $table->string('query_key', 160);
            $table->string('region', 10)->default('all');
            $table->string('url', 500);
            $table->char('url_hash', 32);
            $table->string('title', 200)->default('');
            $table->string('source_name', 120)->default('');
            $table->string('snippet', 400)->default('');
            $table->timestamp('published_at')->nullable();
            // Số lần nguồn này được tìm thấy — nguồn lặp lại nhiều lần là nguồn đáng tin cho lĩnh vực này.
            $table->unsignedSmallInteger('hits')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            // Mốc NGƯỜI DÙNG bấm "Lưu nguồn" — khác hẳn \`last_seen_at\` (máy tự thấy). Chỉ người dùng mới đặt được.
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            // Đường dùng lại chính: "câu này đã tra chưa, trong N ngày, cho vùng này".
            $table->unique(['user_id', 'url_hash']);
            $table->index(['user_id', 'query_key', 'region', 'last_seen_at']);
            $table->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_findings');
    }
};
