<?php

namespace App\Services;

use App\Models\Project;
use App\Models\TechPack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PHIẾU KỸ THUẬT TƯƠNG TÁC (tech pack) — 2026-09-26.
 *
 * VÌ SAO CÓ LỚP NÀY: gói xuất cho xưởng đã có, nhưng phiếu kỹ thuật trong gói chỉ là CHỮ CÓ DÒNG CHẤM
 * để xưởng tự điền. Chủ shop không có chỗ nào GHI thông số trong ứng dụng ⇒ mỗi lần xuất là một tờ
 * giấy trắng mới, và thông số thật chỉ nằm trong đầu họ.
 *
 * BA NGUYÊN TẮC:
 *   1. MỘT NGUỒN KHAI BÁO HÌNH DẠNG — TEXT_FIELDS · FIELD_LABELS · FIELD_HINTS · các trần. Controller
 *      validate và giao diện hiển thị đều đọc từ đây, nên không thể có chuyện validate cho qua rồi
 *      service cắt bớt mà không ai biết.
 *   2. KHÔNG CHẶN NGƯỜI DÙNG VÔ CỚ — phiếu thiếu ô vẫn lưu và vẫn xuất được; hệ thống chỉ NÓI RÕ còn
 *      thiếu gì (completeness()). Bắt điền đủ 11 ô mới cho lưu là biến một công cụ thành cửa chặn.
 *   3. SỐ LIỆU LÀ CỦA NGƯỜI DÙNG — không suy diễn, không bịa định mức. Hệ thống chỉ ĐỊNH DẠNG lại.
 */
class TechPackService
{
    /** Trường văn bản: khoá => trần ký tự. Thứ tự ở đây là thứ tự hiển thị. */
    public const TEXT_FIELDS = [
        'style_no' => 40,
        'style_name' => 120,
        'category' => 60,
        'fabric' => 240,
        'lining' => 160,
        'trim' => 240,
        'colorways' => 240,
        'construction' => 600,
        'labels_packaging' => 240,
        'qc_notes' => 400,
        'notes' => 400,
    ];

    public const FIELD_LABELS = [
        'style_no' => 'Mã hàng',
        'style_name' => 'Tên mẫu',
        'category' => 'Nhóm hàng',
        'fabric' => 'Vải chính',
        'lining' => 'Lót / mex / dựng',
        'trim' => 'Phụ liệu',
        'colorways' => 'Màu / mã vải',
        'construction' => 'Đường may & hướng dẫn may',
        'labels_packaging' => 'Nhãn & bao bì',
        'qc_notes' => 'Lưu ý QC',
        'notes' => 'Ghi chú khác',
    ];

    /** Gợi ý điền — dùng làm placeholder ở giao diện, KHÔNG phải giá trị mặc định. */
    public const FIELD_HINTS = [
        'style_no' => 'VD: FD-2610',
        'style_name' => 'VD: Đầm linen cổ V',
        'category' => 'VD: Váy',
        'fabric' => 'VD: Linen 55% · cotton 45% · 180 gsm · khổ 150cm',
        'lining' => 'VD: Lót cotton mỏng, mex dựng thân trước',
        'trim' => 'VD: Khoá kéo giọt nước 15cm · chỉ may 40/2 · nhãn dệt',
        'colorways' => 'VD: Trắng ngà (PANTONE 11-0602) · Be (14-1116)',
        'construction' => 'VD: Vai 2 kim · sườn 4 chỉ · lai vắt sổ cuốn 0.5cm · tra khoá giữa lưng',
        'labels_packaging' => 'VD: Nhãn size sau cổ · tag giấy · túi PE có logo',
        'qc_notes' => 'VD: Soi chỉ thừa, kiểm tra chiều co vải sau giặt',
        'notes' => 'VD: Vải cần test co trước khi cắt',
    ];

