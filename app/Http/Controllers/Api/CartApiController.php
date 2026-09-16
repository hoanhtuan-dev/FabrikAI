<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CartService;
use Illuminate\Http\Request;

class CartApiController extends Controller
{
    public function __construct(protected CartService $cart)
    {
    }

    public function index()
    {
        return response()->json($this->cart->payload());
    }

    public function add(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer',
            'quantity' => 'nullable|integer|min:1|max:999',
        ]);

        try {
            $item = $this->cart->add($data['product_id'], $data['variant_id'] ?? null, $data['quantity'] ?? 1);

            return response()->json([
                'message' => 'Đã thêm vào giỏ hàng.',
                'item' => $item,
                'cart' => $this->cart->payload(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'quantity' => 'required|integer|min:0|max:999',
        ]);

        $this->cart->update($data['id'], $data['quantity']);

        return response()->json(['message' => 'Đã cập nhật.', 'cart' => $this->cart->payload()]);
    }

    public function remove(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer']);
        $this->cart->remove($data['id']);

        return response()->json(['message' => 'Đã xóa.', 'cart' => $this->cart->payload()]);
    }

    public function clear()
    {
        $this->cart->clear();

        return response()->json(['message' => 'Đã xóa giỏ hàng.', 'cart' => $this->cart->payload()]);
    }

    public function shipping(Request $request)
    {
        $data = $request->validate(['code' => 'required|string']);
        $this->cart->setShippingMethod($data['code']);

        return response()->json(['message' => 'Đã chọn phương thức vận chuyển.', 'cart' => $this->cart->payload()]);
    }
}
