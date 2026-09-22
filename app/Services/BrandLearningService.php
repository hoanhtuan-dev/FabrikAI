<?php

namespace App\Services;

use App\Jobs\ReflectBrandMemoryJob;
use App\Models\BrandLearning;
use App\Models\Generation;
use App\Models\User;
use App\Support\Vocabulary;
use Illuminate\Support\Str;

/**
 * TRÍ NHỚ DÀI HẠN — học từ lựa chọn duyệt/loại ảnh của chủ shop.
 *
 * BA GIAI ĐOẠN của vòng lặp tự học, tất cả nằm ở lớp này:
 *   · GĐ1 — GHI: chủ shop duyệt ảnh (ProjectController::reviewShots) → record() ghi prompt + quyết định.
 *   · GĐ2 — RÚT BÀI HỌC: ReflectBrandMemoryJob gọi vai agent_reflect để khái quát thành lesson.
 *   · GĐ3 — CỦNG CỐ: record() nhận ra quyết định TRÙNG với ký ức đã có thì củng cố ký ức đó (mạnh lên,
 *     lesson dùng luôn của bản cũ nên KHÔNG tốn thêm một lượt gọi model); decay() thì suy yếu ký ức lâu
 *     không dùng và quên hẳn ký ức đã yếu + đã cũ.
 * Khi tạo brief, DesignAgentService đọc preferences() để nhét cả prompt thô lẫn bài học vào prompt —
 * và nay đọc theo ĐỘ MẠNH, không phải theo thứ tự mới nhất.
 *
 * VÌ SAO GĐ3 CẦN THIẾT: nếu mọi ký ức nặng như nhau và cửa sổ prompt luôn lấy N cái MỚI NHẤT thì một buổi
 * duyệt 40 ảnh sẽ **đẩy hết** phong cách đã đúng suốt nhiều tháng ra khỏi prompt. Trí nhớ thành NHẬT KÝ.
 */
class BrandLearningService
{
    /** Trần độ mạnh — lấy từ model để chỉ có MỘT nguồn (xem BrandLearning::WEIGHT_MIN/MAX). */
    public const WEIGHT_MIN = BrandLearning::WEIGHT_MIN;

    public const WEIGHT_MAX = BrandLearning::WEIGHT_MAX;

    public const DEFAULT_WEIGHT = BrandLearning::DEFAULT_WEIGHT;

    /**
     * Ngưỡng coi hai quyết định là CÙNG MỘT ký ức.
     *
     * ĐO LẠI TRÊN PRODUCTION 2026-09-26 (3 ca thật của ngành):
     *   · "đầm linen trắng ngà dáng suông" vs "… dáng rộng"  → **0,71** (cùng phong cách, khác chi tiết ⇒ khớp)
     *   · "áo sơ mi linen form rộng" vs "áo thun linen form rộng" → **0,57** (CỐ Ý không khớp: khác loại hàng)
     *   · "áo sơ mi linen" vs "đầm dạ hội sequin đen"            → **0,00**
     * 0,6 nằm giữa 0,57 và 0,71 nên tách đúng hai chuyện rất khác nhau: "cùng món, khác chi tiết" và
     * "cùng vải, khác sản phẩm". Đây là phép ĐO TỪ KHOÁ vì chưa có embedding — xem chú thích ở similarity().
     */
    public const SIMILARITY_THRESHOLD = 0.6;

    /** Số ký ức gần nhất đem ra so khi củng cố — có trần để đường duyệt ảnh không phình theo thời gian. */
    public const MATCH_WINDOW = 50;

    /** Lâu hơn mức này mà không được nhắc lại thì bắt đầu yếu đi. */
    public const DECAY_AFTER_DAYS = 30;

    /** Quá mức này mà vẫn ở mức yếu nhất thì QUÊN hẳn (không giữ rác trong prompt). */
    public const FORGET_AFTER_DAYS = 180;

    /** Trần số ký ức xử lý mỗi lượt suy giảm — lệnh chạy hằng ngày, không cần dọn hết trong một lượt. */
    public const DECAY_BATCH = 500;

    /** Trần số ký ức trả về màn hình "Trí nhớ" — bảng dài hơn thì không ai đọc, và không phải chỗ để xuất dữ liệu. */
    public const MEMORY_MAX = 200;

