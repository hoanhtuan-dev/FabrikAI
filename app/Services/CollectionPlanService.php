<?php

namespace App\Services;

/**
 * KẾ HOẠCH SẢN XUẤT & LỢI NHUẬN — tầng "tiền" của Agent Studio.
 *
 * VÌ SAO CÓ FILE NÀY: một bộ sưu tập chỉ có giá trị khi nó ra được LỆNH SẢN XUẤT: cắt bao nhiêu
 * cái mỗi mã theo từng size, đặt bao nhiêu mét vải, giá vốn mỗi cái bao nhiêu, bán giá nào thì có
 * lãi, và cần bao nhiêu vốn cho đợt đầu. Trước đây Agent Studio dừng ở "dải giá Mid-range" — con
 * số đó không giúp chủ xưởng quyết định gì cả.
 *
 * NGUYÊN TẮC:
 *   · TOÀN BỘ con số ở đây do MÁY TÍNH từ công thức và từ ĐƠN GIÁ DO CHỦ XƯỞNG NHẬP — không có
 *     model AI nào tham gia vào con số. AI viết chữ, hệ thống tính tiền.
 *   · Mọi đơn giá/định mức đều là GIẢ ĐỊNH có thể sửa; response trả lại assumptions đã dùng và
 *     ghi rõ basis để người dùng biết con số đến từ đâu.
 *   · Định mức vải và bảng size là SỐ THAM CHIẾU của hệ thống (dáng nữ VN, khổ vải 1m5) — phải
 *     đối chiếu với rập thật của xưởng; file nói thẳng điều đó thay vì tỏ ra chính xác tuyệt đối.
 */
class CollectionPlanService
{
    /** Đơn giá/định mức mặc định — CHỈNH ĐƯỢC toàn bộ từ giao diện. */
    public const DEFAULTS = [
        'units_per_sku' => 30,          // số lượng dự kiến cho MỖI mã (mỗi SKU)
        'fabric_price_per_m' => 85000,  // VND / mét vải
        'fabric_width_cm' => 150,       // khổ vải
        'fabric_safety_pct' => 5,       // đặt dư vải (đầu khúc, canh sợi)
        'wastage_pct' => 12,            // tiêu hao khi cắt
        'trim_cost' => 25000,           // phụ liệu / cái (khoá, chỉ, bo, dựng, nhãn)
        'sewing_cost' => 65000,         // giá công may / cái
        'packaging_cost' => 8000,       // bao bì / cái
        'defect_pct' => 3,              // tỉ lệ lỗi phải làm lại
        'channel_discount_pct' => 18,   // chiết khấu kênh bán (sàn/affiliate/CTV)
        'target_margin_pct' => 55,      // % lãi gộp mong muốn trên giá vốn
        'daily_capacity' => 25,         // số cái xưởng ra được mỗi ngày
        'fixed_cost' => 0,              // chi phí cố định của cả bộ (mẫu, rập, chụp ảnh…)
    ];

    /** Định mức vải tham chiếu (mét/cái ở size M, khổ 1m50). */
    private const FABRIC_M = [
        'Áo / blouse' => 1.55,
        'Quần' => 1.30,
        'Váy' => 1.75,
        'Phụ kiện' => 0.35,
    ];

    /** Hệ số theo size (nhân vào định mức vải size M). */
    private const SIZE_FACTOR = ['XS' => 0.92, 'S' => 0.96, 'M' => 1.00, 'L' => 1.06, 'XL' => 1.12, 'XXL' => 1.18];

    /** Bảng size tham chiếu: số đo ở size M + bước nhảy mỗi size (cm). */
    private const SIZE_CHART = [
        'Áo / blouse' => [
            'M' => ['Ngực' => 88, 'Eo' => 72, 'Mông' => 96, 'Dài áo' => 62, 'Vai' => 38, 'Dài tay' => 58, 'Rộng tay' => 34],
            'grade' => ['Ngực' => 4, 'Eo' => 4, 'Mông' => 4, 'Dài áo' => 1.5, 'Vai' => 1, 'Dài tay' => 1, 'Rộng tay' => 1],
        ],
        'Quần' => [
            'M' => ['Eo' => 70, 'Mông' => 94, 'Dài quần' => 98, 'Vòng ống' => 42, 'Ngang gối' => 44],
            'grade' => ['Eo' => 4, 'Mông' => 4, 'Dài quần' => 1, 'Vòng ống' => 1, 'Ngang gối' => 1],
        ],
        'Váy' => [
            'M' => ['Ngực' => 86, 'Eo' => 70, 'Mông' => 94, 'Dài váy' => 100, 'Ngang vai' => 36],
            'grade' => ['Ngực' => 4, 'Eo' => 4, 'Mông' => 4, 'Dài váy' => 2, 'Ngang vai' => 1],
        ],
    ];

