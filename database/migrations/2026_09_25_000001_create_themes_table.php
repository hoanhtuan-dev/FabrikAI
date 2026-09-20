<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THƯ VIỆN THEME (2026-09-25) — mỗi dòng là MỘT theme daisyUI đã import.
 *
 * Vì sao cần bảng riêng thay vì nhét vào bảng settings: mỗi lần import sinh ra NHIỀU theme (bản gốc
 * + bản đối ứng Sáng/Tối, xem App\Support\ThemeDeriver), và mỗi theme có tên, chế độ, nguồn, người
 * import, thời điểm — đó là một thực thể, không phải một khoá cấu hình.
 *
 * Lưu ý về hai cột quan trọng:
 *   · payload  — ĐÚNG payload của daisyUI Theme Generator (đã kiểm qua DaisyTheme). Không lưu bảng
 *                token đã sinh: bảng đó là hàm thuần của payload (ThemeRamp), sinh lại lúc đọc thì
 *                sửa công thức là mọi theme cũ tự cập nhật, không phải chạy migration dữ liệu.
 *   · checksum — vân tay của payload, dùng để import lại đúng liên kết đó KHÔNG sinh bản sao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 80);
            // 'light' | 'dark' — theme này dùng cho chế độ nào (daisyUI gọi là "color-scheme").
            $table->string('scheme', 8);
            // 'builtin' (theme gốc của sản phẩm) | 'import' (dán liên kết vào) | 'derived' (bản đối ứng tự sinh).
            $table->string('source', 16)->default('import');
            // Lô import: mọi theme sinh ra trong CÙNG một lần dán mang cùng một mã, để trang Quản trị
            // gom nhóm được ("lần import này đã thêm 4 theme") và để hoàn tác cả lô nếu cần.
            $table->uuid('batch')->nullable()->index();
            $table->string('checksum', 64)->index();
            $table->text('source_link')->nullable();
            $table->json('payload');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
