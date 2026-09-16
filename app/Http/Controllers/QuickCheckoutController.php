<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * "Thanh toán nhanh" — place an order with just name + phone (COD),
 * silently persisting a pending customer account for guests.
 */
class QuickCheckoutController extends Controller
{
    public function __construct(
        protected CartService $cart,
        protected CheckoutService $checkout,
    ) {
    }

    public function form()
    {
        if ($this->cart->count() === 0) {
            return redirect()->route('cart.show');
        }

        $payload = $this->cart->payload();

        return view('checkout.quick', compact('payload'));
    }

    public function store(Request $request)
    {
        if ($this->cart->count() === 0) {
            return back()->withErrors(['cart' => 'Giỏ hàng trống.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:2'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s.-]{8,20}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'terms' => ['accepted'],
        ]);

        // Resolve the customer: an existing account by phone, or a silently
        // persisted "pending" account so the info is saved for later completion.
        $user = auth()->user() ?? $this->resolveUser($data);

        $order = $this->checkout->place($this->cart, [
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => null,
            'address' => $data['address'] ?? null,
            'note' => $data['note'] ?? null,
        ], $user?->id, 'cod');

        $this->cart->clear();
        session()->forget('shipping_method');
        session()->put('quick_order_id', $order->id);

        return redirect()->route('checkout.quick-success', $order);
    }

    /**
     * Success page. If the order belongs to a still-inactive (pending) account,
     * encourage the guest to complete registration so they can manage the order,
     * otherwise let them keep shopping while staff confirms by phone.
     */
    public function success(Order $order)
    {
        $this->authorizeOrder($order);

        $pendingUser = ($order->user && ! $order->user->is_active) ? $order->user : null;

        return view('checkout.quick-success', compact('order', 'pendingUser'));
    }

    /**
     * Complete a silently-created pending account (email + password) and log in.
     */
    public function completeForm(Order $order)
    {
        $this->authorizeOrder($order);

        $user = $order->user;

        if (! $user || $user->is_active) {
            abort(404, 'Không tìm thấy tài khoản chờ hoàn thiện.');
        }

        return view('checkout.complete', compact('order', 'user'));
    }

    public function completeStore(Request $request, Order $order)
    {
        $this->authorizeOrder($order);

        $user = $order->user;

        if (! $user || $user->is_active) {
            abort(404, 'Không tìm thấy tài khoản chờ hoàn thiện.');
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->update([
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $order->update(['email' => $data['email']]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('account.dashboard')
            ->with('success', 'Tài khoản đã được hoàn thiện. Các đơn hàng của bạn được liên kết với tài khoản.');
    }

    /**
     * Allow only the session that just placed this quick order (or the owning
     * logged-in user) to access its confirmation / completion pages.
     */
    protected function authorizeOrder(Order $order): void
    {
        $ownsSessionOrder = (int) session('quick_order_id', 0) === $order->id;
        $ownsAsUser = auth()->check() && $order->user_id === auth()->id();

        if (! $ownsSessionOrder && ! $ownsAsUser) {
            abort(404, 'Không tìm thấy đơn hàng.');
        }
    }

    /**
     * Find an existing user by phone, or silently create a pending one.
     */
    protected function resolveUser(array $data): ?User
    {
        $user = User::where('phone', $data['phone'])->first();

        if ($user) {
            return $user;
        }

        return User::create([
            'name' => $data['name'],
            'email' => $this->guestEmail($data['phone']),
            'phone' => $data['phone'],
            'password' => Str::random(16),
            'role' => 'customer',
            'is_active' => false,
        ]);
    }

    protected function guestEmail(string $phone): string
    {
        return 'guest.'.substr(md5($phone), 0, 10).'@trillfa.guest';
    }
}