    /**
     * TRƯỜNG CỐT LÕI — dùng để nói "phiếu đã đủ để gửi xưởng chưa".
     *
     * Cố ý NGẮN: một phiếu thiếu tên mẫu vẫn cắt được, nhưng thiếu VẢI thì không. Đây là ngưỡng để
     * cảnh báo, KHÔNG phải điều kiện để lưu hay xuất.
     */
    public const CORE_FIELDS = ['style_no', 'fabric', 'colorways', 'construction'];

    public const MAX_SIZE_LABELS = 8;

    public const SIZE_LABEL_MAX = 8;

    public const MAX_MEASUREMENT_ROWS = 30;

    public const POINT_MAX = 60;

    public const TOLERANCE_MAX = 20;

    public const MEASUREMENT_VALUE_MAX = 16;

    /** Hình dạng + trần cho controller và giao diện — MỘT nguồn khai báo. */
    public static function shape(): array
    {
        return [
            'fields' => array_map(
                fn (string $key) => [
                    'key' => $key,
                    'label' => self::FIELD_LABELS[$key],
                    'hint' => self::FIELD_HINTS[$key] ?? '',
                    'max' => self::TEXT_FIELDS[$key],
                ],
                array_keys(self::TEXT_FIELDS),
            ),
            'core_fields' => self::CORE_FIELDS,
            'core_labels' => array_map(fn (string $k) => self::FIELD_LABELS[$k], self::CORE_FIELDS),
            'limits' => [
                'max_sizes' => self::MAX_SIZE_LABELS,
                'size_label_max' => self::SIZE_LABEL_MAX,
                'max_measurements' => self::MAX_MEASUREMENT_ROWS,
                'point_max' => self::POINT_MAX,
                'tolerance_max' => self::TOLERANCE_MAX,
                'value_max' => self::MEASUREMENT_VALUE_MAX,
            ],
        ];
    }

    /** Phiếu RỖNG theo đúng hình dạng đã khai. */
    public static function empty(): array
    {
        return [
            'fields' => array_fill_keys(array_keys(self::TEXT_FIELDS), ''),
            'sizes' => [],
            'measurements' => [],
        ];
    }

    /**
     * Chuẩn hoá dữ liệu thô về ĐÚNG hình dạng — HÀM THUẦN (không DB) nên test được trực tiếp.
     *
     * KHÔNG ném lỗi: dữ liệu cũ/hỏng vẫn phải đọc được. Trường thiếu bù rỗng, giá trị vượt trần bị cắt,
     * danh sách được làm sạch + bỏ trùng và GIỮ thứ tự người dùng nhập.
     *
     * @param  mixed  $raw
     */
    public static function normalize($raw): array
    {
        $out = self::empty();
        if (! is_array($raw)) {
            return $out;
        }

        $fields = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];
        foreach (self::TEXT_FIELDS as $key => $limit) {
            $value = $fields[$key] ?? '';
            $out['fields'][$key] = is_scalar($value) ? Str::limit(trim((string) $value), $limit, '') : '';
        }

        // Nhãn size: viết HOA cho khớp cách xưởng đọc, khử trùng, giữ thứ tự.
        $sizes = [];
        foreach ((array) ($raw['sizes'] ?? []) as $size) {
            if (! is_scalar($size)) {
                continue;
            }
            $label = Str::limit(mb_strtoupper(trim((string) $size)), self::SIZE_LABEL_MAX, '');
            if ($label === '' || in_array($label, $sizes, true)) {
                continue;
            }
            $sizes[] = $label;
            if (count($sizes) >= self::MAX_SIZE_LABELS) {
                break;
            }
        }
        $out['sizes'] = $sizes;