    /**
     * Ghi một quyết định. Prompt rút gọn 500 ký tự; cùng generation + cùng quyết định chỉ ghi 1 lần.
     *
     * Ghi xong thì CỦNG CỐ hoặc RÚT BÀI HỌC:
     *   · trùng với ký ức đã có VÀ ký ức đó đã có lesson ⇒ dùng luôn lesson đó, KHÔNG gọi model
     *     (đây là chỗ tiết kiệm thật: mỗi lượt gọi model là tiền và độ trễ);
     *   · còn lại ⇒ đẩy job rút bài học như trước.
     */
    public function record(Generation $shot, string $decision): void
    {
        if (! in_array($decision, [BrandLearning::DECISION_APPROVED, BrandLearning::DECISION_REJECTED], true)) {
            return;
        }

        $prompt = Str::limit(trim((string) $shot->prompt), 500, '');
        if ($prompt === '') {
            return;
        }

        $exists = BrandLearning::where('generation_id', $shot->id)
            ->where('decision', $decision)
            ->exists();
        if ($exists) {
            return;
        }

        $row = BrandLearning::create([
            'user_id' => $shot->user_id,
            'generation_id' => $shot->id,
            'decision' => $decision,
            'prompt' => $prompt,
            'source' => 'shot_review',
        ]);

        $matched = $this->consolidate($row);

        if ($matched !== null && trim((string) $matched->lesson) !== '') {
            // Đã có bài học ĐÚNG cho phong cách này ⇒ kế thừa, và cũng đánh dấu hàng mới là đã củng cố
            // (nó thuộc cùng một ký ức, không phải một ký ức mới yếu).
            $row->lesson = $matched->lesson;
            $row->weight = max(self::WEIGHT_MIN, (int) $matched->weight);
            $row->refreshed_at = now();
            $row->context = [
                'inherited_from' => (int) $matched->id,
                'consolidated_at' => now()->toISOString(),
            ];
            $row->save();

            return;
        }

        ReflectBrandMemoryJob::dispatch($row->id);
    }