    private const SIZE_STEP = ['XS' => -2, 'S' => -1, 'M' => 0, 'L' => 1, 'XL' => 2, 'XXL' => 3];

    /**
     * Tỉ lệ chia 3 đợt sản xuất: cắt THỬ mỏng trước → dồn cho mã chạy → dứt điểm.
     * Đợt 1 cố ý NHỎ NHẤT vì đó là đợt duy nhất còn có thể sửa sai mà không chôn vốn.
     */
    private const WAVE_SHARES = [
        ['id' => 'wave-1', 'name' => 'Đợt 1 — cắt thử toàn bộ mã', 'share' => 0.25, 'note' => 'Cắt mỏng đủ bán thử 2–3 tuần. Đây là đợt duy nhất còn sửa sai được mà không chôn vốn.'],
        ['id' => 'wave-2', 'name' => 'Đợt 2 — dồn cho mã bán chạy', 'share' => 0.40, 'note' => 'Chốt theo số bán THẬT của đợt 1: mã nào chạy thì dồn thêm, mã nào đứng thì dừng.'],
        ['id' => 'wave-3', 'name' => 'Đợt 3 — dứt điểm', 'share' => 0.35, 'note' => 'Phần còn lại, ưu tiên mã chủ lực; canh để không tồn cuối mùa.'],
    ];

    /**
     * @param  array  $brief  kết quả collectionBrief (dùng structure / size_distribution / price_bands)
     * @param  array  $assumptions  đơn giá do chủ xưởng nhập (thiếu thì lấy DEFAULTS)
     */
    public function plan(array $brief, array $assumptions = []): array
    {
        $a = $this->normalizeAssumptions($assumptions);
        $categories = $brief['structure']['categories'] ?? [];
        $sizes = $brief['size_distribution'] ?? [];
        $priceBand = $brief['price_bands'] ?? [];

        if ($categories === [] || $sizes === []) {
            return [
                'basis' => 'missing_input',
                'message' => 'Cần cấu trúc danh mục và phân bổ size từ brief trước khi lập kế hoạch sản xuất.',
                'assumptions' => $a,
                'cut_lines' => [],
            ];
        }

        $totalSkus = array_sum(array_column($categories, 'count')) ?: 1;
        $sizeTotal = array_sum(array_column($sizes, 'count')) ?: 1;
        $targetSellPrice = (int) round((($priceBand['min_vnd'] ?? 0) + ($priceBand['max_vnd'] ?? 0)) / 2);

        $lines = [];
        foreach ($categories as $category) {
            $name = (string) ($category['category'] ?? '');
            $skuCount = max(0, (int) ($category['count'] ?? 0));
            if ($name === '' || $skuCount === 0) {
                continue;
            }
            $unitsForCategory = $skuCount * $a['units_per_sku'];
            // Chia số cái cho từng size bằng phương pháp PHẦN DƯ LỚN NHẤT: tổng các size LUÔN đúng
            // bằng số mã × số lượng mỗi mã. Làm tròn từng size riêng lẻ (như trước) sinh sai lệch
            // vài cái — chủ xưởng đọc lệnh cắt mà tổng không khớp thì mất tin ngay.
            foreach ($this->allocateUnits($unitsForCategory, $sizes, $sizeTotal) as $size => $qty) {
                if ($qty <= 0) {
                    continue;
                }
                $lines[] = $this->cutLine($name, (string) $size, $qty, $a, $targetSellPrice, (string) ($category['source'] ?? 'default'));
            }
        }

        $totals = $this->totals($lines, $a);

        return [
            'basis' => ($brief['structure']['basis'] ?? 'heuristic') === 'shop_data' ? 'shop_data' : 'heuristic',
            'currency' => 'VND',
            'assumptions' => $a,
            'inputs' => [
                'total_skus' => $totalSkus,
                'units_per_sku' => $a['units_per_sku'],
                'sizes' => array_map(fn (array $row) => $row['size'].':'.(int) $row['count'], $sizes),
                'target_sell_price_vnd' => $targetSellPrice,
                'price_band' => $priceBand,
            ],
            'cut_lines' => $lines,
            'totals' => $totals,
            'waves' => $this->waves($lines, $a),
            'size_chart' => $this->sizeChart($categories, $sizes, $lines),
            'selling' => $this->sellingScenarios($lines, $a, $priceBand),
            'price_check' => $this->priceCheck($lines, $priceBand),
            'notes' => $this->notes($a, $lines, $this->priceCheck($lines, $priceBand)),
        ];
    }

