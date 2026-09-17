<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** POST /api/billing/subscribe — tự đăng ký gói. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        // Chỉ cho đăng ký gói ĐANG MỞ BÁN (gói bị ẩn không chọn được).
        $plan = Plan::query()->where('is_active', true)->findOrFail((int) $data['plan_id']);
        $user = $request->user();

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
