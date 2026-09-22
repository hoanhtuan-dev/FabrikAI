<?php

namespace App\Services;

use App\Models\Project;
use App\Models\QcInspection;
use App\Models\Sample;
use App\Models\TechPack;
use Illuminate\Support\Str;

/**
 * KIỂM TRA CHẤT LƯỢNG — QC (Việc #6 · 2026-09-26).
 *
 * BA NGUYÊN TẮC:
 *   1. KẾT LUẬN LÀ CON SỐ, KHÔNG PHẢI Ý KIẾN. Đạt/không đạt do verdict() tính từ số lỗi so với kế hoạch
 *      lấy mẫu (Ac/Re). AI có thể viết CHỮ (ghi chú, mô tả lỗi) nhưng không được quyết định đạt hay không.
 *   2. KẾ HOẠCH LẤY MẪU ĐƯỢC CHỐT VÀ LƯU LẠI. resolvePlan() chạy một lần rồi ghi vào biên bản: một biên
 *      bản đã lập không được đổi số hồi tố khi bảng tham chiếu của hệ thống cập nhật.
 *   3. BẢNG THAM CHIẾU GHI RÕ LÀ THAM CHIẾU. Xem chú thích ở LOT_RANGES/ACCEPT — cùng cách dự án này đã
 *      xử lý SIZE_CHART và FABRIC_M: nói thẳng "phải đối chiếu trước khi dùng cho hợp đồng" thay vì tỏ ra
 *      chính xác tuyệt đối.
 *
 * @@defect_pct@@ ở tầng giá thành là GIẢ ĐỊNH của chủ xưởng; bảng này là chỗ DUY NHẤT ghi lỗi THẬT để đối
 * chiếu lại giả định đó.
 */
class QcService
{
    /** Điểm kiểm: trong chuyền · cuối chuyền · trước khi giao. */
    public const STAGES = ['inline', 'final', 'pre_shipment'];

    public const STAGE_LABELS = [
        'inline' => 'Trong chuyền',
        'final' => 'Cuối chuyền',
        'pre_shipment' => 'Trước khi giao',
    ];

    /** Bốn mức AQL mà bảng tham chiếu bên dưới có đủ số — thêm mức thì phải thêm CẢ cột ở ACCEPT. */
    public const AQL_LEVELS = ['1.0', '1.5', '2.5', '4.0'];

    public const DEFAULT_AQL = '2.5';

    public const MAX_LOT_SIZE = 100000000;

    /**
     * CỠ LÔ → MÃ CỠ MẪU (mức kiểm tra thường II, lấy mẫu ĐƠN).
     *
     * ⚠️ BẢNG THAM CHIẾU. Số liệu theo kế hoạch lấy mẫu đơn / kiểm tra thường của tiêu chuẩn nghiệm thu
     * phổ biến trong ngành may (ISO 2859-1 · ANSI/ASQ Z1.4 · MIL-STD-105E), mức kiểm tra thường II.
     * ĐÂY KHÔNG PHẢI BẢN SAO CÓ CHỨNG THỰC: trước khi dùng cho hợp đồng, đối chiếu với tiêu chuẩn mà hai
     * bên đã thoả thuận. Bảng nằm ở MỘT chỗ trong mã nên sửa là sửa một nơi, và mỗi biên bản lưu lại kế
     * hoạch ĐÃ CHỐT nên sửa bảng không làm đổi biên bản cũ.
     */
    private const LOT_RANGES = [
        ['min' => 2, 'max' => 8, 'code' => 'A'],
        ['min' => 9, 'max' => 15, 'code' => 'B'],
        ['min' => 16, 'max' => 25, 'code' => 'C'],
        ['min' => 26, 'max' => 50, 'code' => 'D'],
        ['min' => 51, 'max' => 90, 'code' => 'E'],
        ['min' => 91, 'max' => 150, 'code' => 'F'],
        ['min' => 151, 'max' => 280, 'code' => 'G'],
        ['min' => 281, 'max' => 500, 'code' => 'H'],
        ['min' => 501, 'max' => 1200, 'code' => 'J'],
        ['min' => 1201, 'max' => 3200, 'code' => 'K'],
        ['min' => 3201, 'max' => 10000, 'code' => 'L'],
        ['min' => 10001, 'max' => 35000, 'code' => 'M'],
        ['min' => 35001, 'max' => 150000, 'code' => 'N'],
        ['min' => 150001, 'max' => 500000, 'code' => 'P'],
        ['min' => 500001, 'max' => 0, 'code' => 'Q'],   // 0 = không có giới hạn trên
    ];

