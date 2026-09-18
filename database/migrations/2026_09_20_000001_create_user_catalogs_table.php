<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [Yêu cầu 2026-09-20] CATALOG TÙY CHỈNH CẤP TÀI KHOẢN — chuyển từ localStorage lên SERVER.
 *
 * Trước đây tùy chỉnh của người dùng ở /presets · /stylist-data nằm trong localStorage
 * (resources/js/studio/composables/useLocalCatalog.js), khoá theo userId. Ba hệ quả không sửa
 * được ở phía máy khách:
 *   · đổi máy / xoá dữ liệu trình duyệt là MẤT bản tùy chỉnh (công sức người dùng trả tiền);
 *   · hai thiết bị của CÙNG một người không thấy bản của nhau;
 *   · dữ liệu nằm ngoài tầm kiểm soát của server nên không sao lưu và không kiểm toán được.
 *
 * Bảng giữ NGUYÊN mô hình 3 phần của bản cục bộ (custom / edits / hidden) trong MỘT cột json,
 * để bản cũ đọc lên là dùng được ngay, không phải ánh xạ lại dữ liệu.
 *
 * Vì sao UNIQUE(user_id, name): mỗi người chỉ có ĐÚNG MỘT bản cho mỗi catalog. Ràng buộc này
 * đặt ở tầng DB (không chỉ ở code) vì hai request PUT đồng thời có thể cùng qua vòng "chưa có
 * hàng" rồi cùng INSERT — khi đó sinh hàng trùng và lần đọc sau trả về bản CŨ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_catalogs', function (Blueprint $table) {
            $table->id();
            // Xoá tài khoản là xoá luôn bản tùy chỉnh của họ — dữ liệu này vô nghĩa khi chủ không
            // còn tồn tại; cascade giữ cho bảng không đầy hàng mồ côi.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->json('data');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_catalogs');
    }
};
