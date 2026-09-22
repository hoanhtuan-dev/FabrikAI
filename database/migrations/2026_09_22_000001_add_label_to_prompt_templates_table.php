<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [2026-09-22] Ghi chú cho từng phiên bản chỉ dẫn.
 *
 * Lệnh studio:prompt đã nhận --label từ trước nhưng cột KHÔNG tồn tại và 'label' không nằm trong
 * $fillable — nên nhãn bị nuốt im lặng. Giao diện quản lý chỉ dẫn cần nhãn này để owner biết
 * "vì sao tôi đổi bản này", nên thêm cột thật.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->string('label', 120)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
