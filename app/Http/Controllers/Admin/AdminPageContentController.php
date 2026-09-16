<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminPageContentController extends Controller
{
    public function about()
    {
        return view('admin.pages.about');
    }

    public function updateAbout(Request $request)
    {
        $data = $request->validate([
            'about_heading' => ['required', 'string', 'max:255'],
            'about_intro' => ['nullable', 'string', 'max:5000'],
            'about_body' => ['nullable', 'string'],
            'about_image' => ['nullable', 'image', 'max:4096'],
            'about_v1_title' => ['nullable', 'string', 'max:255'],
            'about_v1_text' => ['nullable', 'string', 'max:2000'],
            'about_v2_title' => ['nullable', 'string', 'max:255'],
            'about_v2_text' => ['nullable', 'string', 'max:2000'],
            'about_v3_title' => ['nullable', 'string', 'max:255'],
            'about_v3_text' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($data as $key => $value) {
            set_setting($key, $value);
        }

        // Hồ sơ thương hiệu (dùng cho AI sản phẩm) đã thay đổi → làm mới cache
        // để lần gợi ý/tinh chỉnh sau của AI dùng nội dung mới nhất.
        \Illuminate\Support\Facades\Cache::forget('product_ai:brand_ctx');

        if ($request->hasFile('about_image')) {
            set_setting('about_image', $request->file('about_image')->store('settings', 'public'));
        } elseif ($request->boolean('about_image_remove')) {
            set_setting('about_image', null);
        }

        return back()->with('success', 'Đã lưu nội dung trang Giới thiệu.');
    }

    public function contact()
    {
        return view('admin.pages.contact');
    }

    public function updateContact(Request $request)
    {
        $data = $request->validate([
            'contact_heading' => ['required', 'string', 'max:255'],
            'contact_intro' => ['nullable', 'string', 'max:2000'],
            'contact_hours' => ['nullable', 'string', '255'],
        ]);

        foreach ($data as $key => $value) {
            set_setting($key, $value);
        }

        return back()->with('success', 'Đã lưu nội dung trang Liên hệ.');
    }
}
