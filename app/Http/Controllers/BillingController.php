<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gói đăng ký (billing) — cho NGƯỜI DÙNG tự chọn gói.
 *
 * · catalog(): danh mục gói công khai (trang giá / trang đăng ký dùng).
 * · subscribe(): tự đăng ký một gói (auth + can-studio). MVP chưa có cổng thanh toán VNĐ —
 *   đăng ký gói trả phí hiện = "kích hoạt gói" (tặng bonus + đặt hạn +1 tháng); việc thu tiền
 *   (VNPay/MoMo/chuyển khoản) tích hợp sau — xem PRICING.md §4.
 *
 * Gán gói thực tế đi qua App\Services\PlanService (đường DUY NHẤT đổi plan_id/plan_expires_at),
 * sổ cái credit do CreditService ghi — controller này chỉ làm cổng HTTP.
 */
class BillingController extends Controller
{
    /**
     * Ba nhóm khách hàng của FabrikAI — nội dung của TRANG GIÁ công khai.
     *
     * Vì sao đặt ở đây (không hardcode trong blade): đây là DỮ LIỆU mô tả sản phẩm, và trang giá
     * phải nói đúng nỗi đau + việc cần làm của từng nhóm, kèm gói khởi đầu và gói để lớn lên. Gói
     * được tham chiếu bằng SLUG nên khi chủ dự án đổi giá/credit thì trang tự đúng theo DB.
     */
    protected const PERSONAS = [
        [
            'icon' => 'pencil',
            'title' => 'Nhà thiết kế thời trang',
            'pain' => 'Mỗi bộ sưu tập cần hàng chục biến thể phom · dáng · chất liệu để chào khách. Thuê studio chụp lại tốn vài ngày và vài triệu đồng mỗi buổi.',
            'want' => ['Nhiều biến thể nhanh từ một ý tưởng', 'Giữ đúng phom và chất liệu gốc', 'Prompt tái dùng theo bộ sưu tập', 'Ảnh 2K để in lookbook'],
            'start' => 'starter',
            'grow' => 'pro',
        ],
        [
            'icon' => 'briefcase',
            'title' => 'Chủ doanh nghiệp · thương hiệu',
            'pain' => 'Cần ảnh đăng bán đều mỗi tuần cho hàng chục SKU, nhưng chi phí chụp ảnh tăng theo số SKU và không đoán trước được.',
            'want' => ['Ra ảnh đều đặn, chi phí biết trước', 'Ảnh chuẩn sàn thương mại điện tử', 'Duyệt nội bộ trước khi đăng', 'Theo dõi credit theo tháng'],
            'start' => 'pro',
            'grow' => 'studio',
        ],
        [
            'icon' => 'scissors',
            'title' => 'Chủ xưởng may',
            'pain' => 'Nhận yêu cầu mẫu từ khách nhưng phải chờ ảnh và mô tả thật rõ mới dám cắt — sai một chi tiết là tốn vải và tốn công thợ.',
            'want' => ['Ảnh chi tiết đúng kỹ thuật', 'Mô tả chất liệu rõ ràng', 'Chốt mẫu nhanh với khách', 'Tái dùng mẫu cũ cho đơn mới'],
            'start' => 'starter',
            'grow' => 'studio',
        ],
    ];

    /**
     * GET /bang-gia — TRANG GIÁ CÔNG KHAI (không cần đăng nhập).
     *
     * Vì sao cần: trước đây KHÔNG có bề mặt nào cho khách chưa đăng nhập xem gói (không có view
     * pricing, trang đăng ký không nhắc gói nào) ⇒ khách phải tạo tài khoản mới biết có gói gì.
     * Trang render phía máy chủ (không cần JS) nên nhanh, xem được trên mọi thiết bị, chia sẻ được.
     *
     * Giá/credit LUÔN lấy từ bảng plans đang mở bán ⇒ không bao giờ lệch với thực tế hệ thống.
     */
    public function pricingPage(): \Illuminate\View\View
    {
        $plans = Plan::query()->where('is_active', true)->orderBy('sort')->get();

        // Gói nào được đề xuất cho nhóm khách nào (suy từ PERSONAS — không viết tay trong view).
        $recommended = [];
        foreach (self::PERSONAS as $persona) {
            foreach ([$persona['start'], $persona['grow']] as $slug) {
                $recommended[$slug][] = $persona['title'];
            }
        }

        return view('pricing', [
            'plans' => $plans,
            'personas' => self::PERSONAS,
            'recommended' => $recommended,
            'freePlan' => $plans->first(fn (Plan $p) => $p->isFree()),
            'cheapestPaid' => $plans->first(fn (Plan $p) => ! $p->isFree()),
            // [Q2 — 2026-09-19] Cách thanh toán thật (chuyển khoản + kênh hỗ trợ) để trang giá nói được
            // bước tiếp theo thay vì chỉ "chưa có cổng thanh toán". Cùng nguồn với popup trong Studio.
            'payment' => self::paymentInfo(),
        ]);
    }

