<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminShippingController extends Controller
{
    public function index()
    {
        $methods = ShippingMethod::orderBy('sort_order')->get();

        return view('admin.shipping.index', compact('methods'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['code'] = Str::slug($data['name']);
        ShippingMethod::create($data);

        return back()->with('success', 'Đã thêm phương thức vận chuyển.');
    }

    public function update(Request $request, ShippingMethod $method)
    {
        $data = $this->validated($request);
        $method->update($data);

        return back()->with('success', 'Đã cập nhật phương thức vận chuyển.');
    }

    public function toggleActive(ShippingMethod $method)
    {
        $method->update(['is_active' => ! $method->is_active]);

        return back()->with('success', 'Đã cập nhật trạng thái.');
    }

    public function destroy(ShippingMethod $method)
    {
        $method->delete();

        return back()->with('success', 'Đã xóa phương thức vận chuyển.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'fee' => ['required', 'numeric', 'min:0'],
            'free_threshold' => ['nullable', 'numeric', 'min:0'],
            'estimated_days' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
