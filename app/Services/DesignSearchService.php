<?php

namespace App\Services;

use App\Ai\EmbeddingGateway;
use App\Models\BrandLearning;
use App\Models\DesignEmbedding;
use App\Models\Generation;
use App\Models\Project;
use App\Models\TechPack;
use App\Models\User;
use App\Support\Vocabulary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TÌM THIẾT KẾ CŨ — FileSearch (Việc #9 · 2026-09-26).
 *
 * VÌ SAO: cả dự án chỉ có MỘT việc thật sự cần tới vec-tơ, và đây là nó. Trước đợt này "tìm thiết kế cũ"
 * là ĐẾM + DÒ TỪ KHOÁ cứng trong 120 prompt gần nhất: gõ "áo khoác màu be mùa trước" thì không tìm được
 * prompt viết "khoác dạ be" vì hai câu không dùng chung một từ nào.
 *
 * HAI CHẾ ĐỘ, VÀ NÓI RÕ ĐANG CHẠY CHẾ ĐỘ NÀO (không có chuyện "AI tìm kiếm" chung chung):
 *   · embedding — có nhà cung cấp nhúng được (đo trên production: ckey 1536 chiều · qwen-paygo 1024 chiều).
 *     Điểm là COSINE giữa vec-tơ câu hỏi và vec-tơ tài liệu.
 *   · keyword   — chưa/chẳng may nhà cung cấp nhúng hỏng. Điểm là BM25 trên chính kho tài liệu, có nêu
 *     những từ đã khớp. Chế độ này KHÔNG phải tìm ngữ nghĩa và giao diện phải ghi đúng như vậy.
 *
 * GIỚI HẠN ĐÃ BIẾT, khai ra thay vì giấu: máy chủ đang chạy MariaDB (không có kiểu vec-tơ, không có chỉ
 * mục ANN) nên việc so điểm nằm ở PHP và CHỈ QUÉT MAX_SCAN tài liệu mới nhất của tài khoản. Vượt trần đó
 * thì kết quả vẫn đúng nhưng có thể thiếu tài liệu cũ — đây là lý do trần được trả về trong response
 * (scanned · capped) chứ không im lặng.
 */
class DesignSearchService
{
    /** Trần tài liệu quét mỗi lượt tìm. 2.000 vec-tơ 1.536 chiều ≈ 12 MB — mức còn chấp nhận cho một request. */
    public const MAX_SCAN = 2000;

    /** Trần tài liệu nhúng trong MỘT lượt lập chỉ mục (mỗi tài liệu là một phần của một lời gọi ra ngoài). */
    public const MAX_INDEX = 300;

    public const QUERY_LIMIT = 10;

    /** Ngưỡng tối thiểu để trả về — dưới ngưỡng là rác, và rác làm người dùng mất tin vào tìm kiếm. */
    public const MIN_SCORE = 0.25;

    public const QUERY_MAX = 200;

    public const SOURCE_LABELS = [
        'generation' => 'Ảnh đã tạo',
        'brief' => 'Brief bộ sưu tập',
        'lesson' => 'Bài học đã rút',
        'tech_pack' => 'Phiếu kỹ thuật',
    ];

    public function __construct(
        private readonly EmbeddingGateway $embeddings,
    ) {}

    /**
     * KHO TÀI LIỆU của một tài khoản — dựng từ dữ liệu THẬT đang có (không phải bảng riêng phải nuôi tay).
     *
     * @return list<array{source_type: string, source_id: int, project_id: ?int, title: string, text: string}>
     */
    public function documents(User $user, int $limit = self::MAX_SCAN): array
    {
        $out = [];

        Generation::query()
            ->where('user_id', $user->id)
            ->whereNotNull('prompt')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'project_id', 'prompt'])
            ->each(function (Generation $row) use (&$out) {
                $text = trim((string) $row->prompt);
                if ($text === '') {
                    return;
                }
                $out[] = [
                    'source_type' => 'generation',
                    'source_id' => (int) $row->id,
                    'project_id' => $row->project_id !== null ? (int) $row->project_id : null,
                    'title' => 'Ảnh · '.Str::limit($text, 60, '…'),
                    'text' => $text,
                ];
            });

        Project::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'name', 'brief'])
            ->each(function (Project $row) use (&$out) {
                $text = trim(((string) $row->name).'. '.((string) $row->brief));
                if (trim((string) $row->brief) === '') {
                    return;   // brief rỗng thì tài liệu chỉ còn cái tên — không đủ để tìm
                }
                $out[] = [
                    'source_type' => 'brief',
                    'source_id' => (int) $row->id,
                    'project_id' => (int) $row->id,
                    'title' => 'Brief · '.(string) $row->name,
                    'text' => $text,
                ];
            });

        BrandLearning::query()
            ->where('user_id', $user->id)
            ->whereNotNull('lesson')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'prompt', 'lesson'])
            ->each(function (BrandLearning $row) use (&$out) {
                $lesson = trim((string) $row->lesson);
                if ($lesson === '') {
                    return;
                }
                $out[] = [
                    'source_type' => 'lesson',
                    'source_id' => (int) $row->id,
                    'project_id' => null,
                    'title' => 'Bài học · '.Str::limit($lesson, 60, '…'),
                    'text' => $lesson.' '.(string) $row->prompt,
                ];
            });

        TechPack::query()
            ->whereIn('project_id', function ($q) use ($user) {
                $q->select('id')->from('projects')->where('user_id', $user->id)->whereNull('deleted_at');
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'project_id', 'data'])
            ->each(function (TechPack $row) use (&$out) {
                $data = (array) ($row->data ?? []);
                $fields = (array) ($data['fields'] ?? []);
                $text = trim(implode(' ', array_filter([
                    (string) ($fields['style_no'] ?? ''),
                    (string) ($fields['style_name'] ?? ''),
                    (string) ($fields['category'] ?? ''),
                    (string) ($fields['fabric'] ?? ''),
                    (string) ($fields['construction'] ?? ''),
                    (string) ($fields['colorways'] ?? ''),
                ])));
                if ($text === '') {
                    return;
                }
                $out[] = [
                    'source_type' => 'tech_pack',
                    'source_id' => (int) $row->id,
                    'project_id' => $row->project_id !== null ? (int) $row->project_id : null,
                    'title' => 'Phiếu kỹ thuật · '.($fields['style_no'] ?? ('#'.$row->id)),
                    'text' => $text,
                ];
            });

        return $out;
    }

    /**
     * LẬP CHỈ MỤC: nhúng những tài liệu CHƯA có vec-tơ hoặc ĐÃ ĐỔI CHỮ (so bằng text_hash).
     *
     * @return array<string, mixed>
     */
    public function index(User $user, int $limit = self::MAX_INDEX, bool $dryRun = false): array
    {
        $limit = max(1, min(self::MAX_INDEX, $limit));
        $docs = $this->documents($user, self::MAX_SCAN);

        $existing = DesignEmbedding::query()
            ->where('user_id', $user->id)
            ->get(['source_type', 'source_id', 'text_hash', 'dims'])
            ->keyBy(fn (DesignEmbedding $r) => $r->source_type.':'.$r->source_id);

        $todo = [];
        foreach ($docs as $doc) {
            $hash = sha1($doc['text']);
            $row = $existing->get($doc['source_type'].':'.$doc['source_id']);
            if ($row !== null && $row->text_hash === $hash && (int) $row->dims > 0) {
                continue;
            }
            $todo[] = $doc + ['hash' => $hash];
        }

        $pending = count($todo);
        if ($dryRun) {
            return [
                'total' => count($docs),
                'indexed' => 0,
                'pending' => $pending,
                'dry_run' => true,
                'provider' => $this->embeddings->provider(),
            ];
        }

        if ($todo === []) {
            return ['total' => count($docs), 'indexed' => 0, 'pending' => 0, 'provider' => $this->embeddings->provider()];
        }

        $todo = array_slice($todo, 0, $limit);
        $embedded = $this->embeddings->embed(array_map(fn ($d) => $d['text'], $todo));
        if ($embedded === null || count($embedded['vectors']) !== count($todo)) {
            return [
                'total' => count($docs),
                'indexed' => 0,
                'pending' => $pending,
                'provider' => null,
                'unavailable' => true,
            ];
        }

        foreach ($todo as $i => $doc) {
            $vector = $embedded['vectors'][$i] ?? [];
            if ($vector === []) {
                continue;
            }
            DesignEmbedding::updateOrCreate(
                ['user_id' => $user->id, 'source_type' => $doc['source_type'], 'source_id' => $doc['source_id']],
                [
                    'project_id' => $doc['project_id'],
                    'text' => $doc['text'],
                    'text_hash' => $doc['hash'],
                    'provider' => $embedded['provider'],
                    'model' => $embedded['model'],
                    'dims' => count($vector),
                    'vector' => $this->pack($vector),
                ],
            );
        }

        return [
            'total' => count($docs),
            'indexed' => count($todo),
            'pending' => max(0, $pending - count($todo)),
            'provider' => ['provider' => $embedded['provider'], 'model' => $embedded['model'], 'dims' => $embedded['dims']],
        ];
    }

    /**
     * TÌM. Luôn trả về cả chế độ đã dùng và lý do — người dùng phải biết mình đang nhận kết quả ngữ nghĩa
     * hay kết quả từ khoá.
     *
     * @return array<string, mixed>
     */
    public function search(User $user, string $query, int $limit = self::QUERY_LIMIT): array
    {
        $query = Str::limit(trim($query), self::QUERY_MAX, '');
        $limit = max(1, min(self::QUERY_LIMIT, $limit));
        $started = microtime(true);

        if ($query === '') {
            return ['query' => '', 'mode' => 'none', 'mode_label' => '', 'items' => [], 'scanned' => 0, 'took_ms' => 0, 'reason' => 'Chưa nhập nội dung cần tìm.', 'shape' => $this->shape()];
        }

        $embedded = $this->embeddings->embed([$query]);
        $queryVector = $embedded['vectors'][0] ?? null;

        if (is_array($queryVector) && $queryVector !== []) {
            $result = $this->searchByVector($user, $queryVector, $limit, $started);
            if ($result !== null) {
                return $result + ['query' => $query, 'shape' => $this->shape()];
            }
        }

        $reason = $this->embeddings->available()
            ? 'Nhà cung cấp nhúng trả lỗi ở lượt này nên đang tìm theo TỪ KHOÁ — đây KHÔNG phải tìm ngữ nghĩa.'
            : 'Chưa có nhà cung cấp nào nhúng được văn bản, nên đang tìm theo TỪ KHOÁ — đây KHÔNG phải tìm ngữ nghĩa. Muốn tìm theo ngữ nghĩa thì cần một nhà cung cấp có endpoint /embeddings (đã đo: ckey và qwen-paygo có, deepseek không).';

        return $this->searchByKeyword($user, $query, $limit, $reason, $started) + ['query' => $query, 'shape' => $this->shape()];
    }

    /** @return array<string, mixed>|null null = không quét được vec-tơ nào (nơi gọi lùi về từ khoá) */
    private function searchByVector(User $user, array $queryVector, int $limit, float $started): ?array
    {
        $dims = count($queryVector);

        // Chỉ so với vec-tơ CÙNG SỐ CHIỀU: đổi nhà cung cấp nhúng là đổi số chiều, và so hai không gian
        // khác nhau cho ra điểm vô nghĩa (cosine vẫn chạy, kết quả vẫn sai — kiểu sai khó thấy nhất).
        $rows = DesignEmbedding::query()
            ->where('user_id', $user->id)
            ->where('dims', $dims)
            ->orderByDesc('id')
            ->limit(self::MAX_SCAN)
            ->get(['id', 'source_type', 'source_id', 'project_id', 'text', 'vector', 'dims']);

        if ($rows->isEmpty()) {
            return null;
        }

        $scored = [];
        foreach ($rows as $row) {
            $vector = $this->unpack((string) $row->vector, (int) $row->dims);
            if ($vector === []) {
                continue;
            }
            $score = $this->cosine($queryVector, $vector);
            if ($score >= self::MIN_SCORE) {
                $scored[] = ['row' => $row, 'score' => $score];
            }
        }

        if ($scored === []) {
            return null;
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);
        $names = $this->projectNames($top);

        return [
            'mode' => 'embedding',
            'mode_label' => 'Tìm theo NGỮ NGHĨA (vec-tơ '.$dims.' chiều)',
            'provider' => $this->embeddings->provider(),
            'reason' => null,
            'scanned' => $rows->count(),
            'capped' => $rows->count() >= self::MAX_SCAN,
            'indexed' => $rows->count(),
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
            'items' => array_map(fn ($hit) => $this->presentHit($hit['row'], $hit['score'], $names, $hit['score']), $top),
        ];
    }

    /** @return array<string, mixed> */
    private function searchByKeyword(User $user, string $query, int $limit, string $reason, float $started): array
    {
        $docs = $this->documents($user, self::MAX_SCAN);
        $tokens = Vocabulary::tokens($query);

        // BM25 trên CHÍNH kho tài liệu: idf tính theo tần suất trong kho, nên một từ hiếm (tên vải, mã hàng)
        // nặng hơn hẳn một từ phổ biến ("đầm"). Đây vẫn là tìm TỪ KHOÁ — không phải ngữ nghĩa.
        $docTokens = [];
        $df = [];
        foreach ($docs as $i => $doc) {
            $docTokens[$i] = Vocabulary::tokens($doc['text']);
            foreach (array_unique($docTokens[$i]) as $token) {
                $df[$token] = ($df[$token] ?? 0) + 1;
            }
        }

        $total = max(1, count($docs));
        $avgLen = 0;
        foreach ($docTokens as $tokens2) {
            $avgLen += count($tokens2);
        }
        $avgLen = $avgLen > 0 ? $avgLen / $total : 1;
        $k1 = 1.2;
        $b = 0.75;

        $scored = [];
        foreach ($docs as $i => $doc) {
            $length = max(1, count($docTokens[$i]));
            $score = 0.0;
            $matched = [];
            foreach ($tokens as $token) {
                $tf = 0;
                foreach ($docTokens[$i] as $word) {
                    if ($word === $token) {
                        $tf++;
                    }
                }
                if ($tf === 0) {
                    continue;
                }
                $matched[] = $token;
                $idf = log(1 + ($total - ($df[$token] ?? 0) + 0.5) / (($df[$token] ?? 0) + 0.5));
                $score += $idf * (($tf * ($k1 + 1)) / ($tf + $k1 * (1 - $b + $b * ($length / $avgLen))));
            }
            if ($score > 0) {
                $scored[] = ['doc' => $doc, 'score' => $score, 'matched' => $matched];
            }
        }

        usort($scored, fn ($a, $b2) => $b2['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);
        $best = $top === [] ? 1.0 : max(0.0001, (float) $top[0]['score']);
        $names = $this->projectNames(array_map(fn ($h) => (object) ['project_id' => $h['doc']['project_id']], $top));

        return [
            'mode' => 'keyword',
            'mode_label' => 'Tìm theo TỪ KHOÁ (không phải ngữ nghĩa)',
            'provider' => $this->embeddings->provider(),
            'reason' => $reason,
            'scanned' => count($docs),
            'capped' => count($docs) >= self::MAX_SCAN,
            'indexed' => (int) DesignEmbedding::query()->where('user_id', $user->id)->count(),
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
            // Điểm BM25 không có thang 0..1; quy về % SO VỚI KẾT QUẢ ĐẦU để so sánh được giữa các dòng,
            // và nói rõ đó là điểm tương đối chứ không phải "độ khớp tuyệt đối".
            'items' => array_map(fn ($hit) => $this->presentDocHit($hit['doc'], round($hit['score'] / $best * 100, 1), $names, $hit['matched']), $top),
        ];
    }

    /** @return array<string, mixed> */
    private function presentHit(DesignEmbedding $row, float $score, array $names, float $rawScore): array
    {
        return [
            'source_type' => (string) $row->source_type,
            'source_label' => self::SOURCE_LABELS[$row->source_type] ?? $row->source_type,
            'source_id' => (int) $row->source_id,
            'project_id' => $row->project_id !== null ? (int) $row->project_id : null,
            'project_name' => $names[(int) $row->project_id] ?? null,
            'title' => Str::limit((string) $row->text, 70, '…'),
            'snippet' => Str::limit((string) $row->text, 240, '…'),
            'score' => round($rawScore, 4),
            'score_pct' => round(min(100, max(0, $score * 100)), 1),
            'matched' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function presentDocHit(array $doc, float $pct, array $names, array $matched): array
    {
        return [
            'source_type' => $doc['source_type'],
            'source_label' => self::SOURCE_LABELS[$doc['source_type']] ?? $doc['source_type'],
            'source_id' => $doc['source_id'],
            'project_id' => $doc['project_id'],
            'project_name' => $names[(int) $doc['project_id']] ?? null,
            'title' => $doc['title'],
            'snippet' => Str::limit($doc['text'], 240, '…'),
            'score' => $pct,
            'score_pct' => $pct,
            'matched' => $matched,
        ];
    }

    /** @return array<int, string> id dự án => tên (một truy vấn cho cả lượt, không N+1) */
    private function projectNames(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = is_object($row) ? ($row->project_id ?? null) : ($row['project_id'] ?? null);
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        return Project::query()->whereIn('id', array_unique($ids))->pluck('name', 'id')->all();
    }

    /** @return array{indexed: int, total: int, percent: float|null, provider: ?array, can_embed: bool} */
    public function stats(User $user): array
    {
        $total = count($this->documents($user, self::MAX_SCAN));
        $indexed = (int) DesignEmbedding::query()->where('user_id', $user->id)->count();

        return [
            'indexed' => $indexed,
            'total' => $total,
            'percent' => $total > 0 ? round(min(100, $indexed / $total * 100), 1) : null,
            'provider' => $this->embeddings->provider(),
            'can_embed' => $this->embeddings->available(),
        ];
    }

    /** @return array<string, mixed> */
    public function shape(): array
    {
        return [
            'sources' => array_map(fn ($k, $v) => ['id' => $k, 'label' => $v], array_keys(self::SOURCE_LABELS), self::SOURCE_LABELS),
            'limits' => [
                'max_scan' => self::MAX_SCAN,
                'max_index' => self::MAX_INDEX,
                'query_max' => self::QUERY_MAX,
                'min_score' => self::MIN_SCORE,
                'query_limit' => self::QUERY_LIMIT,
            ],
        ];
    }

    /** float32 đóng gói — 1.536 chiều là 6 KB thay vì ~15 KB JSON (xem migration). */
    public function pack(array $vector): string
    {
        return pack('g*', ...array_map(fn ($v) => (float) $v, $vector));
    }

    /** @return list<float> */
    public function unpack(string $binary, int $dims): array
    {
        if ($binary === '' || $dims <= 0 || strlen($binary) < $dims * 4) {
            return [];
        }

        return array_values(unpack('g*', substr($binary, 0, $dims * 4)) ?: []);
    }

    /** Cosine — hàm THUẦN, test được không cần DB. */
    public function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
