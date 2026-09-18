<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * FabrikAI Studio — Project (Design Board).
 *
 * Mỗi Project là một "bảng thiết kế" của Designer: gom generations/assets
 * thành một luồng công việc có trạng thái (Draft → In Progress → Review →
 * Approved → Archived). Thuộc tính `status` được dẫn dắt bởi
 * App\Services\ProjectWorkflowService để đảm bảo transition hợp lệ.
 */
class Project extends Model
{
    use HasFactory;
    // [R6] Soft-delete thủ công: không dùng trait SoftDeletes (gây crash với SQLite trong môi trường test).
    // deleted_at được quản lý thông qua ProjectController::destroy và các query có whereNull('deleted_at').

    /**
     * Danh sách trạng thái hợp lệ — đồng bộ với ProjectWorkflowService::STATES.
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_REVIEW = 'review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_PROGRESS,
        self::STATUS_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * `user_id` và `status` KHÔNG fillable (chống mass-assignment):
     *  - user_id chỉ được gán lúc create qua quan hệ $user->projects();
     *  - status chỉ được dẫn dắt bởi ProjectWorkflowService (forceFill) —
     *    mọi chuyển trạng thái phải đi qua transition() để bị gate kiểm duyệt.
     * Seeder dùng Project::unguarded() khi cần set cả hai.
     */
    protected $fillable = [
        'name',
        'assignee_id',
        'base_concept',
        'brief',
        'deadline',
        'thumbnail_url',
        'tags',
        'color',
        'sort',
        'archived',
        'settings',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'tags' => AsArrayObject::class,
        'settings' => AsArrayObject::class,
        'archived' => 'boolean',
        'sort' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'sort' => 0,
        'archived' => false,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * [P1.2 — 2026-09-20] Người ĐANG LÀM bộ sưu tập (khác `user` = người sở hữu/trả tiền).
     * Không gán ⇒ null, và giao diện hiểu là "chủ bộ sưu tập tự làm".
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Ai được giao việc trên bộ sưu tập này: chủ sở hữu + mọi thành viên trong nhóm của chủ.
     * Dùng CHUNG cho validate ở ProjectController (một nguồn luật, không lệch giữa store/update).
     *
     * @return array<int, int>
     */
    public function assignableUserIds(): array
    {
        $ownerId = (int) $this->user_id;

        $memberIds = User::query()
            ->where('team_owner_id', $ownerId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge([$ownerId], $memberIds)));
    }

    /** [Đợt 4 — 2026-09-19] Link chia sẻ công khai cho khách duyệt (có hạn, thu hồi được). */
    public function shares(): HasMany
    {
        return $this->hasMany(ProjectShare::class);
    }

    /** [Đợt 4] Phản hồi của người xem link chia sẻ (Duyệt / Yêu cầu sửa). */
    public function feedback(): HasMany
    {
        return $this->hasMany(ProjectFeedback::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * Generation mới nhất CÓ media_url — eager-load (`with('latestGeneration')`)
     * cho accessor thumbnail để tránh N+1 khi serialize danh sách dự án.
     *
     * Constraint whereNotNull PHẢI truyền qua closure của ofMany() để nằm TRONG
     * subquery MAX(id): chain ->whereNotNull() trước ->latestOfMany() bị Laravel
     * bỏ qua khi build subquery tươi mới → thumbnail sẽ trả NULL sai mỗi khi
     * generation mới nhất còn đang render / render fail (media_url NULL).
     */
    public function latestGeneration(): HasOne
    {
        return $this->hasOne(Generation::class, 'project_id')
            ->ofMany(['id' => 'MAX'], fn ($query) => $query->whereNotNull('media_url'));
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(PromptsHistory::class);
    }

    /**
     * Các StudioAsset được ghim vào dự án (reference images / pose / model assets).
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(StudioAsset::class, 'project_assets')->withPivot('sort', 'role')->orderByPivot('sort');
    }

    /**
     * Thumbnail dùng cho card: ưu tiên thumbnail_url (chọn tay), fallback ảnh mới nhất.
     */
    protected function thumbnail(): Attribute
    {
        return Attribute::get(function () {
            if (! empty($this->thumbnail_url)) {
                return $this->thumbnail_url;
            }
            // Ưu tiên relation đã eager-load (index) — tránh 1 query/project (N+1).
            if ($this->relationLoaded('latestGeneration')) {
                return $this->latestGeneration?->media_url;
            }
            $latest = $this->generations()->whereNotNull('media_url')->latest('id')->first();
            return $latest?->media_url;
        });
    }

    /**
     * Số lượng output trong dự án (cho badge / progress).
     */
    protected function outputCount(): Attribute
    {
        return Attribute::get(fn () => $this->generations()->count());
    }
}
