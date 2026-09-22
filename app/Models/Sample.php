<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MỘT MẪU VẬT LÝ của một mã hàng — vòng đời do XƯỞNG làm ra (Việc #4, 2026-09-26).
 *
 * KHÁC Generation (vòng đời của ẢNH): đây là mẫu thật, có xưởng, có hạn chót, và có thể phải làm lại
 * nhiều vòng. Máy trạng thái ở đây chép ĐÚNG khuôn của Generation::SHOT_TRANSITIONS — whitelist cạnh,
 * chặn nhảy cóc, và luôn có đường quay lại (rejected → làm lại từ đầu). Một khuôn, hai đối tượng: người
 * đọc không phải học hai luật khác nhau cho hai thứ cùng là "duyệt mẫu".
 */
class Sample extends Model
{
    protected $fillable = ['project_id', 'style_no', 'name', 'factory', 'stage', 'round', 'due_at', 'note', 'sort'];

    protected $casts = [
        'due_at' => 'date',
        'round' => 'integer',
        'sort' => 'integer',
    ];

    public const STAGE_REQUESTED = 'requested';
    public const STAGE_IN_FACTORY = 'in_factory';
    public const STAGE_FIT = 'fit';
    public const STAGE_PP = 'pp';
    public const STAGE_TOP = 'top';
    public const STAGE_APPROVED = 'approved';
    public const STAGE_REJECTED = 'rejected';

    /** Mọi trạng thái hợp lệ. */
    public const STAGES = ['requested', 'in_factory', 'fit', 'pp', 'top', 'approved', 'rejected'];

    /** Đường đi "thuận" — dùng cho nút "bước kế tiếp", KHÔNG cho phép nhảy cóc. */
    public const STAGE_FLOW = ['requested', 'in_factory', 'fit', 'pp', 'top', 'approved'];

    /** Nhãn tiếng Việt — MỘT nguồn cho cả API lẫn giao diện. */
    public const STAGE_LABELS = [
        'requested' => 'Đã yêu cầu xưởng',
        'in_factory' => 'Xưởng đang làm',
        'fit' => 'Mẫu FIT',
        'pp' => 'Mẫu PP',
        'top' => 'Mẫu TOP',
        'approved' => 'Đạt',
        'rejected' => 'Không đạt',
    ];

    /**
     * Bản đồ chuyển trạng thái HỢP LỆ (whitelist cạnh) — chặn "nhảy cóc" requested → approved.
     *
     * Mọi trạng thái đều có đường LÙI và mọi trạng thái chưa đạt đều tới được 'rejected': làm mẫu là quá
     * trình thử-sai, một máy trạng thái chỉ cho tiến là máy trạng thái sai với thực tế.
     */
    public const TRANSITIONS = [
        'requested' => ['in_factory', 'rejected'],
        'in_factory' => ['fit', 'requested', 'rejected'],
        'fit' => ['pp', 'in_factory', 'rejected'],
        'pp' => ['top', 'fit', 'rejected'],
        'top' => ['approved', 'pp', 'rejected'],
        'approved' => ['top', 'rejected'],
        'rejected' => ['requested'],
    ];

    /** Bước kế tiếp trên đường thuận (null nếu đã ở bước cuối hoặc đang ở nhánh loại). */
    public static function nextStage(?string $from): ?string
    {
        $from = $from ?: self::STAGE_REQUESTED;
        $i = array_search($from, self::STAGE_FLOW, true);

        if ($i !== false) {
            return self::STAGE_FLOW[$i + 1] ?? null;
        }

        // Ngoài đường thuận (vd 'rejected'): bước kế tiếp là bước ĐẦU TIÊN mà whitelist cho phép —
        // lấy từ chính TRANSITIONS để không sinh ra luật thứ hai.
        return (self::TRANSITIONS[$from] ?? [])[0] ?? null;
    }

    /**
     * Chuyển trạng thái theo whitelist. Ném InvalidArgumentException khi bước chuyển không hợp lệ —
     * controller bắt và trả 422 (giống hệt Generation::transitionShotState).
     */
    public function transitionStage(string $to, ?string $note = null): bool
    {
        if (! in_array($to, self::STAGES, true)) {
            throw new \InvalidArgumentException('Trạng thái mẫu không hợp lệ: '.$to);
        }

        $from = $this->stage ?: self::STAGE_REQUESTED;
        $allowed = self::TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Không thể chuyển mẫu từ '".$from."' sang '".$to."' (chỉ cho phép: ".implode(', ', $allowed).').'
            );
        }

        $this->stage = $to;
        if ($note !== null) {
            $this->note = $note;
        }

        return $this->save();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
