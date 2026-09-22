<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [Đợt 1.7] Một phiên bản prompt có key ổn định (vd 'garment.lock').
 * Resolver studio_prompt_template() chọn version cao nhất đang active.
 */
class PromptTemplate extends Model
{
    // 'label' = ghi chú của phiên bản (migration 2026_09_22). Trước đây thiếu ở đây nên
    // studio:prompt --label bị nuốt im lặng.
    protected $fillable = ['key', 'scope', 'version', 'body', 'label', 'is_active'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