    /**
     * CỦNG CỐ: quyết định vừa ghi có trùng với ký ức nào đã có không? Nếu có thì làm ký ức đó MẠNH LÊN.
     *
     * Chọn ký ức **mạnh nhất** trong số trùng khớp (không phải mới nhất): cơ chế ghi nhớ hoạt động theo
     * kiểu "đường mòn nào được đi nhiều thì mòn thêm" — củng cố chính cái đã mạnh, thay vì rải độ mạnh ra
     * nhiều bản gần giống nhau.
     *
     * @return BrandLearning|null ký ức đã được củng cố, hoặc null nếu đây là ký ức mới.
     */
    public function consolidate(BrandLearning $new): ?BrandLearning
    {
        $candidates = BrandLearning::query()
            ->where('user_id', $new->user_id)
            ->where('decision', $new->decision)
            ->where('id', '!=', $new->id)
            ->orderByDesc('weight')->orderBy('id')
            ->limit(self::MATCH_WINDOW)
            ->get();

        $best = null;
        $bestScore = 0.0;
        foreach ($candidates as $candidate) {
            $score = self::similarity((string) $new->prompt, (string) $candidate->prompt);
            if ($score >= self::SIMILARITY_THRESHOLD && $score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return null;
        }

        $this->reinforce($best);

        return $best;
    }

    /** Làm một ký ức mạnh lên: +weight (có trần), +hits, và đóng dấu thời điểm củng cố. */
    public function reinforce(BrandLearning $row, int $steps = 1): BrandLearning
    {
        $row->weight = min(self::WEIGHT_MAX, max(self::WEIGHT_MIN, (int) $row->weight + $steps));
        $row->hits = (int) $row->hits + 1;
        $row->refreshed_at = now();
        $row->save();

        return $row;
    }

    /**
     * SUY YẾU + QUÊN — phần cần THỜI GIAN trôi qua nên phải chạy theo lịch (studio:memory:consolidate).
     *
     * Vì sao không gộp vào record(): decay là việc của cả tập ký ức theo thời gian, không phải của một
     * quyết định. Và vì sao vẫn phải NÓI RA số lượng: một lệnh im lặng xoá ký ức của khách là thứ không
     * ai kiểm được.
     *
     * @param  bool  $dryRun  true = CHỈ ĐẾM, không ghi gì (để người vận hành xem trước).
     * @return array{decayed: int, forgotten: int, scanned: int, dry_run: bool}
     */
    public function decay(bool $dryRun = false): array
    {
        $staleBefore = now()->subDays(self::DECAY_AFTER_DAYS);
        $forgetBefore = now()->subDays(self::FORGET_AFTER_DAYS);

        // Quá hạn suy giảm = chưa từng củng cố (refreshed_at null) và tạo đã lâu, HOẶC củng cố lần cuối
        // đã lâu. Dùng created_at làm mốc khi chưa từng củng cố — nên không phải ghi refreshed_at lúc tạo.
        $stale = fn ($query) => $query
            ->where(fn ($q) => $q->where('refreshed_at', '<', $staleBefore)
                ->orWhere(fn ($q2) => $q2->whereNull('refreshed_at')->where('created_at', '<', $staleBefore)));

        $decayed = 0;
        $rows = BrandLearning::query()
            ->where('weight', '>', self::WEIGHT_MIN)
            ->where($stale)
            ->orderBy('refreshed_at')->orderBy('id')
            ->limit(self::DECAY_BATCH)
            ->get(['id', 'weight']);

        foreach ($rows as $row) {
            if (! $dryRun) {
                BrandLearning::whereKey($row->id)->update([
                    'weight' => max(self::WEIGHT_MIN, (int) $row->weight - 1),
                ]);
            }
            $decayed++;
        }

        // QUÊN: đã ở mức yếu nhất mà còn cũ hơn ngưỡng quên. Xoá hẳn thay vì giữ một dòng weight=1 mãi
        // mãi — dòng đó vẫn chiếm chỗ trong cửa sổ prompt mà không còn nói lên điều gì về shop.
        $doomed = BrandLearning::query()
            ->where('weight', '<=', self::WEIGHT_MIN)
            ->where($stale)
            ->where(fn ($q) => $q->where('refreshed_at', '<', $forgetBefore)
                ->orWhere(fn ($q2) => $q2->whereNull('refreshed_at')->where('created_at', '<', $forgetBefore)));

        $forgotten = $dryRun ? $doomed->count() : $doomed->delete();

        return [
            'decayed' => $decayed,
            'forgotten' => (int) $forgotten,
            'scanned' => $rows->count(),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * ĐỘ GIỐNG NHAU giữa hai prompt — hàm THUẦN, test được không cần DB.
     *
     * VÌ SAO CHƯA DÙNG EMBEDDING: việc #9 của lộ trình (vector retrieval) chưa làm, và cắm một lời gọi
     * embedding vào đường DUYỆT ẢNH là thêm độ trễ + tiền cho mỗi cú bấm. Phép trùng-từ-khoá này tất định,
     * đo được, và đủ cho việc củng cố ("cùng phong cách" thường dùng cùng bộ từ: linen · trắng ngà · suông).
     * Khi có embedding thì thay ĐÚNG hàm này bằng khoảng cách ngữ nghĩa — mọi nơi gọi không phải sửa.
     *
     * Trả 0..1 (Jaccard trên tập từ khoá đã chuẩn hoá).
     */
    public static function similarity(string $a, string $b): float
    {
        // [2026-09-26] Phép tách từ nay nằm ở App\Support\Vocabulary — một chỗ cho cả trí nhớ (ở đây) và
        // việc #9 (tìm thiết kế cũ). Hai bản sao của một phép tách là hai tập từ khoá khác nhau.
        return Vocabulary::jaccard($a, $b);
    }

    /** Tách từ khoá — luật nằm ở App\Support\Vocabulary (một chỗ cho cả trí nhớ và việc #9). */
    private static function tokens(string $text): array
    {
        return Vocabulary::tokens($text);
    }

    /**
     * DANH SÁCH ký ức để NGƯỜI DÙNG đọc lại (Việc #7+ · 2026-09-26).
     *
     * Vì sao cần: trí nhớ đã có tác dụng từ đợt 1 nhưng KHÔNG có màn hình nào cho thấy agent đã học gì —
     * người dùng chỉ thấy brief "tự nhiên" đổi giọng. Một trí nhớ không đọc lại được thì không sửa được,
     * và một trí nhớ không sửa được là trí nhớ sẽ sai dần mà không ai biết.
     *
     * Xếp theo ĐỘ MẠNH (rồi id mới nhất) — đúng thứ tự mà brief đọc, để màn hình này nói cùng một chuyện
     * với thứ agent thật sự dùng.
     *
     * @return array<string, mixed>
     */
    public function memory(User $user, int $limit = 50): array
    {
        $take = max(1, min(self::MEMORY_MAX, $limit));
        $rows = BrandLearning::query()
            ->where('user_id', $user->id)
            ->orderByDesc('weight')
            ->orderByDesc('id')
            ->limit($take)
            ->get();

        return [
            'items' => $rows->map(fn (BrandLearning $row) => $this->presentMemory($row))->all(),
            'stats' => $this->stats($user),
            'limits' => [
                'max' => self::MEMORY_MAX,
                'limit' => $take,
                'weight_min' => self::WEIGHT_MIN,
                'weight_max' => self::WEIGHT_MAX,
            ],
        ];
    }

    /**
     * QUÊN một ký ức — quyền của người dùng, không phải của hệ thống.
     *
     * Vì sao phải có: agent có thể rút ra một bài học SAI (một lần loại ảnh vì lỗi kỹ thuật lại bị đọc
     * thành "shop không thích màu này"). Cách sửa duy nhất đúng là XOÁ ký ức đó — không phải thêm một
     * ký ức ngược lại để hai cái đánh nhau trong prompt.
     */
    public function forget(BrandLearning $row): void
    {
        $row->delete();
    }

    /** Hình dạng trả ra giao diện cho MỘT ký ức — một nơi, không chép ở hai chỗ. */
    private function presentMemory(BrandLearning $row): array
    {
        $weight = (int) $row->weight;

        return [
            'id' => (int) $row->id,
            'decision' => (string) $row->decision,
            'decision_label' => $row->decision === BrandLearning::DECISION_APPROVED ? 'Đã duyệt' : 'Đã loại',
            'lesson' => (string) ($row->lesson ?? ''),
            'prompt' => Str::limit((string) $row->prompt, 300, '…'),
            'weight' => $weight,
            'strength' => $weight >= 8 ? 'strong' : ($weight <= 3 ? 'weak' : 'normal'),
            'strength_label' => $weight >= 8 ? 'Mạnh' : ($weight <= 3 ? 'Yếu' : 'Vừa'),
            'hits' => (int) $row->hits,
            'source' => (string) ($row->source ?? ''),
            'created_at' => $row->created_at?->toISOString(),
            'created_at_label' => $row->created_at?->format('d/m/Y'),
            'refreshed_at_label' => $row->refreshed_at?->format('d/m/Y'),
            'age_days' => $row->created_at !== null ? (int) $row->created_at->diffInDays(now()) : null,
        ];
    }

    /**
     * Số liệu để người vận hành nhìn ra trí nhớ đang dày lên hay mỏng đi — thay vì tin vào cảm giác.
     *
     * @return array{total: int, approved: int, rejected: int, with_lesson: int, strong: int, weak: int, avg_weight: float, last_at: ?string}
     */
    public function stats(User $user): array
    {
        $rows = BrandLearning::query()->where('user_id', $user->id)->get(['decision', 'lesson', 'weight', 'created_at']);

        return [
            'total' => $rows->count(),
            'approved' => $rows->where('decision', BrandLearning::DECISION_APPROVED)->count(),
            'rejected' => $rows->where('decision', BrandLearning::DECISION_REJECTED)->count(),
            'with_lesson' => $rows->filter(fn ($r) => trim((string) $r->lesson) !== '')->count(),
            // "Mạnh" = từ 8 trở lên (đã được củng cố ít nhất 3 lần so với mức khởi đầu 5).
            'strong' => $rows->filter(fn ($r) => (int) $r->weight >= 8)->count(),
            'weak' => $rows->filter(fn ($r) => (int) $r->weight <= 2)->count(),
            'avg_weight' => $rows->isEmpty() ? 0.0 : round($rows->avg('weight'), 2),
            'last_at' => $rows->max('created_at')?->toISOString(),
        ];
    }

    /**
     * Rút "bài học" cho MỘT quyết định đã ghi (ReasoningBank, rút gọn).
     *
     * Vì sao tách khỏi record(): bước này gọi model (~vài giây) nên phải nằm trong job nền, KHÔNG chặn
     * request duyệt ảnh. Trả null khi không có model hoặc model không trả được bài học — khi đó brief
     * vẫn dùng prompt thô như cũ, không bao giờ vỡ vì thiếu "bài học".
     *
     * @return string|null bài học đã ghi, hoặc null nếu không rút được.
     */
    public function reflectRecord(BrandLearning $row, ?AiModelGateway $gateway = null): ?string
    {
        if ($gateway === null || $row->lesson !== null) {
            return $row->lesson;
        }

        $approved = [];
        $rejected = [];
        foreach (BrandLearning::where('user_id', $row->user_id)
            ->where('id', '!=', $row->id)
            ->orderByDesc('weight')->orderByDesc('id')->limit(12)->get(['decision', 'prompt']) as $near) {
            if ($near->decision === BrandLearning::DECISION_APPROVED) {
                $approved[] = $near->prompt;
            } else {
                $rejected[] = $near->prompt;
            }
        }

        $instruction = 'Bạn là bộ phận RÚT KINH NGHIỆM cho Agent Studio thời trang. '
            .'Dựa trên prompt ảnh chủ shop ĐÃ DUYỆT và ĐÃ LOẠI, hãy viết MỘT bài học ngắn về gu thật của shop. '
            .'Bài học phải KHÁI QUÁT (thích/tránh chất liệu, dáng, màu, phong cách) — KHÔNG chép nguyên văn prompt. '
            .'Không bịa con số, không nhắc tên model hay nhà cung cấp. '
            .'Trả DUY NHẤT một object JSON có đúng một khoá: {"lesson": "..."}.';

        $focus = $row->decision === BrandLearning::DECISION_APPROVED ? 'ĐÃ DUYỆT' : 'ĐÃ LOẠI';
        $payload = [
            'focus' => $focus.' — '.$row->prompt,
            'approved_recent' => array_slice($approved, 0, 6),
            'rejected_recent' => array_slice($rejected, 0, 6),
        ];

        $messages = [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => 'DỮ LIỆU: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ];

        $answer = $gateway->text(DesignAgentService::REFLECT_GROUP, $messages, [
            'fallback_groups' => [DesignAgentService::REASON_GROUP, DesignAgentService::AI_GROUP],
            'response_format' => 'json_object',
            'disable_thinking' => true,
            'timeout' => 30,
        ]);

        if ($answer === null) {
            return null;
        }

        $lesson = $this->decodeLesson((string) $answer['text']);
        if ($lesson === null) {
            return null;
        }

        $row->lesson = Str::limit($lesson, 800, '');
        $row->context = [
            'reflected_at' => now()->toISOString(),
            'provider' => $answer['provider'] ?? null,
            'model' => $answer['model'] ?? null,
        ];
        $row->save();

        return $row->lesson;
    }

    /** Đọc "lesson" từ text trả về — chấp nhận JSON trần hoặc bọc lời dẫn quanh vùng JSON. */
    private function decodeLesson(string $text): ?string
    {
        $text = trim($text);
        $json = json_decode($text, true);

        if (! is_array($json)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $json = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($json)) {
            return null;
        }

        $lesson = trim((string) ($json['lesson'] ?? ''));

        return $lesson !== '' ? $lesson : null;
    }

    /**
     * "Gu" của shop: prompt đã DUYỆT/đã LOẠI (GĐ1) + bài học đã rút (GĐ2), xếp theo ĐỘ MẠNH (GĐ3).
     *
     * VÌ SAO ĐỔI TỪ "MỚI NHẤT" SANG "MẠNH NHẤT": cửa sổ prompt có trần (10 prompt + 5 bài học). Lấy theo
     * thứ tự mới nhất nghĩa là một buổi duyệt hôm nay có thể **xoá sạch** ảnh hưởng của những phong cách
     * đã đúng suốt nhiều tháng. Lấy theo độ mạnh thì cái gì được duyệt lặp lại nhiều lần mới tồn tại được
     * — đúng nghĩa "gu", và đúng tinh thần củng cố của GĐ3.
     *
     * @return array{approved: list<string>, rejected: list<string>, lessons: array{approved: list<string>, rejected: list<string>}}
     */
    public function preferences(User $user, int $limitApproved = 10, int $limitRejected = 5): array
    {
        $ranked = fn (string $decision) => BrandLearning::where('user_id', $user->id)
            ->where('decision', $decision)
            ->orderByDesc('weight')->orderByDesc('id');

        return [
            'approved' => $ranked(BrandLearning::DECISION_APPROVED)->limit($limitApproved)->pluck('prompt')->all(),
            'rejected' => $ranked(BrandLearning::DECISION_REJECTED)->limit($limitRejected)->pluck('prompt')->all(),
            'lessons' => [
                'approved' => $ranked(BrandLearning::DECISION_APPROVED)
                    ->whereNotNull('lesson')->where('lesson', '!=', '')
                    ->limit($limitApproved)->pluck('lesson')->all(),
                'rejected' => $ranked(BrandLearning::DECISION_REJECTED)
                    ->whereNotNull('lesson')->where('lesson', '!=', '')
                    ->limit($limitRejected)->pluck('lesson')->all(),
            ],
        ];
    }
}