    /**
     * Chia số cái của một nhóm hàng cho các size sao cho TỔNG KHỚP CHÍNH XÁC (phần dư lớn nhất).
     *
     * @return array<string, int> size => số cái
     */
    private function allocateUnits(int $total, array $sizes, int $sizeTotal): array
    {
        if ($total <= 0 || $sizes === []) {
            return [];
        }
        $out = [];
        $remainders = [];
        $assigned = 0;
        foreach ($sizes as $row) {
            $size = strtoupper((string) ($row['size'] ?? ''));
            if ($size === '') {
                continue;
            }
            $exact = $total * ((int) ($row['count'] ?? 0)) / $sizeTotal;
            $floor = (int) floor($exact);
            $out[$size] = ($out[$size] ?? 0) + $floor;
            $assigned += $floor;
            $remainders[] = ['size' => $size, 'frac' => $exact - $floor];
        }
        if ($out === []) {
            return [];
        }

        usort($remainders, fn (array $a, array $b) => $b['frac'] <=> $a['frac']);
        $left = $total - $assigned;
        $index = 0;
        while ($left > 0) {
            $out[$remainders[$index % count($remainders)]['size']]++;
            $left--;
            $index++;
        }

        return $out;
    }

    /** Một dòng cắt: một nhóm hàng × một size. */
    private function cutLine(string $category, string $size, int $qty, array $a, int $targetSellPrice, string $source): array
    {
        $factor = self::SIZE_FACTOR[$size] ?? 1.0;
        $baseM = self::FABRIC_M[$category] ?? 1.5;
        $perUnit = round($baseM * $factor * (1 + $a['wastage_pct'] / 100), 2);
        $fabricM = round($perUnit * $qty, 2);

        $defect = 1 + $a['defect_pct'] / 100;
        $fabricCost = $fabricM * $a['fabric_price_per_m'];
        $trimCost = $qty * $a['trim_cost'] * $defect;
        $sewingCost = $qty * $a['sewing_cost'] * $defect;
        $packagingCost = $qty * $a['packaging_cost'];
        $cost = (int) round($fabricCost + $trimCost + $sewingCost + $packagingCost);
        $unitCost = $qty > 0 ? (int) round($cost / $qty) : 0;

        $suggested = $this->roundPrice((int) round($unitCost * (1 + $a['target_margin_pct'] / 100)));
        $sellPrice = $targetSellPrice > 0 ? $targetSellPrice : $suggested;
        $netRevenue = (int) round($sellPrice * (1 - $a['channel_discount_pct'] / 100));
        $profitUnit = $netRevenue - $unitCost;

        return [
            'category' => $category,
            'size' => $size,
            'qty' => $qty,
            'fabric_m_per_unit' => $perUnit,
            'fabric_m_total' => $fabricM,
            'fabric_cost' => (int) round($fabricCost),
            'trim_cost' => (int) round($trimCost),
            'sewing_cost' => (int) round($sewingCost),
            'packaging_cost' => (int) round($packagingCost),
            'unit_cost' => $unitCost,
            'suggested_price_vnd' => $suggested,
            'sell_price_vnd' => $sellPrice,
            'net_revenue_unit' => $netRevenue,
            'profit_unit' => $profitUnit,
            'margin_pct' => $netRevenue > 0 ? round($profitUnit / $netRevenue * 100, 1) : null,
            'revenue_vnd' => $netRevenue * $qty,
            'cost_total_vnd' => $cost,
            'profit_total_vnd' => $profitUnit * $qty,
            'source' => $source,
        ];
    }

