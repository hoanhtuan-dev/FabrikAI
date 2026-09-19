<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Thêm nhóm 'deepseek' vào LUỒNG ƯU TIÊN ĐÃ LƯU trong bảng settings.
 *
 * Vì sao cần migration: luồng ưu tiên đọc setting `studio_provider_priority` trước config.
 * Production đã có sẵn giá trị (vd 'custom,qwen,flux,gemini') nên đổi default trong
 * config/studio.php là KHÔNG đủ — giá trị cũ vẫn thắng. Migration này chèn 'deepseek'
 * NGAY TRƯỚC 'gemini' (yêu cầu: deepseek đứng trước gemini) và giữ nguyên thứ tự còn lại
 * mà admin đã đặt.
 *
 * Không có setting (máy mới / test) ⇒ bỏ qua, default trong config đã bao gồm deepseek.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', 'studio_provider_priority')->first();
        if (! $row) {
            return; // chưa cấu hình ⇒ dùng default mới của config
        }

        $tokens = array_values(array_filter(array_map('trim', explode(',', (string) $row->value))));
        if (in_array('deepseek', $tokens, true)) {
            return; // đã có — idempotent
        }

        $pos = array_search('gemini', $tokens, true);
        if ($pos === false) {
            $tokens[] = 'deepseek';       // không có gemini ⇒ thêm vào cuối
        } else {
            array_splice($tokens, $pos, 0, ['deepseek']); // chèn ngay TRƯỚC gemini
        }

        DB::table('settings')->where('key', 'studio_provider_priority')->update([
            'value' => implode(',', $tokens),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', 'studio_provider_priority')->first();
        if (! $row) {
            return;
        }

        $tokens = array_values(array_filter(array_map('trim', explode(',', (string) $row->value))));
        $tokens = array_values(array_diff($tokens, ['deepseek']));

        DB::table('settings')->where('key', 'studio_provider_priority')->update([
            'value' => implode(',', $tokens),
            'updated_at' => now(),
        ]);
    }
};