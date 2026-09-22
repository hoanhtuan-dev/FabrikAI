<?php

namespace App\Services;

use App\Models\ProductionLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * THEO DÕI TIẾN ĐỘ SẢN XUẤT — THỰC TẾ so với KẾ HOẠCH (Việc #8 · 2026-09-26).
 *
 * VÌ SAO CẦN: kế hoạch sản xuất đã có từ lâu (lệnh cắt · số ngày · năng lực xưởng) nhưng nó là KẾ HOẠCH —
 * một tờ giấy đúng ở thời điểm lập. Sau khi lên kế hoạch, thứ duy nhất còn thiếu là SỐ THẬT mỗi ngày, và
 * từ đó là câu trả lời cho câu hỏi duy nhất chủ xưởng cần: "có kịp không, và nếu không thì lệch bao nhiêu?".
 *
 * BA NGUYÊN TẮC:
 *   1. MỌI CON SỐ Ở ĐÂY LÀ SỐ TÍNH, không phải số lưu: tiến độ · nhịp · ngày dự kiến xong được suy ra từ
 *      (kế hoạch + sản lượng đã ghi + hôm nay). Lưu sẵn là để số cũ nằm lại trong DB sau khi dữ liệu đổi.
 *   2. NÓI ĐƯỢC VÌ SAO CHẬM: mỗi cảnh báo kèm CON SỐ đã tạo ra nó (chậm 3 ngày · thiếu 240 cái · nhịp cần
 *      8 cái/ngày mà đang làm 5). Cảnh báo không có số là cảnh báo sẽ bị bỏ qua.
 *   3. KHÔNG CÓ KẾ HOẠCH THÌ NÓI THẲNG là chưa so được — không bịa một tỉ lệ phần trăm từ số 0.
 *
 * @@defect_pct@@ trong kế hoạch là GIẢ ĐỊNH của chủ xưởng; units_defect ở đây là SỐ THẬT đầu tiên đối chiếu
 * được với giả định đó ở tầng sản lượng (biên bản QC đối chiếu ở tầng lấy mẫu).
 */
class ProductionTrackingService
{
    /** Trần số dòng sản lượng của một bộ — 3 năm sản xuất mỗi ngày là quá đủ, quá đó là ghi sai. */
    public const MAX_LOGS = 1000;

    public const NOTE_MAX = 300;

    /** Trần sản lượng một ngày so với kế hoạch (hệ số). Vượt là ghi nhầm (thêm số 0), không phải năng suất. */
    public const DAILY_CAP_FACTOR = 20;

    /** Chậm từ bao nhiêu ngày thì coi là lệch tiến độ (dưới ngưỡng này là dao động bình thường). */
    public const BEHIND_DAYS = 1;

    /** Không ghi sản lượng bao nhiêu ngày thì nhắc (theo lịch, không phải theo cảm giác). */
    public const STALE_LOG_DAYS = 3;

    /** Trạng thái tiến độ — đọc được bằng mắt, không phải suy từ số. */
    public const STATUS_NO_PLAN = 'no_plan';

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_ON_TRACK = 'on_track';

    public const STATUS_BEHIND = 'behind';

    public const STATUS_DONE = 'done';

    public const STATUS_LABELS = [
        self::STATUS_NO_PLAN => 'Chưa có kế hoạch',
        self::STATUS_NOT_STARTED => 'Chưa ghi sản lượng',
        self::STATUS_ON_TRACK => 'Đúng tiến độ',
        self::STATUS_BEHIND => 'Chậm tiến độ',
        self::STATUS_DONE => 'Đã xong kế hoạch',
    ];

    /**
     * Toàn cảnh tiến độ của MỘT bộ sưu tập + cảnh báo tính từ dữ liệu.
     *
     * @return array<string, mixed>
     */
    public function overview(Project $project, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $logs = ProductionLog::query()
            ->where('project_id', $project->id)
            ->orderByDesc('logged_on')
            ->limit(self::MAX_LOGS)
            ->get();

        $settings = $project->settingsArray();
        $plan = is_array($settings['plan'] ?? null) ? $settings['plan'] : null;
        $assumptions = (array) (($plan['assumptions'] ?? []));
        $plannedUnits = (int) (($plan['totals']['units'] ?? 0));
        $daysPlanned = (int) (($plan['totals']['days_total'] ?? 0));
        $assumedDefectPct = isset($assumptions['defect_pct']) ? (float) $assumptions['defect_pct'] : null;

        $done = (int) $logs->sum('units_done');
        $defects = (int) $logs->sum('units_defect');
        $firstLog = $logs->min('logged_on');
        $lastLog = $logs->max('logged_on');

        // Số NGÀY ĐÃ SẢN XUẤT = số ngày có ghi sản lượng, không phải số ngày trên lịch: xưởng nghỉ lễ thì
        // nhịp không được tính là tụt, và ngược lại nghỉ giữa đợt vẫn phải thấy là chậm (xem alerts).
        $daysLogged = $logs->count();
        $todayRow = $logs->first(fn (ProductionLog $row) => $row->logged_on?->toDateString() === $today->toDateString());
        $pace = $daysLogged > 0 ? $done / $daysLogged : 0.0;

        $deadline = $project->deadline?->copy()->startOfDay();
        $daysLeft = $deadline !== null ? (int) $today->diffInDays($deadline, false) : null;
        $remaining = max(0, $plannedUnits - $done);
        $requiredRate = ($deadline !== null && $daysLeft !== null && $daysLeft > 0 && $remaining > 0)
            ? $remaining / $daysLeft
            : null;

        // Ngày dự kiến xong = hôm nay + số ngày còn lại theo NHỊP ĐANG CHẠY. Chỉ có nghĩa khi đã có nhịp và
        // còn việc; nếu không thì trả null chứ không bịa một ngày.
        $forecast = ($pace > 0 && $remaining > 0)
            ? $today->copy()->addDays((int) ceil($remaining / $pace))
            : null;

        // SỐ NGÀY CHẬM — định nghĩa thẳng, không vòng vo: theo nhịp ĐANG CHẠY thì cần bao nhiêu ngày nữa,
        // trừ đi số ngày còn lại tới hạn. Không có nhịp (chưa ghi ngày nào) hoặc không khai hạn ⇒ 0, và
        // màn hình nói "chưa so được" chứ không bịa một con số.
        $behindDays = 0;
        if ($daysLeft !== null && $pace > 0 && $remaining > 0) {
            $behindDays = max(0, (int) ceil($remaining / $pace - $daysLeft));
        }

        $status = $this->status($plan, $plannedUnits, $done, $behindDays);

        return [
            'items' => $logs->take(60)->map(fn (ProductionLog $row) => $this->present($row))->all(),
            'plan' => [
                'units' => $plannedUnits,
                'days_total' => $daysPlanned,
                'waves' => array_slice((array) ($plan['waves'] ?? []), 0, 6),
                'assumed_defect_pct' => $assumedDefectPct,
                'deadline' => $deadline?->toDateString(),
                'deadline_label' => $deadline?->format('d/m/Y'),
            ],
            'actual' => [
                'units_done' => $done,
                'units_defect' => $defects,
                'days_logged' => $daysLogged,
                'first_log' => $firstLog?->toDateString(),
                'last_log' => $lastLog?->toDateString(),
                'last_log_label' => $lastLog?->format('d/m/Y'),
                'units_left' => $remaining,
                // Nhịp THẬT đã đo được — làm tròn 1 chữ số vì "5,3 cái/ngày" là mức chính xác có nghĩa.
                'pace_per_day' => round($pace, 1),
                'required_per_day' => $requiredRate !== null ? round($requiredRate, 1) : null,
                'defect_pct' => $done > 0 ? round($defects / $done * 100, 2) : null,
            ],
            'progress' => [
                'pct' => $plannedUnits > 0 ? round(min(100, $done / $plannedUnits * 100), 1) : null,
                'forecast_date' => $forecast?->toDateString(),
                'forecast_label' => $forecast?->format('d/m/Y'),
                'behind_days' => $behindDays,
                'days_left' => $daysLeft,
                'status' => $status,
                'status_label' => self::STATUS_LABELS[$status],
            ],
            'alerts' => $this->alerts($today, $status, $behindDays, $lastLog, $deadline, $requiredRate, $pace, $remaining, $assumedDefectPct, $done, $defects),
            'summary' => [
                'total' => $logs->count(),
                // TRA THEO KHOÁ CHUỖI: cột logged_on được cast thành Carbon, nên firstWhere('logged_on', '2026-09-22')
                // LUÔN false (Carbon != chuỗi) — màn hình sẽ nói "hôm nay chưa ghi" ngay sau khi vừa ghi.
                'can_log_today' => $todayRow !== null || $logs->count() < self::MAX_LOGS,
                'today' => $today->toDateString(),
                'today_logged' => $todayRow !== null,
                'today_units' => (int) ($todayRow?->units_done ?? 0),
            ],
            'shape' => [
                'statuses' => array_map(fn (string $s) => ['id' => $s, 'label' => self::STATUS_LABELS[$s]], array_keys(self::STATUS_LABELS)),
                'limits' => [
                    'note_max' => self::NOTE_MAX,
                    'max_logs' => self::MAX_LOGS,
                    'behind_days' => self::BEHIND_DAYS,
                    'stale_log_days' => self::STALE_LOG_DAYS,
                    'daily_cap_factor' => self::DAILY_CAP_FACTOR,
                ],
            ],
        ];
    }

    /** Trạng thái tiến độ — hàm THUẦN để test được không cần DB. */
    public function status(?array $plan, int $planned, int $done, int $behindDays): string
    {
        if ($plan === null || $planned <= 0) {
            return self::STATUS_NO_PLAN;
        }
        if ($done <= 0) {
            return self::STATUS_NOT_STARTED;
        }
        if ($done >= $planned) {
            return self::STATUS_DONE;
        }

        return $behindDays >= self::BEHIND_DAYS ? self::STATUS_BEHIND : self::STATUS_ON_TRACK;
    }

    /**
     * CẢNH BÁO — mỗi câu kèm CON SỐ đã sinh ra nó. Tính từ dữ liệu nên tự đúng lại mỗi ngày, không có cờ
     * "đã đọc" nào phải làm mới.
     *
     * @return list<array{level: string, text: string}>
     */
    private function alerts(
        Carbon $today,
        string $status,
        int $behindDays,
        ?Carbon $lastLog,
        ?Carbon $deadline,
        ?float $requiredRate,
        float $pace,
        int $remaining,
        ?float $assumedDefectPct,
        int $done,
        int $defects,
    ): array {
        $out = [];

        if ($status === self::STATUS_NO_PLAN) {
            $out[] = ['level' => 'info', 'text' => 'Chưa có kế hoạch sản xuất trong bộ sưu tập nên chưa so được tiến độ — mở Agent Studio → Định hướng → Sản xuất & lãi rồi lưu vào bộ.'];
        }

        if ($deadline !== null && $remaining > 0 && $requiredRate !== null) {
            $gap = round($requiredRate - $pace, 1);
            if ($gap > 0) {
                $out[] = [
                    'level' => 'danger',
                    'text' => 'Còn '.number_format($remaining, 0, ',', '.').' cái trong '.max(0, (int) $today->diffInDays($deadline, false)).' ngày ⇒ cần '
                        .$this->num($requiredRate).' cái/ngày, đang làm '.$this->num($pace).' cái/ngày (thiếu '.$this->num($gap).' cái/ngày).',
                ];
            }
        }

        if ($status === self::STATUS_BEHIND && $behindDays > 0) {
            $out[] = ['level' => 'warn', 'text' => 'Theo nhịp hiện tại thì chậm khoảng '.$behindDays.' ngày so với hạn đã khai.'];
        }

        if ($lastLog !== null) {
            $idle = (int) $lastLog->diffInDays($today, false);
            if ($idle >= self::STALE_LOG_DAYS && $status !== self::STATUS_DONE) {
                $out[] = ['level' => 'warn', 'text' => 'Đã '.$idle.' ngày không ghi sản lượng (lần cuối '.$lastLog->format('d/m/Y').') — số trên màn hình đang là số cũ.'];
            }
        } elseif ($status !== self::STATUS_NO_PLAN) {
            $out[] = ['level' => 'info', 'text' => 'Chưa ghi ngày sản lượng nào — kế hoạch đã có nhưng chưa có số thật để so.'];
        }

        if ($assumedDefectPct !== null && $done > 0) {
            $actual = $defects / $done * 100;
            if ($actual > $assumedDefectPct + 0.5) {
                $out[] = [
                    'level' => 'warn',
                    'text' => 'Lỗi thật '.$this->num($actual).'% cao hơn giả định trong kế hoạch ('.$this->num($assumedDefectPct).'%) — giá vốn thực tế sẽ cao hơn con số trong kế hoạch.',
                ];
            }
        }

        return $out;
    }

    /**
     * GHI (hoặc SỬA) sản lượng của MỘT NGÀY. Một dòng một ngày — ghi lại cùng ngày là cập nhật.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function log(Project $project, array $data, User $user): array
    {
        $day = Carbon::parse((string) $data['logged_on'])->startOfDay();
        if ($day->isAfter(now()->startOfDay())) {
            throw new InvalidArgumentException('Không ghi sản lượng cho ngày CHƯA TỚI — con số đó chưa xảy ra.');
        }

        $planned = (int) (($project->settingsArray()['plan']['totals']['units'] ?? 0));
        $cap = $planned > 0 ? $planned * self::DAILY_CAP_FACTOR : 1000000;
        $done = max(0, (int) ($data['units_done'] ?? 0));
        if ($done > $cap) {
            $this->assertUnderCap($done, $cap, $planned);
        }

        $count = ProductionLog::query()->where('project_id', $project->id)->count();
        $existing = ProductionLog::query()->where('project_id', $project->id)->whereDate('logged_on', $day)->first();
        if ($existing === null && $count >= self::MAX_LOGS) {
            throw new InvalidArgumentException('Bộ sưu tập đã có '.self::MAX_LOGS.' ngày sản lượng — xoá bớt trước khi thêm.');
        }

        $row = $existing ?? new ProductionLog(['project_id' => $project->id, 'logged_on' => $day]);
        $row->units_done = $done;
        $row->units_defect = max(0, (int) ($data['units_defect'] ?? 0));
        $row->note = ($text = Str::limit(trim((string) ($data['note'] ?? '')), self::NOTE_MAX, '')) !== '' ? $text : null;
        $row->created_by = $user->id;
        $row->save();

        return $this->overview($project);
    }

    private function assertUnderCap(int $done, int $cap, int $planned): void
    {
        throw new InvalidArgumentException(
            'Một ngày ghi '.number_format($done, 0, ',', '.').' cái — gấp hơn '.self::DAILY_CAP_FACTOR.' lần cả kế hoạch ('
            .number_format($planned, 0, ',', '.').' cái). Kiểm tra lại số vừa nhập.',
        );
    }

    public function delete(ProductionLog $row): void
    {
        $row->delete();
    }

    /** Định dạng số cho CÂU chữ: phẩy thập phân, tối đa 1 chữ số — "5,3 cái/ngày" là mức chính xác có nghĩa. */
    private function num(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');

        return $text === '' ? '0' : $text;
    }

    /** Hình dạng trả ra giao diện cho MỘT ngày sản lượng. */
    private function present(ProductionLog $row): array
    {
        return [
            'id' => (int) $row->id,
            'logged_on' => $row->logged_on?->toDateString(),
            'logged_on_label' => $row->logged_on?->format('d/m/Y'),
            'units_done' => (int) $row->units_done,
            'units_defect' => (int) $row->units_defect,
            'defect_pct' => $row->units_done > 0 ? round($row->units_defect / $row->units_done * 100, 2) : null,
            'note' => (string) ($row->note ?? ''),
            'created_by' => $row->createdBy?->name,
        ];
    }
}
