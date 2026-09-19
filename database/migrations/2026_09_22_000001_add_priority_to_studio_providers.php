<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Độ ưu tiên TRONG NỘI BỘ một nhóm provider (family). Luồng ưu tiên chỉ quyết định
 * THỨ TỰ NHÓM (qwen → custom → flux → gemini); khi nhiều custom provider cùng nằm
 * trong nhóm 'custom' thì không có gì phân biệt chúng — trước đây thứ tự rơi vào id.
 * Cột này cho admin quyết định route nào thử trước trong cùng nhóm.
 *
 * Xếp hạng cuối cùng: rank nhóm (asc) → priority provider (desc) → priority model (desc).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('studio_providers') || Schema::hasColumn('studio_providers', 'priority')) {
            return;
        }

        Schema::table('studio_providers', function (Blueprint $table) {
            // 0-100, lớn hơn = thử trước trong cùng nhóm. 5 = mặc định trung tính.
            $table->unsignedSmallInteger('priority')->default(5)->after('api_key_ref');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('studio_providers') && Schema::hasColumn('studio_providers', 'priority')) {
            Schema::table('studio_providers', function (Blueprint $table) {
                $table->dropColumn('priority');
            });
        }
    }
};