    private function totals(array $lines, array $a): array
    {
        $units = array_sum(array_column($lines, 'qty'));
        $fabricM = array_sum(array_column($lines, 'fabric_m_total'));
        $cost = array_sum(array_column($lines, 'cost_total_vnd'));
        $revenue = array_sum(array_column($lines, 'revenue_vnd'));
        $profit = $revenue - $cost;
        $order = (int) (ceil(($fabricM * (1 + $a['fabric_safety_pct'] / 100)) / 10) * 10);
        $avgUnitCost = $units > 0 ? (int) round($cost / $units) : 0;
        $avgProfit = $units > 0 ? (int) round($profit / $units) : 0;

        return [
            'units' => $units,
            'cut_lines' => count($lines),
            'fabric_m' => round($fabricM, 1),
            'fabric_order_m' => $order,
            'fabric_order_cost_vnd' => (int) round($order * $a['fabric_price_per_m']),
            'cost_total_vnd' => (int) $cost,
            'revenue_vnd' => (int) $revenue,
            'profit_vnd' => (int) $profit,
            'margin_pct' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            'avg_unit_cost_vnd' => $avgUnitCost,
            'avg_profit_unit_vnd' => $avgProfit,
            'breakeven_units' => ($a['fixed_cost'] > 0 && $avgProfit > 0) ? (int) ceil($a['fixed_cost'] / $avgProfit) : 0,
            'capital_needed_vnd' => (int) $cost + (int) $a['fixed_cost'],
            'days_total' => $a['daily_capacity'] > 0 ? (int) ceil($units / $a['daily_capacity']) : null,
        ];
    }

    /** Chia 3 đợt theo tỉ lệ cố định; mọi con số đều suy ra từ dòng cắt nên không mâu thuẫn. */
    private function waves(array $lines, array $a): array
    {
        if ($lines === []) {
            return [];
        }
        $totalUnits = array_sum(array_column($lines, 'qty')) ?: 1;
        $out = [];
        $assigned = 0;
        foreach (self::WAVE_SHARES as $index => $wave) {
            $isLast = $index === count(self::WAVE_SHARES) - 1;
            $rows = [];
            $units = 0;
            $fabric = 0.0;
            $cost = 0;
            $revenue = 0;
            foreach ($lines as $line) {
                $already = $this->assignedForLine($line, $index);
                $target = $isLast ? ($line['qty'] - $already) : (int) round($line['qty'] * $wave['share']);
                $qty = max(0, min($line['qty'] - $already, $target));
                if ($qty <= 0) {
                    continue;
                }
                $units += $qty;
                $fabric += $line['fabric_m_per_unit'] * $qty;
                $cost += (int) round($line['unit_cost'] * $qty);
                $revenue += $line['net_revenue_unit'] * $qty;
                $rows[] = ['category' => $line['category'], 'size' => $line['size'], 'qty' => $qty];
            }
            $assigned += $units;
            $out[] = [
                'id' => $wave['id'],
                'name' => $wave['name'],
                'note' => $wave['note'],
                'share_pct' => (int) round($wave['share'] * 100),
                'units' => $units,
                'lines' => $rows,
                'fabric_m' => round($fabric, 1),
                'fabric_order_m' => (int) (ceil(($fabric * (1 + $a['fabric_safety_pct'] / 100)) / 10) * 10),
                'cost_vnd' => $cost,
                'revenue_vnd' => $revenue,
                'profit_vnd' => $revenue - $cost,
                'days' => $a['daily_capacity'] > 0 ? (int) ceil($units / $a['daily_capacity']) : null,
            ];
        }

        return $out;
    }

    /** Số lượng đã chia cho một dòng ở các đợt TRƯỚC đợt đang xét. */
    private function assignedForLine(array $line, int $waveIndex): int
    {
        $sum = 0;
        for ($i = 0; $i < $waveIndex; $i++) {
            $sum += (int) round($line['qty'] * self::WAVE_SHARES[$i]['share']);
        }

        return $sum;
    }

