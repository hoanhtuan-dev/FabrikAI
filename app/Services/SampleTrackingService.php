<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Sample;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * THEO DÕI MẪU VẬT LÝ — Việc #4 (2026-09-26).
 *
 * BA QUY TẮC:
 *   1. TRẠNG THÁI ĐI QUA WHITELIST của model, không có đường tắt: mọi lượt chuyển đều ném khi bước
 *      chuyển không hợp lệ, controller bắt và trả 422 kèm lý do (không "thử rồi tính").
 *   2. CẢNH BÁO TÍNH TỪ DỮ LIỆU, không lưu cờ: "quá hạn" là so `due_at` với HÔM NAY, nên nó tự đúng
 *      lại mỗi ngày mà không cần job nào chạy. Cờ lưu sẵn sẽ sai ngay hôm sau và không ai biết.
 *   3. KHÔNG CHẶN NGƯỜI DÙNG: thiếu mã hàng hay thiếu hạn chót vẫn tạo được mẫu — chủ xưởng thường biết
 *      mẫu đang làm TRƯỚC khi biết ngày xong. Hệ thống chỉ NÓI RA khi hàng thiếu thông tin quan trọng.
 */
class SampleTrackingService
{
    /** Sắp tới hạn trong bao nhiêu ngày thì bắt đầu nhắc. */
    public const DUE_SOON_DAYS = 3;

    /** Trần số mẫu mỗi bộ — bảng theo dõi dài hơn thì không ai đọc, và mỗi vòng mẫu là một hàng. */
    public const MAX_SAMPLES = 200;

    /** Trần ký tự từng trường — MỘT nguồn cho controller và giao diện. */
    public const TEXT_LIMITS = [
        'style_no' => 40,
        'name' => 120,
        'factory' => 120,
        'note' => 400,
    ];

    /** Hình dạng + nhãn cho controller và giao diện — MỘT nguồn khai báo. */
    public static function stageShape(): array
    {
        return [
            'stages' => array_map(
                fn (string $stage) => ['id' => $stage, 'label' => Sample::STAGE_LABELS[$stage]],
                Sample::STAGES,
            ),
            'flow' => Sample::STAGE_FLOW,
            'transitions' => Sample::TRANSITIONS,
            'limits' => ['max_samples' => self::MAX_SAMPLES, 'due_soon_days' => self::DUE_SOON_DAYS] + self::TEXT_LIMITS,
        ];
    }

    /**
     * Mức cảnh báo của MỘT mẫu — tính từ dữ liệu, không đọc cờ lưu sẵn.
     *
     * null = không có gì phải nhắc (đã đạt, hoặc chưa khai hạn).
     */
    public static function alert(?Sample $sample): ?string
    {
        if ($sample === null || $sample->due_at === null) {
            return null;
        }

        // Mẫu ĐÃ ĐẠT thì hạn chót không còn nghĩa — nhắc tiếp là tạo tiếng ồn.
        if ($sample->stage === Sample::STAGE_APPROVED) {
            return null;
        }

        $today = now()->startOfDay();
        if ($sample->due_at->lt($today)) {
            return 'overdue';
        }

        return $sample->due_at->lte($today->copy()->addDays(self::DUE_SOON_DAYS)) ? 'due_soon' : null;
    }

