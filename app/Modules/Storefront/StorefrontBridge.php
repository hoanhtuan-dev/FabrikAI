<?php

namespace App\Modules\Storefront;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * StorefrontBridge — the single contract between the Laravel backend and the
 * Vue storefront SPA. Every controller and the boot view pulls data from here
 * so the widget settings, models and JSON shape stay in one place.
 *
 * The outputs are deliberately plain JSON-safe arrays (no Eloquent models, no
 * Carbon, no `url` facades leaking through eager-loading) so the SPA can render
 * them directly, and so the same payload works both as `<script>` boot JSON and
 * as the `/api/storefront/home` JSON feed.
 */
class StorefrontBridge
{
    /**
     * Full homepage payload for the Vue app.
     */
    public function homePayload(): array
    {
        return [
            'site' => $this->site(),
            'user' => $this->user(),
            'nav' => $this->nav('header'),
            'contact' => $this->contact(),
            'hero' => $this->hero(),
            'benefits' => $this->benefits(),
            'categories' => $this->categories(),
            'featured' => $this->productsSection('featured'),
            'new_arrivals' => $this->productsSection('new'),
            'on_sale' => $this->productsSection('sale'),
            'bestsellers' => $this->productsSection('bestsellers'),
            'secondary' => $this->secondary(),
            'blog' => $this->blog(),
            'cta' => $this->cta(),
            'sale_ends_at' => setting('sale_ends_at', ''),
        ];
    }

    /**
     * Shop / category listing payload for the Vue shop SPA.
     * Reuses the same product serializer so cards look identical to home.
     */
    public function shop(?string $categorySlug = null, array $filters = [], int $page = 1): array
    {
        $query = Product::active()->with('category', 'variants');

        $category = null;
        if ($categorySlug) {
            $category = Category::active()->where('slug', $categorySlug)->first();
            if ($category) {
                $ids = collect([$category->id])->merge($category->children->pluck('id'));
                $query->whereIn('category_id', $ids);
            }
        }

        if (! empty($filters['q'])) {
            $query->search($filters['q']);
        }
        if (! empty($filters['brand'])) {
            $query->where('brand', $filters['brand']);
        }
        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $query->where('price', '>=', (float) $filters['min_price']);
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $query->where('price', '<=', (float) $filters['max_price']);
        }

