<?php

namespace App\Http\Controllers;

use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Services\CartService;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class CheckoutController extends Controller
{
    public function __construct(protected CartService $cart, protected CheckoutService $checkout)
    {
    }

    public function show()
    {
        if ($this->cart->count() === 0) {
            return redirect()->route('cart.show');
        }

        $payload = $this->cart->payload();
        $shippingMethods = $this->cart->shippingMethods();
        $paymentMethods = \App\Models\PaymentMethod::active()->orderBy('sort_order')->get();
        $address = auth()->user()?->addresses()->where('is_default', true)->first();

        return view('checkout.show', compact('payload', 'shippingMethods', 'paymentMethods', 'address'));
    }

    public function store(Request $request)
    {
        if ($this->cart->count() === 0) {
            return back()->withErrors(['cart' => 'Giỏ hàng trống.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s.-]{8,20}$/'],
            'address' => ['required', 'string', 'min:5'],
            'ward' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'shipping_method' => ['nullable', 'string'],
            'payment_method' => ['required', 'string'],
            'terms' => ['accepted'],
        ]);

        $order = $this->checkout->place($this->cart, $data, auth()->id(), $data['payment_method']);

        $isOnline = in_array($data['payment_method'], ['vnpay', 'momo']);
        $this->cart->clear();
        session()->forget('shipping_method');

        try {
            Mail::to($order->email)->send(new OrderConfirmation($order));
        } catch (\Throwable $e) {
            logger()->error('Send order confirmation email failed: '.$e->getMessage());
        }

        if ($isOnline) {
            return redirect()->route('checkout.pay', $order);
        }

        return redirect()->route('checkout.success', $order);
    }

    public function pay(Order $order)
    {
        if ($order->user_id && $order->user_id !== auth()->id()) {
            abort(403);
        }

        return view('checkout.pay', compact('order'));
    }

    public function confirmPay(Request $request, Order $order)
    {
        if ($order->user_id && $order->user_id !== auth()->id()) {
            abort(403);
        }

        $order->update(['payment_status' => 'paid', 'paid_at' => now()]);

        return redirect()->route('checkout.success', $order);
    }

    public function success(Order $order)
    {
        // Prevent a logged-in user from viewing another customer's order.
        if ($order->user_id && $order->user_id !== auth()->id()) {
            abort(403);
        }

        return view('checkout.success', compact('order'));
    }
}