    /** GET /api/billing/plans — danh mục gói đang mở bán (không cần đăng nhập). */
    public function catalog(): JsonResponse
    {
        $plans = Plan::query()->where('is_active', true)->orderBy('sort')->get();

        return response()->json([
            'plans' => $plans->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'tagline' => $p->tagline,
                'price_vnd' => (int) $p->price_vnd,
                'price_label' => $p->priceLabel(),
                'credits_per_month' => (int) $p->credits_per_month,
                'bonus_credits' => (int) $p->bonus_credits,
                'resolution_cap' => $p->resolution_cap,
                'features' => $p->features ?? [],
                'is_default' => (bool) $p->is_default,
                'is_free' => $p->isFree(),
            ]),
        ]);
    }


    /**
     * THÔNG TIN THANH TOÁN + HỖ TRỢ (Q2 — 2026-09-19) — một nguồn duy nhất.
     *
     * Vì sao để ở controller chứ không viết thẳng vào blade/JS: cùng một thông tin được dùng bởi
     * trang giá công khai, popup "Gói & credit" trong Studio và màn hình Quản trị. Ba chỗ chép tay
     * là ba chỗ lệch nhau khi chủ dự án đổi số tài khoản.
     *
     * Giá trị do chủ dự án đặt trong Quản trị (`studio_config`); chưa đặt thì trả rỗng và giao diện
     * phải nói thật là "chưa cấu hình" chứ KHÔNG bịa số tài khoản.
     */
    public static function paymentInfo(): array
    {
        return [
            'bank' => [
                'name' => (string) studio_config('bank_name', ''),
                'account' => (string) studio_config('bank_account', ''),
                'holder' => (string) studio_config('bank_holder', ''),
                'branch' => (string) studio_config('bank_branch', ''),
            ],
            'support' => [
                'phone' => (string) studio_config('support_phone', ''),
                'email' => (string) studio_config('support_email', ''),
                'zalo' => (string) studio_config('support_zalo', ''),
                'hours' => (string) studio_config('support_hours', '8h30 – 18h, thứ 2 – thứ 7'),
            ],
            // VNPay cần mã đối tác + khoá bí mật của merchant; CHƯA có nên tuyệt đối không giả vờ đã có.
            'vnpay' => [
                'available' => filter_var(studio_config('vnpay_enabled', false), FILTER_VALIDATE_BOOLEAN),
                'note' => 'VNPay chưa mở — để lại thông tin, FabrikAI sẽ liên hệ ngay khi kênh này hoạt động.',
            ],
        ];
    }

    /** Hình dạng một yêu cầu nâng cấp trả cho khách (không lộ ghi chú nội bộ của admin). */
    protected function presentRequest(UpgradeRequest $r): array
    {
        return [
            'id' => $r->id,
            'code' => $r->code,
            'status' => $r->status,
            'status_label' => $r->statusLabel(),
            'is_open' => $r->isOpen(),
            'plan' => $r->plan ? ['id' => $r->plan->id, 'name' => $r->plan->name, 'slug' => $r->plan->slug] : null,
            'months' => $r->months,
            'amount_vnd' => $r->amount_vnd,
            'amount_label' => $r->amountLabel(),
            'method' => $r->method,
            'method_label' => $r->methodLabel(),
            'contact_phone' => $r->contact_phone,
            'note' => $r->note,
            'created_at' => $r->created_at?->format('d/m/Y H:i'),
            'handled_at' => $r->handled_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * POST /api/billing/upgrade-request — khách GỬI YÊU CẦU NÂNG CẤP.
     *
     * Thay cho việc bấm một nút là gói trả phí tự kích hoạt (không có dấu vết thanh toán). Yêu cầu
     * có MÃ theo dõi, chốt SỐ TIỀN tại thời điểm gửi, và đi kèm hướng dẫn chuyển khoản nếu khách
     * chọn phương thức đó.
     *
     * Chống trùng: khách bấm gửi hai lần cho cùng một gói ⇒ trả lại yêu cầu đang mở (không rải
     * nhiều yêu cầu rác cho chủ dự án phải xử lý).
     */
    public function upgradeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'months' => ['required', 'integer', 'in:'.implode(',', UpgradeRequest::MONTHS)],
            'method' => ['required', 'string', 'in:'.implode(',', UpgradeRequest::METHODS)],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $plan = Plan::query()->where('is_active', true)->findOrFail((int) $data['plan_id']);
        $user = $request->user();

        if ($plan->isFree()) {
            return response()->json([
                'message' => 'Gói miễn phí không cần thanh toán — hãy chuyển thẳng bằng nút «Dùng gói miễn phí».',
                'code' => 'plan_is_free',
            ], 422);
        }

        // Số điện thoại Việt Nam: bỏ khoảng trắng/dấu chấm/gạch rồi kiểm 10–11 số bắt đầu bằng 0.
        $phone = preg_replace('/[\s.\-()]/', '', (string) $data['contact_phone']);
        if (! preg_match('/^0\d{9,10}$/', (string) $phone)) {
            return response()->json([
                'message' => 'Số điện thoại chưa hợp lệ — nhập số Việt Nam (vd 0901234567) để FabrikAI liên hệ được.',
                'code' => 'phone_invalid',
            ], 422);
        }

        $months = (int) $data['months'];
        $existing = UpgradeRequest::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->whereIn('status', [UpgradeRequest::STATUS_PENDING, UpgradeRequest::STATUS_CONTACTED])
            ->latest('id')
            ->first();

        if ($existing) {
            return response()->json([
                'ok' => true,
                'reused' => true,
                'request' => $this->presentRequest($existing),
                'payment' => self::paymentInfo(),
                'message' => 'Bạn đã có yêu cầu '.$existing->code.' đang chờ xử lý cho gói này — không cần gửi lại.',
            ]);
        }

        $created = DB::transaction(function () use ($user, $plan, $months, $phone, $data) {
            return UpgradeRequest::create([
                'code' => UpgradeRequest::nextCode(),
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'months' => $months,
                'amount_vnd' => (int) $plan->price_vnd * $months,
                'method' => $data['method'],
                'contact_name' => $data['contact_name'] ?? $user->name,
                'contact_phone' => $phone,
                'note' => $data['note'] ?? null,
                'status' => UpgradeRequest::STATUS_PENDING,
            ]);
        });

        return response()->json([
            'ok' => true,
            'reused' => false,
            'request' => $this->presentRequest($created),
            'payment' => self::paymentInfo(),
            'message' => 'Đã gửi yêu cầu '.$created->code.' — FabrikAI sẽ liên hệ theo số '.$phone.' để xác nhận.',
        ], 201);
    }

    /**
     * GET /api/billing/upgrade-request — yêu cầu đang mở (nếu có) + hướng dẫn thanh toán.
     * Dùng khi mở popup "Gói & credit": khách thấy ngay "đang chờ xử lý mã UP-…" thay vì gửi trùng.
     */
    public function upgradeStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        $open = UpgradeRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [UpgradeRequest::STATUS_PENDING, UpgradeRequest::STATUS_CONTACTED])
            ->with('plan')
            ->latest('id')
            ->first();

        $latestActivated = UpgradeRequest::query()
            ->where('user_id', $user->id)
            ->where('status', UpgradeRequest::STATUS_ACTIVATED)
            ->with('plan')
            ->latest('handled_at')
            ->first();

        return response()->json([
            'open' => $open ? $this->presentRequest($open) : null,
            'last_activated' => $latestActivated ? $this->presentRequest($latestActivated) : null,
            'methods' => [
                ['value' => UpgradeRequest::METHOD_BANK, 'label' => 'Chuyển khoản ngân hàng', 'hint' => 'Chuyển khoản rồi FabrikAI kích hoạt trong vài giờ làm việc.'],
                ['value' => UpgradeRequest::METHOD_VNPAY, 'label' => 'VNPay (chưa mở)', 'hint' => 'Để lại thông tin — FabrikAI liên hệ ngay khi kênh VNPay hoạt động.'],
                ['value' => UpgradeRequest::METHOD_SUPPORT, 'label' => 'Nhờ FabrikAI hỗ trợ', 'hint' => 'FabrikAI gọi lại tư vấn gói phù hợp với khối lượng thật của bạn.'],
            ],
            'months' => UpgradeRequest::MONTHS,
            'payment' => self::paymentInfo(),
        ]);
    }

    /**
     * POST /api/billing/subscribe — tự đăng ký gói.
     *
     * [Q2 — 2026-09-19] CHỈ GÓI MIỄN PHÍ. Trước đây endpoint này gán được CẢ gói trả phí: khách bấm
     * một nút trong Studio là có gói 499.000 ₫ mà không có cổng thanh toán, không dấu vết ai trả
     * tiền, trả bao nhiêu. Nay gói trả phí phải đi qua YÊU CẦU NÂNG CẤP (có mã theo dõi) và chủ dự
     * án kích hoạt sau khi nhận tiền — Super Admin vẫn gán trực tiếp được bằng đường quản trị.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        // Chỉ cho đăng ký gói ĐANG MỞ BÁN (gói bị ẩn không chọn được).
        $plan = Plan::query()->where('is_active', true)->findOrFail((int) $data['plan_id']);
        $user = $request->user();

        if (! $plan->isFree() && ! $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Gói trả phí cần xác nhận thanh toán. Hãy gửi «Yêu cầu nâng cấp» — '
                    .'FabrikAI sẽ liên hệ và kích hoạt gói cho bạn.',
                'code' => 'payment_required',
                'redirect' => '/bang-gia',
            ], 402);
        }

        app(PlanService::class)->assign($user, $plan);

        $fresh = $user->fresh();

        return response()->json([
            'ok' => true,
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price_label' => $plan->priceLabel(),
                'is_free' => $plan->isFree(),
            ],
            'credits_balance' => (int) $fresh->credits_balance,
            'plan_expires_at' => $fresh->plan_expires_at?->format('d/m/Y'),
        ]);
    }
}
