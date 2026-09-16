<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request, ?string $categorySlug = null)
    {
        $query = Product::active()->with('category', 'variants');

        $category = null;
        if ($categorySlug) {
            $category = Category::active()->where('slug', $categorySlug)->firstOrFail();
            $ids = collect([$category->id])->merge($category->children->pluck('id'));
            $query->whereIn('category_id', $ids);
        }

        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }

        if ($request->filled('brand')) {
            $query->where('brand', $request->input('brand'));
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        $sort = $request->input('sort', 'newest');
        $query = match ($sort) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'popular' => $query->orderByDesc('sales_count'),
            'rating' => $query->orderByDesc('rating_avg'),
            default => $query->orderByDesc('featured')->orderByDesc('id'),
        };

        if ($category) {
            seo()->title($category->name.' | '.setting('site_name'))
                ->description($category->description ?: 'Sản phẩm '.$category->name.' tại Trillfa Fa.')
                ->canonical(route('shop.category', $category->slug))
                ->breadcrumbs([
                    ['label' => 'Trang chủ', 'url' => route('home')],
                    ['label' => 'Cửa hàng', 'url' => route('shop.index')],
                    ['label' => $category->name, 'url' => route('shop.category', $category->slug)],
                ]);
        } else {
            seo()->title('Cửa hàng | '.setting('site_name'))
                ->description('Khám phá bộ sưu tập thời trang & phong cách sống tại Trillfa Fa.')
                ->canonical(route('shop.index'))
                ->breadcrumbs([
                    ['label' => 'Trang chủ', 'url' => route('home')],
                    ['label' => 'Cửa hàng', 'url' => route('shop.index')],
                ]);
        }

        $products = $query->paginate(12)->withQueryString();

        $categories = Category::active()->whereNull('parent_id')->with('children')->orderBy('sort_order')->get();
        $brands = Product::active()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand');

        return view('shop', compact('products', 'categories', 'brands', 'category', 'sort'));
    }
}