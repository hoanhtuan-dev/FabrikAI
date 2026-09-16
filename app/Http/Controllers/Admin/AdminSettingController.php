<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminSettingController extends Controller
{
    public function index()
    {
        return view('admin.settings.index');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:255'],
            'site_tagline' => ['nullable', 'string', 'max:255'],
            'site_email' => ['nullable', 'email'],
            'site_phone' => ['nullable', 'string', 'max:30'],
            'site_address' => ['nullable', 'string', 'max:500'],
            'facebook' => ['nullable', 'url'],
            'instagram' => ['nullable', 'url'],
            'tiktok' => ['nullable', 'url'],
            'youtube' => ['nullable', 'url'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0'],
            'default_shipping_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($data as $key => $value) {
            set_setting($key, $value);
        }

        return back()->with('success', 'Đã lưu cài đặt cửa hàng.');
    }
}
