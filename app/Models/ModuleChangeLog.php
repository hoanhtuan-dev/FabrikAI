<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần thay đổi quyền tính năng (theo gói hoặc toàn cục) — để TRUY ĐƯỢC và HOÀN TÁC ĐƯỢC.
 *
 * VÌ SAO `affected_users` PHẢI GHI LẠI chứ không tính lại: số khách thuộc gói đổi theo thời gian.
 * Nếu tính lại khi XEM lịch sử thì "lúc đó ảnh hưởng bao nhiêu người" sẽ sai — và đó chính là câu hỏi
 * lịch sử phải trả lời đúng.
 */
class ModuleChangeLog extends Model
{
    protected $table = 'module_change_log';

    protected $fillable = [
        'plan_id', 'admin_id', 'kind', 'added', 'removed', 'affected_users', 'note',
    ];

    protected $casts = [
        'added' => 'array',
        'removed' => 'array',
        'affected_users' => 'integer',
    ];

    public const KIND_PLAN_MODULES = 'plan_modules';
    public const KIND_GLOBAL_DISABLE = 'global_disable';

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