        $sort = $filters['sort'] ?? 'newest';
        $query = match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'popular' => $query->orderByDesc('sales_count'),
            'rating' => $query->orderByDesc('rating_avg'),
            default => $query->orderByDesc('featured')->orderByDesc('id'),
        };

        $p = $query->paginate(12, ['*'], 'page', $page);

        // Merge the shared base (site/nav/user/contact/categories for the
        // layout + footer) so the shop page gets the header/menu/footer too.
        return array_merge($this->base(), [
            'filter_categories' => Category::active()->whereNull('parent_id')
                ->with('children', fn ($q) => $q->active())
                ->orderBy('sort_order')->get()
                ->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'icon' => $c->icon,
                    'icon_image' => $c->icon_image_url,
                    'url' => route('shop.category', $c->slug),
                    'children' => $c->children->map(fn ($ch) => [
                        'name' => $ch->name,
                        'slug' => $ch->slug,
                        'url' => route('shop.category', $ch->slug),
                    ])->values()->all(),
                ])->values()->all(),
            'brands' => Product::active()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand')->values()->all(),
            'active' => [
                'category' => $categorySlug,
                'q' => $filters['q'] ?? '',
                'brand' => $filters['brand'] ?? '',
                'min_price' => $filters['min_price'] ?? '',
                'max_price' => $filters['max_price'] ?? '',
                'sort' => $sort,
            ],
            'category_name' => $category?->name,
            'products' => $p->getCollection()->map(fn ($product) => $this->product($product))->values()->all(),
            'total' => $p->total(),
            'per_page' => $p->perPage(),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
        ]);
    }

    /**
     * Product detail payload for the Vue product SPA — includes the shared base
     * (site/user/nav/contact/categories) the layout needs plus the product.
     */
    public function productDetail(string $slug): array
    {
        $product = Product::where('slug', $slug)->active()
            ->with('category', 'variants', 'reviews.user')->firstOrFail();

        $price = (float) $product->min_price;
        $compare = (float) $product->compare_price;
        if ($compare <= $price && $product->variants->isNotEmpty()) {
            $compare = (float) ($product->variants->where('compare_price', '>', 0)->min('compare_price') ?: 0);
        }
        $onSale = $compare && $compare > $price;

        return [
            'site' => $this->site(),
            'user' => $this->user(),
            'nav' => $this->nav('header'),
            'contact' => $this->contact(),
            'categories' => $this->categories(),
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'url' => route('product.show', $product->slug),
                'sku' => $product->sku,
                'brand' => $product->brand,
                'short_description' => $product->short_description,
                'description' => $product->description,
                'attributes' => (array) $product->attributes,
                'tags' => (array) $product->tags,
                'price' => $price,
                'compare_price' => $onSale ? $compare : null,
                'discount_percent' => $onSale ? (int) round((1 - $price / $compare) * 100) : 0,
                'rating_avg' => (float) $product->rating_avg,
                'rating_count' => (int) $product->rating_count,
                'image' => $product->image_url ?: asset('images/placeholder.svg'),
                'gallery' => $product->gallery_urls,
                'in_stock' => $product->total_stock > 0,
                'total_stock' => (int) $product->total_stock,
                'category' => $product->category?->name,
                'variants' => $product->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'options' => (array) $v->options,
                    'price' => (float) $v->price,
                    'stock' => (int) $v->stock,
                ])->values()->all(),
                'reviews' => $product->reviews->take(5)->map(fn ($r) => [
                    'name' => $r->user?->name,
                    'rating' => (int) $r->rating,
                    'title' => $r->title,
                    'body' => $r->body,
                    'created_at' => $r->created_at?->format('d/m/Y'),
                ])->values()->all(),
            ],
            'related' => Product::where('category_id', $product->category_id)
                ->where('id', '!=', $product->id)->active()->inStock()
                ->limit(4)->get()->map(fn ($p) => $this->product($p))->values()->all(),
        ];
    }

    /**
     * Base payload the shared layout needs on any storefront page
     * (site/user/nav/contact/categories). Page-specific data is appended.
     */
    public function base(): array
    {
        return [
            'site' => $this->site(),
            'user' => $this->user(),
            'nav' => $this->nav('header'),
            'contact' => $this->contact(),
            'categories' => $this->categories(),
        ];
    }

    /**
     * Blog index payload for the Vue blog SPA.
     */
    public function blogIndex(): array
    {
        $posts = Post::published()->with('author', 'category')->latest()->paginate(9);

        return array_merge($this->base(), [
            'posts' => $posts->getCollection()->map(fn (Post $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'url' => route('blog.show', $p->slug),
                'excerpt' => $p->excerpt,
                'image' => $this->image($p->image),
                'category' => $p->category?->name,
                'published_at' => $p->published_at?->format('d/m/Y'),
                'reading_time' => $p->reading_time,
            ])->values()->all(),
            'total' => $posts->total(),
            'last_page' => $posts->lastPage(),
        ]);
    }

    /**
     * Blog single-post payload.
     */
    public function blogPost(string $slug): array
    {
        $post = Post::where('slug', $slug)->published()->with('author', 'category')->firstOrFail();

        return array_merge($this->base(), [
            'post' => [
                'title' => $post->title,
                'slug' => $post->slug,
                'excerpt' => $post->excerpt,
                'body' => $post->body,
                'image' => $this->image($post->image),
                'category' => $post->category?->name,
                'published_at' => $post->published_at?->format('d/m/Y'),
                'reading_time' => $post->reading_time,
                'author' => $post->author?->name,
            ],
        ]);
    }

    /**
     * Checkout payload — cart data comes client-side via /api/cart; here we pass
     * the shipping/payment methods and the customer's default address.
     */
    public function checkout(): array
    {
        $shippingMethods = app(\App\Services\CartService::class)->shippingMethods();
        $paymentMethods = \App\Models\PaymentMethod::active()->orderBy('sort_order')->get();
        $user = auth()->user();
        $address = $user?->addresses()->where('is_default', true)->first();

        return array_merge($this->base(), [
            'user' => array_merge($this->user(), [
                'email' => $user?->email,
                'phone' => $user?->phone,
            ]),
            'shipping_methods' => $shippingMethods->map(fn ($m) => [
                'code' => $m->code,
                'name' => $m->name,
                'description' => $m->description,
                'fee' => (float) $m->fee,
                'free_threshold' => $m->free_threshold !== null ? (float) $m->free_threshold : null,
                'estimated_days' => $m->estimated_days,
            ])->values()->all(),
            'payment_methods' => $paymentMethods->map(fn ($m) => [
                'code' => $m->code,
                'name' => $m->name,
                'description' => $m->description,
                'fee' => (float) $m->fee,
            ])->values()->all(),
            'default_address' => $address ? [
                'name' => $address->name,
                'phone' => $address->phone,
                'address' => $address->address,
                'ward' => $address->ward,
                'district' => $address->district,
                'province' => $address->province,
            ] : null,
        ]);
    }

    /**
     * Account dashboard payload (auth route).
     */
    public function accountDashboard(): array
    {
        $user = auth()->user();

        return array_merge($this->base(), [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'orders' => $user->orders()->withCount('items')->latest()->limit(5)->get()->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'total' => (float) $o->total,
                'status' => $o->status,
                'created_at' => $o->created_at?->format('d/m/Y'),
                'items_count' => $o->items_count,
                'url' => $o->user_id ? route('account.order', $o) : '#',
            ])->values()->all(),
            'counts' => [
                'orders' => $user->orders()->count(),
                'addresses' => $user->addresses()->count(),
                'wishlist' => $user->wishlistProducts()->count(),
            ],
        ]);
    }

    public function accountOrders(): array
    {
        $user = auth()->user();
        $orders = $user->orders()->with('items')->latest()->paginate(10);

        return array_merge($this->base(), [
            'view' => 'orders',
            'orders' => $orders->getCollection()->map(fn ($o) => $this->orderCard($o))->values()->all(),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
            'current_page' => $orders->currentPage(),
        ]);
    }

    public function accountOrder($order): array
    {
        return array_merge($this->base(), [
            'view' => 'order',
            'order' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'created_at' => $order->created_at?->format('d/m/Y H:i'),
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'shipping_fee' => (float) $order->shipping_fee,
                'total' => (float) $order->total,
                'payment_method' => $order->payment_method,
                'payment_status' => $order->payment_status,
                'shipping_method' => $order->shipping_method,
                'can_cancel' => $order->can_cancel,
                'paid_at' => $order->paid_at?->format('d/m/Y'),
                'shipped_at' => $order->shipped_at?->format('d/m/Y'),
                'delivered_at' => $order->delivered_at?->format('d/m/Y'),
                'items' => $order->items->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'variant_id' => $i->variant_id,
                    'name' => $i->product_name,
                    'sku' => $i->sku,
                    'price' => (float) $i->price,
                    'quantity' => (int) $i->quantity,
                    'subtotal' => (float) $i->subtotal,
                    'image' => $i->image ? asset_image($i->image) : null,
                ])->values()->all(),
            ],
        ]);
    }

    public function accountProfile(): array
    {
        $user = auth()->user();

        return array_merge($this->base(), [
            'view' => 'profile',
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function accountAddresses(): array
    {
        $addresses = auth()->user()->addresses()->latest()->get();

        return array_merge($this->base(), [
            'view' => 'addresses',
            'addresses' => $addresses->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'phone' => $a->phone,
                'address' => $a->address,
                'ward' => $a->ward,
                'district' => $a->district,
                'province' => $a->province,
                'is_default' => (bool) $a->is_default,
            ])->values()->all(),
        ]);
    }

    public function accountPassword(): array
    {
        return array_merge($this->base(), ['view' => 'password']);
    }

    public function accountReviews(): array
    {
        $reviews = auth()->user()->reviews()->with('product')->latest()->get();

        return array_merge($this->base(), [
            'view' => 'reviews',
            'reviews' => $reviews->map(fn ($r) => [
                'id' => $r->id,
                'rating' => (int) $r->rating,
                'title' => $r->title,
                'body' => $r->body,
                'product' => $r->product?->name,
                'product_url' => $r->product ? route('product.show', $r->product->slug) : '#',
                'created_at' => $r->created_at?->format('d/m/Y'),
            ])->values()->all(),
        ]);
    }

    /**
     * Full product data for the quick-view modal (variants, specs, shipping
     * estimate) — richer than the lightweight grid card product.
     */
    public function productForQuickView(int $id): array
    {
        $product = Product::where('id', $id)->active()->with('category', 'variants')->firstOrFail();

        $price = (float) $product->min_price;
        $compare = (float) $product->compare_price;
        if ($compare <= $price && $product->variants->isNotEmpty()) {
            $compare = (float) ($product->variants->where('compare_price', '>', 0)->min('compare_price') ?: 0);
        }
        $onSale = $compare && $compare > $price;

        $fastest = app(\App\Services\CartService::class)->shippingMethods()
            ->filter(fn ($m) => ($m->estimated_days ?? 0) > 0)
            ->sortBy('estimated_days')->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'url' => route('product.show', $product->slug),
            'sku' => $product->sku,
            'brand' => $product->brand,
            'short_description' => $product->short_description,
            'description' => $product->description,
            'attributes' => (array) $product->attributes,
            'tags' => (array) $product->tags,
            'price' => $price,
            'compare_price' => $onSale ? $compare : null,
            'discount_percent' => $onSale ? (int) round((1 - $price / $compare) * 100) : 0,
            'rating_avg' => (float) $product->rating_avg,
            'rating_count' => (int) $product->rating_count,
            'image' => $product->image_url ?: asset('images/placeholder.svg'),
            'gallery' => $product->gallery_urls,
            'in_stock' => $product->total_stock > 0,
            'total_stock' => (int) $product->total_stock,
            'category' => $product->category?->name,
            'variants' => $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'price' => (float) $v->price,
                'stock' => (int) $v->stock,
            ])->values()->all(),
            'shipping_estimate' => $fastest ? [
                'name' => $fastest->name,
                'days' => $fastest->estimated_days,
            ] : null,
        ];
    }

    public function checkoutPay($order): array
    {
        return array_merge($this->base(), ['view' => 'pay', 'order' => $this->orderSummary($order)]);
    }

    public function checkoutSuccess($order): array
    {
        return array_merge($this->base(), ['view' => 'success', 'order' => $this->orderSummary($order)]);
    }

    protected function orderSummary($order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'total' => (float) $order->total,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'items_count' => $order->items->count(),
        ];
    }

    protected function orderCard($o): array
    {
        return [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'total' => (float) $o->total,
            'status' => $o->status,
            'created_at' => $o->created_at?->format('d/m/Y'),
            'items_count' => $o->items_count ?? $o->items->count(),
            'url' => route('account.order', $o),
        ];
    }

    /**
     * Return saved products for the wishlist (client passes the persisted ids).
     */
    public function wishlistProducts(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        return Product::whereIn('id', $ids)->active()->with('category')
            ->get()->map(fn ($p) => $this->product($p))->values()->all();
    }

    /**
     * Static content page (about/contact/faq/privacy/terms). Content is pulled
     * from settings; custom /trang/{slug} pages via the CustomPage model.
     */
    public function staticPage(string $key, ?string $slug = null): array
    {
        $map = [
            'about' => [
                'title' => setting('about_heading', 'Thời trang cho người Việt hiện đại'),
                'intro' => setting('about_intro', 'Trillfa Fa ra đời với khát vọng mang đến trải nghiệm mua sắm trực tuyến tối giản, tinh tế nhưng đầy cảm hứng — nơi mỗi sản phẩm đều là một lựa chọn phong cách cho cuộc sống của bạn.'),
                'image' => setting('about_image') ? asset_image(setting('about_image')) : null,
                'body' => setting('about_body', ''),
            ],
            'contact' => [
                'title' => setting('contact_heading', 'Liên hệ với chúng tôi'),
                'intro' => setting('contact_intro', 'Chúng tôi luôn sẵn sàng hỗ trợ bạn.'),
                'body' => setting('contact_body', ''),
            ],
            'faq' => [
                'title' => 'Câu hỏi thường gặp',
                'intro' => 'Giải đáp các câu hỏi thường gặp về mua sắm tại Trillfa Fa.',
                'body' => setting('faq_body', ''),
            ],
            'privacy' => [
                'title' => 'Chính sách bảo mật',
                'intro' => 'Chúng tôi cam kết bảo vệ thông tin cá nhân của bạn.',
                'body' => setting('privacy_body', ''),
            ],
            'terms' => [
                'title' => 'Điều khoản sử dụng',
                'intro' => 'Vui lòng đọc kỹ điều khoản trước khi sử dụng dịch vụ.',
                'body' => setting('terms_body', ''),
            ],
        ];

        if ($slug) {
            $page = \App\Models\CustomPage::where('slug', $slug)->where('is_active', true)->first();
            if (! $page) {
                abort(404);
            }
            $pageData = [
                'title' => $page->hero_heading ?: $page->title,
                'intro' => $page->excerpt ?: '',
                'image' => $page->hero_image_url ?? null,
                'body' => $page->hero_content ?: $page->body,
            ];
        } else {
            $pageData = $map[$key] ?? ['title' => 'Trang', 'intro' => '', 'body' => ''];
        }

        return array_merge($this->base(), ['key' => $key, 'page' => $pageData]);
    }

    // ---------------------------------------------------------------- sections

    public function site(): array
    {
        return [
            'name' => setting('site_name', 'Trillfa Fa'),
            'logo' => asset('images/logo.png'),
            'announcement_enabled' => widget_enabled('announcement'),
            'announcement_text' => widget_field('announcement', 'text', 'Miễn phí vận chuyển cho đơn hàng từ 500.000đ'),
            'free_shipping_threshold' => (float) setting('free_shipping_threshold', 0),
        ];
    }

    public function user(): array
    {
        $user = auth()->user();

        return [
            'authed' => (bool) $user,
            'name' => $user?->name,
            'is_admin' => $user ? (bool) $user->isAdmin() : false,
        ];
    }

    public function nav(string $location = 'header')
    {
        return menu_tree($location)->map(fn ($item) => [
            'label' => $item->label,
            'url' => $item->url,
            'children' => collect($item->children ?? [])->map(fn ($c) => [
                'label' => $c->label,
                'url' => $c->url,
            ])->values()->all(),
        ])->values()->all();
    }

    public function contact(): array
    {
        return [
            'hotline' => setting('contact_hotline', '1900 0000'),
            'email' => setting('contact_email', 'support@trillfa.shop'),
            'address' => setting('contact_address', ''),
        ];
    }

    public function hero(): array
    {
        $banners = widget_enabled('hero')
            ? Banner::active()->where('position', 'hero')->orderBy('sort_order')->get()
            : collect();

        return [
            'enabled' => widget_enabled('hero'),
            'slides' => $banners->map(fn (Banner $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'subtitle' => $b->subtitle,
                'image' => $this->image($b->image),
                'button_text' => $b->button_text,
                'button_link' => $b->button_link ?: route('shop.index'),
            ])->values()->all(),
        ];
    }

    public function benefits(): array
    {
        return [
            ['icon' => 'truck', 'title' => 'Miễn phí vận chuyển', 'subtitle' => 'Cho đơn hàng từ 500.000₫'],
            ['icon' => 'shield', 'title' => 'Thanh toán an toàn', 'subtitle' => 'COD, VNPay, MoMo, chuyển khoản'],
            ['icon' => 'refresh', 'title' => 'Đổi trả dễ dàng', 'subtitle' => 'Trong vòng 7 ngày'],
            ['icon' => 'chat', 'title' => 'Hỗ trợ 24/7', 'subtitle' => 'Luôn sẵn sàng phục vụ bạn'],
        ];
    }

    public function categories(): array
    {
        $items = widget_enabled('categories')
            ? Category::active()->whereNull('parent_id')
                ->with('children', fn ($q) => $q->active())
                ->orderBy('sort_order')->get()
            : collect();

        return [
            'enabled' => widget_enabled('categories'),
            'kicker' => widget_field('categories', 'kicker', 'Danh mục'),
            'title' => widget_field('categories', 'title', 'Khám phá theo danh mục'),
            'link_text' => widget_field('categories', 'link_text', 'Xem tất cả'),
            'items' => $items->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'url' => route('shop.category', $c->slug),
                'icon' => $c->icon,
                'icon_image' => $c->icon_image_url,
                'image' => $c->image_url,
            ])->values()->all(),
        ];
    }

    /**
     * Build a "products grid" section (featured / new / sale / bestsellers).
     */
    public function productsSection(string $key): array
    {
        if (! widget_enabled($key === 'new' ? 'new' : $key)) {
            return $this->sectionSkeleton($key, false);
        }

        $limit = widget_limit($key === 'new' ? 'new' : $key);
        $products = match ($key) {
            'featured' => Product::active()->featured()->inStock()->with('category')->latest()->limit($limit)->get(),
            'new' => Product::active()->inStock()->with('category')->latest()->limit($limit)->get(),
            'bestsellers' => Product::active()->inStock()->withCount('orderItems')
                ->orderByDesc('order_items_count')->orderByDesc('sales_count')->limit($limit)->get(),
            'sale' => Product::active()->inStock()->where('compare_price', '>', 0)
                ->orderByDesc('id')->limit($limit)->get(),
            default => collect(),
        };

        return [
            'enabled' => true,
            'kicker' => widget_field($key === 'new' ? 'new' : $key, 'kicker', $this->productsDefaults($key)['kicker']),
            'title' => widget_field($key === 'new' ? 'new' : $key, 'title', $this->productsDefaults($key)['title']),
            'link_text' => widget_field($key === 'new' ? 'new' : $key, 'link_text', 'Xem tất cả'),
            'products' => $products->map(fn (Product $p) => $this->product($p))->values()->all(),
        ];
    }

    public function secondary(): array
    {
        $banners = widget_enabled('secondary')
            ? Banner::active()->where('position', 'secondary')->orderBy('sort_order')->limit(4)->get()
            : collect();

        return [
            'enabled' => widget_enabled('secondary'),
            'banners' => $banners->map(fn (Banner $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'subtitle' => $b->subtitle,
                'image' => $this->image($b->image),
                'button_text' => $b->button_text,
                'link' => $b->link ?: ($b->button_link ?: route('shop.index')),
            ])->values()->all(),
        ];
    }

    public function blog(): array
    {
        $posts = widget_enabled('blog')
            ? Post::published()->with('author', 'category')->latest()->limit(3)->get()
            : collect();

        return [
            'enabled' => widget_enabled('blog'),
            'kicker' => widget_field('blog', 'kicker', 'Blog'),
            'title' => widget_field('blog', 'title', 'Câu chuyện & Phong cách'),
            'link_text' => widget_field('blog', 'link_text', 'Đọc tất cả'),
            'posts' => $posts->map(fn (Post $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'url' => route('blog.show', $p->slug),
                'excerpt' => $p->excerpt,
                'image' => $this->image($p->image),
                'category' => $p->category?->name,
                'published_at' => $p->published_at?->format('d/m/Y'),
                'reading_time' => $p->reading_time,
            ])->values()->all(),
        ];
    }

    public function cta(): array
    {
        return [
            'enabled' => widget_enabled('cta'),
            'title' => widget_field('cta', 'title', 'Sẵn sàng nâng cấp phong cách của bạn?'),
            'subtitle' => widget_field('cta', 'subtitle', 'Khám phá bộ sưu tập mới nhất và tận hưởng ưu đãi hấp dẫn dành riêng cho bạn.'),
            'button_text' => widget_field('cta', 'button_text', 'Mua sắm ngay'),
            'button_link' => widget_field('cta', 'button_link', '/shop'),
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Resolve a stored image path to a public URL. Handles the legacy `samples/`
     * dev seed paths (which the Product model treats via asset_image() but the
     * Banner/Post models would wrongly prefix with storage/) as well as real
     * storage uploads.
     */
    protected function image(?string $path): ?string
    {
        if (! $path) {
            return asset('images/placeholder.svg');
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        if (str_starts_with($path, 'samples/')) {
            return asset($path);
        }

        return asset('storage/'.$path);
    }

    protected function product(Product $p): array
    {
        $price = (float) $p->min_price;

        // "Was" price: prefer the parent compare_price (what shoppers see next to
        // the price), then fall back to the cheapest variant compare_price.
        $compare = (float) $p->compare_price;
        if ($compare <= $price) {
            $compare = $p->variants->isNotEmpty()
                ? (float) ($p->variants->where('compare_price', '>', 0)->min('compare_price') ?: 0)
                : 0;
        }
        $onSale = $compare && $compare > $price;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'url' => route('product.show', $p->slug),
            'price' => $price,
            'compare_price' => $onSale ? $compare : null,
            'discount_percent' => $onSale ? (int) round((1 - $price / $compare) * 100) : 0,
            'rating_avg' => (float) $p->rating_avg,
            'rating_count' => (int) $p->rating_count,
            'image' => $p->image_url ?: ($p->gallery_urls[0] ?? asset('images/placeholder.svg')),
            'gallery' => $p->gallery_urls,
            'short_description' => $p->short_description,
            'in_stock' => $p->total_stock > 0,
            'total_stock' => (int) $p->total_stock,
        ];
    }

    protected function productsDefaults(string $key): array
    {
        return [
            'featured' => ['kicker' => 'Tuyển chọn', 'title' => 'Sản phẩm nổi bật'],
            'new' => ['kicker' => 'Mới về', 'title' => 'Hàng mới nhất'],
            'bestsellers' => ['kicker' => 'Bán chạy', 'title' => 'Được yêu thích nhất'],
            'sale' => ['kicker' => 'Deal hot', 'title' => 'Ưu đãi đặc biệt &mdash; giảm sâu'],
        ][$key] ?? ['kicker' => '', 'title' => ''];
    }

    protected function sectionSkeleton(string $key, bool $enabled): array
    {
        return [
            'enabled' => $enabled,
            'kicker' => $this->productsDefaults($key)['kicker'] ?? '',
            'title' => $this->productsDefaults($key)['title'] ?? '',
            'link_text' => 'Xem tất cả',
            'products' => [],
        ];
    }
}
