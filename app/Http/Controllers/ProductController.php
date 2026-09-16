<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function show(string $slug)
    {
        $product = Product::active()
            ->with('category', 'variants', 'reviews.user')
            ->where('slug', $slug)
            ->firstOrFail();

        $product->increment('views_count');

        seo()->title($product->name)
            ->description($product->meta_description ?? $product->short_description)
            ->canonical(route('product.show', $product->slug))
            ->image($product->image_url)
            ->product($product)
            ->breadcrumbs([
                ['label' => 'Trang chủ', 'url' => route('home')],
                ['label' => $product->category?->name ?? 'Cửa hàng', 'url' => $product->category ? route('shop.category', $product->category->slug) : route('shop.index')],
                ['label' => $product->name, 'url' => route('product.show', $product->slug)],
            ]);

        $related = Product::active()->inStock()
            ->where('id', '!=', $product->id)
            ->where('category_id', $product->category_id)
            ->with('category', 'variants')->latest()->limit(4)->get();

        $ratingSummary = [
            'avg' => (float) $product->rating_avg,
            'count' => (int) $product->rating_count,
        ];

        $groups = collect([5, 4, 3, 2, 1])->mapWithKeys(function ($star) use ($product) {
            $count = Review::where('product_id', $product->id)->where('rating', $star)->count();
            return [$star => $count];
        });

        return view('product.show', compact('product', 'related', 'ratingSummary', 'groups'));
    }
}