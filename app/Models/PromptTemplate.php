<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * [Đợt 1.7] Một phiên bản prompt có key ổn định (vd 'garment.lock').
 * Resolver studio_prompt_template() chọn version cao nhất đang active.
 */
class PromptTemplate extends Model
{
    protected $fillable = ['key', 'scope', 'version', 'body', 'is_active'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