    /** Mã cỡ mẫu → cỡ mẫu. */
    private const SAMPLE_SIZES = [
        'A' => 2, 'B' => 3, 'C' => 5, 'D' => 8, 'E' => 13, 'F' => 20, 'G' => 32, 'H' => 50,
        'J' => 80, 'K' => 125, 'L' => 200, 'M' => 315, 'N' => 500, 'P' => 800, 'Q' => 1250,
    ];

    /**
     * SỐ CHẤP NHẬN (Ac) theo (cỡ mẫu · mức AQL). Re = Ac + 1 (lấy mẫu đơn: hai số liền nhau, không có vùng
     * lưỡng lự).
     *
     * null = ô đó KHÔNG có kế hoạch trong tiêu chuẩn ⇒ theo nguyên tắc MŨI TÊN của bảng gốc, phải dùng kế
     * hoạch ở CỠ MẪU LỚN HƠN đầu tiên có số (resolvePlan làm đúng việc đó và trả về cỡ mẫu THẬT SỰ dùng).
     */
    private const ACCEPT = [
        2 => ['1.0' => null, '1.5' => null, '2.5' => 0, '4.0' => 0],
        3 => ['1.0' => null, '1.5' => 0, '2.5' => 0, '4.0' => 0],
        5 => ['1.0' => null, '1.5' => 0, '2.5' => 0, '4.0' => 0],
        8 => ['1.0' => 0, '1.5' => 0, '2.5' => 0, '4.0' => 1],
        13 => ['1.0' => 0, '1.5' => 0, '2.5' => 0, '4.0' => 1],
        20 => ['1.0' => 0, '1.5' => 0, '2.5' => 1, '4.0' => 2],
        32 => ['1.0' => 0, '1.5' => 1, '2.5' => 2, '4.0' => 3],
        50 => ['1.0' => 1, '1.5' => 1, '2.5' => 3, '4.0' => 5],
        80 => ['1.0' => 2, '1.5' => 3, '2.5' => 5, '4.0' => 7],
        125 => ['1.0' => 3, '1.5' => 5, '2.5' => 7, '4.0' => 10],
        200 => ['1.0' => 5, '1.5' => 7, '2.5' => 10, '4.0' => 14],
        315 => ['1.0' => 7, '1.5' => 10, '2.5' => 14, '4.0' => 21],
        500 => ['1.0' => 10, '1.5' => 14, '2.5' => 21, '4.0' => null],
        800 => ['1.0' => 14, '1.5' => 21, '2.5' => null, '4.0' => null],
        1250 => ['1.0' => 21, '1.5' => null, '2.5' => null, '4.0' => null],
    ];

    /** Trần số điểm kiểm và số biên bản — bảng dài hơn thì không ai đọc. */
    public const MAX_CHECKLIST_ITEMS = 40;

    public const MAX_INSPECTIONS = 200;

    public const NOTE_MAX = 400;

    public const LABEL_MAX = 120;

