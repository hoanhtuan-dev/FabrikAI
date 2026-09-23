<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\ModelCreditCost;
use App\Models\ProviderPrice;
use App\Models\ProviderUsage;

/**
 * GIÁ VỐN NHÀ CUNG CẤP & BIÊN LỢI NHUẬN — MỘT CHỖ DUY NHẤT.
 *
 * Ba việc, và cả ba đều phải đi qua đây:
 *   1. QUY ĐỔI ĐƠN VỊ TÍNH TIỀN — chỗ dễ sai nhất khi tích hợp, và sai ở đây thì mọi con số lãi
 *      đều sai theo. fal tính theo MEGAPIXEL và **làm tròn LÊN**; DashScope tính theo ẢNH; video
 *      tính theo GIÂY. Cùng một ảnh "1K" mà 1:1 là 2 MP còn 4:5 là 1 MP.
 *   2. GHI SỔ mỗi lần gọi provider (kể cả lần lỗi — để đếm được chi phí của chuỗi dự phòng).
 *   3. TÍNH SỐ CREDIT phải thu, theo công thức đã chốt ở docs/CREDIT_GOI_VA_LOI_NHUAN.md §4.4:
 *          credit = ceil( giá_vốn_VNĐ / 720 )   (tối thiểu 1)
 *      720 ₫ = 1.200 ₫/credit × (1 − 0,40) — tức bảo đảm biên **≥ 40 %** ở mức đơn giá TỆ NHẤT
 *      trong mọi gói. Chia cho mức tệ nhất (không phải mức trung bình) là điều biến "bình quân
 *      40 %" thành "MỌI dòng ≥ 40 %".
 */
class ProviderCostService
{
    /** Tỉ giá mặc định USD→VND. Là CẤU HÌNH, không phải hằng số trong mã. */
    public const DEFAULT_FX = 26000;

    /**
     * Mẫu số của công thức credit, tính bằng VNĐ.
     *
     * 720 = 1.200 ₫/credit × (1 − 0,40). Đây là mức chia TỆ NHẤT trong mọi gói — nó giả định
     * khách đang trả đúng 1.200 ₫/credit. Gói nào trả cao hơn thì biên cao hơn 40 %.
     */
    public const CREDIT_DIVISOR_VND = 720;

    /**
     * Ngưỡng đơn giá TỐI THIỂU của một gói (₫/credit).
     *
     * VÌ SAO LÀ MỘT CON SỐ DUY NHẤT: giá vốn trên mỗi credit khác nhau theo model; cái cao nhất
     * là video kling (9.100 ₫ ÷ 13 credit = 700 ₫/credit). Để biên ≥ 40 % ở dòng tệ nhất thì
     * đơn giá gói phải ≥ 700 ÷ 0,60 = 1.166,67 ₫. Làm tròn lên 1.200 ₫ (đệm ~3 % cho thử lại và
     * cho lỗi 422 mà fal CÓ THỂ vẫn tính tiền).
     *
     * Đây là bất biến khoá được bằng test: thêm gói mới rẻ hơn ngưỡng là test ĐỎ.
     */
    public const MIN_VND_PER_CREDIT = 1200;

    /** Tỉ giá USD→VND đang dùng (setting `studio_usd_vnd`, mặc định 26.000). */
    public function fxRate(): float
    {
        $raw = studio_config('usd_vnd', self::DEFAULT_FX);
        $rate = (float) $raw;

        return $rate > 0 ? $rate : (float) self::DEFAULT_FX;
    }

    // ══════════════════════════════════════════════════════════════════════════════════════
    // 1. ĐƠN VỊ TÍNH TIỀN
    // ══════════════════════════════════════════════════════════════════════════════════════

    /**
     * Kích thước ảnh THẬT mà ta gửi cho nhà cung cấp (px) — theo đúng quy ước đang chạy ở
     * Studio (1K = cạnh dài 1024, 2K = 2048) và bảng tỉ lệ của app.
     *
     * Giữ ở ĐÂY (không nằm trong StudioController) để "ta gửi cỡ nào" và "ta bị tính tiền cỡ nào"
     * không thể lệch nhau — lệch là lỗi im lặng.
     *
     * @return array{0:int,1:int}
     */
    public function imageDimensions(?string $resolution, ?string $ratio): array
    {
        $long = $resolution === '2K' ? 2048 : 1024;
        $map = [
            '1:1' => [1, 1], '4:3' => [4, 3], '3:4' => [3, 4],
            '16:9' => [16, 9], '9:16' => [9, 16], '4:5' => [4, 5],
            '2:3' => [2, 3], '21:9' => [21, 9], '19:6' => [19, 6],
        ];
        [$rw, $rh] = $map[$ratio] ?? [1, 1];

        $w = $rw >= $rh ? $long : (int) round($long * $rw / $rh);
        $h = $rw >= $rh ? (int) round($long * $rh / $rw) : $long;

        return [$w, $h];
    }

