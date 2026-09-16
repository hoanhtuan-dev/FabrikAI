<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;

class HomeController extends Controller
{
    public function index()
    {
        seo()->organization()->website();
        $featured = widget_enabled('featured')
            ? Product::active()->featured()->inStock()->with('category')->latest()->limit(widget_limit('featured'))->get()
            : collect();

        $newArrivals = widget_enabled('new')
            ? Product::active()->inStock()->with('category')->latest()->limit(widget_limit('new'))->get()
            : collect();

        $bestsellers = widget_enabled('bestsellers')
            ? Product::active()->inStock()->withCount('orderItems')
                ->orderByDesc('order_items_count')->orderByDesc('sales_count')
                ->limit(widget_limit('bestsellers'))->get()
            : collect();

        $onSale = widget_enabled('sale')
            ? Product::active()->inStock()->where('compare_price', '>', 0)
                ->orderByDesc('id')->limit(widget_limit('sale'))->get()
            : collect();

        $categories = widget_enabled('categories')
            ? Category::active()->whereNull('parent_id')
                ->with('children', fn ($q) => $q->active())
                ->orderBy('sort_order')->get()
            : collect();

        $heroBanners = widget_enabled('hero')
            ? Banner::active()->where('position', 'hero')->orderBy('sort_order')->get()
            : collect();
        $secondaryBanners = widget_enabled('secondary')
            ? Banner::active()->where('position', 'secondary')->orderBy('sort_order')->limit(4)->get()
            : collect();

        $latestPosts = widget_enabled('blog')
            ? Post::published()->with('author', 'category')->latest()->limit(3)->get()
            : collect();

        return view('home', compact(
            'featured', 'newArrivals', 'bestsellers', 'onSale',
            'categories', 'heroBanners', 'secondaryBanners', 'latestPosts'
        ));
    }
}