    /**
     * KẾ HOẠCH LẤY MẪU cho một lô + một mức AQL — hàm THUẦN (không DB) nên test được trực tiếp.
     *
     * @return array{code: ?string, sample_size: int, ac: int, re: int, aql: string, note: ?string}
     */
    public static function resolvePlan(int $lotSize, string $aql = self::DEFAULT_AQL): array
    {
        $aql = in_array($aql, self::AQL_LEVELS, true) ? $aql : self::DEFAULT_AQL;
        $lotSize = max(0, $lotSize);

        // Lô 0–1 cái: không có kế hoạch lấy mẫu nào áp được. Nói thẳng là kiểm 100%, đừng bịa một cỡ mẫu.
        if ($lotSize < 2) {
            return [
                'code' => null,
                'sample_size' => max(1, $lotSize),
                'ac' => 0,
                're' => 1,
                'aql' => $aql,
                'note' => 'Lô dưới 2 cái — không có kế hoạch lấy mẫu, phải kiểm 100%.',
            ];
        }

        $code = self::codeFor($lotSize);
        $size = self::SAMPLE_SIZES[$code] ?? 0;
        $ac = self::ACCEPT[$size][$aql] ?? null;
        $note = null;

        // NGUYÊN TẮC MŨI TÊN: ô trống ⇒ dùng kế hoạch ở cỡ mẫu LỚN HƠN đầu tiên có số, và CỠ MẪU CŨNG ĐỔI
        // theo. Quên đổi cỡ mẫu là lấy mẫu thiếu rồi kết luận sai — đây là chỗ dễ sai nhất của bảng AQL.
        if ($ac === null) {
            foreach (array_keys(self::ACCEPT) as $candidate) {
                if ($candidate <= $size) {
                    continue;
                }
                if ((self::ACCEPT[$candidate][$aql] ?? null) !== null) {
                    $size = $candidate;
                    $ac = self::ACCEPT[$candidate][$aql];
                    $note = 'Mức AQL này không có kế hoạch ở mã '.$code.' — theo mũi tên của bảng, dùng cỡ mẫu '.$size.'.';
                    break;
                }
            }
        }

        // HẾT ĐƯỜNG MŨI TÊN: ô trống nằm ở ĐÁY cột (lô rất lớn ở mức AQL chặt — ví dụ lô > 150.000 cái ở
        // AQL 2.5), không còn kế hoạch nào lớn hơn để trỏ tới.
        //
        // KHÔNG lùi về một cỡ mẫu NHỎ HƠN: như thế lô to hơn lại lấy mẫu ít hơn — nghịch lý, và là kiểu sai
        // đúng theo hướng có lợi cho người bán. Giữ CỠ MẪU theo mã lô và dùng số chấp nhận CAO NHẤT của
        // cột: cùng một cỡ mẫu mà chặt hơn bảng gốc thì siết, không nới. Nói rõ trong note để người dùng
        // biết đây là chỗ bảng tham chiếu chưa phủ, không phải số đã đối chiếu tiêu chuẩn.
        if ($ac === null) {
            $column = [];
            foreach (self::ACCEPT as $row) {
                if (($row[$aql] ?? null) !== null) {
                    $column[] = (int) $row[$aql];
                }
            }

            if ($column !== []) {
                $ac = max($column);

                return [
                    'code' => $code,
                    'sample_size' => $size,
                    'ac' => $ac,
                    're' => $ac + 1,
                    'aql' => $aql,
                    'note' => 'Bảng tham chiếu không phủ ô (cỡ mẫu '.$size.' · AQL '.$aql.') — giữ cỡ mẫu theo mã lô và dùng số chấp nhận cao nhất của cột (Ac='.$ac.'). Kế hoạch này CHẶT HƠN bảng gốc; đối chiếu tiêu chuẩn trước khi dùng cho hợp đồng.',
                ];
            }

            // Chỉ tới đây khi cả CỘT không có số nào — bảng bị sửa sai. Nói thẳng thay vì bịa một kết luận.
            return [
                'code' => $code,
                'sample_size' => $size,
                'ac' => 0,
                're' => 1,
                'aql' => $aql,
                'note' => 'Không tra được số chấp nhận cho AQL '.$aql.' ở cỡ mẫu '.$size.' — kiểm tra lại bảng tham chiếu.',
            ];
        }

        return [
            'code' => $code,
            'sample_size' => $size,
            'ac' => (int) $ac,
            're' => (int) $ac + 1,
            'aql' => $aql,
            'note' => $note,
        ];
    }

    /** Mã cỡ mẫu của một cỡ lô (mức kiểm tra thường II). */
    public static function codeFor(int $lotSize): string
    {
        foreach (self::LOT_RANGES as $range) {
            $upper = $range['max'];
            if ($lotSize >= $range['min'] && ($upper === 0 || $lotSize <= $upper)) {
                return $range['code'];
            }
        }

        return 'A';   // không tới được (dải đã phủ 2…∞); giữ để hàm luôn trả về một mã
    }

