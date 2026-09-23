<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gói cước (subscription plan) — FabrikAI Studio.
 *
 * Giá VNĐ, hạn mức credit, đặc quyền hiển thị. Dữ liệu này được chủ (Owner) quản trị từ
 * trang Quản trị, và được đọc bởi trang Đăng ký + SPA để hiển thị gói. Việc gán gói cho
 * người dùng đi qua App\Services\PlanService (không mass-assign vào User).
 */
class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'tagline', 'price_vnd', 'unit_months', 'cycle_months', 'units',
        'credits_per_month', 'bonus_credits',
        'image_credit_cost', 'video_credit_cost', 'daily_image_limit', 'resolution_cap', 'seats', 'features', 'modules',
        'is_active', 'is_default', 'sort',
    ];

    protected $casts = [
        'features' => 'array',
        'modules' => 'array',
        'units' => 'array',
        'unit_months' => 'integer',
        'cycle_months' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'price_vnd' => 'integer',
        'credits_per_month' => 'integer',
        'bonus_credits' => 'integer',
        'image_credit_cost' => 'integer',
        'video_credit_cost' => 'integer',
        'daily_image_limit' => 'integer',
        'seats' => 'integer',
        'sort' => 'integer',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Giá hiển thị VNĐ: "199.000 ₫" hoặc "Miễn phí". */
    public function priceLabel(): string
    {
        if ((int) $this->price_vnd === 0) {
            return 'Miễn phí';
        }

        return number_format((int) $this->price_vnd, 0, ',', '.').' ₫';
    }

    public function isFree(): bool
    {
        return (int) $this->price_vnd === 0;
    }

    // ── GÓI = CÔNG TẮC CẤP PHÁT TÍNH NĂNG (ModuleRegistry — 2026-09-19) ───────────────────

    /**
     * Danh sách module gói này cấp. Chưa cấu hình (null) ⇒ suy từ ModuleRegistry theo slug gói, để gói
     * cũ và gói do seeder tạo luôn có hành vi đúng mà không cần migration mới.
     *
     * @return array<int, string>
     */
    public function modules(): array
    {
        $stored = is_array($this->modules) ? array_values(array_filter(array_map('strval', $this->modules))) : null;

        // Chưa cấu hình ⇒ ĐỦ module. Đây là quyết định có chủ đích: hệ thống đang chạy với mọi gói dùng
        // được mọi tính năng, nên mặc định phải GIỮ NGUYÊN hành vi đó; chủ dự án siết dần bằng dữ liệu
        // (Quản trị có nút áp dụng đề xuất — xem ModuleRegistry::suggestedForPlan()).
        $ids = $stored ?? \App\Support\ModuleRegistry::allIds();

        // Chỉ nhận module CÓ THẬT trong bản khai: gói lưu id lạ (đổi tên module · dữ liệu cũ) không được
        // biến thành quyền truy cập mơ hồ.
        return array_values(array_intersect($ids, \App\Support\ModuleRegistry::ids()));
    }

    /**
     * Gói MẶC ĐỊNH (do chủ dự án đặt, thường là gói Miễn phí) — dùng làm phương án dự phòng cho tài
     * khoản CHƯA được gán gói nào.
     *
     * Vì sao quan trọng: khi công tắc module bật, nếu tài khoản không có gói mà bị coi là "không có quyền
     * gì" thì mọi khách cũ (đăng ký trước khi có hệ gói) mất sạch tính năng — đúng loại lỗi làm sập sản
     * phẩm. Không có gói ⇒ dùng gói mặc định, giống hệt cách hệ thống vẫn đối xử với họ từ trước.
     */
    public static function defaultPlan(): ?self
    {
        static $cached = null;

        if ($cached === null) {
            $cached = static::query()->where('is_default', true)->first()
                ?? static::query()->where('slug', 'free')->first();
        }

        return $cached;
    }

    /** Gói này cấp module `$id`? */
    public function grantsModule(string $id): bool
    {
        return in_array($id, $this->modules(), true);
    }

    /** Số module gói cấp (hiển thị trên trang giá/Quản trị). */
    public function modulesCount(): int
    {
        return count($this->modules());
    }

    /**
     * TÊN các tính năng gói cấp — dùng để HIỂN THỊ cho khách (trang giá, popup gói, Quản trị).
     *
     * Đây là phần "gói cước đồng bộ với gói cấp tính năng nào": tên lấy từ ModuleRegistry ⇒ chủ dự án đổi
     * tên/ thêm/ bớt tính năng là phần hiển thị tự đúng, không phải viết lại danh sách ở trang giá.
     *
     * @return array<int, string>
     */
    public function moduleNames(): array
    {
        return array_values(array_map(
            fn (string $id) => \App\Support\ModuleRegistry::name($id),
            $this->modules()
        ));
    }

    /** Tên tính năng theo NHÓM (nhóm do bản khai quyết định) — để trang giá hiển thị gọn. */
    public function modulesByGroup(): array
    {
        $out = [];
        foreach ($this->modules() as $id) {
            $m = \App\Support\ModuleRegistry::get($id);

            $out[$m['group']][] = $m['name'];
        }

        return $out;
    }

    /**
     * GHI CHÚ HIỂN THỊ do chủ dự án NHẬP TAY (cột `features`).
     *
     * Cố ý KHÔNG phải công tắc: đây là câu chữ bán hàng/giải thích thêm cho khách đọc (vd "hỗ trợ ưu tiên
     * 1:1", "hoá đơn theo vụ"). Việc cấp quyền thật nằm ở `modules()` — nhập tay ở đây KHÔNG mở
     * thêm tính năng nào, và xoá hết ghi chú cũng KHÔNG lấy mất quyền của khách.
     *
     * @return array<int, string>
     */
    public function manualFeatures(): array
    {
        return array_values(array_filter(array_map(
            fn ($f) => trim((string) $f),
            is_array($this->features) ? $this->features : []
        ), fn (string $f) => $f !== ''));
    }

    // ── [Q3 — 2026-09-19] GÓI THEO MÙA VỤ ────────────────────────────────────────────────
    // Chủ xưởng may mua theo VỤ (một bộ sưu tập / một đơn lớn), không mua theo tháng. Ba khái niệm:
    //   unit_months  = một đơn vị mua bằng mấy tháng (1 = gói tháng, 3 = gói vụ)
    //   cycle_months = bao lâu cấp credit một lần (gói vụ cấp MỘT LẦN cho cả vụ)
    //   units        = các mốc mua được: [1,3,6,12] tháng · [1,2,4] vụ

    /** Số tháng của MỘT đơn vị mua (tối thiểu 1). */
    public function unitMonths(): int
    {
        return max(1, (int) ($this->unit_months ?: 1));
    }

    /** Bao lâu cấp credit một lần (tối thiểu 1 tháng). */
    public function cycleMonths(): int
    {
        return max(1, (int) ($this->cycle_months ?: 1));
    }

    /** Tên đơn vị mua: "tháng" hoặc "vụ" — dùng chung cho trang giá, Studio và hoá đơn. */
    public function unitLabel(): string
    {
        return match ($this->unitMonths()) {
            1 => 'tháng',
            3 => 'vụ',
            6 => 'nửa năm',
            12 => 'năm',
            default => 'kỳ '.$this->unitMonths().' tháng',
        };
    }

    /** Các mốc mua được (số đơn vị). Gói tháng mặc định 1/3/6/12; gói vụ 1/2/4. */
    public function allowedUnits(): array
    {
        $units = is_array($this->units) ? array_values(array_filter(array_map('intval', $this->units), fn ($n) => $n > 0)) : [];

        return $units ?: [1, 3, 6, 12];
    }

    /** Tổng số tháng khi mua N đơn vị. */
    public function monthsFor(int $units): int
    {
        return max(1, $units) * $this->unitMonths();
    }

    /** Số tiền khi mua N đơn vị (chốt tại thời điểm gọi). */
    public function amountFor(int $units): int
    {
        return (int) $this->price_vnd * max(1, $units);
    }

    /** Nhãn credit kèm ĐƠN vị: "1.200 credit/tháng" hoặc "3.000 credit/vụ". */
    public function creditsLabel(): string
    {
        return number_format((int) $this->credits_per_month, 0, ',', '.').' credit/'.$this->unitLabel();
    }

    /** Nhãn giá kèm ĐƠN vị: "3.290.000 ₫/vụ". */
    public function pricePerUnitLabel(): string
    {
        return $this->isFree() ? 'Miễn phí' : $this->priceLabel().'/'.$this->unitLabel();
    }

    /** Gói theo mùa vụ (một đơn vị > 1 tháng)? Dùng để ưu tiên hiển thị cho nhóm chủ xưởng. */
    public function isSeasonal(): bool
    {
        return $this->unitMonths() > 1;
    }

    // ── [Q4 — 2026-09-19] SỐ GHẾ ────────────────────────────────────────────────────────
    // Chủ doanh nghiệp không làm một mình: gói phải nói được "bao nhiêu NGƯỜI dùng chung gói này".

    /** Số ghế (người dùng chung một gói). Tối thiểu 1 — không có gói nào cho 0 người. */
    public function seats(): int
    {
        return max(1, (int) ($this->seats ?: 1));
    }

    /** Nhãn ghế để hiển thị: "1 người" · "3 người" · "10 người". */
    public function seatsLabel(): string
    {
        return $this->seats().' người';
    }

    /** Gói dùng chung cho nhóm (nhiều hơn 1 ghế)? */
    public function hasTeamSeats(): bool
    {
        return $this->seats() > 1;
    }
}
