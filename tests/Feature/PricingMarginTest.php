<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\ModelCreditCost;
use App\Models\Plan;
use App\Models\ProviderPrice;
use App\Models\ProviderUsage;
use App\Models\User;
use App\Services\ProviderCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BIÊN LỢI NHUẬN — các bất biến khoá lại sự cố kinh tế phát hiện 2026-09-26.
 *
 * VÌ SAO BỘ TEST NÀY TỒN TẠI: trước đây MỌI model đều bán 1 credit, trong khi giá vốn lệch hơn 300
 * lần giữa model rẻ nhất và đắt nhất. Đo được ba đường LỖ ÂM THẦM:
 *     flux-pro/fill 2K  −262 %   ·   veo3  −189 %   ·   flux-pro/fill 1K  −45 %
 * Không có test nào bắt được vì "lỗ" là chuyện của SỐ, không phải của mã — và bảng \`generations\`
 * chỉ ghi khách trả bao nhiêu, không ghi ta trả bao nhiêu.
 *
 * Bất biến (mỗi bài dưới đây khoá một điều):
 *   (a) MỌI gói giữ ₫/credit ≥ 1.200 — nếu không thì dòng đắt nhất không thể đạt 40 %;
 *   (b) MỌI dòng giá bán đạt biên ≥ 40 % ở đơn giá TỆ NHẤT trong các gói;
 *   (c) megapixel được làm tròn LÊN đúng như fal tính tiền (1024×1024 = 2 MP, không phải 1,049);
 *   (d) giá bán theo model thắng giá phẳng của gói, nhưng CHƯA KHAI thì rơi về giá của gói;
 *   (e) sổ chi phí ghi theo TỪNG LẦN GỌI và không bao giờ ĐOÁN giá;
 *   (f) trần tạo ảnh/ngày CHẶN được và SỬA ĐƯỢC;
 *   (g) gói Miễn phí mở ĐẦY ĐỦ tính năng (cổng chặn là credit, không phải tính năng).
 */
class PricingMarginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    // ── (a) BẤT BIẾN CỦA GÓI ─────────────────────────────────────────────────────────────

    public function test_every_plan_keeps_the_minimum_credit_rate(): void
    {
        $checked = 0;

        foreach (Plan::all() as $plan) {
            $credits = (int) $plan->credits_per_month;
            // ⚠️ GÓI KHÔNG BÁN (giá 0) KHÔNG ĐO ĐƯỢC BẰNG ₫/credit — chia cho doanh thu bằng 0
            // là vô nghĩa. Bắt được trên production 2026-09-26: gói `free` có 50 credit/tháng,
            // giá 0 ⇒ phép kiểm báo "0 ₫/credit, cần ≥ 1.200" — ĐÚNG SỐ nhưng SAI VIỆC.
            // Gói tặng không có biên lợi nhuận để bảo vệ; thứ đo được là CHI PHÍ PHƠI NHIỄM
            // (bài `test_the_free_plan_exposure_is_bounded` ngay dưới).
            if ($credits <= 0 || (int) $plan->price_vnd <= 0) {
                continue;
            }

            $rate = (int) $plan->price_vnd / $credits;
            $checked++;

            $this->assertGreaterThanOrEqual(
                ProviderCostService::MIN_VND_PER_CREDIT,
                $rate,
                'Gói '.$plan->slug.' chỉ đạt '.round($rate, 1).' ₫/credit — dưới ngưỡng '
                .ProviderCostService::MIN_VND_PER_CREDIT.' ⇒ dòng đắt nhất (video, 700 ₫/credit) sẽ dưới 40 %.',
            );
        }

        $this->assertGreaterThanOrEqual(4, $checked, 'Phải có ít nhất 4 gói trả phí được kiểm.');
    }

    public function test_the_seasonal_plan_was_corrected_instead_of_silently_losing_money(): void
    {
        // Đây là gói DUY NHẤT không đạt ngưỡng ở bản đầu (3.290.000 ÷ 3.000 = 1.096,7 ₫/credit
        // ⇒ biên 36,2 %). Bài này khoá lại cách sửa đã chọn: TĂNG GIÁ, giữ nguyên 3.000 credit —
        // vì "3.000 credit/vụ" là lời hứa trên trang giá, còn giá chỉ ảnh hưởng lượt mua sau.
        $plan = Plan::where('slug', 'factory_season')->firstOrFail();

        $this->assertSame(3000, (int) $plan->credits_per_month, 'Không được giảm số credit đã hứa.');
        $this->assertGreaterThanOrEqual(3600000, (int) $plan->price_vnd);
    }

    // ── (b) BẤT BIẾN CỦA TỪNG DÒNG GIÁ BÁN ───────────────────────────────────────────────

    public function test_every_model_credit_row_reaches_the_margin_floor(): void
    {
        $cost = app(ProviderCostService::class);

        $rates = Plan::where('price_vnd', '>', 0)->where('credits_per_month', '>', 0)->get()
            ->map(fn (Plan $p) => $p->price_vnd / (int) $p->credits_per_month);
        $worstRate = (float) $rates->min();

        $rows = ModelCreditCost::all();
        $this->assertGreaterThan(0, $rows->count(), 'Bảng giá theo model phải được seed.');

        $below = [];

        foreach ($rows as $row) {
            [$w, $h] = ($row->resolution !== '' && $row->ratio !== '')
                ? $cost->imageDimensions($row->resolution, $row->ratio)
                : $cost->imageDimensions('1K', '1:1'); // dòng "mọi cỡ" ⇒ lấy trường hợp đắt nhất

            $quote = $cost->quote($row->provider, $row->model, ['width' => $w, 'height' => $h, 'seconds' => 5]);

            if (! $quote['known']) {
                $below[] = $row->provider.'/'.$row->model.' — CHƯA KHAI GIÁ VỐN';
                continue;
            }

            $revenue = $row->credits * $worstRate;
            $margin = ($revenue - (float) $quote['cost_vnd']) / $revenue * 100;

            if ($margin < 40) {
                $below[] = $row->provider.'/'.$row->model.' ('.$row->resolution.' '.$row->ratio.')'
                    .' = '.round($margin, 1).' %';
            }
        }

        $this->assertSame([], $below, "Các dòng dưới 40 %:\n · ".implode("\n · ", $below));
    }

    // ── (c) MEGAPIXEL LÀM TRÒN LÊN ───────────────────────────────────────────────────────

    public function test_megapixels_are_rounded_up_exactly_like_fal_bills(): void
    {
        $cost = app(ProviderCostService::class);

        // fal: "billed by rounding up to the nearest megapixel".
        // 1024×1024 = 1,049 MP ⇒ 2 MP. Tính sai chỗ này là đánh giá thấp giá vốn gần MỘT NỬA ở
        // đúng tỉ lệ 1:1 — tỉ lệ phổ biến nhất.
        $this->assertSame(2, $cost->billableMegapixels(1024, 1024));
        $this->assertSame(1, $cost->billableMegapixels(1024, 768));
        $this->assertSame(5, $cost->billableMegapixels(2048, 2048));
        $this->assertSame(4, $cost->billableMegapixels(1638, 2048));

        // Và ảnh 1:1 ở 1K phải đắt GẤP ĐÔI ảnh 4:5 ở 1K — đây là hệ quả người dùng nhìn thấy.
        [$w1, $h1] = $cost->imageDimensions('1K', '1:1');
        [$w2, $h2] = $cost->imageDimensions('1K', '4:5');
        $this->assertSame(2, $cost->billableMegapixels($w1, $h1));
        $this->assertSame(1, $cost->billableMegapixels($w2, $h2));
    }

    // ── (d) GIÁ THEO MODEL THẮNG GIÁ CỦA GÓI ─────────────────────────────────────────────

    public function test_per_model_credits_win_over_the_plan_flat_rate(): void
    {
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $user->forceFill(['plan_id' => Plan::where('slug', 'pro')->value('id')])->save();

        // Gói khai 1 credit/ảnh — nhưng sửa ảnh có mask ở 2K 4:5 tốn 8 credit (giá vốn 5.200 ₫).
        $this->assertSame(8, studio_credit_cost_for('image', 'fal', 'fal-ai/flux-pro/v1/fill', '2K', '4:5', $user));
        $this->assertSame(4, studio_credit_cost_for('image', 'fal', 'fal-ai/flux-pro/v1/fill', '1K', '1:1', $user));
        $this->assertSame(2, studio_credit_cost_for('image', 'fal', 'fal-ai/flux-pro/v1/fill', '1K', '4:5', $user));

        // Tạo ảnh nhanh vẫn 1 credit — đường phổ biến nhất KHÔNG bị đắt lên.
        $this->assertSame(1, studio_credit_cost_for('image', 'fal', 'fal-ai/flux/schnell', '1K', '4:5', $user));
    }

    public function test_an_unpriced_model_falls_back_to_the_plan_rate_instead_of_breaking(): void
    {
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        // Model lạ (chưa khai giá) ⇒ rơi về giá của GÓI, đúng hành vi cũ. Hệ thống KHÔNG vỡ khi
        // bảng giá chưa được seed — đây là điều kiện để deploy an toàn.
        $this->assertSame(
            studio_credit_cost('image', $user),
            studio_credit_cost_for('image', 'some-vendor', 'model-chua-khai', '2K', '1:1', $user),
        );
    }

    // ── (e) SỔ CHI PHÍ ───────────────────────────────────────────────────────────────────

    public function test_cost_is_recorded_per_provider_call_and_never_guessed(): void
    {
        $cost = app(ProviderCostService::class);
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        $gen = Generation::create([
            'user_id' => $user->id, 'type' => 'image', 'status' => 'completed',
            'provider' => 'fal', 'model' => 'fal-ai/flux-pro/v1/fill',
            'resolution' => '2K', 'ratio' => '4:5', 'credits_cost' => 8,
        ]);

        // 2K 4:5 = 4 MP (làm tròn lên) × $0,05/MP × 26.000 = 5.200 ₫.
        $usage = $cost->record($gen, 'fal', 'fal-ai/flux-pro/v1/fill', ['width' => 1638, 'height' => 2048]);

        $this->assertNotNull($usage);
        $this->assertSame(4.0, (float) $usage->units);
        $this->assertSame('megapixel', $usage->unit);
        $this->assertEqualsWithDelta(5200.0, (float) $usage->cost_vnd, 1.0);
        $this->assertSame(ProviderUsage::OUTCOME_OK, $usage->outcome);

        // Sổ là nguồn sự thật; generations.cost_vnd chỉ là bản sao để báo cáo đọc nhanh.
        $cost->syncGenerationCost($gen);
        $this->assertEqualsWithDelta(5200.0, (float) $gen->fresh()->cost_vnd, 1.0);
    }

    public function test_a_failed_call_is_recorded_but_not_charged(): void
    {
        $cost = app(ProviderCostService::class);
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        $gen = Generation::create([
            'user_id' => $user->id, 'type' => 'image', 'status' => 'failed',
            'provider' => 'fal', 'model' => 'fal-ai/flux/dev', 'resolution' => '1K', 'ratio' => '4:5',
        ]);

        $usage = $cost->record($gen, 'fal', 'fal-ai/flux/dev', ['width' => 1024, 'height' => 819], ProviderUsage::OUTCOME_FAILED);

        // Vẫn GHI dòng (để đếm được chi phí thật của chuỗi dự phòng) nhưng tiền = 0.
        $this->assertNotNull($usage);
        $this->assertSame(ProviderUsage::OUTCOME_FAILED, $usage->outcome);
        $this->assertSame(0.0, (float) $usage->cost_vnd);
    }

    public function test_an_unknown_price_is_recorded_as_unknown_instead_of_a_made_up_number(): void
    {
        $cost = app(ProviderCostService::class);
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        $gen = Generation::create([
            'user_id' => $user->id, 'type' => 'image', 'status' => 'completed',
            'provider' => 'nha-cung-cap-moi', 'model' => 'model-chua-biet-gia',
        ]);

        $usage = $cost->record($gen, 'nha-cung-cap-moi', 'model-chua-biet-gia');

        // THÀ THIẾU MỘT DÒNG CÒN HƠN GHI SỐ SAI: một con số đoán bừa làm mọi báo cáo sau đó vô nghĩa.
        $this->assertNotNull($usage);
        $this->assertSame(ProviderUsage::OUTCOME_UNKNOWN, $usage->outcome);
        $this->assertNull($usage->cost_usd);
        $this->assertNull($usage->cost_vnd);
    }

    public function test_the_profit_report_flags_a_losing_model(): void
    {
        $cost = app(ProviderCostService::class);
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        $gen = Generation::create([
            'user_id' => $user->id, 'type' => 'video', 'status' => 'completed',
            'provider' => 'fal', 'model' => 'fal-ai/kling-video/v2.5-turbo/pro/text-to-video',
        ]);

        $cost->record($gen, 'fal', 'fal-ai/kling-video/v2.5-turbo/pro/text-to-video', ['seconds' => 5]);

        $report = $cost->marginReport(now()->subDay(), now());

        $this->assertGreaterThan(0, $report['cost_vnd']);
        $this->assertNotEmpty($report['rows']);
        $this->assertArrayHasKey('margin_pct', $report['rows'][0]);
        $this->assertFalse($report['rows'][0]['losing'], 'Video kling ở 13 credit phải CÓ LÃI.');
    }

    // ── (f) TRẦN TẠO ẢNH/NGÀY ────────────────────────────────────────────────────────────

    public function test_the_daily_image_limit_blocks_and_is_editable(): void
    {
        $plan = Plan::where('slug', 'starter')->firstOrFail();
        $this->assertSame(60, (int) $plan->daily_image_limit);

        // Sửa được bằng DỮ LIỆU (đây là yêu cầu "giới hạn tạo ảnh có thể sửa được").
        $plan->forceFill(['daily_image_limit' => 2])->save();

        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $user->forceFill(['plan_id' => $plan->id, 'credits_balance' => 5000])->save();

        for ($i = 0; $i < 2; $i++) {
            Generation::create([
                'user_id' => $user->id, 'type' => 'image', 'status' => 'completed',
                'provider' => 'fal', 'model' => 'fal-ai/flux/schnell',
            ]);
        }

        $this->actingAs($user)
            ->postJson('/api/generate', ['prompt' => 'áo sơ mi nữ', 'type' => 'image'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'daily_limit_reached')
            ->assertJsonPath('limit', 2)
            ->assertJsonPath('used', 2);
    }

    // ── (g) GÓI MIỄN PHÍ MỞ ĐẦY ĐỦ TÍNH NĂNG ─────────────────────────────────────────────

    public function test_the_free_plan_unlocks_every_module(): void
    {
        $free = Plan::where('slug', 'free')->firstOrFail();
        $all = \App\Support\ModuleRegistry::allIds();

        $granted = $free->modules();

        $this->assertSame(
            [],
            array_values(array_diff($all, $granted)),
            'Gói Miễn phí phải mở ĐỦ tính năng — cổng chặn của gói free là CREDIT, không phải tính năng.',
        );

        // Và cơ chế thực thi phải đồng ý với bản khai.
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $user->forceFill(['plan_id' => $free->id])->save();

        foreach ($all as $id) {
            $this->assertTrue(
                module_allowed($user->fresh(), $id),
                'Gói Miễn phí bị khoá module '.$id.' — trái với chính sách đã chốt.',
            );
        }
    }

    // ── (h) QUẢN TRỊ SỬA ĐƯỢC GIÁ ────────────────────────────────────────────────────────

    public function test_an_owner_can_edit_the_sell_price_of_a_model(): void
    {
        $owner = User::where('email', 'owner@fabrikai.shop')->firstOrFail();
        $row = ModelCreditCost::where('model', 'fal-ai/flux/schnell')->firstOrFail();

        $this->actingAs($owner)
            ->getJson('/api/admin/model-credits')
            ->assertOk()
            ->assertJsonStructure(['rows', 'plan_rates', 'min_rate_vnd', 'min_required_vnd', 'fx_rate']);

        $this->actingAs($owner)
            ->putJson('/api/admin/model-credits/'.$row->id, ['credits' => 3])
            ->assertOk()
            ->assertJsonPath('row.credits', 3);

        // Giá vốn KHÔNG sửa được qua đường này — đó là sự thật của nhà cung cấp, không phải lựa chọn.
        $this->assertDatabaseHas('provider_price', ['model' => 'fal-ai/flux/schnell', 'unit' => 'megapixel']);
    }

    public function test_a_non_owner_cannot_read_the_cost_ledger(): void
    {
        $customer = User::where('email', 'user@fabrikai.shop')->firstOrFail();

        // Giá vốn là DỮ LIỆU CẠNH TRANH — rò rỉ là mất lợi thế.
        $this->actingAs($customer)->getJson('/api/admin/model-credits')->assertForbidden();
        $this->actingAs($customer)->getJson('/api/admin/profit')->assertForbidden();
    }

    // ── (i) ĐƯỜNG TIỀN THẬT: số credit bị TRỪ khi gọi API ────────────────────────────────

    public function test_the_generate_endpoint_charges_the_price_of_the_actual_model_and_size(): void
    {
        $user = User::where('email', 'user@fabrikai.shop')->firstOrFail();
        $user->forceFill([
            'plan_id' => Plan::where('slug', 'pro')->value('id'),
            'credits_balance' => 1000,
        ])->save();

        // Đọc số credit ĐÃ TRỪ từ chính bản ghi generation (credits_cost) chứ không từ số dư:
        // lượt gọi đầu tiên còn kèm việc cấp credit chu kỳ của gói (syncCycleCredits), nên số dư
        // nhảy lên rồi mới trừ — đo bằng số dư ở đây là đo nhầm hai thứ cùng lúc.
        $charge = function (array $payload) use ($user): int {
            $this->actingAs($user)->postJson('/api/generate', $payload + [
                'prompt' => 'áo sơ mi nữ dáng suông',
                'provider' => 'fal',
                'resolution' => '1K',
                'variants' => 1,
            ])->assertOk();

            return (int) Generation::where('user_id', $user->id)->latest('id')->firstOrFail()->credits_cost;
        };

        // CÙNG độ phân giải, KHÁC model: giá theo model, không phải một giá cho mọi thứ.
        $this->assertSame(1, $charge(['model' => 'fal-ai/flux/schnell']),
            'Tạo ảnh nhanh phải tốn 1 credit — đường phổ biến nhất KHÔNG được đắt lên.');
        $this->assertSame(2, $charge(['model' => 'fal-ai/flux/dev', 'ratio' => '1:1']),
            'Tạo ảnh khá 1K 1:1 (2 MP do làm tròn lên) phải tốn 2 credit.');
        $this->assertSame(1, $charge(['model' => 'fal-ai/flux/dev', 'ratio' => '4:5']),
            'Tạo ảnh khá 1K 4:5 (1 MP) chỉ tốn 1 credit — nếu là 2 thì tỉ lệ phổ biến nhất bị thu đắt.');

        // Sổ cái phải ghi ĐÚNG số đã trừ — sổ và tiền không được lệch nhau.
        $spent = (int) \App\Models\CreditTransaction::where('user_id', $user->id)->where('type', 'spend')->sum('amount');
        $this->assertSame(-4, $spent, 'Sổ cái phải ghi đúng tổng credit đã trừ (1 + 2 + 1).');
    }

    // ── (j) TÊN NHÀ CUNG CẤP: nhiều tên, MỘT hoá đơn ──────────────────────────────────────

    public function test_provider_aliases_resolve_to_one_invoice(): void
    {
        $cost = app(ProviderCostService::class);

        // Đo trên production: Model Registry khai `qwen-paygo`, bảng giá khoá `dashscope`.
        // Không chuẩn hoá thì MỌI lượt tra giá đều trượt và sổ chi phí ghi 'unknown_cost' cho gần hết.
        $this->assertSame('dashscope', $cost->normalizeProvider('qwen-paygo'));
        $this->assertSame('dashscope', $cost->normalizeProvider('qwen'));
        $this->assertSame('dashscope', $cost->normalizeProvider('wan'));
        $this->assertSame('fal', $cost->normalizeProvider('flux'));
        $this->assertSame('fal', $cost->normalizeProvider('fal-ai'));
        // Tên lạ GIỮ NGUYÊN — để nó lộ ra trong báo cáo chứ không bị nuốt im lặng.
        $this->assertSame('nha-cung-cap-la', $cost->normalizeProvider('nha-cung-cap-la'));

        // Và giá phải tra được qua tên gốc của registry.
        $this->assertNotNull($cost->priceFor('qwen-paygo', 'qwen-image-edit-2511'));
        $this->assertNotNull($cost->priceFor('flux', 'fal-ai/flux-2-flex'));
    }

    // ── (k) GIÁ BẬC THANG + SỐ MẶT TÍNH TIỀN ─────────────────────────────────────────────

    public function test_tiered_pricing_matches_how_fal_actually_bills(): void
    {
        $cost = app(ProviderCostService::class);

        // fal-ai/flux-2-pro: "$0.03 for the FIRST megapixel of output, plus $0.015 per EXTRA
        // megapixel" — 1024x1024 = 2 MP do làm tròn lên ⇒ 0,03 + 0,015 = 0,045 USD.
        $pro = $cost->quote('fal', 'fal-ai/flux-2-pro', ['width' => 1024, 'height' => 1024]);
        $this->assertTrue($pro['known']);
        $this->assertSame(2.0, (float) $pro['units']);
        $this->assertEqualsWithDelta(0.045, (float) $pro['cost_usd'], 0.000001);

        // fal-ai/flux-2-pro/edit: CÙNG công thức nhưng tính CẢ mặt vào LẪN mặt ra (sides = 2).
        // Quên điều này là ĐÁNH GIÁ THẤP giá vốn một nửa ở đúng đường đắt nhất.
        $edit = $cost->quote('fal', 'fal-ai/flux-2-pro/edit', ['width' => 1024, 'height' => 1024]);
        $this->assertSame(4.0, (float) $edit['units']);
        $this->assertEqualsWithDelta(0.075, (float) $edit['cost_usd'], 0.000001);

        // Giá PHẲNG (first_unit_price_usd = NULL) giữ nguyên hành vi cũ — không được đổi.
        $flat = $cost->quote('fal', 'fal-ai/flux-pro/v1/fill', ['width' => 1024, 'height' => 768]);
        $this->assertEqualsWithDelta(0.05, (float) $flat['cost_usd'], 0.000001);
    }

    public function test_the_free_plan_exposure_is_bounded(): void
    {
        // Gói TẶNG không có biên để bảo vệ — nhưng nó KHÔNG được là lỗ vô hạn. Hai thứ chặn nó:
        //   · số credit cấp (credits_per_month + bonus lúc đăng ký),
        //   · TRẦN ẢNH/NGÀY (daily_image_limit).
        // Bài này khoá cả hai, để một lần sửa form gói không âm thầm mở đường đốt tiền.
        $cost = app(ProviderCostService::class);

        // Giá vốn cao nhất trên mỗi credit trong toàn bảng — mức tệ nhất khách có thể tiêu.
        $worstPerCredit = 0.0;
        foreach (ModelCreditCost::all() as $m) {
            [$w, $h] = ($m->resolution !== '' && $m->ratio !== '')
                ? $cost->imageDimensions($m->resolution, $m->ratio)
                : $cost->imageDimensions('1K', '1:1');
            $q = $cost->quote($m->provider, $m->model, ['width' => $w, 'height' => $h, 'seconds' => 5]);
            if ($q['known'] && $m->credits >= 1) {
                $worstPerCredit = max($worstPerCredit, (float) $q['cost_vnd'] / (int) $m->credits);
            }
        }
        $this->assertGreaterThan(0, $worstPerCredit, 'Phải có ít nhất một dòng giá để đo.');

        foreach (Plan::where('price_vnd', 0)->get() as $free) {
            // Trần ảnh/ngày là BẮT BUỘC với gói tặng — không có nó thì một tài khoản đốt hết
            // credit trong một phiên, và không có gì chặn việc tạo lại tài khoản.
            $this->assertGreaterThan(
                0,
                (int) $free->daily_image_limit,
                'Gói tặng '.$free->slug.' PHẢI có trần ảnh/ngày — nếu không, chi phí không bị chặn.',
            );

            // Và phơi nhiễm tối đa mỗi tài khoản phải nằm ở mức chấp nhận được (dưới 200.000 ₫).
            $exposure = (int) $free->credits_per_month * $worstPerCredit;
            $this->assertLessThan(
                200000,
                $exposure,
                'Gói tặng '.$free->slug.' có thể đốt tới '.number_format($exposure).'₫ mỗi tài khoản.',
            );
        }
    }

    // ── (l) TRANG GIÁ KHÔNG ĐƯỢC TỰ MÂU THUẪN ────────────────────────────────────────────

    public function test_the_pricing_page_copy_does_not_contradict_the_real_credit_numbers(): void
    {
        // LỖI THẬT đo trên https://fabrikai.shop/bang-gia (2026-09-26): migration nâng
        // `credits_per_month` 120→155 nhưng KHÔNG đụng `features` (mảng chuỗi tiếp thị do chủ dự án
        // viết) ⇒ thẻ gói ghi "155 credit" còn danh sách đặc quyền ghi "120 credit mỗi tháng".
        // Khách đọc thấy HAI câu trả lời khác nhau cho cùng một câu hỏi, trên trang niêm yết giá.
        //
        // Bất biến: MỌI con số credit xuất hiện trong chữ của một gói phải khớp credits_per_month
        // của chính gói đó.
        $problems = [];

        foreach (Plan::all() as $plan) {
            $credits = (int) $plan->credits_per_month;
            if ($credits <= 0) {
                continue;
            }

            $haystack = implode(' | ', array_map('strval', (array) $plan->features));

            // Bắt mọi cụm "<số> credit" trong chữ (chấp nhận dấu phân cách nghìn kiểu Việt Nam).
            if (preg_match_all('/([0-9][0-9.,]*)\s*credit/i', $haystack, $m)) {
                foreach ($m[1] as $raw) {
                    $n = (int) preg_replace('/[^0-9]/', '', $raw);
                    if ($n > 0 && $n !== $credits) {
                        $problems[] = $plan->slug.': chữ ghi "'.$raw.' credit" nhưng credits_per_month = '.$credits;
                    }
                }
            }
        }

        $this->assertSame([], $problems, "Trang giá tự mâu thuẫn:\n · ".implode("\n · ", $problems));
    }

    public function test_every_plan_with_a_daily_cap_says_so_in_its_copy(): void
    {
        // Giới hạn tạo ảnh/ngày là giới hạn THẬT (429) — khách phải biết TRƯỚC khi mua,
        // không phải phát hiện lúc bị chặn.
        foreach (Plan::where('daily_image_limit', '>', 0)->get() as $plan) {
            $haystack = implode(' | ', array_map('strval', (array) $plan->features));

            $this->assertMatchesRegularExpression(
                '/ảnh\/ngày|ảnh mỗi ngày/u',
                $haystack,
                'Gói '.$plan->slug.' có trần '.$plan->daily_image_limit.' ảnh/ngày nhưng chữ không nói ra.',
            );
        }
    }
}