    /** Số ngày còn lại tới hạn (âm = đã quá hạn bao nhiêu ngày). null = chưa khai hạn. */
    public static function daysLeft(?Sample $sample): ?int
    {
        if ($sample === null || $sample->due_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($sample->due_at->startOfDay(), false);
    }

    /**
     * Bảng theo dõi của MỘT bộ sưu tập: từng mẫu + đếm theo giai đoạn + cảnh báo + hình dạng cho UI.
     *
     * @return array<string, mixed>
     */
    public function overview(?Project $project): array
    {
        $items = $project === null ? [] : Sample::query()
            ->where('project_id', $project->id)
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->map(fn (Sample $sample) => $this->present($sample))
            ->all();

        $counts = array_fill_keys(Sample::STAGES, 0);
        $overdue = 0;
        $dueSoon = 0;
        foreach ($items as $row) {
            $counts[$row['stage']] = ($counts[$row['stage']] ?? 0) + 1;
            if ($row['alert'] === 'overdue') {
                $overdue++;
            } elseif ($row['alert'] === 'due_soon') {
                $dueSoon++;
            }
        }

        return [
            'items' => $items,
            'counts' => $counts,
            'alerts' => ['overdue' => $overdue, 'due_soon' => $dueSoon],
            'summary' => [
                'total' => count($items),
                'open' => count($items) - $counts[Sample::STAGE_APPROVED],
                'approved' => $counts[Sample::STAGE_APPROVED],
                'rejected' => $counts[Sample::STAGE_REJECTED],
            ],
            'shape' => self::stageShape(),
        ];
    }

    /** Tạo một mẫu. Trạng thái đầu LUÔN là 'requested' — không nhận trạng thái từ client khi tạo. */
    public function create(Project $project, array $data): array
    {
        $count = Sample::query()->where('project_id', $project->id)->count();
        if ($count >= self::MAX_SAMPLES) {
            throw new \RuntimeException('Bộ sưu tập đã có '.self::MAX_SAMPLES.' mẫu — xoá bớt mẫu cũ trước khi thêm.');
        }

        $sample = Sample::create($this->attributes($data) + [
            'project_id' => $project->id,
            'stage' => Sample::STAGE_REQUESTED,
            'round' => max(1, (int) ($data['round'] ?? 1)),
            'sort' => $count,
        ]);

        return $this->present($sample);
    }

    /** Sửa thông tin của một mẫu — KHÔNG đụng tới trạng thái (đường riêng, có whitelist). */
    public function update(Sample $sample, array $data): array
    {
        $sample->fill($this->attributes($data));
        if (array_key_exists('round', $data)) {
            $sample->round = max(1, (int) $data['round']);
        }
        $sample->save();

        return $this->present($sample->fresh());
    }

    /** Chuyển giai đoạn theo whitelist của model. Ném InvalidArgumentException khi bước không hợp lệ. */
    public function transition(Sample $sample, string $to, ?string $note = null): array
    {
        $sample->transitionStage($to, $note);

        return $this->present($sample->fresh());
    }

    public function delete(Sample $sample): void
    {
        $sample->delete();
    }

    /**
     * Mẫu cần nhắc: CHƯA ĐẠT và hạn chót nằm trong khoảng tới hạn` (kể cả đã quá hạn).
     *
     * @return Collection<int, Sample>
     */
    public function dueSamples(int $withinDays = self::DUE_SOON_DAYS): Collection
    {
        $limit = now()->startOfDay()->addDays($withinDays)->toDateString();

        return Sample::query()
            ->where('stage', '!=', Sample::STAGE_APPROVED)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $limit)
            ->orderBy('due_at')
            ->with(['project:id,user_id,name'])
            ->get();
    }

    /** Hình dạng trả ra giao diện cho MỘT hàng — một nơi, không chép ở ba chỗ. */
    private function present(Sample $sample): array
    {
        $next = Sample::nextStage((string) $sample->stage);

        return [
            'id' => (int) $sample->id,
            'style_no' => (string) $sample->style_no,
            'name' => (string) $sample->name,
            'factory' => (string) $sample->factory,
            'stage' => (string) $sample->stage,
            'stage_label' => Sample::STAGE_LABELS[$sample->stage] ?? (string) $sample->stage,
            'round' => (int) $sample->round,
            'due_at' => $sample->due_at?->toDateString(),
            'due_at_label' => $sample->due_at?->format('d/m/Y'),
            'note' => (string) $sample->note,
            'sort' => (int) $sample->sort,
            'alert' => self::alert($sample),
            'days_left' => self::daysLeft($sample),
            'next_stage' => $next,
            'next_stage_label' => $next !== null ? (Sample::STAGE_LABELS[$next] ?? $next) : null,
            'allowed' => Sample::TRANSITIONS[$sample->stage] ?? [],
            'updated_at' => $sample->updated_at?->toISOString(),
        ];
    }

    /**
     * Chỉ lấy các trường VĂN BẢN đã khai, cắt theo trần — không nhận trạng thái/dự án từ client.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function attributes(array $data): array
    {
        $out = [];
        foreach (self::TEXT_LIMITS as $key => $limit) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = Str::limit(trim((string) $data[$key]), $limit, '');
            $out[$key] = $value !== '' ? $value : null;
        }

        if (array_key_exists('due_at', $data)) {
            $raw = trim((string) $data['due_at']);
            $out['due_at'] = $raw !== '' ? $raw : null;
        }

        return $out;
    }
}