    /**
     * Megapixel ĐÃ LÀM TRÒN LÊN — đúng như fal tính tiền.
     *
     * VÌ SAO PHẢI LÀM TRÒN LÊN Ở ĐÂY: fal ghi rõ *"Images are billed by rounding up to the nearest
     * megapixel"*. Ảnh 1024×1024 = 1,049 MP ⇒ bị tính **2 MP** (gấp đôi). Nếu ta tính 1,049 thì
     * giá vốn bị đánh giá thấp gần một nửa ở đúng tỉ lệ 1:1 — tỉ lệ phổ biến nhất.
     */
    public function billableMegapixels(int $width, int $height): int
    {
        if ($width < 1 || $height < 1) {
            return 1;
        }

        return max(1, (int) ceil(($width * $height) / 1_000_000));
    }

    /**
     * Số ĐƠN VỊ bị tính tiền cho một lượt, theo đơn vị của model.
     *
     * @param  array{width?:int,height?:int,seconds?:float}  $shape
     */
    public function billableUnits(string $unit, array $shape): float
    {
        return match ($unit) {
            ProviderPrice::UNIT_MEGAPIXEL => (float) $this->billableMegapixels(
                (int) ($shape['width'] ?? 0),
                (int) ($shape['height'] ?? 0),
            ),
            ProviderPrice::UNIT_SECOND => max(0.1, (float) ($shape['seconds'] ?? 1)),
            default => 1.0,
        };
    }

    // ══════════════════════════════════════════════════════════════════════════════════════
    // 2. BÁO GIÁ (chưa ghi sổ)
    // ══════════════════════════════════════════════════════════════════════════════════════

    /**
     * TÊN NHÀ CUNG CẤP CHUẨN HOÁ — nhiều tên, MỘT nhà cung cấp, MỘT hoá đơn.
     *
     * VÌ SAO CẦN (đo trên production 2026-09-26): Model Registry khai nhóm 'edit' với provider
     * `qwen-paygo`, nhóm 'image' có khi là `flux`, trong khi bảng giá lại khoá theo `dashscope`/`fal`.
     * Không chuẩn hoá thì MỌI lượt tra giá đều TRƯỢT ⇒ sổ chi phí ghi 'unknown_cost' cho gần hết
     * lượt và báo cáo lợi nhuận thành vô dụng — đúng kiểu lỗi im lặng.
     *
     * Danh sách này là DỮ LIỆU VỀ NHÀ CUNG CẤP (ai gửi hoá đơn cho ta), không phải về giao thức.
     */
    public const PROVIDER_ALIASES = [
        'qwen' => 'dashscope',
        'wan' => 'dashscope',
        'qwen-paygo' => 'dashscope',
        'dashscope' => 'dashscope',
        'flux' => 'fal',
        'fal' => 'fal',
        'fal-ai' => 'fal',
        'gemini' => 'gemini',
        'replicate' => 'replicate',
    ];

    /** Quy tên nhà cung cấp về một khoá duy nhất. Tên lạ giữ nguyên (để lộ ra chứ không nuốt). */
    public function normalizeProvider(?string $provider): string
    {
        $p = strtolower(trim((string) $provider));

        return self::PROVIDER_ALIASES[$p] ?? $p;
    }

    public function priceFor(?string $provider, ?string $model): ?ProviderPrice
    {
        if (! $provider || ! $model) {
            return null;
        }

        $p = $this->normalizeProvider($provider);
        $m = trim($model);

        // Thử tên chuẩn hoá trước, rồi tới tên NGUYÊN BẢN — để một dòng giá khai theo tên gốc
        // (`qwen-paygo`) vẫn dùng được nếu chủ dự án muốn tách hoá đơn riêng.
        return ProviderPrice::query()->where('provider', $p)->where('model', $m)->first()
            ?: ProviderPrice::query()->where('provider', strtolower(trim($provider)))->where('model', $m)->first();
    }

