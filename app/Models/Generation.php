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
        // [Đợt 1.1] vòng đời shot — xem migration 2026_09_17_000002_add_shot_lifecycle_to_generations
        'shot_state', 'is_selected', 'note', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_demo' => 'boolean',
            'is_selected' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public const STATUS_CANCELLED = 'cancelled';

    // ── [Đợt 1.1] Vòng đời SHOT (khác hẳn `status` — vòng đời RENDER) ───────────
    // idea → drafted → selected → fitted → campaign_ready → approved, cộng rejected ở mọi nhánh.
    public const SHOT_STATES = ['idea', 'drafted', 'selected', 'fitted', 'campaign_ready', 'approved', 'rejected'];

    /** Bản đồ chuyển trạng thái HỢP LỆ (whitelist cạnh) — chặn "nhảy cóc" idea→approved. */
    public const SHOT_TRANSITIONS = [
        'idea' => ['drafted'],
        'drafted' => ['selected', 'rejected', 'idea'],
        'selected' => ['fitted', 'drafted', 'rejected'],
        'fitted' => ['campaign_ready', 'selected', 'rejected'],
        'campaign_ready' => ['approved', 'fitted', 'rejected'],
        'approved' => ['campaign_ready', 'rejected'],
        'rejected' => ['drafted'],
    ];

    /**
     * [Đợt 1.1] Chuyển shot sang trạng thái mới theo whitelist.
     *
     * Ném InvalidArgumentException khi trạng thái không tồn tại hoặc bước chuyển KHÔNG hợp lệ
     * (vd idea→approved) — controller bắt và trả 422. Quyền (chỉ owner/admin) do controller kiểm.
     */
    public function transitionShotState(string $to, ?string $note = null): bool
    {
        if (! in_array($to, self::SHOT_STATES, true)) {
            throw new \InvalidArgumentException('Trạng thái shot không hợp lệ: '.$to);
        }

        $from = $this->shot_state ?: 'drafted';
        $allowed = self::SHOT_TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Không thể chuyển shot từ '".$from."' sang '".$to."' (chỉ cho phép: ".implode(', ', $allowed).').'
            );
        }

        $this->shot_state = $to;
        if ($note !== null) {
            $this->note = $note;
        }

        return $this->save();
    }

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
