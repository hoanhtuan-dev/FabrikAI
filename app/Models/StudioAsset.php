<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioAsset extends Model
{
    protected $fillable = ['type', 'name', 'path', 'sort', 'user_id'];

    /** [Yêu cầu 2026-09-17] null ⇒ tài nguyên DÙNG CHUNG (dữ liệu cũ); có id ⇒ RIÊNG của user đó. */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    /** Ai được ĐỌC/XOÁ tài nguyên này: chủ sở hữu, owner (admin), hoặc tài nguyên dùng chung. */
    public function visibleTo(?\App\Models\User $user): bool
    {
        if ($user === null || (bool) $user->isAdmin()) {
            return true;
        }

        return $this->user_id === null || (int) $this->user_id === (int) $user->id;
    }
}
