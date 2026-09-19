<?php

use App\Models\Setting;
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
 * ⚠️ GHI QUA Setting::set() chứ KHÔNG qua DB::table(): Setting cache TOÀN BỘ bảng settings
 * trong khoá `settings:all` và chỉ xoá cache qua model event. Ghi thẳng bằng query builder
 * sẽ để lại cache cũ ⇒ app vẫn đọc giá trị CŨ dù DB đã đúng (đúng lỗi đã gặp khi deploy
 * lần đầu: DB có deepseek nhưng setting() vẫn trả luồng cũ).
 *
 * Không có setting (máy mới / test) ⇒ bỏ qua, default trong config đã bao gồm deepseek.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->rewrite(function (array $tokens) {
            if (in_array('deepseek', $tokens, true)) {
                return $tokens; // đã có — idempotent
            }

            $pos = array_search('gemini', $tokens, true);
            if ($pos === false) {
                $tokens[] = 'deepseek';       // không có gemini ⇒ thêm vào cuối
            } else {
                array_splice($tokens, $pos, 0, ['deepseek']); // chèn ngay TRƯỚC gemini
            }

            return $tokens;
        });
    }

    public function down(): void
    {
        $this->rewrite(fn (array $tokens) => array_values(array_diff($tokens, ['deepseek'])));
    }

    /** Đọc → biến đổi → ghi lại luồng đã lưu (không tạo setting mới nếu chưa có). */
    private function rewrite(callable $transform): void
    {
        if (! DB::getSchemaBuilder()->hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', 'studio_provider_priority')->first();
        if (! $row) {
            return; // chưa cấu hình ⇒ dùng default mới của config
        }

        $tokens = array_values(array_filter(array_map('trim', explode(',', (string) $row->value))));
        $next = $transform($tokens);

        if ($next === $tokens) {
            return;
        }

        // Setting::set() ghi qua Eloquent ⇒ model event xoá cache `settings:all`.
        Setting::set('studio_provider_priority', implode(',', $next));
        Setting::flushCache();
    }
};