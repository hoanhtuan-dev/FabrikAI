<?php

namespace App\Console\Commands;

use App\Models\ModelCreditCost;
use App\Models\Plan;
use App\Models\ProviderPrice;
use App\Services\ProviderCostService;
use Illuminate\Console\Command;

/**
 * BẢNG GIÁ & BIÊN LỢI NHUẬN — công cụ vận hành cho chủ dự án.
 *
 * Ba việc:
 *   · (mặc định) IN bảng: giá vốn → số credit → biên ở từng gói → cảnh báo chỗ không đạt.
 *     Chạy SAU khi đổi giá nhà cung cấp để biết mình có đang lỗ ở đâu không.
 *   · --sync   : tính lại TOÀN BỘ model_credit_cost từ provider_price (GHI ĐÈ — có xác nhận).
 *   · --check  : chỉ kiểm bất biến, exit code 1 nếu có chỗ không đạt (dùng được trong CI/cron).
 *
 * VÌ SAO CẦN LỆNH NÀY: giá nhà cung cấp đổi theo thời gian, và một model lỗ chỉ lộ ra qua con số
 * — không ai nhìn ra bằng mắt. Bất biến duy nhất cần nhớ:
 *
 *     mọi gói phải giữ  ₫/credit ≥ 1.200   ⇔   biên ≥ 40 % ở dòng đắt nhất
 */
class StudioPricingCommand extends Command
{
    protected $signature = 'studio:pricing
        {--sync : Tính lại model_credit_cost từ provider_price (GHI ĐÈ giá bán đang có)}
        {--usage= : In LƯỢT GỌI THẬT theo model trong N ngày (để ĐỐI CHIẾU HOÁ ĐƠN nhà cung cấp)}
        {--check : Chỉ kiểm bất biến; exit 1 nếu có chỗ không đạt}';

    protected $description = 'In bảng giá vốn → credit → biên lợi nhuận; --sync để tính lại, --check để kiểm bất biến';