    /**
     * KẾT LUẬN của một biên bản — TÍNH TỪ SỐ, không phải ý kiến.
     *
     * Luật (đã ghi rõ để không phải đoán):
     *   · chưa có kế hoạch / chưa ghi ngày kiểm ⇒ 'pending' (chưa kết luận được — KHÔNG mặc định là đạt);
     *   · có BẤT KỲ lỗi nghiêm trọng nào ⇒ 'fail' (lỗi nghiêm trọng không có mức chấp nhận);
     *   · lỗi nặng ≤ Ac ⇒ 'pass', ngược lại ⇒ 'fail'; Re = Ac + 1 nên không có vùng lưỡng lự.
     *
     * Lỗi NHẸ được ghi để theo dõi nhưng KHÔNG quyết định kết luận — muốn siết thì hạ mức AQL hoặc nâng
     * lỗi nhẹ lên lỗi nặng, chứ không phải cộng dồn hai loại vào một con số rồi so với Ac của một loại.
     *
     * @param  array<string, mixed>  $inspection
     */
    public static function verdict(array $inspection): string
    {
        $plan = $inspection['plan'] ?? null;
        if (! is_array($plan) || ! array_key_exists('ac', $plan)) {
            return QcInspection::RESULT_PENDING;
        }

        if (empty($inspection['inspected_at'])) {
            return QcInspection::RESULT_PENDING;
        }

        if ((int) ($inspection['critical'] ?? 0) > 0) {
            return QcInspection::RESULT_FAIL;
        }

        return (int) ($inspection['major'] ?? 0) <= (int) $plan['ac']
            ? QcInspection::RESULT_PASS
            : QcInspection::RESULT_FAIL;
    }

