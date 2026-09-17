<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'project_id', 'prompts_history_id', 'type', 'status',
        'prompt', 'model', 'provider', 'resolution', 'ratio', 'duration', 'media_url', 'base_image', 'mask_image', 'job_id', 'error', 'credits_cost', 'elapsed_ms', 'meta',
        // [Đợt 0.3] cờ DEMO — xem migration 2026_09_17_000000_add_demo_flags_to_generations
        'is_demo', 'demo_reason',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_demo' => 'boolean',
        ];
    }

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Đẩy job NGAY LÚC TẠO khi máy chủ có worker nền.
     *
     * Trước đây việc enqueue chỉ xảy ra trong StudioController::show() (lúc client poll), nên một
     * generation tạo ra mà không bị poll sẽ nằm 'pending' mãi mãi — credit đã trừ mà không có kết quả.
     * Đặt ở ĐÂY (model event) thay vì sửa từng controller vì có ~8 đường tạo generation
     * (generate · video · inpaint · region · pattern · tryon · reimagine · upscale) — sửa từng chỗ là
     * mẫu lỗi "vá một nơi, quên chỗ tương đương" đã gặp nhiều lần trong repo này.
     *
     * Chỉ chạy khi config('studio.queue_worker') = true: ở chế độ dev (không worker) hành vi cũ được
     * giữ nguyên — request poll tự xử lý inline.
     */
    protected static function booted(): void
    {
        static::created(function (self $generation) {
            if (! config('studio.queue_worker')) {
                return;
            }

            studio_dispatch_generation($generation);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function promptsHistory(): BelongsTo
    {
        return $this->belongsTo(PromptsHistory::class);
    }
}
