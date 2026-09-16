<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Central order placement logic shared by full and quick checkout.
 */
class CheckoutService
{
    /**
     * Create an order from the current cart and return it.
     *
     * @param  array<string,mixed>  $data
     */
    public function place(CartService $cart, array $data, ?int $userId, string $paymentMethod): Order
    {
        if (! empty($data['shipping_method'])) {
            $cart->setShippingMethod($data['shipping_method']);
        }

        $payload = $cart->payload();

        $order = DB::transaction(function () use ($cart, $data, $userId, $paymentMethod, $payload) {
            $cart->cart(true);

            $cartModel = $cart->cart();

            $order = Order::create([
                'order_number' => Order::generateNumber(),
                'user_id' => $userId,
                'email' => $data['email'] ?? null,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'address' => $data['address'] ?? null,
                'ward' => $data['ward'] ?? null,
                'district' => $data['district'] ?? null,
                'province' => $data['province'] ?? null,
                'note' => $data['note'] ?? null,
                'subtotal' => $payload['subtotal'],
                'discount' => $payload['discount'],
                'shipping_fee' => $payload['shipping_fee'],
                'tax' => 0,
                'total' => $payload['total'],
                'shipping_method' => $payload['shipping_method']['name'] ?? null,
                'payment_method' => $paymentMethod,
                'payment_status' => 'unpaid',
                'coupon_id' => $cartModel->coupon_id,
                'status' => Order::STATUS_PENDING,
                'placed_at' => now(),
            ]);

            foreach ($cartModel->items as $item) {
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'product_name' => $item->product?->name ?? 'Sản phẩm',
                    'sku' => $item->product?->sku,
                    'options' => $item->options,
                    'price' => $item->price,
                    'quantity' => $item->quantity,
                    'subtotal' => round($item->price * $item->quantity, 2),
                    'image' => $item->product?->image,
                ]);

                $this->stockOut($item->product_id, $item->variant_id, $item->quantity);
            }

            if ($cartModel->coupon) {
                $cartModel->coupon->increment('used_count');
            }

            return $order;
        });

        return $order;
    }

    protected function stockOut(int $productId, ?int $variantId, int $quantity): void
    {
        if ($variantId) {
            ProductVariant::where('id', $variantId)->decrement('stock', $quantity);
            $product = Product::find($productId);
            if ($product) {
                $product->increment('sales_count', $quantity);
                $product->stock = $product->variants()->sum('stock');
                $product->save();
            }
        } else {
            Product::where('id', $productId)->decrement('stock', $quantity);
            Product::where('id', $productId)->increment('sales_count', $quantity);
        }
    }
}