    /** Bảng size số đo tham chiếu cho từng nhóm hàng có rập + số cái cần cắt mỗi size. */
    private function sizeChart(array $categories, array $sizes, array $lines): array
    {
        $planned = [];
        foreach ($lines as $line) {
            $planned[$line['category'].'|'.$line['size']] = (int) $line['qty'];
        }
        $out = [];
        foreach ($categories as $category) {
            $name = (string) ($category['category'] ?? '');
            $chart = self::SIZE_CHART[$name] ?? null;
            if ($chart === null) {
                continue;
            }
            $rows = [];
            foreach ($sizes as $sizeRow) {
                $size = strtoupper((string) ($sizeRow['size'] ?? ''));
                $step = self::SIZE_STEP[$size] ?? 0;
                $measures = [];
                foreach ($chart['M'] as $measure => $value) {
                    $measures[$measure] = round($value + ($chart['grade'][$measure] ?? 0) * $step, $step === 0 ? 0 : 1);
                }
                $rows[] = [
                    'size' => $size,
                    'units_planned' => $planned[$name.'|'.$size] ?? 0,
                    'measures' => $measures,
                ];
            }
            $out[] = [
                'category' => $name,
                'unit' => 'cm',
                'rows' => $rows,
                'note' => 'Số đo THAM CHIẾU dáng nữ VN (khổ vải '.self::DEFAULTS['fabric_width_cm'].'cm). Phải đối chiếu với rập thật của xưởng và cộng độ co của vải trước khi cắt.',
            ];
        }

        return $out;
    }

    /** Ba kịch bản bán: giá thấp / giá mục tiêu / giá cao — để chủ xưởng chọn mức chấp nhận được. */
    private function sellingScenarios(array $lines, array $a, array $priceBand): array
    {
        $units = array_sum(array_column($lines, 'qty'));
        $cost = array_sum(array_column($lines, 'cost_total_vnd')) + (int) $a['fixed_cost'];
        if ($units === 0) {
            return [];
        }
        $min = (int) ($priceBand['min_vnd'] ?? 0);
        $max = (int) ($priceBand['max_vnd'] ?? 0);
        $mid = (int) round(($min + $max) / 2);
        $prices = array_values(array_unique(array_filter([$min, $mid, $max])));
        $out = [];
        foreach ($prices as $price) {
            $net = (int) round($price * (1 - $a['channel_discount_pct'] / 100));
            $revenue = $net * $units;
            $profit = $revenue - $cost;
            $out[] = [
                'price_vnd' => $price,
                'net_unit_vnd' => $net,
                'revenue_vnd' => $revenue,
                'profit_vnd' => $profit,
                'margin_pct' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'breakeven_units' => $this->breakeven($cost, $net, $this->avgUnitCost($lines)),
                'label' => $price === $min ? 'Giá sàn (dễ bán nhất)' : ($price === $max ? 'Giá trần (lãi tốt nhất)' : 'Giá mục tiêu'),
            ];
        }

        return $out;
    }

    private function avgUnitCost(array $lines): int
    {
        $units = array_sum(array_column($lines, 'qty'));
        $cost = array_sum(array_column($lines, 'cost_total_vnd'));

        return $units > 0 ? (int) round($cost / $units) : 0;
    }

    private function breakeven(int $cost, int $netUnit, int $unitCost): int
    {
        $profitUnit = $netUnit - $unitCost;

        return $profitUnit > 0 ? (int) ceil($cost / $profitUnit) : 0;
    }

    private function roundPrice(int $value): int
    {
        return (int) (ceil($value / 10000) * 10000);
    }

