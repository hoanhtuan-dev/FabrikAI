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
