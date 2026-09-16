<?php

namespace App\Modules\Storefront\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Storefront\StorefrontBridge;
use Illuminate\Http\Request;

/**
 * Public storefront controller. Thin: delegates all data shaping to the bridge
 * and only cares about returning the mount view (boot payload) or JSON feed.
 */
class StorefrontController extends Controller
{
    public function __construct(protected StorefrontBridge $bridge)
    {
    }

    /**
     * The homepage — renders the Vue SPA mount view with a boot payload.
     */
    public function home()
    {
        // SEO + org schema (same intent as the old HomeController).
        seo()->organization()->website();

        return view('storefront.index', [
            'boot' => $this->bridge->homePayload(),
        ]);
    }

    /**
     * JSON feed — lets the SPA re-request a fresh payload after a cache expiry.
     */
    public function feed()
    {
        return response()->json($this->bridge->homePayload());
    }

    /**
     * Shop listing (shop + /danh-muc/{category}) — renders the Vue shop SPA,
     * or returns JSON when the request asks for it (filter/sort/page updates).
     */
    public function shop(Request $request, ?string $categorySlug = null)
    {
        $filters = [
            'q' => $request->input('q', ''),
            'brand' => $request->input('brand', ''),
            'min_price' => $request->input('min_price', ''),
            'max_price' => $request->input('max_price', ''),
            'sort' => $request->input('sort', 'newest'),
        ];
        $page = (int) $request->input('page', 1);

        $payload = $this->bridge->shop($categorySlug, $filters, $page);

        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        if ($categorySlug && $payload['category_name']) {
            seo()->title($payload['category_name'].' | '.setting('site_name'))
                ->canonical(route('shop.category', $categorySlug));
        } else {
            seo()->title('Cửa hàng | '.setting('site_name'))
                ->canonical(route('shop.index'));
        }
        seo()->description('Khám phá bộ sưu tập thời trang & phong cách sống tại Trillfa Fa.');

        $payload['slug'] = $categorySlug;

        return view('storefront.shop', [
            'boot' => $payload,
        ]);
    }

    /**
     * Cart page — renders the Vue cart SPA (cart data is client-side via /api/cart).
     */
    public function cart()
    {
        seo()->title('Giỏ hàng | '.setting('site_name'));

        return view('storefront.cart', ['boot' => $this->bridge->base()]);
    }

    /**
     * Account dashboard — renders the Vue account SPA (auth).
     */
    public function accountDashboard()
    {
        seo()->title('Tài khoản | '.setting('site_name'));

        return view('storefront.account', ['boot' => $this->bridge->accountDashboard()]);
    }

    public function accountOrders()
    {
        seo()->title('Đơn hàng | '.setting('site_name'));

        return view('storefront.account', ['boot' => $this->bridge->accountOrders()]);
    }

    public function accountOrder(\App\Models\Order $order)
    {
        abort_unless($order->user_id === auth()->id(), 403);

        seo()->title('Đơn hàng '.$order->order_number.' | '.setting('site_name'));

        return view('storefront.account', ['boot' => $this->bridge->accountOrder($order)]);
    }

    public function accountProfile()
    {
        seo()->title('Hồ sơ | '.setting('site_name'));

        return view('storefront.account', ['boot' => $this->bridge->accountProfile()]);
    }

    public function accountAddresses()
    {
        seo()->title('Địa chỉ | '.setting('site_name'));

        return view('storefront.account', ['boot' => $this->bridge->accountAddresses()]);
    }

    public function accountReviews()
    {
        seo()->title('Đánh giá | '.setting('site_name'));

        return view('storefront.account', ['boot' => $this->bridge->accountReviews()]);
    }

    public function accountPassword()
    {
        seo()->title('Mật khẩu | '.setting('site_name'));

        return view('storefront.account', ['boot' => $this->bridge->accountPassword()]);
    }

    public function checkoutPay(\App\Models\Order $order)
    {
        if ($order->user_id && $order->user_id !== auth()->id()) {
            abort(403);
        }

        return view('storefront.checkout-result', ['boot' => $this->bridge->checkoutPay($order)]);
    }

