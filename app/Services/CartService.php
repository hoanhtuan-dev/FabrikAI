<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartService
{
    protected ?Cart $cart = null;

    /**
     * Resolve the active cart and (optionally) reload its relations.
     */
    public function cart(bool $reload = false): Cart
    {
        if ($this->cart && ! $reload) {
            return $this->cart;
        }

        if (auth()->check()) {
            $cart = Cart::firstOrCreate(['user_id' => auth()->id()]);
            $this->mergeGuestCart($cart);
        } else {
            $cart = Cart::firstOrCreate([
                'session_id' => $this->guestToken(),
                'user_id' => null,
            ]);
        }

        return $this->cart = $cart->load('items.product', 'items.variant', 'coupon');
    }

    public function add(int $productId, ?int $variantId = null, int $quantity = 1)
    {
        $product = Product::active()->with('variants')->find($productId);

        if (! $product) {
            throw new \Exception('Sản phẩm không tồn tại hoặc ngừng bán.');
        }

        $quantity = max(1, min(999, $quantity));
        $variant = null;
        $price = (float) $product->price;
        $stock = (int) $product->stock;
        $options = null;

        if ($variantId) {
            $variant = $product->variants->where('id', $variantId)->first();
            if ($variant) {
                $price = (float) ($variant->price ?? $product->price);
                $stock = (int) $variant->stock;
                $options = $variant->options;
            }
        } elseif ($product->variants->isNotEmpty()) {
            $variant = $product->variants->first();
            $variantId = $variant->id;
            $price = (float) ($variant->price ?? $product->price);
            $stock = (int) $variant->stock;
            $options = $variant->options;
        }

        if ($stock <= 0) {
            throw new \Exception('Sản phẩm đã hết hàng.');
        }

        $cart = $this->cart();
        $item = $cart->items()
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->first();

        $newQty = ($item?->quantity ?? 0) + $quantity;
        if ($stock && $newQty > $stock) {
            $newQty = $stock;
        }

        if ($item) {
            $item->quantity = $newQty;
            $item->price = $price;
            $item->save();
            $this->refreshCart();

            return $item;
        }

        $created = $cart->items()->create([
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => $newQty,
            'price' => $price,
            'options' => $options,
        ]);
        $this->refreshCart();

        return $created;
    }

    public function update(int $itemId, int $quantity): void
    {
        $item = $this->cart()->items()->findOrFail($itemId);
        $quantity = max(0, min(999, $quantity));

        if ($quantity < 1) {
            $item->delete();
            $this->refreshCart();

            return;
        }

        $item->quantity = $quantity;
        $item->save();
        $this->refreshCart();
    }

    public function remove(int $itemId): void
    {
        $this->cart()->items()->findOrFail($itemId)->delete();
        if ($this->cart()->items()->count() === 0) {
            $this->cart()->coupon()->dissociate();
            $this->cart()->save();
        }
        $this->refreshCart();
    }

    public function clear(): void
    {
        $cart = $this->cart();
        $cart->items()->delete();
        $cart->coupon()->dissociate();
        $cart->save();
        $this->refreshCart();
    }

    public function applyCoupon(string $code): Coupon
    {
        $coupon = Coupon::where('code', $code)->first();

        if (! $coupon) {
            throw new \Exception('Mã giảm giá không tồn tại.');
        }

        $subtotal = $this->subtotal();

        if (! $coupon->isValid($subtotal)) {
            throw new \Exception('Mã giảm giá không khả dụng với đơn hàng này.');
        }

        $this->cart()->coupon()->associate($coupon);
        $this->cart()->save();
        $this->refreshCart();

        return $coupon;
    }

    public function removeCoupon(): void
    {
        $this->cart()->coupon()->dissociate();
        $this->cart()->save();
        $this->refreshCart();
    }

    public function subtotal(): float
    {
        return (float) $this->cart(true)->items->sum(fn ($i) => (float) $i->price * (int) $i->quantity);
    }

    public function discount(): float
    {
        $coupon = $this->cart(true)->coupon;

        return $coupon ? $coupon->discountFor($this->subtotal()) : 0.0;
    }

    public function shippingFee(): float
    {
        $method = $this->shippingMethod();
        if (! $method) {
            return 0.0;
        }

        if ($method->free_threshold !== null && $this->subtotal() >= (float) $method->free_threshold) {
            return 0.0;
        }

        return (float) $method->fee;
    }

    public function shippingMethods()
    {
        return ShippingMethod::active()->orderBy('sort_order')->get();
    }

    public function shippingMethod(): ?ShippingMethod
    {
        $code = session()->get('shipping_method');
        if ($code) {
            $method = ShippingMethod::active()->where('code', $code)->first();
            if ($method) {
                return $method;
            }
        }

        return $this->shippingMethods()->first();
    }

    public function setShippingMethod(string $code): void
    {
        if (ShippingMethod::active()->where('code', $code)->exists()) {
            session()->put('shipping_method', $code);
        }
    }

    public function total(): float
    {
        return $this->subtotal() - $this->discount() + $this->shippingFee();
    }

    public function count(): int
    {
        return (int) $this->cart(true)->items->sum('quantity');
    }

    public function payload(): array
    {
        $cart = $this->cart(true);
        $shippingMethod = $this->shippingMethod();

        $items = $cart->items->map(function ($item) {
            $product = $item->product;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'name' => $product?->name ?? 'Sản phẩm',
                'slug' => $product?->slug,
                'image' => $product?->image_url,
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
                'line_total' => round((float) $item->price * $item->quantity, 2),
                'stock' => $item->variant?->stock ?? $product?->stock ?? 0,
                'options' => $item->options,
                'variant_name' => $item->variant?->name,
                'url' => $product ? route('product.show', $product->slug) : null,
            ];
        })->values();

        return [
            'items' => $items,
            'count' => $this->count(),
            'subtotal' => round($this->subtotal(), 2),
            'discount' => round($this->discount(), 2),
            'shipping_fee' => round($this->shippingFee(), 2),
            'total' => round($this->total(), 2),
            'coupon' => $cart->coupon ? [
                'code' => $cart->coupon->code,
                'type' => $cart->coupon->type,
                'value' => (float) $cart->coupon->value,
                'discount' => round($this->discount(), 2),
            ] : null,
            'shipping_method' => $shippingMethod ? [
                'code' => $shippingMethod->code,
                'name' => $shippingMethod->name,
                'fee' => (float) $shippingMethod->fee,
                'free_threshold' => $shippingMethod->free_threshold !== null ? (float) $shippingMethod->free_threshold : null,
                'estimated_days' => $shippingMethod->estimated_days,
            ] : null,
            'shipping_methods' => $this->shippingMethods()->map(fn ($m) => [
                'code' => $m->code,
                'name' => $m->name,
                'fee' => (float) $m->fee,
                'free_threshold' => $m->free_threshold !== null ? (float) $m->free_threshold : null,
                'estimated_days' => $m->estimated_days,
            ])->values(),
        ];
    }

    /**
     * A stable guest-cart key kept in session data (survives session-id
     * migration on login and per-request regeneration in tests).
     */
    protected function guestToken(): string
    {
        $token = session('guest_cart_token');

        if ($token) {
            return $token;
        }

        $token = Str::random(32);
        session(['guest_cart_token' => $token]);

        return $token;
    }

    protected function refreshCart(): void
    {
        $this->cart = $this->cart(true);
    }

    protected function mergeGuestCart(Cart $userCart): void
    {
        $guestCart = Cart::with('items')
            ->whereNull('user_id')
            ->where('session_id', $this->guestToken())
            ->first();

        if (! $guestCart || $guestCart->id === $userCart->id) {
            return;
        }

        DB::transaction(function () use ($guestCart, $userCart) {
            foreach ($guestCart->items as $item) {
                $existing = $userCart->items()
                    ->where('product_id', $item->product_id)
                    ->where('variant_id', $item->variant_id)
                    ->first();

                $qty = ($existing?->quantity ?? 0) + (int) $item->quantity;
                if ($existing) {
                    $existing->update(['quantity' => $qty]);
                } else {
                    $userCart->items()->create([
                        'product_id' => $item->product_id,
                        'variant_id' => $item->variant_id,
                        'quantity' => $qty,
                        'price' => $item->price,
                        'options' => $item->options,
                    ]);
                }
            }
            $guestCart->delete();
        });

        $this->refreshCart();
    }
}