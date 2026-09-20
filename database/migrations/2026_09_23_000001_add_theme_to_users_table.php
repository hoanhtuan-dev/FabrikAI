<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TÙY CHỌN GIAO DIỆN THEO TÀI KHOẢN (2026-09-23).
 *
 * Vì sao ở DB chứ không chỉ localStorage: đây là CÙNG loại quyết định như bản tùy chỉnh
 * preset (xem docs/DESIGN_SYSTEM.md §15.1) — người dùng làm việc trên nhiều máy, và theme
 * là thứ họ đã chọn một lần rồi mong nó theo mình. Ở localStorage thì mở máy khác lại phải
 * chọn lại (và bản localStorage vẫn được giữ làm CACHE để vẽ đúng ngay từ khung hình đầu).
 *
 * Giá trị hợp lệ nằm ở whitelist phía PHP/JS: light · dark · system (system = theo hệ điều
 * hành, phía server không biết được nên render 'dark' rồi để script trong
 * resources/views/partials/theme.blade.php sửa lại trước khi vẽ).
 *
 * nullable: NULL = "chưa từng chọn" ⇒ dùng mặc định của sản phẩm (dark), KHÁC với 'dark'
 * (người dùng đã chủ động chọn tối). Nhờ vậy sau này đổi mặc định sản phẩm vẫn áp được cho
 * người chưa từng chọn, mà không ghi đè lên lựa chọn của người đã chọn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
