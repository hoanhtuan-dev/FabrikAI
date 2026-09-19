<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Design Agents — contract và dữ liệu hợp nhất cho TrendRadar + CollectionBot.
 *
 * HAI TẦNG TÁCH BẠCH (bắt buộc):
 *   · TẦNG SỐ LIỆU — catalog demo + dữ liệu nội bộ của CHÍNH user đang đăng nhập.
 *     Luôn khai báo source_mode=demo / evidence_mode=demo: connector TMĐT, computer
 *     vision, POS/ERP CHƯA chạy nên không có con số thị trường thật nào ở đây.
 *   · TẦNG SUY LUẬN — model AI của NHÓM CÔNG VIỆC 'prompt' (Cài đặt → Nhóm công việc /
 *     Model Registry / Luồng ưu tiên / Custom Provider) đọc tầng số liệu rồi viết định
 *     hướng, brief, caption mood board, prompt. AI chỉ được trả về CHỮ: mọi con số trong
 *     response vẫn do tầng số liệu quyết định nên AI không thể bịa số liệu thị trường.
 *
 * Không có model khả dụng (hoặc người dùng tắt AI) ⇒ quay về engine tất định và NÓI THẬT
 * bằng khối `model` (mode=rule + lý do cụ thể), không im lặng giả vờ đã dùng AI.
 */
class DesignAgentService
{
    private const REGIONS = ['all', 'hcm', 'hanoi', 'danang'];

    /** Nhóm công việc dùng cho MỌI suy luận của hai agent (Settings → Nhóm công việc). */
    public const AI_GROUP = 'prompt';

    /** Cache định hướng radar theo vùng + model đang cấu hình (tránh gọi lại mỗi lần mở). */
    private const RADAR_CACHE_SECONDS = 600;

    /**
     * Tham số này BẮT BUỘC (kiểu nullable, không có default): container của Laravel KHÔNG tự
     * inject tham số nullable-có-default — nó lấy giá trị default null — nên viết
     * "?AiModelGateway $gateway = null" sẽ khiến service LUÔN chạy ở chế độ tất định dù Cài đặt
     * đã có model. Truyền null tường minh khi cần test tất định thuần PHPUnit.
     */
    public function __construct(private readonly ?AiModelGateway $gateway) {}

    private const SOURCES = [
        [
            'id' => 'ecommerce', 'name' => 'Sàn TMĐT', 'channels' => 'Shopee, TikTok Shop, Lazada',
            'method' => 'Connector TMĐT (chưa bật)', 'frequency' => 'Real-time',
            'status' => 'demo', 'status_label' => 'Dữ liệu mẫu',
        ],
        [
            'id' => 'social', 'name' => 'Mạng xã hội', 'channels' => 'Instagram, TikTok, Pinterest',
            'method' => 'Computer vision (chưa bật)', 'frequency' => 'Hàng giờ',
            'status' => 'demo', 'status_label' => 'Dữ liệu mẫu',
        ],
        [
            'id' => 'international', 'name' => 'Sàn quốc tế', 'channels' => 'SHEIN, TEMU, ASOS',
            'method' => 'Crawler (chưa bật)', 'frequency' => 'Hàng ngày',
            'status' => 'demo', 'status_label' => 'Dữ liệu mẫu',
        ],
        [
            'id' => 'runway', 'name' => 'Runway & Fashion Week', 'channels' => 'Các tuần lễ thời trang',
            'method' => 'Phân tích hình ảnh (chưa bật)', 'frequency' => 'Theo mùa',
            'status' => 'demo', 'status_label' => 'Dữ liệu mẫu',
        ],
        [
            'id' => 'internal', 'name' => 'Dữ liệu nội bộ', 'channels' => 'Project & generation của tài khoản',
            'method' => 'Đọc dữ liệu nội bộ đã có', 'frequency' => 'Real-time',
            'status' => 'local', 'status_label' => 'Dữ liệu nội bộ',
        ],
    ];

    public function radar(?User $user, string $region = 'all', bool $useAi = true): array
    {
        $region = $this->normalizeRegion($region);
        $trends = $this->trendCatalog($region);
        $internal = $this->internalBrandSignal($user);
        $candidates = $this->aiCandidates();

        // Định hướng TẤT ĐỊNH luôn được dựng trước: vừa là kết quả khi không có model,
        // vừa là lưới an toàn nếu model trả về thiếu/không hợp lệ.
        $ruleDirections = $this->ruleDirections($trends);
        [$directions, $model] = $this->radarDirections($trends, $ruleDirections, $candidates, $region, $useAi);

        return [
            'agent' => 'TrendRadar',
            'engine' => $model['mode'] === 'ai' ? 'ai-v1' : 'rule-based-v1',
            'model' => $model,
            'directions' => $directions,
            'source_mode' => 'demo',
            'generated_at' => now()->toISOString(),
            'region' => $region,
            'regions' => [
                ['id' => 'all', 'name' => 'Toàn quốc'],
                ['id' => 'hcm', 'name' => 'TP.HCM'],
                ['id' => 'hanoi', 'name' => 'Hà Nội'],
                ['id' => 'danang', 'name' => 'Đà Nẵng'],
            ],
            'sources' => self::SOURCES,
            'summary' => [
                // Catalog mẫu hiện có 8 hướng; connector/CV/POS-ERP chưa chạy nên không phóng đại sản lượng.
                'tracked_attributes' => 5,
                'images_analyzed_monthly' => 0,
                'active_trends' => count($trends),
                'internal_products' => $internal['product_count'],
                'internal_generations' => $internal['generation_count'],
            ],
            'internal_brand_signal' => $internal,
            'trends' => $trends,
            'methodology' => [
                'color_clustering' => 'Trường màu đã có; connector và CV chưa chạy để gom ảnh thật.',
                'silhouette_detection' => 'Trường dáng đã có; pipeline nhận diện ảnh chưa bật.',
                'fabric_recognition' => 'Trường chất liệu đã có; pipeline nhận diện ảnh chưa bật.',
                'price_band_analysis' => 'Dải giá đề xuất theo brief; chưa đọc giá bán thực tế.',
                'trend_lifecycle' => 'Nhãn demo: emerging → peak → declining.',
                'ai_reasoning' => $model['mode'] === 'ai'
                    ? 'Định hướng do model “'.$model['provider'].':'.$model['model'].'” (nhóm prompt) viết trên đúng dữ liệu mẫu ở trên; số liệu không do AI tạo.'
                    : 'Chưa có model khả dụng cho nhóm “prompt” nên định hướng do engine tất định dựng từ catalog mẫu.',
            ],
        ];
    }

