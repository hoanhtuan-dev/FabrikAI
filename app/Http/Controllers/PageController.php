<?php

namespace App\Http\Controllers;

use App\Models\CustomPage;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function about()
    {
        seo()->title('Về chúng tôi | '.setting('site_name'))
            ->description('Tìm hiểu về sứ mệnh và giá trị của Trillfa Fa.')
            ->canonical(route('page.about'));

        return view('pages.about');
    }

    public function contact()
    {
        seo()->title('Liên hệ | '.setting('site_name'))
            ->description('Liên hệ với Trillfa Fa để được hỗ trợ.')
            ->canonical(route('page.contact'));

        return view('pages.contact');
    }

    public function sendContact(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // Demo: log the contact message.
        logger()->info('Contact form submitted', $request->only(['name', 'email', 'subject', 'message']));

        return back()->with('success', 'Cảm ơn bạn đã liên hệ. Chúng tôi sẽ phản hồi sớm nhất.');
    }

    public function faq()
    {
        seo()->title('Câu hỏi thường gặp | '.setting('site_name'))
            ->description('Giải đáp các câu hỏi thường gặp về mua sắm tại Trillfa Fa.')
            ->canonical(route('page.faq'));

        return view('pages.faq');
    }

    public function privacy()
    {
        seo()->title('Chính sách bảo mật | '.setting('site_name'))
            ->description('Chính sách bảo mật thông tin của Trillfa Fa.')
            ->canonical(route('page.privacy'));

        return view('pages.privacy');
    }

    public function terms()
    {
        seo()->title('Điều khoản sử dụng | '.setting('site_name'))
            ->description('Điều khoản sử dụng dịch vụ của Trillfa Fa.')
            ->canonical(route('page.terms'));

        return view('pages.terms');
    }

    /**
     * Render a custom / landing page created in the admin.
     */
    public function show(string $slug)
    {
        $page = CustomPage::where('slug', $slug)->where('is_active', true)->firstOrFail();

        seo()->title(($page->meta_title ?: $page->hero_heading ?: $page->title).' | '.setting('site_name'))
            ->description($page->meta_description ?: $page->excerpt)
            ->canonical($page->url);

        $products = $page->products();

        return view('pages.custom', compact('page', 'products'));
    }
}