    /**
     * ĐỐI CHIẾU GIÁ: giá bán gợi ý từ giá vốn (× lãi mong muốn) so với dải giá thị trường của brief.
     *
     * Vì sao cần: kế hoạch có thể đẹp trên giấy mà vẫn chết — giá vốn quá cao so với mức thị trường
     * trả, hoặc giá vốn quá thấp khiến chủ xưởng bỏ tiền trên bàn. Chủ xưởng phải thấy NGAY độ lệch
     * này thay vì tin vào một biên lợi nhuận tưởng tượng.
     */
    private function priceCheck(array $lines, array $priceBand): array
    {
        $units = array_sum(array_column($lines, 'qty'));
        $bandMin = (int) ($priceBand['min_vnd'] ?? 0);
        $bandMax = (int) ($priceBand['max_vnd'] ?? 0);
        if ($units === 0 || $bandMax <= 0) {
            return [
                'status' => 'unknown',
                'suggested_price_vnd' => null,
                'band_min_vnd' => $bandMin ?: null,
                'band_max_vnd' => $bandMax ?: null,
                'message' => 'Chưa đủ dữ liệu để đối chiếu giá bán với thị trường.',
            ];
        }

        $weighted = 0;
        foreach ($lines as $line) {
            $weighted += $line['suggested_price_vnd'] * $line['qty'];
        }
        $suggested = (int) round($weighted / $units);

        if ($suggested > $bandMax) {
            return [
                'status' => 'above_band',
                'suggested_price_vnd' => $suggested,
                'band_min_vnd' => $bandMin,
                'band_max_vnd' => $bandMax,
                'gap_pct' => (int) round(($suggested - $bandMax) / $bandMax * 100),
                'message' => 'Giá vốn của bạn cần bán ở mức '.number_format($suggested).'đ mới đạt lãi mong muốn — CAO HƠN dải giá thị trường (tối đa '.number_format($bandMax).'đ). Nên giảm giá vải/định mức, hoặc định vị lại sản phẩm trước khi cắt.',
            ];
        }
        if ($bandMin > 0 && $suggested < $bandMin) {
            return [
                'status' => 'below_band',
                'suggested_price_vnd' => $suggested,
                'band_min_vnd' => $bandMin,
                'band_max_vnd' => $bandMax,
                'gap_pct' => (int) round(($bandMin - $suggested) / max(1, $bandMin) * 100),
                'message' => 'Giá vốn của bạn chỉ cần bán ở mức '.number_format($suggested).'đ là đủ lãi, THẤP HƠN dải giá thị trường (từ '.number_format($bandMin).'đ). Đây là dư địa lãi — nhưng đừng bán thấp hơn mức khách chấp nhận trả.',
            ];
        }

        return [
            'status' => 'within_band',
            'suggested_price_vnd' => $suggested,
            'band_min_vnd' => $bandMin,
            'band_max_vnd' => $bandMax,
            'gap_pct' => 0,
            'message' => 'Giá bán gợi ý theo giá vốn ('.number_format($suggested).'đ) nằm trong dải giá thị trường — phương án khả thi.',
        ];
    }

    private function normalizeAssumptions(array $input): array
    {
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = is_numeric($input[$key]) ? (float) $input[$key] : (float) $default;
            // Chặn giá trị phi lý (số âm, hoặc % > 100) để kế hoạch không bao giờ ra số vô nghĩa.
            $max = str_ends_with($key, '_pct') ? 100 : 1000000000;
            $out[$key] = (int) max(0, min($max, $value));
        }

        return $out;
    }

    /** @return list<string> */
    private function notes(array $a, array $lines, array $priceCheck = []): array
    {
        $notes = [
            ($priceCheck['message'] ?? '') !== '' ? 'Đối chiếu giá: '.$priceCheck['message'] : null,
            'Mọi đơn giá/định mức ở đây là GIẢ ĐỊNH bạn nhập — sửa được ngay trên màn hình và kế hoạch tính lại tức thì.',
            'Định mức vải là số tham chiếu theo khổ '.$a['fabric_width_cm'].'cm; vải sọc/họa tiết hoa văn cần cộng thêm 10–20%.',
            'Đợt 2 và đợt 3 nên chốt lại theo số bán THẬT của đợt 1, không nên cắt đủ ngay từ đầu.',
        ];
        if ($a['channel_discount_pct'] > 0) {
            $notes[] = 'Lãi đã trừ chiết khấu kênh bán '.$a['channel_discount_pct'].'%.';
        }
        if ($a['fixed_cost'] > 0) {
            $notes[] = 'Đã tính '.number_format($a['fixed_cost']).'đ chi phí cố định vào vốn và điểm hoà vốn.';
        }
        if ($lines === []) {
            $notes[] = 'Chưa có dòng cắt nào — kiểm tra lại số lượng mỗi mã và bảng size.';
        }

        return array_values(array_filter($notes, 'strlen'));
    }
}