    public function collectionBrief(array $data, ?User $user, bool $useAi = true): array
    {
        $prompt = trim((string) ($data['prompt'] ?? ''));
        $region = $this->normalizeRegion((string) ($data['region'] ?? 'all'));
        $requestedIds = array_values(array_filter(array_map('strval', (array) ($data['trend_ids'] ?? [])), 'strlen'));
        $allTrends = $this->trendCatalog($region);
        $selected = collect($allTrends)
            ->filter(fn (array $trend) => in_array((string) $trend['id'], $requestedIds, true))
            ->values()
            ->all();
        if (!$selected && !$requestedIds) {
            $selected = array_slice($allTrends, 0, 3);
        }

        $brand = $this->internalBrandSignal($user);
        $palette = $this->paletteFor($prompt, $selected);
        $categoryMix = $this->categoryMix($prompt, $brand);
        $moodboard = $this->moodboard($prompt, $selected, $palette);
        $outfits = $this->outfitMatching($palette);
        $priceBands = $this->priceBands($prompt);
        $sizeDistribution = $this->sizeDistribution($data);

        $trendNames = collect($selected)->pluck('title')->filter()->all();
        $trendPhrase = $trendNames ? implode(', ', array_slice($trendNames, 0, 3)) : 'xu hướng đang lên';
        $collectionName = $this->collectionName($prompt, $region);
        $brief = trim((string) ($data['brief'] ?? ''));

        // TẦNG SUY LUẬN — model của nhóm 'prompt' viết narrative / brief / caption mood board /
        // prompt trên ĐÚNG dữ liệu tất định ở trên. Con số (SKU, size, dải giá, cấu trúc) KHÔNG
        // đi qua AI; AI trả về chữ nên không thể bịa số liệu thị trường.
        $ai = $this->aiBrief([
            'prompt' => $prompt,
            'region' => $region,
            'region_name' => $this->regionName($region),
            'season' => $this->seasonTag($prompt),
            'audience' => $this->audienceFor($prompt),
            'brand_narrative' => $brand['narrative'],
            'brand_top_categories' => $brand['top_categories'],
            'brand_top_colors' => $brand['top_colors'],
            'selected_trends' => array_map(fn (array $trend) => [
                'id' => $trend['id'],
                'title' => $trend['title'],
                'category' => $trend['category'],
                'lifecycle' => $trend['lifecycle'],
                'description' => $trend['description'],
                'recommended_action' => $trend['recommended_action'],
                'evidence_mode' => 'demo',
            ], $selected),
            'palette' => $palette,
            'categories' => $categoryMix,
            'outfits' => $outfits,
            'size_distribution' => $sizeDistribution,
            'price_band' => $priceBands,
        ], $this->aiCandidates(), $useAi);

        $aiData = $ai['data'] ?? [];
        $applied = [
            'narrative' => false, 'brief' => false, 'moodboard_captions' => 0,
            'category_rationale' => 0, 'outfit_goals' => 0, 'prompts' => false, 'next_steps' => false,
        ];

        if ($aiData) {
            if (($aiData['narrative'] ?? '') !== '') {
                $brand['narrative'] = $aiData['narrative'];
                $applied['narrative'] = true;
            }
            foreach (($aiData['captions'] ?? []) as $index => $caption) {
                if (isset($moodboard[$index])) {
                    $moodboard[$index]['caption'] = $caption;
                    $applied['moodboard_captions']++;
                }
            }
            foreach ($categoryMix as $index => $row) {
                $key = (string) $row['category'];
                if (($aiData['category_rationale'][$key] ?? '') !== '') {
                    $categoryMix[$index]['rationale'] = $aiData['category_rationale'][$key];
                    $applied['category_rationale']++;
                }
            }
            foreach ($outfits as $index => $row) {
                $key = (string) $row['id'];
                if (($aiData['outfit_goals'][$key] ?? '') !== '') {
                    $outfits[$index]['goal'] = $aiData['outfit_goals'][$key];
                    $applied['outfit_goals']++;
                }
            }
        }

        // Brief người dùng tự viết LUÔN thắng; sau đó mới tới brief của AI; cuối cùng là câu tất định.
        $briefText = $brief !== ''
            ? $brief
            : (($aiData['brief'] ?? '') !== ''
                ? $aiData['brief']
                : sprintf(
                    '%s. Bộ sưu tập hướng tới %s, kết hợp %s và DNA shop (%s). Ưu tiên các sản phẩm dễ phối, có thể sản xuất theo size thực tế và bán ở phân khúc %s.',
                    $prompt,
                    $this->audienceFor($prompt),
                    $trendPhrase,
                    $brand['narrative'],
                    $priceBands['recommended_label'],
                ));
        $applied['brief'] = $brief === '' && ($aiData['brief'] ?? '') !== '';

        $promptVi = ($aiData['prompt_vi'] ?? '') !== '' ? $aiData['prompt_vi'] : $this->promptVi($prompt, $selected, $palette);
        $promptEn = ($aiData['prompt_en'] ?? '') !== '' ? $aiData['prompt_en'] : $this->promptEn($prompt, $selected, $palette);
        $applied['prompts'] = ($aiData['prompt_vi'] ?? '') !== '' || ($aiData['prompt_en'] ?? '') !== '';

        $nextSteps = array_values(array_filter((array) ($aiData['next_steps'] ?? []), 'strlen'));
        $applied['next_steps'] = $nextSteps !== [];

        $canvas = $this->canvasSuggestions($prompt, $promptVi, $promptEn);
        $inputSignature = hash('sha256', json_encode([
            $prompt,
            $region,
            $requestedIds,
            array_map(fn ($row) => $row['size'].':'.$row['count'], $sizeDistribution),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'agent' => 'CollectionBot',
            'engine' => $ai['model']['mode'] === 'ai' ? 'ai-v1' : 'rule-based-v1',
            'model' => $ai['model'],
            'ai_applied' => $applied,
            'generated_at' => now()->toISOString(),
            'input' => [
                'prompt' => $prompt,
                'region' => $region,
                'trend_ids' => $requestedIds,
            ],
            'brand_narrative' => $brand,
            'selected_trends' => $selected,
            'moodboard' => [
                'count' => count($moodboard),
                'items' => $moodboard,
                'layout' => 'grid-24',
            ],
            'palette' => $palette,
            'structure' => [
                'total_skus' => array_sum(array_column($categoryMix, 'count')),
                'categories' => $categoryMix,
                'rationale' => 'Số lượng SKU cân bằng giữa nhóm bán chạy lịch sử và xu hướng đang lên.',
            ],
            'outfit_matching' => $outfits,
            'size_distribution' => $sizeDistribution,
            'price_bands' => $priceBands,
            'brief' => $briefText,
            'prompt_vi' => $promptVi,
            'prompt_en' => $promptEn,
            'canvas' => $canvas,
            'input_signature' => $inputSignature,
            'project_payload' => [
                'name' => Str::limit($collectionName, 255),
                'brief' => Str::limit($briefText, 4000),
                'tags' => array_values(array_slice(array_filter([
                    $this->seasonTag($prompt),
                    $region === 'all' ? 'TrendRadar' : $this->regionName($region),
                ]), 0, 20)),
            ],
            'next_steps' => $nextSteps ?: [
                'Duyệt mood board và bảng màu.',
                'Điều chỉnh số lượng SKU theo tồn kho và năng lực sản xuất.',
                'Áp dụng prompt vào Canvas hoặc tạo bộ sưu tập mới.',
            ],
        ];
    }

    // ───────────────────────── TẦNG SUY LUẬN (AI) ─────────────────────────

    /**
     * Candidate của nhóm công việc 'prompt' — đã tôn trọng Model Registry, luồng ưu tiên
     * provider, custom provider và key đang bật. Rỗng = chưa cấu hình model nào dùng được.
     *
     * @return list<array{provider:string, model:string}>
     */
    private function aiCandidates(): array
    {
        return $this->gateway?->candidates(self::AI_GROUP) ?? [];
    }

    /**
     * Khối "model" mà UI đọc để nói THẬT đang chạy bằng gì: mode=ai|rule, model nào, còn
     * candidate nào, mất bao lâu, có lấy từ cache không, và LÝ DO khi phải quay về rule.
     */
    private function modelBlock(string $mode, array $candidates, array $extra = []): array
    {
        return array_merge([
            'group' => self::AI_GROUP,
            'mode' => $mode,
            'provider' => null,
            'model' => null,
            'candidates' => count($candidates),
            'available' => array_map(fn (array $c) => $c['provider'].':'.$c['model'], $candidates),
            'latency_ms' => null,
            'cached' => false,
            'reason' => $mode === 'ai' ? null : ($candidates === [] ? 'no_model_key' : 'ai_disabled'),
        ], $extra);
    }

    /**
     * Định hướng của TrendRadar: 5-10 hướng. AI viết khi có model; nếu AI lỗi/thiếu hướng thì
     * bù bằng hướng tất định để KHÔNG bao giờ trả về danh sách rỗng.
     *
     * @return array{0: list<array>, 1: array}
     */
    private function radarDirections(array $trends, array $ruleDirections, array $candidates, string $region, bool $useAi): array
    {
        if (! $useAi || $this->gateway === null || $candidates === []) {
            return [$ruleDirections, $this->modelBlock('rule', $candidates)];
        }

        // LƯU Ý QUAN TRỌNG: chỉ gửi catalog của VÙNG (không gửi tín hiệu nội bộ của shop) nên
        // cache dùng chung giữa các tài khoản là an toàn — dữ liệu nội bộ của người dùng không
        // bao giờ rời khỏi tài khoản, kể cả khi hai người mở cùng một khu vực.
        $fingerprint = md5(implode('|', array_map(fn (array $c) => $c['provider'].':'.$c['model'], $candidates)));
        $cacheKey = 'design-agent:radar:v1:'.$region.':'.$fingerprint;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached['directions'])) {
            return [$cached['directions'], $this->modelBlock('ai', $candidates, [
                'provider' => $cached['provider'] ?? null,
                'model' => $cached['model'] ?? null,
                'latency_ms' => 0,
                'cached' => true,
            ])];
        }

        $instruction = 'Bạn là TrendRadar — chuyên gia phân tích xu hướng thời trang Việt Nam cho xưởng may và thương hiệu nhỏ. '
            .'Bạn CHỈ được suy luận từ đúng khối DỮ LIỆU bên dưới (danh mục xu hướng mẫu + tín hiệu nội bộ của shop). '
            .'TUYỆT ĐỐI KHÔNG bịa số liệu thị trường và KHÔNG được nói như thể đã đọc Shopee, TikTok, Instagram, SHEIN, TEMU, ASOS hay Runway — '
            .'các connector đó CHƯA được kết nối, dữ liệu là mẫu. Không tự nghĩ ra mã xu hướng mới ngoài danh mục. '
            .'Nhiệm vụ: viết 5-10 ĐỊNH HƯỚNG hành động cho khu vực "'.$region.'", mỗi định hướng bám vào 1-3 id xu hướng CÓ THẬT trong dữ liệu. '
            .'Chỉ trả về JSON đúng dạng: {"directions":[{"title":"...","thesis":"...","why_now":"...","action":"...","risk":"...","price_band":"entry|mid|premium","confidence":0.8,"trend_ids":["id-co-that"]}]}. '
            .'Viết tiếng Việt, ngắn gọn, cụ thể, có thể hành động ngay: MỖI trường tối đa 25 từ, KHÔNG xuống dòng trong giá trị, KHÔNG thêm chữ nào ngoài JSON.';

        $started = microtime(true);
        $answer = $this->gateway->text(self::AI_GROUP, [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => "DỮ LIỆU:\n".json_encode([
                'region' => $region,
                'region_name' => $this->regionName($region),
                'data_mode' => 'demo',
                'trends' => array_map(fn (array $trend) => [
                    'id' => $trend['id'],
                    'title' => $trend['title'],
                    'category' => $trend['category'],
                    'lifecycle' => $trend['lifecycle'],
                    'momentum' => $trend['momentum'],
                    'confidence' => $trend['confidence'],
                    'description' => $trend['description'],
                    'recommended_action' => $trend['recommended_action'],
                ], $trends),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ], ['response_format' => 'json_object', 'max_tokens' => 3000, 'timeout' => 60]);
        $latency = (int) round((microtime(true) - $started) * 1000);
        $attempted = $candidates[0]['provider'].':'.$candidates[0]['model'];

        if ($answer === null) {
            logger()->warning('TrendRadar: model không trả về nội dung, dùng engine tất định', ['group' => self::AI_GROUP, 'attempted' => $attempted]);

            return [$ruleDirections, $this->modelBlock('rule', $candidates, ['reason' => 'model_error', 'latency_ms' => $latency, 'attempted' => $attempted])];
        }

        $directions = $this->normalizeDirections($this->decodeJson($answer['text']), $trends, $ruleDirections);
        if ($directions === []) {
            // Ghi lại ĐẦU ra thô (đã cắt) để lần sau biết chính xác vì sao không dùng được —
            // đầu vào chỉ là catalog mẫu nên không có dữ liệu riêng của người dùng ở đây.
            logger()->warning('TrendRadar: model trả về định hướng không hợp lệ, dùng engine tất định', [
                'attempted' => $attempted,
                'provider' => $answer['provider'],
                'model' => $answer['model'],
                'raw' => substr($answer['text'], 0, 800),
            ]);

            return [$ruleDirections, $this->modelBlock('rule', $candidates, ['reason' => 'invalid_output', 'latency_ms' => $latency, 'attempted' => $attempted])];
        }

        Cache::put($cacheKey, [
            'directions' => $directions,
            'provider' => $answer['provider'],
            'model' => $answer['model'],
        ], self::RADAR_CACHE_SECONDS);

        return [$directions, $this->modelBlock('ai', $candidates, [
            'provider' => $answer['provider'],
            'model' => $answer['model'],
            'latency_ms' => $latency,
        ])];
    }

    /**
     * Định hướng tất định từ catalog mẫu — nguồn duy nhất khi chưa có model, và là lưới an toàn
     * khi model trả thiếu hướng. Mọi con số ở đây đến từ catalog (evidence_mode=demo).
     *
     * @return list<array>
     */
    private function ruleDirections(array $trends): array
    {
        $rows = [];
        foreach (array_slice($trends, 0, 8) as $index => $trend) {
            $rows[] = [
                'id' => 'dir-rule-'.($index + 1),
                'title' => (string) $trend['title'],
                'thesis' => (string) $trend['description'],
                'why_now' => 'Momentum mẫu '.$trend['momentum'].'/100 · '.number_format((int) $trend['evidence_count']).' tín hiệu mẫu (demo).',
                'action' => (string) $trend['recommended_action'],
                'risk' => $this->riskFor((string) $trend['lifecycle']),
                'price_band' => null,
                'confidence' => (float) $trend['confidence'],
                'trend_ids' => [(string) $trend['id']],
                'source' => 'rule',
            ];
        }

        return $rows;
    }

    private function riskFor(string $lifecycle): string
    {
        return match ($lifecycle) {
            'declining' => 'Tăng trưởng đã chậm lại — chỉ nên dùng làm màu/vải nền, không dồn SKU.',
            'peak' => 'Đang ở đỉnh nên cạnh tranh giá cao — cần khác biệt ở chất liệu và chi tiết.',
            default => 'Chưa kiểm chứng ở quy mô lớn — nên sản xuất số lượng nhỏ rồi đo lại.',
        };
    }

    /**
     * Chuẩn hoá định hướng do model trả về: chỉ nhận CHỮ + id xu hướng có thật, cắt độ dài,
     * và bù bằng hướng tất định nếu AI trả về ít hơn 5 hướng.
     *
     * @return list<array>
     */
    private function normalizeDirections(?array $json, array $trends, array $ruleDirections): array
    {
        if (! is_array($json)) {
            return [];
        }

        $rows = $json['directions'] ?? $json;
        if (! is_array($rows)) {
            return [];
        }

        $knownIds = array_column($trends, 'id');
        $bands = ['entry', 'mid', 'premium'];
        $out = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = Str::limit(trim((string) ($row['title'] ?? '')), 120, '');
            if ($title === '') {
                continue;
            }
            $confidence = is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : null;
            if ($confidence !== null) {
                $confidence = max(0.0, min(1.0, $confidence > 1 ? $confidence / 100 : $confidence));
            }
            $out[] = [
                'id' => 'dir-ai-'.($index + 1),
                'title' => $title,
                'thesis' => Str::limit(trim((string) ($row['thesis'] ?? '')), 400, ''),
                'why_now' => Str::limit(trim((string) ($row['why_now'] ?? '')), 400, ''),
                'action' => Str::limit(trim((string) ($row['action'] ?? '')), 400, ''),
                'risk' => Str::limit(trim((string) ($row['risk'] ?? '')), 400, ''),
                'price_band' => in_array((string) ($row['price_band'] ?? ''), $bands, true) ? (string) $row['price_band'] : null,
                'confidence' => $confidence,
                'trend_ids' => array_values(array_intersect(
                    array_map('strval', (array) ($row['trend_ids'] ?? [])),
                    $knownIds,
                )),
                'source' => 'ai',
            ];
            if (count($out) >= 10) {
                break;
            }
        }

        if (count($out) < 3) {
            return [];   // model trả về quá ít hướng dùng được ⇒ coi như hỏng, quay về tất định
        }

        // Bù cho đủ 5 hướng tối thiểu bằng hướng tất định (khác tiêu đề), tối đa 10.
        foreach ($ruleDirections as $fallback) {
            if (count($out) >= 5) {
                break;
            }
            if (collect($out)->contains(fn (array $item) => $item['title'] === $fallback['title'])) {
                continue;
            }
            $out[] = $fallback;
        }

        return array_slice($out, 0, 10);
    }

    /**
     * Gọi model nhóm 'prompt' để viết phần CHỮ của brief bộ sưu tập.
     *
     * @return array{model: array, data: ?array}
     */
    private function aiBrief(array $context, array $candidates, bool $useAi): array
    {
        if (! $useAi || $this->gateway === null || $candidates === []) {
            return ['model' => $this->modelBlock('rule', $candidates), 'data' => null];
        }

        $instruction = 'Bạn là CollectionBot — trưởng phòng thiết kế bộ sưu tập thời trang Việt Nam. '
            .'Bạn CHỈ được dùng đúng khối DỮ LIỆU bên dưới (DNA shop, xu hướng đã chọn, bảng màu, cơ cấu SKU, phối đồ, phân bổ size, dải giá). '
            .'TUYỆT ĐỐI KHÔNG bịa số liệu bán hàng, không nhắc tới việc đã kết nối Shopee/TikTok/POS/ERP (chưa có connector thật). '
            .'Không đổi bất kỳ con số nào — cơ cấu SKU, size và dải giá là do hệ thống quyết định. '
            .'Chỉ trả về MỘT object JSON đúng dạng: {"narrative":"...","brief":"...","moodboard_captions":["... x24"],'
            .'"category_rationale":{"TÊN NHÓM":"..."},"outfit_goals":{"look-1":"..."},"prompt_vi":"...","prompt_en":"...","next_steps":["...","...","..."]}. '
            .'narrative: 1-2 câu DNA/định vị. brief: 3-5 câu tiếng Việt cho xưởng. moodboard_captions: ĐÚNG 24 caption ngắn tiếng Việt theo thứ tự ô. '
            .'category_rationale: mỗi nhóm hàng 1 câu, dùng ĐÚNG tên nhóm trong dữ liệu. outfit_goals: mỗi look 1 câu, dùng ĐÚNG id look trong dữ liệu. '
            .'prompt_vi: 1 đoạn mô tả ảnh tiếng Việt. prompt_en: 1 đoạn prompt ảnh tiếng Anh giàu chi tiết (chất liệu, dáng, ánh sáng, bố cục). '
            .'next_steps: đúng 3 việc cần làm tiếp.';

        $started = microtime(true);
        $answer = $this->gateway->text(self::AI_GROUP, [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => "DỮ LIỆU:\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ], ['response_format' => 'json_object', 'max_tokens' => 4000, 'timeout' => 75]);
        $latency = (int) round((microtime(true) - $started) * 1000);
        $attempted = $candidates[0]['provider'].':'.$candidates[0]['model'];

        if ($answer === null) {
            logger()->warning('CollectionBot: model không trả về nội dung, dùng engine tất định', ['attempted' => $attempted]);

            return ['model' => $this->modelBlock('rule', $candidates, ['reason' => 'model_error', 'latency_ms' => $latency, 'attempted' => $attempted]), 'data' => null];
        }

        $data = $this->normalizeAiBrief($this->decodeJson($answer['text']));
        if ($data === null) {
            logger()->warning('CollectionBot: model trả về JSON không dùng được, dùng engine tất định', [
                'attempted' => $attempted,
                'provider' => $answer['provider'],
                'model' => $answer['model'],
                'raw' => substr($answer['text'], 0, 800),
            ]);

            return ['model' => $this->modelBlock('rule', $candidates, ['reason' => 'invalid_output', 'latency_ms' => $latency, 'attempted' => $attempted]), 'data' => null];
        }

        return [
            'model' => $this->modelBlock('ai', $candidates, [
                'provider' => $answer['provider'],
                'model' => $answer['model'],
                'latency_ms' => $latency,
            ]),
            'data' => $data,
        ];
    }

    /** Chuẩn hoá phần chữ do model trả về; null = không có gì dùng được. */
    private function normalizeAiBrief(?array $json): ?array
    {
        if (! is_array($json)) {
            return null;
        }

        $line = fn ($value, int $limit) => is_string($value) ? Str::limit(trim($value), $limit, '') : '';
        $narrative = $line($json['narrative'] ?? '', 600);
        $brief = $line($json['brief'] ?? '', 2000);
        if ($narrative === '' && $brief === '') {
            return null;
        }

        $captions = [];
        foreach ((array) ($json['moodboard_captions'] ?? []) as $caption) {
            $captions[] = $line($caption, 220);
        }
        $captions = array_slice(array_values(array_filter($captions, 'strlen')), 0, 24);

        $map = function ($value, int $limit): array {
            $out = [];
            foreach ((array) $value as $key => $text) {
                $key = Str::limit(trim((string) $key), 120, '');
                $text = is_string($text) ? Str::limit(trim($text), $limit, '') : '';
                if ($key !== '' && $text !== '') {
                    $out[$key] = $text;
                }
            }

            return $out;
        };

        $nextSteps = [];
        foreach ((array) ($json['next_steps'] ?? []) as $step) {
            $step = $line($step, 240);
            if ($step !== '') {
                $nextSteps[] = $step;
            }
        }

        return [
            'narrative' => $narrative,
            'brief' => $brief,
            'captions' => $captions,
            'category_rationale' => $map($json['category_rationale'] ?? [], 240),
            'outfit_goals' => $map($json['outfit_goals'] ?? [], 240),
            'prompt_vi' => $line($json['prompt_vi'] ?? '', 1200),
            'prompt_en' => $line($json['prompt_en'] ?? '', 1200),
            'next_steps' => array_slice($nextSteps, 0, 5),
        ];
    }

    /**
     * Đọc JSON từ model — chịu được 3 kiểu trả về thường gặp của LLM:
     *   1. JSON sạch;
     *   2. JSON bọc trong code fence hoặc có lời dẫn quanh nó;
     *   3. JSON bị CẮT vì hết token (finish_reason=length) — cắt về phần tử hoàn chỉnh cuối
     *      cùng rồi đóng nốt ngoặc còn mở. Không có bước này thì một câu trả lời dài hơn dự kiến
     *      sẽ âm thầm đẩy cả agent về engine tất định dù model hoàn toàn bình thường.
     */
    private function decodeJson(string $text): ?array
    {
        $text = trim($text);
        $json = json_decode($text, true);
        if (is_array($json)) {
            return $json;
        }

        // (2) Bỏ code fence nếu có.
        if (preg_match('/\x60{3}(?:json)?\s*([\s\S]*?)\x60{3}/i', $text, $m) === 1) {
            $inner = trim($m[1]);
            $json = json_decode($inner, true);
            if (is_array($json)) {
                return $json;
            }
            $text = $inner;
        }

        // (3) Trích object JSON đầu tiên (đếm ngoặc, bỏ qua ngoặc nằm trong chuỗi).
        $snippet = $this->jsonObjectSnippet($text);
        if ($snippet !== null) {
            $json = json_decode($snippet, true);
            if (is_array($json)) {
                return $json;
            }
            $repaired = $this->repairTruncatedJson($snippet);
            if ($repaired !== null) {
                $json = json_decode($repaired, true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }

        return null;
    }

    /** Trích object JSON đầu tiên trong một chuỗi văn bản (bỏ qua ngoặc bên trong chuỗi). */
    private function jsonObjectSnippet(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($i = $start, $len = strlen($text); $i < $len; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        // Chưa đóng ngoặc ⇒ nhiều khả năng bị cắt vì hết token: trả phần còn lại để bước sau cứu.
        return substr($text, $start);
    }

    /** Cứu JSON bị cắt: cắt về phần tử hoàn chỉnh cuối cùng rồi đóng nốt ngoặc còn mở. */
    private function repairTruncatedJson(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $text = substr($text, $start);

        [$open, $lastSafeEnd] = $this->scanJsonStructure($text);
        if ($open === []) {
            return null;   // ngoặc đã cân bằng ⇒ lỗi không phải do bị cắt
        }
        if ($lastSafeEnd === null) {
            return null;   // chưa có phần tử nào hoàn chỉnh để cắt về
        }

        $body = substr($text, 0, $lastSafeEnd);
        [$open] = $this->scanJsonStructure($body);
        $closers = '';
        foreach (array_reverse($open) as $char) {
            $closers .= $char === '{' ? '}' : ']';
        }

        return $body.$closers;
    }

    /**
     * Quét cấu trúc JSON: trả về [danh sách ngoặc còn mở, vị trí kết thúc AN TOÀN cuối cùng]
     * — bỏ qua mọi ký tự nằm trong chuỗi và ký tự được escape.
     *
     * @return array{0: list<string>, 1: ?int}
     */
    private function scanJsonStructure(string $text): array
    {
        $open = [];
        $inString = false;
        $escaped = false;
        $lastSafeEnd = null;
        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $open[] = $char;
            } elseif ($char === '}' || $char === ']') {
                array_pop($open);
                $lastSafeEnd = $i + 1;
            }
        }

        return [$open, $lastSafeEnd];
    }

    /** Danh sách ID ổn định để controller có thể validate trước khi gọi service. */
    public function trendIds(): array
    {
        return array_column($this->trendCatalog('all'), 'id');
    }

    /** Catalog mẫu cho UI và validation; thay bằng connector thật mà không đổi schema. */
    public function trendCatalog(string $region = 'all'): array
    {
        $region = $this->normalizeRegion($region);
        $base = [
            ['id' => 'soft-pastel', 'title' => 'Pastel dịu', 'category' => 'color', 'lifecycle' => 'emerging', 'momentum' => 86, 'confidence' => 0.84, 'evidence_count' => 18420, 'color' => '#d9c7f2', 'region' => 'all', 'description' => 'Oải hương, hồng đất và xanh bạc hà tạo cảm giác nhẹ, dễ mặc cho văn phòng.', 'recommended_action' => 'Kết hợp linen/cotton và phom rộng vừa.'],
            ['id' => 'clean-tailoring', 'title' => 'Tailoring tối giản', 'category' => 'silhouette', 'lifecycle' => 'peak', 'momentum' => 91, 'confidence' => 0.9, 'evidence_count' => 24180, 'color' => '#b9c8c2', 'region' => 'all', 'description' => 'Áo blazer, quần ống đứng và đường nét tinh gọn tiếp tục có tín hiệu chuyển đổi tốt.', 'recommended_action' => 'Tạo hero SKU dễ phối, màu trung tính.'],
            ['id' => 'linen-breeze', 'title' => 'Linen thoáng', 'category' => 'fabric', 'lifecycle' => 'peak', 'momentum' => 88, 'confidence' => 0.87, 'evidence_count' => 16940, 'color' => '#e5d7bd', 'region' => 'all', 'description' => 'Linen và vải dệt thoáng được tìm kiếm mạnh cho mùa nóng và phong cách tối giản.', 'recommended_action' => 'Ưu tiên màu ivory, beige và form có độ thở.'],
            ['id' => 'wide-leg', 'title' => 'Quần ống rộng', 'category' => 'silhouette', 'lifecycle' => 'peak', 'momentum' => 82, 'confidence' => 0.81, 'evidence_count' => 13750, 'color' => '#9ca9a2', 'region' => 'all', 'description' => 'Ống rộng vừa phải, cạp cao và chiều dài chạm mắt cá dễ ứng dụng cho nhiều dáng người.', 'recommended_action' => 'Phối cùng áo cropped hoặc áo sơ mi thả.'],
            ['id' => 'butter-yellow', 'title' => 'Vàng bơ', 'category' => 'color', 'lifecycle' => 'emerging', 'momentum' => 78, 'confidence' => 0.76, 'evidence_count' => 9210, 'color' => '#f4d98d', 'region' => 'all', 'description' => 'Màu nhấn ấm, dễ dùng cho áo và phụ kiện, phù hợp với nền pastel.', 'recommended_action' => 'Dùng làm accent, không nên chiếm toàn bộ bộ.'],
            ['id' => 'office-midi', 'title' => 'Váy midi công sở', 'category' => 'silhouette', 'lifecycle' => 'emerging', 'momentum' => 74, 'confidence' => 0.79, 'evidence_count' => 8120, 'color' => '#c8d1d5', 'region' => 'all', 'description' => 'Váy midi có độ che vừa, dễ mặc và phù hợp với nhu cầu mặc cả ngày.', 'recommended_action' => 'Thêm biến thể chất liệu và màu trung tính.'],
            ['id' => 'quiet-shine', 'title' => 'Quiet shine', 'category' => 'fabric', 'lifecycle' => 'emerging', 'momentum' => 69, 'confidence' => 0.72, 'evidence_count' => 6640, 'color' => '#d8c9b8', 'region' => 'all', 'description' => 'Bề mặt lụa mờ hoặc satin nhẹ tạo điểm nhấn mà vẫn giữ tổng thể tối giản.', 'recommended_action' => 'Giới hạn ở một nhóm sản phẩm để kiểm soát giá.'],
            ['id' => 'earth-neutral', 'title' => 'Neutral đất', 'category' => 'color', 'lifecycle' => 'declining', 'momentum' => 48, 'confidence' => 0.83, 'evidence_count' => 21050, 'color' => '#a98f77', 'region' => 'all', 'description' => 'Màu đất vẫn bán được nhưng tốc độ tăng đã chậm lại; nên dùng làm nền, không làm trend chính.', 'recommended_action' => 'Giữ làm màu nền và giảm depth SKU.'],
        ];

        $boosts = [
            'hcm' => ['soft-pastel' => 5, 'linen-breeze' => 7, 'butter-yellow' => 4],
            'hanoi' => ['clean-tailoring' => 6, 'office-midi' => 5, 'quiet-shine' => 3],
            'danang' => ['linen-breeze' => 8, 'wide-leg' => 4, 'soft-pastel' => 3],
        ][$region] ?? [];

        return collect($base)
            ->map(function (array $trend) use ($region, $boosts) {
                $delta = $boosts[$trend['id']] ?? 0;
                $trend['region'] = $region;
                $trend['momentum'] = min(99, max(1, (int) $trend['momentum'] + $delta));
                $trend['regional_note'] = $region === 'all'
                    ? 'Tín hiệu tổng hợp toàn quốc.'
                    : 'Tín hiệu được điều chỉnh theo khu vực đã chọn.';
                $trend['evidence_mode'] = 'demo';
                return $trend;
            })
            ->sortByDesc('momentum')
            ->values()
            ->all();
    }

    private function internalBrandSignal(?User $user): array
    {
        if (!$user) {
            return [
                'product_count' => 0, 'generation_count' => 0, 'approved_count' => 0,
                'narrative' => 'Chưa có dữ liệu shop; đang dùng DNA mặc định: tối giản, dễ phối, chất liệu thoáng.',
                'top_categories' => [], 'top_colors' => [],
            ];
        }

        $projects = $user->projects()->count();
        // Chỉ lấy một mẫu có kiểm soát; không đọc toàn bộ lịch sử prompt của user.
        $generations = $user->generations()
            ->whereNotNull('prompt')
            ->orderByDesc('id')
            ->limit(120)
            ->get(['prompt', 'shot_state']);
        $approved = $generations->where('shot_state', Generation::SHOT_APPROVED)->count();
        $text = mb_strtolower($generations->pluck('prompt')->implode(' '));
        $colorCandidates = ['pastel', 'beige', 'ivory', 'white', 'đen', 'trắng', 'xanh', 'hồng', 'vàng'];
        $categoryCandidates = ['áo', 'blouse', 'váy', 'quần', 'phụ kiện', 'linen', 'cotton', 'lụa'];
        $topColors = collect($colorCandidates)->filter(fn ($word) => str_contains($text, $word))->take(4)->values()->all();
        $topCategories = collect($categoryCandidates)->filter(fn ($word) => str_contains($text, $word))->take(5)->values()->all();
        $narrative = $topCategories || $topColors
            ? sprintf('DNA shop hiện có %s và thiên về màu %s.', implode(', ', $topCategories) ?: 'sản phẩm dễ phối', implode(', ', $topColors) ?: 'trung tính')
            : 'Chưa đủ lịch sử; dùng DNA mặc định: tối giản, dễ phối, chất liệu thoáng.';

        return [
            'product_count' => $projects,
            'generation_count' => $generations->count(),
            'approved_count' => $approved,
            'narrative' => $narrative,
            'top_categories' => $topCategories,
            'top_colors' => $topColors,
        ];
    }

    private function paletteFor(string $prompt, array $trends): array
    {
        $colors = [
            ['name' => 'Ivory', 'hex' => '#f4efe6', 'role' => 'nền'],
            ['name' => 'Pastel lavender', 'hex' => '#d9c7f2', 'role' => 'màu chính'],
            ['name' => 'Sage', 'hex' => '#b9c8c2', 'role' => 'màu phối'],
            ['name' => 'Butter', 'hex' => '#f4d98d', 'role' => 'điểm nhấn'],
            ['name' => 'Mocha', 'hex' => '#a98f77', 'role' => 'neo trung tính'],
        ];
        $lower = mb_strtolower($prompt);
        if (str_contains($lower, 'trắng') || str_contains($lower, 'white')) {
            $colors[0]['role'] = 'màu chính';
        }
        if (str_contains($lower, 'xanh') || str_contains($lower, 'blue')) {
            $colors[2] = ['name' => 'Mist blue', 'hex' => '#c8d7e5', 'role' => 'màu phối'];
        }
        if (str_contains($lower, 'hồng') || str_contains($lower, 'pink')) {
            $colors[1] = ['name' => 'Dusty pink', 'hex' => '#e8c4ca', 'role' => 'màu chính'];
        }
        foreach ($trends as $trend) {
            if (!empty($trend['color']) && !collect($colors)->contains('hex', $trend['color'])) {
                $colors[] = ['name' => (string) ($trend['title'] ?? 'Trend color'), 'hex' => (string) $trend['color'], 'role' => 'gợi ý radar'];
            }
        }

        return array_slice($colors, 0, 7);
    }

    private function categoryMix(string $prompt, array $brand): array
    {
        $lower = mb_strtolower($prompt);
        $base = [
            ['category' => 'Áo / blouse', 'count' => 4, 'rationale' => 'Nhóm dễ thử biến thể và có tần suất mặc cao.'],
            ['category' => 'Quần', 'count' => 3, 'rationale' => 'Cân bằng bộ và tăng giá trị đơn hàng.'],
            ['category' => 'Váy', 'count' => 3, 'rationale' => 'Hero SKU cho mood board và lookbook.'],
            ['category' => 'Phụ kiện', 'count' => 2, 'rationale' => 'Tăng khả năng phối và cross-sell.'],
        ];
        if (str_contains($lower, 'váy') || str_contains($lower, 'dress')) {
            $base[2]['count'] = 5;
            $base[0]['count'] = 3;
        }
        if (str_contains($lower, 'quần') || str_contains($lower, 'pants')) {
            $base[1]['count'] = 5;
            $base[3]['count'] = 1;
        }
        if (str_contains($lower, 'công sở') || str_contains($lower, 'văn phòng')) {
            $base[0]['count'] = 5;
            $base[2]['count'] = 2;
        }
        $total = array_sum(array_column($base, 'count')) ?: 1;
        foreach ($base as &$row) {
            $row['share'] = (int) round($row['count'] / $total * 100);
        }
        return $base;
    }

    private function moodboard(string $prompt, array $trends, array $palette): array
    {
        $labels = ['Silhouette', 'Color story', 'Fabric', 'Detail', 'Styling', 'Runway cue', 'Office wear', 'Texture'];
        $items = [];
        for ($i = 0; $i < 24; $i++) {
            $color = $palette[$i % count($palette)];
            $trend = $trends[$i % max(1, count($trends))] ?? null;
            $items[] = [
                'id' => 'mood-'.$i,
                'label' => $labels[$i % count($labels)],
                'caption' => $trend ? ($trend['title'].' · '.$trend['description']) : $prompt,
                'color' => $color['hex'],
                'role' => $color['role'],
                'source' => $trend ? 'TrendRadar' : 'CollectionBot',
                'image_url' => null,
            ];
        }
        return $items;
    }

    private function outfitMatching(array $palette): array
    {
        return [
            ['id' => 'look-1', 'name' => 'Office soft', 'items' => ['Blouse linen', 'Quần ống rộng', 'Giày minimal'], 'palette' => [$palette[0]['hex'], $palette[2]['hex']], 'goal' => 'Look chủ đạo dễ bán'],
            ['id' => 'look-2', 'name' => 'Weekend pastel', 'items' => ['Váy midi', 'Áo khoác nhẹ', 'Túi nhỏ'], 'palette' => [$palette[1]['hex'], $palette[3]['hex']], 'goal' => 'Tăng giá trị đơn hàng'],
            ['id' => 'look-3', 'name' => 'Quiet shine evening', 'items' => ['Áo satin mờ', 'Quần tailoring', 'Phụ kiện kim loại mềm'], 'palette' => [$palette[4]['hex'], $palette[0]['hex']], 'goal' => 'Biến thể cao cấp'],
        ];
    }

    private function sizeDistribution(array $data): array
    {
        $custom = (array) ($data['size_distribution'] ?? []);
        $base = ['S' => 20, 'M' => 35, 'L' => 30, 'XL' => 15];
        foreach ($custom as $size => $value) {
            $size = strtoupper(substr((string) $size, 0, 8));
            $number = (int) $value;
            if ($size !== '' && $number >= 0) $base[$size] = $number;
        }
        $total = array_sum($base) ?: 100;
        return collect($base)->map(fn ($count, $size) => [
            'size' => $size, 'count' => (int) $count, 'share' => (int) round(((int) $count / $total) * 100),
        ])->values()->all();
    }

    private function priceBands(string $prompt): array
    {
        $lower = mb_strtolower($prompt);
        if (str_contains($lower, 'cao cấp') || str_contains($lower, 'luxury') || str_contains($lower, 'premium')) {
            return ['recommended' => 'premium', 'recommended_label' => 'Premium', 'min_vnd' => 900000, 'max_vnd' => 1800000, 'rationale' => 'Chất liệu và độ hoàn thiện cho phép định vị cao hơn.'];
        }
        if (str_contains($lower, 'giá rẻ') || str_contains($lower, 'bình dân')) {
            return ['recommended' => 'entry', 'recommended_label' => 'Entry', 'min_vnd' => 250000, 'max_vnd' => 550000, 'rationale' => 'Tập trung volume và phối lớp cơ bản.'];
        }
        return ['recommended' => 'mid', 'recommended_label' => 'Mid-range', 'min_vnd' => 550000, 'max_vnd' => 1200000, 'rationale' => 'Cân bằng chất liệu, độ dễ mặc và biên lợi nhuận.'];
    }

    private function promptVi(string $prompt, array $trends, array $palette): string
    {
        $colors = collect($palette)->pluck('name')->join(', ');
        $trend = collect($trends)->pluck('title')->join(', ');
        return trim(sprintf('%s. Phong cách tối giản, dễ phối; ưu tiên đường nét tinh gọn, chất liệu thoáng và bảng màu: %s. Gợi ý từ radar: %s.', $prompt, $colors, $trend));
    }

    private function promptEn(string $prompt, array $trends, array $palette): string
    {
        $colors = collect($palette)->pluck('name')->join(', ');
        $fabrics = str_contains(mb_strtolower($prompt), 'linen') ? 'lightweight linen and cotton' : 'breathable natural fabric';
        return trim(sprintf('%s. Minimal wearable fashion, clean tailoring, soft natural light, %s palette, %s, cohesive office-to-weekend styling, premium editorial look.', $prompt, $colors, $fabrics));
    }

    private function collectionName(string $prompt, string $region): string
    {
        $season = $this->seasonTag($prompt);
        $area = $this->regionName($region);
        $word = Str::of($prompt)
            ->replaceMatches('/^\s*bộ\s+sưu\s+tập\s*/iu', '')
            ->replace(['cho', 'phong cách', 'tông màu', 'chất liệu', 'nữ', 'nam', 'người', 'mặc'], ' ')
            ->replaceMatches('/\b(mùa\s+)?(hè|summer|thu|winter|đông|xuân|spring)\b/iu', ' ')
            ->replaceMatches('/[^\pL\pN ]+/u', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->words(2, '')
            ->toString();
        $label = trim($word) === '' ? 'Collection' : ucwords(mb_strtolower(trim($word)));

        return trim(sprintf('Bộ sưu tập %s %s %s', $label, $season, $area));
    }

    private function seasonTag(string $prompt): string
    {
        $lower = mb_strtolower($prompt);
        if (str_contains($lower, 'hè') || str_contains($lower, 'summer')) return 'Hè';
        if (str_contains($lower, 'thu') || str_contains($lower, 'winter') || str_contains($lower, 'đông')) return 'Thu Đông';
        if (str_contains($lower, 'xuân') || str_contains($lower, 'spring')) return 'Xuân';
        return 'Seasonless';
    }

    private function regionName(string $region): string
    {
        return ['hcm' => 'TP.HCM', 'hanoi' => 'Hà Nội', 'danang' => 'Đà Nẵng'][$region] ?? 'Toàn quốc';
    }

    private function audienceFor(string $prompt): string
    {
        $lower = mb_strtolower($prompt);
        if (str_contains($lower, 'văn phòng') || str_contains($lower, 'công sở')) return 'nữ văn phòng cần sự tinh gọn và dễ phối';
        if (str_contains($lower, 'tuổi teen') || str_contains($lower, 'trẻ')) return 'khách trẻ thích màu mới và năng lượng';
        return 'khách hàng cần sản phẩm mặc được nhiều dịp';
    }

    /**
     * Gợi ý cấu hình Canvas đi kèm brief — chỉ là gợi ý, người dùng vẫn đổi được trước khi tạo ảnh.
     * Cố ý KHÔNG tự đổi resolution để không làm tăng chi phí credit ngoài ý muốn.
     */
    private function canvasSuggestions(string $prompt, string $promptVi, string $promptEn): array
    {
        $lower = mb_strtolower($prompt);
        $ratio = '4:5';
        if (str_contains($lower, 'story') || str_contains($lower, 'dọc') || str_contains($lower, 'tiktok')) {
            $ratio = '9:16';
        } elseif (str_contains($lower, 'lookbook') || str_contains($lower, 'catalogue') || str_contains($lower, 'catalog')) {
            $ratio = '4:3';
        }
        $variants = (str_contains($lower, 'biến thể') || str_contains($lower, 'nhiều phương án')) ? 4 : 2;

        return [
            'ratio' => $ratio,
            'resolution' => '1K',
            'variant_count' => $variants,
            'negative_prompt' => 'blurry, low quality, distorted proportions, extra limbs, deformed hands, watermark, text, logo',
            'prompt_vi' => $promptVi,
            'prompt_en' => $promptEn,
            'note' => 'Gợi ý cấu hình Canvas: tỉ lệ, số biến thể và negative prompt. Bạn có thể đổi lại trước khi tạo ảnh.',
        ];
    }

    private function normalizeRegion(string $region): string
    {
        return in_array($region, self::REGIONS, true) ? $region : 'all';
    }
}