    /**
     * Báo giá MỘT lượt: biết đơn giá hay không, và tốn bao nhiêu.
     *
     * `known = false` nghĩa là CHƯA khai giá vốn cho model này. Cố ý KHÔNG đoán — xem
     * ProviderUsage::OUTCOME_UNKNOWN.
     *
     * @param  array{width?:int,height?:int,seconds?:float}  $shape
     * @return array{known:bool,unit:string,units:float,unit_price_usd:?float,cost_usd:?float,cost_vnd:?float,fx_rate:float}
     */
    public function quote(?string $provider, ?string $model, array $shape = []): array
    {
        $fx = $this->fxRate();
        $price = $this->priceFor($provider, $model);

        if (! $price) {
            return [
                'known' => false,
                'unit' => ProviderPrice::UNIT_IMAGE,
                'units' => 1.0,
                'unit_price_usd' => null,
                'cost_usd' => null,
                'cost_vnd' => null,
                'fx_rate' => $fx,
            ];
        }

        $unitsPerSide = $this->billableUnits($price->unit, $shape);

        // SỐ MẶT ĐƯỢC TÍNH TIỀN. fal tính CẢ ảnh vào lẫn ảnh ra với model edit:
        // *"$0.05 per megapixel on BOTH input and output side"*. Chỉ tính mặt ra là ĐÁNH GIÁ THẤP
        // giá vốn một nửa ở đúng đường đắt nhất (sửa ảnh) — và đó là đường bị lỗ âm thầm.
        $sides = max(1, (int) ($price->billed_sides ?: 1));
        $units = $unitsPerSide * $sides;

        // GIÁ BẬC THANG: đơn vị ĐẦU có giá riêng, các đơn vị THÊM giá khác.
        //   falt: "$0.03 for the FIRST megapixel, plus $0.015 per EXTRA megapixel"
        // first_unit_price_usd = null ⇒ giá phẳng (mọi đơn vị cùng giá) — hành vi cũ, không đổi.
        $first = $price->first_unit_price_usd !== null
            ? (float) $price->first_unit_price_usd
            : (float) $price->unit_price_usd;
        $extra = (float) $price->unit_price_usd;

        $costUsd = $first + $extra * max(0.0, $units - 1);

        return [
            'known' => true,
            'unit' => $price->unit,
            'units' => $units,
            'unit_price_usd' => $extra,
            'first_unit_price_usd' => $first,
            'billed_sides' => $sides,
            'cost_usd' => round($costUsd, 6),
            'cost_vnd' => round($costUsd * $fx, 2),
            'fx_rate' => $fx,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════════════════
    // 3. GHI SỔ
    // ══════════════════════════════════════════════════════════════════════════════════════

    /**
     * Ghi một lần gọi provider vào sổ chi phí.
     *
     * KHÔNG BAO GIỜ NÉM RA NGOÀI: ghi sổ hỏng không được làm hỏng lượt render đã xong — cùng
     * nguyên tắc với thông báo cho người dùng trong RenderImageJob.
     *
     * @param  array{width?:int,height?:int,seconds?:float}  $shape
     */
    public function record(
        ?Generation $generation,
        ?string $provider,
        ?string $model,
        array $shape = [],
        string $outcome = ProviderUsage::OUTCOME_OK,
        int $attempt = 1,
        ?string $falRequestId = null,
        ?int $inferenceMs = null,
    ): ?ProviderUsage {
        try {
            $q = $this->quote($provider, $model, $shape);

            // Lượt LỖI: fal và DashScope đều KHÔNG tính tiền lỗi 5xx, nhưng lỗi 422 (input sai)
            // CÓ THỂ vẫn bị tính nếu runner đã tiêu GPU. Vì vậy vẫn ghi dòng, nhưng cost = 0 khi
            // đã biết chắc nhà cung cấp không tính — và để NULL khi chưa chắc.
            $costUsd = $q['cost_usd'];
            $costVnd = $q['cost_vnd'];
            if ($outcome === ProviderUsage::OUTCOME_FAILED && $q['known']) {
                $costUsd = 0.0;
                $costVnd = 0.0;
            }
            if (! $q['known']) {
                $outcome = ProviderUsage::OUTCOME_UNKNOWN;
            }

            return ProviderUsage::create([
                'generation_id' => $generation?->id,
                'user_id' => $generation?->user_id,
                // Lưu tên ĐÃ CHUẨN HOÁ: sổ chi phí phải nhóm theo NHÀ CUNG CẤP gửi hoá đơn,
                // không theo tên giao thức trong Model Registry.
                'provider' => $this->normalizeProvider($provider),
                'model' => trim((string) $model),
                'attempt' => max(1, $attempt),
                'outcome' => $outcome,
                'unit' => $q['unit'],
                'units' => $q['units'],
                'unit_price_usd' => $q['unit_price_usd'],
                'cost_usd' => $costUsd,
                'fx_rate' => $q['fx_rate'],
                'cost_vnd' => $costVnd,
                'fal_request_id' => $falRequestId,
                'inference_ms' => $inferenceMs,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('provider_usage ghi sổ hỏng (không ảnh hưởng kết quả render)', [
                'generation_id' => $generation?->id,
                'provider' => $provider,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cộng dồn giá vốn của MỘT generation vào `generations.cost_vnd` (bản sao để báo cáo đọc nhanh).
     *
     * Nguồn sự thật vẫn là `provider_usage` — cột này chỉ để khỏi JOIN mỗi dòng khi hiện lưới ảnh.
     */
    public function syncGenerationCost(Generation $generation): void
    {
        try {
            $total = (float) ProviderUsage::where('generation_id', $generation->id)->sum('cost_vnd');
            $generation->forceFill(['cost_vnd' => round($total, 2)])->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('syncGenerationCost hỏng', [
                'generation_id' => $generation->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════════════════
    // 4. GIÁ BÁN — số credit khách phải trả
    // ══════════════════════════════════════════════════════════════════════════════════════

    /**
     * Giá bán theo model (số credit) — tra bảng `model_credit_cost`.
     *
     * Thứ tự tra (DỪNG ở dòng khớp đầu tiên):
     *   1. (provider, model, cỡ, tỉ lệ)   ← chính xác nhất, vd flux-pro/fill ở 2K tỉ lệ 1:1
     *   2. (provider, model, cỡ, '')      ← "mọi tỉ lệ ở cỡ này"
     *   3. (provider, model, '', '')      ← "mọi cỡ, mọi tỉ lệ"
     *   4. null                          ← chưa khai ⇒ nơi gọi rơi về giá của GÓI
     *
     * Vì sao cần cả cỡ LẪN tỉ lệ: fal tính theo megapixel làm tròn LÊN, nên 1K 1:1 (2 MP) đắt
     * gấp đôi 1K 4:5 (1 MP). Chỉ tra theo cỡ thì phải lấy giá tỉ lệ đắt nhất cho mọi tỉ lệ —
     * và ảnh 4:5, tỉ lệ phổ biến nhất của ngành, bị thu gấp đôi giá vốn thật.
     */
    public function creditsFor(?string $provider, ?string $model, ?string $resolution = '', ?string $ratio = ''): ?int
    {
        if (! $provider || ! $model) {
            return null;
        }

        [$p, $m] = ModelCreditCost::key($this->normalizeProvider($provider), $model);
        $res = strtoupper(trim((string) $resolution));
        $rat = trim((string) $ratio);

        // Ba lượt tra, theo thứ tự từ CỤ THỂ tới TỔNG QUÁT.
        $candidates = [
            [$res, $rat],
            [$res, ''],
            ['', ''],
        ];

        foreach ($candidates as [$r, $t]) {
            $v = ModelCreditCost::query()
                ->where('provider', $p)->where('model', $m)
                ->where('resolution', $r)->where('ratio', $t)
                ->value('credits');
            if ($v !== null) {
                return max(1, (int) $v);
            }
        }

        return null;
    }

    /**
     * Số credit suy ra TỪ GIÁ VỐN — dùng khi bảng chưa khai model đó.
     *
     * Công thức đã chốt (docs/CREDIT_GOI_VA_LOI_NHUAN.md §4.4): ceil(giá_vốn_VNĐ / 720).
     * Nhờ hàm này, một model MỚI thêm vào hệ thống **tự có giá đúng** thay vì mặc định 1 credit
     * và âm thầm lỗ.
     *
     * @param  array{width?:int,height?:int,seconds?:float}  $shape
     */
    public function suggestCredits(?string $provider, ?string $model, array $shape = []): int
    {
        $q = $this->quote($provider, $model, $shape);

        if (! $q['known'] || $q['cost_vnd'] === null) {
            return 1;
        }

        return max(1, (int) ceil($q['cost_vnd'] / self::CREDIT_DIVISOR_VND));
    }

    // ══════════════════════════════════════════════════════════════════════════════════════
    // 5. BÁO CÁO
    // ══════════════════════════════════════════════════════════════════════════════════════

    /**
     * Bảng lãi/lỗ theo model trong một khoảng thời gian.
     *
     * "Doanh thu" ở đây là **số credit đã trừ × đơn giá credit của gói** — ước lượng đúng mức có
     * thể, vì một credit không có giá cố định (tuỳ gói của khách). Dùng đơn giá trung bình có
     * trọng số theo số credit đã bán trong kỳ.
     *
     * @return array{rows:array<int,array<string,mixed>>,revenue_vnd:float,cost_vnd:float,profit_vnd:float,margin_pct:float,unknown_count:int}
     */
    public function marginReport(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = ProviderUsage::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('provider, model,
                COUNT(*) as calls,
                COALESCE(SUM(cost_vnd), 0) as cost_vnd,
                SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) as unknown_count', [ProviderUsage::OUTCOME_UNKNOWN])
            ->groupBy('provider', 'model')
            ->orderByDesc('cost_vnd')
            ->get();

        $rate = $this->averageCreditRateVnd();

        $out = [];
        $totalRevenue = 0.0;
        $totalCost = 0.0;
        $unknown = 0;

        foreach ($rows as $r) {
            $credits = $this->creditsFor($r->provider, $r->model) ?? 1;
            $revenue = (float) $r->calls * $credits * $rate;
            $cost = (float) $r->cost_vnd;
            $totalRevenue += $revenue;
            $totalCost += $cost;
            $unknown += (int) $r->unknown_count;

            $out[] = [
                'provider' => $r->provider,
                'model' => $r->model,
                'calls' => (int) $r->calls,
                'credits_each' => $credits,
                'revenue_vnd' => round($revenue, 2),
                'cost_vnd' => round($cost, 2),
                'profit_vnd' => round($revenue - $cost, 2),
                'margin_pct' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 1) : null,
                'losing' => $revenue > 0 && $cost > $revenue,
            ];
        }

        return [
            'rows' => $out,
            'revenue_vnd' => round($totalRevenue, 2),
            'cost_vnd' => round($totalCost, 2),
            'profit_vnd' => round($totalRevenue - $totalCost, 2),
            'margin_pct' => $totalRevenue > 0 ? round((($totalRevenue - $totalCost) / $totalRevenue) * 100, 1) : 0.0,
            'unknown_count' => $unknown,
            'credit_rate_vnd' => $rate,
        ];
    }

    /**
     * Đơn giá credit TRUNG BÌNH của các gói đang bán = Σ(giá gói) ÷ Σ(credit gói).
     *
     * Đây là mẫu số dùng cho báo cáo khi chưa biết mỗi lượt thuộc gói nào. Số ĐÚNG theo từng
     * khách nằm ở `credit_transactions` (dòng trừ credit có reference tới generation).
     *
     * Chỉ lấy gói CÓ BÁN và CÓ credit — gói Miễn phí (giá 0) không được kéo con số này xuống, vì
     * nó không tạo doanh thu và trộn vào sẽ làm mọi biên lợi nhuận trông tệ giả tạo.
     */
    public function averageCreditRateVnd(): float
    {
        $plans = \App\Models\Plan::query()
            ->where('is_active', true)
            ->where('price_vnd', '>', 0)
            ->where('credits_per_month', '>', 0)
            ->get(['price_vnd', 'credits_per_month']);

        if ($plans->isEmpty()) {
            return (float) self::MIN_VND_PER_CREDIT;
        }

        $credits = (float) $plans->sum(fn ($p) => (int) $p->credits_per_month);
        if ($credits <= 0) {
            return (float) self::MIN_VND_PER_CREDIT;
        }

        $revenue = (float) $plans->sum(fn ($p) => (int) $p->price_vnd);

        return round($revenue / $credits, 2);
    }
}