    public function checkoutSuccess(\App\Models\Order $order)
    {
        if ($order->user_id && $order->user_id !== auth()->id()) {
            abort(403);
        }

        return view('storefront.checkout-result', ['boot' => $this->bridge->checkoutSuccess($order)]);
    }

    /**
     * Quick-view product data (JSON) — full product for the modal.
     */
    public function productQuickView(int $id)
    {
        try {
            return response()->json($this->bridge->productForQuickView($id));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        }
    }

    /**
     * Static content page — renders the Vue static SPA.
     */
    public function staticPage(string $key, ?string $slug = null)
    {
        // Route /trang/{slug} binds the slug to $key — treat non-static keys as slugs.
        $knownKeys = ['about', 'contact', 'faq', 'privacy', 'terms'];
        if ($slug === null && ! in_array($key, $knownKeys, true)) {
            $slug = $key;
            $key = '';
        }
        $payload = $this->bridge->staticPage($key, $slug);
        $title = $payload['page']['title'] ?? 'Trang';

        // Intro giờ là rich text (admin soạn bằng rich editor) — bỏ thẻ HTML khi dùng cho SEO description.
        $introText = trim(strip_tags((string) ($payload['page']['intro'] ?? '')));

        seo()->title($title.' | '.setting('site_name'))->description($introText);

        return view('storefront.static', ['boot' => $payload]);
    }

    /**
     * Auth page (login/register) — renders the Vue auth SPA. Submission posts
     * natively to the existing AuthController login/register endpoints.
     */
    public function auth(string $mode)
    {
        seo()->title(($mode === 'register' ? 'Đăng ký' : 'Đăng nhập').' | '.setting('site_name'));

        return view('storefront.auth', [
            'boot' => array_merge($this->bridge->base(), ['mode' => $mode]),
        ]);
    }

    /**
     * Checkout page — renders the Vue checkout SPA (order is created by the
     * existing CheckoutController@store endpoint).
     */
    public function checkout()
    {
        if (app(\App\Services\CartService::class)->count() === 0) {
            return redirect()->route('cart.show');
        }

        seo()->title('Thanh toán | '.setting('site_name'));

        return view('storefront.checkout', ['boot' => $this->bridge->checkout()]);
    }

    /**
     * Wishlist page — renders the Vue wishlist SPA (ids are client-side).
     */
    public function wishlist()
    {
        seo()->title('Yêu thích | '.setting('site_name'));

        return view('storefront.wishlist', ['boot' => $this->bridge->base()]);
    }

    /**
     * Wishlist JSON feed — returns products for the given saved ids.
     */
    public function wishlistFeed(Request $request)
    {
        $ids = explode(',', (string) $request->input('ids', ''));

        return response()->json(['products' => $this->bridge->wishlistProducts($ids)]);
    }

    /**
     * Blog index — renders the Vue blog SPA.
     */
    public function blogIndex()
    {
        seo()->title('Blog | '.setting('site_name'))->canonical(route('blog.index'));

        return view('storefront.blog', ['boot' => $this->bridge->blogIndex()]);
    }

    /**
     * Blog single post — renders the Vue blog-post SPA.
     */
    public function blogPost(string $slug)
    {
        try {
            $payload = $this->bridge->blogPost($slug);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        }

        seo()->title($payload['post']['title'].' | '.setting('site_name'))
            ->description($payload['post']['excerpt'])
            ->canonical(route('blog.show', $slug))
            ->image($payload['post']['image']);

        return view('storefront.blog-post', ['boot' => $payload]);
    }

    /**
     * Product detail page — renders the Vue product SPA.
     */
    public function product(string $slug)
    {
        try {
            $payload = $this->bridge->productDetail($slug);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            abort(404);
        }

        $p = $payload['product'];
        seo()->title($p['name'].' | '.setting('site_name'))
            ->description($p['short_description'] ?: setting('site_name'))
            ->canonical($p['url'])
            ->image($p['image']);

        // Khôi phục LD+JSON Product server-side (mất khi chuyển SPA — chỉ bổ sung,
        // không đổi payload Vue). Truyền URL sẵn có thay vì gọi route() lại.
        $productModel = \App\Models\Product::where('slug', $slug)->first();
        if ($productModel) {
            seo()->product($productModel, $p['url']);
        }

        return view('storefront.product', [
            'boot' => $payload,
        ]);
    }
}
