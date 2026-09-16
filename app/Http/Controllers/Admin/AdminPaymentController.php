<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPaymentController extends Controller
{
    public function index()
    {
        $methods = PaymentMethod::orderBy('sort_order')->get();

        return view('admin.payments.index', compact('methods'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:payment_methods,code'],
            'description' => ['nullable', 'string', 'max:500'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['code'] = Str::slug($data['code']);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['fee'] = $data['fee'] ?? 0;

        PaymentMethod::create($data);

        return back()->with('success', 'Đã thêm phương thức thanh toán.');
    }

    public function update(Request $request, PaymentMethod $method)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', $method->is_active);
        $data['fee'] = $data['fee'] ?? $method->fee;

        $method->update($data);

        return back()->with('success', 'Đã cập nhật phương thức thanh toán.');
    }

    public function toggleActive(PaymentMethod $method)
    {
        $method->update(['is_active' => ! $method->is_active]);

        return back()->with('success', 'Đã cập nhật trạng thái.');
    }

    public function destroy(PaymentMethod $method)
    {
        $method->delete();

        return back()->with('success', 'Đã xóa phương thức thanh toán.');
    }
}