        $rows = [];
        foreach ((array) ($raw['measurements'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $point = Str::limit(trim((string) ($row['point'] ?? '')), self::POINT_MAX, '');
            if ($point === '') {
                continue;   // dòng chưa có điểm đo = dòng trống lúc đang gõ, không phải dữ liệu
            }

            $values = $row['values'] ?? [];
            // Cho phép nhập nhanh bằng dấu gạch chéo ("96 / 100 / 104") — thứ tự khớp cột size.
            if (is_string($values)) {
                $parts = preg_split('/\s*[\/|;]\s*/', trim($values)) ?: [];
                $values = [];
                foreach ($sizes as $i => $label) {
                    $values[$label] = $parts[$i] ?? '';
                }
            }

            $clean = [];
            foreach ($sizes as $label) {
                $value = is_array($values) ? ($values[$label] ?? '') : '';
                $clean[$label] = is_scalar($value) ? Str::limit(trim((string) $value), self::MEASUREMENT_VALUE_MAX, '') : '';
            }

            $rows[] = [
                'point' => $point,
                'tolerance' => Str::limit(trim((string) ($row['tolerance'] ?? '')), self::TOLERANCE_MAX, ''),
                'values' => $clean,
            ];
            if (count($rows) >= self::MAX_MEASUREMENT_ROWS) {
                break;
            }
        }
        $out['measurements'] = $rows;

        return $out;
    }

    /** Phiếu có nội dung gì không? (mọi trường rỗng + không có ô đo = chưa lập) */
    public static function isEmpty(array $pack): bool
    {
        foreach ($pack['fields'] ?? [] as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return ($pack['measurements'] ?? []) === [];
    }

    /**
     * Đã đủ để gửi xưởng chưa — ĐẾM và NÓI RÕ còn thiếu gì.
     *
     * @return array{filled: int, total: int, missing: list<string>, has_measurements: bool, ready: bool}
     */
    public static function completeness(array $pack): array
    {
        $missing = [];
        $filled = 0;
        foreach (self::CORE_FIELDS as $key) {
            if (trim((string) ($pack['fields'][$key] ?? '')) !== '') {
                $filled++;
            } else {
                $missing[] = self::FIELD_LABELS[$key] ?? $key;
            }
        }

        $hasMeasurements = ($pack['measurements'] ?? []) !== [] && ($pack['sizes'] ?? []) !== [];
        if (! $hasMeasurements) {
            $missing[] = 'Bảng thông số (cần ít nhất 1 điểm đo và 1 size)';
        }

        $total = count(self::CORE_FIELDS) + 1;   // +1 cho bảng thông số

        return [
            'filled' => $filled + ($hasMeasurements ? 1 : 0),
            'total' => $total,
            'missing' => $missing,
            'has_measurements' => $hasMeasurements,
            'ready' => $missing === [],
        ];
    }

    /**
     * Phiếu kỹ thuật của MỘT bộ sưu tập + trạng thái để giao diện nói THẬT.
     *
     * @return array{data: array<string, mixed>, is_set: bool, updated_at: ?string, completeness: array<string, mixed>}
     */
    public function get(?Project $project): array
    {
        if (! $project) {
            return $this->present(self::empty(), null);
        }

        $row = TechPack::query()->where('project_id', $project->id)->first();

        return $this->present(self::normalize($row?->data), $row?->updated_at?->toISOString());
    }

    /**
     * Lưu phiếu (upsert theo project_id). Quyền đã được controller kiểm trước khi tới đây.
     *
     * @param  array<string, mixed>  $raw
     * @return array{data: array<string, mixed>, is_set: bool, updated_at: ?string, completeness: array<string, mixed>}
     */
    public function save(?Project $project, array $raw): array
    {
        if (! $project) {
            return $this->present(self::empty(), null);
        }

        $data = self::normalize($raw);

        DB::transaction(function () use ($project, $data) {
            TechPack::updateOrCreate(['project_id' => $project->id], ['data' => $data]);
        });

        return $this->get($project->fresh());
    }

    /** Xoá phiếu (về "chưa lập") — KHÔNG đụng ảnh, dự án hay dữ liệu khác. */
    public function reset(?Project $project): array
    {
        if ($project) {
            TechPack::query()->where('project_id', $project->id)->delete();
        }

        return $this->get($project);
    }

    /**
     * PHẦN PHIẾU KỸ THUẬT của tệp gửi xưởng — thay cho các dòng chấm trống của bản cũ.
     *
     * Trường nào chưa điền thì vẫn in dòng chấm để xưởng biết cần hỏi lại, KHÔNG bỏ im lặng: một ô
     * biến mất làm xưởng tưởng hạng mục đó không áp dụng.
     */
    public function textSheet(Project $project, array $pack, string $note): string
    {
        $out = ['PHIẾU KỸ THUẬT — '.$project->name, str_repeat('=', 60), ''];
        $completeness = self::completeness($pack);

        if ($note !== '') {
            $out[] = 'Ghi chú chung: '.$note;
            $out[] = '';
        }

        $out[] = 'THÔNG SỐ';
        $out[] = str_repeat('-', 60);
        foreach (self::TEXT_FIELDS as $key => $_) {
            $label = str_pad(self::FIELD_LABELS[$key].':', 22);
            $value = trim((string) ($pack['fields'][$key] ?? ''));
            $out[] = $label.($value !== '' ? $value : '....................................');
        }
        $out[] = '';

        $out[] = 'BẢNG THÔNG SỐ (cm)';
        $out[] = str_repeat('-', 60);
        foreach ($this->measurementTable($pack) as $line) {
            $out[] = $line;
        }
        $out[] = '';

        if (! $completeness['ready']) {
            $out[] = 'PHIẾU CÒN THIẾU: '.implode(' · ', $completeness['missing']);
            $out[] = 'Xưởng vui lòng xác nhận lại các mục trên với khách trước khi cắt.';
            $out[] = '';
        }

        return implode("\n", $out)."\n";
    }

    /** Bảng thông số dạng CSV — cột là size, dòng là điểm đo. */
    public function measurementsCsv(array $pack): string
    {
        $sizes = $pack['sizes'] ?? [];
        $header = array_merge(['diem_do', 'dung_sai'], $sizes);

        $out = [$this->csvRow($header)];
        foreach ($pack['measurements'] ?? [] as $row) {
            $cells = [$row['point'] ?? '', $row['tolerance'] ?? ''];
            foreach ($sizes as $size) {
                $cells[] = $row['values'][$size] ?? '';
            }
            $out[] = $this->csvRow($cells);
        }

        return implode("\n", $out)."\n";
    }

    /**
     * Bảng thông số dạng CHỮ, canh cột theo bề rộng lớn nhất — một nguồn cho cả tệp gửi xưởng lẫn
     * bản xem trên màn hình (hai bản chép tay sẽ lệch nhau ngay lần sửa đầu).
     *
     * @return list<string>
     */
    public function measurementTable(array $pack): array
    {
        $sizes = $pack['sizes'] ?? [];
        $rows = $pack['measurements'] ?? [];
        if ($sizes === []) {
            return ['(chưa khai size — bảng thông số trống)'];
        }
        if ($rows === []) {
            return ['(chưa có điểm đo nào)'];
        }

        $head = array_merge(['Điểm đo', 'Dung sai'], $sizes);
        $widths = array_map(fn ($h) => mb_strlen((string) $h), $head);

        $matrix = [];
        foreach ($rows as $row) {
            $line = [$row['point'] ?? '', $row['tolerance'] ?? ''];
            foreach ($sizes as $size) {
                $line[] = $row['values'][$size] ?? '';
            }
            $matrix[] = $line;
            foreach ($line as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        $render = function (array $cells) use ($widths): string {
            $parts = [];
            foreach ($cells as $i => $cell) {
                $parts[] = mb_str_pad((string) $cell, ($widths[$i] ?? 0) + 2);
            }

            return rtrim(implode('', $parts));
        };

        return array_merge([$render($head), str_repeat('-', array_sum($widths) + count($widths) * 2)], array_map($render, $matrix));
    }

    /** @return array{data: array<string, mixed>, is_set: bool, updated_at: ?string, completeness: array<string, mixed>} */
    private function present(array $data, ?string $updatedAt): array
    {
        return [
            'data' => $data,
            'is_set' => ! self::isEmpty($data),
            'updated_at' => $updatedAt,
            'completeness' => self::completeness($data),
        ];
    }

    /** @param  list<string>  $cells */
    private function csvRow(array $cells): string
    {
        return implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $cells));
    }
}