    /**
     * Nhóm hàng người dùng khai ("Váy", "đầm dạ hội", "Quần tây", "áo sơ mi") → KHOÁ nhóm hàng mà
     * checklistFor() có sẵn điểm kiểm.
     *
     * Vì sao khớp TƯƠNG ĐỐI chứ không so bằng: người dùng không gõ đúng một chuỗi cố định bao giờ. Khớp
     * tuyệt đối nghĩa là checklist riêng của váy/quần gần như KHÔNG BAO GIỜ chạy — im lặng rơi về danh
     * sách chung, và không ai phát hiện vì giao diện vẫn ra một checklist trông hợp lý.
     *
     * @return ?string khoá trong bảng byCategory của checklistFor(), null nếu không nhận ra
     */
    public static function matchCategory(?string $category): ?string
    {
        $needle = mb_strtolower(trim((string) $category));
        if ($needle === '') {
            return null;
        }

        // Từ khoá đã bỏ dấu ở cuối mỗi dòng để bắt được cả trường hợp người dùng gõ không dấu.
        $keywords = [
            'Áo / blouse' => ['áo', 'blouse', 'shirt', 'sơ mi', 'so mi', 'thun', 'polo', 'jacket', 'khoác', 'khoac'],
            'Quần' => ['quần', 'quan tay', 'pant', 'trouser', 'short', 'jean'],
            'Váy' => ['váy', 'đầm', 'dam da hoi', 'dress', 'skirt'],
            'Phụ kiện' => ['phụ kiện', 'phu kien', 'accessor', 'túi', 'tui xach', 'bag', 'thắt lưng', 'belt', 'mũ', 'hat'],
        ];

        foreach ($keywords as $key => $words) {
            foreach ($words as $word) {
                if (mb_strpos($needle, $word) !== false) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * CHECKLIST MẶC ĐỊNH theo nhóm hàng — điểm bắt đầu để sửa, KHÔNG phải danh sách đầy đủ.
     *
     * Vì sao theo nhóm hàng: áo và quần hỏng ở chỗ khác nhau. Một checklist chung cho mọi thứ là checklist
     * không ai dùng. Chủ shop thêm/bớt được và bản đã lưu nằm trong biên bản.
     *
     * @return list<array{key: string, label: string, result: ?string}>
     */
    public static function checklistFor(?string $category): array
    {
        $generic = [
            'Đúng mã hàng / đúng mẫu đã duyệt',
            'Đúng màu so với mẫu duyệt',
            'Đúng chất liệu đã khai trong phiếu kỹ thuật',
            'Đường may thẳng, không nhăn, không bỏ mũi',
            'Chỉ thừa đã cắt sạch',
            'Nhãn / mác đúng vị trí và đúng nội dung',
            'Đóng gói đúng quy cách',
        ];

        $byCategory = [
            'Áo / blouse' => ['Cổ và cầu vai đối xứng', 'Tra tay không nhíu', 'Cúc / khoá chắc, đúng khoảng cách', 'Lai áo đều'],
            'Quần' => ['Hai ống bằng nhau', 'Khoá kéo êm, không hở', 'Cạp đều, không vặn', 'Lai quần đều'],
            'Váy' => ['Khoá kéo êm, không hở', 'Lai váy đều', 'Lót không lộ ra ngoài'],
            'Phụ kiện' => ['Đúng kích thước đã khai', 'Đường viền / mũi may đều', 'Không xước, không gỉ, không lệch màu'],
        ];

        $labels = array_merge($generic, $byCategory[self::matchCategory($category)] ?? []);

        $out = [];
        foreach (array_slice($labels, 0, self::MAX_CHECKLIST_ITEMS) as $i => $label) {
            $out[] = ['key' => 'c'.($i + 1), 'label' => $label, 'result' => null];
        }

        return $out;
    }

    /** Hình dạng + nhãn + bảng tra cho giao diện — MỘT nguồn khai báo. */
    public static function shape(): array
    {
        return [
            'stages' => array_map(fn (string $s) => ['id' => $s, 'label' => self::STAGE_LABELS[$s]], self::STAGES),
            'aql_levels' => self::AQL_LEVELS,
            'results' => array_map(
                fn (string $r) => ['id' => $r, 'label' => QcInspection::RESULT_LABELS[$r]],
                QcInspection::RESULTS,
            ),
            'limits' => [
                'max_inspections' => self::MAX_INSPECTIONS,
                'max_checklist_items' => self::MAX_CHECKLIST_ITEMS,
                'note_max' => self::NOTE_MAX,
                'label_max' => self::LABEL_MAX,
                'max_lot_size' => self::MAX_LOT_SIZE,
            ],
        ];
    }

    /**
     * Bảng biên bản của MỘT bộ sưu tập + số liệu tổng.
     *
     * Có thêm một con số mà tầng giá thành đang THIẾU: TỈ LỆ LỖI THẬT trên số cái đã kiểm. @@defect_pct@@ ở
     * CollectionPlanService là GIẢ ĐỊNH của chủ xưởng; đây là chỗ duy nhất đối chiếu được với thực tế.
     *
     * @return array<string, mixed>
     */
    public function overview(?Project $project): array
    {
        $items = $project === null ? [] : QcInspection::query()
            ->where('project_id', $project->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (QcInspection $row) => $this->present($row))
            ->all();

        $counts = array_fill_keys(QcInspection::RESULTS, 0);
        $defects = ['critical' => 0, 'major' => 0, 'minor' => 0];
        $inspected = 0;
        $inspectedDefects = 0;
        foreach ($items as $row) {
            $counts[$row['result']] = ($counts[$row['result']] ?? 0) + 1;
            foreach (array_keys($defects) as $class) {
                $defects[$class] += $row[$class];
            }
            // Tỉ lệ lỗi chỉ lấy từ biên bản ĐÃ KIỂM, và lấy CẢ tử số lẫn mẫu số từ cùng tập đó. Lấy tử số
            // của mọi biên bản rồi chia cho số cái đã kiểm là trộn hai tập khác nhau — đã đo được một con
            // số vô nghĩa 638,75% khi có biên bản nháp ghi sẵn lỗi.
            if ($row['inspected_at'] !== null) {
                $inspected += $row['sample_size_actual'];
                $inspectedDefects += $row['defects_total'];
            }
        }

        $totalDefects = $defects['critical'] + $defects['major'] + $defects['minor'];

        return [
            'items' => $items,
            'counts' => $counts,
            'defects' => $defects,
            'summary' => [
                'total' => count($items),
                'inspected_units' => $inspected,
                'defect_rate_pct' => $inspected > 0 ? round($inspectedDefects / $inspected * 100, 2) : null,
                // Cơ sở của con số trên: nói ra tử số và mẫu số để đối chiếu được, không đưa một tỉ lệ trần.
                'defect_rate_basis' => ['defects' => $inspectedDefects, 'units' => $inspected],
                'defects_tracked' => $totalDefects,
            ],
            'shape' => self::shape(),
        ];
    }

    /**
     * Mở một biên bản QC. Kế hoạch lấy mẫu được CHỐT NGAY lúc tạo và lưu vào biên bản.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(Project $project, array $data): array
    {
        $count = QcInspection::query()->where('project_id', $project->id)->count();
        if ($count >= self::MAX_INSPECTIONS) {
            throw new \RuntimeException('Bộ sưu tập đã có '.self::MAX_INSPECTIONS.' biên bản QC — xoá bớt trước khi thêm.');
        }

        $lotSize = min(self::MAX_LOT_SIZE, max(0, (int) ($data['lot_size'] ?? 0)));
        $aql = self::normalizeAql($data['aql'] ?? null);
        $sampleId = $this->sampleId($project, $data['sample_id'] ?? null);

        $row = QcInspection::create([
            'project_id' => $project->id,
            'sample_id' => $sampleId,
            'style_no' => $this->text($data['style_no'] ?? null, 40),
            'stage' => self::normalizeStage($data['stage'] ?? null),
            'lot_size' => $lotSize,
            'aql' => $aql,
            'plan' => self::resolvePlan($lotSize, $aql),
            // Checklist: dùng bản người dùng gửi lên nếu có, còn không thì phát MẪU theo nhóm hàng của mẫu.
            'checklist' => array_key_exists('checklist', $data)
                ? self::cleanChecklist($data['checklist'])
                // sample_id có thể null (mở biên bản trước khi có mẫu) → vẫn lấy được nhóm hàng từ phiếu kỹ thuật.
                : self::checklistFor($this->sampleCategory($project, $sampleId)),
            'notes' => $this->text($data['notes'] ?? null, self::NOTE_MAX),
        ]);

        return $this->present($row->fresh());
    }

    /**
     * Cập nhật biên bản rồi TÍNH LẠI kết luận.
     *
     * Đổi cỡ lô hoặc mức AQL ⇒ CHỐT LẠI kế hoạch lấy mẫu (không giữ kế hoạch cũ cho một lô khác kích thước).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(QcInspection $row, array $data): array
    {
        if (array_key_exists('style_no', $data)) {
            $row->style_no = $this->text($data['style_no'], 40);
        }
        if (array_key_exists('stage', $data)) {
            $row->stage = self::normalizeStage($data['stage']);
        }
        if (array_key_exists('notes', $data)) {
            $row->notes = $this->text($data['notes'], self::NOTE_MAX);
        }

        foreach (['critical', 'major', 'minor'] as $class) {
            if (array_key_exists($class, $data)) {
                $row->{$class} = max(0, (int) $data[$class]);
            }
        }

        if (array_key_exists('checklist', $data)) {
            $row->checklist = self::cleanChecklist($data['checklist']);
        }

        if (array_key_exists('inspected_at', $data)) {
            $value = trim((string) $data['inspected_at']);
            $row->inspected_at = $value !== '' ? $value : null;
        }

        if (array_key_exists('lot_size', $data) || array_key_exists('aql', $data)) {
            $row->lot_size = min(self::MAX_LOT_SIZE, max(0, (int) ($data['lot_size'] ?? $row->lot_size)));
            $row->aql = self::normalizeAql($data['aql'] ?? $row->aql);
            $row->plan = self::resolvePlan((int) $row->lot_size, (string) $row->aql);
        }

        if (array_key_exists('sample_id', $data)) {
            $row->sample_id = $this->sampleId($row->project, $data['sample_id']);
        }

        $row->result = self::verdict($row->toArray());
        $row->save();

        return $this->present($row->fresh());
    }

    public function destroy(QcInspection $row): void
    {
        $row->delete();
    }

    /**
     * Chuẩn hoá checklist người dùng gửi lên: bỏ mục rỗng, cắt theo trần, kết quả chỉ nhận pass/fail/na.
     *
     * @param  mixed  $raw
     * @return list<array{key: string, label: string, result: ?string, note: ?string}>
     */
    public static function cleanChecklist($raw): array
    {
        $out = [];
        foreach ((array) $raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = Str::limit(trim((string) ($item['label'] ?? '')), self::LABEL_MAX, '');
            if ($label === '') {
                continue;
            }
            $result = (string) ($item['result'] ?? '');
            $out[] = [
                'key' => Str::limit(trim((string) ($item['key'] ?? '')), 20, '') ?: 'c'.(count($out) + 1),
                'label' => $label,
                'result' => in_array($result, ['pass', 'fail', 'na'], true) ? $result : null,
                'note' => Str::limit(trim((string) ($item['note'] ?? '')), 240, '') ?: null,
            ];
            if (count($out) >= self::MAX_CHECKLIST_ITEMS) {
                break;
            }
        }

        return $out;
    }

    public static function normalizeAql($value): string
    {
        $aql = trim((string) $value);

        return in_array($aql, self::AQL_LEVELS, true) ? $aql : self::DEFAULT_AQL;
    }

    public static function normalizeStage($value): string
    {
        $stage = trim((string) $value);

        return in_array($stage, self::STAGES, true) ? $stage : 'final';
    }

    /** Hình dạng trả ra giao diện cho MỘT biên bản — một nơi, không chép ở ba chỗ. */
    private function present(QcInspection $row): array
    {
        $plan = (array) ($row->plan ?? []);

        return [
            'id' => (int) $row->id,
            'sample_id' => $row->sample_id !== null ? (int) $row->sample_id : null,
            'style_no' => (string) $row->style_no,
            'stage' => (string) $row->stage,
            'stage_label' => self::STAGE_LABELS[$row->stage] ?? (string) $row->stage,
            'lot_size' => (int) $row->lot_size,
            'aql' => (string) $row->aql,
            'plan' => $plan,
            'sample_size_actual' => (int) ($plan['sample_size'] ?? 0),
            'ac' => array_key_exists('ac', $plan) ? (int) $plan['ac'] : null,
            're' => array_key_exists('re', $plan) ? (int) $plan['re'] : null,
            'critical' => (int) $row->critical,
            'major' => (int) $row->major,
            'minor' => (int) $row->minor,
            'defects_total' => (int) $row->critical + (int) $row->major + (int) $row->minor,
            'checklist' => array_values((array) ($row->checklist ?? [])),
            'result' => (string) $row->result,
            'result_label' => QcInspection::RESULT_LABELS[$row->result] ?? (string) $row->result,
            'notes' => (string) $row->notes,
            'inspected_at' => $row->inspected_at?->toISOString(),
            'inspected_at_label' => $row->inspected_at?->format('d/m/Y'),
            'updated_at' => $row->updated_at?->toISOString(),
        ];
    }

    /** Chỉ nhận id mẫu THUỘC bộ đang gọi — id lạ trả null chứ không gắn một mẫu của bộ khác vào biên bản. */
    private function sampleId(Project $project, $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }

        return Sample::query()->where('project_id', $project->id)->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Nhóm hàng để phát checklist mặc định.
     *
     * NGUỒN: phiếu kỹ thuật của bộ (ô "Nhóm hàng") — chỗ người dùng KHAI nhóm hàng, và cũng là thứ đi
     * thẳng vào hợp đồng. Không có phiếu kỹ thuật thì mới suy từ TÊN mẫu ("Đầm linen cổ V" ⇒ Váy);
     * cả hai đều chỉ là GỢI Ý, người dùng sửa được và bản đã sửa nằm lại trong biên bản.
     */
    private function sampleCategory(Project $project, ?int $sampleId): ?string
    {
        $pack = TechPack::query()->where('project_id', $project->id)->first();
        $category = trim((string) (($pack->data ?? [])['category'] ?? ''));
        if ($category !== '') {
            return $category;
        }

        return $sampleId !== null ? (string) (Sample::find($sampleId)?->name ?? '') : null;
    }

    private function text($value, int $max): ?string
    {
        $text = Str::limit(trim((string) $value), $max, '');

        return $text !== '' ? $text : null;
    }
}
