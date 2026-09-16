<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function dashboard()
    {
        $user = auth()->user();
        $orders = $user->orders()->withCount('items')->latest()->limit(5)->get();
        $orderCount = $user->orders()->count();
        $wishlistCount = $user->wishlistProducts()->count();
        $addressCount = $user->addresses()->count();

        return view('account.dashboard', compact('user', 'orders', 'orderCount', 'wishlistCount', 'addressCount'));
    }

    public function orders()
    {
        $orders = auth()->user()->orders()->with('items')->latest()->paginate(10);

        return view('account.orders', compact('orders'));
    }

    public function order(Order $order)
    {
        abort_unless($order->user_id === auth()->id(), 403);

        return view('account.order', compact('order'));
    }

    public function cancelOrder(Request $request, Order $order)
    {
        abort_unless($order->user_id === auth()->id(), 403);

        if (! $order->can_cancel) {
            return back()->with('error', 'Đơn hàng này không thể hủy.');
        }

        $order->update(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()]);

        // Restore stock
        foreach ($order->items as $item) {
            if ($item->variant_id) {
                \App\Models\ProductVariant::where('id', $item->variant_id)->increment('stock', $item->quantity);
            } else {
                \App\Models\Product::where('id', $item->product_id)->increment('stock', $item->quantity);
            }
        }

        return back()->with('success', 'Đơn hàng đã được hủy.');
    }

    public function profile()
    {
        return view('account.profile', ['user' => auth()->user()]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.auth()->id()],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        auth()->user()->update($data);

        return back()->with('success', 'Đã cập nhật thông tin tài khoản.');
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($data['current_password'], auth()->user()->password)) {
            return back()->withErrors(['current_password' => 'Mật khẩu hiện tại không đúng.']);
        }

        auth()->user()->update(['password' => $data['password']]);

        return back()->with('success', 'Đã đổi mật khẩu thành công.');
    }

    public function addresses()
    {
        $addresses = auth()->user()->addresses()->orderByDesc('is_default')->get();

        return view('account.addresses', compact('addresses'));
    }

    public function storeAddress(Request $request)
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'min:5'],
            'ward' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $user = auth()->user();

        if ($request->boolean('is_default')) {
            $user->addresses()->update(['is_default' => false]);
        }

        $user->addresses()->create($data + ['is_default' => $request->boolean('is_default')]);

        return back()->with('success', 'Đã thêm địa chỉ mới.');
    }

    public function updateAddress(Request $request, Address $address)
    {
        abort_unless($address->user_id === auth()->id(), 403);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'min:5'],
            'ward' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
        ]);

        if ($request->boolean('is_default')) {
            auth()->user()->addresses()->update(['is_default' => false]);
            $data['is_default'] = true;
        }

        $address->update($data);

        return back()->with('success', 'Đã cập nhật địa chỉ.');
    }

    public function deleteAddress(Request $request, Address $address)
    {
        abort_unless($address->user_id === auth()->id(), 403);
        $address->delete();

        return back()->with('success', 'Đã xóa địa chỉ.');
    }

    public function reviews()
    {
        $reviews = auth()->user()->reviews()->with('product')->latest()->paginate(10);

        return view('account.reviews', compact('reviews'));
    }
}
