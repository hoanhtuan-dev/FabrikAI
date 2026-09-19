<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Design Agents — contract và dữ liệu hợp nhất cho TrendRadar + CollectionBot.
 *
 * Các nguồn bên ngoài chưa có connector thật trong bản hiện tại, vì vậy response luôn
 * khai báo rõ source_mode=demo. Dữ liệu nội bộ chỉ đọc project/generation của chính
 * user đang đăng nhập; không có POS/ERP hay scraping thật cho đến khi connector được gắn.
 */
class DesignAgentService
{
    private const REGIONS = ['all', 'hcm', 'hanoi', 'danang'];

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

    public function radar(?User $user, string $region = 'all'): array
    {
        $region = $this->normalizeRegion($region);
        $trends = $this->trendCatalog($region);
        $internal = $this->internalBrandSignal($user);

        return [
            'agent' => 'TrendRadar',
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
            ],
        ];
    }

    public function collectionBrief(array $data, ?User $user): array
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
        $briefText = $brief !== '' ? $brief : sprintf(
            '%s. Bộ sưu tập hướng tới %s, kết hợp %s và DNA shop (%s). Ưu tiên các sản phẩm dễ phối, có thể sản xuất theo size thực tế và bán ở phân khúc %s.',
            $prompt,
            $this->audienceFor($prompt),
            $trendPhrase,
            $brand['narrative'],
            $priceBands['recommended_label'],
        );
        $promptVi = $this->promptVi($prompt, $selected, $palette);
        $promptEn = $this->promptEn($prompt, $selected, $palette);
        $canvas = $this->canvasSuggestions($prompt, $promptVi, $promptEn);
        $inputSignature = hash('sha256', json_encode([
            $prompt,
            $region,
            $requestedIds,
            array_map(fn ($row) => $row['size'].':'.$row['count'], $sizeDistribution),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'agent' => 'CollectionBot',
            'engine' => 'rule-based-v1',
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
            'next_steps' => [
                'Duyệt mood board và bảng màu.',
                'Điều chỉnh số lượng SKU theo tồn kho và năng lực sản xuất.',
                'Áp dụng prompt vào Canvas hoặc tạo bộ sưu tập mới.',
            ],
        ];
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