    public function handle(ProviderCostService $cost): int
    {
        if ($this->option('sync')) {
            return $this->sync($cost);
        }

        // ── ĐỐI CHIẾU HOÁ ĐƠN ────────────────────────────────────────────────────────────
        // VÌ SAO CẦN: giá vốn trong CSDL là SUY RA (số lần gọi × đơn giá đã khai). Chỉ HOÁ ĐƠN
        // của nhà cung cấp mới là sự thật. Lệnh này in đúng con số để đem so: số lượt theo model
        // trong kỳ — khớp thì bảng giá đúng, lệch thì biết ngay phải sửa chỗ nào.
        if ($this->option('usage') !== null && $this->option('usage') !== false) {
            $this->usageReport((int) $this->option('usage'));
        }

        $this->newLine();
        $this->line('  <options=bold>GIÁ VỐN NHÀ CUNG CẤP</>');
        $this->table(
            ['Provider', 'Model', 'Đơn vị', 'Giá USD'],
            ProviderPrice::orderBy('provider')->orderBy('model')->get()
                ->map(fn ($p) => [
                    $p->provider,
                    $p->model,
                    $p->unit,
                    '$'.number_format((float) $p->unit_price_usd, 6),
                ])->all(),
        );

        // ── Giá bán theo model, quy về VNĐ và biên ở mỗi mức giá của gói ──────────────────
        $rates = $this->planRates();
        $this->line('  <options=bold>GIÁ BÁN THEO MODEL</> (VNĐ ở đơn giá TỆ NHẤT trong các gói)');
        $this->line('  Đơn giá mỗi gói: '.collect($rates)->map(fn ($r, $s) => $s.'='.number_format($r).'₫')->implode(' · '));
        $this->newLine();

        $worstRate = $rates ? min($rates) : (float) ProviderCostService::MIN_VND_PER_CREDIT;
        $rows = [];
        $problems = [];

        foreach (ModelCreditCost::orderBy('provider')->orderBy('model')->orderBy('resolution')->orderBy('ratio')->get() as $m) {
            // Giá vốn của dòng này — cần hình dạng (cỡ, tỉ lệ) để tính đúng số megapixel.
            $shape = $this->shapeFor($cost, $m->resolution, $m->ratio);
            $q = $cost->quote($m->provider, $m->model, $shape);

            $revenue = $m->credits * $worstRate;
            $costVnd = $q['cost_vnd'];
            $margin = $costVnd !== null && $revenue > 0 ? round((($revenue - $costVnd) / $revenue) * 100, 1) : null;

            $flag = '';
            if ($margin === null) {
                $flag = '? chưa có giá vốn';
                $problems[] = $m->provider.'/'.$m->model.' ('.$m->resolution.' '.$m->ratio.') — CHƯA KHAI GIÁ VỐN';
            } elseif ($margin < 40) {
                $flag = '❗ DƯỚI 40 %';
                $problems[] = $m->provider.'/'.$m->model.' ('.$m->resolution.' '.$m->ratio.') — biên '.$margin.'%';
            }

            $rows[] = [
                $m->provider,
                $m->model,
                trim($m->resolution.' '.$m->ratio) ?: 'mọi cỡ',
                $m->credits,
                $costVnd !== null ? number_format($costVnd).'₫' : '—',
                $margin !== null ? $margin.' %' : '—',
                $flag,
            ];
        }

        $this->table(['Provider', 'Model', 'Cỡ', 'credit', 'Giá vốn', 'Biên', ''], $rows);

        // ── Bất biến của GÓI ─────────────────────────────────────────────────────────────
        $this->line('  <options=bold>BẤT BIẾN: mọi gói phải giữ ₫/credit ≥ '
            .number_format(ProviderCostService::MIN_VND_PER_CREDIT).'</>');
        // ⚠️ GÓI KHÔNG BÁN (giá 0) KHÔNG ĐO ĐƯỢC BẰNG ₫/credit — chia cho doanh thu bằng 0 là vô nghĩa.
        // Lỗi thật gặp trên production 2026-09-26: gói `free` có 50 credit/tháng, giá 0 ⇒ phép kiểm
        // báo '0 ₫/credit, cần ≥ 1.200' — ĐÚNG SỐ nhưng SAI VIỆC. Gói miễn phí không có biên lợi
        // nhuận để mà bảo vệ; thứ cần đo là CHI PHÍ PHƠI NHIỄM (trần thiệt hại mỗi tài khoản).
        //
        // Nên: gói bán ⇒ kiểm ₫/credit; gói tặng ⇒ in chi phí tối đa, và chi phí đó bị CHẶN bởi
        // credit/tháng + trần ảnh/ngày (hai thứ sửa được trong Quản trị).
        $planRows = [];
        foreach (Plan::orderBy('sort')->get() as $p) {
            $credits = (int) $p->credits_per_month;
            $price = (int) $p->price_vnd;
            $isSold = $price > 0 && $credits > 0;

            if (! $isSold) {
                // Chi phí phơi nhiễm = toàn bộ credit × mức giá vốn TỆ NHẤT trên mỗi credit.
                $exposure = $credits * $this->worstCostPerCredit();
                $planRows[] = [
                    '·',
                    $p->slug.' (tặng)',
                    number_format($price).'₫',
                    number_format($credits),
                    $credits > 0 ? '≤ '.number_format($exposure).'₫' : '—',
                    $p->resolution_cap,
                    (int) $p->daily_image_limit ?: 'không giới hạn',
                ];

                continue;
            }

            $rate = $price / $credits;
            $ok = $rate >= ProviderCostService::MIN_VND_PER_CREDIT;
            if (! $ok) {
                $problems[] = 'Gói '.$p->slug.' — '.number_format($rate, 1).' ₫/credit (cần ≥ '
                    .number_format(ProviderCostService::MIN_VND_PER_CREDIT).')';
            }
            $planRows[] = [
                $ok ? '✓' : '❗',
                $p->slug,
                number_format($price).'₫',
                number_format($credits),
                number_format($rate, 1),
                $p->resolution_cap,
                (int) $p->daily_image_limit ?: 'không giới hạn',
            ];
        }

        $this->table(['', 'Gói', 'Giá', 'credit/th', '₫/credit (hoặc chi phí tối đa)', 'Cỡ tối đa', 'Trần ảnh/ngày'], $planRows);
        if ($problems) {
            $this->newLine();
            $this->error('  CÓ '.count($problems).' CHỖ KHÔNG ĐẠT:');
            foreach ($problems as $p) {
                $this->line('   · '.$p);
            }
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('  Mọi dòng ≥ 40 % và mọi gói ≥ '.number_format(ProviderCostService::MIN_VND_PER_CREDIT).' ₫/credit.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * LƯỢT GỌI THẬT theo model trong N ngày — để ĐỐI CHIẾU với hoá đơn nhà cung cấp.
     *
     * Đây là mảnh còn thiếu của "theo dõi giá vốn": số trong CSDL là SUY RA, chỉ hoá đơn mới là
     * sự thật. Hai con số khớp nhau thì bảng giá đúng; lệch thì biết ngay model nào khai sai.
     */
    protected function usageReport(int $days): void
    {
        $days = max(1, min(365, $days));
        $from = now()->subDays($days)->startOfDay();

        $rows = \App\Models\ProviderUsage::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('provider, model, outcome, COUNT(*) as calls, COALESCE(SUM(cost_vnd),0) as cost_vnd')
            ->groupBy('provider', 'model', 'outcome')
            ->orderByDesc('calls')
            ->get();

        $this->newLine();
        $this->line('  <options=bold>LƯỢT GỌI NHÀ CUNG CẤP — '.$days.' ngày gần nhất</> (đối chiếu hoá đơn)');

        if ($rows->isEmpty()) {
            $this->warn('  Chưa có lượt gọi nào trong sổ provider_usage cho khoảng này.');
            $this->line('   · Sổ chỉ có dữ liệu TỪ SAU khi bản này được deploy — các lượt trước đó không có trong sổ.');
            $this->newLine();

            return;
        }

        $this->table(
            ['Provider', 'Model', 'Kết quả', 'Lượt', 'Giá vốn suy ra'],
            $rows->map(fn ($r) => [
                $r->provider,
                $r->model,
                $r->outcome,
                number_format((int) $r->calls),
                $r->outcome === \App\Models\ProviderUsage::OUTCOME_UNKNOWN
                    ? '— (chưa khai giá)'
                    : number_format((float) $r->cost_vnd).'₫',
            ])->all(),
        );

        $known = (float) $rows->where('outcome', '!=', \App\Models\ProviderUsage::OUTCOME_UNKNOWN)->sum('cost_vnd');
        $unknown = (int) $rows->where('outcome', \App\Models\ProviderUsage::OUTCOME_UNKNOWN)->sum('calls');

        $this->line('  Tổng giá vốn SUY RA: <options=bold>'.number_format($known).'₫</>');

        if ($unknown > 0) {
            $this->newLine();
            $this->error('  '.$unknown.' lượt CHƯA KHAI GIÁ VỐN — con số tổng đang THIẾU phần này.');
            $this->line('   · Thêm dòng vào bảng provider_price cho các model ghi "chưa khai giá" ở trên,');
            $this->line('     rồi chạy: php artisan studio:pricing --sync');
        }

        $this->newLine();
    }

    /**
     * Tính lại model_credit_cost từ provider_price bằng CHÍNH công thức của hệ thống.
     *
     * Ghi đè giá bán đang có ⇒ bắt buộc xác nhận (trừ khi --no-interaction, dùng cho CI).
     */
    protected function sync(ProviderCostService $cost): int
    {
        if (! $this->option('no-interaction') && ! $this->confirm('Ghi đè TOÀN BỘ giá bán theo model bằng giá tính từ giá vốn?', false)) {
            $this->warn('Đã bỏ qua.');

            return self::SUCCESS;
        }

        $ratios = ['1:1', '4:3', '3:4', '4:5', '16:9', '9:16', '2:3', '21:9', '19:6'];
        $n = 0;

        foreach (ProviderPrice::all() as $price) {
            if ($price->unit === ProviderPrice::UNIT_MEGAPIXEL) {
                foreach (['1K', '2K'] as $res) {
                    foreach ($ratios as $ratio) {
                        [$w, $h] = $cost->imageDimensions($res, $ratio);
                        $n += $this->upsert($cost, $price, $res, $ratio, ['width' => $w, 'height' => $h]);
                    }
                }
            } elseif ($price->unit === ProviderPrice::UNIT_SECOND) {
                $n += $this->upsert($cost, $price, '', '', ['seconds' => 5]);
            } else {
                $n += $this->upsert($cost, $price, '', '', []);
            }
        }

        $this->info('Đã tính lại '.$n.' dòng giá bán theo model.');

        return self::SUCCESS;
    }

    /** @param  array<string,mixed>  $shape */
    protected function upsert(ProviderCostService $cost, ProviderPrice $price, string $res, string $ratio, array $shape): int
    {
        $credits = $cost->suggestCredits($price->provider, $price->model, $shape);

        ModelCreditCost::updateOrCreate(
            ['provider' => $price->provider, 'model' => $price->model, 'resolution' => $res, 'ratio' => $ratio],
            ['credits' => $credits],
        );

        return 1;
    }

    /**
     * GIÁ VỐN CAO NHẤT TRÊN MỘT CREDIT trong toàn bộ bảng giá.
     *
     * Đây là con số quyết định NGƯỠNG ĐƠN GIÁ TỐI THIỂU của mọi gói bán: để biên ≥ 40 % ở dòng
     * tệ nhất thì ₫/credit ≥ max ÷ 0,60. Với bảng hiện tại max ≈ 700 ₫ (video kling).
     *
     * Dùng luôn cho gói TẶNG: chi phí phơi nhiễm = credit × con số này.
     */
    protected function worstCostPerCredit(): float
    {
        $cost = app(ProviderCostService::class);
        $worst = 0.0;

        foreach (ModelCreditCost::all() as $m) {
            [$w, $h] = ($m->resolution !== '' && $m->ratio !== '')
                ? $cost->imageDimensions($m->resolution, $m->ratio)
                : $cost->imageDimensions('1K', '1:1');

            $q = $cost->quote($m->provider, $m->model, ['width' => $w, 'height' => $h, 'seconds' => 5]);
            if (! $q['known'] || $m->credits < 1) {
                continue;
            }

            $worst = max($worst, (float) $q['cost_vnd'] / (int) $m->credits);
        }

        return $worst > 0 ? $worst : (float) ProviderCostService::MIN_VND_PER_CREDIT * 0.6;
    }

    /** @return array<string,float> slug gói ⇒ ₫/credit */
    protected function planRates(): array
    {
        return Plan::where('price_vnd', '>', 0)->where('credits_per_month', '>', 0)
            ->get()
            ->mapWithKeys(fn ($p) => [$p->slug => round($p->price_vnd / (int) $p->credits_per_month, 1)])
            ->all();
    }

    /** @return array{width?:int,height?:int,seconds?:float} */
    protected function shapeFor(ProviderCostService $cost, string $resolution, string $ratio): array
    {
        if ($resolution === '' || $ratio === '') {
            // Dòng "mọi cỡ": lấy trường hợp ĐẮT NHẤT (1K 1:1 = 2 MP) để không đánh giá thấp giá vốn.
            [$w, $h] = $cost->imageDimensions('1K', '1:1');

            return ['width' => $w, 'height' => $h, 'seconds' => 5];
        }

        [$w, $h] = $cost->imageDimensions($resolution, $ratio);

        return ['width' => $w, 'height' => $h, 'seconds' => 5];
    }
}
