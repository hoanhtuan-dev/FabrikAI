<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectGate;
use App\Models\QcInspection;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * BA CỔNG DUYỆT của một bộ sưu tập (Việc #7 · 2026-09-26).
 *
 * VÌ SAO CẦN: chuỗi sản xuất đã có đủ công cụ (phiếu kỹ thuật · kế hoạch SX & giá · biên bản QC) nhưng
 * KHÔNG có chỗ nào nói "phần này đã được ai đó CHỐT". Trạng thái bộ sưu tập (draft→review→approved) là
 * duyệt BẢN THIẾT KẾ, không phải duyệt BA THỨ ĐI RA NHÀ MÁY: thông số, tiền, chất lượng. Gộp chúng vào
 * một trạng thái duy nhất là để một lần duyệt ảnh âm thầm ký luôn cả phiếu kỹ thuật.
 *
 * BA LUẬT, mỗi luật đều có test:
 *   1. THỨ TỰ — cổng sau chỉ mở khi cổng trước ĐÃ DUYỆT và còn hiệu lực. Duyệt QC trước khi chốt thông
 *      số là duyệt một thứ chưa tồn tại.
 *   2. DUYỆT TRÊN DỮ LIỆU, KHÔNG DUYỆT TRÊN LỜI HỨA — mỗi cổng khai rõ điều kiện dữ liệu; thiếu thì máy
 *      chủ từ chối kèm DANH SÁCH còn thiếu, chứ không ghi một quyết định rỗng.
 *   3. DUYỆT RỒI MÀ DỮ LIỆU ĐỔI THÌ HẾT HIỆU LỰC — mỗi lần duyệt lưu VÂN TAY của dữ liệu nguồn. Sửa
 *      phiếu kỹ thuật / đổi kế hoạch / thêm biên bản QC sau khi duyệt ⇒ cổng thành "cần duyệt lại".
 *      KHÔNG xoá quyết định cũ: vết kiểm toán giữ nguyên, chỉ hiệu lực bị treo.
 *
 * Hệ quả của luật 3 (cascade): rút lại một cổng trước ⇒ các cổng sau đã duyệt thành VÔ HIỆU, vì chúng
 * được duyệt DỰA TRÊN một thứ nay không còn đúng.
 */
class ProjectGateService
{
    public const GATE_TECH_PACK = 'tech_pack';

    public const GATE_PRODUCTION_PLAN = 'production_plan';

    public const GATE_QC = 'qc';

    /** Thứ tự ở đây CHÍNH LÀ thứ tự bắt buộc — đổi thứ tự trong mảng là đổi luật (có test khoá lại). */
    public const GATES = [self::GATE_TECH_PACK, self::GATE_PRODUCTION_PLAN, self::GATE_QC];

    public const GATE_META = [
        self::GATE_TECH_PACK => [
            'label' => 'Chốt phiếu kỹ thuật',
            'hint' => 'Thông số · vải · màu · đường may · bảng đo — thứ đi thẳng vào hợp đồng với xưởng.',
        ],
        self::GATE_PRODUCTION_PLAN => [
            'label' => 'Chốt kế hoạch sản xuất & giá',
            'hint' => 'Số lượng, lệnh cắt, giá vốn và ba mức giá bán. Giá nằm TRONG bản kế hoạch nên chốt chung một cổng — tách ra là hai nút cho một tờ giấy.',
        ],
        self::GATE_QC => [
            'label' => 'Nghiệm thu chất lượng',
            'hint' => 'Chốt sau khi đã có kết quả kiểm thật và không còn lô nào đang không đạt.',
        ],
    ];

    /** Hiệu lực ĐỌC ĐƯỢC của một cổng — khác `decision` đã ghi (quyết định cũ không bị xoá). */
    public const EFFECTIVE_PENDING = 'pending';

    public const EFFECTIVE_APPROVED = 'approved';

    public const EFFECTIVE_REJECTED = 'rejected';

    public const EFFECTIVE_STALE = 'stale';

    public const EFFECTIVE_VOID = 'void';

    public const EFFECTIVE_LABELS = [
        self::EFFECTIVE_PENDING => 'Chưa duyệt',
        self::EFFECTIVE_APPROVED => 'Đã duyệt',
        self::EFFECTIVE_REJECTED => 'Không duyệt',
        self::EFFECTIVE_STALE => 'Cần duyệt lại',
        self::EFFECTIVE_VOID => 'Hết hiệu lực',
    ];

    public const NOTE_MAX = 300;

    /** Từ chối mà không nói vì sao thì người sau không biết phải sửa gì — bắt buộc có lý do. */
    public const REJECT_NOTE_MIN = 3;

    public function __construct(private readonly TechPackService $tech) {}

    /** Hình dạng cho giao diện — MỘT nguồn khai báo, không chép nhãn ở Vue. */
    public function shape(): array
    {
        $decisions = ['decision' => ProjectGate::DECISIONS, 'effective' => self::EFFECTIVE_LABELS];

        return [
            'gates' => array_map(fn (string $gate) => [
                'id' => $gate,
                'label' => self::GATE_META[$gate]['label'],
                'hint' => self::GATE_META[$gate]['hint'],
                'order' => array_search($gate, self::GATES, true) + 1,
            ], self::GATES),
            'decisions' => array_map(
                fn (string $d) => ['id' => $d, 'label' => ProjectGate::DECISION_LABELS[$d]],
                $decisions['decision'],
            ),
            'effective' => array_map(
                fn (string $e) => ['id' => $e, 'label' => $decisions['effective'][$e]],
                array_keys($decisions['effective']),
            ),
            'limits' => ['note_max' => self::NOTE_MAX, 'reject_note_min' => self::REJECT_NOTE_MIN],
        ];
    }

    /**
     * Toàn cảnh ba cổng của MỘT bộ sưu tập + tổng kết.
     *
     * @return array<string, mixed>
     */
    public function overview(Project $project): array
    {
        $rows = ProjectGate::query()
            ->where('project_id', $project->id)
            ->with('decidedBy')
            ->get()
            ->keyBy('gate');

        $items = [];
        $approved = 0;
        $attention = 0;
        $previousEffective = null;   // hiệu lực cổng liền trước (cổng đầu coi như đạt)

        foreach (self::GATES as $gate) {
            $row = $rows->get($gate);
            $decision = $row?->decision ?? ProjectGate::DECISION_PENDING;
            $requirements = $this->requirements($project, $gate);

            // Vân tay chỉ có nghĩa với một quyết định ĐÃ DUYỆT: cổng đang chờ mà dữ liệu đổi thì chẳng có
            // gì để hết hiệu lực.
            $fingerprintNow = $this->fingerprint($project, $gate);
            $fingerprintChanged = $decision === ProjectGate::DECISION_APPROVED
                && $row?->fingerprint !== null
                && $row->fingerprint !== $fingerprintNow;

            $previousOk = $previousEffective === null || $previousEffective === self::EFFECTIVE_APPROVED;
            $effective = $decision;
            $effectiveReason = null;
            if ($decision === ProjectGate::DECISION_APPROVED) {
                if ($fingerprintChanged) {
                    $effective = self::EFFECTIVE_STALE;
                    $effectiveReason = 'Dữ liệu nguồn đã thay đổi sau khi duyệt — quyết định cũ vẫn nằm trong hồ sơ nhưng không còn hiệu lực.';
                } elseif (! $previousOk) {
                    $effective = self::EFFECTIVE_VOID;
                    $effectiveReason = 'Cổng phía trước đã bị rút hoặc làm lại sau khi cổng này được duyệt — phải duyệt lại từ cổng trước.';
                }
            }

            if ($effective === self::EFFECTIVE_APPROVED) {
                $approved++;
            } elseif ($effective !== self::EFFECTIVE_PENDING) {
                $attention++;
            }

            $items[] = [
                'gate' => $gate,
                'label' => self::GATE_META[$gate]['label'],
                'hint' => self::GATE_META[$gate]['hint'],
                'order' => array_search($gate, self::GATES, true) + 1,
                'decision' => $decision,
                'decision_label' => ProjectGate::DECISION_LABELS[$decision] ?? $decision,
                'effective' => $effective,
                'effective_label' => self::EFFECTIVE_LABELS[$effective] ?? $effective,
                'effective_reason' => $effectiveReason,
                'fingerprint_changed' => $fingerprintChanged,
                'note' => (string) ($row->note ?? ''),
                'decided_at' => $row?->decided_at?->toISOString(),
                'decided_at_label' => $row?->decided_at?->format('d/m/Y H:i'),
                'decided_by' => $row?->decidedBy?->name,
                'requirements_ok' => $requirements['ok'],
                'missing' => $requirements['missing'],
                'facts' => $requirements['facts'],
                'previous_gate' => $this->previousGate($gate),
                'previous_ok' => $previousOk,
                // Duyệt được hay không là KẾT LUẬN TỪ DỮ LIỆU, không phải cờ cấu hình.
                'can_approve' => $requirements['ok'] && $previousOk,
                'allowed' => ProjectGate::allowedFrom($decision),
            ];

            $previousEffective = $effective;
        }

        $nextGate = null;
        foreach ($items as $item) {
            if ($item['effective'] !== self::EFFECTIVE_APPROVED) {
                $nextGate = $item['gate'];
                break;
            }
        }

        return [
            'items' => $items,
            'summary' => [
                'total' => count(self::GATES),
                'approved' => $approved,
                'needs_attention' => $attention,
                'ready' => $approved === count(self::GATES),
                'next_gate' => $nextGate,
                'next_gate_label' => $nextGate !== null ? self::GATE_META[$nextGate]['label'] : null,
            ],
            'shape' => self::shape(),
        ];
    }

    /**
     * Điều kiện dữ liệu để DUYỆT một cổng. Trả về cả `facts` (thông tin cho người duyệt đọc) và
     * `missing` (lý do chặn). Cố ý KHÔNG trộn hai thứ: cảnh báo không được biến thành cửa chặn, và cửa
     * chặn không được im lặng.
     *
     * @return array{ok: bool, missing: list<string>, facts: list<string>}
     */
    public function requirements(Project $project, string $gate): array
    {
        return match ($gate) {
            self::GATE_TECH_PACK => $this->techPackRequirements($project),
            self::GATE_PRODUCTION_PLAN => $this->planRequirements($project),
            self::GATE_QC => $this->qcRequirements($project),
            default => ['ok' => false, 'missing' => ['Cổng không hợp lệ: '.$gate], 'facts' => []],
        };
    }

    /** @return array{ok: bool, missing: list<string>, facts: list<string>} */
    private function techPackRequirements(Project $project): array
    {
        $pack = $this->tech->get($project);
        $completeness = (array) ($pack['completeness'] ?? []);
        $missing = [];
        $facts = [];

        if (! ($pack['is_set'] ?? false)) {
            $missing[] = 'Chưa lập phiếu kỹ thuật cho bộ sưu tập này.';
        } else {
            $facts[] = 'Phiếu đã điền '.((int) ($completeness['filled'] ?? 0)).'/'.((int) ($completeness['total'] ?? 0)).' ô.';
            $stillMissing = (array) ($completeness['missing'] ?? []);
            // Cố ý KHÔNG chặn khi phiếu còn thiếu ô: có ô không áp dụng cho mọi loại hàng, và bắt điền đủ
            // mới cho duyệt là biến một công cụ thành cửa chặn. Người duyệt PHẢI THẤY mình đang ký gì.
            $facts[] = $stillMissing === []
                ? 'Danh sách ô kiểm không còn thiếu ô nào.'
                : 'Còn thiếu (không chặn duyệt, nhưng nên bổ sung): '.implode(' · ', array_slice($stillMissing, 0, 4));
        }

        return ['ok' => $missing === [], 'missing' => $missing, 'facts' => $facts];
    }

    /** @return array{ok: bool, missing: list<string>, facts: list<string>} */
    private function planRequirements(Project $project): array
    {
        $plan = $project->settingsArray()['plan'] ?? null;
        $missing = [];
        $facts = [];

        if (! is_array($plan) || $plan === []) {
            $missing[] = 'Chưa có kế hoạch sản xuất trong bộ sưu tập — mở Agent Studio → Định hướng → Sản xuất & lãi rồi lưu vào bộ.';

            return ['ok' => false, 'missing' => $missing, 'facts' => $facts];
        }

        $totals = (array) ($plan['totals'] ?? []);
        $units = (int) ($totals['units'] ?? 0);
        if ($units <= 0) {
            $missing[] = 'Kế hoạch chưa có số lượng sản xuất (0 cái).';
        }

        $selling = (array) ($plan['selling'] ?? []);
        if ($selling === []) {
            $missing[] = 'Kế hoạch chưa có mức giá bán nào — chưa chốt được giá.';
        }

        if ($units > 0) {
            $facts[] = 'Tổng '.number_format($units, 0, ',', '.').' cái · '.((int) ($totals['cut_lines'] ?? 0)).' mã cắt.';
        }
        if (isset($totals['avg_unit_cost_vnd'])) {
            $facts[] = 'Giá vốn bình quân '.number_format((int) $totals['avg_unit_cost_vnd'], 0, ',', '.').' đ/cái.';
        }
        foreach (array_slice($selling, 0, 3) as $row) {
            $price = (int) ($row['price_vnd'] ?? 0);
            if ($price > 0) {
                $facts[] = 'Giá bán '.($row['label'] ?? 'mức').': '.number_format($price, 0, ',', '.').' đ'
                    .(isset($row['margin_pct']) ? ' (lãi gộp '.$this->pct((float) $row['margin_pct']).')' : '').'.';
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing, 'facts' => $facts];
    }

    /** @return array{ok: bool, missing: list<string>, facts: list<string>} */
    private function qcRequirements(Project $project): array
    {
        $rows = QcInspection::query()
            ->where('project_id', $project->id)
            ->whereNotNull('inspected_at')
            ->get(['id', 'result', 'critical', 'major', 'minor', 'plan']);

        $missing = [];
        $facts = [];

        if ($rows->isEmpty()) {
            $missing[] = 'Chưa có biên bản QC nào đã kiểm — mở "Kiểm tra chất lượng" và ghi kết quả ít nhất một lô.';

            return ['ok' => false, 'missing' => $missing, 'facts' => $facts];
        }

        $failed = $rows->where('result', QcInspection::RESULT_FAIL)->count();
        if ($failed > 0) {
            $missing[] = 'Còn '.$failed.' lô KHÔNG ĐẠT — phải xử lý (làm lại · loại · thoả thuận với xưởng) trước khi nghiệm thu.';
        }

        $units = 0;
        $defects = 0;
        foreach ($rows as $row) {
            $units += (int) (((array) ($row->plan ?? []))['sample_size'] ?? 0);
            $defects += (int) $row->critical + (int) $row->major + (int) $row->minor;
        }
        $facts[] = 'Đã kiểm '.$rows->count().' lô · '.number_format($units, 0, ',', '.').' cái.';
        $facts[] = $units > 0
            ? 'Tỉ lệ lỗi thật: '.$this->pct($defects / $units * 100).' ('.$defects.' lỗi / '.number_format($units, 0, ',', '.').' cái đã kiểm).'
            : 'Chưa cộng được số cái đã kiểm (các biên bản không có kế hoạch lấy mẫu).';

        return ['ok' => $missing === [], 'missing' => $missing, 'facts' => $facts];
    }

    /**
     * VÂN TAY của dữ liệu nguồn — chụp LÚC DUYỆT, so lại mỗi lần đọc.
     *
     * Cố ý bám vào thứ NGƯỜI DÙNG SỬA ĐƯỢC và ĐÁNG phải duyệt lại, không bám vào updated_at: sửa một lỗi
     * chính tả trong ghi chú QC không làm hết hiệu lực nghiệm thu, còn đổi kết quả một lô thì có.
     */
    public function fingerprint(Project $project, string $gate): string
    {
        if ($gate === self::GATE_TECH_PACK) {
            return sha1(json_encode($this->tech->get($project)['data'] ?? []));
        }
        if ($gate === self::GATE_PRODUCTION_PLAN) {
            return sha1(json_encode($project->settingsArray()['plan'] ?? null));
        }
        if ($gate === self::GATE_QC) {
            $parts = QcInspection::query()
                ->where('project_id', $project->id)
                ->whereNotNull('inspected_at')
                ->orderBy('id')
                ->get(['id', 'result'])
                ->map(fn (QcInspection $row) => $row->id.':'.$row->result)
                ->all();

            return sha1(implode(',', $parts));
        }

        return sha1('invalid:'.$gate);
    }

    /**
     * Phần trăm theo cách viết tiếng Việt: dấu PHẨY thập phân, bỏ số 0 vô nghĩa (2,5% · 63,75%).
     *
     * Vì sao không dùng round() rồi nối '%': đầu ra là CÂU cho người duyệt đọc trước khi ký, mà "2.5%"
     * lẫn trong một câu tiếng Việt là chỗ dễ bị đọc nhầm thành 25. Số trong JSON thì vẫn là số — chỉ câu
     * chữ mới định dạng.
     */
    private function pct(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

        return ($text === '' ? '0' : $text).'%';
    }

    /** Cổng liền trước theo thứ tự — null với cổng đầu. */
    public function previousGate(string $gate): ?string
    {
        $index = array_search($gate, self::GATES, true);

        return ($index === false || $index === 0) ? null : self::GATES[$index - 1];
    }

    /**
     * GHI một quyết định duyệt. Ném InvalidArgumentException kèm câu giải thích — controller trả 422.
     *
     * @return array<string, mixed> toàn cảnh sau khi ghi (giao diện không phải tự đoán lại số)
     */
    public function decide(Project $project, string $gate, string $decision, ?string $note, User $user): array
    {
        if (! in_array($gate, self::GATES, true)) {
            throw new \InvalidArgumentException('Cổng không hợp lệ: '.$gate);
        }
        if (! in_array($decision, ProjectGate::DECISIONS, true)) {
            throw new \InvalidArgumentException('Quyết định không hợp lệ: '.$decision);
        }

        $row = ProjectGate::firstOrNew(['project_id' => $project->id, 'gate' => $gate]);
        $from = $row->exists ? (string) $row->decision : ProjectGate::DECISION_PENDING;
        $allowed = ProjectGate::allowedFrom($from);
        if (! in_array($decision, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Cổng "'.self::GATE_META[$gate]['label'].'" đang ở "'.(ProjectGate::DECISION_LABELS[$from] ?? $from)
                .'" — chỉ đi tiếp được tới: '.implode(' · ', array_map(
                    fn (string $d) => ProjectGate::DECISION_LABELS[$d] ?? $d,
                    $allowed,
                )).'.',
            );
        }

        $note = Str::limit(trim((string) $note), self::NOTE_MAX, '');

        if ($decision === ProjectGate::DECISION_REJECTED && mb_strlen($note) < self::REJECT_NOTE_MIN) {
            throw new \InvalidArgumentException(
                'Không duyệt thì phải ghi LÝ DO (ít nhất '.self::REJECT_NOTE_MIN.' ký tự) — người sau cần biết phải sửa gì.',
            );
        }

        if ($decision === ProjectGate::DECISION_APPROVED) {
            // Thứ tự trước, dữ liệu sau: nói "cổng trước chưa duyệt" hữu ích hơn là bắt người dùng đọc
            // danh sách dữ liệu thiếu của một cổng mà họ còn chưa được phép duyệt.
            $previous = $this->previousGate($gate);
            if ($previous !== null) {
                $previousItem = collect($this->overview($project)['items'])->firstWhere('gate', $previous);
                if (($previousItem['effective'] ?? null) !== self::EFFECTIVE_APPROVED) {
                    throw new \InvalidArgumentException(
                        'Phải duyệt xong cổng "'.self::GATE_META[$previous]['label'].'" trước — hiện tại nó đang là "'
                        .($previousItem['effective_label'] ?? '?').'".',
                    );
                }
            }

            $requirements = $this->requirements($project, $gate);
            if (! $requirements['ok']) {
                throw new \InvalidArgumentException('Chưa duyệt được: '.implode(' ', $requirements['missing']));
            }

            $row->decided_by = $user->id;
            $row->decided_at = now();
            $row->fingerprint = $this->fingerprint($project, $gate);
        } elseif ($decision === ProjectGate::DECISION_REJECTED) {
            $row->decided_by = $user->id;
            $row->decided_at = now();
            $row->fingerprint = null;
        } else {
            // Rút lại / mở lại: quyết định cũ bị ghi đè thì dấu vết ai-khi-nao phải bị xoá cùng lúc — để
            // lại một dấu vết trỏ vào quyết định không còn tồn tại là dấu vết SAI.
            $row->decided_by = null;
            $row->decided_at = null;
            $row->fingerprint = null;
        }

        $row->decision = $decision;
        $row->note = $note !== '' ? $note : null;
        $row->save();

        return $this->overview($project);
    }
}
