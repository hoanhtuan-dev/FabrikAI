<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CartService;
use Illuminate\Http\Request;

class CouponApiController extends Controller
{
    public function __construct(protected CartService $cart)
    {
    }

    public function apply(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:50']);

        try {
            $coupon = $this->cart->applyCoupon($data['code']);

            return response()->json([
                'message' => 'Áp dụng mã giảm giá thành công.',
                'coupon' => ['code' => $coupon->code],
                'cart' => $this->cart->payload(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function remove()
    {
        $this->cart->removeCoupon();

        return response()->json(['message' => 'Đã bỏ mã giảm giá.', 'cart' => $this->cart->payload()]);
    }
}
